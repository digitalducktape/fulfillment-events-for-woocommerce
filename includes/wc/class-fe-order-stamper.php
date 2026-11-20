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
	 * Per-request memo for cart_contains_event().
	 *
	 * @var bool|null
	 */
	private static $cart_has_event = null;

	/**
	 * Registers the checkout hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'tag_line_item' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order_shipping_item', array( __CLASS__, 'tag_shipping_item' ), 10, 3 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_classic_processed' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'finalize' ) );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( __CLASS__, 'strip_fe_meta' ) );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_item_meta' ) );
	}

	/**
	 * Shipping item meta keys the plugin writes (rate meta).
	 *
	 * @return string[]
	 */
	public static function item_meta_keys() {
		$keys = array( 'fe_addon_guest', 'fe_event_ids', 'fe_offers' );
		foreach ( self::RATE_KEYS as $key ) {
			$keys[] = 'fe_' . $key;
		}
		return $keys;
	}

	/**
	 * Drops this plugin's entries from formatted item meta (emails, My
	 * Account, thank-you page, order admin). Other plugins' fe_* keys stay.
	 *
	 * @param array $formatted Formatted meta, id => {key, value, display_key, display_value}.
	 * @return array
	 */
	public static function strip_fe_meta( $formatted ) {
		if ( ! is_array( $formatted ) ) {
			return $formatted;
		}
		$ours = self::item_meta_keys();
		foreach ( $formatted as $id => $meta ) {
			$key = is_object( $meta ) && isset( $meta->key ) ? (string) $meta->key : ( is_array( $meta ) && isset( $meta['key'] ) ? (string) $meta['key'] : '' );
			if ( in_array( $key, $ours, true ) ) {
				unset( $formatted[ $id ] );
			}
		}
		return $formatted;
	}

	/**
	 * Hides the fe_* item meta in the order admin.
	 *
	 * @param string[] $keys Hidden keys.
	 * @return string[]
	 */
	public static function hidden_item_meta( $keys ) {
		return array_values( array_unique( array_merge( (array) $keys, self::item_meta_keys() ) ) );
	}

	/**
	 * Group label for a line item.
	 *
	 * Matches Fe_Cart_Grouper's package group, so a product in two open
	 * events is tagged 'event:{a},{b}' like its package.
	 *
	 * @param bool                 $needs_shipping Whether the product ships.
	 * @param array|int|int[]|null $offers         Offers (see Fe_Cart_Grouper::to_offers), ids or one id.
	 * @return string Fe_Cart_Grouper::key(), 'regular' or 'none'.
	 */
	public static function line_group( $needs_shipping, $offers ) {
		if ( ! $needs_shipping ) {
			return 'none';
		}
		$key = Fe_Cart_Grouper::key( Fe_Cart_Grouper::to_offers( $offers ) );
		return '' === $key ? 'regular' : $key;
	}

	/**
	 * Whether any resolved event id is present.
	 *
	 * @param array $event_ids List of ?int event ids.
	 * @return bool
	 */
	public static function cart_has_event( array $event_ids ) {
		foreach ( $event_ids as $id ) {
			if ( null !== $id && '' !== $id && false !== $id && 0 !== $id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the current cart has an event product (memoised per request).
	 *
	 * @return bool
	 */
	private static function cart_contains_event() {
		if ( null === self::$cart_has_event ) {
			$ids = array();
			if ( function_exists( 'WC' ) && WC() && WC()->cart ) {
				foreach ( WC()->cart->get_cart() as $line ) {
					$product = isset( $line['data'] ) ? $line['data'] : null;
					if ( $product && method_exists( $product, 'needs_shipping' ) && ! $product->needs_shipping() ) {
						continue;
					}
					$ids[] = Fe_Cart::product_event_status( (int) $line['product_id'] )['event_id'];
				}
			}
			self::$cart_has_event = self::cart_has_event( $ids );
		}
		return self::$cart_has_event;
	}

	/**
	 * Tags a line item with its group.
	 *
	 * @param WC_Order_Item_Product $item          Order item.
	 * @param string                $cart_item_key Cart key.
	 * @param array                 $values        Cart item.
	 */
	public static function tag_line_item( $item, $cart_item_key, $values ) {
		if ( ! self::cart_contains_event() ) {
			return;
		}
		$product_id = isset( $values['product_id'] ) ? (int) $values['product_id'] : 0;
		$product    = isset( $values['data'] ) ? $values['data'] : null;
		$ships      = $product && method_exists( $product, 'needs_shipping' ) ? (bool) $product->needs_shipping() : true;
		$offers     = $ships ? Fe_Cart::product_event_status( $product_id )['offers'] : array();
		$item->update_meta_data( Fe_Meta::GROUP, self::line_group( $ships, $offers ) );
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
		$get   = function ( $key ) use ( $rate_meta ) {
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
	 * Diffs current order meta against the desired managed meta.
	 *
	 * @param array    $current      Current meta, key => value.
	 * @param array    $desired      Desired meta, key => value.
	 * @param string[] $managed_keys Keys this class owns.
	 * @return array {set: array, delete: string[]}
	 */
	public static function meta_changes( array $current, array $desired, array $managed_keys ) {
		$set    = array();
		$delete = array();
		foreach ( $desired as $key => $value ) {
			if ( ! array_key_exists( $key, $current ) || (string) $current[ $key ] !== (string) $value ) {
				$set[ $key ] = $value;
			}
		}
		foreach ( $managed_keys as $key ) {
			if ( ! array_key_exists( $key, $desired ) && isset( $current[ $key ] ) && '' !== $current[ $key ] ) {
				$delete[] = $key;
			}
		}
		return array(
			'set'    => $set,
			'delete' => $delete,
		);
	}

	/**
	 * Stamps or marks the order once checkout has created it.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function finalize( $order ) {
		if ( ! $order || $order->get_meta( Fe_Meta::SPLIT_DONE ) ) {
			return;
		}
		Fe_Addon_Field::hydrate( $order );
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
		$plan    = self::plan( $lines );
		$desired = array();
		if ( 'stamp' === $plan['action'] ) {
			$desired = Fe_Meta::to_meta( $plan['fulfillment'] );
		} elseif ( 'split' === $plan['action'] ) {
			$desired = array( Fe_Meta::WILL_SPLIT => 'yes' );
		}
		$managed = array_merge( Fe_Meta::contract_keys(), array( Fe_Meta::WILL_SPLIT ) );
		$current = array();
		foreach ( $managed as $key ) {
			$current[ $key ] = $order->get_meta( $key );
		}
		$changes = self::meta_changes( $current, $desired, $managed );
		foreach ( $changes['delete'] as $key ) {
			$order->delete_meta_data( $key );
		}
		foreach ( $changes['set'] as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		if ( ! empty( $changes['delete'] ) || ! empty( $changes['set'] ) ) {
			$order->save();
		}
		if ( 'stamp' === $plan['action'] ) {
			do_action( 'fe_order_stamped', $order );
		}
	}
}
