<?php
/**
 * The zone x fulfillment type grid shown in the event editor.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
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
	 * Pure: the cell's form rows. Row 0 is the setting for all dates; then
	 * one row per date, so a saved override never adds an extra row. A rule
	 * for a date that is no longer an event date keeps its row, so saving
	 * does not drop it silently.
	 *
	 * @param array    $rules The cell's saved rules.
	 * @param string[] $dates Event dates, Y-m-d.
	 * @return array[] Rows of {date:string ('' for all dates), mode, cost:string}.
	 */
	public static function cell_rows( array $rules, array $dates ) {
		$by_date = array();
		foreach ( $rules as $rule ) {
			$key             = isset( $rule['date'] ) && null !== $rule['date'] ? (string) $rule['date'] : '';
			$by_date[ $key ] = $rule;
		}
		$all = array_values( array_unique( array_merge( $dates, array_diff( array_keys( $by_date ), array( '' ) ) ) ) );
		sort( $all );
		$rows = array();
		foreach ( array_merge( array( '' ), $all ) as $date ) {
			$rule   = isset( $by_date[ $date ] ) ? $by_date[ $date ] : array();
			$rows[] = array(
				'date' => (string) $date,
				'mode' => isset( $rule['mode'] ) ? (string) $rule['mode'] : 'inherit',
				'cost' => isset( $rule['cost'] ) && null !== $rule['cost'] ? (string) $rule['cost'] : '',
			);
		}
		return $rows;
	}

	/**
	 * Pure: cell state for its colour: 'default', 'override' or 'off'. A cell
	 * with only per-date changes reads as an override.
	 *
	 * @param array[] $rows Result of cell_rows().
	 * @return string
	 */
	public static function cell_state( array $rows ) {
		$first = $rows ? $rows[0]['mode'] : 'inherit';
		if ( 'off' === $first ) {
			return 'off';
		}
		foreach ( $rows as $row ) {
			if ( 'inherit' !== $row['mode'] ) {
				return 'override';
			}
		}
		return 'default';
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
		$labels   = array(
			'delivery' => __( 'Delivery', 'fulfillment-events-for-woocommerce' ),
			'pickup'   => __( 'Pickup', 'fulfillment-events-for-woocommerce' ),
			'courier'  => __( 'Courier', 'fulfillment-events-for-woocommerce' ),
		);
		$settings = admin_url( 'admin.php?page=wc-settings&tab=shipping' );
		?>
		<p class="description">
			<?php esc_html_e( 'Each zone starts from its shipping zone price. Change a price or turn a type off for this event only. Dates added above show up here after you save.', 'fulfillment-events-for-woocommerce' ); ?>
			<a href="<?php echo esc_url( $settings ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Edit shipping zones and their prices', 'fulfillment-events-for-woocommerce' ); ?></a>
		</p>
		<div class="fe-legend" aria-hidden="true">
			<span class="fe-key fe-key--default"><?php esc_html_e( 'Zone price', 'fulfillment-events-for-woocommerce' ); ?></span>
			<span class="fe-key fe-key--override"><?php esc_html_e( 'Changed for this event', 'fulfillment-events-for-woocommerce' ); ?></span>
			<span class="fe-key fe-key--off"><?php esc_html_e( 'Off for this event', 'fulfillment-events-for-woocommerce' ); ?></span>
		</div>
		<table class="widefat fe-grid">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Zone', 'fulfillment-events-for-woocommerce' ); ?></th>
				<?php foreach ( $labels as $label ) : ?>
					<th scope="col"><?php echo esc_html( $label ); ?></th>
				<?php endforeach; ?>
			</tr></thead>
			<tbody>
			<?php foreach ( self::zones() as $zone ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $zone['name'] ); ?></th>
					<?php foreach ( $labels as $type => $label ) : ?>
						<?php
						$method = isset( $zone['methods'][ $type ] ) ? $zone['methods'][ $type ] : null;
						if ( ! $method ) {
							echo '<td class="fe-zcell fe-zcell--none"><span class="fe-zcell__none">' . esc_html__( 'Not offered in this zone', 'fulfillment-events-for-woocommerce' ) . '</span></td>';
							continue;
						}
						$rows    = self::cell_rows( isset( $saved[ $zone['id'] ][ $type ] ) ? $saved[ $zone['id'] ][ $type ] : array(), $dates );
						$default = self::money( (float) $method->get_option( 'cost', 0 ) );
						/* translators: 1: zone name, 2: fulfillment type */
						$context = sprintf( __( '%1$s, %2$s', 'fulfillment-events-for-woocommerce' ), $zone['name'], $label );
						?>
						<td class="fe-zcell fe-zcell--<?php echo esc_attr( self::cell_state( $rows ) ); ?>">
							<?php self::cell_row( $zone['id'], $type, 0, $rows[0], $default, $context ); ?>
							<?php if ( count( $rows ) > 2 ) : ?>
								<?php
								$changed = count(
									array_filter(
										array_slice( $rows, 1 ),
										function ( $row ) {
											return 'inherit' !== $row['mode'];
										}
									)
								);
								?>
								<details class="fe-zdates" <?php echo $changed ? 'open' : ''; ?>>
									<summary>
										<?php
										echo esc_html(
											$changed
												/* translators: %d: number of dates with their own setting */
												? sprintf( _n( 'Different on %d date', 'Different on %d dates', $changed, 'fulfillment-events-for-woocommerce' ), $changed )
												: __( 'Different on one date', 'fulfillment-events-for-woocommerce' )
										);
										?>
									</summary>
									<?php foreach ( array_slice( $rows, 1 ) as $i => $row ) : ?>
										<?php self::cell_row( $zone['id'], $type, $i + 1, $row, $default, $context ); ?>
									<?php endforeach; ?>
								</details>
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
	 * One setting inside a cell: the all-dates row (date '') or a date row.
	 *
	 * @param int    $zone    Zone id.
	 * @param string $type    Fulfillment type.
	 * @param int    $n       Row index within the cell.
	 * @param array  $row     Row from cell_rows().
	 * @param string $default Zone price text, e.g. "$9.00" or "Free".
	 * @param string $context Zone and type, for screen-reader labels.
	 */
	private static function cell_row( $zone, $type, $n, array $row, $default, $context ) {
		$name    = 'fe_rules[' . (int) $zone . '][' . $type . '][' . (int) $n . ']';
		$is_date = '' !== $row['date'];
		$when    = $is_date ? self::short_date( $row['date'] ) : __( 'All dates', 'fulfillment-events-for-woocommerce' );
		$label   = $context . ', ' . $when;
		?>
		<div class="fe-zrow<?php echo $is_date ? ' fe-zrow--date' : ''; ?><?php echo 'on' === $row['mode'] ? ' is-custom' : ''; ?>">
			<?php if ( $is_date ) : ?>
				<span class="fe-zrow__date"><?php echo esc_html( $when ); ?></span>
			<?php endif; ?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[date]" value="<?php echo esc_attr( $row['date'] ); ?>">
			<select class="fe-zmode" name="<?php echo esc_attr( $name ); ?>[mode]" aria-label="<?php echo esc_attr( $label ); ?>">
				<option value="inherit" <?php selected( $row['mode'], 'inherit' ); ?>>
					<?php
					echo esc_html(
						$is_date
							? __( 'Same as all dates', 'fulfillment-events-for-woocommerce' )
							/* translators: %s: zone price or "Free" */
							: sprintf( __( 'Zone price (%s)', 'fulfillment-events-for-woocommerce' ), $default )
					);
					?>
				</option>
				<option value="on" <?php selected( $row['mode'], 'on' ); ?>><?php esc_html_e( 'Custom price', 'fulfillment-events-for-woocommerce' ); ?></option>
				<option value="off" <?php selected( $row['mode'], 'off' ); ?>><?php esc_html_e( 'Off', 'fulfillment-events-for-woocommerce' ); ?></option>
			</select>
			<span class="fe-zcost">
				<span class="fe-zcost__symbol" aria-hidden="true"><?php echo esc_html( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ); ?></span>
				<input type="number" min="0" step="0.01" name="<?php echo esc_attr( $name ); ?>[cost]" value="<?php echo esc_attr( $row['cost'] ); ?>" placeholder="0.00" aria-label="<?php /* translators: %s: zone, type and date */ echo esc_attr( sprintf( __( 'Price for %s', 'fulfillment-events-for-woocommerce' ), $label ) ); ?>">
			</span>
		</div>
		<?php
	}

	/**
	 * Short date label, e.g. "Thu, Dec 10".
	 *
	 * @param string $ymd Y-m-d.
	 * @return string
	 */
	private static function short_date( $ymd ) {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $ymd, wp_timezone() );
		return $dt ? wp_date( 'D, M j', $dt->getTimestamp() ) : (string) $ymd;
	}
}
