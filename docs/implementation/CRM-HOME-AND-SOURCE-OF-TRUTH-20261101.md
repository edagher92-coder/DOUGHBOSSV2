# CRM home screen and source of truth (design only)

Date: 2026-11-01. Branch: qwen/doughboss-home-sot. Design doc only; no code in this change.
Owner decisions are labelled "owner decision". Unknowns are [CONFIRM: ...].
Silence rule (owner decision): a source that could not be read shows UNSEEN, never 0, empty or "all clear".

## 1. Who the home screen is for

| Role | Capability in code today | Status |
|---|---|---|
| Owner (Elie) | manage_doughboss or manage_options (includes/class-doughboss-crm.php:17-20) | VERIFIED; sees all shops via existing all-store authority (class-doughboss-crm.php:16 comment) |
| Co-owner (Charlie) | same as owner in code today | INFERRED; no separate co-owner capability exists. Telegram bot is the intended channel (owner decision). |
| Shop manager (own shop only) | manage_doughboss grants ALL stores today (class-doughboss-crm.php:16-20); per-store scope exists only for KDS/staff via includes/class-doughboss-staff-scope.php:41 | MISSING: no location-scoped CRM capability yet |
| Catering approver (Kristy) | no dedicated capability; preorder approval takes an actor user id/name (includes/class-doughboss-preorder-approval.php:166) | MISSING: approver role not modelled in code |
| Staff | clock_doughboss_staff (includes/class-doughboss-timeclock.php:17); kitchen needs manage_doughboss_kds (includes/class-doughboss-portals.php:149) | VERIFIED; staff should see no home cards beyond their own scope [CONFIRM: any staff-facing card?] |

## 2. Home screen cards, priority order

Existing entry point: management portal section selector accepts only "crm" or "overview" (includes/class-doughboss-portals.php:248); nav shows Overview + Catering CRM (class-doughboss-portals.php:255-256). No home aggregation exists today.

1. **Catering inbox** - Question: "what needs attention?" Data: DoughBoss_CRM::inbox() (includes/class-doughboss-crm.php:27) over {prefix}doughboss_catering_enquiries (includes/class-doughboss-catering.php:56). Roles: owner, co-owner, managers (all stores today). States: live / UNSEEN on DB error (generic message, class-doughboss-crm.php:47). Already built and tested (tests/test-crm.php:72-158).
2. **New enquiries today** - Question: "what arrived?" Data: status=new count from the same table (statuses at class-doughboss-catering.php:23-30). Roles: owner, co-owner, managers. States: live / UNSEEN / off (switch).
3. **Awaiting approval** - Question: "what is stuck before Kristy?" Data: preorder approvals in pending state (includes/class-doughboss-preorder-approval.php:22,166). Roles: owner, co-owner, catering approver. States: live / UNSEEN / off (switch; online ordering is off until released - owner decision).
4. **Catering production queue** - Question: "which catering jobs are booked for production?" Data: DoughBoss_Catering::production_queue() over catering statuses deposit_paid/confirmed/balance_due/paid (includes/class-doughboss-catering.php:1118-1130). This is the catering pipeline, separate from the online preorder `released` state (class-doughboss-preorder-approval.php:32), which is the one kitchen release for web orders. Roles: owner, co-owner, managers. States: live / UNSEEN / off (switch).
5. **Approved, awaiting payment** - Question: "which approved orders has the customer not paid yet?" Data: preorder approvals in approved_unpaid (the chase list), with payment_active/payment_held shown separately as "payment in progress" (class-doughboss-preorder-approval.php:24-30). Roles: owner, co-owner, catering approver. Read-only: no mark-paid or release action (docs/implementation/CRM-EXECUTION-PLAN-20261030.md:130). States: live / UNSEEN / off (switch).
6. **Order snapshot (Revesby)** - Question: "how is the shop trading?" Data: native Square pickup snapshot read by the order model (includes/class-doughboss-order.php:332). Roles: owner, co-owner, Revesby manager. States: live / UNSEEN / not configured (web ordering Revesby-only - owner decision).
7. **Staff on shift** - Question: "who is clocked in?" Data: timeclock (includes/class-doughboss-timeclock.php). Roles: managers (own shop), owner. States: live / UNSEEN / off (switch). Later: needs per-shop scoping (class-doughboss-staff-scope.php:41).
8. **Reports link card** - Question: "where are the numbers?" Data: reports module (includes/class-doughboss-reports.php:129,169,295,372). Roles: owner, co-owner. States: live / UNSEEN / off (switch).

Cards 1-2 need no new data; 3-8 need new reads or new scoping and come later.

## 3. Source of truth

