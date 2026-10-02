<?php
/**
 * Activation, schema installation, deactivation drafting and uninstall planning.
 *
 * This file is also loaded on its own by uninstall.php, so it must define nothing at load time
 * beyond the class and must not rely on the plugin's other classes being present.
 *
 * The companion never touches core names: every option, table and cron hook handled here starts
 * with "doughboss_growth_". The uninstall plan is validated against that rule and refuses to run if
 * any name breaks it.
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lifecycle handling.
 */
final class DoughBoss_Growth_Activator {

	/**
	 * Option holding the installed schema version.
	 */
	const DB_VERSION_OPTION = 'doughboss_growth_db_version';

	/**
	 * Option holding the ids of pages the companion created (shape owned by the landing module).
	 */
	const PAGES_OPTION = 'doughboss_growth_pages';

	/**
	 * Transient that throttles failed schema installs (name starts with the companion prefix).
	 */
	const RETRY_TRANSIENT = 'doughboss_growth_install_retry';

	/**
	 * Transient set for an hour after a clean "every expected table exists" check, so the self-heal
	 * does not run a SHOW TABLES per table on every admin request.
	 */
	const SCHEMA_OK_TRANSIENT = 'doughboss_growth_schema_ok';

	/**
	 * Transients the companion may own.
	 */
	const TRANSIENTS = array(
		'doughboss_growth_install_retry',
		'doughboss_growth_schema_ok',
	);

	/**
	 * Every option the companion may own (frozen by the architecture).
	 */
	const OPTIONS = array(
		'doughboss_growth_settings',
		'doughboss_growth_db_version',
		'doughboss_growth_pages',
		'doughboss_growth_recon',
	);

	/**
	 * Every table the companion may own, without the WordPress prefix (frozen by the architecture).
	 */
	const TABLE_SUFFIXES = array(
		'doughboss_growth_waitlist',
		'doughboss_growth_suppression',
		'doughboss_growth_attribution',
		'doughboss_growth_lead_meta',
		'doughboss_growth_outbox',
		'doughboss_growth_rate',
		'doughboss_growth_recon_run',
		'doughboss_growth_recon_row',
		'doughboss_growth_recon_xref',
	);

	/**
	 * Cron hooks the companion may schedule.
	 */
	const CRON_HOOKS = array(
		'doughboss_growth_outbox_dispatch',
		'doughboss_growth_retention_purge',
		'doughboss_growth_recon_daily',
	);

	/**
	 * Post statuses that are visible or about to be, and so must be drafted on deactivation.
	 */
	const LIVE_STATUSES = array( 'publish', 'pending', 'future', 'private' );

