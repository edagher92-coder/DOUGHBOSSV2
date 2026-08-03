# DoughBoss Revesby scan-to-order launch pack

Status: software prepared; physical mapping and live acceptance are still required. Keep **Accept orders** and **Accept card payments** off until every gate below passes.

## What is prebuilt

- A manager-only **DoughBoss -> Tables & QR -> Revesby launch QR pack** workflow.
- A safe 1-12 launch template that must be edited to match the real Revesby floor before creation.
- One server-bound QR per confirmed table. The browser cannot choose or replace the store, table or dine-in fulfilment type.
- A clean cart on every scan, an eight-hour HttpOnly table session, active store/table checks on every money path, and immediate invalidation after QR rotation or table deactivation.
- A print/PDF layout plus an individual SVG download for durable commercial labels.
- Kitchen cards that show **TABLE**, **Table QR**, customer name, modifiers, allergy/dietary notes and paid/pay-at-counter state.
- Dine-in lifecycle wording: **Ready to Serve** and **Served**.

## Manager setup at Revesby

1. Confirm the exact physical table labels and zones with DoughBoss management. Do not assume that every number from 1 to 12 exists.
2. Open **DoughBoss -> Tables & QR** using an Administrator or DoughBoss Manager account.
3. In **Revesby launch QR pack**, choose Revesby and delete or rename every template label that does not exactly match the floor.
4. Keep the order page on the same WordPress origin, normally `https://doughboss.com.au/order/`.
5. Create the pack once. If any label already exists, the entire request is rejected before new tables are created.
6. Immediately print or save the pack as PDF. Download each SVG if the labels will be professionally printed. Raw bearer codes cannot be recovered after leaving the page.
7. Mount each label only at the matching table. Have a second staff member independently verify store, zone and table mapping.
8. Keep the tables inactive, or keep global ordering/payments off, until acceptance is complete.

## Required phone and payment test

For one table in each physical zone, test both iPhone/Safari and Android/Chrome on store Wi-Fi and mobile data:

1. Scan the label and confirm the clean URL opens the Revesby order page.
2. Confirm the page says the verified Revesby table and does not offer pickup/delivery or another store.
3. Confirm a previously started pickup cart cannot leak into the table cart.
4. Add a normal item, a modifier and an allergy test note. Confirm all three appear correctly on checkout and the kitchen ticket.
5. In Stripe **Test** mode only, confirm the hosted Stripe checkout shows the final AUD amount, Apple Pay or Google Pay when the device is eligible, and card as the universal fallback.
6. Confirm exactly one payment, one authoritative order, one KDS card, one printer/POS outbox item and one customer confirmation are created on refresh/retry.
7. Confirm the KDS shows Paid before preparation. For an approved pay-at-counter order, staff must acknowledge the explicit unpaid warning before accepting it.
8. Move the ticket through Accepted, Preparing, Ready to Serve and Served. Confirm the customer tracker and configured email/SMS milestones match.
9. Disconnect the kitchen device temporarily. Confirm it shows Offline, locks mutations, keeps the last safe view and catches up after reconnect.
10. Rotate the QR. Confirm the old print and its active session fail closed, then retest the new print.

## Kitchen deployment gate

- Assign named staff their own WordPress accounts. Kitchen staff use the **DoughBoss Kitchen** role; managers use **DoughBoss Manager**. Do not share an Administrator login on the kitchen device.
- An Administrator must open each Kitchen user's WordPress profile and set **DoughBoss kitchen assignment -> Revesby**. A single-store install safely inherits its sole active shop, but as soon as multiple shops are active an unassigned KDS account is blocked. Managers retain the deliberate all-store view.
- Enable sound with a staff tap after each browser/device restart and confirm the visible Sound Off warning is gone.
- Keep the kitchen PC on wired Ethernet, disable automatic sleep during service and pin the KDS in full-screen kiosk mode.
- Complete a peak-hour rehearsal with duplicate taps, delayed Wi-Fi, a refunded ticket, an allergy note and a rotated QR.
- Verify POSPal mapping and the physical thermal printer at Revesby, or leave those connectors disabled and follow the documented manual ticket fallback.

## Rollback

Deactivate the affected table or rotate its QR immediately. Global **Accept orders** and **Accept card payments** are the final kill switches. Preserve orders and event history for reconciliation; never delete them to hide a failed test.
