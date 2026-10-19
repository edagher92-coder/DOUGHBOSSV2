# Revesby readiness — live readback, 17 October 2026

Base source: `5c8ece91ac53169109cd5cfa77658071108a6f22` on
`codex/2.51-handoff-20261016`. This is evidence of read-only inspection, not
an import, account migration, tax approval, payment activation or KDS pass.
Bankstown/Roselands account connections remain deferred to the weekend.

## Catalogue transfer: directly observed matches

Square Dashboard's Item Library initially rendered 34 rows. After scrolling,
all 43 rows were observed; the initial nine apparently absent products were
not missing imports. A native **All items / CSV** export independently has
43 rows. Compared with the public website menu API:

| Check | Result | Scope |
| --- | --- | --- |
| Unique item names | 43/43 matched | Case-sensitive, one exact row per website name |
| Base AUD prices | 43/43 matched | Single displayed price, not modifier/fee/tax allocations |
| Reporting categories | 43/43 matched | Does not prove printer routing |
| SKU to WordPress item ID | 43/43 matched | Export SKU `DB-WP-{website id}` |
| Descriptions | 43/43 matched after markup/entity/whitespace normalization | Not byte equality or independent ingredient verification |
| Enabled modifier group label sets | 43/43 matched | Only observed exported `Style - {id}` headers normalized to `Style`; choice IDs/prices/defaults/min/max remain unverified |

Evidence root (local only):
`C:/Codex/Output/doughboss-revesby-readback-20261017`.
Files: `square-rendered-catalogue-all-observed.json`,
`website-menu-observation.json`, `website-menu-options-observation.json`,
`catalogue-comparison.json`, `catalogue-option-group-comparison.json`,
`catalogue-description-comparison.json`, `square-catalogue-before.png`.

Native export: `square-catalogue-readonly-export.csv`, SHA-256
`5B4A8FEB580CCACE762DCF4C621900F5DE8241566F86ABD29F60F8D5B82BDDED`.
The original browser download was preserved. **Do not commit the raw export**:
its schema includes catalogue tokens/object identifiers and internal inventory,
supplier and cost columns. No raw export was sent to a model or another
provider; automated local comparison emitted only necessary catalogue facts.

## Observed settings and remaining checks

- All 43 observed rows say **All locations / Available**. CSV enables every
  item for `Doughboss`, `Roselands` and `Roselands shop`. This is not proof of
  separate legal sellers, correct stock, or approved availability.
- All 43 CSV rows have Square Online visibility `unavailable`; SEO title and
  description are blank. Shipping is `Y`; pickup/delivery/QR and sellable fields
  are blank. Preserve these literal observations; do not infer the semantics
  of a blank export cell or silently enable channels.
- No product `img[src]` elements were present in the 43 rendered table rows;
  the Aged Cheese editor explicitly offered adding an image. The website has
  image references for 43 items. This does not establish that every Square
  object lacks an image, or that website images accurately depict every item.
- Aged Cheese, SKU `DB-WP-357`, has a displayed **GST (10%)** tax label and the
  expected Lemon & chilli / Sesame seeds / Sauce on top groups. This is one
  item observation, not 43-item tax approval or inclusive-price verification.
  The CSV does not include item-tax assignment columns.
- The sampled editor was closed **without saving**. No item, tax, image,
  modifier, availability, financial or account setting was changed.

## Seller/location boundary

Current Square active-location view shows `Doughboss` at 12/25 Selems Parade,
Revesby (`LHMBV6NDDD4BC`), plus two Roselands-labelled locations. No Bankstown
row was present in that active view. These labels do not prove merchant/legal
identity, and must not replace the owner's later explicit separate-ABN,
separate-account requirement. Do not delete or merge existing locations,
reroute settlement, or treat this account's Roselands rows as approved weekend
connections. Verify actual OAuth merchant/application/environment/location
binding before provider writes or activation.

Fresh public WordPress readback remains `ordering_open=false`,
`payments_enabled=false`, `payment_gateway=square`, approval required. Its
public location list includes all three website shops with pickup enabled;
this is not per-shop Square connection or online-sale acceptance.

## Confirmed KDS source gap and implementation boundary

