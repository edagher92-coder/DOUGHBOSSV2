# Yoast / Rank Math settings checklist for doughboss.com.au

DRAFT. Nothing here has been changed on the live site. A human with wp-admin access works through it after Elie approves. Prepared 2026-10-02.

## 0. What we observed on the live site (read-only, 2026-10-02, through a summarising fetch tool, so re-check in wp-admin)

- The sitemap is the WordPress core sitemap at /wp-sitemap.xml (robots.txt points to it). Its index lists posts, pages, a `doughboss_item` post type, a `doughboss_cat_pkg` post type, the category taxonomy and the `doughboss_category` taxonomy. The core sitemap naming means no Yoast or Rank Math is replacing it, so there is probably no SEO plugin yet. [CONFIRM in wp-admin.]
- Page sitemap URLs: /, /menu/, /locations/, /about-us/, /catering/, /wholesale/, /franchising/, /terms-conditions/, /privacy-policy/, /kitchen/, /order/, /track-order/, /vouchers/, /student-vouchers/. Several core pages show last-modified dates in 2022 and 2023.
- /kitchen/ is almost certainly the staff kitchen board and /track-order/ is a customer order lookup. Neither should be indexed. They are in the sitemap today.
- robots.txt blocks only /wp-admin/ (with admin-ajax allowed).
- Title tags seen: home "Dough Boss | Fresh Manoush, Pies & Catering Sydney"; /locations/ "Dough Boss Locations | Lebanese Bakery Sydney – Dough Boss"; /catering/ "Dough Boss Catering | Mini Manoush & Pies Sydney – Dough Boss". The home title says "Fresh", which is a claim the ledger must confirm.
- A plain command-line request returned HTTP 406 from ModSecurity. Confirm Googlebot and Bingbot get through (docs/marketing/02-seo-external.md, task A4).

## 1. Pick one plugin, then do not install the other

