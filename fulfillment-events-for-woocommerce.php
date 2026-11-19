<?php
/**
 * Plugin Name:          Fulfillment Events for WooCommerce
 * Plugin URI:           https://github.com/digitalducktape/fulfillment-events-for-woocommerce
 * Description:          Sell products tied to dated fulfillment events (pickup, delivery, courier), check out several dates in one payment, and split the paid order into one order per date.
 * Version:              0.1.1
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.9
 * Author:               Ed Wolf
 * Author URI:           https://www.ed-wolf.com/
 * License:              GPL v2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          fulfillment-events-for-woocommerce
 * Domain Path:          /languages
 *
 * @package Fulfillment_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'FE_VERSION' ) ) {
	define( 'FE_VERSION', '0.1.1' );
}
if ( ! defined( 'FE_DB_VERSION' ) ) {
	define( 'FE_DB_VERSION', 1 );
}
if ( ! defined( 'FE_PLUGIN_FILE' ) ) {
	define( 'FE_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'FE_PLUGIN_DIR' ) ) {
	define( 'FE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'FE_SUPPORT_URL' ) ) {
	define( 'FE_SUPPORT_URL', 'https://buymeacoffee.com/digitalducktape' );
}

// Declare HPOS and Cart/Checkout Blocks compatibility.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', FE_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', FE_PLUGIN_FILE, true );
		}
	}
);

require_once FE_PLUGIN_DIR . 'includes/class-fe-activator.php';
require_once FE_PLUGIN_DIR . 'includes/domain/class-fe-meta.php';
require_once FE_PLUGIN_DIR . 'includes/functions.php';
require_once FE_PLUGIN_DIR . 'includes/class-fe-plugin.php';

register_activation_hook( __FILE__, array( 'Fe_Activator', 'activate' ) );

add_action( 'plugins_loaded', array( 'Fe_Plugin', 'init' ) );
