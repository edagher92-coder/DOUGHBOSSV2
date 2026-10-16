# DoughBoss 2.51 consolidation — 16 October 2026

## Goal

Preserve Claude's source and consolidate the published presentation into one evidence-backed continuation branch. This is not a 2.51 deployment or Square operational acceptance. Read HANDOFF before work and append a dated entry after finishing.

## Confirmed facts

- Incoming commit: `233841d077e34520026fd9c8f0e79888e45abfda`, imported as `claude/pickup-2.51-20261016`; 2.50 base `7c0883c` is its ancestor.
- Working branch: `codex/2.51-handoff-20261016`. Published UI commit `0181ec657a0e61d267b464716b41490c2ff67359` merged without conflict. The separate unpublished backend pilot `671141c` was NOT merged. Protected/dirty checkouts were untouched.
- Input SHA-256: bundle `6C48C5DEA8547F1F709A39BA736023C8ED50F626ED41A35E90C3EE07B88E1C95`; ZIP `2F6CC14CD4BF7C7F7D56BC26FCE408CAB3747814DDC1C6A1797C37BD4EFA2CF4`; supplied MD `D4E82BF5744F76AC3EDC79D59FD6D986F5304CA149C3775769A99BEEDD377FEE`.
- Supplied ZIP: 236 entries; 209 exact, 27 CRLF/LF-only differences, no missing or additional normalized-content differences. Existing strict validator correctly exits 1; it was not weakened.
- Rebuilt plugin review ZIP: `C:/Codex/Output/doughboss-251-handoff-20261016/doughboss-2.51.0-consolidated-review.zip`; SHA-256 `0C9982614B5A74BA9C98B91845FE4334088958F7A991AE53096782FFD32F286C`. Existing build and strict parity validator pass, 236 entries. Theme/snippet presentation is separate from this archive.

## Decisions made

### Owner's corrected Square topology

The 16 October owner instruction supersedes the single-merchant assumption in the historical homepage handoff: Revesby, Bankstown and Roselands EACH have their own ABN and Square account. No actual identifiers or credentials were supplied or verified here.

Ordinary daily sales/pre-orders strictly below AUD300 use the customer's chosen shop and that shop's account. Every catering order, regardless of amount, and ordinary orders of AUD300 or more go through Revesby (main store). Changed merchant/pickup routing must be disclosed and confirmed before checkout, never silently after payment begins. The exact threshold calculation basis (discounts, fees and taxes) needs an owner decision before implementation.

See `HANDOFF/2026-10-16-SQUARE-SHOP-ROUTING-PLAN.md`. This handoff records policy, not implemented routing or connected accounts. Shared displayed prices, shop-specific availability and per-item GST configuration remain. Split checkout stays off. Kristy approves before payment; only server-verified payment may release kitchen work.

### Homepage

Owner requested the full silent film once, followed by a hold on the actual final open-box video frame with continuous subtle ambient steam instead of restarting. Browser inspection found that existing fallback posters show the opening board, so natural completion must not switch to those posters. Native video repeat is disabled; completion is latched. Replay is explicit; intentional scroll still advances/reverses. Reduced-motion, Save-Data, visibility, manual pause and Pause steam remain respected. Play film wording is consistent.

This is a separately rollbackable change to WPCode snippet598, NOT a core2.51 deployment. Franchising pause snippet601 remains independent; commerce settings are untouched.

### Qwen

Owner-authorized server connector change at `C:/AI/gateway`: topic keywords no longer blanket-refuse supervised commerce/tax/legal drafts. Mandatory unverified-draft/independent-verification labels remain, even after optional Claude checks. No execution, credential, provider, payment or deployment authority was added. GPU/governor/video interlocks and default-off cloud checking remain.

17 offline tests and syntax checks pass. Fresh Sol review accepted frozen module SHA `77BDD862283218DB5AA35E5651499A95B02EC95289B19D59579BFA76FB4944BC` after warning-preservation repair. Existing MCP processes need reconnect; live refresh/agentic execution is not proven. The earlier refused assignment was not retried or counted as a contribution. Connector source is not part of the WordPress ZIP.

