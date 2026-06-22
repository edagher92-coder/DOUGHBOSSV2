# DoughBoss × POSPal — Voucher / Discount-Coupon Integration Plan

**Status:** Draft v1 — for team review
**Author:** DoughBoss dev
**Scope decisions (locked):** single POSPal account for all three shops (Bankstown, Roselands Centro, Revesby); **discount-coupon** model (not stored-value).

---

## 1. Goal

Let a voucher issued by DoughBoss (e.g. the Snow Boss `$5 + $10` student launch voucher, online promos, or a manually-issued code) **appear and redeem natively on the in-store POSPal till**, and also redeem in the online cart — with **one source of truth** so a code can never be spent twice across channels, and so discounts (and, later, surcharges) are fully audited.

Non-goal for v1: stored-value/gift-card balances, loyalty points accrual, full menu sync. These are noted as future extensions.

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

Created via `create_tables()` (dbDelta) **plus** a version-gated migration step, **plus** a `DOUGHBOSS_DB_VERSION` bump (next increment — reconcile with the catering branch's `1.5.0`, so likely `1.6.0`).

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

## 9. Security

- **Secrets:** `appId`, `appKey`, `area-host` stored via `DoughBoss_Settings` (the single options array) — `appKey` write-only in the admin field, **never** echoed back or committed. Prefer setting via server env where possible.
- **Signing:** `data-signature = strtoupper( md5( $appKey . $rawBody ) )`, `time-stamp` in ms, on every call. Reject responses with mismatched echo where POSPal provides one.
- **Single-use / idempotency:** `redeem` uses a unique `idempotency_key` (same pattern as checkout `doughboss_idem_*` transients and `payment_intent_used`).
- **Inbound callback** (if used): verify POSPal's signature before acting; return the same opaque error for not-found vs. invalid to avoid probing.
- **Least privilege:** till devices use the kitchen/back-office capability, not admin.

---

## 10. Admin & settings

- **Settings → Payments/Integrations:** POSPal `appId`, `appKey`, `area-host`, enable toggle, "test connection" (calls `queryCouponPromotions`), and a mapping field (which POSPal coupon rule represents `$5 off` / `$10 off`).
- **Orders → Vouchers:** list with status, channel, shop, amount, ticket no; manual issue/revoke; CSV export for reconciliation.
- Off by default until configured + enabled (mirrors the Stripe `ready()` gate): no POSPal calls happen unless `appId`+`appKey`+`area-host` are set and the toggle is on.

---

## 11. Open items to confirm during build

1. **Exact `promotionOpenApi` method names** for (a) granting a coupon to a member and (b) querying a coupon's redemption/usage. (`queryCouponPromotions` for rules is confirmed; grant/verify names are in the 优惠券 doc pages.)
2. **Push vs poll** — does POSPal emit a ticket/coupon-used callback we can subscribe to, or do we poll `orderOpenApi`? (Affects §7B latency and the `/pospal/redemption-callback` route.)
3. **Coupon rule setup** — confirm whether the `$5`/`$10` discounts are pre-created as POSPal coupon *rules* in the back office and we grant instances, vs. created via API.
4. **`area-host`** — the exact host for this account (region/data-centre).
5. **Sandbox/test** — does POSPal provide a test app, or do we test against a low-value live coupon?
6. **Member identity** — confirm phone is the agreed member key across all three shops.

---

## 12. Phased build

- **M0 — Spec sign-off** (this doc) + obtain `appId`/`appKey`/`area-host`; confirm §11.
- **M1 — Connector + auth:** `class-doughboss-pospal.php`, signed request + "test connection" (`queryCouponPromotions`). No DB yet.
- **M2 — Data model + REST:** tables, migration, DB-version bump, `validate`/`redeem`/`issue` endpoints, single-use lock. Wire the **online cart** redemption (port the demo's `SNOW-` flow to the real endpoints).
- **M3 — POSPal issue:** ensure-member + grant-coupon on issue; store refs; convert the Snow Boss claim to call `/voucher/issue`.
- **M4 — Reconciliation:** cron poll (or callback) → mark redeemed + audit; cross-channel revoke.
- **M5 — Admin UI + reporting + owner docs;** soft launch with the student voucher at **Bankstown**, then roll to all three.

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

---

### Sources
- POSPal Open Platform — Coupon API (查询可用优惠券规则): `https://www.pospal.cn/openplatform/apis/couponapi/3.%20查询可用优惠券规则.html`
- POSPal Open Platform — Product/Order API: `http://pospal.cn/openplatform/productorderapi.html`
- POSPal Open Platform overview: `https://pospal.cn/openplatform/openplatform.html`
- PHP SDKs (reference for endpoints/signing): `https://github.com/Hanson/pospal`, `https://github.com/ledccn/ledc-pospal`, `https://github.com/minms/pospal`
- Pospal for Asian restaurants: `https://www.pospal.ai/`
