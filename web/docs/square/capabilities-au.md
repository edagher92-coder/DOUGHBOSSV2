# Square capability matrix for Dough Boss (Australia)

Prepared for Elie Dagher. Evidence base for the question: can Square be the system of record (catalogue and prices, orders and payments, customers, loyalty, labour) for three Sydney stores (Revesby, Bankstown, Roselands Centro), so that website orders land on each store's POS and KDS.

- Retrieval date for every source below: 2026-10-02 (today).
- Scope: Square product capability, Australian availability, published plan tiers, what Elie must enable or check. API field-level detail is covered in the separate file `api-integration-notes.md` and is only touched here where it decides a capability question (for example, whether API orders reach the KDS).
- Read-only research. No Square API call, login, registration or connector was used. Nothing in this file was tested against Dough Boss's own Square account.

## How to read this file

Evidence labels:

- OBSERVED: read on the cited page on 2026-10-02. Quotes are exact.
- INFERRED: my reasoning from observed facts. Always marked.
- UNVERIFIED: seen only in a search-result summary or a non-AU page, not read on an official AU page.

Australian availability labels:

- AVAILABLE-AU: an Australian page, an AU help-centre article, or an official page that names Australia says it exists.
- NOT-AVAILABLE-AU: official AU evidence says it does not exist here, or the AU equivalent is absent and Square staff say so.
- UNKNOWN: not established. The "check" column says where to look.
- NOT-FOUND: searched the official sources listed and found nothing. This is not proof of absence.

Source citations look like [S12]. The full register with URLs is at the end. All AU help-centre articles are under `https://squareup.com/help/au/en/article/`, all AU marketing pages under `https://squareup.com/au/en/`.

Access notes. No host was blocked by the network policy, so `read_documentation environment.network` was not needed. squareup.com returned HTTP 429 (rate limit) on bursts of requests; pages were re-fetched with back-off. One search tool was used only to discover URLs; every factual claim below was checked against a page I read, except where marked UNVERIFIED.

---

## 1. Headline findings (the ones that change what we build or configure)

1. Card surcharging ended in Australia on Thursday 1 October 2026 (yesterday). Square switches off its card-surcharge setting automatically, but a surcharge built manually as a service charge, a sales tax or automatic gratuity must be deleted by the seller. Weekend and public holiday service charges can continue. [S11] [S12] [S13]
2. Square Payroll is not an Australian product on the evidence available. `https://squareup.com/au/en/payroll` returns 404, the AU navigation has no Payroll entry, and a Square staff reply on the Square Community says "Square doesn't offer a built-in payroll service" and points to Shifts timecard export plus Xero, MYOB or QuickBooks. Plan payroll outside Square. [S27] [S28] [S15]
3. API-created orders reach the store only if they use a PICKUP fulfilment and are paid. Per Square's developer docs, "Orders with fulfillments appear on Square products (such as the Square Dashboard and Point of Sale application) only after they're paid for", and DELIVERY fulfilments "[are] not available to a seller and not shown in the Square Point of Sale unless you have a formal partnership agreement with Square". A July 2026 developer-forum post reports PICKUP orders appear in Order Manager, on the KDS and print. [S85] [S86]
4. The KDS shows online orders only when told to. Routing is a per-device setting ("View online, kiosk and delayed fulfilment orders") and items must be assigned to kitchen routing categories. Square KDS runs on Android tablets only; iPad KDS users "will need to migrate". [S54] [S57] [S56]
5. A scheduled order does not become an active kitchen ticket until pickup time minus prep time. If no prep time is supplied, a SCHEDULED order "becomes active immediately". For catering pre-orders, prep time per order must be set deliberately. [S85]
6. Square Online has three AU tiers: Free $0, Plus $36/mo billed annually, Premium $99/mo billed annually. Custom domain, pre-orders, item quantity limits, QR ordering, time-based categories, order status text alerts, scheduled item updates and "Facebook and Google ads" are all Plus or above. Online card fee is 2.2% on Free and Plus, 1.9% on Premium. [S5]
7. Square for Restaurants has Free ($0), Plus ($129/month per location) and Premium (custom). Coursing, seat management, reopen closed bills, shift and close-of-day reports, live sales, category rollups, section sales, menu reports, unlimited KDS devices, Shifts Plus, unlimited permission sets and 24/7 phone support are Plus. [S1]
8. Card rates published for AU: 1.6% in person (1.9% for older sign-ups using Reader, Stand or Tap to Pay), 2.2% online, keyed, card-on-file, invoices and Virtual Terminal, Afterpay 6% + 30 cents excl. GST. Which in-person rate Dough Boss pays depends on its sign-up date and hardware and must be read from the Dashboard. [S1] [S8] [S9]
9. Marketing consent is strict. Emails collected through receipts, invoices, loyalty or appointments do not make a customer a Square Marketing "subscribed customer". Only explicit consent recorded in Square (or imported with an Email Subscription Status) counts. Square Marketing in AU is described as email; native SMS marketing was not found. [S30] [S31] [S33]
10. For corporate catering the AU toolset exists: Invoices (Free $0, Plus $30/mo), estimates with up to nine packages, deposits and milestone schedules (Plus), recurring invoice series, House Accounts (customer account with spending limit, invoiced later), Contracts with e-signature, and Xero/MYOB/QuickBooks Online sync. Square's own pages disagree on which tier unlocks estimates (see section 5). [S40] [S41] [S42] [S43] [S44] [S45]
11. Overtime and break logic in Square Shifts is generic (daily/weekly overtime, double time, mandatory breaks). No Modern Award penalty rates, junior rates, allowances, leave loading or superannuation calculations were found in the official AU docs. Square points sellers to the Fair Work site for break rules. Treat Square Shifts as a timesheet and roster tool, not an award interpreter. [S18] [S19] [S21]
12. Ingredient-level inventory is not in Square for Restaurants itself. It is the paid add-on "Square Restaurant Inventory by MarketMan" at "+$149/mo. per location" (Plus and Premium only), and MarketMan's marketplace listing says it is available in Australia. [S1] [S83h]

---

## 2. Summary matrix

Plan/tier column quotes the published AU wording. "Plus" under a product means that product's own Plus tier (Restaurants Plus, Online Plus, Shifts Plus, Invoices Plus), not one account-wide plan.

