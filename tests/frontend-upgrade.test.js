'use strict';

// 2.49.0 / theme 1.8.0 front-end upgrade: helper-level checks that need no
// browser. Run with: node tests/frontend-upgrade.test.js

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

const root = path.resolve(__dirname, '..');
const storefront = path.join(root, 'public', 'js', 'doughboss.js');
const catering = path.join(root, 'public', 'js', 'doughboss-catering.js');
const shopStatus = path.join(root, 'public', 'js', 'doughboss-shop-status.js');
const b2b = path.join(root, 'public', 'js', 'doughboss-b2b.js');
const hero = path.join(root, 'public', 'js', 'doughboss-manoush-hero.js');
const themeScript = path.join(root, 'themes', 'doughboss-final', 'assets', 'theme.js');

function extractFunction(file, name, context) {
	const source = fs.readFileSync(file, 'utf8');
	const start = source.indexOf('function ' + name + '(');
	assert.notEqual(start, -1, name + ' must exist in ' + path.basename(file));
	let depth = 0;
	let started = false;
	for (let index = start; index < source.length; index += 1) {
		if (source[index] === '{') { started = true; depth += 1; }
		else if (source[index] === '}' && started) {
			depth -= 1;
			if (depth === 0) return vm.runInNewContext('(' + source.slice(start, index + 1) + ')', context || {});
		}
	}
	throw new Error(name + ' has no complete body');
}

function node(tag, attrs, children) {
	const element = {
		tag, attributes: {}, children: [], className: '', textContent: '', hidden: false, listeners: {}, parentNode: null,
		setAttribute(key, value) { this.attributes[key] = String(value); },
		getAttribute(key) { return Object.hasOwn(this.attributes, key) ? this.attributes[key] : null; },
		removeAttribute(key) { delete this.attributes[key]; },
		appendChild(child) { child.parentNode = this; this.children.push(child); return child; },
		addEventListener(type, callback) { this.listeners[type] = callback; },
		querySelector(selector) {
			const want = selector.replace(/^\./, '');
			for (const child of this.children) {
				if (child.tag === selector || (child.className || '').split(' ').includes(want)) return child;
				const found = child.querySelector ? child.querySelector(selector) : null;
				if (found) return found;
			}
			return null;
		}
	};
	for (const [key, value] of Object.entries(attrs || {})) {
		if (key === 'class') element.className = value;
		else if (key === 'text') element.textContent = value;
		else if (key === 'hidden' || key === 'disabled' || key === 'type' || key === 'href') element[key] = value;
		else element.setAttribute(key, value);
	}
	for (const child of children || []) if (child) element.appendChild(typeof child === 'string' ? node('#text', { text: child }) : child);
	return element;
}

function textOf(element) {
	return [element.textContent].concat((element.children || []).map(textOf)).join(' ').replace(/\s+/g, ' ').trim();
}

function find(element, predicate) {
	if (predicate(element)) return element;
	for (const child of element.children || []) {
		const match = find(child, predicate);
		if (match) return match;
	}
	return null;
}

/* ------------------------------------------------------------------ */
/* Menu                                                                */
/* ------------------------------------------------------------------ */

const compactMenuCategory = extractFunction(storefront, 'compactMenuCategory');

function menuCardFactory() {
	const context = {
		I18N: {}, el: node, money: value => '$' + Number(value).toFixed(2), dietaryBadges: () => [],
		compactMenuCategory, menuPhoto: () => node('div', { class: 'db-card-img' }),
		menuLocationId: () => 1, dbToast() {}, request() { return Promise.resolve(); }
	};
	return extractFunction(storefront, 'menuCard', context);
}

