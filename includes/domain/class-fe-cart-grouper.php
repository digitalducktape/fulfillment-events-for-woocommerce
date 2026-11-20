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
	 * Groups shippable items by what they can ship in: the same events and,
	 * for date-limited products, the same allowed dates. Groups come in order
	 * of first appearance and the regular group (items without an event)
	 * comes last. A product limited to Dec 18 gets its own group, so checkout
	 * gives it its own shipping choice.
	 *
	 * @param array[] $items List of {key:string, needs_shipping:bool} plus one of
	 *                       offers (list of {event_id, dates:?string[]}), event_ids (int[]) or event_id (?int).
	 * @return array[] List of {group:string, event_id:?int (the first), event_ids:int[], offers:array[], item_keys:string[]}.
	 */
	public static function group( array $items ) {
		$events  = array();
		$regular = array();

		foreach ( $items as $item ) {
			if ( empty( $item['needs_shipping'] ) ) {
				continue;
			}
			$key = (string) $item['key'];
			if ( isset( $item['offers'] ) ) {
				$offers = self::to_offers( $item['offers'] );
			} elseif ( isset( $item['event_ids'] ) ) {
				$offers = self::to_offers( $item['event_ids'] );
			} else {
				$offers = self::to_offers( isset( $item['event_id'] ) ? $item['event_id'] : null );
			}
			if ( $offers ) {
				$group = self::key( $offers );
				if ( ! isset( $events[ $group ] ) ) {
					$ids              = array_column( $offers, 'event_id' );
					$events[ $group ] = array(
						'group'     => $group,
						'event_id'  => $ids[0],
						'event_ids' => $ids,
						'offers'    => $offers,
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
				'offers'    => array(),
				'item_keys' => $regular,
			);
		}
		return $groups;
	}

	/**
	 * Normalises what a product resolves to into an offers list: accepts an
	 * offers list, a list of event ids, one event id, or null.
	 *
	 * @param mixed $value Offers, ids, id or null.
	 * @return array[] List of {event_id:int, dates:?string[]}, without duplicate events.
	 */
	public static function to_offers( $value ) {
		$out  = array();
		$seen = array();
		foreach ( null === $value ? array() : (array) $value as $entry ) {
			if ( is_array( $entry ) ) {
				$id    = isset( $entry['event_id'] ) ? (int) $entry['event_id'] : 0;
				$dates = isset( $entry['dates'] ) && is_array( $entry['dates'] ) ? array_values( array_map( 'strval', $entry['dates'] ) ) : null;
			} else {
				$id    = (int) $entry;
				$dates = null;
			}
			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$out[]       = array(
				'event_id' => $id,
				'dates'    => $dates,
			);
		}
		return $out;
	}

	/**
	 * Group key shared by packages and order line items: "event:" then each
	 * offer as "{id}", or "{id}@{date}+{date}" when limited; '' for none.
	 *
	 * @param array[] $offers Offers list.
	 * @return string
	 */
	public static function key( array $offers ) {
		$parts = array();
		foreach ( $offers as $offer ) {
			$part = (string) (int) $offer['event_id'];
			if ( isset( $offer['dates'] ) && is_array( $offer['dates'] ) ) {
				$part .= '@' . implode( '+', $offer['dates'] );
			}
			$parts[] = $part;
		}
		return $parts ? 'event:' . implode( ',', $parts ) : '';
	}
}
