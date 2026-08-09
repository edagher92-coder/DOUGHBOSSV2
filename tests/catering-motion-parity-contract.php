<?php
/** Static contract for the authentic photographic hero shared by WordPress and demo. */

$fail = 0;
$pass = 0;
function catering_parity_ok( $condition, $label ) {
	global $fail, $pass;
	if ( $condition ) { ++$pass; echo "  ok   $label\n"; }
	else { ++$fail; echo "  FAIL $label\n"; }
}

$root     = dirname( __DIR__ );
$demo     = file_get_contents( $root . '/demo/index.html' );
$demo_css = file_get_contents( $root . '/demo/demo.css' );
$wp       = file_get_contents( $root . '/includes/class-doughboss-shortcodes.php' );
$wp_css   = file_get_contents( $root . '/public/css/doughboss-manoush-hero.css' );
$wp_js    = file_get_contents( $root . '/public/js/doughboss-manoush-hero.js' );
$catering = file_get_contents( $root . '/themes/doughboss-final/page-catering.php' );

echo "=== Authentic photo parity contract ===\n";
catering_parity_ok( file_exists( $root . '/public/images/doughboss-feast-real-v1.jpg' ), 'approved real feast photograph is packaged' );
catering_parity_ok( false !== strpos( $wp, 'doughboss-feast-real-v1.jpg' ) && false !== strpos( $demo, 'doughboss-feast-real-v1.jpg' ), 'WordPress and demo use the same approved merchant photograph' );
catering_parity_ok( false !== strpos( $catering, 'doughboss-feast-real-v1.jpg' ), 'catering page uses the approved real spread' );
catering_parity_ok( false === strpos( $wp, 'db-mh-ingredient' ) && false === strpos( $wp, 'db-mh-central' ), 'WordPress contains no floating-food layer markup' );
catering_parity_ok( false === strpos( $demo, 'ingredient-burst' ) && false === strpos( $demo, 'hero-sujuk-special-v5.webp' ), 'demo contains no floating-food stage or generated hero cutout' );
catering_parity_ok( false === strpos( $wp_css, 'db-mh-smoke' ) && false === strpos( $wp_css, 'rotateX(' ), 'WordPress hero contains no fake smoke or 3D food transforms' );
catering_parity_ok( false !== strpos( $wp_css, 'background-size: cover' ) && false !== strpos( $demo_css, '.hero-photo-real .hero-bg' ), 'both surfaces treat the real photo as responsive editorial art direction' );
catering_parity_ok( false !== strpos( $wp_js, "setProperty('--db-mh-photo-y'" ) && false !== strpos( $wp_js, "window.addEventListener('scroll'" ), 'WordPress uses restrained reversible photo parallax' );
catering_parity_ok( false !== strpos( $wp_js, 'is-photo-paused' ) && false !== strpos( $wp, 'Pause photo motion' ), 'WordPress provides an accessible pause and resume control' );
catering_parity_ok( false !== strpos( $wp_css, '@media (prefers-reduced-motion: reduce)' ) && false !== strpos( $demo_css, '@media(prefers-reduced-motion:reduce)' ), 'both surfaces respect reduced motion' );
catering_parity_ok( false === stripos( $wp . $catering, 'stone-baked' ) && false === stripos( $wp . $catering, 'wood-fired' ), 'public hero copy uses no rejected stone-baked or wood-fired claim' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
