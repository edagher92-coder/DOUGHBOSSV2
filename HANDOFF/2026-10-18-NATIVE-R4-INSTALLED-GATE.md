# 18 October 2026 — public native R4 installed-kernel gate

## Latest disposition — R2 public gate reconciled

The subsequent R2 run completed **81/81 guest tests and 9/9 Linux I/O checks**, `ROOT_RESTORED`, clean guest/owned-process shutdown, unchanged post-run pins and healthy base/overlay image checks. Its executor receipt remains FAILED because the frozen whole-boot parser mistook the kernel's delay-calibration `(skipped)` text for a skipped test. A separately tested read-only boundary correction and fresh GPT-6.1 Sol SOURCE SHIP / EVIDENCE SUFFICIENT support an additional root public-gate reconciliation; no original receipt was rewritten and no VM was rerun for that correction.

See `2026-10-18-NATIVE-R4-R2-PREP.md` for exact hashes, defects, 14 host tests, six parser regressions, the immutable failed receipt and separate reconciled acceptance. Archive: `HANDOFF/artifacts/native-r4-installed-r2-reconciled-20261018.zip`, SHA-256 `2a2730b56f81e63f0fde01f1ed9475774918c53891cda139a8fa66f3744e4318` (21/21 payload hashes verified). Private WordPress restore, full native/restore acceptance, production publication and Revesby operational acceptance remain open. The R1 history below is preserved, not the current public-gate disposition.

## Scope and source

Run the previously frozen R4 public test gate through the successfully verified installed-kernel topology. The original R4 attempt and all accepted source inputs remain unchanged. The earlier separate 22-check actual-Linux filesystem probe already passed and is carried forward rather than repeated.

New source under `C:/Codex/Temp/doughboss-linux-restore-20261014-9bd2e853/`:

| File | SHA-256 |
| --- | --- |
| `public_native_installed_r4_profile_r1.py` | `d10877f7c66771cb6440d949bb9351fe2838c14145ac0672c8494c6f50a35251` |
| `public_native_installed_r4_executor_r1.py` | `f589c108accdb278e0e583a20a14a0beeb09c402869909c19aaa41a1cab9c060` |
| `run_public_native_installed_r4_r1.py` | `e1cbc74fd8b0e767856abdfce8c7f262fecd848d4e9142654e1c3e98cfe99cb1` |
| `test_public_native_installed_r4_r1.py` | `3170b123a03d0e3caf687d894a95f21b8e5437052d417d52882cc1d6e8b3d11d` |

One Terra lane built the four owned files. Root caught and corrected through that lane the guest isolated-mode import failure, insufficiently observed success counts, missing transitive helper bindings, guest post-check gaps and premature preparation acceptance flags before the final freeze. Qwen remained outside this batch's critical path after its previous repeated timeouts; no Qwen code contribution is claimed here.

Root reran the focused **8/8 host tests** and independently exercised the real isolated source-byte loader with process creation prohibited: all five dependencies loaded in the correct order; frozen generic helper identities matched; exact argv validation passed; all output paths were absent. The serial command is **5700 bytes**, **285.2 seconds** at the unchanged four-byte/0.2-second pacing. Fresh explicit GPT-6.1 Sol high source review returned **SHIP** on the exact hashes above. Host tests and source review do not prove guest execution.

## Exact operation admission

Root's full read-only preflight passed all **3385 tool payload size/mtime/hash pins**, exact tool inventory/exclusions, all **9 profile inputs** and **6 source bindings**, exact argv/profile/output-name equality and absent outputs. No child process was started by preflight. Available host RAM was 11.32 GiB and no QEMU process existed then.

The draft operation plan SHA-256 was `09107cb0089680579068e78af293348497333555939515bc02a6a7b5443556c3`. A separate GPT-6.1 Sol operation-binding gate returned **SHIP**. Root changed only the two reviewed admission/review values. Final admitted plan `PUBLIC-NATIVE-INSTALLED-R4-R1-OPERATION.json` SHA-256 is `ecdb68d8377a4726ef26fecc3d23209d7591ba270ef76130eeff55ac122350f9`.

Limits: one attempt, 600 seconds, 2 virtual CPUs/2 GiB, single-thread TCG/qemu64, no NIC/display/monitor/shares/default devices or user configuration, fresh overlay as `vda`, unchanged 358400-byte public tar as read-only `vdb`, 4 MiB serial ceiling, strict handshake/echo/result validation and owned-handle closeout. No private backup, database service, WordPress, provider or live-site action is attached.

## Terminal attempt — do not restart

Started using trusted Python 3.14 `-I -S -B`, exact caller and admitted-plan hash. Unified execution session **96676** and owned QEMU PID **39556** (created **2026-10-18 09:51:51 Australia/Sydney**) are now terminal. Caller exit **1**, `SERIAL_ECHO`. Independent readback found **zero** remaining QEMU/qemu-img processes. Do not restart or reuse this admission/overlay.

The expected 5700-byte echo arrived as 5701 bytes: the exact command plus one control byte **19 (XOFF)** at index 5700. Removing control bytes reconstructs the expected command, but the executor deliberately did not strip or accept the unexpected byte. No guest test result is proven. This is evidence of a terminal-stream boundary failure, not evidence that the frozen 81 tests or nine I/O checks failed.

The executor did not create a failure receipt when `_run` raised. Root therefore completed a separately labelled failure capture in `PUBLIC-NATIVE-INSTALLED-R4-R1-RESULT.json`, without claiming executor-native acceptance. Root verified all six source bindings, nine profile inputs, all 3385 tool hashes and the exact inventory after closeout. The image backing chain remained valid; base image check exit **0**. The retained failed overlay check exited **3**, with **seven leaked clusters** and no check errors. No repair was attempted. The overlay is terminal evidence and is never an input to the replacement attempt.

Retained log: **30117 bytes**, SHA-256 `af01a1398dfae9e83bd18dd89b8f4c183dae27a2792b9f83e8d68087f8ae00e6`. Private preparation, restore, release and public native-gate acceptance remain false. A bounded R2 source repair is assigned: short individually echoed frames instead of the single long line, unchanged pacing and guest payload, plus durable failed-result and post-pin capture. No R2 operation is admitted by this handoff.

Acceptance requires actual **81 tests with zero failures/errors/skips**, the exact **9-check I/O PASS ending ROOT_RESTORED**, unchanged public source/device/network boundaries, no database process/listener, clean shutdown, image checks and final input-pin equality. Even PASS will establish this public gate only; private preparation/import/WordPress restore, production rollback/publication and Revesby operational/financial/device acceptance remain separate.

Terminal R1 source/receipt archive: `HANDOFF/artifacts/native-r4-installed-r1-terminal-20261018.zip`, SHA-256 `fd88eb449b28c56d9ce7687c7d3d0d55f3bf8c1b623532973bccd6c29de5a7d3`, 442121 bytes. ZIP CRC and all **15/15 payload size/hash checks** passed (16 total entries including manifest). It contains public source/tests/tar/closure, the prior boot receipt, admitted plan, root-captured failure and log. It excludes the failed overlay, base disk, tool binaries and every private backup/configuration artifact.
