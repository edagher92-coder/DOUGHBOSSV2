# DoughBoss owner security brief

Date: 24 July 2026

Release reviewed: WordPress plugin 2.24.1

Status: pre-production acceptance; online payments are not yet approved for live use

## The short owner summary

DoughBoss has been designed so the website does not handle or store a
customer's card number or security code. Card entry is owned by the selected
payment provider. DoughBoss creates the order, calculates the price on the
server, and accepts a paid result only after it independently verifies the
provider's record against the original cart, amount, currency, shop and table.

The platform currently has separate adapters for Tyro Connect, Mastercard
Payment Gateway Services through Tyro, and Stripe. Only one is selected for a
given checkout. Live payment mode remains locked until its provider credentials,
staging tests and production approval have all been completed.

**Owner position:** the important controls are built, but the system should
still be described as **acceptance-ready**, not “live” or “fully certified”.

## How a secure order works

1. The customer builds an order. Prices, discounts and availability shown in
   the browser are treated as a preview.
2. The WordPress server rebuilds the cart from trusted menu records and
   calculates the final total.
3. The payment provider collects the card details on its hosted form or hosted
   fields. DoughBoss never receives the card number or CVV.
4. A unique checkout key binds that payment attempt to the final cart, shop,
   table, amount and currency.
5. After payment, the server retrieves or verifies the provider result. A
   successful-looking browser return is not sufficient.
6. Only a correctly matched paid order can continue to customer confirmation,
   staff acceptance and the kitchen workflow.

## Protections already built

### Payment and card protection

- Card numbers and CVVs are never stored in WordPress, the DoughBoss database,
  browser storage, analytics or logs.
- MPGS uses Mastercard Hosted Checkout. Tyro Connect and Stripe use their
  provider-controlled payment components.
- Payment passwords, API secrets and webhook secrets are server-side settings
  with environment-first support. They are not included in public JavaScript.
- Gateway hosts must be HTTPS and match an approved Mastercard host pattern.
  Arbitrary payment destinations are rejected.
- Test and live credentials are separate. Live mode has an additional explicit
  approval lock.

### Price and order integrity

- The server is the pricing authority; a customer cannot change the final
  amount by editing browser data.
- Paid results are checked against the expected amount, AUD currency, order
  type, DoughBoss shop, table/QR context and final cart fingerprint.
- Payment provider references become immutable once attached to an attempt.
- Database uniqueness rules and idempotency keys prevent repeated clicks,
  network retries and duplicate callbacks from creating duplicate orders or
  charges.
- Unknown payment outcomes are retained for reconciliation instead of being
  silently treated as paid or failed.

### Customer and staff access

- Staff, kitchen and management routes use separate WordPress capabilities.
  A kitchen user does not automatically receive owner permissions.
- Administrative changes require authenticated capability checks and WordPress
  request nonces.
- Public mutation endpoints are rate-limited to reduce automated checkout,
  voucher and form abuse.
- Order tracking requires the order number and matching email. Tracking
  responses are marked private and `no-store`; credentials are not placed in
  shareable tracking URLs.

### Store and table QR protection

- Each table QR record is bound on the server to a specific shop and table.
- Payment verification includes the same shop, table and QR context.
- A browser cannot safely convert a Revesby table scan into another shop or
  table simply by editing visible page values.

### Privacy and marketing

- Meta and TikTok events remain disabled until the relevant customer consent
  exists.
- Marketing payloads use an allowlist and exclude card data, customer contact
  details, payment identifiers and internal provider references.
- WordPress privacy export/erasure support can redact customer information
  while retaining the minimum accounting and redemption audit record.

### Kitchen and operational safety

- Payment state and kitchen state are separate. A payment-provider outage
  cannot silently rewrite the kitchen workflow.
- After-hours preorders are unpaid requests awaiting staff review; they do not
  enter the normal kitchen lane as confirmed orders.
- Order lifecycle changes are recorded as auditable, idempotent events.
- Paid orders can remain visible to the KDS when a downstream POSPal action
  needs manual review; a POS outage does not erase the customer order.

### Engineering verification

- Automated checks cover supported PHP and WordPress versions, JavaScript
  contracts, database migrations, checkout uniqueness and secret-pattern
  scanning.
- MariaDB integration jobs test duplicate checkout keys, payment references,
  capacity behaviour and lifecycle migrations.
- Payment adapters are fail-closed: missing credentials or missing approval
  leave the gateway unavailable rather than falling back to an unverified paid
  state.

## What remains before live payment approval

1. Install the test API password in the staging server's secret environment,
   never in Git or public WordPress content.
2. Run the authenticated MPGS connection test.
3. Complete Visa and Mastercard success, decline, cancellation, 3-D Secure,
   timeout, retry and lost-return scenarios.
4. Confirm each successful payment produces exactly one order, one customer
   confirmation and one correct kitchen record.
5. Confirm refund and void responsibilities with Tyro and test the agreed
   process. The current MPGS adapter intentionally requires operator review for
   refunds.
6. Confirm the Revesby eCommerce merchant account and settlement mapping.
7. Obtain production merchant credentials, the production gateway hostname and
   Tyro's approval to enable live processing.
8. Run one supervised low-value Revesby production order and reconcile the
   gateway, DoughBoss order, customer email and kitchen record before expanding.
9. Maintain normal WordPress operational controls: HTTPS, MFA for privileged
   accounts, least-privilege staff roles, prompt security updates, monitored
   backups and a tested restore procedure.

## A 90-second presentation script

> We have designed DoughBoss so the website never stores customer card numbers
> or security codes. The payment provider owns the secure card page. Our server
> remains responsible for the real menu price and will only accept a payment
> after checking the provider's record against the exact cart, amount,
> currency, shop and table.
>
> Duplicate clicks and network retries use unique attempt keys, so they cannot
> simply create duplicate orders. Staff, kitchen and management access are
> separated by role, public forms are rate-limited, order tracking is private,
> and marketing tools receive nothing until consent is provided.
>
> The important security controls are built and automated tests are passing.
> We are deliberately keeping payments in pre-production until the Tyro
> Mastercard sandbox journeys, refund process, settlement mapping and
> production approval are completed. After those checks, we will run one
> supervised Revesby transaction before wider activation.

## Questions an owner may ask

**Do we store card details?**

No. The chosen provider hosts card entry. DoughBoss stores only non-card order
and payment references needed for reconciliation.

**Can someone alter the price in their browser?**

They can alter what their own browser displays, but the server recalculates the
cart and rejects a payment that does not match the trusted total and context.

**Can a customer be charged twice by double-clicking?**

The platform uses durable payment attempts, unique checkout keys and database
constraints specifically to make retries idempotent. Sandbox testing must still
prove the complete provider journey before live approval.

**Is the system PCI certified?**

Hosted payment pages materially reduce DoughBoss's card-data scope, but only
the business's payment provider and qualified compliance advisers should make
the final PCI compliance determination.

**Is it ready to switch on today?**

No. It is acceptance-ready. The authenticated sandbox matrix, refund process,
production credentials and Tyro approval still need to pass before activation.
