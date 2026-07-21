# CLAUDE.md — DoughBoss V2 Agent Context

This file is the first-stop memory for Claude/ChatGPT/Codex-style agents working on DoughBoss. Keep it current in the same PR as any feature that changes version, schema, architecture, release status, integrations, or deployment assumptions.

## Current repo state

- **Repository:** `edagher92-coder/DOUGHBOSSV2`
- **Primary integration branch:** `claude/doughboss-website-design-fixes-li6dqa` (PR #17; staging/integration, not a production release by default).
- **Consolidation branch:** `codex/doughboss-p0-integrity`, based on the current integration tip and incorporating the lifecycle/capacity work from draft PRs #22/#23 for combined verification.
- **Lifecycle release:** plugin `2.18.0` / DB `1.11.0`. Keep it in staging until the sanitised-backup migration/restore rehearsal is recorded.
- **Payments:** Stripe and Tyro remain disabled until their respective test credentials, webhook recovery, reconciliation, and provider certification checks pass.
- **Requires:** WordPress 6.0+, PHP 7.4+
- **REST namespace:** `doughboss/v1`
- **Text domain:** `doughboss`
- **License:** GPL-2.0-or-later

The repository now contains both the WordPress plugin and supporting demo/docs/app assets. Do not assume a feature is deployed to production just because code exists in this branch. Confirm deployment separately.

## Product summary

DoughBoss is a commission-free restaurant ordering platform delivered as a WordPress plugin. It started as pizza/food ordering and now includes, on the current platform branch, a broader operating platform:

- Storefront menu and custom pizza builder.
- Guest cart and checkout with server-side pricing.
- Pickup/delivery order capture and customer order tracking.
- Admin orders/settings.
- Multi-shop locations and order routing.
- Live kitchen order board / KDS.
- Payment abstraction (`DoughBoss_Payment`) with Stripe and Tyro (MPGS hosted session) gateways, both off by default until configured; Tyro is the pilot launch gateway (pickup-only Revesby pilot) but remains dormant until sandbox-verified.
- Stage emails module (`DoughBoss_Emails`) and customer order tracker.
- Management oversight: Today strip, per-shop reports, paid-vs-gross.
- Voucher/coupon system with staff scan tools.
- POSPal integration work for POS mirroring.
- Catering packages/enquiries/quote/deposit workflow.
- Optional notifications/real-time/printer/SMS integrations, dormant until configured.
- Static demo/marketing site and standalone staff console assets.

## Release discipline

Current consolidated release contract: plugin `2.20.0`, database schema `1.13.0`.

The Phase 2 branch is intentionally broad. Stabilize it by splitting future work into small release PRs:

1. Core plugin stabilization, CI, docs, packaging.
2. Stripe checkout and webhooks.
3. Vouchers and staff scan console.
4. POSPal integration.
5. Catering workflow.
6. Notifications, printer, SMS, Mercure/real-time.
7. Demo/static marketing site and Offers & News content (Snow Boss brand retired in S1).

Every release should use `RELEASE_CHECKLIST.md`.

## Key files and directories

- `doughboss.php` — plugin header, constants, activation/deactivation hooks, boot entrypoint.
- `includes/class-doughboss.php` — core loader/singleton; loads dependencies, migrations, and runtime components.
- `includes/class-doughboss-activator.php` — DB creation/defaults/capabilities/roles.
- `includes/class-doughboss-migrations.php` — versioned schema/data upgrade runner.
- `includes/class-doughboss-settings.php` — typed wrapper around the `doughboss_settings` option; use this instead of raw `get_option()` for settings.
- `includes/class-doughboss-post-types.php` — menu item CPT, category taxonomy, price/type/availability metadata.
- `includes/class-doughboss-cart.php` — guest cart token, transient storage, line caps, server-side totals.
- `includes/class-doughboss-order.php` — order persistence, order status, public/customer view, admin queries.
- `includes/class-doughboss-rest-controller.php` — current large route/controller surface. Prefer extracting new domains into smaller controllers rather than growing this file.
- `includes/class-doughboss-stripe.php` — Stripe client/payment helpers.
- `includes/class-doughboss-voucher.php` and `includes/class-doughboss-coupon-code.php` — voucher engine and typo-resistant codes.
- `includes/class-doughboss-pospal*.php` — POSPal integration modules.
- `includes/class-doughboss-catering*.php` — catering package/enquiry/quote/deposit workflow.
- `includes/class-doughboss-mercure.php`, `class-doughboss-ntfy.php`, `class-doughboss-sms.php`, `class-doughboss-printer.php` — optional external integration modules.
- `admin/class-doughboss-admin.php` — wp-admin screens/settings/order board/admin JS hooks.
- `public/js/` and `public/css/` — storefront, voucher, order board, and catering front-end assets.
- `app/` — standalone staff console assets.
- `demo/` — static demo/marketing site, including the Offers & News view (ex Snow Boss; brand retired in S1, `#view-snow` id kept for deep-links).
- `docs/` — owner/dev/product documentation, reports, proposals, PDFs, and assets.
- `scripts/dev-check.sh` — session-safe verifier; use `--strict` in CI.
- `.github/workflows/plugin-ci.yml` — plugin verification/build/secret-pattern workflow.
- `.github/workflows/pages.yml` — static demo Pages deploy workflow.
- `build-zip.sh` — creates an installable `dist/doughboss.zip`.
- `uninstall.php` — full plugin data removal.

## Data model notes

Known custom data areas include:

- Orders and order items.
- Shop/location routing.
- Catering enquiries/packages/quotes/deposits.
- Vouchers and voucher redemptions/audit trail.
- Settings stored in `doughboss_settings`.
- Cart/idempotency/rate-limit transient or option data.
- Menu items as WordPress CPT `doughboss_item` with category taxonomy and meta.

Before editing schema, inspect the current activator and migration files directly. `dbDelta()` is additive; schema changes usually require `create_tables()` changes plus an ordered migration step plus a DB version bump.

## Security invariants

- Never commit API keys, tokens, passwords, webhook secrets, private customer data, or live credentials.
- Prefer environment variables for secrets. Admin secret fields must be write-only: blank means keep current, never echo stored values back into HTML.
- All money totals, discounts, taxes, delivery fees, voucher effects, and payment amounts are recomputed server-side.
- Browser/client totals are display hints only.
- State-changing REST routes need `X-WP-Nonce` or capability checks.
- Admin routes need capability checks.
- Public read routes are intentional only when they expose public storefront data or are independently gated, such as order tracking by number + matching email.
- Sanitize all input and escape all output.
- Use `$wpdb->prepare()` for variable SQL. Interpolate only plugin-owned table names derived from `$wpdb->prefix`, with narrow comments where WPCS suppression is needed.
- Avoid logging secrets or PII. Logs may include high-level status codes and safe diagnostic labels.
- Integrations should be dormant until their `*_ready()` gate is true.

## Money-path requirements

Changes touching checkout, Stripe, vouchers, POSPal, invoices, refunds, deposits, or order totals require:

- Code review.
- Security review.
- Strict verifier/CI pass.
- Manual staging smoke test.
- Test cases or a written reason why automated coverage is not possible in that PR.

High-value test targets:

- `DoughBoss_Cart::totals()` across pickup/delivery/GST branches.
- Custom pizza price recomputation from settings.
- Voucher preview vs redeem state transitions.
- Atomic single-use voucher redeem and checkout-failure revert.
- Stripe PaymentIntent amount/currency/status verification.
- PaymentIntent replay protection.
- Coupon check-character validation and normalization.

## Verification

Session-safe check:

```bash
bash scripts/dev-check.sh
```

CI/strict check:

```bash
bash scripts/dev-check.sh --strict
```

Build installable plugin zip:

```bash
bash build-zip.sh
```

The strict verifier should fail on PHP syntax errors, JS syntax errors when Node is available, missing PHP in CI, or configured-but-missing phpcs in strict mode. Normal session mode stays non-blocking.

## Agent roles

Use this board for complex work:

- **Product agent:** scope, release slice, customer value, rollout order.
- **Engineering agent:** code structure, dependencies, performance, maintainability.
- **Security agent:** secrets, auth, capabilities, REST, SQL, PII, payment/voucher abuse.
- **QA agent:** tests, smoke scenarios, regression risk, staging checklist.
- **Docs/release agent:** `CLAUDE.md`, `README`, `readme.txt`, manuals, changelog, release notes.
- **UX/front-end agent:** checkout conversion, accessibility, KDS usability, mobile performance.

When agents disagree, choose the smallest safe release that preserves the ability to roll back.

## Current priorities

1. Build `dist/doughboss.zip` and execute `docs/UPLIFT-2-Staging-Verification-Runbook.md` on a staging WordPress.
2. Sandbox-verify Tyro before enabling any live payment path; Tyro stays dormant until then.
3. Execute the four UPLIFT specs in `docs/` (UPLIFT-1 storefront design port, UPLIFT-2 staging verification, UPLIFT-3 content completion, UPLIFT-4 performance/SEO) as the forward plan.
4. Pickup-only Revesby pilot is the launch scope; delivery and other shops come later.
5. Keep the live demo (https://edagher92-coder.github.io/DOUGHBOSSV2/) as the owner's validation surface; keep brand facts intact (Lebanese Bakery, since 2009, hello@ email, 3 shops, $4 bases, per-item lemon & chilli, no Mixed zaatar).

## Known gotchas

- The current platform branch is ahead of the default/base branch and includes many off-by-default integrations. Inspect the target branch before making assumptions.
- `includes/class-doughboss-rest-controller.php` is large. Prefer extracting new REST domains instead of adding more methods to it.
- Demo assets and docs are useful, but plugin release safety comes first.
- Static demo deploy workflow is separate from plugin CI. Plugin CI now covers PHP/JavaScript verification, an installable zip, secret scanning and MariaDB lifecycle rehearsal.
- Live-site deployment is not proven by repository state alone. Require explicit approval, backup, and smoke test before production.
- PR #22's MariaDB jobs are synthetic evidence; they do not replace `docs/DoughBoss-Phase-2-Staging-Rehearsal-Runbook.md` on a recent sanitised production copy.
- Keep this file current. A stale `CLAUDE.md` creates cascading agent errors.
