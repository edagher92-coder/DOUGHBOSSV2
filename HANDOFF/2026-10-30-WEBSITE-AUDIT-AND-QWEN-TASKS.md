# 30 October 2026: live website audit and the first Qwen storefront tasks

**Owner request:** "doughboss website improvements … use qwen to fix the top issues".

**What was not touched:** no deploy, theme or plugin upload, WPCode snippet, Square, Ads or form submission. Live checks were GET-only.

## How this was done

| Step | Who | Evidence |
|---|---|---|
| Live audit at 390 and 1280 px (Playwright): LCP, CLS, requests, SEO and accessibility | Sonnet worker | `live-results.json` and screenshots, kept in the session scratchpad and not committed |
| Each top finding re-checked against the repo source | Claude (lead) | Notes below |
| Qwen task kit: runner, briefs and oracles | Claude | `ops/qwen/` and `tests/qwen/` |
| Qwen writes the fixes | Qwen on ELZYDLAB | **Not run yet.** This cloud session cannot reach the PC's Qwen, and the Ollama Cloud connector refused inference ("invalid credentials") and lists no Qwen model |

## The headline: live is two releases behind the repo

- Live serves plugin **2.49.0**. The repo is **2.51.1** (`doughboss.php:6`).
- Several live findings are already fixed in the repo:
  - menu-card image `width`/`height` (`public/js/doughboss.js` `menuPhoto`);
  - the catering enquiry form, quote and REST route (`includes/class-doughboss-catering.php`, `create_catering_enquiry`);
  - the skip link (`themes/doughboss-final/header.php:11`).
- Releasing 2.51.x is the owner's decision under the existing release gates and is not part of this change.
- Live copy also says "Mediterranean Bakery" while the repo SEO says "Lebanese" (`includes/class-doughboss-seo.php:139`, `:332`). That needs the owner's call before any release.

## Findings, re-checked against 2.51.1

| # | Finding (live, measured) | Still in repo? | Route |
|---|---|---|---|
| 1 | Once, theme and plugin CSS were served as `text/plain` and refused, leaving the home page unstyled. Also one 502 on /menu/ and three timeouts | Host-level, not code. **Not reproduced:** 6 of 6 later probes of `style.css` returned `text/css` | Watch the host and cache. Nothing to fix in the repo |
| 2 | Layout shift: /order/ CLS 0.297, /menu/ CLS 0.235 (the "good" threshold is 0.1) | Unknown: the 2.51 store-first picker changed this area and can't be measured without WordPress | Measure 2.51.1 on staging first |
| 3 | Mobile: about 290 of 844 px is banners, header and shop picker; "ordering paused" is said 3 to 4 times | Partly (`themes/doughboss-final/header.php:18-19`, call bar, shop card) | Design and copy decision for the owner |
| 4 | Shop-card phone shows `0287646783` and dials `tel:0287646783` (no +61) | **Yes**: `public/js/doughboss.js` `shopContact` | **Qwen tasks `phone-helpers` and `shop-contact-phone`** |
| 5 | /contact/ and /about-us/ have no title, description or OG tags (about reuses the home copy) | **Yes**: `doughboss_final_thin_page_copy()` (`themes/doughboss-final/functions.php`) has no branch for either | Customer-facing copy, so Claude drafts it and the owner approves (not a local model) |
| 6 | /menu/ canonicalises to /order/, whose title and description say "paused" | Deliberate (`functions.php` canonical) | Owner decision |
| 7 | JSON-LD `telephone` is not in +61 form; no `geo`/`sameAs`/`hasMenu` | Yes, but `tests/test-seo.php:52,81` pin the current form | Later; needs the owner's facts for `sameAs`/`geo` |
| 8 | Home LCP is the 212 KB JPG poster; 4 to 6 CSS files per page | Yes | Later: asset pipeline (`scripts/build-responsive-images.php`) |
| 9 | Catering is mailto-only on live | **Fixed in repo** (enquiry form and REST) | Ships with 2.51 |
| 10 | Skip link missing on /menu/ and /contact/; small tap targets; /catering/corporate and /catering/events 404 | Skip link fixed in repo; the 404 links are not linked anywhere in the theme | Low |

**New, found while writing the oracle:**
- `DoughBoss_Settings::phone_tel('1300 123 456')` returns `+1300123456`, which dials another country.
- `phone_display` returns `(13) 0012 3456` for the same number.
- No shop uses a 1300 number today, so it is latent. The Qwen JS helper is deliberately stricter and refuses to link it.
- A PHP fix belongs with the other Settings tests (`tests/test-*.php`) and is not in this change.

## Why only two Qwen tasks

The NUMBERS RULE keeps payment, cart, price, quote, voucher, catering and customer-facing copy away from a local model. Of the live issues still present in 2.51.1, the shop-card phone bug is the one that is:
- code, not copy or a design decision;
- outside every stakes path;
- gradeable by a deterministic oracle.

The other issues are either already fixed in the repo or are owner decisions.

## Next steps

1. On ELZYDLAB, run the commands in `ops/qwen/README.md` and push.
2. The lead reviews Qwen's diff, as was done for the Trueline coach modules, then folds the oracles into `tests/` once the change is accepted.
3. The owner decides:
   - releasing 2.51.x;
   - "Lebanese" or "Mediterranean";
   - the /menu/ canonical;
   - the mobile banner consolidation.
