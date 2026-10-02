# Google Ads conversion plan, DRAFT

Prepared 2026-10-02 for Elie. Internal planning document: it carries `[CONFIRM: ...]` and `[VERIFY: ...]` items. Nothing here is configured in Google Ads, GA4, Tag Manager or Square. Companion to `docs/marketing/03a-google-ads.md`.

Labels: OBSERVED means I read it at the cited URL on 2026-10-02. INFERRED is my reasoning. `[VERIFY: ...]` means a documented fact I could not confirm from the pages I could read, to be checked in the product or the docs before relying on it. The fetch tool summarises pages with a small model, so quoted wording is as it reported it.

## 1. Summary

- The goal of the account is won catering orders, not clicks and not even form fills. So the measurement is built in three stages: (1) count enquiries and calls, (2) count qualified and won leads by importing them back from the plugin and Square, (3) bid on those once there is enough volume.
- Primary conversion at launch: `generate_lead` where `form = catering_enquiry`. Everything else is secondary (reported, not used for bidding) until it has earned primary status.
- Stage 2 works today without any build: a weekly CSV of won deals with click IDs (section 7).
- Stage 3 (automatic upload once the plugin sends itemised orders to Square) is designed in section 8. It depends on things I could not verify for Australia. Do not build it before section 8.4 is done.
- The analytics event names below are the exact ones in `src/lib/analytics/events.ts`. Today `src/lib/analytics/track.ts` is a deliberate no-op stub, so no events fire until the dispatcher exists. The "start now on WordPress" route in section 3 does not depend on it.

## 2. Conversion actions to create

Names are the Google Ads conversion action names. "Source" is the exact event name from `events.ts`, with the parameter that selects it.

| # | Conversion action | Source | Goal | Role at launch | Count | Notes |
|---|---|---|---|---|---|---|
| 1 | Catering enquiry | `generate_lead` with `form = catering_enquiry` | Submit lead form | PRIMARY | One per click | The money action. Params available: `category` (event type), `guest_band`, `store` |
| 2 | Minis waitlist | `generate_lead` with `form = minis_waitlist` | Submit lead form | Secondary until the Minis campaign runs, then primary for that campaign only | One per click | Do not let waitlist sign-ups count toward catering bidding |
| 3 | Calls from ads | Google call asset (call reporting) | Phone call lead | PRIMARY once a minimum call length is set: `[CONFIRM: minimum call length that counts as a real enquiry]` | One per call | Only where a number is answered. Needs a catering number: `[CONFIRM: which number answers catering calls]` |
| 4 | Click to call (website) | `click_to_call` (params `store`, `surface`) | Contact | Secondary | One per click | A tap on a phone link on the site is intent, not a conversation. Promote after calls-to-lead rate is known. Avoid double counting with row 3 (different source: site click, not ad call) |
| 5 | Order started (pickup) | `begin_checkout` (params `store`, `value_cents`, `item_count`, `payment_method`) | Begin checkout | Secondary, Brand campaign only | One per click | Optional. Pickup ordering is live for Revesby. `[CONFIRM: events.ts payment_method values change when Square replaces Stripe]` |
| 6 | Order placed (pay at pickup) | `order_placed` (params `store`, `value_cents`, `item_count`) | Purchase | Secondary, Brand campaign only | Every | Optional. `purchase` is deliberately not fired from the browser (see `events.ts`); card orders will report server-side |
| 7 | Catering quote sent | Offline import, plugin `LeadStatus = QUOTED` | Qualified lead | Secondary | One per lead | Section 7 and 8 |
| 8 | Catering order won | Offline import, plugin `LeadStatus = WON` | Purchase | Secondary at first. PRIMARY candidate once there are enough imported wins | One per lead | The conversion we actually want to buy. Value = real paid amount (section 4) |

Not imported to Google Ads, kept in GA4 for analysis only: `select_store`, `view_item`, `add_to_cart`, `remove_from_cart`, `quote_step` (diagnostic: step 1 to step 2 drop-off), `hero_explore`, `pack_size_change`, `get_directions` (a secondary conversion later is fine), `cta_click`.

