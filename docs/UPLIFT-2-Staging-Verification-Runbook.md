# UPLIFT-2 — Staging Verification Runbook

**Goal:** take DoughBoss v2.17.0 (DB schema 1.10.0) from *code-verified* (lint + review pass) to *staging-verified* (a real order and a real test payment observed end to end on a real WordPress install). This is the gap between 6/10 and 9.5/10 production readiness.

**Companions — do not duplicate them, use them:**
- `RELEASE_CHECKLIST.md` — per-release gate; §3 (manual smoke) and §4 (payments) are executed *by* this runbook.
- `docs/DoughBoss-Manual-Deployment.md` — live-site deployment mechanics, backup policy, rollback detail.
- `CLAUDE.md` §Money-path requirements — any money-path change requires the staging smoke test below before it counts as done.

Work top to bottom. Each numbered section ends with a pass condition. Stop and fix on any failure.

---

## 1. Staging setup

### 1.1 Build the zip
```bash
cd /path/to/DOUGHBOSSV2
bash scripts/dev-check.sh --strict   # must PASS
bash build-zip.sh                    # fails if version/Stable tag/changelog disagree
unzip -l dist/doughboss.zip | head   # expect top-level doughboss/ with includes/, admin/, public/
```

### 1.2 Provision staging WordPress
- WordPress **6.0+**, PHP **7.4+** (match the live host's PHP version if known), MySQL/MariaDB.
- Check strict SQL mode before activating (`SELECT @@sql_mode;`) — see Deployment Manual §1.3 for the `NO_ZERO_DATE` risk.
- Fresh site or a clone of production content; never point staging at live payment credentials.
- Install WP-CLI if possible; several checks below use it.

### 1.3 Install and activate
1. wp-admin → Plugins → Add New → Upload Plugin → `dist/doughboss.zip` → Activate. (Or `wp plugin install dist/doughboss.zip --activate`.)
2. No fatal on activation; site front end still loads.

### 1.4 Activation checks
```bash
wp db query "SHOW TABLES LIKE '%doughboss%'"
```
Expect all seven plugin tables: `doughboss_orders`, `doughboss_order_items`, `doughboss_locations`, `doughboss_catering_enquiries`, `doughboss_vouchers`, `doughboss_voucher_redemptions`, `doughboss_pospal_outbox` (with your table prefix).
```bash
wp option get doughboss_db_version        # expect 1.10.0
wp role list | grep doughboss             # expect doughboss_kitchen and doughboss_manager
```
- Menu items are **not** auto-seeded on activation. Seed the demo/starter menu explicitly:
```bash
wp doughboss seed-menu --dry-run   # review
wp doughboss seed-menu             # seed (or wp eval-file scripts/seed-menu.php)
```
- wp-admin shows the DoughBoss menu; Settings page loads and saves without wiping unrelated keys (save once, reload, confirm nothing blanked).

**Pass:** tables + DB version + roles present, menu seeded, settings round-trip clean.

## 2. First real order — end to end

### 2.1 Page setup
Create pages containing these shortcodes (registered in `includes/class-doughboss-shortcodes.php`):

| Page | Shortcode |
|---|---|
| Menu / Order | `[doughboss_menu]` |
| Pizza Builder | `[doughboss_builder]` |
| Cart / Checkout | `[doughboss_cart]` |
| Track My Order | `[doughboss_order_tracking]` |
| Location picker (multi-shop only) | `[doughboss_shop_picker]` |
| Catering (optional now) | `[doughboss_catering]` |
| Voucher claim (optional now) | `[doughboss_voucher_claim]` |

Confirm each page renders its app container and assets load (no 404s in devtools Network tab).

### 2.2 Configure the shop
- Settings: shop open / "accept orders" on, pickup enabled, at least one location configured, order notification email set to an inbox you control, ETA options sane.
- Leave `payment_gateway` untouched and no Stripe/Tyro keys set — this first order exercises the **unpaid pickup** path.
- Ensure staging can send mail (mail-catcher plugin or real SMTP) — email verification below depends on it.

### 2.3 Place a pickup order (unpaid path)
1. In a private browser window (guest, no login): add one standard menu item and one custom builder pizza to the cart.
2. Refresh the page — cart must survive (guest cart token).
3. Checkout as pickup with a real reachable email. Confirm exactly **one** order row lands in `doughboss_orders`; note the order number.
4. Confirm the customer-facing confirmation shows the order number and server-computed total matches the cart display.

### 2.4 Order Board / kitchen lifecycle
1. Log in as a `doughboss_kitchen` (or manager) user; open the Order Board. The new order must appear without a manual DB poke (polling is fine; Mercure not required).
2. **Accept with ETA:** accept the order and set an ETA. Verify:
   - Customer receives the *accepted* email (with ETA).
   - Order tracking page (order number + matching email) shows accepted state and a live ETA countdown.
   - Tracking with a **wrong** email reveals nothing (no existence leak — RELEASE_CHECKLIST §3).
3. **Advance to ready.** Verify the *ready* email arrives and the tracker flips to ready.
4. **Complete** the order. Tracker shows completed/collected.
5. Verify admin reporting: the Today strip/dashboard counts the order, and Reports include its line items and total for today's date.

**Pass:** one order, full status lifecycle, both emails, tracker correct at each step, Today strip + Reports reflect it.

## 3. Stripe test-mode verification

1. Settings → Payments: `payment_gateway` = Stripe, mode = **test**. Provide keys env-first: set `DOUGHBOSS_STRIPE_TEST_SK` (secret key) as an env var/constant; publishable test key in settings. Confirm the secret field stays write-only (blank on reload = kept).
2. Confirm `stripe_ready()` is now true (Payments settings panel shows gateway active; checkout offers card payment).
3. **Webhook registration:** in the Stripe test dashboard add an endpoint pointing at `https://<staging>/wp-json/doughboss/v1/stripe-webhook` (events: payment_intent succeeded/failed at minimum). Put the endpoint's signing secret in `DOUGHBOSS_STRIPE_TEST_WHSEC`.
4. Place one pickup order paying with test card `4242 4242 4242 4242` (any future expiry/CVC). Verify:
   - Payment succeeds; order records `payment_method = stripe` and a PaymentIntent id.
   - Stripe dashboard shows the PaymentIntent with the AUD amount **equal to the server total**.
   - Webhook delivery shows 200 in the Stripe dashboard.
   - Order proceeds through §2.4 lifecycle as a paid order.
5. Negative checks (RELEASE_CHECKLIST §4): a declined test card (`4000 0000 0000 0002`) does not create a paid order; replaying the same PaymentIntent against a second checkout is rejected.
6. **Refund:** from the admin order screen, refund the paid order. Verify the refund appears in the Stripe test dashboard and the order reflects refunded status.

**Pass:** one full card payment, webhook 200s, decline + replay rejected, refund round-trips.

## 4. Tyro sandbox verification (when credentials arrive)

Tyro rides Mastercard MPGS hosted sessions (`includes/class-doughboss-tyro.php`).

1. Settings → Payments: gateway = **Tyro**, mode = **Sandbox/test**. Fill: Merchant ID, API version, and **Host** = `https://test-tyro.mtf.gateway.mastercard.com` (the MTF sandbox host). Password env-first: `DOUGHBOSS_TYRO_TEST_PASSWORD`; webhook secret in `DOUGHBOSS_TYRO_TEST_WHSEC`. `tyro_ready()` requires gateway=tyro + merchant id + password.
2. Click **Test Connection** on the settings page. It authenticates merchant id + password against the gateway without moving money. Fix a 502 "could not authenticate" before proceeding.
3. Place one pickup order via the hosted-session card form with an MPGS sandbox test card. Verify: server-side PAY completes (Tyro requires the merchant server to submit PAY — success is confirmed server-side, not by the browser), order records `payment_method = tyro` and the gateway order reference, amount matches server total.
4. Webhook endpoint (if Tyro notifications are configured): `https://<staging>/wp-json/doughboss/v1/tyro-webhook` — signature-gated, expect 200 on delivery.
5. **If session.js fails to load** (blank/broken card fields): check devtools Console/Network for the script URL — it is served from the configured Tyro host, so verify the Host setting is exactly the MTF URL above (https, no trailing path typo), no CSP/adblock/proxy is blocking `*.mastercard.com`, and the configured API version exists on that host. Re-run Test Connection to separate credential failures from asset-load failures.

**Pass:** Test Connection OK, one sandbox hosted-session payment confirmed server-side.

## 5. Go-live gate checklist

Do not touch production until every box is checked. This executes RELEASE_CHECKLIST §6 with DoughBoss specifics; mechanics are in the Deployment Manual §2–5.

- [ ] Sections 1–3 above passed on staging (and §4 if launching with Tyro).
- [ ] Fresh production backup exists — database **and** files (UpdraftPlus per Deployment Manual §1.4) — and the restore path has been read.
- [ ] All secrets are env-vars/constants, none pasted into the DB where avoidable, none committed: `DOUGHBOSS_STRIPE_LIVE_SK`, `DOUGHBOSS_STRIPE_LIVE_WHSEC` (or `DOUGHBOSS_TYRO_LIVE_PASSWORD`, `DOUGHBOSS_TYRO_LIVE_WHSEC`).
- [ ] `payment_gateway` deliberately chosen (stripe or tyro), mode flipped to **live**, live webhook endpoint registered against the production URL with the live signing secret.
- [ ] Notifications deliberately decided (see §6 table): each integration either configured and smoke-tested, or intentionally left dormant. `email_on_ready` / `sms_on_ready` toggles set as the owner wants.
- [ ] Zip built from the reviewed commit SHA; deployment has explicit owner approval; rollback owner named.
- [ ] **Rollback plan agreed:** deactivate the plugin (wp-admin or `wp plugin deactivate doughboss`). Deactivation **keeps all data** — tables, orders, settings survive; only `uninstall.php` (full delete) removes data, so never uninstall as a rollback. Reactivating the previous zip restores service.
- [ ] Post-deploy: one safe production test order + payment, then check logs for fatals, webhook failures, and duplicate orders.

## 6. Dormant integrations — activation switches

Every integration is off until its `*_ready()` gate (in `includes/class-doughboss-settings.php`) is true. Env var/constant always beats the stored setting for secrets.

| Integration | Ready gate | What switches it on |
|---|---|---|
| Stripe payments | `stripe_ready()` | `payment_gateway=stripe` + secret key: `DOUGHBOSS_STRIPE_TEST_SK` / `DOUGHBOSS_STRIPE_LIVE_SK` (webhooks: `DOUGHBOSS_STRIPE_TEST_WHSEC` / `DOUGHBOSS_STRIPE_LIVE_WHSEC`) |
| Tyro payments | `tyro_ready()` | `payment_gateway=tyro` + merchant id + password: `DOUGHBOSS_TYRO_TEST_PASSWORD` / `DOUGHBOSS_TYRO_LIVE_PASSWORD` (webhooks: `DOUGHBOSS_TYRO_TEST_WHSEC` / `DOUGHBOSS_TYRO_LIVE_WHSEC`) |
| POSPal mirroring | `pospal_ready()` | POSPal base URL/appid settings + app key: `DOUGHBOSS_POSPAL_APPKEY`; verify with its own Test Connection |
| Mercure real-time | `mercure_ready()` | Mercure hub URL setting + publish JWT: `DOUGHBOSS_MERCURE_PUBLISH_JWT` (board falls back to polling when off) |
| ntfy push notifications | `ntfy_ready()` | ntfy server/topic settings + token: `DOUGHBOSS_NTFY_TOKEN` |
| SMS (ClickSend) | `sms_ready()` | ClickSend username/sender settings + API key: `DOUGHBOSS_CLICKSEND_API_KEY`; per-event toggle `sms_on_ready` |
| Kitchen printer | `printer_ready()` | Printer relay settings + token: `DOUGHBOSS_PRINTER_TOKEN` |
| Customer emails | `email_on_ready()` etc. | On by default (`email_on_ready=1`); needs working WP mail. Templates via `tpl_*` settings |

For any integration you enable at go-live, re-run the relevant slice of §2–4 with it on, and confirm its failure mode stays best-effort (checkout must not fatal when the external service is down — RELEASE_CHECKLIST §5).

---

**Definition of staging-verified:** §1–3 pass on a staging install (plus §4 if shipping Tyro), evidence captured (order numbers, Stripe dashboard screenshots, webhook delivery logs), and the §5 gate is checkable without hand-waving.
