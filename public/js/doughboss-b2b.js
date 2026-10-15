/**
 * DoughBoss — wholesale / franchising enquiry form ([doughboss_b2b_form]).
 *
 * Posts the form as JSON to POST /b2b/enquiry with the page's REST nonce. The
 * server validates every field again, applies a rate limit and a honeypot,
 * and emails the B2B inbox; this script only improves feedback.
 */
(function () {
	'use strict';

	var forms = document.querySelectorAll('[data-doughboss-b2b]');
	if (!forms.length || typeof window.fetch !== 'function') { return; }

	var FIELDS = ['name', 'business', 'email', 'phone', 'focus', 'message'];

	// Client-side checks mirror DoughBoss_B2B::validate(); the server decides.
	function b2bClientErrors(values, type) {
		var errors = {};
		var name = String(values.name || '').trim();
		var business = String(values.business || '').trim();
		var email = String(values.email || '').trim();
		var phone = String(values.phone || '').trim();
		var digits = phone.replace(/\D+/g, '');
		var message = String(values.message || '').trim();
		if (name.length < 2) { errors.name = 'Please enter your name.'; }
		if (type !== 'franchise' && business.length < 2) { errors.business = 'Please enter your business or venue name.'; }
		if (!/^[^\s@<>,;"]+@[^\s@<>,;"]+\.[^\s@<>,;"]+$/.test(email)) { errors.email = 'Please enter a valid email address.'; }
		if (!/^\+?[0-9 ()\-]+$/.test(phone) || digits.length < 8 || digits.length > 15) { errors.phone = 'Please enter a phone number we can call.'; }
		if (message.length < 10) { errors.message = 'Please tell us a little more about what you are exploring.'; }
		return errors;
	}

	function showErrors(form, errors) {
		var first = null;
		FIELDS.forEach(function (field) {
			var input = form.querySelector('[name="' + field + '"]');
			var slot = form.querySelector('[data-error-for="' + field + '"]');
			var message = errors && errors[field] ? String(errors[field]) : '';
			if (slot) { slot.textContent = message; }
			if (input) {
				if (message) {
					input.setAttribute('aria-invalid', 'true');
					if (!first) { first = input; }
				} else {
					input.removeAttribute('aria-invalid');
				}
			}
		});
		return first;
	}

	function setStatus(form, text, ok) {
		var status = form.querySelector('[data-b2b-status]');
		if (!status) { return; }
		status.textContent = text;
		status.className = 'db-b2b-status' + (text ? (ok ? ' is-success' : ' is-error') : '');
	}

	function wire(form) {
		var endpoint = form.getAttribute('data-endpoint') || '';
		var nonce = form.getAttribute('data-nonce') || '';
		var submitting = false;
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			if (submitting) { return; }
			var values = {};
			['type', 'website'].concat(FIELDS).forEach(function (field) {
				var input = form.querySelector('[name="' + field + '"]');
				values[field] = input ? String(input.value || '') : '';
			});
			var errors = b2bClientErrors(values, values.type);
			var first = showErrors(form, errors);
			if (first) {
				setStatus(form, 'Please check the highlighted fields.', false);
				first.focus();
				return;
			}
			if (!endpoint) {
				setStatus(form, 'The form is unavailable right now. Please call or email us directly.', false);
				return;
			}
			submitting = true;
			var button = form.querySelector('[type="submit"]');
			var label = button ? button.textContent : '';
			if (button) { button.disabled = true; button.textContent = 'Sending…'; }
			setStatus(form, '', false);
			fetch(endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body: JSON.stringify(values)
			}).then(function (response) {
				return response.json().catch(function () { return {}; }).then(function (data) {
					return { ok: response.ok, data: data || {} };
				});
			}).then(function (result) {
				if (result.ok && result.data.success) {
					form.reset();
					showErrors(form, {});
					setStatus(form, result.data.message || 'Thanks — your enquiry has been sent.', true);
					var status = form.querySelector('[data-b2b-status]');
					if (status && status.focus) { status.focus(); }
					return;
				}
				var fields = result.data && result.data.data && result.data.data.fields;
				var firstInvalid = showErrors(form, fields || {});
				setStatus(form, (result.data && result.data.message) || 'Your enquiry could not be sent. Please call or email us directly.', false);
				if (firstInvalid) { firstInvalid.focus(); }
			}).catch(function () {
				setStatus(form, 'Your enquiry could not be sent. Please check your connection, or call or email us directly.', false);
			}).then(function () {
				submitting = false;
				if (button) { button.disabled = false; button.textContent = label; }
			});
		});
	}

	Array.prototype.forEach.call(forms, wire);
}());
