# 17 October — local Qwen upgrade and staff pickup integration checkpoint

## 17 October continuation — R10 evidence and signed-in Qwen

### R11 — complete local native pipeline passed

Final local preservation checks on this same integrated source: root ran PHP 8.2 with its existing ZIP extension (`tests/run.php`: 3,102 passed / 0 failed), PHP 7.4 without ZIP (`tests/run.php`: 3,032 passed / 0 failed), and `node --test tests/orderboard-2-51.test.js tests/pickup-storefront-2-51.test.js` (55 passed / 0 failed). An initial PHP 7.4 invocation requested its absent ZIP module and emitted a startup warning; the clean no-ZIP invocation was then run explicitly. PHP 7.4 ZIP-dependent coverage is unavailable, not represented as passing. These checks plus R11 support preserving the approved local staff-pickup batch; they do not close unrelated broader frontend or launch gates.

The narrowly repaired worker is frozen at SHA-256 `CCBC0BB34108CCC6C0701ED8E6679266B44F82F5997E4EE67DA6696D937D22B1`. Root inspected its actual diff and independently passed PHP 8.2/PHP 7.4 syntax checks and worker-only `git diff --check`. The implementation lane additionally ran the existing `tests/test-square.php` with `tests/bootstrap.php`: 817 passed / 0 failed on each PHP version. Whole dirty-tree `git diff --check` still reports whitespace in other approved files; it is not represented as passing.

Fresh freeze: `C:/Codex/Temp/doughboss-local-first-runtime-20261017/runtime-r11-protocol-worker-freeze.json`, SHA-256 `1A08AF04B8BF6B5C91EDC16F5C74D141DA417C5963A1C29BF6BC3DA8E106DD7E`. Root independently compared R10/R11: exactly the one synthetic worker changed among 272 frozen files; all 238 runtime files and both harness files were unchanged.

Execution command (exit 0):

```powershell
& C:\Codex\Temp\doughboss-local-first-runtime-20261017\run-runtime-r8-gate.ps1 -ApprovedFrozenSource -FreezeManifestPath C:\Codex\Temp\doughboss-local-first-runtime-20261017\runtime-r11-protocol-worker-freeze.json -FreezeManifestSha256 1A08AF04B8BF6B5C91EDC16F5C74D141DA417C5963A1C29BF6BC3DA8E106DD7E -RunName runtime-r11-protocol-worker
```

Preserved `runtime-r11-protocol-worker/result.json` reports `Passed=true`, `SourceGatePassed=true`, `HashesStable=true`, `DatabaseStopped=true`, `PortStillListening=false`, `ForcedStop=false`, `HttpAndMailGuardsVerified=true`, `FreshDataset=true`, and `OldFixtureUntouched=true`. The run lasted 07:15:23–07:17:15 UTC on 17 October 2026. Root re-read the result and gate log and independently checked that port 33119 was closed.

The main fixture passed 3,593/3,593. Both web and approved-preorder races produced exactly one Order POST and one Payment POST with zero duplicate dispatches; approved release produced one fulfillment and zero duplicate kitchen hooks. Split-ledger races and all web/approved native crash/replay boundaries passed while retaining original identities. The full local native blocker is resolved, subject to the fresh frozen-R11 review disposition recorded below. This synthetic run made no real Square provider calls.

Independent frozen-R11 read-only Sol review returned `ship` with no blocking findings in this bounded fixture repair. It independently verified all 272 manifest entries, unchanged runtime/harness files, retained protocol/hash validation, truthful OPEN/PROPOSED pickup semantics, unchanged barriers/provider counts, crash/replay identities, and the completed native result. The R10 native-gate finding is dispositioned `fixed` for this frozen R11 candidate. Result SHA-256: `C8CA83B6A70BE4E8157158051C63AE5056B2E317A07B65052BCBB26355FEACD8`. Root inspected the actual diff and preserved run evidence before accepting this local repair. This is not a new Astra or whole-launch sign-off. Production release remains blocked by separate restore/boot and provider/financial/operational acceptance, including physical KDS installation. No new DoughBoss commit, push, deployment, payment activation, or Ads spending occurred.

The connected Studio session is signed in and shows Qwen ready. A fresh local connector call completed with `status=ok`, model alias `qwen3.8-27b:code`, and `elapsed_ms=38571`. Its bounded synthetic regression-review response drifted into an unrelated Python fixture draft; no generated code was applied or treated as acceptance. Credentials and sign-in codes are excluded from this handoff.

R10 (`runtime-r10-basis-fixture`, freeze SHA-256 `9CF22C0F950AAD5FE3EC93294B4A43F3F4E22ED882671D8B67545C7760FCD2AB`) passed all 3,593 main WordPress/MySQL assertions, the six-holds/six-refusals durable capacity race, split-ledger races, and ordinary Square web dispatch counts. The later approved-preorder race failed: `Approved Square race never received durable provider reference.` Overall `Passed=false`, `SourceGatePassed=false`, and `HashesStable=false`; the database stopped normally with no remaining listener or forced stop. These partial passes are not full native acceptance.

