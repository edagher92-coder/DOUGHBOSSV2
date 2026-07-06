# Project Audit and Improvement Report

**Project:** DoughBoss — commission-free pizza/food ordering WordPress plugin
**Version audited:** 2.12.3 (DB schema 1.7.0) · **Branch:** `claude/funny-goodall-gsoog4` · **PR:** #2 (draft)
**Scope:** full static review of `/home/user/DOUGHBOSSV2` across data quality, architecture, front-end, UI/UX, back-end, QA/testing, and DevOps/deployment readiness. No live-site tool is connected in this environment — every claim below is grounded in the repository as committed, not in the observed behavior of doughboss.com.au.

---

## 1. Executive Summary

DoughBoss is a mature, feature-rich WordPress plugin (26 classes, ~13,561 lines in `includes/`, ~1,850 lines in `admin/`) built with real domain discipline: an atomic voucher-redemption engine, transactional order creation with collision retry, server-side price recomputation everywhere, and a consistent dependency-free pattern across six external integrations (Stripe, POSPal, Mercure, ntfy, ClickSend, printer). Three genuine bugs — a cart-token case mismatch that silently dropped the first cart write of nearly every new session, a settings-save path that deleted `app_origin` and `voucher_campaigns` on any save that didn't touch them, and Stripe/POSPal secret keys being echoed into admin HTML — were found and fixed today (shipped in 2.12.3, not yet deployed live).

Six independent specialist passes (data, architecture, front-end, UI/UX, back-end, QA, DevOps) surfaced a consistent second-order pattern: **good conventions established once are not mechanically propagated to newer subsystems.** The voucher engine's "till can't create value" boundary wasn't carried to catering-status endpoints. The env-first secrets pattern used for five integrations wasn't applied to Stripe, the most sensitive one. The rate limiter protects checkout and vouchers but not payment-intent creation, the most expensive call in the system. The catering Stripe flow has a webhook safety net; the main storefront checkout does not, despite carrying identical risk.

The single most material fact governing the Final Decision (§12) is this: **automated test coverage is 0%** (no `tests/`, no PHPUnit, no `composer.json`, no JS test runner — confirmed by exhaustive search) and `scripts/dev-check.sh`, the only verification tool, is syntax-lint-only and is documented to always exit 0 regardless of outcome. Combined with the fact that **no live-site tool is connected in this environment**, nothing in this report can be verified against production behavior — every money-path guarantee described here (voucher atomicity, payment replay protection, GST math) has been checked by reading the code, never by executing it.

CLAUDE.md, the project's own memory file, is materially stale: it says v2.5.0/DB v1.4.0 (actual v2.12.3/v1.7.0), says "3 custom tables" (actual 6), twice states catering "has zero code" (actual: 836 lines, a full enquiry→quote→deposit→balance pipeline with a working Stripe webhook), and lists Stripe webhook/refunds/push-transport/receipt-printing as "not yet implemented" (all four are shipped, off by default). This isn't cosmetic — it is actively capable of misleading the next engineer or reviewer into re-building or mis-scoping work that already exists.

---

## 2. Overall Project Health Score

| Dimension | Score /100 | Justification |
|---|---|---|
| Code quality | 68 | Domain logic (vouchers, checkout transactions, migrations) is well-reasoned; undermined by a 2,699-line god-class REST controller, zero DI/interfaces anywhere, duplicated HTTP-response-parsing boilerplate across 5 integrations, and 4 divergent DOM-builder reimplementations in JS. |
| Data quality | 62 | Sound schema fundamentals (`varchar(191)` index-safe, transactional writes) but no reporting/aggregation exists at all, `currency_code` fallback hardcodes `'USD'` against the plugin's AUD-only design, zero GDPR/Privacy-Act export/erase hooks for stored PII, and `create_refund()` is a fully-built orphaned stub never called. |
| Security | 65 | Strong baseline (nonces, capability checks, `$wpdb->prepare()`, HMAC webhook verification, opaque anti-enumeration errors) offset by a site-wide CORS side effect that strips WordPress's default CORS handling for every REST route, a privilege-boundary gap letting a kitchen-tablet account change catering payment status, an unthrottled payment-intent endpoint, and a non-atomic rate limiter. |
| Performance | 60 | N+1 query pattern on the two highest-frequency read paths (Live Order Board ~7s poll, admin Orders list), fully synchronous checkout blocking on two `wp_mail()` calls plus a 25s-timeout POSPal push and 20s ClickSend SMS with no queue/retry, and a storefront that fully tears down and rebuilds the cart/checkout DOM (including remounting Stripe Elements) on every cart mutation. |
| UI/UX | 58 | The wp-admin screens are genuinely strong (native WP chrome, good empty/error states); the demo site runs three unreconciled brand-color systems across 8 pages, has zero responsive breakpoints on 3 legal pages with unwrapped wide tables, and is missing a skip link on its busiest page. |
| Testing | 12 | Confirmed 0% automated coverage; the only "check" that exists is a syntax lint documented to always exit 0. The 12 is not a rounding error — it reflects that manual QA checklists exist and are well-specified, but nothing executes automatically, ever. |
| Documentation | 45 | 30+ docs files plus 5 new manuals and this report are a real asset, but the canonical CLAUDE.md is stale enough to misstate the plugin's version, table count, and feature completeness — the exact document meant to onboard the next engineer is the least trustworthy one. |
| Deployment readiness | 62 | Matches the DevOps specialist's own scored assessment: solid no-build-pipeline fundamentals, a safe explicit-allowlist build script, and a real migration runner, offset by the CORS finding, a self-contradicting `readme.txt`, and zero CI enforcement of anything beyond a local session hook. |
| Maintainability | 60 | 26 single-responsibility classes and a consistent integration pattern are real strengths; static-call-only architecture (zero DI, zero interfaces) means the codebase structurally resists the test harness it most needs, and the REST controller's size makes it the hardest file in the repo to safely change. |
| **Overall readiness** | **58** | A functionally rich, thoughtfully-built plugin with real money-path correctness in its core (vouchers, checkout transactions) that is not yet safe to treat as "done" — the gaps are concentrated in verification (zero tests), a few propagation misses of the project's own good patterns, and stale documentation, not in a lack of underlying engineering competence. |

