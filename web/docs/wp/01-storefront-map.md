# 01 Storefront map: menu data, locations, imagery, theme, SEO and front-end architecture

Slice owner: storefront, menu data, locations, imagery, SEO, front-end architecture.
Read-only audit. Live comparison retrieved **2026-10-02** from the public https://doughboss.com.au (GET only; no login, no forms, no orders, no admin paths, no provider APIs).

## 0. How to read this document

- **B** = `/tmp/wp-src/baseline-2.41.0` (git worktree labelled plugin 2.41.0 / theme 1.4.0). **C** = `/tmp/wp-src/candidate-2.43.2` (plugin 2.43.2 / theme 1.6.2, reviewed, not installed). A path with no prefix is relative to the worktree; where a file is byte-identical in B and C it is cited once and marked "same in B and C". Line numbers are C unless a `B:` prefix is given.
- **OBSERVED** = read in code or fetched from the live site. **INFERRED** = my reasoning from observed facts. "Not found" says where I looked.
- **LIVE** = a public GET on 2026-10-02: `/wp-json/doughboss/v1/{config,menu,locations,catering/packages}`, `/wp-json/` (index), `/`, `/menu/`, `/locations/`, `/robots.txt`, `/wp-sitemap*.xml`, and seven static theme/plugin assets (for the byte comparison in section 1).
- No secrets, tokens or customer data were read or are reproduced. Option names only.
- Prices are AUD, GST-inclusive (section 3.4).

## Summary: the findings that most change what we build

1. **The "baseline-2.41.0" worktree is not byte-identical to the live site.** Five live front-end files (`doughboss.js`, `doughboss.css`, `doughboss-manoush-hero.css`, theme `style.css`, theme `assets/theme.js`) differ from B, and the live homepage lacks the hero `<link rel="preload" as="image">` that B's shortcode emits (B: `includes/class-doughboss-shortcodes.php:88-104`). Same version labels (`?ver=2.41.0`, theme 1.4.0), different bytes; B is ahead of live. Section 1.
2. **Live menu prices are till-aligned, not the seeder's.** Live `/menu` returns 43 items; the seeder (`includes/class-doughboss-menu-seeder.php:35-79`) holds 34. Six prices differ, 11 drink items replace two generic ones. `ops/README.md` records a 2026-09-07 reconciliation to the POSPal till. The table in 3.6 is the verified source for current prices; the seeder must not be re-run on live (3.5).
3. **The live admin repricing scripts silently wiped data.** The same six repriced items now have empty dietary flags and empty descriptions on live (and the 11 split drinks have empty descriptions). `ops/scripts/align_prices.py:109-124` and `split_drinks.py:131` post a classic-editor form with no `doughboss_dietary[]` and blank/unparsed content, and `includes/class-doughboss-post-types.php:342-344` writes an empty flag array when the field is absent. This is INFERRED but matches the live data exactly.
4. **No exploding-pizza hero exists in either worktree, and it was deliberately removed.** The hero is a real photograph with scroll parallax and a steam effect (`public/js/doughboss-manoush-hero.js`, `public/css/doughboss-manoush-hero.css:1`: "No generated food layers"). `readme.txt:152-153` (2.34.0) records replacing the "generated floating-food homepage treatment" with approved real merchant photography. Earlier versions did ship a lift/explode/assemble scene (`readme.txt:167-190`, `readme.txt:350-355`). Any new explosion should use real photographed layers and be flag-gated.
5. **"Online ordering is Revesby-only" is not enforced by the live data.** All three live locations return `pickup_enabled: true` (LIVE `/locations`) and `single_location_mode` is `false` (LIVE `/config`) because the mode only takes effect with exactly one active shop (`includes/class-doughboss-locations.php:104-110`). Today ordering is globally off (`ordering_open: false`), so nothing is orderable, but flipping that switch would make Bankstown and Roselands orderable unless their `pickup_enabled` flags are cleared. Only the after-hours pre-order is hard-pinned to Revesby (`includes/class-doughboss-rest-controller.php:4132-4142`).
6. **Docs and live disagree on ordering state.** `docs/REVIEW-20260908.md:23-39` and `ops/README.md:103` say ordering was open (pickup, Revesby only, 43 items). LIVE `/config` on 2026-10-02 says `ordering_open: false`, message "Online ordering coming soon. Please call (02) 9774 2286 to place an order." and three active shops. Something changed after the docs; the cause is not in the repo.
7. **Hours exist in three unsynchronised places.** DB table `doughboss_location_hours` (drives JSON-LD and, in C, `pickup_status`), hard-coded copy in `themes/doughboss-final/template-parts/locations.php:4,9,14`, and the Next.js catalogue (`web/src/lib/data/catalogue.ts:45,64,82`). All four currently agree with the known public hours (section 4.4). The catalogue sets `acceptsOnline: true` for all three shops (`:43,62,80`), which contradicts the Revesby-only ordering evidence. Checkout does not enforce hours (not found in `includes/class-doughboss-rest-controller.php`).
8. **Images: 23 of 43 live menu items use "real-v1" photographs; 20 use older 300x300 or 500x500 WebP files** (all 11 drinks share one `juice.webp`; three veggie items share `veggie-plus.webp`). Provenance is documented only as changelog prose (`readme.txt:152-154`); no file carries camera EXIF or C2PA data. Real photos are small (mostly 550x440); the hero is 1080x864. Section 5.
9. **SEO is a fallback layer, not a local-SEO build.** `includes/class-doughboss-seo.php` emits title, description, OG/Twitter, one JSON-LD graph (Organization, WebSite, one `Bakery`/`Restaurant` node per shop). All shop nodes share `url` = the homepage; there is no `geo`, `sameAs`, `hasMenu`, `OrderAction`, FAQ, Service/Offer or per-shop landing page. Each of the 43 menu items and 4 catering packages is a public, sitemapped single-post URL rendered by the generic `single.php` with a date eyebrow and no meta description. Section 7.
10. **ES5 is a convention, not enforced.** Every shipped JS file is ES5 syntax today (my scan found no arrow, `const`, `let`, template literal or `class` outside comments), but CI only runs `node --check` and dependency-free tests (`.github/workflows/plugin-ci.yml:37-49`, `scripts/test-release.ps1:37-54`). Runtime APIs such as `Promise`, `fetch`, `Number.isFinite` and `CustomEvent` are used freely. Section 8.2.
11. **The theme templates have no extension hooks.** `themes/doughboss-final/front-page.php` hard-codes its sections and never calls `the_content()`; the theme defines no `do_action`. A new homepage block needs a theme edit or a separate page that uses `page.php`. The 4 MB plugin zip exceeds the host's documented 2 MB upload cap (`docs/VISUAL-PREVIEW-20260908.md:239`), whereas the theme zip is about 31 KB. Section 8.5.
12. **Features present on live today (baseline behaviour) are fewer than the candidate's.** Dietary badges, the header pickup-status selector, responsive AVIF/WebP, the mobile customiser sheet and SEO for template pages are candidate-only. No dietary filter, waitlist or Minis teaser exists in either tree. Section 9.

---

## 1. Reality check: live site versus the "baseline-2.41.0" worktree

OBSERVED. I fetched the public static assets the live pages reference and compared bytes with B and C.

| Asset (live URL path under `/wp-content/`) | Live bytes / lines | B bytes / lines | Result |
| --- | --- | --- | --- |
| `plugins/doughboss/public/js/doughboss-manoush-hero.js` | 2,555 / n/a | same | identical to B and C |
| `plugins/doughboss/public/js/doughboss-order-page.js` | 2,400 | same | identical to B (file absent in C: removed in 2.43.x, `docs/INTERACTION-POLISH-20260908.md`, section "Astra design stages 1-2") |
| `plugins/doughboss/public/js/doughboss.js` | 88,103 / 2,055 | 95,388 / 2,213 | live older: 32 live-only lines, 190 B-only lines (e.g. B adds the `role/tabindex/for/list` attribute fix at `B:public/js/doughboss.js:67-72`; live still has `h3` category headings and `h4` item sub-headings) |
| `plugins/doughboss/public/css/doughboss.css` | 46,997 / 2,119 | 49,194 / 2,201 | live older (B adds 44px touch targets, `.db-card-body h3` rule) |
| `plugins/doughboss/public/css/doughboss-manoush-hero.css` | 6,014 / 268 | 6,484 / 275 | live older (live primary button `#e52a21`, B `#d81f16`; B adds `overflow: clip`) |
| `themes/doughboss-final/style.css` | 29,011 / 339 | 32,143 / 377 | live older (live lacks `--dbf-ember-bright`, `--dbf-ember-ink`; header says Version 1.4.0 in both) |
| `themes/doughboss-final/assets/theme.js` | 3,487 / 91 | 4,551 / 112 | live older (live lacks the sticky-header `--db-sticky-top` publisher, `B:themes/doughboss-final/assets/theme.js:73-93`) |

Other live-versus-B differences:

- Live `/` HTML contains **no** `<link rel="preload"` (count 0). B emits one once per request (`B:includes/class-doughboss-shortcodes.php:88-104`).
- Live `/menu/` has a self-referential canonical (`https://doughboss.com.au/menu/`); C consolidates it to `/order/` (`themes/doughboss-final/functions.php:321-327`).
- Live `/` loads Contact Form 7 CSS and two scripts although no form is rendered there; C dequeues them where no form exists (`themes/doughboss-final/functions.php:338-359`, comment: about 29 KB).
- `ops/README.md:12-35` records that live runs WPCode hotfix snippets #533 (security headers), #534 (vouchers admin fatal), #535 (`[hidden]` CSS) and #537 (mobile nav drawer) on top of the plugin/theme build, so live behaviour is "older build plus snippets".

INFERRED consequence: treat B as "the 2.41.0 source branch that is ahead of what is deployed", not as a mirror of production. Anything compared with live must be re-checked against the live bytes; the label 2.41.0 / 1.4.0 therefore covers two different byte sets, so version strings alone cannot identify a build or bust caches.

