'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

function squareDashboardHarness(responder) {
	function element(tag = 'div') {
		return { tag, children: [], style: {}, attributes: {}, listeners: {}, textContent: '', value: 'all',
			get firstChild() { return this.children[0] || null; },
			appendChild(child) { this.children.push(child); return child; },
			removeChild(child) { this.children.splice(this.children.indexOf(child), 1); },
			setAttribute(key, value) { this.attributes[key] = value; },
			removeAttribute(key) { delete this.attributes[key]; },
			addEventListener(key, callback) { this.listeners[key] = callback; } };
	}
	const controls = {};
	['preview', 'status-button', 'location', 'status', 'content'].forEach(key => { controls[key] = element(); });
	const root = element();
	root.querySelector = selector => controls[selector.match(/data-square-([^\]]+)/)[1]];
	const requests = [];
	const sessionValues = new Map();
	const window = { sessionStorage: {
		getItem: key => sessionValues.has(key) ? sessionValues.get(key) : null,
		setItem: (key, value) => sessionValues.set(key, String(value)),
		removeItem: key => sessionValues.delete(key)
	}, DoughBossSquareDashboard: { restUrl: '/wp-json/doughboss/v1', nonce: 'synthetic-nonce' },
		fetch: async (url, options) => { requests.push({ url, options }); return responder(url, options); } };
	const document = { getElementById: () => root, createElement: element };
	vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../public/js/doughboss-square-dashboard.js'), 'utf8'), { window, document, Intl, Number, Array, String, Error, encodeURIComponent });
	const textOf = node => [node.textContent, ...node.children.map(textOf)].join(' ');
	return { controls, requests, text: () => textOf(controls.content), settle: () => new Promise(resolve => setImmediate(resolve)) };
}

function squareDashboardPayload(mode = 'status', location = 'all') {
	const payload = { schema_version: 1, source: 'square', data_state: 'not_connected',
		connection: { state: 'not_configured', environment: 'test', credentials_present: false, provider_verified: false },
		readiness: { schema_version: 1, state: 'not_attested', merchant_verification: 'not_attested', location_verification: 'not_attested',
			permissions: 'not_attested', account_eligibility: 'not_attested', sync_state: 'not_recorded', last_successful_sync: null,
			api: { configured_pin: '2025-05-21', timecards_minimum: '2025-05-21', timecards_version_state: 'meets_minimum' },
			capabilities: { payments_refunds: 'not_attested', gift_card_balance_redemption: 'not_attested', gift_card_purchase_delivery: 'not_implemented',
				gift_card_split_checkout: 'disabled', loyalty: 'not_implemented', loyalty_subscription: 'not_attested', catalogue_inventory: 'not_implemented',
				timecards: 'not_implemented', staff_permissions_passcodes_payroll: 'dashboard_only', team_sales_tip_activity_reports: 'unsupported', reporting_costing: 'not_implemented' } },
		staff: { managed_by: 'square', attendance_available: false },
		planned_location: { label: 'Planned location', state: 'private_unmapped' },
		locations: ['revesby', 'bankstown', 'roselands'].map(id => ({ id, name: id, state: 'unmapped' })) };
	if (mode === 'demo') Object.assign(payload, { source: 'synthetic', data_state: 'demo', demo: true, currency: 'AUD', selected_location: location,
		summary: { gross_sales_minor: 450, refunds_minor: 0, net_sales_minor: 450, orders: 1, average_order_minor: 450 },
		location_summaries: [{ id: 'bankstown', name: 'Bankstown', gross_sales_minor: 450, refunds_minor: 0, net_sales_minor: 450, orders: 1, average_order_minor: 450 }],
		sales_series: [{ label: '08:00', gross_sales_minor: 450 }],
		orders: [{ id: 'DEMO-1', location_id: 'bankstown', location_name: 'Bankstown', customer: '<img src=x onerror=alert(1)>', status: 'Ready', amount_minor: 450,
			items: [{ name: 'Zaatar', quantity: 1, unit_price_minor: 450 }] }] });
	return payload;
}

test('Square dashboard starts disconnected and demo needs an explicit action', async () => {
	const harness = squareDashboardHarness(async url => ({ ok: true, json: async () => squareDashboardPayload(url.includes('mode=demo') ? 'demo' : 'status') }));
	await harness.settle();
	assert.match(harness.requests[0].url, /mode=status/);
	assert.equal(harness.requests[0].options.headers['X-WP-Nonce'], 'synthetic-nonce');
	assert.match(harness.text(), /Not configured/);
	assert.doesNotMatch(harness.text(), /Demo orders/);
	assert.equal(harness.controls.location.disabled, true);
	assert.equal(harness.controls['status-button'].hidden, true);
	harness.controls.preview.listeners.click();
	await harness.settle();
	assert.match(harness.text(), /synthetic preview/);
	assert.match(harness.text(), /Team and attendance/);
	assert.match(harness.text(), /Square attendance integration not implemented/);
	assert.match(harness.text(), /No clock controls or attested Square attendance records are provided by this dashboard/);
	assert.doesNotMatch(harness.text(), /attendance integration (?:completed|implemented|verified|attested)|attendance records (?:are )?(?:verified|attested)/i);
	assert.match(harness.text(), /Unannounced/);
	assert.match(harness.text(), /<img src=x onerror=alert\(1\)>/);
	assert.equal(harness.controls.location.disabled, false);
	assert.equal(harness.controls['status-button'].hidden, false);
	harness.controls['status-button'].listeners.click();
	await harness.settle();
	assert.match(harness.requests[2].url, /mode=status/);
	assert.doesNotMatch(harness.text(), /Demo orders/);
	assert.equal(harness.controls['status-button'].hidden, true);
});

test('Square dashboard request failure never falls back to demo figures', async () => {
	const harness = squareDashboardHarness(async () => { throw new Error('network'); });
	await harness.settle();
	assert.match(harness.controls.status.textContent, /could not be loaded/);
	assert.doesNotMatch(harness.text(), /Demo orders|Gross sales/);
	assert.equal(harness.requests.length, 1);
	assert.equal(harness.controls.content.attributes['aria-busy'], undefined);
});

test('Square dashboard refuses a response for the wrong selected shop', async () => {
	const harness = squareDashboardHarness(async url => ({ ok: true, json: async () => squareDashboardPayload(url.includes('mode=demo') ? 'demo' : 'status', 'all') }));
	await harness.settle();
	harness.controls.location.value = 'bankstown';
	harness.controls.preview.listeners.click();
	await harness.settle();
	assert.match(harness.requests[1].url, /location=bankstown/);
	assert.match(harness.controls.status.textContent, /could not be loaded/);
	assert.doesNotMatch(harness.text(), /Demo orders/);
});

test('Square dashboard retry recovers connection status without substituting demo data', async () => {
	let attempts = 0;
	const harness = squareDashboardHarness(async () => {
		if (++attempts === 1) throw new Error('network');
		return { ok: true, json: async () => squareDashboardPayload() };
	});
	await harness.settle();
	const retry = harness.controls.content.children.find(child => child.tag === 'button');
	assert.ok(retry, 'API failure offers a retry control');
	retry.listeners.click();
	await harness.settle();
	assert.equal(attempts, 2);
	assert.match(harness.controls.status.textContent, /status loaded/);
	assert.match(harness.text(), /Not configured/);
	assert.doesNotMatch(harness.text(), /Demo orders/);
});

test('Square dashboard distinguishes configured mappings without treating them as connected data', async () => {
	const configuredPayload = squareDashboardPayload();
	configuredPayload.connection = { state: 'configured_unverified', environment: 'test', credentials_present: true, provider_verified: false };
	configuredPayload.locations = [
		{ id: 'revesby', name: 'revesby', state: 'unmapped' },
		{ id: 'bankstown', name: 'bankstown', state: 'configured_unverified' }
	];
	const configured = squareDashboardHarness(async () => ({ ok: true, json: async () => configuredPayload }));
	await configured.settle();
	assert.match(configured.text(), /Mapped, not verified/);
	assert.doesNotMatch(configured.text(), /Demo orders|Gross sales|Net sales|Average order/);

	const unsupportedPayload = squareDashboardPayload();
	unsupportedPayload.locations[0].state = 'connected';
	const unsupported = squareDashboardHarness(async () => ({ ok: true, json: async () => unsupportedPayload }));
	await unsupported.settle();
	assert.match(unsupported.controls.status.textContent, /could not be loaded/);
	assert.doesNotMatch(unsupported.text(), /Demo orders|Gross sales|Net sales|Average order/);
});

function extractFunction(file, name, context) {
	const source = fs.readFileSync(file, 'utf8');
	const marker = 'function ' + name + '(';
	const start = source.indexOf(marker);
	assert.notEqual(start, -1, name + ' must exist in ' + path.basename(file));
	let depth = 0;
	let bodyStarted = false;
	let end = -1;
	for (let index = start; index < source.length; index += 1) {
		if (source[index] === '{') {
			bodyStarted = true;
			depth += 1;
		} else if (source[index] === '}' && bodyStarted) {
			depth -= 1;
			if (depth === 0) {
				end = index + 1;
				break;
			}
		}
	}
	assert.notEqual(end, -1, name + ' must have a complete function body');
	return vm.runInNewContext('(' + source.slice(start, end) + ')', context || {});
}

const storefront = path.resolve(__dirname, '..', 'public', 'js', 'doughboss.js');
const orderboard = path.resolve(__dirname, '..', 'public', 'js', 'doughboss-orderboard.js');
const square = path.resolve(__dirname, '..', 'public', 'js', 'doughboss-square.js');
const shopStatus = path.resolve(__dirname, '..', 'public', 'js', 'doughboss-shop-status.js');
const catering = path.resolve(__dirname, '..', 'public', 'js', 'doughboss-catering.js');

function storefrontElement(tag = 'div') {
	const node = {
		tag,
		children: [],
		attributes: {},
		className: '',
		textContent: '',
		parentNode: null,
		listeners: {},
		style: { setProperty() {} },
		classList: { add() {}, remove() {}, toggle() {} },
		addEventListener(type, callback) {
			this.listeners[type] = callback;
		},
		getBoundingClientRect() {
			return { top: 0, bottom: 64, height: 64 };
		},
		removeAttribute(key) {
			delete this.attributes[key];
		},
		appendChild(child) {
			child.parentNode = this;
			this.children.push(child);
			return child;
		},
		removeChild(child) {
			this.children.splice(this.children.indexOf(child), 1);
			child.parentNode = null;
		},
		setAttribute(key, value) {
			this.attributes[key] = String(value);
		},
		getAttribute(key) {
			return this.attributes[key] || null;
		},
		querySelector(selector) {
			const match = /^\[([^\]]+)\]$/.exec(selector);
			if (!match) return null;
			const key = match[1];
			for (const child of this.children) {
				if (Object.hasOwn(child.attributes, key)) return child;
				const descendant = child.querySelector(selector);
				if (descendant) return descendant;
			}
			return null;
		}
	};
	Object.defineProperty(node, 'innerHTML', {
		set() {
			node.children.forEach(child => { child.parentNode = null; });
			node.children = [];
		}
	});
	return node;
}

function storefrontCreate(tag, attrs, children) {
	const node = storefrontElement(tag);
	for (const [key, value] of Object.entries(attrs || {})) {
		if (key === 'class') node.className = value;
		else if (key === 'text') node.textContent = value;
		else if (key === 'id') node.id = value;
		else node.setAttribute(key, value);
	}
	for (const child of children || []) node.appendChild(child);
	return node;
}

function responsiveMenuItem() {
	const prefix = 'https://example.test/plugin/public/images/';
	return {
		name: 'Spinach Pie & cheese', image: prefix + 'menu/real-v1/spinach-pie.jpg',
		image_responsive: {
			original: prefix + 'menu/real-v1/spinach-pie.jpg', width: 1080, height: 864,
			sources: ['avif', 'webp'].map(format => ({ type: 'image/' + format,
				srcset: [480, 800].map(width => `${prefix}responsive/spinach-pie-${width}.${format} ${width}w`).join(', ') }))
		}
	};
}

test('menu photographs deliver local responsive sources and a dimensioned original fallback', () => {
	const menuPhoto = extractFunction(storefront, 'menuPhoto', { el: storefrontCreate });
	const item = responsiveMenuItem();
	const photo = menuPhoto(item);
	assert.equal(photo.attributes.style, undefined, 'no parallel original CSS background download');
	assert.equal(photo.className, 'db-card-img');
	assert.equal(photo.children[0].tag, 'picture');
	const [avif, webp, image] = photo.children[0].children;
	assert.equal(avif.attributes.type, 'image/avif');
	assert.equal(webp.attributes.type, 'image/webp');
	assert.equal(avif.attributes.srcset, item.image_responsive.sources[0].srcset);
	assert.match(avif.attributes.sizes, /max-width: 600px/);
	assert.equal(image.tag, 'img');
	assert.equal(image.attributes.src, item.image);
	assert.equal(image.attributes.alt, item.name);
	assert.equal(image.attributes.width, '1080');
	assert.equal(image.attributes.height, '864');
	assert.equal(image.attributes.loading, 'lazy');
	assert.equal(image.attributes.decoding, 'async');
});

test('menu photographs retain originals for absent, unrelated or malformed candidates', () => {
	const menuPhoto = extractFunction(storefront, 'menuPhoto', { el: storefrontCreate });
	const mutations = [
		item => { delete item.image_responsive; },
		item => { item.image_responsive = null; },
		item => { item.image = 'https://uploads.example.test/featured.jpg'; },
		item => { item.image_responsive.original += '?changed'; },
		item => { item.image_responsive.width = '1080'; },
		item => { item.image_responsive.height = 0; },
		item => { item.image_responsive.sources = []; },
		item => { item.image_responsive.sources = [null]; },
		item => { item.image_responsive.sources[0].type = 'image/svg+xml'; },
		item => { item.image_responsive.sources[1].type = 'image/avif'; },
		item => { item.image_responsive.sources[0].srcset = ''; },
		item => { item.image_responsive.sources[0].srcset = 42; },
		item => { item.image_responsive.sources[0].srcset = 'https://other.example.test/public/images/responsive/spinach-pie-480.avif 480w'; },
		item => { item.image_responsive.sources[0].srcset = 'https://example.test/plugin/public/images/responsive/../private-480.avif 480w'; },
		item => { item.image_responsive.sources[0].srcset = 'https://example.test/plugin/public/images/responsive/spinach-pie-480.webp 480w'; },
		item => { item.image_responsive.sources[0].srcset = 'https://example.test/plugin/public/images/responsive/spinach-pie-480.avif 800w'; },
		item => { item.image_responsive.width = 479; },
		item => { item.image_responsive.sources[0].srcset += ', https://example.test/plugin/public/images/responsive/spinach-pie-480.avif 480w'; },
		item => { item.image_responsive.sources[0].srcset += ', '; }
	];
	for (const mutate of mutations) {
		const item = responsiveMenuItem();
		mutate(item);
		const photo = menuPhoto(item);
		assert.equal(photo.children.length, 0, 'invalid data never partly enhances');
		assert.equal(photo.attributes.style, 'background-image:url(' + item.image + ')', 'original delivery is preserved');
	}
});

// These excerpt tests model an unselected shop and a browser without SVG DOM
// support. Keep the current rendering helpers real rather than omitting them.
function menuBrowserContext() {
	const context = {
		getLocations: () => Promise.resolve([]),
		menuLocationId: () => 0,
		el: storefrontCreate,
		document: { addEventListener() {} }
	};
	for (const name of ['svgIcon', 'compactMenuCategory', 'syncPhoneOrderBar', 'watchMenuAvailability']) {
		context[name] = extractFunction(storefront, name, context);
	}
	return context;
}

function menuEnhancementHarness(overrides = {}) {
	const root = storefrontElement();
	const fallback = storefrontCreate('div', { 'data-doughboss-menu-fallback': '' });
	const browseItem = storefrontCreate('li', { text: 'Published browse-only item $4.50' });
	fallback.appendChild(browseItem);
	root.appendChild(fallback);
	const showMenuFallbackError = extractFunction(storefront, 'showMenuFallbackError', { I18N: {}, el: storefrontCreate });
	const context = {
		...menuBrowserContext(),
		Promise,
		request: () => Promise.resolve(overrides.items || [{ name: 'Zaatar', category: 'Manoush' }]),
		getConfig: () => Promise.resolve({ ordering_open: false }),
		activeTableContext: () => null,
		el: storefrontCreate,
		I18N: {},
		catId: extractFunction(storefront, 'catId'),
		menuCard: item => storefrontCreate('article', { class: 'db-card', text: item.name }),
		initCartFab() {},
		window: { addEventListener() {}, location: { hash: '' }, scrollY: 0 },
		showMenuFallbackError,
		...overrides
	};
	const render = extractFunction(storefront, 'renderMenu', context);
	return { root, fallback, browseItem, render: () => render(root), settle: () => new Promise(resolve => setImmediate(resolve)) };
}

