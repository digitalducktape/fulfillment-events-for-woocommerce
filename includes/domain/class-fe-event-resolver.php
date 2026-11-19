<?php
/**
 * Resolves which event a product currently belongs to.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * Pure: no WordPress or WooCommerce calls.
 */
class Fe_Event_Resolver {

	/**
	 * Picks the open event whose next date (on or after today) is soonest.
	 *
	 * @param int[]   $product_event_ids Event ids linked to the product.
	 * @param array[] $open_events       Open events, each with a `dates` list.
	 * @param string  $today             Store-local Y-m-d.
	 * @return int|null Event id, or null when none qualifies.
	 */
	public static function resolve( array $product_event_ids, array $open_events, $today ) {
		$by_id = array();
		foreach ( $open_events as $event ) {
			$by_id[ (int) $event['id'] ] = $event;
		}

		$best_id   = null;
		$best_date = null;
		foreach ( array_unique( array_map( 'intval', $product_event_ids ) ) as $id ) {
			if ( ! isset( $by_id[ $id ] ) ) {
				continue;
			}
			$next = null;
			foreach ( (array) $by_id[ $id ]['dates'] as $row ) {
				$date = isset( $row['date'] ) ? (string) $row['date'] : '';
				if ( '' !== $date && $date >= $today && ( null === $next || $date < $next ) ) {
					$next = $date;
				}
			}
			if ( null === $next ) {
				continue;
			}
			if ( null === $best_date || $next < $best_date || ( $next === $best_date && $id < $best_id ) ) {
				$best_date = $next;
				$best_id   = $id;
			}
		}
		return $best_id;
	}

	/**
	 * Purchase status for a product.
	 *
	 * @param int[]    $product_event_ids Event ids linked to the product.
	 * @param int|null $resolved          Result of resolve().
	 * @return string 'none', 'active' or 'closed'.
	 */
	public static function status( array $product_event_ids, $resolved ) {
		if ( empty( $product_event_ids ) ) {
			return 'none';
		}
		return null === $resolved ? 'closed' : 'active';
	}
}
