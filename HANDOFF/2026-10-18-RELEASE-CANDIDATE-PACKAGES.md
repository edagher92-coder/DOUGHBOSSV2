# 18 October 2026 — current upload candidate packages

Canonical source: `codex/2.51-handoff-20261016`, commit `d1ef2375020a832cb552ecce424f988cb40e9bd1`, directly verified on GitHub after the previous batch's consolidated push.

Fresh output directory: `C:/Codex/Output/doughboss-release-candidate-20261018-d1ef237/`. Earlier archives were not overwritten.

| Artifact | Version / entries | SHA-256 |
| --- | --- | --- |
| `doughboss-2.51.0-d1ef237.zip` | Plugin 2.51.0 / 239 files | `6269c1714f2cbdd6d7c59000eafee20cbe32b790dbb6505b84c1d1990d8e1d16` |
| `doughboss-final-d1ef237.zip` | Theme 1.8.0 / 27 files | `2d9c932193afff24227aac417ff39224e510bbc933bd6c38a7e33dd02ac26454` |

Built and validated with the existing non-overwriting scripts under portable PHP 8.2, `-n`, explicitly loading its existing `ext/php_zip.dll`:

```text
php scripts/build-zip.php <new-plugin-output-path>
php scripts/build-theme-zip.php <new-theme-output-path>
php scripts/validate-zip.php <plugin-output-path>
php scripts/validate-theme-zip.php <theme-output-path>
```

All four commands exited 0. Both validators confirmed archive layout and exact current-tree bytes. The package versions and file counts above came from those actual validators.

The complete frontend release gate also passed on this source: `node --test` over all five `tests/*.test.js` files reported **228 tests, 228 passed, 0 failed, 0 cancelled, 0 skipped**. `node --check` passed for all **24 JavaScript files** discovered under `public/`, `themes/`, `admin/`, `tests/` and `scripts/`. This includes pickup, board and navigation checks beyond the earlier 170-test focused bootstrap/frontend gate. No new runtime code changed during packaging.

Compared with accepted database-source checkpoint `1b7389ab4779c9466040bdf434e454abfcc39310`, `git diff --name-only` reports no changes in `includes/`, `admin/`, `doughboss.php` or `readme.txt`. The changed runtime payloads are only `public/css/doughboss.css`, `public/js/doughboss.js` and `themes/doughboss-final/functions.php`, covered by the frontend handoff and fresh GPT-6.1 Sol gate. This preserves the scope of earlier database-source evidence; it does not prove installed parity or the production restore.

These are upload candidates only. They have not been installed or deployed. Required remaining acceptance includes the R4 actual-Linux public integration gate, isolated private WordPress/database restore and sanitized application boot, fresh production backup/rollback and installed-core parity, real browser/device performance, and Revesby provider/financial/device acceptance. Ordering/payments/Ads activation is not implied by package validation.
