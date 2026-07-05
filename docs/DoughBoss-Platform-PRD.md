# DoughBoss Platform — Phased PRD

## 1. Overview & vision

DoughBoss is a commission-free ordering and voucher platform for a small Sydney hospitality group (Dough Boss pizza/manousheh bakery and Snow Boss dessert brand, across Revesby and Bankstown). It already exists as a working, self-contained WordPress plugin. The vision is to keep the group's customer relationships and margins in-house — no per-order commissions, no platform lock-in — by extending the plugin we already own rather than buying or rebuilding a stack. We grow capability in deliberate phases, each banking a visible win for a low-budget business: harden and ship what's 90% done first (a scannable, POS-redeemable voucher), then add real-time and notification polish, then richer loyalty, and only consider heavier platforms if the group scales to many venues. The rule throughout: **leverage proven open-source/APIs, keep the plugin dependency-free, and never reinvent what a small, vendored library already does well.**

## 2. What already exists (the foundation)

DoughBoss is a coherent, production WordPress plugin (PHP over `wp_remote_*`, vanilla JS, **no build step**). Concretely, it already ships:

- **Branded storefront** — menu, custom pizza builder, guest cookie-token cart, pickup/delivery checkout, order tracking, multi-shop order routing.
- **Stripe card payments** — PaymentIntents created server-side, re-verified on `/checkout` (status + amount + currency), PI-replay blocked. Server is the source of truth for totals.
- **A full single-use VOUCHER system** — high-entropy codes, **atomic single-use redeem**, daily-capped "$5/$10 student" campaigns (shared 100/day pool), a customer claim widget, in-cart apply, and server-side discount through checkout.
- **Two staff surfaces** — a "Voucher Scan" wp-admin dashboard, and a standalone web **Console** (static, hostable on GitHub Pages, authenticates via WordPress Application Password) that surfaces Scan / Vouchers / Order Board over the `doughboss/v1` REST API.
- **A real-time-ish KDS** — the Order Board polling every ~7s.
- **An in-progress POSPal (银豹) integration** — the Open Platform connection is **already authenticated** (appId/appKey + signature); the next milestone is granting a claimed voucher as a coupon-rule to a phone-identified member so it redeems natively at the physical till.

**Why this is the foundation:** every off-the-shelf alternative (RestroPress, Orderable, UpMenu, TastyIgniter, Medusa/Saleor/Vendure) is either a feature subset of what we already have, a different language stack (Laravel/Node/Ruby/Python) requiring a rewrite, or a SaaS that re-introduces fees and lock-in. DoughBoss already owns the hard, bespoke parts no product offers — atomic single-use vouchers, daily-capped campaigns, and a POSPal till bridge. We extend it.

## 3. Landscape & build-vs-buy

