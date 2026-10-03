# 06 Growth release review: doughboss-growth 0.1.0 release candidate

Package WP-16, 2026-10-02. Companion to `05-work-breakdown.md` (cited as `05 §n`) and `doughboss-growth/docs/RELEASE-0.1.0.md` (the rollout runbook). Written by the integration package (Sonnet, high effort). **This document says what was verified, what was not, and why; it does not authorise any install, flag, provider call or merge.** Those stay Elie's decisions.

Scope after the hero decision (`docs/site/hero-decision.md`): eleven flags, one code zip, no hero, no media zip, WP-09 and WP-10 cancelled.

## 1. Verdict

**The package is a sound release candidate to install with every flag off, and not yet safe to enable all features.** Everything that could be run here passed on the final tree. Installing it with all flags off changes nothing a visitor can see (byte-identical public HTML on core 2.43.2 and on the live-line 2.41.0, and on a PHP 7.4 runtime). Each flag is individually reversible. No unsourced claim, early tracking request, personal data in a conversion payload or core-table write was found.

Four items must be settled by Elie, or by a follow-up package, before the matching feature is enabled on the live site (details in sections 3 to 5):

1. **F-1 (privacy):** attribution and lead records have no retention rule and no WordPress privacy exporter or eraser. Decide before enabling `attribution` or `lead_form`.
2. **F-2 (live site today, not caused by this work):** core's own `/catering/` page, on both 2.41.0 and 2.43.2, already contains the unannounced product's working name in its "how it works" copy. It is visible now, with every companion flag off. This breaks `teaser-direction.md` and needs a core copy fix (core 2.44.0 or a hot-fix), independent of this release.
3. **F-3:** the GA4 lead is sent by both the Tag Manager tag and the server; choose one source before `server_conversions` and `gtm` are both on.
4. **The independent Opus adversarial pass required by `05` WP-16 was not run** (this package runs as Sonnet). My own refute-oriented pass (section 5) found the defects listed in section 3 and nothing further, but it is not independent. The lead should dispatch the Opus pass before the enable steps in the runbook.

## 2. What was verified

Full table with commands, counts and hashes: `doughboss-growth/docs/RELEASE-0.1.0.md` section 6; raw results in `doughboss-growth/docs/evidence-wp16/`. Summary:

| Acceptance item (`05` WP-16) | Result | Evidence |
| --- | --- | --- |
| Whole suite, PHP 8.3 | Pass: 5,602 assertions, 0 failed, 0 foreign writes | `php tests/run.php` with the core source set to 2.43.2 and to 2.41.0 |
| Whole suite, PHP 7.4 | Pass: 5,336 assertions, 0 failed, 25 skipped (need a sub-process) | Real PHP 7.4.33 WebAssembly build; also a 7.4 runtime smoke |
| `php -l`, PHP 7.4 guard, node tests, ES5 gate | Pass: 70/70 files, 144 node tests, 7 JS files | Negative controls: planted arrow function fails the gate |
| Zip built and validated, under 1.0 MB | Pass: 225,504 bytes (23%), reproducible, tamper test fails validation | There is no media zip and no 1.9 MB budget (hero cancelled) |
| Inert test passes on B and C | Pass, both | Four pages byte-identical with and without the companion; hashes in RELEASE section 6 |
| Every flag individually reversible to a byte-identical page | Pass on both cores | `landing_pages` and `seo_head` after the six pages are set back to Draft (the flag alone leaves published pages empty: F-4) |
| All flags together, no first-party console errors | Pass | 185 checks on C and 185 on B |
| Screenshots desktop and Pixel 7 of `/`, coming-soon, six landing pages; axe | Pass: taken; no axe violation in companion markup | Core and theme violations noted, not caused here |
| Contract change requests resolved | See section 6 | |
| Adversarial review | **Partly:** own pass done, independent Opus pass NOT run | Section 5 |

Notes on how to read the browser results. The throwaway site is a local WordPress Playground (PHP 8.2, WordPress 7.1, SQLite). Every non-local request the browser tried was aborted and counted, so nothing left the machine; with all flags on and no consent the only tracking request was the Tag Manager loader (with a dummy container id). The sender name, privacy URL, container id and webhook URL used are placeholders that exist only on that site. Two fake-address shops and two test packages were added by a scratch file in the runtime's copy of the plugin (never in the source tree) because the seed has only one shop.

