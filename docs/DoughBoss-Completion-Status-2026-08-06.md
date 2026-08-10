# DoughBoss launch acceptance checkpoint — 6 August 2026

Site: https://doughboss.com.au/

This checkpoint contains no passwords, API keys, signing secrets, card data, customer email addresses, complete voucher codes or complete payment-provider references.

## Executive result

All safe server-side work available from this workstation has been completed. The public website remains visible, while **Accept orders is OFF** and **Accept card payments is OFF**. No live payment was attempted and no real voucher was consumed.

The launch is **not yet approved**. The remaining gates require a management-controlled student inbox, the physical till and wallet devices, the purchased kitchen hardware, legal/management sign-off, credential rotation and a final backup. These are evidence requirements, not unfinished coding tasks.

## Verified production state

- Canonical DoughBoss plugin: **2.33.5 active** at `doughboss/doughboss.php`.
- Database schema: **1.18.0 code and stored versions match**.
- Selected gateway: **Stripe**.
- Selected Stripe mode after testing: **Live**.
- Public ordering after testing: **OFF**.
- Card payments after testing: **OFF**.
- POSPal integration reports ready.
- Tyro, Mercure, ntfy, SMS and printer integrations report not ready; these are optional unless management selects them.
- The public home, order and student-voucher pages return HTTP 200.
- Logged-out Kitchen MAKE/PASS, Catering, Management, Voucher Scan and their protected data routes require staff authentication.
- The Migration Gate and the separate Maintenance plugin are currently **inactive**. Safety is presently provided by the two DoughBoss ordering/payment switches, not by a public maintenance screen.
- UpdraftPlus is active, but the final pre-launch files-and-database backup remains intentionally postponed until the external acceptance gates pass.

## Stripe and hosted Checkout evidence

### Completed

- Test mode temporarily reported Stripe and webhook readiness while card payments were enabled for the controlled check.
- A fresh server-side Test request created one **A$3.50 AUD Stripe-hosted Checkout Session** with a `cs_test_` reference.
- The session was bound to one durable payment-attempt row and returned an unpaid/processing state, as expected before a customer enters payment details.
- Repeating the same payment-preparation request reused the existing durable attempt. Only one new attempt row was created, proving creation idempotency at the server boundary.
- The implementation validates that the redirect is HTTPS on `checkout.stripe.com`, binds amount, currency, location, cart snapshot and checkout key, and suppresses provider errors from the storefront.
- Hosted Checkout is configured with Stripe's card payment method. On eligible devices Stripe can present Apple Pay and Google Pay automatically; card remains the fallback.
- Automated contracts passed for hosted Checkout, durable webhook recovery, duplicate-return protection, wallet eligibility and the kitchen loop.

### Still requires external evidence

- Complete one successful hosted Test payment in a real browser.
- Confirm the signed webhook creates/reconciles exactly one order when the browser return is not used.
- Confirm a declined-card retry, a 3-D Secure challenge, duplicate return, and an idempotent refund.
- Confirm Apple Pay on eligible Apple hardware and Google Pay on eligible Android/Chrome hardware.
- After every Test check passes, management must separately approve one small Live payment and refund.

The `/doughboss/v1/status` Stripe boolean is false while card payments are off because readiness deliberately includes the payment switch. It must not be interpreted by itself as proof that a key is absent. A fresh Test Checkout Session proves the Test server credential was accepted by Stripe. Live key and live webhook acceptance still require the separately approved Live test.

## Security action required before launch

A privileged diagnostic confirmed that provider credentials are stored inside the serialized WordPress settings option, rather than only in protected host configuration. Because administrative tooling rendered those values during the audit, management must treat them as exposed.

Before launch:

1. Rotate both Stripe secret keys and both webhook signing secrets.
2. Rotate the POSPal application keys and the retained Mastercard/MPGS test password.
3. Put the replacement secrets in protected hosting constants/environment values.
4. Clear the database fallback secret fields only after the host values are confirmed working.
5. Restart/recycle the PHP worker and re-run Test readiness, POSPal handshake and webhook verification.

Never paste replacement values into chat, source control, documents or screenshots.

## Student voucher and QR evidence

### Completed

- The offer is **one $5 voucher per eligible verified student email across the full student allocation period**, including legacy student-campaign claims in the same allocation group.
- The daily allocation cap remains **100**.
- The public claim page is live at https://doughboss.com.au/student-vouchers/.
- An ineligible non-student email was rejected with HTTP 422 and no voucher was issued.
- A mismatched confirmation email was rejected with HTTP 422 and no voucher was issued.
- The staff voucher activity and scan APIs rejected anonymous requests with HTTP 403.
- Each accepted claim generates one personal, single-use voucher and a self-hosted personal QR.
- Redemption logic is atomic: the first valid redemption wins and a later attempt fails.
- The permanent promotional QR decodes to the canonical HTTPS claim page and uses high error correction with a four-module quiet zone.
- A4 poster: https://doughboss.com.au/wp-content/uploads/Dough-Boss-Student-Voucher-QR-Poster-%E2%80%93-A4.png
- Standalone QR: https://doughboss.com.au/wp-content/uploads/Dough-Boss-Student-Voucher-QR-Code.gif

