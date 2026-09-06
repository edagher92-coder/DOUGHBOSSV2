# DoughBoss v2.5 — full-stack audit and what changed

Audit of the 2.0 plugin (the code behind doughboss.com.au) across security,
architecture, performance and UI/UX, followed by the fixes shipped as 2.5.0.
Each finding cites the 2.0 location it was found in. "Fixed" means the change is
in this branch and covered by a lint, a unit test, or a rendered check; anything
not verifiable in this environment (no MySQL, so no live WordPress) is labelled.

## Verification environment

| Check | Method | Result |
| --- | --- | --- |
| PHP syntax | `php -l` on every file (PHP 8.4 CLI) | clean |
| Pricing / tax / cart / order-number logic | `php tests/run.php` (WordPress shim, no DB) | 469 assertions pass |
| Storefront JS | `node --check` + Playwright harness against a mocked REST API | passes (see PR) |
| Live WordPress install | not available in this sandbox | **not run** — activation, `dbDelta` upgrade and the REST routes need a smoke test on a staging site |

## Findings that were fixed

### Operations-critical

| # | Finding (2.0 location) | Severity | Fix |
| --- | --- | --- | --- |
| 1 | Delivery address and customer notes were collected at checkout and stored, but **never shown to staff** anywhere (admin orders table omitted both columns; store email omitted them). A delivery order could not be fulfilled from the admin screen. | P0 | Address and notes columns in the Orders screen; both included in the store email. |
| 2 | Status update JS reported "Saved" regardless of the HTTP result; `update_status()` returned success for a non-existent order. | High | JS checks `r.ok` and `success`, reverts the select on failure; `update_status()` returns false for a missing order and the REST route returns 404. |
| 3 | `wp_mail()` ran synchronously inside checkout (two sends) so a slow SMTP host stalled the customer's "Place order" for seconds and a mail failure surfaced as a checkout error after the order was already written. | High | Emails queued to a `shutdown` handler after `fastcgi_finish_request()`, with a cron fallback; `email_sent` / `email_error` recorded and shown in admin. |
| 4 | Order number date used UTC, so an 8am Sydney order carried yesterday's date; uniqueness was check-then-insert (racy). | Medium | `wp_date('ymd')` + unambiguous 6-char alphabet; uniqueness enforced by the `UNIQUE KEY` with a duplicate-key retry. |
| 5 | Order row and item rows were inserted without a transaction and item inserts were unchecked, so a partial order could be committed. | High | `START TRANSACTION` … `COMMIT`/`ROLLBACK`, every insert checked. |
| 6 | Double-submitting checkout created two orders. | High | `add_option` lock per cart token (30s stale recovery) plus a client idempotency key stored with a `UNIQUE KEY`. |
| 7 | Cart was priced at add-time and never re-checked, so a menu price change or sold-out item between add and checkout was honoured at the stale price. | High | `revalidate_cart()` re-prices every line against the live menu/settings before insert and returns `409 doughboss_prices_changed` with the fresh cart. |
| 8 | No rate limiting on checkout or order tracking. | Medium | Transient counters: checkout 6/10 min per IP and per email; tracking 20/10 min. |

### Money / tax (NUMBERS RULE)

| # | Finding | Fix |
| --- | --- | --- |
| 9 | Defaults were `USD`, `$`, tax added on top at 0%; installer and runtime had different default tables. | Single defaults table in `DoughBoss_Settings`: AUD, tax-inclusive, label "GST", `tax_applies_to_delivery`. **Tax rate still ships as 0** — a rate is a business decision (plain bread is GST-free, hot prepared food is taxable) and the admin screen nags until it is set. Nothing is guessed. |
| 10 | Inclusive tax was impossible to express; the customer-facing total said "Total charged" for an unpaid order. | `totals()` supports inclusive (`base × r/(1+r)`) and exclusive models; storefront shows "Includes GST $x" and "Total due on pickup/delivery"; unit tests cover both models, the delivery toggle, zero rate and rounding. |

### Correctness / data integrity

