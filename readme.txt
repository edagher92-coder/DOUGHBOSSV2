=== DoughBoss ===
Contributors: doughboss
Tags: pizza, food ordering, menu, restaurant, ecommerce
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.23.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pizza & food ordering for WordPress: menu management, a custom pizza builder, online ordering and order tracking.

== Description ==

DoughBoss turns any WordPress site into a pizza/food ordering storefront. It adds:

* A **Menu Items** custom post type with categories, prices and images.
* A **custom pizza builder** where customers choose a size and toppings with live pricing.
* A **cart and checkout** for pickup, delivery, or secure QR-bound table ordering, with configurable tax and delivery fees. Payment-provider activation remains optional and is not live until its own onboarding and verification gates pass.
* **Order tracking** so customers can check their order status by order number + email.
* An **Orders** admin screen with live status updates, plus a settings page for sizes, toppings, currency and fees.

Everything is rendered through shortcodes and a small REST API; no theme changes are required.

= Shortcodes =

* `[doughboss_menu]` — the menu grid.
* `[doughboss_builder]` — the custom pizza builder.
* `[doughboss_cart]` — the cart and checkout.
* `[doughboss_order_tracking]` — the order status lookup form.
* `[doughboss_shop_picker]` — choose which shop to order from (multi-shop sites).
* `[doughboss_ordering_status]` — accessible Coming Soon copy while checkout is paused.

== Installation ==

1. In wp-admin go to **Plugins → Add New → Upload Plugin**.
2. Upload `doughboss.zip` and click **Install Now**, then **Activate**.
3. Go to **DoughBoss → Settings** to configure sizes, toppings, currency, tax and fees. Fresh installs begin in safe browse-only mode; leave **Accept orders** off until staging is complete.
4. Add menu items under **DoughBoss → Menu Items**.
5. Place the shortcodes above on your pages.

== Frequently Asked Questions ==

= Does this process payments? =

Optionally. Orders are always recorded and the store is notified — DoughBoss
suits "order now, pay on pickup/delivery" out of the box. Stripe card payments
are also built in and can be switched on under **DoughBoss → Settings →
Payments** (off by default); once configured, checkout collects a card and the
order is verified server-side before it's accepted.

Tyro Connect Pay is also available as a **pre-final, disabled-by-default**
integration. It needs Tyro-provided sandbox credentials, a confirmed per-shop
`locationId`, a signed webhook, sandbox evidence and Tyro production approval
before live payments may be enabled. The installed code and this readme do not
include merchant credentials or make a shop payment-ready.

= Does it need an account system? =

No. Carts are tied to a cookie token, so guests can order without logging in.

== Changelog ==

= 2.23.3 =
* Restore visible replayable food-build animation to the demo homepage and Menu view.
* Add a static assembled fallback so food artwork remains visible if JavaScript is delayed or unavailable.
* Strengthen animation replay paint boundaries in both the demo and WordPress hero.
* Bump demo and WordPress asset versions to prevent stale browser caches.

= 2.23.2 =
* Add configurable first-party Track My Order links to confirmation, accepted and ready emails.
* Move customer tracking credentials from URL query strings into private no-store POST requests.
* Add the illustrated pre-final staff and management operations guide.
* Add a customer notification and tracking contract to the strict verifier.

= 2.23.1 =
* Add an end-to-end customer tracking and versioned KDS lifecycle acceptance contract.
* Persist POSPal's stable order number for safe positive reconciliation.
* Quarantine ambiguous transport and success-without-order-number outcomes instead of blindly replaying them.
* Add provider-readiness and behavioural POSPal outbox contracts to the strict verifier.
* Add schema 1.16.0 for the indexed POSPal remote reference.

= 2.23.0 =
* Add a live-data WordPress SEO fallback, full social previews, and crawlable Menu and Catering landing pages.
* Add a consent-gated Meta/TikTok commerce event bridge with a strict no-PII allowlist and simulated-demo isolation.
* Replace the catering artwork with real-alpha menu-based mini manoush and pie compositions shared by the demo and WordPress.
* Refine product motion into a replayable lift/explode/assemble sequence without scroll-direction reversal.

= 2.22.2 =
* Add the complete item-specific WordPress menu options from the reviewed demo, including Zaatar styles and mix, pizza sauces and crusts, pie sesame defaults, extras, removals, lemon and chilli.
* Recalculate every selected option and price on the server before storing the cart line.
* Import the corrected 33-item catalogue and ensure a fresh single-shop activation seeds the Revesby location.
* Build and validate installable plugin ZIPs consistently across Windows, macOS and Linux.

