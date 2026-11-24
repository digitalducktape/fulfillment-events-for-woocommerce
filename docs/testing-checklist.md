# Manual testing checklist

Staging checks that unit tests can't cover. Each milestone appends its checks
here.

## M1: plugin core

- [ ] Activate on a fresh site: the three fe_ tables exist; re-activating or bumping the plugin does not re-ALTER columns (check the debug log for dbDelta queries).
- [ ] The fe_event post type is registered (no menu yet): wp-admin/edit.php?post_type=fe_event lists events and the editor opens.
- [ ] Save a location as default, then save another as default: only the second stays default.
- [ ] A product in two open events (Dec 10 and Dec 18): the product page says "… on Dec 10 or Dec 18" and checkout offers both events' dates in one shipping section headed "Event A / Event B". Choosing a Dec 18 rate makes the order (or split child) a Dec 18 event order. Close the Dec 10 event: only Dec 18 remains.
- [ ] Link an event to a product and to a category; a variation of the product resolves to the parent product links and categories.

- [ ] A product linked only to an event whose sales window has ended: the add-to-cart button disappears and the product cannot be added; a variation of it is also unavailable.
- [ ] A product linked to an open event and a closed event stays purchasable; an unlinked product is unaffected.
- [ ] A product whose only open event has all dates in the past is not purchasable.
- [ ] A virtual product (class ticket) in a category linked to an event shows no "Available for…" note, and stays purchasable after that event closes; a virtual variation of a variable product behaves the same.

- [ ] WooCommerce > Settings > Shipping: add Event delivery, Event pickup, Event courier and Event add-on to a zone; delivery, pickup and courier save their title, cost and default window in the modal; Event add-on shows only a title.
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
- [ ] Create one event "Holiday Pre-order" with Dec 10 (delivery, courier) and Dec 18 (delivery, pickup at a non-default location, courier), a sales window, East Bay unticked under courier on Dec 10, and a price change "Marin · Delivery · All dates · $25". Save, reload: every value is identical. Adding and removing dates, exceptions and price changes works without a page reload; a new date starts with every zone ticked.
- [ ] Zone lists show only zones that have that Event method; a type no zone offers says so. "Edit shipping zones and their prices" opens WooCommerce → Settings → Shipping in a new tab.
- [ ] Upgrade: an event saved on 0.4.x with a zone turned off for the whole event opens with that zone unticked on every date; saving without changes keeps it off at checkout. Its price overrides appear under "Change a price for this event".
- [ ] Untick every delivery zone on a date and save: no delivery rates for that date anywhere; the Delivery checkbox stays as set.
- [ ] Untick a zone on a date that also has a price change for that zone and date: after saving, the price change is gone and a notice says why.
- [ ] Exceptions: holiday cookie box "Only on Dec 18". Product page says "… on Dec 18" only. Cart with the cookie box and a cookie tin: two shipping choices, the cookie box one headed "Holiday Pre-order · Holiday cookie box" offering only Dec 18. Pick Dec 18 for both: one combined delivery credit; the order splits as usual.
- [ ] A variation of a date-limited variable product follows the parent's limit at checkout and the order splits correctly.
- [ ] Remove Dec 18 from the event while the cookie box is limited to it: saving shows "… has no dates left in this event"; the product can't be bought for that event.
- [ ] Hanukkah items in "Winter collection": add them as "Not in this event" exceptions: they lose the holiday event's rates and note; the Products list strikes the event through.
- [ ] "Only on" for a product not in the event by category: saving adds it to Individual products.
- [ ] Logged-in customer with a Dec 10 order: a cart with the Dec 18-only cookie box offers no "Add to my order #N"; a guest typing that order number is refused.
- [ ] Courier off on a date: no courier rates that date; a courier-only date shows "Available for courier on …" on product pages.
- [ ] Products list Events column shows "Holiday Pre-order (Dec 18)" for a limited product; product edit screen shows "Date limit: Holiday Pre-order: only Dec 18 (set on the event)". Events list shows "2 product exceptions".
- [ ] Event edit screen: the Publish box has a Duplicate button (not on a brand-new unsaved event); it copies the saved event like the list action.
- [ ] Event list: "Duplicate" creates a draft "{title} (copy)" with the same dates, sales window, zone rules, categories, individual products, exceptions (exclusions and date limits) and notes. Publishing nothing yet: the original still sells as before.
- [ ] Exclusions: link a category to an event, then add one of its products as a "Not in this event" exception and save. That product no longer shows the event's rates or availability note (and is unpurchasable if that was its only event); the rest of the category is unchanged. The product edit screen shows the event under "Excluded from events". Removing the exclusion there restores it. Picking the same event in both fields on a product keeps only the exclusion.
- [ ] Delete an event permanently: its dates and zone rules rows are gone (check the fe_ tables) and products and categories no longer list it.
- [ ] Product edit, General tab: "Fulfillment events" multiselect saves; category add and edit screens save the same field. The Events column of the products list names each event the product is in (excluded ones struck through) and adds "In 2 open events" when two are open; a product in no event shows a dash.
- [ ] Products list bulk edit: tick two events in the Events checklist with "Add to event": every selected product lists both.
- [ ] Products list bulk edit: select several products, Edit, Fulfillment event → "Add to event" + an event, Update: each now lists the event (its own link) and any exclusion of it is gone. "Exclude from event" strikes it through and the products lose that event's rates. "Remove from event (follow categories)" clears both, so a product whose category is in the event is back in it. "— No change —" leaves links alone; other bulk-edit fields still save. A shop manager can use it; the field doesn't appear for users without manage_woocommerce.
- [ ] Shop manager (manage_woocommerce without administrator) can open Events, Locations, Settings and save an event.