test('non-empty menu enhancement supports prototype-named WordPress categories', async () => {
	const categories = ['constructor', '__proto__', 'toString', 'hasOwnProperty', 'Manoush'];
	const harness = menuEnhancementHarness({ items: categories.map((category, index) => ({ name: 'Item ' + index, category })) });
	harness.render();
	await harness.settle();
	assert.equal(harness.root.querySelector('[data-doughboss-menu-fallback]'), null, 'successful non-empty enhancement replaces the browse fallback');
	const headings = harness.root.children.filter(child => child.className === 'db-category');
	const grids = harness.root.children.filter(child => child.className === 'db-grid');
	assert.deepEqual(headings.map(heading => heading.textContent).sort(), categories.slice().sort());
	assert.equal(grids.length, categories.length);
	assert.deepEqual(grids.flatMap(grid => grid.children.map(card => card.textContent)).sort(), categories.map((category, index) => 'Item ' + index).sort());
	assert.equal(harness.root.getAttribute('data-ordering-open'), 'false');
});

test('menu restores the original browse fallback after a late card-rendering failure', async () => {
	let cardsRendered = 0;
	const harness = menuEnhancementHarness({
		items: [{ name: 'First item', category: 'Manoush' }, { name: 'Second item', category: 'Manoush' }],
		getConfig: () => Promise.resolve({ ordering_open: true }),
		menuCard: item => {
			cardsRendered += 1;
			if (cardsRendered === 2) throw new Error('late card rendering failure');
			return storefrontCreate('article', { class: 'db-card', text: item.name });
		}
	});
	harness.render();
	await harness.settle();
	assert.equal(cardsRendered, 2, 'the fault occurs after enhancement has already started');
	assert.deepEqual(harness.root.children, [harness.fallback], 'partial interactive content is removed');
	assert.equal(harness.fallback.children[0], harness.browseItem, 'the original server-rendered content survives');
	assert.ok(harness.fallback.querySelector('[data-doughboss-menu-availability]'));
	assert.equal(harness.root.getAttribute('data-ordering-open'), 'false', 'failed enhancement cannot leave an enabled ordering surface');
});

test('menu restores browse content when the last enhancement step fails', async () => {
	let reachedFinalStep = false;
	const harness = menuEnhancementHarness({
		initCartFab() { reachedFinalStep = true; throw new Error('cart cue initialization failed'); }
	});
	harness.render();
	await harness.settle();
	assert.equal(reachedFinalStep, true);
	assert.deepEqual(harness.root.children, [harness.fallback]);
	assert.equal(harness.fallback.children[0], harness.browseItem);
	assert.ok(harness.fallback.querySelector('[data-doughboss-menu-availability]'));
});

test('failed config reads keep browse content and a missing fallback is described honestly', async () => {
	const harness = menuEnhancementHarness({ getConfig: () => Promise.reject(new Error('config unavailable')) });
	harness.render();
	await harness.settle();
	assert.equal(harness.root.querySelector('[data-doughboss-menu-fallback]'), harness.fallback);
	assert.equal(harness.fallback.children[0], harness.browseItem);
	const rootWithoutFallback = storefrontElement();
	const showError = extractFunction(storefront, 'showMenuFallbackError', { I18N: {}, el: storefrontCreate });
	showError(rootWithoutFallback, new Error('unavailable'));
	assert.doesNotMatch(rootWithoutFallback.children[0].textContent, /browse.*below/i);
	assert.match(rootWithoutFallback.children[0].textContent, /contact the shop/i);
});

test('menu fallback survives failed interactive reads and is replaced only by successful enhancement', async () => {
	const showMenuFallbackError = extractFunction(storefront, 'showMenuFallbackError', { I18N: {}, el: storefrontCreate });
	const fallbackRoot = storefrontElement();
	const fallback = storefrontElement();
	fallback.setAttribute('data-doughboss-menu-fallback', '');
	const browseItem = storefrontElement('li');
	browseItem.textContent = 'Published browse-only item';
	fallback.appendChild(browseItem);
	fallbackRoot.appendChild(fallback);
	const failedRender = extractFunction(storefront, 'renderMenu', {
		...menuBrowserContext(),
		Promise,
		request: () => Promise.reject(new Error('network')),
		getConfig: () => Promise.resolve({ ordering_open: false }),
		showMenuFallbackError,
		activeTableContext: () => null,
		el: storefrontCreate,
		I18N: {}
	});
	failedRender(fallbackRoot);
	await new Promise(resolve => setImmediate(resolve));
	assert.equal(fallbackRoot.querySelector('[data-doughboss-menu-fallback]'), fallback);
	assert.equal(fallback.children[0], browseItem);
	const availability = fallback.querySelector('[data-doughboss-menu-availability]');
	assert.ok(availability, 'failed reads add an availability notice to, not instead of, the browse fallback');
	assert.equal(availability.attributes.role, 'status');
	assert.equal(availability.attributes['aria-live'], 'polite');
	showMenuFallbackError(fallbackRoot, new Error('retry failed'));
	assert.equal(fallback.children.filter(child => Object.hasOwn(child.attributes, 'data-doughboss-menu-availability')).length, 1, 'repeated failures keep one notice');

	const enhancedRoot = storefrontElement();
	const enhancedFallback = storefrontElement();
	enhancedFallback.setAttribute('data-doughboss-menu-fallback', '');
	enhancedRoot.appendChild(enhancedFallback);
	const successfulRender = extractFunction(storefront, 'renderMenu', {
		...menuBrowserContext(),
		Promise,
		request: path => {
			assert.equal(path, '/menu');
			return Promise.resolve([]);
		},
		getConfig: () => Promise.resolve({ ordering_open: false }),
		activeTableContext: () => null,
		el: storefrontCreate,
		I18N: {}
	});
	successfulRender(enhancedRoot);
	await new Promise(resolve => setImmediate(resolve));
	assert.equal(enhancedRoot.querySelector('[data-doughboss-menu-fallback]'), null, 'a successful enhancement removes fallback content before rendering');
	assert.equal(enhancedRoot.children.length, 1, 'successful enhancement leaves one menu experience');
	assert.equal(enhancedRoot.children[0].className, 'db-empty');
});

const prettyUrl = extractFunction(storefront, 'restRequestUrl', {
	DATA: { restUrl: 'https://example.test/wp-json/doughboss/v1' }
});
assert.equal(
	prettyUrl('/cart?order_type=pickup'),
	'https://example.test/wp-json/doughboss/v1/cart?order_type=pickup',
	'pretty REST routes retain their first query separator'
);

const plainUrl = extractFunction(storefront, 'restRequestUrl', {
	DATA: { restUrl: 'https://example.test/index.php?rest_route=/doughboss/v1' }
});
assert.equal(
	plainUrl('/cart?order_type=pickup'),
	'https://example.test/index.php?rest_route=/doughboss/v1/cart&order_type=pickup',
	'plain-permalink REST routes append endpoint parameters with an ampersand'
);
assert.equal(
	plainUrl('/locations'),
	'https://example.test/index.php?rest_route=/doughboss/v1/locations',
	'plain-permalink REST routes without endpoint parameters remain unchanged'
);

const dietaryBadges = extractFunction(storefront, 'dietaryBadges');
function localBadges(value) {
	return JSON.parse(JSON.stringify(dietaryBadges(value)));
}
assert.deepEqual(localBadges(null), [], 'missing dietary flags render no badges');
assert.deepEqual(localBadges({ vegan: true }), [], 'invalid dietary input renders no badges');
assert.deepEqual(
	localBadges(['vegetarian', 'VEGAN', 'halal', 'gluten_free', 'vegetarian', 'unknown']),
	[
		{ value: 'vegetarian', label: 'V Vegetarian' },
		{ value: 'vegan', label: 'VG Vegan' },
		{ value: 'halal', label: 'Halal' },
		{ value: 'gluten_free', label: 'GF Gluten-free' }
	],
	'allowlisted dietary flags are normalised and deduplicated'
);
assert.deepEqual(localBadges(['gluten_free']), [{ value: 'gluten_free', label: 'GF Gluten-free' }], 'gluten-free is shown only when explicitly supplied as an item flag');
assert.deepEqual(localBadges(['vegetarian']), [{ value: 'vegetarian', label: 'V Vegetarian' }], 'vegetarian remains distinct from gluten-free');
assert.deepEqual(localBadges(['__proto__', 'constructor', 'toString', 3, false, '  VEGAN ']), [{ value: 'vegan', label: 'VG Vegan' }], 'prototype names and non-string flags cannot become claims');

const pickupStatusText = extractFunction(shopStatus, 'pickupStatusText');
const observed = Date.parse('2026-09-08T01:00:00Z');
const openConfig = { ordering_open: true, enable_pickup: true };
function shop(state, changes) {
	return { pickup_enabled: true, pickup_status: Object.assign({ state, observed_at_utc: '2026-09-08T01:00:00Z', expires_at_utc: '2026-09-08T01:01:00Z' }, changes) };
}
assert.match(pickupStatusText(shop('open'), openConfig, observed), /open now/, 'fresh configured pickup hours are open');
assert.match(pickupStatusText(shop('closes_soon'), openConfig, observed), /closing soon/, 'closing soon is distinct from open');
assert.match(pickupStatusText(shop('open'), { ordering_open: false }, observed), /ordering is paused/, 'global pause takes precedence over open hours');
assert.match(pickupStatusText({ pickup_enabled: false }, openConfig, observed), /unavailable/, 'disabled pickup cannot be offered');
assert.match(pickupStatusText(shop('closed', { next_open_label: 'Wednesday 6:30am' }), openConfig, observed), /Wednesday 6:30am\. Not a pre-order booking/, 'next opening must not promise a booked pre-order');
assert.match(pickupStatusText(shop('open'), openConfig, observed + 60000), /unconfirmed/, 'expired hours fail closed at the exact expiry');
assert.match(pickupStatusText(shop('open', { expires_at_utc: 'invalid' }), openConfig, observed), /unconfirmed/, 'malformed expiry fails closed');
assert.match(pickupStatusText(shop('open', { expires_at_utc: '2026-09-08T01:05:00Z' }), openConfig, observed), /unconfirmed/, 'long-lived hours evidence is rejected');
assert.match(pickupStatusText(shop('open'), openConfig, observed - 60000), /unconfirmed/, 'future-dated evidence is not treated as current');
assert.match(pickupStatusText(shop('invented'), openConfig, observed), /unconfirmed/, 'unknown state does not imply an open shop');

let expiryDelay;
let clearedTimer;
const scheduleTick = extractFunction(shopStatus, 'scheduleTick', {
	tickTimer: 19,
	watches: [{ location: shop('open', { expires_at_utc: '2026-09-08T01:00:05Z' }) }],
	Date: { now: () => observed, parse: Date.parse },
	clearTimeout: id => { clearedTimer = id; },
	setTimeout: (callback, delay) => { expiryDelay = delay; return 20; },
	tick: () => {}
});
scheduleTick();
assert.equal(clearedTimer, 19, 'newly attached short-lived evidence reschedules the existing timer');
assert.equal(expiryDelay, 5020, 'the status expires near its deadline rather than waiting for the next 15-second refresh');

test('public shop reads retain signed cookies without imposing a stale WP nonce', async () => {
	let requested;
	const readShop = extractFunction(shopStatus, 'read', {
		data: { restUrl: 'https://example.test/?rest_route=/doughboss/v1', nonce: 'synthetic-expired-nonce' },
		fetch: (url, options) => { requested = { url, options }; return Promise.resolve({ ok: true, json: () => Promise.resolve({ active: false }) }); }
	});
	await readShop('/table/context');
	assert.equal(requested.url, 'https://example.test/?rest_route=/doughboss/v1/table/context');
	assert.equal(requested.options.credentials, 'same-origin');
	assert.equal(requested.options.cache, 'no-store');
	assert.equal(requested.options.headers, undefined, 'public-only reads must not send an expired X-WP-Nonce');
});

const retrySafe = extractFunction(square, 'isRetrySafeOutcome');
assert.equal(retrySafe({ retry_safe: true, payment_pending: false }), true, 'validated terminal no-charge outcomes unlock retry');
assert.equal(retrySafe({ retry_safe: true, payment_pending: true }), false, 'contradictory pending evidence remains locked');
assert.equal(retrySafe({ retry_safe: false, payment_pending: false }), false, 'unknown provider outcomes remain locked');
assert.equal(retrySafe({}), false, 'missing provider evidence remains locked');

function squarePaymentHarness(options) {
	options = options || {};
	const requests = [];
	const stored = [];
	const placed = [];
	const tokenizations = [];
	const fields = {
		customer_name: 'Ada Customer',
		customer_email: 'ada@example.test',
		customer_phone: '0400 000 000'
	};
	const form = {
		querySelector: selector => {
			const match = /^\[name="([^"]+)"\]$/.exec(selector);
			return match && Object.prototype.hasOwnProperty.call(fields, match[1]) ? { value: fields[match[1]] } : null;
		}
	};
	const validCurrency = extractFunction(square, 'validSquareCurrency');
	const verificationDetailsFor = extractFunction(square, 'verificationDetailsFor', {
		CONFIG: { currency: options.currency },
		I18N: {},
		readDisplayedTotal: () => options.total,
		validSquareCurrency: validCurrency,
		value: (node, name) => fields[name] || ''
	});
	const payAndPlace = extractFunction(square, 'payAndPlace', {
		tokenizeTender: extractFunction(square, 'tokenizeTender'),
		I18N: {},
		orderTypeFor: () => 'pickup',
		storedLocationId: () => 12,
		verificationDetailsFor,
		value: (node, name) => fields[name] || '',
		storeSession: session => stored.push({ providerRequestStarted: session.providerRequestStarted, paymentId: session.paymentId }),
		request: (route, requestOptions) => {
			requests.push({ route, requestOptions });
			return Promise.resolve(options.paymentResponse || { payment_intent: 'sq-payment-1' });
		},
		placeOrder: (session, orderType, locationId) => {
			placed.push({ session, orderType, locationId });
			return Promise.resolve({ order_id: 44 });
		}
	});
	const session = {
		form,
		paymentAttemptKey: 'attempt-1',
		paymentId: options.paymentId || '',
		providerRequestStarted: false,
		controller: {
			ready: () => Promise.resolve({
				kind: options.tenderKind || 'card',
				card: {
					tokenize: details => {
						tokenizations.push(details);
						if (options.tokenizeError) { return Promise.reject(options.tokenizeError); }
						return Promise.resolve(options.tokenResult);
					}
				}
			})
		}
	};
	return { payAndPlace, session, requests, stored, placed, tokenizations };
}

test('Square puts buyer verification details into tokenization and sends only its resulting token', async () => {
	const h = squarePaymentHarness({
		total: '27.50',
		currency: 'AUD',
		tokenResult: { status: 'OK', token: 'cnon:browser-token' }
	});
	await h.payAndPlace(h.session);
	assert.deepEqual(JSON.parse(JSON.stringify(h.tokenizations)), [{
		amount: '27.50',
		billingContact: { givenName: 'Ada', familyName: 'Customer', email: 'ada@example.test', phone: '0400 000 000' },
		currencyCode: 'AUD',
		intent: 'CHARGE',
		customerInitiated: true,
		sellerKeyedIn: false
	}]);
	assert.equal(h.requests.length, 1);
	assert.equal(h.requests[0].route, '/payment-intent');
	assert.equal(h.requests[0].requestOptions.body.source_id, 'cnon:browser-token');
	assert.equal(Object.prototype.hasOwnProperty.call(h.requests[0].requestOptions.body, 'verification_token'), false);
	assert.equal(h.session.providerRequestStarted, true);
	assert.equal(h.placed.length, 1, 'a confirmed server payment still proceeds to checkout');
	const source = fs.readFileSync(square, 'utf8');
	assert.doesNotMatch(source, /verifyBuyer|verificationToken|verification_token/);
});

test('Square full-value gift checkout sends only the hosted-field nonce and tender selection', async () => {
	const h = squarePaymentHarness({ total: '27.50', currency: 'AUD', tenderKind: 'gift_card', tokenResult: { status: 'OK', token: 'cnon:gift-secure-nonce' } });
	await h.payAndPlace(h.session);
	assert.deepEqual(h.tokenizations, [undefined], 'gift SDK tokenize takes no bank-card buyer verification details');
	assert.equal(h.requests[0].requestOptions.body.tender_kind, 'gift_card');
	assert.equal(h.requests[0].requestOptions.body.source_id, 'cnon:gift-secure-nonce');
	assert.equal(JSON.stringify(h.stored).includes('cnon:'), false, 'nonce never enters recovery storage');
	assert.equal(h.placed.length, 1);
});

