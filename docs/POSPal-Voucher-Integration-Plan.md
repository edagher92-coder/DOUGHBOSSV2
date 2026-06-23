# DoughBoss × POSPal — Integration Plan (Orders · Catering · Vouchers)

**Status:** Draft v3 — security + architecture + web-dev review folded in (§15); M1 connector built & merged.
**Author:** DoughBoss dev
**Spans three domains over one POSPal connector:** (1) **online & in-app orders** → POSPal, (2) **catering** orders/deposits → POSPal, (3) **vouchers** (discount coupons). Shared foundation in §2–§6, §9–§11, §14; domain specifics in §7 (vouchers), §8A (orders), §8B (catering), §8C (product mapping).
**Scope decisions (locked):** **discount-coupon** model (not stored-value). POSPal Open Platform access is currently the **Revesby store only** (account `aus_nsw1740`, area28 host `area28-win.pospal.cn:443`). **v1 pilots at Revesby**, with the connector built **multi-store-ready** so Bankstown & Roselands plug in once their POSPal access is provisioned. Credentials (host, appId, appKey) are supplied out-of-band and stored in settings/env — **never committed to the repo**.

> ⚠️ **Two constraints from the account:**
> 1. **Rate limit — 300 API calls/day on the free tier.** This is the single biggest design driver: a naive 5-minute reconciliation poll (~288/day) would nearly exhaust the quota on its own. The design must be **event-driven first** and poll only when work is actually outstanding (see §7B/§14). **With orders + catering now in scope**, real order volume + product sync + status polling will blow past 300/day quickly — **budget a paid quota upgrade from POSPal sales before orders go live.** The voucher-only pilot can run within the free tier.
> 2. **Revesby ≠ Bankstown.** The Snow Boss `$5+$10` student voucher is themed for the **Bankstown** flagship, but only **Revesby** has POSPal access today. Either pilot the voucher in-store at **Revesby**, or provision **Bankstown** POSPal access before the Bankstown launch (online redemption works regardless).

---

## 1. Goal

Use the shops' **POSPal POS as the operational hub** and connect it to the DoughBoss site so three things flow through one integration:

1. **Orders** — online & in-app orders push into POSPal as online orders (网单) so they hit the till/kitchen, with status synced back to order tracking + the Live Order Board.
2. **Catering** — confirmed catering orders (with Stripe deposit/balance) create POSPal fulfilment orders.
3. **Vouchers** — discount coupons issued by DoughBoss appear and redeem natively at the POSPal till **and** in the online cart, with one cross-channel single-use lock.

**One source of truth:** the DoughBoss plugin owns issuance, money (Stripe), and audit; POSPal owns in-store execution (till, kitchen, native coupon redemption). Everything is keyed so nothing double-counts.

Non-goals for v1: stored-value/gift-card balances and loyalty-points accrual (future). Product/menu mapping **is** in scope now — orders need it (§8C).

---

## 2. Guiding principle — one source of truth

The DoughBoss WordPress plugin is the **system of record** for voucher *issuance, single-use locking and audit*. POSPal performs the *redemption at the register* using its native coupon mechanism. We **issue vouchers into POSPal as member coupons** and **reconcile redemptions back** into the plugin.

Why not let POSPal own the whole thing? Because the same voucher must also work in the online cart, and only a central lock prevents double-spend across the till and the web. Why not inject ad-hoc discounts into a live POSPal ticket? POSPal's intended extension point is its **coupon (优惠券) + member (会员)** system; using it means **zero new cashier workflow** (the coupon simply shows when the customer is identified) and reliable, real-time behaviour.

---

## 3. POSPal Open Platform — confirmed facts

- **Base URL:** `https://<area-host>/pospal-api2/openapi/v1/<module>/<method>`
  (`<area-host>` is account/region-specific — store it in settings, do not hard-code.)
