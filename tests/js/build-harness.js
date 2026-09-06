/*
 * Builds two self-contained pages that inline the real storefront CSS + JS
 * and stub window.fetch with an in-memory cart that speaks the doughboss/v1
 * REST contract:
 *
 *   .build/harness.html      — menu fetched from /menu (JS-rendered path)
 *   .build/harness-ssr.html  — menu server-rendered, mirroring the shortcode
 *
 * Run: node tests/js/build-harness.js && node tests/js/run-harness.js
 */
var fs = require('fs');
var path = require('path');

var ROOT = path.resolve(__dirname, '..', '..', 'public');
var OUT_DIR = path.join(__dirname, '.build');

var css = fs.readFileSync(path.join(ROOT, 'css/doughboss.css'), 'utf8');
var js = fs.readFileSync(path.join(ROOT, 'js/doughboss.js'), 'utf8');

var stub = `
(function () {
	var params = new URLSearchParams(location.search);
	var CLOSED = params.get('closed') === '1';

	var CONFIG = {
		currency_symbol: '$',
		currency_code: 'AUD',
		tax_rate: 10,
		tax_label: 'GST',
		tax_inclusive: true,
		delivery_fee: 5,
		min_order: 0,
		enable_pickup: true,
		enable_delivery: true,
		ordering_open: !CLOSED,
		sizes: [
			{ slug: 'small', label: 'Small (10")', price: 12 },
			{ slug: 'medium', label: 'Medium (13")', price: 16 },
			{ slug: 'large', label: 'Large (16")', price: 20 }
		],
		toppings: [
			{ slug: 'pepperoni', label: 'Pepperoni', price: 3 },
			{ slug: 'mushrooms', label: 'Mushrooms', price: 2.5 },
			{ slug: 'olives', label: 'Olives', price: 2 },
			{ slug: 'extra-cheese', label: 'Extra cheese', price: 3.5 },
			{ slug: 'basil', label: 'Fresh basil', price: 1.5 }
		],
		pay_note: 'No payment now — pay when you collect.',
		store_phone: '0400 000 000'
	};

	window.DoughBossData = {
		restUrl: '/wp-json/doughboss/v1',
		nonce: 'test-nonce-1',
		currency: '$',
		i18n: {},
		config: CONFIG,
		storePhone: '0400 000 000',
		headingLevel: 2,
		menuUrl: '/order-online/',
		trackingUrl: '/track-order/'
	};

	var MENU = [
		{ id: 101, name: 'Margherita', description: 'San Marzano, fior di latte, basil.', price: 18, type: 'menu', image: '', srcset: '', image_width: 800, image_height: 600, category: 'Pizzas', available: true },
		{ id: 102, name: 'Diavola', description: 'Spicy salami, chilli, mozzarella.', price: 22, type: 'menu', image: '', srcset: '', image_width: 800, image_height: 600, category: 'Pizzas', available: true },
		{ id: 103, name: 'Garlic bread', description: 'Wood-fired, garlic butter.', price: 8, type: 'menu', image: '', srcset: '', image_width: 800, image_height: 600, category: 'Sides', available: true },
		{ id: 104, name: 'Nonna\\'s cannoli', description: 'Sold out today.', price: 6, type: 'menu', image: '', srcset: '', image_width: 800, image_height: 600, category: 'Sides', available: false }
	];

	var ITEMS = [];
	var keySeq = 0;

	function round(n) { return Math.round(n * 100) / 100; }

	function totalsFor(orderType) {
		var subtotal = 0;
		var count = 0;
		ITEMS.forEach(function (l) { subtotal += l.line_total; count += l.quantity; });
		subtotal = round(subtotal);
		var deliveryFee = (orderType === 'delivery' && ITEMS.length) ? CONFIG.delivery_fee : 0;
		var tax = round(subtotal * CONFIG.tax_rate / (100 + CONFIG.tax_rate));
		var total = round(subtotal + deliveryFee);
		return {
			subtotal: subtotal,
			tax: tax,
			delivery_fee: deliveryFee,
			total: total,
			item_count: count,
			tax_inclusive: true,
			tax_label: CONFIG.tax_label,
			tax_rate: CONFIG.tax_rate,
			min_order: CONFIG.min_order,
			min_order_met: CONFIG.min_order <= 0 || subtotal >= CONFIG.min_order
		};
	}

	function cartObject(orderType) {
		return { items: ITEMS.map(function (l) { return l; }), totals: totalsFor(orderType) };
	}

	function json(body, status) {
		return new Response(JSON.stringify(body), {
			status: status || 200,
			headers: { 'Content-Type': 'application/json' }
		});
	}

	function addLine(body) {
		var line;
		if (body.type === 'custom') {
			var size = CONFIG.sizes.filter(function (s) { return s.slug === body.size; })[0] || CONFIG.sizes[0];
			var tops = (body.toppings || []).map(function (slug) {
				return CONFIG.toppings.filter(function (tp) { return tp.slug === slug; })[0];
			}).filter(Boolean);
			var unit = size.price + tops.reduce(function (a, tp) { return a + tp.price; }, 0);
			keySeq++;
			line = {
				key: 'c' + keySeq,
				type: 'custom',
				item_id: 0,
				name: 'Custom Pizza',
				size: size.label,
				size_slug: size.slug,
				toppings: tops,
				unit_price: round(unit),
				quantity: body.quantity || 1,
				line_total: round(unit * (body.quantity || 1)),
				available: true
			};
			ITEMS.push(line);
			return line;
		}

		var item = MENU.filter(function (m) { return m.id === Number(body.item_id); })[0];
		if (!item) { return null; }
		var existing = ITEMS.filter(function (l) { return l.type === 'menu' && l.item_id === item.id; })[0];
		if (existing) {
			existing.quantity += (body.quantity || 1);
			existing.line_total = round(existing.unit_price * existing.quantity);
			return existing;
		}
		keySeq++;
		line = {
			key: 'm' + keySeq,
			type: 'menu',
			item_id: item.id,
			name: item.name,
			size: '',
			toppings: [],
			unit_price: item.price,
			quantity: body.quantity || 1,
			line_total: round(item.price * (body.quantity || 1)),
			available: true
		};
		ITEMS.push(line);
		return line;
	}

	window.__dbRequests = [];

	window.fetch = function (url, opts) {
		opts = opts || {};
		var u = String(url);
		var qs = u.indexOf('?') === -1 ? '' : u.slice(u.indexOf('?') + 1);
		var pathOnly = u.split('?')[0].replace('/wp-json/doughboss/v1', '');
		var body = opts.body ? JSON.parse(opts.body) : {};
		var orderType = /order_type=delivery/.test(qs) ? 'delivery' : 'pickup';
		window.__dbRequests.push((opts.method || 'GET') + ' ' + u);

		return new Promise(function (resolve) {
			setTimeout(function () {
				if (pathOnly === '/config') { return resolve(json(CONFIG)); }
				if (pathOnly === '/nonce') { return resolve(json({ nonce: 'test-nonce-2' })); }
				if (pathOnly === '/menu') { return resolve(json(MENU)); }
				if (pathOnly === '/cart') { return resolve(json(cartObject(orderType))); }

				if (pathOnly === '/cart/add') {
					if (!CONFIG.ordering_open) {
						return resolve(json({ code: 'doughboss_closed', message: 'Online ordering is currently closed.', data: { status: 403 } }, 403));
					}
					var added = addLine(body);
					if (!added) { return resolve(json({ code: 'doughboss_not_found', message: 'Item not found.', data: { status: 404 } }, 404)); }
					var out = cartObject(body.order_type || 'pickup');
					out.added = added;
					return resolve(json(out));
				}

				if (pathOnly === '/cart/update') {
					ITEMS.forEach(function (l) {
						if (l.key === body.key) {
							l.quantity = body.quantity;
							l.line_total = round(l.unit_price * l.quantity);
						}
					});
					if (body.quantity <= 0) {
						ITEMS = ITEMS.filter(function (l) { return l.key !== body.key; });
					}
					return resolve(json(cartObject(body.order_type || 'pickup')));
				}

				if (pathOnly === '/cart/remove') {
					ITEMS = ITEMS.filter(function (l) { return l.key !== body.key; });
					return resolve(json(cartObject(body.order_type || 'pickup')));
				}

				if (pathOnly === '/cart/clear') {
					ITEMS = [];
					return resolve(json(cartObject('pickup')));
				}

				if (pathOnly === '/checkout') {
					if (!CONFIG.ordering_open) {
						return resolve(json({ code: 'doughboss_closed', message: 'Online ordering is currently closed.', data: { status: 403 } }, 403));
					}
					if (!ITEMS.length) {
						return resolve(json({ code: 'doughboss_cart_expired', message: 'Your cart is empty.', data: { status: 400 } }, 400));
					}
					var t = totalsFor(body.order_type);
					ITEMS = [];
					return resolve(json({
						success: true,
						order_number: 'DB-260906-A7K2',
						total: t.total,
						message: 'Thanks! Your order has been received.',
						email_sent: true,
						tracking_url: '/track-order/?number=DB-260906-A7K2'
					}));
				}

				if (pathOnly === '/order/track') {
					return resolve(json({
						order_number: body.number,
						status: 'preparing',
						status_label: 'In the oven',
						order_type: 'pickup',
						total: 26,
						currency: 'AUD',
						created_at: '2026-09-06T18:00:00+10:00',
						items: [{ name: 'Margherita', quantity: 1, size: '', toppings: [], line_total: 18 }]
					}));
				}

				resolve(json({ code: 'rest_no_route', message: 'No route.', data: { status: 404 } }, 404));
			}, 10);
		});
	};
}());
`;

