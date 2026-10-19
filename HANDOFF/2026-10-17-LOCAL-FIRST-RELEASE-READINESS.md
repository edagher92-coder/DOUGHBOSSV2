# Local-first release readiness — 17 October 2026

**Read the final dated appendices below before continuing.** The r1/r3/r4
sections retain attempt history, not current acceptance or publication claims.

## Scope and current boundary

Continue the owner-approved DoughBoss release-readiness batch from branch
`codex/2.51-handoff-20261016`, base
`f0615ec5269c2847083d6211e043a47e889b72d6`. Preserve the approval-first
candidate, unrelated/protected checkouts and live commerce configuration.
This receipt distinguishes local source, package, browser, database, restore
and publication evidence. It is not a whole-site or Square launch certificate.

The Sol orchestration skill governs bounded lanes and independent acceptance.
The Ollama partner skill's output/admission and failure limits remain in force;
no local model has release authority. Astra's requested final review is a
separate read-only gate. No new dependency or production backend change is
included in this batch.

## Hero preview reconciliation

The previous static exporter bypassed WPCode snippet 598 and therefore showed
the theme's photo hero. Catering screenshots also were not homepage evidence.
The builder now optionally accepts a local pinned media directory and renders
the real shared homepage snippet through allowlisted presentation hooks.
The four emitted controllers are extracted byte-for-byte to local scripts;
`script-src 'self'`, `connect-src 'none'` and `form-action 'none'` remain.
Only same-origin media is added to the preview policy.

The approved quiet film is already public at
`https://doughboss.com.au/wp-content/uploads/doughboss-commercial-v3-motion-subtle-audio.mp4`.
Fresh full-byte HTTP read returned 200 and SHA-256
`41F306A81AE4709A876E5F341A836E6DAB344DB858855B2CDBC9B0F8320D4414`
(10,410,982 bytes). The current live older web-loop is 2,720,427 bytes;
the size difference must not be represented as a performance improvement.
Both existing posters are independently pinned by size and SHA-256 in the
builder and validator. No media generation or upload was necessary.

Without media, the exporter explicitly labels the result photo-only in both
the manifest and visible banner. `--require-hero` rejects that mode.
Missing/unapproved media is rejected. No commerce/provider runtime boots.

Local preview: `http://127.0.0.1:8111/index.html` (this PC only), output
`C:/Codex/Output/doughboss-local-first-20261017/hero-preview-root-r1`.
Strict validator passes: **9 pages, 68 files**.
The latest applicable playback policy is full native looping, not older
once-then-final-frame or scroll-scrubbing behavior.

Browser evidence: 1440px desktop, 390px and 320px phone; no horizontal
overflow, controls at least 44px high, approved film loaded/playing/muted with
native loop enabled. Reduced-motion mode leaves no film source, pauses video
and shows the poster. Hidden/offscreen pause remains effective. No observed
network failures or runtime exceptions in monitored final loads. Temporary
reduced-motion/device overrides were cleared; normal-width preview again
read back playing, muted, loop=true, readyState=4.
Screenshots: `hero-desktop-1440.png` and `hero-phone-390-playing.png` in the
same output root. These are local-preview screenshots, not publication proof.

Live snippet 598 was preserved as `hero-editor-before.php` and separately
copied from its actual editable buffer as `hero-editor-buffer-before.php` in
that output root. Both matched (37,187 characters before added PHP prefix).
First readback artifact SHA-256:
`2BBCA00912DCE4E9D423FF96D596BB28663541739C0DD3B3E77A9C18D6A854EA`.
Actual live-to-candidate differences include the quiet URL/comment AND the
consent-aware localStorage guard already committed locally but absent live.
Any presentation review/publication must cover that whole delta and preserve
snippet 601 plus 598's conditional 2.49 menu-cache hotfix.

## Local validation

Existing source/package gate (`scripts/test-release.ps1`) passed before the
latest integration-fixture-only corrections: **136 PHP syntax files,
2,928/2,928 PHP assertions, 24 JavaScript syntax files, 208/208 Node tests
across five existing suites**. Strict package parity: **238 plugin files,
27 theme files**. Log: `source-gate-r1.log` in the output root above.
Review ZIPs are not installation/release approval.

The dedicated frontend/storefront checks passed **163/163**. Changed preview
PHP and snippet linted under PHP 7.4 and 8.2. `git diff --check` passed.
The integration fixture subsequently linted under both PHP versions.

## Synthetic WordPress/database gate

Harness root: `C:/Codex/Temp/doughboss-local-first-runtime-20261017`.
It uses synthetic-only data and random test-only credentials, loopback port
33119, HTTP/mail guards, frozen source/harness identities and owned-process
cleanup. Themes are frozen non-runtime inputs because tests include them.
No production database or private backup is used here.

