# WP-01 hand-off: companion scaffold, flags, CI, packaging, runtime mount

Status: complete and verified as listed below. Plugin 0.1.0, DB version 1.0.0, eleven feature flags, all off.
Scope decisions applied: no hero flag, no media plugin, no media zip, one code zip (budget 1.0 MB).

## What exists

Production code (all in `doughboss-growth/`): `doughboss-growth.php`, `uninstall.php`, `readme.txt`,
`includes/class-doughboss-growth{,-settings,-activator,-rate-limit,-http,-outbox}.php`,
`admin/class-doughboss-growth-admin.php`, `.gitignore` (ignores `dist/`).
Tooling: `scripts/{build-zip.php,validate-zip.php,budgets.php,php74-guard.php,es5-check.mjs}`.
Tests: `tests/{bootstrap.php,run.php,stubs-core.php,test-core.php,core.test.js}` plus five more WP-01 files named
`tests/test-core-{harness,http,limiter,outbox,lifecycle,scripts}.php` (split from the single `test-core.php` so each stays readable).
Also: `.github/workflows/growth-ci.yml`, `web/scripts/wp-local/{config.sh,start.sh}` (edited) and
`web/scripts/wp-local/growth/wp01-inert.mjs` (named by the WP-01 acceptance text, so written).
Removed: the whole `doughboss-growth-media/` directory and every reference to a hero flag or media pack.

## Contract for later packages (read this before you start)

1. **Module registry** (`DoughBoss_Growth::modules()`). A package just adds files; nothing in WP-01 changes.
   - Entry file and class per module (frozen here): ledger `includes/ledger/class-doughboss-growth-ledger.php` `DoughBoss_Growth_Ledger`;
     consent `.../consent/class-doughboss-growth-consent.php` `DoughBoss_Growth_Consent`; attribution `DoughBoss_Growth_Attribution`;
     waitlist `.../waitlist/class-doughboss-growth-waitlist.php` `DoughBoss_Growth_Waitlist`; coming soon `.../class-doughboss-growth-coming-soon.php`
     `DoughBoss_Growth_Coming_Soon`; landing `DoughBoss_Growth_Landing`; leads `DoughBoss_Growth_Leads`; conversions `DoughBoss_Growth_Conversions`;
     recon: entry is **`includes/recon/class-doughboss-growth-recon-admin.php` / `DoughBoss_Growth_Recon_Admin`** (WP-11 owns no "recon" main file, so the
     admin file is the entry; put cron and admin-post registration in its `init()`).
   - Extra files of the same module (waitlist-rest, waitlist-privacy, landing-seo, landing-schema, party-sizer, ga4, meta, offline-export, tags,
     recon-reader/-square/-matcher/-report) are listed in the registry `files` array and `require_once`d just before the entry file, only if present.
   - The entry class gets `init()` called only when the file exists AND one of its flags is effectively on (or `admin_always` and `is_admin()`),
     and, for `needs_storage` modules, only after the schema is installed. `init()` must be idempotent and must re-check
     `DoughBoss_Growth_Settings::enabled( '<its flag>' )` for the exact feature it serves.
   - Module files must be **side-effect free at include time** (classes only): the activator includes every present entry file to collect
     `schema()` even while its flag is off.
   - Tables: put `public static function schema()` on the **entry class**, returning an array of `CREATE TABLE {$wpdb->prefix}doughboss_growth_...` strings
     in dbDelta format (two spaces after PRIMARY KEY). The names must be in `DoughBoss_Growth_Activator::TABLE_SUFFIXES`; the uninstall plan refuses others.
   - A module that throws in `init()` or `schema()` is contained (the site keeps working, the module is inactive, only the exception class is logged).
   - Ledger (WP-02): registered with `admin_always` and the flags landing_pages, seo_head, coming_soon, waitlist, so its `init()` can hook
     `doughboss_growth_feature_enabled` (return false when the ledger is invalid) before those features are evaluated.
2. **Flags**: `DoughBoss_Growth_Settings::enabled( $feature )` only. It already ANDs the saved flag, the dependency rules, the kill switch and the
   disable-only filter. Settings are sanitised on every read; unknown or corrupt data means off.
3. **Rate limiter**: `DoughBoss_Growth_Rate_Limit::hit( $bucket, $limit, $window )` / `hit_all( array( array( $bucket, $limit, $window ), ... ) )`;
   `hash_ip( DoughBoss_Growth_Rate_Limit::client_ip() )` for IP buckets. Returns `allowed`, `reason` (`ok|limited|storage_error|invalid`), `retry_after`.
   Anything other than `allowed === true` must be refused (503 for `storage_error`). The table is created by WP-01.