| Domain | System of record | Who writes | How CRM reads it | Max staleness | Unreadable shows |
|---|---|---|---|---|---|
| Catering enquiries | {prefix}doughboss_catering_enquiries (class-doughboss-catering.php:56) | Staff/manager via create() (line 463) and status changes | DoughBoss_Catering::query() (line 1048) via CRM inbox (class-doughboss-crm.php:45) | [CONFIRM: acceptable lag] | UNSEEN |
| Orders | {prefix}doughboss_orders/items/events (class-doughboss-order.php:630,640,650) + Square pickup snapshot (line 332) | WordPress plugin governs orders (owner decision); Square supplies pickup data | Order model reads | [CONFIRM: acceptable lag] | UNSEEN |
| Customers/contacts | No dedicated table; lives in enquiry rows (INFERRED) | Enquiry creation | Same table | n/a | UNSEEN |
| Menu/catalogue | Not in this plugin's tables (INFERRED; Square or WordPress) [CONFIRM: where is the menu stored?] | [CONFIRM] | Not read by CRM today | n/a | UNSEEN / not configured |
| Payments | Square only (owner decision) | Square | No live payment read in CRM today; Square modules loaded (class-doughboss.php:98-100) but CRM inbox does not call them | [CONFIRM] | UNSEEN |
| Loyalty/vouchers/membership | Square (owner decision); plugin "never enrols anyone" (class-doughboss-square-loyalty.php:11) | Square | Not read by CRM today | [CONFIRM] | UNSEEN / not configured |
| Staff and clock-in | Timeclock module (class-doughboss-timeclock.php) | Staff via clock portal (class-doughboss-portals.php:119) | Not read by CRM today | [CONFIRM] | UNSEEN |
| Stores/locations | WordPress locations + doughboss_location_id user meta (class-doughboss-staff-scope.php:18,41) | Admin | Staff-scope helper | n/a | UNSEEN |
| Reports | Derived from orders + enquiries (class-doughboss-reports.php:129) | Plugin computes on read | Reports module | [CONFIRM] | UNSEEN |

## 4. Gaps and risks

- No per-store sales role or location-scoped CRM auth: manage_doughboss is all-store (class-doughboss-crm.php:16-20). Blocks the "own shop only" manager card.
- B2B/wholesale enquiries are not persisted; email only (docs/implementation/CRM-FIRST-SLICE-HANDOVER-20261030.md) - no retrospective CRM visibility.
- No Telegram adapter in this repo (docs/implementation/CRM-EXECUTION-PLAN-20261030.md:43); Hermes bot is external and unverified live.
- Square live merchant/location/settlement not verified in code; only components exist (class-doughboss.php:98-100).
- Customers/contacts domain has no dedicated table (INFERRED).
- No home screen aggregation exists; only the single CRM inbox section (class-doughboss-portals.php:248).
- Catering query has no location filter (class-doughboss-catering.php:1048) - per-shop cards need one.

## 5. Slices (smallest first, switches default-off)

1. **Home shell + catering inbox card.** Acceptance: new "home" section in management portal renders the existing inbox card; UNSEEN banner on DB error; no writes on render. Tests: extend tests/test-crm.php patterns - capability denial, UNSEEN on injected DB error, no SQL on denial. Switch: doughboss_home_enabled, default off.
2. **New-enquiries + production-queue cards.** Acceptance: counts from existing table; UNSEEN on error; per-page-free single count query. Tests: fixture rows by status; error path shows UNSEEN not 0. Switch default off.
3. **Location scoping for managers.** Acceptance: manager with doughboss_location_id sees own-shop cards only; owner/co-owner see all; unassigned multi-store user gets UNSEEN not all. Tests: staff-scope matrix (class-doughboss-staff-scope.php:41) against card visibility. Switch default off.
4. **Approval + awaiting-payment cards.** Acceptance: pending, approved_unpaid and payment-in-progress counts from the preorder-approval table; no action buttons (read-only, per docs/implementation/CRM-EXECUTION-PLAN-20261030.md:130). Tests: state fixtures; UNSEEN on error. Switch default off.
5. **Reports link card + Square snapshot card (Revesby).** Acceptance: links to existing reports; order snapshot shows UNSEEN when Square read fails. Tests: fixture + injected failure. Switch default off.

## 6. Open questions for Elie

1. What max staleness is acceptable per domain before a card must say UNSEEN?
2. Should Charlie get a WordPress capability, or is the Telegram bot his only entry?
3. Where is the menu/catalogue actually stored (Square, WordPress, other)?
4. Does Kristy need a distinct approver capability, or does she use manage_doughboss?
5. Any staff-facing card at all, or is the home screen manager-and-above only?
6. Should per-shop cards appear before or after the all-store owner view is proven?
