<?php
/**
 * Test stub for core's DoughBoss_Locations as the consent module (WP-03) uses it: DoughBoss_Locations::all( true ).
 *
 * Only defined when no other test stub or the real class is loaded. Tests set DoughBoss_Locations::$rows (objects
 * or arrays, as $wpdb->get_results() would return) and, to prove the failure path, ::$throw.
 *
 * @package DoughBoss_Growth
 */

if ( ! class_exists( 'DoughBoss_Locations', false ) ) {
	/** Minimal stand-in for the core shop list. */
	class DoughBoss_Locations {
		/**
		 * Rows returned by all().
		 *
		 * @var array|null
		 */
		public static $rows = array();

		/**
		 * When true, all() throws (a database failure).
		 *
		 * @var bool
		 */
		public static $throw = false;

		/**
		 * Shops.
		 *
		 * @param bool $active_only Active shops only.
		 * @return array|null
		 */
		public static function all( $active_only = false ) {
			if ( self::$throw ) {
				throw new RuntimeException( 'database error' );
			}
			return self::$rows;
		}
	}
}
