# WP-11 hand-off: timesheet reconciliation (plugin staff clock vs Square Team)

Status: built and tested; feature `timesheet_recon` ships OFF. The report is evidence only: it never decides
who is right and never computes pay. Nothing here has called Square or touched a live site.

## What exists

| File | Role |
| --- | --- |
| `includes/recon/class-doughboss-growth-recon-admin.php` | Module entry (`DoughBoss_Growth_Recon_Admin`, per WP-01 contract item 1): `init()` (no-op while the flag is off), `schema()`, cron `doughboss_growth_recon_daily`, admin-post handlers, report screen "Timesheet check" under the core `doughboss` menu |
| `includes/recon/class-doughboss-growth-recon-reader.php` | SELECT-only reader of core `doughboss_staff_shifts` / `_breaks` / `_shift_events` through core's accessors and the 03 §2.7 guards; row contract without names, logins or reasons |
| `includes/recon/class-doughboss-growth-recon-square.php` | Read-only Square client: `POST /v2/labor/timecards/search` (window search + open-timecard search, cursor loop) and `POST /v2/team-members/search` (mapping suggestions only); `Square-Version: 2026-09-16` pinned |
| `includes/recon/class-doughboss-growth-recon-matcher.php` | Pure matching engine: full 03 §2.5 catalogue, UTC minutes, Sydney labels, DST-safe helpers |
| `includes/recon/class-doughboss-growth-recon-report.php` | Option `doughboss_growth_recon` (no shipped values), tables `doughboss_growth_recon_run` / `_row` / `_xref`, run orchestration and lock, change detection, CSV |
| `tests/test-recon-{matcher,reader,square,report,admin}.php`, `tests/stubs-recon.php`, `tests/fixtures/recon-cases.json` | 48 hand-worked scenarios plus unit, failure-path and negative-control tests |
| `tests/integration/recon-mysql.php` | WP-CLI MySQL check (same four guards as core `tests/integration/wordpress-mysql.php:16-28`): real-core parity, query-log proof of zero core writes, no stored names, MySQL run lock |

Names used are the frozen ones (00 §4.2) plus ONE addition, see contract change request 3.

## Behaviour that matters

- **Fail closed.** Run statuses: `COMPLETE`, `INCOMPLETE` (rows stored but some item is `UNMAPPED_*`), `NOT_RUN`
  (precondition missing: flag off, companion storage, core storage/version, token, environment, no shop mapping,
  invalid dates, another run in progress), `FAILED` (any Square error incl. 429/5xx/401/transport, truncated or
  non-JSON body, `errors[]`, wrong-filter response, cursor loop, page cap, invalid data either side, DB error,
  thrown error). Only COMPLETE/INCOMPLETE runs are shown as results; NOT_RUN/FAILED keep the previous result and
  leave no rows. A row is `MATCHED` only when both sides are closed, every check is rated and nothing is flagged.
- **No shipped tolerance.** Unset tolerance gives `UNRATED_START/END/BREAK/NET/OPEN_ALERT/LONG_SHIFT/NEAR_MISS`, so
  nothing is ever green until Elie sets every number. Equal to the tolerance passes; one minute more fails.
  Out-of-range input is refused and left unset (never clamped). Accepted ranges only reject nonsense (for example
  tolerances 0-1440); they are not suggestions.
- **Time.** Every comparison is in UTC epoch minutes (plugin seconds floored; Square truncates). Local time is only
  for the workday label and display (Australia/Sydney, shown with AEST/AEDT). Local time comes from
  `DateTimeZone::getOffset()` on a UTC `DateTime` and `getTransitions()`; a converted object's `getTimestamp()` is
  never used (PHP 7.4 collapses the repeated hour that way; reproduced here on 7.4.33: 1775316600 -> 1775320200).
  Local-to-UTC: a time in the spring-forward gap maps to the end of the gap; a time in the autumn fold maps to
  the earlier instant. The daily run is a single event re-armed after each run at the next Sydney `run_time_local`,
  so it keeps wall-clock time across DST (a 02:30 run on the gap day fires at 03:00 AEDT).
