# DoughBoss — User Manual

**For:** customers ordering food, and kitchen/till staff running the order board
**Covers:** DoughBoss plugin v2.12.3 (DB schema v1.7.0)
**Does not cover:** `wp-admin` configuration (Settings, Shops, Vouchers admin screens) —
see `docs/DoughBoss-Manual-Admin.md` for that. This manual covers what the two
*other* audiences actually click on: the person ordering food, and the person
in the kitchen or at the till fulfilling it.

**A note on scope, read this first:** DoughBoss the WordPress plugin (the real,
working ordering system described below) is separate from the **concept demo**
site in this repository's `demo/` folder (`index.html`, `staff.html`,
`backend.html`, `owner.html` and friends), which is a self-contained,
click-through mock-up built to sell the idea — it has no real backend, its
"staff sign-in" accepts any staff ID/passcode, and every order shown on it is
fictional sample data. §7 of this manual explains what each demo page is *for*
and how it differs from the real system. Everywhere else in this manual, "the
system" means the actual WordPress plugin — the shortcode-driven storefront a
real customer orders from, and the real `wp-admin` **Order Board** a real
kitchen uses. This document could not verify the current state of the live
`doughboss.com.au` site (no live-site connection was available) — if something
here doesn't match what you see on the live site, the site may be running a
different build than the one this manual was written against.

---

## Part A — For customers

### A.1 Browsing the menu

The menu lives wherever the site owner has placed the `[doughboss_menu]`
shortcode. It loads live from the kitchen's own menu list and groups items by
category (for a shop that's used the built-in menu import, that's typically
things like Manoush, Pizza, Pies, Wraps, Desserts, Drinks). Each item shows a
photo (if one's been added), a short description, and its price.

- If an item is temporarily unavailable, its card is greyed out with a
  **Sold out** badge and the "Add to cart" button is disabled — you can still
  see it, you just can't order it right now.
- If a shop runs more than one location, you may also see a **shop picker**
  first (the `[doughboss_shop_picker]` block). Choosing a shop there is what
  routes your order to the *right* kitchen — the storefront remembers your
  choice for next time. If there's only one shop, this step is skipped
  automatically and you won't see a picker at all.

### A.2 Building a custom pizza

If the site includes the pizza builder (`[doughboss_builder]`), it works in
three steps:

1. **Pick a size.** Sizes and their prices are whatever the shop has configured
   — there's no fixed "Small/Medium/Large" list built into the system, so what
   you see is genuinely this shop's own menu. (If a shop hasn't set up any
   sizes yet, the builder will say "No pizza sizes configured yet." instead of
   showing empty options.)
2. **Pick toppings.** Each topping you tick adds its own price; the running
   total at the bottom updates live as you tick and untick boxes.
3. **Add to cart.** The button briefly shows "Added!" then resets.

**Why the price you see is always trustworthy:** the total shown while you
build is calculated from the size and toppings you picked, but the price that
actually gets charged is *recomputed on the server* from the shop's current
Settings the moment you add it to your cart — your browser's number is never
what's billed. This is deliberate: it's the same reason a shop can safely
change a topping's price in Settings without worrying about a stale price
being charged from someone's already-open browser tab.

### A.3 The cart

Open the cart (`[doughboss_cart]`) to see everything you've added. From there
you can:

- **Change quantity** with the number field on each line, or **Remove** a line
  entirely.
- **Choose Pickup or Delivery** — only the fulfilment types the shop actually
  offers are shown as options.
- **Choose your shop**, if the shop runs more than one location and you didn't
  already pick one — this determines whose kitchen board your order lands on.
- See a running **Subtotal / Tax / Delivery fee / Total**. If the shop prices
  in GST-inclusive mode (the Australian default), tax isn't shown as a separate
  line added on top — it's called out underneath the total instead, e.g.
  *"(includes GST $X.XX)"*, because it's already baked into the prices you see
  on the menu.

### A.4 Applying a voucher

There are two different ways a voucher code ends up in your hands, and one
place you use it:

**Getting a code.** Some shops run a **"Claim your student voucher"** widget
(or similar) right on the site — you tap an offer (e.g. "$5 off" or "$10
off"), enter your mobile number, and you're given a single-use code on the
spot (with a scannable QR code, if your device supports it). These offers are
capped per day; if a campaign has already given out its full daily allowance,
you'll see *"Today's vouchers have all been claimed — check back tomorrow."*
Other codes are handed to you directly — a one-off promotion, a goodwill
gesture from the shop, etc.

**Using a code.** However you got it, enter it in the **voucher box** on the
cart screen and tap **Apply**. If it's valid, you'll see the discount applied
to your total immediately (e.g. *"$5.00 off applied."*) along with the code
next to "Discount" in your totals. You can remove it any time before checking
out with the **Remove** link next to the applied voucher.

**Important: applying a voucher in your cart doesn't spend it yet.** The
system only actually "burns" a single-use voucher at the moment you complete
checkout — applying it to your cart is just a live preview of the discount.
So if you apply a code, get distracted, and never finish the order, the code
is **not** wasted — it's still good the next time you come back and enter it.

### A.5 Checking out

Fill in your name, email, phone, and (for delivery) your address, plus any
notes for the kitchen. Tap **Place order** (or **Pay $X**, if the shop has
card payments turned on — see below) and you'll land on a confirmation screen
showing a message, **your order number**, and the total. Hang on to the order
number — it's how you track your order afterwards (§A.6).

Order numbers look like `DB-YYMMDD-XXXXXX` — e.g. `DB-260703-K7QF3M` for an
order placed on 3 July 2026 — a date stamp plus six random letters/digits, so
two orders never collide even on a very busy day.

A few things that can stop checkout, all shown to you as a clear message
rather than a silent failure:
- **Online ordering is temporarily closed.** The shop has a single master
  switch for this — if it's off, you'll see *"Online ordering is currently
  closed."* instead of being able to submit.
- **A required field is missing** — name, a valid email, and phone are always
  required; a delivery address is required only for delivery orders.
- **Your cart is empty.** Add something first.
- **Too many attempts in a short window.** Checkout is limited to a handful of
  submissions per visitor every ten minutes as an anti-abuse measure — if you
  hit that limit (e.g. from repeatedly retrying a failing card), you'll be
  asked to wait a few minutes rather than being blocked outright.

**If the shop accepts card payments (Stripe):** when this is switched on, the
"Place order" button becomes **"Pay $X"** and a card entry field appears.
Tapping it: creates a secure payment behind the scenes, asks your bank to
confirm the card, and only then submits the order — the same total the
checkout screen showed you is the exact amount that's charged and verified
server-side, so a stale or tampered price can never slip through. If the shop
hasn't turned card payments on yet, "Place order" simply places the order with
no payment step, exactly as before.

**If your connection drops mid-checkout,** it's safe to just try again (the
same button, same form) — behind the scenes each attempt carries a one-time
identifier, so a genuinely duplicate submission returns your original order
confirmation again instead of creating a second, duplicate order and charging
you twice.

### A.6 Tracking your order by order number

If the site includes the order-tracking block (`[doughboss_order_tracking]`),
enter your **order number** and the **email address you used on the order**.
You'll see the current status in plain language:

| What you'll see | What it means |
|---|---|
| Pending | Placed, waiting for the shop to accept it |
| Confirmed | The shop has accepted it |
| Preparing | Being made |
| In the Oven | Baking |
| Ready for Pickup | Ready — come and get it |
| Out for Delivery | On its way to you |
| Completed | Done |
| Cancelled | The order was cancelled |

Both the order number **and** the matching email are required — if either is
wrong, you'll see the same *"No matching order found. Check your order number
and email."* message whether the order number simply doesn't exist or it
exists but the email doesn't match. That's deliberate: it stops a stranger
from fishing for other customers' order numbers or guessing whether a
particular order exists, rather than a sign that something's broken.

---

## Part B — For kitchen & till staff

**Where this actually happens:** on the real, live system, staff work in two
possible places, and it's worth knowing the difference:

