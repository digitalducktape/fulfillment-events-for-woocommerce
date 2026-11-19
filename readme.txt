=== Fulfillment Events for WooCommerce ===
Contributors: digitalducktape
Donate link: https://buymeacoffee.com/digitalducktape
Tags: woocommerce, delivery, pickup, shipping, events
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell products tied to dated pickup, delivery and courier events, check out several dates in one payment, and split the paid order per date.

== Description ==

Define fulfillment events with one or more dates, attach products or categories to them, and let WooCommerce shipping zones decide which fulfillment types are offered and at what price. A cart can span several events; the customer pays once and the order splits into one order per date.

This is an early development release.

== Installation ==

1. Upload the plugin and activate it. WooCommerce must be active.

== Support ==

Support this plugin: https://buymeacoffee.com/digitalducktape

== Changelog ==

= 0.2.1 =
* Split: hand recorded sales to the children, lock against concurrent splits, roll back a failed split, release on-hold children when payment completes.

= 0.2.0 =
* Split a paid multi-date order into one order per date, with a new Split status and a combined-delivery credit.

= 0.1.1 =
* Order meta contract v1 and fe_get_order_fulfillment().

= 0.1.0 =
* Repository scaffold, database schema and test harness.
