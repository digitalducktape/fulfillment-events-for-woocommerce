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
	 * Groups shippable items. Events come in order of first appearance and the
	 * regular group (items without an event) comes last.
	 *
	 * @param array[] $items List of {key:string, needs_shipping:bool, event_id:?int}.
	 * @return array[] List of {group:string, event_id:?int, item_keys:string[]}.
	 */
	public static function group( array $items ) {
		$events  = array();
		$regular = array();

		foreach ( $items as $item ) {
			if ( empty( $item['needs_shipping'] ) ) {
				continue;
			}
			$key = (string) $item['key'];
			if ( isset( $item['event_id'] ) && null !== $item['event_id'] ) {
				$id = (int) $item['event_id'];
				if ( ! isset( $events[ $id ] ) ) {
					$events[ $id ] = array(
						'group'     => 'event:' . $id,
						'event_id'  => $id,
						'item_keys' => array(),
					);
				}
				$events[ $id ]['item_keys'][] = $key;
			} else {
				$regular[] = $key;
			}
		}

		$groups = array_values( $events );
		if ( ! empty( $regular ) ) {
			$groups[] = array(
				'group'     => 'regular',
				'event_id'  => null,
				'item_keys' => $regular,
			);
		}
		return $groups;
	}
}
