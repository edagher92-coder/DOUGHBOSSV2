­r‡^Ñf¥–Ø¦{M¬yÊ'vÃ®¶›­<?php
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

		// Fresh activations are immediately stamped with the current database
		// version below, so versioned migrations do not run first. Seed the
		// default shop here as well as in the 1.2 migration to ensure a brand-new
		// single-shop install never starts with an empty location table.
		require_once DOUGHBOSS_PLUGIN_DIR . 'includes/class-doughboss-settings.php';
		require_once DOUGHBOSS_PLUGIN_DIR . 'includes/class-doughboss-locations.php';
		DoughBoss_Locations::ensure_default();

		// Register post types so rewrite rules exist, then flush them.
		require_once DOUGHBOSS_PLUGIN_DIR . 'includes/class-doughboss-post-types.php';
		require_once DOUGHBOSS_PLUGIN_DIR . 'includes/class-doughboss-catering-package.php';
		DoughBoss_Post_Types::register();
		DoughBoss_Catering_Package::register();
		flush_rewrite_rules();

		if ( self::lifecycle_storage_ready() && self::capacity_storage_ready() && self::checkout_storage_ready() && self::table_qr_storage_ready() && self::table_occupancy_storage_ready() && self::payment_storage_ready() && self::pospal_outbox_storage_ready() && self::timeclock_storage_ready() ) {
			update_option( 'doughboss_db_version', DOUGHBOSS_DB_VERSION );
			delete_option( 'doughboss_migration_error' );
		} else {
			update_option( 'doughboss_migration_error', 'Transactional order, capacity, checkout-integrity, table-QR/table-occupancy, payment-attempt, POSPal outbox, or staff-attendance storage is incomplete or is not using InnoDB.' );
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
		$table_reservation_events = $wpdb->prefix . 'doughboss_table_reservation_events';
		$payment_attempts = $wpdb->prefix . 'doughboss_payment_attempts';
		$payment_events   = $wpdb->prefix . 'doughboss_payment_events';
		$checkout_snapshots = $wpdb->prefix . 'doughboss_checkout_snapshots';
		$loyalty_members = $wpdb->prefix . 'doughboss_loyalty_members';
		$loyalty_ledger  = $wpdb->prefix . 'doughboss_loyalty_ledger';
		$loyalty_tokens  = $wpdb->prefix . 'doughboss_loyalty_tokens';
		$staff_shifts    = $wpdb->prefix . 'doughboss_staff_shifts';
		$staff_events    = $wpdb->prefix . 'doughboss_staff_shift_events';

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
			manual_reserved_until datetime NULL DEFAULT NULL,
			manual_released_at datetime NULL DEFAULT NULL,
			manual_release_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			reservation_version bigint(20) unsigned NOT NULL DEFAULT 1,
			created_at datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY location_label (location_id,label),
			KEY location_active (location_id,is_active),
			KEY location_manual_reservation (location_id,manual_reserved_until)
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

		$sql_table_reservation_events = "CREATE TABLE {$table_reservation_events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			table_id bigint(20) unsigned NOT NULL,
			reservation_version bigint(20) unsigned NOT NULL,
			event_type varchar(20) NOT NULL,
			actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_key varchar(191) NOT NULL,
			reserved_until datetime NULL DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_key (event_key),
			KEY table_created (table_id,created_at)
		) ENGINE=InnoDB {$charset_collate};";

		$sql_catering = "CREATE TABLE {$catering} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			enquiry_number varchar(32) NOT NULL,
			loóO8¶‰Ëkºwµçp€ôôô€‘É½Ü´ùMÕ‰}Á…ÉĞ€ü¹Õ±°€è€¡¥¹Ğ¤€‘É½Ü´ùMÕ‰}Á…ÉĞì($$%¥˜€ ($$$$¡ÍÑÉ¥¹œ¤€‘É½Ü´ù½±Õµ¹}¹…µ”€„ôô€‘½±Õµ¹Íl€‘½™™Í•Ğt($$$%ñğ€ €‘Õ¹¥ÅÕ”€ü€À€è€Ä€¤€„ôô€¡¥¹Ğ¤€‘É½Ü´ù9½¹}Õ¹¥ÅÕ”($$$%ñğ€ ¹Õ±°€„ôô€‘ÍÕ‰}Á…ÉĞ€˜˜€ €„¥ÍÍ•Ğ €‘±•¹Ñ¡Íl€‘½™™Í•Ğt€¤ñğ€‘ÍÕ‰}Á…ÉĞ€ğ€‘±•¹Ñ¡Íl€‘½™™Í•Ğt€¤€¤($$$¤ì($$$%É•ÑÕÉ¸™…±Í”ì($$%ô($%ô($%É•ÑÕÉ¸ÑÉÕ”ì(%ô(($¼¨¨($€¨Y•É¥™äÑ¡”‘ÕÉ…‰±”¡•­½ÕĞÉ•Á±…ä…¹½¹”µÁ…åµ•¹Ğ½½¹”µ½É‘•È½¹ÍÑÉ…¥¹ÑÌ¸($€¨($€¨É•ÑÕÉ¸‰½½°($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸¡•­½ÕÑ}ÍÑ½É…•}É•…‘ä ¤ì($%±½‰…°€‘İÁ‘ˆì($$‘½É‘•ÉÌ€ô€‘İÁ‘ˆ´ùÁÉ•™¥à€¸€‘½Õ¡‰½ÍÍ}½É‘•ÉÌœì($$¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($$‘•¹¥¹”€ô€‘İÁ‘ˆ´ù•Ñ}Ù…È €‘İÁ‘ˆ´ùÁÉ•Á…É” €M1P9%9I=4¥¹™½Éµ…Ñ¥½¹}Í¡•µ„¹Q	1L]!IQ	1}M!5€ôQ	M ¤9Q	1}95€ô€•Ìœ°€‘½É‘•ÉÌ€¤€¤ì($%¥˜€ €„€‘•¹¥¹”ñğ€%99=œ€„ôôÍÑÉÑ½ÕÁÁ•È €‘•¹¥¹”€¤€¤ì($$%É•ÑÕÉ¸™…±Í”ì($%ô(($$‘½±Õµ¹Ì€ô…ÉÉ…ä ($$$Á…åµ•¹Ñ}¥¹Ñ•¹Ñ}¥œ€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€Ù…É¡…È ÄäÄ¤œ°€¹Õ±°œ€ôø€eLœ°€‘•™…Õ±Ğœ€ôø¹Õ±°€¤°($$$¡•­½ÕÑ}­•äœ€€€€€€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€¡…È ØĞ¤œ°€¹Õ±°œ€ôø€eLœ°€‘•™…Õ±Ğœ€ôø¹Õ±°€¤°($$¤ì($%É•ÑÕÉ¸Í•±˜èé½±Õµ¹}½¹ÑÉ…Ñ}É•…‘ä €‘½É‘•ÉÌ°€‘½±Õµ¹Ì€¤($$$˜˜Í•±˜èé¥¹‘•á}½¹ÑÉ…Ñ}É•…‘ä €‘½É‘•ÉÌ°€Á…åµ•¹Ñ}¥¹Ñ•¹Ñ}¥œ°…ÉÉ…ä €Á…åµ•¹Ñ}¥¹Ñ•¹Ñ}¥œ€¤°ÑÉÕ”°…ÉÉ…ä €ÄäÄ€¤€¤($$$˜˜Í•±˜èé¥¹‘•á}½¹ÑÉ…Ñ}É•…‘ä €‘½É‘•ÉÌ°€¡•­½ÕÑ}­•äœ°…ÉÉ…ä €¡•­½ÕÑ}­•äœ€¤°ÑÉÕ”°…ÉÉ…ä €ØĞ€¤€¤ì(%ô(($¼¨¨($€¨Y•É¥™äÑ¡”ÍÑ½É”½Ñ…‰±”EH½É‘•É¥¹œÍ¡•µ„‰•™½É”…•ÁÑ¥¹œÑ…‰±”½É‘•ÉÌ¸($€¨($€¨É•ÑÕÉ¸‰½½°($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸Ñ…‰±•}ÅÉ}ÍÑ½É…•}É•…‘ä ¤ì($%±½‰…°€‘İÁ‘ˆì($$‘½É‘•ÉÌ€€€ô€‘İÁ‘ˆ´ùÁÉ•™¥à€¸€‘½Õ¡‰½ÍÍ}½É‘•ÉÌœì($$‘Ñ…‰±•Ì€€€ô€‘İÁ‘ˆ´ùÁÉ•™¥à€¸€‘½Õ¡‰½ÍÍ}‘¥¹¥¹}Ñ…‰±•Ìœì($$‘½‘•Ì€€€€ô€‘İÁ‘ˆ´ùÁÉ•™¥à€¸€‘½Õ¡‰½ÍÍ}Ñ…‰±•}ÅÉ}½‘•Ìœì($$‘Í•ÍÍ¥½¹Ì€ô€‘İÁ‘ˆ´ùÁÉ•™¥à€¸€‘½Õ¡‰½ÍÍ}Ñ…‰±•}Í•ÍÍ¥½¹Ìœì(($%™½É•… € …ÉÉ…ä €‘½É‘•ÉÌ°€‘Ñ…‰±•Ì°€‘½‘•Ì°€‘Í•ÍÍ¥½¹Ì€¤…Ì€‘Ñ…‰±”€¤ì($$$¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($$$‘•¹¥¹”€ô€‘İÁ‘ˆ´ù•Ñ}Ù…È €‘İÁ‘ˆ´ùÁÉ•Á…É” €M1P9%9I=4¥¹™½Éµ…Ñ¥½¹}Í¡•µ„¹Q	1L]!IQ	1}M!5€ôQ	M ¤9Q	1}95€ô€•Ìœ°€‘Ñ…‰±”€¤€¤ì($$%¥˜€ €„€‘•¹¥¹”ñğ€%99=œ€„ôôÍÑÉÑ½ÕÁÁ•È €‘•¹¥¹”€¤€¤ì($$$%É•ÑÕÉ¸™…±Í”ì($$%ô($%ô(($%É•ÑÕÉ¸Í•±˜èé½±Õµ¹}½¹ÑÉ…Ñ}É•…‘ä ($$$‘½É‘•ÉÌ°($$%…ÉÉ…ä ($$$$Ñ…‰±•}¥œ€€€€€€€€€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€‰¥¥¹Ğ ÈÀ¤Õ¹Í¥¹•œ°€¹Õ±°œ€ôø€9<œ°€‘•™…Õ±Ğœ€ôø€œÀœ€¤°($$$$Ñ…‰±•}±…‰•°œ€€€€€€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€Ù…É¡…È àÀ¤œ°€¹Õ±°œ€ôø€9<œ°€‘•™…Õ±Ğœ€ôø€œœ€¤°($$$$Ñ…‰±•}ÅÉ}½‘•}¥œ€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€‰¥¥¹Ğ ÈÀ¤Õ¹Í¥¹•œ°€¹Õ±°œ€ôø€9<œ°€‘•™…Õ±Ğœ€ôø€œÀœ€¤°($$$$Ñ…‰±•}Í•ÍÍ¥½¹}¥œ€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€‰¥¥¹Ğ ÈÀ¤Õ¹Í¥¹•œ°€¹Õ±°œ€ôø€9<œ°€‘•™…Õ±Ğœ€ôø€œÀœ€¤°($$$$½É‘•É}Í½ÕÉ”œ€€€€€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€Ù…É¡…È ÈÀ¤œ°€¹Õ±°œ€ôø€9<œ°€‘•™…Õ±Ğœ€ôø€İ•ˆœ€¤°($$$¤($$¤($$$˜˜Í•±˜èé½±Õµ¹}½¹ÑÉ…Ñ}É•…‘ä ($$$$‘Ñ…‰±•Ì°($$$%…ÉÉ…ä ($$$$$±½…Ñ¥½¹}¥œ€€€€€€€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€‰¥¥¹Ğ ÈÀ¤Õ¹Í¥¹•œ°€¹Õ±°œ€ôø€9<œ€¤°($$$$$±…‰•°œ€€€€€€€€€€€€€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€Ù…É¡…È àÀ¤œ°€¹Õ±°œ€ôø€9<œ°€‘•™…Õ±Ğœ€ôø€œœ€¤°($$$$$½É‘•É¥¹}ÕÉ°œ€€€€€€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€Ù…É¡…È ÈÔÔ¤œ°€¹Õ±°œ€ôø€9<œ°€‘•™…Õ±Ğœ€ôø€œœ€¤°($$$$$¥Í}…Ñ¥Ù”œ€€€€€€€€€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€Ñ¥¹å¥¹Ğ Ä¤œ°€¹Õ±°œ€ôø€9<œ°€‘•™…Õ±Ğœ€ôø€œÄœ€¤°($$$$$ÕÉÉ•¹Ñ}ÅÉ}½‘•}¥œôø…ÉÉ…ä €ÑåÁ”œ€ôø€‰¥¥¹Ğ ÈÀ¤Õ¹Í¥¹•œ°€¹Õ±°œ€ôø€9<œ°€‘•™…Õ±Ğœ€ôø€œÀœ€¤°($$$$¤($$$¤($$$˜˜Í•±˜èé½±Õµ¹}½¹ÑÉ…Ñ}É•…‘ä ($$$$‘½‘•Ì°($$$%…ÉÉ…ä ($$$$$Ñ…‰±•}¥œ€€€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€‰¥¥¹Ğ ÈÀ¤Õ¹Í¥¹•œ°€¹Õ±°œ€ôø€9<œ€¤°($$$$$Ñ½­•¹}¡…Í œ€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€¡…È ØĞ¤œ°€¹Õ±°œ€ôø€9<œ€¤°($$$$$ÍÑ…ÑÕÌœ€€€€€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€Ù…É¡…È ÈÀ¤œ°€¹Õ±°œ€ôø€9<œ°€‘•™…Õ±Ğœ€ôø€…Ñ¥Ù”œ€¤°($$$$¤($$$¤($$$˜˜Í•±˜èé½±Õµ¹}½¹ÑÉ…Ñ}É•…‘ä ($$$$‘Í•ÍÍ¥½¹Ì°($$$%…ÉÉ…ä ($$$$$Í•ÍÍ¥½¹}¡…Í œ€€€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€¡…È ØĞ¤œ°€¹Õ±°œ€ôø€9<œ€¤°($$$$$ÅÉ}½‘•}¥œ€€€€€€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€‰¥¥¹Ğ ÈÀ¤Õ¹Í¥¹•œ°€¹Õ±°œ€ôø€9<œ€¤°($$$$$…ÉÑ}Ñ½­•¹}¡…Í œ€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€¡…È ØĞ¤œ°€¹Õ±°œ€ôø€9<œ€¤°($$$$$•áÁ¥É•Í}…Ğœ€€€€€€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€‘…Ñ•Ñ¥µ”œ°€¹Õ±°œ€ôø€9<œ€¤°($$$$¤($$$¤($$$˜˜Í•±˜èé¥¹‘•á}½¹ÑÉ…Ñ}É•…‘ä €‘Ñ…‰±•Ì°€±½…Ñ¥½¹}±…‰•°œ°…ÉÉ…ä €±½…Ñ¥½¹}¥œ°€±…‰•°œ€¤°ÑÉÕ”€¤($$$˜˜Í•±˜èé¥¹‘•á}½¹ÑÉ…Ñ}É•…‘ä €‘½‘•Ì°€Ñ½­•¹}¡…Í œ°…ÉÉ…ä €Ñ½­•¹}¡…Í œ€¤°ÑÉÕ”°…ÉÉ…ä €ØĞ€¤€¤($$$˜˜Í•±˜èé¥¹‘•á}½¹ÑÉ…Ñ}É•…‘ä €‘½‘•Ì°€Ñ…‰±•}ÍÑ…ÑÕÌœ°…ÉÉ…ä €Ñ…‰±•}¥œ°€ÍÑ…ÑÕÌœ€¤°™…±Í”€¤($$$˜˜Í•±˜èé¥¹‘•á}½¹ÑÉ…Ñ}É•…‘ä €‘Í•ÍÍ¥½¹Ì°€Í•ÍÍ¥½¹}¡…Í œ°…ÉÉ…ä €Í•ÍÍ¥½¹}¡…Í œ€¤°ÑÉÕ”°…ÉÉ…ä €ØĞ€¤€¤($$$˜˜Í•±˜èé¥¹‘•á}½¹ÑÉ…Ñ}É•…‘ä €‘Í•ÍÍ¥½¹Ì°€ÅÉ}½‘•}¥œ°…ÉÉ…ä €ÅÉ}½‘•}¥œ€¤°™…±Í”€¤($$$˜˜Í•±˜èé¥¹‘•á}½¹ÑÉ…Ñ}É•…‘ä €‘Í•ÍÍ¥½¹Ì°€•áÁ¥É•Í}…Ğœ°…ÉÉ…ä €•áÁ¥É•Í}…Ğœ€¤°™…±Í”€¤($$$˜˜Í•±˜èé¥¹‘•á}½¹ÑÉ…Ñ}É•…‘ä €‘½É‘•ÉÌ°€±½…Ñ¥½¹}Ñ…‰±•}É•…Ñ•œ°…ÉÉ…ä €±½…Ñ¥½¹}¥œ°€Ñ…‰±•}¥œ°€É•…Ñ•‘}…Ğœ€¤°™…±Í”€¤ì(%ô(($¼¨¨($€¨Y•É¥™ä½¹±äÑ¡”½ÁÑ¥½¹…°ÍÑ…™˜Ñ…‰±”µ½ÕÁ…¹äÁÉ½©•Ñ¥½¸¸Q¡¥Ì¥Ì­•ÁĞ($€¨Í•Á…É…Ñ”™É½´Ñ…‰±•}ÅÉ}ÍÑ½É…•}É•…‘ä ¤è„‘¥ÍÁ±…ä½½¹ÑÉ½°µ¥É…Ñ¥½¸¥ÍÍÕ”($€¨µÕÍĞ¹•Ù•È‘¥Í…‰±”Í•ÕÉ”ÕÍÑ½µ•ÈÑ…‰±”½É‘•É¥¹œ½È„Á…åµ•¹Ğ™±½Ü¸($€¨($€¨É•ÑÕÉ¸‰½½°($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸Ñ…‰±•}½ÕÁ…¹å}ÍÑ½É…•}É•…‘ä ¤ì($%±½‰…°€‘İÁ‘ˆì($$‘Ñ…‰±•Ì€ô€‘İÁ‘ˆ´ùÁÉ•™¥à€¸€‘½Õ¡‰½ÍÍ}‘¥¹¥¹}Ñ…‰±•Ìœì($$‘•Ù•¹ÑÌ€ô€‘İÁ‘ˆ´ùÁÉ•™¥à€¸€‘½Õ¡‰½ÍÍ}Ñ…‰±•}É•Í•ÉÙ…Ñ¥½¹}•Ù•¹ÑÌœì($%™½É•… € …ÉÉ…ä €‘Ñ…‰±•Ì°€‘•Ù•¹ÑÌ€¤…Ì€‘Ñ…‰±”€¤ì($$$¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($$$‘•¹¥¹”€ô€‘İÁ‘ˆ´ù•Ñ}Ù…È €‘İÁ‘ˆ´ùÁÉ•Á…É” €M1P9%9I=4¥¹™½Éµ…Ñ¥½¹}Í¡•µ„¹Q	1L]!IQ	1}M!5€ôQ	M ¤9Q	1}95€ô€•Ìœ°€‘Ñ…‰±”€¤€¤ì($$%¥˜€ €„€‘•¹¥¹”ñğ€%99=œ€„ôôÍÑÉÑ½ÕÁÁ•È €‘•¹¥¹”€¤€¤ì($$$%É•ÑÕÉ¸™…±Í”ì($$%ô($%ô($%É•ÑÕÉ¸Í•±˜èé½±Õµ¹}½¹ÑÉ…Ñ}É•…‘ä ($$$‘Ñ…‰±•Ì°($$%…ÉÉ…ä ($$$$µ…¹Õ…±}É•Í•ÉÙ•‘}Õ¹Ñ¥°œ€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€‘…Ñ•Ñ¥µ”œ°€¹Õ±°œ€ôø€eLœ°€‘•™…Õ±Ğœ€ôø¹Õ±°€¤°($$$$µ…¹Õ…±}É•±•…Í•‘}…Ğœ€€€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€‘…Ñ•Ñ¥µ”œ°€¹Õ±°œ€ôø€eLœ°€‘•™…Õ±Ğœ€ôø¹Õ±°€¤°($$$$µ…¹Õ…±}É•±•…Í•}½É‘•É}¥œ€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€‰¥¥¹Ğ ÈÀ¤Õ¹Í¥¹•œ°€¹Õ±°œ€ôø€9<œ°€‘•™…Õ±Ğœ€ôø€œÀœ€¤°($$$$É•Í•ÉÙ…Ñ¥½¹}Ù•ÉÍ¥½¸œ€€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€‰¥¥¹Ğ ÈÀ¤Õ¹Í¥¹•œ°€¹Õ±°œ€ôø€9<œ°€‘•™…Õ±Ğœ€ôø€œÄœ€¤°($$$¤($$¤($$$˜˜Í•±˜èé½±Õµ¹}½¹ÑÉ…Ñ}É•…‘ä ($$$$‘•Ù•¹ÑÌ°($$$%…ÉÉ…ä ($$$$$Ñ…‰±•}¥œ€€€€€€€€€€€€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€‰¥¥¹Ğ ÈÀ¤Õ¹Í¥¹•œ°€¹Õ±°œ€ôø€9<œ€¤°($$$$$É•Í•ÉÙ…Ñ¥½¹}Ù•ÉÍ¥½¸œ€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€‰¥¥¹Ğ ÈÀ¤Õ¹Í¥¹•œ°€¹Õ±°œ€ôø€9<œ€¤°($$$$$•Ù•¹Ñ}ÑåÁ”œ€€€€€€€€€€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€Ù…É¡…È ÈÀ¤œ°€¹Õ±°œ€ôø€9<œ€¤°($$$$$…Ñ½É}ÕÍ•É}¥œ€€€€€€€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€‰¥¥¹Ğ ÈÀ¤Õ¹Í¥¹•œ°€¹Õ±°œ€ôø€9<œ°€‘•™…Õ±Ğœ€ôø€œÀœ€¤°($$$$$•Ù•¹Ñ}­•äœ€€€€€€€€€€€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€Ù…É¡…È ÄäÄ¤œ°€¹Õ±°œ€ôø€9<œ€¤°($$$$$É•Í•ÉÙ•‘}Õ¹Ñ¥°œ€€€€€€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€‘…Ñ•Ñ¥µ”œ°€¹Õ±°œ€ôø€eLœ°€‘•™…Õ±Ğœ€ôø¹Õ±°€¤°($$$$$É•…Ñ•‘}…Ğœ€€€€€€€€€€ôø…ÉÉ…ä €ÑåÁ”œ€ôø€‘…Ñ•Ñ¥µ”œ°€¹Õ±°œ€ôø€9<œ€¤°($$$$¤($$$¤($$$˜˜Í•±˜èé¥¹‘•á}½¹ÑÉ…Ñ}É•…‘ä €‘Ñ…‰±•Ì°€±½…Ñ¥½¹}µ…¹Õ…±}É•Í•ÉÙ…Ñ¥½¸œ°…ÉÉ…ä €±½…Ñ¥½¹}¥œ°€µ…¹Õ…±}É•Í•ÉÙ•‘}Õ¹Ñ¥°œ€¤°™…±Í”€¤($$$˜˜Í•±˜èé¥¹‘•á}½¹ÑÉ…Ñ}É•…‘ä €‘•Ù•¹ÑÌ°€•Ù•¹Ñ}­•äœ°…ÉÉ…ä €•Ù•¹Ñ}­•äœ€¤°ÑÉÕ”°…ÉÉ…ä €ÄäÄ€¤€¤($$$˜˜Í•±˜èé¥¹‘•á}½¹ÑÉ…Ñ}É•…‘ä €‘•Ù•¹ÑÌ°€Ñ…‰±•}É•…Ñ•œ°…ÉÉ…ä €Ñ…‰±•}¥œ°€É•…Ñ•‘}…Ğœ€¤°™…±Í”€¤ì(%ô(($¼¨¨($€¨Y•É¥™äÑ¡”A¡…Í”€Ì…Á…¥ÑäÑ…‰±•Ì…¹µÕÑ•à½Õ¹¥ÅÕ•¹•ÍÌ½¹ÍÑÉ…¥¹ÑÌ¸($€¨($€¨É•ÑÕÉ¸‰½½°($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸…Á…¥Ñå}ÍÑ½É…•}É•…‘ä ¤ì($%±½‰…°€‘İÁ‘ˆì($$‘½É‘•ÉÌ€€€€€ô€‘İÁ‘ˆ´ùÁÉ•™¥à€¸€‘½Õ¡‰½ÍÍ}½É‘•ÉÌœì($$‘±½…Ñ¥½¹Ì€€ô€‘İÁ‘ˆ´ùÁÉ•™¥à€¸€‘½Õ¡‰½ÍÍ}±½…Ñ¥½¹Ìœì($$‘¡½ÕÉÌ€€€€€€ô€‘İÁ‘ˆ´ùÁÉ•™¥à€¸€‘½Õ¡‰½ÍÍ}±½…Ñ¥½¹}¡½ÕÉÌœì($$‘•á•ÁÑ¥½¹Ì€ô€‘İÁ‘ˆ´ùÁÉ•™¥à€¸€‘½Õ¡‰½ÍÍ}Í¡•‘Õ±•}•á•ÁÑ¥½¹Ìœì($$‘Í±½ÑÌ€€€€€€ô€‘İÁ‘ˆ´ùÁÉ•™¥à€¸€‘½Õ¡‰½ÍÍ}…Á…¥Ñå}Í±½ÑÌœì($$‘¡½±‘Ì€€€€€€ô€‘İÁ‘ˆ´ùÁÉ•™¥à€¸€‘½Õ¡‰½ÍÍ}…Á…¥Ñå}¡½±‘Ìœì($$‘É•ÅÕ¥É•€ô…ÉÉ…ä ($$$‘½É‘•ÉÌ€ôø…ÉÉ…ä €…Á…¥Ñå}¡½±‘}¥œ°€…Á…¥Ñå}Õ¹¥ÑÌœ°€™¥É•}…Ñ}ÕÑŒœ°€Á±…¹¹¥¹}Ù•ÉÍ¥½¸œ€¤°($$$‘±½…Ñ¥½¹Ì€ôø…ÉÉ…ä €Ñ¥µ•é½¹”œ°€…Á…¥Ñå}µ½‘”œ°€Í±½Ñ}µ¥¹ÕÑ•Ìœ°€µ¥¹¥µÕµ}¹½Ñ¥•}µ¥¹ÕÑ•Ìœ°€‰½½­¥¹}¡½É¥é½¹}‘…åÌœ°€¡½±‘}µ¥¹ÕÑ•Ìœ°€Í±½Ñ}½É‘•É}…Á…¥Ñäœ°€Í±½Ñ}Õ¹¥Ñ}…Á…¥Ñäœ°€Á±…¹¹¥¹}Ù•ÉÍ¥½¸œ€¤°($$$‘¡½ÕÉÌ€ôø…ÉÉ…ä €±½…Ñ¥½¹}¥œ°€½É‘•É}ÑåÁ”œ°€İ••­‘…äœ°€Í•µ•¹Ğœ°€½Á•¹Í}…Ğœ°€±½Í•Í}…Ğœ°€¥Í}…Ñ¥Ù”œ€¤°($$$‘•á•ÁÑ¥½¹Ì€ôø…ÉÉ…ä €±½…Ñ¥½¹}¥œ°€½É‘•É}ÑåÁ”œ°€Í•ÉÙ¥•}‘…Ñ”œ°€Í•µ•¹Ğœ°€¥Í}±½Í•œ°€½Á•¹Í}…Ğœ°€±½Í•Í}…Ğœ°€½É‘•É}…Á…¥Ñäœ°€Õ¹¥Ñ}…Á…¥Ñäœ€¤°($$$‘Í±½ÑÌ€ôø…ÉÉ…ä €±½…Ñ¥½¹}¥œ°€½É‘•É}ÑåÁ”œ°€ÍÑ…ÉÑÍ}…Ñ}ÕÑŒœ°€•¹‘Í}…Ñ}ÕÑŒœ°€Ñ¥µ•é½¹•}Í¹…ÁÍ¡½Ğœ°€½É‘•É}…Á…¥Ñäœ°€Õ¹¥Ñ}…Á…¥Ñäœ°€Á±…¹¹¥¹}Ù•ÉÍ¥½¸œ°€…•ÁÑ¥¹}¡½±‘Ìœ€¤°($$$‘¡½±‘Ì€ôø…ÉÉ…ä €Í±½Ñ}¥œ°€Ñ½­•¹}¡…Í œ°€¥‘•µÁ½Ñ•¹å}­•äœ°€…ÉÑ}¡…Í œ°€ÍÑ…ÑÕÌœ°€…Á…¥Ñå}Õ¹¥ÑÌœ°€•áÁ¥É•Í}…Ğœ°€½É‘•É}¥œ°€½¹Ù•ÉÑ•‘}…Ğœ°€É•±•…Í•‘}…Ğœ€¤°($$¤ì($%™½É•… € €‘É•ÅÕ¥É•…Ì€‘Ñ…‰±”€ôø€‘½±Õµ¹Ì€¤ì($$$¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($$$‘•¹¥¹”€ô€‘İÁ‘ˆ´ù•Ñ}Ù…È €‘İÁ‘ˆ´ùÁÉ•Á…É” €M1P9%9I=4¥¹™½Éµ…Ñ¥½¹}Í¡•µ„¹Q	1L]!IQ	1}M!5€ôQ	M ¤9Q	1}95€ô€•Ìœ°€‘Ñ…‰±”€¤€¤ì($$%¥˜€ €„€‘•¹¥¹”ñğ€%99=œ€„ôôÍÑÉÑ½ÕÁÁ•È €‘•¹¥¹”€¤€¤ì($$$%É•ÑÕÉ¸™…±Í”ì($$%ô($$$¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä°]½É‘AÉ•ÍÌ¹¹AÉ•Á…É•‘ME0¹%¹Ñ•ÉÁ½±…Ñ•‘9½ÑAÉ•Á…É•($$$‘…ÑÕ…°€ô€¡…ÉÉ…ä¤€‘İÁ‘ˆ´ù•Ñ}½° €‰M!=\=1U59LI=4ì‘Ñ…‰±•ôˆ€¤ì($$%¥˜€ …ÉÉ…å}‘¥™˜ €‘½±Õµ¹Ì°€‘…ÑÕ…°€¤€¤ì($$$%É•ÑÕÉ¸™…±Í”ì($$%ô($%ô(($$¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä°]½É‘AÉ•ÍÌ¹¹AÉ•Á…É•‘ME0¹%¹Ñ•ÉÁ½±…Ñ•‘9½ÑAÉ•Á…É•($$‘Í±½Ñ}µÕÑ•à€ô€‘İÁ‘ˆ´ù•Ñ}Ù…È €‰M!=\%9`I=4ì‘Í±½ÑÍô]!I-•å}¹…µ”€ô€±½…Ñ¥½¹}Í±½Ğœ99½¹}Õ¹¥ÅÕ”€ô€Àˆ€¤ì($$¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä°]½É‘AÉ•ÍÌ¹¹AÉ•Á…É•‘ME0¹%¹Ñ•ÉÁ½±…Ñ•‘9½ÑAÉ•Á…É•($$‘¡½ÕÉÍ}Õ¹¥ÅÕ”€ô€‘İÁ‘ˆ´ù•Ñ}Ù…È €‰M!=\%9`I=4ì‘¡½ÕÉÍô]!I-•å}¹…µ”€ô€±½…Ñ¥½¹}Í¡•‘Õ±”œ99½¹}Õ¹¥ÅÕ”€ô€Àˆ€¤ì($$¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä°]½É‘AÉ•ÍÌ¹¹AÉ•Á…É•‘ME0¹%¹Ñ•ÉÁ½±…Ñ•‘9½ÑAÉ•Á…É•($$‘•á•ÁÑ¥½¹}Õ¹¥ÅÕ”€ô€‘İÁ‘ˆ´ù•Ñ}Ù…È €‰M!=\%9`I=4ì‘•á•ÁÑ¥½¹Íô]!I-•å}¹…µ”€ô€±½…Ñ¥½¹}•á•ÁÑ¥½¸œ99½¹}Õ¹¥ÅÕ”€ô€Àˆ€¤ì($$¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä°]½É‘AÉ•ÍÌ¹¹AÉ•Á…É•‘ME0¹%¹Ñ•ÉÁ½±…Ñ•‘9½ÑAÉ•Á…É•($$‘Ñ½­•¹}Õ¹¥ÅÕ”€ô€‘İÁ‘ˆ´ù•Ñ}Ù…È €‰M!=\%9`I=4ì‘¡½±‘Íô]!I-•å}¹…µ”€ô€Ñ½­•¹}¡…Í œ99½¹}Õ¹¥ÅÕ”€ô€Àˆ€¤ì($$¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä°]½É‘AÉ•ÍÌ¹¹AÉ•Á…É•‘ME0¹%¹Ñ•ÉÁ½±…Ñ•‘9½ÑAÉ•Á…É•($$‘¥‘•µ}Õ¹¥ÅÕ”€ô€‘İÁ‘ˆ´ù•Ñ}Ù…È €‰M!=\%9`I=4ì‘¡½±‘Íô]!I-•å}¹…µ”€ô€¥‘•µÁ½Ñ•¹å}­•äœ99½¹}Õ¹¥ÅÕ”€ô€Àˆ€¤ì($$¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä°]½É‘AÉ•ÍÌ¹¹AÉ•Á…É•‘ME0¹%¹Ñ•ÉÁ½±…Ñ•‘9½ÑAÉ•Á…É•($$‘½É‘•É}Õ¹¥ÅÕ”€ô€‘İÁ‘ˆ´ù•Ñ}Ù…È €‰M!=\%9`I=4ì‘¡½±‘Íô]!I-•å}¹…µ”€ô€½É‘•É}¥œ99½¹}Õ¹¥ÅÕ”€ô€Àˆ€¤ì(($%É•ÑÕÉ¸€¡‰½½°¤€‘Í±½Ñ}µÕÑ•à€˜˜€¡‰½½°¤€‘¡½ÕÉÍ}Õ¹¥ÅÕ”€˜˜€¡‰½½°¤€‘•á•ÁÑ¥½¹}Õ¹¥ÅÕ”€˜˜€¡‰½½°¤€‘Ñ½­•¹}Õ¹¥ÅÕ”€˜˜€¡‰½½°¤€‘¥‘•µ}Õ¹¥ÅÕ”€˜˜€¡‰½½°¤€‘½É‘•É}Õ¹¥ÅÕ”ì(%ô(($¼¨¨($€¨M••‘•™…Õ±ĞÍ•ÑÑ¥¹ÌÑ¡”™¥ÉÍĞÑ¥µ”Ñ¡”Á±Õ¥¸¥Ì…Ñ¥Ù…Ñ•¸($€¨($€¨É•ÑÕÉ¸Ù½¥($€¨¼(%ÁÉ¥Ù…Ñ”ÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸…‘‘}‘•™…Õ±Ñ}½ÁÑ¥½¹Ì ¤ì($%¥˜€ ™…±Í”€„ôô•Ñ}½ÁÑ¥½¸ €‘½Õ¡‰½ÍÍ}Í•ÑÑ¥¹Ìœ€¤€¤ì($$%É•ÑÕÉ¸ì($%ô(($$‘‘•™…Õ±ÑÌ€ô…ÉÉ…ä ($$$ÕÉÉ•¹å}Íåµ‰½°œ€ôø€œœ°($$$ÕÉÉ•¹å}½‘”œ€€€ôø€Uœ°($$$Ñ…á}É…Ñ”œ€€€€€€€€ôø€ÄÀ°($$$ÍÑ}¥¹±ÕÍ¥Ù”œ€€€ôø€Ä°($$$‘•±¥Ù•Éå}™•”œ€€€€ôø€Ô¸ÀÀ°($$$•¹…‰±•}Á¥­ÕÀœ€€€ôø€Ä°($$$•¹…‰±•}‘•±¥Ù•Éäœ€ôø€À°($$$½É‘•É¥¹}½Á•¸œ€€€ôø€À°($$$½É‘•É¥¹}±½Í•‘}µ•ÍÍ…”œ€ôø€=¹±¥¹”½É‘•É¥¹œ¥Ì½µ¥¹œÍ½½¸¸e½Ô…¸‰É½İÍ”Ñ¡”µ•¹Ô¹½Ü°…¹İ”İ¥±°±•Ğå½Ô­¹½Üİ¡•¸¡•­½ÕĞ½Á•¹Ì¸œ°($$$Í¥é•Ìœ€€€€€€€€€€€ôø…ÉÉ…ä ($$$%…ÉÉ…ä ($$$$$Í±Õœœ€€ôø€Íµ…±°œ°($$$$$±…‰•°œ€ôø€Mµ…±°€ ÄÀˆ¤œ°($$$$$ÁÉ¥”œ€ôø€ä¸ÀÀ°($$$$¤°($$$%…ÉÉ…ä ($$$$$Í±Õœœ€€ôø€µ•‘¥Õ´œ°($$$$$±…‰•°œ€ôø€5•‘¥Õ´€ ÄÈˆ¤œ°($$$$$ÁÉ¥”œ€ôø€ÄÈ¸ÀÀ°($$$$¤°($$$%…ÉÉ…ä ($$$$$Í±Õœœ€€ôø€±…É”œ°($$$$$±…‰•°œ€ôø€1…É”€ ÄØˆ¤œ°($$$$$ÁÉ¥”œ€ôø€ÄÔ¸ÀÀ°($$$$¤°($$$¤°($$$Ñ½ÁÁ¥¹Ìœ€€€€€€€€ôø…ÉÉ…ä ($$$%…ÉÉ…ä ($$$$$Í±Õœœ€€ôø€Á•ÁÁ•É½¹¤œ°($$$$$±…‰•°œ€ôø€A•ÁÁ•É½¹¤œ°($$$$$ÁÉ¥”œ€ôø€Ä¸ÔÀ°($$$$¤°($$$%…ÉÉ…ä ($$$$$Í±Õœœ€€ôø€µÕÍ¡É½½µÌœ°($$$$$±…‰•°œ€ôø€5ÕÍ¡É½½µÌœ°($$$$$ÁÉ¥”œ€ôø€Ä¸ÀÀ°($$$$¤°($$$%…ÉÉ…ä ($$$$$Í±Õœœ€€ôø€•áÑÉ„µ¡••Í”œ°($$$$$±…‰•°œ€ôø€áÑÉ„¡••Í”œ°($$$$$ÁÉ¥”œ€ôø€Ä¸ÔÀ°($$$$¤°($$$%…ÉÉ…ä ($$$$$Í±Õœœ€€ôø€½±¥Ù•Ìœ°($$$$$±…‰•°œ€ôø€=±¥Ù•Ìœ°($$$$$ÁÉ¥”œ€ôø€Ä¸ÀÀ°($$$$¤°($$$%…ÉÉ…ä ($$$$$Í±Õœœ€€ôø€½¹¥½¹Ìœ°($$$$$±…‰•°œ€ôø€=¹¥½¹Ìœ°($$$$$ÁÉ¥”œ€ôø€À¸ÜÔ°($$$$¤°($$$¤°($$¤ì(($%…‘‘}½ÁÑ¥½¸ €‘½Õ¡‰½ÍÍ}Í•ÑÑ¥¹Ìœ°€‘‘•™…Õ±ÑÌ€¤ì(%ô(($¼¨¨($€¨¥Ù”…‘µ¥¹¥ÍÑÉ…Ñ½ÉÌÑ¡”µ…¹…•µ•¹Ğ…Á…‰¥±¥Ñ¥•Ì…¹•¹ÍÕÉ”„±½ÜµÁÉ¥Ù¥±•”($€¨­¥Ñ¡•¸É½±”•á¥ÍÑÌ™½ÈÍÑ…™˜İ¡¼½¹±ä¹••Ñ¡”±¥Ù”½É‘•È‰½…É¸($€¨($€¨AÕ‰±¥Œ…¹¥‘•µÁ½Ñ•¹ĞÍ¼Ñ¡”µ¥É…Ñ¥½¸ÉÕ¹¹•È…¸…±°¥Ğ½¸ÕÁÉ…‘”¸($€¨($€¨É•ÑÕÉ¸Ù½¥($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸…‘‘}…Á…‰¥±¥Ñ¥•Ì ¤ì($$‘…‘µ¥¸€ô•Ñ}É½±” €…‘µ¥¹¥ÍÑÉ…Ñ½Èœ€¤ì($%¥˜€ €‘…‘µ¥¸€¤ì($$%¥˜€ €„€‘…‘µ¥¸´ù¡…Í}…À €µ…¹…•}‘½Õ¡‰½ÍÌœ€¤€¤ì($$$$‘…‘µ¥¸´ù…‘‘}…À €µ…¹…•}‘½Õ¡‰½ÍÌœ€¤ì($$%ô($$%¥˜€ €„€‘…‘µ¥¸´ù¡…Í}…À €µ…¹…•}‘½Õ¡‰½ÍÍ}­‘Ìœ€¤€¤ì($$$$‘…‘µ¥¸´ù…‘‘}…À €µ…¹…•}‘½Õ¡‰½ÍÍ}­‘Ìœ€¤ì($$%ô($$%¥˜€ €„€‘…‘µ¥¸´ù¡…Í}…À €É•‘••µ}‘½Õ¡‰½ÍÍ}Ù½Õ¡•ÉÌœ€¤€¤ì($$$$‘…‘µ¥¸´ù…‘‘}…À €É•‘••µ}‘½Õ¡‰½ÍÍ}Ù½Õ¡•ÉÌœ€¤ì($$%ô($$%¥˜€ €„€‘…‘µ¥¸´ù¡…Í}…À €±½­}‘½Õ¡‰½ÍÍ}ÍÑ…™˜œ€¤€¤ì($$$$‘…‘µ¥¸´ù…‘‘}…À €±½­}‘½Õ¡‰½ÍÍ}ÍÑ…™˜œ€¤ì($$%ô($%ô(($$¼¼-¥Ñ¡•¸ÍÑ…™˜É½±”è©ÕÍĞ•¹½Õ Ñ¼½Á•¸Ñ¡”½É‘•È‰½…É…¹Í…¸($$¼¼Ù½Õ¡•ÉÌ½¸„Í¡½ÀÑ…‰±•ĞƒŠP¹•Ù•È„™Õ±°…‘µ¥¸±½¥¸½¸„‘•Ù¥”¥¸($$¼¼Ñ¡”­¥Ñ¡•¸¸($$‘­¥Ñ¡•¸€ô•Ñ}É½±” €‘½Õ¡‰½ÍÍ}­¥Ñ¡•¸œ€¤ì($%¥˜€ €„€‘­¥Ñ¡•¸€¤ì($$%…‘‘}É½±” ($$$$‘½Õ¡‰½ÍÍ}­¥Ñ¡•¸œ°($$$%}| €½Õ¡	½ÍÌ-¥Ñ¡•¸œ°€‘½Õ¡‰½ÍÌœ€¤°($$$%…ÉÉ…ä ($$$$$É•…œ€€€€€€€€€€€€€€€€€€€€€€ôøÑÉÕ”°($$$$$µ…¹…•}‘½Õ¡‰½ÍÍ}­‘Ìœ€€€€€€ôøÑÉÕ”°($$$$$É•‘••µ}‘½Õ¡‰½ÍÍ}Ù½Õ¡•ÉÌœ€ôøÑÉÕ”°($$$$$±½­}‘½Õ¡‰½ÍÍ}ÍÑ…™˜œ€€€€€ôøÑÉÕ”°($$$$¤($$$¤ì($%ô•±Í”ì($$%¥˜€ €„€‘­¥Ñ¡•¸´ù¡…Í}…À €É•‘••µ}‘½Õ¡‰½ÍÍ}Ù½Õ¡•ÉÌœ€¤€¤ì($$$$‘­¥Ñ¡•¸´ù…‘‘}…À €É•‘••µ}‘½Õ¡‰½ÍÍ}Ù½Õ¡•ÉÌœ€¤ì($$%ô($$%¥˜€ €„€‘­¥Ñ¡•¸´ù¡…Í}…À €±½­}‘½Õ¡‰½ÍÍ}ÍÑ…™˜œ€¤€¤ì($$$$‘­¥Ñ¡•¸´ù…‘‘}…À €±½­}‘½Õ¡‰½ÍÍ}ÍÑ…™˜œ€¤ì($$%ô($%ô(($$¼¼=İ¹•È½5…¹…•ÈÉ½±”è™Õ±°½Õ¡	½ÍÌµ…¹…•µ•¹Ğ€¡µ•¹Ô°½É‘•ÉÌ°Í•ÑÑ¥¹Ì°($$¼¼-L°Ù½Õ¡•ÉÌ¤İ¥Ñ¡½ÕĞÉ…¹Ñ¥¹œ™Õ±°]½É‘AÉ•ÍÌ…‘µ¥¹¥ÍÑÉ…Ñ½È…•ÍÌ¸($$‘µ…¹…•È€ô•Ñ}É½±” €‘½Õ¡‰½ÍÍ}µ…¹…•Èœ€¤ì($%¥˜€ €„€‘µ…¹…•È€¤ì($$%…‘‘}É½±” ($$$$‘½Õ¡‰½ÍÍ}µ…¹…•Èœ°($$$%}| €½Õ¡	½ÍÌ5…¹…•Èœ°€‘½Õ¡‰½ÍÌœ€¤°($$$%…ÉÉ…ä ($$$$$É•…œ€€€€€€€€€€€€€€€€€€€€€€ôøÑÉÕ”°($$$$$µ…¹…•}‘½Õ¡‰½ÍÌœ€€€€€€€€€€ôøÑÉÕ”°($$$$$µ…¹…•}‘½Õ¡‰½ÍÍ}­‘Ìœ€€€€€€ôøÑÉÕ”°($$$$$É•‘••µ}‘½Õ¡‰½ÍÍ}Ù½Õ¡•ÉÌœ€ôøÑÉÕ”°($$$$$±½­}‘½Õ¡‰½ÍÍ}ÍÑ…™˜œ€€€€€ôøÑÉÕ”°($$$$¤($$$¤ì($%ô•±Í”ì($$%¥˜€ €„€‘µ…¹…•È´ù¡…Í}…À €µ…¹…•}‘½Õ¡‰½ÍÌœ€¤€¤ì($$$$‘µ…¹…•È´ù…‘‘}…À €µ…¹…•}‘½Õ¡‰½ÍÌœ€¤ì($$%ô($$%¥˜€ €„€‘µ…¹…•È´ù¡…Í}…À €µ…¹…•}‘½Õ¡‰½ÍÍ}­‘Ìœ€¤€¤ì($$$$‘µ…¹…•È´ù…‘‘}…À €µ…¹…•}‘½Õ¡‰½ÍÍ}­‘Ìœ€¤ì($$%ô($$%¥˜€ €„€‘µ…¹…•È´ù¡…Í}…À €É•‘••µ}‘½Õ¡‰½ÍÍ}Ù½Õ¡•ÉÌœ€¤€¤ì($$$$‘µ…¹…•È´ù…‘‘}…À €É•‘••µ}‘½Õ¡‰½ÍÍ}Ù½Õ¡•ÉÌœ€¤ì($$%ô($$%¥˜€ €„€‘µ…¹…•È´ù¡…Í}…À €±½­}‘½Õ¡‰½ÍÍ}ÍÑ…™˜œ€¤€¤ì($$$$‘µ…¹…•È´ù…‘‘}…À €±½­}‘½Õ¡‰½ÍÍ}ÍÑ…™˜œ€¤ì($$%ô($%ô(($$¼¼±½¬µ½¹±äÉ½±”™½È™É½¹Ğµ½˜µ¡½ÕÍ”…¹½Ñ¡•ÈÍÑ…™˜¸ÑÑ•¹‘…¹”…•ÍÌ($$¼¼µÕÍĞ¹½ĞÍ¥±•¹Ñ±äÉ…¹Ğ-L°Ù½Õ¡•È½Èµ…¹…•µ•¹ĞÁ•Éµ¥ÍÍ¥½¹Ì¸($$‘ÍÑ…™˜€ô•Ñ}É½±” €‘½Õ¡‰½ÍÍ}ÍÑ…™˜œ€¤ì($%¥˜€ €„€‘ÍÑ…™˜€¤ì($$%…‘‘}É½±” ($$$$‘½Õ¡‰½ÍÍ}ÍÑ…™˜œ°($$$%}| €½Õ¡	½ÍÌMÑ…™˜œ°€‘½Õ¡‰½ÍÌœ€¤°($$$%…ÉÉ…ä ($$$$$É•…œ€€€€€€€€€€€€€€€€€€ôøÑÉÕ”°($$$$$±½­}‘½Õ¡‰½ÍÍ}ÍÑ…™˜œ€ôøÑÉÕ”°($$$$¤($$$¤ì($%ô•±Í•¥˜€ €„€‘ÍÑ…™˜´ù¡…Í}…À €±½­}‘½Õ¡‰½ÍÍ}ÍÑ…™˜œ€¤€¤ì($$$‘ÍÑ…™˜´ù…‘‘}…À €±½­}‘½Õ¡‰½ÍÍ}ÍÑ…™˜œ€¤ì($%ô(%ô)ô