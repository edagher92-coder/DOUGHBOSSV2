# Dough Boss: corporate, office and event catering outreach playbook

DRAFT for Elie. Prepared 2026-10-02 by the B2B catering sales and outbound slice. Nothing in this playbook has been sent, posted, called or submitted. Every outreach asset is a file in `marketing/outreach/`.

## Read this first

- Labels. OBSERVED means read at a source on 2026-10-02. INFERRED means my reasoning. `[CONFIRM: ...]` marks a fact or decision only Elie (or the team) can supply. Internal documents may carry `[CONFIRM]`; the customer-facing blocks in `sequence-email.md`, `linkedin.md`, `phone-script.md` and the customer part of `offer-one-pager.md` may not, and a unit test enforces that.
- Numbers rule. This playbook contains no budget, response rate, conversion rate, audience size, price or target. Where a number is needed it is a formula or a `[CONFIRM]`. The only third-party figures are health guardrails for email, cited with a source and date in section 10.
- Claims rule. The claims ledger (`src/content/ledger.ts`) holds the type and helpers only. It contains no confirmed catering claims yet. So customer-facing copy here uses only the typed store data (names, addresses, hours, phones from `src/lib/data/catalogue.ts`) plus actions the customer can take ("tell us the date, headcount and dietary needs and we'll come back with a written quote"). No prices, lead times, minimums, delivery areas, capacity, halal, "fresh", "authentic" or superlatives appear anywhere customer-facing.
- Tenant separation. Dough Boss is its own business. Nothing here uses any other business's agents, skills, accounts, tokens, data, pricing, labour rates or voice.
- Not legal advice. Items for a lawyer or ACMA are collected in section 11.
- Hosts that could not be read: `www.acma.gov.au` returned HTTP 503 to the fetch tool today (avoid-sending-spam page), so no ACMA text was freshly read. The Spam Act text below was read in the as-made version (Act No. 129 of 2003) from `https://www.legislation.gov.au/C2004A01214/asmade/2003-12-12/text/original/pdf`; it has been amended since, so a lawyer should check the current compilation.

## 1. The two tracks

### Track 1: start now on the existing WordPress site (no build needed)

These steps need only what exists today: the live `/catering/` page and enquiry form, the three shop phone numbers, and a person sending individual messages.

1. Decide the sender. One named person with a monitored inbox and phone (`{SENDER_NAME}`, `{SENDER_ROLE}`), plus the operating entity name and address for the footer. `[CONFIRM: sender, legal entity name, ABN and postal or street address for the footer]`.
2. Build a small prospect list by hand from the 13 segments in `segments.csv`, using only public business information (section 5).
3. Send touch 1 individually from a real inbox, not from a bulk tool. No email service provider is connected and none is needed to start.
4. Point every link at the live `/catering/` form with the UTM convention in section 9, until the new routes exist.
5. Log every prospect and outcome in a spreadsheet using the fields in `crm-pipeline.md`. Copy the enquiry-form leads into the same sheet each day.
6. Phone the shops' published numbers only for inbound leads; outbound calling follows `phone-script.md` and the checks in `compliance-checklist.md`.

### Track 2: when the WordPress companion plugin ships

- Corporate pages at `/catering/corporate`, `/catering/office-breakfast`, `/catering/events`, and store pages at `/locations/<store>`. Until they are live, every link falls back to `/catering/` (the interim URL). The swap is a find-and-replace on the base path; UTM parameters stay the same.
- The enquiry form should capture `guestBand`, `wantsCorporateAccount`, `source`, attribution and marketing consent. Today the live plugin does not capture UTM, consent or lead source on catering enquiries (OBSERVED in `docs/wp/02-orders-square-kitchen-map.md`, section 9). See `needsFromLead` in the report and `crm-pipeline.md`.
- Square invoice and customer sync, once the one-shop pilot is proven. Do not assume any Square feature works until the pilot says so.

## 2. Ideal customer profiles and buying roles

Evidence base: `docs/marketing/research/local-demand.md` (clusters A to J). Counts and procurement rules there are mostly not public; this playbook does not use them as claims.

### Ideal customer profile (ICP)

A workplace inside reach of one of the three shops that already buys food for a group on a repeating rhythm: weekly or monthly meetings, a team lunch, a training day, a recurring event. Repeatability matters more than size, because standing orders are the volume play. INFERRED from local-demand section 3, where the ranking criteria were closeness, repeatability and ease of a compliant first contact.

