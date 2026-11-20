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
		$ids = self::open_ids( $product_event_ids, $open_events, $today );
		return $ids ? $ids[0] : null;
	}

	/**
	 * Every open event of the product that still has a date on or after
	 * today, soonest next date first (ties by id). Ignores date limits.
	 *
	 * @param int[]   $product_event_ids Event ids linked to the product.
	 * @param array[] $open_events       Open events, each with a `dates` list.
	 * @param string  $today             Store-local Y-m-d.
	 * @return int[]
	 */
	public static function open_ids( array $product_event_ids, array $open_events, $today ) {
		return array_column( self::open_offers( $product_event_ids, $open_events, $today, 0 ), 'event_id' );
	}

	/**
	 * What a product can be bought for: each open event with a date on or
	 * after today, and the dates allowed for this product. `dates` is null
	 * when the event has no limit for the product (all its dates), else the
	 * limit's dates that are still ahead. Soonest next allowed date first,
	 * ties by id; an event with no allowed date left is left out.
	 *
	 * @param int[]   $product_event_ids Event ids linked to the product.
	 * @param array[] $open_events       Open events, each with `dates` and optional `date_limits`.
	 * @param string  $today             Store-local Y-m-d.
	 * @param int     $product_id        Product (parent) id whose limits apply; 0 for none.
	 * @return array[] List of {event_id:int, dates:?string[]}.
	 */
	public static function open_offers( array $product_event_ids, array $open_events, $today, $product_id ) {
		$by_id = array();
		foreach ( $open_events as $event ) {
			$by_id[ (int) $event['id'] ] = $event;
		}
		$product_id = (int) $product_id;
		$offers     = array();
		$next_of    = array();
		foreach ( array_unique( array_map( 'intval', $product_event_ids ) ) as $id ) {
			if ( ! isset( $by_id[ $id ] ) ) {
				continue;
			}
			$event  = $by_id[ $id ];
			$future = array();
			foreach ( (array) $event['dates'] as $row ) {
				$date = isset( $row['date'] ) ? (string) $row['date'] : '';
				if ( '' !== $date && $date >= $today ) {
					$future[] = $date;
				}
			}
			$limits  = isset( $event['date_limits'] ) && is_array( $event['date_limits'] ) ? $event['date_limits'] : array();
			$limited = $product_id > 0 && isset( $limits[ $product_id ] );
			$allowed = $limited ? array_values( array_intersect( $future, array_map( 'strval', (array) $limits[ $product_id ] ) ) ) : $future;
			if ( ! $allowed ) {
				continue;
			}
			sort( $allowed );
			$offers[ $id ]  = array(
				'event_id' => $id,
				'dates'    => $limited ? $allowed : null,
			);
			$next_of[ $id ] = $allowed[0];
		}
		$ids = array_keys( $offers );
		usort(
			$ids,
			function ( $a, $b ) use ( $next_of ) {
				$cmp = strcmp( $next_of[ $a ], $next_of[ $b ] );
				return 0 !== $cmp ? $cmp : $a - $b;
			}
		);
		$out = array();
		foreach ( $ids as $id ) {
			$out[] = $offers[ $id ];
		}
		return $out;
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
