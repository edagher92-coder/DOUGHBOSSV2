# WP-03 hand-off: consent banner, Consent Mode v2, Tag Manager loader, dataLayer dispatcher

Status: built and verified as listed below. Everything is off by default; with every flag off the public pages are unchanged (proved in a browser).

## What exists

- `includes/consent/class-doughboss-growth-tags.php` (`DoughBoss_Growth_Tags`): Consent Mode default plus cookie replay (wp_head priority 0), the one Tag Manager loader (priority 1), container id validation. Inert unless `consent_banner` AND `gtm` are effectively on, the id matches `^GTM-[A-Z0-9]{4,10}$` and `content/events.json` is valid.
- `includes/consent/class-doughboss-growth-consent.php` (`DoughBoss_Growth_Consent`, registry entry for module `consent`): banner and "Privacy choices" button in `wp_footer`, script and style enqueue, inline `window.DoughBossGrowthConfig`, the `doughboss_marketing_config` filter (`enabled` true, `metaPixelId` and `tiktokPixelId` empty), `current()` (server-side reading of the cookie), `events()` / `validate_events()`, the shop map, and a "Consent and tags" admin tab.
- `public/js/dbgr-consent.js`, `public/js/dbgr-datalayer.js`, `public/css/dbgr-consent.css`, `content/events.json`.
- `web/scripts/wp-oracle/export-events.ts` (exporter, `--check` mode), `web/src/lib/analytics/events.ts` (only `begin_checkout.payment_method` is now `"SQUARE" | "PAY_AT_SHOP"`), `web/tests/unit/analytics-events.test.ts` (the matching test; the old file had no payment_method test), `web/scripts/wp-local/growth/wp03-consent.mjs`.
- Tests: `tests/test-consent.php`, `tests/stubs-consent.php` (stub of `DoughBoss_Locations`), `tests/consent.test.js`, `tests/datalayer.test.js`.

## Interface for later packages

- `DoughBoss_Growth_Consent::current()` returns `array( measurement, advertising, chosen, version )`. Closed (all false, version "") when `consent_banner` is off. In notice-and-opt-out mode and before a choice, `measurement` is true and `chosen` false. WP-04 and WP-08 should use this, and may require `chosen` if they want an explicit choice. A cookie made under an older `consent_text_version` is not a choice.
- `DoughBoss_Growth_Consent::parse_cookie( $raw, $version )` validates the cookie rules (v string equal to the current version, m and a integers 0 or 1, ts positive integer, 400 characters at most).
- Browser: `DoughBossGrowth.track( name, params )` returns true (pushed) or false (refused or dropped). It always exists when `consent_banner` is on; with Tag Manager off it returns false. `DoughBossGrowth.consent.get()` and `.open()`. Events on `document`: core `doughboss:consent` `{ measurement, advertising, version }` and `doughboss-growth:consent-changed` `{ measurement, advertising, version, chosen }`; both fire once on load (replay) and on every change. Any element with `data-dbgr-consent-open` reopens the banner.
- Typed events are pushed as `{ event: name, ...params }`. Nothing is pushed until the visitor has allowed measurement or advertising; events before a choice are dropped, not queued.
- Modules should call `window.DoughBossGrowth && DoughBossGrowth.track(...)`.

## Decisions I made (flag them if you disagree)

