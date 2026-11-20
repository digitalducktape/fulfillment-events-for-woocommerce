# One-screen event editor, per-date services and product date limits

Status: draft for approval · Target: plugin 0.5.0 · Date: 2026-11-19

## Why

Setting up an event takes four boxes (Details, Dates, Zones grid, Products)
plus notes, and the hardest one, the Zones grid, exists for something the
bakery rarely does (change a price per event). What the bakery actually does:

1. **Turns services off on certain days.** "No East Bay on Dec 10", "no
   courier on Dec 18". Prices come from the WooCommerce zone settings.
2. **Limits a product to certain dates of an event.** The 2026 holiday
   cookie box ships only on Dec 18, while the rest of the holiday products
   ship Dec 10 or Dec 18. Today this can't be said, so the store runs two
   holiday events and excludes the cookie box from one.
3. **Pulls individual products out of an event** their category brings them
   into. Hanukkah items sit in "Winter collection" but belong to the
   Hanukkah event, not the December pickup dates. (Exclusions exist since
   0.4.1; they move onto the new screen.)

A handful of exceptions per season, not dozens.

**Success:** one "Holiday Pre-order 2026" event replaces the two, and the
whole event can be checked on one screen, top to bottom.

## Goals

- One event screen, read in order: sales window → dates (what runs each
  day) → products (categories + exceptions) → notes.
- Per date: delivery (time, zones), pickup (time, place), courier (zones),
  each switched on or off.
- Per product, per event: "not in this event" or "only on these dates".
- Checkout: an item limited to some dates gets its own shipping choice,
  offering only its dates (user's decision, see Approaches).
- Price changes per event remain possible behind a small link.

## Non-goals

- No product-side editor for date limits (a handful of exceptions → the
  event screen is the place). The product edit screen shows a read-only
  line.
- No change to the order meta contract (`_fe_*`), splitting, emails or City
  Guy Delivers. The chosen rate still carries one event and one date.
- No new pricing features.

## Anti-goals

- Losing anything already configured on staging/production events (dates,
  windows, locations, price overrides, exclusions, links).
- A screen where the same fact can be set in two places that disagree.
- Making ordinary single-date events harder than they are now.

## Approaches considered

- **A. One event screen in reading order (chosen).** The Zones grid goes
  away; each date says what runs; product exceptions sit next to the
  categories; price overrides behind a link. Biggest change, matches how
  the bakery thinks about an event.
- B. Keep the boxes, trim each (grid → on/off checkboxes, exclusions gain
  dates). Less work, still four boxes to bounce between.
- C. Exceptions set from the product edit screen. Suits dozens of
  exceptions; with a handful it scatters what needs to be seen together.

Checkout for a mixed cart (cookie box Dec 18 only + cookies Dec 10/18 from
the same event):

- **Two shipping choices (chosen by the user).** The limited item gets its
  own section offering only its dates; the rest keep all dates. If the
  customer picks the same date for both, the existing split + "Combined
  delivery" credit apply.
- One shipping choice limited to shared dates (rejected).

## The design

### Screen

The event edit screen keeps WordPress's title and Publish box (with the
Duplicate button) and the Internal notes side box. The main column has one
meta box, "Event setup", with three headed sections:

```
When it sells   Opens [Nov 1, 9:00]   Closes [Dec 16, 17:00]

Dates
  Thu, Dec 10                                                     🗑
    ☑ Delivery  [10am to 2pm]   to ☑ SF ☑ Marin
    ☐ Pickup    [          ]    at [Shop ▾]
    ☑ Courier                   to ☑ SF ☑ Marin ☐ East Bay ☐ Bay Area
  Fri, Dec 18                                                     🗑
    ☑ Delivery  [10am to 2pm]   to ☑ SF ☑ Marin
    ☑ Pickup    [11am to 4pm]   at [Holiday Fair ▾]
    ☑ Courier                   to ☑ SF ☑ Marin ☑ East Bay ☑ Bay Area
  [Add date]

Products
  Categories   [Winter collection ×] [Holiday ×]
  Products     [Holiday cookie box ×]          (individual additions)
  Exceptions
    Holiday cookie box   (•) Only on ☐ Dec 10 ☑ Dec 18   ( ) Not in this event   🗑
    Hanukkah gelt box    ( ) Only on …                   (•) Not in this event   🗑
    [Search for a product to add an exception]

Change a price for this event (rarely needed) ▸
    SF · Delivery · All dates · [$25.00]   🗑
    [Add a price change]
```

- **Zone lists** show only zones that have that Event method (delivery
  list: zones with Event delivery; courier list: zones with Event courier).
  A zone with no such method isn't shown. New dates start with every zone
  ticked.
- **Pickup has no zone list.** Pickup is offered to every zone that has the
  Event pickup method; turning pickup off for the date turns it off for all.
  (My call: "no East Bay" means nothing travels to East Bay; customers can
  still come to the shop. East Bay today has pickup + courier, no delivery,
  so "no East Bay on Dec 10" = untick East Bay under courier.)
