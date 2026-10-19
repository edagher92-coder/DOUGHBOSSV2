# 2026-10-17 — Restore boot preparation

The full launch still requires an actual private backup restore and WordPress boot. No such acceptance is claimed here.

## R14 assertion checkpoint

Rechecked the existing four-file R14 preparation against its recorded hashes. Before editing, copied and hash-matched all four into `C:/Codex/Temp/doughboss-linux-restore-20261014-9bd2e853/r14-preparation-red-20261017/`, preserving the exact 132-pass/one-failure checkpoint and its original freeze/plan.

Only the test `test_redraw_changed_unrelated_cell_rejects` changed: it now expects `EDIT_PREFIX_DISCREPANCY`, the existing earlier exact full-frame rejection. Its malformed input is still rejected; no production driver condition was relaxed or changed. The separate same-value/unrelated-cell revision test retains its later rejection category.

`python -B -m unittest -q test_public_linux_r14_driver` completed exit **0** in terminal session **70021**. The unchanged inventory was separately counted at **133 tests**, all passing. Driver SHA-256 remains `DE81AE69B6C7CC331AC660C7CE4B05CBE52B867943AC77668BD0B5D1A09C0343`; corrected test SHA-256 is `37EBC7894C83D1FBDD7928BCC11A8EC09D3C25F602E023BE53EDEE05F76F02AD`. No QEMU process was running after the tests. No VM/image/guest operation was admitted or launched by this preparation, and no large-base rehash was performed.

Machine-readable receipt: `C:/Codex/Temp/doughboss-linux-restore-20261014-9bd2e853/NATIVE-PUBLIC-LINUX-R14-CORRECTED-PREPARATION.json`. The original root-level R14 freeze records the original red revision, not the corrected test. Use the preserved red copy to reproduce that old checkpoint. The corrected preparation remains **operation NOT_ADMITTED**, with fresh full-driver review outstanding.

## Restore-boot route assessment

R14 `ACK_SUFFIX_ONLY` is deliberately a seven-character serial diagnostic that stops before Enter. It is not guest boot or restoration proof. Repeating it unchanged cannot complete the restore requirement.

The public installation receipt and current files establish existing official bootstrap inputs at `bootstrap-kernel/boot/vmlinuz-virt` and `bootstrap-kernel/boot/initramfs-virt`. One bounded read-only Terra lane is assessing whether a fresh installed-disk overlay can boot directly from separately pinned inputs, avoiding extlinux serial-menu editing. Its packet excludes private backups, native private directories, credentials, downloads, VM/image execution and edits.

A different topology must receive its own provenance/identity, exact offline argv, test-only paths, input checks, validation and operation admission. It must preserve no network, host shares, passthrough or production credentials. Existing public serial-driver admission cannot be relabelled as approval of a direct-boot operation. No boot route has been accepted merely because the files exist.

## Read-only feasibility findings and ISO provenance

The Terra lane found a genuine version mismatch: public ISO/bootstrap logs identify **6.18.52-0-virt**, while the successful installed-disk receipt identifies **6.18.56-0-virt**. The ISO pair must not be used as equivalent installed-kernel acceptance; live initramfs semantics and installed module compatibility are also unproven. No direct installed-disk boot is admitted from that pair.

Root closed the separate bootstrap extraction-provenance gap using only existing Windows `tar.exe`: first verified the official ISO SHA-256 `3AB424762AF704B2C2A9E57DF1DC37F982AF260071504D977F2FB96822E7130B`, then read exact members to memory and compared them with the existing files. Both matched byte size and SHA-256:

- `boot/vmlinuz-virt`: **12,608,512 bytes**, `40F620BC8C93D952E57DD8DFC0F94FCA1759D192A4FC4A260705D50CA378559C`.
- `boot/initramfs-virt`: **9,840,259 bytes**, `7EC8C3FA13403A4E0F08CCA6991230147B134198E9ED726964AFADDB52BE6EAF`.

No VM, QEMU image operation, extraction write, download/dependency installation, private-data read or network operation occurred. Receipt: `C:/Codex/Temp/doughboss-linux-restore-20261014-9bd2e853/PUBLIC-BOOTSTRAP-ISO-MEMBER-PARITY-20261017.json`.

## Prepared matching-kernel staging route — not executed

One explicitly selected Sol 6.1/high worker owns only two new public test-infrastructure files, `public_installed_boot_stage_r1.py` and its synthetic test file. Its assignment is preparation only: offline boot the official **live ISO** using its now-proven matching bootstrap pair, attach the existing public installed disk **read-only**, mount only its boot partition `ro,noload`, and copy the installed kernel/initramfs into a fresh, bounded raw test-output disk. No source-root mount, installed-code execution, credentials, private backup, networking, host share, passthrough or provider action is allowed.

