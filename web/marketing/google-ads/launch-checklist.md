# Google Ads launch checklist, DRAFT

Prepared 2026-10-02 for Elie. Companion to `docs/marketing/03a-google-ads.md`, `campaigns.json` and `conversion-plan.md`. Nothing here has been done: no Google Ads account, campaign, audience or conversion has been created or edited. Every box below is unticked.

Rule for the whole checklist: a human works through it, in order. A "no" at any gate stops the launch. Nothing is enabled until Part 7 is signed off.

## Part 1. Facts and permissions (before building anything)

- [ ] Elie has answered the open questions in `03a-google-ads.md` section 17, or each unanswered one is accepted as a known gap with the ad group it blocks kept on hold.
- [ ] The entity and brand-ownership question is settled in writing: the advertiser is the legal entity entitled to trade as Dough Boss and to use the name, the site and the three shop listings. Until it is, nothing is built, and above all the Brand campaign (which bids on "dough boss") does not run. `[CONFIRM: entity, ABN and the written basis for using the name]`
- [ ] `[CONFIRM: who owns and administers the Google Ads account, GA4 property, Tag Manager container and the three Business Profiles]`. Access is in Elie's or the business's name, not a freelancer's.
- [ ] `[CONFIRM: billing: payment profile, who pays, monthly ceiling and learning-budget ceiling]`. No budget is entered until this is written down.
- [ ] Claims ledger: the facts listed in `03a-google-ads.md` section 11 are in `src/content/ledger.ts` as confirmed with a source, or the affected headlines are removed.
- [ ] Store addresses, hours and phone numbers re-confirmed against reality (the catalogue notes the source lacks public-holiday trading).
- [ ] Delivery or serve area decided: `[CONFIRM: delivery area]` (sets radius or suburb list) and `[CONFIRM: drop-off or delivery offered for catering]` (releases or cuts the Drop-Off group).
- [ ] Namesake check: search "dough boss" and note any other business using the name.

## Part 2. Tracking verified end to end (the hard gate)

Follow `conversion-plan.md` section 6. All must pass on the real landing pages that will receive ad traffic.

- [ ] Google tag and Conversion Linker installed and gated by the consent signal. Starting state per `docs/wp/01-storefront-map.md`: no Google tag existed in the plugin or theme.
- [ ] Auto-tagging is ON in Google Ads.
- [ ] A test enquiry on each landing page type (corporate, office breakfast, events, a store page) is seen in: Tag Manager preview, GA4 DebugView, the plugin lead list (with `utm*` and the click id in `attribution`, `consentAt` and `consentText` set, landing path without a query string) and the team notification email.
- [ ] Google Ads conversion action "Catering enquiry" shows as recording.
- [ ] `click_to_call` fires with the correct store for each store number.
- [ ] Call asset call reporting tested, if a catering number exists. If not, no call asset is attached to the Corporate and Event campaigns.
- [ ] Test leads are marked as tests and excluded from lead-quality counts and from any won-deal upload.
- [ ] No personal data appears in any analytics event parameter, URL or log.
- [ ] Privacy policy states that contact details may be shared in hashed form with advertising platforms, and consent wording is approved (LAWYER). Enhanced conversions stay OFF until this passes.

## Part 3. Landing pages live and fast

For every final URL in `campaigns.json` (the route contract: `/`, `/catering`, `/catering/corporate`, `/catering/office-breakfast`, `/catering/events`, `/locations/revesby`, `/locations/bankstown`, `/locations/roselands`):

- [ ] The page is live, returns 200, is not redirected through a chain, and is indexable.
- [ ] Message match: the page headline and first screen restate the ad group's theme and the place. A visitor from "office breakfast catering" lands on office breakfast, not a general page.
- [ ] The quote form or the enquiry call to action is visible without a long scroll on a phone. Form asks for date, headcount, event type, suburb and dietary needs (the plugin fields), and consent text is shown.
- [ ] Phone number for the store is a tap-to-call link and the store hours are shown.
- [ ] Page speed is acceptable on a mid-range phone on mobile data: measured with a real tool and recorded. `[CONFIRM: the speed bar the team accepts; do not invent one]`.
- [ ] No claim on the page contradicts the ads, and no unconfirmed claim is on the page either (the same claims rule applies).
- [ ] Prices and price basis on the page, if any, come from the ledger. Google's misrepresentation policy requires the payment model or expense to be disclosed clearly (`compliance-au.md` section 10).
- [ ] Allergen information is available or the page says how to ask for it (FSANZ, `compliance-au.md` section 7).
- [ ] Hours text on store pages matches the typed data used in the ads (`Open 7 Days At Revesby`, `Bankstown Open Mon To Fri`, `Roselands Open Daily`).

## Part 4. Policy and compliance check

- [ ] Run `npx vitest run tests/unit/marketing-google-ads.test.ts`: all tests pass (length limits, banned terms, route contract, paused status, negatives safety).
- [ ] Read every ad in `rsa.csv` once as a customer, and check each against the ledger. Anything with no source is removed, not softened.
- [ ] Re-read Google's ad policies for misrepresentation and for the sensitive categories before launch (links in `compliance-au.md` section 10). Policies change.
- [ ] No religion or ethnicity addressing in copy. No health or diet claims. No halal, gluten-free, vegan or similar claim anywhere (parked).
- [ ] Business Profile rules respected (`compliance-au.md` section 9): real-world name, one profile per staffed store, no review gating or incentives.
- [ ] LAWYER and REGULATOR items from `compliance-au.md` that touch ads are cleared or consciously accepted: privacy wording, any outbound calling (not planned), allergen and gluten-free wording.

