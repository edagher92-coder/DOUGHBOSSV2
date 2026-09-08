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
