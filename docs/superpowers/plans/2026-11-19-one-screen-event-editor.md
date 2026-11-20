# One-screen Event Editor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the four-box event editor with one "Event setup" screen where each date says what runs (delivery/pickup/courier, which zones), products can be limited to some of an event's dates or excluded, and prices are a rarely-used list, shipping as plugin 0.5.0.

**Architecture:** Two new stored facts: a `courier` flag on each date row, and `_fe_date_limits` (product id => dates) on the event post. Zone on/off ticks are plain zone rules (`off` per date), converted by a new pure class `Fe_Event_Setup`. In the cart, a product resolves to *offers* (event id + allowed dates or null); the package/line-item group key encodes them so limited items get their own shipping choice.

**Tech Stack:** PHP 7.4+, WordPress/WooCommerce plugin APIs, PHPUnit (`FE_USE_LOCAL_PHP=1 bin/test.sh`), WPCS (`vendor/bin/phpcs`), plain JS/CSS in `assets/`.

**Spec:** `docs/superpowers/specs/2026-11-19-one-screen-event-editor-design.md`

## Global Constraints

- WP-native admin, no React. PHP-rendered markup, `assets/admin.css`, `assets/admin.js`, WooCommerce `wc-product-search` / `wc-enhanced-select`.
- Store-agnostic: zone names come from WooCommerce; no City Gal Bakes specifics in code or copy.
- Order meta contract (`_fe_*`), splitting, emails and City Guy Delivers are unchanged. A chosen rate still carries one event and one date.
- Upgrade in place: 0.4.x events open and save without losing dates, windows, locations, price overrides, exclusions or links.
- Pure logic stays in classes loadable under `FE_TESTING` (guard `if ( ! defined( 'ABSPATH' ) && ! defined( 'FE_TESTING' ) )`), registered in `tests/bootstrap.php`.
- Line-item group keys (`Fe_Order_Stamper::line_group`) must equal package group keys (`Fe_Cart_Grouper`) for the same product; both call one key function.
- Text domain `fulfillment-events-for-woocommerce`; sentence case copy; every user-visible string translatable.
- Each commit passes `FE_USE_LOCAL_PHP=1 bin/test.sh` and `vendor/bin/phpcs -q -n` on the touched files. Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Version: `FE_VERSION` 0.5.0 (plugin header, constant, readme Stable tag, changelog) in the final task only; `FE_DB_VERSION` 2 in Task 1.

## Review Focus

1. **Upgrading an event with an event-wide `off` rule** (e.g. courier off in East Bay for the whole event): the screen shows East Bay unticked under courier on every date, and saving without changes keeps East Bay off. Pinned in Task 5.
2. **Removing a date that a product is limited to:** the limit loses that date; a product left with no dates is unpurchasable for that event (not an error), and a notice says so. Pinned in Tasks 2 and 5.
3. **All zones unticked for a type on a date** must post as "all off", while a row whose zone checkboxes never rendered must mean "all on". Pinned in Task 5 (the `zones_posted` marker).
4. **Variation of a date-limited product** uses the parent's limit at checkout and in the line-item group key. Pinned in Task 3.
5. **Mixed cart, both packages pick the same delivery date:** the two packages have different group keys and the "Combined delivery" credit still applies. Pinned in Task 3.

---

### Task 1: Courier flag on dates

**Files:**
- Modify: `includes/class-fe-activator.php` (dates table), `fulfillment-events-for-woocommerce.php` (`FE_DB_VERSION` 2)
- Modify: `includes/data/class-fe-event-repo.php` (`normalize_dates`, `dates_for_events`, `save_dates`)
- Modify: `includes/domain/class-fe-rate-builder.php` (courier branch)
- Modify: `includes/wc/class-fe-cart.php` (`availability_note`, `merged_dates`)
- Modify: `includes/admin/class-fe-event-editor.php` (`offer_text`)
- Test: `tests/EventRepoTest.php` (or the existing normalize_dates test file), `tests/RateBuilderTest.php`, `tests/AvailabilityNoteTest.php`, `tests/ZoneGridTest.php`

