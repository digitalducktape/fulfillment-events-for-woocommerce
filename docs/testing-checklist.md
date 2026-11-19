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

- [ ] Analytics filter name: on the installed WooCommerce, confirm `woocommerce_analytics_excluded_order_statuses` is applied (Reports DataStore::get_excluded_report_order_statuses). After activation, WooCommerce > Settings > Analytics "Excluded statuses" lists Split.
- [ ] Staging with the Square sandbox: a cart with products from two events (two dates) and a regular product. Pay. The parent moves to "Split" with a "Split into #a, #b, #c" note; each child is Processing, has `created_via` = `fe-split`, `_fe_parent_id` = parent id (WooCommerce parent is empty), its own items, its shipping line and the `_fe_*` contract meta for its event (the regular child has only `_fe_parent_id`).
- [ ] The children's totals sum exactly to the amount Square charged (the parent total); a cent of rounding, if any, sits on the first child with a note.
- [ ] Fees and fee tax are split across children by item subtotal; a coupon on the parent shows as a note on each child and the line totals are the discounted ones.
- [ ] Stock is reduced once: product stock drops by the ordered quantities and does not drop again when the children move to Processing. Each child line item carries `_reduced_stock`; the parent's line items do not.
- [ ] Cancel one child: only that child's items are restocked. Cancel (or fully refund with "restock" ticked) the parent: no stock changes.
- [ ] An offline gateway (bank transfer, cheque) puts the parent on-hold: it splits immediately and the children are On hold. Moving the children to Processing reduces no stock.
- [ ] Emails at split (two-event order): the customer receives exactly one email, the split confirmation, listing every child with event, type, date, window, pickup location and items; no per-child processing or on-hold emails and none for the parent.
- [ ] Staff new-order emails: one per child with "per child" ticked in Settings; none at all with it unticked; never one for the parent.
- [ ] Single-event and regular orders: stock WooCommerce emails are unchanged (customer processing/on-hold and staff new order).
- [ ] WooCommerce > Settings > Emails lists "Split order confirmation" with editable subject, heading and additional content; a theme copy at woocommerce/emails/split-confirmation.php overrides the template; plain text format renders.
- [ ] Orders list shows a Fulfillment column with HPOS on and again with legacy storage: children read "Type · date · part of #N", the parent reads "Split into #a, #b", and the links open the right order.
- [ ] Child edit screen shows "Refunds for this order are made on #N" linking to the parent; the parent edit screen links to each child.
- [ ] Idempotency: re-send the Square webhook / call payment complete again, and move the parent processing → on-hold → processing: no new children.
- [ ] With HPOS on, and again with HPOS compatibility mode (sync) on: after checkout returns, the parent is still "Split" in both the orders table and the posts backup, and the children's stock flag is set.
- [ ] Analytics > Revenue and Orders count the children, not the parent; the legacy Reports do not list the parent.
- [ ] The parent can't be paid again from My account (no "Pay" button on a Split order).
- [ ] Product "total sales" counts rise once per item (not twice) after a split; cancelling a child lowers only its items' counts; trashing the parent changes nothing.
- [ ] Send the Square webhook at the same moment as the checkout return (or replay it quickly): exactly one set of children; no `fe_split_lock_*` row is left in wp_options afterwards.
- [ ] Parent on-hold (bank transfer) split into on-hold children, then mark payment complete on the parent (or the gateway confirms): every on-hold child moves to Processing with a "Payment completed on #parent" note, and the parent gets a paid date.
- [ ] Two events delivering on the same date: the cart shows a negative "Combined delivery" fee equal to the cheaper delivery cost; picking a different date or pickup for one removes it.

## M4: add-ons and polish
