# DoughBoss Staff Clock

The staff clock is a simple shift record built into DoughBoss. It is not a payroll system and does not connect to ordering, payments or customer accounts.

## Staff use

1. Give each person their own WordPress account. Do not share the Kitchen tablet account for clocking purposes.
2. Assign the **DoughBoss Kitchen** role to kitchen staff, or **DoughBoss Manager** to supervisors.
3. On the staff tablet, open `/staff-clock/` and sign in.
4. Tap **Clock in** when starting and **Clock out** when leaving.

The page is noindex and has no public navigation link. A person who is not signed in cannot clock a shift; a signed-in customer account without a DoughBoss staff role cannot use it.

## Manager use

Open **DoughBoss → Staff Timesheet** in WordPress. It shows active shifts first, then the selected 7, 14, 30 or 90-day history. Times are stored in UTC and displayed in the site timezone.

## Operating rules

- One person, one account.
- Do not use a shared PIN: it produces an unreliable timesheet.
- If a person forgets to clock out, record the correction in the approved payroll process. This first release intentionally does not let a manager silently rewrite a staff member's record.
- The clock is an operational attendance record only. Confirm award, payroll, privacy and record-retention requirements with management before using it for pay calculations.
