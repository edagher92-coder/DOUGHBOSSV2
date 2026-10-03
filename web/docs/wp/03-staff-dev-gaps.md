# 03 Staff and timeclock, reconciliation design, development conventions, handoff docs, risk register, PR graph and gap analysis

Slice owner: staff and timeclock, development and release conventions, handoff documents, risk register, PR graph, gap analysis against the original brief.
Read-only audit of two git worktrees. No live site, Square, Stripe, POSPal or other provider was contacted by this slice. Retrieval/analysis date: 2026-10-02.

## 0. How to read this document

- **B** = `/tmp/wp-src/baseline-2.41.0` (plugin 2.41.0, theme 1.4.0). **C** = `/tmp/wp-src/candidate-2.43.2` (plugin 2.43.2, theme 1.6.2, reviewed, not installed). A path with no prefix is relative to the worktree. Where a file is byte-identical in B and C I cite C's line numbers and say "same in B and C" (checked with `cmp`). Paths starting `web/` or `docs/wp/` are in `/home/user/DOUGHBOSSV2/`.
- **OBSERVED** = read in code or in a repo document. **INFERRED** = my reasoning from observed facts. "Not found" says where I looked.
- Severity scale used in the risk register: **Critical** = can destroy live data or cause money/legal exposure; **High** = likely to block release, corrupt records or produce a wrong money/pay outcome; **Medium** = degrades correctness, operations or auditability; **Low** = hygiene.
- No secret values, tokens or customer data appear here. Option names only.
- Sibling documents already exist and are not repeated: `docs/wp/01-storefront-map.md` (storefront, SEO, menu, locations, imagery, ES5 audit) and `docs/wp/02-orders-square-kitchen-map.md` (orders, payments, Square gap, kitchen, hooks). I cite them as `01 §n` and `02 §n`. `docs/square/api-integration-notes.md` has arrived and is cited as `SQ:line`.

## Summary: the findings that most change what we build

1. **The timeclock is complete for attendance evidence but has no machine interface.** It is four InnoDB tables (`doughboss_staff_shifts`, `_breaks`, `_badges`, `_shift_events`), eight `admin-post.php` actions and three screens (portal, manager timesheet, badge admin). There are **no REST routes** for it (`grep` of `includes/class-doughboss-rest-controller.php` for clock/staff/badge/shift: no matches), no hook fired on clock in/out, no WP-CLI command, and the CSV export carries no user id, shift id or timezone-safe instant (`includes/class-doughboss-timeclock.php:442-449`). Reconciliation therefore needs a small companion-side read module, not a core rewrite (section 2.7).
2. **A manager "correction" closes a forgotten shift at the moment the manager clicks, not at the real finish time.** `handle_correction()` sets `clock_out_utc = current_time()` (`timeclock.php:386,394`) and the audit event stores before/after JSON but no true-end input. Next-morning corrections therefore inflate hours by up to a night. This is the single largest correctness hazard for any pay-adjacent use and the first thing reconciliation must flag (`MANAGER_CLOSED`, section 2.5).
3. **Breaks have no paid/unpaid flag.** Worked minutes subtract every recorded break (`timeclock.php:531-543`; `staff-badge.php:354-370`), while a Square timecard break carries `is_paid` (`SQ:386`). Comparing the two honestly needs gross and net figures on both sides and a `BREAK_PAID_CLASS` flag.
4. **There is no automated test for any timeclock, badge or roster code.** `grep -rli "timeclock|staff_shift|staff_badge|clock_doughboss"` over `tests/` in B and C returns nothing. The shim suite (`php tests/run.php`) and the WordPress/MySQL integration file have zero attendance assertions. The reconciliation reader must ship with its own fixtures and a parity test against `DoughBoss_Timeclock::worked_minutes()`.
5. **No identity link exists between WordPress staff and Square team members, or between plugin shops and Square locations.** Plugin identity is `user_id`; the locations table has `tyro_location_id` and `pospal_store_index` but no Square column (`includes/class-doughboss-activator.php:182-210`). Mapping tables must be owned by the companion (never by editing core tables).
6. **Dev conventions are strong on packaging and money-path safety, weak on JS syntax and CI coverage.** ES5 is a convention only: CI runs `node --check`, which accepts ES2015+ (`.github/workflows/plugin-ci.yml:40-48`). The baseline branch has no CI at all (`diff -rq`: `.github` only in C). The WordPress/MySQL suite and the two-process Square race run only from PowerShell, never in hosted CI (`scripts/test-wordpress-integration.ps1`; `plugin-ci.yml`).
7. **Versioning is machine-enforced in a surprising place.** `scripts/build-zip.php:38-60` and `scripts/validate-zip.php:19-44` require four values to match: the plugin header `Version:`, `DOUGHBOSS_VERSION`, readme `Stable tag:` and the first `= x.y.z =` heading directly after `== Changelog ==` and one blank line. A new runtime directory not named in `$directories` (`build-zip.php:88`) is silently omitted from the zip.
8. **Two delivery constraints bound any new plugin.** The host caps browser uploads at about 2 MB and the canonical plugin zip is about 4.09 MB at 2.43.2 (`docs/VISUAL-PREVIEW-20260908.md:239`, table at `:233-237`); `DISALLOW_FILE_EDIT` is set on live (`:136`). A small separate companion zip fits under the cap; the 2.43.x plugin zip does not. Whether the Upload Plugin route is open at all is untested (`DISALLOW_FILE_MODS` is not evidenced either way).
9. **The ops directory documents one catastrophic trap and one silent-data-loss trap.** Deleting the stale `doughboss-1` plugin through wp-admin runs an uninstaller that drops live tables (`ops/README.md:117-131`), and the live repricing scripts wiped dietary flags and descriptions (`01` Summary 3). The real plugin's own `uninstall.php` also drops all 26 plugin tables including the four attendance tables (`uninstall.php:20-51`), so "uninstall" is never a rollback.
10. **PR graph: #68 is a superset of #66 and #67 by ancestry, and all three share base `claude/live-2.41.0-baseline`.** `docs/REVIEW-20260908.md:234` says the #66/#67 commits "are ancestors of the local repair" that became #68's head. The local `.git` metadata confirms the worktrees are `origin/claude/live-2.41.0-baseline` (76deb569) and `origin/codex/release-readiness-20260908` (1600a399). The checked-out branch for this work, `ccr-ba2d93d9-lbw916`, descends from `claude/awesome-johnson-bkjh83` (240d426c), whose plugin is **2.0.0**.
11. **Safest base for new additive work:** branch from `claude/live-2.41.0-baseline` (76deb569), add only new files under a new top-level directory, target that same base, and give the branch its own workflow file name. Do not base on #68 until the 2.43.x candidate is installed; do not base on `awesome-johnson` (2.0.0). Detail in section 6.
12. **Original-brief coverage is thin on the creative asks and partial on growth.** No exploding hero, Minis teaser, party-pack sizer, VIP waitlist, dietary filter, corporate catering page or click-id capture exists in B or C. Partial: dietary badges (C), shop status (C), catering enquiry and quote workflow (B and C), consent-gated Meta/TikTok bridge (B and C). Section 7.

---

## 1. Timeclock and staff (OBSERVED; identical in B and C)

Files compared with `cmp`: `class-doughboss-timeclock.php`, `-staff-scope.php`, `-staff-badge.php`, `-staff-experience.php`, `-portals.php`, `-table-qr.php` are byte-identical in B and C. `includes/class-doughboss-activator.php` and `class-doughboss-migrations.php` also do not appear in the `diff -rq` output. So everything below is true for production-line 2.41.0 source and for 2.43.2. (INFERRED caveat from `01 §1`: live bytes can differ from B; staff code was not fetched from live.)

### 1.1 Surfaces, history and scope

| Surface | Where | Capability / auth | Notes |
| --- | --- | --- | --- |
| `/staff-clock/` portal | rewrite rule `includes/class-doughboss-portals.php:45`; `render_staff_clock()` `:118-140`; headers `:415-421` | Public landing for badge scans; WordPress login fallback needs `clock_doughboss_staff` (`timeclock.php:17`) | `noindex,nofollow,noarchive`, `X-Frame-Options: DENY`, no-cache. Route version `ROUTE_VERSION '5'` (`portals.php:17`). |
| Manager timesheet | `add_submenu_page('doughboss', ..., 'manage_doughboss', 'doughboss-timeclock')` `timeclock.php:58-67` | `manage_doughboss` (or `manage_options`, `:315`) | Shows newest 1,000 rows (`:328`); CSV up to 5,000 (`:434`). |
| Staff QR badges (admin) | `staff-badge.php:92-101` | `manage_doughboss` | Issue/revoke; one-time print page. |
| Role-aware login and menu trimming | `class-doughboss-staff-experience.php:40-63,140-168` | Presentation only | Not a security boundary (docblock `:132-137`). |
| Interactive staff guide | `/staff-guide/` and `/management-guide/` (`portals.php:46-48`, `class-doughboss-guides.php:133-136,183-188`) | Public / manager | Says the clock "does not make payroll decisions" (`guides.php:184`). |
| `class-doughboss-table-qr.php` | `table-qr.php:1-80` | Public bearer code | **Not a staff feature.** It is customer dining-table ordering QR (cookie `doughboss_table_session`, 8-hour TTL, tables `doughboss_dining_tables`, `_table_qr_codes`, `_table_sessions`). Excluded from reconciliation. |

History (OBSERVED `readme.txt`): 2.26 prototype page `[doughboss_staff_clock]` (removed by uninstall, `uninstall.php:67-81`); 2.36.0 protected `/staff-clock/`, clock-only role, audited forced close (`readme.txt:134-139`); 2.37.0 QR badges, PINs, breaks, rosters (`readme.txt:125-129`); 2.38.0 staff and management guides (`readme.txt:120`).

### 1.2 Data model

All tables are `ENGINE=InnoDB`, created by `DoughBoss_Activator::create_tables()` through `dbDelta` (`activator.php:564-632`, `:658-661`). `DOUGHBOSS_DB_VERSION` is `1.23.0` (`doughboss.php:31`); attendance arrived at DB 1.20.0/1.21.0 (`migrations.php:150,258`). All timestamps are stored **UTC** with `current_time('mysql', true)`.

`{prefix}doughboss_staff_shifts` (`activator.php:564-585`)

| Column | Type | Meaning |
| --- | --- | --- |
| `id` | bigint unsigned PK | Shift id |
| `user_id` | bigint unsigned | WordPress user (employee identity) |
| `staff_name`, `staff_login` | varchar(191) | Snapshots at clock-in (reports never join live profiles: `migrations.php` 1.20 comment) |
| `location_id` | bigint unsigned | `doughboss_locations.id` (always an active shop; `timeclock.php:213-214`) |
| `location_name` | varchar(191) | Snapshot |
| `timezone_snapshot` | varchar(64) default `Australia/Sydney` | Location timezone at clock-in |
| `clock_in_utc` / `clock_out_utc` | datetime / datetime NULL | Second precision; `clock_out_utc` NULL = open |
| `scheduled_start_local` | varchar(5) | Roster start `HH:MM` snapshot, or empty |
| `late_grace_minutes`, `late_minutes` | smallint unsigned | Roster grace and computed lateness snapshot |
| `open_guard` | tinyint NULL default 1 | 1 while open, NULL when closed; `UNIQUE (user_id, open_guard)` enforces one open shift per employee (MySQL permits many NULLs) |
| `source` | varchar(32) default `staff_portal` | `staff_portal` (WordPress login) or `staff_badge` (QR+PIN) (`timeclock.php:256`; `staff-badge.php:322`) |
| `created_at`, `updated_at` | datetime NULL | |

