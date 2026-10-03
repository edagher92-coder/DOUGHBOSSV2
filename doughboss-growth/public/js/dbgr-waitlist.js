/*
 * DoughBoss Growth: VIP waitlist form and the coming_soon_view event.
 *
 * ES5 (hand-written, checked by scripts/es5-check.mjs). Dynamic text goes in with textContent only.
 *
 * The form needs a signed form token from GET /form-token (so a cached page cannot break the sign-up and a bot that
 * posts instantly is refused). The token is fetched on load and a submit waits until the token is old enough
 * (the server refuses one younger than the configured minimum). Success is only shown after the server answers
 * success; waitlist_submit is sent only then. Nothing is stored in the browser.
 */
(function () {
	'use strict';

	var cfg = window.DoughBossGrowthWaitlist || null;
	var TOKEN_MAX_AGE_MS = 23 * 60 * 60 * 1000;
	var REQUEST_TIMEOUT_MS = 15000;

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
	function request(method, url, payload, done) {
		var xhr;
		var finished = false;
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
			if (payload !== null) {
				xhr.setRequestHeader('Content-Type', 'application/json');
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
			xhr.send(payload === null ? null : JSON.stringify(payload));
		} catch (e) {
			finish(0, null);
		}
	}

	function text(key, fallback) {
		var strings = cfg && cfg.strings ? cfg.strings : null;
		return strings && typeof strings[key] === 'string' ? strings[key] : fallback;
	}

	function field(form, name) {
		return form.querySelector('[name="' + name + '"]');
	}

	function trim(value) {
		return String(value === null || value === undefined ? '' : value).replace(/^\s+|\s+$/g, '');
	}

	/**
	 * The status line (below the button) is for what the server said and for success. When focusNode is true the line is
	 * made focusable and focused, so a keyboard or screen reader user lands on the confirmation after the form collapses.
	 */
	function setStatus(form, message, kind, focusNode) {
		var node = form.querySelector('[data-dbgr-wl-status]');
		if (!node) {
			return;
		}
		node.textContent = message;
		node.className = 'dbgr-wl__status' + (kind ? ' dbgr-wl__status--' + kind : '');
		if (focusNode === true && typeof node.focus === 'function') {
			node.setAttribute('tabindex', '-1');
			node.focus();
		}
	}

	/* ---- field-level errors: the message sits next to its own field and the field says it is invalid ---- */

	function errorId(node) {
		var id = node.getAttribute('id');
		return (id ? id : 'dbgr-wl-' + String(node.getAttribute('name') || 'field')) + '-err';
	}

	function hasToken(list, token) {
		return (' ' + trim(list).replace(/\s+/g, ' ') + ' ').indexOf(' ' + token + ' ') !== -1;
	}

	function addToken(list, token) {
		var current = trim(list);
		if (hasToken(current, token)) {
			return current;
		}
		return current === '' ? token : current + ' ' + token;
	}

	function removeToken(list, token) {
		var parts = trim(list).split(/\s+/);
		var kept = [];
		var i;
		for (i = 0; i < parts.length; i += 1) {
			if (parts[i] !== '' && parts[i] !== token) {
				kept.push(parts[i]);
			}
		}
		return kept.join(' ');
	}

	/** Put message in <span id="<field id>-err" class="dbgr-wl__err"> after the field (reused on the next try). */
	function fieldError(node, message) {
		var id = errorId(node);
		var span = document.getElementById(id);
		var anchor = node;
		if (!span) {
			span = document.createElement('span');
			span.setAttribute('id', id);
			span.className = 'dbgr-wl__err';
			/* A checkbox sits inside its label: put the message after the label so it is not read as part of the name. */
			if (anchor.parentNode && String(anchor.parentNode.nodeName).toLowerCase() === 'label') {
				anchor = anchor.parentNode;
			}
			if (anchor.parentNode) {
				anchor.parentNode.insertBefore(span, anchor.nextSibling);
			}
		}
		span.hidden = false;
		span.textContent = message;
		node.setAttribute('aria-invalid', 'true');
		node.setAttribute('aria-describedby', addToken(node.getAttribute('aria-describedby'), id));
	}

	function clearFieldError(node) {
		var id = errorId(node);
		var span = document.getElementById(id);
		var rest = removeToken(node.getAttribute('aria-describedby'), id);
		node.removeAttribute('aria-invalid');
		if (rest === '') {
			node.removeAttribute('aria-describedby');
		} else {
			node.setAttribute('aria-describedby', rest);
		}
		if (span) {
			span.textContent = '';
			span.hidden = true;
		}
	}

	/** An invalid field is cleared as soon as the visitor edits it (one delegated listener per form). */
	function clearOnEdit(form) {
		function onEdit(event) {
			var target = event ? event.target : null;
			if (target && typeof target.getAttribute === 'function' && target.getAttribute('aria-invalid') === 'true') {
				clearFieldError(target);
			}
		}
		form.addEventListener('input', onEdit);
		form.addEventListener('change', onEdit);
	}

	function wire(form) {
		var state = { token: '', tokenAt: 0, busy: false };
		var button = form.querySelector('button[type="submit"]');

		function fetchToken(done) {
			request('GET', cfg.tokenUrl, null, function (status, data) {
				if (status === 200 && data && typeof data.token === 'string' && data.token !== '') {
					state.token = data.token;
					state.tokenAt = new Date().getTime();
					done(true);
					return;
				}
				state.token = '';
				done(false);
			});
		}

		function ensureToken(done) {
			if (state.token !== '' && new Date().getTime() - state.tokenAt < TOKEN_MAX_AGE_MS) {
				done(true);
				return;
			}
			fetchToken(done);
		}

		function release() {
			state.busy = false;
			if (button) {
				button.removeAttribute('aria-disabled');
			}
			form.removeAttribute('aria-busy');
		}

		function send() {
			var store = field(form, 'store');
			var slug = 'none';
			var option;
			var payload;
			if (store && store.selectedIndex >= 0 && store.options && store.options[store.selectedIndex]) {
				option = store.options[store.selectedIndex];
				slug = option.getAttribute('data-slug') || 'none';
			}
			payload = {
				email: trim(field(form, 'email').value),
				first_name: trim(field(form, 'first_name') ? field(form, 'first_name').value : ''),
				mobile: trim(field(form, 'mobile') ? field(form, 'mobile').value : ''),
				store: store ? store.value : '',
				consent: 1,
				consent_version: field(form, 'consent_version').value,
				website: field(form, 'website') ? field(form, 'website').value : '',
				token: state.token,
				path: window.location.pathname
			};
			request('POST', cfg.signupUrl, payload, function (status, data) {
				var fields;
				var code = data && typeof data.code === 'string' ? data.code : '';
				release();
				if (status === 200 && data && data.success === true) {
					fields = form.querySelector('[data-dbgr-wl-fields]');
					field(form, 'email').value = '';
					if (field(form, 'first_name')) {
						field(form, 'first_name').value = '';
					}
					if (field(form, 'mobile')) {
						field(form, 'mobile').value = '';
					}
					field(form, 'consent').checked = false;
					if (fields) {
						fields.hidden = true;
					}
					setStatus(form, typeof data.message === 'string' && data.message !== '' ? data.message : text('generic', ''), 'ok', true);
					track('waitlist_submit', { store: slug });
					return;
				}
				if (code === 'dbgr_token_invalid' || code === 'dbgr_token_early' || code === 'dbgr_consent_changed') {
					state.token = '';
					fetchToken(function () {});
				}
				if (status === 0) {
					setStatus(form, text('network', ''), 'error');
				} else if (data && typeof data.message === 'string' && data.message !== '') {
					setStatus(form, data.message, 'error');
				} else {
					setStatus(form, text('generic', ''), 'error');
				}
			});
		}

		/**
		 * Run both checks, mark every bad field and focus the first bad one (in page order). True when the form is fine.
		 */
		function validate() {
			var emailNode = field(form, 'email');
			var consent = field(form, 'consent');
			var email = trim(emailNode.value);
			var first = null;
			var failed = false;
			function check(node, bad, message) {
				if (!bad) {
					if (node) {
						clearFieldError(node);
					}
					return;
				}
				failed = true;
				if (!node) {
					setStatus(form, message, 'error'); /* no field to attach it to */
					return;
				}
				fieldError(node, message);
				if (!first) {
					first = node;
				}
			}
			setStatus(form, '', ''); /* a message from an earlier try is out of date now */
			check(emailNode, email === '' || email.indexOf('@') < 1, text('email', ''));
			check(consent, !consent || !consent.checked, text('consent', ''));
			if (first && typeof first.focus === 'function') {
				first.focus();
			}
			return !failed;
		}

		form.addEventListener('submit', function (event) {
			if (event && typeof event.preventDefault === 'function') {
				event.preventDefault();
			}
			if (state.busy) {
				return;
			}
			if (!validate()) {
				return;
			}
			state.busy = true;
			if (button) {
				button.setAttribute('aria-disabled', 'true'); /* not disabled: keyboard focus must stay on the button */
			}
			form.setAttribute('aria-busy', 'true');
			setStatus(form, text('sending', ''), '');
			ensureToken(function (ok) {
				var minimum;
				var wait;
				if (!ok) {
					release();
					setStatus(form, text('network', ''), 'error');
					return;
				}
				minimum = (cfg.minAge > 0 ? cfg.minAge : 3) * 1000 + 300;
				wait = minimum - (new Date().getTime() - state.tokenAt);
				if (wait > 0) {
					setStatus(form, text('wait', ''), '');
					window.setTimeout(send, wait);
				} else {
					send();
				}
			});
		});

		clearOnEdit(form);
		fetchToken(function () {});
	}

	/** coming_soon_view: once, when the section first scrolls into view; retried once if consent arrives later. */
	function watchSections() {
		var nodes = document.querySelectorAll('[data-dbgr-coming-soon][data-dbgr-surface="home"]');
		var viewed = false;
		var sent = false;
		var observer = null;
		var i;

		function attempt() {
			if (sent) {
				return;
			}
			viewed = true;
			if (track('coming_soon_view', { surface: 'home' })) {
				sent = true;
			}
		}

		if (!nodes || nodes.length === 0) {
			return;
		}
		document.addEventListener('doughboss-growth:consent-changed', function () {
			if (viewed && !sent) {
				attempt();
			}
		});
		if (typeof window.IntersectionObserver !== 'function') {
			attempt();
			return;
		}
		observer = new window.IntersectionObserver(function (entries) {
			var j;
			for (j = 0; j < entries.length; j += 1) {
				if (entries[j].isIntersecting) {
					attempt();
					observer.disconnect();
					return;
				}
			}
		}, { threshold: 0.25 });
		for (i = 0; i < nodes.length; i += 1) {
			observer.observe(nodes[i]);
		}
	}

	function init() {
		var forms;
		var i;
		watchSections();
		if (!cfg || typeof cfg.tokenUrl !== 'string' || typeof cfg.signupUrl !== 'string') {
			return;
		}
		forms = document.querySelectorAll('form[data-dbgr-waitlist]');
		for (i = 0; i < forms.length; i += 1) {
			wire(forms[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
