# 02 - Orders, payments (Square), kitchen, promotions, catering and reporting: map of the DoughBoss WordPress plugin

Slice owner: orders, payments (especially Square), kitchen routing, promotions, catering, reporting and privacy.
Prepared for: Elie Dagher (Dough Boss: Revesby, Bankstown, Roselands Centro).
Method date: 2 October 2026. Read-only study of two git worktrees; nothing installed, deployed, called or changed.

## 0. How to read this document

Two trees were read:

| Short name | Path | Meaning |
| --- | --- | --- |
| B (baseline) | `/tmp/wp-src/baseline-2.41.0` | The live plugin, DoughBoss 2.41.0 (`doughboss.php:26`). |
| C (candidate) | `/tmp/wp-src/candidate-2.43.2` | The 2.43.2 release candidate. A superset of B. Reviewed locally, NOT installed. |

Every fact carries a path and line, relative to the worktree. Paths are for C unless prefixed `B`. Where B and C are byte-identical the path is given once and "same in B" is stated. Claims are labelled:

- **OBSERVED**: read in code, a repo document, or a result I ran (the scratch test run in section 14).
- **INFERRED**: my reasoning from observed code. It may be wrong and should be tested before building on it.
- **not found**: I looked and the thing is absent. The place I looked is named.

Square API field names in section 6 are NOT observed in this environment. I did not fetch Square documentation or call any Square API (hard rule 4). They come from general knowledge of the Square Orders and Payments APIs and from `docs/SQUARE-MIGRATION-20260908.md:28`. Every such name is INFERRED and must be checked against the pinned `Square-Version` header (`includes/class-doughboss-settings.php:234`, default `2025-01-23`) in a sandbox before code is written.

Files that differ between B and C (OBSERVED, `diff -rq`): `class-doughboss-square.php`, `class-doughboss-payment-attempts.php`, `class-doughboss-checkout-snapshots.php`, `class-doughboss-rest-controller.php`, `class-doughboss-voucher.php`, `class-doughboss-catering.php`, `class-doughboss-locations.php`, `class-doughboss-settings.php` (two message strings only), `class-doughboss-assets.php`, `class-doughboss-shortcodes.php`, the main class `class-doughboss.php`, `class-doughboss-images.php` (C only), `public/js/doughboss-square.js`, `public/js/doughboss-catering.js`, `public/js/doughboss.js`, plus theme and CSS. Files identical in B and C include `class-doughboss-order.php`, `class-doughboss-cart.php`, `class-doughboss-stripe.php`, `class-doughboss-tyro.php`, `class-doughboss-mpgs.php`, `class-doughboss-payment.php`, all POSPal classes, printer, ntfy, mercure, sms, emails, loyalty, reports, privacy, menu-options and post-types.

Live state recorded by the repo itself (OBSERVED, `ops/README.md:99-109`, captured 2026-09-07): ordering open, pickup only, single location (Revesby); online payments OFF (pay at the shop), Stripe present in test mode; GST 10% inclusive; POSPal mirroring on, 43 of 43 menu items mapped; voucher reconciliation owner set; 4 catering packages published; delivery disabled. (The row for the 2026-09-07 snapshot is at `ops/README.md:103-109`.) `docs/REVIEW-20260908.md:33-35` agrees (payments disabled, gateway Stripe test). I did not re-verify the live site (another agent owns that). Treat this as the last documented state, not a current fact.

## 1. Findings that change what we build

1. **Square is payments-only today.** The `POST /v2/payments` body is `idempotency_key, source_id, amount_money, location_id, reference_id, autocomplete` (+ optional `verification_token`): `includes/class-doughboss-square.php:398-411`. No `order_id`, no line items, no catalog ids, no fulfilment. A Square payment is therefore not a kitchen ticket (`docs/SQUARE-MIGRATION-20260908.md:23`).
2. **The Square location is one global setting per mode**, not per shop: `DoughBoss_Settings::square_location_id()` (`includes/class-doughboss-settings.php:847-851`), read at `class-doughboss-square.php:141, 261, 577, 689, 825, 910` and sent to the browser by `class-doughboss-assets.php:288`. The `doughboss_locations` table has `tyro_location_id` and `pospal_store_index` but no Square column (`includes/class-doughboss-activator.php:182-210`). A Tyro-style per-shop mapping exists as a pattern (`class-doughboss-locations.php:701-711`).
3. **The live site runs the older Square protocol (B).** B's Square path is the v1 flow (attempt key `sha256('square|'.checkout_key)`, `claim_creation`, `release_creation` after a failed POST: `B includes/class-doughboss-square.php:290-296, 328-358`). C replaces it with a `square-v2` protocol (irreversible dispatch, cart guard, binding hash, snapshot-owned order, sticky `unknown`/`mismatch`). Enabling Square on 2.41.0 would run the weaker protocol. Payments are off live, so this is a build-order point, not a live defect.
4. **Square refunds and Square webhooks are thin.** Refund is a full refund only from the admin (`admin/class-doughboss-admin.php:1840-1873`) and the order is marked `refunded` on any 2xx response without reading the Square refund status (`class-doughboss-square.php:805-816`). The webhook ignores every event whose type does not start with `payment.` (`class-doughboss-rest-controller.php:5545-5548`), so refund, order and fulfilment events are dropped.
5. **No code path in C or B calls `DoughBoss_Square::test_connection()`** (grep of `includes` and `admin` in both trees: only POSPal, Tyro and MPGS have callers: `class-doughboss-rest-controller.php:1769, 1807, 1844`; `class-doughboss-cli.php:49`). The docblock at `class-doughboss-square.php:819-820` promises an admin connectivity action that does not exist.
6. **The catalog is code, and a naive modifier map would collide.** Option groups are PHP constants (`includes/class-doughboss-menu-options.php:63-190`, selected per category and per item name at `:193-238`). Two groups share the id `style` with different prices (`:65-72` flat = $0.00 default; `:74-81` flat = +$0.50, folded default). The cart modifier slug is `group--choice` (`:287`), so `style--flat` is $0 on one item and $0.50 on another.
7. **POSPal precedent is name-keyed and fail-all.** The product map is keyed by lower-cased item name (`includes/class-doughboss-pospal-orders.php:197-214`) and ONE unmapped item abandons the whole order push (`:80-84`; `ops/README.md:62-66`). Custom pizza names have no verified POSPal mapping (`docs/REVIEW-20260908.md:124`). Do not copy name keying for Square.
8. **Kitchen dispatch has several independent writers and only some are de-duplicated.** Board polling, ntfy, Mercure, printer queue, POSPal outbox, email and loyalty all hang off the same actions. Only the POSPal outbox, the order row, status events and email have explicit duplicate guards. A Square POS/KDS would be one more consumer, with no veto point: there is no filter that lets an extension stop POSPal push, printing, ntfy or Mercure (section 7.4).
9. **The printer queue is global, not per shop.** One option watermark (`doughboss_printer_watermark`) and `SELECT ... WHERE id > watermark` with no location filter (`class-doughboss-printer.php:184-244`). Multi-shop printing would mix shops.
10. **Catering deposits cannot be paid through Square.** `catering_payment_intent` only excludes MPGS (`class-doughboss-rest-controller.php:4945`) and then calls the generic `DoughBoss_Payment::create_payment_intent` with metadata that lacks Square's required v2 keys (`:5034-5047` vs `class-doughboss-square.php:269-281`). INFERRED: it fails closed with `doughboss_pay_request` (HTTP 400). B fails earlier on the `source_id` check (`B class-doughboss-square.php:273`).
11. **Marketing attribution is effectively unwired.** The browser bridge reads only five `utm_*` keys, only after a consent event nothing in the plugin or theme dispatches, keeps them in memory only, and never forwards them to the server (`public/js/doughboss-marketing.js:73-88, 138-140`; `class-doughboss-assets.php:209` `adpilotServerReady => false`). No `gclid`, `fbclid`, `ttclid` or `msclkid` is captured anywhere (grep of all `*.php`/`*.js`). Neither orders nor catering enquiries have attribution columns (`class-doughboss-activator.php:91-148, 326-363`).
12. **Marketing consent exists in exactly one place**: the Rewards sign-in checkbox, stored as a bare timestamp in user meta `doughboss_marketing_consent` (`class-doughboss-loyalty.php:94, 177-178`). Nothing reads it. Checkout, voucher claim, catering enquiry and pre-order capture none.
13. **Privacy tooling covers 3 of the tables that hold customer data.** Exporters and erasers: orders, catering, vouchers (`class-doughboss-privacy.php:45-90`). Not covered: loyalty members/ledger/tokens, checkout snapshots (full contact details in `payload_json`), POSPal outbox `payload_json` (name, phone, address; kept at least 30 days: `class-doughboss-pospal-outbox.php:744-755`), and the marketing-consent user meta. Erasing a voucher row anonymises `customer_email`, which is the key the one-voucher-per-student check uses (`class-doughboss-voucher.php:1095-1114`), so an erasure request reopens that student's eligibility (INFERRED).
14. **Reports are online-order only and UTC-day based.** Every query reads `doughboss_orders` created through the plugin, filtered by `created_at` in UTC (`class-doughboss-reports.php:65-73, 106-115`). In-store sales (POSPal or Square POS) never appear. Revenue includes unconfirmed `preorder_request` rows until they are cancelled (INFERRED from `scope_where`, which excludes only `status = 'cancelled'`).
15. **Integration tests exist but CI does not run them.** CI runs `php tests/run.php` only (`.github/workflows/plugin-ci.yml:38`); the WordPress/MySQL suite (`tests/integration/wordpress-mysql.php`, about 250 assertion call sites) runs through a local PowerShell script (`scripts/test-wordpress-integration.ps1`). Square `create_refund`, `orphan_payment` and the admin refund path have no test at all (grep of `tests/`).

## 2. Order model

### 2.1 Tables (C `includes/class-doughboss-activator.php`, created by `create_tables()` at `:58`, all `ENGINE=InnoDB` except where noted)

| Table | Def. line | Purpose and key columns |
| --- | --- | --- |
| `{prefix}doughboss_orders` | 91-147 | One row per order. `order_number` (UNIQUE), `location_id`, `status` (default `pending`), `version` (default 1), `order_type` (pickup/delivery/dine_in), `table_id/table_label/table_qr_code_id/table_session_id`, `order_source` (web, table_qr, preorder_request), customer name/email/phone, `address`, `notes`, money (`subtotal, tax, delivery_fee, total, discount` decimal(10,2)), `voucher_code`, `currency`, `payment_status` (default `unpaid`), `payment_method`, `payment_intent_id` (UNIQUE, nullable), `checkout_key` (char(64), UNIQUE, nullable), `eta_minutes`, lifecycle timestamps (`seen_at, acknowledged_at, accepted_at, status_changed_at, cooking_started_at, ready_at, completed_at, cancelled_at`), capacity columns (`promised_ready_from_utc, promised_ready_by_utc, timezone_snapshot, capacity_hold_id, capacity_units, fire_at_utc, planning_version`), `created_at/updated_at` (UTC). |
| `{prefix}doughboss_order_items` | 168-180 | `order_id, item_id` (menu post id; 0 for custom pizza), `name, size, toppings` (JSON text of `{slug,label,price}`), `quantity, unit_price, line_total`. No catalog, SKU or modifier-id columns. |
| `{prefix}doughboss_order_events` | 149-166 | Append-only audit. `order_id, order_version, event_type, from_status, to_status, actor_type, actor_id, reason_code, event_key` (UNIQUE), `UNIQUE (order_id, order_version)`. No PII. |
| `{prefix}doughboss_locations` | 182-210 | Shop row. Includes `prep_time_default` (20), `timezone` (Australia/Sydney), capacity settings, `tyro_location_id`, `pospal_store_index` (0-3), `online_payment_enabled` (default 0), `pickup_enabled`, `delivery_enabled`, `is_active`. |
| `{prefix}doughboss_payment_attempts` | 456-484 | Durable provider-neutral attempt: `attempt_key` UNIQUE, `provider`, `provider_reference` UNIQUE nullable, `checkout_key` UNIQUE, `purpose, context, local_reference, location_id, table_id, qr_code_id, amount_minor, currency, status, provider_status, safe_metadata_json, last_error, verified_at`. |
| `{prefix}doughboss_payment_events` | 485-498 | Webhook de-duplication: `event_key` UNIQUE, `provider, provider_reference, event_type, outcome`. No raw body. |
| `{prefix}doughboss_checkout_snapshots` | 499-514 | Immutable server-owned snapshot: `checkout_key` UNIQUE, `payload_hash, payload_json` (order + lines + contact details), `status, order_id, expires_at`. |
| `{prefix}doughboss_pospal_outbox` | 435-455 | POSPal push queue (section 7). |
| `{prefix}doughboss_catering_enquiries` | 326-363 | Catering (section 9). NOT declared InnoDB. |
| `{prefix}doughboss_vouchers`, `_voucher_redemptions`, `_voucher_audit` | 365-433 | Vouchers (section 8). |
| `{prefix}doughboss_loyalty_members`, `_ledger`, `_tokens` | 515-563 | Rewards (section 8). |

