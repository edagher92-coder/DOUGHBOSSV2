# Square shop routing — owner rules and bounded execution plan

16 October 2026. Planning handoff only; no routing implementation, account connection, provider call, migration, deployment or operational acceptance is performed by this document.

## Scope and authority

- Outcome: hand the next implementation batch an evidence-backed routing contract and acceptance checklist for the latest owner instructions.
- Non-goals: activate ordering/payments, connect sellers, copy catalogues, change prices/tax policy, enable split checkout, install KDS or claim live readiness.
- File owned by this planning lane: this document only. Other writers own the merge/consolidation, hero and selected-store fixture work.
- Proof for this handoff: inspect the actual 2.50/2.51 source seams below and check the document's diff. Historical handoff test counts are not rerun evidence for the merged candidate.

The owner correction supersedes earlier single-merchant assumptions: **Revesby, Bankstown and Roselands each have their own ABN and their own Square account.** No ABN, merchant ID, location ID, credentials or connection status is inferred here.

| Order classification | Required shop and seller |
| --- | --- |
| Ordinary daily sale, preorder or small order, strictly below AUD 300 | Customer chooses the closest shop; use that shop's Square account and pickup facts. |
| Ordinary order at AUD 300 or above | Revesby main store and its Square account. |
| Catering, at any amount | Revesby main store and its Square account. Catering classification takes precedence over amount. |

The threshold comparison basis remains **pending owner confirmation**: subtotal versus final total, before/after discounts, inclusive/exclusive tax, delivery/service fees, tips, rounding and edits/quotes that cross the boundary. Do not choose financial semantics from an existing `total` field. Once approved, compare validated server-derived AUD minor units, not browser totals or floating-point boundary guesses.

Shared menus and displayed prices remain shared; availability is shop-specific. Each item's tax treatment must be confirmed before selling. Kristy approval remains before payment; server-verified payment remains before exactly one kitchen release. A changed pickup shop requires explicit customer confirmation before a replacement approval/payment flow. There is no silent post-payment seller or pickup reroute. Split checkout stays off. The two Revesby KDS screens are the final physical step and are **not installed/accepted**.

## Source support versus gaps

Line references describe this checkout's inspected source, not installed production state.

| Existing seam and evidence | What it supports; what remains |
| --- | --- |
| [2.50 handoff](2.50.0.md:34), [2.51 handoff](2.51.0.md:9) | Source-level per-shop OAuth and store-first/pickup work exist. Their reported tests and rollout instructions do not prove this consolidated candidate or real connected accounts. |
| [Square binding](../includes/class-doughboss-square.php:179), [OAuth merchant binding](../includes/class-doughboss-square.php:223), [binding recheck](../includes/class-doughboss-square.php:2966) | Active native shop, environment, provider location and OAuth seller can be bound and rechecked. There is no verified AUD 300 routing policy in these paths. |
| [Effective settings map](../includes/class-doughboss-settings.php:1109), [credential selection](../includes/class-doughboss-square.php:1186), [OAuth duplicate guard](../includes/class-doughboss-square-oauth.php:780) | Per-shop connected credentials and duplicate-seller rejection exist. Unconnected/revoked shops can return to legacy settings/token paths; this compatibility behavior is not proof of strict three-account isolation. Bankstown/Roselands must not accidentally become Revesby sellers through fallback configuration. |
| [Webhook routing](../includes/class-doughboss-square-oauth.php:344), [webhook entry](../includes/class-doughboss-rest-controller.php:8267) | Known merchants can route to shops. Missing merchant identity, or an unknown identity while legacy merchant is undetected, can select the legacy route. Explicit fail-closed attribution and per-attempt seller checks need negative-test evidence before activation. |
| [Catering shop policy](../includes/class-doughboss-catering.php:420), [enquiry routing](../includes/class-doughboss-rest-controller.php:7292), [catering invoices](../includes/class-doughboss-square-invoices.php:267) | Catering defaults to Revesby, but allowed slugs are filterable and the enquiry path has legacy omission/fallback handling. Invoice Orders transport uses the enquiry's native location. Verify every new and already-stored enquiry/quote/deposit/balance path, not just the form default. |
| [Store change](../public/js/doughboss.js:778), [cart revalidation](../includes/class-doughboss-rest-controller.php:4389), [shop availability](../includes/class-doughboss-post-types.php:125) | Customer store selection and shop-specific availability exist. The threshold-specific reason, explicit Revesby confirmation, reapproval and threshold-crossing cart UX are not demonstrated. Do not introduce geolocation collection as a prerequisite for choosing the closest shop. |
| [Approved Square start](../includes/class-doughboss-rest-controller.php:4867), [approval fingerprint](../includes/class-doughboss-preorder-approval.php:302), [release verification](../includes/class-doughboss-preorder-approval.php:727), [immutable snapshot](../includes/class-doughboss-checkout-snapshots.php:38) | Existing approval/snapshot/verified-release seams should be extended, not bypassed. Routing inputs and customer-confirmed pickup must be immutable before payment; replay, webhook recovery and refund must retain the original seller. |
| [Cart tax basis](../includes/class-doughboss-cart.php:308), [saved accounting basis](../includes/class-doughboss-order.php:17), [Orders tax projection](../includes/class-doughboss-square-orders.php:87), [catalogue tax matching](../includes/class-doughboss-square-catalog-copy.php:112) | Current totals/projection use a shared rate/basis and apply the projected tax to each goods line; catalogue copy matches existing target taxes and does not establish every item's approved treatment. This does not prove mixed item-level taxable/exempt correctness. Catalogue copy is not continuous menu/price synchronization. |
| [Pickup projection](../includes/class-doughboss-square-orders.php:147), [Orders linkage contract](../docs/SQUARE-ORDERS-LINKAGE-PACKET-20261002.md:40) | Timed pickup adds `ticket_name`, `db_pickup_at` and `db_pickup_mode`. These are **metadata-only pickup annotations: no `PICKUP` fulfillments are emitted**. A projected or paid Square Order is not proof of POS/KDS ticket delivery or staffed acceptance. |

