# Post-order experience: confirmation, tracking and modifier repairs

Date: 19 October 2026. Scope: the owner's request for an ordering and feedback experience competitive with Domino's and Pizza Hut. This record is not production acceptance or a claim of superiority.

## Reference and rights boundary

- Domino's AU app: https://www.dominos.com.au/landing-pages/app — publicly describes larger images, phone performance, easier ordering and deals in the menu.
- Domino's AU tracking: https://www.dominos.com.au/inside-dominos/apple-pay/ — publicly describes its live order tracker.
- Pizza Hut AU: https://www.pizzahut.com.au/default.asp?page=%2Fcoupons — public navigation includes Track Order, account access and feedback.
- Rea: https://github.com/morluto/rea — Rea-informed observation/adaptation workflow. No callable Rea runtime or installed CLI was established. Public documentation/source observation is the fallback; no Rea execution, app binary inspection, private backend extraction, or competitor transaction is claimed.

Reference patterns are inspiration only. This implementation uses DoughBoss's existing JavaScript, styles and server states; it copies no competitor assets or code and introduces no dependency.

## Small implemented batch

Outcome: keep confirmation about fulfilment, and ask for feedback only after actual completion.

- `public/js/doughboss.js`: remove immediate social/review marketing from order confirmation; retain order number, payment/pickup facts and existing reachable tracking actions. Render the existing styled review invitation only for server status `completed` when a review URL is configured. Every completed order is eligible without sentiment filtering. Cancelled and unfinished orders are not solicited.
- Completed/cancelled tracker copy now says final status instead of implying the stopped poller continues to update. Nonterminal copy clarifies that updates require the page to remain open.
- Failed background refreshes retain the last confirmed order and announce automatic retry. The two-hour polling limit supplies an explicit instruction to check status again.
- Each poll captures its lookup object and ignores success/failure from an obsolete lookup, including a fresh lookup with identical order number and email. A fresh Sol review reproduced the original same-key failure before this repair and accepted the corrected identity guard.
- Radio modifier groups without an explicit default now initialise the same first choice that the native input displays as checked. Explicit defaults remain respected; card price, customizer price and submitted modifier slug agree. Checkbox groups remain initially empty.
- `tests/storefront-helpers.test.js`: exercise actual extracted renderOrder function for pending, confirmed, preparing, baking, ready, completed and cancelled, each with/without configured URL. Assert external-link isolation, completion channel, terminal stop behaviour and absence of early marketing in confirmation.

Non-goals: no provider writes, payment/order enablement, new feedback storage, notification sending, customer identity changes or database schema changes. No live publication.

## Evidence

- `node --check public/js/doughboss.js`: passed.
- `node --test tests/frontend-upgrade.test.js tests/storefront-helpers.test.js tests/theme-navigation-layering.test.js tests/pickup-storefront-2-51.test.js tests/orderboard-2-51.test.js`: **234 passed, 0 failed, 0 skipped** after the final race repair.
- `git -c core.whitespace=cr-at-eol diff --check`: passed.
- Fresh Sol review in existing verification thread `01a14f2c-2b6c-7520-aadc-d6c64071dc4d`: **SHIP_LOCAL_SOURCE_ONLY**. The reviewer independently reran the new same-credentials regression, existing failure/retry and expiry regressions, and JS syntax. Earlier acceptance of unchanged review timing and radio-default behaviour stands. Its initial REVISE finding was repaired before this verdict.
- Qwen `qwen3.8-27b:code` synthetic test-case advisory: 228 prompt / 92 output tokens, finish stop, 5.297 seconds total, first content 2.203 seconds, native generation 29.5714 tokens/second, five progress updates. This was advice for independently written tests, not runtime code or sustained-throughput acceptance.
- Final bounded Qwen code work product: captured lookup object, use it as request body, and check object identity in both response handlers. The returned code was recovered through the supported content-retrieval tool, inspected, adapted to the existing function and integrated. Actual model `qwen3.8-27b:code`, 293 prompt / 110 completion tokens, 4.156 seconds elapsed, first content 2.297 seconds, native generation 58.5034 tokens/second, four progress updates, finish stop. This one small call is not a sustained speed benchmark or proof of app-display latency.

## Current-source browser evidence

Built and validated `C:/Codex/.codex/doughboss-premium-final-20261019-r2/` with the existing exporter and validator: nine pages / 61 files, exact runtime source hashes, synthetic catalogue and blocked writes. This is the eight-item visual sample; the approved hero film is deliberately absent and real checkout/provider acceptance is outside this fixture.

- At 390px and 320px, document width matched viewport width; customizer widths were approximately 374.4px and 304px respectively.
- Actual native radio interaction changed the synthetic item's total from $8.50 to $9.00 for Flat +$0.50; the selected state remained visible across viewport changes. Closing restored focus to Customize.
- Optional-cookie rejection closed the preferences panel. The initial panel can cover part of the customizer until dismissed; captures made before dismissal are not accepted as unobstructed customizer evidence.
- At 1440px, the menu grid used three approximately 429.97px columns with no horizontal page overflow.
- The Members page had no public form, displayed "Coming later · Sign-up not open", and had no horizontal overflow at 320px/390px. Preview links intentionally disable real tracker/provider destinations, so this does not prove live navigation or customer identity.
- One browser-control call took 815.8 seconds. No site latency inference is drawn from that control-plane delay. Earlier cached/partial screenshots are not performance evidence.
- Verified unobstructed phone capture: `customizer-390-verified.png` inside the directory above. Other captures retain their actual coverage limits.

## Reference pack

The owner-requested private REA assets pack is pushed separately at `edagher92-coder/rea-assets`, branch `references/doughboss-web-benchmarks-20261019`, commit `a6aeeb1424cc6c4a3ce5e7db6b7c879c5b0b481c`. It contains public reference screenshots, provenance and scrutiny only. The Rea engine was unavailable; supported browser/source tools were used. It confers no licence or provider/deployment acceptance on DoughBoss.

## Remaining experience work and acceptance

1. Menu/customisation: authentic exact-item photos, readable mobile cards, usable modifiers, clear price changes and preserved options. Verify 320/390px, keyboard and slow/failed image loading; do not substitute unrelated food photos.
2. Cart/checkout: clear shop and pickup promise, item editing without lost choices, total transparency, duplicate-submit protection, retry/reload recovery. Preserve A$300 routing basis and current payment gates.
3. Confirmation/tracking: easy return to the private tracker, actual acceptance/preparation/ready states, explicit delayed and disconnected behaviour. Never fabricate GPS, timestamps or live kitchen evidence.
4. Service recovery: verify the authoritative shop support destination, cancellation/refund wording and escalation route before adding actions. Do not leak email, tokens or order credentials in URLs or analytics.
5. Feedback loop: current completed-order review entry is only the first step. A private issue-report/response/closure workflow requires an explicit validated service and retention/consent contract; no such backend is claimed here. Avoid review gating and unsolicited marketing enrolment.
6. Members: retain Square/customer identity boundaries; seller OAuth is not customer login. Do not imply rewards, balances or membership are operational without provider evidence.
7. End-to-end acceptance: compare task completion, errors, response/loading behaviour and support discoverability on phone/desktop with realistic synthetic journeys. Separate source tests, browser observations, provider sandbox evidence, installed code and real fulfilment acceptance. No numeric 9+ or 'better than competitors' verdict until these are measured.

Prior checkout scroll repair and photo mapping receipts remain separate. Current product-photo commit `de1f484` is local source evidence, not proof of installed or published photos. Existing plugin/theme archives predating current source must not be called the current release.