Storage readiness is gated at runtime: `payment_storage_ready()` (`:755-805`) checks InnoDB and a column/index contract including `locations.tyro_location_id`, `pospal_store_index`, `online_payment_enabled`. The DB version is `DOUGHBOSS_DB_VERSION = '1.23.0'` in both trees (`doughboss.php:31`); migrations are verify-only steps run after `dbDelta` re-runs `create_tables()` (`class-doughboss-migrations.php:27-110`, pattern at `:594-603`).

### 2.2 Status lifecycle and versioned transitions (identical in B and C; `includes/class-doughboss-order.php`)

Statuses (`:22-33`): `pending, confirmed, preparing, baking, ready, out_for_delivery, completed, cancelled`.

Forward-only graph (`allowed_transitions`, `:80-97`):

```
pending -> confirmed | cancelled
confirmed -> preparing | cancelled
preparing -> baking | ready | cancelled
baking -> ready | cancelled
ready -> out_for_delivery (delivery) | completed (pickup, dine_in)
out_for_delivery -> completed
completed, cancelled: terminal
```

Rules enforced inside `transition()` (`:743-897`) (OBSERVED):

- Requires `expected_version` (non-zero) and a non-empty `event_key`; otherwise `doughboss_transition_invalid` (400).
- Runs in a SQL transaction. The `event_key` lookup is inside the transaction: same order and same target state is an idempotent replay; any other reuse is `doughboss_event_key_conflict` (409).
- `version` must match, else `doughboss_stale_order` (409). The update is a compare-and-set on `(id, status, version)`; zero rows is a conflict.
- Cancellation requires a `reason_code` and is refused while `payment_status = 'paid'` (the order must be refunded first): `doughboss_cancel_reason`, `doughboss_refund_required`. `available_transitions()` (`:116-123`) hides `cancelled` for paid orders.
- Acceptance (`confirmed`) stamps `accepted_at`, `acknowledged_at`, `eta_minutes`; with an ETA it computes `promised_ready_from_utc = now + eta` and `promised_ready_by_utc = +15 min` (`:822-836`). A `preorder_request` flips `order_source` to `web` on acceptance (`:814-820`).
- After commit it fires `doughboss_order_accepted` (on `confirmed`) and `doughboss_order_status_changed` (`:896-898`).
- `update_status()` (`:1135-1155`) is a legacy wrapper that synthesises an `event_key`.
- Customer-facing wording is a separate projection (`customer_projection`, `:130-165`): `received, confirmed, preparing, ready_for_pickup/ready_to_serve/ready_for_delivery, out_for_delivery, collected/served/delivered, cancelled`, plus `preorder_pending_review`.
- `payment_status` has only three values: `unpaid`, `paid`, `refunded` (`update_payment_status`, `:1163-1190`). Partial refunds, disputes and "pending refund" are not representable.

### 2.3 Order numbers

`DB-` + UTC `ymd` + `-` + 6 characters from `wp_generate_password( 6, false, false )`, retried until unique (`:244-256`); UNIQUE index on `order_number`. Catering uses `CAT-ymd-NNNN` with a 4-digit random (`class-doughboss-catering.php:831-847`).

### 2.4 Creating an order (`DoughBoss_Order::create`, `:264-473`)

- Refuses below DB version 1.13.0, an empty cart, or a missing 64-hex `checkout_key`.
- A `preorder_request` is forbidden from carrying payment fields or a capacity hold (`:280-287`).
- One transaction covers the order row, item rows, the `created` event (`event_key = 'order-created:{id}'`) and optional capacity-hold conversion. Up to 5 retries on an order-number collision.
- A unique-key loser (same `checkout_key` or same `payment_intent_id`) returns the existing order with `replayed => true` and fires no hook.
- `doughboss_order_created( $order_id, $data )` fires only after COMMIT (`:470`).
- `currency` is taken from settings at insert time, not from the snapshot (`:363`).

### 2.5 Idempotency and checkout snapshots

| Layer | Mechanism | Evidence |
| --- | --- | --- |
| Browser retry key | Header `Idempotency-Key` or param `idempotency_key`, 16-191 chars, HMAC-bound to the cart token with `wp_salt('auth')`; the HMAC is the stored `checkout_key`. | `class-doughboss-rest-controller.php:4068-4081` |
| Response cache | `doughboss_idem_{key}` transient, 6 hours. | `:3558, 3562, 3574, 3873` |
| Durable replay | `DoughBoss_Order::find_id_by_checkout_key()` before cart validation, so a lost response after the cart was cleared still replays. | `:3566-3576`; `class-doughboss-order.php:1044`; unique-key loser replay at `:386-399` |
| One payment, one order | UNIQUE `payment_intent_id`; `payment_intent_used()` (`class-doughboss-order.php:1033`). | |
| Payment checkout key | `payment_checkout_key()` = HMAC of scope + client `payment_attempt_key` + snapshot JSON. | `rest-controller.php:2924-2937` |
| Square attempt identity | `payment_attempt_identity()` (HMAC of scope + cart token + client key), `square_cart_guard()`, `square_binding_hash()`. | `:2939-2998` |
| Snapshot | Stored before any money moves; 262,144-byte cap; 24 hour expiry; a different payload for the same key is `doughboss_snapshot_changed` (409). Expired snapshots are kept while an unresolved Square v2 attempt owns them. | `class-doughboss-checkout-snapshots.php:38-88, 114-133, 191-204` |

B differs: no expired-snapshot protection and a plain purge (`B class-doughboss-checkout-snapshots.php:104, 156`); B's Square path does not use a snapshot at all (the order is rebuilt from the live cart at `/checkout`).

### 2.6 Customer tracking

`POST /order/track` with `number` and `email` in a JSON body; a not-found and an email mismatch return the same 404 (`class-doughboss-rest-controller.php:4375-4390`). `public_view()` returns status labels, timing projection, totals, voucher code, items (`class-doughboss-order.php:1276-1304`). Responses get `Cache-Control: no-store, private` and `Referrer-Policy: no-referrer` (`rest-controller.php:4400-4414`). The page uses the `doughboss_order_tracking` shortcode (`class-doughboss-shortcodes.php:29`) and the theme template `page-track-order.php`; the URL is filterable (`doughboss_tracking_page_url`, `class-doughboss-settings.php:444`). There is NO rate limit on this route (no `rate_limited()` call in `:4375-4390`). INFERRED: the 6-character random suffix plus an email match makes brute force impractical, but it is the only public lookup without a limiter.

## 3. REST routes (namespace `doughboss/v1`, `DOUGHBOSS_REST_NAMESPACE`, `doughboss.php:41`)

Permission callbacks (`class-doughboss-rest-controller.php`):

- `verify_nonce` (`:1298-1306`): header `X-WP-Nonce` must verify against action `wp_rest`. Guests receive a nonce through `wp_localize_script` (`class-doughboss-assets.php:300`).
- `verify_manage` (`:1362-1368`): `manage_doughboss` or `manage_options`.
- `verify_admin` (`:1311-1318`): adds `manage_doughboss_kds`.
- `verify_board_access` (`:1329-1355`): `verify_admin`; owners pass; KDS-only staff must also resolve a location scope (`DoughBoss_Staff_Scope::current_location_id`) and, if a board key is set, present it in header `X-DoughBoss-Board-Key`.
- `verify_redeem` (`:1377-1384`): `redeem_doughboss_vouchers` or manage. `verify_staff` (`:1391-1398`): any staff capability (401 so the console can prompt).
- Webhooks use `permission_callback => __return_true` and verify a provider signature inside the handler.

Rate limiter (`rate_limited()`, `:1479-1507`): per client IP, a transient counter serialised by a named MySQL lock; it FAILS OPEN if the lock cannot be taken. `client_ip()` (`:1441-1471`) uses `REMOTE_ADDR` unless the `behind_reverse_proxy` setting (default 0) is on. Buckets in use: `voucher_validate` 12/10 min, `voucher_redeem` 6/hour, `voucher_scan` 240/10 min, `voucher_claim` 8/10 min, `voucher_apply` 15/10 min, `payment_intent` 12/10 min, `checkout` 8/10 min, `preorder_request` 4/10 min, `catering` 5/hour, `catering_payment_intent` 12/10 min, `catering_confirm_payment` 12/10 min (call sites `:1529, 1569, 1602, 2495, 2585, 3339, 3413, 3548/3582, 4734, 4952, 5089`). Cart routes, `order/track` and the board have no limiter.

CORS: `send_cors_headers` (`:74-86`) adds `Access-Control-Allow-Origin` for the `app_origin` setting on this namespace only, with headers `Authorization, Content-Type, X-WP-Nonce, X-DoughBoss-Board-Key`. The default `app_origin` is `https://edagher92-coder.github.io` (`class-doughboss-settings.php:281`), filterable via `doughboss_app_origin` (`:340`). Worth reviewing: a third-party origin is allowed by default on a payments plugin.

