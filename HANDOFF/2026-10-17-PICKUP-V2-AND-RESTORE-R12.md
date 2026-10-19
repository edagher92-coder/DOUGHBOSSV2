# 17 October 2026 — native pickup-v2 and restore R12

Baseline: `af8160977588c7fc8692423c9a5dd474ab0248cb` on `codex/2.51-handoff-20261016`. This is an in-progress local batch, not a production release or Square operational acceptance. Preserve prior accepted evidence and all protected/dirty work elsewhere.

## Confirmed owner policy

The owner selected: **Use the website's existing preparation timing** for Square kitchen tickets. Preserve the preparation timing with each new immutable order snapshot, so later settings changes cannot move its kitchen start or promised pickup time. The earlier confirmed policy also requires Square `SCHEDULED` pickup to use the exact website-promised time, including when the website labels the choice ASAP.

Only Revesby is in the current integration scope. Other merchant accounts remain separate and deferred. Ordering, payments, split checkout, Ads spending and unvalidated membership activation remain off. Physical KDS screens remain uninstalled; code or mocked tests cannot prove kitchen delivery.

## Local implementation scope

Add a distinct immutable native Orders protocol for new supported pickup attempts. Preserve protocol-absent legacy attempts, exact `square-orders-v1` behavior, hosted links' separate provider-DIGITAL contract and inert split behavior. The new contract must carry strict pickup time, recipient and captured preparation timing through projection, creation, paid reread/replay, recovery and approval release guards. Do not silently broaden the change to taxes, merchant bindings, hosted links or payment activation.

Use the existing pickup, order, Square and checkout service boundaries and existing tests. The saved preparation policy must agree with local order fire timing; do not derive already-promised orders from later mutable settings. No new dependencies or database tables are approved by this batch. Report unsupported or ambiguous native flows rather than fabricating pickup facts or silently falling back.

The source map was refreshed against the baseline. Important files: `includes/class-doughboss-square-orders.php`, `includes/class-doughboss-square.php`, `includes/class-doughboss-rest-controller.php`, `includes/class-doughboss-pickup.php`, `includes/class-doughboss-order.php`, `tests/test-square.php` and `tests/test-zz-checkout-pickup-2-51.php`.

Current official documentation: <https://developer.squareup.com/docs/orders-api/fulfillments> and <https://developer.squareup.com/docs/orders-api/create-orders>. It states that scheduled pickup becomes active at pickup time minus preparation duration; omission makes it active immediately. These current examples use `2026-09-16` and are **not** historical pinned-`2025-01-23` acceptance evidence. Reconcile version policy deliberately; do not silently downgrade configuration or relax guards.

### Historical pinned schema evidence

The official `square/connect-api-specification` repository contains release commit `f87c6371f29b1e5c290a4af23440d6d0923e8eaa`, dated `2025-01-23T15:17:33Z`, with the release merge subject explicitly naming `2025-01-23`. Root fetched `api.json` from that exact commit and extracted the OpenAPI `components.schemas` for fulfillment, pickup details, recipient, schedule type and state.

Source: <https://github.com/square/connect-api-specification/blob/f87c6371f29b1e5c290a4af23440d6d0923e8eaa/api.json>. Local minimized evidence: `C:/Codex/Output/doughboss-revesby-readback-20261017/pinned-square-2025-01-23-fulfillment-schemas.json`, SHA-256 `72a6500a8ecf943f54e3dc23693cf4c25ea1450cd5e9e48864a18ed065949f21`.

This supports the historical field nesting and enum shape; it does not establish account behavior, response normalization, kitchen timing or device delivery. The pinned schema limits recipient display name to 255 characters and phone to 17, correcting the root/Qwen draft's proposed 32-character phone limit. Current installed `2026-09-16` configuration still needs deliberate version-policy reconciliation and its own relevant evidence; no setting was changed.

The installed-version schema was also obtained from official release commit `0689c901bdfe1ff73f47aa28b17ccd6e0c40bf2d`, dated `2026-09-15T21:22:51Z`, whose merge names release `2026-09-16`. Its model names have changed to `Fulfillment`, `FulfillmentPickupDetails` and `FulfillmentRecipient`; extraction followed the actual Order/property references rather than treating missing old names as a semantic change.

Source: <https://github.com/square/connect-api-specification/blob/0689c901bdfe1ff73f47aa28b17ccd6e0c40bf2d/api.json>. Actual referenced schemas and enums are saved at `C:/Codex/Output/doughboss-revesby-readback-20261017/pinned-square-2026-09-16-fulfillment-schemas.json`, SHA-256 `658bcab47e6ac7d6ef4074436b6e1893b12814ea4481fe5b8d3dbd07a0aa82bb`.

