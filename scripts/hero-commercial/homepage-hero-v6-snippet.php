<?php
/** Homepage presentation. Muted-first film once, optional quiet audio, ambient steam. */
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

// Owner-approved quiet master; both viewports use the exact same complete cut.
$db_opening_v5_video_url  = 'https://doughboss.com.au/wp-content/uploads/doughboss-commercial-v3-web-loop.mp4';
$db_opening_v5_poster_url = 'https://doughboss.com.au/wp-content/uploads/doughboss-commercial-v5-poster.jpg';
$db_opening_v6_desktop_url = $db_opening_v5_video_url;
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
			<div class="db-opening-utility">
				<button type="button" data-db-opening-control aria-controls="db-opening-film" hidden>Play film</button>
				<button type="button" data-db-sound-control aria-controls="db-opening-film" aria-label="Unmute film" hidden>Unmute</button>
				<button type="button" data-db-steam-control aria-controls="db-opening-steam" hidden>Pause steam</button>
				<button type="button" data-db-motion-control aria-controls="db-opening-film db-opening-steam" hidden>Enable motion</button>
				<span data-db-opening-status role="status" aria-live="polite"></span>
			</div>
			<div class="db-opening-frame">
				<picture><source media="(min-width: 1025px), (orientation: landscape)" srcset="<?php echo esc_url( $db_opening_v6_desktop_poster ); ?>"><img class="db-opening-poster" data-db-opening-poster src="<?php echo esc_url( $db_opening_v5_poster_url ); ?>" width="720" height="1280" alt="A stylised Dough Boss film of Lebanese flatbreads and a mini-bakes box." loading="eager" fetchpriority="high" decoding="async"></picture>
				<video id="db-opening-film" class="db-opening-video" data-db-opening-video data-db-opening-src="<?php echo esc_url( $db_opening_v5_video_url ); ?>" data-db-opening-desktop-src="<?php echo esc_url( $db_opening_v6_desktop_url ); ?>" width="720" height="1280" muted playsinline preload="none" aria-hidden="true" hidden></video>
				<div class="db-opening-steam" id="db-opening-steam" aria-hidden="true"><span></span><span></span><span></span></div>
			</div>
		</div>
	</div>
<div class="db-opening-ribbon" aria-hidden="true">
	<div class="db-opening-ribbon-track"><span>Fresh dough.</span><span class="db-opening-ribbon-star">✳</span><span>Big love.</span><span class="db-opening-ribbon-star">✳</span><span>Made to share.</span></div>
</div>
</section>
<style id="db-opening-home-v6-style">
#db-opening-home-v6{background:var(--dbf-char,#0a0807);border-radius:0;box-sizing:border-box;color:var(--dbf-paper,#f7f5f0);padding:clamp(16px,3vw,40px)}
#db-opening-home-v6 .db-opening-layout{align-items:center;display:grid;gap:clamp(24px,3vw,64px);grid-template-columns:minmax(0,1fr) minmax(0,1fr);margin-inline:auto;max-width:1840px}
#db-opening-home-v6 .db-opening-media{grid-column:2;grid-row:1;min-width:0;position:relative}
#db-opening-home-v6 .db-opening-frame{aspect-ratio:9/16;background:#080705;margin-inline:auto;max-width:100%;overflow:hidden;position:relative;width:min(100%,480px)}
#db-opening-home-v6 .db-opening-poster,#db-opening-home-v6 .db-opening-video{display:block;height:100%;inset:0;object-fit:contain;position:absolute;width:100%}
#db-opening-home-v6 [hidden]{display:none!important}
#db-opening-home-v6 .db-opening-utility{align-items:center;color:var(--dbf-mist,#b8b1a4);display:flex;flex-wrap:wrap;font-size:16px;gap:8px 14px;justify-content:flex-end;line-height:1.45;margin-bottom:12px;min-height:44px}
#db-opening-home-v6 .db-opening-utility button{background:#fff4df;border:1px solid #fff4df;border-radius:999px;color:#211a13;cursor:pointer;font:inherit;font-weight:600;min-height:44px;padding:9px 16px}
#db-opening-home-v6 button:focus-visible,#db-opening-home-v6 a:focus-visible{outline:3px solid var(--dbf-gold,#f1a132);outline-offset:4px}
#db-opening-home-v6 [data-db-opening-status]:not(:empty){flex-basis:100%;text-align:center}
#db-opening-home-v6 .db-opening-copy{grid-column:1;grid-row:1;min-width:0;align-self:start;padding-top:clamp(64px,7vw,110px)}
#db-opening-home-v6 .db-mh-kicker{color:var(--dbf-gold,#f1a132);font-size:14px;font-weight:900;letter-spacing:.2em;margin:0 0 16px;text-transform:uppercase}
#db-opening-home-v6 h2{color:inherit;font-family:var(--dbf-font-display,'Bebas Neue',Impact,sans-serif);font-size:clamp(64px,6.2vw,132px);font-weight:400;letter-spacing:-.015em;line-height:.95;margin:0;max-width:14ch;text-transform:uppercase}
#db-opening-home-v6 .db-opening-copy>p:not(.db-mh-kicker){color:#e7dfd2;font-size:clamp(20px,1.3vw,26px);line-height:1.6;margin:24px 0;max-width:42ch}
#db-opening-home-v6 .db-opening-actions{align-items:center;display:flex;flex-wrap:wrap;gap:12px}
#db-opening-home-v6 .db-mh-action{align-items:center;border:1px solid rgba(255,255,255,.76);border-radius:999px;box-sizing:border-box;display:inline-flex;font:inherit;font-size:16px;font-weight:700;justify-content:center;min-height:48px;padding:.75rem 1.3rem;text-decoration:none}
#db-opening-home-v6 .db-mh-action--primary{background:var(--dbf-ember,#e2231a);border-color:var(--dbf-ember,#e2231a);color:#fff}
#db-opening-home-v6 .db-mh-action--secondary{background:rgba(9,9,8,.28);color:#fff}


