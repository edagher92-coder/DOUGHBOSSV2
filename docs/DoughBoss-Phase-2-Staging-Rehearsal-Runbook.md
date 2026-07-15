# DoughBoss Phase 2 staging migration and rollback rehearsal

**Applies to:** plugin `2.18.0`, database `1.10.0 -> 1.11.0`

**Target commit:** record the exact PR #22 head SHA before starting
**Safety rule:** run only on a disposable staging clone made from a recent,
sanitised production backup. Never run the reset/import steps on production.

This is the manual evidence gate that automated MariaDB CI cannot replace. CI
proves the migration logic on clean MariaDB 10.6 and 11.4 services; this
rehearsal proves the real site's data shape, volume, table engines, migration
duration and backup restore path.

## 1. Required inputs and owners

- A recent sanitised production database dump stored outside the web root.
- The previous reviewed plugin zip using DB contract `1.10.0`.
- The PR #22 plugin zip built from the exact reviewed head SHA.
- WP-CLI and database access to an isolated staging site.
- A named operator for the migration and a second person for the go/no-go check.
- A maintenance window in which staging can be reset twice.

Stop if any input is missing. Do not substitute the live database for the
sanitised copy and do not copy customer data into CI artifacts or this repo.

## 2. Prove the target is disposable staging

From the staging WordPress root, record these values in the evidence record:

```bash
date -u +%FT%TZ
wp option get home
wp option get siteurl
wp db prefix
wp config get DB_NAME
wp plugin status doughboss
```

The operator and checker must both confirm that the URL and database name are
staging-only. Set a shell guard only after that check:

```bash
export DOUGHBOSS_STAGING_RESET_APPROVED=YES
test "$DOUGHBOSS_STAGING_RESET_APPROVED" = YES
```

Disable public access, background traffic, outbound email/SMS, POSPal,
printers, Mercure publishing and payments for the rehearsal. Stripe must remain
off or in test mode.

## 3. Preserve and identify the inputs

```bash
sha256sum /secure/path/sanitised-production.sql
sha256sum /secure/path/doughboss-previous.zip
sha256sum /secure/path/doughboss-pr22.zip
git rev-parse HEAD
```

Copy the sanitised dump to a second safe location before using it. The SHA-256
values must match. Never print secrets or raw customer rows into the evidence
record.

## 4. Establish the 1.10 baseline

Resetting a database is destructive. Reconfirm the target from section 2, then:

```bash
test "$DOUGHBOSS_STAGING_RESET_APPROVED" = YES
wp --skip-plugins --skip-themes db reset --yes
wp --skip-plugins --skip-themes db import /secure/path/sanitised-production.sql
wp --skip-plugins --skip-themes cache flush
```

Before loading WordPress normally, place the previous reviewed plugin files on
disk. The release zip contains a top-level `doughboss/` directory:

```bash
if test -d wp-content/plugins/doughboss; then
  mv wp-content/plugins/doughboss /tmp/doughboss-pre-rehearsal-files
fi
unzip -q /secure/path/doughboss-previous.zip -d wp-content/plugins
```

Load WordPress once, then require the isolated starting contract:

```bash
wp option get doughboss_db_version
wp eval 'echo defined("DOUGHBOSS_VERSION") ? DOUGHBOSS_VERSION : "missing";'
```

Expected database version: exactly `1.10.0`. Stop if it is older or newer; that
would be a different migration test.

Create privacy-safe fingerprints of the durable order data. The raw rows pass
only through SHA-256 and must not be saved to the repo:

```bash
PREFIX="$(wp db prefix)"
wp db query --skip-column-names "SELECT id,order_number,location_id,status,order_type,subtotal,tax,delivery_fee,total,discount,currency,payment_status,eta_minutes,created_at,updated_at FROM ${PREFIX}doughboss_orders ORDER BY id" | sha256sum | tee /tmp/doughboss-orders.before.sha256
wp db query --skip-column-names "SELECT id,order_id,item_id,size,quantity,unit_price,line_total FROM ${PREFIX}doughboss_order_items ORDER BY id" | sha256sum | tee /tmp/doughboss-items.before.sha256
wp db query --skip-column-names "SELECT COUNT(*),COALESCE(SUM(total),0),MIN(id),MAX(id) FROM ${PREFIX}doughboss_orders"
wp db query --skip-column-names "SELECT COUNT(*),COALESCE(SUM(line_total),0),MIN(id),MAX(id) FROM ${PREFIX}doughboss_order_items"
```

Take the actual rollback snapshot now, before new plugin files can boot:

```bash
wp db export /secure/path/doughboss-phase2-pre-migration.sql --add-drop-table
sha256sum /secure/path/doughboss-phase2-pre-migration.sql
```

## 5. Run and time the migration

