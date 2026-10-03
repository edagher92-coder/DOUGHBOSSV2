# Dough Boss: Google Ads (Search) account build, DRAFT

Prepared 2026-10-02 for Elie. Status: DRAFT and review-ready. Nothing described here exists in any Google Ads account. Every campaign and ad group is specified to launch PAUSED, and a human stages the account after review (see `marketing/google-ads/launch-checklist.md`).

This is an internal planning document, so it carries `[CONFIRM: ...]` items. Anything customer-facing (the ad text in `rsa.csv`, the assets in `extensions.json`) carries none, and a test enforces that (`tests/unit/marketing-google-ads.test.ts`).

## 0. Files and how to read them

| File | What it is |
|---|---|
| `marketing/google-ads/campaigns.json` | Machine-readable build: 4 campaigns, 20 ad groups, settings, final URLs, tracking template, all `paused` |
| `marketing/google-ads/keywords.csv` | 130 keyword rows (phrase and exact only), each mapped to one landing page |
| `marketing/google-ads/negatives.csv` | 141 negative keyword rows at account and campaign level |
| `marketing/google-ads/rsa.csv` | One responsive search ad per ad group: 15 headlines, 4 descriptions, pins, character counts |
| `marketing/google-ads/extensions.json` | Sitelinks, callouts, structured snippets, call assets, location assets, lead form recommendation |
| `marketing/google-ads/conversion-plan.md` | Conversions, enhanced conversions, value rules, the Square closed loop and its manual interim |
| `marketing/google-ads/launch-checklist.md` | The pre-launch gate and the first 30 days |

Labels used: OBSERVED means I read it at the cited URL on 2026-10-02. INFERRED means my reasoning. `[CONFIRM: ...]` means only Elie or the team can supply it.

Two limits on the evidence you should know about:

- No search volume, CPC, competition or auction data was available. Google Keyword Planner needs an account and was not touched. `marketing/keywords.csv` has an empty `monthly_volume` column for the same reason. Every budget and bid in this plan is therefore a formula with named inputs, not a number.
- The claims ledger (`src/content/ledger.ts`) currently holds the type and the rules but no confirmed claims. So ad copy is restricted to what the typed store data carries (store names, addresses, hours, phones, menu and category names) plus plain calls to action. The copy therefore avoids every claim that needs a ledger entry. Section 11 lists the few facts it still leans on, so Elie can confirm them once.

## 1. The plan in one page

1. Start with Search only. Run four campaigns now (Corporate and Office, Events and Functions, Local Store Areas, Brand). No campaign, ad group or keyword is built around an unannounced product; the public teaser is generic ("Something exciting is coming") and `docs/site/teaser-direction.md` rule 6 applies: a generic awareness line may be added only once Elie approves it, and a product-specific campaign stays parked until the product is confirmed.
2. Phrase and exact match only. No broad match, no Performance Max, no AI Max or final URL expansion, no dynamic search ads, no auto-applied recommendations, no Search Partners, no Display.
3. One theme per ad group, one landing page per ad group, one responsive search ad per ad group written for that theme.
4. Launch the highest-intent ad groups first (wave 1, 11 groups) and add the rest once the search-terms report shows what people really type (wave 2, 9 groups). Two groups are on hold for facts only Elie can confirm.
5. Bid manually for the first weeks with a hard CPC cap, then Maximise conversions once tracking is proven, then Target CPA once there is enough data. Section 7 has the path and Google's own guidance.
6. Presence-only location targeting around the three stores. The radius is `[CONFIRM: radius from delivery area]` and is treated as a hypothesis until Elie confirms where Dough Boss will actually serve.
7. Measure leads, not clicks. The primary conversion is `generate_lead` with `form = catering_enquiry`. The closed loop to won Square orders starts as a weekly CSV and becomes automatic later (`conversion-plan.md`).
8. Every ad is checked against the claims rule and against `docs/marketing/research/compliance-au.md` before it is staged.

Two things I would not compromise on: no spend before a test lead is seen end to end in the plugin, GA4 and Google Ads, and no call asset pointing at a phone nobody will answer.

## 2. Why Search only first, and why PMax and broad match wait

