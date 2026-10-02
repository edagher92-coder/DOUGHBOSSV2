# Dough Boss: local and off-site SEO plan (Google Business Profile, citations, reviews, links, digital PR)

Prepared 2026-10-02 for Elie. Status: DRAFT. Nothing in this plan has been launched, submitted, posted, sent or bought. Every action below is for a human to do after review.

Slice: local and off-site SEO for three Sydney south-west shops (Revesby, Bankstown, Roselands Centro), aimed at walk-in trade, corporate, office and event catering enquiries, and the upcoming Minis party-bite line.

## Read this first

- Labels. OBSERVED means I read it at the URL or file shown, on 2026-10-02. INFERRED is my reasoning. [CONFIRM] marks a fact only Elie or the team can supply. This is an internal planning document, so it may carry [CONFIRM]. Nothing customer-facing may.
- The live site was read through a summarising fetch tool (a small model condenses each page), so quoted wording can be imprecise. Re-read any page before relying on a quote. Direct command-line requests to doughboss.com.au return HTTP 406 from the host's ModSecurity firewall, so everything about the live site comes from the fetch tool.
- No numbers were invented. There is no search volume, CPC, ranking, review target, conversion rate or budget here. Where a number is needed, the plan states the formula and what input is missing. Third-party benchmarks are cited with URL and date, or not used.
- Tenant separation. Dough Boss is its own business. No Snow Flow or Slushie Co material, skill, pricing, voice rule or labour rate was used.
- Practical guidance, not legal advice. Items flagged LAWYER or REGULATOR in docs/marketing/research/compliance-au.md still apply.
- Platform pages change. Google's help pages, category names and attribute lists were read on 2026-10-02. Re-check the dashboard wording at the time of the task.

## 1. What exists today (OBSERVED)

| Item | Finding | Source |
|---|---|---|
| Site | WordPress with the DoughBoss plugin and theme. Core WordPress sitemap at /wp-sitemap.xml. robots.txt blocks only /wp-admin/. No Yoast or Rank Math signals in the pages read. | doughboss.com.au/robots.txt, /sitemap.xml, home page (fetch tool) |
| Pages in the sitemap | /, /menu/, /locations/, /about-us/, /catering/, /wholesale/, /franchising/, /terms-conditions/, /privacy-policy/, /kitchen/, /order/, /track-order/, /vouchers/, /student-vouchers/. Core pages last modified in 2022 and 2023. | /wp-sitemap-posts-page-1.xml |
| Menu item pages | The sitemap also lists `doughboss_item` and `doughboss_cat_pkg` post types. What those pages show is not confirmed. | /sitemap.xml |
| Staff and utility pages | /kitchen/ and /track-order/ are in the public sitemap. | same |
| Title tags | Home "Dough Boss \| Fresh Manoush, Pies & Catering Sydney". Locations "Dough Boss Locations \| Lebanese Bakery Sydney – Dough Boss". Catering "Dough Boss Catering \| Mini Manoush & Pies Sydney – Dough Boss". | fetch tool |
| NAP on the site | Revesby "Shop 12/25 Selems Parade". Bankstown "462 Chapel Road" with no plaza line. Roselands "Shop MM03, Roselands Drive". Phones match the typed store data. | /locations/ (fetch tool) versus src/lib/data/catalogue.ts |
| Catering page | Lists "mini manoush, pizzas, pies, wraps and platters", an allergen statement, no prices, lead time "depends on the date, quantity and menu mix", and a separate catering phone 0422 487 487 and catering@doughboss.com.au. The online form was noted as pending. | /catering/ (fetch tool) |
| Online ordering | The /order/ page (last modified 2026-07-31) still said "Online ordering is coming soon", which conflicts with the project brief that says Revesby pickup is live. | /order/ (fetch tool) |
| Student vouchers | /vouchers/ and /student-vouchers/ show a $5 student voucher for education-email holders, single use, while the day's allocation lasts. No Snow Boss mention. | fetch tool |
| Structured data | None seen in the pages read. | fetch tool |
| Existing Google listings | Unknown. A competitor-research snippet mentioned a "Doughboss" listing at 462 Chapel Road, Bankstown (unverified). | docs/marketing/research/competitors.md |

