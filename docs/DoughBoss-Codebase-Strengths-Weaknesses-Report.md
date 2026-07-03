# DoughBoss Codebase — Strengths, Weaknesses & Improvement Report

**Scope:** full plugin codebase (`includes/`, `admin/`, `demo/`, `app/`, `public/`) at `/home/user/DOUGHBOSSV2`, plugin v2.12.2.
**Method:** initial broad recon by Claude (Fable 5), then independently re-verified line-by-line for every finding below marked **CONFIRMED**. Findings not personally re-verified are marked **REPORTED (unverified)** — treat with slightly less certainty, but they came with specific file:line citations and read as credible.
**Date:** 2 July 2026.

---

## Critical — fix before the next real customer touches checkout

### 1. Guest cart silently loses the customer's first add — **CONFIRMED**

`includes/class-doughboss-cart.php:40-56`:

```php
public function get_token() {
    if ( null !== $this->token ) { return $this->token; }
    if ( isset( $_COOKIE[ self::COOKIE ] ) ) {
        $candidate = sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) );   // <-- lowercases
        if ( strlen( $candidate ) >= 16 ) { $this->token = $candidate; return $this->token; }
    }
    $this->token = wp_generate_password( 32, false, false );                   // <-- mixed case
    $this->set_cookie( $this->token );
    return $this->token;
}
```

`wp_generate_password(32, false, false)` draws from `[a-zA-Z0-9]` — with 32 characters, the odds of generating a token with **zero** uppercase letters are astronomically small, so in practice almost every fresh session gets a mixed-case token. That token is used as-is to key the cart transient (`doughboss_cart_{token}`) on the request that creates it. But `sanitize_key()` — which WordPress lowercases and strips to `[a-z0-9_-]` — runs on *every subsequent read* of the cookie. So:

- Request 1 (new visitor, adds an item): cart written under `doughboss_cart_AbC123…`.
- Request 2+ (same visitor, views cart / goes to checkout): cookie read back, lowercased to `doughboss_cart_abc123…` — a **different transient key**. The cart from request 1 is unreachable. The customer sees an empty cart.

This isn't a rare edge case — it's the *first* add-to-cart of essentially every new session, which for most storefronts is exactly when "add" and "view cart" happen as separate HTTP requests.

**Fix:** stop lowercasing a value that's meant to preserve case. Use a case-preserving validator instead of `sanitize_key()`:

```php
if ( isset( $_COOKIE[ self::COOKIE ] ) ) {
    $candidate = preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) );
    if ( strlen( $candidate ) >= 16 ) { $this->token = $candidate; return $this->token; }
}
```

