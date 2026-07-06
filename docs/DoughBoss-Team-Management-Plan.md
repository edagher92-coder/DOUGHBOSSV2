# DoughBoss — Team and Management Plan

**Task 10 — consolidated program plan.** Synthesizes the Engineering Manager, Product/UX
Manager, and Delivery/Operations Manager lenses (each of which built on the Front-End,
Back-End, Data Analyst, QA, DevOps, and UI/UX Designer specialist reviews conducted earlier
in this session) into one document: nine role sections and one Program RACI. No new repo
digging was done for this pass — every citation below (`file:line`) traces back to a specific
finding already logged by a specialist lens in this session; this document's job is to
organize and de-duplicate, not re-litigate.

**Scope caveat, stated once, applies throughout:** production state of **doughboss.com.au**
is not verified anywhere in this plan — no live-site tool was available this session. Every
task, deliverable, and risk below is scoped to the repository (branch `claude/funny-goodall-gsoog4`,
PR #2, draft) and a future staging deploy via WPVibe. Nothing here should be read as a
claim about what is currently live.

---

## How to read this document

1. **Executive risk register** — the handful of findings that matter regardless of which
   lens found them, ranked, with an explicit go/no-go call on real-money payments.
2. **Sequencing rules** — the order fixes must land in, because this codebase has zero
   automated tests and zero CI enforcement, so *order of operations is itself a risk control*.
3. **Nine role sections** — Engineering Manager, Product/UX Manager, Delivery/Operations
   Manager, then the six specialists (Front-End, Back-End, Data Analyst, QA, DevOps, UI/UX
   Designer), each with Responsibilities / Immediate tasks / Deliverables / Risks owned /
   Dependencies.
4. **Program RACI** — one table, 33 rows, covering every workstream named above.

---

## Executive risk register

Four items are escalated above every other finding because each carries real money,
security, or revenue exposure and none is contained to a single specialist's surface area.
These are the items an Engineering Manager or Ops lead should look at first regardless of
which team "owns" the file:

| Rank | Risk | Where | Why it's Critical/High |
|---|---|---|---|
| 1 | Checkout can capture a customer's card with **no reconciliation or refund path** if the order row never gets created, and the error copy at `rest-controller.php:2195`/`2530` falsely tells the customer "it will be reversed automatically" when nothing does that (`DoughBoss_Stripe::create_refund()` at `stripe.php:134` has zero callers anywhere in the codebase) | Back-End Finding 1 | Direct "customer charged, shop gets nothing, and the error message lies" scenario |
| 2 | `create_payment_intent` (`rest-controller.php:1674`) skips the same `ordering_open()` / `enable_delivery` / `enable_pickup` gates `checkout()` enforces at `1998-2014` — a card can be charged for an order the shop will then refuse to create | Back-End Finding 2 | Compounds Risk 1: money can be taken for an order that was never going to be accepted |
| 3 | `enable_cors()` / `send_cors_headers()` (`rest-controller.php:52-79`) strips WordPress's default `rest_send_cors_headers` for **every** REST route on the site, not just `doughboss/v1` | DevOps Finding 1 | Deployment-readiness blocker — could silently break any other REST consumer on the same WordPress install, discovered only in production |
| 4 | Checkout form, including the mounted Stripe Elements iframe, is destroyed and rebuilt on every cart mutation (`doughboss.js:356-423`, triggered from lines `251`, `331`, `442`, `448`, `502`, `519`) | Front-End Finding 1 | A customer who edits quantity or applies a voucher mid-card-entry silently loses what they typed — friction today, compounding risk 1/2 if it also masks a failed charge |

**Product decision, stated plainly (Product/UX Manager call, binding until reversed):**
**do not turn on `payments_enabled` (Stripe) for any site taking real customer money** until
Risk 1 (reconciliation/refund path + honest error copy) and Risk 4 (checkout form survives
cart edits) are fixed — or, as an interim mitigation only, until there is a documented manual
daily process to reconcile the Stripe dashboard against DoughBoss orders and refund by hand.
The underlying money-safety logic that *is* shipped — voucher atomic claim, checkout DB
transactions, PaymentIntent replay protection — is independently verified as correct by
Back-End, QA, and Architecture passes; the exposure above is specifically the *seam* between
payment capture and order creation, not the core logic.

Three fixes already **shipped in code today** (v2.12.3, this session, NOT yet deployed to
doughboss.com.au) sit ahead of all of the above and just need a supervised deploy:
cart-token case-mismatch (`class-doughboss-cart.php` `get_token()`), the settings-save silently
wiping unlisted keys (`admin/class-doughboss-admin.php` `sanitize_settings()`), and Stripe/POSPal
secrets echoing into admin HTML. See Delivery/Operations Manager, Immediate task 1.

---

## Sequencing rules (binding on all roles)

Three gating rules apply across the whole plan, because the codebase has **0% automated test
coverage** (no `tests/`, no `phpunit.xml`, no `composer.json`, no JS test runner — confirmed by
exhaustive repo search) and no CI enforcement of correctness (`scripts/dev-check.sh` is `php -l`
syntax-lint only and always exits `0` by design; `.github/workflows/pages.yml` deploys only the
static demo). In a codebase like this, *sequencing is a risk control*, not a scheduling nicety:

1. **Tests before risky refactors.** QA's PHPUnit harness must exist before Back-End (or anyone)
   modifies any of the five areas QA has flagged as too risky to touch blind: voucher
   `redeem()`/`claim()`, `verify_payment()`, `Order::create()` transaction boundaries,
   `Cart::totals()` GST branches, `Coupon_Code::normalize()`. This also blocks any god-class
   split of `rest-controller.php` (Program RACI row 10) until row 9 lands.
2. **CORS and Stripe/payment-intent fixes land together.** DevOps's CORS fix and Back-End's
   payment-intent gating/rate-limit fixes touch the same file
   (`class-doughboss-rest-controller.php`) and should go through one staging verification pass,
   not two, to avoid two separate smoke-test cycles on the same file.
3. **UI follows business logic, not the other way round.** Front-End's checkout-form
   partial-render refactor (Risk 4) should land *after*, not before, Back-End's payment-intent
   gating fix (Risk 2) — otherwise the UI gets rebuilt against a payment path that's still
   accepting money for orders the shop can't fulfill.

The Engineering Manager is accountable for holding this order (see role section below); the
Program RACI marks the corresponding rows accordingly.

---

## 1. Engineering Manager

### Responsibilities
- Own **technical sequencing** across all five other engineering roles (Front-End, Back-End,
  Data Analyst, QA, DevOps) so fixes land in an order that doesn't destabilize a codebase with
  zero automated tests and zero CI enforcement — this is the plan's single point of authority
  for "who goes first."
- Hold the line on the three sequencing rules above, specifically: block any risky refactor
  (voucher redeem/claim, `verify_payment()`, `Order::create()`, `Cart::totals()` GST branches,
  `Coupon_Code::normalize()`) from being touched until QA's harness exists and actually covers
  that code path — not just "a harness exists somewhere."
- Arbitrate the two-file collision: CORS (DevOps-identified, Back-End-implemented) and
  Stripe/payment-intent fixes (Back-End) both land in `class-doughboss-rest-controller.php` —
  one PR, one staging pass, not two uncoordinated diffs to the same file.
- Give final technical sign-off before Front-End's checkout-form refactor starts, confirming
  Back-End's payment-intent gating fix (Risk 2) has actually landed first.
- Push back on any specialist's proposed scope that would touch a money-path class without a
  test in place first, even under release-date pressure.

### Immediate tasks
1. Confirm and communicate the sequencing rules above as a hard gate, not a suggestion —
   specifically that Back-End's five "too risky without tests" areas are off-limits until QA's
   harness (Deliverable below) exists and has real coverage on that exact area.
2. Broker the CORS + payment-intent joint PR: same file, same reviewer pass, same staging
   smoke test, one go/no-go decision — not two.
3. Sign off (or block) Front-End starting the `draw()` checkout-form split until Back-End's
   `create_payment_intent` gating fix (`rest-controller.php:1674`) has merged.
4. Review each specialist's task list above for hidden ordering conflicts before work starts
   (e.g., Data Analyst's Reports page is explicitly blocked on Back-End's refund-status wiring;
   confirm Data Analyst doesn't start that build early).
5. Track the "areas too risky to modify without tests" list as a living gate — update it as
   QA's coverage actually lands, not on a fixed calendar date.

### Deliverables
- The sequencing rules themselves, written down and enforced (this section + the Sequencing
  Rules block above) — treated as a merge-gate policy, not just documentation.
- Technical sign-off record for: (a) the joint CORS/payment-intent PR going to staging, (b)
  Front-End starting the checkout-form refactor, (c) any PR touching the five QA-flagged
  risky areas.
- A running "gate status" note (harness exists? covers this area? sign-off given?) attached to
  each risky-area PR.

### Risks owned
- **Sequencing violation risk**: if a risky refactor lands before its test-coverage
  prerequisite, the one thing currently protecting the money paths (a human reading the diff
  carefully) is removed with nothing to replace it.
- **Two-cooks-one-file risk**: uncoordinated parallel edits to `class-doughboss-rest-controller.php`
  (2,699 lines, already a god-class) from DevOps's CORS fix and Back-End's payment-intent fixes
  landing independently could produce a merge conflict or, worse, a silent regression neither
  diff's author notices alone.
- **UI-ahead-of-logic risk**: shipping Front-End's checkout refactor against a payment-intent
  path that still skips business-rule gates would ship a *more polished* version of Risk 2, not
  a fix for it.

### Dependencies
- QA's harness timeline (realistic estimate: ~0.5–1 day just to stand up scaffolding, before
  any test cases) directly determines when the risky-area gate opens.
- Back-End must land Risk 2 (payment-intent gating) before Front-End's checkout-form work
  starts — this is the one hard cross-role blocking dependency in the whole plan.
- DevOps and Back-End must coordinate the CORS/Stripe joint PR rather than filing independent
  diffs.
- Delivery/Operations Manager for the actual staging deploy window once sign-off is given.

---

## 2. Product/UX Manager

### Responsibilities
- Make the **go/no-go call on enabling real-money Stripe payments** (see Executive risk
  register) and hold that line until the stated conditions are met.
- Broker the demo-site brand-consistency decision between UI/UX Designer and Engineering —
  "finish the rebrand everywhere" vs. "internal tools intentionally keep a distinct accent" is
  a product decision, not something UI/UX Designer should have to guess at while executing.
  See UI/UX Designer's own Immediate task 1 for the concrete choice being brokered.
- Own sign-off on customer-facing copy tied to money and trust — most urgently the false
  "reversed automatically" refund language (Risk 1) — since that's a legal/trust statement,
  not a styling choice.
- Sequence the business-operations gaps (no revenue reporting, no Privacy Act compliance
  hooks) relative to the money-safety fixes so neither gets silently deprioritized behind the
  other.
- Decide, with input from whoever is legally accountable, the disclosure wording required on
  `backend.html` / `owner.html` / `staff.html` before those pages are shown to any prospect
  again (see UI/UX Designer Immediate task 6).

### Immediate tasks
1. Issue the payments go/no-go decision as a written, dated call (see Executive risk register)
   — not enable Stripe on any real-money site until Back-End Finding 1 (reconciliation/refund
   path + honest copy) and Front-End Finding 1 (checkout-form teardown) are both fixed, or an
   explicit manual-reconciliation interim process is documented and agreed.
2. Rule on the demo brand-color question (UIUX Finding 1: three unreconciled palettes across 8
   pages) — either commission finishing the black/white rebrand on
   `backend/owner/staff/franchise/licensing/privacy/terms.html`, or formally ratify "operator
   tools keep a gold accent on purpose" — and get that ruling into the design-token spec so it
   stops being ambiguous.
3. Approve corrected customer-facing refund/error copy once Back-End proposes replacement text
   for the "reversed automatically" claim at `rest-controller.php:2195`/`2530`.
4. Sign off on the exact disclosure wording ("this is a simulated demo") for
   `backend.html`/`owner.html`/`staff.html` — flagged as the highest-credibility-risk gap
   because these are the most realistic-looking, most likely to be forwarded standalone, and
   currently the only demo pages with zero such language.
5. Do not hand a prospect the `licensing.html` link on mobile until UI/UX Designer's
   responsive-table fix lands (13 unwrapped tables today) — treat this as a hard rule for
   sales/franchise conversations in the interim, not just a backlog item.
6. Sequence near-term roadmap explicitly as: (a) payment reconciliation + checkout-form fix
   before any real-money launch; (b) disclosure + brand-consistency fixes before the next
   external demo; (c) revenue/reporting page and Privacy Act export hooks (Data Analyst
   Finding 4 — Australian Privacy Act 1988 APP 11 applies regardless of GDPR status) as
   near-term roadmap, since an owner will notice both within their first week of real use.

### Deliverables
- A written, dated go/no-go memo on enabling live Stripe payments, with explicit reopen
  criteria.
- The demo brand-consistency ruling (finish vs. ratify-as-distinct), handed to UI/UX Designer
  as a design-system requirement.
- Approved replacement copy for the refund/error messaging.
- Approved disclosure copy for the three at-risk demo pages.
- The (a)/(b)/(c) roadmap sequencing, communicated to Delivery/Operations Manager for
  scheduling.

### Risks owned
- Shipping a payment flow whose own error message promises something the code doesn't do —
  this is a trust and potentially legal exposure, not just a bug.
- Presenting an inconsistent, undisclosed-simulation demo to a franchise prospect — a
  credibility risk in a sales context specifically, since `backend.html`/`staff.html` look the
  most real and are exactly the ones with no palette consistency and no disclosure today.
- A legal/contract page (`licensing.html`) that's unreadable on a prospect's phone is a lost or
  delayed business deal, not a cosmetic issue.
- Under-scoping the checkout partial-render acceptance criteria loosely enough that Front-End's
  fix doesn't actually solve "customer loses a half-typed card number" — needs precise
  acceptance criteria, not just "make it feel less broken" (see hand-off note below).

### Dependencies
- Back-End must land Risk 1/Risk 2 fixes before the payments go/no-go can flip to "go."
- UI/UX Designer executes the brand-consistency and disclosure decisions once ruled on here;
  Front-End implements the checkout-form fix against acceptance criteria this role and UI/UX
  Designer co-author (see UI/UX Designer Immediate task 9 for the actual spec hand-off).
- Data Analyst's Reports page (revenue/AOV/CSV) and Privacy Act export hooks are on this role's
  near-term roadmap but explicitly *behind* the money-safety fixes in sequencing.
- Whoever is legally accountable for the business must weigh in on exact disclosure wording —
  this isn't a pure design call.

---

## 3. Delivery/Operations Manager

### Responsibilities
- Own the **release calendar and sequencing** across all specialist findings (23+ distinct
  issues spanning Low→Critical) so fixes land in an order that doesn't destabilize a codebase
  with zero automated tests and no CI enforcement.
- Own the **CLAUDE.md / readme.txt currency problem as a process failure**, not a one-time doc
  fix: `CLAUDE.md` states v2.5.0/DB v1.4.0/"3 tables"/catering "zero code" against an actual
  v2.12.3/DB v1.7.0/6-tables/836-lines-of-shipped-catering reality; `readme.txt`'s `Stable tag`
  (2.12.1) disagrees with `doughboss.php` (2.12.3), and its own FAQ contradicts its own
  changelog. This has already drifted once this badly — the fix has to include a mechanism, not
  just corrected text.
- Own the **staging → production deployment process** end-to-end (build → staging smoke test
  via WPVibe → backup confirmation → activate → post-deploy monitoring) — no such pipeline
  exists today beyond `build-zip.sh`'s plain file copy.
- Own **cross-workstream risk visibility**: the three already-shipped-but-undeployed v2.12.3
  fixes, the four Executive-register items, and every other High finding, so nothing slips
  because "someone else's report already covered it."
- Maintain the RACI and risk register (this document) as a living artifact, and gate any
  production deploy on the Executive risk register items above.

### Immediate tasks
1. Confirm the three already-shipped v2.12.3 fixes (cart-token case-mismatch, settings-wipe,
   secret-echo) are staged on branch `claude/funny-goodall-gsoog4` / PR #2 but **explicitly not
   yet pushed to doughboss.com.au**, and hold that no deploy happens without an explicit
   go-ahead plus a fresh UpdraftPlus backup.
2. Regenerate `CLAUDE.md` (version numbers, table count, catering status, and the Stripe
   webhook/refund/push-transport claims — all four listed as "not yet implemented" are actually
   shipped, off by default) and reconcile `readme.txt`'s `Stable tag` and payments FAQ before
   this project is shown to any client or new engineer.
3. Rank-order the four Executive-register risks (CORS, checkout money-orphan + payment-intent
   gating, catering privilege gap if separately confirmed, checkout-form data loss) for
   immediate scheduling, since none is contained to one team.
4. Stand up the build-time consistency gate: version/readme match check + a `php -l` re-run
   inside `build-zip.sh` before it zips — cheap, and prevents the CLAUDE.md/readme.txt drift
   from recurring next release.
5. Scope and sequence the QA test-harness investment (realistic estimate ~1–1.5 weeks for a
   first real pass covering the risky areas) as a hard prerequisite before the god-class
   `rest-controller.php` split or any refactor of voucher/payment logic — enforced jointly with
   Engineering Manager's sequencing gate above.
6. Broker the demo-site brand reconciliation decision between Product/UX Manager and UI/UX
   Designer, and schedule the resulting work once a direction is picked.
7. Log and track items that are not directly actionable this cycle so they aren't silently
   dropped: the Staff Console (`app/`) has no documented hosting/deploy story at all; live
   production state of doughboss.com.au cannot be verified from this environment.

### Deliverables
- Updated `CLAUDE.md` and `readme.txt` (version, DB version, table count, feature status all
  reconciled to actual repo state).
- A written **release runbook**: staging smoke test checklist → backup confirmation → deploy →
  post-deploy log-tail check, formalizing the deployment steps below.
- This document (role sections + Program RACI), kept current as items close.
- A risk register derived from the Executive risk register above, reviewed weekly until those
  items close.
- A go/no-go sign-off record for the first production deploy of v2.12.3+ fixes.

### Risks owned
- **Deploy-without-backup risk**: there is no in-plugin rollback/downgrade path
  (`doughboss_db_version` only moves forward; migrations have no automated undo) — the
  pre-deploy backup is the *only* rollback story, and confirming it exists before any
  activation/upgrade is this role's accountability, not a checkbox someone else owns.
- **Drift-recurrence risk**: CLAUDE.md/readme.txt have already drifted this badly once; without
  the build-time consistency gate (Immediate task 4), it drifts again by the next feature.
- **Sequencing risk**: refactor work (god-class split, DI, response-envelope standardization)
  landing before the test harness exists removes the one thing currently protecting the money
  paths — this role blocks that ordering jointly with Engineering Manager.
- **Cross-team handoff risk**: several findings sit at the boundary of two roles (demo rebrand
  is Front-End + UI/UX Designer; Privacy Act hooks are Data Analyst + Back-End; refund UX is
  Back-End + Product/UX Manager) — this role owns making sure none of these fall through the
  crack of "I thought the other team had it."

### Dependencies
- Engineering Manager for final technical sign-off on sequencing and refactor-risk calls.
- Product/UX Manager for the demo-brand-unification decision and any customer-facing copy
  changes (e.g., the false "reversed automatically" messaging).
- DevOps Engineer for the actual CORS/build-gate/CI implementation.
- QA Engineer for the test-harness timeline that gates all refactor work.
- No live-site tool is available this session — any production-state claim depends on WPVibe
  access at actual staging/deploy time, which this role does not control from here.

---

## 4. Front-End Specialist

### Responsibilities
- Own the four independently-enqueued vanilla-JS surfaces: `public/js/doughboss.js`
  (storefront), `doughboss-orderboard.js` (KDS), `doughboss-voucher-scan.js`, and `app/app.js`
  (standalone Staff Console PWA) — plus their paired CSS and accessibility/animation
  conventions.
- Own the demo site's brand/UX consistency at the implementation layer (`demo/*.html`,
  `demo/demo.css`), executing decisions ruled on by Product/UX Manager and UI/UX Designer
  rather than making the brand call unilaterally.

