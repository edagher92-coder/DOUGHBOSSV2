/**
 * Offers & News — single-brand voucher follow gate.
 *
 * Replaces snowboss.js after the Snow Boss retirement. This gate unlocks the
 * $5 student voucher when the visitor taps Follow @doughboss (single Instagram,
 * not the dual-brand gate the retired Snow Boss section used). Issued codes
 * carry the new DOUGH- prefix.
 */
(function () {
	'use strict';

	var form = document.getElementById('sb-voucher-form');
	if (!form) { return; }

	var followed = { doughboss: false };
	var email = document.getElementById('sb-email');
	var claim = form.querySelector('.sb-claim');
	var lockMsg = document.getElementById('sb-lockmsg');
	var hidden = document.getElementById('sb-followed');
	var errEl = document.getElementById('sb-err');
	var thanks = document.getElementById('sb-thanks');

	function isFollowed() {
		return followed.doughboss;
	}

	function refresh() {
		var unlocked = isFollowed();
		form.classList.toggle('is-locked', !unlocked);
		if (email) { email.disabled = !unlocked; }
		if (claim) { claim.disabled = !unlocked; }
		if (hidden) { hidden.value = unlocked ? '@doughboss' : ''; }
		if (lockMsg) {
			if (unlocked) {
				lockMsg.innerHTML =
					'<span class="sb-unlock" aria-hidden="true">✓</span> ' +
					'Unlocked &mdash; drop your email to lock in the voucher.';
				lockMsg.classList.add('is-unlocked');
			} else {
				lockMsg.innerHTML =
					'<span class="sb-lock" aria-hidden="true">🔒</span> ' +
					'Follow <b>@doughboss</b> to unlock.';
				lockMsg.classList.remove('is-unlocked');
			}
		}
		if (unlocked && email && document.activeElement !== email) {
			try { email.focus({ preventScroll: true }); } catch (e) { email.focus(); }
		}
	}

	var gate = form.closest('.sb-gate') || document;
	gate.querySelectorAll('.sb-follow').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var brand = btn.getAttribute('data-brand');
			if (!brand || followed[brand]) { return; }
			followed[brand] = true;
			btn.classList.add('is-followed');
			var state = btn.querySelector('.sb-follow-state');
			if (state) { state.textContent = 'Following ✓'; }
			refresh();
		});
	});

	function genCode() {
		var chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';   // skip ambiguous 0/O/1/I/L
		var s = '';
		for (var i = 0; i < 6; i++) {
			s += chars.charAt(Math.floor(Math.random() * chars.length));
		}
		return 'DOUGH-' + s;
	}

	function reveal() {
		var gate = form.closest('.sb-gate') || document;
		form.hidden = true;
		gate.querySelectorAll('.sb-follows, .sb-step').forEach(function (el) {
			el.hidden = true;
		});
		if (thanks) { thanks.hidden = false; }
	}

	var copyBtn = document.getElementById('sb-copy');
	if (copyBtn) {
		copyBtn.addEventListener('click', function () {
			var codeEl = document.getElementById('sb-code');
			var text = codeEl ? codeEl.textContent : '';
			function done() {
				copyBtn.textContent = 'Copied ✓';
				setTimeout(function () { copyBtn.textContent = 'Copy'; }, 1600);
			}
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(done, done);
			} else {
				done();
			}
		});
	}

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		if (errEl) { errEl.textContent = ''; }

		if (!isFollowed()) {
			if (errEl) { errEl.textContent = 'Please follow @doughboss first.'; }
			return;
		}
		var val = email && email.value.trim();
		if (!val || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
			if (errEl) { errEl.textContent = 'Enter a valid email address.'; }
			if (email) { email.focus(); }
			return;
		}

		var code = genCode();
		var codeEl = document.getElementById('sb-code');
		if (codeEl) { codeEl.textContent = code; }

		if (claim) {
			claim.disabled = true;
			claim.textContent = 'Issuing…';
		}

		var fd = new FormData(form);
		fd.append('code', code);
		fetch(form.action, {
			method: 'POST',
			body: fd,
			headers: { 'Accept': 'application/json' }
		}).catch(function () { /* best-effort lead capture — always issue */ })
			.then(reveal);
	});

	refresh();
}());
