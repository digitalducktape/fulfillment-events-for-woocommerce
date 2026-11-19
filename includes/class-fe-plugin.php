<?php
/**
 * Plugin bootstrap: requires WooCommerce, then wires hooks.
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin's hooks once WooCommerce is available.
 */
class Fe_Plugin {

	/**
	 * Entry point, run on plugins_loaded.
	 */
	public static function init() {
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woocommerce_notice' ) );
			return;
		}

		Fe_Activator::maybe_upgrade();
		add_action( 'init', array( 'Fe_Event_Repo', 'register_post_type' ) );
		Fe_Cart::init();
	}

	/**
	 * Admin notice shown when WooCommerce is inactive.
	 */
	public static function missing_woocommerce_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Fulfillment Events for WooCommerce needs WooCommerce to be installed and active.', 'fulfillment-events-for-woocommerce' ) . '</p></div>';
	}

	/**
	 * Adds the support link to the Plugins list row.
	 *
	 * @param string[] $links Existing row meta links.
	 * @param string   $file  Plugin basename.
	 * @return string[]
	 */
	public static function row_meta( $links, $file ) {
		if ( plugin_basename( FE_PLUGIN_FILE ) === $file ) {
			$links[] = '<a href="' . esc_url( FE_SUPPORT_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support this plugin', 'fulfillment-events-for-woocommerce' ) . '</a>';
		}
		return $links;
	}
}