### Priority order (from `segments.csv`)

| Priority | Segments | Why |
|---|---|---|
| P1 | Bankstown CBD offices, Selems Parade professional offices, Milperra and Revesby depots | Repeatable weekday buying, role-based contacts, closest to a shop (INFERRED) |
| P2 | Business parks (Condell Park, Chullora), university and TAFE, council and library, airport precinct, Lakemba community organisations, chambers | Recurring events and institutional buying, but procurement rules are unknown |
| P3 | Hospital administration, shopping centre management, council festivals, function venues | Rules unknown or mostly competitors |

### Buying roles

| Role | What they care about (INFERRED) | What they need from us | Best ask |
|---|---|---|---|
| Office manager | Fewer things to organise; a supplier that answers | A simple way to send date, headcount, dietary needs; a written quote | "Who looks after team food orders?" |
| Executive assistant | Getting a meeting or visitor day sorted without surprises | Clear written details to forward to a manager | Date, headcount, dietary needs for the next meeting |
| People and culture | Team events, onboarding days, recognition moments | Event platters; dietary coverage in the group | A date for the next team event |
| Event coordinator | A caterer that fits a run sheet | Quote in writing, contact on the day | Event date and headcount |
| School, community or faith organiser | Trust, clear dietary information, volunteer-run | A person to talk to, plain language | A conversation about the occasion |
| Facilities or operations manager (depots, airport) | Early starts, shift teams, site access | Practical details on timing and drop-off | Breakfast for a training or toolbox day |

`[CONFIRM: delivery area, drop-off and set-up arrangements, early dispatch before 7am (Revesby is the only shop open before 7am), lead time, minimum order]` are all stakes facts and are not stated anywhere customer-facing.

## 3. The offer ladder

The ladder moves a buyer from a small, low-risk first order to a repeating one. Names come from the route contract and brief; the contents and prices of each rung are `[CONFIRM]` until the ledger confirms them.

| Rung | Offer | Route (final / interim) | Role in the ladder |
|---|---|---|---|
| 1 | Office breakfast | `/catering/office-breakfast` / `/catering/` | The entry order: one meeting, one date, easy to approve |
| 2 | Team-lunch grazing | `/catering/corporate` / `/catering/` | The second order: a regular team lunch |
| 3 | Event platters | `/catering/events` / `/catering/` | One-off events and open days |
| Volume play | Standing orders (weekly or monthly) | `/catering/corporate` / `/catering/` | Converts a happy rung 1 or 2 customer into recurring revenue |

Rules for the ladder:

- Do not announce, name or hint at any unannounced product in outreach. See `docs/site/teaser-direction.md`: no product, price, size, dietary, halal, ingredient, date or location claim about anything coming. Outreach speaks to organisers and parents, never to children, and covers only what the ledger confirms today.
- A standing order is a decision about price terms, cut-off, headcount changes and cancellation. `[CONFIRM: standing-order terms; lawyer to review the catering terms for unfair-contract-term risk, which applies to standard-form contracts with small businesses (EXTRACT, ACCC release cited in compliance-au.md section 8)]`.
- Corporate buyers often want an invoice and an account. See the Square handoff in `crm-pipeline.md`.

## 4. First-touch strategy

### Principle

One clear ask per message, small enough to answer in a line. The first email asks who looks after team food orders. It does not ask for a meeting and does not pitch. That is a deliberate choice for deliverability and for the Spam Act relevance test: a message to a role is on firmer ground when it is about that role's work, and it gets a referral if the recipient is the wrong person. Third-party guidance (Email Marketing Bible, section 14, `https://emailmarketingskill.com`, v2.7 of 8 Sep 2026) is to keep cold messages short, personalised and interest-based, send a small number per inbox per day, and treat founder-led one-to-one sends from a real inbox as better than a blast. These are third-party practices, not Dough Boss results.

### The tasting hypothesis

A low-friction tasting may lift reply and enquiry rates for corporate buyers. That is a hypothesis, not a finding, and no evidence in this repo supports it. It is a `[CONFIRM: Elie approves cost and terms]` item. No customer-facing file promises a tasting. `{OPTIONAL_TASTING_LINE}` in touch 3 stays empty until Elie approves. The decision memo is `tasting-offer.md`.

