# Storefront completion checkpoint — 8 September 2026

## Scope and source

- Checkout: `C:\Codex\.codex\worktrees\5bdb\DOUGHBOSSV2-review-20260908`
- Branch: `codex/storefront-completion-20260908`
- Base: `4909823beb0767a4c5a9086b8c0ed59083215237`
- Plugin/theme candidate: 2.43.0 / DoughBoss Final 1.6.0
- Prior baseline: [draft PR #66](https://github.com/edagher92-coder/DOUGHBOSSV2/pull/66), [successful CI](https://github.com/edagher92-coder/DOUGHBOSSV2/actions/runs/34216140907).

Outcome: saved dietary flags appear on menu cards, a sitewide pickup selector shares short-lived configured-hours status with the ordering page, and image markup reserves real dimensions with genuine responsive sources when Media Library variants exist.

Non-goals: no new framework or dependency, no provider activation, no live configuration mutation, no new bookable capacity/preorders, no guessed stores/prices/dietary certification, and no production publishing without backup/rollback evidence. The original July checkout and all quarantine content remain untouched.

## Implemented behavior

1. Menu badges use only the saved `vegetarian`, `vegan`, `halal` and `gluten_free` flags. Invalid or unknown inputs are ignored. A gluten-free crust choice is not converted into a gluten-free claim about the base item. No halal certification or nut-free claim is inferred.
2. Public location responses include `pickup_status`, explicitly limited to configured pickup hours. Status distinguishes open, closing soon, closed, unavailable and unknown, with UTC observation/expiry and the next scheduled opening where known.
3. The PHP calculator handles weekday ranges, split sessions, merged overlaps, overnight spill, exclusive closing boundaries, Sydney DST gaps/folds and conservative dated-exception blackouts. Missing/invalid configuration and database read failures do not become false “open” claims. Responses are `Cache-Control: no-store, max-age=0`.
4. Header status and full ordering-page status use the same small presentation script. An expired observation becomes unconfirmed. A failed load retains a usable locations link. Global ordering pause takes precedence over pickup hours. A scheduled next opening is not advertised as a pre-order booking.
5. The header's shop-change event can be cancelled by the existing checkout lock. Fixed table context has no editable shop selector. Selection synchronizes with the main storefront. Header resize observation keeps sticky menu controls below the asynchronously rendered header.
6. Non-storefront templates load the small public-read script without pulling in checkout/card libraries. No global gateway initialization rule was broadened.
   The public-only script does not send a WordPress REST nonce, avoiding expiry failures from cached pages. Same-origin signed table cookies remain attached; protected storefront requests retain their existing nonce checks.
7. Bundled theme photos now declare actual source dimensions; below-fold images are lazy/async, heroes are prioritized. The Manoush hero is a discoverable decorative image with full-bleed reduced-motion layout. WordPress attachment URLs use actual `wp_get_attachment_image` srcsets. Bundled originals do not receive fabricated variants.

## Verification

Completed local gates:

```text
PHP syntax: 88 files passed on both PHP 7.4.33 and 8.2.33
PHP unit assertions: 158 passed, 0 failed on both PHP 7.4.33 and 8.2.33
JavaScript syntax: 15 files passed
Dependency-free storefront helper suite: passed
WordPress/MySQL assertions: 96 passed, 0 failed
Two-process mocked Square race: 1 provider POST, 0 duplicate charges
Plugin archive: 138 exact runtime files
Theme archive: 25 exact runtime files
```

Commands:

```powershell
scripts/test-release.ps1 -PhpPath C:\Codex\Temp\doughboss-review-20260908\php-8.2.33\php.exe -ExtensionDir C:\Codex\Temp\doughboss-review-20260908\php-8.2.33\ext -OutputPath dist/doughboss-2.43.0.zip -ThemeOutputPath dist/doughboss-final-1.6.0.zip
scripts/test-wordpress-integration.ps1 -PhpPath C:\Codex\Temp\doughboss-review-20260908\php-8.2.33\php.exe -WpCliPath C:\Codex\Temp\doughboss-integration-20260908\wp-cli.phar -WordPressPath C:\Codex\Temp\doughboss-integration-20260908\wordpress -PhpIniPath C:\Codex\Temp\doughboss-integration-20260908\php.ini
```

Browser checks used real local WordPress markup with synthetic menu/API fixtures, not customer orders:

- 390px mobile: no horizontal overflow, expected five dietary badges, drawer focus/Escape preserved, sticky offset matches the measured 120px/146px header as content wraps.
- Synthetic two-shop response: header and ordering selectors both switch to shop 2; a cancelled shop-change request leaves both and the saved preference at shop 2.
- A two-second synthetic hours observation expires to “unconfirmed”; an HTTP 503 produces the locations fallback.
- Synthetic table T7: fixed shop/table label and zero editable shop selectors.
- 1440px homepage, reduced motion: hero and image bounds match, transform is `none`, measured source is 1080×864, no exposed edges or horizontal overflow.
- Non-storefront 404 template: status and theme scripts load without the main storefront or card SDKs. This proves asset isolation, not content completeness of a production marketing page.
- The local favicon 404, deliberate synthetic service error and deliberately missing test page are expected fixture responses, not hidden passing assertions.

Browser evidence is retained outside the source tree at `C:\Codex\Temp\doughboss-storefront-completion-20260908\browser-output`. Temporary local site URLs are restored after browser work. No live site data was used in fixtures.

## Five-milestone acceptance limits

| Milestone | Delivered evidence | Still required |
| --- | --- | --- |
| Mobile customizer/cart | Accessible sheet, sticky item/cart controls, saved dietary badges, focus and viewport checks | Owner visual acceptance; style/crust controls remain native radio choices, not a new segmented-control redesign |
| Stores/hours | Configured read-only hours/status, header selection, conservative freshness | Confirm/configure all intended online branches and holiday hours; booking/preorder capacity remains off/shadow only |
| Catering | Existing packages, server quote/enquiry records and shop/customer mail hooks verified in source | Headcount estimator, stale quote/race handling, larger-group commercial rule and real delivery acceptance |
| Checkout/kitchen | Prior accepted recovery/pricing/webhook seams plus local database/concurrency regressions | Provider sandbox, real catalog/modifier mapping, POS/KDS/printer and refund acceptance; generic CloudPRNT is not implemented |
| SEO/performance | Existing per-active-location schema, font swap, reduced motion, genuine dimensions/attachment responsive markup | Verified metadata for additional branches, real image variants for bundled photos and production Web Vitals measurements |

SamOS discovery identified `snowflow-ask-sam`; its DoughBoss workspace/site/location foundation exists. A live authenticated read adapter is not proven. Credentials must remain server-side; bare numeric location IDs are not globally unique. No SamOS schema was duplicated in WordPress.

## Publication and delivery gates

The 8 September read-only production check still showed plugin 2.41.0 / theme 1.4.0, ordering enabled, payments disabled, Stripe selected, Revesby ID 1 and 43 menu items. Existing POSPal configuration was not changed. This release must not enable payments or alter live ordering/POSPal settings.

The historical shared Drive folder returns 404. The owner was asked for the intended accessible folder URL and the date/time of the latest successful full UpdraftPlus backup (database, plugins, themes and uploads). UpdraftPlus is active and has backup-history records, but record lengths alone do not prove a successful recoverable backup. The local full-folder ZIP is a credential-filtered source backup, not that live database/uploads backup.

The canonical plugin exceeds the previously documented 2MB browser upload cap; a supported transport must be confirmed before publication. Preserve inactive duplicates, quarantine and rollback artifacts. Do not activate unrelated uploader helpers or invent a public download URL.

## Final artifact evidence

The final local release gate was rerun after the short-expiry timer, public-read nonce and PHP 7.4 DST repairs and passed with the counts above. The 96-assertion database/concurrency gate was also rerun after the DST repair. These artifacts match the runtime source bytes:

| Artifact | Bytes | SHA-256 |
| --- | ---: | --- |
| `dist/doughboss-2.43.0.zip` | 2,576,351 | `D4C527C852B0890AB9AB9B60616302E7A5430EA41BEF321E7412431E37FA390A` |
| `dist/doughboss-final-1.6.0.zip` | 30,513 | `A2B4C822F644550506214F57F872DB8A2764BABC4826D810FEA91BF2A1C5475D` |

Earlier intermediate artifacts were moved to the external local staging folder, not overwritten or presented as final. A fresh read-only Sol xhigh review returned `ship` after the public-read nonce repair; the reviewer independently reran final JavaScript syntax/helper checks and `git diff --check`. Astra's earlier payment-architecture acceptance remains limited to the frozen payment-hardening baseline, not this new UI batch.

The authorized delivery shape is one consolidated commit/push and one draft PR/automatic CI cycle, stacked on #66. Consult that PR for its current hosted-CI result. Publishing, provider activation and Drive writes are not part of this source-delivery action. This is not a production completion declaration.

## Hosted compatibility repair

[Draft PR #67](https://github.com/edagher92-coder/DOUGHBOSSV2/pull/67) was opened for commit `6928123`. Its first [CI run 34219384387](https://github.com/edagher92-coder/DOUGHBOSSV2/actions/runs/34219384387) passed PHP 8.2 and paired archives but failed the PHP 7.4 repeated-hour regression (157/158 passed). No remote rerun was used to diagnose it.

The failure reproduced locally with the official portable PHP 7.4.33 CLI. Two Sydney fold instants (`1775316600` and `1775320200`) both reported `1775320200` after timezone conversion on 7.4; PHP 8.2 retained distinct timestamps. This is consistent with the [PHP repeated-hour timestamp issue](https://bugs.php.net/bug.php?id=68549). The minimal repair keys ambiguity matches by the original UTC timestamp rather than the timezone-converted object's `getTimestamp()`. The existing failing test now passes on both runtimes; test expectations were not weakened. PHP 7.4 is used only as a local CLI compatibility test, not a web server or production runtime.

A fresh read-only Sol high reviewer reproduced the timestamp difference and independently passed 158 assertions plus changed-file lint on both PHP versions, returning `ship` for this bounded repair. One deliberate follow-up push/CI cycle follows this local reproduction, repair, final gates and focused review. Intermediate ZIPs and the pre-repair source backup remain preserved but are not the final installable release.