## Part 5. Build the account PAUSED

Done by a human from the files, in this order. Use Google Ads Editor or the web UI. Nothing is enabled during the build.

- [ ] Create the account settings: AUD, Australia/Sydney, auto-tagging on, auto-apply recommendations OFF, no automatic asset creation, `eu_political_advertising` declaration answered.
- [ ] Create the four campaigns from `campaigns.json`, each PAUSED: Search network only, Search Partners OFF, Display OFF, English, presence-only locations. Set the radius or suburb list only after `[CONFIRM: delivery area]`.
- [ ] Set the tracking template and `utm_campaign` per campaign (and the three store overrides in the Local campaign). Test the template on one ad.
- [ ] Choose manual CPC with a max CPC from the break-even maths (`03a-google-ads.md` section 8). `[CONFIRM: max CPC]`. Set daily budgets from the formula, only after the inputs are confirmed.
- [ ] Create ad groups (PAUSED), keywords from `keywords.csv` (phrase and exact only, no broad), and one RSA per ad group from `rsa.csv` including pins exactly as listed. Set path1 and path2 from `campaigns.json`.
- [ ] Upload negatives from `negatives.csv` at the account and campaign levels. Confirm the account-level negative option exists in the UI, or use a shared list applied to every campaign.
- [ ] Create assets from `extensions.json`: sitelinks, callouts, structured snippets. Link the Business Profile for location assets only after `[CONFIRM: verified profiles]`. Create call assets only for answered numbers.
- [ ] Do NOT create a lead form asset. Do NOT create a Performance Max or Dynamic Search campaign. Do NOT enable broad match.
- [ ] Keep ad groups marked `hold` in `campaigns.json` PAUSED, with a label `hold` and the reason in the notes: Drop-Off And Boxed Lunch, Mezze And Grazing.
- [ ] Label wave 1 groups `wave-1` and wave 2 groups `wave-2`.
- [ ] Re-count: the account matches the files (4 campaigns, 20 ad groups, 15 headlines and 4 descriptions each, 130 keyword rows, 141 negative rows). Any difference is explained in writing.

## Part 6. Search-terms report plan (set up before launch)

- [ ] A saved view of the search terms report with: query, match type, campaign, ad group, clicks, cost, conversions, and the keyword that matched.
- [ ] Review days fixed: daily for the first 7 days of live spend, then weekly (see Part 8).
- [ ] A shared "negatives to add" list with a rule: exact or phrase negatives only, never broad single words without a second pair of eyes.
- [ ] A record of every `TEMP:` negative and the question that releases it.
- [ ] A way to tie each lead back to a keyword: lead `utmTerm` is read in the plugin lead list.

## Part 7. Human approval step (the only way anything goes live)

- [ ] Elie reviews the staged account (not the files) and signs off in writing: campaigns, budgets, locations, ads, assets, negatives.
- [ ] Elie confirms a named person owns the weekly routine and the lead follow-up, and the enquiry-handling hours match the call asset schedule.
- [ ] Enable wave 1 only. A single change at a time: enable, then check the status of every ad group (approved, eligible).
- [ ] Record the launch date, time, budget and who enabled it. Note the first 7 days' review dates in the calendar.
- [ ] Wave 2 and the held groups stay paused until their release conditions in `03a-google-ads.md` sections 3 and 4 are met.

## Part 8. First 30 days: review cadence

No launch-day numbers are assumed. The targets are the formulas in `03a-google-ads.md` section 8 once their inputs are filled.

| When | Review | What to look at and do |
|---|---|---|
| Launch day, same afternoon | Smoke check | All ads approved and serving; no disapprovals; conversions still record after a test lead; spend is tracking to the daily budget; a phone call placed in store hours is answered |
| Days 1 to 3, daily | Search terms and spend | Junk queries become negatives; any query that is clearly a good buyer is added as exact; check location report for out-of-area impressions; check every lead came with a click id |
| Day 7 | First weekly review | Full weekly routine (`03a-google-ads.md` section 13). Decide which wave 2 groups to release (only those with matching wave 1 evidence). Check lead quality with the team |
| Day 14 | Second weekly review | Cut variants with spend and no impressions; replace the weakest headline in the busiest ad groups (new `utm_content`); confirm the manual won-deal upload ran if there are any wins |
| Day 21 | Third weekly review | Review hour and day patterns (no bid adjustments yet); landing page form drop-off (`quote_step`); check brand campaign impression share and spend |
| Day 28 to 30 | Month review | Cost per qualified lead and cost per won order by campaign and ad group; compare to the break-even maths; decide bid phase (stay manual, or move to Maximise conversions per `03a-google-ads.md` section 7 and Google's 30-conversion evaluation guidance); decide to scale, hold or cut; refresh the "what would change my mind" list; plan Christmas and Ramadan seasonal changes |

Stop conditions during the first 30 days, any of which pauses spend immediately and calls for a review:

- Conversion tracking stops recording while clicks continue.
- A disapproval or policy warning relating to a claim.
- Leads arriving that nobody is replying to.
- Spend running faster than the budget formula allows without leads.
- A complaint about an ad's accuracy.

## Part 9. Sign-off record

| Gate | Done by | Date | Evidence (link or note) |
|---|---|---|---|
| Part 1 facts | | | |
| Part 2 tracking | | | |
| Part 3 landing pages | | | |
| Part 4 policy | | | |
| Part 5 build paused | | | |
| Part 6 reporting | | | |
| Part 7 approval | | | |