- **Matching.** Per mapped employee, a connected group of overlapping (>= 1 minute) plugin shifts and Square
  timecards is one row: 1+1 = `pair`; more on either side = `SPLIT` (union interval, summed breaks and nets).
  Leftover singles within `near_miss_search_minutes` are linked as `NO_OVERLAP_NEAR_MISS`; the rest are
  `MISSING_IN_SQUARE` / `MISSING_IN_PLUGIN`. Rows are reported for business days in range, plus any row with an
  open item whatever its age. Plugin net uses exactly core's `worked_minutes()` formula; Square shows net with all
  breaks (compared) and net with unpaid breaks only (information).
- **Core 2.44.0.** When core >= 2.44.0 is active its verified `doughboss_square_locations` rows are authoritative;
  a shop where core and the companion map disagree, or a Square location claimed by two shops, is
  `UNMAPPED_LOCATION` until resolved; core 2.44.0 without that table fails the run (`core_location_map_missing`).
- **Personal data.** Stored: WordPress user ids, Square team member/location/timecard ids, UTC instants, minutes,
  codes, a content hash, the id of the manager who pressed Run. Not stored anywhere (tables, CSV, logs): names,
  logins, emails, wages, break names, correction reasons, the token. Names are resolved at render time. Email
  matching for mapping suggestions happens in memory on an explicit, nonce-checked request and is never saved or
  shown; a mapping exists only after a manager confirms it.
- **Zero core writes.** The reader issues SELECTs only, with an explicit column list; every write goes to
  `{prefix}doughboss_growth_recon_*`. Proved by the query log in tests and in the MySQL integration file.

## What I ran (PHP 8.3.6 unless stated; all from `/home/user/DOUGHBOSSV2/doughboss-growth`)

| Command | Result |
| --- | --- |
| `php tests/run.php recon` | 871 assertions: 871 passed, 0 failed, 1 skipped (real-core parity needs core source), 0 foreign writes |
| `DBGR_CORE_SRC=/tmp/wp-src/candidate-2.43.2 php tests/run.php recon-reader` | 59 passed, 0 skipped: companion net == REAL core `worked_minutes()` on 80 seeded shifts |
| `DBGR_CORE_SRC=/tmp/wp-src/baseline-2.41.0 php tests/run.php recon-reader` | 59 passed, 0 skipped (same against live-line 2.41.0 source) |
| recon suite on PHP 7.4.33 (WebAssembly build from the installed Playground CLI) | 864 passed, 0 failed, 3 skipped (sub-processes cannot start in WASM; DST and fold tests included) |
| recon suite on PHP 8.2.33 (same WASM tooling) | 864 passed, 0 failed, 3 skipped (same reason) |
| `php -l` on the 5 module files and 7 test/stub/integration files | 12 of 12 clean on 8.3.6 and on 7.4.33 |
| `php scripts/php74-guard.php` | no violations (whole plugin tree) |
| `ACORN_PATH=web/node_modules/acorn node scripts/es5-check.mjs` | passes (WP-11 adds no JavaScript) |
| `php scripts/build-zip.php <scratch>` + `validate-zip.php` + `budgets.php` | zip builds and validates, recon files included, tests excluded, 13% of the 1.0 MB budget |
| `php tests/run.php` (whole suite, shared tree, final run) | 3306 assertions: 3300 passed, 6 failed, 1 skipped, 0 foreign writes; all 6 failures are the WP-01 two-schema assertions in `test-core-lifecycle.php` (see "Not mine") |

Negative controls that ran: a tampered fixture expectation and a missing row are detected; non-integer
tolerances are treated as unset; malformed timecards (16 variants), bad RFC 3339 strings, bad local dates and
times, bad core datetimes and invalid core rows are refused; a wrong break formula is detected by the parity data;
an undeclared Square call fails the test; a forged or foreign nonce and a non-manager are refused on every handler.

## Not run, and why

- `tests/integration/recon-mysql.php`: no MySQL server or disposable WordPress+MySQL site exists here. It lints
  clean and, run outside WordPress, stops before doing anything.
