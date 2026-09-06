# DoughBoss 🍕

Pizza & food ordering for WordPress — a self-contained plugin that adds menu
management, a custom pizza builder, online ordering (pickup/delivery) and
customer order tracking, all driven by shortcodes and a small REST API.

> **Heads up:** This is a WordPress *plugin*, not a connector. Install it into a
> WordPress site via **Plugins → Add New → Upload Plugin**. There is no live
> service to "connect" — the code runs inside WordPress.

## Features

- **Menu Items** custom post type (`doughboss_item`) with a category taxonomy,
  per-item price and type (pizza / side / drink / standard), featured image and
  a sold-out toggle. The menu is server-rendered with schema.org `Menu` markup.
- **Custom pizza builder** — configurable sizes and toppings with live pricing.
- **Cart & checkout** — cookie-based guest cart (cookie only set once something
  is added), pickup or delivery, tax-inclusive (GST) or tax-exclusive pricing,
  delivery fee, minimum order, "ordering open" switch. Prices are always
  computed server-side and the cart is re-priced against the live menu before
  an order is created.
- **Order tracking** — customers look up an order by number + email.
- **Admin** — an Orders screen (address, notes, phone/email links, status
  counts, search, new-order chime, one-click status changes) and a Settings
  page for sizes, toppings, currency, tax and fulfilment options.
- Confirmation emails to the customer and store, sent after the response is
  flushed so checkout never waits on the mail server.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- MySQL/MariaDB with InnoDB (orders are written transactionally)

## Installation

### From a built zip (recommended)

```bash
# Build an installable zip from the repo root
bash build-zip.sh
```

Then in wp-admin: **Plugins → Add New → Upload Plugin → `doughboss.zip` → Activate**.

### From source

Copy the repository contents into `wp-content/plugins/doughboss/` and activate
**DoughBoss** from the Plugins screen.

### After activation

1. **DoughBoss → Settings**: set the tax rate deliberately (it ships as `0`
   and the admin nags until it is set), currency, delivery fee, minimum order,
   store phone and the URLs of your menu / cart / tracking pages.
2. **DoughBoss → Menu Items**: add items and categories.
3. Place the shortcodes below on pages.

## Shortcodes

| Shortcode                     | Renders                                              |
| ----------------------------- | ---------------------------------------------------- |
| `[doughboss_menu]`            | The menu grid (server-rendered; `heading_level="2–4"`) |
| `[doughboss_builder]`         | The custom pizza builder                             |
| `[doughboss_cart]`            | The cart and checkout                                |
| `[doughboss_order_tracking]`  | The order status lookup form                         |
| `[doughboss_cart_button]`     | Cart badge (count + total); hidden while empty       |

A typical setup: an **Order Online** page containing `[doughboss_builder]` and
`[doughboss_menu]` plus `[doughboss_cart]` (or a dedicated Cart page), a
**Track Order** page containing `[doughboss_order_tracking]`, and
`[doughboss_cart_button]` in the header.

## REST API

All endpoints live under `…/wp-json/doughboss/v1`. State-changing calls require
the standard WordPress REST nonce (`X-WP-Nonce`); pricing is recomputed on the
server for every cart/checkout operation. Every cart endpoint returns the full
cart (`{ items, totals }`).

| Method | Endpoint                          | Purpose                                                 |
| ------ | --------------------------------- | ------------------------------------------------------- |
| GET    | `/config`                         | Sizes, toppings, currency, tax, fees (publicly cacheable) |
| GET    | `/nonce`                          | Fresh REST nonce for pages served from a full-page cache |
| GET    | `/menu`                           | Published menu items (cached, publicly cacheable)       |
| GET    | `/cart`                           | Current cart (`no-store`)                               |
| POST   | `/cart/add`                       | Add a menu item / custom pizza (returns cart + `added`) |
| POST   | `/cart/update`                    | Change a line quantity                                  |
| POST   | `/cart/remove`                    | Remove a line                                           |
| POST   | `/cart/clear`                     | Empty the cart                                          |
| POST   | `/checkout`                       | Create an order (rate-limited, idempotency key, lock)   |
| POST   | `/order/track`                    | Track an order by number + email (rate-limited)         |
| GET    | `/order/{number}?email=`          | Legacy tracking endpoint (kept for old links)           |
| POST   | `/admin/order/{id}/status`        | Staff status update (`manage_doughboss`)                |
| GET    | `/admin/orders/new-count?since=`  | Staff poll for new orders                               |

Checkout errors carry `data.errors` keyed by field. A `409 doughboss_prices_changed`
response includes the re-priced cart in `data.cart`.

## Tax model

Prices are **tax-inclusive by default** (Australian convention): the menu price
is what the customer pays and the GST component is shown as "Includes GST"
(`base × r / (1 + r)`). Turn **Prices include tax** off for add-on-top tax.
**Tax applies to delivery** decides whether the delivery fee is part of the
taxable base. The tax rate is never guessed — it ships as `0` and must be set.

## Development

```bash
# Syntax-check every PHP file
find . -name '*.php' -print0 | xargs -0 -n1 php -l

# Unit tests for pricing/tax/cart/order numbers — no WordPress, no DB
php tests/run.php

# Storefront JS syntax
node --check public/js/doughboss.js
```

CI (`.github/workflows/ci.yml`) runs the PHP lint + tests on PHP 7.4/8.1/8.3,
the JS syntax check, and builds the zip.

## Project layout

```
doughboss.php                  Plugin bootstrap, constants, activation hooks
includes/
  class-doughboss.php          Core loader / DI, DB upgrade + rewrite flush
  class-doughboss-activator.php   DB schema, defaults, capabilities, manager role
  class-doughboss-deactivator.php
  class-doughboss-settings.php    Typed, memoised settings access (single source of defaults)
  class-doughboss-post-types.php  Menu Items CPT + taxonomy + meta box + menu-version cache key
  class-doughboss-cart.php        Cookie/transient guest cart + tax maths
  class-doughboss-order.php       Orders data model (custom tables, transactional insert)
  class-doughboss-rest-controller.php  REST endpoints, checkout, emails
  class-doughboss-shortcodes.php  Server-rendered menu + app containers
  class-doughboss-assets.php      Front-end enqueue + localization
admin/
  class-doughboss-admin.php       Orders screen + settings page
public/
  css/doughboss.css               Storefront styles
  css/doughboss-admin.css         Admin styles
  js/doughboss.js                 Storefront app (vanilla JS)
tests/
  bootstrap.php                   WordPress function shim
  run.php                         Unit tests (php tests/run.php)
uninstall.php                  Full data removal on delete
```

## Roadmap

- Online payments (Stripe).
- Opening hours / scheduled pickup and delivery time slots.
- Delivery zones (postcode or radius) with per-zone fees.
- Per-item topping support for specialty pizzas.
- Email template customization.

## License

GPL-2.0-or-later.
