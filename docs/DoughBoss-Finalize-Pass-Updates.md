# DoughBoss — Finalize Pass Updates

**Scope:** every change in this pass (backend audit fixes + demo polish), described from the actual working-tree diff.
**Audience:** owner first (plain English), then developers (file references).

---

## 1. What shipped, in plain English

This pass closes the money-safety and data-safety gaps flagged by the 10-role audit and polishes the public demo. The big one: if a customer's card is ever charged but their order never lands (browser crash, dropped connection), the site now catches it — Stripe reports the payment back to the site, and any payment with no matching order is flagged on the Orders screen so you can contact the customer or refund them. You can now also refund a card order in one click, see a proper **Reports** page (revenue, top sellers, CSV for the accountant), and answer privacy requests through WordPress's built-in Tools. Under the hood, slow spots were batched or made non-blocking so checkout and the kitchen board stay fast, and the demo site got accessibility and presentation polish. No customer money ever moves automatically — refunds and flagged payments always wait for a human decision.

---

## 2. Backend / money & data safety

- **Stripe webhook reconciliation (safety-net for "money taken, no order").** The site now listens for Stripe's own `payment_intent.succeeded` reports on a new signature-verified endpoint. A succeeded payment with no matching order is recorded for review; catering deposit/balance events are handled by the same endpoint too, so one Stripe webhook covers the whole site. It never creates orders and never auto-refunds.
  - `includes/class-doughboss-rest-controller.php` — new `POST /doughboss/v1/stripe-webhook` route + `stripe_webhook()`, shared `reconcile_catering_intent()`, and `record_unreconciled_payment()` (capped, non-autoloaded `doughboss_unreconciled_payments` option written under a named MySQL lock so a concurrent admin prune can't drop an entry). Settings help text updated in `admin/class-doughboss-admin.php`; `uninstall.php` deletes the option.
- **"Payment issues" surface on the Orders screen.** Flagged payments appear in a red card at the top of DoughBoss → Orders with the PaymentIntent id, amount and time, plus a nonce-checked "Clear list — all handled" button. False alarms are pruned automatically: the list re-checks whether each payment has since become an order and holds entries back for a 5-minute race window (the webhook usually races the normal checkout).
  - `admin/class-doughboss-admin.php` — `unreconciled_payments()` (lock-guarded prune) + `handle_clear_payment_issues()`.
- **One-click card refund.** Paid Stripe orders show a "Refund" link in the Orders list (with a confirm dialog). The PaymentIntent id is always read from the stored order row — never from the request — and only verified card-paid orders are eligible; the order is then marked `refunded`. A voucher used on the order is deliberately **not** auto-reissued (flash notice says so).
  - `admin/class-doughboss-admin.php` — `handle_refund_order()` → `DoughBoss_Stripe::create_refund()`; `includes/class-doughboss-order.php` — new whitelisted `update_payment_status()`.
- **Atomic rate limiter.** The REST rate limiter's read-increment-write is now serialized per bucket+IP with a named DB lock (same pattern as the voucher claim), so concurrent requests can't under-count. If the lock can't be taken it fails open — availability over strictness.
  - `includes/class-doughboss-rest-controller.php` — `rate_limited()`.
- **Privacy Tools (GDPR/APP requests).** WordPress's Tools → Export/Erase Personal Data now covers DoughBoss orders, catering enquiries and vouchers, keyed on customer email. Erasure **redacts** name/email/phone/address in place and never deletes rows — order numbers and financial totals survive (AU tax law expects ~5-year retention).
  - New `includes/class-doughboss-privacy.php`, wired in `includes/class-doughboss.php`.
- **Migration 1.8.0 (schema hygiene).** `DOUGHBOSS_DB_VERSION` 1.7.0 → 1.8.0: the invalid `'0000-00-00'` datetime defaults on orders/catering/vouchers/redemptions become `NULL DEFAULT NULL` (altered explicitly for existing installs — dbDelta won't retro-change defaults), and `balance_intent_id` on the catering table gets an index so webhook lookups stop table-scanning.
  - `doughboss.php`, `includes/class-doughboss-activator.php`, `includes/class-doughboss-migrations.php` — `upgrade_to_1_8_0()`.
- **REST input hardening.** `/checkout` and `/voucher/issue` now declare per-field `sanitize_callback` args (numeric casts wrapped in closures — bare `floatval` fatals as a REST sanitize callback on PHP 8).
  - `includes/class-doughboss-rest-controller.php` — route `args` blocks.
- **Dev-only POSPal diagnostics locked away.** `/pospal/test-grant`, `/pospal/test-revoke` and `/pospal/probe-grant` (which touch real coupons on the till) are now registered only under `WP_DEBUG`, and the matching Settings "Test grant" row is hidden the same way. The read-only handshake checks stay.
  - `includes/class-doughboss-rest-controller.php`, `admin/class-doughboss-admin.php`.
- **New `GET /status` health endpoint (admin-gated).** Plugin/DB versions (stored vs. code — a mismatch means a stuck migration) plus a ready/not-ready boolean per integration. Booleans only; no keys or URLs.
  - `includes/class-doughboss-rest-controller.php` — `get_status()`.
- **Smaller correctness fixes.** Failed `wp_mail()` calls are now logged (order + catering emails) instead of vanishing; percentage vouchers are clamped to 100% at issue time; catering enquiries reject past event dates and headcounts over 1,000; location slugs are de-duplicated on create (`bankstown-2`, …); the menu seeder matches items by a stable `_doughboss_seed_key` meta so a renamed item is updated, not duplicated, on re-run.
  - `includes/class-doughboss-rest-controller.php`, `includes/class-doughboss-voucher.php`, `includes/class-doughboss-catering.php`, `includes/class-doughboss-locations.php`, `includes/class-doughboss-menu-seeder.php`.
- **Release gate in the zip builder.** `build-zip.sh` now refuses to build if `DOUGHBOSS_VERSION`, readme.txt's Stable tag and its top changelog entry disagree, and `php -l` lints every staged file — a zip with a parse error or a stale version can no longer ship.

---

## 3. Performance

- **N+1 items query batched.** The kitchen board feed and the admin Orders list used to run one items query per order; both now use a single `IN (…)` query via the new `DoughBoss_Order::get_items_for_orders()` (same row shape as `get_items()`).
  - `includes/class-doughboss-order.php`, `admin/class-doughboss-admin.php`.
- **Non-blocking POSPal + SMS dispatch.** The POSPal order mirror and coupon revoke, and the ClickSend "order ready"/"voucher claimed" texts, are dispatched fire-and-forget (`blocking => false`) so a slow third party can never stall a customer's checkout or the kitchen's status tap. Logs now say "dispatched (non-blocking)"; delivery is not confirmed, by design.
  - `includes/class-doughboss-pospal.php` (`call()`, `push_order()`, `revoke_coupon()`), `includes/class-doughboss-pospal-orders.php`, `includes/class-doughboss-pospal-sync.php`, `includes/class-doughboss-sms.php` (`send()`).

---

## 4. New: Reports

The owner gets a **DoughBoss → Reports** page (same `manage_doughboss` capability as the rest of the group):

- **Date range** — defaults to the last 7 days; From/To date pickers + Apply. Cancelled orders are excluded from everything.
- **Headline cards** — Revenue, Orders, Average order value.
- **Pickup vs delivery** — order count and revenue per type.
- **Top items** — top 10 sellers by units, with revenue.
- **Download CSV** — nonce-checked export, one row per order (number, date, type, status, customer, totals, voucher, payment status). Customer-supplied cells are prefixed to neutralise spreadsheet formula injection.

Files: new `includes/class-doughboss-reports.php` (read-only aggregation, all SQL prepared), `admin/class-doughboss-admin.php` (`render_reports_page()`, `handle_export_report()`, `csv_cell()`). Documented in `docs/DoughBoss-Manual-Admin.md` §4.7.

---

## 5. Demo site & kitchen board polish

- **Honesty ribbons** — the backend, owner and staff demo pages now carry a fixed "Concept demo · simulated data" ribbon (`demo/backend.html`, `demo/owner.html`, `demo/staff.html`).
- **Simulated checkout/booking feel real** — placing a demo order and a catering booking now show a brief "Placing order…"/"Booking…" busy state, disable the button against double-clicks, and move focus to the confirmation for screen readers (`demo/menu-order.js`, `demo/catering-demo.js`).
- **Accessibility** — skip-to-content link on the storefront demo that focuses the active view without tripping the hash router (`demo/index.html`); aria-live status regions on the console app's toasts and the voucher-scan result (`app/app.js`, `public/js/doughboss-voucher-scan.js`).
- **Console app usability** — the login is now a real `<form>` (Enter submits from any field, password managers engage) and a "Reconnecting…" pill appears while any poller is failing and clears on the next good poll (`app/app.js`).
- **Legal pages restyled** — privacy, terms and licensing pages moved to the monochrome brand palette with tokenised font sizes, underlined links, horizontally-scrollable tables (`tbl-scroll`) and small-screen media queries (`demo/privacy.html`, `demo/terms.html`, `demo/licensing.html`).
- **Storefront CSS tidy** — `.btn`/`.vb-btn` share one geometry rule; the kitchen lock panel recoloured to the neutral scheme (`demo/demo.css`).
- **Kitchen board (real plugin)** — all order-board colours extracted to CSS custom properties (`--dbb-*`) for consistent theming, and `prefers-reduced-motion` now stops the two infinite animations while keeping a static ring/label as the visual cue (`public/css/doughboss-orderboard.css`).

---

## 6. What's still open

- **Register the webhook in Stripe.** The new `/stripe-webhook` endpoint only works once an endpoint subscribed to `payment_intent.succeeded` is added in the Stripe Dashboard and its signing secret saved in Settings. The plugin stores **one** signing secret per mode, so register exactly one endpoint (the new one is preferred; the older catering-only one still works if already registered).
- **Webhook handles `payment_intent.succeeded` only.** Failed/disputed/refund events from Stripe are not consumed; the webhook is a reconciliation safety-net, not yet the authoritative payment source-of-truth.
- **Refunds are full-order only and don't reissue vouchers.** Partial refunds and automatic voucher reissue remain manual (Stripe Dashboard / Vouchers page).
- **`DOUGHBOSS_VERSION` / readme.txt not yet bumped** — the orchestrator does one version bump covering the whole pass (only `DOUGHBOSS_DB_VERSION` moved, to 1.8.0, for the migration).
- This pass was adversarially reviewed, but a human should still run the staging checklist in `docs/DoughBoss-Manual-Deployment.md` before deploying — in particular: run the 1.8.0 migration on a staging copy, place a test card order, and exercise the webhook + Payment issues + Refund flow in Stripe test mode.