Indexes: `user_open_guard (user_id,open_guard)` UNIQUE, `location_clock_in (location_id,clock_in_utc)`, `clock_in_utc`.

`{prefix}doughboss_staff_breaks` (`activator.php:606-619`): `id`, `shift_id`, `user_id`, `break_start_utc`, `break_end_utc` NULL, `open_guard` (UNIQUE `(shift_id,open_guard)` = one open break per shift), `source` default `staff_badge`, timestamps. **No break type, no paid flag, no expected duration.**

`{prefix}doughboss_staff_shift_events` (`activator.php:621-632`): `id`, `shift_id`, `event_type` varchar(32), `actor_user_id`, `reason` varchar(500), `before_json`, `after_json` longtext, `occurred_at_utc`. The only event type written anywhere is `manager_closed` (`timeclock.php:404`; search of all three staff classes for other `event_type` writers: none).

`{prefix}doughboss_staff_badges` (`activator.php:587-604`): `user_id`, `token_hash` char(64) UNIQUE (SHA-256 of the bearer token), `pin_hash` varchar(255) (`wp_hash_password`), `status` (`active`/`revoked`), `active_guard` (UNIQUE `(user_id,active_guard)` = one active badge per employee), `issued_by`, `last_used_at`, `revoked_at`.

User meta (`staff-scope.php:18-19`): `doughboss_location_id` (assigned shop) and `doughboss_staff_roster` (array keyed `'0'..'6'` = Sunday..Saturday, each `{start:'HH:MM', grace:0..120}`; validated by regex `staff-scope.php:197,219`).

Capabilities and roles (`activator.php:1204-1290`): `clock_doughboss_staff` is held by administrator, `doughboss_kitchen`, `doughboss_manager` and the clock-only `doughboss_staff` role (`read` + `clock_doughboss_staff` only). `manage_doughboss` is held by administrator and manager. Uninstall removes the caps and roles (`uninstall.php:120-130`).

Not found (searched `timeclock`, `staff-badge`, `staff-scope`, `activator` staff tables, `reports`, `cli`): approval or sign-off state on a shift, a locked pay period, hourly rate, paid-break flag, overtime or award logic, shift reports in `class-doughboss-reports.php`, a privacy exporter/eraser for staff rows, a retention or purge job.

### 1.3 Identity

- **Standard route:** an individual WordPress account with `clock_doughboss_staff`; `get_current_user_id()`; shift rows snapshot `display_name` and `user_login` (`timeclock.php:244-247`). Every action ends with `wp_logout()` (`timeclock.php:307-311`) and the page signs out after 90 s of inactivity (inline script `:160-162`).
- **Badge route:** a manager issues one badge per employee. A 32-byte random bearer token is shown **once** in a QR code on a print page; only its SHA-256 is stored (`staff-badge.php:148-175`, `:457-459`). A scan is consumed by an `init` hook (priority 2, `:29`), validated against `token_hash` + `status='active'` + `active_guard=1` (`:70`), and exchanged for a 120-second transient session bound to a random cookie (`HttpOnly`, `SameSite=Strict`, `:462-472`; TTL const `:23`). The raw token is removed from the address bar by a 303 (`:88`).
- **PIN:** 4-8 digits (`/^\d{4,8}$/`, `:140,277`), hashed with `wp_hash_password`; 5 wrong attempts lock that badge for 900 s (`:24-25,279-287`). The PIN is checked against the badge's own hash, so cross-employee uniqueness is not required by the code even though the admin hint text asks for it (`:120`).
- **A badge session never creates a WordPress login cookie** (class docblock `:3-9`), which keeps the kiosk safe beside an always-on KDS browser.

### 1.4 Location binding

- Staff (non-manager): `DoughBoss_Staff_Scope::assigned_location_id($user_id)` (`staff-scope.php:63-84`) returns the `doughboss_location_id` meta if that shop is active; otherwise the single active shop **only when** `single_location_mode` is on and exactly one shop is active (`locations.php:104-110`); otherwise a `WP_Error`, so clock-in is disabled (**fails closed**). On live today there are three active shops (`01` Summary 5), so every staff account must have an explicit assignment before it can clock in or be issued a badge (`staff-badge.php:143-146`).
- Managers/administrators choose an active shop at clock-in (`timeclock.php:141-147,199-206`).
- The shop is snapshotted into the row (`location_id`, `location_name`, `timezone_snapshot`). Later renames do not rewrite history.

### 1.5 QR/PIN kiosk flow (OBSERVED, `staff-badge.php`)

1. Employee scans badge at `/staff-clock/?staff_badge=<token>` (or pastes into the scan box, `public/js/doughboss-staff-badge.js:16-33`). Server consumes and redirects (303) to a clean URL with a 120-second session (`:55-89`).
2. PIN screen (touch keypad) -> `admin-post.php?action=doughboss_staff_badge_pin` (`:266-294`). Wrong PIN: counter transient; fifth failure locks the badge 15 minutes.
3. Action screen shows Clock in, or Start break / Clock out, or End break (`:222-237`). `handle_badge_action()` re-validates badge, user capability, storage readiness and active shop (`:303-315`), then runs one transition inside `with_user_lock` (named lock `doughboss_clock_<blog>_<user>`, `GET_LOCK(...,5)`, `timeclock.php:286-298`).
4. Rules enforced: one open shift (`already-in`), cannot clock out with an open break (`break-open`), one open break (`already-break`), no break without a shift (`:317-338`). The session and cookie are destroyed after every action (`:343`) and `last_used_at` is stamped (`:339-342`).

### 1.6 Clock-in/out records and breaks

- Clock-in inserts one row with a roster snapshot: `late_minutes = max(0, actual - scheduled - grace)` in the **shop's** timezone (`staff-scope.php:227-248`). Clock-out is a guarded `UPDATE ... WHERE clock_out_utc IS NULL AND open_guard = 1` setting `open_guard = NULL` (`timeclock.php:279`).
- `worked_minutes = floor((out - in) / 60) - break_minutes` (`:531-538`); `break_minutes_for_shift` floors **each** break to whole minutes and sums them (`staff-badge.php:354-370`). There is no automatic or assumed break deduction (docblock `:353`; readme `readme.txt:2.37.0`).
- Breaks are recorded only through the badge kiosk (`start_break`/`end_break`, `:373-393`); the WordPress-login route has no break action (help text `timeclock.php:157`).

### 1.7 Approvals, edits, exports and reports

| Capability | State | Evidence |
| --- | --- | --- |
| Manager forced close of an **open** shift with mandatory reason (max 500 chars) | Exists | `timeclock.php:357-428`; transaction, `FOR UPDATE` row lock, also closes an open break first (`:387-393`), writes a `manager_closed` event with before/after JSON (`:402-414`) |
| Edit the start or end time of a closed shift | **Not found** | no handler; only `handle_correction()` exists and it requires `clock_out_utc IS NULL` (`:381`) |
| Set the true end time when force-closing | **Not found** | `$now = current_time(...)` is used for both break and shift (`:386,394`) |
| Create a missed shift manually | **Not found** | no insert path except `clock_in_for_user()` |
| Approve / lock a pay period | **Not found** | no column, option or screen |
| Manager timesheet screen | Exists | period 7/14/30/90 days (`:513`), shop filter, status `Complete` / `Clocked in`, lateness label |
| CSV export | Exists | columns `Staff member, Username, Shop, Clock in, Clock out, Break minutes, Worked minutes, Scheduled start, Late grace minutes, Late minutes, Status` (`:442`); local-time strings with zone abbreviation (`:449`); spreadsheet-injection neutralised (`:483-489`); nonce `doughboss_export_timesheet` + manager cap (`:431-433`) |
| Scheduled/automated export, email report, webhook | **Not found** | |
| WP-CLI | **Not found** for attendance | commands registered at `class-doughboss-cli.php:555-564` are POSPal, voucher and seed only |
| Privacy export/erase of staff rows | **Not found** | `class-doughboss-privacy.php` handles orders, catering, vouchers only (`:45-90`; `02 §10.2`) |

### 1.8 REST routes and permissions

**None.** `includes/class-doughboss-rest-controller.php` registers 60 routes (`grep -c register_rest_route`); none mention clock, shift, staff badge or timesheet. Attendance is reachable only through these `admin-post.php` actions:

| Action | Handler | Auth |
| --- | --- | --- |
| `doughboss_clock_in`, `doughboss_clock_out` | `timeclock.php:21-22,167-185` | logged in + `clock_doughboss_staff` + nonce (`check_admin_referer`, `:192`) + `storage_ready()` |
| `doughboss_export_timesheet`, `doughboss_correct_shift` | `:23-24` | `manage_doughboss` or `manage_options` + nonce (per-shift nonce for correction, `:360`) + `storage_ready()` |
| `doughboss_staff_badge_pin`, `doughboss_staff_badge_action` (incl. `nopriv`) | `staff-badge.php:30-33` | no WP login; kiosk-session synchroniser nonce compared with `hash_equals` (`:415-419`) |
| `doughboss_issue_staff_badge`, `doughboss_revoke_staff_badge` | `:34-35,134-187` | `manage_doughboss` + nonce |

`storage_ready()` (`timeclock.php:52-55`) requires DB version >= 1.21.0 **and** the InnoDB/column contract (`activator.php:665-`); a failed migration therefore disables attendance and signs the shared screen out (`timeclock.php:73-87`).

### 1.9 Hazards found in the staff code

(Carried into section 5.) Forced close at "now" (summary 2); no tests (summary 4); no privacy/retention treatment of staff PII (names, logins, timestamps, badge hashes); no paid-break flag; one fixed 90-second kiosk timeout; `staff_session_days` can set multi-year WordPress sessions for tablets (`settings.php:160-161`; filter in `class-doughboss.php`), which interacts with `wp_logout()` after every clock action (`timeclock.php:307-311`) if a kitchen tablet user also uses the WordPress-login clock route (INFERRED); badge PIN limiter is per badge not per IP (`staff-badge.php:279-287`; Low).

---

## 2. Design: daily reconciliation of the plugin clock against Square Team timecards

Decision recorded by Elie: run **both** systems and reconcile. Nothing here is implemented; this is the design for the companion plugin (`doughboss-growth`) and its work breakdown.

### 2.1 Principles

