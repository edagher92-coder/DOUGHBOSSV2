# Launch acceptance checkpoint — 16 October 2026

The durable launch goal remains **active and incomplete**. Do not substitute local tests, source support, an account dashboard or a model score for installed/provider/staffed acceptance.

## Published and saved

- Members & Events is in the primary navigation after Catering. Its responsive and keyboard evidence is in `2026-10-16-FRONTEND-PERFORMANCE-RELEASE.md`.
- The bounded menu startup repair is live on installed core **2.49.0** and committed/pushed as `a6f300211dde83ab42e3e9cbe0697660c8f09861`. Public script HTTP 200, 160942 bytes, SHA-256 `6CFDED01496D708D21F41F5228C9E8E25151B764853181B8A187684F80E420F5`; cache key `2.49.0-menu-6cfded01496d`.
- Anonymous configuration still reports `ordering_open=false`, `payments_enabled=false`, `payment_gateway=square`. No commerce activation occurred.
- Five frontend suites pass **179/179**, no skips, under both Node 24.16.0 and CI-compatible **Node 20.20.2**. Node 20 was downloaded from the official distribution and checksum-verified: Windows ZIP SHA-256 `DC3700FDD57A63EEDB8FD7E3C7BAAA32E6A740A1B904167FF4204BC68ED8BF77`. No production dependency was added.

## Square connection diagnosis and local repair

The live operations reader returned a uniform HTTP 502. A bounded read-only sandbox probe from the existing hosting terminal used configured credentials only inside the host and printed no secrets or raw provider objects:

| Observation | Result |
| --- | --- |
| `POST /oauth2/token/status` | HTTP 200; valid JSON; no error codes |
| Configured application equality | true |
| `MERCHANT_PROFILE_READ` present | true |
| `expires_at` present | false |
| `GET /v2/locations` | HTTP 200; one location |

This is connection-diagnostic evidence only, not approval of the legal entity/location, catalogue, payment authority or production readiness. No account mapping, credential, provider data or live setting was changed.

`DoughBoss_Square::catalogue_identity()` already accepts an explicit empty expiry but rejected its absence. Square's official PHP SDK describes the response expiry as optional/nullable and empty for a token that never expires. The local repair canonicalises **only an absent provider-response key** to the existing empty-string representation. Present null/non-string/malformed/expired values remain denied; exact client/merchant identity, observed scope validation and repeated-observation checks remain.

Regression proof: the new absence test failed before the runtime repair (2709 passing assertions, one failure). After repair and final-disclosure negative controls: **PHP 8.2 + Sodium 2721/2721**, **PHP 7.4 + Sodium 2651/2651**, exit 0. PHP 7.4 retains its pre-existing 70 AVIF/MIME assertion difference; this is not asserted as equal coverage. Final-view evidence must still explicitly contain its canonical expiry field; missing retained evidence, an expired observation or a different application is denied. Both PHP runtime syntax checks and `git diff --check` pass.

This repair is **local, not deployed**. Fresh review, comprehensive database acceptance, exact packaging and release gates remain separate.

## Real database gate: current evidence, not a pass

Evidence directory: `C:/Codex/Temp/doughboss-251-runtime-gate-20261016`. Each admitted full run uses a fresh synthetic database/source freeze, outbound HTTP/mail guards, exact installed-source parity and graceful cleanup. Red evidence is retained.

- All three admitted runs established **236/236 runtime-file parity** and a real 12-process/six-slot contention result: six accepted holds, six full refusals, six unique durable allocations, no orders. These results do not prove paid pickup or the full suite.
- `runtime-r1` stopped on an old fixture's unchecked `WP_Error`. Initial legacy tests had not explicitly selected legacy staff approval. No runtime default was changed.
- `runtime-r2` exposed further fixture prerequisites: fixed cart IDs did not correspond to published/available menu items, and the after-hours request section did not enable its own required setting. Targeted diagnosis captured `doughboss_sold_out`/`doughboss_preorder_unavailable`; real synthetic item seeding and explicit test-only policy corrected those premises.
- `runtime-r3` progressed further but stopped in the older approved Square transport fixture, before the new pickup block. Targeted evidence identifies a missing configured approver/after-hours premise and a hardcoded non-authorised positive-fixture actor. The comprehensive gate remains **red**. Positive fixtures are being aligned with real authority, not weakening runtime policy or negative cases.
- Task-owned databases were stopped gracefully; the loopback port was confirmed closed after runs/diagnostics. No live database, old dataset or provider was used.

