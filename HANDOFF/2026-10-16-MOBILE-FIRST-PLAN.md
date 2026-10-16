# Mobile-first ordering and customer hub — active implementation

Owner request, 16 October 2026: prioritise phone browsing, item customisation, cart/checkout, installation, Members and consent-aware remembering. This adds to the active launch goal; it does not waive its backend/provider/restore acceptance or activate commerce. Current code base: `d2c1fbbfc2defb72a287e49eb80a11961f838949`. Installed core was last independently observed as 2.49; do not bulk-install the 2.51 candidate to publish presentation changes.

## Outcome and design contract

Make the phone the primary ordering surface while preserving Dough Boss's existing brand: warm paper `#f7f5f0`, near-black `#151210`, ember `#e2231a`, readable Barlow text and restrained Bebas Neue display headings. Use compact product photography and clear next actions rather than extra animation. Keep ingredient and dietary information readable, all original prices/options intact, and real shop availability authoritative.

Non-goals: new commerce identities, a second cart/checkout, changed prices/taxes/approval policy, other-shop connections, payment activation, offline ordering, push notifications, customer account creation or an invented active Square membership. No new production dependencies. Do not turn cookie choice into a forced acceptance of unrelated order terms.

## Verified baseline

See `C:/Codex/Output/doughboss-mobile-20261016/MOBILE-BASELINE.md` and its four retained screenshots. The logged-in Chrome audit at 390/320px found:

- Search below the first screen, duplicate shop/status presentation, and 432–443px full-width cards; the page was approximately 17,432px tall.
- Combined sticky header/tools/call bar occupied about 427px, plus a 46px admin bar. This is not an anonymous-device measurement.
- Pizza jump → search `cheese` → search `kaak` stranded the remaining result and search above the viewport at the footer. This is a concrete interaction bug, not a visual preference.
- Members' generic approximately 390px photo hero put the first tracking CTA around 1,148px/1,283px down the page.
- No sampled same-origin HTTP errors or console errors; current paused-ordering state intentionally hides active customisation/cart. No active signup or manifest was observed.

## Coherent implementation lanes

1. **Root: compact browsing and Members presentation.** Two product columns from 360–640px; compact image/text rows below 360px; retain full descriptions and usable 44px controls. Match responsive-image fallback sizes to actual columns. Reuse the current customiser, category search and cart; no duplicate app. Replace Members' generic image masthead with a compact dedicated template, quick links, honest membership status, and online install guidance. Extend the existing read-only preview exporter to render the same Members source.
2. **Sol: optional preferences and search regression.** One dependency-free helper for the four existing shop-preference consumers. Optional preferences default off, versioned 180-day choice; remembered contact details remain a separate explicit opt-in with a 30-day expiry. Reject/withdraw targets named optional keys only. Essential HttpOnly cart/table cookies and every payment/staff sessionStorage record remain untouched. Current-page shop choice, revalidation, storage denial and native motion/data-saver controls must still work. Fix search-result context only when layout collapse takes the focused search offscreen, without jumping on every keystroke or stealing focus.
3. **Sol: online install support.** Use WordPress's configured 192/512px site icons and a narrow manifest response with canonical public URLs. Native install is offered only after a real browser install event; otherwise show iOS/Android instructions. Installed/dismissed states must be honest. No service worker, API/checkout caching, forced reload, transfer of payment attempts, or claim that installation enables ordering/membership.

Exact file ownership is assigned in the chat. Preserve the earlier backend fixes and their red/green evidence in `2026-10-16-LAUNCH-ACCEPTANCE.md`. No new backend full-run freeze may assume unchanged runtime/frontend bytes after this batch begins.

## Acceptance before publication

