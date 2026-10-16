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
	assert.match(footer, /esc_html_e\( 'Partners'/);
	assert.doesNotMatch(footer, /Franchising & partners|Student vouchers/);
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

test('homepage ribbon clips decoration without capturing descendant view timelines', () => {
	const source = fs.readFileSync(path.join(root, 'scripts', 'hero-commercial', 'homepage-hero-v6-snippet.php'), 'utf8');
	const ribbonRule = source.match(/#db-opening-home-v6 \.db-opening-ribbon\{([^}]+)\}/)?.[1];
	assert.ok(ribbonRule, 'homepage ribbon rule exists');
	assert.match(ribbonRule, /overflow:hidden;overflow:clip;/, 'clip keeps the viewport as the view timeline scrollport; hidden is the older-browser fallback');
});

test('homepage and shared navigation retain readable text floors and opaque review copy', () => {
	const css = fs.readFileSync(path.join(root, 'themes', 'doughboss-final', 'style.css'), 'utf8');
	const hero = fs.readFileSync(path.join(root, 'scripts', 'hero-commercial', 'homepage-hero-v6-snippet.php'), 'utf8');
	assert.match(css, /\.dbf-nav a \{ position:[^}]+font-size: 15px/);
	assert.match(css, /\.dbf-nav a \{ display: block;[^}]+font-size: 28px/, 'mobile drawer keeps large labels');
	assert.match(css, /body\.home \.dbf-eyebrow \{ font-size: 14px/);
	assert.match(css, /body\.home \.dbf-review-panel p:not\(\.dbf-eyebrow\) \{ font-size: 18px; line-height: 1.6/);
	assert.match(css, /\.dbf-review-panel p \{[^}]+color: #fff;/);
	assert.match(css, /\.dbf-footer li \{[^}]+font-size: 16px/);
	assert.match(css, /\.dbf-footer-bottom \{[^}]+font-size: 14px/);
	assert.match(hero, /\.db-mh-kicker\{grid-row:1;font-size:14px/);
	assert.match(hero, /\.db-opening-description\{grid-row:5;font-size:17px/);
	assert.match(hero, /font-size:16px;font-weight:700/);
});

/* Homepage presentation is installed as a reversible, separately scoped snippet. */
function homepageFilmFixture(options = {}) {
	const source = fs.readFileSync(path.join(root, 'scripts', 'hero-commercial', 'homepage-hero-v6-snippet.php'), 'utf8');
	const script = source.match(/<script id="db-opening-home-v6-controller">([\s\S]*?)<\/script>/)[1];
	function target() {
		return { hidden: false, textContent: '', attrs: {}, listeners: {},
			addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); },
			emit(name) { for (const callback of this.listeners[name] || []) callback(); },
			setAttribute(name, value) { this.attrs[name] = String(value); },
			getAttribute(name) { return this.attrs[name] ?? null; } };
	}
	const video = target(), poster = target(), control = target(), status = target(), frame = target(), steamControl = target();
	video.duration = 10; video.readyState = 2;
	frame.getBoundingClientRect = () => ({ height: 600 });
	video.hidden = true; video.currentTime = 0; video.playCount = 0; video.pauseCount = 0;
	video.attrs['data-db-opening-src'] = 'https://doughboss.com.au/wp-content/uploads/doughboss-commercial-v5-hero-720.mp4';
	video.attrs['data-db-opening-desktop-src'] = 'https://doughboss.com.au/wp-content/uploads/doughboss-commercial-v6-desktop-square.mp4';
	video.play = () => { video.playCount++; return options.reject ? Promise.reject(new Error('blocked')) : Promise.resolve(); };
	video.pause = () => { video.pauseCount++; };
	const elements = { '[data-db-opening-video]': video, '[data-db-opening-poster]': poster,
		'[data-db-opening-control]': control, '[data-db-opening-status]': status, '.db-opening-frame': frame, '[data-db-steam-control]': steamControl };
	const classes = new Set();
	const opening = { querySelector: key => elements[key], classList: { toggle(key, on) { if (on) classes.add(key); else classes.delete(key); } } };
	const document = target(); document.hidden = false; document.getElementById = () => opening;
	const motion = target(); motion.matches = !!options.reduced;
	const connection = target(); connection.saveData = !!options.saveData;
	const desktop = target(); desktop.matches = !!options.desktop;
	let intersect;
	const callbacks = new Map(); let sequence = 0;
	const window = { ...target(), scrollY: 0, navigator: { connection }, matchMedia: query => query.includes('min-width') ? desktop : motion,
		requestAnimationFrame(fn) { callbacks.set(++sequence, fn); return sequence; }, cancelAnimationFrame(id) { callbacks.delete(id); },
		IntersectionObserver: function (callback) { intersect = callback; this.observe = element => assert.equal(element, frame); } };
	const context = vm.createContext({ document, window });
	vm.runInContext(script, context);
	return { source, video, poster, control, status, document, motion, connection, desktop, classes, window, steamControl,
		scroll(y) { window.scrollY = y; window.emit('scroll'); },
		flush() { const work = [...callbacks.values()]; callbacks.clear(); work.forEach(fn => fn()); },
		visible(on) { intersect([{ isIntersecting: on, intersectionRatio: on ? .5 : 0 }]); },
		rerun() { vm.runInContext(script, context); } };
}

test('large homepage film autoplays silently in view and respects a persistent manual pause', () => {
	const x = homepageFilmFixture();
	assert.equal(x.video.playCount, 0, 'do not load video before its stage is visible');
	x.visible(true); x.video.emit('playing');
	assert.equal(x.video.playCount, 1); assert.equal(x.video.muted, true); assert.equal(x.video.loop, false);
	assert.equal(x.video.hidden, false); assert.equal(x.poster.hidden, true); assert.equal(x.classes.has('is-playing'), true);
	x.visible(false); assert.equal(x.classes.has('is-playing'), false);
	x.visible(true); assert.equal(x.video.playCount, 2, 'offscreen suspension can resume');
	x.control.emit('click'); x.visible(false); x.visible(true);
	assert.equal(x.video.playCount, 2, 'scrolling must not undo a manual pause');
	x.control.emit('click'); assert.equal(x.video.playCount, 3);
	x.document.hidden = true; x.document.emit('visibilitychange'); assert.equal(x.classes.has('is-playing'), false);
	x.document.hidden = false; x.document.emit('visibilitychange'); assert.equal(x.video.playCount, 4);
	x.rerun(); assert.equal(x.control.listeners.click.length, 1, 'no duplicate controller');
	assert.doesNotMatch(x.source, /<p[^>]*>AI-generated concept|Concept preview &mdash;/);
	assert.match(x.source, /width:min\(100%,520px\)/);
	assert.match(x.source, /aspect-ratio:1\/1/);
	assert.match(x.source, /prefers-reduced-motion:reduce/);
	assert.match(x.source, /is_admin\(\) \|\| ! is_front_page\(\)/);
});

test('homepage uses the food-focused desktop cut and changes source without undoing a manual pause', () => {
	const x = homepageFilmFixture({ desktop: true }); x.visible(true); x.video.emit('playing');
	assert.match(x.video.getAttribute('src'), /v6-desktop-square\.mp4$/);
	x.desktop.matches = false; x.desktop.emit('change');
	assert.match(x.video.getAttribute('src'), /v5-hero-720\.mp4$/);
	x.control.emit('click'); const plays = x.video.playCount;
	x.desktop.matches = true; x.desktop.emit('change');
	assert.equal(x.video.playCount, plays, 'breakpoint changes do not resume manually paused video');
});

test('homepage reduced-motion and Save-Data visitors get a poster and explicit play only', () => {
	for (const preference of ['reduced', 'saveData']) {
		const x = homepageFilmFixture({ [preference]: true });
		x.visible(true); assert.equal(x.video.playCount, 0); assert.equal(x.video.getAttribute('src'), null);
		assert.equal(x.poster.hidden, false);
		x.control.emit('click'); assert.equal(x.video.playCount, 1); assert.equal(x.video.loop, false);
		x.video.emit('playing'); x.video.emit('ended'); x.visible(true);
		assert.equal(x.video.playCount, 1, 'explicit play does not grant continuous autoplay');
	}
	const x = homepageFilmFixture(); x.visible(true); x.video.emit('playing');
	x.motion.matches = true; x.motion.emit('change');
	assert.equal(x.video.hidden, true); assert.equal(x.poster.hidden, false); assert.equal(x.video.loop, false);
});

test('homepage rejected autoplay and failed media keep an accessible image fallback', async () => {
	const x = homepageFilmFixture({ reject: true }); x.visible(true);
	await Promise.resolve(); await Promise.resolve();
	assert.equal(x.poster.hidden, false); assert.equal(x.control.textContent, 'Play film');
	assert.equal(x.status.textContent, 'Select Play film to watch the film.');
	x.visible(false); x.visible(true); assert.equal(x.video.playCount, 1, 'do not repeatedly attempt blocked autoplay');
	x.video.emit('error'); assert.equal(x.control.hidden, true); assert.equal(x.poster.hidden, false);
	assert.match(x.status.textContent, /image is shown/);
});

test('paused film shows fallback poster with independently pausable steam; active film plays once', () => {
	const x = homepageFilmFixture(); x.visible(true); x.video.emit('playing');
	assert.equal(x.video.loop, false); assert.equal(x.classes.has('has-steam'), false);
	x.control.emit('click');
	assert.equal(x.video.hidden, true); assert.equal(x.poster.hidden, false);
	assert.equal(x.classes.has('has-steam'), true); assert.equal(x.steamControl.hidden, false);
	x.steamControl.emit('click'); assert.equal(x.classes.has('has-steam'), false); assert.equal(x.steamControl.textContent, 'Play steam');
	x.visible(false); x.visible(true); assert.equal(x.classes.has('has-steam'), false, 'remember explicit steam pause');
	x.steamControl.emit('click'); assert.equal(x.classes.has('has-steam'), true);
	x.visible(false); assert.equal(x.classes.has('has-steam'), false);
	x.visible(true); x.document.hidden = true; x.document.emit('visibilitychange'); assert.equal(x.classes.has('has-steam'), false);
	x.document.hidden = false; x.document.emit('visibilitychange'); assert.equal(x.classes.has('has-steam'), true);
	x.control.emit('click'); x.video.emit('playing'); assert.equal(x.video.loop, false); assert.equal(x.classes.has('has-steam'), false);
});

test('completed homepage film holds the final frame and steam until explicit replay or scroll scrubbing', () => {
	const x = homepageFilmFixture(); x.visible(true); x.video.emit('playing');
	x.video.currentTime = x.video.duration; x.video.ended = true; x.video.emit('ended');
	assert.equal(x.video.loop, false); assert.equal(x.video.currentTime, 10);
	assert.equal(x.video.hidden, false); assert.equal(x.poster.hidden, true);
	assert.equal(x.control.textContent, 'Replay film'); assert.equal(x.classes.has('has-steam'), true);
	x.visible(false); assert.equal(x.classes.has('has-steam'), false);
	x.visible(true); assert.equal(x.classes.has('has-steam'), true);
	x.document.hidden = true; x.document.emit('visibilitychange'); assert.equal(x.classes.has('has-steam'), false);
	x.document.hidden = false; x.document.emit('visibilitychange'); assert.equal(x.classes.has('has-steam'), true);
	x.desktop.matches = true; x.desktop.emit('change');
	assert.equal(x.video.playCount, 1, 'completion survives viewport, visibility and breakpoint changes');
	assert.equal(x.video.hidden, false); assert.equal(x.poster.hidden, true);
	assert.match(x.video.getAttribute('src'), /v5-hero-720\.mp4$/, 'hold the completed source until explicit replay');
	x.steamControl.emit('click'); x.visible(false); x.visible(true);
	assert.equal(x.classes.has('has-steam'), false, 'completion preserves an explicit steam pause');
	x.steamControl.emit('click'); assert.equal(x.classes.has('has-steam'), true);
	x.scroll(-60); x.flush();
	assert.equal(x.video.currentTime, 8.96, 'reverse scrolling takes over from the completed final frame');
	assert.equal(x.video.hidden, false); assert.equal(x.poster.hidden, true);
	x.scroll(-30); x.flush(); assert.equal(x.video.currentTime, 9.46, 'scrolling down still advances');
	assert.equal(x.video.playCount, 1, 'scroll scrubbing never restarts time-based playback');
	x.control.emit('click'); assert.equal(x.control.textContent, 'Replay film');
	x.control.emit('click'); assert.equal(x.video.playCount, 2); assert.equal(x.video.currentTime, 0);
	x.video.emit('playing'); assert.equal(x.classes.has('has-steam'), false);
	x.video.emit('ended'); x.visible(false); x.visible(true);
	assert.equal(x.video.playCount, 2, 'an explicit replay also finishes without repeating');
});

test('steam is restricted to the box scene or poster and respects reduced motion and data saving', () => {
	const x = homepageFilmFixture(); x.visible(true); x.video.emit('playing'); x.video.currentTime = 3;
	x.scroll(60); x.flush(); assert.equal(x.classes.has('has-steam'), false, 'no steam floating off the bake board');
	x.scroll(180); x.flush(); assert.equal(x.classes.has('has-steam'), true, 'box reveal may breathe while stationary');
	x.motion.matches = true; x.motion.emit('change'); assert.equal(x.classes.has('has-steam'), false); assert.equal(x.steamControl.hidden, true);
	const saved = homepageFilmFixture({ saveData: true }); saved.visible(true); assert.equal(saved.classes.has('has-steam'), false);
	assert.match(x.source, /id="db-opening-steam" aria-hidden="true"/);
	assert.match(x.source, /\.has-steam \.db-opening-steam span\{animation-play-state:running\}/);
});

test('hero scroll takes over at the current frame, advances down and reverses up', async () => {
	const x = homepageFilmFixture(); x.visible(true); x.video.emit('playing'); x.video.currentTime = 3;
	x.scroll(60); x.flush(); assert.equal(x.video.currentTime, 4);
	assert.equal(x.video.loop, false); assert.equal(x.control.textContent, 'Pause film');
	x.scroll(30); x.flush(); assert.equal(x.video.currentTime, 3.5);
	await Promise.resolve(); assert.ok(x.video.pauseCount > 0, 'pending autoplay cannot restart scrubbed film');
	x.scroll(10000); x.flush(); assert.equal(x.video.currentTime, 9.96);
	x.scroll(0); x.flush(); assert.equal(x.video.currentTime, 0);
});

test('hero pause and motion preferences cancel queued scroll seeks and never resume on scroll', () => {
	for (const preference of ['pause', 'motion', 'connection', 'hidden']) {
		const x = homepageFilmFixture(); x.visible(true); x.video.emit('playing'); x.video.currentTime = 3;
		x.scroll(60);
		if (preference === 'pause') x.control.emit('click');
		if (preference === 'motion') { x.motion.matches = true; x.motion.emit('change'); }
		if (preference === 'connection') { x.connection.saveData = true; x.connection.emit('change'); }
		if (preference === 'hidden') { x.document.hidden = true; x.document.emit('visibilitychange'); }
		x.flush(); x.scroll(120); x.flush(); assert.equal(x.video.currentTime, 3, preference);
	}
});

test('hero ignores scroll until media is ready and avoids offscreen scroll jumps on reentry', () => {
	const x = homepageFilmFixture(); x.visible(true); x.video.emit('playing'); x.video.currentTime = 3;
	x.video.duration = NaN; x.scroll(50); x.flush(); assert.equal(x.video.currentTime, 3);
	x.video.duration = 10; x.video.readyState = 1; x.scroll(100); x.flush(); assert.equal(x.video.currentTime, 3);
	x.video.readyState = 2; x.scroll(160); x.flush(); assert.equal(x.video.currentTime, 4);
	x.visible(false); x.scroll(3000); x.flush(); assert.equal(x.video.currentTime, 4);
	x.visible(true); x.scroll(2940); x.flush(); assert.equal(x.video.currentTime, 3);
	x.control.emit('click'); x.scroll(2800); x.flush(); assert.equal(x.video.currentTime, 3);
	x.control.emit('click'); assert.equal(x.video.playCount, 2, 'explicit play resumes time-based playback');
});

function homepageTiltFixture(options = {}) {
	const source = fs.readFileSync(path.join(root, 'scripts/hero-commercial/homepage-hero-v6-snippet.php'), 'utf8');
	const script = source.match(/<script id="db-opening-home-v6-tilt">([\s\S]*?)<\/script>/)[1];
	function target() { return { listeners: {}, addEventListener(name, fn) { (this.listeners[name] ||= []).push(fn); }, emit(name, event = {}) { for (const fn of this.listeners[name] || []) fn(event); } }; }
	const properties = { transform: 'translateY(36px)' }, panel = target(), opening = {};
	panel.setAttribute = () => {};
	panel.style = { setProperty(k, v) { properties[k] = v; }, removeProperty(k) { delete properties[k]; } };
	panel.getBoundingClientRect = () => ({ left: 100, top: 100, width: 400, height: 200 });
	const document = { ...target(), hidden: false, readyState: options.loading ? 'loading' : 'complete', getElementById: () => opening,
		querySelectorAll(selector) { assert.equal(selector, 'body.home .db-opening-frame,body.home .dbf-story-media,body.home .dbf-food-card,body.home .dbf-card'); return [panel]; } };
	const allowed = { ...target(), matches: options.allowed !== false };
	const queue = new Map(); let id = 0;
	const window = { ...target(), matchMedia(query) { assert.match(query, /no-preference.*hover: hover.*pointer: fine/); return allowed; },
		requestAnimationFrame(fn) { queue.set(++id, fn); return id; }, cancelAnimationFrame(key) { queue.delete(key); } };
	const context = vm.createContext({ document, window }); vm.runInContext(script, context);
	return { source, panel, properties, window, document, allowed, queue,
		move(x, y, type = 'mouse') { panel.emit('pointermove', { clientX: x, clientY: y, pointerType: type, buttons: 0 }); },
		flush() { const jobs = [...queue.values()]; queue.clear(); jobs.forEach(fn => fn()); }, rerun() { vm.runInContext(script, context); } };
}

test('panel tilt is bounded, frame-coalesced and composes with existing reveal transforms', () => {
	const x = homepageTiltFixture(); x.move(500, 100); x.move(900, -500);
	assert.equal(x.queue.size, 1); x.flush(); assert.equal(x.properties['--db-tilt-angle'], '1.600deg');
	assert.equal(x.properties.transform, 'translateY(36px)');
	x.move(300, 200); x.flush(); assert.equal(x.properties['--db-tilt-angle'], '0.000deg');
	x.rerun(); assert.equal(x.panel.listeners.pointermove.length, 1);
});

test('panel tilt resets on leave, cancellation, focus, scroll, blur and preference change', () => {
	for (const event of ['pointerleave', 'pointercancel', 'pointerdown', 'focusin', 'scroll', 'resize', 'blur', 'visibilitychange', 'change']) {
		const x = homepageTiltFixture(); x.move(500, 100); x.flush(); x.move(100, 300);
		const owner = ['scroll', 'resize', 'blur'].includes(event) ? x.window : event === 'visibilitychange' ? x.document : event === 'change' ? x.allowed : x.panel;
		owner.emit(event); x.flush(); assert.equal(x.properties['--db-tilt-angle'], undefined, event);
	}
});

test('panel tilt ignores touch, pen, hidden pages and reduced/coarse input; deferred DOM wires once', () => {
	const x = homepageTiltFixture({ loading: true }); x.rerun();
	assert.equal(x.document.listeners.DOMContentLoaded.length, 1); x.document.emit('DOMContentLoaded');
	for (const type of ['touch', 'pen']) { x.move(500, 100, type); x.flush(); assert.equal(x.properties['--db-tilt-angle'], undefined); }
	x.allowed.matches = false; x.move(500, 100); x.flush(); assert.equal(x.properties['--db-tilt-angle'], undefined);
	x.allowed.matches = true; x.document.hidden = true; x.move(500, 100); x.flush(); assert.equal(x.properties['--db-tilt-angle'], undefined);
});
