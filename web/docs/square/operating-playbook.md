# Square operating playbook for Dough Boss (three Sydney stores)

Owner: Elie Dagher. Prepared 2026-10-02. Slice: operating playbooks for using Square across planning, kitchen, promotions, sales, adverts, timesheets, catering, multi-location management and security.

Read-only research. No Square API was called, no account was logged into, no Square connector was used. Nothing here has been run against Dough Boss's live Square account, so every Dashboard path is "as documented", not "as tested".

Companion document: `/home/user/DOUGHBOSSV2/web/docs/square/api-integration-notes.md` (the engineering evidence base, cited below as [API-NOTES]). Where this playbook states an API behaviour, it is taken from [API-NOTES] and its developer.squareup.com sources unless a developer.squareup.com URL is given here. I did not re-verify every [API-NOTES] claim.

---

## 0. How to read this document

### 0.1 Evidence labels

- **OBSERVED** - read at the cited URL on 2026-10-02 (today).
- **INFERRED** - my reasoning from observed facts. Treat as a hypothesis to test in the first month.
- **UNKNOWN / not found in official docs** - not established; the "verify" note says where to check.
- **AU: yes / no / UNKNOWN** - Australian availability, stated for each capability.

Source tags such as [H8] point to the register in section 12. Every source was retrieved on **2026-10-02**.

### 0.2 Limits of this evidence (read before relying on it)

1. **Fetch method.** Pages were read through a fetch tool that returns a model-written extraction of each page, not raw HTML. Quotes and numbers are as the extraction reported them. Before anyone commits money or a payroll rule to a number in this document, re-read that number on the cited page. I flag the highest-risk numbers with "re-read".
2. **Hosts that did not return content.** These returned origin-style errors, not content, so the claims that depend on them are marked "search excerpt only":
   - www.fairwork.gov.au (HTTP 503 on the record-keeping, paying-wages, awards and list-of-awards pages)
   - www.acma.gov.au (HTTP 503, including the fact-sheet PDF)
   - www.ato.gov.au (HTTP 403)
   - awards.fairwork.gov.au for MA000102 and MA000119 (HTTP 403); MA000003 did load
   - www.fwc.gov.au (HTTP 403)
   - squareup.com/au/en/software/marketing (HTTP 429)
   - squareup.com/help/au/en/article/6688 and /6415 returned the Support Centre home page and US content respectively, so I did not rely on them.
   The sandbox proxy status listing I read showed no CONNECT rejections for these hosts (the output I saw was truncated), so these look like origin responses, not network-policy denials. I read the network-policy documentation once as instructed. If Elie wants these read in full, retry from a normal browser or ask for the host to be allow-listed.
3. **Search excerpts** (labelled as such) are search-result summaries of an official page. They are weaker evidence than a page read and are not used for any number that drives a decision without a "re-read" flag.
4. **Square Community and Developer Forum posts** are not documentation. They are labelled "community" wherever used.
5. **Naming drift.** Square's AU Dashboard and help centre use several names for the same area: Staff, Team Management, Square Shifts, Shifts Plus, Advanced Access. Dashboard menu labels also vary ("Items & services", "Items & menus", "Items & inventory") [H24]. Expect small label differences on screen.
6. **No numeric targets.** This playbook gives definitions and methods only. Targets are Elie's to set after four to six weeks of baseline.

### 0.3 Australian-first rule applied

A capability is only called available if an AU source says so or the feature sits in the AU help centre. US-only features found during research are listed in section 11 so nobody plans around them by mistake.

---

## 1. Executive summary (what to do, in order)

1. **Confirm the plan and enabled features first** (section 13). KDS needs Square for Restaurants Plus or Premium, or a standalone KDS subscription [H10]. Several staff controls need Shifts Plus [H1, H2, H3]. Elie must read the Square Dashboard Subscriptions page and list what is on.
2. **AU availability confirmed for:** KDS [H10, M1], kitchen performance report [H13], discounts with schedules and customer groups [H24], loyalty [H21, API doc via API-NOTES], email marketing and vanity coupons [H23, H31], eGift cards [H32], invoices with deposits, recurring invoices and estimates [H27, H28, H29], timecards, breaks, overtime thresholds, rostering [H1-H8], cash drawer reports [H34], two-step verification [H33], multiple locations [H35], Xero daily-sales sync [X1], Payouts API [D1].
3. **Not available or not found in Australia:** SMS campaigns through Square Marketing (community statement only; verify), Square Payroll (no AU product found; section 7.6), penalty-rate modelling in Square (not documented), Square-native ad buying (not found), a native Square-to-Xero Payroll timesheet sync (not found).
4. **Square will not, by itself, plan production.** Square gives sales history and item stock counts per variation. Forecasting, prep sheets and ingredient maths belong to our ops layer (section 2).
5. **Pay online first is a technical requirement for KDS visibility** of website orders [API-NOTES section 4.4]. That shapes catering (deposit-only orders may not reach the KDS; section 8) and promotions (coupon redemptions only report when an order is Complete [H31]).
6. **A website order's attribution lives in our database**, not Square. Send conversions to ad platforms only from the verified-paid step (section 6).
7. **Run Square timecards and our own staff clock in parallel until a pay cycle reconciles** (task 15 in the build plan) and never treat Square overtime thresholds as award compliance [H4].
8. **The award is not decided.** Candidates found are listed in section 7.7. Elie or an adviser must confirm which applies before any rate is configured.

---

## 2. PLANNING: demand forecasting, prep sheets, bake plans, ordering and waste

### 2.1 Goal

Bake and prep the right quantity per item, per store, per day-part, so fewer items sell out early and less is binned, using Square's own sales history as the single source of demand.

### 2.2 Square features to use (with AU availability)

| Need | Square feature | Dashboard path / app | AU | Source |
|---|---|---|---|---|
| Item history | Item sales report (CSV or Excel) | Reports > Custom > Custom item report; export top right | yes | [H16] |
| Category and modifier history | Category sales, Modifier sales | Reports > Sales > Category sales / Modifier sales | yes | [H16] |
| Hour-level history | Hourly sales via time-frame filter and Reporting timeframes | Reports > Settings > Reporting timeframe; filter on time of day | yes (community post for hourly export; the Reporting timeframe setting is in the AU help article) | [H15, H18] |
| Location, device and channel filter | Filter by location, device, Source | "Filter By > Source" on item reports | yes | [H16] |
| Raw transaction-level data | Orders API SearchOrders (our server) | Developer API | yes (API is region-agnostic for Orders) | [API-NOTES 4.3.3] |
| Daily limited quantities | Item availability: Available / Sold out / Set quantity per location, syncs across POS and online; resets at end of each business day unless a reset is chosen | Items & menus > Items > Item library > status pill | yes | [H25] |
| Stock counts | "Track stock" per item or variation; adjustment reasons "Stock received, Inventory re-count, Damage, Theft, Loss and Restock return"; bulk import and export of counts | Items & services > Items > Item library | yes | [H20] |
| Ingredient inventory, recipes, purchase orders | Square Restaurant Inventory (a MarketMan integration) | Items & services > Inventory Management > Ingredient tracking > "Continue with Square" | The AU help article tells sellers to contact their Account Manager before setup; the April 2026 AU press page says Square for Restaurants is live in Australia but does not state price or plan terms | [H19, M8] |
| Waste in the API | Inventory API state WASTE (IN_STOCK to WASTE adjustment) | Developer API | yes | [D6] |

Key limitation, **OBSERVED**: the Inventory API "cannot track subcomponents, ingredients, or product bundling" [D6]. Item inventory is per variation. Ingredient and recipe planning therefore needs either Square Restaurant Inventory (MarketMan, separate subscription, terms UNKNOWN for Dough Boss) or our own recipe table.

Price of Square Restaurant Inventory: **not found in official docs for AU.** A search-result summary mentioned "an additional $149/month per location" requiring Restaurants Plus, but the AU press page I read does not state a price, so treat the figure as unverified. Verify with the Square Account Manager.

### 2.3 Forecast method (transparent, no invented numbers)

This is **INFERRED** design, not a Square feature. It is deliberately simple so a store manager can check it by hand.

**Unit of forecast:** `item (or item group) x location x weekday x day-part bucket`. A bucket is a block of trading hours chosen by Elie (for example open to first lunch rush, lunch, afternoon). Choose buckets from where the hourly sales histogram changes shape, not from a guess.

**Step 1 - Build the history table.**
Pull item sales by location and hour from SearchOrders (completed orders only) or the Custom item report. Keep: location, closed-at date and hour, item, variation, quantity, channel (POS, online, phone), and the day's flags below.

**Step 2 - Clean demand, because sales are not demand.**
- Drop days a store was closed or traded abnormally (power outage, fit-out). Keep a manual "excluded days" list.
- Flag **censored buckets**: any bucket where the item became Sold out or stock hit zero before the bucket ended. Sales in that bucket understate demand. Do not average censored buckets as if they were true demand; either exclude them or replace them with a conservative estimate and mark them.
- Strip **one-off bulk orders** (catering) from walk-in demand and forecast them separately from confirmed orders (section 8).
- Flag public holidays, school holidays and local events in a small events table that Elie or the store manager maintains.

**Step 3 - Baseline = trailing same-weekday average.**
For each cell, baseline = mean of the same weekday's quantity over the last **N** comparable weeks (N chosen by Elie; start with a value that gives enough points to see variation, review after a month). A median can replace the mean if single big days distort it.

**Step 4 - Safety margin.**
Add a margin on top of the baseline. Two transparent options:
- *Spread-based:* margin = **k x** the standard deviation of the same N values (k set by Elie).
- *Percentage:* margin = a fixed percentage of baseline per item class (for example high-waste items get a smaller margin, hero items a larger one).
Do not copy a number from this document; calibrate in step 6.

**Step 5 - Adjustments.**
Multiply or add for events from the events table. Add confirmed catering and pre-orders as a separate known line. Subtract opening stock still sellable from the previous day (shelf-life rules decided by the head baker; Square does not hold shelf life).

**Step 6 - Round to the production unit.**
Planned bake = round up to the batch or tray size per item: `ceil((baseline + margin + known orders - carry-over) / batch size) x batch size`. Batch sizes come from the kitchen, not Square.

**Step 7 - Back-test and tune (weekly).**
Compare plan vs actual per item per bucket. Track:
- **Forecast bias** = sum of (planned - actual sold) over the period / sum of actual sold. Positive means over-baking.
- **Absolute error** = mean of the absolute gap, per item.
- **Stock-out time** = the time an item went to Sold out.
- **Waste rate** = quantity wasted / quantity produced.
If stock-outs recur, raise k (or the percentage) for that item. If waste recurs with no stock-outs, lower it. Change one parameter per item per week so cause and effect stay visible.

**Why this method:** it is explainable on a whiteboard, needs only data Square exports, and fails safe (a missing history cell is shown as "no baseline: bake by judgement" rather than a made-up number).

### 2.4 Setup checklist

1. Confirm the three Locations exist and each item's availability per location is correct (Items > Item library > item > Locations and channels) [H26].
2. Turn on **Track stock** for bake-limited items at each location and decide the unit (per piece, per tray). Use variations only where the sell unit differs (for example half or full tray) [H20].
3. Agree **adjustment reasons** for waste. The AU article lists "Damage" and "Loss" but no dedicated "Waste" reason [H20]. Decide that "Damage" means dropped or spoiled in production and "Loss" means unsold at close, or record waste through the Inventory API WASTE state from our ops layer. Document the rule so counts are comparable across stores.
4. Set **Reporting timeframes** (Reports > Settings > Reporting timeframe) to match each store's trading day so reports and the forecast use the same "business day" [H15].
5. Name each POS device (device names show in reports and CSV) so a report can be cut by till or station [H15].
6. Export 8 to 12 weeks of item sales per location as the first baseline. If more history exists, load it; the Orders API is the source for hour-level detail.
7. Build the events table (public holidays, school holidays, local events, catering confirmed).
8. Agree production units, batch sizes and shelf lives per item with the head baker. Store them in the ops layer, not in Square.
9. Decide whether ingredient-level control is needed now (MarketMan) or later (section 2.8).

