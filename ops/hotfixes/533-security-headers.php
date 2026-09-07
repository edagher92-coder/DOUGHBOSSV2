// Dough Boss — baseline security headers (added pre-launch 2026-09-07).
// Deactivate this snippet in WPCode to remove every header at once.
add_filter( 'wp_headers', function ( $headers ) {
    $headers['Strict-Transport-Security'] = 'max-age=31536000';
    $headers['X-Content-Type-Options']    = 'nosniff';
    $headers['X-Frame-Options']           = 'SAMEORIGIN';
    $headers['Referrer-Policy']           = 'strict-origin-when-cross-origin';
    return $headers;
} );
add_action( 'admin_init', function () {
    if ( headers_sent() ) { return; }
    header( 'Strict-Transport-Security: max-age=31536000' );
    header( 'X-Content-Type-Options: nosniff' );
    header( 'X-Frame-Options: SAMEORIGIN' );
    header( 'Referrer-Policy: strict-origin-when-cross-origin' );
} );
add_filter( 'rest_post_dispatch', function ( $response ) {
    if ( $response instanceof WP_HTTP_Response ) {
        $response->header( 'Strict-Transport-Security', 'max-age=31536000' );
        $response->header( 'X-Content-Type-Options', 'nosniff' );
    }
    return $response;
}, 20 );
