<?php
/**
 * Test stubs for the conversions module (WP-08): core's DoughBoss_Catering::get() and WordPress's wp_timezone().
 *
 * DoughBoss_Order::get() is already stubbed by stubs-attribution.php (WP-04); tests set DoughBoss_Order::$rows to
 * order objects with order_number, total, currency, payment_status, customer_email and customer_phone.
 *
 * Only defined when no other test stub or the real class is loaded. Tests set DoughBoss_Catering::$rows (enquiry id =>
 * row array as core's get() returns it) and, to prove the failure path, ::$throw.
 *
 * @package DoughBoss_Growth
 */

if ( ! class_exists( 'DoughBoss_Catering', false ) ) {
	/** Minimal stand-in for the core catering lookup. */
	class DoughBoss_Catering {
		/**
		 * Enquiries by id.
		 *
		 * @var array
		 */
		public static $rows = array();

		/**
		 * When true, get() throws (a database failure).
		 *
		 * @var bool
		 */
		public static $throw = false;

		/**
		 * Fetch an enquiry row.
		 *
		 * @param int $id Enquiry id.
		 * @return array|null
		 */
		public static function get( $id ) {
			if ( self::$throw ) {
				throw new RuntimeException( 'database error' );
			}
			return isset( self::$rows[ $id ] ) ? self::$rows[ $id ] : null;
		}
	}
}

if ( ! function_exists( 'wp_timezone' ) ) {
	/**
	 * The site timezone. The tests run as the Sydney business: core stores enquiry times in this zone.
	 *
	 * @return DateTimeZone
	 */
	function wp_timezone() {
		return new DateTimeZone( 'Australia/Sydney' );
	}
}
