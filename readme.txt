=== Fulfillment Events for WooCommerce ===
Contributors: digitalducktape
Donate link: https://buymeacoffee.com/digitalducktape
Tags: woocommerce, delivery, pickup, shipping, events
Requires at least: 6.0
Tested up to: 6.9
WC tested up to: 10.3
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.6.7
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

* Split children cannot be refunded on their own; refund on the parent order. Children don't carry the transaction id (it is kept as `_fe_parent_transaction_id`), so WooCommerce offers no "Refund via gateway" on them.
* Block checkout on WooCommerce 8.9 to 9.8: the "Adding to an existing order? Order number" field needs conditional additional fields (WooCommerce 9.9), so it is not registered for block checkout, and the guest "Add to an existing order" rate is only offered on the classic checkout. Logged-in customers still see their own orders as add-on rates. On 9.9 and later the block field appears only when that rate is selected.
* Payment gateways that only authorize at checkout and capture later are not supported: the split runs when the order reaches Processing or On hold, and the capture must happen on the parent.
* A split that fails and is rolled back is not retried automatically. The order stays one order with `_fe_split_failed` = `yes`; delete that order meta to let the next status change or payment retry the split.
* WooCommerce Blocks "Local pickup" rates are filtered out of event packages; use the plugin's Event pickup method for event items.
* When a duplicate same-date delivery fee is credited ("Combined delivery"), the shipping tax on that fee is still charged.
* A split parent passes through Processing (or On hold) before Split, so third-party hooks on those statuses fire for the parent and again for each child.
* A product linked only to unpublished (draft or private) events is not purchasable.
* Listeners on `fe_order_stamped` must be idempotent; it can fire more than once for an order.

== Changelog ==

= 0.6.7 =
* Classic checkout: an option's text stays beside its radio button in themes that let shipping options wrap (such as Kadence), instead of dropping below it when the column is narrow.

= 0.6.6 =
* Split confirmation email: the closing line says "Each order above", since the orders come before it.
* Fulfillment → Settings: "Staff emails" is now "Admin emails".

= 0.6.5 =
* Order-received page: a split order lists each order it became, with its number, items, and how and when it arrives. Works on the classic and block order confirmation; themes can override templates/order-received/split-orders.php.
* Classic checkout: radio buttons sit beside their option's text again, on the first line, when the theme lays options out differently from the cart.

= 0.6.4 =
* Classic cart and checkout: each option's radio button lines up with its first line of text when the option wraps.

= 0.6.3 =
* Updating the plugin refreshes shipping options WooCommerce had saved in shoppers' sessions, so changes like the times in 0.6.2 show straight away.
* The classic checkout shows the courier instructions even when a saved option has none.

= 0.6.2 =
* Delivery and pickup options show their time: "Delivery · Thu, Dec 10 · 9am–1pm". The time is also on the order and its emails.
* Courier instructions sit under the courier option's name. In the block checkout they're shown as the option's delivery time (WooCommerce 9.2+), the only extra text it displays.

= 0.6.1 =
* Classic cart and checkout: the Date dropdown sits above the section's shipping options, and the courier instructions get a line of their own instead of squeezing beside the option.

= 0.6.0 =
* Checkout date picker: each event section has a Date dropdown and offers one option per service (delivery, pickup, courier) for that date, instead of one option per service and date. Works in the block and classic checkout and cart.
* The section only lists the services that run on the picked date for the customer's address.
* Fulfillment → Settings links to the project on GitHub and to "Support this plugin".

= 0.5.1 =
* Checkout lists shipping sections soonest date first, whatever order items were added in.
* A date-limited section is headed with its dates ("Holiday Pre-order 2026 · Dec 18"), not a product name.
* Courier instructions: set them in Fulfillment → Settings; they show under each courier option at checkout.
* Quick Edit is gone from the events list; it couldn't edit dates, zones or products.

= 0.5.0 =
* One "Event setup" screen instead of four boxes: when it sells, the dates, the products, and (rarely needed) price changes.
* Each date says what runs: delivery (time and which zones), pickup (time and place) and courier (which zones), each on or off. Courier can now be turned off for a date.
* Product exceptions on the event: limit a product to some of the event's dates, or take it out of the event. One holiday event can now hold a product that ships only on Dec 18.
* A date-limited product gets its own shipping choice at checkout, offering only its dates.
* "Add to my order" only offers orders on a date the new items allow.
* Products show their date limits on the edit screen and in the Products list; the events list counts each event's exceptions.
* Events from 0.4.x keep their settings: zones turned off for a whole event show as unticked on every date, and price overrides move to "Change a price for this event".

= 0.4.5 =
* Fix: "Add to my order" could be missing at checkout when the same cart had been priced before the customer's order existed. Cached rates now refresh when the customer's open orders change.
* Fix: the zone grid showed an extra row after every save. Each cell now has one setting for all dates, plus one row per date for multi-date events.
* Clearer event editor: Delivery and Pickup checkboxes sit next to their times, the pickup place has its own column, and each zone cell shows its state by colour with the price box only shown for a custom price.
* The Zones box links to WooCommerce's shipping zone settings.
* The events list shows each event's dates and what they offer.
* Events have an Internal notes box, shown in the events list and copied by Duplicate.

= 0.4.4 =
* A product in more than one open event now offers every event's dates, on the product page note and at checkout, instead of only the soonest event's. As each event closes, its dates drop off.
* Products list bulk edit takes several events at once.
* The event edit screen has a Duplicate button in the Publish box.

= 0.4.3 =
* Products that don't need shipping (virtual, e.g. class tickets) ignore events: no "Available for delivery or pickup" note, and a closed event no longer makes them unpurchasable, even when their category is in the event.

= 0.4.2 =
* Products list bulk edit: add products to an event, exclude them from it, or remove them (back to following their categories).
* The Products list Events column names each event a product is in, with excluded events struck through.

= 0.4.1 =
* Products can be excluded from an event, from the event's Products box or the product's "Excluded from events" field. An exclusion wins over category and product links.
* The Event add-on shipping method only has a title; its rates are always free and use the target order's date, window and location.
* "Duplicate for next year" is now "Duplicate": an exact draft copy (dates, sales window, zone rules, categories, products and exclusions).

= 0.4.0 =
* Checkout rejects an event date that has passed since the rates were shown; rates refresh daily.
* Block checkout below WooCommerce 9.9: the add-on order number field is no longer shown on every checkout; the guest add-on rate is classic-only there.
* Split: no database lock for orders that won't split; a rolled-back split is not retried until `_fe_split_failed` is deleted; children no longer copy the transaction id (kept as `_fe_parent_transaction_id`).
* Emails: the split confirmation no longer depends on the mailer being loaded; on-hold payment instructions are sent for split orders; with per-child staff emails off, staff get one new-order email for the whole order.

= 0.3.3 =
* Availability note only on the main product's price block; hint cache clears when an event is saved.

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
