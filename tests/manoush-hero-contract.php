<?php
/** Static contract for the authentic photographic homepage hero. */

$fail = 0;
$pass = 0;
function hero_ok( $condition, $label ) {
	global $fail, $pass;
	if ( $condition ) { ++$pass; echo "  ok   $label\n"; }
	else { ++$fail; echo "  FAIL $label\n"; }
}

$root   = dirname( __DIR__ );
$css    = file_get_contents( $root . '/public/css/doughboss-manoush-hero.css' );
$js     = file_get_contents( $root . '/public/js/doughboss-manoush-hero.js' );
$php    = file_get_contents( $root . '/includes/class-doughboss-shortcodes.php' );
$assets = file_get_contents( $root . '/includes/class-doughboss-assets.php' );
$theme  = file_get_contents( $root . '/themes/doughboss-final/front-page.php' );

echo "=== Authentic photographic hero contract ===\n";
hero_ok( false !== strpos( $php, "add_shortcode( 'doughboss_manoush_hero'" ), 'shortcode is registered' );
hero_ok( false !== strpos( $assets, 'doughboss-manoush-hero.css' ) && false !== strpos( $assets, 'doughboss-manoush-hero.js' ), 'hero ships dependency-free assets' );
hero_ok( false !== strpos( $php, 'doughboss-feast-real-v1.jpg' ), 'hero defaults to the approved real DoughBoss feast photo' );
hero_ok( file_exists( $root . '/public/images/doughboss-feast-real-v1.jpg' ), 'real hero photo is packaged' );
hero_ok( false !== strpos( $theme, 'title="Fresh from the oven."' ) && false !== strpos( $theme, 'Oven-baked in Sydney since 2009' ), 'homepage uses approved oven-baked language' );
hero_ok( false !== strpos( $php, 'db-mh-action--primary' ) && false !== strpos( $php, "home_url( '/order/' )" ), 'hero exposes an immediate ordering action' );
hero_ok( false !== strpos( $php, 'db-mh-action--secondary' ) && false !== strpos( $php, "home_url( '/catering/' )" ), 'hero exposes catering without crowding the primary action' );
hero_ok( false === strpos( $php, 'db-mh-central' ) && false === strpos( $php, 'db-mh-ingredient' ), 'generated floating-food layer markup has been removed' );
hero_ok( false === strpos( $css, 'db-mh-smoke' ) && false === strpos( $css, 'rotateX(' ), 'fake smoke and 3D food transforms have been removed' );
hero_ok( false !== strpos( $css, 'background-size: cover' ) && false !== strpos( $css, 'linear-gradient(90deg' ), 'real photo is presented full-bleed with a legibility veil' );
hero_ok( false !== strpos( $js, "setProperty('--db-mh-photo-y'" ) && false !== strpos( $js, "setProperty('--db-mh-photo-scale'" ), 'scroll drives restrained camera-style motion' );
hero_ok( false !== strpos( $js, 'is-photo-paused' ) && false !== strpos( $js, 'aria-pressed' ), 'motion has an accessible pause control' );
hero_ok( false !== strpos( $css, '@media (prefers-reduced-motion: reduce)' ) && false !== strpos( $js, 'prefers-reduced-motion: reduce' ), 'reduced-motion is honoured in CSS and JavaScript' );
hero_ok( false !== strpos( $css, '@media (max-width: 720px)' ) && false !== strpos( $css, '.db-mh-copy { padding-top: 27vh; }' ), 'mobile receives deliberate photographic art direction' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
