# 19 October 2026 — reproduced checkout navigation repair

## Reproduction and root cause

The alternate in-app browser rendered the current source successfully, allowing the earlier Chrome screenshot failure to be investigated as an actual customer journey. On the source-pinned synthetic cart page, clicking View cart moved focus correctly but the settled checkout heading was approximately **765.59px above the viewport**. Deferred menu grids changed height while the browser's smooth scroll was travelling to the earlier destination. Focus-only tests had not proved a visible destination.

## Repair

On an explicit jump to the existing on-page cart, `initCartFab` now pins the menu grids to visible rendering and forces their current geometry to settle before calculating the scroll target. That state remains for this page instance so a customer returning between menu and checkout does not reintroduce the shifting destination. Ordinary browsing before checkout retains the existing deferred rendering. A separate cart URL still uses native navigation. Cart totals, fields, provider calls and financial behavior are unchanged.

The existing cart harness asserts that geometry is pinned before the scroll destination is selected. This supplements, rather than replaces, real browser validation.

## Evidence

- Focused frontend/storefront suites: **170 passed, 0 failed**.
- Changed JavaScript syntax and CRLF-aware diff hygiene passed.
- Source-pinned synthetic preview: nine pages / 61 files, existing validator passed; photo-only, no hero acceptance.
- Desktop 1280px: after correction, cart top **180.03px**, heading top **234.75px**; order summary and details panel visibly rendered below the header.
- 390px: document/viewport **390/390**, cart top **124.72px**, heading top **161.04px**.
- Fresh page at 320px: document/viewport **320/320**, cart top **125.08px**, heading top **161.40px**.
- Focus remained on Review, order and track. Synthetic cart remained three items / $33.00; preview writes remain blocked.

Screenshots retained outside the runtime package at `C:/Codex/.codex/doughboss-checkout-scroll-20261019-r1/`: `checkout-desktop.jpg`, `checkout-phone-390.jpg`, `checkout-phone-320.jpg`.

The earlier Chrome screenshot transport failure is not claimed fixed. The alternate browser supplies direct rendering evidence for this named journey. This is not a complete 43-item photo audit, physical-device performance test, live checkout, provider acceptance or a new independent premium design score. The broader goal remains open.