| Capability | AU status | Plan or price (as published) | Key source |
|---|---|---|---|
| Square for Restaurants POS (tables, open bills, bar tabs, order manager, menus, modifiers, floor plan) | AVAILABLE-AU | Free $0/month; Plus $129/month per location; Premium custom | [S1] [S2] |
| Course management, seat management, reopen closed bills, time-based table colours | AVAILABLE-AU | Plus and Premium only | [S1] |
| Item availability, "86" (sold out), item counts, auto-86 | AVAILABLE-AU | Grid shows item availability and counts in Free; FAQ lists "auto 86ing, item counts" under Plus (conflict) | [S1] [S48] |
| Order sources into POS (POS, Online, kiosk, delivery apps) | AVAILABLE-AU | Routing needs Restaurants Plus/Premium or a KDS subscription | [S54] |
| Kitchen Display System | AVAILABLE-AU | "$25 Per month per device" (30-day trial); unlimited devices with Restaurants Plus | [S3] [S1] |
| Group ordering (one merged kitchen ticket) | AVAILABLE-AU | Restaurants Plus and Premium | [S74] |
| Square Online (website, ordering page, pickup, in-house delivery, QR ordering) | AVAILABLE-AU | Free $0; Plus $36/mo billed annually; Premium $99/mo billed annually | [S5] |
| Online pre-orders, quantity limits, time-based categories, scheduled item updates | AVAILABLE-AU | Online Plus and Premium | [S5] [S96] |
| Custom domain or custom subdomain on Square Online | AVAILABLE-AU | Online Plus and Premium | [S65] [S66] |
| Order with Google, Facebook/Instagram food ordering | AVAILABLE-AU | Online Free, Plus and Premium (Order with Google) | [S72] [S5] |
| Meta for Business connection (pixel, Ads Manager, catalogue) and "Facebook and Google ads" | AVAILABLE-AU | Online Plus and Premium | [S71] [S5] |
| Square Payments (card, tap, online) | AVAILABLE-AU | 1.6% in person, 2.2% online/keyed, Afterpay 6% + 30c excl. GST | [S8] [S9] |
| Payouts | AVAILABLE-AU | Next-day free; instant at 1.95% (min $5, max $5,000/day) | [S10] |
| Tap to Pay on iPhone and Android | AVAILABLE-AU | "Free with your iPhone or Android phone" | [S7] [S8] |
| Card surcharging | NOT-AVAILABLE-AU from 1 Oct 2026 | Banned by RBA decision; weekend/public holiday service charges continue | [S11] |
| Catalogue and inventory (variations, modifiers, bulk import, stock alerts) | AVAILABLE-AU | Free with a Square account | [S93] [S49] |
| Per-location price overrides | AVAILABLE-AU | Dashboard feature; plan not stated | [S50] |
| Dietary, allergen and calorie fields on items | AVAILABLE-AU (fields); display on web UNKNOWN | Item type "Prepared food and beverage" | [S51] [S52] |
| Ingredient-level inventory | AVAILABLE-AU as add-on | MarketMan "+$149/mo. per location" | [S1] [S83h] |
| Customer Directory and smart groups | AVAILABLE-AU | "Free when you take payments with Square" | [S38] [S39] |
| Square Loyalty | AVAILABLE-AU | $49 / $99 / $149 per month per location by loyalty visits | [S34] [S87] |
| Square Gift Cards and eGift Cards | AVAILABLE-AU | Free set-up; from 74c per physical card; 2.5% load fee | [S37] |
| Discounts (manual, automatic, BOGO, schedule, customer group) | AVAILABLE-AU | Included | [S46] |
| Coupons and promo (vanity) codes | AVAILABLE-AU (coupon creation on Online Free+; vanity-code article not read) | Online coupon creation: Free, Plus, Premium | [S5] [S46] |
| Square Marketing (email campaigns and automations) | AVAILABLE-AU | $20 / $35 / $50 per month by contacts (page); "$20/mo. per location" (Restaurants page) | [S33] [S1] |
| SMS marketing (native) | NOT-FOUND | Loyalty sends transactional texts only; Mailchimp "Email and SMS" is a third-party app | [S34] [S83d] |
| Square Invoices, estimates, recurring, deposits | AVAILABLE-AU | Free $0 + processing; Plus $30/mo | [S40] |
| House Accounts | AVAILABLE-AU | Dashboard feature; plan not stated | [S44] |
| Contracts and e-signature | AVAILABLE-AU | "Build professional contracts for free" | [S45] |
| Square Shifts (roster, timecards, breaks, overtime, tip pooling) | AVAILABLE-AU | Free up to five team members; Plus "$5/mo per team member"; Shifts Plus included in Restaurants Plus | [S14] [S1] |
| Advanced Access (custom permission sets, badges, activity log) | AVAILABLE-AU | "$35/mo per location"; included in Restaurants Plus | [S26] [S1] |
| Square Payroll | NOT-AVAILABLE-AU (strong evidence, confirm with Square) | AU page 404 | [S27] [S28] |
| Payroll export (timecards to CSV, third-party sync) | AVAILABLE-AU | Shifts | [S15] [S20] |
| Reporting (sales by item, category, modifier, team member, discounts, taxes) | AVAILABLE-AU | Free with account; some reports Plus | [S75] |
| Custom reports | AVAILABLE-AU | Dashboard only; export only, no saved date filter | [S77] |
| Scheduled reports | PARTIAL | Only a daily sales summary email, set by account owner | [S76] |
| Accounting integrations (Xero, MYOB, QuickBooks Online) | AVAILABLE-AU | Xero listing names Australia; MYOB named on AU Invoices page | [S83a] [S40] |
| Delivery platforms (Uber Eats, DoorDash, Deliverect, Doshii; Menulog via partners) | AVAILABLE-AU | Uber Eats and DoorDash listings name Australia; Menulog only via partner text | [S61] [S83] [S84] |
| Square-native advertising | PARTIAL | No standalone "Square Advertising" product found; ads tools sit inside Square Online Plus and Meta for Business | [S5] [S71] |
| Security (roles per location, passcodes, badges, activity log) | AVAILABLE-AU | Basic free; unlimited permission sets Restaurants Plus or Advanced Access | [S24] [S26] |

---

## 3. Detail by capability

Each block states what it does, Australian availability, the plan or tier, what Elie must enable or check, and the Dough Boss use.

### 3.1 Square POS and Square for Restaurants

- What it does. Restaurant POS with fast order entry, menu manager, order manager, table management, open bills, bar tabs, floor plan, cash management, advanced discounts. "All three Square for Restaurants plans include fast order entry, menu manager, order manager, table management, open bills, auto gratuity, remote device management, multilocation management, the Square Team Management free plan and free phone support Monday–Friday, 9:00 am–5:00 pm AEST." [S1]
- AU: AVAILABLE-AU. The page is the AU pricing page and prices are in AUD. [S1]
- Tier (quotes):
  - Free: "$0/month + processing fees", "Unlimited countertop POS devices", "Unlimited locations", "Free phone support M–F, 9:00 am–5:00 pm AEST".
  - Plus: "$129/month per location + processing fees", "Unlimited countertop POS devices", "+$50/mo. per location for locations using mobile POS", "Unlimited Square KDS devices", "24/7 phone support", "Start 30-day free trial".
  - Premium: "Custom pricing"; "Businesses processing $250,000 or more in yearly payments may be eligible for custom pricing."
  - Plus-only POS features: reopen closed bills, seat management, course management, time-based table colour indicators, shift reports, closing procedures, close-of-day reports, live sales, shared settings, category rollup reporting, section sales reports, menu reports. [S1]