test('search uses an inline SVG icon and a clear button, never the U+2315 glyph', () => {
	const source = fs.readFileSync(storefront, 'utf8');
	assert.doesNotMatch(source, /⌕/);
	assert.match(source, /svgIcon\('db-menu-search-icon'/);
	assert.match(source, /class: 'db-menu-search-clear'/);
	assert.match(source, /clearSearch\.hidden = !search\.value;/);
});

test('paused menu cards carry no per-card ordering controls', () => {
	const menuCard = menuCardFactory();
	const item = { id: 7, name: 'Zaatar', price: 4.5, category: 'Manoush', image: 'x.jpg', options: [{ id: 'style', type: 'radio', label: 'Style', choices: [{ slug: 'flat', label: 'Flat', default: true }] }] };
	const card = menuCard(item, false, 0, true);
	const text = textOf(card);
	assert.doesNotMatch(text, /Customise when ordering opens|Ordering paused|Coming soon|Add to cart/);
	assert.equal(find(card, n => n.tag === 'button'), null, 'no disabled buttons on paused cards');
	assert.match(text, /\$4\.50/, 'price still shown');
});

test('open ordering restores the normal add-to-cart control', () => {
	const menuCard = menuCardFactory();
	const card = menuCard({ id: 8, name: 'Cheese', price: 9, category: 'Manoush', image: 'x.jpg', options: [] }, true, 1, false);
	const button = find(card, n => n.tag === 'button');
	assert.ok(button, 'add button rendered');
	assert.equal(button.textContent, 'Add to cart');
	assert.notEqual(button.disabled, true);
});

test('drinks render as compact rows without photo frames', () => {
	assert.equal(compactMenuCategory('Drinks'), true);
	assert.equal(compactMenuCategory(' drink '), true);
	assert.equal(compactMenuCategory('Pizza'), false);
	const menuCard = menuCardFactory();
	const card = menuCard({ id: 9, name: 'Coke 600ml', price: 4.5, category: 'Drinks', image: 'juice.webp', options: [] }, false, 0, true);
	assert.match(card.className, /db-card--compact/);
	assert.equal(find(card, n => /db-card-img/.test(n.className || '')), null, 'no image frame');
	const soldOut = menuCard({ id: 10, name: 'Sprite 600ml', price: 4.5, category: 'Drinks', image: '', available: false, options: [] }, false, 0, true);
	assert.match(textOf(soldOut), /Sold out/, 'compact sold-out state is still announced');
});

test('items without photos get the warm branded tile and no invented description', () => {
	const menuCard = menuCardFactory();
	const card = menuCard({ id: 11, name: 'Cheese Kaak', price: 7, category: 'Manoush', image: '', description: '', options: [] }, false, 0, true);
	const media = find(card, n => /db-card-img--placeholder/.test(n.className || ''));
	assert.ok(media, 'placeholder tile');
	assert.match(textOf(media), /Dough Boss/);
	assert.equal(find(card, n => n.className === 'db-card-desc'), null, 'missing descriptions stay empty');
});

test('the single call-to-order bar follows the live ordering state', () => {
	const inserted = [];
	const body = { classList: { toggle(name, on) { this[name] = on; } } };
	let existing = null;
	const document = { body, querySelector: () => existing };
	const phoneOrderBar = extractFunction(storefront, 'phoneOrderBar', { DATA: { orderPhone: { display: '(02) 9774 2286', tel: '+61297742286' } }, I18N: {}, el: node, svgIcon: () => node('svg') });
	const sync = extractFunction(storefront, 'syncPhoneOrderBar', { document, phoneOrderBar });
	const menuRoot = { parentNode: { insertBefore(child) { inserted.push(child); existing = child; } }, nextSibling: null };
	sync(menuRoot, true);
	assert.equal(inserted.length, 1, 'bar created when the server did not render one');
	assert.equal(existing.hidden, false);
	assert.equal(find(existing, n => n.tag === 'a').href, 'tel:+61297742286');
	assert.match(textOf(existing), /Call to order \(02\) 9774 2286/);
	assert.equal(body.classList['db-has-callbar'], true);
	sync(menuRoot, false);
	assert.equal(existing.hidden, true, 'hidden as soon as ordering opens');
	assert.equal(body.classList['db-has-callbar'], false);
	assert.equal(inserted.length, 1);
	const untrusted = extractFunction(storefront, 'phoneOrderBar', { DATA: { orderPhone: { display: 'x', tel: 'javascript:alert(1)' } }, I18N: {}, el: node, svgIcon: () => node('svg') })();
	assert.equal(find(untrusted, n => n.tag === 'a').href, 'tel:+61297742286', 'only an Australian E.164 number becomes a tel: link');
});

test('menu shortcode renders the server-side bar hidden while ordering is open', () => {
	const source = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-shortcodes.php'), 'utf8');
	assert.match(source, /data-doughboss-callbar/);
	assert.match(source, /DoughBoss_Settings::ordering_open\(\) \? ' hidden' : ''/);
});

/* ------------------------------------------------------------------ */
/* Header / ordering state                                             */
/* ------------------------------------------------------------------ */

test('header ordering controls swap between call-to-order and online ordering', () => {
	function link(attrs, href, text) {
		const element = node('a', {}, []);
		Object.assign(element.attributes, attrs, { href });
		element.textContent = text;
		return element;
	}
	const navLink = link({ 'data-db-open-href': '/order/', 'data-db-open-label': 'Order Online', 'data-db-paused-href': 'tel:+61297742286', 'data-db-paused-label': 'Call to order' }, 'tel:+61297742286', 'Call to order');
	const cta = link({ 'data-db-open-label': 'Order now', 'data-db-paused-label': 'Browse menu' }, '/order/', 'Browse menu');
	const banner = node('div', { 'data-db-when-ordering': 'paused' });
	const classes = {};
	const document = {
		body: { classList: { toggle(name, on) { classes[name] = on; } } },
		querySelectorAll: selector => selector === '[data-db-ordering-swap]' ? [navLink, cta] : [banner]
	};
	const sync = extractFunction(shopStatus, 'syncOrderingState', { document, Array });
	sync(true);
	assert.equal(navLink.getAttribute('href'), '/order/');
	assert.equal(navLink.textContent, 'Order Online');
	assert.equal(cta.textContent, 'Order now');
	assert.equal(cta.getAttribute('href'), '/order/', 'labels without hrefs keep their link');
	assert.equal(banner.hidden, true);
	assert.equal(classes['dbf-ordering-open'], true);
	sync(false);
	assert.equal(navLink.getAttribute('href'), 'tel:+61297742286');
	assert.equal(navLink.textContent, 'Call to order');
	assert.equal(cta.textContent, 'Browse menu');
	assert.equal(banner.hidden, false);
	assert.equal(classes['dbf-ordering-paused'], true);
});

test('mobile toggle is an SVG icon button with no raw "Menu ×" text', () => {
	const header = fs.readFileSync(path.join(root, 'themes', 'doughboss-final', 'header.php'), 'utf8');
	assert.doesNotMatch(header, /&times;|×/);
	assert.doesNotMatch(header, /dbf-menu-toggle-label/);
	assert.match(header, /aria-expanded="false" aria-controls="dbf-primary-nav"/);
	assert.match(header, /dbf-menu-icon--open/);
	assert.match(header, /dbf-menu-icon--close/);
	const css = fs.readFileSync(path.join(root, 'themes', 'doughboss-final', 'style.css'), 'utf8');
	assert.match(css, /\.dbf-menu-toggle\[aria-expanded="true"\] \.dbf-menu-icon--close \{ display: block; \}/);
	const footer = fs.readFileSync(path.join(root, 'themes', 'doughboss-final', 'footer.php'), 'utf8');
	assert.match(footer, /Franchising & partners/);
	assert.doesNotMatch(footer, />Partners</);
});

/* ------------------------------------------------------------------ */
/* Hero                                                                */
/* ------------------------------------------------------------------ */

test('photo-motion corner toggle updates its label span and keeps its icons', () => {
	const label = { textContent: 'Pause photo motion' };
	const attrs = { 'data-db-pause-label': 'Pause photo motion', 'data-db-resume-label': 'Resume photo motion', 'data-db-reduced-label': 'Photo motion off', title: 'Pause photo motion' };
	const control = {
		textContent: 'ICONS', listeners: {}, disabled: false,
		querySelector: selector => selector === '[data-db-motion-label]' ? label : null,
		getAttribute: name => Object.hasOwn(attrs, name) ? attrs[name] : null,
		setAttribute: (name, value) => { attrs[name] = String(value); },
		hasAttribute: name => Object.hasOwn(attrs, name),
		addEventListener(name, callback) { this.listeners[name] = callback; }
	};
	const heroNode = { _dbPhotoPaused: false, querySelector: () => control, classList: { add() {}, toggle() {} }, getBoundingClientRect: () => ({ top: 0, bottom: 10, height: 10 }), style: { setProperty() {} } };
	const window = { matchMedia: () => ({ matches: false, addEventListener() {} }), requestAnimationFrame() {}, addEventListener() {}, innerHeight: 800 };
	vm.runInNewContext(fs.readFileSync(hero, 'utf8'), { window, document: { querySelectorAll: () => [heroNode] }, Math });
	control.listeners.click();
	assert.equal(label.textContent, 'Resume photo motion');
	assert.equal(control.textContent, 'ICONS', 'icons are not replaced by text');
	assert.equal(attrs['aria-pressed'], 'true');
	assert.equal(attrs['aria-label'], 'Resume photo motion');
	assert.equal(attrs.title, 'Resume photo motion');
	const shortcodes = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-shortcodes.php'), 'utf8');
	const actions = shortcodes.slice(shortcodes.indexOf('<div class="db-mh-actions">'), shortcodes.indexOf('</div>', shortcodes.indexOf('<div class="db-mh-actions">')));
	assert.doesNotMatch(actions, /db-mh-replay/, 'motion control is no longer a CTA pill');
	assert.doesNotMatch(shortcodes, /moves gently as you scroll/, 'helper caption removed');
	assert.match(shortcodes, /<\/strong> <span class="db-mh-proof-label">/, 'stat values and labels are separated by a space');
	const css = fs.readFileSync(path.join(root, 'public', 'css', 'doughboss-manoush-hero.css'), 'utf8');
	assert.match(css, /prefers-reduced-motion: reduce\)[\s\S]*\.db-mh-replay \{ display: none; \}/);
	const front = fs.readFileSync(path.join(root, 'themes', 'doughboss-final', 'front-page.php'), 'utf8');
	assert.doesNotMatch(front, /dbf-hero-stats/, 'duplicate home stat strip removed');
});

