---
title: DoughBoss Technical Operations Guide
status: pre-final
audience: technicians, developers, WordPress administrators
software_version: "2.20.0"
database_schema: "1.13.0"
last_verified: "2026-07-21"
payment_status: integration-pending
---

# DoughBoss technical operations guide

> **DRAFT NOTICE:** This is a pre-final staging guide. The concept demo is simulated. Online payment processing must remain disabled until the selected Tyro or Stripe integration completes its sandbox and staging certification. Unknown deployment-specific values are marked **TO BE CONFIRMED**.

## 1. Outcome and operating boundary

Following this guide should produce a backed-up, reviewable staging installation with:

- the DoughBoss WordPress plugin installed;
- database schema `1.13.0` verified;
- menu, location and staff access configured;
- the order lifecycle and Kitchen Display System (KDS) exercised;
- voucher and POSPal behaviour tested in the selected operating mode; and
- payment controls left disabled until provider certification is complete.

This guide does **not** authorise a production rollout.

`[DIAGRAM: WordPress storefront → checkout → order database → KDS, POSPal outbox and notifications]`

## 2. Prerequisites and evidence to collect

### Required access and tools

- WordPress administrator access to a disposable or restorable staging site.
- Hosting control panel or equivalent access for database/files backup and PHP logs.
- An installable DoughBoss plugin zip built from the approved commit.
- A supported PHP runtime. CI currently validates PHP 7.4, 8.2, 8.3, 8.4 and 8.5.
- MariaDB/MySQL with InnoDB tables and permission to run WordPress migrations.
- A test kitchen tablet/browser.
- POSPal test access, only when testing POSPal integration.
- Tyro or Stripe sandbox details: **TO BE CONFIRMED**.

### Record before changing anything

1. Staging URL and environment owner.
2. Current plugin version and commit SHA.
3. Current `doughboss_db_version` value.
4. Backup identifier, creation time and restore owner.
5. Existing order, voucher and POSPal outbox record counts.
6. PHP version, WordPress version and database version.

> **WARNING — BACKUP REQUIRED:** Take a full database and WordPress files backup before installing or upgrading the plugin. A plugin-file rollback alone cannot reverse a completed database migration.

## 3. Build and install on staging

1. Confirm the branch and commit are the approved review target.
2. Run the repository verification workflow and require every mandatory check to pass.
3. Build the installable zip using the repository packaging workflow or documented packaging script.
4. In WordPress, open **Plugins → Add New → Upload Plugin**.
5. Upload the zip, install it, then activate or replace the existing version.
6. Confirm the **DoughBoss** menu appears in WordPress administration.
7. Open the PHP error log and check for `DoughBoss migration halted:`.
8. Record the installed plugin version and resulting database version.

`[SCREENSHOT: WordPress plugin upload and activation result]`

## 4. Database migration and checkout-integrity checks

Plugin `2.20.0` expects database schema `1.13.0`.

The migration adds or verifies:

- nullable `payment_intent_id varchar(191)`;
- nullable `checkout_key char(64)`;
- a unique index on `payment_intent_id`;
- a unique index on `checkout_key`; and
- InnoDB storage for transactional order, lifecycle and capacity operations.

The checkout key stored in the database is an HMAC-derived value bound to the cart. The raw browser key is not stored.

### Migration procedure

1. Install/activate the approved plugin build.
2. Allow the WordPress migration runner to finish.
3. Confirm `doughboss_db_version` is `1.13.0`.
4. Confirm no migration error is displayed in DoughBoss administration.
5. Verify the orders table has the two exact unique indexes above.
6. Run one unpaid-order test and confirm more than one unpaid order can exist because missing payment references are stored as `NULL`.
7. Run the interrupted-response test: retry the same checkout attempt and confirm only the original order is returned.

> **WARNING — DUPLICATE PAYMENT REFERENCES:** If historical non-empty payment references are duplicated, schema `1.13.0` stops before reopening checkout. It preserves the order rows and identifies the affected order IDs. Do not delete or choose a winning financial record without a written reconciliation decision.

`[DIAGRAM: backup → migration → schema readiness → checkout enabled; failure branch → reconcile or restore]`

## 5. Initial WordPress configuration

Open **DoughBoss → Settings** and work through the available sections.

1. Configure the active store/location and fulfilment modes.
2. Confirm pickup/delivery availability and business rules.
3. Import or create menu items and verify prices, categories and availability.
4. Configure sizes and toppings used by the current storefront.
5. Confirm tax, delivery fees and currency settings.
6. Create staff users with the minimum required role/capabilities.
7. Add the required DoughBoss shortcodes to customer-facing WordPress pages.
8. Configure notifications and real-time services only when their external service is actually available.

Recommended capability separation:

| Capability | Intended use |
|---|---|
| `manage_doughboss` | owner/manager administration |
| `manage_doughboss_kds` | kitchen order board |
| `redeem_doughboss_vouchers` | counter voucher scan/redeem |

`[SCREENSHOT: DoughBoss Settings, locations and staff role assignment]`

## 6. Payment boundary — provisional

> **PAYMENT INTEGRATION PENDING:** Keep online payments disabled until the selected provider passes every gate below.

The implemented checkout safety foundation:

1. The browser creates one checkout-attempt identity and retains it through retry.
2. The server recalculates the cart total.
3. A provider reference is verified for success, amount, currency and order metadata.
4. The database allows only one order for a checkout key and one order for a canonical provider reference.
5. A successful replay returns the existing order without writing items/events again.
6. Unmatched successful provider events are surfaced for operator reconciliation.

