<?php
/**
 * Tags order items at checkout and stamps the fulfillment contract.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) ) {
	exit;
}

/**
 * Checkout hooks that write item meta, then order meta or the split marker.
 */
class Fe_Order_Stamper {

	/**
	 * Rate meta keys carried onto the shipping item, mapped to fulfillment keys.
	 *
	 * @var string[]
	 */
	const RATE_KEYS = array( 'event_id', 'event_name', 'date', 'type', 'window', 'location', 'addon_of' );

	/**
	 * Registers the checkout hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'tag_line_item' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order_shipping_item', array( __CLASS__, 'tag_shipping_item' ), 10, 3 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_classic_processed' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'finalize' ) );
	}

	/**
	 * Group label for a line item.
	 *
	 * @param bool     $needs_shipping Whether the product ships.
	 * @param int|null $event_id       Resolved event, if any.
	 * @return string 'event:{id}', 'regular' or 'none'.
	 */
	public static function line_group( $needs_shipping, $event_id ) {
		if ( ! $needs_shipping ) {
			return 'none';
		}
		return $event_id ? 'event:' . (int) $event_id : 'regular';
	}

	/**
	 * Tags a line item with its group.
	 *
	 * @param WC_Order_Item_Product $item          Order item.
	 * @param string                $cart_item_key Cart key.
	 * @param array                 $values        Cart item.
	 */
	public static function tag_line_item( $item, $cart_item_key, $values ) {
		$product_id = isset( $values['product_id'] ) ? (int) $values['product_id'] : 0;
		$product    = isset( $values['data'] ) ? $values['data'] : null;
		$ships      = $product && method_exists( $product, 'needs_shipping' ) ? (bool) $product->needs_shipping() : true;
		$event_id   = $ships ? Fe_Cart::product_event_status( $product_id )['event_id'] : null;
		$item->update_meta_data( Fe_Meta::GROUP, self::line_group( $ships, $event_id ) );
	}

	/**
	 * Tags a shipping item with its package group.
	 *
	 * @param WC_Order_Item_Shipping $item        Shipping item.
	 * @param string|int             $package_key Package key.
	 * @param array                  $package     Package.
	 */
	public static function tag_shipping_item( $item, $package_key, $package ) {
		if ( ! empty( $package['fe_group'] ) ) {
			$item->update_meta_data( Fe_Meta::GROUP, (string) $package['fe_group'] );
		}
	}

	/**
	 * Classic checkout adapter.
	 *
	 * @param int      $order_id Order id.
	 * @param array    $posted   Posted data.
	 * @param WC_Order $order    Order.
	 */
	public static function on_classic_processed( $order_id, $posted, $order ) {
		self::finalize( $order );
	}

	/**
	 * Maps rate meta (fe_<k>) to a fulfillment array.
	 *
	 * @param array $rate_meta Shipping item meta.
	 * @return array
	 */
	public static function fulfillment_from_rate_meta( array $rate_meta ) {
		$get = function ( $key ) use ( $rate_meta ) {
			return isset( $rate_meta[ 'fe_' . $key ] ) ? (string) $rate_meta[ 'fe_' . $key ] : '';
		};
		$addon = $get( 'addon_of' );
		return array(
			'event_id'   => (int) $get( 'event_id' ),
			'event_name' => $get( 'event_name' ),
			'date'       => $get( 'date' ),
			'type'       => $get( 'type' ),
			'window'     => $get( 'window' ),
			'location'   => $get( 'location' ),
			'addon_of'   => '' !== $addon ? (int) $addon : null,
			'parent_id'  => null,
		);
	}

	/**
	 * Decides what to do with an order from its shipping lines.
	 *
	 * @param array $shipping_lines List of {group, rate_meta}.
	 * @return array {action: none|stamp|split, fulfillment: ?array}
	 */
	public static function plan( array $shipping_lines ) {
		$events = array();
		foreach ( $shipping_lines as $line ) {
			$group = isset( $line['group'] ) ? (string) $line['group'] : '';
			$meta  = isset( $line['rate_meta'] ) ? (array) $line['rate_meta'] : array();
			if ( 0 === strpos( $group, 'event:' ) && ! empty( $meta['fe_date'] ) ) {
				$events[] = $meta;
			}
		}
		if ( empty( $events ) ) {
			return array(
				'action'      => 'none',
				'fulfillment' => null,
			);
		}
		if ( 1 === count( $shipping_lines ) ) {
			return array(
				'action'      => 'stamp',
				'fulfillment' => self::fulfillment_from_rate_meta( $events[0] ),
			);
		}
		return array(
			'action'      => 'split',
			'fulfillment' => null,
		);
	}

	/**
	 * Stamps or marks the order once checkout has created it.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function finalize( $order ) {
		if ( ! $order || $order->get_meta( Fe_Meta::CONTRACT ) || $order->get_meta( Fe_Meta::WILL_SPLIT ) ) {
			return;
		}
		$lines = array();
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$rate_meta = array();
			foreach ( self::RATE_KEYS as $key ) {
				$value = $item->get_meta( 'fe_' . $key );
				if ( '' !== $value && null !== $value ) {
					$rate_meta[ 'fe_' . $key ] = $value;
				}
			}
			$lines[] = array(
				'group'     => (string) $item->get_meta( Fe_Meta::GROUP ),
				'rate_meta' => $rate_meta,
			);
		}
		$plan = self::plan( $lines );
		if ( 'stamp' === $plan['action'] ) {
			foreach ( Fe_Meta::to_meta( $plan['fulfillment'] ) as $key => $value ) {
				$order->update_meta_data( $key, $value );
			}
			$order->save();
			do_action( 'fe_order_stamped', $order );
		} elseif ( 'split' === $plan['action'] ) {
			$order->update_meta_data( Fe_Meta::WILL_SPLIT, 'yes' );
			$order->save();
		}
	}
}