1. **Read-only on both sides.** Never create or edit a shift, break, event row or Square timecard. The Square token must carry `TIMECARDS_READ`, `EMPLOYEES_READ` and optionally `TIMECARDS_SETTINGS_READ` only; no `TIMECARDS_WRITE` (matches `SQ` least-privilege plan, `api-integration-notes.md:85-94`, `backoffice-read`). This is how "no two competing writers" is honoured.
2. **Fail closed.** No token, no mapping, plugin storage not ready, Square API error or an incomplete page loop produces `NOT_RUN` / `UNMAPPED`, never "all matched".
3. **Tolerances are configuration, not constants.** Every numeric threshold below is a named parameter with **no shipped default**. Until Elie sets one, that comparison shows the raw delta with status `UNRATED`. (A tolerance of `0` is legal but must be chosen.)
4. **Evidence, not decisions.** The report does not say who is right and does not compute pay. Guides already tell staff the clock "does not make payroll decisions" (`guides.php:184`). Square also exposes no award, penalty, super or leave fields (`SQ:390`).
5. **Minimal personal data.** Store ids and minutes; resolve names at render time from `wp_users`; restrict to `manage_doughboss`.

### 2.2 Field mapping

| Concept | Plugin side (OBSERVED) | Square side (assumption to verify, `SQ:384-390`) | Comparison rule |
| --- | --- | --- | --- |
| Employee | `shifts.user_id` (snapshot name/login only for display) | `Timecard.team_member_id`; `TeamMember` has `email_address`, `assigned_locations`, `status` (`SQ:389`) | Explicit confirmed mapping `user_id <-> team_member_id` in a companion table. Email equality may be **suggested**, never auto-bound. Unmapped employee on either side -> `UNMAPPED_EMPLOYEE`. |
| Location | `shifts.location_id` (+ `timezone_snapshot`) | `Timecard.location_id`, `timezone` (read-only) | Explicit mapping `plugin location_id <-> Square location_id`. Compare **after** employee matching so a wrong-shop clock is visible (`LOCATION_MISMATCH`) rather than looking like two missing shifts. |
| Date (workday) | UTC instants; local day derived from `timezone_snapshot` | `workday { date_range, match_timecards_by, default_timezone }` search filter | Report `workday_local` = local date of the shift **start** in the shop timezone, shifted back one day when the start is before the per-shop `business_day_cutoff_local` parameter. Match by interval overlap, not by date equality. |
| Start | `clock_in_utc` (seconds) | `start_at` (minute precision, seconds truncated, `SQ:386`) | Floor plugin instant to the minute, then `abs(delta)`. |
| End | `clock_out_utc` or NULL | `end_at` or absent while `status=OPEN` | Same floor; open on either side handled by 2.5. |
| Breaks | rows in `staff_breaks`; no paid flag | `breaks[] { start_at, end_at, is_paid, break_type_id, name, expected_duration }` (`SQ:386`) | Compute `break_minutes_all` (sum of every break, each floored like `staff-badge.php:362-368`) and, for Square, also `break_minutes_unpaid`. |
| Net worked | `floor((out-in)/60) - break_minutes_all` (`timeclock.php:531-538`) | `floor((end-start)/60) - sum(all breaks)` for like-for-like; also `- sum(unpaid breaks)` for Square's own view | Compare plugin net with Square net-all; show Square net-unpaid as information. |
| Source | `staff_portal` / `staff_badge` | none | Informational. |
| Manager edit signal | `shift_events.event_type = 'manager_closed'` | `Timecard.version`, `updated_at` (edited or created by a manager) | `MANAGER_CLOSED` / `SQUARE_EDITED` info flags. |

### 2.3 Normalisation rules

- Everything is compared as **UTC epoch minutes**. Parse plugin datetimes with an explicit UTC zone (the plugin itself does `strtotime( $x . ' UTC' )`, e.g. `timeclock.php:444`). Never use local midnight arithmetic for instants; Sydney has DST gaps and folds, and PHP 7.4 mishandles repeated-hour timestamps via `DateTimeZone::getTimestamp()` (`docs/STOREFRONT-COMPLETION-20260908.md:93-97`).
- Overnight shifts: matching by overlap handles them; only the **display** workday uses the cutoff parameter.
- Open entries use the run time as a provisional end for `LONG_SHIFT` checks only; they are never treated as matched.
- Pin the Square API version header explicitly (`SQ` finding 8: the SDK defaults to the newest version; raw `wp_remote_post` has no default) and use a version >= 2025-05-21 for Timecards (`SQ:384`).

### 2.4 Matching algorithm

1. Fetch plugin shifts overlapping `[from, to)` for mapped shops (2.7). Fetch Square timecards for mapped locations with `POST /v2/labor/timecards/search`, paginating `cursor` until exhausted (limit max 200; an invalid filter is silently ignored, so assert the response only contains requested locations and window: `SQ:385`).
2. Group by mapped employee. For each employee, build overlap pairs between plugin shifts and Square timecards (overlap of at least one minute). Pair greedily by largest overlap. Remainders:
   - plugin only -> `MISSING_IN_SQUARE`; Square only -> `MISSING_IN_PLUGIN`;
   - when an unpaired plugin shift and unpaired Square timecard of the same employee lie within the `near_miss_search_minutes` parameter, link them as `NO_OVERLAP_NEAR_MISS` (probably one real shift with large deviation) instead of two "missing".
3. One Square timecard overlapping several plugin shifts (or the reverse) is a `SPLIT` row: compare the union interval and summed breaks and flag `SPLIT`.
4. For each pair compute the deltas in 2.2 and apply 2.5. Store a content hash of both source snapshots so a later manager edit on either side re-opens a previously cleared row.

### 2.5 Mismatch catalogue

| Code | Definition | Parameter | Class | Resolved by |
| --- | --- | --- | --- | --- |
| `UNMAPPED_EMPLOYEE` / `UNMAPPED_LOCATION` | no confirmed mapping on either side | none | Blocker (nothing else is evaluated for that person/shop) | Manager confirms mapping |
| `MISSING_IN_SQUARE` | plugin shift with no overlapping Square timecard | none | Review | Manager: forgot Square clock-in, or Square edited/deleted |
| `MISSING_IN_PLUGIN` | Square timecard with no plugin shift | none | Review | Manager: forgot QR clock-in |
| `OPEN_PLUGIN_SHIFT` | `clock_out_utc` NULL and older than parameter | `open_alert_after_minutes` | Review | Manager forced close (core screen) |
| `OPEN_SQUARE_TIMECARD` | Square `status=OPEN` older than parameter | same | Review | Manager in Square |
| `OPEN_ONE_SIDE` | one side closed, other open | none | Review | Manager |
| `START_DIFF`, `END_DIFF` | absolute minute delta above tolerance | `start_tolerance_minutes`, `end_tolerance_minutes` | Review | Manager |
| `BREAK_DIFF` | difference in total break minutes above tolerance | `break_tolerance_minutes` | Review | Manager |
| `BREAK_PAID_CLASS` | any Square break has `is_paid = true` while the plugin deducts all breaks | none | Info (pay-policy question, not a data fault) | Elie / accountant |
| `NET_DIFF` | net worked minutes differ above tolerance | `net_tolerance_minutes` | Review | Manager |
| `LOCATION_MISMATCH` | matched employee/interval, mapped locations differ | none | Review | Manager |
| `TIMEZONE_MISMATCH` | Square `timezone` differs from `timezone_snapshot` | none | Info | Admin |
| `OVERLAP_SAME_SIDE` | one employee's timecards overlap each other (possible in Square; the plugin's one-open guard prevents overlapping **open** shifts) | none | Review | Manager |
| `CROSS_LOCATION_OVERLAP` | same employee in two shops at once | none | Review | Manager |
| `LONG_SHIFT` | duration above parameter | `max_shift_minutes` | Review | Manager |
| `MANAGER_CLOSED` | plugin shift has a `manager_closed` event (end = correction time, not true finish; `timeclock.php:386,394`) | none | Review (treat plugin end as unreliable) | Manager supplies true end in the system that will be used for pay |
| `SQUARE_EDITED` | Square `version` > 1 or `updated_at` well after `end_at` | none | Info | none |
| `SPLIT`, `NO_OVERLAP_NEAR_MISS` | see 2.4 | `near_miss_search_minutes` | Info / Review | Manager |
| `UNRATED_*` | a comparison whose tolerance parameter is unset | n/a | Info | Elie sets parameters |

### 2.6 Parameters (companion options; none has a shipped default)

`doughboss_growth_recon_` + `start_tolerance_minutes`, `end_tolerance_minutes`, `break_tolerance_minutes`, `net_tolerance_minutes`, `open_alert_after_minutes`, `max_shift_minutes`, `near_miss_search_minutes`, `lookback_days` (re-evaluate recent days so late edits surface), `run_time_local`, per-shop `business_day_cutoff_local`. A report banner lists any unset parameter. Elie chooses the numbers; this document does not.

### 2.7 Minimal read-only data the plugin must provide

**Preferred: no core change.** The companion reads the four tables directly, behind guards, because the table-name accessors are already public static: `DoughBoss_Timeclock::table()` (`timeclock.php:29`), `::events_table()` (`:35`), `DoughBoss_Staff_Badge::breaks_table()` (`staff-badge.php:46`), `DoughBoss_Timeclock::storage_ready()` (`:52`).

Guards (all fail closed): `class_exists('DoughBoss_Timeclock')`; `version_compare( DOUGHBOSS_VERSION, '2.37.0', '>=' )`; `version_compare( get_option('doughboss_db_version'), '1.21.0', '>=' )`; `DoughBoss_Timeclock::storage_ready()`; `$wpdb->last_error === ''` after each read.

Row contract (data minimisation: **no names, logins or reason text**):

```
shift:  shift_id, user_id, location_id, timezone_snapshot,
        clock_in_utc, clock_out_utc|null, source, late_minutes,
        scheduled_start_local, updated_at,
        manager_closed (bool, from an EXISTS on events.event_type='manager_closed'),
        breaks: [ { break_id, break_start_utc, break_end_utc|null, source } ]
window predicate (same shape as timeclock.php:460):
        clock_in_utc < :to AND (clock_out_utc IS NULL OR clock_out_utc >= :from)
```

Use `$wpdb->prepare`, an explicit column list, `ORDER BY clock_in_utc, id`, a hard `LIMIT`, and one batched break query (`WHERE shift_id IN (...)`, not per shift). Existing indexes support it (`clock_in_utc`, `location_clock_in`). Re-implement the per-break flooring so `net` equals `DoughBoss_Timeclock::worked_minutes()` and **prove parity in an integration test** on seeded rows.

If an external consumer later needs it, the companion (not core) exposes `GET /doughboss-growth/v1/timesheet-export?from&to&location` with `manage_doughboss`, a nonce or application-password auth, a hard row cap and the row contract above. Optional later core patch (not needed now): a `do_action( 'doughboss_shift_closed', $shift_id )` after `clock_out_for_user()` for event-driven runs.

The existing CSV is **not** suitable: no `user_id`, no `shift_id`, no `source`, local strings with a zone abbreviation, status only `Complete`/`Clocked in` (`timeclock.php:442-449`).

### 2.8 What the Square side must supply (assumptions to verify; see `SQ` section 10 smoke test 24 and section 11)

