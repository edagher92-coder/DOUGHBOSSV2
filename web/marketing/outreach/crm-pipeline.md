# CRM pipeline: one pipeline for enquiry-form and outbound catering leads

INTERNAL. DRAFT for Elie. Prepared 2026-10-02. No system has been changed.

## Principle

Every catering lead, whether it arrived through the enquiry form or came from outbound outreach, is one record in one pipeline with the same six stages. The stages equal the `LeadStatus` enum in `src/types/marketing.ts` and `prisma/schema.prisma` (`NEW`, `CONTACTED`, `TASTING_OFFERED`, `QUOTED`, `WON`, `LOST`). A unit test fails if this table and `LEAD_STATUSES` ever differ.

Until the companion plugin carries these stages, the working system is a shared spreadsheet with the fields below. Do not wait for a build.

## Stages

| Stage | Meaning | Enter when | Leave when | SLA |
|---|---|---|---|---|
| `NEW` | A lead exists and nobody has replied | A form enquiry arrives, or a prospect with an evidence record is added, or someone replies to outreach | A human has replied or called | `[CONFIRM: first-response SLA by tier]` |
| `CONTACTED` | A human has made contact and a conversation is open | First reply or call logged | A tasting is offered, a quote is sent, or the lead is lost | `[CONFIRM: time to move to a quote or to close]` |
| `TASTING_OFFERED` | A tasting has been offered and Elie approved the offer | Only after `[CONFIRM: Elie approves cost and terms]` in `tasting-offer.md`; the offer is logged | The tasting happens and a quote follows, or the lead declines | `[CONFIRM: follow-up after a tasting]` |
| `QUOTED` | A written quote has been sent | Quote sent with date, headcount and dietary needs confirmed | The customer accepts (`WON`) or declines or goes silent (`LOST`) | `[CONFIRM: quote nudge interval and expiry]` |
| `WON` | The customer accepted and the order or account is confirmed | Customer accepts in writing; Square customer and invoice or order created (see below) | Terminal for the lead; a standing order continues as an account, not a lead | `[CONFIRM: time to create the Square record]` |
| `LOST` | No order will follow, or the contact asked to stop | Declined, no response after the agreed follow-ups, unsubscribed or asked not to be contacted | Terminal. A new, separate enquiry creates a new `NEW` record | none |

Rules:

- A lead may skip `CONTACTED` or `TASTING_OFFERED`; it may never move backwards. A lost lead who comes back starts a new record linked to the old one.
- `LOST` always carries a reason from a short list: `declined`, `no-response`, `went-elsewhere`, `outside-area`, `timing`, `unsubscribed`, `not-relevant`. `unsubscribed` also puts the address on the suppression list.
- A stop request ("please stop") moves the record to `LOST` and the contact to suppression the same day, in any stage.

## How the live plugin's statuses map

The live DoughBoss plugin stores catering enquiries in `doughboss_catering_enquiries` with its own statuses (OBSERVED, `docs/wp/02-orders-square-kitchen-map.md` section 9: `new`, `quoted`, `deposit_paid`, `confirmed`, `balance_due`, `paid`, `fulfilled`, `lost`). It has no `CONTACTED` or `TASTING_OFFERED` and no lead source, consent or attribution fields.

| Pipeline stage | Plugin status today |
|---|---|
| `NEW` | `new` |
| `CONTACTED` | none (kept in the spreadsheet until the plugin adds it) |
| `TASTING_OFFERED` | none (same) |
| `QUOTED` | `quoted` |
| `WON` | `deposit_paid`, `confirmed`, `balance_due`, `paid`, `fulfilled` |
| `LOST` | `lost` |

The Next.js lab's `CateringLead` model (`prisma/schema.prisma`) already has `guestBand`, `wantsCorporateAccount`, `source`, `attribution`, `consentAt`, `consentText` and `status` (`LeadStatus`). It is not the production store; the plugin is. The companion plugin needs the same fields. See `needsFromLead` in the report.

## Fields to track (one record per lead)

### Shared by every lead

