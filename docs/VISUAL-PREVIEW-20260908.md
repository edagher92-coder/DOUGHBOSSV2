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

The generated adapter accepts only four synthetic GET routes. Request objects retain their HTTP method; all writes, unknown routes and external origins are rejected without delegating to native fetch. CSP forbids network connections and form submission, starts with `default-src 'none'`, and allows only the required local assets. External/call/email links are rewritten in exported HTML, so alternate clicks and disabled JavaScript cannot escape that boundary. Noindex/nofollow/noarchive/nosnippet request exclusion from search indexing; the visible preview notice identifies the synthetic menu regardless of crawler compliance.

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

## Publication and rendered verification completed

The one approved preview push published commit `27c39fff3d7f421b25d5fe68c8b70751ebfd8bc3`. [Deploy read-only visual preview, run 34231630329](https://github.com/edagher92-coder/DOUGHBOSSV2/actions/runs/34231630329) completed successfully. The hosted manifest's `source_commit` matched that exact commit, `preview_only` was true, and the base path was `/DOUGHBOSSV2/`. No PR or manual workflow dispatch was issued. Pages has no custom domain; this did not publish to production.

- [Homepage](https://edagher92-coder.github.io/DOUGHBOSSV2/), [menu/customizer](https://edagher92-coder.github.io/DOUGHBOSSV2/order.html), and [paused ordering](https://edagher92-coder.github.io/DOUGHBOSSV2/paused.html) opened in the supported in-app browser.
- Measured document widths at 320, 390, 767, 768, 900, 901 and 1440 CSS-pixel viewport requests showed no horizontal overflow. This is not a complete physical-device or breakpoint acceptance matrix.
- At 390px, the labelled customizer opened, focused its close control, rendered canonical option groups, updated the synthetic total from $8.50 to $11.00 after choosing Wholemeal, and closed on Escape.
- Reduced-motion emulation removed the motion-enabled class; no completed-but-failed images were observed. The paused page had its paused notice, no add buttons and no transaction/form roots. The inspected console log had no errors or warnings.
- A usable, actual homepage capture was saved at `C:\Codex\Temp\doughboss-preview-20260908\published-home-default.png`. Viewport-override captures had scaling/padding artifacts and are not presented as reliable visual proof. Overrides were reset and the homepage was left visible as the deliverable.
- The published mobile navigation exposed a real initial-focus defect: its background became inert while focus remained on the document body. Escape restored the trigger. This is the sole additional implementation scope below.

These observations supersede the earlier pending-preview statement, not the production/provider/backup limitations. Zoom, every keyboard path, solid-fallback rendering and real-device acceptance are not complete. No orders, payments, customer mail or provider submissions were made.

## Local follow-on: mobile navigation focus

Work continued on `codex/release-readiness-20260908`, not the auto-deploy branch. The only runtime change defers initial navigation focus by one animation frame (one task fallback), verifies focus actually entered the drawer before isolating the background, and invalidates pending callbacks when the menu closes or changes breakpoint. The existing regression harness now checks deferred isolation and stale callbacks. No polling, dependency, settings change or second navigation implementation was added.

A GPT-5.6 Sol worker implemented the two-file repair; the lead inspected the diff and interfaces. A separate read-only review returned **ship**, with rendered acceptance explicitly outstanding. That review lane's actual model metadata was not exposed, so no model identity is claimed for it.

Full local gate after the repair:

```powershell
& .\scripts\test-release.ps1 -PhpPath C:\Codex\Temp\doughboss-review-20260908\php-8.2.33\php.exe -ExtensionDir C:\Codex\Temp\doughboss-review-20260908\php-8.2.33\ext -OutputPath C:\Codex\Temp\doughboss-preview-20260908\doughboss-focus-local.zip -ThemeOutputPath C:\Codex\Temp\doughboss-preview-20260908\doughboss-theme-focus-local.zip
```

Result: 90 PHP syntax files, 165 PHP assertions, 16 JavaScript syntax files, 26 named Node tests, and exact payload validation for 137 plugin / 25 theme files passed. The PHP source is unchanged from the earlier PHP 7.4 check; no new database suite was needed or claimed for this JavaScript-only repair.

| Local review artifact | SHA-256 |
| --- | --- |
| `doughboss-focus-local.zip` (2.43.0) | `4D984E715D0D52BC06A0DA5A34AF79C27087F321A5479ACEE134C632AB644368` |
| `doughboss-theme-focus-local.zip` (1.6.0) | `79D1901A4F1084DCD027AD31C7EF9B8FE11F825ECE983DD85A58C5C546CD60F6` |

The repair and these artifacts are local only. The published preview still contains the original initial-focus defect until a separately approved preview batch verifies and publishes the fix. Production still has the last observed plugin 2.41.0 / theme 1.4.0; no production update occurred. Current live ordering/payment/POS toggle values were not successfully re-verified in this batch and must not be inferred from the old handoff.

## Efficient continuation and advisory use

The user's current preference is Ollama-first for batched analysis, draft code, test design and critique, with GPT-5.6 integration and verification, and GPT-5.5 where a supported callable lane exists. The current child-agent API does not expose GPT-5.5; no new task or unsupported model switch was made to simulate it. Astra's accepted design phase remains closed unless a material unresolved design question warrants escalation. No claim is made about the provider's current billing or unlimited usage.

Two guarded, non-sensitive advisory calls were used for this local follow-on batch:

1. `tests` / `public`: `kimi-k2.7-code:cloud`, selected by the guarded runner. Its context had already received the worker's deferred-focus edit while the prompt described the published synchronous failure. Therefore its suggested polling workaround was not treated as evidence of a new defect and was rejected. Focus-before-isolation and stale-callback test guidance was independently checked against the source.
2. `long_horizon` / `synthetic`: `glm-5.2:cloud`, selected by the guarded runner. It usefully separated local, provider and deployment evidence. Suggested speculative refund state machines, placeholder SamOS/POS interfaces, blanket default-setting changes and an assumed `npm test` command were not adopted. Only real repository contracts and existing validation commands should drive the next implementation.

Recommended next batches, in priority order:

1. **Release acceptance and recoverability.** Verify the navigation fix in a supported rendered environment; finish the focused keyboard/mobile matrix; establish a fresh complete database-and-files backup with an identified restore path. Confirm the intended plugin package and unchanged live toggles before using the existing plugin-update approval. Theme publication still needs its own WordPress preview and publish approval. One explicit, coherent remote batch per approved release; no automatic second preview push.
2. **Operational payment, mail and kitchen acceptance.** Inspect existing Stripe/Square contracts and configuration without exposing credentials; determine the actual Stripe failure and desired POS/provider before adding code. Reuse existing tests, then separately authorize any provider sandbox/refund, real mail or kitchen/printer tests. Exit evidence must distinguish mocks from provider responses and physical kitchen delivery. Never enable production ordering/payments/POS or create real transactions under this continuation.
3. **Verified multi-location/SamOS integration and delivery.** Locate the actual SamOS repository and existing business/location/auth contracts read-only, then implement only an agreed first end-to-end connection with stable site/location identity and measurable status. No guessed endpoint or throw-only stub. Resolve the intended shared Drive folder by successful metadata verification before any upload; preserve quarantine and exclude sensitive historical material from public artifacts.

Ollama supplies bounded work products, not release authority. GPT-5.6 integration retains exclusive file ownership, source review, local validation and fresh review for consequential changes. No more than two complementary Ollama calls are used per coherent batch; remote workflows and live actions retain their separate approval gates.

## Owner-confirmed backup and plugin update attempt

The owner subsequently confirmed "backed up, go" after being asked for a fresh complete database-and-files backup. That is owner confirmation, not an agent-observed restore rehearsal. The plugin update was approved; theme publication and payment activation were not.

Fresh read-only evidence on 8 September 2026, approximately 13:42 UTC:

- WPVibe `option pluck doughboss_settings payments_enabled` returned `0`; `stripe_mode` returned `test`; `ordering_open`, `pospal_enabled` and `pospal_push_orders` each returned `1`. This establishes a local payments-disable gate, not a Stripe outage. No stored credential was retrieved.
- Public `/wp-json/doughboss/v1/config` reported ordering open, effective payments disabled, gateway Stripe, single native location `1`, pickup enabled and delivery disabled. Current values supersede the older all-off handoff, and were left unchanged.
- The active plugin remained 2.41.0. `doughboss_db_version` was already 1.23.0, matching the candidate; no new schema version is required.

The canonical plugin payload at local `6226da0` is unchanged from published/tested commit `27c39fff3d7f421b25d5fe68c8b70751ebfd8bc3`. The existing successful run `34231630329` was rechecked; no new CI or Pages deployment was triggered. The verified ZIP was copied without overwrite to `C:\Codex\Temp\doughboss-preview-20260908\doughboss-2.43.0.zip` and uploaded as the only asset of [v2.43.0-rc.1](https://github.com/edagher92-coder/DOUGHBOSSV2/releases/tag/v2.43.0-rc.1). GitHub reported uploaded state, 2,578,194 bytes and matching SHA-256 `4d984e715d0d52bc06a0da5a34af79c27087f321a5479acee134c632ab644368`.

The approved WPVibe command `plugin install https://github.com/edagher92-coder/DOUGHBOSSV2/releases/download/v2.43.0-rc.1/doughboss-2.43.0.zip --force --activate` returned exit 1, **Plugin not found**. It was not retried with guessed flags or an alternative payload. A subsequent plugin listing still showed active 2.41.0. Chrome could list the existing DoughBoss tab, but claiming it timed out; no plugin upload or replacement occurred through the browser.

**Remaining delivery action:** use WordPress's normal Plugins → Add New → Upload Plugin flow with the exact canonical `doughboss-2.43.0.zip`, review the replacement target, and replace only the existing DoughBoss plugin. Do not uninstall/delete it, upload the full repository archive or publish the theme. Then verify active version 2.43.0, existing menu/location records and unchanged ordering/payment/POS settings. Until that succeeds, the release asset is delivered but production is not updated.

The owner's new Square migration request and verified SamOS integration gaps are recorded in `SQUARE-MIGRATION-20260908.md`. Neither is claimed connected or operational from the existing payment-only implementation.

## Stripe coverage follow-on

The read-only payment trace found no direct Stripe regression coverage in the previous pure suite. `tests/test-stripe.php` now adds 42 assertions for the existing readiness gates, canonical reference sanitization, hosted-session response validation and webhook signature verification. It calls real pure helpers, uses synthetic settings and a narrow reflection invocation for the private response validator, and adds no HTTP/database shim or production code.

The lead reran `php -n tests/run.php` with PHP 7.4.33 and 8.2.33: **207 assertions passed on each**. PHP syntax and `git diff --check` passed. Revalidation of the published `doughboss-2.43.0.zip` still matched the 137-file runtime tree exactly. These test-only changes do not alter the release asset; provider HTTP, persistence and browser redirect/return acceptance remain distinct missing evidence.

After the failed install, public read-only checks still returned 43 menu items, one Revesby location (native ID 1), ordering open, gateway Stripe and payments disabled. WPVibe still reported active theme 1.4.0. No toggle, menu/location record, quarantine item or credential was changed by this batch.

## Private WordPress preview gate — 9 September 2026 (Sydney)

The next scoped action was to copy the already-reviewed theme into a private WPVibe draft, then inspect its actual WordPress rendering. Public theme publication, plugin replacement, database/content changes and payment/provider changes were excluded from this action. The existing owner-confirmed backup was retained as evidence; no second backup confirmation was requested.

`create_draft_theme` returned **HTTP 403: File editing is disabled on this site (`DISALLOW_FILE_EDIT` is set)**. No draft was created and no file was written. The tool explicitly reported that retrying the same call cannot succeed while that setting remains. The security constant was not changed, and no code snippet, helper plugin, alternate file writer or security bypass was used. Consequently, there is **no private WordPress preview URL** and the theme is not published. The existing GitHub Pages preview remains separate, synthetic presentation evidence.

Read-only WPVibe evidence collected around 14:00 UTC on 8 September / midnight 9 September Sydney:

- Active theme: **DoughBoss Final 1.4.0**, a plain classic theme with all standard templates present; 25 active-theme files were listed. `functions.php`, `header.php` and `page-order.php` were read without modification.
- Active canonical plugin: **DoughBoss 2.41.0**. UpdraftPlus 1.26.7 remains active.
- Individually plucked settings: `payments_enabled=0`, `ordering_open=1`, `pospal_enabled=1`, `pospal_push_orders=1`. These are saved flags, not proof that a provider or POS connection is operational. All were left unchanged; no credential-bearing settings object was retrieved.

The independent read-only compatibility review found no theme-on-load database, settings, remote HTTP or provider writes. Theme 1.6.0 uses presentation hooks and read-only settings/image access. It can be considered for an authorized private visual preview, but **plugin 2.41.0 cannot demonstrate the complete 2.43.0 storefront behavior**: it lacks the newer `doughboss_load_shop_status_assets` and `doughboss_is_order_page` filter receivers. The missing receivers are harmless at the PHP hook boundary but leave newer pickup-status and template-rendered ordering styling/behavior incomplete. This is source evidence, not rendered or production acceptance.

Both existing archives were revalidated against the current source tree, without rebuilding or uploading:

- `doughboss-2.43.0.zip`: **137 files**, exact current-tree bytes; SHA-256 `4d984e715d0d52bc06a0da5a34af79c27087f321a5479acee134c632ab644368`.
- `doughboss-theme-focus-local.zip`: theme **1.6.0**, **25 files**, exact current-tree bytes; SHA-256 `79d1901a4f1084dcd027ad31c7ef9b8fe11f825ece983dd85a58c5c546cd60f6`.

The commands were the existing `scripts/validate-zip.php` and `scripts/validate-theme-zip.php` under PHP 8.2.33 with ZipArchive loaded. Both exited 0. No source behavior changed in this follow-on, so the prior 207-assertion PHP and 26-test Node results remain the latest behavioral test evidence rather than being represented as newly rerun tests.

**Superseding plugin hold, 9 September:** do not install the earlier 2.43.0 candidate. A subsequent isolated Stripe lifecycle run reproduced two runtime defects; the separately versioned 2.43.1 repair candidate has now passed its local gates and bounded Astra review, but is not released or deployed. Preserve the earlier ZIP and release asset unchanged. Read the latest Stripe recovery section in `REVIEW-20260908.md` for current candidate evidence and approval status.

**Next owner-dependent actions:** provide a host-supported staging/private-theme-preview path, or explicitly choose how the file-edit restriction should be handled. Do not weaken it automatically. A real draft preview and explicit publish approval are still required before public theme publication. Square connection work separately awaits confirmation of the merchant account and configured locations described in `SQUARE-MIGRATION-20260908.md`; no OAuth URL, account connection or migration is claimed. Do not repeat the failed install, browser claim or draft-creation attempts unless relevant external state has changed.
