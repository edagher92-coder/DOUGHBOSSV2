# Disabled legacy rewards: accepted local-source repair

19 October 2026, Australia/Sydney. Base pushed source: `93acde47370cd9a033136ecdfaafe9b0e5003759`, branch `codex/2.51-handoff-20261016`.

## Confirmed gap and narrow repair

The Members hub says sign-up is closed. The legacy rewards shortcode nevertheless rendered a waitlist/sign-in form while `loyalty_enabled` was false, and both registered public `request_link()` and `magic_link()` handlers lacked that feature gate. The former could enter the user/token/email path; the latter could consume a token and establish a session. This was identified by source inspection, not by calling a live endpoint or using customer data.

The local candidate changes only:

- `includes/class-doughboss-loyalty.php`: disabled shortcode returns an escaped informational message and Members & Events link before wallet/authentication work. Both sign-in handlers redirect to the existing rewards destination with `db_loyalty=unavailable` before processing account, token or email data. The replaced disabled waitlist branch is removed. Enabled legacy logic, historical records, provider code and settings are otherwise unchanged.
- `tests/test-zz-loyalty-disabled.php`: 20 assertions cover absent/default and explicitly false flags, the informational renderer, forged success-query copy, and refusal of both handler paths before database work or mail. Guarded test shims follow the existing runner pattern. The test restores original options, request globals and database-global presence.

There is no Square customer-login implementation, provider call, migration, account deletion, token revocation, live setting change or deployment in this repair.

## Local evidence

- PHP 8.2.33 `-n -l` on both changed PHP files: no syntax errors.
- Independent-order regression command: `php -n -r 'require "tests/bootstrap.php"; require "tests/test-zz-loyalty-disabled.php"; echo json_encode($GLOBALS["doughboss_test_results"]); exit($GLOBALS["doughboss_test_results"]["failed"] ? 1 : 0);'`: **20 passed, 0 failed**, exit 0.
- Existing full `php -n tests/run.php`: **3,252 passed, 0 failed**, exit 0 after the final test-shim correction. Existing synthetic catering-mail failure diagnostics are expected fixtures.
- `git -c core.whitespace=cr-at-eol diff --check`: exit 0.
- Frozen runtime SHA-256: `D49B92B48A27C44EE186F3F8F3AF24149B64F7679E1870B0640CF24C79CB0FC9`.
- Final test SHA-256: `CBA2F6702D6C1B3CC98F6461830185002D41966C54BA8BB6DBD3A42F166D006D`.

## Review and continuation

One explicit GPT-6.1 Sol/xhigh read-only review was requested in existing authorised thread `01a14f2c-2b6c-7520-aadc-d6c64071dc4d`, title `Verify premium menu candidate`. Scope: these two files, authentication boundary, enabled-path preservation and test validity. The reviewer was notified of the final test-only shim correction; runtime bytes did not change during review.

The repository-access issue was resolved. GPT-6.1 Sol/xhigh returned **SHIP_LOCAL_SOURCE_ONLY** with no actionable findings after inspecting the actual production diff and complete final test file. The reviewer confirmed that both guards precede account/token/email/session work, the disabled shortcode precedes account rendering, and the enabled paths and stored records are preserved. The reviewer did not independently rerun PHP; the 20/20 focused assertions and 3,252/3,252 full-suite results above are lead-run evidence. This accepts source behavior, not installed bytes or live WordPress authentication.

The accepted guard source, regression test and this receipt are isolated into one local-only checkpoint; the five unreviewed phone-layout files are not included. The reviewed runtime and test SHA-256 values above were rechecked immediately before staging and remain exact. No unchanged tests were repeated solely for this checkpoint. No remote push, new package or deployment is part of this action. The previous accepted plugin archive at `doughboss-premium-candidate-20261019-90984cd` does not contain this repair; its existing receipt remains historical and exact for that revision. Theme runtime is unchanged. Do not call the repair pushed, installed or live without its separate receipt.

The full premium readiness goal remains active. Verified full-menu photo coverage, current checkout/reload/failure acceptance, and the separate Square identity/consent/privacy, restore, provider and publication gates remain open. The protected untracked Qwen handoff remains untouched.