Current `class-doughboss-square-orders.php` adds only `ticket_name` and pickup
metadata. Its native response validator rejects non-empty fulfillments.
Tests intentionally preserve that v1 informational behavior. **There is no
implemented physical PICKUP/KDS fulfillment contract in this candidate.**

The owner approved local PICKUP/strict verification/replay work and chose the
website's promised time: both UI `asap` and scheduled selections must project
Square `SCHEDULED` with the exact immutable `pickup_ready_from_utc` promise.
Do not let Square recalculate another ASAP time.

Source-backed seams for the next implementation:

- Immutable snapshot: `payload.order` already carries name/phone, native shop,
  pickup mode, ready-from/by UTC and timezone when pickup selection is live.
- `orders_protocol` and `orders_snapshot_hash` are creation-only attempt
  metadata. New pickup semantics need a distinct contract version; historical
  protocol-absent and `square-orders-v1` attempts must retain their original
  projection/recovery bytes. Existing mutable status updates are not authority
  to add a marker retrospectively.
- Native card, native full-value gift and approved Square payments share the
  projection/validation path. Hosted Payment Links currently admit Square's
  DIGITAL fulfillment, and split remains inert. Do not silently retrofit those
  paths into PICKUP or enable split.
- Missing/changed/extra fulfillment, pickup promise, recipient or shop facts
  must block payment/release; payment, approval, currency/amount, ownership,
  replay and duplicate-kitchen guards remain mandatory.

Official documentation inspected through Context7:

- `https://developer.squareup.com/docs/orders-api/fulfillments`: PICKUP uses
  recipient display name and pickup time; `SCHEDULED` requires `pickup_at`.
  Prep duration controls when scheduled orders become Active/KDS-visible;
  absent duration means immediately active. It must not silently be recomputed
  from mutable current settings.
- `https://developer.squareup.com/docs/orders-api/pay-for-orders`: a single
  full-value `CreatePayment` can reference the Order; `PayOrder` is required
  for multiple authorized payments. This does not authorize split activation.

The application remains pinned to Square API `2025-01-23`; current examples
show a later API version. Do not upgrade the pin merely to match an example.
Exact pinned-version field limits/normalization, Payment Link compatibility,
and paid KDS visibility still require confirmation and sandbox readback.
No KDS API call or local KDS implementation occurred in this read-only investigation.
Physical KDS screens remain uninstalled per the owner's latest report.

## Release gates

### Offline restore prerequisite progress (17 October)

The independent reviews stopped R6/R7 before any launch: their edit-echo/terminal-closeout and startup-timing defects were repaired in new packets, with old files/evidence preserved. R8's separately admitted observation reached the real Syslinux menu but stopped on an unsupported menu colour sequence (`VT_UNSUPPORTED`). Its one attempt is terminal and retained; it was not blindly retried. Both image checks passed, no owned process/listener remained, and root independently verified all 34 source/base/tool pins afterward.

R9 changed only post-Space observation to opaque raw capture, preserving the proven fresh-countdown/one-Space authorization and mandatory closeout. Root and a fresh independent Sol review each passed `python -B -m unittest -q test_public_linux_r9_driver`: **105 tests, OK**. Local Qwen supplied one independent opaque-byte vector as advisory input; real observer tests, not Qwen's mock skeleton, establish the result.

One separately reviewed/root-admitted R9 operation then completed with state **RAW_MENU_OBSERVATION_ONLY_NOT_SCREEN_OR_BOOT_ACCEPTANCE**: 1,952 raw serial bytes, including 1,760 post-Space bytes (post-Space SHA-256 `b17a293757f9c29ecefccefb6176db4f0d77c4199bc9ec6ad7dc42168093a671`). The exact runtime result records `decoderStatus=NOT_DECODED_RAW_CAPTURE_ONLY`, `frozenInputsAfter=34`, an empty terminal-failure list, no remaining owned process/listener, successful explicit log close/fsync, and both image checks at exit zero.

