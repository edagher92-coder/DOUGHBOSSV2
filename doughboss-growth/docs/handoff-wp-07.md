# WP-07 hand-off: corporate lead form and party-pack sizer

Status: built and verified as listed below. Everything is off by default (flags `lead_form`, `party_sizer`); with both off the public pages are unchanged (proved in a browser).

## What exists

- `includes/leads/class-doughboss-growth-leads.php` (`DoughBoss_Growth_Leads`, registry module `leads`, flags `lead_form` and `party_sizer`, `needs_storage`)
  - Shortcode `[doughboss_growth_lead_form variant="corporate|office_breakfast|events" landing=""]`. Variant maps to segment and landing key (`catering-corporate`, `catering-office-breakfast`, `catering-events`, matching the WP-06 definitions). An unknown variant renders nothing. `landing` is accepted only as a kebab-case key of 64 characters or fewer.
  - The form posts to core's `POST /doughboss/v1/catering/enquiry` with core's field names (`customer_name`, `customer_email`, `customer_phone`, `package_id`, `guest_count`, `order_type`, `location_id`, `event_date`, `address`, `notes`, `hp`) and core's `wp_rest` nonce (`X-WP-Nonce`), plus `dbgr_company`, `dbgr_segment`, `dbgr_landing_key` and, only when the box is ticked, `dbgr_consent_marketing=1` with `dbgr_consent_text_version`. The segment is a data attribute, not a visitor-editable field.
  - Marketing consent: separate, optional, UNTICKED. The wording names the sender (`sender_legal_name`) and says how to withdraw. Its version is `ld-` plus 12 hex characters of the SHA-256 of the exact wording (any change, including the sender name, is a new version). **No sender legal name (or one carrying the working name) means no box at all**: the enquiry still works and consent cannot be given.
  - The class stores nothing itself. The `lead_meta` row is written by WP-04's `on_enquiry_created` in the same hook as core's insert, so an enquiry core rejects leaves no row. `ensure_recording()` makes sure that hook is live when `lead_form` is on alone (see contract change 1). The form renders nothing if the hook cannot be wired, or storage is not ready (fail closed).
  - Package choice: the lint-clean real packages from the WP-06 reader plus "Not sure yet" (core treats 0 as a custom enquiry). Shop choice: `DoughBoss_Locations::all( true )`; if core cannot be read the choice is omitted and core assigns its default shop.
  - Admin tab "Leads": status, wording version, packages the sizer can use, the latest 10 lead records (enquiry id, segment, company, consent Yes/No, wording version, UTC time; never an email, name, phone or notes), and the [CONFIRM] gaps.
  - Action `doughboss_growth_lead_recorded( $enquiry_id, $meta )` is fired by WP-04's module (once per new row; no email or name). Not re-declared here.
- `includes/leads/class-doughboss-growth-party-sizer.php` (`DoughBoss_Growth_Party_Sizer`): shortcode `[doughboss_growth_party_sizer enquiry_url=""]`, flag `party_sizer`.
  - `packages()`: WP-06's `DoughBoss_Growth_Landing::core_packages()` (published, named, real price, lint-clean) restricted to those with a serve range, smallest first. **Prices are not given to the browser.**
  - `guidance()`: pieces-per-guest guidance only from a confirmed, sourced, lint-clean ledger claim with id `catering-pieces-per-guest`. There is no such claim in the shipped ledger, so none is shown (contract change 2).
