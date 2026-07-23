<?php
/**
 * Fired during plugin activation.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets up database tables, default options and capabilities on activation.
 */
class DoughBoss_Activator {

	/**
	 * Activation routine.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
		self::add_default_options();
		self::add_capabilities();

		// Register post types so rewrite rules exist, then flush them.
		require_once DOUGHBOSS_PLUGIN_DIR . 'includes/class-doughboss-post-types.php';
		require_once DOUGHBOSS_PLUGIN_DIR . 'includes/class-doughboss-catering-package.php';
		DoughBoss_Post_Types::register();
		DoughBoss_Catering_Package::register();
		flush_rewrite_rules();

		if ( self::lifecycle_storage_ready() && self::capacity_storage_ready() && self::checkout_storage_ready() && self::table_qr_storage_ready() && self::payment_storage_ready() ) {
			update_option( 'doughboss_db_version', DOUGHBOSS_DB_VERSION );
			delete_option( 'doughboss_migration_error' );
		} else {
			update_option( 'doughboss_migration_error', 'Transactional order, capacity, checkout-integrity, table-QR, or payment-attempt storage is incomplete or is not using InnoDB.' );
		}
	}

	/**
	 * Create the orders and order-items tables.
	 *
	 * Public so the migration runner can re-run it (dbDelta is additive and
	 * adds any new columns to existing installs).
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$orders          = $wpdb->prefix . 'doughboss_orders';
		$order_items     = $wpdb->prefix . 'doughboss_order_items';
		$order_events    = $wpdb->prefix . 'doughboss_order_events';
		$locations       = $wpdb->prefix . 'doughboss_locations';
		$catering        = $wpdb->prefix . 'doughboss_catering_enquiries';
		$vouchers        = $wpdb->prefix . 'doughboss_vouchers';
		$redemptions     = $wpdb->prefix . 'doughboss_voucher_redemptions';
		$pospal_outbox   = $wpdb->prefix . 'doughboss_pospal_outbox';
		$location_hours  = $wpdb->prefix . 'doughboss_location_hours';
		$exceptions      = $wpdb->prefix . 'doughboss_schedule_exceptions';
		$capacity_slots  = $wpdb->prefix . 'doughboss_capacity_slots';
		$capacity_holds  = $wpdb->prefix . 'doughboss_capacity_holds';
		$dining_tables   = $wpdb->prefix . 'doughboss_dining_tables';
		$table_qr_codes  = $wpdb->prefix . 'doughboss_table_qr_codes';
		$table_sessions  = $wpdb->prefix . 'doughboss_table_sessions';
		$payment_attempts = $wpdb->prefix . 'doughboss_payment_attempts';
		$payment_events   = $wpdb->prefix . 'doughboss_payment_events';

		$sql_orders = "CREATE TABLE {$orders} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_number varchar(32) NOT NULL,
			location_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			version bigint(20) unsigned NOT NULL DEFAULT 1,
			order_type varchar(20) NOT NULL DEFAULT 'pickup',
			table_id bigint(20) unsigned NOT NULL DEFAULT 0,
			table_label varchar(80) NOT NULL DEFAULT '',
			table_qr_code_id bigint(20) unsigned NOT NULL DEFAULT 0,
			table_session_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_source varchar(20) NOT NULL DEFAULT 'web',
			customer_name varchar(191) NOT NULL DEFAULT '',
			customer_email varchar(191) NOT NULL DEFAULT '',
			customer_phone varchar(40) NOT NULL DEFAULT '',
			address text NULL,
			notes text NULL,
			subtotal decimal(10,2) NOT NULL DEFAULT 0.00,
			tax decimal(10,2) NOT NULL DEFAULT 0.00,
			delivery_fee decimal(10,2) NOT NULL DEFAULT 0.00,
			total decimal(10,2) NOT NULL DEFAULT 0.00,
			discount decimal(10,2) NOT NULL DEFAULT 0.00,
			voucher_code varchar(40) NOT NULL DEFAULT '',
			currency varchar(10) NOT NULL DEFAULT 'AUD',
			payment_status varchar(20) NOT NULL DEFAULT 'unpaid',
			payment_method varchar(20) NOT NULL DEFAULT '',
			payment_intent_id varchar(191) NULL DEFAULT NULL,
			checkout_key char(64) NULL DEFAULT NULL,
			eta_minutes int(11) NOT NULL DEFAULT 0,
			seen_at datetime NULL DEFAULT NULL,
			acknowledged_at datetime NULL DEFAULT NULL,
			accepted_at datetime NULL DEFAULT NULL,
			status_changed_at datetime NULL DEFAULT NULL,
			promised_ready_from_utc datetime NULL DEFAULT NULL,
			promised_ready_by_utc datetime NULL DEFAULT NULL,
			timezone_snapshot varchar(64) NOT NULL DEFAULT '',
			capacity_hold_id bigint(20) unsigned NOT NULL DEFAULT 0,
			capacity_units int(10) unsigned NOT NULL DEFAULT 0,
			fire_at_utc datetime NULL DEFAULT NULL,
			planning_version bigint(20) unsigned NOT NULL DEFAULT 0,
			cooking_started_at datetime NULL DEFAULT NULL,
			ready_at datetime NULL DEFAULT NULL,
			completed_at datetime NULL DEFAULT NULL,
			cancelled_at datetime NULL DEFAULT NULL,
			created_at datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_number (order_number),
			KEY status (status),
			KEY customer_email (customer_email),
			KEY location_id (location_id),
			KEY location_table_created (location_id,table_id,created_at),
			KEY promised_ready_from (location_id,promised_ready_from_utc),
			KEY fire_time (location_id,fire_at_utc),
			UNIQUE KEY payment_intent_id (payment_intent_id),
			UNIQUE KEY checkout_key (checkout_key)
		) ENGINE=InnoDB {$charset_collate};";

		$sql_events = "CREATE TABLE {$order_events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			order_version bigint(20) unsigned NOT NULL,
			event_type varchar(32) NOT NULL DEFAULT 'status_changed',
			from_status varchar(20) NOT NULL DEFAULT '',
			to_status varchar(20) NOT NULL DEFAULT '',
			actor_type varchar(20) NOT NULL DEFAULT 'system',
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			reason_code varchar(32) NOT NULL DEFAULT '',
			event_key varchar(191) NOT NULL,
			occurred_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_key (event_key),
			UNIQUE KEY order_version (order_id,order_version),
			KEY order_time (order_id,occurred_at),
			KEY occurred_at (occurred_at)
		) ENGINE=InnoDB {$charset_collate};";

		$sql_items = "CREATE TABLE {$order_items} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			item_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(191) NOT NULL DEFAULT '',
			size varchar(80) NOT NULL DEFAULT '',
			toppings text NULL,
			quantity int(11) NOT NULL DEFAULT 1,
			unit_price decimal(10,2) NOT NULL DEFAULT 0.00,
			line_total decimal(10,2) NOT NULL DEFAULT 0.00,
			PRIMARY KEY  (id),
			KEY order_id (order_id)
		) ENGINE=InnoDB {$charset_collate};";

		$sql_locations = "CREATE TABLE {$locations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			slug varchar(191) NOT NULL DEFAULT '',
			suburb varchar(191) NOT NULL DEFAULT '',
			address text NULL,
			phone varchar(40) NOT NULL DEFAULT '',
			postcodes text NULL,
			prep_time_default int(11) NOT NULL DEFAULT 20,
			timezone varchar(64) NOT NULL DEFAULT 'Australia/Sydney',
			capacity_mode varchar(12) NOT NULL DEFAULT 'off',
			slot_minutes smallint(5) unsigned NOT NULL DEFAULT 15,
			minimum_notice_minutes smallint(5) unsigned NOT NULL DEFAULT 30,
			booking_horizon_days smallint(5) unsigned NOT NULL DEFAULT 7,
			hold_minutes smallint(5) unsigned NOT NULL DEFAULT 10,
			slot_order_capacity smallint(5) unsigned NOT NULL DEFAULT 4,
			slot_unit_capacity smallint(5) unsigned NOT NULL DEFAULT 12,
			planning_version bigint(20) unsigned NOT NULL DEFAULT 1,
			tyro_location_id varchar(191) NOT NULL DEFAULT '',
			pospal_store_index tinyint(3) unsigned NOT NULL DEFAULT 0,
			online_payment_enabled tinyint(1) NOT NULL DEFAULT 0,
			pickup_enabled tinyint(1) NOT NULL DEFAULT 1,
			delivery_enabled tinyint(1) NOT NULL DEFAULT 0,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY slug (slug),
			KEY is_active (is_active)
		) ENGINE=InnoDB {$charset_collate};";

		$sql_location_hours = "CREATE TABLE {$location_hours} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			location_id bigint(20) unsigned NOT NULL,
			order_type varchar(20) NOT NULL DEFAULT 'pickup',
			weekday tinyint(3) unsigned NOT NULL,
			segment tinyint(3) unsigned NOT NULL DEFAULT 1,
			opens_at time NOT NULL,
			closes_at time NOT NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY location_schedule (location_id,order_type,weekday,segment),
			KEY active_hours (location_id,order_type,is_active)
		) ENGINE=InnoDB {$charset_collate};";

		$sql_exceptions = "CREATE TABLE {$exceptions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			location_id bigint(20) unsigned NOT NULL,
			order_type varchar(20) NOT NULL DEFAULT 'pickup',
			service_date date NOT NULL,
			segment tinyint(3) unsigned NOT NULL DEFAULT 1,
			is_closed tinyint(1) NOT NULL DEFAULT 0,
			opens_at time NULL DEFAULT NULL,
			closes_at time NULL DEFAULT NULL,
			order_capacity smallint(5) unsigned NULL DEFAULT NULL,
			unit_capacity smallint(5) unsigned NULL DEFAULT NULL,
			note varchar(191) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY location_exception (location_id,order_type,service_date,segment),
			KEY service_date (service_date)
		) ENGINE=InnoDB {$charset_collate};";

		$sql_capacity_slots = "CREATE TABLE {$capacity_slots} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			location_id bigint(20) unsigned NOT NULL,
			order_type varchar(20) NOT NULL DEFAULT 'pickup',
			starts_at_utc datetime NOT NULL,
			ends_at_utc datetime NOT NULL,
			timezone_snapshot varchar(64) NOT NULL,
			order_capacity smallint(5) unsigned NOT NULL,
			unit_capacity smallint(5) unsigned NOT NULL,
			planning_version bigint(20) unsigned NOT NULL DEFAULT 1,
			accepting_holds tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY location_slot (location_id,order_type,starts_at_utc),
			KEY available_slots (location_id,order_type,starts_at_utc,accepting_holds)
		) ENGINE=InnoDB {$charset_collate};";

		$sql_capacity_holds = "CREATE TABLE {$capacity_holds} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slot_id bigint(20) unsigned NOT NULL,
			token_hash char(64) NOT NULL,
			idempotency_key varchar(191) NOT NULL,
			cart_hash char(64) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'held',
			capacity_units int(10) unsigned NOT NULL,
			expires_at datetime NOT NULL,
			order_id bigint(20) unsigned NULL DEFAULT NULL,
			converted_at datetime NULL DEFAULT NULL,
			released_at datetime NULL DEFAULT NULL,
			created_at datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			UNIQUE KEY idempotency_key (idempotency_key),
			UNIQUE KEY order_id (order_id),
			KEY slot_state (slot_id,status,expires_at)
		) ENGINE=InnoDB {$charset_collate};";

		$sql_dining_tables = "CREATE TABLE {$dining_tables} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			location_id bigint(20) unsigned NOT NULL,
			label varchar(80) NOT NULL DEFAULT '',
			zone varchar(80) NOT NULL DEFAULT '',
			ordering_url varchar(255) NOT NULL DEFAULT '',
			is_active tinyint(1) NOT NULL DEFAULT 1,
			sort_order int(11) NOT NULL DEFAULT 0,
			current_qr_code_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY location_label (location_id,label),
			KEY location_active (location_id,is_active)
		) ENGINE=InnoDB {$charset_collate};";

		$sql_table_qr_codes = "CREATE TABLE {$table_qr_codes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			table_id bigint(20) unsigned NOT NULL,
			token_hash char(64) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NULL DEFAULT NULL,
			revoked_at datetime NULL DEFAULT NULL,
			last_scanned_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY table_status (table_id,status)
		) ENGINE=InnoDB {$charset_collate};";

		$sql_table_sessions = "CREATE TABLE {$table_sessions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_hash char(64) NOT NULL,
			qr_code_id bigint(20) unsigned NOT NULL,
			cart_token_hash char(64) NOT NULL,
			expires_at datetime NOT NULL,
			last_seen_at datetime NULL DEFAULT NULL,
			created_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_hash (session_hash),
			KEY qr_code_id (qr_code_id),
			KEY expires_at (expires_at)
		) ENGINE=InnoDB {$charset_collate};";

		$sql_catering = "CREATE TABLE {$catering} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			enquiry_number varchar(32) NOT NULL,
			location_id bigint(20) unsigned NOT NULL DEFAULT 0,
			package_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'new',
			customer_name varchar(191) NOT NULL DEFAULT '',
			customer_email varchar(191) NOT NULL DEFAULT '',
			customer_phone varchar(40) NOT NULL DEFAULT '',
			event_date date NULL DEFAULT NULL,
			event_time varchar(20) NOT NULL DEFAULT '',
			guest_count int(11) NOT NULL DEFAULT 0,
			order_type varchar(20) NOT NULL DEFAULT 'pickup',
			address text NULL,
			dietary text NULL,
			notes text NULL,
			subtotal decimal(10,2) NOT NULL DEFAULT 0.00,
			delivery_fee decimal(10,2) NOT NULL DEFAULT 0.00,
			quote_total decimal(10,2) NOT NULL DEFAULT 0.00,
			deposit_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			balance_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			currency varchar(10) NOT NULL DEFAULT 'AUD',
			deposit_intent_id varchar(64) NOT NULL DEFAULT '',
			balance_intent_id varchar(64) NOT NULL DEFAULT '',
			deposit_paid_at datetime NULL DEFAULT NULL,
			balance_paid_at datetime NULL DEFAULT NULL,
			quoted_at datetime NULL DEFAULT NULL,
			created_at datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY enquiry_number (enquiry_number),
			KEY status (status),
			KEY location_id (location_id),
			KEY customer_email (customer_email),
			KEY event_date (event_date),
			KEY deposit_intent_id (deposit_intent_id),
			KEY balance_intent_id (balance_intent_id)
		) {$charset_collate};";

		$sql_vouchers = "CREATE TABLE {$vouchers} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code varchar(32) NOT NULL,
			type varchar(20) NOT NULL DEFAULT 'amount',
			value decimal(10,2) NOT NULL DEFAULT 0.00,
			currency varchar(10) NOT NULL DEFAULT 'AUD',
			min_spend decimal(10,2) NOT NULL DEFAULT 0.00,
			scope varchar(20) NOT NULL DEFAULT 'both',
			location_id bigint(20) unsigned NOT NULL DEFAULT 0,
			single_use tinyint(1) NOT NULL DEFAULT 1,
			status varchar(20) NOT NULL DEFAULT 'issued',
			customer_phone varchar(40) NOT NULL DEFAULT '',
			customer_email varchar(191) NOT NULL DEFAULT '',
			campaign varchar(40) NOT NULL DEFAULT '',
			pospal_customer_uid varchar(64) NOT NULL DEFAULT '',
			pospal_coupon_ref varchar(64) NOT NULL DEFAULT '',
			valid_from datetime NULL DEFAULT NULL,
			valid_to datetime NULL DEFAULT NULL,
			meta text NULL,
			created_at datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY status (status),
			KEY customer_phone (customer_phone),
			KEY campaign (campaign)
		) {$charset_collate};";

		$sql_redemptions = "CREATE TABLE {$redemptions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			voucher_id bigint(20) unsigned NOT NULL,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			channel varchar(20) NOT NULL DEFAULT 'online',
			pospal_ticket_no varchar(64) NOT NULL DEFAULT '',
			location_id bigint(20) unsigned NOT NULL DEFAULT 0,
			amount_applied decimal(10,2) NOT NULL DEFAULT 0.00,
			idempotency_key varchar(64) NOT NULL DEFAULT '',
			redeemed_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY voucher_id (voucher_id),
			KEY pospal_ticket_no (pospal_ticket_no)
		) {$charset_collate};";

		$sql_pospal_outbox = "CREATE TABLE {$pospal_outbox} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kind varchar(32) NOT NULL DEFAULT 'order_push',
			entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
			store_index tinyint(3) unsigned NOT NULL DEFAULT 1,
			payload_json longtext NULL,
			idempotency_key varchar(191) NOT NULL DEFAULT '',
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			last_error varchar(64) NOT NULL DEFAULT '',
			next_attempt_at datetime NULL DEFAULT NULL,
			created_at datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY status_next (status,next_attempt_at),
			KEY entity_id (entity_id)
		) {$charset_collate};";

		$sql_payment_attempts = "CREATE TABLE {$payment_attempts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attempt_key char(64) NOT NULL,
			provider varchar(20) NOT NULL DEFAULT 'tyro',
			provider_reference varchar(191) NULL DEFAULT NULL,
			checkout_key char(64) NOT NULL,
			purpose varchar(32) NOT NULL DEFAULT 'order',
			context varchar(32) NOT NULL DEFAULT 'web',
			local_reference varchar(191) NOT NULL DEFAULT '',
			location_id bigint(20) unsigned NOT NULL DEFAULT 0,
			table_id bigint(20) unsigned NOT NULL DEFAULT 0,
			qr_code_id bigint(20) unsigned NOT NULL DEFAULT 0,
			amount_minor bigint(20) unsigned NOT NULL DEFAULT 0,
			currency char(3) NOT NULL DEFAULT 'AUD',
			status varchar(32) NOT NULL DEFAULT 'created',
			provider_status varchar(32) NOT NULL DEFAULT '',
			safe_metadata_json longtext NULL,
			last_error varchar(64) NOT NULL DEFAULT '',
			created_at datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			verified_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attempt_key (attempt_key),
			UNIQUE KEY provider_reference (provider_reference),
			UNIQUE KEY checkout_key (checkout_key),
			KEY status_updated (status,updated_at),
			KEY local_reference (local_reference)
		) ENGINE=InnoDB {$charset_collate};";

		$sql_payment_events = "CREATE TABLE {$payment_events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_key char(64) NOT NULL,
			provider varchar(20) NOT NULL DEFAULT 'tyro',
			provider_reference varchar(191) NOT NULL DEFAULT '',
			event_type varchar(64) NOT NULL DEFAULT '',
			outcome varchar(32) NOT NULL DEFAULT 'processing',
			created_at datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_key (event_key),
			KEY provider_reference (provider_reference)
		) ENGINE=InnoDB {$charset_collate};";

		dbDelta( $sql_orders );
		dbDelta( $sql_items );
		dbDelta( $sql_events );
		dbDelta( $sql_locations );
		dbDelta( $sql_catering );
		dbDelta( $sql_vouchers );
		dbDelta( $sql_redemptions );
		dbDelta( $sql_pospal_outbox );
		dbDelta( $sql_location_hours );
		dbDelta( $sql_exceptions );
		dbDelta( $sql_capacity_slots );
		dbDelta( $sql_capacity_holds );
		dbDelta( $sql_dining_tables );
		dbDelta( $sql_table_qr_codes );
		dbDelta( $sql_table_sessions );
		dbDelta( $sql_payment_attempts );
		dbDelta( $sql_payment_events );
	}

	/**
	 * Verify durable payment attempts, webhook de-duplication and store mappings.
	 *
	 * @return bool
	 */
	public static function payment_storage_ready() {
		global $wpdb;
		$attempts  = $wpdb->prefix . 'doughboss_payment_attempts';
		$events    = $wpdb->prefix . 'doughboss_payment_events';
		$locations = $wpdb->prefix . 'doughboss_locations';
		foreach ( array( $attempts, $events, $locations ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			if ( ! $engine || 'INNODB' !== strtoupper( $engine ) ) {
				return false;
			}
		}

		return self::column_contract_ready(
			$attempts,
			array(
				'attempt_key'        => array( 'type' => 'char(64)', 'null' => 'NO' ),
				'provider_reference' => array( 'type' => 'varchar(191)', 'null' => 'YES', 'default' => null ),
				'checkout_key'       => array( 'type' => 'char(64)', 'null' => 'NO' ),
				'amount_minor'       => array( 'type' => 'bigint(20) unsigned', 'null' => 'NO', 'default' => '0' ),
				'status'             => array( 'type' => 'varchar(32)', 'null' => 'NO', 'default' => 'created' ),
			)
		)
			&& self::column_contract_ready( $events, array( 'event_key' => array( 'type' => 'char(64)', 'null' => 'NO' ) ) )
			&& self::column_contract_ready(
				$locations,
				array(
					'tyro_location_id'      => array( 'type' => 'varchar(191)', 'null' => 'NO', 'default' => '' ),
					'pospal_store_index'     => array( 'type' => 'tinyint(3) unsigned', 'null' => 'NO', 'default' => '0' ),
					'online_payment_enabled' => array( 'type' => 'tinyint(1)', 'null' => 'NO', 'default' => '0' ),
				)
			)
			&& self::index_contract_ready( $attempts, 'attempt_key', array( 'attempt_key' ), true, array( 64 ) )
			&& self::index_contract_ready( $attempts, 'provider_reference', array( 'provider_reference' ), true, array( 191 ) )
			&& self::index_contract_ready( $attempts, 'checkout_key', array( 'checkout_key' ), true, array( 64 ) )
			&& self::index_contract_ready( $events, 'event_key', array( 'event_key' ), true, array( 64 ) );
	}

	/**
	 * Verify that the three lifecycle tables exist and support transactions.
	 *
	 * @return bool
	 */
	public static function lifecycle_storage_ready() {
		global $wpdb;
		$orders = $wpdb->prefix . 'doughboss_orders';
		$events = $wpdb->prefix . 'doughboss_order_events';
		$tables = array(
			$orders,
			$wpdb->prefix . 'doughboss_order_items',
			$events,
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$table
				)
			);
			if ( ! $engine || 'INNODB' !== strtoupper( $engine ) ) {
				return false;
			}
		}

