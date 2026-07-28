'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const controller = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-rest-controller.php'), 'utf8');
const snapshots = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-checkout-snapshots.php'), 'utf8');
const activator = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-activator.php'), 'utf8');
const client = fs.readFileSync(path.join(root, 'public', 'js', 'doughboss.js'), 'utf8');

test('Stripe payment preparation stores a server-owned order snapshot before redirect', function () {
	assert.match(controller, /DoughBoss_Checkout_Snapshots::store\([\s\S]{0,2500}?'order'\s*=>[\s\S]{0,2500}?'lines'\s*=>\s*array_values\(\s*\$this->cart->get_lines\(\)\s*\)/);
	assert.match(controller, /DoughBoss_Checkout_Snapshots::store\([\s\S]{0,5000}?DoughBoss_Stripe::create_checkout_session\(/);
	assert.match(client, /customer_phone:\s*payload\.customer_phone,[\s\S]{0,200}?notes:\s*payload\.notes/);
});

test('signed Stripe webhook can recover exactly one paid order from the snapshot', function () {
	assert.match(controller, /function recover_stripe_order\(/);
	assert.match(controller, /find_by_checkout_key\(\s*\$checkout_key\s*\)/);
	assert.match(controller, /DoughBoss_Checkout_Snapshots::find\(\s*\$checkout_key\s*\)/);
	assert.match(controller, /\$amount\s*!==\s*\(int\)\s*\$attempt\['amount_minor'\]/);
	assert.match(controller, /\$currency\s*!==\s*strtoupper\(\s*\(string\)\s*\$attempt\['currency'\]\s*\)/);
	assert.match(controller, /'payment_status'\]\s*=\s*'paid'/);
	assert.match(controller, /'payment_method'\]\s*=\s*'stripe'/);
	assert.match(controller, /'payment_intent_id'\]\s*=\s*\$pi_id/);
	assert.match(controller, /DoughBoss_Order::create\(\s*\$order_data,\s*\$lines\s*\)/);
	assert.match(controller, /DoughBoss_Checkout_Snapshots::complete\(\s*\$checkout_key,\s*\$order_id\s*\)/);
});

test('recovery snapshot storage is immutable, bounded and short lived', function () {
	assert.match(snapshots, /hash_equals\(\s*\(string\)\s*\$existing\['payload_hash'\],\s*\$payload_hash\s*\)/);
	assert.match(snapshots, /strlen\(\s*\$json\s*\)\s*>\s*262144/);
	assert.match(snapshots, /time\(\)\s*\+\s*DAY_IN_SECONDS/);
	assert.match(snapshots, /DELETE FROM .*expires_at < %s LIMIT 100/);
	assert.doesNotMatch(snapshots, /card_number|cardNumber|cvc|cvv|secret_key|client_secret/);
});

test('activation requires the InnoDB recovery table and its unique checkout key', function () {
	assert.match(activator, /CREATE TABLE \{\$checkout_snapshots\}/);
	assert.match(activator, /UNIQUE KEY checkout_key \(checkout_key\)/);
	assert.match(activator, /foreach \( array\( \$attempts, \$events, \$locations, \$snapshots \)/);
	assert.match(activator, /index_contract_ready\( \$snapshots, 'checkout_key'/);
});
