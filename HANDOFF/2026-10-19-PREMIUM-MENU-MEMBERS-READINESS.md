# 19 October 2026 — premium menu and members readiness

## Scope and outcome

This local source batch improves the existing menu and order-page presentation without changing catalogue identity, prices, cart calculations, checkout, provider calls, payment state, shop connections or database schema.

- All menu categories, including Drinks, now use the same photo-led card path.
- A missing or failed product image continues to render the existing branded fallback tile; the code never substitutes another item's photograph.
- Menu grids are capped at three columns on wide screens, use two columns through 1099px, and use one complete vertical card below 360px.
- Responsive image sizing now matches those layouts: one card width below 360px, two columns through 1099px and a capped desktop request.
- The existing tests were extended around the changed user-visible contracts. No new dependency or test framework was added.

Changed production/test files:

- `public/js/doughboss.js`
- `public/css/doughboss.css`
- `public/css/doughboss-order-page.css`
- `tests/frontend-upgrade.test.js`
- `tests/storefront-helpers.test.js`

## Validation

- `node --test tests/frontend-upgrade.test.js tests/theme-navigation-layering.test.js tests/storefront-helpers.test.js tests/pickup-storefront-2-51.test.js tests/orderboard-2-51.test.js`: **228 passed, 0 failed, 0 skipped**.
- `node --test tests/frontend-upgrade.test.js tests/storefront-helpers.test.js`: **170 passed, 0 failed, 0 skipped** after the final exact-byte reapplication.
- `node --check` over all JavaScript under `public/`, `themes/`, `admin/`, `tests/` and `scripts/`: **25 checked, 0 failed**.
- `node --check public/js/doughboss.js`: exit 0 after the final reapplication.
- `git -c core.whitespace=cr-at-eol diff --check`: exit 0. The repository uses `core.autocrlf=true`, so this is the CRLF-aware form of the whitespace gate.

## Premium prototype and independent visual gate

A separate non-transactional prototype at `C:/Codex/.codex/visualizations/2026/07/23/019f8d23-6ae6-7c51-9c68-7ff6d23c5bfd/doughboss-premium-menu-r1/` was used to validate the intended mobile journey before this narrow production adaptation.

- Prototype smoke tests: **7/7 passed**.
- Fresh Astra design re-review: **ready, 8.7/10**, with every dimension at least 8.5/10 and no unresolved blocker.
- Independently checked 1440px, 390px and 320px layouts; the repaired phone layouts had no horizontal page overflow and desktop did not expose the mobile bag.
- The prototype remains an eight-of-43 design sample and is not production code, a live order surface or provider acceptance.

## Qwen contribution and acceptance boundary

The local Qwen connector was used as the requested coding partner. Its bounded production proposal completed once but its content was unavailable to the lead through a broken result pointer; two other attempts were length-incomplete. Those proposals were not accepted into source. Measured completed-call evidence was 461 prompt tokens, 766 completion tokens, 3.702 seconds to first content and 26.030 seconds total, with reported predicted generation of 34.30 tokens/second. The final patch was written, inspected and tested by the Sol/Codex lead rather than representing unavailable or incomplete model output.

## Catalogue and photography truth

The current WordPress `doughboss_item` source remains authoritative. The reviewed 43-item catalogue matched the dated public comparison for identity, price, category, availability and options. Existing responsive assets remain local. File presence and URL matching do **not** prove that every photograph is an authentic photo of its named item or that image-use permission is complete. This batch prevents a wrong substitute image and presents an honest branded tile when no approved image exists; it does not manufacture or claim missing product photography.

## Members and Square boundary

`/members-events/` remains an informational page. Current Square OAuth is merchant/shop authorization and must not be presented or reused as customer login. Public member enrollment is not activated because customer identity mapping, duplicate-account policy, consent evidence and WordPress privacy exporter/eraser coverage are not yet complete. Square loyalty accrual remains off. No public membership/authentication bridge, provider program or customer database migration is part of this batch.

## Not live; remaining release gates

This is local source readiness only. Nothing in this batch was installed or published. Ordering, payments and Ads remain off; Bankstown/Roselands connections, KDS/device acceptance and customer membership login remain off.

Before a production release, retain the canonical gates in `HANDOFF/2026-10-18-RESUME-RELEASE-GATES.md`, including current production backup/rollback evidence, installed-byte parity, isolated restore/application boot, real device/browser acceptance, Revesby Square catalogue/provider/financial acceptance and an explicitly approved single publication batch.
