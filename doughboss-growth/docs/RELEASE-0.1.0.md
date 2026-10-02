# DoughBoss Growth 0.1.0: release candidate runbook

Status: **release candidate, not released.** Nothing here has been installed on the live site, no provider (Google, Meta, Square) has been called, and no flag has been switched on anywhere except the throwaway local runtime. Every step below that touches the live site is Elie's decision.

Package: `doughboss-growth-0.1.0.zip`, one code zip, built by `scripts/build-zip.php`, checked by `scripts/validate-zip.php` and `scripts/budgets.php`.
Requirements: WordPress 6.0 or later, PHP 7.4 or later, DoughBoss (core) 2.41.0 or later. The live site runs core 2.41.0 on PHP 8.2.33.

There is no hero, no 3D, no WebGL and no media zip (Elie, 2026-10-02: "3D here is a no no"; `web/docs/site/hero-decision.md`). The plugin has **eleven** flags.

## 1. What you are installing

Installing and activating the plugin changes nothing a visitor can see. Every one of the eleven features is off, each is enabled separately under DoughBoss, Growth, and several refuse to switch on until a prerequisite exists.

| Flag | What it does when on | Prerequisite the settings page enforces |
| --- | --- | --- |
| `consent_banner` | Consent banner, "Privacy choices" button, Consent Mode v2 defaults (all four signals denied) | none |
| `gtm` | The single Google Tag Manager loader and the typed dataLayer events | `consent_banner`, a container id |
| `attribution` | First-party source capture (cookie `dbgr_attr`, 90 days) and side records for enquiries and orders | none (it does nothing visible without the banner) |
| `server_conversions` | purchase, refund and lead events to GA4 and Meta through the outbox, and an offline Google Ads CSV export | `attribution`, one configured destination |
| `landing_pages` | Six landing pages (three catering, three shops), created as drafts | a valid claims ledger |
| `seo_head` | Title, description, Open Graph and JSON-LD for those pages | `landing_pages` |
| `lead_form` | Corporate lead form shortcode (core's enquiry route, plus company, segment, optional marketing consent) | none (no consent box until a sender name exists) |
| `party_sizer` | Party-pack sizer shortcode, real packages and prices from core only | none |
| `coming_soon` | A neutral "Something exciting is coming" section | a valid claims ledger |
| `waitlist` | VIP list with double opt-in, opt-out, privacy exporter and eraser | sender legal name, privacy-policy URL |
| `timesheet_recon` | Read-only staff clock versus Square Team report | none (it needs a labour token before it can run) |

One module is deliberately active even with every flag off: the **waitlist "always" duties** (opt-out link pages and route, privacy exporter and eraser, purge cron). A person must always be able to leave a list, be exported and be erased, so these stay registered while sign-ups are off. With every flag off they print nothing on a public page (proved by the byte comparison in section 6). Activation also schedules the daily retention purge, so deactivating and reactivating with the waitlist off keeps the retention promise.

Safety switches, strongest last:

1. Untick a feature in DoughBoss, Growth. The public site returns to its previous output immediately.
2. Define `DOUGHBOSS_GROWTH_DISABLE` as `true` in `wp-config.php`. Stops the whole companion without deactivating it.
3. Deactivate the plugin. The six landing pages go back to draft. See the shortcode warning in section 5.
4. Delete the plugin. Nothing is deleted unless `DOUGHBOSS_GROWTH_UNINSTALL_DELETE_DATA` is `true` in `wp-config.php`.

## 2. Before you install (one-off)

1. Take a full backup (files and database) and note the time. This is the rollback point for everything below.
2. Confirm core: DoughBoss 2.41.0 or later is active (Plugins screen). The companion shows an admin notice only, and does nothing, if core is missing or older.
3. Upload `doughboss-growth-0.1.0.zip` (270,365 bytes, 51 files, 27% of the 1,000,000-byte budget, well inside Crazy Domains' 2 MB upload limit; sha256 `1b61a7280327e90da9ca5a6e3f0ea3e75e152b52be1bb8751f37a5a2bffa7df2`, and a rebuild from the same tree is byte-identical) under Plugins, Add New, Upload Plugin. Do not use any other build.
4. Activate. Open DoughBoss, Growth. Expected: eleven unticked boxes, a list headed "To decide before switching on" (owner decisions, in plain words, with a "Before you switch this on" note under each switch), and `GET /wp-json/doughboss-growth/v1/health` (signed in as an administrator) showing every flag `false` and `storage_ready: true`.
5. Load `/`, `/order/`, `/catering/` and `/locations/` once. They must look exactly as before.
6. Page caching: exclude the catering pages from full-page caching on Crazy Domains (or any cache plugin) before enabling `lead_form` or `party_sizer`. The form uses core's `wp_rest` nonce; a cached copy older than the nonce lifetime gets a 403 and the visitor sees a refresh prompt.
7. WP-Cron: the outbox dispatch and the waitlist purge run from WP-Cron. Confirm cron fires on the host (a real server cron hitting `wp-cron.php` is better than visitor-triggered cron).

### What the polish pass added (an installer should know)

- Settings, Status table: a new "Recent failures" row lists the last problems the companion recorded (stored in the option `doughboss_growth_failures`, not autoloaded; removed on uninstall with the other options).
- `consent_text_version` now defaults to 2 and the banner wording changed. **NEEDS LEGAL REVIEW before `consent_banner` is switched on.** Raise the version again after any further wording change.
- A webhook alone no longer counts as a conversion destination: `server_conversions` needs GA4 or Meta configured.
- The tilt cards (a decorative effect on the landing pages) are removed.

## 3. Rollout order

Switch on one feature at a time, check it, wait, then move on. A suggested order, safest and most independent first, privacy-dependent features after the privacy policy is updated:

1. **Install and activate** (everything off). Soak for a day.
2. **`consent_banner`**: after the privacy-policy URL is saved and the banner wording is approved.
3. **`landing_pages`, then `seo_head`**: after Elie confirms which claims may be published (all seven are gaps today, so catering pages are `noindex` and carry no claim content), then publishes the drafts.
4. **`lead_form` and `party_sizer`**: after the page-cache exclusion (section 2, step 6) and the open items in section 4.
5. **`coming_soon`**, then **`waitlist`**: after the sender legal name, privacy-policy URL and retention period are decided.
6. **`attribution`**: needs the banner on to collect anything. Do not enable until the retention and erasure decision in section 4 is made.
7. **`gtm`**: after a container id from the right entity's account and a privacy policy that describes the tags.
8. **`server_conversions`**: last of the measurement stack, after accounts, secrets and the GA4 lead decision below.
9. **`timesheet_recon`**: standalone. Only after Elie approves reading Square staff data and sets every tolerance.

## 4. Per-flag enable checklist (owner gates)

The same notes are printed under each switch on the Settings tab (this document is not in the plugin zip, the Settings tab is).

The owner gates are from `web/docs/wp/05-work-breakdown.md` section 4. A box that is not ticked means do not enable. After enabling each feature, run its "verify" step before the next. Rollback for every row is "untick the flag, then reload the public pages" unless the row says more.

### consent_banner and gtm (WP-03)

- [ ] Elie supplies the privacy-policy URL and the policy describes the cookies and tags. (Gate: updates the privacy policy.)
- [ ] Elie and a solicitor review the banner wording and the three categories. Raise `consent_text_version` after any change so everyone is asked again.
- [ ] Elie picks the consent default. `deny` is shipped. `opt_out` is a legal and accountant decision, not a technical one.
- [ ] For `gtm`: a Tag Manager container id (`GTM-` plus 4 to 10 capitals or digits) from an account owned by the right entity. Inside the container, GA4 must require `analytics_storage`; Ads and Meta tags must require `ad_storage`, `ad_user_data`, `ad_personalization`. The plugin sets the signals; the container decides what fires.
- Verify: banner shows with Accept, Reject and Choose of equal prominence; before any choice the only tracking request is the Tag Manager loader; Reject persists across reload; "Privacy choices" reopens it.
- Known: events are dropped, not queued, until the visitor allows measurement or advertising (stricter than Consent Mode "advanced"). No `<noscript>` Tag Manager iframe (visitors without JavaScript cannot be asked). `begin_checkout` has no `payment_method` until core 2.44.0 sends one. `item_slug` is core's menu item id.

### attribution (WP-04)

- [ ] **Decision needed first:** there is no automatic deletion of attribution or lead rows, and these tables are **not** covered by the WordPress personal-data exporter and eraser. Elie, with an accountant or solicitor, sets a retention period and decides whether an exporter and eraser are required before enabling (open finding F-1 in `web/docs/wp/06-growth-release-review.md`). Until then, a person's rows can be removed by enquiry or order id only by an administrator with database access.
- [ ] The privacy policy describes the `dbgr_attr` cookie (90 days) and that the source of an enquiry or order is stored with it.
- Verify: with the banner on and consent accepted, `/catering/?utm_source=test` sets `dbgr_attr`; with consent rejected it does not. A signed-in manager's browser never records its own visits.

### server_conversions (WP-08)

- [ ] GA4 measurement id and `DOUGHBOSS_GROWTH_GA4_API_SECRET`; Meta pixel id and `DOUGHBOSS_GROWTH_META_CAPI_TOKEN`; each from an account owned by the right entity. Secrets go in `wp-config.php` or the environment, never in the settings page. Nothing is queued for a channel until both parts exist.
- [ ] The privacy policy says order and enquiry details and consented advertising identifiers go from the server to Google and Meta.
- [ ] **Decide the single GA4 source for `generate_lead`.** Tag Manager (the browser) and the server both send it, so GA4 would count a lead twice. Keep one: mark the server lead as the source and drop the tag's `generate_lead` GA4 event, or the reverse. Meta de-duplicates only if the pixel sends the same `event_id` (`lead:{enquiry_number}`), which the browser event does not yet. (Open finding F-3.)
- [ ] Verify against provider documentation before relying on them: Meta Graph API version `v22.0`, Meta `event_source_url`, GA4 Measurement Protocol (run the GA4 validation endpoint first), the Google Ads conversion action names (`Catering quote sent`, `Catering order won`) and the upload template columns.
- [ ] Decide: whether quote rows in the offline export carry a value (they carry core's quote total today); the GST basis of the values.
- [ ] Hashed identifiers stay off (`send_hashed_identifiers` 0) until the privacy policy covers them.
- Verify: pay-at-shop (unpaid) orders produce no `purchase`; with measurement consent refused nothing goes to GA4; with advertising consent refused nothing goes to Meta; the offline CSV has one row per quoted enquiry that carries a Google click id.
- Known: outbox rows (sent and failed) have no retention rule yet.

### landing_pages and seo_head (WP-06, WP-02)

- [ ] Elie confirms the claims to publish in the claims ledger (`content/claims.json`; all seven are unconfirmed gaps today: lead time, service area, delivery or drop-off, dietary status, catering phone line, reviews, how the food is made). The public-copy lint rejects dietary words (halal, vegan, gluten, nut-free), "certified", "best" and "#1" outright even for owner-confirmed claims, so a dietary claim needs a deliberate lint change.
- [ ] Core location slugs: the shop definitions assume `revesby`, `bankstown`, `roselands`. The live Roselands row is called "Roselands Centro"; if its slug differs the page renders nothing (fail closed) and the Landing pages tab says why. Fix `location_slug` in `content/landing/locations-roselands.json` if needed.
- [ ] Elie publishes the drafts (the plugin never does). Check each page's title and description against the Landing pages tab; with Yoast or Rank Math active the companion prints no head tags unless `seo_jsonld_with_seo_plugin` is set, so enter the text there.
- [ ] Core catering form: core 2.43.2's own catering page copy contains the unannounced product name (`/catering/` today, with every companion flag off). The companion hides the core form block on landing pages for that reason. Core 2.44.0 must neutralise that copy (open finding F-2).
- Verify: each page has exactly one title, one description, one canonical (core's), one companion JSON-LD script, no `geo`, `sameAs`, rating or price range; shop address, phone and hours equal core's; package prices equal core's.
- Rollback: **unticking the flag leaves published landing pages online but empty** (the shortcode renders nothing). To take the pages down, set the six pages to Draft (or Trash) as well. Deactivating the plugin drafts them for you.

### lead_form and party_sizer (WP-07)

- [ ] Sender legal name (and ABN if shown): until set, no marketing-consent box is shown and no marketing consent can be collected. The enquiry itself still works.
- [ ] Elie and a solicitor approve the marketing-consent wording. The box is separate, optional and unticked. Ticking it records consent only; whatever later sends marketing must honour it and give a working unsubscribe (Spam Act).
- [ ] The privacy policy describes what an enquiry stores (company, segment, source, consent and its wording version).
- [ ] Retention and erasure of lead records: same open decision as `attribution` (F-1).
- [ ] Pieces-per-guest guidance stays hidden until the ledger holds a confirmed, sourced claim `catering-pieces-per-guest` (an unconfirmed gap is listed in `claims.json`).
- Verify: submit the corporate form; core records one enquiry and the Leads tab shows one record with the right segment; a rejected enquiry (honeypot, rate limit) leaves no record; the sizer shows only core's packages, serve ranges and totals.

### coming_soon and waitlist (WP-05)

- [ ] Sender legal name (and ABN if shown), privacy-policy URL, and the confirmed-sign-up retention period (unset means never deleted automatically). The waitlist cannot be enabled while the first two are empty.
- [ ] Elie and a solicitor approve the consent wording (it names the sender, covers email, and covers text only if a mobile number is given). Nothing sends text messages and there is no SMS opt-out.
- [ ] The teaser copy stays generic. The headline and body are edited in the Coming soon tab and pass the public-copy lint at render time; a value with a digit, price, dietary word, location, date or the working name falls back to the neutral default. There is no product-specific interest picker and no code path for one.
- [ ] If Crazy Domains puts all visitors behind one proxy address, the five-per-hour sign-up limit is shared by everyone; supply the real address through the `doughboss_growth_client_ip` filter.
- [ ] Mail: confirm the confirmation email actually arrives (SPF, DKIM, DMARC, and that it is not filtered). Nothing here could test a real mailbox.
- Verify: sign up with a test address; the confirmation arrives; the link opens a page with a button (a mail scanner's GET changes nothing); confirm; unsubscribe by button and by the one-click header; a duplicate sign-up gets the same neutral message.
- Rollback: untick `waitlist`; sign-ups stop, the opt-out link keeps working, stored rows stay exportable and erasable.

### timesheet_recon (WP-11)

- [ ] Elie approves reading Square staff data (personal data) and confirms who owns the Square merchant account (orchestrator task 18). Do not supply `DOUGHBOSS_GROWTH_SQUARE_LABOUR_TOKEN` or `DOUGHBOSS_GROWTH_SQUARE_ENV` until both are settled.
- [ ] Elie sets every number: start, finish, break and net tolerances, open-shift alert, longest normal shift, near-miss distance, look-back days, daily run time, per-shop cutoff. **No tolerance ships**; until set, each check shows UNRATED and nothing turns green.
- [ ] Decide which system is authoritative for pay when they disagree (the report never decides) and whether paid breaks exist.
- [ ] First approved read: confirm whether `version` above 1 is normal after a clock-out (otherwise the information-only `SQUARE_EDITED` flag is noisy), whether `is_paid` is filled for the break types (a break without it fails the run, by design), and whether the Labor reads need a paid Square plan.
- Verify: a manual run over a short range returns RUN, INCOMPLETE or NOT_RUN with a reason; the previous result stays on screen after a failure; nothing is written to any core table.

## 5. Rollback

| Level | Action | Result |
| --- | --- | --- |
| One feature | Untick it in DoughBoss, Growth and save | Public pages return to their previous output: in the local run each of the eleven flags, switched on alone (with only the prerequisite the settings page demands) and then off, left all seven public test pages byte-identical to the flags-off reference. For `landing_pages` and `seo_head` that holds once the six pages are set back to Draft (see the row's note in section 4). Data the feature stored is kept. |
| Everything, site stays up | `define( 'DOUGHBOSS_GROWTH_DISABLE', true );` in `wp-config.php` | The companion is inert: no module runs, an admin notice says so. Remove the line to resume. |
| Deactivate | Plugins screen, Deactivate | The six landing pages, and the page at the coming-soon slug when its content is exactly one companion shortcode, go to Draft and the companion's cron events are removed. Settings and tables are kept. **Warning:** any other page the owner wrote that holds a companion shortcode (a form page, a waitlist page, a page with two shortcodes) is not on the plugin's list, so it shows the raw tag (for example `[doughboss_growth_waitlist]`) while the plugin is deactivated; the local run observed exactly this. Set such pages to Draft first, or remove the shortcode. |
| Uninstall | Delete the plugin | Nothing is removed unless `DOUGHBOSS_GROWTH_UNINSTALL_DELETE_DATA` is `true`, in which case only `doughboss_growth_*` tables, options, transients and cron events are dropped. Core data is never touched. |
| Full | Restore the pre-install backup | Returns the site to the state in section 2, step 1. |

The companion never writes a core table, option, post type, role or capability; the one exception it is allowed is the status of the six landing pages it created. Core does not need a downgrade to roll the companion back.

## 6. Evidence

The PHP, Node, build and browser results below were re-run on 2026-10-02 after the polish pass; rows marked "not re-run" are from the earlier build. Detail, failures found and fixed, and what was not run are in `web/docs/wp/06-growth-release-review.md`.

| Check | Result |
| --- | --- |
| `php tests/run.php`, PHP 8.3.6, core source for the parity test pointed at 2.43.2 and then at 2.41.0 | 7,712 assertions, 7,712 passed, 0 failed, 0 skipped, 0 foreign writes (both). Without a core source: 7,707 passed, 1 visibly skipped parity test |
| Same suite, real PHP 7.4.33 (WebAssembly build from the installed Playground CLI) | Not re-run after the polish pass (the figure from the earlier build, 5,336 passed, 0 failed, 25 skipped, no longer applies). Re-run before relying on PHP 7.4 |
| `php -l` on every PHP file | 77 of 77 clean on 8.3.6 (not re-run on 7.4.33 since the polish pass) |
| `php scripts/php74-guard.php` | 77 files, no violations, on 8.3.6 (not re-run on 7.4.33 since the polish pass) |
| `node --test tests/*.test.js` (Node 22) | 162 tests passed (attribution 29, consent 33, core 24, datalayer 26, lead-form 21, party-sizer 12, waitlist 17), 0 skipped |
| ES5 gate `scripts/es5-check.mjs` | 6 files, no violations (the negative control, a planted arrow function, was not re-run) |
| Build, validate, budget | one zip, 51 files, 270,365 bytes (27% of 1,000,000); `validate-zip.php` and `budgets.php` OK; a second build is byte-identical (checked with `cmp`); the builder refuses to overwrite (earlier check; the old zip had to be deleted first); no file in the zip contains the working name (earlier check) |
| `web`: `npx vitest run`, `npx tsc --noEmit -p .` | 780 passed (29 test files); tsc clean. (`npx eslint scripts/wp-local/growth/` was not re-run) |
| Inert test (`wp01-inert.mjs`), from the earlier build and not re-run after the polish pass: `/`, `/order/`, `/catering/`, `/locations/` with and without the companion, every flag off, nonces masked | **Identical** against the candidate core 2.43.2 (sha256 703e0491913c, c8d155f9b852, f24ecb533f15, 33ed18056e1a) and against the live-line baseline 2.41.0 (1ecb636b5982, 3b54956a8f12, cdfc2b0110e6, d242cc9e4ab5) |
| Browser run `wp16-full.mjs` on the candidate (PHP 8.2, WordPress 7.1, SQLite) | 188 checks passed, 0 failed, 0 outbound requests reached the network (every non-local server-side request recorded and refused): each of the eleven flags alone (switched on, effective, expectation met, no first-party console error or failed request, matching admin screen loads, switched off to byte-identical pages), all eleven together, desktop 1280x800 and Pixel 7 screenshots of `/`, the coming-soon page and the six landing pages (plus the form and waitlist pages), axe, banner accept, every admin screen, deactivate and reactivate, clean debug and server logs |
| Same run on the baseline 2.41.0 | 188 checks passed, 0 failed, 0 outbound requests |
| Smoke on PHP 7.4 runtime (candidate core, flags off then all on), from the earlier build and not re-run | Home page sha identical to the PHP 8.2 capture; 100 checks passed, 0 failed |
| Core isolation | Of 26 core tables, only the two the scratch seed deliberately writes changed (the fake test shops); no core option and no role or capability changed (`docs/evidence-wp16/core-isolation.txt`) |
| axe | No violation inside companion markup on any page, desktop or mobile. Core and theme violations that this work did not cause: colour contrast on `/`, `aria-allowed-role` on `/order/`, heading order on `/locations/` (candidate) |

Raw results: `docs/evidence-wp16/` (checks JSON per run, run logs, reference hashes). Screenshots are in `/tmp/wpl-wp16/shots-C/` on the build machine (scratch, not in the repository): `wp16-home`, `wp16-coming-soon`, the six `wp16-catering-*` and `wp16-locations-*` landing pages, `wp16-lead-test` and `wp16-waitlist-test`, each `-desktop.png` and `-mobile.png`.

### Reproduce

```
php doughboss-growth/tests/run.php                                       # DBGR_CORE_SRC=/path/to/core optional
php doughboss-growth/scripts/php74-guard.php
ACORN_PATH=web/node_modules/acorn node doughboss-growth/scripts/es5-check.mjs
php doughboss-growth/scripts/build-zip.php && php doughboss-growth/scripts/validate-zip.php doughboss-growth/dist/doughboss-growth-0.1.0.zip
# browser: start the runtime without, then with, the companion and run wp01-inert.mjs capture/compare; then wp16-full.mjs (header of that file)
```

## 7. Not verified here

See section 4 of the review document. In short: no real Google, Meta, Square or mail provider was contacted; MySQL, native PHP 7.4 and 8.2 and GitHub Actions were not run; the Opus adversarial review pass was not run by this (Sonnet) package; core 2.44.0 and the Square Orders work are outside this release.

## 8. Upgrading to a later version

Upload the new zip over the old one (Replace current with uploaded). Nothing needs to be done by hand; the steps below say what the plugin does and what a release author must keep true.

What happens on upgrade:

1. The public duties carry on immediately. `storage_ready()` compares the stored schema version with `DOUGHBOSS_GROWTH_DB_MIN_COMPAT` (the oldest schema the code still runs on), not with `DOUGHBOSS_GROWTH_DB_VERSION`. Opt-out pages, exporter, eraser, purge, lead form and conversion hooks therefore keep working before anyone opens wp-admin.
2. The first manager request in wp-admin runs the repair (`maybe_upgrade()`): dbDelta brings the tables to `DOUGHBOSS_GROWTH_DB_VERSION`, the version is recorded after every table and column is confirmed, and the daily purge event is checked. A failure is listed under Recent failures on the Settings tab and retried (at most every five minutes, at once when the Growth page is opened).
3. The same request brings the settings option up to the current `settings_version` (see below).

Rules for a release that changes the schema:

- Raise `DOUGHBOSS_GROWTH_DB_VERSION` whenever a CREATE TABLE changes.
- New columns must be nullable or have a default, so older code still runs on newer tables (a rollback to the previous zip, or the window before the repair).
- If the new code works without the new column or index, leave `DOUGHBOSS_GROWTH_DB_MIN_COMPAT` at the previous value. If it cannot, raise it to the new version in the same release; the modules then wait for the repair (fail closed). It must never be above the schema version.
- Never read or write a new column from code that runs while `DOUGHBOSS_GROWTH_DB_MIN_COMPAT` is still below the version that adds it.

Settings:

- The settings option holds `settings_version` inside itself (no extra option). Saving after a rollback keeps keys and feature flags a newer release stored, so they are not lost when the newer release returns; the form can never add a key the running version does not define.
- To change what a stored value means, raise `DoughBoss_Growth_Settings::SETTINGS_VERSION` and add an idempotent step to `DoughBoss_Growth_Activator::migrate( $from )` (pattern in its comment). It runs once, on the first manager request or activation after the stored version is lower, works on the raw option, and returns true only on success; a failure is recorded and retried.
