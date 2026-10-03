# Live quick fixes (written and tested; NOT applied to the live site)

Three small fixes for doughboss.com.au, each a self-contained snippet for the WPCode plugin the site already uses for its hotfixes (`ops/README.md`, snippets #533 to #537). Nothing has been applied: this session has no WordPress or Crazy Domains access. Someone with admin access pastes each snippet after Elie says go.

| # | Fix | File | WPCode type, location | What it changes |
|---|---|---|---|---|
| 1 | Remove the black strip on the right of inner-page heroes | `01-hero-strip.css` | CSS snippet, Site Wide Header (or Appearance > Customize > Additional CSS) | One rule, `max-width: none` on `.dbf-page-hero-bg`. The theme's global `img { max-width: 100% }` capped the 108%-wide hero image, leaving a strip (51 px at 1280 px wide, 16 px at 390 px). |
| 2 | Accessible names for the 43 menu card photos | `02-menu-photo-labels.js` | JavaScript snippet, Site Wide Footer | Adds `role="img"` and `aria-label="<item name>, photo"` to each card photo. A photo shown on more than one card (11 drinks and 3 veggie items today) is hidden from assistive technology instead, because labelling it as the item would be untrue. Labels appear automatically once those items get their own photos. |
| 3 | Keep staff pages out of search | `03-sitemap-staff-pages.php` | PHP snippet, Run Everywhere | Removes `/kitchen/` and `/track-order/` from the core sitemap, adds noindex to their robots meta, and sends `X-Robots-Tag: noindex, nofollow`. Reads two page slugs; writes nothing. |

## How to apply (about 10 minutes)

1. WordPress admin > WPCode > Code Snippets > Add Snippet > "Add Your Custom Code (New Snippet)".
2. Choose the type and location in the table, paste the file contents (for the PHP file, do not paste the opening `<?php` line if WPCode adds its own), name it `Quick fix N`, switch it on, Save.
3. If a page cache or CDN is active, purge it.
4. Check:
   - **Fix 1:** open `/order/`, `/locations/`, `/about-us/`, `/franchising/`. The hero photo reaches the right edge with no black strip, on desktop and phone.
   - **Fix 2:** on `/menu/`, inspect any card photo: it has `role="img"` and an `aria-label`. The drink cards carry `aria-hidden="true"`.
   - **Fix 3:** open `/wp-sitemap-posts-page-1.xml`: `/kitchen/` and `/track-order/` are gone and the customer pages remain. In the browser's network panel, `/track-order/` shows `x-robots-tag: noindex, nofollow`. Search engines drop the pages on their own schedule, not instantly.

## Undo

Deactivate the snippet in WPCode, then purge the cache. Each fix is independent.

## Evidence (2 October 2026)

- `verify-quick-fixes.cjs`: **14 of 14 checks pass** against a local runtime built from the 2.41.0 plugin and theme source, with each fix switched on and off (hero gap 51.2 px to bleed past both edges with no horizontal overflow, 15.6 px at 390 px; card labels: 31 labelled, 3 shared photos hidden, 0 wrong; sitemap and robots output; other pages untouched).
- `verify-on-live-dom.cjs`: **10 of 10 checks pass** against the real live pages loaded in a local browser, with the snippets injected into that browser's copy only (nothing sent to or stored on the site): hero strip fixed on `/order/`, `/locations/`, `/about-us/` and `/franchising/` at 1280 and 390 px; on `/menu/` and `/order/`, 43 cards = 29 labelled + 14 hidden, none wrong.
- That live check caught a real difference: the live 2.41.0 build uses `h4` for card titles but the repository source uses `h3`. The snippet accepts either.
- `php -l` clean; the JavaScript parses as ES5 (the site's rule).

## Not verified

- Fix 3 was tested on a local WordPress, not on the live host. The live host runs ModSecurity and a page cache, so confirm the sitemap and header after applying it.
- The live site may have an SEO plugin that also prints robots tags; the header and noindex meta both say noindex, so the most restrictive instruction wins.
- Nothing here changes `robots.txt`.
