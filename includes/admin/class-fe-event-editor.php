<?php
/**
 * Event editor: meta boxes, input parsing, save, duplicate and cleanup.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * The fe_event edit screen. Parsing and date shifting are pure so they can be
 * unit tested; everything else talks to WordPress and the data repos.
 */
class Fe_Event_Editor {

	const NONCE_ACTION = 'fe_save_event';
	const NONCE_FIELD  = 'fe_event_nonce';

	/**
	 * Hooks the editor.
	 */
	public static function init() {
		add_action( 'add_meta_boxes_' . Fe_Event_Repo::POST_TYPE, array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'edit_form_top', array( __CLASS__, 'render_header' ) );
		add_action( 'save_post_' . Fe_Event_Repo::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'cleanup_deleted' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'post_submitbox_start', array( __CLASS__, 'render_duplicate_button' ) );
		add_filter( 'manage_' . Fe_Event_Repo::POST_TYPE . '_posts_columns', array( __CLASS__, 'list_columns' ) );
		add_action( 'manage_' . Fe_Event_Repo::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_list_column' ), 10, 2 );
		add_action( 'admin_post_fe_duplicate_event', array( __CLASS__, 'handle_duplicate' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_notice' ) );
	}

	/**
	 * Post meta holding the event's internal notes.
	 */
	const NOTES_KEY = '_fe_notes';

	// -------------------------------------------------------------------
	// Pure helpers.
	// -------------------------------------------------------------------

	/**
	 * Pure: turns an already-unslashed form post into clean event data.
	 *
	 * @param array $post           Posted fields.
	 * @param array $zones_by_type  type => zone ids shown on the screen (delivery, courier).
	 * @param array $existing_rules The event's zone rules before this save.
	 * @return array{event:array, dates:array, rules:array, dropped:int, category_ids:int[], product_ids:int[], excluded_ids:int[], limits:array, emptied:int[], only_ids:int[]}
	 */
	public static function parse_post( array $post, array $zones_by_type = array(), array $existing_rules = array() ) {
		$open  = self::parse_datetime( isset( $post['fe_sales_open'] ) ? $post['fe_sales_open'] : '' );
		$close = self::parse_datetime( isset( $post['fe_sales_close'] ) ? $post['fe_sales_close'] : '' );
		$error = '';
		if ( '' !== $open && '' !== $close && $close <= $open ) {
			$close = '';
			$error = 'close_before_open';
		}

		$date_rows  = isset( $post['fe_dates'] ) && is_array( $post['fe_dates'] ) ? array_values( $post['fe_dates'] ) : array();
		$dates      = Fe_Event_Repo::normalize_dates( $date_rows );
		$date_list  = array_column( $dates, 'date' );
		$setup      = Fe_Event_Setup::rules_from_setup(
			$existing_rules,
			Fe_Event_Setup::ticks_from_post( $date_rows, $zones_by_type ),
			Fe_Event_Setup::prices_from_post( isset( $post['fe_prices'] ) && is_array( $post['fe_prices'] ) ? array_values( $post['fe_prices'] ) : array() ),
			$date_list,
			$zones_by_type,
			! empty( $post['fe_legacy_posted'] ) ? array_map( 'strval', isset( $post['fe_keep_rules'] ) ? (array) $post['fe_keep_rules'] : array() ) : null
		);
		$exceptions = Fe_Event_Setup::exceptions_from_post( isset( $post['fe_exceptions'] ) && is_array( $post['fe_exceptions'] ) ? array_values( $post['fe_exceptions'] ) : array(), $date_list );

		$excluded = array_values( array_unique( array_merge( self::clean_ids( isset( $post['fe_excluded_ids'] ) ? $post['fe_excluded_ids'] : array() ), $exceptions['excluded'] ) ) );
		$products = array_values( array_diff( self::clean_ids( isset( $post['fe_product_ids'] ) ? $post['fe_product_ids'] : array() ), $excluded ) );

		return array(
			'event'        => array(
				'sales_open'  => $open,
				'sales_close' => $close,
				'error'       => $error,
			),
			'dates'        => $dates,
			'rules'        => $setup['rules'],
			'dropped'      => $setup['dropped'],
			'category_ids' => self::clean_ids( isset( $post['fe_category_ids'] ) ? $post['fe_category_ids'] : array() ),
			'product_ids'  => $products,
			'excluded_ids' => $excluded,
			'limits'       => $exceptions['limits'],
			'emptied'      => $exceptions['emptied'],
			'only_ids'     => array_keys( $exceptions['limits'] ),
		);
	}

	/**
	 * Pure: a datetime-local value to store-local 'Y-m-d H:i:s', or ''.
	 *
	 * @param mixed $value Posted value, e.g. 2026-12-01T09:00.
	 * @return string
	 */
	private static function parse_datetime( $value ) {
		$value = trim( (string) $value );
		foreach ( array( '!Y-m-d\TH:i', '!Y-m-d\TH:i:s', '!Y-m-d H:i:s' ) as $format ) {
			$dt = DateTime::createFromFormat( $format, $value );
			if ( false !== $dt && $dt->format( substr( $format, 1 ) ) === $value ) {
				return $dt->format( 'Y-m-d H:i:s' );
			}
		}
		return '';
	}

	/**
	 * Pure: unique positive ints.
	 *
	 * @param mixed $value Posted list.
	 * @return int[]
	 */
	private static function clean_ids( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'intval', $value ) ) ) );
	}

	// -------------------------------------------------------------------
	// Save.
	// -------------------------------------------------------------------

	/**
	 * Zone ids shown on the screen for the per-date ticks.
	 *
	 * @return array type => zone ids.
	 */
	private static function shown_zone_ids() {
		$out = array();
		foreach ( Fe_Zone_Grid::zones_by_type() as $type => $zones ) {
			$out[ $type ] = array_keys( $zones );
		}
		return $out;
	}

	/**
	 * Saves the Event setup box.
	 *
	 * @param int     $post_id Event id.
	 * @param WP_Post $post    Event post.
	 */
	public static function save( $post_id, $post ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in parse_post() and the repos.
		$parsed = self::parse_post( wp_unslash( $_POST ), self::shown_zone_ids(), Fe_Zone_Rule_Repo::for_event( $post_id ) );

		$categories = array();
		foreach ( $parsed['only_ids'] as $id ) {
			$terms             = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'ids' ) );
			$categories[ $id ] = is_array( $terms ) ? $terms : array();
		}
		$product_ids = Fe_Event_Setup::products_to_link( $parsed['only_ids'], $parsed['product_ids'], $categories, $parsed['category_ids'] );

		Fe_Event_Repo::save_sales_window( $post_id, $parsed['event']['sales_open'], $parsed['event']['sales_close'] );
		Fe_Event_Repo::save_dates( $post_id, $parsed['dates'] );
		Fe_Event_Repo::save_date_limits( $post_id, $parsed['limits'] );
		Fe_Zone_Rule_Repo::replace_for_event( $post_id, $parsed['rules'] );
		Fe_Product_Links::sync_event( $post_id, $parsed['category_ids'], $product_ids, $parsed['excluded_ids'] );
		if ( isset( $_POST['fe_notes'] ) ) {
			update_post_meta( $post_id, self::NOTES_KEY, sanitize_textarea_field( wp_unslash( $_POST['fe_notes'] ) ) );
		}
		Fe_Admin::flush_rate_cache();

		$notices = array();
		if ( '' !== $parsed['event']['error'] ) {
			$notices[] = array( 'code' => $parsed['event']['error'] );
		}
		if ( $parsed['emptied'] ) {
			$notices[] = array(
				'code'     => 'limit_emptied',
				'products' => $parsed['emptied'],
			);
		}
		if ( $parsed['dropped'] > 0 ) {
			$notices[] = array( 'code' => 'price_dropped' );
		}
		if ( $notices ) {
			set_transient( 'fe_event_notice_' . get_current_user_id(), $notices, 60 );
		}
	}

	/**
	 * Pure: notice text for a stored notice.
	 *
	 * @param array    $notice     {code, products?}.
	 * @param callable $title_of   Product id => name.
	 * @return string
	 */
	public static function notice_text( array $notice, $title_of ) {
		switch ( isset( $notice['code'] ) ? $notice['code'] : '' ) {
			case 'close_before_open':
				return __( 'Sales close must be after sales open. The close time was cleared.', 'fulfillment-events-for-woocommerce' );
			case 'limit_emptied':
				$names = array_map( $title_of, isset( $notice['products'] ) ? (array) $notice['products'] : array() );
				/* translators: %s: product names */
				return sprintf( _n( '%s has no dates left in this event, so it can\'t be bought for it.', '%s have no dates left in this event, so they can\'t be bought for it.', count( $names ), 'fulfillment-events-for-woocommerce' ), implode( ', ', $names ) );
			case 'price_dropped':
				return __( 'A price change was removed because that zone is turned off on that date.', 'fulfillment-events-for-woocommerce' );
		}
		return '';
	}

	/**
	 * Shows the notices left by the last save.
	 */
	public static function show_notice() {
		$key    = 'fe_event_notice_' . get_current_user_id();
		$stored = get_transient( $key );
		if ( ! $stored ) {
			return;
		}
		delete_transient( $key );
		$notices = is_array( $stored ) ? $stored : array( array( 'code' => (string) $stored ) );
		foreach ( $notices as $notice ) {
			$text = is_array( $notice ) ? self::notice_text( $notice, 'get_the_title' ) : '';
			if ( '' !== $text ) {
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
			}
		}
	}

	/**
	 * Removes an event's rows and product links when the event is deleted.
	 *
	 * @param int $post_id Deleted post id.
	 */
	public static function cleanup_deleted( $post_id ) {
		if ( Fe_Event_Repo::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		Fe_Event_Repo::delete_children( $post_id );
		Fe_Zone_Rule_Repo::delete_for_event( $post_id );
		Fe_Product_Links::sync_event( $post_id, array(), array(), array() );
		Fe_Admin::flush_rate_cache();
	}

	// -------------------------------------------------------------------
	// Duplicate.
	// -------------------------------------------------------------------

	/**
	 * Adds "Duplicate" to the event list and drops Quick Edit, which can't
	 * edit an event's dates, zones or products.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Row post.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( Fe_Event_Repo::POST_TYPE !== $post->post_type ) {
			return $actions;
		}
		unset( $actions['inline hide-if-no-js'] );
		if ( current_user_can( 'manage_woocommerce' ) ) {
			$actions['fe_duplicate'] = '<a href="' . esc_url( self::duplicate_url( (int) $post->ID ) ) . '">' . esc_html__( 'Duplicate', 'fulfillment-events-for-woocommerce' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * "Duplicate" button in the event edit screen's Publish box. Shown once
	 * the event has been saved; the copy is made from the saved event.
	 *
	 * @param WP_Post|null $post Post being edited.
	 */
	public static function render_duplicate_button( $post = null ) {
		if ( ! $post instanceof WP_Post || Fe_Event_Repo::POST_TYPE !== $post->post_type || 'auto-draft' === $post->post_status || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<div class="fe-duplicate-action" style="margin-bottom:8px"><a class="button" href="' . esc_url( self::duplicate_url( (int) $post->ID ) ) . '" title="' . esc_attr__( 'Make a draft copy of the saved event', 'fulfillment-events-for-woocommerce' ) . '">' . esc_html__( 'Duplicate', 'fulfillment-events-for-woocommerce' ) . '</a></div>';
	}

	/**
	 * Nonced admin-post URL that duplicates an event.
	 *
	 * @param int $event_id Event id.
	 * @return string
	 */
	private static function duplicate_url( $event_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=fe_duplicate_event&event=' . (int) $event_id ), 'fe_duplicate_event_' . (int) $event_id );
	}

	/**
	 * Creates a draft copy of an event: same dates, sales window, zone rules,
	 * categories, products and exclusions. Staff edit the dates afterwards.
	 */
	public static function handle_duplicate() {
		$id = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0;
		check_admin_referer( 'fe_duplicate_event_' . $id );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fulfillment-events-for-woocommerce' ), 403 );
		}
		$event = $id ? Fe_Event_Repo::get( $id ) : null;
		if ( ! $event ) {
			wp_die( esc_html__( 'Event not found.', 'fulfillment-events-for-woocommerce' ), 404 );
		}

		$new_id = wp_insert_post(
			array(
				'post_type'   => Fe_Event_Repo::POST_TYPE,
				'post_status' => 'draft',
				/* translators: %s: event title */
				'post_title'  => sprintf( __( '%s (copy)', 'fulfillment-events-for-woocommerce' ), $event['title'] ),
			)
		);
		if ( ! $new_id || is_wp_error( $new_id ) ) {
			wp_die( esc_html__( 'Could not create the copy.', 'fulfillment-events-for-woocommerce' ) );
		}

		Fe_Event_Repo::save_sales_window( $new_id, (string) $event['sales_open'], (string) $event['sales_close'] );
		Fe_Event_Repo::save_dates( $new_id, $event['dates'] );
		Fe_Event_Repo::save_date_limits( $new_id, $event['date_limits'] );
		Fe_Zone_Rule_Repo::replace_for_event( $new_id, Fe_Zone_Rule_Repo::for_event( $id ) );
		$notes = (string) get_post_meta( $id, self::NOTES_KEY, true );
		if ( '' !== $notes ) {
			update_post_meta( $new_id, self::NOTES_KEY, $notes );
		}
		Fe_Product_Links::sync_event(
			$new_id,
			Fe_Product_Links::term_ids_for_event( $id ),
			Fe_Product_Links::product_ids_for_event( $id ),
			Fe_Product_Links::product_ids_for_event( $id, Fe_Product_Links::EXCLUDE_KEY )
		);
		Fe_Admin::flush_rate_cache();

		wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . (int) $new_id ) );
		exit;
	}

	// -------------------------------------------------------------------
	// Rendering.
	// -------------------------------------------------------------------

	/**
	 * Registers the Event setup box and the notes box.
	 */
	public static function add_meta_boxes() {
		$type = Fe_Event_Repo::POST_TYPE;
		add_meta_box( 'fe_setup', __( 'Event setup', 'fulfillment-events-for-woocommerce' ), array( 'Fe_Event_Setup_Box', 'render' ), $type, 'normal', 'high' );
		add_meta_box( 'fe_notes', __( 'Internal notes', 'fulfillment-events-for-woocommerce' ), array( __CLASS__, 'box_notes' ), $type, 'side', 'default' );
	}

	/**
	 * Header strip with the support link.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render_header( $post ) {
		if ( Fe_Event_Repo::POST_TYPE !== $post->post_type ) {
			return;
		}
		echo '<div class="fe-header"><span class="fe-header__title">' . esc_html__( 'Fulfillment event', 'fulfillment-events-for-woocommerce' ) . '</span>';
		Fe_Admin::support_link();
		echo '</div>';
	}

	/**
	 * Internal notes box: free text for staff, never shown to customers.
	 *
	 * @param WP_Post $post Event post.
	 */
	public static function box_notes( $post ) {
		?>
		<label for="fe_notes" class="screen-reader-text"><?php esc_html_e( 'Internal notes', 'fulfillment-events-for-woocommerce' ); ?></label>
		<textarea id="fe_notes" name="fe_notes" rows="6" placeholder="<?php esc_attr_e( 'e.g. Copied from last year, moved the fair pickup to Dec 18', 'fulfillment-events-for-woocommerce' ); ?>"><?php echo esc_textarea( (string) get_post_meta( $post->ID, self::NOTES_KEY, true ) ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Only staff see these notes.', 'fulfillment-events-for-woocommerce' ); ?></p>
		<?php
	}

	// -------------------------------------------------------------------
	// Events list.
	// -------------------------------------------------------------------

	/**
	 * Adds Dates and Notes columns after the title.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function list_columns( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['fe_dates'] = __( 'Dates', 'fulfillment-events-for-woocommerce' );
				$out['fe_notes'] = __( 'Internal notes', 'fulfillment-events-for-woocommerce' );
			}
		}
		return $out;
	}

	/**
	 * Pure: what a date offers, e.g. "delivery and pickup".
	 *
	 * @param array $row Date row.
	 * @return string
	 */
	public static function offer_text( array $row ) {
		$parts = array();
		if ( ! empty( $row['delivery'] ) ) {
			$parts[] = __( 'delivery', 'fulfillment-events-for-woocommerce' );
		}
		if ( ! empty( $row['pickup'] ) ) {
			$parts[] = __( 'pickup', 'fulfillment-events-for-woocommerce' );
		}
		if ( ! empty( $row['courier'] ) ) {
			$parts[] = __( 'courier', 'fulfillment-events-for-woocommerce' );
		}
		if ( count( $parts ) < 2 ) {
			return implode( '', $parts );
		}
		$last = array_pop( $parts );
		/* translators: 1: comma-separated services, 2: last service, e.g. "delivery, pickup" and "courier". */
		return sprintf( __( '%1$s and %2$s', 'fulfillment-events-for-woocommerce' ), implode( ', ', $parts ), $last );
	}

	/**
	 * Pure: "1 product exception", "3 product exceptions", or '' for none.
	 *
	 * @param int $count Exceptions.
	 * @return string
	 */
	public static function exception_count_text( $count ) {
		$count = (int) $count;
		if ( $count <= 0 ) {
			return '';
		}
		/* translators: %d: number of product exceptions */
		return sprintf( _n( '%d product exception', '%d product exceptions', $count, 'fulfillment-events-for-woocommerce' ), $count );
	}

	/**
	 * Renders the Dates and Notes columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Event id.
	 */
	public static function render_list_column( $column, $post_id ) {
		if ( 'fe_notes' === $column ) {
			$notes = (string) get_post_meta( $post_id, self::NOTES_KEY, true );
			echo '' === $notes ? '<span aria-hidden="true">—</span>' : esc_html( wp_trim_words( $notes, 20 ) );
			return;
		}
		if ( 'fe_dates' !== $column ) {
			return;
		}
		$event = Fe_Event_Repo::get( (int) $post_id );
		if ( ! $event || empty( $event['dates'] ) ) {
			echo '<span class="description">' . esc_html__( 'No dates yet', 'fulfillment-events-for-woocommerce' ) . '</span>';
			return;
		}
		$today = wp_date( 'Y-m-d' );
		echo '<ul class="fe-datelist">';
		foreach ( $event['dates'] as $row ) {
			$dt   = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $row['date'], wp_timezone() );
			$when = $dt ? wp_date( 'D, M j', $dt->getTimestamp() ) : (string) $row['date'];
			$past = (string) $row['date'] < $today;
			echo '<li' . ( $past ? ' class="fe-past" title="' . esc_attr__( 'Date has passed', 'fulfillment-events-for-woocommerce' ) . '"' : '' ) . '><strong>' . esc_html( $when ) . '</strong> <span class="fe-kinds">' . esc_html( self::offer_text( $row ) ) . '</span></li>';
		}
		echo '</ul>';
		$count = count( $event['date_limits'] ) + count( Fe_Product_Links::product_ids_for_event( (int) $post_id, Fe_Product_Links::EXCLUDE_KEY ) );
		if ( $count ) {
			echo '<span class="description">' . esc_html( self::exception_count_text( $count ) ) . '</span>';
		}
	}
}
