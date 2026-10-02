# Dough Boss Revesby: Google Business Profile draft

DRAFT. Nothing in this file has been submitted to Google. A human claims, verifies and edits the profile after Elie reviews it. Prepared 2026-10-02.

How to read this file. Fenced blocks tagged `gbp-description`, `gbp-service`, `gbp-post` and `faq-answer` are customer-facing text. The unit test checks every one of them against the banned-terms list and the length limits. Everything outside those blocks is internal planning and may carry [CONFIRM] markers.

## 1. Identity and NAP (from marketing/nap.json, which the test proves equals src/lib/data/catalogue.ts)

| Field | Value |
|---|---|
| Business name | Dough Boss [CONFIRM: exact signage wording for this shop] |
| Address | 12/25 Selems Parade, Revesby NSW 2212 |
| Primary phone | (02) 9774 2286 (+61297742286) |
| Website link (final route) | https://doughboss.com.au/locations/revesby?utm_source=gbp&utm_medium=gbp&utm_campaign=revesby-profile&utm_content=website-link |
| Website link (interim, until the route is live) | https://doughboss.com.au/locations/?utm_source=gbp&utm_medium=gbp&utm_campaign=revesby-profile&utm_content=website-link |
| Menu link (final) | https://doughboss.com.au/?utm_source=gbp&utm_medium=gbp&utm_campaign=revesby-profile&utm_content=menu-link#order |
| Menu link (interim) | https://doughboss.com.au/menu/?utm_source=gbp&utm_medium=gbp&utm_campaign=revesby-profile&utm_content=menu-link |
| Catering enquiry link (final) | https://doughboss.com.au/catering?utm_source=gbp&utm_medium=gbp&utm_campaign=revesby-profile&utm_content=catering-link |
| Catering enquiry link (interim) | https://doughboss.com.au/catering/?utm_source=gbp&utm_medium=gbp&utm_campaign=revesby-profile&utm_content=catering-link |
| Appointment / booking link | Not applicable. Leave empty. |
| UTM rule | utm_source=gbp, utm_medium=gbp, utm_campaign=revesby-profile, utm_content=<link variant>. All lower-case, hyphenated. |

Why the website link goes to the store page and not the home page: a profile should point to the page about that shop, and the three shops should not all send people to the same page. Until `/locations/revesby` exists, use the interim link and swap it the day the route goes live. The swap is a one-minute edit.

Before claiming anything, search Google Maps for each address and phone number. A competitor-research snippet mentioned a possible existing "Doughboss" listing at 462 Chapel Road, Bankstown, which has not been verified. If a listing already exists, claim it or request ownership through the profile's own ownership flow. Never create a second profile for the same shop. [CONFIRM the exact in-dashboard steps, because Google changes them.]

## 2. Categories

Google's rule: choose the fewest categories that describe the core business, complete the sentence "This business IS a", and add a few extra categories for special departments or services, not one for every product (https://support.google.com/business/answer/3038177 and https://support.google.com/business/answer/7249669, both retrieved 2026-10-02). Google does not publish a downloadable list, so names below are checked against a third-party list updated 2026-09-08 (https://www.lobstr.io/blog/google-business-categories, retrieved 2026-10-02) and Google's own examples ("Bakery", "Pizza restaurant"). The exact spelling shown in the Australian dashboard picker is the final word. [CONFIRM in the dashboard picker: spellings such as "Pizza takeaway" versus "Pizza takeout" differ between lists.]

