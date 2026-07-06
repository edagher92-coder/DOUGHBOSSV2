# DoughBoss — Maintenance Manual

**Scope:** operational/maintenance guidance for the DoughBoss plugin as it exists in this repository today (plugin `2.12.3`, DB schema `1.7.0`). Written from the actual source (`includes/`, `admin/`, `uninstall.php`, `readme.txt`, `build-zip.sh`) — not from CLAUDE.md's roadmap text, which is itself out of date (see §6).
**Audience:** whoever is responsible for keeping a live DoughBoss install healthy — could be the developer, could be the shop owner with a checklist.
**Not covered:** anything about the actual state of **doughboss.com.au** in production. No live-site tool is available in this environment, so every instruction below describes what the *code* does/requires — it does not confirm what is currently configured or running on the live site. Treat "check X" items as literally unverified until someone with site access checks them.

---

## 1. Monitoring — what exists today (be honest: not much)

**There is no monitoring or alerting layer in this plugin.** There is no dashboard, no health-check endpoint, no email-on-error, no uptime check, no metrics. What exists is plain PHP `error_log()` calls, sprinkled through the six dependency-free integrations plus the migration runner — nothing more. Concretely:

- **What logs, and where:** 11 `error_log()` call sites across 7 files, all guarded by `function_exists( 'error_log' )` (defensive for locked-down hosts that disable the function):
  | File | What it logs |
  |---|---|
  | `includes/class-doughboss-stripe.php:246-247` | Stripe API HTTP error responses |
  | `includes/class-doughboss-pospal.php:480-481, 606-607` | POSPal HTTP errors and a disabled-coupon-revoke no-op |
  | `includes/class-doughboss-pospal-orders.php:223-224` | POSPal order-push failures |
  | `includes/class-doughboss-pospal-sync.php:248-249` | POSPal sync failures |
  | `includes/class-doughboss-mercure.php:162-163, 233-234` | Mercure publish/test transport failures |
  | `includes/class-doughboss-ntfy.php:196-197` | ntfy push failures |
  | `includes/class-doughboss-sms.php:328-329` | ClickSend SMS failures |
  | `includes/class-doughboss-printer.php:758-759` | Printer fetch/report errors |
  | `includes/class-doughboss-migrations.php:75-76` | A migration step throwing (site keeps serving; the failed step retries on the next request) |

  Every one of these logs a short, human-readable status string — the strengths report's claim that no call site leaks a secret or PII holds up; these are genuinely "status only" lines (e.g. `'DoughBoss POSPal error: HTTP ' . $code . ' on ' . $module . '/' . $method`).

- **What does *not* log, ever — a real gap beyond "no aggregation":** the core money/ordering code path never calls `error_log()`. Verified with `grep -c error_log` returning `0` for `includes/class-doughboss-rest-controller.php`, `admin/class-doughboss-admin.php`, `includes/class-doughboss-catering.php`, `includes/class-doughboss-catering-package.php`, `includes/class-doughboss-voucher.php`, `includes/class-doughboss-order.php`, and `includes/class-doughboss-cart.php`. Concretely, none of these leave any server-side trace on failure:
  - A failed `/checkout` (DB transaction rollback, order-number collision exhausted, Stripe PaymentIntent verification mismatch) — the customer gets a REST error response; nothing is written anywhere on the server.
  - **A rejected Stripe webhook signature** at `POST /catering/stripe-webhook` (`includes/class-doughboss-rest-controller.php:2558-2563`) returns HTTP 400 with zero log line — so a misconfigured webhook secret, or someone probing the endpoint, is invisible unless you go looking at the web server's own access log (outside the plugin, outside WordPress).
  - A failed voucher redemption, a failed catering enquiry, a failed admin status change — same story.
  - **Confirmation emails are fire-and-forget.** All four `wp_mail()` call sites (`includes/class-doughboss-rest-controller.php:2648, 2653, 2691, 2696` — catering customer/shop emails and order customer/shop emails) never check the boolean `wp_mail()` returns. If mail delivery silently fails (a very common shared-hosting failure mode), neither the customer nor the shop is ever told, and nothing is logged.