Mark `generate_lead` as a key event in GA4. Do not mark the micro-events.

Why calls from ads are in the plan even though Google rates the form higher in the funnel: INFERRED, a catering buyer often just phones the store. A plan that counts only forms will under-report and make Smart Bidding think it is failing.

## 3. Google tag versus GA4 import, and the start-now track

**Recommendation: Google tag (through Tag Manager) for the Google Ads conversion actions; GA4 for analysis. Do not import the same event from GA4 as a primary action.**

Reasons, INFERRED unless cited:

- The Google tag measures the conversion in the click's own browser session and supports enhanced conversions for leads directly. GA4 import adds a reporting delay and depends on GA4 processing and thresholds.
- Importing the same event from both double counts. If you import from GA4 for comparison, mark it secondary.
- We keep one source of truth per conversion so a mismatch is a finding, not a mystery.

**Start NOW on the existing WordPress site (no build wait):**

1. Starting state, OBSERVED in `docs/wp/01-storefront-map.md` section 7 (a read-only audit dated 2026-10-02): no GA4, GTM or Google Ads tag exists in the plugin or theme code. There is a consent-gated Meta and TikTok bridge (`public/js/doughboss-marketing.js`) exposing `window.DoughBossMarketing.track/setConsent` and the `doughboss:consent` and `doughboss:marketing-event` document events. So the Google tag is new, and it should be gated by the same consent signal (`doughboss:consent`) rather than by a second banner. `[CONFIRM: that no tag was added through a plugin or header setting outside the repository, by viewing the live page source]`.
2. In Tag Manager: a Conversion Linker tag, a Google Ads conversion tag for "Catering enquiry", and a GA4 `generate_lead` event, all fired on the plugin form's success. How the live catering form signals success is `[CONFIRM: does it redirect to a thank-you URL or confirm in-page by AJAX? Read public/js/doughboss-catering.js]`. A thank-you URL is the simplest trigger. If it is in-page, the plugin needs to push a `dataLayer` event (or a `doughboss:marketing-event`) on success.
3. A click listener on `tel:` links for `click_to_call` (store number identifies the store).
4. Call asset call reporting for calls from ads, once the catering number is named.
5. Auto-tagging on in Google Ads, so the `gclid` lands on the page.
6. Verify with section 6 before any spend.

When the companion plugin ships, its dispatcher replaces these listeners and sends the typed events. Keep the names identical so nothing in Google Ads changes.

**Consent and the typed events.** Dough Boss is an Australian business and the Privacy Act and APPs apply (`docs/marketing/research/compliance-au.md` section 6). Fire tags according to the consent design from the companion plugin (the companion plugin design had not landed in `docs/wp/` when this was written; only the storefront map was there). `[CONFIRM: consent model and what categories gate the Google tag]`. Google Consent Mode setup is `[VERIFY: current Google guidance for non-EEA sites]`.

## 4. Value rules, attribution and windows

**Value: nothing is invented.**

- Launch with no conversion value on rows 1 to 4. Count-based bidding (Maximise conversions then Target CPA) does not need it.
- `[CONFIRM: average catering order value]`, `[CONFIRM: gross margin %]`, `[CONFIRM: lead to won rate]` are the inputs for any estimate. Use them in two ways only:
  1. Budget and CPA maths in `03a-google-ads.md` section 8.
  2. Optionally, once confirmed, a default lead value `= average_order_value x lead_to_won_rate` applied to row 1 so that leads and wins can sit in one value-based model. Label this internally as an ESTIMATE. Do not mix it with real order values on the same action.