/* Mirrors DoughBoss_Shortcodes::menu() markup exactly. */
function ssrCard(id, title, desc, price, available) {
	return '<article class="db-card' + (available ? '' : ' db-card--unavailable') + '" data-item-id="' + id +
		'" data-available="' + (available ? '1' : '0') + '">' +
		'<div class="db-card-media"><div class="db-card-img db-card-img--placeholder" aria-hidden="true"></div>' +
		(available ? '' : '<span class="db-badge db-badge--soldout">Sold out</span>') + '</div>' +
		'<div class="db-card-body"><h3 class="db-card-title">' + title + '</h3>' +
		'<p class="db-card-desc">' + desc + '</p>' +
		(available ? '' : '<p class="db-card-note">This item is unavailable right now.</p>') +
		'<div class="db-card-foot"><span class="db-price">$' + price.toFixed(2) + '</span>' +
		'<button type="button" class="db-btn db-add" data-item-id="' + id + '"' +
		(available ? '' : ' disabled aria-disabled="true"') + '>Add to cart</button></div></div></article>';
}

var ssrMenu = '<div class="db-app db-menu" data-doughboss-menu data-doughboss-ssr="1" data-heading-level="2">' +
	'<h2 class="db-category">Pizzas</h2><div class="db-grid">' +
	ssrCard(101, 'Margherita', 'San Marzano, fior di latte, basil.', 18, true) +
	ssrCard(102, 'Diavola', 'Spicy salami, chilli, mozzarella.', 22, true) +
	'</div><h2 class="db-category">Sides</h2><div class="db-grid">' +
	ssrCard(103, 'Garlic bread', 'Wood-fired, garlic butter.', 8, true) +
	ssrCard(104, 'Nonna&#039;s cannoli', 'Sold out today.', 6, false) +
	'</div></div>';

