# DOUGHBOSSV2 main (ec1cc6c) — platform demo audit (read-only, 2026-09-26)

Screenshots: scratchpad/doughboss-audit/main/ (all 11 pages, 390 + 1440, authed owner/backend/staff).

Fact-check: addresses, phones, hours, "since 2009 / three shops" in demo match live doughboss.com.au. No fake reviews/testimonials. Live site says "Online ordering is coming soon" (Revesby first).

Ranked (top 5 starred):
1* owner.html:474/478 — alert HTML escaped then innerHTML'd, shows literal `<b>Revesby</b>`. S, dev.
2* Ordering not wired to kitchen/owner boards; demo-fixtures.js:26-44 uses items not on the menu (Fattoush, Ayran, Lahm bi ajeen, Lentil soup, Margherita, Garlic bread). Caption it + draw fixtures from real catalogue. M, dev.
3* catering.html / menu.html (the sitemap SEO pages) use a different inline palette/header, no legal footer; menu.html shows zero items/prices despite WebPage schema. M, dev/marketing.
4* canonical/OG/JSON-LD/robots/sitemap hard-code github.io/DOUGHBOSSV2 path; sitemap lastmod 2026-07-23 stale. S, dev.
5* No live online ordering yet; demo has no backend (payments.enabled:false, forms mailto only, menu hard-coded 3x). L, dev/owner.
6 Gold #b5571f on cream = 4.43:1, below AA 4.5:1 (eyebrows, links). S.
7 _headers ignored on GitHub Pages; meta-CSP frame-ancestors ignored by spec (console warnings on owner/backend); missing nosniff/Referrer-Policy/Permissions-Policy/HSTS. S/M.
8 No per-item allergen flags (Zaatar = sesame; Choco Banana = hazelnut). S, owner.
9 franchise.html doesn't surface Franchising Code question (only licensing.html A2.4). S, owner/legal.
10 Fixture names/addresses look like real PII (e.g. "14 Marco Ave, Revesby"). S.
11 terms.html 30% deposit 48h vs FAQ "deposits not live yet" — make consistent. S.
12 Ops pages missing favicon link (404). S.
13 Dead legacy JSON-LD (type=application/json) index.html:37-38, ~4KB. S.
14 Four banner JPGs (~390KB) not WebP. S.

Strengths: honest "concept demo" disclaimers everywhere; privacy/terms APP-aware, marked DRAFT; licensing flags Franchising Code; no fabricated metrics; skip link, focus trap, lang en-AU, 100% alt coverage; kitchen board well designed.

Readiness table: menu single-source CMS; real order bus; PCI gateway; real staff auth (any ID/passcode works today, localStorage session); solicitor sign-off privacy/terms/franchise; repoint SEO URLs; host honouring _headers; analytics off until consent live; per-item allergens.