## 3. Integration defects found, and what was done

### Fixed in this package (each re-verified by the suite or the browser run)

| # | Defect | Fix |
| --- | --- | --- |
| D1 | 6 failing assertions in WP-01 `test-core-lifecycle.php`: it hard-coded two schemas and failed once other modules shipped tables | Expected count now comes from `DoughBoss_Growth::schemas()` |
| D2 | `DOUGHBOSS_GROWTH_DB_VERSION` was still 1.0.0 although seven tables were added, so `storage_ready()` would stay true on an upgraded site missing them | Raised to 1.1.0; a test pins that a stored 1.0.0 is not ready (the self-heal then creates the tables) |
| D3 | **Real defect, found only on PHP 7.4 without ext-intl:** the public-copy lint and the coming-soon teaser lint relied on `Normalizer`; without intl, full-width letters (for example a full-width spelling of the working name) passed both | One shared `DoughBoss_Growth_Ledger::fold_compat()`: NFKC when intl exists, otherwise a by-hand full-width fold and fail-closed refusal of the other compatibility alphabets. Tests with negative controls. Hosts without intl are therefore now safe |
| D4 | The shipped settings file contained the working name as a literal in its teaser check (the ledger already spelled it in two parts "so no shipped file contains it") | Split the literal; the zip now contains the word nowhere |
| D5 | Two tests failed on PHP 7.4 only because they needed a sub-process or depended on test order (WP-03 "module not loaded", WP-02 side-effect probe) | Guarded with the existing sub-process probe; the meaningful assertions (no hook added) are unchanged |
| D6 | The coming-soon ribbon option `doughboss_growth_coming_soon` was not in the uninstall option list (a delete-data uninstall would have left it) | Added to `DoughBoss_Growth_Activator::OPTIONS`; lifecycle tests extended |
| D7 | Lead-form-only sites: the attribution module was not initialised by the registry (WP-07 worked around it in its own class) | `lead_form` added to the attribution registry features |
| D8 | `seo_head` could be saved on without `landing_pages` (inert at runtime but misleading) | Dependency rule, admin wording and a test with a negative control. The same rule for `attribution` needing `consent_banner` was tried and **reverted**: it breaks the WP-08 conversion flows that legitimately run attribution without the banner |
| D9 | Claim `catering-pieces-per-guest` (used by the sizer) was not in the ledger, so the owner decision was invisible in the Claims tab | Added as an unconfirmed gap (no invented text) |
| D10 | `wp01-inert.mjs` asserted that no module is active with all flags off, which became false by design when the waitlist module became "always" (opt-out, export, erasure) | Assertion updated to allow exactly that module |
| D11 | New: nothing pinned the cross-package properties. Added `tests/test-integration.php` (144 assertions): the exact hook footprint with all flags off, no post type, role, capability or user creation anywhere, posts written only by the two owning files, SQL writes only through companion table accessors, a capability and a nonce on every admin-post handler, the only public REST routes are the four waitlist routes. Each has a negative control |

### Observed, not fixed here (owner or other package)

