<?php
/** Disabled legacy rewards must not expose account enrollment or sign-in. */
require_once dirname( __DIR__ ) . '/includes/class-doughboss-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-loyalty.php';

class DoughBoss_Test_Loyalty_Redirect extends RuntimeException {}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $key, $value, $url ) {
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( $key ) . '=' . rawurlencode( $value );
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( $url, $status = 302, $by = 'WordPress' ) {
		if ( ! empty( $GLOBALS['db_test_loyalty_capture_redirect'] ) ) {
			throw new DoughBoss_Test_Loyalty_Redirect( $url );
		}
		return false;
	}
}

db_test( 'Disabled rewards render only availability information, never enrollment or a wallet', function () {
	$saved_options = $GLOBALS['doughboss_test_options'];
	$saved_get = $_GET;
	try {
		foreach ( array( array(), array( 'loyalty_enabled' => 0 ) ) as $settings ) {
			doughboss_test_set_settings( $settings );
			$_GET = array( 'db_loyalty' => 'linked' );
			$html = DoughBoss_Loyalty::shortcode();
			assert_true( false !== strpos( $html, 'Membership sign-up and rewards are not open yet.' ), 'closed status is explicit' );
			assert_true( false !== strpos( $html, esc_url( home_url( '/members-events/' ) ) ), 'the informational members hub remains reachable' );
			assert_false( false !== strpos( $html, '<form' ), 'no public account form' );
			assert_false( false !== strpos( $html, 'db-loyalty__wallet' ), 'no wallet or member creation' );
			assert_false( false !== strpos( $html, 'waitlist' ), 'no unsupported enrollment promise' );
			assert_false( false !== strpos( $html, 'Earn 1 point' ), 'no inactive reward promise' );
			assert_false( false !== strpos( $html, 'You are signed in' ), 'query notices cannot imply a successful sign-in' );
		}
	} finally {
		$GLOBALS['doughboss_test_options'] = $saved_options;
		$_GET = $saved_get;
	}
} );

db_test( 'Disabled rewards refuse email enrollment and token login before any database work', function () {
	$saved_options = $GLOBALS['doughboss_test_options'];
	$saved_post = $_POST;
	$saved_get = $_GET;
	$had_db = array_key_exists( 'wpdb', $GLOBALS );
	$saved_db = $GLOBALS['wpdb'] ?? null;
	$mail_count = count( $GLOBALS['db_fe_mail'] ?? array() );
	$database = new class {
		public $calls = 0;
		public function __call( $name, $arguments ) {
			$this->calls++;
			throw new RuntimeException( 'Disabled rewards reached database work: ' . $name );
		}
	};
	try {
		$GLOBALS['wpdb'] = $database;
		$GLOBALS['db_test_loyalty_capture_redirect'] = true;
		$_POST = array( '_wpnonce' => 'synthetic', 'email' => 'member@example.invalid', 'marketing_consent' => '1' );
		$_GET = array( 's' => 'synthetic-selector', 't' => str_repeat( 'a', 64 ) );
		foreach ( array( array(), array( 'loyalty_enabled' => 0 ) ) as $settings ) {
			doughboss_test_set_settings( $settings );
			foreach ( array( 'request_link', 'magic_link' ) as $method ) {
				$redirect = '';
				try {
					( new DoughBoss_Loyalty() )->$method();
				} catch ( DoughBoss_Test_Loyalty_Redirect $exception ) {
					$redirect = $exception->getMessage();
				}
				assert_true( false !== strpos( $redirect, 'db_loyalty=unavailable' ), $method . ' refuses disabled access' );
			}
		}
		assert_same( 0, $database->calls, 'no token read/consumption or member database work' );
		assert_same( $mail_count, count( $GLOBALS['db_fe_mail'] ?? array() ), 'no sign-in email sent' );
	} finally {
		$GLOBALS['doughboss_test_options'] = $saved_options;
		if ( $had_db ) {
			$GLOBALS['wpdb'] = $saved_db;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		unset( $GLOBALS['db_test_loyalty_capture_redirect'] );
		$_POST = $saved_post;
		$_GET = $saved_get;
	}
} );
