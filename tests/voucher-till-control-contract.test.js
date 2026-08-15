'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const voucher = read('includes/class-doughboss-voucher.php');
const rest = read('includes/class-doughboss-rest-controller.php');
const activator = read('includes/class-doughboss-activator.php');
const migrations = read('includes/class-doughboss-migrations.php');
const settings = read('includes/class-doughboss-settings.php');
const scanner = read('public/js/doughboss-voucher-scan.js');

test('promotional vouchers are capped at $5 and require at least a $3 spend', () => {
  assert.match(voucher, /'amount' === \$type && \$value > 5\.00/);
  assert.match(voucher, /max\( 3\.00, round\(/);
  assert.match(voucher, /\$minimum_spend = max\( 3\.00, \(float\) \$row->min_spend \)/);
  assert.match(voucher, /min\( \$amount, \$subtotal, 5\.00 \)/);
  assert.match(voucher, /'min_spend' => 3\.00/);
});

test('in-store scans require a named owner, receipt reference and attributable cashier', () => {
  assert.match(settings, /voucher_reconciliation_owner_id/);
  assert.match(rest, /transaction_ref/);
  assert.match(rest, /doughboss_voucher_owner/);
  assert.match(rest, /wp_get_current_user\(\)/);
  assert.match(rest, /pospal_ticket_no' => \$transaction_ref/);
  assert.match(voucher, /redeemed_by_user_id/);
  assert.match(voucher, /redeemed_by_name/);
  assert.match(voucher, /doughboss_voucher_reconciliation/);
  assert.match(scanner, /POS\/till receipt no\. \(required\)/);
  assert.match(scanner, /transaction_ref: receipt/);
});

test('redemption storage maintains unique receipt evidence and a migration', () => {
  assert.match(activator, /UNIQUE KEY location_transaction_reference \(location_id,transaction_reference\)/);
  assert.match(activator, /KEY redeemed_by_user_id \(redeemed_by_user_id\)/);
  assert.match(migrations, /'1\.22\.0' => 'upgrade_to_1_22_0'/);
  assert.match(migrations, /function upgrade_to_1_22_0/);
});
