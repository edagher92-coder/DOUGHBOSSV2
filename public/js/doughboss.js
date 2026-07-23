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
	// A table QR opens the ordering page with a server-issued, HttpOnly context
	// cookie. The browser deliberately receives only the safe display context;
	// it never gets a table token or an editable table/store authority.
	var tableContextCache = null;
	var tableContextRequest = null;

	// Which gateway the server enqueued a card library for ('stripe' or 'tyro').
	// Defaults to 'stripe' so older localized data behaves exactly as before.
	var GATEWAY = PAY.gateway || 'stripe';

	// Build a single Stripe instance when Stripe is the active gateway and
	// Stripe.js has loaded. Stays null otherwise, and the checkout falls back
	// to Tyro (below) or to no payment.
	var stripe = (PAY.enabled && PAY.pk && GATEWAY === 'stripe' && typeof window.Stripe === 'function') ? window.Stripe(PAY.pk) : null;

	// Current Tyro Connect Pay browser library. It owns all card fields and 3DS.
	var tyroPay = !!(PAY.enabled && GATEWAY === 'tyro' && typeof window.Tyro === 'function');


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

	// Stable DOM id for a category name, for jump-bar scroll anchors.
	function catId(category) {
		return 'db-cat-' + String(category).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
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
			var tableContext = activeTableContext();
			if (tableContext) { root.appendChild(tableContextBanner(tableContext)); }
			if (!items.length) {
				root.appendChild(el('p', { class: 'db-empty', text: 'No menu items yet.' }));
				return;
			}

			var groups = {};
			items.forEach(function (item) {
				(groups[item.category] = groups[item.category] || []).push(item);
			});

			var categories = Object.keys(groups);

			// Sticky category jump-bar: a pill per category that scrolls to its
			// section. Only worth showing when there's more than one category.
			if (categories.length > 1) {
				var jump = el('nav', { class: 'db-jumpbar', 'aria-label': I18N.menuCategories || 'Menu categories' });
				categories.forEach(function (category) {
					var pill = el('button', { class: 'db-jump', type: 'button', text: category });
					pill.addEventListener('click', function () {
						var target = document.getElementById(catId(category));
						if (target) { target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
					});
					jump.appendChild(pill);
				});
				root.appendChild(jump);
			}

			var stagger = 0;
			categories.forEach(function (category) {
				root.appendChild(el('h3', { class: 'db-category', id: catId(category), text: category }));
				var grid = el('div', { class: 'db-grid' });
				groups[category].forEach(function (item) {
					var card = menuCard(item);
					// Cap the stagger so a long menu never delays the last card by
					// seconds; the entrance still reads as a lively cascade.
					card.style.setProperty('--db-i', String(Math.min(stagger, 12)));
					stagger += 1;
					grid.appendChild(card);
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
						dbPop(action);
						dbToast((item.name ? item.name + ' — ' : '') + (I18N.addedToCart || 'added to cart'), true);
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
			var tableContext = activeTableContext();

			if (!cart.items.length) {
				if (tableContext) { cartRegion.appendChild(tableContextBanner(tableContext)); }
				cartRegion.appendChild(el('p', { class: 'db-empty', text: I18N.emptyCart || 'Your cart is empty.' }));
				// Nothing to check out — drop any previous checkout form so a later
				// non-empty cart starts with a fresh one (and a fresh card mount).
				checkoutRegion.innerHTML = '';
				checkoutEl = null;
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
			var list = el('div', { class: 'db-cart-lines' });
			cart.items.forEach(function (line) {
				list.appendChild(cartLine(line, load));
			});
			cartRegion.appendChild(list);
			if (tableContext) { cartRegion.appendChild(tableContextBanner(tableContext)); }

			// Shop selector — routes the order to the right kitchen board. Only
			// shown when more than one shop exists; otherwise the single shop is
			// remembered silently.
			if (!tableContext && !cfg.single_location_mode && locs.length > 1) {
				setLocation(locationId, true);
				cartRegion.appendChild(el('div', { class: 'db-cart-shop' }, [
					el('span', { class: 'db-cart-shop-label', text: I18N.chooseShop || 'Choose your shop' }),
					shopSelect(locs, locationId, function (id) { locationId = id; setLocation(id); })
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
				body: { order_type: s.orderType, location_id: s.locationId, payment_attempt_key: clientKey }
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

		setTimeout(function () {
			init().catch(function (err) {
				msg.textContent = err.message || (I18N.cardInitError || 'The secure payment form could not be loaded.');
				msg.className = 'db-checkout-msg db-error';
			});
		}, 0);

		return {
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
		var paymentAttemptKey = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (String(Date.now()) + '-' + Math.random());

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

		// Tyro Connect: hosted payment form and automatic 3DS.
		var tyro = null;
		if (tyroPay) {
			tyro = tyroConnectCheckout(form, msg, function () {
				return {
					orderType: orderType,
					locationId: getLocationId ? getLocationId() : storedLocationId()
				};
			}, paymentAttemptKey);
		}

		form.appendChild(submit);
		form.appendChild(msg);
		// One browser attempt keeps one checkout key and, after payment succeeds,
		// one provider reference until the server confirms the order. A lost HTTP
		// response can therefore be retried without charging or ordering twice.
		var checkoutAttemptId = null;
		var checkoutPaymentId = null;

		function fail(err) {
			msg.textContent = err.message || (I18N.genericError || 'Something went wrong.');
			msg.className = 'db-checkout-msg db-error';
			submit.disabled = false;
			submit.textContent = payLabel;
		}

		function placeOrder(payload) {
			if (!checkoutAttemptId) {
				checkoutAttemptId = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (String(Date.now()) + '-' + Math.random());
			}
			return request('/checkout', { method: 'POST', body: payload, headers: { 'Idempotency-Key': checkoutAttemptId } }).then(function (res) {
				checkoutAttemptId = null;
				checkoutPaymentId = null;
				// Capture the parent BEFORE clearing it: clearing via innerHTML
				// detaches `form` from the document, which nulls form.parentNode —
				// re-reading form.parentNode afterwards to append the confirmation
				// would throw against null.
				var parent = form.parentNode;
				parent.innerHTML = '';
				var confirmation = el('div', { class: 'db-confirm' }, [
					el('div', { class: 'db-confirm-check', 'aria-hidden': 'true', text: '✓' }),
					el('h3', { text: 'Order received' }),
					el('p', { html: 'Your order number is <strong>' + res.order_number + '</strong>.' }),
				]);
				if (res.table_label) {
					confirmation.appendChild(el('p', { class: 'db-table-context', text: 'Dine in · ' + (res.location_name ? res.location_name + ' · ' : '') + 'Table ' + res.table_label + ' · We will bring it to you.' }));
				}
				confirmation.appendChild(el('p', { text: 'The shop has not accepted it yet. Keep this order number and your email to check the latest status.' }));
				confirmation.appendChild(el('p', { text: (paying ? 'Paid: ' : 'Total: ') + money(res.total) }));
				parent.appendChild(confirmation);
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
			if (paying) { payload.payment_attempt_key = paymentAttemptKey; }

			if (stripe && card) {
				// 1) create a PaymentIntent for the current cart, 2) confirm the card
				// payment, 3) place the order with the confirmed PaymentIntent id —
				// which the server re-verifies before accepting the order as paid.
				submit.textContent = I18N.payProcessing || 'Processing payment…';
				if (checkoutPaymentId) {
					payload.payment_intent_id = checkoutPaymentId;
					placeOrder(payload).catch(fail);
					return;
				}
				request('/payment-intent', { method: 'POST', body: { order_type: orderType, location_id: payload.location_id, payment_attempt_key: paymentAttemptKey } }).then(function (pi) {
					return stripe.confirmCardPayment(pi.client_secret, {
						payment_method: {
							card: card,
							billing_details: { name: payload.customer_name, email: payload.customer_email }
						}
					}).then(function (result) {
						if (result.error) {
							throw new Error(result.error.message || (I18N.cardError || 'Card error'));
						}
						checkoutPaymentId = result.paymentIntent.id;
						payload.payment_intent_id = checkoutPaymentId;
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

		var POLL_MS = 15000;
		var POLL_MAX_MS = 2 * 60 * 60 * 1000; // give up after 2 hours
		var pollTimer = null;
		var pollPath = null;   // current lookup; also stale-response guard
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
			if (pollDone || !pollPath || document.hidden) { return; }
			if (Date.now() - pollStarted >= POLL_MAX_MS) {
				pollDone = true;
				return;
			}
			pollTimer = setTimeout(pollTick, POLL_MS);
		}

		function pollTick() {
			pollTimer = null;
			if (pollDone || !pollPath) { return; }
			var path = pollPath;
			request(path)
				.then(function (order) {
					if (path !== pollPath) { return; } // a newer lookup took over
					renderOrder(order);
					scheduleTick();
				})
				.catch(function () {
					// Silent: keep the last good render, retry on the next tick.
					if (path !== pollPath) { return; }
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
			var card = el('div', { class: 'db-track-card' }, [
				el('h4', { text: 'Order ' + order.order_number }),
				el('p', { class: 'db-status-badge', text: order.customer_status_label || order.status_label || order.status })
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
				if (showEstimate && from) {
					card.appendChild(el('p', { class: 'db-track-timing', text: 'Staff ready estimate: ' + (by && by !== from ? from + '–' + by : from) }));
				}
				var eta = buildEta(order);
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
			pollPath = null;
			pollDone = false;
			result.innerHTML = '';
			var number = form.number.value.trim();
			var email = form.email.value.trim();
			var path = '/order/' + encodeURIComponent(number) + '?email=' + encodeURIComponent(email);

			request(path)
				.then(function (order) {
					pollPath = path;
					pollStarted = Date.now();
					renderOrder(order);
					scheduleTick();
				})
				.catch(function (err) {
					result.innerHTML = '';
					result.appendChild(el('p', { class: 'db-error', text: err.message }));
				});
		});
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                               */
	/* ------------------------------------------------------------------ */

	function boot() {
		// Resolve a scanned-table session before any ordering controls render, so
		// there is no moment where a customer sees a switchable shop or fulfilment
		// option. Non-QR pages simply continue with a null context.
		getTableContext().then(function () {
			document.querySelectorAll('[data-doughboss-shop]').forEach(renderShopPicker);
			document.querySelectorAll('[data-doughboss-menu]').forEach(renderMenu);
			document.querySelectorAll('[data-doughboss-builder]').forEach(renderBuilder);
			document.querySelectorAll('[data-doughboss-cart]').forEach(renderCart);
			document.querySelectorAll('[data-doughboss-tracking]').forEach(renderTracking);
		}).catch(function (err) {
			// A claimed but expired/revoked table session must never silently become
			// a switchable pickup order. Stop ordering and direct the guest to staff.
			document.querySelectorAll('[data-doughboss-shop], [data-doughboss-menu], [data-doughboss-builder], [data-doughboss-cart]').forEach(function (root) {
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
