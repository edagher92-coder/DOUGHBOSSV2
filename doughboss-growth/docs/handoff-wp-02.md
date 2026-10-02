# WP-02 hand-off: claims ledger (PHP port, ledger file, lint, oracle)

## Delivered
- `includes/ledger/class-doughboss-growth-ledger.php` (`DoughBoss_Growth_Ledger`): `load()`, `validate()`, `publishable()`, `text( $id )`,
  `lint_public( $text, $source_kind )`, plus pure TypeScript ports `publishable_claims()` / `claim_text()` and `js_trim()`.
  `init()` hooks `doughboss_growth_feature_enabled` (turns `landing_pages`, `seo_head`, `coming_soon` OFF while the ledger is invalid or
  unreadable, fails closed on any error) and, in wp-admin, the read-only "Claims" tab and an invalid-ledger notice. Writes nothing.
- `content/claims.json`: 7 claims, ALL `confirmed:false` gaps (no source, topic labels only, each with a note saying what Elie must confirm). No fact, number, product name.
- `web/scripts/wp-oracle/export-ledger-fixtures.ts` -> `tests/fixtures/ledger-oracle.json` (37 cases from the real `ledger.ts`, sha256 recorded; `--check` mode).
- `tests/test-ledger.php` (328 assertions).

## Semantics to know (for WP-05 / WP-06)
- Customer-facing code uses ONLY `DoughBoss_Growth_Ledger::text( $id )` / `publishable()`. They return null/empty unless: ledger valid, claim confirmed
  AND sourced, AND wording passes `lint_public` for its source kind. Template must omit the whole block on null.
- The pure `claim_text()` / `publishable_claims()` are exact TS ports (oracle-checked) and do NOT consider ledger validity or lint. Do not use them for output.
- Declare blocks so the admin tab can list hidden ones: `add_filter( 'doughboss_growth_ledger_blocks', fn )` returning
  `array( array( 'page' => string, 'block' => string, 'claims' => string[] ), ... )`. Read only; nothing stored.
- `lint_public( $text, null )` is also the check for admin-editable teaser text. Returns violation codes (empty = clean):
  encoding, product_name, halal, vegan, gluten, nut_free, certified, best, number_one, digit, currency, percent.
  Product name, dietary words, certified, best, "#1"/"number one" are ALWAYS rejected (also for owner-confirmed / core-data). Digits, currency
  symbols and % are rejected unless source kind is owner-confirmed or core-data. The product-name match is a substring (so "administration" is rejected),
  matching `DoughBoss_Growth_Settings::contains_banned_teaser_word()`. Zero-width characters are stripped and NFKC applied before matching.
- Claims file shape: `{ "version": 1, "claims": [ ... ] }`, claim shape as `ledger.ts` plus source kind `core-data`.

## Run (all on PHP 8.3.6, Node 22)
- `php -l` on the ledger class and `tests/test-ledger.php`: clean.
- `php tests/run.php ledger`: 328 passed, 0 failed, 0 skipped, 0 foreign writes.
- `php tests/run.php` (full): 1569 assertions, 1567 passed, 2 failed (both are WP-01 tests, see below), 0 foreign writes.
- `php scripts/php74-guard.php`: 25 files, no violations.
- `node scripts/es5-check.mjs`: 0 files (no hand-written JS yet), no violations.
- `NODE_PATH=web/node_modules web/node_modules/.bin/tsx web/scripts/wp-oracle/export-ledger-fixtures.ts` (and `--check`): 37 cases, current.
- `web/node_modules/.bin/eslint web/scripts/wp-oracle/export-ledger-fixtures.ts`: clean.
- `php scripts/build-zip.php` + `validate-zip.php`: zip built (12 files) and valid; contains `content/claims.json` and the ledger class.
- Negative controls: tampered oracle expectation detected; bad ledgers (bad JSON, wrong shape, oversize, missing file, invalid claim) leave features off; lint rejects every listed token.

## Not run
- Browser / local WordPress runtime check (not part of WP-02 acceptance); no outbound requests were made.
- PHP 7.4 / 8.2 interpreters (only the 7.4 syntax guard on 8.3).
- Oracle staleness check is skipped (visibly) if `web/src/content/ledger.ts` is not next to the plugin dir.

## Known failures not mine (contract change request)
`tests/test-core-scripts.php` lines ~351 and ~513 do `mkdir( $plugin . '/content', ... )` on a copy of the plugin. Now that `content/claims.json`
ships, that directory already exists and the two tests fail with "mkdir(): File exists". Fix (WP-01 file): `if ( ! is_dir( $plugin . '/content' ) ) { mkdir(...); }`.

## [CONFIRM] gaps
- All seven claims in `claims.json` (lead time, service area, delivery or drop-off, dietary information, catering phone line, reviews, how the food is made).
- Dietary wording: the lint rejects dietary words even when owner-confirmed (literal reading of the architecture list). If Elie later confirms a halal or
  dietary statement with evidence, that is a deliberate lint change, not a ledger edit.
- Whether numbers in owner-confirmed claims (for example opening days) are acceptable on landing pages is as per the architecture rule; no such claim ships.
