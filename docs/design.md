# Fulfillment Events for WooCommerce — design

Status: **draft** · 2026-11-18

A new, open-source WooCommerce plugin (its own repo) that lets a store sell
products tied to dated "fulfillment events" (pickup / delivery / courier on
specific dates), lets one cart span several events and dates, charges each
date's fee in one payment, and splits the paid order into one order per date.
Downstream apps (for example a delivery-routing app) consume the plugin's
documented order meta contract (§7).

---

## 1. Why

Stores that sell seasonal drops have fixed fulfillment dates. For example, one
set of items is pickup/delivery **Dec 3 only**; another is **Dec 10 or Dec 18**.
Without this plugin:

- A customer who wants both must **place two orders**; checkout can't hold two
  dates or two delivery fees.
- Every date x zone combination is a **hand-made shipping method** whose title
  carries the date ("Delivery in Zone A - December 18, 9am - 6pm"). A
  downstream app then has to guess the date from that title.
- Nothing ties a product to the dates it can be fulfilled on.
- "Add on to an existing order" is a shipping method that is reconciled by
  hand.

**Success looks like:** one checkout, one payment, one order per date that can
be completed independently (finish the Dec 3 order while the Dec 10 order
stays open); no per-date shipping methods to hand-build each season;
downstream apps read dates and fulfillment type as facts instead of parsing
titles.

## 2. Goals, non-goals, anti-goals

**Goals**

- Admin defines **fulfillment events** (one or more dates each) and attaches
  **products or product categories** to them, reusable year to year.
- Store **shipping zones** (stock WooCommerce, by ZIP) decide which
  fulfillment types are offered and at what price, with **zone defaults** and
  **per-event overrides**.
- Four fulfillment types: **Pickup** (free; per-date location, e.g. the shop
  or "the fair"), **Delivery** (fee), **Courier** (free; customer dispatches a
  courier on a delivery date), **Add-on** (attach to an existing open order).
- A cart spanning events checks out once; the paid order **splits into one
  order per fulfillment date**.
- Products needing no shipping (class tickets) and products in no event keep
  working exactly as stock WooCommerce does today.
- **Open-source ready:** generic, store-agnostic, translatable, documented
  extension points. No store-specific logic in the plugin.

**Non-goals (v1)**

- Per-date capacity limits / slot booking (stock quantity stays the only
  limit).
- Exact delivery time-slot selection (a date has a display window, not slots).
- Courier booking or tracking — the plugin records "courier on date X"; the
  coordination stays human.
- Recurring/rolling availability ("every Saturday") — events are explicit
  dates.
- Partial refunds across split orders automated through the gateway (see §6.6).

**Anti-goals (a failure even if it "works")**

- Checkout breaks or slows for ordinary carts. The plugin must be inert for
  carts with no event products.
- A customer is charged once but the store sees orders that don't add up to
  the payment, or stock is reduced twice.
- Reintroducing free-text parsing as a source of truth.
- Custom React checkout UI that has to be maintained against WooCommerce
  Blocks churn (see §5 — the design avoids it).

## 3. Constraints and findings

- The plugin must work with the **block Cart/Checkout** and the classic
  shortcode checkout.
- **One charge per checkout** through whatever payment gateway the store uses;
  refunds go through the order that holds the transaction.
- **Products may be re-created for each run of an event**, with earlier
  versions left published. Attaching by **category** is the primary path.
- **Stock may be small.** Splitting must never touch stock.
- **No-shipping products (such as class tickets)** must not demand shipping.
  Stock WooCommerce already skips shipping for virtual products; the fix is
  ticking **Virtual** on those products (see §6.8).
- Checkout code is the highest-risk code on a store, so a separate plugin
  lets it be rolled back independently of anything else, and testing on a
  staging site is required before production (§8).
- Admin UI is **WP-native** (no React in wp-admin).
- Target PHP 7.4+, WordPress 6.0+, WooCommerce 8.9+, HPOS-compatible.

