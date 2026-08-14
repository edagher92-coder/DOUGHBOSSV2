# DoughBoss Staff QR Clock — launch setup

The staff clock is designed for a shared touch screen. Employees do **not** need
to know a WordPress username or password. Each person receives a printed QR
badge and a private 4–8 digit PIN.

## What the clock records

- the employee's DoughBoss account and assigned shop;
- clock-in and clock-out time, stored in UTC and displayed in the shop timezone;
- breaks that the employee actually starts and ends at the kiosk;
- worked time (elapsed shift time less only those recorded breaks); and
- optional scheduled start, grace period and late minutes, snapshotted when the
  employee clocks in.

It does **not** collect GPS, IP address, browser location or continuous tracking.
It does **not** silently deduct a standard break, calculate overtime, or decide
whether a break is paid. Those are management/payroll policy decisions.

## One-time manager setup

1. In **WordPress → Users**, create one account per employee and select the
   **DoughBoss Staff** role. Use DoughBoss Kitchen or DoughBoss Manager only
   where that employee genuinely needs the extra access.
2. Edit each employee. Under **DoughBoss staff assignment**, select their active
   shop (for example, Revesby). Do this even on a one-shop site so a future shop
   does not lock the person out.
3. In the same profile, set the weekly roster start and the allowed grace minutes
   only for the days the person is rostered. Leave a day blank when no lateness
   calculation should be made.
4. Open **DoughBoss → Staff QR badges**, choose the employee, enter a unique
   private PIN, then select **Create secure QR badge**.
5. Print the QR page or save it as a PDF card. Give the card and PIN separately.
   The raw QR link is shown only on that issue page. Reissuing a badge immediately
   invalidates the old one; revoke a lost card from the same manager screen.

## Daily staff use

Open [Staff Clock](https://doughboss.com.au/staff-clock/) in full-screen mode.

1. Scan the personal QR badge. A USB 2D scanner configured as a keyboard with an
   **Enter** suffix is the quickest option. If needed, scan into the on-screen
   field and press Enter.
2. Enter the private PIN using the large number pad.
3. Tap one large action: **Clock in**, **Start break**, **End break**, or
   **Clock out**.
4. Wait for the confirmation screen, then hand the screen to the next person.

The badge session clears after every action. Five incorrect PIN attempts lock the
badge for 15 minutes. A manager can reissue it if it is lost or compromised.

## Manager review and corrections

Use [Staff Timesheet](https://doughboss.com.au/wp-admin/admin.php?page=doughboss-timeclock)
to filter shifts, see actual break/worked/late time and export the detailed CSV.
If someone forgets to clock out, a manager may use **Close now** with a required
reason. That creates a separate permanent audit event; it does not rewrite the
original clock-in evidence.

## Lenovo kiosk setup

- Use a separate Chrome or Edge profile called `DoughBoss Staff Clock` for this
  URL. Do not save passwords in it.
- Keep the Kitchen MAKE/PASS/Catering browser session in a different profile.
  The QR kiosk itself never logs a worker into WordPress, so it will not disrupt
  the kitchen board.
- Connect Ethernet for reliability. HDMI/DisplayPort provides picture; the
  monitor's USB-C upstream/data cable is required for touch. Plug the 2D scanner
  into a PC USB port.
- Use 1920×1080 at 100% scale, full screen, and test one scan, PIN, clock-in,
  break start/end and clock-out before trade.

## Before staff start

Management must approve the roster/grace rules, paid or unpaid break policy,
late-arrival process and payroll export review. The clock gives accurate recorded
attendance evidence; it is not a substitute for those policies.