4. **Outbox**: `DoughBoss_Growth_Outbox::register_channel( 'ga4', $callable )` from the module's `init()` (only then does the 5-minute cron run);
   `enqueue( $channel, $event_name, $event_id, $subject_type, $subject_id, array $payload )` returns `queued|duplicate|rejected|error`.
   Payloads with raw personal data are refused (`payload_has_pii()`); hashed identifiers pass only with `send_hashed_identifiers` and as 64-char hex.
   The handler receives the row array with the decoded `payload`; return `true`, or a `WP_Error` (error data `terminal => true` parks it at once).
   A row stuck `in_flight` past the lease is quarantined (`failed_terminal`, `ambiguous_in_flight`) and never re-sent.
5. **HTTP**: all outbound calls go through `DoughBoss_Growth_Http::request( $method, $url, array( 'json' => ..., 'headers' => ..., 'timeout' => ... ) )`
   (https, public host, port 443, 10 s ceiling, no redirects, header injection refused, redacted logs). Never call `wp_remote_*` directly (a test fails if you do).
   `DoughBoss_Growth_Http::log()` fires `doughboss_growth_log`.
6. **Admin**: add a tab from a callback on the `doughboss_growth_admin_tabs` action with `DoughBoss_Growth_Admin::add_tab( $slug, $label, $callback )`.
   Use `DoughBoss_Growth_Admin::user_can_manage()` and a nonce on every handler.
7. **Clock**: use `DoughBoss_Growth::now()`; tests move it with `dbgr_test_set_time()` / `dbgr_test_advance()`.
8. **Test harness**: `tests/bootstrap.php` is the shared WordPress stub (read its header). Add stubs of core classes in your own `tests/stubs-<module>.php`
   (auto-loaded after `stubs-core.php`, which defines `DOUGHBOSS_VERSION` 2.43.2 and `DoughBoss_Settings`). Use `db_test()`, `assert_*`,
   `dbgr_test_login()`, `dbgr_test_nonce()`, `dbgr_test_rest_dispatch()` (real filter order incl. `rest_request_before_callbacks` and `rest_post_dispatch`),
   `dbgr_test_http_expect()` (any undeclared outbound call fails the test), `$GLOBALS['wpdb']->use_sqlite()` + `create_table_from_mysql()` (your real DDL runs),
   `fail_on()` / `fail_all()` / `respond()`, and `dbgr_test_subprocess( $code, $prelude, $load_stubs )` for constants. `dbgr_test_skip()` records a visible skip.
   Writing a non-`doughboss_growth_` option, transient, cron hook or table is a recorded foreign write and `run.php` exits non-zero.
9. **Name your test files** `test-<module>.php`; WP-01's are `test-core*.php`. `tests/test-*.php` are loaded in name order, so do not rely on helpers
   defined in another test file; put shared helpers in your `stubs-<module>.php`.
10. **Front-end JavaScript**: ES5, one IIFE starting with `'use strict'`, no `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write`, `eval`, `new Function`,
    no `.mjs`. Check with `ACORN_PATH=<acorn dir> node doughboss-growth/scripts/es5-check.mjs` (default path `public/`; `public/vendor/` is skipped).
11. **PHP**: `php doughboss-growth/scripts/php74-guard.php` must stay clean (match, `?->`, typed properties, `fn`, `str_contains` family, enums, readonly,
    named arguments, promotion, union types, attributes, trailing comma in parameter lists, catch without variable and more are refused; `#[\ReturnTypeWillChange]` is tolerated).
12. **Regexes**: end-anchor with the `D` modifier (`/^...$/D`); plain `$` accepts a trailing newline. WP-01 fixed this in its own validators.

## What was run (evidence)

