/* Dough Boss — browse-and-order: per-item add, floating cart button, slide-in cart + checkout. */
(function () {
	'use strict';
	var menuView = document.getElementById('view-menu');
	if (!menuView) { return; }
	var SHOPS = ['Bankstown', 'Roselands Centro', 'Revesby'];
	var cart = {};       // name -> { name, price, qty }
	var controls = {};   // name -> { el, name, price }
	var drawerOpen = false, checkoutMode = false, lastFocus = null;
	var voucher = null;   // { code, amount } once a valid code is applied
	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function money(n) { return '$' + Number(n).toFixed(2); }
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function count() { var c = 0; for (var k in cart) { c += cart[k].qty; } return c; }
	function total() { var t = 0; for (var k in cart) { t += cart[k].price * cart[k].qty; } return t; }
	function discount() { return voucher ? Math.min(voucher.amount, total()) : 0; }
	function netTotal() { return Math.max(0, total() - discount()); }
	function onMenuView() { var k = (location.hash || '#about').replace('#', ''); return k === 'menu' || k === 'order'; }

	/* --- enhance each menu item with an Add / stepper control --- */
	Array.prototype.forEach.call(menuView.querySelectorAll('.mn-item'), function (el) {
		var nameEl = el.querySelector('.mn-it-n');
		var priceEl = el.querySelector('.mn-it-p');
		if (!nameEl || !priceEl) { return; }
		var clone = nameEl.cloneNode(true);
		Array.prototype.forEach.call(clone.querySelectorAll('.mn-tag'), function (t) { t.parentNode.removeChild(t); });
		var name = clone.textContent.replace(/\s+/g, ' ').trim();
		var price = parseFloat(priceEl.textContent.replace(/[^0-9.]/g, '')) || 0;
		var act = document.createElement('div');
		act.className = 'mn-it-act';
		(el.querySelector('.mn-it-body') || el).appendChild(act);
		controls[name] = { el: act, name: name, price: price };
		paintItem(name);
	});

	function paintItem(name) {
		var c = controls[name]; if (!c) { return; }
		var qty = cart[name] ? cart[name].qty : 0;
		if (qty > 0) {
			c.el.innerHTML = '<div class="mn-step"><button type="button" data-dec="' + esc(name) + '" aria-label="Remove one ' + esc(name) + '">&minus;</button><b>' + qty + '</b><button type="button" data-inc="' + esc(name) + '" aria-label="Add one ' + esc(name) + '">+</button></div>';
		} else {
			c.el.innerHTML = '<button type="button" class="mn-add" data-add="' + esc(name) + '" aria-label="Add ' + esc(name) + ' to your order">Add <span aria-hidden="true">+</span></button>';
		}
	}

	function add(name, delta) {
		var c = controls[name]; if (!c) { return; }
		if (!cart[name]) { cart[name] = { name: name, price: c.price, qty: 0 }; }
		cart[name].qty += delta;
		if (cart[name].qty <= 0) { delete cart[name]; }
		paintItem(name);
		renderFab(delta > 0);
		if (drawerOpen && !checkoutMode) { renderDrawer(); }
	}

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
		drawerOpen = false; checkoutMode = false;
		overlay.classList.remove('is-open'); drawer.classList.remove('is-open');
		fab.setAttribute('aria-expanded', 'false');
		if (lastFocus && lastFocus.focus && lastFocus.offsetParent !== null) { lastFocus.focus(); }
	}
	function focusables() {
		return Array.prototype.filter.call(
			drawer.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
			function (el) { return !el.disabled && el.offsetParent !== null; }
		);
	}

	function renderDrawer() {
		checkoutMode = false;
		var lines = '';
		for (var k in cart) {
			var it = cart[k];
			lines += '<div class="cd-line"><span class="cd-n">' + esc(it.name) + '</span><span class="cd-qty"><button type="button" data-dec="' + esc(k) + '" aria-label="Remove one">&minus;</button><b>' + it.qty + '</b><button type="button" data-inc="' + esc(k) + '" aria-label="Add one">+</button></span><span class="cd-p">' + money(it.price * it.qty) + '</span></div>';
		}
		if (!lines) { lines = '<p class="cd-empty">Your order is empty — add a few manoush.</p>'; }
		drawer.innerHTML = '<div class="cd-head"><h3>Your order</h3><button type="button" class="cd-close" aria-label="Close order">&times;</button></div>' +
			'<div class="cd-body">' + lines + '</div>' +
			'<div class="cd-foot"><div class="cd-tot"><span>Total</span><strong>' + money(total()) + '</strong></div>' +
			'<button type="button" class="vb-btn vb-btn-ember cd-checkout"' + (count() ? '' : ' disabled') + '>Checkout</button>' +
			'<p class="cd-note">Demo &middot; pickup or delivery &middot; card payment in production.</p></div>';
	}

	function renderCheckout() {
		if (!count()) { return; }
		checkoutMode = true;
		var shopOpts = SHOPS.map(function (s) { return '<option>' + s + '</option>'; }).join('');
		var vouchHtml = voucher
			? '<div class="cd-von"><span class="cd-vcode">&#127915; ' + esc(voucher.code) + ' applied</span><button type="button" class="cd-vremove">Remove</button></div>'
			: '<div class="cd-vrow"><input type="text" class="cd-vinput" placeholder="Voucher code (try a SNOW-… code)" aria-label="Voucher code" autocapitalize="characters"><button type="button" class="cd-vapply">Apply</button></div>';
		var totsHtml = (voucher
			? '<div class="cd-tline"><span>Subtotal</span><span>' + money(total()) + '</span></div>' +
				'<div class="cd-tline cd-tdisc"><span>Voucher ' + esc(voucher.code) + '</span><span>&minus;' + money(discount()) + '</span></div>'
			: '') +
			'<div class="cd-tot"><span>Total</span><strong>' + money(netTotal()) + '</strong></div>';
		drawer.innerHTML = '<div class="cd-head"><h3>Checkout</h3><button type="button" class="cd-close" aria-label="Close order">&times;</button></div>' +
			'<div class="cd-vouch">' + vouchHtml + '<p class="cd-verr" role="alert"></p></div>' +
			'<form class="cd-form" novalidate>' +
			'<label class="cd-f"><span>Name</span><input name="name" type="text" autocomplete="name" required></label>' +
			'<label class="cd-f"><span>Phone</span><input name="phone" type="tel" autocomplete="tel" required></label>' +
			'<fieldset class="cd-f"><span>Fulfilment</span><label class="cd-rad"><input type="radio" name="ful" value="pickup" checked> Pickup</label><label class="cd-rad"><input type="radio" name="ful" value="delivery"> Delivery</label></fieldset>' +
			'<label class="cd-f"><span>Shop</span><select name="shop">' + shopOpts + '</select></label>' +
			'<div class="cd-tots">' + totsHtml + '</div>' +
			'<div class="cd-err" role="alert"></div>' +
			'<button type="submit" class="vb-btn vb-btn-ember">Place order</button>' +
			'<button type="button" class="vb-btn vb-btn-dark cd-back">Back to order</button>' +
			'<p class="cd-privacy">We use your name and phone only to process your order. Card payment is handled by Stripe in production. See our <a href="privacy.html" target="_blank" rel="noopener">Privacy Policy</a>.</p>' +
			'</form>';
		var f = drawer.querySelector('input[name="name"]'); if (f) { f.focus(); }
	}

	function applyVoucher() {
		var input = drawer.querySelector('.cd-vinput');
		var verr = drawer.querySelector('.cd-verr');
		if (verr) { verr.textContent = ''; }
		var code = input ? input.value.trim().toUpperCase() : '';
		// Demo-only shape check (2-4 hyphen-separated groups) — accepts both the
		// short demo-style code (SNOW-7K2D9Q) and real WordPress-issued codes
		// (e.g. SNOW110022-ZRGQ-U8P5). This does NOT check a real database or
		// consume a real voucher; it just stops the demo rejecting real-shaped
		// codes on sight so it looks right when showing people the flow.
		if (!/^[A-Z0-9]{2,12}(-[A-Z0-9]{2,12}){1,3}$/.test(code)) {
			if (verr) { verr.textContent = 'Enter a valid voucher code, e.g. SNOW-7K2D9Q.'; }
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
		var ref = 'DB-' + new Date().toISOString().slice(2, 10).replace(/-/g, '') + '-' + Math.floor(1000 + Math.random() * 9000);
		var amt = money(netTotal());
		var vline = voucher ? ' &middot; voucher <strong>' + esc(voucher.code) + '</strong> (&minus;' + money(discount()) + ')' : '';
		drawer.innerHTML = '<div class="cd-head"><h3>Order placed</h3><button type="button" class="cd-close" aria-label="Close order">&times;</button></div>' +
			'<div class="cd-done" role="status"><div class="cd-check" aria-hidden="true">&#10003;</div><h3>Thanks, ' + esc(name) + '!</h3><p>Order <strong>' + esc(ref) + '</strong> &middot; ' + amt + vline + '</p><p class="cd-note">Demo &mdash; in production this goes straight to the kitchen board and takes card payment.</p></div>';
		cart = {};
		voucher = null;
		for (var n in controls) { paintItem(n); }
		renderFab(false);
	}

	/* --- events --- */
	menuView.addEventListener('click', function (e) {
		var a = e.target.closest('[data-add]'); if (a) { add(a.getAttribute('data-add'), 1); return; }
		var inc = e.target.closest('[data-inc]'); if (inc) { add(inc.getAttribute('data-inc'), 1); return; }
		var dec = e.target.closest('[data-dec]'); if (dec) { add(dec.getAttribute('data-dec'), -1); return; }
	});
	fab.addEventListener('click', openDrawer);
	overlay.addEventListener('click', closeDrawer);
	drawer.addEventListener('click', function (e) {
		if (e.target.closest('.cd-close')) { closeDrawer(); return; }
		if (e.target.closest('.cd-checkout')) { renderCheckout(); return; }
		if (e.target.closest('.cd-back')) { renderDrawer(); return; }
		if (e.target.closest('.cd-vapply')) { applyVoucher(); return; }
		if (e.target.closest('.cd-vremove')) { voucher = null; renderCheckout(); return; }
		var inc = e.target.closest('[data-inc]'); if (inc) { add(inc.getAttribute('data-inc'), 1); return; }
		var dec = e.target.closest('[data-dec]'); if (dec) { add(dec.getAttribute('data-dec'), -1); return; }
	});
	drawer.addEventListener('submit', function (e) { if (e.target.closest('.cd-form')) { e.preventDefault(); placeOrder(e.target); } });
	document.addEventListener('keydown', function (e) {
		if (!drawerOpen) { return; }
		if (e.key === 'Escape') { closeDrawer(); return; }
		if (e.key === 'Tab') {
			var f = focusables();
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
			if (drawerOpen) { closeDrawer(); }
			fab.classList.remove('is-shown');
			fab.setAttribute('aria-hidden', 'true');
			document.body.classList.remove('cart-on');
		} else {
			renderFab(false);
		}
	});
}());
