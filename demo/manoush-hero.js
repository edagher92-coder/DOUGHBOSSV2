(function () {
	'use strict';
	var stages = Array.prototype.slice.call(document.querySelectorAll('[data-manoush-stage]'));
	if (!stages.length) { return; }
	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function play(stage) {
		if (reduce) {
			stage.classList.add('is-assembled');
			stage.classList.remove('is-exploded');
			return;
		}
		stage.classList.add('is-exploded');
		stage.classList.remove('is-assembled');
		window.setTimeout(function () {
			stage.classList.add('is-assembled');
		}, 150);
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
