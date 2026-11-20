<?php
/**
 * Order-received page for a split order.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * After checkout, a split order lands on its parent's order-received page,
 * which only knows the parent's number. This lists each order it was split
 * into: number, items, and how and when it arrives. It runs on
 * woocommerce_thankyou, which the classic page and the block order
 * confirmation's "Additional information" both fire.
 */
class Fe_Order_Received {

	/**
	 * Hooks.
	 */
	public static function init() {
		// Before WooCommerce's own order details (priority 10).
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'render' ), 5 );
	}

	/**
	 * Prints the split orders, if the order was split.
	 *
	 * @param int $order_id Order id.
	 */
	public static function render( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$children = self::children( $order );
		if ( ! $children ) {
			return;
		}
		wc_get_template(
			'order-received/split-orders.php',
			array(
				'order' => $order,
				'rows'  => self::rows( $children ),
			),
			'',
			FE_PLUGIN_DIR . 'templates/'
		);
	}

	/**
	 * The orders a parent was split into, in split order.
	 *
	 * @param WC_Order $parent Parent order.
	 * @return WC_Order[]
	 */
	public static function children( $parent ) {
		$out = array();
		$ids = array_filter( array_map( 'intval', explode( ',', (string) $parent->get_meta( Fe_Meta::CHILDREN ) ) ) );
		foreach ( $ids as $id ) {
			$child = wc_get_order( $id );
			if ( $child ) {
				$out[] = $child;
			}
		}
		return $out;
	}

	/**
	 * What the page shows for each split order.
	 *
	 * @param WC_Order[] $children Split orders.
	 * @return array[] List of {number, event, shipping (method name, '' when none), shipping_cost, location (pickup only), items: [{name, qty}]}.
	 */
	public static function rows( array $children ) {
		$rows = array();
		foreach ( $children as $child ) {
			$meta = array();
			foreach ( Fe_Meta::contract_keys() as $key ) {
				$meta[ $key ] = $child->get_meta( $key );
			}
			$f        = Fe_Meta::from_meta( $meta );
			$shipping = '';
			$cost     = 0.0;
			foreach ( $child->get_items( 'shipping' ) as $item ) {
				$shipping = (string) $item->get_name();
				$cost     = (float) $item->get_total();
				break;
			}
			$items = array();
			foreach ( $child->get_items() as $item ) {
				$items[] = array(
					'name' => (string) $item->get_name(),
					'qty'  => (int) $item->get_quantity(),
				);
			}
			$rows[] = array(
				'number'        => (string) $child->get_order_number(),
				'event'         => $f ? (string) $f['event_name'] : '',
				'shipping'      => $shipping,
				'shipping_cost' => $cost,
				'location'      => $f && 'pickup' === $f['type'] ? (string) $f['location'] : '',
				'items'         => $items,
			);
		}
		return $rows;
	}
}