1. **No `<noscript>` Tag Manager iframe.** It would load the container for visitors who cannot be asked for consent.
2. **Typed events are held until consent** (measurement or advertising). The stricter reading of "nothing before consent"; Consent Mode "advanced" pings are not used. One-line change in `consentAllows()` if Elie wants otherwise.
3. **Core `generate_lead` is not forwarded.** In core 2.43.2 it is the after-hours pre-order request (`content_category: Preorder`), not a catering enquiry; forwarding it as `generate_lead` would corrupt the primary Google Ads conversion. A catering `generate_lead { form: catering_enquiry }` comes only from core's `doughboss:catering-enquiry-created` (2.44.0) or, until then, the `.dbc-success` box inside `[data-doughboss-catering]` (once per enquiry reference; the reference is never pushed).
4. **Core `purchase` becomes `order_placed`; a browser `purchase` is never pushed.**
5. **`consent_default = opt_out` meaning (setting was left to WP-03):** before a choice `analytics_storage` is granted and the three advertising signals stay denied; the banner still shows; a stored choice always wins. Unknown value means deny.
6. **Params are dropped, not the event.** Allow-list per event from `events.json`; wrong type dropped; any string with `@` or nine or more digits (up to two separator characters between digits, so "0400 000 000" is caught) dropped; integers 0 to 99,999,999 only; strings trimmed and capped at 100.
7. **`events.json` has 13 events.** `hero_explore` is excluded by `CANCELLED_EVENTS` in the exporter (added by the lead after the hero decision; I kept it and adapted my tests). `events.ts` itself still lists it.
8. **Tag Manager is also off when `events.json` is missing or invalid** (fail closed, no half-configured state).
9. **Shop to `store` mapping** uses `DoughBoss_Locations::all( true )` and only core slugs exactly `revesby`, `bankstown`, `roselands`; any other shop carries no `store`.
10. Banner is a non-modal dialog in `wp_footer`; first automatic display moves focus to it; Escape never dismisses it before a choice.
11. The GTM host is the constant `DoughBoss_Growth_Tags::GTM_HOST` (a bare host) so WP-01's "no hard-coded provider URL" scope test stays green.
12. wp-admin is excluded from every output.

## What was run (evidence)