- Extend and run the nearest existing Node/PHP tests, syntax checks and final source/package gate once after integration. Review actual diffs and inter-module interfaces; a string assertion alone is not rendering proof.
- Render at 320, 390, 430 and desktop widths. Assert document width, full item names/information, control size, keyboard/focus, search/category behaviour, customiser close/return focus and storage-denied states. Capture screenshots, console errors and failed requests with honest exceptions.
- Exercise real customer-path markup using the existing isolated synthetic WordPress harness when active cart/checkout cannot be tested by the static read-only preview. Do not imply that disabled preview cart/payment routes prove checkout works.
- Consent acceptance must prove no optional storage before choice, selective withdrawal, old contact-record expiry/migration, and essential cart/payment recovery survival. Do not use broad storage clearing. Keep marketing off; no analytics/advertising activation is included.
- Manifest generation/negative controls and synthetic install-event tests are not physical iPhone/Android installation proof. Verify actual configured icons and live manifest before claiming installable; retain manual guidance when the browser does not offer native installation.
- Verify no competing active consent/PWA integration before publication. Anonymous HTML currently has no known CMP/manifest signal; that is not a complete installed-plugin inventory.
- Preserve exact installed files/snippets as rollback, reconcile the live 2.49/candidate 2.51 seam, then publish one approved reversible mobile batch only after its applicable review passes. Core, provider, finance, membership and KDS gates remain separate. Do not award a whole-site 9/10 rating from this narrower UI work.

## Current checkpoint

Local first-pass menu styles and image sizes are implemented. Initial 136 focused Node tests pass. A synthetic 390px preview rendered two approximately 169px cards, 125px image areas, 20px item headings, visible descriptions, 44px customisation controls and no document overflow. This is an intermediate preview, not finished mobile acceptance or publication. Preferences, install and broader flow verification are still in progress. Preview server is loopback-only on port 8108; it contains public assets/synthetic menu data, blocks provider/form actions, and is not phone-accessible remotely.

## 16 October 2026 — integrated local candidate and review checkpoint

Implemented and independently reviewed locally; **not installed or published**. The installed 2.49/core-2.51 compatibility boundary and the existing comprehensive database/restore gates remain in force. Do not upload the review ZIPs as a shortcut around those gates. Do not activate ordering, payments, split checkout, membership signup or the legacy standalone loyalty workflow.

### Changes and measured browser evidence

- Menu: two product columns at 390/430px; compact image/text rows with 80px thumbnails at 320px. Full names, descriptions and dietary information remain available. At 390px, measured cards were about 161px wide with 120px image areas, 20px headings and 44px add controls. No horizontal document overflow at 320, 390, 430 or 1440px.
- Ordering navigation: retain the labelled sticky shop selector but remove its duplicate pickup-status text on phone ordering pages only. The full status remains in the shop card. Header measured about 109px rather than 167px; normal search/category tools about 116px rather than 155px in the synthetic 390px comparison. Result count shares the search row. Search collapse retains focus and returns wholly offscreen tools to the measured sticky-header boundary.
- Cart/checkout: existing server-owned cart renderer, no duplicate app or provider contract. Quantity controls are 44px, checkout inputs 16px/48px, summary labels 14px and the primary action about 51px high. No horizontal overflow at 320, 430 or 1440px. View-cart navigation clears the sticky header, focuses its section heading and hides the floating cue while the cart is visible. Visibility updates do not fetch or add scroll listeners; remote cart URLs remain native links.
- Customiser: browser testing exposed repeated modal activation losing ownership of background `inert` state. The repair retains the first ownership record, validates panel ownership before activation, makes repeated activation idempotent and guards stale focus callbacks. Exact source-event tests cover queued toggle/resize races, switching dialogs, new background nodes and pre-existing inert. Normal phone open/Escape browser readback: no remaining inert body children, zero open details/dialogs, focus restored to the item summary. Physical orientation changes remain untested: synthetic viewport changes did not reliably dispatch native resize events.
- Optional remembering: preferences default off; versioned choice expires after 180 days. A selected shop remains available in page memory without permission, including Square's existing shop consumer. Grant-after-selection persists that shop; no automatic contact opt-in. Separate explicit checkout contact remembering expires after 30 days and stores only bounded name/email/phone. Choosing the checkbox before typing remains selected when permitted; later edits save. Rejection/expiry/withdrawal targets only named optional keys, not essential cart/table cookies or payment/staff session storage. Marketing measurement/advertising remain off. Keyboard focus, equal choices and a scrollable 360px-high viewport panel were browser-checked. This is engineering evidence, not legal certification.
- Members: dedicated image-free 160px title area, accessible shortcuts, tracking/catering help, honest inactive Square membership status and online-only phone-install guidance. The mobile hero initially inherited 390px from the generic theme rule; the more-specific Members selector fixes it. At 320px the final hero measured 160px and shortcuts had approximately 51px targets, with no horizontal overflow. iPhone instruction expansion worked; native install offer correctly remained hidden without a trusted browser event.
- Installation: exact canonical-root GET manifest endpoint, exact 192/512 PNG WordPress Site Icon renditions, HTTPS/same-origin/uploads-only validation, optional integration filter and Members-only install script. No service worker, offline cache, push, forced reload or transfer of payment attempts. Actual live icon eligibility, native Android installation and iOS/Safari acceptance are not yet proven.
- Live anonymous HEAD reads returned 200 for `/privacy-policy/`, `/terms-conditions/` and `/members-events/`. These do not establish installation of this candidate.

