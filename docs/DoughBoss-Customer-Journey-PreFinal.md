# Dough Boss customer journey — pre-final UX layer

## Purpose

This viewable demo layer makes the customer journey feel complete without
pretending that payments, order tracking, or membership accounting are live.
It is deliberately calm: the food leads, while the system earns trust through
clarity at the moment it matters.

**Status:** this document describes a prototype and an acceptance target. It does not make the static demo, payment provider, KDS or POSPal live.

## What is ready to view

- **Homepage ingredient burst.** A lightweight, code-native composition of
  existing Dough Boss food imagery supports the home-page story. It is
  decorative, has no autoplay video cost, hides on small screens, and stops
  moving when a customer requests reduced motion.
- **Checkout clarity.** The existing voucher stays visible from checkout to the
  receipt. The customer can see the amount saved before placing the demo order.
- **Confirmation and order journey.** A post-payment receipt groups payment
  confirmation, the pickup location, order contents, and a three-step order
  journey in one clear surface. Every status is labelled as simulated in this
  static demo.
- **Member/VIP honesty.** The home page and receipt reserve the right place for
  member rewards, but do not invent a balance, status, eligibility, or discount.
  The live version must ask the membership service for an authoritative result.

## Try it

1. Open `demo/index.html` with the static demo server or the published demo.
2. On the Home view, observe the ingredient composition beside the hero. On a
   phone it is intentionally removed so the first call to action stays clear.
3. Go to **Menu**, add an item, then choose **Checkout**.
4. Enter a demo-shaped voucher such as `DOUGH-7K2D9Q`, complete the form with
   made-up card details, and place the test order.
5. The confirmation illustrates the future receipt and tracking surface. It is
   a prototype only: no payment, order, voucher, or member record is created.

## Live integration boundary

The payment/order integration should provide a single safe `customerReceipt`
payload only after server-side verification:

```json
{
  "orderReference": "DB-1234",
  "payment": { "state": "confirmed", "amount": 2590, "currency": "AUD" },
  "fulfilment": { "method": "pickup", "locationName": "Revesby" },
  "discounts": [{ "label": "Voucher DOUGH-7K2D9Q", "amount": 500 }],
  "tracking": { "state": "received", "updatedAt": "2026-07-22T10:15:00Z" },
  "membership": { "eligibility": "not_connected" }
}
```

The browser must never decide payment success, member status, VIP tier, or a
discount amount. The backend should return only the applied discounts and an
explicit `membership.eligibility` state. The UI can then render `not_connected`,
`eligible`, or `ineligible` without showing unverified offers.

## Customer-to-kitchen acceptance hand-off

Use the implementation runbooks before presenting any of these as live:

- **Pickup/delivery:** the server prices the cart, confirms the authoritative payment result when payment is enabled, then creates the order and releases it to the KDS.
- **Table QR:** the scan fixes the store, table and dine-in context. The customer supplies their name; the browser cannot substitute the store or table. See `DoughBoss-Table-QR-Ordering-Runbook-PreFinal.md`.
- **Catering:** deposit and balance are distinct payment attempts. Catering must not be marked paid, released to production or mirrored to POSPal from a browser-only result.
- **Kitchen and POSPal:** the KDS works from the confirmed local order. POSPal is an asynchronous mirror, so a temporary POSPal outage must not hide or duplicate a paid kitchen order.

For Tyro, the evidence is a verified Tyro Connect result and signed webhook, not a successful-looking browser form. Until the provider's sandbox acceptance matrix and production approval are complete, use explicit **demo** or **pre-final** labels in customer-facing previews.

## Catering visual hand-off

The home ingredient component is intentionally modular: `.ingredient-burst`.
For the catering experience, use a separate composition that makes **Bites**
and **mini manoush** the hero assets, rather than reusing the home composition.
Keep its motion decorative and reduce it to a still composition under
`prefers-reduced-motion`; do not mix catering sales claims into the confirmation
or payment surface.
