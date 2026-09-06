(function () {
	'use strict';
	var clock = document.querySelector('[data-db-portal-clock]');
	function paintClock() {
		if (!clock) { return; }
		clock.textContent = new Intl.DateTimeFormat('en-AU', { hour: 'numeric', minute: '2-digit' }).format(new Date());
	}
	paintClock();
	window.setInterval(paintClock, 30000);

	var fullscreen = document.querySelector('[data-db-fullscreen]');
	if (fullscreen && !document.documentElement.requestFullscreen) { fullscreen.hidden = true; }
	if (fullscreen) {
		fullscreen.addEventListener('click', function () {
			if (document.fullscreenElement && document.exitFullscreen) {
				document.exitFullscreen();
			} else if (document.documentElement.requestFullscreen) {
				document.documentElement.requestFullscreen();
			}
		});
		document.addEventListener('fullscreenchange', function () {
			fullscreen.textContent = document.fullscreenElement ? 'Exit full screen' : 'Full screen';
		});
	}
}());