Root owns the final identity/pinning, review and operation admission. The worker must not execute a VM/image tool or admit its own output. Fixed output slots, exact header schema, lengths/hashes, source/output device identity, read-only enforcement, clean shutdown and safe failure are acceptance requirements. Existing serial-menu admission is not reused. If safe execution cannot be prepared within the bounded scope, return the exact gap rather than inventing approval.

This route is intended to obtain the matching **6.18.56** installed artifacts before a separately reviewed installed-guest direct boot. It is not itself the private backup restore. Cost/route record: supported model `gpt-6.1-sol`, high, self-contained packet, exclusive two-file ownership; latest live allowance before dispatch **35% used / 65% remaining**, per-model cost unknown. No savings or qualification claim is made.

## Matching-kernel staging preparation checkpoint

The Sol 6.1 worker froze only its two owned files. Root inspected the rendered guest script/interfaces, matched both hashes and independently reran `python -B -m unittest -q test_public_installed_boot_stage_r1`: **16 tests passed**, exit **0**. No child-process, image or VM operations are implemented in this module. Direct execution was independently checked to reject with `NOT_ADMITTED` rather than launch anything.

- `public_installed_boot_stage_r1.py`: SHA-256 `7C0307D90A06B5E52DF8A78192CB9A33910CB825D2BF26DF8B0618CD44DA0D2F`.
- `test_public_installed_boot_stage_r1.py`: SHA-256 `6CEDDEFEB31A9E34604A2E5AE20293F5783511742E5CE41D3144D82D8167AEDF`.

Both are under `C:/Codex/Temp/doughboss-linux-restore-20261014-9bd2e853/`. The fixed guest script is 2,011 ASCII bytes; `render_script_disk()` returns a 4,096-byte padded input. `qemu_argv(root)` and `validate_argv()` define the offline/read-only-source topology; `render_manifest(root)` and `check_paths(root)` prepare identity/path checks. `parse_output(data, serial, qemu_exit)` requires exactly 64 MiB, an exact header/schema, bounds, zero unused padding, file hashes and unique ordered live/status/exit markers. It returns artifact bytes only, with no extraction write.

The pin authority was resolved without importing legacy code or trusting current files on first use: the actual R13 result's recorded root admission matches the current R13 freeze SHA-256 `C72615BA91414476D901EB21AB0D75F9AB00B14B40E7F492E72296CFEA54E987`; that freeze pins public R13 source SHA-256 `6827AF15C1E8B5CE259F94BF5799CF68611F20664D5FA3F4B59D578B27383A71`, which contains the accepted base/tool literal pins. The public result remains its original failed result (SHA-256 `8927E7E21A96AF46D1EFE8AB50A6FA499CD63F438E11EEA70DA412C877B79BD6`); this source-chain use does not relabel the failed operation as boot acceptance or retry it. Root receipt: `PUBLIC-INSTALLED-BOOT-STAGING-PIN-CHAIN-20261017.json`. Actual base/tool rehashes remain deferred to a new operation preflight.

**Status: PREPARED / NOT_ADMITTED.** Deliberate missing pieces remain root-owned: materializing exclusive fresh public input/output files, current before/after pin checks, bounded serial login/echo/command transport, owned-process identity/deadline/shutdown/log closeout, fresh independent operation review, and known-path output extraction only after accepted parsing. Actual live-ISO utility/topology compatibility is untested. The prepared module cannot itself perform a staging attempt, installed boot or private restore.

Next meaningful step is to prepare and verify that narrow execution/closeout path, freeze the exact staging profile, obtain its independent review and admit at most one named public-only staging operation. Do not substitute a passing serial-prefix diagnostic or prepared parser for real guest boot/restoration evidence. If extraction succeeds, verify the installed artifacts and define a separate installed-guest boot acceptance before introducing a private backup.

Existing live-site, provider, financial, staff and KDS gates remain in force. No live/backend activation, deployment, account change or Ads spend occurred in this preparation.

## Later 17 October — Bounded public staging executor, no VM launch

One Sol 6.1/high lane implemented only two new files in the existing private
test-infrastructure directory (no plugin source change):

- `public_installed_boot_stage_executor_r1.py`, final SHA-256
  `9C19B385AF589485D95C9164DFE68F23D978C70C46F674DF56A8A34102924797`.
- `test_public_installed_boot_stage_executor_r1.py`, final SHA-256
  `CC9B170895168DB8BF48F3E1FF4C70A719E90B03B2CE8B4B9D122A05D20A73D9`.

Root independently ran:
`python -B -m unittest -q test_public_installed_boot_stage_executor_r1 test_public_installed_boot_stage_r1`:
**31/31 passed, exit 0** (15 executor tests plus the unchanged 16 frozen
interface tests). Frozen interface/test hashes above remain unchanged.

