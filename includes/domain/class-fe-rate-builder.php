<?php
/**
 * Pure rate builder: an event's dates become one rate spec per date.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * Builds shipping rate specs for an event, a fulfillment type and a zone.
 * Labels are English here; the WooCommerce adapter passes translated pieces
 * and a locale-aware date formatter.
 */
class Fe_Rate_Builder {

	const DEFAULT_COURIER_WINDOW = "We'll coordinate the time with you";

	/**
	 * Default date label, e.g. "Thu, Dec 10".
	 *
	 * @param string $ymd Date as Y-m-d.
	 * @return string
	 */
	public static function format_date( $ymd ) {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $ymd );
		return $dt ? $dt->format( 'D, M j' ) : (string) $ymd;
	}

	/**
	 * Builds the rate specs.
	 *
	 * @param array         $event               Event from Fe_Event_Repo::get().
	 * @param string        $type                delivery|pickup|courier.
	 * @param int           $zone_id             Shipping zone id.
	 * @param array         $rules               Zone rules for the event.
	 * @param float         $default_cost        Method default cost.
	 * @param string        $default_window      Method default window text.
	 * @param array         $locations_by_id     Location rows keyed by id.
	 * @param int|null      $default_location_id Default location id.
	 * @param string        $today               Y-m-d, store-local; earlier dates are skipped.
	 * @param callable|null $formatter           Optional Y-m-d => label date.
	 * @param string|null   $courier_window      Optional courier window text.
	 * @return array[] Each {id:string (the date; the caller prefixes), date, label, where (location name or ''), when (formatted date), cost, meta}.
	 */
	public static function build( array $event, $type, $zone_id, array $rules, $default_cost, $default_window, array $locations_by_id, $default_location_id, $today, $formatter = null, $courier_window = null ) {
		$specs = array();
		$fmt   = is_callable( $formatter ) ? $formatter : array( __CLASS__, 'format_date' );
		$cwin  = null === $courier_window ? self::DEFAULT_COURIER_WINDOW : (string) $courier_window;
		$dates = isset( $event['dates'] ) && is_array( $event['dates'] ) ? $event['dates'] : array();

		foreach ( $dates as $row ) {
			$date = (string) $row['date'];
			if ( $date < $today ) {
				continue;
			}
			$delivery = ! empty( $row['delivery'] );
			$pickup   = ! empty( $row['pickup'] );

			$location = '';
			$loc_name = '';
			if ( 'delivery' === $type ) {
				if ( ! $delivery ) {
					continue;
				}
				$window = ! empty( $row['delivery_window'] ) ? (string) $row['delivery_window'] : (string) $default_window;
			} elseif ( 'pickup' === $type ) {
				if ( ! $pickup ) {
					continue;
				}
				$window = ! empty( $row['pickup_window'] ) ? (string) $row['pickup_window'] : (string) $default_window;
				$loc_id = ! empty( $row['location_id'] ) ? (int) $row['location_id'] : $default_location_id;
				// A deleted or unknown location falls back to the default one.
				if ( null !== $loc_id && ! isset( $locations_by_id[ $loc_id ] ) ) {
					$loc_id = $default_location_id;
				}
				if ( null !== $loc_id && isset( $locations_by_id[ $loc_id ] ) ) {
					$loc      = $locations_by_id[ $loc_id ];
					$loc_name = (string) $loc['name'];
					$location = '' !== (string) $loc['address'] ? $loc_name . ', ' . $loc['address'] : $loc_name;
				}
			} elseif ( 'courier' === $type ) {
				// Dates saved before 0.5.0 have no courier flag: courier ran on any date with delivery or pickup.
				$courier = array_key_exists( 'courier', $row ) ? ! empty( $row['courier'] ) : ( $delivery || $pickup );
				if ( ! $courier ) {
					continue;
				}
				$window = $cwin;
			} else {
				continue;
			}

			$resolved = Fe_Zone_Rules::resolve( $rules, $zone_id, $type, $date, $default_cost );
			if ( ! $resolved['enabled'] ) {
				continue;
			}

			$when = call_user_func( $fmt, $date );
			if ( 'delivery' === $type ) {
				$label = 'Delivery · ' . $when;
			} elseif ( 'pickup' === $type ) {
				$label = ( '' !== $loc_name ? 'Pickup at ' . $loc_name : 'Pickup' ) . ' · ' . $when;
			} else {
				$label = 'Courier pickup · ' . $when;
			}

			$specs[] = array(
				'id'    => $date,
				'date'  => $date,
				'label' => $label,
				'where' => $loc_name,
				'when'  => $when,
				'cost'  => (float) $resolved['cost'],
				'meta'  => array(
					'fe_event_id'   => (int) $event['id'],
					'fe_event_name' => (string) $event['title'],
					'fe_date'       => $date,
					'fe_type'       => $type,
					'fe_window'     => $window,
					'fe_location'   => $location,
				),
			);
		}
		return $specs;
	}
}