### Validation evidence

Workspace: `C:/Codex/Workspaces/doughboss-251-handoff-20261016`; base commit `d2c1fbbfc2defb72a287e49eb80a11961f838949`.

- `scripts/test-release.ps1` with Node 20.20.2 and portable PHP 8.2/ZIP: **136 PHP syntax files, 2,785/2,785 PHP assertions, 24 JS syntax files and 200/200 Node tests in five existing suites** passed. Plugin package: 238 files; theme package: 27 files; exact current-tree bytes validated. Log: `C:/Codex/Output/doughboss-mobile-20261016/release-gate-accepted.log`.
- PHP 7.4 with ZIP, `tests/run.php`: **2,715/2,715 assertions** passed; six changed PHP sources also linted successfully. Log: `C:/Codex/Output/doughboss-mobile-20261016/php74-accepted.log`. Runtime-dependent counts differ between PHP versions; these are not the separate real-WordPress/database gate.
- Focused final Node suites: **189/189** helper, pickup-storefront and frontend tests passed; included in the 200 total, not additional tests.
- Existing visual-preview exporter/validator: **9 pages / 61 files**, exact source hashes, local resources, CSP and read-only boundaries passed. `cart.html` renders the genuine checkout against a labelled synthetic $33/three-item basket. Every non-GET action, financial/provider route and native form submission remains blocked. Ordinary two-shop `order.html` and paused state remain separate.
- Monitored final menu load/interaction: 25 captured network/runtime events, no console exceptions/errors or failed HTTP/network responses, no event-buffer truncation. Geometry/check receipts are under `C:/Codex/Output/doughboss-mobile-20261016/`.
- Independent read-only Sol review: **ship for this local mobile seam**, after the consent and customiser repairs. No whole-site numerical quality score or live-commerce acceptance was awarded. Frozen main JS SHA-256: `7E1953ABAECCBC5B0999D32AAD10AA25A2FCAA6911B6BB15888885E39420EF1D`; preferences JS: `F380E841F6AE3C0954D1CA1A7DBE4C4396B5AD76D2377176E605401FE4119086`.
- Screenshot limitation: the retained intermediate `menu-390-supported-r2.png` shows the compact-card surface. Final browser screenshot calls repeatedly timed out or produced an unusable compositor frame, so no final screenshot acceptance is claimed. Final DOM/geometry/keyboard/network receipts are retained; physical-device screenshots remain a release check.

### Preview and next safe release steps

Latest read-only artifact: `C:/Codex/Output/doughboss-mobile-20261016/preview-accepted`; loopback preview `http://127.0.0.1:8108/`. It is intentionally not reachable from another phone or over the internet. Source-review ZIPs in the same parent directory are **not approved deployment packages**.

1. Reconcile the exact installed 2.49 frontend with this 2.51 source batch; use a separately tested compatible presentation release or clear the existing core release gates. Preserve exact rollback assets/snippets, including independently rollbackable hero 598 and franchising pause 601. Publish hero consent gating only together with the preferences module.
2. Complete final screenshots/physical Android and Safari checks, live Site Icon/manifest eligibility and the actual WordPress/CMP/plugin coexistence check. Do not claim browser-native install or physical rotation acceptance from the synthetic preview.
3. Resolve the already-recorded comprehensive database failures and missing complete isolated restore boot before core installation. Revesby-only Square sandbox/merchant/payment/catalogue acceptance and real KDS screen delivery remain separate; the other shops' separate ABN/account setup stays deferred. This batch neither resolves nor weakens those gates.