## 4. Approaches considered

**A. Custom shipping methods + fulfillment events + split orders — chosen.**
Pickup / Delivery / Courier / Add-on become WooCommerce shipping method types
the admin adds to stock zones with a default price. Events supply dates.
Carts are split into one shipping package per event; each package offers
"method × date" rates; the paid order splits per date. Removes per-date
method building and gives downstream apps structured data.

**B. Stock Flat Rate / Local Pickup + a date picker.** Less code, but Courier
and Add-on become indistinguishable $0 flat rates, so downstream systems still
need a hand-maintained mapping — the fragility we're removing. Rejected.

**C. Third-party delivery-date / multi-package plugins + glue.** Fast to
start, but none split a paid order into per-date orders, and we'd be bound to
their data shapes. Rejected.

**Separate plugin vs. inside a downstream app's plugin:** separate repo.
Downstream apps consume the plugin's documented order meta contract.

## 5. Key idea: dates are shipping *rates*, not a custom widget

WooCommerce already supports **multiple shipping packages per cart**, each
with its own rate selection, in both block and classic checkout, all paid in
one charge. The plugin:

1. Splits the cart into **one package per event** (plus one "regular"
   package for shippable non-event products).
2. Has its shipping methods emit **one rate per offered date** for that
   package's event, e.g.

   ```
   Winter market - Dec 3
     ( ) Delivery · Wed Dec 3 · 9am–6pm ............ $9.00
     ( ) Pickup at the shop · Wed Dec 3 · 10am–2pm .. Free
     ( ) Courier pickup · Wed Dec 3 ................. Free
   Holiday pre-order
     ( ) Delivery · Wed Dec 10 · 9am–6pm ........... $9.00
     ( ) Delivery · Fri Dec 18 · 9am–6pm ........... $9.00
     ( ) Pickup at the fair · Sat Dec 13 ............ Free
     ( ) Add to my order #1001 (Delivery, Dec 10) ... Free
   ```

So the customer's "pick a date" *is* WooCommerce's native "pick a shipping
option" — no custom checkout UI, the same in block and classic checkout, and
the chosen rate carries its date/type/event as rate meta onto the order's
shipping line.