The newer recipient phone limit is **18**, versus **17** in the older pin; display name is 255 in both. Pickup field keys and schedule/state enum values remain present. This is schema evidence, not proof of provider normalization or ticket delivery. New native-v2 attempts must retain their validated API version and fail closed on version drift; existing v1/catalogue policies remain unchanged. Do not infer that these schemas diagnose the installed locations-read failure.

## Qwen implementation contribution

The dedicated local connector returned a substantial pure-projection PHP draft on `qwen3.8-27b:code` in 34,374 ms using a bounded source excerpt. Local artifact: `C:/Codex/Output/doughboss-local-first-20261017/qwen-pickup-projection-implementation-draft.json`. Later investigation established that source display compression can alter excerpts even before `exec_command.output` reaches the caller; original-byte fidelity was not established for that earlier excerpt. Treat it as draft context, not authoritative source proof.

The draft is not accepted as written: pickup time, recipient, schedule type and preparation duration must be nested inside `pickup_details`; malformed optional phone values must not be silently omitted. Sol must inspect/correct the implementation and exercise the actual existing test harness. No generated stand-in driver or self-mocked result counts as repository evidence. No production/provider authority or acceptance was delegated.

## R12 isolated restore candidate

R11's actual public-only raw edit observation remains accepted separately. R12 prepares the next FULL public-only boot/test candidate using that authentic trace, with only the observed new SGR and exact complete frame/cursor/freshness gates. The seven-byte suffix `b' noapic'` moves cursor `(19,5)` to `(19,12)`.

Worker: **132/132** local tests, 20.588 s. Root independently reran `python -B -m unittest -q test_public_linux_r12_driver`: **132/132**, 20.154 s. These are synthetic/host-side tests, not guest or private-restore results. Root inspected the driver delta and independently matched the four hashes:

- Freeze: `ffe57f58f65d511f0579fe8131760df9d7e3f8991cf91182624016ea260dcf59`.
- Driver: `4c3f07b834db8f0ebc66ce16922d54c20bce5ec947bcc99e17a291ccae2b3319`.
- Tests: `e6ea1fe3cd288b8820d28bc0c92ae4fe9be1177f4eec2943bccf779fa2c81c70`.
- Plan: `531b71513182d6c48d3939620d61c1b6cf7caedea783443f2306b200a975e2e4`.

Four-file packet: `C:/Codex/Temp/doughboss-linux-restore-20261014-9bd2e853`. Frozen mode is FULL, but operation/full-mode admission remains NOT_ADMITTED. Fresh focused Sol xhigh review is pending. No R12 operation has run at this checkpoint. Root must record separate exact admission after review before a single invocation. Public boot/tests and private WordPress/database restore remain distinct gates; no private backup, network, host share, deployment or provider operation is authorized by this prepared packet.

## R12 actual full attempt — terminal failure, no retry

Root recorded a separate `NATIVE-PUBLIC-LINUX-R12-ROOT-ADMISSION.json` after the fresh Sol xhigh ship review (132/132 tests, 20.170 s), then invoked the exact frozen candidate once. It returned `FAILED_TERMINAL_NO_RETRY` with `failure=EDIT_NO_POST_WRITE_EMPTY_FENCE` before boot. The 2,500-byte log is the exact accepted 2,499-byte R11 trace followed by one space, the first suffix echo. No Enter, login, guest test or private restore acceptance is proved.

The log was closed/fsynced; both image checks exited zero; all 34 final input pins matched; owned process/listener counts are zero. The mandatory full-path qualifier was not reached, so `terminalFailures` also correctly reports `serialDrain:QUALIFIER_FINAL_COUNT`. Do not describe this as a clean guest shutdown or all-terminal-check success.

- Result: `NATIVE-ADMISSION-PUBLIC-LINUX-R12-NOAPIC-RESULT.json`, SHA-256 `3d3b9ee550242e62d8e4a954827615a8631c2596be75f4ca7779e39710ddb2ec`.
- Serial: `native-admission-public-linux-r12-noapic-serial.log`, SHA-256 `dcb0b6d1976232182edc17accd63920f4585d6eb6970d799b96a27548dc72e7c`.