1. **The `wp-admin` Order Board** — a page inside the shop's WordPress admin
   (`DoughBoss` → **Order Board**, a separate full-screen top-level menu item
   built to be left open on a kitchen tablet). This is the real, live board.
2. **The standalone Staff Console** (a separate installable app, `app/` in
   this repo) — the same underlying screens (Order Board, Voucher Scan,
   Vouchers) reached via a phone/tablet home-screen icon instead of a browser
   tab, signing in with a WordPress username + an **Application Password**
   (created under the WordPress user's own Users → Profile → Application
   Passwords) rather than a normal password. Which screens a signed-in staff
   member actually sees depends on their WordPress capabilities — a kitchen
   tablet account typically sees **Voucher Scan** and **Order Board** but not
   the full **Vouchers** management screen (see `docs/DoughBoss-Manual-Admin.md`
   §2–3 for exactly what each capability unlocks).

Either way, the underlying order data and voucher data are the same — it's
the same real orders and the same real vouchers, just two different front
doors into them.

### B.1 New order → Ready: the accept/advance flow

A new order lands in the **New** lane the instant it's placed. The board is
not silent about it:

- A **banner** appears — *"N new order(s)!"* — with an **Acknowledge** button.
- An **audible alert** repeats every 1.5 seconds until someone acknowledges.
- If sound alerts haven't been turned on for that browser tab yet, the board
  shows a persistent warning: *"Sound is OFF — tap 'Enable sound alerts' so
  you don't miss new orders."* (Browsers require a tap before they'll allow a
  page to play sound at all — this is why the board can't just turn sound on
  by itself.)

**Working an order:**

1. **Accept the order and set an ETA.** A new order shows an "Accept — ready
   in:" row with quick buttons (10 / 15 / 20 / 30 minutes) plus a plain
   **Accept** button (accepting with no ETA is also fine — it just won't show
   a promised time). Accepting moves the order out of the "New" lane.
2. **Advance it as it cooks.** Depending on where the order is, you'll see the
   next sensible step(s) as buttons — e.g. **Preparing**, **In the Oven**,
   **Ready**. A delivery order additionally gets an **Out for Delivery** step
   before **Completed**; a pickup order goes straight from Ready to Completed.
3. **Cancel**, if needed, is available at any stage (with a confirmation
   prompt) and immediately updates the customer's tracking page.

A simplified way to describe the same flow, if you're new to it: **Accept →
Preparing → Ready** (with "In the Oven" and, for delivery, "Out for Delivery"
as extra checkpoints along the way on the real board).

**Filtering by shop:** if the business runs more than one location, a shop
selector appears above the board so a shared screen (or someone checking in
from one shop) can filter to just that shop's orders, or view all of them
together.

### B.2 Why the board updates the way it does (polling vs. Mercure)

The board **always** checks for new/changed orders on a repeating timer — by
default roughly every 7 seconds — whether or not anything else is configured.
That polling never turns off; it's the guaranteed fallback.

Some shops *additionally* have an optional real-time channel (called
**Mercure**) turned on in Settings. When it's connected and healthy, the board
slows its own polling down to a once-a-minute safety-net check instead,
because Mercure is pushing "something changed, go re-check" pings the moment
they happen — so orders can appear noticeably faster than a 7-second poll
would allow. If Mercure has a hiccup, the board silently drops straight back
to the normal ~7-second poll on its own; nothing needs restarting by hand, and
either way the board **never** trusts data carried in a push message directly
— every update, pushed or polled, is a fresh, authoritative re-fetch from the
server.

**Practically:** the board is a polling kitchen display first, with a
same-instant push channel layered on top only where the shop has switched it
on. If you're ever unsure whether "real-time" is active for your shop, ask
whoever manages the technical side whether Mercure is configured — either way,
an order will never take more than ~7 seconds to appear.

### B.3 "Order not showing on the board" — how to check it

Work through these in order:

1. **Give it a few seconds.** Even with nothing configured, the board is
   guaranteed to pick up a new/changed order within one poll cycle (~7
   seconds) — it is not truly instant even when it feels like it.