### Sequence at a glance

| Touch | Day offset (proposal) | Ask | File |
|---|---|---|---|
| 1 | Day 0 | Who looks after team food orders? | `sequence-email.md` |
| 2 | Day 4 | May I send a one-page overview? | `sequence-email.md` |
| 3 | Day 9 | Date, headcount and dietary needs for the next event | `sequence-email.md` |
| 4 | Day 16 | Closing note: say a month and I'll write then, otherwise I stop | `sequence-email.md` |
| Re-engagement | Only for people who replied or enquired and went quiet | Is there an event coming up? | `sequence-email.md` |

The day offsets are a proposal to be tuned, `[CONFIRM: Elie approves cadence]`. Total touches per recipient are capped at four for a cold prospect. A recipient who never replies hears nothing more after touch 4.

## 5. Prospecting method (public information and consented sources only)

### Allowed sources

1. The organisation's own website, contact page or published directory, where a role address or phone is conspicuously published.
2. Council, university, chamber and precinct pages that publish a business contact for a role (events, facilities, administration).
3. Business-network events the team attends in person, where a person hands over a card or opts in.
4. People who contact Dough Boss first (enquiry form, phone, walk-in).
5. A person's own opt-in to a newsletter or the "VIP first look" list, once those exist (consent separate and unticked), used only for what that opt-in covers. The "VIP first look" consent is about what is coming and early access, not catering sales, so a list member is not a catering prospect unless they also ask about catering or give catering-specific consent (Spam Act consent and APP 6 both follow the purpose the person agreed to).

### Not allowed

- Purchased, rented or scraped lists. No address-harvesting software. The Act's Part 3 prohibits supplying, acquiring or using address-harvesting software and harvested-address lists (headings OBSERVED in the as-made Act; the detailed sections were not read).
- Logins, private groups, or anything behind authentication.
- Personal addresses of private individuals, guessed addresses (for example `firstname@company`), or an address copied from a source where the publication carries a "no unsolicited commercial messages" statement.
- Bulk collection with a tool. Build lists one by one. LinkedIn also prohibits bots, scrapers and automated messaging (OBSERVED, `https://www.linkedin.com/help/linkedin/answer/a1341387`, retrieved 2026-10-02).

### What the Spam Act says about published business addresses (OBSERVED in the as-made Act text)

- Schedule 2, clause 4(1): consent "may not be inferred from the mere fact that the relevant electronic address has been published".
- Schedule 2, clause 4(2): the exception applies when the address enables the public to send messages to a particular employee, officer or office-holder, or to an individual or group performing a particular function or role in an organisation; and it has been "conspicuously published"; and it is reasonable to assume publication was with the agreement of the person (or, for a role, the organisation); and the publication is not accompanied by a statement that the account-holder does not want unsolicited commercial messages. Consent is then taken to exist "so long as the messages are relevant to" the work-related business, functions or duties, or the office, position, function or role.
- Section 17 (sender identification) was read in the compliance guide; section 18 (unsubscribe) I read directly: the message must include a statement that the recipient may use an address set out in it to unsubscribe, presented "in a clear and conspicuous manner", to an address "reasonably likely to be capable of receiving" unsubscribe messages for "at least 30 days after the message is sent".
- ACMA's "5 business days" timeframe for honouring an unsubscribe is an EXTRACT only (page unreadable today). Treat it as the outer limit and aim to action unsubscribes immediately. `[CONFIRM: ACMA current guidance]`.

### Practical rule that follows (INFERRED)

Role-based addresses (events@, facilities@, admin@, a named office-holder whose role matches catering) published conspicuously are the strongest basis. A catering message to an unrelated role (for example a generic accounts address) is weaker; do not send it. Personal-looking addresses are riskier. When in doubt, use the phone or an in-person introduction and ask permission to email.

### Evidence record (one row per prospect before anything is sent)

| Field | Content |
|---|---|
| organisation | Public organisation name |
| role | The role the address belongs to |
| address_basis | Exact URL where the address is published |
| basis_date | Date the page was read |
| relevance_note | Why catering is relevant to this role |
| no_unsolicited_notice | Yes or no: any "no unsolicited marketing" statement near the address |
| segment | From `segments.csv` |
| nearest_store | revesby, bankstown or roselands |
| checked_by | Person who checked the basis, and the date |

