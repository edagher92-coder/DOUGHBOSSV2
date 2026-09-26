# Dough Boss — full evaluation (2026-09-26)

**For:** Codex (DOUGHBOSSV2 and DOUGHXSNOW), the owners and partners.

**Scope:** four read-only audits, run in parallel on 2026-09-25/26:
- **A:** the live website, https://doughboss.com.au
- **B:** the WordPress ordering plugin on the default branch of DOUGHBOSSV2, which is the one installed live as `wp-content/plugins/doughboss`
- **C:** the voucher and staff admin system in DOUGHXSNOW
- **D:** the static platform demo on `main` of DOUGHBOSSV2 (ec1cc6c)

**Method:**
- Nothing was edited, submitted, ordered or logged into.
- Every item is marked VERIFIED (seen, with a URL or file:line) or INFERRED.
- Anything unverifiable is marked `[CONFIRM]`.
- Full evidence is in `./`. The demo admin credential is redacted because this repository is public.

**Fact check:** the addresses, phone numbers, hours and "since 2009, three shops" in the demo match the live site. No fabricated reviews, testimonials or metrics were found anywhere.

---

## Fix now — live revenue or safety impact

| # | Finding | Evidence | Effort | Who |
|---|---|---|---|---|
| 1 | **The live menu doesn't load.** `/order/` and `/menu/` show "Failed to fetch" in place of the menu on mobile and desktop. The plugin's dine-in table-session check fires on the public pickup page, where there is no table session, and wipes the menu and cart. No visitor can see a single item or price. | A §1: both screenshots, plus the matching code path in `public/js/doughboss.js` | S–M | dev |
| 2 | **"Order Online" is the main call to action everywhere, but it leads to "call (02) 9774 2286".** Until checkout ships, change it to a clear "Call to order" button, which is click-to-call on mobile. | A §2 | S now, L for checkout | owner decides the wording; dev |
| 3 | **Allergens aren't flagged per item.** Zaatar contains sesame and Choco Banana contains hazelnut; both are severe allergens. Add per-item icons in the same way as the existing V/VG tags. | D #8 | S | owner and marketing |
| 4 | **Two voucher systems don't talk to each other.** The live `/vouchers/` collects a student's email and mobile for a $5 code. DOUGHXSNOW builds a separate QR and Postgres ledger for the same campaign. Pick one before both are live, or staff and liability tracking will diverge. | C Part 1 #1 | S to decide, M to wire | owner |
| 5 | **Demo admin credential** `manager@doughboss.demo` is in DOUGHXSNOW `docs/ADMIN.md`. Deactivate it once the owners' own accounts work. `/admin` is internet-reachable once deployed. | C S1 | S | owner |

## Before online ordering goes live (the plugin)

| # | Finding | Evidence | Effort |
|---|---|---|---|
| 6 | **Only one store.** There are no locations; "Accept orders" is one manual switch rather than a clock; and there is no pickup-time picker. The site lists three shops with different hours `[CONFIRM each shop's hours with the owners before encoding them]`. | B ★3, #10; `class-doughboss-settings.php:57-60`, `class-doughboss-admin.php:373-376`, `doughboss.js:337-353` | L |
| 7 | **No sold-out toggle** per item. Bakery batches sell out through the day. | B ★4 | S |
| 8 | **No payments** (pay on pickup only), which risks no-shows on made-to-order food. Add a card gateway: Square fits if the shops already use Square POS `[CONFIRM which POS each shop uses]`. | B ★2, `readme.txt:42-46` | M–L |
| 9 | **Tax is added on top, US-style, and the currency defaults to USD.** Australian menus must display GST-inclusive prices, and larger orders need a GST and ABN breakdown. | B Part 2 #5, #6 | S–M |
| 10 | **No sizes or add-ons** outside the pizza builder, so every manoush option has to be a separate item. There are no catering fields either: serves, trays, lead time or minimum order. | B #15, #16 | M, M–L |
| 11 | **Security:** checkout and order tracking have no rate limit or bot protection; the checkout email address is unverified before mail is sent to it; menu edits aren't scoped to `manage_doughboss`. | B Part 2 #1–#3 | S–M |
| 12 | **Reliability:** failed order emails are silent, so a kitchen could miss orders. Order-item inserts aren't in a transaction. Timestamps use a zero-date default. | B #7, #8, #12 | S |
| 13 | **Admin:** there is no daily total, CSV export or kitchen docket. | B #17, #18 | S–M |

## Website and local search

