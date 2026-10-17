# 2026-10-17 — Menu photo fallback loading

## Scope and result

Outcome: native lazy loading now applies to selected menu photos even when responsive metadata is missing or invalid. Previously those paths returned a CSS background; only valid packaged responsive photos used a lazy `<img>`.

Only `public/js/doughboss.js`, the existing `tests/frontend-upgrade.test.js`, and this handoff changed. Every path now renders one image inside the existing `.db-menu-picture` sizing wrapper, with `loading=lazy` and `decoding=async` set before its URL. Valid responsive candidates and dimensions are preserved. Invalid/partial/foreign candidates fall back to the exact original photo; existing validation was not relaxed.

Non-goals: no photo substitution, new ingredient claims, price/options/availability changes, cache/storage/API/schema changes, ordering/payment activation, dependencies, live deployment or full-site performance score. Existing photo-less branded tiles and compact drink rows remain unchanged. Existing CSS reserves the image box; no CSS/layout change was needed.

## Local evidence

- `node --check public/js/doughboss.js`: passed.
- `node --test tests/frontend-upgrade.test.js`: **57 passed, 0 failed**. Added existing-file coverage for uploaded/older-photo fallback, valid responsive sources/dimensions, malformed/foreign/duplicate/mismatched candidates, exactly one image, no eager background style, and loading-policy-before-URL ordering.
- PHP 8.2 `php tests/run.php`: **3,051 passed, 0 failed**.
- `git -c core.whitespace=cr-at-eol diff --check`: passed, preserving the existing mixed line endings.

Source SHA-256: `public/js/doughboss.js` = `A2AE9ED04C34CE989FA822837B3AF6936225E7D21579C06793E76BE5E16360A1`; frontend test = `5666396BE3F8DAD7D12B4B6D55B049A52A3B8338FF899E052960DC9B3BAA0B41`.

## Browser evidence, bounded to the component

Used the already-running loopback preview server, without starting a service or replacing existing preview files. New before/after/phone-width HTML fixtures live under `C:/Codex/Output/doughboss-local-first-20261017/hero-preview-root-r2/`. They contain the exact extracted production `menuPhoto()` and `el()` functions plus local public images. Function parity was independently asserted with Node against committed baseline/working source. Normalized function SHA-256: before `151f92f1dbc4b5387e8b48e84b9587f91e4fdc0ab494c85e5cd796a31b13cd41`; after `f7f6fb0689588c9d47ff843bc70b9e8e29135eb2c87cd9cdab7f4d0717c16533`. The final change after browser observation was comment-only, clarified to avoid implying an unmeasured eager-download result; function parity was rechecked afterward.

At 1280x720, the candidate's near-viewport photo loaded at natural width 550, while the distant photo remained `complete=false`, `naturalWidth=0`. Reaching the distant photo through the page link loaded it at natural width 550. Candidate resource entries changed from one to two; this proves the tested deferred loading behavior, not a live speed improvement. Baseline CSS-background Resource Timing entries were not reliable, so no comparative transfer reduction is claimed.

Same-origin iframe viewports exercised 390px and 320px component widths, with document widths 375px and 305px respectively (no horizontal overflow). These are component checks, not complete mobile-menu, checkout, device or live-site acceptance. A not-yet-visible iframe can legitimately keep its photos deferred.

Saved evidence in `C:/Codex/Output/doughboss-local-first-20261017/`:

- `menu-photo-initial-evidence.json` SHA-256 `04A359BEFE6CD88E6B819DE21809FC5D88587AD4451352040CF22A6648A0E08B` and `menu-photo-initial.png`.
- `menu-photo-after-scroll-evidence.json` SHA-256 `36FB7C354D41AE3F32D4E318BF898AFA9BBF679B52273C880D4F3DC4916B1DB0` and `menu-photo-loading-after-scroll.png`.
- `menu-photo-phone-width-evidence.json` SHA-256 `47558AE34ECBAC7A22A92AB261DAB1CB95F1448B45AE2A8F889D09561B70CCE4`.

## Local model use and publication boundary

One owner-approved dedicated local Qwen call used `task=code`, `check_with_claude=false`, a synthetic frontend-only packet and a 1,000-token cap. Actual model `qwen3.8-27b:code`, elapsed **33,312 ms**. It returned a draft that changed the metadata schema and referenced undefined data; that code was not applied and no retry was made. Codex retained the existing validator, implemented the small rendering change, and verified it with deterministic tests and the browser. No secrets, customer/staff data, provider state or pricing decisions were sent. This is not a model benchmark or measured credit-savings claim; the bounded advisory assignment is closed.

Save/push this as one source-only checkpoint on `codex/2.51-handoff-20261016` after the staged secret/whitespace checks. No deployment, PR, hosted/manual CI or release tag is included. The prior Square R14 database run accepts its own frozen revision (commit `529fd255b3d58c762d9c71eb0622eced31165de0`); this new public-JS change is not byte-identical to that whole-plugin freeze. Reuse unchanged backend contract evidence appropriately, but regenerate final package/parity evidence and retain all release gates.

The full launch remains open: menu request/startup and live performance measurement, exact current live/candidate reconciliation, restore/boot and rollback, timed catering/approval capture, Square merchant/location/item-tax and real financial/gift/loyalty/staff/KDS acceptance. Bankstown/Roselands account connections remain deferred; Ads spending and production ordering/payments are not enabled by this checkpoint.
