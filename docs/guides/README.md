# DoughBoss pre-final how-to guide pack

Status: **rough pre-final rendition — payment integration pending**

Verified against: plugin `2.20.0`, database schema `1.13.0`

Last verified: 21 July 2026

This pack converts the existing technical manuals, walkthrough material and current implementation into audience-specific instructions. It is intentionally reviewable Markdown so screenshots, final provider details and business decisions can be added without rebuilding a PDF.

## Guides

1. [Technical operations](DoughBoss-Technical-Operations-Guide-PreFinal.md) — installation, migration, configuration, KDS, capacity, POSPal, monitoring and recovery.
2. [Customer](DoughBoss-Customer-Guide-PreFinal.md) — ordering, vouchers, checkout and tracking.
3. [Kitchen and counter staff](DoughBoss-Kitchen-Counter-Guide-PreFinal.md) — accepting, preparing and completing orders, sound alerts and voucher scanning.
4. [Owner and manager](DoughBoss-Owner-Manager-Guide-PreFinal.md) — access, configuration, reports, vouchers, staff roles and incident checks.

## Status vocabulary

- **WARNING** — risk of data loss, duplicate handling, incorrect fulfilment or financial impact.
- **CAUTION** — action needs care or manager confirmation.
- **NOTE** — useful operating context.
- **TO BE CONFIRMED** — information that is not yet verified or depends on the final WordPress/provider configuration.
- **PAYMENT INTEGRATION PENDING** — code foundations exist, but neither Tyro nor Stripe should be described as live until the sandbox and staging gates pass.

## Visual placeholder convention

- `[SCREENSHOT: description]`
- `[PHOTO: description]`
- `[DIAGRAM: description]`
- `[GRAPH: description]`
- `[ANIMATION NOTE: description]`

Each placeholder should eventually receive a caption and alternative text. Existing candidates are in `docs/walkthrough/`.

## Principal source set

- `docs/DoughBoss-Manual-User.md`
- `docs/DoughBoss-Manual-Admin.md`
- `docs/DoughBoss-Manual-Deployment.md`
- `docs/DoughBoss-Manual-Maintenance.md`
- `docs/DoughBoss-Order-Lifecycle-ADR.md`
- `docs/DoughBoss-Phase-2-Staging-Rehearsal-Runbook.md`
- `docs/DoughBoss-Phase-3-Capacity-Foundation-2026-07-15.md`
- `docs/POSPAL-Voucher-Bridge-and-Redemption.md`
- current plugin, storefront, KDS, migration and integration source files

Older reports remain useful historical evidence but are not authoritative for current version or readiness claims.

## Finalisation checklist

- Confirm whether Tyro or Stripe is the launch provider.
- Add approved provider screenshots without exposing keys or customer data.
- Capture the actual WordPress, storefront, KDS and POSPal staging screens.
- Confirm store hours, lead times, location scope, support contacts and escalation owner.
- Complete accessibility, mobile/tablet and print/PDF review.
- Replace every **TO BE CONFIRMED** item with verified information or remove it.
