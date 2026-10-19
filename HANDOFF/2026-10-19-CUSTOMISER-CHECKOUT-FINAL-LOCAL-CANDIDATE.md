# Premium menu/customiser/checkout — final local candidate

Date: 19 October 2026 (Australia/Sydney).

## Disposition and authority

This checkout-validation and packaging batch is complete locally. Final independent Sol 6.1 xhigh disposition: **SHIP_LOCAL_SOURCE_ONLY** after repair and re-verification of a P1 stale-cart recovery defect. This is not deployment, installed WordPress parity, provider acceptance, or commerce activation.

The owner explicitly resumed Qwen and approved using existing credits for this named batch after the included allowance was exhausted. Supported reads confirmed a positive existing credit balance and no spend-control block; `ordinaryUsageAllowed` remained false for the exhausted included allowance. No credits were purchased, reset redeemed, billing changed, cloud Claude API invoked, or account switched. Qwen's earlier stop was superseded by the owner's explicit resume.

Preserve payments, Ads, non-Revesby connections, device/KDS activation and live publication as separate gates. No push, CI dispatch, provider mutation, live order or payment occurred. Source remains intentionally uncommitted on `codex/2.51-handoff-20261016`, based on HEAD `09984b91a6abaa24e272256781a0460ebf15a470`. Protected and unrelated handoffs were not opened, hashed, staged or changed.

## Delivered archive

- File: `C:/Codex/Output/doughboss-customiser-checkout-20261019/doughboss-2.51.0-premium-checkout-ready-r4.zip`
- Version: 2.51.0 review candidate; no release/version activation.
- Size: 15,998,112 bytes.
- SHA-256: `E1195E99315A3EA4C569A2D0561DCF236742647E269CABE1937DC61509E6D907`.
- Canonical layout and exact current-tree byte parity validated: **386 files**.
- Archive is plugin runtime only (`doughboss/`), not a WordPress database/theme export. The existing builder whitelist excludes HANDOFF, tests, scripts, themes, docs and repository metadata. No fixture, model receipt, screenshot or private handoff is in the installable archive.
- Earlier premium-menu r3 archive is retained, but does not contain this final checkout/customiser repair. Do not substitute it.

## Scope and changes

Existing stack and native controls retained; no production dependency, REST/schema/storage contract, catalogue option or price change.

- Approved earlier compact mobile catalogue and responsive imagery remain included. Four previously approved generated illustrations and their responsive variants remain packaged. The owner-requested visible badge removal is retained; provenance/AI alt text remains. Originals were not replaced or deleted.
- Ingredient groups explain Choose one / Choose any, identify free choices/removals without inventing recipes, provide selected labels and a base + configured option charges = item total breakdown through the existing `currentPrice()` flow. No client price is submitted.
- Reset restores canonical radio defaults including paid defaults and clears optional checks. Reset and option controls lock during Add; failed Add retains selections. Native controls and minimum 44px rows remain; guidance, prices and summary have a 16px floor. Longer segmented price labels wrap.
- Failed cart GET no longer destroys the checkout form/customer inputs or payment recovery state. Persistent last-confirmed warning and read-only retry live outside disposable cart lines. Read generation guards ignore superseded successes and failures. Local cart readiness is separate from the financial mutation lock.
- Review/edit and Continue to your details move focus within the existing DOM; no form remount or new wizard.
- New core, Square capture/card and wallet operations are refused while cart data is stale, including after asynchronous tokenization and before payment-intent dispatch. Existing verified payment/session recovery is not turned into a new charge.

### Material finding and repair

The first frozen candidate incorrectly counted `checkoutAttemptId` alone as recovery evidence. Hosted flows allocate this local key before `/payment-intent`; a pre-dispatch failure plus stale cart could therefore create a fresh payable session. The server's payment identity includes the current cart, so keeping the same browser key was insufficient proof of exact recovery.

Repair: `hasCheckoutAttempt()` now accepts only `checkoutPaymentId || stripeCancelledPayload || squareHostedActive`. Both failure-button state and submission use this genuine helper. A bare local key cannot bypass freshness for paid **or unpaid** creation. Ordinary ready lost-response retries still reuse their existing key. The test harness now extracts the real helper instead of duplicating it.

