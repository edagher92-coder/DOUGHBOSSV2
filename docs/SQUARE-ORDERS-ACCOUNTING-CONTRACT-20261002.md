# Square Orders accounting-basis prerequisite

Status: revised proposal; `38efe9d` scored 8.0/10 and `aa05fdd` scored 8.3/10,
both fix-first. The latter exposed the ordinary retry-identity migration seam.
Implementation awaits fresh bounded Astra design review at 8.5/10 or higher.
Runtime baseline: `23d74726f5b2f22032edba8f7dac16d76fadb7e4`.
This is one prerequisite of the complete Square integration, not its completion.

## Confirmed gap

The approved Square snapshot constructed by the REST controller contains the
approved order totals, contact data, immutable canonical lines, approval revision,
fingerprint and Square shop binding. It does not contain the historical tax rate
or whether the particular request was priced GST-inclusive or GST-exclusive.
`DoughBoss_Cart::totals()` knows that distinction when pricing, but an approved
request's persisted order row discards it. Current settings are not historical
evidence. Pickup commitments likewise cannot be inferred from generic notes.

Source evidence: `class-doughboss-cart.php:321-357`,
`class-doughboss-rest-controller.php:3847-3880`,
`class-doughboss-order.php:390-447`, and
`class-doughboss-preorder-approval.php:1027-1035`. The existing immutable snapshot
storage verifies the exact payload hash; it cannot recover discarded facts.

## Outcome and non-goals

Capture the exact server tax basis used to price a newly created request, persist
it with that request, include it in its approval fingerprint and payment snapshot,
and preserve it into the paid local fulfillment. Settings drift must not alter it.
Legacy requests without recorded basis remain unknown. Never backfill from
settings or reverse-engineer a rate from aggregate money.

This phase does not create Square Orders, charge cards, route Square fulfillment,
enable ordering/payments, redeem Gift Cards, change monetary pricing policy,
recalculate approved totals, choose a kitchen owner, or promise pickup times.
It does not add a production dependency or issue a real provider request.

## Minimal contract and migration

1. Add one nullable `accounting_basis_json` column to the existing orders table.
   Increment the plugin database schema version. No separate ledger/table/stack.
   Existing rows retain NULL; no destructive migration or data rewrite.
2. The version-1 basis records `version`, `tax_inclusive`, `tax_rate`, and the
   explicit existing discount/delivery policy. `tax_inclusive` is a boolean.
   `tax_rate` is the exact finite non-negative numeric rate used by the server's
   pricing calculation, not read again at order creation or payment time.
   The policy is goods discount before tax; delivery is taxable in the current
   inclusive branch and excluded from the exclusive branch. These are observed
   existing behaviors, not proposed business changes.
3. `totals()` reads its mode and rate once for both calculation and basis output.
   REST constructs order data from server totals and never forwards body/query
   accounting basis. The bounded caller map below controls capture versus copy;
   missing historical metadata remains unknown rather than inferred.
4. The order persistence boundary validates the supplied version, exact allowed
   fields, primitive types, bounded JSON size and policy tuple before storage.
   Unsupported/malformed supplied basis is an error before durable order/items.
   Missing basis is NULL for backward-compatible callers. Unknown versions
   remain fail-closed. Existing decimal money values remain unchanged.
5. Approval fingerprints include valid recorded basis. Legacy NULL/absent basis
   is excluded from the old fingerprint shape so existing approvals do not
   silently change. A malformed non-NULL value must not be treated as legacy
   absence, and cannot obtain a usable current approval/payment snapshot.
6. Both approved gateway snapshots copy the basis from the persisted request,
   not current settings. Where basis is known, request/snapshot equality checks
   and paid release carry it unchanged. Existing legacy payment/recovery paths
   remain compatible; future Square Orders itemization requires known basis.
7. Additive rollback retains the nullable column and all metadata. Do not drop
   it. Old binaries cannot process basis-bearing approvals. The recovery-state
   matrix below is mandatory; payment activation OFF alone is not quiescence.

## Frozen v1 codec

The literal normalized object is:

```json
{
  "version": 1,
  "tax_inclusive": true,
  "tax_rate": 10,
  "discount_policy": "goods_before_tax",
  "delivery_taxable": true
}
```

Exactly these five keys are allowed. Version is integer 1; mode/delivery are
booleans and must be identical for this existing pricing-policy version.
Discount policy has only the literal value above. Rate accepts only an integer
or finite float >= 0: no numeric strings, booleans, coercion, or rate rounding.
Capture the same numeric value already used by totals, without re-reading the
setting. Provider-specific admissible tax rates remain a later Orders gate;
capturing historical facts must not pretend they are provider-compatible.

One lead-owned codec validates, normalizes numeric rate to float without rounding,
and emits keys in the literal order above via `wp_json_encode`, maximum 1024 bytes.
An encoder failure is invalid, not absence. Decode validates then re-encodes;
persisted non-NULL JSON must equal that canonical encoding byte-for-byte. This
also rejects duplicate/extra keys and ambiguous alternate representations.
Transport/snapshot uses the normalized PHP object; equality uses its canonical
JSON bytes, not array key order, loose equality or current settings. Int/float
representations of the same rate normalize through the same codec.

