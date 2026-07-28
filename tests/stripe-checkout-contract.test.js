'use strict';

/*
 * Browser-only Stripe contract. Storefront card entry is entirely hosted by
 * Stripe Checkout; catering intentionally retains its Payment Element flow.
 *
 * Run: node --test tests/stripe-checkout-contract.test.js
 */

var fs = require('fs');
var path = require('path');
var test = require('node:test');
var assert = require('node:assert/strict');
var root = path.resolve(__dirname, '..');
var client = fs.readFileSync(path.join(root, 'public/js/doughboss.js'), 'utf8');
var catering = fs.readFileSync(path.join(root, 'public/js/doughboss-catering.js'), 'utf8');
var stripePhp = fs.readFileSync(path.join(root, 'includes/class-doughboss-stripe.php'), 'utf8');
var restPhp = fs.readFileSync(path.join(root, 'includes/class-doughboss-rest-controller.php'), 'utf8');
var settingsPhp = fs.readFileSync(path.join(root, 'includes/class-doughboss-settings.php'), 'utf8');
var assetsPhp = fs.readFileSync(path.join(root, 'includes/class-doughboss-assets.php'), 'utf8');

test('storefront redirects to a verified Stripe-hosted Checkout Session', function () {
	assert.match(client, /var stripeHosted\s*=\s*!!\(PAY\.enabled\s*&&\s*GATEWAY\s*===\s*['"]stripe['"]\)/);
	assert.match(client, /request\(['"]\/payment-intent['"][\s\S]{0,900}?customer_email:\s*payload\.customer_email[\s\S]{0,900}?return_url:\s*window\.location\.href/);
	assert.match(client, /\^https:\\\/\\\/checkout\\\.stripe\\\.com\\\//);
	assert.match(client, /window\.sessionStorage\.setItem\(['"]doughbossStripePending['"]/);
	assert.match(client, /window\.location\.assign\(session\.checkout_url\)/);
	assert.match(client, /doughboss_stripe_return[\s\S]{0,1000}?session_id[\s\S]{0,1000}?placeOrder\(stripePending\.payload\)/);
	assert.match(client, /stripePending\.payload\.payment_intent_id\s*=\s*returnedSession/);
	assert.doesNotMatch(client, /window\.Stripe\s*\(/);
	assert.doesNotMatch(client, /stripe\.elements\s*\(/);
	assert.doesNotMatch(client, /stripe\.confirmPayment\s*\(/);
	assert.doesNotMatch(client, /\.create\(\s*['"]card['"]/);
	assert.match(client, /paymentMutationLock\s*&&\s*method\s*!==\s*['"]GET['"][\s\S]{0,120}?\^\\\/cart/);
});

test('server creates idempotent Checkout Sessions and never exposes Stripe provider messages', function () {
	assert.match(stripePhp, /function create_checkout_session\s*\(/);
	assert.match(stripePhp, /['"]\/checkout\/sessions['"]/);
	assert.match(stripePhp, /self::idempotency_key\(\s*['"]checkout['"]\s*,\s*\$checkout_key\s*\)/);
	assert.match(stripePhp, /payment_intent_data\[metadata\]/);
	assert.match(stripePhp, /function retrieve_checkout_payment\s*\(/);
	assert.match(stripePhp, /checkout\.stripe\.com/);
	assert.match(stripePhp, /We could not start payment\. Please try again or contact the shop\./);
	assert.doesNotMatch(stripePhp, /\$data\[['"]error['"]\]\[['"]message['"]\]/);
	assert.match(settingsPhp, /function stripe_secret_key_valid\s*\(/);
	assert.match(settingsPhp, /['"]sk_live_['"]\s*:\s*['"]sk_test_['"]/);
	assert.doesNotMatch(assetsPhp, /wp_enqueue_script\(\s*['"]stripe-js['"]/);
});

test('checkout return and webhooks bind the hosted session to one canonical PaymentIntent', function () {
	assert.match(restPhp, /stripe_checkout_return_urls\s*\(/);
	assert.match(restPhp, /session_id=\{CHECKOUT_SESSION_ID\}/);
	assert.match(restPhp, /DoughBoss_Stripe::retrieve_checkout_payment\(\s*\$raw_id\s*\)/);
	assert.match(restPhp, /checkout_session_amount/);
	assert.match(restPhp, /checkout_session_currency/);
	assert.match(restPhp, /checkout_session_reference/);
	assert.match(restPhp, /array\(\s*['"]payment_intent\.succeeded['"]\s*,\s*['"]checkout\.session\.completed['"]\s*\)/);
	assert.match(restPhp, /DoughBoss_Order::payment_intent_used\(\s*\$pi_id\s*\)/);
});

test('catering uses the same modern Stripe Payment Element integration', function () {
	assert.match(catering, /\.create\(\s*['"]payment['"]/);
	assert.match(catering, /\.confirmPayment\s*\(/);
	assert.doesNotMatch(catering, /\.create\(\s*['"]card['"]/);
	assert.doesNotMatch(catering, /\.confirmCardPayment\s*\(/);
	assert.match(catering, /stripeElements\.submit\(\)[\s\S]{0,500}?stripe\.confirmPayment\(/);
	assert.match(catering, /stripe\.confirmPayment\(\{[\s\S]{0,500}?\belements:\s*stripeElements,[\s\S]{0,500}?\bredirect:\s*['"]if_required['"]/);
	assert.match(catering, /wallets:\s*\{\s*applePay:\s*['"]auto['"],\s*googlePay:\s*['"]auto['"]\s*\}/);
	assert.match(catering, /defaultValues:\s*\{\s*billingDetails:\s*\{\s*name:\s*state\.name,\s*email:\s*state\.email\s*\}/);
	assert.match(catering, /if\s*\(\s*confirmedPaymentId\s*\)\s*\{\s*payment\s*=\s*Promise\.resolve\(confirmedPaymentId\);\s*\}\s*else/);
	assert.match(catering, /payment_intent_id:\s*confirmedId/);
	assert.match(catering, /paymentElement\.on\(['"]loaderror['"][\s\S]{0,400}?resetStripePaymentForm\(\)/);
	assert.match(catering, /paymentElement\.on\(['"]ready['"][\s\S]{0,500}?btn\.disabled\s*=\s*false/);
});