The candidate requires a confirmed-empty pipe poll after completion of the input write, which can reject already-available legitimate echo. Root initially described seven per-byte writes with 20 ms pacing; that mechanism was an incorrect assumption. Astra's original-byte source inspection found one `stream.write(data)` followed by one flush, with the 20 ms polling delay in the reader. Diagnose that actual batch-write/phase-barrier contract, not the mistaken pacing description. A focused read-only Astra diagnosis is in progress; there is no permission to rerun R12 or remove freshness protection.

### Astra diagnosis and next transport contract

Astra returned **fix-first** for the frozen R12 candidate, with no code or operations performed. Root accepted its bounded recommendation: a single `OwnedSerial` transition handoff, using the existing writer, must complete pacing first, exclude the reader through read/event-enqueue, reject queued events or readable old pipe bytes, observe real nonblocking EAGAIN under that exclusion, then perform the one write/flush and publish an owned monotonic write receipt/epoch. Reader events capture their epoch at read time. Exact existing screen/cursor/revision/unknown-control gates remain mandatory; timeout clocks remain clocks, not causal acknowledgements.

This proves owned transport ordering and absence of already-queued/readable old bytes at the pre-write barrier. It does **not** prove why output was generated inside QEMU or exclude producer output delayed until after that barrier. A stronger authenticated causality claim is impossible without a guest acknowledgement protocol and must not be manufactured using sleeps or timestamps. The bounded known-public-guest test accepts the explicitly documented transport guarantee, not an invented stronger one.

R13 preparation is assigned to Sol in four new temp files, preserving every R12 artifact. It must exercise the actual handoff with deterministic immediate-echo-in-write/flush, queued/in-flight/OS-buffered stale data, pacing, epoch ownership/order, write/flush/EOF/queue/deadline failures and full closeout tests. No R13 operation is admitted. Fresh frozen review and a new exact root admission remain required after implementation/testing; Astra's diagnosis is not a release approval.

## Source inspection fidelity

The implementation worker found that large direct PHP source reads can be silently stripped by tool compression, even without a visible compression marker. Original-byte base64 reads decoded inside the orchestration cell, followed by small focused line slices, preserve patch anchors and exact conditions. Use that path for source/diff inspection and compare file hashes; do not mistake a stripped display for the actual code. Plain-language display transformations are for reading only, never patch text. The original request packet also contained the corrected suffix-length and writer-pacing assumptions documented above; model outputs must be judged against verified source and schemas, not those erroneous inputs.

## Per-item tax confirmation remains a human gate

Root prepared a frozen 43-item public-name/category/base-price review sheet from the already observed website menu: `C:/Codex/Output/doughboss-revesby-readback-20261017/revesby-item-tax-review.md`, SHA-256 `02d37fdc0b67198561f8ed5d7bfb7e2e395b03c7946889d1f1705cd2fe6b5672`. Every treatment is explicitly Pending. The owner was asked for the approved item treatments or completed sheet. No classification was inferred from food/drink names, no tax setting was saved and no GST was added to displayed prices. This is distinct from the existing all-item inclusive account setting and from the successful 43-item catalogue match.

## Owner inclusive-price confirmation — 17 October 2026

The owner replied **“inc of gst”** to the item-tax question. Preserve every website price as GST-inclusive wherever GST applies; never add GST on top. This confirms price presentation, not a GST-free item list or blanket taxable classification. The local 43-item review sheet now records this reply and retains Pending item treatments. Its earlier SHA above identifies the pre-reply sheet, not the updated file. No tax settings, catalogue prices or payment activation were changed.

## Fresh public commerce flags

Anonymous GET of `https://doughboss.com.au/wp-json/doughboss/v1/config` at `2026-10-17T02:54:15.7799026Z` confirmed `ordering_open=false`, `payments_enabled=false`, `payment_gateway=square`. Minimized local receipt: `C:/Codex/Output/doughboss-revesby-readback-20261017/live-commerce-flags-20261017.json`. This confirms only those public flags, not installed-source parity or provider acceptance. No live setting or provider write was made.

## Results to append after validation

### Root local verification and fresh reviews — 17 October 2026

The seven-file implementation is frozen locally pending independent review. Root independently ran `C:/Codex/Temp/doughboss-portable-php-20261012-c1d4232b/php82/php.exe tests/run.php`: **3,051 assertions, 3,051 passed, 0 failed**, exit 0. All seven changed PHP files pass that executable's syntax check; `git diff --check` passes. A fresh read-only Sol xhigh reviewer is examining the native-v2 contract. No provider or production action occurred.

Approved-preorder timing remains an explicit gap: the request creation path does not currently persist a supported timed promise. Conditional mapping and synthetic cache-consumption tests do not prove an approved timed request can be created and completed end-to-end. Do not fabricate stored promises or upgrade a v1 attempt to claim coverage.

