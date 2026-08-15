=== DoughBoss ===
Contributors: doughboss
Tags: pizza, food ordering, menu, restaurant, ecommerce
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.40.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pizza & food ordering for WordPress: menu management, a custom pizza builder, online ordering and order tracking.

== Description ==

DoughBoss turns any WordPress site into a pizza/food ordering storefront. It adds:

* A **Menu Items** custom post type with categories, prices and images.
* A **custom pizza builder** where customers choose a size and toppings with live pricing.
* A **cart and checkout** for pickup, delivery, or secure QR-bound table ordering, with configurable tax and delivery fees. Payment-provider activation remains optional and is not live until its own onboarding and verification gates pass.
* **Order tracking** so customers can check their order status by order number + email.
* An **Orders** admin screen with live status updates, plus a settings page for sizes, toppings, currency and fees.

Everything is rendered through shortcodes and a small REST API; no theme changes are required.

Kitchen staff can use the capability-gated, shop-scoped `/kitchen/` workspace,
optimised for a 23.8-inch Full HD touch display. Catering production uses the
separate hidden `/catering-kitchen/` workspace, while managers use the protected
`/management/` overview. Every staff route uses the normal WordPress user and
role system; no password or secret is stored in the plugin.

Every employee can use the touch-first `/staff-clock/` workspace by scanning a
personal QR badge and entering a private PIN. Clock-in is bound to an active
DoughBoss shop, the short kiosk session clears after every action, and recorded
breaks are deducted from worked time. Optional manager-set rosters snapshot the
expected start, grace and late minutes without making unapproved payroll-policy
deductions. Managers can review, filter, correct with an audit reason, and export
attendance from Staff Timesheet.

= Shortcodes =

* `[doughboss_menu]` â€” the menu grid.
* `[doughboss_builder]` â€” the custom pizza builder.
* `[doughboss_cart]` â€” the cart and checkout.
* `[doughboss_order_tracking]` â€” the order status lookup form.
* `[doughboss_shop_picker]` â€” choose which shop to order from (multi-shop sites).
* `[doughboss_ordering_status]` â€” accessible Coming Soon copy while checkout is paused.

== Installation ==

1. In wp-admin go to **Plugins â†’ Add New â†’ Upload Plugin**.
2. Upload `doughboss.zip` and click **Install Now**, then **Activate**.
3. Go to **DoughBoss â†’ Settings** to configure sizes, toppings, currency, tax and fees. Fresh installs begin in safe browse-only mode; leave **Accept orders** off until staging is complete.
4. Add menu items under **DoughBoss â†’ Menu Items**.
5. Place the shortcodes above on your pages.

== Frequently Asked Questions ==

= Does this process payments? =

Optionally. Orders are always recorded and the store is notified â€” DoughBoss
suits "order now, pay on pickup/delivery" out of the box. Storefront card
payments redirect to Stripe-hosted Checkout for Visa, Mastercard and eligible
Apple Pay or Google Pay wallets; Stripe automatically presents supported
wallets using its own secure interface. Catering uses Stripe Payment Element.
Payments can be switched on under **DoughBoss â†’ Settings â†’ Payments** (off by
default); once configured, the payment and final order total are verified
server-side before the order is accepted.

Tyro Connect Pay is also available as a **pre-final, disabled-by-default**
integration. It needs Tyro-provided sandbox credentials, a confirmed per-shop
`locationId`, a signed webhook, sandbox evidence and Tyro production approval
before live payments may be enabled. The installed code and this readme do not
include merchant credentials or make a shop payment-ready.

Mastercard Payment Gateway Services (MPGS) Hosted Checkout is available as a
separate gateway. Card entry and 3-D Secure happen on Mastercard's hosted page;
DoughBoss retrieves and verifies the gateway order amount, currency, checkout
binding and captured state before saving a paid order. Test and live API
passwords are environment-first, and live mode has an additional approval gate.

= Does it need an account system? =

No. Carts are tied to a cookie token, so guests can order without logging in.

== Changelog ==

= 2.40.0 =
* Adds a manager-only, reason-required reversal for an unlinked in-store voucher mis-scan; the original receipt evidence remains and the reversal is audited.
* Makes voucher voids reason-required and audited as well.
* Keeps online or order-linked redemptions out of the manual reversal path so refunds remain tied to the original payment record.

= 2.39.0 =
* Enforces a maximum $5 promotional discount and a $3 minimum spend.
* Makes in-store redemption fail closed until a manager is named as the daily reconciliation owner.
* Requires the completed till/POS receipt reference and stores the signed-in cashier with each in-store redemption.

= 2.38.0 =
* Adds two standalone interactive operating guides: unlisted `/staff-guide/` and manager-protected `/management-guide/`, with touch-first walkthroughs, visual operating flows, live links and per-device progress tracking.
* Keeps the QR/PIN kiosk workflow explicit: each employee receives a private badge and PIN, recorded breaks are actual breaks only, and managers retain audited access controls.
* Extends the protected portal route contract without adding either guide to the public website navigation.

= 2.37.0 =
* Adds a scanner-first staff kiosk using revocable personal QR badges and 4â€“8 digit private PINs, with hashed credentials, short-lived sessions and timed lockout after repeated failures.
* Adds touch-first clock in, break start/end and clock out actions while preserving one open shift and one open break per employee.
* Subtracts only actually recorded breaks from worked time; no automatic unpaid-break assumption is made.
* Adds optional weekly roster starts and grace periods, immutable late-minute snapshots, manager timesheets and expanded CSV evidence.

= 2.36.1 =
* Ensures an in-flight hosted payment return is always verified as paid or rejected safely when card acceptance is switched off, instead of falling through to an unpaid order.

= 2.36.0 =
* Adds a protected, touch-first `/staff-clock/` portal with safe shared-kiosk sign-out after every attendance action.
* Records each shift against an active DoughBoss shop, snapshots staff and location identity, and fails closed when a multi-shop assignment is missing.
* Adds a low-privilege clock-only staff role plus manager timesheets, shop filters, safe CSV export and audited forced-close corrections.
* Serializes clock transitions and enforces one open shift per employee in transactional storage.
* Repairs the Dough Boss Rewards settings save path inherited from 2.35.0 so the plugin parses cleanly before activation.

= 2.35.0 =
* Adds Dough Boss Rewards: passwordless customer membership, points wallet, tiers and a manager-controlled, default-off launch gate.
* Adds three prepared launch promotions: Join the Dough Club, Fresh Start and Tuesday Treat, with a one-promotion-per-order safeguard.
* Exchanges points for personal, one-time vouchers through the existing secure voucher system, so online and in-store redemption share the same audit path.
* Binds emailed QR/personal vouchers to the issued email at the final online checkout reservation boundary, preventing a shared QR image from being redeemed by another online customer.

= 2.34.1 =
* Repairs the production visual release by pairing the authentic-photo plugin with the matching DoughBoss Final 1.3.0 theme.
* Replaces the alarming black "photo coming soon" tile with an honest, category-specific freshly-made treatment for products awaiting an exact owner photograph.
* Adds release asset-reference coverage so a package cannot pass when a referenced production image is missing.

= 2.34.0 =
* Replace the generated floating-food homepage treatment with approved real Dough Boss merchant photography and restrained, accessible photo parallax.
* Replace repeated or artificial menu imagery with exact real product photographs; products without a verified exact photo now use an honest branded placeholder instead of a lookalike.
* Apply the real-photo direction across the homepage, order/menu, story, catering, locations and tracking presentation while keeping oven-baked wording consistent.
* Bring the standalone demo and automated visual contracts into parity with the production WordPress experience.

= 2.33.5 =
* Make the $5 student voucher a one-time allocation per verified student email across the whole student campaign, including legacy campaign records in the same allocation pool.
* Keep the existing daily allocation limit while making the customer message accurately explain the one-time student benefit.

= 2.33.4 =
* Start the signature food build once on first view, including on devices that previously suppressed it.
* Hand the finished entrance permanently to the reversible scroll scene so scrolling down and back up always updates the composition without a timer taking control back.
* Make Pause freeze the current food pose and Resume return smoothly to the current scroll position.

