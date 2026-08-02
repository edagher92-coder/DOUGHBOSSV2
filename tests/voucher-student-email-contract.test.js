'use strict';

var fs = require('fs');
var path = require('path');
var test = require('node:test');
var assert = require('node:assert/strict');
var root = path.resolve(__dirname, '..');
var voucherPhp = fs.readFileSync(path.join(root, 'includes/class-doughboss-voucher.php'), 'utf8');
var restPhp = fs.readFileSync(path.join(root, 'includes/class-doughboss-rest-controller.php'), 'utf8');
var shortcodePhp = fs.readFileSync(path.join(root, 'includes/class-doughboss-shortcodes.php'), 'utf8');
var adminPhp = fs.readFileSync(path.join(root, 'admin/class-doughboss-admin.php'), 'utf8');
var voucherJs = fs.readFileSync(path.join(root, 'public/js/doughboss-voucher.js'), 'utf8');
var assetsPhp = fs.readFileSync(path.join(root, 'includes/class-doughboss-assets.php'), 'utf8');
var stripePhp = fs.readFileSync(path.join(root, 'includes/class-doughboss-stripe.php'), 'utf8');
var checkoutJs = fs.readFileSync(path.join(root, 'public/js/doughboss.js'), 'utf8');

test('student campaign requires a confirmed eligible education email server-side', function () {
	assert.match(voucherPhp, /['"]requires_student_email['"]\s*=>\s*1/);
	assert.match(voucherPhp, /campaign_requires_student_email/);
	assert.match(voucherPhp, /eligible_student_email/);
	assert.match(voucherPhp, /\(\?:\^\|\\\.\)edu\(\?:\\\.au\)\?\$/);
	assert.match(voucherPhp, /hash_equals\(\s*\$student_email\s*,\s*\$confirmation\s*\)/);
	assert.match(voucherPhp, /student_email_claimed_today/);
	assert.match(voucherPhp, /LOWER\(customer_email\)/);
	assert.match(voucherPhp, /doughboss_student_email_used/);
});

test('public and manager claim forms both require the email to be re-entered', function () {
	assert.match(restPhp, /['"]customer_email_confirmation['"]/);
	assert.match(restPhp, /get_param\(\s*['"]customer_email_confirmation['"]\s*\)/);
	assert.match(shortcodePhp, /name="email_confirmation"[\s\S]{0,500}?required/);
	assert.match(shortcodePhp, /Student email/);
	assert.match(adminPhp, /name="email_confirmation"[\s\S]{0,300}?required/);
	assert.match(adminPhp, /customer_email_confirmation/);
	assert.match(voucherJs, /email\s*!==\s*emailConfirmation/);
	assert.match(voucherJs, /customer_email_confirmation:\s*emailConfirmation/);
});

test('voucher QR rendering is self-hosted and resilient to third-party blocking', function () {
	assert.match(assetsPhp, /public\/vendor\/qrcode-generator\/qrcode\.js/);
	assert.doesNotMatch(assetsPhp, /cdn\.jsdelivr\.net\/npm\/qrcode-generator/);
});

test('Stripe checkout remains provider hosted with automatic wallets and clear handoff copy', function () {
	assert.match(stripePhp, /['"]payment_method_types\[0\]['"]\s*=>\s*['"]card['"]/);
	assert.match(checkoutJs, /Apple Pay or Google Pay is offered automatically when eligible/);
	assert.match(checkoutJs, /DoughBoss never stores your card details/);
	assert.match(checkoutJs, /return here for your order number, receipt and live tracking/);
	assert.match(checkoutJs, /db-stripe-notice-total/);
});
