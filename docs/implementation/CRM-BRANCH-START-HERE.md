# CRM branch — DoughBoss six-year programme: engineering launch brief
**Branch:** `crm` | **Date:** 2026-10-30 | **Status:** implementation backlog prepared, zero live deployments.
**Read first:** `docs/strategy/DOUGHBOSS_2026_2032_MASTER_PLAN.md` plus the three 2026-10-30 workstream specs. Parent issue [#77](https://github.com/edagher92-coder/DOUGHBOSSV2/issues/77), children #78 (growth), #79 (kitchen/cost), #80 (portal/finance).

## Required outcome
Turn existing DoughBoss V2 into a scalable **central-production + local fulfilment + recurring corporate catering** operator. Reuse the existing WordPress checkout, `DoughBoss_Catering` enquiry/quote model, `DoughBoss_B2B` wholesale enquiry, kitchen/order board, protected `/management/` portal and Square component; build additive CRM, approved contract/quote costing, kitchen BOM/lot/dispatch and finance KPI modules. Integrate Snowflow savoury/sweet packages (slushies, soft serve, fairy floss, popcorn) with real event service costs.

## Product-owner facts (not externally verified)
Elie: A$50k equity; Charlie: A$100k; additional investors with terms to confirm. Four acquisitions shortlisted from ~50 (two Sydney/two Melbourne) around A$150k–200k per store, considerable equipment already installed; user estimates A$400k–500k avoided fresh build cost per site (NOT operating profit). Revesby central kitchen already operating with four cooks, near throughput ceiling; affiliated butcher supplies meat at cost, dough/bread produced internally, Snowflow supplies products/equipment at cost; second shared-warehouse kitchen is contemplated as overflow/backup pending licensing. Darlinghurst proposed as CBD corporate catering hub.

## Verification notes from THIS branch's code
- `includes/class-doughboss-catering.php` stores catering enquiries, quote and deposit lifecycle.
- `includes/class-doughboss-b2b.php` currently validates and emails wholesale/franchise enquiries; it says those are not stored in DB.
- `includes/class-doughboss-portals.php` already handles protected `/management/`, `/kitchen/`, and `/catering-kitchen/` routes; admin Catering and Reports links exist.
- `includes/class-doughboss.php` loads the above plus Square modules. Plugin reports version 2.51.1 on baseline branch.
- Historic `web/docs/wp/02-orders-square-kitchen-map.md` and `docs/SQUARE-ORDERS-LINKAGE-PACKET-20261002.md` describe integration risks and candidate work; they are NOT proof of currently unshipped features. Verify current code/tests AND live deployed version first. `docs/SQUARE-DASHBOARD-DEMO-20260930.md` marks its dashboard as DEMO.
**Do not implement a competing payment engine, catering enquiry table, or replacement management portal.**

## Three implementation tracks; sprint gates
### Track 1 — CRM and corporate sales (Elie; issue #78)
P0: Map current catering entities, admin screens, access roles and consent. P1: design corporate accounts, contacts, consent provenance, lead source, deal stages, quote versions, follow-up tasks and recurring contracts linked to existing catering enquiries and verified real orders. Add manager-only navigation under current /management/, not public menu. P2: structured product bundles with cost floors, quote-deposit state, calendar/capacity gating, owned-channel conversion analytics. Test forbidden actions: sales role can't approve refunds/override pricing; denied consent never triggers email; duplicate webhooks don't create duplicate fulfilment.
Acceptance: create/retrieve/update lead with audit; company can have many contacts; quote version and approvals immutable; every contract order has actual contribution after labour/transport; no retroactive historical price rewrite.

### Track 2 — Revesby production and savings (Charlie/area manager; issue #79)
P0: collect recipe/BOM, actual throughput, cold storage, employee/vehicle and affiliated supplier transfer costs. P1: approve SKU ingredients, allergens and fully-loaded recipe cost; batch plan/order allocation, receiving lots, pack/dispatch trace, exceptions. P2: forecast ingredient demand and replenishment, labour minutes/100 units, waste, electricity/unit and cost/drop.
Acceptance: trace source lot to every dispatched SKU and destination; invalid storage temperature holds affected batch; estimate never labelled verified actual; each store's cost includes central prep and delivery. Melbourne separate supplier validation.

### Track 3 — protected portal, finance and reporting (Elie/technical lead; issue #80)
P0: audit Square payment/Orders/location mapping, legacy POSPal, catering deposit/refund, settlement reconciliation, report data origins and staff-clock vs Square personnel status. P1: management dashboards combining actual **verified** sales and approved costs with consented CRM; weekly 13-week cash forecast and acquisition gates. P2: read-only Square/Xero variance queue with explicit merchant fee, deposit/payout batching and refunds.
Acceptance: no cross-store data access; mismatch stops automatic accounting; demo vs actual clearly separated; no changes to payments or payroll without a separate, reviewed release.

## Dev workflow — first commit and subsequent sprints
1. Snapshot precise deployed WP/theme/DB schema in read-only audit; do not request secrets in issues.
2. Run `scripts/test-release.ps1` with supported PHP/Node and staging MySQL tests; capture baseline failures before writing code.
3. Implement isolated slice in branch `crm` or narrower feature branches based on `crm`. Record architecture decision for companion module vs existing plugin extension before migrations.
4. For every PR: test normal/negative permission, validation, webhook duplicates, timezone, accounting cents, backup/restore and rollback. Avoid mass version bump / live checkout rewrite.
5. Review with Elie+Charlie: product, food safety and financial boundaries. Only approved, tested commits can be promoted to production by authorised humans.

## Source documents on the crm branch
- [6-year master strategy](../strategy/DOUGHBOSS_2026_2032_MASTER_PLAN.md)
- [Corporate catering research and marketing](../strategy/2026-10-30-CATERING-GROWTH-IMPLEMENTATION.md)
- [A–Z kitchen, labour and supplier economics](../strategy/2026-10-30-PRODUCTION-AND-OVERHEAD-SPEC.md)
- [V2 management portal / CRM architecture](../strategy/2026-10-30-V2-MANAGEMENT-PORTAL-ENGINEERING.md)

**No production write, live Square/Xero action, customer campaign, store acquisition or investor transfer is authorized by this branch.**