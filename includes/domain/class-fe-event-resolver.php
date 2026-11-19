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
	 * Events a product belongs to once its exclusions are applied. An
	 * exclusion wins over every link, including the product's own.
	 *
	 * @param int[] $linked   Event ids from the product and its categories.
	 * @param int[] $excluded Event ids the product is excluded from.
	 * @return int[]
	 */
	public static function effective_ids( array $linked, array $excluded ) {
		$linked   = array_unique( array_map( 'intval', $linked ) );
		$excluded = array_map( 'intval', $excluded );
		return array_values( array_diff( $linked, $excluded ) );
	}

	/**
	 * Applies a bulk-edit action to a product's own links and exclusions.
	 *
	 * - add:     link the event, and drop any exclusion of it.
	 * - exclude: exclude the event, and drop any direct link to it.
	 * - remove:  drop both the direct link and the exclusion, so the product
	 *            follows its categories again.
	 *
	 * @param int[]  $own      The product's own event links.
	 * @param int[]  $excluded The product's exclusions.
	 * @param string $action   add|exclude|remove.
	 * @param int    $event_id Event id.
	 * @return array{0:int[],1:int[]} New links and exclusions.
	 */
	public static function apply_bulk( array $own, array $excluded, $action, $event_id ) {
		$event_id = (int) $event_id;
		$own      = array_values( array_diff( array_unique( array_map( 'intval', $own ) ), array( $event_id ) ) );
		$excluded = array_values( array_diff( array_unique( array_map( 'intval', $excluded ) ), array( $event_id ) ) );
		if ( $event_id <= 0 ) {
			return array( $own, $excluded );
		}
		if ( 'add' === $action ) {
			$own[] = $event_id;
		} elseif ( 'exclude' === $action ) {
			$excluded[] = $event_id;
		}
		return array( $own, $excluded );
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