### Still requires the owner-controlled inbox and till

- Claim once using one eligible owner-controlled `.edu` or `.edu.au` inbox.
- Confirm exactly one email and one personal QR arrive.
- Scan that voucher on the shop device, confirm first redemption succeeds, and confirm the immediate repeat fails.

This consumes a real allocation and cannot be completed safely without the chosen inbox and the physical till.

## POSPal evidence

### Completed

- POSPal accepted the configured signature in a fresh read-only handshake.
- Two live coupon rules were returned and the configured $5 coupon UID matched the live `$5 off` rule.
- The live product query returned **135 products**.
- One exact, inert product mapping was saved: DoughBoss **Cheese, $9.50** to POSPal **Cheese, $9.50**.
- The stored mapping was verified against the exact provider UID.
- POSPal order pushing remains **OFF**, so the new mapping cannot send an order unexpectedly.

### Still requires the till

- Grant one controlled voucher to the agreed test customer.
- Place one mapped Cheese order, verify the provider/till total, and exercise retry without duplication.
- Redeem/use and revoke the controlled voucher, then confirm till totals and audit history.

These operations alter real POSPal customer, voucher or till state and require the physical operator and a management-approved test identity.

## Kitchen, catering and management evidence

### Completed

- The authenticated identity used for the audit has management, board and voucher-redemption access.
- The live kitchen order feed responded successfully and currently contained no active orders.
- The morning preorder queue responded successfully and currently contained no requests.
- Kitchen MAKE/PASS, compact Catering and Management portals retain authentication, shop scope, touch-sized controls, stale-write protection, visible/audible alert code and polling fallback.
- Automated portal and KDS contracts passed.

### Important finding

The public catering-package feed currently returns **zero published packages**. Management must confirm whether launch catering is enquiry-only or provide the approved package names, inclusions, headcounts and prices before catering checkout can be accepted.

### Physical acceptance required

- Sign the purchased Lenovo touch monitor into Kitchen MAKE and PASS.
- Connect HDMI/DisplayPort for video, USB upstream for touch and Ethernet for the PC.
- Confirm touch accuracy, full-screen scaling, audio/visual alerts, sleep/wake recovery and a peak-hour rehearsal.
- Test the compact catering display independently on the 15-inch screen.

## Dining-table QR status

The secure dining-table QR feature is installed, but there are **zero physical table records**. Do not issue table codes until management confirms dine-in is offered and supplies the exact physical labels and zone. The student-voucher QR is independent and already complete.

## Legal and management review

The current customer legal pages exist, but management/legal approval cannot yet pass:

- The Terms page still names **Baked & Co** in its liability section and refers to a Returns Policy that is not linked from that wording.
- The Privacy page contains a misspelled contact address/domain and wording copied from a generic ecommerce/GDPR template.
- Privacy wording must accurately describe the systems actually used: Stripe, POSPal, WordPress/hosting, SMTP email, order/kitchen records, student-email eligibility, analytics/advertising only when enabled, retention, overseas disclosure, access/correction and complaints.
- Menu prices must be approved as GST-inclusive totals and must match the till. Any mandatory surcharge must be disclosed in the total-price flow.
- Management must approve the allergen matrix and the staff response procedure. Dietary labels are not a substitute for accurate allergen information or cross-contact handling.
- Management must approve promotion dates, the daily allocation of 100, one-per-student-period wording and the staff voucher procedure.

Official review references:

- OAIC APP 1 privacy policy guidance: https://www.oaic.gov.au/privacy/australian-privacy-principles/australian-privacy-principles-guidelines/chapter-1-app-1-open-and-transparent-management-of-personal-information
- ACCC price display guidance: https://www.accc.gov.au/business/pricing/price-displays
- NSW Food Authority allergen rules: https://www.foodauthority.nsw.gov.au/industry/food-allergen-rules

The current legal copy should not be represented as finally approved until management and an Australian legal adviser have reviewed it.

## Final launch sequence

1. Rotate and relocate credentials, then re-run provider readiness.
2. Complete student inbox and till voucher acceptance.
3. Complete Stripe Test, webhook, decline, 3-D Secure, duplicate and refund acceptance.
4. Complete Apple Pay, Google Pay and card-fallback checks on real devices.
5. Complete POSPal mapped-item and voucher lifecycle acceptance.
6. Complete kitchen and catering hardware rehearsal.
7. Approve legal copy, allergens, menu/prices, promotion and staff procedures.
8. Take and verify the postponed full files-and-database backup.
9. Obtain explicit management go-live authority.
10. Enable card payments, enable orders and monitor the first real orders from checkout through payment, webhook, POSPal and Kitchen MAKE/PASS.

## Current safe conclusion

The server-side hosted Checkout preparation, idempotency, POSPal connection, exact first product mapping, protected staff feeds, student-voucher eligibility rejection, QR pack and code contracts are verified. The store must remain closed to orders and card payments until credential rotation and the physical/management acceptance evidence above are complete.