### Immediate tasks
1. **[Highest priority — real payment-flow data loss]** Fix the checkout-form/Stripe-teardown
   bug: `doughboss.js:356-423` (`draw()`) rebuilds the entire `[doughboss_cart]` container,
   including a freshly-mounted Stripe Elements iframe, on every cart mutation (call sites at
   `251`, `331`, `442`, `448`, `502`, `519`). Split into a mutable region (lines/totals/voucher)
   vs. a checkout form rendered once. Reuse the guard pattern already proven in
   `demo/menu-order.js:54` (`if (drawerOpen && !checkoutMode)`). Per the sequencing rules above,
   **start this only after** Back-End's payment-intent gating fix (Risk 2) has landed, and build
   against the acceptance criteria UI/UX Designer specs (item 9 below): cart lines/totals
   re-render; in-progress name/email/phone/notes and the mounted card field do not.
2. Add `aria-live`/`role="alert"` to voucher-scan result regions in both
   `doughboss-voucher-scan.js:131,273-285` and `app.js:277,295-302`, plus `app.js`'s `toast()`
   (`64-69`) — the primary till feedback loop is currently silent for screen-reader users,
   inconsistent with the pattern already used at `doughboss.js:495,532` in the same codebase.
3. Guard `doughboss-orderboard.css`'s two infinite animations (`83-91`, `153-160`) with
   `prefers-reduced-motion`, matching the existing pattern at `doughboss.css:23` and
   `demo.css:592`.
