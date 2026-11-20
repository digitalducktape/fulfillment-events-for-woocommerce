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

/**
 * Text shown under courier options at checkout. Until staff save their own
 * wording in Fulfillment → Settings, a default explains how courier works;
 * saving it empty shows nothing.
 *
 * @return string
 */
function fe_courier_instructions() {
	$text = get_option( 'fe_courier_instructions', null );
	if ( null === $text || false === $text ) {
		return fe_default_courier_instructions();
	}
	return trim( (string) $text );
}

/**
 * Default courier instructions.
 *
 * @return string
 */
function fe_default_courier_instructions() {
	return __( "Send your own courier, such as Uber, to collect your order on the date you choose. We'll contact you to arrange the pickup time.", 'fulfillment-events-for-woocommerce' );
}
