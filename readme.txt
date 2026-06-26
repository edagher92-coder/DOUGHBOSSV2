=== DoughBoss ===
Contributors: doughboss
Tags: pizza, food ordering, menu, restaurant, ecommerce
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 2.12.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pizza & food ordering for WordPress: menu management, a custom pizza builder, online ordering and order tracking.

== Description ==

DoughBoss turns any WordPress site into a pizza/food ordering storefront. It adds:

* A **Menu Items** custom post type with categories, prices and images.
* A **custom pizza builder** where customers choose a size and toppings with live pricing.
* A **cart and checkout** for pickup or delivery, with configurable tax and delivery fees.
* **Order tracking** so customers can check their order status by order number + email.
* An **Orders** admin screen with live status updates, plus a settings page for sizes, toppings, currency and fees.

Everything is rendered through shortcodes and a small REST API; no theme changes are required.

= Shortcodes =

* `[doughboss_menu]` — the menu grid.
* `[doughboss_builder]` — the custom pizza builder.
* `[doughboss_cart]` — the cart and checkout.
* `[doughboss_order_tracking]` — the order status lookup form.
* `[doughboss_shop_picker]` — choose which shop to order from (multi-shop sites).

== Installation ==

1. In wp-admin go to **Plugins → Add New → Upload Plugin**.
2. Upload `doughboss.zip` and click **Install Now**, then **Activate**.
3. Go to **DoughBoss → Settings** to configure sizes, toppings, currency, tax and fees.
4. Add menu items under **DoughBoss → Menu Items**.
5. Place the shortcodes above on your pages.

== Frequently Asked Questions ==

= Does this process payments? =

Not yet. Orders are recorded and the store is notified; payment integration
(e.g. Stripe) is planned for a future release. Today it suits "order now, pay
on pickup/delivery" workflows.

= Does it need an account system? =

No. Carts are tied to a cookie token, so guests can order without logging in.

== Changelog ==

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