= 2.33.3 =
* Makes the hero food blow-out and rebuild clearly visible, then repeats it at a calm interval only while the hero is on screen.
* Keeps reduced-motion visitors still by default and adds explicit Start, Pause and Resume controls for the animation.
* Brings the WordPress and GitHub demo motion behaviour back into parity.

= 2.33.2 =
* Adds a dedicated, hidden `/catering-kitchen/` production workspace while preserving existing `/kitchen/?screen=catering` bookmarks.
* Sends no-index, no-cache and anti-framing protection before staff authentication redirects and keeps the catering URL out of the public theme and navigation.

= 2.33.1 =
* Makes one-time voucher pricing exclusive to a single immutable Stripe checkout attempt, preventing concurrent carts from paying with the same discount.
* Moves voucher consumption into the paid-order path, makes browser and webhook recovery share the same lease, and preserves a safe retry after cancellation or failed order persistence.
* Prevents public access to the legacy voucher redemption endpoint and protects linked redemption audit records from late retries.
* Emails each successfully claimed student voucher to its verified education address using WordPress mail, with an exactly-once attempt marker and the on-screen code retained as a fallback.
* Lets reduced-motion visitors explicitly replay the homepage food build and remembers that preference for the browser session.
* Optimises all 34 distinct menu photos and homepage food scenes for faster delivery without changing their dimensions or transparent edges, and replaces the pies scene with an oven-bakery presentation.

= 2.33.0 =
* Adds protected, branded `/kitchen/` and `/management/` workspaces using WordPress authentication, staff capabilities, shop scope, REST nonces and optional kitchen-link verification.
* Optimises the primary MAKE view for a 23.8-inch 1920x1080 touch monitor and retains a compact dedicated Catering view for a smaller secondary display.
* Rebuilds the homepage food animation around a high-resolution Sujuk Special and four clearly different menu foods, with an automatic blow-out/rebuild sequence, replay, scroll depth and reduced-motion support.
* Replaces the main public category photography with high-resolution Manoush, Sujuk Special and Lebanese pie imagery, and standardises customer-facing copy on oven-baked.
* Replaces repeated and low-resolution menu placeholders with a consistent high-resolution photo for every one of the 34 canonical products, including distinct half-and-half, pizza, pie, wrap, dessert and drink imagery.

= 2.32.2 =
* Automatically renames the one existing seeded Dough Boss Special pizza to Sujuk Special during upgrade while preserving its post ID, price, options, category and integration mappings.

= 2.32.1 =
* Rebuilds the homepage food blowout with five distinct premium oven-baked products, atmospheric depth and responsive reversible motion.
* Updates public oven-baked wording and tightens the small-screen partnership, contact and footer flow so content no longer overflows or leaves a large empty gap.
* Tunes the MAKE board for a 23.8-inch 1920x1080 touch station and adds a protected, shop-scoped catering production view for a smaller 15-inch display.
* Renames the existing Dough Boss Special pizza to Sujuk Special in place, retaining its product identity while updating the recipe copy and imagery lookup.

= 2.32.0 =
* Requires the same eligible .edu or .edu.au student email twice before a student voucher is allocated, with server-side domain, daily-cap and duplicate-per-day enforcement.
* Adds the polished public voucher journey and clarifies the secure Stripe-hosted handoff, automatic eligible Apple Pay/Google Pay presentation, card fallback and return-to-tracking journey.
* Adds manager-only Revesby bulk table-QR preparation with printable/PDF and individual SVG output using the existing server-bound table-session architecture.
* Scopes kitchen-only staff to their assigned location across feeds and every ticket action, with a safe single-shop legacy fallback and manager all-shop override.
* Improves kitchen offline/stale-response handling, explicit payment states and unpaid-order warnings, while preserving duplicate-safe status transitions.
* Routes POSPal pushes using the order location's configured store mapping and resumes catering Stripe confirmation safely after 3-D Secure redirects.
* Fixes legacy menu imports so renamed pies update in place, documents all 34 canonical menu items and completes the final responsive WordPress UI/accessibility pass.

= 2.31.0 =
* Adds a manager-only catering quote workflow with server-derived AUD totals, deposits and balances that becomes immutable when payment preparation begins.
* Hardens catering payment reconciliation against amount, currency, metadata, location and payment-attempt mismatches, including duplicate browser/webhook races.
* Makes voucher daily caps fail closed when their concurrency lock is unavailable and restricts catalogue/package editing to DoughBoss managers.
* Updates operational inbox defaults, complete uninstall cleanup, customer accessibility states, tracking compatibility and the production WordPress theme handoff.