test('Square cancelled, failed, or rejected tokenization never starts a provider request', async () => {
	for (const outcome of [
		{ label: 'cancelled challenge', tokenResult: { status: 'CANCELED' } },
		{ label: 'failed challenge', tokenResult: { status: 'FAILED', errors: [{ message: 'Authentication failed' }] } },
		{ label: 'rejected challenge', tokenizeError: new Error('Authentication rejected') }
	]) {
		const h = squarePaymentHarness(Object.assign({ total: '27.50', currency: 'AUD' }, outcome));
		await assert.rejects(h.payAndPlace(h.session), undefined, outcome.label);
		assert.equal(h.tokenizations.length, 1, outcome.label + ' reaches tokenization only');
		assert.equal(h.requests.length, 0, outcome.label + ' cannot call /payment-intent');
		assert.equal(h.placed.length, 0, outcome.label + ' cannot call /checkout');
		assert.equal(h.session.providerRequestStarted, false, outcome.label + ' does not lock an unstarted provider request');
		assert.equal(h.stored.length, 0, outcome.label + ' does not persist a provider request');
	}
});

test('Square rejects absent display total and malformed currency before tokenization', async () => {
	for (const options of [
		{ label: 'missing total', total: null, currency: 'AUD' },
		{ label: 'bad currency', total: '27.50', currency: 'aud' },
		{ label: 'missing currency', total: '27.50', currency: '' }
	]) {
		const h = squarePaymentHarness(Object.assign({ tokenResult: { status: 'OK', token: 'cnon:ignored' } }, options));
		await assert.rejects(h.payAndPlace(h.session), undefined, options.label);
		assert.equal(h.tokenizations.length, 0, options.label + ' cannot obtain a token');
		assert.equal(h.requests.length, 0, options.label + ' cannot call /payment-intent');
		assert.equal(h.placed.length, 0, options.label + ' cannot call /checkout');
		assert.equal(h.session.providerRequestStarted, false, options.label + ' leaves provider state unstarted');
	}
});

test('Square replay with a known payment skips browser tokenization', async () => {
	const h = squarePaymentHarness({ paymentId: 'sq-existing-payment' });
	await h.payAndPlace(h.session);
	assert.equal(h.tokenizations.length, 0);
	assert.equal(h.requests.length, 0);
	assert.equal(h.placed.length, 1);
	assert.equal(h.session.providerRequestStarted, false);
});

const validCateringQuote = extractFunction(catering, 'validCateringQuote');
function cateringQuote(total) {
	return { subtotal: total, delivery_fee: 0, total, deposit_pct: 30, deposit: total * 0.3, balance: total * 0.7, lead_days: 2, currency: 'AUD' };
}

function approvedSquareHarness(options = {}) {
	const requests = [];
	const tokenizations = [];
	const summary = { gateway: 'square', total: 14, currency: 'AUD', square: { application_id: 'sq-app-1', location_id: 'sq-shop-bankstown', live_mode: false } };
	const session = {
		summary,
		capability: { requestId: 91, token: 'a'.repeat(64) },
		paymentAttemptKey: 'approved-attempt-1',
		providerRequestStarted: !!options.pending,
		controller: { ready: () => Promise.resolve({ card: { tokenize: details => {
			tokenizations.push(details);
			return Promise.resolve(options.tokenResult || { status: 'OK', token: 'cnon:approved' });
		} }, kind: options.tenderKind || 'card' }) }
	};
	const request = async (route, requestOptions) => {
		requests.push({ route, options: requestOptions });
		if (options.failStart && route === '/preorder-payment/start') { throw new Error('Synthetic lost response'); }
		return route === '/preorder-payment/start' ? { gateway: 'square', payment_intent: 'sq-provider-id' } : (Object.hasOwn(options, 'receipt') ? options.receipt : { success: true, order_number: 'DB-261001-ABC123', total: 14 });
	};
	const confirm = extractFunction(square, 'confirmApproved', { request, validApprovedSquareReceipt: extractFunction(square, 'validApprovedSquareReceipt') });
	const pay = extractFunction(square, 'payApprovedSession', {
		tokenizeTender: extractFunction(square, 'tokenizeTender'),
		approvedVerificationDetails: extractFunction(square, 'approvedVerificationDetails', { validSquareCurrency: extractFunction(square, 'validSquareCurrency') }),
		confirmApproved: confirm, request
	});
	return { session, requests, tokenizations, pay };
}

test('approved Square uses the capability routes and no client money or provider identity', async () => {
	const h = approvedSquareHarness();
	const paid = await h.pay(h.session);
	assert.equal(paid.order_number, 'DB-261001-ABC123');
	assert.equal(h.tokenizations[0].amount, '14.00');
	assert.equal(h.tokenizations[0].currencyCode, 'AUD');
	assert.deepEqual(h.requests.map(call => call.route), ['/preorder-payment/start', '/preorder-payment/complete']);
	assert.deepEqual(Object.keys(h.requests[0].options.body).sort(), ['payment_attempt_key', 'request_id', 'source_id', 'tender_kind', 'token']);
	assert.deepEqual(Object.keys(h.requests[1].options.body).sort(), ['request_id', 'token']);
	assert.equal(h.requests[0].options.body.source_id, 'cnon:approved');
	assert.equal(h.session.providerRequestStarted, true);
});

test('approved Square unknown start outcome retries only confirmation without another token or POST', async () => {
	const h = approvedSquareHarness({ failStart: true });
	await assert.rejects(h.pay(h.session), /lost response/);
	await h.pay(h.session);
	assert.deepEqual(h.requests.map(call => call.route), ['/preorder-payment/start', '/preorder-payment/complete']);
	assert.equal(h.tokenizations.length, 1);
	assert.equal(h.session.paymentAttemptKey, 'approved-attempt-1');
});

test('approved Square gift capture retains the exact approval routes and no new charge on recovery', async () => {
	const h = approvedSquareHarness({ tenderKind: 'gift_card' });
	await h.pay(h.session);
	assert.deepEqual(h.tokenizations, [undefined]);
	assert.equal(h.requests[0].options.body.tender_kind, 'gift_card');
	assert.equal(h.requests[0].options.body.source_id, 'cnon:approved');
	await h.pay(h.session);
	assert.equal(h.tokenizations.length, 1);
	assert.deepEqual(h.requests.map(x => x.route), ['/preorder-payment/start', '/preorder-payment/complete', '/preorder-payment/complete']);
});

test('approved Square reload recovery never tokenizes or starts a new payment', async () => {
	const h = approvedSquareHarness({ pending: true });
	await h.pay(h.session);
	assert.equal(h.tokenizations.length, 0);
	assert.deepEqual(h.requests.map(call => call.route), ['/preorder-payment/complete']);
});

test('approved Square cancelled or failed card verification makes no server payment call', async () => {
	for (const status of ['CANCELED', 'FAILED', 'OK']) {
		const h = approvedSquareHarness({ tokenResult: { status } });
		await assert.rejects(h.pay(h.session), /verification was not completed/);
		assert.equal(h.requests.length, 0);
		assert.equal(h.session.providerRequestStarted, false);
	}
});

test('approved Square rejects invalid public shop bindings and never uses the global shop', () => {
	const binding = extractFunction(square, 'approvedSquareBinding');
	const summary = { gateway: 'square', square: { application_id: 'sq-app-1', location_id: 'sq-bankstown-1', live_mode: false } };
	assert.deepEqual(JSON.parse(JSON.stringify(binding(summary))), { applicationId: 'sq-app-1', locationId: 'sq-bankstown-1', liveMode: false });
	for (const value of [null, { gateway: 'stripe', square: summary.square }, { gateway: 'square', square: { ...summary.square, location_id: '' } }, { gateway: 'square', square: { ...summary.square, live_mode: 'false' } }]) {
		assert.throws(() => binding(value), /shop payment details/);
	}
});

test('approved Square SDK stays dormant on environment mismatch and uses only the exact CDN', async () => {
	let scripts = 0;
	const load = extractFunction(square, 'loadApprovedSquareSdk', {
		CONFIG: { sdkUrl: 'https://sandbox.web.squarecdn.com/v1/square.js' },
		window: {}, document: { createElement: () => { scripts++; return {}; } }, sdkPromise: null
	});
	await assert.rejects(load({ liveMode: true }), /environment changed/);
	assert.equal(scripts, 0);
});

function dormantSquareModuleHarness(harnessOptions = {}) {
	const calls = [];
	const sdkBindings = [];
	const scripts = [];
	const tokenizations = [];
	const fieldStarts = { card: 0, gift: 0 };
	function element(tag = 'div') {
		return { tag, children: [], attributes: {}, className: '', parentNode: null, listeners: {},
			addEventListener(name, listener) { this.listeners[name] = listener; },
			appendChild(child) { child.parentNode = this; this.children.push(child); return child; },
			insertBefore(child, target) { child.parentNode = this; this.children.splice(this.children.indexOf(target), 0, child); return child; },
			setAttribute(key, value) { this.attributes[key] = value; },
			querySelector(selector) { return selector === 'button[type="submit"]' ? this.children.find(child => child.tag === 'button') : null; }
		};
	}
	const window = {
		DoughBossData: { payments: { enabled: harnessOptions.ordinaryEnabled === true, gateway: 'square' }, restUrl: '/wp-json/doughboss/v1', currency: '$' },
		DoughBossSquareConfig: {
			applicationId: harnessOptions.ordinaryEnabled === true ? 'sq-app-test' : '',
			locationId: harnessOptions.ordinaryEnabled === true ? 'sq-bankstown-test' : '',
			currency: harnessOptions.ordinaryEnabled === true ? 'AUD' : '',
			sdkUrl: 'https://sandbox.web.squarecdn.com/v1/square.js'
		},
		crypto: { randomUUID: () => 'synthetic-stable-key' }
	};
	const document = { readyState: 'loading', createElement: element, addEventListener() {},
		querySelector: selector => selector === 'input[name="db-order-type"]:checked' ? { value: 'pickup' } : null,
		head: { appendChild(script) {
			scripts.push(script);
			window.Square = { payments(applicationId, locationId) {
				sdkBindings.push({ applicationId, locationId });
				return { giftCard: () => {
					fieldStarts.gift++;
					if (fieldStarts.gift <= (harnessOptions.giftFailures || 0)) { return Promise.reject(new Error('Synthetic gift mount failure')); }
					return Promise.resolve({ attach: () => Promise.resolve(), tokenize: () => {
					tokenizations.push(undefined);
					return Promise.resolve({ status: 'OK', token: 'cnon:gift-synthetic-secure' });
				} }); }, card: () => {
					fieldStarts.card++;
					if (fieldStarts.card <= (harnessOptions.cardFailures || 0)) { return Promise.reject(new Error('Synthetic card mount failure')); }
					return Promise.resolve({ attach: () => Promise.resolve(), tokenize: details => {
					tokenizations.push(details);
					return Promise.resolve({ status: 'OK', token: 'cnon:synthetic-card' });
				} }); } };
			} };
			script.onload();
		} }
	};
	if (harnessOptions.ordinaryEnabled === true) document.head.appendChild({ onload() {} });
	vm.runInNewContext(fs.readFileSync(square, 'utf8'), { window, document, Promise, CustomEvent: function () {}, fetch: async (url, options) => {
		calls.push({ url, body: JSON.parse(options.body) });
		return { ok: true, json: async () => url.endsWith('/payment-intent') ? { payment_intent: 'sq-provider-id' } : (url.endsWith('/start') ? { gateway: 'square' } : (Object.hasOwn(harnessOptions, 'receipt') ? harnessOptions.receipt : { success: true, order_number: 'DB-261001-ABC123', total: 14 })) };
	} });
	const form = element('form');
	form.appendChild(element('button'));
	const originalQuerySelector = form.querySelector;
	form.querySelector = selector => {
		if (harnessOptions.ordinaryEnabled === true && selector === '.db-checkout-summary-total') return { textContent: '$14.00' };
		if (harnessOptions.ordinaryEnabled === true && selector === '[name="customer_name"]') return { value: 'Synthetic customer' };
		if (harnessOptions.ordinaryEnabled === true && selector === '[name="customer_email"]') return { value: 'synthetic@example.invalid' };
		if (harnessOptions.ordinaryEnabled === true && selector === '[name="customer_phone"]') return { value: '0400000000' };
		return originalQuerySelector.call(form, selector);
	};
	form.dispatchEvent = () => {};
	return { window, form, calls, scripts, sdkBindings, tokenizations, fieldStarts };
}

test('actual dormant Square module loads no SDK until approved mode and binds the exact capability shop', async () => {
	const h = dormantSquareModuleHarness();
	assert.equal(h.scripts.length, 0);
	assert.equal(h.sdkBindings.length, 0);
	h.window.DoughBossSquare.enhance(h.form);
	assert.equal(h.form.children.length, 1, 'closed ordinary checkout has no card field');
	h.window.DoughBossSquare.enhanceApproved(h.form, {
		gateway: 'square', total: 14, currency: 'AUD', can_start_payment: true,
		square: { application_id: 'sq-app-test', location_id: 'sq-roselands-test', live_mode: false }
	}, { requestId: 93, token: 'b'.repeat(64) });
	await h.window.DoughBossSquare.payApproved(h.form);
	assert.equal(h.scripts.length, 1);
	assert.equal(h.scripts[0].src, 'https://sandbox.web.squarecdn.com/v1/square.js');
	assert.deepEqual(h.sdkBindings, [{ applicationId: 'sq-app-test', locationId: 'sq-roselands-test' }]);
	assert.equal(h.tokenizations.length, 1);
	assert.deepEqual(h.calls.map(call => call.url), ['/wp-json/doughboss/v1/preorder-payment/start', '/wp-json/doughboss/v1/preorder-payment/complete']);
	assert.equal(h.calls[1].body.request_id, 93);
	assert.equal(Object.hasOwn(h.calls[1].body, 'payment_intent'), false);
});

test('actual approved module mounts Square gift fields on explicit selection and sends no GAN', async () => {
	const h = dormantSquareModuleHarness();
	h.window.DoughBossSquare.enhanceApproved(h.form, {
		gateway: 'square', total: 14, currency: 'AUD', can_start_payment: true,
		square: { application_id: 'sq-app-test', location_id: 'sq-bankstown-test', live_mode: false }
	}, { requestId: 93, token: 'b'.repeat(64) });
	const wrap = h.form.children.find(x => x.className === 'db-cardfield db-cardfield--square');
	const method = wrap.children.find(x => x.tag === 'select');
	assert.equal(method.value, 'card');
	method.value = 'gift_card';
	method.listeners.change();
	await h.window.DoughBossSquare.payApproved(h.form);
	assert.equal(h.calls[0].body.source_id, 'cnon:gift-synthetic-secure');
	assert.equal(h.calls[0].body.tender_kind, 'gift_card');
	assert.deepEqual(h.tokenizations, [undefined]);
	assert.equal(wrap.children.find(x => x.className === 'db-square-card').hidden, true);
	assert.equal(wrap.children.find(x => x.className === 'db-square-gift').hidden, false);
	assert.match(wrap.children.find(x => x.tag === 'p').textContent, /Split payment is not yet available/);
});

test('failed ordinary card mount does not retry when paying with the selected gift card', async () => {
	const h = dormantSquareModuleHarness({ cardFailures: 10 });
	h.window.DoughBossSquare.enhanceApproved(h.form, {
		gateway: 'square', total: 14, currency: 'AUD', can_start_payment: true,
		square: { application_id: 'sq-app-test', location_id: 'sq-bankstown-test', live_mode: false }
	}, { requestId: 93, token: 'b'.repeat(64) });
	await new Promise(resolve => setImmediate(resolve));
	const wrap = h.form.children.find(x => x.className === 'db-cardfield db-cardfield--square');
	const method = wrap.children.find(x => x.tag === 'select');
	method.value = 'gift_card';
	method.listeners.change();
	await h.window.DoughBossSquare.payApproved(h.form);
	assert.deepEqual(h.fieldStarts, { card: 1, gift: 1 });
	assert.deepEqual(h.tokenizations, [undefined]);
	assert.equal(h.calls[0].body.tender_kind, 'gift_card');
	assert.equal(wrap.children.find(x => x.tag === 'p').className, 'db-pay-secure');
});