## Verification completed

Independent intake report/logs: `C:/Codex/Output/doughboss-251-handoff-20261016/evidence-verifier.md`.

Incoming PHP with Sodium: 8.2 **2664/2664**, 7.4 **2594/2594**. Exact2.50 base8.2 **2085**, proving579 added assertions. Three sealing checks need Sodium; PHP7.4 deliberately skips70 AVIF/MIME assertions.

Combined candidate PHP8.2+Sodium **2692/2692**, PHP7.4+Sodium **2622/2622**, exit0. Partners/franchising smoke **51/51** on each. Hero syntax passes both. Final whole-batch Node gate **165/165**: frontend32, orderboard9, pickup-storefront33, storefront-helpers89, navigation2; all exit0. Both diff whitespace checks pass.

Ten helper failures reproduced on incoming and exact2.50 base. Published UI fixtures supply missing current dependencies/state. The remaining combined fixture was corrected to choose a synthetic shop before testing late-card-render failure; original preservation/denial assertions remain. No runtime change was made for that test repair.

Commands: `php tests/run.php` (Sodium for full sealing coverage); `node tests/<suite>.test.js` for frontend-upgrade, orderboard-2-51, pickup-storefront-2-51, storefront-helpers, theme-navigation-layering. Build: `php scripts/build-zip.php <new-output-path>`; validate: `php scripts/validate-zip.php <output-path>`, with ZIP enabled. Builder refuses replacement of an existing archive.

These are local shim/synthetic tests, not real WordPress/MariaDB/provider acceptance. Node24.16.0 ran; declared CI Node20 is not_run. Claude-reported database/concurrency checks are provenance, not independent proof.

## Remaining work

- Implement the source-backed multi-account routing plan after threshold semantics are approved. Fail closed for unconnected shops; prevent cross-merchant legacy fallback.
- Current Square Orders projection adds ticket name/metadata, explicitly no PICKUP fulfillment. Square KDS delivery remains unimplemented/unverified. Two Revesby screens last owner-confirmed not installed.
- Resolve paid-hold/untimed-note exceptions, including zero-discount vouchers. Retaining an order does not preserve its selected pickup promise automatically.
- Prove fresh backup/restore/code rollback and migration acceptance before core release. Schema1.29→1.30 is additive but consequential; Capacity Off is behavioural disablement, not schema/code rollback.
- Run real WordPress/MariaDB, Node20, Square sandbox, per-merchant webhook and installed/staffed KDS acceptance.

## Blockers and authority

No core2.51 deployment, production migration, Square/OAuth/provider write, payment activation, release tag or hosted CI is performed by this handoff. Astra must assess the frozen presentation/handoff scope separately from whole-site production readiness; a score cannot replace missing operational evidence. Keep one live writer. Publish presentation separately from commerce.

### 16 October 2026 — rendered hero verification

Using the existing isolated loopback preview (no WordPress/database/provider bootstrap), the real 10-second film autoplays muted with native loop false. At natural completion on a 390px viewport: time10, ended/paused true, video visible, fallback hidden, Replay film visible, ambient-steam animation running with infinite iterations and no horizontal overflow. Pause steam stops it; emulated reduced-motion stops steam and hides its control. Unit coverage also preserves the final frame across breakpoint changes without auto-replay and supports scroll reverse/replay. Screenshot artifacts/logs are in the evidence directory above. Temporary browser emulation is test-only and must be cleared afterward.

Before any presentation update, WordPress snippet598 was freshly read back and matched published0181ec6 after opening-tag/line-ending normalization; exact saved-source SHA-256 was `12c7f07630b1deff1e38cad2d08059054fba84cfb72f2fe1dbc8e69a5fae14ed`. The published branch's source is the verified rollback. No snippet601 or theme changes are needed for this delta. Astra and any publication receipt remain to be recorded in a subsequent dated entry; local passing checks do not claim publication.