Root prepared `C:/Codex/Temp/doughboss-local-first-runtime-20261017/run-runtime-r7-gate.ps1` from the preserved existing runner. Only the expected current HEAD, new runner self-pin name and default run name changed. PowerShell parsing passes. New freeze `runtime-r7-freeze.json` contains 272 source/test files, SHA-256 `CDCED5B205129064145842EF3BDA1815FF8654D712657574F65B9FE9729768E5`. Runner SHA-256 `D58C23396CFEA66B6EC3B64A44AE07F4DB425F3E0AE6451F9F40A0BF6E9A725B`; unchanged safety helper SHA-256 `81E37CE9AD898112D105F545DB149B8BE3B29957228267509CE34C590960BF69`. This is preparation only; R7 has not run. R6 evidence is preserved.

The updated local GST sheet SHA-256 is `CCE895BBCDCF9AF84FDD6C90A6691CA6295DFB856E775543A35167FCD6AC8D7D`. This binds the recorded owner reply and still-Pending classifications; it is not tax-setting or sale acceptance.

### R7 actual WordPress/database run — fix-first

Root invoked the exact `runtime-r7-freeze.json` once using the prepared R7 runner. The installed runtime matched all **238 plugin files** and the 12-worker pickup capacity race completed. The source fixture returned **3,134 assertions: 3,122 passed, 12 failed**. Every failure was a new native paid-pickup setup returning `doughboss_pay_order_binding`; later assertions dependent on those setup results did not run. This is not partial pickup acceptance.

The result records `DatabaseStopped=true`, `PortStillListening=false`, no shutdown failure and no forced stop; HTTP/mail guards were verified. `HashesStable=false` means the runner did not reach its final parity check after the failed gate, not proof that source bytes changed. Preserve the complete failed R7 evidence; never overwrite or retry it unchanged.

Root traced the concrete omitted persistence seam to `includes/class-doughboss-payment-attempts.php`: `bind_square_order()` still gates on v1 and projects with the default v1 protocol; `hold_square_order_verification()` also gates on v1. The earlier seven-file implementation lease missed this necessary runtime boundary. Fresh review was notified. Repair must retain exact under-lock metadata/snapshot/API-version identity and fail-closed unknown/legacy behavior, not merely replace a string or weaken binding. A fresh frozen run is required after repair; no live/provider write occurred.

### Root persistence repair and R8 fixture correction

Fresh Sol xhigh returned fix-first for the two concrete persistence omissions described above. The prior worker could not resume through collaboration (`agent thread limit reached`), so root repaired the necessary eighth changed file, `includes/class-doughboss-payment-attempts.php`, and extended the existing integration fixture. Binding now allows only v1 or strict pinned v2, checks the saved protocol/version under the existing locks, includes the pin in the explicit immutable metadata keys and reprojects using the retained protocol. The durable paid-verification hold also recognises strict v2. Tests cover valid storage binding, missing/unknown/changed pins, protocol mismatch/downgrade, non-Revesby projection and persisted negative holds/replays.

The fresh R8 run failed in a root-authored test setup before those assertions: root incorrectly treated `claim_irreversible_creation()`'s boolean result as an attempt row. This was a fixture error, not provider behavior or evidence of a new production failure. Root corrected it to assert the boolean then reread the persisted row. R8 is preserved, its database stopped normally with no remaining listener, and no acceptance is claimed. New R9 freeze SHA-256 `812D468917916FCC723199010027C49AB8FB917EB58E8DD9ED8703004E141D6A` is running on a new dataset. PHP 7.4 syntax checks now pass for all eight changed PHP files. PHP 8.2 pure suite remains **3,051/3,051**, and diff whitespace validation passes after preserving existing mixed endings and normalising only newly changed CR line endings in the persistence file.

### R9 fixture isolation and gift nonce correction

R9 returned **3,304 assertions: 3,293 passed, 11 failed**. The new durable storage binding and reconciliation-hold cases passed; the remaining failures were fixture interference: the expanded scenarios shared one client rate-limit bucket, and the gift-card case used the card nonce prefix so the documented synthetic HTTP fixture returned a bank-card response. Root isolated each pickup scenario with a distinct documentation-only client IP and used the existing `cnon:gift-` fixture convention for the gift case. Production rate limits and tender verification were not changed. The enclosing existing fixture already restores the prior client IP. R9 evidence is preserved with normal database shutdown and no remaining listener.