## Separate implementation packets

Keep packets local and ordered. No packet authorizes credentials, catalogue writes, provider simulations, hosted CI, release or activation. Reconfirm the weekly usage reserve before execution. Extend existing tests; do not add a second checkout implementation or new test infrastructure.

1. **Settle the financial and identity contract (owner/lead, no runtime change).** Obtain the threshold basis and auditable shop-to-legal-entity mapping separately for sandbox and live. Confirm catering classification, pickup versus delivery handling, invalid/unknown currency behavior and the expected response to a changed approved order. Record masked identity evidence, not secrets. Confirm per-item tax/rounding policy with the responsible accountant/owner. Any required persisted snapshot/schema/wire-format extension must be explicitly approved before implementation.

2. **Server routing and merchant isolation (lead-owned shared seam).** Work through `includes/class-doughboss-rest-controller.php` (`preorder_request`, `create_payment_intent_inner`, approved Square start, hosted finalization, catering enquiry/invoice and webhook entry); `includes/class-doughboss-square.php` (binding, token selection, transport and refunds); `includes/class-doughboss-square-oauth.php`; `includes/class-doughboss-settings.php`; and, only where the agreed contract requires it, `includes/class-doughboss-catering.php`, `includes/class-doughboss-square-invoices.php`, `includes/class-doughboss-preorder-approval.php` and `includes/class-doughboss-checkout-snapshots.php`. Reuse one validated server decision across these real callers. Resolve destination and require customer confirmation before approval/payment; fail closed when the destination is unavailable or unbound. Preserve in-flight immutable seller identity and existing compatibility contracts; never rewrite old hashes or paid rows as a migration shortcut. Extend `tests/test-square.php`, `tests/test-zz-square-oauth-2-50.php`, `tests/test-square-hosted.php`, `tests/test-square-invoices.php` and `tests/test-square-refunds.php` for the cases below.

