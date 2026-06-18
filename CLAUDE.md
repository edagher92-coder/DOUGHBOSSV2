# CLAUDE.md — DoughBoss

## Project overview
DoughBoss is a **self-contained WordPress plugin** — a commission-free pizza/food
ordering system: a branded storefront (menu + custom pizza builder), a
cookie-based guest cart & checkout (pickup/delivery), customer order tracking,
multi-shop order routing, a real-time kitchen **Live Order Board** (KDS), an
owner Orders + Settings admin, and an **in-progress** Stripe card-payment layer
(scaffolded, off by default). It is a plugin you install into WordPress — there
is no separate service to "connect". `docs/` holds the product/owner/dev PDFs.

- **Plugin version:** `2.5.0` (`DOUGHBOSS_VERSION`)
- **DB schema version:** `1.4.0` (`DOUGHBOSS_DB_VERSION` / option `doughboss_db_version`)
- **Requires:** WordPress 6.0+, PHP 7.4+ · **Text domain:** `doughboss` · **License:** GPL-2.0-or-later
- **REST namespace:** `doughboss/v1` (`DOUGHBOSS_REST_NAMESPACE`)

## Architecture & key files (one line each)
- `doughboss.php` — bootstrap: header, constants, activation/deactivation hooks, `plugins_loaded` → `DoughBoss::instance()`.
- `includes/class-doughboss.php` — singleton core loader; `require`s deps, runs migrations, instantiates components; holds shared `$cart`.
- `includes/class-doughboss-activator.php` — creates 3 custom tables via `dbDelta`, seeds `doughboss_settings`, adds caps + kitchen role, flushes rewrites.
- `includes/class-doughboss-deactivator.php` — only flushes rewrite rules (data preserved; removed on uninstall).
- `includes/class-doughboss-migrations.php` — version-gated upgrade runner; runs on load when stored DB version < code; re-runs `create_tables()` (dbDelta is additive) + ordered steps (1.1.0/1.2.0/1.3.0).
- `includes/class-doughboss-settings.php` — typed wrapper over the `doughboss_settings` option (`OPTION_KEY`); includes Stripe helpers `payments_enabled()`, `stripe_mode()`, `stripe_ready()`, etc.
- `includes/class-doughboss-locations.php` — shops/locations data model (`{prefix}doughboss_locations`); routing, `ensure_default()`.
- `includes/class-doughboss-post-types.php` — `doughboss_item` CPT + `doughboss_category` taxonomy + price/type/availability meta, meta box, list-table columns + sold-out toggle.
- `includes/class-doughboss-cart.php` — cookie-token + transient guest cart, server-side pricing/totals (GST-inclusive math), qty/line caps.
- `includes/class-doughboss-order.php` — orders/items data model; transactional create with order-number collision retry; statuses, board/active queries, admin `query()`.
- `includes/class-doughboss-stripe.php` — **dependency-free** Stripe PaymentIntents client over `wp_remote_*` (create/retrieve). Wired into `/payment-intent` + checkout verification + the front-end card field; **off by default** until `stripe_ready()` (payments on + keys set).
- `includes/class-doughboss-rest-controller.php` — all `doughboss/v1` routes: config, menu, locations, cart/*, checkout, order tracking, admin board/status/ack/accept.
- `includes/class-doughboss-shortcodes.php` — 5 shortcode containers hydrated by JS: `[doughboss_menu]`, `[doughboss_builder]`, `[doughboss_cart]`, `[doughboss_order_tracking]`, `[doughboss_shop_picker]`.
- `includes/class-doughboss-assets.php` — conditional storefront enqueue (only on pages with a shortcode, or `doughboss_load_assets` filter); localizes `DoughBossData` (restUrl, `wp_rest` nonce, i18n).
- `admin/class-doughboss-admin.php` — admin menu (Orders, Live Board, Shops, Settings), `register_setting` + sanitize, inline status-change JS, enqueues order-board app on its screen.
- `public/css/*.css`, `public/js/*.js` — vanilla-JS storefront (`doughboss.js`) and polling KDS (`doughboss-orderboard.js`, ~7s poll); no build step.
- `uninstall.php` — full data removal (drops tables, deletes items/options/caps/role, cleans transients).
- `build-zip.sh` — stages `doughboss/` and produces `dist/doughboss.zip` (installable upload).

## Data model
Custom tables (created by activator, kept current by the migration runner; all use `$wpdb->prefix`):
- `{prefix}doughboss_orders` — order_number (UNIQUE), location_id, status, order_type, customer fields, address/notes, subtotal/tax/delivery_fee/total, currency, eta_minutes, seen_at/acknowledged_at/accepted_at, created_at/updated_at.
- `{prefix}doughboss_order_items` — order_id, item_id, name, size, toppings(JSON text), quantity, unit_price, line_total.
- `{prefix}doughboss_locations` — name, slug, suburb, address, phone, postcodes, prep_time_default, pickup/delivery/is_active flags, sort_order.
- **Options:** `doughboss_settings` (single array option, accessed only via `DoughBoss_Settings`), `doughboss_db_version`.
- **Posts:** CPT `doughboss_item` + taxonomy `doughboss_category`; meta `_doughboss_price`, `_doughboss_item_type`, `_doughboss_available`.
- **Transients:** `doughboss_cart_{token}` (guest cart, DAY_IN_SECONDS), `doughboss_idem_{md5}` (checkout idempotency, 6h).
- **Caps/roles:** `manage_doughboss`, `manage_doughboss_kds`; role `doughboss_kitchen` (read + KDS only, for shop tablets).

## Conventions
- WordPress Coding Standards (PHP): tabs, Yoda conditions, full braces, doc-blocked methods. Every PHP file starts with the `ABSPATH` guard.
- **i18n:** all user-facing strings via `__()/esc_html__()`/etc. with text domain `'doughboss'`; `load_plugin_textdomain` on `init`.
- **Security (follow these — they are pervasive):**
  - State-changing REST routes use `permission_callback` → `verify_nonce` (`X-WP-Nonce` = `wp_rest`); admin routes → `verify_admin` (capability check). Read routes use `__return_true` intentionally (public storefront data).
  - Admin form handlers use `check_admin_referer`/`wp_nonce_field` + `current_user_can`.
  - **All input sanitized** (`sanitize_text_field`, `sanitize_email`, `sanitize_key`, `absint`, `sanitize_textarea_field`, REST `args` callbacks). **All output escaped** (`esc_html`, `esc_attr`, `esc_url`, `esc_js`).
  - **All SQL via `$wpdb->prepare()`**; table names interpolated only from `$wpdb->prefix`; `orderby`/`status` whitelisted; `esc_like` for LIKE. Where `prepare` is impossible, a scoped `// phpcs:ignore` documents why.
  - **Pricing is always recomputed server-side** — never trust browser-reported prices/totals. Custom-pizza pricing is rebuilt from settings on add.
- **Money:** AUD default, GST-inclusive by default (tax shown as a component, e.g. total/11 @ 10%) — preserve this when touching totals (`DoughBoss_Cart::totals`).
- **No build pipeline / no Composer / no npm:** plain PHP + vanilla JS/CSS, enqueued with `DOUGHBOSS_VERSION` cache-busting. Don't add a bundler.
- Extension points: actions `doughboss_order_created`, `doughboss_order_accepted`, `doughboss_order_status_changed`; filter `doughboss_load_assets`.

## How to verify locally
- Lint every PHP file (no test suite exists): run the project verifier, which also runs on session start.
  ```bash
  bash scripts/dev-check.sh
  ```
- Or lint directly (php is also at `/usr/bin/php` if not on PATH):
  ```bash
  find includes admin -name '*.php' -print0 | xargs -0 -n1 php -l
  php -l doughboss.php && php -l uninstall.php
  ```
- Build an installable zip: `bash build-zip.sh` → `dist/doughboss.zip` (gitignored).
- There is no PHPUnit harness; verification = `php -l` clean + manual reasoning against WPCS conventions.

## Rendering the docs (PDFs/PNGs)
`docs/` ships HTML sources alongside generated PDFs. `weasyprint` + `pypdfium2` are installed and importable. The brand font (`docs/brand/BebasNeue.ttf`, OFL) and the real DB logo SVGs live in `docs/brand/`.
```bash
weasyprint docs/DoughBoss-Product-Walkthrough.html docs/DoughBoss-Product-Walkthrough.pdf
# PDF → PNG page renders:
python3 -c "import pypdfium2 as p; d=p.PdfDocument('docs/DoughBoss-Product-Walkthrough.pdf'); [d[i].render(scale=2.6).to_pil().save(f'docs/walkthrough/page-{i+1}.png') for i in range(len(d))]"
```
The walkthrough uses `@page { size: A4 landscape; margin: 0; }`; the proposal/plan use portrait `@page`.

## Git workflow
- Develop on the designated `claude/*` branch (current: `claude/funny-goodall-gsoog4`); never commit straight to a shared base.
- Commit only when asked; then `git push -u origin <branch>` and open a **DRAFT** PR.
- Honor `.gitignore`: `dist/`, `*.zip`, `vendor/`, `node_modules/`, editor/OS junk are not committed.

## Current state & roadmap
- **Shipped:** menu CPT + builder, cart/checkout, order tracking, admin Orders/Settings, multi-shop locations + routing, Live Order Board (KDS), AUD/GST, per-item availability + shop picker (2.4.0).
- **Stripe card payments — wired (2.5.0), off by default.** `POST /payment-intent` creates an intent for the cart total; `confirmCardPayment` runs client-side with a Stripe Elements card field; `/checkout` re-verifies the PaymentIntent server-side (status `succeeded` + amount + currency must match the server total) and blocks PI replay (`DoughBoss_Order::payment_intent_used`). Orders store `payment_status`/`payment_method`/`payment_intent_id`. Server stays source of truth for totals; secret key never leaves the server; Stripe.js loads only when configured.
- **Roadmap (not yet implemented):** Stripe **webhook** as the authoritative payment source-of-truth (current flow verifies via API on checkout), refunds surfaced in the order board, push transport (Ably/Pusher) + live customer tracking, receipt printing/SMS, scheduled time slots, per-item toppings, catering packages with deposits (mentioned in product docs; **no catering/deposit code exists in the repo yet**).
- **Docs deliverable:** product/owner/dev PDFs live in `docs/` (`Owner-Report.md`, `DoughBoss-*.html` → `.pdf`, walkthrough PNGs). Skills/tooling review: `docs/Skills-and-Tooling-Review.md`.

## Gotchas
- The "catering packages with deposits" feature is described in product docs but **has zero code** in the repo — don't assume it exists.
- Stripe is **off unless `DoughBoss_Stripe::ready()`** (payments enabled AND publishable+secret keys set for the active test/live mode). When off, `/checkout` behaves exactly as before (no payment required); when on, an order with no verified PaymentIntent is rejected (402).
- `php` may not be on `$PATH`; the verifier falls back to `/usr/bin/php`.
- Read-only public REST routes (`/config`, `/menu`, `/locations`, `/order/{n}`) use `__return_true` **by design** — don't "fix" them with nonces; order tracking is gated by matching email instead (and returns the same error for not-found vs. mismatch to avoid leaking order existence).
- `dbDelta` is additive only — schema changes go through `create_tables()` (for columns) **plus** a version-gated migration step (for caps/data) **plus** bumping `DOUGHBOSS_DB_VERSION`.
- Settings are one array option — always read/write via `DoughBoss_Settings`, never `get_option('doughboss_settings')` directly.
- GST-inclusive vs. exclusive totals are computed differently in `DoughBoss_Cart::totals()`; changing tax logic must preserve both branches.
- The Dev Technical Plan PDF lists a historical menu-taxonomy "`field=slug` passed a name" bug; the **current** `get_menu()` uses `get_the_terms()` (not a slug `tax_query`), so verify before acting on that note.
- Order-board JS polls (~7s); it is a polling KDS, not push — keep that in mind for "real-time" expectations.

## Working with the live site & external tools
- A live WordPress site exists at **doughboss.com.au**, reachable via the WPVibe MCP server (read theme/files, run WP-CLI, `site_info`, pull real menu/brand data). Use it to deploy to a draft theme and smoke-test — never push to production without an explicit go-ahead and a fresh backup (UpdraftPlus is installed).
- Direct outbound network is restricted to an allowlist (e.g. `fonts.gstatic.com`, PyPI). `doughboss.com.au` assets must be pulled through the WPVibe server, not curled.
- See `docs/Skills-and-Tooling-Review.md` for the evaluated skills and MCP integrations (Stripe, WPVibe, accounting, etc.) and the prioritised roadmap.

## External AI (Google Gemini) — model policy
A Google Gemini API key is available and the endpoint (`generativelanguage.googleapis.com`) is reachable from this environment. **Keep the key in the `GEMINI_API_KEY` environment variable — never hard-code or commit it.** Used for generating assets/content for the docs & prototypes (e.g. the menu photography in `docs/DoughBoss-Interactive-Prototype.html`), not by the plugin at runtime.

- **Match the model to the task's difficulty and the result quality needed** (don't default to one):
  - **Pro** (`gemini-2.5-pro` / `gemini-pro-latest`) — hard/complex reasoning, long-context synthesis, architecture/code, final client-facing copy. Higher quality, slower, pricier. Use when the result must be polished or the problem is non-trivial.
  - **Flash** (`gemini-2.5-flash` / `gemini-flash-latest`) — fast/iterative/bulk and moderate tasks, quick drafts. Default workhorse when speed matters and the task isn't hard.
  - **Flash-Lite** (`gemini-2.5-flash-lite`) — cheapest, high-volume/trivial.
  - **Images** — `imagen-4.0-fast-generate-001` for bulk/iteration (used for the prototype menu shots); `imagen-4.0-generate-001` / `imagen-4.0-ultra-generate-001` for hero/quality; `gemini-2.5-flash-image` for edits/compositing.
- **Access patterns:**
  - REST: `POST https://generativelanguage.googleapis.com/v1beta/models/<model>:generateContent` with header `X-goog-api-key: $GEMINI_API_KEY` (Imagen uses `:predict` with `{"instances":[{"prompt":...}],"parameters":{"sampleCount":1,"aspectRatio":"1:1"}}`).
  - Google SDK: `from google import genai; client = genai.Client(api_key=os.environ["GEMINI_API_KEY"]); client.models.generate_content(model="gemini-2.5-pro", contents="…")`.
  - OpenAI-compatible (reuse existing OpenAI code, change only baseURL + model): base URL `https://generativelanguage.googleapis.com/v1beta/openai/`, `apiKey`/`api_key` = `GEMINI_API_KEY`, then `chat.completions.create({ model: "gemini-2.5-pro", messages: [...] })`.
- Resize/recompress generated images (Pillow → JPEG ~480px q80) before embedding so deliverables stay small and self-contained.
- **Efficiency:** use `scripts/gemini.py` (prompt-hash cache + model tiering, `--dry-run`); reuse cached assets on rebuilds — the interactive prototype reuses 8 cached images = **0 new calls**. Full prompt library, Claude×Gemini routing and the caching method: `docs/Gemini-Claude-Playbook.md`.
