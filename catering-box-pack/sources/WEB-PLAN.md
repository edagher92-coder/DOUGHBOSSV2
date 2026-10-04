# Catering box brand pack onto doughboss.com.au: web implementation plan

Prepared 2026-10-03 for Elie. Read-only investigation. Nothing was committed, pushed, deployed or installed. Scratch work (live HTML captures, test encodes, test crops) is under `.../pack/web-plan/live/` and `.../pack/web-plan/test/`.

**Conventions.** OBSERVED = I read it in a file or fetched it from the live site on 2026-10-03. INFERRED = my reasoning. `B:` = `/tmp/wp-src/baseline-2.41.0` (the 2.41.0 source branch), `C:` = `/tmp/wp-src/candidate-2.43.2`. `docs/..` under B/C is that worktree's docs. `web/docs/..` is `/home/user/DOUGHBOSSV2/web/docs/..`. `pack/..` is `/tmp/claude-0/-home-user/0ca99cc8-9bba-52a6-ab64-b7eba9ef239b/scratchpad/pack/..`.

---

## 0. The answer in one page

**Recommendation: ship in two phases, and keep the AI food off the live server entirely until real photos exist.**

**Phase 1 (fastest honest route, no theme or core edit):** two small zips uploaded in wp-admin (Plugins, Add New, Upload):

1. `doughboss-growth-box` (code, about 40 KB): shortcodes for the short, long and repeating forms; a manifest-driven image renderer that refuses to print anything marked as AI food; a settings screen; every switch off on install.
2. `doughboss-growth-media` (assets, about 0.95 MB, well under the 2 MB cap): only the SAFE NOW files (closed-box concept render, seal macro, flat artwork, liner pattern, OG card) plus `manifest.json`.

What the visitor gets: a new unlisted long-form page (proposed `/catering/box/`, created as a draft), an optional slim band under the home and catering heroes, and an optional site-wide "Feed the whole table." strip with the liner pattern. Every concept image carries a visible "Concept preview" chip. The page is `noindex` until real photos replace the concept set.

**Phase 2 (when real box and real bakes are photographed):** upload a new `doughboss-growth-media` zip with real photos; flip each manifest slot from `concept` to `real`. The chip and `noindex` disappear per slot. No code release is needed. Any in-place home panel swap, hero swap or above-footer band needs a theme release (section 4.6).

**Not recommended:** putting W11 (AI food) on the home hero, shipping HOLD images "hidden" in a zip (they are world-readable by URL), or adding the box to the unreleased `doughboss-growth` 0.1.0 companion (it drags in the consent, waitlist and legal-review gates that have nothing to do with this job).

**Five things only Elie can decide (they block publishing, not building):** section 7, items B1 to B5.

---

## 1. Current live structure (OBSERVED 2026-10-03)

I fetched with a browser User-Agent. The default curl User-Agent gets HTTP 406 from the host's ModSecurity (same as `web/docs/wp/00-architecture-extend-wordpress.md:365` row 11 and `web/docs/marketing/02-seo-external.md:108`). Use a browser UA for any automated check.

### 1.1 Stack and versions

| Item | Value | Evidence |
| --- | --- | --- |
| Core plugin | DoughBoss 2.41.0 | live asset URLs `?ver=2.41.0` (`live/home.html`) |
| Theme | `doughboss-final` 1.4.0 | live `style.css` header; 29,011 B (`web/docs/wp/01-storefront-map.md:42`) |
| PHP | 8.2.33 | `x-powered-by` header |
| Security headers | `nosniff`, `X-Frame-Options: SAMEORIGIN` | live response headers |
| Live is not B | live theme/hero CSS/JS are older than B; live `/` has no `<link rel="preload">` while B emits one (`B:includes/class-doughboss-shortcodes.php:88-104`) | `web/docs/wp/01-storefront-map.md:31-52`, confirmed: zero `preload` links in `live/home.html` |
| Cache headers on plugin images | none (`cache-control`/`expires` absent on `zaatar-cheese.jpg`) | `curl -I` |
| Extra code route already used | WPCode snippets #533 to #537 | `web/docs/wp/01-storefront-map.md:50`; `web/ops/live-quick-fixes/README.md:3-5` |

### 1.2 Pages (from `/wp-sitemap-posts-page-1.xml` and `...doughboss_cat_pkg-1.xml`)

- Pages: `/`, `/menu/`, `/locations/`, `/about-us/`, `/catering/`, `/wholesale/`, `/franchising/`, `/terms-conditions/`, `/privacy-policy/`, `/kitchen/`, `/order/`, `/track-order/`, `/vouchers/`, `/student-vouchers/`.
- **`/catering/` has no child pages today.** The planned `/catering/corporate/` and others do not exist (companion 0.1.0 is not installed: `doughboss-growth/docs/HANDOFF-CODEX.md:9`, `doughboss-growth/docs/RELEASE-0.1.0.md:3`).
- Four catering package posts are public and sitemapped: `/catering-package/morning-tea-2-dozen-minis/`, `office-platter-3-dozen-minis/`, `function-spread-5-dozen-minis/`, `fried-sambousek-2-dozen/`. Each renders only a date and the title (thin page; title "Morning Tea — 2 Dozen Minis"). The public REST `GET /wp-json/doughboss/v1/catering/packages` returns those names, prices (46, 72, 124, 56), `lead_days: 2` and an `includes` string containing "$" amounts and "mini".

### 1.3 Home `/` (template: front-page.php, even though body classes name stale templates `homepage`/`contact`; INFERRED harmless, the HTML matches `B:themes/doughboss-final/front-page.php`)

