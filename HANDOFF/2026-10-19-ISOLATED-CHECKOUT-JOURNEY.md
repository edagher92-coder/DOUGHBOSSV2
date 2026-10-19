# Isolated cart and checkout browser recovery — local evidence

19 October 2026, Australia/Sydney. Runtime source commit 00ec0a2fa146582e2cb4b4fbec08d7bdc79af11a, branch codex/2.51-handoff-20261016. Continues the exact owner-approved premium checkout-readiness batch despite the weekly reserve. Supported read at this boundary: 94% used / 6% remaining; no purchase, reset or billing change.

## Scope and isolation

Outcome: close missing ordinary cart mutation, duplicate-submit, reload and failure-recovery browser evidence using actual accepted storefront code. Non-goals: no production runtime change, public-preview weakening, provider/payment/Ads/shop/device action or publication. Files: a separate synthetic fixture and tests under C:/Codex/Output/doughboss-checkout-journey-20261019, plus this handoff. No new framework/dependency or production API/schema is introduced.

The standalone fixture runs at loopback 127.0.0.1:8131, clearly marked ISOLATED TEST — NO REAL ORDERS. The existing accepted read-only preview at8130 is unchanged. The fixture copies accepted CSS/fonts/images/storefront runtime byte-exact. Storefront JS SHA256 is 09E06878B0D93A4FE93250D24733454A28A9B6E5629465462336FCCF6F1D09A0. A separate synthetic adapter never forwards fetch; CSP connect-src/form-action/default-src none and self-only scripts/assets independently constrain escape. Unknown routes and provider/payment-intent requests are refused.

Two invented products and prices only: fixture Zaatar & Cheese $8.50 flat/$9.50 folded and fixture Coke600mL $4.00; shop901 is synthetic, not a merchant/provider location. Fake server state/journal persists in a dedicated sessionStorage key on this separate localhost origin. Contact data is not stored by the adapter. Actual radio wire strings are validated/translated at the fixture boundary; production wire format is unchanged. Ordinary pickup/pay-at-shop mode is simulated; pickup scheduling, staff approval-required ordering and payment flows are not exercised.

## Qwen coding contribution and quality limits

New availability evidence justified one deliberate fresh task after the earlier timeouts: current governor ready/idle at32K and native health returned ok. A historical ownership error was still retained in status; no process was restarted/reconfigured and no current status was mistaken for throughput proof.

Actual mcp__qwen__ask_local, task code, check_with_claude false, model qwen3.8-27b:code:
- Initial draft:556prompt /1616output tokens,49.625s connector elapsed,33.6594 native generation tokens/s, stop/non-truncated. First-content timing not exposed.
- One targeted correction:2083prompt /1823output tokens,52.312s elapsed,37.9872 native generation tokens/s,4.327s first-content,45reported progress updates, stop/non-truncated. Actual app display latency is not independently measured.

Both raw drafts are preserved as non-executed proposals. Each failed five of the original seven tests. Initial Map iteration emitted empty carts; restore expected options absent from its own snapshot and ignored canonical keys. Correction repaired iteration/numeric IDs but left restore wrong and rejected cross-realm ordinary objects. Root repaired plain-object validation and reconstructs allowed options from stored topping slugs, validates exact keys and recomputes prices/totals. Final fixture-cart.js passes seven ledger tests. Root owns HTML/transport/CSP/controls and final integration. This is a supervised Qwen-derived TEST FIXTURE, not accepted production cart/backend code or evidence of unsupervised coding qualification. No further model retry.

## Fresh local tests

node --test fixture-cart.test.cjs:9passed,0failed,0skipped. Seven ledger tests cover exact totals, canonical options, merge/update/remove,1..99boundaries with atomic rejection, unknown/malformed input, snapshot isolation and restore identity/price recomputation. Two additional checks verify accepted runtime byte parity, isolation headers/labels, refusal to forward provider requests, actual radio string adaptation, failed update stability, same-key lost-response replay producing one fake order, and no stored contact data. JavaScript syntax checks pass.

## Actual browser evidence