4. Stop the order board's 30-second timestamp-refresh tick from doing a full `render()` rebuild
   (`doughboss-orderboard.js:391`) — update `.db-card-time` nodes in place instead.
5. Align `app.js`'s three `setInterval` pollers (`419`, `502`, `562`) and
   `doughboss-voucher-scan.js:474` onto the board's resolve-then-reschedule pattern
   (`orderboard.js:314-318`); surface a visible "Reconnecting…" state in `app.js` — currently
   none at all, its three `.catch(function(){})` blocks are silently empty.
6. Wrap `app.js`'s login fields (`131-186`) in a real `<form>` for Enter-to-submit and
   password-manager support — relevant given the app's own known plaintext-localStorage
   credential storage.
7. Add focus management to the checkout-confirmation swap (`doughboss.js:578-584`) and
   order-tracking result swap (`655-670`), reusing the `tabindex="-1"` + `.focus()` pattern
   already present at `demo/index.html:414-415`.
8. Demo site execution work (direction set by Product/UX Manager + UI/UX Designer, not decided
   here): resolve or intentionally preserve the three-way brand-color split (B&W storefront vs.
   gold/amber `backend.html`/`owner.html` vs. burnt-orange legal pages); delete the orphaned
   `var(--card,#fff)` / hardcoded `#b5571f` in `demo.css:613-614`; add a skip link to
   `index.html` (copy the working implementation from `backend.html:34-36`); wrap the 13 tables
   in `licensing.html` and add a responsive breakpoint to all three legal pages.

