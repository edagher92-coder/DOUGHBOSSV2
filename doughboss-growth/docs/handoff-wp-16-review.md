# WP-16 adversarial review: hand-off note

The full report is in `web/docs/wp/07-adversarial-review.md` (findings by severity, with evidence, split into fixed and open). This note lists what changed, what was run, what was not, and the [CONFIRM] gaps.

## Changed (smallest fixes, frozen names kept)

| Fix | File | Regression test |
| --- | --- | --- |
| F1: the waitlist module runs on every request (registry key `always`), so opt-out, exporter, eraser and purge survive the flag being switched off | `includes/class-doughboss-growth.php` | R1 |
| F2: empty stubs for the five companion shortcodes once the schema is installed or pages are recorded, so a page never shows a raw tag | `includes/class-doughboss-growth.php` | R2 |
| F3: deactivation also drafts the page at `coming_soon_page_slug`, shortcode-only pages only | `includes/class-doughboss-growth-activator.php` | R3 |
| F4: teaser-only guard against date, price, place, product, size and dietary words and look-alike scripts | `includes/waitlist/class-doughboss-growth-coming-soon.php` | R4 |
| F5: the daily retention purge also purges expired limiter buckets | `includes/waitlist/class-doughboss-growth-waitlist.php` | R5 |
| F6: IPv6 limited per /64; IPv4-mapped counts as IPv4 | `includes/class-doughboss-growth-rate-limit.php` | R6 |

All regression tests live in `tests/test-wp16-adversarial.php` (86 assertions, self-contained).

## Run

- `php tests/run.php`, PHP 8.3.6:
  - before any change: 5245 passed, 0 failed, 1 skipped, 0 foreign writes;
  - final run, including the concurrent WP-16 integration edits: 5355 passed, 0 failed, 1 skipped, 0 foreign writes.
- `php tests/run.php wp16`: 86 passed.
- Mutation check, each fix reverted in a scratch copy: F1 13 failed, F2 7, F3 4, F4 29, F5 1, F6 3, F6 IPv4-mapped branch 2. Every revert was caught.
- `php -l` on the changed files: clean on PHP 8.3.6, and on PHP 7.4.33 (Playground WASM, lint mode, 6 files).
- `php scripts/php74-guard.php`: 68 files, no violations.
- `node --test tests/*.test.js`: 144 passed.
- `node scripts/es5-check.mjs`: 7 files, no violations.

## Not run, and why

- The full suite under PHP 7.4: the WASM CLI hung on `tests/run.php` and was stopped by its timeout.
- A browser runtime check:
  - WP-05 had already browser-proven the same registry patch (97 of 97);
  - the machine was shared with another live Playground runtime and the integration agent's PHP 7.4 runs.
- MySQL (SQLite harness only).
- An audit of a real Tag Manager container (needs the owner's container).

## [CONFIRM] gaps and owner decisions raised by the review

- [CONFIRM: whether Tag Manager may load before consent (Consent Mode advanced pattern) or only after a granted choice (basic). See O1.]
- [CONFIRM: switching `landing_pages` off leaves published pages live with an empty body. Either draft them first (runbook), or approve a code change that drafts them automatically. See O3.]
- [CONFIRM: opt-out route while the plugin is deactivated or kill-switched within 30 days of a list email (Spam Act). See O4.]
- [CONFIRM: whether the free-text teaser lines should become a short list of owner-approved neutral lines. See O6.]
- [CONFIRM: placement of the optional home ribbon after core's hero (off by default) under the "no 3D" hero decision. See O11.]

## Contract change requests (not applied here)

- WP-04/WP-07: exporter and eraser for `doughboss_growth_lead_meta` and `doughboss_growth_attribution` (O2); reject `@` and long digit runs in UTM values in both the TypeScript schema and the PHP sanitiser, then regenerate the oracle (O5).
- WP-06: draft recorded landing pages when `landing_pages` is saved off (O3).
- WP-11: add `run_status` to the reconciliation CSV and an evidence-only file name (O9).
- WP-03: optional basic-mode Tag Manager load (O1); clear first-party analytics cookies on Reject (O10).
