# DoughBoss ChatGPT Resume Map — 2026-07-06

This handoff file links the current DoughBoss V2 work so ChatGPT, Claude, Codex, or a human dev can resume the new site and backend token/security system from the correct branch and files.

## Source of truth

- Repository: `edagher92-coder/DOUGHBOSSV2`
- Default branch reported by GitHub connector: `claude/awesome-johnson-bkjh83`
- Active Phase 2 platform branch: `claude/funny-goodall-gsoog4`
- ChatGPT continuation branch: `chatgpt/site-token-resume-20260706`
- Open integration PR: #2 — Vouchers + Staff Console + POSPal · Catering rebuild · Live Order Board
- Resume tracking issue: #9 — Resume: new site + backend token system

## Related PRs

- #2 — broad Phase 2 integration branch. Keep as staging/integration until split.
- #3 — Snow Boss dual-brand demo section and Instagram-gated student voucher. Merged.
- #4 — demo GUI/UX fixes for fulfilment radios and About CTA. Merged.
- #5 — release discipline guardrails, CI, checklist, agent memory. Merged into `claude/funny-goodall-gsoog4`.

## Related issues

- #6 — trusted-proxy-aware rate limiting.
- #7 — minimal money-path tests for checkout, vouchers, Stripe, coupon codes.
- #8 — split Phase 2 PR into smaller domain release slices.
- #9 — ChatGPT resume map for site + backend token system.

## New site / demo files

Primary files:

- `demo/index.html` — hash-routed concept demo: About, Menu, Catering, Locations, Kitchen, Snow Boss.
- `demo/demo.css` — shared visual system plus Snow Boss styles.
- `demo/snowboss.js` — falling snow, follow gate, voucher claim behavior.
- `demo/menu-order.js` — simulated order/menu flow.
- `demo/catering-demo.js` — catering quote builder demo.

Supporting pages:

- `demo/staff.html`
- `demo/backend.html`
- `demo/franchise.html`
- `demo/licensing.html`
- `demo/owner.html`
- `demo/privacy.html`
- `demo/terms.html`

Deployment:

- `.github/workflows/pages.yml` deploys `demo/` to GitHub Pages from `claude/funny-goodall-gsoog4` when demo files change.

## Staff console files

- `app/index.html` — static staff console shell, loads `html5-qrcode` and `app.js`.
- `app/app.js` — WordPress Application Password login, Voucher Scan, Vouchers, Order Board, optional Mercure SSE fallback to polling.
- `app/app.css` — console UI styling.
- `app/manifest.webmanifest`, `app/icon-192.png`, `app/icon-512.png` — installable PWA shell metadata/icons.

## Backend token / secret system files

- `includes/class-doughboss-settings.php` — central settings, env-first secret accessors, readiness gates.
- `includes/class-doughboss-printer.php` — token-gated CloudPRNT/Epson printer-pull routes.
- `includes/class-doughboss-mercure.php` — optional Mercure SSE publishing; publish token must stay server-side.
- `includes/class-doughboss-ntfy.php` — optional staff push; token env-first.
- `includes/class-doughboss-sms.php` — optional ClickSend SMS; API key env-first.
- `includes/class-doughboss-pospal.php` — POSPal Open Platform client/config.
- `includes/class-doughboss-pospal-sync.php` — POSPal voucher mirror/grant/revoke flow.
- `includes/class-doughboss-pospal-orders.php` — POSPal order push/mirror flow.
- `includes/class-doughboss-coupon-code.php` — check-character code generation, normalization, validation.
- `includes/class-doughboss-voucher.php` — voucher engine, issue/redeem/audit logic.
- `includes/class-doughboss-rest-controller.php` — REST surface. Prefer extracting new domains rather than growing this further.

## Security rules

- Never commit live API keys, app keys, printer tokens, SMS keys, webhook secrets, passwords, customer exports, private URLs, or backup codes.
- Keep secrets env-first where possible:
  - `DOUGHBOSS_POSPAL_APPKEY`
  - `DOUGHBOSS_POSPAL_APPKEY_2`
  - `DOUGHBOSS_POSPAL_APPKEY_3`
  - `DOUGHBOSS_MERCURE_PUBLISH_JWT`
  - `DOUGHBOSS_NTFY_TOKEN`
  - `DOUGHBOSS_CLICKSEND_API_KEY`
  - `DOUGHBOSS_PRINTER_TOKEN`
- Admin secret fields must be write-only: blank means keep current; never echo stored values into HTML.
- Public REST routes must have explicit gates. Printer endpoints use token comparison; state-changing routes use nonce/capability checks.
- Money path remains server-authoritative: totals, vouchers, deposits, Stripe amounts and discounts must be recomputed server-side.
- Avoid logging PII or secrets.

## Resume execution order

1. Verify guardrails from #5 remain in branch:
   - `CLAUDE.md`
   - `RELEASE_CHECKLIST.md`
   - `.github/workflows/plugin-ci.yml`
   - `scripts/dev-check.sh --strict`
2. Finish the site/demo slice:
   - polish `demo/index.html`, `demo/demo.css`, `demo/snowboss.js`
   - verify supporting pages match current offer, privacy, terms, Formspree usage, and Snow Boss copy
   - verify Pages workflow deployment behavior
3. Finish backend token/security slice:
   - confirm all readiness gates are off-by-default
   - verify env-first accessors and write-only admin fields
   - verify printer tokens use `hash_equals`
   - verify console Application Password auth and CORS settings are clear in docs
4. Finish voucher/POSPal slice:
   - verify POSPal member-by-phone grant path
   - verify coupon-rule UID mapping for store 1/2/3
   - verify QR/camera scan against the atomic `/voucher/scan` route
   - verify check-character validation and normalization behavior
5. Before any production release:
   - finish #6 trusted-proxy rate limiting
   - finish #7 money-path tests
   - split #2 per #8
   - run `bash scripts/dev-check.sh --strict`
   - build `dist/doughboss.zip` from the reviewed SHA only
   - use `RELEASE_CHECKLIST.md`

## Immediate next branch work

Use `chatgpt/site-token-resume-20260706` for small, reviewable commits. Do not merge into #2 or production until CI and release checklist are clean.
