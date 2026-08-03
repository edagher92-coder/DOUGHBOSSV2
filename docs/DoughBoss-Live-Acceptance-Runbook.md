# DoughBoss 2.33.2 live acceptance runbook

## Purpose

Use this runbook to validate the installed DoughBoss 2.33.2 release on `doughboss.com.au` without opening public ordering or enabling live payments prematurely. It covers the customer checkout, Stripe recovery, vouchers, customer email, private staff portals, Kitchen Display System (KDS), management, tracking and the optional POSPal mirror.

This runbook contains no credentials. Never paste passwords, API keys, webhook secrets, full payment references or customer data into a shared document or chat.

## Current safe state and readiness rules

Before any controlled test, confirm all of the following:

- The canonical DoughBoss plugin reports **2.33.2** and database schema **1.18.0**.
- Active gateway is **Stripe**.
- Stripe selector is **Live**, but **Accept orders** is OFF and **Accept card payments** is OFF.
- The Live publishable key is present and the Live webhook signing secret indicates set.
- At the time of this handover, the Live secret-key readiness indicator is absent. This is a current blocker, not a required permanent condition: live card payments must stay fail-closed until the indicator reports configured.
- DoughBoss Migration Gate is OFF in the normal safe-restored state. Activate it before opening any controlled Test-mode ordering window.
- Public `/order/` remains browse-only and displays Coming Soon.
- Toolset Types 3.4.2 is inactive but retained. Do not delete it or its legacy data during acceptance.

For a controlled **Test-mode** acceptance run, the Test secret-key indicator and Test webhook recovery must both report configured before orders or card payments are temporarily enabled. For a **Live-mode** acceptance run, the corresponding Live indicators must both report configured. A newly configured Live secret key resolves the current blocker; it does not invalidate this runbook.

If a protective control differs (plugin/schema version, active gateway, orders/cards OFF, gate state or public Coming Soon state), stop and record the discrepancy before changing a setting.

## Protected staff workspaces

| Workspace | Canonical URL | Minimum role |
| --- | --- | --- |
| Main kitchen MAKE | `https://doughboss.com.au/kitchen/` | DoughBoss Kitchen |
| Kitchen PASS | `https://doughboss.com.au/kitchen/?screen=pass` | DoughBoss Kitchen |
| Catering production | `https://doughboss.com.au/catering-kitchen/` | DoughBoss Kitchen |
| Management overview | `https://doughboss.com.au/management/` | DoughBoss Manager |

`/kitchen/?screen=catering` remains a backward-compatible bookmark, but `/catering-kitchen/` is the canonical hidden catering workspace. None of these URLs belongs in public navigation.

Create named staff users with unique passwords stored in the organisation's password manager. Never use a shared administrator account on a kitchen device. Kitchen users must not receive `manage_doughboss` or broad WordPress administrator access.

## Controlled Test-mode window

The approved navigation proof passed. The subsequent fresh 2.33.2 order-preparation phase timed out before Stripe Checkout, so no payment was submitted and no order was created. Run tag `QA-20260803-231050` has zero matching orders. This is a safe negative result, not an accepted end-to-end payment test.

After the timeout, the live site was restored and verified: the Stripe selector is Live; public config reports `orders=false`, `cards=false` and `gateway=stripe`; `/order/` returns HTTP 200 with Coming Soon; and Migration Gate is deactivated.

1. Record the original DoughBoss payment, ordering and POSPal settings.
2. Activate DoughBoss Migration Gate and verify that public customers remain shielded.
3. Keep Revesby as the only ordering location for acceptance.
4. Change Stripe to **Test** and confirm the Test secret-key and Test webhook readiness indicators are configured.
5. Enable **Accept orders** and **Accept card payments** only for the controlled test.
6. Keep POSPal order mirroring OFF until the basic Stripe, order, KDS and email chain passes.
7. Use a unique run tag in the order notes, such as `QA-YYYYMMDD-NN`, and mark the order clearly as do not prepare.

Never proceed when the page does not visibly say Test mode or when the expected amount is unclear.

## A. Public and portal smoke test

