# DoughBoss Codex handoff — 23 July 2026

> **Historical checkpoint only.** The live-state observations below were made
> on 23 July and must not be treated as current. Source has since advanced to
> plugin `2.23.1` / schema `1.16.0` on draft PR #34. Continue from
> `DoughBoss-Completion-Handoff-2026-07-24.md`.

This is a restart-safe checkpoint for continuing the WordPress integration
from another computer.

## Live WordPress state

- Canonical `doughboss/doughboss.php` version 2.22.1 is installed and active.
- Three malformed nested plugin copies were moved (not deleted) to
  `wp-content/doughboss-quarantine-20260723/`.
- Ordering is OFF.
- Online payments are OFF.
- POSPal syncing is OFF.
- The public config, menu and locations REST endpoints respond.
- The live menu currently has 27 legacy items.
- The live locations endpoint currently has no shop rows.
- No real checkout, order or payment was created during testing.

## Release files

- Correct plugin ZIP:
  `https://github.com/edagher92-coder/DOUGHBOSSV2/releases/download/v2.22.1/doughboss.zip`
- One-purpose launch helper:
  `https://github.com/edagher92-coder/DOUGHBOSSV2/releases/download/v2.22.1/doughboss-launch-setup-helper.zip`

The helper is designed to import the canonical 33-item menu, ensure the
plugin's built-in Revesby location exists, and keep ordering, payments,
per-location payments and POS syncing disabled. WPVibe's restricted installer
could not install a custom ZIP URL, so helper activation remains outstanding.

## Source work in this checkpoint

- Added the canonical WordPress menu-option catalogue and server-side price
  resolver for the handwritten requirements.
- Added fresh-activation default-location seeding.
- Updated the admin/manual catalogue count from 27 to 33.
- Corrected remaining demo/document references to Dough Boss Pie and Spinach
  Pie.
- Storefront option controls, server-side resolver coverage, fresh-location
  seed coverage, and Windows-safe archive validation are completed in the
  follow-up change on `codex/wordpress-front-to-back-integration`.
- The CI build publishes the validated canonical plugin ZIP as an artifact.

## Safe continuation order

1. Run the complete test suite and GitHub Actions.
2. Build or download the new canonical ZIP from the verified CI artifact.
3. Upload/activate the new ZIP or the launch helper in WordPress.
4. Verify live config, all 33 menu items, the Revesby location and closed
   checkout. Do not create a real order or payment.
5. Remove helper plugins only after verification; keep quarantine for rollback.
6. Merge the verified PR and upload a fresh full-folder backup to the shared
   Google Drive folder.

Do not expose or copy any credentials from WordPress settings or prior
screenshots. The WordPress login password shown earlier should be rotated by the
owner after the deployment is stable.
