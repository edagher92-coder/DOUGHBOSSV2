const fs = require('node:fs');
const assert = require('node:assert/strict');
const src = fs.readFileSync('tools/doughboss-verified-updater-2360/doughboss-verified-updater-2360.php', 'utf8');
for (const needle of [
  'Plugin Name: DoughBoss Verified Updater 2.36.0',
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
  "check_admin_referer( 'doughboss_deploy_2360' )",
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
]) assert.ok(src.includes(needle), `missing ${needle}`);
assert.ok(!src.includes('SNIPPET_ID_OPTION'));
assert.ok(!src.includes("empty( $_GET['db_run'] )"));
assert.ok(!src.includes("$failed = $extract_root"));
console.log('verified updater 2.36.0 structural contract: PASS');
