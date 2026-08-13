# DoughBoss 2.1 donor integration audit

Date: 13 August 2026

Donor archive: `doughboss-2.1.0-for-chatgpt.zip`

Target: DoughBoss 2.37.1, based on the complete 2.37.0 operational build

The supplied archive was treated as a design and implementation donor, not as
an upgrade. Its plugin metadata is 2.1.0 and its database schema is 1.0.0, so
installing it directly would remove later Stripe, voucher, kitchen, staff,
rewards, table-service and operational safeguards.

## Adapted into the current build

- A polished menu skeleton is rendered immediately while menu/config data is
  loading. It keeps an accessible live status for screen-reader users, hides
  decorative placeholders from assistive technology and disables shimmer when
  reduced motion is requested.
- Menu customisation and pizza-builder totals now provide restrained live price
  feedback after a customer changes an option. Initial render stays still and
  reduced-motion users receive the updated number without animation.
- Add, update, remove and clear cart writes now have generous one-minute abuse
  ceilings. They reuse the modern build's database-serialised limiter and its
  explicitly configured reverse-proxy trust model.

## Already present in a stronger form

- Accessible success/error toasts, quantity steppers and the floating cart
  summary.
- Staggered card entrance, button feedback, confirmation animation and complete
  `prefers-reduced-motion` handling.
- HTML customer and management emails, voucher delivery and order tracking.
- Checkout/voucher/catering throttles, Stripe hosted Checkout, webhook recovery,
  idempotency, duplicate-order protection and refund safeguards.
- Release ZIP validation, PHP/WordPress/database matrices and secret scanning.

## Deliberately not copied

- The donor's IP resolver trusted forwarded headers automatically. That can be
  spoofed when the origin is reachable directly. The current build only trusts
  a forwarded header after an operator explicitly enables and configures the
  proxy boundary.
- The donor's standalone rate-limit class used an unlocked transient increment,
  so concurrent requests could under-count. The current build serialises each
  bucket with a database lock.
- The donor mirrored the cart transient into a separate object-cache group.
  WordPress already backs transients with Redis/Memcached when configured; a
  second cache authority adds stale-cart and partial-write risk without a sound
  performance benefit.
- The donor's older checkout, email, REST, CI and schema files were not copied,
  because they predate the current payment, kitchen, table, staff and voucher
  contracts.

No live ordering, payment or WordPress gate is changed by this adaptation.
