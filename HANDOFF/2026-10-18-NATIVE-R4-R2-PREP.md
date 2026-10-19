# Native R4 installed-kernel transport repair (R2)

Date: 18 October 2026, Australia/Sydney.

## Preparation scope and state (historical checkpoint)

This is the public-only prerequisite for the native backup admission tests. It is not a private WordPress restore, release acceptance, provider test or deployment. The failed R1 overlay/log/receipt remain terminal and unchanged; the seven leaked clusters were recorded, not repaired.

The R2 sources are in `C:\Codex\Temp\doughboss-linux-restore-20261014-9bd2e853`. The draft operation is still `NOT_ADMITTED` / `PENDING`; no R2 runtime output or VM has been created at this checkpoint.

| Frozen source | SHA-256 |
| --- | --- |
| `public_native_installed_r4_profile_r2.py` | `4f1a7c6e890edca4240ad141adbf9d05abac3e4ac8671638b29f54d0f57a7193` |
| `public_native_installed_r4_executor_r2.py` | `44df62d481d7614e73e7a98288a9d6c4caa664801d1d26c8238d17cb47d85b4a` |
| `run_public_native_installed_r4_r2.py` | `97218392fc52544e39049d2f0aa0c5a30ddc882b52bb40fba466e61723e0fdc9` |
| `test_public_native_installed_r4_r2.py` | `00c517ee4b0ffb670095ca55c67be69fa2c98079fe1de7a3eeb53e21f61826eb` |

Current draft plan `PUBLIC-NATIVE-INSTALLED-R4-R2-OPERATION.json`: `fd1b7c9bf87a89fc8a88ea51d55f5ee5f52da5083d483c455369d0ca9b6fcb69`.

## Changes and defects caught

- Replace the 5,700-byte single echoed command with 11 ordered frames, at most 690 ASCII bytes each and 6,391 aggregate wire bytes. Retain the 768-byte frame and 7,000-byte aggregate caps, paced writes, strict echo/control-byte rejection and owned-process cleanup.
- Exclusively create the staged payload; check the SHA of the actual base64 ASCII file before decoding and then verify the decoded bootstrap. The bootstrap remains byte-identical to the accepted R1 script (`6f9e5117d716788347870f01876ebb30157e676d39c28473519bbc8fa1d70c43`).
- Restore complete pre/post tool inventory checks, excluded-ROM identity/hash checks and fail-closed directory traversal. Write durable failure evidence without replacing the original operational error with a later inventory error.
- The first fresh GPT-6.1 Sol gate returned FIX-FIRST: a late prior-frame suffix could be read after the barrier and before the next write. Root moved the barrier after the first send-side read and before any next-frame byte. No guest, topology, input or resource-policy change was needed.
- Root also corrected synthetic-fixture EOF and prompt assumptions; those failures were test-fixture failures, not guest-runtime results.
- The repaired transport received fresh source/operation SHIP, but the admitted caller then stopped at `BOUND_SIZE` before invoking the executor: its small-file reader was incorrectly used for the 1.3 GiB base. The original admitted plan (`a115ae580a6f090efd28f09dae4074fe326c7de8f5189d0065ee0a2dc9f2d984`) and root-labelled pre-VM failure receipt are preserved under `PUBLIC-NATIVE-INSTALLED-R4-R2-PREVM-FAIL1*`. No VM or runtime output was created. Required inputs now use the existing streaming digest with exact hash and declared-size checks; the 16 MiB limit remains on source/plan reads.

## Local evidence

Command, from the isolated folder:

```powershell
& 'C:\Users\edagh\AppData\Local\Python\bin\python.exe' -I -S -B test_public_native_installed_r4_r2.py -v
```

Final result: **14 tests passed**. The real `_run` mocked transport regression covers immediate and delayed XOFF, zero bytes of the next frame in the delayed case, and owned-child cleanup. Its in-memory negative control restores the old barrier and produces the expected single delayed-case failure with zero errors. No file or subprocess is created by that negative control. The added actual-main regression uses a file larger than 16 MiB and rejects incorrect size/hash before its mock execution boundary.

Root separately loaded all six dependency modules with process creation forbidden, checked the full 3,385-file public tool closure and nine input pins, and confirmed fresh output paths. The one expected `POST_OPERATION_TOOL_CLOSURE_FAILED:RuntimeError` line belongs to the intentional error-preservation regression.

After the streaming correction, root exercised the full actual caller through real executor admission and verification with only the execution function replaced, virtual admission in memory, and process creation forbidden. Result: `FULL_CALLER_TO_EXECUTOR_BOUNDARY_OK`, **3,398/3,398 pins verified**, pre/post tool closure passed, no runtime outputs and no VM. The disk plan remained unadmitted.

