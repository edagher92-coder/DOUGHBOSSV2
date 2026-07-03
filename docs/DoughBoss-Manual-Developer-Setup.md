# DoughBoss — Developer Setup Manual

This manual is for a developer setting up DoughBoss for the first time — on a
local WordPress install or on a staging/dev site. It is a plain-PHP WordPress
**plugin**, not a standalone service: there is nothing to "run" outside of
WordPress itself, and there is no build step.

Facts below were verified directly against the code on **2026-07-03**
(plugin `doughboss.php` header, `readme.txt`, `includes/class-doughboss-activator.php`,
`includes/class-doughboss-settings.php`, `includes/class-doughboss-migrations.php`,
`scripts/dev-check.sh`, `admin/class-doughboss-admin.php`). Where the code and
`readme.txt` disagree, that is called out rather than papered over.

---

## 1. Requirements

From the plugin header (`doughboss.php`, lines 1–14):

| Requirement | Value |
|---|---|
| WordPress | **6.0 or later** (`Requires at least: 6.0`) |
| PHP | **7.4 or later** (`Requires PHP: 7.4`) |
| Plugin version | **2.12.3** (`DOUGHBOSS_VERSION`, `doughboss.php`) |
| DB schema version | **1.7.0** (`DOUGHBOSS_DB_VERSION`, `doughboss.php`) |
| Text domain | `doughboss` |
| License | GPL-2.0-or-later |

**Note a real drift you will notice:** `readme.txt` still declares
`Stable tag: 2.12.1` (readme.txt:7) while `doughboss.php` and `CLAUDE.md` say
**2.12.3**. `Requires at least`/`Requires PHP` agree between the two files (6.0 /
7.4), so those numbers are safe to trust; the version number is not — go by
`DOUGHBOSS_VERSION` in `doughboss.php`, and bump `readme.txt`'s `Stable tag`
yourself if you cut a release, because nothing currently keeps the two in sync.

No `composer.json`, no `package.json`, no `Dockerfile`, no `wp-env` config
exists anywhere in this repo — confirmed by directory listing. You need your
own WordPress environment (a local install, LocalWP, a Docker WordPress image
you bring yourself, or a staging site) with MySQL/MariaDB and WP-CLI if you
want to use the seeder/CLI commands in §5. There is nothing in the repo that
provisions one for you.

Database: any MySQL/MariaDB WordPress already runs on. Be aware the three
newer tables (`doughboss_orders`, `doughboss_catering_enquiries`,
`doughboss_voucher_redemptions`) default `created_at`/`updated_at`/`redeemed_at`
to the sentinel `'0000-00-00 00:00:00'`
(`includes/class-doughboss-activator.php`) — this is rejected by MySQL 5.7+/8
running in strict mode with `NO_ZERO_DATE`. If your local MySQL has strict
mode on and activation errors on `dbDelta`, this is why (see §7).

---

## 2. Installation

DoughBoss is installed like any WordPress plugin — there is no separate
service to stand up.

### Option A — as a zip (what an end user does)
1. `bash build-zip.sh` from the repo root → produces `dist/doughboss.zip`
   (gitignored; staged from `doughboss.php`, `uninstall.php`, `readme.txt`,
   `README.md`, `includes/`, `admin/`, `public/`, plus `scripts/seed-menu.php`
   and `languages/` if present — see `build-zip.sh`).
2. In wp-admin: **Plugins → Add New Plugin → Upload Plugin**, upload the zip,
   **Install Now**, then **Activate**.

### Option B — as a symlinked/copied dev checkout (faster iteration)
1. Copy or symlink this repo into `wp-content/plugins/doughboss/` on your
   WordPress install (the directory name doesn't have to be `doughboss`, but
   keep it simple).
