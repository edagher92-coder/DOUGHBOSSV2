=== DoughBoss ===
Contributors: doughboss
Tags: pizza, food ordering, menu, restaurant, ecommerce
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 2.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pizza & food ordering for WordPress: menu management, a custom pizza builder, online ordering and order tracking.

== Description ==

DoughBoss turns any WordPress site into a pizza/food ordering storefront. It adds:

* A **Menu Items** custom post type with categories, prices, images and a sold-out toggle.
* A **custom pizza builder** where customers choose a size and toppings with live pricing.
* A **cart and checkout** for pickup or delivery, with tax-inclusive (GST-style) or tax-exclusive pricing, a delivery fee and an optional minimum order.
* **Order tracking** so customers can check their order status by order number + email.
* An **Orders** admin screen (with delivery address and customer notes, a new-order chime, and one-click status changes) plus a settings page for sizes, toppings, currency, tax and fees.

Everything is rendered through shortcodes and a small REST API; no theme changes are required. The menu is server-rendered with schema.org markup so search engines see real items and prices.

= Shortcodes =

* `[doughboss_menu]` — the menu grid (accepts `heading_level="2|3|4"`).
* `[doughboss_builder]` — the custom pizza builder.
* `[doughboss_cart]` — the cart and checkout.
* `[doughboss_order_tracking]` — the order status lookup form.
* `[doughboss_cart_button]` — a cart badge (count + total) for headers; hidden while the cart is empty.

== Installation ==

1. In wp-admin go to **Plugins → Add New → Upload Plugin**.
2. Upload `doughboss.zip` and click **Install Now**, then **Activate**.
3. Go to **DoughBoss → Settings** to configure sizes, toppings, currency, tax and fees. Set the tax rate deliberately — it ships as 0 and the admin screen reminds you until it is set.
4. Add menu items under **DoughBoss → Menu Items**.
5. Place the shortcodes above on your pages, then enter the cart/menu/tracking page URLs in Settings so the storefront can link between them.

== Frequently Asked Questions ==

= Does this process payments? =

Not yet. Orders are recorded and the store is notified; payment integration
(e.g. Stripe) is planned for a future release. Today it suits "order now, pay
on pickup/delivery" workflows. The "Payment note" setting lets you tell
customers how payment works.

= Does it need an account system? =

No. Carts are tied to a cookie token, so guests can order without logging in.
The cookie is only set once something is added to the cart.

= Are prices shown inclusive of tax? =

By default, yes (Australian consumer-pricing convention): the price on the
menu is what the customer pays, and the GST component is shown as
"Includes GST". Switch "Prices include tax" off for add-on-top tax.

= Who can manage orders? =

Administrators, plus any user given the **DoughBoss Manager** role added on
activation (it carries only the `manage_doughboss` and menu-item capabilities).

== Changelog ==

= 2.5.0 =
* Orders screen now shows the delivery address and customer notes (previously
  collected but never displayed to staff), phone/email links, an "Active"
  default view with per-status counts, search across number/name/email/phone,
  local-timezone timestamps, an "Email failed" flag, and a new-order chime.
* Status changes report real failures and revert instead of showing a false
  "saved".
* Tax model: prices are tax-inclusive by default with a configurable label
  (GST) and an option for whether delivery is taxable; tax rate ships as 0
  with an admin reminder rather than a guessed figure. Currency defaults to
  AUD.
* Checkout: per-field validation errors, minimum order, an "ordering open"
  switch, cart re-pricing against the live menu before an order is created,
  rate limiting, an idempotency key, and an atomic lock so a double-click
  cannot create two orders. Orders and their items are written in one
  transaction.
* Order numbers use the site's timezone and an unambiguous character set;
  uniqueness is enforced by the database with a retry.
* Confirmation emails are sent after the response is flushed (with a cron
  fallback), so checkout no longer waits on the mail server.
* Cart cookie is only created when something is added; the token is
  validated rather than lower-cased on read (fixes lost first item on hosts
  with a persistent object cache).
* Menu is server-rendered with schema.org Menu markup, cached, and thumbnails
  are primed in one query; menu items get a sold-out toggle.
* REST: GET /nonce for cached pages, POST /order/track (email no longer in
  the URL), public cache headers on /menu and /config, no-store on /cart.
* Menu Items use their own capabilities (`edit_doughboss_items` etc.) and a
  DoughBoss Manager role is added.
* Storefront rebuilt: accessible builder and cart (labels, live regions,
  focus management), order confirmation persists, checkout form keeps its
  values when the cart changes, mobile layout, dark-mode aware, reduced
  motion respected.
* Unit tests for pricing/tax/cart/order numbers run without WordPress
  (`php tests/run.php`); CI lints PHP and JS and builds the zip.

= 2.0.0 =
* Initial public build: menu CPT, pizza builder, cart/checkout, order tracking,
  admin orders screen and settings.
