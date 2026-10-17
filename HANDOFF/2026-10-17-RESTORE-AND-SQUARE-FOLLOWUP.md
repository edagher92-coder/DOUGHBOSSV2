# 17 October 2026 — restore and installed Square follow-up

Continuation of the complete launch objective, not a production release. Start from pushed checkpoint `46acbf06927ff6b1f2a615f9fdc1687b87193bb8` on `codex/2.51-handoff-20261016`; preserve the earlier accepted checks and protected material. Read `2026-10-17-REVESBY-READINESS.md` first.

## Installed WordPress Square status (read only)

The authenticated Operations Dashboard reports **test** environment, **Configured, not yet verified**, no recorded successful Square sync, Revesby mapped-but-unverified and Bankstown/Roselands unmapped. The loaded Square-dashboard asset is version **2.49.0**; that is asset-version evidence, not complete installed-source parity.

The actual configured Square API version is **2026-09-16**. This supersedes any assumption that the site's current saved version is the candidate default `2025-01-23`. Some canonical candidate catalogue preparation/readback policy paths are specifically fixed to `2025-01-23`; reconcile and test the version policy deliberately before an import. Do not silently downgrade the setting or loosen admission guards.

The existing **Read Square locations access** control was invoked once. It returned **Square locations could not be observed safely. No partial observation fallback is shown.** The cause is not established from the UI; **do not claim the configured API version caused it**. There was no repeat request, configuration/mapping save, credential extraction, payment or provider-data mutation. Merchant ownership, scopes and actual credential/location binding remain unverified. The signed-in production Square catalogue is not proof the website's sandbox credentials are bound to that merchant.

Minimized local evidence: `C:/Codex/Output/doughboss-revesby-readback-20261017/wordpress-square-readiness-followup.json`. No raw credential, nonce, token or customer record belongs in this handoff.

## Local Qwen use

