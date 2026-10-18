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

test('off-screen menu grids defer rendering without hiding their content', () => {
	const css = fs.readFileSync(path.join(root, 'public', 'css', 'doughboss.css'), 'utf8');
	const gridRule = css.match(/\.db-grid\s*\{[\s\S]*?\}/);
	assert.ok(gridRule, 'the menu grid rule must exist');
	assert.match(gridRule[0], /content-visibility:\s*auto/);
	assert.match(gridRule[0], /contain-intrinsic-size:\s*auto\s+720px/);
	assert.doesNotMatch(gridRule[0], /display:\s*none|visibility:\s*hidden/);
});

test('homepage keeps its hero and status assets without the full storefront bundle', () => {
	const source = fs.readFileSync(path.join(root, 'themes', 'doughboss-final', 'functions.php'), 'utf8');
	const storefront = source.match(/function doughboss_final_load_storefront_assets\([^)]*\)\s*\{[\s\S]*?\n\}/);
	assert.ok(storefront, 'the storefront asset filter must exist');
	assert.doesNotMatch(storefront[0], /is_front_page\s*\(/, 'the presentation-only homepage must not force the ordering app');
	assert.match(storefront[0], /is_page\(\s*array\(\s*'menu',\s*'order',\s*'track-order',\s*'catering'\s*\)\s*\)/);
	assert.match(source, /doughboss_load_shop_status_assets',\s*'__return_true'/, 'the lightweight sitewide shop status stays active');
	assert.match(source, /function doughboss_final_load_hero_assets\([^)]*\)\s*\{[\s\S]*?is_front_page\s*\(/,
		'the independently loaded homepage hero stays active');
});

function installFixture(options = {}) {
  const source = fs.readFileSync(path.join(root, 'themes', 'doughboss-final', 'assets', 'install.js'), 'utf8');
  function target() {
    return { listeners: {}, addEventListener(name, callback) { this.listeners[name] = callback; },
      emit(name, event) { return this.listeners[name]?.(event); } };
  }
  const button = { ...target(), hidden: true, disabled: false };
  const status = { textContent: 'Use your browser menu to add Dough Boss.' };
  const mode = { ...target(), matches: !!options.installed };
  const window = { ...target(), navigator: { standalone: !!options.iosInstalled }, matchMedia(query) {
    assert.equal(query, '(display-mode: standalone)'); return mode;
  } };
  for (const key of ['localStorage', 'sessionStorage', 'location', 'caches']) {
    Object.defineProperty(window, key, { get() { throw new Error('install must not use ' + key); } });
  }
  Object.defineProperty(window.navigator, 'serviceWorker', { get() { throw new Error('no service worker'); } });
  const document = { querySelector(selector) {
    return options.noControls ? null : selector === '[data-db-install]' ? button : status;
  } };
  vm.runInNewContext(source, { window, document });
  function offer(outcome = 'accepted', promptFailure = false) {
    const event = { isTrusted: true, prevented: false, prompts: 0,
      preventDefault() { this.prevented = true; },
      prompt() { this.prompts += 1; return promptFailure ? Promise.reject(new Error('unavailable')) : Promise.resolve(); },
      userChoice: Promise.resolve({ outcome }) };
    window.emit('beforeinstallprompt', event);
    return event;
  }
  return { source, window, button, status, mode, offer };
}

test('install button stays natively hidden without a genuine browser offer', () => {
  const x = installFixture();
  assert.equal(x.button.hidden, true);
  x.window.emit('beforeinstallprompt', { isTrusted: false, prompt() {}, userChoice: Promise.resolve({}) });
  assert.equal(x.button.hidden, true, 'script-dispatched event cannot expose install action');
  x.window.emit('beforeinstallprompt', { isTrusted: true });
  assert.equal(x.button.hidden, true, 'malformed offer ignored');
  assert.match(x.status.textContent, /browser menu/);
  assert.doesNotThrow(() => installFixture({ noControls: true }));
  assert.doesNotMatch(x.source, /serviceWorker|localStorage|sessionStorage|fetch\(|location\.|caches\.|Notification/);
});

test('native offer prompts only from the button and is consumed once', async () => {
  const x = installFixture();
  const event = x.offer();
  assert.equal(event.prevented, true);
  assert.equal(event.prompts, 0, 'no automatic browser prompt');
  assert.equal(x.button.hidden, false);
  const completion = x.button.emit('click');
  assert.equal(x.button.hidden, true);
  assert.equal(x.button.disabled, true);
  await x.button.emit('click');
  await completion;
  assert.equal(event.prompts, 1);
  assert.match(x.status.textContent, /Installation requested/);
  assert.doesNotMatch(x.status.textContent, /was installed/);
});

test('install dismissal and prompt failure retain manual fallback without reusing event', async () => {
  for (const failure of [false, true]) {
    const x = installFixture();
    const event = x.offer('dismissed', failure);
    await x.button.emit('click');
    assert.match(x.status.textContent, failure ? /not available right now/ : /dismissed/);
    assert.match(x.status.textContent, /browser instructions below/);
    assert.equal(x.button.hidden, true);
    await x.button.emit('click');
    assert.equal(event.prompts, 1);
    x.offer();
    assert.equal(x.button.hidden, false, 'only a fresh native offer restores button');
  }
});

test('already installed and appinstalled states never navigate or restore stale offer', async () => {
  for (const options of [{ installed: true }, { iosInstalled: true }]) {
    const x = installFixture(options);
    x.offer();
    assert.equal(x.button.hidden, true);
    assert.match(x.status.textContent, /already open as an installed app/);
  }
  const x = installFixture();
  x.offer();
  const completion = x.button.emit('click');
  x.window.emit('appinstalled', { isTrusted: true });
  await completion;
  assert.match(x.status.textContent, /was installed/);
  x.offer();
  assert.equal(x.button.hidden, true);
  assert.equal(x.button.disabled, true);
  const y = installFixture();
  y.window.emit('appinstalled', { isTrusted: false });
  assert.match(y.status.textContent, /browser menu/);
  y.mode.matches = true;
  y.mode.emit('change');
  assert.match(y.status.textContent, /already open/);
});

test('photo-led menu keeps capped desktop density, two phone columns and compact rows below 360px', () => {
	const css = fs.readFileSync(path.join(root, 'public', 'css', 'doughboss.css'), 'utf8');
	const orderCss = fs.readFileSync(path.join(root, 'public', 'css', 'doughboss-order-page.css'), 'utf8');
	assert.match(css, /\.db-grid \{[\s\S]*?grid-template-columns: repeat\(3, minmax\(0, 1fr\)\)/);
	assert.match(css, /@media \(max-width: 1099px\)[\s\S]*?\.db-app \.db-grid \{[\s\S]*?repeat\(2, minmax\(0, 1fr\)\)/);
	assert.match(css, /@media \(max-width: 640px\)\s*\{\s*\.db-app \.db-grid \{ grid-template-columns: repeat\(2, minmax\(0, 1fr\)\)/);
	assert.match(css, /\.db-grid:not\(\.db-grid--compact\) \.db-card-img \{ min-height: 0; aspect-ratio: 4 \/ 3;/);
	assert.match(css, /\.db-card-desc \{ font-size: 1rem; line-height: 1\.45;/);
	assert.match(css, /\.db-menu-customize > summary \{ min-height: 44px;/);
	assert.match(css, /@media \(max-width: 359\.98px\)[\s\S]*?\.db-card \{ display: grid; grid-template-columns: 96px minmax\(0, 1fr\); align-items: start; \}/);
	assert.match(css, /@media \(max-width: 359\.98px\)[\s\S]*?\.db-card-img \{ width: 84px; height: 84px; aspect-ratio: 1; margin: 12px 0 12px 12px; border-radius: 12px; overflow: hidden; \}/);
	assert.match(orderCss, /@media \(max-width: 359\.98px\)/, 'the order wrapper shares the fractional-safe narrow-phone cutoff');
	assert.match(orderCss, /body\.doughboss-order-page \.db-grid \{\s*grid-template-columns: repeat\(3, minmax\(0, 1fr\)\)/);
	assert.match(orderCss, /@media \(max-width: 1099px\)[\s\S]*?repeat\(2, minmax\(0, 1fr\)\)/);
	assert.doesNotMatch(orderCss, /@media \(max-width: 420px\)/, 'legacy order wrapper must not undo the two-column phone layout');
	assert.doesNotMatch(orderCss, /\.db-card-desc\s*\{\s*display:\s*none/, 'ingredients must remain visible on phones');
  assert.match(css, /\.db-menu-search-status \{ grid-column: 2; grid-row: 1;/, 'phone result count shares the search row');
  const themeStyle = fs.readFileSync(path.join(root, 'themes', 'doughboss-final', 'style.css'), 'utf8');
  assert.match(themeStyle, /body\.doughboss-order-page \.dbf-header-shop \.db-pickup-status \{ display: none;/, 'only duplicated ordering-header status is hidden on phones');
  assert.match(css, /grid-template-columns: 44px 46px 44px;/, 'cart stepper retains 44px touch controls');
  assert.match(css, /\.db-app\.db-cart \{\s*scroll-margin-top: calc\(var\(--db-sticky-top, 0px\) \+ 1rem\);/, 'cart anchor clears sticky navigation');
  assert.match(css, /\.db-checkout-summary-label \{\s*color: #bbb6ae;\s*font-size: \.875rem;/, 'checkout summary labels are readable');
  assert.match(css, /\.db-checkout-safety summary \{[^}]*min-height: 44px;[^}]*padding-block: \.75rem;/, 'the native checkout disclosure retains a comfortable touch target without a fixed height');
});

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

const menuPhoto = extractFunction(storefront, 'menuPhoto', { el: node });

test('menu photos without responsive metadata use native lazy images, never eager backgrounds', () => {
	for (const item of [
		{ name: 'Synthetic uploaded photo', image: 'https://example.invalid/uploads/menu-photo.jpg' },
		{ name: 'Synthetic packaged photo', image: 'https://example.invalid/plugin/public/images/menu/photo.webp' }
	]) {
		const media = menuPhoto(item);
		assert.equal(media.className, 'db-card-img');
		assert.equal(media.getAttribute('style'), null);
		const picture = media.querySelector('picture');
		assert.equal(picture.className, 'db-menu-picture', 'existing sizing and object-fit rules still apply');
		assert.equal(picture.children.length, 1, 'one photo element, no duplicated fallback');
		const image = picture.querySelector('img');
		assert.equal(image.getAttribute('src'), item.image, 'retain the exact selected product photo');
		assert.equal(image.getAttribute('alt'), item.name);
		assert.equal(image.getAttribute('loading'), 'lazy');
		assert.equal(image.getAttribute('decoding'), 'async');
		assert.deepEqual(Object.keys(image.attributes).slice(0, 3), ['loading', 'decoding', 'src'], 'loading policy is set before the URL');
	}
});

test('valid packaged menu photos retain responsive sources, dimensions and native lazy loading', () => {
	const image = 'https://example.invalid/plugin/public/images/menu/real-v1/synthetic.jpg';
	const sources = [
		{ type: 'image/avif', srcset: 'https://example.invalid/plugin/public/images/responsive/synthetic-480.avif 480w, https://example.invalid/plugin/public/images/responsive/synthetic-960.avif 960w' },
		{ type: 'image/webp', srcset: 'https://example.invalid/plugin/public/images/responsive/synthetic-480.webp 480w' }
	];
	const media = menuPhoto({ name: 'Synthetic photo', image, image_responsive: { original: image, width: 960, height: 720, sources } });
	const picture = media.querySelector('picture');
	assert.equal(picture.children.length, 3);
	for (let index = 0; index < sources.length; index += 1) {
		assert.equal(picture.children[index].tag, 'source');
		assert.equal(picture.children[index].getAttribute('srcset'), sources[index].srcset);
		assert.equal(picture.children[index].type, sources[index].type);
		assert.equal(picture.children[index].getAttribute('sizes'), 'auto, (max-width: 359.98px) 84px, (max-width: 1099px) 50vw, 440px');
	}
	const photo = picture.querySelector('img');
	assert.equal(photo.getAttribute('width'), '960');
	assert.equal(photo.getAttribute('height'), '720');
	assert.equal(photo.getAttribute('loading'), 'lazy');
	assert.equal(photo.getAttribute('src'), image);
	assert.equal(media.getAttribute('style'), null);
});

test('invalid responsive metadata falls back to one lazy original without partial sources', () => {
	const image = 'https://example.invalid/plugin/public/images/menu/real-v1/synthetic.jpg';
	const valid = { type: 'image/avif', srcset: 'https://example.invalid/plugin/public/images/responsive/synthetic-480.avif 480w' };
	const invalidSources = [
		[{ type: 'image/avif', srcset: 'https://foreign.invalid/responsive/synthetic-480.avif 480w' }],
		[valid, valid],
		[valid, { type: 'image/webp', srcset: 'https://example.invalid/plugin/public/images/responsive/synthetic-480.avif 480w' }],
		[{ type: 'image/webp', srcset: 'https://example.invalid/plugin/public/images/responsive/synthetic-960.webp 480w' }],
		[]
	];
	for (const sources of invalidSources) {
		const media = menuPhoto({ name: 'Synthetic photo', image, image_responsive: { original: image, width: 960, height: 720, sources } });
		const picture = media.querySelector('picture');
		assert.equal(picture.children.length, 1, 'discard every partial responsive candidate');
		assert.equal(picture.querySelector('source'), null);
		assert.equal(picture.querySelector('img').getAttribute('src'), image);
		assert.equal(picture.querySelector('img').getAttribute('loading'), 'lazy');
		assert.equal(media.getAttribute('style'), null);
	}
});

function menuCardFactory(overrides) {
	const context = {
		I18N: {}, el: node, money: value => '$' + Number(value).toFixed(2), dietaryBadges: () => [],
		compactMenuCategory, menuPhoto: () => node('div', { class: 'db-card-img' }),
		menuLocationId: () => 1, dbToast() {}, request() { return Promise.resolve(); }
	};
	return extractFunction(storefront, 'menuCard', Object.assign(context, overrides || {}));
}

test('radio customisation shows and submits the same default and includes its price', () => {
	for (const explicitDefault of [false, true]) {
		const sends = [];
		const card = menuCardFactory({ request(route, options) { sends.push({ route, options }); return new Promise(() => {}); } })({
			id: 21, name: 'Synthetic pizza', price: 10, category: 'Pizza', image: 'x.jpg',
			options: [{ id: 'crust', type: 'radio', label: 'Crust', choices: [
				{ slug: 'first', label: 'First option', price: 2 },
				{ slug: 'second', label: 'Second option', price: 3, default: explicitDefault }
			] }]
		}, true, 1, false);
		const checked = find(card, n => n.tag === 'input' && n.checked);
		assert.equal(checked.getAttribute('value'), explicitDefault ? 'second' : 'first');
		assert.equal(find(card, n => n.className === 'db-price').textContent, explicitDefault ? '$13.00' : '$12.00');
		assert.equal(find(card, n => n.className === 'db-customizer-price').textContent, explicitDefault ? '$13.00' : '$12.00');
		find(card, n => n.className === 'db-btn db-customizer-add').listeners.click();
		assert.equal(sends.length, 1);
		assert.equal(sends[0].route, '/cart/add');
		assert.equal(sends[0].options.body.options.crust, explicitDefault ? 'second' : 'first');
		assert.equal(sends[0].options.body.unit_price, undefined, 'server retains price authority');
	}
});

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

test('drinks use the same truthful photo-led card path as food', () => {
	assert.equal(compactMenuCategory('Drinks'), false);
	assert.equal(compactMenuCategory(' drink '), false);
	assert.equal(compactMenuCategory('Pizza'), false);
	const menuCard = menuCardFactory();
	const card = menuCard({ id: 9, name: 'Coke 600ml', price: 4.5, category: 'Drinks', image: 'juice.webp', options: [] }, false, 0, true);
	assert.doesNotMatch(card.className, /db-card--compact/);
	assert.ok(find(card, n => /db-card-img/.test(n.className || '')), 'drinks retain the selected image frame');
	const soldOut = menuCard({ id: 10, name: 'Sprite 600ml', price: 4.5, category: 'Drinks', image: '', available: false, options: [] }, false, 0, true);
	assert.ok(find(soldOut, n => /db-card-img--placeholder/.test(n.className || '')), 'missing drink photo uses branded placeholder');
	assert.equal(textOf(find(soldOut, n => n.className === 'db-card-placeholder-kicker')), 'Dough Boss', 'drink fallback makes no food-preparation or invented founding-year claim');
	assert.match(textOf(soldOut), /Sold out/, 'sold-out state remains announced');
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

function cateringRenderFixture(packagesFail) {
	const source = fs.readFileSync(catering, 'utf8');
	function container(tag, attrs, children) {
		const element = node(tag, attrs, children);
		Object.defineProperty(element, 'innerHTML', { get() { return this.html || ''; }, set(value) {
			this.html = String(value);
			for (const child of this.children) child.parentNode = null;
			this.children = [];
		} });
		element.insertBefore = function (child, before) {
			child.parentNode = this;
			const index = before ? this.children.indexOf(before) : -1;
			if (index === -1) this.children.push(child); else this.children.splice(index, 0, child);
			return child;
		};
		return element;
	}
	const email = node('a', { href: 'mailto:events@example.invalid?subject=Catering', text: 'Email catering' });
	const phone = node('a', { href: 'tel:+61400000000', text: 'Call catering' });
	const contactTitle = node('h2', { id: 'dbc-contact-title', text: 'Tell us what you need' });
	const loadingNote = node('p', { class: 'dbc-coming-soon', text: 'The online enquiry form will load here.' });
	const contact = node('section', { class: 'dbc-contact', 'aria-labelledby': 'dbc-contact-title' }, [contactTitle, email, phone, loadingNote]);
	const contactQuery = contact.querySelector.bind(contact);
	contact.querySelector = selector => selector === 'a[href]' ? email : contactQuery(selector);
	contact.scrollIntoView = () => { contact.revealed = true; };
	email.focus = () => { email.focused = true; };
	const how = node('section', { class: 'dbc-how', 'aria-labelledby': 'dbc-how-title' }, [node('h2', { id: 'dbc-how-title', text: 'How it works' })]);
	const question = node('summary', { text: 'How do catering payments work?' });
	const details = node('details', {}, [question, node('p', { text: 'The team confirms the price and payment arrangement.' })]);
	const faqTitle = node('h2', { id: 'dbc-faq-title', text: 'A few useful answers' });
	const faq = node('section', { class: 'dbc-faq', 'aria-labelledby': 'dbc-faq-title' }, [faqTitle, details]);
	const root = container('div', { 'data-doughboss-catering': '' }, [contact, how, faq]);
	const state = { packages: [], packagesUnavailable: false };
	const calls = [];
	const context = vm.createContext({ root, state,
		document: { createElement: tag => container(tag) },
		window: { DoughBossData: { payments: { enabled: false }, googleReviewUrl: 'https://reviews.example.invalid' } },
		el: html => { const element = container('div'); element.innerHTML = html; return element; }, money: value => '$' + Number(value).toFixed(2),
		clearRememberedStripePayment() {},
		renderPackages: () => node('div', { class: 'dbc-packages', text: state.packagesUnavailable ? 'Packages unavailable; custom enquiry remains available.' : 'Sample packages' }),
		renderBuilder: () => node('form', { class: 'dbc-form' }),
		loadCateringLocations() {},
		cateringRequestUrl: route => 'https://preview.invalid/doughboss/v1' + route,
		setTimeout, clearTimeout,
		fetch(url, options) {
			calls.push({ url, options });
			return Promise.resolve({ ok: !packagesFail, json: () => Promise.resolve([{ id: 1, name: 'Sample package' }]) });
		}
	});
	context.esc = extractFunction(catering, 'esc');
	context.get = extractFunction(catering, 'get', context);
	const rendering = source.slice(source.indexOf('/* ---------- rendering ---------- */'), source.indexOf('// "Office Platter'));
	vm.runInContext(rendering, context);
	const boot = source.slice(source.lastIndexOf('\n\tloadCateringLocations();'), source.lastIndexOf('}());'));
	assert.match(boot, /get\('\/catering\/packages'\)/, 'run the actual package-load success/error callbacks');
	return { root, contact, contactTitle, email, phone, how, faq, faqTitle, details, question, state, calls, context,
		async boot() { await vm.runInContext(boot, context); }, render() { context.render(); },
		showSuccess: extractFunction(catering, 'showSuccess', context), showPaid: extractFunction(catering, 'showPaid', context) };
}

for (const failed of [false, true]) {
	test('catering ' + (failed ? 'failed' : 'successful') + ' package GET preserves original contact and accessible FAQ nodes', async () => {
		const shortcodes = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-shortcodes.php'), 'utf8');
		assert.match(shortcodes, /class="dbc-contact" aria-labelledby="dbc-contact-title"/);
		assert.match(shortcodes, /class="dbc-faq" aria-labelledby="dbc-faq-title"/);
		const x = cateringRenderFixture(failed);
		await x.boot();
		assert.equal(x.calls.length, 1);
		assert.match(x.calls[0].url, /\/catering\/packages$/);
		assert.equal(x.calls[0].options.method, undefined, 'package hydration is GET only');
		assert.equal(x.state.packagesUnavailable, failed);
		assert.equal(x.root.querySelector('.dbc-contact'), x.contact, 'configured contact section is not recreated or deleted');
		assert.equal(x.root.querySelector('.dbc-coming-soon').hidden, true, 'loading note is hidden only after the enquiry UI renders');
		assert.equal(x.root.querySelector('.dbc-how'), x.how);
		assert.equal(x.root.querySelector('.dbc-faq'), x.faq, 'FAQ identity survives hydration');
		assert.equal(x.contact.parentNode, x.root);
		assert.equal(x.email.parentNode, x.contact);
		assert.equal(x.phone.parentNode, x.contact);
		assert.equal(x.email.href, 'mailto:events@example.invalid?subject=Catering');
		assert.equal(x.phone.href, 'tel:+61400000000');
		assert.equal(x.contact.getAttribute('aria-labelledby'), x.contactTitle.getAttribute('id'));
		assert.equal(x.faq.getAttribute('aria-labelledby'), x.faqTitle.getAttribute('id'));
		assert.equal(x.faq.querySelector('details'), x.details);
		assert.equal(x.details.querySelector('summary'), x.question);
		x.details.open = true;
		const flow = x.root.querySelector('.dbc-app-flow');
		assert.ok(flow, 'only the changing app content has a dedicated host');
		assert.deepEqual(x.root.children, [x.contact, flow, x.how, x.faq], 'contact actions remain before the app and help remains after it');
		const firstPackages = flow.querySelector('.dbc-packages');
		x.render();
		assert.equal(x.root.querySelector('.dbc-app-flow'), flow, 'repeated render reuses one host');
		assert.equal(x.root.children.length, 4, 'no duplicate contact, FAQ or flow');
		assert.equal(flow.children.length, 2, 'no duplicate packages or forms');
		assert.notEqual(flow.querySelector('.dbc-packages'), firstPackages, 'only flow children are replaced');
		assert.equal(x.details.open, true, 'expanded FAQ state is untouched');
	});
}

test('catering confirmation presentation replaces only its flow and retains reachable help', async () => {
	const x = cateringRenderFixture(false);
	await x.boot();
	const flow = x.root.querySelector('.dbc-app-flow');
	x.showSuccess({ enquiry_number: 'CAT-SYNTHETIC', deposit: 0 });
	assert.equal(flow.children.length, 1, 'enquiry confirmation replaces the previous form');
	assert.equal(x.root.querySelector('.dbc-contact'), x.contact);
	assert.equal(x.root.querySelector('.dbc-faq'), x.faq);
	assert.equal(x.email.href, 'mailto:events@example.invalid?subject=Catering');
	x.showPaid({ message: 'Synthetic receipt presentation only' }, { enquiry_number: 'CAT-SYNTHETIC' });
	assert.equal(flow.children.length, 1, 'receipt presentation also reuses the same flow');
	assert.deepEqual(x.root.children, [x.contact, flow, x.how, x.faq]);
	assert.equal(x.calls.length, 1, 'presentation does not start another request');
	assert.doesNotMatch(fs.readFileSync(catering, 'utf8'), /root\.innerHTML\s*=/, 'loading/error/confirmation paths cannot clear shortcode help');
});

test('catering enquiry success prioritises pending reference and existing contact, not reviews or booking promises', async () => {
	const x = cateringRenderFixture(false);
	await x.boot();
	x.state.submittedLocationName = 'Sample Revesby';
	x.showSuccess({ enquiry_number: 'CAT-SYNTHETIC', deposit: 0, message: 'Your booking is confirmed immediately.' });
	const flow = x.root.querySelector('.dbc-app-flow');
	function copy(element) { return [element.innerHTML || '', element.textContent || ''].concat((element.children || []).map(copy)).join(' '); }
	const text = copy(flow);
	assert.match(text, /Reference: <strong>CAT-SYNTHETIC<\/strong>/);
	assert.match(text, /still need staff confirmation/);
	assert.match(text, /not a confirmed booking/);
	assert.match(text, /Reply to your catering acknowledgement/);
	assert.match(text, /Planning another event/);
	assert.doesNotMatch(text, /confirmed immediately|Google review|Instagram|Follow @|Stay close|offers|secure your date|Pay .+deposit/i);
	const contact = find(flow, item => item.tag === 'button');
	assert.equal(contact.type, 'button', 'follow-up is navigation, not form submission');
	assert.equal(contact.textContent, 'Contact catering about this enquiry');
	contact.listeners.click();
	assert.equal(x.contact.revealed, true);
	assert.equal(x.email.focused, true, 'focus reaches the original configured contact action');
	assert.equal(x.email.href, 'mailto:events@example.invalid?subject=Catering');
	assert.equal(x.calls.length, 1, 'contact navigation starts no extra request');
	assert.equal(x.root.querySelector('.dbc-faq'), x.faq);
	x.state.submittedLocationName = '<script>not markup</script>';
	x.showSuccess({ enquiry_number: '<img src=x onerror=alert(1)>', deposit: 0 });
	assert.doesNotMatch(copy(flow), /<script>|<img/);
	assert.match(copy(flow), /&lt;img/);
});

test('catering required contacts describe their live validation message without requiring optional date or phone', () => {
	const source = fs.readFileSync(catering, 'utf8');
	assert.match(source, /Availability, final pricing and any deposit requirements are confirmed by our team\./);
	assert.match(source, /Sending an enquiry does not confirm a booking or take payment\./);
	assert.doesNotMatch(source, /a deposit secures your date|confirm your quote and deposit link/);
	assert.match(source, /name="customer_name" required aria-describedby="dbc-contact-error"/);
	assert.match(source, /name="customer_email" required aria-describedby="dbc-contact-error"/);
	assert.match(source, /id="dbc-contact-error" role="alert" aria-live="assertive"/);
	assert.doesNotMatch(source, /name="(?:customer_phone|event_date|event_time)"[^>]*\brequired\b/);
});

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
	const video = target(), poster = target(), control = target(), status = target(), frame = target(), steamControl = target(), soundControl = target(), motionControl = target();
	video.duration = 10; video.readyState = 2;
	frame.getBoundingClientRect = () => ({ height: 600 });
	video.hidden = true; video.currentTime = 0; video.playCount = 0; video.pauseCount = 0;
	video.attrs['data-db-opening-src'] = 'https://doughboss.com.au/wp-content/uploads/doughboss-commercial-v5-hero-720.mp4';
	video.attrs['data-db-opening-desktop-src'] = 'https://doughboss.com.au/wp-content/uploads/doughboss-commercial-v6-desktop-square.mp4';
	const pendingPlays = [];
	video.play = () => { video.playCount++; return options.deferred ? new Promise((resolve, reject) => pendingPlays.push({ resolve, reject })) : options.reject ? Promise.reject(new Error('blocked')) : Promise.resolve(); };
	video.pause = () => { video.pauseCount++; };
	const elements = { '[data-db-opening-video]': video, '[data-db-opening-poster]': poster,
		'[data-db-opening-control]': control, '[data-db-opening-status]': status, '.db-opening-frame': frame, '[data-db-steam-control]': steamControl, '[data-db-sound-control]': soundControl, '[data-db-motion-control]': motionControl };
	const classes = new Set();
	const opening = { querySelector: key => elements[key], classList: { toggle(key, on) { if (on) classes.add(key); else classes.delete(key); } } };
	const document = target(); document.hidden = false; document.getElementById = () => opening;
	document.readyState = options.loading ? 'loading' : 'complete';
	const motion = target(); motion.matches = !!options.reduced;
	const connection = target(); connection.saveData = !!options.saveData;
	const desktop = target(); desktop.matches = !!options.desktop;
	let intersect;
	const callbacks = new Map(); let sequence = 0;
	const storage = { 'db-hero-motion': options.motionChoice || null };
	const storageAccess = { reads: 0, writes: 0 };
	const window = { ...target(), scrollY: 0, navigator: { connection }, localStorage: { getItem(key) { storageAccess.reads++; if (options.storageBlocked) throw new Error('blocked'); return storage[key]; }, setItem(key, value) { storageAccess.writes++; if (options.storageBlocked) throw new Error('blocked'); storage[key] = value; } }, matchMedia: query => query.includes('min-width') ? desktop : motion,
		requestAnimationFrame(fn) { callbacks.set(++sequence, fn); return sequence; }, cancelAnimationFrame(id) { callbacks.delete(id); },
		IntersectionObserver: function (callback) { intersect = callback; this.observe = element => assert.equal(element, frame); } };
	if (!options.noPreferences) window.DoughBossPreferences = { allowed: () => options.preferencesAllowed !== false };
	const context = vm.createContext({ document, window });
	vm.runInContext(script, context);
	return { source, video, poster, control, status, document, motion, connection, desktop, classes, window, steamControl, soundControl, motionControl, storage, storageAccess, pendingPlays,
		scroll(y) { window.scrollY = y; window.emit('scroll'); },
		flush() { const work = [...callbacks.values()]; callbacks.clear(); work.forEach(fn => fn()); },
		visible(on) { intersect([{ isIntersecting: on, intersectionRatio: on ? .5 : 0 }]); },
		rerun() { vm.runInContext(script, context); } };
}

test('large homepage film autoplays silently in view and respects a persistent manual pause', () => {
	const x = homepageFilmFixture();
	assert.equal(x.video.playCount, 0, 'do not load video before its stage is visible');
	assert.equal(x.video.muted, true); assert.equal(x.soundControl.hidden, false);
	assert.equal(x.soundControl.textContent, 'Unmute'); assert.equal(x.soundControl.getAttribute('aria-label'), 'Unmute film');
	x.visible(true); x.video.emit('playing');
	assert.equal(x.video.playCount, 1); assert.equal(x.video.muted, true); assert.equal(x.video.loop, true);
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
	assert.match(x.source, /\.db-opening-frame\{aspect-ratio:9\/16/, 'portrait master fills its frame on desktop too');
	assert.match(x.source, /prefers-reduced-motion:reduce/);
	assert.match(x.source, /is_admin\(\) \|\| ! is_front_page\(\)/);
});

test('homepage sound choice changes only audio and survives manual playback, visibility and source changes', () => {
	const x = homepageFilmFixture();
	x.soundControl.emit('click');
	assert.equal(x.video.muted, false); assert.equal(x.soundControl.textContent, 'Mute');
	assert.equal(x.soundControl.getAttribute('aria-label'), 'Mute film');
	assert.equal(x.video.playCount, 0, 'unmute must not start the film');
	x.visible(true); x.video.emit('playing');
	x.visible(false); x.visible(true); assert.equal(x.video.muted, false);
	x.document.hidden = true; x.document.emit('visibilitychange');
	x.document.hidden = false; x.document.emit('visibilitychange'); assert.equal(x.video.muted, false);
	x.desktop.matches = true; x.desktop.emit('change'); assert.equal(x.video.muted, false);
	x.control.emit('click'); x.control.emit('click');
	assert.equal(x.video.loop, true); assert.equal(x.video.muted, false, 'resuming retains explicit sound selection');
	x.video.emit('playing'); x.control.emit('click');
	const plays = x.video.playCount;
	x.soundControl.emit('click');
	assert.equal(x.video.muted, true); assert.equal(x.soundControl.textContent, 'Unmute');
	assert.equal(x.soundControl.getAttribute('aria-label'), 'Unmute film');
	assert.equal(x.video.playCount, plays, 'muting a paused film must not resume it');
	x.control.emit('click'); assert.equal(x.video.muted, true);
	x.rerun(); assert.equal(x.soundControl.listeners.click.length, 1, 'no duplicate sound handler');
	assert.match(x.source, /data-db-sound-control aria-controls="db-opening-film" aria-label="Unmute film" hidden>Unmute/);
	assert.ok(x.source.indexOf('<div class="db-opening-utility">') < x.source.indexOf('<div class="db-opening-frame">'), 'controls precede film in normal flow');
	const utility = x.source.match(/\.db-opening-utility\{([^}]+)\}/)[1];
	assert.match(utility, /display:flex;flex-wrap:wrap/);
	assert.doesNotMatch(utility, /position:absolute|top:|left:|right:/, 'wrapped controls cannot overlay the film');
});

test('homepage responsive source selection preserves manual pause and uses the approved quiet master', () => {
	const x = homepageFilmFixture({ desktop: true }); x.visible(true); x.video.emit('playing');
	assert.match(x.source, /\$db_opening_v5_video_url\s*= 'https:\/\/doughboss\.com\.au\/wp-content\/uploads\/doughboss-commercial-v3-web-loop\.mp4';/);
	assert.match(x.source, /\$db_opening_v6_desktop_url = \$db_opening_v5_video_url;/);
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
	assert.equal(x.soundControl.hidden, true, 'unavailable film has no sound control');
	x.soundControl.emit('click'); assert.equal(x.video.muted, true, 'failed film ignores sound input');
	assert.match(x.status.textContent, /image is shown/);
});

test('paused film shows fallback poster with independently pausable steam; active film loops', () => {
	const x = homepageFilmFixture(); x.visible(true); x.video.emit('playing');
	assert.equal(x.video.loop, true); assert.equal(x.classes.has('has-steam'), false);
	x.control.emit('click');
	assert.equal(x.video.hidden, true); assert.equal(x.poster.hidden, false);
	assert.equal(x.classes.has('has-steam'), true); assert.equal(x.steamControl.hidden, false);
	x.steamControl.emit('click'); assert.equal(x.classes.has('has-steam'), false); assert.equal(x.steamControl.textContent, 'Play steam');
	x.visible(false); x.visible(true); assert.equal(x.classes.has('has-steam'), false, 'remember explicit steam pause');
	x.steamControl.emit('click'); assert.equal(x.classes.has('has-steam'), true);
	x.visible(false); assert.equal(x.classes.has('has-steam'), false);
	x.visible(true); x.document.hidden = true; x.document.emit('visibilitychange'); assert.equal(x.classes.has('has-steam'), false);
	x.document.hidden = false; x.document.emit('visibilitychange'); assert.equal(x.classes.has('has-steam'), true);
	x.control.emit('click'); x.video.emit('playing'); assert.equal(x.video.loop, true); assert.equal(x.classes.has('has-steam'), false);
});

test('homepage native loop keeps playing across film boundaries and visibility suspension', () => {
	const x = homepageFilmFixture(); x.visible(true); x.video.emit('playing');
	assert.equal(x.video.loop, true); assert.equal(x.control.textContent, 'Pause film');
	// A native loop wraps its own clock without an ended event or a controller seek.
	x.video.currentTime = .1; x.video.emit('playing');
	assert.equal(x.video.playCount, 1); assert.equal(x.video.currentTime, .1);
	assert.equal(x.control.textContent, 'Pause film'); assert.equal(x.classes.has('has-steam'), false);
	x.visible(false); assert.equal(x.classes.has('is-playing'), false);
	x.visible(true); x.video.emit('playing');
	assert.equal(x.video.playCount, 2); assert.equal(x.video.currentTime, .1); assert.equal(x.video.loop, true);
	x.document.hidden = true; x.document.emit('visibilitychange');
	x.document.hidden = false; x.document.emit('visibilitychange'); x.video.emit('playing');
	assert.equal(x.video.playCount, 3); assert.equal(x.video.currentTime, .1); assert.equal(x.video.loop, true);
	x.control.emit('click'); assert.equal(x.control.textContent, 'Play film');
	x.visible(false); x.visible(true); assert.equal(x.video.playCount, 3, 'manual pause survives visibility changes');
	x.control.emit('click'); assert.equal(x.video.playCount, 4); assert.equal(x.video.loop, true);
});

test('steam is restricted to the box scene or poster and respects reduced motion and data saving', () => {
	const x = homepageFilmFixture(); x.visible(true); x.video.emit('playing'); x.video.currentTime = 3;
	x.video.emit('waiting'); assert.equal(x.classes.has('has-steam'), false, 'no steam floating off the bake board');
	x.video.currentTime = 6; x.video.emit('waiting'); assert.equal(x.classes.has('has-steam'), true, 'box reveal may breathe while stationary');
	x.motion.matches = true; x.motion.emit('change'); assert.equal(x.classes.has('has-steam'), false); assert.equal(x.steamControl.hidden, true);
	const saved = homepageFilmFixture({ saveData: true }); saved.visible(true); assert.equal(saved.classes.has('has-steam'), false);
	assert.match(x.source, /id="db-opening-steam" aria-hidden="true"/);
	assert.match(x.source, /\.has-steam \.db-opening-steam span\{animation-play-state:running\}/);
});

test('homepage scrolling never seeks, pauses or takes over native film playback', async () => {
	const x = homepageFilmFixture(); x.visible(true); x.video.emit('playing'); x.video.currentTime = 3;
	const pauses = x.video.pauseCount;
	for (const y of [60, 30, 10000, 0]) { x.scroll(y); x.flush(); assert.equal(x.video.currentTime, 3); }
	await Promise.resolve();
	assert.equal(x.video.pauseCount, pauses); assert.equal(x.video.playCount, 1);
	assert.equal(x.video.loop, true); assert.equal(x.control.textContent, 'Pause film');
	assert.equal(x.window.listeners.scroll, undefined, 'IntersectionObserver does not need a scroll listener');
	assert.equal(x.video.listeners.seeked, undefined, 'there is no seek-driven controller state');
	x.visible(false); x.scroll(3000); x.flush(); assert.equal(x.video.currentTime, 3);
	x.visible(true); x.video.emit('playing'); x.scroll(2940); x.flush();
	assert.equal(x.video.currentTime, 3); assert.equal(x.video.playCount, 2);
});

test('homepage manual pause and restrictive preferences stop loops without scroll resumption', () => {
	for (const preference of ['pause', 'motion', 'connection', 'hidden']) {
		const x = homepageFilmFixture(); x.visible(true); x.video.emit('playing'); x.video.currentTime = 3;
		if (preference === 'pause') x.control.emit('click');
		if (preference === 'motion') { x.motion.matches = true; x.motion.emit('change'); }
		if (preference === 'connection') { x.connection.saveData = true; x.connection.emit('change'); }
		if (preference === 'hidden') { x.document.hidden = true; x.document.emit('visibilitychange'); }
		const pauses = x.video.pauseCount; x.scroll(120); x.flush();
		assert.equal(x.video.currentTime, 3, preference); assert.equal(x.video.playCount, 1, preference);
		assert.equal(x.video.pauseCount, pauses, preference); assert.equal(x.control.textContent, 'Play film', preference);
		assert.equal(x.classes.has('is-playing'), false, preference);
	}
});

test('stale play promises cannot pause a newer visible playback request', async () => {
	const x = homepageFilmFixture({ deferred: true });
	x.visible(true); x.visible(false); x.visible(true); x.video.emit('playing');
	assert.equal(x.pendingPlays.length, 2);
	const pauses = x.video.pauseCount;
	x.pendingPlays[0].resolve(); await Promise.resolve();
	assert.equal(x.video.pauseCount, pauses, 'old completion must not pause the new request');
	assert.equal(x.control.textContent, 'Pause film'); assert.equal(x.classes.has('is-playing'), true);
	x.control.emit('click'); const stopped = x.video.pauseCount;
	x.pendingPlays[1].resolve(); await Promise.resolve();
	assert.ok(x.video.pauseCount > stopped, 'late play completion still honours manual pause');
	x.control.emit('click'); x.visible(false);
	const offscreen = x.video.playCount; x.control.emit('click');
	assert.equal(x.video.playCount, offscreen, 'even explicit play cannot run offscreen');
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

test('explicit hero motion choice persists and enables native looping under reduced motion', () => {
	const x = homepageFilmFixture({ reduced: true });
	x.visible(true);
	assert.equal(x.video.playCount, 0);
	assert.equal(x.motionControl.textContent, 'Enable motion');
	x.motionControl.emit('click'); x.video.emit('playing');
	assert.equal(x.storage['db-hero-motion'], 'on');
	assert.equal(x.video.playCount, 1);
	assert.equal(x.video.muted, true);
	assert.equal(x.video.loop, true);
	x.video.currentTime = .1; x.video.emit('playing');
	assert.equal(x.classes.has('has-steam'), false, 'steam is only a fallback while the film is not playing');
	assert.equal(x.classes.has('is-motion-enabled'), true);
	x.motionControl.emit('click');
	assert.equal(x.storage['db-hero-motion'], 'off');
	assert.equal(x.classes.has('has-steam'), false);
	x.visible(false); x.visible(true);
	assert.equal(x.video.playCount, 1, 'reducing motion remains paused');
	const returning = homepageFilmFixture({ reduced: true, motionChoice: 'on' });
	returning.visible(true);
	assert.equal(returning.video.playCount, 1, 'chosen motion autoplays on the next visit');
	const saved = homepageFilmFixture({ saveData: true, motionChoice: 'on' });
	saved.visible(true);
	assert.equal(saved.video.playCount, 0, 'data saver still prevents automatic download');
	const blocked = homepageFilmFixture({ reduced: true, storageBlocked: true });
	blocked.visible(true); blocked.motionControl.emit('click');
	assert.equal(blocked.video.playCount, 1, 'storage denial does not break explicit playback');
});

test('hero motion stays in memory unless preferences consent is available and allowed', () => {
	for (const consent of [{ noPreferences: true }, { preferencesAllowed: false }]) {
		const x = homepageFilmFixture({ ...consent, reduced: true, motionChoice: 'on' });
		x.visible(true);
		assert.equal(x.video.playCount, 0, 'stored choice cannot bypass reduced motion without consent');
		x.motionControl.emit('click');
		assert.equal(x.video.playCount, 1, 'explicit in-memory playback still works');
		x.motionControl.emit('click');
		assert.equal(x.storageAccess.reads, 0, 'denied preferences are never read');
		assert.equal(x.storageAccess.writes, 0, 'denied preferences are never persisted');
	}
	const granted = homepageFilmFixture({ reduced: true });
	granted.visible(true);
	granted.window.DoughBossPreferences.allowed = () => false;
	granted.motionControl.emit('click');
	assert.equal(granted.video.playCount, 1);
	assert.equal(granted.storageAccess.writes, 0, 'revocation takes effect before the next storage write');
});

test('hero resolves footer-loaded consent before autoplay without overriding current-page choices', () => {
	const returning = homepageFilmFixture({ loading: true, noPreferences: true, motionChoice: 'off' });
	returning.visible(true);
	assert.equal(returning.video.playCount, 0, 'autoplay waits for footer preferences');
	assert.equal(returning.storageAccess.reads, 0);
	returning.window.DoughBossPreferences = { allowed: () => true };
	returning.document.emit('DOMContentLoaded');
	assert.equal(returning.storageAccess.reads, 1);
	assert.equal(returning.video.playCount, 0, 'saved off prevents automatic playback');
	assert.equal(returning.video.loop, false);
	for (const allowed of [false, null]) {
		const denied = homepageFilmFixture({ loading: true, noPreferences: true, motionChoice: 'off' });
		denied.visible(true);
		if (allowed !== null) denied.window.DoughBossPreferences = { allowed: () => allowed };
		denied.document.emit('DOMContentLoaded');
		assert.equal(denied.storageAccess.reads, 0, 'denied or missing consent never reads optional storage');
		assert.equal(denied.video.playCount, 1, 'default motion is usable without optional storage');
	}
	const chosen = homepageFilmFixture({ loading: true, noPreferences: true, motionChoice: 'off', reduced: true });
	chosen.visible(true);
	chosen.motionControl.emit('click');
	assert.equal(chosen.video.playCount, 1, 'explicit page choice may play before footer');
	chosen.window.DoughBossPreferences = { allowed: () => true };
	chosen.document.emit('DOMContentLoaded');
	assert.equal(chosen.storageAccess.reads, 0, 'late initial preference never overrides explicit page choice');
	assert.equal(chosen.video.pauseCount, 0);
});

function announcementFixture(options = {}) {
	const source = fs.readFileSync(path.join(root, 'scripts/hero-commercial/homepage-hero-v6-snippet.php'), 'utf8');
	const script = source.match(/<script id="db-announcements-controller">([\s\S]*?)<\/script>/)[1];
	function target() { return { listeners: {}, hidden: false, attrs: {}, addEventListener(n, fn) { this.listeners[n] = fn; }, emit(n, event = {}) { this.listeners[n]?.(event); }, setAttribute(n, v) { this.attrs[n] = v; } }; }
	const slides = [target(), target(), target()]; slides[1].hidden = slides[2].hidden = true;
	const play = target(), next = target(), bar = target(), document = target(), motion = target(), connection = target();
	motion.matches = !!options.reduced; connection.saveData = !!options.saveData;
	bar.querySelectorAll = () => slides;
	bar.querySelector = key => key.includes('-play') ? play : next;
	bar.classList = { toggle() {} }; bar.contains = el => el === play || el === next;
	document.querySelector = () => bar; document.hidden = false;
	const timers = new Map(); let sequence = 0, intersect;
	const window = { navigator: { connection }, matchMedia: () => motion,
		setTimeout(fn) { timers.set(++sequence, fn); return sequence; }, clearTimeout(id) { timers.delete(id); },
		IntersectionObserver: function (fn) { intersect = fn; this.observe = () => {}; } };
	const context = vm.createContext({ document, window }); vm.runInContext(script, context);
	return { source, bar, play, next, slides, document, motion, timers, visible(on) { intersect([{ isIntersecting: on }]); }, tick() { const jobs = [...timers.values()]; timers.clear(); jobs.forEach(fn => fn()); } };
}

test('announcements rotate one real link at a time and retain explicit pause', () => {
	const x = announcementFixture();
	assert.equal(x.timers.size, 1); x.tick();
	assert.deepEqual(x.slides.map(s => s.hidden), [true, false, true]);
	x.play.emit('click'); assert.equal(x.timers.size, 0);
	x.visible(false); x.visible(true); assert.equal(x.timers.size, 0);
	x.next.emit('click'); assert.deepEqual(x.slides.map(s => s.hidden), [true, true, false]);
	assert.equal(x.timers.size, 0, 'next does not restart paused rotation');
	x.play.emit('click'); assert.equal(x.timers.size, 1);
});

test('announcement rotation suspends for focus, hover, hidden tabs and reduced motion', () => {
	const x = announcementFixture();
	x.bar.emit('focusin'); assert.equal(x.timers.size, 0);
	x.bar.emit('focusout', { relatedTarget: x.next }); assert.equal(x.timers.size, 0);
	x.bar.emit('focusout', { relatedTarget: null }); assert.equal(x.timers.size, 1);
	x.bar.emit('mouseenter'); assert.equal(x.timers.size, 0);
	x.bar.emit('mouseleave'); assert.equal(x.timers.size, 1);
	x.document.hidden = true; x.document.emit('visibilitychange'); assert.equal(x.timers.size, 0);
	x.document.hidden = false; x.document.emit('visibilitychange'); assert.equal(x.timers.size, 1);
	x.visible(false); assert.equal(x.timers.size, 0);
	for (const options of [{ reduced: true }, { saveData: true }]) {
		const limited = announcementFixture(options); assert.equal(limited.timers.size, 0);
		limited.next.emit('click'); assert.equal(limited.slides[1].hidden, false);
	}
	assert.doesNotMatch(x.source.match(/<aside class="db-announcements"[\s\S]*?<\/aside>/)[0], /aria-live|Join rewards|\d+% off/);
});

test('frontend repair widens desktop layout, scales type and removes sticky backdrop repaint', () => {
	const source = fs.readFileSync(path.join(root, 'scripts/hero-commercial/homepage-hero-v6-snippet.php'), 'utf8');
	assert.match(source, /--dbf-wrap:1840px;font-size:clamp\(16px,calc\(12px \+ .4vw\),22px\)/);
  assert.match(source, /@media\(min-width:1101px\) and \(max-width:1599px\)/);
  assert.match(source, /\.dbf-header-inner \.dbf-nav\{grid-column:1\/-1;grid-row:2;justify-self:center\}/);
	assert.match(source, /\.dbf-hero \.dbf-wrap\{width:min\(1920px,calc\(100% - 32px\)\)\}/);
	assert.match(source, /\.dbf-header::before\{background:var\(--dbf-coal,#151210\);backdrop-filter:none;-webkit-backdrop-filter:none\}/);
	assert.match(source, /function_exists\( 'doughboss_final_ordering_open' \)/, 'global styles stay scoped to the existing theme');
});

function titleSteamFixture(options = {}) {
	const source = fs.readFileSync(path.join(root, 'scripts', 'hero-commercial', 'homepage-hero-v6-snippet.php'), 'utf8');
	const script = source.match(/<script id="db-title-steam-controller">([\s\S]*?)<\/script>/)[1];
	function eventTarget() {
		return { listeners: {}, addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); },
			emit(name, event = {}) { for (const callback of this.listeners[name] || []) callback(event); } };
	}
	const targets = [node('div'), node('header')];
	for (const target of targets) {
		target.classes = new Set();
		target.classList = { toggle(name, on) { if (on) target.classes.add(name); else target.classes.delete(name); } };
	}
	const motion = { ...eventTarget(), matches: !!options.reduced };
	const connection = { ...eventTarget(), saveData: !!options.saveData };
	const control = node('button', { 'aria-pressed': 'true' });
	const document = { ...eventTarget(), hidden: false,
		body: { classList: { contains: name => name === 'home' && !options.notHome } },
		querySelectorAll(selector) { assert.equal(selector, '.db-opening-copy,.dbf-section-head'); return targets; },
		querySelector(selector) { assert.equal(selector, '[data-db-motion-control]'); return options.noControl ? null : control; }
	};
	const observed = [];
	let observerCallback;
	const window = { navigator: { connection }, matchMedia: () => motion };
	if (!options.noObserver) window.IntersectionObserver = function (callback) {
		observerCallback = callback;
		this.observe = target => observed.push(target);
	};
	vm.runInNewContext(script, { document, window, Set });
	return { source, targets, motion, connection, control, document, observed,
		visible(index, on) { observerCallback([{ target: targets[index], isIntersecting: on }]); },
		motionClick(on) { control.setAttribute('aria-pressed', String(on)); document.emit('click', { target: { closest: () => control } }); }
	};
}

test('title steam runs only on visible headings and suspends while the page is hidden', () => {
	const x = titleSteamFixture();
	assert.equal(x.observed.length, 2);
	assert.equal(x.targets[0].getAttribute('data-db-ambient'), '');
	x.visible(0, true);
	assert.equal(x.targets[0].classes.has('db-ambient-visible'), true);
	assert.equal(x.targets[1].classes.has('db-ambient-visible'), false);
	x.document.hidden = true; x.document.emit('visibilitychange');
	assert.equal(x.targets[0].classes.has('db-ambient-visible'), false);
	x.document.hidden = false; x.document.emit('visibilitychange');
	assert.equal(x.targets[0].classes.has('db-ambient-visible'), true);
	x.visible(0, false);
	assert.equal(x.targets[0].classes.has('db-ambient-visible'), false);
});

test('title steam honours reduced motion, Save-Data and the explicit motion control', () => {
	for (const preference of ['reduced', 'saveData']) {
		const x = titleSteamFixture({ [preference]: true });
		x.visible(0, true);
		assert.equal(x.targets[0].classes.has('db-ambient-visible'), false, preference);
		x.motion.matches = false; x.connection.saveData = false;
		x.motion.emit('change'); x.connection.emit('change');
		assert.equal(x.targets[0].classes.has('db-ambient-visible'), true, preference + ' can be changed');
	}
	const x = titleSteamFixture(); x.visible(0, true);
	x.motionClick(false);
	assert.equal(x.targets[0].classes.has('db-ambient-visible'), false);
	x.visible(0, false); x.visible(0, true);
	assert.equal(x.targets[0].classes.has('db-ambient-visible'), false, 'scrolling cannot undo the motion pause');
	x.motionClick(true);
	assert.equal(x.targets[0].classes.has('db-ambient-visible'), true);
	assert.match(x.source, /@media\(prefers-reduced-motion:reduce\)\{body\.home \[data-db-ambient\][\s\S]*?animation:none!important/);
});

test('title steam safely skips other pages and browsers without an intersection observer', () => {
	for (const options of [{ notHome: true }, { noObserver: true }]) {
		const x = titleSteamFixture(options);
		assert.equal(x.observed.length, 0);
		assert.equal(x.targets[0].getAttribute('data-db-ambient'), null);
		assert.deepEqual(Object.keys(x.document.listeners), []);
	}
	const noControl = titleSteamFixture({ noControl: true });
	noControl.visible(0, true);
	assert.equal(noControl.targets[0].classes.has('db-ambient-visible'), true, 'heading effect does not require a film control');
});

test('Members & Events delegates rewards status to the gated Square shortcode and retains private tracking paths', () => {
	const source = fs.readFileSync(path.join(root, 'scripts', 'hero-commercial', 'members-events-page.html'), 'utf8');
	assert.match(source, /Your Square membership/);
	assert.equal((source.match(/\[doughboss_square_membership\]/g) || []).length, 1);
	assert.doesNotMatch(source, /https:\/\/squareup\.com\/loyalty\//, 'no hardcoded merchant or invented sign-in URL');
	assert.match(source, /There are no event dates or bookings listed/);
	assert.match(source, /order number and the email address used at checkout/);
	assert.match(source, /Catering updates are handled by our team, not the website order tracker/);
	assert.match(source, /never send card details by email/);
	assert.doesNotMatch(source, /<form\b|<script\b|<iframe\b|<h1\b/i, 'no invented signup, embed or second page title');
	const links = [...source.matchAll(/\bhref="([^"]+)"/g)].map(match => match[1]);
	assert.deepEqual([...new Set(links)].sort(), ['/track-order/', '#db-me-catering', '#db-me-app', '#db-me-membership-title', '/locations/', 'mailto:catering@doughboss.com.au', 'tel:+61297742286', '/catering/', '/order/'].sort());
	assert.match(source, /<button[^>]*data-db-install hidden>/, 'install action is natively hidden until a genuine browser offer');
	assert.match(source, /data-db-install-status role="status" aria-live="polite"/);
	assert.match(source, /internet connection/);
	assert.match(source, /saving the app doesn’t switch on online ordering or membership/);
	assert.match(source, /iPhone or iPad/);
	assert.match(source, /Android phone/);
	assert.ok(links.every(link => !/[?&](?:email|token|order)=/i.test(link)), 'customer details never enter the navigation URLs');
	const ids = [...source.matchAll(/\bid="([^"]+)"/g)].map(match => match[1]);
	assert.equal(new Set(ids).size, ids.length, 'content anchors are unique');
	for (const link of links.filter(link => link.startsWith('#'))) assert.ok(ids.includes(link.slice(1)));
	for (const match of source.matchAll(/aria-labelledby="([^"]+)"/g)) {
		for (const id of match[1].split(/\s+/)) assert.ok(ids.includes(id), 'accessible section name resolves: ' + id);
	}
	assert.doesNotMatch(source, /font-size:\s*(?:[0-9]|1[0-7])px/);
  assert.match(source, /\.dbf-button:not\(\.db-me-secondary\)\{color:#fff;background:#b41913;border-color:#b41913\}/);
  assert.match(source, /\.dbf-button:not\(\.db-me-secondary\):hover,[^\n]+:focus-visible\{color:#fff;background:#8f110d;border-color:#8f110d\}/);
	assert.match(source, /@media\(max-width:720px\)[\s\S]*?grid-template-columns:minmax\(0,1fr\)/);
});

test('About hero uses the existing authentic pie photograph without darkening or enlargement', () => {
	const about = fs.readFileSync(path.join(root, 'themes', 'doughboss-final', 'page-about-us.php'), 'utf8');
	const source = fs.readFileSync(path.join(root, 'scripts', 'hero-commercial', 'homepage-hero-v6-snippet.php'), 'utf8');
	const photo = 'menu/real-v1/dough-boss-pie.jpg';
	assert.match(about, /dbf-page-hero db-about-hero/);
	assert.ok(about.includes("doughboss_final_asset_image( '" + photo + "'"), 'use the established responsive image helper');
	assert.match(about, /Two golden Dough Boss pies on a wooden board/);
	assert.doesNotMatch(about, /doughboss-feast-real-v1\.jpg/);
	assert.match(about, /550px/);
	assert.ok(fs.existsSync(path.join(root, 'public', 'images', photo)));
	const manifest = JSON.parse(fs.readFileSync(path.join(root, 'public', 'images', 'responsive', 'manifest.json'), 'utf8'));
	const image = (manifest.images || manifest)[photo];
	assert.equal(image.width, 550); assert.equal(image.height, 440);
	assert.ok(image.sources.webp.length && image.sources.avif.length);
	assert.match(source, /\.db-about-hero::after\{display:none\}/);
	assert.match(source, /\.db-about-hero \.dbf-page-hero-bg\{[^}]*position:static;[^}]*aspect-ratio:5\/4;object-fit:contain;filter:none/);
	assert.match(source, /@media\(max-width:720px\)\{\.db-about-hero\{grid-template-columns:minmax\(0,1fr\)\}/);
});