/* ------------------------------------------------------------------ */
/* Store cards                                                         */
/* ------------------------------------------------------------------ */

const sydneyClock = extractFunction(themeScript, 'sydneyClock', { Intl, Number, isFinite, String });
const formatStoreClock = extractFunction(themeScript, 'formatStoreClock', { Number, String });
const storeOpenState = extractFunction(themeScript, 'storeOpenState', { Array });
const storeBadgeLabel = extractFunction(themeScript, 'storeBadgeLabel', { formatStoreClock });
const everyDay = (open, close) => Object.fromEntries([1, 2, 3, 4, 5, 6, 7].map(day => [day, [[open, close]]]));
const weekdays = (open, close) => Object.fromEntries([1, 2, 3, 4, 5].map(day => [day, [[open, close]]]));

test('Sydney clock follows daylight saving', () => {
	assert.deepEqual({ ...sydneyClock(new Date('2027-01-03T19:30:00Z')) }, { day: 1, clock: '06:30' }, 'AEDT Monday 6:30am');
	assert.deepEqual({ ...sydneyClock(new Date('2026-07-10T03:59:00Z')) }, { day: 5, clock: '13:59' }, 'AEST Friday 1:59pm');
	assert.deepEqual({ ...sydneyClock(new Date('2026-07-12T14:05:00Z')) }, { day: 1, clock: '00:05' }, 'just after midnight');
});

