# DoughBoss completion status — 6 August 2026

Site: https://doughboss.com.au/

This checkpoint contains no passwords, API keys, signing secrets, card data, customer email addresses or complete voucher codes.

## Verified live state

- Canonical DoughBoss plugin: **2.33.5 active** at `doughboss/doughboss.php`.
- Database schema: **1.18.0 code and stored versions match**.
- Public ordering: **OFF**.
- Card payments: **OFF**.
- Selected gateway: **Stripe**.
- Selected Stripe mode: **Live**.
- Stripe server readiness: **not ready**. Live payments remain fail-closed.
- Stripe webhook recovery: **configured**.
- POSPal integration readiness: **configured**; real till-side grant/use/revoke acceptance remains external.
- Tyro, Mercure, ntfy, SMS and printer readiness: **not configured**. They are optional unless management selects them for launch.
- Public `/`, `/order/` and `/student-vouchers/` routes return HTTP 200.
- Logged-out Kitchen MAKE/PASS, Catering, Management and Voucher Scan requests redirect to sign-in.
- Named DoughBoss Manager and DoughBoss Kitchen accounts exist with their separate least-privilege roles. Passwords are not recorded here.

## Student voucher and QR setup completed

- The offer is **one $5 voucher per eligible verified student email across the student allocation**, including legacy student-campaign claims in the same allocation group.
- The daily allocation cap remains **100**, so claims stop safely when that day's allocation is exhausted.
- Each accepted claim creates a single-use voucher and displays its own self-hosted QR code.
- The customer voucher QR encodes only the voucher code. It does not expose account credentials or a staff URL.
- Kitchen/till users can scan the personal QR from the protected Voucher Scan screen or type the code manually.
- Redemption is atomic: the first valid online or staff redemption consumes the voucher and later attempts fail.
- The public claim page is https://doughboss.com.au/student-vouchers/.
- A permanent promotional QR was generated with high error correction and a four-module quiet zone. It decodes to the canonical HTTPS claim page.
- WordPress Media attachment **483** is the 1240 × 1754 A4 poster:
  https://doughboss.com.au/wp-content/uploads/Dough-Boss-Student-Voucher-QR-Poster-%E2%80%93-A4.png
- WordPress Media attachment **484** is the 1080 × 1080 standalone code:
  https://doughboss.com.au/wp-content/uploads/Dough-Boss-Student-Voucher-QR-Code.gif
- Print masters are committed in `docs/brand/` as PNG, GIF and scalable SVG files. Their generator and automated contract are committed with the release branch.

## Dining-table QR status

The secure dining-table QR feature is installed, but **zero physical table records currently exist**. This is intentional until the Revesby floor labels are confirmed.

Do not generate labels from the 1–12 template unless those numbers physically exist. Every issued code is a bearer link permanently bound to one store and one table; the raw code is shown only once and must be printed or saved immediately.

Management must supply:

1. Whether Revesby is offering dine-in table ordering at launch.
2. The exact physical table labels, for example `1`, `2`, `Window 1`.
3. The zone name, if used.
4. Confirmation that each printed label has been attached to the matching table and tested on iPhone and Android.

If DoughBoss has no customer dining tables, table QR ordering should remain unconfigured. The student-voucher QR system is independent and already complete.

## Work that still requires external evidence or management authority

1. **Stripe Live server readiness:** place or correct the protected Live secret key in hosting so `/doughboss/v1/status` reports Stripe ready. Never put the value in chat or source control.
2. **Student inbox acceptance:** use one owner-controlled eligible `.edu` or `.edu.au` address; confirm one claim, one received email and one personal QR. This consumes a real allocation and cannot be completed responsibly without that inbox.
3. **Till voucher acceptance:** scan the issued test voucher on the shop device, confirm the first redemption succeeds and a repeat fails.
4. **Controlled Stripe acceptance:** while still in Test mode, prove hosted Checkout, signed webhook recovery, duplicate-return protection, declined-card retry, 3-D Secure and refund idempotency. Then perform the separately approved small Live test.
5. **Wallet devices:** confirm Apple Pay on eligible Apple hardware, Google Pay on eligible Android/Chrome hardware and card fallback elsewhere.
6. **POSPal:** verify one real mapped item and voucher across grant, order, use/revoke, retry and till totals. POSPal being configured is not the same as this business-process acceptance.
7. **Kitchen hardware:** sign the Lenovo touch monitor into Kitchen MAKE/PASS, test touch USB and Ethernet, sound/visual alerts and a peak-hour rehearsal. Test the compact catering screen on its own display.
8. **Legal and management approval:** approve the final customer terms, privacy wording, menu/prices/allergens, promotion dates/allocation and staff operating procedure.
9. **Final backup:** after all acceptance evidence passes, take and verify the postponed files-and-database backup immediately before management authorises go-live.
10. **Go-live authority:** only then enable card payments, enable orders and monitor the first real orders end to end.

## Current safe conclusion

The website, menu, protected staff workspaces, student-voucher allocation logic, personal voucher QR generation, till scanner code, promotional QR pack and repository are implemented. Public ordering and card payments must remain off because Stripe server readiness is still false and the physical/external acceptance items above have not yet produced evidence.
