# Photo coverage and narrow-phone menu candidate

19 October 2026, Australia/Sydney. Base HEAD: `93acde47370cd9a033136ecdfaafe9b0e5003759`, branch `codex/2.51-handoff-20261016`. This receipt records local work, not publication.

## Scope

Below 360px, replace the oversized vertical food/product card with a 96px-thumbnail-column plus flexible details row. Render the actual thumbnail at 84px square, with 12px top/left/bottom margin and rounded corners. Preserve complete descriptions, existing font sizes, prices, customisation and add controls. Larger phones keep two columns; desktop keeps three. Responsive source hints below 360px now request an 84px thumbnail rather than a viewport-wide image. Compact/photo-free layouts are excluded by the existing selector.

Only `public/css/doughboss.css`, `public/css/doughboss-order-page.css`, `public/js/doughboss.js`, `tests/frontend-upgrade.test.js` and `tests/storefront-helpers.test.js` change for this layout. The second stylesheet changes only its matching existing breakpoint. No HTML, dependency, database, provider, payment, shop-connection or membership capability is added. The separately reviewed disabled-loyalty repair is unchanged.

## Actual local proof

- Existing five frontend suites: `node --test tests/frontend-upgrade.test.js tests/orderboard-2-51.test.js tests/pickup-storefront-2-51.test.js tests/storefront-helpers.test.js tests/theme-navigation-layering.test.js`: **234 passed, 0 failed, 0 skipped**. An initial 233/234 run exposed the second existing image-size assertion; it was updated to the new user-visible sizing contract, then all suites passed.
- `node --check public/js/doughboss.js` and `git -c core.whitespace=cr-at-eol diff --check`: exit 0.
- Existing preview exporter and validator: **9 pages / 61 files**, exact source hashes and read-only boundaries passed. The builder requires `./`, not `/`, for this local relative-base preview. Source commit in its manifest is the base HEAD; file hashes include the uncommitted candidate bytes.
- Browser 320x740: document width 320, representative Zaatar & Cheese card width 278.72px and height 322.96px versus the previously measured 507.84px (about 36.4% less height). Image 84x84px. Title 20px, description 16px; visible Customize and Add controls 44px high. No card or document horizontal overflow. This is measured layout density, not a speed benchmark.
- Browser 320px customisation: modal width 304px, client/scroll width 303px. Flat +$0.50 changed sample total $8.50 to $9.00. Closing returned focus to Customize. No order was submitted.
- Browser 390x844: document width 390; two columns of 168.56px each, no horizontal overflow. The narrow-phone thumbnail rule does not apply.
- A further browser edge test found `innerWidth=359` while the old integer `max-width:359px` media query was false. The final candidate uses `359.98px` consistently in both stylesheets and the image-size hint. Fresh browser proof: at 359px, document width 359, one 317.76px grid column, card display grid and image width 84px; at exactly 360px, document width 360, two 153.52px columns and normal vertical cards. At 1440px, the unchanged layout has three 429.97px columns and no overflow (document width 1425px includes the scrollbar difference). These are actual measurements, not just regex assertions.
- The final fractional-bound repair again passed all 234 existing frontend tests, syntax/diff checks and the 9-page/61-file preview validator. Final menu screenshots are `C:/Codex/.codex/doughboss-premium-final-20261019-r4/menu-320-compact.jpg` and `menu-390-grid.jpg`. The customiser screenshot remains `C:/Codex/.codex/doughboss-premium-final-20261019-r3/customizer-320-compact.jpg`; its 320px behavior is unaffected by the cutoff-only repair. Browser emulation was cleared after testing. The owned loopback preview server serves r4 at `http://127.0.0.1:8130/order.html`; this is not public hosting or a physical-phone test.

The preview contains eight synthetic items, blocked writes and sample prices. The approved hero film is not included. It is not the complete 43-item catalogue, a real checkout, a provider test or installed/live evidence.

Frozen SHA-256 values for the layout review:

- Menu CSS: `A5ACC4F5BE3CDC1B188BC79871212524E8B6999EDADD380F4DE5F3EF1418141F`
- Order wrapper CSS: `5D28DCFD5E5BD780D234DDF61C913F37034430F1F6D0ADFFB97CE39B18FABF8A`
- JS: `3655C63BF056C064757A07588FCC8634703175DF2EB8EE77995401F084971AF6`
- Frontend test: `A8D5079F343576F1E917868B26A440F16222EA4B11D4AF2D7E2ABA81009B29DB`
- Helper test: `F06E8F1C7F2C22D4273D84314C012CD0989201CFC71E51D4F0ED7AAB9B36D478`

## Public catalogue photo audit

