# DoughBoss micro-detail punchlist

**Status:** pre-final implementation and acceptance specification
**Date:** 23 July 2026
**Scope:** customer demo, WordPress storefront, after-hours preorder requests, table service, kitchen workflow, payment safety, accessibility and release verification

This document records the small interaction and contract details that must be
closed before the current work is described as production-ready. A visually
correct demo is not evidence that WordPress, payments or kitchen operations are
connected.

## Decisions captured

- The homepage food composition must use genuine perspective depth: ingredients
  move out on X/Y/Z axes, rotate in three dimensions, hold visibly, then pull
  back into the manoush.
- The composition remains visible on mobile without covering the primary order
  CTA.
- Reduced-motion users receive an assembled still.
- When Revesby is closed, checkout remains available as an **after-hours
  preorder request**.
- An after-hours submission is acknowledged as **request received**, never
  **order confirmed**, **payment confirmed** or **accepted**.
- The customer is told that Revesby will review and confirm the request when the
  shop opens the following morning.
- After-hours requests do not take or simulate a real payment before staff
  acceptance.
- Required order consent and optional marketing consent must be separate.

## 1. Three-dimensional homepage motion

### Required behaviour

1. Add `perspective` to the stage and `transform-style: preserve-3d` to the
   stage world and food layers.
2. Give every ingredient its own X, Y and Z travel plus rotateX, rotateY,
   rotateZ and scale values. At least one item should recede while others move
   toward the viewer so depth is obvious.
3. Start only after required images have decoded or completed load/error.
4. Reset through a forced layout plus two animation frames so replay cannot be
   collapsed into a single browser paint.
5. Keep the exploded state visible for roughly one second before assembly.
6. End in a stable assembled state; do not run an infinite autoplay loop.
7. Provide one visible **Replay burst** control outside any `aria-hidden`
   subtree.
8. Replay when the About or Catering view is re-entered.
9. Keep decorative images `alt=""`; keep meaningful headings and CTAs outside
   the decorative stage.
10. Under `prefers-reduced-motion: reduce`, immediately show the assembled
    still, disable transitions and hide the replay control.

### Mobile acceptance

- The stage must not use `display:none` at 560px.
- The food remains subordinate to the headline and CTA.
- No ingredient may create horizontal page scrolling.
- CTA touch targets remain at least approximately 44px.
- Test 320px, 375px, 430px, 768px and desktop widths.

### WordPress delivery

- Implement an independent `[doughboss_manoush_hero]` shortcode.
- Load only the hero CSS/JavaScript for a hero-only page; never load Tyro,
  Stripe or the ordering app merely to animate marketing imagery.
- Accept escaped WordPress Media Library URLs for the central manoush and each
  ingredient. Do not package demo photos until usage rights are confirmed.
- Support multiple hero instances, no-JavaScript assembled fallback, classic
  themes, block/FSE templates and widget/template rendering.

## 2. Revesby after-hours preorder request

### Customer state machine

| Store state | Customer action | Result |
|---|---|---|
| Open | Place order / pay when enabled | Normal immediate-order flow |
| Closed and preorder enabled | Request preorder | Unconfirmed request receipt |
| Closed and preorder disabled | Contact Revesby | No false submission path |
| Request accepted by staff | Complete payment or receive confirmed order | Confirmed only after staff action |
| Request declined/expired | Clear explanation | No payment and no kitchen order |

### Required fields

- Customer name and mobile number.
- Revesby as the locked location for the initial pilot.
- Preferred pickup date and time/window.
- Cart snapshot and requested total.
- Required acceptance of Terms and Privacy collection notice.
- Optional, separate marketing opt-in; it must not be bundled into the required
  order checkbox.

### Confirmation copy

Use wording equivalent to:

> Preorder request received. This is not confirmed yet and no payment has been
> taken. Night-before preorders can be arranged through Revesby for now.
> Revesby will review your requested pickup time and confirm it when the shop
> opens in the morning.

The reference should be labelled **request reference**, not **order number**.
The timeline begins **Awaiting Revesby confirmation**; it must not say
**Payment confirmed**, **In the oven** or **Order placed**.

### Time and hours details

- Use `Australia/Sydney`, not the customer device timezone.
- Keep opening hours in one configuration source. The current demo conflicts:
  the launch profile says daily 6:30am–2:30pm while checkout code contains
  6:30am–2pm and an unconfirmed Thu–Sat 8:30pm close.
- Treat the exact closing minute as closed.
- Generate references and “tomorrow” dates in Sydney time, not UTC.
- Validate the requested time against the selected date, blackout dates,
  minimum notice and capacity.