### Deliverables
- A PR splitting `draw()`'s checkout-form lifecycle from cart-mutation rebuilds, built against
  UI/UX Designer's acceptance-criteria spec.
- An accessibility-hardening PR bundle: aria-live additions, reduced-motion guards, focus
  management on view swaps.
- Demo-site execution PR implementing whichever brand-color decision Product/UX Manager rules
  on, plus the orphaned-color cleanup, skip link, and legal-page responsive fixes.
- `doughboss-orderboard.css` tokenized onto CSS custom properties.

### Risks owned
- Regressing the Stripe Elements mount/checkout flow while splitting `draw()` — this is the
  highest-stakes JS change in the entire plan given it sits directly on the payment path.
- Breaking the order board's non-overlapping-poll guarantee while touching its render loop.

### Dependencies
- Back-End must land the payment-intent business-rule gating fix (Risk 2) before or alongside
  this role's checkout-form refactor — starting the refactor first would rebuild the UI against
  a still-broken payment path (Engineering Manager sequencing rule 3).
- QA has zero automated coverage for any of this JS surface (no `package.json`, no test runner
  exists) — every change here ships on manual click-through only until that changes; explicitly
  scoped as follow-on work for QA, not a blocker on shipping these fixes.
- DevOps's CORS fix affects `app.js`'s cross-origin calls to the WordPress backend and must be
  smoke-tested against the Staff Console specifically once that fix lands.
- UI/UX Designer for the checkout-form partial-render acceptance criteria and the demo-site
  design-token spec this role implements against.

---

## 5. Back-End Specialist

### Responsibilities
- Own the 2,699-line `class-doughboss-rest-controller.php`, plus `class-doughboss-order.php`,
  `-cart.php`, `-voucher.php`, `-catering.php`, `-stripe.php` — every money-path class — plus
  the six dependency-free integrations (Stripe, POSPal, Mercure, ntfy, ClickSend, printer).

### Immediate tasks (ranked by financial/security exposure)
1. Rate-limit `create_payment_intent` (`rest-controller.php:1674`), `catering_payment_intent`
   (`2430`), and `catering_confirm_payment` (`2489`) — currently the only money-adjacent routes
   with **no** `rate_limited()` call, unlike checkout (8 requests/10 min) and every voucher
   bucket. Add a bucket matching the existing pattern.
2. Move `create_payment_intent` up to the same business gates `checkout()` already enforces —
   `ordering_open()`, `enable_delivery`, `enable_pickup` (currently only checked at `1998-2014`,
   not at `1674-1712`) — so a card cannot be charged for an order the shop will refuse. This is
   Executive risk register item 2, and gates Front-End's checkout-form refactor per the
   sequencing rules.
3. Fix the catering-status permission boundary: `/admin/catering/{id}/status` is currently
   gated `verify_admin` (kitchen-tablet-or-owner), letting a `manage_doughboss_kds`-only account
   mark a catering job `paid`/`lost` — change to `verify_manage`, matching the precedent already
   set for vouchers ("a till can redeem value, never mint or finalize it").
4. Wire `DoughBoss_Stripe::create_refund()` (`stripe.php:134`, currently zero callers anywhere
   in the codebase) into the `verify_payment()` unverified-path, and fix the customer-facing
   copy at `rest-controller.php:2195`/`2530` that falsely claims charges are "reversed
   automatically" — nothing does this today. This is Executive risk register item 1.
5. Add env-first secret overrides for Stripe (`settings.php:307-337`) — the one integration of
   six that skips the `DOUGHBOSS_*` constant → `getenv()` pattern already used consistently for
   POSPal/Mercure/ntfy/ClickSend/printer.
6. Batch the N+1 item-fetch in `active_orders()` (`order.php:191-241`, one query per order
   inside the loop) and the admin Orders list (`admin.php:665`) into a single `IN (...)` query —
   this is the order board's ~7-second poll path, so the cost compounds continuously.
7. Fix the rate limiter's non-atomic get-then-`set_transient` increment
   (`rest-controller.php:889-898`) — a scripted burst can exceed the stated voucher-guessing
   limits.
