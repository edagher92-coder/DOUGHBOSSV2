# CLAUDE.md — DoughBoss V2 Agent Context (main baseline)

First-stop memory for agents working on `main`. Keep it current in the same PR as any change
to version, schema, architecture, or release status.

## Current state

- **Repository:** `edagher92-coder/DOUGHBOSSV2`
- **This branch's scope:** slice 1 — **core plugin + CI + docs + packaging**. This is the first
  release slice landing the platform on `main` incrementally (see "Release slices" below).
- **Plugin version:** `2.0.0` (`DOUGHBOSS_VERSION`)
- **DB schema version:** `1.0.0` (`DOUGHBOSS_DB_VERSION`)
- **Requires:** WordPress 6.0+, PHP 7.4+ · **REST namespace:** `doughboss/v1` · **Text domain:** `doughboss` · **License:** GPL-2.0-or-later

DoughBoss is a commission-free restaurant ordering platform delivered as a WordPress plugin.
The **full Phase 2 platform (2.15.0)** — Stripe, vouchers, POSPal, catering, KDS, notifications,
demo site — lives on `claude/funny-goodall-gsoog4` (PR #2) and is restored on
`claude/doughboss-v2-access-restore-f9idab` (PR #11). It lands on `main` slice by slice, not as
one merge. Do not assume a feature exists on `main` just because it exists on the platform branch.

## Core plugin (this slice)

- Storefront menu (`doughboss_item` CPT + category taxonomy, price/type meta).
- Custom pizza builder with server-trusted pricing.
- Cookie/transient guest cart; pickup or delivery with configurable tax + delivery fee.
- Orders in custom tables; customer order tracking by number + email.
- wp-admin Orders screen + Settings page; order confirmation emails.
- Shortcodes: `[doughboss_menu]` `[doughboss_builder]` `[doughboss_cart]` `[doughboss_order_tracking]`.

### Key files

- `doughboss.php` — header, constants, activation/deactivation, boot.
- `includes/class-doughboss.php` — core loader/singleton.
- `includes/class-doughboss-activator.php` — tables, defaults, capabilities.
- `includes/class-doughboss-settings.php` — typed wrapper over `doughboss_settings`; use instead of raw `get_option()`.
- `includes/class-doughboss-post-types.php` — menu CPT + taxonomy + meta.
- `includes/class-doughboss-cart.php` — guest cart, server-side totals.
- `includes/class-doughboss-order.php` — order persistence, status, tracking.
- `includes/class-doughboss-rest-controller.php` — REST routes. Extract new domains into smaller controllers rather than growing this.
- `admin/class-doughboss-admin.php` — wp-admin screens.
- `public/css/`, `public/js/doughboss.js` — storefront assets.
- `scripts/dev-check.sh` — verifier (`--strict` in CI). `.github/workflows/plugin-ci.yml` — CI. `build-zip.sh` — installable zip. `uninstall.php` — full data removal.

## Release slices (toward `main`, per RELEASE_CHECKLIST.md / #8)

1. **Core plugin + CI + docs + packaging** ← this slice
2. Stripe checkout + webhooks
3. Vouchers + staff scan console (`app/`)
4. POSPal integration
5. Catering workflow
6. Notifications / printer / SMS / Mercure real-time
7. Demo / static marketing site + Snow Boss content

## Security invariants

- Never commit API keys, tokens, passwords, webhook secrets, or customer PII. Prefer env vars.
  Admin secret fields are write-only (blank = keep current; never echo stored values into HTML).
- All money totals, discounts, taxes, delivery fees are recomputed **server-side**; client totals are display hints.
- State-changing REST routes need `X-WP-Nonce` or capability checks; admin routes need capability checks.
  Public reads are intentional only for public storefront data or independently-gated routes (e.g. order tracking by number + matching email).
- Sanitize input, escape output. Use `$wpdb->prepare()` for variable SQL; interpolate only plugin-owned table names from `$wpdb->prefix`.

## Money-path requirements

Changes to checkout, cart totals, or order amounts require code review, security review, strict
CI pass, a staging smoke test, and test cases (or a written reason none are possible). High-value
targets: `DoughBoss_Cart::totals()` across pickup/delivery/GST branches; custom pizza price
recomputation from settings.

## Verify before pushing

```bash
bash scripts/dev-check.sh --strict   # PHP + JS syntax
bash build-zip.sh                    # installable dist/doughboss.zip
```
