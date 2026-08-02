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
