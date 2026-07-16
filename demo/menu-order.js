/* Dough Boss — browse-and-order: per-item add (with Domino's-style options sheet),
   floating cart button, slide-in cart + checkout with a lemon & chilli confirm step. */
(function () {
	'use strict';
	var menuView = document.getElementById('view-menu');
	if (!menuView) { return; }
	var cart = {};       // lineKey -> { key, name, catId, basePrice, price, opts, summary, qty, seq }
	var controls = {};   // item name -> { el, name, price, catId, groups, lastSel }
	var seq = 0;         // insertion counter so the tile "−" targets the newest line
	var drawerOpen = false, checkoutMode = false, lastFocus = null;
	var sheetOpen = false, sheetName = null, sheetLastFocus = null;
	var voucher = null;      // { code, amount } once a valid code is applied
	var lemonChilli = null;  // 'Yes' | 'No' — pre-checkout question, re-asked on each checkout tap
	var pendingOrder = null; // { name, phone } held during the simulated card-payment step
	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function money(n) { return '$' + Number(n).toFixed(2); }
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function count() { var c = 0; for (var k in cart) { c += cart[k].qty; } return c; }
	function total() { var t = 0; for (var k in cart) { t += cart[k].price * cart[k].qty; } return t; }
	function discount() { return voucher ? Math.min(voucher.amount, total()) : 0; }
	function netTotal() { return Math.max(0, total() - discount()); }
	function onMenuView() { var k = (location.hash || '#about').replace('#', ''); return k === 'menu' || k === 'order'; }
	function itemQty(name) { var q = 0; for (var k in cart) { if (cart[k].name === name) { q += cart[k].qty; } } return q; }
	function newestLine(name) { var best = null; for (var k in cart) { if (cart[k].name === name && (!best || cart[k].seq > best.seq)) { best = cart[k]; } } return best; }
	function hasPizza() { for (var k in cart) { if (cart[k].catId === 'cat-pizza') { return true; } } return false; }

	/* --- per-category item options (Domino's-style customization) ---
	   Choices carry a price delta; `def` marks the pre-selected default; `sum` is the
	   short label used in cart summaries when it differs from the sheet label.
	   Pizza base surcharges follow the owner's confirmed pricing (+$4.00 either base). */
	var OPT_STYLE = { id: 'style', label: 'Style', type: 'radio', choices: [
		{ label: 'Flat', delta: 0, def: true },
		{ label: 'Folded', delta: 0 }
	] };
	var OPT_ZAATAR_MIX = { id: 'mix', label: 'Zaatar mix', type: 'radio', choices: [
		{ label: 'Classic', delta: 0, def: true },
		{ label: 'Mixed — zaatar & cheese', sum: 'Mixed zaatar', delta: 0.5 }
	] };
	var OPT_PIZZA_BASE = { id: 'base', label: 'Base', type: 'radio', choices: [
		{ label: 'Normal', delta: 0, def: true },
		{ label: 'Wholemeal', delta: 4 },
		{ label: 'Gluten-free', delta: 4 }
	] };
	var OPT_WRAP_EXTRAS = { id: 'extras', label: 'Extras', type: 'check', choices: [
		{ label: 'Add labneh', sum: 'Labneh', delta: 2.5 },
		{ label: 'Add cheese', sum: 'Cheese', delta: 2.5 }
	] };
	function optionGroups(catId, name) {
		if (catId === 'cat-pizza') { return [OPT_PIZZA_BASE]; }
		if (catId === 'cat-manoush') { return name === 'Zaatar' ? [OPT_STYLE, OPT_ZAATAR_MIX] : [OPT_STYLE]; }
		if (catId === 'cat-wraps' && name === 'Zaatar & Veggie') { return [OPT_WRAP_EXTRAS]; }
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
		var price = parseFloat(priceEl.textContent.replace(/[^0-9.]/g, '')) || 0;
		var catEl = el.closest('.mn-cat');
		var catId = catEl ? catEl.id : '';
		var act = document.createElement('div');
		act.className = 'mn-it-act';
		(el.querySelector('.mn-it-body') || el).appendChild(act);
		controls[name] = { el: act, name: name, price: price, catId: catId, groups: optionGroups(catId, name), lastSel: null };
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
		if (c.groups) { openSheet(name); } else { addLine(name, []); }
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
		lemonChilli = null;   // cart is editable again — re-ask on the next checkout tap
		var lines = '';
		for (var k in cart) {
			var it = cart[k];
			lines += '<div class="cd-line"><span class="cd-n">' + esc(it.name) + (it.summary ? '<small class="cd-opts">' + esc(it.summary) + '</small>' : '') + '</span><span class="cd-qty"><button type="button" data-kdec="' + esc(k) + '" aria-label="Remove one">&minus;</button><b>' + it.qty + '</b><button type="button" data-kinc="' + esc(k) + '" aria-label="Add one">+</button></span><span class="cd-p">' + money(it.price * it.qty) + '</span></div>';
		}
		if (!lines) { lines = '<p class="cd-empty">Your order is empty &mdash; add a few manoush.</p>'; }
		drawer.innerHTML = '<div class="cd-head"><h3>Your order</h3><button type="button" class="cd-close" aria-label="Close order">&times;</button></div>' +
			'<div class="cd-body">' + lines + '</div>' +
			'<div class="cd-foot"><div class="cd-tot"><span>Total</span><strong>' + money(total()) + '</strong></div>' +
			'<button type="button" class="vb-btn vb-btn-ember cd-checkout"' + (count() ? '' : ' disabled') + '>Checkout</button>' +
			'<p class="cd-note">Demo &middot; pickup from Revesby only &middot; no real payment.</p></div>';
	}

	/* Pre-checkout confirm: pizzas prompt the lemon & chilli question (required choice). */
	function renderLemonConfirm() {
		checkoutMode = true;
		drawer.innerHTML = '<div class="cd-head"><h3>One quick thing</h3><button type="button" class="cd-close" aria-label="Close order">&times;</button></div>' +
			'<div class="opt-lc"><p class="opt-lc-q">Would you like lemon &amp; chilli with your pizza?</p>' +
			'<div class="opt-lc-btns"><button type="button" class="vb-btn vb-btn-ember" data-lc="Yes">Yes please</button>' +
			'<button type="button" class="vb-btn vb-btn-dark" data-lc="No">No thanks</button></div>' +
			'<button type="button" class="cd-back opt-lc-back">Back to order</button></div>';
		var y = drawer.querySelector('[data-lc]'); if (y) { y.focus(); }
	}

	function renderCheckout() {
		if (!count()) { return; }
		checkoutMode = true;
		var vouchHtml = voucher
			? '<div class="cd-von"><span class="cd-vcode">&#127915; ' + esc(voucher.code) + ' applied</span><button type="button" class="cd-vremove">Remove</button></div>'
			: '<div class="cd-vrow"><input type="text" class="cd-vinput" placeholder="Voucher code (try a DOUGH-… code)" aria-label="Voucher code" autocapitalize="characters"><button type="button" class="cd-vapply">Apply</button></div>';
		var totsHtml = (voucher
			? '<div class="cd-tline"><span>Subtotal</span><span>' + money(total()) + '</span></div>' +
				'<div class="cd-tline cd-tdisc"><span>Voucher ' + esc(voucher.code) + '</span><span>&minus;' + money(discount()) + '</span></div>'
			: '') +
			(lemonChilli !== null ? '<div class="cd-tline"><span>Lemon &amp; chilli</span><span>' + esc(lemonChilli) + '</span></div>' : '') +
			'<div class="cd-tot"><span>Total</span><strong>' + money(netTotal()) + '</strong></div>';
		drawer.innerHTML = '<div class="cd-head"><h3>Checkout</h3><button type="button" class="cd-close" aria-label="Close order">&times;</button></div>' +
			'<div class="cd-vouch">' + vouchHtml + '<p class="cd-verr" role="alert"></p></div>' +
			'<form class="cd-form" novalidate>' +
			'<label class="cd-f"><span>Name</span><input name="name" type="text" autocomplete="name" required></label>' +
			'<label class="cd-f"><span>Phone</span><input name="phone" type="tel" autocomplete="tel" required></label>' +
			'<fieldset class="cd-f"><legend>Fulfilment</legend><p>Pickup from <strong>Revesby</strong></p><input type="hidden" name="ful" value="pickup"><input type="hidden" name="shop" value="Revesby"></fieldset>' +
			'<fieldset class="cd-f cd-pay">' +
			'<legend>Payment</legend>' +
			'<p class="cd-privacy cd-carddemo">Card payment (simulated). You&rsquo;ll enter test card details on the next step &mdash; orders &amp; payments are simulated, no real payment is taken.</p>' +
			'</fieldset>' +
			'<div class="cd-tots">' + totsHtml + '</div>' +
			'<div class="cd-err" role="alert"></div>' +
			'<button type="submit" class="vb-btn vb-btn-ember">Place demo order</button>' +
			'<button type="button" class="vb-btn vb-btn-dark cd-back">Back to order</button>' +
			'<p class="cd-privacy">We use your name and phone only to process your order. See our <a href="privacy.html" target="_blank" rel="noopener">Privacy Policy</a>.</p>' +
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
		voucher = { code: code, amount: 5 };   // $5 off — the Dough Boss side of the student voucher
		renderCheckout();
	}

	function placeOrder(form) {
		var fd = new FormData(form);
		var name = (fd.get('name') || '').toString().trim();
		var phone = (fd.get('phone') || '').toString().trim();
		var err = form.querySelector('.cd-err');
		if (!name || !phone) { err.textContent = 'Please add your name and phone.'; return; }
		pendingOrder = { name: name, phone: phone };
		renderCardSheet();
	}

	/* Simulated card-payment step — modeled on a hosted-fields checkout but
	   unmistakably TEST MODE: any input is accepted, nothing is stored or sent. */
	function renderCardSheet() {
		if (!count() || !pendingOrder) { return; }
		checkoutMode = true;
		drawer.innerHTML = '<div class="cd-head"><h3>Payment</h3><button type="button" class="cd-close" aria-label="Close order">&times;</button></div>' +
			'<div class="cd-test" role="status">Test mode &mdash; no real payment. Orders &amp; payments are simulated.</div>' +
			'<form class="cd-cardform" novalidate>' +
			'<label class="cd-f"><span>Card number</span><input name="cnum" class="cd-cnum" type="text" inputmode="numeric" autocomplete="off" placeholder="4242 4242 4242 4242"></label>' +
			'<div class="cd-cardrow2">' +
			'<label class="cd-f"><span>Expiry (MM/YY)</span><input name="cexp" class="cd-cexp" type="text" inputmode="numeric" autocomplete="off" placeholder="12/29"></label>' +
			'<label class="cd-f"><span>CVC</span><input name="ccvc" class="cd-ccvc" type="text" inputmode="numeric" autocomplete="off" placeholder="123"></label>' +
			'</div>' +
			'<div class="cd-err" role="alert"></div>' +
			'<button type="submit" class="vb-btn vb-btn-ember cd-payb">Pay ' + money(netTotal()) + ' &mdash; test mode</button>' +
			'<button type="button" class="vb-btn vb-btn-dark cd-cardback">Back</button>' +
			'<p class="cd-privacy">Nothing you type here is stored or sent anywhere &mdash; this screen is part of the interactive demo. Use made-up test details, not a real card.</p>' +
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
		var name = pendingOrder.name;
		var ref = 'DB-' + new Date().toISOString().slice(2, 10).replace(/-/g, '') + '-' + Math.floor(1000 + Math.random() * 9000);
		var amt = money(netTotal());
		var vline = voucher ? ' &middot; voucher <strong>' + esc(voucher.code) + '</strong> (&minus;' + money(discount()) + ')' : '';
		var lcline = lemonChilli !== null ? ' &middot; lemon &amp; chilli: <strong>' + esc(lemonChilli) + '</strong>' : '';
		drawer.innerHTML = '<div class="cd-head"><h3>Order placed</h3><button type="button" class="cd-close" aria-label="Close order">&times;</button></div>' +
			'<div class="cd-done" role="status" tabindex="-1"><div class="cd-check" aria-hidden="true">&#10003;</div><h3>Thanks, ' + esc(name) + '!</h3><p>Demo order <strong>' + esc(ref) + '</strong> &middot; ' + amt + vline + lcline + '</p><p class="cd-note">No payment was taken and no real order was sent.</p></div>';
		cart = {};
		voucher = null;
		lemonChilli = null;
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
		if (e.target.closest('.cd-checkout')) {
			if (hasPizza() && lemonChilli === null) { renderLemonConfirm(); } else { renderCheckout(); }
			return;
		}
		var lc = e.target.closest('[data-lc]'); if (lc) { lemonChilli = lc.getAttribute('data-lc'); renderCheckout(); return; }
		if (e.target.closest('.cd-cardback')) { pendingOrder = null; renderCheckout(); return; }
		if (e.target.closest('.cd-back')) { renderDrawer(); return; }
		if (e.target.closest('.cd-vapply')) { applyVoucher(); return; }
		if (e.target.closest('.cd-vremove')) { voucher = null; renderCheckout(); return; }
		var inc = e.target.closest('[data-kinc]'); if (inc) { bumpLine(inc.getAttribute('data-kinc'), 1); return; }
		var dec = e.target.closest('[data-kdec]'); if (dec) { bumpLine(dec.getAttribute('data-kdec'), -1); return; }
	});
	drawer.addEventListener('submit', function (e) { if (e.target.closest('.cd-form')) { e.preventDefault(); placeOrder(e.target); } });
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
