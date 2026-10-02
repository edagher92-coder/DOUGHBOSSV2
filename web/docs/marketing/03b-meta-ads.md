# Dough Boss: Meta ads (Facebook and Instagram) build, DRAFT

Prepared 2026-10-02 for Elie. Status: DRAFT and review-ready. Nothing described here exists in any Meta ad account, Page, Instagram account, audience or Events Manager dataset. Every campaign, ad set and ad is specified to launch PAUSED, and a human stages it in Dough Boss's own ad account after review. This slice has not touched any ad account.

This is an internal planning document, so it carries `[CONFIRM: ...]` and `[VERIFY: ...]` items. Anything customer-facing (the ad text in `copy.csv`) carries none, and a test enforces that (`tests/unit/marketing-meta-ads.test.ts`).

Labels: OBSERVED means I read it at the cited URL on 2026-10-02, through a fetch tool that summarises pages with a small model, so quoted wording is as it reported it. INFERRED is my reasoning. `[VERIFY: ...]` is a Meta behaviour I could not confirm from a page I could read: check it in Ads Manager or the docs before relying on it. `[CONFIRM: ...]` needs Elie or the team.

## 0. Files and the evidence limits

| File | What it is |
| --- | --- |
| `marketing/meta/campaigns.json` | Machine-readable build: 4 campaigns, 6 ad sets, 15 ads, all `paused`, no budgets, radii or cost caps (all `[CONFIRM]`) |
| `marketing/meta/copy.csv` | 15 ads across office breakfast, team lunch, meeting catering, event and party catering, community events, one generic coming-soon list ad (held) and warm retargeting, each with a UTM-tagged final URL |
| `marketing/meta/audiences.md` | Geography, broad-plus-suggestions audiences, exclusions, customer lists from consented leads only, retargeting and lookalike gates |
| `marketing/meta/creative-brief.md` | Real-photography and video shot lists, formats, safe zones, captions, one-page review checklist |
| `marketing/meta/tracking.md` | Pixel, Conversions API, event de-duplication, Lead mapping, domain verification, QA checklist, Square paid-order phase |

What limits the evidence, stated plainly:

- No search, reach, CPM, CPC or conversion data was available, and no Meta account was opened. Every budget, bid and radius in this plan is a formula with named inputs or a `[CONFIRM]`, never a number I made up.
- The claims ledger (`src/content/ledger.ts`) holds the type and the rules but no confirmed claims yet. So ad copy uses only the typed store data (store names, addresses, the three suburbs), the plain call to action the brief allows ("tell us the date, headcount and dietary needs and we'll come back with a quote"), and the phrase "Lebanese bakery", which the owner's published site uses. Section 12 lists the few facts the copy still leans on so Elie can confirm them once.
- I could read Meta's developer documentation for deduplication, server event parameters, customer information hashing, the API endpoint, custom audience hashing, special ad categories and the call-to-action list. I could not read Meta's Business Help pages for Advantage+ audience, the learning phase or aggregated event measurement (the fetch returned a 404 or only a title), nor the Stories and Reels ad specs. Those points are third-party summaries or `[VERIFY]`, and I say so where they appear.
- Hosts that blocked or failed: `www.jonloomer.com` (HTTP 403), `www.facebook.com/business/help/2893003740770926` (404), `www.facebook.com/business/ads-guide/video/facebook-stories/video-views` (404), `developers.facebook.com/docs/marketing-api/audiences/special-ad-category/advantage-audience` (404). `www.facebook.com/business/help/331612538028890` and `.../112167992830700` returned only a page title.

## 1. The plan in one page

1. Objective first: Leads, with the conversion happening on our own website, on the `/catering/*` pages that hold the enquiry form. Instant forms are a later A/B test, not the starting point (section 3).
2. Four campaigns, all PAUSED at creation: Corporate Office, Events and Parties, Coming Soon List (generic awareness, held), Warm Retarget (held). Six ad sets. Fifteen ads.
3. Start broad: Advantage+ audience with the store radius as the one hard control, job-role and interest options as suggestions only, and Advantage+ placements, reviewed weekly by placement (section 4).
4. Creative does the targeting. The first line of every ad says who it is for. Real photographs only; the AI hero images are illustrative and stay out of ads (`creative-brief.md`).
5. Geography is a `[CONFIRM: radius]` hypothesis per store, held to the area Elie will actually serve.
6. Measure leads that become business, not clicks. `Lead` is the optimisation event; plugin statuses (`quoted`, `confirmed`, `paid`) come back to Meta as server events so lead quality is visible (`tracking.md`).
7. One variable at a time, with a written method for when a test is allowed to declare a winner, and an honest label when volume is too small to say.
8. Budgets are formulas from break-even maths. No budget is set until the inputs are confirmed (section 9).
9. No spend until a test lead has been seen end to end in the plugin, Test Events and Events Manager.
10. Every ad is checked against the claims rule and against `docs/marketing/research/compliance-au.md` before staging.

