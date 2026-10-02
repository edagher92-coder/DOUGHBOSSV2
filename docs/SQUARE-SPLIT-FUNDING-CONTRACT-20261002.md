# Square split funding: provider contract and durable ledger

Status: pre-implementation candidate, not runtime or publication acceptance.
Base: `a78dce650a94ced6c172c34935718b41183c06ee` on the canonical DoughBoss
checkout. This continues the complete Square Gift Card integration, not a
replacement for it. Single full-value checkout remains the accepted baseline.

## Verified provider contract

Fresh public documentation reads on 2 October returned HTTP 200:

- [Gift Card walkthrough](https://developer.squareup.com/docs/web-payments/gift-card-walkthrough):
  request the checkout total on the gift source, allow partial authorization,
  and set `autocomplete=false`. Returned `Payment.amount_money` is the actual
  authorized amount in the worked example. Compute the remainder server-side,
  authorize the remaining payment on the same Order, then call `PayOrder`.
- [PayOrder](https://developer.squareup.com/reference/square/orders-api/pay-order):
  all supplied Payments must be approved delayed captures referring to that
  Order; their total must equal the Order total. Unspecified approved Payments
  referring to the same Order are canceled. The operation supports a stable
  idempotency key and optional exact `order_version`. It completes approved
  Payments; it is not a combination of already independently captured legs.
- [CancelPayment](https://developer.squareup.com/reference/square/payments-api/cancel-payment):
  voids an `APPROVED` Payment. It is not a refund of a `COMPLETED` Payment.
- [Delayed capture](https://developer.squareup.com/docs/payments-api/take-payments/card-payments/delayed-capture):
  `autocomplete=false` yields `APPROVED`, not paid. Use returned provider
  deadlines and fresh status; do not invent a Gift Card capture timeout from
  bank-card examples or request automatic capture on expiry.
- [ListPayments](https://developer.squareup.com/reference/square/payments-api/list-payments):
  explicitly eventually consistent. Exhausting its cursor cannot establish
  linearizable absence of extra approved Payments before `PayOrder`.

The public reference currently defaults to API version `2026-09-16`; the
plugin's configuration defaults to `2025-01-23`. Version-specific behavior and
merchant Gift Card eligibility remain provider acceptance requirements. No API
version, credentials or production setting was changed.

## Advisory evidence and disposition

Earlier guarded Kimi K3 advice supported durable per-leg identity and funding
proof. The second and final complementary call used preferred
`deepseek-v4-pro:cloud`, role `adversarial_review`, synthetic workflow context
only, without source/context files, credentials or customer data. It advised
separate requested/authorized amounts, locked remainder derivation, durable
finalization/cancellation ownership and explicit extra-payment proof.

Retain those constraints. Do not adopt a reusable durable timestamp as release
authority: the accepted baseline's private, one-use request-local attestation
must remain an additional requirement over fresh durable reconciliation.
Do not invent provider lookup/enumeration APIs or send an unsupported
cancellation-idempotency field to `CancelPayment`. A local cancellation receipt
key may be durable, but is not a documented provider request parameter.

## Implementation stage A: storage, not checkout activation

Implement the actual durable two-leg ledger first. No REST route, hosted-field
UI, provider HTTP, `PayOrder` dispatch, kitchen hook or automatic refund is
introduced in this stage. The provider consistency issue below blocks the
later capture adapter, not the inert storage prerequisite.

Expected files:

- `includes/class-doughboss-payment-legs.php`: validated repository.
- `includes/class-doughboss-payment-attempts.php`: separate, inert split-parent Order-binding operation; the existing single-tender binder and generic updater remain unchanged.
- `includes/class-doughboss-activator.php`: additive InnoDB tables and strict
  readiness contracts; no destructive migration or historical backfill.
- `includes/class-doughboss-migrations.php`, `doughboss.php`: next schema
  checkpoint only after the new contracts pass; retain old table semantics.
- `includes/class-doughboss.php`: load the repository.
- Nearest existing Square unit and WordPress/MySQL integration tests.

The existing parent remains the immutable checkout identity. New split parents
must be created with a distinct `orders_protocol=square-split-v1` plus the exact
snapshot hash and existing `protocol_version=square-v2`/shop/approval bindings.
The current single-payment adapter rejects that unknown Orders protocol,
preventing an old caller from charging or releasing a staged split parent.
No legacy parent may be upgraded into this protocol by a generic update.

### Durable tables

`doughboss_payment_sets` has one unique parent attempt ID, a monotonic version,
state, a generated stable PayOrder key, an immutable set binding hash and UTC
timestamps. The initial stage permits `funding`, `canceling` and `held` states;
future finalization states must be explicit schema/repository contract changes,
not generic caller state writes. Provider capture is not represented as proven
merely by adding this table. No array of caller-provided Payment IDs is stored.

`doughboss_payment_legs` has a unique `(payment_attempt_id, leg_index)` pair:
index 0 is Gift Card; index 1 is the optional exact remaining bank-card leg.
Each row contains immutable parent/set binding, tender kind, requested minor
amount, currency, generated unique dispatch key, nullable unique provider
Payment reference, nullable authorized minor amount, state, safe short error
code and UTC timestamps. No generic metadata or raw-provider-response column
is needed. No nonce, GAN, PAN, customer secret or caller source is durable.

Money is strict integer minor units. Reject bools, floats, numeric strings,
negative/zero requested amounts, overflow and malformed currency. Gift requested
amount is exactly the immutable parent total; its provider-authorized amount
must be positive and at most requested. A bank-card leg must authorize its
exact requested amount. Remainder is `parent total - gift authorized amount`,
never a browser value. Full gift funding creates no remaining leg.

### Repository contract

Before preparing a funding set, the parent repository must support
`bind_split_square_order(attempt_id, order_reference, expected)`. This is an
inert storage transition, not provider verification or payment authority:

- Require an exact `square-v2` / `square-split-v1` parent in `dispatching`, with
  no Payment reference, and exact expected immutable parent fields and metadata.
- Lock the parent first, then its retained snapshot. Require the snapshot to be
  prepared/unfulfilled, its stored and recomputed hashes to match, and its pure
  Square Orders projection to conserve the parent amount/currency, location and
  shop binding. Retain any approved-preorder tuple without granting approval.
- Conditionally bind a previously null Order reference exactly once, protected
  by the existing unique parent Order index. An exact same-reference replay is
  allowed only after all parent/snapshot checks; conflicting identity, changed
  tuple, uniqueness/read/write failure or failed COMMIT rolls back and fails.
- The Order binding must exist **before** the funding set is created. Its exact
  Order reference is part of the immutable set binding hash. No unbound set or
  later binding-hash transition is permitted. Authorization claims and observed
  Payment binds fail if this identity is absent or inconsistent.
- The future provider adapter must authenticate and freshly validate its Order
  observation before calling this operation. The repository records a retained
  identity only; accepting caller facts does not attest to an actual provider
  response. There is no HTTP, capture, notification or fulfillment here.

All mutations acquire locks in a single order: parent, set, legs (ascending
index). Provider HTTP never occurs in these transactions. Readiness failure,
missing/wrong parent, stale immutable tuple, wrong protocol, SQL failure or
failed final commit returns failure with rollback and no partial write.

Operations are intentionally narrow:

1. Prepare the Gift Card leg for an exact retained split parent. Create the
   set and first leg atomically, or return an exact existing plan on replay.
   Snapshot hash and parent economics/binding must still match. An approval
   parent must retain its server-owned approval tuple; this operation grants
   no new approval or payment authority.
2. Claim one planned leg for authorization, irreversibly and conditionally.
   Only a funding set may claim; no elapsed-time reclaim. A claimed or unknown
   leg is not a new-token/retry permission.
3. Bind/reconcile an observed `APPROVED` provider Payment to that claimed leg.
   Validate exact Payment ID, server-generated leg reference, parent Order ID,
   location, source type, tender brand, authorized status, integer economics
   and currency. Preserve requested amount, actual authorization and immutable
   reference on replay. A contradictory observation fails closed; no kitchen
   readiness is minted. HTTP authenticity/freshness remains the future adapter's
   responsibility, not a claim made by accepting a caller array.
4. Prepare the bank-card remainder only after the caller's expected Gift Card
   observation exactly matches the locked authorized row and set version.
   Derive the amount under lock; create at most one exact remaining leg.
   Future bank-card dispatch must independently obtain fresh provider proof.
5. Mark ambiguous authorization outcome held/unknown without clearing its
   identity, amount, claim or parent ownership. A later exact provider
   observation can reconcile that same leg; no blind repeated authorization.
6. Request cancellation under the set lock, preventing any later remainder or
   authorization claim. An in-flight callback may record its funding facts,
   but cannot turn a canceling set back into funding or allow new progression.
   Stage A sends no void/refund and never calls cancellation complete.

No stage-A operation marks a parent paid, creates a local order, frees capacity,
emits a notification or produces a fulfillment attestation. No generic setter
may mint terminal captured/canceled state. Every later provider dispatch and
release must revalidate the current shop/mode/configuration and approved-source
authority, then obey the fresh aggregate verification/one-use attestation gate.

## Stage-A acceptance

- Existing single-tender card/Gift Card and Stripe behavior remains unchanged.
- Additive schema/version migration is replay-safe; strict engine, column and
  exact unique-index contracts deny wrong or unavailable storage.
- Legacy, wrong-protocol, changed snapshot, changed parent and stale-set inputs
  cannot prepare, claim or bind a leg.
- A newly created split parent reaches its bound state through the supported
  inert binder, never raw-SQL fixture provisioning. Test exact replay, absent or
  conflicting Order identity, single-tender/legacy denial, changed snapshot and
  parent tuple, uniqueness collision and failed binding COMMIT.
- Exact replay retains one set, one Gift Card leg, one optional balance leg,
  immutable references and stable per-leg keys.
- Partial/full/malformed amount matrices prove conservation and no rounding.
- Duplicate provider IDs or dispatch keys cannot be stolen by another leg.
- Two workers create/claim at most one remainder/authorization owner.
- Unknown response, cancellation race and failed final commit have no partial
  progression and never grant another dispatch or paid release.
- No source token or card/provider payload becomes durable. No real HTTP/mail
  or kitchen event is produced by the storage tests.
- PHP 7.4/8.2, Node, full local WordPress/MySQL races/crashes, source parity,
  syntax/archive gates and frozen review pass. Runtime assurance is separate
  from this design's implementation-readiness review.

## Later stage: unresolved capture boundary

The future gateway must freeze the expected one-or-two approved Payment IDs,
exact amounts and Order version before a separately owned PayOrder dispatch.
Unknown dispatch keeps that claim and key; recovery re-reads exact provider
state before deciding whether an idempotent retry is safe. Cancellation and
finalization must be mutually exclusive durable operations. Completed funds
require supervised original-tender refund reconciliation, not void calls.

Crucially, PayOrder can cancel an unlisted external approved Payment, and
ListPayments cannot prove real-time absence. Before enabling this adapter,
obtain a supported atomic exclusion/ownership guarantee or an explicit,
bounded owner decision about exclusive application management of these Orders
and its residual external-writer risk. Do not manufacture completeness from
pagination, a sleep, two identical reads, synthetic fixtures or a success score.
Until resolved, capture and public split checkout remain disabled/unimplemented.

Also outstanding: exact API-version provider acceptance, SDK/merchant/location
and POS/KDS tests, webhook/per-leg reconciliation, aggregate refunds, the
customer split UI and recovery, and full website publication. The overall
DoughBoss goal remains active and incomplete.
