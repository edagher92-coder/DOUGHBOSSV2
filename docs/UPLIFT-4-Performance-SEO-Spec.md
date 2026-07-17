# UPLIFT 4 — Performance & SEO Spec (Demo → WordPress)

Goal: take the static demo (GitHub Pages) from ~8/10 to 9.5/10 on performance, and add the
missing SEO/discoverability layer. Everything here is deterministic — exact files, exact blocks.
Scope: `demo/` first; the "Carries to WordPress" section maps each item forward.

---

## 1. Font self-hosting (removes the last third-party dependency)

Today `demo/index.html` loads Bebas Neue + Barlow from `fonts.googleapis.com`/`fonts.gstatic.com`
(two preconnects + render-chained CSS). Self-hosting kills 2 DNS lookups, 2 TLS handshakes, one
render-blocking cross-origin stylesheet, and the GDPR/privacy exposure of Google Fonts.

### Files to add (subset to latin only via `pyftsubset` or google-webfonts-helper)

```
demo/assets/fonts/bebas-neue-v14-latin-regular.woff2      (~17 KB)
demo/assets/fonts/barlow-v12-latin-regular.woff2          (~24 KB)
demo/assets/fonts/barlow-v12-latin-500.woff2              (~24 KB)
demo/assets/fonts/barlow-v12-latin-600.woff2              (~24 KB)
demo/assets/fonts/barlow-v12-latin-700.woff2              (~24 KB)
```

woff2 only — every browser that runs this demo supports it. No woff/ttf fallbacks.

### @font-face blocks (top of `demo/demo.css`, replacing the Google Fonts comment)

```css
@font-face{font-family:'Bebas Neue';font-style:normal;font-weight:400;font-display:swap;
  src:url('assets/fonts/bebas-neue-v14-latin-regular.woff2') format('woff2');}
@font-face{font-family:'Barlow';font-style:normal;font-weight:400;font-display:swap;
  src:url('assets/fonts/barlow-v12-latin-regular.woff2') format('woff2');}
@font-face{font-family:'Barlow';font-style:normal;font-weight:500;font-display:swap;
  src:url('assets/fonts/barlow-v12-latin-500.woff2') format('woff2');}
@font-face{font-family:'Barlow';font-style:normal;font-weight:600;font-display:swap;
  src:url('assets/fonts/barlow-v12-latin-600.woff2') format('woff2');}
@font-face{font-family:'Barlow';font-style:normal;font-weight:700;font-display:swap;
  src:url('assets/fonts/barlow-v12-latin-700.woff2') format('woff2');}
```

### Head changes (`index.html`, `franchise.html`, `owner.html`, `staff.html`, `licensing.html`, `privacy.html`, `terms.html`, `backend.html`)

- Remove both `<link rel="preconnect" href="https://fonts.*">` lines and the
  `fonts.googleapis.com/css2?...` stylesheet link.
- Add preloads for the two above-the-fold faces only (Bebas headline + Barlow body):

```html
<link rel="preload" href="assets/fonts/bebas-neue-v14-latin-regular.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="assets/fonts/barlow-v12-latin-regular.woff2" as="font" type="font/woff2" crossorigin>
```

`font-display:swap` keeps text visible during load (Arial/Helvetica fallbacks already in the CSS
stack). After this change the demo makes zero third-party requests.

## 2. Image pipeline: WebP + `<picture>` + srcset

Current state: 27 menu thumbs (`assets/menu/*.jpg`, rendered at 72×72 CSS px), 5 banners at a
single 768×432 size (used as hero backgrounds, category headers, and the LCP preload), plus 6
loose jpgs (`chips.jpg`, `falafel.jpg`, etc.).

### Generation (one-time script, e.g. `demo/scripts/build-images.sh` using `cwebp`/ImageMagick)

| Source | Outputs | Target budget |
|---|---|---|
| `assets/menu/*.jpg` (27) | `{name}-144.webp` + `{name}-144.jpg` (144×144, 2x for 72px slot) | ≤ 10 KB webp each |
| `assets/banners/*.jpg` (5) | `{name}-640.webp/.jpg`, `{name}-1280.webp/.jpg` (16:9) | ≤ 45 KB / ≤ 110 KB webp |
| loose jpgs (6) | `{name}-480.webp` + keep jpg | ≤ 30 KB webp |

WebP quality 78–82; jpg fallbacks quality 80 (mozjpeg). Keep originals as the fallback `src`.

### Markup pattern — menu thumbs (replaces each `<img class="mn-it-img" ...>`)

```html
<picture>
  <source srcset="assets/menu/zaatar-144.webp" type="image/webp">
  <img class="mn-it-img" width="72" height="72" src="assets/menu/zaatar-144.jpg"
       alt="Zaatar manoush" loading="lazy" decoding="async">
</picture>
```

One size (144px = 2x) is enough for a fixed 72px slot — no srcset needed on thumbs.

### Markup pattern — banners / category headers (`.mn-cat-bg`, `.show-img img`)

