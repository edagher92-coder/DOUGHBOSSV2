# DoughBoss go-live handover

Date: 3 August 2026

Site: https://doughboss.com.au/

Document status: live version and post-test safety state verified. Public ordering remains intentionally closed while the remaining live-launch checks are completed.

This document deliberately contains no passwords, API keys, webhook secrets, card details, personal contact details or full voucher codes.

## Release and deployment record

| Item | Verified value |
| --- | --- |
| Live DoughBoss plugin | 2.33.1 active on `doughboss.com.au` |
| Source branch | `codex/final-visual-polish` |
| Source commit | `a4f6d8cdc3b3deecd412b93d8eba17b9ba9a7679` |
| Release | [v2.33.1-rc.2](https://github.com/edagher92-coder/DOUGHBOSSV2/releases/tag/v2.33.1-rc.2) |
| Installation ZIP | [doughboss.zip](https://github.com/edagher92-coder/DOUGHBOSSV2/releases/download/v2.33.1-rc.2/doughboss.zip) |
| ZIP size | 7,735,268 bytes |
| ZIP SHA-256 | `a61b1eba2a3ae44e33ca5539e106dcec796a38d23b09a33f5329eb02a8e51cf7` |
| Pull request | [PR #56](https://github.com/edagher92-coder/DOUGHBOSSV2/pull/56) |

Version 2.33.1 was deployed through a small verified updater because the host's normal WordPress upload limit rejected the full package. The updater verified the exact package size and SHA-256 before replacing the active plugin. A recoverable copy of the previous 2.32.0 plugin was retained outside the active plugins directory.

Two older DoughBoss installations, versions 2.22.1 and 2.25.5, remain inactive and were not used for this release. Both temporary verified-updater helper folders were safely deleted after the 2.33.1 activation was confirmed. The recoverable 2.32.0 backup remains retained outside the active plugins directory. Do not bulk-delete similarly named DoughBoss folders or remove that backup without first verifying the exact active plugin file and taking a current files-and-database backup.

## What changed for customers

- The site and ordering menu now use the 2.33.1 visual set: 34 menu items with 34 distinct high-resolution 1254 x 1254 WebP images.
- Repeated placeholder food photos were replaced with item-specific presentation. The Meat image is brighter and no longer appears burnt.
- Dough Boss Special was renamed to **Sujuk Special**.
- **Zaatar & Cheese** remains its own menu item, not an add-on to another product.
- Customer copy now says **oven-baked**. Stone-baked and wood-fired wording was removed from the current live assets.
- The home-page food scene is cleaner and more realistic. The replay control lets a customer deliberately replay the food movement. Devices that request reduced motion are respected rather than being forced to animate.
- The ordering interface remains responsive, searchable and grouped by Manoush, Pizza, Pies, Wraps, Desserts and Drinks.
- When ordering is closed, the menu remains available for browsing and clearly shows that ordering is coming soon.

## Checkout and payment changes

- DoughBoss calculates the cart, GST, discounts, location and fulfilment details on the server.
- Online payment uses a Stripe-hosted Checkout Session. Card information is entered on Stripe's page and is not handled or stored by WordPress.
- Eligible customers see Apple Pay or Google Pay automatically when Stripe, the browser, the device and the customer's wallet permit it. Card entry remains the fallback.
- One durable payment attempt is reused across retries. Amount, currency, location, cart, table/QR context and checkout identity are verified before an order is created.
- Voucher redemption and order creation are coordinated server-side to prevent a voucher being consumed without its matching order.
- Signed Stripe webhooks provide recovery when the customer pays but closes the browser before returning to the confirmation page.
- Payment and order safety controls remain separate: management can close orders and card payments without deleting configuration or payment references.
- The existing Mastercard/MPGS configuration remains available as a rollback path, but Stripe is the intended online gateway. Tyro remains the in-store EFTPOS path.

## Controlled checkout evidence

The following evidence was produced in Stripe Test mode behind the DoughBoss Migration Gate:

- Basket before discount: one Spring Water, A$3.50 including GST.
- Voucher discount: A$1.00.
- Final Stripe amount: A$2.50 AUD.
- Stripe-hosted Checkout displayed wallet-first options where eligible, plus card fallback.
- Payment returned to the DoughBoss confirmation page as paid.
- DoughBoss order created: **DB-260803-NTSCXM**, total **A$2.50**.
- The order-creation path performs server-side voucher redemption as part of the paid-order transaction; the full voucher code is intentionally omitted from this document.

Post-payment verification completed:

1. Admin Orders shows exactly one order for today: **DB-260803-NTSCXM**, A$2.50, paid and now **Completed**.
2. Repeated navigation back to the return page followed by an admin recount still showed one order; no duplicate order was created.
3. Vouchers shows exactly one **Redeemed online** row for the test voucher.
4. A fresh-cart attempt to reuse that voucher was rejected before payment.
5. Kitchen initially showed exactly one active paid Pickup ticket for Spring Water with the do-not-prepare test note. The ticket was deliberately moved through the full touch workflow: accepted, prep, sent to Pass and collected.
6. The KDS cleanup completed correctly: Admin now shows the order as **Completed**, while MAKE and PASS both show 0 active tickets.
7. Management shows Orders 1, gross sales A$2.50, paid A$2.50 and unresolved payments 0.
8. An invalid-signature POST to the live Stripe webhook returned HTTP 400 rather than the former HTTP 503. This confirms that the route is publicly reachable and rejects an invalid signature. A genuine Stripe Dashboard delivery with a 2xx response remains to be verified because Stripe Dashboard access was unavailable during this check.

## Kitchen and management access

| Workspace | URL | Recommended WordPress role |
| --- | --- | --- |
| Main kitchen MAKE screen | https://doughboss.com.au/kitchen/?screen=make | DoughBoss Kitchen |
| Pass and pickup | https://doughboss.com.au/kitchen/?screen=pass | DoughBoss Kitchen |
| Catering screen | https://doughboss.com.au/kitchen/?screen=catering | DoughBoss Kitchen |
| Owner/manager overview | https://doughboss.com.au/management/ | DoughBoss Manager |
| WordPress sign-in | https://doughboss.com.au/wp-login.php | Individual staff account |

These workspaces are protected by WordPress authentication, DoughBoss capabilities, the assigned-shop scope and REST nonces. An optional kitchen board key can add a second control for a bookmarked kitchen URL. An unauthenticated request to either `/kitchen/` or `/management/` was verified to return a 302 redirect to WordPress sign-in.

Never place a shared administrator password on the kitchen computer. Create one individual Kitchen account with the **DoughBoss Kitchen** role and a separate owner account with the **DoughBoss Manager** role. The kitchen role can use the order board and voucher scanner without receiving full WordPress administration. The manager role can operate DoughBoss orders, menu, settings, vouchers and kitchen screens without being made a full WordPress administrator.

### How staff use the kitchen screen

1. Sign in once with the dedicated Kitchen account and open the MAKE URL.
2. Use full-screen Chrome on the 23.8-inch Lenovo touch monitor at 1920 x 1080 and 100% scaling.
3. Confirm the connection and sound indicators before service.
4. Move each ticket through the production lanes using the large touch controls; payment and allergy warnings remain visible on the ticket.
5. Use the catering URL on the smaller display. Each touch monitor needs its own video connection and its own USB data connection.
6. Keep the mini PC on Ethernet for the primary order feed; Wi-Fi can remain a fallback.

### How management use the dashboard

1. Sign in with an individual Manager account and open the management URL.
2. Review today's order count, sales, paid totals, unresolved payments and POSPal status before opening orders.
3. Use Orders for fulfilment and payment investigation, Vouchers for campaigns and redemptions, and Payment Recovery for successful payments that did not immediately create an order.
4. Close **Accept orders** immediately if kitchen capacity, connectivity or payment recovery is not healthy.

## Coupon email delivery

Coupon delivery can use the existing WordPress email system without adding a new paid subscription:

- A successful website or staff-assisted **campaign claim** sends the claimed voucher to the validated claim email through WordPress `wp_mail()`.
- The current SMTP plugin transports that WordPress mail. If its existing plan/quota is sufficient, there is no extra DoughBoss fee or subscription.
- Student campaigns require an eligible `.edu` or `.edu.au` address and enforce the campaign's duplicate-claim and daily-cap rules.
- A manually created one-off voucher does **not** send the campaign-claim email automatically. It can be shared manually, or the customer should use the campaign claim flow when automatic delivery is required.

WP Mail SMTP Lite accepted and sent a test message successfully to DoughBoss's own orders inbox. This confirms that WordPress can hand mail to the configured transport without requiring a paid plugin subscription. Actual receipt in the destination inbox was not independently confirmed, and the owner-controlled `.edu`/`.edu.au` campaign-claim test is still pending. Complete that claim, then confirm inbox receipt, From address, subject, spam-folder behaviour and the SMTP/email log. Do not use an invented or third-party address for this test. A paid mail service is only needed later if the current SMTP allowance or deliverability proves insufficient.

## Verified post-test safety state

The controlled checkout temporarily used Stripe Test mode with **Accept orders** and **Accept card payments** enabled while the Migration Gate shielded public customers. The settings were then restored and verified on a cache-busted live reload.

The confirmed post-test state is:

- Active gateway: Stripe.
- Stripe selector: Live configuration.
- Live webhook recovery: configured.
- Live secret-key readiness: **not confirmed**. The Live secret-key row did not show the plugin's “A key is set” indicator, so live card payments must remain fail-closed until the protected host value is corrected and the indicator is rechecked.
- Accept orders: **OFF**.
- Accept card payments: **OFF**.
- Migration Gate: inactive.
- Maintenance: inactive.
- Public order page: HTTP 200, browse-only, **Coming Soon**.
- Kitchen and Management remain protected and redirect unauthenticated visitors to sign-in.

The public site can be browsed, but ordering and card payments are intentionally closed. These two controls must remain off until the remaining go-live checks are signed off and kitchen staff are ready for real orders.

## Remaining go-live checks

1. Set or correct the protected Live Stripe server key, restart the PHP worker/cache if required, and confirm that the Live secret-key row reports “A key is set.” Test readiness and Live webhook recovery are configured; the Live server-key indicator is the current blocker.
2. From Stripe Dashboard, confirm the live webhook subscribes to the required Stripe Checkout and PaymentIntent events and record one genuine successful 2xx delivery. The HTTP 400 invalid-signature probe is reachability/security evidence, not a substitute for this Stripe delivery.
3. Test Apple Pay on eligible Safari/Apple Wallet hardware and Google Pay on eligible Chrome/Google Wallet hardware. Confirm clean card fallback on an ineligible device.
4. Run the remaining declined-card, 3-D Secure, abandoned-browser recovery and full-refund paths.
5. Confirm one controlled live order reaches its confirmation email, customer tracking, Kitchen MAKE, Kitchen PASS, Management and the POSPal outbox with the same order number and total, then refund it.
6. Test one owner-controlled campaign claim to an eligible `.edu` or `.edu.au` address and retain its inbox and mail-log evidence.
7. Rotate any password that has previously appeared in a chat or screenshot, then use unique staff accounts and a password manager.
8. Take a fresh files-and-database backup immediately before the live acceptance order.
9. Open public ordering only after management signs the acceptance evidence and kitchen staff are ready to receive real tickets.

## WordPress maintenance notices

WordPress currently reports **two high-risk vulnerabilities** and also offers these plugin updates:

- Contact Form CFDB7: 1.3.6 to 1.4.0.
- Really Simple Security: 9.6.1 to 9.7.0.

These unrelated plugins were not changed during the DoughBoss release so that payment and ordering verification remained isolated. Review the vulnerability details and apply the updates promptly in a separately backed-up maintenance window, then test sign-in, forms, REST/webhook access, kitchen, management and ordering before ending that window.

## Rollback reference

If a live acceptance order fails, switch **Accept orders** and **Accept card payments** off first. Preserve the order, payment attempt, Stripe reference and webhook record. Reconcile any payment that succeeded, refund through the provider recorded on that order when required, and only change gateways when no checkout is in progress. The retained 2.32.0 plugin backup is for a controlled technical rollback; it must not be restored over live data without a database-compatible rollback review.
