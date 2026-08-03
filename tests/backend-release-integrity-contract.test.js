'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');

const voucher = read('includes/class-doughboss-voucher.php');
const settings = read('includes/class-doughboss-settings.php');
const admin = read('admin/class-doughboss-admin.php');
const activator = read('includes/class-doughboss-activator.php');
const uninstall = read('uninstall.php');
const catering = read('includes/class-doughboss-catering.php');
const rest = read('includes/class-doughboss-rest-controller.php');
const menuPostType = read('includes/class-doughboss-post-types.php');
const cateringPostType = read('includes/class-doughboss-catering-package.php');

test('daily-capped voucher campaigns fail closed when their serialization lock is unavailable', () => {
	assert.match(voucher, /SELECT GET_LOCK\(%s, %d\)/);
	assert.match(voucher, /if \( \$cap > 0 && 1 !== \$got \)/);
	assert.match(voucher, /doughboss_campaign_busy/);
	assert.match(voucher, /claimed_today_for\( \$campaign \) >= \$cap/);
	assert.match(voucher, /SELECT RELEASE_LOCK\(%s\)/);
});

test('fresh installs and settings saves use the approved operational inboxes', () => {
	assert.match(settings, /'orders_email'\s*=>\s*'orders@doughboss\.com\.au'/);
	assert.match(settings, /'catering_email'\s*=>\s*'catering@doughboss\.com\.au'/);
	assert.match(admin, /\$existing\['orders_email'\][\s\S]{0,100}?'orders@doughboss\.com\.au'/);
});

test('uninstall removes every custom table created by the current schema', () => {
	const tablePattern = /\$wpdb->prefix\s*\.\s*'([^']+)'/g;
	const created = new Set();
	const removed = new Set();
	let match;

	while ((match = tablePattern.exec(activator)) !== null) {
		created.add(match[1]);
	}
	tablePattern.lastIndex = 0;
	while ((match = tablePattern.exec(uninstall)) !== null) {
		removed.add(match[1]);
	}

	assert.deepEqual([...removed].sort(), [...created].sort());
});

test('custom catering quotes are manager-only, server-derived AUD values', () => {
	assert.match(rest, /admin\/catering\/\(\?P<id>\\d\+\)\/quote/);
	assert.match(rest, /admin_update_catering_quote/);
	assert.match(rest, /'permission_callback'\s*=>\s*array\( \$this, 'verify_manage' \)/);
	assert.match(catering, /function set_custom_quote\s*\(/);
	assert.match(catering, /! is_numeric\( \$subtotal_raw \)/);
	assert.match(catering, /'AUD' !== strtoupper/);
	assert.match(catering, /\$total\s*=\s*round\( \$subtotal \+ \$delivery, 2 \)/);
	assert.match(catering, /'delivery' === \(string\) \$before\['order_type'\] \? \$delivery : 0\.0/);
	assert.match(catering, /\$deposit\s*=\s*round\( \$total \* \$pct \/ 100, 2 \)/);
	assert.match(catering, /\$balance\s*=\s*round\( \$total - \$deposit, 2 \)/);
	assert.match(catering, /if \( \$deposit < 0\.01 \)/);
	assert.match(catering, /doughboss_catering_quote_updated/);
	assert.match(catering, /get_current_user_id\(\)/);
	assert.match(admin, /db-catering-quote-save/);
});

test('catering quote and payment preparation share an immutable serialization boundary', () => {
	assert.match(catering, /SELECT GET_LOCK\(%s, %d\)/);
	assert.match(catering, /SELECT RELEASE_LOCK\(%s\)/);
	assert.match(catering, /function has_payment_preparation\s*\(/);
	assert.match(catering, /doughboss_payment_attempts/);
	assert.match(catering, /deposit_intent_id = '' AND balance_intent_id = ''/);
	assert.match(catering, /status IN \(%s, %s\)/);
	assert.match(catering, /SET \{\$column\} = %s[\s\S]{0,160}?\(\{\$column\} = '' OR \{\$column\} = %s\)/);
	assert.match(rest, /catering_payment_intent[\s\S]{0,1600}?acquire_quote_lock\( \$enquiry_id \)/);
	assert.match(rest, /finally \{[\s\S]{0,160}?release_quote_lock\( \$enquiry_id \)/);
	assert.match(rest, /catering_payment_intent_locked/);
	assert.match(catering, /\$settles_all\s*=\s*! \$is_bal && \(float\) \$enquiry\['balance_amount'\] <= 0\.0/);
	assert.match(catering, /balance_paid_at = CASE WHEN balance_paid_at IS NULL/);
});

test('menu and catering-package writes require the DoughBoss management capability', () => {
	for (const source of [menuPostType, cateringPostType]) {
		assert.match(source, /'capabilities'\s*=>\s*self::management_capabilities\(\)/);
		assert.match(source, /'edit_posts'\s*=>\s*'manage_doughboss'/);
		assert.match(source, /'publish_posts'\s*=>\s*'manage_doughboss'/);
		assert.match(source, /'create_posts'\s*=>\s*'manage_doughboss'/);
		assert.doesNotMatch(source, /current_user_can\( 'edit_posts' \)/);
	}
	assert.match(menuPostType, /'manage_terms'\s*=>\s*'manage_doughboss'/);
	assert.match(menuPostType, /'assign_terms'\s*=>\s*'manage_doughboss'/);
});
