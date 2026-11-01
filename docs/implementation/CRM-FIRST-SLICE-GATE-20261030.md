# CRM first-slice local gate — 30 October 2026

## Decision

**CRM source review: APPROVE. Local release gate: PASSED on one authorized retry. Plugin and theme archives: BUILT AND VALIDATED locally.**

No actionable security or correctness findings were identified in the bounded CRM change. The first declared release-script run stopped at an unchanged attribution oracle's raw-byte hash check. The lead restored that unchanged schema to its committed LF bytes without changing the fixture; one explicitly authorized retry then passed and built both archives. The first failure and its diagnosis are retained below. No runtime source repair was performed by this reviewer.

Reviewed checkout: `C:/Codex/.codex/worktrees/doughboss-crm-20261030`; branch `crm`; HEAD `07a34610eec29fd5ce3f8567d215885420b78462`. The working changes were unstaged. The reviewer changed only this gate document and created ignored validation logs under `dist/crm-gate-20261030/`. No commit, push, deployment, shared WordPress/MySQL fixture, provider call, account change, or external message was performed.

## Scope reviewed

The first slice is a manager-only, read-only catering enquiry inbox at `/management/?section=crm`, reusing the canonical catering records, statuses and query. It introduces no persistence, schema, dependency, public API, quote mutation, payment action or Telegram transport.

Reviewed the tracked diff in `includes/class-doughboss-catering.php`, `includes/class-doughboss-portals.php`, `includes/class-doughboss.php`, and `public/css/doughboss-portals.css`, together with untracked `includes/class-doughboss-crm.php` and `tests/test-crm.php`. Read the execution plan and Telegram reuse audit as scope and integration boundaries.

Evidence from source:

- The existing portal authenticates before rendering; `render_management()` retains its capability check and 403 response before output. CRM independently checks `manage_doughboss` or `manage_options` before reading records. Existing managers retain broad all-store authority; no sales-role or store-specific authorization is introduced.
- The section selector accepts only the exact string `crm`; other input renders the existing overview. CRM status, search and page are type-checked and bounded before querying, including explicit null values. Statuses use the existing allowlist, search is at most 200 bytes, and pages are 1–10000 with 20 records per page.
- The existing query uses `esc_like` plus prepared value placeholders for search/status/limit/offset. Count and list database errors produce an explicit error flag; CRM translates it into a generic unavailable message without disclosing SQL details.
- Rendered customer data and attributes are escaped. Quote display uses each record's saved three-letter currency and identifies amounts as enquiry quotes. It does not claim settlement or contact consent.
- The existing catering admin screen accepts `s`, matching the new enquiry-number search link. Viewing and filtering the new inbox adds no write, email or provider call.
- CSS is scoped to the CRM. Core loading places CRM after Catering; the overview's rendering path remains present.

## Exact validation and results

The process PATH was temporarily prefixed with `C:/Users/edagh/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin`. Node reported `v24.22.1`; the existing PHP executables reported 8.2.33 and 7.4.33. No environment setting was persisted and no dependency was installed.

Initial run from the named checkout (the identical command was later retried once after the diagnosed lead repair):

```powershell
& ./scripts/test-release.ps1 -PhpPath 'C:/Codex/Temp/doughboss-portable-php-20261012-c1d4232b/php82/php.exe' -ExtensionDir 'C:/Codex/Temp/doughboss-portable-php-20261012-c1d4232b/php82/ext' -OutputPath 'C:/Codex/.codex/worktrees/doughboss-crm-20261030/dist/crm-first-slice-20261030.zip' -ThemeOutputPath 'C:/Codex/.codex/worktrees/doughboss-crm-20261030/dist/crm-theme-first-slice-20261030.zip'
```

Initial result: **exit 1**, at `scripts/test-release.ps1:52`, first Node test file `doughboss-growth/tests/attribution.test.js`. Log: `dist/crm-gate-20261030/release-gate.log`.

| Completed check | Actual result |
| --- | --- |
| PHP 8.2 syntax | 237 files passed |
| PHP 8.2 suite | 4649 assertions passed; 0 failed |
| Node syntax | 39 files passed |
| First Node test file | 29 tests: 26 passed, 1 failed, 2 skipped |
| Remaining Node tests | Not reached by the script |
| Plugin and theme archive build/validation | Not reached; neither requested archive exists |

The two attribution test skips explicitly report missing local `web/node_modules/tsx/dist/cli.mjs`/exporter and `acorn` availability. They must not be reported as passes.

Separate compatibility command:

```powershell
& 'C:/Codex/Temp/doughboss-portable-php-20261012-c1d4232b/php74/php.exe' -n tests/run.php
```

Result: **exit 0; 4457 assertions passed, 0 failed**. Log: `dist/crm-gate-20261030/php74-suite.log`. The different assertion total is reported as observed; the suites contain PHP-version-dependent image checks.

