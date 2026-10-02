# Meta audiences and targeting, DRAFT

Prepared 2026-10-02 for Elie. Internal planning document, so it carries `[CONFIRM: ...]` and `[VERIFY: ...]` items. Nothing here exists in any Meta account. Companion to `docs/marketing/03b-meta-ads.md` and `marketing/meta/campaigns.json`.

Labels: OBSERVED means I read it at the cited URL on 2026-10-02 (through a fetch tool that summarises pages with a small model). INFERRED is my reasoning. `[VERIFY: ...]` means check it in Ads Manager or the docs before relying on it.

Evidence limit, stated plainly: I could not open Meta's own Business Help pages for Advantage+ audience (404 or title only). The description of how Advantage+ audience treats suggestions below comes from third-party summaries in a search result and from the Marketing API vocabulary, so it is labelled `[VERIFY]` and must be re-read in Ads Manager at staging.

## 1. The approach in five lines

1. Start broad. Use Advantage+ audience with the location as a hard limit, and let strong creative and the Lead signal find the buyers.
2. Treat job role, industry and interest options as suggestions to Meta, never as a filter we rely on.
3. Exclude people who have already enquired, so spend goes to new buyers.
4. Customer lists only from consented leads, hashed, with the privacy policy naming Meta.
5. Lookalikes wait until there are enough qualified leads to be a good seed. Retargeting waits until the consented pixel audience is big enough to deliver.

## 2. What Meta currently treats as a hard limit and what as a suggestion

[VERIFY: confirm every line in Ads Manager at staging, because this changes.]

What a search summary of Meta's current behaviour says (third-party, not read at Meta's own page): with Advantage+ audience on, location, minimum age, language and excluded custom audiences stay as hard controls; age range, gender, detailed targeting, custom audiences and lookalikes are treated as suggestions that guide delivery first, and Meta may show ads to people outside them if it predicts better results.

Consequences for Dough Boss, INFERRED:

- A radius around a store really does limit who sees the ad. That is the control that matters for a local business, so we use it deliberately (section 3).
- A job-title or interest "target" will not restrict an audience to office managers. Do not write the plan, or the expectations, as if it does.
- Because the platform may go beyond our suggestions, the creative has to do the filtering. The first line of each ad says who it is for ("Booking breakfast for the office?", "Training day or board meeting coming up?"). That line is the audience filter.
- Meta also removed or limited many detailed targeting options over time. The picker, not this document, is the source of truth for what exists today. Every interest named below is "check it is offered", never an instruction.

## 3. Geographic targeting around the three stores

All radii are `[CONFIRM: radius]`. They are a hypothesis tied to the area Elie is willing to serve, not a delivery promise and never copy. Begin no wider than the area Elie confirms, and widen only when the placement and region breakdowns show leads and qualified leads from outside it.

| Store | Typed address | Centre of the area | Radius | Suburb hypothesis (demand ideas, not a service area) | Source for the hypothesis |
| --- | --- | --- | --- | --- | --- |
| Bankstown | 462 Chapel Rd, Bankstown NSW 2200 | The store | `[CONFIRM: radius]` | Bankstown, Chullora, Condell Park, Yagoona | Council ward pages and demand clusters in `docs/marketing/research/local-demand.md` (retrieved 2026-10-02) |
| Revesby | 12/25 Selems Parade, Revesby NSW 2212 | The store | `[CONFIRM: radius]` | Revesby, Padstow, Milperra, Panania | Same |
| Roselands Centro | Shop MM03, Roselands Dr, Roselands NSW 2196 | The store | `[CONFIRM: radius]` | Roselands, Lakemba | Same. Held until corporate enquiries from this store are confirmed. |

Location type: `[CONFIRM: living in this location versus living in or recently in this location]`. Hypothesis, INFERRED: for office and depot buyers, "living in or recently in" catches the person who works in the area but lives elsewhere, which is exactly the buyer who books the team lunch. For a party-organiser audience, "living in" is the cleaner fit. Test both only if the volume allows, and change one thing at a time (03b section 8).