- `public/js/dbgr-lead-form.js` (ES5): client validation (name, email shape, company when required, whole-number guests 1..1000), one request, success only after core answers `success:true`, `generate_lead { form:"catering_enquiry", category:<segment>, guest_band:"1-9|10-24|25-49|50-99|100+", store:<slug|none> }` only when core returned an enquiry number (core answers a filled honeypot with a silent success and no number: not a lead). 403 shows "refresh the page", 429 "too many", other errors show core's own message. Without a usable configuration the form is inert and the browser is stopped from posting it. Nothing is stored in the browser.
- `public/js/dbgr-party-sizer.js` (ES5): the visitor types a head count; the script picks the smallest package whose largest serve count covers it (the **next size up** is also quoted; above the largest package the largest is used and core's per-head overflow applies: never an underquote), then calls core `GET /catering/quote?package_id&guest_count&order_type=pickup` and shows the name, core's serve range and core's `total`. A quote that is missing, not a finite number above 0, or over 10,000,000 shows no price. Newer questions beat stale answers. No price literal in the script.
- `public/css/dbgr-leads.css`: `dbgr-` classes and `--dbgr-*` properties only.
- Tests: `tests/test-leads.php` (isolated-process runner) + `tests/leads-suite.php` (180 assertions), `tests/lead-form.test.js` (12), `tests/party-sizer.test.js` (12), browser script `web/scripts/wp-local/growth/wp07-leads.mjs` (62 checks).

## What was run (evidence)

| Check | Result |
| --- | --- |
| `php -l` on the 2 shipped PHP files and 2 test files | clean (PHP 8.3) |
| `php tests/run.php leads` | 180 assertions, 180 passed, 0 failed, 0 skipped, 0 foreign writes |
| `php tests/run.php` (everything) | 5243 assertions: 5237 passed, 6 failed, 1 skipped. **The 6 failures are WP-01's `test-core-lifecycle.php`** (they assert the shared schema is exactly two tables; they fail the same way without my files, as WP-04 reported). The 1 skip is WP-11's (core source path). I add no table. |
| `php scripts/php74-guard.php` | 67 files, no violations (guard run on 8.3; no real 7.4 interpreter was run) |
| `node --test tests/lead-form.test.js tests/party-sizer.test.js` | 24 pass, 0 fail, 0 skipped (includes the ES5 gate with a planted arrow function / template literal rejected) |
| `node --test` on attribution, consent, core, datalayer, tilt-cards, waitlist | 29, 22, 24, 26, 8, 11 pass, 0 fail |
| `ACORN_PATH=web/node_modules/acorn node scripts/es5-check.mjs` | 7 files, no violations |
| `build-zip.php` / `validate-zip.php` / `budgets.php` (scratch path, removed) | valid, 51 files, 220,457 bytes (22% of budget); the 5 new shipped files are in the archive |
| `web`: `npx eslint scripts/wp-local/growth/wp07-leads.mjs` | clean |
| Browser, `wp07-leads.mjs`, Playground PHP 8.2 / WP 7.1, core 2.43.2, port 9407, state `/tmp/wpl-WP-07`, freshly started runtime | 62 of 62 checks passed. Runtime stopped; nothing of mine left running. |

Negative controls and mutations:
- PHP: `dbgr_test_expect_failure` proves the product-word assertion really fails on bad copy; the lint is shown to flag the working name and a currency symbol. Mutating the checkbox to `checked`, and removing the `ensure_recording()` wiring, each made the suite fail (then restored).
- Node: mutating the sizer to `>` (underquote at a boundary) and the form to send consent without a tick each failed the suites (then restored). Eleven client-validation rejections each have a matching "valid values do send" control.

Browser checks (real core enquiry route and real core quote route, read back from core's staff REST list and the Leads tab):
- Flags off: no companion asset, form or sizer on a page that holds both shortcodes.
- Flags on: Leads tab renders; form shown with an unticked, optional marketing box naming the sender; segment `corporate`; packages are core's three priced test packages plus "Not sure yet" (draft and unpriced packages absent); no product word, no currency symbol; axe 0 violations (desktop and mobile); no mobile overflow.
- Ticked submit: request to core's route with core's nonce header and field names; core holds exactly one new enquiry (30 guests); exactly one `lead_meta` row with segment `corporate`, company, consent Yes, wording version, linked to that enquiry id, UTC time; `generate_lead` params exactly `{form:"catering_enquiry",category:"corporate",guest_band:"25-49",store:"revesby"}` with nothing personal; Leads tab shows no email.
- Unticked submit: no consent keys sent; second row with consent No and no version.
- Rejected: stale nonce (core 403, "refresh" shown), filled honeypot (core 200, empty enquiry number, no event), past event date (core 400, core's message shown): core's enquiries and the lead records unchanged (2 to 2 and 2 to 2).
- Sizer at 12, 13, 20, 26, 41 guests: suggested package covers the count (or is the largest), next size up quoted, every price equals core's own `/catering/quote` answer, every number on screen is the head count, a core serve range or a core total; no draft or unpriced package; no guidance; 1001 and 0 refused with no price; axe 0 violations (desktop and mobile result).

## Not run, and why

- **MySQL**: the harness runs on SQLite and the runtime on SQLite. This package adds no table (WP-04's `lead_meta` is the only storage) and no new SQL beyond two read-only `SELECT`s in the admin tab.
- **PHP 7.4 interpreter and the CI workflow**: no runner. The guard ran on 8.3.
- **Core 2.41.0 (baseline B) in a browser**: only 2.43.2 was used. The hook `doughboss_catering_enquiry_created`, the route and the quote route exist in both per the architecture; B was not run.
- **The real consent module plus Tag Manager with this form**: the browser run replaced `DoughBossGrowth.track` with a recorder (consent and GTM are other packages' flags), so it proves what the form sends, not that a `dataLayer` push happened. The dispatcher's own tests (WP-03) cover its allow-list: `generate_lead` params `form`, `category`, `guest_band`, `store` are in `content/events.json`.
- **A cached page**: not tested (see the nonce gap below).
- Chromium only; no Firefox or Safari. No real customer enquiry, email, payment or marketing send was made (the runtime has no outbound mail).

## [CONFIRM] gaps (also shown in Growth, Leads)

- **Sender legal name** (and ABN if shown): until set, no marketing box is shown on the form, so no marketing consent can be collected.
- **Marketing consent wording**: "I agree that [sender] may email me news and offers about catering. I can withdraw this at any time using the unsubscribe link in any message, or by contacting [sender]." Elie (and a solicitor) to approve it; changing the sender name or the wording creates a new version automatically.
- **Privacy-policy URL and text**: the form links to it only when set; the policy must describe what an enquiry stores and the `dbgr_*` fields.
- **Sending**: ticking the box records consent only. Nothing here sends marketing; whatever sends must honour it and give a working unsubscribe (Spam Act).
- **Pieces-per-guest guidance**: shown only once the ledger has a confirmed, sourced claim `catering-pieces-per-guest`. Until then the sizer shows package, serve range and price.
- **Page caching**: the form uses core's `wp_rest` nonce (frozen contract). If Crazy Domains or a cache plugin serves a cached copy of the page for longer than the nonce lifetime, a submission is refused with 403 and the visitor sees "This page has expired. Please refresh it and try again." Exclude the catering pages from full-page caching, or accept the refresh prompt.
- **Retention** of lead records is WP-04's open gap (no automatic deletion, no WordPress privacy exporter or eraser coverage).
- The guest ceiling of 1,000 mirrors core 2.43.2's `MAX_GUESTS` (read in source, not re-checked in 2.41.0).

## Contract change requests

1. WP-01 registry (`includes/class-doughboss-growth.php`, module `attribution`): add `lead_form` to `features` (this is WP-04's request 2 too). Workaround in place: `DoughBoss_Growth_Leads::ensure_recording()` loads the attribution module and calls its `init()` (idempotent, checked with `has_filter`) when `lead_form` is on alone; proved in PHP and in the browser (flags `lead_form` and `party_sizer` only, attribution off).
2. WP-02 `content/claims.json`: add an unconfirmed gap claim `catering-pieces-per-guest` so the ledger tab shows the guidance as an owner decision. Nothing changes in output until it is confirmed with a source.
3. WP-01 registry, same as WP-06's request: start the `leads` module (and register an empty shortcode) even while its flags are off. The browser run observed that with the flags off a published page that holds the shortcode prints the raw `[doughboss_growth_lead_form ...]` text. The landing page definitions embed these shortcodes, so a published page would show raw text after the feature is switched off.
4. WP-01 `tests/test-core-lifecycle.php`: the 6 hard-coded "two shared tables" assertions fail since other modules added tables (see WP-04's request 4).
5. WP-01 `unmet_requirements()`: I deliberately made `lead_form` need nothing (no sender name), because without a sender name the form simply omits the consent box. If Elie prefers the form to require a sender name before it can be enabled, add `lead_form_requires_sender_legal_name` there.
6. Files I added beyond the work-breakdown list: `tests/leads-suite.php` (the suite body run by `test-leads.php`; the same split WP-04 used so the real attribution module is not loaded into the shared process) and the "Leads" admin tab (inside the owned class). The `landing` and `enquiry_url` shortcode attributes are mine.