= 2.22.1 =
* Add a WordPress-native browse-only launch mode with configurable "Online ordering coming soon" copy.
* Keep menus and carts viewable while removing checkout, vouchers and browser payment initialization whenever ordering is closed.
* Default fresh installations to ordering closed and require an explicit owner action to accept orders.
* Add `[doughboss_ordering_status]` for page builders, block pages and theme templates.
* Add real plugin activation, menu import, shortcode, REST and checkout-gate tests on WordPress 6.0.9/PHP 7.4 and WordPress 7.0.2/PHP 8.4.

= 2.22.0 =
* Replace the unverified legacy MPGS Tyro path with current Tyro Connect Pay: OAuth, Pay Requests, direct Tyro.js, 3DS-ready browser flow, signed thin webhooks, and refunds.
* Add durable payment attempts and webhook event de-duplication without storing card data, OAuth tokens, raw webhook payloads, or Tyro pay secrets.
* Add fail-closed per-shop Tyro Connect location and POSPal store mapping plus a live certification gate.
* Add universal Tyro checkout for storefront/table QR and catering deposit/balance payments.
* Add an original, reduced-motion-aware manoush and catering Bites/mini-manoush ingredient assembly experience to the viewable demo.
* Add schema 1.15.0 and payment integration readiness checks.

= 2.21.0 =
* Add per-store dining tables with opaque, rotatable QR bearer codes stored only as hashes.
* Bind a scanned table to a fresh cart and expiring HttpOnly session; revalidate store, table, and QR state before payment and checkout.
* Add locked dine-in customer flow, required customer name, prominent KDS/table ticket display, and immutable order snapshots.
* Add a manager-only Tables & QR screen with same-site menu links and locally rendered printable QR labels.
* Add schema 1.14.0 and MariaDB coverage for hash-only storage, authoritative routing, and rotation invalidation.

= 2.20.0 =
* Make checkout replay durable with server-bound idempotency keys and database-enforced uniqueness.
* Prevent the same provider payment reference from creating more than one order under concurrent requests.
* Add a fail-closed 1.13.0 migration that preserves historical payment evidence and surfaces duplicates for operator reconciliation.
* Reuse the same browser checkout attempt and verified payment reference after an interrupted response.
* Add MariaDB 10.6 and 11.4 migration and concurrency coverage for checkout integrity.

= 2.19.0 =
* Add the disabled-by-default, time-zone-aware pickup capacity planning engine.
* Add transactional schedule, slot and hold storage with durable per-slot locking.
* Add deterministic Sydney DST, notice, blackout and capacity-boundary tests.

= 2.18.0 =
* New: versioned, forward-only order lifecycle with optimistic concurrency so a
  stale staff screen cannot overwrite a newer kitchen update.
* New: transactional order event history and UTC lifecycle timestamps, including
  staff-estimated ready windows and customer-safe status wording.
* Change: kitchen board, staff console and WordPress order screen now use the
  server-approved next actions; order cancellation is manager-only.
* Change: customer tracking shows truthful shop status, payment wording and ready
  collection cues. Payment provider activation remains unchanged and optional.
* Safety: the 1.11.0 schema migration verifies InnoDB lifecycle storage and fails
  closed with an owner notice when atomic order history cannot be guaranteed.

= 2.17.0 =
* New: **Single-location / pickup-only mode.** When exactly one shop is active,
  the owner can enable a guarded `single_location_mode` setting that hides the
  shop picker and delivery toggle, rejects delivery server-side, and pins every
  order to that sole shop. Multi-shop sites fail closed and cannot enable it.
* New: **Storefront rebrand.** Snow Boss is retired; the "Snow Boss" section
  is now "Offers & News" (Dough Boss only, single Instagram follow gate).
  The "Locations" tab is renamed "Contact Us" (backend data model unchanged).
  Catering is a contact-only block until the online quote flow ships.
* Change: voucher campaign `dough5` (prefix `DOUGH-`) replaces `snow5` (prefix
  `SNOW-`); the legacy `snow5` campaign is dormant but every voucher already
  issued under it stays redeemable at the till.
* Data: 1.10.0 migration enables `single_location_mode` only for sites with no
  more than one active shop and turns delivery off there. Multi-shop sites are
  seeded with the mode off and their delivery setting is left alone.

= 2.16.0 =
* New: **POSPal push outbox** — online orders rejected by POSPal with an explicit
  retryable error are now retried automatically on a durable
  outbox with exponential backoff (60s / 5 min / 30 min, capped at 5 tries).
  A cron worker owns dispatch under an atomic pending → in_flight claim, so
  concurrent local sweeps share one row. Every transport error or abandoned
  in-flight request is treated as ambiguous: it stops for operator review and
  is not retried until staff confirm the order is absent from the till.
  Fully off unless "Push online orders" is enabled.
