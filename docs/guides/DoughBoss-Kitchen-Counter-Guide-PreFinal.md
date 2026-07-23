---
title: DoughBoss Kitchen and Counter Guide
status: pre-final
audience: kitchen and counter staff
software_version: "2.20.0"
database_schema: "1.13.0"
last_verified: "2026-07-21"
payment_status: integration-pending
---

# Kitchen and counter staff guide

> **PRE-FINAL GUIDE:** Use the real WordPress Order Board or approved Staff Console. Demo logins, demo orders and demo timing are simulated and must never be used as production instructions.

## At a glance

Your shift flow is:

```text
Sign in → check the location → enable sound → acknowledge → accept with timing
→ preparing → in the oven → ready → completed
```

Delivery, if enabled, adds **Out for Delivery** before completion.

`[DIAGRAM: Pickup lifecycle with a separate delivery branch]`

## 1. Start the shift

1. Sign in using your individual approved account.
2. Open **DoughBoss → Order Board**.
3. Confirm the correct shop/location filter.
4. If you see **Sound is OFF — tap “Enable sound alerts” so you don’t miss new orders**, enable it.
5. Check the tablet volume and keep the board visible.
6. Confirm the board updates before the shop begins accepting online orders.

**Pro tip:** Use the **DoughBoss Kitchen** role for normal staff. Do not share an Administrator login.

`[PHOTO: Kitchen tablet showing location filter, connection state and sound control]`

## 2. Handle a new order

1. Read the order type, location, items, quantities, modifiers and notes.
2. Select **Acknowledge** to show the alert has been seen.
3. Choose a truthful estimate under **Accept — ready in:** when requested.
4. Select **Accept**.
5. Begin work only from the authoritative order card—not from a demo screen or an unconfirmed message.

`[SCREENSHOT: New order card with type, items, notes, age/due time and action highlighted]`

## 3. Advance the status

Move the order only when the real kitchen step has happened:

1. **Preparing** — preparation has started.
2. **In the Oven** — the applicable cooking stage has begun.
3. **Ready** — the entire order is packed and ready for the correct hand-off.
4. **Out for Delivery** — delivery order has actually left the shop.
5. **Completed** — pickup was handed over or delivery was completed.

> **CAUTION:** Do not mark an order ready to improve dashboard timing. Customers and other staff rely on the status being true.

Cancellation is a controlled action and may require manager permission or confirmation.

## 4. Understand board updates

The board normally checks for updates about every seven seconds. An optional live channel can make updates faster, but polling remains the fallback.

- A short delay does not automatically mean the order is lost.
- Two staff devices may refresh at slightly different times.
- If a transition conflict appears, refresh; another device may already have moved the order.

## 5. Redeem a voucher at the counter

1. Open **DoughBoss → Voucher Scan**.
2. Type or scan the code using **Scan with camera**.
3. Enter the actual order total when the screen asks for it.
4. Select **Redeem**.
5. Continue only when the screen shows **Redeemed ✓**.
6. Apply the approved matching discount in the till when the POSPal bridge is not verified live.

Common results:

- **Already used** — do not apply it again.
- **Minimum spend not met** — confirm the eligible order total.
- **Enter order total** — supply the actual total.
- **Declined** — follow the displayed reason or ask a manager.

`[PHOTO: Voucher camera scan followed by a successful result]`

## Quick fixes

| Problem | What to do |
|---|---|
| Order is not showing | Wait one polling cycle, check the shop filter, refresh, then confirm checkout completed. |
| Board is silent | Enable sound, check tablet volume and browser permission, then run a supervised test alert. |
| Status will not move | Refresh; another device may have updated it. Escalate if the order remains stuck. |
| Voucher is declined | Read the exact reason, confirm the code/total and ask a manager rather than overriding it. |
| POSPal ticket is missing | Search by customer, time and order first. Do not resend an ambiguous order without manager confirmation. |
| Tablet is lost | Tell the manager immediately so its password/session can be revoked. |

## Before ending the shift

- Confirm no active order is stranded in the wrong status.
- Escalate any unmatched payment, missing POSPal ticket or failed voucher record.
- Sign out on shared devices.
- Record tablet sound/connection problems for the next shift.

Final staff escalation phone and incident owner: **TO BE CONFIRMED**.