Tests I ran in a scratch copy under `/tmp` (not in the worktrees): `php tests/run.php` C: 412 assertions, 412 passed (PHP 8.3.6); B: 94 passed; `node --test tests/storefront-helpers.test.js` C: 27 tests, 27 passed. (`php -n` without the ctype extension fails two POSPal assertions; that is an environment effect, not a code defect.)

---

## 2. Live REST surface relevant to the storefront

OBSERVED from LIVE `/wp-json/` (namespaces: `oembed/1.0`, `contact-form-7/v1`, `wpvibe/v1`, `doughboss/v1`, `llar/v1`, `wp/v2`, `wp-site-health/v1`, `wp-block-editor/v1`, `wp-abilities/v1`). Public GET routes I used:

| Route | Result | Code |
| --- | --- | --- |
| `GET /doughboss/v1/config` | 200, 1,068 bytes | `includes/class-doughboss-rest-controller.php:2522-2562` |
| `GET /doughboss/v1/menu` | 200, 43 items | same file `:3053` (B `:2943`) |
| `GET /doughboss/v1/locations` | 200, 3 shops, no `pickup_status` (2.41.0 shape) | C `:3000` (B `:2894`); `includes/class-doughboss-locations.php:429-449` (B `:429-442`) |
| `GET /doughboss/v1/catering/packages` | 200, 4 packages | `:4669` |
| `GET /doughboss/v1/status` | 403 `doughboss_forbidden` | admin gate |
| `/wp/v2/doughboss_item`, `/wp/v2/doughboss_cat_pkg`, `/wp/v2/doughboss_category` | listed in the index (GET and POST; I did not call them; GET returns published items) | CPT `show_in_rest` true (`includes/class-doughboss-post-types.php:105-107`) |

The public index also advertises admin, kitchen, voucher, POSPal, Stripe/Tyro/MPGS and print route names (all permission-gated). Not a storefront defect, but worth knowing for any later hardening.

LIVE `/config` (verbatim fields, no secrets): `currency_symbol "$"`, `currency_code "AUD"`, `tax_rate 10`, `gst_inclusive true`, `delivery_fee 5`, `enable_pickup true`, `enable_delivery false`, `single_location_mode false`, `single_location_id 0`, `ordering_open false`, `after_hours_preorders_enabled false`, `payments_enabled false`, `payment_gateway "stripe"`, `mercure.enabled false`, plus `sizes` and `toppings` (section 3.3).

---

## 3. Menu data and prices

### 3.1 Storage model (OBSERVED)

| Concern | Where | Detail |
| --- | --- | --- |
| Menu item | CPT `doughboss_item` (`includes/class-doughboss-post-types.php:17`, registered `:100-116`) | `public` true, `show_in_rest` true, `has_archive` false, rewrite slug `menu` (so each item has `/menu/<slug>/`), capabilities all `manage_doughboss` except read. Same in B and C. |
| Category | taxonomy `doughboss_category` (`:18`, `:118-140`) | hierarchical, slug `menu-category`. Live terms: Manoush, Pizza, Pies, Wraps, Desserts, Drinks (LIVE `wp-sitemap-taxonomies-doughboss_category-1.xml`). `/menu` takes the first term as the category (`includes/class-doughboss-rest-controller.php:3066`). |
| Price | post meta `_doughboss_price` (`:20`) | number, 2dp, never negative (`sanitize_price` `:236-239`). Exposed read-only in `/wp/v2` with an auth callback for writes. |
| Type | meta `_doughboss_item_type` (`:21`) | `standard`, `pizza`, `side`, `drink` (`:277-283`). Seeder uses `standard`/`pizza`/`drink`. |
| Availability | meta `_doughboss_available` (`:22`) | `'0'` = sold out, anything else available (`is_available` `:49-51`). One-tap toggle in the list table (`:385-426`). |
| Dietary | meta `_doughboss_dietary` (`:23`) | array; allowed `vegetarian`, `vegan`, `gluten_free`, `halal` (`dietary_options` `:58-65`). |
| Seed markers | meta `_doughboss_seed` = `v1`, `_doughboss_seed_key` | `includes/class-doughboss-menu-seeder.php:122,223-224`. |
| Description | post content (or excerpt) | `/menu` returns `wp_strip_all_tags( excerpt or content )` (`rest-controller:3075`). Entities are not decoded (see Sujuk Special in 3.6). |
| Image | featured image else bundled file by name/category | `rest-controller:3067` and `menu_image_url` `:3098-3150` (section 5). |
| Options / modifiers | **not stored**: computed in code | `DoughBoss_Menu_Options::for_item( $category, $name )` (`includes/class-doughboss-menu-options.php:193-231`), same in B and C. Keyed on category and exact title, so renaming an item silently drops its options. |
| Sizes and toppings (custom pizza builder) | option `doughboss_settings` keys `sizes`, `toppings` | `includes/class-doughboss-settings.php:187-188` (defaults empty), getters `:532-550`. Option name `doughboss_settings` (`:20`). |
| Tax and ordering flags | same option | `tax_rate` (`:74`), `gst_inclusive` (`:75`, getter `:389`), `ordering_open` (`:81`, getter `:591`), `ordering_closed_message` (`:82`, getter `:600`), `single_location_mode` (`:144`). |
| Locations | custom tables `{prefix}doughboss_locations`, `{prefix}doughboss_location_hours` (`weekday`, `segment`, `opens_at`, `closes_at`, `order_type`), `{prefix}doughboss_schedule_exceptions` | `includes/class-doughboss-activator.php:67-74,212-225`. |
| Catering packages | CPT `doughboss_cat_pkg`, rewrite slug `catering-package` | `includes/class-doughboss-catering-package.php:26,83-92`; meta `_doughboss_cat_serves_min/_max`, `_base_price`, `_per_head`, `_deposit_pct`, `_lead_days`, `_includes` (`:28-34`). Same in B and C. |
| POSPal mapping | live option / `ops/state/pospal-product-map.json` | keyed on lowercased, whitespace-collapsed item title; one unmapped item drops the whole order push (`ops/README.md:53-62`). Renaming or adding a menu item therefore needs a map update. |

There is **no** per-item GST class, no SKU field, no allergen field, no nutrition field and no per-location price in the data model (not found in `includes/class-doughboss-post-types.php`, `includes/class-doughboss-menu-options.php`, `includes/class-doughboss-rest-controller.php`).

### 3.2 Option groups and price deltas (OBSERVED, `includes/class-doughboss-menu-options.php:63-184`)

| Group id | Type | Choices (price delta) | Applies to (`for_item` `:193-231`) |
| --- | --- | --- | --- |
| `style` | radio | Flat (default, 0), Folded (0) | Manoush other than Zaatar, Zaatar & Cheese |
| `style` (`zaatar_style`) | radio | Flat (**+0.50**), Folded (default, 0) | Zaatar, Zaatar & Cheese (`:74-82`, `:211-216`) |
| `crust` | radio | Crispy (default, 0), Classic (0), Wholemeal (+2.50), Gluten-free (+3.50) | All Pizza; all Manoush |
| `base_sauce` | radio | Tomato (default), Garlic, BBQ, No sauce (all 0) | All Pizza |
| `sauce_top` | check | Tomato ketchup, Smokey BBQ, Mayo swirl, Peri peri, Spicy sriracha (each +1.50) | All Pizza; all Pies |
| `extra_toppings` | check | Olives +1; Spinach, Garlic sauce, Onion, Mushroom, Capsicum, Tomato +2; Sujuk, Chicken, Meat (lahme), Cheese, Mozzarella, Halloumi, Pepperoni +3 | All Pizza |
| `wrap_extras` | check | Add labneh +2.50, Add cheese +2.50 | Zaatar & Veggie, Labneh Veggie Wrap, Zaatar Veggie Pizza, Labneh Veggie Pizza |
| `remove` | check | No cheese, tomato, olives, onion, mushroom, capsicum, cucumber, lettuce, pickles, meat, sujuk (all 0) | Pizza, Manoush, Wraps |
| `sesame` | radio | No sesame seeds (default), With sesame seeds (0) | All Pies |
| `lemon_chilli` | check | Lemon, Chilli (0) | Pizza, Pies, Manoush, Wraps |

Drinks and Desserts get no options. Selections and deltas are re-resolved server-side before a cart line is stored (`resolve()` `:240-307`), so the browser price is advisory. LIVE `/menu` matches this code (checked for Zaatar, Zaatar & Cheese, Cheese, Zaatar Veggie Pizza, Labneh Veggie Wrap, Chicken Delight, Spinach Pie).

### 3.3 Custom pizza builder (OBSERVED, LIVE `/config`)