Two things I would not compromise on: no spend before tracking is proven, and no ad pointing at an enquiry route nobody answers.

## 2. Start NOW on the existing WordPress site

Marketing must not wait for any build. What can start this week, with the site as it is, and what genuinely needs a plugin change.

Can start now, no build:

1. Own the assets. Create or confirm, in Dough Boss's own business portfolio, the Facebook Page, the Instagram account and an ad account in AUD with the Australia/Sydney time zone, with two-factor sign-in and named admins. `[CONFIRM: the operating entity and ABN, and that the Page, Instagram and ad account are owned by the same entity that owns the brand and the site]`. No other business's account, page, pixel or token is used anywhere in this build.
2. Shoot the real photography and video (`creative-brief.md` shot lists). This is the longest lead item and the one most likely to hold launch up.
3. Confirm the handful of facts the copy leans on, so each becomes a ledger entry (section 12).
4. Decide who answers catering enquiries, and when. The form takes enquiries at all hours; the response time is part of conversion. `[CONFIRM: owner, mailbox, phone and target response time]`.
5. Verify the domain `doughboss.com.au` in the business portfolio. DNS sits at Crazy Domains and is Elie's to change or approve; a meta tag or HTML file avoids DNS if the companion plugin can print it. This slice does not touch DNS.
6. Create the dataset (Pixel) and put its ID in the plugin configuration, with consent off by default. The plugin already ships a consent-gated Meta bridge (`DOUGHBOSS_META_PIXEL_ID`, `doughboss_marketing_config`).
7. Build the four campaigns in Ads Manager in draft or paused state from `campaigns.json` and `copy.csv`, using the ad preview to check each placement. Do not publish.
8. Post the real photos organically on the Page and Instagram. This builds the real engagement history and gives the creative a trial run at no media cost. It is organic posting, not a launch, and is a human action.

Needs a small plugin change before any money is spent (hand-off in `tracking.md` section 13):

- The catering form must fire `generate_lead` (it does not today).
- The consent banner must dispatch `doughboss:consent` (nothing does today, so the bridge never switches on).
- The enquiry must store UTM, `fbclid`, `_fbc`, `_fbp`, consent and landing URL (none stored today).
- A server-side `Lead` with the same `event_id`.

Landing pages. The `/catering/*` spokes arrive with the companion plugin. If a spoke is not live at staging, swap the path in the ad's URL to `/catering` (an owner-published page that exists and carries a live enquiry form) and keep the UTMs. Never stage an ad whose URL returns a 404, and never point an ad at a page whose offer differs from the ad.

If tracking is delayed past the point where the photography is ready: do not spend on a Leads campaign, because Meta cannot optimise for a `Lead` it never sees. The only defensible fallback is a very small, time-boxed Traffic test to `/catering` with the full UTM string, where staff record how each enquiry heard about Dough Boss. That is weak evidence, labelled as such, with a hard cap `[CONFIRM: cap]` set before it starts. I would rather wait a week.

## 3. Objective: website leads first, instant forms later

Recommendation: campaign objective Leads, conversion location Website, optimise for the `Lead` event, landing on the matching `/catering/*` page.

| | Website leads (recommended first) | Instant forms (later test) |
| --- | --- | --- |
| What the buyer does | Clicks through, sees the page, fills the plugin's enquiry form | Taps the ad, a form opens inside the app with details pre-filled |
| Volume | Fewer, because each lead takes more effort | More, because it takes less effort |
| Intent | Higher. The person chose to leave the app and describe an event | Lower on average. Pre-filled forms are easy to submit by accident `[INFERRED]` |
| Data we get | The plugin's structured fields: date, headcount, package, store, dietary counts, quote workflow | Whatever questions the form asks. Lives in Meta until moved |
| Fit with the quote workflow | Direct. The enquiry lands in `doughboss_catering_enquiries`, with statuses and staff quote tools | Weak. The plugin has no confirmed Meta lead connector, so each lead is moved by hand or by a tool we have not chosen `[CONFIRM]` |
| Attribution | Full. UTMs, `fbclid`, `event_id` and CAPI tie the lead to the ad and later to the won order | Meta sees the lead natively but the plugin does not, so the loop to won orders is manual |
| Privacy | The enquiry sits in our own system with a consent record | Lead data sits with Meta and must be exported, which adds a place personal data lives |
| Cost per lead | Higher | Lower. But cost per lead is not the number that matters |
| What it needs first | Plugin change (section 2) | Form design, a way to get leads into the plugin, a clear response process |

