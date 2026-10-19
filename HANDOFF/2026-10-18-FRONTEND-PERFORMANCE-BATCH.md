# 18 October 2026 — frontend performance batch

## Scope

Reduce homepage and mobile-menu overhead without changing catalogue data, availability freshness, ordering, checkout, payments, provider state or the live site.

## Source changes

- `themes/doughboss-final/functions.php`: the presentation-only homepage no longer forces the full storefront bundle. The independently enqueued hero remains enabled on the homepage and the lightweight sitewide shop-status script remains enabled. Menu, order, tracking and catering pages retain the storefront bundle.
- `public/js/doughboss.js`: config, locations and table-context reads start together. Rendering remains behind the table-context promise, preserving the fail-closed table-session boundary while removing a network waterfall on ordinary menu/order pages.
- `public/css/doughboss.css`: off-screen menu grids use `content-visibility: auto` with a remembered intrinsic block size, reducing layout and paint work for long mobile menus without hiding content or caching availability.
- Existing tests were extended in `tests/storefront-helpers.test.js` and `tests/frontend-upgrade.test.js`.

## Local proof

- `node --check public/js/doughboss.js`: passed.
- PHP 8.2 `-n -l themes/doughboss-final/functions.php`: no syntax errors.
- `node --test tests/storefront-helpers.test.js tests/frontend-upgrade.test.js`: **170/170 passed**, zero failures.
- PHP 8.2 `-n tests/run.php`: **3105/3105 passed**, zero failures.
- PHP 7.4 `-n tests/run.php`: **3035/3035 passed**, zero failures.
- `git -c core.whitespace=cr-at-eol diff --check`: passed.

## GPT-6.1 Sol gate

A fresh read-only GPT-6.1 Sol review returned **SHIP for local source readiness** with no blocking correctness, security-boundary or scope finding. The reviewer independently reran the focused Node suite (**170/170 passed**), JavaScript syntax and diff checks, and added an ephemeral `node --unhandled-rejections=strict` exercise over the real bootstrap/getter functions. All **5/5** success and failure scenarios passed: public reads began together, no renderer ran before the table gate, a rejected table session rendered only the blocked recovery state, and shared-request failures produced no unhandled rejection.

The gate is limited to local source readiness. It is not a live-release, real-device or measured-performance acceptance result.

These checks prove source contracts and synthetic UI behavior, not real-device milliseconds or live publication. No browser/provider test or deployment was performed in this batch. The accepted source/test changes were saved in commit `43cebe5c735c24c2821b71178e910b52cd5ab85d` on `codex/2.51-handoff-20261016`. Orders, payments and Ads remain off.
