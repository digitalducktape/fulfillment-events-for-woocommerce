<?php
/**
 * Event fields on products and product categories, plus the multi-event badge.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * Lets staff link a product or category to events, and flags products that
 * sit in more than one open event.
 */
class Fe_Product_Fields {

	const NONCE_ACTION = 'fe_save_product_events';
	const NONCE_FIELD  = 'fe_product_events_nonce';

	/**
	 * Open events for the badge, loaded once per request.
	 *
	 * @var int[]|null
	 */
	private static $open_ids = null;

	/**
	 * Hooks the product and category screens.
	 */
	public static function init() {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_product_field' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product' ) );
		add_action( 'product_cat_add_form_fields', array( __CLASS__, 'render_add_term_field' ) );
		add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'render_edit_term_field' ) );
		add_action( 'created_product_cat', array( __CLASS__, 'save_term' ) );
		add_action( 'edited_product_cat', array( __CLASS__, 'save_term' ) );
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_action( 'woocommerce_product_bulk_edit_end', array( __CLASS__, 'render_bulk_field' ) );
		add_action( 'woocommerce_product_bulk_edit_save', array( __CLASS__, 'save_bulk' ) );
	}

	/**
	 * Bulk edit actions as value => label.
	 *
	 * @return array<string,string>
	 */
	private static function bulk_actions() {
		return array(
			''        => __( '— No change —', 'fulfillment-events-for-woocommerce' ),
			'add'     => __( 'Add to event', 'fulfillment-events-for-woocommerce' ),
			'exclude' => __( 'Exclude from event', 'fulfillment-events-for-woocommerce' ),
			'remove'  => __( 'Remove from event (follow categories)', 'fulfillment-events-for-woocommerce' ),
		);
	}

	/**
	 * Fields in the Products list bulk edit panel.
	 */
	public static function render_bulk_field() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<div class="inline-edit-group fe-bulk-events"><label class="alignleft"><span class="title">' . esc_html__( 'Fulfillment event', 'fulfillment-events-for-woocommerce' ) . '</span><span class="input-text-wrap"><select name="fe_bulk_action">';
		foreach ( self::bulk_actions() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></span></label><br class="clear" /><span class="title inline-edit-categories-label">' . esc_html__( 'Events', 'fulfillment-events-for-woocommerce' ) . '</span><ul class="cat-checklist fe-bulk-event-list">';
		foreach ( self::event_choices() as $event_id => $title ) {
			echo '<li><label class="selectit"><input type="checkbox" name="fe_bulk_events[]" value="' . esc_attr( $event_id ) . '" /> ' . esc_html( $title ) . '</label></li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Applies the bulk edit to one product. WordPress has already checked
	 * the bulk-posts nonce before WooCommerce calls this.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public static function save_bulk( $product ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- bulk-posts nonce checked by core.
		$action    = isset( $_REQUEST['fe_bulk_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['fe_bulk_action'] ) ) : '';
		$event_ids = isset( $_REQUEST['fe_bulk_events'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['fe_bulk_events'] ) ) : array();
		// phpcs:enable
		if ( ! in_array( $action, array( 'add', 'exclude', 'remove' ), true ) ) {
			return;
		}
		$event_ids = array_values(
			array_filter(
				array_unique( $event_ids ),
				function ( $id ) {
					return $id > 0 && Fe_Event_Repo::POST_TYPE === get_post_type( $id );
				}
			)
		);
		if ( ! $event_ids ) {
			return;
		}
		$product_id = (int) $product->get_id();
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_post', $product_id ) ) {
			return;
		}
		$own      = get_post_meta( $product_id, Fe_Product_Links::META_KEY, true );
		$own      = is_array( $own ) ? $own : array();
		$excluded = Fe_Product_Links::excluded_for_product( $product_id );
		foreach ( $event_ids as $event_id ) {
			list( $own, $excluded ) = Fe_Event_Resolver::apply_bulk( $own, $excluded, $action, $event_id );
		}
		Fe_Product_Links::set_for_product( $product_id, $own );
		Fe_Product_Links::set_excluded_for_product( $product_id, $excluded );
		Fe_Admin::flush_rate_cache();
	}

	/**
	 * Published and draft events as id => title.
	 *
	 * @return array<int,string>
	 */
	private static function event_choices() {
		$posts = get_posts(
			array(
				'post_type'      => Fe_Event_Repo::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => Fe_Event_Repo::MAX_EVENTS,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$out   = array();
		foreach ( $posts as $post ) {
			$title = '' === $post->post_title ? __( '(no title)', 'fulfillment-events-for-woocommerce' ) : $post->post_title;
			if ( 'draft' === $post->post_status ) {
				/* translators: %s: event title */
				$title = sprintf( __( '%s (draft)', 'fulfillment-events-for-woocommerce' ), $title );
			}
			$out[ (int) $post->ID ] = $title;
		}
		return $out;
	}

	/**
	 * Prints the multiselect.
	 *
	 * @param int[]  $selected Selected event ids.
	 * @param string $id       Element id.
	 * @param string $name     Field name, without the brackets.
	 */
	private static function select( array $selected, $id, $name = 'fe_events' ) {
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '[]" class="wc-enhanced-select" multiple style="width:50%;" data-placeholder="' . esc_attr__( 'No event', 'fulfillment-events-for-woocommerce' ) . '">';
		foreach ( self::event_choices() as $event_id => $title ) {
			echo '<option value="' . esc_attr( $event_id ) . '"' . selected( in_array( $event_id, $selected, true ), true, false ) . '>' . esc_html( $title ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Product General tab field.
	 */
	public static function render_product_field() {
		global $post;
		$own = get_post_meta( $post->ID, Fe_Product_Links::META_KEY, true );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<div class="options_group"><p class="form-field"><label for="fe_events">' . esc_html__( 'Fulfillment events', 'fulfillment-events-for-woocommerce' ) . '</label>';
		self::select( is_array( $own ) ? array_map( 'intval', $own ) : array(), 'fe_events' );
		echo wc_help_tip( __( 'Events this product is sold through. Categories can link events too. Only categories the product is directly assigned to count; parent categories are not inherited.', 'fulfillment-events-for-woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</p><p class="form-field"><label for="fe_excluded_events">' . esc_html__( 'Excluded from events', 'fulfillment-events-for-woocommerce' ) . '</label>';
		self::select( Fe_Product_Links::excluded_for_product( $post->ID ), 'fe_excluded_events', 'fe_excluded_events' );
		echo wc_help_tip( __( 'Events this product is left out of, even when one of its categories is in them.', 'fulfillment-events-for-woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</p>';
		foreach ( Fe_Product_Links::event_ids_for_product( $post->ID ) as $event_id ) {
			$event = Fe_Event_Repo::get( $event_id );
			if ( ! $event || ! isset( $event['date_limits'][ $post->ID ] ) ) {
				continue;
			}
			echo '<p class="form-field"><label>' . esc_html__( 'Date limit', 'fulfillment-events-for-woocommerce' ) . '</label>' . esc_html( self::limit_text( $event['title'], (array) $event['date_limits'][ $post->ID ] ) ) . ' <a href="' . esc_url( get_edit_post_link( $event_id ) ) . '">' . esc_html__( '(set on the event)', 'fulfillment-events-for-woocommerce' ) . '</a></p>';
		}
		echo '</div>';
	}

	/**
	 * Pure: a product's date limit in one event, e.g.
	 * "Holiday Pre-order 2026: only Dec 18".
	 *
	 * @param string   $event_title Event title.
	 * @param string[] $dates       Allowed dates.
	 * @return string
	 */
	public static function limit_text( $event_title, array $dates ) {
		if ( ! $dates ) {
			/* translators: %s: event title */
			return sprintf( __( '%s: no dates', 'fulfillment-events-for-woocommerce' ), $event_title );
		}
		/* translators: 1: event title, 2: dates, e.g. "Dec 18" or "Dec 10 and Dec 18" */
		return sprintf( __( '%1$s: only %2$s', 'fulfillment-events-for-woocommerce' ), $event_title, self::date_list( $dates ) );
	}

	/**
	 * Pure: "Dec 18", "Dec 10 and Dec 18" or "Dec 3, Dec 10 and Dec 18".
	 *
	 * @param string[] $dates Y-m-d dates.
	 * @return string
	 */
	public static function date_list( array $dates ) {
		sort( $dates );
		$labels = array();
		foreach ( $dates as $ymd ) {
			$dt       = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $ymd );
			$labels[] = $dt ? $dt->format( 'M j' ) : (string) $ymd;
		}
		$last = array_pop( $labels );
		if ( ! $labels ) {
			return (string) $last;
		}
		/* translators: 1: comma-separated dates, 2: last date */
		return sprintf( __( '%1$s and %2$s', 'fulfillment-events-for-woocommerce' ), implode( ', ', $labels ), $last );
	}

	/**
	 * Saves the product field.
	 *
	 * @param int $post_id Product id.
	 */
	public static function save_product( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$excluded = self::posted_ids( 'fe_excluded_events' );
		Fe_Product_Links::set_for_product( $post_id, array_diff( self::posted_ids(), $excluded ) );
		Fe_Product_Links::set_excluded_for_product( $post_id, $excluded );
		Fe_Admin::flush_rate_cache();
	}

	/**
	 * Event ids from a posted multiselect.
	 *
	 * @param string $name Field name.
	 * @return int[]
	 */
	private static function posted_ids( $name = 'fe_events' ) {
		$raw = isset( $_POST[ $name ] ) ? (array) wp_unslash( $_POST[ $name ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified by callers; cast to int below.
		return array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
	}

	/**
	 * Category add form field.
	 */
	public static function render_add_term_field() {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<div class="form-field"><label for="fe_events_term">' . esc_html__( 'Fulfillment events', 'fulfillment-events-for-woocommerce' ) . '</label>';
		self::select( array(), 'fe_events_term' );
		echo '<p>' . esc_html__( 'Products directly assigned to this category are sold through these events. Parent categories are not inherited.', 'fulfillment-events-for-woocommerce' ) . '</p></div>';
	}

	/**
	 * Category edit form field.
	 *
	 * @param WP_Term $term Category.
	 */
	public static function render_edit_term_field( $term ) {
		$own = get_term_meta( $term->term_id, Fe_Product_Links::META_KEY, true );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<tr class="form-field"><th scope="row"><label for="fe_events_term">' . esc_html__( 'Fulfillment events', 'fulfillment-events-for-woocommerce' ) . '</label></th><td>';
		self::select( is_array( $own ) ? array_map( 'intval', $own ) : array(), 'fe_events_term' );
		echo '<p class="description">' . esc_html__( 'Products directly assigned to this category are sold through these events. Parent categories are not inherited.', 'fulfillment-events-for-woocommerce' ) . '</p></td></tr>';
	}

	/**
	 * Saves the category field.
	 *
	 * @param int $term_id Category id.
	 */
	public static function save_term( $term_id ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}
		Fe_Product_Links::set_for_term( $term_id, self::posted_ids() );
		Fe_Admin::flush_rate_cache();
	}

	/**
	 * Adds the narrow "Events" column to the product list.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$columns['fe_events'] = __( 'Events', 'fulfillment-events-for-woocommerce' );
		return $columns;
	}

	/**
	 * Shows the warning badge for products in more than one open event.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Product id.
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'fe_events' !== $column ) {
			return;
		}
		if ( null === self::$open_ids ) {
			self::$open_ids = array_map( 'intval', wp_list_pluck( Fe_Event_Repo::open_events( current_time( 'mysql' ) ), 'id' ) );
		}
		$ids      = Fe_Product_Links::event_ids_for_product( $post_id );
		$excluded = Fe_Product_Links::excluded_for_product( $post_id );
		if ( ! $ids && ! $excluded ) {
			echo '<span class="na">&ndash;</span>';
			return;
		}
		$names = array();
		foreach ( $ids as $id ) {
			$label  = get_the_title( $id );
			$limits = get_post_meta( $id, Fe_Event_Repo::META_LIMITS, true );
			if ( is_array( $limits ) && isset( $limits[ $post_id ] ) ) {
				$label .= ' (' . ( $limits[ $post_id ] ? self::date_list( (array) $limits[ $post_id ] ) : __( 'no dates', 'fulfillment-events-for-woocommerce' ) ) . ')';
			}
			$names[] = esc_html( $label );
		}
		foreach ( $excluded as $id ) {
			$names[] = '<s title="' . esc_attr__( 'Excluded', 'fulfillment-events-for-woocommerce' ) . '">' . esc_html( get_the_title( $id ) ) . '</s>';
		}
		echo implode( '<br>', $names ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		$count = count( array_intersect( $ids, self::$open_ids ) );
		if ( $count > 1 ) {
			/* translators: %d: number of open events */
			echo '<br><span class="fe-badge fe-badge--warn" title="' . esc_attr__( 'Checkout offers the dates of each of these events.', 'fulfillment-events-for-woocommerce' ) . '">' . esc_html( sprintf( _n( 'In %d open event', 'In %d open events', $count, 'fulfillment-events-for-woocommerce' ), $count ) ) . '</span>';
		}
	}
}
