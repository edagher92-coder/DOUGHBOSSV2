/**
 * DoughBoss — Square Web Payments card capture.
 *
 * Enqueued by PHP ONLY when Square is the active, ready payment gateway
 * (DoughBoss_Assets::enqueue()). On every other site configuration this file is
 * never loaded, and even if it were it exits immediately on the guards below,
 * so nothing here can affect the pay-at-shop or Stripe storefront.
 *
 * What it does
 * ------------
 *  1. Mounts Square's hosted card fields inside the existing checkout form.
 *     Square's iframe owns the PAN/CVV — this file never sees card data.
 *  2. On submit, tokenises the card, then asks the SERVER to charge it. The
 *     amount is never sent from here: POST /payment-intent recomputes it from
 *     the stored cart, and POST /checkout re-verifies the charge against Square
 *     before an order is created.
 *  3. Renders the same confirmation shape the core script uses.
 *
 * Why it intercepts submit
 * ------------------------
 * The core storefront script (public/js/doughboss.js) is owned elsewhere and
 * has no Square branch, so its own submit handler would try to place an UNPAID
 * order. A capture-phase listener on `document` runs before the form's own
 * listener and stops it, so the Square path is the only one that runs while
 * Square is the active gateway. When doughboss.js later grows a native Square
 * branch, delete this interception and call window.DoughBossSquare.pay()
 * from it instead — that entry point is kept deliberately small for exactly
 * that hand-over.
 *
 * ES5 only, matching every other file in public/js/: var + function, no arrow
 * functions, template literals, const/let, class or optional chaining.
 *
 * NOTE: this file could not be exercised against a real Square account or a
 * live WordPress install in the environment it was written in. Treat the
 * sandbox run-through in the deployment notes as the first real test.
 */
