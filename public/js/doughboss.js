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
	var configCache = null;
	var locationsCache = null;

	// Which gateway the server enqueued a card library for ('stripe' or 'tyro').
	// Defaults to 'stripe' so older localized data behaves exactly as before.
	var GATEWAY = PAY.gateway || 'stripe';

	// Build a single Stripe instance when Stripe is the active gateway and
	// Stripe.js has loaded. Stays null otherwise, and the checkout falls back
	// to Tyro (below) or to no payment.
	var stripe = (PAY.enabled && PAY.pk && GATEWAY === 'stripe' && typeof window.Stripe === 'function') ? window.Stripe(PAY.pk) : null;

	// Tyro (MPGS) hosted-session payments: true when Tyro is the active gateway
	// and its Session.js library loaded. The library is a page-global singleton
	// (window.PaymentSession), not an instance like Stripe's.
	var tyroPay = !!(PAY.enabled && GATEWAY === 'tyro' && window.PaymentSession && typeof window.PaymentSession.configure === 'function');

	// PaymentSession.configure() may only run once per page load — this guard
	// keeps a second checkout widget on the same page from breaking the first.
	var tyroConfigured = false;

	/* ------------------------------------------------------------------ */
	/* Helpers                                                            */
	/* ------------------------------------------------------------------ */

	function money(amount) {
		return DATA.currency + Number(amount || 0).toFixed(2);
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

	// Non-blocking, screen-reader-announced error toast (replaces alert()).
	function dbToast(message) {
		var t = el('div', { class: 'db-toast', text: String(message || (I18N.genericError || 'Something went wrong.')) });
		t.setAttribute('role', 'alert');
		document.body.appendChild(t);
		setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, 4200);
	}

	function request(path, options) {
		options = options || {};
		var headers = { 'Content-Type': 'application/json' };
		if (options.method && options.method !== 'GET') {
			headers['X-WP-Nonce'] = DATA.nonce;
		}
		if (options.headers) {
			Object.keys(options.headers).forEach(function (k) { headers[k] = options.headers[k]; });
		}
		return fetch(DATA.restUrl + path, {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: headers,
			body: options.body ? JSON.stringify(options.body) : undefined
		}).then(function (res) {
			return res.json().then(function (json) {
				if (!res.ok) {
					throw new Error((json && json.message) || I18N.genericError);
				}
				return json;
			});
		});
	}

	function getConfig() {
		if (configCache) {
			return Promise.resolve(configCache);
		}
		return request('/config').then(function (cfg) {
			// Single-location mode: the storefront behaves as one pickup-only
			// shop regardless of how many locations or fulfilment types are
			// configured. Display-only — the checkout REST endpoint's own
			// enable_delivery gate rejects delivery orders server-side.
			if (cfg && cfg.single_location_mode) {
				cfg.enable_delivery = false;
			}
			configCache = cfg;
			return cfg;
		});
	}

	function notifyCartChanged() {
		document.dispatchEvent(new CustomEvent('doughboss:cart-updated'));
	}

	/* ------------------------------------------------------------------ */
	/* Shops (multi-location)                                             */
	/* ------------------------------------------------------------------ */

	function getLocations() {
		if (locationsCache) {
			return Promise.resolve(locationsCache);
		}
		return request('/locations').then(function (locs) {
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
		});
	}

	function locById(locs, id) {
		for (var i = 0; i < locs.length; i++) {
			if (Number(locs[i].id) === Number(id)) { return locs[i]; }
		}
		return null;
	}

	function storedLocationId() {
		try {
			return Number(window.localStorage.getItem('doughboss_location')) || 0;
		} catch (e) {
			return 0;
		}
	}

	function setLocation(id, silent) {
		try {
			window.localStorage.setItem('doughboss_location', String(id));
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

	function shopContact(loc) {
		var info = el('div', { class: 'db-shop-info' });
		if (loc && loc.address) { info.appendChild(el('div', { class: 'db-shop-addr', text: loc.address })); }
		if (loc && loc.phone) { info.appendChild(el('div', { class: 'db-shop-phone', text: loc.phone })); }
		return info;
	}

	function renderShopPicker(root) {
		getLocations().then(function (locs) {
			root.innerHTML = '';
			if (!locs.length) { root.style.display = 'none'; return; }

			// Single shop: remember it silently and just show its details.
			if (locs.length === 1) {
				setLocation(locs[0].id, true);
				root.appendChild(el('div', { class: 'db-shop' }, [
					el('strong', { class: 'db-shop-name', text: locs[0].name }),
					shopContact(locs[0])
				]));
				return;
			}

			var current = currentLocationId(locs);
			setLocation(current, true);
			var infoWrap = el('div', { class: 'db-shop-info-wrap' }, [shopContact(locById(locs, current))]);

			var select = shopSelect(locs, current, function (id) {
				setLocation(id);
				infoWrap.innerHTML = '';
				infoWrap.appendChild(shopContact(locById(locs, id)));
			});

			root.appendChild(el('div', { class: 'db-shop' }, [
				el('label', { class: 'db-shop-heading', text: I18N.chooseShop || 'Choose your shop' }),
				select,
				infoWrap
			]));
		}).catch(function () { root.style.display = 'none'; });
	}

	/* ------------------------------------------------------------------ */
	/* Menu                                                               */
	/* ------------------------------------------------------------------ */

	function renderMenu(root) {
		request('/menu').then(function (items) {
			root.innerHTML = '';
			if (!items.length) {
				root.appendChild(el('p', { class: 'db-empty', text: 'No menu items yet.' }));
				return;
			}

			var groups = {};
			items.forEach(function (item) {
				(groups[item.category] = groups[item.category] || []).push(item);
			});

			Object.keys(groups).forEach(function (category) {
				root.appendChild(el('h3', { class: 'db-category', text: category }));
				var grid = el('div', { class: 'db-grid' });
				groups[category].forEach(function (item) {
					grid.appendChild(menuCard(item));
				});
				root.appendChild(grid);
			});
		}).catch(function (err) {
			root.innerHTML = '';
			root.appendChild(el('p', { class: 'db-error', text: err.message }));
		});
	}

	function menuCard(item) {
		var soldOut = item.available === false;

		var media = item.image
			? el('div', { class: 'db-card-img', style: 'background-image:url(' + item.image + ')' })
			: el('div', { class: 'db-card-img db-card-img--placeholder' });
		if (soldOut) {
			media.appendChild(el('span', { class: 'db-soldout-badge', text: I18N.soldOut || 'Sold out' }));
		}

		var action;
		if (soldOut) {
			action = el('button', { class: 'db-btn', text: I18N.soldOut || 'Sold out', disabled: true });
		} else {
			action = el('button', { class: 'db-btn', text: I18N.addToCart || 'Add to cart' });
			action.addEventListener('click', function () {
				action.disabled = true;
				request('/cart/add', { method: 'POST', body: { type: 'menu', item_id: item.id, quantity: 1 } })
					.then(function () {
						action.textContent = I18N.added || 'Added!';
						notifyCartChanged();
						setTimeout(function () { action.textContent = I18N.addToCart || 'Add to cart'; action.disabled = false; }, 1200);
					})
					.catch(function (err) { dbToast(err.message); action.disabled = false; });
			});
		}

		return el('div', { class: soldOut ? 'db-card db-card--soldout' : 'db-card' }, [
			media,
			el('div', { class: 'db-card-body' }, [
				el('h4', { text: item.name }),
				item.description ? el('p', { class: 'db-card-desc', text: item.description }) : null,
				el('div', { class: 'db-card-foot' }, [
					el('span', { class: 'db-price', text: money(item.price) }),
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
					notifyCartChanged();
					setTimeout(function () { addBtn.textContent = I18N.addToCart || 'Add to cart'; addBtn.disabled = false; }, 1200);
				}).catch(function (err) { dbToast(err.message); addBtn.disabled = false; });
			});

			root.appendChild(el('div', { class: 'db-builder-inner' }, [
				el('h3', { text: 'Build your pizza' }),
				el('h4', { text: 'Size' }),
				sizeWrap,
				cfg.toppings.length ? el('h4', { text: 'Toppings' }) : null,
				cfg.toppings.length ? topWrap : null,
				el('div', { class: 'db-builder-foot' }, [priceEl, addBtn])
			]));

			refreshPrice();
		}).catch(function (err) {
			root.innerHTML = '';
			root.appendChild(el('p', { class: 'db-error', text: err.message }));
		});
	}

	/* ------------------------------------------------------------------ */
	/* Cart & checkout                                                    */
	/* ------------------------------------------------------------------ */

	function renderCart(root) {
		var orderType = 'pickup';
		var locationId = 0;
		// The cart lines/totals region is rebuilt freely on every reload. The
		// checkout region is NOT — once a checkout form exists it is updated in
		// place (see checkoutEl.update below) rather than torn down. Rebuilding it
		// on every cart mutation (e.g. bumping a line's quantity) used to wipe
		// whatever the customer had already typed, and — worse — remount the
		// Stripe card Element's iframe, silently clearing a card number they'd
		// already entered mid-checkout.
		var cartRegion = el('div', { class: 'db-cart-region' });
		var checkoutRegion = el('div', { class: 'db-checkout-region' });
		var checkoutEl = null;
		// Once an order is successfully placed, this cart widget's job is done —
		// further reloads (triggered by the notifyCartChanged() that placeOrder
		// itself fires, telling the rest of the page the cart is now empty) must
		// not overwrite the confirmation message with an "empty cart" render.
		var orderComplete = false;
		root.appendChild(cartRegion);
		root.appendChild(checkoutRegion);

		function load() {
			if (orderComplete) { return; }
			Promise.all([getConfig(), request('/cart?order_type=' + orderType), getLocations()]).then(function (results) {
				draw(results[0], results[1], results[2]);
			}).catch(function (err) {
				cartRegion.innerHTML = '';
				checkoutRegion.innerHTML = '';
				checkoutEl = null;
				cartRegion.appendChild(el('p', { class: 'db-error', text: err.message }));
			});
		}

		function draw(cfg, cart, locs) {
			if (orderComplete) { return; }
			cartRegion.innerHTML = '';

			if (!cart.items.length) {
				cartRegion.appendChild(el('p', { class: 'db-empty', text: I18N.emptyCart || 'Your cart is empty.' }));
				// Nothing to check out — drop any previous checkout form so a later
				// non-empty cart starts with a fresh one (and a fresh card mount).
				checkoutRegion.innerHTML = '';
				checkoutEl = null;
				return;
			}

			if (cfg.single_location_mode && cfg.single_location_id) {
				locationId = Number(cfg.single_location_id);
				orderType = 'pickup';
			} else if (!locationId) { locationId = currentLocationId(locs); }

			// Line items.
			var list = el('div', { class: 'db-cart-lines' });
			cart.items.forEach(function (line) {
				list.appendChild(cartLine(line, load));
			});
			cartRegion.appendChild(list);

			// Shop selector — routes the order to the right kitchen board. Only
			// shown when more than one shop exists; otherwise the single shop is
			// remembered silently.
			if (!cfg.single_location_mode && locs.length > 1) {
				setLocation(locationId, true);
				cartRegion.appendChild(el('div', { class: 'db-cart-shop' }, [
					el('span', { class: 'db-cart-shop-label', text: I18N.chooseShop || 'Choose your shop' }),
					shopSelect(locs, locationId, function (id) { locationId = id; setLocation(id); })
				]));
			} else if (locs.length === 1) {
				locationId = Number(locs[0].id);
				setLocation(locationId, true);
			}

			// Fulfilment selector.
			var typeWrap = el('div', { class: 'db-fulfilment' });
			if (cfg.enable_pickup) { typeWrap.appendChild(typeRadio('pickup', 'Pickup', orderType, onType)); }
			if (cfg.enable_delivery) { typeWrap.appendChild(typeRadio('delivery', 'Delivery', orderType, onType)); }
			cartRegion.appendChild(typeWrap);

			// Totals.
			cartRegion.appendChild(totalsBlock(cart.totals, cfg));

			// Voucher code (apply/remove — preview only; redeemed at checkout).
			cartRegion.appendChild(voucherBox(cart.totals, orderType, load));

			// Checkout — create once, then only update in place.
			if (!checkoutEl) {
				checkoutEl = checkoutForm(cfg, orderType, function () { return locationId; }, cart.totals, function () {
					orderComplete = true;
					// The checkout form's own parent gets replaced with the
					// confirmation message (see placeOrder() above), but that
					// leaves this region's last-rendered cart items/subtotal/
					// voucher box on screen untouched — clear it too so the
					// confirmation isn't shown underneath a stale cart.
					cartRegion.innerHTML = '';
				});
				checkoutRegion.appendChild(checkoutEl.form);
			} else {
				checkoutEl.update(orderType, cart.totals);
			}
		}

		function onType(value) {
			orderType = value;
			load();
		}

		load();
		document.addEventListener('doughboss:cart-updated', load);
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

		var qty = el('input', { type: 'number', min: '0', value: line.quantity, class: 'db-qty', 'aria-label': 'Quantity for ' + line.name });
		qty.addEventListener('change', function () {
			request('/cart/update', { method: 'POST', body: { key: line.key, quantity: Number(qty.value) } })
				.then(function () { notifyCartChanged(); }).catch(function (err) { dbToast(err.message); });
		});

		var remove = el('button', { class: 'db-link', text: I18N.remove || 'Remove' });
		remove.addEventListener('click', function () {
			request('/cart/remove', { method: 'POST', body: { key: line.key } })
				.then(function () { notifyCartChanged(); }).catch(function (err) { dbToast(err.message); });
		});

		return el('div', { class: 'db-cart-line' }, [
			el('div', { class: 'db-cart-line-info' }, [
				el('strong', { text: line.name }),
				sub.length ? el('small', { text: sub.join(' · ') }) : null
			]),
			qty,
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
					.catch(function (err) { remove.disabled = false; msg.textContent = err.message; });
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
				msg.textContent = '';
				request('/cart/apply-voucher', { method: 'POST', body: { code: code, order_type: orderType } })
					.then(function () { reload(); })
					.catch(function (err) { apply.disabled = false; msg.textContent = err.message; });
			}
			apply.addEventListener('click', doApply);
			input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doApply(); } });
			wrap.appendChild(el('div', { class: 'db-voucher-row' }, [input, apply]));
		}

		wrap.appendChild(msg);
		return wrap;
	}

	// Tyro (MPGS) hosted-session card capture for the checkout form.
	//
	// Card-data isolation — the same guarantee as Stripe Elements, different
	// mechanics: the four inputs built here start readOnly and are handed over
	// to Tyro's Session.js, which replaces them with gateway-controlled proxied
	// fields the moment configure() succeeds. What the customer types goes
	// straight from the browser to Tyro's gateway host and lands in a Hosted
	// Session there; this script (and every DoughBoss REST call) only ever sees
	// the opaque session id — never a card number, expiry or security code.
	//
	// Flow (mirrors the Stripe branch's create → confirm → checkout shape):
	//   mount:  POST /payment-intent  → PaymentSession.configure(session id)
	//           so the hosted fields are live before the customer types.
	//   submit: POST /payment-intent again for a FRESH order reference — the
	//           amount and pickup/delivery/shop details the server stashes for
	//           the PAY call must reflect the cart as it is NOW, not as it was
	//           when the form mounted (the hosted session itself only carries
	//           card details, so it is safely reused) — then
	//           PaymentSession.updateSessionFromForm('card') to push the hosted
	//           fields into the session, then POST /checkout with the composite
	//           "{order_id}.{session_id}" reference. The server submits and
	//           verifies the actual charge before trusting the order as paid.
	function tyroCheckout(form, msg, getState) {
		var uid = 'db-tyro-' + String(Date.now()) + String(Math.floor(Math.random() * 1000));
		var ids = {
			number: uid + '-number',
			month: uid + '-month',
			year: uid + '-year',
			csc: uid + '-csc'
		};

		// readOnly until Session.js takes the field over (so nothing typed early
		// can sit in a page-readable input); autocomplete off so the browser
		// never offers to store card data against our own DOM.
		function cardInput(id, ariaLabel, placeholder, extraClass) {
			return el('input', {
				type: 'text',
				id: id,
				class: 'db-card-input' + (extraClass ? ' ' + extraClass : ''),
				readOnly: true,
				autocomplete: 'off',
				placeholder: placeholder,
				'aria-label': ariaLabel
			});
		}

		form.appendChild(el('div', { class: 'db-cardfield db-cardfield--tyro' }, [
			el('span', { class: 'db-field-label', text: I18N.cardDetails || 'Card details' }),
			el('div', { class: 'db-card-fields' }, [
				cardInput(ids.number, I18N.cardNumber || 'Card number', '1234 1234 1234 1234'),
				el('div', { class: 'db-card-row' }, [
					cardInput(ids.month, I18N.cardExpiryMonth || 'Expiry month (MM)', 'MM', 'db-card-input--sm'),
					cardInput(ids.year, I18N.cardExpiryYear || 'Expiry year (YY)', 'YY', 'db-card-input--sm'),
					cardInput(ids.csc, I18N.cardCsc || 'Security code (CVC)', 'CVC', 'db-card-input--sm')
				])
			])
		]));

		var state = {
			sessionId: '',   // the Hosted Session the fields are bound to
			initPromise: null,
			pending: null    // in-flight updateSessionFromForm resolver
		};

		function createIntent() {
			var s = getState();
			return request('/payment-intent', { method: 'POST', body: { order_type: s.orderType, location_id: s.locationId } });
		}

		function fieldErrorMessage(errors) {
			errors = errors || {};
			if (errors.cardNumber) { return I18N.cardNumberError || 'Please check the card number.'; }
			if (errors.expiryMonth || errors.expiryYear) { return I18N.cardExpiryError || 'Please check the card expiry date.'; }
			if (errors.securityCode) { return I18N.cardCscError || 'Please check the card security code.'; }
			return I18N.cardError || 'Please check your card details and try again.';
		}

		function configureSession(sessionId) {
			return new Promise(function (resolve, reject) {
				if (tyroConfigured) {
					reject(new Error(I18N.cardInitError || 'The secure card form could not be loaded. Please refresh the page and try again.'));
					return;
				}
				tyroConfigured = true;
				state.sessionId = sessionId;
				// Session.js is expected to call initialized(); if it never does
				// (bad merchant id / wrong host / blocked script), surface that
				// instead of leaving the form silently dead.
				var timer = setTimeout(function () {
					reject(new Error(I18N.cardInitError || 'The secure card form could not be loaded. Please refresh the page and try again.'));
				}, 20000);
				window.PaymentSession.configure({
					session: sessionId,
					fields: {
						card: {
							number: '#' + ids.number,
							expiryMonth: '#' + ids.month,
							expiryYear: '#' + ids.year,
							securityCode: '#' + ids.csc
						}
					},
					frameEmbeddingMitigation: ['javascript'],
					interaction: {
						displayControl: {
							formatCard: 'EMBOSSED',
							invalidFieldCharacters: 'REJECT'
						}
					},
					callbacks: {
						initialized: function (response) {
							clearTimeout(timer);
							if (response && response.status && response.status !== 'ok') {
								reject(new Error(I18N.cardInitError || 'The secure card form could not be loaded. Please refresh the page and try again.'));
							} else {
								resolve(sessionId);
							}
						},
						formSessionUpdate: function (response) {
							var pending = state.pending;
							state.pending = null;
							if (!pending) { return; }
							clearTimeout(pending.timer);
							if (response && response.status === 'ok') {
								pending.resolve((response.session && response.session.id) || state.sessionId);
							} else if (response && response.status === 'fields_in_error') {
								pending.reject(new Error(fieldErrorMessage(response.errors)));
							} else {
								pending.reject(new Error(I18N.cardError || 'Please check your card details and try again.'));
							}
						}
					}
				});
			});
		}

		function init() {
			if (!state.initPromise) {
				state.initPromise = createIntent().then(function (pi) {
					// client_secret carries the bare Hosted Session id.
					return configureSession(pi.client_secret);
				}).catch(function (err) {
					// Allow the next submit to retry a failed initialization
					// (unless configure() itself already ran — that is one-shot).
					if (!tyroConfigured) { state.initPromise = null; }
					throw err;
				});
			}
			return state.initPromise;
		}

		function updateSession() {
			return new Promise(function (resolve, reject) {
				if (state.pending) {
					reject(new Error(I18N.payProcessing || 'Processing payment…'));
					return;
				}
				var pending = { resolve: resolve, reject: reject, timer: 0 };
				pending.timer = setTimeout(function () {
					if (state.pending === pending) {
						state.pending = null;
						reject(new Error(I18N.cardError || 'Please check your card details and try again.'));
					}
				}, 30000);
				state.pending = pending;
				try {
					window.PaymentSession.updateSessionFromForm('card');
				} catch (e) {
					state.pending = null;
					clearTimeout(pending.timer);
					reject(new Error(I18N.cardInitError || 'The secure card form could not be loaded. Please refresh the page and try again.'));
				}
			});
		}

		// Kick the hosted fields off once the form is in the DOM (it is appended
		// synchronously by draw() — same deferral as the Stripe card mount).
		setTimeout(function () {
			init().catch(function (err) {
				msg.textContent = err.message || (I18N.cardInitError || 'The secure card form could not be loaded.');
				msg.className = 'db-checkout-msg db-error';
			});
		}, 0);

		return {
			pay: function () {
				return init().then(function () {
					return createIntent();
				}).then(function (pi) {
					return updateSession().then(function (sessionId) {
						// Fresh order-reference half + the (card-carrying) hosted
						// session half. On MPGS both halves are plugin-generated
						// alnum ids, so splitting on the first '.' is safe — see
						// DoughBoss_Tyro::ID_DELIM.
						return String(pi.payment_intent).split('.')[0] + '.' + sessionId;
					});
				});
			}
		};
	}

	function checkoutForm(cfg, initialOrderType, getLocationId, initialTotals, onOrderComplete) {
		// orderType/totals are mutable — update() below can revise them (e.g. the
		// customer switches pickup/delivery, or the cart total changes) without
		// recreating this form or its mounted Stripe card Element. The submit
		// handler always reads the current values through these closures, not the
		// frozen constructor arguments.
		var orderType = initialOrderType;
		var totals = initialTotals;

		var form = el('form', { class: 'db-checkout' });
		var msg = el('div', { class: 'db-checkout-msg', 'aria-live': 'polite' });

		var name = field('text', 'customer_name', 'Name', true);
		var email = field('email', 'customer_email', 'Email', true);
		var phone = field('tel', 'customer_phone', 'Phone', true);
		var address = field('textarea', 'address', 'Delivery address', orderType === 'delivery');
		var notes = field('textarea', 'notes', 'Notes (optional)', false);

		address.style.display = orderType === 'delivery' ? '' : 'none';

		// Whether this checkout takes a card payment (either gateway).
		var paying = !!(stripe || tyroPay);

		// When a gateway is active, mount card fields and label the button "Pay $X".
		function payLabelFor(t) {
			var grandTotal = t && typeof t.total !== 'undefined' ? t.total : 0;
			return paying ? ((I18N.pay || 'Pay') + ' ' + money(grandTotal)) : (I18N.placeOrder || 'Place order');
		}
		var payLabel = payLabelFor(totals);
		var submit = el('button', { class: 'db-btn db-btn--lg', type: 'submit', text: payLabel });

		form.appendChild(el('h3', { text: 'Checkout' }));
		[name, email, phone, address, notes].forEach(function (f) { form.appendChild(f); });

		var card = null;
		if (stripe) {
			var cardMount = el('div', { class: 'db-card-element' });
			form.appendChild(el('div', { class: 'db-cardfield' }, [
				el('span', { class: 'db-field-label', text: I18N.cardDetails || 'Card details' }),
				cardMount
			]));
			var elements = stripe.elements();
			card = elements.create('card', { hidePostalCode: true });
			// Mount once the form is in the DOM (it is appended synchronously by draw()).
			setTimeout(function () { try { card.mount(cardMount); } catch (e) {} }, 0);
		}

		// Tyro (MPGS): hosted-session card fields — see tyroCheckout() above.
		var tyro = null;
		if (tyroPay) {
			tyro = tyroCheckout(form, msg, function () {
				return {
					orderType: orderType,
					locationId: getLocationId ? getLocationId() : storedLocationId()
				};
			});
		}

		form.appendChild(submit);
		form.appendChild(msg);

		function fail(err) {
			msg.textContent = err.message || (I18N.genericError || 'Something went wrong.');
			msg.className = 'db-checkout-msg db-error';
			submit.disabled = false;
			submit.textContent = payLabel;
		}

		function placeOrder(payload) {
			// A fresh idempotency key per attempt: a dropped response can be retried
			// without creating a duplicate order.
			var idem = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (String(Date.now()) + Math.random());
			return request('/checkout', { method: 'POST', body: payload, headers: { 'Idempotency-Key': idem } }).then(function (res) {
				// Capture the parent BEFORE clearing it: clearing via innerHTML
				// detaches `form` from the document, which nulls form.parentNode —
				// re-reading form.parentNode afterwards to append the confirmation
				// would throw against null.
				var parent = form.parentNode;
				parent.innerHTML = '';
				parent.appendChild(el('div', { class: 'db-confirm' }, [
					el('h3', { text: res.message }),
					el('p', { html: 'Your order number is <strong>' + res.order_number + '</strong>.' }),
					el('p', { text: (paying ? 'Paid: ' : 'Total: ') + money(res.total) })
				]));
				// Mark this cart widget done BEFORE notifying — the notification
				// triggers this same widget's own reload listener, which must not
				// overwrite the confirmation just shown with an "empty cart" render.
				if (onOrderComplete) { onOrderComplete(); }
				notifyCartChanged();
			});
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			submit.disabled = true;
			msg.textContent = '';
			msg.className = 'db-checkout-msg';

			var payload = {
				order_type: orderType,
				location_id: getLocationId ? getLocationId() : storedLocationId(),
				customer_name: name.querySelector('input,textarea').value,
				customer_email: email.querySelector('input,textarea').value,
				customer_phone: phone.querySelector('input,textarea').value,
				address: address.querySelector('input,textarea').value,
				notes: notes.querySelector('input,textarea').value
			};

			if (stripe && card) {
				// 1) create a PaymentIntent for the current cart, 2) confirm the card
				// payment, 3) place the order with the confirmed PaymentIntent id —
				// which the server re-verifies before accepting the order as paid.
				submit.textContent = I18N.payProcessing || 'Processing payment…';
				request('/payment-intent', { method: 'POST', body: { order_type: orderType, location_id: payload.location_id } }).then(function (pi) {
					return stripe.confirmCardPayment(pi.client_secret, {
						payment_method: {
							card: card,
							billing_details: { name: payload.customer_name, email: payload.customer_email }
						}
					}).then(function (result) {
						if (result.error) {
							throw new Error(result.error.message || (I18N.cardError || 'Card error'));
						}
						payload.payment_intent_id = result.paymentIntent.id;
						return placeOrder(payload);
					});
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
				tyro.pay().then(function (paymentId) {
					payload.payment_intent_id = paymentId;
					return placeOrder(payload);
				}).catch(fail);
			} else {
				submit.textContent = I18N.placing || 'Placing order…';
				placeOrder(payload).catch(fail);
			}
		});

		// Called by draw() when the cart reloads (quantity change, voucher applied,
		// pickup/delivery switch, ...) instead of recreating this form. Only
		// touches what genuinely needs to reflect the new state — never the
		// customer's typed fields, and never the mounted Stripe card Element.
		function update(newOrderType, newTotals) {
			orderType = newOrderType;
			totals = newTotals;
			address.style.display = orderType === 'delivery' ? '' : 'none';
			var addrInput = address.querySelector('input,textarea');
			if (addrInput) { addrInput.required = orderType === 'delivery'; }
			payLabel = payLabelFor(totals);
			// Don't stomp on "Processing payment…"/"Placing order…" if a submit is
			// already in flight.
			if (!submit.disabled) { submit.textContent = payLabel; }
		}

		return { form: form, update: update };
	}

	function field(type, nameAttr, label, required) {
		var input = type === 'textarea'
			? el('textarea', { name: nameAttr })
			: el('input', { type: type, name: nameAttr });
		if (required) { input.required = true; }
		return el('label', { class: 'db-field' }, [el('span', { text: label }), input]);
	}

	/* ------------------------------------------------------------------ */
	/* Order tracking                                                     */
	/* ------------------------------------------------------------------ */

	function renderTracking(root) {
		var form = root.querySelector('.db-track-form');
		var result = root.querySelector('.db-track-result');
		if (!form) { return; }

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			result.innerHTML = '';
			var number = form.number.value.trim();
			var email = form.email.value.trim();

			request('/order/' + encodeURIComponent(number) + '?email=' + encodeURIComponent(email))
				.then(function (order) {
					var items = el('ul', { class: 'db-item-list' });
					(order.items || []).forEach(function (it) {
						items.appendChild(el('li', { text: it.quantity + '× ' + it.name }));
					});
					result.appendChild(el('div', { class: 'db-track-card' }, [
						el('h4', { text: 'Order ' + order.order_number }),
						el('p', { class: 'db-status-badge', text: order.status_label }),
						items,
						el('p', { text: 'Total: ' + money(order.total) })
					]));
				})
				.catch(function (err) {
					result.appendChild(el('p', { class: 'db-error', text: err.message }));
				});
		});
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                               */
	/* ------------------------------------------------------------------ */

	function boot() {
		document.querySelectorAll('[data-doughboss-shop]').forEach(renderShopPicker);
		document.querySelectorAll('[data-doughboss-menu]').forEach(renderMenu);
		document.querySelectorAll('[data-doughboss-builder]').forEach(renderBuilder);
		document.querySelectorAll('[data-doughboss-cart]').forEach(renderCart);
		document.querySelectorAll('[data-doughboss-tracking]').forEach(renderTracking);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
}());