Sol independently reproduced the initial defect with synthetic recorders, then re-verified the repair, including both failure/stale event orders for unpaid, Stripe-hosted and Square-hosted flows. No remaining blocking source finding was returned. No provider was called.

## Partner work and evidence

- Qwen connector: `mcp__qwen__ask_local`, task `code`, `check_with_claude=false`; actual model `qwen3.8-27b:code`. It drafted the regression in which an older cart-read rejection follows a newer successful read. Sol inspected and integrated it with minor editorial trimming and an added intermediate readiness assertion.
- Qwen receipt: `C:/Codex/Output/doughboss-customiser-checkout-20261019/qwen-resumed-late-refresh-regression-r1.json`.
- Measured for that one task: 1,616 prompt tokens; 707 completion tokens; 30,500ms total; first content 10,922ms; 21 progress updates; native generation 36.080543 tokens/sec. This is not a sustained benchmark, proof of app display latency, or a savings estimate.
- Terra scout independently checked canonical package whitelist/exclusions, dirty runtime inclusion, fixture parity and missing assets. Six existing source assets (five fonts and the referenced order image) were copied into the isolated fixture; no new asset dependency or source edit was required.
- Sol 6.1 xhigh was the final source verifier and found the P1 above. Root owned consequential repair/integration. Astra was not called: Sol resolved the material question without an unresolved assurance gap.

## Final validation

Commands ran from `C:/Codex/Workspaces/doughboss-251-handoff-20261016` unless a fixture path is shown.

| Check | Actual result |
| --- | --- |
| `node --check public/js/doughboss.js` | Pass |
| `node --check public/js/doughboss-square.js` | Pass |
| `node --test tests/*.test.js` | 256 passed, 0 failed |
| PHP 8.2 `tests/run.php` | 4,553 assertions passed, 0 failed |
| PHP 7.4 `-n tests/run.php` | 4,361 assertions passed, 0 failed |
| Offline captured-public-menu replay | 43 items / 134 groups / 760 choices; 0 mismatches, 0 invalid options |
| `git diff --quiet -- includes/class-doughboss-menu-options.php` | Exit 0; authoritative definitions unchanged |
| Isolated r2 `node --check fixture-api.js` | Pass |
| Isolated r2 `node --test fixture-cart.test.cjs` | 9 passed, 0 failed, final source parity included |
| `git -c core.whitespace=cr-at-eol diff --check` | Exit 0; ordinary CRLF/LF conversion warnings retained, no wholesale normalization |
| `scripts/build-zip.php` + `scripts/validate-zip.php` via PHP 8.2 | Pass; 386 files, exact runtime parity |
| `scripts/build-visual-preview.php` + `node scripts/validate-visual-preview.js` | Pass; 9 pages / 202 files, exact source hashes and read-only boundaries |

Final photo-only read-only visual preview: `C:/Codex/.codex/doughboss-premium-checkout-20261019-r5`. The approved homepage film is deliberately not in that visual fixture; this batch did not alter the live hero.

### Frozen source hashes

| File | SHA-256 |
| --- | --- |
| `public/js/doughboss.js` | `372A72C90F9A23D5AC22E1278C0F4F744356F73C8B8C3BAEF142093EE05B2ECF` |
| `public/js/doughboss-square.js` | `49CB45AF784E6D9ABDC9F44CF6C9C37E6BAA72543B1534BB97D6B9BF46D63AE5` |
| `public/css/doughboss.css` | `70D4BB082843381085540A4907C343691D98079A4237008347BCF384BB8F70ED` |
| `tests/frontend-upgrade.test.js` | `F82BB505A4BF33EB196BAA1E1A18807C393F87C0A23595F7D08279FBEB789254` |
| `tests/storefront-helpers.test.js` | `6FCAC1D43F9A104F8793AD7C48935CC83EC1335CFEB8E4AAF208EA230ED714B3` |

## Browser evidence — synthetic, not provider acceptance

Final isolated fixture: `C:/Codex/Output/doughboss-checkout-journey-20261019-r2` (17 intentionally minimal files). Its CSP denies native networking and form submissions; payments are false and all products, amounts, customer fields and orders are invented. Corrected failure/hold controls target `GET /cart?order_type=pickup`, not the header badge's bare `/cart` read.

