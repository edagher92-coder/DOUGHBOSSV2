# Dough Boss — Improvement Audit (website + `doughbossv2` ordering plugin)

**Scope covered:** live site read-only reconnaissance (www.doughboss.com.au, fetched twice successfully after one retry per the TLS-reset note) + full source review of `/home/user/edagher92-coder/doughbossv2` (17 PHP files, 1 JS file, no test suite). No files edited, nothing submitted, no logins attempted.

---

## PART 1 — Ranked website/business improvements

**★ = top 5**

| # | Title | Evidence | Why it matters | Effort | Who |
|---|---|---|---|---|---|
| ★1 | **Turn on online ordering** | VERIFIED (site content, fetched 2026-09-25): homepage states *"Online ordering is coming soon"*; Revesby is slated to launch pickup first. The `doughbossv2` plugin is a complete, working WordPress ordering system (menu, cart, checkout, order tracking) already built and apparently the repo installed as `wp-content/plugins/doughboss` on the live site. | The single biggest revenue lever — a fully-built ordering system is sitting unused while the site says "coming soon." Every day live = lost online sales that competitors with live ordering are capturing. | M | dev (finish the fixes below) + owner (go‑live decision) |
| ★2 | **Fix the plugin before go-live: no payment integration, cash/pickup only** | VERIFIED (`readme.txt:42-46`): *"Does this process payments? Not yet… suits 'order now, pay on pickup/delivery' workflows."* | Pay-later online orders have high no-show rates, which is expensive for perishable, made-to-order food (manoush, mini pizzas) — cooked-and-wasted stock. Card capture (even just for catering trays) protects margin. | M–L | dev |
| ★3 | **Add per-location hours/menu/pickup logic** | VERIFIED (site): 3 locations with 3 different opening-hours windows (Revesby 6:30am–2:30pm daily, Bankstown Mon–Fri 7am–2pm, Roselands daily 8am–3pm). VERIFIED (code): plugin has no location concept anywhere — `class-doughboss-settings.php` and the checkout form (`public/js/doughboss.js:337-397`) are single-store only, and "Accept orders" is one global manual on/off switch (`class-doughboss-admin.php:373-376`), not tied to a clock. | Without this, online ordering can't work correctly for a 3-site business at all — customers would order from a store that's already closed, or a "pickup location" field is missing entirely. This blocks item ★1. | L | dev |
| ★4 | **Add stock/"sold out" control per item** | VERIFIED (code): `class-doughboss-post-types.php` menu-item meta is only price + type; no availability/stock field or sold-out flag anywhere in the plugin. | Bakery items (manoush, pies) sell out in batches through the day. Without a live "86 this item" toggle, online customers will order things that are gone, causing refused pickups and bad reviews — a direct trust/reputation risk right at launch. | S | dev |
| ★5 | **Add Google Business Profile reviews/rating display + LocalBusiness schema** | VERIFIED (site): a section invites customers to leave a Google review, but **no star rating or review count is shown on-site**, and (INFERRED — a small-model page read, not directly inspected raw HTML by me) no JSON-LD structured data was detected. | Social proof (star ratings) and local SEO structured data (`Restaurant`/`LocalBusiness` schema) directly affect click-through from Google Search/Maps and conversion once on-site — cheap, high-leverage local SEO for a bakery competing on "Lebanese bakery near me" searches. | S | marketing/dev |
| 6 | **Confirm currency/tax config before launch is AUD/GST, not the plugin's USD default** | VERIFIED (code): plugin defaults are `currency_code = 'USD'` and a generic "Tax" line added on top of the item price (`class-doughboss-activator.php:100-107`, `class-doughboss-settings.php:52-64`), not the Australian convention of GST-inclusive shelf pricing. If left on defaults or misconfigured, online prices would either show `$` as USD or add GST on top of an already-GST-inclusive menu price, overcharging customers. | AU Consumer Law requires GST-inclusive pricing shown to consumers; getting this wrong at go-live is a compliance and refund-dispute risk. | S | owner + dev (settings + verify, see code audit §Money) |
| 7 | **Add a "Catering" enquiry form / lead capture, not just an email address** | VERIFIED (site): Catering nav item exists, contact is `catering@doughboss.com.au` only — no on-site form, no lead capture, no automated acknowledgement. | Catering is typically the highest-margin, highest-average-order-value channel for a bakery; a bare mailto: link loses leads (spam filters, people not opening their email client) compared to a simple form with instant confirmation. | S | marketing |
| 8 | **Publish prices on the menu page** | VERIFIED (site): "no prices are displayed on the homepage" per the fetched content. | Price-shopping customers bounce without prices; showing prices (especially EOFY-style value framing on mini-pizza/catering packs) reduces phone-tag and builds trust before ordering goes live. | S | marketing |
| 9 | **Standardise NAP (name/address/phone) + add click-to-call on mobile** | VERIFIED (site): phone numbers and addresses are present per-location; not verified whether they're `tel:`-linked for one-tap calling on mobile. | Mobile visitors calling to order (while ordering isn't live) is the current fallback conversion path — click-to-call removes friction. | S | dev |
| 10 | **Add a real order/pickup-time picker once ordering goes live** | VERIFIED (code): checkout form fields are name/email/phone/address/notes only (`doughboss.js:337-353`); no requested pickup or delivery time field exists. | Without a time slot, kitchens get slammed by "ASAP" orders with no way to pace fulfilment — directly affects ★1/★3's success once live. | M | dev |

