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

		// run() fires on every request until the version is written; a short lock
		// stops concurrent visitors from racing on dbDelta and cap seeding.
		if ( get_transient( 'doughboss_migrating' ) ) {
			return;
		}
		set_transient( 'doughboss_migrating', 1, 5 * MINUTE_IN_SECONDS );

		require_once DOUGHBOSS_PLUGIN_DIR . 'includes/class-doughboss-activator.php';

		// A failing step must never white-screen the site or replay every prior
		// step for each visitor: wrap the whole run, and checkpoint the stored
		// version after each step so progress is durable.
		try {
			// dbDelta is additive: re-running the table definitions adds any new
			// columns to existing installs without touching existing data.
			DoughBoss_Activator::create_tables();

			// Self-heal roles/capabilities on every upgrade (covers new caps and
			// fresh installs where the version-gated steps below are skipped).
			DoughBoss_Activator::add_capabilities();

			$steps = array(
				'1.1.0' => 'upgrade_to_1_1_0',
				'1.2.0' => 'upgrade_to_1_2_0',
				'1.3.0' => 'upgrade_to_1_3_0',
				'1.4.0' => 'upgrade_to_1_4_0',
				'1.5.0' => 'upgrade_to_1_5_0',
				'1.6.0' => 'upgrade_to_1_6_0',
				'1.7.0' => 'upgrade_to_1_7_0',
			);
			foreach ( $steps as $version => $method ) {
				if ( version_compare( $installed, $version, '<' ) ) {
					self::$method();
					update_option( 'doughboss_db_version', $version );
				}
			}

			update_option( 'doughboss_db_version', DOUGHBOSS_DB_VERSION );
		} catch ( Throwable $e ) {
			// Leave the version at the last successful checkpoint and let the site
			// keep serving; the next request retries the remaining steps.
			if ( function_exists( 'error_log' ) ) {
				error_log( 'DoughBoss migration halted: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		delete_transient( 'doughboss_migrating' );
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

	/**
	 * 1.4.0 — optional Stripe card payments.
	 *
	 * The orders.payment_status / payment_method / payment_intent_id columns are
	 * added by dbDelta via create_tables(); existing orders default to 'unpaid'.
	 * Payments stay off until an operator enables them and saves keys, so there
	 * is no data to backfill here.
	 *
	 * @return void
	 */
	private static function upgrade_to_1_4_0() {
		// Schema handled by create_tables(); nothing else to migrate.
	}

	/**
	 * 1.5.0 — catering: enquiries table + the catering-package post type.
	 *
	 * The {prefix}doughboss_catering_enquiries table is created by dbDelta via
	 * create_tables(); catering management reuses the existing manage_doughboss
	 * capability, so there are no new roles/caps to add and no data to backfill.
	 *
	 * @return void
	 */
	private static function upgrade_to_1_5_0() {
		// Schema handled by create_tables(); nothing else to migrate.
	}

	/**
	 * 1.6.0 — vouchers / discount coupons.
	 *
	 * The {prefix}doughboss_vouchers and {prefix}doughboss_voucher_redemptions
	 * tables are created by dbDelta via create_tables(). This step adds a
	 * dedicated redemption capability so a till device can redeem vouchers
	 * without holding broader management rights.
	 *
	 * @return void
	 */
	private static function upgrade_to_1_6_0() {
		foreach ( array( 'administrator', 'doughboss_kitchen' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && ! $role->has_cap( 'redeem_doughboss_vouchers' ) ) {
				$role->add_cap( 'redeem_doughboss_vouchers' );
			}
		}
	}

	/**
	 * 1.7.0 — voucher discounts on orders.
	 *
	 * The orders.discount / orders.voucher_code columns and the
	 * voucher_redemptions.order_id column are added by dbDelta via
	 * create_tables(); existing orders default to no discount, so there is
	 * nothing to backfill.
	 *
	 * @return void
	 */
	private static function upgrade_to_1_7_0() {
		// Schema handled by create_tables(); nothing else to migrate.
	}
}
