<?php
/**
 * The zone x fulfillment type grid shown in the event editor.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders one cell per shipping zone and type, showing the zone default and
 * the event's overrides, with a small form to change them.
 */
class Fe_Zone_Grid {

	/**
	 * Zones in grid order: each shipping zone, then the catch-all zone 0.
	 *
	 * @return array[] Rows of {id, name, methods: type => WC_Shipping_Method}.
	 */
	private static function zones() {
		$zones = array();
		foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
			$zones[] = array(
				'id'      => (int) $zone['zone_id'],
				'name'    => (string) $zone['zone_name'],
				'methods' => self::methods_by_type( $zone['shipping_methods'] ),
			);
		}
		$rest    = new WC_Shipping_Zone( 0 );
		$zones[] = array(
			'id'      => 0,
			'name'    => __( 'Locations not covered by your other zones', 'fulfillment-events-for-woocommerce' ),
			'methods' => self::methods_by_type( $rest->get_shipping_methods() ),
		);
		return $zones;
	}

	/**
	 * Maps a zone's method instances to fulfillment types.
	 *
	 * @param array $methods Method instances.
	 * @return array<string,WC_Shipping_Method>
	 */
	private static function methods_by_type( $methods ) {
		$out = array();
		foreach ( (array) $methods as $method ) {
			$type = 0 === strpos( (string) $method->id, 'fe_' ) ? substr( (string) $method->id, 3 ) : '';
			if ( in_array( $type, Fe_Meta::TYPES, true ) && ! isset( $out[ $type ] ) ) {
				$out[ $type ] = $method;
			}
		}
		return $out;
	}

	/**
	 * Plain-text price, "Free" for zero.
	 *
	 * @param float $cost Cost.
	 * @return string
	 */
	private static function money( $cost ) {
		return (float) $cost > 0 ? html_entity_decode( wp_strip_all_tags( wc_price( $cost ) ), ENT_QUOTES, 'UTF-8' ) : __( 'Free', 'fulfillment-events-for-woocommerce' );
	}

	/**
	 * Pill class and text for a cell.
	 *
	 * @param object|null $method Method instance or null.
	 * @param array       $rules  The cell's saved rules.
	 * @return array{0:string,1:string}
	 */
	private static function pill( $method, array $rules ) {
		if ( ! $method ) {
			return array( 'none', __( 'Not in zone', 'fulfillment-events-for-woocommerce' ) );
		}
		$default = (float) $method->get_option( 'cost', 0 );
		foreach ( $rules as $rule ) {
			if ( empty( $rule['date'] ) && 'off' === $rule['mode'] ) {
				return array( 'off', __( 'Turned off', 'fulfillment-events-for-woocommerce' ) );
			}
			if ( empty( $rule['date'] ) && 'on' === $rule['mode'] ) {
				/* translators: %s: price */
				return array( 'override', sprintf( __( '%s for this event', 'fulfillment-events-for-woocommerce' ), self::money( null === $rule['cost'] ? $default : $rule['cost'] ) ) );
			}
		}
		/* translators: %s: price or "Free" */
		return array( 'default', sprintf( __( '%s default', 'fulfillment-events-for-woocommerce' ), self::money( $default ) ) );
	}

	/**
	 * Meta box callback.
	 *
	 * @param WP_Post $post Event post.
	 */
	public static function render( $post ) {
		$event = Fe_Event_Repo::get( $post->ID );
		$dates = $event ? array_column( $event['dates'], 'date' ) : array();
		$saved = array();
		foreach ( Fe_Zone_Rule_Repo::for_event( $post->ID ) as $rule ) {
			$saved[ $rule['zone_id'] ][ $rule['type'] ][] = $rule;
		}
		$labels = array(
			'delivery' => __( 'Delivery', 'fulfillment-events-for-woocommerce' ),
			'pickup'   => __( 'Pickup', 'fulfillment-events-for-woocommerce' ),
			'courier'  => __( 'Courier', 'fulfillment-events-for-woocommerce' ),
		);
		?>
		<p class="description"><?php esc_html_e( 'Each cell starts from the shipping zone setting. Turn a type off for this event, or give it a different cost, optionally for one date only. Dates added in this screen show up in the date lists after you save.', 'fulfillment-events-for-woocommerce' ); ?></p>
		<div class="fe-legend">
			<span class="fe-pill fe-pill--default"><?php esc_html_e( 'Zone default', 'fulfillment-events-for-woocommerce' ); ?></span>
			<span class="fe-pill fe-pill--override"><?php esc_html_e( 'Override', 'fulfillment-events-for-woocommerce' ); ?></span>
			<span class="fe-pill fe-pill--off"><?php esc_html_e( 'Turned off', 'fulfillment-events-for-woocommerce' ); ?></span>
			<span class="fe-pill fe-pill--none"><?php esc_html_e( 'Not in zone', 'fulfillment-events-for-woocommerce' ); ?></span>
		</div>
		<table class="widefat fe-grid">
			<thead><tr>
				<th><?php esc_html_e( 'Zone', 'fulfillment-events-for-woocommerce' ); ?></th>
				<?php foreach ( $labels as $label ) : ?>
					<th><?php echo esc_html( $label ); ?></th>
				<?php endforeach; ?>
			</tr></thead>
			<tbody>
			<?php foreach ( self::zones() as $zone ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $zone['name'] ); ?></th>
					<?php foreach ( array_keys( $labels ) as $type ) : ?>
						<?php
						$method = isset( $zone['methods'][ $type ] ) ? $zone['methods'][ $type ] : null;
						$rules  = isset( $saved[ $zone['id'] ][ $type ] ) ? $saved[ $zone['id'] ][ $type ] : array();
						$pill   = self::pill( $method, $rules );
						?>
						<td>
							<span class="fe-pill fe-pill--<?php echo esc_attr( $pill[0] ); ?>"><?php echo esc_html( $pill[1] ); ?></span>
							<?php if ( $method ) : ?>
								<?php foreach ( array_merge( $rules, array( array() ) ) as $n => $rule ) : ?>
									<?php self::cell_form( $zone['id'], $type, $n, $rule, $dates ); ?>
								<?php endforeach; ?>
							<?php endif; ?>
						</td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * One rule form inside a cell. An empty $rule renders the "add" row.
	 *
	 * @param int    $zone  Zone id.
	 * @param string $type  Fulfillment type.
	 * @param int    $n     Row index within the cell.
	 * @param array  $rule  Saved rule or empty.
	 * @param array  $dates Event dates.
	 */
	private static function cell_form( $zone, $type, $n, array $rule, array $dates ) {
		$name = 'fe_rules[' . (int) $zone . '][' . $type . '][' . (int) $n . ']';
		$mode = isset( $rule['mode'] ) ? $rule['mode'] : 'inherit';
		$cost = isset( $rule['cost'] ) && null !== $rule['cost'] ? $rule['cost'] : '';
		$date = isset( $rule['date'] ) ? (string) $rule['date'] : '';
		// A saved date that is no longer an event date stays selectable so saving does not drop it silently.
		$options = array_values( array_unique( array_merge( $dates, '' !== $date ? array( $date ) : array() ) ) );
		?>
		<div class="fe-cell-form">
			<select name="<?php echo esc_attr( $name ); ?>[mode]" aria-label="<?php esc_attr_e( 'Mode', 'fulfillment-events-for-woocommerce' ); ?>">
				<option value="inherit" <?php selected( $mode, 'inherit' ); ?>><?php esc_html_e( 'Use zone default', 'fulfillment-events-for-woocommerce' ); ?></option>
				<option value="on" <?php selected( $mode, 'on' ); ?>><?php esc_html_e( 'On', 'fulfillment-events-for-woocommerce' ); ?></option>
				<option value="off" <?php selected( $mode, 'off' ); ?>><?php esc_html_e( 'Off', 'fulfillment-events-for-woocommerce' ); ?></option>
			</select>
			<input type="number" min="0" step="0.01" name="<?php echo esc_attr( $name ); ?>[cost]" value="<?php echo esc_attr( $cost ); ?>" placeholder="<?php esc_attr_e( 'Cost', 'fulfillment-events-for-woocommerce' ); ?>" aria-label="<?php esc_attr_e( 'Cost', 'fulfillment-events-for-woocommerce' ); ?>">
			<select name="<?php echo esc_attr( $name ); ?>[date]" aria-label="<?php esc_attr_e( 'Only on', 'fulfillment-events-for-woocommerce' ); ?>">
				<option value=""><?php esc_html_e( 'All dates', 'fulfillment-events-for-woocommerce' ); ?></option>
				<?php foreach ( $options as $d ) : ?>
					<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $date, $d ); ?>><?php echo esc_html( $d ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}
}
