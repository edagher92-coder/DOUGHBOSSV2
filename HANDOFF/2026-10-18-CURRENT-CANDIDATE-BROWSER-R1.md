# Current-candidate browser observations — 18 October 2026

## Scope and provenance

Local presentation evidence only; no runtime edit, deployment, provider action or operational acceptance. Source commit `f779a136e7c73545321c11c37b1ea5fe77a4fbf7`; accepted runtime remains `9f37ccf77670b0175049e02c9896f28912f7488c`.

Old preview ports 8099/8111 had no listener. The existing exporter built a fresh output at `C:/Codex/Output/doughboss-browser-current-20261018-r1`, using the approved pinned media at `C:/Codex/Output/doughboss-local-first-20261017/hero-media-input`:

```text
PHP82 -n scripts/build-visual-preview.php NEW_OUTPUT ./ PINNED_MEDIA_DIRECTORY
node scripts/validate-visual-preview.js NEW_OUTPUT --require-hero
```

PHP82 is `C:/Codex/Temp/doughboss-portable-php-20261012-c1d4232b/php82/php.exe`. Both commands exited 0: **9 pages / 68 files**, exact source hashes, pinned media, local assets and read-only boundaries. Its adapter supplies **8 synthetic items**, not the real 43-item menu; submissions/provider calls are blocked.

The output was served only on loopback `127.0.0.1:8112`, tool session 74109. Chrome test tab 634478124 was closed and temporary viewport overrides reset. Ctrl+C ended the owned server (manual-interrupt exit 1); a subsequent TCP read found no port-8112 listener. No unrelated process was stopped.

Receipt: `artifacts/current-candidate-browser-r1-20261018.json`, SHA-256 `b55864d92280914c7932f93adc5610a77373e46afe68eb16b0d04615ad640990`. This is generated public/synthetic DOM, asset and browser-timing data, not credentials/customer data. Local manifest and output remain at the path above.

## Observed behaviour

- **390 × 844:** two 161.2px columns in a 334.4px grid; document width 376px. Menu images selected smaller 480px AVIF candidates. Lower wrap/dessert images were still deferred (`loading=lazy`, empty `currentSrc`), while earlier images were decoded. This is native deferral evidence, not a completed scroll-loading journey.
- **320 × 740:** single 264px column, 20px item headings, document width 305px. No horizontal overflow in either sampled phone geometry.
- **1440 × 900:** five approximately 248.8px columns, 1336px grid, 27.528px item headings, document width 1425px. These are DOM/computed-style observations, not visual screenshot acceptance.
- Accessibility-based entry of `juice` into Search menu yielded **1 match / Sample juice**. The receipt does not retain a post-clear sample, so search-reset acceptance is not claimed here. Separate Playwright role/placeholder locators failed to resolve that control despite its presence in the DOM/accessibility tree. Record the tool discrepancy, not an invented application filtering failure.
- Hero: exact pinned local `doughboss-commercial-v3-web-loop.mp4`, duration 10.005 seconds, muted/loop true, unpaused, readyState 4, no media error. Same-navigation readbacks were 7.89674s then 0.306105s. Playback/wrap observed; visual seam quality, steam continuity and physical-phone autoplay remain unverified.
- One initial order-page loopback navigation recorded DOMContentLoaded 251.2ms, load 290ms, FCP 180ms **before** the phone viewport override. One homepage navigation recorded FCP 124ms. These are isolated local observations, not mobile/cold-network/LCP/INP/CLS, comparative improvements or live performance.

## Limits that change the next action

1. The exporter calls the theme's **fallback menu**, not the saved WordPress Primary menu. Members & Events is absent from that fallback; this cannot prove saved-menu placement or whole-navigation parity. It is not evidence that the live saved menu is missing.
2. The shim explicitly emits the full storefront script on the homepage. It cannot prove the accepted production enqueue-filter saving; actual WordPress integration is still required.
3. Screenshot captures timed out at 5s and then 15s with lower-quality CDP capture. No screenshot was produced or further retry attempted. No visual/scroll-FPS/real-device pass is claimed.
4. Category activation did not establish arrival at the dessert section; its image remained deferred in the later sample. Do not count an automation command returning as journey acceptance.
5. Real 43-item/network behaviour, customisation/cart/checkout, saved-menu rendering, installation/consent retention, private restore, publication and provider/member/staff/KDS acceptance remain open. Existing runtime suites/R18/package checks were not rerun for this no-runtime-change batch.

Next frontend acceptance should use the actual installed WordPress/theme integration after release gates, or an explicitly admitted equivalent local integration. Do not stretch this eight-item fixture into full-launch proof.

## Independent gate

Fresh read-only GPT-6.1 Sol / high review returned **SHIP_BOUNDED_BROWSER_EVIDENCE_ONLY** after one wording correction: the original search-reset assertion lacked a corresponding receipt field and was removed. The reviewer independently verified the receipt hash, source-pinned manifest and existing validator (9 pages / 68 files, exit 0), checked the observation/limitation boundaries and confirmed no port-8112 listener. It then reread the corrected search bullet and resolved its sole finding without repeating the tests or browser run. No residual material finding remains in this bounded evidence review. This is not deployment or full-goal acceptance; the live/operational gates above remain open.