| Method and path | Route def. line | Permission | Handler (line) | Notes |
| --- | --- | --- | --- | --- |
| GET `/config` | 98 | public | `get_config` (2522) | `payments_enabled` = `ordering_open() && DoughBoss_Payment::ready()` (2548); exposes gateway name, Mercure hub URL/topic. |
| GET `/menu` | 108 | public | `get_menu` (3053) | Item id, name, price, category, availability, dietary, `options` groups. The canonical public menu API. |
| GET `/locations` | 118 | public | `get_locations` (3000) | `pickup_status` schedule view is C only. |
| GET `/table/context` | 128 | public | `get_table_context` (3017) | Table QR session. |
| GET `/cart` | 138 | public | `get_cart` (3162) | Cart in a transient keyed by cookie token. |
| POST `/cart/add`, `/cart/update`, `/cart/remove`, `/cart/clear` | 148, 182, 202, 624 | nonce | 3186, 3301, 3315, 3325 | Price is re-resolved server side (`build_menu_line` 3221, `build_custom_line` 3260). |
| POST `/cart/apply-voucher`, `/cart/remove-voucher` | 634, 654 | nonce | 3338, 3380 | 15/10 min on apply. |
| POST `/payment-intent` | 670 | nonce | `create_payment_intent` (2577) | 12/10 min. Gateway-specific (section 5). |
| POST `/checkout` | 722 | nonce | `checkout` (3532) | 8/10 min. Needs idempotency key. |
| POST `/preorder-request` | 767 | nonce | `preorder_request` (3402) | After-hours, unpaid, 4/10 min. |
| POST `/order/track` | 785 | nonce | `track_order` (4375) | No limiter. |
| POST `/admin/order/{id}/status` | 805 | board | `admin_update_status` (4418) | Needs `status`, `expected_version`, `event_key`; kitchen role cannot cancel (4423). |
| GET `/admin/orders` | 836 | board | `admin_orders` (4455) | Live board (100 active); history/status filter needs manage. |
| GET `/admin/board-summary` | 871 | board | `admin_board_summary` (4508) | PII-free MAKE/PASS/CATERING counts. |
| POST `/admin/order/{id}/ack`, `/accept` | 888, 899 | board | 4537, 4553 | Accept requires version and event key. |
| GET `/admin/preorder-requests`; POST `/admin/preorder/{id}/decision` | 928, 942 | board | 4584, 4615 | After-hours review queue. |
| GET `/catering/packages`, `/catering/quote` | 960, 971 | public | 4669, 4711 | Server-computed quote. |
| POST `/catering/enquiry` | 996 | nonce | `create_catering_enquiry` (4733) | 5/hour + honeypot `hp`. |
| GET `/admin/catering`, `/admin/catering-board` | 1066, 1097 | manage; board | 4806, 4835 | |
| POST `/admin/catering/{id}/status`, `/quote` | 1113, 1129 | manage | 4888, 4913 | |
| POST `/catering/payment-intent`, `/catering/confirm-payment` | 1146, 1175 | nonce | 4944, 5084 | Number + email must match. |
| Voucher routes | 218-366, 568-604 | mixed | see section 8 | |
| POST `/catering/stripe-webhook`, `/stripe-webhook`, `/catering/tyro-webhook`, `/tyro-webhook`, `/payments/tyro/webhook`, `/mpgs-notification`, `/square-webhook` | 1205, 1218, 1231, 1243, 1255, 1268, 1283 | signature in handler | 5159, 5223, 5438, 5453, 5453, 1870, 5523 | |
| POSPal, Tyro/MPGS test, Mercure test, `/auth/me`, `/status` | 376-558 | manage/staff | 1735-2338 | Admin tooling. |
| POST/GET/DELETE `/print/cloudprnt`, POST `/print/epos` | `class-doughboss-printer.php:80-125` | public + printer token | printer.php:252-430 | Token checked with `hash_equals` (`:133-157`). |

Timeclock routes are outside this slice.

## 4. Checkout flow end to end (C)

### 4.1 Pay at the shop (payments off, the documented live state)

1. Browser adds lines (`/cart/add`); the server resolves price from `_doughboss_price` post meta plus option deltas, or from settings sizes/toppings for custom pizzas (`rest-controller.php:3221-3299`).
2. `POST /checkout` with an idempotency key. Guards in order: key valid; storage ready (DB 1.13.0); stripe-return handling; cached then durable replay; rate limit; `ordering_open()` (default 0, `class-doughboss-settings.php:81`); cart non-empty; table context; order type; contact fields (`:3610-3636`); location resolution (`resolve_order_location`, `:4158`); `cart->totals()`.
3. `DoughBoss_Order::create` with `payment_status 'unpaid'` (`:3780`). `doughboss_order_created` fires; confirmation email goes to the customer and to `orders_email` (`send_confirmation`, `:5974-6013`); cart cleared; response `{success, order_number, total, replayed, tracking_url}` (`checkout_payload`, `:4088-4107`).

### 4.2 Square card payment (C, protocol `square-v2`)

1. Browser: Web Payments SDK `Square.payments( applicationId, locationId )`, `card().tokenize()`, optional `verifyBuyer` (`public/js/doughboss-square.js:165, 249, 264-287`). The card never reaches the server.
2. `POST /payment-intent` (`rest-controller.php:2577-2860`): storage readiness (`doughboss_db_version >= 1.15.0`); `DoughBoss_Payment::ready()`; rate limit; ordering open; cart; table context; order type; location resolution; `DoughBoss_Locations::online_payment_location()` requires `online_payment_enabled = 1` and an active shop (`class-doughboss-locations.php:720-726`); contact details mandatory for Stripe and Square (`:2639-2648`); `cart->totals()` and `to_minor_units(total)` (`:2653-2655`); `square_binding_payload` and keys (`:2658-2662`); voucher reservation (`:2682`); snapshot store (`:2742`); then `DoughBoss_Square::create_payment_intent( $amount, $currency, $metadata )` (`:2778`).
3. Inside Square class (`:252-456`): validate inputs (minor-unit amount, 3-letter currency, 64-hex keys, `protocol_version === 'square-v2'`); strip `source_id`/`verification_token`/`attempt_key` before persistence (`:293-299`); take a named lock on the cart guard; refuse if an unresolved legacy Square attempt exists (`find_blocking_legacy_square`, `class-doughboss-payment-attempts.php:173-187`); refuse if another attempt on the same cart is unresolved (`find_blocking_by_local_reference`, `:128-170`); `create_or_find` the attempt (`status 'prepared'`); re-assert immutable fields and the stored binding hash, Square location and mode; if a provider reference exists, replay by GET; else claim irreversible dispatch (`prepared` to `dispatching`, optionally together with the voucher marker: `class-doughboss-voucher.php:386-437`); POST `/v2/payments`; validate the payment (`payment_matches`); bind the reference (`bind_irreversible_reference`, `class-doughboss-payment-attempts.php:433`). Any timeout or unexpected shape becomes `unknown`, which is never reclaimed by elapsed time (`:404-431, 465-483`).
4. Browser: `POST /checkout` with `payment_intent_id` (the Square payment id) and `Idempotency-Key` (`doughboss-square.js:355-357`).
5. `checkout()` runs `verify_payment()` (`:4205-4363`). For a Square attempt it re-reads the payment from Square (`DoughBoss_Square::retrieve_payment_intent`), rejects `failed/voided` (releasing the voucher) and `processing/unknown` (`payment_pending`), rejects non-v2 attempts, restores the snapshot, recomputes the binding hash from the request and compares it to both the attempt and the snapshot, derives the expected amount from the SNAPSHOT total, and then applies the final strict comparison (`:4329`).
6. The order is rebuilt from the snapshot (name, email, phone, notes, address, lines, totals: `:3690-3705`), the voucher is redeemed (`:3719-3775`), `Order::create` runs with `payment_status 'paid'`, `payment_method 'square'`, `payment_intent_id` = Square payment id (`:3780`), then the snapshot is marked complete and the attempt becomes `order_committed` (`:3826-3829`), the cart is cleared and the confirmation email sent (`:3861`).

## 5. Payments

### 5.1 Gateway contract (`includes/class-doughboss-payment.php`, same in B)

`DoughBoss_Payment` is a facade selected by the `payment_gateway` setting (default `stripe`, `class-doughboss-settings.php:194`; valid set `stripe|tyro|mpgs|square`, `:784-787`). Each gateway class exposes static methods:

| Method | Meaning |
| --- | --- |
| `ready()` | Master gate: `payments_enabled` AND gateway selected AND credentials complete for the mode (live adds an approval flag). |
| `to_minor_units( $amount )` | `(int) round( $amount * 100 )`. AUD prices are GST-inclusive, so this is a unit change only. |
| `publishable_key()` | Browser bootstrap identifier. |
| `create_payment_intent( $amount_minor, $currency, array $metadata )` | Server-owned creation. |
| `retrieve_payment_intent( $id )` | Re-read and normalise to `{id, status, amount, currency, metadata}`; status vocabulary `succeeded|processing|voided|failed|unknown` (plus `refunded|partially_refunded` for MPGS). |
| `canonical_id( $id )` | The id stored on the order row and used for dedup and webhooks. |
| `create_refund( $id, $amount_minor = null )` | Full or partial refund. `refund_via( $gateway, ... )` targets the gateway recorded on the order (`:154-166`). |

Gate checks (`class-doughboss-settings.php`): `stripe_ready` `:771-775` (live additionally needs the webhook secret); `tyro_ready` `:1078-1084` (live needs `tyro_live_certified`); `mpgs_ready` `:991-998` (live needs `mpgs_live_approved`); `square_ready` `:933-943`.

### 5.2 Stripe, Tyro, MPGS (identical in B and C unless noted)

- **Stripe**: hosted Checkout Session redirect for storefront orders (`class-doughboss-stripe.php:219`), PaymentIntent for catering; webhook `t=...,v1=...` HMAC-SHA256 with a 300-second tolerance (`:573-612`); signed webhook can recover a paid order from the snapshot (`rest-controller.php:5304-5437`); refund `:489`. Live state: present, test mode (`ops/README.md:104`).
- **Tyro Connect Pay**: OAuth (`https://auth.connect.tyro.com/oauth/token`), API `https://api.tyro.com/connect/pay` (`class-doughboss-tyro.php:16-17`); one `tyro_location_id` per shop (`class-doughboss-locations.php:701-711`); webhook is HMAC-SHA256 over the raw body in hex (`:247-257`). `readme.txt:69-73` calls it pre-final and disabled by default.
- **MPGS**: Hosted Checkout session with merchant id and API password, notification URL, `mpgs_live_approved` gate; catering unsupported (`rest-controller.php:4945-4947`).

### 5.3 Square in detail (C `includes/class-doughboss-square.php`, 957 lines)

**Identifiers and endpoints (OBSERVED)**

| Item | Value | Line |
| --- | --- | --- |
| Sandbox API host | `https://connect.squareupsandbox.com` | 49 |
| Production API host | `https://connect.squareup.com` | 54 |
| Web Payments SDK (sandbox / production) | `https://sandbox.web.squarecdn.com/v1/square.js` / `https://web.squarecdn.com/v1/square.js`; loaded only from Square's CDN | 59, 64, 120-122; `class-doughboss-assets.php:245-248` |
| Payments create / retrieve | `POST /v2/payments`, `GET /v2/payments/{id}` | 413, 539 |
| Refund | `POST /v2/refunds` | 805-816 |
| Location read (connectivity) | `GET /v2/locations/{id}` | 829 |
| Headers | `Authorization: Bearer`, `Square-Version`, `Content-Type`, `Accept`; 25 s timeout | 914-923 |
| Limits | idempotency key max 45, `reference_id` max 40 | 69, 74 |

**Money and reference rules**

- Idempotency key `db-{op6}-{32 hex}` from sha256 of `operation|identity` (`:195-199`). The payment key identity is the checkout key (not the card token), so a retry replays the same payment. The refund key is `refund|{payment id}|{amount}`.
- `reference_id` is `db-attempt-{attempt id}` (`:233-235`). This is the only binding between a Square payment and this site's attempt row.
- Status map (`:209-224`): `COMPLETED` is `succeeded`; `APPROVED`, `PENDING` are `processing`; `CANCELED` is `voided`; `FAILED` is `failed`; anything else is `unknown`.
- Metadata cannot live at Square (no arbitrary map), so immutable checkout metadata is rebuilt from the attempt row on retrieval (`:21-26, 597-600`).

**Amounts and double verification (OBSERVED)**

