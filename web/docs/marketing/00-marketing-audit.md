# Dough Boss: marketing audit (head of marketing and compliance review)

Prepared 2026-10-02 for Elie, after the four slices removed the product-specific "Minis" content (direction of 2026-10-02: the public teaser is generic). Status: internal audit. Nothing here was launched, posted, sent, submitted or bought, and no ad, profile, message or account was touched. This is practical guidance, not legal advice; items marked LAWYER or REGULATOR need that check.

Scope: every marketing deliverable as one system. That is `docs/marketing/*.md` and `docs/marketing/research/*.md`, everything under `marketing/`, `docs/site/teaser-direction.md`, and the teaser parts of `src/` (read only: `src/components/teaser/ComingSoonSection.tsx`, `src/app/actions/waitlist.ts`, `src/lib/validations.ts`, `src/lib/analytics/events.ts`, `src/lib/analytics/track.ts`, `src/lib/notify.ts`, `src/types/marketing.ts`, `src/lib/data/catalogue.ts`, `src/content/ledger.ts`).

## 1. Numbers first

| Measure | Count |
|---|---|
| Files audited | 61: 8 marketing docs (4 plans, 4 research), 38 files under `marketing/`, 1 teaser direction, 9 `src` files (teaser, waitlist, events, catalogue, ledger), 5 marketing test files |
| Findings | 26 |
| Critical | 0 (nothing is live; every ad object is paused, not created or not linked; no customer-facing copy names the unannounced product) |
| High | 4 |
| Medium | 12 |
| Low | 10 |
| Fixed in this audit | 18 |
| Left for Elie to decide | 3 findings, plus the decisions inside F02 and the open questions in section 5 |
| Reported to the owner of `src/` (app slice or lead) | 4 |
| Noted only | 1 |
| Tests | New `tests/unit/marketing-audit.test.ts` (39 cross-pack checks). Marketing tests 227 to 266, full unit suite 681 of 681 passing. No existing assertion was changed or weakened |

How "severity" is used here:

- Critical: if launched as written, it would publish a false or unlawful claim or send without consent.
- High: blocks launch, or carries real legal, platform or ownership risk.
- Medium: an inconsistency or wrong internal instruction likely to cause a wrong action.
- Low: hygiene, stale references, or a risk that only matters later.

What the audit confirmed is clean (checked by reading and, where possible, by a test):

- No customer-facing text in any pack names or hints at the unannounced product. A cross-pack test now scans 450+ customer surfaces (Google Business Profile blocks, review scripts, SEO titles, outreach blocks, the one-pager, RSA text, extensions, Meta copy and JSON-LD strings).
- No invented price, rating, review count, award, delivery area, lead time, capacity or statistic in customer copy. Third-party figures in internal docs carry a source and date. The Square facts in `marketing/outreach/crm-pipeline.md` were checked against `docs/square/capabilities-au.md` and match.
- No halal, "fresh", "authentic", "free", superlative or urgency wording in customer copy. No religious or ethnic targeting.
- Every phone number in customer copy is one of the three typed store numbers. The unconfirmed catering line 0422 487 487 appears only in internal notes.
- Every Google and Meta object is paused, not created or not linked. Every launch path needs Elie's explicit yes (Google launch checklist Part 7, Meta 03b section 13, outreach compliance checklist section 7, each GBP file, each PR pitch).
- No credential, token or private individual's data in any marketing file.
- UTM naming is consistent across packs: lower-case, hyphenated, `utm_medium` one of `cpc`, `paid-social`, `email`, `organic-social`, `referral`, `gbp`, `qr`.

## 2. Findings

Each finding gives the file, the evidence, the severity and what was done.

### High

**F01. "Official Site" headline in the Brand ad (fixed)**
- File: `marketing/google-ads/rsa.csv`, ad group `BRND | Dough Boss Brand`.
- Evidence: headline "Dough Boss Official Site". "Official" is a representation about affiliation and approval (ACL s 29(1)(h)), and entity and brand ownership is an open workstream (`docs/wp/00-architecture-extend-wordpress.md`, blockers table row 1). An advertiser whose right to the name is unsettled should not call itself the official site.
- Fix: replaced with "Visit A Dough Boss Shop" (23 characters, `char_count` updated; still 15 unique headlines). The audit test now bans "official", "endorsed", "accredited", "certified" and "approved supplier" in all customer copy.

