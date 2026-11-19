<?php
/**
 * Pure cart grouping: items -> one group per event, plus a regular group.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * Groups cart items by fulfillment event.
 */
class Fe_Cart_Grouper {

	/**
	 * Groups shippable items by the set of events they can ship in. Groups
	 * come in order of first appearance and the regular group (items without
	 * an event) comes last. An item in two open events gets one group offering
	 * both events' dates.
	 *
	 * @param array[] $items List of {key:string, needs_shipping:bool, event_ids?:int[], event_id?:?int}.
	 *                       event_ids are soonest first; event_id is the single-event form.
	 * @return array[] List of {group:string, event_id:?int (the first), event_ids:int[], item_keys:string[]}.
	 */
	public static function group( array $items ) {
		$events  = array();
		$regular = array();

		foreach ( $items as $item ) {
			if ( empty( $item['needs_shipping'] ) ) {
				continue;
			}
			$key = (string) $item['key'];
			$ids = self::ids_of( $item );
			if ( $ids ) {
				$group = 'event:' . implode( ',', $ids );
				if ( ! isset( $events[ $group ] ) ) {
					$events[ $group ] = array(
						'group'     => $group,
						'event_id'  => $ids[0],
						'event_ids' => $ids,
						'item_keys' => array(),
					);
				}
				$events[ $group ]['item_keys'][] = $key;
			} else {
				$regular[] = $key;
			}
		}

		$groups = array_values( $events );
		if ( ! empty( $regular ) ) {
			$groups[] = array(
				'group'     => 'regular',
				'event_id'  => null,
				'event_ids' => array(),
				'item_keys' => $regular,
			);
		}
		return $groups;
	}

	/**
	 * Event ids of an item, in the given order, without duplicates.
	 *
	 * @param array $item Item.
	 * @return int[]
	 */
	private static function ids_of( array $item ) {
		if ( isset( $item['event_ids'] ) && is_array( $item['event_ids'] ) ) {
			$ids = $item['event_ids'];
		} elseif ( isset( $item['event_id'] ) && null !== $item['event_id'] ) {
			$ids = array( $item['event_id'] );
		} else {
			$ids = array();
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		return $ids;
	}
}
