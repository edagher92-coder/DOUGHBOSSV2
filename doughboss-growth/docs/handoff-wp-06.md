# WP-06 hand-off: landing pages engine and SEO head

## Delivered
- `includes/landing/class-doughboss-growth-landing.php` (`DoughBoss_Growth_Landing`): definitions loader and validator, shortcode `[doughboss_growth_landing key="..."]`, block rendering, core-data adapters (shop, weekly hours, catering packages, core catering form), admin-post `doughboss_growth_create_pages`, admin tab "Landing pages", stylesheet and core asset filters, ledger block declarations.
- `includes/landing/class-doughboss-growth-landing-seo.php` (`..._Landing_SEO`): `document_title_parts` at 30, `wp_head` at 6 (description, Open Graph, Twitter, JSON-LD), `wp_robots` noindex for a page with no content of its own, SEO-plugin coexistence.
- `includes/landing/class-doughboss-growth-landing-schema.php` (`..._Landing_Schema`): pure JSON-LD builder; the `sources()` table lists every type and property with where its value comes from, and `validate()` refuses anything not in it (plus an explicit forbidden list: geo, sameAs, ratings, priceRange, hasMenu, servesCuisine, image, hasMap).
- `content/landing/*.json`: six definitions (catering-corporate, catering-events, catering-office-breakfast, locations-bankstown, locations-revesby, locations-roselands). No definition and no code mentions the unannounced product; there is no `catering-minis` key anywhere (the engine renders whatever definition files exist).
- `public/css/dbgr-landing.css`; `tests/test-landing.php`, `tests/test-landing-schema.php`, `tests/stubs-0-landing.php`; `web/scripts/wp-local/growth/wp06-landing.mjs`.

## Behaviour to know
- Pages are DRAFT children created by the admin button (capability + nonce). Per page: `created`, `exists`, `adopted` (orphan with exactly this shortcode), `trashed` (left alone), `parent_missing`, `slug_taken`, `insert_failed`. Nothing is ever published or overwritten. Option `doughboss_growth_pages` is `key => id`; other keys in it are preserved.
- Head output needs BOTH `seo_head` and `landing_pages`, applies only to a registered page whose content is still exactly its shortcode, and never to hub pages. Canonical is not printed (WordPress prints it). One full title (`X | Dough Boss`; tagline and site removed). With Yoast, Rank Math, AIOSEO or SEOPress active nothing is printed, except the JSON-LD when `seo_jsonld_with_seo_plugin` is on.
- Copy: templates hold only neutral headings, claim ids and core slots. A claim block is omitted unless the ledger publishes it. Title/description/crumb/service name pass the ledger lint plus a claim-word list (fresh, delivery, free, cheap, award, ...). No "Sydney" or service-area wording in metadata (service area is an unconfirmed claim).
- Core data read at render time: shop by core slug (`DoughBoss_Locations::all(true)` then `weekly_hours()`), packages (`doughboss_cat_pkg`, base price meta, only published with a price > 0). Every core string is linted (`core-data` kind) and a failing name/address fails the page closed. `{details}` in a shop description lists only what core actually has.
- JSON-LD: `areaServed` only from a confirmed `catering-service-area` claim that also carries an `areas` list of place names (the claim text is a sentence, so it is never used as a place); FAQPage only from confirmed claims that carry a `question` field. Neither exists in `claims.json` today, so neither is emitted.
- Indexing: a page with no ledger claim, FAQ or shop block of its own is marked noindex (package cards and the form are identical on all catering pages; avoids near-duplicate pages). Today all three catering pages are noindex until claims are confirmed.
- The core catering form (`[doughboss_catering]`) is embedded only when its rendered text passes the lint. Core 2.43.2's own form copy contains the unannounced product name, so the block is currently LEFT OUT (the admin tab says why), and core's catering assets are not requested for it. See change request 2.
- Core-supplied constants copied into the JSON-LD address: region NSW and country AU (core SEO class `schema()` prints the same). Shop `name` is core's name exactly, so the `@id` shared with core's home graph describes one entity.