Fixture changes, not production changes:

- Assert the actual static Square gift/loyalty page in both legacy setting
  states; do not restore retired student-voucher claims.
- Establish/restore the existing order-page asset filter for SDK checks.
- Seed the exact menu IDs consumed by accounting-basis cases.
- Fail early with the first prerequisite error rather than dependent cascades.
- Explicitly establish the approved Square REST fixture's required policy,
  pickup/after-hours flags and existing synthetic approver. Its existing
  teardown restores the saved settings.

Runtime r2: **2,752/2,774 assertions passed; 22 failed**; installed plugin
parity **238/238**. Database stopped cleanly, no remaining listener or forced
stop. Earlier lock/port failures were not reproduced.

Runtime r3: diagnostic run stopped at
`square_rest_create mapped-native-revesby: doughboss_preorder_unavailable`.
Recorded state: legacy mode, ordering closed, after-hours enabled, approver 0,
requested location active/pickup-enabled. Independent read-only diagnosis
traced this to the fixture restoring an earlier deliberately cleared approver.
Production's locked validity check correctly refused it; no production rule
was relaxed. Database stopped cleanly, no listener/forced stop.
Do not interpret `HashesStable=false` from a failed short-circuited harness as
proof of actual drift without a separate comparison.

Runtime r4 uses a fresh freeze after that four-setting fixture correction:
`E4E0208638FEFA2DDADF88D844B933F9FA4DBAA1AAABA7C80705A654FC21FE2A`.
Its result must be appended below; no passing result is claimed here.

## Local Qwen status — no successful contribution claimed

The existing ELZYDLAB llama.cpp/bridge route answered authenticated health/model
metadata reads. Two new synthetic presentation-only test-design calls returned
no text: approximately 60s and 120s timeouts. No further inference retries in
this batch. This does not prove successful agentic coding or credit savings.
Governor evidence showed a VRAM-floor unload at 64K and automatic relaunch at
36,864 context. No manual governor weakening, profile change, process kill,
credential exposure or cloud fallback occurred. Do not retry the earlier
safety-refused assignment via renamed tasks/models.

## Isolated restore gate remains unaccepted

The retained public-only Linux R4 boot failed before userland with
`IO-APIC + timer doesn't work`; its planned 81 public tests and nine real
public I/O checks never ran. No private inputs were admitted in that attempt.
The smallest diagnosed candidate is appending exactly `noapic` through the
existing Syslinux menu, preserving installed kernel and all machine/device
isolation. The older cached kernel is not a proven compatible substitute.

A new R5 logic-only packet was prepared under
`C:/Codex/Temp/doughboss-linux-restore-20261014-9bd2e853`:
`NATIVE-ADMISSION-PUBLIC-LINUX-R5-NOAPIC-PLAN.md`,
`syslinux_noapic_r5.py`, `test_syslinux_noapic_r5.py` and
`NATIVE-ADMISSION-PUBLIC-LINUX-R5-NOAPIC-FREEZE.json`.
Pure transcript tests: **23 passed, zero failures/errors/skips**.
Existing R4 public pins rechecked **34/34** and original base hash unchanged.
R5 freeze SHA-256:
`3FC98E7AA1491694BFBBC349D6954159B8EC1BBB6B8A882FD00FA220202091C7`.
It is **NOT_ADMITTED for launch**: existing host serial transport/current-session
readback binding were not recovered, actual edit-line spelling/timing is not
proven, and no VM was launched. No replacement transport was built.
A public synthetic pass would still not prove private restore/WordPress boot.

## Publication and continuation

At this checkpoint, **no live write has occurred in this batch**. Core 2.51
bulk publication remains blocked by incomplete database/restore acceptance.
Ads spending stays off. No ordering, payment, split-checkout or membership
activation; no Square/customer/staff writes, real transactions or KDS pairing.
The KDS screens still need physical installation/acceptance separately.

Next: record the runtime r4 result, disposition the frozen Astra review,
rerun checks only when changed evidence requires them, and distinguish any
accepted reversible presentation publication from the still-gated core.
Commit/push once for the coherent completed local batch, with exact remaining
blockers. Do not mark the active overall goal complete on a partial result.

## 17 October — Astra findings and repaired presentation candidate

The first frozen Astra gate returned **fix-first, approximately 8/10** for the
narrow functional-UX scope. Two P2 findings blocked presentation acceptance:

1. The body hero controller read optional motion storage before the footer
   preferences API existed, ignoring a returning consented visitor's saved
   `off` choice. The usual unit fixture preloaded that API and hid the defect.
2. Substituting the 10.41 MB original quiet master for the existing 2.72 MB
   delivery asset was not supported by mobile performance evidence.