Only SQL NULL (or absent field in a legacy in-memory fixture) means unknown.
Empty string, whitespace, JSON null, scalar, empty object/array, malformed JSON,
unsupported version, invalid types and noncanonical stored JSON are invalid.
Do not collapse those states. The codec returns valid normalized data, explicit
unknown, or `WP_Error`; it must not return an empty object as success.

Fingerprint helper error propagation is part of this change: audit every caller
of `fingerprint_from_order`, `fingerprint_from_locked_order`, and the public
fingerprint method, including current approval/claim/dispatch/release callers at
preorder-approval lines 234, 409-410, 599 and 1020-1035. Check `is_wp_error` and
the 64-character fingerprint shape before any `hash_equals`. Invalid basis must
return a specific deny/hold outcome before approval capability, provider dispatch
or fulfillment, never a PHP type error or an empty/legacy fingerprint. Preserve
the exact existing recovery identities and state-machine error conventions.

## Legacy and snapshot compatibility matrix

| Persisted request basis | Snapshot basis | Required result |
| --- | --- | --- |
| SQL NULL/legacy absent | Absent key | Preserve exact old fingerprint and snapshot shape/hash; existing recovery remains valid. |
| Valid known basis | Same valid canonical object | Include basis in new fingerprint/snapshot and copy it to paid fulfillment. |
| Valid known basis | Absent, NULL or different | Deny/hold before dispatch or release; never infer from settings. |
| Legacy unknown | Unexpected known/NULL key | Deny/hold; it is not the original legacy payload. |
| Any malformed non-NULL basis | Any | Deny/hold; no legacy fallback. |

Never mutate an already stored snapshot to add metadata. Seed pre-change approved
Stripe and Square snapshots in tests; assert identical payload hashes,
fingerprints, approval revisions, capabilities, checkout keys, attempt identities,
and successful existing replay/recovery after migration. NULL is omitted, not
serialized into the legacy fingerprint or gateway snapshot.

## Bounded capture/copy and readiness map

Existing production `DoughBoss_Order::create()` callers in the REST controller:

| Baseline line/path | Authoritative basis source |
| --- | --- |
| 3558: submit an unpaid approval request | Capture from the same pickup cart totals that priced the request. Ignore body/query basis. |
| 4079: approved Square confirmation/webhook recovery | Copy from the verified immutable snapshot's order data; retain unknown for legacy snapshots. |
| 4452: ordinary checkout creation | For freshly priced unpaid checkout, capture same server totals. For paid Square or Stripe snapshot-backed checkout, copy snapshot basis only. The paid Square totals reconstruction at 4362-4378 must copy basis, never attach a fresh cart basis. |
| 6215: Stripe webhook recovery | Copy immutable snapshot basis with its original accounting facts; unknown remains unknown. |

Also capture basis when creating a genuinely new ordinary payment snapshot before
money moves, from that same cart totals computation (order payload at REST 2802).
Existing ordinary retries follow the exact identity/payload contract below.
Approved Stripe (REST 3741 snapshot) and approved Square (REST 3871 snapshot)
copy only the persisted request basis, with the compatibility matrix above.
Common paid release in `DoughBoss_Preorder_Approval::release_locked()` requires
the same basis-preservation validation before either gateway creates or replays
fulfillment. Extend both the current Square comparator and the distinct Stripe
branch at 618-627; no gateway bypass. Paid fulfillment persists the original
known basis, or remains NULL for an unchanged legacy contract.

Physical contract: existing InnoDB orders table, `accounting_basis_json` LONGTEXT,
nullable YES, NULL default. Include it in the existing checkout storage readiness
check using the activator's column-contract machinery, not only CREATE TABLE.
Activator advances stored schema version only after its existing readiness gate
including this column succeeds. Missing/mis-typed/nonnullable column or failed
upgrade blocks new request/checkout/approval/payment provisioning before provider
dispatch and prevents readiness/version claims. Do not drop/rewrite legacy rows.
Recovery with missing schema remains held for the basis-aware repaired schema;
it must not create replacement payment identities to escape a storage failure.

## Rollback and recovery state matrix

Before older code runs, quiesce every consumer of basis-bearing work: new starts,
webhooks, confirmation, reconciliation, retry workers and release. Either prove
there is no such in-flight/recoverable work, or quarantine it durably for the
basis-aware binary. A flag disabling new ordering/payments is not sufficient.

| Basis-bearing state | Rollback behavior |
| --- | --- |
| Approved, unpaid, no irreversible dispatch | Hold unchanged. Reapproval is permitted only by the existing state-machine proof of no money dispatch, never merely by operator assumption. |
| Dispatching or unknown outcome | Quarantine and resume only with basis-aware code on original revision/fingerprint/checkout key/attempt/snapshot. No new key or second charge. |
| Verified paid, not released | Quarantine original paid identity; basis-aware reconciliation/release only. No reapproval or replacement charge. |
| Released | Preserve original paid order and release identity; old code must not replay the new approval contract or create another kitchen release. |

