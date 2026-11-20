<?php
/**
 * Event delivery shipping method.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event delivery shipping method.
 */
class Fe_Method_Delivery extends Fe_Shipping_Method {

	/**
	 * Method id.
	 *
	 * @var string
	 */
	public $id = 'fe_delivery';

	/**
	 * Fulfillment type.
	 *
	 * @var string
	 */
	protected $fe_type = 'delivery';

	/**
	 * Default title.
	 *
	 * @return string
	 */
	protected function default_title() {
		return __( 'Event delivery', 'fulfillment-events-for-woocommerce' );
	}

	/**
	 * Default description.
	 *
	 * @return string
	 */
	protected function default_description() {
		return __( 'One delivery rate for each delivery date of the event in the cart.', 'fulfillment-events-for-woocommerce' );
	}
}
