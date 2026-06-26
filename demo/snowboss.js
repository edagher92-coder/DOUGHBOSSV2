/**
 * Snow Boss — dual-brand view.
 * 1) Animated falling snow (snowflakes), generated only when motion is allowed.
 * 2) Instagram follow-gate: the $5 + $10 student voucher form stays locked until
 *    the visitor taps Follow @doughboss AND Follow @snowboss, then the email
 *    field unlocks and the claim posts to Formspree.
 */
(function () {
	'use strict';

	var reduce = window.matchMedia &&
		window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	/* ---------- falling snow ---------- */
	var field = document.getElementById('snow-field');
	if (field && !reduce && !field.dataset.seeded) {
		field.dataset.seeded = '1';
		var COUNT = window.innerWidth < 700 ? 34 : 64;
		var frag = document.createDocumentFragment();
		for (var i = 0; i < COUNT; i++) {
			var flake = document.createElement('span');
			flake.className = 'flake';
			var size = (Math.random() * 4 + 2).toFixed(1);          // 2–6px
			var fall = (Math.random() * 9 + 7).toFixed(1);          // 7–16s
			var drift = (Math.random() * 4 + 3).toFixed(1);         // 3–7s sway
			flake.style.left = (Math.random() * 100).toFixed(2) + '%';
			flake.style.width = size + 'px';
			flake.style.height = size + 'px';
			flake.style.opacity = (Math.random() * 0.55 + 0.35).toFixed(2);
			flake.style.animationDuration = fall + 's, ' + drift + 's';
			flake.style.animationDelay =
				(-Math.random() * fall).toFixed(1) + 's, ' +
				(-Math.random() * drift).toFixed(1) + 's';
			frag.appendChild(flake);
		}
		field.appendChild(frag);
	}

	/* ---------- instagram follow-gate ---------- */
	var form = document.getElementById('sb-voucher-form');
	if (!form) { return; }

	var followed = { doughboss: false, snowboss: false };
	var email = document.getElementById('sb-email');
	var claim = form.querySelector('.sb-claim');
	var lockMsg = document.getElementById('sb-lockmsg');
	var hidden = document.getElementById('sb-followed');
	var errEl = document.getElementById('sb-err');
	var thanks = document.getElementById('sb-thanks');

	function bothFollowed() {
		return followed.doughboss && followed.snowboss;
	}

	function refresh() {
		var unlocked = bothFollowed();
		form.classList.toggle('is-locked', !unlocked);
		if (email) { email.disabled = !unlocked; }
		if (claim) { claim.disabled = !unlocked; }
		hidden.value = Object.keys(followed)
			.filter(function (k) { return followed[k]; })
			.map(function (k) { return '@' + k; })
			.join(', ');
		if (lockMsg) {
			if (unlocked) {
				lockMsg.innerHTML =
					'<span class="sb-unlock" aria-hidden="true">✓</span> ' +
					'Unlocked &mdash; drop your email to lock in the voucher.';
				lockMsg.classList.add('is-unlocked');
			} else {
				var need = [];
				if (!followed.doughboss) { need.push('<b>@doughboss</b>'); }
				if (!followed.snowboss) { need.push('<b>@snowbosssyd</b>'); }
				lockMsg.innerHTML =
					'<span class="sb-lock" aria-hidden="true">🔒</span> ' +
					'Follow ' + need.join(' and ') + ' to unlock.';
				lockMsg.classList.remove('is-unlocked');
			}
		}
		if (unlocked && email && document.activeElement !== email) {
			try { email.focus({ preventScroll: true }); } catch (e) { email.focus(); }
		}
	}

	// The follow buttons live in .sb-follows, a SIBLING of the form (not inside it),
	// so scope the query to the gate container, not the form.
	var gate = form.closest('.sb-gate') || document;
	gate.querySelectorAll('.sb-follow').forEach(function (btn) {
		btn.addEventListener('click', function () {
			// The link still opens Instagram in a new tab (target="_blank");
			// tapping it also marks the brand as followed.
			var brand = btn.getAttribute('data-brand');
			if (!brand || followed[brand]) { return; }
			followed[brand] = true;
			btn.classList.add('is-followed');
			var state = btn.querySelector('.sb-follow-state');
			if (state) { state.textContent = 'Following ✓'; }
			refresh();
		});
	});

	/* ---------- voucher code ---------- */
	function genCode() {
		var chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';   // skip ambiguous 0/O/1/I/L
		var s = '';
		for (var i = 0; i < 6; i++) {
			s += chars.charAt(Math.floor(Math.random() * chars.length));
		}
		return 'SNOW-' + s;
	}

	function reveal() {
		var gate = form.closest('.sb-gate') || document;
		form.hidden = true;
		gate.querySelectorAll('.sb-follows, .sb-step').forEach(function (el) {
			el.hidden = true;
		});
		if (thanks) { thanks.hidden = false; }
	}

	/* copy the issued code to the clipboard */
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

	/* ---------- claim submit ---------- */
	form.addEventListener('submit', function (e) {
		e.preventDefault();
		if (errEl) { errEl.textContent = ''; }

		if (!bothFollowed()) {
			if (errEl) { errEl.textContent = 'Please follow both brands first.'; }
			return;
		}
		var val = email && email.value.trim();
		if (!val || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
			if (errEl) { errEl.textContent = 'Enter a valid email address.'; }
			if (email) { email.focus(); }
			return;
		}

		// issue the voucher code and show it on the ticket
		var code = genCode();
		var codeEl = document.getElementById('sb-code');
		if (codeEl) { codeEl.textContent = code; }

		if (claim) {
			claim.disabled = true;
			claim.textContent = 'Issuing…';
		}

		// lead-capture is best-effort — the voucher always issues in the demo
		var fd = new FormData(form);
		fd.append('code', code);
		fetch(form.action, {
			method: 'POST',
			body: fd,
			headers: { 'Accept': 'application/json' }
		}).catch(function () { /* ignore network errors in the demo */ })
			.then(reveal);
	});

	refresh();
}());