- **Auth headers (every request):**
  - `Content-Type: application/json; charset=utf-8`
  - `time-stamp: <current epoch milliseconds>`
  - `data-signature: <UPPERCASE( MD5( appKey + rawJsonBody ) )>`
- **Body** always includes `appId` (the public app id). `appKey` is the secret used only to sign — it is **never sent in the body**.
- **Modules:**
  - `customerOpenApi` — members: `queryByNumber`, `queryByTel`, `queryByUid`, `add`, `updateBalancePointByUid`.
  - `promotionOpenApi` — coupons/promotions: `queryCouponPromotions` (list coupon rules) **+ grant-to-customer and usage-query methods (exact names to confirm from the 优惠券 docs — see §11).**
  - `productOpenApi` — products: `queryProductByBarcodes`, `queryProductByUid`, …
  - `orderOpenApi` — orders/tickets: `queryOrderByNo`, ticket queries (used for redemption reconciliation).

> **To confirm against the Chinese docs during build (§11):** the exact `promotionOpenApi` method names for (a) granting a coupon to a member and (b) detecting a coupon's redemption, and whether POSPal offers a **push callback/webhook** for ticket/coupon events or whether we must **poll**.

---

## 4. Architecture

```
                 issue (event / admin / online promo)
                         │
   ┌─────────────────────▼─────────────────────┐
   │   DoughBoss plugin  (system of record)     │
   │   • doughboss_vouchers (codes, type, lock) │
   │   • doughboss_voucher_redemptions (audit)  │
   │   • REST: doughboss/v1/voucher/*           │
   └───────┬───────────────────────────┬────────┘
           │ issue/revoke              │ validate/redeem
           │ (signed)                  │
   ┌───────▼────────┐          ┌───────▼─────────┐
   │ POSPal connector│          │ Online cart      │
   │ class-doughboss-│          │ (web / demo)     │
   │ pospal.php      │          └──────────────────┘
   └───────┬────────┘
           │  pospal-api2/openapi/v1  (HTTPS, signed)
   ┌───────▼──────────────────────────────────────┐
   │ POSPal cloud  →  in-store till (3 shops)       │
   │  member coupon shows + applied (核销)          │
   └───────┬───────────────────────────────────────┘
           │ reconcile (poll orderOpenApi / callback)
           ▼
   mark voucher REDEEMED + write audit row
```

**New connector:** `includes/class-doughboss-pospal.php` — a **dependency-free** signed HTTP client over `wp_remote_post()`, mirroring the existing `includes/class-doughboss-stripe.php` (honours the project rule: no Composer, no bundler). Centralises base URL, signing, retry, and error mapping.

---

## 5. Data model (new tables)

Created via `create_tables()` (dbDelta) **plus** a version-gated migration step, **plus** a `DOUGHBOSS_DB_VERSION` bump to **`1.6.0`** (confirmed: catering already shipped at `1.5.0` in this tree — add an `upgrade_to_1_6_0()` step after the existing 1.5.0 branch). **Also add order columns** (§15): `pospal_order_no`, `pospal_push_status`, `pospal_pushed_at` on `{prefix}doughboss_orders` (and the catering enquiries table) for idempotent order push.

### `{prefix}doughboss_vouchers`
| column | type | notes |
|---|---|---|
| `id` | BIGINT PK | |
| `code` | VARCHAR(32) UNIQUE | e.g. `SNOW-7K2D9Q`; matches the demo issuer/validator |
| `type` | VARCHAR(20) | `percent` / `amount` (discount). `surcharge` reserved for future |
| `value` | DECIMAL(10,2) | e.g. `5.00`, or `10.00` for percent |
| `currency` | CHAR(3) | `AUD` |
| `min_spend` | DECIMAL(10,2) NULL | optional threshold |
| `scope` | VARCHAR(20) | `instore`, `online`, `both` |
| `shop_scope` | VARCHAR(120) NULL | blank = all shops (single-account, so usually blank) |
| `single_use` | TINYINT | 1 = one redemption total |
| `status` | VARCHAR(20) | `issued` → `redeemed` / `revoked` / `expired` |
| `customer_phone` | VARCHAR(32) NULL | member key in POSPal |
| `customer_email` | VARCHAR(190) NULL | for delivery + lookup |
| `pospal_customer_uid` | VARCHAR(64) NULL | POSPal member uid once linked |
| `pospal_coupon_ref` | VARCHAR(64) NULL | the granted POSPal coupon/promotion id |
| `valid_from` / `valid_to` | DATETIME NULL | window |
| `meta` | TEXT NULL | JSON (campaign, source = `snowboss_student` etc.) |
| `created_at` / `updated_at` | DATETIME | |

