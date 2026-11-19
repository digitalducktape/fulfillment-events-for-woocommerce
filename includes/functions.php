<?php
/**
 * Public helper functions.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns an order's fulfillment details, or null when it has none.
 *
 * @param WC_Order|int $order Order or order ID.
 * @return array|null {event_id, event_name, date, type, window, location, addon_of, parent_id}
 */
function fe_get_order_fulfillment( $order ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order );
	}
	if ( ! $order instanceof WC_Order ) {
		return null;
	}
	$meta = array();
	foreach ( Fe_Meta::contract_keys() as $key ) {
		$meta[ $key ] = $order->get_meta( $key );
	}
	return Fe_Meta::from_meta( $meta );
}
