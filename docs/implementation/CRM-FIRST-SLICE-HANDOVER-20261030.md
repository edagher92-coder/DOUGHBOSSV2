# DoughBoss CRM — first implementation handover

30 October 2026. Prepared in an isolated local checkout on `crm`, based on `07a34610eec29fd5ce3f8567d215885420b78462`. The owner approved publication and one Plugin CI run after the local gate; publication receipts are recorded separately. This is the first read-only CRM increment, not the completed corporate CRM programme or a deployed release.

## Delivered behaviour

Managers and administrators can select **Catering CRM** in the existing `/management/` navigation. The local candidate route is `/management/?section=crm`.

The view uses the existing catering enquiry table and lifecycle. It provides name/email/enquiry-number search, canonical status filters, fixed 20-record pages, event and guest details, saved quote value/currency, and links back to the existing catering admin workflow. It does not create an additional enquiry, quote or payment model. Amounts are labelled as enquiry quotes rather than settled revenue.

The existing manager gate still runs before page output. The CRM service also checks authorization before any enquiry read. Invalid scalar types, nulls, unknown statuses, oversize searches and invalid page values are rejected before query execution. Existing query values remain bound; customer output is escaped. Count/list database failures return a generic unavailable state instead of an apparently empty inbox. Managers retain the existing broad all-store authority; this slice introduces no sales role or location-scoped permissions.

Runtime changes: `includes/class-doughboss-crm.php`, its load in `includes/class-doughboss.php`, the management selector/navigation in `includes/class-doughboss-portals.php`, failure detection in `DoughBoss_Catering::query`, and scoped styles in `public/css/doughboss-portals.css`. Focused regressions are in `tests/test-crm.php`.

## Plan and requested resource work

- Astra's `CRM-EXECUTION-PLAN-20261030.md` maps the strategy PDF and actual implementation, chooses the additive WordPress path and defines later account/contact/task/consent, quote, recurrence, kitchen/cost, Square and Telegram stages.
- Terra's `CRM-REFERENCE-SCOUT-20261030.md` records the downloaded, pinned VoltAgent catalogue, REA and Comp AI CRM, with licence and compatibility boundaries. No downloaded skills were activated and no new production dependency was installed.
- `CRM-REA-EXTRACTION-20261030.md` and its two JSON receipts record actual selected-source REA analyses: one reference TypeScript backend service and two existing DoughBoss frontend scripts. Coverage is explicitly partial; this is not a claim that every repository file or a private/deployed backend was reconstructed.
- `CRM-TELEGRAM-REUSE-AUDIT-20261030.md` records current local Hermes and repository source/configuration metadata without tokens or chat IDs. Retain Hermes as sole inbound consumer; no new polling consumer or webhook was introduced. The SamOS read-only connector is a pattern to adapt, not an already working DoughBoss connector. A supported gateway-owned outbound relay remains to be established.

The audit corrected a material assumption: wholesale/B2B enquiries currently send email without persistence, so they are not retrospectively available in this CRM inbox. The canonical catering form does persist enquiries. Existing Square invoice/idempotency/reconciliation source is to be reused; live seller/location, settlement and provider acceptance were not verified in this batch.

## Local evidence

| Check | Result |
|---|---|
| Before-change PHP 8.2 baseline | 4,554 assertions passed |
| Integrated PHP 8.2 suite | 4,649 assertions passed, zero failed |
| Independent lead run of CRM tests | 95 assertions passed, zero failed |
| PHP 7.4 compatibility suite | 4,457 assertions passed, zero failed |
| Full declared release gate | Passed on one deliberate retry |
| PHP / JavaScript syntax | 237 PHP and 39 JavaScript files passed |
| Node 24.22.1 gate | 12 files completed: 382 tests passed, zero failed, 36 skipped |
| Supplementary consent suite | With existing PHP added only to process PATH: 32 passed, zero failed, one Acorn skip |
| Plugin / theme archives | Built and current-runtime-source bytes validated: 387 / 27 files |
| Fresh Astra source review | Approve; zero actionable findings for this slice |
| Git whitespace | Passed |

The supplementary consent run exercises the five PHP-path-dependent tests that were skipped by the main gate. It is separate evidence; the main gate's original totals are retained. Missing Acorn, TypeScript exporter dependencies and the unset optional core-source configuration still leave their corresponding checks unverified. No package was installed to mask these skips.

The first release run stopped at an unchanged attribution-schema raw-byte oracle. Git had converted its checkout from LF to CRLF. The lead first proved the file was otherwise unchanged and that normalizing it exactly matched committed bytes, then restored those exact bytes without changing the oracle. The single retry passed. Git now shows no content change for that unrelated file. See `CRM-FIRST-SLICE-GATE-20261030.md` and logs under `dist/crm-gate-20261030/`.

## Review artifacts

| Candidate | Bytes | SHA-256 |
|---|---:|---|
| `dist/crm-first-slice-20261030.zip` | 16001539 | `893655e8318cd024be5522d8ea7bab3412c3b476613b47f50d4ce30c3b86114a` |
| `dist/crm-theme-first-slice-20261030.zip` | 42329 | `de1651fc5670f30dee53b43f1c67fa41fa0e3c9bba93d8b70189296b8aec7495` |

These are local review candidates; unchanged version identifiers do not turn them into an accepted live release. The runtime-only packaging whitelist excludes the research documents, downloaded reference repositories and local Hermes configuration.

## Remaining acceptance and next batch

Real WordPress/MySQL, authenticated browser/mobile/keyboard, homepage-enquiry-to-inbox, merchant/provider, bot identity/transport/delivery and deployment acceptance remain unverified. No live messages, invoices, payments, webhooks, settings, customer campaigns or Snowflow production systems were changed.

The next coherent implementation batch is the reviewed account/contact/follow-up/consent data design with migration and rollback, followed by the approved persistence implementation. Quote version approval, actual contribution/costing, recurrence and the Hermes-owned read/notification boundary follow the staged plan. Bot credentials, recipient mapping and any real test message remain separate operational actions.

For repository publication, group this source, tests and audit/plan documents into one `crm` batch with one normal GitHub validation cycle. A push/PR, hosted/manual workflow or deployment has not been triggered by the local gate.