= 2.30.0 =
* Integrates the approved dark-hero and wide cream-panel demo presentation directly into the WordPress Order Online page without changing the active theme or payment engine.
* Adds reversible menu-card blowout motion while scrolling up and down, with a no-motion accessibility path.
* Turns Coming Soon into a true browse-only state: products and prices remain visible while ß¸¶‰Ëkºwµç}İÍ•ÈÁ…åµ•¹Ğ¥¹¥Ñ¥…±¥é…Ñ¥½¸İ¡•¹•Ù•È½É‘•É¥¹œ¥Ì±½Í•¸(¨•™…Õ±Ğ™É•Í ¥¹ÍÑ…±±…Ñ¥½¹ÌÑ¼½É‘•É¥¹œ±½Í•…¹É•ÅÕ¥É”…¸•áÁ±¥¥Ğ½İ¹•È…Ñ¥½¸Ñ¼…•ÁĞ½É‘•ÉÌ¸(¨‘m‘½Õ¡‰½ÍÍ}½É‘•É¥¹}ÍÑ…ÑÕÍu€™½ÈÁ…”‰Õ¥±‘•ÉÌ°‰±½¬Á…•Ì…¹Ñ¡•µ”Ñ•µÁ±…Ñ•Ì¸(¨‘É•…°Á±Õ¥¸…Ñ¥Ù…Ñ¥½¸°µ•¹Ô¥µÁ½ÉĞ°Í¡½ÉÑ½‘”°IMP…¹¡•­½ÕĞµ…Ñ”Ñ•ÍÑÌ½¸]½É‘AÉ•ÍÌ€Ø¸À¸ä½A!@€Ü¸Ğ…¹]½É‘AÉ•ÍÌ€Ü¸À¸È½A!@€à¸Ğ¸((ô€È¸ÈÈ¸À€ô(¨I•Á±…”Ñ¡”Õ¹Ù•É¥™¥•±•…ä5ALQåÉ¼Á…Ñ İ¥Ñ ÕÉÉ•¹ĞQåÉ¼½¹¹•ĞA…äè=ÕÑ °A…äI•ÅÕ•ÍÑÌ°‘¥É•ĞQåÉ¼¹©Ì°€ÍLµÉ•…‘ä‰É½İÍ•È™±½Ü°Í¥¹•Ñ¡¥¸İ•‰¡½½­Ì°…¹É•™Õ¹‘Ì¸(¨‘‘ÕÉ…‰±”Á…åµ•¹Ğ…ÑÑ•µÁÑÌ…¹İ•‰¡½½¬•Ù•¹Ğ‘”µ‘ÕÁ±¥…Ñ¥½¸İ¥Ñ¡½ÕĞÍÑ½É¥¹œ…É‘…Ñ„°=ÕÑ Ñ½­•¹Ì°É…Üİ•‰¡½½¬Á…å±½…‘Ì°½ÈQåÉ¼Á…äÍ•É•ÑÌ¸(¨‘™…¥°µ±½Í•Á•ÈµÍ¡½ÀQåÉ¼½¹¹•Ğ±½…Ñ¥½¸…¹A=MA…°ÍÑ½É”µ…ÁÁ¥¹œÁ±ÕÌ„±¥Ù”•ÉÑ¥™¥…Ñ¥½¸…Ñ”¸(¨‘Õ¹¥Ù•ÉÍ…°QåÉ¼¡•­½ÕĞ™½ÈÍÑ½É•™É½¹Ğ½Ñ…‰±”EH…¹…Ñ•É¥¹œ‘•Á½Í¥Ğ½‰…±…¹”Á…åµ•¹ÑÌ¸(¨‘…¸½É¥¥¹…°°É•‘Õ•µµ½Ñ¥½¸µ…İ…É”µ…¹½ÕÍ …¹…Ñ•É¥¹œ	¥Ñ•Ì½µ¥¹¤µµ…¹½ÕÍ ¥¹É•‘¥•¹Ğ…ÍÍ•µ‰±ä•áÁ•É¥•¹”Ñ¼Ñ¡”Ù¥•İ…‰±”‘•µ¼¸(¨‘Í¡•µ„€Ä¸ÄÔ¸À…¹Á…åµ•¹Ğ¥¹Ñ•É…Ñ¥½¸É•…‘¥¹•ÍÌ¡•­Ì¸((ô€È¸ÈÄ¸À€ô(¨‘Á•ÈµÍÑ½É”‘¥¹¥¹œÑ…‰±•Ìİ¥Ñ ½Á…ÅÕ”°É½Ñ…Ñ…‰±”EH‰•…É•È½‘•ÌÍÑ½É•½¹±ä…Ì¡…Í¡•Ì¸(¨	¥¹„Í…¹¹•Ñ…‰±”Ñ¼„™É•Í …ÉĞ…¹•áÁ¥É¥¹œ!ÑÑÁ=¹±äÍ•ÍÍ¥½¸ìÉ•Ù…±¥‘…Ñ”ÍÑ½É”°Ñ…‰±”°…¹EHÍÑ…Ñ”‰•™½É”Á…åµ•¹Ğ…¹¡•­½ÕĞ¸(¨‘±½­•‘¥¹”µ¥¸ÕÍÑ½µ•È™±½Ü°É•ÅÕ¥É•ÕÍÑ½µ•È¹…µ”°ÁÉ½µ¥¹•¹Ğ-L½Ñ…‰±”Ñ¥­•Ğ‘¥ÍÁ±…ä°…¹¥µµÕÑ…‰±”½É‘•ÈÍ¹…ÁÍ¡½ÑÌ¸(¨‘„µ…¹…•Èµ½¹±äQ…‰±•Ì€˜EHÍÉ••¸İ¥Ñ Í…µ”µÍ¥Ñ”µ•¹Ô±¥¹­Ì…¹±½…±±äÉ•¹‘•É•ÁÉ¥¹Ñ…‰±”EH±…‰•±Ì¸(¨‘Í¡•µ„€Ä¸ÄĞ¸À…¹5…É¥…½Ù•É…”™½È¡…Í µ½¹±äÍÑ½É…”°…ÕÑ¡½É¥Ñ…Ñ¥Ù”É½ÕÑ¥¹œ°…¹É½Ñ…Ñ¥½¸¥¹Ù…±¥‘…Ñ¥½¸¸((ô€È¸ÈÀ¸À€ô(¨5…­”¡•­½ÕĞÉ•Á±…ä‘ÕÉ…‰±”İ¥Ñ Í•ÉÙ•Èµ‰½Õ¹¥‘•µÁ½Ñ•¹ä­•åÌ…¹‘…Ñ…‰…Í”µ•¹™½É•Õ¹¥ÅÕ•¹•ÍÌ¸(¨AÉ•Ù•¹ĞÑ¡”Í…µ”ÁÉ½Ù¥‘•ÈÁ…åµ•¹ĞÉ•™•É•¹”™É½´É•…Ñ¥¹œµ½É”Ñ¡…¸½¹”½É‘•ÈÕ¹‘•È½¹ÕÉÉ•¹ĞÉ•ÅÕ•ÍÑÌ¸(¨‘„™…¥°µ±½Í•€Ä¸ÄÌ¸Àµ¥É…Ñ¥½¸Ñ¡…ĞÁÉ•Í•ÉÙ•Ì¡¥ÍÑ½É¥…°Á…åµ•¹Ğ•Ù¥‘•¹”…¹ÍÕÉ™…•Ì‘ÕÁ±¥…Ñ•Ì™½È½Á•É…Ñ½ÈÉ•½¹¥±¥…Ñ¥½¸¸(¨I•ÕÍ”Ñ¡”Í…µ”‰É½İÍ•È¡•­½ÕĞ…ÑÑ•µÁĞ…¹Ù•É¥™¥•Á…åµ•¹ĞÉ•™•É•¹”…™Ñ•È…¸¥¹Ñ•ÉÉÕÁÑ•É•ÍÁ½¹Í”¸(¨‘5…É¥…€ÄÀ¸Ø…¹€ÄÄ¸Ğµ¥É…Ñ¥½¸…¹½¹ÕÉÉ•¹ä½Ù•É…”™½È¡•­½ÕĞ¥¹Ñ•É¥Ñä¸((ô€È¸Ää¸À€ô(¨‘Ñ¡”‘¥Í…‰±•µ‰äµ‘•™…Õ±Ğ°Ñ¥µ”µé½¹”µ…İ…É”Á¥­ÕÀ…Á…¥ÑäÁ±…¹¹¥¹œ•¹¥¹”¸(¨‘ÑÉ…¹Í…Ñ¥½¹…°Í¡•‘Õ±”°Í±½Ğ…¹¡½±ÍÑ½É…”İ¥Ñ ‘ÕÉ…‰±”Á•ÈµÍ±½Ğ±½­¥¹œ¸(¨‘‘•Ñ•Éµ¥¹¥ÍÑ¥ŒMå‘¹•äMP°¹½Ñ¥”°‰±…­½ÕĞ…¹…Á…¥Ñäµ‰½Õ¹‘…ÉäÑ•ÍÑÌ¸((ô€È¸Äà¸À€ô(¨9•ÜèÙ•ÉÍ¥½¹•°™½Éİ…Éµ½¹±ä½É‘•È±¥™•å±”İ¥Ñ ½ÁÑ¥µ¥ÍÑ¥Œ½¹ÕÉÉ•¹äÍ¼„(€ÍÑ…±”ÍÑ…™˜ÍÉ••¸…¹¹½Ğ½Ù•ÉİÉ¥Ñ”„¹•İ•È­¥Ñ¡•¸ÕÁ‘…Ñ”¸(¨9•ÜèÑÉ…¹Í…Ñ¥½¹…°½É‘•È•Ù•¹Ğ¡¥ÍÑ½Éä…¹UQ±¥™•å±”Ñ¥µ•ÍÑ…µÁÌ°¥¹±Õ‘¥¹œ(€ÍÑ…™˜µ•ÍÑ¥µ…Ñ•É•…‘äİ¥¹‘½İÌ…¹ÕÍÑ½µ•ÈµÍ…™”ÍÑ…ÑÕÌİ½É‘¥¹œ¸(¨¡…¹”è­¥Ñ¡•¸‰½…É°ÍÑ…™˜½¹Í½±”…¹]½É‘AÉ•ÍÌ½É‘•ÈÍÉ••¸¹½ÜÕÍ”Ñ¡”(€Í•ÉÙ•Èµ…ÁÁÉ½Ù•¹•áĞ…Ñ¥½¹Ìì½É‘•È…¹•±±…Ñ¥½¸¥Ìµ…¹…•Èµ½¹±ä¸(¨¡…¹”èÕÍÑ½µ•ÈÑÉ…­¥¹œÍ¡½İÌÑÉÕÑ¡™Õ°Í¡½ÀÍÑ…ÑÕÌ°Á…åµ•¹Ğİ½É‘¥¹œ…¹É•…‘ä(€½±±•Ñ¥½¸Õ•Ì¸A…åµ•¹ĞÁÉ½Ù¥‘•È…Ñ¥Ù…Ñ¥½¸É•µ…¥¹ÌÕ¹¡…¹•…¹½ÁÑ¥½¹…°¸(¨M…™•ÑäèÑ¡”€Ä¸ÄÄ¸ÀÍ¡•µ„µ¥É…Ñ¥½¸Ù•É¥™¥•Ì%¹¹½±¥™•å±”ÍÑ½É…”…¹™…¥±Ì(€±½Í•İ¥Ñ …¸½İ¹•È¹½Ñ¥”İ¡•¸…Ñ½µ¥Œ½É‘•È¡¥ÍÑ½Éä…¹¹½Ğ‰”Õ…É…¹Ñ••¸((ô€È¸ÄÜ¸À€ô(¨9•Üè€¨©M¥¹±”µ±½…Ñ¥½¸€¼Á¥­ÕÀµ½¹±äµ½‘”¸¨¨]¡•¸•á…Ñ±ä½¹”Í¡½À¥Ì…Ñ¥Ù”°(€Ñ¡”½İ¹•È…¸•¹…‰±”„Õ…É‘•Í¥¹±•}±½…Ñ¥½¹}µ½‘•€Í•ÑÑ¥¹œÑ¡…Ğ¡¥‘•ÌÑ¡”(€Í¡½ÀÁ¥­•È…¹‘•±¥Ù•ÉäÑ½±”°É•©•ÑÌ‘•±¥Ù•ÉäÍ•ÉÙ•ÈµÍ¥‘”°…¹Á¥¹Ì•Ù•Éä(€½É‘•ÈÑ¼Ñ¡…ĞÍ½±”Í¡½À¸5Õ±Ñ¤µÍ¡½ÀÍ¥Ñ•Ì™…¥°±½Í•…¹…¹¹½Ğ•¹…‰±”¥Ğ¸(¨9•Üè€¨©MÑ½É•™É½¹ĞÉ•‰É…¹¸¨¨M¹½Ü	½ÍÌ¥ÌÉ•Ñ¥É•ìÑ¡”€‰M¹½Ü	½ÍÌˆÍ•Ñ¥½¸(€¥Ì¹½Ü€‰=™™•ÉÌ€˜9•İÌˆ€¡½Õ 	½ÍÌ½¹±ä°Í¥¹±”%¹ÍÑ…É…´™½±±½Ü…Ñ”¤¸(€Q¡”€‰1½…Ñ¥½¹ÌˆÑ…ˆ¥ÌÉ•¹…µ•€‰½¹Ñ…ĞUÌˆ€¡‰…­•¹‘…Ñ„µ½‘•°Õ¹¡…¹•¤¸(€…Ñ•É¥¹œ¥Ì„½¹Ñ…Ğµ½¹±ä‰±½¬Õ¹Ñ¥°Ñ¡”½¹±¥¹”ÅÕ½Ñ”™±½ÜÍ¡¥ÁÌ¸(¨¡…¹”èÙ½Õ¡•È…µÁ…¥¸‘½Õ Õ€€¡ÁÉ•™¥à=U µ€¤É•Á±…•ÌÍ¹½ÜÕ€€¡ÁÉ•™¥à(€M9=\µ€¤ìÑ¡”±•…äÍ¹½ÜÕ€…µÁ…¥¸¥Ì‘½Éµ…¹Ğ‰ÕĞ•Ù•ÉäÙ½Õ¡•È…±É•…‘ä(€¥ÍÍÕ•Õ¹‘•È¥ĞÍÑ…åÌÉ•‘••µ…‰±”…ĞÑ¡”Ñ¥±°¸(¨…Ñ„è€Ä¸ÄÀ¸Àµ¥É…Ñ¥½¸•¹…‰±•ÌÍ¥¹±•}±½…Ñ¥½¹}µ½‘•€½¹±ä™½ÈÍ¥Ñ•Ìİ¥Ñ ¹¼(€µ½É”Ñ¡…¸½¹”…Ñ¥Ù”Í¡½À…¹ÑÕÉ¹Ì‘•±¥Ù•Éä½™˜Ñ¡•É”¸5Õ±Ñ¤µÍ¡½ÀÍ¥Ñ•Ì…É”(€Í••‘•İ¥Ñ Ñ¡”µ½‘”½™˜…¹Ñ¡•¥È‘•±¥Ù•ÉäÍ•ÑÑ¥¹œ¥Ì±•™Ğ…±½¹”¸((ô€È¸ÄØ¸À€ô(¨9•Üè€¨©A=MA…°ÁÕÍ ½ÕÑ‰½à¨¨ƒŠP½¹±¥¹”½É‘•ÉÌÉ•©•Ñ•‰äA=MA…°İ¥Ñ …¸•áÁ±¥¥Ğ(€É•ÑÉå…‰±”•ÉÉ½È…É”¹½ÜÉ•ÑÉ¥•…ÕÑ½µ…Ñ¥…±±ä½¸„‘ÕÉ…‰±”(€½ÕÑ‰½àİ¥Ñ •áÁ½¹•¹Ñ¥…°‰…­½™˜€ ØÁÌ€¼€Ôµ¥¸€¼€ÌÀµ¥¸°…ÁÁ•…Ğ€ÔÑÉ¥•Ì¤¸(€É½¸İ½É­•È½İ¹Ì‘¥ÍÁ…Ñ Õ¹‘•È…¸…Ñ½µ¥ŒÁ•¹‘¥¹œƒŠH¥¹}™±¥¡Ğ±…¥´°Í¼(€½¹ÕÉÉ•¹Ğ±½…°Íİ••ÁÌÍ¡…É”½¹”É½Ü¸Ù•ÉäÑÉ…¹ÍÁ½ÉĞ•ÉÉ½È½È…‰…¹‘½¹•(€¥¸µ™±¥¡ĞÉ•ÅÕ•ÍĞ¥ÌÑÉ•…Ñ•…Ì…µ‰¥Õ½ÕÌè¥ĞÍÑ½ÁÌ™½È½Á•É…Ñ½ÈÉ•Ù¥•Ü…¹(€¥Ì¹½ĞÉ•ÑÉ¥•Õ¹Ñ¥°ÍÑ…™˜½¹™¥É´Ñ¡”½É‘•È¥Ì…‰Í•¹Ğ™É½´Ñ¡”Ñ¥±°¸(€Õ±±ä½™˜Õ¹±•ÍÌ€‰AÕÍ ½¹±¥¹”½É‘•ÉÌˆ¥Ì•¹…‰±•¸(¨9•Üè€¨©…¥±•µÁÕÍ Ù¥Í¥‰¥±¥Ñä¨¨ƒŠPİÀµ…‘µ¥¸ÍÕÉ™…•Ì„‘¥Íµ¥ÍÍ¥‰±”¹½Ñ¥”(€İ¡•¸½É‘•ÉÌ•á¡…ÕÍĞÑ¡•¥ÈÉ•ÑÉä‰Õ‘•Ğ½È…É”ÍÑ¥±°É•ÑÉå¥¹œ…™Ñ•ÈÍ•Ù•É…°(€…ÑÑ•µÁÑÌ¸áÁ±¥¥Ğ™…¥±ÕÉ•Ìµ…ä‰”É•ÑÉ¥•¥¸‰Õ±¬ì…µ‰¥Õ½ÕÌÑÉ…¹ÍÁ½ÉĞ½È(€…‰…¹‘½¹•µİ½É­•È½ÕÑ½µ•ÌÉ•ÅÕ¥É”„Á•Èµ½É‘•ÈÑ¥±°¡•¬…¹½¹™¥Éµ…Ñ¥½¸¸(¨9•Üè€¨©!½ÕÉ±äA=MA…°½ÕÑ‰½àµ…¥¹Ñ•¹…¹”¨¨ƒŠPÍÕ•ÍÍ™Õ°É½İÌ…É”É•Ñ…¥¹•™½È(€€ÌÀ‘…åÌ°Ñ¡•¸ÁÉÕ¹•¸ÕÑ½µ…Ñ¥ŒÉ•µ½Ñ”É”µÁÕÍ ¥Ì‘•±¥‰•É…Ñ•±ä‘¥Í…‰±•Õ¹Ñ¥°(€‘¥ÍÁ…Ñ Á•ÉÍ¥ÍÑÌA=MA…°ÌÍÑ…‰±”½É‘•É9½€ìÑ¡¥ÌÁÉ•Ù•¹ÑÌ…¸•µÁÑä½È(€…µ‰¥Õ½ÕÌ±½½­ÕÀ™É½´‘ÕÁ±¥…Ñ¥¹œ„Ñ¥±°½É‘•È¸(¨9•Üè€¨©Y¥ÍÕ…°A=MA…°ÁÉ½‘ÕĞµ…ÁÁ¥¹œ¨¨ƒŠP„Í•ÑÑ¥¹ÌµÁ…”Ñ…‰±”Ñ¡…Ğ±½…‘Ì(€A=MA…°Ì…Ñ…±½Õ”…¹…ÕÑ¼µµ…Ñ¡•Ìµ•¹Ô¥Ñ•µÌ‰ä¹…µ”°É•Á±…¥¹œÑ¡”(€ÁÉ•Ù¥½ÕÌ]@µ1$µ½¹±äÍ•ÑÕÀ™½Èµ…ÁÁ¥¹œµ•¹Ô¥Ñ•µÌÑ¼A=MA…°ÁÉ½‘ÕĞÕ¥‘Ì¸(¨¡…¹”èÍ¥¹¥¹œ¡•±Á•È½Õ¡	½ÍÍ}A=MA…°èéÍ¥¸ ¥€¥Ì¹½ÜÑ¡”Í¥¹±”(€Í¥¹…ÑÕÉ”¥µÁ±•µ•¹Ñ…Ñ¥½¸ÕÍ•‰ä•Ù•Éä…±°°İ¥Ñ „­¹½İ¸µÙ•Ñ½ÈÍµ½­”(€Ñ•ÍĞÑ¡…Ğ‰É•…­Ì±½Õ‘±ä¥˜Ñ¡”İ¥É”™½Éµ…Ğ•Ù•È‘É¥™ÑÌ¸(¨…Ñ„è¹•ÜíÁÉ•™¥áõ‘½Õ¡‰½ÍÍ}Á½ÍÁ…±}½ÕÑ‰½á€Ñ…‰±”€¡ØÄ¸ä¸À¤ì‘‰•±Ñ„µÍ…™”¸((ô€È¸ÄÔ¸À€ô(¨9•Üè€¨©I•Á½ÉÑÌ¨¨…‘µ¥¸Á…”€¡½Õ¡	½ÍÌƒŠHI•Á½ÉÑÌ¤ƒŠPÉ•Ù•¹Õ”°½É‘•È½Õ¹Ğ°(€…Ù•É…”½É‘•ÈÙ…±Õ”°Ñ½ÀµÍ•±±¥¹œ¥Ñ•µÌ…¹„Á¥­ÕÀ½‘•±¥Ù•ÉäÍÁ±¥Ğ™½È…¹ä(€‘…Ñ”É…¹”°İ¥Ñ „MX‘½İ¹±½…¸(¨9•Üè€¨©MÑÉ¥Á”İ•‰¡½½¬É•½¹¥±¥…Ñ¥½¸¨¨ƒŠP„€½‘½Õ¡‰½ÍÌ½ØÄ½ÍÑÉ¥Á”µİ•‰¡½½­€(€•¹‘Á½¥¹Ğ€¡Í¥¹…ÑÕÉ”µÙ•É¥™¥•¤…Ñ¡•Ì„…É¡…É”Ñ¡…ĞÍÕ••‘Ì‰ÕĞ¹•Ù•È(€‰•…µ”…¸½É‘•È°…¹ÍÕÉ™…•Ì¥Ğ¥¸„€‰A…åµ•¹Ğ¥ÍÍÕ•ÌˆÁ…¹•°½¸Ñ¡”=É‘•ÉÌ(€ÍÉ••¸™½ÈÑ¡”½İ¹•ÈÑ¼É•Í½±Ù”¸9¼µ½¹•ä¥Ì•Ù•È…ÕÑ¼µÉ•™Õ¹‘•¸(¨9•Üè€¨©I•™Õ¹™É½´Ñ¡”=É‘•ÉÌÍÉ••¸¨¨ƒŠP„…ÉµÁ…¥MÑÉ¥Á”½É‘•È…¸‰”(€É•™Õ¹‘•¥¸½¹”±¥¬€¡½İ¹•Èµ½¹±ä°Õ…É‘•……¥¹ÍĞ‘½Õ‰±”µÉ•™Õ¹¤¸(¨9•Üè€¨©AÉ¥Ù…äQ½½±Ì¨¨ƒŠP½É‘•È½…Ñ•É¥¹œ½Ù½Õ¡•È‘…Ñ„¥Ì¹½Ü½Ù•É•‰ä(€]½É‘AÉ•ÍÌÌ‰Õ¥±Ğµ¥¸Á•ÉÍ½¹…°µ‘…Ñ„•áÁ½ÉĞ…¹•É…Í”Ñ½½±Ì€¡ÕÍÑÉ…±¥…¸(€AÉ¥Ù…äĞ€¼AH¤°É•‘…Ñ¥¹œÁ•ÉÍ½¹…°‘•Ñ…¥±Ìİ¡¥±”­••Á¥¹œÉ•½É‘Ì™½È(€…½Õ¹Ñ¥¹œ¸(¨9•ÜèÉ•…µ½¹±ä€½‘½Õ¡‰½ÍÌ½ØÄ½ÍÑ…ÑÕÍ€¡•…±Ñ •¹‘Á½¥¹Ğ€¡…‘µ¥¸µ…Ñ•¤¸(¨A•É™½Éµ…¹”èÑ¡”1¥Ù”=É‘•È	½…É…¹=É‘•ÉÌ±¥ÍĞ¹½Ü±½…½É‘•È¥Ñ•µÌ¥¸„(€Í¥¹±”‰…Ñ¡•ÅÕ•Éä¥¹ÍÑ•…½˜½¹”ÅÕ•ÉäÁ•È½É‘•ÈìA=MA…°…¹M5L…±±Ì(€¹¼±½¹•Èµ…­”¡•­½ÕĞİ…¥Ğ½¸Í±½Ü•áÑ•É¹…°Í•ÉÙ¥•Ì¸(¨M•ÕÉ¥ÑäèÑ¡”É•ÅÕ•ÍĞÉ…Ñ”±¥µ¥Ñ•È¥Ì¹½Ü…Ñ½µ¥Œ€¡±½Í•Ì„½¹ÕÉÉ•¹äÉ…”¤ì(€‘•Ù•±½Á•È‘¥…¹½ÍÑ¥Œ•¹‘Á½¥¹ÑÌ…É”¡¥‘‘•¸Õ¹±•ÍÌ]A}	U¥Ì½¸ìMX•áÁ½ÉÑÌ(€…É”Õ…É‘•……¥¹ÍĞÍÁÉ•…‘Í¡••Ğ™½ÉµÕ±„¥¹©•Ñ¥½¸¸(¨…Ñ„è‘…Ñ…‰…Í”‘…Ñ•Ñ¥µ”½±Õµ¹Ìµ¥É…Ñ•½™˜Ñ¡”±•…ä€ÀÀÀÀ´ÀÀ´ÀÁ€‘•™…Õ±Ğì(€¹•Ü¥¹‘•à½¸Ñ¡”…Ñ•É¥¹œÁ…åµ•¹Ğµ¥¹Ñ•¹Ğ±½½­ÕÀì…Ñ•É¥¹œ•¹ÅÕ¥É¥•ÌÉ•©•Ğ(€Á…ÍĞ‘…Ñ•Ì…¹…ÀÕ•ÍĞ½Õ¹ÑÌì±½…Ñ¥½¸Í±ÕÌ‘”µ‘ÕÁ±¥…Ñ”½¸É•…Ñ”¸(¨•µ¼Í¥Ñ”è‰É…¹½±½ÕÉÌÉ•½¹¥±•…¹±•…°Á…•Ìµ…‘”µ½‰¥±”µÉ•ÍÁ½¹Í¥Ù”ì(€­¥Ñ¡•¸‰½…É¡½¹½ÕÉÌ€‰É•‘Õ”µ½Ñ¥½¸ˆ…¹µ••ÑÌ½±½ÕÈµ½¹ÑÉ…ÍĞÍÑ…¹‘…É‘Ìì(€…•ÍÍ¥‰¥±¥Ñä…¹±½…‘¥¹œµÍÑ…Ñ”Á½±¥Í …É½ÍÌÑ¡”ÍÑ½É•™É½¹Ğ…¹MÑ…™˜½¹Í½±”¸((ô€È¸ÄĞ¸À€ô(¨9•Üè€¨©½Õ¡	½ÍÌƒŠH5•ÍÍ…”Q•µÁ±…Ñ•Ì¨¨ƒŠP…¸½İ¹•Èµ½¹±äÍÉ••¸Ñ¼•‘¥ĞÑ¡”(€•á…Ğİ½É‘¥¹œ½˜Ñ¡”½É‘•Èµ½¹™¥Éµ…Ñ¥½¸•µ…¥°€¡ÍÕ‰©•Ğ€¬‰½‘ä¤…¹Ñ¡”Ñİ¼(€M5Lµ•ÍÍ…•Ì€ ‰½É‘•ÈÉ•…‘äˆ°€‰Ù½Õ¡•È±…¥µ•ˆ¤°İ¥Ñ Á±…•¡½±‘•ÈÑ½­•¹Ì(€±¥­”í½É‘•É}¹Õµ‰•Éõ€…¹íÑ½Ñ…±õ€¸1•…Ù¥¹œ„™¥•±‰±…¹¬É•ÍÑ½É•ÌÑ¡”(€‰Õ¥±Ğµ¥¸‘•™…Õ±ĞÑ•áĞ¸M…Ù•ÌÙ¥„¥ÑÌ½İ¸¡…¹‘±•È€¡„ÑÉÕ”Á…ÉÑ¥…°ÕÁ‘…Ñ”¤°(€Í¼¥Ğ…¸¹•Ù•È…™™•Ğ…¹ä½Ñ¡•ÈÍ•ÑÑ¥¹œ¸(¨¥àèÑ¡”¡•­½ÕĞ™½É´€¡…¹¥ÑÌMÑÉ¥Á”…É™¥•±°İ¡•¸•¹…‰±•¤¹¼±½¹•È(€•ÑÌÉ•‰Õ¥±Ğ•Ù•ÉäÑ¥µ”Ñ¡”…ÉĞ¡…¹•ÌƒŠPÅÕ…¹Ñ¥Ñä•‘¥ÑÌ°É•µ½Ù¥¹œ„(€±¥¹”°½È…ÁÁ±å¥¹œ„Ù½Õ¡•ÈÕÍ•Ñ¼Í¥±•¹Ñ±ä±•…Èİ¡…Ñ•Ù•ÈÑ¡”ÕÍÑ½µ•È(€¡……±É•…‘äÑåÁ•°¥¹±Õ‘¥¹œ„…É¹Õµ‰•Èµ¥µ•¹ÑÉä¸(¨¥àè„É…Í ¥¸Ñ¡”½É‘•Èµ½¹™¥Éµ…Ñ¥½¸É•¹‘•É•ÈÑ¡…Ğ½Õ±±•…Ù”„(€ÍÕ•ÍÍ™Õ°½É‘•È±½½­¥¹œ±¥­”…¸•ÉÉ½ÈÑ¼Ñ¡”ÕÍÑ½µ•È¸(¨M•ÕÉ¥ÑäèMÑÉ¥Á”ÌÍ•É•Ğ­•ä…¹İ•‰¡½½¬Í•É•Ğ…¸¹½Ü‰”Í•ĞÙ¥„(€•¹Ù¥É½¹µ•¹ĞÙ…É¥…‰±”€¼İÀµ½¹™¥œ¹Á¡À½¹ÍÑ…¹Ğ°µ…Ñ¡¥¹œÑ¡”Á…ÑÑ•É¸(€…±É•…‘äÕÍ•™½ÈA=MA…°°5•ÉÕÉ”°¹Ñ™ä°±¥­M•¹…¹Ñ¡”É••¥ÁĞÁÉ¥¹Ñ•È¸((ô€È¸ÄÌ¸À€ô(¨¡…¹”èÑ¡”€¨¨ÄÀÍÑÕ‘•¹ĞÙ½Õ¡•ÈÑ¥•È¡…Ì‰••¸É•Ñ¥É•¨¨ƒŠPÑ¡”½Õ 	½ÍÌƒ\(€M¹½Ü	½ÍÌ±…Õ¹ Ù½Õ¡•È¥Ì¹½Ü€¨¨Ô½¹±ä¨¨¸I•µ½Ù•™É½´Ñ¡”‘•™…Õ±Ğ(€…µÁ…¥¸°Ñ¡”A=MA…°½ÕÁ½¸µÉÕ±”µ…ÁÁ¥¹œ€¡M•ÑÑ¥¹ÌƒŠHA=MA…°°…±°ÍÑ½É•Ì¤°(€…¹Ñ¡”ÍÑ½É•™É½¹Ğ‘•µ¼¸(¨M•ÕÉ¥Ñäè™¥á•„Í¥Ñ”µİ¥‘”=ILÉ•É•ÍÍ¥½¸ƒŠPÑ¡”Á±Õ¥¸¹¼±½¹•ÈÉ•µ½Ù•Ì(€]½É‘AÉ•ÍÌÌ‘•™…Õ±ĞIMP=IL¡…¹‘±¥¹œ™½È•Ù•Éä½Ñ¡•ÈÉ½ÕÑ”½¸Ñ¡”Í¥Ñ”¸(¨M•ÕÉ¥Ñäè…Ñ•É¥¹œ•¹ÅÕ¥ÉäÍÑ…ÑÕÌ¡…¹•Ì€¡Á…¥½½¹™¥Éµ•½±½ÍĞ¤¹½ÜÉ•ÅÕ¥É”(€½İ¹•Èµ±•Ù•°…•ÍÌ°µ…Ñ¡¥¹œÑ¡”Í…µ”‰½Õ¹‘…Éä…±É•…‘äÕÍ•™½ÈÙ½Õ¡•ÉÌƒŠP(€„­¥Ñ¡•¸½-LÑ¥±°±½¥¸…¸¹¼±½¹•È¡…¹”…Ñ•É¥¹œÁ…åµ•¹ĞÍÑ…ÑÕÌ¸(¨¥àèÉ•…Ñ•}Á…åµ•¹Ñ}¥¹Ñ•¹Ñ€¹½Ü¡•­ÌÑ¡”Í¡½ÀÌ½Á•¸½±½Í•…¹(€‘•±¥Ù•Éä½Á¥­ÕÀÍ•ÑÑ¥¹Ì‰•™½É”¡…É¥¹œ„…É°µ…Ñ¡¥¹œ€½¡•­½ÕÑ€¸(¨¥àè½ÉÉ•Ñ•ÕÍÑ½µ•Èµ™…¥¹œ½ÁäÑ¡…Ğ¥¹½ÉÉ•Ñ±ä±…¥µ•…¸Õ¹Ù•É¥™¥•(€…É¡…É”€‰İ¥±°‰”É•Ù•ÉÍ•…ÕÑ½µ…Ñ¥…±±ä¸ˆ(¨¥àèÑ¡”ÕÉÉ•¹äµ½‘”Í•ÑÑ¥¹œ¹¼±½¹•È™…±±Ì‰…¬Ñ¼UMİ¡•¸Õ¹Í•Ğ¸(¨M•ÕÉ¥Ñäè…‘‘•É…Ñ”±¥µ¥Ñ¥¹œÑ¼Ñ¡”Ñ¡É•”Á…åµ•¹Ğµ¥¹Ñ•¹ĞÉ½ÕÑ•Ì¸((ô€È¸ÄÈ¸Ä€ô(¨9•Üè€¨©½¹”µ±¥¬€‰%µÁ½ÉĞÍÑ…¹‘…Éµ•¹Ôˆ¨¨‰ÕÑÑ½¸€¡½Õ¡	½ÍÌƒŠHM•ÑÑ¥¹ÌƒŠH5•¹Ô¤ƒŠP(€É•…Ñ•ÌÑ¡”™Õ±°‰½…Éµ•¹Ô€¡5…¹½ÕÍ °A¥éé„°A¥•Ì°]É…ÁÌ°•ÍÍ•ÉÑÌ°É¥¹­Ìì€ÈÜ(€¥Ñ•µÌİ¥Ñ ÁÉ¥•Ì°…Ñ•½É¥•Ì…¹‘¥•Ñ…Éä™±…Ì¤İ¥Ñ ¹¼]@µ1$¹••‘•¸M…™”Ñ¼(€É”µÉÕ¸ìÍ¡…É•Í••‘•ÈÕÍ•‰ä‰½Ñ Ñ¡”‰ÕÑÑ½¸…¹İÀ‘½Õ¡‰½ÍÌÍ••µµ•¹Õ€¸(¨9•Üè€¨©MÑ…™˜Í•ÍÍ¥½¸€¡‘…åÌ¤¨¨Í•ÑÑ¥¹œƒŠP­••À±½•µ¥¸ÕÍ•ÉÌÍ¥¹•¥¸™½È„Í•Ğ(€¹Õµ‰•È½˜‘…åÌ€¡”¹œ¸€ÌØÔÀ¤Í¼Í¡½ÀÑ…‰±•ÑÌ¹•Ù•ÈÑ¥µ”½ÕĞ¸€À€ô]½É‘AÉ•ÍÌ‘•™…Õ±Ğ¸((ô€È¸ÄÈ¸À€ô(¨9•Üè€¨©=É‘•È¹½Ñ¥™¥…Ñ¥½¸•µ…¥°¨¨Í•ÑÑ¥¹œ€¡½Õ¡	½ÍÌƒŠHM•ÑÑ¥¹ÌƒŠHMÑ½É”¤ƒŠP¹•Ü(€½É‘•È…¹…Ñ•É¥¹œµ•¹ÅÕ¥Éä•µ…¥±Ì¼Ñ¼Ñ¡¥ÌÍ¡½À¥¹‰½à€¡‘•™…Õ±ÑÌÑ¼Ñ¡”½Õ (€	½ÍÌ½É‘•ÉÌ¥¹‰½àì‰±…¹¬™…±±Ì‰…¬Ñ¼Ñ¡”Í¥Ñ”…‘µ¥¸•µ…¥°¤¸¥±Ñ•É…‰±”Ù¥„(€‘½Õ¡‰½ÍÍ}½É‘•ÉÍ}•µ…¥±€¸(¨9•Üè€¨©İÀ‘½Õ¡‰½ÍÌÍ••µµ•¹Õ€¨¨]@µ1$½µµ…¹ƒŠPÁ½ÁÕ±…Ñ”Ñ¡”µ•¹Ô€¡¥Ñ•µÌ°(€ÁÉ¥•Ì°…Ñ•½É¥•Ì°‘¥•Ñ…Éä™±…Ì¤™É½´Ñ¡”¥¸µÍÑ½É”‰½…É‘Ì¥¸½¹”¥‘•µÁ½Ñ•¹Ğ(€ÍÑ•À€¡€´µ‘ÉäµÉÕ¹€ÍÕÁÁ½ÉÑ•¤¸5…Ñ¡•Ì¥Ñ•µÌ‰äÑ¥Ñ±”°Í¼É”µÉÕ¹¹¥¹œÕÁ‘…Ñ•Ì(€É…Ñ¡•ÈÑ¡…¸‘ÕÁ±¥…Ñ•Ì¸(¨¥àèÍ…Ù¥¹œM•ÑÑ¥¹Ì¹¼±½¹•È‘É½ÁÌÑ¡”½É‘•Èµ¹½Ñ¥™¥…Ñ¥½¸•µ…¥°¸(¨Q¡”µ…É­•Ñ¥¹œ½‘•µ¼Í¥Ñ”İ…ÌÉ•‰Õ¥±Ğ…É½Õ¹Ñ¡”ÕÉÉ•¹Ğµ•¹Ô€¡5…¹½ÕÍ °A¥éé„°(€A¥•Ì°]É…ÁÌ°•ÍÍ•ÉÑÌ°É¥¹­Ì¤İ¥Ñ „5•‘¥Ñ•ÉÉ…¹•…¸‰É…¹É•™É•Í ¸((ô€È¸Ô¸À€ô(¨9•Üè€¨©…ÉÁ…åµ•¹ÑÌÙ¥„MÑÉ¥Á”¨¨€¡½ÁÑ¥½¹…°°½™˜‰ä‘•™…Õ±Ğ¤¸¹…‰±”¥ĞÕ¹‘•È(€½Õ¡	½ÍÌƒŠHM•ÑÑ¥¹ÌƒŠHA…åµ•¹ÑÌ…¹…‘å½ÕÈ­•åÌìÍÑ…ÉĞ¥¸€¨©Q•ÍĞ¨¨µ½‘”İ¥Ñ (€Ñ•ÍĞ­•åÌ°Ñ¡•¸Íİ¥Ñ Ñ¼€¨©1¥Ù”¨¨¸]¡•¸½¸°ÕÍÑ½µ•ÉÌÁ…ä‰ä…É…Ğ¡•­½ÕĞ(€‰•™½É”Ñ¡”½É‘•È¥ÌÁ±…•¸(¨M•ÕÉ¥ÑäèÁ…åµ•¹ÑÌ…É”Ù•É¥™¥•€¨©Í•ÉÙ•ÈµÍ¥‘”¨¨ƒŠPÑ¡”½É‘•È¥Ì½¹±ä…•ÁÑ•…Ì(€Á…¥½¹”MÑÉ¥Á”½¹™¥ÉµÌ„A…åµ•¹Ñ%¹Ñ•¹ĞÑ¡…Ğµ…Ñ¡•ÌÑ¡”½É‘•ËŠeÌ(€Í•ÉÙ•Èµ½µÁÕÑ•…µ½Õ¹Ğ…¹ÕÉÉ•¹ä°…¹•… A…åµ•¹Ñ%¹Ñ•¹Ğ…¸‰”ÕÍ•™½È…Ğ(€µ½ÍĞ½¹”½É‘•È¸M•É•Ğ­•åÌ¹•Ù•È±•…Ù”Ñ¡”Í•ÉÙ•ÈìMÑÉ¥Á”¹©Ì±½…‘Ì½¹±äİ¡•¸(€Á…åµ•¹ÑÌ…É”½¹™¥ÕÉ•¸=É‘•ÉÌ¹½ÜÉ•½ÉÁ…åµ•¹ĞÍÑ…ÑÕÌ°µ•Ñ¡½…¹¥¹Ñ•¹Ğ¸(¨9¼¡…¹”™½ÈÍ¥Ñ•ÌÑ¡…Ğ‘½»ŠeĞ•¹…‰±”Á…åµ•¹ÑÌè¡•­½ÕĞİ½É­Ì•á…Ñ±ä…Ì‰•™½É”¸((ô€È¸Ğ¸À€ô(¨9•Üè€¨©Á•Èµ¥Ñ•´…Ù…¥±…‰¥±¥Ñä¨¨ƒŠPµ…É¬…¹äµ•¹Ô¥Ñ•´ƒŠqÍ½±½ÕÓŠt™É½´Ñ¡”¥Ñ•´(€•‘¥Ñ½È½Èİ¥Ñ „½¹”µÑ…ÀÉ½Ü…Ñ¥½¸½¸Ñ¡”5•¹Ô%Ñ•µÌ±¥ÍĞ¸M½±µ½ÕĞ¥Ñ•µÌ(€ÍÑ…ä½¸Ñ¡”µ•¹ÔÉ•å•½ÕĞİ¥Ñ „‰…‘”°Ñ¡”‘‰ÕÑÑ½¸¥Ì‘¥Í…‰±•°…¹Ñ¡”(€Í•ÉÙ•ÈÉ•©•ÑÌ…‘‘¥¹œÑ¡•´Ñ¼„…ÉĞ€¡Í¼„ÍÑ…±”Ñ…ˆ…»ŠeĞ½É‘•È½¹”¤¸(¨9•Üè€¨©ÍÑ½É•™É½¹ĞÍ¡½ÀÁ¥­•È¨¨ƒŠP„m‘½Õ¡‰½ÍÍ}Í¡½Á}Á¥­•Éu€Í¡½ÉÑ½‘”…¹„(€Í•±•Ñ½È¥¸Ñ¡”…ÉĞ±•ĞÕÍÑ½µ•ÉÌ¡½½Í”İ¡¥ Í¡½ÀÑ¡•çŠeÉ”½É‘•É¥¹œ™É½´½¸(€µÕ±Ñ¤µÍ¡½ÀÍ¥Ñ•ÌìÑ¡”¡½¥”¥ÌÉ•µ•µ‰•É•…¹É½ÕÑ•ÌÑ¡”½É‘•ÈÑ¼Ñ¡…Ğ(€Í¡½ÃŠeÌ­¥Ñ¡•¸‰½…É¸M¥¹±”µÍ¡½ÀÍ¥Ñ•Ì…É”Õ¹…™™•Ñ•€¡¹½Ñ¡¥¹œ•áÑÉ„Í¡½İ¸¤¸(¨Q¡”5•¹Ô%Ñ•µÌ±¥ÍĞ¹½ÜÍ¡½İÌAÉ¥”…¹Ù…¥±…‰¥±¥Ñä½±Õµ¹Ì¸((ô€È¸Ì¸Ä€ô(¨=É‘•È‰½…É¹½ÜÍ¡½İÌ„Á•ÉÍ¥ÍÑ•¹ĞƒŠqM½Õ¹¥Ì=Štİ…É¹¥¹œ…¹…ÕÑ¼µÉ•ÍÕµ•ÌÑ¡”(€…±•ÉĞ…Õ‘¥¼İ¡•¸Ñ¡”Ñ…‰±•ĞÉ•™½ÕÍ•ÌƒŠP„É•±½…‘•­¥Ñ¡•¸Ñ…‰±•Ğ…¸¹¼(€±½¹•ÈÍ¥ĞÍ¥±•¹Ñ±äÑ¡É½Õ ¹•Ü½É‘•ÉÌ¸(¨•™…Õ±Ğ½É‘•ÈÕÉÉ•¹ä™…±±‰…¬½ÉÉ•Ñ•Ñ¼U¸((ô€È¸Ì¸À€ô(¨9•Üè€¨©ÕÍÑÉ…±¥…¸µ½¹•ä¨¨ƒŠP‘•™…Õ±ÑÌÑ¼U…¹ÍÕÁÁ½ÉÑÌ€¨©MPµ¥¹±ÕÍ¥Ù”(€ÁÉ¥¥¹œ¨¨€¡Ñ…àÍ¡½İ¸…Ì„½µÁ½¹•¹Ğ½˜Ñ¡”ÁÉ¥”°”¹œ¸Ñ½Ñ…°€¼€ÄÄ…Ğ€ÄÀ”°(€É…Ñ¡•ÈÑ¡…¸…‘‘•½¸Ñ½À¤¸ƒŠqAÉ¥•Ì¥¹±Õ‘”MSŠtÍ•ÑÑ¥¹œ½¹ÑÉ½±Ì¥Ğ¸(¨MÑ½É•™É½¹ĞÍ¡½İÌMP…ÌƒŠp¡¥¹±Õ‘•ÌMP€‘`§ŠtÕ¹‘•ÈÑ¡”Ñ½Ñ…°İ¡•¸¥¹±ÕÍ¥Ù”¸(¨=¸ÕÁÉ…‘”°„‘•µ¼UL€¡UM°¹¼Ñ…à¤½¹™¥œ¥Ì±½…±¥Í•Ñ¼U€¬€ÄÀ”MP(€İ¥Ñ¡½ÕĞ½Ù•ÉİÉ¥Ñ¥¹œ„ÍÑ½É”Ñ¡…Ğİ…Ì‘•±¥‰•É…Ñ•±ä½¹™¥ÕÉ•¸((ô€È¸È¸À€ô(¨9•Üè€¨©µÕ±Ñ¤µÍ¡½À™½Õ¹‘…Ñ¥½¸¨¨ƒŠP„M¡½ÁÌ€¼1½…Ñ¥½¹Ì…‘µ¥¸ÍÉ••¸€¡…‘½•‘¥Ğ(€Í¡½ÁÌİ¥Ñ ÍÕ‰ÕÉˆ°…‘‘É•ÍÌ°Á¡½¹”°‘•±¥Ù•ÉäÁ½ÍÑ½‘•Ì°ÁÉ•ÀÑ¥µ”…¹(€Á¥­ÕÀ½‘•±¥Ù•Éä½ÁÑ¥½¹Ì¤¸(¨=É‘•ÉÌ¹½Ü…ÉÉä„±½…Ñ¥½¹}¥‘€ìÑ¡”1¥Ù”=É‘•È	½…É¡…Ì„€¨©Á•ÈµÍ¡½À(€™¥±Ñ•È¨¨Í¼•… Í¡½ÀÌ­¥Ñ¡•¸Ñ…‰±•ĞÍ••Ì½¹±ä¥ÑÌ½İ¸½É‘•ÉÌ¸(¨9•ÜIMP•¹‘Á½¥¹ĞP€½±½…Ñ¥½¹Í€ìP€½…‘µ¥¸½½É‘•ÉÍ€…¹€½¡•­½ÕÑ€(€…•ÁĞ„±½…Ñ¥½¹}¥‘€¸‘•™…Õ±ĞÍ¡½À¥ÌÉ•…Ñ•½¸ÕÁÉ…‘”Í¼•á¥ÍÑ¥¹œ(€Í¥¹±”µÍ¡½ÀÍ¥Ñ•Ì­••Àİ½É­¥¹œÕ¹¡…¹•¸((ô€È¸Ä¸À€ô(¨9•ÜèÉ•…°µÑ¥µ”€¨©1¥Ù”=É‘•È	½…É¨¨€¡­¥Ñ¡•¸‘¥ÍÁ±…ä¤ƒŠP…Ñ¥Ù”½É‘•ÉÌ¥¸(€9•Ü€¼AÉ•Á…É¥¹œ€¼I•…‘ä±…¹•Ì°…¸…Õ‘¥‰±”€¬Ù¥ÍÕ…°…±•ÉĞ½¸¹•Ü½É‘•ÉÌÕ¹Ñ¥°(€…­¹½İ±•‘•°…¹½¹”µÑ…À•ÁĞ€¡İ¥Ñ Q¤…¹ÍÑ…ÑÕÌ¡…¹•Ì¸(¨9•Üè±½ÜµÁÉ¥Ù¥±•”€¨©½Õ¡	½ÍÌ-¥Ñ¡•¸¨¨É½±”€¬µ…¹…•}‘½Õ¡‰½ÍÍ}­‘Í€(€…Á…‰¥±¥ÑäÍ¼„Í¡½ÀÑ…‰±•Ğ…¸ÉÕ¸Ñ¡”‰½…Éİ¥Ñ¡½ÕĞ„™Õ±°…‘µ¥¸±½¥¸¸(¨9•ÜèIMP•¹‘Á½¥¹ÑÌP€½…‘µ¥¸½½É‘•ÉÍ€°A=MP€½…‘µ¥¸½½É‘•È½í¥‘ô½…­€°(€A=MP€½…‘µ¥¸½½É‘•È½í¥‘ô½…•ÁÑ€ì½É‘•ÉÌ¹½Ü…ÉÉä…¸Q…¹(€Í••¸½…­¹½İ±•‘•½…•ÁÑ•Ñ¥µ•ÍÑ…µÁÌ¸(¨I•±¥…‰¥±¥Ñäè½É‘•È€¬±¥¹”¥Ñ•µÌ…É”¹½ÜİÉ¥ÑÑ•¸¥¸„Í¥¹±”‘…Ñ…‰…Í”(€ÑÉ…¹Í…Ñ¥½¸€¡¹¼µ½É”Á…ÉÑ¥…°½É‘•ÉÌ¤°½É‘•È¹Õµ‰•ÉÌ…É”±½¹•Èİ¥Ñ (€½±±¥Í¥½¸µÉ•ÑÉä°…¹€½¡•­½ÕÑ€¡½¹½ÕÉÌ…¸%‘•µÁ½Ñ•¹äµ-•å€Ñ¼ÍÑ½À(€‘ÕÁ±¥…Ñ”½É‘•ÉÌ™É½´‘½Õ‰±”µÍÕ‰µ¥ÑÌ¸(¨%¹Ñ•É¹…°èÙ•ÉÍ¥½¹•‘…Ñ…‰…Í”µ¥É…Ñ¥½¸ÉÕ¹¹•È¸((ô€È¸À¸À€ô(¨%¹¥Ñ¥…°ÁÕ‰±¥Œ‰Õ¥±èµ•¹ÔAP°Á¥éé„‰Õ¥±‘•È°…ÉĞ½¡•­½ÕĞ°½É‘•ÈÑÉ…­¥¹œ°(€…‘µ¥¸½É‘•ÉÌÍÉ••¸…¹Í•ÑÑ¥¹Ì¸(