**Known limit (accepted):** block checkout's address-free "Pickup" toggle
only exists for core Local Pickup with a single package. Event Pickup rates
appear under "Ship", so the customer enters their address first (that's also
how zones are matched). The pickup rate label names the location ("Pickup at
the shop · …") so it's clear nothing is shipped there.

## 6. Design

### 6.1 Data model

- **Fulfillment event** — custom post type `fe_event` (title, status,
  `sales_open` / `sales_close` datetimes, optional description shown to
  customers).
- **Event dates** — table `{prefix}fe_event_dates`: `event_id`, `date`
  (Y-m-d), per type offered (`pickup|delivery`) a flag + display window text,
  `pickup_location_id`. **Courier** is offered on every date that offers
  pickup *or* delivery (no separate flag), subject to zone rules.
- **Pickup locations** — table `{prefix}fe_locations`: name, address,
  instructions. One location is the **default** (for example the
  shop); event dates use it unless set otherwise (e.g. a fair).
- **Zone rules** — table `{prefix}fe_zone_rules`: `event_id`, `zone_id`,
  `type`, `enabled` (inherit / on / off), `cost_override` (nullable).
  Missing row = inherit the zone's method default.
- **Product links** — product meta `_fe_events` (event IDs) and term meta
  `_fe_events` on `product_cat`. A product's events = its own ∪ its
  categories'.

### 6.2 Shipping methods (the "zone defaults")

Four `WC_Shipping_Method` subclasses the admin adds to stock zones:
**Event Delivery**, **Event Pickup**, **Event Courier**, **Event Add-on**.
Instance settings: default cost (Delivery; others default free but
configurable), default window text, label template. Absent from a zone =
that type isn't offered there (e.g. Zone C has no Event Delivery).

### 6.3 Admin UX (WP-native screens)

1. **WooCommerce → Settings → Shipping** (stock): create zones by ZIP, add the
   Event methods with each zone's default price.
2. **Fulfillment → Events → Add**: name, sales window, dates table (date,
   which types, windows, pickup location), and a **zone × type grid**
   pre-filled from zone defaults — admin only touches exceptions ("no Zone B
   delivery for the fair", "$25 Zone B on Dec 18").
3. **Attach products**: on the event screen pick categories/products; also a
   "Fulfillment events" box on the product edit screen and a field on the
   category edit screen.
4. **Fulfillment → Locations**: manage pickup locations.
5. "Duplicate" action: an exact draft copy (dates, sales window, rules,
   category and product links, exclusions) that staff then edit.
6. Exclusions: an event's Products box and the product edit screen can
   exclude a product from an event; an exclusion wins over every link.

### 6.4 Storefront and cart

- Product page shows an availability note ("Available for Dec 10 or Dec 18
  delivery/pickup") via a template hook / block-compatible filter.
- A product is **purchasable** only while at least one of its events is in
  its sales window (if it has events at all).
- **Cart grouping** (`woocommerce_cart_shipping_packages`):
  - Virtual / no-shipping items → no package (stock behavior).
  - Items with no event → one "regular" package, rated by the zone's
    ordinary (non-Event) methods exactly as today.
  - Items with events → one package per event. *If a product belongs to
    several open events, it joins the event with the earliest next date;*
    admin sees a warning badge on such products (builder's call, see §9).
- **Rate filtering** (`woocommerce_package_rates`): event packages show only
  Event-method rates; the regular package shows only non-Event rates.
- Package names come from the event title ("Winter market — Dec 3").
- If an event package ends up with **zero rates** for the customer's zone,
  checkout shows a clear notice ("Winter market items aren't available for delivery
  to 12345 — pickup and courier are") rather than WooCommerce's generic
  "no shipping options".

### 6.5 Add-on to an existing order

Event Add-on emits one rate per **eligible existing order**: same event,
status open (processing/on-hold), not itself an add-on, and
- the customer is **logged in** and owns it → listed automatically, **or**
- (guest) a single generic rate "Add to an existing order" is shown; choosing
  it requires the optional checkout field "Adding to an existing order? Order
  number" (Additional Checkout Fields API in blocks; a classic field
  otherwise), validated **at place-order** against the billing email and the
  package's event. Guests are never shown orders they didn't name, and rates
  never depend on the email (avoids WooCommerce's package-hash rate cache).
  The blocks field is conditional (hidden until the guest rate is picked) and
  needs WooCommerce 9.9; below 9.9 it is not registered and the guest rate is
  only offered to the classic checkout. An order number that can't be matched
  when the order is created leaves a note on the order for staff.

The customer ID is added to the package so logged-in rates recalculate per
customer. Label shows only order number, type and date. The new order inherits the
target's date/type/location, costs nothing to ship, and records
`_fe_addon_of`. Downstream systems treat it as the same stop.

### 6.6 Splitting the paid order

**Split key = shipping package.** Each event package becomes one child; the
"regular" (non-event, shippable) package, if present, becomes its own child
with no `_fe_*` meta (downstream falls back to its own handling). Virtual
items ride on the first child.

**Same date, same type, two packages** (rare: two events sharing a date): still
two child orders (downstream consolidates the stop by address), but the
customer pays the fee once — a cart-level negative fee "Combined delivery"
cancels the duplicate. *(Builder's call; keeps native per-package rates.)*

**Timing.** Whether an order will split is known at order creation (number of
packages). For such orders:
- the stock customer "processing" email is suppressed
  (`woocommerce_email_enabled_customer_processing_order`) because
  `payment_complete()` sends it *before* `woocommerce_payment_complete` fires.
  The parent's "on-hold" email is **not** suppressed: it carries the payment
  instructions of offline gateways (bank transfer, cheque). Children send
  neither;
- the split runs on `woocommerce_payment_complete` (and on transition to
  `processing`/`on-hold` for offline gateways) **in the same request**,
  idempotent via `_fe_split_done`; the parent moves straight to Split. The
  order is read and checked before the per-order lock is taken, so orders that
  won't split cost nothing;
- the plugin then sends its own confirmation email (a static listener on
  `fe_order_split` loads the mailer, so it also works with deferred
  transactional emails);
- a split that fails before any child leaves pending is rolled back, the
  parent stays one order with `_fe_split_failed` = `yes`, and the suppressed
  emails are sent once. It is not split again until staff delete that key.

Rules:

- **≤ 1 fulfillment group → no split.** The order is just stamped with the
  fulfillment meta (§7). This is the common case; stock emails untouched.
- **≥ 2 groups →**
  - Create one **child order** per group (linked by `_fe_parent_id` meta —
    *not* WooCommerce `parent_id`, which Analytics treats as a refund —
    `created_via` = `fe-split`): copies customer, addresses, the group's line
    items at their paid (post-discount) totals, its shipping line, and
    pro-rated fees and tax by item subtotal. Rounding remainder goes to the
    first child so children always sum to the parent total.
  - **Stock:** set children's stock-reduced state through the order API
    (`set_order_stock_reduced`, HPOS-safe) and copy each item's
    `_reduced_stock` meta so cancelling/refunding a *child* restores its own
    stock; strip `_reduced_stock` from the parent's items so cancelling the
    parent can't restore stock a second time.
  - Staff "new order" emails fire per child, and not for the parent. With
    that setting off, the parent's "new order" email is sent once for the
    whole order instead, and none per child.
  - Children do **not** copy the parent's transaction id (a gateway refund
    from a child would refund the shared charge); it is kept for reference
    as `_fe_parent_transaction_id`.
  - Parent moves to a new status **"Split"** (`wc-fe-split`). It **keeps its
    line items** (it is the payment record and the refund anchor for the payment gateway)
    and the Split status is **excluded from WooCommerce Analytics and
    reports**; children carry the revenue. Never "completed" itself.
  - Customer gets **one** confirmation (plugin-sent), items grouped by date,
    each child's order number listed.
  - Each child is completed independently (sends the normal "completed"
    email for that date).
- **Refunds** are issued on the **parent** (it holds the gateway
  transaction). v1 adds a "Refund on parent" link on child orders and an
  order note on both; automatic child↔parent refund mirroring is a non-goal.
- Admin order list shows "part of #1001" on children and "split into
  #1002, #1003" on the parent.
- Downstream apps that list `processing` orders must skip any order with
  `_fe_split_done` (belt-and-braces for the brief window before Split).

### 6.7 Extension points (open source)

- Actions: `fe_order_split( $parent, $children )`, `fe_order_stamped( $order )`.
- Filters: `fe_product_events`, `fe_package_rates_for_event`,
  `fe_rate_label`, `fe_should_split_order`, `fe_child_order_args`.
- Helper: `fe_get_order_fulfillment( $order ) : ?array` (public, documented).
- REST (read-only, `manage_woocommerce`): `fe/v1/events`,
  `fe/v1/events/{id}/dates`.

### 6.8 Class tickets / no-shipping products

No plugin logic: WooCommerce skips shipping for **virtual** products. Action
tick *Virtual* on products such as class tickets. The plugin adds a
dismissible admin notice listing published products in no event, with
"class"/"ticket"-like names, that aren't virtual — a generic, opt-out "possible
no-shipping product" hint.

### 6.9 Open-source hygiene

GPL-2.0-or-later; name **"Fulfillment Events for WooCommerce"** (confirmed),
slug `fulfillment-events-for-woocommerce`, prefix `fe_`. **Support CTA:** a
"Support this plugin" link to `https://buymeacoffee.com/digitalducktape` on
the plugin's settings screen (and its Plugins-list row meta), and a support
section in the repo README. Text domain + `.pot`; WordPress coding
standards (PHPCS); HPOS + Cart/Checkout-blocks compatibility declared;
`uninstall.php` honoring a "delete data" setting; README in wp.org format; no
references to any specific store, zone names, or downstream app.

## 7. Order meta contract (what downstream apps read)

Written on every order that has a fulfillment (unsplit orders and each child):

| Meta key | Value |
|---|---|
| `_fe_event_id` | event post ID |
| `_fe_event_name` | event title snapshot |
| `_fe_date` | `Y-m-d` |
| `_fe_type` | `delivery` \| `pickup` \| `courier` |
| `_fe_window` | display window, e.g. `9am - 6pm` (may be empty) |
| `_fe_location` | pickup location name + address snapshot (pickup only) |
| `_fe_addon_of` | target order ID (add-on orders; `_fe_type`/`_fe_date` are copied from the target) |
| `_fe_parent_id` | parent order ID (split children) |
| `_fe_contract` | `1` — contract version |

Snapshots (not IDs alone) so historical orders stay readable if an event is
edited or deleted.

Internal, not part of the contract: `_fe_will_split`, `_fe_split_done`,
`_fe_split_failed`, `_fe_children` (parent), `_fe_parent_transaction_id`
(children).

## 8. Testing and rollout

- **Unit (Docker, `bin/test.sh`)**: cart grouping, rate building per
  zone/event/override, event resolution for multi-event products, split math
  (pro-ration, rounding sums to parent), add-on eligibility, meta contract.
- **Integration**: a local WooCommerce site (wp-env / Docker) with block
  checkout and a test payment gateway; a scripted checklist
  (`docs/testing-checklist.md`) covering single-event cart, two-event cart,
  event + regular + virtual cart, add-on (logged-in and guest), refund on
  parent, classic checkout.
- **Staging first**: run the full checklist on a staging site with the real
  zones and the payment gateway's sandbox before production.

**Milestones**

| # | What | Gate |
|---|---|---|
| M1 | Plugin core: events, dates, locations, zone rules, product/category links, four methods, cart grouping + rate filtering, meta stamping (no split yet) | unit tests + staging checkout of single-event carts |
| M2 | Order split, emails, stock handling, Split status, Analytics exclusion | staging: two-event cart, cancel child, refund parent |
| M4 | Add-on (logged-in + guest order-number), duplicate-event, product availability note, no-shipping hint, support CTA polish | staging |

**Going live (suggested):**
1. Upload the plugin (deactivated).
2. Create events, attach categories, and add the Event methods to zones with
   default prices.
3. Activate the plugin and **remove any existing per-date shipping methods
   from the zones** in the same sitting. Event packages already filter them out, but
   carts with non-event products would still be offered them.
4. Place one real test order, check it in any downstream app, refund it.

Orders placed before the plugin was activated keep their original shipping
methods. Rollback = deactivate the plugin and re-enable the earlier methods;
orders already placed keep their `_fe_*` meta.

## 9. Design calls

- One shipping fee **per date** (two delivery dates = two fees).
- Multi-event product → joins the event with the **earliest next date**.
- Split = **one child order per event group**; a non-event shippable group is
  its own child; duplicate same-date fees are credited back.
- Customer gets **one** confirmation email (stock processing email suppressed
  for split orders); per-child completion emails.
- Parent keeps its items as the payment/refund record; **Split status is
  excluded from Analytics** so revenue isn't double-counted.
- Refunds live on the **parent** order; no automatic mirroring.
- Guest add-on requires **typing the order number** (+ billing email match);
  only logged-in customers get a list.
- Class tickets handled by ticking **Virtual** (no plugin feature).
- Products are purchasable only during an event's **sales window**.