Overlap: the Bankstown, Revesby and Roselands radii may overlap. Overlapping ad sets compete with themselves in the auction. Check overlap in Ads Manager at staging [VERIFY: audience overlap tool availability]. If two catchments overlap heavily, merge them into one ad set and split the creative instead.

Do not use the store names as a promise of coverage. Copy says where a store is, never where Dough Boss will go.

## 4. Prospecting audiences (broad plus suggestions)

### 4.1 Corporate office and team (campaign "DB | Meta | Leads | Corporate Office")

- Mode: Advantage+ audience, location as the hard control.
- Suggestions only, chosen from what the picker offers `[CONFIRM: picker options]`:
  - Job roles and functions close to buying team food: office and facilities management, executive and personal assistants, event and people-and-culture roles, operations and site managers.
  - Industries that fit the local employer base in `docs/marketing/research/local-demand.md`: logistics and warehousing, manufacturing, trades and construction, education and public administration, health administration.
  - Interests around catering and workplace events.
- Suggested minimum age: `[CONFIRM: minimum age]`, working-age hypothesis, adults only.
- No suggestion may reference religion, ethnicity, health, finances or any personal attribute. Meta's ad standards bar asserting or implying personal attributes and wrongly targeting or excluding groups (OBSERVED, https://transparency.meta.com/policies/ad-standards/, via `docs/marketing/research/compliance-au.md` section 10).
- Honest limit: Meta has no reliable employer or job-title field for everyone. This audience will include people who are not buyers. Judge it on qualified leads (03b section 10), not on reach.

### 4.2 Event and party catering (campaign "DB | Meta | Leads | Events and Parties")