### `{prefix}doughboss_voucher_redemptions`  (immutable audit)
| column | type | notes |
|---|---|---|
| `id` | BIGINT PK | |
| `voucher_id` | BIGINT FK | |
| `channel` | VARCHAR(20) | `instore` / `online` |
| `pospal_ticket_no` | VARCHAR(64) NULL | the till ticket that consumed it |
| `shop` | VARCHAR(120) NULL | which shop |
| `amount_applied` | DECIMAL(10,2) | actual discount given |
| `idempotency_key` | VARCHAR(64) UNIQUE | prevents double-write |
| `redeemed_at` | DATETIME | |

Indexes: `code` (unique), `status`, `customer_phone`, `pospal_customer_uid`.

All SQL via `$wpdb->prepare()`; table names from `$wpdb->prefix`; statuses whitelisted — consistent with existing plugin conventions.

---

## 6. REST endpoints (`doughboss/v1`)

| Method + route | Auth | Purpose |
|---|---|---|
| `POST /voucher/validate` | `wp_rest` nonce (public storefront) | Preview only. Given `code` + cart subtotal, returns `{valid, type, value, amount_applied, reason}`. No state change. |
| `POST /voucher/redeem` | nonce (online) **or** `verify_admin` cap (till/back-office) | **Atomic single-use commit** keyed by `idempotency_key`. Returns the applied amount. Mirrors the existing `payment_intent_used` replay-block pattern. |
| `POST /voucher/issue` | `verify_admin` cap (or internal trigger) | Create a voucher + grant it into POSPal as a member coupon. Used by the Snow Boss claim handler, online promos, and the admin UI. |
| `POST /pospal/redemption-callback` | POSPal signature verify | (If POSPal supports push.) Receives ticket/coupon-used events → marks voucher redeemed. |
| `GET /admin/vouchers` | `verify_admin` cap | List/report for the owner Orders/Settings admin. |

Read/preview is intentionally light-auth like the other public storefront routes; **every state-changing route is nonce- or capability-gated and recomputes value server-side** — never trusts a browser-reported discount.

---

## 7. End-to-end flows (discount-coupon model)