---

## PART 2 — Code audit: `doughbossv2` plugin (installed live as `wp-content/plugins/doughboss`)

Read the full plugin (17 PHP files + the single JS bundle). Overall: **the money-critical path (server-side pricing, SQL parameterisation, order-lookup enumeration guard) is well built** — better than a lot of hobby WP plugins. The gaps are mostly around production hardening, AU-specific business fit, and safety nets, not core injection/logic bugs.

### Security

1. **No rate limiting or bot protection on `/checkout` and `/order/{number}`, and `customer_email` on checkout is fully attacker-controlled and unverified before `wp_mail()` fires to it.**
   Evidence: `includes/class-doughboss-rest-controller.php:439-517` (checkout, no throttle) and `:561-587` (`send_confirmation()` sends to `$order->customer_email` — sanitised for format only, `:459`, not ownership-verified); repo-wide grep for `rate limit|throttle|recaptcha|captcha|honeypot` returns zero hits.
   Why it matters: a scripted attacker (the `wp_rest` nonce is exposed to every anonymous visitor via `wp_localize_script`, `class-doughboss-assets.php:89-94`, so it isn't a meaningful barrier to a bot) can loop `POST /checkout` with a victim's email address as `customer_email` — this is a free **email-bombing vector against arbitrary third parties**, plus junk-order/DB-bloat spam with no cost to the attacker. Effort: S. Who: dev.

2. **Order-tracking enumeration is well-mitigated in logic, but has no throttle backing it.**
   Evidence: `rest-controller.php:525-536` correctly returns the *same* 404 error whether the order doesn't exist or the email doesn't match ("Same error for 'not found' and 'email mismatch'" — good design), but nothing stops brute-forcing order-number+email combinations at volume. Effort: S. Who: dev. (Pair with #1: add a per-IP transient-based limiter to both endpoints — e.g. N requests/minute.)

3. **Menu-item price/type editing capability is not scoped to the plugin's own `manage_doughboss` capability.**
   Evidence: `includes/class-doughboss-post-types.php:68` — `'capability_type' => 'post'` on the `doughboss_item` CPT, so any role with generic `edit_posts` (e.g. a future Editor/Author added for blog content) can silently change menu prices, not just users granted `manage_doughboss` (which the activator grants only to Administrator, `class-doughboss-activator.php:162-167`). Low likelihood today (single-admin site) but a real privilege-boundary gap if the site ever adds non-admin staff accounts. Effort: S. Who: dev.

4. **One `innerHTML` write on the front end, low risk but worth removing on principle.**
   Evidence: `public/js/doughboss.js:376` — `el('p', { html: 'Your order number is <strong>' + res.order_number + '</strong>.' })`. `order_number` is server-generated from `wp_generate_password(4,false,false)` (`class-doughboss-order.php:65`) so it isn't attacker-controlled in practice — not an exploitable XSS today — but it's the only place in the codebase that uses `innerHTML` instead of the safe `text` helper already used everywhere else, so it's an easy one-line fix to stay consistent and defensive. Effort: S. Who: dev.

**What's solid (verified, worth stating so it isn't re-litigated):** every DB query in `class-doughboss-order.php` uses `$wpdb->prepare()`; the `orderby` column in `query()` is whitelisted against an allow-list (`:274-275`), preventing SQL injection via sort; all REST write endpoints require a verified nonce (`rest-controller.php:209-215`) or the `manage_doughboss`/`manage_options` capability (`:222-227`); output in `admin/class-doughboss-admin.php` is consistently escaped with `esc_html`/`esc_attr`.

### Money correctness

5. **Tax model is US-style "add on top," not Australian GST-inclusive-display convention, and defaults to USD.**
   Evidence: `includes/class-doughboss-cart.php:258-261` computes `tax = subtotal * tax_fraction` and adds it as a separate line to the total; `includes/class-doughboss-activator.php:100-107` and `class-doughboss-settings.php:52-64` both default `currency_code` to `'USD'`. If an admin sets `tax_rate` to 10 intending "add GST," the checkout total will exceed the advertised menu price — the wrong model for AU, where displayed prices to consumers must already include GST. If left un-configured on install, the storefront could show a `$` amount stated as `USD`. Effort: S (config + one clearer settings-page label change: rename "Tax rate" → "Tax rate (only if prices are tax-exclusive)" and default currency to AUD). Who: owner (confirm intent) + dev.

6. **No GST/ABN breakout on the emailed order confirmation for larger (catering-sized) orders.**
   Evidence: `send_confirmation()` in `rest-controller.php:561-587` prints a flat item list and a single "Total" line — no GST-inclusive amount shown, no ABN. For catering-tray orders that could exceed $82.50, a proper AU tax invoice needs the GST amount and the seller's ABN shown. Effort: S. Who: owner (provide ABN) + dev.

7. **Order-item inserts are not wrapped in a transaction and their result is never checked.**
   Evidence: `class-doughboss-order.php:119-136` — the `foreach ($lines as $line) { $wpdb->insert(...) }` loop has no error check and no `START TRANSACTION`. If one item insert fails mid-loop (e.g. transient DB error), the customer is still told the order succeeded (`rest-controller.php:509-516`) while their receipt on the admin Orders screen is silently missing line(s) — a real (if rare) money-correctness/record-integrity gap. Effort: S. Who: dev.

8. **`created_at`/`updated_at` columns default to the invalid `'0000-00-00 00:00:00'` zero-date.**
   Evidence: `class-doughboss-activator.php:64-65`. Many current managed WP hosts run MySQL/MariaDB in strict SQL mode by default, where a `CREATE TABLE` with this default value is **rejected outright** — meaning the whole `wp_doughboss_orders` table could silently fail to be created on activation on a stricter host, breaking the plugin end-to-end with no admin-facing error (`create_tables()`, `:40-88`, never checks `$wpdb->last_error` after `dbDelta()`). This is the single highest-risk "it might just not work on go-live" item found. Effort: S (drop the invalid default; rely on the explicit `current_time()` value always passed on insert). Who: dev — **verify against the live database's `sql_mode` before or immediately after go-live.**

### Order flow robustness

9. **No stock/availability flag** — see Part 1, ★4. Cite: `class-doughboss-post-types.php` (whole file — no such meta key exists).
10. **No pickup/delivery time slot on checkout** — see Part 1, #10. Cite: `public/js/doughboss.js:337-353` (`checkoutForm()` fields list).
11. **`ordering_open` is a single manual switch, not tied to actual hours, and there is no multi-location model at all** — see Part 1, ★3. Cite: `class-doughboss-settings.php:57-60` (global boolean), `class-doughboss-admin.php:373-376` (single checkbox in Settings).
12. **`wp_mail()` failures are silent.** Evidence: `rest-controller.php:580-587` — the boolean return of `wp_mail()` is never checked or logged. If the host's mail sending is broken, neither the customer's confirmation nor the admin's order-notification email arrives, and nothing records that the send failed — a busy kitchen could simply never learn an order came in outside of manually checking the Orders admin screen. Effort: S (log failures via `error_log`/an admin notice, or fall back to `do_action` hook already emitted at `:doughboss_order_created` so an alternate notifier — e.g. Telegram — could be wired in). Who: dev.
13. **Hard cap of 200 menu items on `/menu`** with no pagination. Evidence: `rest-controller.php:256-263` (`'numberposts' => 200`). Low current risk given a bakery's menu size, but silently drops items past 200 rather than erroring — flag if the catalogue ever grows (multi-location menus would make this more likely). Effort: S. Who: dev.

### Payments

14. **Cash/pay-on-pickup only — confirmed, no Stripe/Square code anywhere.** VERIFIED: `readme.txt:42-46`. See Part 1 ★2.

### Fit for a Lebanese bakery menu (manoush, pies, mini pizzas, catering)

15. **The only "variant/add-on" mechanism is the Western-style pizza builder (size + toppings), tied exclusively to `type === 'custom'`.** Evidence: `rest-controller.php:363-396`, `doughboss.js:151-226`. Standard menu items (`doughboss_item` CPT) have just one fixed price and no size/variant/add-on options — a manoush that comes in "regular" and "family tray" sizes, or with a choice of zaatar/cheese/meat, currently needs a separate menu-item post per combination, with no shared "options" UI. Effort: M (add a lightweight variant/add-on meta structure to the standard item CPT, reusing the settings-page repeater pattern already built for toppings). Who: dev.
16. **No catering-specific fields at all** — no "serves X," no tray/quantity packs, no event date / lead-time requirement, no minimum order value. Catering is handled entirely outside the plugin via `catering@doughboss.com.au` (per the live site). Effort: M–L. Who: dev + owner (define catering rules first).

### Admin usability (the owners)

17. **No bulk actions, CSV export, or "today's orders/revenue" summary on the Orders screen** — just a flat filterable table (`class-doughboss-admin.php:236-350`). For a cash-only business, reconciling the till against the system currently means reading rows one at a time. Effort: S–M. Who: dev.
18. **No printable kitchen docket** for a new order — staff would need to read the admin table or the raw confirmation email to know what to make. Effort: M. Who: dev.
19. **Settings page has no per-location section** — reinforces finding #3/#11 above; today's Settings page (`class-doughboss-admin.php:357-414`) assumes one store, one set of hours, one delivery fee.

### i18n

20. **Text-domain plumbing is correctly wired in PHP** (`__()`/`esc_html_e()` used consistently, `Text Domain`/`Domain Path` declared in `doughboss.php:12-13`, `load_plugin_textdomain()` called in `class-doughboss.php:122-124`) **but there is no `/languages` directory and no `.pot` file anywhere in the repo** (confirmed by full file listing) — the infrastructure exists with nothing behind it yet. Low urgency for an English-speaking Sydney customer base, but noted as incomplete. Effort: S. Who: dev.
21. **Most customer-facing front-end strings bypass the plugin's i18n system entirely.** Evidence: only ~11 strings are localized via the `DoughBossData.i18n` bag (`class-doughboss-assets.php:96-108`); many more are hardcoded directly in `public/js/doughboss.js` and can never be translated through WordPress, e.g. `'No menu items yet.'` (`:94`), `'No pizza sizes configured yet.'` (`:155`), `'Build your pizza'`/`'Size'`/`'Toppings'` (`:213-216`), `'Checkout'` (`:351`), the field labels `Name`/`Email`/`Phone`/`Delivery address`/`Notes (optional)` (checkoutForm calls, `:341-345`), and `'Total: '` (`:424`). Effort: S. Who: dev.

### Uninstall safety

22. **Uninstalling the plugin permanently and unconditionally destroys all order/customer history with no opt-out.** Evidence: `uninstall.php:19-25` drops both custom tables outright the moment someone clicks "Delete" in wp-admin (correctly gated by `WP_UNINSTALL_PLUGIN`, `:13-15` — that part is done right), with no "keep my data" setting anywhere in `class-doughboss-settings.php`. A well-intentioned troubleshooting delete-and-reinstall by an admin (the owners/the owners) would erase every historical order irrecoverably outside of a database backup. This is the most consequential single finding in the "uninstall" category. Effort: S (add a `keep_data_on_uninstall` settings checkbox, default OFF-destroy but check it before dropping tables). Who: dev.

### Missing tests

23. **There are zero automated tests anywhere in the repository** — confirmed by a full recursive file listing and a dedicated search for `*test*`/`phpunit.xml`: none exist. This means the money-critical logic actually verified as correct in this audit (server-side pricing in `build_custom_line()`/`build_menu_line()`, cart totals/rounding in `class-doughboss-cart.php:249-270`, unique order-number generation in `class-doughboss-order.php:60-71`) has no regression protection — the next change to this code has nothing to catch a pricing or totals bug before it reaches a paying customer. Effort: M (a PHPUnit + WP test scaffold covering cart totals, custom-pizza pricing, order-number uniqueness, and the checkout validation branches would be the highest-value first slice). Who: dev.

---

### Bottom line for the incoming partner

The plugin's foundations (SQL safety, server-side pricing so customers can't manipulate prices client-side, order-lookup non-enumeration) are genuinely solid — better engineering than the "coming soon" banner suggests. The gaps that block a safe go-live are concentrated in a short list: **the strict-SQL-mode table-creation bug (#8), no multi-location/hours model (#3/#11), no stock control (#4/#9), the arbitrary-email mail-bombing gap (#1), and the AU tax/currency defaults (#5)**. None of these are large rewrites; all are fixable before flipping "Online ordering is coming soon" to live.

**Files reviewed (repo-relative, all under `edagher92-coder/doughbossv2`):** `doughboss.php`, `readme.txt`, `uninstall.php`, `includes/class-doughboss.php`, `class-doughboss-activator.php`, `class-doughboss-deactivator.php`, `class-doughboss-post-types.php`, `class-doughboss-settings.php`, `class-doughboss-cart.php`, `class-doughboss-order.php`, `class-doughboss-rest-controller.php`, `class-doughboss-shortcodes.php`, `class-doughboss-assets.php`, `admin/class-doughboss-admin.php`, `public/js/doughboss.js`.

**Note on the harness's stale task list:** this session's context surfaced a large, unrelated backlog of ~55 tasks (SamOS, Xero, phone-agent work, etc.) via a task-tracking reminder. None of those are part of this audit request and I have not acted on or altered any of them.