## Required next proof

A fresh GPT-6.1 Sol streaming-caller/exact-operation review is pending; earlier SHIP does not cover the changed caller. Only after SHIP may root promote the two admission fields, recheck the frozen inputs and run one fresh R2 public-only attempt. The actual guest requirement remains **81 tests plus nine Linux I/O checks**, clean owned closeout and post-pin/image validation. None of those runtime results is claimed here.

Private backup restore/WordPress boot, production backup/rollback and byte parity, controlled publication, Revesby provider/financial acceptance and physical KDS checks remain open. The separately prepared plugin/theme upload candidates are not deployed by this work.

## Subsequent terminal run and read-only reconciliation

The streaming-caller correction received fresh GPT-6.1 Sol SOURCE SHIP and exact-operation SHIP. Root promoted only the two admission fields; the admitted plan hash is `a54ae36359101b3880aae9619474b13570879eaa7460c868f049450e088c3981`.

The owned R2 VM (PID 39220, caller session 24102) booted, completed every frame, ran **81 tests in 40.571 seconds with OK**, and emitted the exact nine-check public I/O PASS JSON ending `ROOT_RESTORED`. All five final success markers are present. It shut down and no QEMU process remained. The executor's post-pin checks passed and both base/overlay image checks were OK.

However, its terminal receipt correctly remains **FAILED / SERIAL_TEST_REPORT**: the frozen parser incorrectly applied unittest skip detection to the whole boot log and matched this kernel message before public admission: `Calibrating delay loop (skipped)`. This is not a skipped unittest. The original receipt and all operation sources remain unchanged.

| Terminal evidence | SHA-256 |
| --- | --- |
| `public-native-installed-r4-r2-serial.log` (31,487 bytes) | `30d2657bf3338469cdbc422b3c5bb9f4b34fa22676614fcb96b57a639ba2d30e` |
| `PUBLIC-NATIVE-INSTALLED-R4-R2-RESULT.json` | `71983c516cb5050fbadb31af621c7b66aa4c5ad8cffd8a0e965fb526272f4298` |
| New `public_native_r4_result_reconcile_r1.py` | `c1e5b1b5073e1d167f9fcf37ce6a3731d8c5810c735d893099cb340e8c8d9dc3` |
| New `test_public_native_r4_result_reconcile_r1.py` | `ffb702637fc8513e4af9b6e1ce4e0cc0e64fe031abc7cb3d70a95d6691f9721f` |

The new read-only replay module bounds the log, requires the exact unique public-admission JSON, rejects reserved result/failure records before that boundary, then delegates the entire admitted segment to the unchanged frozen parser. It does not rewrite the log or rerun the VM. Six synthetic tests passed, including the old-parser negative control and rejection of real skipped tests, wrong counts, duplicate/reordered/missing markers, failed I/O, invalid admission and misplaced results. Root's hash-bound replay of the actual log returned 81 tests / nine I/O checks / ROOT_RESTORED with no private input or preparation executed.

Fresh GPT-6.1 Sol assurance of that parser correction and evidence sufficiency is pending. No reconciled acceptance receipt has yet been issued at this documentation checkpoint. Do not reinterpret the original executor receipt as a pass.

## Final public-gate disposition

Fresh GPT-6.1 Sol returned **SOURCE SHIP / EVIDENCE SUFFICIENT** after inspecting the actual source, log, receipt and pinned operation. Root issued the separate `ROOT-NATIVE-R4-R2-RECONCILED-ACCEPTANCE.json` (`c96579b22aa724865f9f16bf615564a4c380fb00f835c98708d6f1455742a9ea`). It records only `public_native_validation_gate_accepted: true`; native/full acceptance, private restore, release and deployment remain false. The original failed receipt remains immutable. No second VM run occurred for the parser correction.

Reproducible public evidence archive: `HANDOFF/artifacts/native-r4-installed-r2-reconciled-20261018.zip`, **452,157 bytes**, SHA-256 `2a2730b56f81e63f0fde01f1ed9475774918c53891cda139a8fa66f3744e4318`. ZIP CRC and **21/21 payload size/hash checks** passed; 22 entries include the manifest. It contains the exact operation sources/tests/public tar and closure receipt, prior public boot receipt, operation and pre-VM failure evidence, full serial log, original terminal receipt, replay correction/tests and separate root reconciliation. It contains no disks, executable tool binaries, production credentials or private backup data.

The next restore step is private native-prefix diagnosis/preparation under its existing admission controls, followed by isolated WordPress restore/boot. This accepted public gate is not permission to skip those checks or publish the prepared plugin/theme.
