# Public R4 bound-snapshot integration evidence

18 October 2026, Australia/Sydney. Canonical branch `codex/2.51-handoff-20261016`, parent `65efb5d723f8015b960ea7dcaddfba4c4219b314`.

## Why this check exists

The admitted private R4 attempt is terminal at `PREFIX_BINDING / NATIVE_PREFLIGHT / G056`, with both structural fields UNCLASSIFIED. See `2026-10-18-PRIVATE-R4-INSTALLED-R1-RESULT.md`. That result did not establish whether the observer's real snapshot-loading/writer path could carry a valid classification, nor which private construct failed. This checkpoint addresses only that public integration question.

No private capsule, raw log or protected operation directory was accessed. No VM, database, private bind, preparation, provider action or deployment occurred. No SQL grammar, diagnostic protocol or existing frozen source changed.

## Minimal new source

`C:\Codex\Temp\doughboss-linux-restore-20261014-9bd2e853\test_native_prefix_bound_snapshot_r1.py`

SHA-256: **`4a296e9a2430a059e73eea1df9d25993cc7884b81502e6fa48a370c847ea9251`**.

The test uses the actual R4 binder's `module_snapshot()` and `diagnostic_snapshot()` plus preparation `load_dependencies()`. It then calls the loaded prefix adapter with entirely synthetic SQL. For final serialization it exercises the actual `binding.main()` and host contract parser, but replaces `binding.bind` with the captured synthetic error. Thus it does not execute the full private binding operation or its filesystem/environment checks.

Ten public dependency/fixture/contract byte pins are checked, using the accepted public manifest plus the fixed host-contract hash; byte and import-state assertions also run after the tests.

## Verified results

```powershell
& 'C:\Users\edagh\AppData\Local\Python\bin\python.exe' -I -S -B 'C:\Codex\Temp\doughboss-linux-restore-20261014-9bd2e853\test_native_prefix_bound_snapshot_r1.py'
```

Root: **4 test methods passed**, zero failures/errors, Python 3.14.6. Independent **GPT-6.1 Sol / high**: same hash, same command, **4 passed, exit 0**, verdict **SHIP — PUBLIC SOURCE/EVIDENCE CLAIM ONLY**.

The tested cases establish:

- The accepted success tuple is unchanged through the exact snapshot loader.
- All six combinations of `INSERT_VALUE` / `CREATE_DEFAULT` and `WORD` / `IDENT` / `SYMBOL` survive that loader, the actual closed writer and the host parser. Tested synthetic SQL markers do not appear in serialized output.
- The supported table-local `utf8mb4` session-control fixture is accepted.
- Synthetic `utf8`, `utf8mb3` and `latin1` session-control fixtures yield outer `NATIVE_PREFLIGHT / G056` with intentional `UNCLASSIFIED / UNCLASSIFIED`. The serialized record does not expose the control text or charset strings.

The public source explains this boundary: `restore_preparer_native_r1.py` can reach the rejecting literal parser while reading a session control, while `native_g056_structure_r1.py` recognizes only the two explicit insert/default traceback shapes. This supplies a concrete synthetic route to the observed closed tuple; **it does not prove that the private backup took that route**. Do not infer the private SQL token, variable, charset, table or value from these fixtures.

No Linux-runtime equivalence, database restoration, application boot or release acceptance is claimed. The prior accepted native/Linux/PHP/JavaScript evidence was not rerun unchanged.

## Reproducible source checkpoint

`HANDOFF/artifacts/public-bound-snapshot-r1-20261018.zip`: **3,768 bytes**, SHA-256 **`c760056157efda45066fe54a700d53a630d2d655b6cc3d97ecc1a693b6620234`**. CRC and **2/2 payload size/hash checks** passed; three entries including the manifest. Contains only the new public test and closed result record.

Reuse the public pack/manifest and host contract preserved in the prior source archives, including `private-r4-host-runner-source-r2-20261018.zip` (SHA `49ad7310f4b349f028d3757510e742b51ca6d01532b13ff0c1c74716d8f855e4`). Do not substitute unpinned local source or private data for the synthetic fixtures.

## Requested next boundary

A narrowly scoped owner question has been submitted: permit a closed `SESSION_CONTROL` diagnostic context, retaining only WORD/IDENT/SYMBOL kinds and excluding SQL text, values, names and credentials. **No approval had been received at this checkpoint.** This test gate does not authorize that protocol extension or another private attempt.

If approved, prepare the smallest separately versioned observer/closed-contract candidate, preserve the frozen R4 sources and terminal attempt, validate it with public fixtures, and obtain fresh Sol-6.1 source/operation gates before any private diagnostic. Do not relax SQL grammar or progress automatically to import/boot. The full DoughBoss launch objective remains open; the Revesby evidence guide and required human/provider/device gates remain in force.
