<?php
/**
 * Add-on eligibility: which existing orders a new order may be added to.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * Pure rules for adding a new order on to an existing one.
 */
class Fe_Addon_Rules {

	/**
	 * Order statuses that can still take an add-on.
	 *
	 * @var string[]
	 */
	const OPEN_STATUSES = array( 'processing', 'on-hold' );

	/**
	 * Status of a split parent (without the wc- prefix).
	 *
	 * @var string
	 */
	const SPLIT_STATUS = 'fe-split';

	/**
	 * Whether a target order can take an add-on for this event and customer.
	 *
	 * @param array  $target        {event_id, status, is_addon:bool, customer_id, billing_email, date?}.
	 * @param int    $event_id      Event of the package being added.
	 * @param string $billing_email Billing email of the new order ('' when unknown).
	 * @param int    $customer_id   Logged-in customer id (0 for guests).
	 * @param string $today         Store-local Y-m-d; when set, targets dated before it are not eligible.
	 * @param array|null $allowed_dates Dates the items being added may ship on; null for any.
	 *                                  A product limited to Dec 18 can't join a Dec 10 order.
	 * @return bool
	 */
	public static function eligible( array $target, $event_id, $billing_email, $customer_id, $today = '', $allowed_dates = null ) {
		$event_id = (int) $event_id;
		if ( $event_id <= 0 || (int) ( isset( $target['event_id'] ) ? $target['event_id'] : 0 ) !== $event_id ) {
			return false;
		}
		$status = isset( $target['status'] ) ? (string) $target['status'] : '';
		if ( ! in_array( $status, self::OPEN_STATUSES, true ) ) {
			return false;
		}
		if ( ! empty( $target['is_addon'] ) ) {
			return false;
		}
		// The date stopped taking orders, add-ons included.
		if ( ! empty( $target['closed'] ) ) {
			return false;
		}
		$date = isset( $target['date'] ) ? (string) $target['date'] : '';
		if ( '' !== (string) $today && ( '' === $date || $date < (string) $today ) ) {
			return false;
		}
		if ( is_array( $allowed_dates ) && ! in_array( $date, array_map( 'strval', $allowed_dates ), true ) ) {
			return false;
		}
		$customer_id = (int) $customer_id;
		if ( $customer_id > 0 && (int) ( isset( $target['customer_id'] ) ? $target['customer_id'] : 0 ) === $customer_id ) {
			return true;
		}
		$mine   = strtolower( trim( (string) $billing_email ) );
		$theirs = strtolower( trim( isset( $target['billing_email'] ) ? (string) $target['billing_email'] : '' ) );
		return '' !== $mine && $mine === $theirs;
	}

	/**
	 * Order id from what a customer typed ("#345", "345").
	 *
	 * @param string $input Raw input.
	 * @return int 0 when it is not a number.
	 */
	public static function order_id_from_input( $input ) {
		$n = trim( ltrim( trim( (string) $input ), '#' ) );
		if ( '' === $n || ! ctype_digit( $n ) ) {
			return 0;
		}
		return (int) $n;
	}

	/**
	 * Resolves a guest's typed order number to an eligible target. A split
	 * parent (the number in the split confirmation email) resolves to its
	 * child for the package's event.
	 *
	 * @param string          $input         Typed order number.
	 * @param int|int[]|array $event_id      Package event, its event ids, or its offers
	 *                                       (Fe_Cart_Grouper::to_offers); the target must fit one.
	 * @param string   $billing_email New order's billing email.
	 * @param callable $lookup        int order id => ?array target (see eligible()); a split
	 *                                parent is {status: SPLIT_STATUS, children: int[]}.
	 * @param string   $today         Store-local Y-m-d, see eligible().
	 * @return int Target order id, or 0.
	 */
	public static function resolve_guest( $input, $event_id, $billing_email, $lookup, $today = '' ) {
		$id = self::order_id_from_input( $input );
		if ( $id <= 0 ) {
			return 0;
		}
		$target = call_user_func( $lookup, $id );
		if ( ! is_array( $target ) ) {
			return 0;
		}
		$offers = Fe_Cart_Grouper::to_offers( $event_id );
		if ( isset( $target['status'] ) && self::SPLIT_STATUS === $target['status'] ) {
			$children = isset( $target['children'] ) ? (array) $target['children'] : array();
			foreach ( $offers as $offer ) {
				foreach ( $children as $child_id ) {
					$child_id = (int) $child_id;
					$child    = $child_id > 0 ? call_user_func( $lookup, $child_id ) : null;
					if ( is_array( $child ) && self::eligible( $child, $offer['event_id'], $billing_email, 0, $today, $offer['dates'] ) ) {
						return $child_id;
					}
				}
			}
			return 0;
		}
		foreach ( $offers as $offer ) {
			if ( self::eligible( $target, $offer['event_id'], $billing_email, 0, $today, $offer['dates'] ) ) {
				return $id;
			}
		}
		return 0;
	}

