# 00 Architecture: extend the WordPress system (companion plugin + bounded core release)

Owner: Elie Dagher. Prepared 2026-10-02 by the architect slice. Read-only study; nothing was installed, deployed, committed or called. Work breakdown: `05-work-breakdown.md`.

**Conventions.** **B** = `/tmp/wp-src/baseline-2.41.0`, **C** = `/tmp/wp-src/candidate-2.43.2`. Paths without a prefix are C and relative to the worktree; `B:` marks baseline lines. `web/` paths are in `/home/user/DOUGHBOSSV2/web/`. **OBSERVED** = I read it in code/docs or ran it (my own runs are listed in section 8). **INFERRED** = my reasoning. Every number in this document is either cited or is a proposed engineering budget, labelled as such. No secrets or customer data appear here.

---

## 1. The decision in plain language (for Elie)

1. **Build a second, small plugin called `doughboss-growth` next to the existing DoughBoss plugin.** It adds the new things (consent and tracking, lead attribution, landing pages, the coming-soon section and VIP list, the hero upgrade, the timesheet check). It never edits the menu, prices, orders, catering enquiries or staff shifts. It only reads them, and keeps its own extra records in its own tables. Every feature ships switched **off**. Switching a feature off, or deactivating the plugin, puts the site back exactly as it is today.
2. **The Square itemised-orders work goes into the existing DoughBoss plugin itself, as release 2.44.0,** because it sits in the middle of taking money and creating orders, and splitting a payment across two plugins would create two writers of order data. It is built on top of the 2.43.2 candidate (PR #68) because that is where the hardened Square payment code lives (B still has the older protocol: B `includes/class-doughboss-square.php:290-296,328-358`). It is inert until you turn on one shop, in Square's sandbox first.
3. **Nothing goes live without you.** The plan needs about a dozen owner decisions (section 7); the most urgent are which legal entity owns the website, Square and ad accounts, and whether a generated 3D pizza is acceptable on the homepage when 2.34.0 deliberately replaced generated food art with real photography (`readme.txt:152-153`).

Why not the alternatives (detail in 3.1): putting the growth features inside the core plugin would collide with four unmerged PRs (#65 to #68 all edit core files), push a zip that is already about twice the host's 2 MB upload limit even larger, and make every marketing change a money-path release. A theme-only build cannot hold data, routes or consent records and the theme has no extension hooks.

---

## 2. What I verified before relying on the mappers (OBSERVED)

| Claim the design depends on | Evidence |
| --- | --- |
| Order, catering and payment hooks fire after commit and exist in both B and C | `doughboss_order_created` `includes/class-doughboss-order.php:470` (B and C); `doughboss_order_payment_status_changed` `:1188`; `doughboss_catering_enquiry_created` C `includes/class-doughboss-catering.php:248`, B `:237`; `doughboss_catering_status_changed` C `:326,562,674`; `doughboss_catering_payment` C `:673`, B `:662` |
| Core SEO stays silent unless a page contains a core shortcode or is opted in, and steps aside for Yoast/Rank Math/AIOSEO/SEOPress | `includes/class-doughboss-seo.php:51-56` (plugin detection), `:63-77` (`relevant_page()` + filter `doughboss_seo_relevant_page` at `:76`), hooks `document_title_parts` and `wp_head` priority 5 (`:41-43`); file identical in B and C (`cmp`) |
| JSON-LD ids used by core | `{home}/#organization`, `{home}/#website`, `{home}/#location-{slug}` (`includes/class-doughboss-seo.php:191,204,214`); shop `url` is the homepage |
| Theme opts only named slugs into core SEO/assets | `themes/doughboss-final/functions.php:141-164,177-197` (C); B `functions.php:78-108`. Child pages such as `/catering/corporate/` (slug `corporate`) are not matched |
| `page.php` renders `the_content()` (a shortcode page needs no theme edit) | C and B `themes/doughboss-final/page.php:4` |
| **The homepage hero is rendered through `do_shortcode()`**, so WordPress's `do_shortcode_tag` filter can wrap it without a theme or core edit | `themes/doughboss-final/front-page.php:7-10` calls `doughboss_final_shortcode_or_notice()`, which returns `do_shortcode( $shortcode )` (C `functions.php:386-392`; B `:310-316`). Same on `page-catering.php:2,4` |
| Marketing bridge emits `doughboss:marketing-event` for every tracked event and only calls `fbq` itself when `metaPixelId` is set | `public/js/doughboss-marketing.js:93-123`; config filter `doughboss_marketing_config` `includes/class-doughboss-assets.php:202-211` (C); bridge identical in B and C (`cmp`) |
| Core storefront fires a browser `purchase` after any successful `/checkout`, including unpaid pay-at-shop orders | `public/js/doughboss.js:1939-1946` (`trackCommerce('purchase', ...)`) |
| Catering form posts same-origin with cookies; success renders `.dbc-success` | `public/js/doughboss-catering.js:65,442-470`; selector present in B and C (6 occurrences each) |
| `/catering/enquiry` honeypot `hp` and limiter run before `DoughBoss_Catering::create` | `includes/class-doughboss-rest-controller.php:4733-4760` |
| `/payment-intent` responses carry the provider payment id as `payment_intent` | `includes/class-doughboss-rest-controller.php:2833-2850` (response arrays) |
| Square HTTP wrapper is private; payment body has no `order_id`; webhook drops non-`payment.*` events | `includes/class-doughboss-square.php:909` (`private static function request`), `:398-411`, `includes/class-doughboss-rest-controller.php:5545-5548` |
| Irreversible claim point (where an order must be created before money moves) | `includes/class-doughboss-square.php:382-396` |
| Kitchen consumers that a veto filter must reach | `DoughBoss_POSPal_Orders::on_order_created` `class-doughboss-pospal-orders.php:45`; `DoughBoss_Printer::next_unprinted` (private) `class-doughboss-printer.php:213`; `DoughBoss_Ntfy::on_order_created` `class-doughboss-ntfy.php:54`; `DoughBoss_Mercure::on_order_created` `class-doughboss-mercure.php:66` |
| Timeclock tables are reachable read-only through public static accessors; files identical in B and C | `DoughBoss_Timeclock::table()` `:29`, `::events_table()` `:35`, `::storage_ready()` `:52`, `::worked_minutes()` `:531`; `DoughBoss_Staff_Badge::breaks_table()` `:46`; forced close uses "now" (`class-doughboss-timeclock.php:386,394`) |
| Privacy tooling uses the core WordPress exporter/eraser filters, so a second plugin's exporters appear in the same Tools screens | `includes/class-doughboss-privacy.php:35-36` |
| Core CI lints/tests the **whole repository**, prunes any directory named `vendor`, and does not match `.mjs` | `.github/workflows/plugin-ci.yml:30-48` (C only; B has no `.github`) |
| Live sends `X-Content-Type-Options: nosniff` site-wide | `ops/hotfixes/533-security-headers.php` (wp_headers filter). INFERRED consequence: a module script served with a non-JavaScript MIME type is blocked, so no `.mjs` files on Crazy Domains |
| Settings defaults are off | `ordering_open` 0 `includes/class-doughboss-settings.php:81`; `payments_enabled` 0, `payment_gateway` `stripe` `:193-194`; Square keys `:233-244` |

Corrections to the mapper documents are listed in section 9.

---

## 3. Decisions with reasons and rejected alternatives

### 3.1 (i) Where the new code lives

**Decision: a separate companion plugin `doughboss-growth` for everything except the Square itemised-order money path, plus one bounded core release (2.44.0) for Square Orders, the kitchen veto filter and two one-line hygiene hooks.**

| Criterion | Companion plugin (chosen) | Modules inside core | Theme only |
| --- | --- | --- | --- |
| Merge risk with #65-#68 | None textual: new top-level directory (`03 §6.4`). Note: once #68 merges, core CI also lints and `node --test`s the companion files (`plugin-ci.yml:30-48`), so the companion must pass core CI too | High: #66-#68 edit `includes/`, `public/`, `tests/`, `doughboss.php`, `readme.txt` (`03 §6.4` point 3) | Medium: #68 changes the theme from 1.4.0 to 1.6.2 |
| Crazy Domains delivery | Code zip budget 1.0 MB, media pack 1.9 MB, both under the 2 MB browser upload cap (`docs/VISUAL-PREVIEW-20260908.md:239`) | Core zip is already about 4.09 MB and over the cap (`03` summary 8) | Small zip, but `DISALLOW_FILE_EDIT` blocks theme drafts (`docs/VISUAL-PREVIEW-20260908.md:136`) |
| Rollback | Per-feature flag; kill-switch constant; deactivate. Independent of core version | Requires a core downgrade, which re-runs the money path | Theme switch; loses all features |
| No competing writers | Reads core data; writes only `doughboss_growth_*` tables/options | Same process, easy to violate | Cannot store anything safely |
| Works on live 2.41.0 today | Yes, feature-detects C-only hooks | Only after 2.43.x install (on hold, `docs/REVIEW-20260908.md:160`) | Partly |

Rejected: **core modules** (merge and delivery risk, money-path release cadence for marketing changes); **theme only** (no tables, routes, privacy exporters or cron; theme has no `do_action` slots, `01 §8.5`); **separate repository** (acceptable, but adds a second CI/PR process; keep it in the DoughBoss repository under `doughboss-growth/` unless Elie prefers isolation).

**Why the Square Orders work is in core and not in the companion:** the order must be created between the attempt reaching `prepared` and the irreversible payment claim (`class-doughboss-square.php:382-396`); its id must be checked in `payment_matches()` (`:733`) and in `/checkout` verification (`rest-controller.php:4205-4363`); recovery must create the WordPress order from the snapshot. None of those points has a hook (`02 §11.2` "Not found"), and adding hooks that let a second plugin alter a payment body would itself be a second writer. Core is the only writer of orders, so core owns this.

### 3.2 (ii) Landing pages, metadata and the claims ledger

**Decision: real WordPress pages, created as drafts by an admin button, whose body is a single companion shortcode; copy comes from a version-controlled claims ledger; the companion owns head metadata only on its own pages.**

- **Pages, not a custom post type.** Hierarchical pages give the exact contract URLs with no rewrite rules: `/catering/corporate/`, `/catering/office-breakfast/`, `/catering/events/` are children of the existing `/catering/` page; `/locations/revesby/`, `/locations/bankstown/`, `/locations/roselands/` are children of the existing `/locations/` page. Child slugs are not matched by the theme's `is_page()` lists (section 2), so `page.php` renders them (`page.php:4`) and core SEO stays silent. They appear in the core sitemap automatically (LIVE sitemap is WordPress core, `01 §7.1`) and in Yoast/Rank Math if one is installed later. The admin button `Create landing pages` (admin-post `doughboss_growth_create_pages`) creates each page as **draft** with `post_content = [doughboss_growth_landing key="catering-corporate"]` and records the id in option `doughboss_growth_pages`. Publishing is a deliberate owner action in wp-admin.
- **Hub pages stay core/theme owned.** `/catering/` and `/locations/` keep their theme templates and core SEO (`page-catering.php`, `page-locations.php`, `functions.php:177-197`). Hub-to-spoke links go in the admin-editable primary menu (`wp_nav_menu` with fallback, `functions.php:368-384`, `01 §6.2`) and, after the 2.44.0/theme work, in the templates. The companion never prints metadata on a hub.
- **`/catering/minis` is not built.** Elie's later decision (`web/docs/site/teaser-direction.md`, 2026-10-02) says the word "Minis" must not appear on anything public, and the marketing route contract already omits it (`web/docs/marketing/02-seo-external.md:154`). The landing engine supports a `catering-minis` key in code but no page definition is shipped. See the contradiction in section 7, item 6.
- **Rejected:** a CPT (needs custom rewrites that collide with page hierarchy), theme templates (theme release, merge risk with #68), block patterns (free-text editing bypasses the ledger; staff could publish an unsourced claim).

**Metadata ownership on companion pages** (class `DoughBoss_Growth_Landing_SEO`):

| Output | Rule |
| --- | --- |
| `<title>` | `document_title_parts` filter at priority 30 (after theme's 20, `functions.php:306`), only on pages listed in `doughboss_growth_pages` and only when no dedicated SEO plugin is active (same constants as `seo.php:51-56`) |
| `meta description`, Open Graph, Twitter | `wp_head` priority 6 (core SEO is 5 and silent here). Image = core `public/images/doughboss-social-card.jpg` 1200x630 (`01 §5.1`) |
| Canonical | Not printed by the companion. WordPress core `rel_canonical` already prints the permalink for singular pages; printing another would duplicate it |
| Robots | Not printed (core `wp_robots` already prints one; core SEO's duplicate robots tag is a known defect, `01 §7.2`) |
| JSON-LD | One `@graph` per page: `BreadcrumbList`; on catering spokes a `Service` (`provider` = `{home}/#organization`, `areaServed` only from a confirmed claim) with `Offer`s built from **live core catering packages** (`GET /doughboss/v1/catering/packages` data via PHP, prices in AUD as stored, `01 §3.8`); on location pages a `["Bakery","Restaurant"]` node reusing core's `@id` `{home}/#location-{slug}` with `url` = the landing page, address, telephone and `openingHoursSpecification` read from `DoughBoss_Locations::get()` / `::weekly_hours()` (`class-doughboss-locations.php:51,247`). No `geo`, rating, price range, `sameAs` or `hasMenu` unless the ledger holds a confirmed claim (`web/docs/marketing/02-seo-external.md:122`). `FAQPage` only from confirmed FAQ claims |
| With Yoast/Rank Math/AIOSEO/SEOPress active | Companion prints **nothing** in the head. The admin screen shows each page's title/description for the operator to paste into that plugin, and a separate setting `seo_jsonld_with_seo_plugin` (default off) allows the JSON-LD graph only |

Recommended optional core follow-up (P2, not required): a filter around core's shop `url` so the homepage graph points shop nodes to their landing pages (`seo.php:214-216`).

**Claims ledger in PHP (class `DoughBoss_Growth_Ledger`).**

- Source of truth: `doughboss-growth/content/claims.json`, the same shape as `web/src/content/ledger.ts:15-33` (`id`, `text`, `confirmed`, `source { kind, ref, retrieved | confirmedOn }`, `note`), plus one PHP-only source kind `core-data` (a value read live from the core plugin at render time: an address, phone, hours row, catering package price). Changes arrive by pull request; an owner confirmation is recorded as `{ "kind": "owner-confirmed", "ref": "<where Elie confirmed>", "confirmedOn": "YYYY-MM-DD" }`.
- Render rule: `DoughBoss_Growth_Ledger::text( $id )` returns the text only when `confirmed` and `source` are present (port of `publishable()` / `claimText()`, `ledger.ts:38-46`); otherwise `null`, and the template **omits the whole block** (fail closed). Templates contain no free factual prose: only claim ids, core-data slots and neutral UI strings.
- Validation: port of `assertLedger()` (`ledger.ts:49-63`: kebab-case ids, no duplicates, no empty text, placeholder regex `/\[CONFIRM|TODO|TBC|lorem ipsum|xxx/i`, confirmed requires source, non-empty ref) runs in `php tests/run.php` and on plugin load in admin (an invalid ledger disables the landing and coming-soon features with an admin notice). An extra **public-copy lint** rejects, in any claim used on a public page and in admin-editable teaser text: the word "Minis" (case-insensitive, per `teaser-direction.md`), "halal", "vegan", "gluten", "nut-free", "certified", "best", "#1", and any digit, `$` or `%` unless the claim's source kind is `owner-confirmed` or `core-data`.
- Oracle: a fixture file generated from the TypeScript implementation (`web/src/content/ledger.ts`) with `tsx` from the existing `web/node_modules` (no install) is checked into the companion tests, and the PHP port must return identical results for every case.

### 3.3 (iii) Hero and coming-soon front end under the ES5 rule

**Decision: three tiers, chosen in the browser, with the existing real-photo hero always rendered first and never replaced.**

| Tier | What | Technology | Who writes it | Loads when |
| --- | --- | --- | --- | --- |
| 0 Poster | The existing photographic hero (`[doughboss_manoush_hero]`, C `includes/class-doughboss-shortcodes.php:69-117`) | unchanged core | core | always; it stays the LCP element |
| 1 Frames | Pre-rendered WebP frame sequence of the explosion, drawn into one `<canvas>` | hand-written **ES5** (`public/js/dbgr-hero-frames.js`) | companion | after `window.load` + idle, hero in viewport, not reduced-motion, not Save-Data, `effectiveType` not `slow-2g/2g/3g` |
| 2 WebGL | three.js scene loading the GLB | **prebuilt bundle** from TypeScript in `web/tools/wp-hero/`, built with the pinned esbuild in `web/node_modules` (0.28.2 OBSERVED) and three 0.186.1 (OBSERVED, `web/node_modules/three/package.json`) | generated, never hand-edited | tier-1 conditions **and** WebGL2, `hardwareConcurrency >= 4`, `deviceMemory >= 4` when exposed, viewport >= 768 px, frame-time guard passes |

Integration without touching core or theme: `add_filter( 'do_shortcode_tag', ..., 10, 4 )` appends a hidden stage `<div class="dbgr-hero-stage" data-dbgr-hero hidden>` (and a visible "Explode / Replay" button once a tier is ready) to the output of `doughboss_manoush_hero` when `variant="home"` and feature `hero_enhanced` is on. PHP also skips the stage entirely when the request carries `Save-Data: on`. The ES5 loader `public/js/dbgr-hero-loader.js` decides the tier.

**Motion rules.** Explosion is user-triggered (button) or scroll-linked to the hero's own scroll progress (no autonomous loop), so no extra pause control is required; a visible Replay/Stop button is provided anyway, mirroring core's pause button (`public/js/doughboss-manoush-hero.js:44-55`). `prefers-reduced-motion: reduce` (live listener, as core does at `doughboss-manoush-hero.js:62-69`) means tier 0 only. Rendering stops when the hero leaves the viewport (IntersectionObserver) or the tab is hidden, and the WebGL renderer is disposed after the scene completes. If the median of the first 60 frames exceeds 24 ms the loader disposes tier 2 and falls back to tier 1 (proposed threshold, to be tuned with Web Vitals data).

**Byte budgets (proposed, enforced by `scripts/validate-zip.php` and a CI step):**

| Item | Budget | Basis |
| --- | --- | --- |
| Tier 0 added bytes | 0 | unchanged core hero |
| `dbgr-hero-loader.js` + `dbgr-hero.css` | <= 6 KB + 4 KB raw | hand-written |
| Tier 1 frames | <= 24 frames per breakpoint; each <= 60 KB; sm (<= 767 px) total <= 600 KB; lg total <= 1.2 MB | proposed; `web/public/hero/frames/{sm,lg}` are **empty** today (OBSERVED), so these are targets |
| Tier 2 JS | <= 650 KB raw, <= 170 KB gzip | my probe: three r186 + `GLTFLoader` minified ESM = 615,676 B raw, 155,148 B gzip -9 (section 8); meshopt decoder must fit inside the remainder |
| Tier 2 GLB | <= 550 KB | current `web/public/hero/exploded-manoush.glb` = 515,376 B (OBSERVED), meshopt-compressed per `web/tools/blender/optimize_glb.mjs:17` |
| Companion code zip | <= 1.0 MB | host cap 2 MB |
| Media pack zip (`doughboss-growth-media`) | <= 1.9 MB | host cap 2 MB |

**Versioning and integrity.** Generated files are content-hashed (`public/vendor/hero-webgl.<sha8>.js`, `hero-scene.<sha8>.glb`, `frames/<bp>/<sha8>-NN.webp`), so caches bust regardless of WordPress version strings (version strings do not identify builds, `03 §3.6`). The build writes `hero-manifest.json` with `sha384` for each file; PHP localises it; the ES5 loader injects `<script type="module" integrity="sha384-..." crossorigin="anonymous">` and fetches the GLB with `fetch(url, { integrity: ... })`. The vendor file uses the `.js` extension (served as JavaScript, so it survives `nosniff`) and sits in `public/vendor/`, which core CI already prunes from `node --check` (`plugin-ci.yml:44`). CI rebuilds the bundle from the pinned sources and byte-compares it (reproducibility).

**Where the media lives.** GLB and frames ship in a second, asset-only plugin `doughboss-growth-media` (one inert PHP header file plus `assets/`), so each zip stays under 2 MB and media can be rolled back independently. WordPress's media library is not used (GLB is not an allowed upload type by default).

**ES5 policy.** All hand-written front-end JS in the companion is ES5 syntax, IIFE + `'use strict'` + `var`, DOM through `textContent` (core style, `03 §3.3`), checked by `acorn --ecma5` (available in `web/node_modules/.bin/acorn`, OBSERVED; CI installs it in the job only). **One exception needs Elie's approval:** the generated `public/vendor/hero-webgl.<hash>.js` (ES2018 module output). It is vendor-style generated code, never edited by hand, loaded only through feature-detected module injection (a browser that cannot run modules never requests it), excluded from the ES5 gate by path, and reproducible from TypeScript sources in `web/tools/wp-hero/`. This is the same posture as core loading Square's SDK from Square's CDN (`class-doughboss-assets.php:245-248`). If Elie declines, tier 2 is dropped and tiers 0-1 ship unchanged.

**Asset authenticity (blocking owner decision).** The GLB is procedural geometry (`web/tools/blender/build_exploded_manoush.py:1-12`) and `web/public/hero/ai/manoush-blowout-still.webp` is AI-generated and labelled "Not a photograph of any Dough Boss product ... Do not present it to customers" (`manoush-blowout-still.json`). Core 2.34.0 replaced "the generated floating-food homepage treatment" with real photography (`readme.txt:152-153`). Therefore: **the AI still is never shipped**; tier 1 frames should be rendered from real photographed layers (a shoot) or from the 3D scene only if Elie approves stylised art; the feature stays off until then. `web/docs/3d-assets.md` was **not found** (referenced by the JSON sidecar; looked in `web/docs/` and `web/docs/*/`).

**Coming-soon section (replaces the brief's "Coming soon: Minis").** Shortcode `[doughboss_growth_coming_soon]` on its own page (default slug `coming-soon`, page.php) plus an optional slim ribbon appended after the home hero by the same `do_shortcode_tag` hook. Content follows `teaser-direction.md`: neutral headline/body (admin-editable, linted), **no product name, price, size, pack, dietary or launch claim**. "3D cards" are CSS 3D tilt cards (ES5 pointer handler, transform only, reduced-motion = flat), whose slots render only confirmed ledger claims; with no confirmed claims they do not render. Dietary badges render only from a confirmed ledger claim (default none). The **party-pack sizer** is built but not shown on the teaser (it is a pack claim); it is offered as a catering tool on `/catering/*` (feature `party_sizer`, off) and computes nothing itself: it calls core `GET /doughboss/v1/catering/quote` for real packages (`rest-controller.php` route at `:971`), and any pieces-per-guest guidance (`web/src/lib/packs.ts:21`) is shown only if Elie confirms it as a ledger claim.

### 3.4 (iv) Waitlist and lead storage

**VIP waitlist** (feature `waitlist`, off). Companion-owned; nothing in core.

- Table `{prefix}doughboss_growth_waitlist`: `id` PK; `email` varchar(191) lowercased; `email_hash` char(64) UNIQUE (sha256 of normalised email); `first_name` varchar(80) NULL; `mobile_e164` varchar(18) NULL; `store_pref` bigint unsigned NULL (core `doughboss_locations.id`, validated with `DoughBoss_Locations::is_valid()`, `class-doughboss-locations.php:65`); `interests_json` text NULL (empty unless Elie supplies options); `consent_marketing` tinyint(1); `consent_text_version` varchar(20); `consent_text_hash` char(64); `consent_at_utc` datetime; `consent_source_path` varchar(200) (path only); `status` (`pending`, `confirmed`, `unsubscribed`); `confirm_token_hash` char(64) NULL; `confirmed_at_utc`, `unsubscribed_at_utc`, `created_at`, `updated_at`. No IP address or user agent is stored.
- Table `{prefix}doughboss_growth_suppression`: `email_hash` PK, `reason` (`unsubscribed`, `erased`), `created_at`. Kept so an unsubscribe is honoured after erasure (owner/legal decision, section 7).
- REST (namespace `doughboss-growth/v1`): `GET /form-token` (public, `Cache-Control: no-store`, returns an HMAC token over issue time with `wp_salt('nonce')`, valid 30 s to 24 h; replaces `wp_rest` nonces so a cached page cannot break submission and doubling as a minimum-fill-time check); `POST /waitlist` (public; token + honeypot field `website` + rate limit); `POST /waitlist/confirm` and `POST /waitlist/unsubscribe` (token from the email link, posted by a button on the page so mail scanners that prefetch GET links cannot confirm or unsubscribe).
- Validation: `is_email()`, length caps, mobile normalised to E.164 AU or rejected, consent checkbox must be explicitly `1` (unticked by default), consent text version must equal the current version.
- Rate limiting (fail **closed**, unlike core's fail-open limiter at `rest-controller.php:1479-1507`): table `{prefix}doughboss_growth_rate` (`bucket_key` PK, `window_start`, `hits`) with an atomic conditional `UPDATE ... SET hits = hits + 1` then insert; buckets: per hashed IP (daily-rotating salt, never stored raw) 5/hour; per `email_hash` 3/day; global circuit breaker 300/day. Any storage error returns HTTP 503 and nothing is saved. (Chosen over `GET_LOCK` because the local runtime is SQLite and `GET_LOCK` was not exercised there, `04 §9`.)
- Spam Act: double opt-in **on** by default (recommended in `web/docs/marketing/research/compliance-au.md:131`); the confirmation email and every later message name the sender entity and give a working unsubscribe honoured within the ACMA timeframe (`compliance-au.md:114,272`). The sender entity name is unknown (task 18); the feature cannot be enabled until it is set (setting `sender_legal_name` required by the enable check).
- Retention (defaults are the data-minimising direction): `pending` rows purged after `retention_pending_days` = 30; `unsubscribed` rows reduced to a suppression hash after 30 days; `confirmed` rows have **no automatic deletion until Elie sets** `retention_confirmed_months` (banner shown while unset). Daily cron `doughboss_growth_retention_purge`.
- Export and erasure: the companion registers its own exporter and eraser through `wp_privacy_personal_data_exporters` / `wp_privacy_personal_data_erasers`, the same WordPress Tools screens core's privacy class uses (`class-doughboss-privacy.php:35-36`). Core's privacy class is not modified. CSV export for staff: admin-post `doughboss_growth_export_waitlist` (`manage_doughboss`, nonce, spreadsheet-injection neutralised as core does, `timeclock.php:483-489`).
- Notification webhook (off): on `confirmed`, enqueue an outbox row; cron posts `{ "event": "waitlist.confirmed", "id", "store_pref", "confirmed_at" }` (**no email or name**) to `notify_webhook_url`, signed `X-DoughBoss-Growth-Signature: sha256=<hex HMAC>` with env-first secret `DOUGHBOSS_GROWTH_WEBHOOK_SECRET`.

**Leads and attribution on the existing catering enquiry and on orders.** Core stays the only writer of `doughboss_catering_enquiries` and `doughboss_orders`; the companion adds side rows.

- Capture: ES5 `dbgr-attribution.js` reads `utm_source|medium|campaign|term|content`, `gclid`, `gbraid`, `wbraid`, `fbclid`, `msclkid`, referrer **host** and landing **path** on first landing (port of `web/src/lib/attribution-schema.ts`: trim, no control characters, 120-char cap, path without query) and writes first-party cookie `dbgr_attr` (90 days, `SameSite=Lax`, `Secure`, <= 1.5 KB). UTM and referrer need `measurement` consent; click ids need `advertising` consent; without consent nothing is written.
- Catering enquiries: on `doughboss_catering_enquiry_created( $id, $row )` (C `:248`, B `:237`), PHP reads and re-sanitises `$_COOKIE['dbgr_attr']` (the core catering fetch is same-origin with credentials, `doughboss-catering.js:65`) and inserts `{prefix}doughboss_growth_lead_meta` (`enquiry_id` UNIQUE, `segment`, `company_name`, `landing_key`, `attribution_json`, `consent_marketing`, `consent_text_version`, `consent_at_utc`, `lead_score` NULL, `created_at`). The companion's own corporate form (3.4 below) posts to core `/doughboss/v1/catering/enquiry` with extra `dbgr_*` fields; the companion stashes them on `rest_request_before_callbacks` for that route only and writes them in the same hook. If core rejects the enquiry (honeypot, limit, validation) the hook never fires and nothing is stored.
- Orders: on `rest_post_dispatch` for `/doughboss/v1/payment-intent` with HTTP 200, store attribution keyed by the returned `payment_intent` id in `{prefix}doughboss_growth_attribution` (`subject_type` `payment_ref`); on `doughboss_order_created` join by the order's `payment_intent_id` (`DoughBoss_Order::get`, `class-doughboss-order.php:1004`) or fall back to the cookie, writing `subject_type` `order`. This also covers webhook-recovered Square orders (same payment id) without the `doughboss_checkout_snapshot_payload` core patch proposed in `02 §9`.
- Corporate lead form `[doughboss_growth_lead_form variant="corporate|office_breakfast|events"]`: ES5, fields mapped to core's (`customer_name`, `customer_email`, `customer_phone`, `package_id`, `guest_count`, `order_type`, `location_id`, `event_date`, `notes`, `hp`) plus `dbgr_company`, `dbgr_segment`, `dbgr_consent_marketing` (unticked), using core's `wp_rest` nonce. Success fires `generate_lead` with `form: "catering_enquiry"` (`web/src/lib/analytics/events.ts:37-44`).

### 3.5 (v) Analytics, consent and closed-loop conversions

- **Load order** (feature `gtm`, off): `wp_head` priority 0 prints an inline ES5 Consent Mode v2 default (`ad_storage`, `ad_user_data`, `ad_personalization`, `analytics_storage` = `denied`, `wait_for_update: 500`) and replays any stored choice from cookie `dbgr_consent`; priority 1 prints the GTM container snippet for setting `gtm_container_id`. GA4, Google Ads (conversion linker + conversion tags) and the Meta Pixel are configured **inside GTM only**, one loader per vendor.
- **Consent banner** (feature `consent_banner`, off; required before `gtm` can be enabled): companion-owned ES5 banner in `wp_footer` with three categories (necessary, measurement, advertising), "Accept", "Reject" and "Choose" with equal prominence, a persistent "Privacy choices" link, cookie `dbgr_consent` `{ v, m, a, ts }` for 180 days. On change it calls `gtag('consent','update', ...)` and dispatches core's `doughboss:consent` with `{ measurement, advertising, version }`, which the core bridge already listens for (`doughboss-marketing.js:138-140`). Shipped default is **deny until chosen** (fail closed). Australian law does not require prior opt-in for every analytics cookie, so Elie may choose a notice-and-opt-out default; it is a setting, not code (section 7).
- **Bridge to core events**: the companion sets `doughboss_marketing_config` to `enabled: true`, `metaPixelId: ''`, `tiktokPixelId: ''` so the core bridge keeps emitting `doughboss:marketing-event` but never calls `fbq`/`ttq` itself (avoids double firing, `doughboss-marketing.js:96-102`). `dbgr-datalayer.js` listens and pushes typed events to `dataLayer`. Mapping: core `view_item`, `add_to_cart`, `begin_checkout`, `generate_lead` pass through; **core `purchase` becomes `order_placed`** because the browser cannot prove payment and core fires it for unpaid orders too (`doughboss.js:1939`); `purchase` is server-side only (rule 4 of `events.ts:13-15`).
- **Taxonomy**: exactly `EVENT_NAMES` in `web/src/lib/analytics/events.ts:58-73` (`select_store`, `view_item`, `add_to_cart`, `remove_from_cart`, `begin_checkout`, `order_placed`, `generate_lead`, `quote_step`, `hero_explore`, `coming_soon_view`, `waitlist_submit`, `click_to_call`, `get_directions`, `cta_click`), with the same parameter names, no personal data, money in integer cents converted once at dispatch. One change, recorded here: `begin_checkout.payment_method` values become `"SQUARE" | "PAY_AT_SHOP"` (the TS union says `STRIPE | PAY_AT_PICKUP`, `events.ts:29-31`); the TS file is updated in the same work package so both stay identical. `events.ts` is exported to `content/events.json` and the ES5 dispatcher refuses unknown names.
- **Catering form success on the hub page**: core's form does not fire `generate_lead` (`02 §9`). Interim: `dbgr-datalayer.js` watches for `.dbc-success` inside the catering app (a contract test fails if the selector disappears from core). Permanent: core 2.44.0 dispatches `doughboss:catering-enquiry-created` `{ enquiry_number }` (one line after the success render, `doughboss-catering.js:466`).
- **Server-side conversions** (feature `server_conversions`, off): outbox table `{prefix}doughboss_growth_outbox` (`channel` `ga4|meta|webhook`, `event_name`, `event_id` with UNIQUE `(channel, event_id)`, `subject_type`, `subject_id`, `payload_json` without raw personal data, `status`, `attempts`, `next_attempt_at`, `last_error`), cron `doughboss_growth_outbox_dispatch` every 5 minutes, backoff copied from POSPal (60, 300, 1800, 1800, 1800 s; `class-doughboss-pospal-outbox.php:52-59`). Triggers: `doughboss_order_created` when `payment_status = 'paid'` (and `doughboss_order_payment_status_changed` to `paid`) emits `purchase` with `event_id = order:{order_number}`, value from the order total; `refunded` emits a GA4 `refund`; `doughboss_catering_enquiry_created` emits `generate_lead`; `doughboss_catering_status_changed` to `quoted`/`paid` feeds the offline export. GA4 Measurement Protocol (env-first `DOUGHBOSS_GROWTH_GA4_API_SECRET`) only if the subject's stored consent snapshot has `measurement`; Meta Conversions API (env-first `DOUGHBOSS_GROWTH_META_CAPI_TOKEN`) only if `advertising`; hashed email/phone are **off** (`send_hashed_identifiers` = 0) until the privacy policy covers it (`web/docs/marketing/03a-google-ads.md:286`). Google Ads: no Ads API in v1; admin-post `doughboss_growth_export_offline_conversions` produces the weekly won-deals CSV with `gclid`, conversion name, time and value from core enquiry totals (`web/marketing/google-ads/conversion-plan.md` stage 2).
- **Square closed loop**: website orders paid through Square are WordPress orders with `payment_method = 'square'` (`rest-controller.php:3780`), so the `purchase` trigger above covers them; 2.44.0 adds `doughboss_square_order_linked( $wp_order_id, $square_order_id )` for reporting joins. In-store Square POS sales have no web attribution and are reported as offline/unattributed (`web/docs/square/api-integration-notes.md:509-513`).

### 3.6 (vi) Square Orders API itemised integration in core 2.44.0

**Decision: extend the existing Web Payments SDK flow with the Orders API (mapper option A, `02 §6.2`).** The Square research recommends payment links for a Next.js build (`web/docs/square/api-integration-notes.md:343`), but in WordPress the hardened `square-v2` path, its 94 unit assertions and its integration suite already exist; Square documents CreateOrder + Web Payments SDK + CreatePayment as its own order-ahead flow (same doc `:343`, `[S31]`), and that path is exactly smoke test 18 (`:600`). Rejected: payment links (replace a tested path, redirect UX, no expiry field, sandbox cannot test redirects, `:314-319`); Square Online (a second menu/order writer).

**Classes (new files in `includes/`, required after `class-doughboss-square.php` at `includes/class-doughboss.php:86`):**

| Class | Responsibility |
| --- | --- |
| `DoughBoss_Square_Locations` | Table `{prefix}doughboss_square_locations` (`id`, `environment` test/live, `wp_location_id`, `square_location_id`, `square_merchant_id`, `currency`, `timezone`, `orders_enabled` default 0, `kitchen_owner` `wordpress`/`square` default `wordpress`, `status` `unverified`/`verified`, `verified_at`, `verified_by`; UNIQUE `(environment, wp_location_id)` and `(environment, square_location_id)`). `square_location_for( $wp_location_id )` falls back to `DoughBoss_Settings::square_location_id()` (`class-doughboss-settings.php:847`) only when no row exists and orders are off. Read-only verify via `GET /v2/locations/{id}` (reuses `test_connection()`, `class-doughboss-square.php:824`, which has no caller today) |
| `DoughBoss_Square_Orders` | Pure `build_order_body( $snapshot, $attempt, $location_row, $map )` (no I/O), `create_order()`, `retrieve_order()`, `complete_order()`, `cancel_order()`, `assert_order_matches()` |
| `DoughBoss_Square_Order_Links` | Table `{prefix}doughboss_square_orders` (`attempt_id` UNIQUE, `checkout_key` UNIQUE, `wp_order_id` UNIQUE NULL, `environment`, `square_location_id`, `square_order_id` UNIQUE, `square_order_version`, `square_payment_id` NULL, `state`, `fulfilment_state`, `kitchen_ticket_by` `square`/`wordpress`, `last_event_id`, timestamps). States `prepared -> order_created -> payment_dispatching -> paid -> wp_committed`, side states `cancelled`, `refunded`, `needs_review` |
| `DoughBoss_Square_Catalog_Map` (phase 2) | Table `{prefix}doughboss_square_catalog_map` exactly as `02 §6.4` (keyed `(environment, local_type, local_key)`, modifiers keyed per item to avoid the `style--flat` collision, `class-doughboss-menu-options.php:65-81`) |
| `DoughBoss_Square_Outbox` | Post-commit updates (ticket name with WordPress order number, order completion), shape copied from `DoughBoss_POSPal_Outbox` |
| `DoughBoss_Kitchen_Dispatch` | `allowed( $order_id, $channel )` used by the new filter below |

**Flow (changes to existing code, with touch points):**

1. `DoughBoss_Square::request()` gains a public wrapper `api( $method, $path, $body )` (keep the private method; `class-doughboss-square.php:909`), reusing headers, version, timeout and log redaction.
2. Inside `create_payment_intent()`, after the attempt is `prepared` and bound and **before** `claim_irreversible_creation` (`:382-386`): if the shop's mapping row has `orders_enabled = 1`, call `DoughBoss_Square_Orders::create_order()` with idempotency key `db-ord-` + sha256(attempt identity) (<= 192, `api-integration-notes.md:209`). Body: `location_id` from the mapping; `reference_id` = existing `db-attempt-{id}` (<= 40, `class-doughboss-square.php:233-235`); `source.name` `DoughBoss Web`; phase 1 **ad hoc** line items (name, quantity as string, `base_price_money` per unit) with ad hoc modifiers carrying their resolved price deltas; one order-scoped ad hoc tax `{ type: INCLUSIVE, percentage: <tax_rate> }` from settings (`class-doughboss-settings.php:74-75`); voucher as one `FIXED_AMOUNT` order discount; one `PICKUP` fulfilment, `schedule_type ASAP`, `prep_time_duration PT{prep_time_default}M` (`class-doughboss-activator.php:190`), recipient name, E.164 phone (normalise as `DoughBoss_SMS::normalize_phone`, `class-doughboss-sms.php:292`) and email, `note` from order notes (<= 500); metadata `{ dbo_ref }` only (no personal data, `api-integration-notes.md:214`). **Reject and cancel** the Square order unless `total_money.amount === attempt.amount_minor` and currency matches; this failure is `retry_safe` because no money moved.
3. Payment body adds `order_id` (`:398-411`); `payment_matches()` (`:733-748`) and `payment_payload()` require the returned `order_id` to equal the link row.
4. `retrieve_payment_intent()` (`:561-656`) and `/checkout` verification (`rest-controller.php:4205-4363`) additionally re-read the Square order: state, `total_money`, location and tender payment id.
5. After `/checkout` commits (`rest-controller.php:3826-3829`): link row `wp_committed`, `wp_order_id` set; outbox job updates `ticket_name` with the WordPress order number; `do_action( 'doughboss_square_order_linked', $order_id, $square_order_id )`.
6. Webhook (`rest-controller.php:5545-5548`): allow `order.updated`, `order.fulfillment.updated`, `refund.created`, `refund.updated` in addition to `payment.*`; de-duplicate through the existing `claim_event()` (`class-doughboss-payment-attempts.php:684-733`); always re-fetch the object; ignore events whose `version` is older than the stored one. Admin help text (`admin/class-doughboss-admin.php:3575`) lists the new subscriptions.
7. Status bridge (only for shops with `kitchen_owner = 'square'`): fulfilment `RESERVED` maps to `confirmed`, `PREPARED` to `ready` (walking through `preparing` with one `transition()` per allowed step so the forward-only graph at `class-doughboss-order.php:80-97` is respected), `COMPLETED` to `completed`, `CANCELED` to an operator review (never auto-cancel a paid order; `transition()` refuses that, `:743-897`). Event keys `square:{event_id}:{step}`, actor `square`. A polling backstop (SearchOrders by `updated_at`, `api-integration-notes.md:442`) runs hourly because KDS-originated webhook behaviour is unknown (`:354`). On WordPress `completed` for a Square-owned shop, the outbox closes the Square order and fulfilment in one UpdateOrder (`:243`).
8. Recovery: the orphan branch (`rest-controller.php:5566-5586`) creates the WordPress order from the snapshot, copying `recover_stripe_order()` (`:5304-5437`), sets the link row `kitchen_ticket_by = 'square'`, and the kitchen veto suppresses a second ticket. Unpaid Square orders older than 30 minutes (proposed TTL) are cancelled by a janitor cron.
9. Refunds (`class-doughboss-square.php:787-817`, admin `admin/class-doughboss-admin.php:1840-1873`): send a refund reason, read the refund status, mark `refunded` only on `COMPLETED` confirmed by `refund.updated` or a re-read; partial refunds stay out of the pilot.

**Duplicate dispatch versus POSPal and the printer.** New core filter `doughboss_kitchen_dispatch_allowed( bool $allowed, object $order, string $channel )`, channels `pospal`, `printer`, `ntfy`, `mercure`, consulted at the top of `DoughBoss_POSPal_Orders::on_order_created` (`class-doughboss-pospal-orders.php:45`), `DoughBoss_Ntfy::on_order_created` (`class-doughboss-ntfy.php:54`), `DoughBoss_Mercure::on_order_created` (`class-doughboss-mercure.php:66`) and inside `DoughBoss_Printer::next_unprinted` (`class-doughboss-printer.php:213`; a vetoed order advances the watermark with an `order_events` audit row so the queue cannot stall). Core's own callback returns false for `pospal` and `printer` when the order's shop has `kitchen_owner = 'square'`. Enabling `kitchen_owner = 'square'` is refused unless (a) that store's POSPal outbox has no `queued`/`retrying` rows, (b) the shop requires online payment (`online_payment_enabled = 1` and Square ready), because an unpaid order never reaches Square POS/KDS (`api-integration-notes.md:22,304`), and (c) no other shop is Square-owned while `square_orders_pilot` is on.

**Pilot gates (all must pass; every flag defaults off):**

| Gate | Check |
| --- | --- |
| G0 | 2.44.0 installed; `doughboss_db_version >= 1.24.0`; storage contract extended in `payment_storage_ready()` (`class-doughboss-activator.php:755-805`) |
| G1 | `payments_enabled = 1`, `payment_gateway = square`, `square_mode = test`, credentials env-first (`class-doughboss-settings.php:861-895`) |
| G2 | Exactly one `doughboss_square_locations` row `verified` for the pilot shop (owner-approved binding; never default every shop to Revesby, `docs/SQUARE-MIGRATION-20260908.md:22`) |
| G3 | `square_orders_enabled = 1` (new key in `doughboss_settings`, default 0) and that row's `orders_enabled = 1`; code refuses a second enabled row while `square_orders_pilot = 1` (default 1) |
| G4 | Sandbox acceptance checklist below all PASS with evidence |
| G5 (production) | `square_live_approved = 1` (`:244`) plus a live webhook key, plus new `square_orders_live_approved_by`/`_at` recorded by an administrator, plus a staffed real-device test (KDS visibility cannot be proved in sandbox, `api-integration-notes.md:32`) |

**Sandbox acceptance checklist** (synthetic customers only; from `docs/SQUARE-MIGRATION-20260908.md:50` and `web/docs/square/api-integration-notes.md:564-603`): success with exact items, modifiers, discount, tax and pickup details visible in Order Manager; decline; timeout/unknown outcome never re-posts; duplicate `/payment-intent` and duplicate webhook delivery; amount, currency and location mismatch quarantined; browser abandonment (unpaid order cancelled by janitor); order creation failure leaves no charge; webhook signature over the configured URL; refund reconciled only after `refund.updated`; idempotency replay of CreateOrder with same and changed body; INCLUSIVE GST total equals the plugin total to the cent for every fixture basket (`class-doughboss-cart.php:306-353`); `catalog_object_id` with explicit price (phase 2 only).

### 3.7 (vii) Timesheet reconciliation

Adopt the design in `03 §2` with these architecture decisions:

- Lives in the companion (feature `timesheet_recon`, off), read-only on both sides. Plugin side: direct reads through the public accessors and guards listed in `03 §2.7` (`DoughBoss_Timeclock::table()`, `::events_table()`, `::storage_ready()`, `DoughBoss_Staff_Badge::breaks_table()`), with a parity test against `DoughBoss_Timeclock::worked_minutes()` (`class-doughboss-timeclock.php:531`). Square side: `POST /v2/labor/timecards/search` and `SearchTeamMembers` with a **separate** read-only token, env-first `DOUGHBOSS_GROWTH_SQUARE_LABOUR_TOKEN` and `DOUGHBOSS_GROWTH_SQUARE_ENV`, scopes `TIMECARDS_READ`, `EMPLOYEES_READ` (`api-integration-notes.md:69-71,90`), `Square-Version` pinned in a constant (>= 2025-05-21 required for Timecards, `:384`; current 2026-09-16 OBSERVED by that slice, `:16`).
- Mapping: companion table `{prefix}doughboss_growth_recon_xref` (`kind` `employee`/`location`, `local_id`, `square_id`, `environment`, `confirmed_by`, `confirmed_at`, `status`). Email equality may **suggest** an employee pairing, never bind it. Location rows are needed because recon must run on live 2.41.0; once core 2.44.0's `doughboss_square_locations` exists, the companion treats core as authoritative, shows any disagreement, and marks that shop `UNMAPPED_LOCATION` until resolved (one source of truth wins).
- Data flow: daily WP-Cron `doughboss_growth_recon_daily` at `run_time_local` for the previous business day plus `lookback_days`, and a "Run now" admin-post `doughboss_growth_recon_run` -> read plugin shifts and breaks -> page Square timecards until the cursor is exhausted (limit 200, assert returned locations/window because invalid filters are silently ignored, `api-integration-notes.md:385`) -> normalise to UTC epoch minutes -> greedy overlap matching, `SPLIT` and `NO_OVERLAP_NEAR_MISS` -> mismatch codes (`03 §2.5`) -> `{prefix}doughboss_growth_recon_run` and `_recon_row` (key workday, shop, employee; content hash of both sources so a later edit reopens the row).
- Thresholds: option `doughboss_growth_recon` keys `start_tolerance_minutes`, `end_tolerance_minutes`, `break_tolerance_minutes`, `net_tolerance_minutes`, `open_alert_after_minutes`, `max_shift_minutes`, `near_miss_search_minutes`, `lookback_days`, `run_time_local`, per-shop `business_day_cutoff_local`; **no shipped values**: an unset tolerance yields `UNRATED_*` rows and a banner.
- Report: submenu under the core `doughboss` menu (as `class-doughboss-timeclock.php:58-67` does), per shop/day counts, row detail, CSV (ids and minutes only; names resolved at render). Any API error, missing token, unmapped item or incomplete pagination marks the run `FAILED` and keeps the previous result; never green by default.

### 3.8 (viii) Testing strategy

| Layer | Tooling (repo style) | Must cover |
| --- | --- | --- |
| PHP unit | `doughboss-growth/tests/run.php` + own `tests/bootstrap.php` (do not require core's Square/settings classes, `03 §3.9` rule 7), helpers copied from core (`db_test`, `assert_same`, `assert_true`; `tests/bootstrap.php:365,406`) | ledger port vs TS fixtures; public-copy lint; attribution sanitiser vs TS fixtures; consent cookie parser; form token; rate limiter (fake `$wpdb`); waitlist validation and state machine; head/JSON-LD builders (no claim without source); outbox payload builder (consent gating, no PII); recon matcher (all `03 §2.10` cases incl. Sydney DST gap/fold); hero manifest and budgets; feature-flag defaults all false |
| PHP compatibility | `php -l` on 7.4 and 8.2 in `growth-ci.yml`; local smoke with `WPL_PHP=7.4` | 7.4 syntax (`??` fine, no `match`/nullsafe/typed properties) |
| JS | `node --test` dependency-free `*.test.js` extracting functions from ES5 files with `vm.runInNewContext` (core pattern, `tests/storefront-helpers.test.js:9-30`); `acorn --ecma5` over every hand-written file; vendor bundle excluded by path and rebuilt-and-compared | dispatcher refuses unknown events; consent defaults; attribution cookie size; hero tier selection matrix (reduced motion, save-data, memory, cores, WebGL2) |
| Core 2.44.0 | `php tests/run.php` additions in the same style (`tests/test-square-orders.php`: pure builder, GST, modifiers, discounts, idempotency, mismatch) and `tests/integration/wordpress-mysql.php` extended with a `/v2/orders` fixture in its `pre_http_request` handler (`:147-190`), the two-process race (`tests/integration/square-race-worker.php`) extended to orders; add a MySQL integration job to CI before any money path ships (`02` finding 15) | everything in the sandbox checklist that can be faked locally |
| Database paths | Copy of the disposable-site harness with its four guards (`tests/integration/wordpress-mysql.php:16-28`) | limiter atomicity, waitlist uniqueness, outbox claim, recon reads, uninstall leaves core untouched |
| Browser | Local runtime `web/scripts/wp-local/` (Playground, PHP 8.2, SQLite, `04 §1`) with the companion mounted; Playwright from `web/node_modules`; run against **both** C and B plugin sources (`WPL_PLUGIN_SRC`) | **inert activation**: with all flags off, HTML of `/`, `/order/`, `/catering/`, `/locations/` is identical to the run without the companion (after masking nonces); consent banner blocks GTM and pixel requests until accepted (intercept `googletagmanager.com`, `facebook.com`); landing pages render only sourced claims and print exactly one title, description and JSON-LD block; hero tiers under emulated reduced motion, Save-Data and low memory; waitlist end to end with a captured `wp_mail`; axe checks |

### 3.9 (ix) Packaging, versions, flags, rollout, rollback, branches

- **Branches.** Companion: new branch from `claude/live-2.41.0-baseline` (76deb569) adding only `doughboss-growth/`, `doughboss-growth-media/` and `.github/workflows/growth-ci.yml`, PR into that same base (`03 §6.4`). Core 2.44.0: new branch from `codex/release-readiness-20260908` (1600a399, PR #68 head), PR after #68 merges (rebase if #66/#67 merge first, `03 §6.3`). TypeScript hero sources and fixture exporters live in `web/` on the current branch (`ccr-ba2d93d9-lbw916`); their **outputs** are copied into the companion tree by a build script, never edited there. Writable worktrees are created by the lead with Elie's approval; the read-only worktrees under `/tmp/wp-src/` are never written.
- **Versions.** Companion `0.1.0`; `DOUGHBOSS_GROWTH_VERSION`, header `Version:`, readme `Stable tag:` and first changelog heading must match (same four-way rule as core, `scripts/build-zip.php:38-60`); `DOUGHBOSS_GROWTH_DB_VERSION` `1.0.0` stored in `doughboss_growth_db_version`. Core `2.44.0`, `DOUGHBOSS_DB_VERSION` `1.24.0` with an `upgrade_to_1_24_0` migration step (`class-doughboss-migrations.php:63-87`).
- **Core gate.** The companion registers nothing but an admin notice unless `defined( 'DOUGHBOSS_VERSION' ) && version_compare( DOUGHBOSS_VERSION, '2.41.0', '>=' ) && class_exists( 'DoughBoss_Settings' )`; C-only hooks are feature-detected.
- **Feature flags** in option `doughboss_growth_settings['features']`, all `false`: `consent_banner`, `gtm`, `attribution`, `server_conversions`, `landing_pages`, `seo_head`, `lead_form`, `party_sizer`, `coming_soon`, `waitlist`, `hero_enhanced`, `timesheet_recon`. Dependencies are enforced on save (`gtm` needs `consent_banner`; `server_conversions` needs `attribution` and a configured destination; `waitlist` needs `sender_legal_name` and a privacy-policy URL). Filter `doughboss_growth_feature_enabled` can only turn a feature **off**. Constant `DOUGHBOSS_GROWTH_DISABLE` (wp-config) stops everything without deactivation.
- **Rollout order** (each a separate Elie approval): (1) install inert, verify zero public HTML change; (2) consent banner + GTM with no ad spend, attribution; (3) landing pages as drafts, review, publish, lead form; (4) server conversions after the privacy-policy update; (5) waitlist after entity name and privacy decisions; (6) hero after asset approval and the ES5 exception; (7) timesheet reconciliation after approval to read Square staff data; (8) core 2.44.0 Square pilot at one shop, sandbox then staffed production.
- **Rollback.** Flag off is immediate. Deactivation removes all hooks; the deactivation hook moves companion-created landing/coming-soon pages (ids in `doughboss_growth_pages`, content still exactly the companion shortcode) to **draft** so no page shows raw shortcode text. `uninstall.php` keeps all data unless `DOUGHBOSS_GROWTH_UNINSTALL_DELETE_DATA` is true, and never touches a core table, option, role or capability (lesson of `ops/README.md:117-131` and core `uninstall.php:20-51`). Keep the previous zip for re-upload. Core 2.44.0 rollback = re-install 2.43.x zip; new tables are left in place (additive), and `square_orders_enabled = 0` restores today's payment path.

### 3.10 Brief item (4): Square operations, and what is code versus configuration

| Area | Owner of the truth | WordPress build in this plan | Configuration/process (no code) |
| --- | --- | --- | --- |
| Kitchen | Per shop `kitchen_owner` (core 2.44.0) | veto filter, status bridge, board read-only for Square-owned shop (WP-12/WP-13) | KDS device routing toggle "View online, kiosk and delayed fulfilment orders" (`api-integration-notes.md:297`), stations, printer |
| Timesheets | Both systems, reconciled; neither decides pay | WP-11 | staff clock-in on Square POS at each shop; tolerances (section 7) |
| Adverts | GTM/GA4/Ads/Meta accounts (owner) | WP-03, WP-04, WP-08 | campaigns per `web/docs/marketing/03a-google-ads.md`, `03b-meta-ads.md` |
| Sales and planning | Square reports for in-store; core reports for web orders (UTC-day based, `02 §10.1`) | none in v1; a later read-only reporting module can join `SearchOrders` with core orders on Sydney business days | daily close, prep sheets and forecasting per `web/docs/square/operating-playbook.md` sections 2 and 5 |
| Promotions | Core vouchers stay the only web discount engine (`$5` ceiling, `class-doughboss-voucher.php:118`) | none | Square in-store discounts/loyalty per `operating-playbook.md` section 4; do not create a second web promo engine |

---

## 4. Interfaces (B): companion plugin `doughboss-growth`

### 4.1 File layout (owner package in brackets, see `05-work-breakdown.md`)

```
doughboss-growth/
  doughboss-growth.php                     [WP-01] header: Requires at least 6.0, Requires PHP 7.4, GPL-2.0-or-later, Text Domain doughboss-growth
  uninstall.php  readme.txt                [WP-01]
  includes/class-doughboss-growth.php                 [WP-01] bootstrap, core gate, module registry (requires a module file only if it exists)
  includes/class-doughboss-growth-settings.php        [WP-01] option, flags, env-first secrets, dependency checks
  includes/class-doughboss-growth-activator.php       [WP-01] dbDelta over each module's schema(), DB version, deactivation drafting
  includes/class-doughboss-growth-rate-limit.php      [WP-01] fail-closed limiter + table schema
  includes/class-doughboss-growth-http.php            [WP-01] outbound wp_remote_* wrapper, timeout, log redaction
  includes/class-doughboss-growth-outbox.php          [WP-01] shared outbox table + cron worker (channels register handlers)
  includes/ledger/class-doughboss-growth-ledger.php   [WP-02]
  includes/consent/class-doughboss-growth-consent.php, class-doughboss-growth-tags.php   [WP-03]
  includes/attribution/class-doughboss-growth-attribution.php                            [WP-04]
  includes/waitlist/class-doughboss-growth-waitlist.php, -waitlist-rest.php, -waitlist-privacy.php, -coming-soon.php   [WP-05]
  includes/landing/class-doughboss-growth-landing.php, -landing-seo.php, -landing-schema.php                          [WP-06]
  includes/leads/class-doughboss-growth-leads.php, -party-sizer.php                      [WP-07]
  includes/conversions/class-doughboss-growth-conversions.php, -ga4.php, -meta.php, -offline-export.php   [WP-08]
  includes/hero/class-doughboss-growth-hero.php      [WP-10]
  includes/recon/class-doughboss-growth-recon-*.php  [WP-11]
  admin/class-doughboss-growth-admin.php             [WP-01] settings shell; modules add tabs via action
  content/claims.json                                [WP-02]   content/events.json [WP-03]   content/landing/*.json [WP-06]
  public/css/dbgr-*.css  public/js/dbgr-*.js           ES5, owned by the module that uses them
  public/vendor/hero-webgl.<sha8>.js  public/vendor/hero-manifest.json   [WP-09 output, copied by build script]
  tests/bootstrap.php tests/run.php tests/test-*.php tests/*.test.js tests/fixtures/*.json
  scripts/build-zip.php scripts/validate-zip.php scripts/es5-check.mjs scripts/budgets.php   [WP-01]
doughboss-growth-media/   doughboss-growth-media.php (header only)  assets/hero/...   [WP-10]
.github/workflows/growth-ci.yml                                        [WP-01]
```

### 4.2 Names (all new; none collide with core)

Collision check (OBSERVED): `grep -rn` over B and C `*.php|*.js|*.css` finds no `doughboss_growth`, `doughboss-growth`, `DoughBossGrowth` or `dbgr`. The shorter prefix `dbg-` suggested in `03 §3.9` is **not** free: core's staff-guide stylesheet declares `--dbg-ink`, `--dbg-paper`, `--dbg-red` and other `--dbg-*` custom properties on `:root` (`public/css/doughboss-guides.css:1`, B and C). The companion therefore uses `dbgr-` for CSS classes, `--dbgr-*` for custom properties and `dbgr_` for cookies and form fields.

| Kind | Names |
| --- | --- |
| Constants | `DOUGHBOSS_GROWTH_VERSION`, `DOUGHBOSS_GROWTH_DB_VERSION`, `DOUGHBOSS_GROWTH_DIR`, `DOUGHBOSS_GROWTH_URL`, `DOUGHBOSS_GROWTH_DISABLE`, `DOUGHBOSS_GROWTH_UNINSTALL_DELETE_DATA`; env-first secrets `DOUGHBOSS_GROWTH_GA4_API_SECRET`, `DOUGHBOSS_GROWTH_META_CAPI_TOKEN`, `DOUGHBOSS_GROWTH_WEBHOOK_SECRET`, `DOUGHBOSS_GROWTH_SQUARE_LABOUR_TOKEN`, `DOUGHBOSS_GROWTH_SQUARE_ENV` |
| Options | `doughboss_growth_settings` (keys: `features`, `gtm_container_id`, `ga4_measurement_id`, `meta_pixel_id`, `consent_text_version`, `consent_default`, `sender_legal_name`, `privacy_policy_url`, `notify_webhook_url`, `send_hashed_identifiers`, `seo_jsonld_with_seo_plugin`, `retention_pending_days`, `retention_confirmed_months`, `coming_soon_headline`, `coming_soon_body`, `coming_soon_page_slug`), `doughboss_growth_db_version`, `doughboss_growth_pages`, `doughboss_growth_recon` |
| Tables (`{$wpdb->prefix}` +) | `doughboss_growth_waitlist`, `doughboss_growth_suppression`, `doughboss_growth_attribution`, `doughboss_growth_lead_meta`, `doughboss_growth_outbox`, `doughboss_growth_rate`, `doughboss_growth_recon_run`, `doughboss_growth_recon_row`, `doughboss_growth_recon_xref` |
| REST `doughboss-growth/v1` | `GET /form-token` (public, no-store); `POST /waitlist`; `POST /waitlist/confirm`; `POST /waitlist/unsubscribe`; `GET /health` (`manage_doughboss`; flags and readiness booleans, never secrets) |
| admin-post actions | `doughboss_growth_save_settings`, `doughboss_growth_create_pages`, `doughboss_growth_export_waitlist`, `doughboss_growth_export_offline_conversions`, `doughboss_growth_recon_run`, `doughboss_growth_recon_confirm_mapping`, `doughboss_growth_recon_export` (all `manage_doughboss` + nonce) |
| Cron | `doughboss_growth_outbox_dispatch` (custom 5-minute schedule `doughboss_growth_5min`), `doughboss_growth_retention_purge` (daily), `doughboss_growth_recon_daily` (daily) |
| Shortcodes | `doughboss_growth_landing` (`key`), `doughboss_growth_coming_soon`, `doughboss_growth_waitlist` (`store`), `doughboss_growth_lead_form` (`variant`), `doughboss_growth_party_sizer`, `doughboss_growth_hero` |
| Cookies | `dbgr_consent`, `dbgr_attr` |
| Browser | global `DoughBossGrowth` (`track`, `consent`), events `doughboss-growth:consent-changed`, `doughboss-growth:hero-tier`; consumes core `doughboss:marketing-event`, dispatches core `doughboss:consent` |
| Actions provided | `doughboss_growth_loaded`, `doughboss_growth_admin_tabs`, `doughboss_growth_waitlist_confirmed( $id )`, `doughboss_growth_lead_recorded( $enquiry_id, $meta )`, `doughboss_growth_recon_completed( $run_id, $status )` |
| Filters provided | `doughboss_growth_feature_enabled( $enabled, $feature )` (can only disable), `doughboss_growth_conversion_payload( $payload, $channel, $event )` (output re-validated against the allow-list) |
| Capabilities | reuse core `manage_doughboss`; no roles or caps created |

### 4.3 Core hooks the companion consumes (read-only use)

Actions: `doughboss_order_created` (`class-doughboss-order.php:470`), `doughboss_order_payment_status_changed` (`:1188`), `doughboss_catering_enquiry_created` (C `class-doughboss-catering.php:248`, B `:237`), `doughboss_catering_status_changed`, `doughboss_catering_payment`, and from 2.44.0 `doughboss_square_order_linked`. Filters: `doughboss_marketing_config` (`class-doughboss-assets.php:202`), `doughboss_load_catering_assets` (`:364`, for spokes that embed the core form), `doughboss_load_assets` (`:107`). WordPress core: `do_shortcode_tag`, `rest_request_before_callbacks`, `rest_post_dispatch`, `document_title_parts`, `wp_head`, `wp_footer`, `wp_privacy_personal_data_exporters`, `wp_privacy_personal_data_erasers`. Data read through public APIs only: `DoughBoss_Locations::all()/get()/weekly_hours()/is_valid()`, `DoughBoss_Order::get()`, `DoughBoss_Catering::get()`, `DoughBoss_Catering_Package` posts, timeclock accessors (3.7).

### 4.4 Core touch points (2.44.0) and recommendation

**Recommendation: add only the core changes below, all in one 2.44.0 release on top of #68; make no core change for the growth features.**

| # | File:line (C) | Change | Why it must be core |
| --- | --- | --- | --- |
| 1 | `includes/class-doughboss.php:86` | require the new `class-doughboss-square-*.php` and `class-doughboss-kitchen-dispatch.php` | loader |
| 2 | `includes/class-doughboss-square.php:909` | add public `api()` wrapper around private `request()` | reuse headers/version/redaction |
| 3 | `includes/class-doughboss-square.php:382-386` | create Square order before the irreversible claim | money ordering |
| 4 | `includes/class-doughboss-square.php:398-411,733-748,561-656,787-817` | `order_id` in payment body, matching, retrieval and refunds | verification |
| 5 | `includes/class-doughboss-rest-controller.php:4205-4363,3826-3829` | verify Square order at `/checkout`; link after commit | single order writer |
| 6 | `includes/class-doughboss-rest-controller.php:5545-5548,5566-5586` | webhook allow-list; snapshot recovery | recovery owner |
| 7 | `includes/class-doughboss-pospal-orders.php:45`, `class-doughboss-ntfy.php:54`, `class-doughboss-mercure.php:66`, `class-doughboss-printer.php:213` | `doughboss_kitchen_dispatch_allowed` filter | no veto point exists (`02 §7.4`) |
| 8 | `includes/class-doughboss-activator.php` (`create_tables()`, storage contract `:755-805`), `class-doughboss-migrations.php:63-87`, `doughboss.php:6,26,31`, `readme.txt` | DB 1.24.0, four new tables, version bump | schema |
| 9 | `includes/class-doughboss-settings.php:233-244` defaults | `square_orders_enabled` 0, `square_orders_pilot` 1, `square_orders_catalog_mode` `adhoc`, `square_orders_live_approved_by`/`_at` | gates |
| 10 | `admin/class-doughboss-admin.php:3575` + new `admin/class-doughboss-square-admin.php` | location binding screen, webhook subscriptions text, unresolved-attempts list | operator UI |
| 11 | `includes/class-doughboss-rest-controller.php` beside `/pay/tyro-test` (`:424`) | `POST /pay/square-test` calling `test_connection()` | no caller today (`02` finding 5) |
| 12 | `public/js/doughboss-catering.js:466` | dispatch `doughboss:catering-enquiry-created` | browser lead signal |
| 13 | `admin/class-doughboss-admin.php:2727`, `includes/class-doughboss-cli.php:239` | refuse "Import standard menu"/`seed-menu` when published `doughboss_item` posts exist unless `--force-bootstrap` | removes the second menu writer (`01 §3.5`) |
| 14 | `public/js/doughboss-orderboard.js:584,700` and `GET /admin/orders` payload | per-order `kitchen_ticket_by` flag; status buttons read-only for Square-owned shops; copy mentions Square | staff must not drive two kitchens (`02 §7.4` point 5) |

Avoided on purpose: no filter around payment bodies, snapshots or attribution (would create a second writer of payment data); no `square_location_id` column on `doughboss_locations` (needs edits in two positional format arrays, `class-doughboss-locations.php:201,228`); no change to core SEO, privacy or reports classes.

---

## 5. Data flow summary

```
Browser (ES5)                       WordPress (core = only writer of menu/orders/enquiries/shifts)      Outside
-----------------------------------------------------------------------------------------------------------------
consent banner -> dbgr_consent ----> gtag consent update; core doughboss:consent
landing params -> dbgr_attr (gated)
core form -> /catering/enquiry ---> core inserts enquiry -> doughboss_catering_enquiry_created
                                      -> companion lead_meta (+ attribution from cookie / dbgr_* stash)
                                      -> outbox generate_lead ----------------------------------------> GA4 MP / Meta CAPI (consented)
checkout -> /payment-intent -------> core square-v2 [2.44: CreateOrder -> CreatePayment(order_id)] ----> Square Orders/Payments
          <- payment_intent id        companion stores attribution by payment id
         -> /checkout --------------> core order (paid) -> doughboss_order_created
                                      -> kitchen consumers ask doughboss_kitchen_dispatch_allowed
                                      -> companion attribution join; outbox purchase(order:{number}) -> GA4 / Meta
Square webhooks ------------------> core: payment.*, order.*, refund.* (dedupe, re-fetch, bridge status)
waitlist form -> /waitlist --------> companion table -> confirm email -> outbox webhook (no PII) ------> operator webhook
WP-Cron daily -------------------> companion recon: read shifts (core tables) + Square timecards (read token) -> report
```

---

## 6. Risks and open questions (ranked)

| Rank | Risk | Evidence | Mitigation in this design |
| --- | --- | --- | --- |
| 1 | **Entity/brand ownership unresolved** (who owns the site, Square merchant, GTM/GA4/Ads/Meta accounts, Business Profiles; sender name for the Spam Act) | session task 18; `compliance-au.md:125`; `conversion-plan.md` `[CONFIRM ...]` row 9 | waitlist cannot enable without `sender_legal_name`; GTM/server conversions need container/account ids that only the owner can supply |
| 2 | Generated hero art contradicts the 2.34.0 real-photo decision | `readme.txt:152-153`; `manoush-blowout-still.json`; procedural GLB | feature off; AI still never shipped; owner decision or a photo shoot |
| 3 | Reopening ordering exposes Bankstown and Roselands (all three `pickup_enabled`, `single_location_mode` ineffective) | `01` summary 5; `class-doughboss-locations.php:104-110` | data fix before `ordering_open`; Square pilot gate G3 limits Square to one shop but does not stop pay-at-shop orders elsewhere |
| 4 | Upload route on Crazy Domains unproven; core zip over 2 MB; `wpvibe` install returned "Plugin not found" | `docs/VISUAL-PREVIEW-20260908.md:118-120,239` | companion zips under 1.0/1.9 MB; core 2.44.0 delivery still needs SFTP or host help |
| 5 | Live bytes differ from B; version labels lie | `01 §1`; `03 §3.6` | companion feature-detects and its inert-activation test runs against B and C; re-baseline live before any core install |
| 6 | Square KDS/Orders-app visibility and status round-trip cannot be proved in sandbox | `api-integration-notes.md:32,302,354` | G5 staffed production test; polling backstop; board fallback |
| 7 | Inclusive-GST rounding differences between plugin and Square | `class-doughboss-cart.php:341`; `02 §6.4` | total-equality gate before payment; fixtures |
| 8 | Two price authorities once Square POS sells in store (Square catalogue vs WordPress) | `docs/SQUARE-MIGRATION-20260908.md:36`; `02 §6.4` | WordPress canonical; read-only drift report; no `ITEMS_WRITE` |
| 9 | Consent default choice trades measurement volume against caution | `compliance-au.md:168` | fail-closed default, owner-selectable setting |
| 10 | Page caching may serve stale nonces | INFERRED (host cache unknown) | waitlist uses a no-store form token; core forms unchanged |
| 11 | ModSecurity returned HTTP 406 to a command-line request | `web/docs/marketing/02-seo-external.md:108` | Search Console live test before ads; host allow-list for verified crawlers |
| 12 | WP-Cron needs traffic; a real cron is unknown | `03 §2.9` | outbox and recon tolerate delay; "Run now" buttons; ask host for a cron |
| 13 | SQLite local runtime cannot prove MySQL locking paths | `04 §9` | MySQL integration harness for limiter, outbox and core Square paths |
| 14 | Marketing doc proposes 301 `/locations/` -> `/#locations` (Next.js-era plan) | `02-seo-external.md:172` | rejected: WordPress keeps `/locations/` as the hub with child pages |
| 15 | Seeder and ops scripts can still wipe data | `01 §3.5`; `ops/scripts/align_prices.py:109-124` | core touch point 13; repair flags/descriptions as a separate data task |

---

## 7. What the owner must decide or supply (also in the report's `needsFromElie`)

In unblock order: (1) legal entity and account ownership for the website, Square merchant, GTM/GA4/Google Ads/Meta and Business Profiles, and the sender name/ABN for emails; (2) approve this architecture and the branch plan (companion on `claude/live-2.41.0-baseline`, core 2.44.0 on #68); (3) confirm whether the 2.43.x line (#68) will be installed, since Square Orders builds on it; (4) consent default (deny-until-chosen or notice-and-opt-out) and the privacy-policy update covering analytics, ad platforms and the waitlist; (5) the hero asset rule (real photographed layers only, or stylised 3D allowed) and approval of the single generated-bundle ES5 exception; (6) whether live catering package names containing "Minis" (`01 §3.8`) are exempt from the no-"Minis" rule, and confirm `/catering/minis` stays unbuilt; (7) the claims you can confirm for landing pages (catering lead times, service area, delivery or drop-off, dietary/halal status with evidence, catering phone 0422 487 487, `02-seo-external.md:87`); (8) waitlist retention for confirmed subscribers and whether suppression hashes are kept after erasure; (9) Square pilot facts (merchant, pilot shop, developer app, KDS hardware, catalogue/price authority, `docs/SQUARE-MIGRATION-20260908.md:30-36`); (10) timesheet decisions (`03 §2.11`: pay authority, tolerances, cutoffs, paid breaks, approval to read Square staff data); (11) whether the host allows Plugins -> Upload and has a real cron; (12) per-shop `pickup_enabled` state before ordering reopens.

---

## 8. What I ran

- Reads and greps over B, C and `web/` (no writes there); `cmp` of shared files; verification table in section 2.
- Bundle-size probe in my scratchpad only: `NODE_PATH=web/node_modules web/node_modules/.bin/esbuild <scratch>/entry.mjs --bundle --format=esm --minify --target=es2018` importing `WebGLRenderer, Scene, PerspectiveCamera, AmbientLight, DirectionalLight, Clock` and `GLTFLoader`: 615,676 bytes raw, 155,148 bytes `gzip -9`. esbuild 0.28.2, three 0.186.1. Nothing written in `web/`.
- GLB size and magic read with Node (`glTF`, 515,376 bytes).
- Not run: any PHP test (the mappers ran C 412/412 and B 94/94, `02 §14`), PHP 7.4, the local runtime, any network request, any provider API.

## 9. Corrections to the mapper documents

1. `04 §4` says `DOUGHBOSS_DB_VERSION` is defined at `doughboss.php:36`; it is `doughboss.php:31` in both B and C (OBSERVED).
2. `01 §8.5`, `03 §3.9` rule 4 and `03 §10` item 6 say a homepage block needs a theme release. Not strictly: the theme renders the hero through `do_shortcode()` (C `themes/doughboss-final/functions.php:386-392`, B `:310-316`), so WordPress's `do_shortcode_tag` filter can append markup after the home hero with no theme or core edit. A theme slot is still cleaner long term.
3. `03 §6.4` point 3 says new files in a new directory "cannot textually conflict" with the open PRs. True, but once #68 merges, core CI lints every PHP file and runs `node --check`/`node --test` over every `*.js`/`*.test.js` in the repository (`.github/workflows/plugin-ci.yml:30-48`), so companion files are also gated by core CI; only directories named `vendor` are pruned.
4. `02 §0` labels B "the live plugin"; `01 §1` shows five live front-end files differ from B. B is the closest source, not a mirror of production.
5. `02 §6.5` and `03 §2.7` place the Square location map in the companion; this architecture puts the payments/orders map in core (single writer of payment configuration) and keeps only a labour-reporting mapping in the companion, with core authoritative once 2.44.0 is installed (3.7).
6. The brief's "Coming soon: Minis" section and `/catering/minis` route are superseded by Elie's 2026-10-02 teaser direction (`web/docs/site/teaser-direction.md`); `03 §7` rows 2-2d still describe a Minis teaser.
7. `03 §3.9` rule 3 proposes the CSS prefix `dbg-`; core already uses `--dbg-*` custom properties on `:root` in `public/css/doughboss-guides.css:1` (B and C). Use `dbgr-` (section 4.2).
8. `02 §9` suggests a core `doughboss_checkout_snapshot_payload` filter so webhook-recovered orders keep attribution. Not needed: `/payment-intent` responses already return the provider payment id (`includes/class-doughboss-rest-controller.php:2835,2848`), which becomes the order's `payment_intent_id`, so the companion can key attribution by it from `rest_post_dispatch` with no core change (3.4).