- If the event has no dates yet, Dates shows only [Add date]. Dates and
  zone ticks for a newly added date work before saving (no "after you
  save" caveat): the zone checkboxes come from a template rendered with
  every zone.
- A link "Edit shipping zones and their prices" sits under Dates.
- The Products "Exceptions" row for a product that also isn't in the event
  by category or direct link: "Only on" adds it to the event for those dates
  (so the cookie box can be added by exception alone). "Not in this event"
  is the existing exclusion.
- Validation shown after save (as a notice): a product limited to dates
  that no longer exist in the event, or to no dates at all.

### Data

Kept as-is: post title/status, `_fe_sales_open/close`, the dates table
(date, delivery, pickup, windows, location_id), zone rules table, product
links (`_fe_events` on products and product_cat terms), exclusions
(`_fe_excluded_events`), `_fe_notes`.

- **Per-date zone ticks are zone rules.** An unticked zone under delivery on
  Dec 10 = rule {zone, delivery, 2026-12-10, off}. Unticked courier zone
  likewise. Pickup off for a date is already the dates table's `pickup`
  flag. No schema change for rules.
- **Save is scoped** (my call, protects data): the ticks rewrite only rules
  for (zones shown on the screen) × (delivery, courier) × (the event's
  dates). Every other rule — zones without the Event method, pickup rules,
  dates no longer in the event, legacy `inherit` rows — is carried through
  unchanged. Price rows (`on` with a cost, event-wide or for one date) come
  from the price list, which supports "All dates" or a single date.
- **Tick vs. price collision** (same zone, type, date): off wins; the price
  row for that date is dropped and a notice after save says so (my call;
  the table's UNIQUE key allows only one).
- **Courier on/off per date.** New boolean `courier` on a date row,
  default true; stored in the dates table (DB version bump with a column
  default of 1, so existing dates keep courier). A date needs at least one
  of delivery/pickup/courier to be saved (was: delivery or pickup).
  Courier currently requires delivery or pickup on the date; it becomes its
  own flag.
- **Existing event-wide off rules** (date NULL, mode off) render as that
  zone unticked on every date, and are converted to per-date rules on the
  next save. Event-wide `on` rules with a cost show in the price list.
  Pickup zone rules (any) are kept and shown in the price list as-is; the
  new UI doesn't create pickup zone rules.
- **Product date limits:** stored on the event, post meta `_fe_date_limits`,
  an array `product_id => [Y-m-d, …]`. A product without an entry gets all
  of the event's dates. Saved from the event screen's Exceptions; the event
  owns it, so no product rows are scanned or rewritten. (Planning change:
  the draft put this on each product; on the event it needs no LIKE scans,
  Duplicate copies it with the event, and deleting the event removes it.)
  Variations use the parent's limits (as links do).
- **One owner per fact** (my call): the Products row (categories + individual
  products) is the only thing that links a product to the event
  (`_fe_events`). Exceptions only own limits and exclusions. If an "Only on"
  exception names a product that isn't in the event, save adds it to the
  individual products list (visible there next time); deleting the
  exception later leaves that link alone. A product can't be both listed
  under individual products and "Not in this event": save keeps the
  exclusion and drops the link, as 0.4.1 does today.
- Duplicate copies `_fe_date_limits` with the rest of the event. Deleting an
  event or uninstalling removes it with the event post.

### Cart and checkout

- A product's open events become **(event, allowed dates)** pairs: allowed
  = the event's dates ∩ the product's limit for it, minus past dates. An
  event with no allowed dates left doesn't count as open for that product
  (status/closed logic follows, so the cookie box becomes unpurchasable
  once Dec 18 passes, and the "Available on" note lists only Dec 18).
- Grouping key includes limits: items with the same events and same
  allowed dates share a package; the cookie box gets its own. Group/line
  keys stay identical between Fe_Cart_Grouper and Fe_Order_Stamper (the
  0.4.4 rule).
- Package carries the allowed dates per event; Fe_Shipping_Method skips
  rate specs whose date isn't allowed.
- **Add-ons respect date limits** (my call: otherwise the limit leaks). A
  target order is eligible only if its date is one of the package's
  allowed dates for the target's event. Applies to the logged-in rate list,
  guest place-order validation and guest hydration (Fe_Addon_Rules gains
  an allowed-dates argument; the guest rate meta carries the allowed dates
  like it carries `fe_event_ids`).
- A **courier-only date** is a real date: it counts for open/closed status,
  the product note ("Available for courier on Dec 18" when only courier is
  left), the events list and emails (my call).
- Same date picked in two packages that differ only by allowed dates: the
  existing split + "Combined delivery" credit apply unchanged; a test pins
  it.
- Package heading for a limited package: "{event} · {product name}" when
  it holds one product, else "{event} · {dates}" (builder's call on exact
  copy; must tell the two sections apart).
- Per-date zone ticks need nothing new at checkout: they're zone rules the
  rate builder already applies.

### Product screens

- Product edit screen: the existing "Fulfillment events" / "Excluded from
  events" fields stay. Below them, read-only: "Holiday Pre-order 2026: only
  Dec 18 (set on the event)" with a link to the event.
- Products list Events column: an event with a date limit reads
  "Holiday Pre-order 2026 (Dec 18)".
- Bulk edit unchanged.

### Events list

The Dates column (0.4.5) stays dates and what they offer, plus
"2 product exceptions" when the event has any.

## Constraints

- WP-native admin, no React (house rule). PHP-rendered markup, the existing
  `assets/admin.css` / `admin.js`, WooCommerce's `wc-product-search`.
- Store-agnostic: zone names come from WooCommerce; no City Gal Bakes
  specifics in code.
- Upgrade in place: 0.4.x events must open and save without losing data;
  tests pin the conversions above.
- Tests stay pure where the logic is (allowed-date math, grouping keys,
  rule ↔ tick conversion).

## Left to the builder

- Exact copy, spacing, and section headings beyond the sketch.
- Whether "Event setup" is one meta box or three stacked ones without the
  WordPress drag handles (must read as one flow).
- How the price list rows are edited (inline row vs. small form).
