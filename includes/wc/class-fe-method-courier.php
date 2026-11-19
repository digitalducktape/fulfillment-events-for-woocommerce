<?php
/**
 * Event courier shipping method.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event courier method.
 */
class Fe_Method_Courier extends Fe_Shipping_Method {

	/**
	 * Method id.
	 *
	 * @var string
	 */
	public $id = 'fe_courier';

	/**
	 * Fulfillment type.
	 *
	 * @var string
	 */
	protected $fe_type = 'courier';

	/**
	 * Default title.
	 *
	 * @return string
	 */
	protected function default_title() {
		return __( 'Event courier', 'fulfillment-events-for-woocommerce' );
	}

	/**
	 * Default description.
	 *
	 * @return string
	 */
	protected function default_description() {
		return __( 'One courier rate for each date of the event in the cart.', 'fulfillment-events-for-woocommerce' );
	}
}
