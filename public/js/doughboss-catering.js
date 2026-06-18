/**
 * DoughBoss — catering page app.
 *
 * Hydrates [data-doughboss-catering]: loads packages, runs a live server-side
 * quote (deposit estimate), and submits a catering enquiry. Vanilla JS, no
 * build step. Reuses DoughBossData (restUrl, nonce, currency) from the main
 * storefront bundle.
 */
(function () {
	'use strict';

	var root = document.querySelector('[data-doughboss-catering]');
	if (!root) {
		return;
	}

	var DB = window.DoughBossData || {};
	var API = (DB.restUrl || '').replace(/\/$/, '');
	var NONCE = DB.nonce || '';
	var CUR = DB.currency || '$';

	var state = {
		packages: [],
		selectedId: 0,
		guests: 0,
		orderType: 'pickup',
		quote: null,
		email: ''
	};

	function money(n) {
		return CUR + Number(n || 0).toFixed(2);
	}

	function el(html) {
		var d = document.createElement('div');
		d.innerHTML = html.trim();
		return d.firstChild;
	}

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function get(path) {
		return fetch(API + path, { headers: { 'X-WP-Nonce': NONCE } }).then(function (r) { return r.json(); });
	}

	function post(path, body) {
		return fetch(API + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
			body: JSON.stringify(body)
		}).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, data: j }; }); });
	}

	function selectedPackage() {
		for (var i = 0; i < state.packages.length; i++) {
			if (state.packages[i].id === state.selectedId) { return state.packages[i]; }
		}
		return null;
	}

	/* ---------- rendering ---------- */

	function render() {
		root.innerHTML = '';
		root.appendChild(renderPackages());
		root.appendChild(renderBuilder());
	}

	function renderPackages() {
		var wrap = el('<div class="dbc-packages"></div>');
		var head = el(
			'<div class="dbc-head">' +
				'<p class="dbc-kicker">Catering</p>' +
				'<h2 class="dbc-h2">Pick a package</h2>' +
				'<p class="dbc-sub">Wood-fired, commission-free, deposit secures your date.</p>' +
			'</div>'
		);
		wrap.appendChild(head);

		if (!state.packages.length) {
			wrap.appendChild(el('<p class="dbc-empty">Catering packages are coming soon — use the form below to enquire.</p>'));
			return wrap;
		}

		var grid = el('<div class="dbc-grid"></div>');
		state.packages.forEach(function (p) {
			var serves = p.serves_min && p.serves_max ? (p.serves_min + '–' + p.serves_max + ' guests')
				: (p.serves_min ? p.serves_min + '+ guests' : '');
			var card = el(
				'<button type="button" class="dbc-card' + (p.id === state.selectedId ? ' is-selected' : '') + '" data-pick="' + p.id + '">' +
					(p.image ? '<span class="dbc-card-img" style="background-image:url(\'' + esc(p.image) + '\')"></span>' : '') +
					'<span class="dbc-card-body">' +
						'<span class="dbc-card-name">' + esc(p.name) + '</span>' +
						(serves ? '<span class="dbc-card-serves">' + esc(serves) + '</span>' : '') +
						(p.description ? '<span class="dbc-card-desc">' + esc(p.description) + '</span>' : '') +
						'<span class="dbc-card-price">' + money(p.price) + '</span>' +
					'</span>' +
				'</button>'
			);
			grid.appendChild(card);
		});
		wrap.appendChild(grid);
		return wrap;
	}

	function renderBuilder() {
		var pkg = selectedPackage();
		var wrap = el('<div class="dbc-builder"></div>');
		wrap.appendChild(el(
			'<div class="dbc-head">' +
				'<h2 class="dbc-h2">Build your quote</h2>' +
				'<p class="dbc-sub">Tell us the details and we\'ll confirm your quote and deposit link.</p>' +
			'</div>'
		));

		var form = el('<form class="dbc-form" novalidate></form>');
		form.innerHTML =
			'<div class="dbc-selected" aria-live="polite">' +
				(pkg ? 'Selected: <strong>' + esc(pkg.name) + '</strong> · ' + money(pkg.price) : 'No package selected — a custom quote will be prepared.') +
			'</div>' +
			'<div class="dbc-row">' +
				'<label class="dbc-field"><span>Guests</span><input type="number" min="0" step="1" name="guest_count" inputmode="numeric" /></label>' +
				'<label class="dbc-field"><span>Event date</span><input type="date" name="event_date" /></label>' +
				'<label class="dbc-field"><span>Event time</span><input type="time" name="event_time" /></label>' +
			'</div>' +
			'<div class="dbc-row">' +
				'<fieldset class="dbc-field dbc-ful"><span>Fulfilment</span>' +
					'<label class="dbc-radio"><input type="radio" name="order_type" value="pickup" checked /> Pickup</label>' +
					'<label class="dbc-radio"><input type="radio" name="order_type" value="delivery" /> Delivery</label>' +
				'</fieldset>' +
			'</div>' +
			'<label class="dbc-field dbc-addr" hidden><span>Delivery address</span><textarea name="address" rows="2"></textarea></label>' +
			'<div class="dbc-row">' +
				'<label class="dbc-field"><span>Your name</span><input type="text" name="customer_name" required /></label>' +
				'<label class="dbc-field"><span>Email</span><input type="email" name="customer_email" required /></label>' +
				'<label class="dbc-field"><span>Phone</span><input type="tel" name="customer_phone" /></label>' +
			'</div>' +
			'<label class="dbc-field"><span>Dietary requirements (optional)</span><textarea name="dietary" rows="2"></textarea></label>' +
			'<label class="dbc-field"><span>Notes (optional)</span><textarea name="notes" rows="2"></textarea></label>' +
			'<div class="dbc-quote" aria-live="polite"></div>' +
			'<div class="dbc-error" role="alert" aria-live="assertive"></div>' +
			'<button type="submit" class="dbc-submit">Request booking &amp; quote</button>';

		wrap.appendChild(form);
		updateQuoteBox(form);
		return wrap;
	}

	function updateQuoteBox(form) {
		var box = form.querySelector('.dbc-quote');
		if (!box) { return; }
		var q = state.quote;
		if (!q || !q.total) {
			box.innerHTML = '<span class="dbc-quote-note">Select a package and headcount to see your deposit.</span>';
			return;
		}
		var deliveryNote = state.orderType === 'delivery'
			? '<span class="dbc-quote-note">Delivery is quoted separately based on distance.</span>' : '';
		box.innerHTML =
			'<div class="dbc-quote-line"><span>Estimated total</span><strong>' + money(q.total) + '</strong></div>' +
			'<div class="dbc-quote-line dbc-quote-deposit"><span>Deposit to book (' + (q.deposit_pct || 0) + '%)</span><strong>' + money(q.deposit) + '</strong></div>' +
			'<div class="dbc-quote-line"><span>Balance later</span><strong>' + money(q.balance) + '</strong></div>' +
			deliveryNote;
	}

	function refreshQuote() {
		if (!state.selectedId) { state.quote = null; var f0 = root.querySelector('.dbc-form'); if (f0) { updateQuoteBox(f0); } return; }
		var path = '/catering/quote?package_id=' + state.selectedId +
			'&guest_count=' + (state.guests || 0) +
			'&order_type=' + encodeURIComponent(state.orderType);
		get(path).then(function (q) {
			state.quote = q;
			var f = root.querySelector('.dbc-form');
			if (f) { updateQuoteBox(f); }
		}).catch(function () { /* leave prior estimate */ });
	}

	/* ---------- interactions ---------- */

	root.addEventListener('click', function (e) {
		var pick = e.target.closest('[data-pick]');
		if (pick) {
			state.selectedId = parseInt(pick.getAttribute('data-pick'), 10) || 0;
			render();
			refreshQuote();
			var b = root.querySelector('.dbc-builder');
			if (b && b.scrollIntoView) { b.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
		}
	});

	root.addEventListener('input', function (e) {
		var t = e.target;
		if (t.name === 'guest_count') {
			state.guests = parseInt(t.value, 10) || 0;
			refreshQuote();
		}
	});

	root.addEventListener('change', function (e) {
		var t = e.target;
		if (t.name === 'order_type') {
			state.orderType = t.value === 'delivery' ? 'delivery' : 'pickup';
			var addr = root.querySelector('.dbc-addr');
			if (addr) { addr.hidden = state.orderType !== 'delivery'; }
			refreshQuote();
		}
	});

	root.addEventListener('submit', function (e) {
		if (!e.target.classList.contains('dbc-form')) { return; }
		e.preventDefault();
		var form = e.target;
		var errBox = form.querySelector('.dbc-error');
		errBox.textContent = '';

		var fd = new FormData(form);
		var name = (fd.get('customer_name') || '').toString().trim();
		var email = (fd.get('customer_email') || '').toString().trim();
		if (!name || !email) {
			errBox.textContent = 'Please add your name and a valid email.';
			return;
		}
		state.email = email;

		var btn = form.querySelector('.dbc-submit');
		btn.disabled = true;
		var prev = btn.textContent;
		btn.textContent = 'Sending…';

		post('/catering/enquiry', {
			customer_name: name,
			customer_email: email,
			customer_phone: (fd.get('customer_phone') || '').toString(),
			package_id: state.selectedId,
			guest_count: parseInt(fd.get('guest_count'), 10) || 0,
			order_type: state.orderType,
			event_date: (fd.get('event_date') || '').toString(),
			event_time: (fd.get('event_time') || '').toString(),
			address: (fd.get('address') || '').toString(),
			dietary: (fd.get('dietary') || '').toString(),
			notes: (fd.get('notes') || '').toString()
		}).then(function (res) {
			if (!res.ok || !res.data || !res.data.success) {
				var msg = res.data && res.data.message ? res.data.message : 'Something went wrong. Please try again.';
				errBox.textContent = msg;
				btn.disabled = false;
				btn.textContent = prev;
				return;
			}
			showSuccess(res.data);
		}).catch(function () {
			errBox.textContent = 'Something went wrong. Please try again.';
			btn.disabled = false;
			btn.textContent = prev;
		});
	});

	function showSuccess(data) {
		var pay = (window.DoughBossData && window.DoughBossData.payments) || {};
		var canPay = pay.enabled && pay.pk && window.Stripe && data.deposit > 0;

		root.innerHTML = '';
		var box = el('<div class="dbc-success" role="status"></div>');
		box.innerHTML =
			'<div class="dbc-success-check">✓</div>' +
			'<h2 class="dbc-h2">Enquiry received</h2>' +
			'<p class="dbc-success-num">Reference: <strong>' + esc(data.enquiry_number) + '</strong></p>';
		root.appendChild(box);

		if (canPay) {
			box.appendChild(el('<p>Secure your date with a deposit of <strong>' + money(data.deposit) + '</strong>.</p>'));
			var panel = el(
				'<div class="dbc-pay">' +
					'<label class="dbc-pay-label">Card details</label>' +
					'<div class="dbc-card-element"></div>' +
					'<div class="dbc-error" role="alert" aria-live="assertive"></div>' +
					'<button type="button" class="dbc-submit dbc-pay-btn">Pay ' + money(data.deposit) + ' deposit</button>' +
					'<p class="dbc-sub dbc-pay-secure">Secured by Stripe · balance payable later.</p>' +
				'</div>'
			);
			box.appendChild(panel);
			mountPayment(panel, data);
		} else {
			box.appendChild(el('<p class="dbc-sub">' + esc(data.message || 'We\'ll be in touch shortly to confirm your quote and deposit link.') + '</p>'));
		}

		if (root.scrollIntoView) { root.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
	}

	function mountPayment(panel, data) {
		var pay = window.DoughBossData.payments;
		var errBox = panel.querySelector('.dbc-error');
		var stripe, card;
		try {
			stripe = window.Stripe(pay.pk);
			card = stripe.elements().create('card');
			card.mount(panel.querySelector('.dbc-card-element'));
		} catch (e) {
			errBox.textContent = 'Card payments are unavailable right now.';
			return;
		}

		panel.querySelector('.dbc-pay-btn').addEventListener('click', function () {
			var btn = this;
			errBox.textContent = '';
			btn.disabled = true;
			var prev = btn.textContent;
			btn.textContent = 'Processing…';

			post('/catering/payment-intent', {
				enquiry_number: data.enquiry_number,
				email: state.email,
				leg: 'deposit'
			}).then(function (res) {
				if (!res.ok || !res.data || !res.data.client_secret) {
					throw new Error((res.data && res.data.message) || 'Could not start the payment.');
				}
				return stripe.confirmCardPayment(res.data.client_secret, { payment_method: { card: card } }).then(function (result) {
					if (result.error) { throw new Error(result.error.message || 'Your card was declined.'); }
					return post('/catering/confirm-payment', {
						enquiry_number: data.enquiry_number,
						email: state.email,
						leg: 'deposit',
						payment_intent_id: result.paymentIntent.id
					});
				});
			}).then(function (conf) {
				if (!conf.ok || !conf.data || !conf.data.success) {
					throw new Error((conf.data && conf.data.message) || 'We could not confirm your payment.');
				}
				showPaid(conf.data, data);
			}).catch(function (err) {
				errBox.textContent = err.message || 'Something went wrong. Please try again.';
				btn.disabled = false;
				btn.textContent = prev;
			});
		});
	}

	function showPaid(conf, data) {
		root.innerHTML = '';
		root.appendChild(el(
			'<div class="dbc-success" role="status">' +
				'<div class="dbc-success-check">✓</div>' +
				'<h2 class="dbc-h2">Deposit received</h2>' +
				'<p class="dbc-success-num">Reference: <strong>' + esc(data.enquiry_number) + '</strong></p>' +
				'<p class="dbc-sub">' + esc(conf.message || 'Your date is secured. We\'ll be in touch with the details.') + '</p>' +
			'</div>'
		));
		if (root.scrollIntoView) { root.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
	}

	/* ---------- boot ---------- */

	get('/catering/packages').then(function (list) {
		state.packages = Array.isArray(list) ? list : [];
		render();
	}).catch(function () {
		root.innerHTML = '<div class="dbc-builder">' +
			'<p class="dbc-sub">We couldn\'t load packages right now. Please refresh, or call your nearest shop to book catering.</p></div>';
		render();
	});
}());
