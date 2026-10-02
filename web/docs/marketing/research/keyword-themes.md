# Dough Boss: keyword themes and search landscape (local B2B and event catering)

Prepared 2026-10-02 for Elie. Companion data file: `web/marketing/keywords.csv` (68 theme rows).

## Read this first

- This is a THEME map, not a volume forecast. The `monthly_volume` column in keywords.csv is deliberately blank on every row. Search volumes and cost-per-click figures are not knowable from public pages; they come from Google Keyword Planner and Search Console once access exists (see "Validation plan").
- Every statement is labelled OBSERVED (seen at a URL on 2026-10-02) or INFERRED (my reasoning). Nothing here is a ranking claim.
- SERP limitation: the search tool used for research is US-biased and returns link lists only. It showed no local pack, Maps results, ads, People Also Ask or AI Overviews. An Australian searcher on a phone in Bankstown will see a different page. I used search only to DISCOVER which businesses and which phrases exist, never to say who ranks where.
- Owner claims (catering offer, store hours, "baked in-house, never frozen", "delivered to Bankstown and nearby", dietary options, INDICATIVE package prices) are taken from the Dough Boss site as stated and are not verified. Anything the owner site does not state is a `[CONFIRM]` gap below.
- Some pages could not be read. The fetch tool returned HTTP 403 for cateringproject.com.au, weekendnotes.com, dishcult.com, timeanddate.com, cbchamber.org.au, and the council's iftar-2026 page; oneflare.com.au redirected to airtasker.com; two guessed hostnames did not resolve (cateringkingz.com.au, assets.oneflare.com.au). A 403 may be the site refusing automated readers rather than a network-policy block. Where a row says "SERP-seen", I saw the page title or URL in search results but could not open the page.

## What the research found

### Who shows up (OBSERVED in search results, discovery only)

| Type of result | Examples seen | What it suggests (INFERRED) |
|---|---|---|
| Home-services marketplaces and directories | Airtasker corporate catering page; Oneflare Revesby and caterer listings; Top4; Easy Weddings | Marketplaces own the generic "catering [place]" results, so owning the brand-plus-suburb term and Google Business Profile matters more than head terms |
| Specialist corporate caterers (category-style pages) | Catering Project (grazing, morning tea, vegan, gluten-free categories); PEN Catering (office catering); Elizabeth Andrews (Melbourne) | The winning pattern is one page per product or occasion, with dietary labels, not one catch-all page |
| Restaurants with a catering arm | Al Aseel (Lebanese, Greenacre catering kitchen, 10 Sydney restaurants per Time Out 2025); Sammy's Catering (Gymea, mezze grazing tables, buffet banquets) | The nearest direct Lebanese competitors position as "restaurant plus catering", not bakery plus catering |
| Local competitors in the stores' suburbs | A Revesby caterer (pizza, meal prep, spit roast, buffet) via Oneflare; a Bankstown theatre and function centre selling a "Mixed Lebanese Savoury Pastry Platter" | The suburb has caterers, but the Lebanese pastry platter is sold by a venue, not a bakery specialist. That is a gap |
| Editorial listicles | Time Out Sydney (iftar, manoush), Broadsheet (Granville manoush), Sitchu | Earned-mention targets for external SEO, not keyword competitors |
| Low-quality or off-market pages | Notion pages, press-release farms, `pagehost.onrender.com`, US caterers (Lebanese Taverna, DC area) | Search tool noise; ignore. Also a warning that US and Melbourne pages leak into AU queries |

### Terms real businesses use (OBSERVED)