No evidence row, no send. A second person spot-checks a sample of rows before each batch. `[CONFIRM: who is the second checker]`.

### Phone numbers

A published landline of an office is a business number and cannot be registered on the Do Not Call Register; mobile and sole-trader numbers may be mixed-use and registered (OBSERVED, `https://www.donotcall.gov.au/consumers/faqs-for-consumers` via compliance-au.md section 5). Wash any mobile or sole-trader number against the Register before calling. Households, parent committees and personal mobiles are consumer calls: wash them.

## 6. Lead scoring

Scoring applies to every lead, inbound or outbound. It uses the two fields the enquiry form already captures (`guestBand`, `wantsCorporateAccount`) plus event type and timing. It is a rubric for prioritising the team's time, not a prediction. Numeric weights are deliberately omitted: tune the order and the tier cut-offs after the first batch of real leads, with Elie.

### Fields

- `guestBand` (from `GUEST_BANDS` in `src/types/marketing.ts`): `UP_TO_25`, `FROM_26_TO_50`, `FROM_51_TO_100`, `FROM_101_TO_250`, `OVER_250`.
- `wantsCorporateAccount` (boolean): the customer asked for an account or invoicing.
- `eventType`: `OFFICE_BREAKFAST`, `TEAM_LUNCH`, `MEETING`, `CORPORATE_EVENT` are corporate types; `COMMUNITY_RELIGIOUS`, `PARTY`, `WEDDING_ENGAGEMENT`, `OTHER` are not. Any other value in the enum is treated as not corporate and is never named in outreach.
- `eventDate`, `suburb`, `storeId`, `source`, attribution (UTM) where captured.

### Rubric (ordered, first match wins)

| Tier | Rule | Action | SLA |
|---|---|---|---|
| A (call today) | `wantsCorporateAccount` is true, or `guestBand` is `FROM_101_TO_250` or `OVER_250` | Person-to-person contact; quote prepared by a named owner | `[CONFIRM: first-response SLA]` |
| B (same day) | Corporate `eventType` with `guestBand` `FROM_26_TO_50` or `FROM_51_TO_100`, or any lead that mentions a recurring need in notes | Reply with a call or email; ask the recurrence question | `[CONFIRM: first-response SLA]` |
| C (next business day) | Corporate `eventType` with `UP_TO_25`, or non-corporate `eventType` with `FROM_26_TO_50` or above | Reply with the standard enquiry reply | `[CONFIRM: first-response SLA]` |
| D (standard) | Everything else | Standard reply; offer the store for small orders | `[CONFIRM: first-response SLA]` |

Modifiers (move one tier up): an event date inside the next few weeks `[CONFIRM: what "soon" means given the real lead time]`; a repeat buyer; a named senior decision-maker; a request for a standing order. Move one tier down: no event date and no headcount after one follow-up.

Outbound leads have no `guestBand` until the first conversation. Record it from the discovery questions in `phone-script.md` and score them with the same rubric.

## 7. Follow-up cadence

### Outbound (cold prospect)

Per section 4: touch 1 on day 0, touch 2 on day 4, touch 3 on day 9, touch 4 on day 16 (proposal). Stop on any reply, any unsubscribe or any "not relevant". A reply that says "later" gets a diary entry for the month they name, then one note in that month.

### Inbound (enquiry form)

An enquiry is a request from the customer, so a reply about that enquiry is a response to the request, not an outbound marketing sequence. Do not enrol enquiry-form leads in the outbound sequence. The live plugin captures no marketing consent on catering enquiries (OBSERVED, `docs/wp/02-orders-square-kitchen-map.md` section 9), so the right to send promotional follow-up is not established by an enquiry alone. A reasonable follow-up about the same quote is fine; a campaign needs consent captured on the form. `[CONFIRM: lawyer view on how far follow-up on an unanswered quote can go before it needs consent]`.

| Step | When (proposal) | Action |
|---|---|---|
| Reply | Per tier SLA | Answer the enquiry; ask for any missing date, headcount or dietary detail |
| Quote | After required details | Written quote; status `QUOTED` |
| Quote nudge | A few business days after a quote with no reply `[CONFIRM: interval]` | One short note about the quote, from the same thread |
| Close out | After a second silence | Mark `LOST` with a reason; stop |

### After a won order

