# Dough Boss (doughboss.com.au) — Website Improvement Audit

**Scope:** Live read-only audit of `https://www.doughboss.com.au` (redirects to `https://doughboss.com.au`), 25 Sep 2026. Fetched via curl (retried on TLS reset per brief) and rendered with Playwright Chromium at 390px/1440px; screenshots saved to `/tmp/claude-0/-home-user/668dc264-66e3-5bb9-bf19-491c2aea86e9/scratchpad/doughboss-audit/`. No forms submitted, no logins attempted, nothing edited. Every claim below is labelled VERIFIED (I fetched/saw it, source given) or INFERRED (reasoned from evidence but not directly confirmed) — unverifiable facts are marked `[CONFIRM]`.

---

## TOP 5

**1. ★ The online menu fails to load for a normal visitor — shows an error, not the menu, on both mobile and desktop**
- Evidence (VERIFIED): `/order/` renders a fully-styled page, then the menu widget shows only **"Failed to fetch"** in place of any items — reproduced on 390px and 1440px screenshots. The plugin JS (`doughboss.js`) contains the exact matching code path: a comment reading *"A claimed but expired/revoked table session must never silently become a switchable pickup order. Stop ordering and direct the guest to staff"* wipes `[data-doughboss-menu]`/`[data-doughboss-cart]` and prints "…Please scan the table QR again or ask a staff member for help." This is dine-in QR-ordering logic firing on the public pickup-browsing page, which has no table session at all.
- Why it matters: **Revenue.** `/order/` and `/menu/` are the two most-linked CTAs on the site ("Browse the menu", "Order Online" in the header). Right now a customer clicking either, from Google or a social link, cannot see the menu or prices at all — not "coming soon", broken.
- Effort: **S–M** (likely a single conditional: don't attempt table-session hydration when there's no table context).
- Who: **dev**.

**2. ★ There is no online checkout — "Order Online" is the site's main CTA everywhere, but it leads to a phone-only page**
- Evidence (VERIFIED): `/order/` and `/menu/` both display *"Online checkout is coming soon… Please call (02) 9774 2286 to place an order"* and a *"Checkout coming soon"* badge; the same banner ("ONLINE ORDERING IS COMING SOON") runs site-wide.
- Why it matters: **Revenue/effort saved.** Every ad, social bio link, and nav CTA currently drives to a dead end for self-service ordering — a real conversion-rate cost, especially on mobile where calling is friction. Even a simple "click-to-call" or a basic click-and-collect form would convert better than a bare phone number behind an "Order Online" button.
- Effort: **L** (this is the core product build, not a copy fix) — but a **S** interim fix (swap "Order Online" CTA copy/behaviour to a clear "Call to order" button until checkout ships) is cheap and worth doing now.
- Who: **dev** (checkout), **owner/marketing** (interim CTA copy decision).

**3. ★ `/order/` and `/menu/` are duplicate pages — same title, same meta description, same H1, same content — both in primary navigation**
- Evidence (VERIFIED): identical `<title>Dough Boss Menu | Manoush, Pizza & Pies Sydney – Dough Boss</title>` and identical `<meta name="description">` on both URLs; both render the same H1 ("Order online.") and the same lede text; header nav links to `/menu/` while multiple CTA buttons link to `/order/`.
- Why it matters: **Risk (SEO).** Two URLs competing for the same search intent split ranking signal and confuse Google about which is canonical; it also confuses customers/staff about which link to share.
- Effort: **S** — pick one URL (recommend `/order/`, since it already carries the ordering-status logic) and 301-redirect the other, or clearly differentiate the two pages' purpose and metadata.
- Who: **dev**.

