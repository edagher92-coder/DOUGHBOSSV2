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
	var configCache = null;

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
			} else if (key.indexOf('data-') === 0) {
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

	function request(path, options) {
		options = options || {};
		var headers = { 'Content-Type': 'application/json' };
		if (options.method && options.method !== 'GET') {
			headers['X-WP-Nonce'] = DATA.nonce;
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
			configCache = cfg;
			return cfg;
		});
	}

	function notifyCartChanged() {
		document.dispatchEvent(new CustomEvent('doughboss:cart-updated'));
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
		var media = item.image
			? el('div', { class: 'db-card-img', style: 'background-image:url(' + item.image + ')' })
			: el('div', { class: 'db-card-img db-card-img--placeholder' });

		var btn = el('button', { class: 'db-btn', text: I18N.addToCart || 'Add to cart' });
		btn.addEventListener('click', function () {
			btn.disabled = true;
			request('/cart/add', { method: 'POST', body: { type: 'menu', item_id: item.id, quantity: 1 } })
				.then(function () {
					btn.textContent = I18N.added || 'Added!';
					notifyCartChanged();
					setTimeout(function () { btn.textContent = I18N.addToCart || 'Add to cart'; btn.disabled = false; }, 1200);
				})
				.catch(function (err) { alert(err.message); btn.disabled = false; });
		});

		return el('div', { class: 'db-card' }, [
			media,
			el('div', { class: 'db-card-body' }, [
				el('h4', { text: item.name }),
				item.description ? el('p', { class: 'db-card-desc', text: item.description }) : null,
				el('div', { class: 'db-card-foot' }, [
					el('span', { class: 'db-price', text: money(item.price) }),
					btn
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
				}).catch(function (err) { alert(err.message); addBtn.disabled = false; });
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

		function load() {
			Promise.all([getConfig(), request('/cart?order_type=' + orderType)]).then(function (results) {
				draw(results[0], results[1]);
			}).catch(function (err) {
				root.innerHTML = '';
				root.appendChild(el('p', { class: 'db-error', text: err.message }));
			});
		}

		function draw(cfg, cart) {
			root.innerHTML = '';

			if (!cart.items.length) {
				root.appendChild(el('p', { class: 'db-empty', text: I18N.emptyCart || 'Your cart is empty.' }));
				return;
			}

			// Line items.
			var list = el('div', { class: 'db-cart-lines' });
			cart.items.forEach(function (line) {
				list.appendChild(cartLine(line, load));
			});
			root.appendChild(list);

			// Fulfilment selector.
			var typeWrap = el('div', { class: 'db-fulfilment' });
			if (cfg.enable_pickup) { typeWrap.appendChild(typeRadio('pickup', 'Pickup', orderType, onType)); }
			if (cfg.enable_delivery) { typeWrap.appendChild(typeRadio('delivery', 'Delivery', orderType, onType)); }
			root.appendChild(typeWrap);

			// Totals.
			root.appendChild(totalsBlock(cart.totals, cfg));

			// Checkout.
			root.appendChild(checkoutForm(cfg, orderType));
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

		var qty = el('input', { type: 'number', min: '0', value: line.quantity, class: 'db-qty' });
		qty.addEventListener('change', function () {
			request('/cart/update', { method: 'POST', body: { key: line.key, quantity: Number(qty.value) } })
				.then(function () { notifyCartChanged(); }).catch(function (err) { alert(err.message); });
		});

		var remove = el('button', { class: 'db-link', text: I18N.remove || 'Remove' });
		remove.addEventListener('click', function () {
			request('/cart/remove', { method: 'POST', body: { key: line.key } })
				.then(function () { notifyCartChanged(); }).catch(function (err) { alert(err.message); });
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
		block.appendChild(el('div', { class: 'db-total-row db-total-row--grand' }, [
			el('span', { text: I18N.total || 'Total' }), el('span', { text: money(totals.total) })
		]));
		if (inclusive && totals.tax > 0) {
			block.appendChild(el('div', { class: 'db-total-note', text: '(' + (I18N.inclGst || 'includes GST') + ' ' + money(totals.tax) + ')' }));
		}
		return block;
	}

	function checkoutForm(cfg, orderType) {
		var form = el('form', { class: 'db-checkout' });
		var msg = el('div', { class: 'db-checkout-msg', 'aria-live': 'polite' });

		var name = field('text', 'customer_name', 'Name', true);
		var email = field('email', 'customer_email', 'Email', true);
		var phone = field('tel', 'customer_phone', 'Phone', true);
		var address = field('textarea', 'address', 'Delivery address', orderType === 'delivery');
		var notes = field('textarea', 'notes', 'Notes (optional)', false);

		address.style.display = orderType === 'delivery' ? '' : 'none';

		var submit = el('button', { class: 'db-btn db-btn--lg', type: 'submit', text: I18N.placeOrder || 'Place order' });

		form.appendChild(el('h3', { text: 'Checkout' }));
		[name, email, phone, address, notes].forEach(function (f) { form.appendChild(f); });
		form.appendChild(submit);
		form.appendChild(msg);

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			submit.disabled = true;
			submit.textContent = I18N.placing || 'Placing order…';
			msg.textContent = '';
			msg.className = 'db-checkout-msg';

			var payload = {
				order_type: orderType,
				customer_name: name.querySelector('input,textarea').value,
				customer_email: email.querySelector('input,textarea').value,
				customer_phone: phone.querySelector('input,textarea').value,
				address: address.querySelector('input,textarea').value,
				notes: notes.querySelector('input,textarea').value
			};

			request('/checkout', { method: 'POST', body: payload }).then(function (res) {
				form.parentNode.innerHTML = '';
				form.parentNode.appendChild(el('div', { class: 'db-confirm' }, [
					el('h3', { text: '🍕 ' + res.message }),
					el('p', { html: 'Your order number is <strong>' + res.order_number + '</strong>.' }),
					el('p', { text: 'Total charged: ' + money(res.total) })
				]));
				notifyCartChanged();
			}).catch(function (err) {
				msg.textContent = err.message;
				msg.className = 'db-checkout-msg db-error';
				submit.disabled = false;
				submit.textContent = I18N.placeOrder || 'Place order';
			});
		});

		return form;
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