| Role | Category | Reasoning | Status |
|---|---|---|---|
| Primary | Bakery | The owner calls the business "a contemporary Lebanese bakery" on the live site. Primary category is the strongest local-pack signal, and "Bakery" completes "This business IS a". It also fits a manoush and pies range. | Recommended |
| Secondary 1 | Caterer | The business takes catering enquiries, which is the growth goal. Caterer is in the third-party category list cited above. It does not need a staffed catering office, because the shop is the physical location. | Recommended, once catering is confirmed as real service at this shop |
| Secondary 2 | Pizza takeaway (or "Pizza restaurant" if that is the name shown) | Pizza is on the owner's menu line. Add only if pizza is sold at this shop. | [CONFIRM: pizza is sold at this shop] |
| Secondary 3 | Lebanese restaurant | Cuisine signal for "Lebanese" searches. A restaurant category may suggest dine-in, so use it only if the shop has seating or Elie is comfortable with the label. | [CONFIRM: seating and Elie's preference] |
| Considered and rejected | Pie shop, Pastry shop, Halal restaurant, Breakfast restaurant | Pie shop and Pastry shop dilute the primary. Halal restaurant must not be used without confirmed halal status. Breakfast restaurant is a time-of-day claim that has not been confirmed for this shop. | Do not add |

Never put "catering", "corporate" or the suburb into the business name or into a category-like label. Use the Services list and the description for that.

## 3. Description (750 characters or fewer)

Written only from store data and the proposed ledger claims in section 11. No prices, no hours that could go stale, no superlatives, no delivery promise.

```gbp-description
Dough Boss Revesby is a Lebanese bakery at 12/25 Selems Parade, Revesby. The menu covers manoush, pizza and pies, and the shop is open seven days a week. For anything about the menu, call the shop on (02) 9774 2286. We also take catering enquiries for offices, events and parties. Tell us the date, headcount and dietary needs and we will come back with a quote. Dough Boss also has shops in Bankstown and Roselands Centro.
```

- Character count: 423 of 750 (the unit test recounts this).
- Not in the description on purpose: opening hours (they live in the hours field and change on public holidays), links, offers, "fresh", "baked in-house", "since 2009", "halal", prices.

## 4. Services and products

GBP calls these Services or Products depending on category. Whether the Services editor and a Menu or Food menu editor appear for a Bakery primary category is [CONFIRM in the dashboard]. Each line is "Name | description". Descriptions use item names from src/lib/data/catalogue.ts and the owner-published wording that the ledger should confirm.

```gbp-service
Manoush | Manoush on the Dough Boss menu, including za'atar, cheese, lahm bi ajin and shanklish. Call the shop to ask what is ready today.
```

```gbp-service
Pizza | Pizza on the Dough Boss menu. Call the shop to ask what is available.
```

```gbp-service
Pies | Savoury baked pies, including halloumi and spinach.
```

```gbp-service
Catering enquiries | Catering for offices, events and parties. Tell us the date, the headcount and any dietary needs, and we will come back with a quote.
```

```gbp-service
Order for pickup | Order for pickup: choose your items online and collect them from the Revesby shop. You pay at the shop.
```

- Gate: GATED. Add only after Elie confirms online pickup is live and taking real orders at Revesby. The live /order/ page read on 2026-10-02 still said 'Online ordering is coming soon', which conflicts with the brief that says pickup ordering is live. Re-check the page, place a test order, then add this.

Do not add a price to any service line. Do not add a "from" figure. Do not add any product or service that is not already confirmed in the ledger; nothing unannounced is named, hinted at or teased in a service or product line.

## 5. Attributes

Tick only what is verified. A wrong attribute is a public claim about the shop.

| Attribute | Suggestion | Status |
|---|---|---|
| Takeaway (takeout) | Tick | [CONFIRM: yes, expected] |
| Dine-in | Do not tick yet | [CONFIRM: does this shop have seating] |
| Delivery | Do not tick | A delivery promise is a stakes fact. [CONFIRM: delivery offer, area and fee] |
| Halal | Never tick without confirmation | [CONFIRM: halal status and, if certified, the certifier's name] |
| Vegetarian / vegan options | Do not tick yet | Dietary flags in the catalogue are unverified (dietaryVerified is false on every item). [CONFIRM per item] |
| Wheelchair-accessible entrance, seating, toilets, parking | Never tick without an on-site check | [CONFIRM: on-site check by a staff member] |
| Payments (credit cards, NFC mobile payments) | Do not tick yet | The live /order/ page says card and wallet payments arrive "at launch". [CONFIRM: what the till accepts today] |
| Identity attributes (for example "Identifies as ...-owned") | Skip | Optional. Ownership and community attributes are Elie's personal call, and must be true. Do not suggest them in copy or ads. |
| Online ordering / 'Order ahead' link | Tick only after the pickup gate clears | [CONFIRM: pickup live at Revesby, per the gate above] |

## 6. Opening hours and special hours

| Day | Hours (verified data, not owner-confirmed) |
|---|---|
| Monday | 6:30am – 2:30pm |
| Tuesday | 6:30am – 2:30pm |
| Wednesday | 6:30am – 2:30pm |
| Thursday | 6:30am – 2:30pm |
| Friday | 6:30am – 2:30pm |
| Saturday | 6:30am – 2:30pm |
| Sunday | 6:30am – 2:30pm |

Open 7 days. This is the earliest opening of the three shops.

Source: src/lib/data/catalogue.ts (copied from the owner-published locations page; the live /locations/ page agrees, read 2026-10-02).

Public-holiday hours are not stated in any source. Process:

1. Elie or the shop manager decides the trading hours for each NSW public holiday (list: https://www.nsw.gov.au/about-nsw/public-holidays).
2. Enter them in Edit profile, Hours, Add special hours (https://support.google.com/business/answer/3039617). Our rule, not Google's: do it at least 14 days before the holiday.
3. Mark any day the shop is closed as Closed. Do not leave it blank.
4. Repeat the same hours on the website location page and in Apple Business Connect and Bing Places.
5. After the holiday, check that regular hours returned.

## 7. Photo shot list (real photos of the real shop only)

Google's rules: JPG or PNG, 10 KB to 5 MB, 720 px by 720 px recommended and 250 px minimum, in focus, well lit, no heavy filters; video up to 30 seconds, up to 75 MB, 720p or higher (https://support.google.com/business/answer/6103862, retrieved 2026-10-02). Photos must represent reality.

Rules for Dough Boss:

- Real photos taken at this shop only. No stock photos, no 3D renders, no AI-generated food images, no photos from another shop.
- No customer faces. Staff only with their written consent. No photos of children.
- No text overlays, no price tags, no offers in the image.
- File name pattern: doughboss-revesby-<subject>-<yyyymm>-<nn>.jpg, for example doughboss-revesby-shopfront-202610-01.jpg. Keep the original files.
- Add two or three new photos a month. A steady trickle looks alive.

Shot list:

1. Logo, square, at least 720 px. Use the version on the signage. [CONFIRM: logo file from Elie]
2. Cover photo: the shopfront, daytime, signage legible.
3. Shopfront on Selems Parade with the signage legible, daytime, from across the street and from the door.
4. Counter and display as a customer sees it on entry.
5. The oven or bake area only if Elie agrees it can be shown (food-safety and privacy check: no staff faces without written consent).
6. Manoush, close up, on the counter or a plate in the shop, one item per photo: za'atar, cheese, lahm bi ajin, shanklish.
7. Pies, one item per photo: halloumi, spinach.
8. Pizza, if sold at this shop.
9. A catering tray or box set up for a real order, with the customer's permission and no faces, if and when such an order happens.
10. A 10 to 30 second video: the walk from the street to the counter. Shot in one take, no music claims.

Website alt-text pattern for the same images: "<what is in the photo>, Dough Boss Revesby". Plain description, no keyword lists.

## 8. Google Posts: 8-week draft calendar

Each post is 1,500 characters or fewer, uses no claim outside section 11, has one call to action, and contains no emoji, price, offer or exclamation mark. Publish on the Monday shown, then do not edit the text. Posts are what's-new type. Do not use Offer-type posts, because Dough Boss has no confirmed offer.

Audit note (2026-10-02): the dates below are a template, not a schedule. Week 1 falls on Monday 5 October 2026, which is Labour Day, a NSW public holiday (INFERRED from the NSW rule that Labour Day is the first Monday in October; check https://www.nsw.gov.au/about-nsw/public-holidays), and the profile cannot be verified by then anyway. Start week 1 on the first Monday after the profile is verified and the special hours for the next public holiday are set. Never publish a post that states opening hours on a public holiday whose hours are unknown.

#### Week 1 (from Mon 5 Oct 2026): Introduce the store

```gbp-post
Dough Boss Revesby is at 12/25 Selems Parade. We are open seven days, from 6:30am to 2:30pm. Come in and see what is on the menu today, or call the shop on (02) 9774 2286 with a question.
```

- CTA button: Call now (uses the profile phone number). No link.

#### Week 2 (from Mon 12 Oct): Manoush by name

```gbp-post
Manoush at Dough Boss Revesby. The menu includes za'atar manoush, cheese manoush, lahm bi ajin and shanklish manoush. Za'atar manoush is za'atar and olive oil on dough. Cheese manoush is melted cheese on dough. Lahm bi ajin is a spiced minced-meat topping on dough. Ask the team in the shop which ones are ready today.
```

- CTA button: Learn more
  - Final URL: https://doughboss.com.au/?utm_source=gbp&utm_medium=gbp&utm_campaign=revesby-profile&utm_content=post-w2-manoush#order
  - Interim URL (until the route is live): https://doughboss.com.au/menu/?utm_source=gbp&utm_medium=gbp&utm_campaign=revesby-profile&utm_content=post-w2-manoush
- Note: Link target: the home page order section. Interim: /menu/.

#### Week 3 (from Mon 19 Oct): Pies

```gbp-post
Halloumi pie and spinach pie are on the Dough Boss menu. Both are savoury baked pies. If you are collecting something for a team or a group, call the Revesby shop on (02) 9774 2286 and ask what is available.
```

- CTA button: Learn more
  - Final URL: https://doughboss.com.au/?utm_source=gbp&utm_medium=gbp&utm_campaign=revesby-profile&utm_content=post-w3-pies#order
  - Interim URL (until the route is live): https://doughboss.com.au/menu/?utm_source=gbp&utm_medium=gbp&utm_campaign=revesby-profile&utm_content=post-w3-pies
- Note: Link target: the home page order section. Interim: /menu/.

#### Week 4 (from Mon 26 Oct): Catering for work

```gbp-post
Planning a team meeting, morning tea or office lunch? Tell us the date, the headcount and any dietary needs, and Dough Boss will come back with a quote. You can send an enquiry online or call the Revesby shop on (02) 9774 2286.
```

- CTA button: Learn more
  - Final URL: https://doughboss.com.au/catering/corporate?utm_source=gbp&utm_medium=gbp&utm_campaign=revesby-profile&utm_content=post-w4-corporate
  - Interim URL (until the route is live): https://doughboss.com.au/catering/?utm_source=gbp&utm_medium=gbp&utm_campaign=revesby-profile&utm_content=post-w4-corporate
- Note: Link target: /catering/corporate. Interim: /catering/ (the existing page).

#### Week 5 (from Mon 2 Nov): Catering for events

```gbp-post
Birthdays, family gatherings, community events and work functions. Tell us the date, the number of guests and any dietary needs, and we will come back with a quote. Send an enquiry from our website and the team will reply.
```

- CTA button: Learn more
  - Final URL: https://doughboss.com.au/catering/events?utm_source=gbp&utm_medium=gbp&utm_campaign=revesby-profile&utm_content=post-w5-events
  - Interim URL (until the route is live): https://doughboss.com.au/catering/?utm_source=gbp&utm_medium=gbp&utm_campaign=revesby-profile&utm_content=post-w5-events
- Note: Link target: /catering/events. Interim: /catering/.

#### Week 6 (from Mon 9 Nov): Dietary needs and allergies

```gbp-post
Allergies and dietary needs matter. When you order or send a catering enquiry, tell us what you need and we will explain the suitable choices. Our kitchen handles common allergens, so we cannot promise a meal without traces of them. Call the Revesby shop on (02) 9774 2286 and ask.
```

- CTA button: Call now (uses the profile phone number). No link.
- Note: Wording follows the allergen statement the owner already publishes on /catering/. Keep it as is.

#### Week 7 (from Mon 16 Nov): End-of-year catering

```gbp-post
End-of-year team lunch or Christmas party coming up? Send your catering enquiry as early as you can. Tell us the date, the headcount and any dietary needs, and Dough Boss will come back with a quote.
```

- CTA button: Learn more
  - Final URL: https://doughboss.com.au/catering/corporate?utm_source=gbp&utm_medium=gbp&utm_campaign=revesby-profile&utm_content=post-w7-end-of-year
  - Interim URL (until the route is live): https://doughboss.com.au/catering/?utm_source=gbp&utm_medium=gbp&utm_campaign=revesby-profile&utm_content=post-w7-end-of-year
- Note: Link target: /catering/corporate. Interim: /catering/.

#### Week 8 (from Mon 23 Nov): Online pickup

```gbp-post
Order online for pickup from Dough Boss Revesby. Choose your items, then collect them from 12/25 Selems Parade. You pay at the shop. If you would rather talk to someone, call us on (02) 9774 2286.
```

- CTA button: Order online
  - Final URL: https://doughboss.com.au/?utm_source=gbp&utm_medium=gbp&utm_campaign=revesby-profile&utm_content=post-w8-pickup#order
  - Interim URL (until the route is live): https://doughboss.com.au/order/?utm_source=gbp&utm_medium=gbp&utm_campaign=revesby-profile&utm_content=post-w8-pickup
- Note: GATED. Publish only after the pickup gate clears (see Services). If it has not cleared, use the alternate post below.

#### Week 8 alternate (use if the pickup gate has not cleared): Find us

```gbp-post
Find Dough Boss Revesby at 12/25 Selems Parade. We are open seven days, from 6:30am to 2:30pm. Call the shop on (02) 9774 2286 and ask what is on the menu today.
```

- CTA button: Call now (uses the profile phone number). No link.
- Note: No gate.


## 9. Review link and QR

See marketing/reviews/short-links-and-qr.md. Place ID for this shop: [CONFIRM: Place ID, found with Google's Place ID Finder after the profile is verified].

## 10. FAQ seed list for the website (not for GBP)

Google stopped supporting its Q&A API on 2025-11-03 and public Q&A is reportedly being phased out (seo-local skill reference), so these questions belong on the `/locations/revesby` page as a visible FAQ, with FAQ markup only if the page actually shows them. Each answer below uses only store data or the owner-published allergen statement.

### FAQ 1: Where is Dough Boss Revesby?

```faq-answer
Dough Boss Revesby is at 12/25 Selems Parade, Revesby NSW 2212.
```

### FAQ 2: What are the opening hours at Revesby?

```faq-answer
The Revesby shop is open seven days a week, from 6:30am to 2:30pm.
```

### FAQ 3: How do I contact the Revesby shop?

```faq-answer
Call the Revesby shop on (02) 9774 2286.
```

### FAQ 4: How do I ask about catering?

```faq-answer
Send a catering enquiry from our website, or call the shop. Tell us the date, the headcount and any dietary needs, and we will come back with a quote.
```

### FAQ 5: Can you cater for allergies and dietary needs?

```faq-answer
Tell us about allergies and dietary needs when you enquire. We can explain the suitable choices. Our kitchen handles common allergens, so we cannot promise a meal without traces of them.
```

### FAQ 6: What is on the menu?

```faq-answer
The menu covers manoush, pizza and pies. Manoush includes za'atar, cheese, lahm bi ajin and shanklish. Pies include halloumi and spinach.
```

### FAQ 7: Can I order online for pickup at Revesby?

```faq-answer
Online pickup is available at the Revesby shop. Choose your items online, collect them from 12/25 Selems Parade and pay at the shop.
```

- Gate: GATED. Publish only after the pickup gate clears. (The word GATED used to sit inside the answer block, where a copy-paste would have published it; it now lives only in this note.)

Questions customers will ask that we cannot answer yet. Do not publish any answer until the fact is confirmed and in the ledger:

- Do you deliver, and to which suburbs, and what does it cost? [CONFIRM]
- How much notice does catering need, and is there a minimum order? [CONFIRM]
- Is the food halal? [CONFIRM: status and certifier]
- Do you do gluten-free, vegan or dairy-free? [CONFIRM per item; gluten-free is a regulated claim, see compliance-au.md section 7]
- What are the public-holiday hours? [CONFIRM]
- Prices, per-head or otherwise. [CONFIRM: the indicative figures on the owner's earlier catering page are not confirmed and must not be published]

## 11. Ledger claims this profile depends on

The lead should make sure each of these exists in the claims ledger as confirmed with a source before the profile text is published. None exists today: src/content/ledger.ts holds the types and no claims yet.

| Proposed claim id | Wording used here | Source to cite | Status |
|---|---|---|---|
| descriptor-lebanese-bakery | Dough Boss is a Lebanese bakery | owner-site: live doughboss.com.au home page, "a contemporary Lebanese bakery" | Needs Elie's confirmation |
| range-manoush-pizza-pies | The menu covers manoush, pizza and pies | owner-site: live site and src/lib/seo.ts description | Needs Elie's confirmation |
| item-names | Za'atar, cheese, lahm bi ajin and shanklish manoush; halloumi and spinach pies | src/lib/data/catalogue.ts (items named in the project brief) | Needs Elie's confirmation that each is currently sold at this shop |
| item-descriptions | One-line descriptions used in the week 2 post and the services list: za'atar manoush is za'atar and olive oil on dough; cheese manoush is melted cheese on dough; lahm bi ajin is a spiced minced-meat topping on dough; shanklish manoush is shanklish cheese on dough; savoury baked pies | src/lib/data/catalogue.ts item descriptions (the words "fresh" and "fresh-baked" there are deliberately not used) | Needs Elie's confirmation; added by the marketing audit, because the item-names claim covers names only |
| catering-enquiry-quote | Customers send the date, headcount and dietary needs and Dough Boss replies with a quote | owner-confirmed: the plugin's catering enquiry and quote workflow | Needs Elie's confirmation |
| allergen-statement | The kitchen handles common allergens, so a meal without traces cannot be promised | owner-site: live /catering/ page | Needs Elie's confirmation |
| store-revesby-address-hours-phone | Address, hours and phone as in nap.json | owner-site: locations page; src/lib/data/catalogue.ts | Needs Elie's confirmation |
| revesby-online-pickup | Online pickup is available at Revesby and you pay at the shop | owner-confirmed (brief) but contradicted by the live /order/ page on 2026-10-02 | GATED |

## 12. Done-when checklist

- [ ] Existing listing check done in Google Maps; no duplicate created.
- [ ] Profile claimed and verified (see docs/marketing/02-seo-external.md, task A1, for the verification routes).
- [ ] Name, address, phone, hours match nap.json to the character.
- [ ] Categories set as in section 2.
- [ ] Description pasted as in section 3 and saved without a Google warning.
- [ ] Services, attributes and links set as in sections 4, 5 and 1.
- [ ] Logo, cover and at least five real photos uploaded.
- [ ] Week 1 post published; weeks 2 to 8 scheduled in the calendar (a human schedules them; nothing here was scheduled).
- [ ] Review link tested on a phone.
