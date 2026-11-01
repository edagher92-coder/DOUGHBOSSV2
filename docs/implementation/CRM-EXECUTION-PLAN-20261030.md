# DoughBoss CRM execution plan — 30 October 2026

Status: proposed implementation plan grounded in the local `crm` branch. No application code, database, provider, customer communication or production setting is changed by this document.

Baseline inspected: `07a34610eec29fd5ce3f8567d215885420b78462`, worktree `C:/Codex/.codex/worktrees/doughboss-crm-20261030`. The reference scout document is another lane's output and is preserved. Sol owns integration, implementation decisions and final acceptance; this bounded Astra assignment owns this plan only.

## Scope contract

- **Outcome:** connect the existing DoughBoss enquiry and management workflows into a staged corporate CRM, with a safe path to Square visibility and Telegram notifications/interactions.
- **Non-goals:** a second CRM application, replacement storefront/enquiry/payment engine, immediate multi-store rollout, live outreach, live invoicing, refunds, deployment, new dependencies, or a production migration.
- **Files now:** this document only. Proposed implementation files are listed below and require an exact build batch.
- **Proof now:** read the strategy PDF and current PHP contracts, identify unsupported assumptions, specify local behavioural acceptance and return the plan to Sol. Test commands below are planned, not reported as passed.

## Strategy extraction and precedence

The owner-provided `DoughBoss_2026-2032_Complete_Strategy_Charlie_Elie.pdf` has 14 pages. Text was extracted with the existing `pypdf` installation, without installing a dependency. Relevant requirements:

| Source | Requirement translated into software |
|---|---|
| PDF page 4, B2B revenue engine | Organisation, buying role, source, location, headcount, frequency, lawful contact basis, status, owner and next action. Target → researched → qualified → sampled → quoted → trial order → recurring → at risk → dormant. Version quotations; record rejection reasons and sample costs. |
| PDF page 4, account management | Weekly/fortnightly/monthly schedules with actual terms and cutoffs; account owner; renewal reminders subject to permission; feedback and complaints. Ten profitable recurring accounts is a pilot objective, not a forecast. |
| PDF page 10, Corporate CRM | Account, buyer permission, source, stage, quotes, contracts, repeat schedule, tasks, win/loss, retention, opt-out and audit. |
| PDF page 10, integration safety | One authoritative financial fact; signed webhooks; deduplication; retry/dead-letter handling; explicit WordPress shop ↔ Square merchant/location ↔ legal entity mapping. Hold paid-but-unmatched work for review. Server-side secrets and approvals; role/location scope; no automatic Snowflow customer pooling. |
| PDF page 11, delivery backlog | Read-only audit and source-of-truth matrix first; costing before contribution claims; traceable CRM/quotes; later batches/dispatch, verified finance, attribution, acquisition/workforce and staged release with both founders' approval. |

Repository companions are `CRM-BRANCH-START-HERE.md`, `DOUGHBOSS_2026_2032_MASTER_PLAN.md`, and the three dated strategy specifications for catering growth, production/overhead and V2 management engineering. These are requirements and proposals, not evidence of deployed functionality. Where older prose differs from current code, current code defines the local baseline and the discrepancy belongs in Sprint 0. In particular, the brief's suggestion of a B2B database is not supported by the inspected B2B handler.

The requested corporate delivery/recurrence vision is future scope. Current catering capture restricts pickup to active Revesby. Do not silently enable Darlinghurst, Melbourne, delivery, cross-entity access or financial automation to satisfy the vision.

## Current source-of-truth matrix

