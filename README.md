# Fulfillment Events for WooCommerce

Sell products tied to dated fulfillment events (delivery, pickup, courier). One
cart can span several events and dates, every date's fee is charged in one
payment, and the paid order splits into one order per date so each can be
completed on its own.

Design notes: [docs/design.md](docs/design.md).

## Support

If this plugin saves you time, you can support its development.

[![Support this plugin](https://img.buymeacoffee.com/button-api/?text=Support%20this%20plugin&emoji=%E2%9D%A4%EF%B8%8F&slug=digitalducktape&button_colour=FFDD00&font_colour=000000&font_family=Cookie&outline_colour=000000&coffee_colour=ffffff)](https://www.buymeacoffee.com/digitalducktape)

## Requirements

- WordPress 6.0+ (tested up to 6.9)
- WooCommerce 8.9+ (tested up to 10.3); 9.2+ for courier instructions in the block checkout
- PHP 7.4+

## Install

1. Download the plugin zip and upload it under Plugins > Add New Plugin > Upload Plugin, or copy the folder to `wp-content/plugins/`.
2. Activate it. WooCommerce must be active; the plugin creates three tables (`fe_event_dates`, `fe_locations`, `fe_zone_rules`) on activation.

## Configure

1. **Event methods.** In WooCommerce > Settings > Shipping, add "Event delivery", "Event pickup", "Event courier" and "Event add-on" to each zone that should offer them. Each method has a default cost and default time; every event inherits them.
2. **Locations.** Under Fulfillment > Locations, add pickup locations and mark one as the default.
3. **Settings.** Under Fulfillment > Settings, set the courier instructions shown at checkout and choose whether admins get a new-order email for each split order.
4. **Events.** Under Fulfillment > Events, add an event. It opens as one **Event setup** screen:
   - **When it sells:** an optional sales window.
   - **Dates:** one card per date with three lines: Delivery (time, and which zones), Pickup (time and place) and Courier (which zones). Untick a line or a zone to turn it off for that date. **Stop taking orders for this date** closes a date that's full: it keeps its settings, disappears from checkout and product pages, and can be reopened. Orders already placed are unchanged. A separate **Add-ons** line on each date decides whether customers can add items to their existing orders for that date, whether or not it's closed.
   - **Products:** categories and individual products, plus exceptions: a product can be sold **only on** some of the event's dates, or be **not in this event**.
   - **Change a price for this event** (closed by default): a different price for a zone and service, for all dates or one.

   A side box holds internal notes, and the events list shows each event's dates, notes and exception count. **Duplicate** copies an event as a draft.
5. **Products** can also be attached from the product edit screen (General tab), a category's edit screen, or bulk edit, which can add, exclude or remove several events at once.

Only published events whose sales window is open take effect. A product attached to an event that isn't open (a draft, or before its sales window) can't be bought, so attach products when you publish.

### At checkout

- A cart with event products gets one shipping section per event, soonest date first, plus a regular section for everything else. Carts with no event products are not touched.
- A section with several dates has a **Date** dropdown and one option per service that runs on that date for the customer's address, e.g. "Delivery · Thu, Dec 10 · 9am - 1pm". This works in the block and classic cart and checkout. The block dropdowns sit above the shipping options; in classic they sit at the top of each section.
- A product limited to some dates gets its own section until its dates match the rest. A product in several events offers the dates of all of them.
- Courier options show the courier instructions underneath.
- On a product page, an open event adds a line such as "Available for delivery or pickup on Dec 10 or Dec 18".

### After payment

When a paid order spans several sections, it moves to the "Split" status and one child order is created per section. Children are linked with `_fe_parent_id` (not the WooCommerce parent), so WooCommerce Analytics does not treat them as refunds. The customer gets one confirmation email listing every order, and the order-received page lists them too. A negative "Combined delivery" fee removes a duplicate delivery charge when two sections deliver on the same date.

## Order meta contract

Written on every order that has a fulfillment (unsplit orders and each split child). Read it with `fe_get_order_fulfillment( $order )`.

| Meta key | Value |
|---|---|
| `_fe_event_id` | event post ID |
| `_fe_event_name` | event title snapshot |
| `_fe_date` | `Y-m-d` |
| `_fe_type` | `delivery`, `pickup` or `courier` |
| `_fe_window` | display window, e.g. `9am - 6pm` (may be empty) |
| `_fe_location` | pickup location name and address snapshot (pickup only) |
| `_fe_addon_of` | target order ID (add-on orders; `_fe_type` and `_fe_date` are copied from the target) |
| `_fe_parent_id` | parent order ID (split children) |
| `_fe_contract` | `1`, the contract version |

Values are snapshots, so historical orders stay readable if an event is edited or deleted.

## REST API

Read-only, for users with the `manage_woocommerce` capability:

- `GET /wp-json/fe/v1/events` lists events (id, title, status, sales_open, sales_close, dates).
- `GET /wp-json/fe/v1/events/{id}` returns one event plus its zone rules.

## Hooks

Actions:

- `fe_order_stamped( $order )` after fulfillment meta is written to an order.
- `fe_order_split( $parent, $children )` after a successful split.
- `fe_order_split_failed( $parent, $children, $exception )` when a split fails or needs manual review.

Filters:

- `fe_rate_label( $label, $spec, $event, $method_id )` the label of a date rate.
- `fe_courier_instructions( $text, $event, $package )` the courier instructions shown at checkout.
- `fe_package_rates_for_event( $specs, $event, $package, $method )` the rate specs a method offers for an event package.
- `fe_should_split_order( $should_split, $order )` return false to skip splitting an order.
- `fe_child_order_args( $props, $group, $parent )` properties for each child order before it is created.

## Templates

Copy into your theme's `woocommerce/` folder to override:

- `emails/split-confirmation.php` (and `emails/plain/split-confirmation.php`): the customer's split confirmation.
- `order-received/split-orders.php`: the split orders on the order-received page.

## Store API

The cart response carries `extensions.fulfillment-events.sections`, one entry per section with a date dropdown: `{package_id, group, label, selected, options: [{value, label}]}`. Picking a date is a cart extensions update with namespace `fulfillment-events` and data `{group, date}`.

## Known limitations

- Split children cannot be refunded on their own; refund on the parent order. Children don't carry the transaction id (it is kept as `_fe_parent_transaction_id`), so WooCommerce offers no "Refund via gateway" on them.
- Block checkout on WooCommerce 8.9 to 9.8: the "Adding to an existing order? Order number" field needs conditional additional fields (WooCommerce 9.9), so it is not registered for block checkout, and the guest "Add to an existing order" rate is only offered on the classic checkout. Logged-in customers still see their own orders as add-on rates. On 9.9 and later the block field appears only when that rate is selected.
- Payment gateways that only authorize at checkout and capture later are not supported: the split runs when the order reaches Processing or On hold, and the capture must happen on the parent.
- A split that fails and is rolled back is not retried automatically. The order stays one order with `_fe_split_failed` = `yes`; delete that order meta to let the next status change or payment retry the split.
- WooCommerce Blocks "Local pickup" rates are filtered out of event packages; use the plugin's Event pickup method for event items.
- When a duplicate same-date delivery fee is credited ("Combined delivery"), the shipping tax on that fee is still charged.
- A split parent passes through Processing (or On hold) before Split, so third-party hooks on those statuses fire for the parent and again for each child.
- A product attached to an event that isn't open (draft, private, or outside its sales window) is not purchasable.
- In the block checkout, WooCommerce gives plugins one place above all the shipping options, so with several sections each dropdown is labelled "Date for …" instead of sitting inside its section.
- Courier has no "Combined delivery" credit: a customer who picks courier on the same date in two sections pays for it twice.
- Listeners on `fe_order_stamped` must be idempotent; it can fire more than once for an order.
- The "possible no-shipping product" hint only looks at the first 200 published physical products.

## Development

Tests run in Docker (`php:8.2-cli` and PHPUnit 9); with no Docker daemon,
`bin/test.sh` falls back to a local `php`.

```
bin/test.sh        # lint + unit tests
bin/build-zip.sh   # installable zip in dist/ (honors .distignore)
composer install && vendor/bin/phpcs   # coding standards
```

Translation template: `languages/fulfillment-events-for-woocommerce.pot`
(regenerate with `vendor/bin/wp i18n make-pot . languages/fulfillment-events-for-woocommerce.pot --exclude=vendor,tests,bin,.superpowers,dist`).
Manual checks that unit tests can't cover are in
[docs/testing-checklist.md](docs/testing-checklist.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
