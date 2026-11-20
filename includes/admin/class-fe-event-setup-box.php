<?php
/**
 * The "Event setup" box: one screen for sales, dates, products and prices.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the event screen top to bottom: when it sells, what runs on each
 * date, which products are in it (with exceptions), and price changes.
 */
class Fe_Event_Setup_Box {

	/**
	 * Meta box callback.
	 *
	 * @param WP_Post $post Event post.
	 */
	public static function render( $post ) {
		wp_nonce_field( Fe_Event_Editor::NONCE_ACTION, Fe_Event_Editor::NONCE_FIELD );
		$event = Fe_Event_Repo::get( $post->ID );
		$rows  = $event ? $event['dates'] : array();
		$dates = array_column( $rows, 'date' );
		$rules = Fe_Zone_Rule_Repo::for_event( $post->ID );
		$zones = Fe_Zone_Grid::zones_by_type();
		$ids   = array();
		foreach ( $zones as $type => $list ) {
			$ids[ $type ] = array_keys( $list );
		}
		$ticks = Fe_Event_Setup::ticks_from_rules( $rules, $dates, $ids );
		?>
		<div class="fe-setup">
			<?php self::section_sales( $event ); ?>
			<?php self::section_dates( $rows, $ticks, $zones ); ?>
			<?php self::section_products( $post->ID, $event, $dates ); ?>
			<?php Fe_Zone_Grid::render_prices( $rules, $dates ); ?>
		</div>
		<?php
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
	 * When it sells.
	 *
	 * @param array|null $event Event.
	 */
	private static function section_sales( $event ) {
		?>
		<section class="fe-section">
			<h3 class="fe-section__title"><?php esc_html_e( 'When it sells', 'fulfillment-events-for-woocommerce' ); ?></h3>
			<div class="fe-sales">
				<label><?php esc_html_e( 'Opens', 'fulfillment-events-for-woocommerce' ); ?>
					<input type="datetime-local" id="fe_sales_open" name="fe_sales_open" value="<?php echo esc_attr( $event ? self::to_input( $event['sales_open'] ) : '' ); ?>"></label>
				<label><?php esc_html_e( 'Closes', 'fulfillment-events-for-woocommerce' ); ?>
					<input type="datetime-local" id="fe_sales_close" name="fe_sales_close" value="<?php echo esc_attr( $event ? self::to_input( $event['sales_close'] ) : '' ); ?>"></label>
			</div>
			<p class="description"><?php esc_html_e( 'Leave either empty for no limit. Times use the store timezone.', 'fulfillment-events-for-woocommerce' ); ?></p>
		</section>
		<?php
	}

	/**
	 * Dates: one card per date.
	 *
	 * @param array $rows  Date rows.
	 * @param array $ticks [date][type][zone] => bool.
	 * @param array $zones type => [zone id => name].
	 */
	private static function section_dates( array $rows, array $ticks, array $zones ) {
		$locs = Fe_Location_Repo::all();
		?>
		<section class="fe-section">
			<h3 class="fe-section__title"><?php esc_html_e( 'Dates', 'fulfillment-events-for-woocommerce' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Tick what runs on each date and which zones it reaches. Pickup is open to every zone that offers it.', 'fulfillment-events-for-woocommerce' ); ?></p>
			<div class="fe-rows" id="fe-date-rows" data-next="<?php echo esc_attr( count( $rows ) ); ?>">
				<?php foreach ( array_values( $rows ) as $i => $row ) : ?>
					<?php self::date_card( (string) $i, $row, isset( $ticks[ $row['date'] ] ) ? $ticks[ $row['date'] ] : array(), $zones, $locs ); ?>
				<?php endforeach; ?>
			</div>
			<template id="fe-date-template"><?php self::date_card( '__i__', array(), array(), $zones, $locs ); ?></template>
			<p class="fe-actions">
				<button type="button" class="button" data-fe-add="date"><?php esc_html_e( 'Add date', 'fulfillment-events-for-woocommerce' ); ?></button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Edit shipping zones and their prices', 'fulfillment-events-for-woocommerce' ); ?></a>
			</p>
		</section>
		<?php
	}

	/**
	 * One date card. A blank card (the template) runs everything in every zone.
	 *
	 * @param string  $i     Row index or __i__.
	 * @param array   $row   Date row, empty for a new one.
	 * @param array   $ticks [type][zone] => bool for this date.
	 * @param array   $zones type => [zone id => name].
	 * @param array[] $locs  Locations.
	 */
	private static function date_card( $i, array $row, array $ticks, array $zones, array $locs ) {
		$name  = 'fe_dates[' . $i . ']';
		$isnew = ! $row;
		$on    = array(
			'delivery' => $isnew || ! empty( $row['delivery'] ),
			'pickup'   => $isnew ? false : ! empty( $row['pickup'] ),
			'courier'  => $isnew || ! isset( $row['courier'] ) || ! empty( $row['courier'] ),
		);
		$get   = function ( $key ) use ( $row ) {
			return isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
		};
		?>
		<div class="fe-date" data-fe-row>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[zones_posted]" value="1">
			<div class="fe-date__head">
				<input type="date" name="<?php echo esc_attr( $name ); ?>[date]" value="<?php echo esc_attr( $get( 'date' ) ); ?>" aria-label="<?php esc_attr_e( 'Date', 'fulfillment-events-for-woocommerce' ); ?>">
				<button type="button" class="button-link button-link-delete" data-fe-remove aria-label="<?php esc_attr_e( 'Remove this date', 'fulfillment-events-for-woocommerce' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span> <?php esc_html_e( 'Remove', 'fulfillment-events-for-woocommerce' ); ?></button>
			</div>
			<div class="fe-service<?php echo $on['delivery'] ? '' : ' is-off'; ?>">
				<label class="fe-service__toggle"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delivery]" value="1" <?php checked( $on['delivery'] ); ?>> <?php esc_html_e( 'Delivery', 'fulfillment-events-for-woocommerce' ); ?></label>
				<input type="text" class="fe-service__time" name="<?php echo esc_attr( $name ); ?>[delivery_window]" value="<?php echo esc_attr( $get( 'delivery_window' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. 10am to 2pm', 'fulfillment-events-for-woocommerce' ); ?>" aria-label="<?php esc_attr_e( 'Delivery time', 'fulfillment-events-for-woocommerce' ); ?>">
				<?php self::zone_ticks( $name, 'delivery', $zones, $ticks ); ?>
			</div>
			<div class="fe-service<?php echo $on['pickup'] ? '' : ' is-off'; ?>">
				<label class="fe-service__toggle"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[pickup]" value="1" <?php checked( $on['pickup'] ); ?>> <?php esc_html_e( 'Pickup', 'fulfillment-events-for-woocommerce' ); ?></label>
				<input type="text" class="fe-service__time" name="<?php echo esc_attr( $name ); ?>[pickup_window]" value="<?php echo esc_attr( $get( 'pickup_window' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. 10am to 2pm', 'fulfillment-events-for-woocommerce' ); ?>" aria-label="<?php esc_attr_e( 'Pickup time', 'fulfillment-events-for-woocommerce' ); ?>">
				<span class="fe-service__where"><span class="fe-service__lead"><?php esc_html_e( 'at', 'fulfillment-events-for-woocommerce' ); ?></span>
					<select name="<?php echo esc_attr( $name ); ?>[location_id]" aria-label="<?php esc_attr_e( 'Pickup place', 'fulfillment-events-for-woocommerce' ); ?>">
						<option value="0"><?php esc_html_e( 'Default location', 'fulfillment-events-for-woocommerce' ); ?></option>
						<?php foreach ( $locs as $loc ) : ?>
							<option value="<?php echo esc_attr( $loc['id'] ); ?>" <?php selected( (int) $get( 'location_id' ), (int) $loc['id'] ); ?>><?php echo esc_html( $loc['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</span>
			</div>
			<div class="fe-service<?php echo $on['courier'] ? '' : ' is-off'; ?>">
				<label class="fe-service__toggle"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[courier]" value="1" <?php checked( $on['courier'] ); ?>> <?php esc_html_e( 'Courier', 'fulfillment-events-for-woocommerce' ); ?></label>
				<span class="fe-service__time fe-service__time--none" aria-hidden="true"></span>
				<?php self::zone_ticks( $name, 'courier', $zones, $ticks ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * "to ☑ Zone ☑ Zone" for one service on one date.
	 *
	 * @param string $name  Row field prefix.
	 * @param string $type  delivery|courier.
	 * @param array  $zones type => [zone id => name].
	 * @param array  $ticks [type][zone] => bool; a missing zone is ticked.
	 */
	private static function zone_ticks( $name, $type, array $zones, array $ticks ) {
		$list = isset( $zones[ $type ] ) ? $zones[ $type ] : array();
		echo '<span class="fe-service__where"><span class="fe-service__lead">' . esc_html__( 'to', 'fulfillment-events-for-woocommerce' ) . '</span>';
		if ( ! $list ) {
			echo '<span class="fe-muted">' . esc_html__( 'No shipping zone offers this yet', 'fulfillment-events-for-woocommerce' ) . '</span></span>';
			return;
		}
		foreach ( $list as $zone_id => $zone_name ) {
			$on = ! isset( $ticks[ $type ][ $zone_id ] ) || $ticks[ $type ][ $zone_id ];
			echo '<label class="fe-zone"><input type="checkbox" name="' . esc_attr( $name ) . '[zones][' . esc_attr( $type ) . '][]" value="' . esc_attr( $zone_id ) . '"' . checked( $on, true, false ) . '> ' . esc_html( $zone_name ) . '</label>';
		}
		echo '</span>';
	}

	/**
	 * Products: categories, individual products and exceptions.
	 *
	 * @param int        $event_id Event id.
	 * @param array|null $event    Event.
	 * @param string[]   $dates    Saved dates.
	 */
	private static function section_products( $event_id, $event, array $dates ) {
		$cats       = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 500,
			)
		);
		$chosen     = Fe_Product_Links::term_ids_for_event( $event_id );
		$products   = Fe_Product_Links::product_ids_for_event( $event_id );
		$exceptions = array();
		foreach ( Fe_Product_Links::product_ids_for_event( $event_id, Fe_Product_Links::EXCLUDE_KEY ) as $pid ) {
			$exceptions[] = array(
				'product_id' => (int) $pid,
				'mode'       => 'exclude',
				'dates'      => array(),
			);
		}
		foreach ( $event ? $event['date_limits'] : array() as $pid => $limit ) {
			$exceptions[] = array(
				'product_id' => (int) $pid,
				'mode'       => 'only',
				'dates'      => (array) $limit,
			);
		}
		?>
		<section class="fe-section">
			<h3 class="fe-section__title"><?php esc_html_e( 'Products', 'fulfillment-events-for-woocommerce' ); ?></h3>
			<p class="fe-field"><label for="fe_category_ids"><?php esc_html_e( 'Categories', 'fulfillment-events-for-woocommerce' ); ?></label>
				<select id="fe_category_ids" name="fe_category_ids[]" class="wc-enhanced-select" multiple data-placeholder="<?php esc_attr_e( 'Choose categories', 'fulfillment-events-for-woocommerce' ); ?>">
					<?php foreach ( is_array( $cats ) ? $cats : array() as $cat ) : ?>
						<option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( in_array( (int) $cat->term_id, $chosen, true ) ); ?>><?php echo esc_html( $cat->name ); ?></option>
					<?php endforeach; ?>
				</select></p>
			<p class="fe-field"><label for="fe_product_ids"><?php esc_html_e( 'Individual products', 'fulfillment-events-for-woocommerce' ); ?></label>
				<select id="fe_product_ids" name="fe_product_ids[]" class="wc-product-search" multiple data-placeholder="<?php esc_attr_e( 'Search for a product', 'fulfillment-events-for-woocommerce' ); ?>" data-action="woocommerce_json_search_products">
					<?php self::product_options( $products ); ?>
				</select></p>
			<p class="description"><?php esc_html_e( 'Products in these categories, or chosen here, are in the event. Only the categories a product is directly in count.', 'fulfillment-events-for-woocommerce' ); ?></p>

			<h4 class="fe-subtitle"><?php esc_html_e( 'Exceptions', 'fulfillment-events-for-woocommerce' ); ?></h4>
			<p class="description"><?php esc_html_e( 'Limit a product to some of the dates, or take it out of this event. A product limited to dates gets its own shipping choice at checkout.', 'fulfillment-events-for-woocommerce' ); ?></p>
			<div class="fe-rows" id="fe-exception-rows" data-next="<?php echo esc_attr( count( $exceptions ) ); ?>">
				<?php foreach ( $exceptions as $n => $exception ) : ?>
					<?php self::exception_row( (string) $n, $exception, $dates ); ?>
				<?php endforeach; ?>
			</div>
			<template id="fe-exception-template"><?php self::exception_row( '__x__', array(), $dates ); ?></template>
			<p class="fe-actions"><button type="button" class="button" data-fe-add="exception"><?php esc_html_e( 'Add an exception', 'fulfillment-events-for-woocommerce' ); ?></button></p>
		</section>
		<?php
	}

	/**
	 * Selected options for a product search select.
	 *
	 * @param int[] $ids Product ids.
	 */
	private static function product_options( array $ids ) {
		foreach ( $ids as $pid ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
			if ( $product ) {
				echo '<option value="' . esc_attr( $pid ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ) . '</option>';
			}
		}
	}

	/**
	 * One exception row.
	 *
	 * @param string   $n         Row index or __x__.
	 * @param array    $exception {product_id, mode, dates}, empty for a new one.
	 * @param string[] $dates     Saved event dates.
	 */
	private static function exception_row( $n, array $exception, array $dates ) {
		$name    = 'fe_exceptions[' . $n . ']';
		$mode    = isset( $exception['mode'] ) ? $exception['mode'] : 'only';
		$checked = isset( $exception['dates'] ) ? (array) $exception['dates'] : array();
		?>
		<div class="fe-row fe-exception<?php echo 'exclude' === $mode ? ' is-exclude' : ''; ?>" data-fe-row>
			<select class="wc-product-search fe-exception__product" name="<?php echo esc_attr( $name ); ?>[product_id]" data-placeholder="<?php esc_attr_e( 'Search for a product', 'fulfillment-events-for-woocommerce' ); ?>" data-action="woocommerce_json_search_products" aria-label="<?php esc_attr_e( 'Product', 'fulfillment-events-for-woocommerce' ); ?>">
				<?php self::product_options( isset( $exception['product_id'] ) ? array( (int) $exception['product_id'] ) : array() ); ?>
			</select>
			<span class="fe-exception__choice">
				<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="only" <?php checked( 'only', $mode ); ?>> <?php esc_html_e( 'Only on', 'fulfillment-events-for-woocommerce' ); ?></label>
				<span class="fe-exception__dates">
				<?php if ( $dates ) : ?>
					<?php foreach ( $dates as $d ) : ?>
						<label class="fe-zone"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[dates][]" value="<?php echo esc_attr( $d ); ?>" <?php checked( in_array( $d, $checked, true ) ); ?>> <?php echo esc_html( Fe_Zone_Grid::short_date( $d ) ); ?></label>
					<?php endforeach; ?>
				<?php else : ?>
					<span class="fe-muted"><?php esc_html_e( 'Save to use new dates here', 'fulfillment-events-for-woocommerce' ); ?></span>
				<?php endif; ?>
				</span>
			</span>
			<label class="fe-exception__choice"><input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="exclude" <?php checked( 'exclude', $mode ); ?>> <?php esc_html_e( 'Not in this event', 'fulfillment-events-for-woocommerce' ); ?></label>
			<button type="button" class="button-link button-link-delete" data-fe-remove aria-label="<?php esc_attr_e( 'Remove this exception', 'fulfillment-events-for-woocommerce' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
		</div>
		<?php
	}
}
