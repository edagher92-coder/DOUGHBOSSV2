# Premium website rollout and Square launch gates

Date: 19 October 2026, Australia/Sydney. Owner: Sol integration/release lead.

## Authority and frozen source

The owner approved committing the finished work, preparing the final rollout and then publishing accepted website batches directly to live. This supersedes the earlier publication hold for this exact presentation batch only. It does not permit unverified installation, an unreviewed backend migration, accepting orders, payments, Ads spending, other-shop onboarding or KDS activation.

Accepted source is based on `09984b91a6abaa24e272256781a0460ebf15a470` on `codex/2.51-handoff-20261016`. The final local candidate handoff records the frozen hashes, exact commands, synthetic browser evidence and independent Sol 6.1 xhigh finding/repair. There have been no runtime edits since that accepted freeze. Reuse those checks; do not claim they were repeated during rollout preparation.

- Node: 256 passed; PHP 8.2: 4,553 assertions; PHP 7.4: 4,361 assertions; isolated fixture: nine passed.
- Captured-public catalogue comparison: 43 items / 134 groups / 760 choices, zero mismatches. This is not a fresh Square price/tax readback.
- Runtime ZIP: `C:/Codex/Output/doughboss-customiser-checkout-20261019/doughboss-2.51.0-premium-checkout-ready-r4.zip`.
- 386 runtime files, 15,998,112 bytes; SHA-256 `E1195E99315A3EA4C569A2D0561DCF236742647E269CABE1937DC61509E6D907`.
- Full ZIP contains the entire 2.51.0 plugin, not only this batch's 50 changed runtime paths. No theme, tests, fixture, handoff or model receipt is included.

Commit only the 13 accepted modified source/support paths, four approved generated originals, their 40 responsive variants and the exact accepted handoff/provenance documents. Preserve unrelated/protected untracked handoffs. Do not stage an entire directory.

## Partner and review evidence

Qwen `qwen3.8-27b:code` drafted the rollout checklist through the local connector with `check_with_claude=false`. Receipt: 707 prompt / 1,425 completion tokens, 43,484 ms total, first content 4,031 ms, native generation 36.10843 tokens/sec, 40 progress updates. These are one call's metrics, not a sustained benchmark or savings estimate. The draft was not accepted verbatim: installed parity gates deployment, not source push; backup is required for the chosen plugin change; sandbox testing is a separate controlled gate, not forbidden until after launch; membership and other shops are feature-specific gates, not prerequisites for Revesby-only ordering.

Fresh read-only GPT-6.1 Sol xhigh rollout disposition: **SHIP_EXPLICIT_SOURCE_COMMIT; HOLD_WORDPRESS_REPLACEMENT_PENDING_PREFLIGHT; HOLD_COMMERCE**. Frozen source and ZIP hashes independently matched. Astra was not needed for an unresolved decision. Source functional acceptance remains **SHIP_LOCAL_SOURCE_ONLY** until installed and operational evidence exists.

## Authenticated live preflight

Authenticated WordPress Plugins inventory shows active `doughboss/doughboss.php` at **2.49.0**, not 2.51.0. Old inactive DoughBoss copies and historical one-use updater/migration helpers remain untouched. Never delete old duplicate plugins: historical uninstall code can affect live data.

Public `/wp-json/doughboss/v1/config` readback before publication:

```json
{"ordering_open":false,"payments_enabled":false,"single_location_mode":false,"single_location_id":0,"staff_approval_required":true,"payment_gateway":"square","after_hours_preorders_enabled":false}
```

`square_hosted_checkout` was absent/null: unavailable, not evidence of false. No integration settings have been saved.

UpdraftPlus 1.26.7 is active. Its UI reported no configured remote storage, so the approved pre-release backup stays on the current host. A new full files/database backup was started at the displayed host time **19 October 15:59**, protected from automatic retention deletion. At this checkpoint it is still creating plugin ZIPs; do not call it completed/retrievable or restored until independently read back. Existing older backup rows alone are not sufficient proof.

### Publication stop conditions and sequence

1. Finish/retrieve the current backup, identify the actual installed plugin bytes, theme and active overlays, and reconcile the 2.49.0 versus 2.51.0 base. A version label does not prove file parity. Do not overwrite unexpected live-only changes.
2. Prove a supported authorised host transport and rollback mechanism. Recheck historical browser upload limits rather than assuming the 16 MB ZIP fits. Do not create or activate a legacy uploader/helper as a workaround.
3. Choose either a fully reconciled full-runtime upgrade or a verified compatible narrow patch. This owner approval does not silently clear backend/schema migration or data-loss risks.
4. One release owner, one exact manifest, one reversible publication. Preserve the existing homepage film and independently managed franchise pause. No theme replacement, database import or integration-switch change.
5. After installation verify installed hashes, anonymously served assets/cache state, homepage/menu/order/members navigation, mobile customisation and no console/network regressions. Re-read config: **ordering_open=false AND payments_enabled=false** must remain true assertions about the disabled state.