One independent Sol xhigh reviewer rejected the initial 28-test revision:
its mock omitted Alpine's observed prompt DSR suffix; the executor sent a
redundant shutdown despite the frozen guest script already powering off;
and process exit could bypass stdout EOF/tail drainage. The correction
recognizes only the observed optional DSR, exactly verifies a short
`export TERM=dumb` before the long command, sends no second poweroff, and
requires both exit and EOF while retaining the bounded tail. New tests
model those actual lifecycle conditions. The same independent reviewer
re-read only the frozen correction and returned **ship**, with no remaining
blocker in that transport/test delta. This does not approve tool dependency
closure, VM execution, actual transport or restoration; the initial passing
test count was not operation acceptance.

An isolated tiny Python child on the actual Windows/Python 3.14.6 runtime
also confirmed that a nonblocking empty live stdout pipe raises
`BlockingIOError`, stdin triggers exact output, and the child exits 0 with
handles closed. That is host pipe evidence only, not QEMU transport proof.

Default executor CLI rejects with `NOT_ADMITTED`; a separate root-owned
in-memory admission must bind source/module/profile hashes and accepted
public input pins. Complete public QEMU DLL/firmware/config provenance and
additional pins remain a root admission obligation, not established by the
executable hash or a boolean assertion alone. No base rehash, VM launch,
staging image/output creation, private backup access or provider action
occurred. Root checked all six fixed operation outputs absent and no QEMU
process running after both the initial and corrected tests. Machine-readable preparation
receipt: `PUBLIC-INSTALLED-BOOT-EXECUTOR-R1-PREPARATION-20261017.json`.

Current source-package parity and the fresh live-public-asset mismatch are
separately recorded in `2026-10-17-CANDIDATE-ARCHIVE-PARITY.md`. These do not
close private restore, installed boot, live/core or launch acceptance.
Cost route: one implementation lane and one read-only reviewer, with a
delta-only re-review after correction; no Qwen or other model fan-out.
Live usage before corrected-revision review: 38% used / 62% remaining.
## 17 October, 16:25-16:35 Sydney - admitted public staging attempt failed

This later entry supersedes the preparation-only status above for this exact attempt, not the historical test claims. Root ran the admitted operation once from `C:/Codex/Temp/doughboss-linux-restore-20261014-9bd2e853`:

`C:/Users/edagh/AppData/Local/Python/bin/python.exe -B run_public_installed_boot_stage_r1_20261017.py b2314b2c917e19cf615e97b84d87ae5972de12fa78ab20a0f010a1a4c86c6861`

- Outcome: process exit **1**, result **FAILED / TIMEOUT**. `clean_closeout=false`; `post_pins_verified=true`; no post-pin error was reported. No kernel/initramfs artifacts were extracted. No QEMU processes remained at closeout.
- Exact observed failure: Alpine reached `localhost:~#` with one `ESC[6n` suffix. The short `export TERM=dumb` command echoed correctly, but the following prompt still included `ESC[6n`. The driver expected the normalized prompt to contain no such suffix, so it never sent the staging command and reached its 600-second deadline.
- The result's failed clean-closeout flag is retained. A zero process count proves cleanup only, not successful guest shutdown or staging. Do not reinterpret timeout as acceptance.
- Result receipt: `PUBLIC-INSTALLED-BOOT-STAGE-R1-RESULT.json`, SHA-256 `9D12651BD6E18E6340A3EFCC7572270131A5FA540F990636C2429E5AD9556DF0`.
- Serial log: `public-installed-boot-stage-r1-serial.log`, SHA-256 `8F83EB86CB4B58518A9273142207A81C47CDA651F25695A12C92FC09722884CE`.
- Authoritative operation plan SHA-256: `B2314B2C917E19CF615E97B84D87AE5972DE12FA78AB20A0F010A1A4C86C6861`. Final reviewed source/test bundle had **33 passing synthetic tests** before this attempt; no duplicate test run was used to relabel the actual failure.
- Tool provenance closure: official-checksum-matched portable 7-Zip files were used under the owner's exact unsigned-binary exception, without system installation. All 3,385 installed QEMU support files matched the approved installer reference. Closure receipt SHA-256 `623B89BA9C41457F84852D474EDEF003D83CBFE9AB70FB71F913AE6B0663293B`; the fixed x86 profile's two excluded SPARC ROMs were independently included in before/finally checks.

All failed outputs and original admitted sources are retained in the private temporary test directory. No unchanged retry was launched. The next infrastructure task is a narrow serial-normalization repair and regression based on the actual second-prompt trace, followed by fresh review and a new explicitly admitted operation with fresh output names. Do not loosen arbitrary terminal parsing or mutate the historical admitted packet.

This was public-only and offline: no private backup, network, host shares, WordPress restore, deployment, provider mutation, payment activation or Ads spending. Public staging, installed-guest boot, private WordPress restore/boot, exact live/core reconciliation and Square operational acceptance remain unproven. See `2026-10-17-APPROVED-PICKUP-WORKFLOW.md` for the separately approved staff-selected slot and reservation-linked payment expiry decision; it is not implemented.