3. **Storefront, availability and pickup confirmation (after server contract).** Use `public/js/doughboss.js`, `public/js/doughboss-catering.js`, `public/js/doughboss-square.js`, `includes/class-doughboss-post-types.php` and `includes/class-doughboss-pickup.php` only as required. Keep shared prices; show the selected/required shop, seller-facing shop label, reason for Revesby routing and new pickup details before the customer confirms. Revalidate cart availability, hours and slot at the confirmed destination. Never silently remove unavailable items and proceed to pay. Any changes to an approved basket, pickup or seller invalidate the old approval/payment capability. Extend `tests/pickup-storefront-2-51.test.js`, the relevant existing sections of `tests/storefront-helpers.test.js`, `tests/test-zz-pickup-2-51.php` and `tests/test-zz-checkout-pickup-2-51.php`. Coordinate ownership with the current frontend/fixture writers before editing.

4. **Item tax and seller-scoped reconciliation (after approved accounting contract).** Reconcile `includes/class-doughboss-cart.php`, `includes/class-doughboss-order.php` and `includes/class-doughboss-square-orders.php` with the approved per-item treatment and merchant-scoped catalogue/tax IDs; use `includes/class-doughboss-square-catalog-copy.php` only for its existing approved-copy role, not automatic price synchronization. Preserve customer-facing prices while proving line allocations, discounts, tax, fees, totals and refunds. Extend the accounting/projection fixtures in `tests/test-square.php`, invoice tests and refund tests. Do not silently reinterpret historical accounting snapshots; stop for the required storage/API approval if a format change is necessary.

5. **Acceptance and one approved release batch (later, lead/owner).** Integrate once, run the local gate, obtain required independent payment/security review, then request one explicitly scoped sandbox batch. Production connections, catalogue application, deployment and activation are separate gates. Revesby KDS physical installation/acceptance is last; do not promise Square `PICKUP`/KDS behavior from metadata projection. Bankstown and Roselands retain their own seller/location kitchen and reconciliation acceptance, not Revesby evidence by substitution.

## Routing and failure test matrix

Use synthetic merchant/location IDs and the owner-approved amount basis. Run applicable rows for each shop, ordinary direct/approved/hosted entry, browser return, signed webhook and replay; catering also needs quote/invoice/deposit/balance coverage.

| Input or transition | Required result |
| --- | --- |
| Ordinary AUD 299.99, selected Revesby / Bankstown / Roselands | Remains with the selected shop's own seller and confirmed pickup; no implicit Revesby fallback. |
| Ordinary AUD 300.00 and 300.01 | Revesby; Bankstown/Roselands selection is stopped for explicit Revesby confirmation before a fresh approval/payment flow. |
| Catering AUD 0 quote / 0.01 / 299.99 / 300.00 / 300.01 | Revesby irrespective of amount; a zero quote is not payment authority. Never classify by deposit size. |
| Discounts, tax, delivery/service fees or rounding move values across AUD 300 | Follow the explicitly approved basis, with fixtures on both sides; until that basis is approved this acceptance case is blocked. |
| Cart edits/quote edits cross threshold, or customer changes pickup after approval | Revalidate destination/availability/slot and require confirmation plus new approval; reject the old payment capability. |
| Wrong/duplicate/inactive native shop, missing Revesby, no OAuth location, revoked/expired/unreadable token, duplicate seller/location | No payment/provider dispatch, no kitchen release and no fallback into another shop's merchant. A valid refresh retains the same seller. |
| Non-AUD, malformed or browser-forged totals/classification; stale catalogue/tax treatment | Reject before creating a payable routed snapshot; no guessed conversion or tax. |
| Cross-merchant token/location/payment/order/invoice/refund or webhook substitution; unknown/missing webhook merchant | Fail closed or hold for reconciliation without attributing to another shop; log a privacy-safe reason. |
| Store becomes unavailable before payment | Stop for an explicit new choice/approval; no silent pickup substitution. |
| Store/config changes after payment, paid replay or out-of-order/duplicate webhook | Retain original seller/payment/pickup identity; reconcile once or hold for staff. Do not charge again, rewrite merchant or duplicate kitchen release. |
| Mixed taxable/exempt goods, modifiers, discounts and fee treatment | Approved item-level allocations and final amounts agree across the saved basket, Square Order, payment, invoice and refund. |
| Split path invoked | Remains unavailable; no extra authorization or kitchen release. |

## Proof, reconciliation and rollback