No WordPress replacement has happened at this checkpoint. A Git push is source delivery, not live publication.

## Git remote batch

Origin is `https://github.com/edagher92-coder/DOUGHBOSSV2.git`. Read-only `gh pr list` for this exact branch returned no open PR. Checked-in plugin CI uses `pull_request`/`workflow_dispatch`, not direct push; Pages pushes only `codex/visual-preview-20260908`. No active non-sample local Git hooks were found. External host/repository hooks remain unverified; do not claim no automation exists anywhere.

Remote branch readback was `93acde47370cd9a033136ecdfaafe9b0e5003759`, a confirmed ancestor of local HEAD. One ordinary fast-forward push may deliver the accepted existing five unpushed commits plus this consolidated finished source commit. No force push, new PR, manual CI dispatch or merge is needed. Record the actual result separately after execution.

## What still needs validating before Square commerce

Local source/mock acceptance is not Square account configuration, financial correctness, physical fulfilment or production acceptance. Keep payment and ordering switches off throughout website publication.

| Gate | Required acceptance receipt | Current gap |
| --- | --- | --- |
| Revesby seller identity | Owner-approved legal seller/ABN -> Square merchant -> exact website location -> Square location, correct environment/app/scopes and credential status; identify OAuth versus retained legacy path, never record secrets | Earlier dashboard showed Revesby location `LHMBV6NDDD4BC`; website credential-to-seller binding is not proven |
| Seller isolation | No fallback to another seller, no cross-account dispatch/event attribution; other shops unconnected/unapproved for the pilot | Separate-account source exists; current provider isolation remains unproven |
| Catalogue and GST | Fresh selected-seller variations/modifiers/availability/discounts and GST-inclusive cents/tax mapping, preserving current prices | 43 names and a captured website options replay are not a current full Square financial readback |
| Routing boundaries | A$299.99 / A$300.00 after discounts, including GST, before delivery/service fees; reject wrong/unavailable store and preserve retries | Local rule requires active Revesby selection for >=A$300; it does not silently move an ordinary order. Catering is Revesby-only |
| Approval and pickup | Staff chooses customer-confirmed slot; website promised ASAP time sent exactly; future preparation snapshot stable; payment link expires with reservation | Source/local tests exist, staffed provider journey outstanding |
| Controlled sandbox checkout | Actual selected hosted/embedded path, offered card/3DS/wallet routes, success/decline/cancel, stale cart, lost response, replay, expiry and amount/location mismatch; one payment/order effect | Synthetic fixture and source guards are not this provider receipt. Separate sandbox action approval remains required |
| Webhooks and recovery | Correct app signing and exact endpoint; forged/stale/duplicate/out-of-order/unknown-seller events; reliable retry and order reconciliation | Local test doubles do not prove real signed provider delivery |
| Refunds and accounting | Full/partial/pending/completed refunds, amount/currency/tax/location/reference matching; local attempt -> Square Order -> Payment -> native order reconciliation | No operational acceptance claimed |
| Kitchen and staffed operations | Exactly one dispatch/ticket, correct store and pickup promise, staff access, actual device receipt, end-to-end staff acceptance | KDS screens not installed; physical delivery/cutover remains gated |
| Membership, only if advertised/activated | Revesby hosted link ownership, customer SMS login/enrolment, approved terms/consent and actual reward behaviour; seller-specific voucher/redemption mapping if offered | Safe hosted shortcode remains disabled by default; no completed rewards/login bridge claim |

Owner-supplied public membership URL: `https://app.squareup.com/loyalty/MLK5K6N8G157N`. Earlier Square UI labelled it Doughboss customer loyalty status/enrolment; no SMS/customer write was performed.

**Bankstown and Roselands are not required for a Revesby-only pilot** while hard-disabled. Each will need its own ABN/seller/account validation later. Membership/voucher features may remain unavailable rather than blocking basic ordering, provided the site does not advertise them as operational. Ads remains non-spending.

Do not turn any readiness switch on to make a website presentation release pass. After the website is safely published, the next bounded work is read-only Revesby identity/catalogue verification followed by separately approved sandbox and staffed operational acceptance, not blanket live-payment activation.