8. Fix `sanitize_settings()`'s `currency_code` fallback from hardcoded `'USD'` to
   preserve-existing-value (`admin.php:179`) — a leftover from the pre-1.3.0-migration US demo
   config, now silently reverting an AUD site's currency on any Settings save that omits it.
9. Fix the site-wide CORS side effect: `enable_cors()`/`send_cors_headers()`
   (`rest-controller.php:52-79`) removes WordPress's default `rest_send_cors_headers` for
   **every** REST route on the site, not just `doughboss/v1` — this is Executive risk register
   item 3; land it as the joint PR with DevOps per the sequencing rules (same file, one staging
   pass).
10. Move blocking POSPal push and ClickSend SMS (`pospal-orders.php:35`, `sms.php:220-231`) off
    the synchronous order-creation/status-change path, and log `wp_mail()`'s return value in
    `send_confirmation()` (`rest-controller.php:2690-2697`) — currently the one integration point
    that silently swallows failures, breaking the "log status on every failure" convention
    followed everywhere else in the codebase.
11. Add the missing index on `catering_enquiries.balance_intent_id` plus a version-gated
    migration — the schema currently forces a full table scan on every Stripe webhook delivery.

### Deliverables
- A sequence of small, individually-testable PRs: rate limiting, permission-callback fix,
  refund wiring, CORS scoping, N+1 batching, env-first Stripe secrets — each shipped with its
  own manual regression note since no automated safety net exists yet.

### Risks owned
- Every task on this list touches real money and real customer PII; a careless change to
  `verify_payment()`'s amount/currency comparison or `payment_intent_used()` replay guard is a
  direct path to free orders or double-charged customers.
- The CORS fix (task 9) is the single change most likely to regress something *outside*
  DoughBoss's own feature surface — any other REST consumer on the same WordPress site.

### Dependencies
- QA's manual checkout+voucher+POSPal checklist must run in Stripe **test mode** before and
  after every task on this list — there is no automated safety net.
- Delivery/Operations Manager owns the actual staging deploy (via WPVibe) where these changes
  get smoke-tested.
- Front-End needs task 2 (payment-intent gating) landed before it starts the checkout-form
  refactor, and needs to know when task 4 (refund wiring) ships so it can surface refund status
  in the order UI.
- Data Analyst's Reports page depends on task 4 (refund status existing) before any revenue
  report can avoid overstating gross revenue.
- Engineering Manager and DevOps for the joint sequencing of task 9 (CORS) with DevOps's own
  CORS-adjacent findings.

---

## 6. Data Analyst

### Responsibilities
- Own the settings schema (`DoughBoss_Settings::defaults()`), REST input validation, the
  currently-nonexistent reporting/analytics surface, PII/privacy compliance for the three
  tables holding customer data (orders, catering, vouchers), and data-integrity edge cases in
  voucher/catering business rules.

### Immediate tasks
1. Add `args` schemas with real `validate_callback`s (not just `sanitize_callback`) to
   `/checkout` (`rest-controller.php:528-536`) and `/voucher/issue` (`257-265`) — the two
   highest-stakes routes in the plugin currently have the least structural input protection,
   relying entirely on inline manual checks.
2. Partner with Back-End on the `currency_code` fallback fix (`admin.php:179`) — confirm the
   corrected fallback doesn't clash with migration `1.3.0`'s own AUD-correction intent.
3. Scope and spec a Reports admin page: `DoughBoss_Order::query()` (`order.php:422-477`) has
   zero `SUM()`/`GROUP BY` anywhere in the class — an owner today cannot see daily revenue,
   AOV, top items, or pickup/delivery mix from wp-admin. **This must wait on Back-End's
   refund-status wiring** (currently no `'refunded'` status is ever written), or a first-cut
   revenue report will overstate gross revenue with no way for an owner to know.
4. Register WordPress's core privacy exporters/erasers
   (`wp_privacy_personal_data_exporters`/`_erasers` — currently zero hits repo-wide) against the
   PII columns in orders, catering enquiries, and vouchers. Design an anonymization policy (null
   PII, keep financial totals for AU tax retention) rather than deleting rows outright —
   Australian Privacy Act 1988 APP 11 applies regardless of GDPR status.
5. Document `voucher_campaigns` (`voucher.php:429`) and `pospal_label`
   (`settings.php:543,588`) in `defaults()` — both are live, read settings keys currently
   invisible to anyone reading the schema of record.
6. Add de-dup handling for `doughboss_locations.slug` (currently a plain `KEY`, not `UNIQUE`,
   with no collision check on create) before it becomes load-bearing for any future
   shop-picker URL feature.
7. Bound catering `event_date` (reject `< today`, currently unchecked) and `guest_count` (no
   upper cap, flows straight into pricing) — `catering.php:494-501`,
   `rest-controller.php:670-673`.
8. Cap voucher `type=percent` at `value <= 100` in `issue()` (`voucher.php:93-100`) — not a
   financial-loss bug since `evaluate()` clamps to subtotal, but a nonsensical stored value
   would mislead any future report.
9. Fix the menu seeder's exact-title matching (`menu-seeder.php:106-119`) so a renamed seeded
   item doesn't silently duplicate on the next `wp doughboss seed-menu` run — use the existing
   `_doughboss_seed` marker plus a stable synthetic key.

### Deliverables
- REST `args`/`validate_callback` additions for `/checkout` and `/voucher/issue`.
- A Reports page spec (blocked on refund status) with CSV export.
- Registered privacy exporter/eraser functions.
- An updated `defaults()` covering all live settings keys.

### Risks owned
- Getting the privacy eraser wrong — deleting order rows instead of anonymizing PII columns
  would break order-number history, voucher audit trails, and accounting reconciliation.
- A revenue report shipped before refund status exists would overstate gross revenue with no
  way for an owner to know.

### Dependencies
- Hard-blocked on Back-End for refund-status plumbing before the Reports page can be trusted.
- Needs Back-End to implement the `validate_callback` additions without breaking the existing
  inline checks in `checkout()`/`DoughBoss_Voucher::issue()` — those must stay as
  defense-in-depth, not be replaced.
- QA should turn the boundary conditions scoped here (`event_date`, percent cap, min-spend
  edges) into the same test cases already scoped in QA's own findings (cases 9, 10) rather than
  duplicating test-design work.
- Product/UX Manager's roadmap sequencing places this role's Reports page and privacy hooks
  behind the money-safety fixes, ahead of everything else.

---

## 7. QA Engineer

### Responsibilities
- Stand up the first automated test harness this project has ever had — confirmed 0%
  coverage: no `tests/`, no `phpunit.xml`, no `composer.json`, no JS test runner, and
  `scripts/dev-check.sh` is syntax-lint-only and always exits `0` by design.
- Own the manual regression checklists for every money-path feature until that harness has
  real coverage, and act as gatekeeper before anyone touches the five flagged risky areas.

