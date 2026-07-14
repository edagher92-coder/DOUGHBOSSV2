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

		update_option( 'doughboss_db_version', DOUGHBOSS_DB_VERSION );
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
		$locations       = $wpdb->prefix . 'doughboss_locations';
		$catering        = $wpdb->prefix . 'doughboss_catering_enquiries';
		$vouchers        = $wpdb->prefix . 'doughboss_vouchers';
		$redemptions     = $wpdb->prefix . 'doughboss_voucher_redemptions';
		$pospal_outbox   = $wpdb->prefix . 'doughboss_pospal_outbox';

		$sql_orders = "CREATE TABLE {$orders} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_number varchar(32) NOT NULL,
			location_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			order_type varchar(20) NOT NULL DEFAULT 'pickup',
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
			payment_intent_id varchar(64) NOT NULL DEFAULT '',
			eta_minutes int(11) NOT NULL DEFAULT 0,
			seen_at datetime NULL DEFAULT NULL,
			acknowledged_at datetime NULL DEFAULT NULL,
			accepted_at datetime NULL DEFAULT NULL,
			created_at datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_number (order_number),
			KEY status (status),
			KEY customer_email (customer_email),
			KEY location_id (location_id),
			KEY payment_intent_id (payment_intent_id)
		) {$charset_collate};";

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
		) {$charset_collate};";

		$sql_locations = "CREATE TABLE {$locations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			slug varchar(191) NOT NULL DEFAULT '',
			suburb varchar(191) NOT NULL DEFAULT '',
			address text NULL,
			phone varchar(40) NOT NULL DEFAULT '',
			postcodes text NULL,
			prep_time_default int(11) NOT NULL DEFAULT 20,
			pickup_enabled tinyint(1) NOT NULL DEFAULT 1,
			delivery_enabled tinyint(1) NOT NULL DEFAULT 0,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY slug (slug),
			KEY is_active (is_active)
		) {$charset_collate};";

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

		dbDelta( $sql_orders );
		dbDelta( $sql_items );
		dbDelta( $sql_locations );
		dbDelta( $sql_catering );
		dbDelta( $sql_vouchers );
		dbDelta( $sql_redemptions );
		dbDelta( $sql_pospal_outbox );
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
