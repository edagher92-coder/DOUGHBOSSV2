# 04 Local WordPress runtime: the real DoughBoss plugin and theme, in a browser, in the sandbox

Slice owner: local WordPress runtime feasibility spike. Verified **2026-10-02**.

**Result: it works, first try, with option (a), WordPress Playground CLI (PHP compiled to WASM, SQLite database).** Options (b) and (c) were not needed; the host checks I ran for (b) are in section 8.

- **B** = `/tmp/wp-src/baseline-2.41.0`, **C** = `/tmp/wp-src/candidate-2.43.2`. Paths are relative to the worktree unless they start with `web/`. **OBSERVED** = I ran it or read it. **INFERRED** = my reasoning.
- Nothing here touched B or C (both read-only). The plugin and theme are **copied** into `/tmp/wp-local/src` and the copies are mounted. No git operations. No secrets, customer data or provider APIs were involved. The network was used only for package/WordPress downloads (section 8).

## 1. TL;DR for another agent

```bash
cd /home/user/DOUGHBOSSV2/web
scripts/wp-local/start.sh          # ~30 s warm, ~40 s fully cold; no-op (1 s) if already up
scripts/wp-local/verify.sh         # PASS/FAIL checks: plugin+theme active, tables, menu, payments off
SHOTS_DIR=/tmp/wp-local/shots node scripts/wp-local/shots.mjs   # Playwright smoke + screenshots
scripts/wp-local/stop.sh           # idempotent; also sweeps orphaned ~190 MB scratch dirs
```

- Site: `http://127.0.0.1:9410` (override with `WPL_PORT`). Home `/`, ordering page `/order/`, REST `/?rest_route=/doughboss/v1/menu` (pretty `/wp-json/` also works).
- Candidate 2.43.2 plugin + theme `doughboss-final`, **PHP 8.2.33** (same as live), **WordPress 7.1.2** (live is 7.1, `docs/REVIEW-20260908.md:29` in C).
- The database is **recreated on every start** (SQLite, in a scratch dir). Anything you create (orders, settings) is gone on restart. This is deliberate: every start is the same known state.
- Never leave it running when you finish a slice if another agent might need the port; `stop.sh` is safe to call.

## 2. What was tried

| Option | Outcome | Evidence |
| --- | --- | --- |
| (a) `@wp-playground/cli` 3.1.56, PHP-WASM + SQLite, plugin and theme mounted | **Success.** Boots, activator runs, theme active, seed runs, Chromium renders it. | sections 3-6 |
| (b) wordpress.org core + `php -S` + sqlite-database-integration | Not attempted (a worked). Host reachability checked: section 8. | section 8 |
| (c) closest alternative | Not needed. | n/a |

Playground downloads WordPress and the SQLite integration plugin itself (it logged `Resolved WordPress release URL: https://downloads.w.org/release/wordpress-7.1.2.zip`, OBSERVED in the debug log) and caches the zip in `~/.wordpress-playground/`.

## 3. How it is put together (`web/scripts/wp-local/`)

| File | Role |
| --- | --- |
| `config.sh` | All settings, each overridable by env: `WPL_PORT` (9410), `WPL_PHP` (8.2), `WPL_WP` (7.1), `WPL_PLAYGROUND_VERSION` (3.1.56), `WPL_PLUGIN_SRC` (`/tmp/wp-src/candidate-2.43.2`), `WPL_THEME_SRC`, `WPL_STATE` (`/tmp/wp-local`), `WPL_ORDERING_OPEN` (0), `WPL_BOOT_TIMEOUT` (300 s). |
| `start.sh` | Idempotent start. Installs the pinned CLI into `/tmp/wp-local/pg` (never into `web/node_modules`), copies plugin and theme into `/tmp/wp-local/src`, writes a Blueprint, launches detached, waits for `Ready!` and a healthy `/doughboss/v1/config`, warms the home page. `--restart` = stop then start. |
| `stop.sh` | Idempotent stop (SIGTERM, then SIGKILL after 10 s), removes the scratch VFS dir and any orphans whose pid is dead. |
| `verify.sh` | Exits non-zero unless all checks pass (section 4). Reads the SQLite file read-only. |
| `php/seed.php` | Runs inside WordPress as the last Blueprint step. Idempotent. Section 5. |
| `shots.mjs` | Playwright smoke test and screenshots (section 6). Resolves `@playwright/test` from `web/node_modules`. |