2. **Check the shop filter.** If a shop selector is showing and it's set to a
   specific location, an order for a *different* shop won't appear — switch
   to "All shops" to confirm.
3. **Check the order actually completed checkout.** If the customer's browser
   showed an error at checkout (closed ordering, empty cart, a card decline,
   a rate limit), no order was created at all — there's nothing missing from
   the board, because nothing was placed.
4. **Refresh the tab.** A tablet that's been asleep or lost network briefly
   can fall behind; a manual reload forces an immediate re-fetch rather than
   waiting for the next poll.
5. **If it's still missing** and you can confirm (e.g. from the customer's
   own tracking page, or the `wp-admin` Orders list) that the order genuinely
   exists — that's a real fault worth reporting to whoever manages the
   technical side, with the order number in hand.

### B.4 Redeeming a voucher in person (Voucher Scan)

A customer can bring a voucher code in person — shown on their phone, printed,
or read out over the phone — and redeem it at the till without ever having
placed an online order. This is the **Voucher Scan** screen (its own
top-level `wp-admin` menu item, or the "Voucher Scan" tab of the Staff
Console for anyone whose account has voucher-redeem access):

1. **Get the code into the box** three ways all work: type it, let a barcode
   scanner "type" it for you (scan, then press Enter), or tap **Scan with
   camera** to use the device's own camera to read a QR code.
2. **Enter the order total**, but only when it's actually needed — a flat
   dollar-amount voucher with no minimum spend can be redeemed without
   entering a total at all. If the voucher is a percentage-off code, or it has
   a minimum spend, the screen will insist: *"Enter the order total to redeem
   this voucher"* — because a percentage or a minimum-spend check can't be
   computed without the real total, and the system deliberately never guesses
   one on your behalf.
3. **Tap Redeem.** A successful redemption shows **"Redeemed ✓"** with the
   discount amount. From that instant, the code is dead — it cannot be used
   again, online or in-store.

**A voucher that's declined at the till tells you exactly why**, not just
"no":

| What you'll see | What it means |
|---|---|
| **Already used** | This exact code has already been redeemed once (online or in-store) — voucher codes are strictly single-use. |
| **Minimum spend not met** | The order total you entered is below the voucher's minimum spend. |
| **Enter order total** | This is a %-off or minimum-spend voucher — key in the total and try again. |
| **Declined** (generic) | Anything else — most often a mistyped code, an expired code, or a code that was created "online only" / "in-store only" and doesn't match this channel. The system deliberately doesn't say *which* of these it is, so a fake or guessed code can't be used to "probe" for real ones. |

The screen also shows a **live tracker** alongside the scan box: today's
running counts of issued / redeemed / voided vouchers, a meter per active
campaign (e.g. "37 / 100 claimed" for a shared daily pool), and a **Recent
vouchers** feed you can filter by code, status or phone number — handy for
confirming a code you just redeemed, or checking whether a customer's claimed
code is even still valid before they've walked up to the counter.

---

## Part C — Common problems

### "My voucher code was rejected"

1. **Check it was typed correctly.** Codes use a deliberately unambiguous
   alphabet with no `0`/`O`, `1`/`I`/`L` confusion built in, and the system
   automatically corrects the most common look-alike mix-ups (a mistyped `O`
   for `0`, `I`/`L` for `1`, etc.) before giving up — so most small typos
   still work. A code with a genuine typo elsewhere in it will usually be
   caught and rejected *before* it even checks the database, specifically so
   the failure is fast and doesn't leak anything about real codes.
2. **Check it hasn't been used already.** Every voucher code is single-use —
   once it's been redeemed once (online at checkout, or in-store at the
   till), it's gone. The message for this is specific: *"This voucher has
   already been used."*
3. **Check the order actually meets the minimum spend**, if the voucher has
   one — you'll be told directly: *"Your order doesn't meet this voucher's
   minimum spend."*
