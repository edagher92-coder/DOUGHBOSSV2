# Members, exact drinks and checkout: local readiness batch

Date: 19 October 2026, Australia/Sydney. Base: `3fdcbe9f22c39f352c4e636cc77e5cdf39275273` on `codex/2.51-handoff-20261016`.

## Scope and authority

Outcome: finish the bounded local membership handoff, exact-product drink fallbacks, five explicitly requested AI food illustrations, and concrete checkout/menu navigation regressions. Qwen supplies bounded coding drafts; Sol/Codex integrates and independently verifies them. No new dependencies, customer database or authentication bridge.

Payments, Ads, other shop connections, live publication, provider/customer writes and loyalty accrual remain gated. This is local source/package readiness, not production or financial acceptance. No push or deployment is part of this batch. The protected untracked Qwen-lead resume handoff is excluded from all inspection and commits.

## Implemented contracts

- Separate `[doughboss_square_membership]` shortcode and admin controls for a public Square-hosted loyalty-status link. The URL and explicit enabled flag default to blank/off. Omitted controls preserve settings; disabling retains a valid saved URL; invalid input clears/disables it. Existing loyalty accrual and legacy rewards settings are not activated.
- Pure allowlist accepts only HTTPS `squareup.com/loyalty/TOKEN`, `app.squareup.com/loyalty/TOKEN`, or `profile.squareup.com/merchantportal/TOKEN/loyalty` (optional terminal slash). No arbitrary hosts, credentials, ports, queries, fragments or extra paths. Non-string input is rejected.
- Enabled, valid configuration renders a same-tab Square handoff, not WordPress login. Off/invalid state is honest and has no active Square link. No customer phone/SMS collection, iframe, provider request or local identity mapping is added.
- Owner supplied `https://app.squareup.com/loyalty/MLK5K6N8G157N`. Public browser readback redirected to `https://profile.squareup.com/merchantportal/MLK5K6N8G157N/loyalty`, showing Doughboss and a Sign up/check in control. This is public page evidence only, not legal merchant/location mapping, an SMS login test, reward configuration acceptance, or website redemption. The URL was not written into active settings.
- Checkout submit now ignores a duplicate while its existing submit control is disabled. Regression fixtures execute the actual submit handler, `placeOrder` and failure helper together; failure unlocks for retry with the actual idempotency key preserved. Pickup refusal remains retryable. Existing Square capture handling remains separate.
- Explicit category navigation now uses the existing stable-layout class plus a synchronous layout read before measuring the scroll destination. Both pill clicks and category hashes use it. Ordinary initial browsing retains deferred grids until explicit navigation. No new scroll listeners, timers, dependencies or per-frame work.

## Exact drinks and AI illustration provenance

Five distributor originals under `public/images/menu/brand-v1/` match Coke, Coke Zero, Coke Vanilla, Sprite and Fanta Orange in 600 mL bottles. Source: `https://mybrandz.com.au/suppliers/coca-cola/`, observed product originals on its hub-brandz-cms S3 image host. Photos were visually inspected and exact filenames/size/MIME tested. This records source/matching, not image-use rights clearance. These are local review assets until publication approval.

The owner explicitly requested image generation because originals were unavailable for Cheese, Tomato & Olives; Half Meat & Cheese; Labneh Veggie Pizza; Sujuk Special; Zaatar Veggie Pizza. Built-in ChatGPT image generation created five distinct images; originals remain under the session generated-images folder. WebP delivery versions under `public/images/menu/generated-v1/` retain native resolution and are **AI-generated menu illustrations, not original or verified photographs of actual products**. They visibly say `AI image`, have descriptive illustration alt text, and preserve the whole bake with contain sizing. Merchant review of visual toppings and actual appearance remains required before publication, especially where catalogue descriptions are empty. No generated ingredients were added as verified menu claims.

All ten new mappings are exact fallback keys. Existing WordPress featured-upload precedence is unchanged; a fallback mapping does not prove a wrong uploaded featured image was removed. Juice/water brands and sizes are not inferred from a different product. No blanket claim that all menu images are original/high-resolution is made.

Five WebP delivery files total 2,158,052 bytes. Individual SHA-256 digests:

| File | SHA-256 |
| --- | --- |
| cheese-tomato-olives.webp | B25A9C25217A8D567E513046DBD0395789B2266A2B80DC41D64A37E3D73946B6 |
| half-meat-cheese.webp | BD36E42D8C325CA8CA9B730A36E18EA91624417375F5E04B813BE62E1E376ED1 |
| labneh-veggie-pizza.webp | E619A2909DD5EA31018B7B0628F8813B1232FF398782B2F6CB17F0C2E511377F |
| sujuk-special.webp | B04626019D91A2CC9906A2531A02EE54B99F1EDFED696CFDC956386B05D156B4 |
| zaatar-veggie-pizza.webp | 8C87EB1976465C468A248840BAABB0E3B37CD6CDEB99B164204C6761FF8AC73A |