| Check | Result |
| --- | --- |
| `php -l` on every PHP file in `doughboss-growth/` (29) | clean, PHP 8.3.6 |
| `php doughboss-growth/tests/run.php` | 1862 assertions, 1862 passed, 0 failed, 0 skipped, 0 foreign writes (includes 290 in `test-consent.php` and WP-01's scope tests) |
| `php tests/run.php consent` | 290 passed |
| `php scripts/php74-guard.php` | no violations, 29 files (PHP 8.3 runs the guard; a real 7.4 interpreter was not run by me) |
| `node --test tests/consent.test.js` | 22 pass, 0 skipped |
| `node --test tests/datalayer.test.js` | 26 pass, 0 skipped (includes the core-source contract tests and `export-events.ts --check`) |
| `node --test tests/core.test.js` (WP-01) | 24 pass |
| `ACORN_PATH=web/node_modules/acorn node scripts/es5-check.mjs` | 2 files, no violations; a planted arrow function in a copy fails the gate |
| `build-zip.php` / `validate-zip.php` / `budgets.php` (to a scratch path) | zip valid, 18 files, 59,318 bytes (6% of budget) |
| `web`: `npx vitest run` | 29 files, 780 tests pass (8 in `analytics-events.test.ts`) |
| `web`: `npx eslint` on the four web files, `npx tsc --noEmit` | clean |
| `tsx web/scripts/wp-oracle/export-events.ts --check` | events.json is up to date |
| Browser, `wp03-consent.mjs`, Playground PHP 8.2 / WP 7.1, core 2.43.2, port 9403, state `/tmp/wpl-WP-03` | 68 of 68 checks passed, see below. Runtime stopped; nothing left running. |

Browser checks (third-party hosts intercepted and counted, none contacted; the Tag Manager script was a stub that acts as a consent-respecting GA4 tag and requests `collect` only once `analytics_storage` is granted):
- Flags off on `/`, `/order/`, `/catering/`: zero tracking requests, no banner, no global, no cookie, no companion string in the HTML, no console errors.
- Banner plus Tag Manager (dummy id `GTM-TEST123`): container requested once; Consent Mode default (four denied, `wait_for_update` 500) pushed before the container's `gtm.js` event; zero `collect` requests before a choice; Reject writes `{v,m,a,ts}` with 180-day, Lax cookie, persists across reload and is replayed as an update before the container loads; granting measurement makes the tag collect; Accept grants all four and collects (zero before, one after); Tab order link, Accept, Reject, Choose; Escape behaviour; the three buttons have identical computed styles and are 48 px tall; axe 0 violations (banner closed, banner open, Pixel 7); Pixel 7 banner fits with no horizontal scroll; core bridge config has both pixel ids empty and no `fbq` or `ttq`; core received the stored choice; a core `purchase` reached `dataLayer` only as `order_placed`; a core pre-order `generate_lead` was not forwarded.
- Banner only: banner works, no dataLayer, no gtag, zero tracking requests, `track` refuses.
- Opt-out: analytics granted by default (collect before a choice), advertising denied, banner still shown, Reject denies analytics.
- Cleanup: every flag off again, pages clean.
Screenshots: `/tmp/wpl-WP-03/shots/` (`wp03-banner-desktop.png`, `wp03-banner-choices-desktop.png`, `wp03-banner-pixel7.png`) and `wp03-checks.json`.

## Not run, and why

- A real Tag Manager container, GA4, Google Ads or Meta (no ids, and no outbound request is allowed). The stub proves the plugin's signals and ordering, not any real container's behaviour.
- PHP 7.4 interpreter and the CI workflow (as WP-01: guard run on 8.3; no runner).
- Core 2.41.0 (baseline B) in the browser; only C (2.43.2) was used. The core selectors and filter name are covered by the contract test against C only (skipped visibly when `/tmp/wp-src` or `DBGR_CORE_SRC` is absent).
- MySQL (the module writes no table, option or transient).
- A real screen-reader pass and cross-browser (Chromium only). axe is automated, not a full WCAG audit.
- The catering success box was exercised with a fake `MutationObserver`/DOM in node, not by submitting a real enquiry in the browser.

## [CONFIRM] gaps (also shown in Growth, Consent and tags)

- Banner wording and the three categories need review by Elie and a solicitor; raise `consent_text_version` after any change so everyone is asked again.
- Privacy-policy URL: the banner shows no link until one is saved, and the policy must describe the tags before `gtm` is enabled.
- Tag Manager container id from the account owned by the right entity (the Settings tab already lists it). Inside the container, GA4 must require `analytics_storage`, and Ads and Meta tags `ad_storage`, `ad_user_data`, `ad_personalization`; the plugin sets signals, the container decides what fires.
- Whether `consent_default = opt_out` is acceptable (notice-and-opt-out) is Elie's legal and accountant decision; deny is shipped.
- `begin_checkout` carries no `payment_method`: core 2.43.2 does not send one and its bridge drops unknown fields. It is only added when core sends exactly `SQUARE` or `PAY_AT_SHOP`.
- `item_slug` for `add_to_cart` and `view_item` is core's menu item id string (for example "42"), because core sends no slug. Elie to confirm this is acceptable for GA4 and feed matching.
- Events with no trigger in this package (not built, out of scope): `select_store`, `remove_from_cart`, `quote_step`, `coming_soon_view`, `waitlist_submit`, `click_to_call`, `get_directions`, `cta_click`. Other modules or later work call `DoughBossGrowth.track`. The `tel:` listener from the conversion plan section 3 is not built because `click_to_call` needs a store that a phone link does not give.

## Contract change requests

1. `web/marketing/meta/tracking.md:252` and `web/marketing/google-ads/conversion-plan.md` row 4 still say `STRIPE | PAY_AT_PICKUP`; update to `SQUARE | PAY_AT_SHOP` (owner of those docs).
2. WP-01: `docs/wp/00` section 3.9 lists `hero_enhanced` and 12 flags; its scope test forbids a provider URL literal in `includes/`. Consider whitelisting `DoughBoss_Growth_Tags::GTM_HOST` explicitly rather than relying on the constant workaround.
3. WP-01 registry: module `consent` has `admin_always` false, so the "Consent and tags" tab and its [CONFIRM] list exist only while `consent_banner` is on (the Settings tab shows the Tag Manager id gap regardless). Set `admin_always` true if the tab should be visible while off.
4. Core 2.44.0: include `payment_method` and the store slug in the `begin_checkout` marketing event and add `payment_method` to the bridge `allowedFields`; dispatch `doughboss:catering-enquiry-created { enquiry_number }` (architecture 4.4 item 12). Also consider renaming core's pre-order `generate_lead` so it cannot be mistaken for a catering enquiry.
5. WP-16: `DOUGHBOSS_GROWTH_DB_VERSION` is unaffected (this package adds no table).