	/**
	 * Activation hook. Installs the schema only when the core gate passes; otherwise the schema is
	 * installed later, on the first admin request that finds core present.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( class_exists( 'DoughBoss_Growth' ) && DoughBoss_Growth::core_ready() ) {
			self::install();
		}
	}

	/**
	 * Deactivation hook: draft companion pages (so no raw shortcode text can show) and clear cron.
	 * Deletes no data.
	 *
	 * @return void
	 */
	public static function deactivate() {
		self::draft_companion_pages();
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Create or update every registered table with dbDelta, then verify them.
	 * The version is recorded only when every expected table exists (fail closed).
	 *
	 * @return bool
	 */
	public static function install() {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$schemas = DoughBoss_Growth::schemas();
		foreach ( $schemas as $sql ) {
			dbDelta( $sql );
		}
		if ( array() !== self::missing_tables( $schemas ) ) {
			return false;
		}
		update_option( self::DB_VERSION_OPTION, DOUGHBOSS_GROWTH_DB_VERSION, true );
		return true;
	}

	/**
	 * Admin-side self-heal for managers: run install() when the schema was never confirmed, or when a
	 * table is missing (covers a plugin updated by zip upload and modules that add a table later).
	 * The full table check runs at most hourly, or immediately when the owner opens the Growth page.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( ! DoughBoss_Growth_Admin::user_can_manage() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check, no state change.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$on_page = ( 0 === strpos( $page, 'doughboss-growth' ) );

		if ( ! self::storage_ready() ) {
			// A failing install is retried at most every five minutes, except when the owner opens
			// the Growth page (an explicit retry).
			if ( ! $on_page && false !== get_transient( self::RETRY_TRANSIENT ) ) {
				return;
			}
			if ( self::install() ) {
				set_transient( self::SCHEMA_OK_TRANSIENT, 1, HOUR_IN_SECONDS );
				delete_transient( self::RETRY_TRANSIENT );
			} else {
				set_transient( self::RETRY_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );
			}
			return;
		}
		if ( ! $on_page && false !== get_transient( self::SCHEMA_OK_TRANSIENT ) ) {
			return;
		}
		if ( array() !== self::missing_tables() ) {
			self::install();
			delete_transient( self::SCHEMA_OK_TRANSIENT );
			return;
		}
		set_transient( self::SCHEMA_OK_TRANSIENT, 1, HOUR_IN_SECONDS );
	}

	/**
	 * Cheap runtime check: has install() completed for this schema version?
	 *
	 * @return bool
	 */
	public static function storage_ready() {
		$stored = get_option( self::DB_VERSION_OPTION, '0' );
		return is_string( $stored ) && '' !== $stored && version_compare( $stored, DOUGHBOSS_GROWTH_DB_VERSION, '>=' );
	}

	/**
	 * Table names declared by a set of CREATE TABLE statements.
	 *
	 * @param array|null $schemas Statements; defaults to every registered schema.
	 * @return array
	 */
	public static function expected_tables( $schemas = null ) {
		if ( null === $schemas ) {
			$schemas = DoughBoss_Growth::schemas();
		}
		$tables = array();
		foreach ( $schemas as $sql ) {
			if ( is_string( $sql ) && 1 === preg_match( '/^\s*CREATE TABLE\s+`?([A-Za-z0-9_]+)`?/i', $sql, $match ) ) {
				$tables[] = $match[1];
			}
		}
		return array_values( array_unique( $tables ) );
	}

	/**
	 * Expected tables that do not exist.
	 *
	 * @param array|null $schemas Statements; defaults to every registered schema.
	 * @return array
	 */
	public static function missing_tables( $schemas = null ) {
		global $wpdb;
		$missing = array();
		foreach ( self::expected_tables( $schemas ) as $table ) {
			$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
			);
			if ( 0 !== strcasecmp( (string) $found, $table ) ) {
				$missing[] = $table;
			}
		}
		return $missing;
	}

	/**
	 * Move companion-created pages whose content is exactly one companion shortcode to draft.
	 *
	 * @return array Ids moved to draft.
	 */
	public static function draft_companion_pages() {
		$drafted = array();
		foreach ( self::page_ids( get_option( self::PAGES_OPTION, array() ) ) as $page_id ) {
			$post = get_post( $page_id );
			if ( ! is_object( $post ) || 'page' !== $post->post_type ) {
				continue;
			}
			if ( ! in_array( $post->post_status, self::LIVE_STATUSES, true ) ) {
				continue;
			}
			if ( ! self::is_companion_shortcode_only( $post->post_content ) ) {
				continue;
			}
			wp_update_post(
				array(
					'ID'          => $page_id,
					'post_status' => 'draft',
				)
			);
			$drafted[] = $page_id;
		}
		return $drafted;
	}