## M2: order split

- [ ] Analytics filter name: on the installed WooCommerce, confirm `woocommerce_analytics_excluded_order_statuses` is applied (Reports DataStore::get_excluded_report_order_statuses). After activation, WooCommerce > Settings > Analytics "Excluded statuses" lists Split.
- [ ] Staging with the Square sandbox: a cart with products from two events (two dates) and a regular product. Pay. The parent moves to "Split" with a "Split into #a, #b, #c" note; each child is Processing, has `created_via` = `fe-split`, `_fe_parent_id` = parent id (WooCommerce parent is empty), its own items, its shipping line and the `_fe_*` contract meta for its event (the regular child has only `_fe_parent_id`).
- [ ] The children's totals sum exactly to the amount Square charged (the parent total); a cent of rounding, if any, sits on the first child with a note.
- [ ] Fees and fee tax are split across children by item subtotal; a coupon on the parent shows as a note on each child and the line totals are the discounted ones.
- [ ] Stock is reduced once: product stock drops by the ordered quantities and does not drop again when the children move to Processing. Each child line item carries `_reduced_stock`; the parent's line items do not.
- [ ] Cancel one child: only that child's items are restocked. Cancel (or fully refund with "restock" ticked) the parent: no stock changes.
- [ ] An offline gateway (bank transfer, cheque) puts the parent on-hold: it splits immediately and the children are On hold. Moving the children to Processing reduces no stock.
- [ ] Emails at split (two-event order): the customer receives the split confirmation, listing every child with event, type, date, window, pickup location and items; no per-child processing or on-hold emails and no processing email for the parent. An on-hold parent (bank transfer, cheque) also sends its on-hold email with the payment instructions.
- [ ] Forced split failure that rolls back (for example fail child creation): the parent stays one order, gets `_fe_split_failed` = yes, the customer processing email (on-hold was already sent) and, with "per child" ticked, the staff new-order email are sent once for the parent; later status changes or payments do not split it again; deleting `_fe_split_failed` lets the next trigger retry.
- [ ] Partial split failure ("needs manual review" note): no email is sent automatically; contact the customer manually.
- [ ] Staff new-order emails: one per child (none for the parent) with "per child" ticked in Settings; with it unticked, exactly one for the whole order (the parent) and none per child.
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
- [ ] With "Defer transactional emails" on (`woocommerce_defer_transactional_emails` returning true), the split confirmation is still sent once.
- [ ] Bank transfer (BACS) two-event order: the customer receives the on-hold email with the bank details for the parent, plus the split confirmation; no on-hold email per child.
- [ ] Split children show no "Refund via gateway" button (no transaction id); `_fe_parent_transaction_id` holds the parent's id when the gateway set one.
- [ ] Staff add a product linked to a closed event to a manual order and the customer pays via the order-pay link: the order completes (no split/stamp surprise) and nothing blocks payment.
- [ ] Plain-text split confirmation: apostrophes and other entities in event names, locations and the additional content render as characters, not `&#039;`.
- [ ] A cart whose rates were shown yesterday for a date that has now passed: placing the order (classic and block) fails with "The date you chose for {event} has passed. Choose another date." and the rates list refreshes without that date.
- [ ] Orders with no `_fe_will_split` cause no `fe_split_lock_*` rows in wp_options on payment.

## M4: add-ons and polish