Blueprint steps (generated into `/tmp/wp-local/run/blueprint.json`): `setSiteOptions` (`blogname`), `activateTheme` (`doughboss-final`), `activatePlugin` (`doughboss/doughboss.php`), `runPHP` (loads `wp-load.php`, then `php/seed.php`). `preferredVersions` in the Blueprint is what pins PHP and WordPress: **the Blueprint overrides the `--php` flag** (OBSERVED: with `--php 8.2` and no `preferredVersions`, the seed step reported `PHP 8.5.10`; with `preferredVersions` it reports `8.2.33`).

Mounts: plugin copy to `/wordpress/wp-content/plugins/doughboss`, theme copy to `/wordpress/wp-content/themes/doughboss-final`, `scripts/wp-local/php` to `/internal/wpl` (seed code), `/tmp/wp-local/run/out` to `/internal/wpl-out` (seed writes `seed-report.json` there). Defines: `WP_DEBUG` true, `WP_DEBUG_DISPLAY` false, `WP_DEBUG_LOG` true (log lands in the scratch VFS at `wordpress/wp-content/debug.log`), `DISABLE_WP_CRON` true.

The plugin copy excludes `.git`, `.github`, `.claude`, `themes`, `tests`, `docs`, `ops` (5.9 MB; not loaded at runtime). It keeps `scripts/`, so `scripts/seed-menu.php` is present in the copy.

## 4. Verification results (OBSERVED, final run)

`verify.sh` output, 2026-10-02:

```
INFO  PHP/8.2.33
PASS  REST /doughboss/v1/config reports payments_enabled=false
PASS  REST /doughboss/v1/menu returns 34 items
PASS  home page loads theme doughboss-final style.css
PASS  home page loads plugin doughboss.css
PASS  /order/ -> 200
PASS  SQLite: 26 doughboss_* tables exist (activator ran), doughboss_db_version=1.23.0
PASS  SQLite: 34 published doughboss_item posts
PASS  SQLite: active theme = doughboss-final
PASS  SQLite: plugin doughboss/doughboss.php active
ALL CHECKS PASSED
```

- **The activator ran.** `activatePlugin` fires `register_activation_hook` (`doughboss.php:55`), which calls `DoughBoss_Activator::activate()` (`includes/class-doughboss-activator.php:22-47`). Evidence: 26 `doughboss_*` tables (orders, order_items, order_events, locations, location_hours, catering_enquiries, vouchers, payment_attempts, checkout_snapshots, pospal_outbox, staff_shifts, staff_shift_events, staff_badges, staff_breaks, capacity_*, loyalty_*, table_*, voucher_*, dining_tables, schedule_exceptions, payment_events), `doughboss_db_version` = `1.23.0` (equals `DOUGHBOSS_DB_VERSION`, `doughboss.php:36`), and option `doughboss_migration_error` is **false**. That error is set when the activator's InnoDB/column checks fail (`class-doughboss-activator.php:41-47`), so those checks pass on the SQLite layer.
- **Payments are off.** `payments_enabled` = false and `payment_gateway` = `stripe` (the plugin default, `includes/class-doughboss-settings.php:193-194`). `seed.php` never writes any payment option and `verify.sh` fails if `payments_enabled` is not `false`.
- **Fidelity to live:** the runtime reproduces the double-escaped `&amp;` that doc 01 recorded on live (`docs/wp/01-storefront-map.md:157,203`): descriptions such as "mushroom, olives &amp; cheese" display the literal `&amp;` on `/order/` (seen in the screenshots; REST returns `&amp;` in `description` and in names like `Cheese, Tomato &amp; Olives`). INFERRED cause: the seeder inserts `&` through `wp_insert_post`, whose kses filter stores `&amp;`, then the JS inserts it with `textContent`. So this is a plugin behaviour, not a runtime artefact, and a good regression check.

## 5. Menu seeding

