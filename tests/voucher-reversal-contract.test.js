'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');

const plugin = read('doughboss.php');
const voucher = read('includes/class-doughboss-voucher.php');
const rest = read('includes/class-doughboss-rest-controller.php');
const activator = read('includes/class-doughboss-activator.php');
const migrations = read('includes/class-doughboss-migrations.php');
const admin = read('admin/class-doughboss-admin.php');
const consoleApp = read('app/app.js');
const terms = read('docs/Promotional-Voucher-Print-Terms.md');

test('release 2.41 retains the manager-audited correction path', () => {
	assert.match(plugin, /Version:\s+2\.41\.1/);
	assert.match(plugin, /DOUGHBOSS_DB_VERSION',\s*'1\.23\.0'/);
	assert.match(voucher, /function reverse_redemption\s*\(/);
	assert.match(voucher, /'doughboss_voucher_reverse_reason'/);
	assert.match(voucher, /'instore' !== \(string\) \$redemption->channel/);
	assert.match(voucher, /! empty\( \$redemption->order_id \)/);
	assert.match(voucher, /record_audit_event/);
	assert.match(voucher, /'reversal'/);
});

test('reversal and void controls require attributable reasons and durable storage', () => {
	assert.match(activator, /redemption_status varchar\(20\) NOT NULL DEFAULT 'redeemed'/);
	assert.match(activator, /reversal_reason varchar\(500\) NOT NULL DEFAULT ''/);
	assert.match(activator, /doughboss_voucher_audit/);
	assert.match(migrations, /'1\.23\.0' => 'upgrade_to_1_23_0'/);
	assert.match(migrations, /function upgrade_to_1_23_0/);
	assert.match(rest, /'\/voucher\/reverse'/);
	assert.match(rest, /admin_reverse_voucher/);
	assert.match(rest, /'reason' => array\(\s*'required'\s*=>\s*true/s);
	assert.match(admin, /handle_reverse_voucher/);
	assert.match(admin, /doughboss_void_voucher/);
	assert.match(consoleApp, /Reason for till correction \(required\):/);
});

test('print terms tell management about the $3-to-zero-price edge case', () => {
	assert.match(terms, /Minimum spend \$3\./);
	assert.match(terms, /can reduce that basket to \$0/);
	assert.match(terms, /original receipt reference/);
	assert.match(terms, /not legal advice/i);
});