Evidence root: `C:/Codex/Temp/doughboss-linux-restore-20261014-9bd2e853`. Frozen packet: `NATIVE-PUBLIC-LINUX-R9-DRIVER-FREEZE.json`, SHA-256 `ec9e02928583e1c06b699822ffd71636436423bfb9d2fd9c871615fe99bc58c3`; driver SHA-256 `9ee6baff9e4c7d4d5da9ea3956872cda3e0ce26e2c0486102dc7c2f8663cd640`. Runtime files: `NATIVE-ADMISSION-PUBLIC-LINUX-R9-OBSERVE-MENU-RAW-RESULT.json` and `native-admission-public-linux-r9-observe-menu-raw-serial.log`. No private backup, database, WordPress or provider was accessed in these public-only operations.

The raw trace now contains the complete real menu, including its border, palette/charset controls, actual labels and final erase/cursor controls. Repair and test decoding offline against that fixed trace; do not guess two unadorned menu lines or spend one VM attempt per escape sequence. A separately frozen/reviewed next operation is still required. **Kernel/userland boot, the 81 public guest tests/nine I/O checks, private database/WordPress restore and production release remain unaccepted.** No production code/settings, Square catalogue/payment state, account identity or Ads budget changed in this batch.

### Follow-up: exact modifier choices and defaults (17 October)

Read-only inspection of all ten existing Square modifier-set editors found **48/48 choice labels, AUD prices and preselect flags matching** the ten distinct option definitions returned by the public website. All ten group default rules also match the website's radio/check definitions (required single choice versus optional multiple choices). No editor was saved.

The minimized saved Square group data was independently checked against the browser's structured readback: canonical SHA-256 `61aa1f94e70c2a967122481175537ac51d828717744df8af2f102c62e1c9d3a9` matched exactly. The native CSV's two distinct Style objects were resolved to the visible Flat-default and Folded-default/+0.50 editors; **43/43 exact enabled modifier-set assignments match**, including those two Style variants. This supersedes the earlier label-only group check, not the remaining item-specific override checks.

Local evidence: `website-option-choices-observation.json`, `square-option-choices-observation.json`, `catalogue-option-choices-comparison.json`, and `catalogue-modifier-assignment-exact-comparison.json` in the evidence root above. Raw Square export stays local and uncommitted.

Still unverified: per-item modifier minimum/maximum overrides, enforced cart/checkout behavior, location-specific modifier/variation price overrides, per-item tax treatment, actual photos and seller binding. Matching the group defaults is not checkout or tax acceptance.

### Previously accepted local gate and remaining release boundary

Local-model boundary: two earlier release-readiness synthetic Qwen requests timed out without text. A later read-only governor/runtime check found a lower current 32K context and resource-floor refits; that does not establish the timeout cause. In the subsequent, separately bounded R9 public-terminal-fixture batch, the dedicated local connector returned two synthetic drafts successfully (model `qwen3.8-27b:code`, 6,123 ms and 16,341 ms). Codex checked the advice, corrected a terminology/line-art inaccuracy and used only opaque-byte test inputs, not its self-mocked test skeleton as validation evidence. This establishes bounded advisory responsiveness, not autonomous coding qualification or measured credit savings. No cloud check/fallback, guard weakening, process termination, local-model configuration change, credential/provider access or release authority was delegated. Implementation and acceptance remain with Codex and the independent review lane.

Read-only tax follow-up: Square's existing **GST 10%** is enabled at **All locations**, applies to **All current and future taxable items at selected locations**, shows **All items**, includes custom amounts, and uses **Inclusive tax** (not additive). No exemption rule is configured. The editor was closed without saving. Evidence: `square-tax-configuration-observation.json` locally. This confirms inclusive calculation configuration only; it does not approve the legal GST classification of every food/drink or prove all variation/location overrides. Owner's per-item-tax-before-selling gate remains.

The complete local WordPress/database gate is now green (3,125/3,125 plus
concurrency/recovery), as recorded in the local-first handoff. Restore boot
and actual core/preferences compatibility remain release blockers. Preserve
the existing live site, off-state commerce, no Ads spend and separate-account
boundaries while the offline public prerequisite is repaired. Catalogue name/
price/description matches do not clear tax, options, photos, reporting/refund,
staff/timecard, loyalty/gift, actual checkout or physical KDS acceptance.