**F02. Entity and brand ownership was not a hard gate for Google Ads (fixed as a gate; decision open)**
- File: `marketing/google-ads/launch-checklist.md`, Part 1.
- Evidence: Meta (`03b-meta-ads.md` section 13) and outreach (`04-corporate-outreach.md` section 12) already stop on this question, but the Google checklist only asked who administers the accounts. The Brand campaign bids on "dough boss".
- Fix: added a first gate: the advertiser is the entity entitled to trade as Dough Boss and use the name, site and listings; until settled nothing is built and the Brand campaign does not run. The decision itself is Elie's (section 5, question 1).

**F03. The teaser line is a statement about a future matter (mitigated; decision open)**
- Files: `src/components/teaser/ComingSoonSection.tsx` ("Something exciting is coming", "Join the VIP first-look list"), `src/lib/validations.ts` (consent text "contacting me about what's coming and early access"), `marketing/meta/copy.csv` (SOON-ALL-01).
- Evidence: Elie's own words were "maybe we have something exciting coming". Under the Australian Consumer Law a representation about a future matter is taken to be misleading unless the business had reasonable grounds for it (ACL s 4: background knowledge, not re-read today, LAWYER to confirm). The ACCC page read for the compliance guide lists "future predictions without reasonable grounds" as a breach (OBSERVED, `research/compliance-au.md` section 1). "Early access" and "VIP first look" also promise a benefit.
- Done: added this as the first launch gate on the Meta Coming Soon List campaign (`marketing/meta/campaigns.json`), to the claims list in `03b-meta-ads.md` section 12, and to `research/compliance-au.md` (section 1 note and a section 12 row for a lawyer).
- Left for Elie: confirm in writing that something real is planned before the teaser is public, and that list members will get early access. If it is only a possibility, keep the teaser off the public site and do not run the ad.

**F04. The waitlist form has no privacy collection notice, and the sender is a trading name only (reported to the app slice)**
- Files: `src/components/teaser/ComingSoonSection.tsx`, `src/lib/validations.ts` (`WAITLIST_CONSENT_TEXT`), `src/lib/notify.ts`.
- Evidence: the form collects name, email, optional mobile and store, with a separate unticked consent box (good). But no privacy notice or privacy-policy link sits at the point of collection (APP 5; the lab app has no privacy page at all), the consent names "Dough Boss" but not the legal entity that will send (Spam Act s 17; the entity is unsettled, F02), and `notifyWaitlistSignup` posts name, email and phone to `WAITLIST_WEBHOOK_URL`, a disclosure the privacy policy must cover. The WordPress architecture already blocks the plugin waitlist until `sender_legal_name` is set (`docs/wp/00-architecture-extend-wordpress.md`), which is the right model.
- Not fixed here (`src/` belongs to the app slice). Recommended: a one-line collection notice with a privacy-policy link under the form; the legal entity named once settled; say "by email or SMS" in the consent if the mobile will be used; keep the webhook pointed at a system the business controls.

### Medium

**F05. Stale counts in the Google launch checklist (fixed)**
- File: `marketing/google-ads/launch-checklist.md`, Part 5 re-count.
- Evidence: "22 ad groups, ... 136 keyword rows, 145 negative rows". The files hold 20 ad groups, 130 keywords and 141 negatives after the product campaign was removed. A person staging the account would have chased six missing ad groups.
- Fix: now "4 campaigns, 20 ad groups, ... 130 keyword rows, 141 negative rows". The audit test checks every count stated in the docs against the files (Google, Meta, outreach segments, keyword themes, citations, link targets).

**F06. Keyword theme count out of date (fixed)**
- File: `docs/marketing/research/keyword-themes.md`.
- Evidence: "68 theme rows"; `marketing/keywords.csv` now has 66.
- Fix: corrected, with a note on what changed. Tested.

