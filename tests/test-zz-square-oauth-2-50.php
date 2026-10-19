<?php
/**
 * 2.50.0 — per-shop Square OAuth: state signing, authorize URL, refresh
 * scheduling, credential precedence, webhook merchant routing and the
 * master-menu copy planner. Pure / option-store only; no network.
 *
 * @package DoughBoss
 */

if ( ! function_exists( 'wp_json_encode' ) ) {
	/** @return string|false */
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	/** @return bool */
	function delete_option( $key ) {
		unset( $GLOBALS['doughboss_test_options'][ $key ] );
		return true;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-doughboss-square-oauth.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-square-catalog-copy.php';

/** Call a private static method. */
function db_oauth_private( $class, $method, array $args = array() ) {
	$ref = new ReflectionMethod( $class, $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( null, $args );
}

/** A connected record (plain-sealed tokens) for tests. */
function db_oauth_record( array $overrides = array() ) {
	return array_merge(
		array(
			'status'          => 'active',
			'merchant_id'     => 'MERCHBANKSTOWN01',
			'access_token'    => 'p1:EAAAoauthBankstownAccessToken000001',
			'refresh_token'   => 'p1:EQAAoauthBankstownRefreshToken00001',
			'expires_at'      => time() + 29 * 86400,
			'scopes'          => DoughBoss_Square_OAuth::SCOPES,
			'location_id'     => 'LBANKSTOWNOAUTH1',
			'connected_at'    => time(),
			'refreshed_at'    => time(),
			'last_attempt_at' => time(),
			'failures'        => 0,
			'last_error'      => '',
		),
		$overrides
	);
}

db_test( '2.50 OAuth state is signed, single-shop, user- and mode-bound, and expires', function () {
	$key   = 'test-key-1234567890';
	$nonce = str_repeat( 'ab', 16 );
	$now   = 1800000000;
	$state = DoughBoss_Square_OAuth::create_state( 'bankstown', 'test', 7, $nonce, $now, $key );
	assert_true( 1 === preg_match( '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $state ), 'state is URL-safe payload.mac' );
	$ok = DoughBoss_Square_OAuth::verify_state( $state, 7, 'test', $now + 60, $key );
	assert_same( array( 'shop' => 'bankstown', 'mode' => 'test', 'user' => 7, 'nonce' => $nonce ), $ok, 'valid state verifies to its exact claims' );
	assert_true( is_wp_error( DoughBoss_Square_OAuth::verify_state( $state, 8, 'test', $now, $key ) ), 'another WordPress user cannot finish it' );
	assert_true( is_wp_error( DoughBoss_Square_OAuth::verify_state( $state, 7, 'live', $now, $key ) ), 'a sandbox state cannot complete in live mode' );
	assert_true( is_wp_error( DoughBoss_Square_OAuth::verify_state( $state, 7, 'test', $now + DoughBoss_Square_OAuth::STATE_TTL + 1, $key ) ), 'expired state is refused' );
	assert_true( is_wp_error( DoughBoss_Square_OAuth::verify_state( $state, 7, 'test', $now, 'other-key-123456789' ) ), 'state signed with another key is refused' );
	list( $payload, $mac ) = explode( '.', $state );
	$forged = rtrim( strtr( base64_encode( json_encode( array( 'v' => 1, 's' => 'roselands', 'm' => 'test', 'u' => 7, 'e' => $now + 600, 'n' => $nonce ) ) ), '+/', '-_' ), '=' );
	assert_true( is_wp_error( DoughBoss_Square_OAuth::verify_state( $forged . '.' . $mac, 7, 'test', $now, $key ) ), 'payload swapped to another shop fails the MAC' );
	assert_true( is_wp_error( DoughBoss_Square_OAuth::verify_state( $payload . '.' . strrev( $mac ), 7, 'test', $now, $key ) ), 'tampered MAC fails' );
	foreach ( array( null, '', 'x', $state . '.x', array( $state ), str_repeat( 'a', 3000 ) ) as $bad ) {
		assert_true( is_wp_error( DoughBoss_Square_OAuth::verify_state( $bad, 7, 'test', $now, $key ) ), 'malformed state is refused' );
	}
	assert_same( '', DoughBoss_Square_OAuth::create_state( 'planned', 'test', 7, $nonce, $now, $key ), 'unknown shop cannot be signed' );
	assert_same( '', DoughBoss_Square_OAuth::create_state( 'bankstown', 'test', 0, $nonce, $now, $key ), 'anonymous user cannot be signed' );
	assert_same( '', DoughBoss_Square_OAuth::create_state( 'bankstown', 'test', 7, 'short', $now, $key ), 'weak nonce cannot be signed' );
} );

db_test( '2.50 authorize URL uses the right host, scopes, state and session rule', function () {
	$state   = str_repeat( 'a', 20 ) . '.' . str_repeat( 'b', 20 );
	$sandbox = DoughBoss_Square_OAuth::authorize_url( 'test', 'sandbox-sq0idb-EXAMPLETESTAPPID000000', $state );
	assert_true( 0 === strpos( $sandbox, 'https://connect.squareupsandbox.com/oauth2/authorize?client_id=sandbox-sq0idb-EXAMPLETESTAPPID000000&scope=' ), 'sandbox host and client id' );
	assert_false( strpos( $sandbox, 'session=' ), 'session parameter is omitted in the Sandbox (no effect there)' );
	$live = DoughBoss_Square_OAuth::authorize_url( 'live', 'sq0idp-EXAMPLELIVEAPPID0000000', $state );
	assert_true( 0 === strpos( $live, 'https://connect.squareup.com/oauth2/authorize?' ), 'production host' );
	assert_true( false !== strpos( $live, '&session=false&' ), 'production forces a fresh Square sign-in' );
	parse_str( (string) parse_url( $live, PHP_URL_QUERY ), $query );
	assert_same( implode( ' ', DoughBoss_Square_OAuth::SCOPES ), $query['scope'], 'scope is the exact space-separated list' );
	assert_same( $state, $query['state'], 'state round-trips' );
	foreach ( array( 'PAYMENTS_WRITE', 'ORDERS_WRITE', 'CUSTOMERS_WRITE', 'INVOICES_WRITE', 'LOYALTY_WRITE', 'ITEMS_WRITE', 'SUBSCRIPTIONS_READ', 'MERCHANT_PROFILE_READ' ) as $scope ) {
		assert_true( in_array( $scope, explode( ' ', $query['scope'] ), true ), 'requests ' . $scope );
	}
	assert_same( '', DoughBoss_Square_OAuth::authorize_url( 'prod', 'sq0idp-EXAMPLE', $state ), 'unknown mode builds nothing' );
	assert_same( '', DoughBoss_Square_OAuth::authorize_url( 'live', 'bad id!', $state ), 'bad client id builds nothing' );
	assert_same( '', DoughBoss_Square_OAuth::authorize_url( 'live', 'sq0idp-EXAMPLELIVEAPPID0000000', 'x"><script>' ), 'unsafe state builds nothing' );
} );

db_test( '2.50 token refresh scheduling follows Square guidance (≤7 days) with backoff', function () {
	$now  = 1800000000;
	$base = array( 'status' => 'active', 'refresh_token' => 'p1:x', 'expires_at' => $now + 29 * 86400, 'refreshed_at' => $now - 3600, 'failures' => 0, 'last_attempt_at' => $now - 3600 );
	assert_same( 'skip', DoughBoss_Square_OAuth::refresh_decision( $base, $now ), 'fresh token is left alone' );
	assert_same( 'refresh', DoughBoss_Square_OAuth::refresh_decision( array_merge( $base, array( 'expires_at' => $now + 22 * 86400 ) ), $now ), 'token older than ~7 days (23 days left) refreshes' );
	assert_same( 'refresh', DoughBoss_Square_OAuth::refresh_decision( array_merge( $base, array( 'refreshed_at' => $now - 7 * 86400 ) ), $now ), 'last refresh 7 days ago refreshes' );
	assert_same( 'refresh', DoughBoss_Square_OAuth::refresh_decision( array_merge( $base, array( 'expires_at' => 0 ) ), $now ), 'unknown expiry refreshes' );
	assert_same( 'skip', DoughBoss_Square_OAuth::refresh_decision( array_merge( $base, array( 'status' => 'revoked', 'expires_at' => 0 ) ), $now ), 'revoked connection never refreshes' );
	assert_same( 'skip', DoughBoss_Square_OAuth::refresh_decision( array_merge( $base, array( 'refresh_token' => '', 'expires_at' => 0 ) ), $now ), 'no refresh token, nothing to do' );
	$failing = array_merge( $base, array( 'expires_at' => $now + 10 * 86400, 'failures' => 2, 'last_attempt_at' => $now - 600 ) );
	assert_same( 'backoff', DoughBoss_Square_OAuth::refresh_decision( $failing, $now ), 'recent failure backs off' );
	assert_same( 'refresh', DoughBoss_Square_OAuth::refresh_decision( array_merge( $failing, array( 'last_attempt_at' => $now - 7200 ) ), $now ), 'backoff elapses' );
	$urgent = array_merge( $failing, array( 'expires_at' => $now + 3600, 'last_attempt_at' => $now - 120 ) );
	assert_same( 'refresh', DoughBoss_Square_OAuth::refresh_decision( $urgent, $now ), 'a token about to expire is retried quickly' );
	assert_same( 'backoff', DoughBoss_Square_OAuth::refresh_decision( array_merge( $urgent, array( 'last_attempt_at' => $now - 10 ) ), $now ), 'but never hammered within a minute' );
	assert_same( array( 'bankstown' ), ( function () use ( $now ) {
		update_option( DoughBoss_Square_OAuth::OPTION, array( 'test' => array( 'bankstown' => db_oauth_record( array( 'refreshed_at' => $now - 9 * 86400 ) ), 'roselands' => db_oauth_record( array( 'merchant_id' => 'MERCHROSELANDS01', 'refreshed_at' => $now ) ) ) ) );
		doughboss_test_set_settings( db_square_settings() );
		$stale = DoughBoss_Square_OAuth::stale_shops( $now );
		delete_option( DoughBoss_Square_OAuth::OPTION );
		return $stale;
	} )(), 'owner is warned when a token has not refreshed for over 8 days' );
} );

db_test( '2.50 credential resolution: own OAuth seller when connected, legacy settings otherwise', function () {
	$original = get_option( 'doughboss_settings', array() );
	$maps     = array( 'revesby' => 'LREVESBYLEGACY01', 'bankstown' => 'LBANKSTOWNLEGACY', 'roselands' => 'LROSELANDSLEGACY' );
	try {
		doughboss_test_set_settings( db_square_settings( array( 'square_test_location_map' => $maps ) ) );
		delete_option( DoughBoss_Square_OAuth::OPTION );
		$row = static function ( $id, $slug ) {
			return (object) array( 'id' => $id, 'slug' => $slug, 'is_active' => 1 );
		};
		// Zero config: exactly the 2.49 binding shape and the legacy token.
		$revesby = db_oauth_private( 'DoughBoss_Square', 'shop_binding_from_location', array( $row( 1, 'revesby' ), 1 ) );
		assert_same( array( 'shop_slug' => 'revesby', 'square_location_id' => 'LREVESBYLEGACY01', 'square_mode' => 'test' ), $revesby, 'unconnected shop keeps the exact legacy three-key binding' );
		assert_same( 'EAAAtestExampleSandboxAccessToken0000', db_oauth_private( 'DoughBoss_Square', 'access_token', array( $revesby ) ), 'unconnected shop uses the settings token' );
		assert_true( DoughBoss_Settings::square_shop_ready( 'bankstown' ), 'legacy mapped shop readiness unchanged' );

		// Bankstown connects its own account and picks a location.
		update_option( DoughBoss_Square_OAuth::OPTION, array( 'test' => array( 'bankstown' => db_oauth_record() ) ) );
		$bank = db_oauth_private( 'DoughBoss_Square', 'shop_binding_from_location', array( $row( 2, 'bankstown' ), 2 ) );
		assert_same( array( 'shop_slug' => 'bankstown', 'square_location_id' => 'LBANKSTOWNOAUTH1', 'square_mode' => 'test', 'merchant_id' => 'MERCHBANKSTOWN01' ), $bank, 'connected shop binds its own location and carries its merchant id' );
		assert_same( 'EAAAoauthBankstownAccessToken000001', db_oauth_private( 'DoughBoss_Square', 'access_token', array( $bank ) ), 'connected shop uses its own seller token' );
		assert_same( 'EAAAtestExampleSandboxAccessToken0000', db_oauth_private( 'DoughBoss_Square', 'access_token', array( null ) ), 'legacy (unbound) calls keep the settings token' );
		assert_same( 'EAAAtestExampleSandboxAccessToken0000', db_oauth_private( 'DoughBoss_Square', 'access_token', array( $revesby ) ), 'Revesby is unaffected by another shop connecting' );
		assert_true( db_oauth_private( 'DoughBoss_Square', 'mapped_binding_current', array( $bank ) ), 'connected binding is current' );
		assert_false( db_oauth_private( 'DoughBoss_Square', 'mapped_binding_current', array( array_merge( $bank, array( 'merchant_id' => 'MERCHOTHER00001' ) ) ) ), 'a binding naming another seller is refused' );
		assert_true( DoughBoss_Settings::square_shop_ready( 'bankstown' ), 'connected shop with location is ready' );

		$fingerprint = db_oauth_private( 'DoughBoss_Square', 'credential_fingerprint', array( $bank ) );
		update_option( DoughBoss_Square_OAuth::OPTION, array( 'test' => array( 'bankstown' => db_oauth_record( array( 'access_token' => 'p1:EAAAoauthBankstownRefreshedToken02' ) ) ) ) );
		assert_same( $fingerprint, db_oauth_private( 'DoughBoss_Square', 'credential_fingerprint', array( $bank ) ), 'a token refresh keeps the same seller context' );
		update_option( DoughBoss_Square_OAuth::OPTION, array( 'test' => array( 'bankstown' => db_oauth_record( array( 'merchant_id' => 'MERCHREPLACED001' ) ) ) ) );
		assert_false( $fingerprint === db_oauth_private( 'DoughBoss_Square', 'credential_fingerprint', array( $bank ) ), 'reconnecting another seller changes the context' );
		assert_false( db_oauth_private( 'DoughBoss_Square', 'mapped_binding_current', array( $bank ) ), 'an in-flight binding for the previous seller is no longer current' );

		// Connected but location not yet picked: never inherits the legacy map row.
		update_option( DoughBoss_Square_OAuth::OPTION, array( 'test' => array( 'bankstown' => db_oauth_record( array( 'location_id' => '' ) ) ) ) );
		assert_same( '', DoughBoss_Settings::square_shop_location_id( 'bankstown' ), 'unpicked connection has no location' );
		assert_false( DoughBoss_Settings::square_shop_ready( 'bankstown' ), 'and is not ready' );

		// Expired / unreadable OAuth token: fail closed, never fall back to the legacy token.
		update_option( DoughBoss_Square_OAuth::OPTION, array( 'test' => array( 'bankstown' => db_oauth_record( array( 'expires_at' => time() - 10 ) ) ) ) );
		assert_false( DoughBoss_Settings::square_shop_ready( 'bankstown' ), 'expired seller token is not ready' );
		assert_same( '', db_oauth_private( 'DoughBoss_Square', 'access_token', array( $bank ) ), 'expired seller token is never sent' );
		update_option( DoughBoss_Square_OAuth::OPTION, array( 'test' => array( 'bankstown' => db_oauth_record( array( 'access_token' => 's1:garbage' ) ) ) ) );
		assert_false( DoughBoss_Settings::square_shop_ready( 'bankstown' ), 'unreadable sealed token is not ready' );

		// OAuth shop readiness does not depend on the legacy token.
		update_option( DoughBoss_Square_OAuth::OPTION, array( 'test' => array( 'bankstown' => db_oauth_record() ) ) );
		doughboss_test_set_settings( db_square_settings( array( 'square_test_location_map' => $maps, 'square_test_access_token' => '' ) ) );
		assert_true( DoughBoss_Settings::square_shop_ready( 'bankstown' ), 'connected shop works without a legacy token' );
		assert_false( DoughBoss_Settings::square_shop_ready( 'revesby' ), 'legacy shop still needs the legacy token' );
		doughboss_test_set_settings( db_square_settings( array( 'square_test_location_map' => $maps ) ) );

		// Revoked: back to the legacy configuration for that shop.
		update_option( DoughBoss_Square_OAuth::OPTION, array( 'test' => array( 'bankstown' => db_oauth_record( array( 'status' => 'revoked', 'access_token' => '', 'refresh_token' => '' ) ) ) ) );
		assert_same( 'LBANKSTOWNLEGACY', DoughBoss_Settings::square_shop_location_id( 'bankstown' ), 'revoked shop reverts to its legacy map row' );
		assert_false( array_key_exists( 'merchant_id', db_oauth_private( 'DoughBoss_Square', 'shop_binding_from_location', array( $row( 2, 'bankstown' ), 2 ) ) ), 'revoked shop binding is legacy-shaped again' );

		// Live-mode connections are separate from sandbox ones.
		update_option( DoughBoss_Square_OAuth::OPTION, array( 'test' => array( 'bankstown' => db_oauth_record() ) ) );
		doughboss_test_set_settings( db_square_settings( array( 'square_mode' => 'live', 'square_live_location_map' => $maps ) ) );
		assert_false( DoughBoss_Square_OAuth::is_connected( 'bankstown' ), 'a sandbox connection does not exist in live mode' );
		assert_same( 'LBANKSTOWNLEGACY', DoughBoss_Settings::square_shop_location_id( 'bankstown' ), 'live mode uses its own (legacy) mapping' );

		// A connected location equal to another shop's legacy ID removes both.
		doughboss_test_set_settings( db_square_settings( array( 'square_test_location_map' => $maps ) ) );
		update_option( DoughBoss_Square_OAuth::OPTION, array( 'test' => array( 'bankstown' => db_oauth_record( array( 'location_id' => 'LREVESBYLEGACY01' ) ) ) ) );
		assert_same( '', DoughBoss_Settings::square_shop_location_id( 'bankstown' ), 'duplicate provider location is unmapped (connected shop)' );
		assert_same( '', DoughBoss_Settings::square_shop_location_id( 'revesby' ), 'duplicate provider location is unmapped (legacy shop)' );
	} finally {
		delete_option( DoughBoss_Square_OAuth::OPTION );
		doughboss_test_set_settings( $original );
	}
} );

db_test( '2.50 webhook events route by merchant_id', function () {
	$connections = array(
		'bankstown' => db_oauth_record(),
		'roselands' => db_oauth_record( array( 'merchant_id' => 'MERCHROSELANDS01', 'status' => 'revoked' ) ),
	);
	assert_same( array( 'route' => 'shop', 'shop' => 'bankstown' ), DoughBoss_Square_OAuth::route_merchant( 'MERCHBANKSTOWN01', $connections, 'MERCHREVESBY0001' ), 'connected seller routes to its shop' );
	assert_same( array( 'route' => 'legacy', 'shop' => '' ), DoughBoss_Square_OAuth::route_merchant( 'MERCHREVESBY0001', $connections, 'MERCHREVESBY0001' ), 'single-account seller keeps the 2.49 path' );
	assert_same( 'ignore', DoughBoss_Square_OAuth::route_merchant( 'MERCHSTRANGER001', $connections, 'MERCHREVESBY0001' )['route'], 'unknown seller is ignored once the legacy seller is known' );
	assert_same( 'ignore', DoughBoss_Square_OAuth::route_merchant( 'MERCHROSELANDS01', $connections, '' )['route'], 'a disconnected shop\'s late events are ignored' );
	assert_same( 'legacy', DoughBoss_Square_OAuth::route_merchant( 'MERCHSTRANGER001', $connections, '' )['route'], 'legacy seller not yet detected: unknown merchant keeps 2.49 behaviour' );
	assert_same( 'legacy', DoughBoss_Square_OAuth::route_merchant( '', $connections, 'MERCHREVESBY0001' )['route'], 'payload without merchant_id keeps 2.49 behaviour' );
	assert_same( 'ignore', DoughBoss_Square_OAuth::route_merchant( 'bad merchant!', $connections, '' )['route'], 'malformed merchant id is ignored' );
	assert_same( 'shop', DoughBoss_Square_OAuth::route_merchant( 'MERCHREVESBY0001', array( 'revesby' => db_oauth_record( array( 'merchant_id' => 'MERCHREVESBY0001' ) ) ), 'MERCHREVESBY0001' )['route'], 'Revesby connected by OAuth routes to its shop' );
} );

db_test( '2.50 revocation webhook disconnects only that seller', function () {
	$original = get_option( 'doughboss_settings', array() );
	try {
		doughboss_test_set_settings( db_square_settings() );
		update_option( DoughBoss_Square_OAuth::OPTION, array( 'test' => array( 'bankstown' => db_oauth_record(), 'roselands' => db_oauth_record( array( 'merchant_id' => 'MERCHROSELANDS01' ) ) ) ) );
		assert_same( 0, DoughBoss_Square_OAuth::handle_revocation( 'MERCHSTRANGER001' ), 'unknown merchant changes nothing' );
		assert_same( 1, DoughBoss_Square_OAuth::handle_revocation( 'MERCHBANKSTOWN01' ), 'the revoked seller is disconnected' );
		assert_false( DoughBoss_Square_OAuth::is_connected( 'bankstown' ), 'bankstown is no longer connected' );
		assert_true( DoughBoss_Square_OAuth::is_connected( 'roselands' ), 'roselands is untouched' );
		$record = DoughBoss_Square_OAuth::record( 'bankstown' );
		assert_same( '', $record['access_token'] . $record['refresh_token'], 'revoked tokens are wiped' );
		assert_same( 1, count( DoughBoss_Square_OAuth::notices() ), 'owner gets an admin notice' );
	} finally {
		delete_option( DoughBoss_Square_OAuth::OPTION );
		delete_option( DoughBoss_Square_OAuth::NOTICE_OPTION );
		doughboss_test_set_settings( $original );
	}
} );

db_test( '2.50 token responses, duplicate sellers and sealing', function () {
	$good = array( 'access_token' => 'EAAAoauthAccessToken0000000001', 'token_type' => 'bearer', 'expires_at' => '2026-11-15T00:00:00Z', 'merchant_id' => 'MERCHBANKSTOWN01', 'refresh_token' => 'EQAAoauthRefreshToken000000001', 'short_lived' => false );
	$ok   = DoughBoss_Square_OAuth::token_response( $good );
	assert_same( 'MERCHBANKSTOWN01', $ok['merchant_id'], 'valid ObtainToken response accepted' );
	assert_same( strtotime( '2026-11-15T00:00:00Z' ), $ok['expires_at'], 'expiry parsed' );
	assert_true( is_wp_error( DoughBoss_Square_OAuth::token_response( $good, 'MERCHOTHER00001' ) ), 'a refresh returning another merchant is refused' );
	foreach ( array( 'access_token', 'refresh_token', 'merchant_id', 'expires_at' ) as $field ) {
		$bad = $good;
		unset( $bad[ $field ] );
		assert_true( is_wp_error( DoughBoss_Square_OAuth::token_response( $bad ) ), 'missing ' . $field . ' is refused' );
	}
	assert_true( is_wp_error( DoughBoss_Square_OAuth::token_response( array_merge( $good, array( 'short_lived' => true ) ) ) ), 'short-lived tokens are refused' );
	assert_true( is_wp_error( DoughBoss_Square_OAuth::token_response( array( 'errors' => array( array( 'code' => 'UNAUTHORIZED' ) ) ) ) ), 'error body is refused' );

	$connections = array( 'roselands' => db_oauth_record( array( 'merchant_id' => 'MERCHROSELANDS01' ) ) );
	assert_same( 'roselands', DoughBoss_Square_OAuth::duplicate_owner( 'bankstown', 'MERCHROSELANDS01', $connections, '' ), 'one seller cannot serve two shops' );
	assert_same( 'revesby', DoughBoss_Square_OAuth::duplicate_owner( 'bankstown', 'MERCHREVESBY0001', $connections, 'MERCHREVESBY0001' ), 'connecting the Revesby account to Bankstown by mistake is refused' );
	assert_same( '', DoughBoss_Square_OAuth::duplicate_owner( 'revesby', 'MERCHREVESBY0001', $connections, 'MERCHREVESBY0001' ), 'Revesby may move its own account to OAuth' );
	assert_same( '', DoughBoss_Square_OAuth::duplicate_owner( 'roselands', 'MERCHROSELANDS01', $connections, '' ), 'reconnecting the same shop is fine' );

	assert_same( 'EAAAplainToken000000001', DoughBoss_Square_OAuth::open( DoughBoss_Square_OAuth::seal( 'EAAAplainToken000000001' ) ), 'plain storage round-trips without an at-rest key' );
	if ( function_exists( 'sodium_crypto_secretbox' ) && ! defined( 'DOUGHBOSS_SQUARE_TOKEN_KEY' ) ) {
		define( 'DOUGHBOSS_SQUARE_TOKEN_KEY', 'unit-test-at-rest-key-000000000000' );
		$sealed = DoughBoss_Square_OAuth::seal( 'EAAAsealedToken00000001' );
		assert_true( 0 === strpos( $sealed, 's1:' ) && false === strpos( $sealed, 'EAAAsealedToken' ), 'with a key, tokens are encrypted at rest' );
		assert_same( 'EAAAsealedToken00000001', DoughBoss_Square_OAuth::open( $sealed ), 'sealed token opens with the key' );
		assert_same( '', DoughBoss_Square_OAuth::open( substr( $sealed, 0, -2 ) . 'AA' ), 'tampered ciphertext does not open' );
	}
} );

db_test( '2.50 master-menu copy plan: temp ids, two phases, tax mapping, skips', function () {
	$source = array(
		array( 'type' => 'CATEGORY', 'id' => 'CATPIZZA', 'version' => 5, 'category_data' => array( 'name' => 'Pizza', 'is_top_level' => true ) ),
		array( 'type' => 'CATEGORY', 'id' => 'CATSUB', 'category_data' => array( 'name' => 'Vegan', 'parent_category' => array( 'id' => 'CATPIZZA', 'ordinal' => 1 ) ) ),
		array( 'type' => 'TAX', 'id' => 'TAXGST', 'tax_data' => array( 'name' => 'GST', 'percentage' => '10.0' ) ),
		array( 'type' => 'TAX', 'id' => 'TAXOTHER', 'tax_data' => array( 'name' => 'Levy', 'percentage' => '2' ) ),
		array( 'type' => 'MODIFIER_LIST', 'id' => 'MLEXTRA', 'present_at_location_ids' => array( 'LREV' ), 'modifier_list_data' => array( 'name' => 'Extras', 'selection_type' => 'MULTIPLE',
			'modifiers' => array( array( 'type' => 'MODIFIER', 'id' => 'MODCHEESE', 'modifier_data' => array( 'name' => 'Cheese', 'price_money' => array( 'amount' => 200, 'currency' => 'AUD' ), 'modifier_list_id' => 'MLEXTRA', 'image_id' => 'IMG1' ) ) ) ) ),
		array( 'type' => 'ITEM', 'id' => 'ITMARG', 'updated_at' => 'x', 'item_data' => array( 'name' => 'Margherita', 'description' => 'Classic', 'image_ids' => array( 'IMG2' ), 'tax_ids' => array( 'TAXGST', 'TAXOTHER' ),
			'categories' => array( array( 'id' => 'CATPIZZA' ) ), 'reporting_category' => array( 'id' => 'CATPIZZA' ),
			'modifier_list_info' => array( array( 'modifier_list_id' => 'MLEXTRA', 'enabled' => true, 'modifier_overrides' => array( array( 'modifier_id' => 'MODCHEESE', 'on_by_default' => true ) ) ) ),
			'variations' => array( array( 'type' => 'ITEM_VARIATION', 'id' => 'VARLARGE', 'item_variation_data' => array( 'item_id' => 'ITMARG', 'name' => 'Large', 'pricing_type' => 'FIXED_PRICING', 'price_money' => array( 'amount' => 2200, 'currency' => 'AUD' ), 'location_overrides' => array( array( 'location_id' => 'LREV' ) ) ) ) ) ) ),
		array( 'type' => 'ITEM', 'id' => 'ITGIFT', 'item_data' => array( 'name' => 'Gift', 'product_type' => 'GIFT_CARD', 'variations' => array( array( 'id' => 'VARG', 'item_variation_data' => array( 'name' => 'x' ) ) ) ) ),
		array( 'type' => 'ITEM', 'id' => 'ITOLD', 'item_data' => array( 'name' => 'Already copied', 'variations' => array( array( 'id' => 'VAROLD', 'item_variation_data' => array( 'name' => 'x' ) ) ) ) ),
		array( 'type' => 'ITEM', 'id' => 'ITDEL', 'is_deleted' => true, 'item_data' => array( 'name' => 'Deleted' ) ),
	);
	$target_taxes = array( array( 'type' => 'TAX', 'id' => 'TGTGST', 'tax_data' => array( 'name' => 'gst', 'percentage' => '10' ) ) );
	$plan = DoughBoss_Square_Catalog_Copy::build_plan( $source, $target_taxes, array( 'ITOLD' => 'TGTOLD' ) );
	assert_same( array( 'categories' => 2, 'modifier_lists' => 1, 'modifiers' => 1, 'items' => 1, 'variations' => 1, 'skipped_existing' => 1, 'skipped_other' => 1 ), $plan['counts'], 'counts' );
	assert_same( array( 'type' => 'CATEGORY', 'id' => '#CATPIZZA', 'present_at_all_locations' => true, 'category_data' => array( 'name' => 'Pizza' ) ), $plan['categories'][0], 'category cleaned: temp id, no version/read-only fields' );
	assert_same( array( 'id' => '#CATPIZZA' ), $plan['categories'][1]['category_data']['parent_category'], 'parent link uses the same-batch temp id' );
	$list = $plan['modifier_lists'][0];
	assert_false( isset( $list['present_at_location_ids'] ), 'source location presence is not copied' );
	assert_same( '#MLEXTRA', $list['modifier_list_data']['modifiers'][0]['modifier_data']['modifier_list_id'], 'nested modifier points at its list temp id' );
	assert_false( isset( $list['modifier_list_data']['modifiers'][0]['modifier_data']['image_id'] ), 'modifier image not copied' );
	$item = $plan['items'][0];
	assert_same( array( 'TGTGST' ), $item['item_data']['tax_ids'], 'GST mapped to the target seller\'s own tax; unmatched tax dropped' );
	assert_same( array( array( 'id' => '@CATPIZZA' ) ), $item['item_data']['categories'], 'phase-two category ref is a placeholder' );
	assert_same( '#ITMARG', $item['item_data']['variations'][0]['item_variation_data']['item_id'], 'variation points at its item temp id' );
	assert_false( isset( $item['item_data']['image_ids'] ) || isset( $item['item_data']['variations'][0]['item_variation_data']['location_overrides'] ), 'images and location overrides not copied' );
	assert_true( count( $plan['warnings'] ) >= 4, 'tax, gift card, images and overrides are reported in the preview' );

	$map      = array( 'CATPIZZA' => 'TCATPIZZA', 'MLEXTRA' => 'TMLEXTRA', 'MODCHEESE' => 'TMODCHEESE' );
	$resolved = DoughBoss_Square_Catalog_Copy::resolve_item( $item, $map );
	assert_same( array( array( 'id' => 'TCATPIZZA' ) ), $resolved['item_data']['categories'], 'category placeholder resolved to the real target id' );
	assert_same( array( 'id' => 'TCATPIZZA' ), $resolved['item_data']['reporting_category'], 'reporting category resolved' );
	assert_same( 'TMLEXTRA', $resolved['item_data']['modifier_list_info'][0]['modifier_list_id'], 'modifier list resolved' );
	assert_same( 'TMODCHEESE', $resolved['item_data']['modifier_list_info'][0]['modifier_overrides'][0]['modifier_id'], 'modifier override resolved' );
	$unresolved = DoughBoss_Square_Catalog_Copy::resolve_item( $item, array() );
	assert_false( isset( $unresolved['item_data']['categories'] ) || isset( $unresolved['item_data']['modifier_list_info'] ) || isset( $unresolved['item_data']['reporting_category'] ), 'references that failed to copy are dropped, never sent as placeholders' );

	assert_same( array( 'CATPIZZA' => 'TCATPIZZA', 'VARLARGE' => 'TVAR' ), DoughBoss_Square_Catalog_Copy::mappings( array( 'id_mappings' => array( array( 'client_object_id' => '#CATPIZZA', 'object_id' => 'TCATPIZZA' ), array( 'client_object_id' => '#VARLARGE', 'object_id' => 'TVAR' ), array( 'client_object_id' => 'NOHASH', 'object_id' => 'X' ) ) ) ), 'id_mappings parsed for temp ids only' );

	$many = array();
	for ( $i = 0; $i < 700; ++$i ) {
		$many[] = array( 'type' => 'ITEM', 'id' => '#I' . $i, 'item_data' => array( 'variations' => array( array(), array() ) ) );
	}
	$requests = DoughBoss_Square_Catalog_Copy::chunk( $many );
	$weights  = array();
	foreach ( $requests as $batches ) {
		foreach ( $batches as $batch ) {
			$weights[] = array_sum( array_map( array( 'DoughBoss_Square_Catalog_Copy', 'weight' ), $batch ) );
		}
	}
	assert_true( max( $weights ) <= 1000, 'no batch exceeds 1,000 objects including nested variations' );
	assert_same( 2100, array_sum( $weights ), 'every object is sent exactly once' );
	assert_same( 1, count( $requests ), 'small menus go in one request' );
} );
