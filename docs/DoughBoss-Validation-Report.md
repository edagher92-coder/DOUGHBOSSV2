# DoughBoss V2 — Validation Report (slices 1–7)

Proof that the restored platform **boots and works**, domain by domain. Reproduce with:

```bash
php tests/smoke-boot.php        # boots the plugin against a WP-stub kernel
bash scripts/dev-check.sh --strict   # PHP + JS syntax
bash build-zip.sh               # installable dist/doughboss.zip
```

All three are wired into CI (`.github/workflows/plugin-ci.yml`).

## Results (full platform, plugin 2.15.0)

| Check | Result |
|---|---|
| Boot smoke (`tests/smoke-boot.php`) | **44 passed · 0 failed** — plugin boots, no fatal |
| REST routes registered at boot | **41 routes** (incl. `/config`, `/menu`, `/checkout`) |
| Strict verifier | **PASS** — PHP 35/35, JS 10/10 |
| Installable zip | builds; contains `doughboss/doughboss.php` + `includes/class-doughboss.php` |

The smoke test loads the plugin against a minimal WordPress kernel stub
(`tests/wp-stubs.php`), fires the real WP lifecycle hooks (`plugins_loaded` → `init`
→ `rest_api_init`), and asserts each domain wires up. It is **not** a substitute for a
live-WordPress staging smoke test of the money path (still required per
`RELEASE_CHECKLIST.md`), but it proves the code actually boots and registers — well
beyond syntax.

## Per-slice matrix

| # | Slice | Classes verified present + booting | Extra validation |
|---|---|---|---|
| 1 | Core plugin | `DoughBoss`, `_Activator`, `_Settings`, `_Post_Types`, `_Cart`, `_Order`, `_REST_Controller`, `_Shortcodes`, `_Assets`, `_Migrations` | 4 shortcodes registered; `doughboss_item` CPT registered; `Cart::totals()` present |
| 2 | Stripe | `DoughBoss_Stripe` | `::ready()` **dormant** with no key (security gate) |
| 3 | Vouchers | `DoughBoss_Voucher`, `DoughBoss_Coupon_Code` | `Coupon_Code::validate()` + `::normalize()` present |
| 4 | POSPal | `DoughBoss_POSPal`, `_POSPal_Sync`, `_POSPal_Orders` | `POSPal::ready()` **dormant** with no config |
| 5 | Catering | `DoughBoss_Catering`, `DoughBoss_Catering_Package` | Catering CPT init runs at boot |
| 6 | Notifications / real-time | `DoughBoss_Mercure`, `_Ntfy`, `_SMS`, `_Printer` | all init at boot; SMS `ready()` **dormant** |
| 7 | Locations / reports / privacy / seeder / CLI | `DoughBoss_Locations`, `_Reports`, `_Privacy`, `_Menu_Seeder`, `_CLI` | Privacy init runs at boot |

Every optional integration is **dormant by default** — it does nothing until its key is
set (see `docs/DoughBoss-Secrets-and-Config.md`). That is the intended, safe shipping state.

## What this does *not* prove (still owner/staging work)

- Live money-path behaviour (real Stripe PaymentIntent, atomic voucher redeem, GST branches)
  against a running WordPress + DB — the `RELEASE_CHECKLIST.md` staging smoke test.
- That any live service is actually connected — that needs the real keys
  (`docs/DoughBoss-Secrets-and-Config.md`), which are owner-set and never committed.