- [ ] Add Event add-on to the zone. Logged in as a customer with an open (processing/on-hold) order for an event, add that event's product: the rates list "Add to my order #N — {Type} · {date} · no extra fee" at 0 cost, only for that customer's own orders for this event (not completed or cancelled ones, not other customers', not orders that are themselves add-ons). Log in as another customer: none of the first customer's orders appear.
- [ ] As a guest, the same cart shows one rate "Add to an existing order" (cost 0) and never lists an order number. The order notes area shows "Adding to an existing order? Order number" in block and classic checkout.
- [ ] Guest picks the add-on rate and enters a valid order number with a different billing email: checkout is blocked with "We couldn't find an open order #N for {event} with this email." in blocks and classic; an empty number shows "Enter the number of the order you are adding to."; a number for another event or a completed order is blocked the same way. Typing in the field (before Place order) shows no error.
- [ ] Guest with the matching billing email (any letter case) places the order: the shipping line carries the target's fe_date, fe_type, fe_window, fe_location and fe_addon_of; the order carries `_fe_addon_of` = target id plus the target's `_fe_date`/`_fe_type`/`_fe_window`/`_fe_location`, and `_fe_addon_order_input`.
- [ ] Logged-in add-on order (single package): stamped with the target's fulfillment and `_fe_addon_of`; `fe_get_order_fulfillment()` returns addon_of.
- [ ] Mixed cart (add-on for one event plus a second event package): the order splits; the add-on child carries `_fe_addon_of` and the target's date/type; the other child has none.
- [ ] Block checkout: with WooCommerce's additional fields API, confirm the field value is stored on the order (WooCommerce's own `_wc_other/fulfillment-events/addon-order` meta) and that `_fe_addon_order_input` matches.
- [ ] Past-dated targets: a customer's open order whose event date was yesterday is not listed (logged in) and is rejected with the generic message (guest); an order dated today is still offered.
- [ ] Logged-in customer: put the add-on product in the cart and open checkout before placing the target order; place the target order; back at checkout with the same cart, "Add to my order #N" now appears.
- [ ] Guest types the number from the split confirmation email (the parent's number): the add-on resolves to the child for that event and the new order's `_fe_addon_of` is the child's id; a parent number whose children are all for other events is rejected with the generic message.
- [ ] WooCommerce 9.9+ block checkout: the order number field is hidden until "Add to an existing order" is selected, appears and is required when it is, and disappears again when another rate is picked. Confirms the `cart.shipping_rates` schema path and the `fe_addon:{instance}:guest` id match. A cart with no event product never shows it.
- [ ] WooCommerce 8.9–9.8 block checkout: the order number field is not shown on any checkout and the guest "Add to an existing order" rate is not offered (logged-in add-on rates still are); the classic checkout on the same site still offers the guest rate with its field. On 9.9+ the schema-valued `hidden`/`required` work as above (registration doesn't throw; check the log).
- [ ] Guest add-on whose order number can't be matched when the order is created (for example the target was cancelled in between): the new order gets the note "Add-on order number could not be matched; check this order."
- [ ] Order edit screen: the "Split order" box shows on split parents, children and stamped event orders, and not on orders with no `_fe_*` meta.
- [ ] Shipping lines of event orders show no `fe_*` keys (fe_event_id, fe_date, fe_addon_of, fe_addon_guest…) in the customer emails, My Account order view, thank-you page, or the order admin item list.

## M3: storefront, hint, REST, release

- [ ] Classic theme: a product in an active event shows "Available for delivery or pickup on {dates}" under the price; past dates are omitted; a product whose event is closed or not in any event shows no note.
- [ ] Block theme (for example Twenty Twenty-Five): the same note appears under the price on the single product page, and not on related-products price blocks or the shop archive.
- [ ] Create a physical product named "Sourdough workshop" in no event: the Fulfillment screens and Products list show the hint listing it (at most 10); "Dismiss this hint" hides it for that user only; another admin still sees it. Marking the product virtual or adding it to an event clears it after saving the product.
- [ ] While logged in as a shop manager, GET /wp-json/fe/v1/events and /wp-json/fe/v1/events/{id} return events and zone rules; logged out or as a customer both return 401/403; POST is rejected.
- [ ] Uninstall: with "Delete events, locations and zone rules" on, delete the plugin: the fe_ tables, event posts, `_fe_events` product and category meta, `fe_split_lock_*` options, the ticket-hint transient and the `fe_dismissed_ticket_hint` user meta are gone. With it off, nothing is removed.
- [ ] Load the .pot file in Loco Translate or Poedit and confirm strings appear.

## Orders entered by staff

- [ ] New order (legacy and HPOS): Add item(s) > Add shipping, pencil, choose an event, date, service, time and place, Save, then Create. The line is titled like checkout ("Delivery · Thu, Dec 10 · 9am–1pm") and the order has the `_fe_*` contract meta. Also choose the event and click Create without Save first.
- [ ] A draft event, a closed date and a past date can be chosen; the list says which they are.
- [ ] Edit an existing event order without touching its event and Update: nothing changes and no note is added. Change its date: title, meta and a note change. Choose "No event", or delete the line: the order leaves the event.
- [ ] A split parent, or an order waiting to be split, shows why its event can't be changed. A processing order (not editable) shows no fields.