```html
<picture>
  <source type="image/webp"
          srcset="assets/banners/Zaatar-640.webp 640w, assets/banners/Zaatar-1280.webp 1280w"
          sizes="100vw">
  <img class="mn-cat-bg" src="assets/banners/Zaatar-768x432.jpg"
       srcset="assets/banners/Zaatar-640.jpg 640w, assets/banners/Zaatar-1280.jpg 1280w"
       sizes="100vw" alt="" loading="lazy" decoding="async" width="1280" height="720">
</picture>
```

### Hero backgrounds (currently inline `style="background-image:url(...)"`)

Convert the three `.hero-bg` divs to a positioned `<img>` (`object-fit:cover; inset:0`) inside
the same wrapper so they get `<picture>`/srcset and native lazy-loading. The first hero image is
the LCP: keep it `loading="eager" fetchpriority="high"` and update the existing
`<link rel="preload" as="image">` to preload the webp with `imagesrcset`/`imagesizes`.

Expected saving: ~60–70% of image bytes on first load; menu view drops from ~1.2 MB to <400 KB.

## 3. SEO / discoverability layer

### 3a. Per-page title + meta description (unique, ≤60 / ≤155 chars)

| Page | `<title>` | Meta description |
|---|---|---|
| `index.html` | Dough Boss — Lebanese Bakery, Manoush & Pizza, Sydney | Stone-baked manoush, pizza, pies and wraps since 2009. Pickup from Revesby; shops in Bankstown and Roselands. Halal. Catering 10–200. |
| `franchise.html` | Partner with Dough Boss — Franchise & Wholesale | Franchise, wholesale and partnership opportunities with Dough Boss, south-west Sydney's Lebanese bakery. |
| `privacy.html` | Privacy Policy — Dough Boss | How Dough Boss handles your personal information and orders. |
| `terms.html` | Terms of Service — Dough Boss | Ordering, pickup and catering terms for Dough Boss. |
| `licensing.html` | Licensing — Dough Boss | Software and content licensing for the Dough Boss platform. |
| `owner.html` / `staff.html` / `backend.html` | (internal tools) | Add `<meta name="robots" content="noindex,nofollow">` — do not index consoles. |

Add `<link rel="canonical" href="https://edagher92-coder.github.io/DOUGHBOSSV2/...">` per page
(swap to `doughboss.com.au` at WP launch).

### 3b. Schema.org JSON-LD (one `<script type="application/ld+json">` in `index.html`)

Single `@graph` containing:

1. **`Bakery`** (subtype of Restaurant/LocalBusiness — most specific wins) for the flagship:
   `name: "Dough Boss Revesby"`, `servesCuisine: ["Lebanese","Pizza"]`, `priceRange: "$"`,
   `address` (12/25 Selems Parade, Revesby NSW 2212, AU), `telephone: "+61297742286"`,
   `email`, `geo: {latitude: -33.9515, longitude: 151.0150}` (verify against Maps),
   `openingHoursSpecification: [{dayOfWeek: Mon–Sun, opens: "06:30", closes: "14:30"}]`,
   `hasMenu` → node 4, `acceptsReservations: false`, `image`, `url`.
2. **`Bakery`** — Bankstown: 462 Chapel Rd, Bankstown NSW 2200, `+61287646783`,
   Mon–Fri 07:00–14:00, geo ≈ -33.9199, 151.0344.
3. **`Bakery`** — Roselands: Shop MM03, Roselands Dr, Roselands NSW 2196, `+61466353133`,
   daily 08:00–15:00, geo ≈ -33.9346, 151.0730.
   Link all three with a parent **`Organization`** node (`name: "Dough Boss"`,
   `logo`, `sameAs: [Uber Eats listing URL]`) via `parentOrganization`.
4. **`Menu`** with six `hasMenuSection` (Manoush, Pizza, Pies, Wraps, Desserts, Drinks), each
   section containing `hasMenuItem: MenuItem[]` with `name`, `description`,
   `offers: {"@type":"Offer","price":"4.50","priceCurrency":"AUD"}`, and
   `suitableForDiet: "https://schema.org/HalalDiet"` (+ `VegetarianDiet`/`VeganDiet` for V/VG
   tags). Prices must match the visible menu exactly — generate this block from the same data
   used in the DOM, do not hand-maintain two copies.

Validate with Google's Rich Results Test before merging.

### 3c. Open Graph / Twitter completeness (index.html; analogous for franchise.html)

Existing: `og:title`, `og:description`, `og:image`. Add:

```html
<meta property="og:type" content="website">
<meta property="og:url" content="https://edagher92-coder.github.io/DOUGHBOSSV2/">
<meta property="og:site_name" content="Dough Boss">
<meta property="og:locale" content="en_AU">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Dough Boss — stone-baked manoush and pizza">
<meta name="twitter:card" content="summary_large_image">
```

