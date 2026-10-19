# Square itemized Orders linkage: bounded implementation packet

Status: design candidate, not implemented or deployed. Return owner: root lead.

Baseline: `50cbc1be59480fb7d9af62276725dacc44f02e37`; accepted runtime
`adcca08bed4ad38cfee2fe0b85f75809750b991c`. This packet extends, not replaces,
`SQUARE-ORDERS-ACCOUNTING-CONTRACT-20261002.md`.

## Outcome, boundaries and proof

New Square checkouts create an itemized provider Order from the immutable server
snapshot, reconcile all financial facts, bind a separate immutable Order ID, and
include that ID in the existing one-time CreatePayment request. Existing payment
and receipt identities remain unchanged. No provider Order alone releases food.

Non-goals: payment/ordering activation, real provider calls, customer messages,
automatic recovery POSTs after ambiguity, catalog repricing, fulfillment promises,
staff attendance, Gift Card tender, site publication, dependencies or a new stack.
Revesby, Bankstown and Roselands retain separate mode/location bindings; no new
location is disclosed. Native voucher records and quarantine are untouched.

Expected files: Square adapter (or one narrow Orders helper if it reduces its
size), payment-attempt repository, activator/schema readiness, plugin schema
version, existing Square unit and WordPress/MySQL/race tests, this packet and the
existing sprint evidence document. REST changes are limited to supplying trusted
snapshot linkage if the adapter cannot obtain it from the existing checkout key.
All shared schema/payment/approval seams remain lead-owned.

Proof: pure projection/response negative matrix first; then disposable real
WordPress/MySQL storage, failure and concurrent-process cases; existing Square
and Stripe release races; supported PHP 7.4/8.2, Node and release archive gates.
External HTTP and mail remain blocked/intercepted. Frozen runtime requires fresh
Astra assurance >=8.5/10; a design score is not runtime or live acceptance.

## Verified provider contract

Read-only official reference observed on 2 October 2026, selected API **2025-01-23**:

