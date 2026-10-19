# 2026-10-17 — Approved-preorder pickup workflow decision

Read-only trace against `codex/2.51-handoff-20261016`, commit
`b44c547c21cdf61d7c8f16ee8e1ee02f3f529024`. No application source, provider
state or live settings changed; no new test run was represented as evidence.

## Verified source gap

The missing timed promise is not a downstream Square mapping defect. The
approval-required request workflow has no supported selector at either end:

- `includes/class-doughboss-rest-controller.php:853-875`: public request
  arguments exclude pickup mode, slot and hold token.
- `public/js/doughboss.js:665` and `tests/pickup-storefront-2-51.test.js:231-242`:
  the normal pickup selector deliberately stays inactive under required staff
  approval. The form around lines 3024-3095 submits contact details, notes,
  location and idempotency identity; its copy says timing follows review.
- `includes/class-doughboss-rest-controller.php:4517-4522,4613-4636`:
  `preorder_request()` creates an unpaid request without a persisted pickup
  promise/capacity link. This is deliberate separation from ordinary checkout,
  not evidence of losing a previously selected time.
- The staff decision at REST lines 7072-7111 and
  `includes/class-doughboss-preorder-approval.php:166-286` records accept/reject,
  contact confirmation and approval identity, but no selected pickup slot.
- REST lines 4971-4976 copy supported source timing only if already present;
  `native_pickup_snapshot_order()` around lines 3954-3973 cannot mark an
  untimed source as a v2 timed pickup.
- `includes/class-doughboss-square-orders.php:150-176` already maps a valid
  retained v2 promise to Square `PICKUP` / `SCHEDULED`, exact `pickup_at` and
  immutable preparation duration. Do not replace this mapper or fabricate an
  ASAP time to make a positive fixture pass.

The separate `public/js/doughboss-catering.js` enquiry/quote workflow does not
create an approved payment/order or a supported time promise. Do not silently
turn an enquiry into an order or claim catering end-to-end acceptance.

## Owner-approved workflow choice

Owner confirmed **staff selects after customer confirmation** on 17 October.
Keep the public request form unchanged: staff calls the customer, selects a
supported available slot, and only then approves the request/payment path.
This matches current copy/contact attestation. Do not reserve capacity for an
unanswered initial request or retain an indefinite or invented hold.

The smallest implementation seam is source request/staff
decision persistence before approval fingerprinting and payment snapshotting.
Validate through existing pickup/capacity services, not a client timestamp;
preserve exact server-derived from/by times, location timezone, capacity
linkage and approval binding. No schema/API/storage changes are implemented
or implicitly approved by this trace. Existing historical v1 and untimed
records must remain fail-closed and must not be upgraded automatically.

## Appropriate eventual proof

- Existing narrow UI guard: `node --test tests/pickup-storefront-2-51.test.js`.
- Existing PHP runner glob-loads all `tests/test-*.php`; there is no standalone
  per-file unit command to claim.
- Extend the existing WordPress/MySQL fixture with a real selected-slot ->
  persisted source -> approval fingerprint -> immutable snapshot/prep/API pin
  -> paid-order time -> exact Square `SCHEDULED.pickup_at` chain, plus no-time,
  stale/invalid hold, legacy-v1, replay and drift refusals.

These are required future checks, not tests run or passed in this trace.
One Terra read-only lane supplied this bounded map; root did not duplicate
the investigation. No Qwen, additional reviewer, provider call, hosted CI or
deployment was started for this trace. The full launch goal remains open.
## Later 17 October - reservation and payment expiry decision

Owner explicitly selected **expire the approval payment link with the pickup reservation**. Use the actual server-derived hold expiry, not a new fixed 24-hour deadline. Existing capacity defaults to 10 minutes, caps at 30 minutes, and cannot extend beyond the selected slot start. No implementation or runtime acceptance is represented by this decision.

A bounded Terra read-only investigation identified the concrete implementation constraints:

- `DoughBoss_Preorder_Approval::decide()` owns its transaction; `DoughBoss_Capacity::create_hold()` also starts/commits a transaction. Do not nest them or reserve capacity in a separate non-atomic controller step.
- Reuse the existing caller-owned transaction pattern used by `lock_hold_for_order()` and `convert_locked_hold()`. Any extracted hold-creation helper must not start, commit or roll back its caller's transaction.
- Preserve slot/hold before source-request lock ordering, then revalidate request revision and cart identity under lock. Roll back hold, timing and approval together on failure.
- Persist server-derived timing/timezone and capacity linkage before computing the approval fingerprint. Replay must not allocate a second hold or accept a different selected slot under the same event identity.
- Existing source order timing and `capacity_hold_id` columns can support the short-lived reservation policy; a new schema is not established as necessary. Public request-time capacity reservation remains forbidden.
- Bind approved payment fulfilment to the source request's locked hold without exposing or persisting its raw browser token. Validate cart, location, timing and source identity, then convert the hold atomically.
- Block new provider dispatch after expiry. Do not reuse the ordinary paid-after-expiry overflow path as proof that an approved pickup promise remains reserved; an in-flight payment arriving after expiry needs explicit reconciliation handling and proof.
- Set approval payment capability expiry no later than the hold expiry and update the staff/customer payment-window copy. The staff-only slot selection endpoint/UI contract and payment boundaries still require implementation, focused tests and fresh review.

Evidence sources: `includes/class-doughboss-capacity.php` (`create_hold`, `lock_hold_for_order`, `convert_locked_hold`, `lock_hold_for_checkout`); `includes/class-doughboss-preorder-approval.php` (`decide`, `fingerprint_from_locked_order`); `includes/class-doughboss-order.php` (request guard and paid-order transaction); `includes/class-doughboss-rest-controller.php` (staff decision and approved payment creation). This read-only lane modified no source and ran no tests. No provider, CI, deployment, payment activation or live-data action occurred.