| # | Finding | Evidence | Effort |
|---|---|---|---|
| 14 | Only Revesby has LocalBusiness/Restaurant structured data. **Bankstown and Roselands Centro are missing**, so they're invisible to that layer of Google local search. | A ★4, C #2 | M |
| 15 | Menu items and prices exist only in client-side JavaScript, so Google cannot index them. Render the menu on the server. | A ★5 | M |
| 16 | `/order/` and `/menu/` are duplicate pages with the same title, meta description and H1. Pick one and 301-redirect the other. | A ★3 | S |
| 17 | Housekeeping: <br>• an orphaned blank `/contact/` page<br>• `/student-vouchers/`, a duplicate listed in the sitemap<br>• `/kitchen/`, a login-only page listed in the public sitemap<br>• `/about-us/` reuses the homepage title | A #6–#9 | S |
| 18 | No on-site star rating or review count. Only Instagram is linked: there is no Facebook link and no Google Business Profile link. | B ★5, C #3–#4 | S |

## The platform demo on `main` (DOUGHBOSSV2)

The demo is honest: every screen says "concept demo", the privacy policy and terms are drafts aware of the Australian Privacy Principles, and the kitchen board is strong. Items:

| # | Finding | Evidence | Effort |
|---|---|---|---|
| 19 | The owner dashboard alert prints raw HTML (`<b>Revesby</b> running behind…`). | `demo/owner.html:474/478` | S |
| 20 | The kitchen and owner boards use fixture orders containing items that aren't on the menu (Fattoush, Ayran, Margherita, Garlic bread). Draw the fixtures from the real catalogue, or label them as sample data. | `demo/demo-fixtures.js:26-44` | S–M |
| 21 | `menu.html` shows no items and no prices. `catering.html` and `menu.html` use a different palette and header from the rest of the demo. | D #3 | M |
| 22 | Canonical, Open Graph, JSON-LD, robots and sitemap URLs all point at the GitHub Pages demo path. The sitemap `lastmod` is stale. | D #4 | S |
| 23 | The ordering front end has no backend: payments are off, forms use `mailto`, the menu is hard-coded three times, and any staff ID signs in. See the readiness table in `./D-platform-demo-main.md`. | D #5 | L |
| 24 | Gold text on cream measures 4.43:1, below the WCAG AA 4.5:1. The `_headers` file is ignored on GitHub Pages, and the `frame-ancestors` in the meta CSP is ignored by the spec, so there is no working clickjacking defence. | D #6, #7 | S–M |
| 25 | `franchise.html` should say plainly that the Franchising Code may apply; today only `licensing.html` A2.4 says so. The terms say "30% deposit, 48h" while the FAQ says deposits aren't live yet. | D #9, #11 | S; solicitor |

## Voucher and admin system (DOUGHXSNOW)

**Solid:**
- The one-time-use guarantee holds. The audit ran the concurrent double-redeem test against a real Postgres, and exactly one scan wins.
- Voucher codes carry about 50 bits of entropy.
- Every query is parameterised.
- Row-level security is on, and CI is real.

| # | Finding | Evidence | Effort |
|---|---|---|---|
| 26 | Admin PIN minimum is 4 digits. Rate limiting is per IP with no per-account lockout, and `trust proxy: true` lets X-Forwarded-For be spoofed to get around it. Set the exact hop count and require a 6–8 digit admin PIN. | C S2, S3; `src/server.ts:212, 381` | S |
| 27 | Session tokens are kept in `localStorage` and there is no CSP. GitHub Pages also publishes `web/admin` and `web/staff`. | C S4, S8 | S–M |
| 28 | No backups: the Supabase free tier has none. There is no uptime monitoring and no runbook for "server down at the counter". | C Operational readiness | S–M |
| 29 | `docs/WORDPRESS.md` and `src/sync-woocommerce.ts` assume WooCommerce, but the live site uses a custom plugin. `web/locations` and `web/bankstown` would overwrite already-indexed live pages. | C, web/ pages section | S to correct the docs |
| 30 | Owners can't create a new voucher batch from the console; it's CLI-only. | C admin section | M |

## Owner decisions needed (the owners)

- Which voucher system is the one to keep (#4).
- Each shop's hours, pickup windows and whether it offers delivery `[CONFIRM]`.
- Payment provider and POS in use at each shop `[CONFIRM]`.
- Wording of the interim "Order Online" CTA.
- Solicitor review of the privacy policy, terms, and franchise/licensing documents before any fee is taken.
- Allergen list per item.
