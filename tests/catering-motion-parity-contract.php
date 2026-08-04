<?php
/**
 * Static contract for the catering Bites composition shared by the demo and
 * the WordPress manoush shortcode.
 *
 * Run: php tests/catering-motion-parity-contract.php
 */

$fail = 0;
$pass = 0;

function catering_parity_ok( $condition, $label ) {
	global $fail, $pass;
	if ( $condition ) {
		$pass++;
		echo "  ok   $label\n";
		return;
	}
	$fail++;
	echo "  FAIL $label\n";
}

function catering_parity_has_all( $haystack, $needles ) {
	foreach ( $needles as $needle ) {
		if ( false === strpos( $haystack, $needle ) ) {
			return false;
		}
	}
	return true;
}

$root     = dirname( __DIR__ );
$demo     = file_get_contents( $root . '/demo/index.html' );
$demo_css = file_get_contents( $root . '/demo/demo.css' );
$demo_js  = file_get_contents( $root . '/demo/manoush-hero.js' );
$wp       = file_get_contents( $root . '/includes/class-doughboss-shortcodes.php' );
$wp_css   = file_get_contents( $root . '/public/css/doughboss-manoush-hero.css' );
$wp_js    = file_get_contents( $root . '/public/js/doughboss-manoush-hero.js' );

$catering_assets = array(
	'hero-sujuk-special-v5.webp',
	'hero-folded-zaatar-v4.webp',
	'hero-cheese-manoush-v4.webp',
	'hero-spinach-fatayer-v4.webp',
	'hero-chicken-wrap-v4.webp',
);
$menu_varieties = array(
	'Mini zaatar',
	'cheese and meat manoush',
	'spinach, haloumi, chicken and shanklish pies',
);