Anonymous website menu read observed at **2026-10-18T15:51:48.8840426Z**: `https://doughboss.com.au/wp-json/doughboss/v1/menu`, 43 items. The candidate's actual private `menu_image_url()` fallback helper was invoked read-only with the same reflection approach as the existing image tests. Local dimensions were read from existing assets. Raw public-only receipts are under `C:/Codex/.codex/doughboss-premium-final-20261019-r2/catalogue-photo-audit*.json`.

**33/43 items have candidate fallback mappings; 10 do not.** This does not prove absence of WordPress featured photos: the real endpoint prefers a featured image. Existing accepted six Juice World mappings use manufacturer product images at 500x333; these are correctly matched product photos, not newly captured high-resolution originals.

Acquire or verify these exact missing fallback images; do not substitute a related product or invent a flavour:

| Item | Required truthful image |
| --- | --- |
| Cheese, Tomato & Olives | The complete merchant item, matching its actual format and toppings; not an assumed pizza slice |
| Half Meat & Cheese | The actual complete merchant item, showing the two halves |
| Labneh Veggie Pizza | The actual whole merchant pizza, not another veggie product |
| Sujuk Special | The actual merchant product, not an assumed slice or Sujuk Deluxe substitute |
| Zaatar Veggie Pizza | The actual whole merchant pizza, not a different veggie item |
| Coke 600ml | Exact 600mL product/packaging |
| Coke Vanilla 600ml | Exact Vanilla 600mL product/packaging |
| Coke Zero 600ml | Exact Zero 600mL product/packaging |
| Fanta 600ml | Exact merchant-confirmed flavour and 600mL packaging; do not infer orange solely from the catalogue title |
| Sprite 600ml | Exact 600mL product/packaging |

Four mapped assets are only **300x300** and need better exact originals for a high-resolution claim: All Meat, Dough Boss Wrap, Garlic Prawns, Ultimate Chicken. Many authentic `real-v1` assets are about 550x440. Do not claim all menu photos are now high resolution. Use source/rights checks before adopting any new external product photograph.

## Actual model contribution and limitations

The owner-requested `ollama-dev-orchestrator` skill and canonical guarded runner supplied one UI/UX advisory consultation: **kimi-k3:cloud**, preferred role model, public layout/photo counts only, approximately 5.997s runner wall time. No secrets, customer data, private source, provider credentials or sensitive business records were sent. It advised truthful placeholders, no image distortion, readable narrow cards and unobstructed controls. It did not see screenshots and is not a visual acceptance score; its suggested arbitrary viewport thresholds were not treated as standards.

Local **qwen3.8-27b:code** supplied the corrected ten-item shot-list draft: 333 prompt / 271 output tokens, 11.577s elapsed, 6.639s first content, 54.5629 native generation TPS, seven progress updates, finish `stop`. An earlier 420-token output was incomplete and not accepted. Root removed invented pizza-slice formats and an unconfirmed Fanta flavour from the final instructions above.

Qwen also supplied the compact-card CSS draft: 456 prompt / 348 output tokens, 41.157s elapsed, 33.375s first content, 44.6722 native generation TPS, ten progress updates, finish `stop`. Root integrated the bounded grid/image rules, kept existing body padding, removed ineffective `object-fit` on the wrapper, and sized decorative placeholder text separately. The first-content delay remains significant; this short task does not prove sustained 40+ TPS or universal latency improvement. No engine/governor changes were made.

The return owner is Sol/root for integration and acceptance. A fresh read-only Sol 6.1/high review of these five layout files is requested in the existing authorised `Verify premium menu candidate` thread. The reviewer was told the initial four-file freeze was superseded by the fractional-bound repair and received final hashes above. Its result must be recorded before calling the layout independently accepted. No new agent task was created.

Final review supersedes the pending checkpoint above: Sol 6.1/high returned **REVISE** for one P3 finding (rounded photo containers lacked child clipping). Root added `overflow: hidden` to the narrow thumbnail rule and its existing assertion. The same bounded checkout-readiness batch also incorporated Qwen's two-declaration native-disclosure touch-target repair. Fresh Sol verification then returned **SHIP_LOCAL_SOURCE_ONLY**, closed the clipping finding, matched all five supplied hashes, and passed the existing targeted layout test 1/1. The rest of the earlier layout findings remain accepted. Final local five-suite result is 234/234; browser and scope details are in `2026-10-19-CHECKOUT-READINESS.md`. The last verified pushed source remains `93acde47370cd9a033136ecdfaafe9b0e5003759`; local acceptance is not installation, publication or complete premium readiness.

## Remaining gates

Source/local checks are not full premium acceptance. Exact imagery, complete authoritative catalogue/customisation checks, real browser checkout/reload/failure acceptance, privacy and Square identity/consent validation, restore/release evidence, installed-byte checks and publication remain separate. Square membership/login has not been invented or activated. Payments, Ads and the other shop connections remain off. The protected Qwen handoff is untouched.
