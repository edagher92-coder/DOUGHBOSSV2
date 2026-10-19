<?php
/**
 * Dough Boss — menu seeder (thin wrapper).
 *
 * Populates the `doughboss_item` CPT with the in-store menu boards (Manoush,
 * Pizza, Pies, Wraps, Desserts, Drinks) so the WordPress storefront matches the
 * boards. The actual seeding lives in the shipped CLI command
 * `DoughBoss_CLI::seed_menu()` (so it works from any install of the plugin zip);
 * this file just lets you run it via `wp eval-file` if you prefer.
 *
 * Preferred — the registered WP-CLI command (ships in the plugin):
 *
 *     wp doughboss seed-menu
 *     wp doughboss seed-menu --dry-run
 *
 * Equivalent via this file:
 *
 *     wp eval-file scripts/seed-menu.php
 *     wp eval-file scripts/seed-menu.php dry-run
 *
 * Idempotent: items are matched by exact title and UPDATED (not duplicated).
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DoughBoss_CLI' ) ) {
	$msg = "DoughBoss_CLI is unavailable. Run via WP-CLI with the plugin active: wp doughboss seed-menu\n";
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::error( $msg );
	} else {
		echo $msg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	return;
}

$db_seed_dry = isset( $args ) && is_array( $args ) && in_array( 'dry-run', $args, true );
DoughBoss_CLI::seed_menu( array(), array( 'dry-run' => $db_seed_dry ) );
