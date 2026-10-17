# DoughBoss shop replication and acceptance pack

## 18 October - retained source baseline

The owner resumed DoughBoss while Fable owns Qwen V4. Destination account connections remain deferred. Source at resume is `575a6a5` on `codex/2.51-handoff-20261016`; all 238 runtime entries in the accepted R11 database freeze still match exact bytes. The additional AVIF mapping has separate local checks. Retained candidate plugin ZIP SHA256: `7ACA585EBFAF44E1BA6645B9DAC6C1C3B02BAC3145681FE04370186DD56014F5`; theme: `54EE4110432E74DC6F5B7E447117D2799A51D65371E93B113272675658A3F521`. These are undeployed candidates, not new operational acceptance.

Fresh live readback shows active 2.49.0, 43 public menu items and ordering/payments off. Retain existing catalogue comparisons and GST-inclusive displayed prices. Each destination still needs its own legal/merchant/location binding, item tax approval, availability/options/media and credential/device receipts; never copy Revesby's credentials. See `2026-10-18-RESUME-RELEASE-GATES.md` for current restore preparation and open release gates.

Prepared 16 October 2026. **Bankstown and Roselands account connections remain deferred to the weekend.** This is an execution checklist, not evidence that either shop is connected, copied, approved or accepting orders. Revesby is the only current integration target.

## Version and source of truth

- Canonical repository: `edagher92-coder/DOUGHBOSSV2`, branch `codex/2.51-handoff-20261016`.
- Read `2.51.0.md`, `2026-10-16-CODEX-251-CONSOLIDATION.md`, `2026-10-16-SQUARE-SHOP-ROUTING-PLAN.md` and the latest dated acceptance receipt before executing. Record the exact commit and independently validated plugin/theme ZIP hashes for the accepted release; an older review ZIP is not interchangeable.
- Shared approved item names, descriptions, displayed prices and options may be replicated. Availability is shop-specific. Preserve displayed website prices; confirm GST treatment per item before selling. Neither a copied catalogue nor passing code tests establishes accounting approval.
- Each shop has its own ABN and Square account. The two Roselands-looking locations observed in the currently signed-in Square account are **not** an approved mapping. Do not select, merge or reassign them by name alone.

## What must be established separately for each destination

Record a privacy-minimised receipt with shop slug, environment, verified legal entity/ABN reference, verified merchant/location binding, connection/scopes/expiry status, catalogue preview/receipt, tax approval reference, shop availability, staff-role acceptance, webhook verification and kitchen-device acceptance. Never store credentials, tokens, passwords, PINs, customer records or raw financial exports in this pack.

1. Have the owner authenticate the correct destination Square account. Verify the legal entity, intended shop and sandbox/production environment before connecting through the existing **Square Accounts** administration page. Never copy Revesby credentials or seller IDs to make another shop appear connected.
2. Connect and select that account's verified location using the existing OAuth flow. Reconfirm that the resulting merchant and location belong to the destination. Missing, revoked, duplicate or ambiguous bindings must block activation, not fall back to Revesby.
3. Configure the target seller's accountant-approved taxes first. The current catalogue copy matches existing target taxes by name/percentage; it does **not** establish correct tax treatment. Any missing-tax warning blocks selling until resolved.
4. Use **Preview: copy Revesby menu here** in the existing account administration page. Save the preview/hash, counts and warnings. Review exact items, variations, modifiers, prices and availability before the separately approved apply action.
5. The current copy implementation is one-way **add missing**, not ongoing synchronisation. It does not update/delete existing destination objects. It does not copy images, inventory, per-location price overrides, item options, custom attributes or tax objects. Do not describe this tool as a complete full-stack transfer. Resolve every excluded required feature separately and verify item-to-image accuracy.
6. Apply only the reviewed unchanged preview. The implementation uses two phases and can succeed partially across requests; preserve returned mappings and errors, then read back the destination. Never assume an interrupted copy is all-or-nothing or delete destination items as an automatic rollback.
7. Reconfirm staff permissions and correct-shop assignments with the owner; do not copy another shop's owner role, staff access, payroll settings, leave balances or historical timecards. Pair and accept each shop's own devices when physically available.

Implementation references: `includes/class-doughboss-square-catalog-copy.php` (`preview`, `apply`, `build_plan`, `plan_hash`, `stored_map`), `admin/class-doughboss-square-accounts-admin.php`, and `includes/class-doughboss-square-oauth.php`. Source support is not proof of installation or successful provider operation.

## Acceptance receipt required before each shop opens online

- Verify exact installed code, fresh recoverable backup and accepted restore/rollback evidence.
- Keep ordering/payments off during preparation. Prove correct merchant/location/environment, permissions and signed webhook attribution independently.
- Reconcile item/variation/modifier counts, exact AUD prices, photos, tax treatment and shop-specific availability against approved source; test unavailable/missing/wrong-shop item denial.
- Prove approval precedes payment; customer confirmation, payment/order linkage, duplicate/retry recovery, reporting and refund attribution stay with the original merchant. Financial actions retain their human gate. No automatic cross-shop rerouting or split checkout.
- Confirm Square gift-card/loyalty rules and correct-seller customer experience; do not revive the paused standalone voucher system or promise cross-seller redemption without supported provider evidence.
- Verify pickup promise, order timing, kitchen routing and staffed receipt on the actual devices. Metadata alone is not a Square PICKUP fulfillment or KDS acceptance.
- Catering always uses Revesby; ordinary orders below AUD 300 use the selected shop. Orders AUD 300 and above use Revesby, but the threshold's accounting basis remains unconfirmed: do not silently choose subtotal versus post-discount/final total semantics.

Record pass/fail/unverified for every row, the evidence location and responsible owner. A Revesby pass cannot be reused as Bankstown/Roselands operational proof.

## Reproduction and rollback

Run the existing release script with the accepted PHP/extension paths, plus `php tests/run.php` with Sodium under PHP 7.4 and 8.2; use Node 20 for the CI-compatible JavaScript checks. Run the existing isolated WordPress/MariaDB gate against a new source freeze and synthetic database. Build new, non-overwriting review archives using `php scripts/build-zip.php <new-path>` and `php scripts/build-theme-zip.php <new-path>`, then run their matching validators. Save exact commands, exit codes, counts and hashes in the dated acceptance receipt.

Before any approved publication, preserve the exact installed plugin/theme files, database backup and independent snippet sources. A demonstrated restore boot and provider-safe rollback remain mandatory; current offline source-preparation or partial DB tests do not satisfy them. Keep snippets 598 (presentation) and 601 (franchising pause) separately reversible.

If acceptance fails, keep the destination disabled, preserve its existing objects/mappings and reconciliation evidence, and return the exact failed row to the lead. Never reconnect/revoke credentials, alter ownership, refund/replay payments, delete catalogue data or restore over the live site as an automatic rollback. Capacity Off only disables pickup-capacity behaviour; it is not a code/schema/payment-routing rollback.

### Preparation status

This pack is locally prepared only. No Bankstown/Roselands OAuth connection, catalogue write, staff mutation, device pairing or payment activation was performed. The active launch goal remains incomplete until its separate release and Revesby acceptance requirements have direct evidence.