The unchanged new pickup block was subsequently executed directly with its existing real-database fixtures/mocks: **82 passed, 3 failed**. Two failures reproduce a real paid-order defect: a valid 0.01% voucher on a synthetic $12 basket rounds to zero; payment succeeds, then checkout clears the retained voucher identity and `DoughBoss_Order::create()` rejects the immutable snapshot. No local paid order is produced. This is more severe than the earlier suspected untimed-note outcome. The third failure was a test's literal wording mismatch (`Confirm pickup time` versus `Confirm the pickup time`), corrected without relaxing its behaviour requirement. Evidence: `runtime-r3/pickup-closure-diagnosis.log` and JSON; fixture SHA-256 `2B8030A02DAC2291F7AEB8B8EA61FAA364B99876CE105E5F2736BDB3631E1196` identifies that executed red revision.

Root has locally removed only the three zero-discount voucher-code resets in the existing checkout, hosted-finalisation and webhook-recovery writers. Invalid cart vouchers are already canonicalised to empty by `DoughBoss_Cart::totals()`; retained paid snapshots remain authoritative. A valid zero-value code is preserved but never redeemed by the positive-discount-only redemption branch. PHP 8.2/7.4 full unit counts remain 2721/2651 passing, with syntax/diff checks passing. An author-independent Sol review accepted this bounded repair; runtime SHA-256 `F0600DE5181F829D3E71F15A7407A25CCD51E9A333F8A23771C7333107105314` was stable throughout review.

The fresh targeted real-WordPress/MariaDB rerun now passes **89/89** assertions, including normal/expired/overflow/shifted/missing paid holds, staff delay/stale-version handling, Off/Shadow/Live modes, migration default/custom-setting preservation and the zero-discount regression. The latter retains the exact approved voucher identity, financial discount zero, no extra payment POST, no zero-value redemption and unchanged immutable snapshot hash. Fixture SHA-256 `BC63C8508F1E9F999DF1C35F2816F43B2933CA388D5C45568B7B80F68D405219`; evidence `pickup-targeted-r1/gate.log` and `result.json`. Installed-source parity, HTTP/mail guards, hash stability, graceful database shutdown and closed port are recorded. **This is targeted acceptance only.** Equivalent hosted-finalisation and signed-recovery zero-discount paths are not independently claimed as exercised by this targeted block.

The subsequent comprehensive **runtime-r4 is red: 2760 assertions, 2718 passed, 42 failed, no fatal error**. Three independent-connection test helpers still hardcode old port 33117 although this admitted database uses 33119, invalidating their lock/quarantine probes. The full suite also exhausts the shared synthetic IP's rate limit before the new pickup block; the fresh isolated 89/89 result remains distinct. Those two diagnosed fixture causes are being repaired locally with strict configured-loopback connections and a dedicated test-only pickup IP. Other failure groups include legacy voucher HTML, SDK-bootstrap expectations, approved REST/gift prerequisites and late Stripe/accounting-basis preparation. They require actual expected/current diagnosis before altering assertions; they are not waived or all presumed harmless. No runtime-r5 attempt has started: diagnose locally before another deliberate source-frozen run. Runtime-r4's database shut down gracefully and port 33119 closed; its exact logs/results remain preserved. Downstream comprehensive Square/Stripe race/crash acceptance is not claimed after an initial-fixture failure.

## Source/package gate for the repaired candidate

The existing `scripts/test-release.ps1` passed with PHP 8.2 ZIP and Node 20.20.2: **135 PHP syntax checks**, **2718/2718 unit assertions**, **22 JavaScript syntax checks**, all **five frontend suites / 179 tests**, then plugin/theme build and exact-current-tree validation. Its no-Sodium invocation omits three sealing assertions; the separate Sodium runs above cover those. Log: `C:/Codex/Output/doughboss-251-handoff-20261016/source-release-gate-repair-r1.log`.

- Plugin review archive: `doughboss-2.51.0-repair-r1-review.zip`, **236 runtime files**, SHA-256 `C08CD3CFB928F1BD8EDCD46AB23A677E8D0B05628AC102B97B0F56200B4FD3F9`.
- Paired theme review archive: `doughboss-theme-2.51.0-repair-r1-review.zip`, **25 files / theme 1.8.0**, SHA-256 `D29763498F439E52CB020A9ACBDFC1CA596A45B6BE5F41928C6A933F1324A11A`.