- **Where `error_log()` output actually lands is a hosting/WordPress config question, not a plugin one.** The plugin never touches `WP_DEBUG` / `WP_DEBUG_LOG` (confirmed: zero references anywhere in `includes/`, `admin/`, `doughboss.php`). PHP's `error_log()` with no explicit destination writes to whatever the `error_log` php.ini directive points at:
  - If `wp-config.php` has `define( 'WP_DEBUG_LOG', true )`, WordPress core repoints that ini directive to `wp-content/debug.log` for the duration of the request — so DoughBoss's lines will show up there, mixed in with every other plugin's and PHP's own notices.
  - If `WP_DEBUG_LOG` is off (the stock WordPress default), these lines go to whatever the host's default PHP error log is — could be a cPanel `error_log` file next to the site root, could be a syslog/stderr destination on managed hosting, could be effectively nowhere on some shared hosts. **This cannot be verified from the repo; check the specific host.**
- **Bottom line:** treat "Monitoring" today as *reactive log-reading*, not alerting. Nothing pages anyone. Nothing aggregates across requests. If integration health matters (a POSPal outage, a Mercure hub down, a printer that stopped fetching), a human has to go looking — either at whatever file `error_log` lands in, or by proactively running the health-check commands in §2/§7.

---

## 2. Logs — how to actually look

Because there's no aggregation, "checking the logs" means finding and reading `error_log`'s actual destination for the specific host, then grepping for `DoughBoss` (every line above is prefixed `DoughBoss <Component>: ...` or `DoughBoss <Component> error (...)`) — e.g.:

```bash
grep -i doughboss /path/to/wp-content/debug.log      # if WP_DEBUG_LOG is on
# or wherever the host's PHP error log actually lives — check with the host/hosting panel
```

Beyond grepping logs, the plugin ships a few **built-in, non-destructive health checks** that are more reliable than waiting for something to throw an error, because they actively probe the integration instead of waiting for a failure to be logged:

| Check | How | What it proves |
|---|---|---|
| POSPal connectivity | `wp doughboss pospal-test` (WP-CLI) | Host reachable, appId/appKey signature accepted, coupon promotion rules exist — read-only |
| Mercure push | Settings → Real-time & Notifications → **Test connection** button (`admin/class-doughboss-admin.php:1695`) | Hub reachable and publish JWT accepted |
| POSPal coupon grant | Settings → POSPal POS → **Send test coupon** button (`admin/class-doughboss-admin.php:1604`) | End-to-end grant path works — **use sparingly**, this issues a real coupon against the live POSPal account |
| Voucher campaign pool health | `wp doughboss campaigns` (WP-CLI) | Today's claimed/remaining counts per campaign, including shared `cap_group` pools |
| Recent voucher activity | `wp doughboss voucher-list --limit=20` (WP-CLI) | Spot-check for stuck `issued` vouchers, unexpected redemption volume |
| Menu ↔ POSPal product mapping | `wp doughboss pospal-map --dry-run` (WP-CLI) | Confirms every menu item still matches a POSPal product by name (a menu-item rename or a POSPal-side product rename breaks the match silently otherwise) |
| A specific order's POSPal push body | `wp doughboss pospal-push-order <id> --dry-run` (WP-CLI) | Inspect exactly what would be sent before trusting the live push |

None of these are automated or scheduled (there is no WP-Cron usage anywhere in the plugin — confirmed by grep, zero hits for `wp_schedule_event`/`wp_next_scheduled` in `includes/` or `admin/`). Each one is a manual, on-demand action a person has to run — worth building into the weekly/monthly cadence in §7.

---

## 3. Backups — what actually needs to be in one

DoughBoss stores everything in standard WordPress mechanisms; there is no separate database, no external persistence layer, no plugin-specific backup tooling. A standard **full WordPress backup (database + uploads)** covers it, provided it actually includes all of the following:

