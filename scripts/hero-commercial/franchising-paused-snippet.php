<?php
/** Temporary owner-requested pause. Keep separate from hero rollback. */
if ( ! defined( 'ABSPATH' ) ) { return; }

add_filter( 'wp_nav_menu_objects', static function ( $items ) {
	return array_values( array_filter( (array) $items, static function ( $item ) {
		// WordPress changes a draft page's nav URL to ?page_id=..., so bind
		// page menu entries to the stored slug as well as the public URL.
		if ( is_object( $item ) && isset( $item->object, $item->object_id ) && 'page' === $item->object && 'franchising' === get_post_field( 'post_name', (int) $item->object_id ) ) { return false; }
		$path = is_object( $item ) && isset( $item->url ) ? wp_parse_url( $item->url, PHP_URL_PATH ) : '';
		return '/franchising' !== rtrim( (string) $path, '/' );
	} ) );
} );

// The existing theme's footer is not a WordPress navigation menu.
add_action( 'wp_head', static function () {
	if ( is_admin() ) { return; }
	echo '<style id="doughboss-franchising-paused">.dbf-footer a[href$="/franchising/"],.dbf-footer a[href$="/franchising"]{display:none!important}.dbf-footer li:has(>a[href$="/franchising/"]),.dbf-footer li:has(>a[href$="/franchising"]){display:none!important}</style>';
} );

add_filter( 'pre_do_shortcode_tag', static function ( $result, $tag, $attributes ) {
	if ( false !== $result || 'doughboss_b2b_form' !== $tag || ! is_array( $attributes ) ) { return $result; }
	$type = isset( $attributes['type'] ) && is_scalar( $attributes['type'] ) ? strtolower( trim( (string) $attributes['type'] ) ) : '';
	return in_array( $type, array( 'franchise', 'franchising', 'partner', 'partners', 'partnership', 'partnerships' ), true ) ? '' : $result;
}, 21, 3 );

// Unpublishing the page alone must not leave its mail-sending API usable.
add_filter( 'rest_pre_dispatch', static function ( $result, $server, $request ) {
	if ( null !== $result || '/doughboss/v1/b2b/enquiry' !== strtolower( rtrim( $request->get_route(), '/' ) ) || 'POST' !== $request->get_method() ) { return $result; }
	$type = $request->get_param( 'type' );
	$type = is_scalar( $type ) ? strtolower( trim( (string) $type ) ) : '';
	if ( in_array( $type, array( 'franchise', 'franchising', 'partner', 'partners', 'partnership', 'partnerships' ), true ) ) {
		return new WP_Error( 'doughboss_franchising_paused', 'Franchising enquiries are currently unavailable.', array( 'status' => 503 ) );
	}
	return $result;
}, 10, 3 );