Real IAB at390x844 using unchanged storefront code:
1. Opened active mobile customiser, selected Folded +$1.00, added via sheet; journal showed actual POST /cart/add option=folded; cart total$9.50 and Folded shown.
2. Added Coke: total$13.50. Typed synthetic Name/Email/Mobile. Quantity increase on folded item to2 produced$23.00/3items without replacing checkout form or clearing the name; email/mobile remained nonempty (browser redacted exact values).
3. Armed one quantity failure: failed request leftquantity2/total$23.00, exposed failure toast and re-enabled controls. Retry reachedquantity3/total$32.50/4items with contact fields preserved.
4. Reload restored quantities3and1/total$32.50. All three contact fields were empty and optional remember-details unchecked. This proves this unchecked fixture path, not real expiry or every storage browser.
5. Filled synthetic contact fields and performed actual button dblclick. Exactly1checkout request reached the adapter; submitdisabled and form aria-busy true, fake request pending. Clicking quantity increase while pending displayed the existing lock warning and did not increment quantity-call count.
6. Released503error: accessible error received focus, aria-busy false, submit re-enabled, name retained. Retried: request2 reused the same key, no fake order yet.
7. Released save-then-lose-response: adapter recorded1fake order and cleared its fake cart; browser focused loss/error and allowed confirmation retry. Retryrequest3 used the same key and returned SIMULATED-001; fake order count stayed1.
8. Actual completion rendered1confirmation, removed all cart lines and checkout forms, focused confirmation; top85.12px below test-banner bottom80.70px. No horizontal overflow (document scrollWidth376 <= viewport390). No stale empty-cart rerender replaced confirmation.
9. At320x600 after explicit fixture reset: Coke add gave$4.00/1item; fields16px/48px high, submitwidth183.52px, documentwidth305<=320. Remove returnedemptycart with zero checkout forms. No real order.

Before reload, native fetch self-check was blocked by CSP. Node transport tests also proved zero native fetch calls for provider/payment routes. No actual Square/WordPress request, email, financial action or customer data was used. Screenshot C:/Codex/Output/doughboss-checkout-journey-20261019/checkout-recovered-390.png contains a visible simulation banner; ordinary storefront confirmation wording is not a real order claim.

## Additional read-only Square membership evidence

Using the existing authenticated IAB Square session, navigated through Customers > Loyalty > Marketing > Other, without opening customer records or changing settings. On https://app.squareup.com/dashboard/customers/loyalty/marketing/other the page explicitly showed **Doughboss customer loyalty status and enrolment** and the public link **https://app.squareup.com/loyalty/MLK5K6N8G157N**, exactly matching the owner's supplied URL. The page describes using this link on a website/social page for status checks and enrolment. Screenshot: C:/Codex/Output/doughboss-checkout-journey-20261019/square-loyalty-account-link-readonly.png.

This establishes that the supplied link is the configured public loyalty link of the signed-in Doughboss account, improving on the earlier public destination-only read. It does not prove the account's legal ABN/location mapping, authorised SMS login, a completed enrolment, rewards terms/consent acceptance, accrual/redemption or checkout linkage. No View site/sign-in, marketing promotion, subscription, URL setting or activation was submitted. The website membership URL/defaults remain unchanged/off pending the separate publication/configuration gate.

## Final evidence gate

Fresh Sol 6.1 returned **SHIP_LOCAL_EVIDENCE_ONLY**, no material defect. It verified all five frozen fixture hashes, independently passed all nine tests, confirmed byte parity for the storefront/CSS copies, and accepted the fixture isolation and narrowly bounded same-page lost-response/retry claims. Browser focus, presentation, geometry and interaction results remain lead observations, not independently repeated by Sol. Receipt: C:/Codex/Output/doughboss-checkout-journey-20261019/sol-verdict.md. The later Square account-link read is separate lead evidence, not part of this fixture verdict.

No production runtime repair was needed from the observed journeys. The existing accepted 342-file ZIP remains exact for runtime commit 00ec0a2; this external fixture is not shipped in that package. This handoff is checkpointed locally without push or publication. Test controls were reset to an empty synthetic cart with no saved contact data; device emulation was cleared.

These checks close the named ordinary frontend interaction evidence only. They do NOT establish real server cart persistence/idempotency, Square payment capture/replay, pickup holds, approval-required flow, merchant43-item/options/tax parity, operational membership SMS/account ownership or real notifications. Retain merchant photography/rights, backup/restore, installed-byte, provider/financial/device and live publication gates. Protected untracked Qwen-lead handoff was not opened/hashed/staged. Previous two Terra independent source audits are reused for unchanged code.
