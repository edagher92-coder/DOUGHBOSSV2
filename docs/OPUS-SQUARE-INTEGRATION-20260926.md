# DoughBoss V2: Opus findings and complete Square integration

## Scope and source of truth

Owner direction on 26 September 2026: integrate Opus findings and make Square
the complete frontend/backend/API integration. Preserve the earlier requirement
that Kristy approves a request before payment and payment is verified before
kitchen release. This is a WordPress PHP/vanilla-JavaScript project, not SameOS.

Working branch: `codex/square-integration-20260926`, isolated at
`C:\Codex\Workspaces\doughboss-square-20260926` from local commit
`9590541f898edda8e4bd314868992ba0ad649e69`. That parent contains the Stripe
approval flow and is **not** the published tip: PR #68 is still at
`1600a399edefecba91018298d6c1de6f717b94b1`. The task's detached July checkout
`ca79850` must not be deployed over the later work. Preserve all other worktrees.

Finding source: Claude Code **Snowflowsamos**, message 478, read on 26 September:
<https://claude.ai/code/session_014bnrnF78NxeWCeL9KqqBjt>.
Opus reports four audits: the main-branch platform demo, public website,
ordering plugin and DOUGHXSNOW voucher system. The summary is evidence of the
findings, not proof that each applies to the current WordPress candidate.
Its full individual audit artifacts were not present in the checked New branch.
The earlier imported-live-baseline review is PR #65:
<https://github.com/edagher92-coder/DOUGHBOSSV2/pull/65>.

## Fresh production observations (read-only)

- Public `/wp-json/doughboss/v1/config`: HTTP 200, AUD, 10% GST-inclusive,
  ordering false, payments false, after-hours requests false, gateway Stripe.
- Public menu: HTTP 200, 43 items. Locations: HTTP 200, one location.
- `/menu/` rendered names and prices in Chrome, including Zaatar $4.50 and
  Zaatar & Cheese $8.50; buttons remained disabled with coming-soon labels.
  No browser errors/warnings were reported in this check.
- Served plugin CSS/JS version identifiers: **2.41.0**. This is asset evidence,
  not an authenticated plugin inventory. Live `/menu/` canonical points to itself.
- No production settings, payments, orders, shifts, vouchers or files changed.

## Opus disposition register

`Present in candidate` does not mean deployed. `Not reproduced` does not erase a
historical failure. Findings outside this plugin remain explicit follow-up work.

