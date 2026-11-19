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
		Fe_Order_Stamper::init();
		Fe_Split_Status::init();
		Fe_Order_Splitter::init();
		if ( is_admin() ) {
			Fe_Admin::init();
		}
		add_action( 'woocommerce_shipping_init', array( __CLASS__, 'load_shipping_methods' ) );
		add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'register_shipping_methods' ) );
	}

	/**
	 * Loads the shipping method classes once WC_Shipping_Method exists.
	 */
	public static function load_shipping_methods() {
		require_once FE_PLUGIN_DIR . 'includes/domain/class-fe-rate-builder.php';
		require_once FE_PLUGIN_DIR . 'includes/wc/class-fe-shipping-method.php';
		require_once FE_PLUGIN_DIR . 'includes/wc/class-fe-method-delivery.php';
		require_once FE_PLUGIN_DIR . 'includes/wc/class-fe-method-pickup.php';
		require_once FE_PLUGIN_DIR . 'includes/wc/class-fe-method-courier.php';
		require_once FE_PLUGIN_DIR . 'includes/wc/class-fe-method-addon.php';
	}

	/**
	 * Registers the event shipping methods.
	 *
	 * @param array $methods Method id => class name.
	 * @return array
	 */
	public static function register_shipping_methods( $methods ) {
		self::load_shipping_methods();
		$methods['fe_delivery'] = 'Fe_Method_Delivery';
		$methods['fe_pickup']   = 'Fe_Method_Pickup';
		$methods['fe_courier']  = 'Fe_Method_Courier';
		$methods['fe_addon']    = 'Fe_Method_Addon';
		return $methods;
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
