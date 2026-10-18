# R18 — current candidate native application/database gate

18 October 2026, Australia/Sydney. Source `9f37ccf77670b0175049e02c9896f28912f7488c`, branch `codex/2.51-handoff-20261016`. This advances exact-current-source local acceptance after the loyalty opt-in repair; it is not publication or private-backup restoration.

## Scope and frozen runner

Outcome: test the updated candidate in the existing fresh synthetic WordPress/MariaDB pipeline. Non-goals: no private backup/VM operation, provider call, production credential/configuration change or deployment. Only a separately named temporary runner, generated freeze/evidence, fresh package and these handoffs are new; no application source changed in this batch.

The previous runner remains unchanged at SHA-256 `4783807FDB07FA0D7DD7C034E5A3CCD0E5E569C32EA766626EDDE9A235EBCDE2`. Its new separately named copy differs, after newline normalization/terminal whitespace, only in one HEAD literal, two helper/self filenames and the default run name. Root exact transformation check passed; zero PowerShell parse errors. A Terra read-only lane independently confirmed the contract. Its no-process description of freeze-only mode excludes ordinary diagnostic Git processes; no database/WordPress worker started during freeze creation.

New runner: `C:/Codex/Temp/doughboss-local-first-runtime-20261017/run-runtime-20261018-loyalty-r1-gate.ps1`, SHA-256 `579376BBBA2B76513C0268964EA015AF0FE13CC299CE1A7BAF14C77D5478A369`.
Fresh freeze: `runtime-r18-loyalty-opt-in-freeze.json`, SHA-256 `9BDA89249293AF13B82EA4DEBED1574D6DBA0F7FF5A4AA9C0FCC59F25A0CEEA3`. All **273 source entries**, including **239 runtime entries**, and both harness pins matched before execution. The safety helper remains unchanged at `81E37CE9AD898112D105F545DB149B8BE3B29957228267509CE34C590960BF69`.

Fresh explicit GPT-6.1 Sol high review returned **SHIP_ONE_SYNTHETIC_RUN**. It independently checked the source/harness pins, HEAD/branch, source guards, absent run directory, free loopback port and available memory. The exact admitted command ran once:

```powershell
& 'C:/Codex/Temp/doughboss-local-first-runtime-20261017/run-runtime-20261018-loyalty-r1-gate.ps1' -ApprovedFrozenSource -FreezeManifestPath 'C:/Codex/Temp/doughboss-local-first-runtime-20261017/runtime-r18-loyalty-opt-in-freeze.json' -FreezeManifestSha256 '9BDA89249293AF13B82EA4DEBED1574D6DBA0F7FF5A4AA9C0FCC59F25A0CEEA3' -RunName runtime-r18-loyalty-opt-in
```

## Actual result — exit 0

Session 92026 completed between 01:40:20 and 01:42:23 UTC. Evidence remains in `C:/Codex/Temp/doughboss-local-first-runtime-20261017/runtime-r18-loyalty-opt-in/`.

- **3,593/3,593 installed WordPress/MySQL assertions**, zero failures.
- **239/239 installed plugin runtime files** match. Root independently rehashed all 273 source files and all 239 installed files afterward: zero mismatches; result HEAD/freeze identity also match.
- Twelve-worker capacity race: **six holds / six full refusals**, durable state verified.
- Ordinary web and approved-preorder races each produced **one synthetic Order POST, one synthetic Payment POST, zero duplicate dispatches**. Approved release produced one fulfillment and zero duplicate kitchen hooks. Split-ledger and ordinary/approved crash/replay markers retained original identities.
- HTTP/mail guards verified. Database PID 47428 stopped normally; direct postcheck found no process at that PID and no listener on 33119. No forced stop or shutdown error.

Result SHA-256: `884A7D9F16A5183D93AF4007745692472BB8963BA5648C4DC42E7A262BF2BC6C`.
Gate log SHA-256: `9C1FB08C7ADBE1CFB06BE7CAC31226CE288B16B1DC05C939E97AACCAC41A2233`.
Machine-readable root summary: `artifacts/native-loyalty-r18-local-acceptance-20261018.json`.

The runner directs fresh data exclusively into the new named test tree and preserves old evidence; its `OldFixtureUntouched` flag is not a separate byte-for-byte audit of old database files. No real Square request follows from the synthetic POST counts. This is new synthetic application/database evidence, not restored production-data fidelity or private WordPress restore/boot.

## Current package pair

New plugin: `C:/Codex/Output/doughboss-release-candidate-20261018-9f37ccf/doughboss-2.51.0-9f37ccf.zip`, **239 files**, 5,688,238 bytes, SHA-256 `3ba72970dbe8dbbefa9e55906199e3b32feccc9685dd62a0d9003866280bc28f`. Existing `build-zip.php` and `validate-zip.php` both exited 0 and checked current-tree bytes.

Unchanged theme retained: `C:/Codex/Output/doughboss-release-candidate-20261018-d1ef237/doughboss-final-d1ef237.zip`, **27 files**, 42,110 bytes, SHA-256 `2d9c932193afff24227aac417ff39224e510bbc933bd6c38a7e33dd02ac26454`. Fresh `validate-theme-zip.php` exited 0 against current theme source. Rebuilding an unchanged theme was unnecessary. The native gate skips themes; this is byte validation, not browser rendering.

Commands used existing PHP 8.2 at `C:/Codex/Temp/doughboss-portable-php-20261012-c1d4232b/php82/php.exe`:

```text
php scripts/build-zip.php <new-plugin-path-above>
php scripts/validate-zip.php <new-plugin-path-above>
php scripts/validate-theme-zip.php <retained-theme-path-above>
```

## Final local gate and remaining release requirements

Fresh explicit **GPT-6.1 Sol / high** final review returned **SHIP_CURRENT_LOCAL_NATIVE_AND_PACKAGE_EVIDENCE_ONLY**. It independently checked actual receipts/logs, all 273 source and 239 installed pins, clean process/port shutdown, both package hashes/lengths, and both existing archive validators (exit 0). It found no blocking discrepancy and did not rerun the runtime. This new freeze supersedes older exact-candidate database coverage for the current plugin, while preserving prior receipts unchanged. Use this new plugin, not the pre-loyalty ZIP, if a later controlled release is admitted.

Required private-backup restore/application boot, fresh production backup/rollback, installed whole-tree parity, signed-out/device performance and Revesby real provider/financial/member/staff/KDS acceptance remain open. The pending SESSION_CONTROL approval is not supplied by this run. The private R4 attempt is still terminal and frozen. No orders, payments or Ads were activated; other-shop connections remain deferred.

## Preserved evidence bundle

`artifacts/native-r18-loyalty-evidence-20261018.zip`: **41,827 bytes**, SHA-256 `12f7190b3f6d2ea545debc940b4c005b90786caaddd4e780c747d419b7367123`. Root verified all **9/9** member sizes and SHA-256 hashes against their actual source files after creation. It contains the original/new runner, safety helper, fresh source freeze, terminal result, capacity receipt, installed parity receipt, synthetic gate log and accepted root summary. No database directory, WordPress configuration/passwords, setup log, production backup, private serial/capsule, provider credentials or executable binary is included. All prior evidence remains untouched.