Both were repaired, without relaxing a gate:

- Automatic playback now waits for DOM readiness when the controller executes
  during parsing. Initial consent-aware motion restoration happens after the
  footer API is available. Current-page film/motion choices win over late
  stored state. Denied/missing consent still produces no optional storage read.
- **The larger media substitution was removed.** Candidate and preview retain
  the exact already-live `doughboss-commercial-v3-web-loop.mp4`, 2,720,427 bytes,
  SHA-256 `6035FFCEF72DCAE2C667CED78CD56D8E7823E5841C6BD530BE2E0BE9CF9ECFF4`.
  Fresh public full-byte read matches the retained local original. No creative
  edit, re-encode, media upload or measured speed gain is claimed.
- Existing frontend tests extended for actual footer ordering: stored-off,
  denied/missing consent and an explicit choice before footer readiness.
  **54/54 frontend tests passed**. Browser UI reproduced the consented
  returning-visitor case: allow preferences, reduce motion, reload => no source,
  paused, loop=false, poster visible, Enable motion. Enabling again restores
  muted/native-loop playback.
- Final r2 preview uses the smaller live asset, **9 pages/68 files** validated.
  Desktop 1440 and phone 390 play readyState=4 without horizontal overflow;
  320px has no overflow and film/sound/motion controls 44px high. Offscreen
  film correctly remains unloaded/paused, not an autoplay failure. Final
  screenshots: `hero-r2-desktop-1440.png`, `hero-r2-phone-390-playing.png`.
  Browser emulation was cleared and the deliverable is now
  `http://127.0.0.1:8112/index.html` (this PC only).

Source gate r2 passed: **136 PHP lints, 2,928/2,928 PHP assertions,
24 JavaScript syntax checks, 209/209 Node tests in five suites**, and exact
**238/238 plugin, 27/27 theme** package layout/parity. Evidence:
`source-gate-r2.log`, `doughboss-local-first-review-r2.zip`,
`doughboss-theme-local-first-review-r2.zip` in the output root. Subsequent
changes are confined to the synthetic integration fixture's policy setup;
both PHP 7.4 and 8.2 syntax checks passed for it. These do not alter package
runtime bytes. Fresh Astra review of the repaired five-file presentation set
is pending and must be dispositioned below before claiming acceptance.

## 17 October — synthetic fixture policy isolation

R4 reached **3,083/3,120 assertions**, with 37 failures. Setting required-approval
mode across the entire mixed Square REST section incorrectly intercepted its
ordinary readiness/checkout/gift tests. R5 restored legacy mode with a valid
approver, but fail-fast correctly refused `mapped-native-bankstown`: legacy
after-hours requests are Revesby-only. Both attempts stopped cleanly with no
remaining database listener or forced termination. Independent post-R4 source
hash comparison matched **272/272** frozen inputs.

Root's final correction keeps the mixed block in legacy mode for ordinary
tests, sets required-approval mode **only around the three-shop approved
fixture loop**, then restores legacy mode before continuing. No production
policy, expected rejection assertion or provider behavior was changed.
The independent reviewer's earlier assertion that no mode switch was needed
was contradicted by R5 and was not accepted as completion evidence.

R6 freeze:
`33D499BEA51375C0AD7591E9B430D112E8C7D2622516CD454E1592B049DF5E9C`.
Its main suite reports **3,125 passed, zero failed**. Separate concurrency,
recovery and cleanup stages must also finish before recording the full gate
as passed. The final result belongs in the next appendix, not inferred here.

## 17 October — complete synthetic runtime gate passed

R6 completed successfully (exit 0). `result.json` records `Passed=true`,
`SourceGatePassed=true`, `HashesStable=true`, `DatabaseStopped=true`,
`PortStillListening=false`, `ForcedStop=false`, no shutdown failure, and
verified HTTP/mail guards. Fresh synthetic dataset; previous fixtures untouched.
Main suite: **3,125/3,125 assertions**; installed source parity **238/238**.
Separate split-ledger concurrency, ordinary/approved Square races, approved
release deduplication, ten crash/replay boundaries and approved-preorder races
also completed. These are guarded synthetic/local transport results, not
Square sandbox or real payment acceptance. Afterward, only four leading
indentations in the test fixture were normalized; its final PHP lint passes.
No production runtime byte changed after the source/package gate.

The WordPress/database gate is therefore no longer a release blocker for
this candidate's tested behavior. **The isolated backup restore/boot gate
remains unaccepted**; core installation/publication remains blocked.

## 17 October — live capability mismatch discovered before publication