**F07. Measurement plans named an event that `src` removed and missed the new ones (fixed)**
- Files: `marketing/google-ads/conversion-plan.md` (GA4-only list), `marketing/meta/tracking.md` (event map, section 12).
- Evidence: both listed `pack_size_change`, which `events.ts` no longer defines. Neither mapped `coming_soon_view`, and the Meta plan described the waitlist as a `generate_lead` form value although `events.ts` now has `waitlist_submit`.
- Fix: removed the old name; added `coming_soon_view` and `waitlist_submit` to both plans; stated that a waitlist sign-up is never a Google Ads conversion and never trains the catering campaigns. The audit test now requires every `events.ts` name to appear in both plans and rejects any unknown or removed event name.

**F08. The Meta teaser campaign used catering maths for its budget (fixed)**
- File: `marketing/meta/campaigns.json`, campaign "DB | Meta | Leads | Coming Soon List".
- Evidence: `target_cost_per_lead = avg_catering_order_value x gross_margin_pct x lead_to_won_rate x ...` was applied to waitlist sign-ups, which have no order value. Following it would have priced a sign-up off assumptions about catering, and implicitly off the unannounced product. The primary conversion also still said `generate_lead:waitlist`.
- Fix: the budget is now a fixed, time-boxed test cap that Elie sets (inputs are all `[CONFIRM: ...]`), and the conversion is `waitlist_submit` mapped to `Lead`. The existing NUMBERS RULE test still applies unchanged. `03b-meta-ads.md` section 9 says the same.

**F09. An internal marker sat inside customer-facing text (fixed)**
- File: `marketing/gbp/revesby.md`, FAQ 7 `faq-answer` block.
- Evidence: the answer began "GATED. Online pickup is available...". Copying the block would have published "GATED".
- Fix: moved the gate to the note under the block. The audit test bans `[CONFIRM`, `[VERIFY`, `TODO`, `TBC`, `GATED`, `PARKED`, `HELD` and `HOLD` in every customer surface.

**F10. Four files silently picked a side on Revesby pickup (fixed)**
- Files: `conversion-plan.md` row 4 and `tracking.md` (`begin_checkout`) said "Pickup ordering is live for Revesby"; `gbp/bankstown.md` and `gbp/roselands.md` said "Online ordering is live at Revesby only".
- Evidence: `gbp/revesby.md` and `02-seo-external.md` record the conflict: the brief says pickup is live, the live /order/ page said "Online ordering is coming soon" on 2026-10-02.
- Fix: all four now carry the gate. The audit test fails any line that says pickup or online ordering "is live" without its gate or the conflict.

**F11. The re-engagement email could go to enquiry-form leads without consent (fixed)**
- File: `marketing/outreach/sequence-email.md`, re-engagement note.
- Evidence: allowed for anyone who "sent an enquiry", with the footer "You are receiving this because you contacted ... about catering". `04-corporate-outreach.md` section 7 says an enquiry is not marketing consent, and the live form captures no consent today.
- Fix: eligibility is now limited to outbound contacts with a complete conspicuous-publication evidence record who replied, or enquirers with a recorded, separate marketing consent. `[CONFIRM: lawyer view]` kept.

**F12. The "VIP first look" list was an allowed outreach source without a scope limit (fixed)**
- File: `docs/marketing/04-corporate-outreach.md` section 5.
- Evidence: the waitlist consent covers "what's coming and early access", not catering sales. Using it for catering outreach goes beyond the consent (Spam Act) and the purpose of collection (APP 6).
- Fix: allowed only for what the opt-in covers.

**F13. Research documents were not labelled internal, and one held a product FAQ seed (fixed)**
- Files: `docs/marketing/research/keyword-themes.md`, `local-demand.md`, `compliance-au.md`, `competitors.md`.
- Evidence: `teaser-direction.md` rule 5 lets internal documents keep a working name only if they are labelled internal. None was. `keyword-themes.md` still proposed a `/catering/minis` page and a website FAQ seed "What mini food can I order for a party or kids' birthday (once Minis launches)?", which is product-specific and speaks to children's events. `local-demand.md` listed product packs as entry offers and a schools segment built on them. `compliance-au.md` named the product in checklist items used for every ad.
- Fix: an "INTERNAL RESEARCH, not customer-facing" banner on all four; the page row, cluster, offers and segment marked PARKED; the FAQ seed removed; the compliance checklist items made generic ("any new or unannounced product line"). In `compliance-au.md` the banner was merged into an existing line so the line numbers that `docs/wp/*.md` cite (for example `compliance-au.md:125`, `:131`) still point at the same text. The audit test checks the banners.