### Database — 6 custom tables (created by `includes/class-doughboss-activator.php`, all under `$wpdb->prefix`)
- `doughboss_orders` — every order ever placed (customer PII: name/email/phone/address).
- `doughboss_order_items` — line items per order.
- `doughboss_locations` — shop/location configuration.
- `doughboss_catering_enquiries` — the catering pipeline (enquiry → quote → deposit → balance), including Stripe PaymentIntent IDs for deposit/balance legs.
- `doughboss_vouchers` — issued/redeemed voucher codes, values, campaign attribution.
- `doughboss_voucher_redemptions` — the audit trail (idempotency key, amount applied, channel, POSPal ticket number) that makes the voucher engine's "never double-fire" guarantee possible. **Losing this table without the vouchers table also being consistent could reopen already-redeemed vouchers** — if you ever restore from a backup, restore both tables together, not one without the other.

### Options (all under `wp_options`, standard WP backup covers these automatically)
- `doughboss_settings` — the single array option holding *every* configuration value: currency/tax/fees, sizes/toppings, Stripe keys (see the callout below), POSPal store credentials, Mercure/ntfy/SMS/printer config, `app_origin` (the Console app's CORS allow-list), `voucher_campaigns`, `pospal_product_map`. **This is the one option to never lose** — the §2 Codebase report's Bug #2 (now fixed in 2.12.3) existed precisely because this option was at risk of silent partial data loss on every Settings save; a backup is the last line of defense if that class of bug recurs.
- `doughboss_db_version` — schema checkpoint; if lost, the migration runner (`includes/class-doughboss-migrations.php`) just re-runs from `0`, which is safe (every step is idempotent / dbDelta is additive) but will re-fire capability-seeding etc. on the next page load.
- `doughboss_printer_watermark` (`includes/class-doughboss-printer.php:47`) — the last order ID fetched by the receipt printer poller. Not covered by `uninstall.php`'s cleanup (confirmed: `uninstall.php:47-49` only deletes `doughboss_settings` and `doughboss_db_version`), so it will survive an uninstall/reinstall cycle unless removed manually — worth knowing if you ever see the printer skip or re-fetch tickets after a restore.

  **Secret-storage callout for backups specifically:** Stripe's secret key and webhook secret (`stripe_test_sk`/`stripe_live_sk`/`stripe_test_whsec`/`stripe_live_whsec`) have **no environment-variable override** — `DoughBoss_Settings::stripe_secret_key()` and `::stripe_webhook_secret()` (`includes/class-doughboss-settings.php:316-327`) read *only* from the `doughboss_settings` option, unlike POSPal/Mercure/ntfy/ClickSend/printer, which all check a `DOUGHBOSS_*` constant/env var first and only fall back to the option (e.g. `pospal_app_key()` at `includes/class-doughboss-settings.php:368-380`). Practically: **every database backup of this site contains the live Stripe secret key in plaintext inside `wp_options`.** That's not a bug (Stripe requires the secret key to live somewhere the server can read), but it does mean database backups are exactly as sensitive as the Stripe dashboard itself and should be stored/encrypted accordingly — and anyone who *can* set env vars on the host should consider doing so for Stripe too, for parity with the other five integrations.

### Content (standard WP export/backup covers these)
- `doughboss_item` custom post type — the menu (with `_doughboss_price`/`_doughboss_item_type`/`_doughboss_available` meta) and its `doughboss_category` taxonomy terms.
- `doughboss_catering_package` custom post type (registered in `includes/class-doughboss-catering-package.php`, confirmed alongside `doughboss_item` in `uninstall.php:35-45`) — catering package definitions. **Easy to miss** because CLAUDE.md's own architecture summary doesn't mention this CPT at all.
- Any uploaded media (menu photography, brand assets) referenced by menu items — standard `wp-content/uploads` backup.

### Explicitly *not* part of the WordPress backup, and *not* covered by any backup discussed above
- The **staff Console** (`app/`) stores its WordPress Application Password in the browser's `localStorage` on whatever tablet it's installed on — that credential lives on the device, not in WordPress data, and isn't restorable from a site backup. If a kitchen tablet is lost/replaced, that Application Password should be revoked from **Users → your user → Application Passwords** in wp-admin and a new one issued — it isn't a "restore from backup" scenario.
- The **demo site** (`demo/`) is static HTML deployed to GitHub Pages via `.github/workflows/pages.yml` — it's a marketing artifact, not part of the live ordering system, and has no data to back up.

---

## 4. Updating dependencies

**There are none, by design.** No `composer.json`, no `package.json`, no bundler, no vendored SDKs — confirmed by the project's own conventions (`CLAUDE.md`: "No build pipeline / no Composer / no npm") and by the repo's actual file listing (no `vendor/`, no `node_modules/`, `scripts/dev-check.sh` explicitly checks for a `composer.json` before even trying to run `phpcs` and skips cleanly if there isn't one). All six external integrations (Stripe, POSPal, Mercure, ntfy, ClickSend SMS, receipt printer) are hand-rolled HTTP clients over `wp_remote_*` — there is nothing to `composer update` or `npm audit`.

What *does* need periodic attention instead:

- **WordPress core / PHP version compatibility.** The plugin declares `Requires at least: 6.0` and `Requires PHP: 7.4` in three places that should agree and currently do (`doughboss.php:6-7`, `readme.txt:4,6`, `README.md:32-33`) — but the **`readme.txt` `Tested up to: 6.5`** (`readme.txt:5`) is the one concrete "last verified against" data point in the repo, and it is not being kept current: `readme.txt`'s own `Stable tag: 2.12.1` (`readme.txt:7`) is already two patch versions behind the actual `Version: 2.12.3` in `doughboss.php:6`, and its changelog section stops at `= 2.12.1 =` — none of today's three bug fixes (cart-token, settings-wipe, Stripe/POSPal secret-echo) are recorded there. **Action:** whenever the plugin is tested against a newer WordPress release, bump `Tested up to` in `readme.txt`; whenever the version number changes, update `Stable tag` and add a changelog entry in the same commit — right now this is not happening.
- **Updating the plugin files themselves** is a straightforward file replacement, *not* a package-manager operation: run `bash build-zip.sh` to produce `dist/doughboss.zip`, then in wp-admin **Plugins → Add New → Upload Plugin** (WordPress will prompt to replace the existing install), or copy files directly into `wp-content/plugins/doughboss/` if working from source. Either way, **no deactivate/reactivate step is required for schema changes** — `DoughBoss_Migrations::run()` is invoked on every page load (`includes/class-doughboss.php:108`) and only does work when the stored `doughboss_db_version` option is behind the code's `DOUGHBOSS_DB_VERSION` constant, so an upgrade takes effect on the very next request after the files land, guarded by a 5-minute transient lock (`doughboss_migrating`) against concurrent visitors racing the migration.
- **What actually ships in the zip vs. what lives in the repo:** `build-zip.sh` stages only `doughboss.php`, `uninstall.php`, `readme.txt`, `README.md`, `includes/`, `admin/`, `public/`, optionally `scripts/seed-menu.php` and a `languages/` directory if present. It does **not** package `app/` (the staff Console PWA — a separate deployable) or `demo/` (the GitHub Pages marketing site) — those are updated/deployed independently of a plugin update.

---

## 5. Security checks — a recurring checklist

Run through this whenever doing a maintenance pass (see §7 for cadence) or after any Settings-page change:

1. **Are the secret-storage fields actually write-only?** Load **DoughBoss → Settings**, sections **Payments (Stripe)**, **POSPal POS (in-store coupons)**, and **Real-time & Notifications**; view page source. All 11 secret fields — `stripe_test_sk`, `stripe_live_sk`, `stripe_test_whsec`, `stripe_live_whsec`, `pospal_app_key`, `pospal2_app_key`, `pospal3_app_key`, `mercure_publish_jwt`, `ntfy_token`, `clicksend_api_key`, `printer_token` — should render **blank** with a "leave blank to keep current" message, never the actual stored value. (All 11 now go through the shared `keep_secret()` helper at `admin/class-doughboss-admin.php:292-298`; this was the fix shipped in 2.12.3 for the previously-echoing Stripe + POSPal fields.)
2. **Is a routine Settings save still preserving unlisted keys?** After any save, spot-check that `app_origin` (feeds the Console app's CORS allow-list) and `voucher_campaigns` are unchanged in the `doughboss_settings` option — `sanitize_settings()` now seeds `$clean` from `DoughBoss_Settings::all()` (`admin/class-doughboss-admin.php:175-176`) rather than an empty array, but this is exactly the kind of regression that's invisible until someone checks.
3. **Are capabilities still scoped the way they should be?** Three capabilities exist: `manage_doughboss` (Orders/Catering/Shops/Vouchers/Settings screens), `manage_doughboss_kds` (Order Board only), `redeem_doughboss_vouchers` (Voucher Scan only — can redeem, can never mint). Confirm no role other than Administrator and the dedicated `doughboss_kitchen` role carries these (`includes/class-doughboss-activator.php:291-322`), and that no kitchen-tablet account has been *upgraded* to a role with `manage_doughboss` "just to make something work" — that would hand a shop tablet the ability to change Settings/Stripe keys.
4. **Is HTTPS actually in effect?** The plugin does **not** enforce HTTPS itself anywhere — the only `is_ssl()` reference in the whole codebase (`includes/class-doughboss-cart.php:79`) just decides whether to mark the cart cookie `Secure`, i.e. it *follows* whatever the site's current scheme is rather than requiring one. Enforcing HTTPS site-wide is a hosting/WordPress-level setting (`siteurl`/`home` on `https://`, plus a host-level redirect), not something to look for in this plugin's code — but it matters a great deal here because Stripe card fields and the checkout flow both assume a secure origin. Check the site actually redirects HTTP → HTTPS.
5. **Any WordPress core or plugin updates pending?** Check wp-admin **Dashboard → Updates**. There are no other plugins DoughBoss depends on, but WordPress core itself should stay current against `Requires at least` (see §4).
6. **POSPal diagnostic routes still capability-gated?** The REST controller exposes POSPal probing endpoints (including one the prior audit flagged as brute-forcing 11 candidate endpoint names against the live account) — confirm these still sit behind `verify_admin`/the `manage_doughboss` capability check and haven't been left reachable during any debugging session. Not something this pass changed; just don't let it regress.
7. **Any secrets showing up somewhere they shouldn't?** Re-grep the codebase for the pattern that caused the original bug — `esc_attr( $settings[` — to make sure no *new* secret field was added the "old" (echoing) way instead of via `keep_secret()`.

---

## 6. A maintenance item that is itself part of maintenance: keep the docs honest

Three separate top-level documents in this repo are stale to different degrees, independent of the already-known `CLAUDE.md` staleness:

- **`readme.txt`** — `Stable tag: 2.12.1` vs. the actual `Version: 2.12.3` in `doughboss.php`; changelog stops at `2.12.1`; and its own FAQ (`readme.txt:43-47`, "Does this process payments? **Not yet**... payment integration (e.g. Stripe) is planned for a future release") directly contradicts its *own* changelog eleven lines below, which documents Stripe shipping in `2.5.0`.
- **`README.md`** — frozen at roughly the 2.4 feature set (menu, builder, cart/checkout, order tracking, Live Order Board). It doesn't mention Stripe, vouchers, catering, POSPal, or the staff Console at all.
- **`CLAUDE.md`** — already identified and partly addressed in `docs/DoughBoss-Codebase-Strengths-Weaknesses-Report.md` (states v2.5.0/DB v1.4.0/"3 tables", says catering "has zero code", lists shipped features as roadmap items).

None of these three has been fixed as part of today's work. **Recommendation:** fold a "docs pass" into the quarterly maintenance cycle (§7) — update `readme.txt`'s `Stable tag`/`Tested up to`/changelog and `README.md`'s feature list in the same change that bumps the plugin version, the same discipline already recommended for `CLAUDE.md`.

---

## 7. Regular maintenance schedule

A realistic cadence for a small single/multi-shop operation — not a large SaaS on-call rotation:

### Weekly (~15 minutes)
- Grep whatever destination `error_log()` actually lands on for this host for `DoughBoss` lines (see §2) — look for repeated POSPal/Stripe/Mercure/ntfy/SMS/printer error lines, which would indicate an integration has been silently failing.
- Run `wp doughboss campaigns` — check voucher pool usage against expectations (a campaign hitting its `daily_cap` every day might warrant a bigger pool or a pricing rethink; a campaign claiming zero when it shouldn't suggests something's broken upstream, e.g. a promo QR code or link).
- Skim recent orders in **DoughBoss → Orders** for anything stuck in an unexpected status, and recent catering enquiries in **DoughBoss → Catering** for anything sitting unactioned.
- If Stripe/POSPal/printer are in active use: confirm a spot-check real order actually reached the till/printer/kitchen board as expected.

### Monthly (~30–60 minutes)
- Run `wp doughboss pospal-test` (if POSPal is enabled) — confirms the connection is still live and the signature still valid; POSPal API/host changes wouldn't otherwise surface until an order fails to push.
- Click **Test connection** on the Mercure settings section (if enabled).
- Run `wp doughboss pospal-map --dry-run` — catch any menu item renamed on either side (WordPress or POSPal) that's silently fallen out of the push mapping.
- Review the Stripe dashboard directly (outside this plugin) for the last month's payments/disputes/refunds — the plugin itself surfaces no refund UI in the order board today.
- Check **wp-admin → Updates** for WordPress core updates; apply on a staging copy first if one exists.
- Re-read `wp doughboss voucher-list --limit=50` for anything that looks like abuse (repeated claims from the same phone/email around a daily-cap boundary, unusually high redemption amounts).

### Quarterly (~half a day)
- Re-check WordPress core version and PHP version on the host against `Requires at least: 6.0` / `Requires PHP: 7.4` (`doughboss.php:6-7`) and the `readme.txt` `Tested up to` value — bump `Tested up to` if a newer core version has actually been verified.
- Update `readme.txt`'s `Stable tag` + changelog and `README.md`'s feature list to match the current `Version` (see §6) — do this in the same pass as any version bump going forward, not as a quarterly catch-up, but treat it as a quarterly backstop for now.
- Re-run the full security checklist in §5 end to end, not just the "did anything change" spot checks from weekly/monthly.
- Review open items from `docs/DoughBoss-Codebase-Strengths-Weaknesses-Report.md`'s "Recommended fix order" table — as of this writing, items 4–7 (rate-limiter proxy-awareness, `CLAUDE.md` regeneration, a minimal test harness, the REST-controller god-class split) are still "Not started."
- Confirm a recent full WordPress backup exists and — at least once a year, ideally quarterly — actually **restore it somewhere and verify it** (an untested backup is not a backup). Pay particular attention to restoring `doughboss_vouchers` and `doughboss_voucher_redemptions` together (see §3) if a partial restore is ever needed.
- If any host env vars are set for the five integrations that support them (`DOUGHBOSS_POSPAL_APPKEY[_2/_3]`, `DOUGHBOSS_MERCURE_PUBLISH_JWT`, `DOUGHBOSS_NTFY_TOKEN`, `DOUGHBOSS_CLICKSEND_API_KEY`, `DOUGHBOSS_PRINTER_TOKEN`), confirm they're still set after any hosting migration/host change — a host migration is exactly the kind of event that silently drops environment variables while the database-backed fallback quietly keeps working, masking the loss until someone reads `includes/class-doughboss-settings.php`'s env-first logic and wonders why the env var "isn't doing anything."

---

## Appendix — quick command reference

```bash
# Syntax-lint every PHP file (does NOT check logic correctness — see the
# Strengths & Weaknesses report §6 on the complete absence of automated tests)
bash scripts/dev-check.sh

# Build an installable zip for a plugin update
bash build-zip.sh   # -> dist/doughboss.zip

# WP-CLI health/maintenance commands (run on the host, in the WordPress install)
wp doughboss pospal-test                       # read-only POSPal connectivity check
wp doughboss campaigns                         # voucher campaign pool usage
wp doughboss voucher-list --limit=20           # recent vouchers + status
wp doughboss voucher-redeem <CODE> --subtotal=<amt> [--channel=online|instore]
wp doughboss voucher-void <ID>                 # void an issued, un-redeemed voucher
wp doughboss pospal-products [--store=<n>]     # list POSPal products (uid/name/price)
wp doughboss pospal-map [--dry-run] [--store=<n>]   # rebuild the menu<->POSPal product map
wp doughboss pospal-push-order <ORDER_ID> [--dry-run] [--store=<n>]
wp doughboss seed-menu [--dry-run]             # idempotent: seed/refresh the in-store menu
```