2. In wp-admin: **Plugins**, find "DoughBoss", click **Activate**.
3. Edit files in place; WordPress serves PHP directly, so there is no compile
   step — reload the page to see changes. Front-end JS/CSS are enqueued with
   `DOUGHBOSS_VERSION` as the cache-busting query string
   (`includes/class-doughboss-assets.php`), so during dev you may need a hard
   refresh (or bump the version) to see JS/CSS edits if your browser caches
   aggressively.

### What activation actually does (`includes/class-doughboss-activator.php`, `DoughBoss_Activator::activate()`)
Verified by reading the class in full:
1. **`create_tables()`** — runs `dbDelta()` for **all six** custom tables:
   `{prefix}doughboss_orders`, `{prefix}doughboss_order_items`,
   `{prefix}doughboss_locations`, `{prefix}doughboss_catering_enquiries`,
   `{prefix}doughboss_vouchers`, `{prefix}doughboss_voucher_redemptions`.
   (`dbDelta` is additive-only — re-running it on upgrade adds new columns but
   never drops/renames existing ones.)
2. **`add_default_options()`** — seeds the single `doughboss_settings` option
   *only if it doesn't already exist* (`false !== get_option(...)` short-circuits
   on a re-activation) with AUD-ish defaults: `$` symbol, `AUD`, 10% tax,
   GST-inclusive on, pickup enabled/delivery off, ordering open, 3 pizza sizes,
   5 toppings.
3. **`add_capabilities()`** — grants `manage_doughboss`, `manage_doughboss_kds`,
   and `redeem_doughboss_vouchers` to the `administrator` role, and creates the
   low-privilege `doughboss_kitchen` role (capabilities: `read`,
   `manage_doughboss_kds`, `redeem_doughboss_vouchers` — a kitchen tablet login
   that can never issue a voucher or touch WP admin generally).
4. Registers the `doughboss_item` CPT and the catering-package post type, then
   **flushes rewrite rules** so their permalinks work immediately.
5. Writes `doughboss_db_version = DOUGHBOSS_DB_VERSION` (currently `1.7.0`) to
   options.

**Deactivation** (`includes/class-doughboss-deactivator.php`) only flushes
rewrite rules — no data is touched. **Uninstall** (`uninstall.php`, triggered
only by deleting the plugin from the Plugins screen, not deactivating it) is
destructive: drops all six tables, deletes `doughboss_item`/catering-package
posts, deletes the `doughboss_settings` and `doughboss_db_version` options,
removes the added capabilities/role, and cleans up cart/idempotency transients.
Don't run "delete" on a site with real orders you want to keep.

