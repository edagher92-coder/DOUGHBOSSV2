<?php
/**
 * Test stub for core's DoughBoss_Order as the attribution module (WP-04) uses it: DoughBoss_Order::get( $order_id ).
 *
 * Only defined when no other test stub or the real class is loaded. Tests set DoughBoss_Order::$rows (order id =>
 * object with payment_intent_id) and, to prove the failure path, ::$throw.
 *
 * @package DoughBoss_Growth
 */

if ( ! class_exists( 'DoughBoss_Order', false ) ) {
	/** Minimal stand-in for the core order lookup. */
	class DoughBoss_Order {
		/**
		 * Orders by id.
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
		 * Fetch an order row.
		 *
		 * @param int $order_id Order id.
		 * @return object|null
		 */
		public static function get( $order_id ) {
			if ( self::$throw ) {
				throw new RuntimeException( 'database error' );
			}
			return isset( self::$rows[ $order_id ] ) ? self::$rows[ $order_id ] : null;
		}
	}
}
