# DoughBoss Stripe Online Payments Runbook

Date: 27 July 2026

## Decision

- Use Stripe for online ordering.
- Keep Tyro for in-store EFTPOS.
- Keep the existing MPGS adapter configured but inactive as a short-term rollback.
- Keep DoughBoss as the source of truth for the order, voucher, location, QR/table context and customer notifications.
- Send the completed paid order to POSPal only after server-side payment verification.

## Payment chain

1. DoughBoss calculates the cart and voucher-adjusted total on the server.
2. WordPress creates or reuses one durable Stripe payment attempt.
3. Stripe Payment Element collects card or eligible wallet details.
4. Stripe confirms the PaymentIntent.
5. WordPress retrieves the PaymentIntent and verifies its status, amount, currency, location, order type, table/QR context and checkout identity.
6. DoughBoss atomically redeems the voucher and creates one order.
7. The confirmation email, order tracker and kitchen board use the DoughBoss order.
8. The POSPal outbox mirrors the completed order without controlling payment.
9. A signed Stripe webhook provides recovery if the browser disappears after payment.

## Test-mode acceptance

- Successful Visa payment.
- Successful Mastercard payment.
- 3-D Secure challenge.
- Declined card with a clear retry path.
- Double-click and repeated network request create one PaymentIntent and one order.
- Browser closed after payment is surfaced for recovery.
- Full refund is safe to retry.
- Five-dollar voucher reaches Stripe, the order and POSPal as the same final total.
- QR/table order retains the correct Revesby location and table.
- Confirmation, accepted and ready emails contain the same order number.
- Kitchen Make and Pass screens receive exactly one order.
- Catering deposit and balance remain separately reconcilable.
- The Stripe test webhook is configured and an interrupted browser payment is visible to management.
- Apple Pay is tested on an eligible Safari/Apple Wallet device after domain registration.
- Google Pay is tested on an eligible Chrome/Google Wallet device after it is enabled.
- Unsupported devices fall back cleanly to card entry without an empty wallet control.

## Live activation gate

Do not enable live customer payments until all of the following are true:

- Stripe business verification and payout bank account are complete.
- Live publishable and secret keys are stored server-side.
- One live webhook endpoint is configured and its signing secret is stored server-side.
- The live webhook subscribes to `payment_intent.succeeded`.
- `doughboss.com.au` is registered as a Stripe payment-method domain.
- Apple Pay and Google Pay are enabled in Stripe only after their device tests pass.
- No Stripe secret, webhook secret, client secret or card detail exists in Git or browser storage.
- A controlled live order succeeds through payment, email, tracking, kitchen and management.
- The controlled order is refunded and the refund is visible in both Stripe and DoughBoss.
- Ordering remains limited to Revesby until another location is explicitly approved.

## Rollback

If Stripe checkout fails during launch:

1. Close online ordering.
2. Preserve all existing orders and payment references.
3. Review Stripe payments that succeeded without an order.
4. Switch the configured gateway only after no checkout is in progress.
5. Reopen ordering only after a new controlled acceptance order passes.

Never delete or overwrite payment references when changing gateways. Refund each order through the provider recorded on that order.