	/**
	 * Page ids from the doughboss_growth_pages option, whatever its shape (list, key => id,
	 * or key => array with an id).
	 *
	 * @param mixed $stored Option value.
	 * @return array Positive, unique ints.
	 */
	public static function page_ids( $stored ) {
		$ids = array();
		if ( ! is_array( $stored ) ) {
			return $ids;
		}
		foreach ( $stored as $value ) {
			if ( is_array( $value ) && isset( $value['id'] ) ) {
				$value = $value['id'];
			}
			if ( ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && (int) $value > 0 ) {
				$ids[] = (int) $value;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Whether page content is exactly one companion shortcode (optionally inside the block editor's
	 * shortcode block comments).
	 *
	 * @param mixed $content Post content.
	 * @return bool
	 */
	public static function is_companion_shortcode_only( $content ) {
		$content = trim( (string) $content );
		$content = preg_replace( '/^<!--\s*wp:shortcode\s*-->/', '', $content );
		$content = preg_replace( '/<!--\s*\/wp:shortcode\s*-->$/', '', (string) $content );
		$content = trim( (string) $content );
		return 1 === preg_match( '/^\[doughboss_growth_(?:landing|coming_soon|waitlist|lead_form|party_sizer)(?:\s+[^\[\]]*)?\]$/D', $content );
	}

	/**
	 * What an uninstall with data deletion would remove, validated so that nothing outside the
	 * companion's own names can ever be included. This is the dry-run view.
	 *
	 * @param string $prefix WordPress table prefix.
	 * @return array { options: string[], transients: string[], tables: string[], cron: string[], violations: string[] }
	 */
	public static function uninstall_plan( $prefix ) {
		$prefix = (string) $prefix;
		$plan   = array(
			'options'    => self::OPTIONS,
			'transients' => self::TRANSIENTS,
			'tables'     => array(),
			'cron'       => self::CRON_HOOKS,
			'violations' => array(),
		);
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]*$/D', $prefix ) ) {
			$plan['violations'][] = 'prefix:' . $prefix;
			return $plan;
		}
		foreach ( self::TABLE_SUFFIXES as $suffix ) {
			$plan['tables'][] = $prefix . $suffix;
		}
		foreach ( $plan['options'] as $name ) {
			if ( 0 !== strpos( $name, 'doughboss_growth_' ) || 1 !== preg_match( '/^[a-z0-9_]+$/D', $name ) ) {
				$plan['violations'][] = 'option:' . $name;
			}
		}
		foreach ( $plan['transients'] as $name ) {
			if ( 0 !== strpos( $name, 'doughboss_growth_' ) || 1 !== preg_match( '/^[a-z0-9_]+$/D', $name ) ) {
				$plan['violations'][] = 'transient:' . $name;
			}
		}
		foreach ( $plan['tables'] as $name ) {
			if ( 0 !== strpos( $name, $prefix . 'doughboss_growth_' ) || 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $name ) ) {
				$plan['violations'][] = 'table:' . $name;
			}
		}
		foreach ( $plan['cron'] as $name ) {
			if ( 0 !== strpos( $name, 'doughboss_growth_' ) ) {
				$plan['violations'][] = 'cron:' . $name;
			}
		}
		return $plan;
	}

	/**
	 * Delete the companion's data. Called by uninstall.php only when
	 * DOUGHBOSS_GROWTH_UNINSTALL_DELETE_DATA is exactly true. Refuses on any plan violation.
	 *
	 * @param object $wpdb WordPress database object.
	 * @return array The plan that was executed (empty arrays when refused).
	 */
	public static function run_uninstall( $wpdb ) {
		$plan = self::uninstall_plan( $wpdb->prefix );
		if ( array() !== $plan['violations'] ) {
			return array(
				'options'    => array(),
				'transients' => array(),
				'tables'     => array(),
				'cron'       => array(),
				'violations' => $plan['violations'],
			);
		}
		foreach ( $plan['tables'] as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- name validated by uninstall_plan().
		}
		foreach ( $plan['options'] as $option ) {
			delete_option( $option );
		}
		foreach ( $plan['transients'] as $transient ) {
			delete_transient( $transient );
		}
		foreach ( $plan['cron'] as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		return $plan;
	}
}
