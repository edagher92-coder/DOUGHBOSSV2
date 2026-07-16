'use strict';

const assert = require('assert');

require('../demo/config/base.js');
require('../demo/config/profiles/doughboss-revesby-launch.js');
require('../demo/config/i18n/en-AU.js');
require('../demo/demo-runtime.js');
require('../demo/demo-fixtures.js');

const launch = globalThis.DBDemo.config;
assert.strictEqual(launch.schemaVersion, 1);
assert.strictEqual(launch.profileId, 'doughboss-revesby-launch');
assert.strictEqual(launch.defaultLocationId, 'revesby');
assert.strictEqual(globalThis.DBDemo.activeLocations().length, 1);
assert.deepStrictEqual(globalThis.DBDemo.enabledFulfilments('revesby'), ['pickup']);
assert.strictEqual(launch.payments.enabled, false);
assert.deepStrictEqual(launch.payments.allowedProviders, ['stripe', 'tyro']);
assert.strictEqual(launch.demo.noExternalWrites, true);

const launchFixtures = globalThis.DBDemoFixtures.build(new Date('2026-07-16T09:00:00+10:00'));
const launchOnline = launchFixtures.filter((order) => order.channel === 'online');
assert.ok(launchOnline.length > 0);
assert.ok(launchOnline.every((order) => order.locationId === 'revesby' && order.fulfilment === 'pickup'));
assert.ok(globalThis.DBDemo.formatMoney(4.5).includes('4.50'));

const universalReference = globalThis.DBDemo.create({
	profileId: 'universal-reference',
	region: { language: 'en-AU', fallbackLanguage: 'en-AU', locale: 'en-IE', currency: 'EUR', timezone: 'Europe/Dublin', direction: 'ltr' },
	defaultLocationId: 'central',
	locations: [
		{ id: 'central', name: 'Central', active: true, fulfilment: { pickup: { enabled: true }, delivery: { enabled: true, addressRequired: true, fee: 4 } } },
		{ id: 'north', name: 'North', active: true, fulfilment: { pickup: { enabled: true }, delivery: { enabled: false } } }
	],
	payments: { enabled: true, allowedProviders: ['stripe', 'tyro'], selectedProvider: 'tyro', allowPayOnPickup: false },
	demo: { noExternalWrites: true, noRealPayment: true, fixtureProfile: 'universal-reference' }
});

assert.doesNotThrow(() => globalThis.DBDemo.validate(universalReference));
assert.strictEqual(universalReference.locations.length, 2);
assert.strictEqual(universalReference.locations[0].fulfilment.delivery.enabled, true);
assert.strictEqual(universalReference.payments.selectedProvider, 'tyro');
assert.ok(universalReference.workflows.delivery.states.out_for_delivery);
const universalRuntime = globalThis.DBDemo.createRuntime(universalReference);
const universalFixtures = globalThis.DBDemoFixtures.build(new Date('2026-07-16T09:00:00+01:00'), universalRuntime);
const universalOnline = universalFixtures.filter((order) => order.channel === 'online');
assert.ok(universalOnline.some((order) => order.locationId === 'north'));
assert.ok(universalOnline.some((order) => order.fulfilment === 'delivery'));
assert.ok(universalRuntime.formatMoney(4.5).includes('€'));
assert.strictEqual(universalRuntime.state({ channel: 'online', fulfilment: 'delivery', status: 'ready' }).action.to, 'out_for_delivery');

const invalidPayment = globalThis.DBDemo.create({
	profileId: 'invalid-payment',
	payments: { enabled: true, allowedProviders: ['stripe', 'tyro'], selectedProvider: 'unknown' }
});
assert.throws(() => globalThis.DBDemo.validate(invalidPayment), /selectedProvider/);

console.log('Demo configuration: launch profile and universal reference passed.');