### After activating, the minimum to see something on the front end
1. **DoughBoss → Settings** in wp-admin — the settings page is a single long
   page (not tabs) with sections in this order: **Menu** (one-click "Import
   standard menu" seeder), **Store**, **Pizza Sizes**, **Toppings**,
   **Payments (Stripe)**, **POSPal POS (in-store coupons)** + additional
   stores, **Real-time & Notifications** (Mercure / ntfy / SMS / Receipt
   printer). (`admin/class-doughboss-admin.php`, `render_settings_page()`.)
2. **DoughBoss → Orders** (top of the admin menu), plus submenus **Catering**,
   **Shops**, **Vouchers**, **Settings**; and two standalone
   tablet-friendly screens outside the submenu group, **Order Board**
   (`manage_doughboss_kds` cap) and **Voucher Scan**
   (`redeem_doughboss_vouchers` cap) — both reachable by the low-privilege
   kitchen role without a full admin login (`register_menu()`).
3. Add at least one `doughboss_item` (menu item) manually, or click
   **Import standard menu** on the Settings page (idempotent — safe to
   re-run; also available headless as `wp doughboss seed-menu`).
4. Drop the shortcodes on real pages: `[doughboss_menu]`, `[doughboss_builder]`,
   `[doughboss_cart]`, `[doughboss_order_tracking]`, `[doughboss_shop_picker]`
   (`includes/class-doughboss-shortcodes.php`). Assets only enqueue on pages
   that actually contain one of these shortcodes (or via the
   `doughboss_load_assets` filter) — if the storefront looks unstyled/inert,
   check the page really has the shortcode in its content, not just the
   builder's visual embed.

---

## 3. Environment variables

DoughBoss reads integration secrets **env-first**: a defined PHP constant (set
in `wp-config.php`) or an OS/webserver environment variable **always wins**
over whatever is stored in the `doughboss_settings` option, so secrets never
have to sit in the database (and therefore never end up in a DB backup). All
of these are read in `includes/class-doughboss-settings.php`; every one is
optional and every integration is **off by default** until its `*_ready()`
gate passes (see §6).

| Variable | Read by | Purpose | Also settable in wp-config.php as a `define()`? |
|---|---|---|---|
| `DOUGHBOSS_POSPAL_APPKEY` | `pospal_app_key()` (settings.php:375) | POSPal store #1 secret App Key (signs POS API calls) | Yes |
| `DOUGHBOSS_POSPAL_APPKEY_2` | `pospal_store_key(2)` (settings.php:519) | POSPal store #2 secret App Key (multi-store) | Yes |
| `DOUGHBOSS_POSPAL_APPKEY_3` | `pospal_store_key(3)` (settings.php:519) | POSPal store #3 secret App Key (multi-store) | Yes |
| `DOUGHBOSS_MERCURE_PUBLISH_JWT` | `mercure_publish_jwt()` (settings.php:638) | JWT to publish real-time order-board updates to a Mercure hub | Yes |
| `DOUGHBOSS_NTFY_TOKEN` | `ntfy_token()` (settings.php:714) | Bearer token for ntfy staff push alerts | Yes |
| `DOUGHBOSS_CLICKSEND_API_KEY` | `clicksend_api_key()` (settings.php:770) | ClickSend API key for outbound customer SMS | Yes |
| `DOUGHBOSS_PRINTER_TOKEN` | `printer_token()` (settings.php:846) | Shared token authenticating the CloudPRNT/ePOS receipt-printer exchange | Yes |
| `GEMINI_API_KEY` | *not read by the plugin at all* | Used only by `scripts/gemini.py` for generating docs/prototype imagery — **dev tooling, not runtime**. Never referenced anywhere in `includes/` or `admin/`. | N/A (shell env only) |

**None of these are required for the plugin to run.** With no env vars and no
keys entered in Settings, every optional integration (Stripe, POSPal, Mercure,
ntfy, SMS, printer) stays dormant and DoughBoss behaves as a plain
order-now-pay-on-pickup/delivery system — this is the state a fresh dev
checkout should be in.

Every one of these fields also has a same-named field in **DoughBoss →
Settings** as a fallback (e.g. "App Key" under POSPal). As of the v2.12.3 fix
described in `CLAUDE.md`, all seven of these secret fields (4 Stripe + 3
POSPal) are now **write-only**: the page never echoes the stored value back
into the HTML, and posting the field blank on save preserves whatever is
currently stored (`keep_secret()`, `admin/class-doughboss-admin.php`) — do not
expect to "view" a saved secret in the Settings screen; that's intentional.

Two non-secret settings worth knowing about because they gate real
functionality and are easy to overlook:
- `app_origin` — the CORS allow-list origin for the separate Staff Console PWA
  (`app/`); defaults to `https://edagher92-coder.github.io`
  (`class-doughboss-settings.php:137`). If you stand up your own Staff Console
  origin, update this or cross-origin REST calls will be blocked.
- `orders_email` — where order/catering notification emails go; defaults to
  `orders@doughboss.com.au` (`class-doughboss-settings.php:83`). Change this
  on any install that isn't the real Dough Boss business, or notification
  emails will go to someone else's inbox.

---

## 4. Local development process

**There is no build step, and none is intended.** Confirmed: no `package.json`,
no `composer.json`, no bundler config anywhere in the repo. It is plain PHP
(WordPress Coding Standards style — tabs, Yoda conditions, full braces) plus
vanilla JS/CSS enqueued directly with `DOUGHBOSS_VERSION` cache-busting
(`includes/class-doughboss-assets.php`). Do not add a bundler, Composer, or
npm — that is a stated project convention, not an oversight.

Day-to-day loop:
1. Edit PHP/JS/CSS directly in your `wp-content/plugins/doughboss/` checkout.
2. Reload the affected wp-admin or front-end page — PHP is interpreted on
   every request, so changes are live immediately.
3. Before committing, lint every PHP file for syntax errors:
   ```bash
   bash scripts/dev-check.sh
   ```
   This runs `php -l` over every `*.php` file (excluding `.git`, `vendor`,
   `node_modules`, `dist`) and prints a PASS/FAIL summary. It looks for `php`
   on `$PATH` first, then falls back to `/usr/bin/php` and
   `/usr/local/bin/php` — if none exist it prints `RESULT: SKIPPED`, not a
   failure. **By explicit design this script always exits `0`** (a comment at
   the top says so) so it can run unattended as a session-start hook without
   ever blocking or aborting a session — a green `dev-check.sh` run tells you
   the PHP parses, and nothing about whether the logic is correct.
   Equivalent manual commands if you don't want the wrapper:
   ```bash
   find includes admin -name '*.php' -print0 | xargs -0 -n1 php -l
   php -l doughboss.php && php -l uninstall.php
   ```
4. If `php` isn't on `$PATH` in your shell, it's also at `/usr/bin/php` in
   this environment — `dev-check.sh` already checks that location for you.
5. To produce an installable artifact for manual upload/QA, run
   `bash build-zip.sh` → `dist/doughboss.zip` (gitignored; rebuild after every
   change you want to test via Upload Plugin rather than a symlinked checkout).
6. Git workflow: develop on the designated `claude/*` branch (do not commit to
   a shared base directly), commit only when asked, then
   `git push -u origin <branch>` and open a **draft** PR. Honor `.gitignore`
   (`/dist/`, `*.zip`, `/vendor/`, `/node_modules/`, editor/OS junk, the
   Gemini `.cache/` and `docs/proto-img/` scratch dirs — none of these should
   ever be committed).

If you need WP-CLI commands beyond core WordPress ones, DoughBoss registers
its own under `wp doughboss ...` (only loaded when `WP_CLI` is true —
`includes/class-doughboss-cli.php`): `seed-menu` (idempotent menu import,
supports `--dry-run`), `pospal-test` (read-only POSPal connectivity check),
`pospal-map`, `campaigns`, `voucher-claim`, `voucher-list`, `voucher-redeem`,
`voucher-void`. None of these require a build step either — they run against
whatever code is currently on disk.

---

## 5. Running tests

**Be honest with yourself here: there is no automated test suite.** Verified
by directory listing — no `tests/` directory, no PHPUnit config, no
`composer.json` to even install PHPUnit from. The only thing that runs
automatically is `scripts/dev-check.sh`, and as noted above that is a **syntax
lint only** (`php -l`) that always exits `0` — it will happily pass a build
where the logic is completely wrong, it only catches things that fail to
parse. The one CI workflow in the repo, `.github/workflows/pages.yml`, deploys
the static `demo/` site to GitHub Pages — it does not test, lint, or even
touch the actual plugin code in `includes/`/`admin/`.

So "testing" a change here means, in order:
1. `bash scripts/dev-check.sh` — catch syntax errors before you even open a
   browser.
2. Manual QA against a real WordPress install, driving the actual flow you
   changed end to end (not just eyeballing the diff).

`docs/DoughBoss-Codebase-Strengths-Weaknesses-Report.md`, §6 ("Zero automated
tests for a payments/vouchers/POS system") names the specific classes that are
highest-risk precisely because nothing automated exercises them — use this as
your manual-QA priority list, in order of what would hurt most if wrong:

- **`DoughBoss_Cart::totals()`** (`includes/class-doughboss-cart.php`) — has
  two branches (GST-inclusive vs. exclusive); exercise both by toggling
  "Prices include GST" in Settings and adding items/toppings, checking the
  displayed subtotal/tax/total match hand-computed numbers.
- **`DoughBoss_Voucher::redeem()`** (`includes/class-doughboss-voucher.php`) —
  claim a voucher via `wp doughboss voucher-claim`, then redeem it twice in a
  row (should reject the second as already-redeemed) and check the audit
  table (`{prefix}doughboss_voucher_redemptions`) got exactly one row with the
  right `idempotency_key`.
- **`DoughBoss_Stripe::verify_payment()`** and the webhook signature check
  (`includes/class-doughboss-stripe.php`) — with Stripe in **test** mode, run
  a checkout with a Stripe test card, confirm the order is only marked
  paid once the PaymentIntent is `succeeded` and amount/currency match, and
  confirm a replayed PaymentIntent on a second checkout attempt is rejected.
- **`DoughBoss_Coupon_Code`** check-character math (voucher code
  generation/validation) — deliberately mistype a single character of a real
  voucher code at redemption time and confirm it's rejected, not silently
  "close enough" accepted.

Beyond those four, a baseline manual pass before merging anything that
touches checkout/cart/vouchers should also cover: placing an order as a fresh
guest (new cookie — this is exactly the flow the token-case-mismatch bug in
`CLAUDE.md` broke), viewing it on the Live Order Board, Accept → Preparing →
Ready on the board, and looking the order up via
`[doughboss_order_tracking]` with the matching email. There is currently no
written checklist file for this beyond the audit doc's §6 — if you formalize
one, `docs/DoughBoss-Codebase-Strengths-Weaknesses-Report.md` is the right
place to extend, since it already names the highest-risk surface.

If you want to actually add automated coverage, the audit doc suggests
WP_Mock or Brain\\Monkey specifically because the four classes above are
static/pure-ish and don't need a full WordPress bootstrap to unit-test — that
would be new infrastructure (a `tests/` dir + a `composer.json` dev
dependency), not something that exists today.

---

## 6. Common errors and fixes

1. **"I entered a POSPal App Key in Settings and enabled POSPal, but the coupon
   grant on voucher claim still does nothing."**
   Two separate gates have to pass, not one. `pospal_ready()` requires
   `pospal_enabled()` **and** a host **and** an App ID **and** an App Key
   (env or Settings). But the coupon-**grant** leg specifically also needs
   `pospal_grant_enabled()` — which additionally requires at least one of
   `pospal_coupon_uid_5`/`pospal_coupon_uid_10` (or the store-2/3 equivalents)
   to be set (`includes/class-doughboss-settings.php:442`). Leaving both coupon
   UID fields blank is a deliberate "grant leg stays dormant" state, not a bug
   — map at least one coupon-rule UID under Settings → POSPal to turn it on.

2. **"I set `DOUGHBOSS_POSPAL_APPKEY` in wp-config.php but the Settings page
   still shows the App Key field as empty / lets me type a new one."**
   That's the write-only `keep_secret()` pattern working as designed — the
   field is deliberately never populated from a stored value (env or DB) so
   the secret can't leak into page HTML. Leaving it blank on save keeps
   whatever is currently in effect; you don't need to (and can't) see the
   env-provided value reflected there. Confirm the env var actually won by
   checking `wp doughboss pospal-test` (`includes/class-doughboss-cli.php`),
   which errors out clearly if POSPal isn't fully configured.

3. **"Activation fails / `dbDelta` errors on a fresh MySQL 8 install."**
   The orders/catering/voucher-redemption tables default several `datetime`
   columns to the sentinel `'0000-00-00 00:00:00'`
   (`includes/class-doughboss-activator.php`). MySQL 8 (and MySQL 5.7+) with
   strict SQL mode including `NO_ZERO_DATE` rejects that literal. Either
   remove `NO_ZERO_DATE`/`STRICT_TRANS_TABLES` from your local `sql_mode` for
   dev, or be aware this is a known fragility rather than something you
   broke.

4. **"I changed a Settings field and now unrelated settings like `app_origin`
   or voucher campaigns look reset."** This exact bug shipped and was fixed in
   v2.12.3 (see `CLAUDE.md`): `sanitize_settings()` used to build the cleaned
   array from scratch instead of merging onto
   `DoughBoss_Settings::all()`, silently deleting any key the form didn't
   explicitly post. If you're on an older checkout (pre-2.12.3) and see this,
   update; if you see it on current code, that's a regression worth reporting
   immediately given how recently it was fixed.

5. **"A brand-new guest's first cart add doesn't seem to save — refreshing
   the cart shows it empty."** Also a shipped-and-fixed v2.12.3 bug: the guest
   cart token generated by `wp_generate_password(32, false, false)` is mixed
   case, but every subsequent cookie read ran it through `sanitize_key()`
   (which lowercases), so the transient key looked up on read never matched
   the one written on the first request. If you're testing against an older
   checkout and see this, update to a build with the case-preserving token
   filter in `includes/class-doughboss-cart.php`'s `get_token()`.

6. **"My REST calls to `doughboss/v1` return 403 `doughboss_bad_nonce` /
   `doughboss_forbidden`."** State-changing routes (`verify_nonce`,
   `includes/class-doughboss-rest-controller.php:801`) require a valid
   `X-WP-Nonce` header using the `wp_rest` action — grab it from
   `DoughBossData.nonce` (localized by `class-doughboss-assets.php`) when
   testing from the browser console, not an arbitrary string. Admin-only
   routes (`verify_admin`, line 814) additionally require
   `manage_doughboss`, `manage_doughboss_kds`, or `manage_options` — a
   plain subscriber account will correctly get 403 here. Read-only public
   routes (`/config`, `/menu`, `/locations`, `GET /order/{number}`) use
   `__return_true` **on purpose** — don't "fix" them by adding a nonce
   requirement; that's a deliberate public-storefront-data design, not an
   oversight (see `CLAUDE.md` gotchas).