| # | Finding | Severity | Owner and action |
| --- | --- | --- | --- |
| F-1 | **Attribution and lead records have no automatic retention and are not covered by the WordPress personal-data exporter and eraser** (WP-04, WP-07). They hold source data, a consent record and company name, keyed to enquiry and order ids | High for enabling; none while off | Elie decides a retention period (accountant or solicitor); WP-04 follow-up to add retention and an exporter and eraser if required. Runbook gates `attribution` and `lead_form` on it |
| F-2 | Core's own `/catering/` page contains the working name once ("We will help balance ..." step 2), on the baseline 2.41.0 and the candidate 2.43.2, with every companion flag off | High (live, binding teaser rule) | Core copy fix (WP-12 / hot-fix), not the companion. The companion hides the core form on landing pages for this reason |
| F-3 | GA4 `generate_lead` is sent by the Tag Manager tag and by the server, so a lead counts twice; Meta de-duplicates only with a shared event id the browser does not send | Medium (data quality) | Owner picks one source. Left as a documented gate rather than silently changing WP-08's behaviour |
| F-4 | Switching `landing_pages` off leaves published landing pages online but empty (the shortcode renders nothing) | Medium | Runbook rollback says to set the pages to Draft. A package could force a 404 or draft automatically, but that widens what the plugin writes |
| F-5 | Deactivating drafts the six landing pages and the coming-soon-slug page, but any other owner page with a companion shortcode shows the raw tag | Low (owner-made pages) | Documented; observed in the browser run on the form and waitlist test pages |
| F-6 | Waitlist: a new or pending address sends mail inline, so response time can differ from an already-confirmed one (message and status are identical) | Low | Not mitigated (WP-05) |
| F-7 | `attribution` collects nothing without the consent banner; the settings page does not enforce that dependency (D8) | Low | The Attribution tab shows it as a [CONFIRM] gap |
| F-8 | The "Consent and tags" admin tab exists only while `consent_banner` is on | Low | `admin_always` left false: initialising the module in admin while off would add hooks to an "off" site. The Settings tab lists the Tag Manager id gap regardless |
| F-9 | Outbox rows (sent, failed) have no retention rule | Low | WP-01 follow-up |
| F-10 | The four public waitlist REST routes (`permission_callback` `__return_true`) are the only unauthenticated routes; each is guarded by token age, honeypot, rate limit and validation | Informational | Pinned by the integration test; the 5-per-hour limit uses `REMOTE_ADDR` and is shared if the host proxies everyone through one address |
| F-11 | The consent default is `deny`; `opt_out` is legally Elie's call. Events are dropped, not queued, until consent (stricter than Consent Mode "advanced") | Informational | the runbook, section 4 |

## 4. What was not verified, and why

