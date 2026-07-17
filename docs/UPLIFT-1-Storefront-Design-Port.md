# UPLIFT-1 — Storefront Design Port (demo polish → plugin storefront)

Status: spec, decision-complete. Scope: visual/UX parity only — no REST, pricing, or data-model changes.

Port the demo site's visual polish (`demo/demo.css` + `demo/index.html` patterns) onto the plugin's
real customer storefront: `public/css/doughboss.css` (scoped `.db-app`), rendered by
`includes/class-doughboss-shortcodes.php` shells hydrated by `public/js/doughboss.js`.

## Baseline (already shared — do NOT re-port)

Both stylesheets already share the 2026 mono ink/paper system: mono `#111` accent, radius scale
(`--r-sm/md/lg/xl/pill` ↔ `--db-r-*`), shadow scale (`--sh-1/2` ↔ `--db-shadow-1/2`), easing
(`--ease`/`--db-ease`, `cubic-bezier(.32,.72,.33,1)`), timing (`--t-fast/med`), `:focus-visible`
ring, `prefers-reduced-motion` guard, red-stays-red errors. The port is about the *remaining* gap:
typography, layout patterns, and interaction feedback.

## 1. Component-by-component mapping

| Demo pattern (demo.css) | Plugin equivalent | Change needed | Effort |
|---|---|---|---|
| Display font `Bebas Neue` + body `Barlow` (`.disp`) | System font stack only | Enqueue Google Fonts (or self-host) in `class-doughboss-assets.php`; add `.db-app` font-family + `.db-disp` heading class | S |
| Boxed mono wordmark `.brand-mark` (`.bm-box/.bm-dot/.bm-sub`, L595-601) | None | New optional `[doughboss_brand]` markup or emit atop `[doughboss_menu]`; copy CSS as `.db-brand-mark` | S |
| Design tokens `--r-*/--sh-*/--ease` | `--db-r-*/--db-shadow-*/--db-ease` exist | Verify values match (they do); add missing `--db-ink/--db-paper/--db-linel` paper-tone tokens | S |
| Menu item row w/ photo `.mn-item/.mn-it-img` (72px rounded thumb, dotted price lead `.mn-lead`, Bebas name/price) | `.db-card` grid with 140px `.db-card-img` banner | Decision: keep card grid, restyle to demo tone (Bebas item name via `.db-disp`, ember-mono price, `--db-r-md` thumb radius, `--sh-1` on image). Optionally add compact list variant `.db-menu--list` cloning `.mn-item` | M |
| Branded placeholder tile `.mn-it-ph` (boxed-logo tile for photo-less items, L602-605) | Striped gradient `.db-card-img--placeholder` | Replace stripes with boxed-wordmark mono placeholder (pure CSS + one inline SVG in JS `renderMenu`) | S |
| Category header banner `.mn-cat-head` (photo + gradient overlay) | Plain `.db-category` heading + underline | Phase 2 optional: category term image meta. Phase 1: keep underline heading, upgrade type to Bebas | M |
| Sticky category jump-bar `.mn-jumpbar/.mn-jump` | None | New: render pill buttons per category in `renderMenu`; sticky under theme header (`top` configurable, see risks) | M |
| Add / stepper controls `.mn-add/.mn-step` | `.db-btn` "Add to cart" w/ inline "Added ✓" swap (doughboss.js L276-283) | Restyle `.db-btn` to demo geometry (`--db-r-md`, Bebas label, active `scale(.96)`); stepper optional Phase 2 | S |
| Added-toast `.cart-toast` (pill, green check span, L281-284) | `.db-toast` exists but is error-only | Add success variant `.db-toast--ok` + `showToast(msg, ok)` in doughboss.js; call after successful `addToCart` | S |
| Pinned CTA drawer `.cart-fab` (fixed view-order bar, count + total, `cartbump` animation) | None — cart is a separate `[doughboss_cart]` page | New `.db-cart-fab` fixed bar shown on menu pages when cart non-empty; links to cart page URL (new setting `cart_page_url` or `data-cart-url` shortcode attr). Bump animation on add | M |
| Options sheet `.opt-sheet/.opt-c/.opt-overlay` (centred modal, bottom-sheet <560px, `:has(input:checked)` ring) | `.db-builder-inner` inline panel + `.db-option` rows | Phase 1: restyle `.db-option` to `.opt-c` look (shadow, checked ring, accent-color, Bebas price). Phase 2: optional modal sheet for builder | M |
| Pinned checkout CTA `.cd-form/.cd-scroll/.cd-cta` (scrollable fields, pinned pay button) | `.db-checkout` plain column form | Phase 2: only meaningful if cart becomes a drawer; on a full page, skip. Adopt only the `.cd-cta` top-shadow divider styling on `.db-totals` | S |
| Rewarding confirmation `.cd-check` + `checkpop` keyframe (green pop-in check) | `.db-confirm` static bordered box | Add `.db-confirm-check` circle + `db-checkpop` animation; emit in doughboss.js order-placed branch (~L881) | S |
| Voucher applied row `.cd-von` (green pill w/ remove) | `.db-voucher-applied` exists, near-identical | Token alignment only | S |
| Sticky quote summary `.dbq-sum` (catering) | `doughboss-catering.js` CSS (separate file) | Out of scope for UPLIFT-1; note for a catering uplift | — |
| `touch-action: manipulation` on tap targets (L608) | Missing | Add to `.db-btn`, qty inputs, option rows | S |
| Coarse-pointer 44px tap targets (L250) | `.db-btn--lg` only | Add `@media(pointer:coarse)` block | S |

