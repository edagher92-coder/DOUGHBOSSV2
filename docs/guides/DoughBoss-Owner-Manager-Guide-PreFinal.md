---
title: DoughBoss Owner and Manager Guide
status: pre-final
audience: owners and managers
software_version: "2.20.0"
database_schema: "1.13.0"
last_verified: "2026-07-21"
payment_status: integration-pending
---

# Owner and manager guide

> **PRE-FINAL GUIDE:** Live figures depend on the final WordPress and integration configuration. Demo sales, customers, timing, payments and logins are simulated.

## At a glance

Use WordPress administration for real operations. The main DoughBoss areas are expected to include:

- **Orders**
- **Order Board**
- **Vouchers**
- **Voucher Scan**
- **Reports**
- **Catering**
- **Settings**

Exact visibility depends on the user’s role.

`[SCREENSHOT: WordPress DoughBoss menu with each owner area labelled]`

## 1. Give staff the correct access

1. Create one account per person or approved device.
2. Give kitchen/counter staff the **DoughBoss Kitchen** role where appropriate.
3. Keep owner/payment/settings access limited to authorised managers.
4. Do not reuse one password across tablets.
5. Revoke the account or application password immediately when a device is lost or a staff member leaves.

| Role | Normal access |
|---|---|
| Kitchen/counter | Order Board and permitted Voucher Scan functions |
| DoughBoss Manager | Orders, reports, voucher oversight and selected settings; this is distinct from WordPress Administrator |
| Administrator/owner | Installation, integrations, secrets and full configuration |

`[DIAGRAM: Least-privilege role matrix]`

## 2. Check operations at opening

1. Open **Order Board** and confirm the correct location.
2. Run a supervised sound/refresh test.
3. Confirm ordering is open only when the shop can fulfil orders.
4. Review unresolved payment and POSPal notices.
5. Confirm WordPress cron and the last backup are healthy.
6. Check the menu, unavailable items, store hours and fulfilment settings.

## 3. Manage orders

Use **Orders** for detail and oversight; use **Order Board** for active kitchen work.

- Confirm staff use truthful ready estimates.
- Investigate late or stalled orders.
- Restrict cancellations/refunds to authorised staff.
- Do not treat a payment as resolved until the provider record matches the DoughBoss order.
- For an ambiguous POSPal timeout, search the till first before approving a resend.

`[SCREENSHOT: Order detail with lifecycle history and manager-only actions]`

## 4. Understand the payment status

> **PAYMENT INTEGRATION PENDING:** The checkout-integrity foundation is implemented and tested, but the launch provider is not yet certified. Keep online payments disabled until the signed staging checklist is complete.

Owner decisions still required:

- Launch provider: **TO BE CONFIRMED — Tyro or Stripe**.
- Merchant onboarding and commercial approval: **TO BE CONFIRMED**.
- Refund authority and procedure: **TO BE CONFIRMED**.
- Reconciliation owner and daily cut-off: **TO BE CONFIRMED**.
- Customer wording for payment failure/recovery: **TO BE CONFIRMED**.

Never paste keys into documents, tickets or chat. Secret fields may appear blank when revisiting Settings because stored values are intentionally not displayed.

## 5. Manage vouchers correctly

There are two different workflows.

### Claim a voucher for a customer

- Uses the active campaign rules.
- Usually records the customer phone.
- Is the path designed to trigger a mapped POSPal grant when the bridge is configured.

### Create a voucher (manual, one-off)

- Creates a WordPress voucher directly.
- Does not automatically make the code available in POSPal.

Void only eligible issued vouchers and keep the reason/evidence.

`[FLOW: Campaign claim with phone → optional POSPal grant versus manual code → WordPress only]`

## 6. Review reports

1. Open **Reports**.
2. Choose **From** and **To** dates.
3. Select **Apply**.
4. Review the figures against the real order records.
5. Use **Download CSV** when an export is required.

> **NOTE:** Demo dashboard figures are representative only. Do not use them for payroll, tax, settlement or trading decisions.

`[SCREENSHOT: Reports date filters, totals and Download CSV]`

## 7. POSPal order recovery

| Situation | Manager decision |
|---|---|
| Clear retryable failure | Correct configuration/network, then use controlled retry. |
| Unknown/timeout | Search POSPal first; release resend only when the original ticket is confirmed absent. |
| Unmapped item | Correct the product mapping and coordinate the kitchen manually. |
| POSPal disabled | Keep WordPress/KDS as order truth until integration is restored. |

Do not allow automatic blind retries for ambiguous outcomes; they can create duplicate kitchen tickets.

## 8. Daily close and weekly checks

### Daily close

- Reconcile provider transactions, DoughBoss paid orders and POSPal tickets.
- Resolve unmatched successful payments.
- Review failed/ambiguous POSPal outbox items.
- Confirm no active order remains stranded.
- Review voucher redemptions and voids.

### Weekly

- Confirm a successful backup and test restore evidence.
- Review staff access and revoke unused credentials.
- Review PHP/WordPress errors and migration notices.
- Confirm plugin `2.20.0`, database `1.13.0` and approved commit.
- Review late-order patterns, capacity assumptions and customer complaints.

## Quick fixes

| Problem | First checks |
|---|---|
| Voucher not appearing in POSPal | Was it a campaign claim or manual code? Was a phone supplied? Is the bridge/rule mapping enabled? |
| Secret setting looks blank | This can be intentional. Verify using the approved connection test; do not re-enter blindly. |
| Order board is missing data | Check location filter, polling/connection state, WordPress errors and checkout completion. |
| Payment succeeded but no order | Stop duplicate attempts, record provider evidence and use the unreconciled-payment process. |
| Staff cannot perform an action | Check their role/capabilities before granting broader administrator access. |

Final location list, support contacts, trading hours, payment provider and escalation matrix: **TO BE CONFIRMED**.