1. Server computes the total from the stored cart (`class-doughboss-cart.php:308-358`): GST-inclusive (`tax_rate` default 10, `gst_inclusive` default 1: `class-doughboss-settings.php:74-75`), `tax = round( total * rate / (100 + rate), 2 )`, voucher discount applied to goods before tax, delivery fee added.
2. The attempt row records `amount_minor` and `currency`; the snapshot records the total.
3. After the POST, `payment_matches()` (`:733-748`) requires a positive id, `amount === expected`, currency equal, and `reference_id` equal via `hash_equals`; any miss is `doughboss_pay_create` and the attempt is bound as `mismatch` (`:421-431`).
4. `retrieve_payment_intent()` (`:561-656`) re-reads from Square and re-checks amount, currency, reference and that the payment's Square location equals the location stored in the attempt; a miss quarantines the attempt as `mismatch`.
5. `/checkout` (`rest-controller.php:4329`) requires status `succeeded`, amount equals the snapshot total in minor units, currency equals settings, metadata checkout key, order type, location, table and QR ids all equal.
6. `orphan_payment()` (`:678-712`) proves ownership of a payment with no attempt row by location plus the `db-attempt-` prefix, but may only flag it for a human.
7. GST handling: no Square tax object is sent; the single amount is the GST-inclusive total. Test `GST-inclusive AUD totals are charged as-is, never grossed up again` pins this (`tests/test-square.php:99-120`).

**Refunds**: `create_refund()` (`:787-817`) finds the attempt by payment id, refunds `amount_minor` (full) or a smaller positive amount, with no `reason` and no inspection of the refund status. The only caller is `handle_refund_order` (`admin/class-doughboss-admin.php:1840-1873`): full refund, then `update_payment_status( 'refunded' )` regardless of the returned refund state. A redeemed voucher is deliberately not reissued (`:1834-1838`). Loyalty reverses points on `refunded` (`class-doughboss-loyalty.php:221-224`).

**Webhook (`square_webhook`, `rest-controller.php:5523-5617`)**: validates `x-square-hmacsha256-signature` as base64 HMAC-SHA256 of notification URL + raw body (`class-doughboss-square.php:878-890`, fail-closed on missing key/header/body, `hash_equals`); the URL defaults to `rest_url('doughboss/v1/square-webhook')` with an operator override (`class-doughboss-settings.php:914-923`); only `payment.*` events with a canonical id are processed; de-duplicated by `sha256('square|' . event_id)` through `claim_event` with a 5-minute lease (`class-doughboss-payment-attempts.php:684-733`); the payment is re-read, not trusted; unknown reference tries `orphan_payment` and records `doughboss_unreconciled_payments` (an option capped at 50 entries, `rest-controller.php:5772-5836`); terminal `failed/voided` releases the voucher (C only), failure to release returns HTTP 500 so Square retries. It never creates an order and never refunds. The admin help text asks for subscriptions to `payment.created` and `payment.updated` (`admin/class-doughboss-admin.php:3575`).

**Settings and gates (OBSERVED)**

| Option (key in `doughboss_settings`) | Default | Meaning | Line |
| --- | --- | --- | --- |
| `payments_enabled` | 0 | Master switch, off by default. | settings 193 |
| `payment_gateway` | `stripe` | Square must be selected explicitly. | 194 |
| `square_mode` | `test` | `test` = sandbox, `live` = production. | 233 |
| `square_api_version` | `2025-01-23` | Sent as `Square-Version`; validated as `YYYY-MM-DD`. | 234, 816-819 |
| `square_webhook_url` | blank | Override of the signed URL. | 235 |
| `square_test_app_id`, `square_live_app_id` | blank | Public. A `sandbox-` id is rejected in live mode and vice versa (`:828-841`). | 236, 240 |
| `square_test_location_id`, `square_live_location_id` | blank | Public; 4-64 chars `[A-Za-z0-9_-]` (`:847-851`). | 238, 242 |
| `square_test_access_token`, `square_live_access_token` | blank | Secret; env-first `DOUGHBOSS_SQUARE_TEST_ACCESS_TOKEN` / `..._LIVE_...`, option is a write-only fallback (`:861-865`); shape check only (`:878-881`). | 237, 241 |
| `square_test_webhook_key`, `square_live_webhook_key` | blank | Secret; env-first `DOUGHBOSS_SQUARE_*_WEBHOOK_KEY` (`:890-895`). | 239, 243 |
| `square_live_approved` | 0 | Live approval flag. | 244 |

`square_ready()` (`:933-943`) is true only when payments are on, gateway is `square`, the application id (mode-checked), location id and token are valid, AND (sandbox, or `square_live_approved` AND a webhook key). Per shop, `online_payment_enabled` must also be 1 (`class-doughboss-locations.php:720-726`). Admin saves go through `keep_secret()` so a routine save cannot wipe secrets (`admin/class-doughboss-admin.php:444-465`).

**B versus C for Square**

| Aspect | B 2.41.0 (live) | C 2.43.2 |
| --- | --- | --- |
| Attempt key | `sha256('square|'.checkout_key)` (B square.php:290-296) | `sha256('square-v2|'.attempt_identity)` (C :303) |
| Dispatch claim | `claim_creation`, reclaimable after a 5-minute lease; a failed POST calls `release_creation` and replays by idempotency key (B :328-358) | `claim_irreversible_creation`; never reclaimed; failure becomes `unknown` (C :382-417) |
| Cart concurrency | None beyond the checkout key | Named lock and cart guard blocking a second nonce while money is unresolved (C :304-325) |
| Order source at `/checkout` | Rebuilt from live cart | Rebuilt from the immutable snapshot, binding hash enforced |
| Voucher | Not reserved for Square | Reserved, marked payment-pending, released only on proven terminal failure |
| Webhook | Flags paid-without-order | Same, plus voucher release on terminal failure |
| Attempt states | `created, provisioning, ...` | `prepared, dispatching, processing, unknown, succeeded, failed, voided, mismatch, order_committed` |
| Legacy handling | n/a | Drains unresolved legacy attempts fail-closed (`find_blocking_legacy_square`) |

### 5.4 Tests that cover Square (OBSERVED)

Unit, `tests/test-square.php` (531 lines, 26 `db_test` blocks, 94 assertions, identical in B and C): minor-unit conversion (`:78, 88`), GST not grossed up (`:99`), idempotency key length and stability (`:124, 140`), webhook signature accept/tamper/wrong key/missing header/URL binding/no key (`:170-245`), `payment_matches` accept, amount mismatch both ways, currency and binding mismatch, malformed object (`:262-355`), inert by default, off while another gateway is active, off while payments disabled, incomplete credentials, ready sandbox, live approval and webhook-key gates, sandbox/live id cross-use (`:361-473`), status map (`:482`), `canonical_id` validation (`:495`), reference length (`:507`), API version validation (`:520`).

Integration, `tests/integration/wordpress-mysql.php` (C only; real WordPress + MySQL, provider HTTP intercepted by `pre_http_request`): transport fixture `:147-190`; v2 metadata builder `:385-400`; exactly one provider POST per attempt, replay by GET, cart-guard blocking, post-commit new attempt, mismatch, stale failure, mode drift, unknown outcome never reposts, guard read failure, voucher claim and release, signed terminal webhook, retryable webhook after storage failure, REST `/payment-intent` then `/checkout` with changed-contact rejection, currency drift, legacy drain (`:512-960`). Two-process race: `tests/integration/square-race-worker.php` (one provider POST).

NOT covered (grep of `tests/` for `create_refund`, `orphan_payment`, `unreconciled`, `refund`: no matches): Square refund, orphan flagging, the admin refund handler, refund events, order or fulfilment events, location mapping, any Orders API call.

## 6. The Square gap: engineering map for itemised Square Orders

Goal: when a customer pays online, Square holds an itemised order (line items, modifiers, tax, discount, pickup details) linked to the payment, so it shows in Square POS and Order Manager and can feed a KDS, while WordPress stays the canonical menu and order system and the only writer of menu and order data.

### 6.1 Constraints that shape the design

- PHP 7.4+ and WordPress 6.0+ (`doughboss.php:7-8`): arrays via `array()`, no arrow functions, `match`, nullsafe, typed properties or PHP 8 string helpers (C uses none; `php -l` passes on PHP 8.3.6, not run on 7.4).
- Hand-written JS is ES5 with no build step (`public/js/doughboss-square.js:27-28`).
- Fail closed, payment-off defaults, secrets env-first and never echoed (`class-doughboss-settings.php:703-714`).
- One writer per datum: DoughBoss owns menu, prices, modifiers and orders; Square receives a projection. Never import Square POS sales into `doughboss_orders` (they would become a second writer and would hit the board and printer).
- Payment success alone is not a kitchen order (`docs/SQUARE-MIGRATION-20260908.md:23, 48`); WordPress and Square cannot share a transaction, so every failure window needs a recovery owner.

### 6.2 Two build options (decision for Elie and the lead)

| | A: Web Payments SDK plus Orders API (extend the existing flow) | B: Square-hosted Payment Link or Checkout API (mirror the Stripe hosted flow) |
| --- | --- | --- |
| Customer UX | Unchanged inline card form. | Redirect to Square, return to site. |
| Reuses | `square-v2` protocol, attempts, snapshots, voucher lease, 94 + integration tests. | Stripe pattern: return URLs (`rest-controller.php:2869-2918`), `stripe_paid_order_replay` (3885), `recover_stripe_order` (5304). |
| New work | Create Square order before payment; pass `order_id` into the payment; verify order totals; webhook for order, fulfilment, refund. | New hosted-session class; snapshot/recovery wiring; return handling; different idempotency model. |
| Risk | Order creation adds a provider call before dispatch. | Replaces a hardened, tested path; UX regression risk. |

INFERRED recommendation: A for the pilot, because it extends what exists and keeps the existing tests as a safety net. B remains viable if Square Payment Links give a cheaper PCI and 3DS story. The orchestrator task list names "Orders + Payment Links checkout", so both may be wanted; the mapping, location, link-table and kitchen-owner work below is common to both.

### 6.3 Where the code would change (class and method level)

| Change | Where (C) | Notes |
| --- | --- | --- |
| New class `DoughBoss_Square_Orders` with `build_order_body( $snapshot, $attempt, $map )`, `create_order()`, `retrieve_order()`, `update_order()`, `cancel_order()`, `assert_order_matches( $square_order, $snapshot )` | new file, require it next to Square at `includes/class-doughboss.php:86` | Pure builder first (unit-testable, no I/O). |
| Make the HTTP wrapper reusable | `DoughBoss_Square::request()` is `private static` (`class-doughboss-square.php:909`) | CORE PATCH: make it `public static` (or add `api_request()`), reuse headers, version, timeout and the error-log redaction at `:941-955`. |
| Pass the order into the payment | `create_payment_intent()` body (`:398-408`) | Add `order_id` when present in metadata; add the expected order id to `payment_matches()` (`:733-748`) and `payment_payload()` (`:760`). |
| Create the Square order before dispatch | `rest-controller.php:2742-2778` (after snapshot store, before `create_payment_intent`) or inside `DoughBoss_Square::create_payment_intent` after the attempt is `prepared` (`:326-362`) and BEFORE `claim_irreversible_creation` (`:382-396`) | Order creation moves no money, so a failure here is `retry_safe` and must happen before the irreversible claim. |
| Verify against Square's order, not only the payment | `retrieve_payment_intent()` (`:561-656`); `/checkout` verify (`rest-controller.php:4205-4363`) | Add: order id equals link row, `total_money` equals attempt amount, location equals mapped location. |
| Link WordPress order to Square order after `/checkout` | `rest-controller.php:3826-3829` (next to `mark_order_committed`) | Write `wp_order_id` on the link row; best-effort update of the Square order ticket name/metadata with the WordPress order number (enqueue, do not block). |
| Webhook for order, fulfilment and refund events | `square_webhook()` (`:5523`); the ignore branch at `:5545-5548` | Extend the event allow-list and add handlers; reuse `claim_event` (`class-doughboss-payment-attempts.php:684`). Admin help text at `admin/class-doughboss-admin.php:3575` must list the new subscriptions. |
| Recovery of a paid payment whose `/checkout` never landed | `square_webhook()` orphan branch (`:5566-5586`) | Replace "flag for a human" with snapshot-based order creation, copying `recover_stripe_order()` (`:5304-5437`) and `finalize_stripe_order()` (`:4027-4062`). Must suppress a second kitchen dispatch (section 7.4). |
| Refunds | `create_refund()` (`class-doughboss-square.php:787-817`); admin handler (`admin/class-doughboss-admin.php:1840-1873`) | Read refund status; add `refund.*` webhook; do not mark `refunded` until Square confirms; support partial refunds (needs a new payment-status value or a ledger table). |
| Connectivity check | `test_connection()` (`:824-837`) has no caller | Add a REST route beside `/pay/tyro-test` (`rest-controller.php:424`). |

