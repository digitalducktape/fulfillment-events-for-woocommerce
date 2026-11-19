# Manual testing checklist

Staging checks that unit tests can't cover. Each milestone appends its checks
here.

## M1: plugin core

- [ ] Activate on a fresh site: the three fe_ tables exist; re-activating or bumping the plugin does not re-ALTER columns (check the debug log for dbDelta queries).
- [ ] The fe_event post type is registered (no menu yet): wp-admin/edit.php?post_type=fe_event lists events and the editor opens.
- [ ] Save a location as default, then save another as default: only the second stays default.
- [ ] Link an event to a product and to a category; a variation of the product resolves to the parent product links and categories.

- [ ] A product linked only to an event whose sales window has ended: the add-to-cart button disappears and the product cannot be added; a variation of it is also unavailable.
- [ ] A product linked to an open event and a closed event stays purchasable; an unlinked product is unaffected.
- [ ] A product whose only open event has all dates in the past is not purchasable.

- [ ] WooCommerce > Settings > Shipping: add Event delivery, Event pickup, Event courier and Event add-on to a zone; each saves its title, cost and default window in the modal.
- [ ] For a package carrying a published event, the zone shows one rate per date, labelled like "Delivery · Thu, Dec 10" and "Pickup at {location} · Sun, Dec 13"; past dates and dates switched off for the zone are absent; a draft event yields no rates.
- [ ] After checkout, the shipping line item carries fe_event_id, fe_event_name, fe_date, fe_type, fe_window and fe_location meta.
- [ ] A cart with no event product shows none of these rates.

- [ ] Block checkout, cart with products from two events: two shipping packages named after the events, each showing only Event rates.
- [ ] Add a regular (no-event) product to that cart: a third package shows only the store's stock shipping methods.
- [ ] A cart with only a non-event product is byte-identical to before: compare the Store API /cart response with the plugin on and off.
- [ ] Enter an address in a zone with no rates for an event: a notice reads "{event} items aren't available for {postcode}. Try a different address or remove them from your cart." in classic cart/checkout and blocks, and blocks checkout is blocked. With no postcode entered, the notice omits it.

- [ ] Single-event orders (delivery, pickup, courier) in block and classic checkout: order meta shows `_fe_contract`, `_fe_event_id`, `_fe_event_name`, `_fe_date`, `_fe_type`, `_fe_window` (plus `_fe_location` for pickup), and `fe_get_order_fulfillment()` returns them.
- [ ] Line items carry `_fe_group` (`event:{id}`, `regular`, or `none` for virtual) and shipping items carry the package group.
- [ ] An order with an event package plus a regular package gets `_fe_will_split = yes` and no contract meta.
- [ ] Rename the event or location after ordering: existing orders keep the name and location they were stamped with.

- [ ] Admin menu: "Fulfillment" (calendar icon) lists Events, Locations, Settings with Events first; the top-level link opens the event list; the "Support this plugin" link opens in a new tab from Settings and the event editor.
- [ ] Settings: toggle both options, save, reload; values persist. Locations: add, edit, set default, delete; the default stays unique.
- [ ] Create an event "Holiday pre-order" with 3 dates (mixed delivery and pickup, windows, a non-default location), a sales window, a zone override (for example delivery on, cost 25, "only on" one date) and a turned-off courier cell. Save, reload: every value is identical. Adding and removing date rows works without a page reload.
- [ ] Zone grid: zones with no delivery/pickup/courier method show "Not in zone" and have no form; pills show "{cost} default"/"Free", "Turned off" and the override cost matching the saved rules; zone "Locations not covered by your other zones" is listed last.
- [ ] Set sales close before sales open and save: the close time is cleared and a warning appears. After any save, the checkout shipping rates reflect the change without clearing transients by hand.
- [ ] Event list: "Duplicate for next year" creates a draft "{title} (copy)" with every date, rule date and the sales window one year later (a Feb 29 date is dropped) and the same category links.
- [ ] Delete an event permanently: its dates and zone rules rows are gone (check the fe_ tables) and products and categories no longer list it.
- [ ] Product edit, General tab: "Fulfillment events" multiselect saves; category add and edit screens save the same field. A product in two open events shows "In 2 open events" in the Events column of the products list; one in a single event shows a dash.
- [ ] Shop manager (manage_woocommerce without administrator) can open Events, Locations, Settings and save an event.

## M2: order split

## M4: add-ons and polish
