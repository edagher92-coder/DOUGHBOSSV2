# 07 Adversarial review: `doughboss-growth` companion plugin

Independent, refute-oriented review of `/home/user/DOUGHBOSSV2/doughboss-growth/` (WP-01 to WP-08 and WP-11), done on 2026-10-02 for the WP-16 review gate. The reviewer did not write the code under review. Every finding below was reproduced through real code paths using the test suite's fake WordPress harness (in-memory SQLite with the module's real `CREATE TABLE` statements; fake mail and HTTP transports; no outbound request). Nothing was deployed and git was not run.

**Bottom line.** Two high-severity defects broke the plugin's own promises once a feature was switched off: opt-out links, the privacy exporter and eraser, and retention all stopped working, and published companion pages showed raw shortcode text. Both are fixed, along with four medium issues. Each fix has a regression test, and each test was shown to fail when its fix was reverted. Eleven findings remain open. None of them lets an unsourced claim, a pre-consent pixel or raw personal data reach a third party by default. Four need an owner or legal decision (O1, O2, O4, O5).

Status labels: **FIXED** (changed in this review and regression-tested), **FIXED-BY-INTEGRATION** (fixed by the concurrent WP-16 integration agent and verified in the tree), **OPEN** (reported, not changed).

## Findings by severity

| # | Severity | Property | Status | Finding |
| --- | --- | --- | --- | --- |
| F1 | High | (f) unsubscribe and erasure | FIXED | When the waitlist flag is off, opt-out links, the exporter, the eraser and the retention purge all stop working |
| F2 | High | (e) flag-off | FIXED | Every companion shortcode prints as raw text on a published page when its feature is off |
| F3 | Medium | (e) deactivation | FIXED | Deactivation leaves the hand-made coming-soon page showing raw `[doughboss_growth_coming_soon]` |
| F4 | Medium | (a) claims | FIXED (residual O6) | Admin teaser text can state a date, price, place, product, size or dietary claim in words |
| F5 | Medium | (c) personal data retention | FIXED | Limiter buckets keyed on `sha256(email)` are never purged, so they outlive erasure |
| F6 | Medium | (f) spam limits | FIXED | Rotating IPv6 addresses bypasses the per-address sign-up limit |
| F7 | Low | (d)/(e) uninstall | FIXED-BY-INTEGRATION | A data-deleting uninstall missed the option `doughboss_growth_coming_soon` |
| O1 | Medium | (b) consent | OPEN, owner/legal | The Tag Manager container loads before any consent choice (by design) |
| O2 | Medium | (c)/(f) erasure | OPEN | Lead and attribution records are covered by no exporter or eraser |
| O3 | Medium | (e) flag-off | OPEN | Switching `landing_pages` off leaves published pages live, with an empty body, in the sitemap |
| O4 | Medium | (f) unsubscribe | OPEN, owner | Kill switch, missing core or deactivation silently disables opt-out links |
| O5 | Low | (c) personal data | OPEN | An email or phone number in a UTM value is stored verbatim and shown in admin |
| O6 | Low | (a) claims | OPEN | The teaser deny-list cannot be complete |
| O7 | Low | (f) spam limits | OPEN, by design | Plus-addressing gets around the per-email limit; 3 addresses can trip the daily breaker |
| O8 | Low | (f) enumeration | OPEN (WP-05 known) | Response timing differs between new or pending addresses and others |
| O9 | Low | (h) pay decision | OPEN | The reconciliation CSV omits the run status and any "evidence only" marker |
| O10 | Low | (b) consent | OPEN | Withdrawing consent does not remove analytics cookies already set |
| O11 | Info | hero decision | OPEN, owner | The optional ribbon is appended after core's home hero |
| O12 | Info | (f) consent | OPEN | Lead-form marketing consent has no withdrawal route of its own |

## Fixed findings (evidence, fix, regression)

### F1 (High): with the waitlist off, people cannot leave and their data cannot be exported, erased or purged

- **Reproduction.** With every flag off, schema installed, front end: `DoughBoss_Growth::init(); do_action('rest_api_init')`.
  - Before the fix, the only route registered was `/doughboss-growth/v1/health`.
  - `template_redirect` had no `maybe_handle_link`, so the email-link opt-out page was dead.
  - `wp_privacy_personal_data_exporters` and `wp_privacy_personal_data_erasers` were absent.
  - `doughboss_growth_retention_purge` had no callback, so a scheduled purge did nothing.
