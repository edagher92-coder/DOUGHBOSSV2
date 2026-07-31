# DoughBoss WordPress Live Acceptance Report

Date: 26 July 2026  
Scope: Revesby controlled acceptance while the public migration gate remains enabled  
Release: DoughBoss 2.25.2  
Source commit: `0de5bbf9f7a51fd7db9a3baf0443ed3b6d8ef1c5`

## Release evidence

- WordPress confirms DoughBoss plugin version 2.25.2 is installed.
- GitHub Plugin CI run 30192724437 completed successfully for the exact source commit.
- The release passed PHP/JavaScript validation, WordPress 6.0.9 and 7.0.2 boot/install tests, PHP 7.4 through 8.5 coverage, MariaDB 10.6 and 11.4 lifecycle/integrity tests, secret scanning and ZIP validation.
- Installed ZIP SHA-256: `BA492B3F6D9A25A91835E9E9C1CE4ED66A6036B459FF670105CC876634CA162C`.
- The repository worktree was clean after publication.

## Mobile and responsive acceptance

The live WordPress ordering surface was checked at 320, 360, 390, 768 and 1280 CSS pixels.

Passed:

- no document-level horizontal overflow;
- the order application remains inside the viewport;
- menu category controls meet the 44-pixel touch-target baseline;
- all 27 menu imagery backgrounds load;
- menu order remains Manoush, Pizza, Pies, Wraps, Desserts and Drinks;
- no literal escaped newline text is shown;
- fulfilment and voucher rows stack safely on narrow phones;
- the cart control remains reachable without covering the page;
- tracking stages remain readable on narrow phones;
- catering cards cannot force the page wider than the viewport;
- the kitchen board renders at phone width without horizontal overflow;
- kitchen Retry, sound and Acknowledge controls are at least 44 pixels high.

The mobile fixes also contain the active legacy WordPress theme shell on the Order page, rather than relying on a future theme replacement.

## Mastercard payment acceptance

Sandbox order: `DB-260726-GOALZO`  
Amount: AUD $19.50  
Result: Passed

Verified:

- the browser received only a short-lived Hosted Checkout session;
- card number and security code were entered only on the Mastercard-hosted secure page;
- the gateway returned to WordPress;
- WordPress reconciled the result as paid;
- exactly one DoughBoss order was created;
- the paid amount appeared in the owner Orders screen;
- the order appeared once on the live kitchen board;
- the customer tracking lookup returned the order and showed it as paid.

## Visa payment acceptance

Sandbox order: `DB-260726-LOIRWP`  
Amount: AUD $19.00  
Result: Passed

Verified:

- the Visa test card was accepted through the same MPGS Hosted Checkout;
- WordPress reconciled the result as paid;
- exactly one DoughBoss order was created;
- the order appeared once on the live kitchen board;
- owner totals reconciled to two paid-card orders and AUD $38.50 across the Mastercard and Visa tests.

These were sandbox transactions. Production card acceptance must remain blocked until Tyro issues and approves the live merchant credentials and the owner explicitly switches from Test to Live.

## Kitchen and customer tracking

Passed:

- both paid test orders appeared on the KDS with customer, item, modifier, pickup and paid status details;
- the board showed two active orders and two paid indicators;
- the correct order-number and email pair returned the expected customer status and paid amount;
- the same order number with a different email returned the generic not-found response and disclosed no customer or order detail;
- the KDS phone layout had no horizontal overflow.

The test orders are clearly marked “sandbox … do not prepare.” They should be cancelled or closed during staff rehearsal, and sandbox payments can only be refunded from the Mastercard gateway test portal.

## Voucher and POSPal evidence

Passed:

- the active public campaign is the $5 Dough Boss student voucher;
- the $10 campaign is not active;
- an invalid voucher is rejected without changing the total;
- the Revesby read-only POSPal verifier connected successfully;
- two POSPal coupon rules were found;
- the configured Revesby $5 coupon-rule UID matched a live POSPal rule.

Still required before voucher launch:

- run one authorised $5 campaign claim against a real test/customer member phone;
- confirm the coupon appears at the Revesby till;
- redeem it once and verify a second redemption is refused;
- cancel a payment after voucher reservation and verify the voucher is released.

This step creates real promotional value and therefore needs an authorised phone/member record. It was not simulated or fabricated.

## QR and table ordering

The WordPress QR system is installed and the administration screen correctly limits new table QR codes to Revesby. It binds each code to a store and table on the server.

Current data gate:

- there are no Revesby dining-table records yet;
- the actual table labels/numbers and optional zones have not been supplied.

No production table numbers were invented. Once management supplies the Revesby table list, create one permanent row per table, print the generated QR, then run rotation and wrong/expired-code tests from the acceptance runbook.

## Email evidence

The application generated the successful on-screen receipt and tracking instructions for both paid orders. The code calls WordPress mail for the customer and shop confirmations.

Delivery is not yet acceptance-proven:

- no matching confirmation was visible in the connected Gmail mailbox;
- native `wp_mail()` reports only hand-off to the site mailer and cannot prove inbox delivery;
- stage emails remain separate from initial receipts and must be exercised during the staff status rehearsal.

Before public launch, configure a monitored transactional SMTP/mail provider, enable delivery logging and run customer plus management-inbox receipt tests.

## POSPal order push boundary

POSPal coupon verification is healthy, but online order mirroring is intentionally not live:

- “Push online orders” remains off;
- the menu-to-POSPal product map is empty;
- unmapped items fail closed and are not pushed;
- Bankstown and Roselands operational credentials remain blank/dormant.

Do not enable POSPal order push until the Revesby catalogue is loaded, every sold item is reviewed and mapped, and one controlled order proves exactly-once creation at the till.

## Page parity and legal/content gates

The detailed page matrix is in `DoughBoss-WordPress-Demo-Parity-Audit-2026-07-26.md`.

Known owner-review items:

- the live Terms content still contains stale Baked & Co wording;
- the Privacy Policy contains a spelling error and an incorrect `dougboss.com.au` email;
- legal wording must be approved before it is changed or published.

## Release decision

Status: **Controlled acceptance passed for mobile ordering, Mastercard, Visa, WordPress order creation, KDS delivery and customer tracking. Public launch remains blocked.**

Keep the migration gate enabled until all of the following are complete:

1. monitored customer/shop email delivery passes;
2. Revesby table numbers are supplied and QR acceptance passes;
3. one authorised $5 voucher/POSPal till lifecycle passes;
4. Revesby POSPal products are mapped and exactly-once order mirroring passes, if POS order push is required for launch;
5. staff accounts are created as named, least-privilege users and the staff rehearsal completes;
6. owner/legal review approves the Terms and Privacy corrections;
7. Tyro provides live merchant credentials and the owner approves the Test-to-Live switch.
