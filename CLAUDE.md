# CLAUDE.md — repo context for future sessions

## What this repo is

**DoughBoss** — a self-contained **WordPress plugin** (not a connector, not a
standalone app). It turns a WordPress site into a pizza/food ordering
storefront: menu management, a custom pizza builder, cart/checkout, and order
tracking, all rendered via shortcodes plus a small REST API. Verified from the
plugin header in `doughboss.php` (`Plugin Name: DoughBoss`, `Version: 2.0.0`,
`Requires at least: 6.0`, `Requires PHP: 7.4`, GPL-2.0-or-later) and
`readme.txt` (the WordPress.org-format readme).

Current version: **2.0.0** ("Initial public build" per the `readme.txt`
changelog). Payments are **not** implemented yet — the FAQ in `readme.txt`
says orders are recorded and the store is notified, suited to "order now, pay
on pickup/delivery"; Stripe integration is listed as a roadmap item in
`README.md`. There is no account system — carts are tied to a cookie token
(guest checkout only).

### Shortcodes (from `readme.txt` / `class-doughboss-shortcodes.php`)
- `[doughboss_menu]` — the menu grid
- `[doughboss_builder]` — the custom pizza builder
- `[doughboss_cart]` — cart and checkout
- `[doughboss_order_tracking]` — order status lookup by order number + email

### REST API
All endpoints live under `…/wp-json/doughboss/v1` (the namespace is defined as
`DOUGHBOSS_REST_NAMESPACE` in `doughboss.php`). State-changing calls require
the standard WordPress REST nonce; per `README.md`, pricing is always
recomputed server-side. Endpoints (documented in `README.md`, implemented in
`includes/class-doughboss-rest-controller.php`): `GET /config`, `GET /menu`,
`GET /cart`, `POST /cart/add`, `POST /cart/update`, `POST /cart/remove`,
`POST /cart/clear`, `POST /checkout`, `GET /order/{number}?email=`,
`POST /admin/order/{id}/status`.

## Directory layout

```
doughboss.php                          Plugin bootstrap: header, constants
                                        (DOUGHBOSS_VERSION, DOUGHBOSS_DB_VERSION,
                                        DOUGHBOSS_REST_NAMESPACE, …), activation/
                                        deactivation hooks, boots DoughBoss::instance()
                                        on `plugins_loaded`.
uninstall.php                          Runs only on plugin deletion from wp-admin;
                                        drops the custom DB tables and deletes menu
                                        items, options, capabilities, cart transients.
readme.txt                             WordPress.org-format plugin readme (used to
                                        build the plugin listing on wordpress.org
                                        if ever published there).
README.md                              Human-facing repo readme — feature list,
                                        install instructions, shortcode table, REST
                                        API table, project layout, roadmap.
build-zip.sh                           Packages the shippable files into
                                        dist/doughboss.zip (top-level `doughboss/`
                                        dir) for Plugins → Add New → Upload Plugin.
includes/
  class-doughboss.php                  Core loader / dependency wiring.
  class-doughboss-activator.php        DB schema creation, defaults, capabilities
                                        (runs on activation).
  class-doughboss-deactivator.php      Deactivation hook (no data removal — that's
                                        uninstall.php's job).
  class-doughboss-settings.php         Typed access to plugin settings (sizes,
                                        toppings, currency, tax, fees).
  class-doughboss-post-types.php       Registers the `doughboss_item` Menu Items
                                        CPT, its category taxonomy, and meta boxes.
  class-doughboss-cart.php             Cookie/transient-based guest cart.
  class-doughboss-order.php            Orders data model backed by custom DB
                                        tables (`wp_doughboss_orders`,
                                        `wp_doughboss_order_items`).
  class-doughboss-rest-controller.php  All `/wp-json/doughboss/v1/*` REST routes.
  class-doughboss-shortcodes.php       Registers the four shortcodes above.
  class-doughboss-assets.php           Front-end script/style enqueue + JS
                                        localization (config/nonce handoff).
admin/
  class-doughboss-admin.php            wp-admin Orders screen (status updates) +
                                        Settings page.
public/
  css/doughboss.css                    Storefront styles.
  css/doughboss-admin.css              Admin screen styles.
  js/doughboss.js                      Storefront front-end app (vanilla JS, no
                                        build step — served as-is).
```

There is no `languages/` directory yet, even though `doughboss.php` declares
`Domain Path: /languages` — `build-zip.sh` includes it only if present.

## Build / packaging

`bash build-zip.sh` stages the shippable files (`doughboss.php`,
`uninstall.php`, `readme.txt`, `README.md`, `includes/`, `admin/`, `public/`,
and `languages/` if present) into `dist/doughboss/` and zips it to
`dist/doughboss.zip`, ready for **Plugins → Add New → Upload Plugin**. `dist/`
and `*.zip` are gitignored — never commit build output. There is no other
build step: the JS/CSS in `public/` are committed as-is, not compiled.

## Workflow

- No test suite, linter, or CI configured in this repo (no `.github/`
  workflows, no `phpunit.xml`, no `package.json`). If that changes, document
  the validation commands here.
- Feature branches: `claude/<slug>`, PR'd to `main`.
- Sanity-check PHP changes with `php -l <file>` (syntax-only; there is no
  WordPress test harness here) before opening a PR, and re-run `build-zip.sh`
  if you need to verify the packaged zip still installs cleanly.

## Notes for future sessions

- This is plugin code that runs *inside* WordPress — there's no server to
  start or "connect" to for local testing; verifying behavior requires an
  actual WordPress install with the plugin activated.
- Prices are computed server-side in the REST controller/cart/order classes —
  keep that invariant when touching pricing logic (per `README.md`: "Prices
  are always computed server-side").
- `uninstall.php` performs full data removal (DB tables + menu items +
  options + capabilities) — be careful not to widen what it deletes without
  checking it matches what the activator actually creates.