- Occasion terms: corporate catering, office catering, breakfast catering, lunch catering, morning tea, meeting catering, staff and client meetings, product launches, Christmas catering, desk lunches, EOFY and Christmas parties (Airtasker, PEN, Elizabeth Andrews).
- Product terms: grazing platters, grazing boxes, lunch grazing, mezze-style grazing tables, drop-off catering, finger food, bite-sized, canapés, Lebanese savoury pastry platter, manoush, "Lebanese pizzas" (Catering Project, Sammy's, Platter Wonderland, Fabulous Catering, Catered By Matt, Bryan Brown venue menu, Time Out).
- Dietary terms: vegan, vegetarian, gluten-free, dairy-free, halal, allergen matrix (Catering Project, Pepperberry, Elizabeth Andrews, Brisk). Halal is used by Melbourne and US caterers in the pages I read. I did not find a Sydney-specific halal catering page in this session, so its search use in Sydney is unconfirmed.
- Spelling: "manoush" is used by Time Out Sydney. Other transliterations (manoushe, manakish, manaeesh) are INFERRED variants; test each in Keyword Planner.

### Ramadan, iftar and Eid: is it genuine seasonal demand?

Short answer: yes for iftar food in this area, but I cannot size it, and catering-specific demand is inferred.

- OBSERVED: Time Out Sydney (published 2025-03-06) lists multiple Sydney venues running iftar offers, including one selling "iftar boxes", and notes Lakemba Nights ran Thursday to Sunday, 6pm to 2am, until 30 March 2025. https://www.timeout.com/sydney/restaurants/where-to-break-fast-this-ramadan
- OBSERVED: The City of Canterbury-Bankstown (the same council area as all three stores) runs Lakemba Nights during Ramadan. The 2026 edition ran 19 February to 15 March 2026 at Haldon Street and The Boulevarde, Lakemba, with about 60 food vendors (council release dated 2026-02-10). https://www.cbcity.nsw.gov.au/your-council/media-centre/australias-biggest-ramadan-cultural-event-returns-to-lakemba
- OBSERVED: Ramadan 2027 is expected to start Monday 8 February 2027, end Tuesday 9 March 2027, with Eid al-Fitr on Wednesday 10 March 2027, subject to moon sighting confirmed by the Australian National Imams Council and local groups (Wego, retrieved 2026-10-02). https://blog.wego.com/ramadan-in-australia/ That is about four months from today.
- NOT VERIFIED: Eid al-Adha 2027 dates. Sources in search conflicted (mid-May versus late May). Do not plan to a date until ANIC confirms.
- INFERRED: corporate and community iftars, iftar boxes, and Eid platters are plausible catering products, but I found no page that proves volume. Operational fit needs checking: the stores close early afternoon (owner claim) while iftar is after sunset, so this needs a pre-order and a pickup or delivery window, not walk-in trade.
- Recommendation: one permanent URL, `/catering/ramadan-iftar`, refreshed yearly, with a pre-order form opening in January. Do not create a new URL each year.

## Theme clusters (as used in keywords.csv)

| Cluster | Themes covered | Priority logic |
|---|---|---|
| A. Corporate and office | Corporate, office, staff and team lunch, meetings, boardroom, boxed lunches, drop-off, recurring orders, Christmas party | Highest commercial value and the stated goal. Christmas party is the nearest seasonal window (INFERRED peak Oct to Dec) |
| B. Breakfast and morning tea | Office breakfast, morning tea, breakfast meeting with manoush | Strong brand fit (bakery, early opening); owner site already lists breakfast spreads |
| C. Platters | Mezze, grazing, Lebanese pastry platter, manoush platter | Product-led terms; contested by specialist platter businesses on "grazing", open on "Lebanese pastry platter" |
| D. Lebanese and Middle Eastern | Lebanese catering Sydney and Bankstown | The brand-defining head term; competitors are restaurants with catering arms |
| E. Party and function | Party, function, birthday, pizza catering, large-group orders | Volume potential; mobile pizza caterers dominate the pizza words, so qualify with Lebanese |
| F. Minis and finger food | Finger food, bite-sized, mini pizzas, party food | Launch-gated; page should not rank for claims the product cannot yet back |
| G. School and community | School events, fundraising, council and community events | Lowest priority; check school food-policy rules before supplying schools |
| H. Ramadan and Eid | Iftar catering and boxes, corporate iftar, Eid platters | Seasonal sprint, 8 Feb to 10 Mar 2027 expected |
| I. Dietary | Vegan, vegetarian, gluten-free, halal | Qualifier traffic and trust signal. Each claim needs owner confirmation |
| J. Local and near me | Store suburbs, LGA, region, near me | Conversion intent; served by location pages and Google Business Profile |

## Page-to-cluster mapping (one primary intent per page)

| Page | Primary intent | Owns | Must NOT target |
|---|---|---|---|
| `/catering` | Hub: Lebanese catering Sydney south west, quote and order | Lebanese catering Sydney or Bankstown, catering near me, quote, region and LGA phrases, large-group orders | Corporate-qualified, breakfast-qualified, or Minis-qualified terms |
| `/catering/corporate` | Corporate and office catering | Anything containing corporate, office, staff, team, meeting, boardroom, drop-off, Christmas party | Breakfast-only and platter-only terms |
| `/catering/office-breakfast` | Breakfast and morning tea for workplaces | Breakfast, morning tea, breakfast meeting, pastry platter for the office | Lunch and generic corporate terms |
| `/catering/platters` (proposed) | Product: mezze, grazing, Lebanese pastry, manoush platters (no "corporate" qualifier) | Mezze platter, grazing platter, lunch grazing, Lebanese pastry platter, manoush catering | Corporate-qualified versions (those go to `/catering/corporate`) |
| `/catering/events` | Party, function, community, school | Party, function, birthday, pizza catering, community and school events, grazing tables | Office terms |
| `/catering/minis` | Finger food and bites (upcoming line) | Finger food, bite-sized, mini pizzas, party food | Anything about products not yet confirmed |
| `/catering/dietary` (proposed, only if it has substantive content) | Dietary-led catering | Vegan, vegetarian, gluten-free, halal | Occasion terms. If thin, fold into `/catering` as a section |
| `/catering/ramadan-iftar` (proposed) | Seasonal | Iftar, iftar boxes, corporate iftar, Eid platters | Year-round terms |
| `/locations/revesby`, `/bankstown`, `/roselands` | Local: visit the store and see catering from that store | "Catering [store suburb]" and nearby suburb modifiers assigned to the nearest store | Corporate, breakfast, or product head terms |

Rules to prevent cannibalisation (INFERRED best practice, consistent with the local-SEO method used):

1. Qualifier decides the page: "corporate", "office", "meeting" always go to `/catering/corporate`; "breakfast" and "morning tea" to `/catering/office-breakfast`; product words with no occasion qualifier to `/catering/platters`.
2. No standalone page per suburb. Swapping the suburb name on an otherwise identical page is a doorway pattern. Suburb modifiers belong on the three real store pages, only where each carries content that is unique to that store (delivery area, local employers, store hours, photos). Padstow, Panania and Milperra map to Revesby; Condell Park maps to Bankstown; Lakemba maps to Roselands in the CSV (assignment is INFERRED from geography and should follow the confirmed delivery radius).
3. The owner site says delivery covers "Bankstown and nearby". That radius is `[CONFIRM]`; do not target or advertise suburbs outside it.
4. Remaining suburbs in the Canterbury-Bankstown LGA that can be added after validation (all confirmed as LGA members via Wikipedia, retrieved 2026-10-02: https://en.wikipedia.org/wiki/City_of_Canterbury-Bankstown): Yagoona, Chester Hill, Campsie, Riverwood, Narwee, Beverly Hills, Kingsgrove.
5. Package prices on the live site are INDICATIVE; do not put them in titles, schema or ads until confirmed.

## First-pass negative keyword list for paid search

Start these as phrase or exact match negatives at campaign level, then review the Search Terms report weekly. Never add a negative blindly if it overlaps a wanted term (for example "recipe" is safe; "cheap" can block valid price-sensitive buyers).

| Negative | Reason |
|---|---|
| jobs, job, careers, employment, hiring, vacancy, resume | Job seekers, not buyers |
| course, courses, training, certificate, TAFE, college, licence, food safety supervisor | Education and compliance queries |
| recipe, recipes, how to make, homemade, dough recipe, pizza dough, flour | DIY cooks; Dough Boss sells food, not ingredients (if retail dough is sold, treat separately) |
| catering equipment, catering supplies, catering trolley, chafing dish, bain marie, disposable, for sale, hire (except where Dough Boss offers hire) | Equipment buyers and hire shoppers |
| food truck, mobile pizza oven, wood fired pizza hire, pizza oven | Different service model (mobile pizza caterers were prominent in SERPs) |
| wedding, wedding catering | Out of focus for this push; reconsider if the owner wants it |
| buffet restaurant, all you can eat, dine in, restaurant booking, reservation | Dine-in intent |
| Uber Eats, Menulog, DoorDash, delivery app | Platform intent |
| menu pdf, template, example, sample menu | Research or document-seeking intent |
| Melbourne, Brisbane, Perth, Adelaide, Canberra, NZ, Auckland, other-state suburbs | Out of area (Melbourne results appeared for Sydney queries) |
| Ramadan timetable, iftar time, prayer times, Ramadan 2027 date, Eid prayer | Informational, no purchase intent (keep only for organic content) |
| free, free samples, coupon, voucher | Low purchase intent; review before applying |
| franchise, wholesale, supplier, distributor (until wholesale is confirmed as an offer) | Trade-supply intent; `[CONFIRM]` whether Dough Boss serves it |
| competitor brand names | Decide deliberately as a conquesting strategy, not by default |

## Long-tail and question-style queries for FAQ content

These are INFERRED natural-language questions; validate in Search Console after launch. Answers must come from the owner or verified sources. Where a figure is needed (price, serves per platter, lead time, radius), leave a `[CONFIRM]` placeholder until supplied.

- How much does office catering cost per person in Sydney?
- How many platters do I need for 20 or 50 people?
- How far ahead do I need to order office catering?
- Do you deliver to Padstow, Panania, Milperra, or Condell Park?
- Is there a minimum order for catering delivery?
- Can you cater a breakfast meeting before 8am?
- What is on a Lebanese mezze platter and does it suit vegetarians?
- Do you have vegan or gluten-free catering options? How do you handle cross-contamination?
- Is your catering halal? `[CONFIRM]` certification before answering.
- What is the difference between manoush and pizza?
- Can you invoice my company or set up a weekly account?
- Can I change or cancel an order, and by when?
- Do you provide plates, serviettes, and serving gear?
- How should I serve and keep manoush warm for a meeting?
- What can I order for an iftar at work or for a community iftar?
- What mini food can I order for a party or kids' birthday (once Minis launches)?

Schema note (background knowledge, verify against Google's current documentation before relying on it): Google narrowed FAQ rich results several years ago, so FAQ markup should be treated as a clarity aid for people and machines, not a guaranteed rich result.

## Validation plan (volumes and CPCs must come from these tools)

1. Google Keyword Planner (free with a Google Ads account; no ad spend needed to open it). Upload the `example_query` column under "Get search volume and forecasts", target Australia and Greater Sydney, and paste the returned monthly search ranges into `monthly_volume`. Do not estimate. Background knowledge to verify: accounts without active spend may see broad volume ranges, and very small suburbs often return thin data, so use suburb terms qualitatively and the Sydney figure for the head terms.
2. Keyword Planner also returns CPC ranges and competition labels. Record them in a separate paid-search sheet, not in this file, and never carry a CPC into a budget without checking it against the Google Ads forecast.
3. Search Console (after the domain property is verified; DNS access is on Crazy Domains): once the new pages are live and indexed, review Performance by Query and Page, filter to Australia, and group by cluster. Real queries replace guesses. Compare against this list after about 28 days of data and re-map any query that lands on the wrong page.
4. Google Business Profile Performance (for each of the three stores): record which search terms trigger the profile. This is the best local signal for "catering near me".
5. Google Ads Search Terms report (once ads run): the source for extending the negative list and for finding new long-tail terms.
6. Triage rule once volumes exist: score each theme on volume, fit with the owner's capacity (confirmed radius, lead times, serve counts), and competition, then promote or demote the `priority` column. Until then, priority reflects commercial fit and evidence only.
7. Re-check dates yearly: Ramadan and Eid move about 11 days earlier each Gregorian year; confirm against ANIC each time.

## Open `[CONFIRM]` items for Elie (these gate several rows)

- Delivery radius and lead times for catering (rows with "nearby", same-day, drop-off).
- Whether the products implied by rows exist: breakfast spreads contents, grazing tables with setup, boxed lunches, sweets or baklava, Minis (size, count, price, launch date).
- Halal certification (the word must not be used without it), and how gluten-free and allergen handling actually works in a wheat-dough kitchen.
- Whether catering accepts online orders, quotes only, or both; whether company accounts and invoicing exist.
- Capacity for banquet-scale and large group orders.
- Whether wholesale or supply to cafes is an offer.

## Sources (all retrieved 2026-10-02)

- Airtasker corporate catering Sydney (fetched): https://www.airtasker.com/catering/corporate-catering/sydney
- Elizabeth Andrews corporate catering, Melbourne (fetched): https://lp.elizabethandrews.com.au/
- Platter Wonderland, Spice News, 2017-04-30 (fetched): https://www.spicenews.com.au/2017/05/platter-wonderland-arrived-sydney/
- Time Out Sydney, Mt Lewis Pizzeria, 2023-05-11 (fetched): https://www.timeout.com/sydney/restaurants/mt-lewis-pizzeria
- Time Out Sydney, where to break fast, 2025-03-06 (fetched): https://www.timeout.com/sydney/restaurants/where-to-break-fast-this-ramadan
- City of Canterbury-Bankstown, Lakemba Nights 2026 release, 2026-02-10 (fetched): https://www.cbcity.nsw.gov.au/your-council/media-centre/australias-biggest-ramadan-cultural-event-returns-to-lakemba
- Wego, Ramadan 2027 in Australia (fetched): https://blog.wego.com/ramadan-in-australia/
- Wikipedia, City of Canterbury-Bankstown (fetched): https://en.wikipedia.org/wiki/City_of_Canterbury-Bankstown
- Lebanese Taverna blog, US (fetched): https://www.lebanesetaverna.com/blog-1-copy-1-1/wseuzsgk62v51vvvushika5tge2z4y-9z7xr-xlmaw-r88el-xdhyx-cpmfr-m424m-ewgdm-p973x-cjzps
- SERP-seen only (page not opened): Catering Project https://www.cateringproject.com.au/grazing, https://cateringproject.com.au/items/folder/morning-tea, https://www.cateringproject.com.au/lunch-grazing, https://cateringproject.com.au/items/category/vegan, https://www.cateringproject.com.au/items/category/gluten-free, https://www.cateringproject.com.au/catering-project-sydney; PEN Catering https://amp.getrocketamp.com/a/s/pen-catering/collections/office-catering; Fabulous Catering https://www.top4.com.au/business/fabulous-catering-110624; Catered By Matt https://raindrop.io/Cateredbymatt/catered-by-matt-56891998; Wheelie Great Pizzas https://www.easyweddings.com.au/WeddingCaterers/Sydney/WheelieGreatPizzas/; Oneflare Catering Kings (Revesby) https://www.oneflare.com.au/b/catering-kings; Pepperberry https://www.oneflare.com.au/b/pepperberry-catering; Brisk https://www.monash.edu/food-and-retail/vendors/brisk-catering; Sammy's https://assets.oneflare.com.au/b/sammy-s-catering-co; Al Aseel https://cbchamber.org.au/member/al-aseel/; Bryan Brown venue menu https://bryanbrowntheatre.cbcity.nsw.gov.au/sites/default/files/2026-02/BLaKC_Catering_Menu.pdf; Corrimal Public School platter note https://corrimal-p.schools.nsw.gov.au/content/dam/doe/sws/schools/c/corrimal-p/notes/2019_Education_Week_PC_Platter_Note_.pdf
