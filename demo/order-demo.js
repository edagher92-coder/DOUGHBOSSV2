/* Dough Boss — interactive online ordering demo (menu -> cart -> checkout). */
(function () {
	'use strict';
	var mount = document.getElementById('db-order-demo');
	if (!mount) { return; }
	var MENU = [
		{ cat: 'Manoush', items: [
			{ n: 'Zaatar', p: 7, d: ['vegan'] }, { n: 'Cheese', p: 9, d: ['vegetarian'] },
			{ n: 'Zaatar & Cheese', p: 10, d: ['vegetarian'] }, { n: 'Lahm bi Ajin', p: 11, d: ['halal'] },
			{ n: 'Spinach Fatayer', p: 8, d: ['vegan'] }
		] },
		{ cat: 'Bites', items: [
			{ n: 'Falafel (6)', p: 8, d: ['vegan'] }, { n: 'Cheese Sambousek', p: 7, d: ['vegetarian'] },
			{ n: 'Kibbeh (4)', p: 10, d: ['halal'] }, { n: 'Chicken Wings', p: 12, d: ['halal'] }, { n: 'Chips', p: 6, d: ['vegan'] }
		] },
		{ cat: 'Dips', items: [ { n: 'Hummus', p: 6, d: ['vegan'] }, { n: 'Labneh', p: 6, d: ['vegetarian'] }, { n: 'Garlic', p: 5, d: ['vegan'] } ] },
		{ cat: 'Drinks', items: [ { n: 'Soft Drink', p: 3.5, d: [] }, { n: 'Water', p: 2, d: [] }, { n: 'Ayran', p: 4, d: ['vegetarian'] }, { n: 'Lebanese Coffee', p: 4, d: [] } ] }
	];
	var DIET = { vegetarian: 'V', vegan: 'VG', halal: 'H', gluten_free: 'GF' };
	var SHOPS = ['Bankstown', 'Revesby', 'Roselands'];
	var cart = {};
	function money(n) { return '$' + Number(n).toFixed(2); }
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function total() { var t = 0; for (var k in cart) { t += cart[k].p * cart[k].q; } return t; }
	function count() { var c = 0; for (var k in cart) { c += cart[k].q; } return c; }
	function render() {
		var menuHtml = MENU.map(function (g) {
			return '<div class="dbo-cat"><h3>' + esc(g.cat) + '</h3><div class="dbo-items">' +
				g.items.map(function (it) {
					var tags = it.d.map(function (k) { return '<span class="dbo-tag">' + DIET[k] + '</span>'; }).join('');
					return '<div class="dbo-item"><div class="dbo-it-main"><span class="dbo-it-n">' + esc(it.n) + ' ' + tags + '</span><span class="dbo-it-p">' + money(it.p) + '</span></div>' +
						'<button type="button" class="dbo-add" data-add="' + esc(it.n) + '" data-price="' + it.p + '">Add</button></div>';
				}).join('') + '</div></div>';
		}).join('');
		mount.innerHTML = '<div class="dbo"><div class="dbo-menu">' + menuHtml + '</div><aside class="dbo-cart" aria-live="polite"></aside></div>';
		paintCart();
	}
	function paintCart() {
		var box = mount.querySelector('.dbo-cart');
		if (!box) { return; }
		var lines = '';
		for (var k in cart) {
			var c = cart[k];
			lines += '<div class="dbo-line"><span class="dbo-line-n">' + esc(c.n) + '</span>' +
				'<span class="dbo-qty"><button type="button" data-dec="' + esc(k) + '" aria-label="Decrease">&minus;</button>' +
				'<b>' + c.q + '</b><button type="button" data-inc="' + esc(k) + '" aria-label="Increase">+</button></span>' +
				'<span class="dbo-line-p">' + money(c.p * c.q) + '</span></div>';
		}
		if (!lines) { lines = '<p class="dbo-empty">Your bag is empty — add a few manoush.</p>'; }
		box.innerHTML = '<div class="dbo-cart-h">Your order <span>' + count() + '</span></div><div class="dbo-lines">' + lines + '</div><div class="dbo-tot"><span>Total</span><strong>' + money(total()) + '</strong></div><button type="button" class="vb-btn vb-btn-ember dbo-checkout"' + (count() ? '' : ' disabled') + '>Checkout</button>';
	}
	function checkout() {
		var shopOpts = SHOPS.map(function (s) { return '<option>' + s + '</option>'; }).join('');
		mount.querySelector('.dbo-cart').innerHTML = '<div class="dbo-cart-h">Checkout</div><form class="dbo-form"><label class="dbo-f"><span>Name</span><input name="name" type="text" required></label><label class="dbo-f"><span>Phone</span><input name="phone" type="tel" required></label><fieldset class="dbo-f"><span>Fulfilment</span><label class="dbo-rad"><input type="radio" name="ful" value="pickup" checked> Pickup</label><label class="dbo-rad"><input type="radio" name="ful" value="delivery"> Delivery</label></fieldset><label class="dbo-f"><span>Shop</span><select name="shop">' + shopOpts + '</select></label><div class="dbo-tot"><span>Total</span><strong>' + money(total()) + '</strong></div><div class="dbo-err" role="alert"></div><button type="submit" class="vb-btn vb-btn-ember">Place order</button><button type="button" class="vb-btn vb-btn-dark dbo-back">Back to menu</button></form>';
	}
	function placeOrder(form) {
		var fd = new FormData(form);
		var name = (fd.get('name') || '').toString().trim();
		var phone = (fd.get('phone') || '').toString().trim();
		var err = form.querySelector('.dbo-err');
		if (!name || !phone) { err.textContent = 'Please add your name and phone.'; return; }
		var ref = 'DB-' + new Date().toISOString().slice(2, 10).replace(/-/g, '') + '-' + Math.floor(1000 + Math.random() * 9000);
		mount.querySelector('.dbo-cart').innerHTML = '<div class="dbo-done"><div class="dbo-check">✓</div><h3>Order placed</h3><p>Order <strong>' + esc(ref) + '</strong> · ' + money(total()) + '</p><p class="dbo-demo">Demo — in production this goes straight to the kitchen board and takes card payment.</p></div>';
		cart = {};
	}
	mount.addEventListener('click', function (e) {
		var a = e.target.closest('[data-add]');
		if (a) { var n = a.getAttribute('data-add'); var p = parseFloat(a.getAttribute('data-price')); if (!cart[n]) { cart[n] = { n: n, p: p, q: 0 }; } cart[n].q++; paintCart(); return; }
		var inc = e.target.closest('[data-inc]'); if (inc) { var ki = inc.getAttribute('data-inc'); cart[ki].q++; paintCart(); return; }
		var dec = e.target.closest('[data-dec]'); if (dec) { var kd = dec.getAttribute('data-dec'); cart[kd].q--; if (cart[kd].q <= 0) { delete cart[kd]; } paintCart(); return; }
		if (e.target.classList.contains('dbo-checkout')) { checkout(); return; }
		if (e.target.classList.contains('dbo-back')) { paintCart(); return; }
	});
	mount.addEventListener('submit', function (e) { if (e.target.classList.contains('dbo-form')) { e.preventDefault(); placeOrder(e.target); } });
	render();
}());
