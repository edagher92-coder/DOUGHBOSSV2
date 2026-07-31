# DoughBoss Tyro Connect acceptance runbook — pre-final

Applies to DoughBoss `2.22.0`, database schema `1.15.0`.

This is the operational hand-off for the current Tyro Connect Pay integration.
It is intentionally **pre-final** until Tyro supplies sandbox credentials,
per-store location IDs and a webhook signing key, the sandbox matrix passes,
and Tyro completes its mandatory technical review.

## Architecture decision

DoughBoss uses the current Tyro Connect Pay API and Tyro.js:

```text
customer / table QR / catering link
  -> server-owned store, table, cart and total
  -> durable DoughBoss payment attempt
  -> mapped Tyro Connect locationId
  -> Tyro Pay Request + short-lived paySecret
  -> provider-hosted Tyro.js card form and 3DS
  -> browser status for quick feedback
  -> signed webhook + server retrieval for payment authority
  -> paid DoughBoss order / catering leg
  -> KDS + printer + customer message + asynchronous POSPal outbox
```

The old MPGS merchant-password, Session.js and server-PAY assumptions are not
used. Tyro's current flow is documented in [Accept an online payment](https://docs.connect.tyro.com/app/apis/pay/accept-an-online-payment).

## What Tyro must provide

- Sandbox `client_id` and `client_secret`.
- Production `client_id` and `client_secret` after approval.
- Tyro Connect `locationId` for every DoughBoss shop.
- Confirmation of the eCommerce MID mapped to each location.
- Sandbox and production webhook signing keys.
- Confirmation that the registered URL is:
  `https://<site>/wp-json/doughboss/v1/payments/tyro/webhook`
- Card payment enablement. Leave Apple Pay and Google Pay off until their
  domain/merchant setup is separately complete.
- Technical review instructions and production approval.

## Server configuration

Prefer environment variables or `wp-config.php` constants for every secret:

```php
define( 'DOUGHBOSS_TYRO_TEST_CLIENT_SECRET', 'provided-by-tyro' );
define( 'DOUGHBOSS_TYRO_TEST_WHSEC', 'provided-webhook-signing-key' );
define( 'DOUGHBOSS_TYRO_LIVE_CLIENT_SECRET', 'provided-after-approval' );
define( 'DOUGHBOSS_TYRO_LIVE_WHSEC', 'provided-live-signing-key' );
```

Do not commit secrets or put a `paySecret` in logs, options, database rows,
analytics, URLs or browser storage. Tyro's [Tyro.js initialization guide](https://docs.connect.tyro.com/app/apis/pay/tyro-js/init)
states that the Pay Secret is short-lived and should not be stored or logged.

## WordPress setup

1. Install the review build on staging and verify plugin `2.22.0`, schema
   `1.15.0`, and no migration error.
2. In **DoughBoss → Settings → Payments**, choose Tyro and Sandbox.
3. Enter the sandbox client ID. Supply the client secret env-first.
4. Enter the sandbox webhook signing key env-first.
5. For every shop in **DoughBoss → Shops / Locations**:
   - enter its exact Tyro Connect location ID;
   - select its POSPal store mapping;
   - enable online payment for that shop only after the mapping is checked.
6. Save and run the read-only Tyro connection test. It may authenticate; it
   must not create a Pay Request or move money.
7. Keep live mode disabled. The live readiness gate also requires the operator
   to confirm that Tyro certified this integration.

