# DoughBoss universal brand copy replacements

**Decision:** DoughBoss should lead with flavour, freshness, generosity and
Mediterranean food rather than define the brand as a “Lebanese bakery”.
Lebanese heritage and halal suitability can remain as supporting product and
dietary information, but neither should imply that the experience is intended
for only one community.

## Immediate screenshot fix

In `demo/franchise.html`, replace:

> **100%**
> halal Lebanese bakery

with:

> **Fresh**
> baked to order

This keeps the fourth proof point useful without presenting ethnicity or halal
status as a percentage statistic.

## Franchise-page replacements

| Current | Replacement |
|---|---|
| “A proven Lebanese bakery brand” | “A proven fresh-baked food brand” |
| “south-west Sydney’s Lebanese bakery since 2009” | “fresh-baked favourites from south-west Sydney since 2009” |
| “A proven Lebanese bakery brand — … halal” | “A proven Mediterranean food brand — in the industry since 2009, with three Sydney shops, fresh food made to order, commission-free online ordering and POS built in.” |
| “A real, trading Lebanese bakery” | “A real, established food business” |
| “Halal & consistent” | “Quality & consistency” |
| “Tight supplier standards and recipes…” | “Clear ingredient standards and proven recipes so every Dough Boss tastes like a Dough Boss.” |

Halal suitability should remain available in menu filters, item information,
FAQs and catering conversations where it helps customers make a dietary
decision.

## Homepage and social metadata

| Surface | Replacement direction |
|---|---|
| Description | “Dough Boss — stone-baked manoush, pizza, pies, wraps and catering, made fresh since 2009.” |
| Open Graph title | “Dough Boss — Manoush, Pizza & Catering” |
| Open Graph/Twitter description | “Stone-baked manoush, pizza, pies and wraps, made fresh to order. Pickup from Revesby.” |
| Image alt | “Dough Boss stone-baked manoush and pizza” |
| Hero lead | “In the industry since 2009 — serving fresh manoush, pizza and pies, baked to order with Mediterranean flavour and a modern twist.” |
| About lead | “Dough Boss brings generations of dough-making know-how together with a modern, generous approach…” |
| Location note | “A Sydney favourite since 2009 · halal options · baked fresh daily.” |
| Footer | “Dough Boss — stone-baked since 2009” |

## Legal and agreement descriptions

Privacy, Terms and licensing documents can use the neutral factual description:

> Dough Boss is a bakery and food business specialising in manoush, pizza,
> pies, wraps, desserts and related products.

This changes positioning only. It must not change the legal entity, registered
business details, product disclosures, halal representations or substantive
agreement terms without owner/legal review.

## Acceptance search

Before publishing, this command should return no public-facing brand-positioning
matches:

`rg -n -i "Lebanese bakery|halal Lebanese|100% halal" demo -g "*.html" -g "*.js" -g "*.css"`

Any intentionally retained heritage reference should be reviewed in context and
must read as provenance, not an audience restriction.