- Mode: Advantage+ audience, location as the hard control, three store areas in one ad set to keep the budget in one learning pool.
- Suggestions only: interests around parties, events, community groups and school or club organising `[CONFIRM: picker options]`.
- Adults only. Speak to organisers. No creative aimed at children (AANA Food and Beverages Code defines children as under 15 and restricts targeting occasional foods at them; EXTRACT only, https://aana.com.au/self-regulation/food-and-beverages-code/ via the compliance guide).

### 4.3 Coming-soon list (HELD, generic awareness only)

- Mode: Advantage+ audience, location as the hard control.
- Suggestions only: adults living near the three stores. No product interest, no food-category interest, no interest built around what is coming (docs/site/teaser-direction.md rule 6). The ad speaks to adults and never to a child.
- Minimum age hard control set to an adult age `[CONFIRM: minimum age]`.
- No urgency, scarcity or excess-consumption language. No cartoon-led creative aimed at children.
- Launch gate: Elie's approval of the generic teaser ad, and the teaser page live with a separate, unticked consent checkbox. No product claim of any kind.

## 5. Customer lists (consented leads only)

A customer list audience (also called a customer file custom audience) lets Meta match our own contacts to accounts. For Dough Boss it has two uses: exclude people who have already enquired (so spend goes to new buyers), and, later, seed a lookalike or a retargeting message. Both are optional, and neither is needed to launch.

What Meta requires, OBSERVED 2026-10-02 at https://developers.facebook.com/docs/marketing-api/audiences/guides/custom-audiences (via summarising fetch):

- Data must be hashed with SHA-256. "We don't support other hashing mechanisms."
- Data must be normalised before hashing (for example lower-case email, phone without symbols).
- The advertiser must accept Meta's terms for custom audiences for the business the account acts for, and is responsible for the data as the owner.

What Australian law and Meta's terms require us to add, INFERRED from `docs/marketing/research/compliance-au.md` sections 6 and 10 (practical guidance, not legal advice; LAWYER to check wording):

1. Only people who gave their details to Dough Boss as a customer or enquirer, in a setting where they would reasonably expect an ad platform use, and where the privacy policy names Meta and says some processing is overseas. `[CONFIRM: privacy policy published and current]`.
2. Never purchased, scraped, rented or third-party lists. Never a list of people Dough Boss only guessed at, such as published business emails.
3. Never upload dietary information, event notes, free text, or anything that implies a health, religious or other sensitive attribute. The upload carries only hashed email and phone (and, if wanted, first name, last name and postcode, hashed as Meta specifies).
4. A person who withdraws consent or asks to be erased is removed from the list at the next refresh. Each upload is a snapshot, so a refresh is a delete as well as an add. `[CONFIRM: who runs the refresh, and how often]`.
5. The list is built from the plugin's consent record, not from a spreadsheet someone remembers. Today the catering enquiry form captures no consent at all (`docs/wp/02-orders-square-kitchen-map.md`), so no list can be uploaded until the form records consent (tracking.md section 7).
6. Hashing happens inside Dough Boss's own systems or the Ads Manager uploader, not by emailing a spreadsheet to a third party. Do not use a third-party list-upload tool.
7. Retention: delete the working file once the audience is created. Keep only the hashed consent record in the plugin. `[CONFIRM: retention period]`.
8. Minimum list size to build an audience and to deliver is `[VERIFY: Meta's current minimum]`. A tiny list will not work and that is not a fault.
9. Spam Act: this is advertising targeting, not a message to the person. It does not create consent to email or SMS them. Keep that separate (tracking.md section 7).

Operating rule: the first customer list Dough Boss builds is the exclusion list, because it protects spend and asks nothing new of anyone.

## 6. Website and engagement audiences

- Website visitors (pixel): visitors to `/catering` and `/catering/*` in the last `[CONFIRM: window]` days, built only from the consent-gated pixel. Needs a deliverable audience size `[VERIFY: Meta's minimum]`. Local B2B volumes may be too small for a long time. Do not buy reach to fill it; widen the window (within what the privacy policy says) or wait.
- Exclusion version: anyone who fired `Lead` in the last `[CONFIRM: window]` days. Use it as an exclusion on prospecting ad sets and as the stop rule on the warm campaign.
- Page and Instagram engagers: possible warm audience from real organic activity. Do not boost posts to inflate it.
- No retargeting of store-ordering visitors with catering ads unless the consent covers it. Different intent, different message.

## 7. Lookalikes: not at launch

Meta now folds lookalikes into Advantage+ audience as suggestions `[VERIFY]`. If used later: the seed is qualified leads and won orders (the plugin statuses `quoted`, `confirmed`, `deposit_paid`, `paid`), never all form fills, and only once the seed is large enough for Meta to use `[VERIFY: Meta's minimum seed size]`. A lookalike built from poor leads teaches the account to find more poor leads.

## 8. Exclusions

- Hard exclusions: existing catering enquirers (section 5, once it exists).
- Do not exclude by religion, ethnicity or any personal attribute. Do not use language, ethnicity-coded interests or names as proxies.
- Do not exclude suburbs on assumptions about the people who live there. Exclude on evidence: if the region breakdown shows leads from an area are consistently unqualified for a reason we can state (for example outside what the kitchen can reach), tighten the radius instead.
- Placement exclusions are a creative and quality choice, not an audience one, and are covered in 03b section 4.

## 9. Naming

`<CODE> | <Area or audience>` for ad sets (`CORP | Bankstown catchment`, `EVNT | Three-store catchment`, `SOON | Three-store catchment`, `WARM | Catering page visitors`). Custom audiences: `DB | CA | <source> | <window>` and `DB | EX | <what is excluded>`. Every audience records its source, consent basis, creation date and owner in a short note beside the list.

## 10. Audience review checklist (before staging)

- [ ] Location is the only hard control we rely on.
- [ ] No suggestion references religion, ethnicity, health, finances or any personal attribute.
- [ ] Teaser and event audiences are adults.
- [ ] No customer list is uploaded without recorded consent and a policy that names Meta.
- [ ] Every list is hashed with SHA-256 and carries no dietary or free-text data.
- [ ] The exclusion list exists, or the ad set launches without it and the gap is noted.
- [ ] No lookalike or retargeting audience is live before its seed or size gate is met.
- [ ] Every radius is a confirmed number, no longer a hypothesis, before the ad set is switched on.