Within a short window after the event, ask how it went and whether a standing order would suit. Ask every customer the same way, with no incentive tied to a review (Google and ACCC rules in `compliance-au.md` section 3).

## 8. Objection handling

Principle: answer with an action or a question, never with an unconfirmed fact. If the honest answer is a gap, say you will check and come back.

| Objection | Response approach | Do not say |
|---|---|---|
| "We already have a caterer." | Accept it. Ask whether they would like a quote for one date to compare, and whether anything is missing from what they have now. | That we are better, cheaper or more reliable |
| "Send me your prices." | Explain that quotes are written for the date, headcount and dietary needs; ask for those three details. `[CONFIRM: whether any indicative price may be shared]` | Any price, "from $" or a per-head figure |
| "What is your minimum order?" / "Do you deliver to us?" / "How much notice?" | Say you will confirm for their address and date, and ask for them. `[CONFIRM: minimum order, delivery area, lead time]` | Any number, radius or promise |
| "Is it halal?" | Say you will check the exact position and reply in writing; do not guess. `[CONFIRM: the exact halal position and the certifier if any]` | "Halal" in any form until confirmed |
| "We have people with allergies." | Ask for the numbers and the needs; say you will send allergen information in writing for the items quoted. `[CONFIRM: allergen matrix per item]` | "Allergen-free", "safe for" |
| "Can we try it first?" | Only if Elie approved a tasting (`tasting-offer.md`). Otherwise suggest a small order for one date. `[CONFIRM: tasting approval]` | A promise of a tasting |
| "We need an invoice or account." | Take their details; explain the next step is a written quote and an invoice. `[CONFIRM: payment terms]` | Payment terms not yet decided |
| "We buy through a panel." | Ask how a local supplier gets listed and who to contact. Note it in the CRM. | Any claim of being approved |
| "Please stop emailing." | Confirm in one line, suppress the address the same day, stop. | Anything further |

## 9. UTM convention for outreach links

Lower-case, hyphenated, no spaces.