| Capability | Best existing tool(s) | Verdict | Why (one line) |
|---|---|---|---|
| **Ordering / storefront** | TastyIgniter (MIT, Laravel); RestroPress / Orderable (WP) | **KEEP + borrow** | We already do menu/cart/checkout/routing; mine TastyIgniter only as a *reference* for time-slots & prep-time routing — adopting any of these is a rewrite or a WooCommerce coupling we don't want. |
| **Payments** | Stripe PaymentIntents (shipped); `stripe-php` SDK | **KEEP** | Our dependency-free `wp_remote_*` Stripe client is correct; `stripe-php` would break the no-Composer rule. Use the **Stripe MCP** at dev-time only (build the webhook, surface refunds). |
| **Vouchers / coupons** | `mariuswilms/coupon_code` (BSD); Voucherify (SaaS) | **INTEGRATE (tiny lib) + KEEP engine** | Keep our voucher engine; vendor one BSD class to add a **check digit + `normalize()`** (O→0, I→1) — kills typo/guess DB hits and "read-it-aloud" support calls. Voucherify (~US$170/mo) is the far-future graduation option, not now. |
| **POS integration** | **POSPal Coupon API V2 + Member API**; Square (Plan B) | **INTEGRATE / FINISH** | POSPal exposes exactly the four calls we need (query rules, add/grant coupon, query member coupons, use/validate) — finish the in-progress grant. Keep Square documented as the migration path; **avoid Toast/Clover/Lightspeed** (inverted "POS-calls-you" model, partner-gated). |
| **Loyalty / membership** | Extend our own schema; Stampee (ref); Voucherify/Open Loyalty (later) | **BUILD natively** | Open Loyalty's OSS edition is effectively dead; Stampee is a separate Node/Supabase app. Add a `stamps`/points table keyed by phone-as-member-key — don't split the data store. |
| **KDS / real-time transport** | **Mercure** (SSE, PHP-native); Ably (free tier); Soketi/Pusher | **INTEGRATE (Mercure)** | Mercure drops into a no-build PHP plugin: publish = one `wp_remote_post`, subscribe = a 3-line `EventSource`. Self-host the AGPL Caddy hub on a $5–10 VPS. Ably's free tier is the zero-ops fallback. Don't adopt a third-party KDS app — we already have one. |
| **Notifications — staff** | **ntfy** (Apache-2.0) | **INTEGRATE** | Near-zero-cost "new ticket" push to kitchen phones — one `wp_remote_post` on `doughboss_order_created`. |
| **Notifications — customer SMS** | **ClickSend** (AU, AUD); Twilio (global) | **INTEGRATE (ClickSend)** | AU-based, AUD-billed (~7c/SMS, free inbound, no contract, official PHP SDK) — beats Twilio's USD + FX for a Sydney shop. Keep the OTP state machine in DoughBoss. |
| **Receipt / kitchen printing** | **Epson Server Direct Print** / **Star CloudPRNT**; (avoid `escpos-php`) | **INTEGRATE (printer-pull)** | The printer polls our REST endpoint and pulls an XML/JSON ticket — zero on-prem print server, matches our serverless hosting. `escpos-php` needs a physically attached printer; wrong for hosted WP. |
| **QR generation** | `chillerlan/php-qrcode` or `endroid/qr-code` (PHP); JS lib in Console | **INTEGRATE (vendored)** | Turn every existing voucher code into a scannable QR. Note: `chillerlan` needs PHP 8.2 — pin an older 4.x release or use `endroid` for our PHP 7.4 floor; or render QR client-side in the Console (no plugin change). |
| **QR scanning (staff)** | **html5-qrcode** (Apache-2.0); zxing-wasm (fallback engine) | **INTEGRATE** | Single JS file, no build step, works as a PWA — camera-scan a voucher and POST to the existing atomic `/redeem` route. Drops straight into the vanilla-JS Console. |
| **Staff Console framework** | Tabler (CSS); Refine / React-Admin (later) | **KEEP + light polish now** | Keep the vanilla-JS Console; drop in Tabler HTML/CSS for a fast professional look. Graduate to Refine + `vite-plugin-pwa` only when CRUD complexity justifies a build pipeline (Phase 3). |

## 3b. Recommended approach

The spine: **the WordPress plugin stays the system of record. We integrate a handful of narrow, proven pieces and avoid every platform-scale rewrite.**

