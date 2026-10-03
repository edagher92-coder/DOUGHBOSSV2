# Handoff for a coding agent (OpenAI Codex): DoughBoss Growth 0.1.0

Written 2026-10-02. Cold start: read this, then `docs/RELEASE-0.1.0.md`. Australian English; the owner is Elie.

## 1. Goal and status

- Deliverable: `doughboss-growth`, a WordPress companion plugin (0.1.0) for the DoughBoss core plugin. Needs WordPress 6.0+, PHP 7.4+, core 2.41.0+.
- All eleven features (flags) are off by default. Installing changes nothing a visitor sees.
- Not installed on the live site. No provider (Google, Meta, Square) has ever been called.
- Pull request: PR #69, against the repo default branch `claude/awesome-johnson-bkjh83`.
- The live site runs core 2.41.0. Its source is the branch `claude/live-2.41.0-baseline`. The newer candidate core is 2.43.2.
- The repository is being made private (see open decisions).
- Last verified (2026-10-02): 7,712 PHP assertions passed (with a core source), 162 Node tests, 780 web unit tests, 188 browser checks on both cores, zip 51 files / 270,365 bytes (27% of the 1,000,000-byte budget), byte-identical rebuild.

## 2. Where things are

`doughboss-growth/`
- `doughboss-growth.php`, `uninstall.php`, `readme.txt`: entry point, uninstall, plugin readme.
- `includes/`: `class-doughboss-growth*.php` (main, settings, activator, http, outbox, rate-limit, failures) plus module folders `attribution/`, `consent/`, `conversions/`, `landing/`, `leads/`, `ledger/`, `recon/`, `waitlist/`.
- `admin/class-doughboss-growth-admin.php`: DoughBoss, Growth settings screen and tabs.
- `public/css`, `public/js`: front-end assets (ES5 only).
- `content/`: `claims.json` (claims ledger), `events.json`, `landing/` (six landing page definitions).
- `tests/`: `run.php` (PHP runner), `test-*.php`, `stubs-*.php`, `*.test.js` (Node), `fixtures/`, `integration/`.
- `scripts/`: `build-zip.php`, `validate-zip.php`, `budgets.php`, `php74-guard.php`, `es5-check.mjs`.
- `dist/`: git-ignored build output.
- `docs/`: `RELEASE-0.1.0.md` (runbook and evidence), `handoff-wp-*.md` (per work package), `evidence-wp16/` (raw run results).

`web/scripts/wp-local/`: throwaway local WordPress runtime (`start.sh`, `stop.sh`, `verify.sh`, `config.sh`, `php/`, `shots.mjs`) and `growth/` browser scripts (`wp01-inert.mjs`, `wp03`..`wp08`, `wp16-full.mjs`).
`web/scripts/wp-oracle/`: TypeScript exporters (attribution fixtures, events, ledger fixtures) that generate the oracle data the PHP and JS tests compare against.
`web/docs/wp/`: `00` architecture, `04` local runtime, `05` work breakdown, `06` growth release review, `07` adversarial review, `08` security review.

## 3. How to run every check

From `doughboss-growth/` (PHP 8.3, Node 22):

```
export ACORN_PATH=/home/user/DOUGHBOSSV2/web/node_modules/acorn
DBGR_CORE_SRC=/tmp/wp-src/candidate-2.43.2 php tests/run.php   # also try /tmp/wp-src/baseline-2.41.0; without it 1 test is skipped, visibly
php scripts/php74-guard.php
node --test tests/*.test.js
node scripts/es5-check.mjs
rm -f dist/doughboss-growth-0.1.0.zip       # the builder refuses to overwrite
php scripts/build-zip.php
php scripts/validate-zip.php dist/doughboss-growth-0.1.0.zip
php scripts/budgets.php dist/doughboss-growth-0.1.0.zip
php scripts/build-zip.php /tmp/second.zip && cmp dist/doughboss-growth-0.1.0.zip /tmp/second.zip
```

From `web/`: `npx vitest run` and `npx tsc --noEmit -p .`.

Core sources: `/tmp/wp-src/candidate-2.43.2` and `/tmp/wp-src/baseline-2.41.0` are scratch copies. If missing, extract them from the repo branches (the default branch for 2.43.2, `claude/live-2.41.0-baseline` for 2.41.0).

Browser integration (local throwaway site only; header of `web/scripts/wp-local/growth/wp16-full.mjs`):

```
export WPL_STATE=/tmp/wpl-wp16 WPL_PORT=9416
mkdir -p $WPL_STATE && ln -sfn /tmp/wp-local/pg $WPL_STATE/pg
WPL_EXTRA_PLUGIN_SRC=/home/user/DOUGHBOSSV2/doughboss-growth web/scripts/wp-local/start.sh --restart
# from web/:
WPL_URL=http://127.0.0.1:9416 WPL_STATE=/tmp/wpl-wp16 node scripts/wp-local/growth/wp16-full.mjs
web/scripts/wp-local/stop.sh
```

