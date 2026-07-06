# DoughBoss — Secrets & Config Map

Every external API DoughBoss can talk to, the key it needs, and **where to put that
key**. This is the "import all keys" reference — but keys are **configured, never
committed**. Each secret is read **env-first** by `includes/class-doughboss-settings.php`
(`env_first_secret()`): an environment variable or `wp-config.php` constant wins over
the stored admin option, and the admin field is write-only (blank = keep current, the
stored value is never echoed back into HTML).

> **Guardrail:** no live key belongs in git. `.env` is git-ignored; `.env.example` is the
> committed template. CI (`plugin-ci.yml`) runs a static secret scan that fails the build
> if a Stripe/AWS/Google/GitHub-shaped secret is ever committed. Leave a slot blank and
> that integration stays **dormant** — the plugin ships safe with nothing configured.

## Where to set a key (three equivalent places, pick one)

1. **Host environment / `.env`** — export the variable, or copy `.env.example` → `.env`.
2. **`wp-config.php` constant** — e.g. `define( 'DOUGHBOSS_STRIPE_TEST_SK', 'sk_test_…' );`
3. **wp-admin** — DoughBoss → Settings → the relevant section (write-only field). Use this
   only if you can't set env/constants; env/constant always wins.

## The map

| Slice | API | Secret / key | Env var (or `wp-config.php` constant) | Also needs |
|---|---|---|---|---|
| 2 | **Stripe** (test) | Secret key | `DOUGHBOSS_STRIPE_TEST_SK` | Webhook secret `DOUGHBOSS_STRIPE_TEST_WHSEC` |
| 2 | **Stripe** (live) | Secret key | `DOUGHBOSS_STRIPE_LIVE_SK` | Webhook secret `DOUGHBOSS_STRIPE_LIVE_WHSEC`; enable live mode in Settings |
| 4 | **POSPal** store 1 | App key | `DOUGHBOSS_POSPAL_APPKEY` | Host + App ID in Settings; $5/$10 coupon-rule UID mapped |
| 4 | **POSPal** store 2 | App key | `DOUGHBOSS_POSPAL_APPKEY_2` | per-store host/App ID |
| 4 | **POSPal** store 3 | App key | `DOUGHBOSS_POSPAL_APPKEY_3` | per-store host/App ID |
| 6 | **Mercure** SSE | Publish JWT | `DOUGHBOSS_MERCURE_PUBLISH_JWT` | Hub URL in Settings (server-side only; never client) |
| 6 | **ntfy** push | Topic token | `DOUGHBOSS_NTFY_TOKEN` | Topic name in Settings |
| 6 | **ClickSend** SMS | API key | `DOUGHBOSS_CLICKSEND_API_KEY` | AU/AUD account; sender in Settings |
| 6 | **Printer** pull | Device token | `DOUGHBOSS_PRINTER_TOKEN` | CloudPRNT/Epson device pointed at the pull route |
| 3 | **Staff console** (`app/`) | WP Application Password | — (created in WordPress, not an env var) | A staff user + Application Password; set the console's CORS origin |
| — | **WooCommerce** coupons *(sibling repo `DOUGHXSNOW`)* | REST consumer key/secret | `WC_STORE_URL`, `WC_CONSUMER_KEY`, `WC_CONSUMER_SECRET` | WooCommerce → Settings → Advanced → REST API (Read/Write) |

## Verify a key is live (not just present)

Each integration self-reports through its readiness gate; nothing goes live silently:

- **Stripe** — `DoughBoss_Settings` Stripe accessors return non-empty; test a PaymentIntent in test mode first.
- **POSPal** — Settings → POSPal → **Test connection** (`/pospal/test`); connection is authenticated live before grant/revoke is used.
- **Mercure / ntfy / SMS / Printer** — each `*_ready()` flips true only when its token *and* its endpoint/topic are set; until then the module is dormant (proved by `tests/smoke-boot.php`).
- **WooCommerce** — `npm run sync:woo` against a real store creates one single-use coupon per voucher.

## If a key leaks

Rotate it at the provider immediately, then update the env var / constant / Settings field.
Because keys are env-first and write-only, rotating never requires a code change or a commit.