- **KEEP the plugin as system of record.** It already holds orders, the cart, the voucher tables, Stripe, and the POSPal connection. Every headless engine (Medusa/Saleor/Vendure/Spree/Bagisto) and SaaS (UpMenu/Voucherify/Talon.One/Open Loyalty) would mean abandoning working, bespoke logic and hiring off-stack engineers — directly contradicting the low-cost, no-build-step, phased goal.
- **INTEGRATE only small, vendored or `wp_remote_*` pieces:** one BSD coupon-code class (check digit), QR gen + `html5-qrcode` scan, Mercure SSE, ntfy, ClickSend, Epson SDP / Star CloudPRNT, and the POSPal Coupon/Member APIs. Each is a drop-in that respects "no Composer/npm in the plugin."
- **Finish, don't start.** The single highest-leverage move is shipping the **POSPal coupon-rule grant** — the connection is already authenticated and it's the stated next milestone. That's a Phase 1 quick win, not a research project.
- **AVOID the rewrite tier and the inverted-POS tier.** No WooCommerce migration (we'd have to bridge our non-Woo cart), no Toast/Clover/Lightspeed (the till calls *you*, partner-gated), no Talon.One (enterprise pricing, being acquired). Use MCP servers (Stripe, WPVibe, Square) **at dev/agent time only** — they accelerate *our* build, they don't ship in the plugin.
- **Cost discipline.** The entire near-term roadmap runs on one $5–10/mo VPS (Mercure + optional self-hosted ntfy), pay-as-you-go SMS, and free libraries. No new subscriptions until the group genuinely outgrows custom code.

## 4. Phased roadmap

### Phase 1 — Quick Win: the scannable, till-redeemable voucher (~1–2 weeks)
- **Goal:** Close the loop a customer can *see* — claim a voucher, get a scannable QR, and have staff redeem it natively at the POSPal till.
- **Scope:** (a) Finish the **POSPal Coupon API V2 grant** (grant a claimed voucher as a coupon-rule to a phone-identified member via the Member API); (b) vendor `mariuswilms/coupon_code` to add a **check digit + `normalize()`** to code gen/validation; (c) render each voucher code as a **QR** (client-side in the Console, or `endroid/qr-code` server-side); (d) add **camera scanning** to the staff Console via `html5-qrcode` POSTing to the existing atomic `/redeem` route.
- **Tools:** POSPal Coupon API V2 + Member API (wrapped in a dependency-free `wp_remote_*` client mirroring `class-doughboss-stripe.php`, signing pattern referenced from `Hanson/pospal`); `mariuswilms/coupon_code` (vendored); `html5-qrcode`; QR generator.
- **Effort:** ~1–2 weeks. Mostly wiring against an already-authenticated connection and existing routes.

### Phase 2 — Real-time + notifications polish (~2–3 weeks)
- **Goal:** Replace the 7s poll, alert the kitchen instantly, and notify customers.
- **Scope:** (a) **Mercure SSE** behind the `doughboss_load_assets` pattern — plugin publishes on `doughboss_order_created` / `order_status_changed`; Order Board + Console subscribe via `EventSource` (keep polling as graceful fallback); (b) **ntfy** push to staff phones on every new order; (c) **ClickSend** SMS on order-ready / voucher-claimed, with the OTP state machine kept in DoughBoss; (d) **printer-pull tickets** via Epson SDP or Star CloudPRNT (new `doughboss/v1` endpoint emitting the order as ePOS-XML / CloudPRNT JSON).
- **Tools:** Mercure (self-hosted Caddy hub on a $5–10 VPS) or Ably free tier; ntfy; ClickSend + `clicksend-php` signing pattern; Epson Server Direct Print / Star CloudPRNT.
- **Effort:** ~2–3 weeks.

### Phase 3 — Loyalty, identity & Console-as-PWA (~3–5 weeks)
- **Goal:** Turn one-off vouchers into repeat visits; make the Console a real installable staff app.
- **Scope:** (a) Native **points/stamps** table keyed by phone-as-member-key (design referenced from Stampee, Medusa/Vendure conditions+actions, Square Loyalty tiers — built in PHP, not adopted); (b) **phone-as-member-key OTP** for the POSPal member grant (ClickSend send + our own code store, *not* Twilio Verify); (c) rebuild the Console as a **PWA** with `vite-plugin-pwa` + **Refine** (or React-Admin) for campaign CRUD and pool monitoring — lives in the Console repo, so it does *not* violate the plugin's no-build rule.
- **Tools:** `vite-plugin-pwa`, Refine/React-Admin, Tabler; ClickSend; `php-pkpass` for Apple Wallet voucher passes (nice-to-have).
- **Effort:** ~3–5 weeks.

### Phase 4 — Richer campaigns & scale options (as needed)
- **Goal:** Multi-brand (Dough Boss + Snow Boss) campaign rules and a documented exit ramp if the group scales.
- **Scope:** Port the **conditions + actions** promotion pattern (Vendure) and **campaign-with-budget-cap** model (Medusa) into the native discount layer for BOGO/stacking/scheduled windows; extend the voucher table to **stored-value / partial-redemption gift cards** (model from YITH); branded PDF vouchers via the existing **WeasyPrint** pipeline. Keep **Voucherify** (+ its read-only MCP for plain-language staff lookups), **Square** (gift cards + loyalty + Orders API, AU-ready), and **Odoo POS** (self-host RPC) on the shelf as documented graduation paths — adopt *only* if multi-venue scale or SaaS analytics genuinely outgrow custom code.
- **Tools:** Native PHP (patterns borrowed, platforms not adopted); WeasyPrint; Voucherify / Square / Odoo as deferred options.
- **Effort:** Open-ended; demand-driven.

## 5. Phase 1 in detail — the quick win

**Problem.** The voucher system is 90% there but the loop a customer *experiences* isn't closed: a claimed voucher is a string, not a thing you scan; codes typed/read aloud cause typo and support friction; and the POSPal till can't yet honour a claimed voucher natively. Banking this loop turns existing work into a visible, demoable win with minimal new code.

**Scope — IN:**
- Finish the **POSPal coupon-rule grant**: resolve/create the member by phone (Member API), then **add (grant)** the claimed voucher as a coupon to that member (Coupon API V2), so it redeems natively at the physical till.
- Vendor `mariuswilms/coupon_code` into `includes/` (with the `ABSPATH` guard, no Composer): add a **check digit** to reject typo'd/guessed codes *before* a DB hit, and `normalize()` to map ambiguous characters.
- Render each voucher code as a **QR** (claim widget + Console). Default to client-side JS rendering to avoid the PHP 8.2 issue in `chillerlan`; if server-side is needed, vendor `endroid/qr-code` (PHP 7.4-safe).
- Add **camera scanning** to the standalone Console via `html5-qrcode`, POSTing the scanned code to the existing atomic single-use `/redeem` route.

**Scope — OUT (deferred):** Mercure/real-time, SMS/ntfy, printer-pull, points/stamps, PWA rebuild, gift-card stored value, BOGO/stacking, Wallet passes. (Phases 2–4.)

**User stories & acceptance criteria:**

1. *As a student, I claim a voucher and see a scannable QR.*
   - AC: The claim widget displays the existing high-entropy code **and** a QR encoding it; the QR renders with no build step and no plugin Composer dependency.
2. *As a customer, I have my voucher waiting at the POSPal till under my phone number.*
   - AC: On claim with a phone number, DoughBoss resolves or creates the POSPal member (Member API) and grants the voucher as a coupon-rule (Coupon API V2); the coupon appears in `query a member's coupons`; the POSPal client is a dependency-free `wp_remote_*` wrapper mirroring `class-doughboss-stripe.php` with correct appId/appKey signature.
3. *As staff, I scan a voucher QR on my phone in the Console and redeem it.*
   - AC: `html5-qrcode` opens the camera, decodes the code, and POSTs to the atomic `/redeem` route; a valid code redeems exactly once and a re-scan is rejected; the Console shows clear success/already-used/invalid states.
4. *As the system, I reject bad codes before touching the database.*
   - AC: A code failing the `coupon_code` check digit is rejected client-of-DB (no redeem query); `normalize()` maps O→0 / I→1 etc. before validation so a correctly-read-aloud code still validates.

**Exact tools/libraries to adopt:**
- **POSPal Open Platform** — Coupon API V2 (`add` + `query member coupons` + `use/validate`) and Member API (`query by customerUid` / `add member`); signing referenced from `Hanson/pospal`, re-implemented in a vendored `wp_remote_*` client.
- **`mariuswilms/coupon_code`** (BSD, vendored single class).
- **`html5-qrcode`** (Apache-2.0, single JS file) in the Console.
- **QR generator** — client-side JS by default; `endroid/qr-code` if server-side rendering is required.

**Definition of done:** A claimed voucher shows a scannable QR; the same voucher is granted to the customer's POSPal member by phone and is visible at the till; staff can camera-scan it in the Console and redeem it once via the existing atomic route; the check digit + `normalize()` are live in generation and validation; all PHP passes `bash scripts/dev-check.sh` (`php -l` clean) and respects the no-Composer / `ABSPATH` / `$wpdb->prepare()` conventions; the POSPal client never logs secrets and the server stays the source of truth.

## 6. Risks & open questions

- **POSPal coupon-rule semantics (highest risk).** It's unconfirmed whether a *single-use, daily-capped student voucher* maps cleanly onto a POSPal coupon-**rule** granted per member, or whether the rule model assumes reusable/templated coupons. Open question: does "add coupon" attach an *instance* to a member, or define a rule? This determines whether our daily-cap logic stays entirely in DoughBoss (likely) with POSPal only holding the per-member grant.
- **API quota (300 calls/day).** A real ceiling for a two-shop group at peak. Mitigations: cache `query available coupon rules`, grant on claim (not on every cart view), avoid polling POSPal, and batch member lookups. Open question: is the limit per-app or per-store, and does it reset at AU midnight or CN midnight?
- **Multi-store routing into POSPal.** Revesby and Bankstown may be distinct POSPal stores/customerUids. Open question: one appId/appKey across both stores, or per-store credentials? Voucher grant must target the correct store's member.
- **Docs are CN + region-blocked.** `pospal.cn` 403s through the proxy; we rely on `Hanson/pospal` and the already-authenticated connection for signature mechanics. Risk: undocumented field/format surprises — mitigate with a staging member and small test grants.
- **PHP version vs QR libs.** `chillerlan/php-qrcode` needs PHP 8.2 while the plugin floors at 7.4 — resolved by client-side QR or `endroid`; confirm the live host's PHP version.
- **Phone-as-member-key & privacy.** Granting by phone implies storing/normalising phone numbers; AU commercial-messaging rules apply once SMS lands (Phase 2). Decide consent/normalisation now even though SMS is deferred.
- **Mercure hub ops (Phase 2).** A self-hosted hub is one more thing to keep alive on the VPS; Ably's free tier is the documented zero-ops fallback if maintenance burden isn't wanted.

## 7. Success metrics for Phase 1

- **Voucher → till redemption rate:** ≥ 70% of vouchers granted to a POSPal member are successfully redeemed at the physical till (the loop actually closes).
- **POSPal grant success rate:** ≥ 99% of claim-with-phone events produce a member-attached coupon with no manual intervention.
- **Scan-to-redeem latency:** median < 3s from camera decode to confirmed redeem in the Console.
- **Support-call reduction:** measurable drop in "code doesn't work / can't read it" issues after the check digit + `normalize()` ship (target: near-zero ambiguous-character failures).
- **Quota headroom:** stays under ~50% of the 300-calls/day POSPal limit on a normal trading day (proves the grant-on-claim + caching design scales).
- **Zero regressions:** `php -l` clean; existing checkout, Stripe, and atomic single-use redeem behaviour unchanged; no secrets logged.
