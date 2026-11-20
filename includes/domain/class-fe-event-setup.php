<?php
/**
 * Pure conversions between the Event setup screen and stored data.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * The screen shows, per date, which zones get delivery and courier. Those
 * ticks are stored as zone rules ("off" for a zone, type and date), next to
 * the rarely used price rules. No WordPress calls here.
 */
class Fe_Event_Setup {

	/**
	 * Types whose zones are ticked per date on the screen.
	 */
	const ZONE_TYPES = array( 'delivery', 'courier' );

	/**
	 * Ticks as the stored rules imply them: a zone runs on a date unless the
	 * rules turn it off there (a date rule first, then an event-wide one).
	 *
	 * @param array    $rules         Zone rules of the event.
	 * @param string[] $dates         Event dates.
	 * @param array    $zones_by_type type => zone ids shown on the screen.
	 * @return array [date][type][zone_id] => bool.
	 */
	public static function ticks_from_rules( array $rules, array $dates, array $zones_by_type ) {
		$ticks = array();
		foreach ( $dates as $date ) {
			foreach ( self::ZONE_TYPES as $type ) {
				$ticks[ $date ][ $type ] = array();
				foreach ( self::zone_ids( $zones_by_type, $type ) as $zone ) {
					$ticks[ $date ][ $type ][ $zone ] = Fe_Zone_Rules::resolve( $rules, $zone, $type, $date, 0 )['enabled'];
				}
			}
		}
		return $ticks;
	}

	/**
	 * Ticks from posted date rows. A row with `zones_posted` lists the zones
	 * that are ticked, so a type with nothing ticked is off everywhere; a row
	 * without it (its checkboxes never rendered) runs in every zone.
	 *
	 * @param array $date_rows     Posted fe_dates rows.
	 * @param array $zones_by_type type => zone ids shown on the screen.
	 * @return array [date][type][zone_id] => bool.
	 */
	public static function ticks_from_post( array $date_rows, array $zones_by_type ) {
		$ticks = array();
		foreach ( $date_rows as $row ) {
			$date = is_array( $row ) && isset( $row['date'] ) ? trim( (string) $row['date'] ) : '';
			if ( '' === $date ) {
				continue;
			}
			$posted = ! empty( $row['zones_posted'] );
			foreach ( self::ZONE_TYPES as $type ) {
				$on    = $posted && isset( $row['zones'][ $type ] ) ? array_map( 'intval', (array) $row['zones'][ $type ] ) : array();
				$zones = self::zone_ids( $zones_by_type, $type );
				// Only zones the card showed can be unticked; one added to the
				// shipping zone while the screen was open keeps its rules.
				if ( $posted && isset( $row['zones_shown'][ $type ] ) ) {
					$zones = array_values( array_intersect( $zones, array_map( 'intval', (array) $row['zones_shown'][ $type ] ) ) );
				}
				$ticks[ $date ][ $type ] = array();
				foreach ( $zones as $zone ) {
					$ticks[ $date ][ $type ][ $zone ] = ! $posted || in_array( $zone, $on, true );
				}
			}
		}
		return $ticks;
	}

	/**
	 * Rules to store after a save. Rules the screen doesn't show (other
	 * types, zones without the method, dates no longer in the event, legacy
	 * "inherit" rows) are kept as they were. Every "on" rule comes from the
	 * price list. Each unticked zone becomes an "off" rule for its date; a
	 * price for the same zone, type and date is dropped and counted.
	 *
	 * @param array    $existing      Rules stored before the save.
	 * @param array    $ticks         From ticks_from_post().
	 * @param array    $prices        From prices_from_post().
	 * @param string[]      $dates         The event's dates after the save.
	 * @param array         $zones_by_type type => zone ids shown on the screen.
	 * @param string[]|null $keep_legacy   Keys (rule_key()) of the legacy_rules() still listed on
	 *                                     the screen; others were removed there. Null keeps them all.
	 * @return array{rules: array[], dropped: int}
	 */
	public static function rules_from_setup( array $existing, array $ticks, array $prices, array $dates, array $zones_by_type, $keep_legacy = null ) {
		$kept = array();
		foreach ( $existing as $rule ) {
			$mode = isset( $rule['mode'] ) ? (string) $rule['mode'] : '';
			if ( 'on' === $mode ) {
				continue;
			}
			if ( 'off' === $mode && self::in_scope( $rule, $dates, $zones_by_type ) ) {
				continue;
			}
			if ( 'off' === $mode && is_array( $keep_legacy ) && ! in_array( self::rule_key( $rule ), $keep_legacy, true ) ) {
				continue;
			}
			$kept[] = $rule;
		}

		$offs = array();
		foreach ( $ticks as $date => $types ) {
			if ( ! in_array( (string) $date, $dates, true ) ) {
				continue;
			}
			foreach ( $types as $type => $zones ) {
				foreach ( $zones as $zone => $on ) {
					if ( ! $on ) {
						$offs[ (int) $zone . '|' . $type . '|' . $date ] = array(
							'zone_id' => (int) $zone,
							'type'    => (string) $type,
							'date'    => (string) $date,
							'mode'    => 'off',
							'cost'    => null,
						);
					}
				}
			}
		}

		$dropped = 0;
		$priced  = array();
		foreach ( $prices as $price ) {
			$date = isset( $price['date'] ) && null !== $price['date'] ? (string) $price['date'] : '';
			if ( '' !== $date && isset( $offs[ (int) $price['zone_id'] . '|' . $price['type'] . '|' . $date ] ) ) {
				++$dropped;
				continue;
			}
			$priced[] = $price;
		}

		return array(
			'rules'   => Fe_Zone_Rule_Repo::normalize_rules( array_merge( $kept, $priced, array_values( $offs ) ) ),
			'dropped' => $dropped,
		);
	}

