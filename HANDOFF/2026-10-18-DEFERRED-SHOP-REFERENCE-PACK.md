# Deferred-shop catalogue reference pack — 18 October 2026

Canonical handoff baseline `2cdc65e148f5b1de8ab7120324721e6734d0260d`; runtime source `9f37ccf77670b0175049e02c9896f28912f7488c`. No runtime code changed.

Outcome: preserve public menu values and the deferred shops' tax/setup/validation/rollback worksheets. Non-goals: no account connection, credentials, provider read/write, direct import, activation, payment or deployment. Files: one five-member ZIP, this handoff and current-reference notes. Proof: schema/record checks, manifest/complete archive verification and a bounded Sol 6.1 review.

Archive: `artifacts/deferred-shop-reference-20261018-r1.zip`, **12,015 bytes**, SHA-256 `72ca9b57ff5cca04b9b3060e618598c3bc65c9e6138c5e5814b54c9cbabca3bd`. Manifest SHA-256: `86f37c7a8c7bb07a2c5d4a5231068e8ea153366d35efba4b37de8ebfa3a99b68`. Readable originals remain at `C:/Codex/Output/doughboss-deferred-shop-reference-20261018-r1/`.

## Exact contents

- `catalogue-public.json`: timestamped 43 public menu records, with descriptions, base AUD prices, categories, availability/dietary text, options and image references.
- `item-tax-review.csv`: 43 ID/name/price-matched rows; all tax treatments **UNREVIEWED**, approver/date blank, both destination validations **NOT_ACCEPTED**.
- `SHOP-SETUP-AND-ROLLBACK.md`: separate seller/environment/location, tax/options/availability, membership/staff/device and rollback worksheet, with no credentials or merchant bindings.
- `README.md`: provenance, exclusions, existing two-phase/add-missing copy workflow and activation restrictions.
- `manifest.json`: four other members' sizes/SHA-256 values, source/package references, 43 items, 10 option signatures and 48 choice entries, with activation/direct-import/destination-connection flags false.

## Actual generation and verification

Existing Node built-ins only. `C:/Codex/Output/build-deferred-shop-reference-20261018-r1.cjs` passed `node --check` and its one export invocation exited 0. It requires a fresh direct output child directory and uses exclusive writes, never overwriting prior evidence. The public GET has a 2 MiB/15-second/no-redirect limit. Before writing, it validates all 11 item fields, unique IDs/names, bounded strings, cent-precision finite prices, booleans, option/choice/default structure, dietary-array shape and same-origin HTTPS image/srcset references; formula-leading CSV names are rejected.

The export required and matched the already observed website-response digest `d6ef7a800365810483fc0fb4153e3a4649949e3a99b7e7390036302f5c93c431`. Preserved JSON contains validated values, not the exact HTTP serialization; that response digest remains root-observed evidence. Root independently verified **43/43** tax-sheet IDs/names/prices, four manifest member hashes/sizes, exactly five files, zero approved tax rows and all blocked activation/connection/import flags. ZIP readback required the exact inventory and verified **5/5 member sizes/SHA-256 hashes**. No database/configuration/passwords, setup/private logs, provider tokens, customer/staff data or executable binary is included.

## Limits and continuation

This is a reference/checklist pack, **not** a Square export or directly importable API payload. The existing copier still needs a separately verified connected Revesby Square source, destination preview/approval and post-apply readback. It is add-missing only, two-phase/possibly partial and excludes images, inventory, item options, per-location price overrides, custom attributes and tax objects. Never delete destination objects automatically as rollback.

Image URLs can be fallback tiles; media binaries/live-media parity are not included. Copied descriptions/dietary text are not new ingredient/allergen certification. Public availability is not destination inventory. Preserve displayed prices without adding GST on top; per-item classification and AUD300 routing amount basis remain unapproved/unresolved. Never copy Revesby tokens, merchant IDs, staff/payroll/device assignments or assume cross-seller loyalty/gift cards.

Each deferred shop still needs its own legal seller/account/environment/location, permissions, tax/catalogue/media/device and financial acceptance. Native R18/package acceptance remains intact; private-backup restore/boot, fresh production backup/rollback, installed whole-tree parity, publication and real Revesby provider/member/staff/KDS acceptance remain open. The exact Square handoff tab was rechecked and remains on sign-in; owner authentication and the earlier private diagnostic approval are pending. No provider control or private operation was retried.

Fresh explicit **GPT-6.1 Sol / high** review returned **SHIP_REFERENCE_PACK_ONLY**, with no blocking defect. It independently verified archive/manifest hashes, the exact five-member inventory, all member bytes, 43 unique item records/11 fields each, 10 option signatures/48 choice entries and all 43 tax-sheet rows/blocked approval flags. It inspected the isolation/exclusion/rollback wording offline; it did not reproduce the public response or run the generator. Do not call either deferred shop connected, configured, replicated or operationally accepted from this archive.