**4. ★ Local SEO structured data only exists for 1 of the business's 3 shops**
- Evidence (VERIFIED): The homepage lists **three trading locations** — Revesby, Bankstown, and Roselands Centro (each with its own address, phone and hours) — but the JSON-LD `Bakery`/`Restaurant` schema (present identically on the homepage and on the dedicated `/locations/` page) contains **only** the Revesby location (`#location-revesby`). Bankstown and Roselands Centro have zero structured-data footprint anywhere on the site.
- Why it matters: **Revenue.** Google Search/Maps rich results, hours-in-search, and "near me" local pack visibility rely on this schema per location. Two of three shops are effectively invisible to that layer of local search — a meaningful gap for a multi-site food business.
- Effort: **M** — add `Bakery`/`Restaurant` schema entries for Bankstown and Roselands Centro (address, phone, hours, `hasMap`, `parentOrganization`) mirroring the existing Revesby block.
- Who: **dev** (implementation), **owner** (confirm each shop's exact hours/phone before publishing — see gaps below).

**5. ★ Menu items and prices don't exist in the page's HTML at all — they're 100% client-JS-rendered, and currently unreachable**
- Evidence (VERIFIED): `curl`-fetched `/order/` and `/menu/` HTML contain zero `$` price strings and no per-item markup; the real data (43 items, real AUD prices, e.g. Manoush from $9.50, Pizza $11–$15, Pies $10–$11) lives only behind `GET /wp-json/doughboss/v1/menu`, fetched client-side. Combined with Finding #1, this means neither Google nor a visitor currently sees any menu content server-side or client-side.
- Why it matters: **Revenue + risk.** Search engines can't index menu items or prices for rich snippets/Google's menu feature, and any visitor with slow/blocked JS sees nothing regardless of the Finding #1 fix.
- Effort: **M** — server-render the menu list (even as a plain, non-interactive fallback) so content and prices exist in the HTML; JS can still layer on interactivity.
- Who: **dev**.

---

## Also worth doing (ranked, not in top 5)

**6. Orphaned, unbranded default-WordPress page live at `/contact/`**
- Evidence (VERIFIED): `/contact/` returns HTTP 200 with `<title>contact – Dough Boss</title>` (lowercase, breaks the "X – Dough Boss" pattern used everywhere else) and renders as bare WordPress block-editor boilerplate — no branding, no obvious content matching the rest of the site. It is not linked from the header or footer nav, and not in the XML sitemap.
- Why it matters: Low likelihood of discovery, but if found via an old link, cached search result, or someone typing the URL, it reads as a broken/demo page for a soon-to-be-partner business.
- Effort: **S** — either delete/redirect it to the real contact info in the footer, or build it out properly.
- Who: **dev**.

**7. `/student-vouchers/` is an orphaned near-duplicate page, still published in the sitemap**
- Evidence (VERIFIED): the real "Student vouchers" nav link correctly points to `/vouchers/`. `/student-vouchers/` is a *different*, unlinked URL that returns 200 with the homepage's exact title tag, and it **is** listed in `wp-sitemap-posts-page-1.xml` — i.e. Google is being told to index a thin duplicate.
- Why it matters: Minor duplicate-content/crawl-budget waste.
- Effort: **S** — delete or 301 to `/vouchers/`.
- Who: **dev**.

**8. An internal, login-gated route (`/kitchen/`) is exposed in the public sitemap**
- Evidence (VERIFIED): `/kitchen/` 302-redirects to `wp-login.php` (correctly access-controlled) but is listed in `wp-sitemap-posts-page-1.xml`, revealing internal system/URL structure to anyone reading the public sitemap.
- Why it matters: Low-severity information hygiene, not a live security hole (it's already login-gated).
- Effort: **S** — exclude it from the sitemap (WordPress `wp_sitemaps_post_types` filter or the sitemap plugin's exclusion setting).
- Who: **dev**.

**9. `/about-us/` reuses the exact homepage `<title>`**
- Evidence (VERIFIED): `about-us.html` title is byte-identical to the homepage's: `Dough Boss | Fresh Manoush, Pies & Catering Sydney – Dough Boss`.
- Why it matters: Small SEO hygiene item — unique titles per page help ranking and click-through from search.
- Effort: **S**.
- Who: **dev/marketing**.

**10. No Content-Security-Policy or Permissions-Policy header on the main site**
- Evidence (VERIFIED): response headers on the homepage/`/order/` show `strict-transport-security`, `x-content-type-options`, `x-frame-options`, and `referrer-policy` (all good) but no `content-security-policy` and no `permissions-policy` (the latter *does* appear, but only on the `/kitchen/` login-redirect response, not on public pages).
- Why it matters: Defence-in-depth against XSS/clickjacking-adjacent risks; not urgent given the other headers already present.
- Effort: **S–M**.
- Who: **dev**.

**11. Heaviest images are plain JPG, not next-gen formats**
- Evidence (VERIFIED): the homepage catering photo is 329KB, the OG social-card image 150KB, menu thumbnails 38–55KB each — all `.jpg`. Images do use `loading="lazy"` correctly (good).
- Why it matters: Minor mobile page-speed gain from WebP/AVIF re-encoding; current weights aren't alarming (order page's full network transfer measured ~146KB across 20 requests) so this is a nice-to-have, not urgent.
- Effort: **S** (usually a plugin/build-step setting in WordPress).
- Who: **dev**.

---

## Things that are already good (so nothing gets "fixed" that isn't broken)
- HTTPS/www: `www.doughboss.com.au` and bare HTTP both 301 cleanly to `https://doughboss.com.au`; HSTS is set. VERIFIED.
- `robots.txt` and `wp-sitemap.xml` exist and are well-formed; a real 404 returns HTTP 404 (not a soft-404). VERIFIED.
- The Revesby location's schema (address, phone, hours, `hasMap`, cuisines) is genuinely solid when present. VERIFIED.
- Real photography of actual food/shop, not stock or placeholder imagery, on the pages checked. VERIFIED.
- Mobile viewport meta tag present; images lazy-load. VERIFIED.

## Gaps I could not verify — ask before anyone quotes these externally
- `[CONFIRM]` Whether "mini pizzas" (mentioned in the brief) are meant to be a menu category — the live menu API has no item or category named "mini pizza" (only "Pizza", and "Mini Manoush" on the catering page). Worth confirming with the owners whether that's a naming/positioning gap or simply not yet listed online.
- `[CONFIRM]` Bankstown and Roselands Centro opening hours/phone numbers as shown on-page (Mon–Fri 7am–2pm / Daily 8am–5pm) — I read these directly off the rendered homepage but could not independently corroborate them against a second source; confirm before encoding them into schema (Finding #4).
- `[CONFIRM]` Whether `/contact/`, `/student-vouchers/` and `/kitchen/`'s current states are intentional works-in-progress the owners already know about, versus genuine oversights.