@media(max-width:1024px){
 #db-opening-home-v6{padding:12px 16px 28px}
 #db-opening-home-v6 .db-opening-layout{gap:16px;grid-template-columns:minmax(0,1fr)}
 #db-opening-home-v6 .db-opening-copy{display:contents}
 #db-opening-home-v6 .db-mh-kicker{grid-row:1;font-size:14px;margin:0}
 #db-opening-home-v6 h2{grid-row:2;font-size:clamp(44px,9vw,72px);max-width:18ch}
 #db-opening-home-v6 .db-opening-actions{grid-row:3}
 #db-opening-home-v6 .db-opening-media{grid-column:1;grid-row:4}
 #db-opening-home-v6 .db-opening-frame{width:min(100%,520px)}
 #db-opening-home-v6 .db-opening-description{grid-row:5;font-size:17px;line-height:1.5;margin:0}
}
@media(max-width:1024px) and (orientation:portrait){#db-opening-home-v6 .db-opening-frame{aspect-ratio:9/16}}

/* One arrival sequence; repeating movement belongs to the food, not its rectangle. */
#db-opening-home-v6 .db-opening-frame{border-radius:0;box-shadow:none;isolation:isolate}
#db-opening-home-v6 .db-opening-steam{inset:0;pointer-events:none;position:absolute;z-index:1}
#db-opening-home-v6 .db-opening-steam span{position:absolute;left:32%;top:43%;width:9%;height:25%;border-radius:50%;background:radial-gradient(ellipse,rgba(255,247,230,.32),rgba(255,247,230,0) 70%);opacity:0}
#db-opening-home-v6 .db-opening-steam span:nth-child(2){left:47%;top:48%}
#db-opening-home-v6 .db-opening-steam span:nth-child(3){left:62%;top:44%}
@media(prefers-reduced-motion:no-preference){
  #db-opening-home-v6 .db-opening-steam span{animation:db-opening-steam-rise 5.6s ease-in-out infinite;animation-play-state:paused}
  #db-opening-home-v6 .db-opening-steam span:nth-child(2){animation-delay:-1.9s}
  #db-opening-home-v6 .db-opening-steam span:nth-child(3){animation-delay:-3.7s}
  #db-opening-home-v6.has-steam .db-opening-steam span{animation-play-state:running}
  #db-opening-home-v6:not(.has-steam) .db-opening-steam span{visibility:hidden}
}
@keyframes db-opening-steam-rise{0%{opacity:0;transform:translate3d(0,4%,0) scale(.7,1)}35%{opacity:.32}70%{opacity:.2}100%{opacity:0;transform:translate3d(12%,-95%,0) scale(1.5,1.3)}}
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
/* A visitor can explicitly opt into this film and its steam without changing
   the operating-system preference or enabling every animation on the site. */
#db-opening-home-v6.is-motion-enabled .db-opening-steam span{animation:db-opening-steam-rise 5.6s ease-in-out infinite!important;animation-play-state:paused!important}
#db-opening-home-v6.is-motion-enabled .db-opening-steam span:nth-child(2){animation-delay:-1.9s!important}
#db-opening-home-v6.is-motion-enabled .db-opening-steam span:nth-child(3){animation-delay:-3.7s!important}
#db-opening-home-v6.is-motion-enabled.has-steam .db-opening-steam span{animation-play-state:running!important}
#db-opening-home-v6:not(.has-steam) .db-opening-steam span{visibility:hidden}
/* Jeton-inspired editorial rhythm, independently implemented. The existing
   theme observer owns reveals; no second scroll loop or animation dependency. */
#db-opening-home-v6{position:relative;isolation:isolate;background:radial-gradient(ellipse at 0 30%,rgba(226,35,26,.13),transparent 55%),var(--dbf-char,#0a0807)}
#db-opening-home-v6 .db-opening-copy{position:relative;z-index:1}
#db-opening-home-v6 .db-mh-action{transition:transform .36s cubic-bezier(.22,1.4,.36,1),box-shadow .3s ease,background-color .3s ease}
/* Clip decoration without creating a local scroll container for view() timelines. */
#db-opening-home-v6 .db-opening-ribbon{border-block:1px solid rgba(241,161,50,.3);color:var(--dbf-gold,#f1a132);margin-top:clamp(32px,5vw,72px);overflow:hidden;overflow:clip;padding-block:clamp(14px,2.4vw,30px)}
#db-opening-home-v6 .db-opening-ribbon-track{align-items:center;display:flex;gap:clamp(18px,3vw,48px);justify-content:center;white-space:nowrap;font-family:var(--dbf-font-display,'Bebas Neue',Impact,sans-serif);font-size:clamp(30px,5.8vw,86px);line-height:1;text-transform:uppercase}
#db-opening-home-v6 .db-opening-ribbon-star{font-family:Arial,sans-serif;font-size:.65em;color:var(--dbf-ember,#e2231a)}
body.home .dbf-section{position:relative}
body.home .dbf-section-head .dbf-heading{font-size:clamp(46px,6.8vw,94px);line-height:.98;max-width:17ch}
body.home .dbf-section--cream{background:#f6eddc}
body.home .dbf-food-card{border-radius:24px;isolation:isolate}
body.home .dbf-food-card img{transition:transform .7s cubic-bezier(.2,.8,.2,1)}
body.home .dbf-food-card-copy{transition:transform .45s cubic-bezier(.22,1.3,.36,1)}
body.home .dbf-food-card-copy strong{font-size:clamp(38px,4vw,60px)}
.dbf-js.dbf-motion-ok body.home .dbf-card[data-dbf-reveal]{transition-property:transform,opacity,box-shadow,border-color;transition-duration:.45s,.7s,.35s,.35s;transition-timing-function:cubic-bezier(.22,1.3,.36,1),ease,ease,ease}
body.home .dbf-card-number{transition:transform .45s cubic-bezier(.22,1.3,.36,1),background-color .3s ease,color .3s ease}
body.home .dbf-button{transition:transform .35s cubic-bezier(.22,1.4,.36,1),box-shadow .3s ease}
@media(prefers-reduced-motion:no-preference){
  /* Content stays in normal document flow. Keyboard focus reveals immediately. */
  .dbf-js.dbf-motion-ok body.home [data-dbf-reveal]{transition-duration:.7s;transition-timing-function:cubic-bezier(.16,1,.3,1)}
  .dbf-js.dbf-motion-ok body.home [data-dbf-reveal]:not(.is-visible):not(:focus):not(:focus-within){transform:translateY(36px)}
  body.home .dbf-food-card:focus-visible img{transform:scale(1.08) rotate(-1deg)}
  body.home .dbf-food-card:focus-visible .dbf-food-card-copy{transform:translateY(-8px)}
  body.home .dbf-card:focus-within .dbf-card-number{transform:rotate(-10deg);background:var(--dbf-ember,#e2231a);color:#fff}
  body.home .dbf-button:focus-visible{transform:translateY(-3px)}
  @media(hover:hover) and (pointer:fine){
    body.home .dbf-food-card:hover img{transform:scale(1.08) rotate(-1deg)}
    body.home .dbf-food-card:hover .dbf-food-card-copy{transform:translateY(-8px)}
    .dbf-js.dbf-motion-ok body.home .dbf-card.is-visible:hover{transform:translateY(-8px);box-shadow:0 20px 40px rgba(36,20,10,.14);transition-delay:0s}
    body.home .dbf-card:hover .dbf-card-number{transform:rotate(-10deg);background:var(--dbf-ember,#e2231a);color:#fff}
    body.home .dbf-button:hover{transform:translateY(-3px);box-shadow:0 8px 20px rgba(36,20,10,.15)}
  }
  body.home .dbf-button:active,#db-opening-home-v6 .db-mh-action:active{transform:scale(.96)}
  /* Native view timelines are optional: unsupported browsers keep a static
     composition. Motion follows the user's scroll; nothing scrolls for them. */
  @supports(animation-timeline:view()){
    #db-opening-home-v6 .db-opening-ribbon-track{animation:db-opening-ribbon-travel linear both;animation-timeline:view();animation-range:entry 0% exit 100%}
    #db-opening-home-v6 .db-opening-ribbon-star{animation:db-opening-star-turn linear both;animation-timeline:view();animation-range:entry 0% exit 100%}
    body.home .dbf-story-media img{animation:db-opening-photo-settle linear both;animation-timeline:view();animation-range:entry 0% entry 100%}
  }
}
@keyframes db-opening-ribbon-travel{from{transform:translateX(4%)}to{transform:translateX(-4%)}}
@keyframes db-opening-star-turn{from{transform:rotate(-30deg)}to{transform:rotate(60deg)}}
@keyframes db-opening-photo-settle{from{transform:scale(.94) rotate(-2deg)}to{transform:scale(1) rotate(0)}}
@media(max-width:600px){
  #db-opening-home-v6 .db-opening-ribbon-track{flex-wrap:wrap;gap:10px 16px;white-space:normal;font-size:32px}
  #db-opening-home-v6 .db-opening-ribbon-track>span:last-child{flex-basis:100%;text-align:center;font-size:.65em;letter-spacing:.08em}
  body.home .dbf-section-head .dbf-heading{font-size:clamp(42px,11vw,60px)}
}
@media(prefers-reduced-motion:reduce){
  body.home .dbf-card,body.home .dbf-card-number,body.home .dbf-food-card img,body.home .dbf-food-card-copy,body.home .dbf-button{transition:none!important;transform:none!important}
}
/* Individual rotate composes with the theme's reveal transforms. */
@media(prefers-reduced-motion:no-preference) and (hover:hover) and (pointer:fine){
  body.home .dbf-card-grid,body.home .dbf-food-grid,body.home .dbf-story-grid,#db-opening-home-v6 .db-opening-media{perspective:1100px}
  body.home [data-db-tilt]{rotate:var(--db-tilt-x,0) var(--db-tilt-y,1) 0 var(--db-tilt-angle,0deg);transition-property:transform,opacity,box-shadow,border-color,rotate;transition-duration:.45s,.7s,.35s,.35s,.35s;transition-timing-function:ease;transition-delay:0s}
}
@media(prefers-reduced-motion:reduce){body.home [data-db-tilt]{rotate:none!important}}
</style>
<script id="db-opening-home-v6-tilt">
(function () {
  'use strict';
  var opening = document.getElementById('db-opening-home-v6');
  if (!opening || opening._dbTiltWired || !window.matchMedia || !window.requestAnimationFrame) { return; }
  opening._dbTiltWired = true;
  var allowed = window.matchMedia('(prefers-reduced-motion: no-preference) and (hover: hover) and (pointer: fine)');
  var resets = [];
  function resetAll() { resets.forEach(function (reset) { reset(); }); }
  function init() {
    document.querySelectorAll('body.home .db-opening-frame,body.home .dbf-story-media,body.home .dbf-food-card,body.home .dbf-card').forEach(function (panel) {
      var rect = null, pending = 0, x = 0, y = 0;
      panel.setAttribute('data-db-tilt', '');
      function reset() {
        if (pending) { window.cancelAnimationFrame(pending); pending = 0; }
        rect = null;
        panel.style.removeProperty('--db-tilt-x');
        panel.style.removeProperty('--db-tilt-y');
        panel.style.removeProperty('--db-tilt-angle');
      }
      function move(event) {
        if (!allowed.matches || document.hidden || event.pointerType !== 'mouse' || event.buttons) { reset(); return; }
        if (!rect) { rect = panel.getBoundingClientRect(); }
        if (!rect.width || !rect.height) { reset(); return; }
        x = Math.max(-1, Math.min(1, (event.clientX - rect.left) / rect.width * 2 - 1));
        y = Math.max(-1, Math.min(1, (event.clientY - rect.top) / rect.height * 2 - 1));
        if (!pending) {
          pending = window.requestAnimationFrame(function () {
            pending = 0;
            panel.style.setProperty('--db-tilt-x', String(-y));
            panel.style.setProperty('--db-tilt-y', String(x || .0001));
            panel.style.setProperty('--db-tilt-angle', (Math.min(1, Math.sqrt(x*x + y*y)) * 1.6).toFixed(3) + 'deg');
          });
        }
      }
      panel.addEventListener('pointermove', move, { passive: true });
      panel.addEventListener('pointerleave', reset);
      panel.addEventListener('pointercancel', reset);
      panel.addEventListener('pointerdown', reset);
      panel.addEventListener('focusin', reset);
      resets.push(reset);
    });
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init, { once: true }); } else { init(); }
  window.addEventListener('blur', resetAll);
  window.addEventListener('resize', resetAll, { passive: true });
  window.addEventListener('scroll', resetAll, { passive: true });
  document.addEventListener('visibilitychange', resetAll);
  if (allowed.addEventListener) { allowed.addEventListener('change', resetAll); } else if (allowed.addListener) { allowed.addListener(resetAll); }
}());
</script>
<script id="db-opening-home-v6-controller">

(function () {
 'use strict';
 var opening = document.getElementById('db-opening-home-v6');
 if (!opening || opening._dbOpeningV6Wired) { return; }
 var video = opening.querySelector('[data-db-opening-video]');
 var poster = opening.querySelector('[data-db-opening-poster]');
 var control = opening.querySelector('[data-db-opening-control]');
 var status = opening.querySelector('[data-db-opening-status]');
 var steamControl = opening.querySelector('[data-db-steam-control]');
 var soundControl = opening.querySelector('[data-db-sound-control]');
 var motionControl = opening.querySelector('[data-db-motion-control]');
 if (!video || !poster || !control || !video.play || !video.pause) { return; }
 opening._dbOpeningV6Wired = true;
 var source = video.getAttribute('data-db-opening-src');
 var desktop = window.matchMedia ? window.matchMedia('(min-width: 1025px), (orientation: landscape)') : null;
 var motion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
 var connection = window.navigator && window.navigator.connection;
 var inView = false, userPaused = false, wanted = false, playing = false, failed = false, attempt = 0;
 var steamPaused = false;
 var soundMuted = true;
 var motionChoice = null;
 function mayRememberMotion() { return !!(window.DoughBossPreferences && typeof window.DoughBossPreferences.allowed === 'function' && window.DoughBossPreferences.allowed()); }
 try { if (mayRememberMotion()) { motionChoice = window.localStorage.getItem('db-hero-motion'); } } catch (ignoreStorage) {}
 video.muted = soundMuted;
 function restricted() { return !!(motionChoice === 'off' || ((motion && motion.matches) && motionChoice !== 'on') || (connection && connection.saveData)); }
 function label() {
  var text = wanted ? 'Pause film' : 'Play film';
  control.textContent = text;
  control.setAttribute('aria-label', text);
  control.setAttribute('aria-pressed', wanted ? 'true' : 'false');
  opening.classList.toggle('is-playing', playing && wanted);
  opening.classList.toggle('is-motion-enabled', motionChoice === 'on' && !restricted());
  if (motionControl) {
   motionControl.hidden = false;
   motionControl.textContent = restricted() ? 'Enable motion' : 'Reduce motion';
   motionControl.setAttribute('aria-pressed', restricted() ? 'false' : 'true');
  }
  if (soundControl) {
   soundControl.hidden = failed;
   soundControl.textContent = soundMuted ? 'Unmute' : 'Mute';
   soundControl.setAttribute('aria-label', soundMuted ? 'Unmute film' : 'Mute film');
  }
  var steamAvailable = inView && !document.hidden && !restricted() && !playing && (video.hidden || video.currentTime >= 5);
  opening.classList.toggle('has-steam', steamAvailable && !steamPaused);
  if (steamControl) {
   steamControl.hidden = !steamAvailable;
   steamControl.textContent = steamPaused ? 'Play steam' : 'Pause steam';
   steamControl.setAttribute('aria-pressed', steamPaused ? 'false' : 'true');
  }
 }
 function stop(showPoster) {
  wanted = false; playing = false; attempt += 1; video.pause();
  if (showPoster) { video.hidden = true; poster.hidden = false; }
  label();
 }
 function start(explicit) {
  if (failed || !inView || document.hidden || (!explicit && (userPaused || restricted()))) { return; }
  wanted = true;
  if (video.ended) { video.currentTime = 0; }
  var currentAttempt = ++attempt;
  video.muted = soundMuted;
  // Native playback repeats the full film; restricted visitors retain one-shot explicit play.
  video.loop = !restricted();
  var selectedSource = desktop && desktop.matches ? video.getAttribute('data-db-opening-desktop-src') : source;
  if (video.getAttribute('src') !== selectedSource) { video.setAttribute('src', selectedSource); }
  if (status) { status.textContent = ''; }
  label();
  function rejected() {
   if (currentAttempt !== attempt || failed) { return; }
   userPaused = true; stop(true);
   if (status) { status.textContent = 'Select Play film to watch the film.'; }
  }
  try {
   var promise = video.play();
   if (promise && typeof promise.then === 'function') {
    promise.then(function () { if (!wanted || document.hidden || !inView) { video.pause(); } }, rejected);
   }
  } catch (error) { rejected(); }
 }
 function syncVisibility() {
  if (!inView || document.hidden) { stop(false); }
  else if (!wanted) { start(false); }
  label();
 }
 video.addEventListener('playing', function () {
  if (!wanted || failed || document.hidden || !inView) { video.pause(); return; }
  playing = true; video.hidden = false; poster.hidden = true; label();
 });
 video.addEventListener('waiting', function () { playing = false; label(); });
 video.addEventListener('ended', function () { if (!video.loop) { stop(false); } });
 video.addEventListener('error', function () {
  failed = true; stop(true); control.hidden = true;
  if (status) { status.textContent = 'Film unavailable. Our bakery image is shown instead.'; }
 });
 control.addEventListener('click', function () {
  if (wanted) { userPaused = true; stop(true); }
  else { userPaused = false; start(true); }
 });
 if (steamControl) { steamControl.addEventListener('click', function () { steamPaused = !steamPaused; label(); }); }
 if (motionControl) { motionControl.addEventListener('click', function () {
  motionChoice = restricted() ? 'on' : 'off';
 try { if (mayRememberMotion()) { window.localStorage.setItem('db-hero-motion', motionChoice); } } catch (ignoreStorage) {}
  if (motionChoice === 'off') { userPaused = true; stop(false); }
  else {
   userPaused = false; steamPaused = false;
   start(true);
   if (connection && connection.saveData && status) { status.textContent = 'Data saver is on. Automatic playback remains off.'; }
  }
  label();
 }); }
 if (soundControl) { soundControl.addEventListener('click', function () {
  if (failed) { return; }
  soundMuted = !soundMuted;
  video.muted = soundMuted;
  label();
 }); }
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

// One rollbackable presentation release. No commerce state or theme files are
// replaced; these hooks run only with the existing DoughBoss presentation theme.
add_action( 'wp_head', static function () {
	if ( is_admin() || ! function_exists( 'doughboss_final_ordering_open' ) ) { return; }
	?>
<style id="db-front-repair-style">
:root{--dbf-wrap:1840px;font-size:clamp(16px,calc(12px + .4vw),22px)}
body{font-size:clamp(18px,calc(14px + .35vw),23px)}
.dbf-hero .dbf-wrap{width:min(1920px,calc(100% - 32px))}
.db-app{font-size:1rem}
/* The nearly opaque sticky header was blurring the entire scrolling page
   beneath it. A solid identical-colour surface avoids that repeated GPU pass. */
.dbf-header::before{background:var(--dbf-coal,#151210);backdrop-filter:none;-webkit-backdrop-filter:none}
.db-about-hero{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,550px);align-items:center;gap:clamp(24px,4vw,70px);padding:clamp(32px,5vw,80px) max(20px,calc((100% - 1840px)/2));min-height:0}
.db-about-hero::after{display:none}
.db-about-hero>.db-responsive-picture{grid-column:2;grid-row:1;display:block}
.db-about-hero .dbf-page-hero-bg{position:static;width:100%;height:auto;inset:auto;aspect-ratio:5/4;object-fit:contain;filter:none;border-radius:20px}
.db-about-hero .dbf-page-hero-inner{grid-column:1;grid-row:1;width:100%;min-height:0;padding:0}
.db-about-hero .dbf-display{font-size:clamp(44px,5vw,100px)}
@media(max-width:720px){.db-about-hero{grid-template-columns:minmax(0,1fr)}.db-about-hero>.db-responsive-picture{grid-column:1;grid-row:2;max-width:550px}}
/* Sparse ambient wisps: no blur, particles, canvas or scroll-driven work. */
body.home [data-db-ambient]{position:relative;isolation:isolate}
body.home [data-db-ambient]::before,body.home [data-db-ambient]::after{content:'';pointer-events:none;position:absolute;z-index:-1;left:10%;top:15%;width:min(45%,260px);height:180px;background:radial-gradient(ellipse,rgba(232,217,192,.18),transparent 65%);opacity:0;animation:db-title-steam 9s ease-in-out infinite;animation-play-state:paused}
body.home [data-db-ambient]::after{left:55%;top:25%;animation-delay:-4.5s}
body.home .db-ambient-visible::before,body.home .db-ambient-visible::after{animation-play-state:running}
@keyframes db-title-steam{0%{opacity:0;transform:translate3d(-15%,18%,0) scale(.8)}35%{opacity:.7}100%{opacity:0;transform:translate3d(20%,-65%,0) scale(1.25)}}
@media(prefers-reduced-motion:reduce){body.home [data-db-ambient]::before,body.home [data-db-ambient]::after{animation:none!important}}
.dbf-header-shop,.dbf-launch-bar,.dbf-footer-bottom{font-size:max(15px,.85rem)}
.dbf-header-shop .db-pickup-status,.dbf-footer li,.dbf-location-rows,.dbf-location-meta{font-size:max(16px,.9rem)}
.dbf-story-copy p:not(.dbf-eyebrow),body.home .dbf-review-panel p:not(.dbf-eyebrow){font-size:max(18px,1rem);line-height:1.65}
.dbf-eyebrow,body.home .dbf-eyebrow{font-size:max(14px,.8rem)}
.dbf-button--small{font-size:max(18px,1rem)}
@media(min-width:1101px) and (max-width:1599px){.dbf-header-inner{grid-template-columns:1fr auto;gap:4px 24px;padding-block:8px}.dbf-header-inner .dbf-brand{grid-column:1;grid-row:1;white-space:nowrap}.dbf-header-cta{grid-column:2;grid-row:1;white-space:nowrap}.dbf-header-inner .dbf-nav{grid-column:1/-1;grid-row:2;justify-self:center}.dbf-nav a{white-space:nowrap}}
@media(min-width:1101px){.dbf-nav a{display:inline-flex;align-items:center;min-height:44px;font-size:clamp(16px,.9vw,20px);letter-spacing:.04em}.dbf-nav ul{gap:clamp(14px,1.3vw,28px)}}
@media(max-width:600px){.dbf-launch-bar{flex-wrap:wrap;gap:0 8px;letter-spacing:.04em}.dbf-hero .dbf-wrap{width:100%}}
.db-announcements{display:flex;align-items:center;justify-content:center;gap:12px;background:#f1a132;color:#21170d;padding:4px max(16px,3vw);font-size:max(16px,.9rem);line-height:1.4;min-height:56px}
.db-announcements-list{min-width:0;max-width:68ch;flex:1;text-align:center}
.db-announcements a{display:flex;align-items:center;justify-content:center;min-height:44px;padding:6px;text-decoration:underline;text-underline-offset:4px;font-weight:700}
.db-announcements [hidden]{display:none!important}
.db-announcements-controls{display:flex;gap:8px;flex-shrink:0}
.db-announcements button{border:1px solid currentColor;border-radius:999px;background:transparent;color:inherit;min-height:44px;min-width:44px;padding:6px 12px;font:inherit;cursor:pointer}
.db-announcements a:focus-visible,.db-announcements button:focus-visible{outline:3px solid #21170d;outline-offset:2px;box-shadow:none}
.db-announcements.is-animated a:not([hidden]){animation:db-announcement-in .3s ease-out}
@keyframes db-announcement-in{from{opacity:0;transform:translateX(18px)}to{opacity:1;transform:translateX(0)}}
@media(max-width:600px){.db-announcements{display:grid;grid-template-columns:minmax(0,1fr);gap:0;padding:6px 16px}.db-announcements-list{min-height:68px;display:grid;align-items:center}.db-announcements-controls{justify-content:center}.db-announcements button{font-size:14px}}
@media(prefers-reduced-motion:reduce){.db-announcements.is-animated a:not([hidden]){animation:none}}
</style>
	<?php
}, 99 );

add_action( 'wp_body_open', static function () {
	if ( is_admin() || ! function_exists( 'doughboss_final_ordering_open' ) ) { return; }
	?>
<aside class="db-announcements" data-db-announcements aria-label="Dough Boss announcements">
	<div class="db-announcements-list" id="db-announcement-messages">
		<a href="<?php echo esc_url( home_url( '/locations/' ) ); ?>">Your local Dough Boss — Revesby, Bankstown &amp; Roselands</a>
		<a href="<?php echo esc_url( home_url( '/catering/' ) ); ?>" hidden>Planning a gathering? Explore catering with Revesby</a>
		<a href="<?php echo esc_url( home_url( '/vouchers/' ) ); ?>" hidden>Gift cards &amp; loyalty — see the latest update</a>
		<a href="<?php echo esc_url( home_url( '/members-events/' ) ); ?>" hidden>Members &amp; Events — order updates and what&rsquo;s coming</a>
	</div>
	<div class="db-announcements-controls">
		<button type="button" data-db-announcement-play aria-controls="db-announcement-messages" hidden>Pause updates</button>
		<button type="button" data-db-announcement-next aria-controls="db-announcement-messages" hidden>Next update</button>
	</div>
</aside>
	<?php
}, 20 );

add_action( 'wp_footer', static function () {
	if ( is_admin() || ! function_exists( 'doughboss_final_ordering_open' ) ) { return; }
	?>
<script id="db-announcements-controller">
(function () {
 'use strict';
 var bar = document.querySelector('[data-db-announcements]');
 if (!bar || bar._dbAnnouncements) { return; }
 bar._dbAnnouncements = true;
 var slides = bar.querySelectorAll('.db-announcements-list a');
 var play = bar.querySelector('[data-db-announcement-play]');
 var next = bar.querySelector('[data-db-announcement-next]');
 if (slides.length < 2 || !play || !next) { return; }
 var motion = window.matchMedia('(prefers-reduced-motion: reduce)');
 var connection = window.navigator.connection;
 var paused = motion.matches || !!(connection && connection.saveData);
 var hovered = false, focused = false, visible = true, index = 0, timer = 0;
 function label() {
  play.textContent = paused ? 'Play updates' : 'Pause updates';
  play.setAttribute('aria-pressed', paused ? 'false' : 'true');
  bar.classList.toggle('is-animated', !paused && !motion.matches);
 }
 function advance() {
  slides[index].hidden = true;
  index = (index + 1) % slides.length;
  slides[index].hidden = false;
 }
 function schedule() {
  if (timer) { window.clearTimeout(timer); timer = 0; }
  label();
  if (!paused && !hovered && !focused && visible && !document.hidden) {
   timer = window.setTimeout(function () { timer = 0; advance(); schedule(); }, 6500);
  }
 }
 play.hidden = false; next.hidden = false;
 play.addEventListener('click', function () { paused = !paused; schedule(); });
 next.addEventListener('click', function () { advance(); schedule(); });
 bar.addEventListener('mouseenter', function () { hovered = true; schedule(); });
 bar.addEventListener('mouseleave', function () { hovered = false; schedule(); });
 bar.addEventListener('focusin', function () { focused = true; schedule(); });
 bar.addEventListener('focusout', function (event) { focused = bar.contains(event.relatedTarget); schedule(); });
 document.addEventListener('visibilitychange', schedule);
 function preferenceChanged() {
  if (motion.matches || (connection && connection.saveData)) { paused = true; }
  schedule();
 }
 if (motion.addEventListener) { motion.addEventListener('change', preferenceChanged); }
 else if (motion.addListener) { motion.addListener(preferenceChanged); }
 if (connection && connection.addEventListener) { connection.addEventListener('change', preferenceChanged); }
 if (window.IntersectionObserver) {
  new window.IntersectionObserver(function (entries) { visible = entries[0].isIntersecting; schedule(); }).observe(bar);
 }
 schedule();
}());
</script>
<script id="db-title-steam-controller">
(function () {
 'use strict';
 if (!document.body.classList.contains('home') || !window.IntersectionObserver) { return; }
 var targets = Array.prototype.slice.call(document.querySelectorAll('.db-opening-copy,.dbf-section-head'));
 var motion = window.matchMedia('(prefers-reduced-motion: reduce)');
 var connection = window.navigator.connection;
 var visible = new Set();
 function sync() {
  var control = document.querySelector('[data-db-motion-control]');
  var allowed = !motion.matches && !(connection && connection.saveData) && !document.hidden && (!control || control.getAttribute('aria-pressed') !== 'false');
  targets.forEach(function (target) { target.classList.toggle('db-ambient-visible', allowed && visible.has(target)); });
 }
 var observer = new window.IntersectionObserver(function (entries) {
  entries.forEach(function (entry) { if (entry.isIntersecting) { visible.add(entry.target); } else { visible.delete(entry.target); } });
  sync();
 });
 targets.forEach(function (target) { target.setAttribute('data-db-ambient', ''); observer.observe(target); });
 document.addEventListener('visibilitychange', sync);
 document.addEventListener('click', function (event) { if (event.target.closest('[data-db-motion-control]')) { sync(); } });
 if (motion.addEventListener) { motion.addEventListener('change', sync); }
 if (connection && connection.addEventListener) { connection.addEventListener('change', sync); }
}());
</script>
	<?php
}, 30 );

// The separate 2.49 menu-request hotfix needs a fresh browser cache key.
// A later core release uses its own version and does not inherit this marker.
add_filter( 'script_loader_src', static function ( $src, $handle ) {
	if ( 'doughboss' !== $handle || is_admin() || ! is_string( $src ) || ! defined( 'DOUGHBOSS_VERSION' ) || '2.49.0' !== DOUGHBOSS_VERSION ) {
		return $src;
	}
	$path = wp_parse_url( $src, PHP_URL_PATH );
	$asset = '/doughboss/public/js/doughboss.js';
	if ( ! is_string( $path ) || $asset !== substr( $path, -strlen( $asset ) ) ) {
		return $src;
	}
	return add_query_arg( 'ver', '2.49.0-menu-6cfded01496d', $src );
}, 20, 2 );
