# DoughBoss 🍕

Pizza & food ordering for WordPress — a self-contained plugin that adds menu
management, a custom pizza builder, online ordering (pickup/delivery) and
customer order tracking, all driven by shortcodes and a small REST API.

> **Heads up:** This is a WordPress *plugin*, not a connector. Install it into a
> WordPress site via **Plugins → Add New → Upload Plugin**. There is no live
> service to "connect" — the code runs inside WordPress.

## Features

- **Menu Items** custom post type (`doughboss_item`) with a category taxonomy,
  per-item price and type (pizza / side / drink / standard), and featured image.
- **Custom pizza builder** — configurable sizes and toppings with live pricing.
- **Cart & checkout** — cookie-based guest cart, pickup or delivery, configurable
  tax rate and delivery fee. Prices are always computed server-side.
- **Order tracking** — customers look up an order by number + email.
- **Admin** — an Orders screen with live status changes and a Settings page for
  sizes, toppings, currency, tax and fulfilment options.
- Confirmation emails to the customer and store on each order.

## Requirements

- WordPress 6.0+
- PHP 7.4+

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

## Shortcodes

| Shortcode                   | Renders                          |
| --------------------------- | -------------------------------- |
| `[doughboss_menu]`          | The menu grid                    |
| `[doughboss_builder]`       | The custom pizza builder         |
| `[doughboss_cart]`          | The cart and checkout            |
| `[doughboss_order_tracking]`| The order status lookup form     |

A typical setup: an **Order Online** page containing `[doughboss_builder]` and
`[doughboss_menu]` plus `[doughboss_cart]` (or a dedicated Cart page), and a
**Track Order** page containing `[doughboss_order_tracking]`.

## REST API

All endpoints live under `…/wp-json/doughboss/v1`. State-changing calls require
the standard WordPress REST nonce (`X-WP-Nonce`); pricing is recomputed on the
server for every cart/checkout operation.

| Method | Endpoint                       | Purpose                          |
| ------ | ------------------------------ | -------------------------------- |
| GET    | `/config`                      | Sizes, toppings, currency, fees  |
| GET    | `/menu`                        | Published menu items             |
| GET    | `/cart`                        | Current cart                     |
| POST   | `/cart/add`                    | Add a menu item / custom pizza   |
| POST   | `/cart/update`                 | Change a line quantity           |
| POST   | `/cart/remove`                 | Remove a line                    |
| POST   | `/cart/clear`                  | Empty the cart                   |
| POST   | `/checkout`                    | Create an order                  |
| GET    | `/order/{number}?email=`       | Track an order                   |
| POST   | `/admin/order/{id}/status`     | Staff status update (capability) |

## Project layout

```
doughboss.php                  Plugin bootstrap, constants, activation hooks
includes/
  class-doughboss.php          Core loader / DI
  class-doughboss-activator.php   DB schema, defaults, capabilities
  class-doughboss-deactivator.php
  class-doughboss-settings.php    Typed settings access
  class-doughboss-post-types.php  Menu Items CPT + taxonomy + meta box
  class-doughboss-cart.php        Cookie/transient guest cart
  class-doughboss-order.php       Orders data model (custom tables)
  class-doughboss-rest-controller.php  REST endpoints
  class-doughboss-shortcodes.php  Shortcode containers
  class-doughboss-assets.php      Front-end enqueue + localization
admin/
  class-doughboss-admin.php       Orders screen + settings page
public/
  css/doughboss.css               Storefront styles
  css/doughboss-admin.css         Admin styles
  js/doughboss.js                 Storefront app (vanilla JS)
uninstall.php                  Full data removal on delete
```

## Roadmap

- Online payments (Stripe).
- Scheduled pickup / delivery time slots.
- Per-item topping support for specialty pizzas.
- Email template customization.

## License

GPL-2.0-or-later.
