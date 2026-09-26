# Dough Boss — Findings & Recommendations

Two separate items below: (1) the live site, doughboss.com.au, and (2) the doughxsnow voucher/admin repo. Everything is read-only — no edits, commits, pushes, form submissions, or logins were made anywhere. AUD, Australian English throughout.

---

## PART 1 — doughboss.com.au: ranked improvements

Evidence base: fetched homepage + `/locations/`, `/vouchers/`, `/catering/`, `/menu/`, `/order/`, `/track-order/` live via curl (retried once after an initial TLS/406 hiccup, per your note) on 2026‑09‑25. All facts below are **VERIFIED** against that live HTML/JSON‑LD unless marked **INFERRED**.

### 🔝 Top 5

**1. Two live voucher systems that don't talk to each other — reconcile before launch**
- Evidence: `/vouchers/` (VERIFIED, live now) already collects **student email + mobile number** for a one‑time $5 code, independent of any QR/Postgres ledger. Separately, the owners's `doughxsnow` repo is building a *different* one‑time‑use voucher engine (QR + Postgres, no email capture) for the same "Dough Boss student voucher" campaign — its own plan doc explicitly recommends "no account required" (`docs/VOUCHER_SYSTEM_PLAN.md:106`, INFERRED as the intended default, contradicted by the live page).
- Why it matters: two independently-issued "one $5/$10 voucher" mechanisms under the same brand risks double-dipping, confused staff at the till ("which code is this?"), and duplicate liability tracking. Revenue/risk: real $ exposure once both are live simultaneously.
- Effort: **S** (a decision + a short doc), then M to wire one into the other.
- Who: the owners decides which system is canonical; dev implements.

**2. Local SEO / structured data covers only 1 of 3 shops — and misses the one that's growing**
- Evidence: the homepage's `Organization`/`LocalBusiness` JSON‑LD (VERIFIED, `<script type="application/ld+json">` on `/`) only has a `Restaurant`/`Bakery` entity for **Revesby** (address, phone, opening hours). **Bankstown and Roselands have no structured-data entity at all**, despite both having full details in the visible page copy.
- Why it matters: Bankstown is the location that's about to launch the Snow Boss co-brand and the whole student-voucher campaign — it's the one location with zero Google-eligible LocalBusiness schema, hurting Maps/local-pack ranking exactly where new footfall matters most. Revenue impact, low effort.
- Effort: **S** (copy the existing Revesby block twice, swap address/phone/hours).
- Who: dev (or whoever edits the `doughboss` custom plugin/theme — see Part 2).

**3. No customer reviews or social proof anywhere on the site**
- Evidence: WebFetch pass over the homepage found no testimonials, no embedded Google reviews widget, no review count/star rating (VERIFIED — none present in the fetched markup).
- Why it matters: for a 3-location bakery competing on local search, review counts/stars are one of the highest-leverage trust signals before someone drives over. Zero cost to display if you already have Google reviews.
- Effort: **S–M** (embed Google Places widget or a simple hand-picked quotes strip).
- Who: marketing (content), dev (embed).

**4. Only one social channel linked (Instagram) — no Facebook Page, no Google Business Profile link**
- Evidence: the only social `href` on the homepage is `instagram.com/doughboss` (VERIFIED, grepped the full page). No Facebook, no Google Maps/Business Profile link surfaced outside the embedded map iframes.
- Why it matters: Facebook still drives meaningful local-food discovery and is where Google-review prompts and Messenger enquiries land for many customers; missing it is a quiet lead-generation gap. Low effort, no downside.
- Effort: **S**.
- Who: marketing (set up/verify the Page), dev (add the link).

**5. Menu/Order/Vouchers pages are indexable but functionally dead ends ("coming soon" everywhere)**
- Evidence (VERIFIED): body class is literally `dbf-ordering-paused`; `/menu/`, `/order/` both say "Online checkout is coming soon… Please call (02) 9774 2286 to place an order"; `/track-order/` exists but there's nothing to track yet.
- Why it matters: these are real, crawlable, linked-from-nav pages that currently convert to nothing but a phone number. Until checkout ships, each should carry a clear, single next step (call/DM/visit) rather than leaving the visitor to hunt for it — small copy/CTA fix, meaningful conversion effect while ordering is paused.
- Effort: **S** (copy/CTA tightening on 2–3 existing pages).
- Who: marketing (copy), dev (deploy).