| | Yoast SEO | Rank Math |
|---|---|---|
| Titles, meta descriptions, canonicals, sitemaps, noindex controls, breadcrumbs | Free version covers these | Free version covers these |
| Multiple locations schema | Separate premium Local SEO product (https://yoast.com/features/local-seo/) | Pro only (https://rankmath.com/kb/multiple-locations/) |
| What that means for us | Paste our own JSON-LD for three stores (install-guide.md) | Same |

Retrieved 2026-10-02. Who decides: Elie or the agency. Either free plugin is enough. Install only one. [CONFIRM: choice.] If the companion plugin being built later outputs its own meta and schema, turn the SEO plugin's overlapping modules off so each page has one canonical, one title and one set of schema.

## 2. General settings

- [ ] Site name: Dough Boss. Separator and title template consistent. Language: English (Australia).
- [ ] Knowledge graph or organisation name: Dough Boss. Logo: the real logo file. [CONFIRM: logo.]
- [ ] Social profiles: https://instagram.com/doughboss [CONFIRM it is owned and active]. Add the Facebook Page when it exists.
- [ ] Default social image: a real photo from a shop. No 3D render that misrepresents a shop.
- [ ] Turn the plugin's Organization and LocalBusiness schema off if you paste our JSON-LD, or use the bakeries-only file only (install-guide.md).
- [ ] Do not enable the plugin's local business module for a single address. Dough Boss has three.

## 3. Indexing controls

- [ ] /kitchen/: noindex, and remove from the sitemap. Confirm it is also behind a login or other protection. A staff screen should not be public. [CONFIRM with the developer.]
- [ ] /track-order/: noindex, remove from the sitemap.
- [ ] Order confirmation, thank-you, cart and checkout pages, if any: noindex.
- [ ] /student-vouchers/ and /vouchers/: keep indexable only if Elie wants them found. They are one concept on two URLs. Pick one, 301 the other to it, and canonicalise. [CONFIRM which.]
- [ ] /wholesale/ and /franchising/: confirm they are live pages Elie wants indexed.
- [ ] `doughboss_item` and `doughboss_cat_pkg` post types: look at what these pages show on the front end. If they are thin, near-duplicate pages (one per menu item or package), set them to noindex and remove them from the sitemap. If they are useful, give each a unique title and description. [CONFIRM.]
- [ ] Internal search results, tag archives, author archives, date archives and attachment pages: noindex, unless there is a reason.
- [ ] After changing, submit the sitemap again in Search Console and Bing Webmaster Tools.

## 4. On-page titles and descriptions (draft, for the existing WordPress pages)

Length targets are 60 characters or fewer for a title and 155 or fewer for a description. The unit test checks them. These replace the current text on the existing WordPress pages today. The new routes get their own titles from the on-site work.

Home page (/):

```seo-title
Dough Boss | Lebanese Bakery in Sydney's South-West
```

```seo-meta
Dough Boss is a Lebanese bakery with shops in Revesby, Bankstown and Roselands Centro. See the menu, hours and addresses, or send a catering enquiry.
```

Locations page (/locations/):

```seo-title
Dough Boss Shops | Revesby, Bankstown, Roselands
```

```seo-meta
Addresses, opening hours and phone numbers for the three Dough Boss shops in Revesby, Bankstown and Roselands Centro.
```

Catering page (/catering/):

```seo-title
Catering Enquiries | Dough Boss, Sydney
```

```seo-meta
Send a catering enquiry to Dough Boss. Tell us the date, headcount and dietary needs and we will come back with a quote.
```

Menu page (/menu/):

```seo-title
Menu | Manoush, Pizza and Pies | Dough Boss
```

```seo-meta
See the Dough Boss menu: manoush, pizza and pies. Find your nearest shop in Revesby, Bankstown or Roselands Centro.
```

Order page (/order/): leave the current title until online pickup is confirmed live, then write one that says what the page does.

Store pages, for when /locations/<store> goes live (titles end up unique per shop):

```seo-title
Dough Boss Revesby | Lebanese Bakery, Selems Parade
```

```seo-meta
Dough Boss Revesby is at 12/25 Selems Parade, Revesby. Open seven days. See the menu, call the shop or send a catering enquiry.
```

```seo-title
Dough Boss Bankstown | Lebanese Bakery, Chapel Rd
```

```seo-meta
Dough Boss Bankstown is at 462 Chapel Rd, Little Saigon Plaza. Open Monday to Friday. See the menu, call the shop or send a catering enquiry.
```

```seo-title
Dough Boss Roselands Centro | Lebanese Bakery
```

```seo-meta
Dough Boss Roselands Centro is in Shop MM03, Roselands Dr. Open every day. See the menu, call the shop or send a catering enquiry.
```

Checklist for each:

- [ ] One H1 per page, and it says what the page is (for example "Dough Boss Revesby").
- [ ] The visible address, phone and hours match marketing/nap.json character for character.
- [ ] A tap-to-call link on every phone number (`tel:` with the E.164 number from nap.json).
- [ ] An embedded map, lazy loaded, for each store. [CONFIRM: the existing page already embeds Google Maps.]
- [ ] Image alt text describes the real photo.

## 5. Search Console and Bing Webmaster Tools

- [ ] Add and verify the domain as a property in Google Search Console (https://search.google.com/search-console). Verification by DNS record is cleanest, and needs access to the domain's DNS at the registrar. A human does this. [CONFIRM: who controls DNS.] Alternative: the HTML tag or file method through the header-code plugin.
- [ ] Add and verify the site in Bing Webmaster Tools (https://www.bing.com/webmasters). Bing can import the site from Search Console. [CONFIRM.]
- [ ] Submit https://doughboss.com.au/wp-sitemap.xml, or the plugin's sitemap if one replaces it, in both.
- [ ] Run URL Inspection on /, /locations/, /catering/, /menu/ and check "Test live URL" returns the page and the rendered HTML (this also tests the firewall).
- [ ] Add the owner or an agency as a user with the right role. Do not share a personal login.
- [ ] Note the properties' owners in the shared handover notes.

## 6. GA4 and Google Tag Manager (coordinate with the analytics plan)

The companion plugin will deliver analytics and consent, so avoid double installation. If something is needed before the plugin ships:

- [ ] Create one GA4 property and one Tag Manager container under a company-owned Google account, not a personal one. [CONFIRM: which account.]
- [ ] Load the container through the same header-code plugin. Fire GA4 and any advertising tags only after consent where required (compliance-au.md section 6; Google's consent mode guide: https://developers.google.com/tag-platform/security/guides/consent).
- [ ] Do not send names, emails, phone numbers or dietary details to any ad platform or analytics tag.
- [ ] Mark conversions the business actually has: a catering enquiry submitted, a click-to-call, a directions click. Use the UTM convention: lower-case, hyphenated, utm_source=<platform>, utm_medium=<cpc|paid-social|email|organic-social|referral|gbp|qr>, utm_campaign=<segment>-<offer>-<area>.
- [ ] Add a GA4 internal-traffic filter for staff and the shops' own devices. [CONFIRM: the IP addresses.]

## 7. Done when

- [ ] One SEO plugin is installed, with sitemap, canonicals, titles and descriptions set as above.
- [ ] /kitchen/ and /track-order/ are noindex and out of the sitemap.
- [ ] Both Search Console and Bing show the property verified and the sitemap read.
- [ ] URL Inspection's live test succeeds for the four key pages.
- [ ] No page has two sets of schema or two canonicals.