| Domain | Existing local implementation and evidence | Plan boundary / live evidence still needed |
|---|---|---|
| Public catering capture | `includes/class-doughboss-rest-controller.php`: `POST /doughboss/v1/catering/enquiry`, nonce gate, rate limit, honeypot, validated active Revesby location, server quote calculation, notification after persistence. `public/js/doughboss-catering.js` is the existing client. | Follow the homepage's actual deployed link/shortcode to this handler during audit. A repository route does not prove the live homepage uses it. Preserve its request/response contract in slice 1. |
| Enquiry and quote | `DoughBoss_Catering` owns `{prefix}doughboss_catering_enquiries`; `create`, `get`, `query`, `set_custom_quote`, payment legs and lifecycle already exist. Creation emits `doughboss_catering_enquiry_created` after insert. | Keep the enquiry ID/number canonical. No copied lead table containing another quote/payment state. Existing quote editing is not proof of immutable quote versions. |
| Wholesale/franchise | `DoughBoss_B2B::validate` and `send`; `create_b2b_enquiry` emails the configured inbox using `wp_mail`. Route comments explicitly say it stores nothing. | Do not promise retrospective CRM visibility or recover records from mail without a separately scoped import. Persistence is a later approved data-model change. |
| Management portal | `includes/class-doughboss-portals.php::render_management` requires `manage_doughboss` or `manage_options`; `/management/` renders the existing admin dashboard and links Catering to `admin.php?page=doughboss-catering`. | Extend this portal; no separate auth system or public CRM menu. Existing broad managers are not a demonstrated per-store sales role. |
| Listing permissions | Existing `GET /admin/catering` uses `verify_manage`. `DoughBoss_Catering::query` supports status/search/page/per_page, but no location filter. | Slice 1 remains within the existing broad manager boundary. Store-limited sales users require enforced query/detail scope before access is granted. A location dropdown is not authorization. |
| Catering status | Existing lifecycle: `new`, `quoted`, `deposit_paid`, `confirmed`, `balance_due`, `paid`, `fulfilled`, `lost`. Manager status changes can affect consequential business state. | Corporate relationship stages must remain separate from these operational/payment states. Do not let a sales stage or Telegram command mark a deposit paid or release production. |
| Square invoices | `class-doughboss-square-invoices.php` already creates/resumes/publishes deposit/balance invoices, uses deterministic idempotency keys and quote locking, validates commerce binding, and re-reads invoices before matching amount/currency/location and marking a leg paid. | Reuse this boundary. An invoice send is a provider/customer action. Existing source/tests do not prove the correct live merchant, webhook delivery, settlement, device behaviour or accounting reconciliation. |
| Existing money/time | Catering schema in `class-doughboss-activator.php` uses `decimal(10,2)` money; Square boundary converts to minor units. Existing rows use WordPress local time calls. | New approved financial snapshots should use integer cents and explicit currency/GST. Do not silently migrate historical money/time fields; document conversion and compatibility in a separate migration. |
| Privacy | `class-doughboss-privacy.php` already addresses catering records in export/erasure. | Every later CRM table must join the privacy/retention model; an enquiry is not blanket marketing consent. |
| Telegram | No Telegram integration was found in the searched `includes`, `public`, `admin`, `tests`, `scripts`, `web/src` and `web/app` paths. | Treat Telegram as a proposed adapter. Bot identity, approved chat/user IDs, hosting and existing external automation remain unverified. |
| Web app | `web/package.json` contains Next.js/Prisma scripts and dependencies alongside the WordPress plugin. | Its presence does not make it the live homepage or the CRM host. No migration to that stack and no new frontend runtime for this slice. |

## Architecture decision: extend the existing WordPress boundary

**Decision:** use a small management presentation/controller within the existing plugin, calling `DoughBoss_Catering` and later a focused CRM service. WordPress remains the identity and presentation boundary. Existing catering/order/payment services retain their responsibilities. No new production dependency is needed for the first slice.

**Benefits:** immediately useful against persisted enquiries; preserves checkout and manager workflows; one identity and one canonical enquiry; uses existing PHP/Node/WordPress test infrastructure.

**Costs:** the current broad manager permission is coarse; full CRM needs approved persistence and privacy controls; durable notification work requires operational design. These limits are explicit rather than hidden inside UI code.

**Alternatives considered:** embedding a foreign CRM app creates duplicate identity/data/integration ownership; rebuilding in `web/` duplicates the portal before its live role is established; a separate service adds deployment and synchronization overhead without a demonstrated current capacity need. Revisit service extraction only after measured load or independent operational requirements justify it.