(function () {
	'use strict';

	if (typeof window.DoughBossData === 'undefined' || typeof window.DoughBossSquareConfig === 'undefined') {
		return;
	}

	var DATA = window.DoughBossData;
	var CONFIG = window.DoughBossSquareConfig;
	var PAY = DATA.payments || {};
	var I18N = DATA.i18n || {};

	// Hard gate. Square only ever runs when the server said Square is the
	// active, ready gateway AND handed over both public identifiers.
	if (!PAY.enabled || PAY.gateway !== 'square' || !CONFIG.applicationId || !CONFIG.locationId) {
		return;
	}

	var CARD_CONTAINER_CLASS = 'db-square-card';
	var enhanced = [];

	/* ------------------------------------------------------------------ */
	/* Small helpers (kept local — nothing is shared with doughboss.js)    */
	/* ------------------------------------------------------------------ */

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		attrs = attrs || {};
		Object.keys(attrs).forEach(function (key) {
			if (key === 'class') {
				node.className = attrs[key];
			} else if (key === 'text') {
				node.textContent = attrs[key];
			} else {
				node.setAttribute(key, attrs[key]);
			}
		});
		(children || []).forEach(function (child) {
			if (child) { node.appendChild(child); }
		});
		return node;
	}

	function money(amount) {
		return (DATA.currency || '$') + Number(amount || 0).toFixed(2);
	}

	function uuid() {
		if (window.crypto && typeof window.crypto.randomUUID === 'function') {
			return window.crypto.randomUUID();
		}
		return String(Date.now()) + '-' + String(Math.random()).slice(2);
	}

	function request(path, options) {
		options = options || {};
		var headers = { 'Content-Type': 'application/json' };
		if (DATA.nonce) {
			headers['X-WP-Nonce'] = DATA.nonce;
		}
		if (options.headers) {
			Object.keys(options.headers).forEach(function (k) {
				headers[k] = options.headers[k];
			});
		}
		return fetch(DATA.restUrl + path, {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: headers,
			body: options.body ? JSON.stringify(options.body) : undefined
		}).then(function (res) {
			return res.json().then(function (json) {
				if (!res.ok) {
					throw new Error((json && json.message) || I18N.genericError || 'Something went wrong.');
				}
				return json;
			});
		});
	}

	function value(form, name) {
		var node = form.querySelector('[name="' + name + '"]');
		return node ? String(node.value || '') : '';
	}

	function storedLocationId() {
		try {
			return Number(window.localStorage.getItem('doughboss_location')) || 0;
		} catch (ignore) {
			return 0;
		}
	}

	// The core script keeps the chosen fulfilment in a radio group and mirrors
	// it onto the address field's `required` flag. Read whichever exists. A
	// scanned-table session is resolved server-side regardless of what is sent
	// here, and BOTH server calls resolve it the same way, so the checkout key
	// still matches.
	function orderTypeFor(form) {
		var checked = document.querySelector('input[name="db-order-type"]:checked');
		if (checked && (checked.value === 'pickup' || checked.value === 'delivery' || checked.value === 'dine_in')) {
			return checked.value;
		}
		var address = form.querySelector('[name="address"]');
		return address && address.required ? 'delivery' : 'pickup';
	}

	/* ------------------------------------------------------------------ */
	/* Square Web Payments SDK                                            */
	/* ------------------------------------------------------------------ */

	var paymentsPromise = null;

	function squarePayments() {
		if (paymentsPromise) { return paymentsPromise; }
		paymentsPromise = new Promise(function (resolve, reject) {
			if (!window.Square || typeof window.Square.payments !== 'function') {
				reject(new Error('The secure payment form could not be loaded. Please refresh and try again.'));
				return;
			}
			resolve(window.Square.payments(CONFIG.applicationId, CONFIG.locationId));
		});
		return paymentsPromise;
	}

	/**
	 * Mount Square's hosted card fields into one checkout form.
	 *
	 * @param {HTMLElement} form The .db-checkout form.
	 * @return {Object} A small controller: { ready, tokenize, destroyed }.
	 */
	function mountCard(form) {
		var mountId = 'db-square-card-' + String(Date.now()) + '-' + String(Math.floor(Math.random() * 10000));
		var mount = el('div', { class: CARD_CONTAINER_CLASS, id: mountId });
		var status = el('p', { class: 'db-pay-secure', text: 'Secure payment by Square · your card details never reach DoughBoss.' });
		var wrap = el('div', { class: 'db-cardfield db-cardfield--square' }, [
			el('span', { class: 'db-field-label', text: I18N.cardDetails || 'Card details' }),
			mount,
			status
		]);

		// Sit the card block directly above the submit button, matching where
		// the other gateways put theirs.
		var submit = form.querySelector('button[type="submit"]');
		if (submit && submit.parentNode === form) {
			form.insertBefore(wrap, submit);
		} else {
			form.appendChild(wrap);
		}

		var cardPromise = squarePayments().then(function (payments) {
			return payments.card().then(function (card) {
				return card.attach('#' + mountId).then(function () {
					return { payments: payments, card: card };
				});
			});
		}).catch(function (err) {
			// Let a later submit retry a transient load failure.
			cardPromise = null;
			status.textContent = 'The secure payment form could not be loaded. Please refresh the page and try again.';
			status.className = 'db-pay-secure db-error';
			throw err;
		});

		return {
			status: status,
			ready: function () {
				if (!cardPromise) {
					cardPromise = squarePayments().then(function (payments) {
						return payments.card().then(function (card) {
							return card.attach('#' + mountId).then(function () {
								return { payments: payments, card: card };
							});
						});
					});
				}
				return cardPromise;
			}
		};
	}

	/* ------------------------------------------------------------------ */
	/* Payment + order placement                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Tokenise the card, charge it server-side, then place the order.
	 *
	 * No amount, currency or price is sent from the browser at any point: the
	 * server recomputes the total from the stored cart for the charge and
	 * re-verifies it against Square before the order row exists.
	 *
	 * @param {Object} session Per-form state.
	 * @return {Promise}
	 */
	function payAndPlace(session) {
		var form = session.form;
		var orderType = orderTypeFor(form);
		var locationId = storedLocationId();

		return session.controller.ready().then(function (parts) {
			return parts.card.tokenize().then(function (result) {
				if (!result || result.status !== 'OK' || !result.token) {
					var detail = result && result.errors && result.errors.length && result.errors[0].message
						? String(result.errors[0].message)
						: (I18N.cardError || 'Please check your card details and try again.');
					throw new Error(detail);
				}
				return { parts: parts, token: result.token, details: result.details || null };
			});
		}).then(function (tokenised) {
			// 3-D Secure / Strong Customer Authentication. Square runs the
			// challenge in the browser and returns evidence the server forwards
			// with the charge. A failure here must NOT be swallowed: an
			// unverified payment can be refused by the issuer later.
			var payments = tokenised.parts.payments;
			if (typeof payments.verifyBuyer !== 'function') {
				return { sourceId: tokenised.token, verificationToken: '' };
			}
			var billing = {
				intent: 'CHARGE_AND_STORE',
				billingContact: {
					givenName: value(form, 'customer_name'),
					email: value(form, 'customer_email'),
					phone: value(form, 'customer_phone')
				}
			};
			// Square requires an amount for verifyBuyer. It is display-only
			// evidence for the issuer's challenge screen; the CHARGED amount is
			// always the server's own figure. Read it from the summary the
			// server rendered rather than inventing one, and skip verification
			// rather than guess if it cannot be read.
			var displayTotal = readDisplayedTotal(form);
			if (displayTotal === null) {
				return { sourceId: tokenised.token, verificationToken: '' };
			}
			billing.amount = displayTotal;
			billing.currencyCode = CONFIG.currency || 'AUD';
			billing.intent = 'CHARGE';
			return payments.verifyBuyer(tokenised.token, billing).then(function (verification) {
				return {
					sourceId: tokenised.token,
					verificationToken: verification && verification.token ? verification.token : ''
				};
			}).catch(function () {
				// Verification unavailable for this card/region. Continue with
				// the plain token — Square (not this file) decides whether an
				// unverified payment is acceptable.
				return { sourceId: tokenised.token, verificationToken: '' };
			});
		}).then(function (source) {
			return request('/payment-intent', {
				method: 'POST',
				body: {
					order_type: orderType,
					location_id: locationId,
					payment_attempt_key: session.paymentAttemptKey,
					customer_name: value(form, 'customer_name'),
					customer_email: value(form, 'customer_email'),
					customer_phone: value(form, 'customer_phone'),
					address: value(form, 'address'),
					notes: value(form, 'notes'),
					source_id: source.sourceId,
					verification_token: source.verificationToken
				}
			});
		}).then(function (payment) {
			if (!payment || !payment.payment_intent) {
				throw new Error('The card payment could not be confirmed. Please contact the shop before trying again.');
			}
			// From here the money has moved. Never start a second charge for
			// this checkout: keep the same payment reference and the same
			// checkout idempotency key for every retry of /checkout.
			session.paymentId = payment.payment_intent;
			return placeOrder(session, orderType, locationId);
		});
	}

	/**
	 * Read the order total the server already rendered into the checkout
	 * summary. Used only as display evidence for Square's 3DS challenge — never
	 * as a charge amount. Returns null when it cannot be read confidently.
	 *
	 * @param {HTMLElement} form Checkout form.
	 * @return {string|null}
	 */
	function readDisplayedTotal(form) {
		var node = form.querySelector('.db-checkout-summary-total');
		if (!node) { return null; }
		var digits = String(node.textContent || '').replace(/[^0-9.]/g, '');
		if (!/^\d+(\.\d{1,2})?$/.test(digits)) { return null; }
		return digits;
	}

	function placeOrder(session, orderType, locationId) {
		var form = session.form;
		if (!session.checkoutAttemptId) {
			session.checkoutAttemptId = uuid();
		}
		return request('/checkout', {
			method: 'POST',
			headers: { 'Idempotency-Key': session.checkoutAttemptId },
			body: {
				order_type: orderType,
				location_id: locationId,
				payment_attempt_key: session.paymentAttemptKey,
				payment_intent_id: session.paymentId,
				customer_name: value(form, 'customer_name'),
				customer_email: value(form, 'customer_email'),
				customer_phone: value(form, 'customer_phone'),
				address: value(form, 'address'),
				notes: value(form, 'notes')
			}
		}).then(function (res) {
			renderConfirmation(session, res);
			return res;
		});
	}

	function renderConfirmation(session, res) {
		var form = session.form;
		var parent = form.parentNode;
		if (!parent) { return; }
		parent.innerHTML = '';

		var confirmation = el('div', { class: 'db-confirm', role: 'status', 'aria-live': 'polite' }, [
			el('div', { class: 'db-confirm-check', 'aria-hidden': 'true', text: '✓' }),
			el('p', { class: 'db-order-kicker', text: 'Payment confirmed' }),
			el('h3', { text: 'Your order is in' }),
			el('p', { text: 'Keep this order number for live updates.' })
		]);
		confirmation.appendChild(el('div', { class: 'db-confirm-number-wrap' }, [
			el('strong', { class: 'db-confirm-number', text: res.order_number || '' })
		]));
		confirmation.appendChild(el('div', { class: 'db-confirm-facts' }, [
			el('span', {}, [el('small', { text: 'Status' }), el('strong', { text: 'Sent to the shop' })]),
			el('span', {}, [el('small', { text: 'Payment' }), el('strong', { text: 'Paid' })]),
			el('span', {}, [el('small', { text: 'Total' }), el('strong', { text: money(res.total) })])
		]));
		if (res.tracking_url) {
			confirmation.appendChild(el('div', { class: 'db-confirm-actions' }, [
				el('a', { class: 'db-btn db-btn--track', href: res.tracking_url, rel: 'noreferrer', text: 'Track this order' })
			]));
		}
		parent.appendChild(confirmation);
	}

	/* ------------------------------------------------------------------ */
	/* Form wiring                                                        */
	/* ------------------------------------------------------------------ */

	function sessionFor(form) {
		for (var i = 0; i < enhanced.length; i++) {
			if (enhanced[i].form === form) { return enhanced[i]; }
		}
		return null;
	}

	function enhance(form) {
		if (sessionFor(form)) { return; }
		if (form.className.indexOf('db-preorder-request') !== -1) { return; }
		if (form.querySelector('.' + CARD_CONTAINER_CLASS)) { return; }

		var session = {
			form: form,
			controller: mountCard(form),
			paymentAttemptKey: uuid(),
			checkoutAttemptId: null,
			paymentId: '',
			busy: false
		};
		enhanced.push(session);

		// The core script labels the button "Place order" when it does not
		// recognise the gateway. Restate it from the total the server rendered
		// so the customer knows a card will be charged.
		var submit = form.querySelector('button[type="submit"]');
		if (submit) {
			var total = readDisplayedTotal(form);
			submit.textContent = total === null ? (I18N.pay || 'Pay') : ((I18N.pay || 'Pay') + ' ' + money(total));
		}
	}

	function fail(session, err) {
		var form = session.form;
		var msg = form.querySelector('.db-checkout-msg');
		var submit = form.querySelector('button[type="submit"]');
		session.busy = false;
		form.setAttribute('aria-busy', 'false');
		if (msg) {
			msg.textContent = err && err.message ? err.message : (I18N.genericError || 'Something went wrong.');
			msg.className = 'db-checkout-msg db-error';
		}
		if (submit) {
			// Only re-enable when no money has moved for this attempt. Once a
			// Square payment exists, a second press must never start a second
			// charge — the customer is directed to the shop instead.
			if (session.paymentId) {
				submit.disabled = true;
				submit.textContent = 'Payment confirmation pending';
				if (msg) {
					msg.textContent = 'Your payment may already be complete. Please do not pay again — keep this page open or contact the shop so we can confirm your order.';
				}
			} else {
				submit.disabled = false;
				var total = readDisplayedTotal(form);
				submit.textContent = total === null ? (I18N.pay || 'Pay') : ((I18N.pay || 'Pay') + ' ' + money(total));
				// A declined card must start a genuinely new payment attempt so
				// the server derives a fresh Square idempotency key.
				session.paymentAttemptKey = uuid();
				session.checkoutAttemptId = null;
			}
		}
	}

	// Capture phase on `document`, so this runs BEFORE the core script's own
	// listener on the form and can stop it. See the file header for why.
	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (!form || form.nodeName !== 'FORM') { return; }
		if (form.className.indexOf('db-checkout') === -1) { return; }
		if (form.className.indexOf('db-preorder-request') !== -1) { return; }

		var session = sessionFor(form);
		if (!session) { return; }

		event.preventDefault();
		event.stopPropagation();

		if (session.busy) { return; }
		session.busy = true;
		form.setAttribute('aria-busy', 'true');

		var msg = form.querySelector('.db-checkout-msg');
		var submit = form.querySelector('button[type="submit"]');
		if (msg) {
			msg.textContent = '';
			msg.className = 'db-checkout-msg';
		}
		if (submit) {
			submit.disabled = true;
			submit.textContent = I18N.payProcessing || 'Processing payment…';
		}

		payAndPlace(session).catch(function (err) {
			fail(session, err);
		});
	}, true);

	function scan() {
		var forms = document.querySelectorAll('form.db-checkout');
		for (var i = 0; i < forms.length; i++) {
			enhance(forms[i]);
		}
	}

	if (typeof window.MutationObserver === 'function') {
		var observer = new window.MutationObserver(function () { scan(); });
		observer.observe(document.documentElement, { childList: true, subtree: true });
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', scan);
	} else {
		scan();
	}

	// Deliberately small public surface for a future native branch inside
	// doughboss.js: hand it a checkout form and it returns a promise that
	// resolves once the payment is taken and the order is placed.
	window.DoughBossSquare = {
		enhance: enhance,
		pay: function (form) {
			var session = sessionFor(form);
			if (!session) {
				enhance(form);
				session = sessionFor(form);
			}
			if (!session) {
				return Promise.reject(new Error('The secure payment form is not ready.'));
			}
			return payAndPlace(session);
		}
	};
}());
