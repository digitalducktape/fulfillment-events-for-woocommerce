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
		add_action( 'admin_post_fe_duplicate_event', array( __CLASS__, 'handle_duplicate' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_notice' ) );
	}

	// -------------------------------------------------------------------
	// Pure helpers.
	// -------------------------------------------------------------------

	/**
	 * Pure: turns an already-unslashed form post into clean event data.
	 *
	 * @param array $post Posted fields.
	 * @return array{event:array, dates:array, rules:array, category_ids:int[], product_ids:int[], excluded_ids:int[]}
	 */
	public static function parse_post( array $post ) {
		$open  = self::parse_datetime( isset( $post['fe_sales_open'] ) ? $post['fe_sales_open'] : '' );
		$close = self::parse_datetime( isset( $post['fe_sales_close'] ) ? $post['fe_sales_close'] : '' );
		$error = '';
		if ( '' !== $open && '' !== $close && $close <= $open ) {
			$close = '';
			$error = 'close_before_open';
		}

		$excluded = self::clean_ids( isset( $post['fe_excluded_ids'] ) ? $post['fe_excluded_ids'] : array() );
		$products = array_values( array_diff( self::clean_ids( isset( $post['fe_product_ids'] ) ? $post['fe_product_ids'] : array() ), $excluded ) );

		return array(
			'event'        => array(
				'sales_open'  => $open,
				'sales_close' => $close,
				'error'       => $error,
			),
			'dates'        => Fe_Event_Repo::normalize_dates( isset( $post['fe_dates'] ) && is_array( $post['fe_dates'] ) ? array_values( $post['fe_dates'] ) : array() ),
			'rules'        => Fe_Zone_Rule_Repo::normalize_rules( self::rules_from_grid( isset( $post['fe_rules'] ) ? $post['fe_rules'] : array() ) ),
			'category_ids' => self::clean_ids( isset( $post['fe_category_ids'] ) ? $post['fe_category_ids'] : array() ),
			'product_ids'  => $products,
			'excluded_ids' => $excluded,
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
	 * Pure: flattens fe_rules[zone][type][] into rule rows. A row that
	 * defers to the zone default ("inherit") stores nothing.
	 *
	 * @param mixed $grid Posted grid.
	 * @return array[]
	 */
	private static function rules_from_grid( $grid ) {
		$rules = array();
		if ( ! is_array( $grid ) ) {
			return $rules;
		}
		foreach ( $grid as $zone_id => $types ) {
			if ( ! is_array( $types ) ) {
				continue;
			}
			foreach ( $types as $type => $cells ) {
				if ( ! is_array( $cells ) ) {
					continue;
				}
				foreach ( $cells as $cell ) {
					if ( ! is_array( $cell ) || empty( $cell['mode'] ) || 'inherit' === $cell['mode'] ) {
						continue;
					}
					$rules[] = array(
						'zone_id' => (int) $zone_id,
						'type'    => (string) $type,
						'date'    => isset( $cell['date'] ) ? trim( (string) $cell['date'] ) : '',
						'mode'    => (string) $cell['mode'],
						'cost'    => isset( $cell['cost'] ) ? trim( (string) $cell['cost'] ) : '',
					);
				}
			}
		}
		return $rules;
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
	 * Saves the event meta boxes.
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
		$parsed = self::parse_post( wp_unslash( $_POST ) );

		Fe_Event_Repo::save_sales_window( $post_id, $parsed['event']['sales_open'], $parsed['event']['sales_close'] );
		Fe_Event_Repo::save_dates( $post_id, $parsed['dates'] );
		Fe_Zone_Rule_Repo::replace_for_event( $post_id, $parsed['rules'] );
		Fe_Product_Links::sync_event( $post_id, $parsed['category_ids'], $parsed['product_ids'], $parsed['excluded_ids'] );
		Fe_Admin::flush_rate_cache();

		if ( '' !== $parsed['event']['error'] ) {
			set_transient( 'fe_event_notice_' . get_current_user_id(), $parsed['event']['error'], 60 );
		}
	}

	/**
	 * Shows a one-off notice after a save that dropped input.
	 */
	public static function show_notice() {
		$key = 'fe_event_notice_' . get_current_user_id();
		if ( 'close_before_open' !== get_transient( $key ) ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Sales close must be after sales open. The close time was cleared.', 'fulfillment-events-for-woocommerce' ) . '</p></div>';
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
	 * Adds "Duplicate" to the event list.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Row post.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( Fe_Event_Repo::POST_TYPE === $post->post_type && current_user_can( 'manage_woocommerce' ) ) {
			$url                     = wp_nonce_url( admin_url( 'admin-post.php?action=fe_duplicate_event&event=' . (int) $post->ID ), 'fe_duplicate_event_' . (int) $post->ID );
			$actions['fe_duplicate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Duplicate', 'fulfillment-events-for-woocommerce' ) . '</a>';
		}
		return $actions;
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
		Fe_Zone_Rule_Repo::replace_for_event( $new_id, Fe_Zone_Rule_Repo::for_event( $id ) );
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
	 * Registers the four meta boxes.
	 */
	public static function add_meta_boxes() {
		$type = Fe_Event_Repo::POST_TYPE;
		add_meta_box( 'fe_details', __( 'Details', 'fulfillment-events-for-woocommerce' ), array( __CLASS__, 'box_details' ), $type, 'normal', 'high' );
		add_meta_box( 'fe_dates', __( 'Dates', 'fulfillment-events-for-woocommerce' ), array( __CLASS__, 'box_dates' ), $type, 'normal', 'high' );
		add_meta_box( 'fe_zones', __( 'Zones', 'fulfillment-events-for-woocommerce' ), array( 'Fe_Zone_Grid', 'render' ), $type, 'normal', 'default' );
		add_meta_box( 'fe_products', __( 'Products', 'fulfillment-events-for-woocommerce' ), array( __CLASS__, 'box_products' ), $type, 'normal', 'default' );
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
	 * Formats a stored 'Y-m-d H:i:s' for a datetime-local input.
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	private static function to_input( $value ) {
		return $value ? str_replace( ' ', 'T', substr( (string) $value, 0, 16 ) ) : '';
	}

	/**
	 * Details box: sales window.
	 *
	 * @param WP_Post $post Event post.
	 */
	public static function box_details( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		$event = Fe_Event_Repo::get( $post->ID );
		$open  = $event ? self::to_input( $event['sales_open'] ) : '';
		$close = $event ? self::to_input( $event['sales_close'] ) : '';
		?>
		<p class="description"><?php esc_html_e( 'Customers can buy event products while the sales window is open. Leave a field empty for no limit. Times use the store timezone.', 'fulfillment-events-for-woocommerce' ); ?></p>
		<table class="form-table fe-form-table"><tbody>
			<tr>
				<th scope="row"><label for="fe_sales_open"><?php esc_html_e( 'Sales open', 'fulfillment-events-for-woocommerce' ); ?></label></th>
				<td><input type="datetime-local" id="fe_sales_open" name="fe_sales_open" value="<?php echo esc_attr( $open ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="fe_sales_close"><?php esc_html_e( 'Sales close', 'fulfillment-events-for-woocommerce' ); ?></label></th>
				<td><input type="datetime-local" id="fe_sales_close" name="fe_sales_close" value="<?php echo esc_attr( $close ); ?>"></td>
			</tr>
		</tbody></table>
		<?php
	}

	/**
	 * Dates box: one row per fulfillment date.
	 *
	 * @param WP_Post $post Event post.
	 */
	public static function box_dates( $post ) {
		$event = Fe_Event_Repo::get( $post->ID );
		$rows  = $event ? $event['dates'] : array();
		$locs  = Fe_Location_Repo::all();
		?>
		<p class="description"><?php esc_html_e( 'Each date can offer delivery, pickup or both. A date with neither is not saved. Courier is offered on every date for zones that have it.', 'fulfillment-events-for-woocommerce' ); ?></p>
		<table class="widefat fe-dates" id="fe-dates">
			<thead><tr>
				<th><?php esc_html_e( 'Date', 'fulfillment-events-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Delivery', 'fulfillment-events-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Pickup', 'fulfillment-events-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Pickup location', 'fulfillment-events-for-woocommerce' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody id="fe-dates-rows">
				<?php foreach ( array_values( $rows ) as $i => $row ) : ?>
					<?php self::date_row( (string) $i, $row, $locs ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<template id="fe-date-template">
			<?php self::date_row( '__i__', array(), $locs ); ?>
		</template>
		<p><button type="button" class="button" id="fe-add-date"><?php esc_html_e( 'Add date', 'fulfillment-events-for-woocommerce' ); ?></button></p>
		<?php
	}

	/**
	 * One dates-table row.
	 *
	 * @param string  $i    Row index or the __i__ placeholder.
	 * @param array   $row  Date row (empty for a blank one).
	 * @param array[] $locs Locations.
	 */
	private static function date_row( $i, array $row, array $locs ) {
		$name = 'fe_dates[' . $i . ']';
		$get  = function ( $key, $default = '' ) use ( $row ) {
			return isset( $row[ $key ] ) ? $row[ $key ] : $default;
		};
		?>
		<tr class="fe-date-row">
			<td><input type="date" name="<?php echo esc_attr( $name ); ?>[date]" value="<?php echo esc_attr( $get( 'date' ) ); ?>"></td>
			<td>
				<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delivery]" value="1" <?php checked( ! empty( $row['delivery'] ) ); ?>> <?php esc_html_e( 'Offer', 'fulfillment-events-for-woocommerce' ); ?></label>
				<input type="text" name="<?php echo esc_attr( $name ); ?>[delivery_window]" value="<?php echo esc_attr( $get( 'delivery_window' ) ); ?>" placeholder="<?php esc_attr_e( 'Window, e.g. 10am to 2pm', 'fulfillment-events-for-woocommerce' ); ?>">
			</td>
			<td>
				<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[pickup]" value="1" <?php checked( ! empty( $row['pickup'] ) ); ?>> <?php esc_html_e( 'Offer', 'fulfillment-events-for-woocommerce' ); ?></label>
				<input type="text" name="<?php echo esc_attr( $name ); ?>[pickup_window]" value="<?php echo esc_attr( $get( 'pickup_window' ) ); ?>" placeholder="<?php esc_attr_e( 'Window, e.g. 10am to 2pm', 'fulfillment-events-for-woocommerce' ); ?>">
			</td>
			<td>
				<select name="<?php echo esc_attr( $name ); ?>[location_id]">
					<option value="0"><?php esc_html_e( 'Default location', 'fulfillment-events-for-woocommerce' ); ?></option>
					<?php foreach ( $locs as $loc ) : ?>
						<option value="<?php echo esc_attr( $loc['id'] ); ?>" <?php selected( (int) $get( 'location_id', 0 ), $loc['id'] ); ?>><?php echo esc_html( $loc['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><button type="button" class="button-link button-link-delete fe-remove-date"><?php esc_html_e( 'Remove', 'fulfillment-events-for-woocommerce' ); ?></button></td>
		</tr>
		<?php
	}

	/**
	 * Products box: categories and individual products.
	 *
	 * @param WP_Post $post Event post.
	 */
	public static function box_products( $post ) {
		$selected_cats = Fe_Product_Links::term_ids_for_event( $post->ID );
		$selected_prod = Fe_Product_Links::product_ids_for_event( $post->ID );
		$excluded_prod = Fe_Product_Links::product_ids_for_event( $post->ID, Fe_Product_Links::EXCLUDE_KEY );
		$cats          = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 500,
			)
		);
		?>
		<p class="description"><?php esc_html_e( 'Products in these categories, or chosen below, are sold through this event. Only categories a product is directly assigned to count; parent categories are not inherited.', 'fulfillment-events-for-woocommerce' ); ?></p>
		<p><label for="fe_category_ids"><strong><?php esc_html_e( 'Categories', 'fulfillment-events-for-woocommerce' ); ?></strong></label><br>
			<select id="fe_category_ids" name="fe_category_ids[]" class="wc-enhanced-select" multiple style="width:100%;" data-placeholder="<?php esc_attr_e( 'Choose categories', 'fulfillment-events-for-woocommerce' ); ?>">
				<?php foreach ( is_array( $cats ) ? $cats : array() as $cat ) : ?>
					<option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( in_array( (int) $cat->term_id, $selected_cats, true ) ); ?>><?php echo esc_html( $cat->name ); ?></option>
				<?php endforeach; ?>
			</select></p>
		<p><label for="fe_product_ids"><strong><?php esc_html_e( 'Individual products', 'fulfillment-events-for-woocommerce' ); ?></strong></label><br>
			<select id="fe_product_ids" name="fe_product_ids[]" class="wc-product-search" multiple style="width:100%;" data-placeholder="<?php esc_attr_e( 'Search for a product', 'fulfillment-events-for-woocommerce' ); ?>" data-action="woocommerce_json_search_products">
				<?php foreach ( $selected_prod as $pid ) : ?>
					<?php $product = wc_get_product( $pid ); ?>
					<?php if ( $product ) : ?>
						<option value="<?php echo esc_attr( $pid ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
					<?php endif; ?>
				<?php endforeach; ?>
			</select></p>
		<p><label for="fe_excluded_ids"><strong><?php esc_html_e( 'Exclude products', 'fulfillment-events-for-woocommerce' ); ?></strong></label><br>
			<select id="fe_excluded_ids" name="fe_excluded_ids[]" class="wc-product-search" multiple style="width:100%;" data-placeholder="<?php esc_attr_e( 'Search for a product', 'fulfillment-events-for-woocommerce' ); ?>" data-action="woocommerce_json_search_products">
				<?php foreach ( $excluded_prod as $pid ) : ?>
					<?php $product = wc_get_product( $pid ); ?>
					<?php if ( $product ) : ?>
						<option value="<?php echo esc_attr( $pid ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
					<?php endif; ?>
				<?php endforeach; ?>
			</select></p>
		<p class="description"><?php esc_html_e( 'Products listed here are left out of this event even when one of the categories above includes them.', 'fulfillment-events-for-woocommerce' ); ?></p>
		<?php
	}
}