```text
Existing homepage / catering form
  → existing nonce-gated enquiry endpoint
  → canonical Catering row / enquiry number
  → protected /management/ CRM view
  → established manager quote workflow
  → established Square invoice / payment verification boundary

Later approved additions:
  Catering ID ← account/contact/task/consent/audit records
  committed event → durable notification outbox → allowlisted Telegram chat
  Telegram command → authenticated, scoped command service → permitted CRM action
```

## Sprint 0 — read-only audit and acceptance baseline

1. Freeze the exact branch/revision and record dirty files without resetting or cleaning. Inventory active plugin/theme, homepage entrypoint, catering shortcode/client, enabled checkout gateway and migration/schema versions. Owner-provided production evidence or separately authorized read-only access is needed for deployed bytes; do not obtain or print secrets.
2. Trace synthetic enquiries locally: homepage entrypoint → existing REST handler → one canonical enquiry → manager view → existing quote/payment linkage. Verify mail failure does not cause duplicate lead creation on retries; assess actual request idempotency separately rather than assuming the creation hook provides it.
3. Record actual role assignments and legal-entity/store scope. Confirm who may see all enquiries. Inventory notification recipients and existing bot automations without sending test messages.
4. Map WordPress shop, Square merchant/location, invoice/order/payment references, environment, consent ownership and source confidence. Redact credentials and customer payloads. Historical Square gap/demo documents are audit prompts, not confirmed current faults.
5. Inspect local fixture availability, PHP executable/extensions, test scripts and baseline failures. Confirm the disposable WordPress installation contains this branch's plugin bytes before integration tests. Do not use a production database for fixture tests.
6. Deliver a source-of-truth matrix labelled `existing local`, `locally tested`, `owner reported`, `verified live`, or `proposed`, plus a narrowly approved first build packet. Missing live access does not prevent a local-only first slice; it prevents live claims and rollout.

## First implementation slice — manager catering CRM inbox

**Chosen slice:** a read-only CRM tab at `/management/?section=crm` listing existing catering enquiries. This is a useful lead visibility increment, not the completed account/contact CRM. It requires no new persisted fields, public endpoint, schema migration or provider action.

### User behaviour and interfaces

- Existing authorized managers can reach CRM from the current management navigation. Allowlist `section`; unknown values must not dynamically include files. Preserve the current overview.
- Show enquiry number, customer, existing status, requested event, guest count, location, created date, existing quoted amount and recorded payment-leg indicators. Labels must say recorded status; do not turn a local timestamp into a claim of fresh provider verification.
- Search and status filters reuse `DoughBoss_Catering::query`; validate status against `statuses`, bound search length and page size (20 default, 100 maximum). Escape all output. Use canonical enquiry ID/number; no inferred account merging by email domain or customer name.
- Provide a clear link into the established Catering management screen. Verify the actual accepted navigation parameters before adding a deep link. The new view has no quote, invoice, status, outreach or payment mutation controls.
- Render an honest empty state and storage-unavailable error. Do not create demo rows or report missing storage as zero business activity. Do not claim follow-up owner/due date/consent fields exist; those are slice 2.
- Keep SQL and provider calls out of templates: the controller invokes the existing model, returns a narrowly selected display model, and the template renders it. No Square or Telegram network requests on page render. Prefer server-rendered GET filters to a new API/client bundle for this one caller.
- Preserve capability checks for the page and any reused endpoint. Cookie-authenticated REST mutations elsewhere still require WordPress REST nonce plus capability checks; a public nonce never grants staff authority. Do not grant a new role during this slice.

### Smallest expected change set

