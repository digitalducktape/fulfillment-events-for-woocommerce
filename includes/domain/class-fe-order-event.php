<?php
/**
 * Putting an order in an event from wp-admin.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * Pure rules behind the event fields on an order's shipping line. Staff may
 * pick any date an event is configured for: sales windows, closed dates,
 * zones and product limits only govern what customers are offered.
 */
class Fe_Order_Event {

	/**
	 * What staff can choose, per event.
	 *
	 * @param array[]       $events              Events from Fe_Event_Repo.
	 * @param array[]       $zones               Event methods in shipping zones: {zone_id, name, type, cost, window}.
	 * @param array         $rules_by_event      Event id => zone rules.
	 * @param array         $locations_by_id     Location rows keyed by id.
	 * @param int|null      $default_location_id Default location id.
	 * @param string        $today               Store-local Y-m-d.
	 * @param callable|null $formatter           Y-m-d => label date.
	 * @param string|null   $courier_window      Courier window text.
	 * @return array Event id => {title, draft, dates: date => {label, past, closed, types: type => {window, location, fees: [[zone, cost]]}}}.
	 */
	public static function catalog( array $events, array $zones, array $rules_by_event, array $locations_by_id, $default_location_id, $today, $formatter = null, $courier_window = null ) {
		$fmt     = is_callable( $formatter ) ? $formatter : array( 'Fe_Rate_Builder', 'format_date' );
		$windows = array();
		foreach ( $zones as $zone ) {
			if ( empty( $windows[ $zone['type'] ] ) ) {
				$windows[ $zone['type'] ] = (string) $zone['window'];
			}
		}

		$out = array();
		foreach ( $events as $event ) {
			$closed = isset( $event['closed_dates'] ) ? array_map( 'strval', (array) $event['closed_dates'] ) : array();
			$dates  = array();
			foreach ( Fe_Meta::TYPES as $type ) {
				// No rules and no "today": every configured date, whatever its zones.
				$specs = Fe_Rate_Builder::build( $event, $type, 0, array(), 0, isset( $windows[ $type ] ) ? $windows[ $type ] : '', $locations_by_id, $default_location_id, '', $fmt, $courier_window );
				foreach ( $specs as $spec ) {
					$date = $spec['date'];
					if ( ! isset( $dates[ $date ] ) ) {
						$dates[ $date ] = array(
							'label'  => $spec['when'],
							'past'   => $date < (string) $today,
							'closed' => in_array( $date, $closed, true ),
							'types'  => array(),
						);
					}
					$dates[ $date ]['types'][ $type ] = array(
						'window'   => (string) $spec['meta']['fe_window'],
						'location' => (string) $spec['meta']['fe_location'],
						'fees'     => array(),
					);
				}
			}
			if ( ! $dates ) {
				continue;
			}
			ksort( $dates );

			$rules = isset( $rules_by_event[ $event['id'] ] ) ? (array) $rules_by_event[ $event['id'] ] : array();
			foreach ( $zones as $zone ) {
				$type = $zone['type'];
				foreach ( Fe_Rate_Builder::build( $event, $type, (int) $zone['zone_id'], $rules, (float) $zone['cost'], '', $locations_by_id, $default_location_id, '', $fmt, $courier_window ) as $spec ) {
					$dates[ $spec['date'] ]['types'][ $type ]['fees'][] = array( (string) $zone['name'], (float) $spec['cost'] );
				}
			}
			$out[ (int) $event['id'] ] = array(
				'title' => (string) $event['title'],
				'draft' => 'publish' !== (string) $event['status'],
				'dates' => $dates,
			);
		}
		return $out;
	}

	/**
	 * Location text stored on orders ("Shop, 1 Main St") => its name.
	 *
	 * @param array[] $locations Location rows.
	 * @return array<string,string>
	 */
	public static function location_names( array $locations ) {
		$out = array();
		foreach ( $locations as $loc ) {
			$name = (string) $loc['name'];
			$out[ '' !== (string) $loc['address'] ? $name . ', ' . $loc['address'] : $name ] = $name;
		}
		return $out;
	}