R10 runs the fresh source freeze `74F238D7EDC5D5A9339AA14B4031A19A605284B0C4B4288E190A1AB87B6DB80B` on a new synthetic dataset. A fresh read-only Sol max review is evaluating the repaired eight-file candidate; source verdict and actual runtime proof remain separate.

### R10 actual local acceptance evidence

The corrected fresh R10 run passed **3,363 WordPress/MySQL assertions: 3,363 passed, 0 failed**. All 238 installed plugin files matched the source; all frozen source/harness hashes remained stable through the final check. The existing 12-worker pickup race recorded six successful holds and six full refusals. Existing two-process Square web and approved-preorder races recorded one Order POST, one Payment POST and zero duplicate dispatches; these approved-preorder cases remain legacy/untimed coverage, not acceptance of the unresolved timed-approved entrypoint.

The final result records `Passed=true`, `SourceGatePassed=true`, `HashesStable=true`, `DatabaseStopped=true`, `PortStillListening=false`, no shutdown failure and no forced stop. Outbound HTTP and mail remained blocked. Result SHA-256 `28EA67A3CA41153FA2439A2AAAD2F58D641FE1E5F824F457BCF21AF68B56CA82`; gate log SHA-256 `E248EF9A2BA3593A707B1B254E50615480E1B6191E27860470190324B6CE9DDD`. The exact source freeze is `runtime-r10-freeze.json`, SHA-256 `74F238D7EDC5D5A9339AA14B4031A19A605284B0C4B4288E190A1AB87B6DB80B`. Root's PHP 7.4 pure suite also passed **2,981/2,981**; PHP 8.2 pure suite passed **3,051/3,051**. These counts are runtime-specific; do not assume identical conditional coverage. Eight changed PHP files lint successfully; whitespace validation passes.

Fresh Sol max has received the exact R10 evidence and latest fixture. Until that review returns, this is passing local test evidence only. No provider, deployment, installed-source, financial, physical-KDS or full-launch acceptance is implied.

### R13 root synthetic check and actual operation

The four-file R13 preparation packet is frozen. Root independently ran `python -B -m unittest -q test_public_linux_r13_driver`: **147 tests passed in 20.717s**. Freeze SHA-256 `c72615ba91414476d901eb21ab0d75f9ab00b14b40e7f492e72296cfea54e987`; driver `6827af15c1e8b5ce259f94bf5799cf68611f20664d5fa3f4b59d578b27383a71`; tests `066ce8a51da16f81ab7fa3d02b84bae3aad8166709ced200c6701224a75997b2`; plan `7c326789a0f76bcdb266809c4f615c14edb4762ea0eb65d9ebb8b783b18ada7e`. Root inspected the transport delta, including serialized read/enqueue and pre-write empty barrier, immutable epochs/receipts, completion revision and failure closeout. Fresh Sol max review is pending because this repairs a diagnosed failed real operation. No R13 operation is admitted at this checkpoint; public boot and private restore are unproved.

#### R13 actual result — no retry

Fresh Sol max returned ship for the exact R13 frozen transport candidate and independently reproduced **147/147 tests in 20.804s**. Root recorded `NATIVE-PUBLIC-LINUX-R13-ROOT-ADMISSION.json` and executed exactly once. The actual operation failed **`FAILED_TERMINAL_NO_RETRY` / `TIMEOUT`**, with one launch and 2,500 serial bytes. The raw log is identical to R12's authentic initial trace plus only the first space; the remainder of the seven-byte suffix was not observed. No Enter, Linux boot, public guest tests or private restore were proved.

Result SHA-256 `8927E7E21A96AF46D1EFE8AB50A6FA499CD63F438E11EEA70DA412C877B79BD6`; serial SHA-256 `dcb0b6d1976232182edc17accd63920f4585d6eb6970d799b96a27548dc72e7c`. Both image checks exit 0, all 34 final pins matched, raw log closed/fsynced and no owned process or listener remains. `serialDrain:QUALIFIER_FINAL_COUNT` correctly remains a failed mandatory full-path condition, not a clean guest shutdown. Preserve all R13 files; no unchanged retry. A narrow read-only diagnosis of the actual burst-input/guest-consumption seam is pending; no R14 preparation or operation is authorised by this note.

### Ordinary linked-Order revocation race — revised candidate

The fresh Sol max review returned **fix-first**, despite the passing R10 suite. It identified a reachable ordinary-checkout interleaving: A jointly verifies a payment and linked Order; B subsequently commits `order_verification_held` and clears `verified_at`; A's final order transaction previously ignored that durable revocation. This could release food after a failed linked-Order verification, although it would not create another payment. Approved-preorder release already has a separate attestation/verification guard and is not the same gap.