**Interfaces:**
- Produces: date rows gain `courier` (bool). `Fe_Event_Repo::normalize_dates()` keeps a row when any of delivery/pickup/courier is true; `courier` is `! empty( $row['courier'] )` (absent = false, form semantics). Event arrays from `get()`/`open_events()` carry `courier`.
- Produces: `Fe_Rate_Builder::build()` offers courier on a date when `array_key_exists( 'courier', $row ) ? ! empty( $row['courier'] ) : ( $delivery || $pickup )`.

- [ ] **Step 1: Write failing tests**
  - `test_courier_only_row_is_kept_and_neither_is_dropped`: `normalize_dates([['date'=>'2026-12-18','courier'=>1],['date'=>'2026-12-19']])` returns one row with `courier === true`, `delivery === false`, `pickup === false`.
  - `test_courier_follows_its_own_flag`: build courier specs for dates `[{date 12-10, delivery:true, courier:false}, {date 12-18, courier:true}]` → only `2026-12-18`; a row without the `courier` key and `delivery:true` still yields courier (legacy).
  - `test_courier_only_note`: `availability_note(['dates'=>[['date'=>'2026-12-18','courier'=>true]]], '2026-12-01')` === `'Available for courier on Dec 18'`; a date with delivery plus courier still reads `'Available for delivery on Dec 18'`.
  - `merged_dates` carries `courier` (OR across events).
  - `offer_text(['courier'=>true])` === `'courier'`; `offer_text(['delivery'=>true,'courier'=>true])` === `'delivery and courier'`; delivery+pickup+courier === `'delivery, pickup and courier'`.
- [ ] **Step 2: Run** `FE_USE_LOCAL_PHP=1 bin/test.sh` — expect the new tests to FAIL.
- [ ] **Step 3: Implement.** Schema: `courier TINYINT(1) NOT NULL DEFAULT 1` after `pickup_window` (dbDelta adds it; existing rows get 1). `FE_DB_VERSION` → 2. Select/insert the column. Note text: courier counts toward "has a date" and is listed as a type only when no date offers delivery or pickup.
- [ ] **Step 4: Run tests** — all PASS.
- [ ] **Step 5: Commit** `Dates: courier on/off per date (DB v2)`.

### Task 2: Product date limits stored on the event

**Files:**
- Modify: `includes/data/class-fe-event-repo.php` (`shape`, new `META_LIMITS`, `save_date_limits`, `clean_limits`)
- Modify: `includes/admin/class-fe-event-editor.php` (`handle_duplicate` copies limits)
- Test: `tests/EventRepoTest.php`

**Interfaces:**
- Produces: `Fe_Event_Repo::META_LIMITS = '_fe_date_limits'`.
- Produces: event arrays carry `date_limits` => `array<int,string[]>` (product id => sorted Y-m-d list; may be empty list).
- Produces: `Fe_Event_Repo::clean_limits( array $raw, array $event_dates ): array{limits: array<int,string[]>, emptied: int[]}` — pure; keeps only valid dates that are event dates, sorted, deduped; product ids > 0; `emptied` lists products whose limit lost every date (they stay with `[]`).
- Produces: `Fe_Event_Repo::save_date_limits( int $event_id, array $limits ): void` (stores `[]`-free array; deletes meta when empty).

- [ ] **Step 1: Failing tests**
  - `test_clean_limits_drops_non_event_dates_and_reports_emptied`: raw `[12 => ['2026-12-18','2026-12-18','bad'], 13 => ['2026-12-01'], 0 => ['2026-12-10']]`, event dates `['2026-12-10','2026-12-18']` → limits `[12 => ['2026-12-18'], 13 => []]`, emptied `[13]`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.** `shape()` reads the meta (non-array → `[]`). Duplicate: `save_date_limits( $new_id, $event['date_limits'] )`.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `Events store per-product date limits`.

### Task 3: Cart resolves products to offers (event + allowed dates)