* New: **Failed-push visibility** — wp-admin surfaces a dismissible notice
  when orders exhaust their retry budget or are still retrying after several
  attempts. Explicit failures may be retried in bulk; ambiguous transport or
  abandoned-worker outcomes require a per-order till check and confirmation.
* New: **Hourly POSPal outbox maintenance** — successful rows are retained for
  30 days, then pruned. Automatic remote re-push is deliberately disabled until
  dispatch persists POSPal's stable `orderNo`; this prevents an empty or
  ambiguous lookup from duplicating a till order.
* New: **Visual POSPal product mapping** — a settings-page table that loads
  POSPal's catalogue and auto-matches menu items by name, replacing the
  previous WP-CLI-only setup for mapping menu items to POSPal product uids.
* Change: signing helper `DoughBoss_POSPal::sign()` is now the single
  signature implementation used by every call, with a known-vector smoke
  test that breaks loudly if the wire format ever drifts.
* Data: new `{prefix}doughboss_pospal_outbox` table (DB v1.9.0); dbDelta-safe.

= 2.15.0 =
* New: **Reports** admin page (DoughBoss → Reports) — revenue, order count,
  average order value, top-selling items and a pickup/delivery split for any
  date range, with a CSV download.
* New: **Stripe webhook reconciliation** — a `/doughboss/v1/stripe-webhook`
  endpoint (signature-verified) catches a card charge that succeeds but never
  became an order, and surfaces it in a "Payment issues" panel on the Orders
  screen for the owner to resolve. No money is ever auto-refunded.
* New: **Refund from the Orders screen** — a card-paid Stripe order can be
  refunded in one click (owner-only, guarded against double-refund).
* New: **Privacy Tools** — order/catering/voucher data is now covered by
  WordPress's built-in personal-data export and erase tools (Australian
  Privacy Act / GDPR), redacting personal details while keeping records for
  accounting.
* New: read-only `/doughboss/v1/status` health endpoint (admin-gated).
* Performance: the Live Order Board and Orders list now load order items in a
  single batched query instead of one query per order; POSPal and SMS calls
  no longer make checkout wait on slow external services.
* Security: the request rate limiter is now atomic (closes a concurrency race);
  developer diagnostic endpoints are hidden unless WP_DEBUG is on; CSV exports
  are guarded against spreadsheet formula injection.
* Data: database datetime columns migrated off the legacy `0000-00-00` default;
  new index on the catering payment-intent lookup; catering enquiries reject
  past dates and cap guest counts; location slugs de-duplicate on create.
* Demo site: brand colours reconciled and legal pages made mobile-responsive;
  kitchen board honours "reduce motion" and meets colour-contrast standards;
  accessibility and loading-state polish across the storefront and Staff Console.

= 2.14.0 =
* New: **DoughBoss → Message Templates** — an owner-only screen to edit the
  exact wording of the order-confirmation email (subject + body) and the two
  SMS messages ("order ready", "voucher claimed"), with placeholder tokens
  like `{order_number}` and `{total}`. Leaving a field blank restores the
  built-in default text. Saves via its own handler (a true partial update),
  so it can never affect any other setting.
* Fix: the checkout form (and its Stripe card field, when enabled) no longer
  gets rebuilt every time the cart changes — quantity edits, removing a
  line, or applying a voucher used to silently clear whatever the customer
  had already typed, including a card number mid-entry.
* Fix: a crash in the order-confirmation renderer that could leave a
  successful order looking like an error to the customer.
* Security: Stripe's secret key and webhook secret can now be set via
  environment variable / wp-config.php constant, matching the pattern
  already used for POSPal, Mercure, ntfy, ClickSend and the receipt printer.

= 2.13.0 =
* Change: the **$10 student voucher tier has been retired** — the Dough Boss ×
  Snow Boss launch voucher is now **$5 only**. Removed from the default
  campaign, the POSPal coupon-rule mapping (Settings → POSPal, all stores),
  and the storefront demo.
* Security: fixed a site-wide CORS regression — the plugin no longer removes
  WordPress's default REST CORS handling for every other route on the site.
* Security: catering enquiry status changes (paid/confirmed/lost) now require
  owner-level access, matching the same boundary already used for vouchers —
  a kitchen/KDS till login can no longer change catering payment status.
* Fix: `create_payment_intent` now checks the shop's open/closed and
  delivery/pickup settings before charging a card, matching `/checkout`.
* Fix: corrected customer-facing copy that incorrectly claimed an unverified
  card charge "will be reversed automatically."
* Fix: the currency-code setting no longer falls back to USD when unset.
* Security: added rate limiting to the three payment-intent routes.