| Not verified | Why | Where it should be closed |
| --- | --- | --- |
| The independent Opus adversarial review | This package runs as Sonnet; `05` asks for an independent model | The lead, before enabling anything |
| Any real Google, Meta, Square or mail provider | No credentials, no outbound requests allowed, and none are Elie-approved. Payloads are proved against hand-written fixtures and fake transports | A staged first send per provider after the owner gates |
| MySQL (limiter, outbox claim, UNIQUE keys, real `dbDelta`, affected-row counts); `tests/integration/recon-mysql.php` | No MySQL here; everything ran on SQLite with the real DDL | A disposable MySQL site (WP-15 style job) |
| Native PHP 7.4 and 8.2 CLI, Node 20, GitHub Actions `growth-ci.yml` | 7.4 ran as a WebAssembly build (8.3 native; 8.2 runtime via Playground); Node 22 used; no runner | CI |
| A real inbox, SPF/DKIM/DMARC, spam scoring for the waitlist confirmation | The runtime cannot send mail; mail was captured in WP-05's scratch run | A staged send on the live host |
| Real Yoast or Rank Math | Only a fake `WPSEO_VERSION` constant was tested (WP-06) | After install |
| The live site, Crazy Domains hosting, page caching, WP-Cron behaviour, real proxy address | Out of reach and out of scope | Runbook section 2 |
| A paid order or paid catering leg, a Stripe checkout redirect | Payments are off on the local runtime; covered by PHP tests only | Core track (WP-13 to WP-15) |
| Browsers other than Chromium; a full WCAG audit | axe is automated and finds only part of the issues | A manual accessibility pass on the live pages |
| Core 2.44.0 and Square Orders | Separate packages (WP-12 to WP-15), reviewed separately | |
| The hero, 3D, media zip | Cancelled by the owner | |
| Higgsfield hero reel and regenerated photos (the user's original message) | Not part of this package. No Higgsfield call was made, no credits spent. The hero decision cancels a website hero; a social reel is a separate marketing task | The lead (task 21 in its list) |
| The recon "reader parity" against core source | Ran with `DBGR_CORE_SRC` on both 2.43.2 and 2.41.0 in the final suite (not skipped); listed here only because it needs the core source path | |

## 5. Adversarial review

Own refute-oriented pass by the integration package. **It is not the independent Opus pass `05` requires; that pass was not run.** I tried to break each property directly.

| Attack | What I tried | Result |
| --- | --- | --- |
| (a) Render an unsourced claim | Read every landing definition (blocks reference claim ids or core-data slots only; static copy is limited to page titles and a description); ledger fixtures cover unconfirmed, no-source and placeholder claims; teaser text with a digit, price, dietary word, location, date or the working name falls back to the neutral default; **found D3**: full-width spellings bypassed both lints on a PHP without intl | Fixed (D3). All seven catering claims are unconfirmed gaps, so catering pages render no claim content and are `noindex`. Browser: `grep -i` for the working name finds nothing the companion adds on any page, flag by flag and all together |
| (b) Fire a pixel before consent | Browser, all flags on, fresh visitor, desktop and mobile, every page: the only tracking request is the Tag Manager loader (aborted locally); no `collect`, Meta or Ads request; no `<noscript>` iframe; Consent Mode defaults are all `denied`; node tests refuse events before consent | Nothing fires before consent from the companion. The container's own tags are the owner's responsibility (runbook) |
| (c) Store personal data in a conversion payload | Read the payload builders and the outbox guard; 680 conversion assertions include a privacy test with `send_hashed_identifiers` off (no name, email, phone, address or notes, hashed or plain), a consent-gated hashing test, and a filter-injection test; a mutation check in WP-08 killed 23 of 23 mutants | None found. Related but separate: F-1 (attribution and lead rows) |
| (d) Write to a core table | Static: no literal core table in any write statement, every table variable resolves to a companion accessor, no post type, role, capability or user creation, posts only by two files (integration test). Dynamic: the harness records foreign writes (0 over 5,602 assertions); in the browser run the 26 core tables and all core options and roles were compared before and after: only the two tables the scratch seed deliberately wrote changed | None found. The one permitted core write, the status of its own landing and coming-soon pages, is the documented exception |
| (e) Leave raw shortcode text after deactivation | Browser: deactivated the plugin with pages published | The six landing pages and the coming-soon page go to Draft. **An owner page with a different companion shortcode (form, waitlist) shows the raw tag** while deactivated (F-5), documented. With a flag merely switched off no page shows a raw tag (the registry registers empty stubs) |
| Extra: admin-post handlers and REST routes | Static scan: every one of nine handlers checks a capability and a nonce; only four waitlist routes are public | None found |
| Extra: kill switch, uninstall | Covered by WP-01 tests (sub-process); not re-run in a browser | |
| Extra: raw SQL | `$wpdb->prepare` on every variable query (reviewed by grep of every write site and by the suite's SQL shape tests) | None found |

## 6. Cross-package contract requests: disposition

| From | Request | Disposition |
| --- | --- | --- |
| WP-01 | Docs 00 and 05 still say 12 flags, a media plugin, WP-09/10 | Not mine to edit; banner supersedes. Doc owner should correct 00 section 3.9 and 4.1 and the WP-01 text |
| WP-01 | Recon entry class is `DoughBoss_Growth_Recon_Admin` | Accepted as is |
| WP-01, WP-05, WP-11 | Raise `DOUGHBOSS_GROWTH_DB_VERSION` | **Done** (1.1.0, D2) |
| WP-01 | After core PR #68 merges, core CI will run `core.test.js` without acorn and skip its acorn tests visibly | Noted; passes in `growth-ci.yml` |
| WP-02 | Two WP-01 tests fail on `mkdir( content )` | Already guarded: not failing |
| WP-02 | Lint rejects dietary words even for owner-confirmed claims | Owner decision, left as the literal reading of the architecture (runbook) |
| WP-03 | Docs `tracking.md` and `conversion-plan.md` say `STRIPE / PAY_AT_PICKUP` | Doc owner (web/marketing); not touched |
| WP-03 | Whitelist `DoughBoss_Growth_Tags::GTM_HOST` explicitly | Accepted: the constant workaround stays |
| WP-03 | `consent` module `admin_always` | Not applied (F-8) |
| WP-03, WP-05 | Core 2.44.0: `payment_method`, store slug, `doughboss:catering-enquiry-created`, rename the pre-order `generate_lead` | Core track (WP-12/13) |
| WP-03, WP-05 | `coming_soon_view.surface` allows only `home` | Deferred (events.ts and events.json belong to WP-03 and `web/`); only the ribbon and `surface="home"` send it |
| WP-04 | TS `referrerHost` host-name rule | Deferred to `web/` owner |
| WP-04, WP-07 | Add `lead_form` to the attribution registry features | **Done** (D7) |
| WP-04 | `attribution` requires `consent_banner` | Tried, reverted (D8, F-7) |
| WP-04, WP-05, WP-06, WP-07, WP-11 | Lifecycle test counts | **Done** (D1) |
| WP-05, WP-06, WP-07 | Registry "always" and empty shortcode stubs, so a flag off never prints a raw tag | Already present in the tree (waitlist `always`, stub registration); **verified in the browser** |
| WP-05 | Ribbon option and `doughboss_growth_save_coming_soon` action | **Accepted**; option added to the uninstall list (D6); the action name should be added to doc 00 section 4.2 |
| WP-05 | Form token 3 s versus 30 s in doc 00 | 3 s stays (work breakdown figure) |
| WP-06 | Neutralise the product name in core's catering copy | Core track; **F-2 is bigger than WP-06 thought: it is on the live page today** |
| WP-06 | `seo_head` requires `landing_pages` | **Done** (D8) |
| WP-07 | `catering-pieces-per-guest` gap claim | **Done** (D9) |
| WP-07 | `lead_form` requiring a sender name | Not applied: without a sender the consent box is omitted and the enquiry still works |
| WP-08 | Public enumerator `subject_ids`, capture `_ga`/`_fbp`, record order consent when no campaign data | Deferred to WP-04 follow-up; none blocks a safe enable (fails closed) |
| WP-08 | Single GA4 source for `generate_lead` | Owner decision, gate in the runbook (F-3) |
| WP-11 | Add admin-post `doughboss_growth_recon_save_params` to doc 00 section 4.2 | **Accepted** as built; doc owner to list it |
| WP-11 | Per-request response cap in the HTTP wrapper | Deferred, optional |
| WP-11 | Cron is one re-armed event, not a repeating schedule | Noted; hook name unchanged |
| All | Extra test and stub files beyond the work breakdown list | Accepted |

## 7. Files this package touched

All paths under `/home/user/DOUGHBOSSV2/`.

Owned by this package: `doughboss-growth/docs/RELEASE-0.1.0.md`, `doughboss-growth/readme.txt` (changelog), `web/scripts/wp-local/growth/wp16-full.mjs`, this document. Also added: `doughboss-growth/tests/test-integration.php` (D11) and `doughboss-growth/docs/evidence-wp16/` (results of the runs; not in the zip), and `doughboss-growth/docs/handoff-wp-16.md`.

Small fixes to other packages' files (each named, with the reason):

| File | Why |
| --- | --- |
| `doughboss-growth/doughboss-growth.php` | D2: DB version 1.1.0 |
| `doughboss-growth/includes/class-doughboss-growth-activator.php` | D6: coming-soon option in the uninstall list |
| `doughboss-growth/includes/class-doughboss-growth.php` | D7: attribution registry features |
| `doughboss-growth/includes/class-doughboss-growth-settings.php` | D8: `seo_head` dependency; D4: working-name literal split |
| `doughboss-growth/admin/class-doughboss-growth-admin.php` | D8: wording for the new dependency error |
| `doughboss-growth/includes/ledger/class-doughboss-growth-ledger.php` | D3: shared `fold_compat()` |
| `doughboss-growth/includes/waitlist/class-doughboss-growth-coming-soon.php` | D3: teaser lint uses the shared fold |
| `doughboss-growth/content/claims.json` | D9: pieces-per-guest gap |
| `doughboss-growth/tests/test-core-lifecycle.php`, `test-core.php`, `test-core-scripts.php`, `test-waitlist.php`, `test-ledger.php`, `test-consent.php` | D1, D2, D3, D5, D6, D8: updated or added tests |
| `web/scripts/wp-local/growth/wp01-inert.mjs` | D10 |

No frozen name in section 4 of `00-architecture-extend-wordpress.md` was changed. Nothing was committed, pushed, installed on a live site or sent anywhere.
