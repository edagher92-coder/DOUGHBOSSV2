/*
 * DoughBoss Growth: corporate lead form (WP-07).
 *
 * ES5 (hand-written, checked by scripts/es5-check.mjs). Dynamic text goes in with textContent only.
 *
 * The form posts to core's catering enquiry route with core's field names and core's wp_rest nonce (the X-WP-Nonce
 * header), plus dbgr_company, dbgr_segment, dbgr_landing_key and, ONLY when the visitor ticked the box,
 * dbgr_consent_marketing=1 with dbgr_consent_text_version. Success is shown only after core answers success; the
 * generate_lead event (form catering_enquiry, category, guest_band, store) is sent only when core returned an enquiry
 * number (core answers a filled honeypot with a silent success and no number, which is not a lead). Nothing is stored
 * in the browser and no personal data goes into the event.
 */
(function () {
	'use strict';

	var cfg = window.DoughBossGrowthLeads || null;
	var REQUEST_TIMEOUT_MS = 20000;

	/** Send a typed event if the consent module is present. Never throws; false when dropped. */
	function track(name, params) {
		try {
			if (window.DoughBossGrowth && typeof window.DoughBossGrowth.track === 'function') {
				return window.DoughBossGrowth.track(name, params) === true;
			}
		} catch (e) {
			/* tracking must never break the page */
		}
		return false;
	}

	/** One JSON request. done(status, data): status 0 means the network failed. */
	function request(method, url, headers, payload, done) {
		var xhr;
		var finished = false;
		var name;
		function finish(status, data) {
			if (finished) {
				return;
			}
			finished = true;
			done(status, data);
		}
		try {
			xhr = new XMLHttpRequest();
			xhr.open(method, url, true);
			xhr.setRequestHeader('Accept', 'application/json');
			xhr.setRequestHeader('Content-Type', 'application/json');
			for (name in headers) {
				if (Object.prototype.hasOwnProperty.call(headers, name)) {
					xhr.setRequestHeader(name, headers[name]);
				}
			}
			xhr.timeout = REQUEST_TIMEOUT_MS;
			xhr.onreadystatechange = function () {
				var data = null;
				if (xhr.readyState !== 4) {
					return;
				}
				try {
					data = JSON.parse(xhr.responseText);
				} catch (e) {
					data = null;
				}
				finish(xhr.status, data);
			};
			xhr.send(JSON.stringify(payload));
		} catch (e) {
			finish(0, null);
		}
	}

	function text(key, fallback) {
		var strings = cfg && cfg.strings ? cfg.strings : null;
		return strings && typeof strings[key] === 'string' ? strings[key] : fallback;
	}

	function trim(value) {
		return String(value === null || value === undefined ? '' : value).replace(/^\s+|\s+$/g, '');
	}

	function field(form, name) {
		return form.querySelector('[name="' + name + '"]');
	}

	function valueOf(form, name) {
		var node = field(form, name);
		return node ? trim(node.value) : '';
	}

	function setStatus(form, message, kind) {
		var node = form.querySelector('[data-dbgr-lead-status]');
		if (!node) {
			return;
		}
		node.textContent = message;
		node.className = 'dbgr-lead__status' + (kind ? ' dbgr-lead__status--' + kind : '');
	}

	/** A whole number from digits only, within 1..max; otherwise 0. */
	function wholeGuests(raw, max) {
		var n;
		if (!/^[0-9]{1,6}$/.test(raw)) {
			return 0;
		}
		n = parseInt(raw, 10);
		return (n >= 1 && n <= max) ? n : 0;
	}

	/** Analytics band for a guest count. */
	function guestBand(n) {
		if (n < 10) {
			return '1-9';
		}
		if (n < 25) {
			return '10-24';
		}
		if (n < 50) {
			return '25-49';
		}
		if (n < 100) {
			return '50-99';
		}
		return '100+';
	}

	/** The slug of the chosen shop (revesby, bankstown, roselands) or none. */
	function storeSlug(form) {
		var select = field(form, 'location_id');
		var option;
		var slug;
		if (select && select.selectedIndex >= 0 && select.options && select.options[select.selectedIndex]) {
			option = select.options[select.selectedIndex];
			slug = option.getAttribute('data-slug');
			if (slug === 'revesby' || slug === 'bankstown' || slug === 'roselands') {
				return slug;
			}
		}
		return 'none';
	}

	function toInt(raw) {
		return /^[0-9]{1,9}$/.test(raw) ? parseInt(raw, 10) : 0;
	}

	function wire(form) {
		var busy = false;
		var button = form.querySelector('button[type="submit"]');
		var orderType = field(form, 'order_type');
		var address = form.querySelector('[data-dbgr-lead-address]');
		var max = (cfg && cfg.maxGuests > 0) ? cfg.maxGuests : 1000;

		function release() {
			busy = false;
			if (button) {
				button.disabled = false;
			}
			form.removeAttribute('aria-busy');
		}

		function syncAddress() {
			if (address && orderType) {
				address.hidden = orderType.value !== 'delivery';
			}
		}

		function clear() {
			var names = ['customer_name', 'dbgr_company', 'customer_email', 'customer_phone', 'guest_count', 'event_date', 'address', 'notes'];
			var i;
			var node;
			for (i = 0; i < names.length; i += 1) {
				node = field(form, names[i]);
				if (node) {
					node.value = '';
				}
			}
			node = field(form, 'dbgr_consent_marketing');
			if (node) {
				node.checked = false;
			}
		}

		function fail(message, name) {
			var node = name ? field(form, name) : null;
			setStatus(form, message, 'error');
			if (node && typeof node.focus === 'function') {
				node.focus();
			}
		}

		function send(guests) {
			var consentBox = field(form, 'dbgr_consent_marketing');
			var ticked = !!(consentBox && consentBox.checked);
			var version = valueOf(form, 'dbgr_consent_text_version');
			var segment = form.getAttribute('data-segment') || '';
			var payload = {
				customer_name: valueOf(form, 'customer_name'),
				customer_email: valueOf(form, 'customer_email'),
				customer_phone: valueOf(form, 'customer_phone'),
				package_id: toInt(valueOf(form, 'package_id')),
				guest_count: guests,
				order_type: valueOf(form, 'order_type') === 'delivery' ? 'delivery' : 'pickup',
				location_id: toInt(valueOf(form, 'location_id')),
				event_date: valueOf(form, 'event_date'),
				address: valueOf(form, 'address'),
				notes: valueOf(form, 'notes'),
				hp: valueOf(form, 'hp'),
				dbgr_company: valueOf(form, 'dbgr_company'),
				dbgr_segment: segment,
				dbgr_landing_key: form.getAttribute('data-landing') || ''
			};
			if (ticked && version !== '') {
				payload.dbgr_consent_marketing = '1';
				payload.dbgr_consent_text_version = version;
			}
			request('POST', cfg.enquiryUrl, { 'X-WP-Nonce': cfg.nonce }, payload, function (status, data) {
				var number;
				var fields;
				var message;
				var band = guestBand(guests);
				var slug = storeSlug(form);
				release();
				if (status === 200 && data && data.success === true) {
					number = (typeof data.enquiry_number === 'string') ? trim(data.enquiry_number) : '';
					clear();
					fields = form.querySelector('[data-dbgr-lead-fields]');
					if (fields) {
						fields.hidden = true;
					}
					message = text('sent', '');
					if (number !== '') {
						message = message + ' ' + text('number', '') + ' ' + number + '.';
						track('generate_lead', { form: 'catering_enquiry', category: segment, guest_band: band, store: slug });
					}
					setStatus(form, message, 'ok');
					return;
				}
				if (status === 0) {
					setStatus(form, text('network', ''), 'error');
				} else if (status === 403) {
					setStatus(form, text('refresh', ''), 'error');
				} else if (status === 429) {
					setStatus(form, text('limit', ''), 'error');
				} else if (data && typeof data.message === 'string' && data.message !== '') {
					setStatus(form, data.message, 'error');
				} else {
					setStatus(form, text('generic', ''), 'error');
				}
			});
		}

		form.addEventListener('submit', function (event) {
			var email;
			var guests;
			if (event && typeof event.preventDefault === 'function') {
				event.preventDefault();
			}
			if (busy) {
				return;
			}
			if (valueOf(form, 'customer_name') === '') {
				fail(text('name', ''), 'customer_name');
				return;
			}
			email = valueOf(form, 'customer_email');
			if (email.length < 5 || email.indexOf('@') < 1 || email.lastIndexOf('.') < email.indexOf('@') + 2 || email.lastIndexOf('.') === email.length - 1) {
				fail(text('email', ''), 'customer_email');
				return;
			}
			if (form.getAttribute('data-company-required') === '1' && valueOf(form, 'dbgr_company') === '') {
				fail(text('company', ''), 'dbgr_company');
				return;
			}
			guests = wholeGuests(valueOf(form, 'guest_count'), max);
			if (guests < 1) {
				fail(text('guests', ''), 'guest_count');
				return;
			}
			busy = true;
			if (button) {
				button.disabled = true;
			}
			form.setAttribute('aria-busy', 'true');
			setStatus(form, text('sending', ''), '');
			send(guests);
		});

		if (orderType) {
			orderType.addEventListener('change', syncAddress);
		}
		syncAddress();
	}

	/** Without a usable configuration the form must do nothing: never let the browser post it to the page. */
	function inert(form) {
		form.addEventListener('submit', function (event) {
			if (event && typeof event.preventDefault === 'function') {
				event.preventDefault();
			}
			setStatus(form, text('generic', 'Something went wrong. Please try again later.'), 'error');
		});
	}

	function init() {
		var forms;
		var i;
		forms = document.querySelectorAll('form[data-dbgr-lead-form]');
		for (i = 0; i < forms.length; i += 1) {
			if (!cfg || typeof cfg.enquiryUrl !== 'string' || typeof cfg.nonce !== 'string' || cfg.nonce === '') {
				inert(forms[i]);
			} else {
				wire(forms[i]);
			}
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
