# Meta tracking plan: Pixel, Conversions API and the paid-order loop, DRAFT

Prepared 2026-10-02 for Elie. Internal planning document, so it carries `[CONFIRM: ...]` and `[VERIFY: ...]` items. Nothing here is configured in any Meta account, Events Manager, Tag Manager or the WordPress site. Companion to `docs/marketing/03b-meta-ads.md`.

Labels: OBSERVED means I read it at the cited URL on 2026-10-02 (through a fetch tool that summarises pages with a small model, so quoted wording is as it reported it). INFERRED is my reasoning. `[VERIFY: ...]` is a Meta behaviour I could not confirm from a page I could read; check it in Events Manager or the docs before relying on it. `[CONFIRM: ...]` needs Elie or the team.

Alignment note. `docs/marketing/05-measurement.md` (the growth slice's measurement plan) had not landed when this was written. This plan uses the event names in `src/lib/analytics/events.ts` and the plugin facts in `docs/wp/01-storefront-map.md` and `docs/wp/02-orders-square-kitchen-map.md`. Section 12 lists what to reconcile once 05 exists.

## 1. Principles

1. One typed event list. The names come from `events.ts`; Meta names are a mapping, not a second taxonomy.
2. Redundant setup. A browser Pixel event and a server Conversions API (CAPI) event for the same action, joined by `event_id` and `event_name`, because Meta recommends running both together (section 4 source).
3. No personal data in parameters. `events.ts` deliberately has no field that can hold an email, phone, name or free text. Customer identifiers go only in the `user_data` block of a server event, hashed, and only with consent (section 7).
4. Consent first. Nothing fires to Meta, browser or server, until the visitor has said yes to advertising and measurement.
5. Leads, not clicks. The ad campaigns optimise for the `Lead` event. The money event is a won catering order, which arrives later from the plugin (section 9) and from Square (section 10).
6. No spend before a test lead has been seen end to end (section 11 gate).

## 2. Where the site is today (OBSERVED, from the read-only WordPress audit dated 2026-10-02)

- The plugin ships a consent-gated Meta and TikTok bridge: `public/js/doughboss-marketing.js`, configured by `DOUGHBOSS_MARKETING_ENABLED`, `DOUGHBOSS_META_PIXEL_ID` and the `doughboss_marketing_config` filter. It exposes `window.DoughBossMarketing.track` and `setConsent` and fires a `doughboss:marketing-event` document event.
- The bridge maps `generate_lead` to Meta `Lead`. It is called for the pre-order form only. The catering enquiry form does not call it.
- The bridge listens for a `doughboss:consent` event. Nothing in the plugin or theme dispatches it, so the bridge never switches on.
- The bridge keeps five `utm_*` keys in memory only. It captures no `fbclid`, no `_fbp`, no `_fbc`, and forwards nothing to the server. `adpilotServerReady` is `false`.
- Catering enquiries (`doughboss_catering_enquiries`) store name, email, phone, date, guests, dietary, notes and quote fields. They store no UTM, no click id, no referrer, no consent and no source page.
- The plugin fires the hooks we need: `doughboss_catering_enquiry_created( $id, $row )`, `doughboss_catering_status_changed( $id, $status )`, `doughboss_catering_quote_updated`, `doughboss_catering_payment( $id, $leg )`. Catering statuses are `new, quoted, deposit_paid, confirmed, balance_due, paid, fulfilled, lost`.
- No GA4, GTM or Google Ads tag exists. This plan does not depend on one; it uses the plugin's own bridge or its replacement.

What this means: the Lead event does not fire for catering today, consent never switches the bridge on, and there is no attribution on the enquiry row. That is the work in section 11.

## 3. Event map: `events.ts` to Meta

Meta receives only the events that help it find buyers or that we report on. Engagement noise stays out.

| `events.ts` name | Meta event | Browser | Server (CAPI) | Role | Notes |
| --- | --- | --- | --- | --- | --- |
| `generate_lead` with `form = catering_enquiry` | `Lead` standard event | Yes | Yes | PRIMARY optimisation event for the Corporate, Events and Warm campaigns | Custom data: `content_name = catering_enquiry`, `content_category = <category>`, `guest_band`, `store`. No free text. |
| `waitlist_submit` (param `store`; fired only after the server confirms the sign-up) | `Lead` standard event | Yes | Yes | PRIMARY for the Coming Soon List campaign only, which is held | `content_name = waitlist`. Separate it from catering with a custom conversion on `content_name` [VERIFY: create in Events Manager; confirm rule options]. Do not let waitlist sign-ups train the catering campaigns. `events.ts` also still allows `generate_lead` with `form = waitlist`; nothing fires it today, and it must not be mapped as a second waitlist event (see section 12). |
| `coming_soon_view` | none | No | No | GA4 only | The teaser section scrolled into view. Engagement signal, not sent to Meta. |
| `click_to_call` | `Contact` | Yes | Optional | Secondary, report only | A tap on a phone link is intent, not a conversation. Custom data: `store`, `surface`. |
| `get_directions` | `FindLocation` | Yes | No | Secondary, report only | Custom data: `store`, `surface`. |
| `begin_checkout` | `InitiateCheckout` | Yes | No | Not used for the lead campaigns | Revesby pickup is GATED: the brief says it is live, but the live /order/ page said "Online ordering is coming soon" on 2026-10-02 (`marketing/gbp/revesby.md` section 4). Relevant only if a pickup campaign is ever run, and only after the gate clears. |
| `order_placed` (pay at pickup) | none | No | No | Not sent | An unpaid pay-at-shop order is not a purchase. See section 10. |
| `purchase` (server only, verified payment) | `Purchase` | No | Yes | Later phase | Section 10. `events.ts` is explicit that purchase is never fired from the browser. |
| `quote_step` | none | No | No | GA4 and plugin diagnostics only | Step 1 to step 2 drop-off. |
| `select_store`, `view_item`, `add_to_cart`, `remove_from_cart`, `hero_explore`, `cta_click` | none | No | No | Not sent to Meta | Engagement signals. Sending them adds noise and more data shared with a platform. |
| Plugin status `quoted` | custom `QuoteSent` | No | Yes | Quality signal | Section 9. |
| Plugin status `confirmed`, `deposit_paid` or `paid` | `Purchase` with real value | No | Yes | The event we want to buy | Section 9. Only when a real amount is known. |

Reconcile: the plugin bridge says `generate_lead` maps to `Lead` and `Lead` is also my choice for the waitlist submit. If the growth slice maps the waitlist to a different Meta event, change the waitlist row only and keep the catering row. The event carries no product name and no interest value (docs/site/teaser-direction.md).

## 4. The `Lead` event and the `event_id` approach

Source, OBSERVED 2026-10-02: https://developers.facebook.com/docs/marketing-api/conversions-api/deduplicate-pixel-and-server-events

- Meta deduplicates on event ID and event name: "We determine if events are identical based on their ID and name." The Pixel `eventID` must equal the CAPI `event_id`, and the Pixel event name must equal the CAPI `event_name`.
- Deduplication works inside a 48-hour window: events "are only deduplicated if they are received within 48 hours of when we receive the first event with a given event_id".
- A secondary method uses `fbp` and `external_id`. It "generally only works for deduplicating events sent first from the browser and then through the server". Do not rely on it.
- Meta recommends running CAPI alongside the Pixel in a redundant setup with deduplication.

Source, OBSERVED 2026-10-02: https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/server-event

- `event_name` and `event_time` are required. `event_id` is optional but recommended. `event_source_url` should match the verified domain and is required for website events. `action_source` is required (allowed values include `website`, `email`, `phone_call`, `chat`, `physical_store`, `system_generated`, `other`).
- `event_time` is a Unix timestamp in seconds and may be up to 7 days old when sent. Consequence for us: send stage events (section 9) when the status changes, with `event_time` set to the change time, never in a late batch.

### How the id is created (recommended flow)

The server creates the `event_id`, so the browser and the server cannot disagree.

1. The visitor submits the catering form. The browser sends the enquiry to the plugin REST endpoint, with the consent state and the attribution values (section 6) in the body.
2. The plugin saves the enquiry, creates `event_id` (a random UUID, stored in the side table against the enquiry), and returns it in the success response.
3. The browser, only if consent is granted, fires `fbq('track', 'Lead', {content_name: 'catering_enquiry', ...}, {eventID: <event_id>})` using that returned id.
4. In the same request, on the `doughboss_catering_enquiry_created` hook, the plugin queues the CAPI `Lead` with the same `event_id`, `event_name = Lead` and the stored `fbc`, `fbp`, IP and user agent.
5. Test Events must show one `Lead` marked as received from both browser and server, then deduplicated (section 11).

Why not the enquiry number (`CAT-ymd-NNNN`) as the id: it is guessable, and a UUID gives us the same join without leaking the numbering scheme into a third-party platform. Keep the enquiry number as an internal key only.

Failure rule: if the visitor has not consented, send neither event. If the browser blocks the Pixel but consent was granted, the server event still goes and counts once. If the server send fails, retry with the same `event_id` inside 48 hours so a late success does not double count.

### CAPI request shape (placeholders only, no real values)

```json
{
  "data": [
    {
      "event_name": "Lead",
      "event_time": 1700000000,
      "event_id": "<uuid-from-plugin>",
      "event_source_url": "https://doughboss.com.au/catering/office-breakfast",
      "action_source": "website",
      "user_data": {
        "em": ["<sha256 of normalised email>"],
        "ph": ["<sha256 of normalised phone>"],
        "client_ip_address": "<not hashed>",
        "client_user_agent": "<not hashed>",
        "fbc": "<not hashed, fb.1.<creation_time>.<fbclid>>",
        "fbp": "<not hashed, fb.1.<creation_time>.<random>>"
      },
      "custom_data": {
        "content_name": "catering_enquiry",
        "content_category": "<CateringEventType value>",
        "guest_band": "<band>",
        "store": "<store slug or none>"
      }
    }
  ]
}
```

Sent with `POST https://graph.facebook.com/{API_VERSION}/{PIXEL_ID}/events` and an access token in the `access_token` query parameter (OBSERVED, https://developers.facebook.com/docs/marketing-api/conversions-api/using-the-api, 2026-10-02). The token lives in server configuration, never in the repository, a page, a log or a screenshot. `[CONFIRM: which system user or role holds the token and how it is rotated]`.

Hashing and formats, OBSERVED 2026-10-02 at https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/customer-information-parameters:

- Contact fields (email, phone, names and similar) are normalised (trim leading and trailing spaces, lower case) and hashed with SHA-256.
- Not hashed: `client_ip_address`, `client_user_agent`, `fbc`, `fbp`.
- `fbc` format `fb.${subdomain_index}.${creation_time}.${fbclid}`; `fbp` format `fb.${subdomain_index}.${creation_time}.${random_number}`.
- Phone normalisation (digits only, with country code, so 04xx numbers become 614xx) is covered on the same page [VERIFY: read the phone rule before building the hasher].

No dollar value goes on `Lead` unless Elie decides to run value-based optimisation. A made-up lead value would train the account on a number we invented. `[CONFIRM: whether to assign a lead value at all; default is none]`.

## 5. Domain verification and aggregated event measurement

- Domain verification. OBSERVED 2026-10-02, https://developers.facebook.com/docs/sharing/domain-verification: verification lets the business claim the domain in Business Manager and control who can edit link content and previews; the methods named are an HTML file, a DNS TXT record and a meta tag. `event_source_url` should match the verified domain (server-event page, above). Do this for `doughboss.com.au`. DNS lives at Crazy Domains; that change is Elie's to make or approve, and this slice does not touch DNS. A meta tag or HTML file avoids DNS if the WordPress companion plugin can print it. `[CONFIRM: who controls the Meta business portfolio that will own the domain; see the entity question in 03b section 13]`.
- Aggregated Event Measurement (AEM). I could not read Meta's AEM help page (the fetch returned only a title), and a search extract from a third-party site said events are limited and prioritised per domain. That is not good enough to state as fact. `[VERIFY: in Events Manager, open the dataset for doughboss.com.au and read the current event configuration and any prioritisation requirement before launch. Record the date and what Meta shows.]` Practical guard until verified: make `Lead` the highest-priority web event if a priority list is requested, because it is the only event the campaigns optimise for.
- Special ad category: none. OBSERVED 2026-10-02, https://developers.facebook.com/docs/marketing-api/audiences/special-ad-category: the four categories are housing, employment, financial products and services, and issues, elections and politics; every campaign still has to declare a category, and food or catering declares none.

## 6. Capturing the click and the source (the plugin work this plan depends on)

For a lead to be matched to the ad that earned it, the enquiry row must keep four things. Today it keeps none (section 2).

| Field | Where it comes from | Stored where |
| --- | --- | --- |
| `utm_source`, `utm_medium`, `utm_campaign`, `utm_content` | Landing URL query string (our convention: `meta`, `paid-social`, `<segment>-<offer>-<area>`, `<creative-variant>`) | Side table keyed by enquiry id |
| `fbclid` and the derived `fbc` cookie value | Landing URL and the `_fbc` cookie once the Pixel has loaded | Side table |
| `fbp` | `_fbp` first-party cookie set by the Pixel | Side table |
| Landing URL, referrer, consent version and time | Browser, sent in the enquiry POST body | Side table |

Approach from the WordPress audit: the companion script adds these values to the POST body of `/doughboss/v1/catering/enquiry`; PHP stashes them and writes them on the `doughboss_catering_enquiry_created` hook. `[CONFIRM with the plugin architect: the first-party cookie lifetime and where it is set, so the values survive a visit that continues over several sessions]`.

Rule: capture the values only when advertising and measurement consent is granted. If there is no consent, store nothing and send nothing.

## 7. Consent and privacy gates (the Privacy Act and the APPs)

Source: `docs/marketing/research/compliance-au.md` sections 6 and 10. Practical guidance, not legal advice. LAWYER to check the wording.

- The consent signal that switches the Pixel on, the browser event and the CAPI send must be the same flag, stored with the enquiry as a record (time, version, wording). The Pixel and the server must agree, otherwise CAPI becomes the back door around the cookie choice.
- The privacy policy must name Meta as a platform that receives data, say some of it is stored overseas, and say what is sent (event name, page, and hashed contact details if given). `[CONFIRM: policy published and current]`.
- No dietary text, event notes, names or free text in `custom_data` or any Pixel parameter. Dietary needs are sensitive; the enquiry form collects them as counts and categories, and they stay in the plugin.
- Hashing is not anonymising. Treat hashed email and phone as personal information.
- Spam Act: advertising consent is not email-marketing consent. A catering enquiry form must not silently opt the person into marketing emails. If a follow-up marketing list is wanted, it needs its own unticked, express opt-in with the sender named and a working unsubscribe.
- Customer list audiences (a separate matter from CAPI) follow `audiences.md` section 5.

## 8. Meta Pixel installation, minimal

1. Create the dataset (Pixel) inside Dough Boss's own Meta business portfolio. `[CONFIRM: portfolio and ID]`. No other business's pixel, page or ad account is used.
2. Set `DOUGHBOSS_META_PIXEL_ID` and `DOUGHBOSS_MARKETING_ENABLED` through the plugin configuration, with consent defaulting to off.
3. Standard `PageView` fires with consent. `Lead` fires per section 4. `Contact` and `FindLocation` fire per section 3.
4. Turn off any automatic advanced matching or automatic event detection that sends form field contents in the browser [VERIFY: setting names in Events Manager]. The server sends matching data on purpose, hashed, with consent.
5. Do not install a second copy of the Pixel through a plugin or theme setting as well as the bridge. Two installs double count. Check with the Meta Pixel Helper extension, which should report one Pixel and one `PageView` per page.

## 9. Quality signals from the plugin (the lead-quality loop)

A cheap lead and a good lead are different things. These server events teach Meta which leads became business. All are server-side, `action_source` per `[VERIFY: Meta's current guidance for CRM-stage events; the options in the server-event page include system_generated, other and phone_call]`.

| Plugin trigger | Meta event | `event_time` | Value | `event_id` |
| --- | --- | --- | --- | --- |
| Status becomes `quoted` | custom `QuoteSent` | Time of the status change | none | `quote-<uuid stored with the enquiry>` |
| Status becomes `deposit_paid`, `confirmed` or `paid` (first time only) | `Purchase` | Time of the status change | Real quoted total in AUD as a decimal, currency `AUD`, only when the amount is known and final [CONFIRM: use the quote total or the paid amount] | `won-<uuid stored with the enquiry>` |
| Status becomes `lost` | none | n/a | n/a | Not sent. Use it for internal reporting only. |

Matching: these events carry the same `user_data` (hashed email and phone, `fbc`, `fbp`) the plugin stored at enquiry time, with consent. Without `fbc` or `fbp` Meta can still try to match on hashed email or phone, with lower reliability [VERIFY: match quality shown in Events Manager].

Timing rule: `event_time` must be within 7 days of sending (section 4), so send on the status change. A nightly batch is acceptable only if it always runs within that window.

Optimising a campaign toward `Purchase` (won leads) or `QuoteSent` is a later step, only when the count of these events is large enough for Meta to learn from. `[CONFIRM: Meta's current minimum event volume for the chosen goal; read it in Ads Manager when staging]`. Until then every campaign optimises for `Lead` and we judge quality by the plugin's own funnel (03b section 10).

## 10. Square paid orders become server-side `Purchase` events (later phase)

Status: DESIGN ONLY. It depends on things that do not exist yet. Do not build it before the dependencies below are real, and do not assume any Square feature works until `docs/square/capabilities-au.md` says so and the one-shop pilot proves it. (Audit note, 2026-10-02: that file was not present when this plan was written; it now exists. Reconcile the dependencies below against it before any build.)

Depends on:

1. The plugin sending itemised orders to Square, Revesby pilot first (one shop), with the plugin remaining the canonical menu and order system.
2. A verified payment signal from Square back to the plugin (a webhook the plugin already validates for orders: `x-square-hmacsha256-signature` check, per the audit), or the shop marking a pay-at-shop order paid.
3. Attribution saved at checkout time. The audit says the checkout snapshot has no attribution and that a webhook-recovered order has no browser request, so the click ids must be stored with the order attempt at `/payment-intent` time. The cleanest route named in the audit is a `doughboss_checkout_snapshot_payload` filter, which is a core plugin patch (`needsFromLead`, not this slice).
4. Consented customer data. A hashed email or phone on a Meta event needs the customer's consent for advertising use and a policy that names Meta. Checkout today captures no marketing consent at all.

Design:

| Item | Rule |
| --- | --- |
| Trigger | Square payment confirmed as completed through the verified webhook, or a pay-at-shop order marked paid by staff. Never on cart, checkout start or order placed. |
| Event | `Purchase`, server only. No browser `Purchase`, which matches `events.ts`. |
| `event_id` | `sq-<square_order_id>` (stable across webhook retries, so a repeat delivery cannot double count). Browser and server do not both send, so deduplication is protection against our own retries. |
| `event_time` | Time the payment completed, within 7 days of sending. |
| `value` and `currency` | Order total in AUD as a decimal, from the Square order, after any refund adjustment policy [CONFIRM: net or gross of refunds and how a later refund is reported]. |
| `contents`, `content_ids`, `num_items` | From the itemised order lines, using plugin item slugs as ids, so Meta can optimise on the catalogue later. No customer text. |
| `action_source` | `website` for an order placed online and paid online; `physical_store` for a sale matched to a consenting customer in shop [VERIFY: Meta's guidance for matched in-store sales]. |
| `user_data` | Hashed email and phone, `fbc`, `fbp`, IP and user agent from checkout, only where consent is recorded. Otherwise send no `user_data` and expect no match, or send nothing. |
| Refunds | A refund does not remove a Purchase on Meta. Decide whether to report negative adjustments [CONFIRM] or accept slight overstatement. |
| Scope | One shop first, matching the Square pilot. Add shops only after the totals reconcile. |

Reconciliation test before trusting it: for two weeks compare, per day, the count and sum of Meta `Purchase` events against the Square order export for the same shop. A gap above a tolerance Elie sets (`[CONFIRM: tolerance]`) blocks any optimisation on `Purchase`.

Until this exists, orders that come from paid social are measured the manual way: the weekly won-enquiry list from the plugin (with `utm_*` and click ids from section 6) is the source of truth for catering. Walk-in and pickup sales are not attributable to ads and are not claimed.

## 11. Launch gate and QA checklist (Test Events)

No ad is switched on until every box is ticked, in this order. Meta's Test Events tool lists events received for a given test code. Meta generates a code each time the tab is opened, and the code must be removed from production payloads (OBSERVED for the removal rule, https://developers.facebook.com/docs/marketing-api/conversions-api/using-the-api, 2026-10-02: the `test_event_code` field "should be used only for testing. You need to remove it when sending your production payload.").

Setup

- [ ] Dataset created in Dough Boss's own portfolio; ID recorded in `campaigns.json` account block by a human.
- [ ] `doughboss.com.au` domain verified in the business portfolio.
- [ ] Privacy policy names Meta and overseas storage; cookie or consent banner live; consent defaults to off.
- [ ] `doughboss:consent` is dispatched by the consent banner and the bridge turns on only after a yes.
- [ ] Meta Pixel Helper shows exactly one Pixel and one `PageView` per page, and nothing before consent.

Browser

- [ ] With consent denied: submit a test enquiry. Test Events shows no browser event and no server event.
- [ ] With consent granted: landing on `/catering/office-breakfast` with the full UTM string fires `PageView` once.
- [ ] Submitting the catering form fires `Lead` once, with `content_name = catering_enquiry` and no free text, name, email or phone in any parameter (inspect the network request).
- [ ] The waitlist form fires `Lead` with `content_name = waitlist`, and the custom conversion separates it.
- [ ] `click_to_call` fires `Contact` once per tap; `get_directions` fires `FindLocation` once.

Server

- [ ] A `Lead` arrives from the server with `action_source = website` and the right `event_source_url` on the verified domain.
- [ ] Test Events shows the browser and server `Lead` as one deduplicated event: same `event_id`, same name. Record the screenshot date.
- [ ] Break it on purpose: send the server event with a different `event_id`; Test Events should show two events. Confirm you can recognise that failure.
- [ ] `user_data` is hashed (email, phone) and `client_ip_address`, `client_user_agent`, `fbc`, `fbp` are present and not hashed.
- [ ] The access token is not in the repository, logs, page source or the network tab.
- [ ] A forced server failure retries with the same `event_id` and does not create a second `Lead`.

Attribution and quality

- [ ] The test enquiry row in the plugin shows `utm_source = meta`, `utm_medium = paid-social`, the campaign and content slugs, `fbc`, `fbp`, consent version and time.
- [ ] Moving the test enquiry to `quoted` sends `QuoteSent` once; moving it to `confirmed` sends `Purchase` once with the right AUD value; moving it again sends nothing.
- [ ] Event Match Quality and the diagnostics tab show no critical errors. `[VERIFY: where Meta shows these in the current interface]`.
- [ ] A staff member received the test enquiry email and knows the agreed response time `[CONFIRM: response time]`.
- [ ] Test events deleted or marked as test internally so they do not pollute reports.

Sign-off: name, date and the screenshots kept with the launch checklist.

## 12. Reconcile with `docs/marketing/05-measurement.md` and `events.ts` when 05 lands

1. The neutral rename has landed: `events.ts` now has `coming_soon_view` and `waitlist_submit` (no product name), and the old product-specific pack-size event is gone. Confirm with 05 that `generate_lead` is the single catering lead event, that `waitlist_submit` is the single waitlist event, and that the leftover `form = waitlist` value on `generate_lead` is either removed from `events.ts` or documented as unused, so one sign-up can never be counted twice.
2. Confirm 05 describes the same server-created `event_id` flow, or change one of them. Two different id schemes would break deduplication.
3. Confirm the consent flag name and the single dispatcher event (`doughboss:consent` today).
4. Confirm the side-table fields in section 6 match 05 and the Google Ads plan (`marketing/google-ads/conversion-plan.md` uses the same `gclid` capture; `fbclid`, `fbc` and `fbp` should sit beside it).
5. Confirm the status mapping to Meta events (section 9) matches the Google offline imports (`QUOTED` and `WON` in that plan correspond to the plugin statuses `quoted` and `confirmed or paid`; the plugin has no status literally called `WON`).
6. `events.ts` lists `begin_checkout.payment_method` as `SQUARE | PAY_AT_SHOP`. It already reflects Square replacing Stripe, which section 10 assumes.
7. `track.ts` is a no-op stub today. The final dispatcher must call `fbq` with the `eventID` option and must not duplicate the bridge's own `Lead` mapping.

## 13. Needs from the lead and the plugin architect

- Side table for attribution and consent on the catering enquiry (section 6) and the REST body fields.
- The server-side event queue on `doughboss_catering_enquiry_created`, `doughboss_catering_status_changed` (section 9).
- The consent banner that dispatches `doughboss:consent`.
- A checkout snapshot filter for attribution on orders (section 10, core plugin patch).

## 14. Sources (all retrieved 2026-10-02)

- https://developers.facebook.com/docs/marketing-api/conversions-api/deduplicate-pixel-and-server-events (READ, via summarising fetch)
- https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/server-event (READ, via summarising fetch)
- https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/customer-information-parameters (READ, via summarising fetch)
- https://developers.facebook.com/docs/marketing-api/conversions-api/using-the-api (READ, via summarising fetch)
- https://developers.facebook.com/docs/sharing/domain-verification (READ, partial: it did not explain the conversion-event link)
- https://developers.facebook.com/docs/marketing-api/audiences/special-ad-category (READ, via summarising fetch)
- Not readable this session: Meta's AEM help page and the learning-phase page (title only returned). Nothing in this plan states AEM or learning-phase thresholds as fact.
