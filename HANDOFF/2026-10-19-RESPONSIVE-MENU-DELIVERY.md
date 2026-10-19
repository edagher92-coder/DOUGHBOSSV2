# Premium menu responsive-image delivery — local candidate

19 October 2026, Australia/Sydney. Base d45d1ba44d885886af77117d9217330d210f9b16; branch codex/2.51-handoff-20261016. Continues the owner's exact premium checkout-readiness batch exception. Supported account read: 93% weekly used / 7% remaining, reset 25 October 08:28:34 Sydney. This does not authorise publication or commerce activation.

## Scope contract

Outcome: deliver the ten already-approved bundled brand/illustration originals at phone-appropriate resolutions without changing item identity, image precedence, provenance labels or fallback behavior. Non-goals: no runtime JavaScript/CSS redesign, provider or database changes, dependency installation, Qwen model/config changes, push or deployment. Files: existing PHP image helper, existing offline image builder, existing manifest and 87 prebuilt derivatives, existing PHP/JS/preview checks. Proof: actual encoded assets, exact original/legacy preservation, existing suites and browser currentSrc at 320/390px.

## Implementation and provenance

- Runtime allowlist now admits exact manifest-backed brand-v1 JPG/PNG and generated-v1 WebP paths. Existing exact URL binding, typed candidates, native dimensions/aspect validation and readable file checks are retained. Unknown, traversal, external, query/fragment and mismatched paths keep the original fallback.
- Only the ten new originals receive 168, 336, 480 and 960px candidates capped at native width, plus the existing native-width policy. Legacy 17 manifest entries and their original/variant hashes remain exact. The ten original source files are byte-identical to HEAD.
- Manifest now contains 27 originals; 87 new binary files. Nine opaque originals have AVIF/WebP. The indexed transparent Coke Zero PNG has WebP only; 336px WebP independently inspected as yuva420p. No upscaling/cropping.
- Existing GD generator accepts JPEG/PNG/WebP and preserves non-JPEG alpha. It passes PHP syntax, but this host's PHP lacks GD, so that generator's full encode path was NOT executed. Installed FFmpeg 8.1.2 produced the inspected prebuilt assets: Lanczos scaling, one frame, threads 2; libaom-av1 still-picture 1 / crf 28 / b:v 0 / cpu-used 8 / row-mt 1 / yuv420p for opaque AVIF, libwebp quality 75 / compression 6. Encoder refused overwrites and unknown alpha handling. GD and FFmpeg are not claimed byte-identical encoders.
- All five food depictions retain their AI-image badges/alt descriptions and contain sizing. They are not claimed as merchant photography. Distributor drink originals remain exact 600mL products. Merchant visual/use-rights review remains a publication gate.
- Qwen was genuinely attempted through the named connector twice, timed out at 180015ms and 120000ms without any output; no Qwen code accepted. Prior direct native health GET also timed out. No restart/reconfiguration or blind retry. Root completed this slice locally after the failures; Sol's fresh frozen-candidate gate is required before acceptance. This is not a Qwen throughput/performance claim.

## Measured payload comparison

336px preferred encoded candidate versus original, bytes:

| Asset | Original | 336px | Reduction |
|---|---:|---:|---:|
| Coke Classic |62429|6200|90.1%|
| Coke Vanilla |57697|5598|90.3%|
| Coke Zero PNG |416716|9750|97.7%|
| Sprite |31640|2877|90.9%|
| Fanta |45267|3528|92.2%|
| Cheese Tomato Olives |427114|13301|96.9%|
| Half Meat Cheese |395792|13720|96.5%|
| Labneh Veggie Pizza |410072|14072|96.6%|
| Sujuk Special |451670|15346|96.6%|
| Zaatar Veggie Pizza |473404|15196|96.8%|

These are per-file payload reductions, not whole-page speed or rendering improvements. Browser DPR/slot determines selection.

## Validation evidence

- Before implementation, new 10 original URL regressions failed exactly 10 assertions (3387/3397 passed); after integration PHP 8.2 tests/run.php: 4181/4181 passed, 0 failed.
- Existing PHP 7.4 compatibility command, sibling php74/php.exe -n tests/run.php: 4029/4029 passed, 0 failed. Its no-extension run does not prove GD/AVIF encoding or ZIP behavior.
- node --test tests/*.test.js: 238 passed, 0 failed, 0 skipped. The actual renderer test exercises all 10 new manifest entries, original identity, 168px candidate delivery and AI provenance.
- PHP 8.2 -l helper and builder: both pass. CRLF-aware git diff --check passes.
- Fresh scripts/build-visual-preview.php output at C:/Codex/.codex/doughboss-responsive-20261019-r1; strict validator: 9 pages / 158 files, exact source/local asset/read-only boundaries. Photo-only preview, film omission explicitly disclosed.
- Real IAB, 320x740 at 2x DPR: Half Meat, Sujuk Special, Coke Classic and Vanilla all loaded 168px AVIF into 84px slots; document scrollWidth 320. At 390x844 and 2x DPR after a fresh reload, all four loaded 336px AVIF into 167px slots; document scrollWidth 390. Off-screen drink images were initially unrequested, then loaded after category navigation. The observed currentSrc selected derivatives, not fallback originals; no independent network log was captured to prove that originals were never requested.
- Screenshot: C:/Codex/.codex/doughboss-members-drinks-checkout-evidence-20261019/responsive-drinks-390.png. Device/viewport overrides cleared. Synthetic menu/prices only; no provider calls.

## Acceptance and remaining gates

Fresh Sol 6.1 frozen review returned **SHIP_LOCAL_SOURCE_ONLY**, no actionable findings. All 93 frozen hashes matched. It independently proved 17 legacy entries / 70 legacy derivatives unchanged, all 10 originals exact, exactly 87 new derivatives, and both transparent/opaque pixels in every Coke Zero variant. Its focused PHP image suite passed 1506/1506 assertions; two focused JavaScript tests, PHP syntax and strict preview validation passed. The initial reviewer sandbox file-read failures did not reproduce with the supplied runtime outside that sandbox. Full suites and browser selection remain lead evidence, not independently repeated by Sol. Verdict receipt: C:/Codex/.codex/doughboss-members-drinks-checkout-evidence-20261019/sol-responsive-verdict.md.

Sol accepted the inspected prebuilt assets while explicitly retaining the unexecuted GD regeneration limitation. No future GD output, installed runtime, merchant/provider or publication acceptance is implied. Previous d45d1ba package does not contain this new runtime. Payment/Ads/non-Revesby/device/live publication remain off/gated. A local-only source checkpoint and fresh package are the next accepted actions; no push is included.

Full objective remains incomplete: isolated actual-cart duplicate/reload/error recovery browser journey; merchant photo/rights and four older 300x300 originals; Square program/seller ownership and authorised SMS/member operational acceptance; installed-byte/backup-restore/provider/financial/device/publication gates. Preserve existing separate receipts. Protected untracked Qwen-lead handoff was not opened, hashed or staged.

