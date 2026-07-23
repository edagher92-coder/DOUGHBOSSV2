(function () {
	'use strict';
	var stages = Array.prototype.slice.call(document.querySelectorAll('[data-manoush-stage]'));
	if (!stages.length) { return; }
	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var explodeHoldMs = 1150;

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

	window.addEventListener('db:view', function (event) {
		playForView(event && event.detail ? event.detail : '');
	});
	playForView((window.location.hash || '#about').slice(1));
}());
