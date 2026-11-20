<?php
/**
 * Checkout and cart block integration for the date picker.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

/**
 * Loads assets/checkout-dates.js wherever the checkout or cart block shows.
 */
class Fe_Blocks_Integration implements IntegrationInterface {

	/**
	 * Integration name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'fulfillment-events-dates';
	}

	/**
	 * Registers the script.
	 */
	public function initialize() {
		wp_register_script(
			'fe-checkout-dates',
			plugins_url( 'assets/checkout-dates.js', FE_PLUGIN_FILE ),
			array( 'wp-plugins', 'wp-element', 'wp-data', 'wc-blocks-checkout' ),
			FE_VERSION,
			true
		);
	}

	/**
	 * Front-end scripts.
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		return array( 'fe-checkout-dates' );
	}

	/**
	 * Editor scripts: none.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array();
	}

	/**
	 * Script data: none; the dates come with the cart.
	 *
	 * @return array
	 */
	public function get_script_data() {
		return array();
	}
}
