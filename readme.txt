=== Fulfillment Events for WooCommerce ===
Contributors: digitalducktape
Donate link: https://buymeacoffee.com/digitalducktape
Tags: woocommerce, delivery, pickup, shipping, events
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.3.1
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

== Known limitations ==

* Block checkout on WooCommerce 8.9 to 9.8: the "Adding to an existing order? Order number" field shows on every checkout, because conditional additional fields need WooCommerce 9.9. It is optional and ignored unless the customer picks "Add to an existing order". On 9.9 and later it only appears when that rate is selected.

== Changelog ==

= 0.3.1 =
* Add-ons: orders whose date has passed are no longer offered; a split confirmation's order number resolves to the right child; the block checkout field only appears when needed (WooCommerce 9.9+). The plugin's shipping line details are hidden from customers and order screens.


= 0.3.0 =
* Add on to an existing order: logged-in customers pick one of their open orders for the event; guests enter the order number, checked against the billing email at place-order.

= 0.2.1 =
* Split: hand recorded sales to the children, lock against concurrent splits, roll back a failed split, release on-hold children when payment completes.

= 0.2.0 =
* Split a paid multi-date order into one order per date, with a new Split status and a combined-delivery credit.

= 0.1.1 =
* Order meta contract v1 and fe_get_order_fulfillment().

= 0.1.0 =
* Repository scaffold, database schema and test harness.