Fresh homepage DOM inspection shows installed scripts for hero, shop-status,
marketing, core, Square and theme, but **no `doughboss-preferences.js`**.
The signed-in page still advertises online ordering paused. The local 2.51
preview does load the preferences API; live 2.49 does not. Consequently the
repaired consent-aware snippet alone would make motion choices current-page
only on the present live installation, not provide the tested returning-visitor
behavior. This difference was sent to the fresh Astra gate before any write.
Do not deploy the preferences system as an unreviewed extra file or claim
preview/live capability parity. Keep any source push separate from publication.

## Reproduction commands and review artifacts

Use the existing portable PHP 8.2 runtime (or the declared compatible runtime),
not a newly installed dependency. Build targets must be new, absent paths.

```text
php -n scripts/build-visual-preview.php NEW_OUTPUT ./ PINNED_MEDIA_DIRECTORY
node scripts/validate-visual-preview.js NEW_OUTPUT --require-hero
node --test tests/frontend-upgrade.test.js
php tests/run.php
php scripts/build-zip.php NEW_PLUGIN_ZIP
php scripts/validate-zip.php NEW_PLUGIN_ZIP
```

The existing consolidated source/package command is
`scripts/test-release.ps1 -PhpPath PHP_EXE -ExtensionDir PHP_EXTENSIONS -OutputPath NEW_PLUGIN_ZIP -ThemeOutputPath NEW_THEME_ZIP`.
The guarded real-database runner is under the temporary harness root above;
it requires a new source freeze and new run name. Do not overwrite or blindly
rerun an accepted/failed evidence directory.

Final review plugin ZIP SHA-256:
`A7B197DF7D6894AA2BED53900445045F141DAF971DF3CF53EE893E8311F7C1B4`.
Final review theme ZIP SHA-256:
`54EE4110432E74DC6F5B7E447117D2799A51D65371E93B113272675658A3F521`.
The source/package receipt does not authorize installation or provider changes.

## 17 October — final Astra disposition

Fresh repaired-candidate Astra gate: **SHIP for local r2 preview, 9/10
functional UX; FIX-FIRST for standalone current-live snippet publication**.
All five frozen presentation input hashes matched before/after review.
Independent focused hero tests: **13 passed, zero failed**; strict preview
validator independently passed **9 pages, 68 files**. Reviewer inspected actual
live-to-candidate diff, consent implementation, shared controllers, preview
boundaries and final r2 desktop/phone screenshots.

Dispositions:

- Footer-order P2: **fixed** and accepted for the supported local/new-core
  integration, with no-storage negatives and explicit page choices preserved.
- Larger-media P2: **fixed by withdrawing the substitution**; exact existing
  live delivery asset retained. No benchmark improvement claimed.
- Current live 2.49 missing preferences dependency: **deferred/blocking live
  publication**, not accepted risk. Do not publish snippet 598 by itself, do
  not remove its consent guard, and do not silently reuse legacy optional
  preferences without valid consent. Resolve through the separately reviewed
  core/preferences release after restore acceptance, then repeat real
  consent/motion/reload acceptance on the actual installed integration.

No P0/P1 or further blocking defect was found in the bounded presentation
candidate. Physical Safari/cold-network performance remains unproven; the
score is not artistic, full-site, operational or financial acceptance.
The Astra assignment is closed; integration ownership returns to root Sol.
**No live code, media, settings or provider write occurred in this batch.**

## 17 October — final fixture review and source-push boundary

Fresh read-only Sol fixture review: **ship, no blocking findings**. It verified
the isolated required/legacy mode setup against the production boundary,
unchanged voucher information page, exact seeded IDs, fail-fast diagnostics,
and asset-filter teardown. It read R6 results without repeating the database
run. Its initial provenance caveat about post-run indentation was resolved by
root: reversing exactly those four leading tabs **in memory only** reproduces
the frozen R6 fixture SHA-256 exactly:
`06FBEAF8647A61354C19816CC25DB9BF1DFD247A015AFF09A45665126CCAF035`.
No additional semantic edit occurred after the passing run.

The accepted output is the local source/preview/test/handoff batch, not a live
release. One consolidated push is authorized to the existing branch; no new
PR, tag, workflow dispatch or deployment is part of it. Before that push,
remote branch readback matched base `f0615ec...`, open PR lookup for the branch
returned none, Plugin CI was PR/manual-only, and Pages watched a different
branch. Preserve those publication holds after the source is pushed.

Next release work: complete the isolated backup restore/WordPress boot gate,
then review the real core/preferences/hero integration as one compatible
publication candidate. Repeat installed consent/reload behavior and anonymous
front-end acceptance; retain provider/payment/ordering and Ads gates. Square
sandbox, merchant routing, financial reconciliation and physical KDS delivery
remain separate operational acceptance, not implied by these synthetic tests.
