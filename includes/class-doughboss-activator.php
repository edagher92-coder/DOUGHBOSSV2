<?php
/**
 * Fired during plugin activation and on schema upgrades.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets up database tables, default options, roles and capabilities.
 */
class DoughBoss_Activator {

	const FLUSH_FLAG   = 'doughboss_flush_rewrites';
	const MANAGER_ROLE = 'doughboss_manager';

	/**
	 * Activation routine (runs from the activation hook, where `init` has
	 * already fired so rewrite rules can be flushed immediately).
	 *
	 * @return void
	 */
	public static function activate() {
		self::install();

		require_once DOUGHBOSS_PLUGIN_DIR . 'includes/class-doughboss-post-types.php';
		DoughBoss_Post_Types::register();
		flush_rewrite_rules();
		delete_option( self::FLUSH_FLAG );
	}

	/**
	 * Schema, options and capabilities only — safe to run at `plugins_loaded`
	 * (the upgrade path for file-copy deploys). Rewrite flushing is deferred to
	 * `init` via a flag because `$wp_rewrite` does not exist yet at
	 * `plugins_loaded`, so a flush there silently does nothing.
	 *
	 * @return void
	 */
	public static function install() {
		self::create_tables();
		self::add_default_options();
		self::add_capabilities();
		update_option( 'doughboss_db_version', DOUGHBOSS_DB_VERSION );
		update_option( self::FLUSH_FLAG, 1 );
	}

	/**
	 * Flush rewrite rules once, on `init`, if an upgrade asked for it.
	 *
	 * @return void
	 */
	public static function maybe_flush_rewrites() {
		if ( get_option( self::FLUSH_FLAG ) ) {
			flush_rewrite_rules();
			delete_option( self::FLUSH_FLAG );
		}
	}

	/**
	 * Create (or upgrade) the orders and order-items tables.
	 *
	 * dbDelta is picky: two spaces after PRIMARY KEY, one column per line, and
	 * no integer display widths (MySQL 8.0.19+ strips them, which otherwise
	 * makes dbDelta issue a redundant ALTER on every version check).
	 *
	 * @return void
	 */
	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$orders          = $wpdb->prefix . 'doughboss_orders';
		$order_items     = $wpdb->prefix . 'doughboss_order_items';

		$sql_orders = "CREATE TABLE {$orders} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			order_number varchar(32) NOT NULL,
			idempotency_key varchar(64) NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			order_type varchar(20) NOT NULL DEFAULT 'pickup',
			customer_name varchar(191) NOT NULL DEFAULT '',
			customer_email varchar(191) NOT NULL DEFAULT '',
			customer_phone varchar(40) NOT NULL DEFAULT '',
			address text NULL,
			notes text NULL,
			subtotal decimal(10,2) NOT NULL DEFAULT 0.00,
			tax decimal(10,2) NOT NULL DEFAULT 0.00,
			tax_rate decimal(5,2) NOT NULL DEFAULT 0.00,
			tax_inclusive tinyint NOT NULL DEFAULT 1,
			delivery_fee decimal(10,2) NOT NULL DEFAULT 0.00,
			total decimal(10,2) NOT NULL DEFAULT 0.00,
			currency varchar(10) NOT NULL DEFAULT 'AUD',
			email_sent tinyint NOT NULL DEFAULT 0,
			email_error text NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY order_number (order_number),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY status (status),
			KEY customer_email (customer_email),
			KEY created_at (created_at),
			KEY status_created (status,created_at)
		) {$charset_collate};";

		$sql_items = "CREATE TABLE {$order_items} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint unsigned NOT NULL,
			item_id bigint unsigned NOT NULL DEFAULT 0,
			name varchar(191) NOT NULL DEFAULT '',
			size varchar(80) NOT NULL DEFAULT '',
			toppings text NULL,
			quantity int NOT NULL DEFAULT 1,
			unit_price decimal(10,2) NOT NULL DEFAULT 0.00,
			line_total decimal(10,2) NOT NULL DEFAULT 0.00,
			PRIMARY KEY  (id),
			KEY order_id (order_id)
		) {$charset_collate};";

		dbDelta( $sql_orders );
		dbDelta( $sql_items );
	}

	/**
	 * Seed settings the first time the plugin is activated.
	 *
	 * Scalar defaults come from DoughBoss_Settings::defaults() — the ONE source
	 * of truth — so the installer and the runtime can never disagree. Sample
	 * sizes and toppings are added so the builder works out of the box; they are
	 * placeholders for the shop to replace, not real prices.
	 *
	 * The option is stored with autoload=no: it is only needed on storefront
	 * and admin requests, not on every cron/heartbeat/REST hit for the site.
	 *
	 * @return void
	 */
	private static function add_default_options() {
		$existing = get_option( DoughBoss_Settings::OPTION_KEY );

		if ( false !== $existing ) {
			// Existing install: move it off the autoload blob (WP 6.4+).
			if ( function_exists( 'wp_set_option_autoload' ) ) {
				wp_set_option_autoload( DoughBoss_Settings::OPTION_KEY, false );
			}
			return;
		}

		$defaults = DoughBoss_Settings::defaults();

		$defaults['sizes'] = array(
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
		);
		$defaults['toppings'] = array(
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
		);

		add_option( DoughBoss_Settings::OPTION_KEY, $defaults, '', 'no' );
	}

	/**
	 * Capabilities.
	 *
	 * Menu items now use their own mapped capability set instead of the generic
	 * `post` caps, so a blog Author can no longer publish a "$0.01 Family Feast".
	 * A `doughboss_manager` role lets a staff member run the Orders screen and
	 * edit the menu without being made an Administrator.
	 *
	 * @return void
	 */
	private static function add_capabilities() {
		$item_caps = array(
			'edit_doughboss_item',
			'read_doughboss_item',
			'delete_doughboss_item',
			'edit_doughboss_items',
			'edit_others_doughboss_items',
			'publish_doughboss_items',
			'read_private_doughboss_items',
			'delete_doughboss_items',
			'delete_private_doughboss_items',
			'delete_published_doughboss_items',
			'delete_others_doughboss_items',
			'edit_private_doughboss_items',
			'edit_published_doughboss_items',
			'manage_doughboss_categories',
		);

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'manage_doughboss' );
			foreach ( $item_caps as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		$manager_caps = array_fill_keys( $item_caps, true );
		$manager_caps['read']             = true;
		$manager_caps['upload_files']     = true;
		$manager_caps['manage_doughboss'] = true;

		$manager = get_role( self::MANAGER_ROLE );
		if ( ! $manager ) {
			add_role( self::MANAGER_ROLE, __( 'DoughBoss Manager', 'doughboss' ), $manager_caps );
		} else {
			foreach ( $manager_caps as $cap => $grant ) {
				$manager->add_cap( $cap, $grant );
			}
		}
	}
}
