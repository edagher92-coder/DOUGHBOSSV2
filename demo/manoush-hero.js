(function () {
	'use strict';
	var stages = Array.prototype.slice.call(document.querySelectorAll('[data-manoush-stage]'));
	if (!stages.length) { return; }
	var motionQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
	var reduce = motionQuery ? motionQuery.matches : false;
	var motionOptedIn = false;
	var explodeHoldMs = 1500;
	var settleMs = 1900;
	try { motionOptedIn = window.sessionStorage.getItem('doughbossHeroMotion') === 'on'; } catch (ignore) {}
	// The signature build starts automatically and then follows scroll in both
	// directions. The on-page Pause control remains the visitor override.
	motionOptedIn = true;
	document.documentElement.classList.add('motion-ready');
	if (reduce && motionOptedIn) { document.documentElement.classList.add('db-demo-motion-opted-in'); }

	function motionAllowed() { return !reduce || motionOptedIn; }
	function stageCanRun(stage) { return motionAllowed() && !stage._dbManoushUserPaused; }

	function stageForButton(button) {
		var variant = button.getAttribute('data-manoush-replay');
		return stages.filter(function (item) { return item.getAttribute('data-manoush-variant') === variant; })[0];
	}

	function updateReplayButtons() {
		Array.prototype.slice.call(document.querySelectorAll('[data-manoush-replay]')).forEach(function (button) {
			var stage = stageForButton(button);
			var label;
			if (reduce && !motionOptedIn) { label = 'Start food animation'; }
			else if (stage && stage._dbManoushUserPaused) { label = 'Resume food animation'; }
			else { label = 'Pause food animation'; }
			var text = button.querySelector('[data-manoush-replay-text]');
			if (text) { text.textContent = label; }
			else { button.textContent = label; }
			button.setAttribute('aria-label', label);
		});
	}

	function enableMotion() {
		if (!reduce || motionOptedIn) { return; }
		motionOptedIn = true;
		try { window.sessionStorage.setItem('doughbossHeroMotion', 'on'); } catch (ignore) {}
		document.documentElement.classList.add('db-demo-motion-opted-in');
		updateReplayButtons();
		window.dispatchEvent(new Event('db:motion-opt-in'));
	}

	function stageIsVisible(stage) {
		if (document.hidden || (stage._dbManoushObserved && stage._dbManoushInView === false)) { return false; }
		var view = stage.closest('.view');
		if (view && !view.classList.contains('active')) { return false; }
		var rect = stage.getBoundingClientRect();
		return rect.bottom > 0 && rect.top < (window.innerHeight || document.documentElement.clientHeight || 800);
	}

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

	function stopStageCycle(stage) {
		stage._dbManoushCycle = (stage._dbManoushCycle || 0) + 1;
		clearStageTimer(stage);
		stage._dbManoushPlaying = false;
		stage._dbManoushIntroUntil = 0;
		releaseScrollStage(stage);
		stage.classList.remove('is-resetting', 'is-exploded');
		stage.classList.add('is-assembled');
	}

	function freezeStage(stage) {
		stage._dbManoushCycle = (stage._dbManoushCycle || 0) + 1;
		clearStageTimer(stage);
		stage._dbManoushPlaying = false;
		stage._dbManoushIntroUntil = 0;
		Array.prototype.slice.call(stage.querySelectorAll('.ingredient-burst__manoush,.ingredient,.ingredient-burst__stamp')).forEach(function (part) {
			var computed = window.getComputedStyle(part);
			part.style.transform = computed.transform;
			part.style.opacity = computed.opacity;
		});
		stage.classList.remove('is-resetting', 'is-exploded', 'is-assembled');
		stage.classList.add('is-scroll-driven');
	}

	function pauseStage(stage) {
		stage._dbManoushUserPaused = true;
		freezeStage(stage);
		stage.classList.add('is-user-paused');
		updateReplayButtons();
	}

	function playReady(stage, cycle) {
		if (cycle !== stage._dbManoushCycle) { return; }
		if (!stageCanRun(stage)) {
			stage.classList.remove('is-resetting');
			stage.classList.add('is-assembled');
			stage.classList.remove('is-exploded');
			return;
		}
		// Keep scroll motion from taking control until the food has completed its
		// entrance. Without this short reservation, the first scroll-scene paint
		// cancels the deliberate burst-and-assemble sequence on page load.
		stage._dbManoushIntroUntil = Date.now() + explodeHoldMs + settleMs;
		stage._dbManoushPlaying = true;
		stage._dbManoushHasPlayed = true;
		stage.classList.remove('is-exploded');
		stage.classList.remove('is-assembled');
		stage.classList.add('is-resetting');
		void stage.offsetWidth;
		window.requestAnimationFrame(function () {
			if (cycle !== stage._dbManoushCycle) { return; }
			window.requestAnimationFrame(function () {
				if (cycle !== stage._dbManoushCycle) { return; }
				stage.classList.remove('is-resetting');
				stage.classList.add('is-exploded');
				stage._dbManoushTimer = window.setTimeout(function () {
					if (cycle !== stage._dbManoushCycle) { return; }
					stage.classList.remove('is-exploded');
					stage.classList.add('is-assembled');
					stage._dbManoushTimer = 0;
					stage._dbManoushReleaseTimer = window.setTimeout(function () {
						if (cycle !== stage._dbManoushCycle) { return; }
						stage._dbManoushIntroUntil = 0;
						stage._dbManoushPlaying = false;
						stage._dbManoushReleaseTimer = 0;
						window.dispatchEvent(new Event('db:manoush-ready'));
					}, settleMs);
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
		stopStageCycle(stage);
		if (!stageCanRun(stage)) { return; }
		stage.classList.remove('is-user-paused');
		// Reserve the stage straight away; image decoding can be asynchronous
		// while the scroll renderer is already queued for its first frame.
		stage._dbManoushIntroUntil = Date.now() + explodeHoldMs + settleMs + 600;
		var cycle = stage._dbManoushCycle;
		imagesReady(stage, function () { playReady(stage, cycle); });
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
		stages.filter(function (stage) { return stage.getAttribute('data-manoush-variant') === variant; }).forEach(function (stage) {
			if (!stage._dbManoushHasPlayed) { play(stage); }
			else { window.dispatchEvent(new Event('db:manoush-ready')); }
		});
	}

	function releaseScrollStage(stage) {
		Array.prototype.slice.call(stage.querySelectorAll('.ingredient-burst__manoush,.ingredient,.ingredient-burst__stamp')).forEach(function (part) {
			part.style.removeProperty('transform');
			part.style.removeProperty('opacity');
		});
		stage.classList.remove('is-scroll-driven');
	}

	function prepareScrollStage(stage) {
		if (!stageCanRun(stage) || stage._dbManoushPlaying) { return; }
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
		if (!stageCanRun(stage) || stage._dbManoushPlaying) { return; }
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
				var stage = stageForButton(button);
				if (!stage) { return; }
				if (reduce && !motionOptedIn) {
					enableMotion();
					stage._dbManoushUserPaused = false;
					updateReplayButtons();
					play(stage);
				} else if (stage._dbManoushUserPaused) {
					stage._dbManoushUserPaused = false;
					stage.classList.remove('is-user-paused');
					updateReplayButtons();
					if (!stage._dbManoushHasPlayed) { play(stage); }
					else { window.dispatchEvent(new Event('db:manoush-ready')); }
				} else {
					pauseStage(stage);
				}
			});
		});
		updateReplayButtons();
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
		if (!scenes.length) { return; }
		var queued = false;
		function render() {
			if (!motionAllowed()) { queued = false; return; }
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
				if (stage && stageCanRun(stage) && !stage._dbManoushPlaying && (!stage._dbManoushIntroUntil || Date.now() >= stage._dbManoushIntroUntil)) {
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
		window.addEventListener('db:motion-opt-in', requestRender);
		if (motionAllowed()) { requestRender(); }
	}

	function wireStageVisibility() {
		if (!('IntersectionObserver' in window)) { return; }
		var observer = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				var stage = entry.target;
				stage._dbManoushInView = entry.isIntersecting;
				if (!entry.isIntersecting) {
					stopStageCycle(stage);
				} else if (stageCanRun(stage) && !stage._dbManoushPlaying) {
					if (!stage._dbManoushHasPlayed) { play(stage); }
					else { window.dispatchEvent(new Event('db:manoush-ready')); }
				}
			});
		}, { threshold: 0.22 });
		stages.forEach(function (stage) {
			stage._dbManoushObserved = true;
			stage._dbManoushInView = stageIsVisible(stage);
			observer.observe(stage);
		});
	}

	function motionPreferenceChanged(event) {
		reduce = event.matches;
		if (reduce && motionOptedIn) { document.documentElement.classList.add('db-demo-motion-opted-in'); }
		else if (!reduce || !motionOptedIn) { document.documentElement.classList.remove('db-demo-motion-opted-in'); }
		stages.forEach(function (stage) {
			if (!motionAllowed()) { stopStageCycle(stage); }
			else if (stageCanRun(stage) && stageIsVisible(stage) && !stage._dbManoushPlaying) {
				if (!stage._dbManoushHasPlayed) { play(stage); }
				else { window.dispatchEvent(new Event('db:manoush-ready')); }
			}
		});
		updateReplayButtons();
	}

	window.addEventListener('db:view', function (event) {
		stages.forEach(stopStageCycle);
		playForView(event && event.detail ? event.detail : '');
	});
	document.addEventListener('visibilitychange', function () {
		stages.forEach(function (stage) {
			if (document.hidden) { stopStageCycle(stage); }
			else if (stageCanRun(stage) && !stage._dbManoushPlaying && stageIsVisible(stage)) {
				if (!stage._dbManoushHasPlayed) { play(stage); }
				else { window.dispatchEvent(new Event('db:manoush-ready')); }
			}
		});
	});
	if (motionQuery) {
		if (motionQuery.addEventListener) { motionQuery.addEventListener('change', motionPreferenceChanged); }
		else if (motionQuery.addListener) { motionQuery.addListener(motionPreferenceChanged); }
	}
	wireReplay();
	updateStoreStatus();
	wireReveals();
	wireScrollScenes();
	wireStageVisibility();
	playForView((window.location.hash || '#about').slice(1));
}());