- A browser / Playground check: WP-11's acceptance has none; the screen and handlers are exercised in the shim.
- Any real Square call (sandbox or production): not allowed, and the first real run needs Elie's approval.
- `node --test`, vitest, eslint: WP-11 has no JavaScript and no `web/` file.

## Not mine (full-suite failures seen in the shared tree)

Other packages were being edited while I worked, so whole-suite counts moved. Two kinds of failure are not caused
by WP-11: (a) `tests/test-core-lifecycle.php:30-31,108,119` (WP-01) hard-codes exactly two schemas, so it fails as
soon as any module ships `schema()` (it already failed with WP-05's tables before WP-11's existed: checked in a scratch
copy without the recon files); (b) in-progress WP-05 waitlist failures. Two code comments of mine contained the banned teaser
word as a substring of an ordinary English word; reworded (WP-01's scope test now passes for recon files).

## [CONFIRM] gaps and decisions for Elie (also shown on the report screen)

1. Approval to read Square production staff data (personal data) before the first real run, and who owns the
   Square merchant account (orchestrator task 18). Do not supply the labour token until both are confirmed.
2. Every number: start, finish, break and net tolerances; open-shift alert; longest normal shift; near-miss
   distance; look-back days; daily run time; per-shop business-day cutoff. None is shipped.
3. Which system is authoritative for pay when they disagree (the report never decides).
4. Whether paid breaks exist in the pay policy (`BREAK_PAID_CLASS` is information, not a fault).
5. Forced close: core closes a forgotten shift at the click time (`MANAGER_CLOSED` is Review). A core change to
   take a true finish time would reduce this; separate approval.
6. To verify on the first approved read (03 §2.8, notes smoke test 24): whether `version` > 1 is normal after a plain
   clock-out (if so `SQUARE_EDITED` is noisy information); whether `is_paid` is populated for Dough Boss break types
   (a break without it fails the run); whether Labor/Team API reads need a paid Square Team plan in Australia;
   whether `start` time-range bounds are inclusive (the client accepts both ends).
7. Engineering values I chose (labelled, easy to change): page size 40 (the HTTP wrapper keeps 64 KiB per body;
   Square allows 200), page cap 50, stale-run limit 1,800 s, fetch margin one day plus the near-miss distance,
   manual range at most 31 days, rows kept for the newest 30 finished runs, at most 5,000 shifts per read.
8. With no cutoff set a shift belongs to the calendar date it starts on (display label only; matching is by overlap).

## Contract change requests (nothing edited outside WP-11's files)

1. WP-01 `tests/test-core-lifecycle.php:30-31,108,119`: compare against `count( DoughBoss_Growth::schemas() )` (or use
   `set_registry_override()`), instead of exactly 2.
2. WP-01 `doughboss-growth.php`: raise `DOUGHBOSS_GROWTH_DB_VERSION` at integration so `storage_ready()` turns false
   until the three recon tables exist on an upgraded site (meanwhile the self-heal creates them and the module checks
   its own tables before every run).
3. Architecture 00 §4.2: add admin-post `doughboss_growth_recon_save_params` (manage_doughboss + nonce), which the
   recon screen uses so Elie can set the parameters in wp-admin. If not accepted, remove the handler and the form;
   the parameters would then only be settable with `wp option update doughboss_growth_recon`.
4. Optional, WP-01 `class-doughboss-growth-http.php`: a per-request response cap would let the client use Square's
   200-item pages.
5. File ownership note: besides the three test files named in 05, WP-11 added `tests/test-recon-report.php`,
   `tests/test-recon-admin.php`, `tests/stubs-recon.php` and `tests/fixtures/recon-cases.json` (recon namespace, per
   05 §0 rule 6 and the WP-01 contract item 8). `stubs-recon.php` defines `DoughBoss_Locations` only when no other
   stub has (same `::$rows` shape as WP-03/WP-05), and guarded `get_userdata()`, `get_users()`, `wp_nonce_url()`,
   `sanitize_file_name()`.