## 2. Demo patterns that do NOT port (simulated-only / marketing-only)

- Hash-view router (`.view/.view.active`), nav/hero/footer, grain overlay, stats strip, dual-engine
  panels, FAQ, locations, Offers & News (`#view-snow`), demo ribbon `.demo-ribbon` — marketing
  chrome; the plugin renders inside an arbitrary theme page.
- Simulated card form `.cd-test/.cd-cardform` ("TEST MODE") — plugin has real Stripe/Tyro fields.
- Demo cart drawer state machine (localStorage cart) — plugin cart is server-side transient.
- Kitchen lock panel, staff console styles — not customer storefront.
- Google-hosted `doughboss.com.au` imagery URLs — plugin must use site-local media only.
- Body-level rules (`body.cart-on` padding, `html{scroll-behavior}`) — cannot touch `body` from a
  plugin stylesheet safely; the FAB must reserve space via `.db-app` padding or `scroll-margin`.

## 3. Menu item photos — the featured-image path (already wired)

No new plumbing needed; this is confirmation, not work:

- CPT `doughboss_item` declares `'thumbnail'` support (`includes/class-doughboss-post-types.php` L111).
- REST menu response already maps `'image' => get_the_post_thumbnail_url( $post->ID, 'medium' )`
  (`includes/class-doughboss-rest-controller.php` L2282/L2293; `large` at L2972/L2985).
- `doughboss.js` `renderMenu` already branches on `item.image` (L265-267): background-image div vs
  `.db-card-img--placeholder`.

Remaining work: (a) upgrade the placeholder branch to the branded `.mn-it-ph`-style tile;
(b) ops task (not code): upload real photos as featured images per menu item (Higgsfield shot list
per demo.css L602 comment). Consider `medium_large` instead of `medium` if the 140px banner looks
soft on retina — one-line change, verify on staging.

## 4. Files to touch and rough line counts

| File | Change | ~Lines |
|---|---|---|
| `public/css/doughboss.css` (732 now) | Phase 1 restyle: fonts, tokens, card/option/button/confirm polish, placeholder tile, toast--ok, tap targets | +170 / ~60 edited |
| `public/css/doughboss.css` | Phase 2: `.db-cart-fab`, jump-bar, checkpop, bump keyframes | +110 |
| `public/js/doughboss.js` (1176 now) | Phase 2: success toast (~15), FAB render/update (~55), jump-bar (~35), confirm check markup (~10) | +115 |
| `includes/class-doughboss-assets.php` | Font enqueue + `cart_url`/new I18N strings in `wp_localize_script` | +15 |
| `includes/class-doughboss-shortcodes.php` | Optional: `data-cart-url` attr on menu shortcode; brand-mark helper | +25 |
| `includes/class-doughboss-settings.php` | Optional: `cart_page_url` + `storefront_fonts` (on/off) settings | +20 |