Why website first, in order of weight:

1. The enquiry form is the quote workflow. A lead that never reaches the plugin never gets a quote, a status or a won-order signal.
2. The plugin gives us attribution and consent we can control. That is what makes the quality loop in `tracking.md` possible.
3. B2B catering is a considered purchase with a date, a headcount and dietary needs. Friction filters out casual taps, which is what we want while volume is small and each lead costs staff time.
4. The risk of the website path is the leak between the ad and the form. The mitigation is page-to-ad match, a form that works on a phone, and fast loading, all checked in the QA list.

The test that would change my mind. Once website leads are flowing and tracked, run one controlled A/B test of conversion location (website against an instant form with extra qualifying questions and a review step, using the "higher intent" style of form `[VERIFY: current form type names]`), same creative, same audience, same dates, equal budget. Judge it on cost per qualified lead and on quoted leads per spend, not on cost per lead. If instant forms win on qualified leads and the hand-off into the plugin is solved, scale them. If they win only on raw leads, they have lost.

Not now: Messenger, WhatsApp or call-led conversion locations. They need a person answering in real time and a number nobody has confirmed. `[CONFIRM: which number answers catering calls and when]`. Click-to-call on the website is measured as `Contact` and reported.

Lead quality, defined. A qualified lead is an enquiry with an event date, a headcount, a reachable contact and a store the team can serve, as judged by the person who answers it. The plugin's status movement (`new` to `quoted` to `confirmed`, `deposit_paid` or `paid`) is the record. Quality is judged on that, weekly.

## 4. Campaign structure for the current Meta system

OBSERVED (Marketing API, 2026-10-02, https://developers.facebook.com/docs/marketing-api/audiences/special-ad-category): food and catering sit outside Meta's four special ad categories (housing, employment, financial products and services, issues, elections and politics). The campaign declares category NONE. No age or detailed-targeting restrictions of a special category apply.

| Campaign | Phase | Ad sets | Ads | Landing | Optimises for |
| --- | --- | --- | --- | --- | --- |
| DB \| Meta \| Leads \| Corporate Office | Launch | CORP Bankstown catchment (wave 1), CORP Revesby catchment (wave 1), CORP Roselands catchment (wave 2, held) | 8 | `/catering/office-breakfast`, `/catering/corporate` | `Lead` (catering enquiry) |
| DB \| Meta \| Leads \| Events and Parties | Launch | EVNT Three-store catchment (wave 2) | 4 | `/catering/events` | `Lead` (catering enquiry) |
| DB \| Meta \| Leads \| Coming Soon List | Later, held | SOON Three-store catchment (wave 3, held) | 1 | `/` (teaser section) | `Lead` with `content_name = waitlist`, from `waitlist_submit` |
| DB \| Meta \| Leads \| Warm Retarget | Later, held | WARM Catering page visitors (wave 3, held) | 2 | `/catering` | `Lead` (catering enquiry) |

Why this shape:

- One campaign per buyer intent, because corporate and event buyers want different words and a different landing page, and the generic coming-soon list ad is awareness only and must stay apart from catering so waitlist sign-ups never train the catering campaigns. Per `docs/site/teaser-direction.md` rule 6, there is no product-specific campaign: one generic ad, held, with no product claim.
- One ad set per store catchment for corporate, because the creative says where the store is and the radii differ. This is the one place I split by geography. If the budget formula (section 9) says a catchment cannot fund learning on its own, merge Bankstown and Revesby into one ad set and keep the two sets of ads.
- Events combine the three store areas in one ad set because the event buyer is not tied to a nearby store the way an office is, and a single pool learns faster.
- Few ads per ad set (three or four). Too many ads split a small budget into slivers that never learn.
- Ad set budgets, not campaign budget optimisation, at launch. With catchments of different size and an untested budget, campaign-level optimisation would pour money into whichever ad set Meta likes first. Revisit when the ad sets share an audience and the data shows they can be pooled.

### Advantage+ audience and placements, or manual?

Start with Advantage+ audience and Advantage+ placements, with the store radius as the hard control. My reasoning:

- With little or no conversion history, manual narrow targeting gives the account no advantage and shrinks a small local audience further. Meta's system learns from the `Lead` events we send. Broad delivery with a clear first line lets the creative sort the audience.
- Advantage+ audience, as third-party summaries of Meta's current behaviour describe it (I could not read Meta's own help page: `[VERIFY]`), treats location, minimum age, language and excluded custom audiences as hard limits, and age range, gender, detailed targeting, custom audiences and lookalikes as suggestions that guide delivery first. A search extract of that description: https://www.conversios.io/blog/meta-advantage-audience-vs-detailed-targeting-2026-guide/ (retrieved 2026-10-02; third party, not Meta). Re-read Meta's own page at staging.
- The cost of this choice is control. We will see some reach to people who are not buyers. The fix is creative that speaks to the buyer in its first line, and judging on qualified leads rather than reach.
- Advantage+ placements: start on, because Meta delivers to whichever surface is cheapest for a lead and we have no placement evidence of our own. Condition: every ad has been previewed in Feed, Stories and Reels (`creative-brief.md` section 5). Review the placement breakdown each week. If a placement spends without producing qualified leads, exclude it for that ad set, and note why. Audience Network and similar off-platform placements are the first I would test excluding, because intent there is the hardest to judge `[INFERRED]`.
- Advantage+ creative enhancements (automatic text and image changes): off at launch. Each variation would be copy we had not reviewed, which the claims rule does not allow. `[VERIFY: option names in Ads Manager]`.
- Manual targeting becomes useful later, for one thing: a narrow test where we want a clean comparison, run as an A/B test with the audience held constant.

