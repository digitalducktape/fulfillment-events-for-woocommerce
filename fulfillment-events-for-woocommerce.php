<?php
/**
 * Plugin Name:          Fulfillment Events for WooCommerce
 * Plugin URI:           https://github.com/digitalducktape/fulfillment-events-for-woocommerce
 * Description:          Sell products tied to dated fulfillment events (pickup, delivery, courier), check out several dates in one payment, and split the paid order into one order per date.
 * Version:              0.8.1
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
	define( 'FE_VERSION', '0.8.1' );
}
if ( ! defined( 'FE_DB_VERSION' ) ) {
	define( 'FE_DB_VERSION', 2 );
}
if ( ! defined( 'FE_PLUGIN_FILE' ) ) {
	define( 'FE_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'FE_PLUGIN_DIR' ) ) {
	define( 'FE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'FE_REPO_URL' ) ) {
	define( 'FE_REPO_URL', 'https://github.com/digitalducktape/fulfillment-events-for-woocommerce' );
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
require_once FE_PLUGIN_DIR . 'includes/domain/class-fe-zone-rules.php';
require_once FE_PLUGIN_DIR . 'includes/data/class-fe-event-repo.php';
require_once FE_PLUGIN_DIR . 'includes/data/class-fe-location-repo.php';
require_once FE_PLUGIN_DIR . 'includes/data/class-fe-zone-rule-repo.php';
require_once FE_PLUGIN_DIR . 'includes/data/class-fe-product-links.php';
require_once FE_PLUGIN_DIR . 'includes/domain/class-fe-event-resolver.php';
require_once FE_PLUGIN_DIR . 'includes/domain/class-fe-rate-builder.php';
require_once FE_PLUGIN_DIR . 'includes/domain/class-fe-cart-grouper.php';
require_once FE_PLUGIN_DIR . 'includes/domain/class-fe-split-math.php';
require_once FE_PLUGIN_DIR . 'includes/domain/class-fe-fee-dedupe.php';
require_once FE_PLUGIN_DIR . 'includes/domain/class-fe-addon-rules.php';
require_once FE_PLUGIN_DIR . 'includes/domain/class-fe-event-setup.php';
require_once FE_PLUGIN_DIR . 'includes/functions.php';
require_once FE_PLUGIN_DIR . 'includes/wc/class-fe-cart.php';
require_once FE_PLUGIN_DIR . 'includes/wc/class-fe-checkout-dates.php';
require_once FE_PLUGIN_DIR . 'includes/wc/class-fe-order-stamper.php';
require_once FE_PLUGIN_DIR . 'includes/wc/class-fe-addon-field.php';
require_once FE_PLUGIN_DIR . 'includes/wc/class-fe-split-status.php';
require_once FE_PLUGIN_DIR . 'includes/wc/class-fe-order-splitter.php';
require_once FE_PLUGIN_DIR . 'includes/wc/class-fe-emails.php';
require_once FE_PLUGIN_DIR . 'includes/wc/class-fe-order-received.php';
require_once FE_PLUGIN_DIR . 'includes/admin/class-fe-zone-grid.php';
require_once FE_PLUGIN_DIR . 'includes/admin/class-fe-event-setup-box.php';
require_once FE_PLUGIN_DIR . 'includes/admin/class-fe-event-editor.php';
require_once FE_PLUGIN_DIR . 'includes/admin/class-fe-locations-page.php';
require_once FE_PLUGIN_DIR . 'includes/admin/class-fe-product-fields.php';
require_once FE_PLUGIN_DIR . 'includes/admin/class-fe-order-admin.php';
require_once FE_PLUGIN_DIR . 'includes/admin/class-fe-notices.php';
require_once FE_PLUGIN_DIR . 'includes/admin/class-fe-admin.php';
require_once FE_PLUGIN_DIR . 'includes/rest/class-fe-rest-controller.php';
require_once FE_PLUGIN_DIR . 'includes/class-fe-plugin.php';

register_activation_hook( __FILE__, array( 'Fe_Activator', 'activate' ) );

add_action( 'plugins_loaded', array( 'Fe_Plugin', 'init' ) );