- [CreateOrder](https://developer.squareup.com/reference/square_2025-01-23/orders-api/create-order):
  accepts `reference_id` (max 40), explicit lines/taxes/discounts, and an
  idempotency key (max 192). The pinned page explicitly permits retry with the
  same key without duplicating the order. This implementation deliberately does
  not introduce automatic recovery POSTs; uncertain work stays held.
- Its expanded pinned Order schema: `OPEN` can be updated; `DRAFT` cannot be paid
  or fulfilled. Therefore this packet creates **OPEN, with no fulfillment**, not
  a DRAFT silently passed to payment. An unpaid OPEN object is not a local order,
  kitchen permission or evidence of POS/KDS visibility.
- Pinned line schema: quantity string (max 12), name (max 512), note (max 2000),
  base price money, applied taxes/discounts, and pricing blocklists. Tax supports
  `INCLUSIVE`/`ADDITIVE`; percentage is a decimal string (max 10). Fixed-amount
  discounts support ORDER/LINE_ITEM scope. An ad-hoc discount can be blocked on
  a line by `discount_uid`.
- [CreatePayment](https://developer.squareup.com/reference/square_2025-01-23/payments-api/create-payment):
  `order_id` associates a previously created Order. Keep `autocomplete=true` for
  the existing single full card payment. Do not enable partial authorization.
  `APPROVED`/`PENDING` never means paid or kitchen-ready.

No API version upgrade, Square SDK/provider simulation or credential access is
part of this evidence. Real response/currency/merchant/POS acceptance remains a
later staffed sandbox/live gate.

## Pure projection and reconciliation seam

1. Consume only an already hash-validated saved snapshot, exact attempt tuple and
   current separately verified shop binding. No body-supplied order facts, fresh
   menu lookup, current tax setting, contact metadata or raw provider payload in
   durable attempt metadata. Known valid historical accounting basis is required
   for the new Orders protocol. Unknown legacy basis stays unknown and cannot be
   invented for itemization.
2. Validate every persisted line: integer positive quantity, bounded UTF-8 name,
   size/modifier-label shapes, finite non-negative two-decimal unit/line amounts;
   integer-cents unit times quantity must equal stored line total. Sum of goods
   line totals must equal saved subtotal. Convert validated decimal values to
   integer cents once; reject excess precision/overflow rather than round away a
   discrepancy. Numeric strings from DECIMAL columns are legitimate only when
   they meet the same exact decimal grammar.
3. Assign deterministic local line UIDs by snapshot position. One provider line
   uses the saved **final unit price**, which already includes paid modifiers.
   Size and full saved modifier labels appear as a bounded line note; never add
   paid modifiers again, manufacture catalog IDs, silently truncate choices or
   transmit unrelated customer notes/contact information.
4. A nonzero saved delivery fee becomes one explicit quantity-one fee line. It
   is excluded from the goods-before-tax discount using its discount blocklist.
   Inclusive tax applies to goods plus delivery; exclusive tax applies to goods
   only, matching the saved v1 policy. Use explicit ad-hoc percentage tax and
   fixed-amount discount, with automatic catalog taxes/discounts disabled. Zero
   amounts omit adjustments. Rate must have an exact non-exponent representation
   accepted by the pinned percentage field; otherwise hold before dispatch.
5. Validate saved subtotal, discount <= subtotal, tax, delivery, total and currency.
   Prove the saved inclusive/exclusive total equation in integer cents. Square
   calculates tax/discount allocation; compare its actual returned tax, discount,
   fee, currency and final total **exactly** to saved cents. Different rounding is
   a denied/held checkout, never a changed customer price or balancing line.
6. Validate response location, reference, Order ID shape, version/state, complete
   UID/name/quantity/unit-price/note line set, expected tax/discount definitions
   and scopes, absence of catalog substitutions, modifiers charged again,
   additional fees/tips, fulfillment, reward or automatic pricing. Check returned
   line monetary rollups sum to the returned order rollups, not just total money.
   Unexpected/malformed/extra economic facts deny money dispatch. Generated
   provider UIDs are allowed only for applied adjustment references; references
   must resolve to the exact expected tax/discount definitions.
7. Before CreatePayment, re-read the bound Order and reconcile it again against
   the same snapshot. Verify the returned Payment `order_id` equals the immutable
   bound Order ID. Subsequent server payment verification also retrieves/rechecks
   the linked Order against the retained snapshot before a new local release.
   Only provider `COMPLETED` payment may release. An Order read failure or drift
   after successful payment means paid-but-held reconciliation, not recharging.
   Already committed receipts retain accepted historical replay/purge behavior;
   do not require erased snapshots to materialize again or substitute settings.

## Identity, schema and compound dispatch ownership

Add nullable `provider_order_reference varchar(191)` with a unique index to the
existing InnoDB attempt table. Do not put an Order ID in `provider_reference` or
the local order's `payment_intent_id`: those remain Payment IDs. Extend the real
column/index readiness contract and advance schema version only after it passes.
Keep the column on rollback; no backfill, destructive migration or separate table.

Only genuinely new attempts acquire immutable `square_orders_protocol=1` and
`square_orders_snapshot_hash=<64 hex>` metadata at creation. The existing safe
metadata boundary supports these small scalar facts (64 keys, depth 3, 256-byte
strings, 8192-byte JSON). Never append the protocol to an existing attempt.
Markers must survive exact persisted validation, not merely caller intent.

Use the existing **non-reclaimable** `claim_irreversible_creation()` as one owner
of both provider POSTs. Approved requests continue using the existing transactional
`claim_square_dispatch()` to lock/validate approval, source request, exact attempt
and bind the active approval before that claim. Do not add an elapsed lease retry.

The distinct Order bind is a transaction/CAS on the exact `dispatching` Square
attempt: checkout/attempt identity, mode/shop binding, immutable protocol/hash,
amount/currency, no Payment reference and no prior Order reference. Lock/re-read
the saved snapshot in the same transaction and verify its hash. On a duplicate
key or competing binder, only an exact same owner/ID may replay; a different owner
or ID is an error. Read failure is not absence. Binding does not change payment
status out of `dispatching`. Recheck authority/binding/approval before money POST
without reclaiming ownership. Do not hold a SQL transaction across provider HTTP.

| Boundary | Durable state and safe behavior |
| --- | --- |
| Before claim | Prepared new-marker attempt may be claimed once after all checks. |
| Claim before Orders POST | Dispatching; other workers return held, even after timeout. |
| Orders POST ambiguous/crash before bind | Original attempt held; no new Orders/payment POST. Manual reconciliation remains a real operational gap. |
| Order bound, crash before payment | Original Order ID retained; no automatic continuation or replacement charge in this packet. |
| Order invalid/bind failure/final check denied | Hold original identity, zero Payment POST; no guessed cancellation or replacement. |
| Payment POST ambiguous/bind failure | Existing unknown hold, original Order/attempt identity; no re-POST. |
| Payment ID bound | Existing payment reconciliation/recovery, plus new-marker Order-ID/economic checks. |
| Paid Order drift/read failure | Hold fulfillment, preserve paid identity; do not charge again. |
| Paid and local release verified | Existing exactly-one fulfillment/kitchen path; Order ID is ancillary immutable linkage. |

Old unmarked v2 attempts, including prepared rows, keep the accepted payment-only
path. Bound/unknown/committed rows are never upgraded into the compound sequence.
Legacy non-v2 blocking remains unchanged. A marked row missing/invalid hash or
bound Order is held, not downgraded to payment-only. Existing HMAC/cart JSON,
checkout/attempt/payment idempotency keys and approval capability hashes remain
unchanged. New Order idempotency uses a separate operation plus the same original
server-owned checkout identity; no token/body hash or new checkout identity.

## Required regression matrix and acceptance sequence

- Known inclusive/exclusive/zero-rate basis; discounts and taxable/non-taxable
  delivery; modified menu/custom-pizza lines; decimal DB strings; rounding drift.
- Unknown/malformed basis, overflow/excess precision, wrong quantity/line total,
  missing/extra/duplicate line UIDs, tax/discount/reference/location drift,
  automatic catalog adjustments, unexpected tip/fee/fulfillment, malformed money.
- Migration missing/mistyped/nonnullable column/index and failed schema upgrade.
- Snapshot/attempt/hash/approval/map drift before claim, bind and final dispatch:
  no money POST. SQL reads/writes/full storage fail closed without partial claims.
- Two actual processes: exactly one CreateOrder and CreatePayment owner; original
  attempt/order/payment identities; one fulfillment and one kitchen hook.
- Every crash boundary above; successful Order response with failed local bind;
  unknown Orders/payment response; no second POST or new identity on retry.
- Old prepared/unknown/paid/committed v2 and legacy unknown-basis snapshots remain
  unchanged; Stripe barriers and accepted receipt-replay/purge fixtures stay green.
- New marker cannot be body-forged, appended, stripped or silently downgraded;
  provider `order_id` mismatch and paid-but-held Orders drift cannot release food.

First review this design against the actual seams and pinned facts. Implement the
pure projection/response validator before wiring schema/dispatch. Then integrate
one coherent runtime batch, run the supported gates, freeze it and obtain the
required fresh runtime assurance. Record exact skipped/live evidence separately.
No push, CI dispatch, deploy, provider activation or backup upload is authorized
by this packet; prepare one consolidated remote batch for separate approval.

Advisory provenance: one previously completed guarded `kimi-k3:cloud` backend call
used synthetic context only. Its immutable-itemization/distinct-reference advice
is useful; its replacement identity after ambiguity and authorized-as-paid ideas
are explicitly rejected. Read-only Terra independently mapped the existing CAS,
approval, snapshot, metadata and compatibility seams; no source edits or repeat
tests were delegated.
