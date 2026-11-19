<?php
/**
 * Event addon shipping method.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event add-on method.
 */
class Fe_Method_Addon extends Fe_Shipping_Method {

	/**
	 * Method id.
	 *
	 * @var string
	 */
	public $id = 'fe_addon';

	/**
	 * Fulfillment type.
	 *
	 * @var string
	 */
	protected $fe_type = 'addon';

	/**
	 * Default title.
	 *
	 * @return string
	 */
	protected function default_title() {
		return __( 'Event add-on', 'fulfillment-events-for-woocommerce' );
	}

	/**
	 * Default description.
	 *
	 * @return string
	 */
	protected function default_description() {
		return __( 'Add-on to an existing order. Rates arrive in a later release.', 'fulfillment-events-for-woocommerce' );
	}
}
