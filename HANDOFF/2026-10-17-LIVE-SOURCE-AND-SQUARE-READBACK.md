# 17 October 2026 - direct live source and Square readback

Read-only browser/hosting inspection against the canonical candidate. No application source, live configuration, tax rule, provider data, payment, order, subscription or Ads budget changed. No credentials or private bank details are retained here.

## Installed source is not the prepared 2.51.0 package

WordPress Installed Plugins identifies the active entry as `doughboss/doughboss.php`, Version **2.49.0**. A read-only hosting PHP command independently read the source header and hashed regular files without executing plugin code:

| Evidence | Live plugin | Prepared candidate ZIP |
| --- | --- | --- |
| Version | 2.49.0 | 2.51.0 |
| Regular files | 232 | 238 |
| Sorted path/hash manifest SHA-256 | `52892e0725e374e9ec14028b8b0f284891bccf486fe6194733087f4cf1a7a20e` | `9227bb70bc7c69d96c8ba766447bc17850c61d52a89a789f7996434838af5df7` |
| Main PHP SHA-256 | `d669ad44872cc864e90e7e3e7b1da728c722345965468f746b7dce3abadcf7d4` | `1a746b3544a71f720814cf48d22df4d4467a563d03f61249cc27a2844ec7f8dc` |
| Main JS SHA-256 | `6cfded01496d708d21f41f5228c9e8e25151b764853181b8a187684f80e420f5` | `a2ae9ed04c34ce989fa822837b3af6936225e7d21579c06793e76be5e16360a1` |
| Square JS SHA-256 | `63415dfa7a1af5bd80ac62e103cd30cf4387884d95a0a6eed27738f7f07e316b` | `8699d6b7cc9e4eac428c599a8e0fd8b2bd5348233d0ad39676d82b17953bf736` |

Manifest format: ordinal relative-path sort, each line `path lowercase-sha256`, LF separators plus final LF. Live values were read in the hosting terminal; candidate values were computed directly from `doughboss-2.51.0-9597e48-candidate.zip`. The live main PHP hash also matches the existing local 2.49 workspace main file. That is one-file agreement, not full-tree parity. This closes the version/header uncertainty but does not supply downloaded live source, full file-by-file reconciliation, a fresh production backup or rollback acceptance.

## Environment separation and Revesby configuration

The host has no `wp` command; no installation was attempted. A `SHORTINIT` bootstrap and one settings-row SELECT emitted only named non-secret fields, without loading site plugins/themes. Stored values observed:

- Gateway `square`; mode **test**; configured API version `2026-09-16`.
- Test location `L9TYDD95JCSCCM`; test map contains only Revesby with that ID.
- Live location `LHMBV6NDDD4BC`; live map contains only Revesby with that ID.
- `ordering_open=0`, `payments_enabled=0`.

Do not interpret configuration as successful merchant/token verification or payment acceptance. Candidate catalogue preparation still uses its reviewed API-version policy; do not silently change the live version to bypass admission checks.

## Square dashboard observations, no saves

The connected dashboard displays account name Doughboss. With Active locations selected, it currently lists:

| Label | Location ID | Observed city |
| --- | --- | --- |
| Doughboss | `LHMBV6NDDD4BC` | Revesby, 12/25 Selems Parade |
| Roselands | `L8RBWTH17GSA8` | Roselands |
| Roselands shop | `LRGWVH50NVR48` | Roselands |

These are observations only. Neither Roselands-labelled record was created, edited, removed or connected by this batch. The owner-approved separate-ABN/separate-account policy remains authoritative, and Bankstown/Roselands account work is deferred. The matching live Revesby location setting does not prove seller ownership or the sandbox merchant identity. Reconcile those identities before any financial action.

GST is Enabled at **10%**, and its editor has **Inclusive tax** selected. It applies to all current/future taxable items at selected locations, with All locations selected. The editor was closed without saving. This is general tax-calculation evidence, not approval of each item's taxable/GST-free classification; the 43-item review remains pending.

Machine-readable local observation: `C:/Codex/Output/doughboss-revesby-readback-20261017/live-source-and-square-binding-20261017.json`. This artifact intentionally excludes credentials, raw option values outside the whitelist, customer/staff records and bank details. Browser session URLs are not retained. Full release, restore, Square financial/refund/reporting, staff and physical KDS gates remain open.
