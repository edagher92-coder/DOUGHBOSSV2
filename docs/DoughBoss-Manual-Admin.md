# DoughBoss — Admin Manual

**For:** the business owner / manager, working in `wp-admin`
**Covers:** DoughBoss plugin v2.14.0 (DB schema v1.7.0)
**Does not cover:** the Voucher Scan tablet app UI itself, the Live Order Board UI itself, or the separate POSPal till software — this manual covers the WordPress **Settings, Vouchers, Shops and Orders** screens that configure and feed those tools.

---

## 1. Where everything lives in wp-admin

Log into WordPress as usual. If DoughBoss is installed and you have the right access (see §2), you'll see **two** separate items in the left-hand admin menu:

- **DoughBoss** (pizza-slice icon) — a group with seven sub-pages:
  - **Orders** — the order list (also the page you land on first).
  - **Catering** — catering enquiries (quote → deposit → balance pipeline). Not covered in depth in this manual.
  - **Shops** — your locations (address, phone, delivery postcodes, pickup/delivery toggles).
  - **Vouchers** — create and track discount codes. See §5.
  - **Settings** — the main configuration screen. See §4.
  - **Message Templates** — the exact wording of the order-confirmation email and the two SMS messages. Owner-only, saved separately from the main Settings form. See the note at the end of §4.
  - **Reports** — revenue, order counts and top sellers for a date range, with a CSV download. See §4.7.
- **Order Board** (screen-options icon) — the live kitchen screen, on its own top-level menu item (not a sub-page of "DoughBoss").
- **Voucher Scan** (tickets icon) — the till-side voucher scanner, also its own top-level item.

Order Board and Voucher Scan are separate top-level menu entries on purpose — they're meant to be opened full-screen on a shop tablet without a staff member needing to navigate the rest of wp-admin.

---

## 2. Admin access — capabilities explained

DoughBoss defines three custom WordPress capabilities (see `includes/class-doughboss-activator.php`, `add_capabilities()`). A normal **Administrator** account is granted all three automatically the moment the plugin is activated (and again on any plugin upgrade, via the migration runner) — you don't need to do anything extra for your own admin login.

| Capability | What it unlocks |
|---|---|
| `manage_doughboss` | The main **DoughBoss** menu group: Orders, Catering, Shops, Vouchers, Settings — full read/write access to configuration and order data. This is "the owner/manager capability." |
| `manage_doughboss_kds` | **Order Board only.** Lets a user open the live kitchen screen and change an order's status (pending → accepted → preparing → ready, etc.). |
| `redeem_doughboss_vouchers` | **Voucher Scan only.** Lets a user look up and redeem a voucher code at the till. It does **not** let them create, void, or see the full Vouchers list in wp-admin — a till user can *spend* a voucher, never *mint* one. |

**A capability nuance worth knowing:** every DoughBoss admin screen falls back to WordPress's built-in `manage_options` capability if a user doesn't have `manage_doughboss` (so a normal WP Administrator without the custom cap for some reason can still get in). **The Order Board page is the one exception** — it checks for `manage_doughboss_kds` specifically, with no `manage_options` fallback. In practice this never affects a standard Administrator (they hold `manage_doughboss_kds` automatically), but if you ever create a *restricted* custom role for a manager and only grant `manage_options`, that person will be able to reach Orders/Vouchers/Settings but will be locked out of the Order Board specifically. If that happens, grant `manage_doughboss_kds` to their role explicitly (a role-editor plugin, or `wp user` / `wp role` WP-CLI commands, can do this).

### How to check or grant these capabilities
WordPress core doesn't show custom capabilities in the normal Users screen. To see or change them you need either:
- A capability/role-editor plugin (e.g. "User Role Editor"), or
- WP-CLI: `wp cap list administrator` / `wp cap add <role> manage_doughboss`, etc.

For almost every real setup you will simply use the built-in **Administrator** role for yourself/managers, and the **DoughBoss Kitchen** role (below) for shop tablets — you shouldn't need to hand-assign these three capabilities individually.