## Local proof

- `php tests/run.php`: **3,381 assertions passed, 0 failed**. Test-fixture email-failure messages are expected error-path coverage, not customer messages.
- `node --test tests/frontend-upgrade.test.js tests/storefront-helpers.test.js tests/pickup-storefront-2-51.test.js tests/orderboard-2-51.test.js tests/theme-navigation-layering.test.js`: **238 passed, 0 failed, 0 skipped**.
- Category-navigation negative control: both new tests failed on the old scroll path; **2/2 passed** after stabilising layout. Earlier checkout negative control likewise failed both cases before its guard and passed both afterward.
- `node --check public/js/doughboss.js` and CRLF-aware `git diff --check`: passed.
- Fresh `scripts/build-visual-preview.php ... ./` and `node scripts/validate-visual-preview.js ...`: **9 pages, 71 files**, exact source/local asset hashes, strict read-only boundary. The first attempted `/` base was rejected; supported `./` was used. Film is intentionally absent from this photo-only preview; no hero removal is implied.
- Browser 390px: first Drinks tap settled with heading at 236.72px below sticky header/controls, document width 390px, loaded bottle images using contain. Browser 320px: document width 320px, AI illustration thumbnails 84x84 using contain. Members off-state: zero forms and zero active Square anchors. Temporary device emulation was cleared. Synthetic preview prices/items are not real offers. Checkout is intentionally not available in this read-only preview; unit evidence is not provider acceptance.

Evidence root: `C:/Codex/.codex/doughboss-members-drinks-checkout-evidence-20261019/`. Contains generation prompts, Qwen receipts, phone screenshots, frozen review packets and final review receipt. Preview root: `C:/Codex/.codex/doughboss-members-drinks-checkout-20261019-r2/`, owned loopback server `http://127.0.0.1:8130/`.

## Model contribution and limits

Four completed `mcp__qwen__ask_local` code calls returned stop/non-truncated output. Elapsed times: 66.343s, 62.750s, 61.327s, 33.750s. Native reported generation rates: 46.29, 42.90, 46.40, 47.83 tokens/s respectively. They are task receipts, not a controlled sustained benchmark or measured credit savings; first-content latency was not exposed. Qwen supplied useful implementation and test drafts, including reuse of the existing stable-layout mechanism. Root corrected admin option names, disabled-state persistence, VM lexical context/idempotency fixture defects, unsafe/incorrect URL proposals and generated-image DOM/path handling before integration. These corrections remain necessary evidence against unsupervised security/payment authority.

Sol 6.1 xhigh accepted the earlier 23-file freeze as `SHIP_LOCAL_SOURCE_ONLY`, independently rerunning four focused JS tests. The final URL-form, generated-image and navigation deltas require the separate final frozen review receipt; prior acceptance alone does not cover them. Nothing in this handoff claims live activation.

## Remaining gates

Retain `HANDOFF/2026-10-18-RESUME-RELEASE-GATES.md`. Before publication: merchant visual approval/image-use clearance, current backup/rollback and isolated restore/application boot, installed-byte parity, real browser/device acceptance, Revesby merchant/provider/catalogue/financial checks and explicit publication batch approval. Square SMS customer sign-in and website reward redemption are not tested or implemented by this public-link handoff; KDS/device acceptance and other shops remain separate. Payments, Ads and loyalty accrual stay off.

## Final dated acceptance — 19 October 2026

Fresh Sol 6.1 xhigh verdict: **SHIP_LOCAL_SOURCE_ONLY**, no actionable findings. Independently matched all 35 frozen path hashes, passed 3/3 focused JavaScript media/category regressions, JavaScript syntax and the 9-page/71-file read-only preview validator. Full PHP 3381/3381 and frontend 238/238 totals remain lead-run evidence, not independent reruns. Final review receipt is saved in the evidence root. This acceptance addendum is documentation-only; reviewed runtime/test bytes remain unchanged.

Local archive: `C:/Codex/Output/doughboss-members-drinks-checkout-20261019/doughboss-2.51.0-local-candidate.zip`, SHA-256 `2A3C3F0D52FA0DCCEFE415DF1653AFCB62B31D7235A5B3B1CDE33B205B118104`, version 2.51.0, 255 runtime files. Existing archive validator passed current-tree byte parity. No push, upload, install or activation occurred.