| File | Responsibility |
|---|---|
| `includes/class-doughboss-portals.php` | Allowlisted management section and CRM navigation; existing manager gate; fetch through the existing catering model. |
| `admin/partials/management-crm.php` (proposed new file; confirm nearest established partial convention first) | Escaped, accessible server-rendered inbox. If an existing suitable partial exists, reuse it rather than adding this file. |
| Existing portal stylesheet, only if required | Minimal responsive table/filter styling; identify the actual asset loaded by `render_head` before editing. |
| Closest existing portal PHP/Node tests and `tests/integration/wordpress-mysql.php` as warranted | Behavioural access, filtering, existing-enquiry visibility and no-write regression checks. Extend existing tests; do not add test infrastructure. |

No changes to public capture, payment modules, activator, migrations, credentials, dependency manifests or `web/` are expected. If implementation needs any of these, stop and revise the exact build scope with the owner/Sol.

### Slice acceptance

1. An authorized manager sees the same synthetic canonical enquiry created through the existing catering route, with stable ID/number and correctly escaped fields.
2. Anonymous, ordinary customer and KDS-only users cannot load CRM data through HTML or reused endpoints. Existing broad manager access is documented; store-scoped sales access is not introduced.
3. Search, status, pagination, invalid filters, long input, empty/error states and keyboard navigation behave correctly; customer input cannot render executable markup.
4. Opening/filtering the view causes no database write, email, Square call or Telegram send. No copied enquiry, order, quote or payment record is created.
5. Current homepage/catering and quote/payment flows retain their contracts. Existing local payment tests remain green; this does not certify live Square behaviour.

## Later slices and explicit approval gates

| Slice | Deliverable | Gate / concrete interface |
|---|---|---|
| 2 — accounts and follow-up | Company with many contacts; manual relationship stage, owner, next action/due date, source, consent/suppression and audited changes linked to canonical catering enquiry IDs. | Approve new table/field/index design, capabilities, retention and migration/rollback before coding. Proposed narrow `DoughBoss_CRM` service owns validated reads/writes; portal templates never issue SQL. Reuse activator/migration/privacy conventions. No invented historical permissions or email-only backfill. |
| 3 — versioned commercial workflow | Immutable quote revisions and approval, costing reference, contract cadence and win/loss reasons; link the accepted revision to existing enquiry/payment legs. | Approve schema/API changes and price-approval roles. Keep current quote locks and payment preparation protections. Sales cannot alter paid amounts, approve refunds or bypass cost floors. Actual contribution requires sourced cost records, not a forecast label. |
| 4 — Telegram pilot | Minimal internal new-enquiry/overdue-task notifications, then bounded read-only commands. | Approve bot/chat ownership and external send test batch; approved durable delivery schema and scoped identity mapping. No automatic customer contact. See security contract below. |
| 5 — recurring/attribution and operational linkage | Scheduled account reviews, permitted reminders, lead-to-order attribution and repeat contribution; later production/cost/Xero exception views. | Approved consent proof, actual capacity and costing. Each recurring fulfilment uses existing canonical order/payment services. No cloned payment ledger, autonomous production release or accounting write-off. |
| 6 — controlled release | Performance/security checks, backup/restore rehearsal, feature rollback, operator training and one consolidated release candidate. | Local acceptance, independent review and explicit staged/live release approvals. Verify deployed bytes and live receipt separately. Never uninstall a legacy plugin as a rollback before inspecting data-dropping behaviour. |

For slice 2, prefer relational account/contact/task/consent/audit records with explicit location/entity scope and unique linkage keys; do not serialize an entire CRM into enquiry notes or WordPress options. Proposed records need finite field lengths, allowlisted enums, reference validation, actor/time, concurrency/version checks and transactionally consistent audit events. Exact DDL and REST contracts are intentionally not approved by this planning document. Provider identifiers should be references, not a second financial authority. Do not migrate old decimal amounts or overwrite past quotes implicitly.

## Telegram security and reliability contract

