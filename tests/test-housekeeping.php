<?php
/**
 * 2.48.0 housekeeping: bounded Square Loyalty done markers and the Apple Pay
 * domain file moved out of the autoloaded settings array.
 *
 * Minimal option/transient shims below are in-memory only (no autoload or
 * expiry semantics); the real autoload flag is asserted through the recorded
 * add_option() arguments.
 *
 * @package DoughBoss
 */

$GLOBALS['doughboss_test_add_option_calls'] = array();
$GLOBALS['doughboss_test_transients']       = array();
$GLOBALS['doughboss_test_filters']          = array();

if ( ! function_exists( 'add_option' ) ) {
	/** @return bool */
	function add_option( $key, $value = '', $deprecated = '', $autoload = 'yes' ) {
		$GLOBALS['doughboss_test_add_option_calls'][] = array( $key, $autoload );
		if ( array_key_exists( $key, $GLOBALS['doughboss_test_options'] ) ) {
			return false;
		}
		$GLOBALS['doughboss_test_options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	/** @return bool */
	function delete_option( $key ) {
		unset( $GLOBALS['doughboss_test_options'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	/** @return bool */
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['doughboss_test_transients'][ $key ] = array( $value, $ttl );
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	/** @return mixed */
	function get_transient( $key ) {
		return isset( $GLOBALS['doughboss_test_transients'][ $key ] ) ? $GLOBALS['doughboss_test_transients'][ $key ][0] : false;
	}
}
if ( ! function_exists( 'has_filter' ) ) {
	/** @return bool */
	function has_filter( $hook ) {
		return ! empty( $GLOBALS['doughboss_test_filters'][ $hook ] );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-doughboss-square-loyalty.php';

db_test(
	'Square Loyalty done markers are 30-day transients, not permanent option rows',
	function () {
		$GLOBALS['doughboss_test_transients']       = array();
		$GLOBALS['doughboss_test_add_option_calls'] = array();
		assert_false( DoughBoss_Square_Loyalty::is_done( 77 ), 'fresh order is not done' );
		DoughBoss_Square_Loyalty::mark_done( 77, 'done' );
		assert_true( DoughBoss_Square_Loyalty::is_done( 77 ), 'marked done' );
		assert_same( 'doughboss_sqloy_done_77', DoughBoss_Square_Loyalty::done_key( 77 ), 'bounded key' );
		assert_same( 2592000, $GLOBALS['doughboss_test_transients']['doughboss_sqloy_done_77'][1], '30-day expiry' );
		assert_same( array(), $GLOBALS['doughboss_test_add_option_calls'], 'no wp_options row is added per order' );
		assert_false( array_key_exists( 'doughboss_sqloy_77', $GLOBALS['doughboss_test_options'] ), 'no legacy option written' );

		$GLOBALS['doughboss_test_options']['doughboss_sqloy_78'] = 'done|2026-10-01T00:00:00+00:00';
		assert_true( DoughBoss_Square_Loyalty::is_done( 78 ), 'legacy markers from 2.47.0 are still honoured' );
	}
);

db_test(
	'Apple Pay domain file migrates out of the autoloaded settings on read',
	function () {
		$GLOBALS['doughboss_test_add_option_calls'] = array();
		$GLOBALS['doughboss_test_filters']          = array();
		doughboss_test_set_settings( array( 'square_wallets_enabled' => 1, 'square_apple_pay_domain_file' => 'QUJDREVG' ) );
		assert_false( array_key_exists( 'square_apple_pay_domain_file', DoughBoss_Settings::defaults() ), 'not part of the autoloaded defaults' );
		assert_same( 'QUJDREVG', DoughBoss_Settings::square_apple_pay_domain_file(), 'legacy value is served' );
		assert_same( 'QUJDREVG', get_option( 'doughboss_square_apple_pay_domain_file' ), 'copied into its own option' );
		assert_same( array( array( 'doughboss_square_apple_pay_domain_file', 'no' ) ), $GLOBALS['doughboss_test_add_option_calls'], 'created with autoload off' );
		$settings = get_option( 'doughboss_settings' );
		assert_false( array_key_exists( 'square_apple_pay_domain_file', $settings ), 'legacy key stripped from the settings array' );
		assert_same( 1, $settings['square_wallets_enabled'], 'other settings untouched' );
		assert_same( 'QUJDREVG', DoughBoss_Settings::square_apple_pay_domain_file(), 'later reads use the new option' );
	}
);

db_test(
	'Apple Pay domain file migration never rewrites settings while a sanitizer is registered',
	function () {
		$GLOBALS['doughboss_test_filters'] = array( 'sanitize_option_doughboss_settings' => true );
		doughboss_test_set_settings( array( 'square_apple_pay_domain_file' => 'QUJD' ) );
		assert_same( 'QUJD', DoughBoss_Settings::square_apple_pay_domain_file(), 'value served' );
		assert_same( 'QUJD', get_option( 'doughboss_square_apple_pay_domain_file' ), 'copied' );
		assert_true( array_key_exists( 'square_apple_pay_domain_file', get_option( 'doughboss_settings' ) ), 'admin requests leave removal to the settings save' );
		$GLOBALS['doughboss_test_filters'] = array();

		DoughBoss_Settings::save_square_apple_pay_domain_file( '' );
		assert_same( '', DoughBoss_Settings::square_apple_pay_domain_file(), 'clearing the field clears the file' );
		doughboss_test_reset_options();
		assert_same( '', DoughBoss_Settings::square_apple_pay_domain_file(), 'nothing configured' );
	}
);