### Immediate tasks
1. Stand up a minimal PHPUnit harness: `composer.json` (dev-only — must **not** be pulled into
   `build-zip.sh`'s shipped output), `wp-phpunit`/`yoast/phpunit-polyfills` or a lightweight
   `$wpdb`/`WP_Error` stub layer, and `tests/bootstrap.php`. This is a real prerequisite cost
   (~0.5–1 day) not a "just add a file" task, since there's no WordPress test scaffold to build
   on.
2. Day 1 (pure logic, no DB needed): `DoughBoss_Coupon_Code` round-trip/corruption/transposition
   /legacy-passthrough tests (cases 26–30, especially case 28 — verify whether transposed body
   parts are *actually* caught, since the docblock's "typo-resistant" claim for transpositions
   has never been empirically confirmed); `DoughBoss_Cart::totals()` GST-inclusive/exclusive/
   voucher-clamping arithmetic (cases 1–7).
3. Day 2: `DoughBoss_Voucher::evaluate()`'s pure branches — scope mismatch, window boundaries,
   exact-minimum-spend boundary (cases 8–10); `DoughBoss_Order::create()` happy path and
   empty-lines guard (31, 35).
4. Day 3+ (needs a real test DB): the concurrency/transactional cases — voucher `redeem()`'s
   atomic claim under two connections (case 11), `create()`'s rollback-reaches-the-order-row-too
   verification (case 32), order-number collision retry exhaustion (33–34), and Stripe webhook
   signature vectors (18–21, pure functions, cheap once the harness exists).
5. Run the full manual checkout+voucher+POSPal QA checklist (22 steps, already scoped) on
   staging in Stripe **test mode** before any release — with explicit regression checks for
   today's three already-shipped fixes: cart-token case bug (fresh browser, never-set cookie),
   settings-wipe (`option get doughboss_settings --format=json` diff before/after an unrelated
   Settings save), and secret-echo (eyeball all seven secret fields render blank post-save — no
   automated test enforces this yet).
6. Turn the release-readiness checklist into the actual gate: `php -l` clean, `Stable tag` vs.
   `DOUGHBOSS_VERSION` diff, `DOUGHBOSS_DB_VERSION` bump check when schema changes,
   `build-zip.sh` file-list diff against the previous release, grep for `error_log`/`var_dump`/
   `print_r` leftovers.

### Deliverables
- `tests/` + `phpunit.xml` + a dev-only `composer.json`.
- ~36 prioritized initial test cases (coupon-code, cart totals, voucher evaluate/redeem, order
  create, Stripe webhook).
- A written manual QA checklist.
- A release-readiness checklist wired into the actual release process.

### Risks owned
- False confidence from partial coverage — must explicitly flag the areas still too risky to
  refactor without tests (voucher `redeem()`/`claim()`, `verify_payment()`, `Order::create()`
  transaction boundaries, `Cart::totals()` GST branches, `Coupon_Code::normalize()`) and act as
  gatekeeper before anyone touches them, per the Engineering Manager's sequencing rule 1.

### Dependencies
- Back-End's classes have zero dependency injection except `DoughBoss_Cart` (everything else is
  hardcoded static calls — `DoughBoss_Settings::`, `DoughBoss_Voucher::`, etc.) — QA needs
  Back-End to accept `Settings`-sourced config as parameters (at minimum for `Cart`, `Voucher`,
  `Stripe`) to make these testable without a full WordPress bootstrap.
- DevOps needs to eventually wire the harness into CI once it exists (today nothing enforces
  even `php -l` as a merge gate).
- Front-End's JS surfaces have no test runner at all (no `package.json`) — explicitly scoped as
  follow-on work, not blocking the Day 1–3 PHP focus above.

---

## 8. DevOps Engineer

### Responsibilities
- Own the build/release process (`build-zip.sh`), docs accuracy (`readme.txt`, and — jointly
  with Delivery/Operations Manager — the stale `CLAUDE.md`), the one existing CI workflow
  (`.github/workflows/pages.yml`, which deploys only the static demo), and the actual
  deployment process to doughboss.com.au via WPVibe.

### Immediate tasks
1. Escalate and pair with Back-End on the CORS fix (Executive risk register item 3, Back-End
   Immediate task 9) — the one deployment-readiness blocker in this plan: `enable_cors()`
   (`rest-controller.php:52-79`) strips WordPress's default CORS handling site-wide, not just
   for `doughboss/v1`, on every request.
2. Fix `readme.txt` drift: `Stable tag: 2.12.1` vs. actual `2.12.3` in `doughboss.php`; stale
   `Tested up to: 6.5`; and a changelog that documents Stripe as shipped (`2.5.0`) directly
   contradicted by the FAQ still saying "payment integration... is planned for a future
   release." Fold in a refresh of `CLAUDE.md`'s own stale version/table-count/roadmap claims
   while touching docs (coordinate with Delivery/Operations Manager, who owns this as a process
   item).
3. Add a version/readme consistency gate plus a `php -l` pass to `build-zip.sh` before it zips
   — today nothing would catch the exact drift found in task 2 happening again next release.
4. Resolve the settings-defaults drift between `activator.php:225-230`'s hand-maintained seed
   array (`delivery_fee => 5.00`) and `Settings::defaults()` (`delivery_fee => 0`) — masked
   today by `wp_parse_args` merge order, but a maintainability trap for every setting added
   since. Recommend seeding the activator from `Settings::defaults()` directly, explicitly
   preserving the demo-content overrides (seeded sizes/toppings) it still needs.
5. Fix the three `0000-00-00 00:00:00` datetime defaults (`orders`, `catering_enquiries`,
   `voucher_redemptions`) to `NULL DEFAULT NULL`, matching the already-correct pattern two
   columns over in the same tables, via a version-gated migration.
6. Add an optional `GET /doughboss/v1/status` health endpoint (`verify_admin`-gated,
   booleans/counts only — no secrets/PII, matching the existing logging discipline) so an
   external uptime monitor has something to poll; today diagnosis means tailing the host's PHP
   error log for `DoughBoss` lines.
7. Own the deployment runbook end to end: pre-build gate → `build-zip.sh` → staging deploy via
   WPVibe to a **draft theme** (never straight to production) → smoke test (menu/cart/checkout
   with and without Stripe, order tracking, Live Order Board, and specifically a cross-origin
   check against the Staff Console to catch any CORS regression) → confirm a fresh UpdraftPlus
   backup exists → deploy → tail logs for the first few real orders → rollback-via-backup-restore
   plan (there is no in-plugin downgrade path; `doughboss_db_version` only moves forward).

### Deliverables
- Corrected `readme.txt` (and a coordinated pass on `CLAUDE.md`, owned jointly with
  Delivery/Operations Manager).
- A `build-zip.sh` with a version-consistency and lint gate.
- A written deployment runbook.
- A decision doc on consolidating the two settings-defaults sources.