1. `POST /v2/labor/timecards/search` with `TIMECARDS_READ` returns `team_member_id`, `location_id`, `timezone`, `start_at`, `end_at`, `status`, `breaks[]`, `version`, timestamps (`SQ:384-386`). Verify the break `is_paid` and `expected_duration` fields are populated for Dough Boss's configured break types.
2. `SearchTeamMembers` with `EMPLOYEES_READ` returns the email used to **suggest** mappings (`SQ:389`). Verify it for real staff; owner team member is not exposed (`SQ:390`).
3. Square API version >= 2025-05-21 for Timecards; Shift endpoints return 410 (`SQ:384`).
4. Unknown, to verify: whether Labor/Team API reads need a paid Square Team plan in Australia (`SQ:600`); whether staff will actually clock in on Square POS at each shop; whether Sandbox can exercise Labor at all (Permissions/Payroll are absent in the Sandbox Dashboard, `SQ:391`), so the first real run needs production read access, which is a data-protection approval gate for Elie.
5. Webhook `labor.timecard.updated` (full timecard, `SQ:353`) could trigger on-demand re-checks later; the daily poll is the base design and the polling backstop.
6. Square minute truncation means Square cannot reproduce plugin seconds; the floor-to-minute rule handles this.

### 2.9 Run model, outputs and failure behaviour

- WP-Cron job at `run_time_local` for the previous complete business day plus `lookback_days`. Precedent: the plugin already schedules hourly WP-Cron work for POSPal (`class-doughboss-pospal-outbox.php:65,72`, `deactivator.php`). Unknown: whether the Crazy Domains host has a real cron; WP-Cron needs traffic (`INFERRED`).
- Storage: companion tables `doughboss_growth_recon_run`, `_recon_row` (key = workday, shop, employee), `_xref` (mapping with `confirmed_by`, `confirmed_at`). Resolution notes live only here; neither source system is touched.
- Outputs: a submenu under the existing `doughboss` admin menu (as `timeclock.php:58-67` does), summary counts per shop/day, per-row detail, CSV of the report. Optional email to a configured address is **off by default**.
- Failure: Square API error, token missing, pagination incomplete or plugin storage not ready -> run status `FAILED`, previous results retained, banner shown; never a green result.

### 2.10 Acceptance tests (synthetic fixtures only)

Perfect match; each tolerance boundary (equal, +1 minute) with the parameter set and unset; missing in each direction; open on each side; forced-close shift; shift crossing midnight; Sydney DST gap and fold (PHP 7.4 and 8.2); two shifts in one Square timecard (`SPLIT`); employee in two shops; unmapped employee/shop; Square `is_paid` break; Square pagination over 200; Square 429/5xx; wrong-filter response; plugin storage not ready; parity of plugin net vs `worked_minutes()`.

### 2.11 Decisions needed from Elie

1. Which system is authoritative for **pay** when they disagree (the report never decides).
2. The tolerance numbers and the business-day cutoff per shop.
3. Whether paid breaks exist in the approved pay policy (the plugin treats every break as deducted).
4. Authorisation to read Square production labour data (staff PII) for the first real run, and who owns the Square merchant (task 18 on the orchestrator list).
5. How forced-close should work going forward: a core change to accept a true end time would reduce `MANAGER_CLOSED` noise but is a core patch and a separate approval.

---

## 3. Development conventions (OBSERVED; C unless stated)

### 3.1 Layout and what ships

Runtime payload = `doughboss.php`, `uninstall.php`, `readme.txt`, `THIRD_PARTY_NOTICES.md`, `includes/`, `admin/`, `public/`, optional `languages/` (`scripts/build-zip.php:87-92`). Everything else (`tests/`, `scripts/`, `docs/`, `ops/`, `themes/`, `.claude/`, `.github/`) is excluded and validated as excluded (`validate-zip.php:52-86`). The theme ships separately (`themes/doughboss-final/`). `.gitignore` excludes `/dist/`, `*.zip`, `/node_modules/`, `/vendor/`.

### 3.2 Tests

- **Shim suite:** `php tests/run.php` loads `tests/bootstrap.php`, then every `tests/test-*.php` in sorted order (`tests/run.php:16-27`); exits non-zero on any failure. **Registering a new test = drop a file named `tests/test-<topic>.php`**; no manifest.
- **Helpers** (`tests/bootstrap.php`): `db_test( $name, $callable )` (`:365`, resets the in-memory options and catches `Exception` and `Error`), `assert_same( $expected, $actual, $label )` (`:406`), `assert_true`, `assert_false`, `doughboss_test_set_settings( array )` (`:57`), `doughboss_test_reset_options()` (`:47`), `db_fail`/`db_pass`. A minimal WordPress shim (options, `sanitize_*`, `home_url`, `WP_Error`, `current_time`) is provided; **no database, network or WordPress runtime is shimmed** (docblock `:5-12`). The bootstrap unconditionally requires `class-doughboss-settings.php` and `class-doughboss-square.php` (`:344-345`).
- **Pure tests** copy patterns such as synthetic orders and fake `$wpdb` classes (`tests/test-store-hours.php:25-60`, `tests/test-pospal-routing.php:10-24`).
- **Node tests:** dependency-free `node --test` files named `*.test.js`; `tests/storefront-helpers.test.js` extracts named functions from shipped ES5 files and runs them in `vm.runInNewContext` (`:9-30`), so the JS stays build-free and testable.
- **Integration:** `tests/integration/wordpress-mysql.php` runs through WP-CLI `eval-file` against a disposable site. It **refuses** to run unless `wp_get_environment_type() === 'local'`, `DB_NAME === 'doughboss_wp_test'`, `home_url() === 'http://doughboss.local.test'` and `WP_HTTP_BLOCK_EXTERNAL === true` (`:16-28`); provider HTTP is intercepted with `pre_http_request`; mail with `pre_wp_mail`. `scripts/test-wordpress-integration.ps1` also runs a 2-process Square race requiring exactly one provider POST (`:55-101`).
- **I ran** `php tests/run.php` in a scratch copy of C (PHP 8.3.6, `ctype` and `zip` present): **412 assertions, 412 passed**. `php -l` passed on the three staff classes. I did **not** run PHP 7.4 (not installed), the integration suite, or any Node test.
- Gap: attendance has no assertions in any of these layers (summary 4).

### 3.3 ES5 JavaScript

- Rule (Elie, in the brief): hand-written front-end JS is ES5 syntax, no build step. Only in-repo statement: `public/js/doughboss-square.js:27-28` ("ES5 only ... var + function, no arrow functions, template literals, const/let, class or optional chaining"); also "No build step" in `doughboss-voucher.js:7` and `doughboss-voucher-scan.js:10`. Style: `(function () { 'use strict'; ... }());` IIFEs, `var`, function expressions, DOM built with `textContent`.
- My scan (grep for `=>`, `const`, `let`, backticks, `class X`) of `public/js/*.js` and theme `assets/theme.js` finds hits only inside comments, as `01 §8.2` reports.
- "ES5" means **syntax only**: `fetch`, `Promise`, `URL`, `NodeList.forEach`, `Element.closest`, `CustomEvent`, `IntersectionObserver` are used (`doughboss-staff-badge.js:6,35,41`, `01 §8.2`).
- **Enforcement: none for ES5.** CI loops `node --check` over all `*.js` (`plugin-ci.yml:40-48`), which accepts modern syntax; `vendor` folders are pruned (`:34,44`). Recommended gate (companion first): an `acorn --ecma5` (or equivalent) parse step in CI, installed in the CI job only, so no production dependency and no shared `node_modules` change.

### 3.4 PHP and WordPress compatibility

- `Requires at least: 6.0`, `Requires PHP: 7.4` (`doughboss.php:7-8`); `Tested up to: 7.1` (`readme.txt:5`). CI matrix: PHP 7.4 and 8.2 (`plugin-ci.yml:17`). Docs note PHP 7.4 runs 390 assertions vs 412 on 8.2 because 22 AVIF checks need 8.2 (`docs/VISUAL-PREVIEW-20260908.md:232`).
- Style observed: WordPress Coding Standards (tabs, Yoda conditions, `array()`, `phpcs:ignore` markers), no typed properties, no `declare(strict_types=1)`, no `str_contains`/`match`/nullsafe/union types (`grep` over `includes admin scripts tests themes`: none). **No phpcs ruleset, composer.json or package.json exists** in either tree, so the `phpcs:ignore` comments are not machine-checked in CI (INFERRED).
- Defensive patterns to copy: `ABSPATH` guard on every PHP file; `final class` + `init()`; capability **and** nonce on every `admin-post` handler; `$wpdb->prepare`; named locks (`GET_LOCK`) and `START TRANSACTION`/`FOR UPDATE` for state transitions; `storage_ready()` fail-closed checks; UTC storage; env-first secrets (`DoughBoss_Settings::env_first_secret`, `settings.php:703-712`).

### 3.5 Packaging scripts and pitfalls

| Script | Does | Pitfall |
| --- | --- | --- |
| `scripts/build-zip.php [out]` | Builds `dist/doughboss-review-candidate.zip` from the whitelist; verifies the four-way version match; **refuses to overwrite** (`:81`); temp file then rename | New root files or directories not listed (`:87-88`) are silently omitted; each rebuild needs a new output path |
| `scripts/validate-zip.php <zip>` | Rejects unsafe/duplicate/non-runtime entries; compares **every byte** to the current tree; re-checks versions | Must be edited together with the builder when the whitelist changes |
| `scripts/build-theme-zip.php`, `validate-theme-zip.php` | Same for `themes/doughboss-final` (version from `style.css` `Version:` and `DOUGHBOSS_FINAL_VERSION`, `functions.php:15`) | Theme zip is about 31 KB |
| `scripts/build-responsive-images.php` | Offline GD generator for AVIF/WebP variants into `public/images/responsive/` + manifest | Needs PHP 8.2 + GD/AVIF; not a runtime dependency |
| `scripts/build-visual-preview.php`, `validate-visual-preview.js`, `scripts/visual-preview/*` | Static synthetic Pages preview with provenance manifest and CSP | Preview is not a WordPress staging site |
| `scripts/test-release.ps1` | Local Windows release gate: PHP lint, `tests/run.php`, Node check/tests, both builders and validators | Needs explicit `-PhpPath` and `-ExtensionDir` |
| `build-zip.sh` | C: 8-line wrapper to `scripts/build-zip.php`. **B:** a different script that does `rm -rf dist`, copies `README.md` into the zip and shells out to `zip` (B `build-zip.sh:12-34`) | Running B's script deletes `dist/` |
| `scripts/seed-menu.php`, `wp doughboss seed-menu` | Seeder | Must never run on live (`01 §3.5`) |

### 3.6 Versioning, changelog and readme

