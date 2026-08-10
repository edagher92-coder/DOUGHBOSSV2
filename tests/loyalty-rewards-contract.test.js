'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const core = read('includes/class-doughboss.php');
const loyalty = read('includes/class-doughboss-loyalty.php');
const activator = read('includes/class-doughboss-activator.php');
const assets = read('includes/class-doughboss-assets.php');
const shortcode = read('includes/class-doughboss-shortcodes.php');
const voucher = read('includes/class-doughboss-voucher.php');
const rest = read('includes/class-doughboss-rest-controller.php');
const settings = read('includes/class-doughboss-settings.php');

test('loyalty is a default-off, passwordless customer feature', () => {
  assert.match(core, /class-doughboss-loyalty\.php/);
  assert.match(loyalty, /wp_set_auth_cookie/);
  assert.match(loyalty, /token_hash/);
  assert.match(loyalty, /20 \* MINUTE_IN_SECONDS/);
  assert.match(loyalty, /loyalty_enabled/);
  assert.match(settings, /'loyalty_enabled'\s*=>\s*0/);
});

test('loyalty awards are durable, idempotent and redemption reuses single-use vouchers', () => {
  assert.match(activator, /doughboss_loyalty_members/);
  assert.match(activator, /doughboss_loyalty_ledger/);
  assert.match(activator, /UNIQUE KEY event_key/);
  assert.match(loyalty, /START TRANSACTION/);
  assert.match(loyalty, /DoughBoss_Voucher::issue/);
  assert.match(loyalty, /'scope'\s*=>\s*'both'/);
  assert.match(loyalty, /points_balance >= %d/);
  assert.match(loyalty, /doughboss_order_payment_status_changed/);
});

test('QR vouchers remain online-capable but are email-bound at secure checkout reservation', () => {
  assert.match(voucher, /\$customer_email = ''/);
  assert.match(voucher, /'online' === \$channel/);
  assert.match(rest, /DoughBoss_Voucher::RESERVATION_TTL_SECONDS,\s*\$email/);
});

test('the customer page and launch promotions are wired without leaking payment configuration', () => {
  assert.match(shortcode, /doughboss_loyalty/);
  assert.match(assets, /doughboss-loyalty/);
  assert.match(settings, /Join the Dough Club/);
  assert.match(settings, /Fresh Start/);
  assert.match(settings, /Tuesday Treat/);
  assert.doesNotMatch(loyalty, /sk_(test|live)_/);
});