---

## 3. Critical Issues

### 3.1 Storefront checkout has no reconciliation path for a payment that succeeds but never becomes an order
- **Location:** `includes/class-doughboss-rest-controller.php:1674-1712` (`create_payment_intent`), `:1982-2145` (`checkout`), `:2173-2199` (`verify_payment`) — contrast with `:2558-2588` (`catering_stripe_webhook`, the only Stripe webhook in the plugin).
- **Severity:** High
- **Why it matters:** If a customer's card is charged via `confirmCardPayment` but the browser never completes the follow-up `/checkout` call (closed tab, dropped connection, dead device), Stripe has captured real money and DoughBoss has no order, no record, and no automated way to ever notice. `DoughBoss_Stripe::create_refund()` is fully implemented (`class-doughboss-stripe.php:134-146`) but has zero call sites anywhere in the repo. Compounding this, the customer-facing copy in `verify_payment` (`:2195`) and `catering_confirm_payment` (`:2530`) both claim *"If you were charged it will be reversed automatically"* — untrue for both flows.
- **Recommended fix:** Add a `/stripe-webhook` route mirroring the catering one; on `payment_intent.succeeded`, check `DoughBoss_Order::payment_intent_used()` and, if no order exists after a grace period, surface it in an admin "unreconciled payments" list or auto-fire `create_refund()`. Fix the misleading customer copy immediately regardless.
- **Risk if ignored:** Real, unrecoverable customer financial harm with no operational visibility — a customer can be charged and receive nothing, and the shop has no way to find out short of a manual Stripe dashboard audit.

### 3.2 `create_payment_intent` skips the business-rule gates that `checkout` enforces
- **Location:** `includes/class-doughboss-rest-controller.php:1674-1712` vs. `:1998-2014`.
- **Severity:** High
- **Why it matters:** `checkout()` checks `ordering_open()`, `enable_delivery`, and `enable_pickup` before trusting a payment; `create_payment_intent()` has none of these checks. A customer can be charged while the shop is closed or for a delivery/pickup mode the shop has disabled, discovering the refusal only after money is already captured — a second, more common route into the same orphaned-payment state as 3.1 (no dropped connection required, just normal use during a closed window).
- **Recommended fix:** Move `ordering_open()`/`enable_delivery`/`enable_pickup` checks into `create_payment_intent()`, or factor them into a shared private method both handlers call first.
- **Risk if ignored:** Same financial-harm class as 3.1, triggered by ordinary operating-hours edge cases rather than network failure.

### 3.3 Plugin globally disables WordPress's default REST CORS handling for every request, site-wide
- **Location:** `includes/class-doughboss-rest-controller.php:52-53` (`enable_cors()`), `:64-79` (`send_cors_headers()`), wired unconditionally via `includes/class-doughboss.php:123`.
- **Severity:** High
- **Why it matters:** `enable_cors()` calls `remove_filter('rest_pre_serve_request', 'rest_send_cors_headers')` and replaces it with a callback that only emits CORS headers for `doughboss/v1` routes matching the configured `app_origin`. For every other REST route on the site — core `/wp/v2/*`, any other plugin's namespace — WordPress's permissive-but-scoped default CORS handling is gone entirely, with no fallback. Any other plugin or headless/JS consumer relying on default WP REST CORS behavior silently breaks the moment DoughBoss is activated.
- **Recommended fix:** Never `remove_filter` the core callback. Either let the default run first and supplement it for `doughboss/v1` only, or fall through to calling `rest_send_cors_headers()` for every non-DoughBoss route inside the replacement callback.
- **Risk if ignored:** A regression outside the plugin's own feature surface that nobody would think to blame on DoughBoss — the hardest class of bug to diagnose.

### 3.4 Catering enquiry status changes are reachable by the kitchen/KDS role, breaking the project's own "till can't create value" boundary
- **Location:** route registration `includes/class-doughboss-rest-controller.php:711-717` (`/admin/catering/{id}/status` → `verify_admin`, which explicitly ORs in `manage_doughboss_kds`); contrast `verify_manage()`'s doc-comment (`:821-833`), written specifically so "a till device can never create value."
- **Severity:** High
- **Why it matters:** `DoughBoss_Catering::update_status()` can set an enquiry to `PAID`, `CONFIRMED`, or `LOST` — real financial/business state. The route is gated with the same permission level as kitchen-floor order-board actions, meaning a shop-tablet-only account can mark a catering job paid or dead. This is the identical class of bug the team already fixed for vouchers, just not propagated here.
- **Recommended fix:** Change the permission callback to `verify_manage` (owner-only), matching the voucher precedent. Leave `verify_admin` untouched on the genuinely kitchen-floor routes (`admin_update_status`, `admin_acknowledge`, `admin_accept`).
- **Risk if ignored:** A compromised or misused kitchen tablet login can manipulate catering financial state with no owner-level gate.