This keeps the sanitization (strip anything not alphanumeric) without destroying the case the token was generated with. Ship this fix, then check whether abandoned-cart transients from the old bug are worth a cleanup query (they'll just expire naturally — `DAY_IN_SECONDS` — so probably not urgent).

### 2. Saving Settings silently wipes fields the form doesn't know about — **CONFIRMED**

`admin/class-doughboss-admin.php:168-268`, `sanitize_settings()`:

```php
public function sanitize_settings( $input ) {
    $input = is_array( $input ) ? $input : array();
    $clean = array();          // <-- starts EMPTY, never merges $existing
    $clean['currency_symbol'] = ...
    // ... ~90 explicit keys ...
    return $clean;
}
```

WordPress's Settings API replaces the *entire* stored option with whatever this function returns. Because `$clean` starts empty and only ever gets the ~90 keys this function explicitly lists, **any option key not in that list is deleted from the database on every single Settings save** — including a save that only touched an unrelated field like "Ordering open".

Confirmed missing from `$clean` (there may be others; these three are directly used elsewhere and matter):

| Key | Used at | Effect when wiped |
|---|---|---|
| `app_origin` | `rest-controller.php:71`, feeds the CORS allow-list for the standalone staff Console app | Console app gets silently CORS-blocked after the next unrelated Settings save |
| `voucher_campaigns` | `class-doughboss-voucher.php:429` — the owner-editable campaign config | Any customization to the $5/$10 campaigns reverts to hardcoded defaults |
| `pospal_label` | `class-doughboss-settings.php:543,588` | Cosmetic only — the Store 1 label in the admin UI blanks out |

**Fix:** merge onto existing settings instead of building from scratch:

```php
public function sanitize_settings( $input ) {
    $input   = is_array( $input ) ? $input : array();
    $clean   = DoughBoss_Settings::all();   // start from what's already stored
    // ... existing per-field assignments unchanged, they overwrite as before ...
    return $clean;
}
```

This is a one-line change (`$clean = array();` → `$clean = DoughBoss_Settings::all();`) that makes every currently-listed field still explicit and controlled, while no longer nuking anything the form doesn't ask about. Low-risk, high-value fix.

---

## High priority

### 3. Rate limiter is bypassable/collision-prone behind any proxy — **CONFIRMED**

`includes/class-doughboss-rest-controller.php:889-897`:

```php
private function rate_limited( $bucket, $max, $window ) {
    $ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
    $key  = 'doughboss_rl_' . $bucket . '_' . md5( $ip );
    $hits = (int) get_transient( $key );
    if ( $hits >= $max ) { return true; }
    set_transient( $key, $hits + 1, $window );
    return false;
}
```

Two separate issues: (a) `REMOTE_ADDR` is the direct TCP peer — behind Cloudflare or any reverse proxy, every visitor shares the proxy's IP, so the checkout rate limit (8 requests/10 min per the code comment) could throttle *all* customers during a genuine dinner rush; conversely, without a proxy, IP-based limiting is trivially defeated by rotating IPs. (b) the get-then-set is not atomic — two concurrent requests can both read `$hits` before either writes, under-counting the true rate.

**Fix:** honour a configurable trusted-proxy header (`X-Forwarded-For`, first hop, only if a proxy is explicitly configured — never trust it blindly from the public internet) for the IP, and switch to an atomic increment (`wpdb` `INSERT ... ON DUPLICATE KEY UPDATE count = count + 1`, or `wp_cache_incr` if an object cache is available) instead of transient get/set.

### 4. Stripe secret keys are echoed back into admin HTML — **CONFIRMED**

`admin/class-doughboss-admin.php:1426-1447` — the Stripe test/live secret key and webhook secret fields render `value="<?php echo esc_attr( $settings['stripe_test_sk'] ); ?>"` etc., i.e. the *actual secret value* is written into the page source every time the Settings screen loads. Compare with a `keep_secret()` write-only helper (`admin/class-doughboss-admin.php:281`) that's already used elsewhere for `mercure_publish_jwt`, `ntfy_token`, `clicksend_api_key`, and `printer_token` — a "leave blank to keep current" pattern that never echoes the stored value back. **Correction to the initial pass:** POSPal's app key does *not* use this pattern either (`pospal_app_key` is plain `sanitize_text_field`, same gap as Stripe) — so this is two secret fields needing the retrofit, not one.

**Fix:** apply the existing `keep_secret()` pattern to the four Stripe secret fields and the POSPal app-key fields (all stores). This is a genuine inconsistency, not a design decision — the newer code already knows the right pattern; it just wasn't applied to the two oldest integrations.

### 5. `CLAUDE.md` is stale enough to actively mislead — **CONFIRMED** (verified independently earlier in this session too)

- States plugin v2.5.0 / DB v1.4.0 — actual is v2.12.2 / v1.7.0.
- States "3 custom tables" — actual is 6 (`orders`, `order_items`, `locations`, `catering_enquiries`, `vouchers`, `voucher_redemptions`).
- Twice states catering "has **zero code**" (Gotchas section) — actual: `class-doughboss-catering.php` (502 lines) + `class-doughboss-catering-package.php` (334 lines), a full enquiry→quote→deposit→balance pipeline with a working Stripe webhook.
- Lists Stripe webhook, refunds, push transport, and receipt printing as "not yet implemented" — all four exist and are shipped (off by default, per the project's own dormancy convention, but implemented).
- Omits the entire voucher subsystem, POSPal integration, WP-CLI tooling, and the standalone staff Console from its own architecture summary.

**Fix:** regenerate CLAUDE.md from the current codebase (an `/init`-style pass), and add a habit/process step — update CLAUDE.md in the same commit as any feature that changes the "Current state & roadmap" section, so it can't drift this far again. Given how much of this session's own output has depended on catching CLAUDE.md's staleness by hand, this is worth prioritizing.

---

## Medium priority

### 6. Zero automated tests for a payments/vouchers/POS system — **CONFIRMED**

`scripts/dev-check.sh` is `php -l` (syntax lint) only — it's designed to always exit 0 regardless of logical correctness. There's no `tests/` directory, no PHPUnit, no `composer.json`. The only CI workflow deploys the static demo, not the plugin.

The classes most worth testing are exactly the ones handling money and single-use value: `DoughBoss_Cart::totals()` (dual GST-inclusive/exclusive branches — CLAUDE.md itself warns both must be preserved by hand), `DoughBoss_Voucher::redeem()` (atomic claim + audit + revert-on-failure), `DoughBoss_Stripe::verify_payment()`/webhook signature verification, and `DoughBoss_Coupon_Code`'s check-character math. All of these are static/pure-ish methods that don't need a full WordPress bootstrap to test — a lightweight harness (WP_Mock or Brain\Monkey) could cover the highest-risk 20% of the codebase in a day or two of work. The cart-token bug above (#1) is precisely the class of defect a two-line integration test would have caught before it shipped.

### 7. `includes/class-doughboss-rest-controller.php` is a 2,699-line god-class — **REPORTED**

Mixes route registration, permission callbacks, rate limiting, voucher orchestration, checkout, catering, and POSPal diagnostic endpoints (e.g. `/pospal/probe-grant`, which brute-forces 11 candidate endpoint names against the live POSPal account — useful during integration development, but worth gating hard behind a capability check if it isn't already, and removing once POSPal's real coupon-grant endpoint is fully confirmed). Splitting into per-domain controllers (Checkout, Vouchers, Catering, POSPal-diagnostics, Admin/board) would make this far more maintainable without changing behavior.

### 8. Voucher schema supports multi-use vouchers; the code doesn't — **REPORTED**

`vouchers.single_use` exists as a column and `issue()` accepts it as a parameter, but `redeem()` unconditionally flips `issued → redeemed` regardless of that flag — so a "multi-use" voucher is schema-representable but functionally impossible today. Either wire up multi-use redemption properly, or remove the column/parameter so the code doesn't imply a capability that doesn't exist.

### 9. Printer ticket loss on a paper jam — **REPORTED**

`class-doughboss-printer.php` advances its "already printed" watermark on the printer's *fetch* (at-most-once semantics, by design, to prevent reprint loops on an unreliable DELETE confirmation) — but this means if the physical printer jams or runs out of paper right after fetching a ticket, that order's kitchen ticket is lost with no retry/reprint path. Worth a manual "reprint last N tickets" admin action as a safety valve, even if the core at-most-once design is otherwise reasonable.

### 10. Demo site duplication across 8 pages — **REPORTED**

Each of the 8 demo pages (`index/owner/staff/backend/franchise/licensing/privacy/terms`) carries its own `<style>` block on top of the shared 2,000+-line `demo.css`; nav/footer/theme markup is duplicated per-page rather than templated. Fine for a static demo today, but will drift as pages get individually patched (as has happened repeatedly this session). Not worth a framework migration, but a shared header/footer include pattern (even simple JS-injected) would reduce repeat-edit risk.

---

## Low priority / hygiene

- **`uninstall.php` misses two cleanup targets:** the `doughboss_printer_watermark` option and `doughboss_rl_*` rate-limit transients aren't removed on uninstall. Minor — transients expire on their own, and the watermark option is harmless orphaned data — but worth a one-line addition for completeness.
- **Hard-coded personal defaults in a distributable plugin:** `class-doughboss-settings.php:83` defaults `orders_email` to `orders@doughboss.com.au`, and `:137` defaults `app_origin` to the developer's personal GitHub Pages URL. Harmless for this specific deployment, but if the plugin is ever distributed/forked, these should move to empty defaults with clear first-run prompts.
- **`DoughBoss_POSPal::sign()` is dead code** (`class-doughboss-pospal.php:131`) — `call()` inlines the same signature logic at line 575 instead of calling it. Either wire `sign()` in or delete it.
- **Duplicated PaymentIntent verification logic** between `verify_payment()` and `catering_confirm_payment()` in the REST controller — same checks (status/amount/currency/single-use), copy-pasted rather than shared. Extract to one method both call.
- **~82 `phpcs:ignore` comments**, overwhelmingly for direct/unprepared SQL on the plugin's own custom tables — checked a sample and they're legitimately scoped (not blanket-suppressing real issues), consistent with the project's stated convention of documenting *why* each one is needed.

---

## Strengths worth preserving (not just a bug list)

1. **The voucher engine is genuinely well-engineered.** Atomic conditional-`UPDATE` claim (`issued → redeemed`, can't double-fire even under concurrent requests), a mandatory audit row with revert-on-checkout-failure, idempotent replay via an idempotency key, deliberately opaque error responses (same message for "not found" and "ineligible" — no enumeration), and typo-resistant check-character codes that still fall through gracefully for legacy formats. This is harder to get right than it looks, and it's right.
2. **Checkout correctness plumbing is solid**: a real DB transaction wraps order + order_items creation, order-number collision retry, checkout idempotency keys, Stripe PaymentIntent single-use enforcement, and voucher reversion if the order insert fails partway through.
3. **The dependency-free integration philosophy is executed consistently six times over** (Stripe, POSPal, Mercure, ntfy, SMS, printer) — no Composer, no bundled SDKs, every connector dormant by default behind its own `*_ready()` gate, every secret read env-first. This keeps the attack surface and maintenance burden small in a way a lot of WordPress plugins don't bother with.
4. **Secrets discipline is real, not just documented** — checked every `error_log`/logging call site across the integrations; none echo a token, key, or PII. Mercure, ntfy, ClickSend SMS and the printer already use a proper write-only "keep current" pattern for their secret fields; Stripe and POSPal are the two integrations still missing that retrofit (see #4 above).
5. **Capability granularity is genuinely least-privilege**: `manage_doughboss`, `manage_doughboss_kds`, and `redeem_doughboss_vouchers` are three separate capabilities, so a kitchen-tablet login can redeem a voucher at the till but can never mint one — a real security boundary, not just a role name.
6. **The migration runner is defensive in the right ways**: checkpointed steps, a concurrency lock so two simultaneous requests can't run migrations twice, and `Throwable` containment so a failed migration step can't white-screen the whole site.

---

## Recommended fix order

| # | Fix | Effort | Why this order |
|---|---|---|---|
| 1 | Cart token case-mismatch (§1) | ~5 min | Actively losing real customer carts right now |
| 2 | Settings-save wiping `app_origin`/`voucher_campaigns` (§2) | ~10 min | Silently breaks the staff Console + campaign config on the next unrelated settings save |
| 3 | Stripe + POSPal secret keys echoed to admin HTML (§4) | ~30 min | Real credential-exposure surface, easy retrofit of an existing pattern |
| 4 | Rate limiter proxy-awareness + atomicity (§3) | ~1–2 hrs | Correctness/availability risk, not urgent but not free |
| 5 | Regenerate CLAUDE.md (§5) | ~1 hr | Keeps compounding — every future session (human or AI) inherits wrong assumptions until this is fixed |
| 6 | Minimal test harness for money-path classes (§6) | ~1–2 days | Biggest structural risk, but the least urgent to ship *today* |
| 7 | Everything else | as time allows | Real but lower-stakes |

Items 1–3 are small, mechanical, and low-risk — happy to implement and push them now if you want. Item 5 (CLAUDE.md regeneration) is also quick and would meaningfully improve every future session's starting accuracy.