The Sol 6.1 worker repaired only `includes/class-doughboss-order.php` and the existing integration fixture. The final locking ordinary Square predicate now rejects the linked-Order hold and requires a supported linked protocol, provider Order reference and current nonempty verification timestamp whenever linked-Order identity is present. True historical payment-only attempts retain their protocol-absent behavior. The regression uses two actual wpdb connections: A pauses before its final locking read, B performs the real failed linked-Order retrieval and commits its revocation, then A resumes; later fresh verification must recover exactly once without a new Payment POST.

Worker checks: PHP 8.2 **3,051/3,051**, PHP 7.4 **2,981/2,981**, both owned-file lints on both runtimes and whitespace validation passed. Root inspected the actual unstaged two-file delta and matched hashes: order `0CD7C1FADC5C268D451F8DCF8A4B751061964FCCF38E943DF6FDF26601DA6F4E`; fixture `44479F1B15DB2A336084F9000138D14C031EDEB416ED6F4251C723D64C113F1D`. The new frozen R11 database run uses manifest SHA-256 `8E557102B98B31127297C731B25DC97983FDB66C7C44A2D5A0B7F2EF8562534C`. Its result and final independent acceptance remain pending at this checkpoint; R10 is not evidence of the new interleaving.

### R14 preparation checkpoint — unfinished, no operation

Read-only R13 diagnosis found that all seven suffix bytes were accepted/flushed by the host but only the first space was observed. The exact downstream component remains unproven; burst-sensitive serial/guest consumption is the leading diagnosis, also consistent with the historical installation receipt. R14 therefore prepares a different, bounded discriminator: seven individually acknowledged one-byte suffix transitions, with no Enter or boot. It is not an unchanged retry or private restore admission.

At the owner's usage-control checkpoint, both implementation workers stopped further activity and preserved their work. The four new R14 files remain **NOT_ADMITTED**: local suite **133 tests, 132 passed and one failed assertion**. The malformed unrelated-cell redraw is correctly rejected by the production whole-frame gate as `EDIT_PREFIX_DISCREPANCY`; the test expected a later `EDIT_REDRAW_UNRELATED_CELL` category. No assertion repair or operation has been performed. Checkpoint freeze SHA-256 `ed7662fe3d5caca9b76656a0570e61ea8a6e9a52a0db38e47e435b57e3eb9808`; driver `de81ae69b6c7cc331ac660c7ce4b05cbe52b867943ac77668bd0b5d1a09c0343`; tests `178f9f3b270972f58429eeffb0626c2230df98c42c8f9594b02d7271eae80a88`; plan `a196962681d4dc876ff61bff89bb317bf624535ee251b1dda12ece6e30482116`.

One approved dedicated-local Qwen synthetic test draft returned on `qwen3.8-27b:code` in 109,165 ms, saved at `C:/Codex/Output/doughboss-local-first-20261017/qwen-r14-synthetic-test-draft.json`. It was not applied: the output was truncated at the cap, introduced duplicate scaffolding and had incorrect suffix epochs. Root's prompt also failed to explicitly label the coordinate tuple as row/column, causing a coordinate interpretation mismatch. This is not a model capability benchmark or proof of autonomous file work. No cloud cross-check, credentials, customer data, private backup data or provider action was included. Resources/authority were unchanged.

### Refreshed read-only installed status

The connected authenticated WordPress dashboard still reports test environment, configured-not-verified, no successful recorded sync, Revesby mapped-not-verified, other shops unmapped and API pin `2026-09-16`. The existing hosting terminal is disconnected; its visible historical sanitized probe matches `2026-10-16-LAUNCH-ACCEPTANCE.md`: token status/location HTTP 200, matching application/scope and absent optional expiry. The canonical candidate already contains the optional-expiry repair. No new probe, reconnection, account change or duplicate repair was performed; this observation does not establish current merchant ownership or provider acceptance.

### Safe source checkpoint

The exact eight changed PHP files and this handoff are staged, not committed or pushed at this checkpoint. Gitleaks staged-diff scanning completed with **no leaks found** (~70 KB scanned); staged whitespace validation passes. No private exports, backup data, generated database configuration or temporary runners are staged. The repaired source review and narrow R13 runtime diagnosis remain active. A fresh anonymous public config read still confirms `ordering_open=false`, `payments_enabled=false`, `payment_gateway=square`. No live/provider write occurred.

### 2026-10-17 — R11 recovery diagnosis and R12 local gate