test('switching back to a failed card mount retries safely without a null promise', async () => {
	const h = dormantSquareModuleHarness({ cardFailures: 2 });
	h.window.DoughBossSquare.enhanceApproved(h.form, {
		gateway: 'square', total: 14, currency: 'AUD', can_start_payment: true,
		square: { application_id: 'sq-app-test', location_id: 'sq-bankstown-test', live_mode: false }
	}, { requestId: 93, token: 'b'.repeat(64) });
	await new Promise(resolve => setImmediate(resolve));
	const wrap = h.form.children.find(x => x.className === 'db-cardfield db-cardfield--square');
	const method = wrap.children.find(x => x.tag === 'select');
	method.value = 'gift_card';
	method.listeners.change();
	await new Promise(resolve => setImmediate(resolve));
	method.value = 'card';
	assert.doesNotThrow(() => method.listeners.change());
	await new Promise(resolve => setImmediate(resolve));
	assert.equal(h.calls.length, 0);
	assert.equal(h.tokenizations.length, 0);
	await h.window.DoughBossSquare.payApproved(h.form);
	assert.deepEqual(h.fieldStarts, { card: 3, gift: 1 });
	assert.equal(h.calls[0].body.tender_kind, 'card');
});

test('failed gift mount retries only gift fields and makes no premature payment call', async () => {
	const h = dormantSquareModuleHarness({ giftFailures: 2 });
	h.window.DoughBossSquare.enhanceApproved(h.form, {
		gateway: 'square', total: 14, currency: 'AUD', can_start_payment: true,
		square: { application_id: 'sq-app-test', location_id: 'sq-bankstown-test', live_mode: false }
	}, { requestId: 93, token: 'b'.repeat(64) });
	await new Promise(resolve => setImmediate(resolve));
	const wrap = h.form.children.find(x => x.className === 'db-cardfield db-cardfield--square');
	const method = wrap.children.find(x => x.tag === 'select');
	method.value = 'gift_card';
	method.listeners.change();
	await new Promise(resolve => setImmediate(resolve));
	await assert.rejects(h.window.DoughBossSquare.payApproved(h.form), /Synthetic gift mount failure/);
	assert.equal(h.calls.length, 0);
	assert.equal(h.tokenizations.length, 0);
	await h.window.DoughBossSquare.payApproved(h.form);
	assert.deepEqual(h.fieldStarts, { card: 1, gift: 3 });
	assert.deepEqual(h.tokenizations, [undefined]);
	assert.equal(h.calls[0].body.tender_kind, 'gift_card');
});

test('actual ordinary checkout rejects selected gift mount failure before payment then retries once', async () => {
	const h = dormantSquareModuleHarness({ ordinaryEnabled: true, giftFailures: 1 });
	h.window.DoughBossSquare.enhance(h.form);
	await new Promise(resolve => setImmediate(resolve));
	const wrap = h.form.children.find(x => x.className === 'db-cardfield db-cardfield--square');
	const method = wrap.children.find(x => x.tag === 'select');
	method.value = 'gift_card';
	method.listeners.change();
	await assert.rejects(h.window.DoughBossSquare.pay(h.form), /Synthetic gift mount failure/);
	assert.deepEqual(h.fieldStarts, { card: 1, gift: 1 }, 'the selected gift failure does not remount card');
	assert.deepEqual(h.tokenizations, [], 'a mount failure cannot reach hosted-field tokenization');
	assert.deepEqual(h.calls, [], 'a mount failure cannot call /payment-intent or /checkout');

	method.listeners.change();
	await h.window.DoughBossSquare.pay(h.form);
	assert.deepEqual(h.fieldStarts, { card: 1, gift: 2 }, 'the successful retry remains on the selected gift tender without remounting card');
	assert.deepEqual(h.tokenizations, [undefined], 'gift tokenization has no card verification details');
	assert.deepEqual(h.calls.map(call => call.url), [
		'/wp-json/doughboss/v1/payment-intent',
		'/wp-json/doughboss/v1/checkout'
	], 'only the successful retry starts one payment and places one order');
	assert.equal(h.calls[0].body.tender_kind, 'gift_card');
	assert.equal(h.calls[0].body.source_id, 'cnon:gift-synthetic-secure');
});

test('actual Square recovery module never loads SDK, mounts fields or tokenizes', async () => {
	const h = dormantSquareModuleHarness();
	h.window.DoughBossSquare.enhanceApproved(h.form, {
		gateway: 'square', total: 14, currency: 'AUD', can_start_payment: false, can_confirm_payment: true,
		square: { application_id: 'sq-app-test', location_id: 'sq-bankstown-test', live_mode: false }
	}, { requestId: 94, token: 'c'.repeat(64) });
	await h.window.DoughBossSquare.payApproved(h.form);
	assert.equal(h.form.children.length, 1);
	assert.equal(h.scripts.length, 0);
	assert.equal(h.tokenizations.length, 0);
	assert.deepEqual(h.calls.map(call => call.url), ['/wp-json/doughboss/v1/preorder-payment/complete']);
});

test('actual approved Square module rejects a missing capability before loading card fields', () => {
	const h = dormantSquareModuleHarness();
	assert.throws(() => h.window.DoughBossSquare.enhanceApproved(h.form, {
		gateway: 'square', total: 14, currency: 'AUD', can_start_payment: true,
		square: { application_id: 'sq-app-test', location_id: 'sq-bankstown-test', live_mode: false }
	}, { requestId: 94, token: '' }), /valid approved payment link/);
	assert.equal(h.form.children.length, 1);
	assert.equal(h.scripts.length, 0);
	assert.equal(h.calls.length, 0);
});

function invalidApprovedReceipts() {
	const valid = { success: true, order_number: 'DB-261001-ABC123', total: 14 };
	return [null, {}, { ...valid, success: false }, { ...valid, order_number: undefined },
		{ ...valid, order_number: '' }, { ...valid, order_number: '<img src=x>' },
		{ ...valid, order_number: 'DB-261001-ABC123\n' }, { ...valid, total: undefined },
		{ ...valid, total: null }, { ...valid, total: '14.00' }, { ...valid, total: NaN },
		{ ...valid, total: Infinity }, { ...valid, total: -14 }, { ...valid, total: 0 },
		{ ...valid, total: 14.005 }, { ...valid, total: 14.01 }];
}

test('actual Square module rejects malformed HTTP-200 receipts and never charges again', async () => {
	for (const receipt of invalidApprovedReceipts()) {
		const h = dormantSquareModuleHarness({ receipt });
		h.window.DoughBossSquare.enhanceApproved(h.form, {
			gateway: 'square', total: 14, currency: 'AUD', can_start_payment: true,
			square: { application_id: 'sq-app-test', location_id: 'sq-bankstown-test', live_mode: false }
		}, { requestId: 94, token: 'c'.repeat(64) });
		await assert.rejects(h.window.DoughBossSquare.payApproved(h.form), /receipt could not be confirmed/);
		assert.equal(h.window.DoughBossSquare.approvedPending(h.form), true);
		await assert.rejects(h.window.DoughBossSquare.payApproved(h.form), /receipt could not be confirmed/);
		assert.equal(h.tokenizations.length, 1);
		assert.deepEqual(h.calls.map(call => call.url), ['/wp-json/doughboss/v1/preorder-payment/start', '/wp-json/doughboss/v1/preorder-payment/complete', '/wp-json/doughboss/v1/preorder-payment/complete']);
		assert.equal(h.calls[2].body.token, 'c'.repeat(64), 'capability remains in the bounded recovery session');
	}
});

test('approved receipt validation compares exact minor units and accepts a valid replay', async () => {
	const validate = extractFunction(square, 'validApprovedSquareReceipt');
	assert.equal(validate({ success: true, order_number: 'DB-261001-ABC123', total: 0.29 }, { total: 0.29 }), true);
	assert.equal(validate({ success: true, order_number: 'DB-261001-ABC123', total: 14 }, { total: 14.01 }), false);
	assert.equal(validate({ success: true, order_number: 'DB-261001-ABC123', total: 14 }, { total: '14' }), false);
	const h = dormantSquareModuleHarness({ receipt: { success: true, order_number: 'DB-261001-ABC123', total: 14, replayed: true } });
	h.window.DoughBossSquare.enhanceApproved(h.form, {
		gateway: 'square', total: 14, currency: 'AUD', can_start_payment: true,
		square: { application_id: 'sq-app-test', location_id: 'sq-bankstown-test', live_mode: false }
	}, { requestId: 94, token: 'c'.repeat(64) });
	assert.equal((await h.window.DoughBossSquare.payApproved(h.form)).success, true);
	assert.equal((await h.window.DoughBossSquare.payApproved(h.form)).replayed, true);
	assert.equal(h.tokenizations.length, 1);
	assert.equal(h.calls.filter(call => call.url.endsWith('/start')).length, 1);
});

function approvedScreenHarness(summary, paymentError, options = {}) {
	const elements = [];
	const calls = [];
	const locks = [];
	const historyRemovals = [];
	let pending = summary.can_confirm_payment === true;
	function element(tag, attrs = {}, children = []) {
		const node = { tag, attrs, children: [], textContent: attrs.text || '', listeners: {}, hidden: false,
			set innerHTML(value) { this.children = []; this.textContent = value; },
			get firstChild() { return this.children[0] || null; },
			appendChild(child) { if (child) { this.children.push(child); } return child; },
			addEventListener(name, callback) { this.listeners[name] = callback; },
			setAttribute(key, value) { this.attrs[key] = value; }, closest() { return { hidden: true }; }
		};
		children.forEach(child => node.appendChild(child));
		elements.push(node);
		return node;
	}
	const presentation = extractFunction(storefront, 'approvedPreorderPresentation');
	const render = extractFunction(storefront, 'renderApprovedPreorder', {
		document: { body: { classList: { add() {} } }, querySelectorAll: () => [] },
		window: { history: { replaceState() { historyRemovals.push(true); } }, location: { pathname: '/order/', search: '' }, DoughBossSquare: {
			enhanceApproved(form, state, capability) { calls.push({ type: 'enhance', form, state, capability }); },
			payApproved(form) {
				calls.push({ type: 'pay', form }); pending = true;
				if (paymentError) { return Promise.reject(paymentError); }
				const confirm = extractFunction(square, 'confirmApproved', {
					request: async () => Object.hasOwn(options, 'receipt') ? options.receipt : { success: true, order_number: 'DB-261001-ABC123', total: summary.total },
					validApprovedSquareReceipt: extractFunction(square, 'validApprovedSquareReceipt')
				});
				return confirm({ summary, capability: { requestId: 97, token: 'd'.repeat(64) } });
			},
			approvedPending() { return pending; }
		} },
		el: element, money: amount => '$' + Number(amount).toFixed(2),
		approvedPreorderPresentation: presentation,
		isApprovedPreorderComplete: state => state.presentation_state === 'complete',
		approvedSquareRecoverable: extractFunction(storefront, 'approvedSquareRecoverable'),
		approvedPreorderContact: () => null,
		setPaymentMutationLock: locked => locks.push(locked),
		clearError: node => { node.textContent = ''; }, announceError: (node, message) => { node.textContent = message; }, revealNode() {},
		request: async route => { calls.push({ type: 'request', route }); return summary; }
	});
	const root = element('main');
	render(root, { requestId: 97, token: 'd'.repeat(64) });
	const textOf = node => [node.textContent, ...node.children.map(textOf)].join(' ');
	return { elements, calls, locks, historyRemovals, text: () => textOf(root), settle: () => new Promise(resolve => setImmediate(resolve)) };
}

test('approved screen renders Square payment and confirms only after its server completion', async () => {
	const h = approvedScreenHarness({ gateway: 'square', presentation_state: 'ready', can_start_payment: true, total: 14, currency: 'AUD', items: [] });
	await h.settle();
	assert.match(h.text(), /Pay securely with Square/);
	assert.doesNotMatch(h.text(), /Continue to Stripe|after Stripe/);
	const form = h.elements.find(node => node.tag === 'form');
	assert.ok(form);
	form.listeners.submit({ preventDefault() {} });
	await h.settle();
	assert.match(h.text(), /Payment verified/);
	assert.equal(h.calls.filter(call => call.type === 'pay').length, 1);
	assert.equal(h.locks.at(-1), false);
});

test('approved screen keeps uncertain Square result in confirmation-only recovery', async () => {
	const h = approvedScreenHarness({ gateway: 'square', presentation_state: 'held', can_start_payment: false, can_confirm_payment: true, total: 14, currency: 'AUD', items: [] }, new Error('Synthetic pending'));
	await h.settle();
	assert.match(h.text(), /Check existing payment/);
	const form = h.elements.find(node => node.tag === 'form');
	form.listeners.submit({ preventDefault() {} });
	await h.settle();
	assert.match(h.text(), /Do not pay again/);
	assert.doesNotMatch(h.text(), /Pay securely with Square/);
	assert.equal(form.attrs['aria-busy'], 'false');
	assert.equal(h.locks.at(-1), true);
});

test('approved screen never presents malformed HTTP-200 receipts as paid or discards recovery', async () => {
	for (const receipt of invalidApprovedReceipts()) {
		const h = approvedScreenHarness({ gateway: 'square', presentation_state: 'ready', can_start_payment: true, total: 14, currency: 'AUD', items: [] }, null, { receipt });
		await h.settle();
		const form = h.elements.find(node => node.tag === 'form');
		form.listeners.submit({ preventDefault() {} });
		await h.settle();
		assert.doesNotMatch(h.text(), /Payment verified|Your order is in/);
		assert.match(h.text(), /Do not pay again/);
		assert.match(h.text(), /Check existing payment/);
		assert.equal(h.locks.at(-1), true);
		assert.equal(h.historyRemovals.length, 0);
		assert.equal(form.attrs['aria-busy'], 'false');
	}
});