= 2.12.1 =
* New: **one-click "Import standard menu"** button (DoughBoss → Settings → Menu) —
  creates the full board menu (Manoush, Pizza, Pies, Wraps, Desserts, Drinks; 27
  items with prices, categories and dietary flags) with no WP-CLI needed. Safe to
  re-run; shared seeder used by both the button and `wp doughboss seed-menu`.
* New: **Staff session (days)** setting — keep logged-in users signed in for a set
  number of days (e.g. 3650) so shop tablets never time out. 0 = WordPress default.

= 2.12.0 =
* New: **Order notification email** setting (DoughBoss → Settings → Store) — new
  order and catering-enquiry emails go to this shop inbox (defaults to the Dough
  Boss orders inbox; blank falls back to the site admin email). Filterable via
  `doughboss_orders_email`.
* New: **`wp doughboss seed-menu`** WP-CLI command — populate the menu (items,
  prices, categories, dietary flags) from the in-store boards in one idempotent
  step (`--dry-run` supported). Matches items by title, so re-running updates
  rather than duplicates.
* Fix: saving Settings no longer drops the order-notification email.
* The marketing/demo site was rebuilt around the current menu (Manoush, Pizza,
  Pies, Wraps, Desserts, Drinks) with a Mediterranean brand refresh.

= 2.5.0 =
* New: **card payments via Stripe** (optional, off by default). Enable it under
  DoughBoss → Settings → Payments and add your keys; start in **Test** mode with
  test keys, then switch to **Live**. When on, customers pay by card at checkout
  before the order is placed.
* Security: payments are verified **server-side** — the order is only accepted as
  paid once Stripe confirms a PaymentIntent that matches the order’s
  server-computed amount and currency, and each PaymentIntent can be used for at
  most one order. Secret keys never leave the server; Stripe.js loads only when
  payments are configured. Orders now record payment status, method and intent.
* No change for sites that don’t enable payments: checkout works exactly as before.

= 2.4.0 =
* New: **per-item availability** — mark any menu item “sold out” from the item
  editor or with a one-tap row action on the Menu Items list. Sold-out items
  stay on the menu greyed out with a badge, the Add button is disabled, and the
  server rejects adding them to a cart (so a stale tab can’t order one).
* New: **storefront shop picker** — a `[doughboss_shop_picker]` shortcode and a
  selector in the cart let customers choose which shop they’re ordering from on
  multi-shop sites; the choice is remembered and routes the order to that
  shop’s kitchen board. Single-shop sites are unaffected (nothing extra shown).
* The Menu Items list now shows Price and Availability columns.

= 2.3.1 =
* Order board now shows a persistent “Sound is OFF” warning and auto-resumes the
  alert audio when the tablet refocuses — a reloaded kitchen tablet can no
  longer sit silently through new orders.
* Default order currency fallback corrected to AUD.

= 2.3.0 =
* New: **Australian money** — defaults to AUD and supports **GST-inclusive
  pricing** (tax shown as a component of the price, e.g. total / 11 at 10%,
  rather than added on top). A “Prices include GST” setting controls it.
* Storefront shows GST as “(includes GST $X)” under the total when inclusive.
* On upgrade, a demo US (USD, no tax) config is localised to AUD + 10% GST
  without overwriting a store that was deliberately configured.

= 2.2.0 =
* New: **multi-shop foundation** — a Shops / Locations admin screen (add/edit
  shops with suburb, address, phone, delivery postcodes, prep time and
  pickup/delivery options).
* Orders now carry a `location_id`; the Live Order Board has a **per-shop
  filter** so each shop's kitchen tablet sees only its own orders.
* New REST endpoint `GET /locations`; `GET /admin/orders` and `/checkout`
  accept a `location_id`. A default shop is created on upgrade so existing
  single-shop sites keep working unchanged.

= 2.1.0 =
* New: real-time **Live Order Board** (kitchen display) — active orders in
  New / Preparing / Ready lanes, an audible + visual alert on new orders until
  acknowledged, and one-tap Accept (with ETA) and status changes.
* New: low-privilege **DoughBoss Kitchen** role + `manage_doughboss_kds`
  capability so a shop tablet can run the board without a full admin login.
* New: REST endpoints `GET /admin/orders`, `POST /admin/order/{id}/ack`,
  `POST /admin/order/{id}/accept`; orders now carry an ETA and
  seen/acknowledged/accepted timestamps.
* Reliability: order + line items are now written in a single database
  transaction (no more partial orders), order numbers are longer with
  collision-retry, and `/checkout` honours an `Idempotency-Key` to stop
  duplicate orders from double-submits.
* Internal: versioned database migration runner.

= 2.0.0 =
* Initial public build: menu CPT, pizza builder, cart/checkout, order tracking,
  admin orders screen and settings.
