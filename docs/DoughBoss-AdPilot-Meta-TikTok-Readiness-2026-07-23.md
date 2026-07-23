# DoughBoss acquisition and conversion readiness

Date: 2026-07-23
Status: browser bridge implemented; vendor IDs and AdPilot delivery deliberately dormant

## What is ready

- `public/js/doughboss-marketing.js` provides one strict event vocabulary for
  WordPress, Meta and TikTok.
- `demo/marketing.js` mirrors that behaviour but is hard-disabled in the public
  demo so simulated orders cannot pollute production advertising data.
- The WordPress storefront emits:
  - `add_to_cart`
  - `begin_checkout`
  - `purchase` only after `/checkout` returns a successful order
  - `generate_lead` for an unpaid after-hours preorder request
- The demo emits `purchase_simulated`, never `purchase`.
- UTM source, medium, campaign, content and term are read only after measurement
  consent. They are clipped and never mixed with customer contact fields.
- Vendor events require all three gates:
  1. `DOUGHBOSS_MARKETING_ENABLED` is true.
  2. The relevant Pixel ID exists.
  3. The host consent manager grants advertising consent by calling:

```js
window.DoughBossMarketing.setConsent({
  measurement: true,
  advertising: true,
  version: "2026-07"
});
```

The plugin does not inject Meta or TikTok vendor scripts. Load their official
base snippets through the selected consent manager, then the bridge will call
the existing `fbq` and `ttq` globals after consent.

## Configuration

Use environment variables or WordPress constants:

```php
define( 'DOUGHBOSS_MARKETING_ENABLED', false );
define( 'DOUGHBOSS_META_PIXEL_ID', '' );
define( 'DOUGHBOSS_TIKTOK_PIXEL_ID', '' );
```

Keep `DOUGHBOSS_MARKETING_ENABLED` false until the privacy notice, consent
interface, Events Manager test events and TikTok Events test are approved.

## Event mapping

| DoughBoss | Meta | TikTok | Meaning |
|---|---|---|---|
| `view_item` | `ViewContent` | `ViewContent` | Product detail is viewed |
| `add_to_cart` | `AddToCart` | `AddToCart` | Server cart accepted the line |
| `begin_checkout` | `InitiateCheckout` | `InitiateCheckout` | Checkout form is presented |
| `purchase` | `Purchase` | `CompletePayment` | WordPress created the order after payment verification when required |
| `generate_lead` | `Lead` | `SubmitForm` | Catering or unpaid preorder enquiry |
| `purchase_simulated` | none | none | Public demo only |

Every event carries a generated `event_id`. Use that same ID for a future
server-side copy to deduplicate browser and server events.

## Data boundary

Allowed browser properties:

- content IDs/names/categories
- currency, value, quantity and item count
- order type, numeric location ID and channel
- simulation marker
- consent state/version and UTM fields

Never include:

- name, email, phone or address
- order number, table number or QR token
- card, Tyro, Stripe or POSPal identifiers
- voucher codes
- staff notes
- raw item customisation text

## AdPilot integration contract

Do not post DoughBoss events to AdPilot's existing `/api/ingest` route. That
route is for aggregate Meta/TikTok advertising metrics and does not provide the
tenant-scoped replay/idempotency boundary required for customer conversions.

AdPilot needs a separate server-to-server endpoint before activation:

```json
{
  "schema_version": 1,
  "event_id": "uuid",
  "occurred_at": "ISO-8601 UTC",
  "organisation_id": "uuid",
  "store_id": 1,
  "channel": "web|qr|table|catering",
  "event_type": "order_created|accepted|paid|refunded|cancelled|fulfilled",
  "order_token": "rotating pseudonymous token",
  "currency": "AUD",
  "subtotal_aud": 0,
  "discount_aud": 0,
  "total_aud": 0,
  "items_count": 0,
  "utm": {
    "source": "",
    "medium": "",
    "campaign": "",
    "content": "",
    "term": ""
  },
  "consent": {
    "measurement": false,
    "advertising": false,
    "version": "2026-07"
  }
}
```

Required transport controls:

- raw-body HMAC SHA-256 signature
- timestamp and nonce with a short freshness window
- per-organisation credential, not the global ingest key
- `UNIQUE (organisation_id, event_id)` idempotency
- durable WordPress outbox with bounded retries and dead-letter review
- strict server allowlist with unknown fields rejected
- no browser endpoint or shared secret

## Launch acceptance

1. Approve the privacy/consent copy.
2. Add official vendor scripts through the consent manager.
3. Keep pixels in test-event mode.
4. Confirm no request fires before advertising consent.
5. Verify `add_to_cart`, checkout and one sandbox purchase once each.
6. Confirm the demo still emits no vendor request.
7. Build and test the separate AdPilot endpoint/outbox.
8. Verify browser/server `event_id` deduplication.
9. Enable production IDs only after payment and conversion totals reconcile.
