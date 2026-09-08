# Source-based visual preview — 8 September 2026

## Scope and authority

The user approved one preview-only publication to the existing separate GitHub Pages site at `https://edagher92-coder.github.io/DOUGHBOSSV2/`. It has no custom domain. One consolidated push to `codex/visual-preview-20260908` triggers one build/deploy workflow. Do not make additional pushes to this deployment branch without a separately approved batch. No PR or manual workflow dispatch is needed for this preview.

The user separately approved updating the production plugin and indicated a logged-in Chrome session. That is not evidence of a completed update or a complete recoverable backup. Production theme publication still requires its preview/publish approval gate. Ordering, payments and POS settings must not be enabled or changed by this preview batch.

## What the preview demonstrates

- `index.html`: actual current homepage, header, footer, real bundled photographs and self-hosted fonts.
- `order.html`: actual current order template, shop picker, menu, full canonical modifier groups and current customizer JavaScript, using clearly identified synthetic shop/menu/pricing data.
- `paused.html`: the same template's paused state, with browsing and shop discovery retained.
- Builder, cart, checkout, tracking and catering submissions are unavailable. There are no customer/contact forms, payment SDKs, analytics, provider calls or fake order successes. This is not a WordPress staging installation or payment acceptance test.

The CLI-only WordPress presentation shim invokes selected source renderers without loading WordPress, its database, plugin bootstrap or production settings. It changes only preview boundaries: URL routing, synthetic data, a visible notice and unavailable-action placeholders. Copied runtime CSS/JS/images/fonts remain byte-identical to source. The manifest records the source commit and SHA-256 hashes of every input and output; PHP/config/docs/archive files are not published.

The generated adapter accepts only four synthetic GET routes. Request objects retain their HTTP method; all writes, unknown routes and external origins are rejected without delegating to native fetch. CSP forbids network connections and form submission, starts with `default-src 'none'`, and allows only the required local assets. External/call/email links are rewritten in exported HTML, so alternate clicks and disabled JavaScript cannot escape that boundary. Noindex/nofollow/noarchive/nosnippet prevent the preview claiming to be the production menu.

## Source-faithful reference use

Source anchors: DoughBoss's existing identity and typography; real food photography; the accepted Astra ink/paper/ember layering; actual header/order/customizer structure; and truthful paused/sample/unavailable state labels. No second UI implementation or generated decorative asset was added.

Two locally pinned MIT references were inspected for workflow ideas only; no code/assets, framework, provider, CLI, MCP, hooks or telemetry were copied or executed:

| Reference / inspected revision | Borrowed invariant | Acceptance check |
| --- | --- | --- |
| [Kayforkind/reimagine-it](https://github.com/Kayforkind/reimagine-it), `6a17bf5bf80f93207f784c33d743a99e000a3dcd` | Preserve source identity, content and actions; explicitly label preview substitutions. | Render current PHP templates; validate exact copied-asset hashes and inspect actual rendered output. |
| [abi/screenshot-to-code](https://github.com/abi/screenshot-to-code), `d026163f586dfa8c5c10d28c36edd59a9d3b0e88` | Compare an actual rendered interface to the source-backed target, not an imagined screenshot. | Phone/desktop browser checks and screenshots after approved HTTPS preview publication. |

The dated manifest/routing record is discovery evidence, not global security approval. This task checked the local revisions and licences; it did not audit upstream services or install their stacks. Pages separation/permissions follow the [official GitHub custom-workflow requirements](https://docs.github.com/en/pages/getting-started-with-github-pages/using-custom-workflows-with-github-pages).

## Small production-source repairs included

The theme's paused wording exposed inconsistent plugin-generated labels. Defaults, empty-message fallbacks, notice headings and disabled menu actions now say ordering is paused; existing custom business messages and localization keys are preserved. No settings migration or live toggle occurs.

The full asset check also found the legacy `.bgcontent` background referencing nonexistent `doughboss-hero-premium-v1.webp`. It now uses the existing bundled `doughboss-feast-real-v1.jpg`; no asset was fabricated or renamed.

## Local verification before publication

- PHP 7.4.33 and 8.2.33: `-n tests/run.php` — 165 assertions passed on each.
- `node --test tests/storefront-helpers.test.js` — 25 named tests passed, including synthetic-request isolation and paused-state regression checks.
- `scripts/test-release.ps1` — 90 PHP and 16 JavaScript syntax files passed; exact current-tree payloads validated for 137 plugin and 25 theme files.
- `php scripts/build-visual-preview.php <new-output> /DOUGHBOSSV2/` and `node scripts/validate-visual-preview.js <new-output>` — 3 pages / 27 files; provenance, exact copied bytes, local resource references, CSP and unavailable-action boundaries passed.
- Validator review identified missing checks for CSS imports, responsive-image/poster attributes and meta refresh. These are now rejected and the restrictive CSP baseline is required.

Every exporter/package run uses a new output path and refuses overwriting prior artifacts. Existing ZIPs, quarantine and rollback materials remain intact. Current ZIPs retain review-candidate versions 2.43.0 / 1.6.0; they are not proof of production deployment. Earlier WordPress/MySQL 141-assertion and mocked Square-race evidence belongs to the catering integrity checkpoint, not this static export.

## Live and delivery state at preparation

WPVibe read-only checks confirmed the active canonical plugin `doughboss/doughboss.php` is 2.41.0, the active theme is 1.4.0, and UpdraftPlus is active. No backup contents or credentials were exposed. Supported Chrome discovery found the user's DoughBoss order tab, but two page-read attempts failed with `Emulation.setFocusEmulationEnabled` timeouts; no browser security or native-host changes were attempted. A complete current production backup/rollback has not been verified.

The design changes through `b0a1d3d` and this preview batch still need rendered verification. GitHub PR #67's older checks do not cover this batch. Record the actual Pages commit/run and browser results after publication; until then the older demo is not the new design. Production plugin/theme deployment, Stripe/Square provider acceptance, POS/kitchen delivery, real catering mail, commercial catering policy, accessible Drive backup target and SamOS integration remain unmet gates.
