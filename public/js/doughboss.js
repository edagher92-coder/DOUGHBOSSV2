/**
 * DoughBoss storefront.
 *
 * Hydrates the menu, custom pizza builder, cart/checkout and order-tracking
 * shortcode containers by talking to the doughboss/v1 REST API. No framework,
 * no jQuery — just fetch and the DOM.
 */
(function () {
	'use strict';

	if (typeof window.DoughBossData === 'undefined') {
		return;
	}

	var DATA = window.DoughBossData;
	var I18N = DATA.i18n || {};
	var PAY = DATA.payments || { enabled: false, pk: '' };
	// Capture an approved-payment bearer once, then remove it from the address
	// bar before any asynchronous bootstrap or network work. The capability is
	// retained only inside this module closure and never enters a Referer URL.
	var APPROVED_PREORDER = approvedPreorderCapability();
	if (APPROVED_PREORDER) {
		try { window.history.replaceState({}, document.title, window.location.pathname + window.location.search); } catch (ignored) {}
	}
	var configCache = null;
	var locationsCache = null;
	// Share startup reads while concurrent menu, picker and cart callers wait.
	// These promises live only on this page; failed reads remain retryable.
	var configRequest = null;
	var locationsRequest = null;
	// A table QR opens the ordering page with a server-issued, HttpOnly context
	// cookie. The browser deliberately receives only the safe display context;
	// it never gets a table token or an editable table/store authority.
	var tableContextCache = null;
	var tableContextRequest = null;
	// Item count of the most recently loaded cart; lets a shop change decide
	// whether the customer needs the "re-check your cart" confirmation.
	var lastCartItemCount = 0;
	// A choice is still usable on this page when optional persistence is denied.
	var currentPageLocationId = 0;

	// Once checkout is submitted, its shop and cart snapshot must stay immutable
	// until the matching order is stored or the flow explicitly permits editing.
	var paymentMutationLock = false;
	var paymentMutationMessage = 'Your order is being confirmed. Cart, voucher, shop and fulfilment changes are temporarily locked until the order is saved.';

	// Which gateway the server enqueued a card library for ('stripe' or 'tyro').
	// Defaults to 'stripe' so older localized data behaves exactly as before.
	var GATEWAY = PAY.gateway || 'stripe';

	// Stripe card entry is hosted entirely by Stripe Checkout. The storefront
	// receives only a short-lived checkout.stripe.com URL and never loads,
	// renders or handles card fields.
	var stripeHosted = !!(PAY.enabled && GATEWAY === 'stripe');

	// Current Tyro Connect Pay browser library. It owns all card fields and 3DS.
	var tyroPay = !!(PAY.enabled && GATEWAY === 'tyro' && typeof window.Tyro === 'function');
	// Mastercard Hosted Checkout redirects card entry to the gateway page. No
	// PAN/CVV fields are ever rendered or handled by DoughBoss.
	var mpgsHosted = !!(PAY.enabled && GATEWAY === 'mpgs' && window.Checkout && typeof window.Checkout.configure === 'function');
	// Square-hosted checkout (owner setting): redirect to Square's own checkout
	// page for a server-built payment link. No card fields or SDK load here; the
	// order is created only after the server re-reads the Square payment.
	var squareHosted = !!(PAY.enabled && GATEWAY === 'square' && PAY.squareHosted === true);
	var SQUARE_HOSTED_PENDING_KEY = 'doughbossSquareHostedPending';


	/* ------------------------------------------------------------------ */
	/* Helpers                                                            */
	/* ------------------------------------------------------------------ */

	function money(amount) {
		return DATA.currency + Number(amount || 0).toFixed(2);
	}

	/* Square-hosted checkout helpers (self-contained; unit tested). */

	// Only Square's own payment-link hosts for the active mode may be opened.
	function squareHostedCheckoutUrlValid(url, liveMode) {
		if (typeof url !== 'string' || url.length > 512 || /[\s"'<>\\]/.test(url)) { return false; }
		var match = /^https:\/\/([a-z0-9.-]+)\/[^#]*$/i.exec(url);
		if (!match) { return false; }
		var host = match[1].toLowerCase();
		if (liveMode) { return host === 'square.link' || host === 'checkout.square.site'; }
		return host === 'sandbox.square.link' || host === 'sandbox.checkout.square.site' || /^[a-z0-9-]+(\.[a-z0-9-]+)*\.squareupsandbox\.com$/.test(host);
	}

	// The checkout reference Square's redirect carries back to this page.
	function squareHostedReturnKey(search) {
		// Square may append its own parameters (e.g. orderId) after ours.
		var match = /(?:^\?|&|^)doughboss_square_return=([a-f0-9]{64})(?![a-f0-9])/.exec(String(search || ''));
		return match ? match[1] : '';
	}

	// A saved hosted checkout may resume only within its 45-minute lease.
	function squareHostedPendingFresh(pending, now) {
		var age = pending && typeof pending === 'object' ? now - Number(pending.savedAt || 0) : -1;
		return !!(pending && typeof pending === 'object'
			&& /^[a-f0-9]{64}$/.test(String(pending.checkoutReference || ''))
			&& pending.payload && typeof pending.payload === 'object'
			&& /^[A-Za-z0-9._:-]{8,128}$/.test(String(pending.payload.payment_attempt_key || ''))
			&& typeof pending.checkoutAttemptId === 'string' && pending.checkoutAttemptId.length >= 16
			&& age >= 0 && age < 45 * 60 * 1000);
	}

	// Bounded confirmation: retries only "not yet confirmed" answers, never
	// anything that could start a payment. Resolves with the order receipt.
	function confirmSquareHosted(send, wait, maxAttempts) {
		var delays = [0, 2000, 3000, 5000, 8000];
		var limit = Math.max(1, Math.min(delays.length, Number(maxAttempts) || 1));
		var attempt = 0;
		var retryCodes = ['doughboss_pay_finalize_pending', 'doughboss_pay_order_unavailable', 'doughboss_pay_unavailable', 'doughboss_square_hosted_pending', 'doughboss_square_hosted_unpaid'];
		function next() {
			return wait(delays[attempt]).then(function () { return send(); }).catch(function (err) {
				attempt += 1;
				var data = err && err.data && typeof err.data === 'object' ? err.data : {};
				var retryable = data.hosted_state === 'pending' || data.hosted_state === 'unpaid'
					|| (err && retryCodes.indexOf(err.code) !== -1);
				if (!retryable || attempt >= limit) { throw err; }
				return next();
			});
		}
		return next();
	}

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		attrs = attrs || {};
		Object.keys(attrs).forEach(function (key) {
			if (key === 'class') {
				node.className = attrs[key];
			} else if (key === 'text') {
				node.textContent = attrs[key];
			} else if (key === 'html') {
				node.innerHTML = attrs[key];
			} else if (key.indexOf('data-') === 0 || key.indexOf('aria-') === 0) {
				node.setAttribute(key, attrs[key]);
			} else if (key === 'role' || key === 'tabindex' || key === 'for' || key === 'list') {
				// These have no same-named settable DOM property (it is tabIndex /
				// htmlFor, and role only reflects in newer engines), so the
				// property assignment below silently did nothing — which is why a
				// tabindex:'-1' region could never take focus.
				node.setAttribute(key, attrs[key]);
			} else {
				node[key] = attrs[key];
			}
		});
		(children || []).forEach(function (child) {
			if (child) {
				node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
			}
		});
		return node;
	}

	// Inline stroke icon. el() creates HTML elements only, so SVG needs its own
	// namespace. Decorative: hidden from assistive technology.
	function svgIcon(className, paths) {
		if (typeof document === 'undefined' || typeof document.createElementNS !== 'function') {
			return el('span', { class: className, 'aria-hidden': 'true' });
		}
		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS(ns, 'svg');
		svg.setAttribute('class', className);
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('width', '18');
		svg.setAttribute('height', '18');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('focusable', 'false');
		svg.setAttribute('fill', 'none');
		svg.setAttribute('stroke', 'currentColor');
		svg.setAttribute('stroke-width', '2');
		svg.setAttribute('stroke-linecap', 'round');
		svg.setAttribute('stroke-linejoin', 'round');
		(paths || []).forEach(function (d) {
			var path = document.createElementNS(ns, 'path');
			path.setAttribute('d', d);
			svg.appendChild(path);
		});
		return svg;
	}

	// Drinks read best as a compact two-column list without photo frames.
	function compactMenuCategory(category) {
		return /^drinks?\b/i.test(String(category || '').trim());
	}

	// While online ordering is paused the menu shows ONE sticky "Call to order"
	// bar instead of a disabled control on every card. The shortcode renders the
	// bar server-side; the live /config read reveals or hides it so a cached
	// page follows the real ordering state in either direction.
	function phoneOrderBar() {
		var phone = DATA.orderPhone || {};
		var display = typeof phone.display === 'string' && phone.display ? phone.display : '(02) 9774 2286';
		var tel = typeof phone.tel === 'string' && /^\+61[0-9]{9}$/.test(phone.tel) ? phone.tel : '+61297742286';
		return el('aside', { class: 'db-callbar', 'data-doughboss-callbar': '', 'aria-label': I18N.orderByPhone || 'Order by phone' }, [
			el('p', { class: 'db-callbar-text' }, [
				el('strong', { text: I18N.orderingPausedShort || 'Online ordering is paused.' }),
				' ',
				el('span', { text: I18N.callToOrderLead || 'Call us to order for pickup.' })
			]),
			el('a', { class: 'db-callbar-btn', href: 'tel:' + tel }, [
				svgIcon('db-callbar-icon', ['M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z']),
				el('span', { text: (I18N.callToOrder || 'Call to order') + ' ' + display })
			])
		]);
	}

	function syncPhoneOrderBar(root, paused) {
		if (typeof document === 'undefined' || typeof document.querySelector !== 'function') { return; }
		var bar = document.querySelector('[data-doughboss-callbar]');
		if (!bar && paused && root && root.parentNode) {
			bar = phoneOrderBar();
			root.parentNode.insertBefore(bar, root.nextSibling);
		}
		if (bar) { bar.hidden = !paused; }
		if (document.body && document.body.classList) {
			document.body.classList.toggle('db-has-callbar', !!(bar && paused));
		}
	}

	// Non-blocking, screen-reader-announced toast (replaces alert()). Pass ok=true
	// for the success variant (green check, polite announcement); default is the
	// error variant (assertive alert). Springs in via the .db-toast animation.
	function dbToast(message, ok) {
		var cls = ok ? 'db-toast db-toast--ok' : 'db-toast';
		var t = el('div', { class: cls, text: String(message || (I18N.genericError || 'Something went wrong.')) });
		t.setAttribute('role', ok ? 'status' : 'alert');
		t.setAttribute('aria-live', ok ? 'polite' : 'assertive');
		document.body.appendChild(t);
		setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, ok ? 2600 : 4200);
	}

	// Brief tactile "pop" on a tap target (spring scale via CSS). Safe to call on
	// any element; the class is removed after the animation so it can retrigger.
	function dbPop(node) {
		if (!node) { return; }
		node.classList.remove('db-pop');
		// Force reflow so re-adding the class restarts the animation.
		void node.offsetWidth;
		node.classList.add('db-pop');
		setTimeout(function () { node.classList.remove('db-pop'); }, 420);
	}

	// Bring a node into view and hand it keyboard focus. Used at the two moments
	// where the page changes underneath the customer but the viewport does not:
	// a failed checkout submit, and the order confirmation replacing the form.
	// Both previously rendered off-screen (measured: error at y=905 in an 844px
	// viewport; confirmation at y=-637), so the customer saw nothing happen.
	function revealNode(node, focus, block) {
		if (!node) { return; }
		try {
			var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
			if (node.scrollIntoView) {
				node.scrollIntoView(reduce ? true : { behavior: 'smooth', block: block || 'center' });
			}
		} catch (scrollUnsupported) {
			if (node.scrollIntoView) { node.scrollIntoView(); }
		}
		if (focus === false) { return; }
		// Focus after the scroll is queued so assistive tech announces the node
		// once, in its new position.
		setTimeout(function () {
			try { node.focus({ preventScroll: true }); } catch (focusUnsupported) { node.focus(); }
		}, 0);
	}

	// Turn a passive polite message into an assertive, focused, visible error,
	// keeping whatever base classes the node already carries. Pass focusNode to
	// park the caret somewhere more useful than the message itself (e.g. back in
	// the voucher input the customer now has to correct).
	function announceError(node, message, focusNode) {
		if (!node) { return; }
		node.textContent = message;
		if (node.classList) { node.classList.add('db-error'); }
		node.setAttribute('role', 'alert');
		node.setAttribute('aria-live', 'assertive');
		revealNode(node, false);
		var target = focusNode || node;
		setTimeout(function () {
			try { target.focus({ preventScroll: true }); } catch (focusUnsupported) { target.focus(); }
		}, 0);
	}

	// Return a message node to its quiet state before a fresh attempt.
	function clearError(node) {
		if (!node) { return; }
		node.textContent = '';
		if (node.classList) { node.classList.remove('db-error'); }
		node.removeAttribute('role');
		node.setAttribute('aria-live', 'polite');
	}

	// Stable DOM id for a category name, for jump-bar scroll anchors.
	function catId(category) {
		return 'db-cat-' + String(category).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
	}

	function cartItemCount(cart) {
		return (cart && Array.isArray(cart.items) ? cart.items : []).reduce(function (count, line) {
			return count + Number(line.quantity || 0);
		}, 0);
	}

	function setPaymentMutationLock(locked) {
		paymentMutationLock = !!locked;
	}

	function paymentMutationAllowed() {
		if (!paymentMutationLock) { return true; }
		dbToast(paymentMutationMessage);
		return false;
	}

	// The lightweight sitewide header uses the same immutable-checkout boundary.
	document.addEventListener('doughboss:shop-change-request', function (event) {
		if (activeTableContext() || !paymentMutationAllowed()) { event.preventDefault(); return; }
		// A carried cart must be re-checked at the new shop (2.51.0). When one is
		// known, take the change over so the header gets the same confirmation and
		// re-validation as the in-page chooser; the header follows via shop-changed.
		var requested = Number(event.detail && event.detail.id) || 0;
		if (lastCartItemCount > 0 && requested > 0 && requested !== storedLocationId() && !event.defaultPrevented) {
			event.preventDefault();
			requestShopChange(requested);
		}
	});

	// WordPress uses either pretty REST paths (/wp-json/doughboss/v1) or a
	// query-string route (index.php?rest_route=/doughboss/v1). When the endpoint
	// itself has query parameters, the latter form needs '&', not a second '?'.
	function restRequestUrl(path) {
		var queryAt = String(path).indexOf('?');
		if (queryAt !== -1 && String(DATA.restUrl).indexOf('?') !== -1) {
			return DATA.restUrl + path.slice(0, queryAt) + '&' + path.slice(queryAt + 1);
		}
		return DATA.restUrl + path;
	}

	function request(path, options) {
		options = options || {};
		var method = String(options.method || 'GET').toUpperCase();
		if (paymentMutationLock && method !== 'GET' && /^\/cart(?:\/|$)/.test(path)) {
			return Promise.reject(new Error(paymentMutationMessage));
		}
		var headers = { 'Content-Type': 'application/json' };
		// Send the REST nonce on reads as well as writes. WordPress deliberately
		// treats cookie-authenticated REST requests without a nonce as anonymous,
		// which prevents signed-in staff from using the protected migration
		// preview even though the page itself is authenticated.
		if (DATA.nonce) {
			headers['X-WP-Nonce'] = DATA.nonce;
		}
		if (options.headers) {
			Object.keys(options.headers).forEach(function (k) { headers[k] = options.headers[k]; });
		}
		return fetch(restRequestUrl(path), {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: headers,
			body: options.body ? JSON.stringify(options.body) : undefined
		}).then(function (res) {
			return res.json().then(function (json) {
				if (!res.ok) {
					var failure = new Error((json && json.message) || I18N.genericError);
					// Structured WordPress error facts (code / data such as
					// payment_pending) for flows that must never retry a payment.
					failure.code = json && typeof json.code === 'string' ? json.code : '';
					failure.data = json && json.data && typeof json.data === 'object' ? json.data : {};
					throw failure;
				}
				return json;
			});
		});
	}

	function getConfig() {
		if (configCache) {
			return Promise.resolve(configCache);
		}
		if (configRequest) {
			return configRequest;
		}
		configRequest = request('/config').then(function (cfg) {
			// Single-location mode: the storefront behaves as one pickup-only
			// shop regardless of how many locations or fulfilment types are
			// configured. Display-only — the checkout REST endpoint's own
			// enable_delivery gate rejects delivery orders server-side.
			if (cfg && cfg.single_location_mode) {
				cfg.enable_delivery = false;
			}
			configCache = cfg;
			return cfg;
		}).then(function (cfg) {
			configRequest = null;
			return cfg;
		}, function (error) {
			configRequest = null;
			throw error;
		});
		return configRequest;
	}

	function getTableContext() {
		if (tableContextRequest) {
			return tableContextRequest;
		}
		tableContextRequest = request('/table/context').then(function (context) {
			if (!context || !context.active || !context.location || !context.table) {
				tableContextCache = null;
				return null;
			}
			tableContextCache = context;
			return tableContextCache;
		});
		return tableContextRequest;
	}

	function activeTableContext() {
		return tableContextCache && tableContextCache.active ? tableContextCache : null;
	}

	function tableContextBanner(context, compact) {
		if (!context) { return null; }
		var locationName = context.location.name || 'this store';
		var tableLabel = context.table.label || 'your table';
		return el('div', {
			class: 'db-table-context' + (compact ? ' db-table-context--compact' : ''),
			role: 'status',
			'aria-live': 'polite'
		}, [
			el('span', { class: 'db-table-context-icon', 'aria-hidden': 'true', text: '\u2713' }),
			el('div', { class: 'db-table-context-copy' }, [
				el('strong', { text: 'You are ordering for ' + locationName }),
				el('span', { text: 'Table ' + tableLabel + ' \u00b7 dine in' })
			])
		]);
	}

	function notifyCartChanged() {
		document.dispatchEvent(new CustomEvent('doughboss:cart-updated'));
	}

	function trackCommerce(name, properties) {
		if (window.DoughBossMarketing && typeof window.DoughBossMarketing.track === 'function') {
			window.DoughBossMarketing.track(name, properties || {});
		}
	}

	/* ------------------------------------------------------------------ */
	/* Shops (multi-location)                                             */
	/* ------------------------------------------------------------------ */

	function getLocations() {
		if (locationsCache) {
			return Promise.resolve(locationsCache);
		}
		if (locationsRequest) {
			return locationsRequest;
		}
		locationsRequest = request('/locations').then(function (locs) {
			locs = Array.isArray(locs) ? locs : [];
			// Single-location mode pins the storefront to the first active shop:
			// the picker collapses to the single-shop display and orders carry
			// that shop's id. Note this is client-side narrowing only — keep the
			// site's location list itself trimmed to the real active shop.
			return getConfig().then(function (cfg) {
				if (cfg && cfg.single_location_mode && locs.length > 1) {
					locs = locs.slice(0, 1);
				}
				locationsCache = locs;
				return locationsCache;
			});
		}).then(function (locs) {
			locationsRequest = null;
			return locs;
		}, function (error) {
			locationsRequest = null;
			throw error;
		});
		return locationsRequest;
	}

	function locById(locs, id) {
		for (var i = 0; i < locs.length; i++) {
			if (Number(locs[i].id) === Number(id)) { return locs[i]; }
		}
		return null;
	}

	function storedLocationId() {
		if (typeof currentPageLocationId !== 'undefined' && currentPageLocationId) { return currentPageLocationId; }
		try {
			var saved = window.DoughBossPreferences ? window.DoughBossPreferences.readShop() : 0;
			if (typeof currentPageLocationId !== 'undefined') { currentPageLocationId = saved; }
			return saved;
		} catch (e) {
			return 0;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Store-first + pickup-time helpers (2.51.0). Pure unless noted.     */
	/* ------------------------------------------------------------------ */

	// The shop the customer explicitly picked: 0 when nothing valid is stored.
	// currentLocationId() still falls back to the first shop for callers that
	// only need *a* shop; the store-first gate uses this instead.
	function explicitLocationId(locs) {
		var saved = storedLocationId();
		return saved && locById(Array.isArray(locs) ? locs : [], saved) ? saved : 0;
	}

	// True when several shops exist and none has been chosen (or the stored
	// choice is no longer an active shop). One shop never needs a choice.
	function storeChoiceRequired(locs, storedId) {
		var list = Array.isArray(locs) ? locs : [];
		var stored = Number(storedId) || 0;
		if (list.length < 2) { return false; }
		if (!stored) { return true; }
		for (var i = 0; i < list.length; i++) {
			if (Number(list[i].id) === stored) { return false; }
		}
		return true;
	}

	// Open/closed badge for a shop card, from the public pickup_status read.
	function storeStatusBadge(loc) {
		var status = loc && loc.pickup_status;
		var state = status && typeof status.state === 'string' ? status.state : '';
		if (loc && loc.ordering_paused === true) { return { state: 'paused', label: 'Ordering paused' }; }
		if (state === 'open') { return { state: 'open', label: 'Open now' }; }
		if (state === 'closes_soon') { return { state: 'soon', label: 'Closing soon' }; }
		if (state === 'closed') { return { state: 'closed', label: 'Closed' }; }
		return { state: 'unknown', label: 'Check hours' };
	}

	// Toast copy after a shop change dropped items the new shop does not make.
	function revalidateMessage(removedNames) {
		var names = (Array.isArray(removedNames) ? removedNames : []).map(function (name) {
			return String(name || '').trim();
		}).filter(function (name) { return name !== ''; });
		if (!names.length) { return ''; }
		return names.length === 1
			? names[0] + ' isn’t available at this shop and was removed from your cart.'
			: names.join(', ') + ' aren’t available at this shop and were removed from your cart.';
	}

	// Accessible name for one slot button ("12:45pm" / "12:45pm, full").
	function pickupSlotLabel(slot) {
		if (!slot || typeof slot !== 'object') { return ''; }
		var label = typeof slot.label === 'string' ? slot.label.trim() : '';
		if (!label && typeof slot.local_from === 'string') { label = slot.local_from.trim(); }
		if (!label) { return ''; }
		return slot.available === false ? label + ', full' : label;
	}

	// ASAP headline: the server's own label wins; otherwise build one from the ETA.
	function pickupEtaLabel(asap) {
		if (!asap || typeof asap !== 'object') { return ''; }
		if (typeof asap.label === 'string' && asap.label.trim() !== '') { return asap.label.trim(); }
		var minutes = Math.round(Number(asap.eta_minutes));
		return minutes > 0 ? 'ASAP — ready in about ' + minutes + ' min' : '';
	}

	// "Held for 9:42" while the hold lives, "Hold expired" after.
	function pickupCountdownLabel(remainingMs) {
		var ms = Number(remainingMs);
		if (!isFinite(ms) || ms <= 0) { return 'Hold expired'; }
		var total = Math.ceil(ms / 1000);
		var seconds = total % 60;
		return 'Held for ' + Math.floor(total / 60) + ':' + (seconds < 10 ? '0' : '') + seconds;
	}

	// A selection is usable only while its server hold has not expired.
	function pickupSelectionValid(selection, nowMs) {
		if (!selection || typeof selection !== 'object') { return false; }
		if (selection.mode !== 'asap' && selection.mode !== 'scheduled') { return false; }
		if (!/^[a-f0-9]{64}$/i.test(String(selection.hold_token || ''))) { return false; }
		var expires = Date.parse(selection.expires_at_utc);
		if (!isFinite(expires)) { return false; }
		return expires > (typeof nowMs === 'number' ? nowMs : Date.now());
	}

	// Normalise a /pickup/hold response into the selection the UI keeps.
	function pickupSelectionFromHold(res, fallbackMode, slot) {
		if (!res || typeof res !== 'object' || !/^[a-f0-9]{64}$/i.test(String(res.hold_token || ''))) { return null; }
		var mode = res.pickup_mode === 'asap' || res.pickup_mode === 'scheduled'
			? res.pickup_mode
			: (fallbackMode === 'asap' ? 'asap' : 'scheduled');
		return {
			mode: mode,
			slot_id: Number(res.slot_id) || 0,
			hold_token: String(res.hold_token),
			expires_at_utc: String(res.expires_at_utc || ''),
			ready_from_utc: String(res.ready_from_utc || (slot && slot.ready_from_utc) || ''),
			ready_by_utc: String(res.ready_by_utc || ''),
			label: String(res.label || ''),
			eta_minutes: Number(res.eta_minutes) || 0
		};
	}

	// The exact checkout fields for a selection. No selection = all empty, and
	// the form then renders and sends none of them.
	function pickupHiddenFields(selection) {
		var active = !!(selection && typeof selection === 'object' && selection.hold_token);
		return {
			pickup_mode: active ? String(selection.mode || '') : '',
			pickup_slot_id: active && Number(selection.slot_id) > 0 ? String(Number(selection.slot_id)) : '',
			capacity_hold_token: active ? String(selection.hold_token) : ''
		};
	}

	// One-line summary of what is held ("Today 12:45pm" / "ASAP, about 25 min (Today 12:45pm)").
	function pickupSummaryText(selection) {
		if (!selection || typeof selection !== 'object') { return ''; }
		var label = String(selection.label || '');
		if (selection.mode === 'asap') {
			var minutes = Math.round(Number(selection.eta_minutes));
			if (minutes > 0) { return 'ASAP, about ' + minutes + ' min' + (label ? ' (' + label + ')' : ''); }
			return label ? 'ASAP (' + label + ')' : 'ASAP';
		}
		return label;
	}

	// Day to show first: the selection's day, else the first day with room.
	function pickupDefaultDay(days, selection) {
		var list = Array.isArray(days) ? days : [];
		var i;
		var j;
		if (selection && selection.mode === 'scheduled' && selection.ready_from_utc) {
			for (i = 0; i < list.length; i++) {
				var held = Array.isArray(list[i].slots) ? list[i].slots : [];
				for (j = 0; j < held.length; j++) {
					if (held[j].ready_from_utc === selection.ready_from_utc) { return String(list[i].date || ''); }
				}
			}
		}
		for (i = 0; i < list.length; i++) {
			var slots = Array.isArray(list[i].slots) ? list[i].slots : [];
			for (j = 0; j < slots.length; j++) {
				if (slots[j].available !== false) { return String(list[i].date || ''); }
			}
		}
		return list.length ? String(list[0].date || '') : '';
	}

	// Customer wording for the pickup error codes the server can return.
	function pickupErrorMessage(code, serverMessage) {
		var messages = {
			doughboss_slot_full: 'That pickup time just filled up. Please choose another time.',
			doughboss_slot_unavailable: 'That pickup time is no longer available. Please choose another time.',
			doughboss_hold_expired: 'Your pickup time hold expired. Please choose a time again.',
			doughboss_hold_stale: 'Your cart changed, so your pickup time needs refreshing. Please choose a time again.',
			doughboss_hold_used: 'That pickup time was already used for an order. Please choose a time again.',
			doughboss_pickup_required: 'Choose a pickup time first.',
			doughboss_location_paused: 'Online ordering is paused at this shop right now.',
			doughboss_pickup_off: 'Pickup times are not available right now.'
		};
		var key = String(code || '');
		return Object.prototype.hasOwnProperty.call(messages, key) ? messages[key] : String(serverMessage || '');
	}

	// Show the picker only for a pickup order at a normal, open-for-orders page.
	function pickupSchedulingActive(cfg, tableContext, orderType) {
		return !!(cfg && cfg.pickup_scheduling && cfg.ordering_open && cfg.staff_approval_required !== true && !tableContext && orderType === 'pickup');
	}

	// Confirmation line: "<shop>, <label>" (ASAP orders are marked as such).
	function pickupConfirmationText(res) {
		if (!res || (res.pickup_mode !== 'asap' && res.pickup_mode !== 'scheduled')) { return ''; }
		var where = res.location_name ? String(res.location_name) : '';
		var when = res.pickup_label ? String(res.pickup_label) : (res.pickup_mode === 'asap' ? 'ASAP' : '');
		if (when && res.pickup_mode === 'asap' && when !== 'ASAP') { when = 'ASAP · ' + when; }
		return where && when ? where + ', ' + when : (where || when);
	}

	// Tracking line: "Pickup at <shop>, <label>".
	function pickupTrackingText(order) {
		if (!order || (order.pickup_mode !== 'asap' && order.pickup_mode !== 'scheduled') || !order.pickup_label) { return ''; }
		var where = order.location_name ? ' at ' + String(order.location_name) : '';
		return 'Pickup' + where + ', ' + String(order.pickup_label) + (order.pickup_mode === 'asap' ? ' (ASAP)' : '');
	}

	// Idempotency key for /pickup/hold (server requires 16-191 characters).
	function newClientKey() {
		return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (String(Date.now()) + '-' + String(Math.random()).slice(2));
	}

	/* ------------------------------------------------------------------ */
	/* Remember my details (opt-in, this device only)                     */
	/* ------------------------------------------------------------------ */

	// Only name, email and phone are ever stored, and only after the customer
	// ticks the box. Unticking deletes them. Every storage access is guarded:
	// private browsing or blocked storage simply means nothing is remembered.
	var REMEMBER_DETAILS_FIELDS = ['customer_name', 'customer_email', 'customer_phone'];

	function rememberStorage() {
		return window.DoughBossPreferences || null;
	}

	function readRememberedDetails(storage) {
		try { return storage && typeof storage.readDetails === 'function' ? storage.readDetails() : null; } catch (ignoreRead) { return null; }
	}

	function writeRememberedDetails(storage, values) {
		try { return !!(storage && typeof storage.writeDetails === 'function' && storage.writeDetails(values)); } catch (ignoreWrite) { return false; }
	}

	function clearRememberedDetails(storage) {
		try { if (storage && typeof storage.clearDetails === 'function') { storage.clearDetails(); } } catch (ignoreClear) {}
	}

	/**
	 * Build the opt-in checkbox and wire it to the given field wrappers
	 * ({ customer_name: label, ... }). Prefills only empty inputs.
	 */
	function rememberDetailsControl(fieldWraps) {
		var storage = rememberStorage();
		var inputs = {};
		REMEMBER_DETAILS_FIELDS.forEach(function (key) {
			inputs[key] = fieldWraps[key] ? fieldWraps[key].querySelector('input,textarea') : null;
		});
		var checkbox = el('input', { type: 'checkbox', name: 'remember_details', value: '1' });
		var feedback = el('span', { role: 'status' });
		var saved = readRememberedDetails(storage);
		if (saved) {
			checkbox.checked = true;
			REMEMBER_DETAILS_FIELDS.forEach(function (key) {
				if (inputs[key] && !inputs[key].value && saved[key]) { inputs[key].value = saved[key]; }
			});
		}
		function currentValues() {
			var values = {};
			REMEMBER_DETAILS_FIELDS.forEach(function (key) { values[key] = inputs[key] ? String(inputs[key].value || '') : ''; });
			return values;
		}
		function sync() {
			if (!checkbox.checked) { return; }
			var values = currentValues();
			var permitted = false;
			try { permitted = !!(storage && typeof storage.allowed === 'function' && storage.allowed()); } catch (ignoreConsent) {}
			if (permitted && !REMEMBER_DETAILS_FIELDS.some(function (key) { return values[key].trim(); })) {
				clearRememberedDetails(storage);
				return;
			}
			if (!permitted || !writeRememberedDetails(storage, values)) {
				checkbox.checked = false;
				feedback.textContent = permitted
					? 'Details were not saved because browser storage is unavailable.'
					: 'Details were not saved. Allow optional device preferences first.';
			}
		}
		checkbox.addEventListener('change', function () {
			feedback.textContent = '';
			if (checkbox.checked) { sync(); } else { clearRememberedDetails(storage); }
		});
		document.addEventListener('doughboss:preferences-changed', function (event) {
			if (!event.detail || !event.detail.preferences) { checkbox.checked = false; }
		});
		// Save on edit (not on submit): some gateways intercept the submit event
		// before this form's own handler runs.
		REMEMBER_DETAILS_FIELDS.forEach(function (key) {
			if (inputs[key]) { inputs[key].addEventListener('change', sync); }
		});
		return el('label', { class: 'db-remember-details' }, [
			checkbox,
			el('span', { text: 'Remember my details on this device for 30 days (optional)' }), feedback
		]);
	}

	function setLocation(id, silent) {
		currentPageLocationId = Number(id) || 0;
		try {
			if (window.DoughBossPreferences) { window.DoughBossPreferences.writeShop(id); }
		} catch (e) { /* private mode — selection just isn't persisted */ }
		if (!silent) {
			document.dispatchEvent(new CustomEvent('doughboss:shop-changed', { detail: { id: Number(id) } }));
		}
	}

	// The currently chosen shop: a remembered valid choice, else the first shop.
	function currentLocationId(locs) {
		var saved = storedLocationId();
		if (saved && locById(locs, saved)) { return saved; }
		return locs.length ? Number(locs[0].id) : 0;
	}

	function menuLocationId() {
		var table = activeTableContext();
		if (table) { return Number(table.location.id); }
		// Store-first: several shops and no explicit choice = no menu scope yet.
		if (storeChoiceRequired(locationsCache || [], storedLocationId())) { return 0; }
		return currentLocationId(locationsCache || []);
	}

	// The one door for "the customer picked a shop". Honours the payment lock,
	// re-checks a carried cart against the new shop (items it does not make are
	// removed server-side), then switches. Resolves true when the shop changed.
	function requestShopChange(id) {
		id = Number(id) || 0;
		if (!id) { return Promise.resolve(false); }
		if (!paymentMutationAllowed()) { return Promise.resolve(false); }
		var previous = storedLocationId();
		if (previous === id) { return Promise.resolve(true); }
		var hasItems = lastCartItemCount > 0;
		if (hasItems && previous && typeof window.confirm === 'function'
			&& !window.confirm(I18N.changeShopConfirm || 'Changing shop re-checks your cart. Items not available at the new shop are removed.')) {
			return Promise.resolve(false);
		}
		return request('/cart/revalidate', { method: 'POST', body: { location_id: id } }).catch(function () {
			// Older servers or a transient failure: the checkout re-validates anyway.
			return null;
		}).then(function (res) {
			var removed = res && Array.isArray(res.removed) ? res.removed : [];
			setLocation(id);
			if (removed.length) {
				lastCartItemCount = Math.max(0, lastCartItemCount - removed.length);
				notifyCartChanged();
				dbToast(revalidateMessage(removed));
			}
			return true;
		});
	}

	// Refresh only the cards on shop changes. Cart lines and search state survive.
	function watchMenuAvailability(root, cards, orderingOpen, locationId) {
		var revision = 0;
		var notice = null;
		function paint(items, id) {
			var indexed = Object.create(null);
			(items || []).forEach(function (item) { indexed[item.id] = item; });
			cards.forEach(function (entry) {
				var item = indexed[entry.item.id] || Object.assign({}, entry.item, { available: false });
				var card = menuCard(item, orderingOpen && id > 0, id, !orderingOpen);
				card.setAttribute('data-search-text', entry.card.getAttribute('data-search-text') || '');
				card.hidden = entry.card.hidden;
				entry.card.parentNode.replaceChild(card, entry.card);
				entry.card = card;
				entry.item = item;
			});
		}
		root._dbMenuRefresh = function () {
			var id = menuLocationId();
			if (id === locationId) { return; }
			locationId = id;
			var pending = ++revision;
			if (activeMobileCustomizer) { releaseMobileCustomizer(activeMobileCustomizer, true); }
			paint([], id); // Stale cards cannot add while the new scope is loading.
			root.setAttribute('aria-busy', 'true');
			if (!notice) {
				notice = el('p', { role: 'status', 'aria-live': 'polite', class: 'db-menu-availability' });
				root.appendChild(notice);
			}
			notice.textContent = 'Checking availability at your selected shop…';
			request('/menu' + (id > 0 ? '?location_id=' + encodeURIComponent(id) : '')).then(function (items) {
				if (pending !== revision || id !== menuLocationId()) { return; }
				if (!Array.isArray(items) || id < 1) { throw new Error('Choose an active shop to order.'); }
				paint(items, id);
				notice.textContent = '';
				root.removeAttribute('aria-busy');
			}).catch(function () {
				if (pending !== revision) { return; }
				notice.textContent = 'Availability could not be checked. Please choose your shop again or contact the shop.';
				locationId = 0; // A deliberate same-shop retry may refresh the read.
				root.removeAttribute('aria-busy');
			});
		};
		if (!root._dbMenuShopListener) {
			root._dbMenuShopListener = true;
			document.addEventListener('doughboss:shop-changed', function () {
				if (root._dbMenuRefresh) { root._dbMenuRefresh(); }
			});
		}
		// The selected shop may have changed during the first menu response.
		if (menuLocationId() !== locationId) { root._dbMenuRefresh(); }
	}

	function shopSelect(locs, current, onChange) {
		var sel = el('select', { class: 'db-shop-select', 'aria-label': I18N.chooseShop || 'Choose your shop' });
		locs.forEach(function (loc) {
			var label = loc.suburb ? (loc.name + ' — ' + loc.suburb) : loc.name;
			var opt = el('option', { value: String(loc.id), text: label });
			if (Number(loc.id) === Number(current)) { opt.selected = true; }
			sel.appendChild(opt);
		});
		sel.addEventListener('change', function () { onChange(Number(sel.value)); });
		return sel;
	}

	function shopContact(loc, cfg) {
		var info = el('div', { class: 'db-shop-info' });
		if (window.DoughBossShopStatus) {
			var status = el('p', { class: 'db-pickup-status', role: 'status', 'aria-live': 'polite' });
			info.appendChild(status);
			window.DoughBossShopStatus.attach(status, loc, cfg);
		}
		if (loc && loc.address) { info.appendChild(el('div', { class: 'db-shop-addr', text: loc.address })); }
		if (loc && loc.phone) {
			var dial = String(loc.phone).replace(/[^0-9+]/g, '');
			info.appendChild(el(/^\+?\d{6,15}$/.test(dial) ? 'a' : 'span', {
				class: 'db-shop-phone', text: loc.phone,
				href: /^\+?\d{6,15}$/.test(dial) ? 'tel:' + dial : ''
			}));
		}
		if (cfg) {
			var pickup = !!(cfg.enable_pickup && loc && loc.pickup_enabled);
			var delivery = !!(cfg.enable_delivery && loc && loc.delivery_enabled);
			var service = !cfg.ordering_open ? 'Online ordering paused' : pickup && delivery ? 'Pickup or delivery'
				: (pickup ? 'Pickup from this shop' : (delivery ? 'Delivery available' : 'Check with the shop'));
			// The public location response does not expose online-payment
			// eligibility. Global gateway readiness is not a per-shop promise.
			var payment = !cfg.ordering_open ? 'Contact the shop' : PAY.enabled ? 'Confirmed at checkout' : (pickup && !delivery ? 'Pay when you collect' : 'Arrange with the shop');
			info.appendChild(el('div', { class: 'db-shop-context' }, [
				el('span', { class: 'db-shop-context-item' }, [el('small', { text: 'Ordering' }), el('strong', { text: service })]),
				el('span', { class: 'db-shop-context-item' }, [el('small', { text: 'Payment' }), el('strong', { text: payment })])
			]));
		}
		return info;
	}

	var storeChooserSeq = 0;

	// Store-first chooser: one card per shop (name, suburb, open/closed badge,
	// today's hours, prep time) with a large "Order here" button. `currentId`
	// marks the shop already chosen when this is used to change store.
	// `onChoose(id)` returns a Promise; `onCancel` (optional) adds a Cancel link.
	function storeChooser(locs, cfg, options) {
		options = options || {};
		storeChooserSeq += 1;
		var headingId = 'db-store-title-' + storeChooserSeq;
		var errorNode = el('p', { class: 'db-store-error', 'aria-live': 'polite', tabindex: '-1' });
		var list = el('ul', { class: 'db-store-cards' });
		var busy = false;
		(locs || []).forEach(function (loc) {
			var id = Number(loc.id);
			var current = !!options.currentId && Number(options.currentId) === id;
			var badge = storeStatusBadge(loc);
			var chooseLabel = current ? (I18N.storeCurrent || 'Your shop') : (I18N.orderHere || 'Order here');
			var button = el('button', {
				type: 'button',
				class: 'db-btn db-btn--lg db-store-choose-btn',
				text: chooseLabel,
				'aria-label': chooseLabel + ': ' + loc.name
			});
			if (current) {
				button.disabled = true;
				button.setAttribute('aria-current', 'true');
			} else {
				button.addEventListener('click', function () {
					if (busy) { return; }
					busy = true;
					clearError(errorNode);
					button.setAttribute('aria-busy', 'true');
					var pending = typeof options.onChoose === 'function' ? options.onChoose(id) : Promise.resolve(true);
					Promise.resolve(pending).then(function () {
						busy = false;
						button.removeAttribute('aria-busy');
					}).catch(function (err) {
						busy = false;
						button.removeAttribute('aria-busy');
						announceError(errorNode, (err && err.message) || (I18N.genericError || 'Something went wrong.'), button);
					});
				});
			}
			var note = el('p', { class: 'db-store-note' });
			if (window.DoughBossShopStatus) { window.DoughBossShopStatus.attach(note, loc, cfg); }
			var hours = loc.today_hours ? String(loc.today_hours) : '';
			var prep = Number(loc.prep_time) > 0 ? Math.round(Number(loc.prep_time)) : 0;
			list.appendChild(el('li', { class: 'db-store-card' + (current ? ' is-current' : '') }, [
				el('div', { class: 'db-store-card-head' }, [
					el('h3', { class: 'db-store-name', text: loc.name }),
					el('span', { class: 'db-store-badge db-store-badge--' + badge.state, text: badge.label })
				]),
				loc.suburb ? el('p', { class: 'db-store-suburb', text: loc.suburb }) : null,
				hours ? el('p', { class: 'db-store-hours' }, [
					el('span', { class: 'db-store-label', text: 'Today' }),
					el('strong', { text: hours })
				]) : null,
				prep ? el('p', { class: 'db-store-ready' }, [
					el('span', { class: 'db-store-label', text: 'Pickup' }),
					el('strong', { text: 'Ready in ~' + prep + ' min' })
				]) : null,
				note,
				button
			]));
		});
		var head = [
			el('h2', { class: 'db-store-title', id: headingId, text: options.title || I18N.chooseShop || 'Choose your shop' }),
			el('p', { class: 'db-store-sub', text: options.subtitle || 'Pick where you’ll collect from. We’ll show that shop’s menu, hours and pickup times.' })
		];
		var children = head.concat([list, errorNode]);
		if (typeof options.onCancel === 'function') {
			var cancel = el('button', { type: 'button', class: 'db-link db-store-cancel', text: I18N.cancel || 'Cancel' });
			cancel.addEventListener('click', options.onCancel);
			children.push(cancel);
		}
		return el('section', { class: 'db-store-choose', 'aria-labelledby': headingId }, children);
	}

	// Menu root while no shop is chosen: explain, and (when the page has no
	// shop-picker block of its own) offer the chooser right here. No cards.
	function renderMenuStoreGate(root, locs, cfg) {
		root.innerHTML = '';
		root.setAttribute('data-store-gate', 'true');
		root.setAttribute('data-ordering-open', 'false');
		if (root.classList) { root.classList.remove('db-menu--has-cart-fab', 'db-menu--searching'); }
		var hasPicker = !!(document.querySelector && document.querySelector('[data-doughboss-shop]'));
		root.appendChild(el('div', { class: 'db-menu-gate', role: 'status' }, [
			el('strong', { text: I18N.chooseShopMenuTitle || 'Choose your shop to see today’s menu' }),
			el('p', { text: hasPicker
				? 'Pick where you’ll collect from above and we’ll show that shop’s menu and pickup times.'
				: 'Pick where you’ll collect from and we’ll show that shop’s menu and pickup times.' })
		]));
		if (!hasPicker) { root.appendChild(storeChooser(locs, cfg, { onChoose: requestShopChange })); }
	}

	function renderShopPicker(root) {
		Promise.all([getLocations(), getConfig()]).then(function (results) {
			var locs = results[0];
			var cfg = results[1];
			root.innerHTML = '';
			var tableContext = activeTableContext();
			if (tableContext) {
				// QR table context is fixed on the server. Do not show an editable
				// shop picker that suggests a customer can change its destination.
				root.appendChild(tableContextBanner(tableContext, true));
				return;
			}
			if (!locs.length) { root.style.display = 'none'; return; }

			// Single shop: remember it silently and just show its details.
			if (locs.length === 1) {
				setLocation(locs[0].id, true);
				root.appendChild(el('div', { class: 'db-shop' }, [
					el('span', { class: 'db-shop-heading', text: 'Your shop' }),
					el('strong', { class: 'db-shop-name', text: locs[0].name }),
					shopContact(locs[0], cfg)
				]));
				return;
			}

			// Several shops: store-first. Nothing is stored silently any more; until
			// the customer picks, the whole block is the chooser.
			var shown = 0;
			var focusAfterChange = false;
			// Only a choice made in this block moves focus into the new view; a
			// change made in the header or cart must not steal it.
			function choose(id) {
				focusAfterChange = true;
				return requestShopChange(id).then(function (changed) {
					focusAfterChange = false;
					return changed;
				});
			}
			function paint(focusName) {
				root.innerHTML = '';
				shown = explicitLocationId(locs);
				if (!shown) {
					root.appendChild(storeChooser(locs, cfg, { onChoose: choose }));
					return;
				}
				var loc = locById(locs, shown);
				var name = el('strong', { class: 'db-shop-name', tabindex: '-1', text: loc.name });
				var change = el('button', { type: 'button', class: 'db-link db-shop-change', text: I18N.changeStore || 'Change store', 'aria-expanded': 'false' });
				var panel = el('div', { class: 'db-shop-switch' });
				panel.hidden = true;
				change.addEventListener('click', function () {
					if (!panel.hidden) {
						panel.hidden = true;
						change.setAttribute('aria-expanded', 'false');
						change.focus();
						return;
					}
					if (!paymentMutationAllowed()) { return; }
					panel.innerHTML = '';
					panel.appendChild(storeChooser(locs, cfg, {
						currentId: shown,
						title: I18N.changeStore || 'Change store',
						onChoose: choose,
						onCancel: function () {
							panel.hidden = true;
							change.setAttribute('aria-expanded', 'false');
							change.focus();
						}
					}));
					panel.hidden = false;
					change.setAttribute('aria-expanded', 'true');
					var first = panel.querySelector('.db-store-choose-btn:not([disabled])');
					if (first) { first.focus(); }
				});
				root.appendChild(el('div', { class: 'db-shop' }, [
					el('span', { class: 'db-shop-heading', text: I18N.yourShop || 'Your shop' }),
					name,
					change,
					panel,
					el('div', { class: 'db-shop-info-wrap', 'aria-live': 'polite' }, [shopContact(loc, cfg)])
				]));
				if (focusName) { name.focus(); }
			}
			paint(false);
			document.addEventListener('doughboss:shop-changed', function (event) {
				var id = Number(event.detail && event.detail.id);
				if (id !== shown && !paymentMutationLock && locById(locs, id)) { paint(focusAfterChange); }
			});
		}).catch(function () { root.style.display = 'none'; });
	}

	/* ------------------------------------------------------------------ */
	/* Menu                                                               */
	/* ------------------------------------------------------------------ */

	// Render only dietary claims explicitly supplied by the menu item. Keep the
	// allowlist closed so names, descriptions and option choices cannot become
	// inferred dietary claims (a gluten-free crust is an item option, not a
	// gluten-free base-item certification).
	function dietaryBadges(dietary) {
		if (!Array.isArray(dietary)) { return []; }
		var definitions = {
			vegetarian: { value: 'vegetarian', label: 'V Vegetarian' },
			vegan: { value: 'vegan', label: 'VG Vegan' },
			halal: { value: 'halal', label: 'Halal' },
			gluten_free: { value: 'gluten_free', label: 'GF Gluten-free' }
		};
		var seen = {};
		return dietary.reduce(function (badges, flag) {
			if (typeof flag !== 'string') { return badges; }
			var value = flag.trim().toLowerCase();
			if (!definitions[value] || seen[value]) { return badges; }
			seen[value] = true;
			badges.push(definitions[value]);
			return badges;
		}, []);
	}

	// The shortcode supplies a browse-only menu before JavaScript runs. Keep it
	// available whenever the interactive config or menu read cannot complete.
	function showMenuFallbackError(root, err) {
		var fallback = root.querySelector('[data-doughboss-menu-fallback]');
		var message = I18N.menuUnavailable || 'The interactive menu is unavailable. You can still browse the menu below.';
		if (!fallback) {
			root.appendChild(el('p', { class: 'db-menu-availability', role: 'status', 'aria-live': 'polite', text: 'The menu is unavailable. Please contact the shop for the menu and orders.' }));
			return;
		}
		var previous = fallback.querySelector('[data-doughboss-menu-availability]');
		if (previous && previous.parentNode) {
			previous.parentNode.removeChild(previous);
		}
		fallback.appendChild(el('p', {
			class: 'db-menu-availability',
			'data-doughboss-menu-availability': 'true',
			role: 'status',
			'aria-live': 'polite',
			text: message
		}));
	}

	function restoreMenuSearchPosition(tools) {
		var bounds = tools.getBoundingClientRect();
		var stickyTop = window.getComputedStyle ? parseFloat(window.getComputedStyle(tools).top) || 0 : 0;
		if (bounds.bottom <= stickyTop || bounds.top >= window.innerHeight) {
			window.scrollBy({ top: bounds.top - stickyTop, behavior: 'auto' });
		}
	}

	function renderMenu(root) {
		// Keep the original server-rendered nodes, not an HTML copy. A failure
		// after cards start rendering must restore the same browse-only menu.
		// The reference is kept on the root so a re-render after the store-first
		// gate (which empties the root) can still restore it.
		var fallback = root.querySelector('[data-doughboss-menu-fallback]') || root._dbMenuFallback || null;
		if (fallback) { root._dbMenuFallback = fallback; }
		Promise.all([getLocations(), getConfig()]).then(function (context) {
			var id = menuLocationId();
			// Store-first gate: several shops, none chosen, ordering open => no
			// menu request and no cards until the customer picks a shop.
			if (id < 1 && context[1] && context[1].ordering_open && !activeTableContext() && storeChoiceRequired(context[0], storedLocationId())) {
				return [null, context[1], 0, context[0]];
			}
			return request('/menu' + (id > 0 ? '?location_id=' + encodeURIComponent(id) : '')).then(function (items) {
				return [items, context[1], id];
			});
		}).then(function (results) {
			if (results[0] === null) {
				renderMenuStoreGate(root, results[3], results[1]);
				if (!root._dbGateListener) {
					root._dbGateListener = true;
					document.addEventListener('doughboss:shop-changed', function () {
						if (root.getAttribute && root.getAttribute('data-store-gate') === 'true') {
							root._dbFocusAfterGate = true;
							renderMenu(root);
						}
					});
				}
				return;
			}
			if (root.removeAttribute) { root.removeAttribute('data-store-gate'); }
			var items = results[0];
			var orderingOpen = !!results[1].ordering_open && results[2] > 0;
			// Globally paused: no per-card controls, one sticky call bar instead.
			var phoneOnly = !results[1].ordering_open;
			root.innerHTML = '';
			root.setAttribute('data-ordering-open', orderingOpen ? 'true' : 'false');
			root.classList.toggle('db-menu--phone-ordering', phoneOnly);
			syncPhoneOrderBar(root, phoneOnly);
			var tableContext = activeTableContext();
			if (tableContext) { root.appendChild(tableContextBanner(tableContext)); }
			if (!items.length) {
				root.appendChild(el('p', { class: 'db-empty', text: 'No menu items yet.' }));
				return;
			}

			// Category names are WordPress content, including valid names such as
			// "constructor" or "__proto__". They must not resolve inherited keys.
			var groups = Object.create(null);
			items.forEach(function (item) {
				(groups[item.category] = groups[item.category] || []).push(item);
			});

			var categories = Object.keys(groups);
			var preferredCategories = ['Manoush', 'Pizza', 'Pies', 'Wraps', 'Desserts', 'Drinks'];
			categories.sort(function (a, b) {
				var ai = preferredCategories.indexOf(a);
				var bi = preferredCategories.indexOf(b);
				ai = ai < 0 ? preferredCategories.length : ai;
				bi = bi < 0 ? preferredCategories.length : bi;
				return ai - bi || a.localeCompare(b);
			});

			var tools = el('div', { class: 'db-menu-tools' });
			var categoryButtons = {};
			var searchStatus = el('span', {
				class: 'db-menu-search-status',
				role: 'status',
				'aria-live': 'polite'
			});
			var search = el('input', {
				class: 'db-menu-search',
				type: 'search',
				placeholder: I18N.searchMenu || 'Search the menu',
				'aria-label': I18N.searchMenu || 'Search the menu',
				autocomplete: 'off',
				spellcheck: false
			});
			// Inline SVG: the old U+2315 glyph rendered as a missing-glyph box on
			// several platforms. The clear button appears once there is a query.
			var clearSearch = el('button', {
				class: 'db-menu-search-clear',
				type: 'button',
				hidden: true,
				'aria-label': I18N.clearSearch || 'Clear search'
			}, [svgIcon('db-menu-search-clear-icon', ['M6 6l12 12', 'M18 6L6 18'])]);
			clearSearch.addEventListener('click', function () {
				search.value = '';
				search.dispatchEvent(new Event('input'));
				search.focus();
			});
			tools.appendChild(el('div', { class: 'db-menu-search-wrap' }, [
				svgIcon('db-menu-search-icon', ['M10.5 3.5a7 7 0 1 0 0 14 7 7 0 0 0 0-14z', 'M20.5 20.5l-5-5']),
				search,
				clearSearch
			]));
			tools.appendChild(searchStatus);

			// Sticky category jump-bar: a pill per category that scrolls to its
			// section. Only worth showing when there's more than one category.
			if (categories.length > 1) {
				var jump = el('nav', { class: 'db-jumpbar', 'aria-label': I18N.menuCategories || 'Menu categories' });
				categories.forEach(function (category) {
					var targetId = catId(category);
					var pill = el('button', { class: 'db-jump', type: 'button', text: category, 'aria-controls': targetId });
					categoryButtons[targetId] = pill;
					pill.addEventListener('click', function () {
						if (search.value) {
							search.value = '';
							search.dispatchEvent(new Event('input'));
						}
						var target = root.querySelector('#' + targetId);
						if (target) {
							target.scrollIntoView({
								behavior: window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
								block: 'start'
							});
						}
					});
					jump.appendChild(pill);
				});
				tools.appendChild(jump);
			}
			root.appendChild(tools);

			// The search + category bar is sticky under the site header, so a
			// category jump has to clear BOTH or the heading lands underneath it
			// (measured before this: 92px of the heading hidden at 390px, 37px at
			// 1280px). Publish its real height for .db-category's scroll-margin-top
			// instead of guessing a constant.
			var publishToolsHeight = function () {
				var toolsHeight = Math.round(tools.getBoundingClientRect().height);
				if (toolsHeight > 0 && root.style && root.style.setProperty) {
					root.style.setProperty('--db-tools-h', toolsHeight + 'px');
				}
			};
			publishToolsHeight();
			if (window.addEventListener) {
				window.addEventListener('resize', publishToolsHeight);
				window.addEventListener('orientationchange', publishToolsHeight);
			}
			if (typeof window.ResizeObserver === 'function') {
				new window.ResizeObserver(publishToolsHeight).observe(tools);
			}

			var sections = [];
			var menuCards = [];
			categories.forEach(function (category) {
				var heading = el('h2', { class: 'db-category', id: catId(category), text: category });
				var grid = el('div', { class: compactMenuCategory(category) ? 'db-grid db-grid--compact' : 'db-grid', 'aria-labelledby': catId(category) });
				groups[category].forEach(function (item) {
					var card = menuCard(item, orderingOpen, results[2], phoneOnly);
					menuCards.push({ item: item, card: card });
					card.setAttribute('data-search-text', String(item.name || '') + ' ' + String(item.description || '') + ' ' + category);
					grid.appendChild(card);
				});
				sections.push({ heading: heading, grid: grid });
				root.appendChild(heading);
				root.appendChild(grid);
			});

			// Mark the section currently below the sticky tools without moving
			// keyboard focus or changing the customer's scroll position.
			function updateActiveCategory() {
				var threshold = tools.getBoundingClientRect().bottom + 16;
				var activeId = '';
				sections.forEach(function (section) {
					if (section.heading.hidden) { return; }
					if (!activeId || section.heading.getBoundingClientRect().top <= threshold) { activeId = section.heading.id; }
				});
				Object.keys(categoryButtons).forEach(function (id) {
					if (id === activeId) { categoryButtons[id].setAttribute('aria-current', 'location'); }
					else { categoryButtons[id].removeAttribute('aria-current'); }
				});
			}
			var categoryFramePending = false;
			function scheduleCategoryUpdate() {
				if (categoryFramePending) { return; }
				categoryFramePending = true;
				(window.requestAnimationFrame || function (callback) { callback(); })(function () {
					categoryFramePending = false;
					updateActiveCategory();
				});
			}
			window.addEventListener('scroll', scheduleCategoryUpdate, { passive: true });
			window.addEventListener('resize', scheduleCategoryUpdate);
			updateActiveCategory();
			// Homepage category cards use short hashes such as #pizza. The menu
			// arrives asynchronously, so resolve those links once headings exist.
			function followCategoryHash() {
				var hash = window.location.hash;
				var linked = sections.filter(function (section) {
					return hash === '#' + section.heading.id || hash === '#' + section.heading.id.replace(/^db-cat-/, '');
				})[0];
				if (!linked) { return; }
				if (search.value) { search.value = ''; search.dispatchEvent(new Event('input')); }
				linked.heading.scrollIntoView({ behavior: 'auto', block: 'start' });
				updateActiveCategory();
			}
			window.addEventListener('hashchange', followCategoryHash);

			searchStatus.textContent = items.length + (items.length === 1 ? ' item' : ' items');
			search.addEventListener('input', function () {
				var query = search.value.trim().toLowerCase();
				var visible = 0;
				clearSearch.hidden = !search.value;
				sections.forEach(function (section) {
					var sectionVisible = 0;
					section.grid.querySelectorAll('.db-card').forEach(function (card) {
						var matches = !query || String(card.getAttribute('data-search-text') || '').toLowerCase().indexOf(query) !== -1;
						card.hidden = !matches;
						if (matches) {
							visible += 1;
							sectionVisible += 1;
						}
					});
					section.heading.hidden = sectionVisible === 0;
					section.grid.hidden = sectionVisible === 0;
				});
				searchStatus.textContent = query
					? (visible ? visible + (visible === 1 ? ' match' : ' matches') : 'No menu matches')
					: items.length + (items.length === 1 ? ' item' : ' items');
				root.classList.toggle('db-menu--searching', !!query);
				restoreMenuSearchPosition(tools);
				updateActiveCategory();
			});
			var initialHash = window.location.hash;
			var initialScroll = window.scrollY;
			Promise.resolve(document.fonts ? document.fonts.ready : null).then(function () {
				// Fonts can change the sticky tool height. Resolve the incoming
				// link after that settles, unless the customer already moved on.
				if (window.location.hash === initialHash && window.scrollY === initialScroll) {
					publishToolsHeight();
					followCategoryHash();
				}
			});
			initCartFab(root);
			watchMenuAvailability(root, menuCards, !!results[1].ordering_open, results[2]);
			if (root._dbFocusAfterGate) {
				// The chooser that had focus is gone: land on the first category.
				root._dbFocusAfterGate = false;
				var firstHeading = root.querySelector('.db-category');
				if (firstHeading) {
					firstHeading.setAttribute('tabindex', '-1');
					try { firstHeading.focus({ preventScroll: true }); } catch (ignoreFocus) {}
				}
			}
		}).catch(function (err) {
			if (fallback && fallback.parentNode !== root) {
				root.innerHTML = '';
				root.appendChild(fallback);
			}
			root.setAttribute('data-ordering-open', 'false');
			root.classList.remove('db-menu--has-cart-fab', 'db-menu--searching');
			showMenuFallbackError(root, err);
		});
	}

	// The cart remains server-owned. This small fixed cue deliberately navigates
	// to the existing cart shortcode rather than duplicating checkout in a drawer.
	function initCartFab(root) {
		var orderingOpen = root.getAttribute('data-ordering-open') !== 'false';
		if (!orderingOpen) {
			root.classList.remove('db-menu--has-cart-fab');
			return;
		}
		var cartUrl = root.getAttribute('data-cart-url') || '';
		var cartRoot = document.querySelector('[data-doughboss-cart]');
		var fab = el('a', { class: 'db-cart-fab', href: cartUrl || '#doughboss-cart' });
		var count = el('span', { class: 'db-cart-fab-count' });
		var total = el('strong', { class: 'db-cart-fab-total' });
		var label = el('span', { class: 'db-cart-fab-label', text: I18N.viewCart || 'View cart' });
		fab.appendChild(el('span', { class: 'db-cart-fab-copy' }, [label, count]));
		fab.appendChild(total);
		root.appendChild(fab);
		var cartVisible = false;
		var hasCartItems = false;

		if (!cartUrl && cartRoot) {
			fab.addEventListener('click', function (event) {
				event.preventDefault();
				cartRoot.scrollIntoView({
					behavior: window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
					block: 'start'
				});
				var heading = cartRoot.querySelector('h1, h2, h3, h4, h5, h6, [role="heading"]');
				if (heading) { heading.setAttribute('tabindex', '-1'); }
				var focusTarget = heading || cartRoot.querySelector('input, button, select, textarea, a[href]');
				if (focusTarget) { focusTarget.focus({ preventScroll: true }); }
			});
		} else if (!cartUrl && !cartRoot) {
			// A menu-only page without a cart target cannot offer a usable route.
			// Keep the element hidden until the page author supplies cart_url.
			fab.remove();
			return;
		}

		function showCue(bump) {
			if (!hasCartItems || cartVisible) {
				fab.classList.remove('is-shown', 'db-cart-fab--bump');
				return;
			}
			fab.classList.add('is-shown');
			if (bump) {
				fab.classList.remove('db-cart-fab--bump');
				void fab.offsetWidth;
				fab.classList.add('db-cart-fab--bump');
			}
		}
		if (!cartUrl && cartRoot && typeof window.IntersectionObserver === 'function') {
			new window.IntersectionObserver(function (entries) {
				entries.forEach(function (entry) {
					if (entry.target === cartRoot) { cartVisible = entry.isIntersecting; }
				});
				showCue(false);
			}).observe(cartRoot);
		}

		function update(bump) {
			request('/cart').then(function (cart) {
				var itemCount = cartItemCount(cart);
				lastCartItemCount = itemCount;
				hasCartItems = itemCount > 0;
				if (!itemCount) {
					fab.classList.remove('is-shown', 'db-cart-fab--bump');
					root.classList.remove('db-menu--has-cart-fab');
					return;
				}
				var itemLabel = itemCount === 1 ? 'item' : (I18N.cartItems || 'items');
				count.textContent = itemCount + ' ' + itemLabel;
				total.textContent = money(cart.totals && cart.totals.total);
				fab.setAttribute('aria-label', (I18N.viewCart || 'View cart') + ': ' + count.textContent + ', ' + total.textContent);
				root.classList.add('db-menu--has-cart-fab');
				showCue(bump);
			}).catch(function () {
				// The normal menu/cart renderers show REST errors. A decorative cue
				// should fail closed and never interrupt ordering.
				hasCartItems = false;
				fab.classList.remove('is-shown', 'db-cart-fab--bump');
				root.classList.remove('db-menu--has-cart-fab');
			});
		}

		update(false);
		document.addEventListener('doughboss:cart-updated', function () { update(true); });
	}

	var activeMobileCustomizer = null;
	var customizerBackdrop = null;
	var customizerInertNodes = [];
	var customizerWasMobile = isMobileCustomizer();

	function isMobileCustomizer() {
		return !!(window.matchMedia && window.matchMedia('(max-width: 767px)').matches);
	}

	function ensureCustomizerBackdrop() {
		if (customizerBackdrop && customizerBackdrop.parentNode) { return customizerBackdrop; }
		customizerBackdrop = el('div', {
			class: 'db-customizer-backdrop',
			'aria-hidden': 'true'
		});
		customizerBackdrop.addEventListener('click', function () {
			if (activeMobileCustomizer) { activeMobileCustomizer.open = false; }
		});
		document.body.appendChild(customizerBackdrop);
		return customizerBackdrop;
	}

	function setCustomizerBackgroundInert(locked, panel) {
		if (!('inert' in HTMLElement.prototype)) { return; }
		if (!locked) {
			customizerInertNodes.forEach(function (entry) {
				if (!entry.wasInert) { entry.node.removeAttribute('inert'); }
			});
			customizerInertNodes = [];
			return;
		}
		Array.prototype.forEach.call(document.body.children, function (node) {
			if (node === panel || node === customizerBackdrop) { return; }
			if (customizerInertNodes.some(function (entry) { return entry.node === node; })) { return; }
			var wasInert = node.hasAttribute('inert');
			customizerInertNodes.push({ node: node, wasInert: wasInert });
			if (!wasInert) { node.setAttribute('inert', ''); }
		});
	}

	function releaseMobileCustomizer(details, restoreFocus) {
		var panel = details && (details._dbCustomizerPanel || details.querySelector('.db-menu-options'));
		if (panel) {
			panel.removeAttribute('role');
			panel.removeAttribute('aria-modal');
			if (panel.parentNode !== details) { details.appendChild(panel); }
		}
		if (activeMobileCustomizer !== details) { return; }
		activeMobileCustomizer = null;
		document.body.classList.remove('db-customizer-open');
		if (customizerBackdrop) { customizerBackdrop.classList.remove('is-shown'); }
		setCustomizerBackgroundInert(false);
		if (restoreFocus) {
			var summary = details.querySelector('summary');
			if (summary) { revealNode(summary, true, 'nearest'); }
		}
	}

	function activateMobileCustomizer(details) {
		if (!isMobileCustomizer() || !details || !details.open) { return; }
		var panel = details._dbCustomizerPanel || details.querySelector('.db-menu-options');
		if (!panel) { return; }
		if (activeMobileCustomizer === details) {
			setCustomizerBackgroundInert(true, panel);
			return;
		}
		if (activeMobileCustomizer && activeMobileCustomizer !== details) {
			var previous = activeMobileCustomizer;
			previous.open = false;
			releaseMobileCustomizer(previous, false);
		}
		activeMobileCustomizer = details;
		details._dbCustomizerPanel = panel;
		panel.setAttribute('role', 'dialog');
		panel.setAttribute('aria-modal', 'true');
		document.body.appendChild(panel);
		document.body.classList.add('db-customizer-open');
		ensureCustomizerBackdrop().classList.add('is-shown');
		setCustomizerBackgroundInert(true, panel);
		var close = panel.querySelector('.db-customizer-close');
		if (close) {
			setTimeout(function () {
				if (activeMobileCustomizer === details && details.open && isMobileCustomizer()) { close.focus(); }
			}, 0);
		}
	}

	document.addEventListener('keydown', function (event) {
		if (!activeMobileCustomizer || !isMobileCustomizer()) { return; }
		if (event.key === 'Escape') {
			event.preventDefault();
			activeMobileCustomizer.open = false;
			return;
		}
		if (event.key !== 'Tab') { return; }
		var panel = activeMobileCustomizer._dbCustomizerPanel || activeMobileCustomizer.querySelector('.db-menu-options');
		var focusable = panel ? Array.prototype.slice.call(panel.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])')) : [];
		if (!focusable.length) { return; }
		var first = focusable[0];
		var last = focusable[focusable.length - 1];
		if (focusable.indexOf(document.activeElement) === -1) {
			event.preventDefault();
			(event.shiftKey ? last : first).focus();
		} else if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	});

	window.addEventListener('resize', function () {
		var mobile = isMobileCustomizer();
		if (mobile === customizerWasMobile) { return; }
		customizerWasMobile = mobile;
		if (!mobile) {
			if (activeMobileCustomizer) { releaseMobileCustomizer(activeMobileCustomizer, true); }
			return;
		}
		var openDetails = Array.prototype.slice.call(document.querySelectorAll('.db-menu-customize[open]'));
		if (openDetails.length) {
			openDetails.slice(1).forEach(function (details) { details.open = false; });
			activateMobileCustomizer(openDetails[0]);
		}
	});

	// Use packaged responsive candidates only when they describe the selected
	// original. Featured uploads, older responses and malformed data fall back.
	function menuPhoto(item) {
		var media = el('div', { class: 'db-card-img' });
		// Native lazy loading must also cover featured uploads and older API
		// responses. CSS backgrounds cannot opt into native lazy loading.
		var image = el('img', { loading: 'lazy', decoding: 'async', src: item.image, alt: String(item.name || '') });
		function finish(sources) {
			media.appendChild(el('picture', { class: 'db-menu-picture' }, (sources || []).concat([image])));
			return media;
		}
		var data = item.image_responsive;
		var prefixAt = typeof item.image === 'string' ? item.image.lastIndexOf('/public/images/') : -1;
		if (!data || data.original !== item.image || prefixAt < 0
			|| !Number.isInteger(data.width) || !Number.isInteger(data.height) || data.width < 1 || data.height < 1
			|| !Array.isArray(data.sources) || !data.sources.length || data.sources.length > 2) { return finish(); }
		var prefix = item.image.slice(0, prefixAt + '/public/images/'.length);
		var sources = [];
		var seen = Object.create(null);
		for (var index = 0; index < data.sources.length; index += 1) {
			var source = data.sources[index];
			if (!source || ['image/avif', 'image/webp'].indexOf(source.type) === -1 || seen[source.type]
				|| typeof source.srcset !== 'string' || !source.srcset) { return finish(); }
			seen[source.type] = true;
			var format = source.type.split('/')[1];
			var lastWidth = 0;
			var candidates = source.srcset.split(',');
			for (var candidateIndex = 0; candidateIndex < candidates.length; candidateIndex += 1) {
				var candidate = candidates[candidateIndex].trim().match(/^(\S+) ([1-9][0-9]*)w$/);
				if (!candidate || candidate[1].indexOf(prefix) !== 0) { return finish(); }
				var file = candidate[1].slice(prefix.length).match(/^responsive\/[a-z0-9-]+-([1-9][0-9]*)\.(avif|webp)$/);
				var width = Number(candidate[2]);
				if (!file || file[2] !== format || Number(file[1]) !== width || width <= lastWidth || width > data.width) { return finish(); }
				lastWidth = width;
			}
			sources.push(el('source', { type: source.type, srcset: source.srcset, sizes: 'auto, (max-width: 359px) 80px, (max-width: 640px) calc((100vw - 58px) / 2), 320px' }));
		}
		image.setAttribute('width', data.width);
		image.setAttribute('height', data.height);
		return finish(sources);
	}

	function menuCard(item, orderingOpen, locationId, phoneOnly) {
		var soldOut = item.available === false;
		var compact = compactMenuCategory(item.category);
		var options = Array.isArray(item.options) ? item.options : [];
		var selections = {};
		var priceEl = el('span', { class: 'db-price', text: money(item.price) });
		var priceOutputs = [priceEl];
		var actionButtons = [];
		var optionInputs = [];

		function selectedOptions() {
			var selected = {};
			options.forEach(function (group) {
				var values = selections[group.id] || [];
				if (group.type === 'radio') {
					selected[group.id] = values.length ? values[0] : '';
				} else if (values.length) {
					selected[group.id] = values.slice();
				}
			});
			return selected;
		}

		function currentPrice() {
			var total = Number(item.price || 0);
			options.forEach(function (group) {
				var values = selections[group.id] || [];
				(group.choices || []).forEach(function (choice) {
					if (values.indexOf(choice.slug) !== -1) { total += Number(choice.price || 0); }
				});
			});
			return total;
		}

		function refreshPrice() {
			var label = money(currentPrice());
			priceOutputs.forEach(function (output) { output.textContent = label; });
		}

		function setActionState(label, disabled) {
			actionButtons.forEach(function (button) {
				button.textContent = label;
				button.disabled = disabled;
			});
		}

		function setOptionState(disabled) {
			optionInputs.forEach(function (input) { input.disabled = disabled; });
		}

		function addItem(sourceButton) {
			if (!locationId || locationId !== menuLocationId()) {
				dbToast('Please check availability at your selected shop before adding this item.');
				return;
			}
			var submittedOptions = selectedOptions();
			var submittedPrice = currentPrice();
			var sheet = sourceButton.closest ? sourceButton.closest('.db-customizer-sheet') : null;
			var close = sheet ? sheet.querySelector('.db-customizer-close') : null;
			if (close) { close.focus(); }
			setActionState(I18N.adding || 'Adding…', true);
			setOptionState(true);
			if (sheet) { sheet.setAttribute('aria-busy', 'true'); }
			request('/cart/add', { method: 'POST', body: { type: 'menu', item_id: item.id, location_id: locationId, options: submittedOptions, quantity: 1 } })
				.then(function () {
					setActionState(I18N.added || 'Added!', true);
					dbPop(sourceButton);
					dbToast((item.name ? item.name + ' — ' : '') + (I18N.addedToCart || 'added to cart'), true);
					trackCommerce('add_to_cart', {
						content_ids: [String(item.id)],
						content_name: item.name || 'Menu item',
						content_category: item.category || 'Menu',
						content_type: 'product',
						currency: 'AUD',
						value: submittedPrice,
						quantity: 1
					});
					notifyCartChanged();
					var details = sourceButton.closest ? sourceButton.closest('.db-menu-customize') : null;
					if (!details && activeMobileCustomizer && activeMobileCustomizer._dbCustomizerPanel === sourceButton.closest('.db-menu-options')) {
						details = activeMobileCustomizer;
					}
					if (details) { details.open = false; }
					setTimeout(function () {
						setActionState(I18N.addToCart || 'Add to cart', false);
						setOptionState(false);
						if (sheet) { sheet.removeAttribute('aria-busy'); }
					}, 1200);
				})
				.catch(function (err) {
					dbToast(err.message);
					setActionState(I18N.addToCart || 'Add to cart', false);
					setOptionState(false);
					if (sheet) { sheet.removeAttribute('aria-busy'); }
				});
		}

		function makeAddButton(className) {
			var button = el('button', {
				class: className,
				type: 'button',
				text: I18N.addToCart || 'Add to cart',
				'aria-label': (I18N.addToCart || 'Add to cart') + ': ' + item.name
			});
			actionButtons.push(button);
			button.addEventListener('click', function () { addItem(button); });
			return button;
		}

		function optionControls() {
			if (!options.length || phoneOnly) { return null; }
			if (!orderingOpen) {
				return el('p', {
					class: 'db-card-customize-note',
					text: I18N.customizationAvailable || 'Customise when ordering opens'
				});
			}
			var titleId = 'db-customizer-title-' + String(item.id).replace(/[^a-z0-9_-]/gi, '-');
			var closeButton = el('button', {
				class: 'db-customizer-close',
				type: 'button',
				text: '×',
				'aria-label': I18N.closeCustomizer || 'Close customization'
			});
			var header = el('div', { class: 'db-customizer-head' }, [
				el('strong', { id: titleId, text: (I18N.customize || 'Customize') + ' ' + item.name }),
				closeButton
			]);
			var scroll = el('div', { class: 'db-customizer-scroll' });
			var sheetPrice = el('span', { class: 'db-customizer-price', text: money(item.price), 'aria-live': 'polite' });
			priceOutputs.push(sheetPrice);
			var footer = el('div', { class: 'db-customizer-foot' }, [
				el('span', { class: 'db-customizer-total' }, [
					el('small', { text: I18N.itemTotal || 'Item total' }),
					sheetPrice
				]),
				makeAddButton('db-btn db-customizer-add')
			]);
			var controls = el('div', { class: 'db-menu-options db-customizer-sheet', 'aria-labelledby': titleId }, [ header, scroll, footer ]);
			options.forEach(function (group, groupIndex) {
				var choices = Array.isArray(group.choices) ? group.choices : [];
				var defaultChoice = choices.filter(function (choice) { return choice.default; })[0];
				selections[group.id] = group.type === 'radio' && defaultChoice ? [defaultChoice.slug] : [];
				// Style and Crust are the two compact, mutually-exclusive menu choices
				// that benefit from a segmented presentation. Keep the native radios and
				// their names intact so keyboard selection and submitted slugs are unchanged.
				var segmented = group.type === 'radio' && (group.id === 'style' || group.id === 'crust');
				var fieldset = el('fieldset', { class: 'db-menu-option-group' + (segmented ? ' db-menu-option-group--segmented' : '') });
				fieldset.appendChild(el('legend', { text: group.label || 'Options' }));
				choices.forEach(function (choice, choiceIndex) {
					var input = el('input', { type: group.type === 'check' ? 'checkbox' : 'radio', name: 'db-option-' + item.id + '-' + groupIndex, value: choice.slug, disabled: !orderingOpen });
					optionInputs.push(input);
					input.checked = group.type === 'radio' ? choiceIndex === choices.indexOf(defaultChoice || choices[0]) : false;
					input.addEventListener('change', function () {
						var values = selections[group.id] || [];
						if (group.type === 'radio') { selections[group.id] = input.checked ? [choice.slug] : []; }
						else if (input.checked && values.indexOf(choice.slug) === -1) { values.push(choice.slug); selections[group.id] = values; }
						else if (!input.checked) { selections[group.id] = values.filter(function (value) { return value !== choice.slug; }); }
						refreshPrice();
					});
					var suffix = Number(choice.price || 0) ? (Number(choice.price) > 0 ? '+' : '') + money(choice.price) : '';
					fieldset.appendChild(el('label', { class: 'db-menu-option' + (segmented ? ' db-menu-option--segment' : '') }, [ input, el('span', { text: choice.label }), suffix ? el('span', { class: 'db-option-price', text: suffix }) : null ]));
				});
				scroll.appendChild(fieldset);
			});
			var details = el('details', { class: 'db-menu-customize' }, [
				el('summary', { text: I18N.customize || 'Customize' }),
				controls
			]);
			closeButton.addEventListener('click', function () { details.open = false; });
			details.addEventListener('toggle', function () {
				if (details.open) { activateMobileCustomizer(details); }
				else { releaseMobileCustomizer(details, true); }
			});
			return details;
		}

		var categoryLabel = String(item.category || 'Dough Boss');
		var categoryKey = categoryLabel.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'menu';
		function placeholderMedia() {
			return el('div', { class: 'db-card-img db-card-img--placeholder', 'data-category': categoryKey }, [
				el('span', { class: 'db-card-placeholder-kicker', text: 'Dough Boss · Oven-baked' }),
				el('span', { class: 'db-card-placeholder-category', text: categoryLabel })
			]);
		}
		var media = compact ? null : (item.image ? menuPhoto(item) : placeholderMedia());
		// A photo that fails to load becomes the warm branded tile, never a grey box.
		var photo = media && media.querySelector ? media.querySelector('img') : null;
		if (photo && photo.addEventListener) {
			photo.addEventListener('error', function () {
				var replacement = placeholderMedia();
				if (soldOut) { replacement.appendChild(el('span', { class: 'db-soldout-badge', text: I18N.soldOut || 'Sold out' })); }
				if (media.parentNode) { media.parentNode.replaceChild(replacement, media); }
				media = replacement;
			});
		}
		if (soldOut && media) {
			media.appendChild(el('span', { class: 'db-soldout-badge', text: I18N.soldOut || 'Sold out' }));
		}

		var action;
		if (phoneOnly) {
			// Paused: the single sticky call bar replaces every per-card control.
			action = soldOut && !media ? el('span', { class: 'db-soldout-badge db-soldout-badge--inline', text: I18N.soldOut || 'Sold out' }) : null;
		} else if (soldOut) {
			action = el('button', { class: 'db-btn', type: 'button', text: I18N.soldOut || 'Sold out', disabled: true });
		} else if (!orderingOpen) {
			action = el('button', {
				class: 'db-btn db-btn--coming-soon',
				type: 'button',
				text: I18N.comingSoonShort || 'Ordering paused',
				disabled: true,
				'aria-label': (I18N.orderingComingSoon || 'Online ordering is paused') + ': ' + item.name
			});
		} else {
			action = makeAddButton('db-btn');
		}

		var controls = optionControls();
		var badges = dietaryBadges(item.dietary).map(function (badge) {
			return el('span', {
				class: 'db-dietary-badge db-dietary-badge--' + badge.value,
				'aria-label': badge.label,
				'data-dietary': badge.value,
				text: badge.label
			});
		});
		var badgeWrap = badges.length ? el('div', { class: 'db-dietary-badges', 'aria-label': 'Dietary information' }, badges) : null;
		refreshPrice();
		var cardClass = 'db-card' + (soldOut ? ' db-card--soldout' : '') + (compact ? ' db-card--compact' : '');
		return el('div', { class: cardClass }, [
			media,
			el('div', { class: 'db-card-body' }, [
				el('h3', { class: 'db-card-title', text: item.name }),
				item.description ? el('p', { class: 'db-card-desc', text: item.description }) : null,
				badgeWrap,
				controls,
				el('div', { class: 'db-card-foot' }, [
					priceEl,
					action
				])
			])
		]);
	}

	/* ------------------------------------------------------------------ */
	/* Pizza builder                                                      */
	/* ------------------------------------------------------------------ */

	function renderBuilder(root) {
		getConfig().then(function (cfg) {
			root.innerHTML = '';
			var tableContext = activeTableContext();
			if (tableContext) { root.appendChild(tableContextBanner(tableContext)); }
			if (!cfg.sizes.length) {
				root.appendChild(el('p', { class: 'db-empty', text: 'No pizza sizes configured yet.' }));
				return;
			}

			var state = { size: cfg.sizes[0], toppings: {} };

			var priceEl = el('span', { class: 'db-builder-price' });
			function refreshPrice() {
				var total = Number(state.size.price);
				Object.keys(state.toppings).forEach(function (slug) {
					total += Number(state.toppings[slug].price);
				});
				priceEl.textContent = money(total);
			}

			// Sizes.
			var sizeWrap = el('div', { class: 'db-options' });
			cfg.sizes.forEach(function (size, idx) {
				var input = el('input', { type: 'radio', name: 'db-size', value: size.slug });
				if (idx === 0) { input.checked = true; }
				input.addEventListener('change', function () { state.size = size; refreshPrice(); });
				sizeWrap.appendChild(el('label', { class: 'db-option' }, [
					input,
					el('span', { text: size.label }),
					el('span', { class: 'db-option-price', text: money(size.price) })
				]));
			});

			// Toppings.
			var topWrap = el('div', { class: 'db-options' });
			cfg.toppings.forEach(function (top) {
				var input = el('input', { type: 'checkbox', value: top.slug });
				input.addEventListener('change', function () {
					if (input.checked) { state.toppings[top.slug] = top; }
					else { delete state.toppings[top.slug]; }
					refreshPrice();
				});
				topWrap.appendChild(el('label', { class: 'db-option' }, [
					input,
					el('span', { text: top.label }),
					el('span', { class: 'db-option-price', text: '+' + money(top.price) })
				]));
			});

			var addBtn = el('button', { class: 'db-btn db-btn--lg', text: (I18N.addToCart || 'Add to cart') });
			addBtn.addEventListener('click', function () {
				addBtn.disabled = true;
				request('/cart/add', {
					method: 'POST',
					body: { type: 'custom', size: state.size.slug, toppings: Object.keys(state.toppings), quantity: 1 }
				}).then(function () {
					addBtn.textContent = I18N.added || 'Added!';
					dbPop(addBtn);
					dbToast(I18N.addedToCart || 'Added to cart', true);
					var customValue = Number(state.size.price || 0);
					Object.keys(state.toppings).forEach(function (slug) { customValue += Number(state.toppings[slug].price || 0); });
					trackCommerce('add_to_cart', {
						content_ids: ['custom-pizza'],
						content_name: 'Custom pizza',
						content_category: 'Pizza',
						content_type: 'product',
						currency: 'AUD',
						value: customValue,
						quantity: 1
					});
					notifyCartChanged();
					setTimeout(function () { addBtn.textContent = I18N.addToCart || 'Add to cart'; addBtn.disabled = false; }, 1200);
				}).catch(function (err) { dbToast(err.message); addBtn.disabled = false; });
			});

			root.appendChild(el('div', { class: 'db-builder-inner' }, [
				el('h2', { text: 'Build your pizza' }),
				el('h3', { text: 'Size' }),
				sizeWrap,
				cfg.toppings.length ? el('h3', { text: 'Toppings' }) : null,
				cfg.toppings.length ? topWrap : null,
				el('div', { class: 'db-builder-foot' }, [priceEl, addBtn])
			]));

			refreshPrice();
		}).catch(function (err) {
			root.innerHTML = '';
			root.appendChild(el('p', { class: 'db-error', role: 'alert', text: err.message }));
		});
	}

	/* ------------------------------------------------------------------ */
	/* Cart & checkout                                                    */
	/* ------------------------------------------------------------------ */

	// Whether this storefront actually takes money online. When payments are off
	// (the Revesby pickup launch state) the customer pays in the shop, so every
	// piece of checkout copy must say that instead of promising a card step that
	// never happens.
	function payingOnline() {
		return !!(stripeHosted || tyroPay || mpgsHosted || (PAY.enabled && GATEWAY === 'square'));
	}

	function onlinePaymentIntro() {
		return stripeHosted
			? 'Check your items, add your details, then continue to Stripe for secure payment.'
			: 'Check your items, add your details, then securely pay by card online.';
	}

	function orderJourney() {
		var labels = payingOnline()
			? ['Review order', 'Your details', 'Secure payment']
			: ['Review order', 'Your details', 'Confirm pickup'];
		var items = [];
		var list = el('ol', { class: 'db-order-journey', 'aria-label': 'Order progress' });
		labels.forEach(function (label, index) {
			var item = el('li', { class: 'db-order-journey-step' }, [
				el('span', { class: 'db-order-journey-number', 'aria-hidden': 'true', text: String(index + 1) }),
				el('span', { class: 'db-order-journey-label', text: label })
			]);
			items.push(item);
			list.appendChild(item);
		});
		function set(step) {
			items.forEach(function (item, index) {
				var number = index + 1;
				item.classList.toggle('is-current', number === step);
				item.classList.toggle('is-complete', number < step || step > labels.length);
				if (number === step) { item.setAttribute('aria-current', 'step'); }
				else { item.removeAttribute('aria-current'); }
			});
		}
		set(1);
		return { element: list, set: set };
	}

	function renderCart(root) {
		root.innerHTML = '';
		var initialTableContext = activeTableContext();
		var orderType = initialTableContext ? 'dine_in' : 'pickup';
		var locationId = initialTableContext ? Number(initialTableContext.location.id) : 0;
		// The cart lines/totals region is rebuilt freely on every reload. The
		// checkout region is NOT — once a checkout form exists it is updated in
		// place (see checkoutEl.update below) rather than torn down. Rebuilding it
		// on every cart mutation (e.g. bumping a line's quantity) used to wipe
		// whatever the customer had already typed, and — worse — remount the
		// Stripe card Element's iframe, silently clearing a card number they'd
		// already entered mid-checkout.
		var cartRegion = el('section', { class: 'db-cart-region', 'aria-label': 'Order summary' });
		var checkoutRegion = el('section', { class: 'db-checkout-region', 'aria-label': 'Checkout details' });
		var journey = orderJourney();
		// Pickup time picker (2.51.0). Created once per cart widget so its hold
		// and selection survive the cart region being rebuilt on every reload.
		var pickup = pickupPicker(function (id) {
			var shop = locById(locationsCache || [], id);
			return shop && shop.name ? String(shop.name) : '';
		});
		var shell = el('div', { class: 'db-order-shell' }, [cartRegion, checkoutRegion]);
		var checkoutEl = null;
		var orderTitle = el('h2', { text: payingOnline() ? 'Review, pay and track' : 'Review, order and track' });
		var orderIntro = el('p', { text: payingOnline()
			? onlinePaymentIntro()
			: 'Check your items and add your details. You pay when you collect from the shop.' });
		// Once an order is successfully placed, this cart widget's job is done —
		// further reloads (triggered by the notifyCartChanged() that placeOrder
		// itself fires, telling the rest of the page the cart is now empty) must
		// not overwrite the confirmation message with an "empty cart" render.
		var orderComplete = false;
		root.appendChild(el('header', { class: 'db-order-header' }, [
			el('p', { class: 'db-order-kicker', text: 'Your order' }),
			orderTitle,
			orderIntro
		]));
		root.appendChild(journey.element);
		root.appendChild(shell);

		function load() {
			if (orderComplete) { return; }
			Promise.all([getConfig(), request('/cart?order_type=' + orderType), getLocations()]).then(function (results) {
				draw(results[0], results[1], results[2]);
			}).catch(function (err) {
				cartRegion.innerHTML = '';
				checkoutRegion.innerHTML = '';
				checkoutEl = null;
				cartRegion.appendChild(el('p', { class: 'db-error', role: 'alert', text: err.message }));
			});
		}

		function draw(cfg, cart, locs) {
			if (orderComplete) { return; }
			cartRegion.innerHTML = '';
			lastCartItemCount = cartItemCount(cart);
			var tableContext = activeTableContext();
			var orderingOpen = !!cfg.ordering_open;
			// This is display guidance only: the server remains authoritative for
			// the approval policy and rejects any stale or forged direct checkout.
			// Require an explicit true value so older static previews that do not
			// publish the new flag retain their existing legacy presentation.
			var staffApprovalRequired = cfg && cfg.staff_approval_required === true;
			var staffApprovalRequestsEnabled = cfg && cfg.staff_approval_requests_enabled === true;
			journey.element.hidden = !orderingOpen || staffApprovalRequired;
			if (staffApprovalRequired) {
				orderTitle.textContent = 'Review and request';
				orderIntro.textContent = staffApprovalRequestsEnabled
					? 'Send your order request for staff review. It remains unpaid until approved.'
					: 'Order requests are unavailable right now. Please try again later.';
			} else if (!orderingOpen) {
				orderTitle.textContent = 'Browse now, request for later';
				orderIntro.textContent = 'Online checkout and payment are closed. If pre-orders are available, your request stays unpaid until staff call and confirm it.';
			} else if (payingOnline()) {
				orderTitle.textContent = 'Review, pay and track';
				orderIntro.textContent = onlinePaymentIntro();
			} else {
				orderTitle.textContent = 'Review, order and track';
				orderIntro.textContent = 'Check your items and add your details. You pay when you collect from the shop.';
			}

			if (!orderingOpen) {
				cartRegion.appendChild(orderingClosedNotice(cfg.ordering_closed_message));
				checkoutRegion.innerHTML = '';
				checkoutEl = null;
				journey.set(1);
			}

			if (!cart.items.length) {
				if (tableContext) { cartRegion.appendChild(tableContextBanner(tableContext)); }
				cartRegion.appendChild(el('p', { class: 'db-empty', text: I18N.emptyCart || 'Your cart is empty.' }));
				// Nothing to hold a pickup time for.
				pickup.hide(true);
				// Nothing to check out — drop any previous checkout form so a later
				// non-empty cart starts with a fresh one (and a fresh card mount).
				checkoutRegion.innerHTML = '';
				checkoutEl = null;
				journey.set(1);
				return;
			}

			if (tableContext) {
				// The server resolves and enforces this QR context again on payment
				// and checkout. These values are display-only client state.
				locationId = Number(tableContext.location.id);
				orderType = 'dine_in';
			} else if (cfg.single_location_mode && cfg.single_location_id) {
				locationId = Number(cfg.single_location_id);
				orderType = 'pickup';
			} else if (!locationId) { locationId = currentLocationId(locs); }

			// Line items.
			var itemCount = cartItemCount(cart);
			cartRegion.appendChild(el('div', { class: 'db-cart-section-heading' }, [
				el('h3', { text: 'Order summary' }),
				el('span', { text: itemCount + (itemCount === 1 ? ' item' : ' items') })
			]));
			var list = el('div', { class: 'db-cart-lines' });
			cart.items.forEach(function (line) {
				list.appendChild(cartLine(line, load));
			});
			cartRegion.appendChild(list);
			if (tableContext) { cartRegion.appendChild(tableContextBanner(tableContext)); }

			// Store-first: with several shops and none chosen yet, ask before
			// showing fulfilment and checkout. Pre-order and approval flows keep
			// their existing routing.
			if (orderingOpen && !staffApprovalRequired && !tableContext && !cfg.single_location_mode && storeChoiceRequired(locs, storedLocationId())) {
				pickup.hide(true);
				// Forget the provisional first-shop guess so choosing that same
				// shop still counts as a change and reloads this cart.
				locationId = 0;
				cartRegion.appendChild(storeChooser(locs, cfg, {
					subtitle: 'Choose the shop you’re collecting from to continue with this order.',
					onChoose: requestShopChange
				}));
				checkoutRegion.innerHTML = '';
				checkoutEl = null;
				journey.set(1);
				return;
			}

			// Shop selector — routes the order to the right kitchen board. Only
			// shown when more than one shop exists; otherwise the single shop is
			// remembered silently.
			if (!tableContext && !cfg.single_location_mode && locs.length > 1) {
				setLocation(locationId, true);
				cartRegion.appendChild(el('div', { class: 'db-cart-shop' }, [
					el('span', { class: 'db-cart-shop-label', text: I18N.chooseShop || 'Choose your shop' }),
					shopSelect(locs, locationId, function (id) {
						// Re-checks the cart at the new shop (and honours the payment
						// lock); on success the shop-changed listener reloads this cart.
						requestShopChange(id).then(function (changed) {
							if (!changed) { load(); }
						});
					})
				]));
			} else if (!tableContext && locs.length === 1) {
				locationId = Number(locs[0].id);
				setLocation(locationId, true);
			}

			// Fulfilment selector.
			if (!tableContext) {
				var typeWrap = el('div', { class: 'db-fulfilment' });
				if (cfg.enable_pickup) { typeWrap.appendChild(typeRadio('pickup', 'Pickup', orderType, onType)); }
				if (cfg.enable_delivery) { typeWrap.appendChild(typeRadio('delivery', 'Delivery', orderType, onType)); }
				cartRegion.appendChild(typeWrap);
			}

			// Pickup time (2.51.0). Renders nothing unless the server reports this
			// shop as live; delivery, QR table orders and paused ordering never show it.
			if (pickupSchedulingActive(cfg, tableContext, orderType)) {
				pickup.show(locationId);
				cartRegion.appendChild(pickup.element);
			} else {
				pickup.hide(true);
			}

			// Totals.
			cartRegion.appendChild(totalsBlock(cart.totals, cfg));

			// Closed-hours Revesby requests intentionally use a separate unpaid path.
			// It never renders card controls or calls /payment-intent; the server
			// independently checks the same owner flag before saving anything.
			if (!orderingOpen && !staffApprovalRequired) {
				if (cfg.after_hours_preorders_enabled && !tableContext) {
					checkoutEl = preorderRequestForm(cfg, function () { return locationId; }, function () {
						orderComplete = true;
						cartRegion.innerHTML = '';
					});
					checkoutRegion.appendChild(checkoutEl.form);
				}
				return;
			}

			// Explicit server-approved request intake replaces ordinary checkout
			// during open approval-required ordering. Reuse the payment-free request
			// form, and never fall through to voucher or gateway checkout setup.
			if (staffApprovalRequired) {
				if (staffApprovalRequestsEnabled && !tableContext) {
					checkoutEl = preorderRequestForm(cfg, function () { return locationId; }, function () {
						orderComplete = true;
						cartRegion.innerHTML = '';
					});
					checkoutRegion.appendChild(checkoutEl.form);
				} else {
					checkoutRegion.appendChild(el('p', {
						class: 'db-ordering-status',
						role: 'status',
						text: 'Order requests are unavailable right now. Please try again later.'
					}));
				}
				return;
			}

			// Voucher code (apply/remove — preview only; redeemed at checkout).
			cartRegion.appendChild(voucherBox(cart.totals, orderType, load));

			// Checkout — create once, then only update in place.
			if (!checkoutEl) {
				checkoutEl = checkoutForm(cfg, orderType, function () { return locationId; }, cart.totals, function () {
					orderComplete = true;
					pickup.hide(false);
					// The checkout form's own parent gets replaced with the
					// confirmation message (see placeOrder() above), but that
					// leaves this region's last-rendered cart items/subtotal/
					// voucher box on screen untouched — clear it too so the
					// confirmation isn't shown underneath a stale cart.
					cartRegion.innerHTML = '';
				}, journey.set, pickup);
				checkoutRegion.appendChild(checkoutEl.form);
				journey.set(2);
			} else {
				checkoutEl.update(orderType, cart.totals);
			}
		}

		function onType(value) {
			if (!paymentMutationAllowed()) {
				load();
				return;
			}
			orderType = value;
			load();
		}

		load();
		document.addEventListener('doughboss:cart-updated', load);
		document.addEventListener('doughboss:shop-changed', function (event) {
			var id = Number(event.detail && event.detail.id);
			// QR destination and a payment already in flight remain immutable.
			// Reuse the existing selection event so the top and cart pickers route
			// to the same kitchen without rebuilding the customer's detail form.
			if (orderComplete || paymentMutationLock || activeTableContext() || (configCache && configCache.single_location_mode)) { return; }
			if (id === locationId || !locById(locationsCache || [], id)) { return; }
			locationId = id;
			load();
		});
	}

	/* ------------------------------------------------------------------ */
	/* Pickup time picker (2.51.0)                                        */
	/* ------------------------------------------------------------------ */

	// One picker per cart widget. It owns the /pickup/options read, the slot hold
	// and its countdown; the checkout form only mirrors the selection into
	// hidden inputs (doughboss:pickup-changed). It renders NOTHING and sends
	// nothing unless the server reports mode "live" for the chosen shop.
	function pickupPicker(getShopName) {
		var state = {
			locationId: 0, options: null, loading: false, loadError: false,
			selection: null, holding: false, visible: false, day: '',
			revision: 0, holdRevision: 0, rehold: 0, expired: false, dirty: false,
			asapRetried: false, countdown: 0, refresh: 0, cartTimer: 0, minuteNoted: false, signature: ''
		};
		var element = el('section', { class: 'db-pickup', 'data-doughboss-pickup': '', 'aria-labelledby': 'db-pickup-title' });
		element.hidden = true;
		var msgNode = el('p', { class: 'db-pickup-msg', 'aria-live': 'polite', tabindex: '-1' });
		var liveNode = el('p', { class: 'db-pickup-live', role: 'status', 'aria-live': 'polite' });
		var timerNode = el('span', { class: 'db-pickup-timer', 'aria-live': 'off' });

		function t(key, fallback) { return (I18N && I18N[key]) || fallback; }
		function live() { return !!(state.options && state.options.mode === 'live'); }
		function say(text) {
			liveNode.textContent = '';
			setTimeout(function () { liveNode.textContent = text; }, 30);
		}
		function note(text) {
			clearError(msgNode);
			msgNode.textContent = text || '';
		}
		function warn(text) {
			if (!text) { return; }
			announceError(msgNode, text);
		}
		function releaseToken(token) {
			if (!token) { return; }
			// Best effort: an unreleased hold simply expires on the server.
			request('/pickup/release', { method: 'POST', body: { hold_token: token } }).catch(function () {});
		}
		function emit() {
			document.dispatchEvent(new CustomEvent('doughboss:pickup-changed', {
				detail: { selection: state.selection ? Object.assign({}, state.selection) : null }
			}));
		}
		function days() {
			return state.options && Array.isArray(state.options.days) ? state.options.days.filter(Boolean) : [];
		}
		function signatureOf(options) {
			if (!options) { return ''; }
			return JSON.stringify([options.mode, options.paused, options.closed_reason, options.asap, options.days]);
		}

		function stopCountdown() {
			if (state.countdown) { clearInterval(state.countdown); state.countdown = 0; }
		}
		function startCountdown() {
			stopCountdown();
			state.minuteNoted = false;
			if (!state.selection) { return; }
			tick();
			state.countdown = setInterval(tick, 1000);
		}
		function tick() {
			var selection = state.selection;
			if (!selection) { stopCountdown(); return; }
			var remaining = Date.parse(selection.expires_at_utc) - Date.now();
			// While a payment is in flight the server reacquires a lapsed hold for a
			// paid order, so a "Hold expired" label would only alarm the customer.
			timerNode.textContent = paymentMutationLock && remaining <= 0 ? '' : pickupCountdownLabel(remaining);
			if (remaining <= 60000 && remaining > 0 && !state.minuteNoted) {
				state.minuteNoted = true;
				say(t('pickupOneMinute', 'Your pickup time is held for one more minute.'));
			}
			if (remaining <= 0) {
				stopCountdown();
				onHoldExpired();
			}
		}
		function onHoldExpired() {
			var selection = state.selection;
			if (!selection || !state.visible || paymentMutationLock) { return; }
			// One silent re-hold keeps a slow checkout going; after that the
			// customer decides. While a payment is in flight the server's own
			// reacquire-on-order handling covers a paid order, so leave it alone.
			if (state.rehold < 1 && !paymentMutationLock && !state.holding) {
				state.rehold += 1;
				hold(selection.mode, selection, true);
				return;
			}
			state.expired = true;
			render();
		}
		document.addEventListener('visibilitychange', function () {
			if (!document.hidden && state.selection && state.visible) { tick(); }
		});

		function dropSelection(release) {
			stopCountdown();
			var previous = state.selection;
			state.selection = null;
			state.expired = false;
			state.rehold = 0;
			if (previous && release) { releaseToken(previous.hold_token); }
			if (previous) { emit(); }
		}

		function loadOptions(quiet) {
			var id = state.locationId;
			if (!id) { return Promise.resolve(); }
			state.revision += 1;
			var revision = state.revision;
			if (!quiet) {
				state.loading = true;
				state.loadError = false;
			}
			return request('/pickup/options?location_id=' + encodeURIComponent(id)).then(function (options) {
				if (revision !== state.revision || id !== state.locationId) { return; }
				state.loading = false;
				state.loadError = false;
				var previousSignature = state.signature;
				state.options = options && typeof options === 'object' ? options : null;
				state.signature = signatureOf(state.options);
				if (!live()) {
					dropSelection(true);
					render();
					return;
				}
				var list = days();
				var known = list.some(function (day) { return day.date === state.day; });
				if (!state.day || !known) { state.day = pickupDefaultDay(list, state.selection); }
				if (!quiet || state.signature !== previousSignature) { render(); }
			}).catch(function () {
				if (revision !== state.revision) { return; }
				state.loading = false;
				if (quiet) { return; }
				state.loadError = true;
				render();
			});
		}

		function hold(kind, slot, auto, notice) {
			var id = state.locationId;
			state.holdRevision += 1;
			var revision = state.holdRevision;
			var previous = state.selection ? state.selection.hold_token : '';
			// Re-holding the time already held (cart change, expiry, refresh): the
			// server frees the old hold first, otherwise the caller's own hold can
			// make a slot at capacity refuse its replacement.
			var sameSlot = !!(previous && state.selection && slot && slot.ready_from_utc === state.selection.ready_from_utc);
			var body = { location_id: id, pickup_mode: kind, ready_from_utc: slot.ready_from_utc, idempotency_key: newClientKey() };
			if (sameSlot) { body.release_token = previous; }
			state.holding = true;
			state.dirty = false;
			if (!auto) { clearError(msgNode); msgNode.textContent = ''; }
			render();
			return request('/pickup/hold', {
				method: 'POST',
				body: body
			}).then(function (res) {
				if (revision !== state.holdRevision || id !== state.locationId || !state.visible) {
					// Superseded while in flight: hand the stray hold back.
					if (res && res.hold_token) { releaseToken(String(res.hold_token)); }
					return;
				}
				var selection = pickupSelectionFromHold(res, kind, slot);
				if (!selection) { throw new Error(t('pickupHoldError', 'We could not hold that time. Please try again.')); }
				state.holding = false;
				state.expired = false;
				state.asapRetried = false;
				state.selection = selection;
				if (previous && previous !== selection.hold_token) { releaseToken(previous); }
				var list = days();
				if (selection.mode === 'scheduled') { state.day = pickupDefaultDay(list, selection) || state.day; }
				startCountdown();
				render();
				emit();
				if (notice) { note(notice); }
				say((t('pickupHeld', 'Pickup time held:')) + ' ' + pickupSummaryText(selection));
				if (state.dirty) { state.dirty = false; onCartUpdated(); }
			}).catch(function (err) {
				if (revision !== state.holdRevision) { return; }
				// With release_token sent the old hold is already gone server-side,
				// so the selection must not survive a failed replacement.
				return failHold(err, auto || sameSlot, kind);
			});
		}

		function failHold(err, auto, kind) {
			state.holding = false;
			var code = err && err.code ? String(err.code) : '';
			if (code === 'doughboss_empty') {
				// The cart was emptied while a re-hold was pending: nothing to hold.
				dropSelection(true);
				render();
				return Promise.resolve();
			}
			var text = pickupErrorMessage(code, err && err.message) || t('pickupHoldError', 'We could not hold that time. Please try again.');
			// A cart-driven re-hold that fails means the old hold no longer matches.
			if (auto || code === 'doughboss_hold_expired' || code === 'doughboss_hold_stale') { dropSelection(true); }
			var retryAsap = auto && kind === 'asap' && code === 'doughboss_slot_unavailable' && !state.asapRetried;
			return loadOptions(true).then(function () {
				if (retryAsap && live() && state.options.asap) {
					// The ASAP window rolled over while the customer was checking out.
					state.asapRetried = true;
					return hold('asap', state.options.asap, true, t('pickupAsapUpdated', 'Your ASAP time was updated. Please check it before you order.'));
				}
				render();
				warn(text);
			});
		}

		function choose(kind, slot) {
			if (paymentMutationLock) { dbToast(paymentMutationMessage); return; }
			state.rehold = 0;
			state.asapRetried = false;
			hold(kind, slot, false);
		}

		// Cart lines or voucher changed: the hold is bound to the old cart, so
		// take a fresh hold on the same time (debounced) and give the old one back.
		function onCartUpdated() {
			if (!state.visible || !state.selection || paymentMutationLock) { return; }
			if (state.holding) { state.dirty = true; return; }
			clearTimeout(state.cartTimer);
			state.cartTimer = setTimeout(function () {
				var selection = state.selection;
				if (!state.visible || !selection || paymentMutationLock) { return; }
				if (state.holding) { state.dirty = true; return; }
				hold(selection.mode, selection, true);
			}, 300);
		}
		document.addEventListener('doughboss:cart-updated', onCartUpdated);

		function scheduleRefresh() {
			if (state.refresh) { clearInterval(state.refresh); }
			state.refresh = setInterval(function () {
				// Shops that are not live have nothing to keep fresh (and no extra traffic).
				if (!state.visible || state.holding || document.hidden || !live()) { return; }
				loadOptions(true);
			}, 60000);
		}

		function focusKey() {
			var active = document.activeElement;
			return active && active.getAttribute && element.contains && element.contains(active) ? active.getAttribute('data-pk') : null;
		}

		function asapReason(reason) {
			if (reason === 'closed_now') { return t('pickupAsapClosed', 'Shop closed now — choose a time'); }
			if (reason === 'no_slots') { return t('pickupAsapNone', 'No ASAP time left — choose a time'); }
			return t('pickupAsapUnavailable', 'ASAP isn’t available right now — choose a time');
		}
		function emptyReason(reason) {
			if (reason === 'no_hours') { return t('pickupNoHours', 'Pickup times aren’t available at this shop right now. Please contact the shop.'); }
			if (reason === 'paused') { return pickupErrorMessage('doughboss_location_paused'); }
			return t('pickupNoSlots', 'No pickup times left. Try another day or shop.');
		}

		function render() {
			var restore = focusKey();
			if (!state.visible || (!live() && !state.loadError)) {
				element.hidden = true;
				return;
			}
			element.hidden = false;
			element.innerHTML = '';
			element.setAttribute('aria-busy', state.holding ? 'true' : 'false');
			element.appendChild(el('h3', { class: 'db-pickup-title', id: 'db-pickup-title', text: t('pickupTitle', 'When would you like it?') }));

			if (state.loadError && !live()) {
				var retry = el('button', { type: 'button', class: 'db-btn db-btn--ghost db-pickup-retry', 'data-pk': 'retry', text: t('pickupRetry', 'Try again') });
				retry.addEventListener('click', function () { loadOptions(false); });
				element.appendChild(el('p', { class: 'db-pickup-sub', text: t('pickupLoadError', 'We couldn’t load pickup times.') }));
				element.appendChild(retry);
				element.appendChild(msgNode);
				element.appendChild(liveNode);
				return;
			}

			var options = state.options;
			var shop = getShopName ? getShopName(state.locationId) : '';
			var holdMinutes = Math.max(1, Math.round(Number(options.hold_minutes) || 10));
			element.appendChild(el('p', {
				class: 'db-pickup-sub',
				text: (shop ? t('pickupFrom', 'Collect from') + ' ' + shop + '. ' : '')
					+ t('pickupHoldNote', 'We hold your time for %d minutes while you check out.').replace('%d', String(holdMinutes))
			}));
			element.appendChild(msgNode);

			if (options.paused) {
				element.appendChild(el('p', { class: 'db-pickup-closed', role: 'status', text: pickupErrorMessage('doughboss_location_paused') }));
				element.appendChild(liveNode);
				return;
			}

			var asap = options.asap && typeof options.asap === 'object' ? options.asap : null;
			var asapSelected = !!(state.selection && state.selection.mode === 'asap');
			var asapButton = el('button', {
				type: 'button',
				class: 'db-pickup-asap' + (asapSelected ? ' is-selected' : '') + (asap ? '' : ' is-unavailable'),
				'aria-pressed': asapSelected ? 'true' : 'false',
				'data-pk': 'asap'
			}, [
				el('span', { class: 'db-pickup-asap-title', text: asap ? (pickupEtaLabel(asap) || 'ASAP') : 'ASAP' }),
				asap ? null : el('span', { class: 'db-pickup-asap-note', text: asapReason(options.closed_reason) })
			]);
			if (asap) {
				asapButton.addEventListener('click', function () { choose('asap', asap); });
			} else {
				asapButton.disabled = true;
				asapButton.setAttribute('aria-disabled', 'true');
			}
			element.appendChild(asapButton);

			var list = days();
			var hasSlots = list.some(function (day) { return Array.isArray(day.slots) && day.slots.length > 0; });
			if (!hasSlots) {
				element.appendChild(el('p', { class: 'db-pickup-closed', role: 'status', text: emptyReason(options.closed_reason) }));
			} else {
				element.appendChild(el('p', { class: 'db-pickup-or', text: asap ? t('pickupOrChoose', 'Or choose a time') : t('pickupChoose', 'Choose a time') }));
				if (list.length > 1) {
					var tabs = el('div', { class: 'db-pickup-days', role: 'group', 'aria-label': t('pickupDay', 'Pickup day') });
					list.forEach(function (day) {
						var active = day.date === state.day;
						var tab = el('button', {
							type: 'button',
							class: 'db-pickup-day' + (active ? ' is-active' : ''),
							'aria-pressed': active ? 'true' : 'false',
							'data-pk': 'day:' + day.date,
							text: day.label || day.date
						});
						tab.addEventListener('click', function () { state.day = day.date; render(); });
						tabs.appendChild(tab);
					});
					element.appendChild(tabs);
				}
				var current = list.filter(function (day) { return day.date === state.day; })[0] || list[0];
				var slots = Array.isArray(current.slots) ? current.slots : [];
				if (!slots.length) {
					element.appendChild(el('p', { class: 'db-pickup-closed', text: t('pickupDayEmpty', 'No more times on this day.') }));
				} else {
					var grid = el('div', { class: 'db-pickup-slots', role: 'group', 'aria-label': t('pickupTimesFor', 'Pickup times for') + ' ' + (current.label || current.date) });
					slots.forEach(function (slot) {
						var selected = !!(state.selection && state.selection.mode === 'scheduled' && state.selection.ready_from_utc === slot.ready_from_utc);
						var full = slot.available === false;
						var button = el('button', {
							type: 'button',
							class: 'db-pickup-slot' + (selected ? ' is-selected' : '') + (full ? ' is-full' : ''),
							'aria-pressed': selected ? 'true' : 'false',
							'aria-label': pickupSlotLabel(slot),
							'data-pk': 'slot:' + slot.ready_from_utc
						}, [
							el('span', { class: 'db-pickup-slot-time', text: slot.label || slot.local_from || '' }),
							full ? el('span', { class: 'db-pickup-slot-note', text: t('pickupFull', 'Full') }) : null
						]);
						if (full) {
							button.disabled = true;
							button.setAttribute('aria-disabled', 'true');
						} else {
							button.addEventListener('click', function () { choose('scheduled', slot); });
						}
						grid.appendChild(button);
					});
					element.appendChild(grid);
				}
			}

			if (state.selection) {
				var again = null;
				if (state.expired) {
					again = el('button', { type: 'button', class: 'db-link db-pickup-again', 'data-pk': 'again', text: t('pickupHoldAgain', 'Hold this time again') });
					again.addEventListener('click', function () {
						state.rehold = 0;
						hold(state.selection.mode, state.selection, false);
					});
				}
				element.appendChild(el('div', { class: 'db-pickup-selected' + (state.expired ? ' is-expired' : '') }, [
					el('span', { class: 'db-pickup-selected-label', role: 'status', text: t('pickupLabel', 'Pickup:') + ' ' + pickupSummaryText(state.selection) }),
					state.expired ? el('span', { class: 'db-pickup-timer', text: t('pickupExpired', 'Hold expired') }) : timerNode,
					again
				]));
				if (!state.expired) { timerNode.textContent = pickupCountdownLabel(Date.parse(state.selection.expires_at_utc) - Date.now()); }
			}
			element.appendChild(liveNode);

			if (restore) {
				var target = element.querySelector('[data-pk="' + restore.replace(/"/g, '') + '"]');
				if (target && !target.disabled) {
					try { target.focus({ preventScroll: true }); } catch (ignoreFocus) { target.focus(); }
				}
			}
		}

		function show(locationId) {
			var id = Number(locationId) || 0;
			state.visible = true;
			if (id !== state.locationId) {
				dropSelection(true);
				state.locationId = id;
				state.options = null;
				state.signature = '';
				state.loadError = false;
				state.day = '';
				state.asapRetried = false;
				scheduleRefresh();
				loadOptions(false);
				return;
			}
			render();
			if (!state.options && !state.loading && !state.loadError) { loadOptions(false); }
		}

		function hide(release) {
			clearTimeout(state.cartTimer);
			state.visible = false;
			state.holdRevision += 1;
			state.holding = false;
			stopCountdown();
			if (state.refresh) { clearInterval(state.refresh); state.refresh = 0; }
			dropSelection(release !== false);
			// Forget the shop so the next show() re-reads fresh options.
			state.locationId = 0;
			state.options = null;
			state.signature = '';
			state.loadError = false;
			element.hidden = true;
		}

		// Checkout gate. Returns '' when ordering may proceed, otherwise announces
		// and returns the reason. Re-holds a lapsed selection as a side effect.
		function gate() {
			if (!state.visible || !live()) { return ''; }
			var message = '';
			if (state.options.paused) {
				message = pickupErrorMessage('doughboss_location_paused');
			} else if (state.holding) {
				message = t('pickupHolding', 'Reserving your pickup time… please try again in a moment.');
			} else if (!state.selection) {
				message = t('pickupChooseFirst', 'Choose a pickup time first');
			} else if (!pickupSelectionValid(state.selection, Date.now()) && !paymentMutationLock) {
				state.rehold = 0;
				hold(state.selection.mode, state.selection, true);
				message = t('pickupExpiredRetry', 'Your pickup time hold expired. We are refreshing it — please press the button again.');
			}
			if (message) { warn(message); }
			return message;
		}

		// The checkout rejected the pickup facts: re-sync with the server.
		function recover(code, message) {
			var known = ['doughboss_slot_full', 'doughboss_slot_unavailable', 'doughboss_hold_expired', 'doughboss_hold_stale', 'doughboss_hold_used', 'doughboss_pickup_required', 'doughboss_location_paused', 'doughboss_pickup_off'];
			if (known.indexOf(String(code || '')) === -1 || !state.visible) { return false; }
			var selection = state.selection;
			if ((code === 'doughboss_hold_expired' || code === 'doughboss_hold_stale') && selection && !paymentMutationLock) {
				state.rehold = 0;
				hold(selection.mode, selection, true, t('pickupRefreshed', 'We refreshed your pickup time. Please check it and place your order again.'));
				return true;
			}
			dropSelection(true);
			loadOptions(false).then(function () {
				render();
				warn(pickupErrorMessage(code, message));
			});
			return true;
		}

		function focusTarget() {
			return element.querySelector('.db-pickup-asap:not([disabled]), .db-pickup-slot:not([disabled])') || msgNode;
		}

		return {
			element: element,
			show: show,
			hide: hide,
			gate: gate,
			recover: recover,
			selection: function () { return state.selection; },
			required: function () { return state.visible && live(); },
			focusTarget: focusTarget
		};
	}

	function orderingClosedNotice(message) {
		return el('aside', { class: 'db-ordering-status', role: 'status' }, [
			el('strong', { text: I18N.orderingComingSoon || 'Online ordering is paused' }),
			el('p', { text: message || 'Browse the menu and check your preferred shop before visiting.' })
		]);
	}

	function typeRadio(value, label, current, onChange) {
		var input = el('input', { type: 'radio', name: 'db-order-type', value: value });
		if (value === current) { input.checked = true; }
		input.addEventListener('change', function () { onChange(value); });
		return el('label', { class: 'db-option' }, [input, el('span', { text: label })]);
	}

	function cartLine(line, reload) {
		var sub = [];
		if (line.size) { sub.push(line.size); }
		if (line.toppings && line.toppings.length) {
			sub.push(line.toppings.map(function (t) { return t.label; }).join(', '));
		}

		var qty = el('input', { type: 'number', min: '1', value: line.quantity, class: 'db-qty', 'aria-label': 'Quantity for ' + line.name, inputmode: 'numeric' });
		var decrease = el('button', { class: 'db-qty-btn', type: 'button', text: '−', 'aria-label': 'Decrease quantity for ' + line.name });
		var increase = el('button', { class: 'db-qty-btn', type: 'button', text: '+', 'aria-label': 'Increase quantity for ' + line.name });
		var controls = el('div', { class: 'db-qty-control', role: 'group', 'aria-label': 'Quantity for ' + line.name }, [decrease, qty, increase]);

		function updateQuantity(next) {
			if (!paymentMutationAllowed()) { return; }
			next = Math.max(1, Math.min(99, Number(next) || 1));
			qty.value = String(next);
			decrease.disabled = true;
			increase.disabled = true;
			request('/cart/update', { method: 'POST', body: { key: line.key, quantity: next } })
				.then(function () { notifyCartChanged(); })
				.catch(function (err) {
					dbToast(err.message);
					qty.value = String(line.quantity);
					decrease.disabled = false;
					increase.disabled = false;
				});
		}
		decrease.addEventListener('click', function () { updateQuantity(Number(qty.value) - 1); });
		increase.addEventListener('click', function () { updateQuantity(Number(qty.value) + 1); });
		qty.addEventListener('change', function () { updateQuantity(qty.value); });

		var remove = el('button', { class: 'db-link db-cart-remove', type: 'button', text: I18N.remove || 'Remove', 'aria-label': (I18N.remove || 'Remove') + ' ' + line.name });
		remove.addEventListener('click', function () {
			request('/cart/remove', { method: 'POST', body: { key: line.key } })
				.then(function () { notifyCartChanged(); }).catch(function (err) { dbToast(err.message); });
		});

		return el('div', { class: 'db-cart-line' }, [
			el('div', { class: 'db-cart-line-info' }, [
				el('strong', { text: line.name }),
				sub.length ? el('small', { text: sub.join(' · ') }) : null
			]),
			controls,
			el('span', { class: 'db-price', text: money(line.line_total) }),
			remove
		]);
	}

	function totalsBlock(totals, cfg) {
		var inclusive = totals.tax_inclusive || (cfg && cfg.gst_inclusive);

		var rows = [
			[I18N.subtotal || 'Subtotal', totals.subtotal]
		];
		// Only add tax as its own line when it's charged on top of prices.
		if (!inclusive && totals.tax > 0) { rows.push([I18N.tax || 'Tax', totals.tax]); }
		if (totals.delivery_fee > 0) { rows.push([I18N.delivery || 'Delivery', totals.delivery_fee]); }

		var block = el('div', { class: 'db-totals' });
		rows.forEach(function (row) {
			block.appendChild(el('div', { class: 'db-total-row' }, [
				el('span', { text: row[0] }), el('span', { text: money(row[1]) })
			]));
		});
		if (totals.discount > 0) {
			block.appendChild(el('div', { class: 'db-total-row db-total-row--discount' }, [
				el('span', { text: (I18N.discount || 'Discount') + (totals.voucher_code ? ' (' + totals.voucher_code + ')' : '') }),
				el('span', { text: '−' + money(totals.discount) })
			]));
		}
		block.appendChild(el('div', { class: 'db-total-row db-total-row--grand' }, [
			el('span', { text: I18N.total || 'Total' }), el('span', { text: money(totals.total) })
		]));
		if (inclusive && totals.tax > 0) {
			block.appendChild(el('div', { class: 'db-total-note', text: '(' + (I18N.inclGst || 'includes GST') + ' ' + money(totals.tax) + ')' }));
		}
		return block;
	}

	function voucherBox(totals, orderType, reload) {
		var wrap = el('div', { class: 'db-voucher' });
		var msg = el('div', { class: 'db-voucher-msg', 'aria-live': 'polite' });

		if (totals.voucher_code) {
			var remove = el('button', { class: 'db-link', type: 'button', text: I18N.remove || 'Remove' });
			remove.addEventListener('click', function () {
				remove.disabled = true;
				request('/cart/remove-voucher', { method: 'POST', body: { order_type: orderType } })
					.then(function () { reload(); })
					.catch(function (err) { remove.disabled = false; announceError(msg, err.message); });
			});
			wrap.appendChild(el('div', { class: 'db-voucher-applied' }, [
				el('span', { text: (I18N.voucherApplied || 'Voucher applied') + ': ' + totals.voucher_code }),
				remove
			]));
		} else {
			var input = el('input', { type: 'text', class: 'db-voucher-input', placeholder: I18N.voucherPlaceholder || 'Voucher code', 'aria-label': 'Voucher code' });
			var apply = el('button', { class: 'db-btn db-btn--sm', type: 'button', text: I18N.apply || 'Apply' });
			function doApply() {
				var code = (input.value || '').trim();
				if (!code) { return; }
				apply.disabled = true;
				clearError(msg);
				request('/cart/apply-voucher', { method: 'POST', body: { code: code, order_type: orderType } })
					.then(function () { reload(); })
					.catch(function (err) { apply.disabled = false; announceError(msg, err.message, input); });
			}
			apply.addEventListener('click', doApply);
			input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doApply(); } });
			wrap.appendChild(el('div', { class: 'db-voucher-row' }, [input, apply]));
		}

		wrap.appendChild(msg);
		return wrap;
	}


	// Tyro Connect Pay sheet. The Pay Secret is kept only in this closure and is
	// never placed in storage, URLs or logs. Server verification remains the
	// authority before an order reaches the kitchen.
	function tyroConnectCheckout(form, msg, getState, clientKey) {
		var mount = el('div', { class: 'db-tyro-pay-form', id: 'db-tyro-pay-' + String(Date.now()) });
		var status = el('p', { class: 'db-pay-secure', text: 'Secure payment by Tyro · Bank verification may appear here.' });
		form.appendChild(el('div', { class: 'db-cardfield db-cardfield--tyro-connect' }, [
			el('span', { class: 'db-field-label', text: I18N.cardDetails || 'Card details' }),
			mount,
			status
		]));
		var state = { initPromise: null, paymentId: '' };

		function createIntent() {
			var s = getState();
			return request('/payment-intent', {
				method: 'POST',
				body: Object.assign({ order_type: s.orderType, location_id: s.locationId, payment_attempt_key: clientKey }, s.pickup || {})
			});
		}

		function waitForResult(tyro, remaining) {
			return tyro.fetchPayRequest().then(function (result) {
				var payRequest = result && result.payRequest ? result.payRequest : result;
				var providerStatus = payRequest && payRequest.status ? String(payRequest.status).toUpperCase() : '';
				if (providerStatus === 'SUCCESS') { return payRequest; }
				if (providerStatus === 'FAILED' || providerStatus === 'VOIDED') {
					throw new Error(I18N.cardError || 'The payment was not approved. Please check your details and try again.');
				}
				if (remaining < 1) {
					throw new Error('Your payment is still being checked. Do not pay again; please wait a moment and retry confirmation.');
				}
				return new Promise(function (resolve) { setTimeout(resolve, 1200); }).then(function () { return waitForResult(tyro, remaining - 1); });
			});
		}

		function init() {
			if (state.initPromise) { return state.initPromise; }
			state.initPromise = createIntent().then(function (pi) {
				state.paymentId = pi.payment_intent;
				var tyro = window.Tyro({ liveMode: !!PAY.liveMode });
				return tyro.init(pi.client_secret).then(function () {
					var payForm = tyro.createPayForm({
						theme: 'minimal',
						options: { creditCardForm: { enabled: true }, applePay: { enabled: false }, googlePay: { enabled: false } }
					});
					return payForm.inject('#' + mount.id).then(function () { return tyro; });
				});
			}).catch(function (err) {
				state.initPromise = null;
				throw err;
			});
			return state.initPromise;
		}

		function start() {
			init().catch(function (err) {
				announceError(msg, err.message || (I18N.cardInitError || 'The secure payment form could not be loaded.'));
			});
		}
		// On a live pickup shop the intent is bound to the held time, so the card
		// form waits until one is chosen (checkoutForm calls start() then).
		setTimeout(function () {
			if (!getState().pickupPending) { start(); }
		}, 0);

		return {
			start: start,
			pay: function () {
				status.textContent = 'Securely submitting your payment…';
				return init().then(function (tyro) {
					return tyro.submitPay().then(function () { return waitForResult(tyro, 10); });
				}).then(function () {
					status.textContent = 'Payment confirmed · sending your order to DoughBoss.';
					return state.paymentId;
				});
			}
		};
	}

	// Request form. It deliberately does not share checkoutForm(): checkoutForm
	// can mount Stripe/Tyro fields, while every request path must remain
	// payment-free even if a gateway is configured for normal trading hours.
	function preorderRequestForm(cfg, getLocationId, onRequestComplete) {
		var approvalRequiredMode = !!(cfg && cfg.staff_approval_required === true);
		var copy = approvalRequiredMode ? {
			acknowledgement: 'I understand this request is unpaid and awaiting staff review. Pickup details will be confirmed only after review.',
			submit: 'Send request',
			title: 'Send your order request',
			status: 'Your request is awaiting staff review. It is not confirmed or paid.',
			successTitle: 'Request received for review',
			successStatus: 'Your request is awaiting review. It is not confirmed or paid.',
			successFollowUp: 'Staff will review your request before confirming it.',
			analyticsName: 'Order request',
			analyticsCategory: 'Staff approval request'
		} : {
			acknowledgement: 'I understand this is an unconfirmed, unpaid request. Revesby will call to arrange pickup timing before confirming.',
			submit: 'Send pre-order request',
			title: 'Send a Revesby pre-order request',
			status: (cfg && cfg.after_hours_preorders_message) || 'Your request is not confirmed or paid. Revesby will review it first thing in the morning.',
			successTitle: 'Pre-order request received',
			successStatus: 'Revesby will review your request first thing in the morning.',
			successFollowUp: 'Revesby will call to arrange pickup timing before confirming.',
			analyticsName: 'After-hours preorder request',
			analyticsCategory: 'Preorder'
		};
		var form = el('form', { class: 'db-checkout db-preorder-request' });
		var msg = el('div', { class: 'db-checkout-msg', 'aria-live': 'polite' });
		var name = field('text', 'customer_name', 'Name', true);
		var email = field('email', 'customer_email', 'Email', true);
		var phone = field('tel', 'customer_phone', 'Phone', true);
		var notes = field('textarea', 'notes', 'Notes / allergies (optional)', false);
		var notesInput = notes.querySelector('textarea');
		if (notesInput) {
			notesInput.placeholder = 'E.g. no onion, allergy note, catering pickup detail...';
		}
		var acknowledgementInput = el('input', { type: 'checkbox', required: true });
		var acknowledgement = el('label', { class: 'db-option' }, [
			acknowledgementInput,
			el('span', { text: copy.acknowledgement })
		]);
		var submit = el('button', { class: 'db-btn db-btn--lg', type: 'submit', text: copy.submit });
		var requestAttemptId = null;

		form.appendChild(el('h3', { text: copy.title }));
		form.appendChild(el('p', { class: 'db-ordering-status', text: copy.status }));
		if (!approvalRequiredMode) {
			form.appendChild(el('p', { class: 'db-ordering-status', text: 'Pickup timing will be arranged by phone before Revesby confirms your request.' }));
		}
		[name, email, phone, notes, acknowledgement, submit, msg].forEach(function (node) { form.appendChild(node); });

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (!acknowledgementInput.checked) { return; }
			submit.disabled = true;
			submit.textContent = 'Sending request…';
			clearError(msg);
			msg.className = 'db-checkout-msg';
			if (!requestAttemptId) {
				requestAttemptId = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (String(Date.now()) + '-' + Math.random());
			}
			request('/preorder-request', {
				method: 'POST',
				headers: { 'Idempotency-Key': requestAttemptId },
				body: {
					location_id: getLocationId ? getLocationId() : storedLocationId(),
					customer_name: name.querySelector('input,textarea').value,
					customer_email: email.querySelector('input,textarea').value,
					customer_phone: phone.querySelector('input,textarea').value,
					notes: notes.querySelector('input,textarea').value
				}
			}).then(function (res) {
				requestAttemptId = null;
				var parent = form.parentNode;
				if (parent) {
					parent.innerHTML = '';
					parent.appendChild(el('div', { class: 'db-order-confirm' }, [
						el('h3', { text: copy.successTitle }),
						el('p', { text: approvalRequiredMode ? copy.successStatus : (res.message || copy.successStatus) }),
						el('p', { text: 'Request ' + (res.order_number || '') + (approvalRequiredMode ? ' is awaiting review and unpaid. No payment has been taken.' : ' is unconfirmed and unpaid. No payment has been taken.') }),
						el('p', { text: copy.successFollowUp })
					]));
				}
				trackCommerce('generate_lead', {
					content_name: copy.analyticsName,
					content_category: copy.analyticsCategory,
					currency: 'AUD',
					location_id: getLocationId ? getLocationId() : storedLocationId(),
					channel: 'web'
				});
				if (onRequestComplete) { onRequestComplete(); }
				notifyCartChanged();
			}).catch(function (err) {
				submit.disabled = false;
				submit.textContent = copy.submit;
				announceError(msg, err.message || (I18N.genericError || 'Something went wrong.'));
			});
		});

		return { form: form };
	}

	function checkoutForm(cfg, initialOrderType, getLocationId, initialTotals, onOrderComplete, onJourneyStep, pickup) {
		// orderType/totals are mutable — update() below can revise them (e.g. the
		// customer switches pickup/delivery, or the cart total changes) without
		// recreating this form or its mounted Stripe card Element. The submit
		// handler always reads the current values through these closures, not the
		// frozen constructor arguments.
		var orderType = initialOrderType;
		var totals = initialTotals;

		var form = el('form', { class: 'db-checkout' });
		// tabindex allows announceError() below to move focus here. Without it a
		// failed submit only changed text 900px down the page: on a phone the
		// customer saw nothing happen and had no reason to try again.
		var msg = el('div', { class: 'db-checkout-msg', 'aria-live': 'polite', tabindex: '-1' });

		var name = field('text', 'customer_name', 'Name', true, { autocomplete: 'name' });
		var email = field('email', 'customer_email', 'Email', true, { autocomplete: 'email', inputmode: 'email', autocapitalize: 'none', spellcheck: 'false' });
		var phone = field('tel', 'customer_phone', 'Mobile', true, { autocomplete: 'tel', inputmode: 'tel' });
		var address = field('textarea', 'address', 'Delivery address', orderType === 'delivery', { autocomplete: 'street-address', rows: '3' });
		var notes = field('textarea', 'notes', 'Kitchen notes (optional)', false, { rows: '3', placeholder: 'Allergies, dietary needs or preparation notes' });

		address.style.display = orderType === 'delivery' ? '' : 'none';

		// Whether this checkout takes a card payment online.
		var paying = payingOnline();

		function newPaymentAttemptKey() {
			return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (String(Date.now()) + '-' + Math.random());
		}

		// Stripe and MPGS redirect to their hosted checkout pages. Tyro retains
		// its provider-hosted embedded pay form.
		function payLabelFor(t) {
			var grandTotal = t && typeof t.total !== 'undefined' ? t.total : 0;
			if (stripeHosted || squareHosted) { return (I18N.continueToPayment || 'Continue to wallet or card') + ' · ' + money(grandTotal); }
			return paying ? ((I18N.pay || 'Pay') + ' ' + money(grandTotal)) : (I18N.placeOrder || 'Place order');
		}
		var payLabel = payLabelFor(totals);
		var submit = el('button', { class: 'db-btn db-btn--lg', type: 'submit', text: payLabel });
		var paymentAttemptKey = newPaymentAttemptKey();
		var stripeCancelledPayload = null;
		var squareHostedActive = null;
		var squareHostedReturn = null;
		var squareHostedCancel = null;

		// Pickup time (2.51.0). The three hidden inputs exist only while a time
		// is held: with no picker (mode off, delivery, QR table) the form renders
		// and sends none of them, exactly as before.
		var pickupField = {};
		var summaryPickup = el('span', { class: 'db-checkout-summary-pickup' });
		summaryPickup.hidden = true;
		function syncPickupFields(selection) {
			var values = pickupHiddenFields(selection);
			Object.keys(values).forEach(function (fieldName) {
				var input = pickupField[fieldName];
				if (!values[fieldName]) {
					if (input && input.parentNode) { input.parentNode.removeChild(input); }
					delete pickupField[fieldName];
					return;
				}
				if (!input) {
					input = el('input', { type: 'hidden', name: fieldName });
					pickupField[fieldName] = input;
					form.appendChild(input);
				}
				input.value = values[fieldName];
			});
			var text = pickupSummaryText(selection);
			summaryPickup.textContent = text ? (I18N.pickupLabel || 'Pickup:') + ' ' + text : '';
			summaryPickup.hidden = !text;
		}
		function pickupPayloadFields() {
			var out = {};
			Object.keys(pickupField).forEach(function (fieldName) {
				var fieldValue = pickupField[fieldName].value;
				if (fieldValue) { out[fieldName] = fieldName === 'pickup_slot_id' ? Number(fieldValue) : fieldValue; }
			});
			return out;
		}
		// The pickup keys of a payload, for the /payment-intent bodies.
		function pickupBody(source) {
			var out = {};
			['pickup_mode', 'pickup_slot_id', 'capacity_hold_token'].forEach(function (key) {
				if (source && source[key]) { out[key] = source[key]; }
			});
			return out;
		}
		function pickupSelectionNow() {
			return pickup && typeof pickup.selection === 'function' ? pickup.selection() : null;
		}
		function onPickupChanged(event) {
			if (!document.body.contains(form)) {
				document.removeEventListener('doughboss:pickup-changed', onPickupChanged);
				return;
			}
			var selection = event && event.detail ? event.detail.selection : null;
			syncPickupFields(selection);
			// A different slot (or a fresh hold token) is a different checkout
			// attempt. Nothing can be in flight while the payment lock is off.
			if (!paymentMutationLock && !stripeCancelledPayload && !squareHostedActive) {
				paymentAttemptKey = newPaymentAttemptKey();
				checkoutAttemptId = null;
			}
			if (tyro && selection && typeof tyro.start === 'function') { tyro.start(); }
		}
		if (pickup) {
			syncPickupFields(pickupSelectionNow());
			document.addEventListener('doughboss:pickup-changed', onPickupChanged);
			// The Square card script intercepts submit before this form does, so it
			// asks this gate first (see doughboss-square.js).
			form.doughbossPickupGuard = function () {
				return typeof pickup.gate === 'function' ? pickup.gate() : '';
			};
		}

		var summaryType = el('strong', { text: orderType === 'delivery' ? 'Delivery' : (orderType === 'dine_in' ? 'Dine in' : 'Pickup') });
		var summaryCount = el('span', { text: 'Review your items and total' });
		var summaryTotal = el('strong', { class: 'db-checkout-summary-total', text: money(totals && totals.total) });
		var summary = el('div', { class: 'db-checkout-summary', role: 'status' }, [
			el('div', {}, [
				el('span', { class: 'db-checkout-summary-label', text: 'Fulfilment' }),
				summaryType,
				summaryCount,
				summaryPickup
			]),
			el('div', { class: 'db-checkout-summary-price' }, [
				el('span', { class: 'db-checkout-summary-label', text: 'Order total' }),
				summaryTotal
			])
		]);

		form.appendChild(el('div', { class: 'db-checkout-heading' }, [
			el('p', { class: 'db-order-kicker', text: 'Step 2' }),
			el('h3', { text: 'Your details' }),
			el('p', { text: paying
				? 'We use these details for your receipt and live order updates.'
				: 'We use these details to call you if anything changes and to send your order updates. Pay at the shop when you collect.' })
		]));
		form.appendChild(summary);
		form.appendChild(el('div', { class: 'db-checkout-fields' }, [name, email, phone]));
		form.appendChild(rememberDetailsControl({ customer_name: name, customer_email: email, customer_phone: phone }));
		form.appendChild(address);
		form.appendChild(notes);
		form.appendChild(el('details', { class: 'db-checkout-safety' }, [
			el('summary', { text: 'Allergies and order changes' }),
			el('div', {}, [
				el('p', { text: 'Add allergy or dietary notes above. For severe allergies, also speak with staff before ordering.' }),
				el('p', { text: 'After the shop confirms an order, changes may need manager approval and a payment adjustment.' })
			])
		]));

		var stripeNoticeTotal = null;
		if (stripeHosted) {
			stripeNoticeTotal = el('strong', { class: 'db-stripe-notice-total', text: money(totals && totals.total) + ' AUD' });
			form.appendChild(el('div', { class: 'db-stripe-checkout-notice', role: 'note' }, [
				el('span', { class: 'db-secure-lock', 'aria-hidden': 'true', text: '✓' }),
				el('div', {}, [
					el('strong', { text: 'Secure checkout powered by Stripe' }),
					el('span', { text: 'Continue to Stripe to review and pay ' }, [stripeNoticeTotal]),
					el('ul', { class: 'db-stripe-checkout-points' }, [
						el('li', { text: 'Apple Pay or Google Pay is offered automatically when eligible' }),
						el('li', { text: 'Card is always available; DoughBoss never stores your card details' }),
						el('li', { text: 'You return here for your order number, receipt and live tracking' })
					]),
					el('span', { class: 'db-payment-methods', text: 'Apple Pay  ·  Google Pay  ·  Card' })
				])
			]));
		}

		if (squareHosted) {
			form.appendChild(el('div', { class: 'db-stripe-checkout-notice db-square-hosted-notice', role: 'note' }, [
				el('span', { class: 'db-secure-lock', 'aria-hidden': 'true', text: '✓' }),
				el('div', {}, [
					el('strong', { text: 'Secure checkout powered by Square' }),
					el('span', { text: 'Continue to Square to review and pay ' + money(totals && totals.total) + ' AUD' }),
					el('ul', { class: 'db-stripe-checkout-points' }, [
						el('li', { text: 'Apple Pay or Google Pay is offered on Square when eligible' }),
						el('li', { text: 'Card details are entered on Square; DoughBoss never sees or stores them' }),
						el('li', { text: 'You return here for your order number, receipt and live tracking' })
					]),
					el('span', { class: 'db-payment-methods', text: 'Apple Pay  ·  Google Pay  ·  Card' })
				])
			]));
			squareHostedReturn = el('a', { class: 'db-link db-square-hosted-resume', href: '#', hidden: 'hidden', text: 'Return to the secure Square checkout' });
			squareHostedCancel = el('button', { class: 'db-link db-square-hosted-cancel', type: 'button', hidden: 'hidden', text: 'Change my order instead' });
			squareHostedCancel.addEventListener('click', function () {
				// Only offered after the server reported this checkout UNPAID. The
				// next payment start retires that Square link server-side first,
				// and refuses if Square shows any payment on it.
				clearSquareHostedPending();
				squareHostedActive = null;
				paymentAttemptKey = newPaymentAttemptKey();
				checkoutAttemptId = null;
				lockSquareHostedDetails(null);
				squareHostedReturn.hidden = true;
				squareHostedReturn.setAttribute('hidden', 'hidden');
				squareHostedCancel.hidden = true;
				squareHostedCancel.setAttribute('hidden', 'hidden');
				setPaymentMutationLock(false);
				if (onJourneyStep) { onJourneyStep(2); }
				submit.disabled = false;
				payLabel = payLabelFor(totals);
				submit.textContent = payLabel;
				clearError(msg);
			});
		}

		// Tyro Connect: hosted payment form and automatic 3DS.
		var tyro = null;
		if (tyroPay) {
			tyro = tyroConnectCheckout(form, msg, function () {
				return {
					orderType: orderType,
					locationId: getLocationId ? getLocationId() : storedLocationId(),
					pickup: pickupPayloadFields(),
					// A live shop needs a held time before the payment intent exists.
					pickupPending: !!(pickup && typeof pickup.required === 'function' && pickup.required() && !pickupSelectionNow())
				};
			}, paymentAttemptKey);
		}
		if (mpgsHosted) {
			form.appendChild(el('p', {
				class: 'db-cardfield db-mpgs-notice',
				text: 'Card details will be entered securely on the Mastercard payment page.'
			}));
		}

		form.appendChild(submit);
		if (squareHostedReturn) { form.appendChild(squareHostedReturn); }
		if (squareHostedCancel) { form.appendChild(squareHostedCancel); }
		form.appendChild(el('p', { class: 'db-submit-note', text: paying
			? 'You will review the final amount before payment. One payment creates one order.'
			: 'This sends your order to the shop. Nothing is charged online — you pay when you collect.' }));
		form.appendChild(msg);
		form.addEventListener('focusin', function () {
			if (onJourneyStep && !paymentMutationLock) { onJourneyStep(2); }
		});
		trackCommerce('begin_checkout', {
			currency: 'AUD',
			value: Number(totals && totals.total || 0),
			num_items: Number(totals && (totals.item_count || totals.quantity) || 0),
			order_type: orderType,
			location_id: getLocationId ? getLocationId() : storedLocationId(),
			channel: activeTableContext() ? 'table_qr' : 'web'
		});
		// One browser attempt keeps one checkout key and, after payment succeeds,
		// one provider reference until the server confirms the order. A lost HTTP
		// response can therefore be retried without charging or ordering twice.
		var checkoutAttemptId = null;
		var checkoutPaymentId = null;

		function fail(err) {
			setPaymentMutationLock(false);
			form.setAttribute('aria-busy', 'false');
			if (onJourneyStep) { onJourneyStep(2); }
			submit.disabled = false;
			payLabel = payLabelFor(totals);
			submit.textContent = payLabel;
			// The server rejected the pickup facts (slot filled, hold lapsed, cart
			// changed): re-sync the picker and let it explain, rather than a bare error.
			if (pickup && typeof pickup.recover === 'function' && err && pickup.recover(err.code, err.message)) { return; }
			announceError(msg, err.message || (I18N.genericError || 'Something went wrong.'));
		}

		function stripePaidReturnFail() {
			// Stripe may already have captured this Session. Keep the cart/payment
			// locked so an uncertain browser return can never start a second charge;
			// the signed webhook remains the recovery authority.
			setPaymentMutationLock(true);
			form.setAttribute('aria-busy', 'false');
			if (onJourneyStep) { onJourneyStep(3); }
			submit.disabled = true;
			submit.textContent = 'Payment confirmation pending';
			announceError(msg, 'Your payment may already be complete. Please do not pay again. Keep this page open or contact the shop so we can confirm your order.');
		}

		function readSquareHostedPending() {
			try { return JSON.parse(window.sessionStorage.getItem(SQUARE_HOSTED_PENDING_KEY) || 'null'); } catch (ignoreHostedPending) { return null; }
		}

		function clearSquareHostedPending() {
			try { window.sessionStorage.removeItem(SQUARE_HOSTED_PENDING_KEY); } catch (ignoreHostedClear) {}
		}

		function cleanSquareHostedUrl() {
			try {
				var cleanUrl = new URL(window.location.href);
				cleanUrl.searchParams.delete('doughboss_square_return');
				['orderId', 'transactionId', 'referenceId', 'checkoutId'].forEach(function (key) { cleanUrl.searchParams.delete(key); });
				window.history.replaceState({}, document.title, cleanUrl.toString());
			} catch (ignoreHostedUrl) {}
		}

		function lockSquareHostedDetails(details) {
			[
				[name, 'customer_name'], [email, 'customer_email'], [phone, 'customer_phone'],
				[address, 'address'], [notes, 'notes']
			].forEach(function (entry) {
				var input = entry[0].querySelector('input,textarea');
				if (!input) { return; }
				if (details && typeof details[entry[1]] === 'string') { input.value = details[entry[1]]; }
				input.readOnly = !!details;
			});
		}

		// A known hosted checkout may only be confirmed (or reopened on Square),
		// never replaced by a second payment while the server says it is open.
		function squareHostedHold(err, pending) {
			var data = err && err.data && typeof err.data === 'object' ? err.data : {};
			setPaymentMutationLock(true);
			form.setAttribute('aria-busy', 'false');
			if (onJourneyStep) { onJourneyStep(3); }
			submit.disabled = false;
			submit.textContent = 'Check my Square payment';
			squareHostedActive = pending;
			if (squareHostedReturn) {
				var resumeUrl = data.checkout_url || pending.checkoutUrl || '';
				if (data.hosted_state === 'unpaid' && squareHostedCheckoutUrlValid(resumeUrl, !!PAY.liveMode)) {
					squareHostedReturn.hidden = false;
					squareHostedReturn.removeAttribute('hidden');
					squareHostedReturn.setAttribute('href', resumeUrl);
				} else {
					squareHostedReturn.hidden = true;
					squareHostedReturn.setAttribute('hidden', 'hidden');
				}
			}
			if (squareHostedCancel) {
				if (data.hosted_state === 'unpaid') {
					squareHostedCancel.hidden = false;
					squareHostedCancel.removeAttribute('hidden');
				} else {
					squareHostedCancel.hidden = true;
					squareHostedCancel.setAttribute('hidden', 'hidden');
				}
			}
			announceError(msg, data.hosted_state === 'unpaid'
				? (err.message || 'We have not received a completed Square payment yet.')
				: 'Your payment may already be complete. Please do not pay again. Keep this page open or contact the shop so we can confirm your order.');
		}

		function squareHostedClosed(err) {
			clearSquareHostedPending();
			squareHostedActive = null;
			if (squareHostedReturn) { squareHostedReturn.hidden = true; squareHostedReturn.setAttribute('hidden', 'hidden'); }
			// The closed attempt took no money: a new key starts a new checkout.
			paymentAttemptKey = newPaymentAttemptKey();
			checkoutAttemptId = null;
			lockSquareHostedDetails(null);
			fail(err);
		}

		function finishSquareHosted(pending, returned) {
			squareHostedActive = pending;
			setPaymentMutationLock(true);
			form.setAttribute('aria-busy', 'true');
			if (onJourneyStep) { onJourneyStep(3); }
			submit.disabled = true;
			submit.textContent = 'Checking your Square payment...';
			msg.textContent = 'We are confirming your payment with Square — please keep this page open.';
			msg.className = 'db-checkout-msg db-payment-verifying';
			var body = Object.assign({}, pending.payload || {}, { square_checkout: pending.checkoutReference });
			delete body.payment_intent_id;
			return confirmSquareHosted(function () {
				return request('/checkout', { method: 'POST', body: body, headers: { 'Idempotency-Key': pending.checkoutAttemptId } });
			}, function (ms) {
				return new Promise(function (resolve) { setTimeout(resolve, ms || 0); });
			}, returned ? 5 : 1).then(function (res) {
				clearSquareHostedPending();
				squareHostedActive = null;
				cleanSquareHostedUrl();
				completeOrder(res, body);
			}).catch(function (err) {
				var data = err && err.data && typeof err.data === 'object' ? err.data : {};
				if (data.hosted_state === 'failed' || (data.outcome === 'terminal_no_charge' && data.payment_pending !== true)) {
					cleanSquareHostedUrl();
					squareHostedClosed(err);
					return;
				}
				squareHostedHold(err, pending);
			});
		}

		function completeOrder(res, payload) {
			checkoutAttemptId = null;
			checkoutPaymentId = null;
			setPaymentMutationLock(false);
			form.setAttribute('aria-busy', 'false');
			if (onJourneyStep) { onJourneyStep(4); }
			// Capture the parent BEFORE clearing it: clearing via innerHTML
			// detaches `form` from the document, which nulls form.parentNode —
			// re-reading form.parentNode afterwards to append the confirmation
			// would throw against null.
			var parent = form.parentNode;
			parent.innerHTML = '';
			var confirmation = el('div', { class: 'db-confirm', role: 'status', 'aria-live': 'polite', tabindex: '-1' }, [
				el('div', { class: 'db-confirm-check', 'aria-hidden': 'true', text: '✓' }),
				el('p', { class: 'db-order-kicker', text: paying ? 'Payment confirmed' : 'Order confirmed' }),
				el('h3', { text: 'Your order is in' }),
				el('p', { text: 'Keep this order number for live updates.' })
			]);
			var orderNumber = el('strong', { class: 'db-confirm-number', text: res.order_number || '' });
			var copyNumber = el('button', { class: 'db-link db-confirm-copy', type: 'button', text: 'Copy number' });
			copyNumber.addEventListener('click', function () {
				var copied = window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText
					? window.navigator.clipboard.writeText(String(res.order_number || ''))
					: Promise.reject(new Error('Clipboard unavailable'));
				copied.then(function () {
					copyNumber.textContent = 'Copied';
					dbToast('Order number copied', true);
				}).catch(function () {
					copyNumber.textContent = 'Select and copy the number';
				});
			});
			confirmation.appendChild(el('div', { class: 'db-confirm-number-wrap' }, [orderNumber, copyNumber]));
			if (res.table_label) {
				confirmation.appendChild(el('p', { class: 'db-table-context', text: 'Dine in · ' + (res.location_name ? res.location_name + ' · ' : '') + 'Table ' + res.table_label + ' · We will bring it to you.' }));
			}
			confirmation.appendChild(el('div', { class: 'db-confirm-facts' }, [
				el('span', {}, [el('small', { text: 'Status' }), el('strong', { text: 'Sent to the shop' })]),
				pickupConfirmationText(res) ? el('span', { class: 'db-confirm-fact--pickup' }, [el('small', { text: 'Pickup' }), el('strong', { text: pickupConfirmationText(res) })]) : null,
				el('span', {}, [el('small', { text: 'Payment' }), el('strong', { text: paying ? 'Paid' : 'Pay at shop' })]),
				el('span', {}, [el('small', { text: 'Total' }), el('strong', { text: money(res.total) })])
			]));
			// Only promise a tracker when one is actually reachable from here:
			// /order/ carries no tracking widget, and the API returns an empty
			// tracking_url while payments are off, so the original copy pointed
			// at a "live tracker" with no link on screen.
			var tracker = document.querySelector('[data-doughboss-tracking]');
			var hasTracking = !!(res.tracking_url || tracker);
			confirmation.appendChild(el('p', {
				class: 'db-confirm-next',
				text: hasTracking
					? 'The shop will accept your order next. We will email meaningful updates, or you can follow the live tracker.'
					: 'The shop will accept your order next. We will email meaningful updates — keep your order number handy when you collect.'
			}));
			var confirmActions = el('div', { class: 'db-confirm-actions' });
			if (res.tracking_url) {
				confirmActions.appendChild(el('a', { class: 'db-btn db-btn--track', href: res.tracking_url, rel: 'noreferrer', text: 'Track this order' }));
			} else if (tracker) {
				confirmActions.appendChild(el('a', {
					class: 'db-btn db-btn--track',
					href: '#track-order',
					text: 'Track this order',
					onclick: function () {
						var trackForm = tracker.querySelector('.db-track-form');
						if (!trackForm) { return; }
						trackForm.number.value = res.order_number || '';
						trackForm.email.value = payload.customer_email || '';
					}
				}));
			}
			confirmation.appendChild(confirmActions);
			var review = el('div', { class: 'db-review-invite' });
			review.appendChild(el('strong', { text: 'Stay close to the bake.' }));
			review.appendChild(el('span', { text: 'Follow Dough Boss for fresh drops, offers and what is coming out of the oven.' }));
			var reviewActions = el('div', { class: 'db-review-invite__actions' });
			reviewActions.appendChild(el('a', {
				href: 'https://instagram.com/doughboss',
				target: '_blank',
				rel: 'noopener noreferrer',
				'data-doughboss-engagement': 'social_engagement',
				'data-content-name': 'Instagram',
				'data-channel': 'order_success',
				text: 'Follow @doughboss ↗'
			}));
			if (DATA.googleReviewUrl) {
				reviewActions.appendChild(el('a', {
					href: DATA.googleReviewUrl,
					target: '_blank',
					rel: 'noopener noreferrer',
					'data-doughboss-engagement': 'review_engagement',
					'data-content-name': 'Google review',
					'data-channel': 'order_success',
					text: 'Leave a Google review ↗'
				}));
			}
			review.appendChild(reviewActions);
			confirmation.appendChild(review);
			parent.appendChild(confirmation);
			trackCommerce('purchase', {
				currency: 'AUD',
				value: Number(res.total || 0),
				num_items: Number(totals && (totals.item_count || totals.quantity) || 0),
				order_type: payload.order_type,
				location_id: payload.location_id,
				channel: activeTableContext() ? 'table_qr' : 'web'
			});
			// Mark this cart widget done BEFORE notifying — the notification
			// triggers this same widget's own reload listener, which must not
			// overwrite the confirmation just shown with an "empty cart" render.
			if (onOrderComplete) { onOrderComplete(); }
			notifyCartChanged();
			// The page collapses when the cart and form are replaced, leaving
			// the confirmation above the viewport (measured: y=-637 on a 390px
			// phone). Take the customer to their order number and announce it.
			// 'start' (with .db-confirm's scroll-margin-top clearing the sticky
			// header) lands the tick and the order number at the top of the
			// screen; 'center' left the top of a long confirmation cut off.
			revealNode(confirmation, true, 'start');
		}

		function placeOrder(payload) {
			if (!checkoutAttemptId) {
				checkoutAttemptId = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (String(Date.now()) + '-' + Math.random());
			}
			return request('/checkout', { method: 'POST', body: payload, headers: { 'Idempotency-Key': checkoutAttemptId } }).then(function (res) {
				completeOrder(res, payload);
			});
		}

		// Square owns its charge flow, but uses these form-local events to enter
		// the same confirmation and accessible error paths as every other checkout.
		// They are deliberately not a global API: only this checkout form consumes
		// them, after the Square interceptor has completed its server-side work.
		form.addEventListener('doughboss:checkout-complete', function (event) {
			var detail = event.detail || {};
			if (!detail.response) { return; }
			completeOrder(detail.response, detail.payload || {});
		});
		form.addEventListener('doughboss:checkout-error', function (event) {
			var detail = event.detail || {};
			setPaymentMutationLock(!!detail.paymentPending);
			if (onJourneyStep) { onJourneyStep(detail.paymentPending ? 3 : 2); }
			if (!detail.paymentPending && detail.code && pickup && typeof pickup.recover === 'function' && pickup.recover(detail.code, detail.message)) { return; }
			announceError(msg, detail.message || (I18N.genericError || 'Something went wrong.'));
		});
		form.addEventListener('doughboss:checkout-start', function () {
			setPaymentMutationLock(true);
			clearError(msg);
			if (onJourneyStep) { onJourneyStep(3); }
		});

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			// Live pickup shops need a held time first. The gate announces its own
			// reason in the picker; nothing is locked or sent until it passes.
			if (pickup && typeof pickup.gate === 'function' && !(stripeHosted && stripeCancelledPayload) && !squareHostedActive && pickup.gate()) {
				return;
			}
			setPaymentMutationLock(true);
			submit.disabled = true;
			form.setAttribute('aria-busy', 'true');
			if (onJourneyStep) { onJourneyStep(paying ? 3 : 2); }
			clearError(msg);
			msg.className = 'db-checkout-msg';

			// A cancelled Stripe Session remains payable. Its retry must submit the
			// exact immutable details that created it, even if the DOM is modified.
			var payload = stripeHosted && stripeCancelledPayload ? Object.assign({}, stripeCancelledPayload) : {
				order_type: orderType,
				location_id: getLocationId ? getLocationId() : storedLocationId(),
				customer_name: name.querySelector('input,textarea').value,
				customer_email: email.querySelector('input,textarea').value,
				customer_phone: phone.querySelector('input,textarea').value,
				address: address.querySelector('input,textarea').value,
				notes: notes.querySelector('input,textarea').value
			};
			if (!(stripeHosted && stripeCancelledPayload)) { Object.assign(payload, pickupPayloadFields()); }
			if (paying) { payload.payment_attempt_key = paymentAttemptKey; }

			if (squareHosted && squareHostedActive) {
				// A hosted checkout already exists for this order: confirm it.
				finishSquareHosted(squareHostedActive, true);
			} else if (squareHosted) {
				// Save only the non-card order details in this tab, then redirect
				// to Square's own checkout page for this exact itemised order.
				submit.textContent = I18N.payPreparing || 'Opening secure Square checkout...';
				if (!checkoutAttemptId) {
					checkoutAttemptId = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (String(Date.now()) + '-' + Math.random());
				}
				request('/payment-intent', {
					method: 'POST',
					body: Object.assign({
						order_type: orderType,
						location_id: payload.location_id,
						payment_attempt_key: paymentAttemptKey,
						customer_name: payload.customer_name,
						customer_email: payload.customer_email,
						customer_phone: payload.customer_phone,
						address: payload.address,
						notes: payload.notes,
						return_url: window.location.href
					}, pickupBody(payload))
				}).then(function (session) {
					if (!session || session.gateway !== 'square' || session.hosted !== true || !/^[a-f0-9]{64}$/.test(String(session.checkout_reference || ''))
						|| (session.state !== 'confirm' && !squareHostedCheckoutUrlValid(session.checkout_url, !!PAY.liveMode))) {
						throw new Error('The secure Square checkout link could not be verified.');
					}
					var pending = {
						checkoutReference: session.checkout_reference,
						checkoutUrl: session.state === 'confirm' ? '' : session.checkout_url,
						payload: payload,
						checkoutAttemptId: checkoutAttemptId,
						savedAt: Date.now()
					};
					try {
						window.sessionStorage.setItem(SQUARE_HOSTED_PENDING_KEY, JSON.stringify(pending));
					} catch (storageError) {
						if (session.state !== 'confirm') {
							throw new Error('Your browser could not preserve the order for the secure payment return.');
						}
					}
					if (session.state === 'confirm') {
						return finishSquareHosted(pending, true);
					}
					squareHostedActive = pending;
					window.location.assign(session.checkout_url);
				}).catch(function (err) {
					var data = err && err.data && typeof err.data === 'object' ? err.data : {};
					if (data.payment_pending === true) {
						squareHostedHold(err, squareHostedActive || { checkoutReference: '', payload: payload, checkoutAttemptId: checkoutAttemptId });
						if (!squareHostedActive || !squareHostedActive.checkoutReference) {
							squareHostedActive = null;
							submit.disabled = true;
							submit.textContent = 'Payment confirmation pending';
						}
					} else if (data.outcome === 'terminal_no_charge') {
						squareHostedClosed(err);
					} else {
						// Retry-safe: keep this attempt key so a retry reuses the same link.
						fail(err);
					}
				});
			} else if (stripeHosted) {
				// Save only the non-card order details in this tab, then redirect
				// to the unique Stripe-hosted Checkout URL for this cart.
				setPaymentMutationLock(true);
				submit.textContent = I18N.payPreparing || 'Opening secure Stripe Checkout...';
				if (!checkoutAttemptId) {
					checkoutAttemptId = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (String(Date.now()) + '-' + Math.random());
				}
				request('/payment-intent', {
					method: 'POST',
					body: Object.assign({
						order_type: orderType,
						location_id: payload.location_id,
						payment_attempt_key: paymentAttemptKey,
						customer_name: payload.customer_name,
						customer_email: payload.customer_email,
						customer_phone: payload.customer_phone,
						address: payload.address,
						notes: payload.notes,
						return_url: window.location.href
					}, pickupBody(payload))
				}).then(function (session) {
					if (!session || !/^cs_(test|live)_/.test(session.checkout_session || '') || !/^https:\/\/checkout\.stripe\.com\//.test(session.checkout_url || '')) {
						throw new Error('The secure Stripe Checkout link could not be verified.');
					}
					payload.payment_intent_id = session.checkout_session;
					try {
						window.sessionStorage.setItem('doughbossStripePending', JSON.stringify({
							sessionId: session.checkout_session,
							payload: payload,
							checkoutAttemptId: checkoutAttemptId,
							savedAt: Date.now()
						}));
					} catch (storageError) {
						throw new Error('Your browser could not preserve the order for the secure payment return.');
					}
					window.location.assign(session.checkout_url);
				}).catch(fail);
			} else if (tyro) {
				// 1) make sure the hosted card session is live, 2) create a fresh
				// payment reference for the cart AS IT IS NOW, 3) push the gateway-
				// hosted card fields into the session, 4) place the order with the
				// composite payment reference — the server submits and verifies the
				// actual charge before accepting the order as paid. Note the card
				// details themselves never appear in `payload`: they live only in
				// Tyro's hosted session.
				submit.textContent = I18N.payProcessing || 'Processing payment…';
				if (checkoutPaymentId) {
					payload.payment_intent_id = checkoutPaymentId;
					placeOrder(payload).catch(fail);
					return;
				}
				tyro.pay().then(function (paymentId) {
					checkoutPaymentId = paymentId;
					payload.payment_intent_id = checkoutPaymentId;
					return placeOrder(payload);
				}).catch(fail);
			} else if (mpgsHosted) {
				// Create a server-bound Hosted Checkout session, save only the
				// non-card checkout form in this tab, then redirect to Mastercard.
				// On return the server retrieves and verifies the MPGS order before
				// DoughBoss records the order as paid.
				submit.textContent = I18N.payProcessing || 'Processing payment…';
				if (!checkoutAttemptId) {
					checkoutAttemptId = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (String(Date.now()) + '-' + Math.random());
				}
				request('/payment-intent', {
					method: 'POST',
					body: Object.assign({
						order_type: orderType,
						location_id: payload.location_id,
						payment_attempt_key: paymentAttemptKey,
						return_url: mpgsReturnUrl()
					}, pickupBody(payload))
				}).then(function (pi) {
					payload.payment_intent_id = pi.payment_intent;
					try {
						window.sessionStorage.setItem('doughbossMpgsPending', JSON.stringify({
							orderId: pi.payment_intent,
							payload: payload,
							checkoutAttemptId: checkoutAttemptId,
							savedAt: Date.now()
						}));
					} catch (storageError) {
						throw new Error('Your browser could not preserve the order for the secure payment return.');
					}
					window.Checkout.configure({ session: { id: pi.client_secret } });
					window.Checkout.showPaymentPage();
				}).catch(fail);
			} else {
				submit.textContent = I18N.placing || 'Placing order…';
				placeOrder(payload).catch(fail);
			}
		});

		// Complete the server-verified order after Stripe returns to this same
		// checkout page. The session id from the URL is only a lookup key: the
		// server retrieves Stripe and re-verifies payment, amount, currency and
		// immutable cart metadata before creating an order.
		if (stripeHosted && typeof window.URLSearchParams === 'function') {
			var stripeReturnParams = new URLSearchParams(window.location.search);
			var stripeReturnFragment = new URLSearchParams((window.location.hash || '').replace(/^#/, ''));
			var stripeReturnState = (
				stripeReturnFragment.get('doughboss_stripe_return') === '1'
				|| stripeReturnFragment.get('doughboss_stripe_cancel') === '1'
			) ? stripeReturnFragment : stripeReturnParams;
			if (stripeReturnState.get('doughboss_stripe_return') === '1') {
				var stripePending = null;
				try {
					stripePending = JSON.parse(window.sessionStorage.getItem('doughbossStripePending') || 'null');
				} catch (ignoreStripePending) {}
				var returnedSession = stripeReturnState.get('session_id') || '';
				if (stripePending && stripePending.sessionId === returnedSession && stripePending.payload && (Date.now() - Number(stripePending.savedAt || 0)) < 45 * 60 * 1000) {
					checkoutAttemptId = stripePending.checkoutAttemptId;
					stripePending.payload.payment_intent_id = returnedSession;
					setPaymentMutationLock(true);
					form.setAttribute('aria-busy', 'true');
					if (onJourneyStep) { onJourneyStep(3); }
					submit.disabled = true;
					submit.textContent = 'Verifying Stripe payment...';
					msg.textContent = 'Payment received. We are securely matching it to your order—please keep this page open.';
					msg.className = 'db-checkout-msg db-payment-verifying';
					placeOrder(stripePending.payload).then(function () {
						window.sessionStorage.removeItem('doughbossStripePending');
						var cleanStripeUrl = new URL(window.location.href);
						['doughboss_stripe_return', 'doughboss_stripe_cancel', 'session_id'].forEach(function (key) { cleanStripeUrl.searchParams.delete(key); });
						cleanStripeUrl.hash = '';
						window.history.replaceState({}, document.title, cleanStripeUrl.toString());
					}).catch(stripePaidReturnFail);
				} else {
					stripePaidReturnFail();
				}
			} else if (stripeReturnState.get('doughboss_stripe_cancel') === '1') {
				try {
					var cancelledStripePending = JSON.parse(window.sessionStorage.getItem('doughbossStripePending') || 'null');
					if (cancelledStripePending && cancelledStripePending.payload && /^[A-Za-z0-9._:-]{8,128}$/.test(cancelledStripePending.payload.payment_attempt_key || '') && (Date.now() - Number(cancelledStripePending.savedAt || 0)) < 45 * 60 * 1000) {
						paymentAttemptKey = cancelledStripePending.payload.payment_attempt_key;
						checkoutAttemptId = cancelledStripePending.checkoutAttemptId || checkoutAttemptId;
						stripeCancelledPayload = Object.assign({}, cancelledStripePending.payload);
						orderType = stripeCancelledPayload.order_type;
						[
							[name, 'customer_name'], [email, 'customer_email'], [phone, 'customer_phone'],
							[address, 'address'], [notes, 'notes']
						].forEach(function (entry) {
							var input = entry[0].querySelector('input,textarea');
							input.value = stripeCancelledPayload[entry[1]] || '';
							input.readOnly = true;
						});
						address.style.display = orderType === 'delivery' ? '' : 'none';
						summaryType.textContent = orderType === 'delivery' ? 'Delivery' : (orderType === 'dine_in' ? 'Dine in' : 'Pickup');
					}
				} catch (ignoreStripeCancelledPending) {}
				window.sessionStorage.removeItem('doughbossStripePending');
				setPaymentMutationLock(true);
				form.setAttribute('aria-busy', 'false');
				if (onJourneyStep) { onJourneyStep(2); }
				// Keep this attempt key: Stripe's cancelled Session is still payable,
				// so retrying must reuse the same server-side voucher lease and URL.
				var cancelledStripeUrl = new URL(window.location.href);
				cancelledStripeUrl.searchParams.delete('doughboss_stripe_cancel');
				cancelledStripeUrl.hash = '';
				window.history.replaceState({}, document.title, cancelledStripeUrl.toString());
				if (stripeCancelledPayload) {
					msg.textContent = 'Payment was cancelled. Your original details are restored and locked so you can safely reopen the same secure payment session.';
					msg.className = 'db-checkout-msg';
				} else {
					stripePaidReturnFail();
				}
			}
		}

		// Resume a Square-hosted checkout when the customer returns (Square's
		// redirect carries the checkout reference) or comes back to this page.
		// The reference is only a lookup key: the server re-reads Square.
		if (squareHosted) {
			var savedSquareHosted = readSquareHostedPending();
			var returnedSquareKey = squareHostedReturnKey(window.location.search);
			if (squareHostedPendingFresh(savedSquareHosted, Date.now()) && (!returnedSquareKey || returnedSquareKey === savedSquareHosted.checkoutReference)) {
				paymentAttemptKey = savedSquareHosted.payload.payment_attempt_key;
				checkoutAttemptId = savedSquareHosted.checkoutAttemptId;
				lockSquareHostedDetails(savedSquareHosted.payload);
				finishSquareHosted(savedSquareHosted, !!returnedSquareKey);
			} else if (returnedSquareKey) {
				// This tab lost its saved details; the server still confirms by
				// cart session and Square's own records, never by this browser.
				finishSquareHosted({
					checkoutReference: returnedSquareKey,
					payload: {},
					checkoutAttemptId: newPaymentAttemptKey() + '-' + String(Date.now())
				}, true);
			} else if (savedSquareHosted) {
				clearSquareHostedPending();
			}
		}

		// Complete the server-verified order after Mastercard returns to this
		// same checkout page. The resultIndicator alone is never trusted.
		if (mpgsHosted && typeof window.URLSearchParams === 'function') {
			var returnParams = new URLSearchParams(window.location.search);
			if (returnParams.get('doughboss_mpgs_return') === '1') {
				var pending = null;
				try {
					pending = JSON.parse(window.sessionStorage.getItem('doughbossMpgsPending') || 'null');
				} catch (ignorePending) {}
				var returnedOrder = returnParams.get('doughboss_mpgs_order') || '';
				if (pending && pending.orderId === returnedOrder && pending.payload && (Date.now() - Number(pending.savedAt || 0)) < 30 * 60 * 1000) {
					checkoutAttemptId = pending.checkoutAttemptId;
					form.setAttribute('aria-busy', 'true');
					if (onJourneyStep) { onJourneyStep(3); }
					submit.disabled = true;
					submit.textContent = 'Verifying Mastercard payment…';
					placeOrder(pending.payload).then(function () {
						window.sessionStorage.removeItem('doughbossMpgsPending');
						var cleanUrl = new URL(window.location.href);
						['doughboss_mpgs_return', 'doughboss_mpgs_order', 'resultIndicator', 'sessionVersion', 'checkoutVersion'].forEach(function (key) { cleanUrl.searchParams.delete(key); });
						window.history.replaceState({}, document.title, cleanUrl.toString());
					}).catch(fail);
				} else {
					fail(new Error('The Mastercard payment return could not be matched to this cart. No order was placed.'));
				}
			}
		}

		// Called when the server cart reloads. A prepared but unconfirmed Stripe
		// session belongs to the previous cart snapshot, so discard only that
		// secure UI and preserve the customer's typed contact fields.
		function update(newOrderType, newTotals) {
			orderType = newOrderType;
			totals = newTotals;
			address.style.display = orderType === 'delivery' ? '' : 'none';
			var addrInput = address.querySelector('input,textarea');
			if (addrInput) { addrInput.required = orderType === 'delivery'; }
			payLabel = payLabelFor(totals);
			summaryType.textContent = orderType === 'delivery' ? 'Delivery' : (orderType === 'dine_in' ? 'Dine in' : 'Pickup');
			var itemCount = Number(totals && (totals.item_count || totals.quantity) || 0);
			summaryCount.textContent = itemCount ? itemCount + (itemCount === 1 ? ' item' : ' items') : 'Review your items and total';
			summaryTotal.textContent = money(totals && totals.total);
			if (stripeNoticeTotal) { stripeNoticeTotal.textContent = money(totals && totals.total) + ' AUD'; }
			// Do not replace an in-flight payment or order confirmation label.
			if (!submit.disabled) { submit.textContent = payLabel; }
		}

		return { form: form, update: update };
	}

	function mpgsReturnUrl() {
		var cleanUrl = new URL(window.location.href);
		var safe = new URL(cleanUrl.origin + cleanUrl.pathname);
		['page_id', 'p'].forEach(function (key) {
			var value = cleanUrl.searchParams.get(key);
			if (/^[1-9][0-9]*$/.test(value || '')) { safe.searchParams.set(key, value); }
		});
		if (cleanUrl.searchParams.get('preview') === 'true') { safe.searchParams.set('preview', 'true'); }
		return safe.toString();
	}

	var fieldSeq = 0;

	function field(type, nameAttr, label, required, attributes) {
		var input = type === 'textarea'
			? el('textarea', { name: nameAttr })
			: el('input', { type: type, name: nameAttr });
		Object.keys(attributes || {}).forEach(function (key) {
			input.setAttribute(key, attributes[key]);
		});
		if (required) { input.required = true; }

		// Inline, per-field validation on top of (not instead of) the browser's
		// own. The native bubble disappears the moment the customer taps away;
		// this message stays put, is read by screen readers via aria-describedby,
		// and marks the field aria-invalid so the styling and the a11y tree agree.
		fieldSeq += 1;
		var errorId = 'db-field-error-' + fieldSeq;
		var error = el('span', { class: 'db-field-error', id: errorId, 'aria-live': 'polite' });

		function showFieldError() {
			var text = input.validity && input.validity.valueMissing
				? (I18N.fieldRequired || 'Please fill this in.')
				: (input.validationMessage || (I18N.fieldInvalid || 'Please check this.'));
			error.textContent = text;
			input.setAttribute('aria-invalid', 'true');
			input.setAttribute('aria-describedby', errorId);
		}

		function clearFieldError() {
			if (input.getAttribute('aria-invalid') !== 'true') { return; }
			error.textContent = '';
			input.removeAttribute('aria-invalid');
			input.removeAttribute('aria-describedby');
		}

		input.addEventListener('invalid', showFieldError);
		input.addEventListener('input', function () {
			if (input.checkValidity && input.checkValidity()) { clearFieldError(); }
		});
		// Only correct what the customer actually typed. An empty required field
		// is left to the submit-time check so tabbing through the form does not
		// scold them before they have started.
		input.addEventListener('blur', function () {
			if (!input.value) { return; }
			if (input.checkValidity && !input.checkValidity()) { showFieldError(); }
		});

		return el('label', { class: 'db-field' }, [
			el('span', { text: label + (required ? ' *' : '') }),
			input,
			error
		]);
	}

	/* ------------------------------------------------------------------ */
	/* Order tracking                                                     */
	/* ------------------------------------------------------------------ */

	// Coarse progress stages for the customer tracker. Index = stage (0-3);
	// unknown statuses fall back to stage 0 so the tracker never disappears.
	var TRACK_STAGE_MAP = {
		pending: 0,
		confirmed: 1,
		preparing: 1,
		baking: 1,
		ready: 2,
		out_for_delivery: 2,
		completed: 3
	};

	// Parse the API's UTC 'YYYY-MM-DD HH:MM:SS' timestamps to epoch ms.
	// Returns null for anything malformed/absent so callers can bail out.
	function parseUtcTimestamp(value) {
		if (!value || typeof value !== 'string') { return null; }
		var m = value.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/);
		if (!m) { return null; }
		return Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]);
	}

	function renderTracking(root) {
		var form = root.querySelector('.db-track-form');
		var result = root.querySelector('.db-track-result');
		if (!form) { return; }
		var lookupButton = form.querySelector('button[type="submit"]');
		var lookupLabel = lookupButton ? lookupButton.textContent : 'Check live status';

		// Email links prefill only the non-sensitive order number. The customer
		// must still type the matching checkout email, preserving the endpoint's
		// anti-enumeration boundary.
		try {
			var prefill = new URLSearchParams(window.location.search).get('order');
			if (prefill && prefill.length <= 64 && !form.number.value) {
				form.number.value = prefill;
			}
		} catch (error) {}

		var POLL_MS = 15000;
		var POLL_MAX_MS = 2 * 60 * 60 * 1000; // give up after 2 hours
		var pollTimer = null;
		var pollKey = null;    // current lookup; also stale-response guard
		var pollLookup = null; // request body stays in memory, never in a URL
		var pollStarted = 0;
		var pollDone = false;  // permanent stop (completed/cancelled/expired)

		function stopPolling() {
			if (pollTimer) {
				clearTimeout(pollTimer);
				pollTimer = null;
			}
		}

		function scheduleTick() {
			stopPolling();
			if (pollDone || !pollKey || !pollLookup || document.hidden) { return; }
			if (Date.now() - pollStarted >= POLL_MAX_MS) {
				pollDone = true;
				return;
			}
			pollTimer = setTimeout(pollTick, POLL_MS);
		}

		function pollTick() {
			pollTimer = null;
			if (pollDone || !pollKey || !pollLookup) { return; }
			var key = pollKey;
			request('/order/track', { method: 'POST', body: pollLookup })
				.then(function (order) {
					if (key !== pollKey) { return; } // a newer lookup took over
					renderOrder(order);
					scheduleTick();
				})
				.catch(function () {
					// Silent: keep the last good render, retry on the next tick.
					if (key !== pollKey) { return; }
					scheduleTick();
				});
		}

		// Pause polling while the tab is hidden, resume when visible again.
		document.addEventListener('visibilitychange', function () {
			if (document.hidden) {
				stopPolling();
			} else {
				scheduleTick();
			}
		});

		// 4-stage horizontal progress tracker (pickup vs delivery wording).
		function buildTracker(order) {
			var isDelivery = order.order_type === 'delivery';
			var isDineIn = order.order_type === 'dine_in';
			var labels = [
				'Order placed',
				'Being prepared',
				isDelivery ? 'On its way' : (isDineIn ? 'Ready to serve' : 'Ready for pickup'),
				isDelivery ? 'Delivered' : (isDineIn ? 'Served' : 'Picked up')
			];
			var current = TRACK_STAGE_MAP.hasOwnProperty(order.status) ? TRACK_STAGE_MAP[order.status] : 0;
			var list = el('ol', { class: 'db-stage-tracker', 'aria-label': 'Order progress' });
			labels.forEach(function (label, i) {
				// A completed order is fully done, check on every stage.
				var done = i < current || order.status === 'completed';
				var cls = 'db-stage' + (done ? ' db-stage--done' : '') + (i === current ? ' db-stage--current' : '');
				var item = el('li', { class: cls }, [
					el('span', { class: 'db-stage-dot', 'aria-hidden': 'true', text: done ? '✓' : '' }),
					el('span', { class: 'db-stage-label', text: label })
				]);
				if (i === current) { item.setAttribute('aria-current', 'step'); }
				list.appendChild(item);
			});
			return list;
		}

		// Honest ETA line. accepted_at is a new server field that may not be
		// deployed yet — when it's missing the countdown is simply omitted.
		function buildEta(order) {
			if (order.status === 'ready') {
				return el('p', { class: 'db-track-eta db-track-eta--ready', text: 'Your order is ready!' });
			}
			if (order.status === 'completed' || order.status === 'cancelled') { return null; }
			var accepted = parseUtcTimestamp(order.accepted_at);
			var etaMinutes = Number(order.eta_minutes || 0);
			if (accepted === null || !(etaMinutes > 0)) { return null; }
			var remaining = Math.ceil((accepted + etaMinutes * 60000 - Date.now()) / 60000);
			return el('p', {
				class: 'db-track-eta',
				text: remaining > 0 ? 'Ready in about ' + remaining + 'm' : 'Any minute now…'
			});
		}

		function buildPaymentHint(order) {
			if (order.payment_status === 'refunded') {
				return el('p', { class: 'db-track-payment', text: 'Payment: refunded' });
			}
			if (order.payment_status === 'paid') {
				return el('p', { class: 'db-track-paid', text: order.customer_status === 'cancelled' ? 'Payment: paid — contact the shop for the refund status' : '✓ Paid' });
			}
			return el('p', { class: 'db-track-pay-hint', text: order.customer_status === 'cancelled' ? 'Payment: no payment due' : 'Please pay at the counter — ' + money(order.total) });
		}

		function trackingTime(value, timezone) {
			if (!value) { return ''; }
			var date = new Date(value);
			if (isNaN(date.getTime())) { return ''; }
			var options = { hour: 'numeric', minute: '2-digit' };
			if (timezone) { options.timeZone = timezone; }
			try { return new Intl.DateTimeFormat('en-AU', options).format(date); }
			catch (ignore) { delete options.timeZone; return new Intl.DateTimeFormat('en-AU', options).format(date); }
		}

		function renderOrder(order) {
			result.innerHTML = '';
			var service = order.order_type === 'delivery' ? 'Delivery' : (order.order_type === 'dine_in' ? 'Dine in' : 'Pickup');
			var card = el('article', { class: 'db-track-card' }, [
				el('div', { class: 'db-track-card-head' }, [
					el('div', {}, [
						el('span', { class: 'db-track-service', text: service }),
						el('h4', { text: 'Order ' + order.order_number })
					]),
					el('p', { class: 'db-status-badge', text: order.customer_status_label || order.status_label || order.status })
				])
			]);
			if (order.status === 'cancelled') {
				var cancelled = el('p', { class: 'db-track-cancelled', text: 'This order was cancelled' });
				cancelled.setAttribute('role', 'status');
				card.appendChild(cancelled);
				card.appendChild(el('p', { class: 'db-track-note', text: order.payment_status === 'refunded' ? 'Your payment has been refunded.' : 'If you paid online, contact the shop to confirm the refund status.' }));
			} else {
				card.appendChild(buildTracker(order));
				var from = trackingTime(order.promised_ready_from_utc, order.timezone);
				var by = trackingTime(order.promised_ready_by_utc, order.timezone);
				var showEstimate = ['confirmed', 'preparing', 'baking'].indexOf(order.status) !== -1;
				// Timed orders (2.51.0) show the pickup time the customer chose; legacy
				// orders keep the staff estimate and countdown exactly as before.
				var pickupLine = pickupTrackingText(order);
				if (pickupLine) {
					card.appendChild(el('p', { class: 'db-track-timing db-track-timing--pickup', text: pickupLine }));
					var delayedBy = Math.round(Number(order.promised_delay_minutes));
					if (delayedBy > 0) {
						card.appendChild(el('p', { class: 'db-track-delay', role: 'status', text: 'Updated: +' + delayedBy + ' min' }));
					}
				} else if (showEstimate && from) {
					card.appendChild(el('p', { class: 'db-track-timing', text: 'Staff ready estimate: ' + (by && by !== from ? from + '–' + by : from) }));
				}
				var eta = pickupLine && order.status !== 'ready' ? null : buildEta(order);
				if (eta) { card.appendChild(eta); }
				if (order.customer_status === 'ready_for_pickup') {
					card.appendChild(el('p', { class: 'db-track-collection', text: 'Your order is ready — please collect it from the shop.' }));
				} else if (order.customer_status === 'ready_to_serve') {
					card.appendChild(el('p', { class: 'db-track-collection', text: 'Your order is ready. We will bring it to your table.' }));
				} else if (order.customer_status === 'ready_for_delivery') {
					card.appendChild(el('p', { class: 'db-track-collection', text: 'Your order is ready for delivery.' }));
				}
			}
			var items = el('ul', { class: 'db-item-list' });
			(order.items || []).forEach(function (it) {
				items.appendChild(el('li', { text: it.quantity + '× ' + it.name }));
			});
			card.appendChild(items);
			card.appendChild(el('p', { text: 'Total: ' + money(order.total) }));
			card.appendChild(buildPaymentHint(order));
			card.appendChild(el('p', {
				class: 'db-track-checked',
				text: 'Live status checked ' + new Intl.DateTimeFormat('en-AU', { hour: 'numeric', minute: '2-digit', second: '2-digit' }).format(new Date()) + ' · updates automatically'
			}));
			result.appendChild(card);

			// Terminal states: stop polling for good.
			if (order.status === 'completed' || order.status === 'cancelled') {
				pollDone = true;
				stopPolling();
			}
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			// A new lookup invalidates any in-flight poll cycle.
			stopPolling();
			pollKey = null;
			pollLookup = null;
			pollDone = false;
			result.innerHTML = '';
			var number = form.number.value.trim();
			var email = form.email.value.trim();
			var lookup = { number: number, email: email };
			var key = number + '\n' + email.toLowerCase();
			form.setAttribute('aria-busy', 'true');
			if (lookupButton) {
				lookupButton.disabled = true;
				lookupButton.textContent = 'Checking live status…';
			}

			request('/order/track', { method: 'POST', body: lookup })
				.then(function (order) {
					pollKey = key;
					pollLookup = lookup;
					pollStarted = Date.now();
					renderOrder(order);
					scheduleTick();
				})
				.catch(function (err) {
					result.innerHTML = '';
					result.appendChild(el('p', { class: 'db-error', role: 'alert', text: err.message }));
				})
				.then(function () {
					form.setAttribute('aria-busy', 'false');
					if (lookupButton) {
						lookupButton.disabled = false;
						lookupButton.textContent = lookupLabel;
					}
				});
		});
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                               */
	/* ------------------------------------------------------------------ */

	function approvedPreorderCapability() {
		var values = new URLSearchParams(String(window.location.hash || '').replace(/^#/, ''));
		var requestId = Number(values.get('doughboss_request') || 0);
		var token = String(values.get('doughboss_token') || '');
		if (!requestId || !/^[a-f0-9]{64}$/i.test(token)) { return null; }
		return {
			requestId: requestId,
			token: token,
			returning: values.get('doughboss_preorder_return') === '1',
			cancelled: values.get('doughboss_preorder_cancel') === '1',
			sessionId: String(values.get('session_id') || '')
		};
	}

	function approvedPreorderPresentation(summary) {
		summary = summary || {};
		if (summary.gateway === 'square' && summary.can_confirm_payment === true && !summary.complete) {
			return { state: 'held', canStart: false, title: 'Confirming your payment', body: 'Do not pay again. Check the payment already linked to your approved request, or call DoughBoss for help.' };
		}
		var allowed = ['ready', 'held', 'expired', 'payment_disabled', 'complete', 'unavailable'];
		var state = String(summary.presentation_state || '');
		if (allowed.indexOf(state) === -1) {
			state = summary.complete
				? 'complete'
				: (summary.state === 'payment_held' ? 'held' : (summary.payable ? 'ready' : 'unavailable'));
		}
		var copy = {
			ready: {
				title: 'Approved and ready for secure payment',
				body: 'Your request is still unpaid and has not reached the kitchen. Server-verified payment releases it automatically.'
			},
			held: {
				title: 'Payment needs staff review',
				body: 'Do not pay again. DoughBoss needs to check the existing payment attempt before anything reaches the kitchen.'
			},
			expired: {
				title: 'This approval has expired',
				body: 'No new payment can be started from this link. Contact DoughBoss if you still want to place the order.'
			},
			payment_disabled: {
				title: 'Online payment is paused',
				body: 'Your request remains unpaid and outside the kitchen. Contact DoughBoss for help.'
			},
			complete: {
				title: 'Payment verified',
				body: 'Your paid order has been released to DoughBoss.'
			},
			unavailable: {
				title: 'Payment is not available',
				body: 'This request cannot start a payment. Contact DoughBoss and do not submit another payment.'
			}
		};
		return {
			state: state,
			canStart: state === 'ready' && summary.can_start_payment === true,
			title: copy[state].title,
			body: copy[state].body
		};
	}

	function approvedPreorderContact(summary) {
		var phone = String(summary && summary.contact_phone || '').trim();
		var tel = String(summary && summary.contact_tel || '').trim();
		if (!phone || !/^tel:\+?[0-9]{8,15}$/.test(tel)) { return null; }
		return { phone: phone, tel: tel };
	}

	function isApprovedPreorderComplete(summary) {
		return approvedPreorderPresentation(summary).state === 'complete';
	}

	function approvedSquareRecoverable(summary) {
		return !!(summary && summary.gateway === 'square' && summary.can_confirm_payment === true);
	}

	function verifyApprovedPreorder(capability) {
		return request('/preorder-payment/complete', {
			method: 'POST',
			body: {
				request_id: capability.requestId,
				token: capability.token,
				session_id: capability.sessionId
			}
		});
	}

	function renderApprovedPreorder(root, capability) {
		document.body.classList.add('db-approved-preorder-mode');
		document.querySelectorAll('[data-doughboss-shop], [data-doughboss-menu], [data-doughboss-builder]').forEach(function (node) {
			node.hidden = true;
		});
		var cartWrap = root.closest('.dbf-cart-wrap');
		if (cartWrap) { cartWrap.hidden = false; }
		root.innerHTML = '';
		var message = el('div', { class: 'db-checkout-msg', 'aria-live': 'polite', tabindex: '-1' });
		var content = el('div', { class: 'db-order-shell db-approved-preorder' });
		root.appendChild(el('header', { class: 'db-order-header' }, [
			el('p', { class: 'db-order-kicker', text: 'Approved request' }),
			el('h1', { text: 'Review and pay securely' }),
			el('p', { text: 'Payment is available only for the items DoughBoss approved. The kitchen receives the order after the server verifies payment.' })
		]));
		root.appendChild(content);
		root.appendChild(message);

		function showComplete(result) {
			setPaymentMutationLock(false);
			content.innerHTML = '';
			content.appendChild(el('div', { class: 'db-order-confirm', tabindex: '-1' }, [
				el('p', { class: 'db-order-kicker', text: 'Payment verified' }),
				el('h3', { text: 'Your order is in' }),
				el('p', { text: result.message || 'Payment was verified and the order was sent to DoughBoss.' }),
				result.order_number ? el('strong', { text: result.order_number }) : null,
				result.tracking_url ? el('a', { class: 'db-btn', href: result.tracking_url, text: 'Track this order' }) : null
			]));
			message.textContent = '';
			try { window.history.replaceState({}, document.title, window.location.pathname + window.location.search); } catch (ignored) {}
			revealNode(content.firstChild);
		}

		function draw(summary) {
			var presentation = approvedPreorderPresentation(summary);
			if (isApprovedPreorderComplete(summary)) {
				showComplete({
					message: summary.message,
					order_number: summary.fulfilment_number,
					tracking_url: summary.tracking_url
				});
				return;
			}
			content.innerHTML = '';
			var card = el('section', { class: 'db-cart-region' }, [
				el('div', { class: 'db-cart-section-heading' }, [
					el('h3', { text: summary.order_number || 'Approved request' }),
					el('span', { text: summary.location_name || 'DoughBoss' })
				])
			]);
			var list = el('div', { class: 'db-cart-lines' });
			(summary.items || []).forEach(function (item) {
				var details = [];
				if (item.size) { details.push(item.size); }
				if (item.toppings && item.toppings.length) {
					details.push(item.toppings.map(function (t) { return t.label || t.name || String(t); }).join(', '));
				}
				list.appendChild(el('div', { class: 'db-cart-line' }, [
					el('div', { class: 'db-cart-line-info' }, [
						el('strong', { text: String(item.quantity || 1) + ' × ' + item.name }),
						details.length ? el('small', { text: details.join(' · ') }) : null
					]),
					el('span', { class: 'db-price', text: money(item.line_total) })
				]));
			});
			card.appendChild(list);
			card.appendChild(el('div', { class: 'db-total-row db-total-row--grand' }, [
				el('span', { text: 'Approved total' }),
				el('strong', { text: money(summary.total) + ' ' + (summary.currency || 'AUD') })
			]));
			card.appendChild(el('aside', { class: 'db-ordering-status', role: 'note' }, [
				el('strong', { text: presentation.title }),
				el('p', { text: summary.message || presentation.body })
			]));
			content.appendChild(card);

			if (!presentation.canStart && !approvedSquareRecoverable(summary)) {
				var contact = approvedPreorderContact(summary);
				content.appendChild(el('section', { class: 'db-preorder-help', role: 'note' }, [
					el('strong', { text: presentation.title }),
					el('p', { text: presentation.body }),
					contact ? el('a', { class: 'db-preorder-contact', href: contact.tel, text: 'Call DoughBoss on ' + contact.phone }) : null
				]));
				announceError(message, presentation.body);
				return;
			}
			if (summary.gateway === 'square') {
				drawSquarePayment(summary);
				return;
			}
			var pay = el('button', { class: 'db-btn db-btn--lg', type: 'button', text: 'Continue to Stripe · ' + money(summary.total) });
			pay.addEventListener('click', function () {
				pay.disabled = true;
				pay.textContent = 'Preparing secure payment…';
				clearError(message);
				setPaymentMutationLock(true);
				request('/preorder-payment/start', {
					method: 'POST',
					body: { request_id: capability.requestId, token: capability.token }
				}).then(function (session) {
					if (!session || !/^cs_(test|live)_/.test(session.checkout_session || '') || !/^https:\/\/checkout\.stripe\.com\//.test(session.checkout_url || '')) {
						throw new Error('Stripe returned an invalid checkout session. Please call DoughBoss and do not pay again.');
					}
					window.location.assign(session.checkout_url);
				}).catch(function (err) {
					pay.disabled = false;
					pay.textContent = 'Continue to Stripe · ' + money(summary.total);
					setPaymentMutationLock(false);
					announceError(message, err.message);
				});
			});
			content.appendChild(el('section', { class: 'db-checkout-region' }, [
				el('div', { class: 'db-stripe-checkout-notice', role: 'note' }, [
					el('span', { class: 'db-secure-lock', 'aria-hidden': 'true', text: '✓' }),
					el('div', {}, [
						el('strong', { text: 'Secure checkout powered by Stripe' }),
						el('span', { text: 'DoughBoss never receives or stores your card details.' })
					])
				]),
				pay
			]));
			if (capability.cancelled && !capability.returning) {
				message.textContent = 'Payment was cancelled. No kitchen order was created. You can use the same approved link if you still want to pay.';
			}
		}

		function drawSquarePayment(summary) {
			var square = window.DoughBossSquare;
			if (!square || typeof square.enhanceApproved !== 'function' || typeof square.payApproved !== 'function') {
				announceError(message, 'The secure Square payment form is unavailable. Reload your approved link or call DoughBoss.');
				return;
			}
			var recovering = approvedSquareRecoverable(summary);
			var form = el('form', { class: 'db-checkout-region db-approved-square-payment' });
			var pay = el('button', { class: 'db-btn db-btn--lg', type: 'submit', text: recovering ? 'Check existing payment' : 'Pay securely with Square - ' + money(summary.total) });
			form.appendChild(el('p', { class: 'db-pay-secure', role: 'note', text: recovering ? 'Do not pay again. We will check the payment already linked to your approved request.' : 'Square securely handles your card. Your order reaches the kitchen only after the server verifies payment.' }));
			form.appendChild(pay);
			content.appendChild(form);
			try { square.enhanceApproved(form, summary, capability); }
			catch (err) { pay.disabled = true; announceError(message, err.message); return; }
			form.addEventListener('submit', function (event) {
				event.preventDefault();
				if (pay.disabled) { return; }
				pay.disabled = true;
				pay.textContent = 'Checking secure payment...';
				form.setAttribute('aria-busy', 'true');
				clearError(message);
				setPaymentMutationLock(true);
				square.payApproved(form).then(showComplete).catch(function (err) {
					pay.disabled = false;
					form.setAttribute('aria-busy', 'false');
					var pending = square.approvedPending(form);
					pay.textContent = pending ? 'Check existing payment' : 'Pay securely with Square - ' + money(summary.total);
					setPaymentMutationLock(pending);
					announceError(message, pending ? 'Payment is not confirmed yet. Do not pay again. Check the existing payment or call DoughBoss. ' + (err.message || '') : err.message);
				});
			});
		}

		function loadSummary() {
			return request('/preorder-payment/view', {
				method: 'POST',
				body: { request_id: capability.requestId, token: capability.token }
			}).then(function (summary) {
				draw(summary);
				return summary;
			});
		}

		function showVerificationRetry(error) {
			setPaymentMutationLock(false);
			var retry = el('button', { class: 'db-btn db-preorder-retry', type: 'button', text: 'Retry payment confirmation' });
			content.appendChild(el('section', { class: 'db-preorder-help db-preorder-verification', role: 'alert' }, [
				el('strong', { text: 'Your payment result is not confirmed yet' }),
				el('p', { text: 'Do not pay again. Retry the same Stripe confirmation, or contact DoughBoss if it still cannot be verified.' }),
				retry
			]));
			announceError(message, error && error.message ? error.message : 'Payment could not be verified. Do not pay again.');
			retry.addEventListener('click', function () {
				retry.disabled = true;
				retry.setAttribute('aria-busy', 'true');
				retry.textContent = 'Checking the same payment...';
				setPaymentMutationLock(true);
				verifyApprovedPreorder(capability).then(showComplete).catch(function (retryError) {
					retry.disabled = false;
					retry.setAttribute('aria-busy', 'false');
					retry.textContent = 'Retry payment confirmation';
					setPaymentMutationLock(false);
					announceError(message, retryError && retryError.message ? retryError.message : 'Payment still could not be verified. Do not pay again.');
				});
			});
		}

		if (capability.returning && /^cs_(test|live)_/.test(capability.sessionId)) {
			message.textContent = 'Verifying Stripe payment before sending your order to the kitchen…';
			setPaymentMutationLock(true);
			verifyApprovedPreorder(capability).then(showComplete).catch(function (err) {
				loadSummary().then(function (summary) {
					if (!isApprovedPreorderComplete(summary)) { showVerificationRetry(err); }
				}).catch(function () { showVerificationRetry(err); });
			});
			return;
		}
		loadSummary().catch(function (err) { announceError(message, err.message); });
	}

	function boot() {
		var approvedPreorder = APPROVED_PREORDER;
		if (approvedPreorder) {
			document.querySelectorAll('[data-doughboss-cart]').forEach(function (root) {
				renderApprovedPreorder(root, approvedPreorder);
			});
			document.querySelectorAll('[data-doughboss-tracking]').forEach(renderTracking);
			return;
		}
		document.addEventListener('doughboss:shop-changed', function (event) {
			currentPageLocationId = event.detail ? Number(event.detail.id) || 0 : 0;
		});
		// Resolve a scanned-table session before any ordering controls render, so
		// there is no moment where a customer sees a switchable shop or fulfilment
		// option. Non-QR pages simply continue with a null context.
		getTableContext().then(function () {
			document.querySelectorAll('[data-doughboss-shop]').forEach(renderShopPicker);
			document.querySelectorAll('[data-doughboss-menu]').forEach(renderMenu);
			document.querySelectorAll('[data-doughboss-builder]').forEach(renderBuilder);
			document.querySelectorAll('[data-doughboss-cart]').forEach(function (root) {
				renderCart(root);
			});
			document.querySelectorAll('[data-doughboss-tracking]').forEach(renderTracking);
		}).catch(function (err) {
			// A claimed but expired/revoked table session must never silently become
			// a switchable pickup order. Stop ordering and direct the guest to staff.
			document.querySelectorAll('[data-doughboss-shop], [data-doughboss-menu], [data-doughboss-builder], [data-doughboss-cart]').forEach(function (root) {
				if (root.matches && root.matches('[data-doughboss-menu]') && root.querySelector('[data-doughboss-menu-fallback]')) {
					showMenuFallbackError(root, err);
					return;
				}
				root.innerHTML = '';
				root.appendChild(el('div', { class: 'db-error', role: 'alert', text: (err && err.message ? err.message : 'This table session is no longer active.') + ' Please scan the table QR again or ask a staff member for help.' }));
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
}());
