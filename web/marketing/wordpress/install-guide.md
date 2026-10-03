# Install guide: LocalBusiness (Bakery) JSON-LD on the live WordPress site

DRAFT. Nothing here has been installed. A human with WordPress admin access follows this guide after Elie approves it. Prepared 2026-10-02.

## 1. What is in this folder

| File | What it is | Where it goes |
|---|---|---|
| jsonld-all-stores.html | One script block with a WebSite node and three Bakery nodes (Revesby, Bankstown, Roselands Centro) | The existing /locations/ page today, because that page shows all three stores. Use it when no SEO plugin already outputs a WebSite node |
| jsonld-all-stores-bakeries-only.html | The same three Bakery nodes without the WebSite node | The existing /locations/ page instead of the file above, if an SEO plugin already outputs a WebSite node |
| jsonld-revesby.html, jsonld-bankstown.html, jsonld-roselands.html | One Bakery node each | Each store's own page, once /locations/<store> exists. Google's guidance is to put LocalBusiness markup on each location's own page and to point each node's `url` at that page (https://developers.google.com/search/docs/appearance/structured-data/local-business, retrieved 2026-10-02) |

How they were made: a one-off command ran src/lib/seo.ts (buildLocalBusinessJsonLd) over the typed store data in src/lib/data/catalogue.ts through `npx tsx`, and the output was written to these files. Every value (name, phone in E.164, address, hours) comes from the typed store data. Nothing was typed in by hand, and nothing was added for geo, price range, images, ratings or reviews, because we hold no verified source for them. The unit test (tests/unit/marketing-seo-external.test.ts) parses each file and checks it has the right number of Bakery nodes and matches the store data.

## 2. Before you paste

1. Confirm the NAP in marketing/nap.json with Elie. A wrong value in structured data is a public claim about the shop. Open items: the exact signage name, the Revesby address wording ("12/25" or "Shop 12/25"), "Rd" or "Road", the Roselands centre name, and public-holiday hours.
2. Choose one source of schema. If an SEO plugin is installed and already outputs Organization, WebSite or LocalBusiness markup, either turn that off for local business or use only the bakeries-only file. Two sets of conflicting markup on one page is worse than one. On 2026-10-02 the site's sitemap was the WordPress core one (wp-sitemap.xml) and no Yoast or Rank Math signals were found in the pages read, so there is probably no plugin yet. [CONFIRM in wp-admin, Plugins.]
3. Three locations means the free tiers do not help: Rank Math's multiple-locations feature is Pro only (https://rankmath.com/kb/multiple-locations/, retrieved 2026-10-02) and Yoast's local SEO is a separate premium product (https://yoast.com/features/local-seo/). Pasting these blocks needs no paid plugin.
4. The `url` field in each Bakery node is the home page because that is what src/lib/seo.ts emits. When /locations/<store> goes live, change each store's `url` to its own page in the pasted copy, and tell the lead so src/lib/seo.ts can match.
5. Add `geo` only with verified coordinates (five or more decimal places, taken from the verified Google profile pin). Add `image` only with a real photo URL from the shop. Do not add `priceRange`, `aggregateRating` or reviews unless the ledger confirms them.

## 3. Install with a header-code plugin

Any plugin that adds code to the page head works. WPCode Lite is one option: its free version offers conditional logic by page URL (WPCode, https://wpcode.com/lite/, retrieved 2026-10-02) and is listed at https://wordpress.org/plugins/insert-headers-and-footers/. Button labels change between versions. [CONFIRM the current labels when you do it.]

1. In wp-admin, install and activate the header-code plugin. Make sure it is from the official WordPress plugin directory. Take a backup or snapshot of the site first.
2. Add a new snippet of type HTML. Name it "Dough Boss JSON-LD: locations".
3. Paste the full contents of jsonld-all-stores.html (or the bakeries-only file, see section 1), including the `<script type="application/ld+json">` tags and the comment line at the top. Leave out nothing and add nothing.
4. Set the location to the site header (head).
5. Set the conditional rule to run on one page only: the page whose URL is /locations/.
6. Save and activate.
7. Later, when each store page exists, add one snippet per store with that store's file, set to run only on that store's page. Change that file's `url` to the store page. Then turn the all-stores snippet off, so each store's data appears on its own page.
8. Do not install any block on every page, and do not put the three-store block on the home page. Each page should only carry markup for what the page shows.

## 4. Validate

1. Open the page, view source, and search for `application/ld+json`. You should find exactly one block per file you pasted, and no stray quote marks or HTML entities.
2. Paste the page URL into Google's Rich Results Test (https://search.google.com/test/rich-results). Bakery is a LocalBusiness type, and the tool may report the items as detected, with warnings for the recommended fields we deliberately left out (geo, image, price range). Warnings for those are acceptable. Errors are not.
3. Paste the same URL into the Schema Markup Validator (https://validator.schema.org) and confirm no syntax errors.
4. In Google Search Console, use URL Inspection on the page, run Test Live URL, and view the crawled HTML. Confirm Googlebot sees the block. This also checks that Googlebot is not blocked. Reason: on 2026-10-02 the host's ModSecurity firewall returned HTTP 406 to a plain command-line request for the home page, which means the firewall refuses some automated requests. Googlebot and Bingbot must not be among them. See docs/marketing/02-seo-external.md, task A4.
5. Compare every name, address and phone number in the pasted block with the visible text on the page and with marketing/nap.json. They must match.

## 5. Roll back

Switch the snippet off in the plugin. No other change is needed. Keep a copy of the previous page source for comparison.

## 6. Done when

- [ ] /locations/ carries one three-store block (and the home page carries none), until the store pages exist; then each store page carries its own block.
- [ ] The Rich Results Test and Schema Markup Validator show no errors on each page that carries a block.
- [ ] Search Console URL Inspection shows the block in the crawled HTML.
- [ ] Every value matches marketing/nap.json.
- [ ] Elie has the list of fields intentionally left out (geo, image, price range, ratings) and why.