echo "=== Catering motion parity contract ===\n";
catering_parity_ok( catering_parity_has_all( $demo, $catering_assets ), 'demo references every approved transparent catering cutout' );
catering_parity_ok( catering_parity_has_all( $wp, $catering_assets ), 'WordPress shortcode defaults reference the same transparent catering cutouts' );
catering_parity_ok( false === strpos( $demo, 'catering-menu-platter-v3.webp' ), 'demo does not restore the rejected precomposed middle platter' );
catering_parity_ok( false === strpos( $wp, 'catering-menu-platter-v3.webp' ), 'WordPress does not restore the rejected precomposed middle platter' );
catering_parity_ok( catering_parity_has_all( $demo, $menu_varieties ), 'demo catering copy names the actual mini-manoush and pie varieties' );
catering_parity_ok( catering_parity_has_all( $wp, $menu_varieties ), 'WordPress catering copy names the actual mini-manoush and pie varieties' );
catering_parity_ok( false !== strpos( $demo, 'data-manoush-replay="bites"' ) && false !== strpos( $demo, 'data-manoush-replay-text' ) && false !== strpos( $demo_js, "button.setAttribute('aria-label', label)" ), 'demo provides an accessible Bites animation control with a synchronised label' );
catering_parity_ok(
	false !== strpos( $demo, 'data-manoush-variant="full"' )
		&& false !== strpos( $demo, 'data-manoush-variant="menu"' )
		&& false !== strpos( $demo, 'data-manoush-replay="full"' )
		&& false !== strpos( $demo, 'data-manoush-replay="menu"' )
		&& false !== strpos( $demo_js, "if (view === 'menu') { return 'menu'; }" ),
	'homepage and menu both contain replayable, route-bound food build stages'
);
catering_parity_ok(
	false !== strpos( $demo, 'ingredient-burst--signature' )
		&& false === strpos( $demo, '<img class="ingredient-burst__manoush" src="assets/menu/zaatar-cheese.jpg"' )
		&& false !== strpos( $demo_css, '.ingredient-burst--signature .ingredient-burst__manoush' )
		&& false !== strpos( $demo_css, 'rotateX(14deg)' ),
	'homepage uses natural-shape transparent food assets instead of the tilted oval photo collage'
);
catering_parity_ok( false !== strpos( $wp, 'data-db-manoush-replay' ) && false !== strpos( $wp_js, "replay.addEventListener('click'" ), 'WordPress provides an accessible replay control' );
catering_parity_ok( false !== strpos( $demo_css, '@media(prefers-reduced-motion:reduce)' ) && false !== strpos( $demo_css, '.hero-replay{display:inline-flex;}' ) && false !== strpos( $demo_js, "stage.classList.add('is-assembled')" ), 'demo retains its motion-control styling and safe assembled state while the approved automatic build is enabled' );
catering_parity_ok(
	false !== strpos( $wp_css, '@media (prefers-reduced-motion:reduce)' )
		&& false !== strpos( $wp_css, '.db-mh-replay { display: inline-flex; }' )
		&& false !== strpos( $wp_js, 'motionOptedIn = true;' )
		&& false !== strpos( $wp_js, "hero.classList.add('is-assembled')" ),
	'WordPress starts the approved food build on every device while retaining Pause and Resume'
);
catering_parity_ok(
	false !== strpos( $demo, 'The food build starts automatically and follows your scroll.' )
		&& false !== strpos( $demo_css, '.hero-motion-reduced{display:inline-flex;}' )
		&& false !== strpos( $wp, 'The food build starts automatically and follows your scroll.' )
		&& false !== strpos( $wp_css, '.db-mh-motion-note { display: block;' ),
	'demo and WordPress explain the automatic first build, scroll control and visible Pause override'
);
catering_parity_ok(
	false !== strpos( $demo_js, "stage.classList.add('is-resetting')" )
		&& false !== strpos( $demo_css, '.is-resetting .ingredient-burst__manoush' )
		&& false !== strpos( $wp_js, "hero.classList.add('is-resetting')" )
		&& false !== strpos( $wp_css, '.db-manoush-hero.is-resetting .db-mh-central' ),
	'demo and WordPress use a painted reset state so initial and replay animations cannot be coalesced'
);
catering_parity_ok( false !== strpos( $demo_css, '@media(max-width:560px)' ) && false !== strpos( $demo_css, '.hero-catering-bites .ingredient-burst{') && false === strpos( $demo_css, '.hero-catering-bites .ingredient-burst{display:none' ), 'demo keeps the catering composition visible on mobile' );
catering_parity_ok(
	false !== strpos( $demo_css, '.hero-menu-motion .ingredient-burst' )
		&& false !== strpos( $demo_css, '.hero-manoush .ingredient-burst' )
		&& false === strpos( $demo_css, '.hero-menu-motion .ingredient-burst{display:none' )
		&& false === strpos( $demo_css, '.hero-manoush .ingredient-burst{display:none' ),
	'homepage and menu food stages remain visible on mobile'
);
catering_parity_ok( false !== strpos( $wp_css, '@media (max-width:720px)' ) && false !== strpos( $wp_css, '.db-mh-stage,.db-mh-world { min-height: 325px; }' ) && false === strpos( $wp_css, '.db-mh-stage { display: none' ), 'WordPress keeps the composition visible on mobile' );
catering_parity_ok( false !== strpos( $wp_js, 'hero._dbManoushReady = true' ) && false !== strpos( $wp_js, 'play(hero);' ) && false !== strpos( $demo_js, 'playForView((window.location.hash' ), 'demo and WordPress automatically play the food build after their images are ready' );
catering_parity_ok( false !== strpos( $wp_js, "window.dispatchEvent(new Event('db:manoush-ready'))" ) && false !== strpos( $wp_js, "window.addEventListener('db:manoush-ready', requestScrollScene)" ) && false !== strpos( $demo_js, "window.dispatchEvent(new Event('db:manoush-ready'))" ) && false !== strpos( $demo_js, "window.addEventListener('db:manoush-ready', requestRender)" ), 'demo and WordPress hand the completed entrance directly to their reversible scroll renderer' );
catering_parity_ok( false === strpos( $wp_js, 'scheduleReplay' ) && false === strpos( $demo_js, 'scheduleStageReplay' ) && false !== strpos( $wp_js, "window.dispatchEvent(new Event('db:manoush-ready'))" ) && false !== strpos( $demo_js, "window.dispatchEvent(new Event('db:manoush-ready'))" ), 'both experiences play once on first view and leave permanent control with scroll' );
catering_parity_ok( false !== strpos( $wp_js, 'pauseHero(hero)' ) && false !== strpos( $demo_js, 'pauseStage(stage)' ) && false !== strpos( $wp, 'data-db-resume-label=' ), 'first-load and scroll motion have a visible pause and resume mechanism' );
catering_parity_ok( false !== strpos( $wp_js, "classList.add('is-user-paused')" ) && false !== strpos( $wp_css, '.db-manoush-hero.is-user-paused .db-mh-stage::before' ) && false !== strpos( $wp_css, '.is-user-paused.is-assembled:not(.is-scroll-driven) .db-mh-central img' ) && false !== strpos( $demo_js, "classList.add('is-user-paused')" ) && false !== strpos( $demo_css, '.ingredient-burst[data-manoush-stage].is-user-paused' ), 'pause stops scripted cycles, ambient food motion and atmospheric motion, including after reduced-motion opt-in' );
catering_parity_ok( false !== strpos( $wp_js, 'function freezeHero(hero)' ) && false !== strpos( $wp_js, 'window.getComputedStyle(part)' ) && false !== strpos( $demo_js, 'function freezeStage(stage)' ) && false !== strpos( $demo_js, 'window.getComputedStyle(part)' ), 'Pause freezes the current computed pose in demo and WordPress without a visual reset' );
catering_parity_ok( false !== strpos( $demo_css, '@media(prefers-reduced-motion:reduce) and (max-width:800px)' ) && false !== strpos( $demo_css, '.hero-menu-motion .ingredient-burst.is-assembled' ) && false !== strpos( $demo_css, 'db-stage-breathe-mobile 8s' ), 'explicit reduced-motion opt-in preserves the compact and catering mobile stage layouts' );
catering_parity_ok( false === strpos( $demo_js, "classList.toggle('is-exploded'") && false === strpos( $demo_js, "classList.toggle('is-assembled'") && false === strpos( $demo_js, 'direction = currentY' ), 'demo scroll handler does not toggle assembly states' );
catering_parity_ok( false === strpos( $wp_js, "classList.toggle('is-exploded'") && false === strpos( $wp_js, "classList.toggle('is-assembled'") && false === strpos( $wp_js, 'direction = currentY' ), 'WordPress scroll handler does not toggle assembly states' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
