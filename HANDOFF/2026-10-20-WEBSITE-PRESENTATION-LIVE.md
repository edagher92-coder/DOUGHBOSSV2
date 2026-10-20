# DoughBoss approved website presentation — published

20 October2026, Australia/Sydney; final public checks approximately19:33. Owner approved repairing two remaining issues and publishing website updates while preserving the hero and keeping ordering/payments/Ads off.

## Actual outcome

The compatible **2.49.0 website-only backport** and existing WPCode snippets609/598 are published. Full2.51 was NOT installed because its automatic backend/schema/capacity changes are excluded. No Square API, order, payment, customer/member write, Ads action, other-shop/device activation, database import or theme replacement occurred. Do not call the full operational launch complete.

Eight existing runtime files changed;148 approved media/MIME files were added;224 other baseline runtime files are byte-identical. Final inventory380files. No price/option/API/schema changes. The canonical2.51 runtime must not be overwritten with this older compatible backport.

Modified paths: `includes/class-doughboss-assets.php`, `includes/class-doughboss-images.php`, `includes/class-doughboss-rest-controller.php`, `public/css/doughboss-order-page.css`, `public/css/doughboss.css`, `public/images/responsive/manifest.json`, `public/js/doughboss-square.js`, `public/js/doughboss.js`.

## Two repaired defects and proof

1. Restored Square provider-pending-without-payment-ID did not engage the existing checkout mutation lock. Dispatching the existing checkout-start event fixes the readiness/recovery race without a new API, treating a bare attempt key as recovery evidence, re-tokenising or recharging. Actual core/enhance integrated regression reproduced RED then GREEN across known-payment/unknown-provider and stale-before/after combinations. The latest root focused gate passed16 relevant core/Square/cart/mobile cases; Sol worker passed15 relevant cases and both JS syntax checks. No real provider action.
2. A late WebAudio suspend could settle after the hero returned to view. Qwen proposed one pending transition; Sol integrated a serialized gate which reconciles latest desired state on success and fails quiet after rejection until a new sound tap. Deferred regression reproduced RED, then `node --test hero-loop.test.cjs` passed7/7; PHP syntax passed. Live tap changed Sound off -> Sound on -> Sound off while film stayed muted and playing. No human listening/loudness certification is claimed.

Fresh read-only GPT-6.1 Sol xhigh accepted the frozen two-fix source as SHIP_LOCAL_SOURCE_ONLY; four inline hero JS blocks parsed. An initially broader imported selection included the newer2.51 pickup.gate contract absent from BOTH2.49 baseline and backport: diagnosed harness/scope mismatch, not a passing full suite.

Accepted unchanged frontend15, gateway30 and PHP image1878/1686 assertions were reused, not rerun unnecessarily. Canonical ZIP builder/validator proved exact380-file parity. Restricted Windows token falsely reported readability; supported escalation ran checks without runtime workarounds. Tests/support/themes/handoffs are excluded from runtime ZIP.

## Release artifacts and hashes

- Candidate: `C:/Codex/.codex/doughboss-live-backport-20261019`.
- Newer source base: `fc4991750a0805c17ca827e3f63ddc8e9ed07cc4`, branch `codex/2.51-handoff-20261016`.
- Manifest SHA-256: `517DCCF7A8923D4F960F7274DB9576FC1C2FA6F3A321FD09836911330F1FF180`.
- Delta SHA-256: `8a5d75f988040c2b5c8c22c652f653fd15fd8e130972f3902236d73bbdb5ee26`.
- ZIP beside this handoff: `doughboss-2.49-website-backport-r2.zip`,15,904,059bytes,380files; SHA-256 `880FD82295C595FE9F39C16213641971179EEAE73DF43DD4A536864CFAD08AB6`.
- Private publisher `apply-website.php`: SHA-256 `3E568D75308D9015190051C93352168ED335481FF930A78B9168C1D269C3F45A`.
- `hero598-candidate.php`, full file including PHP tag: SHA-256 `D50195F3763FD550EE349F24F43C5EE19BE1951E17D93B8AA61134A16E54100F`. UI paste omitted opening tag only; saved normalized text matched.
- `menu609-candidate.css`: SHA-256 `ED96EEBB19BB2F9515A29ACB507AB68D7EEDBD89624DD8C36F5A5F683877C745`; saved normalized text matched.

## Backup, compatibility and actual publication

Full Updraft files/database backup started19October15:59 and completed16:15; plugin archive retrieved/validated. Existing backups preserved. No remote storage or full restore/boot rehearsal claim.

Private host stage mode0700: `/home/doughbos/doughboss-web-stage-20261020-r2`. Private exact rollback backup: `/home/doughbos/doughboss-deploy-backups/website-20261020-r2`, containing232baseline runtime files and original598/609 sources. Original normalized hashes:598=`a24283e928475f6872c53d725b05f0ca7a8297322e43b6069adb3a17be0c0e09`;609=`7439c0238de3e1c3cdea2de32dd862d46bc5f5f86d42c6758631d3f8ce90eb52`.

Host lacks ZipArchive; the initial preflight safely stopped before runtime writes. Only the reader changed to available built-in PharData, without extension installation. Fresh Sol6.1 xhigh independently checked helper/ZIP/manifest hashes and all380 decompressed payload hashes/path inventory. Reader does not include/eval/extract PHP. Host PharData preflight then passed.

