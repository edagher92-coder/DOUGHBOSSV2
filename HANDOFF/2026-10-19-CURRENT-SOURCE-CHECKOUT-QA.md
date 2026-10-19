# 19 October 2026 — current-source checkout QA and fallback copy

The previous goal turn made progress by building validated plugin/theme archives from `c9f5fdbe17f253f21a7f4eba146b28773ee8c10f`. The independent Sol task has now returned `ship_local_source_only` with no actionable findings on that exact commit.

## Current-source preview

Existing `scripts/build-visual-preview.php` and `scripts/validate-visual-preview.js` built and validated nine pages / 61 files at `C:/Codex/.codex/doughboss-current-preview-20261019-c9f5fdb/`. This preview uses fixed synthetic catalogue/cart data and blocks writes. Hero media was deliberately not included, so this is photo-only and provides no hero acceptance.

Real Chrome emulation established:

- 390px viewport/document width: 390/390; menu grid two columns of approximately 168.56px.
- 320px viewport/document width: 320/320; menu grid one column of approximately 278.72px.
- View cart moved focus to the order-summary heading. The synthetic three-item total remained $33.00.
- Optional preferences could be rejected; the 30-day remember-details choice remained visibly optional.

Screenshot capture initially returned a black viewport after anchor navigation, then repeatedly timed out. DOM geometry and computed visibility remained normal. Reduced-motion emulation did not resolve capture. This is an unresolved browser/rendering evidence gap, not a passed visual checkout gate. No conclusion is made about whether the cause is capture transport, host rendering or site rendering. No live checkout or provider action occurred.

## Concrete defect corrected

Actual browser content exposed the generic placeholder preparation claim `Dough Boss · Oven-baked` on drink cards. The shared fallback kicker now says only `Dough Boss`, retaining the selected item's category separately. This avoids an incorrect preparation claim for drinks and fresh wraps.

The existing drinks regression test now asserts the exact neutral brand label. `node --test tests/frontend-upgrade.test.js tests/storefront-helpers.test.js`: **170 passed, 0 failed, 0 skipped**. CRLF-aware diff hygiene passed. This correction changes no ordering, price, cart, provider, consent or membership behavior.

Qwen completed a bounded copy proposal in 1937ms, first content 1296ms, 210 prompt / 13 completion tokens. Its proposed `Dough Boss · Est. 2018` was rejected because the founding year was unsupported. Lead verification selected the neutral label instead. No model's proposal was treated as factual catalogue evidence.

The prior upload archives remain valid for their pinned commit but do not contain this subsequent one-line copy correction. Rebuild a new non-overwriting archive after the next consolidated source commit before selecting a publication candidate.
