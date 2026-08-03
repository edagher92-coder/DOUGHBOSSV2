(function () {
	'use strict';
	var stages = Array.prototype.slice.call(document.querySelectorAll('[data-manoush-stage]'));
	if (!stages.length) { return; }
	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var explodeHoldMs = 820;
	document.documentElement.classList.add('motion-ready');

	function clearStageTimer(stage) {
		if (stage._dbManoushTimer) {
			window.clearTimeout(stage._dbManoushTimer);
			stage._dbManoushTimer = 0;
		}
		if (stage._dbManoushReleaseTimer) {
			window.clearTimeout(stage._dbManoushReleaseTimer);
			stage._dbManoushReleaseTimer = 0;
		}
	}

	function playReady(stage) {
		clearStageTimer(stage);
		if (reduce) {
			stage.classList.remove('is-resetting');
			stage.classList.add('is-assembled');
			stage.classList.remove('is-exploded');
			return;
		}
		// Keep scroll motion from taking control until the food has completed its
		// entrance. Without this short reservation, the first scroll-scene paint
		// cancels the deliberate burst-and-assemble sequence on page load.
		stage._dbManoushIntroUntil = Date.now() + explodeHoldMs + 1550;
		stage.classList.remove('is-exploded');
		stage.classList.remove('is-assembled');
		stage.classList.add('is-resetting');
		void stage.offsetWidth;
		window.requestAnimationFrame(function () {
			window.requestAnimationFrame(function () {
				stage.classList.remove('is-resetting');
				stage.classList.add('is-exploded');
				stage._dbManoushTimer = window.setTimeout(function () {
					stage.classList.remove('is-exploded');
					stage.classList.add('is-assembled');
					stage._dbManoushTimer = 0;
					stage._dbManoushReleaseTimer = window.setTimeout(function () {
						stage._dbManoushIntroUntil = 0;
						stage._dbManoushReleaseTimer = 0;
						window.dispatchEvent(new Event('db:manoush-ready'));
					}, 1450);
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
		if (!reduce) {
			// Reserve the stage straight away; image decoding can be asynchronous
			// while the scroll renderer is already queued for its first frame.
			stage._dbManoushIntroUntil = Date.now() + explodeHoldMs + 2400;
		}
		releaseScrollStage(stage);
		imagesReady(stage, function () { playReady(stage); });
	}

	function stageForView(view) {
		if (view === 'about') { return 'full'; }
		if (view === 'menu') { return 'menu'; }
		if (view === 'catering') { return 'bites'; }
		return '';
	}
	function playForView(view) {
		var variant = stageForView(view);
		if (!variant) { return; }
		stages.filter(function (stage) { return stage.getAttribute('data-manoush-variant') === variant; }).forEach(play);
	}

	function releaseScrollStage(stage) {
		Array.prototype.slice.call(stage.querySelectorAll('.ingredient-burst__manoush,.ingredient,.ingredient-burst__stamp')).forEach(function (part) {
			part.style.removeProperty('transform');
			part.style.removeProperty('opacity');
		});
		stage.classList.remove('is-scroll-driven');
	}

	function prepareScrollStage(stage) {
		if (reduce) { return; }
		clearStageTimer(stage);
		stage.classList.remove('is-exploded', 'is-assembled');
		stage.classList.add('is-scroll-driven');
	}

	function mix(from, to, amount) { return from + (to - from) * amount; }

	function ingredientRecipe(stage, name) {
		var recipes = {
			zaatar: { near: [-154, -96, 42, 10, 0, -10], scatter: [-248, -158, 250, 18, -12, -28] },
			cheese: { near: [158, -92, 62, 10, 0, 10], scatter: [252, -148, 300, 21, 14, 27] },
			meat: { near: [-146, 108, 48, 10, 0, -8], scatter: [-228, 186, 225, 16, -13, -24] },
			spinach: { near: [150, 106, 58, 10, 0, 9], scatter: [236, 180, 280, 19, 12, 25] }
		};
		var recipe = recipes[name];
		if (window.innerWidth <= 800) {
			var compact = {
				zaatar: { near: [-.26 * window.innerWidth, -.15 * window.innerWidth], scatter: [-.39 * window.innerWidth, -.24 * window.innerWidth] },
				cheese: { near: [.26 * window.innerWidth, -.15 * window.innerWidth], scatter: [.39 * window.innerWidth, -.24 * window.innerWidth] },
				meat: { near: [-.24 * window.innerWidth, .17 * window.innerWidth], scatter: [-.36 * window.innerWidth, .27 * window.innerWidth] },
				spinach: { near: [.24 * window.innerWidth, .17 * window.innerWidth], scatter: [.36 * window.innerWidth, .27 * window.innerWidth] }
			}[name];
			recipe.near[0] = compact.near[0]; recipe.near[1] = compact.near[1];
			recipe.scatter[0] = compact.scatter[0]; recipe.scatter[1] = compact.scatter[1];
		} else if (stage.classList.contains('ingredient-burst--bites')) {
			var bites = {
				zaatar: { near: [-154, -96], scatter: [-248, -158] },
				cheese: { near: [158, -92], scatter: [252, -148] },
				meat: { near: [-146, 108], scatter: [-228, 186] },
				spinach: { near: [150, 106], scatter: [236, 180] }
			}[name];
			recipe.near[0] = bites.near[0]; recipe.near[1] = bites.near[1];
			recipe.scatter[0] = bites.scatter[0]; recipe.scatter[1] = bites.scatter[1];
		}
		return recipe;
	}

	function paintScrollStage(stage, amount) {
		prepareScrollStage(stage);
		var central = stage.querySelector('.ingredient-burst__manoush');
		if (central) {
			central.style.transform = 'translate3d(-50%,calc(-50% + ' + mix(0, 14, amount).toFixed(2) + 'px),' + mix(16, -95, amount).toFixed(2) + 'px) rotateX(' + mix(14, 18, amount).toFixed(2) + 'deg) rotateY(' + mix(0, -8, amount).toFixed(2) + 'deg) rotateZ(' + mix(-3, 6, amount).toFixed(2) + 'deg) scale(' + mix(1, .86, amount).toFixed(3) + ')';
			central.style.opacity = mix(1, .7, amount).toFixed(3);
		}
		['zaatar', 'cheese', 'meat', 'spinach'].forEach(function (name) {
			var part = stage.querySelector('.ingredient--' + name);
			if (!part) { return; }
			var recipe = ingredientRecipe(stage, name);
			var values = recipe.near.map(function (value, index) { return mix(value, recipe.scatter[index], amount); });
			part.style.transform = 'translate3d(calc(-50% + ' + values[0].toFixed(2) + 'px),calc(-50% + ' + values[1].toFixed(2) + 'px),' + values[2].toFixed(2) + 'px) rotateX(' + values[3].toFixed(2) + 'deg) rotateY(' + values[4].toFixed(2) + 'deg) rotateZ(' + values[5].toFixed(2) + 'deg) scale(' + mix(1, 1.08, amount).toFixed(3) + ')';
			part.style.opacity = mix(.94, 1, amount).toFixed(3);
		});
		stage.style.setProperty('--db-scroll-energy', amount.toFixed(3));
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
			label.textContent = open ? 'Revesby online pickup open · three shops baking daily' : 'Revesby preorders welcome · three shops baking daily';
		} catch (error) {
			label.textContent = 'Revesby online pickup · three shops baking daily';
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
				/* The composition is assembled at the visual focal point and separates
				 * smoothly as it approaches either edge of the viewport. This is based
				 * on position, not scroll direction, so it works identically scrolling
				 * down into a scene and back up through it. */
				var stage = scene.querySelector('[data-manoush-stage]');
				if (stage && (!stage._dbManoushIntroUntil || Date.now() >= stage._dbManoushIntroUntil)) {
					paintScrollStage(stage, Math.min(1, Math.max(0, Math.abs(centre) - .035) * 3.45));
				}
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
		window.addEventListener('db:manoush-ready', requestRender);
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
