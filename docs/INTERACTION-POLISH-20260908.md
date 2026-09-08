# Interaction polish - local follow-on, 8 September 2026

## Scope contract

- Outcome: latest-selection-only catering quote rendering, no stale totals on request failure, preserved enquiry fields when switching packages, and segmented native Style/Crust controls.
- Non-goals: no catering pricing-policy change, headcount/package multiplication, backend/payment redesign, dependency, version bump, production setting change, deployment or Drive upload.
- Branch: `codex/catering-interaction-polish-20260908`.
- Base: `56159b55c91672ac704cbe016343659e90a29cca`, the successful [PR #67](https://github.com/edagher92-coder/DOUGHBOSSV2/pull/67) source checkpoint.
- Runtime files: `public/js/doughboss-catering.js`, `public/js/doughboss.js`, `public/css/doughboss.css`.
- Proof files: existing `tests/storefront-helpers.test.js` and this handoff.

The previous installable plugin/theme ZIPs and full-folder backup remain unchanged. This follow-on is local-only and is not covered by PR #67's hosted CI result.

## Implemented changes

1. Catering quote generation increments across package, guest-count and fulfilment changes. Each change clears the prior estimate immediately. Only the current generation can paint success or failure; a stale failure cannot clear a newer valid total.
2. Quote results require finite nonnegative numeric fields, a bounded deposit percentage, integer lead days and an explicit three-letter currency code. Display trims/uppercases that returned currency, preserving valid lowercase or mixed-case stored settings. HTTP errors, invalid response shapes and eight-second timeouts produce an unavailable message instead of retaining old prices.
3. Package selection changes only the selected summary and card states, preserving the existing form DOM and entered customer/event/dietary fields. Selected package cards expose `aria-pressed`. Package-selection scrolling respects reduced motion.
4. Public package/quote reads no longer depend on a page's expiring WordPress nonce; they retain same-origin credentials and request no cache. Protected POST requests retain their nonce. Both existing request helpers use plain-permalink-safe endpoint URL construction.
5. Failed package loading stays visibly distinguishable from an empty catalog while retaining the custom-enquiry form.
6. Only canonical `style` and `crust` radio groups receive segmented styling. Native radio inputs, names, values, default selection, change handlers, server-supplied deltas and submitted slugs are unchanged. Controls have 44px minimum targets, wrapping labels, focus-visible and disabled states. Browsers without `:has()` retain visible native radio glyphs and focus rings; hiding them is guarded by `@supports`.

No server price is calculated or overridden by these changes. The unresolved larger-group package policy remains a separate operational blocker; this patch does not claim to implement the full catering estimator.

## Orchestration and advisory evidence

- `sol-lead-orchestrator` split the independent menu-control implementation from lead-owned catering interactions and tests. Terra owned only the menu JavaScript/CSS pair; the lead inspected the actual diff and reran local checks.
- `frontend-design` guided preserving existing DoughBoss ink/paper tokens, type and compact controls rather than introducing a new design system.
- One guarded `ollama-dev-orchestrator` call used `tests`, data class `synthetic`, preferred model `kimi-k2.7-code:cloud`. It received only the abstract quote contract and proposed repair, not source credentials, customer data or business records. Relevant advice about a shared generation, failure states and DOM retention was incorporated into regression checks. Cancellation advice was not adopted: correctness uses the generation check, and each read has a bounded timeout. No second advisory call was made.
- The fresh read-only Sol high review identified two fix-first issues: unconditional radio hiding without a `:has()` fallback, and rejection of valid lowercase stored currency codes. Both were repaired. The reviewer independently reran JavaScript syntax, all nine named tests and diff checks, returning `ship` for the repaired code. The lead also reran the complete local PHP/JavaScript/archive gate after these repairs. This is not browser or production acceptance, nor an Astra acceptance claim; Astra's prior payment-architecture review remains scoped to the earlier batch.

## Local checks

Commands run after the runtime changes:

```powershell
node --check public/js/doughboss.js
node --check public/js/doughboss-catering.js
node --test tests/storefront-helpers.test.js
& C:\Codex\Temp\doughboss-review-20260908\php-8.2.33\php.exe -n tests/run.php
& C:\Codex\Temp\doughboss-review-20260908\php-7.4.33\php.exe -n tests/run.php
git diff --check
& .\scripts\test-release.ps1 -PhpPath C:\Codex\Temp\doughboss-review-20260908\php-8.2.33\php.exe -ExtensionDir C:\Codex\Temp\doughboss-review-20260908\php-8.2.33\ext -OutputPath C:\Codex\Temp\doughboss-interaction-polish-20260908\doughboss-interaction-reviewed-local.zip -ThemeOutputPath C:\Codex\Temp\doughboss-interaction-polish-20260908\doughboss-theme-reviewed-local.zip
```

Results after both review repairs: PHP syntax 88 files on PHP 8.2; PHP assertions 158 passed/0 failed on PHP 7.4.33 and 8.2.33; JavaScript syntax 15 files; nine named dependency-free Node tests passed plus existing top-level assertions. The external review-only ZIPs matched all 138 plugin and 25 theme runtime files. No remote CI or provider simulation was triggered for this follow-on. The real database/concurrency and hosted PHP 7.4 evidence at PR #67 belongs to the unchanged base, not a new run of this patch. The reviewed-local ZIPs still carry the base versions 2.43.0/1.6.0: they are not versioned release artifacts and must not replace the previously delivered canonical ZIPs.

Final local review plugin: 2,578,150 bytes, SHA-256 `6687A57DB034576E03806F4660026B2672FA344040DAC21EC97E524772B96A37`. Theme: 30,513 bytes, SHA-256 `A2B4C822F644550506214F57F872DB8A2764BABC4826D810FEA91BF2A1C5475D`. Earlier review-only ZIPs remain preserved outside source under their original names.

## Unverified browser acceptance

A disposable loopback-only fixture was prepared outside source at `C:\Codex\Temp\doughboss-interaction-polish-20260908`. The tool rejected the PHP fixture-server launch with `rejected: blocked by policy`. That launch was not retried or bypassed, and no browser acceptance result is claimed. The local source/package checks above remained available and passed.

On the next continuation, source was revalidated at local commit `5de8324f4e84e03b2111bb1de34e0bfe2d1fcab0` with a clean working tree. A distinct offline test was attempted without any server: the synthetic fixture referenced only the existing local scripts/styles and replaced API calls in memory. Playwright CLI opened its dedicated browser session, but navigation returned `Access to "file:" protocol is blocked`. No local page was loaded and no browser checks ran. The dedicated `doughboss-polish-offline` session was then closed successfully. No alternate file-access mechanism, listener, server-launch retry, browser security flag or runtime injection was used to circumvent either rejection.

Browser acceptance now requires a supported preview/staging URL or an approved test environment able to load these project assets. Do not count the prepared fixture, a browser process starting, helper tests or a code-review verdict as rendered UI evidence. A read-only GitHub check confirmed PR #67 remains open/draft at `56159b55c91672ac704cbe016343659e90a29cca`, with all three previous jobs successful. The local interaction checkpoint was not pushed, and no new CI run or deployment was triggered.

Before releasing this follow-on, verify in a permitted browser environment:

- Retain entered name, contact fields, event date/time, address and dietary notes across package switches; check the submitted synthetic enquiry still matches those fields and the selected package.
- Resolve quote requests in reverse order and fail current/older requests separately; verify no stale amount is shown, and a later edit recovers from failure.
- Verify package-load failure copy remains visible and the custom-enquiry form is usable.
- At 320px and desktop widths, verify segmented labels fit, minimum target heights, selected/disabled contrast and keyboard focus.
- Use Tab and arrow keys through Style/Crust; confirm native selection, preview deltas and canonical add-to-cart slugs using synthetic interception only.
- Verify the mobile sheet's focus trap/Escape restoration and reduced-motion behavior remain correct.

The broader publication, accessible shared Drive target, full live backup/rollback, catering policy, provider/POS acceptance, SamOS and production performance gates remain as documented in `docs/STOREFRONT-COMPLETION-20260908.md`. No goal-completion claim is made.

## User-directed Astra-to-orchestrator phase

The user directed: finish Astra's review, then use the orchestrator to implement and code. The earlier accepted Astra artifact covers the frozen payment architecture, not the later storefront/hours/catering changes. A new bounded, read-only Astra review is assigned for those residual integration and release questions at source commit `e1c7f57c1efab1b5a76aa75d05de88403c33e25a`.

- Model/effort: `gpt-6-astra`, `xhigh`, directly exposed by the active collaboration schema on 8 September 2026. Cost/credit estimate: unknown; no billing probe or benchmark.
- Ownership: read-only source review; no provider calls, browser launches, credentials, live settings, source edits or remote writes. Existing payment-protocol acceptance is reused, not restarted.
- Required result: evidence-backed residual findings and a ranked implementation packet, separating code defects, missing features, commercial decisions, runtime evidence and publication prerequisites. Each actionable item needs exact files/contracts, recommended worker capability, acceptance checks and stop conditions.
- Acceptance: the lead verifies findings against actual source, rejects unsupported claims and preserves every unverified browser/provider/deployment gate. Review completion is not production completion.
- Return route: after the accepted Astra review, resume the Sol orchestration workflow for bounded Terra/Luna implementation and independent Sol verification; use the guarded Ollama partner only where materially useful and within its data/call rules. Do not claim an in-place root model switch.
- Status: residual review returned `fix-first`; the lead directly checked the three catering findings below. The user then assigned Astra a separate design/front-end brief phase. That design brief has now returned and been checked; the temporary Astra phase is closed and the first integrity batch is implemented locally as recorded below.

The lead inspected the actual validation scripts while the read-only review ran. The implementation packet must use these boundaries:

| Gate | Current command/implementation | Evidence boundary |
| --- | --- | --- |
| Focused PHP | `php tests/run.php` | Existing unit assertions; not a running WordPress database or provider acceptance |
| Focused JavaScript | `node --test tests/storefront-helpers.test.js` and `node --check` | Helper/control logic and parse checks; not rendered keyboard/layout evidence |
| Full local package gate | `scripts/test-release.ps1` with explicit PHP/extension and external candidate output paths | PHP/JS checks and exact plugin/theme source-byte ZIP validation |
| WordPress/database | `scripts/test-wordpress-integration.ps1` against a designated disposable installation | SQL/integration assertions and a mocked two-process Square race; never point it at production |
| Hosted PR CI | `.github/workflows/plugin-ci.yml`, one approved consolidated PR cycle | PHP 7.4/8.2, JavaScript and ZIP jobs; it does not run the real database/browser/provider gates |
| Publication and business acceptance | Explicit live backup/rollback, supported transport, owner-approved provider/staff checks and read-only post-publication inspection | Cannot be substituted with a code review, helper tests or a synthetic provider mock |

No workflow was dispatched during this preparation. The read-only review does not authorize new dependencies, live payment enablement, real orders, production changes or access-control workarounds.

### Accepted residual findings and next integrity batch

Astra completed its bounded review at `e1c7f57` and independently passed both PHP runtimes (158 assertions each), the nine named Node tests and `git diff --check`. Those existing suites do not cover the full catering enquiry-to-mail path. The lead verified these source paths directly:

1. **Selected shop is omitted:** the catering enquiry POST does not include the existing `location_id` field or consume `doughboss:shop-changed`. The REST callback therefore defaults a missing selection. With two configured shops this can disagree with the header's visible selection; no actual misrouted production enquiry was observed.
2. **Invalid package looks free:** `DoughBoss_Catering::quote()` gives missing, draft and wrong-type positive IDs the same zero quote as intentional custom package `0`. `create()` retains that positive ID, while the staff custom-quote path requires `0`. Validate public package availability at quote and creation boundaries without changing valid arithmetic, existing payment-bound records or the explicit custom path.
3. **Staff mail is incomplete:** `send_catering_notification()` reuses a short customer acknowledgement as the staff body. Use saved values to include reference, shop, package/custom label, guests, event date/time, fulfilment/address, name/email/phone, dietary requirements and notes. Keep the existing configured recipient and honest enquiry/indicative wording. Mail composition tests do not prove delivery.

The smallest coherent implementation batch is **catering enquiry integrity**: Terra owns the client selection/submit interaction; one lead owns the shared catering service and REST methods; test ownership is assigned explicitly once interfaces are fixed. No new schema, mail provider or pricing rule is needed. Explicitly stale selections must not silently route to another shop; preserve intentional missing-location compatibility. Keep an in-flight submission's location snapshot fixed and visible, or veto switching until it completes. Use the existing WordPress integration mail interception; never send test mail or provider traffic.

### User-directed Astra design phase

After the residual review, the user assigned Astra the design and front-end improvement brief, then a return to Sol orchestration for implementation. This is a new, bounded read-only assignment on the same source snapshot, not a reopened payment review.

- Actual assigned model: `gpt-6-astra`, `xhigh`, using the existing Astra lane. Lead integration authority is unchanged; no application model-selector switch is claimed.
- Outcome: an implementation-ready brief for distinct navigation, readable content/workspace panels, elevated dialogs, restrained glass with solid fallbacks, accessible state transitions/reduced motion, and phone usability. Existing self-hosted Bebas Neue/Barlow typography and DoughBoss ink/paper identity remain the baseline.
- Scope: current WordPress storefront/theme source. SamOS receives a clearly labelled operational-UI handoff contract only; this phase does not claim another repository was inspected or its dashboard implemented.
- Optional tools: Higgsfield connector availability was discovered, but no generation, credit-consuming job or media upload was submitted. An abstract background is optional and must not carry product claims or compete with prices, status, order information or text. The brief will remain ordinary Markdown usable in Obsidian; no vault, plugin, sync or third-party note capture is configured by this task.
- Required return: one recommended direction, compact colour/type tokens, layering and motion rules, component/state matrix, mobile/desktop layouts, source-file implementation slices, acceptance checks and stop conditions. Separate already implemented controls from actual gaps. Preserve the header's pseudo-element blur boundary so the fixed mobile navigation does not collapse into the header.
- Benchmark evidence: on 8 September 2026 the public [Ooshman site](https://ooshman.au/) (the Manoosh URL redirected there) exposed direct menu/order, location and catering paths with location-specific hours guidance. [Black Star Pastry](https://blackstarpastry.com/) exposed signature product collections, quick-view, price ranges, stores and delivery/pickup paths. These are content/information-architecture observations, not measured visual quality, usability rankings or permission to copy assets. The DoughBoss homepage fetch timed out; no fresh rendered-site verdict follows.
- Acceptance/return: the lead checked Astra's source-backed recommendations and brand/safety fit, including recomputing the proposed solid-pair contrast ratios. The accepted [Astra design brief](ASTRA-DESIGN-BRIEF-20260908.md) records the remaining browser evidence and staged implementation. Catering integrity precedes the broader aesthetic changes. No app-selector switch or full-site acceptance is claimed.

### Catering enquiry integrity: implemented and locally verified

Outcome: the customer can see and submit the preferred shop, stale positive package/shop selections fail without creating an enquiry, and the configured staff mailbox receives a complete saved-enquiry summary. Explicit custom package `0`, omitted/zero legacy location behavior, valid package arithmetic and payment code remain unchanged.

Implementation ownership and scope:

- Actual Sol backend lane: `gpt-5.6-sol`, `high`; exclusive catering service/REST methods and the existing real-WordPress integration test. Both `quote()` callers were inspected and now propagate the explicit unavailable-package `WP_Error`.
- A Terra client-worker spawn was rejected by the environment's agent-thread limit. The lead therefore owned catering JavaScript, existing Node tests and the minimal field-select CSS. The shared header file needed one narrow integration repair: retain a form's early shop-change event before asynchronous header initialization, including when local storage is blocked. No model switch or Terra implementation is claimed.
- Catering reads existing public locations and table context, shows a preferred-shop control and explicit loading/unavailable states, honours the signed table's existing location, supports retry and retains form inputs. An unavailable explicit preference requires a valid choice, not a silent default. Only a verified empty shop list permits the existing location-zero compatibility path.
- While an enquiry is pending, the form disables shop selection and vetoes sitewide shop-change requests. The POST captures location, package and form fields once; failure retains current inputs. The success panel identifies the submitted shop without claiming an event reservation.
- Staff mail includes the saved reference, shop ID/name, package/custom label, guests, date/time, fulfilment/address, contact details, dietary requirements, notes and indicative prices. The configured recipient and customer acknowledgement remain unchanged. Failure logs contain the reference, not customer fields. The existing human package title remains a live lookup; immutable title snapshots would be a separately approved schema change, not part of this batch.

One guarded Ollama `tests` call used preferred `kimi-k2.7-code:cloud`, data class `synthetic`, with only a conceptual contract/test-plan summary. No repository credentials, customer records or financial data were sent. Advice prompted explicit mid-flight field-edit snapshot and stale-preference/table-context precedence assertions. Its early-event/storage scenario was already covered; disabled form controls and the header event veto are complementary, not a contradiction. No second call was made.

The available independent read-only review lane inspected the frozen combined diff and returned `ship`, with the non-blocking existing package-title lookup qualification above. It did not write the changes or independently run the integration suite. Reactivating the separate prior Sol-review lane also hit the agent-thread limit, so no dedicated fresh Sol-review-model result is claimed for this batch. The lead independently inspected all changed contracts and ran the checks below.

| Check | Actual result |
| --- | --- |
| `node --test tests/storefront-helpers.test.js` | 18 named tests passed, plus existing top-level assertions |
| PHP 7.4.33 and 8.2.33 `-n tests/run.php` | 158 assertions passed on each runtime |
| `scripts/test-release.ps1` with external candidate outputs | 88 PHP syntax files, 15 JavaScript syntax files; exact 138 plugin / 25 theme runtime files validated |
| `scripts/test-wordpress-integration.ps1` against the guarded disposable installation | 141 WordPress/MySQL assertions passed; two-process Square race produced one intercepted mock POST and no duplicate simulated charge |
| Tested PHP source identity | Both changed PHP files SHA-256 matched the installed disposable test copies |
| `git diff --check` | Passed |

The real-WordPress tests add synthetic package/shop fixtures and capture `pre_wp_mail`; no real customer, email transport or provider was used. The only database process started by this batch was the existing disposable MariaDB installation bound to `127.0.0.1:33117` (PID 43748); it was stopped after the completed tests. No browser fixture launch or blocked file-protocol retry occurred.

Review-only artifacts outside source:

- `C:\Codex\Temp\doughboss-catering-integrity-20260908\doughboss-integrity-local.zip`: 2,580,902 bytes; SHA-256 `C57BE6A52654396DDDD1AC64E80BCEDBD5BF49C144ECB237274A3086D19469AC`.
- `C:\Codex\Temp\doughboss-catering-integrity-20260908\doughboss-theme-local.zip`: 30,513 bytes; SHA-256 `A2B4C822F644550506214F57F872DB8A2764BABC4826D810FEA91BF2A1C5475D`.

These still carry base versions 2.43.0/1.6.0 and are not versioned release artifacts. Existing canonical ZIPs, full-folder backup and quarantine were not overwritten. No push, PR update, hosted CI, generation, deployment, Drive upload, live settings change, real payment or real order was performed in this follow-on.

Next: the accepted design brief's surfaces/focus/motion and compact-order-entry stages, with a supported preview needed for visual acceptance. The shared Drive target, full live backup/rollback, catering pricing policy, provider/POS/mail acceptance and SamOS integration remain separate unmet gates. Passing this local integrity batch does not complete the overall goal.

### Astra design stages 1–2: implemented locally

The accepted brief now has a coherent local implementation. The existing Sol high lane owned the three theme files; the lead owned plugin presentation, the obsolete asset enqueue removal, and the existing Node tests. Saved WordPress navigation, homepage hero content/photos, customizer business logic, prices, checkout and live settings were not changed. No additional model or asset-generation call was needed for this batch.

- Navigation uses opaque Coal by default, with supported blur only on its pseudo-element and a solid increased-contrast fallback. The header itself retains the fixed-drawer containing-block invariant. Theme, storefront and detached customizer focus treatments use contrasting ink/paper rings.
- Opening the mobile navigation moves focus inside before isolating background branches. Closing restores exact prior `inert` and `aria-hidden` attribute states before returning focus. The desktop breakpoint releases isolation without moving an already-focused navigation link.
- Theme reveals are one-shot, translate-only and never fade content out. Runtime reduced-motion changes disconnect the observer and expose all content; queued callbacks retain their original observer safely. CSS independently disables transforms/delays for reduced motion and prevents reveal translation around focused content.
- Ordering tools and transient feedback are opaque. Interactive menu cards no longer scale, rotate, blur, fade or stagger into view. The motion-only `public/js/doughboss-order-page.js` and its enqueue were removed; Git retains the previous file. Order-page compatibility styles remain. Existing add-to-cart feedback hooks now use brief non-geometric emphasis, not bouncing money or shrinking controls.
- The order-only masthead has natural-growth minimums of 132px on phones and 168px desktop, with shorter copy and no redundant eyebrow/badge/readiness blocks. These are CSS targets, not measured rendered heights. The shop/status counter remains available while ordering is paused; builder/cart retain the existing ordering gate. Paused copy no longer claims a future launch. The homepage remains food-led.

The independent read-only review found no fix-first issue in the combined diff. It inspected source but did not rerun the lead's tests. The lead additionally corrected the reduced-motion selector specificity so CSS does not depend on JavaScript's media-change callback timing. No dedicated fresh Sol-review-model result is claimed: the prior environment agent-thread limit remains a documented routing limitation.

| Check | Final local evidence |
| --- | --- |
| `node --test tests/storefront-helpers.test.js` | 22 named tests passed, plus existing top-level assertions; new synthetic checks cover isolation/restoration, focus order, desktop release, preference changes/late callbacks, and removal of the card-motion path |
| PHP 7.4.33 and 8.2.33 `-n tests/run.php` | 158 assertions passed on each runtime |
| `scripts/test-release.ps1` using the reviewed outputs below | 88 PHP syntax files, 14 JavaScript syntax files; exact current-tree bytes for 137 plugin and 25 theme runtime files validated |
| `git diff --check` | Passed |

One local rebuild attempt correctly refused to replace the earlier candidate ZIP. The source/tests had passed; no existing archive was deleted or overwritten. The final gate then completed using distinct `reviewed` filenames. The previous WordPress/MySQL 141-assertion result belongs to the catering checkpoint above; that database suite was not rerun for this presentation-only batch. Browser/layout, live provider and mail delivery evidence remain unverified.

Final review-only artifacts (base versions 2.43.0 / 1.6.0, not versioned production releases):

- `C:\Codex\Temp\doughboss-ui-counter-20260908\doughboss-ui-reviewed-local.zip`: 2,578,211 bytes; SHA-256 `AE5E07128D2B6C89458436588A600433BB68668C8D4CCA8022237BE972EB0109`.
- `C:\Codex\Temp\doughboss-ui-counter-20260908\doughboss-theme-ui-reviewed-local.zip`: 30,661 bytes; SHA-256 `D5904B86F8473AE36A4DB9F3B6A5F022166F8BFB608036B365E1B56873DCB71D`.

The user asked to see the updated interface. Read-only GitHub discovery found the existing separate Pages site at `https://edagher92-coder.github.io/DOUGHBOSSV2/`, with no custom domain and the latest listed successful deployment on 18 August 2026. It does not contain this batch. PR #67 still targets source `56159b55c91672ac704cbe016343659e90a29cca`; its three successful checks are older evidence. The active checkout has only the plugin CI workflow and no current Pages build pipeline. WPVibe lists only the production WordPress site, not a staging installation. No preview was generated or published.

Next deliverable: an explicitly approved, separate visual-preview batch built from this source, followed by permitted-browser acceptance. A static Pages preview can demonstrate appearance and synthetic interactions, not a running WordPress/payment integration. Do not present the older demo, historical screenshots or a generated concept image as the updated rendered site. Production publication, complete live backup/rollback, accessible shared Drive target, catering policy, provider/POS/mail acceptance and SamOS remain unmet. No remote push/CI/deployment, live settings mutation, order, payment, email, Drive write or quarantine change occurred in this UI batch.
