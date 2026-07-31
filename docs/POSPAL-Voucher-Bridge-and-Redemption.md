# Vouchers at the till — how to redeem, and the POSPal bridge

**The one rule that fixes the "it didn't work in POSPal" problem:**

> ❌ **Never type a DoughBoss voucher code (e.g. `DOUGH-HSYR-8VCU`) into POSPal.**
> POSPal has no record of DoughBoss codes — it will always reject them. DoughBoss
> owns the voucher; POSPal rings up the sale; a staff member bridges the discount.

---

## Part A — Redeem a voucher at Revesby (works today, no setup)

This is the intended pilot flow. It needs nothing configured beyond the plugin.

1. On the till/tablet, open **WordPress admin → DoughBoss → “Voucher Scan”**
   (menu icon: ticket; internal page `doughboss-scan`). This is a standalone,
   tablet-friendly scanner. Staff need the **“redeem vouchers”** capability
   (the *DoughBoss Kitchen* role has it) — no owner login required.
2. Scan or type the code, e.g. `DOUGH-HSYR-8VCU`.
3. **Enter the order total** when asked.
   - A flat-amount voucher with no minimum spend (like the $5 code) can apply
     without a total, but entering it is good practice.
   - A **percent** voucher or any voucher with a **minimum spend** *requires*
     the real total — the scanner will ask for it and refuse a made-up number.
4. Confirm. On success you’ll see **“$5.00 applied — voucher redeemed.”**
   DoughBoss then:
   - marks the voucher **redeemed** (single-use — it can never be scanned again),
   - records an audit row **against the Revesby location**.
5. **In POSPal, apply the matching discount on the ticket manually** ($5 off),
   then take payment as normal.

That’s the whole flow: **scan in DoughBoss → discount in POSPal by hand.**

### If the scanner rejects a code
A voucher is only accepted when **all** of these are true (see
`DoughBoss_Voucher::evaluate()`):
- status is **issued** (not already redeemed or voided),
- **scope** allows in-store (`instore` or `both` — the default is `both`),
- it’s within its **valid-from / valid-to** dates,
- the order meets any **minimum spend**.

The scanner deliberately returns the same **“This voucher code isn’t valid.”**
message for an unknown code, an already-used code, or a wrong-scope code — so a
stranger can’t probe which codes exist. If a code you expect to work is refused,
check it in **DoughBoss → Vouchers** (status, scope, dates, min spend).

---

## Part B — The POSPal bridge (optional; off by default)

DoughBoss can *mirror* a voucher into POSPal’s **member-coupon** system so the
discount lives on the customer’s POSPal member record. This is the
`DoughBoss_POSPal_Sync` module. **It is fully dormant until you switch it on.**

### What it does
- **On claim** (`doughboss_voucher_claimed`): finds/creates the customer as a
  POSPal **member by phone number**, grants the mapped **coupon rule** for the
  voucher’s dollar value, and stamps the voucher row with
  `pospal_customer_uid` + `pospal_coupon_ref`.
- **On redeem** (`doughboss_voucher_redeemed`): best-effort **revokes/uses** the
  mirrored POSPal coupon, so a voucher spent online can’t also be used in-store.
- It’s **best-effort**: any POSPal failure is logged (status only — no phone,
  member id, keys or PII) and **never blocks** the voucher flow.

### Why this still isn’t "type the code into POSPal"
Even with the bridge on, staff do **not** key the `DOUGH-` code into POSPal.
The discount becomes a **member coupon attached to the customer’s phone/member**,
which POSPal applies when you look that member up. The `DOUGH-` code stays a
DoughBoss artefact.

### Hard limitation to know before relying on it
The bridge fires on **claim** — i.e. vouchers a customer receives through the
**student-voucher / campaign form** (which captures their phone → POSPal member).
A voucher **hand-issued in admin with no phone and no campaign** — which is
exactly how `DOUGH-HSYR-8VCU` was created — is **never mirrored to POSPal**.
So for hand-issued test codes, Part A (scan in DoughBoss) is the only path.

### How to enable it
The gate is `DoughBoss_Settings::pospal_grant_enabled()`, which is true only when
**both** hold:

1. **POSPal is connected** — `pospal_enabled()` is on and each store has its
   POSPal **host + app_id + app_key** filled in (DoughBoss → Settings → POSPal).
2. **A $5 coupon-rule UID is mapped** for at least one store — setting
   `pospal_coupon_uid_5` for the first store (Revesby), `pospal2_coupon_uid_5`,
   `pospal3_coupon_uid_5`, … for additional stores. Each store’s credentials are
   read as `{ label, host, app_id, app_key, uid5 }`.

Steps:
1. In **POSPal**, create the coupon/promotion rule that gives **$5 off**, and
   note its rule **UID**.
2. In **DoughBoss → Settings → POSPal**, connect POSPal for **Revesby**
   (host, app_id, app_key).
3. Paste the $5 rule UID into the store’s **“$5 coupon UID”** field
   (`pospal_coupon_uid_5`). Repeat per store if you run more than one.
4. Verify the mapping resolves against POSPal with
   `DoughBoss_POSPal::verify_coupon_rules( $creds, $uid5 )` (the settings screen
   surfaces this) — a valid UID confirms POSPal recognises the rule.
5. Run a **claim** through the student-voucher form with a test phone number,
   then confirm the voucher row now carries `pospal_customer_uid` +
   `pospal_coupon_ref`, and that the coupon shows on that member in POSPal.

### Adding other voucher values
Only the **$5** value is wired (`uid5` / `pospal_coupon_uid_5`), matching the
current $5 student campaign. A $10 (or other) value needs its own POSPal rule
UID + a matching mapping field and a branch in
`DoughBoss_Settings::pospal_coupon_uid_for()` — a small, well-scoped follow-up,
not a config change.

---

## Recommendation for the Revesby pilot

Use **Part A** — scan in the DoughBoss *Voucher Scan* page, apply the $5 in
POSPal by hand. It’s reliable, needs no integration, and works for **every**
voucher (hand-issued or claimed). Turn on the **Part B** bridge later, once
you’ve (a) created the $5 coupon rule in POSPal, and (b) are handing out codes
through the **claim form** (which captures the phone the bridge needs).