Items marked CORE PATCH are the smallest edits to existing classes. Everything else can live in a companion plugin using the hooks in section 11.

### 6.4 Catalog mapping (plugin menu to Square catalog): where it lives

What exists to map (OBSERVED): menu items are the custom post type `doughboss_item` (`class-doughboss-post-types.php:17`) with price meta `_doughboss_price` (`:20`), category taxonomy `doughboss_category`, availability meta `_doughboss_available` (`:22`). Menu items have NO size (`size` is `''` for menu lines: `rest-controller.php:3253-3256`); sizes exist only for custom pizzas (`DoughBoss_Settings::find_size`, line price from settings, `:3260-3299`, custom lines have `item_id 0`). Option groups are code (`class-doughboss-menu-options.php:63-190`); `for_item( $category, $name )` returns radio and check groups, with item-name special cases (`:193-238`); `resolve()` always selects a default for radio groups and records each selection as `{slug: group--choice, label, price}` (`:240-300`). `remove` ("No cheese" etc.) and `lemon_chilli` choices are $0 modifiers. The cart merges identical configurations by a hash of type, item id, size and sorted modifier slugs (`class-doughboss-cart.php:175-187`).

Where the map should live (INFERRED, recommended): a dedicated table, not option storage and not name keys.

`{prefix}doughboss_square_catalog_map`

| Column | Type | Purpose |
| --- | --- | --- |
| `id` | bigint PK | |
| `environment` | varchar(8) | `test` or `live`; sandbox and production ids never mix. |
| `local_type` | varchar(16) | `item`, `modifier`, `discount`, `service_charge`, `tax`. |
| `local_key` | varchar(191) | `item:{post_id}`; `modifier:{post_id}|{slug}` (per item, see collision below); `discount:voucher`; `service_charge:delivery`; `tax:gst`. |
| `square_object_type` | varchar(32) | `ITEM_VARIATION`, `MODIFIER`, `TAX`, `DISCOUNT`. |
| `square_object_id` | varchar(64) | The catalog object id. |
| `square_parent_id` | varchar(64) | Item id for a variation, modifier-list id for a modifier. |
| `name_at_map`, `price_minor_at_map` | varchar, int | Drift detection if WordPress renames or reprices. |
| `status`, `verified_at` | | `verified`, `stale`, `unmapped`. |
| UNIQUE | `(environment, local_type, local_key)` | |

Reasons: the attempt `safe_metadata_json` is capped at 8,192 bytes and silently drops keys containing `secret|card|pan|cvc|cvv|expiry|expir|cryptogram|track|payload|request|response|body|token|source` (`class-doughboss-payment-attempts.php:863-904`), so a line-level map cannot live there; the `doughboss_settings` option is the POSPal precedent (`pospal_product_map`, `class-doughboss-settings.php:1257-1260`) but it is name-keyed and flat; the existing REST save route for it is `/pospal/product-map` (`rest-controller.php:454, 2003-2041`) and the CLI is `wp doughboss pospal-map` (`class-doughboss-cli.php:450`), which are good UI patterns to mirror.

**Modifier collision (OBSERVED)**: `groups()['style']` and `groups()['zaatar_style']` both use group id `style` with choices `flat` and `folded` but different prices (`menu-options.php:65-81`). A map keyed by `style--flat` alone is wrong. Key modifiers per menu item (`item:{post_id}`), and resolve the group via `for_item()` at build time, or key by `(slug, price)` as a minimum.

**Price authority (INFERRED, must be verified in the sandbox)**: because WordPress is canonical, order lines should carry an explicit price per line and per modifier, using Square catalog ids for identity and reporting only, and the order must be rejected unless Square's returned total equals `attempt.amount_minor`. Whether a given Square `catalog_object_id` plus an explicit price is honoured as an override, and how Square rounds inclusive GST, must be proved with a synthetic sandbox order. Rounding is a real risk: the plugin computes `tax = round( total * rate/(100+rate), 2 )` on the whole order (`class-doughboss-cart.php:341`), whereas Square may compute per line.

Phase the work (INFERRED): phase 1 uses ad hoc line items (name, quantity, price) so the order appears in Order Manager with no catalog dependency; phase 2 adds the catalog map for item-level reporting and KDS routing by category. Custom pizza builder lines (item id 0) stay ad hoc.

Authority for catalog edits is unresolved (`docs/SQUARE-MIGRATION-20260908.md:36`): if staff edit prices in Square POS, WordPress prices drift. A nightly read-only drift report (Square catalog versus `_doughboss_price`) is safer than a two-way sync.

### 6.5 Location mapping (WordPress location to Square location): where it lives

Observed: global setting (finding 2). The shop table already carries `tyro_location_id` (`class-doughboss-activator.php:200`) and `online_payment_location()` gates online payment per shop. A new `square_location_id` column on `doughboss_locations` is possible but costs core edits: `sanitize()` (`class-doughboss-locations.php:118-166`), TWO positional wpdb format arrays that must stay in lock-step (`:201` and `:228`), the admin form (`admin/class-doughboss-admin.php:2224, 2292`), and the column contract (`class-doughboss-activator.php:790-795`).

Recommended (INFERRED): a side table owned by the companion plugin.

`{prefix}doughboss_square_locations`: `wp_location_id`, `environment`, `square_location_id`, `square_merchant_id`, `currency`, `timezone`, `tax_catalog_id`, `kitchen_owner` (`wordpress|square`, section 7.4), `status`, `verified_at`; UNIQUE `(environment, wp_location_id)` and UNIQUE `(environment, square_location_id)`. Populate it by a manual, owner-approved binding (the repo docs say never map every shop to Revesby by default: `docs/SQUARE-MIGRATION-20260908.md:22`), then verify with a read-only location fetch.

Call sites to repoint (CORE PATCH, small): add `DoughBoss_Square::location_id_for( $wp_location_id )` that consults the table and falls back to the global setting; use it at `class-doughboss-square.php:261` (and thereby 300, 405), 577, 689, 825, 910 and in `class-doughboss-assets.php:288` plus `doughboss-square.js:165` (the SDK needs the location of the shop the customer picked; today the config is page-global). Attempt `safe_metadata` already stores `square_location_id` (`:300`), and retrieval checks the payment against it (`:599-614`), so per-attempt location binding is partly in place.

### 6.6 Pickup details and fulfilment, built from the order

All values below exist in the snapshot or order row (OBSERVED sources); Square field names are INFERRED.

| Square concept (INFERRED name) | WordPress source |
| --- | --- |
| Fulfilment type `PICKUP` (delivery stays out of the pilot; delivery is off live, `ops/README.md:109`, and single-location mode forces pickup, `rest-controller.php:2619-2622`) | `order_type` |
| Recipient display name, phone, email | `customer_name`, `customer_phone` (free text up to 40 chars; normalise to E.164 as `DoughBoss_SMS::normalize_phone` does, `class-doughboss-sms.php:292`), `customer_email` |
| ASAP or scheduled, `pickup_at` | `promised_ready_from_utc` when it is set (by an ETA at acceptance, `class-doughboss-order.php:830-834`, or by a capacity hold; capacity is `off` by default per shop, `class-doughboss-activator.php:192`); otherwise ASAP |
| Prep time | `doughboss_locations.prep_time_default` (default 20, `class-doughboss-activator.php:190`); the board's ETA choices are 10, 15, 20, 30 (`public/js/doughboss-orderboard.js:18`) |
| Note | `notes`; for table orders add the table label as POSPal does (`class-doughboss-pospal-orders.php:157, 167`) |
| Ticket name | WordPress `order_number` is not known until `/checkout` (created after payment); use the attempt reference first, then update after `/checkout` |
| Tax | One inclusive GST tax at 10% (`tax_rate`, `gst_inclusive`) |
| Discount | Fixed-amount order discount from the snapshot `discount` and `voucher_code` (no catalog object) |
| Service charge | `delivery_fee` (pilot: none) |

### 6.7 Order/payment association and recovery

Link table (INFERRED, recommended): `{prefix}doughboss_square_orders` with `attempt_id` UNIQUE, `checkout_key` UNIQUE, `wp_order_id` UNIQUE nullable, `environment`, `square_location_id`, `square_order_id` UNIQUE, `square_order_version`, `square_payment_id`, `state`, `fulfilment_state`, `last_event_id`, timestamps. Rationale: the attempt row is immutable for Square v2 (`class-doughboss-payment-attempts.php:320-335`) and size-capped, and `doughboss_orders` has no spare column. Reuse `doughboss_payment_events` for event de-duplication.

State machine for the link row: `prepared` to `order_created` to `payment_dispatching` to `paid` to `wp_committed`, with side states `fulfilment_*`, `cancelled`, `refunded`, `needs_review`.

Failure windows and who recovers each (INFERRED):

| Window | Today | With Orders |
| --- | --- | --- |
| Order created, payment never dispatched | n/a | Square order stays unpaid. A cron janitor cancels after a TTL. The repo's own document says only fully paid orders surface in POS (`docs/SQUARE-MIGRATION-20260908.md:28`); confirm in sandbox. |
| Payment POST outcome unknown (timeout) | `unknown`, human only (`class-doughboss-square.php:414-417`) | `GET order` by the stored order id exposes tenders and payment ids, giving a deterministic lookup the Payments API alone cannot (verify in sandbox). |
| Payment completed, `/checkout` never lands | Webhook flags to a human (`rest-controller.php:5566-5586`) | With itemised Square order the Square kitchen already has the ticket; WordPress must create the order from the snapshot (copy `recover_stripe_order`) without dispatching a second ticket. |
| WordPress order saved, Square order update failed | n/a | Outbox row (copy `DoughBoss_POSPal_Outbox` shape: UNIQUE idempotency key, backoff, ambiguous rows quarantined: `class-doughboss-pospal-outbox.php:52-59, 131-215, 394-512`). |
| Refund | Admin marks refunded on any 2xx | `refund.*` webhook confirms; ledger the refund. |

### 6.8 Existing tests to copy as patterns

| New test | Copy from |
| --- | --- |
| Pure order-body builder (GST, minor units, voucher discount, rounding, modifier collision) | `tests/test-square.php` (`db_square_settings`, `:30-58`; GST `:99-120`; amount `:78-95`); `tests/test-menu-options.php` (18 assertions) for modifier resolution |
| Idempotency key and reference limits | `tests/test-square.php:124-160, 507` |
| Signed webhook for order, fulfilment, refund events | `tests/test-square.php:170-245` plus integration `:737-796` |
| Per-shop location routing | `tests/test-pospal-routing.php` (store index routing; 9 assertions) |
| Orders API HTTP fixture | the `pre_http_request` handler in `tests/integration/wordpress-mysql.php:147-190`; extend to answer `/v2/orders` |
| Concurrent order creation | `tests/integration/square-race-worker.php` and the PowerShell runner `scripts/test-wordpress-integration.ps1:45-93` |
| REST end to end, changed-contact rejection | `tests/integration/wordpress-mysql.php:800-870` |

