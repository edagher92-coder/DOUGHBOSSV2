<?php
/**
 * Test stubs for the VIP waitlist and coming-soon module (WP-05).
 *
 * - DoughBoss_Locations: core's shop list as the module uses it (all( true )). Only defined when no earlier stub or the
 *   real class is loaded (stubs-consent.php defines the same minimal class; tests set DoughBoss_Locations::$rows and,
 *   to prove the failure path, ::$throw).
 * - get_page_by_path() and get_permalink(): the two WordPress page lookups the ribbon uses to find the published
 *   coming-soon page. Tests fill $GLOBALS['dbgr_pages_by_path'] (path => object with post_status and a "link" property).
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

$GLOBALS['dbgr_pages_by_path'] = array();

if ( ! function_exists( 'get_page_by_path' ) ) {
	/**
	 * @param string $path Page path.
	 * @return object|null
	 */
	function get_page_by_path( $path ) {
		return isset( $GLOBALS['dbgr_pages_by_path'][ $path ] ) ? $GLOBALS['dbgr_pages_by_path'][ $path ] : null;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	/**
	 * @param object $post Page.
	 * @return string
	 */
	function get_permalink( $post ) {
		return ( is_object( $post ) && isset( $post->link ) ) ? (string) $post->link : '';
	}
}