The original Chrome extension disconnected during resume; final replay used the available in-app browser. On the repaired source:

- At 390px, failed widget GET preserved Synthetic Customer and the kitchen note, showed last-confirmed warning, and disabled new checkout. No checkout or fake order existed at that point.
- Retry refreshed the total from last-confirmed to the synthetic authoritative cart, hid the warning, re-enabled checkout and retained fields.
- Review/edit and Continue controls focused `db-cart-review-heading` and `db-checkout-heading` respectively.
- A held widget GET disabled checkout while pending; release restored readiness. Native network test reported **blocked by CSP**.
- At 320px, 390px and 1440px there was no horizontal document overflow in the checkout fixture.
- Final ordinary checkout then saved a fake order and intentionally lost its response. Retry used the same key: two synthetic checkout requests, exactly one `SIMULATED-001` order; confirmation received focus.
- Final menu/customiser at 390px retained 44px minimum rows, no sheet/page horizontal overflow, selected/default price summary and no visible AI badge. Earlier 320px menu/customiser measurements remain applicable to the unchanged CSS.

Evidence under `C:/Codex/Output/doughboss-customiser-checkout-20261019/`:

- `final-browser-proof-r1.json`
- `checkout-final-stale-390.png`
- `checkout-final-recovered-390.png`
- `checkout-final-recovered-320.png`
- `checkout-final-recovered-1440.png`
- `checkout-final-synthetic-confirmation-390.png`
- `menu-final-390.png`
- `customiser-final-390.png`

Screenshots are local/synthetic evidence, not a production score. The fixture's own conspicuous sticky test banner is not part of the live storefront. No 9/10 or production-performance claim is made from these captures.

## Membership and remaining external gates

The existing safe Square-hosted membership shortcode and Members & Events content are preserved, not replaced by invented login/reward functionality. Current defaults keep `square_loyalty_status_enabled=0`, an empty configured URL and accrual disabled. The owner-supplied public Revesby loyalty link was verified in the earlier read-only account check; this batch did not configure/activate it or prove SMS login/enrolment/reward consent.

Still separate: credential-to-seller/location binding, current authoritative Square catalogue/tax readback, sandbox SDK/3DS/financial reconciliation, membership customer journey and approved reward terms, physical KDS installation/receipt, staffed end-to-end acceptance, backup/rollback and installed byte parity, other shop onboarding, Ads budget/spend and live publication. This candidate does not satisfy those operational gates and does not authorize them.

## Bounded readiness objective audit

| Requirement | Current evidence and disposition |
| --- | --- |
| Mobile-first menu and approved imagery | Accepted earlier density/image slices retained in the exact 386-file runtime archive; responsive-image PHP contracts pass; final mobile menu/customiser capture and measurements show no horizontal overflow and no visible badge. |
| Authoritative options and prices | Current canonical definitions unchanged; captured-public-data replay proves 43 items / 134 groups / 760 choices with zero mismatches. No invented ingredient claims or new options added. |
| Customiser and checkout locally validated | Frozen source, 256 Node tests, 9 fixture tests, independent Sol re-verification and final browser failure/retry/lost-response journey prove the bounded frontend contract. |
| Only the safe Members & Events / Square-account portion | Current shortcode delegates to `DoughBoss_Square_Loyalty::membership_portal()`. Exact public-host URL allowlist, explicit-enable gate and disabled defaults remain. `tests/test-square-invoices.php` covers unsafe URLs, disabled settings and gated rendering; the Members frontend contract covers private tracking paths and no invented login/embed. No provider identity or rewards bridge is claimed. |
| Qwen coding, Terra lanes, Sol final acceptance | Qwen regression receipt and integrated test; independent Terra fixture/test/artifact work; Sol 6.1 xhigh finding, repaired hashes and SHIP_LOCAL_SOURCE_ONLY return. Astra was not needed to resolve an outstanding issue. |
| Financial/provider/device/publication gates preserved | All exercises used synthetic transport or local test doubles; no live release, charge, Ads activation, other-shop connection or device activation. Package excludes fixture and private material. |

This completes the **bounded local readiness** objective, not the overall operational launch. The operational gates above remain deliberately outside this accepted batch and require their own evidence and authorization.
