# Menu density upgrade — local source only

## Scope and state

Owner requested Astra's plan and execution of the oversized menu repair, with
Domino's/Pizza Hut interaction inspiration. Payments, Ads, other shop connections
and live publication remain gated. No provider settings or live site changed.
Base: `09984b91a6abaa24e272256781a0460ebf15a470`.

## Accepted change

- Phone catalogue up to 640px: one compact horizontal row per product, with an
  84px image in a 96px media track and full, unclipped text beside it.
- 641–1099px: existing two-column cards; 1100–1399px: three columns;
  1400px and wider: four columns inside the existing capped container.
- Description text is 1rem with 1.45 line-height, including the order wrapper.
- AI image disclosure remains visible in normal flow beneath phone thumbnails.
- Responsive image source hints now request the 84px thumbnail through 640px.
- Existing header reduction, menu search/categories, mobile customisation sheet,
  consent, prices, options, store routing and all commerce controls are preserved.
- Five runtime/test files changed: two CSS files, one image-size hint in the
  existing storefront JS, and its two existing regression suites. No dependency,
  schema, public API, framework, renderer or theme/header edit was needed.

## Measured evidence

Live 390x844 observation before this batch: two 171.84px columns, first card row
346.72px tall; header 172.48px and menu tools 159.08px, plus the signed-in admin
bar and bottom call bar. The existing local candidate already reduced header to
109.28px and tools to 116.16px, but a sample pizza row still reached 497.38px.

Same local sample Sujuk Deluxe card after the upgrade: 201.68px at 390px;
sample AI card 162.48px. These are layout measurements, not production speed or
conversion measurements, and the preview has ten synthetic products, not a
published catalogue replacement.

Browser computed checks at 320, 390, 640, 641, 768, 1100, 1399, 1400, 1440 and
1920px matched the column contract. Every checked width had 16px descriptions,
no horizontal page overflow, and AI labels fitting their media region.
Phone AI label measured 84x20.8px, positioned in flow.

Paused and synthetic open-ordering previews checked. Category navigation and
search worked, including zero results and recovery. The existing mobile
customisation sheet opened without horizontal overflow; sample Flat +$0.50
changed $8.50 to $9.00; Escape closed it and returned focus to its summary.
No add-to-cart/order submission/provider action was performed. Radio/summary/
close targets measured 44px in the width-only desktop emulation; existing
coarse-pointer CSS provides the checkbox labels' 44px touch treatment.

Commands:

- `node --test tests/*.test.js`: 238 passed, 0 failed.
- Independent Sol 6.1 focused suites: 180 passed, 0 failed.
- `git -c core.whitespace=cr-at-eol diff --check`: passed. Plain diff check
  flags existing mixed CRLF/LF conventions; the qualification is deliberate.
- PHP 8.2 existing visual-preview builder and Node validator: 9 pages,
  158 files, exact source hashes and read-only boundaries passed.
- No PHP runtime source changed; this batch did not repeat the previously
  accepted full PHP suites or claim new provider/operational acceptance.

Frozen preview:
`C:\Codex\.codex\doughboss-menu-density-20261019-r2`

Screenshots:
`C:\Codex\Output\doughboss-menu-density-20261019\menu-upgraded-phone-390.jpg`
`C:\Codex\Output\doughboss-menu-density-20261019\menu-upgraded-desktop-1440.jpg`

Later attempts to refresh the phone screenshot timed out in browser capture;
they produced no new files. The earlier phone capture exists, but its first
category labels were incompletely painted in that frame. Subsequent DOM checks
confirmed their text, white/black contrast and 44px heights; do not present that
frame as complete visual acceptance of every label. The final fresh phone tab
again measured 390x844, one 334.4px column and 201.68px sample pizza height.
The browser viewport override was reset. Preview server on loopback8134 remains
available for owner inspection; this batch's baseline8132 and first-build8133
servers were stopped using their owned process sessions.

The preview is photo-only: the approved homepage film is deliberately not
included by this menu-only build. This is not evidence the live hero was removed.

## Contributors and reference limits

Astra (`gpt-6-astra`, high) provided the read-only density/cascade plan. Its
premise correction was accepted: the earlier phone toolbar selectors have higher
specificity than the later max-900px block; that block alone is not the cause of
the live three-row tools. No speculative !important override was added.

One `mcp__qwen__ask_local` code request to `qwen3.8-27b:code`, capped at 120s,
returned `ok:false,status:timeout,text:"",elapsed_ms:120000`, no timing/token
metrics and no usable patch. No retry or connector/model tuning followed.
The lead implemented the small Astra-directed patch; no Qwen code was accepted.
Fresh independent `gpt-6.1-sol` high review: SHIP_LOCAL_SOURCE_ONLY, no actionable
functional findings. Browser checks are lead evidence, not reviewer claims.

REA-informed fallback only: no Rea runtime/tool was available or executed.
Read upstream browser-observation documentation and verified upstream main
revision `d6ca7b3f20a273823c50d6253f0b6f34f3876f53` on 19 October 2026.
Public browser references:

- https://www.dominos.com.au/menu — category/subcategory hierarchy and explicit
  pickup/delivery entry; pickup entry opens a store-selection step.
- https://www.pizzahut.com.au/menu/pizza/ — meat/chicken/prawn/vegetarian category
  browsing, cart/empty-state cues and View Details.
- https://www.pizzahut.com.au/menu/pizza/product/pizzas/bbq-meatlovers — item-detail
  hierarchy with size, crust, sauce, ingredients and priced extras.

Adapted browsing principles, not proprietary source/assets. No third-party
checkout transaction, private backend inspection, source reuse, capture upload,
repository push or full competitor checkout validation occurred.

## Remaining gates

This is a locally tested, independently reviewed presentation candidate, not a
live release. Publication requires the retained release gate and live signed-out
readback. Square membership/SMS, financial/device acceptance and other shop
activation are not advanced by these CSS checks. The four newly approved AI
illustrations remain a separate pending asset slice; no image generation occurred
in this menu-density batch. Preserve all pre-existing untracked handoffs.

Subsequent checkpoint: `2026-10-19-APPROVED-FOUR-MENU-ILLUSTRATIONS.md` closes
that four-image slice, including explicit merchant visual acceptance. This dated
menu-density record remains the evidence for the earlier, narrower CSS batch.