| Parameter | Value for outreach |
|---|---|
| `utm_source` | `email`, `linkedin`, `offer-sheet` (the printed one-pager's QR code), or a directory or chamber name |
| `utm_medium` | `email` for cold email links, `organic-social` for LinkedIn, `qr` for the printed one-pager, `referral` for chamber and directory listings |
| `utm_campaign` | `<segment>-<offer>-<area>`, for example `corporate-office-breakfast-bankstown` |
| `utm_content` | the link variant, for example `touch-1-form`, `touch-3-form`, `one-pager-qr`, `linkedin-followup-1` |

Final-route example:

`https://doughboss.com.au/catering/corporate?utm_source=email&utm_medium=email&utm_campaign=corporate-office-breakfast-bankstown&utm_content=touch-3-form`

Interim example (until the corporate route exists):

`https://doughboss.com.au/catering/?utm_source=email&utm_medium=email&utm_campaign=corporate-office-breakfast-bankstown&utm_content=touch-3-form`

Attribution caveat: the live plugin does not yet capture UTM on catering enquiries (OBSERVED). Until the companion plugin does, ask "how did you hear about us" on the form and in the first reply, and record it in the CRM `source` field.

## 10. Metrics (definitions only, no targets)

| Metric | Definition | Notes |
|---|---|---|
| Prospects with evidence | Rows with a complete evidence record | Quality gate before any send |
| Touches sent | Count of outbound messages per touch number | Per segment and per sender |
| Delivered | Sent minus bounced | Bounces suppress the address |
| Reply rate | Replies of any kind divided by delivered first-touch messages | Count by segment |
| Positive reply rate | Replies that give a name, ask for the overview or give event details, divided by delivered | Judge on replies, not opens |
| Open rate | Not used for decisions: mail-privacy and summary features inflate opens (Email Marketing Bible section 6, `https://emailmarketingskill.com`, v2.7, 8 Sep 2026) | Label read-only if reported |
| Unsubscribe rate | Unsubscribes divided by delivered | Action each request immediately |
| Complaint count | Any spam complaint | Pause the sequence on any complaint and review the evidence for that address |
| Overview requests | Replies saying yes to touch 2 | Per segment |
| Enquiry rate | Enquiry-form leads and replies with date and headcount, divided by delivered | Tag outbound versus inbound |
| Tasting acceptance | Tastings accepted divided by tastings offered | Only once a tasting is approved |
| Quote rate | Leads moved to `QUOTED` divided by leads moved past `NEW` | By tier |
| Win rate | `WON` divided by `QUOTED` | By segment and offer rung |
| Time to first response | Hours from lead creation to first human reply | Against the `[CONFIRM]` SLA |
| Standing-order conversion | Won accounts that start a recurring order divided by won accounts | The volume metric |
| Revenue per account | Paid Square invoices and orders per customer over a period | Read from Square, not from the CRM |
| Cost per won account | (Sample cost + labour + tool cost) divided by won accounts | `[CONFIRM: labour and sample costs]` |

Third-party health guardrails, not targets (Email Marketing Bible section 0 and 6, `https://emailmarketingskill.com`, v2.7, 8 Sep 2026): pause sending if complaint rate is at or above 0.1 per cent; treat bounce above 3 per cent and unsubscribe above 0.5 per cent as red flags. At the scale of a hand-built list these rates will be noisy; treat any complaint as a stop-and-review.

## 11. Compliance summary and items for a lawyer or regulator

The one-page pre-send checklist is `compliance-checklist.md`. The full guide is `docs/marketing/research/compliance-au.md`. Items to take to a professional:

| Item | Who |
|---|---|
| Current Spam Act compilation and Spam Regulations; ACMA's current view of the B2B conspicuous-publication test and the unsubscribe timeframe | ACMA or a lawyer (I read the as-made 2003 Act; ACMA pages returned 503) |
| Whether LinkedIn direct messages are commercial electronic messages for the Act | Lawyer or ACMA |
| Telemarketing Industry Standard: calling hours were read from `https://www.donotcall.gov.au/consumers/consumer-overview/industry-standards/` (weekdays 9am to 8pm, Saturdays 9am to 5pm; Sunday and public holiday rules were not stated on that page); whether it binds calls to business numbers is not distinguished there | ACMA |
| Catering terms, standing-order terms, deposits and cancellation: unfair contract terms for small businesses | Lawyer |
| Whether corporate catering quotes engage the single-price rule and GST display | Lawyer |
| Gift and hospitality rules for council, university and hospital staff, if a tasting is offered to them | Lawyer, and the recipient organisation's own policy |
| Privacy policy wording, the small-business exemption and overseas disclosure for ad and email platforms | Lawyer |
| Allergen matrix and food-safety rules for off-site sampling and platters | NSW Food Authority and FSANZ |
| Exact "halal", "gluten-free", "never frozen" wording before any use | Lawyer and the certifying body or regulator |

## 12. Dependencies and open items (all `[CONFIRM]`)

1. Sender name, role, phone, monitored reply address, unsubscribe address.
2. Operating entity name, ABN and street or postal address for the footer. Brand and entity ownership is a separate open workstream; do not send until it is settled.
3. Which email address or form is the official catering contact (the live page lists a catering line and address that are not in the typed data).
4. Lead time, minimum order, delivery area, early dispatch, daily capacity.
5. Halal position, allergen matrix, any gluten-free claim.
6. Tasting: approve cost and terms, or decline.
7. Standing-order terms, payment terms, deposit policy, cancellation terms.
8. Cadence approval and SLAs.
9. Whether Square Invoices estimates and deposits are on the account's plan (Square's own pages disagree; see `crm-pipeline.md`).
10. Enquiry-form consent wording and attribution capture in the companion plugin.

## 13. Files in this slice

| File | Purpose |
|---|---|
| `marketing/outreach/segments.csv` | 13 target segments tied to the local-demand evidence |
| `marketing/outreach/sequence-email.md` | 4-touch sequence plus re-engagement note |
| `marketing/outreach/linkedin.md` | Connection notes and follow-ups, manual only |
| `marketing/outreach/phone-script.md` | Opening, discovery, gatekeeper, voicemail, close |
| `marketing/outreach/offer-one-pager.md` | Printable offer sheet plus internal checklist |
| `marketing/outreach/tasting-offer.md` | Internal decision memo |
| `marketing/outreach/crm-pipeline.md` | Stages, fields, SLAs, Square handoff |
| `marketing/outreach/compliance-checklist.md` | One-page pre-send checklist |
| `tests/unit/marketing-outreach.test.ts` | Guards for all of the above |
