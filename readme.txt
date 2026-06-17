=== DoughBoss ===
Contributors: doughboss
Tags: pizza, food ordering, menu, restaurant, ecommerce
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 2.3.0
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