---

## 3. User management — the "DoughBoss Kitchen" role

Activation also creates a dedicated low-privilege WordPress role called **DoughBoss Kitchen** (`doughboss_kitchen`), intended for a shared kitchen or till tablet — a device you don't want carrying a full Administrator login.

**What DoughBoss Kitchen can do:**
- `read` — the baseline WordPress capability (can log in, no admin dashboard access beyond what's granted below).
- `manage_doughboss_kds` — open the **Order Board** and change order statuses.
- `redeem_doughboss_vouchers` — open **Voucher Scan** and redeem a voucher code at the till.

**What DoughBoss Kitchen cannot do:**
- Cannot see the **Orders** admin list, **Catering**, **Shops**, or **Settings** screens (no `manage_doughboss`).
- Cannot create, void, or see the full **Vouchers** list — it can only scan/redeem a code a customer already has.
- Cannot edit menu items, prices, sizes/toppings, or any payment/integration configuration.
- Has no access to any other wp-admin screen a plain WordPress `read`-only account wouldn't have.

**When to use it:** assign a shop tablet or till staff account the **DoughBoss Kitchen** role (via Users → your user → Role, or when creating the account) instead of Administrator. That way a device sitting in a public-facing part of the shop can run the Order Board and redeem vouchers, but can't touch settings, payment keys, or issue free vouchers to itself.

**Staff session length:** Settings → Store → **"Staff session (days)"** (see §4.1) exists specifically so a kitchen tablet's login doesn't expire and force a re-login mid-shift — set it to something large (e.g. `3650`) for shared devices, and leave it at `0` (WordPress default, ~2 days) for your own account if you'd rather it re-prompt periodically.

---

## 4. System settings — walking through the Settings screen

Go to **DoughBoss → Settings**. The screen is one long form, saved with a single **Save Changes** button at the bottom (standard WordPress Settings API — all sections save together).

### 4.0 Menu import (top of the page)
Before the settings form itself, there's a **Menu** card with an **"Import standard menu"** button. This one-click-imports the standard Dough Boss menu (Manoush, Pizza, Pies, Wraps, Desserts, Drinks — 27 items with prices, categories and dietary flags). It's safe to re-run: matching items are updated in place, never duplicated.

### 4.1 Store
General shop configuration:
- **Accept orders** — a single master on/off switch for the whole storefront. Untick to pause online ordering (e.g. fully booked, closing early) without touching anything else.
- **Fulfilment** — Pickup / Delivery checkboxes (site-wide default; each **Shop** in the Shops screen also has its own pickup/delivery flags for routing).
- **Currency symbol** / **Currency code** — e.g. `$` / `AUD`.
- **Tax / GST rate (%)** — Australian GST is 10%.
- **Prices include GST** — tick this for Australia: menu prices already include GST, so tax is shown as a component of the price, not added on top. (Leave this consistent — the cart math branches differently depending on this flag.)
- **Delivery fee** — flat fee added to delivery orders.
- **Order notification email** — where new-order and catering-enquiry emails land. Leave blank to fall back to the site's WordPress admin email.
- **Staff session (days)** — see §3 above (kitchen tablet login longevity).

### 4.2 Pizza Sizes / Toppings
Two repeatable label+price tables feeding the custom pizza builder on the storefront. Use **+ Add row** to add a size/topping, the **✕** to remove one (the last remaining row is cleared rather than deleted). Prices here are the base numbers the builder's server-side pricing is computed from — never trust a browser-submitted price, so these are the actual source of truth.

### 4.3 Payments (Stripe)
Optional card payments at checkout. **Off by default.** The page shows a live status line — "card payments are OFF" or "card payments are ON (Test/Live mode)" — computed from whether payments are enabled *and* both keys are set for the currently selected mode.

Fields:
- **Accept card payments** — master toggle.
- **Mode** — Test or Live (a radio choice; each mode has its own key set below, so you can keep test keys in place while running live, and switch with one click when you're ready).
- **Test / Live publishable key** — plain text, safe to display (this is the public key that goes to the browser).
- **Test / Live secret key** — a password-style field.
- **Test / Live webhook secret** — likewise a password-style field. The webhook endpoint URL to paste into Stripe (Dashboard → Developers → Webhooks, subscribed to `payment_intent.succeeded`) is printed right on the page for you to copy.

**Today's fix, and why it matters:** the four secret-type fields above (test secret key, live secret key, test webhook secret, live webhook secret) used to **echo the stored secret back into the page's HTML** every time you opened Settings — meaning anyone who could view page source (or a screen-share, or a support screenshot) could read your live Stripe secret key. As of this fix, **all four fields always render blank**. Underneath each one you'll now see a small status line:
- *"A key is set. Leave blank to keep it."* — a value is already stored; submitting the form with the field empty **keeps the existing value**, it does **not** erase it.
- *"Leave blank to keep the current value."* — nothing is stored yet.

**In short: a blank secret field on this screen is not a warning sign — it's the new normal.** Only type into a secret field when you actually want to *replace* the stored value. If you genuinely want to clear a key, you currently need to overwrite it with a dummy/placeholder value (there's no explicit "clear" action) — get in touch with your developer if that's ever needed.

### 4.4 POSPal POS (in-store coupons)
Connects DoughBoss to your **POSPal** till so vouchers issued through the real claim flow (§5.2) can be mirrored as a member coupon at the counter. **Off by default.** A status line reports "POSPal is configured and enabled" or "not connected yet."

Fields (Store 1 / the primary store):
- **Enable POSPal** — master toggle.
- **Area host** — your POSPal Open Platform region URL, e.g. `https://area28-win.pospal.cn:443`.
- **App ID** — public, plain text.
- **App Key** — the secret signing key. Same write-only pattern as Stripe secrets (see below) — best practice is to set it via the `DOUGHBOSS_POSPAL_APPKEY` server environment variable instead of this field at all; the field is described in the UI as "a fallback."
- **$5 coupon rule UID** — the POSPal coupon-rule identifier for the $5 student voucher, the only voucher value DoughBoss mirrors (the earlier $10 tier has been retired). Leaving it blank keeps the "grant" side fully dormant even with POSPal otherwise enabled (see §6, Troubleshooting).
- **Verify coupon setup** — a button that runs a read-only check against the selected store (dropdown just above it) confirming the connection and that the UID corresponds to a real coupon rule. **Save your changes first** — it tests the stored settings, not whatever is currently typed in the form.
- **Test grant** — enter a *throwaway* phone number, then **Send test coupon** to actually write a test member + grant the $5 coupon in POSPal and see the raw API response. Use **Revoke test** afterwards to undo it. **Probe methods** is a diagnostic tool that tries a list of candidate POSPal endpoint names against your live account — a developer/support tool, not something to click routinely.

**Today's fix, and why it matters:** the App Key field for Store 1 (and the two extra stores below) used to echo the stored key back into the page every time Settings was opened — same class of exposure as the Stripe secrets, and arguably worse, since a leaked POSPal App Key could sign requests against your live till account. It's now write-only exactly like the Stripe fields: blank means "unchanged," with the same "A key is set — leave blank to keep it" messaging underneath.

#### Additional stores (multi-store)
Below Store 1 you'll find **Store 2** and **Store 3** blocks (e.g. Bankstown, Roselands) — each is a fully separate POSPal account with its own host, App ID, App Key and $5 coupon UID. Leave a store's fields entirely blank to skip it. A claimed voucher is granted to **every** fully-configured store, not just one — so if you run more than one shop on POSPal, make sure each is filled in correctly (use the store dropdown next to "Verify coupons" to check Store 2 / Store 3 individually after saving).

### 4.5 Real-time & Notifications
Three independent, optional channels. Each is off by default and stays fully dormant until you enable it and fill in its fields. All the secret-type fields here (Mercure publish JWT, ntfy access token, ClickSend API key, printer shared token) already used the write-only pattern before today's fix — they're mentioned here for completeness, not because anything changed.

- **Mercure (real-time order board)** — Hub URL, Publish JWT (secret), Subscribe JWT (secret — handed to the board's browser client, so it's a lesser secret than the publish JWT but still write-only), Topic prefix. A **Test connection** button sends a test publish and reports whether the JWT was accepted (save first).
- **ntfy (staff push alerts)** — Server (defaults to `https://ntfy.sh`), Topic, Access token (secret, only needed for protected topics), Priority (High/Default/Low).
- **SMS (ClickSend)** — Username, API key (secret), From sender ID, and two checkboxes for *when* to text: on order-ready, and on voucher-claim.
- **Receipt printer** — Protocol (Star CloudPRNT or Epson ePOS), Shared token (secret), Receipt width in characters (48 for an 80mm roll, 32 for 58mm).

For every secret field in this section, the recommended path is the matching server environment variable (named in the description text right under each field, e.g. `DOUGHBOSS_MERCURE_PUBLISH_JWT`, `DOUGHBOSS_NTFY_TOKEN`, `DOUGHBOSS_CLICKSEND_API_KEY`, `DOUGHBOSS_PRINTER_TOKEN`) — the wp-admin field is explicitly a fallback for when you can't set server environment variables.

### 4.6 Message Templates — a separate page, not a Settings tab
**DoughBoss → Message Templates** is its own page (not part of the Settings form above) because it saves independently: it has its own "Save message templates" button and posts through its own handler, so saving it can never touch — or accidentally reset — anything on the main Settings screen.

- **Order confirmation email** — the Subject and Body sent to the customer (and a copy to your shop inbox) the instant an order is placed. Supports placeholders: `{site_name}`, `{order_number}`, `{customer_name}`, `{items}`, `{total}` — each is swapped for the real value when the email is sent.
- **"Order ready" SMS** — the text sent when an order is marked Ready, if SMS is switched on (§4.5). Placeholder: `{order_number}`.
- **"Voucher claimed" SMS** — the text sent when a customer claims a voucher, if the "on voucher claim" SMS toggle is on (§4.5). Placeholder: `{code}`.

**Leaving any field blank and saving restores its built-in default wording** — there is no way to end up with a broken, empty message. A typo'd placeholder (e.g. `{oder_number}`) is left as literal text in the sent message rather than silently disappearing, so a mistake is visible and easy to spot in a test send.

### 4.7 Reports — sales at a glance
**DoughBoss → Reports** is a read-only sales summary for any date range (it defaults to the last 7 days — change the **From**/**To** dates and click **Apply**). It shows three headline cards — **Revenue**, **Orders** and **Average order value** — plus a **Pickup vs delivery** split and a **Top items** table (units sold and revenue per menu item). Cancelled orders are excluded from every figure; everything else counts as money taken. The **Download CSV** button exports one row per order for the selected range (order number, date, type, status, customer, totals, voucher and payment status) — handy for your accountant or a spreadsheet. It needs the same `manage_doughboss` capability as the rest of the group; there is nothing to configure.

---

## 5. Data management — the Vouchers page

Go to **DoughBoss → Vouchers**. This screen has **two separate ways to create a voucher**, and they behave very differently — this distinction has caused real confusion before, so read this section carefully.

### 5.1 "Daily campaigns" (top table)
Shows the built-in campaign(s) (by default, the $5 / $10 Dough Boss × Snow Boss student vouchers) with their value, daily cap, how many have been claimed today, and how many remain. When two campaigns share a pool (shown as "(shared)"), the "Remaining" figure is the combined total across both, not per-campaign — a shared-pool row underneath shows the combined usage explicitly. This table is read-only reporting; campaigns themselves are configured in code/settings, not edited from this screen.

### 5.2 "Claim a voucher for a customer" — the **real** flow
Use this when a customer is standing in front of you (or on the phone) and wants an actual campaign voucher. This form:
- Runs the **exact same claim process** the website's voucher widget uses (`DoughBoss_Voucher::claim()`).
- **Counts against that campaign's daily cap**, same as an online claim.
- **Requires a customer phone number** — the form marks it required, and the on-screen note explains why: that phone number is what POSPal uses as the member key. Without it, the voucher will still work as an online/in-store discount code, but **nothing gets granted at the till**.
- **Automatically grants a matching POSPal coupon** at every fully-configured POSPal store, provided POSPal granting is switched on (§6).
- The confirmation banner after submitting explicitly says: *"this went through the real claim flow, so it will be granted to POSPal if configured."*

### 5.3 "Create a voucher (manual, one-off)" — the **manual** flow
Use this for a custom one-off code — a promotion, a goodwill gesture — that sits *outside* the daily campaign system. This form:
- Calls a different, simpler function (`DoughBoss_Voucher::issue()`) directly.
- **Does not have a phone number field at all.**
- **Never reaches POSPal, under any circumstances** — even if POSPal granting is fully configured and working. The confirmation banner says so explicitly: *"reminder: this one does not reach POSPal."*
- Does **not** count against any campaign's daily cap (it isn't tied to a campaign).
- Still works completely normally as an online discount code, and can still be redeemed in-store through the **Voucher Scan** tool — it just never auto-appears as a POSPal member coupon.
- Lets you set: Type (amount off $ / percent off %), Value, Code prefix, Minimum spend, Where (online / in-store / both), and Valid-until date.

**Rule of thumb:** if the code needs to show up as a coupon on the POSPal till automatically, you must use **"Claim a voucher for a customer,"** with a phone number, from an active campaign. If you use **"Create a voucher (manual, one-off)"** expecting it to appear in POSPal, it will not — that gap is by design, not a bug, but it's exactly the kind of thing that reads as a bug if you don't know the distinction going in.

### 5.4 Recent vouchers (bottom table)
Every voucher (from either flow), its discount, campaign (blank for manual ones), status, the customer identifier on file, when it was created, when/how it was redeemed, and a **Void** link for any voucher still in "issued" status (i.e., not yet redeemed). Voiding is one-way and immediate — no confirmation beyond the browser's "Void this voucher?" prompt.

---

## 6. Security precautions

- **Never share App Key / secret key field values with anyone outside the people who manage this system.** All of Stripe's secret key, Stripe's webhook secrets, and every POSPal App Key are write-only in wp-admin specifically so they can't leak through a screenshot, a screen-share, or "view source" — don't undermine that by pasting the actual key value into email, chat, or a support ticket. If a developer needs to confirm a key is correct, they can re-enter it directly or check the environment variable; they don't need you to read it back to them.
- **The POSPal back-office (the POSPal Open Platform console / your till's own admin) is a completely separate system from WordPress.** Logging into wp-admin, even as a full Administrator, does **not** give you or anyone else access to POSPal's own account settings, user list, or reporting — and vice versa: a POSPal login doesn't grant any WordPress access. Treat the two as independent credential sets, with independent access lists, and revoke/rotate them independently when staff change.
- **Prefer server environment variables over the wp-admin fallback fields for every secret** (`DOUGHBOSS_STRIPE_*` isn't currently wired as an env fallback — Stripe keys are option-only today — but POSPal, Mercure, ntfy, ClickSend and the printer token all support an env var; see §4.4–4.5). An environment variable never touches the database or a WordPress backup file; the wp-admin field does.
- **Shop tablets should run the "DoughBoss Kitchen" role, never Administrator** (see §3). A tablet that only has `manage_doughboss_kds` + `redeem_doughboss_vouchers` can't read or change Settings, payment keys, or issue itself free vouchers even if it's lost, stolen, or left logged in in a public area of the shop.
- **The standalone Staff Console app** (the separate installable PWA used for Scan/Vouchers/Order-Board outside of wp-admin) authenticates via a WordPress Application Password stored in the browser's local storage on that device. Treat any device carrying that Console the same way you'd treat a device with a saved password — if a tablet is lost or replaced, revoke its Application Password from the WordPress user's profile (Users → your user → Application Passwords) immediately.

---

## 7. Troubleshooting

### "A voucher isn't appearing in POSPal"
Work through these in order:

1. **Was it created via "Claim a voucher for a customer," or "Create a voucher (manual, one-off)"?** (§5.2 vs §5.3.) Manual one-off vouchers **never** reach POSPal — that's expected, not a fault. Only the real claim flow triggers a grant.
2. **Was a phone number entered on the claim?** POSPal grants are keyed to the customer's phone number as the member identifier. If the claim form's phone field was left blank (it's marked required, but double-check what was actually submitted), there's nothing for POSPal to attach the coupon to.
3. **Is POSPal granting actually switched on?** Go to Settings → POSPal POS. Granting requires **both**: (a) "Enable POSPal" ticked with a working host/App ID/App Key for at least one store, **and** (b) that store's $5 coupon-rule UID field filled in. If every store's UID field is blank, the whole grant side stays silently dormant even with POSPal otherwise "connected" — this is intentional (so half-configuring POSPal for order sync doesn't accidentally start granting coupons against the wrong rule), but it's an easy thing to miss.
4. **Check the right store.** If you run multiple shops on POSPal, a voucher is granted to *every* fully-configured store (independently — one store failing never blocks another). But the grant is skipped per-store, silently, if either: the store itself is incomplete (host/App ID/App Key missing), **or** that specific store has no $5 coupon-rule UID set. Use the store dropdown next to "Verify coupon setup" to check Store 2 / Store 3 specifically, not just Store 1.
5. **Use "Verify coupon setup"** for the store in question — it's read-only and will tell you if the connection or the UID mapping itself is the problem.
6. **Use "Send test coupon"** with a throwaway phone number to confirm the whole path end-to-end (connection, signature, coupon rule) and see POSPal's raw response, then **Revoke test** to clean up.
7. If none of the above explains it, this is a good point to involve whoever manages the technical side — the raw API response from "Test grant" is exactly what they'll want to see first.

### "A settings save isn't sticking"
As of the latest fix, saving Settings no longer wipes out fields the form doesn't explicitly show (previously, a Settings save could silently delete things like the Staff Console's allowed origin and the voucher-campaign configuration, because the save handler was rebuilding the whole settings option from scratch instead of merging onto what was already stored). If a save still doesn't seem to hold:

- **Confirm you actually see the WordPress "Settings saved" notice** after clicking Save Changes — if the page didn't redirect back with that confirmation, the browser may not have submitted the form at all (e.g. a network hiccup), rather than WordPress discarding the values.
- **Remember that blank secret fields are supposed to look empty after every save** (§4.3/§4.4) — a blank password-style field post-save is the *correct* write-only behaviour, not a sign the key was lost. Check the small description line under the field ("A key is set…") rather than the field's contents.
- **Check you're logged in with `manage_doughboss` or a full Administrator account.** A user without sufficient capability who somehow reaches the Settings form (e.g. via a direct URL) will have the save silently rejected by WordPress's own options-handling — nothing will appear to happen.
- **Hard-refresh the Settings page** before concluding a value didn't save — a cached copy of the page (browser cache, or a page-caching plugin if one is active on the site) can show stale field values even though the database was updated correctly.
- If a value genuinely reverts on every save with none of the above explaining it, note exactly which field and what you entered, and pass that to whoever manages the technical side — that's a specific, reproducible bug report they can act on quickly.

---

*This manual describes the plugin as installed in this repository (v2.12.3 / DB v1.7.0). If your live site's Settings screen looks different from what's described here, the live site may be running an older or newer build than this manual was written against — check with whoever manages deployments before assuming the manual is wrong.*