var fetchedMenu = '<div class="db-app" data-doughboss-menu><div class="db-loading">Loading…</div></div>';

/* Mirrors DoughBoss_Shortcodes::order_tracking() markup. */
var trackingForm = '<div class="db-app db-tracking" data-doughboss-tracking data-heading-level="2">' +
	'<form class="db-track-form"><h2 class="db-track-heading">Track your order</h2>' +
	'<div class="db-field"><label class="db-label" for="db-track-number">Order number</label>' +
	'<input class="db-input" type="text" id="db-track-number" name="number" required autocomplete="off" autocapitalize="characters" enterkeyhint="next" /></div>' +
	'<div class="db-field"><label class="db-label" for="db-track-email">Email</label>' +
	'<input class="db-input" type="email" id="db-track-email" name="email" required autocomplete="email" inputmode="email" enterkeyhint="go" /></div>' +
	'<button type="submit" class="db-btn db-btn--lg">Find my order</button></form>' +
	'<div class="db-track-result" aria-live="polite"></div></div>';

function page(menuMarkup) {
	return '<!doctype html>\n<html lang="en">\n<head>\n<meta charset="utf-8">\n' +
	'<meta name="viewport" content="width=device-width, initial-scale=1">\n' +
	'<title>DoughBoss v2.5 harness</title>\n' +
	'<style>\nbody{margin:0;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#efe9df;padding:24px}\n' +
	'.harness-section{max-width:960px;margin:0 auto 32px}\n' +
	'.harness-section > h1{font-size:20px}\n' +
	'</style>\n' +
	'<style>\n' + css + '\n</style>\n' +
	'</head>\n<body>\n' +
	'<div class="harness-section"><h1>Cart badge</h1>' +
	'<a class="db-app db-cart-badge" data-doughboss-cart-badge href="/cart/" hidden>' +
	'<span class="db-cart-badge-icon" aria-hidden="true">\u{1F6D2}</span>' +
	'<span class="db-cart-badge-count"></span><span class="db-cart-badge-total"></span>' +
	'<span class="db-cart-badge-label">View cart</span></a></div>\n' +
	'<div class="harness-section"><h1>Menu</h1>' + menuMarkup + '</div>\n' +
	'<div class="harness-section"><h1>Builder</h1><div class="db-app" data-doughboss-builder>' +
	'<div class="db-loading">Loading…</div></div></div>\n' +
	'<div class="harness-section"><h1>Cart</h1><div class="db-app" data-doughboss-cart>' +
	'<div class="db-loading">Loading…</div></div></div>\n' +
	'<div class="harness-section"><h1>Tracking</h1>' + trackingForm + '</div>\n' +
	'<script>\n' + stub + '\n</' + 'script>\n' +
	'<script>\n' + js + '\n</' + 'script>\n' +
	'</body>\n</html>\n';
}

if (!fs.existsSync(OUT_DIR)) { fs.mkdirSync(OUT_DIR); }
fs.writeFileSync(path.join(OUT_DIR, 'harness.html'), page(fetchedMenu));
fs.writeFileSync(path.join(OUT_DIR, 'harness-ssr.html'), page(ssrMenu));
console.log('wrote ' + path.relative(process.cwd(), OUT_DIR) + '/harness.html and harness-ssr.html');