Rollback admission was independently repaired/tested for known old/new hybrid states. Six actual local PHP synthetic cases passed: partial apply, partial restore/retired media, already-restored idempotency, reject unknown bytes, reject unexpected path, reject missing baseline file. This is file rollback evidence only.

Actual host preflight PASS; one `php -d display_errors=1 <stage>/apply-website.php apply` PASS/APPLY_EXIT=0;380-file post-apply parity PASS. Media precedes enqueue version update. Existing609 and598 updated through WordPress UI, reloaded saved source matches candidates. Afterward `php <stage>/apply-website.php verify` PASS,380files,ordering_open0,payments_enabled0.

Earlier stored payments_enabled was1 despite the effective public pause. Under the owner's explicit keep-payments-off instruction ONLY that flag was set0; ordering was already0. Do not restore the earlier1 via a blanket settings rollback. No provider/financial action was involved.

Rollback is separate and has NOT run. Private helper restore handles known old/new runtime hybrids and retains new media recoverably outside public runtime. Original598/609 are separate UI restores from exact backups. Preserve commerce-off settings throughout.

## Fresh public/browser evidence

Anonymous host HTTP reads without auth cookies: homepage98,445bytes/menu70,828/members53,337/order70,758 at check; all retained the ordering-paused marker. Homepage contains new transition controller. Four versioned public CSS/JS responses match manifest SHA-256 exactly: mainJS,SquareJS,mainCSS,order-pageCSS. Members intentionally does not load menu runtime assets.

Same film retained: `https://doughboss.com.au/wp-content/uploads/doughboss-commercial-v3-web-loop.mp4`; live duration10.005sec,looptrue,mutedtrue,pausedfalse,readyState4; controls below frame, steam active, no scroll seeking. Reduced-motion/Data Saver/manual pause/offscreen restrictions remain. Synthetic sizzle requires tap; film soundtrack stays muted. Gain.012 is not an absolute device loudness guarantee.

Live menu:43cards, no console errors/warnings in inspected session. Phone390 has compact horizontal rows and no hidden cards displayed. Garlic search yielded four legitimate title/description matches; Clear search restored43. Actual per-tab widths verified, no horizontal page overflow:

| Width | Grid/card | Description/title |
| --- | --- | --- |
|320|one300px column|16px/20px|
|390|compact370.4px rows|16px body|
|1102|three337.36px columns|16px body|
|1440|four329.04px columns|16px body|
|1920|four330.5px capped columns|16px body|

An earlier background viewport-only loop returned1280 for every requested width and was discarded. All final results verify actual innerWidth; emulation cleared after checks.

Header Members & Events actually navigated to `/members-events/`, page603, aria-current=page/current-menu-item. Published content honestly states membership signup/rewards unavailable. Screenshot proofs beside this handoff: `live-menu-1440.png`, `live-menu-390-rows.png`, `live-hero.png`. These are authenticated UI screenshots (admin toolbar visible), separate from anonymous HTTP proof.

## Partner measurements

Latest supervised local Qwen call: qwen3.8-27b:code,taskcode,check_with_claudefalse;408prompt/873completion tokens;21.406sec total;1.139sec first content;43.0501867 native generationTPS;22progress updates;stop/nottruncated. Its pending-transition proposal helped, but stubs and rejection reconciliation could cause recursive failed-suspend retries; Sol adapted/tested final fail-quiet gate. This is one task, not sustained TPS, savings or unattended release qualification. Earlier CSS/sizzle drafts also needed correction. No cloud fallback/Claude API charge.

Sol6.1 xhigh repaired/reviewed Square and independently reviewed frozen source/publisher. No additional Astra call after the bounded questions were resolved. Supported usage:2%weekly used/98%remaining, ordinaryUsageAllowedtrue, existing credits61118.63869. No purchase/reset/account/billing change.

## Remaining Square/operational launch gates — commerce OFF

1. Revesby legal seller/ABN -> Square merchant -> website/Square location binding, environment/app/scopes/credential health and seller isolation. Earlier locationLHMBV6NDDD4BC is not credential-binding proof. Other shops remain gated.
2. Fresh selected-seller variations/modifiers/availability/discount and GST-inclusive cents/tax readback. Captured43item/134group/760choice website replay is not current Square financial parity.
3. A$299.99/A$300.00 routing after discounts including GST before service/delivery fees; catering alwaysRevesby; wrong/unavailable shop and retries. This website-only2.49 release does NOT install/claim the newer full2.51 routing backend.
4. Separately approved actual sandbox journey: hosted/embedded path, card/3DS/offered wallets, success/decline/cancel, expiry/stale cart, lost response/replay, amount/currency/location mismatch, exactly one effect. Source/mock evidence does not satisfy this.
5. Real signed webhook endpoint/delivery and forged/stale/duplicate/out-of-order/unknown-seller handling. Reconcile local attempt -> Square order -> payment -> local order; validate full/partial/pending refunds/accounting.
6. Staff customer-confirmed pickup, stable promised ASAP/preparation time, reservation/payment-link expiry; actual correct-store kitchen dispatch/ticket/staff acceptance. KDS installation not yet accepted.
7. Before advertising membership as open: hosted-link ownership, actual SMS login/enrolment, approved terms/consent/reward behaviour and seller-specific vouchers if offered. Current page remains unavailable honestly; this optional feature need not block a Revesby-only pilot if unadvertised.

No Ads spending enabled or modified in this batch. Shared-account campaign state was not independently audited here. All Ads launch/budget actions remain gated. Presentation is live; operational commerce launch remains unfinished.