| Finding | Current disposition and acceptance requirement |
| --- | --- |
| Demo owner screen prints raw `<b>` tags | Open in main-branch demo; correct text rendering without accepting arbitrary HTML. Not present in this plugin/theme branch. |
| Demo orders contain non-menu sample food | Open in demo; replace with approved menu fixtures, clearly labelled synthetic. |
| Demo menu has no items/prices | Open in demo; publish actual approved menu content with source/date. |
| Demo canonical/social URLs point to GitHub | Open in demo; production domain only for the actual deployed production build, keep preview non-indexed. |
| Demo accepts any staff ID/no real backend | Demo only, not production authentication. Do not publish as staff or kitchen access. |
| Item allergen details (sesame/hazelnut) | Open: owner-verified per-item allergens and cross-contact wording required. Existing dietary flags are not certification or allergen proof. |
| Franchise-page caution | Open: review page content and legal wording before accepting fees. |
| One demo text contrast failure | Open in demo; reproduce affected element and measure after correction. |
| Static host ignores security headers | Open for that deployment; verify actual HTTP headers on intended host. |
| Privacy/terms drafts and franchise legal review | Owner/legal sign-off remains outstanding; no model review substitutes for it. |
| Live menu fails/table-QR guard | Not reproduced in fresh public API and Chrome checks. Candidate has separate menu and table-context paths. Retain regression scenario: public visitor with no table cookie sees menu. |
| Order Online CTA while ordering paused | Confirmed copy mismatch: coming-soon banner plus Order Online heading/nav. Use call-to-order CTA until activation, driven by one status. |
| Duplicate `/order/` and `/menu/` SEO | Candidate already has shared canonical filter in theme `functions.php`; live menu still self-canonical. Deployment and served-page verification required. |
| Only Revesby in local structured data | Open: verified public branch facts may enter schema; do not infer online-order eligibility for other shops. |
| Browser-only menu content hurts discovery | Open: server-rendered menu/prices with accessible enhancement and canonical authority. |
| Blank contact/duplicate vouchers/indexed kitchen | Open in production page/SEO configuration; verify exact pages before redirects or noindex changes. No deletion authorized by audit text. |
| Plugin takes no payments | Production is deliberately disabled. Candidate has gateways, including Square; activation is not complete integration. |
| No locations/opening-hours engine | Contradicted for candidate: Locations and Store Hours exist. Fresh live API returns one configured shop. Actual other-shop activation remains a separate gate. |
| No sold-out support | Unresolved: trace availability through staff controls, menu API and add-to-cart refusal before closing. |
| No pickup-time picker | Candidate has scheduling/capacity foundation; end-to-end picker and Square fulfillment agreement still require acceptance. |
| No sizes/add-ons | Contradicted for candidate: Menu Options and server-side resolution exist; verify real catalog mapping before Square cutover. |
| No catering | Contradicted for candidate: catering estimator/enquiry/payment structures exist; Square catering deposits/balances remain unmigrated. |
| USD/exclusive tax | Contradicted by fresh live AUD/GST-inclusive config and candidate defaults. Preserve deterministic cents and reconcile each line/discount/tax into Square. |
| No GST/ABN breakdown on larger orders | Open: invoice/docket content must use owner-verified business identity; never invent an ABN. |
| No checkout/tracking rate limits | Candidate contains rate-limit guards; audit each public route and production deployment before closing. |
| Email failures not recorded | Approval candidate records payment-link delivery state; general order/kitchen notification receipt and retry coverage still needs review. |
| No daily totals/export/docket | Kitchen/printer/admin paths exist in candidate; verify reporting and actual printer acceptance. Square reporting remains open. |
| DOUGHXSNOW single-use voucher concurrency passed | Opus evidence only; do not claim independently verified here. |
| Two voucher systems | Owner clarified on 1 October: Square Gift Cards, not Xero, is the intended new stored-value authority. Legacy promotional issuance pause is implemented locally; preservation/redemption and a reconciled cutover remain required. No promotional code is converted into a prepaid balance. |
| Voucher demo login/admin PINs | DOUGHXSNOW deployment gate, separate repository. Do not ship demo identity. |
| Voucher backup/outage plan | Operational gate; restore proof and counter fallback required before print launch. |
| Voucher batch creation needs developer | DOUGHXSNOW admin capability backlog; not silently added to this WordPress batch. |

## Confirmed Square integration gaps and build order

1. **Ownership and location binding.** Confirm one merchant versus multiple
   sellers, Revesby pilot, application/environment, exact native shop-to-Square
   location mapping, catalog/pricing owner and kitchen target. Current tokens
   are manually configured, server-only. OAuth lifecycle is absent. One global
   Square location per environment must not silently serve additional shops.
2. **Approval contract.** Existing approved-preorder route and release guards
   explicitly require Stripe. Normal Square checkout does not implement the
   owner's approval-for-all-orders requirement. Bind one approved revision and
   immutable snapshot to one Square payment attempt; deny pre-approval charging.
3. **Itemized Orders API.** Create/replay one Square order from that snapshot,
   mapped catalog variations/modifiers and pickup details, then associate its
   ID with the payment. Reconcile exact totals in integer cents. A payment alone
   is not a Square POS/KDS ticket. No `/v2/orders` implementation exists yet.
4. **Recovery and kitchen ownership.** Signed payment events currently record
   unreconciled charges for manual review if browser checkout never completes.
   Implement durable recovery without double charge/order/dispatch. Designate
   Square or existing kitchen/POSPal ownership, never automatic dual dispatch.
