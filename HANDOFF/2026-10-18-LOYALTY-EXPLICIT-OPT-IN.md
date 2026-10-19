# Square Loyalty explicit opt-in — 18 October 2026

## Scope and reproduced defect

Base: `f54b984b7f8fafa70ea802474e0b0282927fff81` on `codex/2.51-handoff-20261016`.

Outcome: absent Square Loyalty accrual configuration stays off, consistent with the class's documented opt-in contract. Preserve explicit existing saved on/off choices. Non-goals: no programme enrolment, customer/provider calls, account configuration, persisted-option migration, payment activation or deployment. Files: three existing settings/runtime/admin files and the nearest existing loyalty test. Proof: regression first fails at the three mismatched defaults, passes after the correction on both existing PHP runtimes, and receives an independent Sol 6.1 source gate.

The original settings default was `1`, runtime fallback was `1`, and the admin checkbox treated an absent key as checked. Existing payment/commerce readiness checks still apply; this defect is not evidence that loyalty activity happened on production while commerce was disabled.

## Minimal correction

- `includes/class-doughboss-settings.php`: default `square_loyalty_accrual` to `0`.
- `includes/class-doughboss-square-loyalty.php`: match the fallback to `0`.
- `admin/class-doughboss-admin.php`: render the checkbox using the existing `DoughBoss_Square_Loyalty::enabled()` decision rather than a separate absent-means-checked expression.
- `tests/test-square-invoices.php`: nine assertions cover default, missing key, saved off/on representations and the admin's use of the shared decision. Restore the test's entire prior option store in `finally`.

No schema, option shape, API/wire contract, dependency or existing explicit saved value changed. No files outside this four-file implementation and its handoff/receipt were edited. The unrelated untracked Qwen handoff remains preserved.

## Actual local checks

Using existing portable runtimes under `C:/Codex/Temp/doughboss-portable-php-20261012-c1d4232b/`:

1. Added regression before the implementation and ran `php82/php.exe tests/run.php`: **3,114 assertions; 3,111 passed, 3 failed**, exit 1. Exactly the settings default, missing-key enabled decision and admin checkbox checks failed.
2. After the correction, `php82/php.exe tests/run.php`: **3,114/3,114**, exit 0.
3. After the correction, `php74/php.exe tests/run.php`: **3,044/3,044**, exit 0.
4. `php82/php.exe -l` and `php74/php.exe -l` on all four changed PHP files: **8/8 syntax checks**, exit 0. `git diff --check`: exit 0. Only the two changed loyalty-setting lines that acquired CRLF were mechanically normalized; no broad file reformat occurred.

The existing suites emit the expected synthetic failed-mail diagnostics for `CAT-SAVED-0041`; these do not represent production email or failing assertions. These are shim-based local tests, not real WordPress/database, Square, browser-rendered checkbox or live operational acceptance. No unchanged JavaScript suite was rerun.

Exact source hashes and machine-readable results: `artifacts/loyalty-opt-in-local-r1-20261018.json`.

## Qwen and gate

The existing named local connector was asked once for a synthetic regression-test draft, without secrets, customer data or provider access. It returned `paused` for `qwen3.8-27b:code`, stating that Qwen was paused while video renders. No draft was generated, no retry/model restart/cloud fallback occurred, and the resource guard was respected. This is connector-state evidence only; it is not independently verified video-process state or a model performance result. Root implemented and tested the correction.

Fresh explicit **GPT-6.1 Sol / high** review returned **SHIP_LOCAL_SOURCE_ONLY**, with no actionable defect. The reviewer verified all four source hashes/lengths, independently reproduced both passing PHP suites and four PHP 8.2 syntax checks, and checked the scoped diff. It verified that dependency loading precedes admin rendering and that explicit saved values override defaults. Root additionally ran all four PHP 7.4 syntax checks. The red-phase failures remain root-recorded evidence; the reviewer did not revert source to repeat them. No source bytes changed after this gate.

This verdict is source/local acceptance only. No deployment authority, real database/WordPress result, browser rendering or provider acceptance is conferred.

## Release consequences and remaining gates

Subsequent acceptance: `2026-10-18-NATIVE-R18-LOYALTY-CANDIDATE.md` records the new current-source freeze, successful 3593/3593 synthetic installed WordPress/MySQL gate, fresh plugin package and revalidated unchanged theme, with final Sol 6.1 acceptance. The following paragraph records what the code change invalidated before that subsequent gate; R18 now supplies current local coverage, not production/private-restore acceptance.

This change alters three runtime files, so the previous exact-byte database-source freeze and earlier upload ZIPs **do not cover the current candidate**. Do not upload those old archives as if they include this repair. After source acceptance, rerun the applicable isolated application/database gate against a new freeze and build/validate fresh release archives as part of the completed release batch. Preserve earlier passing receipts and frozen/terminal restore attempts without modification.

The private R4 diagnostic protocol extension remains pending owner response; no private operation was retried. Full restore/application boot, fresh production backup/rollback, installed whole-tree parity and controlled publication remain required. Square merchant/location/environment, approved programme/rules, provider lifecycle, financial and physical KDS acceptance remain separate and unproven by these tests. Other-shop connections stay deferred.
