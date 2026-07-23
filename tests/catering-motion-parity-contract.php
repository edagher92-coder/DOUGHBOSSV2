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
	'catering-menu-platter-v3.webp',
	'catering-zaatar-cutout-v2.webp',
	'catering-cheese-cutout-v2.webp',
	'catering-pies-v3.webp',
	'catering-fresh-cutout-v2.webp',
);
$menu_varieties = array(
	'Mini zaatar',
	'cheese and meat manoush',
	'spinach, haloumi, chicken and shanklish pies',
);

echo "=== Catering motion parity contract ===\n";
catering_parity_ok( catering_parity_has_all( $demo, $catering_assets ), 'demo references the real-alpha catering platter and pie assets' );
catering_parity_ok( catering_parity_has_all( $wp, $catering_assets ), 'WordPress shortcode defaults reference the same catering assets' );
catering_parity_ok( catering_parity_has_all( $demo, $menu_varieties ), 'demo catering copy names the actual mini-manoush and pie varieties' );
catering_parity_ok( catering_parity_has_all( $wp, $menu_varieties ), 'WordPress catering copy names the actual mini-manoush and pie varieties' );
catering_parity_ok( false !== strpos( $demo, 'data-manoush-replay="bites"' ) && false !== strpos( $demo_js, "button.setAttribute('aria-pressed', 'true')" ), 'demo provides an accessible Bites replay control' );
catering_parity_ok( false !== strpos( $wp, 'data-db-manoush-replay' ) && false !== strpos( $wp_js, "replay.addEventListener('click'" ), 'WordPress provides an accessible replay control' );
catering_parity_ok( false !== strpos( $demo_css, '@media(prefers-reduced-motion:reduce)' ) && false !== strpos( $demo_css, '.hero-replay{display:none;}' ) && false !== strpos( $demo_js, "stage.classList.add('is-assembled')" ), 'demo has reduced-motion still-state and hides replay' );
catering_parity_ok( false !== strpos( $wp_css, '@media (prefers-reduced-motion:reduce)' ) && false !== strpos( $wp_css, '.db-mh-replay { display: none; }' ) && false !== strpos( $wp_js, "hero.classList.add('is-assembled')" ), 'WordPress has reduced-motion still-state and hides replay' );
catering_parity_ok( false !== strpos( $demo_css, '@media(max-width:560px)' ) && false !== strpos( $demo_css, '.hero-catering-bites .ingredient-burst{') && false === strpos( $demo_css, '.hero-catering-bites .ingredient-burst{display:none' ), 'demo keeps the catering composition visible on mobile' );
catering_parity_ok( false !== strpos( $wp_css, '@media (max-width:720px)' ) && false !== strpos( $wp_css, '.db-mh-stage,.db-mh-world { min-height: 285px; }' ) && false === strpos( $wp_css, '.db-mh-stage { display: none' ), 'WordPress keeps the composition visible on mobile' );
catering_parity_ok( false === strpos( $demo_js, "classList.toggle('is-exploded'") && false === strpos( $demo_js, "classList.toggle('is-assembled'") && false === strpos( $demo_js, 'direction = currentY' ), 'demo scroll handler does not toggle assembly states' );
catering_parity_ok( false === strpos( $wp_js, "classList.toggle('is-exploded'") && false === strpos( $wp_js, "classList.toggle('is-assembled'") && false === strpos( $wp_js, 'direction = currentY' ), 'WordPress scroll handler does not toggle assembly states' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
