# DoughBoss V2 management portal and CRM - technical implementation design
Status: specification for development; no live site or provider touched. Before coding audit deployed bytes and existing branch. Reference the master plan, catering research, production specification, issue #77.

## Reuse and specific repository findings
Existing code includes includes/class-doughboss-portals.php, catering class, kitchen order board, protected management dashboard, staff guide and Square integration components. This is not greenfield ERP. Older design audit web/docs/wp/02-orders-square-kitchen-map.md reported global Square location mapping, payments-only settlement vs itemised provider Orders, thin refund handling, broken catering deposit path, unpersisted marketing attribution and mixed real/demo reports. Other docs/SQUARE-ORDERS-LINKAGE-PACKET-20261002.md designs a candidate order linkage; docs/SQUARE-DASHBOARD-DEMO-20260930.md specifically marks Square sales demonstration data. These are historical findings, not confirmed current faults: **audit current plugin and production before deciding scope**. web/docs/wp/03-staff-dev-gaps.md covers staff clock breaks and manual corrections and says older records are not payroll-ready. Existing owner policy prefers Square for staffing; never replace legacy records until verified migrated.

## Architecture boundaries
WordPress V2: existing public checkout/portal, tightly permissioned interfaces. Square: source of externally verified paid transaction and possible catalog/loyalty only WHEN enabled; per-location mapping mandatory. Xero: ledger, invoices/settlements, not a raw mirror of each POS event. New additive ops module: B2B company CRM and quotes, BOM/lot, production planning, purchase and supplier variances, dispatch, contribution reporting and acquisitions. Snowflow: separate commercial entity inventory/equipment/vehicle contract integration; no automatic pooling of unrelated customers or payment data. Choose WP companion module vs backend service by code audit, DB scale, hosting and rollback strategy. No competing checkout/payroll implementation.

## Portal views
1 Command Centre: role and location scoped paid orders, fulfilment, kitchen capacity, exception inbox, 13-week cash and quality.
2 Catering CRM: companies, people and lawful contact/opt-in, lead owner, stages, quote versions, recurring schedule, tasks, profit guard.
3 Pricing and SKU: recipe/BOM versions, allergens, true affiliated input costs, production labour, overhead allocation, margin ex GST.
4 Kitchen: receiving and batches, shortage prediction, line capacity, expiry, temperatures, manifests and recall.
5 Stores/staff: store/channel contribution, revenue vs sales capacity, staffing forecast and actuals, training, waste; keep attendance systems isolated until reconciled.
6 Suppliers/assets: vendor scorecard, authorised PO, invoice variance, equipment maintenance.
7 Marketing: opt-in, UTMs, lead-to-paid order conversion, CAC, repeat purchase contribution.
8 Acquisitions/capital: site dossiers, lease/equipment checklists, funding/cash downside, dual approval.
9 Finance/compliance: Square vs WordPress vs Xero variance, refunds/fees, food safety incidents, audit trail and investor read-only exports.

## Data model
IDs: legal entity, store, Square merchant/location, WordPress order, Square Order/Payment, Xero reference, CRM organisation/contact/consent, quote version, contract, SKU/BOM version, ingredient, lot, production batch, transfer, delivery, staff role and immutable audit event. Store all money as integer cents plus GST split and exchange/currency if ever required; cost source and confidence. All changes to quote, price, food safety and finance must record actor/before/after/time/justification. Local time zones with UTC event storage. Role-scoped query enforcement (not solely front-end filtering) and MFA for privileged actions. Investor portal aggregated, approved read-only and segmented by legal ownership.

## Order and accounting state machine
Enquiry -> qualified -> approved quote -> customer accepted -> payment/deposit verified -> production release -> lot allocation -> dispatch -> proof receipt -> financial reconciliation -> repeat follow-up.
Missing/ambiguous/pending Square payment must enter HELD state and never release food; idempotency of event_id, signed webhook validation, queue/dead-letter and safe manual recovery. Square webhooks may duplicate and arrive out of order, confirmed in official docs: https://developer.squareup.com/docs/webhooks/overview and https://developer.squareup.com/docs/webhooks/step3validate .
Financial proof checks against independently confirmed location+merchant, exact local immutable cents, GST, itemised Order linkage, refund status, payout batch less fees and Xero clearing accounts. Any mismatch -> exception; no automatic settlement write-off. Clearly distinguish DEMO, ESTIMATE, VERIFIED ACTUAL in dashboard.

## Build sprints
P0 1-2 weeks: read-only current code/live configuration audit; inventory routes, version and active Square/Xero modes; permission and test map; architecture decision; risk register; owner sign-off. Do not install/uninstall plugin to investigate.
P1 2 weeks: BOM/cost calculator and internal transfers; approved recipe version with allergen, observed unit production costs.
P1 2 weeks: B2B lead/quote CRM, quote floor and versioning; consent evidence, follow-up tasks; integration with existing catering path.
P1 2 weeks: batch planning/lot/pack/dispatch and proof of receipt, exception blocker and mock recall.
P1 2 weeks: management contribution dashboard with explicit source mapping, store scope and Xero exception reconciliation.
P2 2 weeks: consented campaign attribution, cohort profitability and contract renewal alerts.
P2 2 weeks: store acquisition dossier, workforce and supplier dashboards, QA and performance.
P3: test secure release candidate and owner-controlled deployment. Sequence may parallelize non-money UI only where dependencies permit.

## Acceptance / tests
Existing management portal reused; no parallel portal or duplicative order store; no cross-entity data; no client-supplied pricing; exact cents and audit records; every catering order can trace lot -> store -> actual settlement; duplicate/out-of-order/invalid signed Square webhook rejected or safely deduped; signed role/permissions; privacy delete/export request tested; kitchen exception blocks shipment; Xero mismatch flagged, not balanced artificially; staged restore + feature rollback; no old plugin uninstallation because uninstaller may drop tables. Verify release scripts, PHP/JS tests, WordPress/MySQL integration and backup before any release. No real refunds, purchases, outreach or payment activation without explicit approvals.

## Open evidence and handoff
Need four leases/assets and financing, live plugin/version and merchant IDs (secrets not posted), actual food costs/yields, 30 days order volume, workforce award classification, Square/Xero ownership/accounting policy, approved marketing budget and contact permissions. Dev issues must link this document, include tests and human approval gates. No live code implemented in this document.