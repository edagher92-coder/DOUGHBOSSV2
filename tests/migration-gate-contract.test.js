const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const gate = fs.readFileSync(
	path.resolve(__dirname, '..', 'tools', 'doughboss-migration-gate', 'doughboss-migration-gate.php'),
	'utf8'
);
const storefront = fs.readFileSync(
	path.resolve(__dirname, '..', 'public', 'js', 'doughboss.js'),
	'utf8'
);

test('migration gate fails closed for public pages and ordering REST routes', () => {
	assert.match(gate, /status_header\( 503 \)/);
	assert.match(gate, /Retry-After: 3600/);
	assert.match(gate, /noindex,nofollow/);
	assert.match(gate, /\/doughboss\/v1\//);
	assert.match(gate, /doughboss_migration_in_progress/);
});

test('signed payment-provider callbacks bypass only the temporary migration gate', () => {
	[
		'/doughboss/v1/stripe-webhook',
		'/doughboss/v1/catering/stripe-webhook',
		'/doughboss/v1/tyro-webhook',
		'/doughboss/v1/catering/tyro-webhook',
		'/doughboss/v1/payments/tyro/webhook',
		'/doughboss/v1/mpgs-notification'
	].forEach((route) => assert.match(gate, new RegExp(route.replaceAll('/', '\\/'))));
	assert.match(gate, /in_array\( \$route, \$provider_callbacks, true \)/);
	assert.doesNotMatch(gate, /strpos\( \$route, ['"]\/doughboss\/v1\/.*webhook/);
});

test('only DoughBoss staff capabilities can bypass the migration gate', () => {
	assert.match(gate, /current_user_can\( 'manage_options' \)/);
	assert.match(gate, /current_user_can\( 'manage_doughboss' \)/);
	assert.match(gate, /current_user_can\( 'manage_doughboss_kds' \)/);
	assert.doesNotMatch(gate, /return is_user_logged_in\(\)/);
});

test('protected storefront reads carry the WordPress REST nonce', () => {
	assert.match(
		storefront,
		/if \(DATA\.nonce\) \{\s*headers\['X-WP-Nonce'\] = DATA\.nonce;/,
		'signed-in preview GET requests must remain authenticated at the REST gate'
	);
	assert.doesNotMatch(
		storefront,
		/options\.method && options\.method !== 'GET'[\s\S]{0,100}X-WP-Nonce/,
		'the nonce must not be limited to write requests'
	);
});