	/**
	 * What a saved shipping line asks for.
	 *
	 * @param array      $posted    {event, date, type, window, location}; empty when the line posted no event fields.
	 * @param array|null $current   Fulfillment on the line, null when it has none.
	 * @param array      $catalog   From catalog().
	 * @param array      $locations From location_names().
	 * @return array {action: same|clear|set|invalid, fulfillment: ?array}
	 */
	public static function resolve( array $posted, $current, array $catalog, array $locations ) {
		$none = array(
			'action'      => 'same',
			'fulfillment' => null,
		);
		if ( ! isset( $posted['event'] ) ) {
			return $none;
		}
		$get      = function ( $key ) use ( $posted ) {
			return isset( $posted[ $key ] ) && is_scalar( $posted[ $key ] ) ? trim( (string) $posted[ $key ] ) : '';
		};
		$event_id = (int) $get( 'event' );
		if ( $event_id <= 0 ) {
			$none['action'] = $current ? 'clear' : 'same';
			return $none;
		}
		$date     = $get( 'date' );
		$type     = $get( 'type' );
		$window   = Fe_Event_Repo::clean_window( $get( 'window' ) );
		$location = '';
		if ( 'pickup' === $type ) {
			// Posting a value changes its spacing: match it to the stored text.
			$known = array_map( 'strval', array_keys( $locations ) );
			if ( $current ) {
				array_unshift( $known, (string) $current['location'] );
			}
			$location = $get( 'location' );
			foreach ( $known as $text ) {
				if ( self::squash( $text ) === self::squash( $location ) ) {
					$location = $text;
					break;
				}
			}
		}
		$stays = $current && (int) $current['event_id'] === $event_id && (string) $current['date'] === $date && (string) $current['type'] === $type;

		// The line's own values stay valid even when the event has since
		// changed or been deleted.
		if ( $stays && Fe_Event_Repo::clean_window( $current['window'] ) === $window && ( 'pickup' !== $type || (string) $current['location'] === $location ) ) {
			return $none;
		}
		$offer = isset( $catalog[ $event_id ]['dates'][ $date ]['types'][ $type ] ) ? $catalog[ $event_id ]['dates'][ $date ]['types'][ $type ] : null;
		if ( ! $offer && ! $stays ) {
			$none['action'] = 'invalid';
			return $none;
		}
		if ( 'pickup' === $type && ! isset( $locations[ $location ] ) && ! ( $stays && (string) $current['location'] === $location ) ) {
			$location = $offer ? (string) $offer['location'] : (string) $current['location'];
		}
		return array(
			'action'      => 'set',
			'fulfillment' => array(
				'event_id'   => $event_id,
				'event_name' => isset( $catalog[ $event_id ] ) ? (string) $catalog[ $event_id ]['title'] : (string) $current['event_name'],
				'date'       => $date,
				'type'       => $type,
				'window'     => $window,
				'location'   => $location,
				// An add-on travels with its target order; on another date it
				// no longer can.
				'addon_of'   => $stays ? $current['addon_of'] : null,
				'parent_id'  => null,
			),
		);
	}

	/**
	 * Text with runs of whitespace as one space.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function squash( $text ) {
		return trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	/**
	 * Shipping item meta for a fulfillment, as checkout writes it.
	 *
	 * @param array $f Fulfillment.
	 * @return array<string,string>
	 */
	public static function line_meta( array $f ) {
		$meta = array();
		foreach ( Fe_Order_Stamper::RATE_KEYS as $key ) {
			if ( isset( $f[ $key ] ) && '' !== (string) $f[ $key ] ) {
				$meta[ 'fe_' . $key ] = (string) $f[ $key ];
			}
		}
		return $meta;
	}

	/**
	 * Contract meta an order should carry for its shipping lines. Orders made
	 * by staff are never split: the first line with an event decides.
	 *
	 * @param array[] $lines     fe_* meta of each shipping line.
	 * @param int     $parent_id Split parent, 0 for none.
	 * @return array<string,string>
	 */
	public static function order_meta( array $lines, $parent_id ) {
		$parent_id = (int) $parent_id;
		foreach ( $lines as $meta ) {
			if ( empty( $meta['fe_date'] ) || empty( $meta['fe_type'] ) ) {
				continue;
			}
			$f              = Fe_Order_Stamper::fulfillment_from_rate_meta( $meta );
			$f['parent_id'] = $parent_id > 0 ? $parent_id : null;
			return Fe_Meta::to_meta( $f );
		}
		return $parent_id > 0 ? array( Fe_Meta::PARENT_ID => (string) $parent_id ) : array();
	}

	/**
	 * Shipping line title after its event changed: ours, unless staff typed
	 * one in the same save.
	 *
	 * @param string $before Title before the save.
	 * @param string $posted Title in the save.
	 * @param string $label  Title for the new event.
	 * @return string
	 */
	public static function title( $before, $posted, $label ) {
		$posted  = trim( (string) $posted );
		$default = array( '', 'Shipping', __( 'Shipping', 'fulfillment-events-for-woocommerce' ) );
		return in_array( $posted, $default, true ) || trim( (string) $before ) === $posted ? (string) $label : $posted;
	}

	/**
	 * Shipping label, as customers see it.
	 *
	 * @param string $type  delivery|pickup|courier.
	 * @param string $when  Formatted date.
	 * @param string $where Location name, '' for none.
	 * @param string $time  Time, '' for none.
	 * @return string
	 */
	public static function label( $type, $when, $where, $time ) {
		switch ( $type ) {
			case 'delivery':
				/* translators: %s: date, e.g. Thu, Dec 10 */
				$label = sprintf( __( 'Delivery · %s', 'fulfillment-events-for-woocommerce' ), $when );
				break;
			case 'pickup':
				if ( '' === (string) $where ) {
					/* translators: %s: date, e.g. Thu, Dec 10 */
					$label = sprintf( __( 'Pickup · %s', 'fulfillment-events-for-woocommerce' ), $when );
				} else {
					/* translators: 1: location name, 2: date, e.g. Thu, Dec 10 */
					$label = sprintf( __( 'Pickup at %1$s · %2$s', 'fulfillment-events-for-woocommerce' ), $where, $when );
				}
				break;
			default:
				/* translators: %s: date, e.g. Thu, Dec 10 */
				$label = sprintf( __( 'Courier pickup · %s', 'fulfillment-events-for-woocommerce' ), $when );
		}
		$time = trim( (string) $time );
		if ( '' !== $time ) {
			/* translators: 1: service and date, e.g. Delivery · Thu, Dec 10; 2: time, e.g. 9am–1pm */
			$label = sprintf( __( '%1$s · %2$s', 'fulfillment-events-for-woocommerce' ), $label, $time );
		}
		return $label;
	}
}