5. **Refund lifecycle and reports.** This batch rejects pending/mismatched refund
   responses as completion. Still needed: durable refund receipts, signed
   refund updates, partial/external refund reconciliation, settlements and
   location/channel reporting. Reposting the same idempotency key is not proof
   that a pending refund has since completed; retrieve/reconcile its status.
6. **Sandbox then physical acceptance.** Prove success, decline, cancelled 3DS,
   unknown outcome, refresh/abandonment, request/webhook replay, amount/currency/
   location mismatch, refund lifecycle and exactly one kitchen release with
   synthetic data. Then verify actual POS/printer/KDS delivery with an approved
   staffed pilot. No merchant secrets in chat, source, browser config or logs.

Official contracts checked on 26 September 2026:
- [Card tokenization and buyer-verification migration](https://developer.squareup.com/docs/web-payments/take-card-payment)
- [Itemized orders, fulfillment and POS visibility](https://developer.squareup.com/docs/orders-api/what-it-does)
- [Refund creation can return PENDING](https://developer.squareup.com/reference/square/refunds-api/refund-payment)

## Current bounded local batch

Outcome: migrate browser verification to `card.tokenize(verificationDetails)`
without a failure fallback; accept Square refunds as complete only with exact
provider evidence. Existing irreversible-attempt/replay protection stays intact.
No schema, production dependency, account connection or production activation.

Files: Square gateway, Square browser controller, nearest PHP/Node/integration
tests, and this requirement/evidence register. Proof: focused behavioral tests,
full existing release gate and disposable WordPress/MySQL integration suite.
Fresh read-only review required before accepting the frozen security diff.

## Local validation evidence

All commands below ran in the isolated working branch on 26 September 2026.

- `scripts/test-release.ps1` with PHP 8.2.33 and its ZIP extension: exit 0;
  96 PHP syntax files, 445 PHP assertions, 16 JavaScript syntax files and
  39 Node tests passed. Plugin ZIP: 162 source-matching files, version 2.44.0;
  theme ZIP: 25 source-matching files, version 1.6.2.
- PHP 7.4.33 `-n tests/run.php`: exit 0, 423 assertions passed. ZIP-dependent
  checks were unavailable on that runtime (its ZIP DLL is absent), not passed.
  Archive validation above used PHP 8.2.33.
- `scripts/test-wordpress-integration.ps1`: exit 0, 442/442 assertions on the
  existing disposable `doughboss_wp_test` database at `127.0.0.1:33117`, with
  local environment and `http://doughboss.local.test` verified before install.
  Provider transport was intercepted with synthetic fixtures and outbound HTTP
  blocked. The installer reported expected blocked WordPress update checks.
- Two-process Square race: exactly one synthetic provider POST, no duplicate
  charge. Approved-preorder start/completion races: both explicit barriers
  crossed, one synthetic Stripe POST and one fulfillment creation.
- `git diff --check`: exit 0. No hosted CI, push, deployment or real payment.

## Frozen-candidate assurance

Astra reviewed commit `e77c37135772c5a6712e8c033c93d7bfc14bc25c` against the
parent above, all six changed paths and adjacent callers. Verdict: ship the
bounded local safety patch, **8.8/10**, no blocking findings. This exceeds the
owner's 8.5 threshold only for these local safety changes, not Square readiness.
The reviewer independently passed four focused Square Node tests and diff
checks, and inspected the existing admin error gate and replay contracts.
The broader local suite evidence above was lead-run, not rerun by the reviewer.

Two nonblocking test improvements are deferred to the next payment-lifecycle
batch, owned by the lead integrator: exercise actual admin persisted state for
pending/completed refunds, and browser post-request network failure through the
existing failure handler. Current caller inspection supports the safety
contract; the unchanged server replay path also passed concurrent tests.
These improvements do not remove the real SDK/3DS, refund reconciliation and
staffed POS/kitchen acceptance gates. No provider acceptance is claimed.

This follow-up changes documentation only; the reviewed implementation remains
unchanged. Overall
Square integration and incorporation of every Opus finding remain **incomplete**
until the open rows and operational gates above are independently closed.