Naming: campaign `DB | Meta | Leads | <Segment>`; ad set `<CODE> | <Area or audience>`; ad `<CODE>-<AREA>-<nn> <angle> <format>-<variant>`. The same slug appears in `utm_content`.

Launch order: wave 1 is Corporate Bankstown and Corporate Revesby, the two catchments with the clearest office and depot demand in `docs/marketing/research/local-demand.md` (INFERRED, ranking by closeness to a store and repeatability, not by measured volume). Wave 2 adds Events and, once confirmed, Roselands. Wave 3 is the generic Coming Soon List ad and Warm, held for the reasons in `campaigns.json`.

## 5. Geographic targeting (a hypothesis, per store)

Full detail and the suburb hypotheses are in `audiences.md` section 3. The short version:

- Radius around each typed store address: `[CONFIRM: radius]`. Never copy, never a delivery promise.
- Begin no wider than the area Elie confirms Dough Boss will actually serve. Widen only when the region and placement breakdowns show qualified leads outside it.
- Location type: `[CONFIRM: living in this location versus living in or recently in this location]`. Hypothesis: "living in or recently in" for office and depot buyers (catches people who work in an area but live elsewhere), "living in" for party organisers.
- Check overlap between catchments at staging. If they overlap heavily, merge ad sets and split the creative.
- The ad says where the store is (typed address), never where we go.

## 6. Audience approach

Detail in `audiences.md`. The decisions:

- Broad with strong creative, location as the hard control.
- Job-role, industry and interest options as suggestions only, from what the picker offers today `[CONFIRM: picker options]`. Meta has removed or limited many detailed options, so no plan can lean on them.
- Exclusions: people who already enquired (from a consented list, once it exists). No exclusion or targeting by religion, ethnicity or any personal attribute. Meta's ad standards bar asserting or implying personal attributes and wrongly targeting or excluding groups (OBSERVED via `compliance-au.md` section 10, https://transparency.meta.com/policies/ad-standards/).
- Customer lists: only from consented leads, hashed with SHA-256, with the privacy policy naming Meta, and never carrying dietary or free-text data. Meta's custom audience guide says data must be SHA-256 hashed and normalised and that the advertiser must accept Meta's custom audience terms and owns the data (OBSERVED, https://developers.facebook.com/docs/marketing-api/audiences/guides/custom-audiences, 2026-10-02). Australian law adds the consent and disclosure conditions in `audiences.md` section 5 (LAWYER to check).
- Retargeting and lookalikes are gated by consent, size and seed quality. Not at launch.
- Teaser and event ads speak to adults. Never to children (AANA code, `compliance-au.md` section 7).

## 7. Frequency and fatigue rules

A local audience is small, so the same people see the same ad again and again. Fatigue shows up as falling interest and rising cost before any dashboard turns red. The rules are a method, with thresholds set from the account's own baseline rather than from a number I invent.

Definitions: frequency is impressions divided by reach for the period; saturation is weekly reach as a share of the estimated audience size `[VERIFY: where Ads Manager shows estimated audience size for the chosen settings]`.

Method:

1. Record a baseline for each ad set after its first complete week: frequency, link click-through rate, landing page view rate, cost per lead, qualified lead rate.
2. Fatigue signal = frequency rising for two reviews in a row AND link click-through rate falling AND cost per lead above target (section 9) for two reviews in a row. One metric moving alone is noise; all three together is a pattern.
3. Set a frequency ceiling per ad set after two weeks of data: the frequency level at which that ad set's own cost per lead started to climb. `[CONFIRM: ceiling per ad set, once the baseline exists]`. Until it is set, review rather than cap.
4. Response order, cheapest first: (a) add a new hook variant to the ad set (same offer, new first line and first frame), (b) rotate the weakest ad out, (c) refresh the picture or video, (d) only then widen the radius, and only on evidence from the region breakdown, (e) lower the budget if the audience is saturated, instead of buying the same people more times.
5. Always keep one new variant ready per ad set at every weekly review, so a fatigued ad has a replacement waiting.
6. Saturation check: if weekly reach as a share of audience size is high and still rising, the radius is too small for the budget. Raise the radius within what Elie confirms, or cut the budget. `[CONFIRM: saturation level at which to act, once there is data]`.
7. Retargeting is the highest-fatigue risk because the audience is the smallest. Cap it by budget and rotate its creative before the other campaigns'.

## 8. Creative testing method

One variable at a time. A test changes exactly one thing; everything else is held constant. The order I would run them, because each answer shapes the next:

1. Hook: the first line of the primary text and the first frame (a question about the booking against a question about the meeting).
2. Format: still photograph against short video, same hook.
3. Angle: office breakfast against team lunch against meeting catering, same format.
4. Call to action button: Get quote against Learn more, same everything else.
5. Landing page variant, last, with the plugin team.

Set up. Use Meta's A/B test tool where the account allows it, because it splits the audience so the two versions do not compete `[VERIFY: tool name and availability in Ads Manager]`. If it is unavailable, duplicate the ad set, change only the one variable, keep equal budgets and identical start day. Do not test inside one ad set, where Meta's delivery will favour a version early and starve the other.

Write the test down before it starts, in a log with: the hypothesis, the one variable, the primary metric, the guardrail metrics, the start date, the end rule, and the decision it will change. Primary metric is qualified leads per unit of spend once the plugin data is flowing, and cost per `Lead` until then. Guardrails are landing page view rate (to catch a slow or broken page) and link click-through rate.

Minimum evidence before declaring a winner, as a method:

1. Full weekly cycles. Both versions run the same whole number of weeks, starting the same day, because catering enquiries have a day-of-week pattern.
2. No edits. A change to creative, audience, budget or schedule during a test voids it. Restart.
3. Enough events to tell a real difference from chance. For a rate difference between two versions, the rough sample size per version is `n = 16 x p x (1 - p) / d^2`, where `p` is the baseline rate (for example lead rate per landing page view) and `d` is the smallest absolute difference worth acting on (a rule of thumb for about 80 per cent power at the usual 5 per cent significance level). Elie sets `d`; the baseline `p` comes from our own data. `[CONFIRM: smallest difference worth acting on]`.
4. A pre-chosen significance rule. Use a two-proportion test, with the threshold decided before the test begins. `[CONFIRM: significance threshold]`.
5. Replication before scaling. A winner has to win again in a second window or a second catchment before it becomes the default.
6. Honest labels. Local B2B catering will often not reach the sample size in step 3. When it does not, call the result directional, not a win. The permitted action on directional evidence is to stop a version that is clearly behind on leading indicators (link click-through, landing page view rate, enquiry rate), never to crown the other one.
7. Do not stop a test the day one version pulls ahead. Looking early and stopping on a lead is how false winners get through.

Ad-level hygiene: every ad carries a unique `utm_content` (already in `copy.csv`) so the plugin's enquiry rows say which creative produced them, not just which campaign.

## 9. Budget formulas

No budget, bid, cost cap or radius is set in the files. The formulas below turn named inputs into decisions. Every input is a `[CONFIRM]`.

```
target_cost_per_lead = avg_catering_order_value x gross_margin_pct x lead_to_won_rate x (1 - required_safety_margin)
leads_per_day_wanted = monthly_lead_goal / 30.4
daily_budget         = target_cost_per_lead x leads_per_day_wanted
```

Supporting checks:

- Learning check: `weekly_budget_to_exit_learning = target_cost_per_lead x optimisation_events_threshold_per_week`. The threshold comes from Meta's learning phase page, which I could not read (title only). `[CONFIRM: figure, from https://www.facebook.com/business/help/112167992830700 or the Ads Manager delivery column]`. If the weekly budget available to an ad set is lower than this, merge ad sets until it holds. Meta also tells advertisers that significant edits can reset learning `[VERIFY]`, so avoid mid-test edits.
- Break-even ceiling: `max_cost_per_lead_to_break_even = avg_catering_order_value x gross_margin_pct x lead_to_won_rate`. Anything above it loses money per lead on average.
- Lead-to-won rate: until the plugin shows real won orders, this input is a guess and must be written down and labelled as a guess. Replace it with the measured rate from the plugin's status history as soon as there are enough leads to be meaningful `[CONFIRM: how many before we trust it]`.
- Split between ad sets: weight the daily budget by the expected qualified leads each catchment can produce, not by area size.
- Scaling rule: raise an ad set's budget in small steps, `[CONFIRM: step size]`, no more than once per review, and only when cost per qualified lead is at or under target for the review before and the saturation check in section 7 passes.
- Media budget ceiling: `[CONFIRM: the monthly media cap and who owns it]`. No formula overrides it.
- Meta's minimum daily budget for the chosen setup: `[VERIFY: read at staging]`.
- The held Coming Soon List ad is the exception: a waitlist sign-up has no order value, so the catering break-even maths above does not apply to it. Its budget is a fixed, time-boxed test cap that Elie sets before it starts (`campaigns.json`), or it does not run.

Metrics to compute each week, from the plugin and Ads Manager together: cost per `Lead`; qualified lead rate (qualified leads divided by leads); cost per qualified lead; quote rate; won rate; cost per won order; revenue per won order; return on ad spend as won revenue divided by spend. Report cost per qualified lead and cost per won order as the headline numbers, never cost per click.

## 10. Weekly optimisation routine

Daily, five minutes `[CONFIRM: who]`:

1. Any disapproved or limited ad, and any account notice.
2. Spend against the plan for the day, and any ad set that stopped delivering.
3. New enquiries arrived in the plugin and were answered within the agreed time.
4. Comments and messages on the ads: answer, hide abuse, never delete a customer's honest question. No incentive for positive comments.

Weekly review, same slot every week, one named owner `[CONFIRM: owner and slot]`:

1. Pull the table (spend, reach, frequency, link click-through rate, landing page views, `Lead`, cost per lead) by campaign, ad set and ad. Pull the same by placement and by region.
2. Pull the plugin's funnel for the same leads: how many were qualified, quoted, won, lost, and why lost.
3. Reconcile: Meta's `Lead` count against the plugin's enquiry count by UTM. A persistent gap points at consent, ad blockers, a deduplication fault or a form that is not firing the event (diagnostics, section 11).
4. Decide per ad: keep, fix or kill, using the rules in sections 7 and 8, and write the reason in the test log.
5. Check fatigue signals (section 7) and have a replacement ready.
6. Check placement and region breakdowns for waste and for evidence about the radius.
7. Check the budget formulas still hold with the latest lead-to-won rate (section 9).
8. Re-read the landing page on a phone. Does it still match the ads, and does the form work?
9. Check for creative approvals due next week and the claims ledger for any new fact.
10. Write three lines for Elie: what happened, what we are changing, what we need from the team. Do not report reach as a result.

Monthly: audience hygiene (consent records, list refresh, removal of withdrawn people), a review of excluded placements, a re-read of Meta's ad standards and the compliance guide, and a look at whether the quality loop (`tracking.md` section 9) has enough won events to start optimising toward them.

## 11. Diagnostics playbook

Work from the cheapest check to the dearest. Always check tracking before blaming the ad.

| Symptom | Likely causes, most likely first | Check | Fix |
| --- | --- | --- | --- |
| Nothing is delivering | Everything is still PAUSED. Payment method or account issue. Ad in review or rejected. Audience or radius too small | Status column, account quality notices, delivery tab | Switch on deliberately, resolve the notice, widen the radius within what Elie confirmed, merge catchments |
| Delivering but spending far below budget | Audience small, or a bid or cost cap too low (none set at launch) | Estimated audience size, delivery column | Widen the radius within limits, add a new creative, remove any cost cap |
| High cost per thousand impressions | Small audience, peak competition, narrow location | Placement and region breakdowns, audience size | Test broader location type, rotate creative, accept it if qualified leads are on target |
| Low link click-through rate | Weak first line or first frame; wrong placement for the creative | Ad-level click-through, placement breakdown | New hook variant (section 8), better first frame, check crops in each placement |
| Good click-through, few landing page views | Slow or broken page, redirect or tracking loss, in-app browser trouble | Link clicks against landing page views, page speed on a phone | Fix the page, shorten the path, check the URL and UTMs resolve |
| Landing page views but no leads | Page does not match the ad, form too long or broken on a phone, response promise unclear, enquiry route unanswered | Phone walkthrough, form submissions in the plugin, `quote_step` drop-off in analytics | Match the page to the ad, shorten the form, fix errors, make the next step plain |
| Leads in the plugin but not in Meta | No consent, event not firing, Pixel blocked, CAPI not sending, domain not verified | Test Events, Pixel Helper, consent flag, server log | Fix per `tracking.md` section 11 |
| Leads in Meta but not in the plugin | Duplicate events, event firing on a click instead of a successful submit, test traffic | Test Events, event timing against form success | Fire only on success, remove test events, check `event_id` |
| Each lead shows twice in Meta | Browser and server `event_id` or `event_name` differ, or the 48-hour deduplication window was missed | Test Events: one event with both sources, or two | Make the server create the id and the browser use it (`tracking.md` section 4) |
| Cheap leads, none qualified | Audience too broad for the offer, instant form friction too low, misleading hook, wrong catchment | Qualified rate by ad, placement and region | Sharpen the first line, add a qualifying cue on the page, exclude the failing placement or region, test an instant form with a review step |
| Qualified leads but no won orders | Quote response slow, quote not competitive `[CONFIRM: who judges]`, lead-to-won rate lower than assumed | Plugin status times, reasons lost | Fix the response process first. Revisit target cost per lead with the measured rate |
| Learning limited or no exit from learning | Too little budget or too many ad sets for the event volume | Delivery column, budget formula in section 9 | Merge ad sets, concentrate budget, hold edits |
| Ad rejected or limited | Policy: personal-attribute language, misleading claim, landing page mismatch, health-style language | The rejection reason, Meta's ad standards | Fix the cause rather than resubmitting. Appeal only if the ad truly complies. Re-run the creative checklist |
| One region or placement dominates spend with poor quality | Breakdown shows skew | Region and placement breakdown against qualified leads | Exclude the placement or tighten the radius, with a log entry |
| Frequency high, results falling | Audience saturated, creative fatigued | Section 7 signals | Rotate creative, lower the budget, or widen the radius on evidence |
| Cost per lead jumps overnight | Learning reset after an edit, a tracking fault, a seasonal competitor | Edit history, Test Events | Revert if it was an edit, fix tracking first, wait before reacting to one day |
| Enquiries arrive and nobody answers | Process gap | Plugin enquiry timestamps against first reply | Fix the process before spending another dollar. Pause the ad sets if it cannot be fixed that day |

Before declaring any ad bad: confirm that the test method in section 8 was followed, that tracking was verified, and that at least one full weekly cycle has passed.

## 12. Claims rule, compliance, and what the copy leans on

The claims rule applies to everything in `copy.csv`: no superlatives or comparatives, no ratings, no halal, organic, "fresh daily" or "authentic" claims, no prices or "from" figures, no delivery area or radius promise, no lead times, no capacity, no minimum order, no free-delivery or discount offers. A benefit is stated as what the customer gets to do ("tell us the date, headcount and dietary needs and we'll come back with a quote"). The tests also reject exclamation marks, emoji, urgency phrases, and any number that is not a typed store phone or address number.

The facts the copy still leans on, to confirm once and then record in the ledger:

1. "Dough Boss is a Lebanese bakery" (the owner's published site; also in the Google RSA copy). `[CONFIRM: ledger entry and wording]`
2. "stores in Revesby, Bankstown and Roselands Centro" and the typed addresses (typed store data, re-confirm before launch).
3. "three stores in the south-west" (EVNT-ALL-03): a store fact, not a service area. `[CONFIRM or reword]`
4. That the business will quote for catering from the form: "we'll come back with a quote" (the brief's own wording).
5. For the coming-soon ad (SOON-ALL-01): that a list exists to join, that something real is planned (the line "Something exciting is coming" is a representation about a future matter, which the Australian Consumer Law treats as misleading unless there are reasonable grounds for it), and that Elie approves the generic line. The ad says nothing about what is coming. `[CONFIRM: approval, the basis for "coming", and the landing anchor]`
6. Copy never says what is on the catering menu, because no item list for catering is confirmed.

Compliance (practical guidance from `docs/marketing/research/compliance-au.md`, not legal advice; LAWYER where flagged):

- Australian Consumer Law: no misleading claims, and every claim is substantiated. Images must show what the customer receives (`creative-brief.md` section 1).
- Spam Act 2003: an enquiry form does not create consent to marketing email or SMS. Any follow-up marketing list needs a separate unticked opt-in with the sender named and a working unsubscribe. Customer-list ad targeting is a separate matter from messaging.
- Privacy Act and the APPs: lead data minimised; the privacy policy names Meta and overseas storage; no dietary or free text in any Pixel or CAPI parameter; tags and server events respect one consent flag. LAWYER on the wording and on the small business exemption, because Dough Boss's turnover is not known to this slice.
- Meta's ad standards: no personal-attribute language, no wrongful targeting or exclusion, no health or "detox" claims. Re-read before each launch (OBSERVED via the compliance guide, https://transparency.meta.com/policies/ad-standards/, 2026-10-02).
- AANA Food and Beverages Code (best practice): the teaser speaks to adults, with no urgency or excess-consumption language.
- Page and ad transparency: the Page and ad account use the real trading name, and any "paid for by" or business disclosure shown by Meta matches the operating entity. `[CONFIRM: entity]`
- Reviews: no review gating, no incentives, no fake testimonials. This plan uses none.

## 13. Staging by a human, and the launch gate

How a person turns this draft into paused campaigns in Dough Boss's own account:

1. Confirm the ad account, Page, Instagram account and dataset belong to Dough Boss's own portfolio and the operating entity. If not, stop.
2. Create the campaign with objective Leads, special ad category NONE, ad set budgets, status paused.
3. Create each ad set from `campaigns.json`: conversion location Website, conversion event `Lead`, Advantage+ audience, location radius per store as confirmed, Advantage+ placements, status paused.
4. Create each ad from `copy.csv`: the text, headline, description and the button label (the CTA values are Meta's own: Get quote, Learn more, Contact us, Sign up; the Marketing API list I read on 2026-10-02 includes `GET_QUOTE`, `LEARN_MORE`, `CONTACT_US` and `SIGN_UP` among its values, https://developers.facebook.com/docs/marketing-api/reference/ad-creative/, and which button appears depends on the objective `[VERIFY: at staging]`).
5. Paste the exact final URL from `copy.csv`. Do not add Meta's dynamic URL macros: they produce capitals and spaces and break the convention.
6. Turn Advantage+ creative enhancements off.
7. Preview every ad in Feed, Stories and Reels. Fix crops.
8. Run the review checklist in `creative-brief.md` section 9 with a named reviewer.
9. Leave everything paused. Switch on only after the gate:
   - test lead seen end to end (`tracking.md` section 11);
   - domain verified;
   - real photography in hand and approved;
   - a named person answers enquiries within the agreed time;
   - Elie's approval of the radii, budgets and caps, each recorded.

Entity and ownership. The Page, Instagram account, ad account, dataset and domain verification all depend on who legally operates Dough Boss online. `[CONFIRM: operating entity and ABN, and who owns the business portfolio]`. This is the first blocker for staging, not a detail.

## 14. What I need from Elie

The consolidated list is in my report. In summary: the operating entity and portfolio ownership; confirmation of the facts in section 12; who answers catering enquiries and when; the radius per store and the area Elie will serve; the monthly media cap; the average catering order value, margin and a stated guess at lead-to-won rate; whether Roselands takes corporate enquiries; whether Elie approves the one generic coming-soon ad; the real photography; and the consent and privacy policy decisions in `tracking.md`.

## 15. Sources (retrieved 2026-10-02 unless stated)

Read, via a summarising fetch tool:

- https://developers.facebook.com/docs/marketing-api/conversions-api/deduplicate-pixel-and-server-events (retrieved 2026-10-02: deduplication uses event ID and name; 48-hour window)
- https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/server-event (retrieved 2026-10-02)
- https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/customer-information-parameters (retrieved 2026-10-02)
- https://developers.facebook.com/docs/marketing-api/conversions-api/using-the-api (retrieved 2026-10-02)
- https://developers.facebook.com/docs/marketing-api/audiences/guides/custom-audiences (retrieved 2026-10-02)
- https://developers.facebook.com/docs/marketing-api/audiences/special-ad-category (retrieved 2026-10-02)
- https://developers.facebook.com/docs/marketing-api/reference/ad-creative/ (retrieved 2026-10-02: call-to-action values)
- https://developers.facebook.com/docs/sharing/domain-verification (retrieved 2026-10-02, partial)
- https://www.facebook.com/business/ads-guide/image/facebook-feed/traffic (retrieved 2026-10-02: Feed image specs)

Third-party search extract, not Meta (labelled where used):

- https://www.conversios.io/blog/meta-advantage-audience-vs-detailed-targeting-2026-guide/ (retrieved 2026-10-02, search summary only)

Not readable, so no thresholds are stated: Meta's learning phase page, aggregated event measurement page, Advantage+ audience help page, Stories and Reels ad specs.

Internal evidence: `docs/marketing/research/compliance-au.md`, `local-demand.md`, `competitors.md`; `docs/wp/01-storefront-map.md`, `02-orders-square-kitchen-map.md`; `src/lib/analytics/events.ts`; `src/lib/data/catalogue.ts`; `src/content/ledger.ts`; `marketing/google-ads/*` (for naming and conversion alignment).