| Block | Markup | Image | Notes |
| --- | --- | --- | --- |
| Hero | `section.db-manoush-hero--home` rendered by `[doughboss_manoush_hero variant="home" ...]` (`B:front-page.php:8`) | **CSS `background-image` inline style** on `.db-mh-backdrop`: `plugins/doughboss/public/images/doughboss-feast-real-v1.jpg`, **1080x864 JPEG, 329,439 B** | Not an `<img>`, no preload, so it is discoverable only after the stylesheet (LCP risk, `web/docs/wp/01-storefront-map.md:415`). Copy: "Fresh from the oven." plus kicker, proof chips, two buttons, scroll-parallax and a pause button. Core's own CSS header says "Authentic DoughBoss photographic hero. No generated food layers." |
| Story | `.dbf-story-media` | `menu/real-v1/sujuk-deluxe.jpg` 550x440, 38,911 B | plain `<img>`, lazy |
| Food cards | `.dbf-food-grid` | `zaatar-cheese.jpg` 55,057 B; `sujuk-deluxe.jpg`; `haloumi-pie.jpg` 53,412 B (all 550x440) | plain `<img>` with width/height, lazy |
| Catering panel | `.dbf-catering-panel` (`B:front-page.php:63-71`) | feast photo again, 1080x864, `alt="A real Dough Boss catering spread"` | text: "Catering, made fresh / Feed the whole table. / Mini manoush, pizzas, pies..." |
| OG | `og:image` = `plugins/doughboss/public/images/doughboss-social-card.jpg`, 1200x630, 149,607 B | | `photo-audit.md` rates it HIGH-priority to replace (looks AI or stock, does not match the real product) |

Weights (OBSERVED, gzip transfer): home HTML 15.3 KB, theme CSS 10.4 KB, `doughboss.css` 16.9 KB, hero CSS 2.5 KB, `doughboss.js` 34.5 KB, `theme.js` 1.2 KB. Images above and below the fold on home: about 516 KB (feast 329 KB, three food cards 148 KB, story 39 KB).

### 1.4 Catering `/catering/` (template: page-catering.php)

- **There is no catering-page hero of its own.** It re-uses the same `[doughboss_manoush_hero variant="catering"]` with the same default `background_image` = `doughboss-feast-real-v1.jpg` (`B:page-catering.php:2`; shortcode default at `B:includes/class-doughboss-shortcodes.php:77`). The photo is reused from the home hero (this is also item 3 of `web/docs/site/hero-decision.md:18-23`).
- Structure, in order: hero (kicker "Catering, made fresh", title "Feed the whole table.", two buttons) then `section.cream` "Made for sharing" with feast photo again (1080x864, lazy) then `#catering-enquiry` containing core's catering app: contact cards (email `catering@doughboss.com.au`, phone `0422 487 487`), a line saying "The online enquiry form will load here", three "How it works" steps (Tell us the occasion, Build the right mix, Confirm before we bake), and six FAQ `details`.
- `<title>` and OG title say "Mini Manoush & Pies Sydney"; step 2 body says "minis". These conflict with the no-"Minis" rule (see B4).
- No package cards render on this page today.

### 1.5 Inner pages (page.php)

`B:themes/doughboss-final/page.php:3-4`: a fixed `dbf-page-hero` (the zaatar-cheese photo, 900x720, `alt=""`, plus the page title in an `h1`) then `the_content()` inside `.dbf-wrap.dbf-prose`. OBSERVED the same on live `/privacy-policy/`. The live theme CSS caps `.dbf-prose` at 840 px (`live/theme.css`, `.dbf-prose`), so a full-bleed section needs a body-class override. The known black-strip bug on this hero has a tested fix, `web/ops/live-quick-fixes/01-hero-strip.css`.

### 1.6 Where images live today

All in the core plugin: `wp-content/plugins/doughboss/public/images/` (hero feast, social card, `menu/real-v1/*.jpg`, legacy `menu/*.webp`). The 2.41.0 build serves single JPEG/WebP files (no AVIF, no `srcset`); the AVIF/WebP `<picture>` pipeline is candidate-only (`C:includes/class-doughboss-images.php:17-58`; `web/docs/wp/01-storefront-map.md:415`).

### 1.7 The known facts, re-verified

| Fact | Result | Evidence |
| --- | --- | --- |
| `front-page.php` hard-codes sections, no hooks | CONFIRMED | `B:front-page.php:1-88` (no `do_action`; `grep do_action` over the theme returns nothing; only `apply_filters` at `B:functions.php:180,249,271`); `web/docs/wp/01-storefront-map.md:26` |
| `page.php` renders `the_content()` | CONFIRMED | `B:page.php:4`; live `/privacy-policy/` |
| Core zip over 2 MB | CONFIRMED for 2.43.x (4,091,908 B); 2.43.0 asset was 2,578,194 B; the 2 MB cap is "observed" | `C:docs/VISUAL-PREVIEW-20260908.md:116,118,233-239` |
| Companion zip small | CONFIRMED, 270,365 B (51 files), budget 1,000,000 B | `doughboss-growth/dist/doughboss-growth-0.1.0.zip`; `doughboss-growth/scripts/budgets.php:23`; `doughboss-growth/docs/RELEASE-0.1.0.md:41` |
| `doughboss-growth-media` planned | **STALE.** Dropped on 2026-10-02 with the 3D hero: "the separate `doughboss-growth-media` plugin ... is dropped" | `web/docs/site/hero-decision.md:8`; `web/docs/wp/05-work-breakdown.md:3`; `doughboss-growth/docs/RELEASE-0.1.0.md:8`. No such directory exists in the repo. Reviving the name for this new purpose is cheap but needs Elie's nod. |
| `DISALLOW_FILE_EDIT` set | CONFIRMED (blocked a theme draft with HTTP 403) | `C:docs/VISUAL-PREVIEW-20260908.md:136`. It does not by itself block Plugins, Add New, Upload. Whether `DISALLOW_FILE_MODS` is also set is **untested** (`web/docs/wp/03-staff-dev-gaps.md:23`, R8 at `:393`). |
| Host Crazy Domains, 2 MB browser cap | CONFIRMED in docs | `doughboss-growth/docs/RELEASE-0.1.0.md:41` |

---

## 2. The pack: honesty classification of every image