## Run (PHP 8.3.6, Node 22)
- `php -l` on all 3 landing classes, `tests/test-landing.php`, `tests/test-landing-schema.php`, `tests/stubs-0-landing.php`: clean.
- `php tests/run.php landing`: 724 assertions, 724 passed (535 engine/SEO/admin + 189 schema), 0 foreign writes.
- `php tests/run.php` (full): 4039 assertions, 4033 passed, 6 failed, 1 skipped. The 6 failures are WP-01 install/self-heal tests that count dbDelta statements and now see the waitlist and recon tables (WP-05/WP-11 schemas); none involve the landing module (it has no schema). The skip is WP-11's core-source parity test.
- `php scripts/php74-guard.php`: 53 files, no violations. `node scripts/es5-check.mjs`: 4 files, no violations (no JS written). `php scripts/build-zip.php` + `validate-zip.php` + `budgets.php`: valid, 40 files, 164,616 bytes (16% of budget; zip written to /tmp and removed).
- Mutation checks on a scratch copy (12 deliberate breaks: claim gate, nonce, capability, publish instead of draft, slug-taken check, form guard, SEO-plugin check, hub guard, price rounding, JSON escaping, package lint, forbidden list): 11 caught; the forbidden-list one is still caught by the allow-list (defence in depth).
- Browser, local runtime port 9406 (`WPL_STATE=/tmp/wpl-WP-06`): `node scripts/wp-local/growth/wp06-landing.mjs` from `web/`: 186 passed, 0 failed, plus 2 NOTEs (change request 1). Covers: hub baseline; drafts only and not public; CSRF, wrong-action nonce and anonymous refusals; idempotent button; per page exactly one `<title>`, one description, one canonical (WordPress core's), one companion JSON-LD script, zero core JSON-LD (control: core's `/locations/` does carry its own); address/phone/hours equal core; Offer prices equal core exactly; no product name anywhere in the page source; axe 0 violations (desktop all six, mobile two); no horizontal scroll; no console errors; scratch confirmed claim shown exactly and removed again; fake `WPSEO_VERSION` prints no head tags (JSON-LD only with the setting); deactivation drafts the six pages and reactivation keeps settings. Runtime stopped. The script was lint-fixed (unused variables) after its last full run; `node --check` and eslint pass, it was not re-run end to end after that cosmetic edit.
- `web/node_modules/.bin/eslint` on the script: clean.

## Not run, and why
- Real Yoast/Rank Math (only the constant was defined, in a scratch file of the runtime copy; `start.sh` has no mu-plugin mount and Playground caches file-existence checks, so the toggle is the file's content).
- PHP 7.4 / 8.2 interpreters (only the 7.4 syntax guard on 8.3); Google Rich Results test or Search Console (no external requests).
- vitest (no `web/` source changed).
- The hub-page comparison versus WP-01's capture was done with WP-01's own `wp01-inert.mjs` against a baseline captured at the start of this run (flags off), not WP-01's archived capture. Result: byte-identical with the six pages as drafts; once published, WordPress core itself adds the body class `page-parent` to `/catering/` and `/locations/` (verified as the only difference; `/` and `/order/` identical).
- The scratch runtime has fake test addresses for Bankstown and Roselands and test packages; live data was never read.

## [CONFIRM] gaps
- Core location slugs: definitions assume core slugs `revesby`, `bankstown`, `roselands`. The live Roselands row is called "Roselands Centro"; if its slug differs the page renders nothing (fail closed, admin tab says why). Fix the `location_slug` in the definition file.
- Address, phone and hours conflicts between the live site and typed data (nap.json) are untouched: pages show core's values only. Core's weekly hours are the pickup hours.
- Region NSW and country AU in the JSON-LD address come from core's SEO class, not from a location row.
- All seven ledger claims are still gaps, so no catering page has claim content: lead time, service area (and its `areas` list), delivery or drop-off, dietary status, catering phone line (a catering phone is NOT rendered anywhere), reviews, how the food is made.
- Whether to publish the drafts, and whether the catering pages should be indexable before claims exist (currently noindex).

## Contract change requests (details in the structured report)
1. WP-01 registry: initialise the landing module (and register an empty shortcode) on the front end even when its flags are off, as WP-05 also asked. Today, if a published page exists and the flag is turned off (or the ledger turns it off), the raw `[doughboss_growth_landing ...]` text prints. Deactivation is safe (WP-01 drafts the pages). Proposed: an `always` registry field.
2. Core (WP-12): neutralise the unannounced product name in `DoughBoss_Catering::catering()` copy ("We will help balance ..."), or the form block stays hidden on every landing page.
3. WP-01 settings: `seo_head` should require `landing_pages` (the SEO head enforces it at runtime already).
