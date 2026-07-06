# DoughBoss V2 — Access Restore Report (2026-07-06)

Record of what was found when "restore all access to DoughBoss V2 development and its
connection to WordPress" was investigated, and what was restored. Kept alongside the
[ChatGPT Resume Map](./DoughBoss-ChatGPT-Resume-Map.md) — read that for the file-by-file map.

## What had been lost (root cause)

The session's working branch had been cut from the **v2.0.0 stub** (`claude/awesome-johnson-bkjh83`,
20 files) instead of the real platform branch. So the checkout — and the branch named for the
restore — held none of the DoughBoss V2 development.

The actual development was never lost on GitHub; it was just not on the branch in hand:

| Branch | Contents | State |
|---|---|---|
| `main` | v2.0.0 stub + Claude config only | 128 commits **behind** the platform |
| `claude/awesome-johnson-bkjh83` | v2.0.0 stub (PR #1) | base of the platform |
| **`claude/funny-goodall-gsoog4`** | **Full Phase 2 platform, plugin 2.15.0, 145 files, every WordPress connector (PR #2, draft)** | the real development |
| `chatgpt/site-token-resume-20260706` | platform + resume map (PR #10, draft) | continuation |

## What was restored

`claude/doughboss-v2-access-restore-f9idab` was reset to the full platform
(`funny-goodall-gsoog4`, plugin **2.15.0**, DB **1.8.0**) plus the ChatGPT resume map.
Verified before push:

- `scripts/dev-check.sh --strict` → **PASS** (PHP 32/32, JS 10/10)
- `build-zip.sh` → installable `dist/doughboss.zip` builds cleanly

Everything below is now present in-tree again: storefront + pizza builder, cart/checkout,
orders/tracking, KDS order board, multi-location routing, Stripe, vouchers + coupon codes +
staff scan console (`app/`), POSPal modules, catering workflow, Mercure/ntfy/SMS/printer
connectors, demo/marketing site (`demo/`), and all docs.

## Connection to WordPress — code vs. credentials

The plugin **is** the WordPress connection: it installs into `doughboss.com.au` via
**Plugins → Add New → Upload Plugin** (`dist/doughboss.zip`). That code path is fully restored.
The *live* connections are deliberately env-first and never committed — restoring them is a
credential step only the owner can complete. Each stays dormant until its `*_ready()` gate is set.

| Integration | Code | To reconnect the live service (owner-gated) |
|---|---|---|
| WordPress plugin | ✅ restored | Upload `dist/doughboss.zip`, activate, configure under **DoughBoss → Settings** |
| Staff console (`app/`) | ✅ restored | WordPress **Application Password** for a staff user; set CORS origin |
| Stripe | ✅ restored | `DOUGHBOSS_STRIPE_*` secret key + webhook secret (env-first), then enable |
| POSPal (Revesby pilot) | ✅ restored | `DOUGHBOSS_POSPAL_APPKEY[_2/_3]` + map the $5/$10 coupon-rule UID per store |
| Mercure SSE | ✅ restored | Hub URL + `DOUGHBOSS_MERCURE_PUBLISH_JWT` (server-side only) |
| ntfy push | ✅ restored | Topic + `DOUGHBOSS_NTFY_TOKEN` |
| ClickSend SMS | ✅ restored | `DOUGHBOSS_CLICKSEND_API_KEY` |
| Printer pull (CloudPRNT/Epson) | ✅ restored | Device + `DOUGHBOSS_PRINTER_TOKEN` |
| **WooCommerce coupons** (in `edagher92-coder/DOUGHXSNOW`) | ✅ intact on `main` | `WC_STORE_URL` + `WC_CONSUMER_KEY/SECRET` (WooCommerce → REST API), then `npm run sync:woo` |

The WooCommerce side lives in the sibling repo **DOUGHXSNOW** — that development was **not** lost
(its branch equals `main`; `src/sync-woocommerce.ts` and `docs/WORDPRESS.md` are present).

## Not done (would change scope — needs an owner decision)

- **Promoting to `main`.** `main` is still 128 commits behind. PR #2 is an intentional draft/staging
  branch; per `RELEASE_CHECKLIST.md` the platform should be split into release slices (issue #8)
  before it lands on `main`. No PR was opened and nothing was merged.
- Setting any live credential above (owner-only, by design).