- Do not promise “first thing” as an exact delivery time; it describes when
  staff review begins.

### Production architecture gate

The current production checkout deliberately rejects closed-store orders and
the capacity system is not yet customer-enforced. Do not simply remove that
gate. Use a durable preorder-request record and morning review queue, or prove a
scheduled-order design that keeps unaccepted requests out of the live KDS,
revenue and POSPal order stream.

Whichever model is selected must provide:

- server-owned cart hash and idempotency key;
- immutable location/requested-time snapshot;
- request status history and staff actor audit;
- accept/decline/expire transitions;
- notification retry trail;
- conversion to exactly one order;
- payment created only for the accepted, unchanged request;
- POSPal dispatch only after confirmed order creation;
- privacy export/erasure and retention handling.

## 3. Payment and retry integrity

- Tyro Pay Request creation must atomically claim one durable attempt before the
  upstream POST.
- Bind the provider reference through the dedicated immutable binding method.
- Do not return duplicate `pay_secret` fields.
- The same browser payment-attempt key must travel through payment creation and
  final checkout.
- Final checkout must recompute the server cart/location/order-type/table
  snapshot and compare its checkout key with the payment attempt.
- A changed cart with the same total must fail.
- Table QR location/table/QR identity must be revalidated immediately before
  payment verification and order creation.
- Payments remain disabled until Tyro sandbox, webhook, refund/void and
  certification acceptance all pass.

## 4. WordPress/customer contract details

- Add `accepted_at` to the public tracking projection so the ETA countdown can
  render.
- Localize the `includes GST` label instead of relying on an English browser
  fallback.
- Mark cart and public tracking responses `private, no-store`; vary
  cookie-bound responses by `Cookie`.
- Exclude DoughBoss ordering pages and REST responses from page/CDN caching.
- Ensure FSE, page-builder, widget and PHP-template shortcode placement can
  load the required assets without globally loading payment scripts.
- Use customer-safe lifecycle words: **Order received**, **Accepted by the
  shop**, **Being prepared**, **Ready for pickup / ready to serve**, **On its
  way**, **Collected / delivered**.
- Keep table number and customer name visible together on KDS and printouts.
- Store/queue QR ordering is not implemented merely because KDS knows how to
  display an order-source label; it needs its own issuer, session authority and
  checkout contract.

## 5. Acceptance matrix

### Automated

- PHP lint for every staged plugin file on PHP 7.4-compatible syntax and the
  current PHP runtime.
- Existing smoke, lifecycle, capacity, capacity-hold, atomic-order, demo-scope
  and Tyro suites.
- New 3D static contract: perspective, preserve-3d, translate3d, rotateX,
  rotateY, replay control, non-hidden mobile and reduced-motion still.
- New preorder tests: Sydney time, exact close boundary, next-day rollover,
  request-only copy, no card step, optional marketing consent, duplicate submit
  and disabled-preorder fallback.
- New payment tests: simultaneous creation ownership, immutable provider
  binding, same-total cart mutation rejection and checkout-key comparison.
- New WordPress asset/localization/tracking/cache contract tests.

### Visual/interactive

- About and Catering initial entry and replay.
- Desktop, tablet and mobile portrait/landscape.
- Reduced-motion browser preference.
- Slow image/network load and one failed decorative image.
- Keyboard-only checkout, Terms validation, replay and focus after receipt.
- Open-store order, exact-closing-minute request, after-hours request, accepted
  request, declined request and expired request.
- Table QR: scan, cart, payment, KDS, print, tracking and revoked/rotated QR.

### Release

- Real WordPress/MySQL staging matrix, not stubs alone.
- Tyro test credentials and webhook delivery.
- POSPal test order/voucher reconciliation.
- WordPress backup and rollback package.
- All CI green on the exact commit proposed for the draft PR.

## 6. Owner confirmations still required

These values must have one authoritative answer before launch:

1. Revesby trading hours, including whether any Thu–Sat evening hours exist.
2. Earliest and latest preorder pickup times and maximum booking horizon.
3. Confirmation channel: SMS, email, phone call or a combination.
4. Time by which unanswered requests expire.
5. Whether accepted preorders receive a payment link or pay on pickup during
   the pilot.
6. Approved production food images and their usage rights.
7. Final in-store prices for the newly listed Zaatar Veggie Pizza, Labneh
   Veggie Pizza, Labneh Veggie Wrap, Sujuk & Cheese, Half Meat & Cheese,
   Cheese Tomato & Olives and Cheese Kaak. The demo currently uses matching
   comparable-item prices and must not be treated as the till price authority.