R11 completed with **3,401 assertions: 3,394 passed, 7 failed**. All seven failures concerned recovery after an earlier linked-Order hold; the final locking guard correctly retained the hold, but successful joint verification restored `verified_at` without retiring `last_error=order_verification_held`. No failed dataset or freeze was overwritten.

The narrowly scoped repair retires only that specific marker inside the same successful reconciliation UPDATE that restores verification authority. Other errors are preserved; non-successful reconciliation cannot retire the hold or restore authority. The existing ordinary two-connection regression now asserts these facts and fresh successful recovery/replay without an additional Payment POST. The final ordinary release guard remains intact. This is local implementation, not a provider action or deployment.

Local validation: PHP 8.2 **3,051/3,051** and PHP 7.4 **2,981/2,981**, changed-file lint on both, and whitespace check with CR-at-EOL permitted for the existing mixed-ending source. Fresh frozen `runtime-r12` passed **3,403/3,403 WordPress/MySQL assertions**, twelve-worker capacity admission (six holds/six refusals), two-process Square/approved-release races and crash/replay cases. All **238** installed runtime files matched; final source/installed hashes remained stable. HTTP/mail guards were verified, the synthetic database stopped normally, no listener remained and no forced shutdown occurred. Freeze SHA-256: `88A64E9D788D685B7623E4EB6C55D2A3D3875A0F72D92AAC63A32D0F2EDA48DA`. Evidence: `C:/Codex/Temp/doughboss-local-first-runtime-20261017/runtime-r12/`.

One bounded fresh read-only Sol review of the ordinary hold/recovery delta is pending. In particular it must assess revocation between the joint remote read and its reconciliation write; passing the current interleaving fixture alone is not proof of every ordering. No final acceptance, commit, push, release, provider mutation or live change is claimed. Latest supported usage before this review: **33% used / 67% remaining** account-wide; there is no per-task cost attribution. No Qwen call was added for this payment-boundary repair.

### 2026-10-17 — R12 review finding and serialized R13 candidate

The fresh read-only Sol review returned **fix-first**: R12's conditional error clearing could still erase B's newer hold if B committed between A's successful provider Order read and A's reconciliation UPDATE. Its existing two-connection fixture injected B only before the final Order locking read, leaving this earlier window untested. R12's passing run is retained as evidence for that revision only, not acceptance of the concurrency contract.

The next local candidate reuses the existing named per-payment database lock. `retrieve_payment_intent()` now holds it across the provider reads and reconciliation result, releasing in `finally`; shared linked-Order verification also records any failure while holding the same lock, including create/replay callers. A competing verifier fails closed before provider reads instead of observing a later failure whose hold could then be lost. This does not rely on second-resolution `updated_at`, alter public signatures, add a schema/dependency or authorize provider traffic. All calls remain synthetic in testing.

The existing database fixture now starts from an already-held attempt and injects a second real database connection immediately before A's successful reconciliation UPDATE. It checks that B is refused without HTTP, A can recover, B can then acquire the lock and persist a later hold, locks are released on failure, subsequent exact recovery is possible, unrelated error evidence remains intact, and no recharge/kitchen order occurs. The original post-retrieval/final-lock revocation and exactly-once recovery/replay fixture remains present.

PHP 8.2 **3,051/3,051**, PHP 7.4 **2,981/2,981** and changed-file lint passed after the runtime change. A new frozen database run `runtime-r13` is in progress, manifest SHA-256 `CC43697A228A89518D25907DACDC7AF88B2DC518D14F1B106D5561B8D717BA93`. Do not reuse the R12 verdict. Its new result and fresh review remain pending. Latest supported usage before this local run: **34% used / 66% remaining** account-wide; no model/task attribution is available. No new external model, deployment or provider action was started.

### 2026-10-17 — R13 actual database result

`runtime-r13` completed with **3,435/3,435 WordPress/MySQL assertions**, plus the complete existing two-process split/ordinary/approved Square, duplicate-dispatch, crash/replay and approved-preorder checks. Its twelve-worker capacity check again produced six holds/six correct refusals. Installed parity matched **238 files**, final hashes stayed stable, HTTP/mail guards were verified, and the owned synthetic database stopped normally with no listener or forced stop. Result SHA-256: `17F4FC69F0F5EBE9D6E8CF43D69E453E584335D79470586A2103D765375AF23D`; gate-log SHA-256: `507AE8E9341769135EBE491885FE37132C87845FEC69F7C1C1550D6B995599B9`. These are local synthetic checks, not Square sandbox transactions or production operational evidence.

