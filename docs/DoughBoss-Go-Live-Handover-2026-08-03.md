# DoughBoss 2.33.2 go-live handover

Date: 3 August 2026

Site: https://doughboss.com.au/

Document status: the 2.33.2 release is installed and the site is in a safe, closed state. Public ordering and card payments remain intentionally OFF while the final acceptance work is completed.

This document contains no passwords, API keys, webhook signing secrets, card data, personal contact details or complete voucher codes.

## Current live state

The latest independently confirmed state is:

- Canonical DoughBoss plugin: **2.33.2 active**.
- DoughBoss database schema: **1.18.0**.
- Payment gateway: **Stripe**.
- Stripe selector: **Live**.
- Live publishable key: present.
- Live webhook signing secret: the settings page indicates that a secret is set.
- Live secret-key readiness: **not confirmed**. The Live secret-key row has no configured/readiness indicator, so live payments must remain fail-closed.
- Accept orders: **OFF**.
- Accept card payments: **OFF**.
- DoughBoss Migration Gate: **OFF**, after the safe state was independently restored and checked.
- Toolset Types 3.4.2: **inactive and retained** with its legacy data.
- Public ordering: browse-only/Coming Soon.

Do not switch on public ordering or live card payments until the Live secret-key readiness indicator passes and the remaining acceptance evidence in this handover is complete.

## Release record

