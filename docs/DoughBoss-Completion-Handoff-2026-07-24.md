# DoughBoss completion handoff — 24 July 2026

## Verified source state

- Repository: `edagher92-coder/DOUGHBOSSV2`
- Branch: `codex/doughboss-post-v2.22.2`
- Draft pull request: #34
- Published baseline: `7aa0298`
- Plugin: `2.23.2`
- Database schema: `1.16.0`

The baseline passed the complete GitHub PHP, WordPress, MariaDB, ZIP and secret
scan matrix. The public GitHub Pages demo was deployed and its home, menu,
catering, sitemap and marketing assets returned HTTP 200.

The 24 July follow-up adds offline provider-readiness, POSPal outbox and
customer-to-KDS acceptance coverage. Use the current PR #34 branch tip and its
green GitHub checks as the release evidence.

## Production WordPress status

Production installation state is currently **unknown**, not failed and not
verified. On 24 July:

- the WPVibe site-information request returned HTTP 504;
- direct HTTPS requests to `doughboss.com.au`, `/wp-json/` and the DoughBoss
  REST endpoints timed out;
- DNS resolved to `203.170.81.129`;
- TCP port 443 did not accept a connection, while port 80 accepted TCP but
  returned no HTTP response within the test window.

Do not overwrite settings, activate ordering, upload a plugin or create a test
order until hosting/HTTPS access is restored and a fresh backup is confirmed.

## Completed code acceptance

- Customer cart and checkout remain server-authoritative.
- After-hours preorder requests remain unpaid and require staff confirmation.
- Durable payment attempts, capacity holds and checkout idempotency are covered.
- Email-bound customer tracking and versioned staff/KDS transitions are covered.
- Stale KDS updates are rejected.
- Tyro remains fail-closed until credentials, shop mapping and certification
  gates are satisfied.
- POSPal remains an asynchronous mirror and cannot block a paid kitchen order.
- Meta/TikTok/AdPilot browser events remain consent-gated and dormant by
  default.
- SEO, responsive catering motion and WordPress shortcode parity are covered.

## Safe production continuation

1. Restore hosting and HTTPS response without changing WordPress settings.
2. Confirm a current full-site and database backup.
3. Read the installed plugin version, menu count, shop rows, ordering flag,
   payment flag and POSPal flag.
4. Install the CI-built plugin ZIP on staging first.
5. Run checkout to customer tracking to KDS acceptance with a no-payment test
   profile.
6. Keep ordering, payments and POSPal off while comparing staging data.
7. Deploy to production only after owner approval and a rollback rehearsal.
8. Verify closed checkout first; enable one Revesby pilot path only after the
   provider acceptance below passes.

## Tyro external acceptance gates

- Sandbox client ID and secret.
- Per-shop Tyro `locationId` and confirmed eCommerce MID mapping.
- Signed webhook secret and staging delivery.
- Mobile and desktop success, decline, 3DS, cancellation, timeout, duplicate
  click, lost response and duplicate/out-of-order webhook scenarios.
- Refund and void operation.
- POSPal-offline paid-order/KDS behaviour.
- Secret/log/database scan.
- Tyro technical review and production certification.
- Environment-first production secrets and owner-set `tyro_live_certified`.
- One supervised Revesby live order before enabling another shop.

## POSPal external acceptance gates

- Exact host, app ID and app key for each store.
- DoughBoss shop to POSPal store mapping.
- Every sale item mapped to a POSPal product UID.
- Confirmed coupon-rule UID and controlled member grant/revoke test.
- Concurrent online/till voucher test with WordPress as the value authority.
- Confirmed `addOnLineOrder` response and stable `orderNo` reconciliation.
- Named staff owner for ambiguous-outcome review.
- POSPal-offline test proving the paid order remains available to KDS and is
  never blindly replayed.

No credentials, customer data or live transaction evidence belongs in this
document or the repository.
