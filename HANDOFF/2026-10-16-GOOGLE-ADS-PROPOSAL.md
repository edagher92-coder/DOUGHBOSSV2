# DoughBoss catering Search proposal — 16 October 2026

Status: **DRAFT / NON-EXECUTING**. This is not an installed campaign, an approved spending limit, or a validated Ads API request.

Proposal ID / intended idempotency key: `d1c535e6-ea61-48db-bdf4-c3f3cbfda8af`.
Prepared by Codex on 16 October 2026. Expires 17 October 2026, 20:45 Australia/Sydney, or immediately if the account context or proposed operations change. Re-audit before execution; never reuse a completed key for another operation.

## Account boundary and observations

The owner explicitly selected the existing shared Ads account ending **1456** for an isolated DoughBoss setup. Other businesses' campaigns, assets, audiences, account-default conversion goals, billing and automatic recommendations are out of scope.

Read-only browser inspection found 11 enabled conversion actions, but no explicitly DoughBoss-named action. Ownership and operation of the generic GA4 contact action have not been verified. Do not reuse it as a DoughBoss success signal. The overview had an old custom date range; those costs and CPCs are not a current DoughBoss performance baseline. Currency and current auction estimates remain unverified. No Google Ads resource has been created or changed.

## Proposed resource changes (not executed)

Before: no verified DoughBoss campaign or dedicated qualified-enquiry conversion has been identified. Exact resource IDs must be recorded privately after a fresh account-scoped readback; none may be guessed.

After, subject to precise approval and validation:

- One new **paused** Search campaign: `DB | Catering | Search | Revesby | Test`. No changes to existing campaigns and no shared budget.
- Separate office/business and event-catering ad groups. Begin with tightly matched terms such as `[office catering revesby]`, `[corporate catering revesby]`, `"business lunch catering revesby"` and `"event catering revesby"`. Check local search volume and actual auction estimates before finalising. No broad-match expansion by default.
- Proposed initial geography: a small Revesby-centred catchment, provisionally 10 km, with people **present in** the target area, not merely interested in it. Radius availability, demand and owner approval need verification. Copy must say Revesby pickup and must not imply delivery coverage.
- Google Search only initially; no Display expansion, Search Partners, remarketing, customer-list upload or automatic campaign expansion. Validate the options actually supported by the account.
- Destination: the approved and deployed `/catering/` page, once release and enquiry acceptance pass. Office meetings, team lunches, mini manoush, pies and platters; availability/final pricing confirmed by the team. No invented discounts, certifications, reviews, delivery promises or response-time guarantees.
- A dedicated campaign-specific qualified catering enquiry goal. Count a successfully saved, non-duplicate enquiry—not a click on Submit, a pageview, directions or another business's contact event. Reconcile lead quality and confirmed bookings before claiming conversion efficiency. No invented conversion value.
- Use campaign-specific goals, not account-default changes. Attribution, duplicate prevention, consent, safe transaction/reference handling and correct domain/tag ownership must be tested before this is a bidding signal. A local code test is not a working Ads conversion.
- Bidding decision remains pending current demand/CPC estimates and conversion evidence. The owner's objective is qualified conversions at minimal spend; do not assume a “Maximise conversions” setting works well without a valid goal and adequate evidence.

## Budget gate

The owner requested the lowest usable test budget but has **not approved an exact monetary limit**. No spending is authorised by this document. A provisional question asks whether A$5 average/day is acceptable after tracking and launch checks. Currency must first be confirmed. Most Google Ads campaigns can spend up to twice the average daily budget on an individual day and 30.4 times that setting over a full month. Thus A$5 average/day may mean A$10 on a day and A$152 in a full month; it is not a hard daily cap or proof of a useful test.

If an exact budget is approved, freeze a new immutable proposal with the exact resources, targeting, ads, conversion action, bid limits, launch/stop window and rollback before activation. A paused setup and a live campaign are separate actions. Do not start spend merely because account selection is approved.

## Validation and rollback

Current validation result: **NOT EXECUTABLE**. Missing exact resource inventory, currency, current estimates, exact budget, conversion/domain/consent acceptance and deployed landing-page acceptance. No Google Ads API transport is established; no provider `validate_only` has run. Do not claim browser inspection is GAQL or API validation.

For any later supported API execution, validate the exact tenant-scoped request with `validate_only`, verify freshness/approval and execute once with recorded outcome. For a supported UI batch, document exact visible settings and read them back; do not invent API validation. Keep sanitised receipts in the private operational evidence location, not public Git.

Rollback intent: pause only the newly created DoughBoss campaign if activation is later approved and acceptance fails. Never alter the other businesses' campaigns or account-default goals. Retain the new resource IDs and audit outcome; do not blindly recreate resources after an uncertain result.

Official references: <https://support.google.com/google-ads/answer/6385083?hl=en>, <https://support.google.com/google-ads/answer/10486637?hl=en> and <https://support.google.com/google-ads/answer/1704424?hl=en>.

## Completion entry — 16 October 2026

Account selection recorded; dedicated setup proposal prepared. Provider mutations: **zero**. Spend enabled: **no**. This proposal is a handoff, not a claim of account setup or profitable conversions.
