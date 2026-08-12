# DoughBoss Staff Clock — Setup and Operating Guide

## What this feature does

DoughBoss 2.36.0 adds a private, touch-friendly staff attendance screen at:

`https://doughboss.com.au/staff-clock/`

Staff use their own WordPress account to clock in and clock out at a confirmed DoughBoss shop. The screen automatically signs the employee out after every valid clock request, including an error, making it safe and quick to share on the shop touchscreen.

This is an attendance record, not a payroll calculator. Management should review exported hours before using them in payroll.

## Privacy and location check-in

“Location check-in” means the staff member is clocking in against a real, active DoughBoss shop in the ordering system.

- The clock records the selected shop, the shop name and its timezone.
- It does **not** request browser GPS, latitude/longitude or a device IP address.
- It does **not** continuously track an employee.
- A staff account cannot clock into an arbitrary or inactive shop.
- When more than one shop is active, ordinary staff must be assigned to a shop and managers must deliberately choose the shop at clock-in.

This privacy-friendly design gives management a clear shop attendance record without collecting precise personal location data. If DoughBoss later wants GPS geofencing, that should be a separate, management-approved project with workplace/privacy review, employee notice and a documented fallback for devices that deny location permission.

## Accounts and roles

Every employee must have an individual account. Never share one username between employees; a shared login makes an attendance record unreliable.

Use the smallest role that matches the person’s job:

| Role | Staff clock | Kitchen board | Management |
|---|---:|---:|---:|
| DoughBoss Staff | Yes | No | No |
| DoughBoss Kitchen | Yes | Yes, assigned shop only | No |
| DoughBoss Manager | Yes | Yes | Yes |
| Administrator | Yes | Yes | Yes |

The **DoughBoss Staff** role is the preferred clock-only role for front-of-house employees who do not need kitchen or manager access.

## Install or update

1. Take a current files-and-database backup.
2. In WordPress, open **Plugins → Add Plugin → Upload Plugin**.
3. Upload the verified DoughBoss 2.36.0 ZIP.
4. Choose **Replace current with uploaded** when WordPress confirms DoughBoss is already installed.
5. Keep DoughBoss active and wait for the update to finish.
6. Open **Settings → Permalinks** and click **Save Changes** once if `/staff-clock/` initially returns a not-found page.
7. Confirm the active plugin shows version **2.36.0** and no DoughBoss migration error is displayed.

Do not upload an older 2.26.0 staff-clock build. It predates the current website, rewards and payment work and must not be used.

## Create each staff account

1. Open **Users → Add New**.
2. Enter the employee’s own username, name and owner-approved email address.
3. Use a unique strong password. Do not reuse a site, Stripe, email or hosting password.
4. Assign **DoughBoss Staff**, **DoughBoss Kitchen** or **DoughBoss Manager**.
5. Save the user.
6. Open the saved user profile.
7. Under **DoughBoss staff assignment**, choose the employee’s active shop and save again.

For a site with one active shop, the system can safely use that sole shop if an assignment has not yet been saved. As soon as multiple shops are active, an ordinary staff account without a valid assignment is blocked until management assigns it.

## Set up the shared touchscreen

1. Connect the monitor’s video cable and its USB touch/data cable to the PC.
2. Use wired Ethernet where practical.
3. In Chrome or Edge, open `https://doughboss.com.au/staff-clock/`.
4. Bookmark it and optionally install the page as an app or open it full screen.
5. Confirm the browser is not saving a shared staff password.
6. Test the buttons by touch and confirm the page returns to the signed-out screen after each clock action, including a simulated error.

The portal sends no-cache, no-index and anti-framing headers. Do not embed it in another website or add it to the public site navigation.

## Daily employee flow

### Clock in

1. Tap **Staff sign in**.
2. Sign in with your own staff account.
3. Confirm the shop shown on screen. A manager must select a shop when more than one is active.
4. Tap **Clock in** once.
5. Wait for the green success confirmation. The screen signs you out automatically for the next person.

### Clock out

1. Open the same Staff Clock bookmark and sign in with your own account.
2. Confirm the open shift and its shop.
3. Tap **Clock out** once.
4. Wait for the success confirmation and automatic sign-out.

