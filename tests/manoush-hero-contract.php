<?php
/**
 * Static delivery contract for the self-contained Manoush hero.
 *
 * Run: php tests/manoush-hero-contract.php
 */

$fail = 0;
$pass = 0;
function hero_ok( $condition, $label ) {
	global $fail, $pass;
	if ( $condition ) { $pass++; echo "  ok   $label\n"; }
	else { $fail++; echo "  FAIL $label\n"; }
}

$root = dirname( __DIR__ );
$css  = file_get_contents( $root . '/public/css/doughboss-manoush-hero.css' );
$js   = file_get_contents( $root . '/public/js/doughboss-manoush-hero.js' );
$php  = file_get_contents( $root . '/includes/class-doughboss-shortcodes.php' );
$assets = file_get_contents( $root . '/includes/class-doughboss-assets.php' );
$demo_css = file_get_contents( $root . '/demo/demo.css' );
$demo_js  = file_get_contents( $root . '/demo/manoush-hero.js' );
$storefront_css = file_get_contents( $root . '/public/css/doughboss.css' );

echo "=== Manoush hero contract ===\n";
hero_ok( false !== strpos( $php, "add_shortcode( 'doughboss_manoush_hero'" ), 'shortcode is registered' );
hero_ok( false !== strpos( $assets, "doughboss-manoush-hero.css" ) && false !== strpos( $assets, "doughboss-manoush-hero.js" ), 'hero ships separate assets' );
hero_ok( false !== strpos( $css, 'perspective:' ) && false !== strpos( $css, 'transform-style: preserve-3d' ), 'CSS defines a 3D stage' );
hero_ok( false !== strpos( $css, 'translate3d(' ) && false !== strpos( $css, 'rotateX(' ) && false !== strpos( $css, 'rotateY(' ), 'ingredients use 3D transforms' );
hero_ok( false !== strpos( $js, 'requestAnimationFrame' ) && false !== strpos( $js, 'offsetWidth' ), 'replay has a paint-safe reset' );
hero_ok( false !== strpos( $js, 'imagesReady' ) && false !== strpos( $js, "addEventListener('error'" ), 'animation waits for image completion or failure' );
hero_ok( false !== strpos( $css, '@media (prefers-reduced-motion:reduce)' ) && false !== strpos( $css, '.db-mh-replay { display: none; }' ), 'reduced-motion still hides replay' );
hero_ok( false !== strpos( $css, '@media (max-width:720px)' ) && false === strpos( $css, '.db-mh-stage { display: none' ), 'mobile retains the stage' );
hero_ok( false !== strpos( $css, '@media (max-width:360px)' ) && false !== strpos( $css, '--db-x:-29vw' ), 'WordPress hero contains a 320px-safe composition' );
hero_ok( false !== strpos( $php, 'width="900" height="716" loading="eager" decoding="async"' ), 'WordPress hero reserves mobile image space' );
hero_ok( false !== strpos( $storefront_css, '@media (max-width: 480px)' ) && false !== strpos( $storefront_css, 'overflow-wrap: anywhere' ), 'WordPress cart and builder guard narrow mobile widths' );
hero_ok( false !== strpos( $demo_css, 'perspective:1100px' ) && false !== strpos( $demo_css, 'transform-style:preserve-3d' ), 'demo defines a 3D ingredient stage' );
hero_ok( false !== strpos( $demo_css, 'translate3d(' ) && false !== strpos( $demo_css, 'rotateX(' ) && false !== strpos( $demo_css, 'rotateY(' ), 'demo burst uses 3D transforms' );
hero_ok( false !== strpos( $demo_js, 'explodeHoldMs = 2050' ) && false !== strpos( $demo_js, "classList.remove('is-exploded')" ), 'demo holds the explosion before assembly' );
hero_ok( false !== strpos( $demo_js, 'requestAnimationFrame' ) && false !== strpos( $demo_js, 'offsetWidth' ), 'demo replay has a paint-safe reset' );
hero_ok( false !== strpos( $demo_js, 'imagesReady' ) && false !== strpos( $demo_js, "addEventListener('error'" ), 'demo waits for image completion or failure' );
hero_ok( false === strpos( $demo_css, '@media(max-width:560px){.ingredient-burst{display:none;}' ), 'demo keeps the ingredient stage visible on mobile' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
