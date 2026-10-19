# Revesby acceptance evidence refresh

18 October 2026, Australia/Sydney. Read-only source/handoff reconciliation at `5d4cce59cd0d0870bee492daeb6eb1cd197fa849`. No provider, checkout, financial, device or deployment action occurred in this refresh.

## Superseded statements — do not repeat as current blockers

- The older 16 October `2718/2760, 42 failed` runtime result is historical, not the latest accepted local PHP result. `2026-10-17-AVIF-MIME.md:15-16` and `2026-10-18-FRONTEND-PERFORMANCE-BATCH.md:19-20` record **3105/3105 PHP 8.2** and **3035/3035 PHP 7.4**, zero failures. These remain local synthetic evidence, not real WordPress/database or Square acceptance. A source comparison from `1b7389ab4779c9466040bdf434e454abfcc39310` to this checkpoint showed only `themes/doughboss-final/functions.php` among PHP changes; it did not show a new plugin backend change. The frontend handoff records the theme syntax check.
- Pickup promise semantics were owner-confirmed, not awaiting a fresh choice: preserve website preparation timing in the immutable snapshot and send the **exact website-promised time** as Square `SCHEDULED`, including when the website labels it ASAP. See `2026-10-17-PICKUP-V2-AND-RESTORE-R12.md:7` and `2026-10-17-REVESBY-READINESS.md:82-84`. Do not let Square silently recalculate another ASAP promise.
- Current source contains a distinct Revesby-only `square-orders-v2-pickup` fulfilment branch. Older metadata-only/v1 observations do not establish that the later branch is absent. Source presence still does not prove Square compatibility, deployment or KDS delivery.

Historical handoffs are preserved, not rewritten into passes. No old failed test was rerun solely to refresh a label.

## Remaining evidence to obtain

| Area | Current evidence boundary | Next proof |
| --- | --- | --- |
| Core publication | Prepared source/package and local tests are not installed-tree parity or publication. The 17 October installed 2.49.0 observation is historical. | Complete restore acceptance, current full installed manifest comparison, fresh backup/rollback and controlled publication. |
| Merchant/location/account | Source validates configured shop/mode/location; a configured location ID does not prove legal seller identity or OAuth health. | Current masked read-only Revesby identity, scope, location and environment readback, separately for sandbox/live where applicable. |
| Catalogue/options/photos/prices/availability | Guarded preview and tax matching exist in source. Complete provider parity was not inspected in this refresh. | Bounded website/candidate/provider inventory reconciliation with per-item/modifier/availability evidence; preserve displayed prices. |
| Item tax and rounding | Owner said prices include GST; this does not classify every item or settle mixed taxable/exempt treatment and modifier rounding. | Approved per-item tax/rounding treatment and line reconciliation, without adding GST on top. |
| A$300 routing | Catering/large orders to Revesby, ordinary small orders to the chosen connected shop; other accounts remain deferred. The amount basis is still recorded as unresolved. | Owner-approved basis for discounts, fees, tips and rounding before consequential threshold logic. Do not silently select one. |
| Payment/order/retry/webhook/refund/reporting | Local safeguards and tests exist; no fresh provider acceptance was performed in this refresh. | Bounded explicitly authorized sandbox lifecycle, duplicate/replay and seller-scoped reconciliation evidence, preserving existing financial gates. |
| Gift cards / loyalty | Read-only balance observation is not tender authority; UI/configuration is not programme activation or redemption acceptance. | Separate Square product/account configuration and permitted end-to-end acceptance. |
| Staff/timecards/KDS | Source capabilities are not Square staff permissions or device receipt. The old report that screens were uninstalled is not a current device check. | Current Square role/timecard setup, real screen installation/sign-in and staffed ticket-delivery evidence. |
| Bankstown/Roselands | Connections deferred; their seller/ABN, credentials, taxes and devices cannot inherit Revesby's acceptance. | Preserve reproducible catalogue/configuration/validation/rollback material and independently bind each account later. |

Keep ordering, payments, Ads spending and unvalidated membership activation off unless their separately required gates are met. The evidence map is a continuation guide, not operational acceptance.
