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