Local final gate for the completed implementation batch: `php tests/run.php`; `node tests/pickup-storefront-2-51.test.js`; `node tests/orderboard-2-51.test.js`; `node tests/storefront-helpers.test.js`; and the existing frontend/navigation checks if their code changed. Lint changed PHP files with `php -l`. Run the existing `tests/integration/wordpress-mysql.php` harness only with its supported disposable configuration; prove upgrade preservation and concurrent/replayed payment release where those paths change. Read harness setup before running it. No checks in this paragraph were executed by this documentation lane.

The later approved sandbox receipt must reconcile, separately per merchant/environment: native shop and legal-entity approval; Square merchant/location; request and approval revision; confirmed pickup; immutable snapshot/attempt; Square Order/payment/invoice IDs; currency, lines, item tax, discounts, fees and amount; webhook identity; kitchen release count; refund seller/amount; and settlement/reporting destination. Do not store tokens, raw customer data or payment credentials in the handoff. A receipt absent from one shop is a failed/unverified case, not evidence borrowed from another.

Before an approved release, preserve the previous artifact/hash, redacted configuration and mapping evidence, a recoverable database backup and a demonstrated restore path. Separate payment-account changes from code rollback: restoring old code must not make Bankstown/Roselands silently inherit a legacy seller. If any attribution, tax or reconciliation check fails, keep/turn commerce off through the approved operating procedure, preserve paid records and provider references, and reconcile/refund through the original seller under explicit authority. Never reconnect, revoke, remap, refund or replay provider writes as an automatic rollback. Capacity Off alone is not a payment-routing rollback. Evidence must show no duplicate charges, lost paid orders, cross-seller refunds or duplicate kitchen release after rollback/recovery.

## Owner sequencing update — 16 October 2026

After the current frontend repair/optimisation release, the next and final project is Square validation and integration for **Revesby only**. Bankstown and Roselands account connections are deferred to the weekend. Do not activate them or route their transactions through Revesby as a shortcut.

Retain the shared menu/prices and shop-specific availability policy. Preserve a reusable, versioned catalogue/configuration mapping, redacted setup steps, validation receipts and rollback instructions for the later shops. Replicate approved common item content and prices; re-establish each shop's own merchant/location IDs, OAuth connection, ABN, taxes, permissions, staff assignments, webhook verification and device routing. Never copy credentials, customer/payment records or Revesby seller IDs into another shop. Revesby acceptance does not prove the other two accounts work.

The existing catering/large-order routing requirement remains recorded above; no threshold-accounting interpretation is newly approved here. The user permits Astra when it offers tangible assurance benefit, not repetitive routine review. Core publication, payments activation, real transactions and KDS operational acceptance retain their existing explicit gates.

## Later acceptance target, not a current score

A later reviewer may assign a **9/10 or better** only after all hard gates below pass with reproducible evidence; a numeric rating cannot waive a failed gate. This plan and the historical handoff assertions are not a current readiness rating.

- Approved threshold/totals definition, order classification and three separate legal-entity/seller mappings, with no invented IDs/ABNs.
- Server-side matrix passes for every relevant entry/recovery path; strict merchant/environment/location isolation and split-off behavior hold under negative tests.
- Customer confirms any changed shop; approved immutable facts are revalidated; Kristy approval precedes payment; verified payment releases the kitchen exactly once.
- Shared menu/display prices preserved; per-shop availability and approved per-item tax/rounding reconcile before selling.
- Local regression, real disposable database/concurrency, required independent review and specifically approved three-seller sandbox lifecycle evidence pass.
- Restorable rollback and seller-scoped refund/reporting reconciliation proven; no silent paid-order reroute.
- Production activation remains separately approved; actual staffed POS/printer/KDS tests (including the two Revesby screens installed last) are required for operational acceptance. Metadata-only Square pickup is not a substitute.

Outstanding concrete unknowns: threshold accounting basis; actual ABNs/merchant/location mappings and connection health in each environment; treatment of changed/mixed-classification orders and delivery; per-item tax catalogue and rounding approval; required snapshot-format changes; Apple Pay registration per seller (2.50 limitation); real sandbox SDK/3DS/webhook/refund behavior; installed artifact parity, backup restore proof and actual kitchen device routing. None is resolved by this planning document.
