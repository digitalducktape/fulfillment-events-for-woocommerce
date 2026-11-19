# Fulfillment Events for WooCommerce

Sell products tied to dated fulfillment events (delivery, pickup, courier). One
cart can span several events and dates, every date's fee is charged in one
payment, and the paid order splits into one order per date so each can be
completed on its own.

Design notes: [docs/design.md](docs/design.md).

## Requirements

- WordPress 6.0+ (tested up to 6.9)
- WooCommerce 8.9+ (tested up to 10.3)
- PHP 7.4+

## Install

1. Download the plugin zip and upload it under Plugins > Add New Plugin > Upload Plugin, or copy the folder to `wp-content/plugins/`.
2. Activate it. WooCommerce must be active; the plugin creates three tables (`fe_event_dates`, `fe_locations`, `fe_zone_rules`) on activation.

## Configure

1. **Zones.** In WooCommerce > Settings > Shipping, open each shipping zone you serve.
2. **Event methods.** Add "Event delivery", "Event pickup", "Event courier" and "Event add-on" to the zones that should offer them. Each method has a default cost and default time window; these are the defaults every event inherits.
3. **Locations.** Under Fulfillment > Locations, add pickup locations and mark one as the default.
4. **Events.** Under Fulfillment > Events, add an event: give it a name, an optional sales window (when it opens and closes for orders) and one or more dates. For each date, choose whether delivery and pickup are offered, set a window, and pick a location.
5. **Zone grid.** In the event editor, the zone grid shows each zone and fulfillment type. Leave a cell on its default, turn it off, or override the cost, optionally for a single date.
6. **Attach products.** Link the event to products (product edit screen, General tab) or to whole product categories (category edit screen).

Cart behaviour: a cart with event products gets one shipping package per event, plus a regular package for everything else. Each event package shows one rate per available date. Carts with no event products are not touched. On a product page, an active event adds a line such as "Available for delivery or pickup on Dec 10 or Dec 18".

When a paid order spans several packages, it moves to the "Split" status and one child order is created per package. Children are linked with `_fe_parent_id` (not the WooCommerce parent), so WooCommerce Analytics does not treat them as refunds. A negative "Combined delivery" fee removes a duplicate delivery charge when two packages deliver on the same date.

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
- `fe_package_rates_for_event( $specs, $event, $package, $method )` the rate specs a method offers for an event package.
- `fe_should_split_order( $should_split, $order )` return false to skip splitting an order.
- `fe_child_order_args( $props, $group, $parent )` properties for each child order before it is created.

## Known limitations

- Block checkout on WooCommerce 8.9 to 9.8: the "Adding to an existing order? Order number" field shows on every checkout, because conditional additional fields need WooCommerce 9.9. It is optional and ignored unless the customer picks "Add to an existing order". On 9.9 and later it only appears when that rate is selected.
- Split children cannot be refunded on their own; refund on the parent order.
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
(regenerate with `vendor/bin/wp i18n make-pot . languages/fulfillment-events-for-woocommerce.pot --exclude=vendor,tests,bin,.superpowers`).
Manual checks that unit tests can't cover are in
[docs/testing-checklist.md](docs/testing-checklist.md).

## Support

If this plugin saves you time, you can [support this plugin](https://buymeacoffee.com/digitalducktape).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