The new R10 synthetic drafting call returned in 10,805 ms on `qwen3.8-27b:code`. Its DEC glyph table was incorrect for five of eight requested entries and was rejected (l/t/u were correct; q/k/x/m/j were incorrect). Correct SO/SI/erase notes were independently checked against the primary [xterm controls](https://invisible-island.net/xterm/ctlseqs/ctlseqs.html) and [ncurses character table](https://invisible-island.net/ncurses/man/curs_addch.3x.html). Do not copy the rejected table or count responsiveness as agentic coding qualification. No model configuration, resource guard or authority was changed.

### Source-policy cross-check

The canonical source keeps the fixed catalogue API policy in `includes/class-doughboss-square-catalogue-preparation.php`, `includes/class-doughboss-square-catalogue-review.php` and the catalogue target validator in `public/js/doughboss-square-dashboard.js`. In contrast, the location-observation validator accepts a valid date pin rather than demanding that fixed catalogue version; `DoughBoss_Square::profile_context()` also performs calendar-date validation and separate settings/shop/credential consistency checks. The generic browser error can cover a non-success response, JSON parsing or response-schema rejection. Therefore the installed version difference alone does not identify the locations failure. Preserve both gates until a deliberate version-policy reconciliation and diagnostic readback are validated; do not retry the provider read blindly.

## Additional supervised Qwen use

A second synthetic test-design draft returned successfully from the approved dedicated local connector: `qwen3.8-27b:code`, 41,647 ms. No cloud cross-check, credential, customer data or provider operation was included. Local evidence: `C:/Codex/Output/doughboss-local-first-20261017/qwen-square-policy-synthetic-draft.json`.

Useful ideas retained for subsequent source verification: distinguish date syntax from fixed catalogue policy; preserve saved configuration and immutable historical attempts; test configuration drift and endpoint failures separately. Rejected: treating HTTP 200 alone as capability verification, inferring a version cause from a generic access failure, or assuming a locations response supplies the stored API-version readback. Generated PHP was not applied. This is advisory/test-design evidence, not executed coverage or integration acceptance.

## R10 frozen review result — not admitted

Root independently matched the R10 source, tests, plan and freeze hashes/sizes. The existing 115-test suite passed, but fresh read-only Sol high review returned **fix-first**: raw capture checked event arrival after physical Tab without requiring the preceding confirmed-empty pipe fence to be after Tab. The reviewer reproduced a false fresh-capture receipt with Tab at 102.2, arrival at 102.2005 and an empty fence at 102.1995. Root confirmed the missing fence condition in the source. R10 was not executed. Its frozen four-file packet remains preserved.

R11 preparation is limited to repairing that freshness condition and adding the exact adversarial regression plus boundary tests; Space, exact full-menu/cursor gate, Tab and five-second raw observation remain the only prospective behavior. No kernel boot, private restore or release is admitted by this preparation.

## R11 repaired candidate — preparation evidence

The exact R10 stale-fence scenario was reproduced before the repair in a new R11 test copy. R11 adds one strict condition before counting raw output: `event.empty_fence_at > boot.phase_started`. A straddling or equal-time fence terminates the attempt without counting the bytes; rejected bytes are retained in the authoritative log. No quiet skip or retry was introduced.

Worker: 118/118 tests, 9.023 s. Root independently reran `python -B -m unittest -q test_public_linux_r11_driver`: **118/118**, 8.417 s. This retains 115 existing tests and adds exactly three race/boundary regressions. Root inspected the source/test delta and matched the final four file hashes:

- Freeze: `fcbf0bc3a691a0154c56064a2a9f12340d34aef27d6c066b411c4f26e2060218`.
- Driver: `a02f30337cc0541dbbdff40b341148a5dd0938588ecf2eebef0598bab7126336`.
- Tests: `e3e1f57784d5ba67cd32cb7aff74ef2bebe8ea47b30acabf3cade0e49a406bd0`.
- Plan: `73392db2ac59dc445cc1ca15d738a4a8407330a2397018b86b13bd6b4143a03b`.

The packet is under `C:/Codex/Temp/doughboss-linux-restore-20261014-9bd2e853`. Preparation leaves the raw-edit operation, FULL, private restore and release not admitted. A fresh focused Sol review is required before root can admit a single raw-edit observation.

### R11 fresh review and exact admission

Fresh read-only Sol high review returned **ship**, independently passing 118 tests in 8.834 s. It verified that the timestamp is recorded after the physical paced Tab write/flush, the pipe-empty fence has retained reader provenance, and all three boundary cases are checked before accepted-byte counting. Root accepted this bounded repair, not a full restore/release.

Root recorded `NATIVE-PUBLIC-LINUX-R11-ROOT-ADMISSION.json` and admitted exactly one `OBSERVE_EDIT_RAW_ONLY` run against the frozen hash above. Only Space after the fresh countdown and Tab after the exact menu/cursor are allowed, followed by five seconds raw capture and mandatory owned closeout. Network, host shares, private inputs, further keys, kernel boot and release remain outside this packet. The conservative real-reader fence timing is an explicit runtime risk, not a reason to weaken the gate or retry blindly.

### R11 actual isolated observation — passed limited acceptance

Exactly one operation completed with `state=RAW_EDIT_OBSERVATION_ONLY_NOT_BOOT_ACCEPTANCE`, mode `OBSERVE_EDIT_RAW_ONLY`, `launchCount=1`. The raw serial log contains 2,499 bytes; the post-Tab portion is 547 bytes with SHA-256 `833fb7f044e35d6c4ea0fc165494dc03b4da9df920f2b8cc48a16a6942b83cb6`. The log was explicitly closed/fsynced, all 34 final input pins matched, both base/overlay image checks exited zero, `terminalFailures=[]`, and terminal readback confirmed zero remaining owned processes/listeners.

- Result: `NATIVE-ADMISSION-PUBLIC-LINUX-R11-OBSERVE-EDIT-RAW-RESULT.json`, SHA-256 `00cd34b6ee3a81e1a2124a18a23afdde42b4e0027920df91d0230135addcb832`.
- Serial log: `native-admission-public-linux-r11-observe-edit-raw-serial.log`, SHA-256 `5a76a9b0cb0198409fd4607abbe1ea8e185f4380e90381b8e325434bdee82386`.

The actual edit output includes a new literal `ESC[0;1;36;40m` prompt style and `.linux vmlinuz-virt ... initrd=initramfs-virt` command ordering, rather than the previous synthetic edit-line layout. It was captured opaquely, not accepted as a decoded screen or executed command. Preserve this trace as the offline input for the next reviewed decoder/boot packet. No noapic, Enter, login, userland tests, private backup, WordPress, deployment or payment activation occurred. Boot/restore/release acceptance remain false.

## Next R12 preparation and Qwen test draft

R12 is a separate local-only preparation using the pinned real R11 edit trace; no R12 VM operation is admitted. The root packet initially misstated the suffix length/cursor: `b' noapic'` is **seven** bytes, so `(19,5)` advances to `(19,12)`, not `(19,11)`. The worker caught this before creating R12 files; root confirmed with deterministic Python and corrected the packet. Preserve the literal suffix and independently derived cursor, not the erroneous numeric instruction.

An additional synthetic Qwen test draft returned on `qwen3.8-27b:code` in 33,246 ms. It is saved at `C:/Codex/Output/doughboss-local-first-20261017/qwen-r12-synthetic-test-draft.json` with that input correction. Its substitute toy-driver code and numeric assertion were not applied or counted as proof. Only test-class ideas already verified against the actual driver may be reused. R12 remains subject to actual trace replay, root inspection/tests, fresh frozen review and a distinct exact operation admission.

## Restore continuation

R9's complete public raw trace is the fixed offline decoder input. R10 preparation is bounded to a new decoder/test/plan/freeze packet and a prospective Space-to-exact-menu-to-Tab raw-edit observation; it does not admit kernel boot, private data, database/WordPress restore, deployment or payment activation. Append actual tests/review/runtime results here after finishing; do not substitute preparation for execution.
