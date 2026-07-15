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

		// add_option() is a single INSERT protected by WordPress's unique option
		// key, so only one request can own the migration. A stale five-minute lock
		// is recoverable after a crashed PHP process.
		$lock_key = 'doughboss_migration_lock';
		$lock_at  = (int) get_option( $lock_key, 0 );
		if ( $lock_at && ( time() - $lock_at ) < ( 5 * MINUTE_IN_SECONDS ) ) {
			return;
		}
		if ( $lock_at ) {
			delete_option( $lock_key );
		}
		if ( ! add_option( $lock_key, time(), '', 'no' ) ) {
			return;
		}

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
				'1.8.0' => 'upgrade_to_1_8_0',
				'1.9.0' => 'upgrade_to_1_9_0',
				'1.10.0' => 'upgrade_to_1_10_0',
				'1.11.0' => 'upgrade_to_1_11_0',
			);
			foreach ( $steps as $version => $method ) {
				if ( version_compare( $installed, $version, '<' ) ) {
					self::$method();
					update_option( 'doughboss_db_version', $version );
				}
			}

			update_option( 'doughboss_db_version', DOUGHBOSS_DB_VERSION );
			delete_option( 'doughboss_migration_error' );
		} catch ( Throwable $e ) {
			// Leave the version at the last successful checkpoint and let the site
			// keep serving; the next request retries the remaining steps.
			if ( function_exists( 'error_log' ) ) {
				error_log( 'DoughBoss migration halted: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			update_option( 'doughboss_migration_error', sanitize_text_field( $e->getMessage() ) );
		}

		delete_option( $lock_key );
		delete_transient( 'doughboss_migrating' ); // Clean up the pre-1.11 lock.
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

	/**
	 * 1.8.0 — datetime hygiene + catering webhook index.
	 *
	 * create_tables() now declares created_at/updated_at/redeemed_at as
	 * `datetime NULL DEFAULT NULL` (instead of the invalid '0000-00-00' zero
	 * date) and adds KEY balance_intent_id to the catering table. dbDelta will
	 * not retroactively change a column default on existing installs, so the
	 * columns are altered explicitly here. Every insert path already supplies
	 * explicit timestamps, so no data backfill is needed.
	 *
	 * @return void
	 */
	private static function upgrade_to_1_8_0() {
		global $wpdb;

		$columns = array(
			$wpdb->prefix . 'doughboss_orders'              => array( 'created_at', 'updated_at' ),
			$wpdb->prefix . 'doughboss_catering_enquiries'  => array( 'created_at', 'updated_at' ),
			$wpdb->prefix . 'doughboss_vouchers'            => array( 'created_at', 'updated_at' ),
			$wpdb->prefix . 'doughboss_voucher_redemptions' => array( 'redeemed_at' ),
		);
		foreach ( $columns as $table => $cols ) {
			foreach ( $cols as $col ) {
				// Table names come from $wpdb->prefix and columns from the
				// hardcoded map above — nothing user-supplied to prepare.
				$wpdb->query( "ALTER TABLE {$table} MODIFY {$col} datetime NULL DEFAULT NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			}
		}

		// Index balance_intent_id so find_by_intent()'s OR lookup stops table-
		// scanning on every Stripe webhook. dbDelta may already have added it via
		// create_tables(), so guard against a duplicate-key error.
		$catering = $wpdb->prefix . 'doughboss_catering_enquiries';
		$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SHOW INDEX FROM {$catering} WHERE Key_name = %s", 'balance_intent_id' )
		);
		if ( ! $existing ) {
			$wpdb->query( "ALTER TABLE {$catering} ADD KEY balance_intent_id (balance_intent_id)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/**
	 * 1.9.0 — POSPal push outbox (durable retry for the till mirror).
	 *
	 * The new {prefix}doughboss_pospal_outbox table is created by dbDelta via
	 * create_tables(); nothing to backfill. The outbox is only used by NEW
	 * orders placed after upgrade — orders placed before the upgrade never had
	 * a retry story and stay unchanged.
	 *
	 * @return void
	 */
	private static function upgrade_to_1_9_0() {
		// Schema handled by create_tables(); nothing else to migrate.
	}

	/**
	 * 1.10.0 — single-location / pickup-only mode.
	 *
	 * Adds the `single_location_mode` setting (defaults to 1). Also, if the site
	 * has zero or exactly one *active* shop today, we auto-turn `enable_delivery`
	 * off — the "For now, pickup only from Revesby" scope in the discovery doc.
	 * A multi-shop site with delivery already on stays untouched.
	 *
	 * @return void
	 */
	private static function upgrade_to_1_10_0() {
		require_once DOUGHBOSS_PLUGIN_DIR . 'includes/class-doughboss-locations.php';

		$settings = get_option( DoughBoss_Settings::OPTION_KEY );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$changed = false;

		$active = DoughBoss_Locations::all( true );

		// Enable only for a genuine single-shop install. Multi-shop sites must
		// opt out by default so an upgrade cannot silently pin every order to the
		// first sorted location.
		if ( ! isset( $settings['single_location_mode'] ) ) {
			$settings['single_location_mode'] = count( $active ) <= 1 ? 1 : 0;
			$changed = true;
		}

		// Auto-narrow to pickup-only if the site currently runs 0 or 1 active
		// shops — matches the discovery doc's "For now, pickup only from Revesby"
		// scope. A multi-shop delivery site is deliberately left alone.
		if ( count( $active ) <= 1 && ! empty( $settings['enable_delivery'] ) ) {
			$settings['enable_delivery'] = 0;
			$changed = true;
		}

		if ( $changed ) {
			update_option( DoughBoss_Settings::OPTION_KEY, $settings );
		}
	}

	/**
	 * 1.11.0 — durable, versioned order lifecycle.
	 *
	 * dbDelta adds the order version/timestamp columns and creates the event
	 * table. Historical events and timestamps are deliberately not fabricated:
	 * the audit trail begins with the first post-upgrade transition.
	 *
	 * @return void
	 */
	private static function upgrade_to_1_11_0() {
		// dbDelta failures commonly return false instead of throwing. Verify the
		// storage invariant explicitly so the migration runner cannot checkpoint
		// 1.11.0 while versioning/events are missing or non-transactional.
		if ( ! DoughBoss_Activator::lifecycle_storage_ready() ) {
			throw new RuntimeException( 'Order lifecycle tables are missing or are not using InnoDB.' );
		}
	}
}