Add to CI: the PHP unit suite already runs (`plugin-ci.yml:38`); the integration suite does not and needs a WordPress plus MySQL job before any money path ships.

## 7. Kitchen

### 7.1 What happens when an order is placed, and who receives it (OBSERVED)

`DoughBoss_Order::create` commits then fires `doughboss_order_created( $order_id, $data )` (`class-doughboss-order.php:470`). Consumers, in registration order (`class-doughboss.php:167-189` for init order):

| Consumer | Hook and priority | Gate and default | Payload and behaviour |
| --- | --- | --- | --- |
| Live Order Board (KDS) | none: polls `GET /admin/orders` | always for staff | Poll cadence `cfg.pollMs` default 7,000 ms (`public/js/doughboss-orderboard.js:83`); alert beeps every 1.5 s until acknowledged (`:189-198`); optional Mercure SSE accelerates but the poll is never fully disabled (`:77-80`). Lanes: New / Preparing / Ready (`:20-24`). Excludes `preorder_request` rows (`class-doughboss-order.php:493-513`). |
| Mercure | `doughboss_order_created` and `doughboss_order_status_changed`, 10 | `mercure_ready()`; off by default (`class-doughboss-settings.php:285`) | Minimal non-PII refresh signal, topic `{prefix}/orders` global (`class-doughboss-mercure.php:47-58, 110-160`). |
| ntfy | `doughboss_order_created`, 10 | `ntfy_ready()`; off by default (`class-doughboss-settings.php:292`) | One line "New order #... - type - total (shop)", no PII (`class-doughboss-ntfy.php:42-72, 130-160`). |
| Loyalty | `doughboss_order_created`, 20; `doughboss_order_payment_status_changed`, 20 | Rewards enabled (off by default, `:87`) and order `paid` | Points (section 8). |
| POSPal order push | `doughboss_order_created`, 20 | `pospal_push_enabled()`; live: on (`ops/README.md:106`) | Builds `addOnLineOrder`, enqueues an outbox row (`class-doughboss-pospal-orders.php:35-110`). |
| Printer (CloudPRNT or Epson ePOS) | no hook; printer polls `POST /print/cloudprnt` | `printer_enabled` off by default (`:313`); token required | Serves oldest order above a watermark option; skips cancelled and `preorder_request`; advances only on confirmation (`class-doughboss-printer.php:184-244, 316-344`). |
| Customer email | direct call after create | always | Confirmation to customer and to `orders_email` default `orders@doughboss.com.au` (`class-doughboss-settings.php:147`; `rest-controller.php:5974-6013`). |
| Customer stage emails | `doughboss_order_accepted`, `doughboss_order_status_changed` | `email_on_accepted` and `email_on_ready` default ON (`class-doughboss-settings.php:307-308`) | `class-doughboss-emails.php:75-180`. |
| Customer SMS (ClickSend) | `doughboss_order_status_changed` to `ready` | `sms_enabled` off by default (`class-doughboss-settings.php:299`) | `class-doughboss-sms.php:72-135`. |

INFERRED: `Order::create` fires the hook for after-hours `preorder_request` rows as well, and the ntfy and Mercure handlers do not check `order_source` (`class-doughboss-ntfy.php:54-72`), so an unpaid, unconfirmed request pings the kitchen as "New order" while the board hides it. POSPal explicitly defers it (`class-doughboss-pospal-orders.php:61-68`).

Who sees the order on the board: owners and managers see all shops; a KDS-only account is pinned to its assigned shop (`class-doughboss-staff-scope.php:41-130`, `LOCATION_META = 'doughboss_location_id'`); an optional board key adds a header check (`rest-controller.php:1329-1355`). Board copy still says it never touches "Tyro/MPGS payment or POSPal record" and does not mention Square (`doughboss-orderboard.js:584`), and after a pre-order acceptance staff must create the POS action and kitchen ticket manually (`:700`).

### 7.2 POSPal sync and outbox (identical in B and C)

- Voucher mirror (`class-doughboss-pospal-sync.php:42-190`): on claim, ensure a POSPal member by phone and grant the coupon in every configured store; on redeem, revoke by code in every store, fire-and-forget. A comment says revoke should move onto the outbox (`:155-163`).
- Order push (`class-doughboss-pospal-orders.php`): needs a product uid per item; `manualSellPrice` carries the website price so a till price gap never mis-charges (`ops/state/pospal-mapping-analysis.md`); sizes and modifiers become a text comment only (`:222-244`); any unmapped item skips the entire push and records an admin alert capped at 20 (`:80-83, 258-284`); store index comes from `doughboss_locations.pospal_store_index` (default store 1) and the filters `doughboss_pospal_order_store_creds`, `doughboss_pospal_order_store_index` (`:77, 91-99`).
- Outbox (`class-doughboss-pospal-outbox.php`): UNIQUE `idempotency_key` `order:{id}:store:{n}` (`:198-205`); WP-cron worker; backoff 60, 300, 1800, 1800, 1800 s, max 5 attempts (`:52-59`); a success response without POSPal's `orderNo` is quarantined as ambiguous, not retried (`:436-444`); succeeded rows pruned after 30 days; hourly reconcile; terminal and ambiguous rows are surfaced in wp-admin.
- Product map evidence: `ops/state/pospal-product-map.json` has 43 entries (OBSERVED; counted); `docs/REVIEW-20260908.md:124` records that custom pizza names are unmapped.

### 7.3 Printer and receipts

`render_ticket()` (`class-doughboss-printer.php:436-527`) prints order number, type, customer name and phone, address, items with modifiers, subtotal, "Tax (incl)", total and notes, as StarPRNT or plain text; `render_epos_xml()` is the Epson form (`:529-614`). Not found: any ABN or "tax invoice" text (grep `ABN|tax invoice` over `includes admin themes`), so the ticket is a kitchen docket, not a GST tax invoice. The watermark is a single option for the whole site, not per shop (finding 9).

### 7.4 Duplicate prevention today, and what must change for Square POS/KDS

Existing de-duplication (OBSERVED):

| Surface | Mechanism |
| --- | --- |
| Order row | UNIQUE `checkout_key` and `payment_intent_id`; replays fire no hook (`class-doughboss-order.php:357-380`) |
| Status changes | UNIQUE `event_key` and `(order_id, order_version)`; compare-and-set on version |
| POSPal | UNIQUE outbox `idempotency_key`; ambiguous outcomes quarantined |
| Email | `already_sent( $order_id, 'accepted'|'ready' )` marker (`class-doughboss-emails.php:112, 150`); the voucher email uses an atomic `add_option` marker (`:280`) |
| Loyalty | UNIQUE ledger `event_key` `order:{id}` (`class-doughboss-loyalty.php:291, 312-316`) |
| Printer | Watermark; at-least-once (a lost DELETE confirm reprints: INFERRED) |
| ntfy, Mercure, SMS | None; they rely on `create`/`transition` firing once |

If Square POS or KDS becomes a kitchen target there would be two parallel ticket sources for one order: the Square order (visible from payment time) and the WordPress order (created after `/checkout`). Required changes (INFERRED):

1. Add a per-shop `kitchen_owner` (`wordpress` | `square`), stored with the location mapping (section 6.5). Exactly one owner per shop; no dual dispatch.
2. Add veto points. Today there is no filter to stop a consumer. CORE PATCH (3 small filters): `doughboss_kitchen_dispatch_allowed( $allowed, $order, $channel )` consulted in `DoughBoss_POSPal_Orders::on_order_created`, `DoughBoss_Printer::next_unprinted`, `DoughBoss_Ntfy::on_order_created`, `DoughBoss_Mercure::publish`. Without a patch the only configuration-only option is to turn off `pospal_push_orders` and `printer_enabled` for the site, or remove the static callbacks with `remove_action( 'doughboss_order_created', array( 'DoughBoss_POSPal_Orders', 'on_order_created' ), 20 )` (works for static callbacks only; Loyalty registers `$this` callbacks, `class-doughboss-loyalty.php:39-40`).
3. A status bridge Square to WordPress: Square fulfilment events call `DoughBoss_Order::transition` with `actor_type 'square'` and `event_key = 'square:{event_id}'` so customer tracking, emails and SMS stay truthful. It needs a read-then-write loop on `version` and a mapping `PROPOSED/RESERVED/PREPARED/COMPLETED/CANCELED` to the plugin lifecycle (INFERRED Square names). Skipped states (WordPress has `preparing` and `baking`, Square may not) must be allowed by the graph, which forbids skipping `confirmed` to `ready` (`class-doughboss-order.php:80-97`).
4. Webhook-recovered WordPress orders must carry a flag meaning "ticket already issued by Square" so `doughboss_order_created` consumers skip them.
5. The WordPress board needs a read-only mode for Square-owned shops, and its copy must stop mentioning only Tyro/MPGS/POSPal.
6. Do not announce an accepted order or print twice from competing POSPal and Square paths (`docs/SQUARE-MIGRATION-20260908.md:48`). Cutover per shop, with the POSPal outbox drained first.
7. Square POS sales rung at the till never exist in `doughboss_orders`. Reports, loyalty and vouchers that depend on that table will not see them (sections 8 and 10).

## 8. Promotions

### 8.1 Student vouchers

Campaigns (`class-doughboss-voucher.php:985-1060`): the default is `dough5`: `$5.00` amount, `min_spend 3.00`, prefix `DOUGH`, `daily_cap 100`, `cap_group student`, `scope both`, `requires_student_email 1`, active; legacy `snow5` is inactive with cap 0 but stays redeemable. Owner can override with the `voucher_campaigns` setting.

Rules (OBSERVED):
- Hard ceiling: an amount voucher above $5.00 is refused at issue (`:118`); `evaluate()` clamps to `min( amount, subtotal, 5.00 )` and a minimum spend of at least $3.00 (`:246, 257`).
- Claim (`claim()`, `:1193-1300`; REST `POST /voucher/claim`, nonce + 8 per 10 minutes, `rest-controller.php:2494-2515`): the student email must be entered twice, match, and end in `.edu` or `.edu.au` (regex `/(?:^|\.)edu(?:\.au)?$/i`, `:1073-1087`); a named DB lock per cap group serialises count then issue; the pool is capped at 100 per site-local day, shared across the group (`:1145-1190`); one voucher per student email across the whole allocation group (`student_email_claimed`, `:1095-1114`, checked under the lock). The claim response returns the code immediately (`rest-controller.php:2509-2514`), so ownership of the inbox is not proved (INFERRED).
- Single use: redeem is one conditional `UPDATE ... WHERE status = 'issued'` plus an immutable redemption row with a UNIQUE idempotency key (`:634-780`).
- Online use: the code is bound to the claim email at the payment boundary (`reserve()`, `:303-312`) and leased to one checkout (45 minutes, `:37`).
- Codes use a check-character scheme (`class-doughboss-coupon-code.php`): typos fail before a DB read.

In-store redemption: staff scan with `POST /voucher/scan` (`verify_redeem`, `rest-controller.php:1601-1660`). It needs (a) a reconciliation owner set in `voucher_reconciliation_owner_id` who holds `manage_doughboss` (otherwise 503: `:1605-1611`; live: set per `ops/README.md:107`), (b) the till receipt reference `transaction_ref` (3-64 characters, `^[A-Za-z0-9][A-Za-z0-9 ._\/-]{2,63}$`, `class-doughboss-voucher.php:661`), (c) a signed-in cashier whose id and name are recorded. The receipt reference is stored in `pospal_ticket_no` and `transaction_reference` with UNIQUE `(location_id, transaction_reference)` (`class-doughboss-activator.php:413`). Manager reversal keeps an audit trail (`reverse_redemption`, `:1383-1490`; routes `/voucher/void`, `/voucher/reverse`).