1. Confirm Home, About, Menu, Locations, Catering and Order pages load without a PHP error.
2. Confirm `/wp-json/doughboss/v1/config`, `/menu` and `/locations` return valid responses; the canonical menu should contain 34 products.
3. In a logged-out browser, confirm `/kitchen/`, `/catering-kitchen/` and `/management/` redirect to WordPress sign-in with the requested URL preserved.
4. Confirm the logged-out responses carry no-index, no-cache and anti-framing protection.
5. Sign in as a Kitchen user and verify MAKE, PASS and Catering workspaces. Confirm the user cannot access broad settings.
6. Sign in as a Manager and verify `/management/`, Orders and Vouchers.

Pass condition: the public site remains usable, staff portals remain private, roles are least-privilege and each portal loads its intended workspace.

## B. Core Stripe recovery and idempotency test

Run this once after each payment-path deployment.

1. Build one pickup order for exactly one Spring Water at the currently displayed price. The current canonical price is A$3.50; stop if the live cart differs.
2. Continue to Stripe-hosted Checkout. Card and eligible wallet details must appear only on `checkout.stripe.com`.
3. Use Stripe's official declined-card Test value first and confirm a clear retry path. No DoughBoss order or KDS ticket may exist yet.
4. Retry the **same** Checkout Session with Stripe's official 3-D Secure Test value and complete the challenge.
5. Close the tab as soon as it begins returning to DoughBoss so the signed webhook, rather than browser completion, must recover the order.
6. Allow up to 90 seconds for delivery and reconciliation, then inspect Stripe Test, Orders, Kitchen and Management.

Required evidence:

- Exactly one succeeded canonical Stripe Test PaymentIntent for the expected AUD amount.
- A successful signed webhook delivery.
- Exactly one paid Pending DoughBoss order.
- Exactly one KDS ticket and one customer confirmation email.
- Management totals increase by exactly the order total and unresolved Stripe payments remain zero.
- Refreshing or reopening the return URL does not create a second order, email or ticket.

The controlled Test-mode run for 2.33.2 is still pending and not accepted. The approved navigation proof passed, but the fresh order-preparation phase timed out before Stripe Checkout. No payment was submitted, and tag `QA-20260803-231050` has zero matching orders. Historical orders and earlier-version acceptance are supporting evidence only; they do not replace this run.

## C. Student voucher claim and customer email test

This test requires one owner-controlled, accessible `.edu` or `.edu.au` mailbox. Do not use an invented or third-party address.

1. Keep the Migration Gate active and capture the current voucher count.
2. At `/vouchers/`, select the active `dough5` offer and enter one controlled test phone plus the same eligible student email twice.
3. Confirm one DOUGH voucher code and self-hosted QR appear on screen.
4. In Vouchers, confirm one new issued $5, single-use voucher with scope `both` and the expected campaign.
5. Confirm exactly one voucher email reaches the same mailbox. Check From, subject, $5 value, masked or privately recorded code, single-use wording, online instructions, spam handling and the mail log.
6. Submit the same student email again. It must be refused without creating another voucher or email.

Important delivery behaviour: DoughBoss claims an exactly-once email-attempt marker before calling WordPress `wp_mail()`. A failed transport is not automatically retried. The on-screen code is the fallback, but a missing email is still a failed acceptance result and must be fixed before advertising email delivery.

## D. Voucher-adjusted Stripe order

Use the voucher from section C. Choose one POSPal-mapped item costing more than $5. The recommended current item is one Cheese at A$9.50, but use it only after the live price and mapping are verified.

1. Add one item and apply the voucher.
2. Confirm the preview is non-mutating: before payment the voucher remains `issued` and no redemption row exists.
3. If the live cart is one A$9.50 Cheese with 10% GST-inclusive pricing, require subtotal A$9.50, discount A$5.00, total A$4.50 and included GST A$0.41. Stop if the server cart differs.
4. Open one Stripe Test Checkout Session for the adjusted total.
5. Exercise a decline, then retry that same immutable session and complete 3-D Secure successfully.
6. Close the return tab to require webhook recovery.

Required evidence:

