# DoughBoss — Technical Audit Findings

**Plugin version:** 2.12.3 · **DB schema version:** 1.7.0
**Branch:** `claude/funny-goodall-gsoog4` · **Repo:** `/home/user/DOUGHBOSSV2`
**Method:** Direct source reading (no live-site access). Every finding below cites file:line in the repository as of this branch. Nothing about the production state of doughboss.com.au is claimed anywhere in this document — no live-site tool was connected during this review.

**Context:** three real bugs were found and fixed earlier the same day and shipped in v2.12.3 (not yet deployed live): (1) a cart-token case-mismatch that silently dropped guest carts on most new sessions, (2) a Settings-save routine that wiped `app_origin`/`voucher_campaigns` whenever a save didn't explicitly touch them, and (3) Stripe/POSPal secret keys being echoed back into Settings-page HTML. The eight task sections below extend that work with fresh, independently-verified findings across data quality, architecture, UX, front-end, back-end, QA, and deployment readiness.

---

## Table of contents
1. [Data Check & Data Quality Review](#task-1-data-check--data-quality-review)
2. [Full Codebase Review](#task-2-full-codebase-review)
3. [System Optimisation](#task-3-system-optimisation)
4. [Visuals, GUI, UX & User Experience Review](#task-4-visuals-gui-ux--user-experience-review)
5. [Front-End Review](#task-5-front-end-review)
6. [Back-End Review](#task-6-back-end-review)
7. [QA, Testing & Reliability Review](#task-7-qa-testing--reliability-review)
8. [Deployment Readiness](#task-8-deployment-readiness)

---

## Task 1: Data Check & Data Quality Review

Lens: schema integrity, settings correctness, REST input validation, PII/privacy, and reporting capability.

### Findings & severity

| # | Finding | Severity | Location |
|---|---|---|---|
| 4 | No WordPress Privacy Tools integration — order/catering/voucher PII is invisible to core export/erase requests | **Medium–High** | grep for `personal_data\|wp_privacy\|gdpr\|erase` = 0 hits in `includes/`/`admin/`; PII columns at `includes/class-doughboss-activator.php:64-66, 131-133, 174-175` |
| 1 | `/checkout` and `/voucher/issue` — the two most sensitive REST routes — declare no `args` schema and no `validate_callback` exists anywhere in the controller | Medium | `includes/class-doughboss-rest-controller.php:528-536` (checkout), `:257-265` (voucher/issue); 0 of 61 routes use `validate_callback` |
| 2 | Settings-save `currency_code` fallback is hardcoded `'USD'`, contradicting the plugin's AUD-only design | Medium | `admin/class-doughboss-admin.php:179` vs. `includes/class-doughboss-settings.php:73` (`'AUD'`) and migration `1.3.0` (`includes/class-doughboss-migrations.php:110-140`), whose entire purpose was fixing this exact default |
| 3 | No revenue/reporting capability anywhere — Orders admin page is a flat list, no `SUM()`/`GROUP BY`, no CSV export | Medium | `admin/class-doughboss-admin.php:609-737`; `includes/class-doughboss-order.php:422-477` (`query()`, the only orders-table query method) |
| 5 | `DoughBoss_Stripe::create_refund()` is fully implemented but has **zero callers** anywhere — `payment_status` can never become `'refunded'` | Medium | `includes/class-doughboss-stripe.php:134`; write sites only ever set `'unpaid'`/`'paid'` (`includes/class-doughboss-rest-controller.php:2054-2065`) |
| 6 | `voucher_campaigns` and `pospal_label` settings are live/readable but absent from `DoughBoss_Settings::defaults()` — undocumented schema | Low | `includes/class-doughboss-voucher.php:429`; `includes/class-doughboss-settings.php:543,588` vs. `defaults()` at `:70-168` |
| 7 | `doughboss_locations.slug` is a non-unique `KEY` with no de-dup check on create | Low | `includes/class-doughboss-activator.php:107-123`; `includes/class-doughboss-locations.php:101-139` |
| 8 | Catering `event_date` accepts past dates; `guest_count` has no upper bound | Low | `includes/class-doughboss-catering.php:494-501`; `includes/class-doughboss-rest-controller.php:670-673` |
| 9 | Voucher `type=percent` has no upper-bound validation on `value` (e.g. 500% can be stored) | Low | `includes/class-doughboss-voucher.php:93-100` (issue-time check); `:222-232` (evaluate-time clamp exists, so not financially exploitable) |
| 10 | `currency_code`/`currency_symbol` are freeform text, no ISO-4217 whitelist — a typo silently breaks Stripe (400 on every payment intent) | Low | `admin/class-doughboss-admin.php:178-179`; `includes/class-doughboss-stripe.php:88` |
| 11 | Menu seeder's "idempotent" matching is by exact `post_title` only — renaming a seeded item in wp-admin causes the next seed run to create a duplicate | Low | `includes/class-doughboss-menu-seeder.php:106-119`; the `_doughboss_seed` marker (`:149`) is stamped but never used as the lookup key |

### Recommended fixes (paired with what must stay unchanged)

- **#4 Privacy hooks:** Register `wp_privacy_personal_data_exporters`/`_erasers`, keyed on `customer_email`, covering orders + catering + vouchers; implement anonymization (null PII columns) rather than row deletion. **Keep unchanged:** financial totals and order-number history must survive an erasure request — AU tax law expects ~5yr retention, so an eraser must anonymize, never delete the row.
- **#1 Schema validation:** Add `args` + `validate_callback` to every route, prioritizing `/checkout` and `/voucher/issue`, mirroring the inline allow-lists that already exist. **Keep unchanged:** the manual required-field checks inside `checkout()`/`DoughBoss_Voucher::issue()` — keep as defense-in-depth even after adding schemas.
- **#2 Currency fallback:** Change to `'AUD'`, or better, fall back to `$existing['currency_code']` (the pattern already used for `orders_email` two lines below). **Keep unchanged:** the "seed `$clean` from `$existing`" pattern itself — that's the fix for the earlier settings-wipe bug.
- **#3 Reporting:** Add a lightweight Reports admin page (`SUM()/GROUP BY` against `doughboss_orders`/`doughboss_order_items`) plus a hand-rolled CSV export (`fputcsv`), consistent with the no-build-pipeline convention. **Risk:** a naive `SUM(total)` must exclude/label cancelled orders — there's no `'refunded'` status in use anywhere (see #5), so a first-cut report could overstate revenue.
- **#5 Refunds:** Add an admin action (gated `manage_doughboss`) calling `create_refund()` and writing `payment_status='refunded'` + `refunded_at`. **Risk:** must decide how a refund interacts with an already-redeemed voucher on that order — `revert_redemption()` today only fires on order-creation failure, not on a later refund.
- **#6–#11:** all low-risk, mechanical fixes (document defaults, de-dup slugs, bound date/guest-count/percent inputs, whitelist currency format, key the seeder off `_doughboss_seed` + a stable synthetic key rather than title).

**Not independently verifiable in this environment:** actual production data volumes/PII exposure on doughboss.com.au, whether any live erasure request has ever been received, and whether the USD fallback (#2) has ever actually fired in production.

---

## Task 2: Full Codebase Review

Lens: senior architecture pass — route registration, permission boundaries, secrets, response conventions, testability, duplication.

### Audit summary

DoughBoss's architecture is honest about what it is: a flat, static-method-heavy, no-framework WordPress plugin that grew from a 3-table MVP into a 6-table platform (vouchers, catering, POS, payments) without pausing to re-shape its own boundaries. The domain logic itself (voucher atomicity, checkout transactions, migration safety, capability separation) reads like it was written by someone who understands the failure modes of what they're building. What hasn't kept pace is the *seams between* those well-built pieces — route registration quietly split across two files while documented as centralized; a security boundary explicitly designed for vouchers (till-can't-create-value) wasn't carried to the structurally identical catering-status endpoint; the one integration handling the highest-value secret (Stripe) is the exception to an otherwise-consistent env-first secrets convention; and payment-intent creation — the single most expensive/abusable call in the system — is the one money-path route with no rate limit, while its siblings (checkout, vouchers) are all protected.

### Critical bugs / risks

| # | Finding | Severity | Location |
|---|---|---|---|
| 1 | Payment-intent creation routes are completely unthrottled — the only 3 money-adjacent routes with no `rate_limited()` call | **High** | `create_payment_intent()` (`includes/class-doughboss-rest-controller.php:1674`), `catering_payment_intent()` (`:2430`), `catering_confirm_payment()` (`:2489`); contrast 8 confirmed `rate_limited()` call sites elsewhere |
| 2 | Catering status changes (`QUOTED→…→PAID/LOST`) are reachable by the kitchen/KDS-only role via `verify_admin`, breaking the project's own "till can't create value" boundary already enforced for vouchers via `verify_manage` | **High** | route reg. `:711-717`; `DoughBoss_Catering::update_status()` (`includes/class-doughboss-catering.php:261`); contrast `verify_manage()` doc-comment at `:821-833` |
| 3 | Stripe secret key + webhook secret are the one integration that skips the project's "env-first secrets" convention (5 of 6 other integrations support `getenv()`/constant override; Stripe doesn't) | Medium (high-value quick win) | `includes/class-doughboss-settings.php:307-337` vs. POSPal (`:376-391`), Mercure (`:638-648`), ntfy (`:706-718`), ClickSend (`:762-774`), printer (`:838-850`) |
| 4 | Route registration is not actually centralized as documented — `DoughBoss_Printer` registers 2 more routes via its own `rest_api_init` hook, outside the REST controller | Medium (docs/clarity) | `includes/class-doughboss-printer.php:66-118`; hooked in `includes/class-doughboss.php:152` |
| 5 | No consistent REST response envelope — at least 5 different ad hoc boolean keys (`success`, `ok`, `valid`, `ready`, `redeemed`) across ~39/61 response sites | Medium | `includes/class-doughboss-rest-controller.php` throughout, e.g. lines 938, 979, 1141, 1188, 2418 |
| 6 | Zero dependency injection anywhere except `DoughBoss_Cart` — all cross-class calls are hardcoded statics, which structurally resists unit testing | Medium | confirmed via `grep -rn "class .* extends\|interface \|abstract class"` = 0 matches across 26 classes; only one `DoughBoss::instance()` call site (`doughboss.php:73`) |
| 7 | Identical HTTP-response-handling boilerplate duplicated across 5 integration classes with no shared helper | Medium | `class-doughboss-mercure.php`, `-ntfy.php`, `-sms.php`, `-pospal.php`, `-stripe.php` — each reimplements `wp_remote_*` → `is_wp_error` → decode chain independently |
| 8 | Diagnostic/dev-only REST endpoints (incl. the already-flagged `/pospal/probe-grant` brute-force) are permanently part of the production API surface with no `WP_DEBUG` gate | Low | `includes/class-doughboss-rest-controller.php` lines 334, 344, 354, 374, 394, 414 — confirmed 0 `WP_DEBUG` references in the file |
| 9 | Admin Settings screen has no shared field-rendering helper — 47 hand-copied `<input>` blocks, which is *why* the secret-echo bug (fixed today) existed in the first place | Low | `admin/class-doughboss-admin.php` — only `keep_secret()` (`:292`) and `sanitize_rows()` (`:306`) are shared, and neither is a rendering helper |

### Refactoring recommendations (ranked)

1. Fix #2 (catering status → `verify_manage`) and #1 (rate-limit payment-intent routes) — small, mechanical, close a real authorization/abuse gap. Do these first.
2. Fix #3 (Stripe env-first secrets) — copy the existing POSPal idiom verbatim, ~15 minutes for the highest-value secret in the plugin.
3. Document the printer route-registration exception (#4) and correct CLAUDE.md's "all routes" claim.
4. Extract the shared HTTP-response-parsing helper (#7) next time any of the 5 integrations needs a touch anyway — don't do it standalone with no test net.
5. Gate diagnostic POSPal/Mercure routes behind `WP_DEBUG` (#8), and revisit deleting `/pospal/probe-grant` now that the real grant endpoint should be confirmed.
6. Settings-page field-render helper (#9) — do opportunistically alongside the next Settings feature.
7. Response-envelope convention (#5) and the DI/testability question (#6) are the biggest-but-least-urgent items — write the convention down for *new* routes now; treat retrofits/DI as work that should *follow*, not precede, a real test harness (Task 7).

### Security improvements
- Swap catering-status route to `verify_manage` (#2).
- Add rate limiting to all 3 payment-intent routes (#1), matching the existing bucket pattern.
- Add env-first override for Stripe secret key / webhook secret (#3) so the highest-value credential in the stack isn't DB-option-only.
- Gate diagnostic POSPal/Mercure endpoints behind `WP_DEBUG` (#8).

### Testing gaps
- Confirmed 0% automated coverage (detailed fully in Task 7). The architecture itself compounds this: zero interfaces/DI (#6) means nothing can be substituted for a test double without PHP-level monkey-patching — testability is a structural, not just a scheduling, gap.

### Do-not-touch / handle-with-care areas
- The existing bucket sizes/windows for checkout and vouchers — sensibly tuned, don't touch when adding payment-intent rate limits.
- `verify_admin`'s `OR`-in of `manage_doughboss_kds` for genuine kitchen-floor routes (order-board ack/accept/status) — correct as-is, only the catering-status route needs to move off it.
- The DB-option fallback for Stripe secrets must remain for backward compatibility even after adding env-first support.
- Each integration's specific request construction (headers/auth scheme/body encoding) — genuinely differ, shouldn't be forced into one shape by the HTTP-helper extraction.
- Printer routes' actual permission model (`__return_true` + in-callback `hash_equals`) — correct for a device that can't send a WP nonce; don't force it through `verify_nonce`.
- Do not rename existing REST response keys retroactively — `app/app.js` and `demo/*.js` hardcode current field names with no contract test to catch breakage; only enforce a new envelope convention going forward.
- No sweeping DI refactor or interfaces wholesale — for a 26-class, no-build-step plugin that adds complexity without a matching payoff; if pursued, do it class-by-class paired with the money-path test harness.

---

## Task 3: System Optimisation

Optimization-relevant points pulled from every specialist lens (data, architecture, front-end, back-end, devops), organized by horizon.

### Immediate (cheap, safe, high-value)

| Area | Action | Source |
|---|---|---|
| Security/abuse | Add `rate_limited()` calls to `create_payment_intent`, `catering_payment_intent`, `catering_confirm_payment` | Architecture #1, Backend #4 (related rate-limiter race) |
| Security/authz | Move `/admin/catering/{id}/status` from `verify_admin` to `verify_manage` | Architecture #2 |
| Query performance | Batch-fetch order line items with a single `WHERE order_id IN (...)` instead of one query per order in `active_orders()` (Live Order Board, polled ~7s) and the admin Orders list | Backend #3 |
| Query performance | Add `KEY balance_intent_id` to `doughboss_catering_enquiries` (currently unindexed while `find_by_intent()`'s `OR` defeats the existing `deposit_intent_id` index on every Stripe webhook delivery) | Backend #5 |
| Front-end perf | Stop the order board's 30s "refresh timestamps" tick from doing a full DOM rebuild — walk `.db-card-time` nodes in place instead | Frontend #2 |
| Front-end perf | Align `app/app.js`'s 3 pollers and `doughboss-voucher-scan.js`'s poller onto the order board's resolve-then-reschedule pattern (currently plain `setInterval`, which can race stale responses over fresh ones) | Frontend #3 |
| Data correctness | Fix settings-save `currency_code` fallback (`'USD'`→`'AUD'` or preserve-existing) | Data #2 |
| Documentation cost | Bump `readme.txt` `Stable tag` to match `DOUGHBOSS_VERSION`; fix the Stripe-FAQ self-contradiction | DevOps #4 |

### Short-term (needs a small design decision, still additive)

| Area | Action | Source |
|---|---|---|
| Async/latency | Mark POSPal push + ClickSend SMS `wp_remote_post` calls `'blocking' => false` (matching ntfy/Mercure, which already do this) so a slow endpoint doesn't extend checkout/status-update response time; log `wp_mail()` return values in `send_confirmation()` (currently the only integration whose failures are unlogged) | Backend #6, DevOps #3 |
| Reliability | Add a Stripe webhook for the *main* storefront checkout (mirroring the catering webhook already built) to reconcile payments that succeed but never produce an order, and wire `create_refund()` (currently dead code) into the unverified-payment path instead of the false "reversed automatically" customer message | Backend #1, Data #5 |
| Reliability | Add the same business-rule gates (`ordering_open()`, `enable_delivery`/`enable_pickup`) to `create_payment_intent()` that `checkout()` already enforces, so a card can't be charged for an order the shop will then refuse | Backend #2 |
| Concurrency correctness | Replace the rate limiter's non-atomic get-then-set transient increment with an atomic `INSERT ... ON DUPLICATE KEY UPDATE` or the same `GET_LOCK` pattern already used correctly in `DoughBoss_Voucher::claim()` | Backend #4 |
| Reporting | Add a Reports admin page (`SUM()`/`GROUP BY` on orders/order_items) + CSV export — currently zero aggregation exists anywhere | Data #3 |
| Config hygiene | Reconcile the two independently-maintained "defaults" arrays (`class-doughboss-activator.php`'s seed vs. `DoughBoss_Settings::defaults()`) — currently masked by `wp_parse_args` merge but a maintainability trap (e.g. `delivery_fee` disagrees: $5 seeded vs. $0 default) | DevOps #2 |
| Build hygiene | Add version/readme consistency check + a `php -l` re-run to `build-zip.sh` before zipping | DevOps #5 |

### Long-term (bigger design work, sequence after a test harness exists)

| Area | Action | Source |
|---|---|---|
| Testability | Stand up a minimal PHPUnit harness (no `composer.json`/`tests/` exist today) — prerequisite for everything else in this row | QA §6 |
| Architecture | Extract a shared `DoughBoss_Http` helper to de-duplicate the identical `wp_remote_*`/`is_wp_error`/decode chain across 5 integrations | Architecture #7 |
| Architecture | Begin passing `DoughBoss_Settings`-sourced config into `DoughBoss_Cart`/`DoughBoss_Voucher`/`DoughBoss_Stripe` as constructor/method params instead of internal static calls, starting with the highest-value classes only | Architecture #6 |
| Async infrastructure | Move POSPal/SMS/email dispatch onto WP-Cron (`wp_schedule_single_event`) rather than inline `blocking=>false`, if true request-time decoupling (not just non-blocking sockets) is wanted — requires confirming WP-Cron is actually firing on the target host first | Backend #6, DevOps #3 |
| Front-end | Converge the four independent `el()`/`make()` DOM-builder implementations and plan real de-duplication of the ~250-line Voucher Scan feature that's currently duplicated (and drifting) between `doughboss-voucher-scan.js` and `app/app.js` | Frontend #8 |
| Observability | Add a single low-privilege `GET /doughboss/v1/status` endpoint reporting DB connectivity, schema version, and each integration's `*_ready()` boolean, for external uptime monitoring — today the only signal is scattered `error_log()` calls | DevOps #8 |
| Privacy | Register WP core privacy exporters/erasers + an anonymization policy for aged PII | Data #4 |

### Do-not-change (explicitly called out across specialists as correct-as-is)

- Server-side price/total recomputation everywhere (cart, voucher, catering, Stripe amount verification) — no exceptions found, must never be short-circuited by an optimization.
- The DB-transaction + order-number-collision-retry shape in `DoughBoss_Order::create()`.
- The atomic conditional-UPDATE voucher claim, its mandatory audit row, and idempotency-key replay handling.
- GST-inclusive vs. exclusive branch logic in `DoughBoss_Cart::totals()` — structurally different formulas that must both be preserved.
- The explicit allow-list approach in `build-zip.sh` — safer by construction than a copy-with-excludes; don't convert to an npm/Composer build step (violates the project's own no-build-pipeline convention).
- The "always exit 0" contract of `scripts/dev-check.sh` — deliberate for its SessionStart-hook role; any stricter CI check should be a *separate* script, not a change to this one.
- The order board's existing resolve-then-reschedule `setTimeout` poll loop — it's the one poller already doing this correctly; propagate the pattern elsewhere rather than changing it.

---

## Task 4: Visuals, GUI, UX & User Experience Review

Scope: all 8 `demo/*.html` pages, `demo/demo.css` (618 lines), `demo/*.js`, and wp-admin `render_*_page()` methods. Contrast ratios computed directly from source hex values (WCAG relative-luminance formula), not assumed.

### Findings

| # | Finding | Severity |
|---|---|---|
| 1 | Site runs **two unreconciled brand color systems** — near-black `--ember:#111111` (storefront) vs. gold/amber `#b5571f`/`#d98a3d` (staff/backend/owner) vs. burnt-orange `#d6502b` (legal pages) vs. a mix of both in `franchise.html`. Confirmed via `git log`: rebrand commits `8d58ac1`/`3154121` touched only `demo.css` + `index.html`; the other 7 pages are fully self-contained (0 `href="demo.css"` references) and never received it. | **Critical** |
| 2 | A hardcoded leftover from the pre-rebrand palette: `.kitchen-lock` (`demo.css:613-614`) references an **undefined** `var(--card, #fff)` (always falls back) and a literal `#b5571f` — exactly the token class the rebrand's own commit message claims to have swept | High |
| 3 | `index.html` — the busiest, most interactive page — has **no skip link**, despite `.skip`/`.vh` being correctly built and working in `backend.html`/`owner.html`/`staff.html` | High (accessibility) |
| 4 | The 3 legal/contract pages (`licensing.html`, `terms.html`, `privacy.html`) have **zero `@media` breakpoints**; `licensing.html` alone has 13 unwrapped `<table>` elements (up to 6 columns), no `overflow-x:auto` guard | Medium-High |
| 5 | The same `.btn` class renders with **three different geometries** across pages (border-radius 7px/10px/11px/12px, differing padding) because there's no shared button component across the 7 standalone pages | Medium |
| 6 | No "this is a simulated demo" disclosure anywhere on `backend.html`, `owner.html`, or `staff.html` — only `index.html`'s `.demo-ribbon` carries the disclaimer, yet these three are the most realistic-looking (live pulse animation, persisted "signed in" state) and most likely to be shared standalone | Medium |
| 7 | Two low-contrast pairs fail WCAG AA: `.dbk-empty` (`#b5ab9c` on `#f1ece4`) = **1.93:1** (Kitchen Board's empty-lane placeholder); `.card .sub` (`#b8b1a4` on white) = **2.13:1** but confirmed **dead CSS** — no markup in `index.html` currently produces it, so this is debt to delete, not a live bug | Medium / Low |
| 8 | Loading/disabled submit state exists in the voucher-claim flow (`snowboss.js:161`) but is **absent** from `menu-order.js`'s `placeOrder` and `catering-demo.js`'s `submit` — currently masked because nothing in the demo has real async latency, but will matter the moment either flow gets wired to a real delay-bearing call | Low |

### What already works well (verified, keep as-is)

- `:focus-visible` is a real, global, correctly-scoped rule (`demo.css:17` + page-specific gold-accent equivalents) — not suppressing default focus rings anywhere checked.
- `prefers-reduced-motion` is honored in `demo.css:592` and independently in `backend.html`/`owner.html`/`staff.html`.
- Form labeling is correct everywhere traced, including JS-templated markup — every icon-only button has an explicit `aria-label`; no unlabeled control found.
- wp-admin screens correctly inherit native WP admin chrome (`wp-list-table`, `form-table`, `submit_button()`, `notice-success`/`notice-error`) rather than reinventing a design system — empty states handled, error/success copy specific and actionable. **Strongest part of the whole review — do not touch.**
- Touch-target polish under `@media(pointer:coarse)`; nav and category jump-bar share a consistent horizontal-scroll-with-fade-mask idiom, kept in sync (sticky-top offsets match between the two).
- Internally-consistent error-state visual language within `index.html` (`cd-err`/`dbq-err`/`dbo-err` share one show/hide idiom; the dark-section Snow Boss variant correctly swaps to a lighter red tint for contrast on navy).

### Suggested improvements (paired with what should stay unchanged)

- **#1:** Either finish the monochrome rollout across all 8 pages, or explicitly decide the operational apps keep a distinct "internal tool" palette *on purpose* and document that decision. **Keep:** the scoped-CSS-variable technique itself (`--ember` redefined per dark section) — good pattern, extend it, don't remove it.
- **#2:** Replace with the already-defined `var(--ember)`/`var(--linel)` equivalents; drop the orphaned `var(--card, ...)` reference. No design risk.
- **#3:** Copy the working `.skip`/`.vh` pattern from `backend.html:34-36` verbatim into `index.html`.
- **#4:** Wrap every `<table>` in `overflow-x:auto`; add at least one `@media(max-width:620px)` pass. **Keep:** the fluid `clamp()`-based type scale — it holds up fine for prose, it's specifically the tables that are unguarded.
- **#5:** Not a build-pipeline fix — a single canonical button block copy-pasted verbatim into every page's `<style>` (same discipline recommended for nav/footer duplication elsewhere) would stop further drift. **Keep:** each page's internal button-variant consistency (one filled + one outlined per CTA row) — already sensible.
- **#6:** Add a small persistent disclosure to backend/owner/staff, consistent with how `licensing.html` already handles its own "DRAFT — NOT YET EXECUTED" disclaimer. **Keep:** the redirect-to-`staff.html` auth-gate pattern with `<noscript>` fallback — clean and honest, just needs the disclosure language to travel with it.
- **#7:** Darken `.dbk-empty` toward `#8a8377`-or-darker (the same fix already applied elsewhere in the same rebrand pass, just missed here); delete the orphaned `.card .sub` rule.
- **#8:** Copy `snowboss.js`'s disable→submit→re-enable-on-error pattern into the other two flows now, while cheap.

---

## Task 5: Front-End Review

Scope: `public/js/doughboss.js`, `doughboss-orderboard.js`, `doughboss-voucher-scan.js`, `demo/menu-order.js`, `app/app.js` + paired CSS. No shared JS module exists anywhere — each script is an independently WP-enqueued file with no common dependency; state lives entirely in closure-scoped variables; every render is `innerHTML=''` + full rebuild (no diffing).

### Findings

| # | Finding | Severity |
|---|---|---|
| 1 | Checkout form (incl. the mounted Stripe card iframe) is **destroyed and rebuilt on every cart mutation** — quantity change, line removal, or voucher apply/remove all funnel through `draw()`, which tears down and rebuilds the entire `[doughboss_cart]` container including a brand-new Stripe Elements iframe, silently discarding a card number the customer already typed | **High** |
| 2 | Live Order Board does a full DOM rebuild every ~7s poll *and* an extra full rebuild every 30s purely to refresh "Nm ago" timestamp text | Medium |
| 3 | `app/app.js` (3 pollers) and `doughboss-voucher-scan.js` use plain `setInterval` with no request sequencing/overlap guard — a stale response can overwrite a fresher one; `app.js`'s `.catch(function(){})` on all 3 pollers is empty, so a dropped connection shows stale data with **zero** visual indication | Medium (app.js) / Low (voucher-scan.js) |
| 4 | Voucher-redeem result has **no `aria-live`/`role="alert"`** in either staff-facing implementation (`doughboss-voucher-scan.js` or `app/app.js`'s `toast()`), despite the identical pattern already being used one file over in `doughboss.js`'s customer-facing checkout/voucher messages | Medium |
| 5 | `doughboss-orderboard.css` — the single most operationally critical stylesheet (kitchen tablet, staff stare at it all shift) — uses **zero CSS custom properties** while 4 of 5 sibling stylesheets use a token system; 37 raw hex literals, several repeated | Low-Medium |
| 6 | Order board's two continuous CSS animations (`db-flash`, `db-card-pop`) **ignore `prefers-reduced-motion`**, despite the rest of the plugin/demo consistently guarding this pattern elsewhere | Medium |
| 7 | `app/app.js`'s login screen has **no `<form>` element** — bare inputs in `<div>`s, Enter-to-submit wired only on the password field, breaking password-manager autofill for a device already flagged as a plaintext-credential soft spot | Low-Medium |
| 8 | DOM-builder helper reimplemented **4 times** with diverging capabilities (`doughboss.js`, `doughboss-orderboard.js`, `doughboss-voucher-scan.js`, `app.js`), and the entire Voucher Scan feature (~250 lines) is duplicated near-verbatim between `doughboss-voucher-scan.js` and `app.js` and has **already started drifting** (different class-name prefixes, different idempotency-key prefixes) | Medium |
| 9 | No focus management after async content swaps — checkout confirmation and order-tracking result blocks get no `tabindex`/`focus()`/live region, despite `demo/index.html` demonstrably knowing this pattern (`view.setAttribute('tabindex','-1'); view.focus(...)` on every hash-route change) | Low-Medium |
| 10 | Checkout payload fields (`customer_name`, `customer_email`, `customer_phone`, `address`, `notes`) aren't `.trim()`ed client-side, inconsistent with the voucher-code input on the same form which already is | Low |

### Front-end strengths (verified)

- Consistent XSS-safe DOM construction — dynamic/user-influenced text goes through `textContent`, never string-concatenated `innerHTML`, across all four scripts in scope. The one `html:` prop usage (`doughboss.js:581`, order number) is safe because the value is server-generated in a fixed format, verified against the generator.
- `demo/menu-order.js` implements a genuinely solid accessible modal: real focus trap, `Escape`-to-close, focus restored on close, `role="dialog" aria-modal="true"`, `prefers-reduced-motion` guard — and correctly skips re-rendering the checkout view when the cart changes underneath it, which is exactly the discipline the real storefront checkout (Finding 1) is missing.
- `doughboss-orderboard.js`'s poll scheduling is correct — `setTimeout` reschedules only after the in-flight request resolves, the only one of the four pollers in scope to get this right.
- Mercure SSE payloads are never trusted/rendered directly in either implementation that uses it — every message triggers a re-fetch of authoritative REST data; polling fallback is never fully disabled.
- `doughboss-voucher-scan.js`'s tracker rendering (`renderTiles`/`renderMeters`/`renderFeed`) clears+rebuilds only its own subtree, which is why the scan input never loses focus across the poll — the better pattern, not reused elsewhere.
- Camera QR scanning is implemented identically and safely in both staff surfaces — lazy CDN load, proper teardown on tab-hide, decoded value fed through the same validated redeem path.
- Idempotency keys generated client-side and correctly reused on retry for both checkout and voucher redemption — real network-resilience, not cosmetic.

### Prioritized refactor list

1. Fix the checkout-form/Stripe-teardown bug (#1) — payment-flow data-loss, highest priority.
2. Add `aria-live`/`role="alert"` to both voucher-scan result regions and `app.js`'s toast (#4).
3. Guard the two order-board animations with `prefers-reduced-motion` (#6).
4. Stop the order board's 30s tick from doing a full rebuild (#2).
5. Align the 4 ad-hoc pollers onto the order board's resolve-then-reschedule pattern; add a visible "reconnecting" state to `app.js` (#3).
6. Wrap `app.js`'s login fields in a real `<form>` (#7).
7. Move focus to checkout-confirmation/order-tracking result blocks (#9).
8. Longer-term: converge the four `el()`/`make()` implementations and plan real de-duplication of the Voucher Scan feature (#8) — needs a design decision since `app/` deploys standalone with no build step; don't force a shared runtime dependency.
9. Tokenize `doughboss-orderboard.css` colors (#5) and trim checkout text inputs (#10) — lowest priority.

---

## Task 6: Back-End Review

Scope: checkout, order, voucher, catering, and Stripe code paths (`class-doughboss-order.php`, `-cart.php`, `-voucher.php`, `-catering.php`, `-catering-package.php`, `-stripe.php`, plus the relevant REST handlers and the six order-lifecycle integration listeners).

### Findings

| # | Finding | Severity |
|---|---|---|
| 1 | Main storefront checkout has **no reconciliation path** for a payment that succeeds but never becomes an order — no webhook (unlike catering, which has one specifically for "any client that never returns"), no cron sweep, and `create_refund()` is never called anywhere. Worse: both `verify_payment()` and `catering_confirm_payment()` tell the customer *"if you were charged it will be reversed automatically"* — **not true for either flow** | **High** |
| 2 | `create_payment_intent()` skips the business-rule gates `checkout()` enforces (`ordering_open()`, `enable_delivery`, `enable_pickup`) — a card can be charged while the shop is closed or for a disabled fulfillment mode, discovered only after money is already captured | **High** |
| 3 | Live Order Board and admin Orders list both **N+1-query** their line items — up to 101 queries per ~7s board poll (1 + 1-per-order for up to 100 orders); the admin Orders table does the same per page load. Contrast: `DoughBoss_Voucher::query()` already does the equivalent correctly with a single `LEFT JOIN` | Medium |
| 4 | Rate limiter's get-then-set transient increment is a classic TOCTOU race — concurrent bursts from one IP can exceed the stated limit, most consequentially on the voucher-guessing buckets where the limiter's entire purpose is slowing down code-guessing attacks | Medium |
| 5 | `doughboss_catering_enquiries.balance_intent_id` has no index while `deposit_intent_id` does; `find_by_intent()`'s `OR` across one indexed/one unindexed column defeats index use, forcing a full table scan on every Stripe webhook delivery (including Stripe's liberal retries) | Low (grows with scale) |
| 6 | All order-lifecycle integrations fire **synchronously in the request thread**; POSPal push (25s timeout) and ClickSend SMS (20s timeout) can each add real latency to a customer checkout or a staff "mark ready" click. No cron/async queue exists anywhere in the plugin (0 hits for `wp_schedule_event`/`wp_next_scheduled`/Action Scheduler). `wp_mail()`'s return value in `send_confirmation()` is never checked — the one place in the codebase that breaks the "log every integration failure" convention otherwise followed consistently | Medium |

### Corroborated existing weakness (new citations)
`vouchers.single_use` is written at `issue()` (`class-doughboss-voucher.php:129`) and `claim()` (`:572`), defined in schema (`class-doughboss-activator.php:172`), but never read by `evaluate()` or `redeem()` — `redeem()` unconditionally flips `issued→redeemed` regardless of the column's value. Confirmed real and precisely located, not re-litigated as new.

### Back-end strengths (verified)
- Checkout is transactionally sound: `DoughBoss_Order::create()` wraps order + all line items in one `START TRANSACTION`/`COMMIT`, rolls back on any single insert failure, retries order-number collisions up to 5 times — a partial order can't happen.
- Voucher redemption is genuinely atomic under concurrency: conditional `UPDATE ... WHERE status='issued'` claim, mandatory audit row with revert-on-failure, idempotency-key replay — the best-designed subsystem in the reviewed scope.
- Checkout idempotency is correctly layered on top of voucher idempotency — a client retry replays the same order confirmation *and* doesn't re-burn the voucher.
- Pricing is recomputed server-side at every layer with no exceptions found (cart, voucher, catering, Stripe amount verification), and GST-inclusive vs. exclusive branching is preserved consistently.
- The catering payment/webhook design (verify-on-return + webhook backstop + idempotent `mark_paid()`) is the *correct* pattern — it simply wasn't applied to the main checkout flow (Finding 1).
- Consistent opaque anti-enumeration errors (order tracking and voucher redemption both return identical errors for "not found" vs. "exists but doesn't match") and consistent `WP_Error` usage with correct HTTP status codes throughout.

### Recommended fixes
- **#1:** Add a `/stripe-webhook` route mirroring the catering one (keyed on `metadata.context`), checking `payment_intent_used()` before acting; fix the customer-facing "reversed automatically" copy immediately regardless, since it's currently false. **Keep unchanged:** verify-then-create ordering, single-use-per-PaymentIntent enforcement, idempotency-key replay cache.
- **#2:** Move `ordering_open()`/`enable_delivery`/`enable_pickup` checks into `create_payment_intent()` too (or a shared private method). **Keep unchanged:** the amount/currency re-verification in `checkout()` remains the final authority.
- **#3:** Batch-fetch items via `WHERE order_id IN (...)`, group in PHP by `order_id`. **Keep unchanged:** per-order JSON shape and `ORDER BY created_at ASC`.
- **#4:** Atomic `INSERT ... ON DUPLICATE KEY UPDATE hits = hits + 1`, or the same `GET_LOCK` pattern `DoughBoss_Voucher::claim()` already uses. **Keep unchanged:** per-bucket limits/windows and IP-based keying (a separate, larger discussion).
- **#5:** Add `KEY balance_intent_id`, bump `DOUGHBOSS_DB_VERSION` with a migration step per the project's own additive-`dbDelta` convention. **Keep unchanged:** `find_by_intent()`'s single-query shape — index both sides, don't split the query.
- **#6:** Mark POSPal push and ClickSend `blocking => false` (matching ntfy/Mercure which already do this correctly), or move to WP-Cron for true decoupling; check/log `wp_mail()`'s return value. **Keep unchanged:** the synchronous DB-transaction-then-`do_action` sequencing for order creation itself — only the outbound integration calls should decouple.

### Top risks (ranked)
1. Money captured with no order and no refund (#1 + #2) — the only issue with direct financial/trust impact.
2. Order-board/admin N+1 queries (#3) — most likely to actually bite as order volume grows, on the highest-frequency polling path.
3. Rate-limiter race (#4) — defeats the voucher-guessing throttle's entire purpose under concurrent load.
4. Blocking integrations with no async layer (#6) — latent latency/availability risk rather than a correctness bug today.

---

## Task 7: QA, Testing & Reliability Review

### Coverage status: confirmed 0%

Repo-wide search confirms: no `tests/` directory, no `phpunit.xml`, no `composer.json` (so no WP PHPUnit scaffold could even be installed), no `package.json` (no JS test runner for `public/js/`, `demo/`, or `app/`), and the only GitHub Actions workflow (`.github/workflows/pages.yml`) deploys the static demo site, unrelated to plugin correctness. `scripts/dev-check.sh` is explicitly documented as syntax-only and **always exits 0** regardless of outcome — it cannot catch a single logic bug, only malformed PHP syntax. Every correctness guarantee described elsewhere in this document (atomic voucher claims, payment replay protection, transactional order writes, GST math) exists only as manually-reasoned-about PHP, never as an executable assertion.

### Highest-risk untested code paths — concrete test cases

**`DoughBoss_Cart::totals()`** (`includes/class-doughboss-cart.php:290-340`)
1. GST-inclusive, no voucher, pickup — pins the default AU configuration used by every customer.
2. GST-exclusive, delivery — the two tax branches are structurally different formulas; a shared test catches an accidental branch swap.
3. Voucher discount reduces the taxable base *before* tax (inclusive mode) — catches a common off-by-formula bug that over/undercharges GST on discounted orders.
4. Voucher discount larger than subtotal is clamped to zero, never negative.
5. Invalid/expired voucher code silently drops with no error — pins the intentional "quietly ignore, don't throw" contract.
6. Percent-voucher rounding (e.g. $19.99 × 15%) — exact rounding-mode verification, cheapest place to catch money-rounding drift.
7. `item_count` sums quantities, not line count — easy to accidentally regress to `count($lines)`.

**`DoughBoss_Voucher::redeem()`/`claim()`/`evaluate()`** (`includes/class-doughboss-voucher.php`)
8. Scope mismatch (`instore` voucher used `online`) is rejected.
9. Validity-window boundaries at exactly `valid_from`/`valid_to` — classic off-by-one risk.
10. Min-spend exact boundary (`subtotal == min_spend` must pass, since the code's condition is strictly `<`) — the single most likely real-world support-ticket scenario.
11. **Concurrency: the atomic claim** — two parallel `redeem()` calls on the same `issued` voucher must yield exactly one success — this *is* the security property the whole engine is built around and has never been tested under real concurrency (only reasoned about).
12. Idempotent replay — same `idempotency_key` called twice returns the same result without a second redemption row.
13. Audit-row insert failure triggers a revert (`issued` restored) — the "never silently consume without a record" guarantee.
14. A corrupted check-character code is rejected **before** any DB call (must be asserted via a query-count/mock, not just the return value).
15. Pooled `cap_group` cap across multiple campaign slugs — the single most complex/least-obvious business rule in the file; an off-by-one here either over-issues (cost risk) or wrongly blocks valid claims (revenue risk) with nothing to surface it except a live incident.
16. `claim()` under concurrency at exactly the cap boundary — the code's own comment concedes a TOCTOU gap if the lock can't be taken; a test should assert intended behavior and flag if it's not achievable under all conditions.
17. `find_by_code()` exact-match must take precedence over typo-folding for codes containing real `0`/`1` characters — the docblock explicitly warns this ordering is load-bearing.

**Stripe verification** (`includes/class-doughboss-stripe.php`, `includes/class-doughboss-rest-controller.php:2173-2199`)
*(Correction: `verify_payment()` lives on the REST controller, not on `DoughBoss_Stripe`.)*
18–21. `verify_webhook_signature()`: valid signature accepted; tampered payload rejected; stale timestamp (>300s tolerance, the real production default) rejected; malformed header handled gracefully with no PHP warning.
22. `retrieve_payment_intent()` rejects a non-`pi_`-prefixed id **before** making any HTTP call (prevents attacker-controlled URL path injection).
23–25. `verify_payment()` rejects amount mismatch; rejects currency mismatch even when amount matches; blocks PaymentIntent replay via `payment_intent_used()` — this last one is the single highest-value payment-integrity test in the codebase (stops one card charge being split into multiple free orders).

**`DoughBoss_Coupon_Code`** (`includes/class-doughboss-coupon-code.php`)
26–30. `generate()`→`validate()` round-trip sanity; single-character corruption is detected; transposed body parts — **must be verified empirically, not assumed**, since the docblock's "catches transposed parts" claim depends on how re-basing indexes interact with a swap; legacy/unknown-format codes always pass (pins backward compatibility); `normalize()`'s two-stage fold mapping (`O→0→Q`, `I→1→7`) matches exactly — an easy target for a "simplify this" regression.

**`DoughBoss_Order::create()`** (`includes/class-doughboss-order.php:80-181`)
31–36. Happy path (order + all items committed atomically); item-insert failure triggers full rollback **including the already-inserted order row** (the exact guarantee the docblock claims); order-number collision retry succeeds; retry budget exhausted returns a clean 500, not a crash; empty `$lines` short-circuits before any DB write; `doughboss_order_created` fires exactly once, only after commit, only on success (every downstream integration — POSPal, printer, notifications — depends on this never firing on a rolled-back order).

### Manual QA checklist (staging, Stripe test mode only)

**Setup:** confirm test-mode Stripe keys, sandbox POSPal credentials (never the production account, given `/pospal/probe-grant`'s brute-force behavior), and record the current `doughboss_settings` option before testing.

**Cart & pricing:** add a menu item + custom pizza, confirm merge/quantity-increment; confirm a fresh cookie issues a new cart; toggle pickup↔delivery and confirm the fee/total recompute without trusting a stale client-side total.

**Voucher redemption:** claim a campaign code; confirm the discount preview matches type/value/min-spend; complete checkout and confirm status flips `issued→redeemed` with a redemption row; attempt reuse and confirm the generic "not valid" message (no enumeration leak); abort/kill the connection after redemption but before order creation and confirm the code is NOT lost (tests `revert_redemption()`); exhaust a campaign's (and a shared `cap_group`'s) daily cap and confirm rejection.

**Stripe:** complete checkout with `4242 4242 4242 4242` and confirm `payment_status='paid'`; use the decline test card `4000 0000 0000 0002` and confirm no order is created; replay a captured `payment_intent_id` against a second checkout and confirm 409; tamper the client-submitted total via devtools and confirm 402 (server catches the amount mismatch); if reachable, fire a real test-mode webhook event for a catering deposit and confirm status advances without the customer present.

**POSPal:** confirm a new order appears in the till/back-office with correctly normalized item names/prices; confirm an online voucher redemption revokes the mirrored in-store coupon so it can't double-spend across channels; attempt to redeem an already-consumed voucher at the till and confirm it's blocked there too.

**Settings:** after any of the above, confirm all 7 secret fields (4 Stripe + 3 POSPal) render blank/masked (today's fix #3) — no automated test enforces this, so it must be eyeballed every release.

**Kitchen board:** confirm a new order appears within one ~7s poll cycle and ack/accept transitions update the right timestamps.

### Release-readiness checklist

- [ ] `php -l` clean (`scripts/dev-check.sh`) — necessary, not sufficient.
- [ ] Full manual checkout+voucher+POSPal walkthrough on staging, Stripe test mode.
- [ ] Manually verify all 7 secret fields render blank/write-only post-save.
- [ ] Manually verify a Settings save that skips `app_origin`/`voucher_campaigns` leaves them intact (regression check for today's fix #2) — inspect the raw option via WP-CLI before/after.
- [ ] Manually verify a fresh guest cart survives a full checkout in a browser that's never had the cookie before (regression check for today's fix #1).
- [ ] Confirm `DOUGHBOSS_DB_VERSION` was bumped if any schema changed, with an ordered migration step.
- [ ] Diff `build-zip.sh`'s output file list against the previous release.
- [ ] Grep the diff for leftover `error_log`/`var_dump`/`print_r` debug calls.
- [ ] Re-run the full manual QA checklist for any Stripe/POSPal code change — don't assume "it compiled" is sufficient.
- [ ] A second human clicks through the storefront checkout on the actual build artifact (the zip), not just the working tree.

### Areas too risky to modify without tests existing first
1. `DoughBoss_Voucher::redeem()`/`claim()` — atomic-claim/idempotency/audit-revert logic; a "small" refactor could silently reintroduce double-spend.
2. `verify_payment()` + `retrieve_payment_intent()` — any change to the amount/currency comparison or the replay guard is a direct path to free orders or double-charged customers.
3. `DoughBoss_Order::create()` transaction boundaries — moving the transaction calls relative to the insert loop risks silent partial writes.
4. `DoughBoss_Cart::totals()` GST branch logic — CLAUDE.md itself flags that changing tax logic must preserve both branches; a "simplify this" pass is likely to collapse them incorrectly without a pinning test.
5. `DoughBoss_Coupon_Code::check_char()`/`normalize()` — the fold-mapping order is exactly the kind of code a well-intentioned cleanup breaks by merging the two `strtr` calls into one.
6. `class-doughboss-rest-controller.php` generally — any structural refactor is itself high-risk without characterization tests first, since manual re-testing of every route by hand is the only current safety net.

### Coverage rating & recommended first-testing effort

**Current coverage: 0%**, confirmed by exhaustive search — not "low," literally zero.

- **Prerequisite (½–1 day):** stand up a minimal PHPUnit harness (dev-only `composer.json`, `wp-phpunit`/polyfills or a lightweight `$wpdb`/`WP_Error` stub layer, `tests/bootstrap.php`) — this is the single biggest reason coverage is zero today; there's no harness to write into yet.
- **Day 1:** pure-logic unit tests needing no DB — `DoughBoss_Coupon_Code` (cases 26-30) and `DoughBoss_Cart::totals()` arithmetic (cases 1-7, with `evaluate()`/`Settings` stubbed). ~15-20 test methods, half a day to a day.
- **Day 2:** `DoughBoss_Voucher::evaluate()`'s pure branches (cases 8-10, no DB writes needed); then `DoughBoss_Order::create()`'s happy path + empty-lines guard against a real or SQLite-shimmed test DB.
- **Day 3+:** concurrency/transactional cases — atomic claim under two connections (case 11), rollback-on-partial-failure (case 32), collision retry (33-34), webhook signature vectors (18-21, cheap once the harness exists). These are the most likely to reveal a real bug — the pooled `cap_group` cap (case 15) in particular is complex enough to bet on a live discrepancy if load-tested.
- **Total realistic first pass to reach "the money-handling code has a safety net": roughly 1 to 1.5 weeks** of focused effort — the absence of any existing scaffolding is itself the main cost, not the individual test logic.

---

## Task 8: Deployment Readiness

**Scope note:** no live-site tool is connected in this environment — nothing below is a claim about the actual state of doughboss.com.au; everything is derived from static analysis of the repo. Where general MySQL/WordPress runtime behavior is referenced that couldn't be executed in this sandbox (no `mysql`/`mysqld` binary present), it's stated as documented behavior, not reproduced fact.

### Findings

| # | Finding | Severity |
|---|---|---|
| 1 | Plugin globally disables WordPress's default REST CORS handling, site-wide, for every request — `remove_filter('rest_pre_serve_request', 'rest_send_cors_headers')` fires on every `rest_api_init`, not just DoughBoss routes; for any other route on the site (core `/wp/v2/*`, other plugins' namespaces) or any request where `app_origin` doesn't exactly match the caller, **zero CORS headers are sent at all** — WordPress's permissive-but-scoped default is simply gone | **High** |
| 2 | Two independently-maintained "default settings" arrays have drifted — the activator's one-time seed (`delivery_fee => 5.00`) disagrees with `Settings::defaults()`'s runtime fallback (`delivery_fee => 0`); masked today by `wp_parse_args` merge, but every setting added since (`app_origin`, `payments_enabled`, all `stripe_*`/`pospal_*`/`mercure_*`/`ntfy_*` keys) had to be remembered in one file and was never mirrored in the other | Medium |
| 3 | Order + catering confirmation emails send synchronously inline in the checkout request, gating customer-facing latency on SMTP round-trips; `wp_mail()`'s return value is never checked — the one place in the codebase that breaks the "log every integration failure" convention the other six integrations follow | Medium |
| 4 | `readme.txt` is stale and self-contradicting — `Stable tag: 2.12.1` vs. actual `2.12.3`; its own FAQ says *"[payments] not yet... planned for a future release"* while its own Changelog documents Stripe as shipped since 2.5.0; never mentions catering/vouchers/POSPal at all | Medium |
| 5 | `build-zip.sh` (the only build process) does zero consistency checks — doesn't verify `readme.txt`'s `Stable tag` matches `DOUGHBOSS_VERSION` (exactly how #4 ships unnoticed), doesn't re-run `php -l` over the staged tree, doesn't verify the zip is openable. It does correctly exclude `demo/`/`app/`/`docs/`/`.claude/` from the shipped zip — confirmed no dev/internal artifacts leak | Low-Medium |
| 6 | `scripts/dev-check.sh` is confirmed `php -l`-only and always exits 0; it is invoked **only** by a Claude Code `SessionStart` hook (`.claude/settings.json`) — not by any CI. The only GitHub workflow (`pages.yml`) deploys the static demo, touching none of `includes/`/`admin/`/`public/`. There is no CI enforcement point of any kind for the plugin code | Informational (corroborates known weakness) |
| 7 | `0000-00-00 00:00:00` datetime defaults on `orders`/`catering_enquiries`/`voucher_redemptions` — safe on ordinary WordPress execution (core strips the relevant strict-mode flags from the session for its own historical reasons — documented WP-core behavior, **not reproduced in this sandbox**, no MySQL binary available to verify), but a landmine for anything outside that path: a host restricting `SET SESSION` privileges, a DB restore/import tool, or a strict replica reprocessing binlogs | Low-Medium |
| 8 | No health/status/version REST endpoint; monitoring is exclusively 11 ad-hoc `error_log()` call sites across 9 files, all uncorrelated, all unstructured, with no aggregation | Low |

### Corroborated existing weaknesses (new specifics, not re-litigated)
- Rate limiter keys on raw `REMOTE_ADDR` with a non-atomic increment (Backend #4) — additionally noted here: it's DB-backed via `wp_options` (correct across a multi-web-server deployment sharing one DB), but depends on WP-Cron's `delete_expired_transients` actually running to avoid unbounded row growth if `DISABLE_WP_CRON` is set with no replacement system cron.
- No multisite network-activation handling — not flagged as a blocker; the plugin is architecturally single-tenant and isn't marketed as multisite-aware.
- Indexed `varchar(191)` columns are correctly sized for the InnoDB/utf8mb4 767-byte prefix limit — a **strength**, checked specifically because it's a classic `dbDelta` pitfall, confirmed correct everywhere in the activator.

### Recommended fixes
- **#1:** Never `remove_filter` the core CORS callback — either let it run first and supplement it for the plugin's own namespace, or fall through to `rest_send_cors_headers()` for every non-DoughBoss route inside the replacement callback. **Keep unchanged:** the underlying goal (Staff Console PWA calling `doughboss/v1` cross-origin via a single named allowed origin, no wildcard) — sound and credential-safe. **Test both** the console's own cross-origin flow and an unrelated REST caller before/after any fix.
- **#2:** Make the activator's seed call `DoughBoss_Settings::defaults()` directly, merged with just the demo-content overrides it actually needs. **Risk:** a naive merge would empty out the seeded example `sizes`/`toppings` (non-empty in the activator, empty in `defaults()`) — a regression in new-store first-run UX; any consolidation must explicitly preserve the demo seed content.
- **#3:** At minimum log `wp_mail()` failures; consider deferring both sends via `wp_schedule_single_event()` or a `shutdown` hook. **Risk:** cron-based deferral is only as reliable as WP-Cron actually firing on the target host — confirm before relying on it.
- **#4:** Bump `Stable tag`, refresh `Tested up to`, rewrite the payments FAQ, mention catering/vouchers/POSPal. Pure content fix, tie to the release step below so it doesn't drift again.
- **#5:** Add ~10 lines to `build-zip.sh`: grep-diff `DOUGHBOSS_VERSION` vs. `Stable tag` and fail on mismatch; re-run `php -l` over the staged tree before zipping. **Keep unchanged:** the explicit allow-list `cp` approach — don't convert to an npm/Composer step.
- **#6:** Not a blocker, but if CI enforcement is wanted, add a *separate* workflow that runs `php -l` as a required PR check — don't change `dev-check.sh`'s always-exit-0 contract, which is deliberate for its SessionStart role.
- **#7:** Switch the three columns to `datetime NULL DEFAULT NULL` (the pattern already correctly used elsewhere in the same tables) and set `current_time('mysql', true)` explicitly on insert. **Risk:** confirm the PHP insert paths always supply an explicit value first.
- **#8:** Add a `verify_admin`-gated `GET /doughboss/v1/status` reporting DB connectivity, schema version, and each integration's `*_ready()` boolean. **Risk:** must not be `__return_true` like the public menu routes — would leak which integrations are configured to an unauthenticated caller.

### Deployment Readiness Score: **62 / 100**

**Earning points:** dependency-free, no-build-pipeline architecture matching its own stated conventions exactly; a real checkpointed, `Throwable`-contained migration runner with a concurrency lock; correct `varchar(191)` index sizing; confirmed server-side price recomputation and `$wpdb->prepare()` discipline; a safe explicit-allow-list build script; three real bugs found and fixed *before* today's review reached production.

**Holding it back:** a site-wide CORS side effect that could silently break unrelated REST integrations on activation (#1); zero automated regression coverage and zero CI enforcement of even the syntax lint that exists (#6); a stale, self-contradicting `readme.txt` (#4); synchronous unlogged email sends inside the checkout critical path (#3); a config-drift trap between two "defaults" sources (#2); a DB-default landmine whose safety depends on WordPress core internals the plugin doesn't control (#7).

None of these are "the app is down" blockers on a normal WordPress host — the real risk profile is **silent, hard-to-diagnose failure**: broken CORS for an unrelated integration nobody thinks to blame on DoughBoss; an installer reading a readme that says payments aren't supported; a delivery fee that's $5 instead of the code's own stated $0 default; a vanished order-confirmation email with no log line.

### Blockers before deployment (priority order)
1. Fix CORS (#1) before installing on any site with other REST consumers (headless frontend, mobile app, other plugins).
2. Reconcile `readme.txt` (#4) — at minimum the Stripe FAQ contradiction and version mismatch — before this is shown to any client.
3. Confirm a real backup exists (UpdraftPlus per CLAUDE.md, or host-level) before activating/upgrading on a site with existing order data — there is no rollback mechanism in the migration runner beyond "leave the version at the last successful checkpoint and retry"; a partially-applied migration has no automated undo.
4. Not a hard blocker but do before go-live: document the config-drift items (#2, and the vendor-personal defaults for `orders_email`/`app_origin`) as an explicit first-run checklist item — otherwise a new client site could silently email order data to the original developer's inbox by default.

### Recommended deployment process
1. **Pre-build gate:** run `scripts/dev-check.sh` manually, require `RESULT: PASS`; grep-diff `DOUGHBOSS_VERSION` vs. `Stable tag` and fail the release if they differ.
2. **Build:** `bash build-zip.sh` → `dist/doughboss.zip`.
3. **Staging verification:** deploy to a draft theme on a staging copy (via WPVibe, per CLAUDE.md's own documented tool) — never straight to production — and smoke-test menu load, cart/checkout (with and without Stripe), order tracking, Live Order Board, and specifically a cross-origin check against the Staff Console to catch any CORS regression.
4. **Confirm backup** immediately before activating on production — the only rollback story available.
5. **Deploy:** upload the zip via Plugins → Add New → Upload → Activate/Replace; first request after activation runs `DoughBoss_Migrations::run()` automatically.
6. **Post-deploy:** tail the host's PHP error log for `DoughBoss` lines for the first few orders; place one real test order and confirm both confirmation emails arrive (no automated signal exists for this).
7. **Rollback plan:** restore the pre-deploy backup if the upgrade misbehaves — there is no in-plugin downgrade path; `doughboss_db_version` only moves forward.

---

*All findings in this document are derived from direct reading of the repository at `/home/user/DOUGHBOSSV2`, branch `claude/funny-goodall-gsoog4`. No claim in this document describes the verified state of the live production site at doughboss.com.au — no live-site tool was available during this review.*