Findings:
- INFERRED: in-store redemptions write `location_id` 0 (the voucher row's location, `class-doughboss-voucher.php:740`, and `/voucher/scan` passes no location), so the UNIQUE `(location_id, transaction_reference)` index is effectively site-wide. Two shops with the same receipt numbering would collide. Square receipt or payment ids are long enough to avoid this, but the index semantics should be fixed when a second shop starts redeeming.
- The reconciliation owner is a single WordPress user id, which is the "reconciliation owner" concept the brief asks about. It governs whether scanning runs at all.
- The in-store leg today is: scan in WordPress, then ring the discount manually on the till. With Square POS this becomes dual entry unless a Square discount and the Square payment id as `transaction_ref` are used. Square payment ids match the allowed pattern.
- POSPal coupon mirror (grant on claim, revoke on redeem) is separate from the manual scan and would need a Square equivalent, or be retired when POSPal is.
- B differs: no voucher payment-pending reservation for Square (`B class-doughboss-voucher.php:325`).

### 8.2 Loyalty and rewards (`class-doughboss-loyalty.php`, identical in B and C)

Off by default (`loyalty_enabled` 0, `class-doughboss-settings.php:87`). Passwordless members: an emailed one-time link (20 minutes, hashed token, single consumption) creates a low-privilege `subscriber` user flagged `doughboss_loyalty_account` (`:151-206`); it refuses accounts that are not deliberate loyalty accounts so the link cannot be a route into staff accounts (`:168-173`, check at `:172`).

Earning (`maybe_award_order`, `:207-219`): on `doughboss_order_created` and when `payment_status` becomes `paid`; base points = `floor( ( subtotal - discount ) * points_per_dollar )`, default 1 point per $1 (`:214`); cancelled orders and unpaid orders earn nothing. One promotion per paid order from three seeded types: welcome (50 bonus on first paid order), a date-window multiplier (launch dates blank), and Tuesday double points with a $15 minimum (`class-doughboss-settings.php:96-130`; `award_for_order`, `:269-286`). Refund reverses points (`:221-224, 329-334`).

Redemption: 100 points buys a personal `$5` voucher (`loyalty_redemption_points 100`, `loyalty_redemption_amount 5`) through the same voucher engine, campaign `loyalty_rewards`, prefix `DBR`, bound to the member email (`:227-247`). Tiers by spend: Dough Club, Fresh Regular at $150, Boss Member at $400 (`:322-326`).

Storage: `loyalty_members` (balance, tier, lifetime figures), `loyalty_ledger` (UNIQUE `event_key`, so `order:{id}` cannot double award), `loyalty_tokens` (`class-doughboss-activator.php:515-563`).

Gap for Square: points exist only for online orders keyed by email. In-store Square POS sales earn nothing. A bridge would need a Square payment or customer to loyalty member match (email or phone) and a consent basis; the ledger `event_key` makes a safe idempotent key (`square-payment:{id}`).

### 8.3 Coupon codes

There is no separate promo-code engine. `DoughBoss_Coupon_Code` only generates and validates the check-character body of voucher codes (`class-doughboss-coupon-code.php:93, 149, 203`). Every discount is a voucher. The maximum single discount is $5.00 by hard rule (`class-doughboss-voucher.php:118, 257`).

### 8.4 Marketing consent capture

Exists only on the Rewards sign-in form: an unticked "Email me occasional Dough Boss offers. Optional." checkbox (`class-doughboss-loyalty.php:94`), recorded as `update_user_meta( ..., 'doughboss_marketing_consent', <UTC timestamp> )` (`:177-178`). It is write-only (grep: no reader), has no source, wording version, withdrawal or IP/user agent record, and only exists for loyalty accounts. Not captured: checkout, pre-order, voucher claim (which collects student email and optional phone), catering enquiry. The consent-gated browser bridge (`doughboss-marketing.js`) is a different consent (advertising/measurement) and nothing dispatches it (finding 11). Australian spam and privacy obligations were not assessed here and need review before any marketing send.

## 9. Catering

Data model (`class-doughboss-catering.php`, `class-doughboss-catering-package.php`):

- Packages are the custom post type `doughboss_cat_pkg` (20-character limit noted at `class-doughboss-catering-package.php:23-26`) with meta `_doughboss_cat_serves_min/_max`, `_base_price`, `_per_head`, `_deposit_pct` (default 30), `_lead_days` (default 2), `_includes` (`:28-44`). Package names and prices are not in code (grep for names found only a docblock at `:5`); the repo records "4 packages published" (`ops/README.md:108`). Names and prices: not found.
- Enquiries live in `doughboss_catering_enquiries` (`class-doughboss-activator.php:326-363`): enquiry number `CAT-ymd-NNNN`, `location_id`, `package_id`, `status`, customer name/email/phone, `event_date`, `event_time`, `guest_count`, `order_type`, `address`, `dietary`, `notes`, `subtotal`, `delivery_fee`, `quote_total`, `deposit_amount`, `balance_amount`, `currency`, `deposit_intent_id`, `balance_intent_id`, paid and quoted timestamps. Not captured: UTM, click ids, referrer, consent, lead score, source page.
- Statuses (`class-doughboss-catering.php:21-40`): `new, quoted, deposit_paid, confirmed, balance_due, paid, fulfilled, lost`.

Workflow:
1. Public form calls `GET /catering/packages` and `GET /catering/quote`; `POST /catering/enquiry` (nonce, 5 per hour per IP, honeypot field `hp`: a filled `hp` returns a fake success and saves nothing, `rest-controller.php:4734-4747`). Server recomputes the quote: base price plus per-head overflow beyond `serves_max`, delivery as a flat fee, deposit percent (`class-doughboss-catering.php:95-150`). C rejects a positive package id that is not published (`:105-113`), B silently falls back to a zero custom quote.
2. Guards in `create()`: name and valid email required, guests at most 1,000, event date not in the past (`:152-176`). Not found: captcha or Turnstile, duplicate-submit idempotency key (each POST creates a new enquiry), phone validation.
3. Emails (plain text `wp_mail`, `rest-controller.php:5874-5933`): a customer acknowledgement with package, guests, event date and indicative deposit; a staff email to `catering_email` (default `catering@doughboss.com.au`, `class-doughboss-settings.php:150, 413-418`, no filter) with every field. Subject `[Site] Catering enquiry CAT-... received`.
4. Staff quote: manager-only `POST /admin/catering/{id}/quote` sets custom subtotal, delivery and deposit percent under a per-enquiry named lock; it becomes immutable once a payment intent is prepared (`class-doughboss-catering.php:404-470`). Status changes via `POST /admin/catering/{id}/status` (manager only).
5. Deposit and balance: `POST /catering/payment-intent` and `/confirm-payment` (customer proves ownership with enquiry number and email); only Stripe and Tyro paths work; Square fails closed (finding 10); MPGS refused. Money amounts come from the saved enquiry (`leg_amount`).
6. Admin screens: `DoughBoss -> Catering` list with inline quote editor and status select (`admin/class-doughboss-admin.php:1878-2000`); the board has a CATERING mode listing deposit-paid, booked and paid enquiries (`public/js/doughboss-orderboard.js:33-37`); dashboard tile shows pipeline counts (`class-doughboss-reports.php:295-330`).

Hook and filter points for attribution and lead scoring without editing core logic (OBSERVED hooks; INFERRED usage):

| Need | Extension point | Detail |
| --- | --- | --- |
| Score a new lead | `doughboss_catering_enquiry_created( $id, $row )` (`class-doughboss-catering.php:248`) | `$row` has package, guests, date, order type, location, dietary, notes, quote, deposit; compute a score and store it in a side table keyed by `$id`. |
| Capture source and click ids | Core `rest_request_before_callbacks` (or `rest_pre_dispatch`) filtered on route `/doughboss/v1/catering/enquiry`; undeclared body params are still readable with `$request->get_param()` | Companion JS adds `utm_*`, `gclid`, `fbclid`, `ttclid`, `msclkid`, landing URL and consent version to the POST body; PHP stashes them, then writes them to the side table on the `doughboss_catering_enquiry_created` hook. Alternative: read a first-party cookie from `$_COOKIE` at hook time (the hook fires inside the same request). |
| Funnel stages | `doughboss_catering_status_changed( $id, $status )` (`:326, 562, 674`), `doughboss_catering_quote_updated( $id, $before, $after, $user )` (`:560`), `doughboss_catering_payment( $id, $leg )` (`:673`) | Map to quote-sent, deposit-paid and balance-paid events for offline conversion uploads. |
| Browser events | `doughboss:marketing-event` CustomEvent (`public/js/doughboss-marketing.js:122`) with `generate_lead` mapped to Meta `Lead` / TikTok `SubmitForm` (`:36`) | The bridge's `generate_lead` is fired for the pre-order form, and the catering form does not call it (grep: only `doughboss.js:1670`). |
| Marketing config | `doughboss_marketing_config` filter (`class-doughboss-assets.php:202`) | Set `initialConsent`, pixel ids and the server-forwarding flag. |
| Orders (webshop) | `doughboss_order_created( $order_id, $data )` (`class-doughboss-order.php:470`) and `doughboss_order_payment_status_changed` (`:1188`) | `$data` has no attribution; use the same cookie or request-stash approach. A Square-webhook-recovered order has no browser request, so attribution must be saved at `/payment-intent` time, keyed to the attempt or snapshot. That key is an HMAC (`rest-controller.php:2924-2937`); a companion cannot compute it without copying internals, so the cleanest core change is a `doughboss_checkout_snapshot_payload` filter (CORE PATCH). |

Is UTM or any click id captured anywhere server side? Not found (grep of every `*.php` and `*.js` for `utm_|gclid|fbclid|ttclid|msclkid|_fbp|_fbc`: matches are the in-memory bridge, `rel="noreferrer"` and unrelated code). The only in-browser read is `doughboss-marketing.js:73-88`.

## 10. Reports and privacy

### 10.1 Reports (`class-doughboss-reports.php`, identical in B and C)

| Method | Line | Returns |
| --- | --- | --- |
| `summary( $from, $to, $location )` | 129 | gross revenue, order count, AOV, paid revenue and orders (excludes only cancelled) |
| `payment_mix` | 169 | orders and revenue by `payment_status` |
| `payment_attempt_statuses` | 207 | counts by attempt status for `purpose = 'order'` |
| `kitchen_timing` | 256 | average minutes from `cooking_started_at` to `ready_at`, only when both exist |
| `catering_pipeline` | 295 | enquiry counts by status |
| `pospal_sync_snapshot` | 333 | outbox state (configured, queued, retrying, terminal, ambiguous) |
| `location_breakdown` | 372 | per shop orders, gross and paid revenue |
| `order_type_mix` | 412 | pickup, delivery, dine_in |
| `top_items( limit <= 50 )` | 446 | name, units, revenue by `order_items.name` |
| `orders_for_export` | 489 | per-order CSV rows (order number, UTC time, type, source, table, status, shop, customer name and email, money, voucher, payment status) via `admin_post_doughboss_export_report` (`admin/class-doughboss-admin.php:40, 3124-3190`; spreadsheet-formula cells are prefixed with an apostrophe) |

What they could feed for demand planning and prep sheets: `order_items` has name, size, modifier JSON, quantity and the order's `created_at`, `location_id`, `promised_ready_from_utc` and `fire_at_utc`, which is enough for item-by-hour and item-by-weekday demand and for a morning prep sheet by shop. Gaps (OBSERVED unless marked):
- No grouping by hour or weekday. All ranges are UTC calendar days (`bounds`, `:65-73`); only `today_bounds()` converts to the Sydney day (`:80-96`). A Sydney trading day spans two UTC dates.
- `top_items` groups by display name, so a renamed item splits history and custom pizzas appear as `Custom Pizza (size)`. Modifier-level demand (crust, extras) is inside JSON and not reported.
- Online orders only: no in-store POSPal or Square POS sales, so any prep model from this table under-counts walk-in demand.
- INFERRED: revenue includes unconfirmed `preorder_request` rows (not cancelled) until rejected.
- `capacity_mode` is `off` by default (`class-doughboss-activator.php:192`), and `DoughBoss_Capacity::demand_units` counts one unit per item (`class-doughboss-capacity.php:142-153`), a conservative placeholder, not a prep time.
- `kitchen_timing` is a measured figure only when staff used the board; it is not an estimate.
Recommended: a companion reporting layer reads `orders`, `order_items` and Square Orders/Payments (read-only) into its own table, with Sydney business-day cutoffs, rather than changing these queries.

### 10.2 Privacy (`class-doughboss-privacy.php`, identical in B and C)

Registers WordPress core exporters and erasers (`:34-90`) for three tables keyed on `customer_email`: orders (export: order number, name, email, phone, address, notes, type, table, total, date), catering enquiries and vouchers. Erasure redacts in place and never deletes rows (orders: name to "Anonymous", email anonymised, phone, address and notes cleared: `:342-372`; vouchers: email anonymised and phone cleared: `:413-436`), keeping order numbers and totals for tax-record retention (`:3-8` comment: about five years). Consent: not handled here.

Not covered (OBSERVED by absence in `class-doughboss-privacy.php`; contents from schema): `loyalty_members` (email), `loyalty_ledger`, `loyalty_tokens`; the user account created for Rewards; `doughboss_marketing_consent` user meta; `checkout_snapshots.payload_json` (name, email, phone, address, notes; 24 hours normally, longer while a Square attempt is unresolved); `pospal_outbox.payload_json` (contact name, phone, address, remark; succeeded rows are deleted after 30 days, terminal and ambiguous rows are not: `class-doughboss-pospal-outbox.php:744-755`); orders' `order_events` actor ids; staff tables (other slice). The repo's own review flags the snapshot retention as needing a policy decision (`docs/REVIEW-20260908.md:127`).

## 11. Public hooks (actions and filters) an extension plugin can use

Defined or fired by the plugin (OBSERVED, `grep -rn 'do_action\|apply_filters'` over `doughboss.php includes admin themes`). Candidate lines; "C only" marks hooks absent from B.

### 11.1 Actions fired

| Hook | Args | Fired at | Use |
| --- | --- | --- | --- |
| `doughboss_order_created` | `$order_id, $data` | `includes/class-doughboss-order.php:470` | Kitchen fan-out, attribution, Square order link. After commit; replays do not fire. Also fires for pre-order requests. |
| `doughboss_order_accepted` | `$order_id, $eta_minutes` | `class-doughboss-order.php:896` | Accept emails; Square fulfilment push. |
| `doughboss_order_status_changed` | `$order_id, $status` | `class-doughboss-order.php:898` | Mercure, SMS, ready email; Square status push. |
| `doughboss_order_payment_status_changed` | `$order_id, $previous, $new` | `class-doughboss-order.php:1188` | Loyalty; refund reconciliation. |
| `doughboss_preorder_request_created` | `$order_id` | `includes/class-doughboss-rest-controller.php:3494` | After-hours lead capture. |
| `doughboss_voucher_claimed` | `$id, $code, $slug, $args` | `includes/class-doughboss-voucher.php:1293` | POSPal grant, SMS, email; consent capture. |
| `doughboss_voucher_redeemed` | `$row, $amount, $channel` | `class-doughboss-voucher.php:777` | POSPal revoke; Square discount reconcile. |
| `doughboss_voucher_reversed` | `$voucher_id, $redemption_id, $actor_id` | `class-doughboss-voucher.php:1484` | Audit. |
| `doughboss_catering_enquiry_created` | `$id, $row` | `includes/class-doughboss-catering.php:248` | Lead scoring, attribution. |
| `doughboss_catering_status_changed` | `$id, $status` | `class-doughboss-catering.php:326, 562, 674` | Funnel events. |
| `doughboss_catering_quote_updated` | `$id, $before, $after, $user_id` | `class-doughboss-catering.php:560` | Quote audit. |
| `doughboss_catering_payment` | `$id, $leg` | `class-doughboss-catering.php:673` | Deposit or balance conversion. |
| Cron `doughboss_pospal_outbox_dispatch`, `doughboss_pospal_outbox_reconcile` | none | constants `class-doughboss-pospal-outbox.php:65, 72` | Not for extension; useful to copy for a Square outbox. |
| Admin-post: `doughboss_loyalty_request_link`, `_magic_link`, `_redeem`, `_adjust`, `doughboss_save_loyalty_settings`, `doughboss_create_loyalty_page`, `doughboss_export_report`, `doughboss_pospal_outbox_resend` | | `class-doughboss-loyalty.php:41-49`; `admin/class-doughboss-admin.php:40, 43` | Reuse as UI patterns. |

### 11.2 Filters

| Filter | Value and args | Line | Use |
| --- | --- | --- | --- |
| `doughboss_marketing_config` | array `enabled, metaPixelId, tiktokPixelId, consentVersion, adpilotServerReady` | `class-doughboss-assets.php:202` | Marketing bridge configuration. |
| `doughboss_is_order_page` (C only) | bool | `class-doughboss-assets.php:62` | Force storefront assets. |
| `doughboss_load_assets` | bool | `class-doughboss-assets.php:107` | Force storefront assets. |
| `doughboss_load_manoush_hero_assets` | bool | `:135` | Asset gate. |
| `doughboss_load_shop_status_assets` (C only) | bool | `:153` | Asset gate. |
| `doughboss_load_catering_assets` | bool | `:364` | Asset gate. |
| `doughboss_load_voucher_assets` | bool | `:382` | Asset gate. |
| `doughboss_app_origin` | string | `class-doughboss-settings.php:340` | CORS origin for the staff console. |
| `doughboss_orders_email` | string | `class-doughboss-settings.php:405` | Order notification inbox. |
| `doughboss_tracking_page_url` | `$url, $order_number` | `class-doughboss-settings.php:444` | Tracking link. |
| `doughboss_google_review_url` | string | `class-doughboss-settings.php:454` | Review link. |
| `doughboss_pospal_order_body` | `$body, $creds` | `includes/class-doughboss-pospal.php:348` | Alter POSPal order payload. |
| `doughboss_pospal_grant_body` | array | `class-doughboss-pospal.php:442` | Coupon grant body. |
| `doughboss_pospal_revoke_noop` | bool | `class-doughboss-pospal.php:484` | Skip revoke. |
| `doughboss_pospal_revoke_body` | `$body, $customer_uid, $code` | `class-doughboss-pospal.php:503` | Revoke body. |
| `doughboss_pospal_order_store_creds` | `null, $order` | `includes/class-doughboss-pospal-orders.php:77` | Route credentials per order. |
| `doughboss_pospal_order_store_index` | `$index, $order, $creds` | `class-doughboss-pospal-orders.php:98` | Route store index. Cannot veto the push. |
| `doughboss_seo_relevant_page` | `false, $post` | `includes/class-doughboss-seo.php:76`; theme `functions.php:256` | SEO. |
| `doughboss_final_menu_canonical`, `doughboss_final_dequeue_cf7` | theme | `themes/doughboss-final/functions.php:325, 347` | Theme. |

Not found: any hook or filter around payment creation, Square calls, checkout snapshots, kitchen dispatch, board data, attribution or consent. Those are the places a minimal core patch would add filters (sections 6.3, 7.4, 9).

### 11.3 Browser events (ES5 `CustomEvent`s on `document` unless noted)

`doughboss:cart-updated` (`public/js/doughboss.js:280`), `doughboss:shop-changed` (`doughboss.js:333`, `doughboss-catering.js:385`, `doughboss-shop-status.js:160`), `doughboss:marketing-event` (`doughboss-marketing.js:122`), `doughboss:marketing-consent-ready` (`:135`), listener for `doughboss:consent` (`:138`, nothing dispatches it), and on the checkout form `doughboss:checkout-start`, `doughboss:checkout-complete`, `doughboss:checkout-error` (`doughboss-square.js:513, 371, 492`).

## 12. Open questions and risks

For Elie (owner facts the repo itself lists as unconfirmed, `docs/SQUARE-MIGRATION-20260908.md:30-36`):
1. Does Dough Boss already have a Square merchant account, and is it one merchant with several locations or several merchants?
2. Which Square location is the pilot, and which WordPress location does it represent (today native location 1, Revesby)?
3. Does a Square Developer application exist, and is own-account token or scoped OAuth the model? (The plugin has no OAuth flow: `:13`.)
4. Which POS device and kitchen target (printer or KDS) will the pilot use?
5. Who owns the catalog, prices, modifiers, taxes and the cutover decision, so there is one writer?
6. Should custom-pizza lines, catering deposits and delivery be in the pilot, or stay on the current path?
7. Is the default `app_origin` (`https://edagher92-coder.github.io`) still wanted as a CORS-allowed staff console?

Risks to track: build order (the live 2.41.0 has the older Square protocol); refund state accuracy; unresolved `unknown` and `mismatch` attempts have no operator UI (the admin class references payment attempts only for a count, `admin/class-doughboss-admin.php:242`); CI does not run the money-path integration suite; privacy coverage gaps in section 10.2.

## 13. Recommended sequence (smallest safe steps)

1. Resolve the owner facts above; bind ONE sandbox account and location to ONE WordPress location in a side table; add the connectivity check route. No live change.
2. Pure order builder plus unit tests (no network). Add `order_id` pass-through behind a flag, off by default.
3. Sandbox Orders flow behind `square-v2`, with synthetic customer data, covering success, decline, unknown, duplicate events, amount/currency/location mismatch, abandonment, refund. Verify exact items, modifiers and pickup details in Square Order Manager.
4. Webhook extensions and recovery by snapshot, with the "ticket already issued" flag.
5. `kitchen_owner` and the three veto filters; board read-only mode; per-shop cutover; drain POSPal outbox first.
6. Reporting and loyalty bridges, privacy lifecycle for snapshots and outbox, then multi-shop.
Each step is a separate approval; nothing here authorises live payment activation, a POSPal cutover or a real transaction.

## 14. What I ran, and what I did not

- Read-only: `diff -rq`, file reads, `grep` over both worktrees.
- `php -l includes/class-doughboss-square.php` (PHP 8.3.6): no syntax errors.
- In a scratch copy (not inside either worktree) of the candidate: `php tests/run.php` gave 412 assertions, 412 passed, 0 failed (per file: images 205, menu-options 18, pospal-routing 9, square 94, store-hours 44, stripe 42). In a scratch copy of the baseline: 94 assertions, 94 passed (Square only; the baseline has only `test-square.php`).
- NOT run: the WordPress/MySQL integration suite (needs a disposable WordPress + MySQL site and WP-CLI), PHP 7.4, any browser test, any network call. No Square, Stripe, Tyro, POSPal, or doughboss.com.au request was made.
- Not verified: live site state (taken from `ops/README.md:99-109` and `docs/REVIEW-20260908.md:23-39`), every Square API name in section 6, Square rounding of inclusive GST, whether Square surfaces unpaid orders, and any Square webhook payload shape beyond what the plugin already parses.