Store data used everywhere in this plan comes from src/lib/data/catalogue.ts, which was copied from the owner-published locations page. It is not yet owner-confirmed, and it states no public-holiday hours.

## 2. Principles

1. One identity. Name, address and phone are written once (marketing/nap.json) and copied everywhere. The unit test proves nap.json equals the typed store data.
2. One profile per shop. Each shop is a real storefront, so each gets its own Google Business Profile at its own address with its own phone number. No profile at a virtual or unstaffed address, and no separate "Dough Boss Catering" profile (compliance-au.md section 9).
3. Only claims the ledger confirms. Descriptions, services, posts and review replies use store data and ledger-proposed claims only. No superlatives, ratings, review counts, prices, offers, delivery area, lead times, capacity, halal, organic, "fresh" or "authentic".
4. Earn it. Reviews, links and press are earned. No incentives, review gating, paid links, link schemes or fake accounts.
5. Start now. Track A needs no new build. Track B waits for the new routes.

## 3. Prerequisites and ownership (do these before A1)

- [ ] Account ownership. Create a company-controlled Google account (a shared business email, not a personal Gmail) to own the three profiles, Search Console and Tag Manager. Give an agency manager access, not the password. [CONFIRM: which legal entity and brand owner is entitled to claim each Dough Boss listing, because the project has an open question on entity and brand ownership. Claim under the entity that owns the shopfront and the trading name.]
- [ ] Legal entity name and ABN. [CONFIRM.] Needed for the Spam Act sender block, Apple Business Connect and ABN-linked directories.
- [ ] Signage check. A staff member photographs each shopfront sign. The listing name must match it (Google's rule: real-world name, no taglines, no keywords; https://support.google.com/business/answer/3038177, retrieved 2026-10-02). [CONFIRM: exact signage wording per store.]
- [ ] Phone check. Each shop's number must be answered during trading hours, and able to receive a verification call or text. [CONFIRM, especially the 0466 mobile-format number at Roselands.]
- [ ] Duplicate check. Search Google Maps and Apple Maps for each address and number before claiming.
- [ ] Claims ledger. The lead adds the proposed claims in each marketing/gbp/<store>.md section 11 to src/content/ledger.ts as confirmed with a source, after Elie confirms them. None exists today.

## 4. Track A: start now on the existing WordPress site

Owner key: Elie (decisions, access, photos), Team (shop managers and staff), Agency (a hired local SEO or web agency, or the build team). Effort is a planning estimate in working time, not a quote. Expected effect is qualitative only: no figure is promised.

| ID | Task | Owner | Effort (estimate) | Expected effect | Done when |
|---|---|---|---|---|---|
| A1 | Claim and verify the three Google Business Profiles | Elie and Team (a person at each shop), Agency may drive | Small per shop, plus waiting for postcards | The shops become eligible to appear in Maps and the local pack, and Elie controls the information | Each profile shows verified in the dashboard, with the owner account and a manager recorded |
| A2 | Fix NAP consistency | Elie decides, Agency edits | Small | Consistent data helps search engines match the shop across sources and avoids split signals | The website footer, /locations/ page, every profile and the JSON-LD match marketing/nap.json to the character; the unit test passes |
| A3 | Configure each profile fully (categories, description, services, attributes, hours, links, photos, posts) | Agency drafts, Elie approves, Team supplies photos | Medium | A complete profile is more useful to customers and has more chances to match searches | Section 12 of each marketing/gbp/<store>.md is ticked |
| A4 | Indexing and firewall check | Agency | Small | Makes sure search engines can reach and read the site, and that staff-only pages do not appear | Search Console URL Inspection live test passes for /, /locations/, /catering/, /menu/; /kitchen/ and /track-order/ are noindex and out of the sitemap |
| A5 | WordPress on-page fixes: one SEO plugin, titles and descriptions, location page content | Agency | Medium | Clearer snippets and cleaner indexing; better relevance for local searches | marketing/wordpress/seo-plugin-checklist.md is fully ticked |
| A6 | Paste the Bakery JSON-LD on the live site | Agency | Small | Gives search engines machine-readable shop data; not a ranking factor on its own (Google's John Mueller, quoted in the seo-local reference) | marketing/wordpress/install-guide.md section 6 is ticked |
| A7 | Search Console and Bing Webmaster verification, sitemap submitted | Agency, with DNS access from Elie | Small | Lets us see what is indexed and what people search | Both properties verified; sitemap read without errors |
| A8 | GA4 and Tag Manager (coordinate with the analytics workstream) | Agency | Medium | Lets us measure enquiries and calls by source | One GA4 property and container exist under the company account; consent is respected; no personal data is sent to ad or analytics tags |
| A9 | Core citations in order: Apple Business Connect, Bing Places, Facebook Page, Instagram, then Australian directories | Agency or Team | Medium | Consistent listings on the places people and assistants check | marketing/citations.csv rows for the priority set read status done, each with the NAP match noted |
| A10 | Review program | Team (shops), Elie sets policy | Small to set up, then steady | A steady flow of real reviews keeps a profile active and helps customers decide | Cards and QR codes are live; opt-in is on the forms; replies follow the policy |
| A11 | Local links | Elie (relationships), Agency | Medium, spread over months | Local links and mentions support local organic ranking | At least the first three high-priority rows in link-targets.csv are actioned, with outcomes logged |
| A12 | Digital PR | Elie (spokesperson), Agency (outreach) | Medium, spread over months | Earned mentions and links; names the business to new audiences | At least one angle in digital-pr-angles.md has its facts confirmed and a real pitch sent by a human |
| A13 | Reporting | Agency | Small, monthly | Shows what is working | A one-page monthly report covers the measures in section 6 |

### A1: Claim and verify the three profiles

Steps for each shop:

1. Search Google Maps for the address and phone. If a listing exists, claim it or request ownership through its own ownership flow. Do not create a second one. [CONFIRM the exact in-dashboard steps.]
2. Sign in with the company Google account. Add the profile with the exact name, address and phone from marketing/nap.json.
3. Choose the category from marketing/gbp/<store>.md section 2.
4. Choose a verification method. Google lists these: phone or SMS, email, live video call, postcard, and video recording. Which ones appear depends on business type, public information, region and business hours (https://support.google.com/business/answer/7107242, retrieved 2026-10-02). Postcard codes mostly arrive within 14 days and expire after 30 days. Google reviews submitted verification evidence within up to five business days. Verification is instant when the business's website is verified in Search Console under the same account, or through bulk verification, which is for businesses with many locations, so it will not apply to three. [CONFIRM which methods the dashboard offers for each shop.]
5. Have a staff member at the shop on hand for the call, text or postcard, and keep the postcard safe.
6. Record the result: date verified, owner account, managers, and the Place ID (needed for the review link).

Special case, Bankstown: the shop is shared with a second brand (Snow Boss). That brand needs its own profile under its own name when it opens. Never put it in the Dough Boss profile name, categories or description.

### A2: NAP consistency

- Decide the canonical forms once. The typed data writes "12/25 Selems Parade", "462 Chapel Rd", "Roselands Dr". The live site writes "Shop 12/25 Selems Parade", "462 Chapel Road", "Roselands Drive". Check each in the Australia Post address lookup and Google's address picker, then pick one form. [CONFIRM.]
- Phone numbers: the three shop numbers are canonical. The catering line 0422 487 487 on the live /catering/ page is not in the typed data, so it is not part of per-store NAP. [CONFIRM whether it is official and whether it should appear on listings.]
- Email addresses seen on the live site: orders@ and catering@doughboss.com.au. [CONFIRM monitored.] Never use a personal email address on a listing.
- Make the website match the profiles: footer, /locations/ and contact blocks. Then fix the old variants wherever they appear (social bios, directories, printed material).
- Business name: "Dough Boss" on the three profiles. src/lib/seo.ts emits "Dough Boss Revesby" and similar as the schema name, so Elie chooses one rule (nap.json business.schemaNameNote).

### A3: Profile configuration

Everything is drafted in marketing/gbp/revesby.md, bankstown.md and roselands.md: categories (Bakery primary; Caterer, Pizza and Lebanese restaurant as secondary options, with the reasoning and the Google sources), a description under 750 characters written only from store data, services, attributes with every unverified one flagged, hours, links with UTM, a photo shot list, an 8-week post calendar and a website FAQ seed list.

Points that matter:

- Primary category is the strongest local-pack signal in the seo-local reference (a third-party study, Whitespark 2026). Bakery is the recommended primary because the owner describes the business as a bakery and it completes Google's "This business IS a" test.
- Attributes: never tick halal, vegetarian or vegan, wheelchair-accessible, or delivery without confirmation. A wrong attribute is a public claim.
- Special hours for public holidays are unknown and must be set per shop before each holiday, using the process in each GBP file.
- Messaging: do not switch on any chat or messaging feature unless the dashboard offers it today and a person will answer within trading hours. [CONFIRM the current availability.]
- Google Q&A: the seo-local reference says Google ended its Q&A API on 2025-11-03 and that public Q&A is reportedly being phased out. The FAQ seeds go on the website store pages instead.
- Photos must be real, from the shop, and meet Google's file rules (https://support.google.com/business/answer/6103862, retrieved 2026-10-02). No 3D renders and no stock.
- Posts do not directly affect ranking per a third-party source cited in the seo-local reference, but they give the profile fresh, honest content. Keep them factual and small.

### A4: Indexing and firewall check

1. In Search Console, run URL Inspection and Test Live URL on /, /locations/, /catering/ and /menu/. A plain command-line request received HTTP 406 from ModSecurity on 2026-10-02, so confirm Googlebot and Bingbot are not blocked. If they are, ask the host (Crazy Domains) to allow verified Google and Bing crawlers (https://developers.google.com/crawling/docs/crawlers-fetchers/verify-google-requests describes how to verify Googlebot).
2. Set /kitchen/ and /track-order/ to noindex and remove them from the sitemap. Confirm /kitchen/ is protected, because a staff screen should not be public.
3. Look at the `doughboss_item` and `doughboss_cat_pkg` pages. If they are thin or duplicate, noindex them.
4. Merge /vouchers/ and /student-vouchers/ with a 301 so only one is indexed. [CONFIRM which.]

### A5: WordPress on-page fixes

- Install one SEO plugin (Yoast or Rank Math free). Both cover titles, descriptions, canonicals, sitemaps and noindex. Multi-location schema is a paid feature in each, so paste our JSON-LD instead (install-guide.md).
- Replace the page titles and descriptions with the drafts in seo-plugin-checklist.md. The current home title says "Fresh", which is a claim the ledger must confirm.
- Give each shop a page that is not a copy of the others. The seo-local reference describes a "swap test": if the city name can be swapped and the page still reads true, it is a doorway page. A store page needs real, store-specific content (the shop's address, hours, photos, directions, how to enquire about catering from that shop). The route contract provides /locations/revesby, /locations/bankstown and /locations/roselands, which the on-site workstream is building. Until they exist, improve /locations/.
- Add tap-to-call links using the E.164 numbers in nap.json, and an embedded map per shop.

### A6: JSON-LD

Done as files in marketing/wordpress/, generated by running src/lib/seo.ts. Place the three-store block on /locations/ today and each shop's block on its own page when it exists (Google's guidance is markup on each location's page; https://developers.google.com/search/docs/appearance/structured-data/local-business, retrieved 2026-10-02). Do not add geo, images, price range or ratings until verified.

### A9: Citations

marketing/citations.csv lists 31 platforms with verified hosts. Order of work:

1. Search, map and social: Google Business Profile (A1), Apple Business Connect, Bing Places, Facebook Page, Instagram, LinkedIn Company Page.
2. Australian directories with a free route verified on 2026-10-02: Yellow Pages and True Local (one shared sign-up), Localsearch, White Pages, StartLocal, Hotfrog, Cylex, Brownbook. Decline paid upsells unless Elie approves a budget.
3. Data and review sites: Foursquare, Yelp, Tripadvisor. Never offer anything for a review on any of them.
4. Local and industry: Canterbury Bankstown Chamber of Commerce, Western Sydney Business Connection, council pages, Restaurant & Catering Australia, Baking Association of Australia, ATDW and Weekend Notes. These cost money or time and need Elie's decision.
5. Delivery marketplaces (Uber Eats, Menulog, DoorDash) are a decision for Elie, not a task. Joining means a delivery promise, which needs a confirmed area, fee and lead time.

For each listing: use the nap.json form, the same category family, the same website link with a UTM (utm_source=<directory>&utm_medium=referral&utm_campaign=<store>-profile), and note the date. Update the status column from todo to done only when the listing is live and the NAP is checked. Watch for aggregator copies that put old data back.

### A10: Review program

Everything is in marketing/reviews/: request-scripts.md (SMS, email, in-store card and counter wording), short-links-and-qr.md (Place ID, short link and QR plan), and response-policy.md (replies, allergen handling and escalation).

Key rules: no incentives of any kind; no review gating; same link for every customer; no staff, family or friends reviews; opt-in consent for SMS and email; allergen replies never state safety (Google's incentive policy: https://support.google.com/business/answer/3474122; ACCC: https://www.accc.gov.au/business/advertising-and-promotions/online-reviews; both retrieved 2026-10-02).

Cadence: a third-party guideline in the seo-local reference (Sterling Sky) says rankings can drop if no new reviews arrive for about three weeks. Treat it as a reason to keep steady, not as a target. Do not set a target count. After four weeks, record the baseline for each shop and decide a goal.

### A11 and A12: Links and digital PR

marketing/links/link-targets.csv has 25 legitimate local opportunities with sources: chambers and networking, council relationships and events, community organisations, universities and TAFE, health, press and media services, trade bodies and the Snow Boss partner. marketing/links/digital-pr-angles.md has five story angles, each with the hook, audience, facts needed, facts we can support now and what Elie must confirm, plus a held Minis angle.

Rules: no paid links, no link exchanges, no fake accounts, no invented quotes. Pitch real people through published contact routes, and only with facts that are in the ledger. Relationship first: most of these are conversations, not link requests.

The Snow Boss partnership is a real angle. The documents I read support only this: the owner's locations page says Snow Boss is coming soon in the Bankstown shop, the pages carry a "Dough Boss x Snow Boss collaboration" line, and the voucher project report describes a joint student-voucher campaign for the Bankstown launch. Everything else about it (what Snow Boss is, the dates, the legal relationship) is [CONFIRM].

## 5. Track B: when the new app is live

This track starts only when the new routes are live on doughboss.com.au (served by the WordPress companion plugin or the app). Route contract: /, /#order, /#minis, /#locations, /catering, /catering/corporate, /catering/office-breakfast, /catering/events, /catering/minis, /locations/revesby, /locations/bankstown, /locations/roselands.

| ID | Task | Owner | Effort (estimate) | Expected effect | Done when |
|---|---|---|---|---|---|
| B1 | Redirect map from old URLs to new routes | Agency or build team, Elie approves | Medium | Keeps the value of existing pages and avoids dead links | Every row in the table below is live, tested, and logged |
| B2 | Switch GBP and citation links to the final store URLs | Agency | Small | Sends each profile's visitors to the page about that shop | All three GBP website links use the final URLs from nap.json; citation rows updated |
| B3 | Replace interim JSON-LD with per-store blocks | Agency | Small | Markup sits on the page it describes | Each store page carries its own block; /locations/ block removed |
| B4 | Sitemap and canonical check; resubmit | Agency | Small | Search engines learn the new URLs | Sitemap lists only canonical, indexable routes; submitted in Search Console and Bing; no canonical points to a redirect |
| B5 | Monitoring for four to six weeks after launch | Agency | Small, weekly | Catches broken links and lost pages early | A weekly note shows crawl errors, indexed pages, 404s and top landing pages, with fixes logged |
| B6 | Review and retire interim assets | Agency | Small | Removes duplicates | No interim URLs remain on profiles, directories or schema |

### B1: Redirect map (draft for the build team)

Principles (https://developers.google.com/search/docs/crawling-indexing/site-move-with-url-changes, retrieved 2026-10-02): map old URLs to new ones one for one; do not send many old URLs to one irrelevant destination such as the home page; keep the redirects for as long as possible, generally at least one year; submit the new sitemap; watch Search Console. The domain stays doughboss.com.au, so the Change of Address tool is not needed.

| Old URL (live WordPress) | New route | Redirect | Notes |
|---|---|---|---|
| / | / | none | Same URL |
| /locations/ | /#locations | 301 | Fragment targets only work for browsers. If the build team can add a /locations hub route, redirect there instead and keep the equity of this indexed page. [CONFIRM: lead decision] |
| /catering/ | /catering | 301 | Same intent. The slash form must be settled (see below) |
| /menu/ | /#order | 301 | The menu is part of the order section in the contract. [CONFIRM: no separate menu route] |
| /order/ | /#order | 301 | Only after online pickup is live; until then keep /order/ as is |
| /about-us/ | No route in the contract | Keep the WordPress page | Do not redirect to / (irrelevant destination). [CONFIRM] |
| /wholesale/ | No route in the contract | Keep the WordPress page | Wholesale is a different intent from catering. [CONFIRM] |
| /franchising/ | No route in the contract | Keep the WordPress page | [CONFIRM] |
| /vouchers/ and /student-vouchers/ | No route in the contract | Keep one, 301 the other to it | [CONFIRM which is canonical] |
| /terms-conditions/ and /privacy-policy/ | Same slugs | Keep | Legal URLs should not move; analytics and ad platforms link to them |
| /kitchen/ and /track-order/ | None | Keep, noindex | Not search pages |
| `doughboss_item` and `doughboss_cat_pkg` URLs | Menu in /#order | 301 only the ones that were indexed and linked | URL pattern not confirmed. [CONFIRM from Search Console after A4] |
| New in the contract with no old page | /catering/corporate, /catering/office-breakfast, /catering/events, /catering/minis, /locations/revesby, /locations/bankstown, /locations/roselands | none | New pages. Link them from the hub and from the profiles |

Rules:

- Pick one URL form (with or without a trailing slash). WordPress uses a trailing slash today and the route contract does not. Redirect the other form to the chosen one, and make every canonical tag and sitemap entry absolute and consistent.
- Avoid chains. Test every old URL with a status check: one hop, 301, to a 200.
- Use 301 for permanent moves, not 302.
- Page content on the target must be about the same thing as the page it replaces. If the content is not equivalent, do not redirect.
- Before launch, export the indexed URLs and top landing pages from Search Console and add any not in this table.

### B2 to B6

- B2: swap each profile's website link from the interim URL to the final URL in nap.json. Keep the UTM.
- B3: use the per-store files with `url` set to the store page.
- B4: the sitemap lists only routes that should be indexed: the contract routes plus any kept WordPress pages. Remove staff and utility URLs.
- B5: weekly for four to six weeks (our rule): Search Console coverage and queries, Bing, 404s from server logs, GBP website clicks, directions and calls. Record any drop and fix it, before reading it as a trend.
- B6: remove every interim URL.

## 6. Measurement (formulas and inputs, no invented targets)

| Measure | Source | Formula or note | Needs |
|---|---|---|---|
| Profile views and actions (calls, directions, website clicks) | Google Business Profile Performance | Count per shop per month; compare month on month | Verified profiles |
| Website sessions from profiles | GA4 | Filter utm_source=gbp and utm_medium=gbp; split by utm_campaign=<store>-profile | A8 |
| Catering enquiries by source | Form entries plus GA4 | Enquiries per source divided by sessions from that source | A8 and the enquiry form tracking |
| Review velocity | Profile | New reviews per month per shop; record the real count and average with it | Baseline after four weeks |
| Review response time | Our log | Time from review to reply | Policy target [CONFIRM] |
| Indexed pages, impressions, clicks, queries | Search Console | Compare to the page list in Section 1 | A7 |
| Citation accuracy | marketing/citations.csv | Rows done with NAP checked, divided by rows in the priority set | A9 |
| Links earned | link-targets.csv | Count of live, earned links by type, with the URL and date | A11 |
| Cost per enquiry | Finance | Spend on tools and services divided by catering enquiries from local sources | [CONFIRM: budget and who pays] |

Do not state any target until a baseline exists. Do not publish a rating without the real count.

## 7. Compliance flags

- Claims: every customer-facing line maps to the ledger (ACL; compliance-au.md section 1). The banned list used in the unit test covers superlatives, ratings, "fresh", "authentic", halal, prices, offers, delivery promises and similar.
- Reviews: no incentives, no gating, no fake reviews (ACCC; Google). The student voucher scheme must never mention reviews.
- Spam Act: SMS and email review requests need express opt-in consent, a sender block and an unsubscribe. Outreach emails need the published-address test and evidence (compliance-au.md section 4). REGULATOR (ACMA) for the current rules.
- Privacy: no customer data to ad platforms without consent and a privacy-policy disclosure (compliance-au.md section 6). LAWYER on the small-business exemption.
- Allergens: public replies never say an item is safe or free of an allergen. REGULATOR (NSW Food Authority) for any reporting duty after a reaction.
- GBP guidelines: real-world name, no keywords in the name, no virtual office, no incentives, factual description with no links or promotions (compliance-au.md section 9).

## 8. Open decisions and facts needed

All are repeated in the hand-off report. In short: signage names, address wording, the catering phone number, the entity and ABN, the account that owns the profiles, the profile category choices, the confirmed claims for the ledger, public-holiday hours, whether online pickup is live at Revesby, which SEO plugin to install, whether to join the chamber, whether to join delivery marketplaces, and the facts needed for each PR angle.

## 9. Files delivered

| File | Purpose |
|---|---|
| marketing/nap.json | Single source of truth for name, address and phone, tested against src/lib/data/catalogue.ts |
| marketing/gbp/revesby.md, bankstown.md, roselands.md | Complete draft profile per shop |
| marketing/citations.csv | 31 directories and platforms with verified hosts, all todo |
| marketing/reviews/request-scripts.md, short-links-and-qr.md, response-policy.md | Review program |
| marketing/links/link-targets.csv, digital-pr-angles.md | 25 link targets and 5 story angles |
| marketing/wordpress/jsonld-*.html, install-guide.md, seo-plugin-checklist.md | Copy-and-paste artefacts for the live site |
| tests/unit/marketing-seo-external.test.ts | Proves the data files and customer-facing text meet the rules |

## 10. Sources read (all retrieved 2026-10-02 unless stated)

- Google Business Profile guidelines: https://support.google.com/business/answer/3038177
- Choose a category: https://support.google.com/business/answer/7249669
- Verify your profile: https://support.google.com/business/answer/7107242
- Asking for reviews and incentives: https://support.google.com/business/answer/3474122
- Contributed content policy: https://support.google.com/contributionpolicy/answer/7400114
- Photos and video: https://support.google.com/business/answer/6103862
- Special hours: https://support.google.com/business/answer/3039617
- Place IDs: https://developers.google.com/maps/documentation/places/web-service/place-id
- LocalBusiness structured data: https://developers.google.com/search/docs/appearance/structured-data/local-business
- Site moves with URL changes: https://developers.google.com/search/docs/crawling-indexing/site-move-with-url-changes
- Category list (third-party, updated 2026-09-08): https://www.lobstr.io/blog/google-business-categories
- ACCC online reviews: https://www.accc.gov.au/business/advertising-and-promotions/online-reviews
- Rank Math multiple locations: https://rankmath.com/kb/multiple-locations/
- Yoast Local SEO: https://yoast.com/features/local-seo/
- WPCode Lite: https://wpcode.com/lite/
- Live site pages on doughboss.com.au, read through the fetch tool.
- Local files: docs/marketing/research/*.md, src/lib/data/catalogue.ts, src/lib/seo.ts, src/content/ledger.ts, and /home/user/DOUGHXSNOW (web/locations, web/catering, docs).