| Item | Verified value |
| --- | --- |
| Live DoughBoss plugin | 2.33.2 |
| Database schema | 1.18.0 |
| Source branch | `codex/final-visual-polish` |
| Source commit | `16f2c4f9eee17d51170527034b304274ce3b8edd` |
| Release | [v2.33.2-rc.1](https://github.com/edagher92-coder/DOUGHBOSSV2/releases/tag/v2.33.2-rc.1) |
| Installation ZIP | [doughboss.zip](https://github.com/edagher92-coder/DOUGHBOSSV2/releases/download/v2.33.2-rc.1/doughboss.zip) |
| ZIP size | 7,735,635 bytes |
| ZIP SHA-256 | `22987e2a059df55e87aac34611c31cad70b190b7651c4577972e524fe0441b50` |
| Pull request | [PR #56](https://github.com/edagher92-coder/DOUGHBOSSV2/pull/56) |
| Green CI | [Build 30803584886](https://github.com/edagher92-coder/DOUGHBOSSV2/actions/runs/30803584886) and [build 30803582269](https://github.com/edagher92-coder/DOUGHBOSSV2/actions/runs/30803582269) |

The release ZIP is the canonical installable package. Do not use an older locally named `doughboss.zip`, because WordPress may offer to replace 2.33.2 with an earlier version.

## Customer-facing release

- The customer site and ordering catalogue use 34 distinct, high-resolution square WebP menu images rather than repeated placeholder photos.
- The Meat image is brighter and presented as properly oven-baked rather than burnt.
- Dough Boss Special was renamed **Sujuk Special**.
- **Zaatar & Cheese** remains a separate product, not an add-on.
- Current customer copy uses **oven-baked**. Stone-baked and wood-fired wording was removed from the active customer-facing release assets; historical briefs and prototypes may still retain old wording for audit context.
- The home food scene and replay control were refined. Normal-motion devices can replay the food movement; reduced-motion preferences remain respected for accessibility.
- The menu is searchable and grouped into Manoush, Pizza, Pies, Wraps, Desserts and Drinks.
- When ordering is closed, customers can still browse the menu and see Coming Soon rather than reaching an incomplete checkout.

## Private kitchen, catering and management workspaces

The staff interfaces are hosted on the main DoughBoss domain. They are not GitHub Pages, ordinary WordPress admin pages or public menu links.

| Workspace | Canonical URL | Minimum role |
| --- | --- | --- |
| Kitchen MAKE | https://doughboss.com.au/kitchen/ | DoughBoss Kitchen |
| Kitchen PASS | https://doughboss.com.au/kitchen/?screen=pass | DoughBoss Kitchen |
| Catering production | https://doughboss.com.au/catering-kitchen/ | DoughBoss Kitchen |
| Management | https://doughboss.com.au/management/ | DoughBoss Manager |
| Sign-in | https://doughboss.com.au/wp-login.php | Individual staff account |

`/kitchen/?screen=catering` remains a backward-compatible bookmark, but `/catering-kitchen/` is the canonical catering URL.

The three staff workspaces are absent from public navigation and require WordPress authentication, the appropriate DoughBoss capability, assigned-shop scope and REST nonces. Logged-out requests redirect to sign-in and return to the requested workspace after authentication. Their responses use no-index, no-cache and anti-framing protections.

Use named staff accounts with unique passwords held in the organisation's password manager. Do not place a shared administrator account on the kitchen computer. The Kitchen role is for production screens and voucher scanning; the Manager role is for orders, reporting, settings, vouchers and recovery.

### Kitchen hardware use

- Use the main MAKE workspace full-screen in Chrome on the 23.8-inch Lenovo touch monitor at 1920 x 1080 and 100% scaling.
- Use the smaller display for `/catering-kitchen/` when catering production is required.
- Each touchscreen needs both a video path and its own USB data path for touch input.
- Keep the mini PC on Ethernet as the primary order-feed connection, with Wi-Fi only as fallback.
- Confirm the board connection and sound indicators at the start of every service.

## Stripe checkout architecture

DoughBoss 2.33.2 uses a per-order **Stripe-hosted Checkout Session**:

- WordPress calculates the authoritative cart, discount, GST, location and fulfilment data on the server.
- Card and wallet details are collected on Stripe's hosted page, not by WordPress.
- Apple Pay and Google Pay appear automatically when Stripe, the account, domain, browser, device and customer wallet are eligible. Card entry remains the fallback.
- A durable, immutable payment attempt is reused for safe retries. Amount, currency, cart, location, fulfilment and checkout identity are verified before order creation.
- Signed webhooks recover a paid order if the customer closes the browser before the return page finishes.
- Return-page replay and webhook replay are idempotent: they must not create duplicate payments, orders, voucher redemptions, emails or KDS tickets.
- Cancelling Stripe Checkout retains the same Checkout Session, frozen order snapshot and voucher reservation for safe retry. Cancellation does not redeem the voucher and does not create a redemption that then needs to be reverted.

The Mastercard/MPGS configuration remains a rollback option. Tyro remains the in-store EFTPOS route. Neither fallback should be switched while a checkout is in progress.

## Current Stripe blocker

The Live publishable key is present and the Live webhook signing-secret field indicates set, but the Live secret-key row does not report configured/readiness. That means Live Stripe remains blocked by design.

The owner or host administrator must place the correct Live server secret in the protected hosting environment under the exact name expected by the plugin, restart the relevant PHP worker/cache if required, and reload the DoughBoss Payments page. Do not paste the value into chat, screenshots, source control or this document.

Only after the plugin visibly reports Live secret readiness may the team perform one tightly controlled live acceptance payment. Accept orders and Accept card payments must stay OFF until that moment.

## Acceptance evidence: current versus historical

### Current 2.33.2 status

- Admin Menu Items and Locations smoke checks passed after Toolset Types was deactivated.
- Public pages, DoughBoss REST endpoints and the webhook route continued to respond after the unrelated WordPress security updates.
- Logged-out Kitchen, Catering and Management requests remained protected and redirected to sign-in.
- The approved navigation proof passed.
- The fresh 2.33.2 order-preparation phase then timed out before Stripe Checkout. No payment was submitted. Run tag `QA-20260803-231050` has zero matching orders.
- The site was safely restored: the Stripe selector is Live; public config reports `orders=false`, `cards=false` and `gateway=stripe`; `/order/` returns HTTP 200 with Coming Soon; and Migration Gate is deactivated.
- A fresh controlled 2.33.2 Stripe Test recovery run is still pending and not accepted. Do not describe 2.33.2 as payment-accepted until its signed webhook, one-order result, KDS, email, tracking and management evidence are recorded.

### Historical supporting evidence

These records prove earlier flows but do not replace a fresh 2.33.2 acceptance run:

- **DB-260803-NTSCXM**: historical 2.33.1 Stripe Test/voucher order. A$3.50 basket, A$1.00 voucher discount and A$2.50 paid total. Earlier checks found one order, one online voucher redemption, no duplicate after return replay, and successful Kitchen progression to Completed.
- **DB-260728-FUBKMT**: historical A$3.50 Stripe Test order for one Spring Water. Earlier checks found one PaymentIntent, one order and one Kitchen ticket after a duplicate-return check.
- An invalid-signature POST to `/wp-json/doughboss/v1/stripe-webhook` returned HTTP 400. This is useful reachability and signature-rejection evidence only; it is not proof of a genuine signed Stripe delivery receiving HTTP 2xx.

Historical test orders should remain identifiable as test evidence and must not be counted as current 2.33.2 live acceptance.

## Voucher and coupon email status

Voucher claiming can use WordPress's existing mail path without a new paid DoughBoss subscription:

- A successful campaign claim sends the voucher to the validated claim email using WordPress `wp_mail()`.
- Student offers require an eligible `.edu` or `.edu.au` address and enforce duplicate-claim and daily-cap rules.
- The same email submitted twice must produce only one voucher and one email.
- A manually created one-off voucher does not automatically trigger the campaign-claim email.
- The on-screen code and self-hosted QR remain the fallback if mail delivery fails.

Important limitation: the exactly-once email-attempt marker is claimed before `wp_mail()` runs. A transport failure is not automatically retried. Therefore, successful message handoff alone is insufficient; the final acceptance test must confirm receipt in an owner-controlled eligible inbox, correct sender and subject, spam behaviour and one matching mail-log entry.

An earlier WP Mail SMTP test was accepted by the configured transport, but destination-inbox receipt was not independently confirmed. The owner-controlled `.edu`/`.edu.au` end-to-end campaign claim remains pending. A paid delivery service is needed only if the current allowance or deliverability proves inadequate.

## POSPal status and launch gaps

The POSPal order mirror uses a durable, retry-safe outbox with stable remote references. The voucher bridge is not yet equivalent:

- POSPal member-coupon grant/revoke has no real provider-network automated test.
- Voucher revoke is currently fire-and-forget rather than stored in the durable outbox. A failed revoke could leave a mirrored POSPal coupon active after the WordPress voucher was redeemed online.
- The POSPal order payload sends the discounted final `totalAmount`, while item lines retain their original unit prices and contain no explicit voucher-discount line. The till's treatment of that difference must be verified on one controlled Revesby order.

Keep POSPal member-coupon bridging disabled for production until grant, use/revoke and recovery are proven on the real account and an operator recovery procedure is approved. Do not bulk-retry an ambiguous outbox record.

## Toolset and WordPress maintenance

Toolset Types 3.4.2 is vulnerable and now inactive. It has no verified DoughBoss runtime dependency, and DoughBoss Menu Items and Locations continued to work after deactivation. However, the WordPress database still contains legacy Toolset data, including 43 Menu Items, 3 Locations and associated field groups.

Do not delete Toolset or its legacy records yet. Retain it inactive until the final export and postponed backup are complete and management approves the cleanup.

Completed unrelated maintenance:

- Contact Form CFDB7 updated from 1.3.6 to 1.4.0. Existing submission counts were preserved: Apply for Franchise 0, Contact Us 356, Apply for a Job 16, Catering 43, Wholesale 27 and Franchising 8.
- Really Simple Security updated from 9.6.1 to 9.7.0.
- Inactive vulnerable Creatify and Lemmony themes were removed.
- The obsolete inactive DoughBoss Verified Updater 2.33.1 was removed.
- Four inactive duplicate WPVibe repair helpers were removed; WPVibe itself was retained.

The public site, staff-access redirects, DoughBoss REST routes and webhook route were smoke-tested after this maintenance. These are smoke results, not a substitute for the payment acceptance run.

## Remaining work before go-live

Use [DoughBoss-Live-Acceptance-Runbook.md](DoughBoss-Live-Acceptance-Runbook.md) for the complete sequence. The launch blockers are:

1. Correct the protected Live Stripe server key and confirm the plugin's Live readiness indicator.
2. Run the fresh 2.33.2 Stripe Test recovery/idempotency test through Stripe Checkout and retain one successful signed webhook delivery. The completed navigation proof and timed-out order-preparation run do not satisfy this item.
3. Complete one owner-controlled `.edu`/`.edu.au` voucher claim and verify exactly one received email.
4. Complete the voucher-adjusted Stripe Test order using one POSPal-mapped item priced above A$5; verify the exact discounted total and single redemption.
5. Confirm the same order number and total across Stripe, customer email, tracking, Kitchen MAKE/PASS, Management and, only with approval, POSPal.
6. Test declined-card retry, 3-D Secure, abandoned-browser recovery and an idempotent refund.
7. Test Apple Pay on eligible Apple hardware and Google Pay on eligible Android/Chrome hardware, plus card fallback on an ineligible device.
8. From Stripe Dashboard, confirm the Live endpoint event subscriptions and capture one genuine signed 2xx delivery during the controlled Live test.
9. Rotate any password or credential that has appeared in a chat or screenshot; use named staff accounts and a password manager.
10. Obtain management acceptance and confirm kitchen staff are ready before opening ordering.

## Backup timing

Management has explicitly postponed backup work until all other implementation, deployment, repository, CI, security cleanup and acceptance tasks are complete.

Do not interrupt the current completion sequence to take another backup. The final fresh files-and-database backup is the **last pre-launch protection step**, after the repository and CI are final and all implementation and acceptance checks pass, immediately before management authorises public go-live. Record its time and recovery location without recording credentials in this handover. Toolset and its legacy data must remain retained until that final backup is complete.

## Go-live sequence

After every blocker above passes:

1. Confirm that the controlled 2.33.2 Test and Live acceptance evidence, including the approved small live order and any required refund, is complete with orders/cards returned OFF.
2. Obtain provisional technical and operational sign-off to proceed to the final backup.
3. Take and verify the postponed final files-and-database backup.
4. Obtain final management authorisation for public go-live after the verified backup is recorded.
5. Confirm Stripe is Live, Live readiness is configured, webhook recovery is healthy and POSPal is at its approved baseline.
6. Confirm Kitchen MAKE, PASS, catering and management screens are signed in and monitored.
7. Turn Accept card payments ON.
8. Turn Accept orders ON.
9. Monitor the first real orders, payments, webhooks, Kitchen and unresolved-payment recovery closely during the opening window.

If any check fails, immediately turn Accept orders and Accept card payments OFF. Preserve the order, payment attempt, Stripe reference, webhook record, voucher audit and POSPal reference. Reconcile any successful payment before retrying or changing gateways.

## Rollback reference

The v2.33.2-rc.1 release asset and its recorded checksum are the known package for this handover. A code rollback must be reviewed for database compatibility and must never overwrite live order/payment data blindly. The operational first response is always to close orders and card payments, preserve evidence and reconcile money movement before changing code or gateways.