**F14. The live site already shows "Mini" publicly (left for Elie)**
- Evidence (internal, `docs/wp/01-storefront-map.md` section 3.8 and section 9; `02-seo-external.md` section 1): the live /catering/ title is "Dough Boss Catering | Mini Manoush & Pies Sydney"; the plugin hero default lists "Mini zaatar, cheese and meat manoush"; four priced catering packages are live. The teaser direction says "Minis" must not appear on anything public.
- Not touched: the live WordPress site is outside this work. The drafted replacement catering title in `marketing/wordpress/seo-plugin-checklist.md` is neutral. Internal docs quote the live wording as evidence only, outside customer blocks (the slices' choice, which this audit agrees with).
- Decision for Elie: are the existing mini manoush catering items a current product that keeps its name, or should the live wording change too?

**F15. "Roselands Centro" may be a stale centre name (flagged; decision open)**
- Evidence: the typed store name "Roselands Centro" is used in every pack. Research found the centre appears to trade as HomeCo Roselands (`research/local-demand.md` cluster F; the segments source URL says the same). `marketing/nap.json` already asks for the centre name to be re-checked.
- Done: added the `[CONFIRM]` to `marketing/gbp/roselands.md` section 1, with the rule that a rename flows from `src/lib/data/catalogue.ts` to every pack in one commit.

**F16. The GBP post calendar starts on a public holiday (fixed as a note)**
- Files: `marketing/gbp/revesby.md`, `bankstown.md`, `roselands.md`, section 8.
- Evidence: week 1 is "from Mon 5 Oct 2026", Labour Day in NSW (INFERRED from the rule that Labour Day is the first Monday in October; check https://www.nsw.gov.au/about-nsw/public-holidays). The week 1 post states opening hours, public-holiday hours are unknown, the files' own 14-day special-hours rule cannot be met, and the profiles cannot be verified by then.
- Fix: a note in all three files: the dates are a template; start the Monday after verification; never post hours on a public holiday whose hours are unknown.

### Low

**F17. "A local delivery business" (fixed).** `03a-google-ads.md` section 5 described Dough Boss as a delivery business; delivery is unconfirmed. Reworded with the `[CONFIRM: delivery area]`.

**F18. Is the live catering form live or pending? (fixed as a conflict note).** `02-seo-external.md` section 1 said the online form was "pending" (fetch summary); the code audit (`docs/wp/01-storefront-map.md` section 9) records a live form, and `03b` and `04` assume it is live. Both views are now stated; check it in a browser before relying on it.

**F19. Stale "file not present" notes (fixed).** `tracking.md` section 10 and `conversion-plan.md` sources said `docs/square/capabilities-au.md` did not exist. It now does. Notes updated to reconcile against it.

**F20. One-pager QR and UTM source (fixed).** `marketing/outreach/offer-one-pager.md` prints the final `/catering/corporate` link, which is not live yet; a printed QR cannot change. The print rule now says to print the interim `/catering/` link until the route returns 200. `offer-sheet` (its `utm_source`) added to the UTM table in `04` section 9.

**F21. Shot code gap (fixed).** `marketing/meta/creative-brief.md` jumped from P4 to P6 after the product shot was removed. P5 is now listed as retired so older references still line up.

**F22. Two ways to count one waitlist sign-up (reported to the app slice).** `events.ts` still allows `generate_lead` with `form: "waitlist"` beside `waitlist_submit`. Nothing fires it today. Recommended: remove the value, so one sign-up is one event.

**F23. Lab catalogue text makes claims the ledger has not confirmed (reported).** `src/lib/data/catalogue.ts` blurbs and descriptions say "fresh-baked", "fresh from the oven", "oven-hot", "Stone-baked", "Artisan stone-baked pizzas", and a category is named "Artisan Pies". The marketing packs avoid all of these (the SEO banned list includes them), but the lab app would show them if it went public.

**F24. Dormant product strings in `src/lib/packs.ts` (reported).** "Mini Za'atar", "Mini Pies" and similar remain in the dormant pack library, marked internal-only by the app slice. Recommended: a test that no route imports it.

**F25. Review SMS sender details (noted).** `marketing/reviews/request-scripts.md` identifies the sender as "Dough Boss {{store_name}}" with "Reply STOP". Confirm the SMS tool sends from a number that receives replies, or add a contact route (Spam Act s 17 and 18). REGULATOR (ACMA) for the current SMS rules.

**F26. Ledger proposals did not cover item descriptions (fixed).** The GBP week 2 posts and services describe items ("za'atar and olive oil on dough", "a spiced minced-meat topping on dough"), from `catalogue.ts`, but section 11 of each GBP file proposed only an `item-names` claim. Added an `item-descriptions` row to all three, for Elie to confirm.

### Proposed additions to `docs/site/teaser-direction.md` (owner: the lead; not edited here)

1. Rule 1: only publish "something is coming" when a real plan exists (F03), and use "early access" only if list members will get it.
2. Rule 4: every form that collects details shows a privacy collection notice and links the privacy policy; the sender is the settled legal entity (F02, F04).
3. Rule 4: the list's consent covers what is coming; it is not a catering or outreach list (F12).
4. A note that the live site's existing "mini manoush" wording needs Elie's call (F14).

## 3. What changed, file by file

| File | Change |
|---|---|
| `marketing/google-ads/rsa.csv` | F01 headline replaced |
| `marketing/google-ads/launch-checklist.md` | F02 entity gate; F05 counts |
| `marketing/google-ads/conversion-plan.md` | F07 events; F10 pickup gate; F19 note |
| `marketing/meta/campaigns.json` | F03 launch gates; F08 budget and conversion |
| `marketing/meta/tracking.md` | F07 events; F10 pickup gate; F19 note |
| `marketing/meta/creative-brief.md` | F21 |
| `marketing/gbp/revesby.md` | F09; F16; F26 |
| `marketing/gbp/bankstown.md` | F10; F16; F26 |
| `marketing/gbp/roselands.md` | F10; F15; F16; F26 |
| `marketing/outreach/sequence-email.md` | F11 |
| `marketing/outreach/offer-one-pager.md` | F20 (internal print rule only) |
| `docs/marketing/02-seo-external.md` | F18 (edited in place, no line shift) |
| `docs/marketing/03a-google-ads.md` | F17 (edited in place, no line shift) |
| `docs/marketing/03b-meta-ads.md` | F03; F08 |
| `docs/marketing/04-corporate-outreach.md` | F12; F20 |
| `docs/marketing/research/*.md` | F06; F13 |
| `tests/unit/marketing-audit.test.ts` | New: 39 cross-pack checks |

Test evidence: `npx vitest run tests/unit/marketing-*.test.ts` gives 5 files, 266 tests passed; `npx vitest run` gives 23 files, 681 tests passed; `npx eslint tests/unit/marketing-audit.test.ts` is clean; every edited JSON file parses. To show the new test bites, seven defects were re-introduced one at a time (the old count, "GATED" in the FAQ, "Official Site", a product name in the Meta teaser, the removed event name, the catering phone in a GBP post, the old theme count). Each one failed the test, and each file was then restored.

## 4. Launch-readiness checklist (in order; nothing is spent or sent until every line is true)

1. **Ownership.** The entity and brand-ownership question is settled in writing: who may trade as Dough Boss and who owns the domain, the site, the three Business Profiles, the Square merchant account, Google Ads, GA4, Tag Manager, the Meta business portfolio, Page, Instagram, ad account and dataset. The legal entity name and ABN are recorded for every sender block.
2. **The teaser is true.** Elie confirms what is planned (a dated record), approves the generic line, and confirms early access is real. Otherwise the teaser stays off the public site and the teaser ad stays parked.
3. **Privacy.** A privacy policy is live and names the data collected, the purposes, the platforms (Google, Meta, the email or SMS tool, the waitlist webhook), overseas storage and hashed sharing for ad measurement. Every form (waitlist, catering enquiry, review opt-in) shows a collection notice and links to it. LAWYER on the wording and the small-business exemption.
4. **Consent records.** Separate, unticked boxes for marketing, review requests and ad measurement, each stored with time and wording. A monitored unsubscribe address that works for at least 30 days after each send, and a suppression list that applies across channels.
5. **Claims ledger.** Every fact the copy leans on is in `src/content/ledger.ts` as confirmed with a source: "Lebanese bakery", the menu item names and descriptions, the catering enquiry and quote wording, the allergen statement, each store's address, hours and phone, and "three stores in the south-west". Anything not confirmed comes out of the copy, it is not softened.
6. **NAP decisions.** Address form (Rd or Road, Shop 12/25 or 12/25), the Roselands centre name, signage wording and the catering phone line are decided; `catalogue.ts`, `nap.json` and every pack change in one commit; tests green.
7. **Operations facts.** Who answers enquiries and calls, and when; the response time; public-holiday hours (Labour Day, 5 October 2026, is the first); Revesby pickup confirmed by a real test order before any pickup post, FAQ or conversion goes live.
8. **Landing pages.** Every URL an ad, post, profile, email or QR code uses returns 200 on a phone, shows the form and consent text, and matches the ad. Until the new routes exist, use the interim `/catering/` page.
9. **Tracking proven end to end.** Tags fire only after consent; the catering lead fires `generate_lead` with `form = catering_enquiry` once, the waitlist fires `waitlist_submit` only, and neither carries personal data. A test lead is seen in the plugin (with UTMs and click ids), GA4, Google Ads and Meta Test Events, and browser and server events deduplicate.
10. **Real creative.** Real photographs only, approved, with releases; no AI renders, stock or children's faces; the teaser graphic shows no food and no hint of the product.
11. **Money set by Elie.** Monthly media cap, learning budget, max CPC, radii, the teaser test cap and its end date, all written down. No formula input is guessed.
12. **Lawyer and regulator items cleared** or consciously accepted in writing: current Spam Act compilation and ACMA's B2B test, privacy wording, future-matter wording of the teaser, catering terms (unfair contract terms), allergen and any gluten-free wording, and the Telemarketing Industry Standard before any calling.
13. **Build paused and reviewed.** Accounts built in draft or paused from the files; `npx vitest run` green; a second person runs the creative, outreach and pre-publication checklists.
14. **Elie's explicit yes, per launch.** Google Part 7 sign-off, Meta section 13 gate, each outreach batch, each GBP profile before publishing, each PR pitch. Enable Google wave 1 only, one change at a time.
15. **First seven days.** Daily search-term, lead and complaint checks; pause at once on any stop condition (tracking stops, a claim-related disapproval, unanswered leads, overspend without leads, a complaint about accuracy).

## 5. Open questions for Elie

1. Which legal entity owns and operates Dough Boss, and may it use the name, site and listings? (F02; blocks everything.)
2. Is something real planned behind "Something exciting is coming", and will list members get early access? (F03)
3. Do you approve the one generic teaser ad (Meta SOON-ALL-01, held), and should it land on `/` or on the `#coming-soon` section the lab app now uses? (If the anchor is used, the Meta route list and `copy.csv` change together.)
4. Do you want any generic Google teaser line? None was built, per `teaser-direction.md` rule 6.
5. Do the existing "mini manoush" catering items on the live site keep that name? (F14)
6. What is the current centre name at Roselands, and what does the shop sign say? (F15)
7. Is online pickup live at Revesby today? A test order settles it. (F10)
8. Who answers catering enquiries and calls, when, and how fast?
9. Which phone number is the official catering line (0422 487 487 is on the live page but not in the store data)?
10. Delivery: is it offered, to which suburbs, and at what fee? This sets every ad radius.
11. Public-holiday trading hours for each shop, starting with Labour Day.
12. Address forms for the listings, and the exact signage wording per shop.
13. The facts to confirm for the ledger (section 4, item 5), including halal status and any certifier.
14. Budgets: monthly cap, learning budget, max CPC, the teaser test cap.
15. Does Roselands take corporate catering enquiries (the Meta Roselands ad set is held on this)?
16. Tasting offer: approve cost and terms, or decline.
17. Who sends outreach, from which inbox and domain, and with which legal footer?
