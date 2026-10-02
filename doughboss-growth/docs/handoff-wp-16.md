# WP-16 hand-off: integration and release candidate

Status: complete as far as it can be run here. Release candidate `doughboss-growth-0.1.0.zip` (225,504 bytes, sha256 `a711ec964025729323a9796f3b4051c8c914452d99983eb0e9ef0cd7fb3e6b2f`, built to `doughboss-growth/dist/`, ignored by git). Read `RELEASE-0.1.0.md` (runbook) and `web/docs/wp/06-growth-release-review.md` (what was verified, found, fixed and not run).

## What was run

| Command | Result |
| --- | --- |
| `php -l` on 70 PHP files, PHP 8.3.6 and real PHP 7.4.33 (WebAssembly) | clean |
| `php scripts/php74-guard.php`, 8.3.6 and 7.4.33 | 70 files, no violations |
| `DBGR_CORE_SRC=/tmp/wp-src/candidate-2.43.2 php tests/run.php` (and baseline-2.41.0) | 5,602 passed, 0 failed, 0 skipped, 0 foreign writes (both) |
| `php tests/run.php` without a core source | 5,597 passed, 1 visibly skipped |
| same suite on PHP 7.4.33 WebAssembly | 5,336 passed, 0 failed, 25 skipped (sub-process tests) |
| `node --test tests/*.test.js`, `scripts/es5-check.mjs` | 144 tests passed; 7 files clean; negative control fails as it should |
| `build-zip.php`, `validate-zip.php`, `budgets.php` | OK; byte-identical rebuild; tamper fails; refuses overwrite |
| `web`: `npx vitest run`; `npx eslint scripts/wp-local/growth/` | 780 passed; clean |
| Inert test, core 2.43.2 and baseline 2.41.0 (`wp01-inert.mjs capture/compare`, `admin`) | identical, 4 pages each; admin checks pass |
| `wp16-full.mjs` on 2.43.2 (flags one by one, all together, shots, axe, reversal, deactivate) | 185 passed, 0 failed |
| `wp16-full.mjs` on 2.41.0 | 185 passed, 0 failed |
| PHP 7.4 runtime smoke (`WPL_PHP=7.4`, phases A, C to E) | identical home page hash; 100 passed, 0 failed |

Runtime instance: `WPL_STATE=/tmp/wpl-wp16`, port 9416, always stopped afterwards (nothing left running).

## Not run

The independent Opus adversarial review; any real provider, mailbox or live host; MySQL; native PHP 7.4/8.2; Node 20; GitHub Actions; real Yoast or Rank Math; a paid order. See the review document, section 4. No Higgsfield call was made (the original user request about a hero reel is outside this package).

## [CONFIRM] gaps and owner decisions

All owner gates are in `RELEASE-0.1.0.md` section 4. The ones that block enabling: F-1 retention and erasure for attribution and lead rows; F-3 single GA4 lead source; every claim in `content/claims.json`; sender legal name, privacy-policy URL, retention period, consent wording; container, GA4, Meta ids and secrets; Square labour approval and every recon tolerance. F-2 (core's own `/catering/` copy contains the working name today) needs a core change.

## Contract change requests

Dispositions are in the review document, section 6. Still open for others: docs 00 and 05 flag counts and the two new admin-post names (doc owner); `tracking.md` and `conversion-plan.md` STRIPE wording (web/marketing owner); core 2.44.0 items (payment_method, store slug, enquiry event, product name in catering copy); `events.ts` `coming_soon_view.surface` `page`; WP-04 follow-ups (retention, exporter and eraser, `subject_ids`, `_ga`/`_fbp`).