test('closed ordering keeps Square dormant while approved recovery is explicitly scoped', () => {
	const source = fs.readFileSync(square, 'utf8');
	const assets = fs.readFileSync(path.resolve(__dirname, '..', 'includes', 'class-doughboss-assets.php'), 'utf8');
	assert.match(source, /function enhance\(form\) \{\s*if \(!ordinaryEnabled\) \{ return; \}/);
	assert.match(assets, /'applicationId'\s*=> \$payments_on \? DoughBoss_Square::publishable_key\(\) : ''/);
	assert.match(assets, /'locationId'\s*=> \$payments_on \? \(string\) \$square_binding\['square_location_id'\] : ''/);
	assert.doesNotMatch(assets, /'locationId'\s*=>[^\n]*DoughBoss_Square::location_id\(\)/);
	assert.match(assets, /\$single_location_id = DoughBoss_Locations::single_location_id\(\)/);
	assert.match(assets, /online_payment_location\( \$single_location_id \)/);
	assert.match(assets, /ready_for_location\( \$single_location_id \)/);
	const recovery = extractFunction(storefront, 'approvedSquareRecoverable');
	assert.equal(recovery({ gateway: 'square', can_confirm_payment: true }), true);
	assert.equal(recovery({ gateway: 'stripe', can_confirm_payment: true }), false);
	assert.equal(recovery({ gateway: 'square', can_confirm_payment: 'true' }), false);
});

test('catering quotes reject error objects and invalid display fields', () => {
	assert.equal(validCateringQuote(cateringQuote(100)), true);
	assert.equal(validCateringQuote(cateringQuote(0)), true);
	assert.equal(validCateringQuote(Object.assign(cateringQuote(72), { currency: 'aud' })), true, 'stored lowercase currency remains a valid server quote');
	assert.equal(validCateringQuote(Object.assign(cateringQuote(72), { currency: ' aUd ' })), true, 'display normalisation accepts surrounding whitespace and mixed case');
	for (const invalid of [null, [], {}, { code: 'error' }, Object.assign(cateringQuote(1), { currency: '<b>' }), Object.assign(cateringQuote(1), { deposit_pct: 101 }), Object.assign(cateringQuote(1), { lead_days: 1.5 })]) {
		assert.equal(validCateringQuote(invalid), false);
	}
	for (const field of ['subtotal', 'delivery_fee', 'total', 'deposit_pct', 'deposit', 'balance', 'lead_days']) {
		for (const value of [undefined, null, '10', NaN, Infinity, -1]) {
			assert.equal(validCateringQuote(Object.assign(cateringQuote(100), { [field]: value })), false, field + ' rejects ' + value);
		}
	}
});

function cateringRefreshHarness() {
	const state = { selectedId: 1, guests: 12, orderType: 'pickup', quote: cateringQuote(56), quoteStatus: 'ready', quoteGeneration: 0 };
	const requests = [];
	const paints = [];
	const refresh = extractFunction(catering, 'refreshQuote', {
		state,
		root: { querySelector: () => ({}) },
		updateQuoteBox: () => paints.push({ status: state.quoteStatus, total: state.quote && state.quote.total }),
		validCateringQuote,
		get: url => new Promise((resolve, reject) => requests.push({ url, resolve, reject }))
	});
	return { state, requests, paints, refresh };
}

test('one catering generation protects package, headcount and fulfilment from stale success', async () => {
	const h = cateringRefreshHarness();
	const first = h.refresh();
	assert.equal(h.state.quote, null, 'previous total clears before any network response');
	assert.equal(h.state.quoteStatus, 'loading');
	h.state.selectedId = 2;
	h.state.guests = 30;
	h.state.orderType = 'delivery';
	const second = h.refresh();
	assert.match(h.requests[1].url, /package_id=2&guest_count=30&order_type=delivery/);
	h.requests[1].resolve(cateringQuote(124));
	await second;
	h.requests[0].resolve(cateringQuote(56));
	await first;
	assert.equal(h.state.quote.total, 124);
	assert.equal(h.state.quoteStatus, 'ready');
	assert.equal(h.paints.length, 3, 'superseded success cannot paint');
});

test('a stale failure cannot clear a fresh quote and a current failure removes stale totals', async () => {
	const h = cateringRefreshHarness();
	const first = h.refresh();
	const second = h.refresh();
	h.requests[1].resolve(cateringQuote(72));
	await second;
	h.requests[0].reject(new Error('old request failed'));
	await first;
	assert.equal(h.state.quote.total, 72);
	const third = h.refresh();
	assert.equal(h.state.quote, null);
	h.requests[2].reject(new Error('offline'));
	await third;
	assert.equal(h.state.quote, null);
	assert.equal(h.state.quoteStatus, 'error');
});

test('malformed current quotes fail closed and custom selection invalidates an in-flight quote', async () => {
	const h = cateringRefreshHarness();
	const first = h.refresh();
	h.requests[0].resolve({ code: 'rest_error', total: '56' });
	await first;
	assert.equal(h.state.quoteStatus, 'error');
	assert.equal(h.state.quote, null);
	const second = h.refresh();
	h.state.selectedId = 0;
	h.refresh();
	h.requests[1].resolve(cateringQuote(56));
	await second;
	assert.equal(h.state.quote, null);
	assert.equal(h.state.quoteStatus, 'idle');
	assert.equal(h.requests.length, 2, 'custom selection does not send a quote request');
});

test('catering quantity changes update only summary and card steppers without replacing form inputs', () => {
	const state = { packages: [{ id: 1, name: 'Synthetic A', price: 46 }, { id: 2, name: 'Synthetic B', price: 72 }], quantities: {}, lines: [], selectedId: 0, guests: 22, orderType: 'pickup' };
	const summary = {};
	const cards = [1, 2].map(id => {
		const controls = { output: { textContent: '0' }, minus: { disabled: true }, plus: { disabled: false } };
		return { id, controls, getAttribute: () => String(id), classList: { toggle: (key, value) => { cards[id - 1].selected = value; } },
			querySelector: selector => ({ '[data-qty-for]': controls.output, '[data-step="-1"]': controls.minus, '[data-step="1"]': controls.plus })[selector] };
	});
	let refreshed = 0;
	const root = {
		querySelector: selector => { assert.equal(selector, '.dbc-selected'); return summary; },
		querySelectorAll: selector => { assert.equal(selector, '[data-package-card]'); return cards; }
	};
	Object.defineProperty(root, 'innerHTML', { set: () => assert.fail('selection must not replace the form DOM') });
	const selectionSummary = extractFunction(catering, 'selectionSummary', { state, esc: value => value, money: value => '$' + value });
	const setQuantity = extractFunction(catering, 'setPackageQuantity', {
		state, root, selectionSummary, MAX_PACKAGE_QTY: 50, refreshQuote: () => { refreshed++; }
	});
	setQuantity(2, 2);
	assert.equal(refreshed, 1);
	assert.equal(state.selectedId, 2, 'one package type keeps the legacy single package id');
	assert.deepEqual(JSON.parse(JSON.stringify(state.lines)), [{ id: 2, qty: 2 }]);
	assert.equal(cards[1].selected, true);
	assert.equal(cards[1].controls.output.textContent, '2');
	assert.equal(cards[1].controls.minus.disabled, false);
	setQuantity(1, 1);
	assert.equal(state.selectedId, 0, 'a combined selection has no single package id');
	assert.deepEqual(JSON.parse(JSON.stringify(state.lines)), [{ id: 1, qty: 1 }, { id: 2, qty: 2 }]);
	assert.match(summary.innerHTML, /1 × Synthetic A.*2 × Synthetic B/);
	setQuantity(2, 99);
	assert.equal(state.quantities[2], 50, 'quantities are capped');
	assert.equal(cards[1].controls.plus.disabled, true);
	setQuantity(9, 3);
	assert.equal(state.quantities[9], undefined, 'unknown packages are ignored');
	setQuantity(1, 0);
	setQuantity(2, 0);
	assert.deepEqual(JSON.parse(JSON.stringify(state.lines)), []);
	assert.equal(state.selectedId, 0);
	assert.equal(state.guests, 22);
	assert.equal(state.orderType, 'pickup');
	assert.equal(refreshed, 5);
});

test('catering estimate states never render old totals while pending or unavailable', () => {
	const state = { quoteStatus: 'loading', quote: null, orderType: 'pickup' };
	const box = { setAttribute: (name, value) => { box[name] = value; } };
	const form = { querySelector: () => box };
	const paint = extractFunction(catering, 'updateQuoteBox', { state, esc: value => value });
	paint(form);
	assert.match(box.innerHTML, /Updating estimate/);
	assert.equal(box['aria-busy'], 'true');
	state.quoteStatus = 'error';
	paint(form);
	assert.match(box.innerHTML, /Estimate unavailable/);
	assert.equal(box['aria-busy'], 'false');
	state.quoteStatus = 'ready';
	state.quote = cateringQuote(72);
	state.quote.currency = ' aUd ';
	paint(form);
	assert.match(box.innerHTML, /AUD 72\.00/);
	assert.doesNotMatch(box.innerHTML, /Updating estimate|Estimate unavailable/);
});

test('catering public reads use plain-permalink-safe URLs without stale nonces and reject HTTP failures', async () => {
	const url = extractFunction(catering, 'cateringRequestUrl', { API: 'https://example.test/?rest_route=/doughboss/v1' });
	assert.equal(url('/catering/quote?package_id=1&guest_count=12'), 'https://example.test/?rest_route=/doughboss/v1/catering/quote&package_id=1&guest_count=12');
	let requested;
	let successful = true;
	const get = extractFunction(catering, 'get', {
		cateringRequestUrl: url, setTimeout, clearTimeout,
		fetch: (requestUrl, options) => { requested = { requestUrl, options }; return Promise.resolve({ ok: successful, json: () => Promise.resolve(cateringQuote(56)) }); }
	});
	assert.equal((await get('/catering/quote?package_id=1')).total, 56);
	assert.equal(requested.options.cache, 'no-store');
	assert.equal(requested.options.credentials, 'same-origin');
	assert.equal(requested.options.headers, undefined);
	successful = false;
	await assert.rejects(get('/catering/packages'), /Catering request failed/);
});

test('catering reads time out even without browser abort support', async () => {
	let timeout;
	let cleared;
	const get = extractFunction(catering, 'get', {
		cateringRequestUrl: value => value,
		setTimeout: (callback, delay) => { assert.equal(delay, 8000); timeout = callback; return 7; },
		clearTimeout: handle => { cleared = handle; },
		fetch: () => new Promise(() => {})
	});
	const pending = get('/catering/quote');
	timeout();
	await assert.rejects(pending, /timed out/);
	assert.equal(cleared, 7);
});

const cateringLocationId = extractFunction(catering, 'cateringLocationId');
const syntheticShops = [{ id: 1, name: 'Synthetic shop A' }, { id: 2, name: 'Synthetic shop B' }];

test('header initial selection preserves early form events when storage is blocked', () => {
	const choose = extractFunction(shopStatus, 'chosenId', {
		latestLocationId: 2,
		locationById: (locations, id) => locations.find(location => location.id === id),
		window: { localStorage: { getItem: () => { throw new Error('Storage blocked'); } } }
	});
	assert.equal(choose(syntheticShops), 2);
	assert.equal(choose([syntheticShops[0]]), 1, 'an event cannot select a shop missing from the header response');
});

function cateringLocationHarness(saved) {
	const state = { locations: [], locationId: 0, locationStatus: 'loading', requestedLocationId: null, locationLocked: false, submitting: false };
	const requests = [];
	const context = {
		state, cateringLocationId, root: { querySelector: () => ({}) }, updateCateringLocation: () => {},
		window: { localStorage: { getItem: () => { if (saved instanceof Error) throw saved; return saved; } } },
		get: url => new Promise((resolve, reject) => requests.push({ url, resolve, reject }))
	};
	const load = extractFunction(catering, 'loadCateringLocations', context);
	const apply = extractFunction(catering, 'applyCateringLocation', context);
	return { state, requests, load, apply };
}

test('catering location identifiers reject malformed values', () => {
	for (const value of [null, undefined, true, {}, '', '2.5', '1e2', '-2', 2.5, Infinity, 9007199254740992]) assert.equal(cateringLocationId(value), 0);
	assert.equal(cateringLocationId('2'), 2);
	assert.equal(cateringLocationId(2), 2);
});

test('catering initial shop preference matches configured shops and storage is optional', async () => {
	for (const [saved, expected] of [['2', 2], [null, 1], [new Error('Storage blocked'), 1], ['99', 0]]) {
		const h = cateringLocationHarness(saved);
		const pending = h.load();
		h.requests[0].resolve(syntheticShops);
		h.requests[1].resolve({ active: false });
		await pending;
		assert.equal(h.state.locationStatus, 'ready');
		assert.equal(h.state.locationId, expected, 'a stale explicit preference must not silently select another shop');
	}
});

test('header changes before and after shop loading remain current without local storage', async () => {
	const h = cateringLocationHarness(new Error('Storage blocked'));
	const pending = h.load();
	h.apply(2);
	h.requests[0].resolve(syntheticShops);
	h.requests[1].resolve({ active: false });
	await pending;
	assert.equal(h.state.locationId, 2);
	h.apply(1);
	assert.equal(h.state.locationId, 1);
	h.apply(99);
	assert.equal(h.state.locationId, 0, 'unknown event cannot route to a default shop');
});

test('shop loading failure recovers without changing the queued preference', async () => {
	const h = cateringLocationHarness('1');
	h.apply(2);
	const failed = h.load();
	h.requests[0].reject(new Error('Offline'));
	h.requests[1].resolve({ active: false });
	await failed;
	assert.equal(h.state.locationStatus, 'error');
	const retry = h.load();
	h.requests[2].resolve(syntheticShops);
	h.requests[3].resolve({ active: false });
	await retry;
	assert.equal(h.state.locationStatus, 'ready');
	assert.equal(h.state.locationId, 2);
});

test('verified empty shops retain legacy custom capture; invalid table context fails closed', async () => {
	for (const [shops, table, expected, locked, id] of [
		[[], { active: false }, 'ready', false, 0],
		[syntheticShops, { active: true, location: { id: 2 }, table: { label: 'Synthetic table' } }, 'ready', true, 2],
		[syntheticShops, { active: true, location: { id: 99 }, table: {} }, 'error', true, 0],
		[syntheticShops, {}, 'error', false, 0]
	]) {
		const h = cateringLocationHarness(table.active ? '99' : '1');
		const pending = h.load();
		h.requests[0].resolve(shops);
		h.requests[1].resolve(table);
		await pending;
		assert.equal(h.state.locationStatus, expected);
		assert.equal(h.state.locationLocked, locked);
		assert.equal(h.state.locationId, id);
	}
});

test('pending catering or signed table context vetoes sitewide shop changes', () => {
	const state = { submitting: false, locationLocked: false };
	let vetoes = 0;
	const guard = extractFunction(catering, 'guardCateringShopChange', { state });
	const event = { preventDefault: () => { vetoes++; } };
	guard(event);
	assert.equal(vetoes, 0);
	state.submitting = true;
	guard(event);
	state.submitting = false;
	state.locationLocked = true;
	guard(event);
	assert.equal(vetoes, 2);
});

test('shop UI changes only its controls, keeping other form values untouched', () => {
	const state = { locations: syntheticShops, locationId: 2, locationStatus: 'ready', submitting: false };
	const select = {};
	const note = {};
	const retry = {};
	const form = { querySelector: selector => ({ '[name="location_id"]': select, '.dbc-location-note': note, '[data-catering-locations-retry]': retry })[selector] };
	Object.defineProperty(form, 'innerHTML', { set: () => assert.fail('shop change must not rebuild enquiry inputs') });
	const selected = extractFunction(catering, 'selectedCateringLocation', { state });
	const update = extractFunction(catering, 'updateCateringLocation', { state, selectedCateringLocation: selected, esc: value => value });
	update(form);
	assert.equal(select.value, '2');
	assert.match(note.textContent, /Synthetic shop B/);
	state.submitting = true;
	state.submittedLocationName = 'Synthetic shop B';
	update(form);
	assert.equal(select.disabled, true);
	assert.match(note.textContent, /Sending enquiry for Synthetic shop B/);
});

test('enquiry submits the exact shop and form snapshot once, retaining fields after server errors', async () => {
	const state = { locations: syntheticShops, locationId: 2, locationStatus: 'ready', submitting: false, selectedId: 9, orderType: 'delivery' };
	const values = { customer_name: 'Synthetic customer', customer_email: 'synthetic@example.invalid', customer_phone: '0400000000', guest_count: '12', event_date: '2099-10-10', event_time: '12:30', address: 'Synthetic venue', dietary: 'Synthetic dietary note', notes: 'Retain this note', hp: '' };
	const error = {};
	const button = { textContent: 'Request booking & quote', disabled: false };
	const form = { classList: { contains: () => true }, querySelector: selector => selector === '.dbc-error' ? error : button };
	const sends = [];
	const selected = extractFunction(catering, 'selectedCateringLocation', { state });
	const submit = extractFunction(catering, 'submitCateringEnquiry', {
		state, selectedCateringLocation: selected, updateCateringLocation: () => {},
		FormData: function () { this.get = key => values[key]; },
		post: (url, body) => new Promise(resolve => sends.push({ url, body, resolve })), showSuccess: () => assert.fail('failed response cannot show success')
	});
	const event = { target: form, preventDefault: () => {} };
	const pending = submit(event);
	submit(event);
	assert.equal(sends.length, 1);
	assert.equal(sends[0].url, '/catering/enquiry');
	assert.equal(sends[0].body.location_id, 2);
	for (const field of ['customer_phone', 'event_date', 'event_time', 'address', 'dietary', 'notes']) assert.equal(sends[0].body[field], values[field]);
	assert.equal(state.submittedLocationName, 'Synthetic shop B');
	values.notes = 'Edited while pending';
	values.event_time = '13:45';
	assert.equal(sends[0].body.notes, 'Retain this note', 'in-flight payload does not reread edited fields');
	assert.equal(sends[0].body.event_time, '12:30');
	sends[0].resolve({ ok: false, data: { message: 'That catering package is no longer available.' } });
	await pending;
	assert.equal(state.submitting, false);
	assert.equal(button.disabled, false);
	assert.match(error.textContent, /package is no longer available/);
	assert.equal(values.notes, 'Edited while pending', 'failure preserves the current form rather than restoring old inputs');
	state.locationId = 0;
	submit(event);
	assert.equal(sends.length, 1, 'unavailable location must not POST legacy zero');
	assert.match(error.textContent, /Choose an available shop/);
});

const themeScript = path.resolve(__dirname, '..', 'themes', 'doughboss-final', 'assets', 'theme.js');

// Minimal attribute/tree fixtures for the existing theme helpers, not a browser.
function themeNode(attributes, children) {
	const attrs = Object.assign({}, attributes);
	const classes = new Set();
	const element = {
		children: children || [],
		getAttribute: name => Object.hasOwn(attrs, name) ? attrs[name] : null,
		setAttribute: (name, value) => { attrs[name] = String(value); },
		removeAttribute: name => { delete attrs[name]; },
		classList: {
			add: name => classes.add(name), remove: name => classes.delete(name), contains: name => classes.has(name),
			toggle: (name, on) => { if (on) classes.add(name); else classes.delete(name); }
		},
		contains: target => target === element || element.children.some(child => child.contains(target))
	};
	return element;
}

function themeNavigationHarness() {
	const frames = [];
	const toggle = themeNode({ 'aria-expanded': 'false' });
	const link = themeNode();
	const nav = themeNode({ 'aria-hidden': 'true' }, [link]);
	const priorInert = themeNode({ inert: 'inert', 'aria-hidden': 'false' });
	const priorHidden = themeNode({ 'aria-hidden': 'true' });
	const main = themeNode();
	const headerInner = themeNode({}, [toggle, nav, priorInert]);
	const header = themeNode({}, [headerInner]);
	const body = themeNode({}, [header, main, priorHidden]);
	const document = { body, documentElement: themeNode({}, [body]), activeElement: toggle };
	const context = {
		document, toggle, nav, mobileQuery: { matches: true }, isolatedNavigationBackground: [], menuReturnFocus: toggle,
		menuFocusRevision: 0, focusableMenuItems: () => [link],
		window: { requestAnimationFrame: callback => frames.push(callback), setTimeout: callback => frames.push(callback) }
	};
	for (const name of ['menuOpen', 'navigationIsolationTargets', 'isolateNavigationBackground', 'restoreNavigationBackground', 'queueNavigationInitialFocus', 'openMenu', 'closeMenu', 'syncMenuMode']) {
		context[name] = extractFunction(themeScript, name, context);
	}
	link.focus = () => {
		assert.equal(nav.getAttribute('aria-hidden'), null, 'navigation is exposed before focus enters it');
		assert.equal(toggle.getAttribute('inert'), null, 'move focus before hiding the previously focused trigger');
		document.activeElement = link;
	};
	toggle.focus = () => {
		assert.equal(toggle.getAttribute('inert'), null, 'restore background before returning keyboard focus');
		document.activeElement = toggle;
	};
	return { context, document, toggle, nav, link, priorInert, priorHidden, main, header, headerInner, frames };
}

test('mobile navigation isolates only background branches and restores exact prior attributes', () => {
	const h = themeNavigationHarness();
	const c = h.context;
	const targets = c.navigationIsolationTargets(h.document.body, h.nav);
	assert.deepEqual(Array.from(targets), [h.toggle, h.priorInert, h.main, h.priorHidden]);
	c.openMenu();
	assert.equal(h.document.activeElement, h.toggle, 'focus waits until the newly visible drawer reaches layout');
	assert.equal(h.main.getAttribute('inert'), null, 'background waits for focus to enter the drawer');
	h.frames.shift()();
	assert.equal(h.document.activeElement, h.link);
	for (const target of targets) {
		assert.equal(target.getAttribute('inert'), '');
		assert.equal(target.getAttribute('aria-hidden'), 'true');
	}
	for (const visible of [h.header, h.headerInner, h.nav, h.link]) assert.equal(visible.getAttribute('inert'), null);
	c.isolateNavigationBackground();
	c.closeMenu(true);
	assert.equal(h.document.activeElement, h.toggle);
	assert.equal(h.toggle.getAttribute('aria-expanded'), 'false');
	assert.equal(h.nav.getAttribute('aria-hidden'), 'true');
	assert.equal(h.document.body.classList.contains('dbf-menu-open'), false);
	assert.equal(h.main.getAttribute('inert'), null);
	assert.equal(h.main.getAttribute('aria-hidden'), null);
	assert.equal(h.priorInert.getAttribute('inert'), 'inert');
	assert.equal(h.priorInert.getAttribute('aria-hidden'), 'false');
	assert.equal(h.priorHidden.getAttribute('aria-hidden'), 'true');
	assert.equal(h.priorHidden.getAttribute('inert'), null);
	c.restoreNavigationBackground();
	assert.equal(h.priorInert.getAttribute('inert'), 'inert', 'repeated restoration is harmless');
});

test('desktop breakpoint releases mobile isolation without hiding navigation or moving focus', () => {
	const h = themeNavigationHarness();
	h.context.openMenu();
	h.frames.shift()();
	h.context.mobileQuery.matches = false;
	h.context.syncMenuMode();
	assert.equal(h.nav.getAttribute('aria-hidden'), null);
	assert.equal(h.main.getAttribute('inert'), null);
	assert.equal(h.priorInert.getAttribute('inert'), 'inert');
	assert.equal(h.document.activeElement, h.link);
	h.context.openMenu();
	assert.equal(h.main.getAttribute('inert'), null, 'desktop cannot reopen the mobile isolation path');
});

test('queued mobile navigation focus cannot jump into a closed or desktop drawer', () => {
	const closed = themeNavigationHarness();
	closed.context.openMenu();
	const afterClose = closed.frames.shift();
	closed.context.closeMenu(true);
	afterClose();
	assert.equal(closed.document.activeElement, closed.toggle);
	assert.equal(closed.main.getAttribute('inert'), null);
	assert.equal(closed.nav.getAttribute('aria-hidden'), 'true');

	const desktop = themeNavigationHarness();
	desktop.context.openMenu();
	const afterBreakpoint = desktop.frames.shift();
	desktop.context.mobileQuery.matches = false;
	desktop.context.syncMenuMode();
	afterBreakpoint();
	assert.equal(desktop.document.activeElement, desktop.toggle);
	assert.equal(desktop.main.getAttribute('inert'), null);
	assert.equal(desktop.nav.getAttribute('aria-hidden'), null);
});

test('runtime reduced-motion changes disconnect reveals and late callbacks remain safe', () => {
	const reveal = themeNode({ 'data-dbf-scroll-state': 'below' });
	const observers = [];
	function Observer(callback) {
		this.callback = callback;
		this.observe = element => { this.observed = element; };
		this.unobserve = element => { this.unobserved = element; };
		this.disconnect = () => { this.disconnected = true; };
		observers.push(this);
	}
	const context = {
		motionQuery: { matches: false }, reveals: [reveal], revealObserver: null,
		document: { documentElement: themeNode() }, window: { IntersectionObserver: Observer }, IntersectionObserver: Observer
	};
	for (const name of ['showAllReveals', 'stopRevealObserver', 'startRevealObserver', 'syncMotionPreference']) context[name] = extractFunction(themeScript, name, context);
	context.syncMotionPreference();
	assert.equal(observers.length, 1);
	assert.equal(observers[0].observed, reveal);
	context.motionQuery.matches = true;
	context.syncMotionPreference();
	assert.equal(observers[0].disconnected, true);
	assert.equal(context.revealObserver, null);
	assert.equal(context.document.documentElement.classList.contains('dbf-motion-ok'), false);
	assert.equal(reveal.classList.contains('is-visible'), true);
	assert.equal(reveal.getAttribute('data-dbf-scroll-state'), null);
	assert.doesNotThrow(() => observers[0].callback([{ target: reveal, isIntersecting: true }]));
	context.motionQuery.matches = false;
	context.syncMotionPreference();
	assert.equal(observers.length, 2);
	assert.equal(reveal.classList.contains('is-visible'), true, 'relaxing the preference never hides previously visible content');
	delete context.window.IntersectionObserver;
	context.syncMotionPreference();
	assert.equal(context.revealObserver, null);
	assert.equal(reveal.classList.contains('is-visible'), true, 'unsupported observers retain readable content');
});

test('ordering presentation has opaque tools, static reduced motion and no retired card-motion path', () => {
	const css = fs.readFileSync(path.resolve(__dirname, '..', 'public', 'css', 'doughboss.css'), 'utf8');
	const orderCss = fs.readFileSync(path.resolve(__dirname, '..', 'public', 'css', 'doughboss-order-page.css'), 'utf8');
	const themeCss = fs.readFileSync(path.resolve(__dirname, '..', 'themes', 'doughboss-final', 'style.css'), 'utf8');
	const reducedMotion = themeCss.slice(themeCss.lastIndexOf('@media (prefers-reduced-motion: reduce)'));
	// A tiny duration on the default transition-property:all can delay inherited
	// visibility and reject the drawer's first-frame focus. Browser proof is separate.
	assert.match(reducedMotion, /\*, \*::before, \*::after\s*\{[^}]*transition:\s*none\s*!important;/);
	assert.doesNotMatch(reducedMotion, /transition-duration:\s*\.01ms/);
	const assets = fs.readFileSync(path.resolve(__dirname, '..', 'includes', 'class-doughboss-assets.php'), 'utf8');
	const source = fs.readFileSync(storefront, 'utf8');
	assert.match(css, /\.db-app \.db-menu-tools\s*\{[^}]*background: var\(--db-paper, #f7f5f0\);/);
	assert.match(orderCss, /body\.doughboss-order-page \.db-menu-tools\s*\{[^}]*background: var\(--db-order-paper\);/);
	assert.doesNotMatch(css, /backdrop-filter|db-cardin|--db-spring/);
	assert.doesNotMatch(orderCss, /db-order-page-motion|data-db-scroll|rotateX/);
	assert.doesNotMatch(source, /setProperty\('--db-i'/);
	assert.doesNotMatch(assets, /public\/js\/doughboss-order-page\.js/);
	assert.match(assets, /public\/css\/doughboss-order-page\.css/, 'order-page compatibility styles remain enqueued');
	assert.equal(fs.existsSync(path.resolve(__dirname, '..', 'public', 'js', 'doughboss-order-page.js')), false);
});

test('paused client notice uses neutral copy and preserves configured messages', () => {
	const render = extractFunction(storefront, 'orderingClosedNotice', {
		I18N: {}, el: (tag, attrs, children) => ({ tag, attrs, children })
	});
	const defaultNotice = render('');
	assert.equal(defaultNotice.children[0].attrs.text, 'Online ordering is paused');
	assert.equal(defaultNotice.children[1].attrs.text, 'Browse the menu and check your preferred shop before visiting.');
	assert.equal(render('Kitchen maintenance until Friday.').children[1].attrs.text, 'Kitchen maintenance until Friday.');
	const assets = fs.readFileSync(path.resolve(__dirname, '..', 'includes', 'class-doughboss-assets.php'), 'utf8');
	assert.match(assets, /'comingSoonShort' => __\( 'Ordering paused'/, 'existing localization key stays compatible');
	assert.match(assets, /'orderingComingSoon' => __\( 'Online ordering is paused'/);
	assert.doesNotMatch(fs.readFileSync(storefront, 'utf8'), /'Coming soon'|'Online ordering coming soon'/);
});

test('paused order page keeps only the approved Stripe surface available', () => {
	const page = fs.readFileSync(path.resolve(__dirname, '..', 'themes', 'doughboss-final', 'page-order.php'), 'utf8');
	const source = fs.readFileSync(storefront, 'utf8');
	const themeCss = fs.readFileSync(path.resolve(__dirname, '..', 'themes', 'doughboss-final', 'style.css'), 'utf8');
	const orderPageCss = fs.readFileSync(path.resolve(__dirname, '..', 'public', 'css', 'doughboss-order-page.css'), 'utf8');
	assert.match(page, /class="dbf-cart-wrap"<\?php echo \$ordering_open \? '' : ' hidden'; \?>/,
		'publicly paused pages render the cart host hidden instead of publishing checkout');
	assert.match(page, /\[doughboss_cart\]/, 'the hidden host remains available for a valid approved-payment capability');
	assert.match(source, /document\.body\.classList\.add\('db-approved-preorder-mode'\)/);
	assert.match(source, /var cartWrap = root\.closest\('\.dbf-cart-wrap'\);\s*if \(cartWrap\) \{ cartWrap\.hidden = false; \}/,
		'approved capability mode reveals the hidden cart host');
	assert.ok(source.indexOf('var APPROVED_PREORDER = approvedPreorderCapability();') < source.indexOf('getTableContext().then(function ()'),
		'the bearer is captured before asynchronous bootstrap or API reads');
	assert.match(source, /if \(APPROVED_PREORDER\) \{\s*try \{ window\.history\.replaceState\(\{\}, document\.title, window\.location\.pathname \+ window\.location\.search\); \}/,
		'the capability fragment is scrubbed immediately and retained only in the module closure');
	assert.match(source, /var approvedPreorder = APPROVED_PREORDER;/, 'boot never reparses the scrubbed URL fragment');
	assert.match(source, /function boot\(\) \{\s*var approvedPreorder = APPROVED_PREORDER;\s*if \(approvedPreorder\) \{[\s\S]*?renderApprovedPreorder\(root, approvedPreorder\);[\s\S]*?return;\s*\}[\s\S]*?getTableContext\(\)/,
		'approved payment renders before and independently of table-session bootstrap');
	assert.match(themeCss, /body\.db-approved-preorder-mode \.dbf-page-hero--order,[\s\S]*?\[data-doughboss-shop\] \{ display: none !important; \}/,
		'approved capability mode suppresses the public shopping surfaces');
	assert.match(orderPageCss, /body\.doughboss-order-page \.db-approved-preorder \.db-ordering-status \{[\s\S]*?margin: 1rem 0 0 !important;[\s\S]*?box-shadow: none;/,
		'approved status stays in normal card flow instead of inheriting the hero-overlap treatment');
});

test('approved payment presentation is deterministic and requires explicit start authority', () => {
	const present = extractFunction(storefront, 'approvedPreorderPresentation');
	const asLocal = value => JSON.parse(JSON.stringify(value));
	for (const state of ['held', 'expired', 'payment_disabled', 'complete', 'unavailable']) {
		const result = asLocal(present({ presentation_state: state, can_start_payment: true }));
		assert.equal(result.state, state);
		assert.equal(result.canStart, false, state + ' cannot expose a new payment start');
		assert.ok(result.title && result.body, state + ' has distinct customer copy');
	}
	assert.equal(present({ presentation_state: 'ready', can_start_payment: true }).canStart, true);
	assert.equal(present({ presentation_state: 'ready', can_start_payment: false }).canStart, false);
	assert.equal(present({ presentation_state: 'ready' }).canStart, false, 'missing authority fails closed');
});

test('a refreshed completed summary suppresses the payment verification retry warning', async () => {
	const present = extractFunction(storefront, 'approvedPreorderPresentation');
	const isComplete = extractFunction(storefront, 'isApprovedPreorderComplete', {
		approvedPreorderPresentation: present
	});
	let retryShown = false;
	await Promise.reject(new Error('Synthetic first completion failure')).catch(async () => {
		const refreshed = await Promise.resolve({ presentation_state: 'complete', complete: true });
		if (!isComplete(refreshed)) { retryShown = true; }
	});
	assert.equal(retryShown, false, 'webhook-completed state remains the only customer message');
	assert.equal(isComplete({ presentation_state: 'held', complete: false }), false);
	const source = fs.readFileSync(storefront, 'utf8');
	assert.match(source, /loadSummary\(\)\.then\(function \(summary\) \{\s*if \(!isApprovedPreorderComplete\(summary\)\) \{ showVerificationRetry\(err\); \}/,
		'the actual Stripe-return fallback gates its warning on the refreshed durable state');
});

test('approved payment uses only validated shop telephone links', () => {
	const contact = extractFunction(storefront, 'approvedPreorderContact');
	assert.deepEqual(JSON.parse(JSON.stringify(contact({ contact_phone: '02 9708 2222', contact_tel: 'tel:+61297082222' }))), {
		phone: '02 9708 2222', tel: 'tel:+61297082222'
	});
	for (const value of ['', 'tel:', 'sms:+61400000000', 'tel:javascript:alert(1)', 'tel:+61 400 000 000']) {
		assert.equal(contact({ contact_phone: '0400 000 000', contact_tel: value }), null, value || 'empty');
	}
});

test('Stripe return and native retry verify the exact same payment without starting another', async () => {
	const calls = [];
	const verify = extractFunction(storefront, 'verifyApprovedPreorder', {
		request: (route, options) => { calls.push({ route, options }); return Promise.resolve({ complete: true }); }
	});
	const capability = { requestId: 91, token: 'a'.repeat(64), sessionId: 'cs_test_bound' };
	await verify(capability);
	await verify(capability);
	assert.equal(calls.length, 2);
	for (const call of calls) {
		assert.equal(call.route, '/preorder-payment/complete');
		assert.deepEqual(JSON.parse(JSON.stringify(call.options.body)), { request_id: 91, token: 'a'.repeat(64), session_id: 'cs_test_bound' });
	}
	assert.doesNotMatch(verify.toString(), /preorder-payment\/start/);
	const source = fs.readFileSync(storefront, 'utf8');
	assert.match(source, /class: 'db-btn db-preorder-retry'[\s\S]*?verifyApprovedPreorder\(capability\)/);
	assert.match(source, /if \(capability\.cancelled && !capability\.returning\)/,
		'a client cancellation marker cannot replace the Stripe-return verification path');
	const approvedFlowSource = source.slice(source.indexOf('function renderApprovedPreorder('), source.indexOf('function boot()'));
	assert.doesNotMatch(approvedFlowSource, /localStorage|sessionStorage|type:\s*'hidden'.*doughboss_token|textarea.*doughboss_token/);
});

test('approved capability boot bypasses a broken or revoked table context', () => {
	let tableReads = 0;
	let approvedRenders = 0;
	const cart = {};
	const boot = extractFunction(storefront, 'boot', {
		APPROVED_PREORDER: { requestId: 7 },
		document: { querySelectorAll: selector => selector === '[data-doughboss-cart]' ? [cart] : [] },
		renderApprovedPreorder: (root, capability) => { assert.equal(root, cart); assert.equal(capability.requestId, 7); approvedRenders += 1; },
		renderTracking: () => {},
		getTableContext: () => { tableReads += 1; return Promise.reject(new Error('revoked')); }
	});
	boot();
	assert.equal(approvedRenders, 1);
	assert.equal(tableReads, 0, 'table context cannot block or replace an approved-payment capability');
});

test('ordinary ordering still fails closed when a claimed table session is invalid', async () => {
	let tableReads = 0;
	const blocked = [{ appendChild(node) { this.node = node; } }, { appendChild(node) { this.node = node; } }];
	const boot = extractFunction(storefront, 'boot', {
		APPROVED_PREORDER: null,
		document: {
			querySelectorAll: selector => selector.indexOf(',') !== -1 ? blocked : []
		},
		renderApprovedPreorder: () => assert.fail('approved renderer must not run'),
		renderTracking: () => {}, renderShopPicker: () => {}, renderMenu: () => {}, renderBuilder: () => {}, renderCart: () => {},
		getTableContext: () => { tableReads += 1; return Promise.reject(new Error('revoked')); },
		el: (tag, attrs) => ({ tag, attrs })
	});
	boot();
	await new Promise(resolve => setImmediate(resolve));
	assert.equal(tableReads, 1);
	assert.equal(blocked.every(node => node.node && /scan the table QR again/.test(node.node.attrs.text)), true);
});

test('approver recovery copies only the existing bearer and reports mail handoff honestly', async () => {
	const calls = [];
	const copied = [];
	const messages = [];
	const recover = extractFunction(orderboard, 'recoverPreorderPaymentLink', {
		offline: false,
		setPreorderBusy: () => true,
		api: (route, method, body) => {
			calls.push({ route, method, body });
			return Promise.resolve({ payment_url: 'https://doughboss.test/order/#doughboss_request=9&doughboss_token=' + 'b'.repeat(64) });
		},
		window: { location: { origin: 'https://doughboss.test' } },
		navigator: { clipboard: { writeText: value => { copied.push(value); return Promise.resolve(); } } },
		URL,
		setPreorderMessage: (message, error) => messages.push({ message, error }),
		applyConnectionState: () => {},
		load: () => Promise.resolve()
	});
	await recover({ id: 9 }, 'view');
	assert.deepEqual(JSON.parse(JSON.stringify(calls)), [{ route: '/admin/preorder/9/payment-link', method: 'POST', body: { mode: 'view' } }]);
	assert.equal(copied.length, 1);
	assert.match(messages[0].message, /existing approved payment link was copied securely/i);
	assert.doesNotMatch(recover.toString(), /createElement|textarea|type=['"]hidden/,
		'the bearer has no visible or hidden DOM clipboard fallback');
	const boardSource = fs.readFileSync(orderboard, 'utf8');
	assert.doesNotMatch(boardSource, /awaiting Kristy|Kristy calls first/);
	assert.match(boardSource, /customerStatus === 'accepted'[\s\S]*?Delivery is not guaranteed/);
});

function previewAdapter(paused) {
	const window = { DoughBossPreviewOptions: { menu: [{ id: 1001, image: 'public/images/menu/real-v1/zaatar-cheese.jpg' }] } };
	const listeners = {};
	const document = {
		currentScript: { src: 'https://example.test/DOUGHBOSSV2/preview/preview-api.js' },
		baseURI: 'https://example.test/DOUGHBOSSV2/order.html',
		body: { classList: { contains: name => paused && name === 'dbf-ordering-paused' } },
		addEventListener: (type, handler) => { listeners[type] = handler; },
		getElementById: () => null
	};
	vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, '..', 'scripts/visual-preview/preview-api.js'), 'utf8'), {
		window, document, URL, Request, Response, Date
	});
	return { window, listeners };
}

test('visual preview permits only four synthetic reads and rejects every write or external route', async () => {
	const { window, listeners } = previewAdapter(false);
	const apiRoot = 'https://preview.invalid/doughboss/v1';
	for (const route of ['/config', '/locations', '/table/context', '/menu']) {
		assert.equal((await window.fetch(apiRoot + route)).status, 200, route);
	}
	for (const method of ['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD']) {
		assert.equal((await window.fetch(apiRoot + '/config', { method })).status, 405, method);
	}
	assert.equal((await window.fetch(new Request(apiRoot + '/config', { method: 'POST' }))).status, 405, 'Request method cannot become GET');
	for (const url of [apiRoot + '/cart', apiRoot + '/checkout', apiRoot + '/catering/enquiry']) {
		assert.equal((await window.fetch(url)).status, 404, url);
	}
	assert.equal((await window.fetch('https://preview.invalid/config')).status, 403, 'paths outside the exact API prefix are forbidden');
	assert.equal((await window.fetch('https://doughboss.com.au/wp-json/doughboss/v1/config')).status, 403);
	assert.equal((await window.fetch('https://api.stripe.com/v1/checkout/sessions')).status, 403);
	const config = await (await window.fetch(apiRoot + '/config')).json();
	assert.equal(config.payments_enabled, false);
	assert.equal(config.stripe_pk, '');
	assert.equal(config.ordering_open, true, 'sample open state only');
	assert.equal(window.DoughBossData.payments.enabled, false);
	let prevented = false;
	listeners.submit({ preventDefault: () => { prevented = true; } });
	assert.equal(prevented, true);
});

test('visual preview keeps paused state separate and sample hours short-lived', async () => {
	const { window } = previewAdapter(true);
	const apiRoot = 'https://preview.invalid/doughboss/v1';
	assert.equal((await (await window.fetch(apiRoot + '/config')).json()).ordering_open, false);
	const locations = await (await window.fetch(apiRoot + '/locations')).json();
	assert.equal(locations.length, 2);
	for (const location of locations) {
		assert.match(location.name, /^Sample /);
		assert.equal(location.phone, '');
		const status = location.pickup_status;
		assert.equal(Date.parse(status.expires_at_utc) - Date.parse(status.observed_at_utc), 55000);
	}
	const menu = await (await window.fetch(apiRoot + '/menu')).json();
	assert.equal(menu[0].image, 'https://example.test/DOUGHBOSSV2/public/images/menu/real-v1/zaatar-cheese.jpg');
});

test('preview srcsets permit only existing local assets and truthful ordered width descriptors', () => {
	const validator = path.resolve(__dirname, '..', 'scripts/validate-visual-preview.js');
	const relativeFiles = ['public/images/responsive/hero-480.avif', 'public/images/responsive/hero-960.webp'];
	const localReference = extractFunction(validator, 'localReference', {
		assert, path, manifest: { base_path: '/preview/', output_sha256: Object.fromEntries(relativeFiles.map(file => [file, 'synthetic'])) },
		safeRelative: extractFunction(validator, 'safeRelative', { assert })
	});
	const validate = extractFunction(validator, 'validateSrcset', { assert, localReference });
	assert.doesNotThrow(() => validate('./public/images/responsive/hero-480.avif 480w, ./public/images/responsive/hero-960.webp 960w', 'index.html'));
	assert.doesNotThrow(() => validate('/preview/public/images/responsive/hero-480.avif 480w', 'index.html'));
	for (const value of [
		'https://external.test/public/images/responsive/hero-480.avif 480w',
		'//external.test/public/images/responsive/hero-480.avif 480w',
		'./public/images/responsive/missing-480.avif 480w',
		'./public/images/responsive/hero-480.avif 960w',
		'./public/images/responsive/hero-480.avif 2x',
		'./public/images/responsive/../hero-480.avif 480w',
		'./public/images/responsive/hero-480.avif 480w, ./public/images/responsive/hero-480.avif 480w',
		'./public/images/responsive/hero-960.webp 960w, ./public/images/responsive/hero-480.avif 480w'
	]) assert.throws(() => validate(value, 'index.html'), value);
});

function photoMotionHarness({ reduced = false, legacy = false, noControl = false, noMedia = false } = {}) {
	const frames = [];
	const events = {};
	const query = { matches: reduced };
	const listen = callback => { events.preference = callback; };
	if (legacy) query.addListener = listen;
	else query.addEventListener = (name, callback) => listen(callback);
	const controls = [];
	const heroes = [0, 1].map(() => {
		const control = themeNode({ 'data-db-pause-label': 'Pause photo motion',
			'data-db-resume-label': 'Resume photo motion', 'data-db-reduced-label': 'Photo motion off' });
		control.listeners = {};
		control.addEventListener = (name, callback) => { control.listeners[name] = callback; };
		const hero = themeNode({}, [control]);
		hero.querySelector = () => noControl ? null : control;
		hero.getBoundingClientRect = () => ({ top: -100, bottom: 500, height: 600 });
		hero.writes = [];
		hero.style = { setProperty: (name, value) => hero.writes.push([name, value]) };
		controls.push(control);
		return hero;
	});
	const window = { innerHeight: 800, requestAnimationFrame: callback => frames.push(callback),
		addEventListener: (name, callback) => { events[name] = callback; } };
	if (!noMedia) window.matchMedia = () => query;
	vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../public/js/doughboss-manoush-hero.js'), 'utf8'), {
		window, document: { querySelectorAll: () => heroes }, Math
	});
	return { frames, events, heroes, controls,
		flush: () => { while (frames.length) frames.shift()(); },
		preference: reduced => { query.matches = reduced; events.preference({ matches: reduced }); }
	};
}

test('photo hero starts reduced motion with a truthful disabled control and no queued frames', () => {
	const h = photoMotionHarness({ reduced: true });
	for (const control of h.controls) {
		assert.equal(control.disabled, true);
		assert.equal(control.textContent, 'Photo motion off');
		assert.equal(control.getAttribute('aria-label'), 'Photo motion off');
		assert.equal(control.getAttribute('aria-pressed'), 'true');
		control.listeners.click();
	}
	assert.ok(h.heroes.every(hero => hero._dbPhotoPaused === undefined), 'blocked clicks do not change manual choice');
	h.events.scroll();
	h.events.resize();
	assert.equal(h.frames.length, 0);
	assert.match(fs.readFileSync(path.join(__dirname, '../includes/class-doughboss-shortcodes.php'), 'utf8'),
		/data-db-reduced-label="<\?php esc_attr_e\( 'Photo motion off', 'doughboss' \); \?>"/);
});

test('photo hero retains each manual pause through both system preference transitions', () => {
	const h = photoMotionHarness();
	h.flush();
	h.controls[0].listeners.click();
	const pausedWrites = h.heroes[0].writes.length;
	assert.equal(h.controls[0].textContent, 'Resume photo motion');
	h.preference(true);
	assert.ok(h.controls.every(control => control.disabled && control.textContent === 'Photo motion off'));
	h.preference(false);
	assert.equal(h.controls[0].disabled, false);
	assert.equal(h.controls[0].textContent, 'Resume photo motion');
	assert.equal(h.controls[0].getAttribute('aria-pressed'), 'true');
	assert.equal(h.heroes[0]._dbPhotoPaused, true);
	assert.equal(h.heroes[0].classList.contains('is-photo-paused'), true);
	assert.equal(h.controls[1].textContent, 'Pause photo motion');
	assert.equal(h.controls[1].getAttribute('aria-pressed'), 'false');
	h.flush();
	h.events.scroll();
	h.flush();
	assert.equal(h.heroes[0].writes.length, pausedWrites, 'manual pause prevents later parallax writes');
	assert.ok(h.heroes[1].writes.length > pausedWrites, 'unpaused hero resumes normal motion');
	const css = fs.readFileSync(path.join(__dirname, '../public/css/doughboss-manoush-hero.css'), 'utf8');
	assert.match(css, /\.db-manoush-hero\.is-photo-paused \.db-mh-backdrop\s*\{[^}]*animation: none;/);
	assert.ok(css.indexOf('.is-photo-paused .db-mh-backdrop') > css.indexOf('.is-photo-ready .db-mh-backdrop'));
});

test('photo hero blocks an already queued frame when reduced motion becomes active', () => {
	const h = photoMotionHarness();
	assert.equal(h.frames.length, 1);
	h.preference(true);
	h.flush();
	assert.ok(h.heroes.every(hero => hero.writes.length === 0));
	h.events.scroll();
	assert.equal(h.frames.length, 0);
	h.preference(false);
	h.flush();
	assert.ok(h.controls.every(control => !control.disabled && control.textContent === 'Pause photo motion'));
	assert.ok(h.heroes.every(hero => hero.writes.length === 2));
});

test('photo hero supports legacy preference listeners and absent controls', () => {
	const h = photoMotionHarness({ reduced: true, legacy: true, noControl: true });
	assert.equal(h.frames.length, 0);
	assert.doesNotThrow(() => h.preference(false));
	h.flush();
	assert.ok(h.heroes.every(hero => hero.writes.length === 2));
});

test('photo hero preserves ordinary controls without matchMedia', () => {
	const h = photoMotionHarness({ noMedia: true });
	assert.ok(h.controls.every(control => !control.disabled && control.textContent === 'Pause photo motion'));
	h.flush();
	h.controls[0].listeners.click();
	assert.equal(h.controls[0].textContent, 'Resume photo motion');
	assert.equal(h.controls[0].getAttribute('aria-pressed'), 'true');
});

console.log('Storefront helper regression checks passed.');

test('Square hosted checkout opens only Square payment-link hosts for the active mode', () => {
	const valid = extractFunction(storefront, 'squareHostedCheckoutUrlValid');
	assert.equal(valid('https://square.link/u/AbC123', true), true);
	assert.equal(valid('https://checkout.square.site/merchant/x/order/y', true), true);
	assert.equal(valid('https://sandbox.square.link/u/jUjglZiR', false), true);
	for (const [url, live] of [
		['https://sandbox.square.link/u/x', true], ['https://square.link/u/x', false], ['http://square.link/u/x', true],
		['https://square.link.evil.test/u/x', true], ['https://evil.test/square.link/', true], ['javascript:alert(1)', true],
		['https://square.link/u/"x', true], ['https://square.link', true], [null, true], ['https://square.link/u/x#frag', true]
	]) {
		assert.equal(valid(url, live), false, String(url) + ' ' + live);
	}
});

test('Square hosted return key and saved checkout resume are strict and time bounded', () => {
	const key = 'ab'.repeat(32);
	const returned = extractFunction(storefront, 'squareHostedReturnKey');
	assert.equal(returned('?doughboss_square_return=' + key), key);
	assert.equal(returned('?page_id=7&doughboss_square_return=' + key + '&orderId=x'), key);
	assert.equal(returned('?doughboss_square_return=' + key.toUpperCase()), '');
	assert.equal(returned('?doughboss_square_return=' + key + 'a'), '');
	assert.equal(returned('?xdoughboss_square_return=' + key), '');
	assert.equal(returned('?doughboss_square_return=' + key + '?orderId=sq123'), key, 'Square-appended parameters do not hide the key');
	const fresh = extractFunction(storefront, 'squareHostedPendingFresh');
	const now = 1_800_000_000_000;
	const pending = { checkoutReference: key, payload: { payment_attempt_key: 'attempt-key-1' }, checkoutAttemptId: 'checkout-attempt-0001', savedAt: now - 1000 };
	assert.equal(fresh(pending, now), true);
	assert.equal(fresh(Object.assign({}, pending, { savedAt: now - 45 * 60 * 1000 }), now), false, 'expired lease');
	assert.equal(fresh(Object.assign({}, pending, { savedAt: now + 1000 }), now), false, 'future timestamp');
	assert.equal(fresh(Object.assign({}, pending, { checkoutReference: 'cs_test_x' }), now), false);
	assert.equal(fresh(Object.assign({}, pending, { payload: {} }), now), false);
	assert.equal(fresh(Object.assign({}, pending, { checkoutAttemptId: 'short' }), now), false);
	assert.equal(fresh(null, now), false);
});

test('Square hosted confirmation polls only unconfirmed answers and is bounded', async () => {
	const confirm = extractFunction(storefront, 'confirmSquareHosted', { Math, Number });
	const waits = [];
	const wait = ms => { waits.push(ms); return Promise.resolve(); };
	const error = (code, data) => Object.assign(new Error(code), { code, data });
	let calls = 0;
	const receipt = await confirm(() => { calls += 1; return calls < 3 ? Promise.reject(error('doughboss_square_hosted_unpaid', { hosted_state: 'unpaid', payment_pending: true })) : Promise.resolve({ order_number: 'DB-1' }); }, wait, 5);
	assert.equal(receipt.order_number, 'DB-1');
	assert.equal(calls, 3);
	assert.deepEqual(waits, [0, 2000, 3000]);

	calls = 0;
	await assert.rejects(confirm(() => { calls += 1; return Promise.reject(error('doughboss_square_hosted_pending', { hosted_state: 'pending' })); }, wait, 5));
	assert.equal(calls, 5, 'never more than five confirmation reads');

	calls = 0;
	await assert.rejects(confirm(() => { calls += 1; return Promise.reject(error('doughboss_square_hosted_closed', { hosted_state: 'failed', outcome: 'terminal_no_charge' })); }, wait, 5));
	assert.equal(calls, 1, 'a closed checkout is not polled');

	calls = 0;
	await assert.rejects(confirm(() => { calls += 1; return Promise.reject(error('doughboss_pay_mismatch', { payment_pending: true })); }, wait, 5));
	assert.equal(calls, 1, 'a held payment is not polled');

	calls = 0;
	await assert.rejects(confirm(() => { calls += 1; return Promise.reject(error('doughboss_square_hosted_unpaid', { hosted_state: 'unpaid' })); }, wait, 1));
	assert.equal(calls, 1, 'a non-return page visit checks once');
	assert.doesNotMatch(confirm.toString(), /payment-intent/);
});

test('Square hosted storefront wiring never loads card fields and confirms only through /checkout', () => {
	const source = fs.readFileSync(storefront, 'utf8');
	const squareSource = fs.readFileSync(square, 'utf8');
	assert.match(source, /var squareHosted = !!\(PAY\.enabled && GATEWAY === 'square' && PAY\.squareHosted === true\);/);
	assert.match(squareSource, /var ordinaryEnabled = !!\(PAY\.enabled && PAY\.squareHosted !== true && CONFIG\.applicationId && CONFIG\.locationId\);/,
		'ordinary Square SDK fields stay dormant in hosted mode');
	const hostedBranch = source.slice(source.indexOf('} else if (squareHosted) {'), source.indexOf('} else if (stripeHosted) {'));
	assert.match(hostedBranch, /request\('\/payment-intent'/);
	assert.doesNotMatch(hostedBranch, /source_id|verification_token|tender_kind|tokenize/);
	assert.match(hostedBranch, /squareHostedCheckoutUrlValid\(session\.checkout_url, !!PAY\.liveMode\)/);
	assert.match(hostedBranch, /window\.location\.assign\(session\.checkout_url\)/);
	const finish = source.slice(source.indexOf('function finishSquareHosted('), source.indexOf('function completeOrder('));
	assert.match(finish, /request\('\/checkout'/);
	assert.match(finish, /square_checkout: pending\.checkoutReference/);
	assert.doesNotMatch(finish, /payment-intent/);
	const cancel = source.slice(source.indexOf("squareHostedCancel.addEventListener('click'"), source.indexOf('// Tyro Connect: hosted payment form'));
	assert.match(cancel, /paymentAttemptKey = newPaymentAttemptKey\(\);/, 'changing the order starts a new attempt key');
	assert.doesNotMatch(cancel, /request\(/, 'cancelling makes no payment call');
	assert.match(source, /if \(data\.hosted_state === 'unpaid'\) \{\s*squareHostedCancel\.hidden = false;/, 'change-order is offered only for a server-confirmed unpaid checkout');
	const assets = fs.readFileSync(path.resolve(__dirname, '..', 'includes', 'class-doughboss-assets.php'), 'utf8');
	assert.match(assets, /'squareHosted'\s*=> \$square_hosted,/);
	assert.match(assets, /\} elseif \( 'square' === \$gateway && ! \$square_hosted \) \{/, 'no Web Payments SDK enqueue in hosted mode');
});

test('2.48 remember-my-details storage is opt-in, bounded and survives blocked storage', () => {
	const ctx = { REMEMBER_DETAILS_KEY: 'doughboss_remember_details', REMEMBER_DETAILS_FIELDS: ['customer_name', 'customer_email', 'customer_phone'], JSON };
	const read = extractFunction(storefront, 'readRememberedDetails', ctx);
	const write = extractFunction(storefront, 'writeRememberedDetails', ctx);
	const clear = extractFunction(storefront, 'clearRememberedDetails', ctx);
	const store = {};
	const storage = { getItem: k => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = String(v); }, removeItem: k => { delete store[k]; } };
	assert.equal(read(storage), null, 'nothing remembered by default');
	assert.equal(write(storage, { customer_name: ' Synthetic Customer ', customer_email: 'synthetic@example.invalid', customer_phone: '0400000000', address: '1 Secret St', notes: 'x' }), true);
	assert.deepEqual(JSON.parse(store.doughboss_remember_details), { customer_name: 'Synthetic Customer', customer_email: 'synthetic@example.invalid', customer_phone: '0400000000' }, 'only name, email and phone are stored');
	assert.deepEqual(JSON.parse(JSON.stringify(read(storage))), { customer_name: 'Synthetic Customer', customer_email: 'synthetic@example.invalid', customer_phone: '0400000000' });
	clear(storage);
	assert.equal(read(storage), null, 'unticking clears it');
	store.doughboss_remember_details = '{not json';
	assert.equal(read(storage), null, 'corrupt data is ignored');
	store.doughboss_remember_details = JSON.stringify({ customer_name: 'x'.repeat(500) });
	assert.equal(read(storage).customer_name.length, 200, 'values are bounded');
	const blocked = { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); }, removeItem() { throw new Error('blocked'); } };
	assert.equal(read(blocked), null);
	assert.equal(write(blocked, { customer_name: 'a' }), false);
	assert.doesNotThrow(() => clear(blocked));
	assert.equal(read(null), null);
	const source = fs.readFileSync(storefront, 'utf8');
	assert.match(source, /rememberDetailsControl\(\{ customer_name: name, customer_email: email, customer_phone: phone \}\)/, 'checkout form renders the opt-in control');
	assert.match(source, /Remember my details on this device/);
});

test('2.48 remember-my-details control prefills empty fields and unticking clears storage', () => {
	const ctx = { REMEMBER_DETAILS_KEY: 'doughboss_remember_details', REMEMBER_DETAILS_FIELDS: ['customer_name', 'customer_email', 'customer_phone'], JSON };
	const store = { doughboss_remember_details: JSON.stringify({ customer_name: 'Synthetic', customer_email: 'saved@example.invalid', customer_phone: '0400000001' }) };
	const storage = { getItem: k => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = String(v); }, removeItem: k => { delete store[k]; } };
	function input(value) { return { value, listeners: {}, addEventListener(type, cb) { this.listeners[type] = cb; } }; }
	const inputs = { customer_name: input(''), customer_email: input('typed@example.invalid'), customer_phone: input('') };
	const wraps = {};
	Object.keys(inputs).forEach(key => { wraps[key] = { querySelector: () => inputs[key] }; });
	let checkbox;
	const control = extractFunction(storefront, 'rememberDetailsControl', Object.assign({}, ctx, {
		I18N: {},
		rememberStorage: () => storage,
		readRememberedDetails: extractFunction(storefront, 'readRememberedDetails', ctx),
		writeRememberedDetails: extractFunction(storefront, 'writeRememberedDetails', ctx),
		clearRememberedDetails: extractFunction(storefront, 'clearRememberedDetails', ctx),
		el: (tag, attrs, children) => {
			const node = { tag, attrs, children, listeners: {}, addEventListener(type, cb) { this.listeners[type] = cb; } };
			if (attrs && attrs.type === 'checkbox') { checkbox = node; }
			return node;
		}
	}));
	control(wraps);
	assert.equal(checkbox.checked, true, 'a saved preference shows as ticked');
	assert.equal(inputs.customer_name.value, 'Synthetic');
	assert.equal(inputs.customer_email.value, 'typed@example.invalid', 'never overwrites what the customer typed');
	assert.equal(inputs.customer_phone.value, '0400000001');
	inputs.customer_phone.value = '0400000002';
	inputs.customer_phone.listeners.change();
	assert.equal(JSON.parse(store.doughboss_remember_details).customer_phone, '0400000002', 'edits are saved while ticked');
	checkbox.checked = false;
	checkbox.listeners.change();
	assert.equal(Object.hasOwn(store, 'doughboss_remember_details'), false, 'unticking deletes the saved details');
	inputs.customer_name.listeners.change();
	assert.equal(Object.hasOwn(store, 'doughboss_remember_details'), false, 'nothing is saved while unticked');
});

test('2.48 order board requests a screen wake lock and fails silently', async () => {
	const requests = [];
	const ctx = { wakeLock: null, wakeLockPending: false, document: { hidden: false }, window: { navigator: {} },
		navigator: { wakeLock: { request: type => { requests.push(type); return Promise.resolve({ released: false, addEventListener() {} }); } } } };
	ctx.window.navigator = ctx.navigator;
	const request = extractFunction(orderboard, 'requestWakeLock', ctx);
	request();
	request();
	await new Promise(resolve => setImmediate(resolve));
	assert.deepEqual(requests, ['screen'], 'one in-flight request for the screen lock');
	for (const nav of [{}, { wakeLock: {} }, { wakeLock: { request: () => Promise.reject(new Error('NotAllowedError')) } }, { wakeLock: { request: () => { throw new Error('sync'); } } }]) {
		const unsupported = extractFunction(orderboard, 'requestWakeLock', { wakeLock: null, wakeLockPending: false, document: { hidden: false }, window: { navigator: nav }, navigator: nav });
		assert.doesNotThrow(() => unsupported());
	}
	await new Promise(resolve => setImmediate(resolve));
	const hidden = extractFunction(orderboard, 'requestWakeLock', Object.assign({}, ctx, { wakeLock: null, document: { hidden: true } }));
	hidden();
	assert.equal(requests.length, 1, 'a hidden page never requests the lock');
	const source = fs.readFileSync(orderboard, 'utf8');
	assert.match(source, /document\.addEventListener\('visibilitychange', function \(\) \{[\s\S]*?requestWakeLock\(\);/, 're-acquired on visibilitychange');
});

test('2.48 shop status polls every 120s, refreshes early only for expired open evidence, and stops when hidden', () => {
	const now = Date.parse('2026-10-15T01:00:00Z');
	const open = { ordering_open: true };
	const fresh = { pickup_status: { expires_at_utc: '2026-10-15T01:00:30Z' } };
	const expired = { pickup_status: { expires_at_utc: '2026-10-15T00:59:59Z' } };
	function due(watches, lastRefresh) {
		const evidenceExpired = extractFunction(shopStatus, 'evidenceExpired', { watches, Date, Number });
		return extractFunction(shopStatus, 'refreshDue', { REFRESH_MS: 120000, EXPIRED_REFRESH_MS: 30000, lastRefresh, evidenceExpired })(now);
	}
	assert.equal(due([{ config: open, location: fresh }], now - 60000), false, 'no 45-second polling any more');
	assert.equal(due([{ config: open, location: fresh }], now - 120000), true, '120-second refresh');
	assert.equal(due([{ config: open, location: expired }], now - 31000), true, 'expired open evidence refreshes early');
	assert.equal(due([{ config: open, location: expired }], now - 10000), false, 'but never more often than every 30 seconds');
	assert.equal(due([{ config: { ordering_open: false }, location: expired }], now - 60000), false, 'paused ordering needs no fresh evidence');
	let scheduled = 0;
	let cleared = 0;
	const tick = extractFunction(shopStatus, 'tick', { document: { hidden: true }, tickTimer: 7, clearTimeout: () => { cleared++; },
		paint: () => { throw new Error('must not paint while hidden'); }, scheduleTick: () => { scheduled++; }, watches: [], refreshDue: () => true, Date });
	tick();
	assert.equal(scheduled, 0, 'a hidden tab stops rescheduling');
	assert.equal(cleared, 1, 'and clears the pending timer');
	assert.match(fs.readFileSync(shopStatus, 'utf8'), /var REFRESH_MS = 120000;/);
});

test('2.48 wallet buttons validate the form synchronously before tokenize and surface failures', () => {
	const source = fs.readFileSync(square, 'utf8');
	const wallets = source.slice(source.indexOf('function mountWallets('), source.indexOf('function tokenizeTender('));
	const apple = wallets.slice(wallets.indexOf("btn.addEventListener('click'"), wallets.indexOf('payments.googlePay('));
	const google = wallets.slice(wallets.indexOf("holder.addEventListener('click'"));
	for (const handler of [apple, google]) {
		assert.ok(handler.indexOf('if (!formReady()) { return; }') !== -1, 'validity gate present');
		assert.ok(handler.indexOf('formReady()') < handler.indexOf('.tokenize()'), 'validity is checked before tokenize');
		assert.doesNotMatch(handler.slice(0, handler.indexOf('.tokenize()')), /\.then\(|await /, 'nothing asynchronous runs before tokenize (Apple Pay gesture)');
		assert.match(handler, /\.catch\(function \(\) \{ walletFailed\(null\); \}\)/, 'a thrown tokenize shows an error');
	}
	assert.match(wallets, /form\.reportValidity\(\)/);
	assert.match(wallets, /if \(!result \|\| result\.status !== 'OK' \|\| !result\.token\) \{ walletFailed\(result\); return; \}/, 'non-OK tokenize is visible');
	assert.match(wallets, /status\.className = 'db-pay-secure db-error';/);
});

test('2.48 Square SDK is enqueued only on checkout pages and loads on demand elsewhere', () => {
	const assets = fs.readFileSync(path.resolve(__dirname, '..', 'includes', 'class-doughboss-assets.php'), 'utf8');
	const branch = assets.slice(assets.indexOf("} elseif ( 'square' === $gateway && ! $square_hosted ) {"), assets.indexOf('Stripe Checkout is a full-page provider-hosted redirect'));
	assert.match(branch, /if \( \$is_order_page \|\| \$this->current_post_has\( 'doughboss_cart' \) \) \{\s*wp_enqueue_script\( 'square-web-payments'/);
	const source = fs.readFileSync(square, 'utf8');
	const payments = source.slice(source.indexOf('function squarePayments('), source.indexOf('function mountCard('));
	assert.match(payments, /loadApprovedSquareSdk\(\{ liveMode: CONFIG\.liveMode === true \}\)/, 'ordinary checkout reuses the exact-CDN loader');
	assert.match(payments, /paymentsPromise = null;\s*throw err;/, 'a failed load can be retried');
});

test('2.48 theme quick wins: distinct menu/order H1 and titles, hero alts, catering notice copy', () => {
	const theme = file => fs.readFileSync(path.resolve(__dirname, '..', 'themes', 'doughboss-final', file), 'utf8');
	const order = theme('page-order.php');
	assert.match(order, /\$is_menu_page = is_page\( 'menu' \);/);
	assert.match(order, /<\?php if \( \$is_menu_page \) : \?>\s*<\?php esc_html_e\( 'Our', 'doughboss-final' \); \?> <em><\?php esc_html_e\( 'menu\.', 'doughboss-final' \); \?><\/em>/);
	const seo = fs.readFileSync(path.resolve(__dirname, '..', 'includes', 'class-doughboss-seo.php'), 'utf8');
	assert.match(seo, /if \( is_page\( 'order' \) \) \{/, '/order/ has its own title copy');
	assert.match(seo, /if \( is_page\( 'menu' \) \|\|/, '/menu/ keeps the menu title');
	for (const file of ['page-about-us.php', 'page-locations.php', 'page-order.php', 'page-track-order.php', 'page.php']) {
		assert.doesNotMatch(theme(file), /doughboss_final_asset_image\( '[^']+', '', array\( 'class' => 'dbf-page-hero-bg'/, file + ' hero has descriptive alt text');
	}
	const shortcodes = fs.readFileSync(path.resolve(__dirname, '..', 'includes', 'class-doughboss-shortcodes.php'), 'utf8');
	assert.match(shortcodes, /'image_alt'\s*=> __\( 'Dough Boss manoush and pies fresh from the oven', 'doughboss' \)/);
	assert.doesNotMatch(shortcodes, /class="db-mh-backdrop" src="' \. esc_url\( \$image_url \) \. '" alt=""/);
	assert.match(shortcodes, /Pickup from Revesby · minimum %d business days’ notice/);
	assert.match(theme('functions.php'), /Pickup from Revesby · minimum %d business days’ notice/);
	assert.match(theme('page-catering.php'), /description="' \. \$dbf_catering_hero_description \. '"/);
});
