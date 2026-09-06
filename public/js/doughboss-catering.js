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
		email: '',
		name: ''
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
				'<p class="dbc-sub">Oven-baked, commission-free, deposit secures your date.</p>' +
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
			'<input class="dbc-hp" type="text" name="hp" tabindex="-1" autocomplete="off" aria-hidden="true" />' +
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
		state.name = name;

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
			notes: (fd.get('notes') || '').toString(),
			hp: (fd.get('hp') || '').toString()
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
		var canPay = pay.enabled && data.deposit > 0 && ((pay.gateway === 'tyro' && typeof window.Tyro === 'function') || (pay.gateway === 'stripe' && pay.pk && typeof window.Stripe === 'function'));

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
					'<label class="dbc-pay-label">Secure payment</label>' +
					'<div class="dbc-card-element"></div>' +
					'<div class="dbc-error" role="alert" aria-live="assertive"></div>' +
					'<button type="button" class="dbc-submit dbc-pay-btn">Pay ' + money(data.deposit) + ' deposit</button>' +
					'<p class="dbc-sub dbc-pay-secure">Secure provider-hosted payment · balance payable later.</p>' +
				'</div>'
			);
			box.appendChild(panel);
			mountPayment(panel, data);
		} else {
			box.appendChild(el('<p class="dbc-sub">' + esc(data.message || 'We\'ll be in touch shortly to confirm your quote and deposit link.') + '</p>'));
		}
		var reviewUrl = window.DoughBossData && window.DoughBossData.googleReviewUrl;
		var review = document.createElement('div');
		review.className = 'dbc-review-invite';
		var title = document.createElement('strong');
		title.textContent = 'Stay close to the bake.';
		var copy = document.createElement('span');
		copy.textContent = 'Follow Dough Boss for fresh drops, offers and what is coming out of the oven.';
		var actions = document.createElement('div');
		actions.className = 'dbc-review-invite__actions';
		var instagram = document.createElement('a');
		instagram.href = 'https://instagram.com/doughboss';
		instagram.target = '_blank';
		instagram.rel = 'noopener noreferrer';
		instagram.setAttribute('data-doughboss-engagement', 'social_engagement');
		instagram.setAttribute('data-content-name', 'Instagram');
		instagram.setAttribute('data-channel', 'catering_success');
		instagram.textContent = 'Follow @doughboss ↗';
		actions.appendChild(instagram);
		if (reviewUrl) {
			var link = document.createElement('a');
			link.href = reviewUrl;
			link.target = '_blank';
			link.rel = 'noopener noreferrer';
			link.setAttribute('data-doughboss-engagement', 'review_engagement');
			link.setAttribute('data-content-name', 'Google review');
			link.setAttribute('data-channel', 'catering_success');
			link.textContent = 'Leave a Google review ↗';
			actions.appendChild(link);
		}
		review.appendChild(title);
		review.appendChild(copy);
		review.appendChild(actions);
		box.appendChild(review);

		if (root.scrollIntoView) { root.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
	}

	function mountPayment(panel, data) {
		var pay = window.DoughBossData.payments;
		var errBox = panel.querySelector('.dbc-error');
		var stripe, stripeElements, paymentElement, tyro;
		var paymentId = '';
		var confirmedPaymentId = '';
		var stripeClientSecret = '';
		var attemptKey = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (String(Date.now()) + '-' + Math.random());
		var cardMount = panel.querySelector('.dbc-card-element');
		var cardLabel = panel.querySelector('.dbc-pay-label');
		var btn = panel.querySelector('.dbc-pay-btn');
		var payButtonLabel = 'Pay ' + money(data.deposit) + ' deposit';
		cardMount.id = 'dbc-tyro-' + String(Date.now());
		if (pay.gateway === 'tyro') {
			btn.disabled = true;
			post('/catering/payment-intent', { enquiry_number: data.enquiry_number, email: state.email, leg: 'deposit', payment_attempt_key: attemptKey }).then(function (res) {
				if (!res.ok || !res.data || !res.data.client_secret) { throw new Error((res.data && res.data.message) || 'Could not start the payment.'); }
				paymentId = res.data.payment_intent;
				tyro = window.Tyro({ liveMode: !!pay.liveMode });
				return tyro.init(res.data.client_secret);
			}).then(function () {
				var form = tyro.createPayForm({ theme: 'minimal', options: { creditCardForm: { enabled: true }, applePay: { enabled: false }, googlePay: { enabled: false } } });
				return form.inject('#' + cardMount.id);
			}).then(function () {
				btn.disabled = false;
				panel.querySelector('.dbc-pay-secure').textContent = 'Secure payment by Tyro · bank verification may appear here.';
			}).catch(function (err) { errBox.textContent = err.message || 'Card payments are unavailable right now.'; });
		} else {
			try {
				stripe = window.Stripe(pay.pk);
				cardLabel.style.display = 'none';
				cardMount.style.display = 'none';
				btn.textContent = 'Continue to payment';
			} catch (e) {
				errBox.textContent = 'Card payments are unavailable right now.';
				btn.disabled = true;
				return;
			}
		}

		function resetStripePaymentForm() {
			if (paymentElement) {
				try { paymentElement.unmount(); } catch (ignoreUnmount) {}
			}
			paymentElement = null;
			stripeElements = null;
			stripeClientSecret = '';
			paymentId = '';
			cardMount.innerHTML = '';
			cardMount.style.display = 'none';
			cardLabel.style.display = 'none';
			btn.disabled = false;
			btn.textContent = 'Continue to payment';
		}

		function prepareStripePayment() {
			btn.textContent = 'Preparing secure payment...';
			return post('/catering/payment-intent', {
				enquiry_number: data.enquiry_number,
				email: state.email,
				leg: 'deposit',
				payment_attempt_key: attemptKey
			}).then(function (res) {
				if (!res.ok || !res.data || !res.data.client_secret || !res.data.payment_intent) {
					throw new Error((res.data && res.data.message) || 'Could not start the payment.');
				}
				paymentId = res.data.payment_intent;
				stripeClientSecret = res.data.client_secret;
				stripeElements = stripe.elements({
					clientSecret: stripeClientSecret,
					appearance: {
						theme: 'stripe',
						variables: {
							colorPrimary: '#e52b2f',
							colorText: '#171717',
							borderRadius: '10px'
						}
					}
				});
				paymentElement = stripeElements.create('payment', {
					layout: {
						type: 'accordion',
						defaultCollapsed: false,
						radios: 'never',
						spacedAccordionItems: true
					},
					defaultValues: {
						billingDetails: {
							name: state.name,
							email: state.email
						}
					},
					paymentMethodOrder: ['card'],
					wallets: { applePay: 'auto', googlePay: 'auto' }
				});
				paymentElement.on('change', function (event) {
					errBox.textContent = event && event.error ? (event.error.message || 'Check your payment details.') : '';
				});
				paymentElement.on('loaderror', function (event) {
					var loadMessage = event && event.error && event.error.message ? event.error.message : 'The secure payment form could not load. Please try again.';
					resetStripePaymentForm();
					errBox.textContent = loadMessage;
				});
				paymentElement.on('ready', function () {
					try { paymentElement.focus(); } catch (ignoreFocus) {}
					if (window.innerWidth < 720 && cardMount.scrollIntoView) {
						cardMount.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
					}
					panel.querySelector('.dbc-pay-secure').textContent = 'Secure Stripe payment · Apple Pay or Google Pay appears when supported.';
					btn.disabled = false;
					btn.textContent = payButtonLabel;
				});
				cardLabel.style.display = '';
				cardMount.style.display = '';
				paymentElement.mount(cardMount);
				panel.querySelector('.dbc-pay-secure').textContent = 'Loading secure payment options...';
			}).catch(function (err) {
				resetStripePaymentForm();
				throw err;
			});
		}

		btn.addEventListener('click', function () {
			errBox.textContent = '';
			btn.disabled = true;
			var prev = btn.textContent;
			btn.textContent = confirmedPaymentId ? 'Confirming deposit...' : 'Processing...';

			if (pay.gateway === 'stripe' && !paymentElement && !confirmedPaymentId) {
				prepareStripePayment().catch(function (err) {
					errBox.textContent = err.message || 'Card payments are unavailable right now.';
					btn.disabled = false;
					btn.textContent = 'Continue to payment';
				});
				return;
			}

			var payment;
			if (confirmedPaymentId) {
				payment = Promise.resolve(confirmedPaymentId);
			} else if (pay.gateway === 'tyro') {
				payment = tyro.submitPay().then(function () {
				return tyro.fetchPayRequest();
			}).then(function (result) {
				var req = result && result.payRequest ? result.payRequest : result;
				if (!req || String(req.status).toUpperCase() !== 'SUCCESS') { throw new Error('Your payment is still being checked. Do not pay again; please wait and retry confirmation.'); }
					confirmedPaymentId = paymentId;
					return confirmedPaymentId;
				});
			} else {
				payment = stripeElements.submit().then(function (result) {
					if (result && result.error) {
						throw new Error(result.error.message || 'Check your payment details.');
					}
					rememberStripePayment(data, paymentId);
					return stripe.confirmPayment({
						elements: stripeElements,
						clientSecret: stripeClientSecret,
						confirmParams: {
							return_url: cateringPaymentReturnUrl()
						},
						redirect: 'if_required'
					});
				}).then(function (result) {
					if (result.error) { throw new Error(result.error.message || 'Your card was declined.'); }
					if (!result.paymentIntent || result.paymentIntent.id !== paymentId) {
						throw new Error('The completed payment could not be matched safely.');
					}
					confirmedPaymentId = result.paymentIntent.id;
					return confirmedPaymentId;
				});
			}

			payment.then(function (confirmedId) {
				return post('/catering/confirm-payment', {
					enquiry_number: data.enquiry_number,
					email: state.email,
					leg: 'deposit',
					payment_intent_id: confirmedId
				});
			}).then(function (conf) {
				if (!conf.ok || !conf.data || !conf.data.success) {
					throw new Error((conf.data && conf.data.message) || 'We could not confirm your payment.');
				}
				showPaid(conf.data, data);
			}).catch(function (err) {
				errBox.textContent = err.message || 'Something went wrong. Please try again.';
				btn.disabled = false;
				btn.textContent = confirmedPaymentId ? 'Confirm paid deposit' : prev;
			});
		});
	}

	function cateringPaymentReturnUrl() {
		var current = new URL(window.location.href);
		var safe = new URL(current.origin + current.pathname);
		['page_id', 'p'].forEach(function (key) {
			var value = current.searchParams.get(key);
			if (/^[1-9][0-9]*$/.test(value || '')) { safe.searchParams.set(key, value); }
		});
		if (current.searchParams.get('preview') === 'true') { safe.searchParams.set('preview', 'true'); }
		return safe.toString();
	}

	// A 3DS challenge may leave this page and return after Stripe has already
	// captured the deposit. Persist only the short-lived browser-local reference
	// needed to finish the server-side verification on return; the REST endpoint
	// still retrieves Stripe and checks the immutable enquiry/amount binding.
	function rememberStripePayment(data, paymentIntentId) {
		try {
			window.sessionStorage.setItem('doughbossCateringPending', JSON.stringify({
				enquiryNumber: data.enquiry_number,
				email: state.email,
				leg: 'deposit',
				paymentIntentId: paymentIntentId,
				savedAt: Date.now()
			}));
		} catch (ignoreStorage) {}
	}

	function clearRememberedStripePayment() {
		try { window.sessionStorage.removeItem('doughbossCateringPending'); } catch (ignoreStorage) {}
	}

	function resumeStripePaymentReturn() {
		if (!(window.DoughBossData && window.DoughBossData.payments && window.DoughBossData.payments.gateway === 'stripe') || typeof window.URLSearchParams !== 'function') {
			return false;
		}
		var params = new URLSearchParams(window.location.search);
		var returnedId = params.get('payment_intent') || '';
		if (!returnedId) {
			return false;
		}
		var pending = null;
		try { pending = JSON.parse(window.sessionStorage.getItem('doughbossCateringPending') || 'null'); } catch (ignoreStorage) {}

		// Remove Stripe's return parameters (including the client secret) from the
		// visible URL regardless of whether this tab holds the matching attempt.
		try {
			var clean = new URL(window.location.href);
			['payment_intent', 'payment_intent_client_secret', 'redirect_status'].forEach(function (key) { clean.searchParams.delete(key); });
			window.history.replaceState({}, document.title, clean.toString());
		} catch (ignoreHistory) {}

		if (!pending || pending.paymentIntentId !== returnedId || !pending.enquiryNumber || !pending.email || pending.leg !== 'deposit' || (Date.now() - Number(pending.savedAt || 0)) > 30 * 60 * 1000) {
			return false;
		}

		root.innerHTML = '<div class="dbc-builder"><p class="dbc-sub" role="status">Confirming your secure deposit payment. Please do not pay again.</p></div>';
		post('/catering/confirm-payment', {
			enquiry_number: pending.enquiryNumber,
			email: pending.email,
			leg: pending.leg,
			payment_intent_id: pending.paymentIntentId
		}).then(function (conf) {
			if (!conf.ok || !conf.data || !conf.data.success) {
				throw new Error((conf.data && conf.data.message) || 'We could not confirm your payment.');
			}
			clearRememberedStripePayment();
			showPaid(conf.data, { enquiry_number: pending.enquiryNumber });
		}).catch(function () {
			root.innerHTML = '<div class="dbc-builder"><p class="dbc-sub" role="alert">Your payment return could not be confirmed yet. Please do not pay again; contact us so we can check it safely.</p></div>';
		});
		return true;
	}

	function showPaid(conf, data) {
		clearRememberedStripePayment();
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

	if (resumeStripePaymentReturn()) {
		return;
	}

	get('/catering/packages').then(function (list) {
		state.packages = Array.isArray(list) ? list : [];
		render();
	}).catch(function () {
		root.innerHTML = '<div class="dbc-builder">' +
			'<p class="dbc-sub">We couldn\'t load packages right now. Please refresh, or call your nearest shop to book catering.</p></div>';
		render();
	});
}());
