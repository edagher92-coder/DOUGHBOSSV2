# DoughBoss photography quality checkpoint — 2 October 2026

## Direction and production boundary

Keep the accepted real-food-led bakery direction. Improve photographic
presentation without changing ingredients, portions, topping coverage, product
geometry or bake characteristics. Preserve original files and their provenance.
The existing AVIF/WebP delivery and responsive dimensions remain accepted; an
AI-created higher-resolution image is not proof of new photographic detail.

One built-in image-generation edit was made of
`public/images/menu/real-v1/zaatar.jpg`. Its source SHA-256 is
`afad7b29109603cfbef22320df5eed2575fc359a17dafa9bf7cc7ae82425dacf`.
The original is preserved and still selected by the production menu code.

The result is saved as
`docs/design/photo-proofs/zaatar-retouch-candidate-20261002.png` for visual
comparison only. It has cleaner presentation but regenerates fine seed/bread
texture. It is **not approved as a faithful replacement for genuine product
photography**, is not referenced by storefront code, and is not a production
photo-quality milestone. Do not replace all menu photos by applying this edit
blindly. Next photo acceptance needs source/result comparison, faithful product
appearance, consistent framing, accessible alt text and delivery byte budgets.

The approved Astra design brief does not require a decorative generated
background. No Higgsfield generation or paid/API fallback was used for this
checkpoint; the built-in image tool made the single edit. No customer/private
photo or credential was sent.

## Exact edit prompt

> Use case: lighting-weather / precise-object-edit. Edit target: the provided
> existing DoughBoss Zaatar manoush product photograph. Produce a restrained,
> high-quality commercial catalog retouch of THIS SAME PRODUCT, not a new recipe.
> Change only photographic quality: clean neutral white balance, even soft
> daylight studio lighting, natural contrast, controlled glare, gentle de-noising
> and clear but not crunchy detail. Preserve the exact irregular crust
> silhouette, proportions, topping distribution, sesame seeds, browned/charred
> patches, bake bubbles and camera angle. Do not add/remove ingredients, lighten
> away the bake marks, increase topping quantities, add garnish, cut the food,
> or create steam. Preserve the existing framing and crop; do not invent unseen
> portions outside the input. Keep the background clean matte off-white with no
> props, text, branding, logo or watermark. This is for the actual menu and must
> remain faithful to the real product, with a warm natural bakery look, not
> artificial glowing fast-food advertising.

## Square Orders contract discovery in the same continuation

The official Square Create Order reference was inspected read-only. It accepts
`POST /v2/orders`, an order plus an idempotency key, requires ORDERS_WRITE, and
supports replay with the same key. No provider operation was performed.

Repository inspection established the following requirements for the next
payment-linked implementation, rather than treating a payment as a POS ticket:

- Build itemization from the approved immutable snapshot, not browser items.
- Local unit price already includes canonical modifiers: do not add them twice.
- Freeze and verify GST basis, discounts and delivery/pickup totals. A provider
  total/tax discrepancy must block charging, not silently change approved money.
- The Square order ID is distinct from `payment_intent_id`. Use an explicitly
  validated immutable provider-order association, not free-form order notes.
- Generic safe-metadata updates currently preserve existing keys but need a
  dedicated conditional persistence boundary for a provider-order reference.
- Unknown Orders/payment outcomes require durable recovery before retries.
- Retain the existing local order/items transaction and approval/payment gate.
  Decide one kitchen owner before enabling Square fulfillment routing; an
  itemized API order is not physical POS/printer/KDS acceptance.
- Exact merchant/location/catalog ownership, credentials/permissions and staffed
  provider acceptance remain unverified. Do not invent catalog IDs or a new shop.

This contract discovery is implementation evidence for the next action, not a
claim that the Orders API, catalog mapping or POS/KDS delivery is complete.