### Required certification gates

- Selected launch provider: **TO BE CONFIRMED — Tyro or Stripe**.
- Merchant account/ID and onboarding status: **TO BE CONFIRMED**.
- Sandbox transaction succeeds for exact amount and AUD currency.
- Failed/declined transaction creates no paid order.
- Dropped checkout response can be retried without a second charge or order.
- Provider webhook signature and replay handling pass.
- Refund/cancellation procedure is tested and assigned to an authorised role.
- Paid-but-unmatched transaction appears in reconciliation and is resolved correctly.
- Keys are stored outside the repository and are not printed in this guide.

`[SCREENSHOT: provider sandbox result beside matching DoughBoss order — redact sensitive identifiers]`

## 7. Order lifecycle and KDS test

Canonical fulfilment flow:

```text
pending → confirmed → preparing → baking → ready → completed
                                             └→ out for delivery → completed
```

Cancellation is a controlled terminal path. Server-side transition rules reject stale or invalid staff actions.

1. Open **DoughBoss → Order Board** on the kitchen tablet.
2. Enable browser sound at the beginning of the test shift.
3. Submit a staging order.
4. Confirm it appears on the correct location board.
5. Acknowledge it, accept it with a truthful ready estimate, and advance each status in order.
6. Confirm customer tracking shows customer-safe wording.
7. Confirm a stale second tablet cannot overwrite the newer state.
8. Confirm the board still updates using polling if Mercure is unavailable.

**NOTE:** Polling is the safety baseline. Mercure can reduce latency when configured, but the presence of Mercure code does not prove a hub or publish key is operational.

`[ANIMATION NOTE: Show one order card moving through only the permitted statuses]`

## 8. Capacity scheduling — staged foundation

The capacity subsystem contains deterministic windows, durable slots, expiring holds and atomic hold-to-order conversion. It is disabled by default and is not yet a customer-facing promise.

Before enabling customer enforcement:

1. Confirm location timezone, hours, notice period and planning horizon.
2. Confirm slot duration, order capacity and unit capacity.
3. Test blackout dates and daylight-saving boundaries.
4. Test two simultaneous customers competing for the final unit.
5. Prove expired holds release capacity.
6. Test the existing atomic hold-to-order primitive directly using the staging test harness.
7. Before customer enablement, implement and verify checkout forwarding of the hold token plus a documented paid-but-unconverted recovery path.
8. Add and approve the customer pickup-window interface.

> **CAUTION:** Capacity controls prevent overselling a window; they do not prove that the payment provider or customer notification succeeded.

`[GRAPH: one pickup slot showing free capacity, active holds and converted orders]`

## 9. Vouchers and POSPal

### Safe/manual pilot mode

1. Scan or enter the voucher in **DoughBoss → Voucher Scan**.
2. Enter the order total when required by the voucher rule.
3. Confirm the DoughBoss result.
4. Apply the matching discount manually in POSPal.
5. Keep the DoughBoss audit record with the order/till evidence.

Do not assume typing a `DOUGH-...` code directly into POSPal will work.

### Optional POSPal bridge

The bridge is off by default and requires a POSPal host, application identity, per-store rule mapping and a verified store test.

- Campaign claim with a customer phone is the path designed to grant a mapped POSPal coupon.
- A manually created one-off DoughBoss code is WordPress-only unless explicitly mapped.
- Best-effort POSPal failure must not invalidate an otherwise valid DoughBoss voucher redemption.

POSPal live auto-redemption status: **TO BE CONFIRMED**.

## 10. POSPal order outbox and recovery

DoughBoss creates the local order first. POSPal delivery is asynchronous through a durable outbox.

| Condition | Required action |
|---|---|
| Retryable network/configuration error | Correct the cause, then use the controlled retry path. |
| Ambiguous timeout | Search POSPal by customer, time and order before releasing a resend. |
| Unmapped product | Correct the mapping; do not assume the ticket reached the till. |
| POSPal disabled | Treat WordPress/KDS as the order truth and resolve configuration before enabling push. |

Ensure WordPress cron runs reliably. Low-traffic sites may require a real server scheduler.

> **WARNING — AMBIGUOUS DELIVERY:** Never automatically resend an order when POSPal may have accepted the original request. Confirm it is absent first to prevent duplicate kitchen tickets.

## 11. Monitoring and daily checks

Check at opening and after any release:

- plugin version, database version and deployed commit;
- WordPress/PHP errors and migration notices;
- unreconciled successful payments;
- POSPal retrying, failed or ambiguous outbox rows;
- KDS connectivity, filters and alert sound;
- stalled or late orders;
- voucher rejection/redemption anomalies; and
- last successful backup and restore evidence.

Do not close an incident merely because an endpoint returned success. Match the provider transaction, DoughBoss order, POSPal ticket and staff/customer outcome.

## 12. Rollback

1. Stop new online checkout if integrity is uncertain.
2. Record affected order/payment/outbox identifiers without exposing them publicly.
3. Export current incident evidence.
4. Restore the full pre-change database and files backup together.
5. Reinstall the previously approved plugin package only when it matches the restored database.
6. Re-run the customer, KDS, voucher and integration smoke tests.
7. Record the reason, owner, time and verification evidence.

Final production owner, escalation contacts and recovery-time target: **TO BE CONFIRMED**.