- **Seeder used:** the plugin's own `DoughBoss_CLI::seed_menu()` (`includes/class-doughboss-cli.php:262`), the code behind `wp doughboss seed-menu` (registered at `:561`) and `scripts/seed-menu.php` (a thin wrapper that calls it). Data comes from `DoughBoss_Menu_Seeder::menu_data()` (`includes/class-doughboss-menu-seeder.php`). It needs **no input**, so no synthetic data was created. It is the in-store board data (6 categories, 34 items), **not live prices**: doc 01 section 3 records that live has 43 items and six different prices. Use the local menu for layout and behaviour, never as a price reference.
- **Why a shim:** the CLI class only loads when `WP_CLI` is defined (`class-doughboss-cli.php:24-26` returns early otherwise) and it logs through `WP_CLI::log/success/warning/error`. `seed.php` defines a minimal in-process `WP_CLI` stand-in (output captured, `error()` throws) and the `WP_CLI` constant **for that one script only**, includes the file, and calls `seed_menu( array(), array() )`. Real WP-CLI is not used. Seed result (OBSERVED): `SUCCESS: Done. Categories created: 6; items created: 34; updated: 0.`
- The seeder is idempotent (items matched by exact title and updated). Since the database is recreated each start, it always creates.
- `seed.php` also sets local-only options (`blogname`, `blogdescription`, `timezone_string` = `Australia/Sydney`, `blog_public` = 0, `permalink_structure` = `/%postname%/`) and creates published pages by slug (`order`, `menu`, `locations`, `track-order`, `catering`, `about-us`, `vouchers`) because the theme keys templates and enqueues off those slugs (`themes/doughboss-final/functions.php:143,152,157,162`; `page-order.php` is the template for slug `order`). Pages are empty: the theme templates render the shortcodes themselves (`page-order.php`).
- **Ordering open or paused:** the default is the plugin default, `ordering_open` = 0 ("Browse the menu. Ordering paused", cart and builder not rendered; `page-order.php`). `WPL_ORDERING_OPEN=1 scripts/wp-local/start.sh --restart` sets `ordering_open` = 1 in `doughboss_settings` so the cart and builder render. Payments stay off in both modes. I ran the Playwright smoke in the open mode as well: 34 cards, no first-party errors (the open-mode screenshots were not inspected visually).

## 6. Browser test and what the screenshots show

Run: `cd web && SHOTS_DIR=<dir> node scripts/wp-local/shots.mjs` (default dir `/tmp/wp-local/shots`). Playwright 1.56.1, Chromium from `/opt/pw-browsers` (`PLAYWRIGHT_BROWSERS_PATH`), no install needed. For `/` and `/order/` at desktop (1360x860) and mobile (Pixel 7) it:

1. fails on any **first-party** console error, uncaught page error, failed request or HTTP >= 400;
2. on `/order/` waits for the client-rendered menu (browser, then `GET /doughboss/v1/menu`, then JS) and requires at least one `.db-card`;
3. scrolls the page (to trigger the reveal animations and lazy images), then saves `wp-<page>-<viewport>.png` (full page) and `wp-<page>-<viewport>-top.png` (first viewport; the Pixel 7 full-page `/order/` capture is 1082x55650 px, unreadable when scaled);
4. records third-party failures separately (they would be sandbox network-policy noise) without failing.

Final run (OBSERVED): `RESULT: PASS`. All four page/viewport combinations: 0 first-party errors, 0 third-party noise, 0 px horizontal overflow, `/order/` = 34 menu cards. Page loads 10-13 s each, and about 30 s for the mobile `/order/` (21,267 CSS px tall, 34 stacked image cards). **Negative control:** with `WPL_NEGATIVE_CONTROL=1` (injects a script tag for a missing first-party file) the run reported first-party errors and `RESULT: FAIL`, so the detector is not vacuous.

Screenshots (final run, in `/tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/shots/`): `wp-home-desktop.png`, `wp-home-mobile.png`, `wp-order-desktop.png`, `wp-order-mobile.png`, plus the `-top` variants. I looked at all four main images (the earlier run for the full pages, the final run for the `-top` ones). Honest description:

- **Home, desktop:** a red banner "Online ordering is paused", a dark header with the Dough Boss logo and nav, a "Pickup shop: Revesby" strip, a hero with real photography of manoush and pizza ("Fresh from the oven."), then the heritage, "three ways we feed you", food-card, catering, three-shop (Revesby, Bankstown, Roselands Centro) and Google-review sections and a dark footer. Looks like a complete, styled site, not a broken or unstyled render.
- **Home, mobile (top):** the banner, header with a menu icon, the hero photograph and headline, "Browse the menu" and "Plan catering" buttons. Fine at that width.
- **Order, desktop:** hero "Browse the menu.", a shop card (Revesby, 12/25 Selems Parade, phone) beside "Online ordering is paused", a search box, category chips (Manoush, Pizza, Pies, Wraps, Desserts, Drinks), then a grid of 34 cards with photos, prices ($4.50 to $15.00) and disabled "Ordering paused" buttons. Visible defect: literal "&amp;" in names and descriptions. Every card shows a photo (INFERRED: the plugin maps images by item; I did not trace how); the three drinks show generic bottle/can images.
- **Order, mobile:** single column of large image cards; the page is about 21,000 CSS px tall. The first viewport shows the banner, header, "Browse the menu." hero, a shop card and the paused notice. No horizontal overflow. (An earlier capture caught the sticky filter bar overlapping a card because the scroll reset was smooth-scrolled; fixed by using an instant scroll. I did not investigate whether that overlap is a real sticky-bar defect.)