Search is the right first channel because the buyers here are searching for something specific ("office catering Revesby", "function catering Bankstown"). The money term is a person with a date and a headcount, and Search is the only channel where we choose the exact words and see the exact queries.

**Performance Max waits.** OBSERVED (https://support.google.com/google-ads/answer/10724817, retrieved 2026-10-02): PMax needs conversion goals and a pool of text, image and video assets, and with final URL expansion on, "Google may replace your Final URL with a more relevant landing page ... and generate a dynamic headline and description". The same page says the advertiser remains responsible for the accuracy of "all dynamically generated assets". INFERRED for Dough Boss:

- We have no conversion history, and tracking is not yet proven. PMax would optimise toward whatever we mis-measure.
- The claims rule is strict (no superlatives, no halal, no prices, no delivery promises). Auto-generated headlines and descriptions are exactly where an unconfirmed claim would slip in, and we could not review them before they run.
- PMax reporting on what people searched is thinner than a Search campaign, which is the learning we need most in the first 60 days.
- OBSERVED on the same page: a Search campaign with an exact match keyword is prioritised over PMax when the query hits that keyword. So adding PMax later does not undo the Search build.

Revisit PMax when: there are stable conversions from Search, tracking is verified, the asset library and claims ledger are approved, and a brand exclusion and URL exclusions can be set (see section 15).

**Broad match waits.** OBSERVED (https://support.google.com/google-ads/answer/7478529, retrieved 2026-10-02): broad match "may show on searches that are related to your keyword, which can include searches that don't contain the direct meaning", and Google says "It's critical to use Smart Bidding with broad match." We will not use Smart Bidding until there are conversions, so broad match has nothing to steer it. Phrase and exact match let us pay only for the meanings we chose. INFERRED: with a tiny, local, high-value account, wasted clicks hurt far more than a missed long-tail query.

**Dynamic search ads and AI Max wait** for the same reason: they pick landing pages and write headlines for us, which collides with the claims rule.

## 3. Structure: campaigns, ad groups, landing pages

Landing pages come only from the route contract: `/`, `/#order`, `/#locations`, `/catering`, `/catering/corporate`, `/catering/office-breakfast`, `/catering/events`, `/locations/revesby`, `/locations/bankstown`, `/locations/roselands`.

Note on `marketing/keywords.csv`: its `suggested_page` column names three pages that are not in the contract (`/catering/platters`, `/catering/ramadan-iftar`, `/catering/dietary`). I remapped them rather than invent routes. Platter and manoush terms go to `/catering` (the hub) or `/catering/events`. Iftar, Eid and dietary terms are parked (section 4).

Wave 1 launches first. Wave 2 follows after the first search-terms review.

| Campaign | Ad group | Landing page | Wave | Status note |
|---|---|---|---|---|
| Corporate Office Catering | CORP Corporate Catering | /catering/corporate | 1 | |
| | CORP Office Catering | /catering/corporate | 1 | |
| | CORP Office Christmas Party | /catering/corporate | 1 | Seasonal. Lead time and last order date unknown: `[CONFIRM: last date for Christmas catering orders]` |
| | CORP Office Breakfast And Morning Tea | /catering/office-breakfast | 1 | |
| | CORP Staff And Team Lunch | /catering/corporate | 2 | Includes weekly and recurring intent |
| | CORP Meeting And Boardroom | /catering/corporate | 2 | |
| | CORP Drop-Off And Boxed Lunch | /catering/corporate | 2 | HOLD until Elie confirms drop-off or delivery is offered, and where |
| Event Function Catering | EVNT Party And Function Catering | /catering/events | 1 | |
| | EVNT Lebanese Catering | /catering | 1 | |
| | EVNT Birthday And Celebration | /catering/events | 2 | |
| | EVNT Pizza Catering And Group Orders | /catering/events | 2 | |
| | EVNT Manoush And Pastry Catering | /catering | 2 | |
| | EVNT School And Community Events | /catering/events | 2 | Low priority per research |
| | EVNT Mezze And Grazing | /catering | 2 | HOLD: mezze and grazing are owner-page claims, not in the ledger, and no route exists for them |
| Local Store Areas Catering | LOCL Catering Revesby | /locations/revesby | 1 | |
| | LOCL Catering Bankstown | /locations/bankstown | 1 | |
| | LOCL Catering Roselands | /locations/roselands | 1 | |
| | LOCL Catering South West And Near Me | /catering | 2 | Broad intent: tight location targeting is essential |
| Brand | BRND Dough Boss Brand | / | 1 | |
| | BRND Dough Boss Catering | /catering | 1 | |

(The real ad group names in the files carry a pipe, for example `CORP | Corporate Catering`.)

Why a separate Local Store Areas campaign: the research maps suburb terms ("catering Padstow", "catering Lakemba") to the store location pages. Those searchers want "a caterer near me", and a store page is the right landing page. Keeping them in their own campaign lets us cap their budget separately and stops them diluting the corporate numbers. Query sculpting keeps the campaigns from competing: the local campaign carries negatives for corporate, office, party and "lebanese" so those queries route to the campaign with the better-matched page (section 10).

Why one campaign per segment and not one per ad group: smart bidding needs data to learn. If the account is small, many tiny campaigns each starve. I would merge Corporate and Events into one campaign when it is time to move to Maximise conversions (section 7), and keep Brand separate always.

Brand defence: run it. INFERRED reason: with competitors present locally (see `docs/marketing/research/competitors.md`) and the brand name being two plain words, someone else can bid on "dough boss" and the cost of owning the top slot is small. [CONFIRM: search "dough boss" in Google and note any other business with the same or similar name before launch; a namesake changes what brand ads should say.] We do not bid on competitor names, and competitor names are negatives in the catering campaigns.

## 4. Keywords, match types and what is parked

Every keyword in `keywords.csv` has a `notes` value: `research` means the term is a row in `marketing/keywords.csv`, `variant` means I added a near variant that is not validated, `brand` is brand. Variants are the first to cut if the search-terms report shows nothing.

- Match types at launch: phrase and exact only. Exact for the terms we most want to own (location plus service, for example `corporate catering bankstown`). Phrase for the head term and variants. Per Google, phrase match "may show on searches that include the meaning of your keyword", which is already looser than it looks (https://support.google.com/google-ads/answer/7478529, retrieved 2026-10-02). Do not add broad match to compensate for low volume.
- No competitor names, no "halal", "gluten free", "vegan", "vegetarian", "iftar", "Eid" terms. A test enforces this.
- Search volume is blank by design. Pull Keyword Planner volumes only once the account exists and record them in the sheet, then prune groups with no search volume.

**Parked clusters** (from the research file) and what releases them:

| Cluster | Why parked | Release when |
|---|---|---|
| Halal catering | No confirmed halal claim exists. `compliance-au.md` section 3 says do not use "halal" until Elie confirms exactly what is true (certified by a named body, or practice only). Searchers with this need will expect a yes. | Elie confirms and the ledger holds a sourced claim. Then add a group, remove the `TEMP: halal` negatives, and write copy from the ledger text only |
| Gluten free, vegan, vegetarian, dairy free, nut free | Regulated or allergen-adjacent claims (`compliance-au.md` section 7). The menu data has `dietaryVerified: false` | Recipes and cross-contact verified, wording approved |
| Iftar and Eid catering | No route in the contract. High demand and seasonal. | A landing page exists and the team confirms the offer. Ads must write about the occasion and the food, not address people by religion (`compliance-au.md` section 10) |
| Mezze and grazing | Owner-page claim only; no route | Offer confirmed and a section exists on `/catering` |
| Competitor names | Conquesting is a policy and relationship risk and is not needed | Not planned |

## 5. Location targeting

- Setting: "Presence: people in or regularly in your targeted locations" for both include and exclude (campaigns.json `PRESENCE`). OBSERVED (https://support.google.com/google-ads/answer/2453995, retrieved 2026-10-02): the default setting uses both physical location and location of interest, and Google's own wording says targeting is "best effort" and "100% accuracy is not guaranteed". I could not read radius-specific rules on the pages I fetched. INFERRED: presence-only stops us paying to show to people who merely searched about Revesby while in Melbourne, which matters for a local business (whether Dough Boss delivers at all is `[CONFIRM: delivery area]`).
- Shape: radius or suburb list around each of the three stores. Starting hypothesis only: `radius_km = [CONFIRM: radius from delivery area]`. It must never be shown to customers or used as a promise. Until Elie confirms the delivery or serve area, the safer option is the suburb list in `campaigns.json` (Revesby, Padstow, Panania, Milperra, Condell Park, Bankstown, Roselands, Lakemba), which comes from search demand terms and does not claim delivery to any of them.
- Store addresses used: 12/25 Selems Parade Revesby 2212, 462 Chapel Rd Bankstown 2200, Shop MM03 Roselands Dr Roselands 2196 (typed data, `src/lib/data/catalogue.ts`).
- Expand only on evidence: a suburb earns a place when the search-terms report shows real queries or when the lead form shows enquiries from it.
- Whole-of-Sydney terms ("office catering Sydney") are in the plan because the research lists them, but the location settings keep them local. Expect them to attract CBD-skewed competition (INFERRED in `keyword-themes.md`). If the first month shows high cost and no leads from them, cut them.

## 6. Ad schedule framework

The schedule is tied to who answers enquiries: `[CONFIRM: who answers and when]`.

- Form enquiries: show all hours. A form can be filled in at 9pm for a Monday meeting, and the confirmation says someone will reply (the reply promise itself must be agreed: `[CONFIRM: how quickly the team replies]`, and not stated in ads).
- Call assets: only during store hours, using the typed hours (Revesby daily 6:30am to 2:30pm, Bankstown Monday to Friday 7:00am to 2:00pm, Roselands daily 8:00am to 3:00pm), and only for a number somebody answers. The Corporate and Event campaigns get no call asset until a catering number is named.
- Bid adjustments by hour or day: none at launch. After four weeks of data, look at conversions by hour and day and adjust in small steps. INFERRED: B2B catering searches probably cluster in office hours, but we have no evidence yet.
- Holiday trading is not in the store data (the catalogue says so). `[CONFIRM: public holiday trading hours]` before any call asset runs over a holiday.

## 7. Bid strategy path

| Phase | Strategy | Move on when |
|---|---|---|
| 1. Learning (weeks 1 to 4) | Manual CPC with a hard max CPC per keyword. `[CONFIRM: max CPC from break-even maths]` | Tracking proven (test lead seen end to end) and the first real conversions are recorded |
| 2. Volume | Maximise conversions, no target, on the consolidated campaign | The last 30 days hold roughly 30 conversions in the campaign (see Google's guidance below) |
| 3. Efficiency | Target CPA, set at or above the recent real average CPA, then stepped down | Stable cost per lead and a closed loop that shows leads become won orders |

Google guidance, OBSERVED:

- Target CPA: "For evaluation, we recommend you measure performance for the last 30 days, including at least 30 conversions." The same page says advertisers "can start using Target CPA with no conversion history" and that the recommended target is "the average CPA from the last 30 days, adjusted for any conversion delays" (https://support.google.com/google-ads/answer/6268632, retrieved 2026-10-02).
- Smart Bidding needs conversion tracking enabled, and Google "recommends meeting certain conversion baselines"; it cites measuring over periods "that have at least 30 conversions, such as a month or longer" (https://support.google.com/google-ads/answer/7065882, retrieved 2026-10-02).
- Maximise clicks "sets your bids to help get as many clicks as possible within your budget" (https://support.google.com/google-ads/answer/2979071, retrieved 2026-10-02). I did not find a max CPC limit option described on that page. [CONFIRM in the account UI: whether a max CPC limit is offered with Maximise clicks; if it is not, use manual CPC for phase 1 as planned].

My reading: the 30-conversion figure is Google's evaluation guidance, not a hard minimum. For a local catering account the volume may never reach it per campaign, which is why I would consolidate Corporate and Events before phase 2 rather than split further. If the volume still does not come, staying on manual CPC with tight keywords is a legitimate long-term answer, not a failure.

Enhanced CPC is not part of the plan.

## 8. Budget framework (formulas, no invented numbers)

Nothing in the files states a budget. `daily_budget_aud` is `null` in every campaign. Fill these inputs first:

```
target_cost_per_lead  = avg_catering_order_value
                        x gross_margin_pct
                        x lead_to_won_rate
                        x (1 - required_safety_margin)

leads_per_day_wanted  = monthly_lead_goal / 30.4
clicks_per_day_needed = leads_per_day_wanted / site_lead_conversion_rate
daily_budget          = target_cost_per_lead x leads_per_day_wanted
sanity check          : daily_budget >= clicks_per_day_needed x expected_avg_cpc
max_cpc_ceiling       = target_cost_per_lead x site_lead_conversion_rate
```

Inputs to confirm: `[CONFIRM: average catering order value]`, `[CONFIRM: gross margin %]`, `[CONFIRM: lead to won rate]`, `[CONFIRM: monthly lead goal]`, `[CONFIRM: site lead conversion rate, unknown until the form has traffic]`, `[CONFIRM: expected CPC, from Keyword Planner once the account exists]`, `[CONFIRM: total monthly ads budget ceiling]`.

How to use them:

- If `sanity check` fails, the lead goal is not buyable at that CPC. Lower the goal, raise the target cost per lead (only if margin allows), or improve the page conversion rate before spending more.
- Split the total across campaigns by the priority in the research: corporate and office first, events second, local third, brand a small capped slice (brand clicks are usually cheap, and the cap is set by search volume: `[CONFIRM: brand search volume from Search Console or Keyword Planner]`).
- The first month's spend is the cost of learning. Set a fixed learning budget ceiling that Elie is comfortable losing: `[CONFIRM: learning budget ceiling]`. Do not scale a campaign that has not produced a won order, even if it produces leads.
- Do not state any CPA, CTR or CPC benchmark as a target in this account until it is sourced (NUMBERS RULE).

## 9. Naming, UTMs and tracking template

- Campaign: `DB | Search | <Segment> <Offer>`. Ad group: `<CODE> | <Theme>` with CODE one of CORP, EVNT, LOCL, BRND. Labels: `wave-1`, `wave-2`, `hold`.
- Final URLs carry no UTMs. A campaign-level tracking template adds them and auto-tagging adds the gclid: `{lpurl}?utm_source=google&utm_medium=cpc&utm_campaign=<slug>&utm_content=rsa-v1&utm_term={keyword}`.
- `utm_campaign` is `<segment>-<offer>-<area>`: `corporate-office-catering-southwest`, `events-function-catering-southwest`, `local-catering-southwest` (overridden per store ad group as `local-catering-revesby`, `local-catering-bankstown`, `local-catering-roselands`), `brand-dough-boss-southwest`.
- `utm_content` is the creative variant. Change `rsa-v1` to `rsa-v2` when an ad is replaced, so results do not mix.
- `{keyword}` returns the keyword text, which can contain spaces, so reporting should lower-case and hyphenate it. [CONFIRM: that `{keyword}` populates in the plugin's captured attribution (`utmTerm`, max 120 characters).]
- The plugin already sanitises and stores these fields plus `gclid`, `gbraid` and `wbraid` (`src/lib/attribution-schema.ts`). The landing path is stored without the query string.

## 10. Negative keyword strategy

`negatives.csv` has three layers:

1. **Account level**: job seekers, recipe and DIY, training and courses, franchise, "free" and "cheap" intent, catering equipment and supplies, start-up advice, food trucks, interstate cities. Interstate names are a backstop, because location targeting is the main control.
2. **Campaign level, catering campaigns**: promo-code seekers (voucher, coupon, student discount: vouchers are a retail feature, not a catering offer), consumer takeaway intent (pizza delivery, takeaway, restaurant, delivery apps), competitor names, the Dough Boss brand name (so brand queries go to the Brand campaign), and the temporary parked cluster.
3. **Query sculpting**: Corporate negates event words (birthday, wedding, kids party). Events negates corporate words (office, corporate, staff, boardroom, meeting). Local negates all of those plus "lebanese" and the two exact queries owned by Corporate. Each cross-negative sends a query to the campaign with the better landing page.

Entries marked `TEMP:` (halal, gluten free, vegan, vegetarian and so on) are deliberate and temporary. Review them weekly and remove them only through the release steps in section 4.

Safety: the unit test checks that no negative blocks a target keyword in its own scope, using Google's phrase, exact and broad negative rules. It already caught one real conflict (an `office` negative in Events against `lunch grazing boxes office`, which I moved to the Corporate campaign).

Process: add negatives from the search-terms report, not from guesses. Prefer exact or phrase negatives for single bad queries and avoid broad single-word negatives that can silently block good traffic.

## 11. Ad copy: rules, angles, pinning and what the copy leans on

Each ad group has one RSA with 15 headlines (30 characters or fewer) and 4 descriptions (90 or fewer), varied across four angles: what you can order, who it is for, the action, and the place. Google's limits: headlines up to 30, descriptions up to 90, up to 15 and 4 (https://support.google.com/google-ads/answer/7684791, retrieved 2026-10-02). The test checks every length and that `char_count` equals the real length.

Claims rule applied to the copy: no superlatives or comparatives, no ratings or review counts, no halal, organic, "fresh" or "authentic", no prices, "from" figures, discounts or free offers, no delivery, speed or lead-time promises, no capacity or minimum numbers, no exclamation marks, no emojis. Digits appear only where they come straight from typed store data (phone numbers, address numbers, "7 days" for Revesby). The test enforces all of this, including a check that no digit appears that is not store data.

Where the benefit is stated as what the customer gets to do ("tell us the date, headcount and dietary needs and we'll come back with a quote") the copy asserts nothing we cannot back.

**Pinning** (only where it protects the message; Google notes pinning "causes it to show only in that specific position" and recommends using it sparingly: https://support.google.com/google-ads/answer/7684791):

- Brand ad group: the headlines `Dough Boss` and `Dough Boss Lebanese Bakery` are pinned to position 1, so the brand name always leads and the two rotate between themselves.
- Brand catering ad group: `Dough Boss Catering` is pinned to position 1 for the same reason.
- Nothing else is pinned. Every other headline reads sensibly in any position, so there is nothing to protect. (INFERRED: pinning also tends to lower Google's ad strength rating.)

Ledger dependencies. The copy leans on these facts, which Elie should put in the claims ledger once so the whole account is backed:

1. Dough Boss is a Lebanese bakery (used as "Lebanese Bakery" in several headlines). Source today: the owner's pages, the catalogue category text and the brief.
2. Dough Boss takes corporate, office breakfast and event catering enquiries and replies with a quote.
3. Manoush, pizza and savoury pies are on the menu (catalogue item and category names). Catering boxes are a menu category in the catalogue. [CONFIRM: that these items can be part of a catering order.]
4. The three stores and their addresses, hours and phone numbers (typed data; re-confirm before launch).
5. `[CONFIRM: store hours text "Open 7 Days At Revesby", "Bankstown Open Mon To Fri", "Roselands Open Daily" are current]`.

I have not run the humanizer skill over the ad copy: the lines are short, constrained to the claims rule and character limits, and I kept them plain by hand. Run it over any longer prose before it goes on a page, and never over a fact.

## 12. Assets (extensions)

Detail is in `extensions.json`. Summary:

- Sitelinks: quote request, corporate, office breakfast, events and the three stores. Titles 25 characters or fewer, description lines 35 or fewer.
- Callouts: eight short, claim-free lines.
- Structured snippets: "Service catalog" (corporate catering, office breakfast, event catering). A "Neighborhoods" snippet is on hold because it can read as a service-area promise.
- Call assets: one per store with the real numbers and store-hours schedule. The Roselands number is a mobile (04) number, so confirm call reporting works for it. No call asset on the Corporate and Event campaigns until a catering number is named.
- Location assets: link the Business Profile for each store. Do not type addresses, and do not create a "catering" profile at an unstaffed address (`compliance-au.md` section 9). `[CONFIRM: a verified Business Profile exists for each store]`.
- Lead form: **recommend against at launch.** The plugin enquiry form is the system of record and carries guest band, event type, dietary needs, consent text and attribution. Google's lead form assets need conversion-focused bidding and a lead form conversion goal and send leads to a second place (https://support.google.com/google-ads/answer/9423234, retrieved 2026-10-02). Revisit with a single-ad-group experiment after 60 days. Compare cost per won lead, not cost per form.
- Not used: price assets, promotion assets (no confirmed offers), image assets (no approved photography staged).

## 13. Optimisation routine

**Weekly (about 45 minutes, same day each week)**

1. Check conversions and test-lead status: did the form, click-to-call and call-from-ad conversions record? Any gap in days with spend but zero tracked actions is a tracking fault until proven otherwise.
2. Search terms report (all campaigns): add negatives for junk, note good new queries as exact keywords. Remove `TEMP` negatives only per section 4.
3. Lead quality: open the week's leads in the plugin. Mark each as qualified or not (guest band, event type, in area). Record the lead source and keyword (`utmTerm`).
4. Impression share lost to budget and to rank by campaign; check pacing against the budget formula.
5. Disapprovals and policy notices; any ad not serving.
6. Update the won-deals CSV for the manual closed loop (`conversion-plan.md` section 7).

**Monthly (about 2 hours)**

1. Cost per qualified lead and, once the loop runs, cost per won order and revenue per ad dollar, by campaign and ad group.
2. Promote or cut ad groups: wave 2 groups join when wave 1 produced search-terms evidence. Cut groups with spend and no leads after a fair sample (`[CONFIRM: minimum spend or clicks before cutting]`).
3. Ad test: replace the weakest headline or description in the top ad groups, and bump `utm_content` (`rsa-v2`).
4. Landing page review: page speed, form completion rate (the `quote_step` events), and message match between ad and page.
5. Bid phase decision (section 7).
6. Policy and claims audit: reread `compliance-au.md` section 10 and the ledger. Confirm store hours and phone numbers are still current.
7. Seasonal planning: Christmas, Ramadan and Eid dates, school terms, EOFY. `[CONFIRM: lead times for each season]`.

## 14. Diagnostics playbook

**Low impressions**
1. Status first: campaign paused, ad group on hold, or ads disapproved. 2. Keywords marked low search volume or "eligible (limited)". 3. Budget or max CPC too low to enter auctions: check impression share lost to rank. 4. Location too narrow or presence-only combined with a small area. 5. Match types too tight: look at search terms for near misses before changing match type. 6. Ad schedule excluding the time people search. Fix in that order. Do not respond by adding broad match.

**Low CTR**
1. Does the first headline echo the keyword? In each group it does by design, so check the search terms: are they the intent we wrote for? 2. Position and competitors (auction insights). 3. Device split. 4. Add assets (sitelinks, callouts). 5. Replace the weakest headlines and re-test. Compare only within the same ad group and a fair period. Do not compare CTR to any benchmark unless it is cited.

**Clicks, no leads**
1. Prove tracking: submit a test lead and check each hop (section 6 of `conversion-plan.md`). 2. Check the landing page: loads fast on mobile, quote form visible without scrolling far, the page answers the ad (same service and place). 3. Form friction: where do people drop off (`quote_step` 1 to 2 to submit)? 4. Intent mismatch: search terms that are informational or consumer. Add negatives. 5. Location mismatch: clicks from outside the serve area. 6. Phone behaviour: are people calling instead? Check `click_to_call` and call assets. 7. Opening hours: clicks arriving when nobody can reply.

**Junk leads**
1. Read the lead's keyword (`utmTerm`) and search term; add exact negatives. 2. Tighten the location, and consider removing the head terms that attract consumers. 3. Add form qualifiers already in the plugin (guest band, event type, suburb) and use them to score. 4. Tell Google which leads were good through the closed loop so bidding learns quality, not volume. 5. Remove or hold the ad group that produces them.

## 15. What would change my mind

- If tracking cannot be proven end to end, nothing launches, regardless of how good the copy is.
- If Elie confirms a delivery area much smaller than the store catchments, drop the whole-of-Sydney terms and run the Local campaign only until the area grows.
- If most enquiries arrive by phone, make call assets and call conversions the primary measure and reduce emphasis on the form.
- If the first 60 days show the cost per won order above break-even, pause paid search and put the effort into outreach, directories and on-site SEO, where the cost per lead is not a per-click auction.
- If a Business Profile with reviews exists later, Local Services or a Maps-led setup may beat Search for the Local campaign.
- If Square closed-loop upload works and there are enough won orders, move to value-based bidding on real order values.
- If an experiment (not a switch) shows PMax or broad match with Target CPA beats Search on cost per won lead, with brand exclusions and a reviewed asset library, adopt it for that segment only.
- If a lead form experiment beats the site form on cost per won lead, adopt it.
- If Elie confirms halal certification, release the halal cluster with copy from the ledger.
- If a namesake business uses "Dough Boss", change the brand copy and consider adding a location qualifier.
- If Elie later announces a new product, build its campaign only then, from confirmed facts, and not before (`docs/site/teaser-direction.md` rule 6).

## 16. Compliance notes (practical guidance, not legal advice)

- Google Ads misrepresentation policy: inaccurate claims and failing to disclose the payment model or expense are disallowed (https://support.google.com/adspolicy/answer/6020955, summarised in `compliance-au.md` section 10). No price is in any ad; the landing pages must carry the price basis once the ledger holds confirmed prices.
- Australian Consumer Law: the claims rule is how we stay inside misleading conduct. Every fact in the copy traces to typed store data or a ledger entry.
- Spam Act 2003 and Privacy Act (APPs): a lead is not consent to marketing. The enquiry form stores `consentAt` and `consentText`; the plugin version must keep that. The privacy policy must say data may be shared with ad platforms before Enhanced Conversions are used (`conversion-plan.md` section 5). LAWYER to check the wording.
- Do Not Call: the plan does not include calling leads' mobiles for marketing. Replying to an enquiry is not the same as cold calling. REGULATOR (ACMA) before any outbound calling programme.
- AANA Food and Beverages Advertising Code (relevance not established for Dough Boss, follow as best practice): it defines children as under 15 and restricts occasional-food promotion aimed at them. All ad copy speaks to adults organising an event or an office order and makes no appeal to children.
- Google Ads account settings: the draft records `eu_political_advertising = does_not_contain` because Google asks every advertiser to declare it. [CONFIRM at account creation.]

## 17. Open questions only Elie can answer

1. `[CONFIRM: who answers enquiries and when]` and how quickly they reply.
2. `[CONFIRM: delivery area]`, hence the radius, hence the suburb list.
3. `[CONFIRM: is drop-off or delivery offered for catering]` (releases the Drop-Off group).
4. `[CONFIRM: which phone number answers catering calls]`.
5. `[CONFIRM: average catering order value]`, gross margin and lead to won rate (budget and CPA maths).
6. `[CONFIRM: total monthly ads budget ceiling]` and a learning budget ceiling.
7. `[CONFIRM: halal status]`, which decides whether that cluster opens.
8. `[CONFIRM: mezze and grazing offer exists, and what it includes]`.
9. `[CONFIRM: who owns the Google Ads account, GA4 property, GTM container and Business Profiles]`.
10. `[CONFIRM: last date for Christmas catering orders]`.
11. `[CONFIRM: public holiday trading hours]` and that all typed store hours are current.
12. The ledger entries listed in section 11.
13. The storefront audit (`docs/wp/01-storefront-map.md`) shows a `/wholesale/` page that is not in the route contract; volume and wholesale ads cannot use it until it is added to the contract.

## 18. Sources (all retrieved 2026-10-02)

- Target CPA: https://support.google.com/google-ads/answer/6268632
- About Smart Bidding: https://support.google.com/google-ads/answer/7065882
- Maximise clicks: https://support.google.com/google-ads/answer/2979071
- Keyword matching options: https://support.google.com/google-ads/answer/7478529
- Performance Max: https://support.google.com/google-ads/answer/10724817
- Responsive search ads: https://support.google.com/google-ads/answer/7684791
- Location targeting settings: https://support.google.com/google-ads/answer/2453995
- Lead form assets: https://support.google.com/google-ads/answer/9423234
- Google Ads policies cited through `docs/marketing/research/compliance-au.md` section 10.
- Fetch caveat: the fetch tool summarises pages with a small model, so quoted wording is as that summary reported it. Re-check any quote on the official page before relying on it in a client document.