### Risks owned
- A CORS fix scoped too narrowly could re-break the Staff Console's cross-origin calls; scoped
  too broadly, it could leave the original site-wide leak in place — must test both directions
  before shipping.
- No rollback mechanism exists beyond restoring a pre-deploy backup, so backup discipline
  before any migration-bearing deploy is entirely on this role.

### Dependencies
- Back-End implements the actual CORS code fix and the Stripe env-first secrets change (DevOps
  identifies/prioritizes, Back-End writes the diff, since both live in
  `rest-controller.php`/`settings.php`).
- QA's staging smoke test is the only verification gate before go-live given zero CI
  enforcement of plugin correctness today.
- Front-End must confirm `app/app.js`'s cross-origin behavior is unaffected post-CORS-fix,
  since the Staff Console is the one real external REST consumer this plugin has to worry
  about.
- Delivery/Operations Manager for the final go/no-go and backup-confirmation gate before any
  activation.

---

## 9. UI/UX Designer

### Responsibilities
- Own one coherent visual system across all 8 demo pages and the two live product surfaces
  (storefront cart/checkout, Live Order Board) — today there are effectively **three
  unreconciled brand palettes** in production markup: `--ember:#111111` on the storefront vs.
  `--gold:#b5571f`/`--amber:#d98a3d` on staff/backend/owner vs. `--ember:#d6502b` on the legal
  pages, with `franchise.html` mixing both systems in the same file.
- Own the accessibility contract the codebase has already half-proven it knows how to keep
  (skip links, focus-visible, reduced-motion, aria-live, focus management on view change) and
  make sure every new or touched screen inherits it rather than reinventing it — the pattern is
  *known* and *well-executed in specific places* but not propagated, which is a design-ops
  failure as much as an engineering one.
- Own the single source of truth for shared visual primitives (buttons, color tokens,
  card/empty-state contrast) that today are hand-copied per page with no shared stylesheet
  across the 7 non-`index.html` demo pages, and hand-copied per script file across three
  different JS "component" implementations.
- Own the "is this real or a demo" disclosure pattern — decide where it must appear and hold
  engineering to it, since the three most realistic-looking, most-likely-to-be-shared-standalone
  pages currently have none.
- Partner with Front-End on the checkout form/Stripe-teardown fix (Executive risk register item
  4) from a UX-acceptance-criteria angle — this is a UX regression as much as a code bug and
  should be owned jointly, not thrown over a wall.

### Immediate tasks
1. Execute the brand-color reconciliation once Product/UX Manager rules on direction: either
   finish the black/white rebrand across `backend.html`, `owner.html`, `staff.html`,
   `franchise.html`, `licensing.html`, `privacy.html`, `terms.html`, or formally document
   "internal/operator tools keep a distinct gold accent on purpose" — right now it reads as an
   abandoned migration (two prior commits, `8d58ac1`/`3154121`, touched only `demo.css` +
   `index.html` and stopped).
2. Kill the orphaned `#b5571f` / `var(--card, #fff)` leftover in `.kitchen-lock`/`.kl-ico` — a
   one-line token swap, but it's the one spot of color surviving in an otherwise-monochrome page
   and undermines the "we did a real rebrand" claim.
3. Add the skip-link pattern to `index.html`, `franchise.html`, `licensing.html`,
   `privacy.html`, `terms.html` — the exact working implementation already exists in
   `backend.html`/`owner.html`/`staff.html`; this is a copy-paste spec, not new design work, and
   should be a template requirement for any new demo page going forward.
4. Spec responsive behavior for the three legal/contract pages — at minimum, every `<table>`
   (13 in `licensing.html` alone) wrapped in a horizontally-scrollable container, plus one
   mobile breakpoint. A prospective franchisee opening a commercial-terms schedule on a phone
   today gets either a horizontally-scrolling whole page or unreadably squeezed legal text —
   this is the page most likely to be opened by someone making a real business decision.
5. Author one canonical `.btn` spec (geometry, radius, padding, font) and get it copy-pasted
   verbatim into every page's inline `<style>` block, closing the current three-way drift:
   `demo.css` 7px/13×26px vs. `franchise.html` 10px/13×22px vs. `staff.html` 11px/14px vs.
   `backend.html` abandoning the display-font CTA convention entirely.
6. Decide the disclosure requirement for `backend.html`/`owner.html`/`staff.html` (with
   Product/UX Manager sign-off) and get the copy into Front-End's queue — these are the most
   realistic-looking, most-likely-to-be-forwarded-standalone pages (pulsing live-indicator dots,
   persisted "signed in" state, real-looking KPIs) and currently carry zero "this is simulated"
   language, unlike `index.html`'s ribbon and `licensing.html`'s own "DRAFT" disclaimer.
7. Fix the two contrast failures: darken `.dbk-empty` (1.93:1 today, on the Kitchen Board's
   empty-lane placeholder — exactly the wrong element to make illegible under tablet glare) and
   delete the dead `.card .sub` rule so no future page inherits a silent AA failure.
8. Specify a consistent submit/disabled state for `placeOrder()` and catering `submit()`
   matching the pattern `menu-order.js` already implements correctly — cheap now, necessary the
   moment either flow gains a real async delay.
9. Sign off jointly with Front-End on the checkout-form/Stripe-teardown fix from a
   UX-acceptance-criteria angle: define exactly what should and shouldn't re-render on cart
   mutation (cart lines/totals: yes; in-progress name/email/phone/notes and the mounted card
   field: no) so Front-End has a design-owned spec to build against, not just a bug ticket.
10. Specify the "reconnecting"/stale-data visual state for `app.js`'s three silent pollers —
    right now a dropped connection shows nothing different from a healthy one on the Staff
    Console; that's a design gap (what should staff see/hear when the till loses its connection
    to the server?), not purely an engineering one.

### Deliverables
- A one-page design-token spec (colors, button geometry, focus/skip-link markup, disclosure
  copy/placement) that every demo page and wp-admin-adjacent surface is audited against — this
  becomes what PRs get checked against instead of each page reinventing its own system.
- Updated `demo.css` (or page-level `<style>` blocks, respecting the project's
  no-shared-stylesheet-across-pages reality for 7 of 8 pages) implementing items 1–8 above.
- An accessibility acceptance checklist (skip link, focus-visible, reduced-motion, aria-live,
  post-navigation focus move) attached to the Definition of Done for any new screen — the raw
  material already exists in this codebase (three different pages independently demonstrate it
  correctly), it just needs to be written down once as a requirement instead of tribal
  knowledge.
- A wireframe/annotated spec for the checkout form's partial-render behavior (item 9), handed to
  Front-End as acceptance criteria.

### Risks owned
- Shipping a "rebrand" that's actually only ever done on the single page prospects see first
  (`index.html`) while the pages most likely to be shared in isolation
  (`backend.html`/`staff.html`/`licensing.html`) stay on the old palette — a credibility risk in
  a sales/franchise-pitch context, not just a cosmetic one.