| Field | Notes |
|---|---|
| `reference` | The plugin's enquiry number (`CAT-...`) or a spreadsheet id |
| `origin` | `inbound-form`, `inbound-phone`, `outbound-email`, `outbound-linkedin`, `outbound-phone`, `referral`, `event` |
| `status` | One of the six stages |
| `organisation`, `contact_role`, `contact_name`, `email`, `phone` | Collect the minimum. Names of private individuals only where they supply them |
| `segment`, `nearest_store` | From `segments.csv` (`revesby`, `bankstown`, `roselands`) |
| `eventType` | `OFFICE_BREAKFAST`, `TEAM_LUNCH`, `MEETING`, `CORPORATE_EVENT`, `PARTY`, `WEDDING_ENGAGEMENT`, `COMMUNITY_RELIGIOUS`, `OTHER` |
| `guestBand` | `UP_TO_25`, `FROM_26_TO_50`, `FROM_51_TO_100`, `FROM_101_TO_250`, `OVER_250` |
| `wantsCorporateAccount` | Boolean |
| `recurring` | Yes, no or unknown (standing-order signal) |
| `eventDate` | Local date, if given |
| `dietary_counts` | Counts by need. Keep out of ad and email platforms. Free-text dietary or health detail can be sensitive information; collect it only for the quote and say why |
| `score_tier` | A, B, C or D from `docs/marketing/04-corporate-outreach.md` section 6 |
| `source`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_content` | From the form's attribution if captured, else from the "how did you hear" answer |
| `owner` | The person responsible for the next step |
| `next_step`, `next_step_date` | Always filled while the lead is open |
| `lost_reason` | Required on `LOST` |
| `created_at`, `last_touch_at`, `touch_count` | For SLAs and the stop rule |

### Extra fields for an outbound lead

| Field | Notes |
|---|---|
| `address_basis_url` | Exact URL where the address was published |
| `address_basis_date` | Date the page was read |
| `relevance_note` | Why catering is relevant to the role |
| `no_unsolicited_notice` | Yes or no |
| `checked_by` | Second checker and date |
| `dnc_washed` | For any mobile or sole-trader number: date washed against the Do Not Call Register |
| `suppressed` | Yes or no; set the same day on an unsubscribe or stop request |
| `consent_basis` | `conspicuous-publication`, `express-opt-in`, `existing-enquiry`, `in-person-agreed` |

### Extra fields for an inbound lead

| Field | Notes |
|---|---|
| `consentAt`, `consentText` | When and what the customer agreed to on the form, per the Spam Act. The live plugin does not capture marketing consent on catering enquiries today (OBSERVED) |
| `marketing_consent` | Separate from the enquiry. An enquiry lets us answer the enquiry; it does not enrol the lead in the outbound sequence |

## How the two sources share one pipeline

1. Same stages, same fields, same score rubric. `origin` tells them apart for reporting.
2. Match on lower-cased email, then phone, then organisation plus role, before creating a record. If an outbound prospect later fills in the form, merge into the existing record, keep the earlier `origin` as `first_origin`, and set the later one as `latest_origin`.
3. An inbound lead is never added to the outbound email sequence. An outbound prospect who becomes a lead stops receiving the sequence the moment they reply.
4. A suppression applies across every channel and every record for that address.
5. Reporting always splits by `origin` so outbound and inbound conversion are not blended.
6. Daily routine (Track 1): copy new form enquiries into the sheet, update statuses, check overdue `next_step_date`, check the unsubscribe inbox.

## How a won deal becomes a Square customer and invoice or order

Source: `docs/square/capabilities-au.md` (retrieved 2026-10-02 by another slice; quotes below are from that file's own record of Square's Australian pages). Do not assume any Square feature works until the one-shop pilot says so.

### What exists in Australia (per that file)

| Capability | Status in the file | Notes |
|---|---|---|
| Square Invoices, estimates, recurring, deposits | AVAILABLE-AU | Free tier: "$0/mo + processing fees" with "Unlimited invoices, estimates and contracts"; Plus: "$30/mo + processing fees" adds milestone payment schedules, multi-package estimates, estimate-to-invoice conversion, custom templates and batch invoices. Invoice processing fee 2.2 per cent. |
| Estimates | Tier conflict | Square's own pages disagree on which tier unlocks estimates ("Square Invoices Plus or Premium subscribers" in the help article versus "Unlimited invoices, estimates and contracts" under Free). `[CONFIRM: tier on Dough Boss's account]` |
| Deposits and milestones | AVAILABLE-AU (Plus) | A deposit as a percentage or amount; up to 12 milestone payments on Plus |
| Recurring invoice series | AVAILABLE-AU | Charges a card on file with the customer's one-time consent; declined payments are not retried automatically; the Invoices API does not create recurring invoices |
| House Accounts | AVAILABLE-AU | Customer account with a spending limit, collected by invoice or card on file; no prepayments; no loans or instalments |
| Contracts with e-signature | AVAILABLE-AU | For standing agreements |
| Xero, MYOB, QuickBooks Online sync | AVAILABLE-AU | Xero listing names Australia; MYOB and QuickBooks named on the AU Invoices page |
| EFT or bank transfer on an invoice | NOT-FOUND | `[CONFIRM: whether an EFT payment can be recorded against invoices]` |
| Service charges on invoices | Not available | A catering delivery fee goes on as a line item. Card surcharging is no longer permitted in Australia from 1 October 2026 per the file |

### The handoff (draft procedure)

1. Trigger: the customer accepts a written quote, in writing. Set the lead to `WON`.
2. Create or match the Square customer. Use the organisation as the customer name, the contact role and email as the contact, and the pipeline `reference` in a note so the CRM and Square can be matched. Keep dietary counts out of Square notes unless needed to fulfil the order.
3. Create the estimate or invoice from the accepted quote, with each quoted line as a line item and the delivery fee, if any, as its own line. Do not add a card surcharge. `[CONFIRM: GST treatment and how the quote's price basis is shown]`
4. Deposit: if the terms require one, request it as a percentage or amount on the invoice. `[CONFIRM: deposit policy]` The plugin's own catering deposit path does not work through Square today (OBSERVED, `docs/wp/02-orders-square-kitchen-map.md` finding 10: the Square catering payment path fails closed), so deposits and balances for catering go through the Square invoice, not the plugin, until that is fixed.
5. Fulfilment: the order goes to the kitchen board by the plugin's existing workflow. `[CONFIRM: how a Square-invoiced order reaches the kitchen board]`
6. Standing orders: one of two routes. A recurring invoice series (needs the customer's one-time card-on-file consent), or a House Account for an approved corporate buyer with a spending limit and invoicing. Contracts with e-signature can record the agreement. `[CONFIRM: which route, terms reviewed by a lawyer]`
7. Accounting: sync invoices to Xero once the connection is on. `[CONFIRM: Xero connection and account ownership]`
8. Reporting: revenue per account and standing-order conversion read from Square, not from the CRM. The CRM keeps the lead history; Square keeps the money.
9. Review request: after fulfilment, ask the customer for a review in the same way as every customer, with no incentive.

### Tracked where the money lands

For every corporate account, the Square customer record is the system of record for orders, invoices and payments. The CRM keeps `square_customer_id` and `account_status` (`lead`, `account`, `standing-order`, `lapsed`) so a repeat customer is not re-counted as a new lead.

## Open items

- `[CONFIRM: SLAs per stage and tier]`.
- `[CONFIRM: who owns the pipeline day to day]`.
- `[CONFIRM: Square tier for estimates and deposits; EFT support]`.
- `[CONFIRM: how and where the companion plugin stores the CONTACTED and TASTING_OFFERED stages, source, consent and attribution]`.
- `[CONFIRM: retention and erasure for lead data; the plugin's privacy tooling covers orders, catering and vouchers but not loyalty, checkout snapshots or the marketing-consent meta (OBSERVED, `docs/wp/02-orders-square-kitchen-map.md` finding 13)]`.