test('store badge mirrors the PHP open-now calculator', () => {
	const revesby = everyDay('06:30', '14:30');
	const bankstown = weekdays('07:00', '14:00');
	const at = iso => sydneyClock(new Date(iso));
	let clock = at('2027-01-03T19:29:00Z');
	assert.equal(storeBadgeLabel(storeOpenState(revesby, clock), clock), 'Closed now · opens 6:30am');
	clock = at('2027-01-03T19:30:00Z');
	assert.equal(storeBadgeLabel(storeOpenState(revesby, clock), clock), 'Open now · until 2:30pm');
	clock = at('2027-01-04T03:30:00Z');
	assert.equal(storeBadgeLabel(storeOpenState(revesby, clock), clock), 'Closed now · opens Tue 6:30am');
	clock = at('2026-07-10T04:00:00Z');
	assert.equal(storeBadgeLabel(storeOpenState(bankstown, clock), clock), 'Closed now · opens Mon 7am');
	clock = at('2026-07-11T00:00:00Z');
	assert.equal(storeOpenState(bankstown, clock).open, false, 'Bankstown closed Saturday');
	assert.equal(storeBadgeLabel(storeOpenState({}, clock), clock), '', 'no hours, no badge');
	assert.equal(storeBadgeLabel(storeOpenState({ 1: [['18:00', '02:00'], ['bad', '10:00']] }, clock), clock), '', 'invalid and overnight ranges ignored');
});