Sizes: `medium-12` "Medium (12")" $12.00; `large-16` "Large (16")" $15.00. Toppings: pepperoni $1.50, mushrooms $1.00, extra-cheese $1.50, olives $1.00, onions $0.75. The activator default set also has a Small (10") $9.00 with slugs `small`/`medium`/`large` (`includes/class-doughboss-activator.php:1132-1195`); live has been edited (slugs `medium-12`/`large-16`, no small). The builder is a separate product path from the menu pizzas ($13-$15 plus options). It is shown only when ordering is open (`themes/doughboss-final/page-order.php:45-47`). Whether the builder is a real product Dough Boss wants is unconfirmed.

### 3.4 GST handling (OBSERVED)

- Seeder comment: "Prices are GST-inclusive AUD" (`includes/class-doughboss-menu-seeder.php:27-28`). Settings `tax_rate` 10, `gst_inclusive` 1 (`includes/class-doughboss-settings.php:74-75`); LIVE `/config` returns `tax_rate 10`, `gst_inclusive true`.
- Cart totals (`includes/class-doughboss-cart.php:306-353`, same in B and C): taxable = subtotal minus voucher discount; when inclusive, `total = taxable + delivery_fee` and `tax = round( total * rate / (100 + rate), 2 )`, i.e. total x 10/110. A $11.00 sale carries $1.00 GST; $13.00 carries $1.18.
- INFERRED limits: one flat rate on the whole basket (including delivery fee), no per-item tax class. If any line is GST-free (to be decided with the accountant) the data model cannot express it. Marked `[CONFIRM]`.

### 3.5 The seeder (OBSERVED)

- `DoughBoss_Menu_Seeder::menu_data()` (`:32-81`) holds 34 items: Manoush 9, Pizza 12, Pies 4, Wraps 5, Desserts 1, Drinks 3. Format `[ name, price, type, dietary[], description ]`. Comment `:27-28` asserts "everything is halal" (a data claim, not a certification; `docs/STOREFRONT-COMPLETION-20260908.md:17` says no halal certification claim is inferred).
- `seed( $dry )` (`:89-238`) creates or updates by `_doughboss_seed_key` = `sanitize_title( category . ' ' . name )` (`:122`), with legacy-key and legacy-title fallbacks for three renamed pies/pizza (`:138-141,162-166`), then falls back to exact title match. **It overwrites title, content, `_doughboss_price`, type, availability (`'1'`), dietary and category on every run** (`:202-226`).
- `scripts/seed-menu.php` is a thin wrapper around `DoughBoss_CLI::seed_menu` (`includes/class-doughboss-cli.php:239`); the admin button calls the seeder at `admin/class-doughboss-admin.php:2727`. The seeder and `scripts/seed-menu.php` are byte-identical in B and C.
- INFERRED live risk: re-running it (WP-CLI `wp doughboss seed-menu` or the "Import standard menu" button) would revert the six till-aligned prices, restore the dietary flags and descriptions, set every item available, and recreate "Soft Drinks 600ml" and "Juice" next to the 11 flavour items. That is a second writer of menu data, which the project rule forbids. Remove or neutralise the import path in any extension, or treat the seeder as bootstrap-only.

### 3.6 Menu table: seeder versus LIVE (2026-10-02)

Seeder prices are OBSERVED in code (file:line in column 5; file is `includes/class-doughboss-menu-seeder.php`) and are **not proof of what is live**. Live prices are OBSERVED from `GET /wp-json/doughboss/v1/menu` on 2026-10-02 (43 items, all `available: true`). All prices GST-inclusive. Dietary key: V vegetarian, VG vegan, H halal, GF gluten-free, `-` none flagged. Image: "photo" = bundled `menu/real-v1/*.jpg`; "LEGACY" = older `menu/*.webp` (section 5).

| Cat | Item | Seeder $ | Seeder diet | Seeder file:line | Live id | Live $ | Live diet | Live image | Flag |
| --- | --- | ---: | --- | --- | ---: | ---: | --- | --- | --- |
| Manoush | Zaatar | 4.50 | VG H | seeder:35 | 339 | 4.50 | VG H | photo zaatar.jpg | matches |
| Manoush | Zaatar & Cheese | 8.50 | V H | seeder:36 | 340 | 8.50 | V H | photo zaatar-cheese.jpg | matches |
| Manoush | Cheese | 9.50 | V H | seeder:37 | 341 | 9.50 | V H | photo cheese.jpg | matches |
| Manoush | Meat | 9.00 | H | seeder:38 | 342 | 9.00 | H | photo meat.jpg | matches |
| Manoush | Meat & Cheese | 11.00 | H | seeder:39 | 343 | 11.00 | H | photo meat-cheese.jpg | matches |
| Manoush | Sujuk & Cheese | 11.00 | H | seeder:40 | 456 | 12.00 | - | photo sujuk-cheese.jpg | PRICE differs (live 12.00 vs seeder 11.00); dietary flags lost on live; description empty on live |
| Manoush | Half Meat & Cheese | 11.00 | H | seeder:41 | 457 | 10.00 | - | LEGACY meat-cheese.webp | PRICE differs (live 10.00 vs seeder 11.00); dietary flags lost on live; description empty on live |
| Manoush | Cheese, Tomato & Olives | 9.50 | V H | seeder:42 | 458 | 12.00 | - | LEGACY veggie-plus.webp | PRICE differs (live 12.00 vs seeder 9.50); dietary flags lost on live; description empty on live |
| Manoush | Cheese Kaak | 9.50 | V H | seeder:43 | 459 | 10.00 | - | photo cheese-kaak.jpg | PRICE differs (live 10.00 vs seeder 9.50); dietary flags lost on live; description empty on live |
| Pizza | Zaatar Veggie Pizza | 13.00 | V H | seeder:46 | 460 | 11.00 | - | LEGACY veggie-plus.webp | PRICE differs (live 11.00 vs seeder 13.00); dietary flags lost on live; description empty on live |
| Pizza | Labneh Veggie Pizza | 13.00 | V H | seeder:47 | 461 | 13.00 | V H | LEGACY veggie-plus.webp | matches |
| Pizza | All Meat | 15.00 | H | seeder:48 | 344 | 15.00 | H | LEGACY all-meat.webp | matches |
| Pizza | Sujuk Deluxe | 14.00 | H | seeder:49 | 345 | 14.00 | H | photo sujuk-deluxe.jpg | matches |
| Pizza | Spinach Deluxe | 13.00 | V H | seeder:50 | 346 | 13.00 | V H | photo spinach-deluxe.jpg | matches |
| Pizza | Veggie Plus | 13.00 | V H | seeder:51 | 347 | 13.00 | V H | photo veggie-plus.jpg | matches |
| Pizza | Pepperoni & Cheese | 13.00 | H | seeder:52 | 348 | 13.00 | H | photo pepperoni-cheese.jpg | matches |
| Pizza | Sujuk Special | 15.00 | H | seeder:53 | 349 | 15.00 | H | LEGACY dough-boss-special.webp | description shows literal &amp; |
| Pizza | Chicken & Cheese | 14.00 | H | seeder:54 | 350 | 14.00 | H | photo chicken-cheese.jpg | matches |
| Pizza | BBQ Chicken | 14.00 | H | seeder:55 | 351 | 14.00 | H | photo bbq-chicken.jpg | matches |
| Pizza | Peri Peri Chicken | 14.00 | H | seeder:56 | 352 | 14.00 | H | photo peri-peri-chicken.jpg | matches |
| Pizza | Garlic Prawns | 15.00 | H | seeder:57 | 353 | 15.00 | H | LEGACY garlic-prawns.webp | matches |
| Pies | Spinach Pie | 10.00 | V H | seeder:60 | 462 | 10.00 | V H | photo spinach-pie.jpg | matches |
| Pies | Haloumi | 11.00 | V H | seeder:61 | 355 | 11.00 | V H | photo haloumi-pie.jpg | matches |
| Pies | Dough Boss Pie | 11.00 | H | seeder:62 | 463 | 11.00 | H | photo dough-boss-pie.jpg | matches |
| Pies | Aged Cheese | 10.00 | V H | seeder:63 | 357 | 10.00 | V H | photo aged-cheese-pie.jpg | matches |
| Wraps | Zaatar & Veggie | 8.50 | VG H | seeder:66 | 358 | 8.50 | VG H | photo zaatar-veggie-wrap.jpg | matches |
| Wraps | Labneh Veggie Wrap | 8.50 | V H | seeder:67 | 464 | 9.00 | - | photo labneh-veggie-wrap.jpg | PRICE differs (live 9.00 vs seeder 8.50); dietary flags lost on live; description empty on live |
| Wraps | Chicken Delight | 14.00 | H | seeder:68 | 359 | 14.00 | H | photo chicken-delight-wrap.jpg | matches |
| Wraps | Ultimate Chicken | 14.00 | H | seeder:69 | 360 | 14.00 | H | LEGACY ultimate-chicken.webp | matches |
| Wraps | Dough Boss Wrap | 14.00 | H | seeder:70 | 361 | 14.00 | H | LEGACY dough-boss-wrap.webp | matches |
| Desserts | Choco Banana | 13.00 | V H | seeder:73 | 362 | 13.00 | V H | photo choco-banana.jpg | matches |
| Drinks | Spring Water | 3.50 | - | seeder:76 | 363 | 3.50 | - | photo spring-water.jpg | matches |
| Drinks | Soft Drinks 600ml | 5.00 | - | seeder:77 | not on live | - | - | - | seeder-only: absent from live (replaced by flavour items) |
| Drinks | Juice | 4.50 | - | seeder:78 | not on live | - | - | - | seeder-only: absent from live (replaced by flavour items) |
| Drinks | Apple Juice | - | - | not in seeder | 544 | 4.50 | - | LEGACY juice.webp | live-only (created via ops/scripts/split_drinks.py:73-82); description empty |
| Drinks | Lemon Juice | - | - | not in seeder | 545 | 4.50 | - | LEGACY juice.webp | live-only (created via ops/scripts/split_drinks.py:73-82); description empty |
| Drinks | Lemon & Mint Juice | - | - | not in seeder | 546 | 4.50 | - | LEGACY juice.webp | live-only (created via ops/scripts/split_drinks.py:73-82); description empty |
| Drinks | Orange & Mango Juice | - | - | not in seeder | 547 | 4.50 | - | LEGACY juice.webp | live-only (created via ops/scripts/split_drinks.py:73-82); description empty |
| Drinks | Orange & Passion Juice | - | - | not in seeder | 548 | 4.50 | - | LEGACY juice.webp | live-only (created via ops/scripts/split_drinks.py:73-82); description empty |
| Drinks | Orange Juice | - | - | not in seeder | 549 | 4.50 | - | LEGACY juice.webp | live-only (created via ops/scripts/split_drinks.py:73-82); description empty |
| Drinks | Coke 600ml | - | - | not in seeder | 550 | 5.00 | - | LEGACY juice.webp | live-only (created via ops/scripts/split_drinks.py:73-82); description empty |
| Drinks | Coke Zero 600ml | - | - | not in seeder | 551 | 5.00 | - | LEGACY juice.webp | live-only (created via ops/scripts/split_drinks.py:73-82); description empty |
| Drinks | Coke Vanilla 600ml | - | - | not in seeder | 552 | 5.00 | - | LEGACY juice.webp | live-only (created via ops/scripts/split_drinks.py:73-82); description empty |
| Drinks | Sprite 600ml | - | - | not in seeder | 553 | 5.00 | - | LEGACY juice.webp | live-only (created via ops/scripts/split_drinks.py:73-82); description empty |
| Drinks | Fanta 600ml | - | - | not in seeder | 554 | 5.00 | - | LEGACY juice.webp | live-only (created via ops/scripts/split_drinks.py:73-82); description empty |

**Counts.** Live: Manoush 9, Pizza 12, Pies 4, Wraps 5, Desserts 1, Drinks 12 = 43. 25 of 43 carry at least one dietary flag; 18 carry none (12 drinks plus the six items listed below). No item carries `gluten_free` on either side; gluten-free exists only as a $3.50 crust option (`includes/class-doughboss-menu-options.php:91`). Prices range $3.50 (Spring Water) to $15.00 (All Meat, Sujuk Special, Garlic Prawns).

**Differences from the seeder (all OBSERVED):**

| Item | Seeder | Live | Likely cause |
| --- | --- | --- | --- |
| Sujuk & Cheese | $11.00 | $12.00 | Till price (`ops/state/pospal-mapping-analysis.md` table 4: till "Soujouk & Cheese" $12) |
| Half Meat & Cheese | $11.00 | $10.00 | Till "1/2 Meat 1/2 Cheese" $10 |
| Cheese, Tomato & Olives | $9.50 | $12.00 | Till "Cheese Tomato Olives" $12 (largest gap, $2.50) |
| Cheese Kaak | $9.50 | $10.00 | Till $10 |
| Zaatar Veggie Pizza | $13.00 | $11.00 | Till "Zaatar & Veggie Pizza" $11 |
| Labneh Veggie Wrap | $8.50 | $9.00 | Till "Labneh & Veggie Roll" $9 |
| Soft Drinks 600ml $5, Juice $4.50 | 2 generic items | absent; 11 flavour items | `ops/scripts/split_drinks.py:72-85` (6 juices at $4.50, 5 soft drinks at $5.00, every price "from the till") |

`ops/README.md:111-113` states the website prices were reconciled to the till on 2026-09-07 ("six items differed, in both directions"). That is exactly the six rows above, so I treat the live prices as **till-derived**. INFERRED: the six repriced items then lost their dietary flags and description text, because `ops/scripts/align_prices.py:113-124` submits the classic editor form without `doughboss_dietary[]` and with `content` taken from a `<textarea id="content">` regex (`:109,118`), and `save_meta` clears flags when the field is absent (`includes/class-doughboss-post-types.php:342-344`). The 11 split drinks also have empty descriptions (`split_drinks.py:131` posts `"content": ""`). Restoring the six flags and descriptions is a data repair, not a code change.

Other live observations: Sujuk Special's description contains a literal `&amp;` (the JS inserts it with `textContent`, so customers would see "&amp;"); Spinach Pie is $10 on both sides while the till mapping analysis flags it as ambiguous (till has Spinach Pie $9 and Spinach & Cheese Pie $10; `ops/state/pospal-mapping-analysis.md:64-67`).

### 3.7 Dietary flags: state and claims

- Menu cards render saved flags only, as closed-allowlist badges "V Vegetarian", "VG Vegan", "Halal", "GF Gluten-free" (`public/js/doughboss.js:451-468`, `:1045-1053`, CSS `public/css/doughboss.css:359-366`). **Candidate only**: B has no `dietaryBadges` (grep). Live therefore shows no badges even though `/menu` returns `dietary`.
- There is **no dietary filter** in either tree; the only filtering is text search and the category jump bar (`public/js/doughboss.js:470-660`).
- Claim hygiene: `docs/STOREFRONT-COMPLETION-20260908.md:17` records "no halal certification or nut-free claim is inferred". The seeder nonetheless marks every food item halal. A halal claim in marketing copy needs owner confirmation of certification: `[CONFIRM]`.
- Six live food items are currently unflagged only because of the repricing side effect (3.6); they should not be read as "not vegetarian".

### 3.8 Catering packages (LIVE `/catering/packages`, 2026-10-02)

Prices are flat per package (`per_head` 0 for all four), `deposit_pct` 30, `lead_days` 2, no images. OBSERVED.

| Id | Name | Serves | Price | Includes (verbatim from the live field) |
| ---: | --- | --- | ---: | --- |
| 538 | Morning Tea - 2 Dozen Minis | 8-12 | $46 | 1 dozen mini zaatar ($20) + 1 dozen mixed minis ($26). 24 pieces total. |
| 539 | Office Platter - 3 Dozen Minis | 12-18 | $72 | 1 dozen mini zaatar ($20) + 1 dozen mixed ($26) + 1 dozen cheese pizza ($26). 36 pieces total. |
| 540 | Function Spread - 5 Dozen Minis | 20-30 | $124 | 1 dozen zaatar ($20) + mixed, meat, cheese pizza and spinach at $26 each. 60 pieces total. |
| 541 | Fried Sambousek - 2 Dozen | 8-12 | $56 | 1 dozen fried meat sambousek ($28) + 1 dozen fried cheese sambousek ($28). 24 pieces total. |

These are the only "Minis" with a price on the website. The till also has $2.50 "Mini" SKUs and "...DOZ" packs ($20-$35) that are not on the website (`ops/state/pospal-mapping-analysis.md:104,133-135`). The package page URLs are `/catering-package/<slug>/` and are public and sitemapped (section 7).

---

## 4. Locations

### 4.1 Model and public output (OBSERVED)

- `DoughBoss_Locations` (`includes/class-doughboss-locations.php`): `all( $active_only )` `:37-43`, `get()` `:51-57`, `is_valid()` `:65-68`, `single_location_id()` `:104-110`, `sanitize()` `:118-158`, `weekly_hours()` `:247-261`, `save_weekly_hours()` `:270-313`, `ensure_default()` `:398-421`, `public_view()` `:429-449` (B `:429-442`).
- Per-location columns written by `sanitize()`: `name`, `slug`, `suburb`, `address`, `phone`, `postcodes`, `prep_time_default` (default 20), `timezone` (default `Australia/Sydney`), `capacity_mode` (`off`/`shadow` only), `slot_minutes` 15, `minimum_notice_minutes` 30, `booking_horizon_days` 7, `hold_minutes` 10, `slot_order_capacity` 4, `slot_unit_capacity` 12, `tyro_location_id`, `pospal_store_index`, `online_payment_enabled`, `pickup_enabled`, `delivery_enabled`, `is_active`, `sort_order` (`:134-157`). Hours are separate weekly rows (`doughboss_location_hours`) with split-session support; dated exceptions are a separate table and are treated as blackouts only (C `:430-692`).
- `ensure_default()` seeds only a single "Revesby" row (12/25 Selems Parade, (02) 9774 2286) when the table is empty and the blog name contains "dough boss" (`:398-421`). Bankstown and Roselands were created by hand on live.
- Public `/locations` fields (both versions): `id`, `name`, `slug`, `suburb`, `address`, `phone`, `pickup_enabled`, `delivery_enabled`, `prep_time`, `timezone`, `capacity_preview`. **C adds `pickup_status`** (schedule-only state `open`/`closes_soon`/`closed`/`unavailable`/`unknown` with `observed_at_utc`, `expires_at_utc`, `next_open_label`; `:429-449`, calculator `:460-692`) and a `Cache-Control: no-store` header (`rest-controller:3000-3010`). Live does not return it yet.

### 4.2 LIVE `/locations` (2026-10-02)

| Id | Name | Address (live) | Phone | `pickup_enabled` | `delivery_enabled` | `prep_time` | `capacity_preview` |
| ---: | --- | --- | --- | --- | --- | ---: | --- |
| 1 | Revesby | Shop 12/25 Selems Parade, Revesby NSW 2212 | 0297742286 | true | false | 20 | false |
| 2 | Bankstown | 462 Chapel Road, Bankstown NSW 2200 | 0287646783 | true | false | 20 | false |
| 3 | Roselands | Shop MM03, Roselands Drive, Roselands NSW 2196 | 0466353133 | true | false | 20 | false |

### 4.3 Hours: where they live

| Source | Revesby | Bankstown | Roselands |
| --- | --- | --- | --- |
| DB `doughboss_location_hours`, as emitted in live JSON-LD (LIVE `/`, 2026-10-02) | Mon-Sun 06:30-14:30 | Mon-Fri 07:00-14:00 | Mon-Sun 08:00-15:00 |
| Hard-coded theme copy (`themes/doughboss-final/template-parts/locations.php:4,9,14`) | "7 days, 6:30am-2:30pm" | "Monday-Friday, 7am-2pm" | "Daily, 8am-3pm" |
| Known public facts (brief) | daily 6:30am-2:30pm | Mon-Fri 7am-2pm | daily 8am-3pm |

All three sources agree. Two consequences: the theme copy is not driven by the database (a change in wp-admin would update JSON-LD and `pickup_status` but not the locations page), and the theme names the third shop "Roselands Centro" while the database row is "Roselands" (a name mismatch the next.js catalogue resolves as "Roselands Centro"). Public holidays and dated exceptions: no rows are visible publicly, so unknown; `[CONFIRM]` with the owner.

### 4.4 Comparison with the known public facts

| Fact in brief | Code/live evidence | Verdict |
| --- | --- | --- |
| Revesby 12/25 Selems Parade NSW 2212, daily 6:30am-2:30pm | live address "Shop 12/25 Selems Parade, Revesby NSW 2212"; hours as above | matches (live adds "Shop") |
| Bankstown 462 Chapel Rd NSW 2200, Mon-Fri 7am-2pm | "462 Chapel Road, Bankstown NSW 2200"; Mon-Fri 07:00-14:00 | matches |
| Roselands Centro Shop MM03 Roselands Dr NSW 2196, daily 8am-3pm | "Shop MM03, Roselands Drive, Roselands NSW 2196"; daily 08:00-15:00 | matches |
| Online ordering is Revesby-only | Public copy: "Online pickup will launch from Revesby first" (`themes/doughboss-final/page-locations.php:2`); B card copy "Online pickup launching here first" (Revesby) and "Visit or call, online ordering later" (others) (`B:themes/doughboss-final/template-parts/locations.php:4,9,14`); after-hours pre-order hard-pinned to Revesby (`rest-controller:4132-4142`). **But** all three shops are active with `pickup_enabled` true and `single_location_mode` is false on live. | public copy yes; **enforcement not in the data** (finding 5) |

### 4.5 Ordering and pickup rules (OBSERVED)

- Order location resolution (`rest-controller:4158-4187`): with exactly one active shop it is chosen automatically; with several, a valid active `location_id` is required (`doughboss_location_required`); `pickup_enabled`/`delivery_enabled` are enforced per shop. There is no per-shop "online ordering" flag other than those two plus `is_active`.
- `single_location_mode` default 1 (`settings:144`; migration `includes/class-doughboss-migrations.php:437-470` sets it only for 0 or 1 active shops) is honoured only when exactly one shop is active (`locations:104-110`); the front end also trims to the first location when the flag is on (`public/js/doughboss.js:293-311`).
- Opening hours, `minimum_notice_minutes` and slot settings are **not** applied in the checkout path (grep of `includes/` outside `class-doughboss-locations.php` finds them only in the activator, SEO JSON-LD and admin form). `pickup_status` is advisory text ("Availability confirmed at checkout", `public/js/doughboss-shop-status.js:28`) and capacity stays `off`/`shadow` (`locations:127-132`).
- After-hours pre-order (`POST /preorder-request`, `rest-controller:3402`; setting `after_hours_preorders_enabled`, default 0 at `settings:131-136`) is unpaid, unconfirmed and Revesby-only; LIVE `/config` reports it disabled.
- Delivery is off everywhere (`delivery_fee` 5 is configured but `enable_delivery` false).
- The Next.js catalogue and the WordPress plugin must not both claim the same shop-level ordering state; the WP location row is the only current machine-readable source of per-shop pickup enablement.

---

## 5. Imagery

### 5.1 Assets (OBSERVED, `public/images/`, same in B and C except `responsive/`)

| Group | Files | Format / size | Used for |
| --- | --- | --- | --- |
| Real product photos | `menu/real-v1/*.jpg` (24 files) | JPEG, mostly 550x440 (a few 500x333 to 782x440), 10-65 KB each | menu cards, theme hero and food cards |
| Legacy menu images | `menu/*.webp` (27 files) | WebP, 300x300 (juice and spring water 500x500) | fallback when no `real-v1` mapping exists |
| Homepage/catering hero | `doughboss-feast-real-v1.jpg` | JPEG 1080x864, 329,439 bytes | hero backdrop, About hero, catering panel |
| Social card | `doughboss-social-card.jpg` | JPEG 1200x630, 149,607 bytes | Open Graph/Twitter and JSON-LD image on every page |
| Responsive variants (C only) | `responsive/*.avif`, `*.webp`, `manifest.json` (22 files plus manifest) | AVIF q58 / WebP q75 at 480, 550/960/1080 px | five originals: feast, zaatar-cheese, sujuk-deluxe, haloumi-pie, meat-cheese (`docs/VISUAL-PREVIEW-20260908.md:201-205`) |
| Fonts | `public/fonts/bebasneue-400.woff2`, `barlow-{400,500,600,700}.woff2` | self-hosted woff2 | theme and plugin CSS |

No other formats (no PNG/SVG product art, no video). B has no `responsive/` directory.

### 5.2 How an image is selected (OBSERVED)

- Menu API: a WordPress featured image wins (`get_the_post_thumbnail_url( $id, 'medium' )`, `rest-controller:3067`); otherwise `menu_image_url()` (`:3098-3150`, identical in B at `B:2988`) looks up a hard-coded slug map (e.g. `all-meat` -> `all-meat.webp`, `sujuk-special` -> `dough-boss-special.webp`, `garlic-prawns` -> `garlic-prawns.webp`), then a category fallback (`Drinks` -> `juice.webp`, `Wraps` -> `labneh-veggie-wrap.webp`, ...). **No live item has a featured image**; all 43 URLs are plugin files.
- Cards render the URL as a CSS `background-image` (`public/js/doughboss.js:1020`), so there is no `alt`, no `srcset`, no lazy loading and all 43 images start loading with the grid. LIVE: 31 unique files, about 1.09 MB (23 `real-v1` files about 0.91 MB, 8 legacy files about 0.18 MB).
- Theme images: `doughboss_final_asset_image()` (`themes/doughboss-final/functions.php:79-103`) emits `<img>` with real intrinsic width/height from `wp_getimagesize` and, via `DoughBoss_Images::picture()` (`includes/class-doughboss-images.php:17-58`, C only), wraps it in `<picture class="db-responsive-picture">` with AVIF then WebP `<source>` sets read from `responsive/manifest.json`; it falls back to the unchanged original on any mismatch. B hard-codes `<img src width height>` with fixed attributes (`B:themes/doughboss-final/page-locations.php:2`). Hero: `[doughboss_manoush_hero]` renders a discoverable `<img>` in C (`shortcodes.php:128-158`), a CSS background plus preload in B and live.

### 5.3 Provenance and authenticity (OBSERVED, with INFERRED limits)

- Written provenance is changelog prose only: 2.34.0 "approved real Dough Boss merchant photography ... products without a verified exact photo now use an honest branded placeholder instead of a lookalike" (`readme.txt:152-154`); 2.34.1 "authentic-photo plugin" (`:147-151`); `css/doughboss-manoush-hero.css:1` "Authentic DoughBoss photographic hero. No generated food layers."; file names carry `real-v1`.
- File-level evidence: very little. None of the JPEGs carries camera make/model or C2PA data (ImageMagick `identify -verbose`); eight carry only an EXIF stub or ICC profile (`chicken-delight-wrap`, `choco-banana`, `haloumi-pie`, `labneh-veggie-wrap`, `peri-peri-chicken`, `spinach-pie`, `zaatar-veggie-wrap`, `juice`). `menu/real-v1/juice.jpg` (500x333; not referenced by any live item because the drink names no longer match its lookup key `juice`) has XMP `CreatorTool: Adobe Photoshop CS6 (Windows)` with a `DerivedFrom` link, i.e. an edited derivative of another document; that is not proof of its origin. I visually spot-checked six images: they look like cut-out food photography on white; I cannot establish who photographed them or the licence.
- **Inconsistency (INFERRED):** the 2.34.x text says unverified products get a placeholder, but `menu_image_url()` still maps nine food products to the older WebP set (All Meat, Sujuk Special, Garlic Prawns, Ultimate Chicken, Dough Boss Wrap, Half Meat & Cheese, plus `veggie-plus.webp` reused for Cheese Tomato & Olives, Labneh Veggie Pizza and Zaatar Veggie Pizza) and all 11 flavour drinks share one `juice.webp` (20 of 43 items in total). The "freshly made" placeholder tile (`public/js/doughboss.js:1021-1024`, CSS `doughboss.css:331-335`) only appears when the URL is empty, which the category fallbacks prevent. One legacy image I opened (All Meat) shows a pepperoni-and-cheese pizza, not the described pepperoni/sujuk/chicken/BBQ topping; I did not verify the others. `[CONFIRM]` with Elie which legacy images are genuine product photos before they are reused in the Next.js site.
- Resolution limits: real photos are small (550x440). Reuse above about 550 CSS px wide, or on retina hero areas, will look soft. The 1080x864 hero is stretched to 104% of a hero up to 1240 px wide (`css/doughboss-manoush-hero.css:25-40`).
- The theme alt text calls images "Real Dough Boss ..." (`themes/doughboss-final/front-page.php:29,55-57`), a claim that depends on the provenance above.

---

## 6. Theme `doughboss-final`

### 6.1 Templates and page set (OBSERVED)

C theme 1.6.2 (`style.css:6`), B 1.4.0. Template files: `front-page.php`, `header.php`, `footer.php`, `page.php`, `page-about-us.php`, `page-catering.php`, `page-franchising.php`, `page-wholesale.php` (both partner pages via `template-parts/partner-page.php`), `page-locations.php` (+ `template-parts/locations.php`), `page-menu.php` (a one-line `require` of `page-order.php`), `page-order.php`, `page-track-order.php`, `page-track.php` (alias), `page-vouchers.php`, `single.php`, `archive.php`, `search.php`, `index.php`, `404.php`.

Live published pages (LIVE `wp-sitemap-posts-page-1.xml`): `/`, `/menu/`, `/locations/`, `/about-us/`, `/catering/`, `/wholesale/`, `/franchising/`, `/terms-conditions/`, `/privacy-policy/`, `/kitchen/`, `/order/`, `/track-order/`, `/vouchers/`, `/student-vouchers/`. Which template each uses follows the slug: `student-vouchers`, `terms-conditions` and `privacy-policy` fall to `page.php` (INFERRED from template hierarchy). `/kitchen/` appears in the public sitemap although the portal sends `noindex` (`includes/class-doughboss-portals.php:305,417`): INFERRED a stale published page or rewrite collision; worth removing from the sitemap.

Shortcodes by page (OBSERVED):

| Page | Shortcodes rendered by the template |
| --- | --- |
| Home | `[doughboss_manoush_hero variant="home" ...]` (`front-page.php:8`) |
| Order and Menu | `[doughboss_ordering_status]`, `[doughboss_shop_picker]`, `[doughboss_menu]`, and only when `ordering_open`: `[doughboss_builder]`, `[doughboss_cart]` (`page-order.php:3-48`) |
| Catering | `[doughboss_manoush_hero variant="catering" ...]`, `[doughboss_catering]` (`page-catering.php:2,4`) |
| Track order | `[doughboss_order_tracking]` (`page-track-order.php:3`) |
| Vouchers | `[doughboss_voucher_claim]` (`page-vouchers.php:41-42`) |
| About, Locations | none (static markup; Locations uses `template-parts/locations.php`) |
| Rewards | `[doughboss_loyalty]` exists (`includes/class-doughboss-shortcodes.php:35`) but no live page was found in the sitemap |

All ten registered shortcodes: `doughboss_menu`, `doughboss_builder`, `doughboss_cart`, `doughboss_order_tracking`, `doughboss_shop_picker`, `doughboss_catering`, `doughboss_voucher_claim`, `doughboss_manoush_hero`, `doughboss_ordering_status`, `doughboss_loyalty` (`:26-35`). Shortcodes render only empty containers with `data-doughboss-*` attributes and a "Loading ..." status; `public/js/doughboss.js` hydrates them from REST (`:2554-2573`). Exception: `[doughboss_catering]` and `[doughboss_voucher_claim]` render real server markup.

### 6.2 Header, navigation, footer (OBSERVED)

- Header (`header.php`): skip link (`:11`), a launch bar when ordering is closed (`:13`: "Online ordering is paused - browse our menu or visit a shop"; B text "Online ordering is coming soon"), brand, a menu toggle with `aria-expanded`/`aria-controls` (`:23`), `wp_nav_menu( theme_location 'primary' )` with a hard-coded fallback list (`functions.php:368-384`), a header CTA ("Order now" when open, "Browse menu" when paused), and in C a `[data-doughboss-header-shop]` strip filled by `doughboss-shop-status.js` (`:37`).
- Mobile drawer: fixed `.dbf-nav` at 900 px, focus moved in, background `inert` + `aria-hidden` while open, Escape closes, Tab trap, exact attribute restoration (`assets/theme.js:5-131`). Never put `filter`, `backdrop-filter`, `transform` or containment on `.dbf-header` itself (`style.css:71-77`, `docs/ASTRA-DESIGN-BRIEF-20260908.md:36`; live hotfix #537 exists because blur once collapsed the drawer, `ops/README.md:42-51`).
- Footer: static location names, Explore links, phone and two mailto links (`footer.php`), Instagram `@doughboss`. No social, review or newsletter widgets beyond the Google review CTA (`front-page.php:84`).

### 6.3 Hero markup and the existing scroll-driven animation (OBSERVED)

Not an exploding-pizza. It is a photographic hero with parallax.

- Markup: `<section class="db-manoush-hero db-manoush-hero--home" data-db-manoush-hero data-db-manoush-variant="home" data-db-scroll-scene>` containing the backdrop image, `.db-mh-steam` (three decorative spans, `home` variant only), `.db-mh-copy` (kicker, `h2`, paragraph, two `a` buttons, a pause/resume `button`), and `.db-mh-proof` ("Since 2009", "Three shops", "Oven-baked"). C: `includes/class-doughboss-shortcodes.php:69-117`. The page `h1` is a visually hidden element in the theme (`front-page.php:5`); the visible title is an `h2`. `data-db-scroll-scene` is vestigial: no CSS or JS selects it (grep).
- Technology: vanilla JS, no library, no canvas, no WebGL. `public/js/doughboss-manoush-hero.js` (72 lines, identical in B, C and live): a passive `scroll`/`resize` handler schedules one `requestAnimationFrame` paint that writes two CSS custom properties, `--db-mh-photo-y` (progress x 18 px) and `--db-mh-photo-scale` (1.035 + 0.025 x |progress|), on the hero while it is in view (`:23-42`). CSS applies `transform: translate3d(0, var(--db-mh-photo-y), 0) scale(var(--db-mh-photo-scale))` with a 100 ms linear transition and `will-change: transform` (`css/doughboss-manoush-hero.css:25-40`). A one-off load animation blurs and fades the photo in over 1.15 s (`:42-49`) and a 6.8 s infinite steam loop runs on the home variant (`:51-81`).
- Controls and degradation: a "Pause photo motion" button toggles `is-photo-paused` and `aria-pressed` (`js:44-55`). If JS fails the image simply shows at its default transform. If the image is slow the hero background is solid `#0a0908`. At 390 px the secondary button is hidden (`css:259-263`).
- Reduced motion: JS stops painting when `prefers-reduced-motion: reduce` matches and reacts to live preference changes (`js:62-69`); CSS removes the transform, the arrival animation and the steam, resets inset to 0 (`css:265-282`). The theme adds a global reduced-motion override (`themes/doughboss-final/style.css:391-400`).
- History (readme changelog): 2.23.0 "replayable lift/explode/assemble" product motion (`readme.txt:350-355`); 2.32.1 "five distinct premium oven-baked products" blow-out (`:194-195`); 2.33.3-2.33.4 repeating blow-out with Start/Pause/Resume (`:167-171`); **2.34.0 replaced the generated floating-food treatment with real photography and photo parallax** (`:152-153`); 2.41.0 added the steam and photo reveal (`:105-109`). The generated layer assets are gone from `public/images/`.

### 6.4 Theme CSS and tokens (OBSERVED)

`style.css` (400 lines in C): `:root` tokens `--dbf-ink #0d0d0d`, `--dbf-char #0a0807`, `--dbf-coal #151210`, `--dbf-paper #f7f5f0`, `--dbf-cream #eee8de`, `--dbf-ember #e2231a`, `--dbf-ember-bright #ec4a41` (small text on dark), `--dbf-ember-ink #c92017` (small text on light), `--dbf-gold #f1a132`, `--dbf-wrap 1240px`, `--dbf-radius 24px`, `--dbf-ease` (`:14-37`). Fonts: Bebas Neue (headings) and Barlow 400/500/600/700, self-hosted woff2, declared both in the theme (inline CSS, `functions.php:128-133`) and the plugin (`public/css/doughboss.css:9-19`). Breakpoints in use: 1000, 900, 767, 720, 560, 420 px (theme); 640, 767, 480, 360, 900, 560 px (plugin storefront). The brief's tokens and the plugin's `--db-*` tokens (`doughboss.css:21-38`) overlap but are separate; the plugin's accent is deliberately monochrome ink (`--db-accent #111111`).

---

## 7. SEO

### 7.1 What exists (OBSERVED; plugin `includes/class-doughboss-seo.php`, same in B and C)

- Owner logic: emits nothing if Yoast (`WPSEO_VERSION`), Rank Math (`RANK_MATH_VERSION`), AIOSEO or SEOPress is active (`:51-56`, `:138`, `:248`). No dedicated SEO plugin is assumed or installed on live (INFERRED: the live pages carry the plugin's own tags).
- Applies only to singular pages containing a DoughBoss shortcode or opted in by the theme filter `doughboss_seo_relevant_page` (`:63-77`; theme `functions.php:177-197`).
- Title and description copy is hard-coded per page group (`:84-115`): catering "Dough Boss Catering | Mini Manoush & Pies Sydney", menu/order "Dough Boss Menu | Manoush, Pizza & Pies Sydney" ("Order pickup from Dough Boss Revesby"), locations, vouchers ("Student Vouchers | Bankstown"), and a default "Fresh-baked manoush, pizza, golden pies, wraps and catering since 2009. Pickup from Revesby."
- Head output (`:247-273`): `meta description`, `meta robots` "index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1", OG type/locale `en_AU`/site name/title/description/url/image 1200x630/alt "Fresh zaatar manoush at Dough Boss", Twitter card `summary_large_image`, one JSON-LD script.
- Theme adds descriptions and OG for `/wholesale/`, `/franchising/`, `/privacy-policy/`, `/terms-conditions/` (`functions.php:208-290`), distinct titles (`:298-306`), and the `/menu/` -> `/order/` canonical (`:321-327`).
- JSON-LD graph (`:189-240`): `Organization` (`name`, `url`, `foundingDate` 2009, `image`), `WebSite` (`name`, `url`, `inLanguage` en-AU, `publisher`), and per active shop a `["Bakery","Restaurant"]` node with `name`, `url`, `telephone`, `image`, `priceRange "$"`, `servesCuisine ["Manoush","Mediterranean","Pizza"]`, `parentOrganization`, `address` (`PostalAddress`, `addressRegion NSW`, `AU`), `hasMap`, and `openingHoursSpecification` (one entry per day range, from the DB hours). LIVE JSON-LD confirms three shop nodes with the hours in 4.3.
- Sitemap: WordPress core `/wp-sitemap.xml` (LIVE) listing posts, pages, `doughboss_item`, `doughboss_cat_pkg`, categories and `doughboss_category`; `robots.txt` (virtual) disallows `/wp-admin/` (allows `admin-ajax.php`) and advertises the sitemap (`seo.php:124-129`; LIVE `robots.txt`). `/sitemap.xml` returns a 301.

### 7.2 Live observations (2026-10-02)

- Duplicate robots meta: core `max-image-preview:large` plus the plugin's longer tag, both present on `/`, `/menu/`, `/locations/`.
- `/menu/` and `/locations/` titles render with the site name appended ("... Sydney - Dough Boss") although the plugin's own title already carries the brand (INFERRED duplicate brand token).
- Canonical is core's self-referential link; `/menu/` and `/order/` are both indexable duplicates on live (C fixes this).
- Home title/description fine; description says "Pickup from Revesby" while ordering is paused.
- Every shop node has `url` = `https://doughboss.com.au/` and `telephone` unformatted (`0297742286`).

### 7.3 Gaps for local SEO and catering (INFERRED from code and live output)

| Gap | Evidence |
| --- | --- |
| No per-shop landing pages or per-shop JSON-LD URLs | `seo.php:216` uses `url => $home`; the locations page is one page with three cards |
| Missing LocalBusiness properties: `geo`, `sameAs`, `hasMenu`/`menu`, `acceptsReservations`, `paymentAccepted`, `areaServed`, `potentialAction` (`OrderAction`), E.164 phone | not found in `seo.php:189-240` |
| No Menu/MenuItem/Offer markup | not found; menu is JS-rendered so the HTML has no item names or prices (LIVE `/menu/` body is "Loading menu...") |
| Menu content invisible to crawlers without JS | LIVE `/menu/` HTML contains only the container |
| Item pages are thin and unsemantic | `single.php:1` shows `get_the_date()` as the eyebrow and `the_content()`; no price, no category, no meta description (item pages are not `is_page`, so the opt-in filter never fires; `functions.php:182`); 43 item URLs and 4 package URLs are in the public sitemap |
| No Service/Offer/FAQPage schema on catering | the catering shortcode has six `<details>` FAQs (`shortcodes.php:267-275`) but no JSON-LD; packages (4 live, prices above) have no schema |
| No review or rating markup; no Google Business Profile link in schema | review CTA only (`front-page.php:84`) |
| Brand/ordering mismatch in copy | descriptions say "Pickup from Revesby" for the whole site |
| No `hreflang`, `llms.txt`, breadcrumbs, image sitemap | not found |
| `/kitchen/` in sitemap although noindex | LIVE sitemap vs `portals.php:305,417` |

Analytics: the plugin ships a consent-gated Meta/TikTok bridge (`public/js/doughboss-marketing.js`, config in `includes/class-doughboss-assets.php:196-219`; constants `DOUGHBOSS_MARKETING_ENABLED`, `DOUGHBOSS_META_PIXEL_ID`, `DOUGHBOSS_TIKTOK_PIXEL_ID`, filter `doughboss_marketing_config`; global `window.DoughBossMarketing.track/setConsent`, event `doughboss:consent`). No GA4, GTM or Google Ads tag exists (not found in `includes/`, `public/js/`, theme). It is enqueued only when the storefront assets load.

---

## 8. Front-end architecture

### 8.1 Organisation and enqueueing (OBSERVED)

- Plugin assets live in `public/css/*.css` and `public/js/*.js`; the storefront stylesheet `doughboss.css` (2,479 lines) is scoped under `.db-app` with `db-*` class names and `--db-*` tokens. Theme: one `style.css` and one `assets/theme.js`, prefix `dbf-`.
- Loader: `includes/class-doughboss-assets.php`. The hero has its own CSS/JS pair loaded on `has_shortcode` or filter `doughboss_load_manoush_hero_assets` (`:135-149`). `doughboss-shop-status.js` loads on the storefront or filter `doughboss_load_shop_status_assets` (C only, `:151-158`, localised `DoughBossShopData.restUrl`). The storefront bundle (`doughboss.css`, `doughboss-marketing.js`, `doughboss.js`) loads when a page contains a storefront shortcode or the filter `doughboss_load_assets` is true (`:89-108,172-262`); the order page adds `doughboss-order-page.css` (`:179-187`; B also `doughboss-order-page.js`), catering and voucher pairs load on their own conditions (`:362-405`). Payment SDKs (Tyro, MPGS, Square) are added only when ordering is open and the gateway is ready (`:233-254`).
- Theme: `style.css` plus inline `@font-face` and `theme.js` (`functions.php:121-139`); theme filters opt template-rendered pages into plugin assets (`:141-164`).
- Localised globals: `DoughBossData` (restUrl, nonce, currency, payments, i18n; `assets.php:295-360`), `DoughBossShopData`, `DoughBossMarketingConfig`, `DoughBossSquareConfig`. Runtime globals: `window.DoughBossMarketing`, `window.DoughBossShopStatus`, `window.DoughBossSquare`. No jQuery, no build step, no bundler.
- Custom events (document level): `doughboss:shop-changed` (detail `{id}`), `doughboss:shop-change-request` (cancelable; the app can veto), `doughboss:cart-updated`, `doughboss:consent`, `doughboss:marketing-event`; form-level `doughboss:checkout-start/-complete/-error`. LocalStorage key `doughboss_location` holds the chosen shop (`public/js/doughboss.js:320-336`).
- REST client: `restRequestUrl()` supports pretty and plain permalinks (`doughboss.js:186-194`); the shop-status script uses public reads with no nonce (`doughboss-shop-status.js:40-58`).

### 8.2 ES5 constraints and how they are enforced

- Convention, stated in code: `public/js/doughboss-square.js:27-28` "ES5 only, matching every other file in public/js/: var + function, no arrow functions, template literals, const/let, class or optional chaining." Elie's rule (ES5 only, no build step) is recorded in the brief, not in a repo document I could find (not found in `README.md`, `ops/README.md`, `themes/doughboss-final/README.md`).
- Scan result (OBSERVED): grep for `=>`, `const`/`let`, backticks, `class X`, `async`, `await` across `public/js/*.js` and `themes/doughboss-final/assets/theme.js` finds hits only in comments (and `s.async = true` in `doughboss-voucher-scan.js:210`, a property). All files use `var` and function expressions inside IIFEs with `'use strict'`.
- Enforcement: **none for ES5.** CI runs PHP lint, `php tests/run.php`, `node --check` on every JS file and the dependency-free `node --test` suites (`.github/workflows/plugin-ci.yml:37-49`; `scripts/test-release.ps1:37-54`). `node --check` accepts ES2015+, so a future arrow function would pass CI. A tiny ES5 syntax check (for example a regex or parser gate with `acorn --ecma5`) is the missing guard; adding it is a tooling change outside my write scope.
- Runtime APIs beyond ES5 are used: `Promise`, `fetch`, `AbortController`, `CustomEvent`, `IntersectionObserver`, `ResizeObserver`, `Number.isFinite`, `Object.assign` (marketing), `NodeList.forEach`, `Element.closest`, `Element.isConnected`. "ES5" therefore means syntax only; evergreen browsers are assumed.
- Tests: `tests/storefront-helpers.test.js` (27 tests, vm-sandboxed extraction of functions from `doughboss.js`, `doughboss-shop-status.js`, `doughboss-catering.js`, `doughboss-square.js`); `tests/test-images.php`; `tests/test-store-hours.php`; `tests/test-menu-options.php`. PHP minimum 7.4 and WordPress 6.0 (`readme.txt:5-6`); note `class-doughboss-images.php:27` uses `??`, valid in 7.4.

### 8.3 How shortcodes render

Server: a shortcode prints a `div.db-app[data-doughboss-*]` with a loading status (`shortcodes.php:288-381`). Client: `boot()` resolves the table-QR context first and then hydrates each container (`doughboss.js:2554-2573`): `renderShopPicker`, `renderMenu`, `renderBuilder`, `renderCart`, `renderTracking`; elements are built with the local `el(tag, attrs, children)` helper and `textContent` (no `innerHTML` for data, `:55-86`). The menu groups by category in the order Manoush, Pizza, Pies, Wraps, Desserts, Drinks (`:482-492`), adds search, a sticky category jump bar and a cart cue (`:496-660`), and `menuCard()` builds each card (`:845-1070`) including a native `details`-based customiser that becomes a labelled sheet on mobile in C (`:731-843`).

### 8.4 Performance state

OBSERVED unless marked.

- Live homepage request set: 4 stylesheets (CF7, hero, `doughboss.css`, theme) and 8 scripts (CF7 x2, WP hooks/i18n, hero, marketing, `doughboss.js` 88 KB, `theme.js`). No `preload`, no `preconnect`, no font preload (counts 0). Hero is a CSS background (329 KB JPEG, discoverable only after the stylesheet) on live; C turns it into an `<img fetchpriority="high">` with AVIF/WebP (`docs/VISUAL-PREVIEW-20260908.md:212` measures 161,675 bytes AVIF 960 vs 329,439 bytes JPEG, about 51% smaller, locally).
- `doughboss.js` loads on the homepage because the theme force-enables storefront assets there (`functions.php:141-145`), even though no menu renders on `/`.
- Menu cards load 31 unique images (about 1.09 MB) with no lazy loading (5.2).
- Fonts are self-hosted with `font-display: swap`.
- No Web Vitals measurements exist (`docs/STOREFRONT-COMPLETION-20260908.md:68`: "production Web Vitals measurements" still required).

### 8.5 How a new self-contained feature should plug in (INFERRED, from the hooks present)

| Option | Mechanism | Fit | Caveat |
| --- | --- | --- | --- |
| Companion plugin shortcode `[doughboss_growth_...]` on a normal WP page | `add_shortcode`; own handles; load via `has_shortcode` or `doughboss_load_*` | Best isolation; the generic `page.php` renders `the_content()` (`page.php:4`), so a new `/minis/` page needs no theme edit | Companion zip is small and fits the host's 2 MB upload cap; the canonical plugin does not (`docs/VISUAL-PREVIEW-20260908.md:239`) |
| New block on the existing homepage | needs a theme change: `front-page.php` hard-codes sections and never calls `the_content()`; the theme defines no `do_action` hook points | requires a theme release (theme zip about 31 KB) adding e.g. `do_action` slots, or a child theme | `DISALLOW_FILE_EDIT` is set on live (`docs/VISUAL-PREVIEW-20260908.md:136`), so install via upload only |
| Hero variant | `[doughboss_manoush_hero]` already takes `variant`, `kicker`, `title`, `description`, `background_image`, button labels/URLs (`shortcodes.php:70-85`) and accepts `photo`/`home`/`catering` only | text and image swaps are free | A new animation should be a separate, dependency-free file (the hero assets deliberately avoid checkout code, `assets.php:132-135`) |
| Asset loading | filters `doughboss_load_assets`, `doughboss_load_manoush_hero_assets`, `doughboss_load_shop_status_assets`, `doughboss_load_catering_assets`, `doughboss_load_voucher_assets`, `doughboss_is_order_page` | safe, additive | filters return booleans only, not handle lists |
| SEO | filter `doughboss_seo_relevant_page` ($relevant, $post); constants for pixels | additive | JSON-LD graph is not filterable (no `apply_filters` around `schema()`); a companion must print its own `application/ld+json` and avoid duplicating `Organization`/`WebSite` ids |
| Data | read `GET /doughboss/v1/menu`, `/locations`, `/config`, `/catering/packages`; never write menu or order tables | respects "no competing writers" | Menu options are code-defined (3.1), so an extension cannot add options without a plugin change |
| Events | listen for `doughboss:shop-changed` / `doughboss:cart-updated`; honour `doughboss:shop-change-request` veto | stays in sync with the picker | ES5 syntax only; handle `DoughBossData` being undefined on pages without storefront assets (`doughboss.js:11-13` pattern) |

Rules to carry over: namespace `db-`/`dbf-` CSS prefixes (or a new unique prefix), no `filter`/`backdrop-filter` on `.dbf-header`, respect `prefers-reduced-motion` and give a visible pause control for any autonomous motion (as the hero does), fail closed when REST data is missing.

### 8.6 Accessibility state (OBSERVED; claims limited to what the docs prove)

- Done in C and recorded: skip link; labelled nav, drawer focus trap/restore and background isolation (`assets/theme.js:5-131`; tests in `tests/storefront-helpers.test.js`); two-tone focus ring (`style.css:52`); 44 px targets; `prefers-contrast: more` solid header (`style.css:76-78`); one-shot translate-only reveals with reduced-motion path (`theme.js:156-198`, `style.css:287-293,391-400`); labelled customiser dialog with inert background and Escape (`public/js/doughboss.js:731-843`); live-region status for search/pickup status; `aria-pressed` on package cards.
- Contrast work recorded: ember tokens measured (`style.css:22-29` comments: `#ec4a41` 5.35:1 on `#0a0807`, `#c92017` 5.21:1 on paper), hero primary button `#d81f16` 5.09:1 (`css/doughboss-manoush-hero.css:153-154`), brief pairs Ink/Paper 17.84, Quiet/Paper 6.26, White/Coal 18.65, Ember ink/Paper 5.21 (`docs/ASTRA-DESIGN-BRIEF-20260908.md:34`). These are solid-pair calculations; the brief itself says they do not certify translucent composites or the whole page.
- Evidence limits: browser acceptance was on a static, synthetic GitHub Pages preview and on local fixtures (`docs/VISUAL-PREVIEW-20260908.md:55-66`); no private WordPress preview existed (file editing disabled, `:134-138`); no real-device or screen-reader testing is recorded. Live (B-minus) still has `h3`/`h4` heading levels and smaller touch targets (section 1).
- Gaps (INFERRED): menu card images are decorative CSS backgrounds with no text alternative (item name is in the heading, so acceptable, but the hero/story images use "Real Dough Boss ..." alt text); the page `h1` on the homepage is visually hidden and the visible hero title is an `h2`; the hero autoplay motion has a pause control but the steam loop is not individually pausable outside the hero button.

---

## 9. Where each brief item exists

| Brief item | Live (baseline-ish) | Candidate 2.43.2 | Notes |
| --- | --- | --- | --- |
| Hero exploding-pizza animation | **No.** Photo hero with parallax and steam (section 6.3) | **No** (same) | Existed in 2.23-2.33.x as generated layers, removed in 2.34.0 for authenticity (`readme.txt:152-153`). Layer assets not in the tree. |
| Minis teaser | **No** dedicated teaser. Minis appear only as four catering packages (3.8), catering hero copy and `/catering/` page. The plugin hero default description lists "Mini zaatar, cheese and meat manoush" (`shortcodes.php:75`). | same | The till has $2.50 Mini SKUs not exposed online (`ops/state/pospal-mapping-analysis.md`). |
| Waitlist | **No** product waitlist. `[doughboss_loyalty]` shows "You can join the waitlist experience now; points and vouchers are not active yet" while Rewards is off (`includes/class-doughboss-loyalty.php:82`); an unpaid after-hours pre-order request exists but is disabled (`after_hours_preorders_enabled` false) | same | No email-capture or waitlist table found (grep `waitlist` finds only the loyalty line). |
| Store-status indicators | Static launch bar ("Online ordering coming soon") and the `[doughboss_ordering_status]` notice (LIVE `/menu/` and `/config`); no open/closed per shop | Header "Pickup shop" select and live status text driven by `pickup_status` (`public/js/doughboss-shop-status.js`, `header.php:37`); order page counter (`page-order.php:30-40`) | Status is schedule-only and fails to "unconfirmed"; global pause takes precedence. |
| Dietary filters | **No** (no badges either) | Badges only (`doughboss.js:451-468`); **no filter** | Only text search and category jump. |
| Item customiser | Yes: per-item option groups via `details`; paused with "Customise when ordering opens" while ordering is closed | Yes, plus mobile bottom-sheet with focus management and live price (`:731-1070`) | Options are code-defined (3.2). |
| Multi-location selector | Shortcode `[doughboss_shop_picker]` (`shortcodes.php:288-298`, `doughboss.js:387-441`) shown only when ordering is open (B `page-order.php`); order page hard-codes a "Revesby" block | Header selector on every page plus order-page picker; picker collapses when one shop is active | `single_location_mode` can pin Revesby, but is off on live (finding 5). |
| Catering enquiry | Live form with direct email/phone fallbacks | Same plus preferred-shop binding and fuller staff mail (`docs/INTERACTION-POLISH-20260908.md`) | Packages are flat-priced; larger-group policy unresolved. |

---

## 10. Conflicts and contradictions

1. **Live bytes versus the baseline worktree** (section 1). Resolve before treating B as "what is live".
2. **Ordering state:** docs (2026-09-07/08, `ops/README.md:103`, `docs/REVIEW-20260908.md:23-39`) say open and Revesby-only; live on 2026-10-02 says closed and three active shops.
3. **Menu data:** seeder (34 items, `Soft Drinks 600ml`, `Juice`, six older prices) versus live (43 items, till prices). Live and `ops/` agree with each other; the seeder is stale.
4. **Provenance wording versus code:** 2.34.x says no lookalike images, but eight legacy WebP images are still mapped, shared across items.
5. **Dietary data:** seeder says halal for all food; live has six food items without any flag; docs say no halal certification is claimed.
6. **Locations:** theme copy says Roselands Centro; DB row says Roselands; page copy says "online ordering later" for two shops that are `pickup_enabled` on live.
7. **Sitemap versus noindex:** `/kitchen/` sitemapped, portal sends `noindex`.
8. **Next.js catalogue versus WordPress:** `web/src/lib/data/catalogue.ts` has every `priceCents` null ("[CONFIRM]", `:131-144`), while 3.6 now supplies verified prices; it sets `acceptsOnline: true` for all three shops (`:43,62,80`); it adds a Bankstown address line "Little Saigon Plaza, cnr Kitchener Pde & French Ave" (`:54`) and a note about "Snow Boss" (`:67`) that I could not verify from any WordPress source or live page; and it describes the food as "Stone-baked" (`:89`) whereas the WordPress changelog deliberately standardised public copy on "oven-baked" (`readme.txt:188`).

## 11. Unknowns for Elie

1. Is online ordering meant to be off right now? Who switched `ordering_open` off and when (docs said open on 2026-09-08)?
2. Should Bankstown and Roselands stay `pickup_enabled` while the intent is Revesby-only? Set the flag off per shop, or deactivate the rows, before any reopening.
3. Are the six till-aligned prices final? Can we restore the lost dietary flags and descriptions for the six items (and write descriptions for the 11 drinks)? Which items are genuinely vegetarian or vegan?
4. Is "everything is halal" a certified claim we can publish? Is there a certificate we can cite?
5. Provenance and licence of the `real-v1` photos and of the older `menu/*.webp` set, plus who owns the hero and social-card photos. Are there higher-resolution originals (the website copies are mostly 550x440)?
6. Which legacy images are accurate for their product (All Meat, Sujuk Special, Garlic Prawns, Ultimate Chicken, Dough Boss Wrap, Half Meat & Cheese, the veggie pizzas, all drinks)?
7. Public-holiday and exception hours for each shop; Roselands is a centre tenancy (centre trading hours may override).
8. GST: is any item GST-free? The system applies 10% to everything.
9. Is the custom pizza builder (Medium $12, Large $15, toppings from $0.75) a real product, or a leftover?
10. Minis: are the four catering packages the intended Minis offer, and is the $20 / $26 / $28 per-dozen pricing current? Are single minis (till $2.50) to be sold online?
11. Should `/kitchen/`, `/student-vouchers/`, and the 43 item and 4 package URLs be indexed?
12. Does Elie want the real-photo-only rule applied to any new exploding-pizza scene (assumed yes)?

## 12. Open issues (for the architect and companion-plugin work)

1. Re-baseline: capture the actual live plugin/theme bytes (or deploy a known build) so "baseline" means production.
2. Data repair script (read-only plan): restore dietary flags and descriptions on the six items and 11 drinks via a safe path that always posts `doughboss_dietary[]` and existing content; do not run the seeder.
3. Neutralise or fence the "Import standard menu" button and `wp doughboss seed-menu` on live (second writer risk).
4. Enforce Revesby-only in data (per-shop `pickup_enabled`) before `ordering_open` is turned on.
5. Add an ES5 syntax gate to CI (tooling change) because `node --check` does not enforce it.
6. Source menu images properly: map each of the 43 items to a verified photo or an honest placeholder; add `loading="lazy"`, `alt` and `srcset` to cards (card images are CSS backgrounds).
7. SEO build: per-shop landing pages with distinct JSON-LD URLs and `geo`/`sameAs`/`hasMenu`/`OrderAction`, noindex or enrich item and package pages, catering FAQ and Service/Offer schema, fix duplicate robots meta and `/menu/` canonical (C already fixes the latter), remove `/kitchen/` from the sitemap.
8. Make the locations page read hours from the database instead of hard-coded copy.
9. Provide theme extension points (a `do_action` slot after the hero and before the footer) if homepage blocks are wanted, or plan a separate Minis page that uses `page.php`.
10. Measure Web Vitals on production before and after any hero or image change; the hero image is 329 KB and discovered late on live.
11. Decide whether menu options remain code-defined (`DoughBoss_Menu_Options`) given that Square becomes the catalogue/POS; options keyed on exact item title are fragile when titles change.