4. **Check where you're trying to use it.** A voucher can be restricted to
   online-only, in-store-only, or both. Trying an in-store-only code at
   online checkout (or vice versa) is rejected with the same generic *"This
   voucher code isn't valid"* message as a code that simply doesn't exist —
   again, deliberately, so no one can tell the two apart from the outside.
5. **If none of the above fits**, and staff can confirm in the Voucher Scan
   screen's "Recent vouchers" feed (or the `wp-admin` Vouchers list) that the
   code should be good, that's worth escalating with the exact code in hand.

### "My cart looked empty right after I added something"

This was a real bug in earlier versions of the system, and it's now fixed —
worth explaining plainly in case you remember hitting it. The cart is
remembered by a token stored in your browser's cookies. In the old code, that
token was occasionally being "corrected" (lower-cased) the moment it was read
back on your very next request, which pointed the cart lookup at the wrong
internal storage key and made a freshly-added item vanish from view on
practically every new visit. It's been fixed by preserving the token exactly
as it was first created. If you ever see an empty cart immediately after
adding an item on a live site, it's now far more likely to be an ordinary
cookie issue (private/incognito browsing, cookies blocked entirely, or a
security/privacy browser extension stripping cookies) than the underlying
system losing your cart — try a normal browser window with cookies allowed.

### "The order board isn't showing a new order"

See §B.3 above for the full checklist — in short: give it up to one poll
cycle (~7 seconds), check the shop filter isn't hiding it, confirm the order
actually completed checkout, and refresh the tab before assuming anything is
actually broken.

### "The board went quiet and stopped alerting me"

Check the **"Sound is OFF"** warning banner at the top of the board — most
browsers block a page from playing any sound until someone on that device has
tapped something on the page first (this is a browser rule, not a DoughBoss
one). Tap **Enable sound alerts** once per device/browser session and the
chime will keep working, including automatically resuming if the tablet's
screen was asleep.

---

## Part D — FAQs

**Q: Can I use a voucher in-store, or is it online-only?**
It depends on how the specific voucher was set up — a code can be scoped to
online-only, in-store-only, or both. If you have a code and it's rejected
somewhere, that doesn't necessarily mean it's invalid everywhere — it may
simply be scoped to the *other* channel. Ask staff to check it on the
Voucher Scan screen if you're unsure, or try it online.

**Q: Why does my cart look empty after I added something?**
Almost always a browser cookie issue today (see Part C above for the one
specific historical bug this used to be, which is now fixed) — try a normal
browser window with cookies enabled rather than private/incognito mode.

**Q: If I apply a voucher to my cart and then don't check out, is the code wasted?**
No. Applying a code in the cart is only a live preview of the discount — the
code isn't actually consumed until the moment you complete checkout. An
abandoned cart never burns a single-use code.

**Q: I placed an order but the tracking page says "No matching order found" — is my order lost?**
Almost certainly not lost — this exact message is shown both when the order
number is wrong *and* when the email doesn't match the one used on the order,
on purpose (so the tracking page can't be used to snoop on other customers'
orders). Double-check you're using the same email you gave at checkout, and
the order number exactly as shown on your confirmation screen.

**Q: Why did checkout tell me to "wait a few minutes and try again"?**
Checkout (and a few other actions, like applying a voucher) is deliberately
rate-limited per visitor as an anti-abuse measure. If you hit this after a
handful of quick attempts in a row (for example, retrying a declined card
repeatedly), it's the limit doing its job, not a sign the order didn't work —
wait a few minutes and try once more.

**Q: Why does my pizza builder only show certain sizes/toppings, or none at all?**
The builder only ever shows exactly what the shop itself has configured —
there's no built-in default list of sizes or toppings baked into the system.
If a shop hasn't set any up yet, you'll see a plain "No pizza sizes configured
yet" message instead of a broken-looking empty form.

**Q: Is the Live Order Board really "real-time"?**
It's guaranteed to catch up within about 7 seconds no matter what (the
always-on poll), and can be noticeably faster than that when a shop has the
optional Mercure push channel switched on — see §B.2. Either way, it is not
an instant, always-on push system by default; it is a very short-cycle
polling display with an optional faster path layered on top.

