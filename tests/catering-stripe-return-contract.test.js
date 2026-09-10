'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const catering = fs.readFileSync(path.join(root, 'public', 'js', 'doughboss-catering.js'), 'utf8');

test('Stripe catering 3DS return resumes the same saved deposit confirmation', function () {
	assert.match(catering, /function rememberStripePayment\(data, paymentIntentId\)/);
	assert.match(catering, /rememberStripePayment\(data, paymentId\)[\s\S]{0,400}?stripe\.confirmPayment/);
	assert.match(catering, /function resumeStripePaymentReturn\(\)/);
	assert.match(catering, /pending\.paymentIntentId !== returnedId/);
	assert.match(catering, /\['payment_intent', 'payment_intent_client_secret', 'redirect_status'\]/);
	assert.match(catering, /post\('\/catering\/confirm-payment'/);
	assert.match(catering, /if \(resumeStripePaymentReturn\(\)\)/);
});

test('package changes preserve form controls and never present a stale catering estimate', function () {
	assert.match(catering, /function updatePackageSelection\(\)/);
	assert.match(catering, /Update only the selected controls\. Re-rendering this root would lose/);
	assert.match(catering, /state\.quote = null;[\s\S]{0,160}?state\.quoteStatus = 'loading'/);
	assert.match(catering, /state\.quoteStatus = 'failed'/);
	assert.match(catering, /No current estimate is shown/);
	assert.match(catering, /if \(requestId !== quoteRequest\) \{ return; \}/);
	assert.match(catering, /if \(!r\.ok\) \{/);
	assert.match(catering, /throw new Error\(data && data\.message \? data\.message : 'Request failed\.'\)/);
});

test('reduced-motion customers receive non-animated catering scrolls', function () {
	assert.match(catering, /prefers-reduced-motion: reduce/);
	assert.match(catering, /behavior: reduceMotion \? 'auto' : 'smooth'/);
});

test('payments-disabled catering uses enquiry-only confirmation language', function () {
	assert.match(catering, /function paymentsEnabled\(\)/);
	assert.match(catering, /availability, the final price and payment arrangement before any payment is taken/);
	assert.match(catering, /Indicative package estimate/);
	var disabledBranch = catering.match(/if \(!paymentsEnabled\(\)\) \{[\s\S]{0,550}?\n\t\t\}/);
	assert.ok(disabledBranch, 'payments-disabled estimate branch exists');
	assert.doesNotMatch(disabledBranch[0], /deposit|secure your date/i);
});
