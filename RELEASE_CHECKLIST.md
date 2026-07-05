# DoughBoss Release Checklist

Use this checklist for every plugin or demo release. It is designed for small reviewable PRs, especially while the large Phase 2 Claude branch is being split into release candidates.

## 1. Scope the release

- [ ] Release has a single clear theme, such as `Stripe`, `Vouchers`, `KDS`, `Catering`, `POSPal`, `Notifications`, `Demo`, or `Docs`.
- [ ] PR description lists user-facing changes, admin changes, REST changes, DB/migration changes, and off-by-default integrations.
- [ ] Any feature that touches payments, vouchers, POS, SMS, printers, customer data, or order state has a security-review note.
- [ ] `CLAUDE.md` is updated when version, schema, architecture, feature status, deployment notes, or gotchas change.

## 2. Pre-merge checks

- [ ] `bash scripts/dev-check.sh --strict` passes locally or in CI.
- [ ] `.github/workflows/plugin-ci.yml` passes on the PR.
- [ ] `bash build-zip.sh` creates `dist/doughboss.zip`.
- [ ] The zip contains `doughboss/doughboss.php`, `doughboss/includes/`, `doughboss/admin/`, `doughboss/public/`, `readme.txt`, and `uninstall.php`.
- [ ] No API keys, tokens, passwords, live webhook secrets, private URLs, or customer data are committed.
- [ ] New REST routes have explicit `permission_callback` decisions.
- [ ] State-changing REST routes use nonce or capability checks.
- [ ] Money totals are recomputed server-side.
- [ ] SQL uses `$wpdb->prepare()` or a documented, scoped exception for plugin-owned table names.
- [ ] Admin output is escaped and settings inputs are sanitized.

## 3. Manual smoke test

Run on a staging or draft WordPress environment before production.

- [ ] Plugin activates without fatal errors.
- [ ] DB schema version updates as expected.
- [ ] Settings page loads and saves without wiping unrelated settings.
- [ ] Menu item can be created/edited, including price, type, image, category, and availability.
- [ ] Customer can add a standard menu item to cart.
- [ ] Customer can add a custom pizza to cart.
- [ ] Cart survives page refresh and separate requests.
- [ ] Checkout creates exactly one order.
- [ ] Retried checkout with the same idempotency key does not duplicate the order.
- [ ] Customer order tracking works with matching email and does not reveal whether an order exists when email mismatches.
- [ ] Admin order status update works for authorized users only.
- [ ] Kitchen board/console can see active orders using the correct low-privilege role.

## 4. Payments and vouchers, when touched

- [ ] Stripe test PaymentIntent succeeds for AUD amount matching the server total.
- [ ] Stripe amount/currency mismatch is rejected.
- [ ] Stripe PaymentIntent replay is rejected.
- [ ] Stripe webhook signature verification is tested.
- [ ] Refund path is tested if the release changes refunds.
- [ ] Voucher preview does not mutate redemption state.
- [ ] Voucher redeem is atomic/single-use where expected.
- [ ] Voucher checkout failure reverts the redemption.
- [ ] Voucher code is not leaked in logs beyond intentional admin/customer views.

## 5. External integrations, when touched

- [ ] Integration is dormant until its `*_ready()` gate is true.
- [ ] Secret values are env-first where practical.
- [ ] Admin secret fields use a write-only “leave blank to keep current” pattern.
- [ ] Logs include HTTP status and diagnostic codes, not secret values, customer PII, SMS body, or full tokens.
- [ ] Failure mode is best-effort and does not fatal the checkout/order flow unless the integration is explicitly required.

## 6. Production deploy gate

- [ ] Fresh backup exists and restore path is known.
- [ ] Production deployment has explicit owner approval.
- [ ] Plugin zip was built from the reviewed commit SHA.
- [ ] Maintenance/rollback owner is assigned.
- [ ] After deploy, run a production smoke test using a safe test order path.
- [ ] Confirm logs for fatals, webhook errors, failed REST calls, and unexpected duplicate orders.

## 7. Post-release

- [ ] Tag the release commit.
- [ ] Attach the built zip to the GitHub release if using GitHub releases.
- [ ] Record deployment date, commit SHA, plugin version, DB version, and rollback notes.
- [ ] Create follow-up issues for known non-blockers discovered during release.