Source diagnosis found that the synthetic race worker projects Order POSTs without the retained protocol (defaulting to v1), although approved workflow-one snapshots require native pickup v2. Its paid Order and crash-replay assumptions also remain v1-oriented. Only a narrow repair of `tests/integration/square-race-worker.php` is authorized in this continuation. Preserve all production guards, exact provider identity/count assertions, barriers, and crash semantics. Model paid-but-uncollected pickup truthfully rather than marking collection just to satisfy verification.

Fresh independent frozen-R10 review returned `fix-first` solely for the mandatory native-race failure; it found no additional static material blocker. A new freeze, complete native gate, clean shutdown/hash parity, and appropriately scoped fresh review remain required. No DoughBoss commit, push, deployment, Square provider action, live payment activation, or Ads spending is claimed.

## Qwen Studio

Owner explicitly requested Qwen as principal coding-draft partner, Astra 6 assistance, and runtime/calls/tools plus Studio frontend/backend improvements before returning to DoughBoss.

The narrow Studio code-tab source is implemented and active. It removes duplicated newest-user context and inaccurate chunk-based token speed, adds 1K/2K/4K answer limits, gives coding the full workspace, improves phone controls, and rejects incomplete/empty/reasoning-only completions. Provider usage/timing and requested model alias are shown without exposing credentials. No weights, context limits, ports, permissions, cloud fallback or governor floors changed.

Proof: **34 focused Python tests**, **4 Node tests**, gateway Python syntax checks, Astra 6 source PASS and independent five-case regression run, subsequent mobile CSS PASS. Actual signed-in Studio coding requests returned; one reported 13.5 seconds/265 output tokens/24.0 generated tokens per second, the later post-activation request reported 34.2 seconds/363 output tokens/10.9 generated tokens per second. These are distinct requests, not a speed comparison or sustained throughput qualification. Root verified 390px visually and 320px without horizontal page/control overflow, then restored the browser viewport.

The old local hub/gateway process tree required a guarded restart, with no active generation/video job/GPU lock. Task-only restart did not replace/restore it; the exact existing hidden launcher restored the service and new gateway process identities. Task restart reliability remains unresolved. Existing standalone MCP clients may require their own supported reconnect to import updated helper code.

Source/evidence/rollback details: `C:/AI/gateway/docs/QWEN-STUDIO-20261017.md`; screenshots and source originals: `C:/Codex/Output/qwen-studio-upgrade-20261017/`. Existing broad video Studio suite initially failed nine tests at the fake model versus real Windows process boundary. A test-only isolation experiment reduced that to two lifecycle/cache failures, then was reverted byte-for-byte. Video source was untouched; full video-suite acceptance is NOT claimed.

## Approved pickup batch — source integrated, native runtime pending

Base HEAD `b2bcbc682265c63e2671e75333f4c66fab029fc8`, branch `codex/2.51-handoff-20261016`. Work remains uncommitted; preserve all existing changes. This entry supersedes earlier preparation-only status, not historical evidence.

- Plugin remains 2.51.0. Database 1.31.0 adds `preorder_workflow_version` default 0 with readiness-only admission. No historical backfill. Only genuinely new required-policy requests receive marker 1 internally.
- Staff-selected pickup uses server demand/options, exact timing/timezone/preparation, source-owned capacity, transactional source/approval binding and actual hold-linked capability expiry.
- New short-hold Stripe paths fail the unchanged 35-minute admission gate; historical already-authorized Stripe/v1 reconciliation remains separately preserved.
- Qwen supplied the initial normalizer draft; root found and Sol corrected its seconds-only UTC canonicalization defect. The staff UI now has server-slot selection, contact/stale-generation/detached-card/busy/offline/reconnect guards and provider-neutral copy. Historical requests remain reject-only.
- Root independently ran PHP 8.2 `tests/run.php`: **3,099 passed, 0 failed**; Node orderboard plus pickup-storefront: **55 passed, 0 failed**.
- Worker additionally reported PHP 7.4 **3,029/3,029**, 22 PHP lint checks, JS syntax and scoped whitespace checks. Those are worker evidence, not native database proof.
- Extra unchanged storefront-helpers suite: worker reported **109 passed / 1 failed**, photograph-fallback expectation at line 681. Neither the storefront source nor that test was changed. This remains unresolved evidence.

The fresh WordPress/MySQL fixture has substantial atomicity/concurrency/admission additions but is NOT yet executed on this working tree. Previous 3,437 runtime evidence does not cover it. Fresh current-source freeze, guarded disposable runtime, integrated review and evidence-led repairs remain required. No commit/push/deployment, merchant write, charge, customer message or payment activation is claimed.