Facts that drive the classification (all OBSERVED in the pack): the box does not exist (`pack/PACK-BRIEF.md:57`); every master is a concept and the food is an AI stand-in (`pack/sections/photography.md:3`); status says internal only, all photos labelled "concept", real bakes replace AI food "before publication" (`pack/sections/status.md:3,36-40`); concept renders "must never be posted, used in ads or uploaded to Google Business Profile as if they were the product" (`pack/sections/marketing.md:106`, Gate 3). Website rule: concept photos are not published as the product; bakes shown must be real menu items (`pack/PACK-BRIEF.md:79-81`). Under the Australian Consumer Law the test is overall impression (ss 18 and 29, `pack/sections/marketing.md:296`). I looked at every image; descriptions below are what is in the pixels.

Two definitions used below:

- **SAFE NOW** = may go on the live site in Phase 1, with the framing stated, once Elie ticks the two framing approvals in B1 and B2. It shows no AI food and cannot be read as "this is what you will receive today".
- **HOLD** = must not be on the live server (not even hidden) until a real photograph replaces it. A file in a plugin folder is public by URL even if no page links it.

| Asset | What it actually is | Class | Justification |
| --- | --- | --- | --- |
| `flats/closed-art.png` (1529x1345) | Flat vector artwork of lid and front panel: "FEED THE WHOLE TABLE.", wordmark, shop names, blank seal | **SAFE NOW** | Unambiguously artwork, not a photo; only Tier A copy plus verified shop names. Label "Artwork. The box is in development." |
| `flats/seal.png` (599x599) | Round kraft label, blank FOR / DATE / tick list / BOX __ OF __ | **SAFE NOW** | Artwork. The tick list names cheese, za'atar, meat, spinach, which are live menu bake names, not a pack-size or product claim. |
| `flats/inside-lid.png` (1516x1142) | Inside-lid artwork | **SAFE NOW with one fix** | Carries "FRESH FROM THE OVEN." and "OVEN-BAKED · BAKED TO ORDER", which `pack/sections/marketing.md:154` calls Tier B (each needs Elie to confirm it holds for every catering bake). Ship it only after B3 is ticked; otherwise omit. |
| `flats/liner.png` / `liner.pdf` | Repeating ember "DOUGH BOSS." pattern on paper | **SAFE NOW** | Decorative brand device, no product depiction. The wordmark is the live site's own (`pack/sections/brand-identity.md:29`); the trade-mark question is open (status gate 3) but that blocks volume print, not an on-site pattern. |
| W1 closed box 4:5 (2560x3200) | AI blank plate with the exact vector artwork composited; steel bench, flour. **No food.** Seal shows sample handwriting ("Friday lunch", "9/10", ticks, "BOX 1 OF 3") | **SAFE NOW (conditional)** | Box only, no food. Visible text is Tier A or verified (FEED THE WHOLE TABLE., Revesby, Bankstown, Roselands, wordmark). Must carry the "Concept preview" chip and caption because the box does not exist. Sample handwriting must be captioned as sample (B2). |
| W4 seal macro 4:5 (1792x2240) | AI macro of the seal on the box corner, handwriting, ink in fibre | **SAFE NOW (conditional, lowest priority)** | No food. It is the most photographic and so the easiest to mistake for a real sample. Long-form page only, never on cards, social or OG. First to drop if Elie wants a stricter line. "Do not enlarge beyond 100 %" (`pack/sections/photography.md:13`): cap display at 640 CSS px. |
| OG card for the new story page only | W1 crop 1.91:1 with the chip baked into the image (tested crop: `test/w1-og.jpg`, 1200x628, JPEG q72 about 89 KB) | **SAFE NOW (conditional)** | A link preview is seen without the page's caption, so the label must be in the pixels. |
| Site-wide / `/catering/` / `/` OG card | n/a | **HOLD** (leave the current card) | A concept render as the site's face in every share is a product claim. (The current card has its own authenticity problem, `web/docs/site/photo-audit.md` HIGH item 3, out of scope here.) |
| W2 stack of three 3:2 (2048x1360) | Closed stack with an **AI-generated bakery background (loaves, shelves, chalkboard)**; seals "BOX 1/2/3 OF 3" | **HOLD** | AI food and an invented shop setting (the pack's own direction says "no invented shop settings", `pack/sections/brand-identity.md:73`); three boxes implies an order size, but "no public piece says '12 per box' or '2 boxes'" until the fit test (`pack/sections/marketing.md:56`). |
| W3 open box 4:5 (2560x3200) | AI food, 12 bakes on liner; lid prints "FRESH FROM THE OVEN." (Tier B) | **HOLD** | AI food (status gate 2). |
| W11 catering hero 16:9 (3840x2160) | AI food in the open box, a closed stack behind | **HOLD** | AI food; the owner's 2026-10-02 hero ruling is "the live site keeps its real-photography hero... no generated-art hero" (`web/docs/site/hero-decision.md:3,18-23`); core 2.34.0 deliberately removed generated food from the homepage (`readme.txt:152-153`). Also the pack says the hero's "headline and button never mention boxes or catering" (`pack/sections/photography.md:26`), so the concept hero would show the product without saying what it is. |
| W7 to W10 bake tiles 1:1 | AI single bakes (cheese, za'atar, meat, spinach) on liner paper | **HOLD** | AI food. Interim: text-only tiles (names are live menu names). |
| W5 overhead stack | Internal reference only | **NEVER publish** | `pack/sections/photography.md:14`: "Never published full frame". |
| W6 counter at the shop | Mood only, dome oven that is not a real Dough Boss shop | **NEVER publish** | `pack/SHOTLIST-WEB.md:9` (stale file, but this row is still the safe reading). |
| 9:16 hero reel (Seedance, 8 s, silent) | AI-generated video of the real hero photo blowing apart | **HOLD** | AI food motion; hero-decision rules out a generated-art hero; the 4:3 website draft was judged "not ready for the site" (`web/docs/site/hero-reel-brief.md`). The loop slot ships empty. |
| Email | All images | **HOLD** | Emails are forwarded and clients strip captions; marketing Gate 3 requires real photos. Text, wordmark and the liner pattern only. |

**Gaps in the pack that I could not close:** there is no 9:16 phone hero master (W12 was never made, `pack/SHOTLIST-WEB.md:12`), and the pack's own brief mentions OG/social 1.91:1 crops that do not exist as files (I made one test crop). Both are Phase 2 items on real photos.

---

## 3. Placement map

Copy lines are only those in `pack/sections/brand-identity.md:14-25` (verified, Tier A or live-site) or the live site's own Tier A words (`pack/sections/marketing.md:153`). Anything I wrote is marked DRAFT and renders only after Elie ticks it (section 4.5). No prices, phone numbers, counts, lead times, dietary or "halal" claims anywhere. The word "Minis" is never printed by any component.

| # | Surface | Master and crop | Copy (verified only) | Class and note |
| --- | --- | --- | --- | --- |
| 1 | **Home hero** | W11 16:9, `object-position 70% 35%`; phone needs a W12 that does not exist | keep live copy ("Fresh from the oven." etc.); nothing about boxes | **HOLD.** Keep core's real feast photo. Mechanism for later: `shortcode_atts_doughboss_manoush_hero` filter (no theme edit), section 4.6. |
| 2 | **Home catering band** (new, under the hero) | W1 cropped 5:4 (top 20 % of height, full width, tested `test/w1-5x4.jpg`): 640 and 828 wide, about 25 to 38 KB WebP | "FEED THE WHOLE TABLE." (Tier A); "Office runs, footy nights and functions."; buttons "Plan catering" to `/catering/`, "See the box concept" to `/catering/box/` | **SAFE NOW (conditional)**, off by default. Chip "Concept preview" on the image. Injected through `do_shortcode_tag` on the hero shortcode (precedent: `doughboss-growth/includes/waitlist/class-doughboss-growth-coming-soon.php:65,387-405`). Text-only variant if B1 is not granted. |
| 2b | Home catering panel replaced in place (`B:front-page.php:63-71`) | real photo later | existing | **HOLD** (theme edit). Today's panel is a real photo; replacing it with a concept render would be a downgrade. |
| 3 | **Catering page hero** | W11 / real box photo later | live copy | **HOLD.** Today's reuse of the home photo stays. Phase 2 gives it its own real hero (hero-decision item 3). |
| 3b | **Catering page box strip** (new, under the hero) | same as row 2, `surface="catering"` | same | **SAFE NOW (conditional)**, off by default. Same injection point (variant `catering`). |
| 4 | **Long-form story page** `/catering/box/` (draft) | see 3.1 | see 3.1 | **SAFE NOW (conditional)**; `noindex` while any slot is `concept` |
| 5 | **Package cards** | W2 (HOLD) | package names | **HOLD** for photos. Text cards with names only (Morning Tea, Office Platter, Function Spread) and an enquiry link are fine. Do not show "dozen" counts, box counts or prices (marketing Gate; prices on site are "INDICATIVE and stay on the site"). Card titles that contain "Minis" must not be printed. |
| 5b | **Item tiles** cheese, za'atar, meat, spinach | W7 to W10 1:1 at 480/640, about 15 to 27 KB WebP | the four names | **HOLD** for photos. Text tiles with the liner pattern now. Slot flips to image when the manifest holds a `real` photo. |
| 6a | **Site-wide catering strip** (repeating) | liner pattern as CSS background, no photo | "FEED THE WHOLE TABLE." + "Plan catering" to `/catering/` | **SAFE NOW** (text and pattern only). Lowest risk, no concept imagery. |
| 6b | **Liner-pattern divider** | `liner` tile 945x473 px (about 10 KB PNG-8/WebP) | none | **SAFE NOW** decorative. |
| 6c | **Seal-style badge** | CSS circle, kraft fill, ember ring | "Plan catering" | **SAFE NOW** decorative. Never put "FRESH FROM THE OVEN." or a count on it. |
| 6d | **Looping hero video slot** | none shipped | none | **HOLD** content; component ships inert (section 3.3). |
| 7 | **OG / social** | W1 1.91:1 with baked chip | page title | story page only, **SAFE NOW (conditional)**. Instagram, GBP, ads: **HOLD** per marketing Gate 3. |
| 8 | **Email** | none | wordmark, text | **HOLD** images. |

### 3.1 Long-form story page, section by section

Page: child of `/catering/`, slug `box` (proposal, [CONFIRM] B3), created as a **draft** by an admin button, body = one shortcode. It uses the theme's own page hero (zaatar real photo + the page title in `h1`, `B:page.php:3`), which keeps the page's LCP on a real, already-cached 55 KB photo and avoids hiding an image that would still download. Apply live quick fix 1 first (hero strip).

| Section | Asset | Copy | Class |
| --- | --- | --- | --- |
| Intro | `closed-art` artwork, 960 and 1280 wide WebP/AVIF (18 / 24 KB WebP) | "FEED THE WHOLE TABLE." H2; "Office runs, footy nights and functions."; chip "Artwork. The box is in development." | SAFE NOW |
| How it arrives (closed) | W1 4:5, 640/828/1280 | DRAFT: "Concept preview of the Dough Boss catering box." Nothing about size, count, delivery, timing. | SAFE NOW (conditional) |
| The seal | `seal` flat 300/600 WebP, plus W4 640/828 | DRAFT: "A hand-written label on every box: who it is for, the date and the box number." (design intent; render only after approval) | seal flat SAFE NOW; W4 conditional |
| Open it up | `inside-lid` artwork, `liner` pattern band | DRAFT: "Open it up." No photo of bakes. Placeholder strip: "Photographs of the real bakes are coming." (owner to approve; teaser rule 1 uses "coming") | SAFE NOW; W3 stays HOLD |
| Order sizes | text cards only | package names; "Tell us your headcount and we will help plan the spread." | SAFE NOW (no counts, no prices) |
| How to order | live three steps: "Tell us the occasion", "Build the right mix", "Confirm before we bake" with the live bodies for steps 1 and 3 only | link to `/catering/#catering-enquiry` (anchor exists, `live/catering.html`); `catering@doughboss.com.au` | SAFE NOW. **Do not copy step 2's live body**, it contains "minis". No phone (claim `catering-phone-line` is unconfirmed, `doughboss-growth/content/claims.json`). |
| Footer note | none | "Allergen information available on request." (FSANZ-safe line, `pack/sections/brand-identity.md:24`) | SAFE NOW |

### 3.2 Definitions and the component list

**Short form** = a self-contained unit that works in a grid, a band or a feed, with one image, one line and one link. Components:

| Shortcode | Attributes | Renders |
| --- | --- | --- |
| `[doughboss_growth_box_card slot="closed\|seal" ratio="4x5\|5x4\|1x1"]` | slot, ratio, `href` | image card with chip; image omitted (text card) if the slot is missing, HOLD or AI food |
| `[doughboss_growth_box_band surface="home\|catering"]` | surface | the slim two-column band; also auto-injected under the hero when its switch is on |
| `[doughboss_growth_bake_tile name="cheese\|zaatar\|meat\|spinach"]` | name | text tile now; photo tile when a `real` slot exists |
| `[doughboss_growth_box_badge]` | none | seal-style badge, CSS only |

**Long form** = one story page, six sections that each omit themselves if their asset or approved copy is missing:

| Shortcode | Renders |
| --- | --- |
| `[doughboss_growth_box_story]` | sections in 3.1; no attributes beyond `sections="intro,closed,seal,open,sizes,order"` for staff to drop one |

**Repeating form** = site-wide, low-weight, reusable bands and patterns:

| Component | Mechanism |
| --- | --- |
| `[doughboss_growth_box_strip]` and an automatic site-wide strip | printed at `wp_footer` (`B:footer.php:42`) with about 1 KB inline CSS, then moved above `.dbf-footer` by a 3-line ES5 snippet; with JS off it stays below the footer (acceptable). No extra request, no layout shift (below the fold). |
| `.dbgr-liner` CSS class and `[doughboss_growth_box_divider]` | repeating liner tile as `background-image` on a 56 px band |
| `[doughboss_growth_box_loop]` (video slot) | section 3.3 |

### 3.3 Looping hero video slot (ships inert)

`<video muted loop playsinline preload="none" poster="...">` with an AV1/WebM source and an MP4 fallback. Behaviour (all ES5, about 2 KB, loaded only on a page that holds the slot): start only when the slot is in the viewport (IntersectionObserver); never start under `prefers-reduced-motion: reduce`, `Save-Data: on` or `effectiveType` of `slow-2g`, `2g`, `3g`; a visible pause button (WCAG 2.2.2, motion over 5 s) mirroring core's (`public/js/doughboss-manoush-hero.js:44-55`, cited in `web/docs/wp/00-architecture-extend-wordpress.md:109`); the poster is the LCP image and is a real photograph or the manifest slot stays empty. Budget: 720p at most, 6 to 8 s, at most 900 KB (INFERRED target). Ships with no media. The only candidate today is the AI reel, which is HOLD.

---

## 4. Delivery mechanism

### 4.1 Options weighed

| Option | Fits 2 MB cap | Needs theme edit | Speed | Risk | Verdict |
| --- | --- | --- | --- | --- | --- |
| **A. Two small plugins: code `doughboss-growth-box` + assets `doughboss-growth-media`** | yes (about 40 KB and about 0.95 MB) | no, for everything in Phase 1 | fastest | low: ships dark, no DB tables, no REST, no forms, independent rollback | **Recommended** |
| B. New module inside `doughboss-growth` 0.2.0 plus the media plugin | yes (270 KB + code) | no | slower | The companion is an unreleased RC with 11 frozen flags (`doughboss-growth/includes/class-doughboss-growth-settings.php:38`), CI and a legal review still pending (`doughboss-growth/docs/RELEASE-0.1.0.md`); it also activates always-on waitlist duties. Coupling couples the schedules. | Fallback if Elie wants one plugin; prefixes below are chosen so A can be merged into B later |
| C. Theme release (1.4.0 to 1.7.x) | theme zip about 31 KB | yes | medium | The repo theme (B) is **ahead** of live; live `style.css` is 29,011 B and B's is 32,143 B (`web/docs/wp/01-storefront-map.md:42`). A replacement built from B would change things beyond this job. The live theme files would have to be copied first. | Phase 2 only, section 4.6 |
| D. No zip: WPCode snippets (CSS and HTML) plus Media Library uploads | n/a (images ≤ 2 MB each) | no | fast | Proven path on this site (`web/ops/live-quick-fixes/README.md`). Weaker: no manifest to enforce the concept chip, WordPress makes extra image sizes, content lives in the database, harder rollback. AVIF upload needs WordPress 6.5 or later ([CONFIRM] live WP version, not exposed in the page). | **Fallback if Upload Plugin is blocked** (`DISALLOW_FILE_MODS` unknown) |
| E. Add the images to the core plugin | no (core zip over cap) | no | blocked | | Rejected |

Why two plugins and not one: (1) HOLD images must never be on the server, and replacing concept photos with real ones should not need a code review; (2) a media-only zip is inert and trivially rolled back; (3) the code plugin can be reviewed in minutes.

### 4.2 Files to add

```
doughboss-growth-box/                         (code, target at most 60 KB zip)
  doughboss-growth-box.php                    header; constants DBGRBOX_*; kill switch DBGRBOX_DISABLE; PHP 7.4 syntax
  uninstall.php                               removes only option doughboss_growth_box
  readme.txt
  includes/class-dbgrbox-manifest.php         locates media plugin, validates manifest, returns slot or null
  includes/class-dbgrbox-render.php           <picture> builder, caption chip, noindex logic
  includes/class-dbgrbox-shortcodes.php       the shortcodes in 3.2
  includes/class-dbgrbox-inject.php           do_shortcode_tag band, wp_footer strip, wp_robots, wp_head og, sitemap exclusion
  includes/class-dbgrbox-admin.php            settings page, "Create draft page" admin-post, copy approvals
  content/copy.json                           every public line, with tier and approved flag
  public/css/dbgr-box.css                     about 7 KB raw (target at most 2.5 KB gzip)
  public/js/dbgr-box.js                       ES5, strip relocation and video slot, about 2 KB, enqueued only when needed
doughboss-growth-media/                       (assets, target at most 1.2 MB zip)
  doughboss-growth-media.php                  header only
  assets/box/manifest.json
  assets/box/*.avif *.webp *.png *.jpg
```

Naming: constants and classes use `DBGRBOX_` / `DoughBoss_Growth_Box_*` so they cannot collide with the companion's `DOUGHBOSS_GROWTH_*` constants if both are ever installed; shortcodes keep the companion's `doughboss_growth_*` family and CSS the `dbgr-` prefix (the theme owns `dbf-`, core owns `db-`, `web/docs/wp/00-architecture-extend-wordpress.md` section 9 item 7).

### 4.3 How the honesty rules are enforced in code (so a later mistake cannot publish AI food)

`manifest.json` slot shape:

```json
{ "version": 1, "slots": {
  "closed": { "status": "concept", "ai_food": false, "alt": "Concept render of the Dough Boss catering box, closed",
              "w": 2560, "h": 3200, "variants": { "avif": [[640,"w1-640.avif"],[828,"w1-828.avif"],[1280,"w1-1280.avif"]],
                                                   "webp": [[640,"w1-640.webp"],[828,"w1-828.webp"],[1280,"w1-1280.webp"]] } } } }
```

Rules in the renderer: a slot with `ai_food: true` is never rendered unless `status` is `real`; `status: concept` always prints the chip and caption; a page holding any `concept` slot sends `noindex` (`wp_robots` filter) and is excluded from the core sitemap (`wp_sitemaps_posts_query_args`); a missing slot or missing media plugin makes the section a text card (graceful, no broken image); dimensions are checked against the files (the same idea as core's `DoughBoss_Images::picture`, `C:includes/class-doughboss-images.php:27-35`).

### 4.4 Markup, `srcset`, AVIF/WebP, lazy-loading, enqueue

Below-the-fold image (everything except a hero):

```html
<figure class="dbgr-fig dbgr-fig--4x5">
  <picture>
    <source type="image/avif" srcset="/.../w1-640.avif 640w, /.../w1-828.avif 828w, /.../w1-1280.avif 1280w" sizes="(max-width:720px) 92vw, 460px">
    <source type="image/webp" srcset="/.../w1-640.webp 640w, /.../w1-828.webp 828w, /.../w1-1280.webp 1280w" sizes="(max-width:720px) 92vw, 460px">
    <img src="/.../w1-828.webp" width="828" height="1035" alt="Concept render of the Dough Boss catering box, closed" loading="lazy" decoding="async">
  </picture>
  <figcaption class="dbgr-chip">Concept preview. The box is in development.</figcaption>
</figure>
```

- **No JPEG fallback in the zip** (INFERRED trade-off): the `<img src>` is WebP, supported by every current browser; a JPEG third copy would add about 250 KB. If Elie wants a universal fallback, only the 828 JPEG of each slot is needed (about 100 KB total).
- `width`/`height` on every `<img>` plus `aspect-ratio` in CSS (no layout shift). The chip is positioned over the image (absolute), so it adds no height.
- `loading="lazy"` and `decoding="async"` on everything not in the first viewport.
- **CSS**: enqueued in the head only when the page holds a box shortcode, is the front page or `/catering/` with the band on, via `wp_enqueue_scripts` with `has_shortcode` (pattern: `doughboss-growth/includes/leads/class-doughboss-growth-party-sizer.php:94-96`). The site-wide strip uses about 1 KB of inline CSS printed with the strip, so there is no extra stylesheet request on other pages.
- **JS**: none on pages without a strip or video slot. Version strings are the plugin version; filenames in the media plugin carry a content hash, because live plugin images send no cache headers (OBSERVED) and `?ver=` does not identify builds (`web/docs/wp/01-storefront-map.md:52`).
- Page cache: [CONFIRM] whether Crazy Domains or a plugin full-page-caches. Output is static, so it is safe either way; purge after enabling.

### 4.5 Settings screen and copy approval

Settings, per component, all **off** on install: Story page; Home band; Catering band; Site strip; Video slot. Plus: a "Create draft page" button (admin-post with nonce and capability, refuses if the slug exists, stores the id in option `doughboss_growth_box_page_id`); a status table of every slot (`concept`, `real`, `missing`, `blocked: ai_food`); and a list of every line in `content/copy.json` with its tier. Tier A lines are pre-approved. DRAFT and Tier B lines render nothing until a named person ticks "approved" (stored with date). Deactivating the code plugin moves the created page to draft so no raw shortcode text is ever shown (the companion's pattern, `web/docs/wp/00-architecture-extend-wordpress.md:234`).

### 4.6 What needs a theme edit (and what does not)

| Wanted | Without a theme edit | Needs a theme edit |
| --- | --- | --- |
| Long-form story page | yes (`page.php:3-4`, shortcode in body) | no |
| Band under home and catering heroes | yes (`do_shortcode_tag` on the hero shortcode, `B:front-page.php:8`, `B:page-catering.php:2`) | no |
| Site-wide strip | yes, at `wp_footer` (`B:footer.php:42`), relocated by JS | proper placement above the footer needs a hook |
| Swap the home or catering hero image | yes: filter `shortcode_atts_doughboss_manoush_hero` sets `background_image` (the attribute exists, `B:includes/class-doughboss-shortcodes.php:77`; the third `shortcode_atts` argument at `:70-85` makes the filter fire). Pair with a `wp_head` preload of the same URL. | no |
| LCP-grade hero as an `<img fetchpriority="high">` with AVIF/WebP | fragile (string-replace on core markup) | yes, or core 2.43.x, which already does it (`web/docs/wp/01-storefront-map.md:415`) but is over the upload cap |
| Replace the home catering panel in place (`B:front-page.php:63-71`) | no | yes |
| Above-the-fold launch bar, header, nav | n/a | out of scope |

Proposed theme release (Phase 2, optional, about 31 KB): add `do_action( 'doughboss_final_after_hero' )` after `B:front-page.php:11`, `do_action( 'doughboss_final_before_footer' )` before `<footer>` in `footer.php`, and a `do_action( 'doughboss_final_catering_media' )` slot at `B:front-page.php:70`. **Pre-condition:** download the live theme files first and patch those; do not upload the repo theme.

### 4.7 LCP preload for a future real hero (spec only; the hero is HOLD)

When a real hero photo exists, in the same plugin: set `background_image` through the filter above to the WebP desktop file, add `@media (max-width:767px)` override with a portrait crop (needs a real 9:16 master), and print in `wp_head` priority 1:

```html
<link rel="preload" as="image" href=".../hero-1920.webp" type="image/webp" fetchpriority="high" media="(min-width:768px)">
<link rel="preload" as="image" href=".../hero-828.webp" type="image/webp" fetchpriority="high" media="(max-width:767px)">
```

Measured sizes for a 16:9 hero (my encodes of W11, a stand-in for any dark hero): 1920 wide WebP 112 KB or AVIF 101 KB; 1280 wide 59 / 52 KB; 828 wide 29 / 25 KB; 2560 wide 173 / 159 KB. Under the pack's 150 KB hero cap at 1920. The inline-style `background-image` on the element would beat a stylesheet, so the filter (not CSS) must set the URL.

---

## 5. Performance budget

All sizes below are from my own encodes (Pillow libwebp q75 method 6; libavif q55 speed 6; `test/enc.json`) or from the live fetches. The pack's own measurements agree within a few percent (`pack/sections/photography.md:25`: W11 115 KB at 1920, W1 80 KB, W3 123 KB).

### 5.1 Per-image (Phase 1 SAFE NOW set)

| File | Width | AVIF | WebP |
| --- | --- | --- | --- |
| W1 4:5 closed box | 640 / 828 / 1280 | 28 / 45 / 96 KB | 30 / 48 / 102 KB |
| W1 5:4 band crop | 640 / 828 | 21 / 34 KB | 25 / 38 KB |
| W4 4:5 seal macro | 640 / 828 | 45 / 71 KB | 55 / 88 KB |
| `closed-art` artwork | 960 / 1280 | 13 / 17 KB | 18 / 24 KB |
| `seal` flat | 300 / 600 | n/a | 9 / 18 KB |
| `inside-lid` artwork | 640 / 960 | n/a | 7 / 11 KB |
| liner tile 945x473 | n/a | n/a | PNG-8 or WebP-lossless about 10 KB |
| OG card 1200x628 | n/a | n/a | JPEG q72 about 89 KB |

Caps: any single image at most 150 KB; any tile at most 45 KB (the pack's cap); the W4 macro displayed at 640 CSS px or less.

**Phase 1 media zip total: about 0.95 MB**, which is under the 2 MB upload cap (INFERRED: AVIF/WebP/JPEG do not compress further in a zip). Add a CI-style check that fails above 1.9 MB, mirroring `doughboss-growth/scripts/budgets.php`.

### 5.2 Per page

| Page | Added to today | Target |
| --- | --- | --- |
| Story page `/catering/box/` | images about 170 to 200 KB (artwork 24, W1 48, W4 45, seal 9 to 18, inside-lid 11, liner 10); CSS about 2.5 KB gzip; no JS | images at most 250 KB, total transfer at most 450 KB including the theme hero photo (55 KB) and existing CSS/JS (about 80 KB gzip) |
| Home with band on | one lazy image 25 to 38 KB; CSS about 2.5 KB gzip | at most +45 KB, **LCP element unchanged** (the band is below the hero) |
| Any page with the strip on | about 1 KB inline CSS, 0.5 KB JS inline | at most +2 KB, no image |

### 5.3 Targets

- **LCP**: at most 2.5 s at p75 on mobile (Google "good"); internal lab target at most 2.0 s on a throttled mobile profile. Phase 1 does not move the LCP element on any existing page; on the story page the LCP element is the theme's cached real photo (`B:page.php:3`, INFERRED) or the intro artwork at 18 KB. Today's home LCP is a 329 KB CSS-background JPEG with no preload (OBSERVED), which is the existing weakness and is not made worse.
- **CLS**: at most 0.1; zero shifts from this work (explicit `width`/`height`, `aspect-ratio`, overlay chip, strip below the fold).
- **INP**: at most 200 ms; the only script is the 2 KB strip/video file.
- **Reduced motion and data**: no new parallax or animation; video slot never plays under `prefers-reduced-motion: reduce` or `Save-Data`; visible pause control.
- **Verification**: before and after HTML of `/`, `/catering/`, `/menu/` must be byte-identical with all switches off (the inert-activation test in `doughboss-growth/docs/RELEASE-0.1.0.md`); Lighthouse mobile on the story page and home; a browser pass for chip visibility at 360, 768 and 1280 px.

---

## 6. Install path for Elie (plain words) and rollback

**Before you start (about 20 minutes):**
1. Take a full backup (files and database) and write down the time. This is your way back.
2. Decide the five questions in section 7 (B1 to B5). I will have the zips built to match.
3. Open the live site once and note how `/`, `/catering/` and `/menu/` look, so you can compare.

**Install (about 15 minutes, nothing public changes until step 5):**
1. WordPress admin, Plugins, Add New, **Upload Plugin**. Upload `doughboss-growth-media-0.1.0.zip` (under 2 MB). Click Install, then Activate. It does nothing by itself.
2. Upload `doughboss-growth-box-0.1.0.zip` the same way. Activate. Open **DoughBoss, Catering box**. Every switch is off.
3. Reload the home page, catering page and menu page. They must look exactly as before.
4. On the Catering box screen click **Create draft page**. Open the draft with **Preview**. Check: the artwork, the box picture, the "Concept preview" chip on every concept image, the three steps, the email link. Read every line; untick any DRAFT line you do not like.
5. When you are happy, tick **Story page** and **Publish** the page. It stays hidden from Google while it contains concept images.
6. Optional, one at a time, reloading after each: **Catering band**, **Home band**, **Site strip**.
7. If the site uses a page cache or CDN, purge it.

**If Upload Plugin is refused** (the host may block it): tell me; I switch to the no-zip route (WPCode snippet for the CSS, Media Library for the images, a normal page with a Custom HTML block). Slower and less tidy, but proven on this site.

**Rollback, strongest last:**
1. Untick a switch: that part disappears at once.
2. Deactivate **Catering box**: the story page goes back to draft automatically, so no stray text shows.
3. Deactivate or delete **doughboss-growth-media**: every box section shows as text only, no broken images.
4. Restore the backup.
Add `define( 'DBGRBOX_DISABLE', true );` to `wp-config.php` to stop the code plugin without deactivating it (same idea as `DOUGHBOSS_GROWTH_DISABLE`, `doughboss-growth/readme.txt`).

**Phase 2 swap (later):** upload a newer `doughboss-growth-media` over the old one ("Replace current with uploaded"); the slot flips to `real` and the chip disappears.

---

## 7. Risks, blockers and open decisions

### Blockers for publishing (Elie)

| # | Decision | Why it blocks |
| - | --- | --- |
| B1 | Is a labelled concept page "coming soon" allowed? `web/docs/site/teaser-direction.md:5-8` says public surfaces say only that something is coming, with no product, category or claim, and the pack says "no hint at the unannounced product" (`pack/sections/marketing.md:12`). A box page with a tick list of bakes is arguably a category hint. | Without an explicit exception, only the text-only strip (6a) and artwork are within the rule. |
| B2 | Approve the caption wording and that the sample handwriting ("Friday lunch", "9/10", ticks, "BOX 1 OF 3") may appear, captioned as sample. | A date on a concept render reads as a booking. |
| B3 | Slug and link placement (`/catering/box/`; is it linked from the home band or left unlisted?). Tier B lines on the artwork ("FRESH FROM THE OVEN.", "OVEN-BAKED · BAKED TO ORDER") each need your confirmation for every catering bake (`pack/sections/marketing.md:154`). | `inside-lid` is excluded until confirmed. |
| B4 | The live site already says "Mini Manoush", "minis" and "Minis" in `/catering/` title and OG, step 2, and four public package titles and slugs (`/catering-package/...-minis/`). Open question 6 in `web/docs/wp/00-architecture-extend-wordpress.md:375`. The new components avoid the word; the mismatch with the old copy stays until you decide. | Brand consistency and the no-"Minis" rule. Renaming package titles is a wp-admin content edit, not code. |
| B5 | Reviving the `doughboss-growth-media` name (dropped 2026-10-02) for a catering-box asset pack, and the standalone plugin route over the companion. | Plan hygiene. |

### Risks

1. **Legal entity and account ownership unresolved**: who owns the website, Square merchant, GTM/GA4/Ads/Meta and Business Profiles, and the sender name/ABN for emails (`web/docs/wp/00-architecture-extend-wordpress.md:355` risk 1 and `:375` item 1). It does not block this plan's build, but it blocks the email, waitlist and ad work around the box, and the pack's own gate 3 (wordmark and trade-mark ownership, `pack/sections/status.md:40`) blocks volume print.
2. **Box and bakes do not exist.** Phase 1 is only honest while the chip stays visible. The first real photo shoot should replace slots, not add to them (`pack/PACK-BRIEF.md:68-81`, `pack/sections/photography.md:29-33`).
3. **Hero ruling conflict**: the owner addition of 3 Oct ("use these as hero images on the home page") against the 2 Oct ruling ("keep the real-photography hero", no generated-art hero). I treated the 2 Oct ruling and ACL as binding until real photos exist. Please confirm.
4. **Upload Plugin untested on this host** (`DISALLOW_FILE_MODS` unknown, `web/docs/wp/03-staff-dev-gaps.md:23`). Fallback is option D.
5. **No staging site** (`web/docs/wp/03-staff-dev-gaps.md:393` R8). Testing is on a draft page plus the byte-identical check; a bug in the strip's CSS would show to all visitors, so the strip stays off until the band and page have passed.
6. **Live is not the repo.** Any theme work must start from the live files (section 4.6).
7. **Static assets have no cache headers** on live (OBSERVED), so repeat views refetch; hashed filenames help, a host-side fix is outside our control.
8. **Core form copy and package titles contain "Minis"** (see B4). The companion's landing code already refuses to print blocked text (`doughboss-growth/includes/landing/class-doughboss-growth-landing.php`, `core_form()`), a useful check to reuse in the box plugin.
9. **ModSecurity 406** to non-browser clients: automated QA must send a browser User-Agent; Search Console live test before any ad spend (`web/docs/wp/00-architecture-extend-wordpress.md:365` risk 11).
10. **Injected band collision**: if the companion's coming-soon ribbon (`class-doughboss-growth-coming-soon.php:65`) is ever enabled it also appends under the home hero. The box band yields to the ribbon (one strip under the hero at a time).
11. **Effort estimate (INFERRED, not evidence)**: code plugin about 1 day, asset encode and manifest about half a day, tests and the inert-activation check about half a day, plus your review time.

### What I did not do

No write to the live site; no install; no theme or plugin file edited. I did not look at the AVIF banding in W11 (HOLD), did not check live WordPress version, page cache or `DISALLOW_FILE_MODS`, and did not run Lighthouse (no network browser in this session). The stale `pack/SHOTLIST-WEB.md` was read for context only; `pack/sections/photography.md` is the reference.