	/**
	 * What a guest add-on line may target: the JSON fe_offers of 0.5.0
	 * packages (events and allowed dates), else the event ids.
	 *
	 * @param array $meta Rate or item meta.
	 * @return array[] Offers list.
	 */
	public static function meta_offers( array $meta ) {
		if ( isset( $meta['fe_offers'] ) && '' !== (string) $meta['fe_offers'] ) {
			$decoded = json_decode( (string) $meta['fe_offers'], true );
			if ( is_array( $decoded ) ) {
				$offers = Fe_Cart_Grouper::to_offers( $decoded );
				if ( $offers ) {
					return $offers;
				}
			}
		}
		return Fe_Cart_Grouper::to_offers( self::meta_event_ids( $meta ) );
	}

	/**
	 * Event ids from guest add-on meta (comma-separated fe_event_ids, else fe_event_id).
	 *
	 * @param array $meta Rate or item meta.
	 * @return int[]
	 */
	public static function meta_event_ids( array $meta ) {
		$ids = isset( $meta['fe_event_ids'] ) ? array_filter( array_map( 'intval', explode( ',', (string) $meta['fe_event_ids'] ) ) ) : array();
		if ( ! $ids && ! empty( $meta['fe_event_id'] ) ) {
			$ids = array( (int) $meta['fe_event_id'] );
		}
		return array_values( $ids );
	}

	/**
	 * Shipping rate / item meta for an add-on to a target order.
	 *
	 * @param array $fulfillment Target's fulfillment (fe_get_order_fulfillment shape).
	 * @param int   $target_id   Target order id.
	 * @return array fe_* keys.
	 */
	public static function rate_meta( array $fulfillment, $target_id ) {
		$get = function ( $key ) use ( $fulfillment ) {
			return isset( $fulfillment[ $key ] ) ? (string) $fulfillment[ $key ] : '';
		};
		return array(
			'fe_event_id'   => (int) $get( 'event_id' ),
			'fe_event_name' => $get( 'event_name' ),
			'fe_date'       => $get( 'date' ),
			'fe_type'       => $get( 'type' ),
			'fe_window'     => $get( 'window' ),
			'fe_location'   => $get( 'location' ),
			'fe_addon_of'   => (int) $target_id,
		);
	}

	/**
	 * Place-order check for chosen guest add-on rates.
	 *
	 * @param array    $rate_metas    Meta of each chosen shipping rate (fe_* keys).
	 * @param string   $input         Typed order number.
	 * @param string   $billing_email Billing email.
	 * @param callable $lookup        See resolve_guest().
	 * @param string   $today         Store-local Y-m-d, see eligible().
	 * @return array|null Null when fine, else {number, event_name} for the message.
	 */
	public static function guest_error( array $rate_metas, $input, $billing_email, $lookup, $today = '' ) {
		foreach ( $rate_metas as $meta ) {
			if ( ! is_array( $meta ) || ! isset( $meta['fe_addon_guest'] ) || 'yes' !== $meta['fe_addon_guest'] ) {
				continue;
			}
			if ( 0 === self::resolve_guest( $input, self::meta_offers( $meta ), $billing_email, $lookup, $today ) ) {
				return array(
					'number'     => trim( ltrim( trim( (string) $input ), '#' ) ),
					'event_name' => isset( $meta['fe_event_name'] ) ? (string) $meta['fe_event_name'] : '',
				);
			}
		}
		return null;
	}
}