### Others (ranked, not top 5)

6. **Mini pizzas / catering not surfaced on the homepage.** Catering copy mentions "mini manoush, pizzas, pies, wraps" (VERIFIED via `/catering/`), matching what you described — but the homepage itself doesn't call out "mini pizzas" or catering platters at all. A homepage catering teaser (with a mini-pizza photo) is a near-zero-cost upsell surface. Effort S · marketing/dev.
7. **Franchising and Wholesale pages exist in the nav but weren't inspected** (INFERRED gap — not fetched in this pass); worth a quick content check given you're about to be a partner and franchising messaging directly affects how a new partner/location is positioned. Effort S · owner review.
8. **Formal review of page-load performance** — our own fetch showed ~1.7s time‑to‑first‑byte (INFERRED as indicative only; our request went through a proxy, so this isn't a clean measurement) — worth a real Google PageSpeed/Lighthouse pass rather than treating this as confirmed slow. Effort S · dev.
9. **No visible newsletter/SMS capture outside the coming-soon voucher form.** Given ordering is paused, an email list would be the highest-value thing to build now for the eventual launch. Effort S–M · marketing.
10. **Consider adding TikTok only if you decide to — not recommended by default** (matches your own operating-rules stance elsewhere; noting only because Snow Boss's own presence is currently invisible on the live site at all — see Part 2 finding on Snow Boss being unmentioned anywhere on doughboss.com.au).

---

## PART 2 — doughxsnow repo audit (`/home/user/doughxsnow`)

**Checks actually run** (not just read): `npm ci` — succeeded, network was **not** blocked (145 packages, ~5s). `npx tsc --noEmit` on Node 22 (`/opt/node22/bin/node`) — **clean, zero errors**. I also stood up a throwaway local Postgres 16, applied all three migrations, and ran `npm test` for real: **6/6 pass**, including the concurrent-double-redeem race test — the one-time-use guarantee is genuinely verified, not just claimed in docs.

### Security

| # | Finding | Evidence | Severity |
|---|---|---|---|
| S1 | **Demo admin credentials are committed in cleartext**: email `manager@doughboss.demo`, PIN `[REDACTED]`. | `docs/ADMIN.md:9-13` | High — repo is private (VERIFIED via GitHub API: `private: true`), but `/admin` itself will be reachable to anyone on the open internet once deployed (`src/server.ts:504-506` serves it with no IP/network gate, only the login), so this credential is the whole perimeter. ADMIN.md's own wording ("seeded for testing") implies it has actually been run against a DB at some point. |
| S2 | **Minimum PIN length is 4 digits, for every role including admin.** | `src/server.ts:381` (`/^\d{4,8}$/`), same rule in `src/seed-staff.ts:29` | Medium — 10,000-combination space guarded only by a per-(path,IP) rate limit of 5/min (`src/server.ts:261`), with no per-account lockout. Recommend a longer minimum (e.g. 6–8 digits) for `role=admin` specifically. |
| S3 | **`app.set("trust proxy", true)` trusts the entire X‑Forwarded‑For chain.** | `src/server.ts:212` | Medium — with `true` (vs. an exact hop count, e.g. `1` for a single-proxy Render deployment), a client can forge X‑Forwarded‑For and get `req.ip` to read whatever they choose, defeating the IP-keyed rate limiter on login/redeem/lookup (`rateLimit()`, `src/server.ts:185-203`) — the brute-force and redeem-flood protections are only as good as this setting. Fix: pin it to the real Render hop count. |
| S4 | **Session tokens live in `localStorage`, no CSP header.** | `web/staff/index.html:96-97,142-143`; `web/admin/index.html:181-182,227-228` | Medium — no httpOnly cookie means any future XSS on either page steals a 12h-lived admin/staff token. Mitigating factor (positive): using `Authorization: Bearer` instead of cookies means there's **no CSRF exposure** — a deliberate and correct trade-off, just worth pairing with a CSP header. |
| S5 | Voucher code entropy and enumeration resistance are solid. | `src/voucher/code.ts:7-10` — Crockford base32, 10 chars ≈ 50 bits; lookup route rate-limited 60/min/IP (`src/server.ts:236`) | **Positive** — 2⁵⁰ combinations makes brute-forcing infeasible even ignoring rate limiting. |
| S6 | One-time-use race safety. | `src/voucher/redeem.ts:174-187` atomic `UPDATE … WHERE status IN ('issued','claimed') AND valid_until > now()`; idempotency-key replay handling `redeem.ts:193-209` | **Positive, independently verified** — I ran the concurrent-redeem test myself against a real Postgres; exactly one of two simultaneous scans wins, every time. |
| S7 | Row-Level Security enabled, deny-by-default. | `db/migrations/002_hardening.sql:30-37`, `003_admin.sql:34` | **Positive**, though there are no explicit policies defined anywhere — fine as long as only the privileged app role (table owner/BYPASSRLS) ever connects; worth a one-line comment confirming that's guaranteed on the hosting side. |
| S8 | CORS allowlist is a plain array reflect, no wildcard, no `Access-Control-Allow-Credentials`. | `src/server.ts:134-155` | **Positive** design. One inconsistency: the default allowlist includes `https://edagher92-coder.github.io` (`src/server.ts:136`, `.env.example`), and `.github/workflows/pages.yml` publishes `web/` — **including `web/admin/index.html` and `web/staff/index.html`** — to that exact GitHub Pages URL on every push to `main`. The pages' own `fetch()` calls use **relative** paths (`fetch("/api/staff/login")`, `web/admin/index.html:219`), so as shipped they'd just 404 against GitHub Pages rather than reach production — but the CORS entry only makes sense if someone intends absolute-URL cross-origin calls later. Worth a decision: either drop the GitHub Pages CORS entry (nothing uses it) or make it deliberate and make the calls absolute — right now it's a inconsistency, not an active hole. |
| S9 | Voucher void is irreversible, admin-only, and correctly rejects non-voidable states with the current state echoed back. | `src/server.ts:471-494` | **Positive.** |

No CSRF gap (confirmed by S4's own analysis), no SQL injection surface found (every query is parameterised throughout `redeem.ts`, `server.ts`, `db.ts` — no string concatenation into SQL anywhere I read), and admin-role checks are enforced server-side per request (`requireAdmin`, `src/server.ts:115-131`), not just hidden client-side.

### Operational readiness

- **No documented fallback if the redeem server is down at the counter.** I read `docs/VOUCHER_SYSTEM_PLAN.md`, `docs/VOUCHER_SETUP_AND_OPS.md`, and `docs/DEPLOYMENT.md` in full — none address what staff do if `redeem.doughboss.com.au` is unreachable mid-service (no offline mode, no manual-override runbook, no "call this number" fallback). Given vouchers = real discount liability, this is a genuine gap before go-live. **Recommend**: a one-page "system is down" runbook (e.g., accept on trust up to a small daily cap, log manually, reconcile later).
- **No backups configured.** `docs/VOUCHER_SETUP_AND_OPS.md:122` itself flags this: Supabase's free tier (the recommended plan) has no backups; Pro (~$38/mo) is needed for that. As written, the ledger — the single source of truth for who's owed what — has no recovery path from an accidental `DELETE`/corruption.
- **No monitoring/alerting beyond a bare `/healthz`.** `src/server.ts:218-225` checks DB connectivity but nothing polls it; there's no mention anywhere of uptime monitoring being wired up.
- **CI is real and runs on every push/PR**: secret-guard (blocks a committed `.env` or a leaked Google API key pattern), typecheck+build, and the Postgres-backed test suite (`.github/workflows/ci.yml:1-70`) — this is solid engineering hygiene, genuinely reduces regression risk.
- **Graceful shutdown is implemented** (`src/server.ts:562-576`) — finishes in-flight requests, closes the pool, hard-stops after 10s. Good.
- **Migrations are idempotent and additive** (`IF NOT EXISTS` guards throughout `002_hardening.sql`, `003_admin.sql`) — safe to re-run, low deploy risk.

### Admin console usefulness for the owners

Read `docs/ADMIN.md` and `web/admin/index.html` in full. The console is genuinely well-scoped for two non-technical shop managers:
- Live overview (outstanding liability, redeemed $, last-7-days redemptions, attempts-by-outcome as a fraud signal, top staff) — `src/server.ts:315-356`.
- Staff management with instant deactivation (`src/server.ts:409-429`) and a self-deactivation guard so an admin can't lock themselves out — a thoughtful detail.
- Runtime-editable prompts (claim-page message, fine print) apply within ~30s with no redeploy (`src/server.ts:53-76`) — genuinely useful for a manager who wants to tweak wording without calling a developer.
- Voucher status check + void, both self-explanatory, both server-enforced.

**Gap**: nothing in the console lets the owners **create a new print batch** themselves — `npm run generate` is CLI-only (`src/generate.ts`), so every new voucher run still needs a developer. If they're expected to run repeat campuses/values independently, that's the one missing admin-console feature.

### web/ pages vs the live WordPress site — duplication risk

This is the most consequential structural finding, and it's fully verified against the live site:

- The live site is **not WooCommerce** — I checked explicitly (zero `woocommerce`/`wc-`/cart/checkout markers anywhere; body class is `wp-theme-doughboss-final … dbf-ordering-paused`, plugin path is `/wp-content/plugins/doughboss/`, a bespoke custom plugin). **`docs/WORDPRESS.md`'s entire "discounts via WooCommerce coupons" plan and `src/sync-woocommerce.ts` (which needs `WC_CONSUMER_KEY`/`WC_CONSUMER_SECRET` against a WooCommerce REST API, `.env.example:19-21`) are built on a premise the live site doesn't satisfy.** When online ordering does go live, it will be through whatever checkout the custom `doughboss` plugin implements — not WooCommerce — so that sync script and integration doc will need a rewrite, not just activation.
- **`web/locations/index.html`** sets `canonical` to `https://doughboss.com.au/locations/` (`web/locations/index.html:9`) — but a **different, already-live, already-indexed** `/locations/` page exists on WordPress right now (VERIFIED, fetched it; three-shop summary, no Snow Boss mention). Same pattern for `web/bankstown/index.html`, canonical `.../bankstown/` (`web/bankstown/index.html:9`) — that path currently returns no response live (curl timed out / connection failed, i.e. not published), so no conflict *yet*, but the moment either repo page is pasted into WordPress at that URL without reconciling content, you get either an overwrite of the real, ranking `/locations/` page or duplicate-content risk if both exist under different hosts.
- **Snow Boss is unmentioned anywhere on the live site** — zero hits for "snow" across the homepage and every subpage I fetched. The repo's Bankstown/locations pages are built entirely around the Dough Boss × Snow Boss co-brand story; none of that has shipped publicly yet.
- The live `/vouchers/` page already runs its own email-capture voucher flow (see Top-5 #1) — independent of, and not reconciled with, the ledger this repo builds.

**Net read**: `docs/WORDPRESS.md`'s stated principle ("native-first, don't rebuild what WordPress/WooCommerce already does") is the right instinct, but two of its load-bearing assumptions (WooCommerce exists; `/locations/`, `/vouchers/` are blank canvases) don't hold. Before deploying any `web/` page to those live paths, someone needs to diff the repo version against the current live page content, not just paste over it.

### Summary of gaps, ranked

1. Reconcile the two voucher systems (see Top‑5 #1) — **highest priority, blocks safe launch**.
2. Fix the WooCommerce-vs-custom-plugin mismatch in `docs/WORDPRESS.md` / `src/sync-woocommerce.ts` before Phase 4.
3. Rotate/remove the demo admin credential before any production seeding, and tighten admin PIN length.
4. Pin `trust proxy` to an exact hop count (`src/server.ts:212`).
5. Write the "server is down at the counter" runbook and get Supabase backups (or at minimum a scheduled `pg_dump`) before real vouchers are printed.
6. Give the admin console a batch-generation UI if the owners are meant to run campaigns unassisted.
7. Reconcile `web/locations/` and `web/bankstown/` content against the live WordPress pages before publishing either.