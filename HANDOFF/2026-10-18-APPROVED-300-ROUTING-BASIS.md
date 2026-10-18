# Owner-approved A$300 routing amount basis — 18 October 2026

## Approved decision

The owner answered the outstanding ordinary-order routing question:

> Food-and-drink total after discounts, including GST, before delivery/service fees (recommended)

Therefore catering always uses Revesby. For ordinary orders, compare the authoritative discounted food-and-drink amount including GST against **A$300.00 inclusive**: A$300.00 and above require Revesby; below A$300.00 use the customer's selected eligible connected shop. This resolves the amount-basis question; it is not per-item tax classification, approval of a new rounding policy, another shop's connection or commerce activation.

Do not compare the undiscounted subtotal, final amount including fees, outstanding balance after tender, or a browser-supplied amount. The comparison must use integer AUD cents derived from the existing validated server accounting calculation, without adding GST again to inclusive prices. Amounts that are not food/drink charges must not move the routing threshold. A payment tender does not by itself discount the food/drink amount.

## Required boundary cases — specifications, not passing application tests

| Food/drink including GST before discount | Goods discount | Delivery/service fees | Routing amount | Required shop |
| --- | --- | --- | --- | --- |
| A$299.99 | A$0.00 | A$25.00 | A$299.99 | Selected eligible connected shop |
| A$300.00 | A$0.00 | A$0.00 | A$300.00 | Revesby |
| A$300.01 | A$0.00 | A$0.00 | A$300.01 | Revesby |
| A$310.00 | A$10.00 | A$25.00 | A$300.00 | Revesby |
| A$310.00 | A$10.01 | A$25.00 | A$299.99 | Selected eligible connected shop |
| Any catering amount | Any applicable approved discount | Any applicable fee | Not threshold-dependent | Revesby |

## Current implementation and next code batch

Read-only source inspection at `155eead9ac22cce380b6d8a4aede2e3be76e4f02` found:

- `DoughBoss_Cart::totals()` computes server subtotal/discount/GST/delivery totals and accounting basis. Preserve its accounting and voucher semantics; do not subtract tax from an inclusive goods amount or subtract a discount twice.
- REST `resolve_order_location()` validates the requested active shop and fulfilment capability; it has no ordinary A$300 amount check. `preorder_request()` separately resolves its location before it computes totals and creates the order. A source search found no ordinary A$300 threshold implementation. Do not label the threshold enforced from this decision record.
- Existing Revesby catering and after-hours request checks do not establish ordinary-order amount routing. `single_location_mode` is not a substitute for a monetary guard or seller binding.

Before multi-shop ordering is admitted, implement the rule on authoritative new-order/payment-entry paths, with matching customer guidance and regression coverage. Inspect ordinary and staff-approved snapshots, discount revalidation, pickup/capacity selection and seller-scoped payment binding before choosing the seam. Preserve committed orders and irreversible payment/retry recovery: never silently migrate a paid order or reused attempt to another seller. Require an explicit customer-confirmed shop/pickup context and new validation where a shop must change; do not charge one merchant and send the kitchen order elsewhere.

Required proof includes the cases above, missing/unavailable Revesby, direct/stale-client bypass, ordinary and staff-approved creation, and unchanged recovery of already-started attempts. These checks have **not** run in this documentation batch. No schema, API, runtime, database, provider or live setting changed. Current native R18/package evidence is therefore unchanged, not evidence of this unimplemented rule.

## Deferred pack and other gates

This decision supersedes the unresolved amount-basis wording in the frozen `deferred-shop-reference-20261018-r1.zip` and older handoffs. Keep that archive and its manifest/hash unchanged; distribute this companion decision with it. Its tax rows remain UNREVIEWED and both destination accounts remain unaccepted/deferred.

This resolves only the routing-policy question. Square sign-in, private-restore diagnostic approval, tax classification/rounding, financial acceptance, physical KDS and production release gates remain separate. No claim of live routing or launch acceptance is made.

Documentation validation: `git diff --check` passed; a Node `assert` check verified the five ordinary examples' integer-cent arithmetic (5/5), not application enforcement. The frozen R1 ZIP still hashes to `72ca9b57ff5cca04b9b3060e618598c3bc65c9e6138c5e5814b54c9cbabca3bd`. No runtime suite was repeated for these documentation-only changes. A bounded Sol 6.1 discovery follow-up was rejected before execution by the occupied agent/thread limit; no substitute or final Sol code verdict is claimed. Preserve the next implementation/gate requirement rather than treating this owner-decision transcription as a tested routing release.
