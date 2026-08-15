const fs = require('node:fs');
const assert = require('node:assert/strict');
const src = fs.readFileSync('tools/doughboss-deploy-2360-pclzip/doughboss-deploy-2360-pclzip.php', 'utf8');
for (const needle of [
  'Plugin Name: DoughBoss Deploy 2.36.0 (PclZip)',
  'Version: 1.0.1',
  'final class DoughBoss_Deploy_2360_PclZip',
  "'doughboss_deploy_2360_pclzip_result'",
  "'doughboss_deploy_2360_pclzip_journal'",
  "'doughboss_deploy_2360_pclzip_db_lock'",
  "'.doughboss-deploy-2360-pclzip.lock'",
  "'doughboss-deploy-2360-pclzip'",
  "'doughboss-deploy-2360-pclzip/v1'",
  "'doughboss_deploy_2360_pclzip'",
  "'bytes'    => 2498594",
  "'sha256'   => 'b451b72111cd5aa46800ad7a2eebebc0e7e926ced2773666853f4b8a8c1e7f27'",
  "'bytes'    => 3285",
  "'sha256'   => '7365c464f9786627d94d39b878e06a2cf2d247cdc3133d3d1776c7062efe693a'",
  "array_key_exists( 'ordering_open'",
  "array_key_exists( 'payments_enabled'",
  "WP_Filesystem()",
  "JOURNAL_OPTION",
  "recovery_required",
  "verify_fresh_request",
  "'1.20.0'",
  "doughboss-displaced-",
  "zip_special",
  "source_changed",
  "manifest.json",
  "check_admin_referer( 'doughboss_deploy_2360_pclzip' )",
  "is_exact_off",
  "cutover_' . $key . '_pending",
  "'cross_device'",
  "catch ( Throwable $e )",
  "DoughBoss_Migration_Gate",
  "DoughBoss_Timeclock::storage_ready()",
  "doughboss_portal_routes_version",
  "manifest_sha256",
  "hash_equals( $expected_digest",
  "write_verified_option",
  "! $operational || ! $shield",
  "'' !== $target['textdomain']",
  "class-pclzip.php",
  "class_exists( 'PclZip' )",
  "validate_archive_members",
  "stored_filename",
  "compressed_size",
  "zip_overflow",
]) assert.ok(src.includes(needle), `missing ${needle}`);
assert.ok(!src.includes("ZipArchive is required for safe package inspection."));
assert.ok(!src.includes('SNIPPET_ID_OPTION'));
assert.ok(!src.includes("empty( $_GET['db_run'] )"));
assert.ok(!src.includes("$failed = $extract_root"));
assert.ok(!src.includes('DoughBoss_Deploy_Bridge_2360'));
for (const stale of [
  "'doughboss_deploy_2360_result'",
  "'doughboss_deploy_2360_journal'",
  "'doughboss_deploy_2360_db_lock'",
  "'.doughboss-deploy-2360.lock'",
  "'doughboss-deploy-2360'",
  "'doughboss-updater/v1'",
  "'doughboss_deploy_2360'",
]) assert.ok(!src.includes(stale), `stale deployment identifier ${stale}`);
assert.ok(fs.existsSync('tests/pclzip-fallback-2360.php'));
console.log('verified updater 2.36.0 unique-slug structural contract: PASS');
