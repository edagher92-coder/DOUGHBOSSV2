# CRM home screen and source of truth

Date: 2026-11-01. Design written first (branch qwen/doughboss-home-sot); slices 1-2 are now built (section 7). Line references in sections 1-4 describe the code as it was before slice 1.
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
2. **New catering enquiries** - Question: "what is waiting for a first reply?" Data: status=new count, of every date (built as a count only; see section 7) from the same table (statuses at class-doughboss-catering.php:23-30). Roles: owner, co-owner, managers. States: live / UNSEEN / off (switch).
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
| Menu/catalogue | Square and WordPress both (owner answer 2026-11-01); [CONFIRM: which wins and sync direction] | [CONFIRM] | Not read by CRM today | n/a | UNSEEN / not configured |
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
- No home screen aggregation existed before slice 1; only the single CRM inbox section (class-doughboss-portals.php:248, before slice 1).
- Catering query has no location filter (class-doughboss-catering.php:1048) - per-shop cards need one.

## 5. Slices (smallest first, switches default-off)

1. **Home shell + catering inbox card.** Acceptance: new "home" section in management portal renders the existing inbox card; UNSEEN banner on DB error; no writes on render. Tests: extend tests/test-crm.php patterns - capability denial, UNSEEN on injected DB error, no SQL on denial. Switch: `crm_home_enabled`, default off. (Built as count cards rather than the inbox list: see section 7.)
2. **New-enquiries + production-queue cards.** Acceptance: counts from existing table; UNSEEN on error; per-page-free single count query. Tests: fixture rows by status; error path shows UNSEEN not 0. Switch default off.
3. **Location scoping for managers.** Acceptance: manager with doughboss_location_id sees own-shop cards only; owner/co-owner see all; unassigned multi-store user gets UNSEEN not all. Tests: staff-scope matrix (class-doughboss-staff-scope.php:41) against card visibility. Switch default off.
4. **Approval + awaiting-payment cards.** Acceptance: pending, approved_unpaid and payment-in-progress counts from the preorder-approval table; no action buttons (read-only, per docs/implementation/CRM-EXECUTION-PLAN-20261030.md:130). Tests: state fixtures; UNSEEN on error. Switch default off.
5. **Reports link card + Square snapshot card (Revesby).** Acceptance: links to existing reports; order snapshot shows UNSEEN when Square read fails. Tests: fixture + injected failure. Switch default off.

## 6. Owner answers (Elie, 2026-11-01)

1. Max staleness per domain before a card says UNSEEN: **team decision** - open until the team sets it; until then each card shows its read time and goes UNSEEN only on a failed read.
2. Charlie: **both** - a WordPress login (all shops) and the DoughBoss HQ Telegram bot.
3. Menu/catalogue: **Square and WordPress** both hold it. Which one wins when they disagree, and which way they sync, is [CONFIRM: menu sync direction].
4. Kristy: **yes**, she gets her own catering approver role.
5. Staff: **yes**, staff see cards. Build two roles: **manager** (own shop) and **staff** (own shop, own shift). Which cards staff see is [CONFIRM: staff card list].
6. Per-shop manager view before or after the all-shop owner view: **team vote** - open.

### What changes because of these answers
- New roles to add (default off, one slice before slice 3): `doughboss_catering_approver` for Kristy; a shop **manager** role and a shop **staff** role, each bound to one `doughboss_location_id`; Charlie keeps an all-shop capability.
- Card visibility gains a staff column; staff cards are limited to their own shop and shift.
- The menu row in section 3 stays [CONFIRM] until the sync direction is decided.
- Slices 1-2 can proceed now; the order of slice 3 waits on the team vote.

## 7. What is built (slices 1-2)

- **Switch:** `crm_home_enabled` in the DoughBoss settings option (`class-doughboss-settings.php`), default **0**. There is **no admin checkbox** for it yet: it is turned on by setting `crm_home_enabled` to 1 in the `doughboss_settings` option. Only 1 (stored as 1, '1' or true) turns it on; any other value, including text such as "yes", leaves it off. That is deliberate: the owner turns it on when the cards are released.
- **Section:** `DoughBoss_Home::management_section()` decides the management section. `?section=home` is Home only while the switch is on; off, it falls back to the overview. The Home nav link shows only while it is on. The overview stays the default.
- **Card: New catering enquiries.** The number of enquiries in status `new`, of every date (not only today's), from `DoughBoss_Catering::status_count_checked()`: a single count query that reads no enquiry rows, so no customer details. The link opens the CRM filtered to `new`.
- **Card: Catering production queue.** The number of jobs in deposit_paid, confirmed, balance_due or paid, from `DoughBoss_Catering::production_queue_count_checked()`. The older `production_queue_count()` now wraps the checked one and keeps its old answer, 0 on a failed read, for its existing REST caller. The link opens the CRM unfiltered: the CRM filters one status at a time, so its list is not the same set as this count. [CONFIRM: a production-queue filter in the CRM list]
- **Both cards:** they need management access (manage_doughboss or manage_options); a person without it causes no database read. A failed or empty read shows "Unseen", never a number. Each card shows the time it was read in the site's own timezone (`wp_date`), with the exact UTC time in the `<time datetime>` attribute. Rendering only reads.
- **Not yet:** shop scoping (slice 3: today both counts cover every shop), the approval and awaiting-payment cards (slice 4), and the reports and Square cards (slice 5).
- **Follow-up outside this change:** the REST controller's production count (`class-doughboss-rest-controller.php`, `production_queue_count()`) still shows 0 when the read fails. The old method now wraps `production_queue_count_checked()`, so there is one copy of the query; the REST screen should move to the checked one so it can say "unseen".