## 7. Cold start time and resource use (OBSERVED, 4 CPU / 16 GB sandbox)

| Scenario | `start.sh` wall time |
| --- | --- |
| Already running | 1 s (no-op) |
| Warm (CLI installed, WordPress zip cached) | 29-34 s across five runs |
| Fully cold (CLI not installed, `~/.wordpress-playground` zip cache removed) | 39 s |
| Stop | a few seconds (not timed) |

Memory/disk: about 188 MB of scratch per running site (`/tmp/node-playground-cli-site-<pid>--<pid>-<random>`, holds WordPress core and the SQLite file) plus 5.9 MB plugin copy. Playground does **not** delete that directory on SIGTERM; `stop.sh` does, and sweeps orphans.

Per-page cost is high: **PHP runs as WebAssembly**, so a front-end request is 1-10 s and the full `shots.mjs` takes about 70 s. It runs 3 workers on 4 CPUs (the CLI warns that fewer than 6 workers risks deadlock on file locks; I saw none, but avoid hammering it with parallel browsers).

## 8. Network policy findings

`mcp__claude-code-remote__read_documentation` topic `environment.network` returns only generic advice (ask the person to widen the environment's network access or allow-list the host); **it lists no hosts**. Hosts I probed with `curl` (HEAD/GET, public, no auth):

| Host / URL | Result | Needed for |
| --- | --- | --- |
| `registry.npmjs.org` (npm install of the CLI) | allowed | option (a) |
| `downloads.w.org`, `wordpress.org` (`/latest.zip`, `/wordpress-6.8.zip`), `downloads.wordpress.org` plugin zip, `api.wordpress.org` | allowed (200/302) | option (a), (b) |
| `playground.wordpress.net` | allowed (200) | not required |
| `raw.githubusercontent.com` (wp-cli phar) | allowed (200) | optional real WP-CLI |
| `packagist.org`, `repo.packagist.org` | allowed (200) | composer |
| `github.com/WordPress/sqlite-database-integration/archive/refs/heads/main.zip` (codeload) | **blocked, HTTP 403** | (b) must use the wordpress.org plugin zip instead |
| `github.com/wp-cli/builds/raw/gh-pages/phar/wp-cli.phar` | **blocked, HTTP 403** (use `raw.githubusercontent.com`) | optional |
| `api.github.com` (unauthenticated) | HTTP 400 | not needed |

The proxy status page also lists failed CONNECTs to `doughboss.com.au:443` and several other `*.com.au` hosts. I made **no** request to `doughboss.com.au` or any provider (Square, Stripe, POSPal, Tyro). Those failures are from other agents and are not evidence about this runtime.

## 9. Limits: what does not work or differs from live

Parity first (OBSERVED unless marked): PHP **8.2.33** equals live; WordPress 7.1.2 vs live 7.1 (minor may differ); GD with WebP and AVIF present. Extensions present in the PHP-WASM probe: curl, openssl, sqlite3, pdo_sqlite, mbstring, intl, gd, zip, ctype. `sodium` was NOT in the probe's list; the live extension set was not compared.

| Limit | Detail |
| --- | --- |
| **Database is SQLite, not MySQL/MariaDB** | Live is MySQL-family (INFERRED from the plugin's InnoDB checks; host DB version not found). The SQLite integration translates queries; it is not identical. The plugin uses MySQL-specific constructs that I did **not** exercise: `SELECT GET_LOCK()` (`includes/class-doughboss-catering.php:408`, `class-doughboss-payment-attempts.php:210`, `class-doughboss-rest-controller.php:1489`), `SELECT ... FOR UPDATE` (`class-doughboss-capacity.php:234,250,341`), `ON DUPLICATE KEY` / `INSERT IGNORE` (counts per file: voucher 10, activator 7, capacity 5, order 4, timeclock 3, others 1-2; `grep` over `includes/*.php`). INFERRED risk: **order placement, capacity holds, voucher redemption and timeclock concurrency were not tested here and may behave differently or fail on SQLite.** Verify those against a real MySQL before trusting them. The activator's table and InnoDB contract checks pass (section 4). |
| **No outbound HTTPS from PHP** | PHP-WASM `curl` fails with errno 77 "error setting certificate verify locations: CAfile /etc/ssl/certs/ca-certificates.crt" and `file_get_contents` returns false (probe with `wp-playground-cli php`). So `wp_remote_*` calls (Square, Stripe, POSPal, Tyro, update checks, Gravatar) fail. Good for "payments off" safety; bad for testing any provider integration. Use mocks or the plugin's own PHP tests (`tests/`) for those. |
| **No cron** | `DISABLE_WP_CRON` is true, there is no system cron, and the process is not persistent. `DoughBoss_POSPal_Outbox` schedules `wp_schedule_single_event` and an hourly reconcile (`includes/class-doughboss-pospal-outbox.php:104,219,226`); those never fire. Trigger a hook by hand with `do_action()` from a test script if needed. |
| **No real email** | Outgoing mail (`wp_mail`, used by `includes/class-doughboss-emails.php`) has no SMTP. Not exercised. |
| **State is ephemeral** | New database every start. The WordPress admin is **not** logged in; I set no admin password and did not use `--login`. To work in wp-admin add `--login` to the `start.sh` launch line (local-only, throwaway site). |
| **Static assets served by Node** | Playground serves files from the mounted copy; no Apache, no `.htaccess`, no security headers. Live's WPCode snippets (security headers #533, `[hidden]` CSS #535, mobile nav #537, `ops/README.md:12-35`) are not present. Live's cache/CDN/Crazy Domains behaviour is not modelled. |
| **Build is the candidate, not live** | The plugin is C (2.43.2) and the theme is C (1.6.2). Live is an older build with different bytes (doc 01 section 1). Compare against live for anything behaviour-critical. To run B instead: `WPL_PLUGIN_SRC=/tmp/wp-src/baseline-2.41.0 scripts/wp-local/start.sh --restart` (untested). |
| **Third-party assets** | Fonts and the Google reviews link are not fetched; the smoke run saw no failing third-party request on `/` or `/order/` (fonts are self-hosted, `public/fonts/`). Pages that embed maps or other external content would show gaps. |
| **Node engine warning** | The CLI declares `node >= 24.18` and `npm >= 11.16`; the sandbox has Node 22.22 / npm 10.9. `npm install` prints `EBADENGINE` warnings (now logged to `/tmp/wp-local/run/npm-install.log`) but everything ran correctly. |
| **First request** | The first request after boot can answer 302 and is slow; `start.sh` warms it. |
| **ES5 / PHP 7.4** | This runtime runs PHP 8.2 only; it does not prove PHP 7.4 compatibility. Playground can run 7.4 (`WPL_PHP=7.4`, listed in its `--php` choices) but I did not test it with this plugin. For ES5 use the existing lint/`node --check` flow; the browser here is modern Chromium and will not flag ES6 syntax. |

## 10. Using it for extension work

- Mount your own plugin next to DoughBoss: add `--mount <host dir>:/wordpress/wp-content/plugins/<slug>` to the launch line in `start.sh` and an `activatePlugin` step to the Blueprint (do this by editing a copy of the script or add a `WPL_EXTRA_*` hook; I did not add one to avoid speculative surface).
- After editing source in `WPL_PLUGIN_SRC`, run `start.sh --restart` (the copy is refreshed on every start). You can also edit files in `/tmp/wp-local/src/plugins/doughboss` while it runs; PHP-WASM re-reads them per request (INFERRED, not tested).
- Read the DB: `php -r '$d=new SQLite3("<vfs>/wordpress/wp-content/database/.ht.sqlite",SQLITE3_OPEN_READONLY); ...'` where `<vfs>` is the path in `/tmp/wp-local/run/vfs-dir`. WordPress debug log: `<vfs>/wordpress/wp-content/debug.log`. Server log: `/tmp/wp-local/run/playground.log`.
- Run Playwright tests of your own against `E2E_BASE_URL`-style `WPL_URL`; reuse `shots.mjs` as a template (note the repo's `playwright.config.ts` targets the Next.js preview on port 3100, not this site).
- The repo rule "no two competing writers of menu or order data" still applies: this runtime is a **test bed with throwaway data**; it never writes to live and has no credentials for it.
