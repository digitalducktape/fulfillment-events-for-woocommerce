<?php
/**
 * Event pickup shipping method.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event pickup method.
 */
class Fe_Method_Pickup extends Fe_Shipping_Method {

	/**
	 * Method id.
	 *
	 * @var string
	 */
	public $id = 'fe_pickup';

	/**
	 * Fulfillment type.
	 *
	 * @var string
	 */
	protected $fe_type = 'pickup';

	/**
	 * Default title.
	 *
	 * @return string
	 */
	protected function default_title() {
		return __( 'Event pickup', 'fulfillment-events-for-woocommerce' );
	}

	/**
	 * Default description.
	 *
	 * @return string
	 */
	protected function default_description() {
		return __( 'One pickup rate for each pickup date of the event in the cart.', 'fulfillment-events-for-woocommerce' );
	}
}
