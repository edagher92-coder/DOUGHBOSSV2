<?php
/** Homepage V6 presentation. Existing silent film, larger stage and preference-aware repeat playback. */
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

// Reconcile these two uploaded media URLs before activating the snippet.
$db_opening_v5_video_url  = 'https://doughboss.com.au/wp-content/uploads/doughboss-commercial-v5-hero-720.mp4';
$db_opening_v5_poster_url = 'https://doughboss.com.au/wp-content/uploads/doughboss-commercial-v5-poster.jpg';
$db_opening_v6_desktop_url = 'https://doughboss.com.au/wp-content/uploads/doughboss-commercial-v6-desktop-square.mp4';
$db_opening_v6_desktop_poster = 'https://doughboss.com.au/wp-content/uploads/doughboss-commercial-v6-desktop-square-poster.jpg';

add_filter( 'pre_do_shortcode_tag', static function ( $return, $tag, $attr ) use ( $db_opening_v5_video_url, $db_opening_v5_poster_url, $db_opening_v6_desktop_url, $db_opening_v6_desktop_poster ) {
	if ( false !== $return || 'doughboss_manoush_hero' !== $tag || ! is_array( $attr ) ||
		! isset( $attr['variant'] ) || 'home' !== $attr['variant'] || is_admin() || ! is_front_page() ) {
		return $return;
	}
	ob_start();
	?>
<section id="db-opening-home-v6" class="db-opening" data-db-opening-v6 aria-labelledby="db-opening-home-v6-title">
	<div class="db-opening-layout">
		<div class="db-opening-copy">
			<p class="db-mh-kicker">Dough Boss &middot; Lebanese bakery &middot; Sydney</p>
			<h2 id="db-opening-home-v6-title">From our oven.<br>To your table.</h2>
			<p class="db-opening-description">Warm manoush, pizza and mini bakes, made for sharing. Come in and see us at Revesby, Bankstown or Roselands.</p>
			<div class="db-opening-actions">
				<a class="db-mh-action db-mh-action--primary" href="<?php echo esc_url( home_url( '/order/' ) ); ?>">Browse menu</a>
				<a class="db-mh-action db-mh-action--secondary" href="tel:+61297742286">Call Revesby</a>
			</div>
		</div>
		<div class="db-opening-media">
			<div class="db-opening-frame">
				<picture><source media="(min-width: 1025px), (orientation: landscape)" srcset="<?php echo esc_url( $db_opening_v6_desktop_poster ); ?>"><img class="db-opening-poster" data-db-opening-poster src="<?php echo esc_url( $db_opening_v5_poster_url ); ?>" width="720" height="1280" alt="A stylised Dough Boss film of Lebanese flatbreads and a mini-bakes box." loading="eager" fetchpriority="high" decoding="async"></picture>
				<video id="db-opening-film" class="db-opening-video" data-db-opening-video data-db-opening-src="<?php echo esc_url( $db_opening_v5_video_url ); ?>" data-db-opening-desktop-src="<?php echo esc_url( $db_opening_v6_desktop_url ); ?>" width="720" height="1280" muted playsinline preload="none" aria-hidden="true" hidden></video>
			</div>
			<div class="db-opening-utility">
				<button type="button" data-db-opening-control aria-controls="db-opening-film" hidden>Play animation</button>
				<span data-db-opening-status role="status" aria-live="polite"></span>
			</div>
		</div>
	</div>
</section>
<style id="db-opening-home-v6-style">
#db-opening-home-v6{background:var(--dbf-char,#0a0807);border-radius:0;box-sizing:border-box;color:var(--dbf-paper,#f7f5f0);padding:clamp(16px,3vw,40px)}
#db-opening-home-v6 .db-opening-layout{align-items:center;display:grid;gap:clamp(24px,3vw,40px);grid-template-columns:minmax(0,.88fr) minmax(0,1.12fr);margin-inline:auto;max-width:1440px}
#db-opening-home-v6 .db-opening-media{grid-column:2;grid-row:1;min-width:0;position:relative;padding-top:56px}
#db-opening-home-v6 .db-opening-frame{aspect-ratio:1/1;background:#080705;margin-inline:auto;max-width:100%;overflow:hidden;position:relative;width:min(100%,680px)}
#db-opening-home-v6 .db-opening-poster,#db-opening-home-v6 .db-opening-video{display:block;height:100%;inset:0;object-fit:contain;position:absolute;width:100%}
#db-opening-home-v6 [hidden]{display:none!important}
#db-opening-home-v6 .db-opening-utility{align-items:center;color:var(--dbf-mist,#b8b1a4);display:flex;flex-wrap:wrap;font-size:14px;gap:8px 14px;justify-content:flex-end;line-height:1.45;position:absolute;top:0;left:0;right:0}
#db-opening-home-v6 .db-opening-utility button{background:#fff4df;border:1px solid #fff4df;border-radius:999px;color:#211a13;cursor:pointer;font:inherit;font-weight:600;min-height:44px;padding:9px 16px}
#db-opening-home-v6 button:focus-visible,#db-opening-home-v6 a:focus-visible{outline:3px solid var(--dbf-gold,#f1a132);outline-offset:4px}
#db-opening-home-v6 [data-db-opening-status]:not(:empty){flex-basis:100%;text-align:center}
#db-opening-home-v6 .db-opening-copy{grid-column:1;grid-row:1;min-width:0;align-self:start;padding-top:clamp(64px,7vw,110px)}
#db-opening-home-v6 .db-mh-kicker{color:var(--dbf-gold,#f1a132);font-size:.76rem;font-weight:900;letter-spacing:.2em;margin:0 0 16px;text-transform:uppercase}
#db-opening-home-v6 h2{color:inherit;font-family:var(--dbf-font-display,'Bebas Neue',Impact,sans-serif);font-size:clamp(54px,5.6vw,92px);font-weight:400;letter-spacing:-.015em;line-height:.95;margin:0;max-width:14ch;text-transform:uppercase}
#db-opening-home-v6 .db-opening-copy>p:not(.db-mh-kicker){color:#e7dfd2;font-size:clamp(16px,1.5vw,20px);line-height:1.6;margin:24px 0;max-width:42ch}
#db-opening-home-v6 .db-opening-actions{align-items:center;display:flex;flex-wrap:wrap;gap:12px}
#db-opening-home-v6 .db-mh-action{align-items:center;border:1px solid rgba(255,255,255,.76);border-radius:999px;box-sizing:border-box;display:inline-flex;font:inherit;font-size:.9rem;font-weight:850;justify-content:center;min-height:48px;padding:.75rem 1.3rem;text-decoration:none}
#db-opening-home-v6 .db-mh-action--primary{background:var(--dbf-ember,#e2231a);border-color:var(--dbf-ember,#e2231a);color:#fff}
#db-opening-home-v6 .db-mh-action--secondary{background:rgba(9,9,8,.28);color:#fff}


@media(max-width:1024px){
 #db-opening-home-v6{padding:12px 16px 28px}
 #db-opening-home-v6 .db-opening-layout{gap:16px;grid-template-columns:minmax(0,1fr)}
 #db-opening-home-v6 .db-opening-copy{display:contents}
 #db-opening-home-v6 .db-mh-kicker{grid-row:1;font-size:11px;margin:0}
 #db-opening-home-v6 h2{grid-row:2;font-size:clamp(44px,9vw,72px);max-width:18ch}
 #db-opening-home-v6 .db-opening-actions{grid-row:3}
 #db-opening-home-v6 .db-opening-media{grid-column:1;grid-row:4}
 #db-opening-home-v6 .db-opening-frame{width:min(100%,520px)}
 #db-opening-home-v6 .db-opening-description{grid-row:5;font-size:16px;line-height:1.5;margin:0}
}
@media(max-width:1024px) and (orientation:portrait){#db-opening-home-v6 .db-opening-frame{aspect-ratio:9/16}}

/* One arrival sequence; repeating movement belongs to the food, not its rectangle. */
#db-opening-home-v6 .db-opening-frame{border-radius:0;box-shadow:none;isolation:isolate}
#db-opening-home-v6 .db-opening-utility button{transition:transform .2s ease,background .2s ease}
#db-opening-home-v6 .db-mh-action{transition:transform .25s cubic-bezier(.2,.8,.3,1.2),box-shadow .25s ease}
@media(prefers-reduced-motion:no-preference){
 #db-opening-home-v6 .db-opening-copy>*{animation:db-opening-arrive .5s cubic-bezier(.2,.8,.3,1) both}
 #db-opening-home-v6 .db-opening-copy>h2{animation-delay:.08s}
 #db-opening-home-v6 .db-opening-copy>p:not(.db-mh-kicker){animation-delay:.16s}
 #db-opening-home-v6 .db-opening-actions{animation-delay:.24s}
 #db-opening-home-v6 .db-mh-action:hover,#db-opening-home-v6 .db-mh-action:focus-visible{transform:translateY(-2px);box-shadow:0 8px 26px rgba(216,31,22,.18)}
 #db-opening-home-v6 .db-mh-action:active,#db-opening-home-v6 .db-opening-utility button:active{transform:scale(.97)}
}
@keyframes db-opening-arrive{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@media(prefers-reduced-motion:reduce){#db-opening-home-v6 *,#db-opening-home-v6 *::before,#db-opening-home-v6 *::after{animation:none!important;transition:none!important}}
</style>
<script id="db-opening-home-v6-controller">

(function () {
 'use strict';
 var opening = document.getElementById('db-opening-home-v6');
 if (!opening || opening._dbOpeningV6Wired) { return; }
 var video = opening.querySelector('[data-db-opening-video]');
 var poster = opening.querySelector('[data-db-opening-poster]');
 var control = opening.querySelector('[data-db-opening-control]');
 var status = opening.querySelector('[data-db-opening-status]');
 if (!video || !poster || !control || !video.play || !video.pause) { return; }
 opening._dbOpeningV6Wired = true;
 var source = video.getAttribute('data-db-opening-src');
 var desktop = window.matchMedia ? window.matchMedia('(min-width: 1025px), (orientation: landscape)') : null;
 var motion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
 var connection = window.navigator && window.navigator.connection;
 var inView = false, userPaused = false, wanted = false, playing = false, failed = false, ended = false, attempt = 0;
 function restricted() { return !!((motion && motion.matches) || (connection && connection.saveData)); }
 function label() {
  var text = wanted ? 'Pause animation' : (ended ? 'Replay animation' : 'Play animation');
  control.textContent = text;
  control.setAttribute('aria-label', text);
  control.setAttribute('aria-pressed', wanted ? 'true' : 'false');
  opening.classList.toggle('is-playing', playing && wanted);
 }
 function stop(showPoster) {
  wanted = false; playing = false; attempt += 1; video.pause();
  if (showPoster) { video.hidden = true; poster.hidden = false; }
  label();
 }
 function start(explicit) {
  if (failed || document.hidden || (!explicit && (!inView || userPaused || restricted()))) { return; }
  wanted = true;
  if (video.ended || ended) { video.currentTime = 0; }
  ended = false;
  var currentAttempt = ++attempt;
  video.muted = true;
  // Repeat only when motion/data preferences allow; explicit reduced-motion play is once.
  video.loop = !restricted();
  var selectedSource = desktop && desktop.matches ? video.getAttribute('data-db-opening-desktop-src') : source;
  if (video.getAttribute('src') !== selectedSource) { video.setAttribute('src', selectedSource); }
  if (status) { status.textContent = ''; }
  label();
  function rejected() {
   if (currentAttempt !== attempt || failed) { return; }
   userPaused = true; stop(true);
   if (status) { status.textContent = 'Select Play animation to watch the film.'; }
  }
  try {
   var promise = video.play();
   if (promise && typeof promise.then === 'function') {
    promise.then(function () { if (!wanted || document.hidden) { video.pause(); } }, rejected);
   }
  } catch (error) { rejected(); }
 }
 function syncVisibility() {
  if (!inView || document.hidden) { stop(false); }
  else if (!wanted) { start(false); }
 }
 video.addEventListener('playing', function () {
  if (!wanted || failed || document.hidden) { video.pause(); return; }
  playing = true; video.hidden = false; poster.hidden = true; label();
 });
 video.addEventListener('waiting', function () { playing = false; label(); });
 video.addEventListener('ended', function () { ended = true; stop(false); });
 video.addEventListener('error', function () {
  failed = true; stop(true); control.hidden = true;
  if (status) { status.textContent = 'Film unavailable. Our bakery image is shown instead.'; }
 });
 control.addEventListener('click', function () {
  if (wanted) { userPaused = true; stop(false); }
  else { userPaused = false; start(true); }
 });
 document.addEventListener('visibilitychange', syncVisibility);
 function preferenceChanged() {
  if (restricted()) { video.loop = false; userPaused = true; stop(true); }
 }
 if (motion && motion.addEventListener) { motion.addEventListener('change', preferenceChanged); }
 else if (motion && motion.addListener) { motion.addListener(preferenceChanged); }
 if (connection && connection.addEventListener) { connection.addEventListener('change', preferenceChanged); }
 function breakpointChanged() { stop(true); start(false); }
 if (desktop && desktop.addEventListener) { desktop.addEventListener('change', breakpointChanged); }
 else if (desktop && desktop.addListener) { desktop.addListener(breakpointChanged); }
 control.hidden = false; label();
 function visibleFallback() {
  var rect = opening.querySelector('.db-opening-frame').getBoundingClientRect();
  inView = rect.bottom > 0 && rect.right > 0 && rect.top < window.innerHeight && rect.left < window.innerWidth;
  syncVisibility();
 }
 if (typeof window.IntersectionObserver === 'function') {
  var observer = new window.IntersectionObserver(function (entries) {
   for (var i = 0; i < entries.length; i += 1) {
    inView = entries[i].isIntersecting && entries[i].intersectionRatio >= .15;
    syncVisibility();
   }
  }, { threshold: .15 });
  observer.observe(opening.querySelector('.db-opening-frame'));
 } else {
  window.addEventListener('scroll', visibleFallback, { passive: true });
  window.addEventListener('resize', visibleFallback); visibleFallback();
 }
}());
</script>
	<?php
	return ob_get_clean();
}, 20, 3 );