- A legal/contract page that's unreadable on mobile is a business-development risk, not a
  nice-to-have — if a franchise prospect can't parse the terms schedule on their phone, that's a
  lost or delayed deal.
- Under-scoping the "what re-renders on cart mutation" spec loosely enough that Front-End's fix
  doesn't actually solve the customer-facing symptom (losing a half-typed card number) — needs
  precise acceptance criteria, not just "make it not feel broken."

### Dependencies
- Front-End for actually implementing the checkout partial-render fix, the aria-live additions,
  the reduced-motion CSS guards, and the reconnect-state UI — this role specs the requirement,
  Front-End owns the DOM/render-tree changes.
- Product/UX Manager (and whoever is legally accountable) for sign-off on exact disclosure
  wording on backend/owner/staff — not a pure design call.
- Back-End/Architecture on the payment-form fix specifically — the "what re-renders" spec has to
  be compatible with how Stripe Elements' iframe lifecycle actually works, which this role alone
  can't finalize.

---

## Program RACI

**Roles (columns):** EM = Engineering Manager · PM = Product/UX Manager · Ops =
Delivery/Operations Manager · FE = Front-End Specialist · BE = Back-End Specialist · DA = Data
Analyst · QA = QA Engineer · DevOps = DevOps Engineer · UX = UI/UX Designer

| # | Workstream (source finding) | EM | PM | Ops | FE | BE | DA | QA | DevOps | UX |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | Verify & deploy the 3 already-shipped v2.12.3 fixes (cart token, settings-wipe, secret-echo) to staging → prod | A | I | R | I | C | I | R | R | I |
| 2 | Regenerate stale CLAUDE.md + reconcile readme.txt version/FAQ | C | C | R/A | I | I | I | I | C | I |
| 3 | Fix site-wide CORS bypass on activation (Executive risk register #3) | A | I | C | I | R | I | C | R | I |
| 4 | Checkout money-orphan: add webhook reconciliation + refund wiring for main checkout (Executive risk register #1) | A | C | C | I | R | C | C | I | I |
| 5 | Payment-intent skips business-rule gates; add rate limit to payment-intent/catering routes (Executive risk register #2) | A | I | C | I | R | I | R | I | I |
| 6 | Catering status endpoint reachable by kitchen role — fix to `verify_manage` | A | C | C | I | R | I | R | I | I |
| 7 | Checkout form/Stripe iframe destroyed on every cart mutation (Executive risk register #4) | A | C | C | R | C | I | R | I | C |
| 8 | Demo site: unify 3 brand color systems across 8 pages | I | A | R | R | I | I | I | I | R |
| 9 | Stand up PHPUnit test harness (0% coverage today; gates refactor work) | A | I | C | C | C | I | R | C | I |
| 10 | Split `rest-controller.php` god-class (2,699 lines) — deferred until row 9 exists | A | I | C | C | R | I | C | I | I |
| 11 | Fix non-atomic rate limiter / proxy-naive IP keying | C | I | C | I | R | I | C | R | I |
| 12 | Fix N+1 query on order board + admin Orders list | C | I | I | I | R | C | C | I | I |
| 13 | Add index on `balance_intent_id` + version-gated migration | C | I | I | I | R | C | I | I | I |
| 14 | Move blocking POSPal push/ClickSend SMS off request thread; log `wp_mail` failures | C | I | C | I | R | I | C | C | I |
| 15 | Stripe env-first secrets (`getenv`/constant pattern, mirroring POSPal) | C | I | I | I | R | I | I | C | I |
| 16 | REST `args`/`validate_callback` schemas for `/checkout`, `/voucher/issue` | C | I | I | I | R | R | R | I | I |
| 17 | Fix `currency_code` USD fallback bug + ISO whitelist | C | I | I | I | R | R | R | I | I |
| 18 | Build Reports/CSV-export admin page (revenue, AOV, top items) — blocked on row 4 | C | A | C | R | R | R | C | I | C |
| 19 | Register WP Privacy Tools exporters/erasers for order/catering/voucher PII (APP 11) | A | C | C | I | R | R | R | I | I |
| 20 | Refund action wired to `create_refund()` + `payment_status='refunded'` + voucher-revert interaction (overlaps row 4) | A | C | C | I | R | C | C | I | C |
| 21 | Low-severity data validation batch (voucher %>100 cap, catering date/guest bounds, locations slug dedup, undocumented settings keys) | I | I | I | I | R | R | C | I | I |
| 22 | Front-end a11y pass: aria-live on voucher-scan/toast, reduced-motion on order-board CSS, focus management on checkout confirmation | C | C | I | R | I | I | C | I | R |
| 23 | Polling discipline + DOM-helper/Voucher-Scan de-duplication across `app.js`/`doughboss-voucher-scan.js` | C | I | I | R | I | I | C | I | I |
| 24 | Gate diagnostic POSPal/Mercure routes (incl. `/pospal/probe-grant`) behind `WP_DEBUG` | C | I | C | I | R | I | C | C | I |
| 25 | Demo-site "simulated demo" disclosure on backend/owner/staff pages | I | A | C | R | I | I | I | I | R |
| 26 | Legal pages (`licensing/terms/privacy.html`) responsive tables + skip link on `index.html` | I | C | I | R | I | I | I | I | R |
| 27 | CI gate: GH Actions `php -l` required check + build-zip version/readme consistency check | C | I | R/A | I | I | I | C | R | I |
| 28 | Settings-defaults drift: reconcile activator seed array vs. `Settings::defaults()` (`delivery_fee` $5 vs $0) | C | I | I | I | R | C | C | I | I |
| 29 | Harden `0000-00-00` datetime defaults → `NULL DEFAULT NULL` + migration | C | I | I | I | R | I | C | C | I |
| 30 | Add `/status` health-check endpoint (DB + integration readiness) | C | I | C | I | R | I | I | R | I |
| 31 | Manual QA regression checklist execution (checkout/voucher/POSPal/Stripe test-mode) before each release | I | I | A | C | C | I | R | C | I |
| 32 | Full staging deploy + backup confirmation + production go-live | A | I | R | I | C | I | C | R | I |
| 33 | Document Staff Console (`app/`) hosting/deploy story — currently undocumented gap | C | C | R/A | R | I | I | I | C | I |

**Notes on reading this table**
- Rows 3–7 are the four High/Critical, cross-cutting items escalated for immediate scheduling
  regardless of originating lens — all four carry real money, security, or revenue risk and none
  is contained to one team (see Executive risk register above).
- Row 9 (test harness) is deliberately positioned **before** row 10 (god-class split) —
  Engineering Manager holds this ordering per Sequencing rule 1.
- No live-site verification is reflected anywhere in this table — every row is scoped to work
  against the repository and a staging deploy; production state of doughboss.com.au remains
  unverified in this environment.