| # | Finding (2.0 location) | Severity | Fix |
| --- | --- | --- | --- |
| 11 | Cart token: `wp_generate_password()` produced mixed case; `sanitize_key()` lower-cased it on read, so the key written and the key read differed. Plain MySQL masked it via `_ci` collation; on hosts with a persistent object cache (Redis/Memcached, where core keeps transients only in the cache) the first item added was silently lost. | High on cached hosts | Token is `bin2hex(random_bytes(16))`, validated with a regex and never mutated. Test: mixed-case legacy cookie is rejected, valid token used verbatim. |
| 12 | Cart cookie was minted on every read, so every page view set a cookie (defeating page caches, and creating a consent surface for someone who only looked at the menu). | Medium | Cookie is only set on a write path. Test: reading an empty cart leaves no cookie and no transient. |
| 13 | `sanitize_email` was called on raw request args; an array value (`?email[]=x`) raised a `TypeError` (500). | Medium | Array-safe `sanitize_email_arg()` / `sanitize_idempotency_key()`; tested. |
| 14 | Duplicate toppings were accepted and each charged. | Low | De-duplicated and capped at 12. |
| 15 | Nonce embedded in page HTML went stale behind a full-page cache (anonymous nonce lifetime 12–24h), so every cart action failed with 403 for cached visitors. | High on cached hosts | `GET /nonce` (no-store) and the storefront refreshes + retries once on 403. |
| 16 | Timezone: admin "Placed" column printed UTC. | Low | `get_date_from_gmt` + `mysql2date` in the site timezone. |
| 17 | Settings repeater rows were plain links, so pressing Enter in a size field deleted the row. | Low | Real `type="button"` controls with labels. |
| 18 | `flush_rewrite_rules()` was called at `plugins_loaded` (before the CPT existed — a no-op) and on deactivation with the CPT still registered (rules left behind). | Low | Flag set on install, flushed at `init` 99; deactivator unregisters first. |
| 19 | CPT used generic `post` capabilities, so any Author could edit menu prices. | Medium | Own capability type with `map_meta_cap`, granted to administrators and a new `doughboss_manager` role. |
| 20 | Legacy tracking put the customer's email in a GET URL (logs, referrers). | Low | `POST /order/track`; the GET route is kept for old links. |

### Performance

| # | Finding | Fix |
| --- | --- | --- |
| 21 | Menu endpoint: one thumbnail query per item (N+1), full description per item, no caching. | Post/thumbnail caches primed in one query; transient keyed by a `doughboss_menu_version` option bumped on any item/term/meta change; `medium_large` + `srcset`; trimmed descriptions. |
| 22 | Admin orders: one items query per order (N+1); `LIKE '%…%'` over every column for any search; no index on `created_at` or `(status, created_at)`. | Batched `get_items_for_orders()`; equality fast-path for order numbers and emails; indexes added in the schema upgrade. |
| 23 | Settings re-parsed on every call (per topping in the pricing path); all options autoloaded. | Memoised per request; options seeded with `autoload = no`. |
| 24 | Assets loaded via `has_shortcode()` (rebuilds a regex over every registered shortcode per request) and missed page builders. | `strpos` fast-path plus enqueue from the shortcode itself. |
| 25 | No cache headers, so the CDN/page cache could not cache `/menu` or `/config`. | `Cache-Control: public` on those; `no-store, private` on `/cart`. |
| 26 | Menu was JS-only: no items or prices for search engines or no-JS visitors. | Server-rendered menu with schema.org `Menu/MenuSection/MenuItem` JSON-LD; JS enhances it. |

### UI / UX / accessibility (storefront rebuild)

Detailed spec in the PR; headline items: the order confirmation was wiped by the
next cart refresh; the checkout form was rebuilt (losing typed values) on every
quantity change; the DOM helper dropped ARIA attributes; no focus management or
live regions; no per-field errors; "Total charged" wording; no empty-cart or
"ordering closed" states; no cart badge; no mobile layout for the builder.
All addressed in `public/js/doughboss.js` and `public/css/doughboss.css`, with
a Playwright harness against a mocked REST API.

## Verified as already safe in 2.0 (no change needed)

- Prices computed server-side; the client never submits a price.
- Order tracking response excludes PII.
- Status values are allow-listed; admin `orderby` is white-listed.
- `UNIQUE` order number constraint existed.
- Output in admin was escaped; the only `innerHTML` sink took server-generated markup.

## Deferred — needs a decision from Elie

1. **GST rate.** Ships as 0 with an admin reminder. Setting 10% is one field
   in Settings, but whether every item is taxable is a business/accountant
   call; the plugin will not assume it.
2. **Payments.** Still "order now, pay on pickup/delivery". Stripe Checkout
   is the obvious next step and is the only way to stop no-show orders; it
   touches money so it was kept out of scope.
3. **Opening hours / time slots.** Only a manual "ordering open" switch was
   added. Trading-hours logic needs the real hours.
4. **Delivery zones and fees.** One flat fee; postcode/radius zones need the
   real delivery area.
5. **`WP_List_Table`** for orders (bulk actions, screen options) — the
   custom table was kept and extended instead, to keep the change reviewable.
6. **Staging smoke test.** Activation, the schema upgrade from 2.0 and the
   email path need a run on a real WordPress before this goes to the live site.
