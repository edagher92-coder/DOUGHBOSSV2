# DoughBoss Customer Notifications and Tracking Handoff

Date: 24 July 2026  
Release: plugin `2.23.2`, database `1.16.0`  
Scope: code-ready and acceptance-ready; not proof of the installed production WordPress version.

## Decision

Use email and self-service tracking together:

1. Send an immediate order-received email after the durable order is created.
2. When enabled, send an accepted email with the kitchen ETA.
3. When enabled, send a ready email with pickup, table-service or delivery wording.
4. Give every customer an order number and a **Track My Order** page.
5. Do not email every internal kitchen transition.

This gives the customer reassurance without notification overload. The tracker remains the current source of truth if an email is delayed.

## Implemented

- Added a configurable first-party **Track My Order page** URL under **DoughBoss → Settings → Store**.
- Added `{tracking_url}` and `{tracking_instructions}` to confirmation, accepted and ready message templates.
- Added the configured tracking link to the checkout response and on-screen confirmation.
- Kept the customer email out of the email link; only the order number is prefilled.
- Changed the actual tracking lookup to `POST /wp-json/doughboss/v1/order/track`.
- Sends the matching email in the JSON body, not a URL query string.
- Requires the storefront WordPress nonce.
- Marks tracking responses `Cache-Control: no-store, private` and `Referrer-Policy: no-referrer`.
- Rejects external tracking-page URLs so order numbers cannot be disclosed to another host through a configuration mistake.
- Keeps the same not-found response for an unknown order and a wrong email to reduce enumeration.
- Continues tracking polling for meaningful live status changes and stops at completed or cancelled.
- Adds an atomic short-lived dispatch lock around accepted/ready email delivery to suppress concurrent duplicate workers.
- Preserves existing replay protection for the immediate checkout confirmation and stage replay protection for accepted/ready emails.

## Customer flow

```text
Customer checkout
    ↓
WordPress validates cart, fulfilment, location and payment state
    ↓
Durable order created
    ├─ immediate order-received email
    ├─ on-screen order number and Track this order action
    └─ pending order appears in Revesby KDS
            ↓
Kitchen accepts and chooses ETA
    ├─ tracker changes
    └─ accepted email when enabled
            ↓
Kitchen marks Ready
    ├─ tracker changes
    └─ ready email when enabled
            ↓
Collected / served / delivered
    └─ tracker becomes complete and stops polling
```

## Management setup

1. In WordPress, create a page named **Track My Order**.
2. Add a Shortcode block containing `[doughboss_order_tracking]`.
3. Publish it on the same WordPress site.
4. Copy the page URL.
5. Open **DoughBoss → Settings → Store**.
6. Paste it into **Track My Order page** and save.
7. Open **Real-time & Notifications** and deliberately choose whether accepted and ready emails are enabled.
8. Review the three customer email templates.
9. Configure an authenticated transactional mail provider.
10. Verify SPF, DKIM and DMARC.
11. Place a no-payment acceptance order and prove delivery to at least Gmail and Outlook.

## Staff usage

1. Sign in with the staff member’s own restricted account.
2. Open **DoughBoss → Order Board**.
3. Confirm the board is connected and audible.
4. Read the entire order before accepting.
5. Accept it and select an honest ETA.
6. Move the order only when the food moves.
7. Mark Ready only when it can be handed over.
8. Complete only after collection, table service or delivery handoff.

If the board says Offline, staff must stop acting on stale cards, retry once, then reconcile from **DoughBoss → Orders**. Never recreate or recharge a paid order.

## Current rollout boundary

- Revesby is the only location intended for online ordering.
- Bankstown and Roselands are operating/baking locations but remain visit-or-call for this rollout.
- Tyro remains dormant until credentials, sandbox acceptance, webhook validation, certification/onboarding and a live low-value transaction are completed.
- POSPal ambiguous outcomes remain quarantined for operator review and must not be blindly resent.

## Acceptance evidence

- PHP syntax: `66/66` passed.
- Customer-to-KDS lifecycle contract: `12/12` passed.
- Customer notification/tracking contract: `14/14` passed.
- Tyro offline contract: `38/38` passed.
- Provider readiness contract: `9/9` passed.
- POSPal outbox behavioural contract: `9/9` passed.
- Plugin boot smoke test: `170/170` passed.
- JavaScript syntax: passed.
- Desktop visual guide: all images loaded, no horizontal page overflow.
- Mobile visual guide: 375 px viewport, no page-wide overflow; wide tables stay inside labelled horizontal scroll regions.

## External go-live gates

Code tests cannot prove any of the following:

- the production WordPress plugin is installed at `2.23.2`;
- Revesby `ordering_open` is intentionally enabled;
- production hours, menu, sold-out state and pickup settings are correct;
- authenticated mail reaches real inboxes;
- Tyro credentials and certification are accepted;
- payment webhooks reach the production WordPress URL;
- a real checkout reaches KDS, tracking, mail and reconciliation correctly.

These checks must be completed on the actual WordPress host before the system is called live.

## Guide

Use `DoughBoss-Staff-Management-Quick-Guide-2026-07-24.html` for screen viewing and `DoughBoss-Staff-Management-Quick-Guide-2026-07-24.pdf` for printing or sharing. Both include marked-up tracking, KDS and management illustrations.