### 2.5 Weekly and monthly routine

**Daily (each store, before first bake):** print or open the prep sheet (forecast + known orders), bake, record any items that sold out and the time, record waste at close.

**Weekly (Monday, by store manager and head baker):**
1. Export last week's item sales by location (Custom item report) or let the ops layer pull it.
2. Compare plan vs actual per item; log bias and stock-out time.
3. Adjust margin parameters for the top items that missed.
4. Review the events table for the coming two weeks.
5. Review waste log; mark items to cut, shrink or promote.

**Monthly (operations manager with Elie):**
1. Re-pick N and bucket boundaries if the shape of demand changed.
2. Review item mix: items with high waste and low margin are candidates for removal; items that always sell out early are candidates for a larger batch.
3. Compare locations on waste rate and stock-out frequency (definitions below).
4. Check that counts in Square match a physical count on at least the top 20 items.

### 2.6 Exact report or export

- **Item demand history:** Reports > Custom > Custom item report > Export (CSV or Excel), filter location, date, time of day, Source [H16].
- **Category mix:** Reports > Sales > Category sales [H16].
- **Modifier demand (for example extra cheese):** Reports > Sales > Modifier sales; note negative-priced modifiers appear as negative revenue lines [H16].
- **Stock on hand:** Item library export (Actions > export; the same file re-imports for bulk counts) [H20].
- **Hour-level detail:** SearchOrders from our server (completed orders, by location and `closed_at`).

### 2.7 Metrics to watch (definitions only)

- **Sell-through** = quantity sold / quantity produced (per item, per bucket).
- **Waste rate** = quantity wasted / quantity produced.
- **Stock-out time** = clock time the item became Sold out.
- **Forecast bias** and **absolute error** as defined in step 7.
- **Item margin contribution** = (price excluding GST - ingredient cost) x quantity. Ingredient cost is not in Square unless Restaurant Inventory or recipe costing is used; Elie or the bookkeeper supplies it [H19].
- **Days of cover** (retail-style items) = stock on hand / average daily sales.

### 2.8 What can be automated through the Square API, and what stays manual

| Task | Automate? | Notes |
|---|---|---|
| Pull completed orders by location/hour/item | Yes | SearchOrders; the SDK's `search` methods are not auto-paginated, loop on `cursor` [API-NOTES 3] |
| Compute forecast and prep sheet | Yes (our ops layer) | Square provides inputs only |
| Set daily "Set quantity" or Sold out | Yes via Catalog/Inventory APIs | The Dashboard path is manual; sync to the ops layer is design work |
| Write waste into inventory | Yes | Inventory API IN_STOCK to WASTE [D6] |
| Watch stock changes | Yes | webhook `inventory.count.updated` [API-NOTES 4.14] |
| Ingredient and recipe maths | Not in Square's API | Needs our own recipe table or MarketMan |
| Physical counts and spoilage judgement | Manual | People decide what is waste |
| Shelf-life and food-safety rules | Manual | Not modelled in Square |

### 2.9 Risks and compliance

- **Censored demand bias** (step 2) will quietly under-bake if ignored.
- **Inventory drift:** counts decrement only when orders reference catalogue variations and are paid or fulfilled [API-NOTES 4.14]. Free-text or custom-amount sales will not decrement stock.
- **Food safety and allergen record-keeping** are outside Square. The Food Standards Code was **not researched** in this slice; ask a food-safety adviser (or the local council / NSW Food Authority) which records apply to the three kitchens.
- **Do not store customer personal information in the prep sheet** beyond what the kitchen needs (name and pickup time).

---

## 3. KITCHEN: Kitchen Display System (KDS)

### 3.1 Goal

Every paid order, in-store or website, shows on the correct station at the right time, with allergens and notes visible, and with measured ticket times so peaks can be managed.

### 3.2 Square features (with AU availability)

| Feature | Detail (OBSERVED) | AU | Source |
|---|---|---|---|
| KDS app | Runs on Android only; supported tablets listed include MicroTouch 10.1", 15", 21.5", Lenovo Tab M10, Samsung Galaxy Tab A8 WiFi and A9+ 11"; at least 3 GB RAM, Google Play Store, Android 9 or above | yes | [H10, M1] |
| Plan | Square for Restaurants Plus or Premium, or a Square KDS subscription | yes | [H10] |
| Price | KDS: "$25 Per month per device" after a 30-day free trial; Restaurants Plus "$129 per month per location" with unlimited KDS devices (re-read before budgeting) | yes | [M1, M2, M3] |
| Device code | Dashboard > Settings > Device Management > Device codes > Kitchen display system; the code expires if unused for 48 hours | yes | [H10, H12] |
| Device roles | **Prep** (a station focused on part of each order) and **Expeditor / Expo** (bridges front and back of house); each supports "Complete for all devices" or "Complete only on this device" | yes | [H10] |
| Layout | Columns 4 to 10, text size, yellow and red timer thresholds, sound alerts | yes | [H10] |
| Source routing | Toggle "View point of sale orders" and "View online, kiosk and delayed fulfilment orders" (Dashboard: Settings > Device Management > Devices > Assign modes > Routing > Source & fulfilment; or in the KDS app: Settings > View all settings > Routing) | yes | [H11] |
| POS-source filter | "Toggle ON which points of sale send orders to this kitchen display" | yes | [H11] |
| Category routing | "Use kitchen routing categories on KDS" toggle; items must be assigned to routing categories | yes | [H12] |
| Kitchen performance report | Reports > Operations > Kitchen performance: completed ticket count, average completed ticket time and average item completion time by device, location and timeframe; requires a KDS subscription; tracks only tickets sent to KDS, not printer tickets | yes | [H13] |

### 3.3 Setup checklist

1. Confirm the plan (Restaurants Plus/Premium or KDS subscription) and the count of devices per store. Elie to supply the layout: how many stations per store (for example oven, pizza, assembly, expo).
2. Buy or confirm compatible Android tablets and wall mounts. Do not assume an existing iPad works (KDS here is Android) [H10].
3. For each station, create a device code (Dashboard > Settings > Device Management > Device codes > Kitchen display system), install Square KDS from Google Play, sign in with the code within 48 hours [H10, H12].
4. Name each device by store and station (for example "Bankstown Pizza"); names appear in the kitchen performance report.
5. Define **kitchen routing categories** (for example "Oven", "Pizza", "Cold", "Drinks") and assign every sellable item to one or more. Turn on "Use kitchen routing categories on KDS" [H12].
6. Decide per device: Prep or Expo, and which sources it sees (POS, online/kiosk/delayed). A prep station that must see website orders needs "View online, kiosk and delayed fulfilment orders" ON [H11, H12].
7. Set the yellow and red timer thresholds from the real target prep time for each station. Do not copy another business's numbers; set them after one week of measured ticket times.
8. Set sound alerts and column count for the screen size in each kitchen.
9. Test: place one POS order and one website order per store; confirm each appears on the right station, with notes and modifiers [H12].
10. Keep a printer fallback for one device per store during the first month; note the report only counts KDS tickets, so printer tickets will not show in the kitchen performance report [H13].

### 3.4 Order-ready and prep time for online pickup

Two cases exist and the choice matters because Dough Boss's storefront is a custom Next.js app, not Square Online.

- **If Square Online were used:** Dashboard > Settings > Account & Settings > Fulfilment methods > Online pickup & delivery lets Square calculate pickup times from "when orders are available for pickup, how soon they can be picked up, when you start prepping orders, required prep time per order, how far in advance customers can place orders, and how simultaneous pickups are restricted based on your capacity". Tickets can print by scheduled pickup time or immediately [H14]. AU: yes (Square Online Free, Plus and Premium).
- **For our own Next.js checkout (API orders):** prep time and pickup time are fields on the order's PICKUP fulfilment (`pickup_at`, `prep_time_duration`). A SCHEDULED order reaches the Orders app Active tab and the KDS at `pickup_at - prep_time_duration`; without a prep time it goes active immediately [API-NOTES 4.3.2, 4.4]. The Square Online capacity setting does **not** apply; **our app must implement slot capacity** (INFERRED).

### 3.5 Throttling during peaks

- Square Online has a pickup-capacity control [H14]. **KDS itself has no documented throttle** (not found in official docs; the KDS pages describe routing, layout and timers only [H10, H11, H12]).
- For Dough Boss's own storefront, throttle in our code: cap paid scheduled orders per store per 15-minute slot, and show slots as full when the cap is reached. The cap value is Elie's decision, set from measured ticket times (INFERRED design).
- Operationally: when a store is overwhelmed, a manager marks high-effort items "Sold out" (Item library status pill) which syncs across POS and online [H25].

### 3.6 Pre-orders and catering orders on the KDS

- A future-dated order (SCHEDULED pickup with `prep_time_duration`) appears at `pickup_at - prep_time_duration` [API-NOTES 4.3.2]. Community posts also describe KDS display-window settings for future orders; **no official AU help page for that was found**, so treat as UNKNOWN and test.
- The KDS must have "View online, kiosk and delayed fulfilment orders" ON for these to appear [H12].
- **Pay online first.** API orders with a fulfilment appear on Square products "only after they're paid" [API-NOTES 4.4]. A catering order that is only a deposit-paid invoice may not appear on the KDS (see section 8.5).
- **Test before launch:** one SCHEDULED paid order per store, confirm the exact time it appears and whether a printer ticket is produced [API-NOTES section 10 items 8-9].

### 3.7 Allergens and special notes

- Square's item type "Prepared food and beverage" includes optional nutritional information such as calorie counts, dietary preferences and allergens [H26]. These fields are **descriptive for buyers**; they do not stop a wrong order and are not a compliance control.
- Use **modifiers** for allergen-relevant choices (for example "No sesame") so they print on the kitchen ticket as a modifier line, not a free note [H26: modifier sets have their own channel visibility].
- Customer free-text notes: the PICKUP fulfilment has a `pickup_details.note` (up to 500 characters) which staff see [API-NOTES 7.1]. Do not put attribution data there.
- Add a house rule: allergy orders are called out by the expediter and the ticket is marked complete only after a second person checks it. This is an operating procedure, not a Square feature.
- Whether the ticket layout shows an allergy flag distinctly is **not documented**; verify on a test ticket.

### 3.8 Weekly and monthly routine

**Weekly (store managers):**
1. Open Reports > Operations > Kitchen performance, set last week, filter by location and device. Note completed ticket count and average completed ticket time by device [H13].
2. Review the slowest device and the busiest hours; compare with the sales-by-hour history from section 2.
3. Check missing-order incidents; use the troubleshooting list (device code, source toggles, routing categories, app version) [H12].

**Monthly (operations manager):**
1. Compare stores on ticket time (same station, same weekday).
2. Revisit timer thresholds and slot caps against what the data shows.
3. Review the allergy-incident log.

### 3.9 Exact report

Reports > Operations > Kitchen performance. Export format is **not specified** in the help text I read [H13]; check the Export control in the Dashboard.

### 3.10 Metrics (definitions)