### Subsequent actual R8 native attempt — failed capacity pre-gate

Root independently corrected an extra trailing character in the prepared runner's HEAD pin, then verified exact Git HEAD equality, exact authorized transformation from r7, and zero PowerShell parse errors. Final runner hash `4783807FDB07FA0D7DD7C034E5A3CCD0E5E569C32EA766626EDDE9A235EBCDE2`. Freeze manifest `runtime-r8-staff-pickup-freeze.json`, hash `C7A2E981D62598CC07C802391DB78D45E93149967D77A3C2CB4D4814B54DCA24`.

The guarded disposable attempt then actually ran. Result: `Passed=false`, failure `Pickup race expected six holds/six full refusals; got 12/0.` All twelve workers reported a hold. The main WordPress/MySQL integration fixture was not reached (`GateInvoked=false`). Setup emitted `SYNTHETIC_GUARDS_OK` and `PICKUP_PREPARE_OK`; the failed run did not reach final hash-stability verification. Clean owned-database shutdown succeeded: `DatabaseStopped=true`, `PortStillListening=false`, no forced stop or shutdown error.

Evidence is preserved at `C:/Codex/Temp/doughboss-local-first-runtime-20261017/runtime-r8-staff-pickup/`. This is a release-blocking observed capacity mismatch, not native acceptance. Sol is tracing source/fixture cause read-only before a precise repair; no unchanged retry has been launched. No production database, Square or live-site action occurred.

### Salt/mutex repair and actual R9 native outcome

Sol traced `hold_token()` calling `wp_salt('auth')` before the slot mutex inside the new transaction. WordPress can issue ordinary option reads there, establishing an earlier repeatable-read snapshot. The unchanged synthetic WordPress setup deliberately has no installed salt constants. Root authorized only moving token/hash calculation after slot `FOR UPDATE` and validation, before idempotency lookup, plus a source-order regression. No salt warming, capacity increase or race-fixture weakening was allowed.

Worker red-before-green proof: pre-repair **3,101 passed / 1 failed** at the new mutex-order assertion; repaired PHP 8.2 **3,102/3,102**, PHP 7.4 **3,032/3,032**, four lint checks passed. Root inspected the repaired ordering and created a fresh R9 freeze using the unchanged admitted R8 runner. R9 manifest hash `0FE63B6F8E411E499E5473A6A16FC6421A7FB213A208953087A7AB1B531D88E7`, 272 frozen files, 238 installed runtime files.

Actual R9 race: **12 workers, 6 successful holds, 6 full refusals, durable state verified**, provider HTTP blocked. The full native fixture then ran: **3,586 assertions, 3,581 passed, 5 failed**. All five failures belong to the timed approved Square paid-receipt positive: release did not succeed, expected pickup time/source-hold transfer were absent, and expected kitchen event/replay counts were one short. This is NOT integration acceptance. Failed evidence is preserved under `C:/Codex/Temp/doughboss-local-first-runtime-20261017/runtime-r9-salt-lock/`.

R9 cleanup: owned database stopped, no remaining listener, no forced stop or shutdown error. Main gate was invoked. The implementation lane is diagnosing the failed positive read-only; a fresh independent Sol xhigh source review is active on the same frozen source, with that lane told not to duplicate the payment-path diagnosis. No further code edit, unchanged retry, deployment, Square write or financial action is represented by this checkpoint.

## Offline restore staging — not accepted

R2: `FAILED / TERMINAL_HANDSHAKE`, guest rejected `set +o emacs`. Result hash `9933D0426933AB9F3A93E315865D0074F950DC2AC1A28D313FDFF46918E73FF0`; log hash `4A638951129CA7EFD4CB5688D0BB9AF5445AD0AAC43339194168BB07105FA7DF`.

R3: separately frozen/reviewed ship, 41 synthetic tests passed. Final admitted packet hash `B0DA7A16652E277F211D138551CCDE28997924317A0D585C15F4AFDBEF76C76B`. Root launched its exact caller once after non-executing working-directory/digest preflight failures. Actual result: exit 1, `FAILED / TERMINAL_HANDSHAKE`, `clean_closeout=false`, `post_pins_verified=true`; no extracted kernel/initramfs and no QEMU process afterward. `exec /bin/sh +i` was echoed, but the next output retained `~ #` and `ESC[6n`; the strict barrier withheld the long staging command.

R3 result hash `2B69DED2456BA2EFCBF2BEF9BBBEFDFBB52C6751579028C8C6A2DB105705F49E`; log hash `5F6348C4B6DEA402CFF75B0BC3577AC8E76115FC69EA3AB14B96148FA783A09D`. All failed artifacts remain preserved. No unchanged retry/R4, private restore, network, host shares, live mutation or Ads spending occurred. Public staging, installed boot, private WordPress restore and Square operational acceptance remain open.