- Tables, courses, open tickets: table management and open bills are in all plans; "Course management" is "Not included" on Free and "Included" on Plus. [S1] Open tickets, predefined tickets, floor plans and split/merge have AU help articles that I listed but did not read (see the "not read" list in the source register), so treat detail beyond the pricing grid as UNVERIFIED.
- Order routing and modifiers: see 3.2 (routing) and 3.5 (modifiers).
- "86" items: see 3.5.
- Order sources: POS devices, Square Online, kiosk, and third-party delivery integrations can all route to the KDS or printers. [S54] [S61] [S60]
- Enable or check: Dashboard > Settings > Account & Settings > Business information > Pricing & subscriptions shows the live subscription list (path given in Square's Loyalty API doc). [S87] Confirm whether Dough Boss is on Free or Plus per location. Confirm that each store is a separate Square location with correct business hours (live sales uses location business hours). [S79]
- Dough Boss use: a single Square account with three locations gives one catalogue, per-location price and availability overrides, and one reporting view. Restaurants Plus is the tier that carries the kitchen, labour-permission and reporting features Elie asked about.
- Hardware prices (AU pricing page): Square Handheld "$349", Register "$1,099", Terminal "$329", Reader "$65", Stand "$149", Kiosk "$149", Tap to Pay "Free with your iPhone or Android phone", "Kitchen display screen 21.5"" "$999". "Hardware is not included with your Square for Restaurants subscription." Hardware can also be rented (AU navigation lists "Rent hardware"). [S7] [S1]

### 3.2 Kitchen Display System (KDS)

- What it does. Digital tickets that replace kitchen printers, with prep and expeditor stations, ticket timers, routing, and performance reporting. [S3]
- AU: AVAILABLE-AU (AU product page with AUD pricing). [S3]
- Tier: "After a 30-day free trial, Square KDS starts at $25 per month per device." Unlimited KDS devices are included in Restaurants Plus at "$129 per month per location". The Restaurants pricing grid is ambiguous on whether the Free plan includes KDS; the KDS product page says "Square for Restaurants Free included" with "$25 Per month per device". [S3] [S1]
- Hardware and software: Android only. "Square KDS is only available on Android tablets." Listed compatible devices: MicroTouch 10.1", 15" and 21.5", Lenovo Tab M10, Samsung Galaxy Tab A8 WiFi, Samsung Galaxy Tab A9+ 11". Minimum recommended: 3 GB RAM, Google Play Store, Android 9 or above. Kitchen-grade devices are recommended "in environments with grease or heat" (relevant to ovens and flour). iPad KDS users must migrate. Mounts: arm mount or 100 mm x 100 mm VESA. [S56] [S54]
- Routing by category and location: KDS devices are created with a device code per location and typed Prep or Expeditor. Kitchen routing categories send items to the right station. "Use kitchen routing categories on KDS" must be on and items assigned to categories. Per device you choose which POS devices send tickets ("Receives orders from"). [S56] [S57] [S54]
- Do online or API orders appear? Online: yes, when "View online, kiosk and delayed fulfilment orders" is toggled ON for the KDS device. Third-party partner orders: Uber Eats orders "appear on Order Manager, appear on KDS and trigger printers"; DoorDash and Deliverect orders route to the POS. Orders API orders: only paid, PICKUP-fulfilment orders (see headline 3 and 3.14). [S54] [S61] [S60] [S85] [S86]
- Ticket timing: yellow and red timers set per device in "Timers & alerts"; new-ticket sound; ticket layouts of four to ten columns; manual drag reorder ("Open Beta", KDS Android 7.15 or later); "Move ready tickets to front" on Expo (KDS 7.18.0 or later); "Combine identical items"; "Staggered item prep times" (items appear at staggered times from their prep times, but "Item prep times do not sync across devices"); "All Day counts" panel with optional separate fired and held counts. [S56] [S55]
- Prep times and order throttling: no KDS-level throttle was found. Throttling lives on the online channel: "Set a quantity limit for pickup and delivery orders", capacity-restricted simultaneous pickups, "Busy Mode" (adds 15 minutes to prep time; can extend by 15 minutes, 30 minutes or 1 hour), and pausing online orders for pickup or delivery. [S63] [S58]
- Reporting: kitchen performance report shows completed ticket count and average completed ticket time "across all devices and locations", but "only tracks tickets sent to your Square kitchen displays. Items and tickets sent to printers are not tracked." [S78]
- Enable or check: KDS subscription or Restaurants Plus; a device code per store; routing categories (for example Bakery, Pizza, Wraps, Drinks); item prep times populated; the online-orders toggle. If an order is missing, Square's six-step checklist starts with device codes (which "expire if not used within 48 hours"). [S57]
- Dough Boss use: "All Day counts" is the closest Square-native bake-planning view (total of each item across open orders). Per-store expo plus a bakery prep station is a natural fit.

### 3.3 Square Online, Online Checkout, website ordering and pickup scheduling

- What it does. A Square-hosted website or ordering page, tied to the same catalogue as the POS, with pickup, in-house delivery, on-demand delivery (Nash), QR table ordering, customer accounts and reorder. [S4] [S5]
- AU: AVAILABLE-AU. [S5]
- Tier (quotes). Free: "$0", "2.2%". Plus: "$36 /mo billed annually", "2.2%". Premium: "$99 /mo billed annually", "1.9%". Free-plan features include "Pickup, local delivery and shipping", "Customer accounts", "Sell on social", "Surcharging on weekends and public holidays", Order with Google, multi-site and location management, unlimited items, coupon creation, SEO tools. Plus adds themes, "Connect custom domain", custom embed code, QR code ordering, personalised ordering, "Pre-orders", "Item quantity limits", "Order status text alerts", abandoned cart recovery, item badges, "Time-based categories", "Scheduled item updates", "Omnichannel discounts", "Facebook and Google ads", site statistics, advanced eCommerce statistics. Premium adds the lower online rate. [S5] [S6]
- Multi-location: "Take online orders from all your locations on a single website." All sites share one item catalogue; items and categories can be assigned to specific sites. For Order with Google "each location can only be associated with one site at a time". [S4] [S68] [S72]
- Prep-time and pickup rules: automatic timing considers "when orders are available for pickup, how soon they can be picked up, when you start prepping orders, required prep time per order, how far in advance customers can place orders, and how simultaneous pickups are restricted based on your capacity". You can also choose to print tickets by scheduled pickup time or immediately. Fulfilment hours and restricted dates (holidays) are set per location. [S63]
- Delivery: in-house couriers (delivery region by postcode or radius, fees, minimum order) or on-demand couriers via Nash, "You cannot offer both services at the same location". On-demand: delivery areas managed by Nash, cannot set zones by postcode. [S62]
- SEO features: SEO settings per site and per page, image alt text, hide from search engines, verification with Google and Bing, redirects, custom favicon. Square "does not provide SEO consultation". [S70] [S69]
- Custom domain behaviour: Plus or Premium. A domain bought elsewhere connects automatically for some hosts or manually by DNS records ("DNS changes can take 24-48 hours to propagate"); SSL is included. A custom subdomain such as "store.mybusiness.com" is supported (Plus or Premium). Free plan gets "mybusiness.square.site". Whether Crazy Domains supports the automatic connect flow is UNKNOWN; manual DNS is the fallback. [S65] [S66]
- Embedding: embed code sections put external content into a Square Online page (Plus or Premium). For the reverse, Square's marketing page says Payment Links can be placed as "Embed a button" on an existing site, and describes WooCommerce, Wix and WordPress payment integrations. The ordering-page route for an existing site is: build the ordering page, publish it to a domain or subdomain, and link to it from the other site. [S67] [S94] [S64]
- Tracking: Google Analytics, Facebook Pixel, Pinterest, Bing verification and custom header or footer code, Plus or Premium. [S69]
- Enable or check: Online subscription tier; a subdomain such as `order.` on the Dough Boss domain; fulfilment windows per store; the quantity-limit message (see 3.19). A WordPress site on Crazy Domains can link to a Square-hosted ordering page without moving hosting. [S64]
- Dough Boss use: Square Online is the fastest path to "website orders appear on each store's POS and KDS" with no custom code. The custom Next.js storefront is an alternative path that depends on the Orders API behaviour in 3.14 and headline 3.

### 3.4 Square Payments in Australia

- What it does. Card, tap, wallet, EFTPOS-brand and online acceptance. "Square works with any Australian-issued and most international chip or swipe cards with a Visa, Mastercard or American Express logo, as well as bank-issued EFTPOS chip cards." [S8]
- Fees as published: "In-person 1.6% per transaction" (contactless, mobile, chip + PIN); "Online 2.2% per transaction" (Square Online, online API payments, invoices); "Remote 2.2% per transaction" (keyed, card on file, Virtual Terminal); "Buy now, pay later 6% + 30 cents per transaction (excl. GST)". Fine print: "1.6% card present rate applies for Square Sellers who sign up on or after 30 May 2024, or who signed up prior to this date using Square Terminal or Square Register. The rate of 1.9% will apply for all other Square Sellers who signed up prior to this date when using Square Reader, Square Stand or Tap to Pay." Custom pricing: "If you process over $250,000 per year". [S8] [S1] [S9]
- Included free per transaction: dispute management ("We cover the fee for every dispute that we fight on your behalf"), fraud prevention, end-to-end encryption, PCI compliance. [S9]
- Payout timing: three options. "Standard next day transfer"; "Instant transfer ... for a 1.95% fee per transfer", minimum $5.00, maximum $5,000 per day, new sellers may start at $500 per day; "Manual transfer". Default close of day is "midnight Melbourne time". Automatic transfers occur on weekends and public holidays "as long as the linked bank account is eligible for real-time payments (NPP)". Manual transfers do not occur on public holidays. [S10]
- Tap to Pay: available on iPhone and Android with no extra hardware; "Free with your iPhone or Android phone". Tap to Pay on iPhone "is not available in all markets" (Apple's list governs). [S7] [S8]
- Offline mode: payments stored up to 24 hours; "eftpos-only cards, Square Gift Cards and Afterpay transactions do not work with offline payments". [S8]
- Surcharging rules: from "Thursday 1 October 2026, card surcharging will no longer be permitted in Australia ... applies to all debit, prepaid and credit cards on the eftpos, Mastercard and Visa networks". Square disables its setting automatically; manual workarounds (service charge, sales tax, automatic tipping) must be removed by the seller before trading on 1 October. Weekend and public holiday service charges, booking fees and service fees "can continue", but "A fee that applies only to card payments below a threshold is a card surcharge, which can't continue", and fees applying "only to some payment methods are probably card payment surcharges". The older footnote on the payments page about weekend surcharging "must comply with ACCC guidelines" pre-dates this change; use the 1 October article. Service charges are "not yet available with Square Invoices, Square Payment Links or Afterpay transactions". [S11] [S12] [S13] [S8]
- Hardware: Reader $65, Terminal $329, Stand $149 (iPad not included), Register $1,099, Handheld $349, Kiosk $149. [S7]
- Enable or check: your in-person rate (sign-up date and device), a surcharge audit (Dashboard > Settings > Account & Settings > Payments > Service charges and Sales taxes; Restaurants > Service settings > Automatic gratuity), and whether signage, web copy and quote templates still mention a card fee. [S11] [S12]
- Dough Boss use: with surcharging gone, processing cost has to be built into prices. Bulk price edits (up to 250 variations at a time) and scheduled price changes are available. [S11]

### 3.5 Catalogue and inventory

- What it does. One item library with variations, modifier sets, categories, menus, images, per-location availability, stock tracking and alerts. [S51] [S52] [S93]
- AU: AVAILABLE-AU. "Managing inventory with Square is free." [S93]
- Multi-location price and availability: item "Locations and channels" assignment plus "location overrides"; price overrides per location set in the Dashboard (cannot be created in the POS app); items show "Varies by location". Menus control what shows on POS, online ordering, kiosks and delivery apps, with time-based availability and location-specific offerings. [S51] [S50] [S52]
- Stock alerts: low-stock alerts per item and location; emails are sent "90 minutes after each location's configured closing time"; Square's marketing page says "a daily stock alert email". Bulk CSV import and export. [S49] [S93]
- Sold out or "86": "you can 86 the item or modifier by marking them as 'sold out'", from Dashboard or POS, optionally with an automatic reset time; marking a modifier sold out hides it from checkout by default. Auto-86 on stock count is described as a Plus feature in the FAQ. [S48] [S1]
- Variations and modifiers: modifiers can be positive or negative priced; nested modifier sets are supported on food and beverage modes (display varies on third-party delivery and KDS platforms); modifier order is set per item; modifier tile colours for POS and KDS; "Kitchen name" separate from the customer-facing name. [S47] [S51] [S82]
- Dietary and allergen fields: item type "Prepared food and beverage: Best for restaurants and other food venues. Includes optional nutritional information for buyers with calorie counts, dietary preferences and allergens." POS staff can press and hold a menu tile to see calories, dietary preferences and allergens. The Catalog API exposes a dietary preference type of STANDARD or CUSTOM with `standard_name` or `custom_name`. Exactly how these appear on the Square Online ordering page was not confirmed. [S51] [S52] [S91]
- Custom attributes: Text, Selection, Number or Toggle attributes on items and variations (Dashboard). Usable for fields such as halal status, pack size, or catering lead time. [S53]
- Images: JPG, JPEG, PNG, GIF; up to 20 MB and 2560 x 2560 pixels; "We recommend at least 2000 x 2000 pixels with a 1:1 aspect ratio". "You cannot have different images for the same item across your point of sale app and Square Online." [S95]
- Not in Restaurants: stock transfer between locations and purchase orders are documented for Square for Retail. Ingredient-level tracking is the MarketMan add-on. [S2] [S1]
- Enable or check: tracking mode per item, low-stock thresholds, kitchen names, prep times, whether half-and-half or build-your-own pizza can be modelled with nested modifiers (UNKNOWN: not verified; test on one pizza).
- Dough Boss use: one catalogue with per-store price overrides supports the three stores; custom attributes can carry catering-only flags.

### 3.6 Customer Directory and segments

- What it does. Free built-in CRM: profiles created at sale, purchase history, custom fields, notes and attachments, import and export, filters and groups. [S38]
- AU: AVAILABLE-AU; "Free when you take payments with Square". [S38]
- Segments: smart groups "regulars" (default: three visits in the last six months) and "lapsed" (default: no visit in six weeks), editable; manual groups; channel filters (in-store, online, third-party apps, subscriptions, invoices). Groups can drive automatic discounts. [S39] [S46]
- "Instant Profiles": when a chip card is inserted, Square may collect some cardholder name details from the card. Worth noting for privacy notices. [S39]
- Enable or check: custom fields (for example company, ABN, store preference), duplicate merge, import template columns. Account owners can export; treat exports as personal data. [S39] [S25]
- Dough Boss use: tag corporate buyers and catering contacts; build lapsed and regular groups per store.

### 3.7 Loyalty

- What it does. Points by visit, spend, item or category; up to 15 rewards; multi-tier; promotions (bonus or multiplier points); Apple Wallet pass; enrolment at POS, on Square Online, via Invoices or a shareable link; transactional text notifications. [S34] [S35] [S36]
- AU: AVAILABLE-AU. Developer docs: "Square Loyalty is available in Australia, Canada, France, Ireland, Japan, Spain, the United Kingdom, and the United States." AU accrues on the after-tax purchase amount. [S87]
- Tier: "0-500 loyalty visits $49 per calendar month, per location"; "501–1,500 loyalty visits $99"; "1,501+ loyalty visits $149"; 30-day free trial; Restaurants Premium has "Custom pricing available". A seller can have one loyalty program, active at multiple locations, and up to 10 active or scheduled promotions. [S34] [S1] [S87]
- Consent: customers enrol with a phone number; the text notification and phone-number consent wording was not read in an AU article. Check before launch. [S34]
- Enable or check: Dashboard > Loyalty > Get started; which items are exempt; per-location participation (billing is per location); consent text at enrolment.
- Dough Boss use: after the Customer Directory is clean, a spend or visit programme across all three stores. At three locations the lowest published tier is 3 x $49 = $147/month [INFERRED arithmetic].

### 3.8 Gift cards

- What it does. Physical and eGift cards sold in person and online, redeemable at any location, balances reloadable, no expiry. [S37]
- AU: AVAILABLE-AU. [S37]
- Cost: eGift "Free to set up" plus "a 2.5% load fee and standard processing fees"; physical "From 74¢ per physical card" plus the same fees; "Do Square Gift Cards expire? No." Gift cards cannot be used on orders placed through partner delivery integrations. [S37] [S60]
- Enable or check: where eGift cards are sold (Square Online or a link), and whether corporate gifting needs a custom design.
- Dough Boss use: corporate gifting, staff rewards, store credit via refund-to-gift-card.

### 3.9 Discounts, coupons and promo codes

- What it does. Manual and automatic discounts with advanced rules: category targeting, BOGO, quantity, customer group, scheduling (for example happy hour) and minimum spend. "If you advertise or promote item(s) at a discounted price, it's your responsibility to ensure you're meeting ACCC guidelines." [S46]
- AU: AVAILABLE-AU. [S46]
- Coupons and promo codes: "Coupon creation" is listed Free, Plus and Premium for Square Online; Square Marketing supports opt-in coupons and vouchers; a "vanity code coupons" article is linked from the discounts article (not read). Omnichannel discounts (POS-created automatic discounts also applying online) are Online Plus and Premium. [S5] [S31] [S30]
- Enable or check: discount eligibility at item level; passcode requirement on discounts; whether web discounts need Online Plus.
- Dough Boss use: bulk-order tier discounts by quantity, customer-group pricing for contracted corporate accounts [INFERRED fit].

### 3.10 Square Marketing (email, SMS, consent)

- What it does. One-time and automated email campaigns (welcome, birthday, lapsed, abandoned cart), smart-group targeting, vouchers, open and click tracking, attributed sales, Google review request emails. [S33] [S30]
- AU: AVAILABLE-AU for email. Native SMS campaigns: NOT-FOUND in the AU product page, the AU campaign article (which says "choose Email"), or the AU customer-engagement help list. Loyalty sends transactional texts only. A WebSearch summary mentioned a "Facebook" campaign type, but the AU article does not list it (UNVERIFIED). [S33] [S30] [S34]
- Tier: "0–500 customer contacts $20 per month; 501–1,000 customer contacts $35 per month; 1,001–2,000 customer contacts $50 per month"; "Unlimited email sends"; 30-day free trial. The Restaurants pricing page says "Starting at $20/mo per location", which conflicts with the marketing page (see section 5). Billing is "based on the number of customers that exist in their subscribed customers group". [S33] [S1] [S39]
- Consent handling (OBSERVED): "Email addresses that you have previously collected through Square ... (for example, a customer has provided you with their email to receive an invoice, book an appointment or receive loyalty rewards) cannot be used to send email marketing through Square Marketing unless you have separately collected and recorded the customer's explicit consent or manually added that customer as a 'subscribed customer'." Square tells sellers to see the ACMA website on spam, and CSV imports need an "Email Subscription Status" column (Subscribed, Unsubscribed or Unknown). Consent tools: QR code poster, sign-up link, POS sign-up screen, opt-in coupons, Square Online collection. Customers unsubscribe through receipt preferences. [S30] [S31] [S32]
- Enable or check: subscribed-status of imported lists; whether the POS sign-up screen is on at each store; sender address and business address on emails.
- Dough Boss use: lapsed-customer and corporate-newsletter campaigns; the Google review email is free with the product. Marketing SMS would need a third party (Mailchimp "Email and SMS" lists Australia) and its own consent record [S83d].

### 3.11 Square Invoices, estimates, recurring invoices and deposits (corporate catering)

- What it does. Invoices, estimates, contracts, projects, deposits and payment schedules, recurring series, card on file, auto reminders, batch invoices. [S40]
- AU: AVAILABLE-AU. Accepts cards, Apple Pay, Google Pay, Afterpay, cash and gift cards on an invoice; "Square does not support multi-currency acceptance today". [S40]
- Tier (quotes): Free "$0/mo + processing fees": "Unlimited invoices, estimates and contracts", "Unlimited user accounts", "Unlimited customers", "Project tracking", "Payment link sharing". Plus "$30/mo + processing fees": "Milestone-based payment schedules", "Multi-package estimates", "Estimate to invoice conversion", "Custom invoice templates", "Custom fields for invoices and contracts", "Batch invoices". Invoice processing fee is 2.2%. [S40] [S8]
- Estimates: "You can send a minimum of two and a maximum of nine packages." Estimates are not transactions in reports. Help article audience: "Square Invoices Plus or Premium subscribers". [S42]
- Deposits and schedules: request a deposit as a percentage or amount; up to 12 milestone payments (Plus); "An item won't be reduced in quantity until the invoice is paid in full"; with progress invoices "sales are not recorded within Sales Reports until the entire Square Invoice is fully paid". "Payment schedules are not available with late fees." [S43]
- Recurring: recurring series charge a card on file with the customer's one-time consent; the help article's audience is "Square Invoices Free and Plus subscribers"; declined recurring payments are not retried automatically. The Invoices API does not support creating recurring invoices. [S41] [S88]
- House Accounts: check out an order to a customer account "and collect payment by invoice or card on file"; spending limit; running balance; recurring invoices; "You cannot collect prepayments using house accounts at this time" and "you are not able to use House Accounts to extend loans, impose finance charges or accept repayment in instalments". [S44]
- Contracts: free customisable templates with e-signature, up to 20 recipients per send. [S45]
- Accounting: "Square offers integrations with the most popular accounting software providers, including Xero, MYOB and QuickBooks Online." [S40]
- Service charges are not available on invoices, so a catering delivery fee goes on as a line item. [S13]
- Enable or check: which tier actually unlocks estimates and deposits on Dough Boss's account (Square's own pages conflict); bank-transfer (EFT) payment options for corporate buyers are NOT-FOUND here; an article about capturing external transactions with custom payment methods (article 8611) is listed in the AU payments topic but was not read, so whether it covers EFT is UNVERIFIED.
- Dough Boss use: catering quote as a multi-package estimate, 50% deposit at acceptance, balance on delivery, weekly office orders as a recurring series, large accounts on a House Account, synced to Xero.

### 3.12 Square Team Management and Shifts (timecards, rostering, labour)

- What it does. Rostering, availability, open shifts and swaps, time clock on POS and Team app, timecards, breaks, overtime, tip pooling, commission tracking, labour cost and labour-vs-sales reports. [S15]
- AU: AVAILABLE-AU. [S14] [S15]
- Tier (quotes): "Free: $0/mo, Up to five team members"; "Plus: $5/mo, Starting at $5 per month, per team member. *Get discounts for larger teams." On Restaurants pricing: "Free up to five team members, then $5/mo. per additional employee" on Free; "Shifts Plus included" on Plus and Premium. Free gives "basic scheduling and time tracking"; Plus adds time off management, shift swapping, overtime tracking and tip pooling. [S14] [S1] [S16]
- Rostering: availability per team member (up to four blocks in the Team app), shifts, open shifts, publish and email, print; a maximum of three shifts per team member per day; multiple locations viewable together. [S16]
- Timecards: clock in with a personal passcode at the POS or the Team app, edit and approve requests ("Edit requests expire after 30 days"), shift notes (Plus). Auto clock-out and blocking early or unscheduled clock-ins "require Shifts Plus or Premium Plans". Team app clock-in can be enforced with geofencing per the features page. [S17] [S20] [S22] [S15]
- Breaks: meal and rest breaks "set up based on your business location" at account creation; paid or unpaid; mandatory once or multiple times per shift; missed-break alert on the timecard; "Block ending breaks early" (Plus); Square says "Visit the Fair Work website meal and rest period rules ... to confirm your settings are accurate". [S19]
- Overtime: daily overtime, weekly overtime and daily double time, each after a set number of hours; workweek start day and workday hours; multi-wage staff use a blended rate with a 0.5x premium. Report wording says "Defined by your state's rules" (generic, not Award-specific). Award penalty rates, junior rates, allowances and leave loading: NOT-FOUND. [S18] [S21]
- Labour reports and exports: labour cost, labour cost by location, and shifts export as CSV (computer only); labour-vs-sales and team sales reports; paid and unpaid breaks, overtime alerts. "Export labour cost by location" exports all locations. [S20] [S21] [S75]
- Permissions: see 3.20.
- Enable or check: staff headcount (Free covers five), personal passcodes for every team member, workday hours and overtime rules reflecting the relevant Award (take advice), break rules, location assignment, and whether wage data is entered in Square at all.
- Dough Boss use: store-level timecards that export to the payroll system. A separate plugin staff clock exists in the WordPress layer, so Square timecards and the plugin clock would need reconciling; Square says edits and approvals happen inside Square. [S20]

### 3.13 Square Payroll (Australia)

- Statement: Square Payroll is NOT-AVAILABLE-AU on the evidence found. Evidence: `https://squareup.com/au/en/payroll` returns HTTP 404; `/au/en/payroll/pricing` and `/au/en/staff/payroll` also return 404; the AU "Staff" navigation lists only Shifts, Advanced access and Team communication; the AU help centre has no payroll article. The AU path `/au/en/payroll/demo` does load, but its content is a US-style pay-run demo ("Pay employees", "Reimbursements", "Travel") with no mention of AUD, STP, superannuation or Fair Work. Square staff on the Square Community (post dated 05-06-2025) wrote: "While Square doesn't offer a built-in payroll service, you can use Square Shifts to track employee hours, export your payroll data, and even sync it with one of our approved partners, such as Xero, MYOB, or QuickBooks." [S27] [S28]
- Mixed messaging: the AU Shifts page promises "payroll prep and compensation management" and the AU Invoices page says "Schedule shifts, track your team's hours and run payroll" — read these as payroll preparation, not payroll processing. Square's Single Touch Payroll article tells employers to use STP-ready software "such as Xero or MYOB" and does not claim Square files STP. [S15] [S40] [S29]
- Export and integrations: "Payroll syncs and exports ... Efficiently export timecards to a third-party payroll provider"; Deputy (listing names Australia) "run payroll with one click with leading payroll providers". Employment Hero and other payroll listings: could not locate an AU marketplace page (UNKNOWN). [S15] [S83e]
- Enable or check: Elie's actual payroll provider and whether it accepts Square's CSV or a Deputy bridge. Confirm with Square Support (1800 760 137) that no AU payroll product is offered. [S1]

### 3.14 Orders API behaviour that affects the website (capability view only)

- API orders reach stores only when paid and fulfilled as PICKUP. DELIVERY needs a partner agreement and otherwise is "not shown in the Square Point of Sale". Developers can add only one fulfilment to an order and all items must be fulfilled at one location. [S85]
- Scheduled pickup orders become active at pickup time minus prep time; without prep time they activate immediately. [S85]
- A July 2026 developer-forum thread (community post, not Square documentation) reports PICKUP orders created through the API appear in Order Manager and on the KDS and print, while DELIVERY orders did not appear. Treat as supporting evidence only. [S86]
- Implication for Dough Boss [INFERRED]: any bespoke storefront must create PICKUP-type fulfilments (including catering pickup) and pay the order through Square for it to appear on the POS, KDS and printers. Delivery by Dough Boss's own drivers has to be modelled as PICKUP or run through Square Online's own delivery. Confirm with a sandbox test before building.

### 3.15 Reporting and analytics

- Available to all sellers in the Dashboard: sales summary, trends, payment methods; item, category and modifier sales; discounts, comps, voids; taxes and fees; transaction status; transaction history; gift cards; custom report; disputes; cash drawers. Subscription reports: Restaurants Plus or Premium (section sales, category rollups), Square Staff (team sales, labour-vs-sales), KDS (kitchen performance), Square Online (online reports). The POS app shows only transactions, sales summary, gift card overview and disputes. [S75]
- By dimension: by item, category, modifier and team member (custom report blocks: key statistics, sales summary, payment methods, item sales, category sales, team member sales, discounts, modifier sales, taxes); by location and device (device names; "Filter By" device); by hour and channel: UNKNOWN as dedicated reports (Square's AI examples include "busiest hours on Mondays" and "sales by channel"). Menu reports and live sales are Plus. Feature-log entries (dated 5 October 2026) describe "revenue centres" grouping sales by bar, patio or delivery channel; regional rollout not stated. [S77] [S75] [S80] [S79] [S82]
- Exports and scheduling: CSV exports from reports; custom reports can be exported but not printed; "The ability to save the date/time filters to your custom reports is not currently available." Scheduled email: a daily sales summary per location, set by account owners. Weekly or custom scheduled reports: NOT-FOUND. [S76] [S77] [S75]
- Square AI: a Dashboard assistant for sales, transactions, staff, customers and web search; requires "full access permissions". Availability appears on an AU help article but regional enablement should be checked. [S80]
- Online reports (Plus or Premium): traffic and sources, purchase funnel. [S81]
- Dough Boss use: sales by store and category; labour-vs-sales per store needs Shifts and wages in Square; kitchen performance only counts KDS tickets.

### 3.16 Integrations and the App Marketplace (Australia)

| Integration | AU evidence | Notes |
|---|---|---|
| Xero | Marketplace listing "Available in: Australia, Canada, United Kingdom, Ireland, United States", updated 2026-08-24 | Daily transactions imported and summarised [S83a] |
| MYOB | Named on the AU Invoices page; marketplace page for MYOB not located (slug returned a generic page) | Verify in marketplace [S40] |
| QuickBooks Online | Named on the AU Invoices page | [S40] |
| Uber Eats | Native integration; AU help article; marketplace "Available in: Australia, Canada, United Kingdom, United States" | Menus, prices, images and availability sync; orders reach Order Manager, KDS and printers; unsupported: driver lookup, direct customer contact, item substitution, rich-text descriptions; "Activating your new integration will disconnect any other integration to Uber Eats" [S61] [S83g] |
| DoorDash | Marketplace "Available in: Australia, Canada, United States"; listed 2026-05-14 | Direct integration; delivery orders can show as pickup or kerbside in the POS; cancel from the DoorDash side [S83f] [S60] |
| Deliverect | Marketplace "Available in: Australia, Canada, Spain, France, United Kingdom, Ireland, United States" | Routes partner orders to POS [S83b] [S60] |
| Doshii | "Available in: Australia" | Order integration [S83c] |
| Menulog | Only in AU product-page text: "partners like Doshii, Menulog, and UberEats (via Deliverect)"; no direct Menulog listing found | Verify route [S84] |
| Deputy | "Available in: Australia, Canada, United Kingdom, United States" | Imports timecards, one-click payroll via providers [S83e] |
| Mailchimp: Email and SMS | "Available in: Australia, Canada, United Kingdom, Japan, United States" | Third-party email and SMS [S83d] |
| MarketMan | "Available in: Australia ..." | Ingredient-level inventory [S83h] |
| Zapier | NOT-FOUND on the AU marketplace; Zapier's own site lists a Square app (third-party source, UNVERIFIED) | Verify |
| Google | Order with Google (free, Online Free+); Google Business Profile; Google review request emails | [S72] [S33] |
| Meta | Meta for Business (Online Plus+), Facebook and Instagram "Order Food" button | [S71] [S5] |
| Apple Maps place card | Feature-log entry: "Available globally to payments-activated sellers with a fixed, physical location" | Dated 5 October 2026 [S82] |
| WooCommerce, Wix, WordPress payments | Marketing page claim: "Our solutions work nicely with WooCommerce, Wix, Magento, BigCommerce, Wordpress, Drupal Commerce" | Not tested [S94] |

- Order partners are chosen from Dashboard > Orders > Order partners or the marketplace. Partner commissions are set by the partner and not published by Square. [S59]
- Enable or check: whether any delivery platform is part of the volume plan; the first-party route (own site) avoids partner commission [INFERRED].

### 3.17 Square-native advertising

- Findings: no standalone "Square Advertising" product was found on the AU site (navigation lists Marketing, Loyalty, Gift cards, Customer directory, Contracts, Photo studio). Advertising-related features: (a) Square Online Plus and above lists "Facebook and Google ads: Create and manage targeted Facebook and Google ads"; (b) Meta for Business gives Facebook Pixel, "Ads Manager", Automatic Advanced Matching, Meta Catalogue, Facebook Shops and Instagram Shopping (Plus or Premium); (c) Square Marketing includes email and, per the AU article, email-only campaign creation. [S5] [S71] [S30]
- Closed-loop attribution: Square Online accepts Google Analytics, Facebook Pixel and custom tracking code, but those apply to the Square-hosted site, not a separate WordPress or Next.js storefront. [S69]
- UNKNOWN: whether Google Ads set-up inside Dashboard is live in AU and what it costs; check Dashboard > Channels.

### 3.18 Receipts, SMS and customer-communication consent

- Digital receipts: customers manage preferences through "Manage preferences" on a receipt; unsubscribing from a business's marketing is separate from receipts. An article on how customers unsubscribe from seller receipts exists (not read). [S32]
- Order-ready texts: collect the phone number and a customer-consent confirmation at the POS, texted when the order is marked complete on KDS or POS; needs a KDS expeditor device code. [S73]
- Order status text alerts on Square Online: Plus or Premium. Arrival replies ("HERE") go to the POS. [S5] [S63]
- Loyalty notifications: transactional texts on earning points. [S34]
- Marketing consent rules: see 3.10. SMS marketing under the Spam Act and the Do Not Call Register are Australian-law matters outside Square's pages; check with the Australian Communications and Media Authority (ACMA) rather than assume Square handles them. [S31]

### 3.19 Order throttling and catering funnelling

- Square Online "Large order settings": "Set a quantity limit for pickup and delivery orders" with "an optional custom message explaining how to place orders that are over the quantity limit". Per-item "Item quantity limits" are Plus. [S63] [S5]
- Busy Mode, pause online orders, delay pickup time by 15-minute steps, and prep-time extension (15 minutes, 30 minutes, one hour). [S58]
- Dough Boss use [INFERRED]: set the order quantity cap so large orders are redirected to the corporate catering enquiry path, protecting store throughput.

### 3.20 Security and permissions

- Roles per location: "Team members can only access and manage data for their assigned locations." Permission levels Standard, Enhanced, Full; Full Access team members become authorised representatives. Account owners hold the highest access. Counts: Restaurants Free and Shifts Free allow one custom permission set; Shifts Plus and Retail Plus allow two; Advanced Access, Restaurants Plus and Appointments Premium allow unlimited. [S24] [S25] [S1]
- Passcodes: personal passcodes track sales, time and actions by team member; a shared team passcode cannot. Team member badges pair passcodes with badges (Reader, Register or Stand). [S24] [S26]
- Advanced Access: "$35/mo per location", unlimited custom permission sets, team member activity log (refunds, discounts, voids), team sales report, badges; "You will be charged for all active locations with team members". Included in Restaurants Plus. [S26] [S1]
- Other: Risk Manager, device-code login, remote device management, offline payments. [S1] [S5]
- Enable or check: owner and authorised representatives, one permission set per role (store manager, baker, counter, catering coordinator), manager access restricted to their own store, wage visibility off for non-owners.

---

## 4. Indicative cost arithmetic for three stores [INFERRED from published prices]

Illustrative only. Headcount, devices and chosen tiers are UNKNOWN.

| Item | Published price | Three stores |
|---|---|---|
| Restaurants Plus | $129/month per location [S1] | $387/month |
| KDS on Free plan instead | $25/month per device [S3] | 1 to 5 devices per store is cheaper than Plus on KDS alone; 6 devices costs $150 against $129 [INFERRED] |
| Shifts Plus on its own | $5 per team member per month [S14] | Included in Restaurants Plus |
| Advanced Access on its own | $35 per location per month [S26] | $105/month; included in Restaurants Plus |
| Square Online Plus | $36/month billed annually [S5] | One site covers multiple locations [S4] |
| Square Loyalty | From $49/month per location [S34] | From $147/month |
| Square Marketing | $20 to $50/month by contacts [S33] | $20 to $50, or $60 if per location [S1] |
| Invoices Plus | $30/month [S40] | $30/month (one account) |
| MarketMan inventory | +$149/month per location [S1] | $447/month |
| Card fees | 1.6% or 1.9% in person, 2.2% online [S8] | Depends on volume and sign-up date |

---

## 5. Conflicts and gaps inside Square's own published material

1. Estimates tier. The AU Invoices page lists "Unlimited invoices, estimates and contracts" under Free and "Multi-package estimates" and "Estimate to invoice conversion" under Plus, while the estimate help article says "Square Invoices Plus or Premium subscribers". [S40] [S42]
2. Recurring invoices. The help article says "Square Invoices Free and Plus subscribers", and also "Sellers with services or standard mode enabled in the Square Point of Sale app". [S41]
3. Square Marketing price basis. Marketing page: by contacts, monthly. Restaurants page: "Starting at $20/mo. per location". [S33] [S1]
4. Item availability and counts. The Restaurants grid shows "Item availability and item counts" included on Free; the FAQ lists "auto 86ing, item counts" under Plus. [S1]
5. KDS on Free. The KDS page says "Square for Restaurants Free included" at "$25 Per month per device"; the Restaurants grid wording is ambiguous. [S3] [S1]
6. In-person rate. 1.6% versus 1.9% depends on sign-up date and hardware. [S8]
7. Custom domain value. Online ordering page: "free custom domain – that's a $31.95 value"; Online plans page: "($19.95 value)". [S4] [S5]
8. Weekend surcharge footnote on the payments page is superseded by the 1 October 2026 articles. [S8] [S11]
9. Payroll wording on the Shifts and Invoices pages ("run payroll") versus the Square staff reply (no built-in payroll). [S15] [S40] [S28]
10. Feature-log entries are dated 5 October 2026, after the retrieval date. They may be scheduled rollouts, and several refer to US-style programmes (Neighborhoods, Ordering Profiles). AU availability of each is not stated. [S82]
11. Admin-side detail that the AU pages publish only in generic form: Award interpretation, superannuation, STP filing. NOT-FOUND for all.

---

## 6. Turn these on first (prioritised for three stores growing corporate catering and volume)

Priority 1: compliance and foundations (this week)

1. Read the live subscription list (Settings > Account & Settings > Business information > Pricing & subscriptions) and record the tier of every product per location. Everything below depends on it.
2. Surcharge audit: confirm no card surcharge remains (service charges, sales tax, automatic gratuity); update signage, web copy, quote templates and receipts. Plan any price rebuild. [S11] [S12]
3. Locations, hours and tax: three correct locations with business hours and GST set; location-specific price overrides for any store pricing differences. [S50] [S79]
4. Catalogue hygiene: one clean menu per channel, kitchen routing categories, kitchen names, prep times, modifiers (including nested pizza options), images, dietary and allergen fields, custom attributes for catering flags. [S52] [S56] [S51] [S53]
5. Permissions: personal passcodes, one permission set per role, location restrictions, owner and authorised representatives. [S24] [S25]

Priority 2: kitchen and ordering (weeks 1 to 4)

6. KDS (Android) at each store: prep and expo devices, routing categories, "View online, kiosk and delayed fulfilment orders" on, timers, staggered prep, All Day counts. Decide Restaurants Plus ($129 per location) versus Free plus $25 per KDS device. [S54] [S56] [S3] [S1]
7. Square Online Plus on a subdomain of the Dough Boss domain (or linking from WordPress): pickup windows and cut-offs per store, pre-orders for catering lead time, quantity limit with a catering redirect message, Order with Google and Business Profile. [S5] [S63] [S66] [S72]
8. Test one end-to-end online order per store: placed online, paid, ticket on KDS, printer, Order Manager, ready text. [S57]

Priority 3: corporate and catering (weeks 2 to 6)

9. Invoices Plus ($30/month): multi-package catering estimates, deposits and milestone schedules, recurring series for office orders, Xero sync. Confirm the tier question in section 5 first. [S40] [S42] [S43] [S41]
10. House Accounts for approved corporate buyers (spending limit, invoice or card on file) and Contracts for standing agreements. [S44] [S45]
11. Customer Directory fields for company, ABN and delivery site; groups for corporate and lapsed. [S38] [S39]

Priority 4: growth tools (once data is clean)

12. Email consent capture at every counter and checkout (QR poster, POS sign-up screen); then Square Marketing for lapsed and corporate lists. [S31] [S33]
13. Loyalty across the three stores once consent text is confirmed. [S34] [S87]
14. Gift cards and eGift cards for corporate gifting. [S37]
15. Meta for Business and Google tools on Square Online only if the Square-hosted ordering page is the conversion point; otherwise run tracking on the main site. [S71] [S69]

Priority 5: labour and finance

16. Shifts timecards and rosters with passcodes at each store; overtime and break rules reviewed against the relevant Award; CSV or Deputy export to the payroll provider. Do not plan on Square Payroll. [S17] [S18] [S19] [S20] [S28]
17. Xero connection for daily sales and invoice sync. [S83a]
18. Delivery platforms (Uber Eats, DoorDash) only if wanted; pricing and commission need the partner's own terms. [S61] [S83f]

---

## 7. Unknowns for Elie to check in the Square Dashboard (or with Square)

1. Which Square subscriptions are active per location (Restaurants Free or Plus, Square Online tier, Shifts tier, Marketing, Loyalty, Invoices, Advanced Access, MarketMan). Settings > Account & Settings > Business information > Pricing & subscriptions.
2. Account sign-up date and devices, to confirm whether the in-person rate is 1.6% or 1.9%.
3. Whether any card surcharge remains in service charges, sales taxes or automatic gratuity.
4. Whether Restaurants Plus includes any Square Online Plus features or only the "Free tier" shown in the grid.
5. Which tier unlocks estimates, deposits and recurring invoices on this account.
6. Whether Crazy Domains supports Square's automatic domain connection, or a manual DNS record is needed for a subdomain.
7. Whether Square Online displays allergen, dietary and calorie fields to customers on the ordering page.
8. Whether half-and-half or build-your-own pizza can be modelled with nested modifiers on this account.
9. Whether item prep times, staggered prep, "All Day counts", draft tickets and revenue centres are live in the account (Dashboard > What's New and Settings > Device management).
10. Whether an AU Square Payroll or any payroll partner is offered in the Dashboard. Ask Square Support on 1800 760 137.
11. Whether Menulog connects through Deliverect or Doshii or directly, and any partner commission.
12. Whether SMS marketing or Facebook campaign creation exists in the Dashboard under Customers > Marketing > Campaigns for this account.
13. Whether MYOB and Employment Hero listings exist in the AU App Marketplace.
14. Whether an EFT or bank-transfer payment can be recorded against invoices (custom payment methods).
15. Whether Square Loyalty enrolment shows an SMS consent line meeting Australian rules.
16. Whether Square AI is enabled for this account.
17. Whether the 5 October 2026 feature-log items roll out in Australia.
18. Overtime, break and penalty-rate settings against the relevant Fair Work Modern Award; Square only provides generic settings.
19. Entity and brand ownership of the Square merchant account (confirm which legal entity holds the merchant account before building on it). Check that the person who owns the Square login is the right legal owner before building on it.

---

## 8. Source register (all retrieved 2026-10-02)

Marketing and product pages (AU):

- S1 https://squareup.com/au/en/point-of-sale/restaurants/pricing
- S2 https://squareup.com/au/en/point-of-sale/restaurants/features
- S3 https://squareup.com/au/en/point-of-sale/restaurants/kitchen-display-system
- S4 https://squareup.com/au/en/online-ordering
- S5 https://squareup.com/au/en/online-store/plans
- S6 https://squareup.com/au/en/online-store
- S7 https://squareup.com/au/en/pricing
- S8 https://squareup.com/au/en/payments
- S9 https://squareup.com/au/en/payments/our-fees
- S14 https://squareup.com/au/en/staff/shifts
- S15 https://squareup.com/au/en/staff/shifts/features
- S26 https://squareup.com/au/en/staff/advanced-access
- S27 https://squareup.com/au/en/payroll (404); https://squareup.com/au/en/payroll/demo (loads, US-style demo); https://squareup.com/au/en/payroll/pricing (404); https://squareup.com/au/en/staff/payroll (404)
- S29 https://squareup.com/au/en/the-bottom-line/operating-your-business/single-touch-payroll
- S33 https://squareup.com/au/en/software/marketing
- S34 https://squareup.com/au/en/software/loyalty
- S37 https://squareup.com/au/en/gift-cards
- S38 https://squareup.com/au/en/point-of-sale/features/customer-directory
- S40 https://squareup.com/au/en/invoices
- S45 https://squareup.com/au/en/contracts
- S82 https://squareup.com/au/en/feature-log
- S83a https://squareup.com/au/en/app-marketplace/app/xero
- S83b https://squareup.com/au/en/app-marketplace/app/deliverect
- S83c https://squareup.com/au/en/app-marketplace/app/doshii
- S83d https://squareup.com/au/en/app-marketplace/app/mailchimp
- S83e https://squareup.com/au/en/app-marketplace/app/deputy
- S83f https://squareup.com/au/en/app-marketplace/app/doordash
- S83g https://squareup.com/au/en/app-marketplace/app/ubereats
- S83h https://squareup.com/au/en/app-marketplace/app/marketman
- S84 https://squareup.com/au/en/point-of-sale/restaurants/food-delivery-software
- S92 https://squareup.com/au/en/point-of-sale/features/dashboard
- S93 https://squareup.com/au/en/point-of-sale/features/inventory-management
- S94 https://squareup.com/au/en/ecommerce

AU help-centre articles (prefix https://squareup.com/help/au/en/article/):

- S10 3807-deposit-options-with-square
- S11 8680-preparing-your-business-for-end-of-card-surcharging
- S12 8147-manage-your-manual-surcharge-settings
- S13 7625-get-started-with-service-charges
- S16 7155-scheduling-with-team-management
- S17 8389-set-up-time-tracking
- S18 8390-set-your-work-period-and-overtime-rules
- S19 8391-set-up-breaks
- S20 8392-edit-employee-timecards
- S21 6140-employee-timecard-reporting
- S22 5643-timecard-management-with-square
- S23 7654-get-started-with-tip-pooling-for-team-management
- S24 5822-employee-permissions
- S25 8356-add-and-manage-team-members
- S30 8412-create-marketing-campaigns
- S31 5976-get-started-with-email-collection-tools
- S32 6549-how-customers-can-unsubscribe-from-square-marketing-emails
- S35 3952-create-a-loyalty-program-with-square
- S36 7794-get-started-with-square-loyalty-promotions
- S39 6245-manage-customer-groups-and-filters
- S41 7209-create-a-recurring-invoice-series-online
- S42 7215-create-an-estimate-online
- S43 6581-request-deposits-with-square-invoices
- S44 8034-create-and-charge-square-house-accounts
- S46 3955-create-and-manage-discounts
- S47 5119-create-and-manage-item-modifiers
- S48 8430-mark-items-and-modifiers-as-sold-out
- S49 8333-create-inventory-alerts
- S50 8242-create-item-price-overrides-for-multiple-locations-in-square-dashboard
- S51 8335-create-and-edit-items
- S52 6424-create-menus-with-square-for-restaurants
- S53 7168-create-and-manage-custom-attributes
- S54 7959-route-orders-with-your-kds
- S55 8168-prioritize-orders-with-square-kds
- S56 7944-get-started-with-square-kds-android
- S57 8103-troubleshoot-missing-orders-with-square-kds
- S58 8325-manage-order-timing-with-square
- S59 8180-integrate-delivery-and-pickup-apps-with-square-for-restaurants
- S60 8445-integrate-partnership-platforms-for-square-online-pickup-and-delivery-orders
- S61 8515-set-up-uber-eats-integration-with-square
- S62 8438-set-up-delivery-options-with-square-online
- S63 6866-in-store-and-curbside-pickup-with-square-online-store
- S64 6861-create-an-order-online-page-with-square-online-store
- S65 6916-connect-your-domain-with-square-online-store
- S66 6966-use-a-custom-subdomain-with-your-online-store (and 6943 free subdomain)
- S67 6899-add-external-content-and-widgets-with-embedded-code-in-square-online-store
- S68 7088-manage-multiple-websites-with-square-online-store
- S69 6957-add-custom-tracking-code-to-your-website
- S70 6874-seo-settings-for-square-online-store
- S71 7887-connect-square-online-with-meta-for-business
- S72 7659-use-order-with-google-with-square-online
- S73 8069-text-customers-order-is-ready-with-square-for-restaurants
- S74 8630-set-up-and-manage-group-ordering
- S75 5072-summaries-and-reports-from-the-online-dashboard
- S76 8362-print-export-or-email-your-reports
- S77 6104-creating-custom-reports-in-the-online-dashboard
- S78 6433-reporting-with-square-for-restaurants
- S79 8142-get-real-time-sales-data-on-square-restaurants-pos
- S80 8516-use-ask-ai-to-get-insights-about-your-business
- S81 6948-insights
- S95 8267-upload-images-to-your-item-library
- S96 7669-schedule-item-updates-with-square-online

Square Community and developer sources:

- S28 https://community.squareup.com/t5/Appointments-Bookings/can-square-be-used-for-payroll-if-so-how/td-p/792374 (Square staff reply, post dated 05-06-2025)
- S85 https://developer.squareup.com/docs/orders-api/fulfillments
- S86 https://developer.squareup.com/forums/t/orders-api-delivery-fulfilments-created-successfully-but-not-appearing-in-orders-manager-or-square-kds/26705 (community post dated 2 July 2026; not official documentation)
- S87 https://developer.squareup.com/docs/loyalty-api/overview
- S88 https://developer.squareup.com/docs/invoices-api/overview
- S89 https://developer.squareup.com/docs/labor-api/what-it-does
- S90 https://developer.squareup.com/docs/catalog-api/manage-menus
- S91 https://developer.squareup.com/reference/square/objects/CatalogItemFoodAndBeverageDetailsDietaryPreference

Articles listed in AU help topic pages but not read (so any detail beyond their titles is UNVERIFIED): 5337-use-open-tickets-with-square, 8322-set-up-order-manager-on-your-point-of-sale, 7778-add-food-ordering-buttons-to-facebook-and-instagram-with-square-online, 8611-capture-external-transactions-using-custom-payment-methods, 7960-get-started-with-tap-to-pay-on-android, 7786-get-started-with-tap-to-pay-on-iphone, 7878-opentable-and-square-for-restaurants, 8270-sevenrooms-and-square-for-restaurants.
