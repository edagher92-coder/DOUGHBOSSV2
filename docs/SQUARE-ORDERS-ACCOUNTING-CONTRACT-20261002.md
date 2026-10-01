# Square Orders accounting-basis prerequisite

Status: proposed; implementation awaits the required bounded Astra design
review at 8.5/10 or higher. Runtime baseline: `23d74726f5b2f22032edba8f7dac16d76fadb7e4`.
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
   accounting basis. All ordinary/request creation callers must be enumerated;
   missing metadata remains unknown rather than inferred.
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
7. Additive rollback: deploy the previous code while retaining the new nullable
   column and data. Do not drop it. New basis-bearing approval fingerprints must
   not be processed by an older binary that lacks their contract; keep payment
   activation disabled throughout migration/rollback and re-review affected
   approvals before any subsequent activation.

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
