'use strict';

/*
 * Browser-only Stripe contract. This stays source-level because the checkout
 * loads Stripe.js from the payment provider and must not be exercised with
 * real card fields in an offline test run.
 *
 * Run: node --test tests/stripe-payment-element-contract.test.js
 */

var fs = require('fs');
var path = require('path');
var test = require('node:test');
var assert = require('node:assert/strict');
var root = path.resolve(__dirname, '..');
var client = fs.readFileSync(path.join(root, 'public/js/doughboss.js'), 'utf8');
var catering = fs.readFileSync(path.join(root, 'public/js/doughboss-catering.js'), 'utf8');

test('storefront uses Stripe Payment Element and confirmPayment rather than legacy Card Element', function () {
	assert.match(client, /\.create\(\s*['"]payment['"]/);
	assert.match(client, /\.confirmPayment\s*\(/);
	assert.doesNotMatch(client, /\.create\(\s*['"]card['"]/);
	assert.doesNotMatch(client, /\.confirmCardPayment\s*\(/);
	assert.match(client, /stripeElements\.submit\(\)[\s\S]{0,500}?stripe\.confirmPayment\(/);
	assert.match(client, /stripe\.confirmPayment\(\{[\s\S]{0,500}?\belements:\s*stripeElements,[\s\S]{0,500}?\bredirect:\s*['"]if_required['"]/);
	assert.match(client, /wallets:\s*\{\s*applePay:\s*['"]auto['"],\s*googlePay:\s*['"]auto['"]\s*\}/);
	assert.match(client, /defaultValues:\s*\{\s*billingDetails:\s*\{\s*name:\s*payload\.customer_name,\s*email:\s*payload\.customer_email\s*\}/);
	assert.match(client, /if\s*\(\s*checkoutPaymentId\s*\)\s*\{[\s\S]{0,250}?payload\.payment_intent_id\s*=\s*checkoutPaymentId;[\s\S]{0,250}?placeOrder\(payload\)[\s\S]{0,100}?\breturn;/);
	assert.match(client, /setPaymentMutationLock\(true\)[\s\S]{0,300}?stripeElements\.submit\(\)/);
	assert.match(client, /paymentMutationLock\s*&&\s*method\s*!==\s*['"]GET['"][\s\S]{0,120}?\^\\\/cart/);
	assert.match(client, /paymentElement\.on\(['"]ready['"][\s\S]{0,500}?submit\.disabled\s*=\s*false/);
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