Run `wp01-inert.mjs` (capture, then compare) first, once without and once with the companion. Options: `WP16_SKIP_MATRIX=1`, `WP16_NO_SHOTS=1`. Latest logs: `/tmp/wp16-candidate.log` and `/tmp/wp16-baseline.log` (188 PASS, 0 FAIL each, 0 outbound attempts).

## 4. Rules that tests enforce (do not weaken a test to pass)

- Silence rule: with flags off nothing is printed on public pages (byte-identical to no plugin), except the waitlist "always" duties, which print nothing publicly.
- Fail closed: missing prerequisite, bad ledger, unknown location or schema below `DOUGHBOSS_GROWTH_DB_MIN_COMPAT` means the module does nothing.
- Inert when flags off, and under `DOUGHBOSS_GROWTH_DISABLE`.
- Uninstall list: only `doughboss_growth_*` tables, options, transients and cron events, and only when `DOUGHBOSS_GROWTH_UNINSTALL_DELETE_DATA` is true. New options must be added to the activator list.
- Write surface and hook footprint are pinned: the companion writes no core table, option, post type, role or capability (only the status of its six landing pages). Adding a hook or write needs the pin updated deliberately.
- No provider hosts in code or assets except the Tag Manager loader. No direct `wp_remote_*`; use `includes/class-doughboss-growth-http.php`.
- Never the unannounced product name, anywhere in the zip. Public-copy lint rejects dietary words, "certified", "best", "#1".
- No 3D, no WebGL, no hero, no media zip (owner decision).
- PHP 7.4 syntax (guard script). Browser JS is ES5 (acorn gate).
- Failure log API: `includes/class-doughboss-growth-failures.php` (option `doughboss_growth_failures`). Record problems there, not with error_log or silent returns.
- Never fabricate a price, date or fact: claims come from the ledger or core.

## 5. Open decisions for the owner (Elie)

From RELEASE section 4 and `web/docs/wp/07-adversarial-review.md`:
- O1: Tag Manager container loads before any consent choice (by design). Accept, or add an after-consent loader, or audit the container.
- O2 / F-1: no retention rule, exporter or eraser for lead and attribution rows.
- O3: unticking `landing_pages` leaves published pages online but empty.
- O4: opt-out links stop when the kill switch, missing core or deactivation applies.
- O5 to O10, O12: UTM personal data, teaser deny-list, plus-address limits, timing side channel, reconciliation CSV marker, cookies left after withdrawal, lead-form consent withdrawal.
- O11: ribbon placement after core's home hero.
- F-2: core 2.43.2 catering copy contains the unannounced name; core 2.44.0 must neutralise it. F-3: single GA4 source for `generate_lead`.
- Consent wording (version 2) needs legal review before the banner is on; consent default (`deny` shipped).
- Sender legal name (and ABN), privacy-policy URL, retention period for the waitlist.
- Dietary facts and the other six unconfirmed claims; shop addresses and phone; catering phone line; Roselands slug.
- Square staff data approval, merchant ownership, every reconciliation tolerance.
- Repo privacy: the repository is being made private; confirm that is done before anything else is shared.

## 6. Recently changed behaviour (polish pass)

- Failure log: bounded record of problems, shown as "Recent failures" on the Status table.
- Settings: values that are refused or adjusted on save now show a warning instead of changing silently.
- `DOUGHBOSS_GROWTH_DB_MIN_COMPAT`: modules run on the oldest compatible schema, so public duties work before the repair.
- `settings_version` lives inside the settings option; `DoughBoss_Growth_Activator::migrate()` runs idempotent steps once.
- Purge rescheduling: the daily purge event is re-checked and rescheduled if missing.
- Outbox: per-run time budget and batch size 10.
- Offline Google Ads export refuses unsafe rows (for example without a click id) instead of writing them.
- Waitlist opt-out is token-first (the token is checked before anything else).
- Privacy exporter and eraser report read failures rather than claiming success.
- Consent banner has a Close button; consent text version defaults to 2 with reworded text (legal review pending).
- dataLayer events are pushed only when the Tag Manager container is ready.
- A webhook alone is not a conversion destination.
- Tilt cards removed.

## 7. Do not

- Deploy, install on the live site, or switch any flag on live.
- Call Google, Meta, Square, mail or any provider (the local runtime refuses non-local requests; keep it that way).
- Commit secrets, tokens, customer data or private transcripts.
- Rewrite history or force-push.
- Weaken or delete a pinned test to make a change pass; change the pin deliberately and say why.
