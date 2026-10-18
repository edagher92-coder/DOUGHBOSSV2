# Private R4 host runner — source-only acceptance

18 October 2026, Australia/Sydney. Canonical branch: `codex/2.51-handoff-20261016`; parent checkpoint `e41f6e771bc44f442c3762f115a40574661fe5af`.

## Outcome and limits

Implemented the protected host runner required by `2026-10-18-PRIVATE-R4-BOUNDARY-PREP.md`. The exact corrected source received **GPT-6.1 Sol / xhigh: SHIP — SOURCE ONLY**. This is not a private-operation admission, native restore, WordPress boot, production release or Square acceptance.

No private capsule or raw private log was opened or hashed, no QEMU process was started, and no database, provider, website or payment setting was changed. Only synthetic fixtures and public source/metadata were used. The full DoughBoss launch objective remains open.

## Implementation

- Fixed three-disk installed-kernel profile: fresh overlay `vda`, read-only public R4 pack `vdb`, read-only sealed capsule `vdc`; two CPUs, 2 GiB, single-thread TCG, no NIC, shares, forwarding, display, monitor or default devices.
- Pinned isolated caller is the authoritative entry. It byte-loads admitted source, checks current owner/SID and protected-directory/file identity tokens, verifies the QEMU inventory including its two excluded ROMs, and binds exact command lines and input pins. Direct `execute()` is not an independently admitted interface.
- Windows security helper verifies owner/SYSTEM-only ACLs and lossless decimal identity tokens. Its fixed PowerShell verifier accepts the measured two-link OS binary only, while private/source-file rules remain single-link. Actual read-only OS-binary verification passed. Synthetic real-Windows ACL positive/negative probes were preserved in the archive.
- Private phase is incrementally capped at 4,096 bytes before retention/log writes; whole boot log is capped at 4 MiB, deadline 600 seconds. Framing remains 15 commands, maximum 747 bytes, 5,998 bytes total, below the 768/7,000 limits; writes are paced four bytes per 0.2 seconds.
- Unexpected controls and late suffixes stop transmission before a subsequent write. Owned-child shutdown and closed failure reporting remain required.
- Both closed `STOPPED` and `ADMISSION_BOUND` records are handled, including only the exact optional observed kernel shutdown tail. The complete closed G056 record is retained; raw output is never relayed publicly.
- One guest admission helper and one frozen binder run, with optimization disabled. No preparation, SQL import, database start or application boot follows automatically.

## Defects found and corrected before acceptance

1. The CLI caught its own successful `SystemExit(0)` and reported failure. Final exit now occurs outside the exception handler.
2. The parser rejected the five-line successful binding plus shutdown tail. Both valid record counts now accept only that exact optional tail.
3. Late XOFF/control input could be retained and followed by further writes. The actual transport now checks controls and first-write barriers immediately.
4. A security-check failure after committing success could leave a durable success receipt while the caller reported failure. All success checks now precede the result commit, followed only by return. Result collisions reject and preserve prior bytes.

Two new terminal-record regressions were first run against the preceding implementation and failed (one error, one failure), then passed after repair. The original failed review receipts are retained rather than rewritten as passes.

Storage limit: a partial write or fsync failure means an untrusted receipt and fixed public failure. This does **not** promise durable failure-record recovery after an I/O failure. The complete caller's final host status is authoritative; an executor receipt alone is insufficient.

## Frozen source

Sources are under `C:\Codex\Temp\doughboss-linux-restore-20261014-9bd2e853` and embedded in the archive below.

| File | SHA-256 |
| --- | --- |
| `private_native_host_security_r1.py` | `449046baab86cb03288ecefa1b0e66534884bb7fdcac22520a27ca748a75e6aa` |
| `test_private_native_host_security_r1.py` | `2146c219e0afd6e42d9affaec086eaf7d7db6b25cbdbf2c69236c10d0e79b4ca` |
| `private_native_installed_r4_profile_r1.py` | `f57a41facd480f1fba8fd0bfdffcec68ffc2f67238daebe3ba5b8352c7f10193` |
| `private_native_installed_r4_executor_r1.py` | `c2d68fd5dd77408585c340d891827f172d6cdacb3bce750e78672e4e902f2a3a` |
| `run_private_native_installed_r4_r1.py` | `6d5fe9a8c49e3e10cd75e59b3faf8d9d6f3574b4831698de69c9ed539247e669` |
| `test_private_native_installed_r4_r1.py` | `3d01bd64d44693e29dbfdd5d40134bcf73b6a4b5423209aa00fe370c9c85aacd` |

## Proof

```powershell
& 'C:\Users\edagh\AppData\Local\Python\bin\python.exe' -I -S -B 'C:\Codex\Temp\doughboss-linux-restore-20261014-9bd2e853\test_private_native_host_security_r1.py'
& 'C:\Users\edagh\AppData\Local\Python\bin\python.exe' -I -S -B 'C:\Codex\Temp\doughboss-linux-restore-20261014-9bd2e853\test_private_native_installed_r4_r1.py'
```

Root: **12 + 15 = 27 tests passed**, zero failures/errors. Sol independently passed the unchanged 12-test security suite and final 15-test runner suite. Six source hashes and syntax parsing matched. Tests cover actual CLI wrapper logic, both closed outcomes/tails, positive mocked transport, late XOFF/suffix rejection before further writes, phase overflow before storage, child cleanup, durable closed failure, terminal commit ordering and collision preservation. They do not establish actual guest execution or operational restoration.

The accepted prior public native 81-test/nine-I/O gate and the unchanged PHP/JavaScript evidence were not rerun. Qwen was not called for this security-boundary batch; no model speed or savings claim is made.

## Archive and prerequisites

`HANDOFF/artifacts/private-r4-host-runner-source-r2-20261018.zip`: **132,589 bytes**, SHA-256 **`49ad7310f4b349f028d3757510e742b51ca6d01532b13ff0c1c74716d8f855e4`**. CRC and **22/22 payload size/hash checks** passed; 23 entries including the manifest. It contains public source/tests, fixed dependencies, public R4 pack/manifest, frozen candidates, review dispositions and synthetic proof. No VM disks, QEMU binaries, private payloads, credentials or raw private logs are included.

Preserve the prior prerequisite archives unchanged:

- `private-r4-boundary-source-r1-20261018.zip`, SHA `75df882c9f583fdd619a0b2a0b7e7af823427c64af007755444ecd3de70f4fa1`.
- `native-r4-installed-r2-reconciled-20261018.zip`, SHA `2a2730b56f81e63f0fde01f1ed9475774918c53891cda139a8fa66f3744e4318`.

The archive is a source checkpoint, not a self-contained admitted runtime. Existing pinned kernel/initramfs, base disk, QEMU closure and separate private capsule remain external prerequisites.

## Next exact gate

Prepare a fresh protected operation directory (never reuse the synthetic ACL probe or an old attempt), verify current metadata/owner tokens and exact source/input/tool pins, and exercise the caller boundary with QEMU/image execution forbidden. Freeze one exact diagnostic-only operation plan and obtain separate **Sol-6.1 operation SHIP** before one private run. Keep capsule hashing inside the admitted guest. Do not automatically proceed from binding to preparation/import/boot.

After that diagnostic, complete the remaining native preparation/import/WordPress restore acceptance, production backup/rollback and installed-code parity, controlled publication, and Revesby Square operational/financial/membership/staff/KDS gates. Bankstown/Roselands account connections remain deferred; preserve their replication pack. No full-launch requirement is marked complete by this source checkpoint.