- Bump all four: header `Version:`, `DOUGHBOSS_VERSION`, `Stable tag:`, and a new top changelog entry whose heading regex is `^== Changelog ==\r?\n\r?\n= ([0-9.]+) =` (`build-zip.php:47`). Anything between `== Changelog ==` and the first version heading breaks the build.
- Schema change: bump `DOUGHBOSS_DB_VERSION` (`doughboss.php:31`), edit `create_tables()` (dbDelta is additive), add a `'x.y.z' => 'upgrade_to_x_y_z'` step in `class-doughboss-migrations.php:63-87` and extend `*_storage_ready()` checks if invariants matter. Migrations run under an option lock with a 5-minute stale recovery and checkpoint the stored version after each step (`:28-100`).
- Theme: bump `style.css` `Version:` and `DOUGHBOSS_FINAL_VERSION` together.
- Changelog style: `= x.y.z =` followed by `* ` bullets that state behaviour, "no payment/production configuration changed" where true; reviews and caveats live in `docs/`.
- Version strings do **not** identify a build: live "2.41.0" differs from B's 2.41.0 (`01 §1`) and the hotfix comment refers to a 2.41.1 that does not exist as a label (`ops/hotfixes/534-vouchers-admin-fatal.php:6-7`).

### 3.7 CI workflows (`.github/workflows/`, C only)

- `plugin-ci.yml`: triggers `pull_request` and `workflow_dispatch` only (**not `push`**). Job `verify` (PHP 7.4 and 8.2): `php -l` over every PHP file, `php tests/run.php`, `node --check` and `node --test` over all JS. Job `archive` (PHP 8.2 + zip): build plugin zip, `validate-zip`, build and validate theme zip. Not run: WordPress/MySQL suite, browser tests, provider calls, deployment (`REVIEW-20260908.md:234`).
- `pages.yml`: runs only on a push to branch `codex/visual-preview-20260908`; verifies source and archives, builds and validates the synthetic preview, deploys to GitHub Pages.
- **B has no workflows**, so a branch cut from B has no CI unless it adds one.

### 3.8 `ops/` conventions

- `ops/hotfixes/NNN-slug.php|css`: exact code of an active **WPCode snippet** (NNN = snippet id), header comment states date, defect and the removal condition; every snippet is reversible by deactivation. Currently #533 security headers, #534 vouchers admin fatal, #535 `[hidden]` CSS, #537 mobile navigation drawer (`ops/README.md:12-28`).
- `ops/state/`: version-controlled live configuration the database holds (POSPal product map keyed on lower-cased, whitespace-collapsed item name; analysis notes). Restore via `POST /wp-json/doughboss/v1/pospal/product-map` or Settings (`README.md:53-70`).
- `ops/scripts/`: one-off tooling (`align_prices.py`, `split_drinks.py`). They authenticate with an existing logged-in session through `DB_OPS_DIR/cookies.txt` and `restnonce.txt`; `align_prices.py` is a dry run unless `--apply` (`align_prices.py:24`; README `:92`). They write prices through the classic-editor form because `doughboss_item` lacks `custom-fields` support (`README.md:94-97`). `align_prices.py:36` hard-codes a machine-specific CA bundle path (`/root/.ccr/ca-bundle.crt`).
- Live-configuration table dated 2026-09-07 (`README.md:99-109`); updated by hand and already stale versus live on 2026-10-02 (`01 §10.2`).

### 3.9 Building a SEPARATE companion plugin (`doughboss-growth`) to the same conventions

Directory (new, top-level; not inside the core payload dirs):

```
doughboss-growth/
  doughboss-growth.php      header: Requires at least 6.0, Requires PHP 7.4, GPL-2.0-or-later, Text Domain doughboss-growth
  uninstall.php             drops ONLY doughboss_growth_* tables/options; never a core table, role or capability
  readme.txt                same four-way version rule and changelog heading rule
  includes/class-doughboss-growth-*.php   final classes with init(); ABSPATH guard
  admin/  public/css  public/js           ES5 only, prefix dbg-, global DoughBossGrowth
  tests/bootstrap.php tests/run.php tests/test-*.php tests/*.test.js
  scripts/build-zip.php scripts/validate-zip.php   copied from core; change $slug and the whitelist
  .github/workflows/growth-ci.yml                  distinct file name (avoids an add/add conflict with plugin-ci.yml)
```

Rules (each mirrors an observed core rule):

