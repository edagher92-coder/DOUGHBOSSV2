# DoughBoss full architecture review

**Date:** 14 July 2026

**Review branch:** `codex/doughboss-architecture-review`

**Reviewed branch:** `claude/doughboss-website-design-fixes-li6dqa` through `df2f606`
**Decision:** conditional approval for continued development; **not approved for a public money-taking launch**.

## Executive decision

The WordPress plugin should remain the system of record and be evolved as a modular monolith. A platform rewrite would add risk without solving the current launch blockers. The correct next move is to harden the existing transaction boundaries, introduce an explicit order-capacity model, and isolate external providers behind adapters.

The current build is broad and visually credible, but several documents overstate production readiness. In particular, the supplied `doughbosssignoff20260714.md` is superseded by this review. Single-location launch behaviour, POSPal retry safety, payment idempotency, voucher authority, legal content and lifecycle capacity all need stronger evidence before sign-off.

This branch implements the low-risk corrections that can be verified without live credentials. It intentionally does not simulate a successful payment, POSPal, Uber Eats or notification test.

## Sources reviewed

- The complete `DOUGHBOSSV2` repository: PHP, JavaScript, CSS, demo, staff console, CI, scripts, manuals, reports, PDFs, DOCX files and XLSX menu template.
- The sibling `DOUGHXSNOW` repository and the restricted `10 - DoughBoss` archive.
- `C:\Users\edagh\Downloads\doughbosssignoff20260714.md`.
- `C:\Users\edagh\Downloads\orderlifecycledesign.html`.
- `C:\Users\edagh\OneDrive\Documents\doughbossv2.docx`, SHA-256 `CFCC575EF8CE3BBA1B15BE7CF935347057BD7A3F4A53D085B6A276867B400930`, including all seven embedded images.
- The linked OneDrive preview, used only until the local DOCX was supplied.
- Current primary documentation for WordPress, PHP, WCAG, PCI, Stripe, Tyro, Uber Eats, POSPal, Australian privacy and franchising obligations.

## Current architecture

The core is a dependency-light WordPress modular monolith:

1. WordPress REST endpoints serve storefront, staff and integration clients.
2. Custom tables store orders, items, locations, catering, vouchers, voucher redemptions and the POSPal outbox.
3. Stripe is directly coupled to checkout, catering, webhooks and refunds.
4. POSPal is a downstream mirror for orders and a second voucher authority.
5. The kitchen board and standalone console poll WordPress, with optional Mercure updates.
6. Email, SMS, ntfy, printer, Instagram and POS integrations are side effects around the order record.

This shape is suitable for the present business. The main weakness is not the monolith; it is that payment, order creation, vouchers and external side effects do not yet share one durable transaction model.

## Corrections implemented in this branch

### Launch scope and routing

- Single-location mode is effective only when exactly one active shop exists.
- The settings screen explains and enforces the single-location rule.
- Effective single-location mode forces pickup and hides delivery from the storefront.
- The storefront is pinned to the sole active shop.
- A multi-shop checkout can no longer silently fall back to the first database row; an explicit active location is required.
- Location-level pickup/delivery flags are now enforced before payment and checkout.
- PaymentIntent metadata now binds the payment to fulfilment type and location, and checkout verifies that binding.

These changes support the current owner direction: **pickup only from Revesby for the initial release**. Production configuration still needs to prove that Revesby is the sole active shop and that its pickup flag is enabled.

### Staff console and accessibility

- Application Password credentials are held in memory only and old stored credentials are scrubbed from browser storage.
- Non-local console connections require HTTPS.
- Console labels are programmatically connected to fields.
- Mobile zoom is no longer blocked and portrait orientation is no longer forced.
- The version-pinned QR scanner now has Subresource Integrity protection.
- Staff order-board and admin status calls now reject non-success HTTP responses instead of displaying false success.
- The optional Order Board access key remains layered on top of WordPress login and the kitchen capability for KDS-only staff accounts. It is generated from a URL-safe alphabet, stored only as a verifier, revealed once, and now enforced on the underlying order read/write REST routes as well as the HTML page. The staff console forwards the same key from its protected `?key=` URL. Owner/manager accounts retain their broader authenticated wp-admin access.
- The static staff console still needs hosting-level access control before its URL is shared. Its WordPress data calls are authenticated, but the HTML/JavaScript shell cannot be protected by plugin PHP.

