# DoughBoss staff clock

## Setup, badge printing, daily use and manager guide

Version 1.0 - 10 September 2026

This guide applies to the secure QR and PIN staff clock in DoughBoss 2.41.1.
The staff clock URL is <https://doughboss.com.au/staff-clock/>. The live DoughBoss
shop currently configured for attendance is Revesby. Do not assign a worker to
Revesby merely to make the clock available if that is not their real work location.

## What the system records

- the employee's DoughBoss staff account and assigned shop;
- clock-in and clock-out timestamps, stored in UTC and displayed in the shop timezone;
- only the breaks the employee starts and ends at the kiosk;
- worked minutes, calculated as elapsed shift time less recorded breaks; and
- the roster start and grace period that applied when the shift began.

The DoughBoss attendance tables do not store GPS, IP address, browser location
or continuous tracking. Hosting and security providers may retain ordinary access
logs under their own policies. The clock does not silently deduct standard breaks
or decide whether a break is paid.

## Manager setup

1. In WordPress, open **Users** and create or edit the employee's individual account.
2. Select the **DoughBoss Staff** role. Only use Kitchen or Manager roles when the
   employee genuinely needs those additional permissions.
3. Under **DoughBoss staff assignment**, select the employee's exact active shop.
4. Enter roster start times and grace minutes for rostered days. Leave other days blank.
5. Open **DoughBoss > Staff QR badges**.
6. Select the employee and enter a private 6-8 digit PIN.
7. Select **Create secure QR badge**. The badge page is shown once.
8. Print or save the badge immediately. Give the PIN privately and separately.

Reissuing a badge revokes the earlier badge. Revoke a lost or compromised badge
from **DoughBoss > Staff QR badges**.

## Print the badge correctly

The one-time badge page includes a **Print / save as PDF** button.

1. Connect and select the office, label or card printer.
2. Use portrait orientation and 100 percent scale where possible.
3. Print in high-contrast black on white. Keep the QR square, complete and uncropped.
4. Do not add the employee's PIN to the badge.
5. Laminate or place the card in a clear holder only after confirming the covering
   does not create scanner glare.
6. Test the final printed card at the real kiosk before giving it to the employee.

If using an A4 printer, print the badge page, trim around the card without cutting
the QR code, and place it in a holder. If using a label or card printer, keep the QR
large and preserve the white border around it. Do not use a dark or patterned stock.

## Kiosk and scanner setup

- Open the staff clock in a separate Chrome or Edge profile named
  `DoughBoss Staff Clock`. Do not save manager passwords in this profile.
- Use Ethernet where practical and keep the kiosk powered during trading hours.
- For a touch monitor, connect both the display cable and the monitor's USB data cable.
- Set the screen to 1920 x 1080 at 100 percent scale where supported, then use full screen.
- Configure the USB 2D QR scanner as a keyboard or HID device with an Enter suffix.
- Scan into the on-screen field and press Enter manually if the scanner does not send Enter.

## Daily staff use

1. Scan your personal QR badge.
2. Enter your private PIN on the number pad.
3. Select the single action shown: **Clock in**, **Start break**, **End break** or
   **Clock out**.
4. Wait for the success confirmation before leaving the screen.
5. The badge session clears after the action. The next person must scan their own badge.

Five incorrect PIN attempts temporarily lock the badge for 15 minutes. A manager
should verify the employee and reissue the badge if it may be compromised.

## Manager review, corrections and payroll

Open **DoughBoss > Staff Timesheet** to review active shifts first, filter by period
or shop, and export CSV. Worked time subtracts only recorded breaks.

If a shift is still open, do not guess. Confirm the correct end time with the employee
or manager and enter a clear correction reason. Corrections must remain in the permanent
audit trail. Keep the exported CSV as a review aid; payroll approval remains a manager task.

## Troubleshooting

- **Nothing happens after scanning:** click the scan field, scan again, and press Enter.
- **Badge not accepted:** confirm it is the employee's current badge; reissued badges revoke old ones.
- **PIN locked:** wait 15 minutes or have a manager verify and reissue the badge.
- **No active shop:** the employee needs their real active shop assigned in WordPress.
- **Wrong action or forgotten break:** stop and tell a manager; do not create another account or badge.
- **Kiosk offline:** record the time under the approved outage procedure and reconcile it later with a reason.
- **Printer will not scan:** reprint black on white at high quality, keep the QR uncropped, and test without glare.

## Before launch - manager acceptance

- [ ] Every employee has one individual staff account.
- [ ] Every enabled employee is assigned to their real active shop.
- [ ] Roster and grace rules have been approved.
- [ ] The paid/unpaid break policy has been approved and communicated.
- [ ] Every badge is printed once and its PIN is provided separately.
- [ ] Lost and superseded badges have been revoked.
- [ ] A manager completes a controlled scan, PIN, clock-in, break start, break end and clock-out test.
- [ ] The shift appears correctly in Staff Timesheet and CSV export.
- [ ] The printer, scanner, touch screen, network and kiosk browser profile have been tested together.
- [ ] Staff know who to contact when a clock event is wrong or the kiosk is unavailable.

Do not use a real employee's badge for training, do not share PINs, and do not create
attendance records for a location where the employee did not work.
