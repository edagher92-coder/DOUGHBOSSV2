/*
 * Drives the harness pages in Chromium and asserts the v2.5 storefront
 * acceptance checks. Build the pages first: node tests/js/build-harness.js
 *
 * Set DB_CHROME to a Chromium binary to use it instead of Playwright's
 * bundled build (e.g. on a box where `playwright install` is not wanted).
 */
var path = require('path');
var { chromium } = require('playwright');

var DIR = path.join(__dirname, '.build');
var URL_BASE = 'file://' + path.join(DIR, 'harness.html');
var EXEC = process.env.DB_CHROME || undefined;

var results = [];
var consoleLog = [];

function record(id, pass, detail) {
	results.push({ id: id, pass: pass, detail: detail });
	console.log((pass ? 'PASS' : 'FAIL') + '  ' + id + '  ' + (detail || ''));
}

function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

(async function () {
	var browser = await chromium.launch({ executablePath: EXEC, args: ['--no-sandbox'] });
	var ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
	var page = await ctx.newPage();

	page.on('console', function (msg) {
		consoleLog.push('[' + msg.type() + '] ' + msg.text());
	});
	page.on('pageerror', function (err) {
		consoleLog.push('[pageerror] ' + err.message);
	});

	await page.goto(URL_BASE);
	await page.waitForSelector('.db-card', { timeout: 10000 });
	await sleep(300);

	/* (a) add item -> 1 cart line + badge shows 1 -------------------- */
	await page.click('.db-card[data-item-id="101"] .db-add');
	await page.waitForSelector('.db-cart-line', { timeout: 5000 });
	await sleep(400);
	var lineCount = await page.$$eval('.db-cart-line', function (n) { return n.length; });
	var badgeCount = await page.$eval('.db-cart-badge-count', function (n) { return n.textContent; });
	var badgeTotal = await page.$eval('.db-cart-badge-total', function (n) { return n.textContent; });
	record('a: add item -> 1 line, badge "1"',
		lineCount === 1 && badgeCount === '1',
		'lines=' + lineCount + ' badgeCount="' + badgeCount + '" badgeTotal="' + badgeTotal + '"');

	/* (g) el() fix: aria-live really is an attribute ------------------ */
	var ariaLive = await page.$eval('.db-checkout-msg', function (n) { return n.getAttribute('aria-live'); });
	record('g: .db-checkout-msg aria-live === "polite"', ariaLive === 'polite', 'got "' + ariaLive + '"');

	/* (b) typed value survives a quantity change ---------------------- */
	await page.fill('.db-checkout input[name="customer_name"]', 'Elie Dagher');
	await page.fill('.db-checkout input[name="customer_email"]', 'elie@example.com');
	await page.fill('.db-checkout input[name="customer_phone"]', '0466353133');
	var totalBefore = await page.$eval('.db-total-row--grand .db-total-value', function (n) { return n.textContent; });
	await page.click('.db-cart-line .db-step--plus');
	await sleep(900); // past the 350ms debounce + the stubbed 10ms round trip
	var nameAfter = await page.$eval('.db-checkout input[name="customer_name"]', function (n) { return n.value; });
	var totalAfter = await page.$eval('.db-total-row--grand .db-total-value', function (n) { return n.textContent; });
	var qtyAfter = await page.$eval('.db-qty', function (n) { return n.value; });
	record('b: name preserved + totals updated after "+"',
		nameAfter === 'Elie Dagher' && totalAfter !== totalBefore && qtyAfter === '2',
		'name="' + nameAfter + '" qty=' + qtyAfter + ' total ' + totalBefore + ' -> ' + totalAfter);

	/* (h) fulfilment toggle: address visibility + totals only ---------- */
	var addrHiddenPickup = await page.$eval('.db-checkout textarea[name="address"]',
		function (n) { return n.closest('.db-field').hidden; });
	await page.check('.db-fulfilment input[value="delivery"]');
	await sleep(500);
	var afterDelivery = await page.evaluate(function () {
		var addr = document.querySelector('.db-checkout textarea[name="address"]');
		var rows = Array.prototype.map.call(document.querySelectorAll('.db-total-row'), function (r) {
			return r.querySelector('.db-total-label').textContent + '=' + r.querySelector('.db-total-value').textContent;
		});
		return {
			addrHidden: addr.closest('.db-field').hidden,
			required: addr.required,
			name: document.querySelector('.db-checkout input[name="customer_name"]').value,
			rows: rows
		};
	});
	record('h: delivery toggle shows/requires address, re-prices totals, keeps typed values',
		addrHiddenPickup === true && afterDelivery.addrHidden === false && afterDelivery.required === true &&
		afterDelivery.name === 'Elie Dagher' && afterDelivery.rows.join('|').indexOf('Delivery=$5.00') !== -1,
		'pickupHidden=' + addrHiddenPickup + ' deliveryHidden=' + afterDelivery.addrHidden +
		' required=' + afterDelivery.required + ' name="' + afterDelivery.name + '" rows=' + afterDelivery.rows.join(' | '));

	await page.check('.db-fulfilment input[value="pickup"]');
	await sleep(400);

	/* (i) remove -> empty state + undo toast --------------------------- */
	await page.click('.db-cart-line .db-remove');
	await sleep(400);
	var emptyState = await page.evaluate(function () {
		var empty = document.querySelector('.db-empty-cart');
		return {
			emptyVisible: empty.offsetParent !== null,
			orderHidden: document.querySelector('.db-order').hidden,
			undo: !!document.querySelector('.db-toast-action')
		};
	});
	record('i: remove empties the cart and offers Undo',
		emptyState.emptyVisible && emptyState.orderHidden && emptyState.undo,
		JSON.stringify(emptyState));

	await page.click('.db-toast-action'); // Undo
	await page.waitForSelector('.db-cart-line', { timeout: 5000 });
	await sleep(400);
	var restored = await page.$$eval('.db-cart-line', function (n) { return n.length; });
	var nameAfterUndo = await page.$eval('.db-checkout input[name="customer_name"]', function (n) { return n.value; });
	record('i2: undo restores the line and the form is intact',
		restored === 1 && nameAfterUndo === 'Elie Dagher',
		'lines=' + restored + ' name="' + nameAfterUndo + '"');

	/* (c) checkout -> receipt persists -------------------------------- */
	await page.click('.db-checkout .db-submit');
	await page.waitForSelector('.db-confirm', { timeout: 5000 });
	var numberNow = await page.$eval('.db-confirm-number strong', function (n) { return n.textContent; });
	await sleep(1500);
	var stillThere = await page.$('.db-confirm');
	var numberLater = stillThere ? await page.$eval('.db-confirm-number strong', function (n) { return n.textContent; }) : null;
	var badgeAfterOrder = await page.$eval('.db-cart-badge-count', function (n) { return n.textContent; });
	record('c: receipt with order number present and still present after 1500ms',
		numberNow === 'DB-260906-A7K2' && numberLater === 'DB-260906-A7K2',
		'number="' + numberNow + '" after1500ms="' + numberLater + '" badge="' + badgeAfterOrder + '"');

	/* tracking (bonus sanity) ---------------------------------------- */
	await page.fill('.db-track-form input[name="number"]', 'DB-260906-A7K2');
	await page.fill('.db-track-form input[name="email"]', 'elie@example.com');
	await page.click('.db-track-form button[type="submit"]');
	await page.waitForSelector('.db-track-card', { timeout: 5000 });
	var pill = await page.$eval('.db-pill', function (n) { return n.className + ' | ' + n.textContent; });
	record('bonus: tracking renders a status pill', /db-pill--preparing/.test(pill), pill);

	await page.screenshot({ path: path.join(DIR, 'shot-desktop.png'), fullPage: true });

	/* (f) 390px viewport -> no horizontal overflow -------------------- */
	var mobile = await ctx.newPage();
	mobile.on('console', function (msg) { consoleLog.push('[mobile ' + msg.type() + '] ' + msg.text()); });
	mobile.on('pageerror', function (err) { consoleLog.push('[mobile pageerror] ' + err.message); });
	await mobile.setViewportSize({ width: 390, height: 844 });
	await mobile.goto(URL_BASE);
	await mobile.waitForSelector('.db-card', { timeout: 10000 });
	await mobile.click('.db-card[data-item-id="102"] .db-add');
	await mobile.waitForSelector('.db-cart-line', { timeout: 5000 });
	await sleep(400);
	await mobile.click('.db-card[data-item-id="103"] .db-add');
	await sleep(600);
	var overflow = await mobile.evaluate(function () {
		var docW = document.documentElement.clientWidth;
		var worst = null;
		var nodes = document.querySelectorAll('.db-app, .db-app *');
		for (var i = 0; i < nodes.length; i++) {
			var r = nodes[i].getBoundingClientRect();
			if (r.right > docW + 1) {
				if (!worst || r.right > worst.right) {
					worst = { right: Math.round(r.right), cls: nodes[i].className || nodes[i].tagName };
				}
			}
		}
		return {
			scrollW: document.documentElement.scrollWidth,
			clientW: docW,
			bodyScrollW: document.body.scrollWidth,
			worst: worst
		};
	});
	// Clipping inside an overflow:hidden container wouldn't show up in
	// scrollWidth, so check the cart rows explicitly too.
	var clipped = await mobile.evaluate(function () {
		var bad = [];
		Array.prototype.forEach.call(document.querySelectorAll('.db-cart-line'), function (row) {
			var rr = row.getBoundingClientRect();
			Array.prototype.forEach.call(row.querySelectorAll('*'), function (child) {
				var cr = child.getBoundingClientRect();
				if (cr.width && cr.right > rr.right + 1) {
					bad.push((child.className || child.tagName) + ' right=' + Math.round(cr.right) + ' row=' + Math.round(rr.right));
				}
			});
		});
		return bad;
	});
	record('f2: no control clipped inside a cart row at 390px', clipped.length === 0, clipped.join(' || ') || 'clean');

	record('f: no horizontal overflow at 390px',
		overflow.scrollW <= overflow.clientW && !overflow.worst,
		'scrollWidth=' + overflow.scrollW + ' clientWidth=' + overflow.clientW +
		' worst=' + JSON.stringify(overflow.worst));
	await mobile.screenshot({ path: path.join(DIR, 'shot-mobile.png'), fullPage: true });

	/* (d) ordering_open:false -> add buttons disabled ----------------- */
	var closed = await ctx.newPage();
	closed.on('console', function (msg) { consoleLog.push('[closed ' + msg.type() + '] ' + msg.text()); });
	closed.on('pageerror', function (err) { consoleLog.push('[closed pageerror] ' + err.message); });
	await closed.goto(URL_BASE + '?closed=1');
	await closed.waitForSelector('.db-card', { timeout: 10000 });
	await sleep(400);
	var btnState = await closed.$$eval('.db-add, .db-builder-add', function (nodes) {
		return nodes.map(function (n) {
			return { disabled: n.disabled, aria: n.getAttribute('aria-disabled') };
		});
	});
	var closedPanels = await closed.$$eval('.db-closed', function (n) { return n.length; });
	var allDisabled = btnState.length > 0 && btnState.every(function (s) { return s.disabled === true; });
	record('d: ordering_open:false disables every add button',
		allDisabled && closedPanels >= 2,
		'buttons=' + btnState.length + ' allDisabled=' + allDisabled + ' closedPanels=' + closedPanels);
	await closed.screenshot({ path: path.join(DIR, 'shot-closed.png'), fullPage: true });

	/* (j) category pill scroller + custom-pizza Edit ------------------- */
	var custom = await ctx.newPage();
	custom.on('console', function (msg) { consoleLog.push('[custom ' + msg.type() + '] ' + msg.text()); });
	custom.on('pageerror', function (err) { consoleLog.push('[custom pageerror] ' + err.message); });
	await custom.goto(URL_BASE);
	await custom.waitForSelector('.db-card', { timeout: 10000 });
	await sleep(300);

	var pills = await custom.$$eval('.db-catpill', function (n) {
		return n.map(function (p) { return p.textContent + (p.getAttribute('aria-current') === 'true' ? '*' : ''); });
	});
	record('j1: category pill scroller renders one pill per category',
		pills.length === 2 && pills[0] === 'Pizzas*', pills.join(', '));

	await custom.check('.db-builder-inner input[value="large"]');
	await custom.check('.db-builder-inner input[value="pepperoni"]');
	await custom.check('.db-builder-inner input[value="olives"]');
	await sleep(450);
	var builderPrice = await custom.$eval('.db-builder-price-value', function (n) { return n.textContent; });
	var builderDesc = await custom.$eval('.db-builder-price .db-sr-only', function (n) { return n.textContent; });
	record('j2: builder <output> announces price + configuration',
		builderPrice === '$25.00' && /Large \(16"\)/.test(builderDesc) && /Pepperoni, Olives/.test(builderDesc),
		'price=' + builderPrice + ' desc="' + builderDesc + '"');

	await custom.click('.db-builder-add');
	await custom.waitForSelector('.db-cart-line', { timeout: 5000 });
	await sleep(500);
	var customLine = await custom.$eval('.db-cart-line', function (n) {
		return n.querySelector('.db-line-name').textContent + ' | ' + n.querySelector('.db-line-sub').textContent;
	});

	await custom.click('.db-cart-line .db-edit');
	await sleep(500);
	var rehydrated = await custom.evaluate(function () {
		return {
			large: document.querySelector('.db-builder-inner input[value="large"]').checked,
			pepperoni: document.querySelector('.db-builder-inner input[value="pepperoni"]').checked,
			olives: document.querySelector('.db-builder-inner input[value="olives"]').checked,
			basil: document.querySelector('.db-builder-inner input[value="basil"]').checked,
			btn: document.querySelector('.db-builder-add').textContent
		};
	});
	record('j3: Edit re-hydrates the builder from the cart line',
		rehydrated.large && rehydrated.pepperoni && rehydrated.olives && !rehydrated.basil &&
		rehydrated.btn === 'Update pizza',
		customLine + ' -> ' + JSON.stringify(rehydrated));

	await custom.uncheck('.db-builder-inner input[value="olives"]');
	await sleep(400);
	await custom.click('.db-builder-add');
	await sleep(1200);
	var afterEdit = await custom.evaluate(function () {
		var lines = document.querySelectorAll('.db-cart-line');
		return {
			count: lines.length,
			sub: lines.length ? lines[0].querySelector('.db-line-sub').textContent : '',
			btn: document.querySelector('.db-builder-add').textContent
		};
	});
	record('j4: updating replaces the original line (add first, then remove)',
		afterEdit.count === 1 && afterEdit.sub.indexOf('Olives') === -1 &&
		afterEdit.sub.indexOf('Pepperoni') !== -1,
		JSON.stringify(afterEdit));

	/* (k) server-rendered menu path ------------------------------------ */
	var ssr = await ctx.newPage();
	ssr.on('console', function (msg) { consoleLog.push('[ssr ' + msg.type() + '] ' + msg.text()); });
	ssr.on('pageerror', function (err) { consoleLog.push('[ssr pageerror] ' + err.message); });
	await ssr.goto('file://' + path.join(DIR, 'harness-ssr.html'));
	await ssr.waitForSelector('.db-catnav', { timeout: 10000 });
	await sleep(400);
	var reqs = await ssr.evaluate(function () { return window.__dbRequests.slice(); });
	var menuFetched = reqs.filter(function (r) { return /\/menu$/.test(r); }).length;
	var soldOutDisabled = await ssr.$eval('.db-card[data-item-id="104"] .db-add', function (n) { return n.disabled; });
	record('k1: SSR menu is not re-fetched and not rebuilt',
		menuFetched === 0 && soldOutDisabled === true,
		'/menu requests=' + menuFetched + ' soldOutDisabled=' + soldOutDisabled + ' | requests: ' + reqs.join(', '));

	await ssr.click('.db-card[data-item-id="101"] .db-add');
	await ssr.waitForSelector('.db-cart-line', { timeout: 5000 });
	await sleep(500);
	var ssrState = await ssr.evaluate(function () {
		var badge = document.querySelector('.db-cart-badge');
		return {
			lines: document.querySelectorAll('.db-cart-line').length,
			badgeHidden: badge.hidden,
			count: badge.querySelector('.db-cart-badge-count').textContent,
			total: badge.querySelector('.db-cart-badge-total').textContent,
			cards: document.querySelectorAll('.db-card').length
		};
	});
	record('k2: SSR add binds, badge unhides, DOM not rebuilt',
		ssrState.lines === 1 && ssrState.badgeHidden === false && ssrState.count === '1' && ssrState.cards === 4,
		JSON.stringify(ssrState));
	await ssr.screenshot({ path: path.join(DIR, 'shot-ssr.png'), fullPage: false });

	/* (e) console errors ---------------------------------------------- */
	var errors = consoleLog.filter(function (l) {
		return l.indexOf('[error]') === 0 || l.indexOf('pageerror') !== -1 ||
			l.indexOf('error]') !== -1;
	});
	record('e: no console errors', errors.length === 0, errors.length ? errors.join(' || ') : 'clean');

	console.log('\n--- VERBATIM CONSOLE OUTPUT (' + consoleLog.length + ' lines) ---');
	if (!consoleLog.length) { console.log('(empty)'); }
	consoleLog.forEach(function (l) { console.log(l); });

	console.log('\n--- SUMMARY ---');
	var failed = results.filter(function (r) { return !r.pass; });
	console.log(results.length - failed.length + '/' + results.length + ' passed');

	await browser.close();
	process.exit(failed.length ? 1 : 0);
}()).catch(function (err) {
	console.error('HARNESS CRASHED: ' + err.stack);
	console.log('--- console so far ---');
	consoleLog.forEach(function (l) { console.log(l); });
	process.exit(2);
});
