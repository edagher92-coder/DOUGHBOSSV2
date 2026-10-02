# Tasting offer: internal decision memo

INTERNAL. Not customer copy. DRAFT for Elie. Prepared 2026-10-02.

## Decision needed

`[CONFIRM: Elie approves cost and terms]` for any tasting offered to a corporate prospect. Until then no customer-facing file mentions a tasting, and the `{OPTIONAL_TASTING_LINE}` slot in touch 3 of `sequence-email.md` stays empty.

## The hypothesis

A low-friction tasting may lift the share of corporate prospects who reply and then enquire, because it lowers the risk of a first order for an office manager who has to answer for the choice. That is a hypothesis. No data in this repo supports or refutes it, and no benchmark is cited. It is cheap to test in a small way, and cheap to drop.

## Options

| Option | What it is | Cost drivers | Main risks |
|---|---|---|---|
| A. Sample box at their workplace | A small box of items left at a business that said yes to the idea | Food, packaging, transport, staff time | Food-safety handling and allergen labelling off-site; gift and hospitality policies at public bodies |
| B. Tasting at a shop | Contact comes to the shop at a time agreed | Food, staff time | Shop capacity at busy times; hard to schedule for office managers |
| C. Small paid trial order | A first order for one meeting, with no sampling | None beyond a normal order | Weaker pull; not a tasting |
| D. No tasting | Rely on the written quote and a reference to the shop | None | Lower pull for a first-time buyer (hypothesis) |

Recommendation (INFERRED, not evidence): test C and D first because they need no approval of cost, and decide on A or B only if replies show prospects asking to try the food. Elie decides.

## Cost model (formula only; no numbers invented)

- Cost per tasting = food cost per person x people tasting + packaging + transport + staff time x hourly cost. `[CONFIRM: each input]`
- Cost per won account = total tasting cost in a period divided by accounts won from tastings in that period.
- Break-even test = cost per won account compared with the gross margin from an average first-year account. `[CONFIRM: average order value and gross margin; both come from Square sales and Elie, not from this memo]`
- Set a monthly spend ceiling before starting. `[CONFIRM: ceiling]`

## Terms to settle before any offer

1. Who may be offered one (segments and roles), and how many per organisation.
2. What is offered, in what quantity, and how it is described. A tasting that is described as "free" is a representation under the Australian Consumer Law and a Google Ads policy matter if it ever reaches an ad; it must be true and its terms stated plainly. `[CONFIRM: wording, lawyer]`
3. Whether any conditions attach. None may attach to a review: Google's policy and the ACCC bar incentives for reviews (OBSERVED, `docs/marketing/research/compliance-au.md` section 3).
4. Food safety: how samples are prepared, labelled, kept at temperature and transported; who is trained; allergen information per item. `[CONFIRM: NSW Food Authority and council requirements for off-site sampling]`
5. Allergens: every sample carries the Food Standards Code allergen names. Do not offer a sample for "allergy-safe" purposes. `[CONFIRM: allergen matrix]`
6. Gifts and hospitality at public bodies. Council, university, hospital and TAFE staff may be barred from accepting gifts or hospitality, or must declare them. Check each organisation's policy before offering to staff there. `[CONFIRM: lawyer, and each organisation's own policy]`
7. Consent. A message offering a tasting is a commercial electronic message under the Spam Act; it needs the same consent basis, sender identification and unsubscribe as any other outreach (see `sequence-email.md`).
8. Records: who was offered, who accepted, what was sent, and what happened next, in the CRM (`TASTING_OFFERED` stage).
9. Stop rule: agree in advance how many tastings are tried before the team reviews, and what result would stop it. `[CONFIRM: review point; no number is set here]`

## Wording to use only after approval

If Elie approves, the line below can go in the `{OPTIONAL_TASTING_LINE}` slot of touch 3. It is shown here, in an internal memo, so it can be checked against the approved terms first. It must not be copied into a customer-facing file until the terms are confirmed and the unit tests are re-run.

> `[CONFIRM: approved wording; draft only] If it would help your team decide, ask me about trying some items before you order.`

The draft deliberately promises nothing about cost, quantity, timing or who qualifies.

## What to measure if a tasting is approved

- Tastings offered, accepted, and completed.
- Enquiries within a set window after a tasting, and quotes issued.
- Accounts won and standing orders started from tasting contacts, against the same segments without a tasting.
- Cost per won account (formula above).
- Any complaint, allergen incident or policy issue (stop immediately and review).

The comparison group is the same segment contacted without a tasting. Do not read a result as proof from a handful of contacts. `[CONFIRM: how many contacts Elie wants before judging]`

## Not in scope

No tasting is being offered, scheduled, promised or costed by this memo.
