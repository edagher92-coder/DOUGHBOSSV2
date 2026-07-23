(function () {
	'use strict';
	var stages = Array.prototype.slice.call(document.querySelectorAll('[data-manoush-stage]'));
	if (!stages.length) { return; }
	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var explodeHoldMs = 2050;
	document.documentElement.classList.add('motion-ready');

	function clearStageTimer(stage) {
		if (stage._dbManoushTimer) {
			window.clearTimeout(stage._dbManoushTimer);
			stage._dbManoushTimer = 0;
		}
	}

	function playReady(stage) {
		clearStageTimer(stage);
		if (reduce) {
			stage.classList.add('is-assembled');
			stage.classList.remove('is-exploded');
			return;
		}
		stage.classList.remove('is-exploded');
		stage.classList.remove('is-assembled');
		void stage.offsetWidth;
		window.requestAnimationFrame(function () {
			window.requestAnimationFrame(function () {
				stage.classList.add('is-exploded');
				stage._dbManoushTimer = window.setTimeout(function () {
					stage.classList.remove('is-exploded');
					stage.classList.add('is-assembled');
					stage._dbManoushTimer = 0;
				}, explodeHoldMs);
			});
		});
	}

	function imagesReady(stage, done) {
		var images = Array.prototype.slice.call(stage.querySelectorAll('img'));
		var remaining = images.filter(function (image) { return !image.complete; }).length;
		var finished = false;
		function doneOnce() {
			if (finished) { return; }
			finished = true;
			done();
		}
		if (!remaining) { doneOnce(); return; }
		images.forEach(function (image) {
			if (image.complete) { return; }
			function settled() {
				remaining -= 1;
				if (!remaining) { doneOnce(); }
			}
			image.addEventListener('load', settled, { once: true });
			image.addEventListener('error', settled, { once: true });
		});
		window.setTimeout(doneOnce, 1400);
	}

	function play(stage) {
		imagesReady(stage, function () { playReady(stage); });
	}

	function stageForView(view) {
		if (view === 'about') { return 'full'; }
		if (view === 'catering') { return 'bites'; }
		return '';
	}
	function playForView(view) {
		var variant = stageForView(view);
		if (!variant) { return; }
		stages.filter(function (stage) { return stage.getAttribute('data-manoush-variant') === variant; }).forEach(play);
	}

	function wireReplay() {
		Array.prototype.slice.call(document.querySelectorAll('[data-manoush-replay]')).forEach(function (button) {
			button.addEventListener('click', function () {
				var variant = button.getAttribute('data-manoush-replay');
				var stage = stages.filter(function (item) {
					return item.getAttribute('data-manoush-variant') === variant;
				})[0];
				if (!stage) { return; }
				play(stage);
				button.setAttribute('aria-pressed', 'true');
				window.setTimeout(function () { button.removeAttribute('aria-pressed'); }, reduce ? 50 : explodeHoldMs + 1000);
			});
		});
	}

	function updateStoreStatus() {
		var status = document.querySelector('[data-store-status]');
		var label = document.querySelector('[data-store-status-text]');
		if (!status || !label) { return; }
		try {
			var parts = new Intl.DateTimeFormat('en-AU', {
				timeZone: 'Australia/Sydney',
				weekday: 'short',
				hour: '2-digit',
				minute: '2-digit',
				hour12: false
			}).formatToParts(new Date());
			var values = {};
			parts.forEach(function (part) { values[part.type] = part.value; });
			var minutes = Number(values.hour) * 60 + Number(values.minute);
			var open = minutes >= 390 && minutes < 870;
			status.classList.toggle('is-closed', !open);
			label.textContent = open ? 'Baking now · Revesby' : 'Preorders welcome · Revesby';
		} catch (error) {
			label.textContent = 'Fresh-baked daily · Revesby';
		}
	}

	function wireReveals() {
		var revealItems = Array.prototype.slice.call(document.querySelectorAll(
			'#view-about .show .grid, #view-about .steps .head, #view-about .steps .grid, #view-about .member-preview, #view-about .final'
		));
		revealItems.forEach(function (item) {
			item.classList.add(item.matches('.steps .grid') ? 'db-reveal-group' : 'db-reveal');
		});
		if (reduce || !('IntersectionObserver' in window)) {
			revealItems.forEach(function (item) { item.classList.add('is-visible'); });
			return;
		}
		var observer = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (!entry.isIntersecting) { return; }
				entry.target.classList.add('is-visible');
				observer.unobserve(entry.target);
			});
		}, { rootMargin: '0px 0px -10% 0px', threshold: 0.12 });
		revealItems.forEach(function (item) { observer.observe(item); });
	}

	function wireScrollScenes() {
		var scenes = Array.prototype.slice.call(document.querySelectorAll('[data-scroll-scene]'));
		if (!scenes.length || reduce) { return; }
		var queued = false;
		function render() {
			var viewport = window.innerHeight || 800;
			scenes.forEach(function (scene) {
				if (scene.closest('.view') && !scene.closest('.view').classList.contains('active')) { return; }
				var rect = scene.getBoundingClientRect();
				var centre = (rect.top + rect.height / 2 - viewport / 2) / Math.max(viewport + rect.height, 1);
				var progress = Math.max(0, Math.min(1, (viewport - rect.top) / Math.max(viewport + rect.height, 1)));
				scene.style.setProperty('--scene-y', (centre * -34).toFixed(1) + 'px');
				scene.style.setProperty('--scene-scale', (1.055 + Math.sin(progress * Math.PI) * 0.035).toFixed(3));
			});
			queued = false;
		}
		function requestRender() {
			if (queued) { return; }
			queued = true;
			window.requestAnimationFrame(render);
		}
		window.addEventListener('scroll', requestRender, { passive: true });
		window.addEventListener('resize', requestRender);
		window.addEventListener('db:view', requestRender);
		requestRender();
	}

	window.addEventListener('db:view', function (event) {
		playForView(event && event.detail ? event.detail : '');
	});
	wireReplay();
	updateStoreStatus();
	wireReveals();
	wireScrollScenes();
	playForView((window.location.hash || '#about').slice(1));
}());
