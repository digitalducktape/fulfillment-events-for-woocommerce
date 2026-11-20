<?php
/**
 * Shipping zones as the event screen needs them, and the price list.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * Lists which zones offer each Event method, and renders the rarely used
 * "Change a price for this event" list.
 */
class Fe_Zone_Grid {

	/**
	 * Per-request cache of zones_by_type().
	 *
	 * @var array|null
	 */
	private static $zones = null;

	/**
	 * Zones that offer each Event method: each shipping zone in WooCommerce
	 * order, then the catch-all zone 0.
	 *
	 * @return array type (delivery|pickup|courier) => [zone id => zone name].
	 */
	public static function zones_by_type() {
		if ( null !== self::$zones ) {
			return self::$zones;
		}
		$rows = array();
		foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
			$rows[] = array( (int) $zone['zone_id'], (string) $zone['zone_name'], $zone['shipping_methods'] );
		}
		$rest   = new WC_Shipping_Zone( 0 );
		$rows[] = array( 0, __( 'Locations not covered by your other zones', 'fulfillment-events-for-woocommerce' ), $rest->get_shipping_methods() );

		$out = array(
			'delivery' => array(),
			'pickup'   => array(),
			'courier'  => array(),
		);
		foreach ( $rows as $row ) {
			foreach ( (array) $row[2] as $method ) {
				$type = 0 === strpos( (string) $method->id, 'fe_' ) ? substr( (string) $method->id, 3 ) : '';
				if ( isset( $out[ $type ] ) ) {
					$out[ $type ][ $row[0] ] = $row[1];
				}
			}
		}
		self::$zones = $out;
		return $out;
	}

	/**
	 * A zone's name, or "Zone #n" for one WooCommerce no longer has.
	 *
	 * @param int $zone_id Zone id.
	 * @return string
	 */
	public static function zone_name( $zone_id ) {
		foreach ( self::zones_by_type() as $zones ) {
			if ( isset( $zones[ (int) $zone_id ] ) ) {
				return $zones[ (int) $zone_id ];
			}
		}
		/* translators: %d: shipping zone id */
		return sprintf( __( 'Zone #%d', 'fulfillment-events-for-woocommerce' ), (int) $zone_id );
	}

	/**
	 * Short date label, e.g. "Thu, Dec 10".
	 *
	 * @param string $ymd Y-m-d.
	 * @return string
	 */
	public static function short_date( $ymd ) {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $ymd, wp_timezone() );
		return $dt ? wp_date( 'D, M j', $dt->getTimestamp() ) : (string) $ymd;
	}

	/**
	 * The "Change a price for this event" list: one row per price change
	 * ("on" rule with a cost), closed unless the event has one.
	 *
	 * @param array    $rules The event's zone rules.
	 * @param string[] $dates The event's dates.
	 */
	public static function render_prices( array $rules, array $dates ) {
		$prices = array_values(
			array_filter(
				$rules,
				function ( $rule ) {
					return 'on' === $rule['mode'];
				}
			)
		);
		?>
		<details class="fe-prices" <?php echo $prices ? 'open' : ''; ?>>
			<summary><?php esc_html_e( 'Change a price for this event', 'fulfillment-events-for-woocommerce' ); ?></summary>
			<p class="description"><?php esc_html_e( 'Prices normally come from your shipping zones. Add a row only when this event charges something different.', 'fulfillment-events-for-woocommerce' ); ?></p>
			<div class="fe-rows" id="fe-price-rows" data-next="<?php echo esc_attr( count( $prices ) ); ?>">
				<?php foreach ( $prices as $i => $rule ) : ?>
					<?php self::price_row( (string) $i, $rule, $dates ); ?>
				<?php endforeach; ?>
			</div>
			<template id="fe-price-template"><?php self::price_row( '__p__', array(), $dates ); ?></template>
			<p><button type="button" class="button" data-fe-add="price"><?php esc_html_e( 'Add a price change', 'fulfillment-events-for-woocommerce' ); ?></button></p>
		</details>
		<?php
	}

	/**
	 * One price row.
	 *
	 * @param string   $i     Row index or the __p__ placeholder.
	 * @param array    $rule  Rule, or empty for a blank row.
	 * @param string[] $dates Event dates.
	 */
	private static function price_row( $i, array $rule, array $dates ) {
		$name  = 'fe_prices[' . $i . ']';
		$zone  = isset( $rule['zone_id'] ) ? (int) $rule['zone_id'] : null;
		$type  = isset( $rule['type'] ) ? (string) $rule['type'] : 'delivery';
		$date  = isset( $rule['date'] ) && null !== $rule['date'] ? (string) $rule['date'] : '';
		$cost  = isset( $rule['cost'] ) && null !== $rule['cost'] ? (string) $rule['cost'] : '';
		$zones = array();
		foreach ( self::zones_by_type() as $by_type ) {
			$zones += $by_type;
		}
		if ( null !== $zone && ! isset( $zones[ $zone ] ) ) {
			$zones[ $zone ] = self::zone_name( $zone );
		}
		$options = array_values( array_unique( array_merge( $dates, '' !== $date ? array( $date ) : array() ) ) );
		$types   = array(
			'delivery' => __( 'Delivery', 'fulfillment-events-for-woocommerce' ),
			'pickup'   => __( 'Pickup', 'fulfillment-events-for-woocommerce' ),
			'courier'  => __( 'Courier', 'fulfillment-events-for-woocommerce' ),
		);
		?>
		<div class="fe-row fe-price" data-fe-row>
			<select name="<?php echo esc_attr( $name ); ?>[zone_id]" aria-label="<?php esc_attr_e( 'Zone', 'fulfillment-events-for-woocommerce' ); ?>">
				<?php foreach ( $zones as $id => $label ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $zone, $id ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="<?php echo esc_attr( $name ); ?>[type]" aria-label="<?php esc_attr_e( 'Service', 'fulfillment-events-for-woocommerce' ); ?>">
				<?php foreach ( $types as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="<?php echo esc_attr( $name ); ?>[date]" aria-label="<?php esc_attr_e( 'Date', 'fulfillment-events-for-woocommerce' ); ?>">
				<option value=""><?php esc_html_e( 'All dates', 'fulfillment-events-for-woocommerce' ); ?></option>
				<?php foreach ( $options as $d ) : ?>
					<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $date, $d ); ?>><?php echo esc_html( self::short_date( $d ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="fe-money"><span aria-hidden="true"><?php echo esc_html( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ); ?></span>
				<input type="number" min="0" step="0.01" name="<?php echo esc_attr( $name ); ?>[cost]" value="<?php echo esc_attr( $cost ); ?>" placeholder="0.00" aria-label="<?php esc_attr_e( 'Price', 'fulfillment-events-for-woocommerce' ); ?>">
			</span>
			<button type="button" class="button-link button-link-delete" data-fe-remove aria-label="<?php esc_attr_e( 'Remove this price change', 'fulfillment-events-for-woocommerce' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
		</div>
		<?php
	}
}
