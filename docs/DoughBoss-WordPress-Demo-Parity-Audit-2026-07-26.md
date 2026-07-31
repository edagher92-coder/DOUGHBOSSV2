# DoughBoss WordPress vs Final Demo Parity Audit

Date: 26 July 2026  
Scope: customer website, ordering, catering, tracking, legal pages, staff, kitchen and management surfaces

## Decision

The live WordPress site is still using the legacy `doughboss-child-wpvibe-backup`
theme. The repository's `doughboss-hybrid` theme is an unpublished integration
scaffold, not yet a full visual port of the final demo. It currently covers only
Home, Menu/Order, Track Order and Catering.

The DoughBoss plugin remains the authority for shops, menu availability, cart
totals, modifiers, vouchers, payments, orders, tracking, catering, QR sessions
and staff capabilities. Demo fixtures, simulated payments, generated voucher
codes, fake logins and local-only order states must never be copied into the live
WordPress implementation.

## Page-by-page parity matrix

| Surface | Final demo | WordPress target and authority | Current gap | Completion test |
| --- | --- | --- | --- | --- |
| Home | `demo/index.html` | `/`; WordPress theme plus live plugin shortcodes | Hybrid has hero, shop picker and menu only. Story, catering, locations, social/review CTA, footer and richer transitions are missing. | Responsive Home contains the approved sections and uses plugin data for every operational value. |
| Menu / Order | `demo/index.html`, `demo/menu.html` | `/menu/`, `/order/`; DoughBoss menu/cart/payment REST APIs | Live page uses the legacy theme. Product photos were missing, category order was wrong and the page content contained literal escaped newlines. | No visible escape text or overflow at 360–1280 px; every product has local approved imagery; cart, voucher and checkout use server totals. |
| Track Order | demo receipt/tracker state | `/track-order/`; `[doughboss_order_tracking]` and `/order/track` | Connected draft exists, but global navigation/footer do not expose it and visual treatment is incomplete. | Correct order number plus matching email succeeds; incorrect pair fails without exposing customer data. |
| Catering | `demo/catering.html`, Home catering section | `/catering/`; DoughBoss catering packages, quote and enquiry APIs | Functional shortcode exists, but the premium platter story, process panels and full site framing are not ported. | Enquiry reaches DoughBoss Catering admin; quote and status updates work; coming-soon contact details remain correct. |
| About | `demo/index.html#about` | `/about-us/`; WordPress editorial content | Existing legacy page is not represented in hybrid templates and does not match the final demo composition. | Approved copy and imagery render in the shared final theme on mobile and desktop. |
| Locations | `demo/index.html#locations` | `/locations/`; DoughBoss shop registry | Legacy template owns the page. Hybrid has no locations template. Bankstown and Roselands must display as “baking now” information only, not online-order destinations. | Revesby alone is orderable; contact, hours and maps reflect the live registry; inactive stores cannot enter checkout. |
| Franchising / Wholesale | `demo/franchise.html` | `/franchising/`, `/wholesale/`; WordPress content plus an approved lead form | Legacy contact template only; demo visual and lead journey are not ported. | Valid enquiry reaches the approved inbox with consent and anti-spam controls; failures are visible to the customer. |
| Licensing | `demo/licensing.html` | Future `/licensing/`; owner-approved legal content | No WordPress equivalent. | Legal-reviewed, versioned content is printable/downloadable and linked from the correct business area. |
| Terms | `demo/terms.html` | `/terms-conditions/`; WordPress legal page | Existing content still includes old brand references and has no final-theme treatment. | Owner/legal-approved text, effective date and footer link are correct on all breakpoints. |
| Privacy | `demo/privacy.html` | `/privacy-policy/`; WordPress privacy-policy assignment | Existing text contains spelling/contact issues and must be reconciled with actual payment, analytics and notification processing. | Disclosures match configured processors and retention; correct contact and footer link; no false promises. |
| Kitchen | `demo/kitchen.html` | DoughBoss Order Board and real KDS REST endpoints | Demo is visually richer but fixture-driven. It must not replace the live board. | A real Revesby test order appears once; kitchen user can accept and advance it; customer tracking updates; unauthorised users are denied. |
| Staff | `demo/staff.html` | WordPress-authenticated staff console / KDS capabilities | Demo accepts fake credentials in local storage. Real app requires HTTPS, WordPress Application Passwords/cookies and capability checks. | Invalid login denied; role-limited navigation; sign-out clears credentials; no shared default production password. |
| Backend | `demo/backend.html` | DoughBoss Orders and Operations Dashboard | Demo data and actions are simulated. WordPress admin is functional but visually different. | Real orders, payment attempts, vouchers and status actions reconcile to persisted records. |
| Owner | `demo/owner.html` | DoughBoss Dashboard, Shops, Settings, Reports, Catering and Orders | Demo metrics and store cards are fixtures. WordPress is the real authority. | Dashboard figures reconcile to stored orders; non-owner access denied; no fake “live” states. |

## Launch order

1. Finish Revesby MPGS Visa/Mastercard reconciliation and protected acceptance.
2. Validate one full Revesby order through checkout, KDS, tracking and emails.
3. Keep Bankstown and Roselands completely dormant: no shop rows, QR tables,
   coupon UIDs, product maps, payments or POSPal order push.
4. Port the final demo presentation into a versioned WordPress theme while
   retaining the live plugin for all connected behaviour.
5. Complete mobile/desktop visual regression and accessibility checks for every
   route above.
6. Review and correct Terms and Privacy before public launch.

## Never migrate from the demo

- Fake staff authentication or local-storage roles.
- Generated order numbers, simulated payment confirmations or local-only order
  status changes.
- Fictional voucher codes or simulated POSPal behaviour.
- Hard-coded menu prices, shop availability, opening rules or payment readiness.
- Demo kitchen, owner or backend fixture records and synthetic “live” indicators.