An obscure URL is not authentication. The supported control is a dedicated WordPress staff account with the minimum role, an Application Password, HTTPS, rate limits and server-side capability checks. The optional key is a genuine additional KDS data gate, but treat its URL as a secret because it may appear in browser history and access logs, rotate it when a device or bookmark is exposed, and never use it without the underlying WordPress gate.

### POSPal and operational safety

- POSPal pickup orders now use the documented `deliveryType = 2`; delivery remains `0`.
- Retry scheduling now derives the next wake-up from the entire pending outbox, preventing future retries from being stranded.
- Unsafe reconciliation that queried with the wrong identifier and could re-push a successful order has been removed.
- Ambiguous network and abandoned in-flight outcomes now stop for operator review instead of automatically re-pushing a possibly successful till order.
- The maintenance job explicitly reports that positive reconciliation is unavailable until the returned POSPal `orderNo` is persisted.
- Deactivation clears POSPal cron jobs; uninstall also removes the outbox table, cron jobs and both custom roles.

### Release hygiene

- Plugin header, constant, readme and changelog versions are checked for consistency.
- PHP CI coverage now extends through 8.5. WordPress metadata remains at the previously tested 6.5 level until a real WordPress/MySQL compatibility suite proves a higher value.
- CI includes PHP 8.5 while retaining the declared PHP 7.4 floor.
- Shell scripts are normalised to LF on checkout.
- Snow-era voucher examples were changed to `DOUGH-`.

## P0 launch blockers

### 1. Atomic checkout and payment recovery

Current checkout idempotency is a transient cache and is not an atomic database claim. Two concurrent requests can pass the check, and a successful card payment can exist without an order if the browser disappears or order creation fails.

Required acceptance criteria:

- A unique, durable checkout-attempt key is claimed in the database before provider work.
- A payment attempt records provider, provider reference, amount, currency, location, cart hash and state.
- Concurrent requests with the same key return one order.
- Webhook reconciliation can link an orphaned payment to its immutable checkout snapshot or place it in a human review queue.
- Refund/void is idempotent and auditable.
- Tests cover duplicate requests, timeout after provider success, webhook-before-browser, browser-before-webhook and refund retry.

### 2. One voucher authority

WordPress and POSPal currently behave as independent voucher ledgers. A code can be consumed on one side while remaining spendable on the other, and revocation before order commit can leave a valid customer order with a used till voucher.

Choose one model:

- **Recommended:** WordPress is authoritative; POSPal receives a non-value mirror/redemption instruction through a durable outbox.
- Alternative: POSPal is authoritative; WordPress performs online holds/redemptions against POSPal and does not maintain an independent spendable balance.

Do not launch a voucher usable both online and at the till until concurrent online/till redemption and rollback tests pass.

### 3. POSPal remote idempotency and reconciliation

The local outbox prevents duplicate local rows, but it cannot determine whether an ambiguous network timeout was accepted remotely. Dispatch must persist the response's stable `orderNo` and reconcile only by a documented positive identifier. Empty or unfamiliar responses must never trigger a re-push.

Voucher code format must also be confirmed: current hyphenated codes conflict with POSPal's documented alphanumeric expectation. Use a stable alias mapping rather than mutating issued customer codes in place.

### 4. Capacity-backed order scheduling

The supplied lifecycle memo's `max(item prep time)` formula assumes unlimited parallel work. That is unsafe for one oven and one or two staff. A 30-minute slot with a 10–12 item count alone cannot protect oven or preparation capacity.

Implement the separate lifecycle design in [DoughBoss-Order-Lifecycle-ADR.md](DoughBoss-Order-Lifecycle-ADR.md). No timer may automatically mark an order ready.

### 5. Database migration locking

The current migration lock is not a safe multi-request mutual exclusion mechanism. Replace it with a database advisory lock or a transactional/atomic option claim with owner token and expiry. Run migrations against a real WordPress/MySQL service in CI, including two simulated concurrent upgrade requests.

### 6. Legal, privacy and licensing release gate

- Public demo privacy/terms pages contain extensive placeholders and Snow Boss/multi-location/delivery statements that conflict with the current Revesby pickup-only scope.
- Legal text requires Australian solicitor/privacy review before publication.
- The plugin contains GPL-governed WordPress code while a separate commercial agreement asserts restrictions that may conflict with GPL redistribution rights. Obtain legal review and separate software licence terms from brand/content/service terms.
- The POSPal outbox stores customer payload data. Add it to the privacy exporter/eraser/retention policy and minimise or encrypt retained PII.
- Never publish the restricted archive or WXR exports; they contain operational and personal data.

