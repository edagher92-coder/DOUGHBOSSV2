<?php
/**
 * Test stub for the DoughBoss CORE plugin, as the companion sees it at plugins_loaded.
 *
 * The companion's core gate needs DOUGHBOSS_VERSION >= 2.41.0 and the class DoughBoss_Settings. This file
 * provides both so every test runs with core "installed" (version 2.43.2, the candidate) unless a sub-process
 * test overrides it: define the constant in the sub-process prelude (this file never redefines an existing
 * constant) or start the sub-process with $load_stubs = false to prove the "core absent" behaviour.
 *
 * Later work packages put THEIR stubs of core classes (DoughBoss_Locations, DoughBoss_Order, ...) in their own
 * tests/stubs-<module>.php, never in bootstrap.php.
 *
 * @package DoughBoss_Growth
 */

if ( ! defined( 'DOUGHBOSS_VERSION' ) ) {
	define( 'DOUGHBOSS_VERSION', '2.43.2' );
}

if ( ! class_exists( 'DoughBoss_Settings', false ) ) {
	/** Minimal stand-in: the gate only checks that the class exists. */
	class DoughBoss_Settings {
	}
}

if ( ! function_exists( 'dbgr_test_describe_tables' ) ) {
	/**
	 * Make the fake database answer "SHOW COLUMNS FROM `table`" as MySQL would for a table that dbDelta created correctly.
	 *
	 * DoughBoss_Growth_Activator::install() confirms every declared column, not just the table, so a test that expects
	 * install() to succeed must call this once (the answer comes from the real CREATE TABLE statements, or from the real
	 * SQLite table when the test uses use_sqlite()). A test that wants a failed ALTER registers its own responder
	 * INSTEAD: the first responder that matches wins.
	 *
	 * @return void
	 */
	function dbgr_test_describe_tables() {
		$GLOBALS['wpdb']->respond(
			'/^SHOW COLUMNS FROM `([A-Za-z0-9_]+)`/',
			function ( $sql ) {
				preg_match( '/^SHOW COLUMNS FROM `([A-Za-z0-9_]+)`/', $sql, $m );
				if ( $GLOBALS['wpdb']->is_sqlite() ) {
					$names = array();
					foreach ( $GLOBALS['wpdb']->sqlite_raw( 'PRAGMA table_info(' . $m[1] . ')' ) as $row ) {
						$names[] = $row['name'];
					}
					return $names;
				}
				$expected = DoughBoss_Growth_Activator::expected_columns();
				return isset( $expected[ $m[1] ] ) ? $expected[ $m[1] ] : array();
			}
		);
	}
}

if ( ! function_exists( 'wp_style_is' ) ) {
	/**
	 * Whether a style is enqueued, registered or done (printed). WordPress keeps "enqueued" true after printing and sets
	 * "done"; this stub does the same, so a test can tell a style that is queued for the footer from one already printed.
	 *
	 * @param string $handle Handle.
	 * @param string $list   enqueued, registered or done.
	 * @return bool
	 */
	function wp_style_is( $handle, $list = 'enqueued' ) {
		if ( ! isset( $GLOBALS['dbgr_assets']['styles'][ $handle ] ) ) {
			return false;
		}
		if ( 'registered' === $list ) {
			return true;
		}
		if ( 'done' === $list ) {
			return ! empty( $GLOBALS['dbgr_assets']['styles'][ $handle ]['done'] );
		}
		return (bool) $GLOBALS['dbgr_assets']['styles'][ $handle ]['enqueued'];
	}
}

if ( ! function_exists( 'wp_print_styles' ) ) {
	/**
	 * Print the link tag of each named style that is registered and not yet printed, and mark it done (WordPress prints a
	 * style at most once). With no argument it prints every enqueued style not yet done, as the footer does.
	 *
	 * @param string|string[]|false $handles Handle(s).
	 * @return string[] Handles printed.
	 */
	function wp_print_styles( $handles = false ) {
		$names = ( false === $handles ) ? array_keys( (array) $GLOBALS['dbgr_assets']['styles'] ) : (array) $handles;
		$done  = array();
		foreach ( $names as $handle ) {
			if ( ! isset( $GLOBALS['dbgr_assets']['styles'][ $handle ] ) || ! empty( $GLOBALS['dbgr_assets']['styles'][ $handle ]['done'] ) ) {
				continue;
			}
			if ( false === $handles && empty( $GLOBALS['dbgr_assets']['styles'][ $handle ]['enqueued'] ) ) {
				continue;
			}
			$style = $GLOBALS['dbgr_assets']['styles'][ $handle ];
			echo "<link rel='stylesheet' id='" . $handle . "-css' href='" . $style['src'] . '?ver=' . $style['ver'] . "' media='" . $style['media'] . "' />\n";
			$GLOBALS['dbgr_assets']['styles'][ $handle ]['done'] = true;
			$done[] = $handle;
		}
		return $done;
	}
}