7. **"I'm getting 429 `doughboss_rate_limit` while manually re-testing
   checkout/voucher endpoints in a tight loop."** Expected — the rate limiter
   (`rate_limited()`, `includes/class-doughboss-rest-controller.php:889`) caps
   checkout at 8 requests/10 minutes, voucher redeem at 6/hour, voucher claim
   at 8/10 minutes, etc., keyed on `REMOTE_ADDR`. Slow down your manual test
   loop, or note (as the audit doc does) that this keys on raw
   `REMOTE_ADDR` and is proxy-naive — if you're testing through a reverse
   proxy/load balancer that doesn't set `REMOTE_ADDR` per real client, every
   request can appear to share one IP and hit the cap fast.

---

## Related reading
- `CLAUDE.md` (repo root) — architecture map, conventions, current
  state/roadmap. Treat its version numbers as historical/stale per
  `docs/DoughBoss-Codebase-Strengths-Weaknesses-Report.md` §5 — this manual's
  §1 has the corrected current numbers.
- `docs/DoughBoss-Codebase-Strengths-Weaknesses-Report.md` — the fuller audit
  this manual's testing guidance is drawn from (strengths, weaknesses,
  recommended fix order).
- `docs/POSPal-Voucher-Integration-Plan.md` — deeper POSPal/voucher design
  detail if you're working in that area.
- `readme.txt` — the WordPress.org-style plugin readme (shortcodes, changelog);
  keep its `Stable tag` and this manual's `DOUGHBOSS_VERSION` in sync when you
  cut a release, since nothing currently enforces that automatically.
