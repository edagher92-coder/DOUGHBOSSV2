# DoughBoss Table QR Ordering Runbook — Pre-Final

Status: **Pre-final. Do not treat this feature as production-ready until the payment-provider, POSPal and physical-store staging gates in this runbook pass.**

Applies to DoughBoss plugin `2.21.0`, database schema `1.14.0`.

## What the feature does

Each printed QR belongs to exactly one active DoughBoss store and one dining table. Scanning it opens the configured menu, establishes a server-verified table session and starts a clean cart. The customer enters their name and the existing required contact details, orders from the normal menu and receives confirmation naming the verified store and table.

The browser cannot choose or replace the store, table or fulfilment type. The server derives all three from the QR session and records an immutable table snapshot on the order.

## Data chain

```text
Printed table QR
  -> opaque one-time-visible bearer code
  -> active QR, table and store validation
  -> new table session plus clean cart
  -> clean menu URL with store/table banner
  -> server-priced checkout with order_type=dine_in
  -> order snapshot: store, table, QR and session
  -> KDS / kitchen ticket / notifications / reporting / POSPal outbox
```

Only a SHA-256 hash of the QR code is stored. The raw code is displayed when it is first issued or rotated so the label can be printed; it cannot be recovered later.

## Owner setup for each store

1. Back up the WordPress database and plugin files.
2. Install the review build on staging and confirm the plugin reports version `2.21.0` and schema `1.14.0`.
3. Open **WordPress Admin -> DoughBoss -> Tables & QR**.
4. Add a table and choose its store.
5. Enter the customer-facing table label, such as `12`, and an optional zone, such as `Dining Room`.
6. Enter the menu page URL. It must use the same scheme, hostname and effective port as the WordPress site.
7. Issue the QR and immediately print the displayed label.
8. Mark the physical label with both the store and table name and attach it permanently to the matching table.
9. Repeat for every table. Never reuse one QR at two tables or across stores.

[SCREENSHOT: DoughBoss Tables & QR page with store, table label, zone and menu URL]

[PHOTO: Printed QR label showing store name and Table 12]

## Customer flow

1. The customer scans the QR at the table.
2. The server verifies that the code, table and store are active.
3. DoughBoss creates a fresh cart and redirects to the clean menu URL.
4. The menu displays **Ordering at: Store, Table** and hides store and pickup/delivery selection.
5. The customer chooses items and modifiers, then enters their name and the existing required contact details.
6. Checkout is recalculated and validated by the server.
7. Confirmation repeats the verified store and table and tells the customer the order will be brought to them.

If the QR is invalid, expired, rotated, revoked, or belongs to an inactive table/store, ordering fails closed. The customer must not be silently changed to pickup or routed to another store.

## Kitchen and staff flow

- The KDS shows a prominent **TABLE** badge before the customer name.
- Dine-in lifecycle wording uses **Ready to serve** and **Served**.
- Kitchen tickets print **DINE IN** and the table label prominently.
- Staff should verify the table badge before preparing or handing off an order.
- Reports and privacy exports include the order source and table snapshot.
- POSPal remarks include the table, but the live POSPal dine-in enum remains to be confirmed before production activation.

[SCREENSHOT: KDS order card with TABLE 12 and customer name]

[PHOTO: Kitchen ticket with DINE IN and TABLE 12]

## Rotate, deactivate and incident response

Rotate a QR when a label is replaced, photographed and shared, moved to another table, or otherwise suspected of misuse. Rotation invalidates the old code and all sessions created from it.

Deactivate a table when it is removed from service. This blocks new and existing table sessions while preserving historical orders.

Incident procedure:

1. Deactivate the affected table or rotate its QR immediately.
2. Print and attach the new label to the verified physical table.
3. Scan the old label; it must fail.
4. Scan the new label; verify the exact store and table banner.
5. Review recent orders and KDS activity for unexpected table use.
6. Record who rotated the code, why and when.

## Security limitation

A printed QR is a bearer link. Strong random codes prevent guessing, but a person can photograph a valid code and use that copy remotely until the code is rotated or deactivated. The QR identifies ordering context; it is not customer authentication or proof of physical presence.

If copied-code misuse becomes material, add a second on-table check such as a short rotating table PIN or staff confirmation. Wi-Fi presence or browser geolocation alone should not be treated as reliable proof.

## Required staging tests per store

For every store and a representative sample of every table zone:

1. Scan the physical label on iPhone and Android using mobile data and store Wi-Fi.
2. Confirm the menu names the correct store and exact table.
3. Confirm store and pickup/delivery controls cannot replace that context.
4. Add products and modifiers; confirm the cart begins empty after scanning.
5. Submit using a test customer name and verify KDS, admin, ticket, email/SMS and report output.
6. Confirm the KDS uses **Ready to serve** and **Served**.
7. Rotate the QR and prove the old label and existing old session both fail before payment and checkout.
8. Deactivate the table and prove its current session fails.
9. Attempt a forged store, table and order type in the request; verify the server retains the QR-bound values.
10. Run payment-provider sandbox tests for amount, currency, metadata binding, retry/idempotency, webhook and recovery behaviour.
11. Confirm the POSPal test order reaches the intended store and visibly carries the correct table remark. Confirm the supported dine-in/delivery-type value with POSPal before enabling live push.

Record device, browser, store, table, timestamp, expected result, actual result and evidence for every test.

## Release gates

Do not enable production table ordering until all of these are complete:

- GitHub CI passes for supported PHP and MariaDB versions.
- The `1.13.0 -> 1.14.0` staging migration succeeds against a production-like backup.
- Physical QR-to-table mapping is independently checked at every store.
- Stripe or Tyro sandbox checkout and webhook binding pass for table orders.
- POSPal confirms the live payload and dine-in mapping, or POSPal push remains disabled.
- Staff complete a peak-hour KDS rehearsal and incident drill.
- Final customer, kitchen and manager screenshots replace all placeholders.

Stripe and Tyro remain open integration options. Their activation, credentials and merchant onboarding are outside this pre-final QR release and must stay disabled until certified.

## Rollback

1. Deactivate all dining tables from **Tables & QR** to stop table sessions.
2. Leave historical orders and table snapshots intact for audit and reconciliation.
3. Disable the table-order entry points or restore the complete pre-release plugin and matching database backup according to the deployment runbook.
4. Do not delete order, QR audit or session records as a shortcut.
5. Reconcile any paid-but-unfulfilled test or production order before closing the incident.
