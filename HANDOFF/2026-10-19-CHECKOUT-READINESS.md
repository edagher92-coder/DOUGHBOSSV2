# Premium checkout-readiness: accepted local source, not full acceptance

19 October 2026, Australia/Sydney. Branch: `codex/2.51-handoff-20261016`. Parent checkpoint: `f49ec2137a47ecec2a6f0b4a9bc38615d1bf290a` (independently accepted disabled-membership guard). The protected untracked Qwen handoff was not opened, hashed, edited or staged.

## Authority and scope

The human explicitly approved this named batch despite 15% weekly allowance remaining, requested Qwen for coding and Sol for verification, and retained payments, Ads, additional shop connections and live publication as separate gates. A supported pre-Qwen read was 86% used / 14% remaining. No purchase, reset, reload setting or account change occurred.

This checkpoint integrates the already-tested narrow-phone layout and its final repair, plus one concrete checkout touch-target improvement. Runtime changes are limited to `public/css/doughboss.css`, `public/css/doughboss-order-page.css` and the responsive source hint in `public/js/doughboss.js`. Existing `tests/frontend-upgrade.test.js` and `tests/storefront-helpers.test.js` carry the regressions. No checkout/payment JavaScript logic, schema, API, storage format, provider setting or production data changed in this batch.

- Below the shared fractional-safe 359.98px cutoff, menu cards use an 84px thumbnail and readable details; 360px and larger phones retain the existing two-column layout.
- The independent reviewer found that rounded ordinary thumbnails lacked child clipping. Added `overflow: hidden` to that narrow rule; no broader image path was replaced.
- The native “Allergies & order changes” disclosure measured only 22.08px high at 390px. Qwen proposed `min-height: 44px` and `padding-block: .75rem` in its existing summary rule. These two declarations were integrated without changing fonts, native marker, display type, text or keyboard behavior.

## Validation and independent gate

- Existing five suites: `node --test tests/frontend-upgrade.test.js tests/storefront-helpers.test.js tests/theme-navigation-layering.test.js tests/pickup-storefront-2-51.test.js tests/orderboard-2-51.test.js`: **234 passed, 0 failed, 0 skipped** after the final repairs.
- `node --check public/js/doughboss.js` and CRLF-aware `git diff --check`: exit 0.
- Existing preview builder and validator passed **9 pages / 61 generated files**, exact source hashes and read-only boundaries at `C:/Codex/.codex/doughboss-checkout-readiness-20261019-r5`. Audit receipts/screenshots added afterward are not the generated payload inventory.
- Sol 6.1/high in the existing authorised `Verify premium menu candidate` task returned **SHIP_LOCAL_SOURCE_ONLY**, confirmed all five final file hashes, closed the P3 clipping finding and independently passed the existing targeted layout test **1/1**. It reused accepted unchanged findings; the 234-suite and browser evidence are lead-run, not independently rerun by Sol.
- No PHP runtime changed since the accepted guard checkpoint. Its retained **3,252/3,252 PHP assertions** are reused, not misrepresented as freshly rerun for CSS.

Final changed hashes:

| File | SHA-256 |
| --- | --- |
| Menu CSS | `A5ACC4F5BE3CDC1B188BC79871212524E8B6999EDADD380F4DE5F3EF1418141F` |
| Order CSS | `5D28DCFD5E5BD780D234DDF61C913F37034430F1F6D0ADFFB97CE39B18FABF8A` |
| Storefront JS | `3655C63BF056C064757A07588FCC8634703175DF2EB8EE77995401F084971AF6` |
| Frontend test | `A8D5079F343576F1E917868B26A440F16222EA4B11D4AF2D7E2ABA81009B29DB` |
| Helper test | `F06E8F1C7F2C22D4273D84314C012CD0989201CFC71E51D4F0ED7AAB9B36D478` |

## Actual browser evidence and limits

The local cart fixture has three synthetic items totaling $33.00, one sample shop, pay-on-collection copy, and blocked quantity/voucher/order/payment writes. It is not Square hosted checkout, a live shop, or an end-to-end order.

The initial r4 functional journey used the same storefront JavaScript as final r5:

- Blank submit focused Name and exposed required-field feedback; malformed email focused Email before submission.
- A valid synthetic submit was refused by the preview boundary. The error container received focus, the message explained that submissions were disabled, `aria-busy` returned false and the submit button recovered. The scoped CDP request trace recorded no request during that submit (no truncation).
- An initial screenshot taken during smooth cart scrolling was blank; a later settled capture displayed the cart correctly and the destination was below the sticky header. This was not accepted as a source defect or a site-performance benchmark; no speculative scroll rewrite was made.

Final r5 browser checks:

| Viewport | Document width | Native disclosure height | Contact inputs |
| --- | --- | --- | --- |
| 320px | 320px | 68.16px with wrapped label | 16px type / 48px height |
| 390px | 390px | 46.08px | 48px height |

The disclosure remains `display: list-item`; Enter expanded and collapsed it. The 320px thumbnail computed to 84px, 12px radius and hidden overflow. The page did not acquire horizontal overflow. These measurements do not prove every item or a physical-device journey.

Consent/reload checks used only synthetic details on localhost:

- With optional preferences denied, the remember-details checkbox reverted off and explained why details were not saved.
- After explicitly allowing device preferences and checking the separate remember-details opt-in, reload restored the exact test name and checked state. Email/mobile appeared nonempty but were redacted by the browser tool; exact positive-value readback for those two is not claimed.
- Rejecting optional preferences and reloading cleared all three contact fields and the remember checkbox. Test details were removed; optional preferences were left denied. This does not prove a real 30-day expiry wait.

Screenshots are `checkout-390-clean.jpg` and `checkout-320-clean.jpg` in the r5 directory. Earlier blocked-submit capture is in the r4 directory. The preview is photo-only and omits the approved hero film.

## Qwen contribution

Actual `mcp__qwen__ask_local`, model `qwen3.8-27b:code`: 415 prompt / 140 output tokens; finish stop, no truncation; 3.031s first content, 7.281s connector elapsed, 32.7947 native generation TPS, seven progress updates. Its two-declaration CSS proposal was accepted after source, test and browser verification. It correctly warned that source-regex assertions do not establish computed geometry. The local JSON receipt is `qwen-disclosure-receipt.json` in r5. Root corrected an escaped patch-context error before applying the change; this was not a model-code failure. No Qwen engine or governor tuning was performed.

## Checkpoint and remaining goal

This source/receipt set is checkpointed locally only. No push, hosted CI, provider call or website publication is authorised by this receipt. A new non-overwriting plugin archive may be built and validated from the accepted source commit; prior archives must not be relabelled as containing these changes.

Full premium readiness remains open: exact high-resolution photos (ten fallback gaps and four 300x300 assets), complete authoritative 43-item/customisation validation, real cart mutation and duplicate-submit/reload failure journeys, and the separate Square customer-identity/consent/account-linking contract. Current source preserves an informational Members hub and disabled legacy enrollment; it does not claim operational Square membership/login. Provider/payment, other-shop, device, installed-restore and live publication gates remain separate. Do not mark the full goal complete from this fixture or source acceptance.