The focused independent reviewer is re-evaluating the repaired, frozen serialization boundary against the prior finding. Source remains unchanged after this run. No provider/deployment action has occurred. The native-public-Linux restore driver R14 preparation is separate and remains unaccepted; this PHP runtime R13 result does not supply private restore/boot proof. Timed approval/catering capture, live package parity/rollback, merchant/account/tax decisions, provider financial lifecycle and real KDS/staff acceptance remain open.

### 2026-10-17 — R13 lock-identity review and R14 PHP candidate

The focused re-review confirmed that serialization closes the original lost-hold schedule for exact references, but returned **fix-first** for inconsistent lock identity: case-insensitive SQL lookup can resolve different reference spellings to one row, while request-derived and returned-Payment-derived guards could differ. This cannot be delegated to undocumented provider alias behavior.

The final local adjustment keys both locks by the durable integer payment-attempt ID. The public entrypoint resolves the row only to obtain identity, acquires that row-derived guard, uses the exact stored provider reference and re-reads/validates both identity and reference under the guard before any provider read. The private shared linked-Order verifier uses the same row ID. No public signature/schema/dependency changed. The regression now proves an uppercase alias resolves the same database row and runs A's recovery through that alias while B uses the canonical reference; both must contend on one guard before HTTP.

Both pure suites and changed-file lints passed again. Fresh frozen **PHP/database** run `runtime-r14` is in progress, manifest SHA-256 `FAF2B743199F9AD2D247B005FF0DBD3A3A6E3C6F55DC38B806EFD342C53A361D`. This name is unrelated to the separate native-Linux restore R14 preparation. Result and final focused source review remain pending; no publication or provider acceptance is implied.

### 2026-10-17 — R14 PHP/database final local evidence

The stable-attempt-ID revision completed **3,437/3,437 WordPress/MySQL assertions**, the existing split/ordinary/approved two-process races and crash/replay tests, and capacity admission (six holds/six refusals). All **238** installed files matched, final hashes remained stable, HTTP/mail guards were verified and database shutdown was normal with no listener or forced stop. Result SHA-256: `B59DB6339F64CB5053832DE4FAF3214A7A8ADBBDEEBE6AC2556BA2D9C0B9B0D6`; gate-log SHA-256: `873845D0A708B15AEF57BC08309B9038AB7D4A89E516AF91E30610E52B749C30`. Evidence directory: `C:/Codex/Temp/doughboss-local-first-runtime-20261017/runtime-r14/`.

Final explicit-exit-checked pure suites remain **3,051/3,051 PHP 8.2** and **2,981/2,981 PHP 7.4**. The focused reviewer is checking the stable-identity delta against its prior finding. This is source-checkpoint evidence only; release and real-provider/restore/device gates remain open. No live website, provider/account, tax, payment activation or Ads-spending change was made.

### 2026-10-17 — Focused review accepted; source-only publication boundary

The independent Sol reviewer returned **ship**, with no material finding in the durable-ID serialization repair. It confirmed the initial lookup supplies identity only, the row/reference are revalidated under the lock, nested and outer guards match, all releases remain in `finally`, and the alias/interleaving fixture exercises the prior missing case. Root separately confirmed the completed R14 result, crash/replay tail and clean shutdown listed above; these were still finishing when the review packet was sent. Source did not change afterward.

This accepts the focused local pickup/verification source checkpoint, not a complete launch or provider acceptance. Publish only the existing nine task-owned source/test/handoff paths after final whitespace and staged secret checks, with one commit/push to `codex/2.51-handoff-20261016`; no PR/manual CI/deployment/tag/payment activation is part of that action. The remote branch was read back at baseline `af8160977588c7fc8692423c9a5dd474ab0248cb`. The inspected workflows run plugin CI only on PR/manual dispatch and Pages only on the separate visual-preview branch, so this source checkpoint does not intentionally trigger either. Preserve all restore checkpoints, source evidence and protected partner material.

Remaining launch requirements are unchanged: timed approval/catering capture, exact live/package reconciliation and rollback, a complete private backup restore/boot, current merchant/location and item-tax evidence, actual sandbox/payment/refund/reporting/gift/loyalty acceptance, staff permissions and installed KDS verification. Bankstown/Roselands account work remains deferred; Ads spending remains off. Do not mark the full goal complete.

Record subsequent actual findings here. New production code invalidates earlier source/package parity and runtime freezes for the changed contract; do not present the prior R6 harness evidence as coverage of a new pickup protocol. Do not overwrite that evidence. Final release, sandbox financial lifecycle and staffed KDS acceptance remain open.
