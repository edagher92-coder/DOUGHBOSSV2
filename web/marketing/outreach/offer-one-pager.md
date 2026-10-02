# Offer one-pager: corporate catering enquiries

DRAFT. Not printed, not sent. This file has two parts. Everything above the heading "Internal checklist" is the customer-facing sheet and is the only part that may be printed or emailed. The unit test checks the customer-facing part against the banned-terms list and fails on any `[CONFIRM` marker in it. The internal checklist below it is for Elie and the team.

Layout note for whoever prints it: A4 portrait, one page, black text on white, the shop details in a three-column table, and a QR code that points to the enquiry link below. No photos of food unless the photo and every claim in it have been confirmed.

---

# Catering enquiries at Dough Boss

For offices, meetings and events.

## How an enquiry works

1. Tell us what you are planning: the date, the number of people, and where it is happening.
2. Tell us about any dietary needs in your group, so the quote can reflect them.
3. We come back with a written quote. You can pass it to whoever approves it.

If your team orders regularly, tell us, and we can talk about a regular order. If your organisation needs an invoice or an account, tell us that too.

## What to have ready

- The date and the time you need it.
- The number of people.
- The address where it is happening.
- Any dietary needs, as numbers where you can (for example, how many people need each).
- The name and phone number of the person we should speak to.

## Where to send your details

Online: https://doughboss.com.au/catering/corporate?utm_source=offer-sheet&utm_medium=qr&utm_campaign=corporate-office-breakfast-bankstown&utm_content=one-pager-qr

Or phone the shop nearest to you.

## Our shops

| Shop | Address | Phone | Shop opening hours |
|---|---|---|---|
| Revesby | 12/25 Selems Parade, Revesby NSW 2212 | (02) 9774 2286 | 7 days, 6:30am to 2:30pm |
| Bankstown | 462 Chapel Rd, Little Saigon Plaza, cnr Kitchener Pde and French Ave, Bankstown NSW 2200 | (02) 8764 6783 | Monday to Friday, 7:00am to 2:00pm |
| Roselands Centro | Shop MM03, Roselands Dr, Roselands NSW 2196 | 0466 353 133 | Daily, 8:00am to 3:00pm |

The opening hours above are the shops' trading hours. Tell us what time you need your order and we will confirm it in writing.

{BUSINESS_NAME}. {BUSINESS_LEGAL_ENTITY_AND_ABN}. {BUSINESS_ADDRESS}.

## Internal checklist

Not for customers. Every item is a `[CONFIRM]` until Elie or the ledger confirms it. Nothing below may move into the customer-facing sheet until it is a confirmed, sourced claim in `src/content/ledger.ts`.

### Facts the sheet deliberately does not state

| Item | Why it is left out | Who confirms |
|---|---|---|
| `[CONFIRM: what is on offer at each rung (office breakfast, team lunch, event platters)]` | No catering claim is confirmed in the ledger | Elie |
| `[CONFIRM: any price, per-head figure or "from" figure]` | The owner's site labels its package prices INDICATIVE; the ACL single-price rule may apply to corporate quotes | Elie, lawyer |
| `[CONFIRM: minimum order]` | Stakes fact | Elie |
| `[CONFIRM: delivery area, delivery fee, drop-off and set-up]` | Stakes fact; a delivery promise needs a defined area | Elie |
| `[CONFIRM: lead time and early-morning dispatch before 7am]` | Stakes fact; Revesby opens at 6:30am, the others at 7am or 8am | Elie |
| `[CONFIRM: daily capacity and maximum event size]` | Stakes fact | Elie |
| `[CONFIRM: halal position and certifier, if any]` | Process claim; consumer-law risk | Elie, certifier |
| `[CONFIRM: allergen matrix for each catering item, using the Food Standards Code allergen names]` | Must exist before any dietary claim | Elie, NSW Food Authority |
| `[CONFIRM: "baked in-house", "never frozen", "fresh"]` | Absolute claims; one counterexample breaks them | Elie |
| `[CONFIRM: descriptor such as "Lebanese bakery" for the sheet header]` | Not a confirmed ledger claim | Elie |
| `[CONFIRM: payment terms, deposit, cancellation, standing-order terms]` | Standard-form terms need a lawyer's review (unfair contract terms) | Elie, lawyer |
| `[CONFIRM: tasting offer]` | Not approved; see `tasting-offer.md` | Elie |
| `[CONFIRM: Minis]` | Not launched; children's advertising code applies to marketing | Elie |

### Sheet facts and where they come from

| Fact on the sheet | Source |
|---|---|
| Shop names, addresses, phones, hours | `src/lib/data/catalogue.ts` (typed store data, provenance in that file, copied from the owner's live locations page; re-confirm before launch; public-holiday trading is not listed) |
| Enquiry link and route | Route contract: `/catering/corporate` final, `/catering/` interim |
| The process in "How an enquiry works" | The brief's permitted benefit wording: tell us the date, headcount and dietary needs and we'll come back with a quote |

### Before printing

- `[CONFIRM: legal entity name, ABN, and the address for the footer]`
- `[CONFIRM: that the catering enquiry form and a monitored person are live and tested]`
- Swap the interim link for the final route once `/catering/corporate` is live.
- Re-read the sheet against `compliance-checklist.md`; a second person signs off.
- Check shop hours the day before printing; they come from the owner's page and may have changed.