	/**
	 * "Off" rules the screen can't show as ticks (pickup turned off for a
	 * zone, zones without the Event method, dates no longer in the event),
	 * listed so they can be seen and removed.
	 *
	 * @param array    $existing      Stored rules.
	 * @param string[] $dates         Event dates.
	 * @param array    $zones_by_type type => zone ids shown on the screen.
	 * @return array[]
	 */
	public static function legacy_rules( array $existing, array $dates, array $zones_by_type ) {
		$out = array();
		foreach ( $existing as $rule ) {
			if ( isset( $rule['mode'] ) && 'off' === $rule['mode'] && ! self::in_scope( $rule, $dates, $zones_by_type ) ) {
				$out[] = $rule;
			}
		}
		return $out;
	}

	/**
	 * A rule's identity, "zone|type|date" ('' for all dates).
	 *
	 * @param array $rule Rule.
	 * @return string
	 */
	public static function rule_key( array $rule ) {
		$date = isset( $rule['date'] ) && null !== $rule['date'] ? (string) $rule['date'] : '';
		return (int) $rule['zone_id'] . '|' . (string) $rule['type'] . '|' . $date;
	}

	/**
	 * "On" rules from posted price rows. Rows without a price, with a
	 * negative one, or with an unknown type are skipped.
	 *
	 * @param array $rows Posted fe_prices rows {zone_id, type, date ('' = all dates), cost}.
	 * @return array[]
	 */
	public static function prices_from_post( array $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$cost = isset( $row['cost'] ) ? trim( (string) $row['cost'] ) : '';
			$type = isset( $row['type'] ) ? (string) $row['type'] : '';
			if ( '' === $cost || ! is_numeric( $cost ) || (float) $cost < 0 || ! in_array( $type, array( 'delivery', 'pickup', 'courier' ), true ) ) {
				continue;
			}
			$out[] = array(
				'zone_id' => isset( $row['zone_id'] ) ? (int) $row['zone_id'] : 0,
				'type'    => $type,
				'date'    => isset( $row['date'] ) ? trim( (string) $row['date'] ) : '',
				'mode'    => 'on',
				'cost'    => (float) $cost,
			);
		}
		return $out;
	}

	/**
	 * Product exceptions from posted rows: "exclude" rows become exclusions,
	 * "only" rows become date limits cleaned against the event's dates.
	 *
	 * @param array    $rows        Posted fe_exceptions rows {product_id, mode, dates[]}.
	 * @param string[] $event_dates The event's dates after the save.
	 * @return array{excluded: int[], limits: array<int,string[]>, emptied: int[]}
	 */
	public static function exceptions_from_post( array $rows, array $event_dates ) {
		$excluded = array();
		$raw      = array();
		foreach ( $rows as $row ) {
			$id = is_array( $row ) && isset( $row['product_id'] ) ? (int) $row['product_id'] : 0;
			if ( $id <= 0 ) {
				continue;
			}
			if ( isset( $row['mode'] ) && 'exclude' === $row['mode'] ) {
				$excluded[ $id ] = $id;
				unset( $raw[ $id ] );
			} else {
				$raw[ $id ] = isset( $row['dates'] ) ? (array) $row['dates'] : array();
				unset( $excluded[ $id ] );
			}
		}
		$clean = Fe_Event_Repo::clean_limits( $raw, $event_dates );
		return array(
			'excluded' => array_values( $excluded ),
			'limits'   => $clean['limits'],
			'emptied'  => $clean['emptied'],
		);
	}

	/**
	 * The event's individual products after a save: the listed ones, plus
	 * each "only on" product that isn't already in the event by list or by
	 * one of the event's categories.
	 *
	 * @param int[] $only_ids              Products with an "only on" exception.
	 * @param int[] $product_ids           Products listed on the screen.
	 * @param array $categories_by_product Product id => its category ids.
	 * @param int[] $category_ids          The event's categories.
	 * @return int[]
	 */
	public static function products_to_link( array $only_ids, array $product_ids, array $categories_by_product, array $category_ids ) {
		$out = array_values( array_map( 'intval', $product_ids ) );
		foreach ( $only_ids as $id ) {
			$id = (int) $id;
			if ( in_array( $id, $out, true ) ) {
				continue;
			}
			$cats = isset( $categories_by_product[ $id ] ) ? array_map( 'intval', (array) $categories_by_product[ $id ] ) : array();
			if ( ! array_intersect( $cats, array_map( 'intval', $category_ids ) ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Whether a rule is one the screen's ticks own.
	 *
	 * @param array    $rule          Rule.
	 * @param string[] $dates         Event dates.
	 * @param array    $zones_by_type type => zone ids shown.
	 * @return bool
	 */
	private static function in_scope( array $rule, array $dates, array $zones_by_type ) {
		$type = isset( $rule['type'] ) ? (string) $rule['type'] : '';
		if ( ! in_array( $type, self::ZONE_TYPES, true ) || ! in_array( (int) $rule['zone_id'], self::zone_ids( $zones_by_type, $type ), true ) ) {
			return false;
		}
		$date = isset( $rule['date'] ) && null !== $rule['date'] ? (string) $rule['date'] : '';
		return '' === $date || in_array( $date, $dates, true );
	}

	/**
	 * Zone ids for a type.
	 *
	 * @param array  $zones_by_type type => zone ids.
	 * @param string $type          Type.
	 * @return int[]
	 */
	private static function zone_ids( array $zones_by_type, $type ) {
		return isset( $zones_by_type[ $type ] ) ? array_values( array_map( 'intval', (array) $zones_by_type[ $type ] ) ) : array();
	}
}