---

## Part E — Screens explained (the concept demo site)

The static files in this repo's `demo/` folder are a **self-contained,
GitHub-Pages-hosted concept demo** built to *show* the vision described in
`docs/Owner-Report.md` — they are not connected to the real WordPress plugin,
have no real database behind them, and (per their own on-page disclaimers)
show only fictional sample data with simulated orders and payments. Treat
this section as "what each demo page is for," not as instructions for the
live system — Parts A and B above describe the real thing.

- **`index.html`** — the customer-facing concept storefront: an About/Menu/
  Catering/Locations/Kitchen/Snow-Boss single page with a hash-based router
  (`#menu`, `#catering`, etc.), a demo cart drawer (`menu-order.js`) and a
  student-voucher claim mock-up. Its own footer literally says *"Concept demo
  · orders & payments are simulated · details & images illustrative."*
- **`staff.html`** — a cinematic, animated staff/kitchen **sign-in** screen.
  On the demo, it explicitly accepts **any** staff ID and passcode (the hint
  text says so on the page) and simply stores a name in the browser's
  `localStorage` — there is no real authentication happening. It also offers
  a one-click **"Owner / Manager dashboard"** link that seeds the same fake
  session and jumps straight to `owner.html`. On the real system, the
  equivalent step is a normal WordPress login (plus, for the standalone Staff
  Console, an Application Password — see Part B above).
  Once "signed in" on the demo, the session persists indefinitely (no
  timeout) until an explicit **Sign out**, which is meant to model the real
  system's "Staff session (days)" setting for shared kitchen tablets
  (`docs/DoughBoss-Manual-Admin.md` §3).
- **`backend.html`** — the demo's kitchen/orders screen: **New & cooking**,
  **Today**, **Coming up**, **Catering**, and **Done** tabs, plus a shop
  filter (All / Revesby / Bankstown / Roselands) and a **"+ New order
  (demo)"** button that injects a fake order to show the alert/flash
  behaviour. Its 3-stage **Accept → Cooking → Ready → Done** button flow is a
  simplified stand-in for the real board's finer-grained
  pending → confirmed → preparing → baking → ready → (out for delivery) →
  completed flow described in §B.1. It is gated so a signed-out visitor is
  bounced straight back to `staff.html` — but again, that gate only checks
  for the fake `localStorage` session `staff.html` sets, not a real login.
- **`owner.html`** — the demo's owner/manager dashboard concept: a
  **Dashboard / Live orders / Catering / All orders** tab set showing
  illustrative sales figures, store performance and a catering pipeline. Its
  own on-page note is explicit about the boundary: *"Demo figures are
  representative — once wired to WordPress, every dollar comes from the
  server (orders + GST), never the browser."* There is no equivalent
  single page in the real plugin today — the closest real things are the
  `wp-admin` **Orders** list and **Order Board** (see
  `docs/DoughBoss-Manual-Admin.md`).

If you're evaluating DoughBoss for the first time, the demo is a fast way to
*see* the concept end-to-end in a browser with no setup. If you're actually
operating a shop on the real system, use Parts A and B of this manual instead
— they describe what genuinely happens on a live order.

---

*This manual describes the plugin as installed in this repository (v2.12.3 /
DB v1.7.0). It was written by reading the actual storefront JavaScript
(`public/js/doughboss.js`, `doughboss-voucher.js`, `doughboss-orderboard.js`,
`doughboss-voucher-scan.js`), the REST controller
(`includes/class-doughboss-rest-controller.php`), the voucher and coupon-code
engines (`includes/class-doughboss-voucher.php`,
`includes/class-doughboss-coupon-code.php`), the order model
(`includes/class-doughboss-order.php`), and the demo site (`demo/*.html`) — not
by observing the live `doughboss.com.au` site, which this session had no tool
access to. If the live site's behaviour differs from what's described here,
it may be running an older or newer build than this manual was written
against — check with whoever manages deployments before assuming this manual
is wrong.*
