<?php
/**
 * Per-event zone rule storage.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * Reads and writes rows of the fe_zone_rules table.
 */
class Fe_Zone_Rule_Repo {

	const MODES = array( 'inherit', 'on', 'off' );

	/**
	 * Name of the rules table.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'fe_zone_rules';
	}

	/**
	 * All rules of an event.
	 *
	 * @param int $event_id Event post id.
	 * @return array[] Rows of {zone_id, type, date, mode, cost}.
	 */
	public static function for_event( $event_id ) {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT zone_id, type, date, mode, cost FROM {$table} WHERE event_id = %d ORDER BY zone_id, type, date", (int) $event_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'zone_id' => (int) $row['zone_id'],
				'type'    => (string) $row['type'],
				'date'    => null === $row['date'] ? null : (string) $row['date'],
				'mode'    => (string) $row['mode'],
				'cost'    => null === $row['cost'] ? null : (float) $row['cost'],
			);
		}
		return $out;
	}

	/**
	 * Pure: cleans submitted rules. Drops rows with an unknown type or mode,
	 * a negative cost, an invalid zone or an invalid date; dedupes on
	 * (zone_id, type, date) with the last row winning. The database UNIQUE
	 * key cannot do this for rows whose date is NULL.
	 *
	 * @param array $rules Raw rules.
	 * @return array[] Rows of {zone_id, type, date, mode, cost}.
	 */
	public static function normalize_rules( array $rules ) {
		$keyed = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$zone = isset( $rule['zone_id'] ) ? (int) $rule['zone_id'] : 0;
			$type = isset( $rule['type'] ) ? (string) $rule['type'] : '';
			$mode = isset( $rule['mode'] ) ? (string) $rule['mode'] : '';
			if ( $zone < 0 || ! in_array( $type, Fe_Meta::TYPES, true ) || ! in_array( $mode, self::MODES, true ) ) {
				continue;
			}
			$date = isset( $rule['date'] ) ? trim( (string) $rule['date'] ) : '';
			if ( '' !== $date && ! Fe_Event_Repo::is_valid_date( $date ) ) {
				continue;
			}
			$cost = null;
			if ( isset( $rule['cost'] ) && '' !== $rule['cost'] ) {
				if ( ! is_numeric( $rule['cost'] ) || (float) $rule['cost'] < 0 ) {
					continue;
				}
				$cost = (float) $rule['cost'];
			}
			$key           = $zone . '|' . $type . '|' . $date;
			$keyed[ $key ] = array(
				'zone_id' => $zone,
				'type'    => $type,
				'date'    => '' === $date ? null : $date,
				'mode'    => $mode,
				'cost'    => $cost,
			);
		}
		return array_values( $keyed );
	}

	/**
	 * Replaces all of an event's rules. The only writer: deletes the event's
	 * rows, then inserts the normalized set.
	 *
	 * @param int   $event_id Event post id.
	 * @param array $rules    Raw rules.
	 */
	public static function replace_for_event( $event_id, array $rules ) {
		global $wpdb;
		$event_id = (int) $event_id;
		$table    = self::table();

		$wpdb->delete( $table, array( 'event_id' => $event_id ), array( '%d' ) );
		foreach ( self::normalize_rules( $rules ) as $rule ) {
			$wpdb->insert(
				$table,
				array(
					'event_id' => $event_id,
					'zone_id'  => $rule['zone_id'],
					'type'     => $rule['type'],
					'date'     => $rule['date'],
					'mode'     => $rule['mode'],
					'cost'     => $rule['cost'],
				),
				array( '%d', '%d', '%s', '%s', '%s', '%f' )
			);
		}
	}
}