Tyro OAuth uses client credentials and cached access tokens. Avoid repeatedly
requesting tokens because Tyro rate-limits excessive authentication. See
[Authentication](https://docs.connect.tyro.com/app/authentication).

## Store and QR binding

- A public menu order uses the chosen DoughBoss shop.
- A table QR uses the server-issued, HttpOnly table session; browser-supplied
  store/table values are not authoritative.
- The backend maps that DoughBoss shop to exactly one Tyro `locationId`.
- The durable attempt records safe local store/table/QR facts, total, currency
  and provider reference. It never records card data or the Pay Secret.
- An unmapped or payment-disabled shop fails before a Pay Request is created.

## POSPal boundary

Tyro owns payment. DoughBoss owns the order and kitchen release. POSPal is an
asynchronous mirror after authoritative payment success:

```text
Tyro SUCCESS -> local paid order -> KDS immediately -> POSPal outbox retry
```

A POSPal outage must not lose a paid kitchen order or cause another charge.
An ambiguous POSPal result stays in manual review and is never blindly replayed.

## Webhook contract

The endpoint reads the exact raw body and verifies the
`Tyro-Connect-Signature` HMAC-SHA256 value. It then:

1. validates the event type/resource/reference;
2. claims a hash-only deduplication key;
3. retrieves the Pay Request with server OAuth;
4. verifies origin attempt, amount, AUD currency and Tyro location;
5. updates the durable attempt;
6. reconciles the order or catering leg, or surfaces a paid attempt that has no
   order for operator review;
7. returns `200` for duplicates, unknown references and safe ignores; returns
   `500` only when a genuine temporary failure should be retried.

Tyro documents thin events, HMAC validation and duplicate/out-of-order handling
in [Webhooks](https://docs.connect.tyro.com/app/webhooks) and [Pay events](https://docs.connect.tyro.com/app/apis/pay/events/).

## Customer payment states

| Provider state | DoughBoss treatment |
|---|---|
| `AWAITING_PAYMENT_INPUT` | Keep Tyro-hosted form available. |
| `AWAITING_AUTHENTICATION` | Explain that bank verification may appear. |
| `PROCESSING` | Disable duplicate pay; check status; do not create a kitchen order. |
| `SUCCESS` | Re-verify server-side, then create/confirm the paid order. |
| `FAILED` | Show a recoverable decline; no order/KDS/POSPal work. |
| `VOIDED` | End the attempt; no kitchen release. |
| `PARTIALLY_REFUNDED` / `REFUNDED` | Reflect provider-confirmed financial state. |

See [Pay Request statuses](https://docs.connect.tyro.com/app/apis/pay/pay-request-statuses)
and [3D Secure](https://docs.connect.tyro.com/app/apis/pay/3d-secure/).

## Sandbox acceptance matrix

Capture an order/reference, browser size, timestamp and webhook evidence for
each scenario:

1. Pickup success at every mapped shop.
2. Table QR success with locked shop/table/name.
3. Public multi-shop selection cannot be altered after Pay Request creation.
4. Catering deposit success; balance remains separate.
5. Catering balance success after deposit.
6. Visa and Mastercard success.
7. Decline, expired card and invalid input: no order/KDS/POSPal entry.
8. 3DS frictionless and challenge flows on desktop and mobile.
9. Bank/3DS cancellation returns an accessible retry state.
10. Provider timeout/network loss enters “checking”; no duplicate charge.
11. Double click, refresh and lost checkout response create one Pay Request and
    at most one order.
12. Duplicate and out-of-order webhooks are harmless and return `200`.
13. Wrong amount, currency, origin or provider location fails closed.
14. QR rotation between Pay Request and checkout fails closed.
15. POSPal offline: paid order remains on KDS; outbox shows pending/review.
16. Full refund and partial refund against the original payment.
17. Secret scan: no OAuth token, client secret, Pay Secret, PAN, CVC or raw
    webhook body appears in HTML, REST logs, database exports or reports.

Use Tyro's published [sandbox testing scenarios](https://docs.connect.tyro.com/app/apis/pay/testing).

## Production activation gate

Do not enable live payments until all boxes are evidenced:

- [ ] Production backup and rollback rehearsal complete.
- [ ] Every live shop has confirmed Tyro location/eCommerce MID mapping.
- [ ] Every live shop has an explicit POSPal mapping or POSPal is intentionally off.
- [ ] Sandbox acceptance matrix passed.
- [ ] Signed webhook delivery verified on staging.
- [ ] Refund/void operational owner trained.
- [ ] Unreconciled-payment and POSPal-ambiguity queues checked by staff.
- [ ] Tyro technical review passed; evidence retained.
- [ ] Production client ID/secrets installed env-first.
- [ ] `tyro_live_certified` explicitly enabled by an owner.
- [ ] One shop enabled first, one controlled live order observed end-to-end,
      then remaining shops enabled independently.

Tyro requires production readiness and technical review; see
[Going live](https://docs.connect.tyro.com/app/apis/pay/going-live/) and
[Certification](https://docs.connect.tyro.com/app/apis/pay-online/certification).

## Rollback

1. Turn **Payments enabled** off. This stops new Pay Requests without deleting
   orders or attempts.
2. Keep the webhook reachable while any existing attempts may complete.
3. Reconcile all `processing`, `unknown` and unreconciled rows in Tyro and
   DoughBoss before changing credentials or deploying an older build.
4. Never uninstall the plugin as a rollback; uninstall removes data.

## Remaining provider-only work

The code is prepared for credentials and store mappings, but the integration is
not “live” until Tyro supplies the credential set, registers the webhook, the
sandbox evidence passes and Tyro approves production. Those are external
acceptance gates, not code that should be guessed around.