Test this matrix locally with synthetic fixtures; no real provider calls are
needed. Rollback readiness remains unproven until all workers/routes can be
quiesced through supported controls; do not describe the prior binary as able to
process new basis-bearing approvals.

## Ordinary checkout identity and retry migration

`Cart::to_array()` must retain its shipped public-cart/totals projection byte for
byte in JSON encoding: do not add accounting basis to it. The internal `totals()`
result can carry basis for preparation, but `to_array()` omits that new metadata
while retaining existing key order, types and values. Every current Stripe HMAC
producer/verifier continues using the identical projection and existing nonce,
purpose, currency and routing inputs (REST 2752-2756, 3013-3018, 5004-5007).
No key version, replacement nonce, global HMAC relaxation or identity reminting.
Square's existing explicit binding payload (3054-3071) is likewise unchanged.
This does not change approval fingerprints: known persisted basis is included
there as specified above.

Before ordinary provisioning, find and validate the existing immutable snapshot
for that exact checkout/attempt through the current protected expiry/recovery
rules. Validate any recorded basis; malformed/NULL-present basis is not legacy.
Compare every other canonical order/cart/line/contact/money/routing fact to the
candidate using the existing equality/hash discipline. Do not blindly substitute
an old snapshot for a changed request. If non-basis facts differ, deny/hold through
the existing state machine without a new provider/session or payment identity.

If an existing valid snapshot belongs to this unchanged retry, reuse its exact
original payload and original basis state, including absent/unknown for a legacy
snapshot. Current pricing metadata must neither overwrite a known historical
basis nor retrofit an unknown one. Preserve its stored bytes/hash, checkout key,
approval/capability if applicable, attempt identity and provider reference.
If needed, compare a candidate with the existing snapshot's basis copied/omitted,
then replay that unchanged snapshot through `store()`; never rewrite the row or
relax its general payload equality guard. A genuinely new snapshot, with no
existing protected snapshot/attempt to replay, captures the newly computed basis.
All existing blocking-attempt/irreversible-outcome guards remain prerequisites.

Seed pre-change ordinary Stripe and Square snapshots/attempts. After migration,
exercise preparation retries and paid recovery and assert unchanged HMAC/key,
snapshot bytes/hash, attempt/provider identities and zero extra payment/session
provisioning; unknown remains unknown. Also exercise a new attempt that captures
basis, a known-basis retry after settings drift, and changed non-basis payload
denial. No new identity or speculative basis may escape a failed legacy replay.

## Proof and acceptance

Extend the nearest existing PHP/WordPress fixtures. Demonstrate inclusive and
exclusive requests retain the exact captured mode/rate after settings change;
discount/delivery policy is explicit; modifiers are not repriced; malformed,
unknown-version and client-forged basis is denied/ignored at the right boundary;
legacy NULL requests are preserved without guessed metadata; recorded-basis
tampering invalidates approval before provider dispatch; and snapshot/release
copy exact basis. Exercise storage failure and transactional rollback using the
existing real WordPress/MySQL test harness. Preserve old approval recovery and
Square/Stripe concurrency regressions, without weakening tests.

Expected files: `doughboss.php`, activator, cart, order, preorder approval,
REST controller, nearest existing unit/integration tests and this evidence doc.
Shared schema/approval/payment seams remain lead-owned; no overlapping writers.
Run syntax/unit checks, real WordPress/MySQL/race tests and the final supported
release gate. Freeze the runtime candidate for fresh required assurance.

## Following Orders slice: constraints already settled

- One canonical line uses its approved final unit price, already including paid
  modifiers. Do not add topping prices a second time or read a mutable catalog.
- Provider order ID is distinct from payment ID. Persist an immutable, conditional
  order reference against the exact existing payment attempt/shop/mode/snapshot.
- Verify response itemization, currency, discounts, tax, fees and total against
  frozen facts before attaching `order_id` to a money dispatch.
- An unpaid Square Order must not cause kitchen/POS fulfillment. No pickup
  fulfillment is invented from notes. Unknown outcomes retain their durable
  hold and must not start a second payment under a new identity.
- Square API state and recovery semantics require official-contract validation;
  advisory claims about finding a payment by idempotency key or automatic
  fulfillment transitions are not accepted provider facts.

## Advisory provenance

Read-only Terra source map confirmed the basis/timing gap and canonical line
facts. One guarded Ollama backend call used preferred `kimi-k3:cloud` with a
synthetic description only, no source files, customer data or credentials.
It independently recommended a frozen basis, distinct durable order linkage,
response reconciliation and unpaid-fulfillment isolation. Its proposed extra
order-intent table and generic ListPayments/idempotency recovery are not accepted:
reuse the existing attempt seam and validate real Square recovery contracts.

No remote publication, provider mutation or credential transfer is authorized
by this design. Live ordering/payments remain OFF.
