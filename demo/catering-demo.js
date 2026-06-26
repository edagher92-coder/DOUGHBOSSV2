/* Dough Boss — interactive catering quote builder (demo). Self-contained. */
(function () {
	'use strict';
	var mount = document.getElementById('db-catering-demo');
	if (!mount) { return; }
	var ORDERS_EMAIL = 'orders@doughboss.com.au';
	var PACKAGES = [
		{ id: 'lunch', name: 'The Lunch Run', serves: '8–10', max: 10, price: 165, desc: 'Manoush, dips & pizza' },
		{ id: 'party', name: 'The Party Box', serves: '15–20', max: 20, price: 320, desc: 'Manoush, pizza & sides', pop: true },
		{ id: 'footy', name: 'The Footy Feed', serves: '25–30', max: 30, price: 520, desc: 'Manoush & pizza platters' },
		{ id: 'big', name: 'The Big Event', serves: '40–50', max: 50, price: 850, desc: 'Full mezze spread' },
		{ id: 'custom', name: 'Custom', serves: '10+', max: 0, price: 0, perHead: 19, desc: '$19 per head, min 10' }
	];
	var SHOPS = ['Bankstown', 'Revesby', 'Roselands'];
	var state = { pkg: 'party', guests: 18, date: '', type: 'pickup', shop: 'Bankstown', name: '', email: '', phone: '' };
	function money(n) { return '$' + (Math.round(Number(n) * 100) / 100).toFixed(2); }
	function pkgById(id) { for (var i = 0; i < PACKAGES.length; i++) { if (PACKAGES[i].id === id) { return PACKAGES[i]; } } return PACKAGES[1]; }
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function quote() {
		var p = pkgById(state.pkg);
		var g = Math.max(0, parseInt(state.guests, 10) || 0);
		var subtotal = p.id === 'custom' ? Math.max(150, g * p.perHead) : p.price;
		var delivery = state.type === 'delivery' ? 25 : 0;
		var total = subtotal + delivery;
		var deposit = Math.round(total * 0.30 * 100) / 100;
		var lead = g >= 40 ? 72 : 48;
		return { subtotal: subtotal, delivery: delivery, total: total, deposit: deposit, balance: total - deposit, lead: lead };
	}
	function minDate(hrs) { return new Date(Date.now() + hrs * 3600 * 1000).toISOString().slice(0, 10); }
	function render() {
		var cards = PACKAGES.map(function (pk) {
			var price = pk.id === 'custom' ? '$19/head' : money(pk.price);
			return '<button type="button" class="dbq-pk' + (pk.id === state.pkg ? ' is-on' : '') + '" data-pk="' + pk.id + '">' +
				(pk.pop ? '<span class="dbq-rib">Most booked</span>' : '') +
				'<span class="dbq-pk-nm">' + esc(pk.name) + '</span>' +
				'<span class="dbq-pk-sv">Serves ' + esc(pk.serves) + ' · ' + esc(pk.desc) + '</span>' +
				'<span class="dbq-pk-pr">' + price + '</span></button>';
		}).join('');
		var shopOpts = SHOPS.map(function (s) { return '<option value="' + s + '"' + (s === state.shop ? ' selected' : '') + '>' + s + '</option>'; }).join('');
		mount.innerHTML =
			'<div class="dbq"><div class="dbq-grid-pk">' + cards + '</div>' +
				'<div class="dbq-form">' +
					'<div class="dbq-row">' +
						'<label class="dbq-f"><span>Guests</span><input type="number" min="0" step="1" inputmode="numeric" name="guests" value="' + esc(state.guests) + '"></label>' +
						'<label class="dbq-f"><span>Event date</span><input type="date" name="date" min="' + minDate(48) + '" value="' + esc(state.date) + '"></label>' +
						'<label class="dbq-f"><span>Shop</span><select name="shop">' + shopOpts + '</select></label>' +
					'</div>' +
					'<div class="dbq-row"><fieldset class="dbq-f dbq-ful"><span>Fulfilment</span>' +
						'<label class="dbq-rad"><input type="radio" name="type" value="pickup"' + (state.type === 'pickup' ? ' checked' : '') + '> Pickup (free)</label>' +
						'<label class="dbq-rad"><input type="radio" name="type" value="delivery"' + (state.type === 'delivery' ? ' checked' : '') + '> Delivery</label></fieldset></div>' +
					'<div class="dbq-row">' +
						'<label class="dbq-f"><span>Your name</span><input type="text" name="name" value="' + esc(state.name) + '"></label>' +
						'<label class="dbq-f"><span>Email</span><input type="email" name="email" value="' + esc(state.email) + '"></label>' +
						'<label class="dbq-f"><span>Phone</span><input type="tel" name="phone" value="' + esc(state.phone) + '"></label>' +
					'</div>' +
					'<div class="dbq-err" role="alert" aria-live="assertive"></div>' +
				'</div>' +
				'<aside class="dbq-sum" aria-live="polite"></aside></div>';
		paintSummary();
	}
	function paintSummary() {
		var q = quote();
		var box = mount.querySelector('.dbq-sum');
		if (!box) { return; }
		var deliveryNote = state.type === 'delivery' ? '<div class="dbq-note">Delivery free within 8km · $25 to 15km (confirmed on quote)</div>' : '';
		box.innerHTML =
			'<div class="dbq-sum-h">Your quote</div>' +
			'<div class="dbq-line"><span>Package</span><strong>' + money(q.subtotal) + '</strong></div>' +
			'<div class="dbq-line"><span>Delivery</span><strong>' + (q.delivery ? money(q.delivery) : 'Free') + '</strong></div>' +
			'<div class="dbq-line dbq-tot"><span>Total</span><strong>' + money(q.total) + '</strong></div>' +
			'<div class="dbq-line dbq-dep"><span>Deposit to book (30%)</span><strong>' + money(q.deposit) + '</strong></div>' +
			'<div class="dbq-line"><span>Balance later</span><strong>' + money(q.balance) + '</strong></div>' +
			deliveryNote +
			'<div class="dbq-note">Lead time: ' + q.lead + ' hours</div>' +
			'<button type="button" class="vb-btn vb-btn-ember dbq-pay">Pay ' + money(q.deposit) + ' deposit &amp; book</button>' +
			'<div class="dbq-secure">Your quote is locked before you pay a cent.</div>';
	}
	function submit() {
		var err = mount.querySelector('.dbq-err');
		err.textContent = '';
		if (!state.name.trim() || !/.+@.+\..+/.test(state.email)) {
			err.textContent = 'Please add your name and a valid email.';
			var f = mount.querySelector('.dbq-form'); if (f && f.scrollIntoView) { f.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
			return;
		}
		var q = quote();
		var p = pkgById(state.pkg);
		var ref = 'CAT-' + new Date().toISOString().slice(2, 10).replace(/-/g, '') + '-' + Math.floor(1000 + Math.random() * 9000);
		var subject = 'Catering booking ' + ref + ' — ' + p.name;
		var bodyLines = [
			'Catering booking request', 'Reference: ' + ref, '',
			'Package: ' + p.name + ' (serves ' + p.serves + ')',
			'Guests: ' + state.guests,
			'Event date: ' + (state.date || 'to be confirmed'),
			'Fulfilment: ' + state.type, 'Shop: ' + state.shop, '',
			'Name: ' + state.name, 'Email: ' + state.email, 'Phone: ' + state.phone, '',
			'Total: ' + money(q.total), 'Deposit (30%): ' + money(q.deposit), 'Balance: ' + money(q.balance)
		];
		var mailto = 'mailto:' + ORDERS_EMAIL + '?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(bodyLines.join('\n'));
		mount.innerHTML =
			'<div class="dbq-done" role="status"><div class="dbq-check">✓</div><h3>Booking request received</h3>' +
			'<p class="dbq-ref">Reference <strong>' + esc(ref) + '</strong></p>' +
			'<p>Deposit to secure your date: <strong>' + money(q.deposit) + '</strong> · balance ' + money(q.balance) + ' due 48h before.</p>' +
			'<p>We\'ll send your quote to <strong>' + esc(state.email) + '</strong> and confirm shortly.</p>' +
			'<a class="vb-btn vb-btn-ember dbq-mailto" href="' + esc(mailto) + '">Email your booking to ' + esc(ORDERS_EMAIL) + '</a>' +
			'<p class="dbq-demo">Demo — in production this takes a card deposit via Stripe and emails your booking to ' + esc(ORDERS_EMAIL) + ' instantly.</p></div>';
		if (mount.scrollIntoView) { mount.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
	}
	mount.addEventListener('click', function (e) {
		var pk = e.target.closest('[data-pk]');
		if (pk) { state.pkg = pk.getAttribute('data-pk'); render(); return; }
		if (e.target.classList.contains('dbq-pay')) { submit(); }
	});
	mount.addEventListener('input', function (e) {
		var n = e.target.name;
		if (n === 'guests') { state.guests = e.target.value; paintSummary(); }
		else if (n === 'name') { state.name = e.target.value; }
		else if (n === 'email') { state.email = e.target.value; }
		else if (n === 'phone') { state.phone = e.target.value; }
		else if (n === 'date') { state.date = e.target.value; }
	});
	mount.addEventListener('change', function (e) {
		if (e.target.name === 'type') { state.type = e.target.value === 'delivery' ? 'delivery' : 'pickup'; paintSummary(); }
		else if (e.target.name === 'shop') { state.shop = e.target.value; }
	});
	render();
}());
