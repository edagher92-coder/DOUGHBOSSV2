<?php
/**
 * Public theme contracts for the Square Gift Cards and Partners navigation.
 *
 * @package DoughBoss
 */

$theme_directory = dirname( __DIR__ ) . '/themes/doughboss-final/';
$voucher_template = (string) file_get_contents( $theme_directory . 'page-vouchers.php' );
$footer           = (string) file_get_contents( $theme_directory . 'footer.php' );
$functions        = (string) file_get_contents( $theme_directory . 'functions.php' );

if ( ! function_exists( 'add_action' ) ) {
	/** @return true */
	function add_action() {
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/** @return true */
	function add_filter() {
		return true;
	}
}

$GLOBALS['doughboss_theme_nav_test_slugs'] = array();
if ( ! function_exists( 'get_post_field' ) ) {
	/** @return string */
	function get_post_field( $field, $post_id ) {
		if ( 'post_name' !== $field ) {
			return '';
		}
		return (string) ( $GLOBALS['doughboss_theme_nav_test_slugs'][ (int) $post_id ] ?? '' );
	}
}

require_once $theme_directory . 'functions.php';

db_test( 'online-only manifest contains only canonical public site and validated Site Icons', function () {
	$home = home_url( '/' );
	$base = home_url( '/wp-content/uploads' );
	$icons = array(
		192 => array( 'src' => $base . '/2026/10/site-icon-192x192.png', 'width' => 192, 'height' => 192 ),
		512 => array( 'src' => $base . '/2026/10/site-icon.png', 'width' => 512, 'height' => 512 ),
	);
	$manifest = doughboss_final_manifest_data( $home, $base, $icons );
	assert_same( $home, $manifest['id'], 'identity is canonical home, never customer state' );
	assert_same( home_url( '/order/' ), $manifest['start_url'], 'installed app opens menu without tracking/account/order state' );
	assert_same( '/', $manifest['scope'], 'root app scope' );
	assert_same( 'Dough Boss', $manifest['name'], 'existing brand name' );
	assert_same( 'standalone', $manifest['display'], 'standalone display' );
	assert_same( '#f7f5f0', $manifest['background_color'], 'existing theme paper colour' );
	assert_same( '#e2231a', $manifest['theme_color'], 'existing theme ember colour' );
	assert_same( false, $manifest['prefer_related_applications'], 'no unrelated app promotion' );
	assert_same( array( '192x192', '512x512' ), array_column( $manifest['icons'], 'sizes' ), 'required icon sizes' );
	assert_same( 'image/png', $manifest['icons'][0]['type'], 'declared MIME follows validated PNG extension' );
	assert_same( null, doughboss_final_manifest_data( $home, $base, array() ), 'missing icons fail closed' );
	$small = $icons;
	$small[512]['width'] = 192;
	assert_same( null, doughboss_final_manifest_data( $home, $base, $small ), 'WordPress small-original fallback cannot pretend to be 512px' );
	foreach ( array(
		'https://external.example/wp-content/uploads/icon.png',
		'http://doughboss.test/wp-content/uploads/icon.png',
		'https://user:secret@doughboss.test/wp-content/uploads/icon.png',
		$base . '/icon.png?token=private', $base . '/icon.png#private',
		home_url( '/private/icon.png' ), $base . '/../private/icon.png',
		$base . '/%2e%2e/private/icon.png', $base . '/icon.svg',
		$base . '/bad icon.png', $base . '/bad\\icon.png',
	) as $unsafe ) {
		$changed = $icons;
		$changed[512]['src'] = $unsafe;
		assert_same( null, doughboss_final_manifest_data( $home, $base, $changed ), 'unsafe icon rejected: ' . $unsafe );
	}
	assert_same( null, doughboss_final_manifest_data( $home . '?email=private', $base, $icons ), 'home cannot include private state' );
	assert_same( null, doughboss_final_manifest_data( $home, 'https://external.example/uploads', $icons ), 'uploads must remain on canonical origin' );
	foreach ( array( null, false, true, 1, array(), new stdClass(), '', '  ' ) as $invalid ) {
		assert_same( null, doughboss_final_manifest_data( $invalid, $base, $icons ), 'invalid home type or empty value fails closed' );
		assert_same( null, doughboss_final_manifest_data( $home, $invalid, $icons ), 'invalid uploads type or empty value fails closed' );
		if ( ! is_array( $invalid ) ) {
			assert_same( null, doughboss_final_manifest_data( $home, $base, $invalid ), 'invalid icon container type fails closed' );
		}
	}
	$subdirectory = doughboss_final_manifest_data( home_url( '/bakery/' ), $base, $icons );
	assert_same( home_url( '/bakery/order/' ), $subdirectory['start_url'], 'menu start URL respects canonical WordPress subdirectory' );
	assert_same( home_url( '/bakery/' ), $subdirectory['id'], 'subdirectory app identity stays unchanged' );
	assert_same( '/bakery/', $subdirectory['scope'], 'subdirectory app scope stays unchanged' );
} );

db_test( 'manifest endpoint accepts only exact canonical-root GET query', function () use ( $functions ) {
	assert_true( doughboss_final_is_manifest_request( 'GET', '/?db_manifest=1', home_url( '/' ) ), 'exact public endpoint admitted' );
	assert_true( doughboss_final_is_manifest_request( 'GET', '/bakery/?db_manifest=1', home_url( '/bakery/' ) ), 'WordPress subdirectory home respected' );
	foreach ( array( '/order/?db_manifest=1', '/?db_manifest=01', '/?db_manifest[]=1', '/?db_manifest=1&email=x', '/?db_manifest=1&db_manifest=1', '/?DB_MANIFEST=1', '//evil.example/?db_manifest=1', 'https://doughboss.test/?db_manifest=1', '/?db_manifest=1#fragment' ) as $uri ) {
		assert_false( doughboss_final_is_manifest_request( 'GET', $uri, home_url( '/' ) ), 'non-exact request ignored: ' . $uri );
	}
	assert_false( doughboss_final_is_manifest_request( 'POST', '/?db_manifest=1', home_url( '/' ) ), 'POST cannot activate endpoint' );
	assert_true( false !== strpos( $functions, "apply_filters( 'doughboss_final_install_enabled', true )" ), 'other manifest suppliers can opt out' );
	assert_true( false !== strpos( $functions, 'get_site_icon_url( $size )' ), 'configured WordPress Site Icon is the only artwork source' );
	assert_true( false !== strpos( $functions, 'wp_upload_dir( null, false )' ), 'manifest lookup cannot create uploads directories' );
	assert_true( false !== strpos( $functions, "'Content-Type: application/manifest+json; charset=UTF-8'" ), 'manifest MIME type emitted' );
} );

db_test( 'voucher page is informational Square Gift Cards and loyalty copy without a legacy claim form', function () use ( $voucher_template ) {
	assert_true( false !== strpos( $voucher_template, 'Square Gift Cards' ), 'voucher page identifies Square Gift Cards' );
	assert_true( false !== strpos( $voucher_template, 'loyalty' ), 'voucher page states the loyalty destination' );
	assert_true( false !== strpos( $voucher_template, 'once it is available' ), 'voucher page does not invent a provider purchase URL' );
	assert_false( false !== strpos( $voucher_template, 'doughboss_voucher_claim' ), 'voucher page does not render the legacy claim shortcode' );
	assert_false( false !== strpos( $voucher_template, 'legacy_voucher_issuance_enabled' ), 'voucher page does not expose claims when the legacy setting is enabled' );
} );

db_test( 'footer relabels voucher navigation and removes the franchising destination', function () use ( $footer ) {
	assert_true( false !== strpos( $footer, "home_url( '/vouchers/' )" ), 'footer retains the vouchers route' );
	assert_true( false !== strpos( $footer, "'Gift cards & loyalty'" ), 'footer uses the Gift cards and loyalty label' );
	assert_same( 1, substr_count( $footer, "home_url( '/wholesale/' )" ), 'footer has one Partners link to the wholesale enquiry route' );
	assert_true( false !== strpos( $footer, "'Partners'" ), 'footer exposes the single Partners link while franchising is hidden' );
	assert_false( false !== strpos( $footer, '/franchising/' ), 'footer has no franchising URL' );
} );

db_test( 'configured and fallback navigation map franchising items to Partners wholesale route', function () use ( $functions ) {
	assert_true( false !== strpos( $functions, 'function doughboss_final_partners_nav_items' ), 'configured menu remap is present' );
	assert_true( false !== strpos( $functions, "home_url( '/wholesale/' )" ), 'configured menu remap targets wholesale' );
	assert_true( false !== strpos( $functions, "__( 'Partners', 'doughboss-final' )" ), 'configured and fallback menus use Partners' );
	assert_true( false !== strpos( $functions, "add_filter( 'wp_nav_menu_objects', 'doughboss_final_partners_nav_items', 9 )" ), 'configured menu is remapped before the separate priority-10 pause filter' );
	assert_true( false !== strpos( $functions, "get_post_field( 'post_name'" ), 'remap recognises a draft page stored as a page-id menu item' );
	assert_true( false !== strpos( $functions, '$item->title ??' ), 'remap tolerates a missing menu-item title' );
	assert_true( false !== strpos( $functions, '$item->classes ??' ), 'remap tolerates a missing menu-item classes property' );
	assert_true( false !== strpos( $functions, '$is_local &&' ), 'remap does not relabel external franchising links based only on their label' );
} );

db_test( 'configured menu remap handles incomplete local items and leaves external franchise labels alone', function () {
	$GLOBALS['doughboss_theme_nav_test_slugs'] = array( 137 => 'franchising' );
	$local = (object) array(
		'url'       => home_url( '/franchising/' ),
		'object'    => 'page',
		'object_id' => 10,
	);
	$draft = (object) array(
		'url'       => home_url( '/?page_id=137' ),
		'object'    => 'page',
		'object_id' => 137,
	);
	$external = (object) array(
		'url'   => 'https://unrelated.example/franchising/',
		'title' => 'Franchising & partners',
	);

	$previous_error_handler = set_error_handler(
		function ( $severity, $message, $file, $line ) {
			throw new ErrorException( $message, 0, $severity, $file, $line );
		}
	);
	try {
		$items = doughboss_final_partners_nav_items( array( $local, $draft, $external ) );
	} finally {
		restore_error_handler();
	}
	unset( $previous_error_handler );

	assert_same( home_url( '/wholesale/' ), $items[0]->url, 'local franchising URL maps to wholesale' );
	assert_same( 'Partners', $items[0]->title, 'local franchising URL is relabelled' );
	assert_true( in_array( 'dbf-nav-partners', $items[0]->classes, true ), 'local item gains the partners class without a pre-existing classes property' );
	assert_same( home_url( '/wholesale/' ), $items[1]->url, 'draft page-id URL maps by stored franchising slug' );
	assert_same( 'Partners', $items[1]->title, 'draft page-id item is relabelled' );
	assert_same( 'custom', $items[1]->object, 'remapped menu item no longer identifies the paused page' );
	assert_same( 0, $items[1]->object_id, 'page identity is cleared only on the remapped navigation object' );
	assert_same( 'https://unrelated.example/franchising/', $items[2]->url, 'external franchising path is unchanged' );
	assert_same( 'Franchising & partners', $items[2]->title, 'external franchising label is unchanged' );
	assert_false( isset( $items[2]->classes ), 'external item is not assigned a partners class' );
} );
