# DoughBoss Live Acceptance Runbook

## Purpose

Use this runbook after the WordPress administrator has signed in and while the public migration gate remains enabled. It proves the live customer-to-kitchen flow without opening public ordering or exposing test data to customers.

## Account setup

Create named WordPress users; never create or share a default kitchen password.

| Account | Recommended username | Role | Permitted work |
| --- | --- | --- | --- |
| Revesby kitchen tablet | `revesby-kitchen-01` | `doughboss_kitchen` | KDS and voucher redemption only |
| Revesby manager | `revesby-manager-01` | `doughboss_manager` | KDS, vouchers and DoughBoss management |

Generate a unique password in the password manager for each person/device. Require a password change when a person leaves or a tablet is replaced. The kitchen role must not receive `manage_doughboss` or WordPress administrator access.

## Safe test configuration

1. Keep the public maintenance/migration gate enabled.
2. Keep launch scope to Revesby only; Roselands and Bankstown remain non-ordering locations.
3. In DoughBoss Settings, enable ordering only for the controlled test window.
4. Select MPGS test mode, never live mode. Leave live approval off.
5. Start with POSPal order mirroring off. Turn it on only for the POSPal section after the basic order test is clean.
6. Record the original settings before changing anything. Restore them after testing.

## A. QR table and shop-context test

1. In **DoughBoss → Tables & QR**, create a temporary active Revesby table such as `TEST-01` and print/open its QR URL.
2. Scan it with a phone. Confirm the shop is Revesby and the table label is visible.
3. Add one menu item. Confirm the order type is table service, not pickup/delivery.
4. Rotate the QR code, then retry the old QR URL. It must fail rather than silently becoming a pickup order.
5. Scan the new QR and confirm a fresh table session starts with an empty cart.

Pass condition: the table, location and QR session remain server-bound throughout checkout.

## B. Voucher test

1. Issue a single-use $5 test voucher for the Revesby test path.
2. Add an eligible item and enter the voucher. Confirm the discount preview is correct without consuming the voucher.
3. Complete one controlled order. Confirm the voucher becomes redeemed exactly once.
4. Attempt the same voucher again. It must be refused.
5. Start a second voucher checkout and deliberately fail/cancel payment. Confirm the voucher redemption is reverted.

Pass condition: no double redemption and no lost voucher after a failed payment.

## C. Visa and Mastercard Hosted Checkout test

Run one low-value sandbox order with each supplied test card.

1. Create a cart from the Revesby QR/table session or the Revesby web ordering path.
2. Enter customer email accessible to the test team.
3. Proceed to Mastercard Hosted Checkout. Card data must only appear on the gateway-hosted screen.
4. Return to DoughBoss and confirm exactly one paid DoughBoss order is created.
5. Refresh and use the back button once. Confirm no duplicate order is created.
6. In the MPGS test portal, confirm the matching gateway reference, amount and AUD currency.

Pass condition: the payment attempt is succeeded/captured, amount and currency match, and the gateway reference appears on one DoughBoss order only.

## D. Kitchen and staff test

1. Sign in as `revesby-kitchen-01` in a separate browser or tablet.
2. Confirm the new test order appears once on the order board.
3. Acknowledge/accept it and progress it through preparation and ready/served as appropriate.
4. Confirm the kitchen user cannot access DoughBoss settings or broad WordPress administration.
5. Sign in as the Revesby manager and confirm management can resolve board/voucher work without using an administrator account.

Pass condition: the customer status, staff board status and event history agree at each transition.

## E. Customer email and tracking test

1. Confirm the order receipt reaches the entered customer email and the configured management inbox.
2. Use the order number and matching email on the tracking page; confirm the expected customer-friendly state.
3. Try the same order number with a different email. It must return the same not-found response and disclose nothing.
4. After staff marks the order ready/served, refresh tracking and confirm the new customer state.

Pass condition: correct customers can track; incorrect emails cannot enumerate or view orders.

## F. POSPal pilot test

Run only after A-E pass.

1. Confirm Revesby product mappings for the selected test item.
2. Enable POSPal order mirroring for one controlled test order.
3. Complete the order and inspect the DoughBoss POSPal outbox.
4. Confirm exactly one POSPal order with the matching local reference, location, items and total.
5. If the remote result is ambiguous, use the operator review path; do not bulk-retry it.
6. Restore mirroring to its previous state unless it has passed owner review.

Pass condition: mapped order is mirrored once, failures do not block checkout, and ambiguous outcomes are manually reviewed.

## Final decision

Open public ordering only when every section passes and the owner signs off on the test evidence. Keep the migration gate enabled if any payment, voucher, QR, KDS, email or POSPal result is unclear.
