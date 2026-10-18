# Private R4 installed diagnostic — terminal closed result

18 October 2026, Australia/Sydney. Canonical branch `codex/2.51-handoff-20261016`, parent `5d4cce59cd0d0870bee492daeb6eb1cd197fa849`.

## Actual outcome

The one separately reviewed private R4 diagnostic ran and completed. Supported execution session **4165**, observed QEMU PID **43444**, caller **exit 0 / STOPPED_DIAGNOSTIC**. No automatic retry occurred. Root independently validated the protected closed result, its exact contract, output ACLs and post-run process state.

```text
category:          PREFIX_BINDING
stage:             NATIVE_PREFLIGHT
reason:            G056
structure_context: UNCLASSIFIED
token_kind:        UNCLASSIFIED
```

This proves the admitted host/guest diagnostic route worked and stopped closed. It does **not** identify the exact rejected SQL construct. Do not relax SQL grammar based on this result. Native preparation, database start, import, WordPress boot, restore acceptance, release acceptance and publication remain false.

## Admission and preflight evidence

- Fresh output directory `native-admission-private-r4-installed-r1` was created with owner/SYSTEM-only protected ACLs at creation. Existing permissions were not changed. It was distinct from the synthetic ACL probe and all previous attempts.
- Current owner/SID, output identity/ACL tokens and sealed-capsule metadata were verified. The capsule was not read, hashed or decompressed on the host; the admitted guest used it read-only.
- Actual inventory: **3,385 QEMU tool pins**, including both independently retained excluded ROMs; **eight public input pins** matched. The source freeze matched all six accepted files.
- Non-admitting machine preflight passed: actual caller byte loader plus executor admission/input verification, **3,400 admitted input pins**, eight allowed hidden PowerShell metadata children, zero forbidden-child attempts, one execution stub, output still empty. Approval was projected in memory only; persisted proposal remained NOT_ADMITTED.
- Fresh **GPT-6.1 Sol / xhigh** operation verdict: **SHIP — ONE DIAGNOSTIC ATTEMPT ONLY**. It approved exactly two activation-field changes, not restoration or publication.

Pins:

| Artifact | SHA-256 |
| --- | --- |
| Local proposal | `125ab1d24b0076df072256bbcc39ec8d6ba1b9e39e5b95922ecf10ee56ef7cae` |
| Materialized operation | `541de04940bb1d7f8b032569de74a50df9172ee4877185fc1dd9852b21dad70c` |
| Non-admitting preflight source | `a046e22c5e6a290567c23aef7ebad2d10db5c8846b2ca1a7b97e83c4a5b7fdf3` |
| Completed preflight receipt | `f8316ca2fea3773d5b56404d5942347a4c332555663420e6cfa0220b7f48d312` |
| Protected executor result | `5342c5382ff172571971af5c46b817ecc1c8627f162fa04b98e5d218bdd124b6` |

Root verified the proposal-to-operation structural diff contained only `operation_admission` and `independent_operation_review`. Exact plan files remain local; they are not included in the public evidence archive.

## Post-run proof and preservation

- The complete caller returned its closed success status after its final tool-closure check. An executor receipt alone was not used as acceptance.
- Admitted executor post-input verification passed. Its successful diagnostic path completed its base/overlay checks before committing the result.
- Root verified the protected result's exact shape, binding enums and false preparation/database/restore/release flags without relaying raw output.
- Root verified owner/SYSTEM ACLs on **all three output files** and matched **six frozen source hashes**.
- **Zero QEMU or image-tool processes** remained after completion. Terminal overlay, protected serial log and result are preserved locally; do not reuse the operation directory or relabel it as another attempt.
- Serial metadata length: **31,619 bytes**. Root did not read or hash the raw serial log. Directory-listing size was not used as a liveness or failure verdict; direct metadata was used while the file was open.

## Public evidence archive

`HANDOFF/artifacts/private-r4-installed-r1-closed-20261018.zip`: **5,828 bytes**, SHA-256 **`043afb9bf4edb0919ff187f84cdc57f9472686bb415b35ac267bb4489fdb0465`**. CRC and **6/6 payload size/hash checks** passed; seven entries including the manifest.

Contains the public preflight script and closed preflight/review/activation/readback/final-state records. No runtime plan, capsule, backup payload, overlay, credentials or raw serial log is included. Reuse the prior reviewed source archive and accepted public R4 evidence; no unchanged PHP/JavaScript or 81-test/nine-I/O gate was rerun.

## Next action, not a retry authorization

Trace the public R4 observer and its synthetic tests to determine why the structural result remained UNCLASSIFIED. Distinguish an observer/interface defect from an unsupported SQL construct; do not infer either without evidence. Any changed source/public pack or later private operation needs its own frozen validation and applicable operation gate. Do not rerun this terminal attempt.

Read-only public-source scout follow-up found that UNCLASSIFIED is an intentional fallback, not proof of a specific defect. `native_prefix_structure_r4.py:37-55` requires an exact module/function/base facade before installing its observer. `native_g056_structure_r1.py:119-154` additionally requires exact error identity/context and one of two exact traceback-code sequences; cursor/token shape guards can also return unknown. The outer R3 reason extractor is less structurally specific. Thus the outer G056 does not prove that any one of these stricter observer checks passed. No private data was used for this analysis.

The next useful public-only check is the complete real module-loading/preparation-to-prefix path with synthetic G056 fixtures, not a new private run or a speculative grammar patch. An intentionally mutated synthetic exception chain can demonstrate the fallback mechanism, but cannot identify the private cause and must not be presented as doing so. Preserve all frozen files and terminal artifacts while diagnosing that distinction.

The full launch objective remains open: native restore/import/boot, fresh production backup/rollback and installed candidate parity, controlled publication, and Revesby Square provider/financial/loyalty/gift-card/staff/KDS acceptance. Bankstown/Roselands connections remain deferred and account-separated.