- Real values come only from won orders (row 8): the amount actually paid, in AUD, taken from Square once the loop exists, or from the invoice in the manual method. `[CONFIRM: record value excluding GST, and apply it consistently]`.
- Guest band (`UP_TO_25`, `FROM_26_TO_50`, and so on) is a lead score, not a value. After the first real wins, compute the average won value per band from data, and only then consider band-specific values.
- Do not use Target ROAS or value-based bidding until real values exist. OBSERVED (https://support.google.com/google-ads/answer/7065882, retrieved 2026-10-02): Google cites measuring over periods "that have at least 30 conversions, such as a month or longer (50 conversions for Target ROAS)".

**Attribution model:** data-driven if the account is offered it, else the available alternative: `[VERIFY: which attribution models Google Ads currently offers for new conversion actions]`. With a handful of conversions per week no model will be statistically firm, so judge by whole-funnel results and search terms, not by model detail.

**Windows:** keep the click-through default for form and call conversions. `[VERIFY: the default and maximum click-through windows, and the maximum age of a click that Google accepts for offline imports]`. Catering has a long consideration period (a quote, then a tasting or a decision), so the offline "won" conversion needs the longest window the account allows.

## 5. Enhanced conversions for leads (and the consent and privacy gate)

OBSERVED (https://support.google.com/google-ads/answer/9888656, retrieved 2026-10-02): enhanced conversions for leads lets you use "hashed, first-party user-provided data from your website (for example, lead forms) together with imported offline lead conversions". The data is normalised (lowercase, whitespace removed, phone numbers in E.164 format) and hashed with SHA-256 before it is sent to Google. The page points to Google's customer data policies for consent requirements, which I could not read. OBSERVED (https://support.google.com/google-ads/answer/2998031, retrieved 2026-10-02): Google says "If you have not already adopted offline conversion import, we recommend starting with enhanced conversions for leads instead." The page I could read does not say whether a `gclid` is required for enhanced conversions for leads, or the upload time window. `[VERIFY: both, and the setup path (Data Manager or API)]`.

What to send: email address and phone number from the enquiry only. Names are not needed. Do not send dietary requirements, notes or anything else.

Gates before turning it on:

1. The privacy policy states that contact details may be shared in hashed form with advertising platforms to measure advertising, and how to opt out. LAWYER to check the wording (APP 1, 5, 7 and 8 context in `compliance-au.md` section 6).
2. A consent record on the lead for ad measurement: `[CONFIRM: new field or checkbox for ad-measurement consent, separate from the Spam Act marketing consent in consentAt and consentText]`. A lead who has not agreed is never uploaded.
3. The typed event params in `events.ts` deliberately cannot hold personal data ("NO PERSONAL DATA" is rule 1 in that file). So the hashed contact data must NOT be added to `EventParams`. It is passed by a separate, consent-gated path in the dispatcher or the plugin. This is a deliberate exception that the lead (the person who owns the analytics layer) must design; see needsFromLead in the report.
4. Hashing is done once, on the server or in the tag, never in logs. Store no raw contact data in analytics logs, URLs, or Google Ads exports.

## 6. End-to-end verification (do this before any spend)

Run once per landing page type (corporate, office breakfast, events, store page, Minis) and again after any form or tag change. A human submits the test lead, since this plan submits nothing.

1. Open the landing page from a test click URL that carries a `gclid` parameter (a made-up value is fine for the plugin check; Google will not credit a fake `gclid`, so use a real ad click in step 7).
2. Tag Manager preview and GA4 DebugView: the page view, the form steps (`quote_step` when the dispatcher exists), and `generate_lead` appear once each, with the expected `form`, `category`, `guest_band`, `store`.
3. Submit a test enquiry marked clearly as a test (`[CONFIRM: a test marker the team agreed, for example a company name 'TEST']`).
4. In the plugin: the lead exists, `attribution` holds `utmSource`, `utmMedium`, `utmCampaign`, `utmTerm`, `utmContent` and the click id, `landingPath` has no query string, `consentAt` and `consentText` are set. The team notification email arrived.
5. Google Ads: the conversion action status moves to "Recording conversions" (this can lag, so check again the next day). Tag Assistant shows the Google Ads tag firing once.
6. Phone: tap a phone link; `click_to_call` is recorded with the right `store`. When the catering number exists, place a call from an ad test and check call reporting.
7. Real click test: with the ad PAUSED nothing runs, so use the first live hours: click your own ad once from a phone, submit a lead, and confirm the click appears in the account and the lead carries a real `gclid`. Delete or mark the lead as a test and exclude it from won-deal uploads.
8. Mark the test leads `LOST` or excluded so they do not pollute lead-quality counts.
9. Record the result in `launch-checklist.md`.

## 7. Interim manual closed loop (works today)

Use this from the first won order. It needs no Square integration, no developer and no API access.

**Weekly, same day as the search-terms review:**

1. In the plugin lead list, filter leads whose status changed to `WON` in the last week. For each, read the click ID from the lead's attribution (`gclid`, or `gbraid` or `wbraid` if that is what was captured), the won amount from the invoice or the Square order, and the date and time the payment was received.
2. Skip leads with no click ID (phone, direct, organic), test leads, refunded or cancelled orders, and leads without ad-measurement consent (when that field exists).
3. Build the upload CSV (below) and upload it in Google Ads under the offline conversions area. `[VERIFY: current menu path and the exact template columns by downloading the template from the account]`.
4. Also upload `QUOTED` leads as the "Catering quote sent" action if row 7 was created. Keep that file separate.
5. Record the upload date and row count in the lead tracker so rows are never uploaded twice. Use the lead reference (for example `DB-Q-7K2M4X`) as the order ID to let Google ignore a duplicate. `[VERIFY: order ID field support for offline imports]`.

CSV shape (columns follow the usual Google offline import template; confirm against the template you download):

```
Parameters:TimeZone=Australia/Sydney
Google Click ID,Conversion Name,Conversion Time,Conversion Value,Conversion Currency
<gclid>,Catering order won,2026-10-14 15:30:00,[real paid amount],AUD
```

- `Conversion Name` must match the Google Ads conversion action name exactly.
- `Conversion Time` is when the order was paid, not when the lead arrived.
- `Conversion Value` is the real paid amount from the invoice or Square, never an estimate.
- For `gbraid` or `wbraid` leads, Google uses separate columns. `[VERIFY: the template's columns for those identifiers]`.
- Newer templates may add user-data consent columns. `[VERIFY: consent columns in the current template]`.
- Upload promptly. Google only accepts a click up to a certain age. `[VERIFY: maximum click age for offline imports]`.

**Owner and effort:** `[CONFIRM: who does this weekly]`. Roughly a short weekly task. Do not skip weeks, because a late upload can miss the window.

**Calls and walk-ins:** phone enquiries that become orders cannot carry a `gclid`. Ask every caller how they found Dough Boss, tag the lead's source in the plugin, and read call-reporting numbers in Google Ads for calls from ads. Do not guess a click id for a phone lead.

## 8. The automatic closed loop (design)

### 8.1 Flow

```
Ad click (auto-tagging adds gclid / gbraid / wbraid)
  -> landing page; plugin stores utm* and click id in lead.attribution (first-party)
  -> enquiry submitted: CateringLead (reference DB-Q-xxxxxx, consentAt, consentText,
     [CONFIRM: ad-measurement consent flag])
  -> team quotes; status NEW -> CONTACTED -> TASTING_OFFERED -> QUOTED
  -> plugin creates the Square order (Orders API) for the quote, and an invoice where needed,
     with reference_id / metadata = lead reference ONLY (no gclid, no personal data)
  -> customer pays (invoice, payment link or pay at shop)
  -> Square webhook -> plugin resolves order -> lead; status WON; records paid amount and time
  -> upload job (queued, consent-checked): send to Google Ads
        (a) enhanced conversions for leads: hashed email and phone + conversion time + value + order id
        (b) or gclid / gbraid / wbraid import where the click id exists and is within the click window
  -> weekly check: Google Ads shows imported "Catering order won"
```

### 8.2 Design choices and why

- **Click ids stay in the plugin, not in Square.** This matches the Square integration notes (`docs/square/api-integration-notes.md` section 7.2): full attribution in our own database, only an opaque reference in Square metadata. The plugin lead already holds `attribution` (`src/lib/attribution-schema.ts`, `CateringLead.attribution`). Square carries only the lead reference. That keeps an advertising identifier out of the point-of-sale, and keeps the identifiers we upload sourced from data we collected ourselves on our own enquiry form, not from Square's buyer data. (INFERRED reason: Square's terms may limit passing Square customer data to advertisers. `[VERIFY: Square developer terms and privacy rules on sending any Square-held customer data to ad platforms]` before any design that does.)
- **Orders API as the bridge.** OBSERVED (https://developer.squareup.com/docs/orders-api/metadata, retrieved 2026-10-02): "An application can map up to 10 entries per metadata field", keys are 60 characters or fewer using letters, digits, underscore or hyphen, values are 255 characters or fewer, and entries are private to the application that wrote them. OBSERVED (https://developer.squareup.com/docs/orders-api/what-it-does, retrieved 2026-10-02): the Orders API tracks the order source and supports metadata. That page does not state country availability: `[VERIFY: Orders API is available for an Australian Square account]`.
- **Invoices for corporate buyers.** OBSERVED (https://developer.squareup.com/docs/invoices-api/overview, retrieved 2026-10-02): "The Invoices API is supported in the following countries: Australia, Canada, France, Ireland, Japan, Spain, the United Kingdom, and the United States." Invoices must be associated with an order created through the Orders API, one invoice per order, and payment for that order must go through the invoice. So a catering quote that becomes an invoice is an Orders API order plus an invoice.
- **Mapping order to lead.** The Square notes record that `order.reference_id` is 40 characters or fewer (a lead reference such as `DB-Q-7K2M4X` fits) and that SearchOrders does not filter by metadata, so store the Square order id on the lead when the order is created and look the lead up from our own table, not from Square. Square does not store marketing consent in a usable way (notes, summary item 11), so ad-measurement consent lives in our database.
- **Events that mean "paid".** OBSERVED (https://developer.squareup.com/docs/webhooks/v2webhook-events-tech-ref, retrieved 2026-10-02): `invoice.payment_made` ("A payment was made for an invoice"), `invoice.refunded`, `invoice.canceled`, `payment.created`, `payment.updated`, `order.created`, `order.updated`, `order.fulfillment.updated`. The plugin should treat an order as won only on a completed payment, never on `order.created`. The Square notes add that there is no payment-link webhook: completion is detected from `payment.updated` (status COMPLETED, carrying `order_id`) plus `order.updated`, then confirmed with RetrieveOrder (summary item 6). `[VERIFY: how refunds appear, and whether the same events fire for invoices, pay-at-shop and payment links in a production smoke test; the notes say sandbox cannot prove this]`.
- **Do not assume any Square feature works yet.** The pilot is one shop. This design starts only after the plugin sends itemised orders to Square for that pilot.

### 8.3 Consent, hashing and data rules

1. Upload only for leads with ad-measurement consent recorded (`[CONFIRM: field]`). No consent, no upload.
2. Hash with SHA-256 after normalising: trim, lowercase, remove spaces, and phone in E.164 (`+61...`). Google's own wording is above in section 5. `[VERIFY: any further email rules, such as handling of dots in addresses]`.
3. Send the minimum: hashed email and hashed phone, conversion time, value, currency, order id. No names, notes, dietary data or addresses.
4. Never log raw contact data or hashed values in analytics tools, URLs or error reports.
5. Respect deletion and opt-out requests: remove the lead from the upload queue; an upload already sent cannot be recalled, so say that in the privacy policy.
6. Refunds: when an order is refunded or cancelled, send an adjustment (retraction) where Google supports it. `[VERIFY: conversion adjustment support for offline conversions]`.
7. Credentials (Google Ads API access, OAuth tokens, developer token) are secrets held in the host's secret store, never in the repo, never committed.
8. Mark test leads and exclude them from the queue.

### 8.4 What must be verified first (a short, honest list)

| Item | Why it matters | Status |
|---|---|---|
| Square Orders API and metadata for an Australian account | The bridge from lead to order | Orders is treated as usable in AU by the Square notes; the metadata page states no country. `[VERIFY in a production smoke test]` |
| Square Invoices API in Australia | Corporate invoicing path | OBSERVED supported (cited above) |
| Square webhook event semantics for completed payment, pay-at-shop, payment links, refunds | When a lead counts as won | Event names OBSERVED; semantics `[VERIFY]` |
| Square terms on sharing customer data with advertisers | Whether we may use Square-held data at all | `[VERIFY]`. Design avoids it by using plugin-held identifiers |
| Square Payment Links and their order linkage in Australia | Pay-at-shop and online payment | `[VERIFY]` |
| Any native Square to Google Ads connection in Australia | May make the custom upload unnecessary | Unknown. `[VERIFY]` |
| Google: maximum click age for offline imports | Weekly rhythm | `[VERIFY]` |
| Google: whether a click id is required for enhanced conversions for leads | Phone and organic leads | `[VERIFY]` (not stated on the page I could read) |
| Google: current import path (UI, scheduled, API, Data Manager) and API access requirements | Build effort and approvals | `[VERIFY]` |
| Google: customer data and consent policy text for enhanced conversions | The consent gate in 8.3 | `[VERIFY]`. Page not readable by the tool |
| Google Customer Match is a separate product with its own rules | Not used here | OBSERVED (https://support.google.com/adspolicy/answer/6299717, cited in `compliance-au.md`): first-party data only, privacy disclosure and consent |

### 8.5 Build shape (for the lead, not built here)

- A plugin job keyed by lead reference that moves a lead to `WON` and writes `wonAt`, `wonValueCents`, `squareOrderId`.
- A queue of "conversions to upload" with consent flag, identifiers, status and a dedupe key (the lead reference).
- An uploader behind a feature flag, default off, plus a "dry run" that writes the CSV in section 7 so the manual and automatic paths use the same data and can be compared.
- Weekly reconciliation: imported count in Google Ads versus won leads with consent.

## 9. Open items

1. `[CONFIRM: what tags, containers, property and consent banner exist on doughboss.com.au today]`.
2. `[CONFIRM: how the existing enquiry form signals success]`.
3. `[CONFIRM: catering phone number and call-length threshold]`.
4. `[CONFIRM: average catering order value, margin, lead to won rate]`.
5. `[CONFIRM: ad-measurement consent field and privacy policy wording]`.
6. `[CONFIRM: value excluding GST, applied consistently]`.
7. `[CONFIRM: who runs the weekly manual upload]`.
8. All `[VERIFY]` items in sections 3 to 8.

## 10. Sources (retrieved 2026-10-02)

- Enhanced conversions for leads: https://support.google.com/google-ads/answer/9888656
- About offline conversion imports: https://support.google.com/google-ads/answer/2998031
- About Smart Bidding: https://support.google.com/google-ads/answer/7065882
- Customer Match data requirements: https://support.google.com/google-ads/answer/7659867 and https://support.google.com/adspolicy/answer/6299717
- Square Orders API overview and metadata: https://developer.squareup.com/docs/orders-api/what-it-does and https://developer.squareup.com/docs/orders-api/metadata
- Square Invoices API overview: https://developer.squareup.com/docs/invoices-api/overview
- Square webhook event reference: https://developer.squareup.com/docs/webhooks/v2webhook-events-tech-ref
- `docs/square/capabilities-au.md` did not exist when this was written. The folder held `docs/square/api-integration-notes.md`, which states AU availability per capability (Invoices and Loyalty explicitly listed as supported in Australia; payment links work where Square accepts payments). Where it and this plan disagree, that file wins, and anything neither confirms is unknown.