Both archives are in the evidence/output directory and are **review candidates only**. They were not uploaded/installed, and no release tag or hosted CI was triggered by this gate. A source/package pass is not the missing comprehensive DB, complete backup restore, provider/sandbox or physical KDS acceptance. Runtime changes after these hashes require a new package and validation.

## Remaining requirements and hard boundaries

### Backend checkpoint and restore prerequisite closeout

The final fixture-only probe/IP repairs are now applied. `db_integration_probe_connection()` strictly validates explicit loopback `DB_HOST` and is reused by all three independent probes. A targeted two-connection preflight confirmed separate connections to 127.0.0.1:33119, first-owner row lock, second-owner timeout, rollback, then successful second-owner acquisition, with the expected timeout captured without a warning. A dedicated synthetic pickup IP is restored after its closure. Both changed test files pass PHP 7.4/8.2 syntax and diff checks. No comprehensive rerun followed these final fixture edits, so **42 failures remain the latest full-run result**, not a newly passing claim. Final owned database PID 24352 stopped gracefully, no forced stop, port closed. All freezes are historical after later source/HEAD changes.

The separately admitted public-only Linux R4 prerequisite was attempted once on its exact reviewed QEMU profile. All 34 input pins matched before/after, but an IO-APIC/timer kernel panic occurred before userland. **The planned 81 tests and nine I/O checks did not run.** No private backup/credentials/database/WordPress/provider input was accessed. The owned process was terminated after the panic (clean guest poweroff unavailable), serial log closed; no QEMU process/listener remains. Base and overlay image checks passed, and the original base hash/size/mtime remained unchanged. Exact result: `C:/Codex/Temp/doughboss-linux-restore-20261014-9bd2e853/NATIVE-ADMISSION-PUBLIC-LINUX-R4-RESULT.json`, SHA-256 `BAF9E0B8033C13DDD7574809418A240D5C1EB01AFA13693967EE188B199879AD`. No retry, kernel/profile change or restore acceptance is implied.

The owner has now prioritised a mobile-first ordering/customisation/cart/checkout, Members, install and consent optimisation batch. Preserve this backend checkpoint and its red/green evidence; do not mix a mobile presentation release with core deployment or commerce activation.

| Requirement | Current state / required proof |
| --- | --- |
| Menu loading | Narrow startup duplication repair published; no matched-window LCP/latency benchmark or complete loading optimisation claimed. |
| Core 2.51 release | Local candidate only; comprehensive DB acceptance, exact final archives, review and complete backup/restore boot/rollback proof still required. Installed core remains 2.49. |
| Revesby Square catalogue/prices/options/tax/availability | Must reconcile exact approved website source with the correct seller and independently read back. A valid sandbox token alone proves none of these. |
| Approval/payment/order/reporting/refund lifecycle | Provider/sandbox acceptance remains incomplete. Real financial actions, credential changes and payment activation retain their human gates; split checkout remains disabled. |
| Gift cards / loyalty | Square is the intended system; verify actual rules, correct-seller operations and customer experience. Do not reactivate the standalone voucher programme or invent active membership. |
| Staff/timecards | Owner-approved roster/permissions and correct-account acceptance remain required; do not import historical hours/pay/leave by assumption. |
| Kitchen/KDS | Current projection is metadata-only. Real PICKUP fulfillment, immutable audit/binding/replay validation and paid/approval-gated dispatch remain to implement/accept. Last owner report: two screens not installed; physical staffed receipt cannot be certified in code. |
| Bankstown/Roselands | Account connections deferred to weekend. Reusable checklist in `2026-10-16-SHOP-REPLICATION-PACK.md`; no target-account write performed. |

Square PICKUP design must preserve historical metadata-only attempts using a creation-only immutable contract marker, bind the first returned pickup facts atomically with the Order, and reject later drift. The website's existing ASAP promise must not be silently replaced by a provider-generated time. A preference question is outstanding: project the exact website promise as scheduled pickup, or use literal Square ASAP with mismatches held for staff. No fulfillment code was changed while that contract is unresolved.

Keep one integration owner/canonical checkout. Protect the unrelated partner handoff and dirty worktrees. No failed mandatory gate is waived by this checkpoint or by the prepared replication pack.
