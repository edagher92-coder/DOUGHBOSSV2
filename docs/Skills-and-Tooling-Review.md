# DoughBoss — Skills & Tooling Review

*Board review of the available Claude Code skills and newly-connected MCP integrations,
with the workflow improvements executed in this pass. Prepared June 2026.*

## Executive summary
DoughBoss is **one integration away from revenue**: the Stripe payment path is scaffolded
but not wired, so the single highest-leverage program is to use the **Stripe MCP** to
complete and validate it (test PaymentIntents, webhooks, refunds, current API patterns),
then prove it end-to-end on the live site via **WPVibe** (draft-theme deploy + real menu/
shop data) — all behind **GitHub** PR review, CI, and a mandatory **security-review**,
because this is a live Australian site handling cards and PII. Immediately after go-live,
wire each paid order into **one** accounting system (**Xero or Zoho Books, not both**) to
auto-issue GST-inclusive tax invoices and reconcile Stripe payouts across the three shops.
Treat *verify, code-review, security-review, review, init,* and *fewer-permission-prompts*
as adopt-now guardrails; Canva/Firefly, Gmail/Drive/M365, Zapier and PDF Viewer are
supporting players that must stay off the money-critical path.

## Executed in this pass
- **Project memory** — added `CLAUDE.md` (architecture, data model, conventions, security
  patterns, verify/render commands, git workflow, current state & gotchas). *(skill: `init`)*
- **SessionStart hook** — `.claude/settings.json` runs `scripts/dev-check.sh` on every fresh
  web session, linting all PHP up front; the script is resilient (always exits 0).
  *(skill: `session-start-hook`)*
- **Verifier** — `scripts/dev-check.sh`: `php -l` across the repo + optional phpcs, PASS/FAIL
  summary. Doubles as a `/loop` body during active work. *(skills: `verify`, `loop`)*
- **Read-only allowlist** — pre-approved `php -l`, `git status/diff/log/branch/show`, `ls`,
  and the verifier, to cut repeated permission prompts. *(skill: `fewer-permission-prompts`)*

## Skills evaluation
| Skill | Verdict for DoughBoss | Tag |
|---|---|---|
| init | Generated `CLAUDE.md`; re-run after big structural changes (e.g. once Stripe lands). | **Adopt now** |
| security-review | Mandatory on every Stripe/checkout PR — payments + `$wpdb` + REST-nonce surface, PII, secret keys. | **Adopt now** |
| code-review | Run on each feature diff for correctness + WPCS drift before opening the draft PR. | **Adopt now** |
| review | Standardise PR review on every payment/checkout PR. | **Adopt now** |
| verify | No PHPUnit; "verify" = `dev-check.sh` lint + runtime check on a live WP instance (via WPVibe). | **Adopt now** |
| fewer-permission-prompts | Allowlist the repeated read-only git/php/MCP calls across the multi-MCP workflow. | **Adopt now** |
| session-start-hook | Auto-verify the plugin on fresh web clones (done). | Adopt now |
| simplify | Quality pass on the big files (`admin`, `rest-controller`) and the Stripe glue — after code-review. | Situational |
| loop | Poll CI runs, webhook delivery, or order-board state during integration testing. | Situational |
| run | Drive the plugin in a real WP install to confirm a change works (pairs with verify). | Situational |
| update-config | Maintain `settings.json` hooks/allowlist as the toolset grows. | Situational |
| deep-research | One-off scoped questions (AU surcharging law, Stripe SCA, GST tax-invoice rules). | Situational |
| keybindings-help | Editor key remapping — no bearing on the plugin. | Skip |
| claude-api | Only if an LLM feature is added (menu copy, support bot); not core today. | Skip |

## MCP integration opportunities
| Integration | Concrete uses for DoughBoss | Priority |
|---|---|---|
| **Stripe** | Finish & validate the in-progress integration: create/confirm **test PaymentIntents in AUD**, set up & verify **webhooks** (payment_succeeded/refund), confirm the dependency-free client against current API docs; wire refunds into the order board; get SCA/3DS + idempotency right. | **High** |
| **WPVibe** (doughboss.com.au) | Deploy the plugin to a **draft theme** and smoke-test checkout on the real site without touching production; run **WP-CLI** for activation/DB/option checks & logs; pull **real menu, prices, brand assets, 3-shop config** to seed accurate fixtures. | **High** |
| **Xero** *(pick one)* | Auto-create a **GST tax invoice per paid order**; reconcile Stripe payouts to the bank feed; per-shop P&L / cash position. | **High** |
| **Zoho Books** *(alternative)* | Same per-order GST tax invoice automation with native AU GST templates + a customer-payment record tied to the Stripe charge; emailed compliant invoices. | **High** |
| **GitHub** | PRs + required code/security review on payment changes; **CI Actions** running PHP lint + secret scanning on every push; tagged releases of the plugin zip. | **High** |
| **Canva + Adobe Firefly** | Generate/retouch menu & pizza-builder imagery and category tiles; per-shop promo / catering one-pagers; export web-optimised assets to upload via WPVibe. | Med |
| **Gmail / Drive / M365** | Order & catering-enquiry notification drafts + templated confirmations; client handover docs & brand/menu source files; rollout scheduling. | Med |
| **Zapier** | Fallback glue for anything without a native MCP (paid order → SMS/Slack to the shop, push to POS/printer, mailing-list sync). Prefer native integrations for money paths. | Med |
| **PDF Viewer** | Eyeball generated GST tax invoices / catering quotes (GST-inclusive totals) before they reach customers. | Low |

## Top 5 highest-impact moves
1. **Finish & validate Stripe via the Stripe MCP** — wire the scaffolded PaymentIntents client
   to checkout, create/confirm test charges in AUD, stand up & verify webhooks. *The one thing
   blocking revenue and the highest-risk surface.*
2. **Deploy + smoke-test on doughboss.com.au via WPVibe (draft theme) + pull real data** —
   prove the full order→pay→board flow on the real site, safely, with accurate menu/shop data.
3. **GitHub guardrails** — PR reviews + CI (lint/secret-scan) + `security-review` on every
   payment PR. *A live site taking cards/PII can't ship unreviewed payment code.*
4. **Auto GST tax invoices via one accounting MCP (Xero *or* Zoho Books)** — turn each paid
   order into a compliant AUD GST-inclusive invoice, reconciled to payouts across 3 shops.
5. **`verify` + `security-review` on checkout before launch; `init`/`fewer-permission-prompts`
   to keep the multi-MCP workflow fast** — convert "it compiles" into "it demonstrably works
   and is safe to take money."

## Notes
- Choose **one** accounting system (Xero or Zoho Books) — running both duplicates invoices.
- Keep money-critical paths on **native integrations** (Stripe, accounting), not Zapier.
- Outbound network is allowlisted; pull live-site assets through **WPVibe**, not direct fetch.