Replace the previous files without loading WordPress or serving public traffic.
Keep the prior directory intact for rollback. The first WordPress load starts
the migration, so time that load:

```bash
mv wp-content/plugins/doughboss /tmp/doughboss-1.10-rehearsal
unzip -q /secure/path/doughboss-pr22.zip -d wp-content/plugins
STARTED="$(date -u +%FT%TZ)"
SECONDS=0
wp eval 'echo get_option("doughboss_db_version");'
ELAPSED_SECONDS="$SECONDS"
FINISHED="$(date -u +%FT%TZ)"
printf 'started=%s\nfinished=%s\nelapsed_seconds=%s\n' "$STARTED" "$FINISHED" "$ELAPSED_SECONDS"
```

Record database and PHP slow-query/error logs covering this exact interval.
Stop on a timeout, lock wait, fatal, or `DoughBoss migration halted` message.

## 6. Validate the migrated data and contract

```bash
wp option get doughboss_db_version
wp eval 'var_export(DoughBoss_Activator::lifecycle_storage_ready());'
PREFIX="$(wp db prefix)"
wp db query "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('${PREFIX}doughboss_orders','${PREFIX}doughboss_order_items','${PREFIX}doughboss_order_events') ORDER BY TABLE_NAME"
wp db query "SHOW INDEX FROM ${PREFIX}doughboss_order_events"
wp db query --skip-column-names "SELECT COUNT(*) FROM ${PREFIX}doughboss_order_events"
wp db query --skip-column-names "SELECT id,order_number,location_id,status,order_type,subtotal,tax,delivery_fee,total,discount,currency,payment_status,eta_minutes,created_at,updated_at FROM ${PREFIX}doughboss_orders ORDER BY id" | sha256sum | tee /tmp/doughboss-orders.after.sha256
wp db query --skip-column-names "SELECT id,order_id,item_id,size,quantity,unit_price,line_total FROM ${PREFIX}doughboss_order_items ORDER BY id" | sha256sum | tee /tmp/doughboss-items.after.sha256
diff -u /tmp/doughboss-orders.before.sha256 /tmp/doughboss-orders.after.sha256
diff -u /tmp/doughboss-items.before.sha256 /tmp/doughboss-items.after.sha256
```

Pass criteria:

- Stored DB version is `1.11.0` and readiness returns `true`.
- Orders, items and events all use InnoDB.
- Both fingerprints are unchanged.
- The new events table is empty; historical events were not fabricated.
- No migration halt, fatal, lock timeout or unexpected outbound integration.
- A second WordPress load is fast, leaves the version unchanged and creates no
  rows or schema changes.

After these checks, create one staging-only unpaid pickup order and run it
through Accept, Preparing, Ready and Complete. Confirm one event per version and
that a stale second tablet receives a conflict instead of overwriting the order.

## 7. Rehearse full rollback

Reconfirm the disposable target. Restore both the pre-migration database and
the previous plugin files; a code-only downgrade is not a complete rollback.

```bash
test "$DOUGHBOSS_STAGING_RESET_APPROVED" = YES
ROLLBACK_STARTED="$(date -u +%FT%TZ)"
SECONDS=0
wp --skip-plugins --skip-themes db reset --yes
wp --skip-plugins --skip-themes db import /secure/path/doughboss-phase2-pre-migration.sql
mv wp-content/plugins/doughboss /tmp/doughboss-2.18-rolled-back
mv /tmp/doughboss-1.10-rehearsal wp-content/plugins/doughboss
wp --skip-plugins --skip-themes cache flush
wp option get doughboss_db_version
ROLLBACK_SECONDS="$SECONDS"
ROLLBACK_FINISHED="$(date -u +%FT%TZ)"
printf 'rollback_started=%s\nrollback_finished=%s\nrollback_seconds=%s\n' "$ROLLBACK_STARTED" "$ROLLBACK_FINISHED" "$ROLLBACK_SECONDS"
```

Recreate the two fingerprints and compare them with the `before` files. Confirm
the site boots with the previous plugin, the stored DB version is `1.10.0`, the
order/item aggregates match, and the error log is clean.

## 8. Evidence record and merge decision

Attach only non-sensitive evidence to PR #22:

- staging URL label (not credentials), date, operator and checker;
- sanitised source backup date and SHA-256;
- previous/new plugin SHA-256 and PR head commit SHA;
- MariaDB/MySQL, WordPress and PHP versions;
- order/item counts and totals, never raw customer rows;
- migration and rollback duration;
- table engines, readiness result and fingerprint comparisons;
- log outcome and two-tablet stale-update result;
- explicit PASS/FAIL and any follow-up issue links.

PR #22 can leave draft only after every pass criterion is recorded. A CI-green
result alone is not approval to deploy the migration to production.
