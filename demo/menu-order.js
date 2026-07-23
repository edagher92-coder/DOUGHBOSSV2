/* Dough Boss — browse-and-order: per-item add (with Domino's-style options sheet),
   floating cart button, slide-in cart + checkout. Lemon & chilli is asked per item
   (inside the options sheet on pizzas and meat manoush), not once per order. */
(function () {
	'use strict';
	var menuView = document.getElementById('view-menu');
	if (!menuView) { return; }
	var CFG = window.DBDemo.config;
	var DEFAULT_LOCATION = window.DBDemo.getLocation(CFG.defaultLocationId);
	document.documentElement.lang = CFG.region.language;
	document.documentElement.dir = CFG.region.direction;
	var cart = {};       // lineKey -> { key, name, catId, basePrice, price, opts, summary, qty, seq }
	var controls = {};   // item name -> { el, name, price, catId, groups, lastSel }
	var seq = 0;         // insertion counter so the tile "−" targets the newest line
	var drawerOpen = false, checkoutMode = false, lastFocus = null;
	var sheetOpen = false, sheetName = null, sheetLastFocus = null;
	var voucher = null;      // { code, amount } once a valid code is applied
	var pendingOrder = null; // { name, phone } held during the simulated card-payment step
	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function money(n) { return window.DBDemo.formatMoney(n); }
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function count() { var c = 0; for (var k in cart) { c += cart[k].qty; } return c; }
	function total() { var t = 0; for (var k in cart) { t += cart[k].price * cart[k].qty; } return t; }
	function discount() { return voucher ? Math.min(voucher.amount, total()) : 0; }
	function netTotal() { return Math.max(0, total() - discount()); }
	function onMenuView() { var k = (location.hash || '#about').replace('#', ''); return k === 'menu' || k === 'order'; }
	function itemQty(name) { var q = 0; for (var k in cart) { if (cart[k].name === name) { q += cart[k].qty; } } return q; }
	function newestLine(name) { var best = null; for (var k in cart) { if (cart[k].name === name && (!best || cart[k].seq > best.seq)) { best = cart[k]; } } return best; }

	/* --- per-category item options (Domino's-style customization) ---
	   Choices carry a price delta; `def` marks the pre-selected default; `sum` is the
	   short label used in cart summaries when it differs from the sheet label.
	   Pizza base surcharges follow the owner's confirmed pricing (+$4.00 either base). */
	var OPT_STYLE = { id: 'style', label: 'Style', type: 'radio', choices: [
		{ label: 'Flat', delta: 0, def: true },
		{ label: 'Folded', delta: 0 }
	] };
	var OPT_ZAATAR_STYLE = { id: 'style', label: 'Style', type: 'radio', choices: [
		{ label: 'Flat', delta: 0.5 },
		{ label: 'Folded', delta: 0, def: true }
	] };
	var OPT_ZAATAR_MIX = { id: 'mix', label: 'Zaatar mix', type: 'radio', choices: [
		{ label: 'Classic zaatar', delta: 0, def: true },
		{ label: 'Mixed zaatar & cheese', sum: 'Zaatar & cheese mixed', delta: 0.5 }
	] };
	/* Crust (ex "Base") — Domino's/Pizza Hut say "crust". Prices per the owner's
	   in-store POS (V23 photos, confirmed): Crispy & Classic free, Wholemeal +$2.50,
	   Gluten-free +$3.50. */
	var OPT_PIZZA_BASE = { id: 'base', label: 'Crust', type: 'radio', choices: [
		{ label: 'Crispy', delta: 0, def: true },
		{ label: 'Classic', delta: 0 },
		{ label: 'Wholemeal', delta: 2.5 },
		{ label: 'Gluten-free', delta: 3.5 }
	] };
	var OPT_WRAP_EXTRAS = { id: 'extras', label: 'Extras', type: 'check', choices: [
		{ label: 'Add labneh (Mediterranean yoghurt)', sum: 'Labneh', delta: 2.5 },
		{ label: 'Add cheese', sum: 'Cheese', delta: 2.5 }
	] };
	/* Pies do not use pizza sauce or extra-topping controls. Sesame is optional
	   and is removed by default. */
	var OPT_SESAME = { id: 'sesame', label: 'Sesame seeds', type: 'radio', choices: [
		{ label: 'No sesame seeds', sum: 'No sesame', delta: 0, def: true },
		{ label: 'With sesame seeds', delta: 0 }
	] };
	/* Pizza builder groups from the owner's in-store POS screens (V23 photos).
	   Base Sauce is free (pick one); Sauce on Top is +$1.50 each; extra toppings
	   are tiered $1/$2/$3 exactly as the till shows. Crust pricing is intentionally
	   NOT changed here — the POS shows Wholemeal $2.50 / GF $3.50, which conflicts
	   with the previously-confirmed flat $4; left for owner confirmation. */
	var OPT_BASE_SAUCE = { id: 'basesauce', label: 'Base Sauce', type: 'radio', choices: [
		{ label: 'Tomato (Pizza Sauce)', sum: 'Tomato base', delta: 0, def: true },
		{ label: 'Garlic', sum: 'Garlic base', delta: 0 },
		{ label: 'BBQ', sum: 'BBQ base', delta: 0 },
		{ label: 'No Sauce', sum: 'No base sauce', delta: 0 }
	] };
	var OPT_SAUCE_TOP = { id: 'saucetop', label: 'Sauce on Top', type: 'check', choices: [
		{ label: 'Tomato Ketchup', delta: 1.5 },
		{ label: 'Smokey BBQ', delta: 1.5 },
		{ label: 'Mayo Swirl', delta: 1.5 },
		{ label: 'Peri Peri Sauce', delta: 1.5 },
		{ label: 'Spicy Siracha', delta: 1.5 }
	] };
	var OPT_ADDONS = { id: 'addons', label: 'Add Extra Toppings', type: 'check', choices: [
		{ label: 'Olives', delta: 1 },
		{ label: 'Spinach', delta: 2 },
		{ label: 'Garlic sauce', delta: 2 },
		{ label: 'Onion', delta: 2 },
		{ label: 'Mushroom', delta: 2 },
		{ label: 'Capsicum', delta: 2 },
		{ label: 'Tomato', delta: 2 },
		{ label: 'Sujuk', delta: 3 },
		{ label: 'Chicken', delta: 3 },
		{ label: 'Meat (lahme)', delta: 3 },
		{ label: 'Cheese', delta: 3 },
		{ label: 'Mozzarella', delta: 3 },
		{ label: 'Halloumi', delta: 3 },
		{ label: 'Pepperoni', delta: 3 }
	] };
	var OPT_REMOVE_TOPPINGS = { id: 'remove', label: 'Remove ingredients', type: 'check', choices: [
		{ label: 'No cheese', delta: 0 },
		{ label: 'No tomato', delta: 0 },
		{ label: 'No olives', delta: 0 },
		{ label: 'No onion', delta: 0 },
		{ label: 'No mushroom', delta: 0 },
		{ label: 'No capsicum', delta: 0 },
		{ label: 'No cucumber', delta: 0 },
		{ label: 'No lettuce', delta: 0 },
		{ label: 'No pickles', delta: 0 },
		{ label: 'No meat', delta: 0 },
		{ label: 'No sujuk', delta: 0 }
	] };
	/* Lemon & chilli (free) — now SEPARATE so a customer can pick lemon, chilli,
	   both or neither on any order. Both default to off; a cart line shows "Lemon"
	   and/or "Chilli" only when ticked — per-item kitchen accuracy. Offered on all
	   savoury orders (manoush, pizza, pies, wraps). */
	var OPT_LEMON = { id: 'lc', label: 'Lemon & chilli — free', type: 'check', choices: [
		{ label: 'Lemon', sum: 'Lemon', delta: 0 },
		{ label: 'Chilli', sum: 'Chilli', delta: 0 }
	] };
	function optionGroups(catId, name) {
		if (catId === 'cat-pizza') {
			var pizzaGroups = [OPT_PIZZA_BASE, OPT_BASE_SAUCE, OPT_SAUCE_TOP, OPT_ADDONS, OPT_REMOVE_TOPPINGS, OPT_LEMON];
			if (name === 'Zaatar Veggie Pizza' || name === 'Labneh Veggie Pizza') { pizzaGroups.splice(4, 0, OPT_WRAP_EXTRAS); }
			return pizzaGroups;
		}
		/* Pies (and minis) take the same add-ons as pizza, plus a free "no sesame"
		   removable. Minis aren't a separate category in the demo yet — flagged. */
		if (catId === 'cat-pies') { return [OPT_SAUCE_TOP, OPT_SESAME, OPT_LEMON]; }
		if (catId === 'cat-manoush') {
			if (name === 'Zaatar') { return [OPT_ZAATAR_STYLE, OPT_ZAATAR_MIX, OPT_PIZZA_BASE, OPT_REMOVE_TOPPINGS, OPT_LEMON]; }
			if (name === 'Zaatar & Cheese') { return [OPT_ZAATAR_STYLE, OPT_PIZZA_BASE, OPT_REMOVE_TOPPINGS, OPT_LEMON]; }
			return [OPT_STYLE, OPT_PIZZA_BASE, OPT_REMOVE_TOPPINGS, OPT_LEMON];
		}
		if (catId === 'cat-wraps') { return (name === 'Zaatar & Veggie' || name === 'Labneh Veggie Wrap') ? [OPT_WRAP_EXTRAS, OPT_REMOVE_TOPPINGS, OPT_LEMON] : [OPT_REMOVE_TOPPINGS, OPT_LEMON]; }
		return null;
	}

	/* --- enhance each menu item with an Add / stepper control --- */
	Array.prototype.forEach.call(menuView.querySelectorAll('.mn-item'), function (el) {
		var nameEl = el.querySelector('.mn-it-n');
		var priceEl = el.querySelector('.mn-it-p');
		if (!nameEl || !priceEl) { return; }
		var clone = nameEl.cloneNode(true);
		Array.prototype.forEach.call(clone.querySelectorAll('.mn-tag'), function (t) { t.parentNode.removeChild(t); });
		var name = clone.textContent.replace(/\s+/g, ' ').trim();
		var price = parseFloat(priceEl.getAttribute('data-price') || priceEl.textContent.replace(/[^0-9.]/g, '')) || 0;
		priceEl.setAttribute('data-price', String(price));
		priceEl.textContent = money(price);
		var catEl = el.closest('.mn-cat');
		var catId = catEl ? catEl.id : '';
		var imgEl = el.querySelector('.mn-it-img');
		var act = document.createElement('div');
		act.className = 'mn-it-act';
		(el.querySelector('.mn-it-body') || el).appendChild(act);
		controls[name] = { el: act, name: name, price: price, catId: catId, groups: optionGroups(catId, name), lastSel: null,
			img: imgEl ? imgEl.getAttribute('src') : '', imgAlt: imgEl ? (imgEl.getAttribute('alt') || name) : name };
		paintItem(name);
	});

	function paintItem(name) {
		var c = controls[name]; if (!c) { return; }
		var qty = itemQty(name);
		if (qty > 0) {
			c.el.innerHTML = '<div class="mn-step"><button type="button" data-dec="' + esc(name) + '" aria-label="Remove one ' + esc(name) + '">&minus;</button><b>' + qty + '</b><button type="button" data-inc="' + esc(name) + '" aria-label="Add one ' + esc(name) + '">+</button></div>';
		} else {
			c.el.innerHTML = '<button type="button" class="mn-add" data-add="' + esc(name) + '" aria-label="Add ' + esc(name) + ' to your order">Add <span aria-hidden="true">+</span></button>';
		}
	}

	/* --- cart lines (keyed by name + option summary, so configs stay separate) --- */
	function addLine(name, opts) {
		var c = controls[name]; if (!c) { return; }
		opts = opts || [];
		var summary = opts.map(function (o) { return o.label; }).join(' · ');
		var key = name + '|' + summary;
		if (!cart[key]) {
			var unit = c.price;
			opts.forEach(function (o) { unit += o.delta; });
			cart[key] = { key: key, name: name, catId: c.catId, basePrice: c.price, price: unit, opts: opts, summary: summary, qty: 0, seq: 0 };
		}
		bumpLine(key, 1);
		flashAdded(name);
	}
	function bumpLine(key, delta) {
		var it = cart[key]; if (!it) { return; }
		it.qty += delta;
		if (it.qty <= 0) { delete cart[key]; } else if (delta > 0) { it.seq = ++seq; }
		paintItem(it.name);
		renderFab(delta > 0);
		if (drawerOpen && !checkoutMode) { renderDrawer(); }
	}
	/* Tile "+"/"Add": items with options open the sheet (pre-filled with the last chosen
	   config, so re-adding the same thing is one confirm tap); plain items add instantly.
	   Tile "−" removes from the most recently added line for that item. */
	function tileAdd(name) {
		var c = controls[name]; if (!c) { return; }
		if (c.groups) { openSheet(name); return; }
		addLine(name, []);
		// Repainting the tile destroyed the focused button — keep keyboard focus on the control.
		var b = c.el.querySelector('[data-inc]'); if (b) { b.focus(); }
	}
	function tileDec(name) {
		var line = newestLine(name);
		if (line) { bumpLine(line.key, -1); }
	}

	/* --- options sheet (small modal; bottom sheet on narrow screens) --- */
	var optOverlay = document.createElement('div');
	optOverlay.className = 'opt-overlay';
	document.body.appendChild(optOverlay);

	var sheet = document.createElement('div');
	sheet.className = 'opt-sheet';
	sheet.setAttribute('role', 'dialog');
	sheet.setAttribute('aria-modal', 'true');
	document.body.appendChild(sheet);

	function openSheet(name) {
		var c = controls[name]; if (!c || !c.groups) { return; }
		sheetName = name; sheetOpen = true;
		sheetLastFocus = document.activeElement;
		sheet.setAttribute('aria-label', 'Choose options for ' + name);
		var pre = c.lastSel || {};
		var html = '<div class="opt-head"><h3>' + esc(name) + '</h3><button type="button" class="opt-close" aria-label="Close options">&times;</button></div>' +
			(c.img ? '<div class="opt-hero"><img src="' + esc(c.img) + '" alt="' + esc(c.imgAlt || name) + '" loading="lazy" decoding="async"></div>' : '') +
			'<form class="opt-form" novalidate>';
		c.groups.forEach(function (g) {
			html += '<fieldset class="opt-g"><legend>' + esc(g.label) + (g.type === 'check' ? ' <span class="opt-optional">(optional)</span>' : '') + '</legend>';
			g.choices.forEach(function (ch, ci) {
				var on = g.type === 'radio'
					? (pre[g.id] != null ? pre[g.id] === ci : !!ch.def)
					: (pre[g.id] ? pre[g.id].indexOf(ci) !== -1 : false);
				html += '<label class="opt-c"><input type="' + (g.type === 'radio' ? 'radio' : 'checkbox') + '" name="' + esc(g.id) + '" value="' + ci + '"' + (on ? ' checked' : '') + '><span class="opt-c-l">' + esc(ch.label) + '</span><span class="opt-c-p">' + (ch.delta > 0 ? '+' + money(ch.delta) : '') + '</span></label>';
			});
			html += '</fieldset>';
		});
		html += '<button type="submit" class="vb-btn vb-btn-ember opt-add"></button></form>';
		sheet.innerHTML = html;
		optOverlay.classList.add('is-open');
		sheet.classList.add('is-open');
		paintSheetPrice();
		var first = sheet.querySelector('input:checked') || sheet.querySelector('input') || sheet.querySelector('.opt-close');
		if (first) { first.focus(); }
	}
	function closeSheet() {
		if (!sheetOpen) { return; }
		sheetOpen = false; sheetName = null;
		optOverlay.classList.remove('is-open'); sheet.classList.remove('is-open');
		if (sheetLastFocus && sheetLastFocus.focus && sheetLastFocus.offsetParent !== null) { sheetLastFocus.focus(); }
	}
	function readSheet() {
		var c = controls[sheetName]; if (!c || !c.groups) { return null; }
		var opts = [], sel = {}, unit = c.price;
		c.groups.forEach(function (g) {
			var inputs = sheet.querySelectorAll('input[name="' + g.id + '"]');
			if (g.type === 'radio') {
				Array.prototype.forEach.call(inputs, function (inp) {
					if (!inp.checked) { return; }
					var ch = g.choices[parseInt(inp.value, 10)];
					sel[g.id] = parseInt(inp.value, 10);
					unit += ch.delta;
					if (!ch.def) { opts.push({ label: ch.sum || ch.label, delta: ch.delta }); }
				});
			} else {
				sel[g.id] = [];
				Array.prototype.forEach.call(inputs, function (inp) {
					if (!inp.checked) { return; }
					var ch = g.choices[parseInt(inp.value, 10)];
					sel[g.id].push(parseInt(inp.value, 10));
					unit += ch.delta;
					opts.push({ label: ch.sum || ch.label, delta: ch.delta });
				});
			}
		});
		return { opts: opts, sel: sel, unit: unit };
	}
	function paintSheetPrice() {
		var r = readSheet();
		var btn = sheet.querySelector('.opt-add');
		if (r && btn) { btn.innerHTML = 'Add to order &mdash; ' + money(r.unit); }
	}

	optOverlay.addEventListener('click', closeSheet);
	sheet.addEventListener('click', function (e) { if (e.target.closest('.opt-close')) { closeSheet(); } });
	sheet.addEventListener('change', paintSheetPrice);
	sheet.addEventListener('submit', function (e) {
		e.preventDefault();
		var name = sheetName;
		var c = controls[name], r = readSheet();
		closeSheet();
		if (!c || !r) { return; }
		c.lastSel = r.sel;
		addLine(name, r.opts);
		var btn = c.el.querySelector('button'); if (btn) { btn.focus(); }
	});

	/* --- floating cart button --- */
	var fab = document.createElement('button');
	fab.type = 'button';
	fab.className = 'cart-fab';
	fab.setAttribute('aria-haspopup', 'dialog');
	fab.setAttribute('aria-expanded', 'false');
	fab.setAttribute('aria-hidden', 'true');
	document.body.appendChild(fab);

	function bag() { return '<svg class="cf-bag" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 8h12l-1 12H7L6 8z"/><path d="M9 8a3 3 0 016 0"/></svg>'; }

	/* Tiny "added ✓" toast — the FAB can be off-screen when adding from the top of a
	   long menu, so acknowledge every add near the thumb. role=status announces it too. */
	var toast = document.createElement('div');
	toast.className = 'cart-toast';
	toast.setAttribute('role', 'status');
	document.body.appendChild(toast);
	var toastT = null;
	function flashAdded(name) {
		toast.innerHTML = '<span aria-hidden="true">&#10003;</span> ' + esc(name) + ' added';
		toast.classList.add('is-on');
		if (toastT) { clearTimeout(toastT); }
		toastT = setTimeout(function () { toast.classList.remove('is-on'); }, 1500);
	}

	function renderFab(bump) {
		var c = count();
		document.body.classList.toggle('cart-on', c > 0 && onMenuView());
		if (c <= 0 || !onMenuView()) { fab.classList.remove('is-shown'); fab.setAttribute('aria-hidden', 'true'); return; }
		fab.classList.add('is-shown');
		fab.setAttribute('aria-hidden', 'false');
		fab.innerHTML = '<span class="cf-left">' + bag() + 'View order <span class="cf-ct">&middot; ' + c + (c === 1 ? ' item' : ' items') + '</span></span>' +
			'<span class="cf-right"><b class="cf-tot">' + money(total()) + '</b><span class="cf-arrow" aria-hidden="true">&rarr;</span></span>';
		fab.setAttribute('aria-label', 'View your order: ' + c + (c === 1 ? ' item' : ' items') + ', total ' + money(total()));
		if (bump && !reduce) { fab.classList.remove('cart-bump'); void fab.offsetWidth; fab.classList.add('cart-bump'); }
	}

	/* --- slide-in drawer --- */
	var overlay = document.createElement('div');
	overlay.className = 'cart-overlay';
	document.body.appendChild(overlay);

	var drawer = document.createElement('div');
	drawer.className = 'cart-drawer';
	drawer.setAttribute('role', 'dialog');
	drawer.setAttribute('aria-modal', 'true');
	drawer.setAttribute('aria-label', 'Your order');
	document.body.appendChild(drawer);

	function openDrawer() {
		if (count() <= 0) { return; }
		lastFocus = document.activeElement;
		drawerOpen = true; checkoutMode = false;
		overlay.classList.add('is-open'); drawer.classList.add('is-open');
		fab.setAttribute('aria-expanded', 'true');
		renderDrawer();
		var x = drawer.querySelector('.cd-close'); if (x) { x.focus(); }
	}
	function closeDrawer() {
		drawerOpen = false; checkoutMode = false; pendingOrder = null;
		drawer.style.bottom = '';
		overlay.classList.remove('is-open'); drawer.classList.remove('is-open');
		fab.setAttribute('aria-expanded', 'false');
		if (lastFocus && lastFocus.focus && lastFocus.offsetParent !== null) { lastFocus.focus(); }
	}
	function focusables(root) {
		return Array.prototype.filter.call(
			root.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
			function (el) { return !el.disabled && el.offsetParent !== null; }
		);
	}

	function renderDrawer() {
		checkoutMode = false;
		drawer.setAttribute('aria-label', 'Your order');
		var lines = '';
		for (var k in cart) {
			var it = cart[k];
			lines += '<div class="cd-line"><span class="cd-n">' + esc(it.name) + (it.summary ? '<small class="cd-opts">' + esc(it.summary) + '</small>' : '') + '</span><span class="cd-qty"><button type="button" data-kdec="' + esc(k) + '" aria-label="Remove one ' + esc(it.name) + '">&minus;</button><b>' + it.qty + '</b><button type="button" data-kinc="' + esc(k) + '" aria-label="Add one ' + esc(it.name) + '">+</button></span><span class="cd-p">' + money(it.price * it.qty) + '</span></div>';
		}
		if (!lines) { lines = '<div class="cd-empty">' + bag() + '<p>Your order is empty &mdash; add a few manoush.</p></div>'; }
		/* One tasteful cross-sell (Pizza Hut pattern): offer a drink once, only when
		   the cart has food but no drink yet — never a cluttered grid of suggestions. */
		var hasDrink = false;
		for (var dk in cart) { if (cart[dk].catId === 'cat-drinks') { hasDrink = true; break; } }
		var dPrice = controls['Soft Drinks 600ml'] ? controls['Soft Drinks 600ml'].price : 5;
		var xsell = (!hasDrink && count())
			? '<div class="cd-xsell"><span class="cd-xsell-t">Fancy a drink with that?</span><button type="button" class="cd-xsell-add" data-xsell="Soft Drinks 600ml">Add a soft drink <b>+' + money(dPrice) + '</b></button></div>'
			: '';
		var defaultMethods = window.DBDemo.enabledFulfilments(DEFAULT_LOCATION.id).map(function (key) { return window.DBDemo.t('fulfilment.' + key).toLowerCase(); });
		drawer.innerHTML = '<div class="cd-head"><h3>Your order</h3><button type="button" class="cd-close" aria-label="Close order">&times;</button></div>' +
			'<div class="cd-body">' + lines + xsell + '</div>' +
			'<div class="cd-foot"><div class="cd-tot"><span>Total</span><strong>' + money(total()) + '</strong></div>' +
			'<button type="button" class="vb-btn vb-btn-ember cd-checkout"' + (count() ? '' : ' disabled') + '>Checkout</button>' +
			'<p class="cd-note">Demo &middot; ' + esc(defaultMethods.join(' / ')) + ' from ' + esc(DEFAULT_LOCATION.name) + ' by default &middot; no real payment.</p></div>';
	}

	function routeOptions() {
		var options = [];
		window.DBDemo.activeLocations().forEach(function (location) {
			window.DBDemo.enabledFulfilments(location.id).forEach(function (method) {
				options.push('<option value="' + esc(location.id + '|' + method) + '">' + esc(window.DBDemo.t('fulfilment.' + method) + ' · ' + location.name) + '</option>');
			});
		});
		return options.join('');
	}

	/* Compact read-only line list shown on the checkout step, so options like
	   lemon & chilli / base stay visible per item right up to payment. */
	function summaryLines() {
		var html = '';
		for (var k in cart) {
			var it = cart[k];
			html += '<div class="cd-sline"><span class="cd-n">' + it.qty + ' &times; ' + esc(it.name) + (it.summary ? '<small class="cd-opts">' + esc(it.summary) + '</small>' : '') + '</span><span class="cd-p">' + money(it.price * it.qty) + '</span></div>';
		}
		return html;
	}

	/* Revesby ordering hours (minutes from midnight). Mon–Wed & Sun 6:30am–2pm;
	   Thu/Fri/Sat 6:30am–8:30pm. NOTE: the Thu–Sat evening close (8:30pm) is an
	   assumption from the brief — CONFIRM with the owner. day: 0=Sun … 6=Sat. */
	var ORDER_HOURS = { 0: [390, 840], 1: [390, 840], 2: [390, 840], 3: [390, 840], 4: [390, 1230], 5: [390, 1230], 6: [390, 1230] };
	function storeStatus() {
		var now = new Date();
		var span = ORDER_HOURS[now.getDay()];
		var mins = now.getHours() * 60 + now.getMinutes();
		return { open: !!span && mins >= span[0] && mins < span[1] };
	}

	function renderCheckout() {
		if (!count()) { return; }
		checkoutMode = true;
		drawer.setAttribute('aria-label', 'Checkout');
		var vouchHtml = voucher
			? '<div class="cd-von"><span class="cd-vcode">&#127915; ' + esc(voucher.code) + ' applied</span><button type="button" class="cd-vremove">Remove</button></div>'
			: '<div class="cd-vrow"><input type="text" class="cd-vinput" placeholder="Voucher code (try a DOUGH-… code)" aria-label="Voucher code" autocapitalize="characters"><button type="button" class="cd-vapply">Apply</button></div>';
		var totsHtml = (voucher
			? '<div class="cd-tline"><span>Subtotal</span><span>' + money(total()) + '</span></div>' +
				'<div class="cd-tline cd-tdisc"><span>Voucher ' + esc(voucher.code) + '</span><span>&minus;' + money(discount()) + '</span></div>'
			: '') +
			'<div class="cd-tot"><span>Total</span><strong>' + money(netTotal()) + '</strong></div>';
		/* The form is a flex column: everything scrolls inside .cd-scroll while the
		   submit CTA stays pinned in .cd-cta — reachable on short/landscape screens
		   and above the on-screen keyboard (see the visualViewport handler below). */
		var open = storeStatus().open;
		var hoursHtml = open
			? '<div class="cd-hours cd-hours--open">Open now &middot; pickup from Revesby</div>'
			: '<div class="cd-hours cd-hours--shut">We&rsquo;re closed right now. Revesby ordering hours are <strong>6:30am&ndash;2pm</strong> (Thu&ndash;Sat to 8:30pm). Please order during opening hours.</div>';
		var anyDelivery = window.DBDemo.activeLocations().some(function (location) { return window.DBDemo.enabledFulfilments(location.id).indexOf('delivery') !== -1; });
		var paymentProvider = CFG.payments.enabled ? CFG.payments.selectedProvider : null;
		drawer.innerHTML = '<div class="cd-head"><h3>Checkout</h3><button type="button" class="cd-close" aria-label="Close order">&times;</button></div>' +
			'<form class="cd-form" novalidate>' +
			'<div class="cd-scroll">' +
			hoursHtml +
			'<div class="cd-summary">' + summaryLines() + '</div>' +
			'<div class="cd-vouch">' + vouchHtml + '<p class="cd-verr" role="alert"></p></div>' +
			'<label class="cd-f"><span>Name</span><input name="name" type="text" autocomplete="name" required></label>' +
			'<label class="cd-f"><span>Phone</span><input name="phone" type="tel" autocomplete="tel" required></label>' +
			'<label class="cd-f"><span>Location and fulfilment</span><select name="route" required>' + routeOptions() + '</select></label>' +
			(anyDelivery ? '<label class="cd-f"><span>Delivery address <small>(required for delivery)</small></span><input name="address" type="text" autocomplete="street-address"></label>' : '') +
			'<fieldset class="cd-f cd-pay">' +
			'<legend>Payment</legend>' +
			'<p class="cd-privacy cd-carddemo">Interactive demo only — use made-up test details, never a real card. ' + (paymentProvider ? esc(paymentProvider.toUpperCase()) + ' is selected by this profile.' : 'Payments are disabled; Tyro and Stripe remain available adapters.') + '</p>' +
			'</fieldset>' +
			'<p class="cd-privacy cd-modnote">Once your order is placed only small changes can be made &mdash; please check it over before you pay.</p>' +
			'<p class="cd-privacy">We use your name and phone only to process your order. See our <a href="privacy.html" target="_blank" rel="noopener">Privacy Policy</a>.</p>' +
			'<label class="cd-agree"><input type="checkbox" name="agree" class="cd-agree-cb"><span>I agree to the <a href="terms.html" target="_blank" rel="noopener">Terms &amp; Conditions</a>, and to Dough Boss contacting me about this order and occasional offers.</span></label>' +
			'</div>' +
			'<div class="cd-cta">' +
			'<div class="cd-tots">' + totsHtml + '</div>' +
			'<div class="cd-err" role="alert"></div>' +
			'<button type="submit" class="vb-btn vb-btn-ember">Place demo order</button>' +
			'<button type="button" class="vb-btn vb-btn-dark cd-back">Back to order</button>' +
			'</div>' +
			'</form>';
		var f = drawer.querySelector('input[name="name"]'); if (f) { f.focus(); }
	}

	function applyVoucher() {
		var input = drawer.querySelector('.cd-vinput');
		var verr = drawer.querySelector('.cd-verr');
		if (verr) { verr.textContent = ''; }
		var code = input ? input.value.trim().toUpperCase() : '';
		// Demo-only shape check (2-4 hyphen-separated groups) — accepts both the
		// short demo-style code (DOUGH-7K2D9Q) and real WordPress-issued codes.
		// This does NOT check a real database or
		// consume a real voucher; it just stops the demo rejecting real-shaped
		// codes on sight so it looks right when showing people the flow.
		if (!/^[A-Z0-9]{2,12}(-[A-Z0-9]{2,12}){1,3}$/.test(code)) {
			if (verr) { verr.textContent = 'Enter a valid voucher code, e.g. DOUGH-7K2D9Q.'; }
			if (input) { input.focus(); }
			return;
		}
		voucher = { code: code, amount: Number((CFG.promotions.studentVoucher || {}).amount || 0) };
		renderCheckout();
	}

	function placeOrder(form) {
		var fd = new FormData(form);
		var name = (fd.get('name') || '').toString().trim();
		var phone = (fd.get('phone') || '').toString().trim();
		var route = (fd.get('route') || '').toString().split('|');
		var locationId = route[0], fulfilment = route[1];
		var address = (fd.get('address') || '').toString().trim();
		var err = form.querySelector('.cd-err');
		if (!name || !phone) {
			err.textContent = 'Please add your name and phone.';
			var miss = form.querySelector(!name ? 'input[name="name"]' : 'input[name="phone"]');
			if (miss) { miss.focus(); }
			return;
		}
		if (!window.DBDemo.getLocation(locationId) || window.DBDemo.enabledFulfilments(locationId).indexOf(fulfilment) === -1) {
			err.textContent = 'Please choose an available location and fulfilment method.';
			return;
		}
		if (fulfilment === 'delivery' && !address) {
			err.textContent = 'Please add a delivery address.';
			return;
		}
		var agree = form.querySelector('input[name="agree"]');
		if (!agree || !agree.checked) {
			err.textContent = 'Please agree to the Terms & Conditions to place your order.';
			if (agree) { agree.focus(); }
			return;
		}
		if (!storeStatus().open) {
			err.textContent = 'Sorry, we’re closed right now — please order during opening hours (6:30am–2pm).';
			return;
		}
		pendingOrder = { name: name, phone: phone, locationId: locationId, fulfilment: fulfilment, address: address };
		renderCardSheet();
	}

	/* Simulated card-payment step — modeled on a hosted-fields checkout but
	   unmistakably TEST MODE: any input is accepted, nothing is stored or sent. */
	function renderCardSheet() {
		if (!count() || !pendingOrder) { return; }
		checkoutMode = true;
		drawer.setAttribute('aria-label', 'Payment');
		drawer.innerHTML = '<div class="cd-head"><h3>Payment</h3><button type="button" class="cd-close" aria-label="Close order">&times;</button></div>' +
			'<div class="cd-test" role="status">Test mode &mdash; no real payment. Orders &amp; payments are simulated.</div>' +
			'<form class="cd-cardform" novalidate>' +
			'<div class="cd-scroll">' +
			'<label class="cd-f"><span>Card number</span><input name="cnum" class="cd-cnum" type="text" inputmode="numeric" autocomplete="off" placeholder="4242 4242 4242 4242"></label>' +
			'<div class="cd-cardrow2">' +
			'<label class="cd-f"><span>Expiry (MM/YY)</span><input name="cexp" class="cd-cexp" type="text" inputmode="numeric" autocomplete="off" placeholder="12/29"></label>' +
			'<label class="cd-f"><span>CVC</span><input name="ccvc" class="cd-ccvc" type="text" inputmode="numeric" autocomplete="off" placeholder="123"></label>' +
			'</div>' +
			'<p class="cd-privacy">Nothing you type here is stored or sent anywhere &mdash; this screen is part of the interactive demo. Use made-up test details, not a real card.</p>' +
			'</div>' +
			'<div class="cd-cta">' +
			'<div class="cd-err" role="alert"></div>' +
			'<button type="submit" class="vb-btn vb-btn-ember cd-payb">' + (netTotal() > 0 ? 'Pay ' + money(netTotal()) + ' &mdash; test mode' : 'Place order &mdash; voucher covers it') + '</button>' +
			'<button type="button" class="vb-btn vb-btn-dark cd-cardback">Back</button>' +
			'</div>' +
			'</form>';
		var f = drawer.querySelector('.cd-cnum'); if (f) { f.focus(); }
	}

	function payCard(form) {
		var err = form.querySelector('.cd-err');
		var num = form.querySelector('.cd-cnum'), exp = form.querySelector('.cd-cexp'), cvc = form.querySelector('.cd-ccvc');
		if (!num.value.trim() || !exp.value.trim() || !cvc.value.trim()) {
			err.textContent = 'Fill in all card fields — any made-up test values work.';
			(!num.value.trim() ? num : (!exp.value.trim() ? exp : cvc)).focus();
			return;
		}
		// Loading state while the (simulated) payment settles — same pattern as
		// the offers.js voucher-claim button.
		if (form.dataset.busy) { return; }
		form.dataset.busy = '1';
		var payBtn = form.querySelector('.cd-payb');
		if (payBtn) { payBtn.disabled = true; payBtn.innerHTML = '<span class="cd-spin" aria-hidden="true"></span>Processing test payment&hellip;'; }
		setTimeout(renderDone, 1500);
	}

	function renderDone() {
		if (!pendingOrder || !drawerOpen) { pendingOrder = null; return; }
		drawer.setAttribute('aria-label', 'Order placed');
		var name = pendingOrder.name;
		var location = window.DBDemo.getLocation(pendingOrder.locationId) || DEFAULT_LOCATION;
		var ref = CFG.brand.orderReferencePrefix + '-' + new Date().toISOString().slice(2, 10).replace(/-/g, '') + '-' + Math.floor(1000 + Math.random() * 9000);
		var amt = money(netTotal());
		var vline = voucher ? ' &middot; voucher <strong>' + esc(voucher.code) + '</strong> (&minus;' + money(discount()) + ')' : '';
		var saving = voucher ? '<p class="cd-reward-note"><b>Discount applied:</b> you saved ' + money(discount()) + ' with ' + esc(voucher.code) + '. The final system will show only verified member or VIP savings here.</p>' : '<p class="cd-reward-note"><b>Member benefits:</b> loyalty and VIP savings are not active in this demo. When connected, eligible benefits will be checked before payment and shown clearly here.</p>';
		var journey = '<div class="cd-receipt" aria-label="Demo order journey"><div class="cd-receipt__head"><span>Payment confirmation</span><span class="cd-receipt__state">Confirmed in demo</span></div><ol class="cd-track"><li class="is-now">Order received</li><li>In the oven</li><li>Ready for pickup</li></ol></div>';
		drawer.innerHTML = '<div class="cd-head"><h3>Order placed</h3><button type="button" class="cd-close" aria-label="Close order">&times;</button></div>' +
			'<div class="cd-done" role="status" tabindex="-1"><div class="cd-check" aria-hidden="true">&#10003;</div><h3>Thanks, ' + esc(name) + '!</h3><p>Demo order <strong>' + esc(ref) + '</strong> &middot; ' + amt + vline + '</p>' +
			'<p class="cd-eta">' + esc(window.DBDemo.t('fulfilment.' + pendingOrder.fulfilment)) + ' from <strong>' + esc(location.name) + '</strong> &middot; timing is simulated</p>' + journey +
			'<div class="cd-summary cd-donesum">' + summaryLines() + '</div>' +
			saving + '<button type="button" class="cd-copyref" data-copyref="' + esc(ref) + '">Copy order number</button><p class="cd-note">' + esc(window.DBDemo.t('checkout.demoNotice')) + '</p></div>';
		cart = {};
		voucher = null;
		pendingOrder = null;
		for (var n in controls) { paintItem(n); }
		renderFab(false);
		var done = drawer.querySelector('.cd-done');
		if (done) { try { done.focus({ preventScroll: true }); } catch (e) { done.focus(); } }
	}

	/* --- events --- */
	menuView.addEventListener('click', function (e) {
		var a = e.target.closest('[data-add]'); if (a) { tileAdd(a.getAttribute('data-add')); return; }
		var inc = e.target.closest('[data-inc]'); if (inc) { tileAdd(inc.getAttribute('data-inc')); return; }
		var dec = e.target.closest('[data-dec]'); if (dec) { tileDec(dec.getAttribute('data-dec')); return; }
	});
	fab.addEventListener('click', openDrawer);
	overlay.addEventListener('click', closeDrawer);
	drawer.addEventListener('click', function (e) {
		if (e.target.closest('.cd-close')) { closeDrawer(); return; }
		if (e.target.closest('.cd-checkout')) { renderCheckout(); return; }
		if (e.target.closest('.cd-cardback')) { pendingOrder = null; renderCheckout(); return; }
		if (e.target.closest('.cd-back')) { renderDrawer(); return; }
		if (e.target.closest('.cd-vapply')) { applyVoucher(); return; }
		if (e.target.closest('.cd-vremove')) { voucher = null; renderCheckout(); return; }
		var copy = e.target.closest('[data-copyref]');
		if (copy) {
			var reference = copy.getAttribute('data-copyref') || '';
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(reference).then(function () { copy.textContent = 'Order number copied'; }, function () { copy.textContent = 'Order number: ' + reference; });
			} else {
				/* A local file preview may not grant clipboard access. Show the value instead
				   of claiming a copy action that the browser could not perform. */
				copy.textContent = 'Order number: ' + reference;
			}
			return;
		}
		var xs = e.target.closest('[data-xsell]'); if (xs) { addLine(xs.getAttribute('data-xsell')); renderDrawer(); return; }
		var inc = e.target.closest('[data-kinc]'); if (inc) { bumpLine(inc.getAttribute('data-kinc'), 1); return; }
		var dec = e.target.closest('[data-kdec]'); if (dec) { bumpLine(dec.getAttribute('data-kdec'), -1); return; }
	});
	drawer.addEventListener('submit', function (e) {
		if (e.target.closest('.cd-form')) { e.preventDefault(); placeOrder(e.target); return; }
		if (e.target.closest('.cd-cardform')) { e.preventDefault(); payCard(e.target); }
	});
	/* Light cosmetic formatting on the simulated card fields (digits only, grouping). */
	drawer.addEventListener('input', function (e) {
		var t = e.target;
		if (t.classList.contains('cd-cnum')) {
			t.value = t.value.replace(/\D/g, '').slice(0, 16).replace(/(\d{4})(?=\d)/g, '$1 ');
		} else if (t.classList.contains('cd-cexp')) {
			var d = t.value.replace(/\D/g, '').slice(0, 4);
			t.value = d.length > 2 ? d.slice(0, 2) + '/' + d.slice(2) : d;
		} else if (t.classList.contains('cd-ccvc')) {
			t.value = t.value.replace(/\D/g, '').slice(0, 4);
		}
	});
	/* Enter in the voucher field applies the code instead of submitting checkout
	   (the field lives inside the checkout form so the whole step scrolls as one). */
	drawer.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && e.target.classList && e.target.classList.contains('cd-vinput')) {
			e.preventDefault();
			applyVoucher();
		}
	});

	/* --- on-screen keyboard handling (visualViewport) ---
	   The drawer is fixed top:0/bottom:0; a mobile keyboard overlays the layout
	   viewport, hiding the pinned CTA. When the visual viewport shrinks we lift
	   the drawer's bottom edge above the keyboard and nudge only the inner
	   .cd-scroll so the focused input stays visible (never scrolling the page
	   behind the drawer). Browsers without visualViewport resize the layout
	   viewport themselves, which achieves the same and needs no fallback code. */
	var vv = window.visualViewport || null;
	function revealFocused() {
		var t = document.activeElement;
		if (!t || t.tagName !== 'INPUT' || !drawer.contains(t)) { return; }
		var sc = t.closest ? t.closest('.cd-scroll') : null;
		if (!sc) { return; }
		var r = t.getBoundingClientRect(), c = sc.getBoundingClientRect();
		if (r.bottom > c.bottom - 10) { sc.scrollTop += (r.bottom - c.bottom) + 10; }
		else if (r.top < c.top + 10) { sc.scrollTop -= (c.top - r.top) + 10; }
	}
	function vvSync() {
		if (!vv) { return; }
		var kb = drawerOpen ? Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop)) : 0;
		var off = kb > 1 ? kb + 'px' : '';
		if (drawer.style.bottom !== off) {
			drawer.style.bottom = off;
			if (off) { requestAnimationFrame(revealFocused); }
		}
	}
	if (vv) {
		vv.addEventListener('resize', vvSync);
		vv.addEventListener('scroll', vvSync);
	}
	drawer.addEventListener('focusin', function (e) {
		if (e.target.tagName !== 'INPUT') { return; }
		// Give the keyboard a beat to open/settle, then bring the field into view.
		setTimeout(function () { vvSync(); revealFocused(); }, 250);
	});

	document.addEventListener('keydown', function (e) {
		var root = sheetOpen ? sheet : (drawerOpen ? drawer : null);
		if (!root) { return; }
		if (e.key === 'Escape') { if (sheetOpen) { closeSheet(); } else { closeDrawer(); } return; }
		if (e.key === 'Tab') {
			var f = focusables(root);
			if (!f.length) { return; }
			var first = f[0], last = f[f.length - 1];
			if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
			else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
		}
	});

	/* The cart FAB/drawer/overlay live on document.body, so hide them when the menu
	   view isn't active (the router dispatches db:view on every route change). */
	window.addEventListener('db:view', function (e) {
		var menu = (e && e.detail) ? (e.detail === 'menu' || e.detail === 'order') : onMenuView();
		if (!menu) {
			if (sheetOpen) { closeSheet(); }
			if (drawerOpen) { closeDrawer(); }
			fab.classList.remove('is-shown');
			fab.setAttribute('aria-hidden', 'true');
			document.body.classList.remove('cart-on');
		} else {
			renderFab(false);
		}
	});
}());