- Keep the bot token and webhook verification secret server-side, out of JavaScript, URLs displayed to users, source control and logs. Verify webhook transport using Telegram's configured secret-token mechanism; WordPress browser nonces are not webhook authentication.
- Allowlist numeric chat and user IDs, bind a Telegram user to an authorized WordPress identity, and re-check capability and store/entity scope for every command. A chat title, username, forwarded message or possession of a link is insufficient authority.
- Start notifications with enquiry reference, permitted location, event timing and a protected management link. Exclude phone/email, dietary/health notes, full addresses and financial detail by default. The link must still require ordinary authenticated access; never embed a login token.
- Initial commands may be `/help`, `/mytasks` and a bounded enquiry status lookup. Each result is scoped and privacy-minimized. Later `/assign` or `/followup` requires an explicitly approved mutation contract, validated inputs, deduplication and an audit event; no unrestricted natural-language execution.
- Never expose commands that charge, refund, mark paid, publish invoices, approve discounts, release production, modify security or start customer marketing. Telegram notifications and commands do not authorize payment or operational state.
- Persist a minimal outbox event after the source write commits, keyed by source event/entity/version and recipient. Deduplicate inbound `update_id` and command IDs. Retry transient failures with bounded exponential backoff and jitter; honour rate limits; dead-letter permanent failures and expose safe manual recovery. A Telegram outage must not roll back or duplicate the original enquiry.
- Telegram sends cannot be assumed exactly-once after a timeout. Record uncertain delivery separately and reconcile operator-visible receipts; do not blindly loop or claim delivery without a response. Deduplication prevents repeated local processing, not every external ambiguity.
- Use synthetic transport tests locally. Installing a bot webhook, sending a message or testing against a real chat is a separately approved external action. Browser-originated management mutations use nonce plus authenticated capability checks independently of the bot path.

## Validation commands and evidence limits

Run from the named worktree. Resolve existing executable and fixture paths; placeholders below are required environment inputs, not known installed locations. Do not install software or invoke remote CI to make these pass.

```powershell
& $PhpPath -l includes/class-doughboss-portals.php
& $PhpPath tests/run.php
node --test tests/frontend-upgrade.test.js tests/storefront-helpers.test.js
```

Lint every additional touched PHP file; run the exact existing portal test file selected during implementation. `tests/run.php` loads the existing `test-*.php` suite through WordPress shims, so a pass is not database/runtime proof.

For approved disposable WordPress/MySQL integration, after staging the canonical plugin bytes into that fixture:

```powershell
& ./scripts/test-wordpress-integration.ps1 -PhpPath $PhpPath -WpCliPath $WpCliPath -WordPressPath $DisposableWordPressPath
```

The integration script verifies installed plugin bytes against the worktree. Its fixture mutates test data; never point it at production. Confirm route rendering and access through the local WordPress runtime, including the existing enquiry flow and negative roles. If the fixture is unavailable, report the skipped layer and do not call runtime acceptance complete.

At the end of an approved completed release-candidate batch, run the established local gate once:

```powershell
& ./scripts/test-release.ps1 -PhpPath $PhpPath -ExtensionDir $ExtensionDir
git diff --check
git status --short
```

`test-release.ps1` lints PHP/JavaScript, runs local PHP/Node tests and builds/validates plugin and theme archives. It does not prove WordPress/MySQL behaviour, live provider binding or deployed success. Keep build outputs in their established ignored location. The `web/` typecheck/lint/test scripts become relevant only if an approved batch actually touches that application; do not invent a Next.js migration to satisfy this plan.

## External references and handoff

The separately owned `CRM-REFERENCE-SCOUT-20261030.md` evaluates VoltAgent/awesome-agent-skills, GitHub CRM topics, morluto/rea and trycompai/crm. Treat them as ideas pending pinned revision, licence, security and compatibility checks. A topic page is discovery, a skill catalogue is not a runtime dependency, and reverse-engineering tooling is not permission to inspect production. No external implementation is adopted by this plan.

**Recommendation to Sol:** accept Sprint 0 and the manager read-only inbox as the first bounded local build. Freeze its actual changed files and acceptance evidence before requesting broader CRM schema or Telegram activation. Return subsequent implementation to the standard Sol-led route; use a fresh bounded assurance review when source changes warrant it. Preserve the distinction between planned, locally implemented, locally tested, staged, deployed and verified live throughout the handoff.
