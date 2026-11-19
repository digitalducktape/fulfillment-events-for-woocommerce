=== Fulfillment Events for WooCommerce ===
Contributors: digitalducktape
Donate link: https://buymeacoffee.com/digitalducktape
Tags: woocommerce, delivery, pickup, shipping, events
Requires at least: 6.0
Tested up to: 6.9
WC tested up to: 10.3
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell products tied to dated pickup, delivery and courier events, check out several dates in one payment, and split the paid order per date.

== Description ==

Define fulfillment events with one or more dates, attach products or categories to them, and let WooCommerce shipping zones decide which fulfillment types are offered and at what price. A cart can span several events; the customer pays once and the order splits into one order per date.

On a product page, an active event adds a line such as "Available for delivery or pickup on Dec 10 or Dec 18". Orders carry a documented set of `_fe_*` meta so other tools can read the fulfillment.

== Installation ==

1. Upload the plugin and activate it. WooCommerce must be active.
2. In WooCommerce > Settings > Shipping, add "Event delivery", "Event pickup", "Event courier" and "Event add-on" to your zones and set their default cost and window.
3. Under Fulfillment > Locations, add pickup locations.
4. Under Fulfillment > Events, add an event with dates, then use the zone grid to keep, turn off or override each zone's rates.
5. Attach the event to products (product edit screen) or categories (category edit screen).

== Order meta ==

Written on every order that has a fulfillment; read it with fe_get_order_fulfillment().

* _fe_event_id: event post ID
* _fe_event_name: event title snapshot
* _fe_date: Y-m-d
* _fe_type: delivery, pickup or courier
* _fe_window: display window (may be empty)
* _fe_location: pickup location snapshot (pickup only)
* _fe_addon_of: target order ID (add-on orders)
* _fe_parent_id: parent order ID (split children)
* _fe_contract: 1, the contract version

== Hooks ==

Actions: fe_order_stamped, fe_order_split, fe_order_split_failed.
Filters: fe_rate_label, fe_package_rates_for_event, fe_should_split_order, fe_child_order_args.

== Frequently Asked Questions ==

= Is there a REST API? =

Yes, read-only: /wp-json/fe/v1/events and /wp-json/fe/v1/events/{id}, for users who can manage WooCommerce.

== Support ==

Support this plugin: https://buymeacoffee.com/digitalducktape

== Known limitations ==

* Split children cannot be refunded on their own; refund on the parent order.
* Block checkout on WooCommerce 8.9 to 9.8: the "Adding to an existing order? Order number" field shows on every checkout, because conditional additional fields need WooCommerce 9.9. It is optional and ignored unless the customer picks "Add to an existing order". On 9.9 and later it only appears when that rate is selected.

== Changelog ==

= 0.3.2 =
* Availability note on product pages, a hint for products that look like tickets or classes, read-only REST endpoints, a translation template and fuller uninstall cleanup.

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
