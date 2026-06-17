<?php
/**
 * Versioned database/upgrade migrations.
 *
 * Runs on load whenever the stored schema version is behind the code's
 * DOUGHBOSS_DB_VERSION. Covers sites updated via file copy (where the
 * activation hook never fires) and provides ordered, version-aware steps for
 * anything `dbDelta` cannot express (capabilities, data backfills, etc.).
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies pending schema/data migrations in order.
 */
class DoughBoss_Migrations {

	/**
	 * Run any migrations the stored version is behind.
	 *
	 * @return void
	 */
	public static function run() {
		$installed = (string) get_option( 'doughboss_db_version', '0' );

		if ( version_compare( $installed, DOUGHBOSS_DB_VERSION, '>=' ) ) {
			return;
		}

		require_once DOUGHBOSS_PLUGIN_DIR . 'includes/class-doughboss-activator.php';

		// dbDelta is additive: re-running the table definitions adds any new
		// columns to existing installs without touching existing data.
		DoughBoss_Activator::create_tables();

		// Ordered, version-gated steps for changes dbDelta can't make.
		if ( version_compare( $installed, '1.1.0', '<' ) ) {
			self::upgrade_to_1_1_0();
		}
		if ( version_compare( $installed, '1.2.0', '<' ) ) {
			self::upgrade_to_1_2_0();
		}
		if ( version_compare( $installed, '1.3.0', '<' ) ) {
			self::upgrade_to_1_3_0();
		}

		update_option( 'doughboss_db_version', DOUGHBOSS_DB_VERSION );
	}

	/**
	 * 1.1.0 — real-time order board: kitchen role + capabilities.
	 *
	 * The new order-board columns (seen_at, acknowledged_at, accepted_at,
	 * eta_minutes) are added by dbDelta via create_tables(); this step only
	 * covers what dbDelta cannot: roles/capabilities.
	 *
	 * @return void
	 */
	private static function upgrade_to_1_1_0() {
		DoughBoss_Activator::add_capabilities();
	}

	/**
	 * 1.2.0 — multi-shop foundation: locations table + a default shop so the
	 * existing single-shop flow keeps working unchanged.
	 *
	 * The locations table and orders.location_id column are added by dbDelta
	 * via create_tables(); this step seeds the first location.
	 *
	 * @return void
	 */
	private static function upgrade_to_1_2_0() {
		require_once DOUGHBOSS_PLUGIN_DIR . 'includes/class-doughboss-locations.php';
		DoughBoss_Locations::ensure_default();
	}

	/**
	 * 1.3.0 — localise the demo US config to Australia (AUD + GST-inclusive),
	 * only touching values that still look like the original demo defaults so a
	 * deliberately-configured store is never overwritten.
	 *
	 * @return void
	 */
	private static function upgrade_to_1_3_0() {
		$settings = get_option( DoughBoss_Settings::OPTION_KEY );
		if ( ! is_array( $settings ) ) {
			return;
		}

		$changed = false;
		if ( isset( $settings['currency_code'] ) && 'USD' === $settings['currency_code'] ) {
			$settings['currency_code'] = 'AUD';
			$changed                   = true;
			// Demo tax was 0; default Australian GST to 10% inclusive.
			if ( empty( $settings['tax_rate'] ) ) {
				$settings['tax_rate'] = 10;
			}
		}
		if ( ! isset( $settings['gst_inclusive'] ) ) {
			$settings['gst_inclusive'] = 1;
			$changed                   = true;
		}

		if ( $changed ) {
			update_option( DoughBoss_Settings::OPTION_KEY, $settings );
		}
	}
}
