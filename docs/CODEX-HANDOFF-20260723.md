# DoughBoss Codex handoff — 23 July 2026

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
- Live storefront wiring for the new option groups is not finished yet.
- Windows-safe ZIP build and CI archive-path validation are not finished yet.

## Safe continuation order

1. Finish REST/client wiring and tests for menu options.
2. Add Windows-safe packaging and CI path validation.
3. Run the complete test suite and GitHub Actions.
4. Build a new canonical ZIP.
5. Upload/activate the new ZIP or the launch helper in WordPress.
6. Verify live config, all 33 menu items, the Revesby location and closed
   checkout. Do not create a real order or payment.
7. Remove helper plugins only after verification; keep quarantine for rollback.
8. Merge the verified PR and upload a fresh full-folder backup to the shared
   Google Drive folder.

Do not expose or copy any credentials from WordPress settings or prior
screenshots. The WordPress login password shown earlier should be rotated by the
owner after the deployment is stable.