		$order_columns = array(
			'version', 'status_changed_at', 'promised_ready_from_utc',
			'promised_ready_by_utc', 'timezone_snapshot', 'cooking_started_at',
			'ready_at', 'completed_at', 'cancelled_at',
		);
		$event_columns = array(
			'order_id', 'order_version', 'event_type', 'from_status', 'to_status',
			'actor_type', 'actor_id', 'reason_code', 'event_key', 'occurred_at',
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$actual_order_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$orders}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$actual_event_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$events}" );
		if ( array_diff( $order_columns, (array) $actual_order_columns ) || array_diff( $event_columns, (array) $actual_event_columns ) ) {
			return false;
		}

		// Presence alone is not enough: a partial/manual migration could leave a
		// nullable version, the wrong default, or text where a UTC datetime is
		// required. Verify the definitions that transaction/version semantics rely
		// on before allowing the stored database version to advance.
		$order_contract = array(
			'version'                   => array( 'type' => 'bigint(20) unsigned', 'null' => 'NO', 'default' => '1' ),
			'status_changed_at'         => array( 'type' => 'datetime', 'null' => 'YES', 'default' => null ),
			'promised_ready_from_utc'   => array( 'type' => 'datetime', 'null' => 'YES', 'default' => null ),
			'promised_ready_by_utc'     => array( 'type' => 'datetime', 'null' => 'YES', 'default' => null ),
			'timezone_snapshot'         => array( 'type' => 'varchar(64)', 'null' => 'NO', 'default' => '' ),
			'cooking_started_at'        => array( 'type' => 'datetime', 'null' => 'YES', 'default' => null ),
			'ready_at'                  => array( 'type' => 'datetime', 'null' => 'YES', 'default' => null ),
			'completed_at'              => array( 'type' => 'datetime', 'null' => 'YES', 'default' => null ),
			'cancelled_at'              => array( 'type' => 'datetime', 'null' => 'YES', 'default' => null ),
		);
		$event_contract = array(
			'order_id'      => array( 'type' => 'bigint(20) unsigned', 'null' => 'NO' ),
			'order_version' => array( 'type' => 'bigint(20) unsigned', 'null' => 'NO' ),
			'event_type'    => array( 'type' => 'varchar(32)', 'null' => 'NO', 'default' => 'status_changed' ),
			'from_status'   => array( 'type' => 'varchar(20)', 'null' => 'NO', 'default' => '' ),
			'to_status'     => array( 'type' => 'varchar(20)', 'null' => 'NO', 'default' => '' ),
			'actor_type'    => array( 'type' => 'varchar(20)', 'null' => 'NO', 'default' => 'system' ),
			'actor_id'      => array( 'type' => 'bigint(20) unsigned', 'null' => 'NO', 'default' => '0' ),
			'reason_code'   => array( 'type' => 'varchar(32)', 'null' => 'NO', 'default' => '' ),
			'event_key'     => array( 'type' => 'varchar(191)', 'null' => 'NO' ),
			'occurred_at'   => array( 'type' => 'datetime', 'null' => 'YES', 'default' => null ),
		);
		if ( ! self::column_contract_ready( $orders, $order_contract ) || ! self::column_contract_ready( $events, $event_contract ) ) {
			return false;
		}

		// Both uniqueness constraints are required for retry idempotency and to
		// guarantee one event for each order version.
		return self::index_contract_ready( $events, 'event_key', array( 'event_key' ), true, array( 191 ) )
			&& self::index_contract_ready( $events, 'order_version', array( 'order_id', 'order_version' ), true )
			&& self::index_contract_ready( $orders, 'promised_ready_from', array( 'location_id', 'promised_ready_from_utc' ), false );
	}

	/**
	 * Verify selected column metadata exactly.
	 *
	 * @param string $table    Table name.
	 * @param array  $contract Field contracts.
	 * @return bool
	 */
	private static function column_contract_ready( $table, array $contract ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SHOW COLUMNS FROM {$table}" );
		$actual = array();
		foreach ( $rows as $row ) {
			$actual[ $row->Field ] = $row;
		}
		foreach ( $contract as $field => $expected ) {
			if ( ! isset( $actual[ $field ] ) ) {
				return false;
			}
			$row = $actual[ $field ];
			// MySQL 8 omits deprecated integer display widths while MariaDB still
			// reports them. They describe the same storage contract, so compare the
			// semantic type while retaining exact varchar lengths and signedness.
			$actual_type   = preg_replace( '/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', strtolower( (string) $row->Type ) );
			$expected_type = preg_replace( '/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', $expected['type'] );
			if ( $actual_type !== $expected_type || strtoupper( (string) $row->Null ) !== $expected['null'] ) {
				return false;
			}
			if ( array_key_exists( 'default', $expected ) ) {
				$actual_default   = $row->Default;
				$expected_default = $expected['default'];
				if ( ( null === $actual_default ) !== ( null === $expected_default ) ) {
					return false;
				}
				if ( null !== $actual_default && (string) $actual_default !== (string) $expected_default ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Verify index uniqueness and exact ordered columns.
	 *
	 * @param string   $table   Table name.
	 * @param string   $name    Index name.
	 * @param string[] $columns Ordered columns.
	 * @param bool     $unique  Whether the index must be unique.
	 * @param int[]    $lengths Minimum full lengths for string index parts.
	 * @return bool
	 */
	private static function index_contract_ready( $table, $name, array $columns, $unique, array $lengths = array() ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $name ) );
		usort(
			$rows,
			static function ( $left, $right ) {
				return (int) $left->Seq_in_index <=> (int) $right->Seq_in_index;
			}
		);
		if ( count( $rows ) !== count( $columns ) ) {
			return false;
		}
		foreach ( $rows as $offset => $row ) {
			$sub_part = null === $row->Sub_part ? null : (int) $row->Sub_part;
			if (
				(string) $row->Column_name !== $columns[ $offset ]
				|| ( $unique ? 0 : 1 ) !== (int) $row->Non_unique
				|| ( null !== $sub_part && ( ! isset( $lengths[ $offset ] ) || $sub_part < $lengths[ $offset ] ) )
			) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Verify the durable checkout replay and one-payment/one-order constraints.
	 *
	 * @return bool
	 */
	public static function checkout_storage_ready() {
		global $wpdb;
		$orders = $wpdb->prefix . 'doughboss_orders';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $orders ) );
		if ( ! $engine || 'INNODB' !== strtoupper( $engine ) ) {
			return false;
		}

		$columns = array(
			'payment_intent_id' => array( 'type' => 'varchar(191)', 'null' => 'YES', 'default' => null ),
			'checkout_key'      => array( 'type' => 'char(64)', 'null' => 'YES', 'default' => null ),
		);
		return self::column_contract_ready( $orders, $columns )
			&& self::index_contract_ready( $orders, 'payment_intent_id', array( 'payment_intent_id' ), true, array( 191 ) )
			&& self::index_contract_ready( $orders, 'checkout_key', array( 'checkout_key' ), true, array( 64 ) );
	}

	/**
	 * Verify the store/table QR ordering schema before accepting table orders.
	 *
	 * @return bool
	 */
	public static function table_qr_storage_ready() {
		global $wpdb;
		$orders   = $wpdb->prefix . 'doughboss_orders';
		$tables   = $wpdb->prefix . 'doughboss_dining_tables';
		$codes    = $wpdb->prefix . 'doughboss_table_qr_codes';
		$sessions = $wpdb->prefix . 'doughboss_table_sessions';

		foreach ( array( $orders, $tables, $codes, $sessions ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			if ( ! $engine || 'INNODB' !== strtoupper( $engine ) ) {
				return false;
			}
		}

		return self::column_contract_ready(
			$orders,
			array(
				'table_id'         => array( 'type' => 'bigint(20) unsigned', 'null' => 'NO', 'default' => '0' ),
				'table_label'      => array( 'type' => 'varchar(80)', 'null' => 'NO', 'default' => '' ),
				'table_qr_code_id' => array( 'type' => 'bigint(20) unsigned', 'null' => 'NO', 'default' => '0' ),
				'table_session_id' => array( 'type' => 'bigint(20) unsigned', 'null' => 'NO', 'default' => '0' ),
				'order_source'     => array( 'type' => 'varchar(20)', 'null' => 'NO', 'default' => 'web' ),
			)
		)
			&& self::column_contract_ready(
				$tables,
				array(
					'location_id'       => array( 'type' => 'bigint(20) unsigned', 'null' => 'NO' ),
					'label'             => array( 'type' => 'varchar(80)', 'null' => 'NO', 'default' => '' ),
					'ordering_url'      => array( 'type' => 'varchar(255)', 'null' => 'NO', 'default' => '' ),
					'is_active'         => array( 'type' => 'tinyint(1)', 'null' => 'NO', 'default' => '1' ),
					'current_qr_code_id'=> array( 'type' => 'bigint(20) unsigned', 'null' => 'NO', 'default' => '0' ),
				)
			)
			&& self::column_contract_ready(
				$codes,
				array(
					'table_id'   => array( 'type' => 'bigint(20) unsigned', 'null' => 'NO' ),
					'token_hash' => array( 'type' => 'char(64)', 'null' => 'NO' ),
					'status'     => array( 'type' => 'varchar(20)', 'null' => 'NO', 'default' => 'active' ),
				)
			)
			&& self::column_contract_ready(
				$sessions,
				array(
					'session_hash'   => array( 'type' => 'char(64)', 'null' => 'NO' ),
					'qr_code_id'      => array( 'type' => 'bigint(20) unsigned', 'null' => 'NO' ),
					'cart_token_hash' => array( 'type' => 'char(64)', 'null' => 'NO' ),
					'expires_at'      => array( 'type' => 'datetime', 'null' => 'NO' ),
				)
			)
			&& self::index_contract_ready( $tables, 'location_label', array( 'location_id', 'label' ), true )
			&& self::index_contract_ready( $codes, 'token_hash', array( 'token_hash' ), true, array( 64 ) )
			&& self::index_contract_ready( $codes, 'table_status', array( 'table_id', 'status' ), false )
			&& self::index_contract_ready( $sessions, 'session_hash', array( 'session_hash' ), true, array( 64 ) )
			&& self::index_contract_ready( $sessions, 'qr_code_id', array( 'qr_code_id' ), false )
			&& self::index_contract_ready( $sessions, 'expires_at', array( 'expires_at' ), false )
			&& self::index_contract_ready( $orders, 'location_table_created', array( 'location_id', 'table_id', 'created_at' ), false );
	}

	/**
	 * Verify the Phase 3 capacity tables and mutex/uniqueness constraints.
	 *
	 * @return bool
	 */
	public static function capacity_storage_ready() {
		global $wpdb;
		$orders     = $wpdb->prefix . 'doughboss_orders';
		$locations  = $wpdb->prefix . 'doughboss_locations';
		$hours      = $wpdb->prefix . 'doughboss_location_hours';
		$exceptions = $wpdb->prefix . 'doughboss_schedule_exceptions';
		$slots      = $wpdb->prefix . 'doughboss_capacity_slots';
		$holds      = $wpdb->prefix . 'doughboss_capacity_holds';
		$required = array(
			$orders => array( 'capacity_hold_id', 'capacity_units', 'fire_at_utc', 'planning_version' ),
			$locations => array( 'timezone', 'capacity_mode', 'slot_minutes', 'minimum_notice_minutes', 'booking_horizon_days', 'hold_minutes', 'slot_order_capacity', 'slot_unit_capacity', 'planning_version' ),
			$hours => array( 'location_id', 'order_type', 'weekday', 'segment', 'opens_at', 'closes_at', 'is_active' ),
			$exceptions => array( 'location_id', 'order_type', 'service_date', 'segment', 'is_closed', 'opens_at', 'closes_at', 'order_capacity', 'unit_capacity' ),
			$slots => array( 'location_id', 'order_type', 'starts_at_utc', 'ends_at_utc', 'timezone_snapshot', 'order_capacity', 'unit_capacity', 'planning_version', 'accepting_holds' ),
			$holds => array( 'slot_id', 'token_hash', 'idempotency_key', 'cart_hash', 'status', 'capacity_units', 'expires_at', 'order_id', 'converted_at', 'released_at' ),
		);
		foreach ( $required as $table => $columns ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			if ( ! $engine || 'INNODB' !== strtoupper( $engine ) ) {
				return false;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$actual = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
			if ( array_diff( $columns, $actual ) ) {
				return false;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$slot_mutex = $wpdb->get_var( "SHOW INDEX FROM {$slots} WHERE Key_name = 'location_slot' AND Non_unique = 0" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hours_unique = $wpdb->get_var( "SHOW INDEX FROM {$hours} WHERE Key_name = 'location_schedule' AND Non_unique = 0" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exception_unique = $wpdb->get_var( "SHOW INDEX FROM {$exceptions} WHERE Key_name = 'location_exception' AND Non_unique = 0" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$token_unique = $wpdb->get_var( "SHOW INDEX FROM {$holds} WHERE Key_name = 'token_hash' AND Non_unique = 0" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$idem_unique = $wpdb->get_var( "SHOW INDEX FROM {$holds} WHERE Key_name = 'idempotency_key' AND Non_unique = 0" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$order_unique = $wpdb->get_var( "SHOW INDEX FROM {$holds} WHERE Key_name = 'order_id' AND Non_unique = 0" );

		return (bool) $slot_mutex && (bool) $hours_unique && (bool) $exception_unique && (bool) $token_unique && (bool) $idem_unique && (bool) $order_unique;
	}

	/**
	 * Seed default settings the first time the plugin is activated.
	 *
	 * @return void
	 */
	private static function add_default_options() {
		if ( false !== get_option( 'doughboss_settings' ) ) {
			return;
		}

		$defaults = array(
			'currency_symbol' => '$',
			'currency_code'   => 'AUD',
			'tax_rate'        => 10,
			'gst_inclusive'   => 1,
			'delivery_fee'    => 5.00,
			'enable_pickup'   => 1,
			'enable_delivery' => 0,
			'ordering_open'   => 1,
			'sizes'           => array(
				array(
					'slug'  => 'small',
					'label' => 'Small (10")',
					'price' => 9.00,
				),
				array(
					'slug'  => 'medium',
					'label' => 'Medium (12")',
					'price' => 12.00,
				),
				array(
					'slug'  => 'large',
					'label' => 'Large (16")',
					'price' => 15.00,
				),
			),
			'toppings'        => array(
				array(
					'slug'  => 'pepperoni',
					'label' => 'Pepperoni',
					'price' => 1.50,
				),
				array(
					'slug'  => 'mushrooms',
					'label' => 'Mushrooms',
					'price' => 1.00,
				),
				array(
					'slug'  => 'extra-cheese',
					'label' => 'Extra Cheese',
					'price' => 1.50,
				),
				array(
					'slug'  => 'olives',
					'label' => 'Olives',
					'price' => 1.00,
				),
				array(
					'slug'  => 'onions',
					'label' => 'Onions',
					'price' => 0.75,
				),
			),
		);

		add_option( 'doughboss_settings', $defaults );
	}

	/**
	 * Give administrators the management capabilities and ensure a low-privilege
	 * kitchen role exists for staff who only need the live order board.
	 *
	 * Public and idempotent so the migration runner can call it on upgrade.
	 *
	 * @return void
	 */
	public static function add_capabilities() {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			if ( ! $admin->has_cap( 'manage_doughboss' ) ) {
				$admin->add_cap( 'manage_doughboss' );
			}
			if ( ! $admin->has_cap( 'manage_doughboss_kds' ) ) {
				$admin->add_cap( 'manage_doughboss_kds' );
			}
			if ( ! $admin->has_cap( 'redeem_doughboss_vouchers' ) ) {
				$admin->add_cap( 'redeem_doughboss_vouchers' );
			}
		}

		// Kitchen staff role: just enough to open the order board and scan
		// vouchers on a shop tablet — never a full admin login on a device in
		// the kitchen.
		$kitchen = get_role( 'doughboss_kitchen' );
		if ( ! $kitchen ) {
			add_role(
				'doughboss_kitchen',
				__( 'DoughBoss Kitchen', 'doughboss' ),
				array(
					'read'                      => true,
					'manage_doughboss_kds'      => true,
					'redeem_doughboss_vouchers' => true,
				)
			);
		} elseif ( ! $kitchen->has_cap( 'redeem_doughboss_vouchers' ) ) {
			$kitchen->add_cap( 'redeem_doughboss_vouchers' );
		}

		// Owner/Manager role: full DoughBoss management (menu, orders, settings,
		// KDS, vouchers) without granting full WordPress administrator access.
		$manager = get_role( 'doughboss_manager' );
		if ( ! $manager ) {
			add_role(
				'doughboss_manager',
				__( 'DoughBoss Manager', 'doughboss' ),
				array(
					'read'                      => true,
					'manage_doughboss'          => true,
					'manage_doughboss_kds'      => true,
					'redeem_doughboss_vouchers' => true,
				)
			);
		} else {
			if ( ! $manager->has_cap( 'manage_doughboss' ) ) {
				$manager->add_cap( 'manage_doughboss' );
			}
			if ( ! $manager->has_cap( 'manage_doughboss_kds' ) ) {
				$manager->add_cap( 'manage_doughboss_kds' );
			}
			if ( ! $manager->has_cap( 'redeem_doughboss_vouchers' ) ) {
				$manager->add_cap( 'redeem_doughboss_vouchers' );
			}
		}
	}
}