`git diff --check` passed before the gate. The tests use WordPress function shims and a database recorder; these results are not WordPress/MySQL integration evidence.

## Initial release-gate blocker and resolved diagnosis

`doughboss-growth/tests/attribution.test.js:180–181` hashes the raw bytes of `web/src/lib/attribution-schema.ts` and compares them with `fixtures/attribution-cases.json`.

- Fixture `source_sha256`: `a415353dfe2447585edd3740c2b075652ae2632b8aafcc37c28a0eabf6b78abd`.
- Current schema checkout bytes: `90990166f1df7e96d9a918048812094b840827576a3b8e5ec70dd1a099a7ba79`, with 80 CRLF line endings.
- The same checkout bytes after CRLF-to-LF normalization: `a415353dfe2447585edd3740c2b075652ae2632b8aafcc37c28a0eabf6b78abd`.
- `git show HEAD:web/src/lib/attribution-schema.ts` bytes: the same `a415353d...` hash as the fixture.

Thus the oracle and committed schema agree; Windows checkout bytes differ only in newline representation. Regenerating the fixture against CRLF would obscure that cause. The integration owner independently verified the byte equivalence, restored this unchanged file to HEAD's LF bytes, and authorized one deliberate retry. The reviewer did not edit this unrelated file, change the fixture or weaken the test.

## Authorized retry and final artifacts

After the lead repaired the unchanged schema checkout bytes, the same release command above ran exactly once more with Node 24.22.1 and PHP 8.2.33. Both reserved archive paths and the retry log were confirmed absent before starting. Result: **exit 0**. Log: `dist/crm-gate-20261030/release-gate-retry.log`.

| Final retry check | Actual result |
| --- | --- |
| PHP syntax | 237 files passed |
| PHP 8.2 suite | 4649 assertions passed; 0 failed |
| JavaScript syntax | 39 files passed |
| Node suites | All 12 files completed successfully; aggregate 418 tests: 382 passed, 0 failed, 36 skipped |
| Plugin archive | Version 2.51.1; layout and current-tree bytes validated; 387 files |
| Theme archive | Version 1.8.1; layout and current-tree bytes validated; 27 files |
| `git diff --check` | Passed; Git printed its ordinary LF-to-CRLF checkout warning for the normalized unchanged schema |

The 36 skipped Node checks remain unverified. Reported reasons are missing Acorn, unavailable local TypeScript exporter runtime, absent `DBGR_CORE_SRC` configuration, and five consent inline-script tests reporting `php binary not available` through their own PATH lookup. PHP itself was available through its explicit executable path and passed the separate PHP suites. No dependencies or persistent PATH entries were added to eliminate skips. PHP 7.4 was not rerun because the repair changed no PHP bytes; its existing 4457/0 result remains applicable.

| Local artifact | Size in bytes | SHA-256 |
| --- | ---: | --- |
| `dist/crm-first-slice-20261030.zip` | 16001539 | `893655e8318cd024be5522d8ea7bab3412c3b476613b47f50d4ce30c3b86114a` |
| `dist/crm-theme-first-slice-20261030.zip` | 42329 | `de1651fc5670f30dee53b43f1c67fa41fa0e3c9bba93d8b70189296b8aec7495` |

The plugin builder includes only its runtime whitelist; this report and other planning documents are excluded. Updating this report therefore does not change the validated archive payload. The normalized schema has no content diff against HEAD; status can still show its checkout-byte/stat change. The reviewer did not stage, reset, refresh the index or touch the protected main checkout.

## Readiness and remaining acceptance

The local read-only CRM source and the two validated archives are ready for the next authorized acceptance stage. The declared local release gate has passed with the explicit skips above. Real WordPress route rendering, database errors against MySQL, role/browser behavior, keyboard and responsive acceptance, and enquiry-to-inbox integration remain unverified. No live homepage, Square merchant/invoice/payment, Telegram identity/transport/message or deployment acceptance is claimed.

This slice does not complete the future account/contact/task/consent CRM. Location/created-date/payment-leg cards described in the broader proposal are not in this bounded implementation. Persisted CRM, per-store sales roles and Telegram workflows remain explicitly deferred and require their own exact approved batches. The Telegram audit identifies Hermes as a reuse candidate; it does not establish a working DoughBoss connector or authorize sharing credentials or starting another bot consumer.

## Review Summary

| Severity | Count | Status |
|----------|-------|--------|
| CRITICAL | 0 | pass |
| HIGH | 0 | pass |
| MEDIUM | 0 | pass |
| LOW | 0 | pass |

Verdict: **APPROVE** for the bounded CRM source review. The independent local release gate **PASSED on the single authorized retry**, and both archives were built and validated. Explicit skips and runtime limitations remain; this verdict does not approve deployment.