**Files:**
- Modify: `includes/domain/class-fe-event-resolver.php` (new `open_offers`)
- Modify: `includes/domain/class-fe-cart-grouper.php` (offers, `key()`)
- Modify: `includes/wc/class-fe-cart.php` (`product_event_status`, `status_for_shipping`, `packages`, `build_packages`, `package_event_ids`, new `package_allowed_dates`, `product_note_html`, `package_name`)
- Modify: `includes/wc/class-fe-shipping-method.php` (skip disallowed dates)
- Modify: `includes/wc/class-fe-order-stamper.php` (`line_group`, `tag_line_item`)
- Test: `tests/EventResolverTest.php`, `tests/CartGrouperTest.php`, `tests/CartPackagesTest.php`, `tests/OrderStamperTest.php`, `tests/AvailabilityNoteTest.php`, `tests/FeeDedupeTest.php` (or wherever `Fe_Fee_Dedupe` is tested)

**Interfaces:**
- Consumes: `date_limits` on events (Task 2), `courier` on dates (Task 1).
- Produces: `Fe_Event_Resolver::open_offers( array $product_event_ids, array $open_events, string $today, int $product_id ): array` → list of `['event_id' => int, 'dates' => string[]|null]`, soonest next allowed date first (ties by id). `dates` is null when the event has no limit for the product, else the limit ∩ event dates ≥ today. An event with no date ≥ today (after the limit) is left out. `open_ids()` and `resolve()` keep their signatures and results for unlimited products (implement them on top of `open_offers` with `$product_id = 0`).
- Produces: `Fe_Cart_Grouper::key( array $offers ): string` → `'event:'` + offers joined by `,`, each `"{id}"` or `"{id}@{d1}+{d2}"` when dates non-null; `''` for no offers. Groups carry `offers` alongside `event_id`/`event_ids`. Items may pass `offers` (new) or the old `event_ids`/`event_id`.
- Produces: `product_event_status()` adds `offers`; `status_for_shipping()` non-shipping result adds `'offers' => array()`.
- Produces: packages carry `fe_offers` (the group's offers); `Fe_Cart::package_allowed_dates( array $package, int $event_id ): ?string[]` (null = all).
- Produces: `Fe_Order_Stamper::line_group( bool $needs_shipping, array|int|null $offers_or_ids ): string` uses `Fe_Cart_Grouper::key()` when given offers; old int/int[] forms still produce `event:3` / `event:3,8`.

- [ ] **Step 1: Failing tests**
  - `test_open_offers_apply_the_products_limit`: events 7 (dates 12-10, 12-18, limits `[42 => ['2026-12-18']]`) → for product 42 today 12-01: `[['event_id'=>7,'dates'=>['2026-12-18']]]`; product 41: `[['event_id'=>7,'dates'=>null]]`; product 42 today 12-19: `[]`; a limit of `[]`: `[]`.
  - `test_limited_item_gets_its_own_group`: items a (offers `[7,null]`), b (offers `[7,['2026-12-18']]`) → two groups, keys `event:7` and `event:7@2026-12-18`.
  - `test_line_group_matches_package_key` for a limited offer list (Review Focus 4: build the line group from the *parent* id's status; assert equality with the grouper key).
  - `CartPackagesTest`: callback returning offers → package `fe_offers` set, `package_allowed_dates($pkg, 7)` === `['2026-12-18']`; heading for a limited single-product package is `"Event 7 · {product name}"` (stub product exposes `get_name()`), multi-product limited package `"Event 7 · Dec 18"`.
  - `test_same_date_in_two_packages_is_credited` (Review Focus 5): `Fe_Fee_Dedupe::credit` with lines `{group:'event:7', delivery, 2026-12-18, 900}` and `{group:'event:7@2026-12-18', delivery, 2026-12-18, 900}` → 900.
  - `availability_note` path: `merged_dates` called on events trimmed to allowed dates gives `'… on Dec 18'` for product 42.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.** `packages()` callback returns `$st['offers']`. `build_packages` accepts offers lists (array of arrays with `event_id`) besides the 0.4.4 forms. `Fe_Shipping_Method::calculate_shipping` drops specs whose `date` isn't in `package_allowed_dates` when non-null. `product_note_html` trims each event's dates to its offer before `merged_dates`. `tag_line_item` passes `offers`.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `Checkout: date-limited products get their own shipping choice`.

### Task 4: Add-ons respect date limits

**Files:**
- Modify: `includes/domain/class-fe-addon-rules.php` (`eligible`, `resolve_guest`, `guest_error`, new `meta_offers`)
- Modify: `includes/wc/class-fe-method-addon.php`, `includes/wc/class-fe-addon-field.php` (hydrate, order_rate_metas)
- Modify: `includes/wc/class-fe-order-stamper.php` (`item_meta_keys` hides `fe_offers`)
- Test: `tests/AddonRulesTest.php`

**Interfaces:**
- Consumes: `fe_offers` on packages (Task 3).
- Produces: `Fe_Addon_Rules::eligible( array $target, $event_id, $billing_email, $customer_id, $today = '', $allowed_dates = null ): bool` — when `$allowed_dates` is an array, the target's `date` must be in it.
- Produces: `Fe_Addon_Rules::resolve_guest( $input, $offers, ... )` where `$offers` is an offers list, an int[] or an int (0.4.4 forms keep working, meaning all dates).
- Produces: guest rate meta `fe_offers` = `wp_json_encode`-style JSON string of the offers list; `Fe_Addon_Rules::meta_offers( array $meta ): array` parses it, falling back to `fe_event_ids` / `fe_event_id` with `dates => null`.

- [ ] **Step 1: Failing tests**
  - `test_dec_18_only_item_cannot_join_a_dec_10_order`: target `{event_id 7, date 2026-12-10, processing, customer 3}` → `eligible($t, 7, '', 3, '2026-12-01', ['2026-12-18'])` false; with `null` true; with `['2026-12-10']` true.
  - `test_guest_resolve_uses_offer_dates`: lookup returns that target for #42 with matching email → `resolve_guest('42', [['event_id'=>7,'dates'=>['2026-12-18']]], …)` === 0; with dates null === 42.
  - `meta_offers(['fe_offers'=>'[{"event_id":7,"dates":["2026-12-18"]}]'])` and the `fe_event_ids` fallback.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.** Logged-in rate list: an order is eligible if any offer accepts it. JSON via `json_encode` in pure code (no WP dependency).
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `Add-ons respect product date limits`.

### Task 5: Pure setup conversions (`Fe_Event_Setup`)

**Files:**
- Create: `includes/domain/class-fe-event-setup.php`; register in `fulfillment-events-for-woocommerce.php` loader and `tests/bootstrap.php`
- Test: `tests/EventSetupTest.php`

**Interfaces:**
- Consumes: `Fe_Zone_Rule_Repo::normalize_rules()`, `Fe_Event_Repo::clean_limits()` (Task 2).
- Produces (all pure, `$zones_by_type` = `['delivery' => int[], 'courier' => int[]]`, the zones shown on screen):
  - `ticks_from_rules( array $rules, array $dates, array $zones_by_type ): array` → `[date][type][zone_id] => bool` (true = runs). A zone is false on a date when a date rule says `off`, or no date rule exists and an event-wide rule says `off`.
  - `ticks_from_post( array $date_rows, array $zones_by_type ): array` → same shape from posted `fe_dates[i]` rows (`date`, `zones_posted`, `zones[delivery][]`, `zones[courier][]`). Without `zones_posted` every shown zone is true; with it, absent ids are false.
  - `rules_from_setup( array $existing, array $ticks, array $prices, array $dates, array $zones_by_type ): array{rules: array, dropped: int}`. Keeps every existing rule that is not (type in delivery/courier AND zone shown AND (date null OR date in `$dates`)), except that **all** `on` rules are replaced by `$prices`. Removes in-scope event-wide `off` rules. Adds `off` for each false tick. A price row colliding with an `off` tick (same zone, type, date) is dropped and counted. Output passes through `normalize_rules`.
  - `prices_from_post( array $rows ): array` → `on` rules from `fe_prices[n]` (`zone_id`, `type`, `date` ('' = all dates), `cost`); rows with empty or negative cost are skipped.
  - `exceptions_from_post( array $rows, array $event_dates ): array{excluded: int[], limits: array<int,string[]>, emptied: int[]}` from `fe_exceptions[n]` (`product_id`, `mode` only|exclude, `dates[]`), limits via `clean_limits`.
  - `products_to_link( array $only_ids, array $product_ids, array $categories_by_product, array $category_ids ): int[]` → `$product_ids` plus each `only` product that is neither listed nor in one of `$category_ids`.

- [ ] **Step 1: Failing tests**
  - `test_event_wide_off_becomes_unticked_on_every_date_and_survives_save` (Review Focus 1): existing `[{zone 3, courier, date null, off}]`, dates 12-10/12-18, zones courier `[1,3]` → ticks courier 3 false on both; `rules_from_setup` with those ticks and no prices → off rules for zone 3 courier on 12-10 and 12-18, no event-wide row.
  - `test_out_of_scope_rules_are_kept`: pickup off rule, a zone-9 (not shown) delivery off rule, a 2026-11-01 (not an event date) rule, a delivery `inherit` rule for shown zone 1 date null → all present in output.
  - `test_price_rows_replace_on_rules_and_collide_with_off`: existing `{1, delivery, null, on, 20}`; prices `[{1, delivery, '2026-12-10', 25}, {1, delivery, '', 22}]`; ticks delivery zone 1 false on 12-10 → output has `{1,delivery,null,on,22}`, `{1,delivery,2026-12-10,off}`, `dropped === 1`.
  - `test_unticking_every_zone_posts_all_off_but_missing_marker_means_all_on` (Review Focus 3).
  - `test_exceptions_split_into_exclusions_and_limits` and Review Focus 2: an `only` row whose dates are all gone → `emptied` contains it.
  - `test_only_products_not_in_event_get_linked`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `Event setup: pure conversions between the screen and stored rules`.

### Task 6: One "Event setup" screen

**Files:**
- Modify: `includes/admin/class-fe-event-editor.php` (meta boxes, `parse_post`, `save`, notices, render)
- Modify: `includes/admin/class-fe-zone-grid.php` → keep `zones()` (make it `public static function zones_by_type(): array` returning `['delivery'=>[id=>name], 'pickup'=>[...], 'courier'=>[...]]` plus `zone_name( int $id ): string`), replace the grid with `render_prices( array $rules, array $dates )`.
- Modify: `assets/admin.js`, `assets/admin.css`
- Test: `tests/EventEditorInputTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1, 2, 5.
- Produces: `parse_post( array $post, array $zones_by_type = array(), array $existing_rules = array() )` returns the 0.4.5 keys plus `limits`, `emptied`, `dropped`, `only_ids`; `rules` now come from `Fe_Event_Setup::rules_from_setup`. Excluded ids = `fe_excluded_ids` (none posted any more) ∪ exception exclusions; the old "Exclude products" field is removed from the screen.
- Produces: one meta box `fe_setup` ("Event setup", normal/high) with sections in order: **When it sells** (sales open/close), **Dates** (per date: date, trash; Delivery checkbox + time + "to" zone checkboxes; Pickup checkbox + time + "at" place select; Courier checkbox + "to" zone checkboxes; hidden `zones_posted=1`; "Edit shipping zones and their prices" link under the list), **Products** (categories, individual products, Exceptions rows: product (`wc-product-search`, single), radios "Only on" with one checkbox per saved event date / "Not in this event", trash; "Add an exception" button), and a closed `<details>` **Change a price for this event** (rows: zone select, type select, date select with "All dates", price, trash; "Add a price change"). Side box `fe_notes` unchanged. Remove `fe_details`, `fe_dates`, `fe_zones`, `fe_products`.
- Zone checkbox lists show only zones from `zones_by_type()[type]`; the date-row template renders every zone checked. Exception date checkboxes list saved dates only, with the hint "Save to use new dates here."
- Notices after save (transient array, one per code): `limit_emptied` ("{product} has no dates left in this event, so it can't be bought for it."), `price_dropped` ("A price change was removed because that zone is turned off on that date."), plus the existing `close_before_open`.

- [ ] **Step 1: Failing tests** in `EventEditorInputTest`: a full posted form (sales window, two date rows with zone ticks, one exception of each mode, one price row) → `parse_post` returns the expected `dates` (with `courier`), `rules`, `excluded_ids`, `limits`, `only_ids`; existing 0.4.5 assertions updated for the removed grid input.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** save: rules from `parse_post`, `save_dates`, `replace_for_event`, links via `sync_event` with product ids from `products_to_link` (categories per product from `wp_get_post_terms( $id, 'product_cat', ['fields'=>'ids'] )`), `save_date_limits`, notes, notices. JS: add date row (template), add/remove exception and price rows, dim zone lists when their type is unticked, show the time/place only as dimmed when off (0.4.5 behaviour). CSS: one flow, sections separated by headings, WP admin palette (blue `#2271b1` custom, red `#d63638` off), no horizontal scroll at 782px.
- [ ] **Step 4: Run tests**, then render the screen through a stub harness (as in the 0.4.5 preview) and screenshot at 800px wide: a two-date event and a one-date event, both readable without wrapping inside a date row's lines.
- [ ] **Step 5: Commit** `Event setup: one screen for sales, dates, products and prices`.

### Task 7: Product screens and events list

**Files:**
- Modify: `includes/admin/class-fe-product-fields.php` (read-only limit line on the product edit screen; Events column suffix)
- Modify: `includes/admin/class-fe-event-editor.php` (`render_list_column` exception count)
- Test: `tests/ProductFieldsTest.php` (new, pure helper) or `EventEditorInputTest`

**Interfaces:**
- Consumes: `date_limits` on events.
- Produces: `Fe_Product_Fields::limit_text( string $event_title, array $dates ): string` → `"Holiday Pre-order 2026: only Dec 18"`; dates `[]` → `"Holiday Pre-order 2026: no dates"`. Column label suffix `" (Dec 18)"`.
- Produces: events list Dates column appends `"%d product exceptions"` (singular "1 product exception") counting limits + this event's exclusions.

- [ ] **Step 1: Failing tests** for `limit_text` (one date, two dates "Dec 10 and Dec 18", none).
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement**; the product screen line links to the event's edit screen and reads "(set on the event)".
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `Show date limits on products and exception counts on events`.

### Task 8: Release 0.5.0

**Files:** plugin header + `FE_VERSION`, `readme.txt` (Stable tag, changelog), `docs/design.md` (editor and checkout sections), `docs/testing-checklist.md`, `languages/*.pot`, staging package in `/Users/ed/sources/City_Guy_Delivers/staging/fulfillment-events-2026-11-19/`.

- [ ] **Step 1:** Bump to 0.5.0; changelog lists: one Event setup screen; per-date delivery/courier zones and courier on/off; products limited to some dates or excluded from the event screen; limited products get their own shipping choice; add-ons respect limits; prices moved behind "Change a price for this event".
- [ ] **Step 2:** Checklist items for each Review Focus line and for: one holiday event replacing two (cookie box only Dec 18), Hanukkah exclusions in Exceptions, upgrade of a 0.4.5 event opens with the same behaviour.
- [ ] **Step 3:** `vendor/bin/wp i18n make-pot . languages/fulfillment-events-for-woocommerce.pot --exclude=vendor,tests,bin,.superpowers,dist`; full tests; phpcs on changed files.
- [ ] **Step 4:** `bin/build-zip.sh`; replace `2-fulfillment-events-for-woocommerce-*.zip` in the staging folder, update `STAGING-GUIDE.md` Part A steps 8–11 for the new screen (one Holiday Pre-order event with Dec 10 + Dec 18; cookie box "Only on Dec 18"; Hanukkah items "Not in this event"), re-zip the bundle.
- [ ] **Step 5:** Commit `0.5.0: one-screen event editor`, push `feature/core`.