Repeated taps or page retries do not create a second open shift. If the system cannot safely update the shift, it shows an error rather than guessing.

## Manager timesheets

Managers and administrators can open **DoughBoss → Staff Timesheet**.

The report provides:

- active shifts first;
- 7, 14, 30 or 90-day periods;
- an all-shop or single-shop filter;
- immutable staff and shop details captured when the shift began;
- local display times backed by server-stored UTC timestamps;
- worked-duration totals; and
- CSV export for owner review (up to the newest 5,000 matching rows per download; narrow the period/shop filters if that limit is reached).

CSV text is neutralised before download so a staff name, username or shop name cannot become a spreadsheet formula when opened in Excel or Google Sheets.

## Audited correction for a forgotten clock-out

In 2.36.0, the manager correction action safely closes an open shift that an employee forgot to clock out. It does not silently rewrite an employee's original clock-in or an already completed shift.

1. Open **DoughBoss → Staff Timesheet**.
2. Find the shift and choose the manager correction action.
3. Enter a clear reason. A correction without a reason is rejected.
4. Choose **Close now** once and confirm the completed shift in the report.

The forced close and its audit event are written as one transaction. The event contains the shift, manager account, reason, before state, after state and event time, and is identified as `manager_closed`. The original action must never be silently overwritten without its audit record.

If a completed shift needs another adjustment, management should preserve the DoughBoss record and document the reviewed payroll adjustment through the approved payroll process. Do not edit attendance rows directly in the database.

Suggested reasons include “employee forgot to clock out”, “manager verified start time with roster” or “duplicate kiosk attempt reviewed”. Do not put medical or other sensitive personal information in the reason.

## Acceptance checklist

Complete this on a staging or controlled test site before relying on the clock operationally:

- [ ] DoughBoss 2.36.0 is active and the database reports schema 1.20.0 with no migration error.
- [ ] `/staff-clock/` loads on desktop and the purchased touch monitor.
- [ ] A signed-out visitor sees only the staff sign-in landing screen.
- [ ] A clock-only staff user cannot open Kitchen or Management.
- [ ] A staff user assigned to Shop A clocks in only at Shop A.
- [ ] In multi-shop mode, an unassigned staff user is blocked.
- [ ] In multi-shop mode, a manager must explicitly choose a shop.
- [ ] Clock in creates exactly one open shift after a double tap or refresh.
- [ ] Clock out closes exactly that shift after a double tap or refresh.
- [ ] Successful clock in and clock out both return to the signed-out screen.
- [ ] A failed or unassigned-shop clock request also returns to the signed-out screen without changing a shift.
- [ ] The report retains the original staff and shop names after a later profile/shop rename.
- [ ] A forced close requires a reason and creates a manager audit event.
- [ ] The forced-close correction stores the manager, reason, before values and after values.
- [ ] CSV opens safely and leading `=`, `+`, `-` or `@` in test text is displayed as text, not executed as a formula.
- [ ] No browser location prompt appears and no GPS or IP is collected.
- [ ] A final files-and-database backup is taken after acceptance and before operational launch.

## Troubleshooting

**The clock URL says page not found**
Save **Settings → Permalinks** once, then reload the URL.

**The employee cannot clock in**
Confirm their account has a DoughBoss staff role, the shop is active and their user profile has the correct **DoughBoss staff assignment**. In multi-shop mode, missing assignments intentionally fail closed.

**A manager cannot see the timesheet**
Confirm the account has the DoughBoss Manager role or administrator access. Do not grant manager access just to let an employee clock in; use DoughBoss Staff instead.

**An employee forgot to clock out**
Use the manager correction/forced-close action and enter an auditable reason. Do not edit the database directly.

**The kiosk remains signed in**
Stop using that device for attendance until the automatic sign-out behavior is restored. Sign out manually, clear any saved password and have management review the last shift action.

## Operational ownership

Management remains responsible for staff account lifecycle, shop assignments, roster comparison, correction approval, payroll review, data retention and employee communication. Disable a departing employee’s account promptly; do not delete historical shifts needed for records.