### 3.5 Zero automated test coverage across the entire codebase
- **Location:** repo-wide — confirmed no `tests/`, no `phpunit.xml`, no `composer.json`, no `package.json`, no JS test runner; `scripts/dev-check.sh:7-9,57-62` is syntax-lint-only and documented to always exit 0 regardless of outcome; the only CI workflow (`.github/workflows/pages.yml`) deploys the static demo site, not the plugin.
- **Severity:** Critical (structural, not a single bug)
- **Why it matters:** Every money-path correctness guarantee in this plugin — voucher atomic-claim/idempotency/revert, PaymentIntent replay protection, order-creation transaction rollback, GST math — has been verified only by manual reading, never by an executable assertion. There is no safety net against a future "small" refactor silently reintroducing a double-spend, a double-charge, or a partial order write.
- **Recommended fix:** Stand up a minimal PHPUnit harness (dev-only `composer.json`, not shipped in `build-zip.sh`'s output) and write the ~36 concrete test cases the QA specialist already enumerated, prioritized: pure-logic tests first (coupon-code check-character, cart GST math), then voucher/order transactional tests, then Stripe webhook signature vectors. Realistic estimate: 1–1.5 weeks for the first pass that gives the money-handling code an actual safety net.
- **Risk if ignored:** Every subsequent change to the checkout/voucher/payment code paths carries unquantifiable regression risk indefinitely.

### 3.6 CLAUDE.md (the project's own memory file) is stale in ways that actively mislead
- **Location:** `/home/user/.claude/CLAUDE.md` project section — states v2.5.0/DB v1.4.0 (actual v2.12.3/v1.7.0), "3 custom tables" (actual 6), catering "has zero code" (actual 836 lines, full pipeline with a working Stripe webhook), and lists Stripe webhook/refunds/push-transport/receipt-printing as "not yet implemented" (all four are shipped, off by default).
- **Severity:** High (process/documentation, but with real downstream cost)
- **Why it matters:** This is the file every future session — human or agent — reads first to orient itself. A stale claim of "catering has zero code" could cause an engineer to duplicate 836 lines of working pipeline from scratch, or a security reviewer to skip auditing a subsystem they believe doesn't exist.
- **Recommended fix:** Update CLAUDE.md's version numbers, table count, and roadmap section to match the current repo state as part of closing out this audit.
- **Risk if ignored:** Compounding wasted engineering effort and blind spots in every future review or feature built on top of this document.

---

## 4. Recommended Changes

| Recommendation | Area | Priority | Effort | Impact | Owner | Reason |
|---|---|---|---|---|---|---|
| Add Stripe webhook + reconciliation for main checkout; fix misleading "reversed automatically" copy | Back-end/Payments | P0 | M | High | Back-End Specialist | Closes the orphaned-payment financial-harm gap (Finding 3.1) |
| Move `ordering_open()`/`enable_delivery`/`enable_pickup` checks into `create_payment_intent()` | Back-end/Payments | P0 | S | High | Back-End Specialist | Prevents charging customers for orders the shop will refuse (Finding 3.2) |
| Fix CORS: stop removing WordPress's default `rest_send_cors_headers`, scope the replacement | Back-end/Security | P0 | S | High | Back-End Specialist | Stops a site-wide regression affecting unrelated REST consumers (Finding 3.3) |
| Change `/admin/catering/{id}/status` permission callback to `verify_manage` | Back-end/Security | P0 | S | High | Back-End Specialist | Restores the "till can't create value" boundary for catering (Finding 3.4) |
| Stand up a minimal PHPUnit harness + first ~15-20 pure-logic tests (coupon-code, cart GST math) | QA/Testing | P0 | M | Critical | QA Engineer | 0% coverage is the single largest structural risk in the project (Finding 3.5) |
| Correct CLAUDE.md's version, table count, and roadmap claims | Documentation | P0 | S | High | Engineering Manager | Stops the project's own memory file from misleading future work (Finding 3.6) |
| Rate-limit `create_payment_intent`, `catering_payment_intent`, `catering_confirm_payment` | Back-end/Security | P1 | S | High | Back-End Specialist | These are the only money-adjacent routes with zero throttle; siblings (checkout, vouchers) already protected |
| Add env-first (`getenv()`/constant) overrides for Stripe secret key/webhook secret, mirroring the existing POSPal pattern | Back-end/Security | P1 | S | Medium | Back-End Specialist | Stripe is the one integration that doesn't follow the project's own env-first secrets convention |
| Batch-fetch order items (single `IN (...)` query) instead of N+1 in `active_orders()` and admin Orders list | Back-end/Performance | P1 | S | Medium | Back-End Specialist | Highest-frequency polling path (Live Order Board, ~7s) currently issues up to 101 queries per poll |
| Make rate limiter's hit-increment atomic (DB counter or `GET_LOCK`, matching the voucher-claim pattern) | Back-end/Security | P1 | M | Medium | Back-End Specialist | Current get-then-set is a TOCTOU race that defeats the limiter under concurrent bursts, especially for voucher-guessing buckets |
| Move POSPal push + ClickSend SMS to `blocking => false` or WP-Cron; check `wp_mail()` return values and log failures | Back-end/Performance | P1 | M | Medium | Back-End Specialist | Checkout/status-update requests currently block up to 25s+20s on integrations whose result isn't needed synchronously |
| Split `draw()` in `doughboss.js` so cart-line mutations don't tear down/remount the checkout form and Stripe Elements iframe | Front-end | P0 | M | High | Front-End Specialist | Any quantity change while a customer is mid-checkout silently wipes their typed card number — a "lose the sale" bug |
| Add `aria-live`/`role="alert"` to both voucher-scan result regions and `app.js`'s toast | Front-end/Accessibility | P1 | S | Medium | Front-End Specialist | Primary till feedback loop gives zero spoken confirmation to screen-reader-using staff |
| Guard `doughboss-orderboard.css`'s two infinite animations with `prefers-reduced-motion` | Front-end/Accessibility | P1 | S | Medium | Front-End Specialist | Always-on kitchen display has no escape hatch for vestibular/photosensitive staff; pattern already exists elsewhere in the codebase |
| Stop the order board's 30s timestamp-refresh tick from doing a full DOM rebuild | Front-end/Performance | P2 | S | Low | Front-End Specialist | Avoidable CPU/battery churn on wall-mounted kitchen tablets |
| Align `app.js`'s 3 pollers and voucher-scan poller onto the order board's resolve-then-reschedule pattern; add visible "reconnecting" state to `app.js` | Front-end | P2 | M | Medium | Front-End Specialist | Stale-response races and silent connection failures on the Staff Console |
| Reconcile the demo site's brand color system across all 8 pages (or explicitly document the internal-tool palette as a deliberate split) | UI/UX | P1 | M | Medium | Product/UX Manager | Brand accent currently changes 3 times across 3 clicks between demo pages |
| Add responsive breakpoints + `overflow-x:auto` table wrappers to the 3 legal/contract demo pages | UI/UX | P1 | S | Medium | Product/UX Manager | Zero `@media` queries; 6-column tables unwrapped on a page a franchise prospect will open on a phone |
| Add a skip link to `demo/index.html` | UI/UX/Accessibility | P2 | XS | Low | Product/UX Manager | Pattern already built correctly on 3 other demo pages; just needs copying |
| Add "simulated demo" disclosure to `backend.html`/`owner.html`/`staff.html` | UI/UX | P2 | XS | Low | Product/UX Manager | These are the most realistic-looking, most likely to be shared standalone, and carry no on-page disclaimer |
| Add a Reports admin page (date-range revenue, AOV, top items) plus CSV export | Data/Reporting | P1 | M | High | Data Analyst | Zero revenue aggregation exists anywhere; owner cannot answer "how did we do this week" from wp-admin |
| Fix `currency_code` settings-save fallback from hardcoded `'USD'` to `'AUD'` or existing-value preserve | Data/Settings | P0 | XS | Medium | Data Analyst | Contradicts the plugin's AUD-only design; migration 1.3.0 was written specifically to fix this class of bug elsewhere |
| Register `wp_privacy_personal_data_exporters`/`_erasers` for orders/catering/vouchers PII | Data/Compliance | P1 | M | High | Data Analyst | Australian Privacy Act 1988 APP 11 applies regardless of GDPR; core WP Privacy Tools currently miss all DoughBoss data entirely |
| Wire `create_refund()` into an admin action; write `payment_status='refunded'` | Back-end/Data | P1 | M | Medium | Back-End Specialist | Fully-built refund capability has zero callers; orders show `paid` forever even after a manual Stripe-dashboard refund |
| Add `args`/`validate_callback` schemas to `/checkout` and `/voucher/issue` | Back-end/Security | P2 | S | Medium | Back-End Specialist | The two highest-stakes routes are the only ones relying entirely on inline manual validation with no structural schema |
| Add `KEY balance_intent_id` index via a version-gated migration | Data/Performance | P2 | XS | Low | Data Analyst | `OR` across one indexed/one unindexed column forces a full table scan on every Stripe webhook delivery |
| Switch `0000-00-00 00:00:00` datetime defaults to `NULL DEFAULT NULL` on orders/catering/voucher-redemptions | Data/Reliability | P2 | S | Low | Data Analyst | Currently masked by WordPress core's sql_mode stripping; a landmine for restores/imports outside that path |
| Add a `GET /doughboss/v1/status` health endpoint (admin-gated) reporting DB/integration readiness | DevOps | P2 | S | Medium | DevOps Engineer | Only monitoring signal today is ad-hoc `error_log()` across 9 files with nothing aggregating it |
| Add version/readme-drift check + re-run `php -l` inside `build-zip.sh` before zipping | DevOps | P1 | S | Medium | DevOps Engineer | `readme.txt`'s Stable tag and payments FAQ have already drifted from reality once; this prevents silent recurrence |
| Correct `readme.txt`'s version number and Stripe-payments FAQ | Documentation | P0 | XS | Medium | DevOps Engineer | Self-contradicts its own changelog; the file most likely to be read by an installer/client |
| Gate POSPal/Mercure diagnostic REST routes (incl. `/pospal/probe-grant`) behind `WP_DEBUG` | Back-end/Security | P2 | S | Low | DevOps Engineer | A brute-force-style diagnostic endpoint is permanently part of the production REST surface with no toggle |
| Extract a shared `DoughBoss_Http` request helper for the 5 integrations' duplicated response-parsing chain | Architecture | P2 | M | Medium | Engineering Manager | Same 6-10 line chain hand-rolled 5 times; a fix to one (timeout, redaction) currently needs 5 manual edits |
| Add 2-3 shared Settings-page field-render helpers (`render_secret_field()`, etc.) | Architecture | P2 | M | Medium | Engineering Manager | Today's secret-echo bug existed precisely because there was no structural default for the write-only pattern |
| De-dup `doughboss_locations.slug` on create + migrate existing collisions before promoting to `UNIQUE` | Data | P3 | S | Low | Data Analyst | Currently harmless (unused as a lookup key) but a latent landmine for a future feature |
| Reject `event_date < today` and cap `guest_count` in catering enquiries | Data | P3 | XS | Low | Data Analyst | Matches the existing `MAX_QTY` guard pattern already used in the cart |
| Clamp voucher `percent` type to ≤100% at issue time | Data | P3 | XS | Low | Data Analyst | `evaluate()` already clamps the discount; this just stops a nonsensical value being stored |

---

## 5. What Not To Change

- **Voucher engine's atomic claim, idempotency replay, and audit-revert logic** (`class-doughboss-voucher.php:254-347, 540-605`) — this is the best-designed subsystem in the codebase (conditional `UPDATE ... WHERE status='issued'`, mandatory audit row with revert-on-failure, idempotency-key replay). *Risk if changed:* a "small" reordering of the idempotency check vs. the eval check could silently reintroduce double-spend with no test to catch it. *Revisit when:* the PHPUnit harness (Finding 3.5) exists and cases 11-17 from the QA specialist's list are written first.
- **`DoughBoss_Order::create()`'s transaction boundaries and collision retry** (`class-doughboss-order.php:95-170`) — correctly wraps order+items in one transaction with 5-attempt collision retry. *Risk if changed:* moving `START TRANSACTION`/`COMMIT`/`ROLLBACK` relative to the insert loop risks silent partial writes that only surface as a mismatched-total support ticket days later. *Revisit when:* transactional test cases (31-36 in the QA list) exist.
- **`DoughBoss_Cart::totals()`'s GST-inclusive vs. exclusive branching** (`class-doughboss-cart.php:319-328`) — CLAUDE.md itself already flags this; the two branches are structurally different formulas, not a shared calculation. *Risk if changed:* a "simplify this" pass is likely to collapse them incorrectly. *Revisit when:* cart GST test cases (1-7 in the QA list) are written — these are cheap, pure-logic tests and a good first target.
- **`DoughBoss_Coupon_Code::check_char()`/`normalize()`'s fold-mapping order** (`class-doughboss-coupon-code.php:62-81, 149-187`) — the two-stage `strtr` (`O→0→Q`, `I→1→7`) is intricate by necessity. *Risk if changed:* a well-intentioned "simplify the two strtr calls into one" would silently break typo recovery for a subset of codes. *Revisit when:* cases 26-30 (round-trip, transposition, fold-mapping) exist — cheapest possible tests to write, no DB needed.
- **The Stripe PaymentIntent verify-then-create ordering, single-use enforcement, and idempotency-key replay cache in `checkout()`** — correct as designed; this is the actual defense against double-charging or free orders. *Risk if changed:* any edit to the amount/currency comparison or `payment_intent_used()` guard is a direct path to financial loss. *Revisit when:* Finding 3.1's webhook reconciliation is added — that is additive, not a change to this logic.
- **The catering payment/webhook design itself** (verify-on-return + webhook backstop + idempotent `mark_paid()`) — this is the correct pattern; the main checkout should be made to look like this, not the reverse.
- **The printer routes' `__return_true` + in-callback `hash_equals` token check** (`class-doughboss-printer.php`) — correct for a device that can't send a WP nonce; don't force it through `verify_nonce`. *Safe to revisit:* only if consolidating route *registration* into the main controller (Finding 4 in the architecture review) — keep the auth logic untouched even then.
- **Read-only public REST routes' `__return_true`** (`/config`, `/menu`, `/locations`, `/order/{n}`) — deliberate design (public storefront data; order tracking is gated by matching email instead). Do not add nonces here.
- **The "seed `$clean` from `$existing`, then re-validate known keys" settings-sanitize pattern** — this is today's fix for the settings-wipe bug; keep it as the foundation even while fixing the `currency_code` fallback (Finding in §4).
- **The explicit-allowlist `cp` approach in `build-zip.sh`** — safer by construction than a blanket copy-with-excludes; a newly-added dev-only file must be deliberately added to ship. Don't convert to an npm/Composer build step — this would violate the project's stated no-build-pipeline convention for no real benefit.
- **`dev-check.sh`'s "always exit 0" contract** — deliberate for its SessionStart-hook use case (never abort a Claude Code session). If CI enforcement is wanted, add a *separate* strict script, don't change this one's contract.
- **The demo site's scoped CSS custom-property re-scoping technique** (`--ember` redefined per dark-background section in `demo.css`) and its `:focus-visible`/`prefers-reduced-motion`/form-labeling discipline — genuinely well-executed, keep as the model for fixing the color-system fragmentation (§4), not something to rip out while fixing it.
- **`demo/menu-order.js`'s accessible modal** (focus trap, `Escape`-to-close, focus restored on close, `prefers-reduced-motion` guard) and its `if (drawerOpen && !checkoutMode)` guard against re-rendering under an open checkout — this is the exact discipline the storefront's real checkout needs (Finding 3, front-end); use it as the reference implementation when fixing `doughboss.js`.

---

## 6. Optimisation Plan

**Immediate (cheap, safe, high-value — do this sprint):**
- Fix the 4 P0 back-end/security items in §4 (checkout webhook copy fix, payment-intent gate checks, CORS, catering permission callback) — all are additive or narrowly-scoped changes with low regression risk.
- Fix `currency_code` fallback (`'USD'` → `'AUD'`/preserve-existing).
- Correct `readme.txt`'s version/FAQ and CLAUDE.md's stale claims.
- Rate-limit the three unthrottled payment-intent routes using the existing `rate_limited()` bucket pattern.
- Fix the checkout-form/Stripe-teardown DOM rebuild bug in `doughboss.js` (highest-severity front-end finding).

**Short-term (needs a small design decision, still additive):**
- Stand up the PHPUnit harness and write the pure-logic test tier (coupon-code, cart GST math) — no DB required, highest value per hour.
- Add env-first Stripe secret overrides mirroring the POSPal pattern.
- Batch-fetch order items to kill the N+1 pattern on the Order Board/admin Orders list.
- Move POSPal/SMS integration calls off the synchronous request path.
- Reconcile the demo site's 3-way brand color drift; add responsive breakpoints to the legal pages.
- Add the Reports/CSV-export admin page.
- Register WordPress Privacy Tools exporters/erasers for order/catering/voucher PII.

**Long-term (bigger design work, sequence after a test harness exists):**
- Wire `create_refund()` into a real admin refund action with voucher-reissue interaction handled explicitly.
- Extract the shared `DoughBoss_Http` helper and Settings field-render helpers — do these opportunistically alongside real feature work on those files, not as standalone refactors.
- Consider splitting the 2,699-line REST controller — but only after characterization tests exist, since this file is currently the hardest in the repo to safely change and has the least test coverage protecting it.
- Any partial dependency-injection introduction (start with `DoughBoss_Cart`/`DoughBoss_Voucher`/`DoughBoss_Stripe` accepting config as parameters) — pair with the money-path test harness, don't precede it.
- Converge the 4 divergent DOM-builder (`el()`/`make()`) implementations and plan real de-duplication between `doughboss-voucher-scan.js` and `app/app.js`'s scan feature — needs a design decision about code-sharing across a WP-enqueued script and a standalone no-build PWA.

---

## 7. UI/UX Improvement Plan

**Visual:**
- Reconcile the demo site's three unreconciled brand-color systems (`#111111` monochrome on storefront vs. `#d6502b`/`#b5571f` gold-amber on legal/staff/backend/owner pages) — either finish the monochrome rollout across all 8 pages or explicitly document the internal-tool palette split as deliberate.
- Remove the orphaned `var(--card, #fff)` / hardcoded `#b5571f` leftover in `.kitchen-lock` (`demo.css:613-614`) — a literal miss from the otherwise-thorough rebrand pass.
- Unify the `.btn` component's geometry (border-radius/padding/font drift across `demo.css`, `franchise.html`, `staff.html`, `backend.html`) into one canonical block copy-pasted consistently.
- Tokenize `doughboss-orderboard.css`'s 37 raw hex literals into CSS custom properties, matching the other 5 plugin stylesheets.

**UX:**
- Split `doughboss.js`'s `draw()` so cart mutations don't destroy an in-progress checkout form/Stripe iframe.
- Add "simulated demo" disclosure text to `backend.html`/`owner.html`/`staff.html`, matching the ribbon already on `index.html`.
- Add visible "Reconnecting…" states to `app/app.js`'s three silent-failure pollers.
- Add loading/disabled states to `menu-order.js`'s `placeOrder()` and `catering-demo.js`'s `submit()`, matching the pattern already correct in `snowboss.js`.

**Accessibility:**
- Add a skip link to `demo/index.html` (pattern already built correctly on 3 other pages — copy, don't invent).
- Add `aria-live`/`role="alert"` to both voucher-scan result regions and `app.js`'s toast — the primary till feedback loop is currently visual-only.
- Wrap `doughboss-orderboard.css`'s two infinite animations in a `prefers-reduced-motion` guard — an always-on kitchen display with no motion escape hatch today.
- Move focus to the checkout-confirmation and order-tracking result blocks after async swaps, matching `demo/index.html`'s existing `tabindex="-1"` + `.focus()` pattern.
- Wrap `app/app.js`'s login fields in a real `<form>` element so Enter-to-submit and password-manager autofill work from any field (relevant given the app's known plaintext-localStorage credential risk).
- Darken `.dbk-empty`'s 1.93:1-contrast placeholder text on the Kitchen Board; delete the dead, sub-AA `.card .sub` rule.

**Mobile-responsive:**
- Add `@media` breakpoints and `overflow-x:auto` table wrappers to `licensing.html`, `terms.html`, `privacy.html` (currently zero responsive rules across all three, with unwrapped 6-column tables).

**Graphics/GUI:**
- Extend the demo site's scoped CSS custom-property re-theming technique (already used well for `.snow-theme`) to cover the color-system reconciliation above, rather than hardcoding a fourth palette.

---

## 8. Engineering Plan

**Front-end tasks:**
1. Split `doughboss.js`'s cart/checkout render so line-item mutations update only the mutable region, leaving the checkout form (and any mounted Stripe Elements instance) alone — model on `demo/menu-order.js`'s `if (drawerOpen && !checkoutMode)` guard.
2. Add `aria-live`/`role="alert"` to `doughboss-voucher-scan.js` and `app.js`'s result/toast regions.
3. Guard `doughboss-orderboard.css`'s two `@keyframes` animations with `prefers-reduced-motion`.
4. Stop the order board's 30s tick from calling full `render()`; update `.db-card-time` nodes in place instead.
5. Align `app.js`'s three `setInterval` pollers and `doughboss-voucher-scan.js`'s poller onto the order board's resolve-then-reschedule pattern; add a visible reconnect indicator to `app.js`.
6. Wrap `app.js`'s login fields in a real `<form>`.
7. Move focus to checkout-confirmation/order-tracking result blocks post-render.
8. Trim the four untrimmed checkout text inputs client-side (consistency with the already-trimmed voucher input).

**Back-end tasks:**
1. Add the main-checkout Stripe webhook + reconciliation path; fix the "reversed automatically" copy immediately regardless of timeline.
2. Add `ordering_open()`/`enable_delivery`/`enable_pickup` gates to `create_payment_intent()`.
3. Fix the CORS `remove_filter` side effect.
4. Change `/admin/catering/{id}/status` to `verify_manage`.
5. Rate-limit `create_payment_intent`, `catering_payment_intent`, `catering_confirm_payment`.
6. Add env-first Stripe secret/webhook-secret overrides.
7. Batch-fetch order items in `active_orders()` and the admin Orders list (single `IN (...)` query).
8. Make the rate limiter's hit-increment atomic.
9. Move POSPal push + ClickSend SMS off the synchronous request path (or mark `blocking => false`); log `wp_mail()` failures.
10. Wire `create_refund()` into an admin action; decide and implement the refund/voucher-reissue interaction.
11. Add `args`/`validate_callback` schemas to `/checkout` and `/voucher/issue`.
12. Fix `currency_code` settings fallback; add ISO-4217-shaped validation.
13. Add `voucher_campaigns`/`pospal_label` to `Settings::defaults()` for documentation parity.
14. Clamp voucher `percent` values to ≤100% at issue time; reject past `event_date`; cap catering `guest_count`.
15. De-dup `doughboss_locations.slug` on create.

**DevOps tasks:**
1. Add version/readme-drift check + `php -l` re-run to `build-zip.sh` before zipping.
2. Correct `readme.txt`'s Stable tag and payments FAQ.
3. Gate POSPal/Mercure diagnostic routes (incl. `/pospal/probe-grant`) behind `WP_DEBUG`.
4. Add a `GET /doughboss/v1/status` health endpoint (admin-gated).
5. Fix the `0000-00-00` datetime defaults on orders/catering/voucher-redemptions to `NULL DEFAULT NULL` via a version-gated migration.
6. Add the missing `balance_intent_id` index via a version-gated migration.
7. Reconcile the activator's hand-maintained default-settings array with `Settings::defaults()` to stop the two from drifting further (preserve the deliberate demo-content seed values).
8. Document the printer route-registration exception in the REST controller's file header (or consolidate registration).

**QA tasks:**
1. Stand up the PHPUnit harness (dev-only `composer.json`, not shipped by `build-zip.sh`).
2. Write the pure-logic tier first: coupon-code round-trip/transposition/fold-mapping (cases 26-30), cart GST math (cases 1-7).
3. Write voucher `evaluate()`'s pure branches (cases 8-10), then `DoughBoss_Order::create()`'s happy path and empty-lines guard.
4. Write the concurrency/transactional tier: voucher atomic claim under two connections, order-creation rollback-on-partial-failure, collision retry, Stripe webhook signature vectors.
5. Run the full manual QA checklist (checkout + voucher + POSPal, Stripe test mode only) on staging before every release until automated coverage exists.
6. Add the release-readiness checklist items (secret-field blank/write-only eyeball check, settings-preserve regression check, cart-token regression check) to the actual release process, not just this document.

**Data tasks:**
1. Build the Reports admin page (date-range revenue, AOV, top items, delivery/pickup mix) + CSV export.
2. Register WordPress Privacy Tools exporters/erasers for orders/catering/vouchers PII, keyed on `customer_email`; design an anonymization-not-deletion policy for aged records.
3. Fix the `currency_code` fallback and add format validation.
4. Fix menu-seeder idempotency to match on a stable marker instead of exact title, so renamed items don't duplicate.

---

## 9. Deployment Plan

**Pre-deployment checklist:**
- [ ] `scripts/dev-check.sh` clean (`RESULT: PASS`) — necessary, not sufficient.
- [ ] Full manual QA checklist executed on staging in Stripe **test mode** (checkout, voucher redemption incl. abort-mid-redemption revert check, PaymentIntent replay attempt, POSPal sync).
- [ ] Manually verify all 7 secret fields (4 Stripe + 3 POSPal) render blank/write-only post-save.
- [ ] Manually verify a Settings save that doesn't touch `app_origin`/`voucher_campaigns` leaves them intact (`wp option get doughboss_settings --format=json` before/after).
- [ ] Manually verify a fresh guest cart (never-before-seen cookie) survives a full checkout.
- [ ] Confirm `DOUGHBOSS_DB_VERSION` bumped and a migration step exists for any schema change.
- [ ] Diff `build-zip.sh`'s output file list against the previous release.
- [ ] Grep the diff for `error_log`/`var_dump`/`print_r` debug leftovers.
- [ ] Confirm a recent backup exists (UpdraftPlus or host-level) before touching a site with real order data — there is no automated schema-rollback path.
- [ ] Second human clicks through the actual build artifact (the zip), not just the working tree.

**Deployment steps:**
1. `bash build-zip.sh` → `dist/doughboss.zip`.
2. Deploy to a **draft theme** on staging (or a staging copy of the live site) via the available WordPress tooling — never straight to production.
3. Smoke-test: menu load, cart add/checkout (with and without Stripe), order tracking, Live Order Board, cross-origin check against the Staff Console (to catch a CORS regression from Finding 3.3 specifically).
4. Confirm the pre-deploy backup is fresh.
5. Upload `dist/doughboss.zip` via Plugins → Add New → Upload → Activate (fresh install) or Replace (upgrade).
6. First request after activation/upgrade runs `DoughBoss_Migrations::run()` automatically — watch for completion.

**Verification steps:**
- Tail the host's PHP error log for `DoughBoss` lines for the first several orders (the only monitoring signal that currently exists).
- Place one real test order end-to-end and confirm both confirmation emails arrive (no automated signal exists for this).
- Re-verify the three fixes shipped in 2.12.3 specifically: cart survives a fresh session, Settings save preserves unrelated keys, secrets don't echo.

**Rollback plan:**
- Restore the pre-deploy backup taken in the checklist above — there is no in-plugin downgrade path; `doughboss_db_version` only moves forward, and `class-doughboss-migrations.php` has no automated undo beyond "leave version at the last successful checkpoint and retry forward."

**Post-launch monitoring:**
- Until Finding 4's `/doughboss/v1/status` health endpoint exists, monitoring is manual `error_log` tailing only — schedule a recurring manual check for the first week after any deploy.
- Watch specifically for orphaned Stripe PaymentIntents (Finding 3.1) via the Stripe dashboard directly, since no in-plugin reconciliation exists yet.

---

## 10. How-To Manuals

The following five manuals were produced alongside this audit and cover the plugin's full operational lifecycle in detail; this report does not duplicate their content, only references them.

- **[Developer Setup Manual](DoughBoss-Manual-Developer-Setup.md)** — covers cloning the repo, the no-build-pipeline local workflow, running `scripts/dev-check.sh`, building the installable zip, and the conventions a new contributor needs before touching `includes/`/`admin/`.
- **[Admin Manual](DoughBoss-Manual-Admin.md)** — covers the wp-admin experience for shop owners/managers: Orders, Live Order Board, Shops/Locations, Catering, Vouchers, and Settings screens, including the Stripe/POSPal configuration flow.
- **[User Manual](DoughBoss-Manual-User.md)** — covers the customer-facing storefront: menu browsing, the custom pizza builder, cart/checkout (including the Stripe card field when enabled), voucher redemption, and order tracking.
- **[Deployment Manual](DoughBoss-Manual-Deployment.md)** — covers building and installing the plugin zip, activation/migration behavior, staging verification, and the backup/rollback discipline required before touching a production site.
- **[Maintenance Manual](DoughBoss-Manual-Maintenance.md)** — covers ongoing operational care: monitoring via `error_log`, migration/version-bump discipline, settings backup practices, and the manual regression checks that substitute for automated tests today.

---

## 11. Final Recommendations

### Change Now
- The 6 Critical/High issues in §3 (checkout payment reconciliation, payment-intent business-rule gates, CORS, catering permission boundary, PHPUnit harness bootstrap, CLAUDE.md staleness).
- `currency_code` settings fallback (`'USD'` → correct value).
- `readme.txt`'s version number and Stripe FAQ contradiction.
- The checkout-form/Stripe-teardown DOM rebuild bug in `doughboss.js`.
- Rate-limiting the three unthrottled payment-intent routes.

### Change Later
- Env-first Stripe secrets, N+1 query batching, async integration dispatch, the Reports/CSV admin page, Privacy Tools hooks, the shared HTTP-helper and Settings field-render extractions, demo-site brand-color reconciliation, and the full accessibility/responsive punch list in §7 — all real, all worth doing, none blocking a careful staged deployment once the "Change Now" items land.
- Any REST-controller decomposition or dependency-injection introduction — sequence strictly after the test harness exists, not before.

### Do Not Change
- Everything enumerated in §5: the voucher engine's atomic-claim/idempotency/revert logic, `DoughBoss_Order::create()`'s transaction boundaries, the cart's dual GST branches, the coupon-code fold-mapping order, the Stripe verify-then-create sequencing, the catering webhook pattern (as a pattern to replicate, not alter), the printer routes' auth model, the public read-only routes' `__return_true`, the settings seed-from-existing pattern, `build-zip.sh`'s explicit allowlist, and `dev-check.sh`'s always-exit-0 contract.

---

## 12. Final Decision

**Not ready for unattended production deployment — ready for a staged, staging-verified deployment only after the P0 items in §4/§11 land.**

The reasoning is deliberately not softened:

1. **Automated test coverage is 0%, confirmed by exhaustive search**, not estimated. The only existing check (`scripts/dev-check.sh`) is syntax-lint-only and is documented, by its own header, to always exit 0 regardless of outcome — it structurally cannot catch a logic bug, let alone the class of concurrency/transactional bug that matters most here (voucher double-spend, payment replay, order-write rollback). Three real bugs were caught in this exact codebase today, all logic bugs, none of which `dev-check.sh` could ever have found, and none of which would be caught before shipping if it happens again next release.
2. **No live-site tool is connected in this environment.** Every claim in this report — including whether the three 2.12.3 fixes actually behave correctly under real WordPress/MySQL runtime conditions, and whether doughboss.com.au is currently running an older, more-buggy version — is unverifiable from here. That is a real gap between "the code looks correct" and "the code is confirmed correct in production," and it must be closed by a human running the staging verification steps in §9, not assumed away.
3. **Two of the six Critical/High findings are direct financial-harm paths** (orphaned Stripe payments with no refund path, payment-intent creation skipping the shop's own open/closed and delivery/pickup gates) that are still live in the current code and have not been fixed as part of today's pass — they were found by this audit, not by the three bugs already shipped.
4. **A site-wide CORS regression (3.3) and a privilege-boundary gap (3.4)** are the kind of issues that could break something the team doesn't even know depends on DoughBoss's REST behavior, or let a shop-tablet account manipulate catering financial state — neither is hypothetical, both are reachable today.

Against that: the underlying engineering is genuinely competent (the voucher engine, the transactional order writer, the migration runner, and the consistent dependency-free integration pattern are all real strengths that show up under close reading, not just in doc-comments), the three bugs already found were caught and fixed *before* today's deploy, and every recommended fix above is small, additive, and low-risk to apply. This is not a codebase that needs a rewrite — it needs the P0 items closed, a first tranche of automated tests written specifically around the money paths, and a staged (staging-first, backed-up, manually-verified) rollout rather than a direct production push. Once the P0 list in §4 is closed and the staging verification in §9 has actually been run against a real WordPress/MySQL environment, this plugin is a reasonable candidate for production deployment.

---

## Next 7 Days / 30 Days / 90 Days

**Next 7 Days**
1. Fix the CORS `remove_filter` side effect (3.3) — highest blast radius, smallest diff.
2. Change `/admin/catering/{id}/status` to `verify_manage` (3.4).
3. Add `ordering_open()`/`enable_delivery`/`enable_pickup` gates to `create_payment_intent()` (3.2).
4. Fix the misleading "reversed automatically" customer copy in `verify_payment`/`catering_confirm_payment` (immediate, even before the webhook exists).
5. Fix `currency_code`'s hardcoded `'USD'` fallback.
6. Correct CLAUDE.md's version/table-count/roadmap claims and `readme.txt`'s Stable tag + Stripe FAQ.
7. Fix the `doughboss.js` checkout-form/Stripe-teardown bug.
8. Rate-limit the three payment-intent routes.
9. Bootstrap the PHPUnit harness (dev-only `composer.json`) — no tests need to be complete yet, just the scaffold.

**Next 30 Days**
1. Build the main-checkout Stripe webhook + orphaned-payment reconciliation (3.1) — the largest single remaining financial-risk item.
2. Write the first real test tier: coupon-code (cases 26-30) and cart GST math (cases 1-7), then voucher `evaluate()` branches (8-10) and order-creation happy-path/empty-guard.
3. Add env-first Stripe secret overrides; batch-fetch order items to kill the N+1 pattern; make the rate limiter atomic.
4. Move POSPal/SMS calls off the synchronous checkout path.
5. Ship the Reports/CSV-export admin page and register WordPress Privacy Tools hooks.
6. Reconcile the demo site's brand-color system and add responsive breakpoints to the legal pages.
7. Run the full staging deployment process end-to-end (§9) at least once against a real WordPress/MySQL environment, with a human executing the manual QA checklist.

**Next 90 Days**
1. Complete the concurrency/transactional test tier (voucher atomic claim under real concurrent connections, order-creation rollback, Stripe webhook signature vectors) — this is the tier most likely to surface a real latent bug (the pooled `cap_group` voucher-cap logic is flagged as the single most likely candidate).
2. Wire `create_refund()` into a real admin action with the voucher-reissue interaction explicitly decided and implemented.
3. Extract the shared `DoughBoss_Http` helper and Settings field-render helpers, done opportunistically alongside real feature work on those files.
4. Add a `/doughboss/v1/status` health endpoint and gate POSPal/Mercure diagnostic routes behind `WP_DEBUG`.
5. Complete the accessibility/UI punch list (§7) across the demo site and the order board.
6. Evaluate, only once the test harness has real coverage over the checkout/voucher/order paths, whether the 2,699-line REST controller is worth decomposing — treat this as a "maybe," not a committed roadmap item, and never attempt it without characterization tests in place first.
7. Re-run this full audit's Critical Issues list (§3) against the then-current code to confirm none have regressed and none of the "Change Later" items introduced a new one.