- **Cause.** The registry ran `DoughBoss_Growth_Waitlist::init()` only while the `waitlist` flag was on. The class itself already supports flag-off mode, but nothing called it. Existing tests called `init()` directly, so the registry path was never tested. This was WP-05's contract change request 1 ("REQUIRED before the waitlist is enabled"), and it had not been applied.
- **Why it matters.**
  - Spam Act 2003 requires a working unsubscribe for at least 30 days after a message.
  - The `List-Unsubscribe-Post` one-click POST returns 200 from the home page, so a mail client reports success while nothing happens.
  - Erasure requests through Tools could not reach the list.
  - Pending and opted-out rows were never purged.
- **Fix.** Registry entry `waitlist` gets `'always' => true` (WP-05's browser-proven patch), and `module_wanted()` honours it (`includes/class-doughboss-growth.php`). With the flag off, init registers only:
  - the opt-out route and page;
  - the exporter and eraser;
  - the purge callback;
  - the staff CSV export;
  - empty shortcode stubs.

  It registers no sign-up, form-token or confirm route, schedules nothing and enqueues nothing (asserted).
- **Regression.** `tests/test-wp16-adversarial.php` R1. A real opt-out with a link token issued while the list was on succeeds with the flag off. A wrong token returns 400 (negative control). Export and erase work. Reverting the fix fails 13 assertions.

### F2 (High): raw shortcode text on published pages when a feature is off

- **Reproduction.** Flags off, schema installed, front end: `do_shortcode('[doughboss_growth_coming_soon]')` returned the tag text. So did all five tags (`landing`, `coming_soon`, `waitlist`, `lead_form`, `party_sizer`).
- **Cause.** Each module registers its "never print the raw tag" stub in its own `init()`, and that runs only while the module is loaded (flag on, or wp-admin). The documented rollback ("flag off is immediate") therefore published raw tags on any page the owner had published.
- **Fix.** `DoughBoss_Growth::register_shortcode_stubs()` runs after module initialisation. Each frozen companion tag that no module registered renders `''`. This applies only once the companion has a footprint (schema installed, or recorded pages), so a fresh install stays strictly inert and WP-01's unit inert test is unchanged. Real handlers are never replaced (asserted).
- **Regression.** R2: all five tags render nothing; a foreign `[gallery]` tag is untouched (control); a fresh install registers nothing. Reverting the fix fails 7 assertions.

### F3 (Medium): deactivation leaves the coming-soon page showing raw text

- **Cause.** The deactivation hook drafts only pages listed in `doughboss_growth_pages`. WP-05 ships no create button for the coming-soon page; the admin tab tells the owner to make it by hand, so it is never recorded. WP-01's own test expects a `coming-soon` entry that no code writes.
- **Fix.** `DoughBoss_Growth_Activator::draft_companion_pages()` also considers the page at the configured `coming_soon_page_slug`. The existing rule still applies: the page is drafted only when its whole content is exactly one companion shortcode, and only `post_status` is written.
- **Regression.** R3: the page at the slug is drafted. An edited page is left alone (control). A custom slug is honoured and the default slug is then left alone. Reverting the fix fails 4 assertions.

### F4 (Medium): teaser claims in words got past the shared lint

- **Reproduction.** Using the lint that shipped (`DoughBoss_Growth_Ledger::lint_public($text, null)`), each of these returned `[]` and would have rendered on the public coming-soon section and the home ribbon:
  - "Mini pizza packs coming to Revesby this Friday"
  - "Vegetarian treats for five dollars"
  - "Mini’s are coming"
  - "Mіnis are coming" (Cyrillic і)

  That lint blocks only digits, currency, `%`, the working name and seven words.
- **Fix.** `DoughBoss_Growth_Coming_Soon::teaser_violations()` is a teaser-only check in `copy()`. It refuses, in words:
  - dates: weekdays, months, today, tomorrow, tonight, weekend;
  - prices and numbers: dollars, cents, price, cost, cheap, free, discount, sale, special, number words, half, dozen;
  - shop places: Revesby, Bankstown, Roselands, Sydney;
  - product, size and pack words: words starting `mini`, pizza, manoush, manakish, pie, pastry, bite, pack, platter, tray, box, size, small, large, flavour;
  - dietary words, including Arabic حلال;
  - Cyrillic and Greek look-alike letters.

  Invisible characters are stripped and NFKC folding is applied first, as the shared lint does. A false positive only restores the neutral default (fail closed). The shared lint is unchanged, so claims and landing pages behave exactly as before.
- **Regression.** R4 runs 14 refused inputs through the accessors and through the rendered section and ribbon, and keeps 4 neutral lines (control). Reverting the fix fails 29 assertions. The residual is O6.

### F5 (Medium): email-hash rate buckets were never purged

- **Cause.** `DoughBoss_Growth_Rate_Limit::purge_expired()` had no caller. The per-email bucket key is `wl:email:<sha256 of the address>`. That is an unsalted hash, so a known address can be confirmed by hashing it. The bucket stayed in `doughboss_growth_rate` indefinitely, including after an erasure.
- **Fix.** The daily `DoughBoss_Growth_Waitlist::purge()` now calls `purge_expired()`, which drops buckets whose window ended more than a day ago. The return shape is unchanged.
- **Regression.** R5: a stale bucket is removed and the current one kept (control). Every statement is prepared. Reverting the fix fails 1 assertion.

### F6 (Medium): IPv6 rotation bypassed the 5-per-hour limit

- **Cause.** `hash_ip()` hashed the full address. An IPv6 visitor controls at least a /64 (privacy addresses rotate on their own), so every new address got a fresh allowance.
- **Fix.** `DoughBoss_Growth_Rate_Limit::bucket_address()` buckets IPv6 by its /64. An IPv4-mapped IPv6 address counts as its IPv4 address; without that, every such visitor would share one zero /64. IPv4 behaviour is unchanged.
- **Regression.** R6: one /64 is one bucket; another /64 is separate (control); IPv4-mapped equals IPv4; two mapped visitors stay apart. End to end, 8 sign-up attempts across rotating addresses in one /64 get exactly 5. Reverting each branch fails 3 and 2 assertions.

### F7 (Low): uninstall missed `doughboss_growth_coming_soon`

This was WP-05 contract change request 2. During this review the WP-16 integration agent added the option to `DoughBoss_Growth_Activator::OPTIONS`. The name passes the uninstall plan's prefix validation, and the full suite passes.

## Open findings

- **O1 (Medium, owner and legal decision): the Tag Manager container loads before consent.**
  - With `consent_banner` and `gtm` on, `gtm.js` is requested on every page view before the visitor chooses, with all four Consent Mode signals denied. That is the architecture's chosen pattern (`00 §3.5`, WP-03 acceptance "GTM request made but no collect request before Accept"), and the noscript iframe is correctly omitted.
  - The container request itself sends the visitor's IP address and browser details to Google.
  - Whether anything else fires before consent depends on the container, which the plugin cannot see: Google tags in advanced consent mode send cookieless pings, and a Meta Pixel without a consent trigger would fire. The admin tab carries this as a [CONFIRM] gap.
  - **Recommendation:** add a setting that injects `gtm.js` only after a granted choice (basic mode, the natural default for `deny`), or make a container audit a hard gate in the release runbook before `gtm` is switched on.
- **O2 (Medium): lead and attribution records have no exporter or eraser.**
  - The records are:
    - `doughboss_growth_lead_meta`: company name, marketing consent and its time;
    - `doughboss_growth_attribution`: click ids and UTM values keyed to a core enquiry or order.
  - Only the waitlist registers privacy tooling. Core's eraser does not touch companion tables, so an erasure request leaves these rows orphaned.
  - **Recommendation:** an exporter and eraser that resolve the requester's email to core enquiry and order ids through core's read API (WP-04/WP-07 change).
- **O3 (Medium): switching `landing_pages` off is not a byte-identical rollback.**
  - Published landing pages stay live, with their title, an empty body (after F2) and a sitemap entry. Before F2 they showed raw shortcode text.
  - WP-16's acceptance "every flag individually reversible to a byte-identical public page" is therefore not met for published pages. The same applies to a published coming-soon page while `coming_soon` is off.
  - **Recommendation:** a runbook step (draft the pages before switching the flag off), or code that drafts recorded pages when `landing_pages` is saved off (WP-06 change).
- **O4 (Medium, owner): opt-out links can silently fail.**
  - Opt-out links stop working whenever the companion is not running:
    - the kill switch `DOUGHBOSS_GROWTH_DISABLE` is set;
    - DoughBoss core is missing or older than 2.41.0 (the core gate);
    - the plugin is deactivated.
  - In every case the one-click POST still receives 200 from the home page.
  - Under the kill switch and the core gate, published companion pages also show raw tags again. The core gate allows only an admin notice, so stubs were deliberately not added there.
  - **Recommendation (runbook):** within 30 days of any list email, do not deactivate or kill-switch without another working opt-out channel (for example a monitored reply address named in the email).
- **O5 (Low): personal data in UTM values is stored and shown in admin.**
  - Reproduction: `parse_cookie({"utmSource":"jane.citizen@example.com","utmTerm":"0412 345 678"})` keeps both values. They are stored in `attribution_json` and shown on the admin Attribution tab.
  - Server conversions refuse such payloads (`payload_has_pii`) and the dataLayer drops them, so nothing reaches a vendor.
  - **Recommendation:** reject `@` and digit runs of nine or more in both the TypeScript schema and the PHP sanitiser, then regenerate the oracle (WP-04).
- **O6 (Low): the teaser deny-list (F4) cannot be complete.**
  - Ingredient and product nouns are unbounded, for example "Cheese and thyme treats are coming".
  - **Recommendation:** replace free text with a short list of owner-approved neutral lines (a select field), and keep the deny-list as a second check.
- **O7 (Low, by design): spam limits can be stretched.**
  - The per-email limit (3 a day) keys on the exact address, so `name+1@`, `name+2@` and so on multiply confirmation emails to one inbox, up to the 300-a-day breaker.
  - Three IPv4 addresses (5 an hour, about 120 a day each) can trip the daily breaker and close sign-ups until the next UTC day. The breaker is fail-closed by design.
  - **Recommendation:** strip `+tags` for the limiter key only, and add a challenge (for example Turnstile) if abuse is seen.
- **O8 (Low, WP-05 known): timing side channel.** New and pending addresses send email inline, so their responses are slower than those for confirmed or suppressed addresses. Not mitigated.
- **O9 (Low, property h): the reconciliation CSV can be mistaken for payroll input.**
  - The screen states "Evidence only ... does not calculate pay". The module writes no core or pay data (WP-11 asserts zero core writes), ships no tolerance values (unset gives `UNRATED_*`), and never shows FAILED or NOT_RUN runs as clean.
  - The downloaded CSV, though, has per-staff, per-day net minutes and no run status (COMPLETE or INCOMPLETE) or "evidence only" marker. Once it leaves the screen it looks like a payroll import file.
  - **Recommendation:** add a `run_status` column and an evidence-only file name (a WP-11 CSV contract change).
- **O10 (Low): withdrawal leaves cookies behind.** Withdrawing consent switches Consent Mode to denied and deletes `dbgr_attr`, but cookies GA set while consent was granted (`_ga` and similar) remain. **Recommendation:** clear known first-party analytics cookies on Reject, or state this in the privacy policy.
- **O11 (Info, owner): ribbon placement.** The companion contains no hero code: no `includes/hero`, no `hero_enhanced` flag (11 flags), and no `doughboss-growth-media` plugin, as the hero decision requires. The optional coming-soon ribbon (off by default, separate switch) is appended after core's home hero output through `do_shortcode_tag`, without changing the hero. Elie may want to confirm that placement is acceptable.
- **O12 (Info): lead-form consent has no withdrawal route.** Marketing consent recorded by the lead form has no opt-out of its own. The plugin sends no marketing, so any later use of these leads needs its own unsubscribe facility.

## Attempts that failed to break a property (evidence)

- **(a) Unsourced or forbidden claims.**
  - A case-insensitive grep for the working name over every shipped file type finds only the settings sanitiser's regex.
  - `content/claims.json` holds 7 claims, all `confirmed:false`.
  - Landing blocks are omitted unless their claim is published.
  - The JSON-LD validator forbids `geo`, `sameAs`, ratings, `priceRange`, `hasMenu` and similar.
  - Package prices come from core meta only. The core catering form is hidden while its own copy fails the lint.
  - Event names in `content/events.json` are neutral. The confirmation email names only the sender.
  - Customer-facing strings were scanned for implicit claims (lead times, "fresh", "best", "free", delivery): none found.
- **(b) Trackers before consent.**
  - Nothing prints unless `consent_banner` AND `gtm` are effectively on, the container id is valid and `events.json` is valid.
  - The `dbgr_consent` cookie is written only on an explicit choice. Escape before a choice does nothing.
  - `dbgr_attr` is written only with the matching consent category.
  - The dataLayer dispatcher drops events without consent and refuses unknown names.
  - Core's `fbq`/`ttq` calls are disabled through `doughboss_marketing_config`, verified against core's `doughboss-marketing.js:93-103`.
  - Server conversions check the consent stored with each subject, per channel.
- **(c) Personal data in payloads, logs and errors.**
  - The outbox rejects personal-data keys and email-shaped values at enqueue, and handlers re-validate before sending.
  - Hashed identifiers are allowed only for Meta, only with the setting on and only with advertising consent.
  - `DoughBoss_Growth_Http` never logs bodies and drops query strings (the GA4 `api_secret`) from logged URLs. Outbox `last_error` holds an error code only.
  - Every `Http::log()` context key across the modules is a code, stage, channel or id.
- **(d) Writes to core.**
  - The harness's foreign-write detector reports 0 foreign writes over the whole suite.
  - The only writes outside companion tables and options are WordPress page posts: landing drafts created by the admin button, and status-only drafting on deactivation.
  - The reconciliation reader issues SELECTs only.
- **(f) Waitlist.**
  - The consent box is unticked. The server accepts only exactly `1` with the current wording version.
  - The honeypot returns the identical success body and stores nothing.
  - Confirm and opt-out change state only on POST. Tokens are a 160-bit single-use hash (confirm) and an HMAC (opt-out).
  - New, pending, confirmed, opted-out and suppressed addresses get the same answer.
  - The suppression list is honoured after erasure.
- **(g) Secrets.**
  - Secrets come from the environment or wp-config only; admin and `/health` show presence only.
  - A repository scan for Meta, Square, Stripe and AWS key shapes and PEM blocks found nothing.
  - The Square labour token is used only in the `Authorization` header (redacted by name), and `unset()` after the request. Square errors return codes only, never response bodies.
  - The zip build is an allow-list, so `tests/` and `docs/` do not ship.
- **(h) Pay decisions.** See O9; the property holds on screen and in storage.

## What was run

| Check | Result |
| --- | --- |
| `php tests/run.php` (PHP 8.3.6), before any change | 5245 passed, 0 failed, 1 skipped (core-source parity; needs `DBGR_CORE_SRC`), 0 foreign writes |
| `php tests/run.php` after the fixes, with the concurrent integration edits (first run) | 5331 passed, 0 failed, 1 skipped, 0 foreign writes |
| Same, final run (the integration agent had added `DoughBoss_Growth_Ledger::fold_compat()` and routed the F4 guard through it) | 5355 passed, 0 failed, 1 skipped, 0 foreign writes |
| `php tests/run.php wp16` (new regression file) | 86 passed, 0 failed |
| Mutation check: each fix reverted in a scratch copy, `run.php wp16` | F1 13 failed, F2 7, F3 4, F4 29, F5 1, F6 3, F6 IPv4-mapped branch 2 |
| `php -l` on every changed PHP file (8.3) | clean |
| `php -l` on the changed files under real PHP 7.4.33 (Playground WASM) | 6 files, 0 failures |
| `php scripts/php74-guard.php` | 68 files, no violations |
| `node --test tests/*.test.js` | 144 passed, 0 failed |
| `node scripts/es5-check.mjs` | 7 files, no violations (no JS changed) |

Not run:

- the full suite under PHP 7.4 (the WASM CLI hung on `tests/run.php` and was stopped by its timeout);
- a browser runtime check (WP-05 had already browser-proven the same registry patch, 97 of 97; four CPUs were shared with another live runtime and the integration agent's PHP 7.4 runs);
- MySQL (SQLite harness only);
- a real Tag Manager container audit (needs the owner's container).

## Files changed by this review

- `doughboss-growth/includes/class-doughboss-growth.php`: the `always` registry key and the shortcode stubs (F1, F2).
- `doughboss-growth/includes/class-doughboss-growth-activator.php`: coming-soon page drafting (F3).
- `doughboss-growth/includes/waitlist/class-doughboss-growth-coming-soon.php`: the teaser guard (F4).
- `doughboss-growth/includes/waitlist/class-doughboss-growth-waitlist.php`: the limiter purge call (F5).
- `doughboss-growth/includes/class-doughboss-growth-rate-limit.php`: IPv6 /64 buckets (F6).
- `doughboss-growth/tests/test-wp16-adversarial.php`: new regression tests R1 to R6.
- `doughboss-growth/docs/handoff-wp-16-review.md`: hand-off note.

No frozen name changed. The fixes add one registry key (`always`), one class constant (`DoughBoss_Growth::SHORTCODES`, which lists the frozen tags) and new public static helpers.

**Concurrency note.** While this review ran, the WP-16 integration agent edited some of the same files:

- it added `lead_form` to the attribution module's features;
- it added `doughboss_growth_coming_soon` to `OPTIONS`;
- it replaced the direct `Normalizer` call in the F4 guard with the new ledger helper `fold_compat()`. That helper fails closed when intl is missing.

Both sets of changes coexist, and the final full-suite run above includes them.