1. **Gate on core, fail closed.** `defined('DOUGHBOSS_VERSION') && class_exists('DoughBoss_Settings')` and a minimum version (2.41.0 for staff and catering features; 2.43.0 for candidate-only features such as `pickup_status` and `doughboss_load_shop_status_assets`). If absent, show an admin notice and register nothing else. Feature-detect with `version_compare`, never assume 2.43.x (`01 §1`: B is not live).
2. **No competing writers.** Never write core tables, options, post types, roles or capabilities; read menu/locations through `GET /doughboss/v1/menu|locations|config|catering/packages` or the public PHP APIs. Reuse the core capability `manage_doughboss`; do not call `add_role`/`remove_role`.
3. **Own namespace everywhere:** constants `DOUGHBOSS_GROWTH_VERSION`/`_DB_VERSION`, option `doughboss_growth_db_version`, tables `{$wpdb->prefix}doughboss_growth_*`, REST `doughboss-growth/v1`, admin_post actions `doughboss_growth_*`, CSS `dbg-`, shortcodes `[doughboss_growth_*]`, text domain `doughboss-growth`.
4. **Extension seams that exist** (`02 §11`): actions `doughboss_order_created`, `doughboss_order_status_changed`, `doughboss_order_payment_status_changed`, `doughboss_catering_enquiry_created`, `_status_changed`, `_quote_updated`, `_payment`, `doughboss_voucher_claimed`, `_redeemed`; filters `doughboss_marketing_config` (`assets.php:202`), `doughboss_seo_relevant_page` (`seo.php:76`), `doughboss_load_assets`, `doughboss_load_manoush_hero_assets`, `doughboss_load_catering_assets`, `doughboss_is_order_page` (C), `doughboss_load_shop_status_assets` (C). **Not available:** any hook on clock in/out, around payment creation, checkout snapshots, kitchen dispatch or attribution (`02 §11.2`); the theme defines no `do_action` slots (`01 §8.5`). A homepage block needs a theme release; a new `/minis/` or `/corporate-catering/` page works through a shortcode on the generic `page.php`.
5. **Defaults off.** Every feature ships disabled behind an explicit setting (payments-off, Rewards-off pattern: `settings.php:81,193`, Rewards `0`). No auto-enable on activation.
6. **Secrets:** env or constant first (pattern `settings.php:703-712`); never in the repo; never echoed.
7. **Tests:** own `tests/bootstrap.php` (do not `require` the core Square/settings classes as core's bootstrap does); pure logic tested with `db_test`; database logic in a copy of the disposable-site harness with the same four guards.
8. **JS:** ES5 syntax, IIFE, `textContent`, honour `prefers-reduced-motion`, give any autonomous motion a visible pause control (`01 §8.5`), never put `filter`/`backdrop-filter` on `.dbf-header` (`style.css:64-66`; hotfix #537).
9. **Packaging:** small zip under the 2 MB upload cap (the core plugin zip is not), exact-byte validation, refuse-overwrite, `dist/` and `*.zip` git-ignored.
10. **Uninstall hygiene:** the lesson of `doughboss-1` (section 5, R1): an uninstaller must only remove what its own plugin created.

---

## 4. Handoff documents: decisions, open gates, rules

All under `docs/` in C (`candidate-2.43.2/docs/`). Dates are 8-9 September 2026 unless stated.

| Document | Purpose and state | Decisions recorded | Open gates | Rules a contributor must not break |
| --- | --- | --- | --- | --- |
| `REVIEW-20260908.md` (256 lines) | Full-system review; 2.42.0 accepted as a **local** release candidate; later sections add the 2.43.1 Stripe recovery repair and a delivery-gate audit | Square checkout hardened (`square-v2`: immutable attempt binding, cart lock, no re-post after timeout, fail-closed legacy drain, `:58-72`); Stripe return verified against the immutable snapshot, contact drift -> `doughboss_pay_attempt_changed` 409 (`:162-168`); 2.43.0 install **on hold** (`:160`); Square recommended as the pilot all-in-one (`:148`); SamOS hierarchy workspace -> installation -> location (`:131-142`) | Custom pizza builder names unmapped in POSPal (`:124`); Square sandbox/webhook/refund/device acceptance not done (`:125`); production still 2.41.0/1.4.0 (`:126`); retention policy for payment-recovery snapshots (`:127`); SamOS live adapter (`:128`); catering larger-group rule (`:129`); backup destination (Drive 404, `:238-252`); #68 CI not yet run on the repaired HEAD (`:221-236`) | Do not enable Stripe, Square or any gateway as part of publishing (`:39`); never treat a bare numeric location id as globally unique (`:142`); report local/pushed/CI/published/accepted/backed-up states separately; do not claim exactly-once mail |
| `VISUAL-PREVIEW-20260908.md` (245 lines) | Synthetic static preview on GitHub Pages; theme 1.6.1 reduced-motion repair; responsive images (2.43.2); private-preview attempt | Preview built from real renderers with a CLI shim, CSP `default-src 'none'`, no forms or provider calls (`:9-18`); `reduced-motion` transitions set to `none` (`:157-165`); AVIF/WebP via offline generator, 22 derivatives (`:199-205`) | `create_draft_theme` failed 403 (`DISALLOW_FILE_EDIT`, `:136`); no private WordPress preview; plugin zip above the 2 MB cap (`:239`); owner backup is "backed up, go", not agent-verified (`:106-108`) | Do not retry the failed install or draft attempts (`:155`); do not weaken `DISALLOW_FILE_EDIT`; do not call the Pages preview a WordPress staging site; every exporter/package run uses a new output path |
| `SOL-IMPLEMENTATION-HANDOFF-20260908.md` (40 lines) | Concise continuation contract for 2.42.0 | Frozen artifacts and hashes; six remaining gates | POSPal mappings, Square sandbox, publication with rollback, backup upload, SamOS adapter (`:29-36`) | Safety boundary `:20-27`: leave live ordering/payments/POSPal unchanged; no real order, payment, voucher claim or customer message; preserve quarantine and rollback; never retrieve or print credentials; sandbox, CI, push, deploy and Drive upload are separate actions |
| `STOREFRONT-COMPLETION-20260908.md` (99 lines) | 2.43.0 / theme 1.6.0: dietary badges, `pickup_status`, header shop selector, image dimensions | Badges use only saved flags; no gluten-free or halal inference (`:17`); hours status is schedule-only and fails to "unconfirmed" (`:18-20`); repair of a PHP 7.4 DST bug (`:93-97`) | Confirm all online branches and holiday hours; catering headcount estimator, race handling, larger-group rule; provider/POS acceptance; real Web Vitals (`:60-70`) | No new framework or dependency; no guessed stores, prices or dietary certification; non-goals `:13` |
| `ASTRA-DESIGN-BRIEF-20260908.md` (123 lines) | Design direction "The DoughBoss counter" | Ink/Paper/Ember tokens, Bebas Neue + Barlow, glass only on navigation with solid fallbacks, stacking bands 25/40/100/1000/1001 (`:36-38`); staged implementation (catering integrity, then surfaces, then compact order entrance) | Browser acceptance needs a permitted preview (`:93-95`) | **Never put filter, backdrop-filter, transform or containment on `.dbf-header`** (`:36`); do not open competing modal surfaces; do not renumber overlays; reuse existing tokens; stop for ownership collisions or checkout contract changes (`:95`) |
| `INTERACTION-POLISH-20260908.md` (179 lines) | Catering quote latest-selection-only, segmented Style/Crust controls, catering enquiry integrity, Astra stages 1-2 implemented locally | Quote generation counter (`:14-25`); enquiry carries selected shop; invalid positive package rejected at quote and create; staff mail complete (`:93-122`); card motion removed (`:151-160`) | Browser acceptance never ran (file protocol and fixture-server launch were blocked, `:50-60`); larger-group catering policy | Do not bypass the blocked browser paths; no pricing-policy change; keep native radios; no stale totals on failure |
| `ops/README.md` (131 lines; in both trees) | State and tooling for the **live** site, captured 2026-09-07 | Hotfix snippets, POSPal map, repricing scripts, live config table (`:99-109`) | Retire snippets when the fixed build ships (`:26-28`) | **Never delete plugin `doughboss-1` through wp-admin** (`:117-131`); keep POSPal map coverage complete, one unmapped item drops the whole order push (`:62-66`); re-run `align_prices.py` dry run after any till repricing (`:92`) |
| `SQUARE-MIGRATION-20260908.md` (70 lines; supporting) | Square preparation, not activation | Payments vs POS are distinct; one-shop pilot; owner facts needed before connection (`:30-36`) | Merchant account, location, application, kitchen target all unconfirmed | No OAuth/URL claim until built and tested; no atomic WordPress+Square transaction; no dual kitchen dispatch |

Consolidated **must-not-break** list for contributors: (1) payment and ordering switches stay as found unless Elie approves; (2) no real orders, payments, customer messages or provider calls in tests; (3) never print or commit credentials, cookie jars or nonces; (4) preserve quarantine, rollback artifacts and inactive duplicate plugins; (5) `.dbf-header` stays free of filter/backdrop-filter; (6) real photography and honest copy only (`readme.txt` 2.34.0), "oven-baked" wording; (7) saved dietary flags only; (8) no invented price, hour, shop or fact; (9) PHP 7.4+, WP 6.0+, ES5 syntax, no build step; (10) report state levels separately.

---

## 5. Risk register

Mitigations are proposals for the build; none has been applied.

| ID | Hazard | Evidence | Severity | Mitigation / owner |
| --- | --- | --- | --- | --- |
| R1 | Deleting the stale `doughboss-1` plugin (shows as plain "DoughBoss", v2.25.4) in wp-admin runs an uninstaller that drops live orders, vouchers, catering, locations, POSPal outbox and capacity tables, deletes every menu item and package and removes `doughboss_settings` with all API keys | `ops/README.md:117-131` | **Critical** | Remove the folder over SFTP only; verify folder name before any plugin action; never script plugin deletion; take and verify a backup first |
| R2 | The real plugin's `uninstall.php` also drops all 26 plugin tables (including the four attendance tables) and deletes options, caps and roles | `uninstall.php:20-51,91-130` | **Critical** | Deactivate, never delete, to disable; companion must never share table or option names; document that uninstall is destructive |
| R3 | `ordering_open`, `payments_enabled` and per-shop `pickup_enabled` interplay: ordering is currently off, yet all three live shops are `pickup_enabled` and `single_location_mode` has no effect with three active shops, so reopening ordering would expose Bankstown and Roselands | `01` Summary 5; `settings.php:81,144,193`; `locations.php:104-110` | High | Clear `pickup_enabled` per shop (or deactivate rows) **before** `ordering_open` is flipped; verify with the public `/locations` route |
| R4 | Payment gates: payments default off, Square live needs `square_live_approved` and a configured webhook; a manual toggle on 2.41.0 would run the older, weaker Square protocol | `settings.php:193,244`; `02` finding 3 | High | Do not enable any gateway on 2.41.0; Square work targets the `square-v2` protocol after install; sandbox acceptance first |
| R5 | Four WPCode hotfix snippets are live (#533 headers, #534 vouchers admin fatal, #535 `[hidden]`, #537 mobile nav). Forgetting to deactivate them after the fixed build ships double-applies fixes; #533 sets HSTS `max-age=31536000` and `X-Frame-Options: SAMEORIGIN` globally while portals send `DENY` | `ops/README.md:12-28`; `ops/hotfixes/533-security-headers.php:1-23`; `portals.php:418` | Medium | Keep a retirement checklist per snippet; deactivate #534/#535/#537 on deploy of the fixed plugin/theme; decide #533's long-term home (host or plugin) |
| R6 | Version labels do not identify builds: live "2.41.0 / 1.4.0" differs byte-wise from B (five front-end files), B already contains the #534/#535/#537 permanent fixes, and the snippets refer to a 2.41.1 | `01 §1`; `admin/class-doughboss-admin.php:2369-2375`; `534-vouchers-admin-fatal.php:6-7` | Medium | Re-baseline from live bytes or deploy a known build; compare hashes, not version strings, before any claim about "what is live" |
| R7 | Packaging: whitelist silently omits new top-level dirs; plugin zip is about 4.09 MB (>2 MB host cap); `build-zip.php` refuses to overwrite so reruns need new names; B's `build-zip.sh` deletes `dist/` and bundles `README.md` | `build-zip.php:81,87-88`; `VISUAL-PREVIEW:233-239`; B `build-zip.sh:12-34` | High (delivery) | Edit builder and validator together; always run `validate-zip`; small companion zip; use C's scripts only |
| R8 | Delivery route unproven: `DISALLOW_FILE_EDIT` blocked the theme draft (403) and a WP-CLI URL install returned "Plugin not found"; Upload Plugin untested; no staging site | `VISUAL-PREVIEW:112-120,136,155` | High | Ask the host/owner for a supported staging or upload route; do not weaken the constant; test the companion zip first because it is small |
| R9 | 2.43.0 is on install hold; 2.43.1/2.43.2 repair Stripe contact-drift and finalisation defects reproduced only in a disposable harness; hosted CI has not run on the repaired HEAD | `REVIEW:160-170,227-236` | Medium | Treat #68's final SHA as untested until its single CI cycle passes; payments stay off |
| R10 | POSPal order push is fail-all: one unmapped item abandons the whole order push silently; custom pizza names have no verified UIDs; map coverage must be re-checked after every menu change | `ops/README.md:62-66`; `REVIEW:124`; `02` finding 7 | High | Coverage check as a release gate; never invent UIDs; do not copy name-keying for Square |
| R11 | Ops repricing scripts and the seeder wipe data: six items lost dietary flags and descriptions, 11 drinks have none; `align_prices.py --apply` and `split_drinks.py` post the classic-editor form without `doughboss_dietary[]`; seeder button/CLI is a second writer | `01` Summary 3, §3.5; `ops/scripts/align_prices.py:24`; `post-types.php:342-344` | High | Fix scripts to resubmit existing flags/content; fence the seeder on live; read-only plan first |
| R12 | Credential-bearing session files for ops scripts (`cookies.txt`, `restnonce.txt`) are read from `DB_OPS_DIR`, but `.gitignore` does not exclude them | `ops/README.md:77-84`; C `.gitignore` (6 patterns, none for these) | High (secret exposure) | Keep `DB_OPS_DIR` outside the repo; add ignore patterns; scan before every commit |
| R13 | Secrets live in the `doughboss_settings` option (plaintext, backed up with the database) unless an env/constant is set; uninstall removes them | `settings.php:703-712,862-863`; `uninstall.php:91` | Medium | Use env/constants for all keys (pattern exists); the companion uses env-first only; never log tokens |
| R14 | Forced close records "now" as the end time, inflating hours; no edit of closed shifts; no approval | `timeclock.php:386,394,381` | High (pay) | Flag in reconciliation (`MANAGER_CLOSED`); optional core change to accept a true end time (separate approval) |
| R15 | No automated tests for attendance (shim, integration or Node) | grep of `tests/` in B and C: none | Medium | Fixtures plus parity test in the companion work; consider adding core attendance tests as a separate task |
| R16 | Staff PII (name, login, shop, times, badge hashes) outside privacy exporters/erasers; no retention policy; employment-record retention obligations are an owner/accountant question | `privacy.php:45-90`; `02 §10.2`; no purge code found | Medium | Owner decision on retention; minimal data in reconciliation tables; no names in exports |
| R17 | Two systems for time doubles the staff burden; a missed clock-in on either side is the expected failure mode | decision record (Elie) | Medium | Reconciliation adoption metric (`MISSING_IN_*` rate); guide update for staff |
| R18 | No mapping between WordPress staff/shops and Square team members/locations; Square location is a single global setting for payments | `activator.php:182-210`; `02` finding 2 | Medium | Companion-owned xref tables with confirmation audit; no fuzzy auto-binding |
| R19 | CI gaps: no ES5 gate; WordPress/MySQL and race suites not in CI; `plugin-ci.yml` runs only on pull requests; baseline branch has no CI; Pages workflow only on one branch name | `plugin-ci.yml:3-5,37-48`; `pages.yml:3-5` | Medium | Add an ES5 parse step and an integration job; give the companion branch its own workflow |
| R20 | Kiosk session design: bearer QR, 4-8 digit PIN, per-badge (not per-IP) lockout; transients as session store; long WordPress sessions on tablets | `staff-badge.php:23-25,279-287`; `settings.php:160-161` | Low | Acceptable for attendance evidence; revisit if pay is automated from it |
| R21 | Time handling: Sydney DST folds mishandled by PHP 7.4 timestamp conversion; hours stored in three places | `STOREFRONT-COMPLETION:93-97`; `01` Summary 7 | Medium | UTC-only comparisons; single source of hours before any live status claim |
| R22 | Integration harness is destructive by design | `tests/integration/wordpress-mysql.php:16-28` | Low (guarded) | Keep all four guards when copying it |
| R23 | Committed build artefacts under `tests/js/.build/` (HTML harness and four PNG screenshots) in both trees | worktree file lists | Low | Leave; do not extend; exclude from any new packaging |
| R24 | Brief-versus-data conflicts in the unmerged `web/` catalogue (all prices null, `acceptsOnline: true` for three shops, unverified address and copy lines) | `01 §10.8` | Medium (if reused) | `web/` is out of scope; do not seed or copy its catalogue into WordPress |
| R25 | Doc/live contradictions on ordering state; backup destination and restore not verified by an agent | `01 §10.2`; `REVIEW:240-252`; `VISUAL-PREVIEW:106-108` | High (rollback) | Re-read live state before any change; obtain a verified restore point |

Carried from `01`/`02` without change: printer queue is global not per shop (`02` finding 9); catering deposits cannot use Square (`02` finding 10); refund/webhook thinness (`02` finding 4); attribution unwired (`02` finding 11); marketing consent in one place (`02` finding 12).

---

## 6. PR graph and the safest base for new additive work

### 6.1 What the repository metadata shows (plain-file reads of `/home/user/DOUGHBOSSV2/.git`; no git command run)

| Ref | Commit | Plugin version at that commit | Evidence |
| --- | --- | --- | --- |
| `origin/claude/awesome-johnson-bkjh83` (also local) | 240d426c | **2.0.0**, DB 1.0.0 | `.git/refs/remotes/origin/...`; `/home/user/DOUGHBOSSV2/doughboss.php:6,26,31` |
| `origin/ccr-ba2d93d9-lbw916` (checked out here) | 30bf19da | 2.0.0 tree plus `web/` Next.js work and `docs/` | `.git/HEAD`, `logs/HEAD` (commits "web: ..."); created from 240d426c |
| `origin/claude/live-2.41.0-baseline` | 76deb569 | 2.41.0 / theme 1.4.0 | `.git/FETCH_HEAD`; `.git/worktrees/baseline-2.41.0/HEAD` = the B worktree |
| `origin/codex/release-readiness-20260908` | 1600a399 | 2.43.2 / theme 1.6.2 | `.git/FETCH_HEAD`; `.git/worktrees/candidate-2.43.2/HEAD` = the C worktree |

Not visible locally: `codex/full-system-review-20260908` (#66 head 4909823), `codex/storefront-completion-20260908` (#67 head 56159b5), `codex/web-upgrade-2.41.0` and `codex/staff-qr-attendance-2.37.0` (#63). Their descriptions below come from your PR list and from the docs.

### 6.2 Branch graph

```
claude/awesome-johnson-bkjh83 (240d426c, plugin 2.0.0)
  |-- PR #65  head claude/live-2.41.0-baseline (76deb569, 2.41.0)       <- B worktree; no CI workflow
  |      |-- PR #66  head codex/full-system-review-20260908 (4909823, 2.42.0 / theme 1.5.0)
  |      |      '-- PR #67  head codex/storefront-completion-20260908 (56159b5, 2.43.0 / 1.6.0)
  |      '-- PR #68  head codex/release-readiness-20260908 (1600a399, 2.43.2 / 1.6.2)   <- C worktree
  |             contains the #66 and #67 commits as ancestors (REVIEW-20260908.md:234)
  '-- ccr-ba2d93d9-lbw916 (30bf19da)  web/ Next.js + docs/wp  (this work; PR #69 per the orchestrator task list)

codex/staff-qr-attendance-2.37.0 <- PR #63 head codex/web-upgrade-2.41.0   (separate stack; contents not visible)
```

What each contains (OBSERVED from C's changelog and docs unless marked INFERRED):

- **#65 / B:** production-line 2.41.0 source, the staff QR attendance (2.37.0), `ops/`, `themes/` and the permanent fixes for the vouchers fatal, `[hidden]` CSS and mobile drawer (`ops/hotfixes/537-mobile-nav-drawer.css:8-9`). No tests beyond `tests/test-square.php` (94 assertions, `02 §14`), no CI, no `docs/`.
- **#66:** 2.42.0: mobile customiser sheet, Square `square-v2` hardening, WordPress/MySQL integration harness, PHP builders and validators, `plugin-ci.yml` (`readme.txt` 2.42.0; `REVIEW:5`).
- **#67:** 2.43.0 / theme 1.6.0: dietary badges, `pickup_status`, header shop selector, image dimensions (`STOREFRONT-COMPLETION:13-20`).
- **#68:** everything above plus catering enquiry integrity, Astra surface/motion stages, reduced-motion repair (theme 1.6.1), Stripe recovery (2.43.1), responsive AVIF/WebP (2.43.2, theme 1.6.2), `pages.yml`, `test-stripe.php` and the visual-preview tooling (INFERRED from `INTERACTION-POLISH` and `VISUAL-PREVIEW` branch names such as `codex/visual-preview-20260908`; the ancestry statement is `REVIEW:234`).
- **#63:** a 2.37.0-based staff-QR branch with a "web upgrade 2.41.0" on top. INFERRED: its tree is largely what B contains, but this cannot be confirmed without comparing trees.

### 6.3 Merge-order implications (INFERRED)

- If #68 merges into `live-2.41.0-baseline`, #66 and #67 become redundant by ancestry (their heads are ancestors). If #66 then #67 merge first, #68 must be rebased because its base is the baseline, not #67.
- #65 targets `awesome-johnson` (2.0.0): merging it brings a roughly 40-version jump in one PR. Dependent PRs (#66-#68) are stacked on a branch that is itself unmerged; if that branch is deleted after merge, GitHub retargets them.
- 2.43.x is on installation hold (`REVIEW:160`); #68's repaired HEAD has had no hosted CI run (`REVIEW:227-236`).
- Elie's decision needed: which stack becomes canonical, and what the true default branch of `edagher92-coder/DOUGHBOSSV2` is (not visible locally).
- A read-only check someone with git permission can run to settle #63 (not run by me): compare the trees of `origin/codex/web-upgrade-2.41.0` and `origin/claude/live-2.41.0-baseline`.

### 6.4 Recommendation: base for new additive work (companion plugin, scripts, docs for it)

**Primary: branch from `claude/live-2.41.0-baseline` (76deb569), target the same branch, add only new files under a new top-level directory (for example `doughboss-growth/`), and add a CI workflow with its own filename (`growth-ci.yml`).**

Why:
1. It is the closest source to production (2.41.0), and the companion must work against production first (feature-detect 2.43.x only).
2. #66, #67 and #68 all share this base, so a sibling PR does not depend on, or wait for, any of them.
3. New files under a new directory cannot textually conflict with any of the unmerged PRs, which touch `includes/`, `public/`, `themes/`, `tests/`, `scripts/`, `docs/`, `README.md`, `readme.txt`, `doughboss.php` and `.github/workflows/plugin-ci.yml`.
4. It avoids inheriting #68's installation hold and unverified CI state.
5. Using a distinct workflow filename avoids an add/add conflict with `plugin-ci.yml` if #68 merges later; copying the build/validate scripts into the companion directory avoids dependence on C's `scripts/`.

Alternatives and why they are second:
- **Separate repository for the companion:** cleanest isolation and independent release cadence; costs a second CI/PR process. Reasonable if Elie prefers; the conventions in 3.9 still apply.
- **Base on #68 (`codex/release-readiness-20260908`):** inherits CI, scripts and tests, but the PR cannot merge before #68, and 2.43.x is not installed. Use only once 2.43.x is deployed or for a branch that needs candidate-only hooks.
- **Base on `claude/awesome-johnson-bkjh83` or `ccr-ba2d93d9-lbw916`:** not recommended: plugin 2.0.0 and, for the latter, out-of-scope Next.js code. Documentation-only work (`docs/wp/*`) stays on `ccr-ba2d93d9-lbw916` with PR #69.

---

## 7. Gap analysis against the original brief

Legend: **Exists** (works in the tree named), **Partial**, **Missing**. "WP" = the WordPress system (B/C), the extension target. `web/` = the in-repo Next.js app, now out of scope (kept only as a source of reusable logic and evidence). Line numbers are C unless prefixed.

| # | Deliverable | Exists in WP (evidence) | Exists in `web/` (out of scope) | Missing | Where to build in WP | Blockers / notes |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Exploding/blow-out pizza hero | **Missing.** Photo hero with scroll parallax, steam and a Pause control (`public/js/doughboss-manoush-hero.js:1-60`; steam `public/css/doughboss-manoush-hero.css:52-76`; reduced-motion block `:265-281`). The generated explode/assemble scene shipped in 2.23-2.33.x and was removed in 2.34.0 for real photography (`readme.txt:152-153`, `:167-190`); layer assets are not in the tree (`01 §9`) | `web/public/hero/exploded-manoush.glb`, `ai/manoush-blowout-still.webp`, `web/tools/blender/*` (pipeline in progress); three/R3F in `web/package.json`; `web/src/app/page.tsx:1-19` is a placeholder | Layered assets from **real** photography, a dependency-free renderer, trigger and fallback | Companion shortcode that wraps/extends `[doughboss_manoush_hero]` (accepts `photo/home/catering`, `shortcodes.php:70-85`); theme edit needed for the homepage hero (`01 §8.5`) | Real-photo-only rule (`01 §11` Q12 assumed yes); needs approved layer assets |
| 1a | 3D layers | **Missing** (no canvas/WebGL/three in `public/`, `themes/`; grep) | GLB and Blender scripts above | 2.5D CSS-layer or small WebGL module, ES5 | Companion JS file loaded via `doughboss_load_manoush_hero_assets` | ES5 + no build step: either hand-written WebGL or CSS 3D transforms; budget per `01 §8.4` (hero image 329 KB on live) |
| 1b | Particles | **Missing** | none shipped | Particle layer | as above | Must be pausable and reduced-motion aware |
| 1c | Low-power fallback | **Partial.** Reduced-motion CSS and a pause button exist for the photo hero (`manoush-hero.css:265-281`; JS `reduceMotion`, `_dbPhotoPaused`) | `?renderer=poster` mode referenced by `web/tests/e2e/minis.spec.ts:6` | Save-data / low-memory / low-core detection, static poster fallback, IntersectionObserver gating | companion JS | Measure with Web Vitals first (`01 §12` item 10) |
| 2 | Minis "coming soon" teaser | **Missing.** Minis appear only as catering packages and copy (`shortcodes.php:75,262`; `seo.php:89-90`; `01 §9`); till has $2.50 Mini SKUs not exposed online | Minis section tests only (`web/tests/e2e/minis.spec.ts`) | Dedicated teaser page/section | `[doughboss_growth_minis]` shortcode on a normal `page.php` page (no theme change) | Minis price/availability unconfirmed (`01 §11` Q10): no prices on the teaser |
| 2a | 3D cards | **Missing** | none | CSS 3D tilt cards (ES5, reduced-motion safe) | companion CSS/JS | 2.30 "3D tilt" was reduced earlier (`readme.txt:321`) for shape fidelity |
| 2b | Dietary badges (teaser) | **Partial.** Menu cards render saved flags only: `vegetarian`, `vegan`, `halal`, `gluten_free` (`public/js/doughboss.js:451-468`) | `web/src/lib/dietary.ts` | Verified Minis dietary data | read flags from `/menu`; teaser shows "confirmed at launch" until verified | Halal certification unknown (`01 §11` Q4); six live items lost flags (`01` Summary 3) |
| 2c | Party-pack sizer slider | **Missing** (server catering quote exists: `class-doughboss-catering.php:95-150`, `serves_min/max`, per-head) | `web/src/lib/packs.ts:1-30` (20-200 step 10, 3/5/8 pieces per guest, 30/30/25 mix, no prices) | UI slider + estimate copy | companion shortcode; reuse `GET /catering/quote` only for real packages | Rule-of-thumb numbers are planning estimates, not quotes; never show invented prices |
| 2d | VIP waitlist | **Missing.** Only a Rewards line "join the waitlist experience" (`includes/class-doughboss-loyalty.php:82`); no waitlist table or capture (grep) | `web/prisma/schema.prisma:273-295` `WaitlistSubscriber` with consent text, `web/src/lib/repositories/waitlist.ts` | Table, REST route, honeypot and rate limit, consent text and timestamp, double opt-in decision, privacy export/erase, staff notification | companion table `doughboss_growth_waitlist` + route `doughboss-growth/v1/waitlist` (copy honeypot and 5-per-hour limit pattern from catering, `02 §9`) | Spam Act and privacy duties (`web/docs/marketing/research/compliance-au.md`); consent is currently a bare timestamp on loyalty only (`02 §8.4`) |
| 3 | Modern menu | **Partial** (below) | n/a | | | |
| 3a | Category tabs | **Partial.** Sticky category jump bar and search (`doughboss.js:496-660`, `01 §8.3`) in B and C; not ARIA tabs | `web/src/components/ui/tabs.tsx` | ARIA tab semantics if "tabs" is a hard requirement | plugin JS change (core) or a companion menu shortcode | Core change must go through the same release gates |
| 3b | Dietary filters | **Missing** (badges only in C; no filter control; `01 §9`) | `web/src/lib/dietary.ts` | Filter UI plus restored flag data | companion filter script over rendered cards (`data-dietary` attributes exist, `doughboss.js:1049`) | Data repair first (R11); no inferred claims |
| 3c | Item customiser | **Exists.** `details` customiser in B; accessible bottom sheet with live price in C (`doughboss.js:731-843`); options are code-defined (`menu-options.php:63-190`) | `web/src/components/ui/dialog.tsx` etc. | none | n/a | Options keyed on item title are fragile (`01 §12` item 11) |
| 4 | Multi-location ordering with live open/closed status | **Partial.** Location model and selector exist; C adds `pickup_status`, header selector and tests (44 assertions, `tests/test-store-hours.php`); B and live have only a static pause notice (`01 §9`). Ordering is globally off live; Revesby-only is not enforced in data (R3) | `web/src/lib/hours.ts`, `web/src/app/api/stores/route.ts` | Confirmed hours for all branches incl. holidays; per-shop ordering enablement; a single source for hours | core config (per-shop `pickup_enabled`, `location_hours`); status UI already in C | Install of 2.43.x is on hold; hours live in three places (`01` Summary 7) |
| 5 | Next.js / Prisma / Supabase architecture | **Out of scope by decision** (extend WordPress; plugin is canonical for menu and orders) | `web/` exists: Next 15, Prisma schema (models `Store`, `MenuItem`, `Order`, `WaitlistSubscriber`, `CateringLead`, ...), tests, Blender tools | n/a | n/a | Do not deploy or extend; salvage list: `packs.ts`, `hours.ts` and its tests, `dietary.ts`, waitlist/lead schema fields, Blender pipeline, marketing research docs. Risk R24 if its catalogue is reused |
| 6 | On-site SEO for corporate and catering | **Partial.** Catering title/description (`seo.php:89-90`); wholesale and franchising meta (`themes/doughboss-final/functions.php:208-217`); per-shop JSON-LD (`seo.php:189-240`); catering FAQ `<details>` (`shortcodes.php:267-275`). The word "corporate" appears nowhere in `includes/`, `themes/` or `public/js` (grep) | `web/src/lib/seo.ts`, `web/src/components/seo/JsonLd.tsx`, `web/src/content/ledger.ts` | Corporate/office-catering landing page(s), Service/Offer/FAQPage schema, per-shop landing URLs with distinct JSON-LD, `sameAs`, `hasMenu`, `OrderAction`, thin item-page fixes, sitemap hygiene (`01 §7.3`) | companion: its own `application/ld+json` (core graph is not filterable, `01 §8.5`), shortcode pages, `wp_head` | Claims must come from a verified ledger; no invented capacity or price claims |
| 7 | External SEO enablement | **Missing/partial.** Review CTA and `doughboss_google_review_url` filter exist (`settings.php:454`; `front-page.php:84`); no Search Console or Bing verification tag found (grep `google-site-verification`, `msvalidate`: none in plugin/theme); schema has no `sameAs` | `web/docs/marketing/research/*` | Google Business Profile linkage, NAP consistency, citation list, verification tags, review-request flow | companion `wp_head` tags (behind settings), schema `sameAs`, link-in-bio pages | Mostly off-site work; entity/brand ownership (task 18) decides whose GBP/Search Console |
| 8 | Analytics and ads tracking readiness | **Partial.** Consent-gated Meta Pixel and TikTok bridge (`public/js/doughboss-marketing.js`; config `assets.php:196-219`; constants `DOUGHBOSS_MARKETING_ENABLED`, `DOUGHBOSS_META_PIXEL_ID`, `DOUGHBOSS_TIKTOK_PIXEL_ID`), events `view_item`, `add_to_cart`, `begin_checkout`, `purchase`, `generate_lead`. **Not found:** GA4, GTM, Google Ads tag, consent UI (nothing dispatches `doughboss:consent`), click-id capture, server-side forwarding (`adpilotServerReady => false`, `assets.php:209`), attribution columns on orders or enquiries (`02` findings 11-12, `§9`) | `web/src/lib/analytics/*`, `web/src/lib/attribution-schema.ts`, `web/docs/marketing/03a-google-ads.md` | Consent banner, GA4/GTM, Google Ads and Meta CAPI plan, UTM and click-id first-party capture, attribution side table | companion via `doughboss_marketing_config` and `doughboss_catering_enquiry_created` hooks (`02 §9` table) | Core patch needed for orders attribution (`doughboss_checkout_snapshot_payload` filter, `02 §9`); privacy and consent decisions |
| 9 | Lead capture | **Partial.** Catering enquiry stored in `doughboss_catering_enquiries` with honeypot and 5/hour/IP limit, customer and staff email (`02 §9`); after-hours pre-order request exists but disabled (`settings.php:135`); voucher claim collects student email; Rewards sign-in has an optional consent tick (`loyalty.php:94`) | `web/prisma` `CateringLead`, `web/src/components/enquiry/CateringEnquiryForm.tsx` | Source/attribution/consent fields, corporate form variant, duplicate-submit key, bot protection beyond honeypot | companion side table keyed by enquiry id; extra form fields via companion shortcode posting to the existing endpoint | Marketing consent write-only today (`02` finding 12) |
| 10 | Corporate catering funnel | **Partial.** Four live packages, server quote, enquiry, deposit/balance workflow, statuses `new, quoted, deposit_paid, confirmed, balance_due, paid, fulfilled, lost`, manager quote editor (`02 §9`); `/catering/` page and homepage panel (`page-catering.php`, `front-page.php:63-70`) | `web/prisma` `CateringLead`/`LeadStatus`, `web/tests/e2e/catering-seo.spec.ts` | Corporate segmentation (company, ABN, PO, recurring), headcount estimator, larger-group commercial rule (`REVIEW:128`), lead scoring, follow-up sequence, funnel reporting, Square Invoices option | companion (scoring/attribution via hooks, reporting page); core only for new enquiry columns | Company/ABN/PO fields not found in the enquiry schema (`activator.php` catering table; `02 §9`); deposits cannot use Square today (`02` finding 10) |
| 11 | Timesheet reconciliation (decided after the brief) | Plugin clock exists (section 1); Square Team side not built | n/a | Section 2 design | companion | Needs Elie decisions in 2.11 |

---

## 8. Conflicts and contradictions

1. **Hotfix comments vs source labels.** Snippet #534 says "remove once DoughBoss 2.41.1 (repo fix) is deployed" (`534-vouchers-admin-fatal.php:6-7`), but B contains the fix while still labelled 2.41.0 (`doughboss.php:6`; `admin/class-doughboss-admin.php:2369-2375`). There is no 2.41.1 build.
2. **Guide wording vs behaviour.** The staff guide says a manager can make an "audited correction" (`guides.php:188`); the correction is audited but its end time is the correction moment (`timeclock.php:386,394`).
3. **Admin hint vs code on PIN uniqueness.** The issue form asks for a "unique" PIN (`staff-badge.php:120`); nothing enforces it and nothing needs it (PIN is checked against one badge's hash).
4. **`ops/README.md` live table vs live.** "Ordering open, Revesby only" (`:103`) vs `01` (live: ordering off, three active shops).
5. **Docs say Drive target and Pages preview are separate from the WordPress site.** True, but the preview's PR and CI evidence (#66/#67) is described as covering "older candidates" only (`REVIEW:234`); treat it as non-evidence for 2.43.2.
6. **CI claim vs reality.** `REVIEW-20260908.md` reports CI passes; the workflow does not run the integration suites whose assertion counts the docs quote (`REVIEW:227-236`; `02` finding 15).
7. **Brief says Next.js/Prisma/Supabase; decision says extend WordPress.** `web/` remains in the checkout; it must not be treated as the target.

## 9. Unknowns for Elie

1. Which system is authoritative for pay when the plugin clock and Square Team disagree; are any breaks paid (2.11 items 1 and 3)?
2. The tolerance numbers (start, end, break, net), open-shift alert time, maximum shift length, business-day cutoff per shop, and run time (2.6).
3. Permission to read Square production labour data (staff personal data) for the first real run; which Square merchant owns the timecards (task 18).
4. Do staff actually clock in on Square POS at each shop, and does Labor/Team API access need a paid Square Team plan in Australia (`SQ:600`)?
5. Staff-data retention and who may export staff records (owner/accountant question).
6. Should forced-close accept a true end time (core patch, separate approval)?
7. Is the default branch of `edagher92-coder/DOUGHBOSSV2` the line #65 targets, or something else; which PR stack is canonical (#66+#67 vs #68); is #63 redundant?
8. Does the host allow Plugins -> Upload Plugin for a small zip (is `DISALLOW_FILE_MODS` set), and is there a real server cron for WP-Cron?
9. Companion plugin in this repository (new top-level directory) or its own repository?
10. Is a verified restore point (database and uploads) available before any live action? The owner's "backed up, go" is recorded, not observed (`VISUAL-PREVIEW:106-108`).

## 10. Open issues for the architect and the build

1. Write the companion reader and reconciliation module (section 2) with fixtures and a `worked_minutes()` parity test; add attendance tests to the shim suite or the integration harness.
2. Add an ES5 syntax gate and an integration job to CI (companion workflow first).
3. Add `.gitignore` entries for `cookies.txt` and `restnonce.txt` and move `DB_OPS_DIR` outside the repository (R12).
4. Decide the forced-close behaviour and a staff-data retention/privacy treatment (R14, R16).
5. Create the mapping tables and confirmation UI before enabling any Square read.
6. Build order for the creative asks: shortcode pages (Minis teaser, sizer, waitlist) need no theme change; the homepage hero needs a theme release with `do_action` slots (`01 §12` item 9).
7. Re-baseline from live bytes so "baseline" means production (`01 §12` item 1).

## 11. What I ran, and what I did not

- Read-only: file reads, `grep`, `cmp`, `diff -rq` over both worktrees and `web/`; plain `cat` of `/home/user/DOUGHBOSSV2/.git/{HEAD,FETCH_HEAD,refs,logs,worktrees/*}` (no `git` command was executed).
- Scratch copy of C under the session scratchpad: `php tests/run.php` -> **412 assertions, 412 passed** (PHP 8.3.6); `php -l` on `class-doughboss-timeclock.php`, `-staff-badge.php`, `-staff-scope.php` -> no errors.
- Not run: PHP 7.4, the WordPress/MySQL integration suite, any Node test, any build or validate script, any network call, the live site, any provider API.
- Nothing inside either worktree was modified; the only file written by this slice is this document.