- **Average completed ticket time** (Square's term): the average time from ticket arrival to completion at a device, as shown in the report [H13]. Confirm Square's precise start and end events before using it for staff targets.
- **Average item completion time** (Square's term) [H13].
- **Late-ticket share** (our metric) = tickets over the red timer / total tickets.
- **Slot fill rate** (our metric) = paid orders in the slot / slot cap.
- **Remake rate** = tickets remade / tickets completed (manual log).

### 3.11 API automation vs manual

| Task | Automate? | Notes |
|---|---|---|
| Create API pickup orders that reach POS and KDS | Yes | Needs fulfilment + paid; PICKUP only (DELIVERY is restricted) [API-NOTES 4.3.2, 4.4] |
| Move order status to READY/PICKED_UP | Partly | KDS Expo may update the fulfilment; Prep devices may not; plan a staff fallback tap in the Orders app [API-NOTES 4.4] |
| Notify the customer "order ready" | Our app, via webhook, after verifying the state change | Whether Square itself notifies for API orders is UNKNOWN |
| Device codes, routing toggles, timers | Manual (Dashboard / KDS app) | No API for KDS settings was found |
| Kitchen performance numbers | Manual export (no API found) | The Reporting API (Beta) might expose it; AU availability UNKNOWN [API-NOTES 4.15] |

### 3.12 Risks and compliance

- **KDS dependency:** if a tablet drops off, orders are missing; keep the printer fallback and the device-code 48-hour rule in mind [H12].
- **Status round-trip is not guaranteed** for API orders [API-NOTES 4.4].
- **Allergen liability** sits with the business; Square fields are descriptive only. Food Standards Code obligations: not researched; obtain advice.
- **Staff safety:** wall-mounted tablets and wet environments; use mounts sold for KDS [M1].

---

## 4. PROMOTIONS: discounts, specials, loyalty, gift cards, coupons, Marketing

### 4.1 Goal

Run promotions that bring in incremental, profitable sales, are measured against a control, and comply with Australian pricing, spam and privacy rules.

### 4.2 Square features (AU availability and plan)

| Feature | What it does (OBSERVED) | Dashboard path | AU | Plan / price | Source |
|---|---|---|---|---|---|
| Discounts | Manual or automatic; schedule by day and time; customer group condition; minimum spend; applicable locations; "Apply automatically" (default for discounts with rules is manual) | Items & services > Items > Discounts | yes | Not stated in the article | [H24] |
| Time-based specials via API | CatalogDiscount + CatalogProductSet + CatalogTimePeriod (iCalendar) + CatalogPricingRule with `application_mode: AUTOMATIC`; POS must be version 5.15 or later; API-created orders auto-apply catalog rules when `pricing_options.auto_apply_discounts` is true | Developer API | yes | n/a | [D2, D3] |
| Vanity code coupons | Coupon codes for in-person and online; one online coupon code at a time at checkout (website or payment link); "Percentage off coupons only apply in store, while limiting coupons to sales over a certain amount only applies to sales made on your website"; online redemptions do not appear in reports until the order is marked Complete; codes not case-sensitive | Customers > Marketing > Coupons | yes | Square Marketing subscribers; described as free to create | [H31] |
| Email marketing | One-time or automated campaigns; audience All or Custom or smart groups; vouchers with expiry (default two months); reminder emails; location selection | Customers > Marketing > Campaigns | yes | "Starting at $20/mo." (re-read) | [H23, M3] |
| SMS marketing | Not available in Australia per a Square Community statement ("only available to US Sellers"); no AU help article found | n/a | **no (community statement; verify with Square support)** | n/a | community |
| Loyalty | Points by spend, items or visits; reward types: discount on whole sale, on an item or category, free item, combo; up to 15 rewards; enrol by mobile number at the register, on invoice payment or online; promotions: point multipliers and bonus points by date, weekday, time, minimum spend, item or category, location; up to 10 active+scheduled promotions via API | Dashboard > Loyalty; Customers > Loyalty > Promotions | yes | "Starting at $49/mo." (re-read); a search excerpt of an AU page listed tiers by loyalty visits per location per month: 0-500 $49, 501-1,500 $99, 1,501-10,000 $149 | [H21, H22, M3, API-NOTES 4.10] |
| Gift cards | eGift cards via email; group gifts; "Pay up to a 2.5% load fee when funds are added"; no fee on redemption | Items & services > Gift Cards > eGift Cards > Configure | yes | 2.5% load fee plus processing | [H32, API-NOTES 4.13] |

**Loyalty accrual in AU:** points accrue on the after-tax amount by default and before tips [API-NOTES 4.10]. Whether points accrue automatically on orders paid through an API payment link is **UNKNOWN** (smoke test 23 in [API-NOTES]).

### 4.3 Setup checklist

1. Decide which promotion tools are worth their subscription (Marketing from $20/mo, Loyalty from $49/mo per location as listed on the AU pricing page; confirm both on the invoice).
2. Create a standing **discount naming convention** (for example `PROMO-2026-11-LUNCH10`) so every promotion is a separate, reportable discount line.
3. For scheduled specials, create the discount with a Discount schedule (days and times) and decide automatic vs manual [H24].
4. Create coupon codes in Customers > Marketing > Coupons with a unique code per campaign and expiry; note the one-code-at-a-time rule [H31].
5. Loyalty: write the programme rules (earn rate, reward levels, which items are excluded). Exclusion is available under Loyalty > Settings > Eligible items and categories [search excerpt of H21]. Do not launch until staff are trained on the enrolment step at the register.
6. Gift cards: enable eGift cards (Items & services > Gift Cards > eGift Cards > Configure) after the expiry and terms wording is checked (see 4.9).
7. Collect email consent only through documented routes (4.9) before any campaign.
8. Pre-define the **measurement plan** (4.6) before the first send.

### 4.4 Weekly and monthly routine

**Weekly (marketing owner):**
1. List active promotions per store and their end dates; end expired discounts (Items > Discounts).
2. Pull the Discounts report and the coupon-redemption view; compare with the baseline (4.6).
3. Check Loyalty dashboard metrics (total loyalty customers, rewards redeemed, top loyalty customers) and loyalty vs non-loyalty average spend and visits [search excerpt of H21].

**Monthly (Elie with marketing owner and bookkeeper):**
1. Review each promotion's incremental sales and margin impact (4.6).
2. Retire or revise any promotion that does not clear the margin test.
3. Audit "was/now" claims against actual sales history (4.9).

### 4.5 Exact reports

- **Discounts, comps and voids** (Reports; listed in the standard report set) [H15].
- **Campaign performance:** email campaign reports show opens, clicks, purchases attributed, revenue, and coupons redeemed (View email marketing campaign reports, article 8413; read via search excerpt only).
- **Loyalty dashboard:** total loyalty customers, redemptions, top customers; comparison of average spend and visits for loyalty vs non-loyalty [search excerpt of H21].
- **Item sales with the discount grouped:** Custom item report grouped by discount name, or "reverse grouping" to see which discounts apply to which items [H16].
- **Online coupon redemption** appears only once the order is Complete [H31]. Make sure our ops layer completes paid website orders at pickup or the redemption report will lag.

### 4.6 How to design and MEASURE a promotion

All of this is **INFERRED method** (standard experimental design), described without numbers.

**Design.**
1. State the single objective: new customers, higher basket, fill a quiet daypart, clear a surplus item, or win back lapsed customers.
2. Define the **exposed** group and a **control** group before launch:
   - *Customer-level control (best for email):* split the consented list into two random halves in the Customer Directory; send the campaign to one half only. The other half is the holdout.
   - *Store-level control:* run the promotion at one store and use a comparable store as control, comparing the change from the pre-period (difference of differences). Use stores with similar weekday patterns.
   - *Time-level control:* alternate weeks only if the demand pattern is stable; weakest option.
3. Define the **baseline period**: the same weekdays in the preceding comparable weeks, excluding public holidays and censored (sold-out) days.
4. Fix the **success metrics** before launch: incremental net sales, incremental orders, incremental gross margin, new vs returning customers.
5. Give the promotion a unique discount or coupon code so redemptions can be matched in Square.

**Measure.**
- **Incremental sales** = exposed-group sales - expected sales (baseline adjusted by how the control group moved).
- **Discount cost** = sum of discount amounts given (from the Discounts report), including discounts given to people who would have bought anyway.
- **Margin impact** = incremental gross margin - discount cost on non-incremental sales. Gross margin needs ingredient cost per item; supply from the bookkeeper or recipe costing [H19].
- **Cannibalisation** = decline in non-promoted items or other dayparts during the promotion.
- **Redemption rate** = redemptions / recipients.
- **Repeat rate after promotion** = customers from the exposed group with a second paid order within a window set by Elie, vs the same in control.
- Report a result only after the window closes and the data is not censored by sold-out items.

**Interpretation rules.**
- A promotion is a success only if incremental gross margin after discount cost is positive, not merely if sales rise.
- Small samples: say "inconclusive" rather than declare a winner.
- Keep a promotions log (what, where, dates, code, result) in the ops layer.

### 4.7 What can be automated through the Square API, and what stays manual

| Task | Automate? | Notes |
|---|---|---|
| Create catalog discounts and pricing rules | Yes | Catalog API; POS 5.15+ for pricing rules [D2] |
| Auto-apply catalog discounts to our API orders | Yes | `pricing_options.auto_apply_discounts` [D3] |
| Create or read customer groups and add customers | Yes | Customer Groups API; group discounts are configured in the Catalog API, not the Groups API [D4] |
| Loyalty accounts, points and rewards | Yes (if Loyalty is subscribed) | The API cannot create or change the programme [API-NOTES 4.10] |
| Gift cards (create, load, redeem) | Yes | Developer must deliver digital gift card details; 2.5% load fee applies [API-NOTES 4.13] |
| Email campaigns and coupon codes | **No Marketing or coupon API found in the permissions reference** (absence, not proof) | Manual in the Dashboard, or use our own ESP [API-NOTES 4.9] |
| Promotion measurement | Yes, in our ops layer from SearchOrders and discount data | Square gives no experiment tool |

### 4.8 Australian rules for promotions (what the official pages say)

**Consumer law: pricing and "was/now"** (ACCC):
- "WAS $275 NOW $149" is risky where "the product or service has never been sold at the higher price, or was sold in a limited amount at the higher price immediately before the sale" [G1, G2]. Another ACCC page, read as a search excerpt, says a business should be cautious using was/now where there were very few or no sales at the "was" price, and that the issue is whether consumers would have paid the "was" price "for a reasonable period before the sale". [G11]
- ACCC warns about prices advertised without GST or other costs included, and about "drip pricing" (extra fees added during an online purchase); fees must be disclosed upfront [G1, G11 excerpt]. Dough Boss action: show the full pickup price including GST and any fee on the first price display in the Next.js checkout.
- **Keep evidence:** for any "was" price, retain the sales history that proves it was genuinely charged for a reasonable period. A discount recorded in Square on a newly invented list price is the classic failure.
- Prefer "special price" or a percentage-off offer on the current price over was/now.

**Spam Act 2003** (ACMA, search excerpt only; page fetch returned HTTP 503):
- Consent is required before sending marketing emails or texts; consent can be express or inferred, and express is best practice.
- Every commercial message must contain a functional unsubscribe that is honoured within 5 working days, works for at least 30 days, is free and does not require login or extra personal information.
- A business remains responsible even if a third party sends for it.
- Square's own rule matches: "Email addresses ... cannot be used to send email marketing through Square Marketing unless you have separately collected and recorded the customer's explicit consent or manually added that customer as a 'subscribed customer'" [H23].
- Square Customers API exposes only `preferences.email_unsubscribed`; there is no SMS consent field or opt-in timestamp [API-NOTES 4.9]. Keep our own consent ledger (what, when, wording version, source).

**Privacy Act 1988 (OAIC):**
- A "small business" is one with annual turnover of $3 million or less and is generally outside the APPs unless an exception applies (trading in personal information, health service provider, related to a body corporate that is covered, voluntary opt-in, and others) [G3]. **Whether Dough Boss is covered depends on turnover across the entity or group and is a question for Elie's adviser.**
- APP 7 (direct marketing) does not apply to messages covered by the Spam Act or Do Not Call Register Act; it applies to channels like post [G4]. Keep a simple opt-out in every channel regardless.
- Square's own API policy requires explicit permission before saving contact information [API-NOTES 4.9].

**Gift cards** (ACCC, search excerpt of an official page): gift cards supplied from 1 November 2019 must be redeemable for at least 3 years from supply; the expiry must be shown prominently; no post-purchase fees; exceptions include reloadable cards and some loyalty and promotional cards [G5]. Check how Square's eGift cards display expiry and fees before launch; that is **UNKNOWN** from the pages read.

**Not researched:** the specific rules on promotional competitions or trade promotion permits, and credit card surcharge rules. Ask an adviser if a competition is planned.

### 4.9 Risks

- A coupon or loyalty reward can stack with an automatic discount; test combinations on a real till before launch.
- Online percentage coupons do not work the same as in-store ones [H31].
- Redemption reporting lags for online orders until Complete [H31].
- Loyalty adds a per-location monthly fee that scales with visits; model it against expected uplift.
- A promotion that pushes volume past the slot cap (3.5) damages service.

---

## 5. SALES AND ANALYTICS

### 5.1 Goal

One trusted daily and weekly picture of sales by store, channel, item and customer, reconciled to the bank and to the accounting system.

### 5.2 Square features (AU availability)

| Need | Feature | Path | AU | Source |
|---|---|---|---|---|
| Standard reports | Sales summary, sales trends, payment methods; item, category, modifier sales; discounts, comps and voids; taxes and fees; transaction status; transaction history; gift cards; custom report; disputes; cash drawers | Dashboard > Reports | yes | [H15] |
| Channel or source | Sales by source; "Filter By > Source" on reports | Reports; Sales Summary > Sales by Source (search excerpt) | yes | [H16] |
| Custom reports | Blocks: key statistics, sales summary, payment methods, item sales, category sales, team member sales, discounts, modifier sales, taxes; export only (cannot print); date filters cannot be saved to the report | Reports > Custom reports > Create New Report | yes | [H17, H18] |
| Export | CSV; "Start export"; cash drawer reports cannot be printed or exported | Per-report export | yes | [H18] |
| Daily email | Daily, monthly and annual sales summary emails (owners only); extra recipients per location | Settings > Account & Settings > Notifications > Account | yes | [H15, H18] |
| Business day | Reporting timeframes | Reports > Settings > Reporting timeframe | yes | [H15] |
| Restaurant reports | Section sales (needs table mapping; Restaurants Plus/Premium), Kitchen performance (KDS subscription) | Reports > Sales > Section sales; Reports > Operations > Kitchen performance | yes | [H13] |
| Customers and repeat | Customer Directory with smart groups (regular, lapsed, visited in last three months) and custom filters | Customers > Customer directory | yes | [H30] |
| Labour vs sales | Reports > Operations > Labour vs. sales (requires Shifts Plus and assigned wages) | Dashboard | yes | [H3] |

### 5.3 Setup checklist

1. Set Reporting timeframes per store (trading hours, including any early-morning bake sales) [H15].
2. Turn on daily sales summary emails to Elie and each store manager (Settings > Account & Settings > Notifications > Account) [H18].
3. Name devices (reports show them) [H15].
4. Decide the **customer capture rule**: every online order creates or links a Square customer (SearchCustomers first; CreateCustomer does not de-duplicate) [API-NOTES 4.9]; at the till, ask for a mobile or email only with a stated reason.
5. Build one saved **custom report** per store with: key statistics, sales summary, payment methods, item sales, discounts, taxes [H17].
6. Install the **Xero app** if Xero is the accounting tool: "Your daily Square transactions will be automatically imported, summarised, and populated to the appropriate account within Xero's general ledger"; it captures gift cards, tips, surcharges and taxes; Xero creates bank rules so Square fees go to a fees account [X1]. Multi-location handling is **not stated** on the page; verify before connecting three locations.
7. Confirm the bank account(s) for payouts per location (Account & settings > My business > Locations allows per-location bank accounts and tags) [H35].

### 5.4 Daily close routine (per store)

1. Compare the till or channel totals to the Square Sales summary for the business day (using the Reporting timeframe).
2. End the cash drawer session and record the actual count; Dashboard Reports > Payments > Cash drawers shows starting cash, cash sales, refunds, paid in and out, and expected cash [H34, search excerpt of 8358]. Note: only account owners can set cash management up; it is device-specific when enabled on a device directly, so enable it on each till [H34].
3. Review voids, refunds and comps for the day.
4. Check no online orders remain open or unfulfilled in the Orders app.
5. Send or review the daily summary email.
6. Log any variance in the close checklist and the reason.

### 5.5 Reconciliation to the bank and Xero

- **Payout timing:** the Payouts API documentation states payout cutoffs are "12AM local time" in Australia, so orders on a given business day can land in a different transfer [D1]. Another reason a day's sales will not equal one bank deposit.
- **Payout entries:** each payout entry carries `gross_amount_money`, `fee_amount_money` and `net_amount_money`; the Payouts API cannot return data older than January 2021 [D1].
- **Where in the Dashboard:** AU articles refer to the Balance and Transfers area (refunds appear as "Adjustment" under Money > Balance [H37]; a search excerpt cites Sales > Transfers). Menu label varies; find it on screen.
- **Routine:** monthly, match each bank deposit to a Square transfer and each transfer's payments; Xero's daily summary plus bank rules do the heavy lifting if connected [X1].
- **Instant transfers:** Instant Deposit is available in Australia [D1] and the AU pricing page lists "Instant Transfers: 1.95%" [M3]. Fees will not match the standard fee line; keep these separate in the books.
- **Refunds:** payments can be refunded within one year of the original transaction through Square; after that, refunds must be made outside Square [H37]. Partial refunds "will not include GST and tips in reporting" [H37]. Tell the bookkeeper.
- **GST:** GST handling by the Square receipt was not researched. The ATO GST page could not be read (HTTP 403). Confirm with the bookkeeper that the catalogue tax is set as GST-inclusive at all three locations.

### 5.6 Weekly and monthly routine

**Weekly (Monday):**
1. Location comparison: sales, orders, basket size, item mix, discounts, labour % (when Shifts Plus), average ticket time.
2. Channel comparison: POS vs online vs phone.
3. Top and bottom 10 items per store.
4. Repeat customers: smart groups (regular, lapsed, last three months) counts [H30].
5. Review voids, refunds, comps by team member (team sales report needs the paid access tier; see section 9).

**Monthly (Elie and bookkeeper):**
1. Bank reconciliation of transfers; investigate any unmatched deposit.
2. Square fees vs Xero fees account.
3. Gift card liability: loaded minus redeemed.
4. Closing report pack: one PDF or CSV set per store, archived (Square does not document a long-term archive; **retain exports**).

### 5.7 Exact reports and exports

| Use | Report | Path |
|---|---|---|
| Daily sales per store | Sales summary | Reports > Sales > Sales summary |
| Item mix | Custom item report | Reports > Custom > Custom item report |
| Category mix | Category sales | Reports > Sales > Category sales |
| Discounts | Discounts, comps and voids | Reports (standard set) |
| Cash | Cash drawers | Reports > Payments > Cash drawers |
| Labour % | Labour vs. sales | Reports > Operations > Labour vs. sales |
| Kitchen | Kitchen performance | Reports > Operations > Kitchen performance |
| Transfers | Transfers / Balance | Dashboard balance and transfers area |

### 5.8 Metrics (definitions)

- **Gross sales / net sales:** as Square defines them on the Sales summary (item report columns: quantity sold, gross sales, net sales [H16]). Confirm exact definitions on the report glossary.
- **Order count** and **basket size** = net sales / orders.
- **Items per order** = items sold / orders.
- **Channel share** = channel net sales / total net sales.
- **New vs repeat customers:** a customer with one paid order in the window is new; two or more is repeat. Window chosen by Elie.
- **Repeat rate** = customers with 2+ paid orders in the window / customers with 1+ paid orders.
- **Same-store sales growth** = net sales this period / same period last year, same store, minus 1 (only for stores open in both).
- **Void, refund and comp rate** = value / net sales.
- **Labour % of net sales** (Square's term in the Dashboard metrics) [H3].

### 5.9 API automation vs manual

| Task | Automate? | Notes |
|---|---|---|
| Daily sales per store | Yes | SearchOrders; cross-check with Dashboard totals (smoke test 26) |
| Repeat customer metrics | Yes | Customers API plus orders; instant profiles may be invisible to search [API-NOTES 4.9] |
| Payout reconciliation | Yes | Payouts API; webhooks on payout state changes [D1] |
| Xero posting | Via the Xero Square app (no code) | X1 |
| Reporting API | Beta; AU UNKNOWN | [API-NOTES 4.15] |
| Cash count and variance explanation | Manual | |

### 5.10 Risks

- **AR and cash mismatches** from refunds after one year and from the payout cutoff [H37, D1].
- **Online orders staying OPEN** (payment-link orders are not completed automatically; fulfilment must be completed) skew "open orders" and coupon reports [API-NOTES 4.5, H31].
- **Report definitions drift** if staff use custom amounts instead of catalogue items; train staff.
- **Square exports are CSV only**, with no documented scheduled export; set a calendar task for archiving.

---

## 6. ADVERTS: how Square data supports advertising

### 6.1 Goal

Know which ad click produced which paid Square order, feed that back to ad platforms to optimise, and use consented customer lists responsibly.

### 6.2 What Square provides (AU)

| Capability | Status | Source |
|---|---|---|
| Consented email list for the business's own email campaigns | yes (Square Marketing) | [H23] |
| Customer groups and smart groups for targeting inside Square Marketing | yes | [H30] |
| Export of the customer directory | The Customer Directory has an Import/Export menu; the export format and fields were not read; verify | [search excerpt of H30 family] |
| Square Online integrations: Meta for Business (Facebook Pixel, Ads Manager, catalogue sync), Google Business Profile, Order with Google | yes for Square Online stores | search excerpts of AU articles 6877, 8654, 7689 |
| Google Ads conversion tracking on Square Online | Square Online has Tracking Tools (Dashboard > Settings > Tracking Tools); community posts report purchase-confirmation values are unreliable | community only |
| Native Square ad buying (a Square-run ad product) | **not found** in the AU pages searched | not found |
| Native Square connector sending offline conversions to Google Ads or Meta | **not found** in official docs | not found |

Dough Boss uses its own Next.js storefront, so the Square Online marketing and SEO tools are only relevant if Square Online is also used (UNKNOWN).

### 6.3 What Square does NOT do

- It does not run or buy ads in Australia that I could find.
- It does not push conversions to Google Ads, Meta or GA4 on its own for orders created by our API.
- It does not store advertising click IDs for us; order metadata is limited to 10 keys and Square prohibits personal information and card data in metadata [API-NOTES 7.1].
- It does not give an SMS marketing channel in Australia (community statement) [section 4.2].
- It does not give a Marketing API for campaign creation (none found in the permissions reference) [API-NOTES 4.9].

### 6.4 Recommended measurement chain from ad click to a paid Square order

**INFERRED architecture** built on [API-NOTES section 7]. Google, Meta and GA4 API specifics are **outside this slice's allowed sources and were not verified**; confirm each against the platform's own current documentation before building.

1. **Landing:** the Next.js site records `utm_*`, `gclid` (and `wbraid`/`gbraid`), `fbclid`, the Meta browser cookies and GA client ID, the landing URL and referrer, **only according to the visitor's consent choice**. Stored server-side in our database against an anonymous session ID.
2. **Checkout:** when the customer pays, create the Square order with our own **opaque reference** in metadata or `reference_id` (for example our order ID, 40 characters or fewer) and, if useful, up to 2 or 3 short fields such as `utm_source`, `utm_medium`, `utm_campaign`. Keep the full attribution record in our database keyed by that ID [API-NOTES 7.2].
3. **Payment confirmation:** wait for the verified-paid event. There is no payment-link webhook; use `payment.updated` (status COMPLETED, carries `order_id`) plus order events, then confirm with RetrieveOrder [API-NOTES section 1 point 6]. Never fire a conversion from the redirect page.
4. **Server-side conversion send:** from the verified-paid step, send the conversion to each platform with our order ID as the de-duplication key: GA4 (Measurement Protocol `purchase`), Meta Conversions API `Purchase`, Google Ads offline click conversion (gclid) or enhanced conversions. Value in AUD (convert from cents), currency AUD [API-NOTES 7.3].
5. **Refunds and cancellations:** listen for `refund.*` and cancelled orders and send an adjustment or reversal in the platform-appropriate way.
6. **Reporting:** a weekly table per campaign: spend (from the ad platform), paid orders and net sales attributed, new vs returning customers (Square customer ID), and blended view including **unattributed** orders (phone, walk-in, POS) which have no click ID.
7. **Consent snapshot:** store the consent state with each order; send only identifiers that were consented, and hash email or phone only if consented [API-NOTES 7.3].

**Closed-loop honesty:** only website orders carry click IDs. Walk-in sales that follow an ad cannot be matched without a voucher or QR with a unique code; use vanity code coupons (4.2) for in-store tracking [H31].

### 6.5 Consented customer lists for custom audiences

- Build lists only from customers who opted in to marketing (Spam Act consent for the contact method, plus a clear statement that the data may be used to show ads, which the Privacy Act and the ad platforms' own terms may require; platform terms were not researched).
- Use Customer Groups as the segmentation unit (Customer Groups API for automation; the groups are manual and explicit) [D4].
- Upload a list only after removing unsubscribed customers (`email_unsubscribed` is read-only in the API and reflects Square's own opt-out) [API-NOTES 4.9].
- Keep a log of each export: who, when, list size, purpose.
- Delete or refresh uploaded lists on a schedule; apply the Square policy that you must obtain explicit permission before saving contact information [API-NOTES 4.9].

### 6.6 Weekly and monthly routine

**Weekly (marketing owner):** compare ad-platform reported conversions with Square paid orders for the same window; investigate a gap above a tolerance Elie sets. Check the Square Marketing campaign report and coupon redemptions.

**Monthly:** review cost per paid order and net sales per dollar of spend by campaign; review the consent ledger; prune lists.

### 6.7 Exact reports

- Square: email campaign reports (opens, clicks, purchases, revenue, coupons redeemed) [search excerpt of article 8413]; Discounts report for coupon-coded orders [H15].
- Ours: the attribution table and the conversion send log.

### 6.8 Metrics (definitions)

- **Attributed paid orders** = paid Square orders with a stored click ID or UTM.
- **Attribution coverage** = attributed paid orders / all paid online orders.
- **Cost per paid order** = ad spend / attributed paid orders.
- **Return on ad spend** = attributed net sales / ad spend (note: before cost of goods).
- **New-customer share** = attributed orders from customers with no prior paid order.
- **Conversion send success rate** = conversions accepted by the platform / conversions sent.

### 6.9 API automation vs manual

| Task | Automate? | Notes |
|---|---|---|
| Capture and store click IDs | Yes (our site) | consent-gated |
| Conversion send on paid | Yes (our server) | platform APIs not verified here |
| Build custom audiences | Partly | export from our DB; upload manually or via platform API |
| Square Marketing campaigns | Manual in Dashboard | no API found |
| Ad creative, budgets | Manual in ad platforms | |

### 6.10 Risks and compliance

- Putting click IDs or personal information into Square metadata is against Square's guidance for PII [API-NOTES 7.1]; keep it in our database.
- Mismatch between consent in the cookie banner and what the server sends.
- Double counting if browser and server events share no de-duplication ID.
- Spam Act applies to any email or SMS advertising to the same list.

---

## 7. TIMESHEETS AND WORKFORCE

### 7.1 Goal

Accurate, auditable hours per person per store, rosters that match demand, labour cost visible against sales, and clean exports to whatever payroll tool is used, without breaching Australian employment rules.

### 7.2 Square features (AU availability and plan)

| Feature | Detail (OBSERVED) | Path | Plan | Source |
|---|---|---|---|---|
| Time tracking | Clock in and out on POS or Square Team app; breaks; job switching; passcodes required for each team member | Staff > Settings > Clock in/out; Edit POS settings > mode > Settings > Shifts > "Track team member time" | Shifts Free and Plus, Retail Free/Plus/Premium, Restaurants Free/Plus/Premium | [H1] |
| Clock-in controls | "Block early or unscheduled clock-ins" with "Allow within" time limit; auto clock-out | Staff > Settings > Clock in/out | **Shifts Plus or Premium** | [H1] |
| Geofencing | Features page: "Time clock (POS and Mobile)" with geofencing capability; plan and setup not documented in what I read | n/a | UNKNOWN | [M5] |
| Breaks | Name, duration, paid or unpaid, mandatory by shift length or hours worked; meal and rest breaks set up by location on account creation; "Block ending breaks early" | Staff > Settings > Breaks | Free/Plus; blocking early end needs Shifts Plus | [H2] |
| Overtime | Daily overtime, weekly overtime, daily double time thresholds; overtime premium "0.5x"; penalty rates (weekend/public holiday) **not described** | Staff > Settings (work period and overtime rules) | Shifts Free/Plus, Retail, Restaurants, Appointments | [H4] |
| Timecards | View and edit; managers add timecards; team members request edits in the Team app; requests expire after 30 days; permissions: Shifts or Manager | Staff > Time tracking > Timecards | Free/Plus | [H8] |
| Rostering | Staff > Scheduling > Schedule; availability; shifts and open shifts; publish with notification of affected or all members; select multiple locations to view together; repeat shifts | Dashboard | Free for up to five team members; advanced items (time off, swaps) with Plus | [H6, M4] |
| Labour vs sales | Reports > Operations > Labour vs. sales; CSV | Dashboard | Shifts Plus + assigned wages | [H3] |
| Tip pooling | Divides each card tip equally among tip-eligible members clocked in at the transaction | Team Management | Shifts Plus, Restaurants Premium etc. | [H9] |
| Team documents | Files up to 25 MB; permission "View team member documents"; deletions need contact with Square privacy | Dashboard / Team app | Shifts Plus, Team Communication, or Premium | [H38] |

**Pricing (AU, re-read):** "Shifts Free - free for up to five team members, including account owners"; "Shifts Plus - starts at $5 AUD per team member (including the account owner), per month". Square Shifts "is included in Square for Restaurants and Square for Retail packages" [M4]. Which tier applies to Dough Boss's Restaurants plan for these features is UNKNOWN; verify.

### 7.3 Setup checklist

1. Confirm headcount per store and whether Shifts Free (five or fewer) or Plus is needed. Confirm what the existing Square plan already includes.
2. Add each team member (Staff > Team), assign **locations**, set a personal passcode, and a permission set (7.8).
3. Set **jobs and wages** per person (Square needs assigned wages for labour vs sales; if wages are unset the labour cost reads as zero [API-NOTES 4.11]).
4. Turn on "Track team member time" for each POS mode (Staff > Settings > Clock in/out > Edit POS settings) [H1].
5. Configure breaks (Staff > Settings > Breaks). The page recommends checking the Fair Work meal and rest period rules [H2]. Do not set break rules from Square defaults without checking the applicable award.
6. Configure overtime thresholds only after the award is confirmed (7.7) and understand Square cannot model penalty rates [H4].
7. If on Shifts Plus: enable block early/unscheduled clock-in with an "Allow within" window and auto clock-out [H1].
8. Decide shared-till vs personal login: shared devices print a workday summary on clock-out [search excerpt of H3].
9. Build the roster template per store (open shifts for key stations) and publish weekly [H6].
10. **Run parallel:** keep the existing staff clock (task 15) alongside Square timecards for at least one full pay cycle and reconcile the differences.

### 7.4 Weekly routine

1. **Monday:** publish next week's roster by store (Staff > Scheduling > Schedule) [H6].
2. **Daily:** manager checks open timecards at close; no one should be left clocked in.
3. **Weekly close:** review timecards for missing clock-outs, unusually long shifts and missing breaks; approve edits (edit requests expire after 30 days) [H8].
4. Export the timecard CSV (Shifts > Time tracking > Timecards; includes regular, overtime and double time hours, paid hours, paid and unpaid breaks, total labour cost, tipped wage, declared cash tips, pooled tips) [H3]. Reports can only be downloaded from a computer [H3].
5. Compare labour % of net sales per store (Reports > Operations > Labour vs. sales) [H3].

### 7.5 Monthly routine

1. Reconcile Square hours to payroll hours; document differences.
2. Archive the month's timecard exports. Square does not document an edit history for timecards [H8]; the archive is the audit trail.
3. Review roster vs forecast demand (section 2): staff against the busiest hours.
4. Review overtime and break compliance exceptions.

### 7.6 Exporting timesheets to payroll or accounting, and Square Payroll in Australia

- **What Square provides:** "Efficiently export timecards to a third-party payroll provider"; "Payroll syncs and exports" [M5]. The features page names no payroll product.
- **Square Payroll in Australia: NOT FOUND.** The AU "payroll/demo" page shows a pay-run demo but does not state whether Square runs payroll or STP in Australia [M6]. The sign-up articles found are US-help-centre articles. The AU Staff help topic lists scheduling, time tracking, tip pooling, commissions and documents; it lists no payroll or Xero article [search result for the AU "Staff and payroll" topic]. A search excerpt says an AU community discussion asked for "Square Payroll for Australia" and noted Square does not build a Square-to-Xero timesheet sync (community, not docs). **Treat Square Payroll as not available in Australia until Square support says otherwise; verify.**
- **Xero:** the Xero app for Square covers daily **sales** reconciliation [X1]; it does not say it moves timesheets. A native Square-Shifts-to-Xero-Payroll timesheet sync was **not found**.
- **Which payroll tool does Dough Boss use?** UNKNOWN. Xero is the likely accounting tool but is unverified for Dough Boss. Elie to confirm (section 13).
- **Practical flow:** export the timecard CSV each pay period, check it, then import or key hours into the payroll tool, or use an approved timesheet partner if one exists for the payroll tool. Our ops layer can read timecards through the Labor API (`SearchTimecards`, `TIMECARDS_READ`) and produce a payroll-ready file [API-NOTES 4.11]. Labor API requires Square API version 2025-05-21 or later [API-NOTES 4.11].
- **STP and super (ATO, search excerpt; ATO page returned HTTP 403):** employers must report through Single Touch Payroll; STP Phase 2 is expected to be in place; the super guarantee rate is 12%; under Payday Super (from 1 July 2026 per the excerpt) super is paid with payday and qualifying earnings are reported through STP. Whatever payroll tool is chosen must be STP-enabled; confirm the Payday Super requirements with the payroll provider or adviser. Re-read at ato.gov.au.

### 7.7 Australian practice: awards, overtime, penalty rates (confirm the award; do not assume)

**Plainly:** the correct award, classification, penalty rates, breaks and overtime rules for Dough Boss's staff must be confirmed by **Elie or an adviser** (accountant, HR or industrial relations adviser, or the Fair Work Ombudsman). Nothing here selects an award for you.

Awards that the official pages suggest could be relevant to a bakery plus pizzeria with catering. Which one applies depends on the employer's actual activities and each employee's duties:

| Award | What I observed | Source |
|---|---|---|
| Fast Food Industry Award 2020 [MA000003] | Coverage clause defines fast food as taking orders for, preparing and selling meals, snacks or beverages "sold to the public primarily to be consumed away from the point of sale"; it excludes employers in the hospitality industry as defined in the Hospitality Industry (General) Award, the general retail industry as defined in the General Retail Industry Award, and "coffee shops, cafes, bars and restaurants providing primarily a sit-down service inside the catering establishment". Clause 21 penalty rates (as extracted: Saturday 125%, Sunday 125 to 150% by level, public holidays 225%, late night 10pm to midnight 110%, early morning midnight to 6am 115%, casual loading 25%) (re-read the award text; shown only to illustrate why penalty rates matter, not as Dough Boss's rates) | [G6] |
| General Retail Industry Award 2020 [MA000004] | Search excerpt: the general retail industry includes "bakery shops at which the predominant activity is baking products for sale on the premises"; includes a pastry cook classification | search excerpt, awards.fairwork.gov.au/MA000004.html |
| Restaurant Industry Award 2020 [MA000119] | Exists on the Fair Work Ombudsman site; coverage text not read (HTTP 403) | search result only |
| Food, Beverage and Tobacco Manufacturing Award [MA000073] | Search excerpt says the Food and Beverage Manufacturing Award does not cover retail bakeries; it may be relevant to wholesale or central production (INFERRED, not confirmed) | search excerpt |
| Pastrycooks | The standalone "Pastrycooks Award" found is a **Western Australian state** award, not federal [search result]; not applicable to Sydney unless an adviser says otherwise | wa.gov.au search result |

Also check whether an **enterprise agreement** covers any store. Not researched.

**What Square can and cannot do here:**
- It can compute daily overtime, weekly overtime and daily double time from thresholds you enter [H4].
- It does **not** document penalty rates for weekends, public holidays, early-morning or late-night work, casual loading, junior rates, allowances or leave loading [H4]; the Labor API timecard has no award classification, penalty or super fields (OBSERVED absence in SDK 46.0.0 in [API-NOTES 4.11]).
- Therefore **Square timecards record hours; they do not compute award pay.** Pay calculation belongs in the payroll tool or an award-aware timesheet product.

**Record-keeping (Fair Work Ombudsman, search excerpt; fetch returned HTTP 503):**
- Time and wages records must be kept for **7 years**.
- Records must be legible, in English, readily accessible to a Fair Work Inspector, not changed unless to correct an error, and not false or misleading.
- Pay slips must be issued within one working day of payday.
- Records include hours worked, overtime hours with start and finish times, and penalty rates or loadings paid.
Square's timecard edits must therefore be corrections of fact, approved by a manager, and the monthly archive (7.5) should be kept for at least the retention period. Re-read these rules at fairwork.gov.au.

### 7.8 Permissions by role and location

- Permission sets: Staff > Team > Permissions > Create permission set; presets Standard, Enhanced, Full, plus custom [H5].
- **Plan limits (AU, re-read):** Free plans 1 custom permission set; Plus plans 2; Premium or Advanced Access unlimited [H5].
- Permissions are location-scoped: "Team members can only access and manage data for their assigned locations" [H5].
- Timecard management needs the Shifts permission or Manager permission [H8].
- Suggested roles (design, not a Square default): Owner; Operations manager (all locations, reports); Store manager (own location: timecards, roster, refunds within limits); Head baker/kitchen lead (own location: item availability, stock); Cashier (POS only, no refunds without a manager); Marketing (customers and marketing, no money). With only 1 to 2 custom sets on lower plans, you may need the paid access tier to model this fully. **Advanced Access price in AU:** a page I read said "$35/mo" per location with unlimited custom sets, a team activity log and a team sales report [M7], but the help article text mixed US figures, so **verify the AU price and inclusions** before relying on it.

### 7.9 Metrics (definitions)

- **Labour % of net sales** (Square's metric) [H3] = total labour cost / net sales for the period.
- **Sales per labour hour** = net sales / paid hours.
- **Scheduled vs actual hours** = published roster hours vs timecard hours (Square dashboard shows scheduled vs actual labour [H3]).
- **Overtime share** = overtime hours / paid hours.
- **Missed-break rate** = shifts where a required break was not recorded / total shifts.
- **Timecard edit rate** = edited timecards / total timecards (watch for patterns by person or manager).

### 7.10 API automation vs manual

| Task | Automate? | Notes |
|---|---|---|
| Read timecards by location and period | Yes | `labor.searchTimecards`; `limit` max 200; unknown filter fields are silently ignored, so test filters [API-NOTES 4.11] |
| Read team members, jobs, wages | Yes | Team API `EMPLOYEES_READ`; permissions and passcodes not exposed [API-NOTES 4.11] |
| Create or publish rosters | Yes | ScheduledShift API; some features need Shifts Plus [API-NOTES 4.11] |
| Labour cost | Partly | Needs `wage.hourly_rate` set on timecards; defaults to zero if unset [API-NOTES 4.11] |
| Pay calculation (penalties, super, leave) | **No, manual / payroll tool** | not in Square [H4] |
| Approving edits, resolving disputes | Manual | |
| Webhooks | Yes | `labor.timecard.*` events [API-NOTES 4.11] |

### 7.11 Risks and compliance

- **Underpayment risk** if Square overtime thresholds are mistaken for award compliance. Underpayment is a live enforcement area (the Fair Work Ombudsman has publicised actions against pizza take-away operators over weekend and penalty rates in search results) [G6 family search excerpt].
- **Break rules:** Square default breaks "based on your business location" are defaults only [H2].
- **Timecard edits** are not documented as audit-logged [H8].
- **Biometric or location data:** geofencing involves employee location data; check privacy and consultation obligations before enabling; not researched.
- **Employee data** in Team documents is not designed for tax file numbers or contracts [H38 note]; keep sensitive HR files in a proper HR or payroll system.
- **Security of passcodes:** shared "team passcode" prevents individual tracking; use personal passcodes only [H7].

---

## 8. CATERING AND CORPORATE ACCOUNTS

### 8.1 Goal

Turn website and phone enquiries into quoted, deposited, scheduled and paid corporate orders, including standing orders, with the kitchen seeing them at the right time.

### 8.2 Square features (AU)

| Need | Feature | Path | AU / plan | Source |
|---|---|---|---|---|
| Quote | **Estimates**: send, customer accepts, convert to invoice (manual or automatic); multi-package estimates; deposit or milestone schedule | Orders & payments > Invoices > Estimates | AU: yes; Invoices Plus or Premium | [H29] |
| Invoice | Create and send invoices | Orders & payments > Invoices | AU: yes | [API-NOTES 4.12] |
| Deposit | Deposit as percentage or amount; Free plan basic deposit; milestone schedules (up to 12) need Invoices Plus; sales recorded and inventory reduced **only when the invoice is fully paid** | Invoices > payment schedule | AU: yes | [H27] |
| Standing orders | **Recurring invoices**: charges a card on file (at 10:00 local time on the chosen date) or emails an invoice; recurring series; edits apply to all future invoices; ending a series leaves outstanding invoices | Orders & payments > Invoices > Recurring series | AU: yes; Invoices Free and Plus | [H28] |
| Customer groups | Manual groups and smart groups; custom fields and filters; used for discounts and Marketing | Customers > Customer directory | AU: yes | [H30] |
| Group pricing | Discount with a customer group condition | Items > Discounts | AU: yes | [H24] |
| Afterpay for invoices | supported in AU | n/a | AU: yes | [API-NOTES 4.12] |

Invoices Plus **price (AU)**: not found on the pages read; the AU pricing page does not list it [M3]. Verify.

### 8.3 Setup checklist

1. Confirm the Invoices tier (Free vs Plus) and whether estimates are enabled. Estimates need Plus or Premium [H29].
2. Create the **"Corporate accounts" customer group** (manual) and a smart group for repeat catering buyers [H30]; add custom fields (company, ABN, cost centre, billing contact, PO number) in the Customer Directory [custom fields article, search excerpt].
3. Decide **payment terms** (for example due on delivery, or a set number of days) and deposit rule per order size. Set in invoice templates. The deposit percentage is Elie's decision.
4. Create an **estimate template** with GST shown and a clear validity period (the estimate expiry is not documented in the article) [H29].
5. Decide the **catering production calendar** (section 8.5) in the ops layer, because deposit-only invoices do not record sales.
6. Define cancellation and change terms and put them on the estimate.
7. Decide whether standing orders use recurring invoices (card on file) or a fixed template resent each cycle; recurring invoices do not document variable line items per cycle [H28].
8. Set an AR routine with reminders (Invoices payment requests allow reminders [API-NOTES 4.12]).

### 8.4 How website enquiries become Square customers and invoices

Design (INFERRED), using the Square APIs documented in [API-NOTES]:

1. **Enquiry form** on the Next.js site stores the enquiry in our database (company, contact, headcount, date, dietary needs, budget) with the consent record.
2. **De-duplicate** in Square: SearchCustomers by email, phone, or `reference_id` before CreateCustomer (CreateCustomer does not de-duplicate) [API-NOTES 4.9].
3. **Quote:** staff prepare an estimate in the Dashboard (estimates are not creatable through the Invoices API) [API-NOTES 4.12].
4. **Accepted:** convert to invoice (or create via API: CreateOrder, then CreateInvoice with DEPOSIT and BALANCE payment requests, delivery method EMAIL; SMS delivery cannot be set via the API) [API-NOTES 4.12].
5. **Link:** store the Square customer ID, order ID and invoice ID against our enquiry record; webhook `invoice.*` events update status [API-NOTES 4.12].
6. **Fulfilment:** a confirmed catering order moves to the production calendar and onto the KDS as in 8.5.
7. **Repeat:** after payment, add the customer to the Corporate accounts group (API: AddGroupToCustomer) [D4].

### 8.5 Large-order scheduling and the KDS

- **OBSERVED:** orders appear on Square products only if they include a fulfilment and are paid [API-NOTES 4.4]. A deposit invoice is not a paid order; sales and inventory are recorded when the invoice is paid in full [H27].
- **UNKNOWN:** whether an invoice-linked order with a PICKUP fulfilment ever reaches the KDS, and when. Test before relying on it.
- **Design (INFERRED):** keep a **Catering production calendar** in the ops layer (date, store, items, quantities, pickup time, allergens, status). Once the balance is paid, create or confirm a SCHEDULED paid PICKUP order so the kitchen sees it at `pickup_at - prep_time_duration` on the KDS [API-NOTES 4.3.2], or print the production sheet from the calendar. Add confirmed catering quantities to the forecast (section 2, step 5) so walk-in stock is not eaten by a large order.
- Cap catering volume per store per day from measured oven or station capacity (Elie's decision from kitchen data).

### 8.6 Weekly and monthly routine

**Weekly:** review the enquiry queue and response times; send estimates; chase unpaid invoices and deposits; confirm next week's catering production calendar against stock and staff; email reminders.

**Monthly:** corporate account review: revenue per account, order frequency, average order, repeat rate, overdue balances, margin per order; adjust group pricing; ask accounts for renewal or standing orders.

### 8.7 Exact reports

- Invoices list with statuses (Orders & payments > Invoices); overdue and unpaid filter.
- Customer Directory filtered by group; sales by customer group via Sales reports filtered to those customers (customer-level reporting detail not read; verify).
- Our enquiry-to-order funnel report.

### 8.8 Metrics (definitions)

- **Enquiry-to-quote rate** = quotes sent / enquiries.
- **Quote-to-order rate** = accepted quotes / quotes sent.
- **Average catering order value** = net catering sales / orders.
- **Days to pay** = payment date - invoice date.
- **Deposit collection rate** = deposits paid by the due date / deposits requested.
- **Repeat account share** = accounts with 2+ orders / accounts with 1+ orders.
- **Catering share of sales** = catering net sales / total net sales.

### 8.9 API automation vs manual

| Task | Automate? | Notes |
|---|---|---|
| Create customers, orders, invoices | Yes | Invoices, Orders, Customers APIs [API-NOTES 4.9, 4.12] |
| Deposits and balance as payment requests | Yes | DEPOSIT / BALANCE; INSTALLMENT needs Invoices Plus; max 13 requests [API-NOTES 4.12] |
| Recurring invoices | **No** (not supported by the Invoices API) | Dashboard recurring series; or the Subscriptions API [API-NOTES 4.12] |
| Estimates | **No** | Dashboard only [API-NOTES 4.12] |
| Invoice status changes | **No** | APIs cannot pay or set invoice status [API-NOTES 4.12] |
| Quote judgement, pricing, terms | Manual | |

### 8.10 Risks

- **Deposit-only orders invisible to the kitchen and reports** until fully paid [H27].
- **Duplicate customers** from CreateCustomer without a search [API-NOTES 4.9].
- **Card-on-file recurring charges** need documented customer consent [H28].
- **Food-safety and allergens for large batches**: not covered by Square.
- **GST on quotes and invoices:** confirm GST wording with the bookkeeper (ATO page not retrievable).

---

## 9. MULTI-LOCATION MANAGEMENT

### 9.1 Goal

Run Revesby, Bankstown and Roselands Centro from one Square account with consistent items and rules, store-level prices and availability where needed, and consolidated reporting.

### 9.2 Square features (AU)

| Need | Feature | Path | Source |
|---|---|---|---|
| Locations | Create locations; unique business profile and hours per location; per-location bank accounts or tags; location-specific reporting; limit of 300 per master account; locations cannot be deleted, only deactivated; only owners or those with account & settings permission can create locations, in the Dashboard (not the POS app) | Account & settings > My business > Locations | [H35] |
| Items per location | Location overrides so an item is not available at specific locations or channels; per-location availability and quantities; channel visibility (POS, online) per location | Items > Item library > Locations and channels | [H26, H25] |
| Price per location | **Price overrides** for multiple locations; the AU item guide refers to the article; the article found in search was a US-labelled page, so the AU path needs confirming on screen | Item library; "Create item price overrides" | [H26; US article 8242 in search results] |
| Per-location stock | Track stock and counts per location | Item library | [H20, H25] |
| Reports | Filter by location or multiple locations; Dashboard app location filter; kitchen performance across devices and locations | Reports; Dashboard app Home tab location pin | [H16, H13, search excerpt] |
| Team | Assigned locations per team member; permissions scoped to assigned locations | Staff > Team | [H5] |
| Scheduling | View shifts across selected locations together | Staff > Scheduling | [H6] |
| Marketing | Campaigns can target specific locations; discounts have applicable locations | Marketing; Discounts | [H23, H24] |
| Loyalty | Customers earn across participating locations; promotions can be location-based; Loyalty pricing is per location | Loyalty | [H21, H22, M3] |
| Franchise tooling | "Square for Franchises" exists in the AU help centre (central item edits, loyalty, online ordering reports) | Franchise articles | [search results] |

### 9.3 Setup checklist

1. Confirm the three Locations exist with correct names, addresses, trading hours (including public holiday hours) and bank accounts or tags [H35]. Note the business-name edit limit of three times every twelve months [H35].
2. Decide **what is the same everywhere** (item names, categories, modifiers, tax, kitchen routing categories) and **what varies** (price, availability, stock, hours). Document it in one table.
3. Build one master item library; for each item set location availability and channel visibility [H26].
4. Set **price overrides** only where a store's price really differs; keep an exception list so the website shows the correct price per store [API-NOTES 4.2].
5. Assign staff to the right locations and permission sets [H5].
6. Set up per-location devices, KDS stations, printers and bank accounts.
7. Create a location comparison custom report (section 5).
8. Test: change a price override at one store and confirm POS and online show it at that store only.

### 9.4 Weekly and monthly routine

**Weekly:** compare stores on sales, orders, basket, item mix, waste, ticket time, labour %; check items marked unavailable at each store against the stock plan; review roster coverage.

**Monthly:** item and price review per store; remove dead items; check permissions after staff changes; confirm all three locations are on the same reporting definitions.

### 9.5 Exact reports

Custom reports filtered by location (Reports > Custom); Kitchen performance by location [H13]; Labour vs sales by location [H3]; the timecard export for "Labour cost by location" exports **all locations** with no per-location choice [H3].

### 9.6 Metrics (definitions)

- **Location net sales share** = store net sales / total net sales.
- **Average ticket time by location** (Kitchen performance) [H13].
- **Waste rate by location** (section 2).
- **Labour % by location** [H3].
- **Item availability rate** = hours an item was available / trading hours, per location (our computation from availability changes).
- **Price exception count** = items with a location price override.

### 9.7 API automation vs manual

| Task | Automate? | Notes |
|---|---|---|
| Read locations, hours, timezone | Yes | Locations API; record the three IDs [API-NOTES 4.1] |
| Per-location prices (location overrides) | Yes | Catalog `location_overrides` (price per store) [API-NOTES section 10 item 4] |
| Item presence per location | Yes | Catalog presence settings [API-NOTES 4.2] |
| Create or deactivate locations | Dashboard only (owner) | [H35] |
| Consolidated report | Yes (our layer) | SearchOrders across location IDs |
| Permissions | **Not through the API** | Team permissions and passcodes are not exposed [API-NOTES 4.11] |

### 9.8 Risks

- **Price mismatch** between the website and the till if overrides are not read per location.
- **Catalogue changes propagate to all locations** unless overrides are set; make changes with a checklist and a second person for price edits.
- **Business-name edits are limited** [H35].
- **Per-location subscription costs** (KDS per device, Restaurants Plus per location, Loyalty per location) multiply by three [M1, M2, M3].

---

## 10. SECURITY AND CONTROLS

### 10.1 Goal

Limit who can do what, record sensitive actions, protect cash and card flows, and keep API credentials safe.

### 10.2 Controls in Square (AU)

| Control | Detail (OBSERVED) | Source |
|---|---|---|
| Two-step verification | Dashboard > Account & Settings > Personal Information > Sign in and security > Enable; methods SMS or an authenticator app (Google Authenticator, Microsoft Authenticator, Authy); owners can enable it for team members, who must complete setup at next sign-in if they do not do so voluntarily; team-wide setting at Account & Settings > My business > Security | [H33] |
| Passcodes | Owner passcode (highest permissions), team passcode (shared; prevents individual tracking), personal passcode (four digits, unique); require them in the POS app: More > Settings > Security; per-device timeout; at least one team member with a permission set is needed first | [H7] |
| Permission sets | Standard, Enhanced, Full, custom; location-scoped; limits depend on plan | [H5] |
| Refunds | Account owners or team members with the **transactions permission** can issue refunds; refundable within one year in Square; not to be used for security deposits | [H37] |
| Cash drawer | Only owners set up cash management; start and end drawer sessions; Reports > Payments > Cash drawers shows starting cash, sales, refunds, paid in/out and expected cash; cash drawer reports cannot be exported; enabled per device unless set through modes | [H34, H18] |
| Activity log | Records sensitive actions such as refunds, comps and voids, discounts; the by-team-member log is tied to a paid access tier (see 7.8); AU plan and price need verification | search excerpts, [M7] |
| Suspicious activity | Square has an AU article on recognising and reporting suspicious account activity | search result |

### 10.3 Setup checklist

1. **Owner account:** two-step verification enabled with an authenticator app (not SMS only), a recovery method recorded in a secure place, and a second authorised person added per Square's authorised representative process (AU article exists [Staff topic]).
2. **Everyone with Dashboard access:** two-step verification mandatory (Account & Settings > My business > Security) [H33].
3. Personal passcodes for every POS user; disable the shared team passcode for sales staff [H7].
4. Permission sets per role (7.8); refunds, discounts, item edits, price changes, customer exports and reports restricted to managers and above.
5. Cash management on every till; opening float defined; managers count at close [H34].
6. Refund rule: a refund over a threshold Elie sets needs a manager; every refund needs a reason recorded (manual procedure).
7. Remove access the same day a person leaves (Staff > Team); deactivate the passcode and the Team app login.
8. **API tokens** (see 10.5).
9. Enable email alerts or review the activity log weekly if the plan includes it.

### 10.4 Routines

**Daily:** manager reviews voids, refunds and comps; cash drawer variance.
**Weekly:** review permission changes and new users; check the activity log if available; review failed login or suspicious activity emails.
**Monthly:** access review (who has which permission set and locations); test one refund path end to end in a safe amount; confirm 2SV status for all users.

### 10.5 API token hygiene (developer.squareup.com)

- Store OAuth access and refresh tokens "encrypted in a secure database or keychain ... such as AES"; never in mobile apps, public clients or version control [D5].
- Renew OAuth access tokens "every 7 days or less"; access tokens expire after 30 days; check stored tokens are not older than 8 days at retrieval [D5].
- Use the least-privileged scopes; request permissions only for APIs the app calls [D5]. Mint a separate reduced-scope storefront token from the main server token [API-NOTES 2.3].
- "Never store the application secret, access token, or refresh token in a mobile application or on any public client" [D5].
- Revoke with the `RevokeToken` endpoint; sellers can disconnect from settings [D5].
- Verify webhook signatures with HMAC-SHA256 over `notificationUrl + rawBody`, with a constant-time compare [API-NOTES 8.1].
- Keep tokens out of the repository; use host environment variables and rotate on staff changes.
- Do not store PII or card data in Square metadata or in the sandbox [API-NOTES section 9].
- Money-moving actions through the API (refunds, payments) are limited to the server and are logged; require human approval in the ops layer for any automated refund.

### 10.6 Metrics (definitions)

- **Void, refund and comp rate** by team member and location = value / net sales.
- **Cash variance** = counted cash - expected cash, per drawer session.
- **No-sale or drawer-open count** (if reported; verify in the cash drawer report).
- **2SV coverage** = users with 2SV enabled / users with Dashboard access.
- **Dormant account count** = users not active in the period.
- **Token age** = days since last refresh; alert at 8 days [D5].

### 10.7 API automation vs manual

| Task | Automate? | Notes |
|---|---|---|
| Token refresh and expiry alerts | Yes | alert at 8 days [D5] |
| Webhook signature checks | Yes | [API-NOTES 8.1] |
| Permission sets, passcodes, 2SV | **Manual in Dashboard** | not exposed [API-NOTES 4.11] |
| Activity log review | Manual (no API found) | |
| Refund approvals | Manual or ops-layer approval | |

### 10.8 Risks

- Account takeover (Square provides guidance; 2SV reduces the risk) [H33].
- Shared passcodes defeat attribution [H7].
- Refund misuse; ensure the transactions permission is rare.
- Cash drawer set up on only some devices [H34].
- Reports with customer data exported to email; treat exports as personal information.

---

## 11. Not available in Australia, or not verified, and what to verify

| Capability | Status | Verify |
|---|---|---|
| Square Payroll | **No AU product found** | Square AU support (1800 760 137 appears on AU help pages) |
| Square Marketing SMS | **Not available in AU** (community statement); a US help article exists | Square AU support |
| Penalty rates in Square | Not documented | n/a; handle in payroll |
| Native Square-to-Xero Payroll timesheet sync | Not found | Xero Payroll marketplace; payroll provider |
| Square-native advertising | Not found | Square AU sales contact |
| Native conversion send to Google Ads or Meta | Not found | n/a; build in our ops layer |
| Estimates through the API | Not supported | n/a [API-NOTES 4.12] |
| Recurring invoices through the Invoices API | Not supported | n/a [API-NOTES 4.12] |
| Reporting API (Beta) | AU UNKNOWN | Square support |
| Restaurant Inventory (MarketMan) AU terms | UNKNOWN | Account Manager [H19] |
| Activity log by team member price and plan in AU | UNKNOWN | Dashboard Subscriptions page |
| Loyalty on API payment-link orders | UNKNOWN | smoke test 23 [API-NOTES] |
| Ready notification for API orders | UNKNOWN | smoke test 11 [API-NOTES] |
| Geofencing clock-in plan and setup | UNKNOWN | Square AU support |
| Gift card expiry display | UNKNOWN | test a card |

---

## 12. 30 / 60 / 90 day Square adoption plan

Roles (not names): **Owner** (Elie), **Ops manager**, **Store managers** (three), **Head baker / kitchen lead**, **Bookkeeper or accountant**, **Developer**, **Marketing owner**. Dates are relative to the day Elie approves the plan.

### Days 0 to 30: Foundation and facts

| # | Task | Owner | Done when |
|---|---|---|---|
| 1 | Record the Square plan and enabled features per location (Subscriptions page screenshot) | Owner | One table in section 13 is filled |
| 2 | Confirm legal entity that holds the Square account and each location | Owner | Documented |
| 3 | Confirm accounting tool, payroll tool and award with the adviser | Owner + Bookkeeper | Written confirmation per question |
| 4 | Two-step verification for all Dashboard users; personal passcodes for all POS users; remove shared passcode | Owner + Store managers | 2SV coverage 100% of users; no team passcode in use for sales |
| 5 | Permission sets per role and location | Owner | Each user mapped to a set; test login per role |
| 6 | Reporting timeframes, device names, daily summary emails | Ops manager | Daily email arrives for each store |
| 7 | Cash management on every till; close checklist | Store managers | A week of drawer sessions with variances explained |
| 8 | KDS stations and device codes at each store (if KDS is on the plan); routing categories assigned to every item | Head baker + Developer | A POS and a website test order show on the right station |
| 9 | Start collecting baseline data: weekly item sales by location and hour, waste log, stock-out log | Ops manager | 4+ weeks of data captured consistently |
| 10 | Run Square timecards in parallel with the current staff clock | Store managers | One full pay cycle compared; differences listed |
| 11 | Capture consent text and storage design (what is collected, wording, ledger) | Marketing owner + Developer | Consent ledger schema agreed; sign-off by Owner |
| 12 | Run the API smoke-test list that needs live credentials (once Elie approves real-money tests) | Developer | Pass/fail evidence for [API-NOTES section 10] |

### Days 31 to 60: Operate and measure

| # | Task | Owner | Done when |
|---|---|---|---|
| 13 | Forecast v1: trailing same-weekday baseline plus margin, daily prep sheet per store | Ops manager + Head baker + Developer | Prep sheets produced for two weeks; bias and stock-out time logged |
| 14 | Item availability: use Set quantity for limited bakes | Store managers | Items sell out correctly on POS and website |
| 15 | Kitchen performance report reviewed weekly; timer thresholds set from data | Head baker | Thresholds set; weekly review held |
| 16 | Slot capacity throttle in the Next.js checkout | Developer | Orders cannot exceed the cap per slot; tested |
| 17 | Xero connection (if Xero) and first monthly bank reconciliation | Bookkeeper | Month reconciled; fees account correct |
| 18 | Labour vs sales (needs Shifts Plus and assigned wages) | Ops manager | Weekly labour % per store |
| 19 | First promotion run with a control group and a unique code | Marketing owner | Result report with incremental sales and margin impact |
| 20 | Catering process: customer group, estimate template, deposit rule, production calendar | Ops manager | First catering order completed end to end |
| 21 | Attribution chain built and verified | Developer + Marketing owner | Test order produces a de-duplicated conversion in each platform |

### Days 61 to 90: Optimise and scale

| # | Task | Owner | Done when |
|---|---|---|---|
| 22 | Forecast v2: tune N and margins using back-tests; add events table | Ops manager | Bias and stock-out trend improved vs v1 |
| 23 | Ingredient-level control decision (MarketMan or own recipe table) | Owner + Head baker | Decision with cost and benefit recorded |
| 24 | Loyalty decision (go or no-go with cost model) | Owner + Marketing owner | Decision recorded; if go, programme rules published |
| 25 | Payroll export routine defined and tested | Bookkeeper + Ops manager | One pay run produced from the export with no manual hour edits |
| 26 | Standing orders for top corporate accounts | Ops manager | At least one recurring series live (if consent captured) |
| 27 | Quarterly access and security review | Owner | Review documented |
| 28 | Review and retire what did not pay back | Owner | One-page review |

---

## 13. Decisions and facts Elie must supply

**Square account and plan**
1. Which Square plan is each location on (Restaurants Free, Plus, Premium; Retail; other)? Which subscriptions are active: KDS, Shifts Plus, Loyalty, Marketing, Invoices Plus, Online?
2. Is Square Online used at all, or is the Next.js storefront the only online channel?
3. How many registers, KDS devices and printers per store, and what is the station layout?
4. Which legal entity holds the Square account and the three locations?

**Ordering channels and customers**
5. Current ordering channels (walk-in, phone, WordPress site, delivery apps, social, other) and the share of each.
6. Are customer emails or mobiles already collected, with what wording and consent?
7. Does the business want delivery (not available for API orders; PICKUP only) or only pickup?

**Finance and payroll**
8. Accounting tool (Xero is likely but unverified).
9. Payroll tool, and whether it is STP-enabled; who runs payroll.
10. Who is the bookkeeper or accountant who will confirm GST settings and bank reconciliation?
11. Is the catalogue tax set as GST-inclusive at all three stores?

**Awards and staff**
12. Which award or enterprise agreement applies to each group (bakers, counter, kitchen, drivers, catering staff, managers)? Confirm with an adviser or the Fair Work Ombudsman.
13. Headcount per store; which staff are casual, part-time, full-time, junior, apprentice.
14. Whether geofencing or location capture for clock-in is wanted.

**Operations**
15. Trading hours per store per weekday; bucket boundaries for forecasting; bake batch sizes; shelf lives.
16. How many weeks of Square sales history exist per store.
17. Waste recording rule (Damage vs Loss vs API WASTE).
18. Catering capacity per store per day; deposit percentage; payment terms; cancellation terms.

**Promotions and marketing**
19. Which promotion types are acceptable (percentage, fixed, combos, loyalty); margins that must be protected; any price promises already made.
20. Whether loyalty and email marketing are worth the monthly cost.
21. Who owns consent and the opt-out process.

**Governance**
22. Annual turnover (for the Privacy Act small-business test) and whether the business has opted in or is related to another covered body.
23. Who approves refunds above a threshold; refund threshold value.
24. Who may hold owner-level Square access and API tokens.
25. Approval to run live real-money smoke tests (one small real order per store, refunded after).

---

## 14. Source register (all retrieved 2026-10-02)

Fetch-tool extraction used unless "search excerpt" or "not read" is stated.

**Square AU help centre** (https://squareup.com/help/au/en/article/...)
- H1 8389-set-up-time-tracking
- H2 8391-set-up-breaks
- H3 6140-employee-timecard-reporting
- H4 8390-set-your-work-period-and-overtime-rules
- H5 5822-employee-permissions
- H6 7155-scheduling-with-team-management
- H7 8357-require-passcodes-at-point-of-sale
- H8 8392-edit-employee-timecards
- H9 7654-get-started-with-tip-pooling-for-team-management
- H10 7944-get-started-with-square-kds-android
- H11 7959-route-orders-with-your-kds
- H12 8103-troubleshoot-missing-orders-with-square-kds
- H13 6433-reporting-with-square-for-restaurants
- H14 6866-in-store-and-curbside-pickup-with-square-online-store
- H15 5072-summaries-and-reports-from-the-online-dashboard
- H16 8363-view-item-category-and-modifiers-sales-reports
- H17 6104-creating-custom-reports-in-the-online-dashboard
- H18 8362-print-export-or-email-your-reports
- H19 8610-manage-ingredient-inventory-with-square-restaurant-inventory
- H20 5228-basic-inventory-management
- H21 3952-create-a-loyalty-program-with-square
- H22 7794-get-started-with-square-loyalty-promotions
- H23 8412-create-marketing-campaigns
- H24 6606-happy-hour-and-discounts-with-square-for-restaurants
- H25 8495-beta-item-availability
- H26 8335-create-and-edit-items
- H27 6581-request-deposits-with-square-invoices
- H28 5096-process-recurring-or-subscription-payments
- H29 7215-create-an-estimate-online
- H30 6245-manage-customer-groups-and-filters
- H31 8485-create-vanity-code-coupons
- H32 6000-square-egift-cards
- H33 5593-2-step-verification
- H34 5152-cash-drawer-management
- H35 5580-manage-multiple-locations-with-square
- H36 6612-set-up-your-online-store-with-square-for-restaurants
- H37 5060-refund-overview
- H38 8128-manage-team-member-documents
- Topic page: https://squareup.com/help/au/en/topic/staff-and-payroll
- Search excerpt only (not read): 8413 (email campaign reports), 8344 and 8358 (cash drawer sessions and reports), 8387 (create and send invoices), 5498 (customer directory), 5860 (custom fields), 8121 (Square for Franchises), 5766 (suspicious activity), 6877 / 8654 / 7689 (Meta and Google Business Profile integrations)
- Read but not relied on: 6415 (returned US content), 6688 (returned the Support Centre home page), 7199 (home page; US SMS article)

**Square AU marketing pages** (https://squareup.com/au/en/...)
- M1 point-of-sale/restaurants/kitchen-display-system
- M2 point-of-sale/restaurants/pricing
- M3 pricing
- M4 staff/shifts/pricing
- M5 staff/shifts/features
- M6 payroll/demo
- M7 point-of-sale/team-management
- M8 press/square-restaurant-inventory-marketman (dated 2 April 2026 on the page)
- Not read: software/marketing (HTTP 429), payroll and staff pages previously reported as HTTP 429 in [API-NOTES]

**Square developer docs** (https://developer.squareup.com)
- D1 /docs/payouts-api/overview
- D2 /docs/catalog-api/cookbook/auto-apply-discounts/timeframe-discounts
- D3 /docs/orders-api/apply-taxes-and-discounts
- D4 /docs/customer-groups-api/what-it-does
- D5 /docs/oauth-api/best-practices
- D6 /docs/inventory-api/what-it-does
- API-NOTES: `/home/user/DOUGHBOSSV2/web/docs/square/api-integration-notes.md` (cites S1 to S88 on developer.squareup.com and squareup.com/help/au)

**Third party (official marketplace listing)**
- X1 https://apps.xero.com/au/app/square

**Australian government and regulators**
- G1 https://www.accc.gov.au/business/advertising-and-promotions/false-or-misleading-claims
- G2 https://www.accc.gov.au/publications/advertising-and-selling-guide/advertising-and-selling-guide/pricing/two-price-comparison-advertising
- G3 https://www.oaic.gov.au/privacy/privacy-guidance-for-organisations-and-government-agencies/organisations/small-business
- G4 https://www.oaic.gov.au/privacy/privacy-guidance-for-organisations-and-government-agencies/organisations/direct-marketing
- G5 https://www.accc.gov.au/business/advertising-and-promotions/gift-cards-and-discount-vouchers (search excerpt; also .../rules-for-gift-cards)
- G6 https://awards.fairwork.gov.au/MA000003.html (Fast Food Industry Award 2020; read via extraction)
- G11 https://www.accc.gov.au/consumers/pricing/price-displays and https://www.accc.gov.au/media-release/accc-takes-action-against-jetstar-and-virgin-for-drip-pricing-practices (search excerpts: was/now and drip pricing)
- Search excerpts only (page fetch failed): https://www.acma.gov.au/avoid-sending-spam (HTTP 503); https://www.fairwork.gov.au/pay-and-wages/paying-wages/record-keeping and https://smallbusiness.fairwork.gov.au/keeping-the-right-records (HTTP 503); https://www.ato.gov.au/businesses-and-organisations/super-for-employers/about-payday-super (HTTP 403); https://awards.fairwork.gov.au/MA000004.html (search excerpt for retail bakery coverage); https://www.fairwork.gov.au/employment-conditions/awards/list-of-awards (HTTP 503)
- Fair Work enforcement items on pizza take-away penalty rates appeared as search results on fairwork.gov.au (newsroom); not read in full.

**Community (not documentation)**
- Square Community post stating SMS Marketing is US-only; Square Community posts on timecard export to Xero and Google Ads conversion tracking.

---

## 15. Open items for the next revision

1. Read the Fair Work, ACMA, ATO and FWC pages in full once those hosts respond, and replace the "search excerpt" rows.
2. Confirm AU pricing for Invoices Plus, Advanced Access, Square Restaurant Inventory and Loyalty tiers from the Dashboard Subscriptions page.
3. Run the live smoke tests in [API-NOTES section 10], especially items 9, 11, 22, 23, 24 and 25, then update sections 3.6, 4.2, 7.6 and 8.5.
4. Read the Google, Meta and GA4 conversion documentation (outside this slice's source list) and confirm the chain in 6.4.
5. Research the Food Standards Code and allergen obligations for the three kitchens.