| Check | Result |
| --- | --- |
| `php doughboss-growth/tests/run.php`, PHP 8.3.6 | 1238 assertions, 1238 passed, 0 failed, 0 skipped, 0 foreign writes |
| same suite, PHP 7.4.33 (real 7.4 interpreter, WebAssembly build from the installed Playground CLI) | 1076 passed, 0 failed, 16 skipped (sub-process tests cannot spawn a process there; listed by run.php) |
| `php -l` on all 23 PHP files | clean on 8.3 and on 7.4.33 |
| `php scripts/php74-guard.php` | no violations in 23 files, on 8.3 and on 7.4.33; negative controls prove each rule fires, identically on both |
| `node --test tests/core.test.js` (Node 22.22) | 24 tests, 24 passed |
| `node scripts/es5-check.mjs` | passes (no hand-written JS exists yet); negative controls: arrow, let, const, template literal, class, destructuring, default parameter, spread, import, async, `**`, `?.`, missing 'use strict', code outside the IIFE, innerHTML, insertAdjacentHTML, document.write, eval, new Function, `.mjs` all fail; `public/vendor/` skipped; exit 2 when acorn is missing |
| `build-zip.php` / `validate-zip.php` / `budgets.php` | zip 33,617 bytes (3% of 1,000,000); two builds byte-identical (8.x); a build made on PHP 7.4.33 validates on 8.3; refuses overwrite, each of the four version places, over-budget, hidden/secret files, symlinks, stale tree, extra/missing entries, traversal, missing ABSPATH guard |
| `bash -n` on `config.sh`, `start.sh`; `eslint` on `wp01-inert.mjs` | clean |
| Runtime (Playground, PHP 8.2, WP 7.1, SQLite), candidate 2.43.2 | `start.sh` unchanged without `WPL_EXTRA_PLUGIN_SRC`; with it the plugin mounts and activates (28 `doughboss_*` tables = 26 core + 2 companion); `verify.sh` ALL CHECKS PASSED |
| Inert test `wp01-inert.mjs` on C and on B (2.41.0) | `/`, `/order/`, `/catering/`, `/locations/` identical with and without the companion after masking nonces (C: sha256 f4ed43a4bef4, 2fae6c175b8c, d63024e582a3, 39965d51d757; B: b02874815006, 447ae266a155, 7c65892cb50e, a7e78b7e3a02); two captures without the companion are identical (determinism); the comparator detects a planted script tag and a one-byte change |
| Admin checks on C and on B | 19/19 each: `/health` 401 anonymous, 200 for the admin, 11 flags all false, no module active, `no-store`; settings page renders with 11 unticked boxes and the [CONFIRM] list |
| Save round trip on C | 12/12: forged nonce 403 and nothing saved; valid save redirects; `gtm` forced off without the banner; hero flag rejected; waitlist refused without a privacy URL; with `coming_soon` on and no module file present the public pages are still identical |
| Core isolation on B (clean boots, no admin session) | the only differences with the companion are `wp_doughboss_growth_outbox`, `wp_doughboss_growth_rate`, option `doughboss_growth_db_version`, `active_plugins`, plus per-install random values (salts, nonce keys, theme mod timestamp); no core table or core option changed |

Runtime instance used port 9401 with state `/tmp/wpl-WP-01`; it was stopped (`stop.sh`) and nothing is left running.

## Not run, and why

- The GitHub Actions workflow itself (no runner here). YAML parses; the same commands were run locally (PHP 8.3 and 7.4.33 WASM, Node 22 not 20, acorn from `web/node_modules` not an `npm install` scratch).
- PHP 8.2 natively (8.3 used).
- MySQL. The limiter, outbox claim, UNIQUE keys and conditional UPDATEs were exercised on SQLite with the real DDL, and the scripted-database cases pin the SQL
  shape (conditional `WHERE status = 'pending' AND next_attempt_at <= ...`). MySQL affected-row semantics and real dbDelta are untested; the disposable-MySQL
  harness belongs to WP-16 / the integration suite. Every UPDATE changes a value, so affected-rows counting is sound on MySQL.
- A rendered browser check (Playwright): WP-01 adds no front-end output; the inert proof is the byte comparison of the HTML.
- `web` vitest: no web test was added or changed by WP-01.

## [CONFIRM] gaps and decisions needed

- Owner inputs, shown in the Growth settings page and in `/health` `confirm_gaps`: sender legal name (and ABN if shown), privacy-policy URL,
  confirmed-waitlist retention months (unset means never deleted automatically), GTM container id, GA4 measurement id, Meta pixel id.
- Consent default: `deny` shipped; `opt_out` is selectable but its meaning belongs to WP-03 (unknown value means deny).
- "1.0 MB" is implemented as 1,000,000 bytes (stricter than 1 MiB). Say if you meant 1,048,576.
- Engineering parameters I chose, all labelled as proposals and easy to change: outbox lease 600 s, batch 25, payload cap 60,000 bytes, response cap 64 KiB;
  settings caps (pending retention at most 365 days, confirmed at most 120 months); schema self-check at most hourly. None is a customer-facing number.

## Contract change requests (nothing edited outside WP-01's files)

1. `docs/wp/00` and `05` still describe 12 flags, the media plugin and WP-09/WP-10 in places; the banner at the top supersedes them, but section 3.9, 4.1 and the WP-01 text should be corrected by the owner of those documents.
2. Recon entry class is `DoughBoss_Growth_Recon_Admin` in `class-doughboss-growth-recon-admin.php` (see contract item 1). WP-11 must put `init()` and `schema()` there.
3. When any package adds a table, raise `DOUGHBOSS_GROWTH_DB_VERSION` in `doughboss-growth.php` (WP-01 file, request it at WP-16 integration). Without a bump a missing table is still repaired by the hourly manager-only self-check or immediately when the Growth page is opened, but `storage_ready()` would not turn false in between.
4. After core PR #68 merges, core's own CI lints every PHP file and runs `node --check` / `node --test` on every `*.js` in the repository: `tests/core.test.js` will run there without acorn, so its acorn-dependent tests will SKIP visibly (they pass in `growth-ci.yml`, which installs acorn).
