# Review short links and QR plan

DRAFT. No link has been created and no QR code has been printed. Prepared 2026-10-02 for Elie.

## 1. What we need per store

One Google review link per store. We do not hold the Place IDs, because the profiles are not claimed yet.

| Store | Place ID | Review link (full) | Short link on our domain | Redirect target |
|---|---|---|---|---|
| Revesby (12/25 Selems Parade) | [CONFIRM: Place ID] | https://search.google.com/local/writereview?placeid=[CONFIRM: Place ID] | https://doughboss.com.au/r/revesby | The store's Google review link |
| Bankstown (462 Chapel Rd) | [CONFIRM: Place ID] | https://search.google.com/local/writereview?placeid=[CONFIRM: Place ID] | https://doughboss.com.au/r/bankstown | The store's Google review link |
| Roselands Centro (Shop MM03, Roselands Dr) | [CONFIRM: Place ID] | https://search.google.com/local/writereview?placeid=[CONFIRM: Place ID] | https://doughboss.com.au/r/roselands | The store's Google review link |

## 2. How to find each Place ID and the review link

Do these only after the store's profile is claimed and verified (docs/marketing/02-seo-external.md, task A1).

1. Preferred route. Sign in to the store's profile, and use the profile's own "ask for reviews" or "get more reviews" option to copy the share link or download the QR code. Google says businesses can ask customers to visit a Google link or scan a QR code (https://support.google.com/business/answer/3474122, retrieved 2026-10-02). The menu wording changes over time: [CONFIRM] the current label in the dashboard.
2. Fallback route. Use Google's Place ID Finder (https://developers.google.com/maps/documentation/javascript/examples/places-placeid-finder, linked from https://developers.google.com/maps/documentation/places/web-service/place-id, retrieved 2026-10-02). Search for the shop's exact name and address, click the pin, and copy the Place ID. Then build the link as https://search.google.com/local/writereview?placeid=<PLACE_ID>. This format is widely documented by third parties but is not on Google's own help page, so open it on a phone and confirm it shows the right shop and a review box.
3. Google notes the same location can have more than one Place ID and IDs can change, and recommends refreshing IDs older than 12 months. Re-test each link every six months. That interval is our own rule.
4. Record the three IDs in marketing/nap.json under each store's listing object as placeId. Do not guess one.

## 3. Short-link plan

- Use our own domain: https://doughboss.com.au/r/<store>. A short link on our domain looks trustworthy, survives a change of review URL, and does not depend on a third-party shortener that could expire or be sold.
- Each short link is a plain 302 redirect to the store's review link. It is not a landing page and does not ask a rating question first, because that would be review gating.
- Where the redirect lives: a redirect rule on the existing WordPress site (a redirect plugin or a server rule). [CONFIRM: which redirect plugin or host rule Elie's team uses. This is a small change a developer can make; it is not done here.]
- Placement tracking: Google's review link cannot carry UTM parameters, so measure with separate short links per placement, for example /r/revesby-card, /r/revesby-counter, /r/revesby-email. The redirect tool's click log is the measure. Do not invent a target rate. Set a baseline after four weeks and decide then.
- Never use a link that sends only some customers to Google.

## 4. QR code plan

- Generate a static QR code that encodes the short link on our domain, not the long Google link. Then the printed material never needs to change when a Place ID changes.
- Use a static code, not a subscription-based dynamic one. A dynamic code from a paid service stops working when the plan lapses.
- Print rules (good practice, test before printing): high contrast dark on light, a clear blank margin around the code, no logo covering the middle, and a size that scans easily from the counter distance. Print a test card and scan it with an iPhone and an Android phone before ordering a run.
- Label the code in words: "Scan to write a Google review of Dough Boss <store>". The label must match the store the code opens.
- Placements: counter sign beside the till, the order card handed over with the food, and inside catering boxes. Do not put review QR codes on staff uniforms or on any surface that makes staff chase customers.
- Keep one master file per store and per placement, named doughboss-<store>-review-qr-<placement>.png, in the shared marketing folder.

## 5. Test checklist

- [ ] The short link opens the correct shop's review box on an iPhone and an Android phone, signed in and signed out.
- [ ] The printed QR scans from the counter distance in shop lighting.
- [ ] The three links point to three different shops (check the shop name shown on the review screen).
- [ ] The redirect log records a click when tested.
- [ ] Nothing on the card, link or QR mentions a reward.