Replace the `og:image` (currently `logo.png`) with a purpose-built 1200×630 jpg
(`assets/og-card.jpg`, banner photo + wordmark, ≤ 150 KB) — logos render poorly as link cards.

### 3d. `sitemap.xml` + `robots.txt` (new files in `demo/`, deployed by `pages.yml`)

`robots.txt`:

```
User-agent: *
Allow: /
Disallow: /owner.html
Disallow: /staff.html
Disallow: /backend.html
Sitemap: https://edagher92-coder.github.io/DOUGHBOSSV2/sitemap.xml
```

`sitemap.xml`: `index.html` (priority 1.0), `franchise.html` (0.6), `privacy.html`/`terms.html`/
`licensing.html` (0.3), with `<lastmod>` set from git in the Pages workflow. Note: the SPA views
(`#menu`, `#catering`, `#locations`, `#offers`) are fragments and won't be indexed separately —
acceptable for the demo; the WP site gets real URLs per section.

## 4. Analytics — recommendation: **Plausible** (self-serve cloud, or self-host later)

Reasoning for a small bakery: no cookie banner needed (cookieless, no PII, GDPR/Australian
Privacy Act friendly — matters given the demo now makes zero third-party calls), one `<script>`
tag (~1 KB vs GA4's ~90 KB gtag chain, protecting the performance budget), and a dashboard an
owner can actually read. GA4 is free but is overkill, needs consent tooling, and its event model
is hostile to non-analysts. Umami is the equal privacy choice but requires self-hosting from day
one; Plausible's ~$9/mo tier removes that burden. Revisit GA4 only if paid Google Ads
attribution becomes a requirement.

Snippet (all public pages, `defer`): `<script defer data-domain="doughboss.com.au" src="https://plausible.io/js/script.js"></script>`

### Event list (custom events via `plausible('EventName', {props})`)

| Event | Fired from | Props |
|---|---|---|
| `add_to_cart` | `menu-order.js` add handler | `item`, `category`, `price` |
| `begin_checkout` | cart → checkout transition | `cart_total`, `item_count` |
| `purchase_simulated` | demo checkout completion | `total`, `payment_method` |
| `catering_enquiry` | catering form submit | `guests_band` |
| `view_location` | Maps link click | `shop` (revesby/bankstown/roselands) |
| `ubereats_out` | Uber Eats outbound click | — |

No PII in props (no names/emails/phone). On the demo, `purchase_simulated` is explicitly named
to avoid polluting future real revenue data.

## 5. Lighthouse targets (mobile, throttled — all four ≥ 95)

| Category | Now (est.) | Target | What specifically moves it |
|---|---|---|---|
| Performance | ~85–90 | ≥ 95 | Font self-host (kills 3rd-party render chain, §1); webp + right-sized LCP hero with preload/fetchpriority (§2); menu thumbs at 2x-only 144px; keeps zero layout shift via existing width/height attrs |
| Accessibility | ~92 | ≥ 95 | Already strong (skip link, aria labels); fix any contrast on `.mist`/`.mn-cat-sub` over photos, ensure `<picture>` conversion preserves alt text, label the mobile nav toggle |
| Best Practices | ~92 | ≥ 95 | No third-party origins left (§1, §4 Plausible is the sole, deferred exception); correct image aspect ratios from srcset; keep CSP `_headers` (needs Cloudflare proxy on GitHub Pages) |
| SEO | ~80 | ≥ 95 | Unique titles/descriptions (§3a), canonical links, robots.txt + sitemap (§3d), noindex on consoles, valid JSON-LD (§3b), descriptive link text on "Open in Maps" links |

Verify in CI: add a `lighthouse-ci` job to `.github/workflows/pages.yml` with
`assert: {performance: 0.95, accessibility: 0.95, best-practices: 0.95, seo: 0.95}` against the
built demo, warn-only for the first two weeks, then blocking.

## 6. Demo-only vs. carries to WordPress

**Demo-only:** sitemap/robots at the `github.io` URL and `<link rel="canonical">` values (regenerate
for `doughboss.com.au`); `purchase_simulated` naming; hash-fragment view caveat in §3d;
Lighthouse CI wiring in `pages.yml`; `_headers` Cloudflare workaround.

**Carries to WP deploy:** the font files + @font-face blocks (enqueue via the theme, same
preloads — never reinstall a Google Fonts plugin); the image pipeline (WP generates srcset
natively — set `webp` as the default sub-size format, register a 144px thumb size, and port the
`<picture>` pattern into menu templates); all JSON-LD (emit from PHP using live
`doughboss_item` CPT prices so schema can never drift from the real menu — single source of
truth, per the money-path rules); OG tags (via theme `wp_head` or SEO plugin, same 1200×630
card); robots/sitemap (WP core sitemap + noindex on admin/staff routes); Plausible snippet and
the full event list, with `purchase_simulated` renamed to `purchase` fired server-side after
Stripe confirmation, and the same Lighthouse ≥95 gates applied to the WP theme before launch.
