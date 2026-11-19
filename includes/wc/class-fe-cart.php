<?php
/**
 * Cart behaviour: purchasability, and (in later tasks) packages and notices.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce cart integration.
 */
class Fe_Cart {

	/**
	 * Per-request cache of product status.
	 *
	 * @var array<int, array>
	 */
	private static $status_cache = array();

	/**
	 * Per-request cache of open events.
	 *
	 * @var array[]|null
	 */
	private static $open_events = null;

	/**
	 * Registers the purchasable filters.
	 */
	public static function init() {
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'filter_purchasable' ), 10, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'filter_purchasable' ), 10, 2 );
	}

	/**
	 * Event status of a product, computed once per request.
	 *
	 * @param int $product_id Product id (a variation id resolves via its parent in the links layer).
	 * @return array{status:string, event_id:?int}
	 */
	public static function product_event_status( $product_id ) {
		$product_id = (int) $product_id;
		if ( isset( self::$status_cache[ $product_id ] ) ) {
			return self::$status_cache[ $product_id ];
		}

		$ids = Fe_Product_Links::event_ids_for_product( $product_id );
		if ( empty( $ids ) ) {
			$result = array(
				'status'   => 'none',
				'event_id' => null,
			);
		} else {
			if ( null === self::$open_events ) {
				self::$open_events = Fe_Event_Repo::open_events( current_time( 'mysql' ) );
			}
			$resolved = Fe_Event_Resolver::resolve( $ids, self::$open_events, current_time( 'Y-m-d' ) );
			$result   = array(
				'status'   => Fe_Event_Resolver::status( $ids, $resolved ),
				'event_id' => $resolved,
			);
		}
		self::$status_cache[ $product_id ] = $result;
		return $result;
	}

	/**
	 * Blocks purchase of products whose events are all closed.
	 *
	 * @param bool       $purchasable Current value.
	 * @param WC_Product $product     Product or variation.
	 * @return bool
	 */
	public static function filter_purchasable( $purchasable, $product ) {
		if ( ! $purchasable ) {
			return $purchasable;
		}
		$id = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
		$st = self::product_event_status( $id );
		return 'closed' === $st['status'] ? false : $purchasable;
	}
}