No changes to REST controller, cart, order, or any money path. Total ≈ 455 added lines across 5-6 files.

## 5. Phased plan

**Phase 1 — pure CSS parity (no markup/JS changes, zero behavioural risk).**
1. Add paper-tone tokens (`--db-ink:#0d0d0d; --db-paper:#f7f5f0; --db-linel:#e6e2da`) to `.db-app`.
2. Font enqueue behind a `storefront_fonts` setting (default on; off = system stack, no CLS risk).
3. Restyle: `.db-btn` (Bebas, `--db-r-md` geometry option), `.db-card` (name/price type, image
   radius/shadow), `.db-option` → opt-c look (`:has(input:checked)` ring with plain `:checked +`
   fallback), `.db-confirm` mono border, placeholder tile, tap targets, voucher token alignment.
4. Verify: `bash scripts/dev-check.sh`, visual pass on Twenty Twenty-Four + site theme.

**Phase 2 — markup/JS enhancements (feedback loop).**
1. Success toast on add-to-cart (`.db-toast--ok`, reuse existing toast fn at doughboss.js L71-73).
2. Pinned CTA FAB on menu pages: cart count + total from existing cart fetch; links to cart page;
   `cartbump` on add; hidden when `[doughboss_cart]` present on same page.
3. Sticky category jump-bar in `renderMenu` (scroll-to with `scroll-margin-top`).
4. `checkpop` confirmation check in the order-placed branch (doughboss.js ~L881).
5. Verify: dev-check strict, manual smoke: add → toast → FAB → checkout → confirm animation,
   reduced-motion pass, keyboard-only pass.

Phase 1 and 2 ship as **separate PRs**. Neither touches money paths, but Phase 2 edits doughboss.js
near checkout rendering — request the standard code review anyway.

## 6. Risks

- **Theme conflicts / `.db-app` scoping.** Every new selector MUST stay under `.db-app` (the FAB and
  toast are `position:fixed` but still classed and appended inside/adjacent to `.db-app` — keep them
  as descendants so the scope holds). Never style `body`/`html`. Theme resets can override plugin
  CSS loaded earlier: keep specificity at 2 classes, avoid `!important` except where demo already
  needed it (button color).
- **Sticky offsets.** Demo hard-codes `top:64px` for its own nav; themes have arbitrary sticky
  headers/admin-bar. Use `top: var(--db-sticky-top, 0px)` and document the override; degrade
  gracefully (non-sticky is acceptable).
- **Fixed FAB collisions.** Themes with their own bottom bars/cookie banners can overlap the FAB.
  `z-index` moderate (not 99999), `env(safe-area-inset-bottom)` padding, setting to disable.
- **Font loading.** External Google Fonts adds a third-party request (privacy/GDPR + perf). Prefer
  self-hosted woff2 in `public/fonts/` (GPL-compatible: Bebas Neue & Barlow are OFL); `font-display:swap`.
- **`:has()` support.** Fine in all 2024+ browsers, but ship the `input:checked + label`-order
  fallback so old Safari still shows a selected state.
- **CLS.** New fonts + FAB appearing can shift layout; reserve FAB space with padding on the menu
  container only, and size-match fallback fonts (`size-adjust`).
- **Reduced motion.** All new keyframes (`barpop`, `cartbump`, `checkpop`, `optpop`) must be inside
  the existing `prefers-reduced-motion` guard (doughboss.css L33-41 already blanket-covers `.db-app *`
  — FAB/toast outside `.db-app` would escape it; another reason to keep them descendants).
- **Cart model mismatch.** The demo FAB opens a drawer over a localStorage cart; the plugin FAB only
  *navigates* to the cart page. Do not attempt a drawer-cart in UPLIFT-1 — that would duplicate
  checkout logic and drag in money-path review scope.