Relevant current sources include the [OAIC Australian Privacy Principles](https://www.oaic.gov.au/privacy/australian-privacy-principles), [current Australian Franchising Code](https://www.legislation.gov.au/Series/F2024L01605), [ACCC guidance](https://www.accc.gov.au/business/industry-codes/franchising-code-of-conduct/guidance-on-changes-to-the-franchising-code) and [GNU GPL FAQ](https://www.gnu.org/licenses/gpl-faq.en.html).

## Target order and kitchen architecture

Persist business facts; calculate time warnings.

- Persisted fulfilment states: `scheduled`, `cooking`, `ready`, `completed`, with `cancelled` as an exception.
- Derived kitchen projections: `queued`, `fire_due`, `late`, `stale_ready`.
- Customer projection: `confirmed`, `preparing`, `ready_for_pickup`, `collected`, `cancelled`.
- Payment state is separate: `requires_payment`, `authorising`, `paid`, `voided`, `part_refunded`, `refunded`, `failed`, `review`.

`fire_due`, `late` and `stale_ready` must never be persisted as order truth. They are calculated from the promised window, capacity allocation and current time. Staff can acknowledge, start cooking, mark ready, complete, cancel or invoke a reason-coded manager override. Only staff can mark food ready.

For paid, capacity-valid online orders, the system should confirm immediately. The staff action is **Acknowledge**, not Accept. If the business insists on manual acceptance, card authorisation and capture must be separated so the customer is not charged for a rejected order.

Customer notifications:

1. Immediate confirmation with pickup window and order number.
2. Optional preparing update when cooking begins or the promise changes materially.
3. Ready notification when staff explicitly marks ready.
4. Cancellation/refund notification when applicable.

Every notification is an outbox event with a unique event key. Delivery failure does not roll back the order state.

## Capacity model

Each menu item needs preparation and oven demand, not only elapsed minutes:

- preparation seconds;
- oven seconds;
- oven units or tray footprint;
- preparation station/resource;
- batch group and batch size;
- optional finishing/packing seconds;
- modifier deltas, such as gluten-free handling.

Availability is calculated against location timezone, opening hours, blackouts, existing allocations, held checkout capacity and staff/oven resource calendars. A slot is reserved by an expiring capacity hold before payment begins, then atomically converted to an allocation when payment succeeds.

Recommended customer promise is a 15-minute **ready from X, collect by Y** window. If 30-minute slots are retained, show the same ready/collect window inside the selected slot so food is not prepared at the start of a broad window and left to cool.

## Payment provider boundary

The latest instruction leaves online payment open to **Tyro or Stripe**. The local DOCX separately says payments should go through **PayPal as integrated through Roselands**. These are conflicting requirements, so this branch does not silently select a provider.

Introduce a provider-neutral `PaymentGateway` contract with:

- create/refresh an authorisation or payment;
- retrieve provider state;
- capture, void and refund;
- verify and normalise webhooks;
- declare browser/client configuration;
- expose provider capabilities without leaking provider-specific states into orders.

Persist `payment_attempts` and map provider states to the DoughBoss payment states above. Stripe's PaymentIntent remains a valid first adapter. A Tyro adapter should follow Tyro's hosted/SDK flow and webhook statuses. PayPal, if confirmed, becomes a third adapter; “through Roselands” must be clarified because a merchant account must not accidentally attribute Revesby sales to the wrong legal entity/location.

Official implementation references: [Stripe PaymentIntents](https://docs.stripe.com/payments/payment-intents), [Tyro online payment flow](https://docs.connect.tyro.com/app/apis/pay/accept-an-online-payment), [Tyro statuses](https://docs.connect.tyro.com/app/apis/pay/pay-request-statuses), [Tyro events](https://docs.connect.tyro.com/app/apis/pay/events/) and [Tyro refunds/voids](https://docs.connect.tyro.com/app/apis/pay/refund-and-void).

## Menu and checkout redesign

The Domino's image in the DOCX is a reference for a structured product configurator, not permission to copy protected visual design or content. Build a DoughBoss-native accessible modifier flow:

1. Item summary and price.
2. Required base choice: normal, wholemeal or gluten-free.
3. Included ingredients with remove/restore controls.
4. Optional extras grouped by type and priced server-side.
5. Quantity and live total.
6. Add-to-order confirmation with a concise modifier summary.

Owner-supplied prices conflict with the current screenshots: the DOCX text says gluten-free is a $4 surcharge and mixed zaatar/cheese is $0.50, while an embedded screenshot shows wholemeal +$2.50 and gluten-free +$3.50. Confirm the canonical price list before migration.

Lemon and chilli should be explicit required choices for relevant pizza/manoush items: `Add lemon and chilli`, `No lemon/chilli`, with allergen/ingredient language reviewed. Never implement them as a global checkout question for unrelated items.

All prices and allowed combinations remain server-owned. The client sends modifier identifiers, not trusted price deltas.

## Staff access redesign

- Use separate named staff accounts; no shared credential printed in HTML or documentation.
- Give kitchen staff only KDS/order capabilities and scope them to one location.
- Use Application Passwords for the standalone console and normal WordPress login/nonces for same-origin admin/KDS.
- Add failed-login throttling, session expiry, device revocation and an audit log for status/override/refund actions.
- Remove the third-party scanner dependency by vendoring it in a later release; SRI protection in this branch is an interim control.
- Replace broad polling rerenders with incremental updates so screen-reader focus and staff input are preserved.
- Do not rely on a special URL for access control.

## Uber Eats

The owner supplied an active Revesby Uber Eats store link. Treat Uber Eats as a separate order channel feeding the same internal order model, not as an iframe or scraped page. Use Uber's authorised integration flow, preserve the external order identifier, and map store/menu/order statuses explicitly. Activation requires Uber approval/credentials and a sandbox/certification plan; possession of a public store URL is not API authorisation.

Official references: [Uber Eats authentication](https://developer.uber.com/docs/eats/guides/authentication) and [integration activation](https://developer.uber.com/docs/eats/references/api/integration_activation_suite).

## Brand and Higgsfield workstream

The local DOCX is authoritative for the creative direction:

- Correct the DoughBoss logo using the supplied distressed `DOUGH BOSS.` / `LEBANESE BAKERY` reference.
- Create a restrained 3D hero rendition, then derive the full brand system from a clean master logo rather than using an AI raster as the legal master.
- Use existing approved WordPress food photography first.
- Generate only missing menu images, beginning with the manoush range.
- Create a lightweight animated kitchen scene featuring an older traditional Middle Eastern woman rolling and filling dough; provide still/WebP fallback and reduced-motion behaviour.

The complete brief and acceptance rules are in [DoughBoss-Higgsfield-Brand-Asset-Brief.md](DoughBoss-Higgsfield-Brand-Asset-Brief.md). No generated asset should be merged until the owner confirms likeness, cultural treatment, food accuracy, usage rights and menu-item identity.

## Snow Boss, pages and repository consolidation

Current owner direction is clear: hide Snow Boss for now, rename the public page to **Offers & News**, use DoughBoss Instagram only, and issue `DOUGH-` vouchers. Existing public legal/demo pages and older reports still contradict this.

Recommended action:

- Keep Snow Boss code behind a disabled feature flag temporarily for rollback.
- Remove Snow Boss from public navigation, sitemap, legal claims, voucher copy and deployment artefacts.
- After one stable release, delete obsolete demo-only Snow Boss assets instead of maintaining a hidden parallel brand.
- Rename the customer “Locations” navigation item to “Contact Us”; retain `location` terminology in the data model and admin.
- Treat `DOUGHXSNOW` as an archive/print-tool source, not a second commerce ledger. Extract any still-used label/QR tooling into one clearly owned utility, then archive the repository read-only.
- Remove obsolete Gemini instructions and reconcile its migration README with the migrations CI actually runs.

## Remove or replace

| Current element | Decision |
|---|---|
| Static sign-off claiming production readiness | Replace with evidence-based release checklist below |
| Arbitrary status dropdown transitions | Replace with a server transition command and reason-coded manager override |
| `max(prep time)` slot calculation | Replace with resource/capacity allocation |
| Automatic “ready” or timer-driven truth | Prohibited; ready requires staff action |
| Two independent voucher ledgers | Replace with one authority and one durable mirror |
| Transient-only checkout idempotency | Replace with unique database checkout attempts |
| Direct provider states on orders | Replace with normalised payment attempts |
| Hidden/special URL as security | Replace with authenticated, scoped staff access |
| Public Snow Boss and placeholder legal pages | Remove from deployment until approved |
| CDN scanner without integrity | SRI added now; vendor locally next |
| Separate DOUGHXSNOW commerce source of truth | Archive after extracting owned utility |

## Delivery sequence

### Stage 0 — merge this hardening branch

- Verify all automated checks.
- Review changes against a staging database copy.
- Confirm sole active location is Revesby, pickup enabled, delivery disabled.
- Do not enable public payment or POSPal order push yet.

### Stage 1 — lifecycle foundation

- Add immutable checkout snapshots, capacity holds, allocations, order events, notification outbox and payment attempts.
- Add a guarded transition service and version/compare-and-set updates.
- Wrap existing Stripe behaviour behind the payment interface without changing customer behaviour.
- Add real WordPress/MySQL integration tests.

### Stage 2 — customer scheduling and modifiers

- Configure hours, timezone, blackouts, prep/oven resources, modifier price catalogue and policies.
- Add the slot availability API and expiring hold.
- Add accessible item configurator and ready/collect promise.
- Load-test concurrent holds and large orders.

### Stage 3 — staff kitchen workflow

- Incremental KDS with location-scoped accounts, acknowledge/start/ready/complete controls, derived warnings and auditable overrides.
- Notification outbox with email first; optional SMS after consent/template approval.
- No automatic ready transition.

### Stage 4 — provider and POS integrations

- Complete Stripe/Tyro/PayPal owner decision and implement the selected adapter.
- Persist POSPal remote identifiers and prove reconciliation/idempotency.
- Select the voucher authority and run concurrent till/online tests.
- Begin Uber Eats partner activation and certification.

### Stage 5 — public launch

- Approved brand assets and optimised menu media.
- Approved Australian legal/privacy copy with no placeholders.
- Disaster recovery drill, monitoring, staff training and rollback test.
- Revesby soft launch with real supervised orders before any other location.

## Required owner decisions

1. Payment provider and merchant account: Stripe, Tyro, or PayPal; and which legal entity/location owns settlement.
2. Whether a paid, capacity-valid order auto-confirms (recommended) or requires manual acceptance.
3. Exact Revesby trading hours, timezone exceptions and blackout dates.
4. Oven capacity, tray/oven units, staffing bands and per-item preparation/oven data.
5. Slot size (15 minutes recommended) or the exact ready/collect promise within a 30-minute window.
6. Capacity hold duration, late arrival/no-show, cancellation, refund, large-order and manager-override policies.
7. Canonical menu modifier prices and which products require lemon/chilli confirmation.
8. Customer notification channels and the verified sender/domain/phone.
9. Whether “Doughboys only” in the DOCX means **DoughBoss only**.
10. Who owns the Uber Eats partner/API relationship.
11. Approved logo master, menu-photo rights and Higgsfield generation account/workflow.
12. Full approved public contact details. The DOCX contains only an incomplete `04`; verify the existing Revesby landline, email, address and trading hours before launch.

## Release evidence checklist

- [ ] Clean automated static/smoke checks on all supported PHP versions.
- [ ] Real WordPress/MySQL migration test, including concurrent upgrade.
- [ ] Two concurrent checkout requests produce one order and one payment.
- [ ] Provider success followed by browser loss is reconciled.
- [ ] Refund/void retry is idempotent.
- [ ] Multi-location request without a shop is rejected; Revesby pickup path succeeds.
- [ ] POSPal ambiguous timeout does not create a duplicate till order.
- [ ] POSPal pickup type and stable order identifier verified in sandbox/test environment.
- [ ] Online/till concurrent voucher redemption cannot double-spend.
- [ ] Capacity holds cannot oversell an oven/staff interval.
- [ ] Timers never mark an order ready.
- [ ] Screen-reader, keyboard, zoom, reduced-motion and mobile tests pass against [WCAG 2.2](https://www.w3.org/TR/WCAG22/).
- [ ] PCI scope is documented for the selected hosted payment UI; see [PCI SSC SAQ A guidance](https://www.pcisecuritystandards.org/faq/articles/Frequently_Asked_Question/How-is-the-payment-page-determined-for-SAQ-A-merchants-using-iframe/).
- [ ] Privacy export/erase/retention covers outbox and new lifecycle tables.
- [ ] Legal pages have no placeholders and are professionally approved.
- [ ] Restore, rollback, cron runner and notification failure drills pass.
- [ ] Revesby supervised soft launch is signed off by an identified owner.

## Final recommendation

Merge the verified hardening changes as a draft/staging release, then execute the lifecycle work as a separate staged programme. Do not add another commerce backend. Keep WordPress authoritative, model capacity and payment attempts durably, integrate providers through adapters, and launch Revesby pickup-only only after the P0 evidence checklist passes.