### A. Issue
1. **Trigger** — Snow Boss student claim (replaces today's Formspree-only flow), an online promo, or admin "issue voucher".
2. Plugin inserts a `doughboss_vouchers` row (`status=issued`, `code=SNOW-XXXXXX`, `type=amount`, `value=5.00`, `customer_phone`).
3. Connector → POSPal: `customerOpenApi/queryByTel`; if no member, `customerOpenApi/add` (create member by phone). Then **grant the `$5` coupon** to that member via `promotionOpenApi` (method per §11). Store `pospal_customer_uid` + `pospal_coupon_ref` on the row.
4. Email the code + instruction: *"Show this code, or give your phone number at the till."*

### B. Redeem in-store (the headline behaviour)
1. Customer gives **phone number** at the POSPal till.
2. Cashier opens the member → the `$5` coupon **appears natively** → applies it → POSPal records the discount on the ticket (核销).
3. **Reconciliation:** a WP-cron job (every ~2–5 min) calls `orderOpenApi`/coupon-usage to find tickets that consumed the coupon (or the `/pospal/redemption-callback` fires) → plugin sets `status=redeemed`, writes a `doughboss_voucher_redemptions` row (shop, ticket no, amount, time).

### C. Redeem online (cart / future online orders)
1. Customer enters `SNOW-XXXXXX` at checkout → `POST /voucher/validate` → `POST /voucher/redeem` (atomic) → server applies the discount to the order total.
2. Connector → POSPal to **revoke/expire the mirrored member coupon** so it cannot also be used in-store.

### D. Cross-channel single-use (the lock)
- The plugin row is the lock. First redemption in **either** channel flips `status` to `redeemed`; the connector then **revokes the counterpart** (revoke the POSPal coupon on online redeem; the poll/callback closes the loop on in-store redeem).
- **Race window:** an **offline till** can apply a coupon before our poll sees it. Mitigations: keep coupons single-use **inside POSPal too** (so POSPal itself blocks a second in-store use); make the online path **revoke-before-apply** (call POSPal to invalidate, only then commit online); treat any post-hoc conflict as "in-store wins" and reverse the online discount in reporting. Document the residual risk for the owner.

---

## 8. Surcharges / extra charges (future, same engine)

The `type=surcharge` value is reserved now so the same tables/endpoints later support public-holiday surcharges, catering deposits, or packaging fees — pushed to POSPal as an **order service-charge / add-item** via `orderOpenApi` rather than a coupon. Out of scope for v1 but designed-for.

---

## 8A. Orders integration (online & in-app → POSPal)

POSPal natively supports **online orders (网单)** via `orderOpenApi` (push an externally-created order; later `queryOrderByNo` for status), so the till and kitchen treat web orders like any other.

**Flow:**
1. Customer orders on the site (the cart we built) → plugin writes the existing `doughboss_orders` row → payment via **Stripe** (existing).
2. Connector pushes the order to POSPal as an online order: line items mapped to POSPal products (§8C), totals, customer, pickup/delivery, **marked prepaid**. Idempotent by our order number (no duplicate tickets on retry).
3. Store the returned POSPal `orderNo` on the order row.
4. **Status sync** (quota-aware — §rate-limit): `queryOrderByNo`/callback updates the plugin's order tracking + **Live Order Board**.

**KDS decision (flag for owner):** with orders flowing into POSPal, the store likely runs ops on POSPal's till/kitchen. The plugin's Live Order Board then becomes either (a) a **customer-facing mirror** of POSPal status, or (b) retired for connected stores. Recommend (a) so online customers keep live tracking. Decide per store.

## 8B. Catering integration

Catering is larger, scheduled, deposit-based. It reuses the catering enquiry/quote system (`doughboss_catering_*`) and pushes to POSPal for fulfilment.

**Flow:** enquiry → quote → **Stripe deposit** → on confirmation create a (future-dated) POSPal order for the event → **balance** on/before the day → push final order to POSPal for fulfilment. Money (deposit/balance) is tracked in-plugin (source of truth); POSPal gets the fulfilment order near the event date to conserve API quota. Lower real-time urgency than walk-in orders.

## 8C. Product & menu mapping (shared prerequisite for §8A/§8B)

Orders and catering need each line item mapped to a POSPal product.

- **Recommended:** treat **POSPal as the master product/price list** for in-store. Pull products via `productOpenApi` (`queryProductPages` / `queryProductByBarcodes` / `queryProductByUid`) and store `productUid`/`barcode` on each DoughBoss menu item (CPT meta). Add an admin **mapping screen** (auto-match by name/barcode, manual override).
- **Pricing:** POS is the price source of truth in-store; reconcile online prices to POSPal product prices to avoid drift (warn on mismatch).
- **Refresh:** sync products on a schedule + manual "resync" button (cache to respect the rate limit).

## 9. Security

- **Secrets:** `appId`, `appKey`, `area-host` stored via `DoughBoss_Settings` (the single options array) — `appKey` write-only in the admin field, **never** echoed back or committed. Prefer setting via server env where possible.
- **Signing:** `data-signature = strtoupper( md5( $appKey . $rawBody ) )`, `time-stamp` in ms, on every call. Reject responses with mismatched echo where POSPal provides one.
- **Single-use / idempotency:** `redeem` uses a unique `idempotency_key` (same pattern as checkout `doughboss_idem_*` transients and `payment_intent_used`).
- **Inbound callback** (if used): verify POSPal's signature before acting; return the same opaque error for not-found vs. invalid to avoid probing.
- **Least privilege:** till devices use the kitchen/back-office capability, not admin.

---

## 10. Admin & settings

- **Settings → Payments/Integrations:** **per-store** POSPal config — `host`, `appId`, `appKey` keyed by shop (Revesby configured now; Bankstown/Roselands rows added later), each with an enable toggle, a "test connection" (calls `queryCouponPromotions`), and a mapping field (which POSPal coupon rule represents `$5 off` / `$10 off`). A voucher's `shop_scope` selects which store's credentials the connector uses.
- **Orders → Vouchers:** list with status, channel, shop, amount, ticket no; manual issue/revoke; CSV export for reconciliation.
- Off by default until configured + enabled (mirrors the Stripe `ready()` gate): no POSPal calls happen unless `appId`+`appKey`+`area-host` are set and the toggle is on.

---

## 11. Open items to confirm during build

1. **Exact `promotionOpenApi` method names** for (a) granting a coupon to a member and (b) querying a coupon's redemption/usage. (`queryCouponPromotions` for rules is confirmed; grant/verify names are in the 优惠券 doc pages.)
2. **Push vs poll** — does POSPal emit a ticket/coupon-used callback we can subscribe to, or do we poll `orderOpenApi`? (Affects §7B latency and the `/pospal/redemption-callback` route.)
3. **Coupon rule setup** — confirm whether the `$5`/`$10` discounts are pre-created as POSPal coupon *rules* in the back office and we grant instances, vs. created via API.
4. **`area-host`** — the exact host for this account (region/data-centre).
5. **Sandbox/test** — does POSPal provide a test app, or do we test against a low-value live coupon?
6. **Member identity** — confirm phone is the agreed member key (Revesby now; same assumption for the other stores later).
7. **Other stores** — confirm whether Bankstown & Roselands will be **separate POSPal accounts/areas** (own host + appId/appKey) or stores under one account, so the per-store settings model is right.
8. **Egress** — the connector runs on the live WP host (has outbound to `area28-win.pospal.cn`); the dev sandbox is allow-listed and blocks it, so live-host testing (or adding the host to dev egress) is required to exercise calls.

---

## 12. Phased build

- **M0 — Spec sign-off** (this doc) + obtain `appId`/`appKey`/`area-host`; confirm §11.
- **M1 — Connector + auth:** `class-doughboss-pospal.php`, signed request + "test connection" (`queryCouponPromotions`). No DB yet.
- **M2 — Data model + REST:** tables, migration, DB-version bump, `validate`/`redeem`/`issue` endpoints, single-use lock. Wire the **online cart** redemption (port the demo's `SNOW-` flow to the real endpoints).
- **M3 — POSPal issue:** ensure-member + grant-coupon on issue; store refs; convert the Snow Boss claim to call `/voucher/issue`.
- **M4 — Reconciliation:** cron poll (or callback) → mark redeemed + audit; cross-channel revoke.
- **M5 — Admin UI + reporting + owner docs;** soft launch at **Revesby** (the only connected store), then add Bankstown & Roselands once their POSPal access is provisioned.

**Orders & catering track (can run in parallel after M1):**
- **O1 — Product mapping:** pull POSPal products (`productOpenApi`) → map to the DoughBoss menu (store `productUid`/barcode) + admin mapping UI (§8C).
- **O2 — Order push:** push online orders to POSPal as online orders (`orderOpenApi`), idempotent by order number, marked prepaid (Stripe); store `orderNo`.
- **O3 — Status sync:** query/callback order status → order tracking + Live Order Board (quota-aware polling; resolve the KDS-mirror decision in §8A).
- **C1 — Catering push:** on catering confirmation, create the POSPal fulfilment order (deposit/balance tracked in-plugin); sync near event date.

---

## 13. Testing & verification

- `php -l` clean across new files (no PHPUnit harness in-repo; verification = lint + manual reasoning per project convention).
- Connector unit-reasoned against a recorded POSPal response fixture.
- Manual matrix: issue → in-store redeem → online attempt blocked; issue → online redeem → in-store attempt blocked; expired/min-spend/invalid code; offline-till race.

---

## 14. Risks

- **Offline till race** on single-use (mitigated, residual — §7D).
- **Doc/language** — POSPal docs are Chinese; method names per §11 must be confirmed before M3.
- **Account/plan** — Open Platform access + `appId/appKey` must be enabled on the POSPal account (may need a plan/developer approval).
- **No webhook** would mean redemption visibility lags by the poll interval (acceptable for vouchers; document for the owner).
- **300 calls/day rate limit (free tier)** — reconciliation must be economical: poll only when vouchers are outstanding, batch ticket queries, and prefer a push callback if POSPal offers one; otherwise budget the quota (issue ≈ 2 calls, online-redeem ≈ 1, poll ≈ N) and plan a paid quota upgrade before scaling beyond the Revesby pilot.
- **Revesby-only access today** — in-store redemption is limited to Revesby until Bankstown/Roselands POSPal is provisioned; the Bankstown-themed Snow Boss voucher must pilot at Revesby or wait for Bankstown access (see top-of-doc constraint).

---

## 15. Review findings folded in (v3)

A three-specialist read (security · architecture · web-dev) of v2 against the live plugin. Net: architecture is sound and convention-aligned; the items below are the must-get-right details, now **binding on the build**. **Factual correction:** the plugin **already ships** the Stripe webhook (`/catering/stripe-webhook` + `verify_webhook_signature`) and the full catering deposit/balance system (`doughboss_catering_enquiries`) — reuse them, don't reinvent. CLAUDE.md's "webhook not yet implemented" note is stale.

### P0 — correctness / data-integrity (binding)
- **Sign the exact bytes sent.** Build the JSON body once, sign that string, POST that same string — never an array body (WP re-encodes arrays → signature mismatch). *(Done in M1.)*
- **DB version = `1.6.0`.** Voucher tables in `create_tables()` (additive dbDelta) + an `upgrade_to_1_6_0()` step after the 1.5.0 branch; bump `DOUGHBOSS_DB_VERSION`.
- **Atomic single-use redeem.** Not read-then-write: conditional `UPDATE doughboss_vouchers SET status='redeemed' WHERE id=%d AND status='issued'`, treat `rows_affected===1` as the winner; back it with `UNIQUE(idempotency_key)` on the redemptions table (insert-first).
- **Order-push idempotency.** `pospal_order_no` + `pospal_push_status` + `pospal_pushed_at` on `doughboss_orders` (and catering); gate the push on a NULL `pospal_order_no`; send our `order_number` as POSPal's dedup key so a timed-out retry can't double-create a ticket.
- **Catering money is one-way.** Stripe/plugin owns deposit/balance (existing `class-doughboss-catering.php`); POSPal receives a fulfilment order for kitchen/ops only and must never write money state back — a POSPal-side price edit is a report-only flag.

### P1 — important (binding)
- **Decouple order push from checkout.** `/checkout` records order + Stripe payment as today; push to POSPal **asynchronously on the existing `doughboss_order_created` action** (cron/async, `pospal_push_status` drives retries). A POSPal outage or quota exhaustion must never block a paid order.
- **Encode the 300/day budget.** Per-event budget (issue ≈2, online-redeem ≈1, order-push ≈1, status ≈1–2/cycle, product-sync ≈⌈catalog/page⌉). Add an in-code **daily call counter + circuit-breaker**; **skip polling entirely when nothing is outstanding**; long-TTL cached product sync; **retry only idempotent reads, never writes.** Secure a **paid quota before O2 (order push) goes live** — hard prerequisite.
- **High-entropy voucher codes.** ≥8 chars from an unambiguous alphabet via `random_bytes`/`wp_generate_password` (not `rand()`).
- **Rate-limit + opaque responses.** Apply the existing `rate_limited()` to `/voucher/validate` (~10/10min) and `/voucher/redeem` (~5/hr); return an identical generic response for invalid/expired/not-found/already-used; keep `/voucher/validate` **purely local** (no POSPal call) so it can't burn quota.
- **Least privilege.** `verify_admin()` also grants the kitchen (`manage_doughboss_kds`) role — use it only for in-store `/voucher/redeem`; add a `verify_manage()` (owner `manage_doughboss` only) for `/voucher/issue`, revoke and `/admin/vouchers`. A till tablet must not issue value.
- **Callback (only if POSPal offers one).** POSPal signs with MD5(appKey+body) — **no built-in replay protection**, so the Stripe verifier can't be reused: add `verify_callback()` (`hash_equals` over the raw `get_body()`), read the header as `data_signature`, route `permission_callback => __return_true`, enforce a `time-stamp` freshness window, dedupe on `pospal_ticket_no` (UNIQUE). If POSPal is poll-only (§11.2), **don't ship the route.**
- **Settings sanitizer trap.** `DoughBoss_Admin::sanitize_settings()` rebuilds `$clean` from a whitelist and **drops unknown keys** — the per-store POSPal config (`pospal_stores[location_id]{host,appId,appKey,enabled,coupon_map}`) must be explicitly sanitized there or it's wiped on every save. Key shop scope by **`location_id`** (not free-text), consistent with `doughboss_orders`/`DoughBoss_Locations`.
- **Secret handling.** `appKey` read **env-first** (`DOUGHBOSS_POSPAL_APPKEY`) *(done)*; if ever stored in DB, a non-autoloaded option; admin field **write-only** (preserve prior value when posted blank; never echo). Connector logs status+endpoint only — never body/PII/key *(done)*.
- **Product mapping.** Store `_doughboss_pospal_uid` / `_doughboss_pospal_barcode` as `doughboss_item` meta; an order with any **unmapped line is blocked/flagged** (not partial-pushed); push DoughBoss prices (= what was paid via Stripe), treat POSPal price drift as report-only.
- **KDS "customer mirror" = the existing `/order/{number}` tracking page** fed by synced POSPal status — not the staff `verify_admin` board. Decide separately whether to retire the staff board per connected store.
- **Sequencing.** Run **M4 (reconciliation) immediately after M3**, or gate online redeem behind a "POSPal coupon revoked OK" — otherwise an in-store redeem is invisible to the online lock during rollout.
- **PII minimisation + consent.** Send only what's needed to grant/redeem (phone as member key; avoid email/notes); capture consent at issuance that the phone is shared with the POS; document retention/erasure for the voucher/redemption tables.

### Done in M1 (merged)
`includes/class-doughboss-pospal.php` — exact-byte signing, ms `time-stamp`, default `sslverify`, gzip-safe response, status-only logging; `pospal_*` settings (env-first `appKey`, `pospal_ready()` gate); loader wiring. `php -l` clean (19/19).

### Sources
- POSPal Open Platform — Coupon API (查询可用优惠券规则): `https://www.pospal.cn/openplatform/apis/couponapi/3.%20查询可用优惠券规则.html`
- POSPal Open Platform — Product/Order API: `http://pospal.cn/openplatform/productorderapi.html`
- POSPal Open Platform overview: `https://pospal.cn/openplatform/openplatform.html`
- PHP SDKs (reference for endpoints/signing): `https://github.com/Hanson/pospal`, `https://github.com/ledccn/ledc-pospal`, `https://github.com/minms/pospal`
- Pospal for Asian restaurants: `https://www.pospal.ai/`