test('store cards use the shared calculator, tel: and Google Maps actions', () => {
	const template = fs.readFileSync(path.join(root, 'themes', 'doughboss-final', 'template-parts', 'locations.php'), 'utf8');
	assert.match(template, /data-dbf-hours=/);
	assert.match(template, /data-dbf-open-badge/);
	assert.match(template, /'tel:' \. \$dbf_store\['tel'\]/);
	assert.match(template, /dbf-location-action--maps/);
	const functions = fs.readFileSync(path.join(root, 'themes', 'doughboss-final', 'functions.php'), 'utf8');
	assert.match(functions, /DoughBoss_Store_Hours::state\(/);
	assert.match(functions, /'tel'      => '\+61297742286'/);
});

/* ------------------------------------------------------------------ */
/* Catering                                                            */
/* ------------------------------------------------------------------ */

test('catering quotes send only package ids and quantities', async () => {
	const requests = [];
	const state = { lines: [{ id: 539, qty: 2 }, { id: 541, qty: 1 }], selectedId: 0, guests: 30, orderType: 'pickup', quoteGeneration: 0 };
	const refresh = extractFunction(catering, 'refreshQuote', {
		state, root: { querySelector: () => null }, updateQuoteBox() {}, validCateringQuote: () => true,
		get: url => { requests.push(url); return Promise.resolve({}); }
	});
	await refresh();
	assert.equal(requests[0], '/catering/quote?packages=539%3A2%2C541%3A1&guest_count=30&order_type=pickup');
	assert.doesNotMatch(requests[0], /price|total|deposit/);
});

test('catering is pickup from Revesby only, without a three-shop chooser', () => {
	const source = fs.readFileSync(catering, 'utf8');
	assert.match(source, /data-catering-locations/);
	assert.match(source, /DELIVERY_ALLOWED\s*\?/, 'delivery fields only when the server allows them');
	const shortcodes = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-shortcodes.php'), 'utf8');
	assert.match(shortcodes, /data-catering-locations="<\?php echo esc_attr\( \(string\) wp_json_encode\( DoughBoss_Catering::catering_location_ids\(\) \) \); \?>"/);
	assert.doesNotMatch(source, /Bankstown|Roselands/);
});

test('catering location list is limited to catering shops', async () => {
	const requests = [];
	const state = { locations: [], locationId: 0, locationStatus: 'loading', requestedLocationId: null, locationLocked: false, submitting: false, cateringLocationIds: [1] };
	const context = {
		state, cateringLocationId: extractFunction(catering, 'cateringLocationId'), root: { querySelector: () => null }, updateCateringLocation() {},
		window: { localStorage: { getItem: () => '2' } },
		get: url => new Promise(resolve => requests.push({ url, resolve }))
	};
	const load = extractFunction(catering, 'loadCateringLocations', context);
	const apply = extractFunction(catering, 'applyCateringLocation', context);
	const pending = load();
	requests[0].resolve([{ id: 1, name: 'Revesby' }, { id: 2, name: 'Bankstown' }, { id: 3, name: 'Roselands' }]);
	requests[1].resolve({ active: true, location: { id: 2 }, table: { label: 'T1' } });
	await pending;
	assert.equal(state.locationStatus, 'ready');
	assert.deepEqual(state.locations.map(location => location.name), ['Revesby'], 'only the catering shop is offered');
	assert.equal(state.locationId, 1, 'stored or table shop preferences cannot move catering');
	assert.equal(state.locationLocked, false);
	apply(2);
	assert.equal(state.locationId, 1, 'header shop changes do not reroute catering');
});

/* ------------------------------------------------------------------ */
/* B2B form                                                            */
/* ------------------------------------------------------------------ */

test('b2b client checks mirror the server rules', () => {
	const errors = extractFunction(b2b, 'b2bClientErrors', { String });
	const valid = { name: 'Sam Haddad', business: 'Metro Grocer', email: 'sam@example.com.au', phone: '0400 000 000', message: 'We would like to stock your pies.' };
	assert.deepEqual({ ...errors(valid, 'wholesale') }, {});
	assert.deepEqual(Object.keys(errors({ ...valid, business: '' }, 'wholesale')), ['business']);
	assert.deepEqual({ ...errors({ ...valid, business: '' }, 'franchise') }, {});
	assert.deepEqual(Object.keys(errors({ ...valid, email: 'a@b', phone: 'call me', message: 'hi' }, 'wholesale')).sort(), ['email', 'message', 'phone']);
	const source = fs.readFileSync(b2b, 'utf8');
	assert.match(source, /'X-WP-Nonce': nonce/);
	const php = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-rest-controller.php'), 'utf8');
	assert.match(php, /'\/b2b\/enquiry'/);
	assert.match(php, /rate_limited\( 'b2b', 5, HOUR_IN_SECONDS \)/);
	const partner = fs.readFileSync(path.join(root, 'themes', 'doughboss-final', 'template-parts', 'partner-page.php'), 'utf8');
	assert.match(partner, /\[doughboss_b2b_form type="/);
});
