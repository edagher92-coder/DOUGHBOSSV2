/**
 * DoughBoss storefront.
 *
 * Hydrates the menu, custom pizza builder, cart/checkout and order-tracking
 * shortcode containers by talking to the doughboss/v1 REST API. No framework,
 * no jQuery â€” just fetch and the DOM.
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

	// Once a Stripe confirmation reaches the provider, the paid cart snapshot
	// must stay immutable until the matching order is stored.
	var paymentMutationLock = false;
	var paymentMutationMessage = 'Your payment is being confirmed. Cart, voucher, shop and fulfilment changes are temporarily locked until the order is saved.';

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
			// configured. Display-only â€” the checkout REST endpoint's own
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
		return request('/locations').then(function (locs) {
			locs = Array.isArray(locs) ? locs : [];
			// Single-location mode pins the storefront to the first active shop:
			// the picker collapses to the single-shop display and orders carry
			// that shop's id. Note this is client-side narrowing only â€” keep the
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
		} catch (e) { /* private mode â€” selection just isn't persisted */ }
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
			var label = loc.suburb ? (loc.name + ' â€” ' + loc.suburb) : loc.name;
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
				if (!paymentMutationAllowed()) {
					select.value = String(current);
					return;
				}
				current = id;
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
		Promise.all([request('/menu'), getConfig()]).then(function (results) {
			var items = results[0];
			var orderingOpen = !!results[1].ordering_open;
			root.innerHTML = '';
			root.setAttribute('data-ordering-open', orderingOpen ? 'true' : 'false');
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
			var preferredCategories = ['Manoush', 'Pizza', 'Pies', 'Wraps', 'Desserts', 'Drinks'];
			categories.sort(function (a, b) {
				var ai = preferredCategories.indexOf(a);
				var bi = preferredCategories.indexOf(b);
				ai = ai < 0 ? preferredCategories.length : ai;
				bi = bi < 0 ? preferredCategories.length : bi;
				return ai - bi || a.localeCompare(b);
			});

			var tools = el('div', { class: 'db-menu-tools' });
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
			tools.appendChild(el('label', { class: 'db-menu-search-wrap' }, [
				el('span', { class: 'db-menu-search-icon', 'aria-hidden': 'true', text: 'âŒ•' }),
				search
			]));
			tools.appendChild(searchStatus);

			// Sticky category jump-bar: a pill per category that scrolls to its
			// section. Only worth showing when there's more than one category.
			if (categories.length > 1) {
				var jump = el('nav', { class: 'db-jumpbar', 'aria-label': I18N.menuCategories || 'Menu categories' });
				categories.forEach(function (category) {
					var targetId = catId(category);
					var pill = el('button', { class: 'db-jump', type: 'button', text: category, 'aria-controls': targetId });
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

			var stagger = 0;
			var sections = [];
			categories.forEach(function (×Î;âÚ$z{-®éÜj×—G'’° —VæF–ærÒ¥4ôâç'6R‡v–æF÷rç6W76–öå7F÷&vRævWD—FVÒ‚vF÷Vv†&÷74×w5VæF–ærr’ÇÂvçVÆÂr“° —Ò6F6‚†–væ÷&UVæF–ær’·Ð —f"&WGW&æVD÷&FW"Ò&WGW&å&×2ævWB‚vF÷Vv†&÷75ö×w5ö÷&FW"r’ÇÂrs° ––b‡VæF–ærbbVæF–æræ÷&FW$–BÓÓÒ&WGW&æVD÷&FW"bbVæF–ærç–ÆöBbb„FFRææ÷r‚’ÒçVÖ&W"‡VæF–ærç6fVDBÇÂ’’Â3¢c¢’° –6†V6¶÷WDGFV×D–BÒVæF–æræ6†V6¶÷WDGFV×D–C° –f÷&Òç6WDGG&–'WFR‚v&–Ö'W7’rÂwG'VRr“° ––b†öä¦÷W&æW•7FW’²öä¦÷W&æW•7FWƒ2“²Ð —7V&Ö—BæF—6&ÆVBÒG'VS° —7V&Ö—BçFW‡D6öçFVçBÒufW&–g––ærÖ7FW&6&B–ÖVçN(
bs° —Æ6T÷&FW"‡VæF–ærç–ÆöB’çF†Vâ†gVæ7F–öâ‚’° —v–æF÷rç6W76–öå7F÷&vRç&VÖ÷fT—FVÒ‚vF÷Vv†&÷74×w5VæF–ærr“° —f"6ÆVåW&ÂÒæWrU$Â‡v–æF÷ræÆö6F–öâæ‡&Vb“° •²vF÷Vv†&÷75ö×w5÷&WGW&ârÂvF÷Vv†&÷75ö×w5ö÷&FW"rÂw&W7VÇD–æF–6F÷"rÂw6W76–öåfW'6–öârÂv6†V6¶÷WEfW'6–öâuÒæf÷$V6‚†gVæ7F–öâ†¶W’’²6ÆVåW&Âç6V&6…&×2æFVÆWFR†¶W’“²Ò“° —v–æF÷ræ†—7F÷'’ç&WÆ6U7FFR‡·ÒÂFö7VÖVçBçF—FÆRÂ6ÆVåW&ÂçFõ7G&–ær‚’“° —Ò’æ6F6‚†f–Â“° —ÒVÇ6R° –f–Â†æWrW'&÷"‚uF†RÖ7FW&6&B–ÖVçB&WGW&â6÷VÆBæ÷B&RÖF6†VBFòF†—26'Bâæò÷&FW"v2Æ6VBâr’“° —Ð —Ð —Ð  ’òò6ÆÆVBv†VâF†R6W'fW"6'B&VÆöG2â&W&VB'WBVæ6öæf—&ÖVB7G&—P ’òò6W76–öâ&VÆöæw2FòF†R&Wf–÷W26'B6æ6†÷BÂ6òF—66&BöæÇ’F†@ ’òò6V7W&RT’æB&W6W'fRF†R7W7FöÖW"w2G—VB6öçF7Bf–VÆG2à –gVæ7F–öâWFFR†æWt÷&FW%G—RÂæWuF÷FÇ2’° –÷&FW%G—RÒæWt÷&FW%G—S° —F÷FÇ2ÒæWuF÷FÇ3° –FG&W72ç7G–ÆRæF—7Æ’Ò÷&FW%G—RÓÓÒvFVÆ—fW'’ròrr¢væöæRs° —f"FG$–çWBÒFG&W72çVW'•6VÆV7F÷"‚v–çWBÇFW‡F&Vr“° ––b†FG$–çWB’²FG$–çWBç&WV—&VBÒ÷&FW%G—RÓÓÒvFVÆ—fW'’s²Ð —”Æ&VÂÒ”Æ&VÄf÷"‡F÷FÇ2“° —7VÖÖ'•G—RçFW‡D6öçFVçBÒ÷&FW%G—RÓÓÒvFVÆ—fW'’ròtFVÆ—fW'’r¢†÷&FW%G—RÓÓÒvF–æUö–âròtF–æR–âr¢u–6·Wr“° —f"—FVÔ6÷VçBÒçVÖ&W"‡F÷FÇ2bb‡F÷FÇ2æ—FVÕö6÷VçBÇÂF÷FÇ2çVçF—G’’ÇÂ“° —7VÖÖ'”6÷VçBçFW‡D6öçFVçBÒ—FVÔ6÷VçBò—FVÔ6÷VçB²†—FVÔ6÷VçBÓÓÒòr—FVÒr¢r—FV×2r’¢u&Wf–Wr–÷W"—FV×2æBF÷FÂs° —7VÖÖ'•F÷FÂçFW‡D6öçFVçBÒÖöæW’‡F÷FÇ2bbF÷FÇ2çF÷FÂ“° ––b‡7G&—Tæ÷F–6UF÷FÂ’²7G&—Tæ÷F–6UF÷FÂçFW‡D6öçFVçBÒÖöæW’‡F÷FÇ2bbF÷FÇ2çF÷FÂ’²rTBs²Ð ’òòFòæ÷B&WÆ6Râ–âÖfÆ–v‡B–ÖVçB÷"÷&FW"6öæf—&ÖF–öâÆ&VÂà ––b‚7V&Ö—BæF—6&ÆVB’²7V&Ö—BçFW‡D6öçFVçBÒ”Æ&VÃ²Ð —Ð  —&WGW&â²f÷&Ó¢f÷&ÒÂWFFS¢WFFRÓ° —Ð  –gVæ7F–öâ×w5&WGW&åW&Â‚’° —f"6ÆVåW&ÂÒæWrU$Â‡v–æF÷ræÆö6F–öâæ‡&Vb“° —f"6fRÒæWrU$Â†6ÆVåW&Âæ÷&–v–â²6ÆVåW&ÂçF†æÖR“° •²wvUö–BrÂwuÒæf÷$V6‚†gVæ7F–öâ†¶W’’° —f"fÇVRÒ6ÆVåW&Âç6V&6…&×2ævWB†¶W’“° ––b‚õå³Ó•Õ³Ó•Ò¢BòçFW7B‡fÇVRÇÂrr’’²6fRç6V&6…&×2ç6WB†¶W’ÂfÇVR“²Ð —Ò“° ––b†6ÆVåW&Âç6V&6…&×2ævWB‚w&Wf–Wrr’ÓÓÒwG'VRr’²6fRç6V&6…&×2ç6WB‚w&Wf–WrrÂwG'VRr“²Ð —&WGW&â6fRçFõ7G&–ær‚“° —Ð  –gVæ7F–öâf–VÆB‡G—RÂæÖTGG"ÂÆ&VÂÂ&WV—&VBÂGG&–'WFW2’° —f"–çWBÒG—RÓÓÒwFW‡F&Vp “òVÂ‚wFW‡F&VrÂ²æÖS¢æÖTGG"Ò “¢VÂ‚v–çWBrÂ²G—S¢G—RÂæÖS¢æÖTGG"Ò“° ”ö&¦V7Bæ¶W—2†GG&–'WFW2ÇÂ·Ò’æf÷$V6‚†gVæ7F–öâ†¶W’’° ––çWBç6WDGG&–'WFR†¶W’ÂGG&–'WFW5¶¶W•Ò“° —Ò“° ––b‡&WV—&VB’²–çWBç&WV—&VBÒG'VS²Ð —&WGW&âVÂ‚vÆ&VÂrÂ²6Æ73¢vF"Öf–VÆBrÒÂ° –VÂ‚w7ârÂ²FW‡C¢Æ&VÂ²‡&WV—&VBòr¢r¢rr’Ò’À ––çW@ •Ò“° —Ð  ’ò¢ÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒ¢ð ’ò¢÷&FW"G&6¶–ær¢ð ’ò¢ÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒ¢ð  ’òò6ö'6R&öw&W727FvW2f÷"F†R7W7FöÖW"G&6¶W"â–æFW‚Ò7FvRƒÓ2“° ’òòVæ¶æ÷vâ7FGW6W2fÆÂ&6²Fò7FvR6òF†RG&6¶W"æWfW"F—6V'2à —f"E$4µõ5DtUôÔÒ° —VæF–æs¢À –6öæf—&ÖVC¢À —&W&–æs¢À –&¶–æs¢À —&VG“¢"À –÷WEöf÷%öFVÆ—fW'“¢"À –6ö×ÆWFVC¢0 —Ó°  ’òò'6RF†R’w2UD2u•••’ÔÔÒÔDB„ƒ¤ÔÓ¥52rF–ÖW7F×2FòWö6‚×2à ’òò&WGW&ç2çVÆÂf÷"ç—F†–ærÖÆf÷&ÖVBö'6VçB6ò6ÆÆW'26â&–Â÷WBà –gVæ7F–öâ'6UWF5F–ÖW7F×‡fÇVR’° ––b‚fÇVRÇÂG—VöbfÇVRÓÒw7G&–ærr’²&WGW&âçVÆÃ²Ð —f"ÒÒfÇVRæÖF6‚‚õâ…ÆG³GÒ’Ò…ÆG³'Ò’Ò…ÆG³'Ò•²EÒ…ÆG³'Ò“¢…ÆG³'Ò“¢…ÆG³'Ò’ò“° ––b‚Ò’²&WGW&âçVÆÃ²Ð —&WGW&âFFRåUD2‚¶Õ³ÒÂ¶Õ³%ÒÒÂ¶Õ³5ÒÂ¶Õ³EÒÂ¶Õ³UÒÂ¶Õ³eÒ“° —Ð  –gVæ7F–öâ&VæFW%G&6¶–ær‡&ö÷B’° —f"f÷&ÒÒ&ö÷BçVW'•6VÆV7F÷"‚ræF"×G&6²Öf÷&Òr“° —f"&W7VÇBÒ&ö÷BçVW'•6VÆV7F÷"‚ræF"×G&6²×&W7VÇBr“° ––b‚f÷&Ò’²&WGW&ã²Ð —f"Æöö·W'WGFöâÒf÷&ÒçVW'•6VÆV7F÷"‚v'WGFöå·G—SÒ'7V&Ö—B%Òr“° —f"Æöö·WÆ&VÂÒÆöö·W'WGFöâòÆöö·W'WGFöâçFW‡D6öçFVçB¢t6†V6²Æ—fR7FGW2s°  ’òòVÖ–ÂÆ–æ·2&Vf–ÆÂöæÇ’F†Ræöâ×6Vç6—F—fR÷&FW"çVÖ&W"âF†R7W7FöÖW  ’òò×W7B7F–ÆÂG—RF†RÖF6†–ær6†V6¶÷WBVÖ–ÂÂ&W6W'f–ærF†RVæGö–çBw0 ’òòçF’ÖVçVÖW&F–öâ&÷VæF'’à —G'’° —f"&Vf–ÆÂÒæWrU$Å6V&6…&×2‡v–æF÷ræÆö6F–öâç6V&6‚’ævWB‚v÷&FW"r“° ––b‡&Vf–ÆÂbb&Vf–ÆÂæÆVæwF‚ÃÒcBbbf÷&ÒæçVÖ&W"çfÇVR’° –f÷&ÒæçVÖ&W"çfÇVRÒ&Vf–ÆÃ° —Ð —Ò6F6‚†W'&÷"’·Ð  —f"ôÄÅôÕ2ÒS° —f"ôÄÅôÔ…ôÕ2Ò"¢c¢c¢²òòv—fRWgFW""†÷W'0 —f"öÆÅF–ÖW"ÒçVÆÃ° —f"öÆÄ¶W’ÒçVÆÃ²òò7W'&VçBÆöö·W²Ç6ò7FÆR×&W7öç6RwV&@ —f"öÆÄÆöö·WÒçVÆÃ²òò&WVW7B&öG’7F—2–âÖVÖ÷'’ÂæWfW"–âU$À —f"öÆÅ7F'FVBÒ° —f"öÆÄFöæRÒfÇ6S²òòW&ÖæVçB7F÷†6ö×ÆWFVBö6æ6VÆÆVBöW‡—&VB  –gVæ7F–öâ7F÷öÆÆ–ær‚’° ––b‡öÆÅF–ÖW"’° –6ÆV%F–ÖV÷WB‡öÆÅF–ÖW"“° —öÆÅF–ÖW"ÒçVÆÃ° —Ð —Ð  –gVæ7F–öâ66†VGVÆUF–6²‚’° —7F÷öÆÆ–ær‚“° ––b‡öÆÄFöæRÇÂöÆÄ¶W’ÇÂöÆÄÆöö·WÇÂFö7VÖVçBæ†–FFVâ’²&WGW&ã²Ð ––b„FFRææ÷r‚’ÒöÆÅ7F'FVBãÒôÄÅôÔ…ôÕ2’° —öÆÄFöæRÒG'VS° —&WGW&ã° —Ð —öÆÅF–ÖW"Ò6WEF–ÖV÷WB‡öÆÅF–6²ÂôÄÅôÕ2“° —Ð  –gVæ7F–öâöÆÅF–6²‚’° —öÆÅF–ÖW"ÒçVÆÃ° ––b‡öÆÄFöæRÇÂöÆÄ¶W’ÇÂöÆÄÆöö·W’²&WGW&ã²Ð —f"¶W’ÒöÆÄ¶W“° —&WVW7B‚rö÷&FW"÷G&6²rÂ²ÖWF†öC¢uõ5BrÂ&öG“¢öÆÄÆöö·WÒ ’çF†Vâ†gVæ7F–öâ†÷&FW"’° ––b†¶W’ÓÒöÆÄ¶W’’²&WGW&ã²ÒòòæWvW"Æöö·WFöö²÷fW  —&VæFW$÷&FW"†÷&FW"“° —66†VGVÆUF–6²‚“° —Ò ’æ6F6‚†gVæ7F–öâ‚’° ’òò6–ÆVçC¢¶VWF†RÆ7BvööB&VæFW"Â&WG'’öâF†RæW‡BF–6²à ––b†¶W’ÓÒöÆÄ¶W’’²&WGW&ã²Ð —66†VGVÆUF–6²‚“° —Ò“° —Ð  ’òòW6RöÆÆ–ærv†–ÆRF†RF"—2†–FFVâÂ&W7VÖRv†Vâf—6–&ÆRv–âà –Fö7VÖVçBæFDWfVçDÆ—7FVæW"‚wf—6–&–Æ—G–6†ævRrÂgVæ7F–öâ‚’° ––b†Fö7VÖVçBæ†–FFVâ’° —7F÷öÆÆ–ær‚“° —ÒVÇ6R° —66†VGVÆUF–6²‚“° —Ð —Ò“°  ’òòB×7FvR†÷&—¦öçFÂ&öw&W72G&6¶W"‡–6·Wg2FVÆ—fW'’v÷&F–ær’à –gVæ7F–öâ'V–ÆEG&6¶W"†÷&FW"’° —f"—4FVÆ—fW'’Ò÷&FW"æ÷&FW%÷G—RÓÓÒvFVÆ—fW'’s° —f"—4F–æT–âÒ÷&FW"æ÷&FW%÷G—RÓÓÒvF–æUö–âs° —f"Æ&VÇ2Ò° ’t÷&FW"Æ6VBrÀ ’t&V–ær&W&VBrÀ –—4FVÆ—fW'’òtöâ—G2v’r¢†—4F–æT–âòu&VG’Fò6W'fRr¢u&VG’f÷"–6·Wr’À –—4FVÆ—fW'’òtFVÆ—fW&VBr¢†—4F–æT–âòu6W'fVBr¢u–6¶VBWr •Ó° —f"7W'&VçBÒE$4µõ5DtUôÔæ†4÷vå&÷W'G’†÷&FW"ç7FGW2’òE$4µõ5DtUôÔ¶÷&FW"ç7FGW5Ò¢° —f"Æ—7BÒVÂ‚vöÂrÂ²6Æ73¢vF"×7FvR×G&6¶W"rÂv&–ÖÆ&VÂs¢t÷&FW"&öw&W72rÒ“° –Æ&VÇ2æf÷$V6‚†gVæ7F–öâ†Æ&VÂÂ’’° ’òò6ö×ÆWFVB÷&FW"—2gVÆÇ’FöæRÂ6†V6²öâWfW'’7FvRà —f"FöæRÒ’Â7W'&VçBÇÂ÷&FW"ç7FGW2ÓÓÒv6ö×ÆWFVBs° —f"6Ç2ÒvF"×7FvRr²†FöæRòrF"×7FvRÒÖFöæRr¢rr’²†’ÓÓÒ7W'&VçBòrF"×7FvRÒÖ7W'&VçBr¢rr“° —f"—FVÒÒVÂ‚vÆ’rÂ²6Æ73¢6Ç2ÒÂ° –VÂ‚w7ârÂ²6Æ73¢vF"×7FvRÖF÷BrÂv&–Ö†–FFVâs¢wG'VRrÂFW‡C¢FöæRò~)É2r¢rrÒ’À –VÂ‚w7ârÂ²6Æ73¢vF"×7FvRÖÆ&VÂrÂFW‡C¢Æ&VÂÒ •Ò“° ––b†’ÓÓÒ7W'&VçB’²—FVÒç6WDGG&–'WFR‚v&–Ö7W'&VçBrÂw7FWr“²Ð –Æ—7BæVæD6†–ÆB†—FVÒ“° —Ò“° —&WGW&âÆ—7C° —Ð  ’òò†öæW7BUDÆ–æRâ66WFVEöB—2æWr6W'fW"f–VÆBF†BÖ’æ÷B&P ’òòFWÆ÷–VB–WB(	Bv†Vâ—Bw2Ö—76–ærF†R6÷VçFF÷vâ—26–×Ç’öÖ—GFVBà –gVæ7F–öâ'V–ÆDWF†÷&FW"’° ––b†÷&FW"ç7FGW2ÓÓÒw&VG’r’° —&WGW&âVÂ‚wrÂ²6Æ73¢vF"×G&6²ÖWFF"×G&6²ÖWFÒ×&VG’rÂFW‡C¢u–÷W"÷&FW"—2&VG’rÒ“° —Ð ––b†÷&FW"ç7FGW2ÓÓÒv6ö×ÆWFVBrÇÂ÷&FW"ç7FGW2ÓÓÒv6æ6VÆÆVBr’²&WGW&âçVÆÃ²Ð —f"66WFVBÒ'6UWF5F–ÖW7F×†÷&FW"æ66WFVEöB“° —f"WFÖ–çWFW2ÒçVÖ&W"†÷&FW"æWFöÖ–çWFW2ÇÂ“° ––b†66WFVBÓÓÒçVÆÂÇÂ†WFÖ–çWFW2â’’²&WGW&âçVÆÃ²Ð —f"&VÖ–æ–ærÒÖF‚æ6V–Â‚†66WFVB²WFÖ–çWFW2¢cÒFFRææ÷r‚’’òc“° —&WGW&âVÂ‚wrÂ° –6Æ73¢vF"×G&6²ÖWFrÀ —FW‡C¢&VÖ–æ–ærâòu&VG’–â&÷WBr²&VÖ–æ–ær²vÒr¢tç’Ö–çWFRæ÷~(
bp —Ò“° —Ð  –gVæ7F–öâ'V–ÆE–ÖVçD†–çB†÷&FW"’° ––b†÷&FW"ç–ÖVçE÷7FGW2ÓÓÒw&VgVæFVBr’° —&WGW&âVÂ‚wrÂ²6Æ73¢vF"×G&6²×–ÖVçBrÂFW‡C¢u–ÖVçC¢&VgVæFVBrÒ“° —Ð ––b†÷&FW"ç–ÖVçE÷7FGW2ÓÓÒw–Br’° —&WGW&âVÂ‚wrÂ²6Æ73¢vF"×G&6²×–BrÂFW‡C¢÷&FW"æ7W7FöÖW%÷7FGW2ÓÓÒv6æ6VÆÆVBròu–ÖVçC¢–B(	B6öçF7BF†R6†÷f÷"F†R&VgVæB7FGW2r¢~)É2–BrÒ“° —Ð —&WGW&âVÂ‚wrÂ²6Æ73¢vF"×G&6²×’Ö†–çBrÂFW‡C¢÷&FW"æ7W7FöÖW%÷7FGW2ÓÓÒv6æ6VÆÆVBròu–ÖVçC¢æò–ÖVçBGVRr¢uÆV6R’BF†R6÷VçFW"(	Br²ÖöæW’†÷&FW"çF÷FÂ’Ò“° —Ð  –gVæ7F–öâG&6¶–æuF–ÖR‡fÇVRÂF–ÖW¦öæR’° ––b‚fÇVR’²&WGW&ârs²Ð —f"FFRÒæWrFFR‡fÇVR“° ––b†—4æâ†FFRævWEF–ÖR‚’’’²&WGW&ârs²Ð —f"÷F–öç2Ò²†÷W#¢vçVÖW&–2rÂÖ–çWFS¢s"ÖF–v—BrÓ° ––b‡F–ÖW¦öæR’²÷F–öç2çF–ÖU¦öæRÒF–ÖW¦öæS²Ð —G'’²&WGW&âæWr–çFÂäFFUF–ÖTf÷&ÖB‚vVâÔRrÂ÷F–öç2’æf÷&ÖB†FFR“²Ð –6F6‚†–væ÷&R’²FVÆWFR÷F–öç2çF–ÖU¦öæS²&WGW&âæWr–çFÂäFFUF–ÖTf÷&ÖB‚vVâÔRrÂ÷F–öç2’æf÷&ÖB†FFR“²Ð —Ð  –gVæ7F–öâ&VæFW$÷&FW"†÷&FW"’° —&W7VÇBæ–ææW$…DÔÂÒrs° —f"6W'f–6RÒ÷&FW"æ÷&FW%÷G—RÓÓÒvFVÆ—fW'’ròtFVÆ—fW'’r¢†÷&FW"æ÷&FW%÷G—RÓÓÒvF–æUö–âròtF–æR–âr¢u–6·Wr“° —f"6&BÒVÂ‚v'F–6ÆRrÂ²6Æ73¢vF"×G&6²Ö6&BrÒÂ° –VÂ‚vF—brÂ²6Æ73¢vF"×G&6²Ö6&BÖ†VBrÒÂ° –VÂ‚vF—brÂ·ÒÂ° –VÂ‚w7ârÂ²6Æ73¢vF"×G&6²×6W'f–6RrÂFW‡C¢6W'f–6RÒ’À –VÂ‚vƒBrÂ²FW‡C¢t÷&FW"r²÷&FW"æ÷&FW%öçVÖ&W"Ò •Ò’À –VÂ‚wrÂ²6Æ73¢vF"×7FGW2Ö&FvRrÂFW‡C¢÷&FW"æ7W7FöÖW%÷7FGW5öÆ&VÂÇÂ÷&FW"ç7FGW5öÆ&VÂÇÂ÷&FW"ç7FGW2Ò •Ò •Ò“° ––b†÷&FW"ç7FGW2ÓÓÒv6æ6VÆÆVBr’° —f"6æ6VÆÆVBÒVÂ‚wrÂ²6Æ73¢vF"×G&6²Ö6æ6VÆÆVBrÂFW‡C¢uF†—2÷&FW"v26æ6VÆÆVBrÒ“° –6æ6VÆÆVBç6WDGG&–'WFR‚w&öÆRrÂw7FGW2r“° –6&BæVæD6†–ÆB†6æ6VÆÆVB“° –6&BæVæD6†–ÆB†VÂ‚wrÂ²6Æ73¢vF"×G&6²Öæ÷FRrÂFW‡C¢÷&FW"ç–ÖVçE÷7FGW2ÓÓÒw&VgVæFVBròu–÷W"–ÖVçB†2&VVâ&VgVæFVBâr¢t–b–÷R–BöæÆ–æRÂ6öçF7BF†R6†÷Fò6öæf—&ÒF†R&VgVæB7FGW2ârÒ’“° —ÒVÇ6R° –6&BæVæD6†–ÆB†'V–ÆEG&6¶W"†÷&FW"’“° —f"g&öÒÒG&6¶–æuF–ÖR†÷&FW"ç&öÖ—6VE÷&VG•ög&öÕ÷WF2Â÷&FW"çF–ÖW¦öæR“° —f"'’ÒG&6¶–æuF–ÖR†÷&FW"ç&öÖ—6VE÷&VG•ö'•÷WF2Â÷&FW"çF–ÖW¦öæR“° —f"6†÷tW7F–ÖFRÒ²v6öæf—&ÖVBrÂw&W&–ærrÂv&¶–æruÒæ–æFW„öb†÷&FW"ç7FGW2’ÓÒÓ° ––b‡6†÷tW7F–ÖFRbbg&öÒ’° –6&BæVæD6†–ÆB†VÂ‚wrÂ²6Æ73¢vF"×G&6²×F–Ö–ærrÂFW‡C¢u7Ffb&VG’W7F–ÖFS¢r²†'’bb'’ÓÒg&öÒòg&öÒ²~(	2r²'’¢g&öÒ’Ò’“° —Ð —f"WFÒ'V–ÆDWF†÷&FW"“° ––b†WF’²6&BæVæD6†–ÆB†WF“²Ð ––b†÷&FW"æ7W7FöÖW%÷7FGW2ÓÓÒw&VG•öf÷%÷–6·Wr’° –6&BæVæD6†–ÆB†VÂ‚wrÂ²6Æ73¢vF"×G&6²Ö6öÆÆV7F–öârÂFW‡C¢u–÷W"÷&FW"—2&VG’(	BÆV6R6öÆÆV7B—Bg&öÒF†R6†÷ârÒ’“° —ÒVÇ6R–b†÷&FW"æ7W7FöÖW%÷7FGW2ÓÓÒw&VG•÷Fõ÷6W'fRr’° –6&BæVæD6†–ÆB†VÂ‚wrÂ²6Æ73¢vF"×G&6²Ö6öÆÆV7F–öârÂFW‡C¢u–÷W"÷&FW"—2&VG’âvRv–ÆÂ'&–ær—BFò–÷W"F&ÆRârÒ’“° —ÒVÇ6R–b†÷&FW"æ7W7FöÖW%÷7FGW2ÓÓÒw&VG•öf÷%öFVÆ—fW'’r’° –6&BæVæD6†–ÆB†VÂ‚wrÂ²6Æ73¢vF"×G&6²Ö6öÆÆV7F–öârÂFW‡C¢u–÷W"÷&FW"—2&VG’f÷"FVÆ—fW'’ârÒ’“° —Ð —Ð —f"—FV×2ÒVÂ‚wVÂrÂ²6Æ73¢vF"Ö—FVÒÖÆ—7BrÒ“° ’†÷&FW"æ—FV×2ÇÂµÒ’æf÷$V6‚†gVæ7F–öâ†—B’° –—FV×2æVæD6†–ÆB†VÂ‚vÆ’rÂ²FW‡C¢—BçVçF—G’²|9rr²—BææÖRÒ’“° —Ò“° –6&BæVæD6†–ÆB†—FV×2“° –6&BæVæD6†–ÆB†VÂ‚wrÂ²FW‡C¢uF÷FÃ¢r²ÖöæW’†÷&FW"çF÷FÂ’Ò’“° –6&BæVæD6†–ÆB†'V–ÆE–ÖVçD†–çB†÷&FW"’“° –6&BæVæD6†–ÆB†VÂ‚wrÂ° –6Æ73¢vF"×G&6²Ö6†V6¶VBrÀ —FW‡C¢tÆ—fR7FGW26†V6¶VBr²æWr–çFÂäFFUF–ÖTf÷&ÖB‚vVâÔRrÂ²†÷W#¢vçVÖW&–2rÂÖ–çWFS¢s"ÖF–v—BrÂ6V6öæC¢s"ÖF–v—BrÒ’æf÷&ÖB†æWrFFR‚’’²r+rWFFW2WFöÖF–6ÆÇ’p —Ò’“° —&W7VÇBæVæD6†–ÆB†6&B“°  ’òòFW&Ö–æÂ7FFW3¢7F÷öÆÆ–ærf÷"vööBà ––b†÷&FW"ç7FGW2ÓÓÒv6ö×ÆWFVBrÇÂ÷&FW"ç7FGW2ÓÓÒv6æ6VÆÆVBr’° —öÆÄFöæRÒG'VS° —7F÷öÆÆ–ær‚“° —Ð —Ð  –f÷&ÒæFDWfVçDÆ—7FVæW"‚w7V&Ö—BrÂgVæ7F–öâ†R’° –Rç&WfVçDFVfVÇB‚“° ’òòæWrÆöö·W–çfÆ–FFW2ç’–âÖfÆ–v‡BöÆÂ7–6ÆRà —7F÷öÆÆ–ær‚“° —öÆÄ¶W’ÒçVÆÃ° —öÆÄÆöö·WÒçVÆÃ° —öÆÄFöæRÒfÇ6S° —&W7VÇBæ–ææW$…DÔÂÒrs° —f"çVÖ&W"Òf÷&ÒæçVÖ&W"çfÇVRçG&–Ò‚“° —f"VÖ–ÂÒf÷&ÒæVÖ–ÂçfÇVRçG&–Ò‚“° —f"Æöö·WÒ²çVÖ&W#¢çVÖ&W"ÂVÖ–Ã¢VÖ–ÂÓ° —f"¶W’ÒçVÖ&W"²uÆâr²VÖ–ÂçFôÆ÷vW$66R‚“° –f÷&Òç6WDGG&–'WFR‚v&–Ö'W7’rÂwG'VRr“° ––b†Æöö·W'WGFöâ’° –Æöö·W'WGFöâæF—6&ÆVBÒG'VS° –Æöö·W'WGFöâçFW‡D6öçFVçBÒt6†V6¶–ærÆ—fR7FGW>(
bs° —Ð  —&WVW7B‚rö÷&FW"÷G&6²rÂ²ÖWF†öC¢uõ5BrÂ&öG“¢Æöö·WÒ ’çF†Vâ†gVæ7F–öâ†÷&FW"’° —öÆÄ¶W’Ò¶W“° —öÆÄÆöö·WÒÆöö·W° —öÆÅ7F'FVBÒFFRææ÷r‚“° —&VæFW$÷&FW"†÷&FW"“° —66†VGVÆUF–6²‚“° —Ò ’æ6F6‚†gVæ7F–öâ†W'"’° —&W7VÇBæ–ææW$…DÔÂÒrs° —&W7VÇBæVæD6†–ÆB†VÂ‚wrÂ²6Æ73¢vF"ÖW'&÷"rÂ&öÆS¢vÆW'BrÂFW‡C¢W'"æÖW76vRÒ’“° —Ò ’çF†Vâ†gVæ7F–öâ‚’° –f÷&Òç6WDGG&–'WFR‚v&–Ö'W7’rÂvfÇ6Rr“° ––b†Æöö·W'WGFöâ’° –Æöö·W'WGFöâæF—6&ÆVBÒfÇ6S° –Æöö·W'WGFöâçFW‡D6öçFVçBÒÆöö·WÆ&VÃ° —Ð —Ò“° —Ò“° —Ð  ’ò¢ÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒ¢ð ’ò¢&ö÷B¢ð ’ò¢ÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒ¢ð  –gVæ7F–öâ&ö÷B‚’° ’òò&W6öÇfR66ææVB×F&ÆR6W76–öâ&Vf÷&Rç’÷&FW&–ær6öçG&öÇ2&VæFW"Â6ð ’òòF†W&R—2æòÖöÖVçBv†W&R7W7FöÖW"6VW27v—F6†&ÆR6†÷÷"gVÆf–ÆÖVç@ ’òò÷F–öââæöâÕ"vW26–×Ç’6öçF–çVRv—F‚çVÆÂ6öçFW‡Bà –vWEF&ÆT6öçFW‡B‚’çF†Vâ†gVæ7F–öâ‚’° –Fö7VÖVçBçVW'•6VÆV7F÷$ÆÂ‚u¶FFÖF÷Vv†&÷72×6†÷Òr’æf÷$V6‚‡&VæFW%6†÷–6¶W"“° –Fö7VÖVçBçVW'•6VÆV7F÷$ÆÂ‚u¶FFÖF÷Vv†&÷72ÖÖVçUÒr’æf÷$V6‚‡&VæFW$ÖVçR“° –Fö7VÖVçBçVW'•6VÆV7F÷$ÆÂ‚u¶FFÖF÷Vv†&÷72Ö'V–ÆFW%Òr’æf÷$V6‚‡&VæFW$'V–ÆFW"“° –Fö7VÖVçBçVW'•6VÆV7F÷$ÆÂ‚u¶FFÖF÷Vv†&÷72Ö6'EÒr’æf÷$V6‚‡&VæFW$6'B“° –Fö7VÖVçBçVW'•6VÆV7F÷$ÆÂ‚u¶FFÖF÷Vv†&÷72×G&6¶–æuÒr’æf÷$V6‚‡&VæFW%G&6¶–ær“° —Ò’æ6F6‚†gVæ7F–öâ†W'"’° ’òò6Æ–ÖVB'WBW‡—&VB÷&Wfö¶VBF&ÆR6W76–öâ×W7BæWfW"6–ÆVçFÇ’&V6öÖP ’òò7v—F6†&ÆR–6·W÷&FW"â7F÷÷&FW&–æræBF—&V7BF†RwVW7BFò7Ffbà –Fö7VÖVçBçVW'•6VÆV7F÷$ÆÂ‚u¶FFÖF÷Vv†&÷72×6†÷ÒÂ¶FFÖF÷Vv†&÷72ÖÖVçUÒÂ¶FFÖF÷Vv†&÷72Ö'V–ÆFW%ÒÂ¶FFÖF÷Vv†&÷72Ö6'EÒr’æf÷$V6‚†gVæ7F–öâ‡&ö÷B’° —&ö÷Bæ–ææW$…DÔÂÒrs° —&ö÷BæVæD6†–ÆB†VÂ‚vF—brÂ²6Æ73¢vF"ÖW'&÷"rÂ&öÆS¢vÆW'BrÂFW‡C¢†W'"bbW'"æÖW76vRòW'"æÖW76vR¢uF†—2F&ÆR6W76–öâ—2æòÆöævW"7F—fRâr’²rÆV6R66âF†RF&ÆR"v–â÷"6²7FfbÖVÖ&W"f÷"†VÇârÒ’“° —Ò“° —Ò“° —Ð  ––b†Fö7VÖVçBç&VG•7FFRÓÓÒvÆöF–ærr’° –Fö7VÖVçBæFDWfVçDÆ—7FVæW"‚tDôÔ6öçFVçDÆöFVBrÂ&ö÷B“° —ÒVÇ6R° –&ö÷B‚“° —Ð§Ò‚’“°