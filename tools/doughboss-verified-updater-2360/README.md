# DoughBoss Verified Updater 2.36.0

One-purpose deployment helper for the exact production baseline:

- active `doughboss/doughboss.php` 2.34.1 to 2.36.0
- active `doughboss-migration-gate/doughboss-migration-gate.php` 1.0.2 to 1.0.3
- database 1.18.0 with no migration error or lock
- `ordering_open` and `payments_enabled` keys both present and exactly off

Activation is inert. An administrator with plugin install/update/activate capabilities must open **Plugins > DoughBoss 2.36 updater** and submit the nonce-protected POST action. The helper checks exact release sizes, SHA-256 values, archive topology and plugin identities, writes byte-verified backups outside `ABSPATH`, then performs same-filesystem atomic folder swaps. A durable journal records the phase and whether host-assisted recovery is required.

Archive inspection uses PHP `ZipArchive` when available. On hosts without ext-zip, it falls back to WordPress's bundled `PclZip` inspector while retaining the same exact package pins, canonical-root/path, duplicate-name, entry-count, expanded-size, per-member-size and compression-ratio checks. The extracted tree is always scanned again to reject links and verify the expected plugin identity and required production files.

The helper intentionally does not run database migrations in the replacement request. The updated DoughBoss plugin performs its normal versioned migration on the next WordPress request. Keep Migration Gate active until a fresh request confirms DoughBoss 2.36.0, database 1.20.0, no migration error/lock, safe ordering/payment toggles and the operational endpoints.

Do not delete the external backup until acceptance is signed off. Deactivate and remove this helper after the fresh-request verification succeeds.
