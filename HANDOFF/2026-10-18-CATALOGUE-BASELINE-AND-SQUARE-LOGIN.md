# Current website catalogue baseline and Square access

18 October 2026, Australia/Sydney. Source checkout `278693700cd6f536018dda2ddc5c817f6d650ae3`; no application source change in this batch.

## Current browser access

Direct navigation in the connected Chrome session to Square's existing item library redirected to `/login` and displayed the sign-in form. No business context, item library, account identity, tax setting or provider response was inspected in that session. Credentials were not read or entered. The owner sign-in handoff is open in **🍕 Revesby read-only**; the current request asks the owner to authenticate to Revesby only. Do not substitute another shop or infer that the website's test credentials belong to a signed-in production Dashboard account.

## Fresh public website comparison

Anonymous GET of `/wp-json/doughboss/v1/menu` at **2026-10-18T01:50:45.774Z** returned 84,946 bytes. The bounded read-only comparison schema-checks item IDs/names/prices/categories/availability, option types/labels/choices/defaults and image URL origins before projecting and comparing data. It enforces a 2 MiB response ceiling, 15-second timeout, no redirect, at most 200 items and finite non-negative two-decimal prices. No private fields or customer information is retained.

- **43/43** current items match the explicitly dated 17 October website baseline for ID, name, base price, category, public availability flag and option-group ID/label/type/choice count.
- **10 option signatures**, including the two different Style configurations, contain **48 choice entries** in total. Full choice labels/prices/defaults and item assignments match the prior website choice capture. These are entries across signatures, not a claim of 48 globally unique modifier IDs.
- No changed current item IDs or missing prior IDs were observed in those projected fields. Description text, image content, tax allocations, location-specific availability and provider IDs were not part of that equality claim.
- All 43 records have image URLs. This is **not** proof of 43 real photographs: branded fallback tiles also have URLs, and no visual/image-HTTP/decode check occurred here.

Root initially used ambiguous image/choice count labels in the ephemeral result, then clarified the saved labels to `itemsWithImageUrl` and `choiceEntriesAcrossSignatures`; the underlying comparisons/counts were unchanged. The first PowerShell schema inspection also wrapped the REST array as one pipeline object; direct item inspection and the actual `Array.isArray`-validated Node comparison correctly counted all 43. Neither tool-level issue was treated as a website defect.

Machine-readable evidence: `artifacts/public-menu-baseline-comparison-20261018-r1.json`. Public response SHA-256 and exact dated baseline-file hashes are included. Reproduction script and local receipt: `C:/Codex/Output/doughboss-revesby-readback-20261018-r1/refresh-public-menu.cjs` and `public-menu-baseline-comparison.json`. The command uses only installed Node built-ins and a public anonymous GET; no dependency, daemon or production code was added. The projection algorithm completed exit 0; the saved count-label clarifications did not change the request or comparisons.

## Historical provider evidence — preserve its date

`C:/Codex/Output/doughboss-revesby-readback-20261017/catalogue-option-choices-comparison.json` records a **17 October 01:20 UTC** comparison of 10 signatures/48 choice entries with `allChoicesMatch=true` and `allDefaultRulesMatch=true`. Therefore the earlier CSV-only limitation that choice prices/defaults were not verified is superseded by that later observation **for its dated scope**. It does not establish current Square state, website-to-provider credential identity or per-item override enforcement. Current website equality to the older website capture does not eliminate possible provider-side drift.

Do not re-run already accepted historical comparisons merely to reproduce a pass. After authentication, refresh the current seller/environment/location context, then compare current provider choices/assignments/availability and outstanding item-specific override/min/max/tax evidence against this baseline. Retain every explicit financial/provider-write gate; no Square API read-control retry or provider mutation occurred in this batch.

## Remaining gates

The R18 local native/package acceptance remains intact because no runtime bytes changed. Private diagnostic protocol approval, private-backup restore/boot, fresh production backup/rollback and installed whole-tree reconciliation are still open. Square authentication is required for further Dashboard inspection; real provider/financial/member/staff/KDS acceptance and deferred-shop account separation remain unchanged. This report does not authorize an import, deployment, payment, enrolment, subscription or account connection.

## Independent evidence gate

Fresh explicit **GPT-6.1 Sol / high** review returned **SHIP_BOUNDED_EVIDENCE_ONLY**. It verified the baseline hashes, historical item/signature/choice counts, dated provider comparison and row-level/aggregate match flags, script bounds/logic and Node syntax. It confirmed that the repository and local receipts have equal parsed values; serialization order differs. It did not re-request the public menu or independently re-derive the current response digest/comparison from a separately preserved body. Those live fields remain root-recorded observations. No provider or release acceptance is implied.

The exact inspected audit script is retained as `artifacts/refresh-public-menu-r1.cjs`; root checked its byte hash against the local inspected copy. It requires the two explicitly referenced dated public baseline files and should only be run when a new readback is needed, not repeatedly to reproduce a pass.
