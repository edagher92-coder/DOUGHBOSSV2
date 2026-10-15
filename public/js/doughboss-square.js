/**
 * DoughBoss — Square Web Payments card capture.
 *
 * Enqueued for the selected Square gateway. Ordinary card fields require
 * enabled checkout; approved-only mode remains dormant until the server has
 * validated a fragment capability and returned its exact public shop binding.
 * Neither mode affects the pay-at-shop or Stripe storefront.
 *
 * What it does
 * ------------
 *  1. Mounts Square's hosted card fields inside the existing checkout form.
 *     Square's iframe owns the PAN/CVV — this file never sees card data.
 *  2. On submit, tokenises the card, then asks the SERVER to charge it. The
 *     amount is never sent from here: POST /payment-intent recomputes it from
 *     the stored cart, and POST /checkout re-verifies the charge against Square
 *     before an order is created.
 *  3. Hands confirmed orders and errors to the core checkout's form-local UI.
 *
 * Why it intercepts submit
 * ------------------------
 * The core storefront script renders the shared confirmation and accessible
 * error state, but does not own Square's charge flow. A capture-phase listener
 * on `document` runs before the form's own listener and stops it, so the Square
 * payment path is the only one that runs while Square is active. It emits
 * form-local events only after its server-side work has completed.
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

	// Ordinary checkout and capability-approved checkout have separate gates.
	// A closed store may load this module, but not initialize payment fields.
	if (PAY.gateway !== 'square') {
		return;
	}
	var ordinaryEnabled = !!(PAY.enabled && CONFIG.applicationId && CONFIG.locationId);

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

	function restRequestUrl(path) {
		var queryAt = String(path).indexOf('?');
		if (queryAt !== -1 && String(DATA.restUrl).indexOf('?') !== -1) {
			return DATA.restUrl + path.slice(0, queryAt) + '&' + path.slice(queryAt + 1);
		}
		return DATA.restUrl + path;
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
		return fetch(restRequestUrl(path), {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: headers,
			body: options.body ? JSON.stringify(options.body) : undefined
		}).then(function (res) {
			return res.json().then(function (json) {
				if (!res.ok) {
					var error = new Error((json && json.message) || I18N.genericError || 'Something went wrong.');
					error.code = json && json.code ? String(json.code) : '';
					error.data = json && json.data && typeof json.data === 'object' ? json.data : {};
					throw error;
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
	var sdkPromise = null;

	function approvedSquareBinding(summary) {
		var binding = summary && summary.square;
		if (!summary || summary.gateway !== 'square' || !binding ||
			typeof binding.live_mode !== 'boolean' ||
			!/^[-A-Za-z0-9_]{5,128}$/.test(String(binding.application_id || '')) ||
			!/^[-A-Za-z0-9_]{5,128}$/.test(String(binding.location_id || ''))) {
			throw new Error('The approved shop payment details are not available. Contact DoughBoss.');
		}
		return { applicationId: binding.application_id, locationId: binding.location_id, liveMode: binding.live_mode };
	}

	function loadApprovedSquareSdk(binding) {
		var expected = binding.liveMode ? 'https://web.squarecdn.com/v1/square.js' : 'https://sandbox.web.squarecdn.com/v1/square.js';
		if (CONFIG.sdkUrl !== expected) {
			return Promise.reject(new Error('The secure payment environment changed. Please reload your approved link.'));
		}
		if (window.Square && typeof window.Square.payments === 'function') { return Promise.resolve(); }
		if (sdkPromise) { return sdkPromise; }
		sdkPromise = new Promise(function (resolve, reject) {
			var script = document.createElement('script');
			script.src = expected;
			script.async = true;
			script.onload = function () {
				if (window.Square && typeof window.Square.payments === 'function') { resolve(); }
				else { reject(new Error('The secure payment form could not be loaded.')); }
			};
			script.onerror = function () { reject(new Error('The secure payment form could not be loaded.')); };
			document.head.appendChild(script);
		});
		return sdkPromise;
	}

	function squarePayments(binding) {
		if (binding) {
			return loadApprovedSquareSdk(binding).then(function () {
				return window.Square.payments(binding.applicationId, binding.locationId);
			});
		}
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
	function mountCard(form, binding) {
		var mountId = 'db-square-card-' + String(Date.now()) + '-' + String(Math.floor(Math.random() * 10000));
		var mount = el('div', { class: CARD_CONTAINER_CLASS, id: mountId });
		var giftMountId = mountId + '-gift';
		var giftMount = el('div', { class: 'db-square-gift', id: giftMountId });
		giftMount.hidden = true;
		var method = el('select', { id: mountId + '-method', 'aria-label': 'Payment method' }, [
			el('option', { value: 'card', text: 'Debit or credit card' }),
			el('option', { value: 'gift_card', text: 'Square gift card — full amount' })
		]);
		method.value = 'card';
		var status = el('p', { class: 'db-pay-secure', text: 'Secure payment by Square · your card details never reach DoughBoss.' });
		var wrap = el('div', { class: 'db-cardfield db-cardfield--square' }, [
			el('label', { 'for': mountId + '-method', class: 'db-field-label', text: 'Payment method' }),
			method,
			el('span', { class: 'db-field-label', text: I18N.cardDetails || 'Card details' }),
			mount,
			giftMount,
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

		var cardPromise = null;
		var giftPromise = null;
		function selectedParts() {
			if (method.value !== 'gift_card') {
				if (!cardPromise) {
					cardPromise = squarePayments(binding).then(function (payments) {
						return payments.card().then(function (card) {
							return card.attach('#' + mountId).then(function () {
								return { payments: payments, card: card };
							});
						});
					}).catch(function (err) {
						// Retry only when this tender is selected again.
						cardPromise = null;
						if (method.value !== 'gift_card') {
							status.textContent = 'The secure payment form could not be loaded. Please refresh the page and try again.';
							status.className = 'db-pay-secure db-error';
						}
						throw err;
					});
				}
				return cardPromise;
			}
			if (!giftPromise) {
				giftPromise = squarePayments(binding).then(function (payments) {
					return payments.giftCard().then(function (gift) {
						return gift.attach('#' + giftMountId).then(function () {
							return { payments: payments, card: gift, kind: 'gift_card' };
						});
					});
				}).catch(function (err) {
					giftPromise = null;
					if (method.value === 'gift_card') {
						status.textContent = 'The secure gift-card form could not be loaded. Please refresh or choose card payment.';
						status.className = 'db-pay-secure db-error';
					}
					throw err;
				});
			}
			return giftPromise;
		}
		method.addEventListener('change', function () {
			var gift = method.value === 'gift_card';
			mount.hidden = gift;
			giftMount.hidden = !gift;
			status.className = 'db-pay-secure';
			status.textContent = gift ? 'Use a Square gift card issued by this seller with enough balance for the full order. Split payment is not yet available.' : 'Secure payment by Square · your card details never reach DoughBoss.';
			selectedParts().catch(function () {});
		});
		// Consume eager mount failures; a selected tender can retry on submission.
		selectedParts().catch(function () {});

		return {
			status: status,
			ready: function () {
				return selectedParts();
			}
		};
	}

	/** Gift-card GANs stay inside Square's secure element; only its nonce leaves. */
	function tokenizeTender(parts, verification) {
		var kind = parts.kind === 'gift_card' ? 'gift_card' : 'card';
		return (kind === 'gift_card' ? parts.card.tokenize() : parts.card.tokenize(verification)).then(function (result) {
			if (!result || result.status !== 'OK' || !result.token) {
				throw new Error(result && result.errors && result.errors[0] && result.errors[0].message ? String(result.errors[0].message) : 'Payment verification was not completed. Please check your payment details and try again.');
			}
			return { token: result.token, kind: kind };
		});
	}

	/* ------------------------------------------------------------------ */
	/* Payment + order placement                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Tokenise the card, charge it server-side, then place the order.
	 *
	 * The displayed amount is supplied to Square only for its tokenization-time
	 * buyer verification. The server recomputes the total from the stored cart
	 * for the charge and re-verifies it against Square before an order row exists.
	 *
	 * @param {Object} session Per-form state.
	 * @return {Promise}
	 */
	function payAndPlace(session) {
		var form = session.form;
		var orderType = orderTypeFor(form);
		var locationId = storedLocationId();
		if (session.paymentId) {
			return placeOrder(session, orderType, locationId);
		}

		// Square now performs any buyer verification while it tokenizes. Validate
		// the browser-only verification evidence before asking it to tokenize, so
		// an incomplete checkout cannot produce a card token or start a provider
		// request. The server remains the authority for the charged amount.
		var verificationDetails;
		try {
			verificationDetails = verificationDetailsFor(form);
		} catch (err) {
			return Promise.reject(err);
		}
		return session.controller.ready().then(function (parts) {
			return tokenizeTender(parts, verificationDetails);
		}).then(function (tender) {
			session.providerRequestStarted = true;
			storeSession(session);
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
					source_id: tender.token,
					tender_kind: tender.kind
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
			storeSession(session);
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

	function validSquareCurrency(currency) {
		return typeof currency === 'string' && /^[A-Z]{3}$/.test(currency) ? currency : '';
	}

	function verificationDetailsFor(form) {
		var amount = readDisplayedTotal(form);
		var currencyCode = validSquareCurrency(CONFIG.currency);
		if (amount === null || !currencyCode) {
			throw new Error(I18N.cardError || 'The payment details could not be verified. Please refresh and try again.');
		}
		return {
			amount: amount,
			billingContact: {
				givenName: value(form, 'customer_name'),
				email: value(form, 'customer_email'),
				phone: value(form, 'customer_phone')
			},
			currencyCode: currencyCode,
			intent: 'CHARGE',
			customerInitiated: true,
			sellerKeyedIn: false
		};
	}

	function approvedVerificationDetails(summary) {
		var amount = Number(summary && summary.total);
		var currency = validSquareCurrency(summary && summary.currency);
		if (!isFinite(amount) || amount <= 0 || !currency) {
			throw new Error('The approved payment amount is not available. Please reload your approved link.');
		}
		return { amount: amount.toFixed(2), currencyCode: currency, intent: 'CHARGE', customerInitiated: true, sellerKeyedIn: false };
	}

	function enhanceApproved(form, summary, capability) {
		if (sessionFor(form)) { return; }
		var requestId = Number(capability && capability.requestId);
		if (!isFinite(requestId) || requestId <= 0 || Math.floor(requestId) !== requestId || !/^[a-f0-9]{64}$/i.test(String(capability && capability.token || ''))) {
			throw new Error('A valid approved payment link is required.');
		}
		var binding = approvedSquareBinding(summary);
		var recovering = summary.can_confirm_payment === true;
		if (!recovering && summary.can_start_payment !== true) {
			throw new Error('This approved request cannot start payment.');
		}
		approvedVerificationDetails(summary);
		enhanced.push({ form: form, approved: true, summary: summary, capability: capability,
			paymentAttemptKey: uuid(), providerRequestStarted: recovering,
			controller: recovering ? null : mountCard(form, binding) });
	}

	function validApprovedSquareReceipt(receipt, summary) {
		function minorUnits(value) {
			if (typeof value !== 'number' || !isFinite(value) || value <= 0) { return null; }
			var scaled = value * 100;
			var minor = Math.round(scaled);
			if (minor > 9007199254740991 || Math.abs(scaled - minor) > 0.000001) { return null; }
			return minor;
		}
		if (!receipt || receipt.success !== true || typeof receipt.order_number !== 'string' ||
			!/^DB-[0-9]{6}-[A-Z0-9]{6}$/.test(receipt.order_number)) { return false; }
		var expected = minorUnits(summary && summary.total);
		var received = minorUnits(receipt.total);
		return expected !== null && received !== null && expected === received;
	}

	function confirmApproved(session) {
		return request('/preorder-payment/complete', { method: 'POST', body: {
			request_id: session.capability.requestId, token: session.capability.token
		} }).then(function (receipt) {
			if (!validApprovedSquareReceipt(receipt, session.summary)) {
				var error = new Error('The paid receipt could not be confirmed. Do not pay again; check the existing payment or call DoughBoss.');
				error.data = { payment_pending: true, retry_safe: false };
				throw error;
			}
			return receipt;
		});
	}

	function payApprovedSession(session) {
		// Once dispatch might have started, only the server-owned linked attempt
		// may be checked. No browser provider id, generic checkout, or new charge.
		if (session.providerRequestStarted) { return confirmApproved(session); }
		var verification;
		try { verification = approvedVerificationDetails(session.summary); }
		catch (err) { return Promise.reject(err); }
		return session.controller.ready().then(function (parts) {
			return tokenizeTender(parts, verification);
		}).then(function (tender) {
			session.providerRequestStarted = true;
			return request('/preorder-payment/start', { method: 'POST', body: {
				request_id: session.capability.requestId, token: session.capability.token,
				payment_attempt_key: session.paymentAttemptKey, source_id: tender.token, tender_kind: tender.kind
			} });
		}).then(function () { return confirmApproved(session); });
	}

	function placeOrder(session, orderType, locationId) {
		var form = session.form;
		if (!session.checkoutAttemptId) {
			session.checkoutAttemptId = uuid();
		}
		var payload = {
			order_type: orderType,
			location_id: locationId,
			customer_email: value(form, 'customer_email')
		};
		return request('/checkout', {
			method: 'POST',
			headers: { 'Idempotency-Key': session.checkoutAttemptId },
			body: {
				order_type: payload.order_type,
				location_id: payload.location_id,
				payment_attempt_key: session.paymentAttemptKey,
				payment_intent_id: session.paymentId,
				customer_name: value(form, 'customer_name'),
				customer_email: payload.customer_email,
				customer_phone: value(form, 'customer_phone'),
				address: value(form, 'address'),
				notes: value(form, 'notes')
			}
		}).then(function (res) {
			clearStoredSession();
			form.dispatchEvent(new CustomEvent('doughboss:checkout-complete', {
				detail: { response: res, payload: payload }
			}));
			return res;
		});
	}

	var SESSION_STORAGE_KEY = 'doughboss_square_attempt_v2';

	function readStoredSession() {
		try {
			var value = JSON.parse(window.sessionStorage.getItem(SESSION_STORAGE_KEY) || '{}');
			return value && typeof value === 'object' ? value : {};
		} catch (ignore) {
			return {};
		}
	}

	function storeSession(session) {
		try {
			window.sessionStorage.setItem(SESSION_STORAGE_KEY, JSON.stringify({
				paymentAttemptKey: session.paymentAttemptKey,
				checkoutAttemptId: session.checkoutAttemptId,
				paymentId: session.paymentId,
				providerRequestStarted: !!session.providerRequestStarted
			}));
		} catch (ignore) {}
	}

	function clearStoredSession() {
		try { window.sessionStorage.removeItem(SESSION_STORAGE_KEY); } catch (ignore) {}
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
		if (!ordinaryEnabled) { return; }
		if (sessionFor(form)) { return; }
		if (form.className.indexOf('db-preorder-request') !== -1) { return; }
		if (form.querySelector('.' + CARD_CONTAINER_CLASS)) { return; }

		var stored = readStoredSession();
		var session = {
			form: form,
			controller: mountCard(form),
			paymentAttemptKey: stored.paymentAttemptKey || uuid(),
			checkoutAttemptId: stored.checkoutAttemptId || null,
			paymentId: stored.paymentId || '',
			providerRequestStarted: !!stored.providerRequestStarted,
			busy: false
		};
		enhanced.push(session);

		// Keep the paid-gateway button label tied to the server-rendered total.
		var submit = form.querySelector('button[type="submit"]');
		if (submit) {
			if (session.paymentId) {
				submit.textContent = 'Check payment and place order';
			} else if (session.providerRequestStarted) {
				submit.disabled = true;
				submit.textContent = 'Payment confirmation pending';
			} else {
				var total = readDisplayedTotal(form);
				submit.textContent = total === null ? (I18N.pay || 'Pay') : ((I18N.pay || 'Pay') + ' ' + money(total));
			}
		}
	}

	function isRetrySafeOutcome(data) {
		return !!(data && data.retry_safe === true && data.payment_pending !== true);
	}

	function fail(session, err) {
		var form = session.form;
		var submit = form.querySelector('button[type="submit"]');
		var message = err && err.message ? err.message : (I18N.genericError || 'Something went wrong.');
		var data = err && err.data && typeof err.data === 'object' ? err.data : {};
		if (data.payment_intent) {
			session.paymentId = String(data.payment_intent);
		}
		var retrySafe = isRetrySafeOutcome(data);
		if (retrySafe) {
			// A matching, provider-verified FAILED/CANCELED result is the only
			// post-tokenization outcome allowed to clear a known payment reference.
			session.paymentId = '';
			session.providerRequestStarted = false;
		}
		var paymentPending = !retrySafe && ( !!data.payment_pending || !!session.providerRequestStarted || !!session.paymentId );
		session.busy = false;
		form.setAttribute('aria-busy', 'false');
		if (submit) {
			// Only re-enable when no money has moved for this attempt. Once a
			// Square payment exists, a second press must never start a second
			// charge — the customer is directed to the shop instead.
			if (paymentPending) {
				submit.disabled = !session.paymentId;
				submit.textContent = session.paymentId ? 'Check payment and place order' : 'Payment confirmation pending';
				message = 'Your payment may already be complete. Please do not pay again — keep this page open or contact the shop so we can confirm your order.';
			} else {
				submit.disabled = false;
				var total = readDisplayedTotal(form);
				submit.textContent = total === null ? (I18N.pay || 'Pay') : ((I18N.pay || 'Pay') + ' ' + money(total));
				// A declined card must start a genuinely new payment attempt so
				// the server derives a fresh Square idempotency key.
				session.paymentAttemptKey = uuid();
				session.checkoutAttemptId = null;
				session.providerRequestStarted = false;
				clearStoredSession();
			}
		}
		if (paymentPending) {
			storeSession(session);
		}
		form.dispatchEvent(new CustomEvent('doughboss:checkout-error', {
			detail: { message: message, paymentPending: paymentPending }
		}));
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
		form.dispatchEvent(new CustomEvent('doughboss:checkout-start'));
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
		enhanceApproved: enhanceApproved,
		approvedPending: function (form) { var session = sessionFor(form); return !!(session && session.approved && session.providerRequestStarted); },
		payApproved: function (form) {
			var session = sessionFor(form);
			if (!session || !session.approved) { return Promise.reject(new Error('The approved payment form is not ready.')); }
			if (session.busy) { return Promise.reject(new Error('This payment is already being checked.')); }
			session.busy = true;
			return payApprovedSession(session).then(function (result) { session.busy = false; return result; }, function (err) { session.busy = false; throw err; });
		},
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
