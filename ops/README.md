# Operations — doughboss.com.au

State and tooling for the **live** site that does not otherwise live in this
repository. WordPress keeps settings, menu items, prices and the POSPal product
map in its database, so a restore from backup would silently lose the work
recorded here. This directory is the written record.

Captured 2026-09-07, the day online ordering went live.

---

## `hotfixes/` — patches applied to the live site as WPCode snippets

Each file is the exact code of a snippet currently active on the live site
(**WPCode → Code Snippets**). They exist because the server's file editor is
disabled and the host caps uploads at 2 MB, so a full plugin re-upload was not
possible. Every one is reversible by deactivating the snippet.

| File | Snippet | Fixes | Retire when |
| --- | --- | --- | --- |
| `533-security-headers.php` | #533 | No HSTS / nosniff / X-Frame-Options / Referrer-Policy on any response | Headers move to the host or a security plugin |
| `534-vouchers-admin-fatal.php` | #534 | `DoughBoss → Vouchers` fatals; see below | The fixed plugin ships |
| `535-hidden-attribute.css` | #535 | Author CSS beat `[hidden]`, so the voucher form showed before an offer was chosen and the catering address box showed for pickup | The fixed plugin ships |
| `537-mobile-nav-drawer.css` | #537 | **Mobile navigation was unusable** — see below | The fixed theme ships |

The permanent fixes for #534, #535 and #537 are already committed in this
branch. **Deactivate the matching snippet when the fixed plugin/theme is
deployed**, or the same fix will be applied twice (harmless, but confusing).

### #534 — the Vouchers admin fatal

`admin/class-doughboss-admin.php:2345` called `get_users()` with an array
`fields` argument, which returns raw `stdClass` rows rather than `WP_User`
objects, then passed each row to `user_can()` — which calls `->has_cap()`
straight on the object it is given. Result: `Call to undefined method
stdClass::has_cap()` on every load.

This was not cosmetic. That screen is the **only** place the voucher
reconciliation owner can be set, and since 2.39.0 in-store voucher scanning
fails closed without one, so the till could not redeem vouchers at all.

### #537 — the mobile navigation drawer

`.dbf-header` carried `backdrop-filter: blur(14px)`. A backdrop-filter makes an
element a containing block for its `position: fixed` descendants, so the
off-canvas `.dbf-nav` (`top:0; bottom:0`) collapsed to the ~65px header box.
Measured before the fix: drawer 66px tall, **0 of 8 nav links tappable**. After:
844px, 8 of 8. On a business that is mostly mobile, this was the most expensive
defect on the site.

---

## `state/` — live configuration worth version-controlling

- **`pospal-product-map.json`** — the website-item → POSPal product-uid map, as
  saved on the live site. Keyed on the **lowercased, whitespace-collapsed
  website item name**; lookup is exact, with no fuzzy matching.

  Restore it with `POST /wp-json/doughboss/v1/pospal/product-map` as
  `{"map": { … }}`, or rebuild it in **DoughBoss → Settings → POSPal**.

  > **Coverage is safety-critical.** If *any* item in an order is unmapped, the
  > plugin abandons the **entire** order push — that order never reaches the
  > till, with no error shown to staff or customer. Never leave an item
  > unmapped; re-check coverage after adding a menu item.

- **`pospal-mapping-analysis.md`** — how each mapping was decided, the
  rejected candidates, and the ambiguous cases the owner resolved.

---

## `scripts/` — one-off operational tooling

Written for jobs the admin UI makes tedious. Both authenticate as an existing
logged-in WordPress session rather than holding any credential of their own.

Set `DB_OPS_DIR` to a directory containing:

- `cookies.txt` — a curl cookie jar from a logged-in admin session
  (note: curl prefixes HttpOnly cookies with `#HttpOnly_`, which the scripts
  handle; a naive parser drops exactly the WordPress auth cookies)
- `restnonce.txt` — a current `wp_rest` nonce

Nonces expire in 12–24 hours; refresh from any admin page if a call returns
`rest_cookie_invalid_nonce`.

| Script | Does |
| --- | --- |
| `split_drinks.py` | Replaces a generic drink item with per-flavour items, each priced from the till and mapped to its own POSPal product |
| `align_prices.py` | Reports every website price that differs from its mapped till price; `--apply` writes the till price to the website |

Run `align_prices.py` with no arguments first — it is a dry run and prints the
full delta table. Both scripts write the price through the classic-editor form,
because `doughboss_item` does not declare `custom-fields` support and so the
REST API will not write its meta.

---

## Live configuration as at 2026-09-07

| Setting | Value |
| --- | --- |
| Ordering | **open** — pickup only, single location (Revesby) |
| Payments | **off** — pay at the shop; Stripe present but in test mode |
| GST | 10%, **tax-inclusive** (the displayed price is what the customer pays) |
| POSPal mirroring | **on**, 43 of 43 menu items mapped |
| Voucher reconciliation owner | set (required, or `/voucher/scan` returns 503) |
| Catering | 4 packages published |
| Delivery | disabled |

Website prices were reconciled to the till on 2026-09-07 — six items differed,
in both directions, meaning online and walk-in customers had been paying
different amounts. Re-run `align_prices.py` after any till repricing.

---

## ⚠️ Do not delete the plugin `doughboss-1`

It appears on the Plugins screen as plain **"DoughBoss"**, indistinguishable
from the real one, but it is a stale 2.25.4 copy whose `uninstall.php` **drops
the live tables** using the same `$wpdb->prefix`: orders, order items, order
events, vouchers, voucher redemptions, catering enquiries, locations, the
POSPal outbox and the capacity tables — then deletes every menu item and
catering package post and the `doughboss_settings` option with all API keys.

Deleting it through wp-admin runs that uninstaller and destroys the shop's
data. **Remove the folder over SFTP instead**, so WordPress never executes it.

Fifteen other leftover helper plugins were removed safely on 2026-09-07 (none
carried an `uninstall.php`). Four report as deleted but persist, a WordPress
quirk with `folder/folder/file.php` layouts; they are inert.