- Stripe shows one successful charge for the exact discounted AUD total and no duplicate successful charge.
- One DoughBoss order stores the original subtotal, $5 discount, final total, voucher code, Revesby location and paid-by-Stripe state.
- The voucher is redeemed once and one online redemption audit row is linked to that exact order.
- Customer and store confirmation emails, tracking, KDS and Management all show the same order number and final total.
- A fresh-cart attempt to use the voucher is refused.
- Replaying the return or checkout completion does not duplicate the order, redemption, email or ticket.

Stripe cancellation semantics in 2.33.2 are intentional: cancelling Checkout does **not** consume or immediately release the voucher. The same Checkout Session, server snapshot and voucher reservation are retained for a safe immutable retry. Do not expect a cancelled payment to produce or "revert" a redemption row.

## E. Kitchen, management and customer tracking

1. Confirm the new order appears once in `/kitchen/` MAKE.
2. Acknowledge and accept it, progress it through preparation and PASS, then complete collection.
3. Confirm MAKE and PASS contain no active duplicate after completion.
4. Confirm Management shows the correct order count, gross, discount, paid total and unresolved-payment count.
5. Track the order with its order number and matching checkout email.
6. Submit the same order number with a different email. The response must disclose nothing about whether the order exists.

Pass condition: customer, kitchen, management and event history agree through every status transition.

## F. POSPal order and voucher pilot

Run only after A-E pass and only with owner approval because this section mutates the real POSPal account even though Stripe remains in Test mode.

1. Use the read-only POSPal connection and coupon-rule checks for Revesby.
2. Confirm the exact test item has a current Revesby product mapping and the $5 coupon rule UID resolves.
3. Enable POSPal order mirroring only for one controlled order and retain the original setting.
4. If testing member-coupon bridging, claim through the campaign form with a test phone. Confirm exactly one $5 member coupon exists and the WordPress voucher stores its POSPal references.
5. Complete one voucher-adjusted Stripe Test order.
6. Confirm one DoughBoss POSPal outbox row reaches `succeeded` with a stable remote reference.
7. In POSPal, confirm exactly one Revesby order with matching DoughBoss order number, item, final discounted total and paid-state treatment.
8. Confirm the mirrored member coupon is visibly used/revoked after the online redemption.
9. Restore order mirroring to its original state.

Important gaps:

- The POSPal order outbox is durable and retry-safe, but the voucher grant/revoke bridge has no provider-network automated test.
- Voucher revoke is currently fire-and-forget rather than stored in the durable outbox. A failed revoke can leave a mirrored POSPal coupon active after the WordPress voucher was redeemed online.
- The order payload sends the discounted `totalAmount`, while product lines retain their original unit prices and contain no explicit voucher-discount line. POSPal's treatment must be verified on the real till.

Until those points are proven and an operator recovery process is approved, keep POSPal member-coupon bridging disabled for production or treat any mismatch as a launch blocker. Never bulk-retry an ambiguous POSPal result; perform the till check and release only that reviewed outbox row.

## G. Refund test

1. Refund the controlled Stripe Test order through DoughBoss or the recorded provider path.
2. Confirm the refund appears once in Stripe and DoughBoss.
3. Repeat the refund action and confirm it reports already refunded rather than issuing another refund.
4. Do not automatically reissue the consumed voucher; management must decide whether to issue a replacement.

Pass condition: one refund, no duplicate money movement and a clear voucher decision.

## Restore and final decision

Always restore the safe state, even when a test fails:

1. Accept orders OFF.
2. Accept card payments OFF.
3. Stripe selector returned to Live configuration.
4. POSPal mirroring returned to its recorded baseline.
5. Migration Gate deactivated only after the safe state and public browse-only page are independently verified.
6. Preserve orders, payment attempts, webhook events, voucher audits and POSPal references as evidence.

Do not open public ordering until every required section passes, Live Stripe secret readiness is configured, one genuine Live webhook delivery succeeds, the real eligible-student inbox test passes, the approved POSPal pilot passes, wallet-device checks are complete, the owner signs the evidence and kitchen staff are ready.

## Backup timing

The owner has explicitly postponed backup work until all other implementation, deployment, repository, CI, security cleanup and acceptance work is complete. Do **not** interrupt the current completion sequence to take another backup. The final fresh files-and-database backup is the last pre-launch protection step after the repository and CI are final and all other work passes, immediately before management authorises public go-live. Record its timestamp and recovery location without storing credentials in this document.
