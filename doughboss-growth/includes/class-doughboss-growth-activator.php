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
		'doughboss_growth_coming_soon',
		'doughboss_growth_failures',
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
	 *
	 * The version is recorded only when every expected table exists AND carries every column its CREATE TABLE declares
	 * (dbDelta can fail to ALTER a table that already exists, and a table-existence check alone would then record the new
	 * version over a table that is missing a column), and the version really was stored (fail closed). A failure is
	 * recorded in the failure list the owner sees, and a later success removes that record.
	 *
	 * @return bool True only when the schema is confirmed and the version is stored.
	 */
	public static function install() {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$schemas = DoughBoss_Growth::schemas();
		foreach ( $schemas as $sql ) {
			dbDelta( $sql );
		}
		$problems = self::schema_problems( $schemas );
		if ( array() !== $problems ) {
			self::note_failure(
				'schema_install_failed',
				array(
					'problems' => count( $problems ),
					'first'    => implode( ',', array_slice( self::short_names( $problems ), 0, 3 ) ),
				)
			);
			return false;
		}
		update_option( self::DB_VERSION_OPTION, DOUGHBOSS_GROWTH_DB_VERSION, true );
		if ( ! self::storage_ready() ) {
			self::note_failure( 'schema_version_save_failed', array( 'version' => DOUGHBOSS_GROWTH_DB_VERSION ) );
			return false;
		}
		if ( class_exists( 'DoughBoss_Growth_Failures', false ) ) {
			DoughBoss_Growth_Failures::clear( 'schema_install_failed' );
			DoughBoss_Growth_Failures::clear( 'schema_version_save_failed' );
		}
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
		// The schema was confirmed once. Re-check at most hourly (and not at all inside the retry window after a failed
		// repair), or at once when the owner opens the Growth page.
		if ( ! $on_page && ( false !== get_transient( self::SCHEMA_OK_TRANSIENT ) || false !== get_transient( self::RETRY_TRANSIENT ) ) ) {
			return;
		}
		if ( array() !== self::schema_problems() ) {
			delete_transient( self::SCHEMA_OK_TRANSIENT );
			if ( self::install() ) {
				set_transient( self::SCHEMA_OK_TRANSIENT, 1, HOUR_IN_SECONDS );
				delete_transient( self::RETRY_TRANSIENT );
			} else {
				// Without this a broken table made every admin request run SHOW TABLES and dbDelta again.
				set_transient( self::RETRY_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );
			}
			return;
		}
		set_transient( self::SCHEMA_OK_TRANSIENT, 1, HOUR_IN_SECONDS );
		delete_transient( self::RETRY_TRANSIENT );
		if ( class_exists( 'DoughBoss_Growth_Failures', false ) ) {
			DoughBoss_Growth_Failures::clear( 'schema_install_failed' ); // The schema is complete: an earlier failure no longer stands.
		}
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
	 * Everything that stops the schema being confirmed: the expected tables that do not exist, then, for each table that
	 * does exist, every declared column it lacks ("table.column"). A table whose columns cannot be read at all is listed
	 * as "table (columns unreadable)": it is not confirmed, so it is a problem (fail closed).
	 *
	 * @param array|null $schemas Statements; defaults to every registered schema.
	 * @return array Table names, "table.column" and "table (columns unreadable)" entries. Empty when the schema is complete.
	 */
	public static function schema_problems( $schemas = null ) {
		if ( null === $schemas ) {
			$schemas = DoughBoss_Growth::schemas();
		}
		$missing  = self::missing_tables( $schemas );
		$problems = $missing;
		foreach ( self::expected_columns( $schemas ) as $name => $columns ) {
			if ( in_array( $name, $missing, true ) ) {
				continue;
			}
			$present = self::table_columns( $name );
			if ( null === $present ) {
				$problems[] = $name . ' (columns unreadable)';
				continue;
			}
			foreach ( $columns as $column ) {
				if ( ! in_array( strtolower( $column ), $present, true ) ) {
					$problems[] = $name . '.' . $column;
				}
			}
		}
		return $problems;
	}

	/**
	 * The columns each CREATE TABLE statement declares.
	 *
	 * @param array|null $schemas Statements; defaults to every registered schema.
	 * @return array Table name => list of column names.
	 */
	public static function expected_columns( $schemas = null ) {
		if ( null === $schemas ) {
			$schemas = DoughBoss_Growth::schemas();
		}
		$out = array();
		foreach ( $schemas as $sql ) {
			if ( is_string( $sql ) && 1 === preg_match( '/^\s*CREATE TABLE\s+`?([A-Za-z0-9_]+)`?/i', $sql, $match ) ) {
				$out[ $match[1] ] = self::declared_columns( $sql );
			}
		}
		return $out;
	}

	/**
	 * Column names declared by one CREATE TABLE statement (keys, indexes and constraints are not columns).
	 *
	 * @param mixed $sql CREATE TABLE statement.
	 * @return array
	 */
	public static function declared_columns( $sql ) {
		if ( ! is_string( $sql ) || 1 !== preg_match( '/^\s*CREATE TABLE\s+`?[A-Za-z0-9_]+`?\s*\((.*)\)[^)]*;?\s*$/is', $sql, $match ) ) {
			return array();
		}
		$columns = array();
		$depth   = 0;
		$buffer  = '';
		$parts   = array();
		$body    = $match[1];
		$length  = strlen( $body );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $body[ $i ];
			if ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				--$depth;
			}
			if ( ',' === $char && 0 === $depth ) {
				$parts[] = $buffer;
				$buffer  = '';
				continue;
			}
			$buffer .= $char;
		}
		$parts[] = $buffer;
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part || 1 === preg_match( '/^(?:PRIMARY\s+KEY|UNIQUE\s+(?:KEY|INDEX)|UNIQUE|KEY|INDEX|FULLTEXT|SPATIAL|CONSTRAINT|FOREIGN\s+KEY|CHECK)\b/i', $part ) ) {
				continue;
			}
			if ( 1 === preg_match( '/^`?([A-Za-z0-9_]+)`?\s/', $part . ' ', $name ) ) {
				$columns[] = $name[1];
			}
		}
		return $columns;
	}

	/**
	 * The column names a table really has, lower-cased, or null when the database cannot say.
	 *
	 * @param string $table Table name (already validated by expected_columns()).
	 * @return array|null
	 */
	private static function table_columns( $table ) {
		global $wpdb;
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', (string) $table ) ) {
			return null;
		}
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the name is a validated identifier from the companion's own schema.
		if ( ! is_array( $columns ) || array() === $columns ) {
			return null;
		}
		return array_map( 'strtolower', array_map( 'strval', $columns ) );
	}

	/**
	 * Table names without the WordPress prefix, for a short failure context.
	 *
	 * @param array $problems Entries from schema_problems().
	 * @return array
	 */
	private static function short_names( array $problems ) {
		$out = array();
		foreach ( $problems as $problem ) {
			$out[] = (string) preg_replace( '/^[A-Za-z0-9_]*?doughboss_growth_/', '', (string) $problem );
		}
		return $out;
	}

	/**
	 * Record a failure. Goes through Http::log() when it is loaded (debug log, action and the owner-visible list in one
	 * call), else straight to the failure list. This file is also loaded alone by uninstall.php, so nothing here may
	 * assume the other classes exist.
	 *
	 * @param string $code    Failure code.
	 * @param array  $context Scalar facts without personal data.
	 * @return void
	 */
	private static function note_failure( $code, array $context ) {
		if ( class_exists( 'DoughBoss_Growth_Http', false ) ) {
			DoughBoss_Growth_Http::log( $code, $context );
		} elseif ( class_exists( 'DoughBoss_Growth_Failures', false ) ) {
			DoughBoss_Growth_Failures::record( $code, $context );
		}
	}

	/**
	 * Move companion-created pages whose content is exactly one companion shortcode to draft.
	 *
	 * @return array Ids moved to draft.
	 */
	public static function draft_companion_pages() {
		$drafted = array();
		$ids     = self::page_ids( get_option( self::PAGES_OPTION, array() ) );
		// The coming-soon page is made by hand (no create button records it), so it is found by its configured slug.
		// It is drafted only under the same rule as every other page: its content is exactly one companion shortcode.
		$coming = self::coming_soon_page_id();
		if ( $coming > 0 && ! in_array( $coming, $ids, true ) ) {
			$ids[] = $coming;
		}
		foreach ( $ids as $page_id ) {
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
	 * Id of the page at the configured coming-soon slug (setting coming_soon_page_slug), or 0.
	 *
	 * @return int
	 */
	public static function coming_soon_page_id() {
		if ( ! class_exists( 'DoughBoss_Growth_Settings' ) || ! function_exists( 'get_page_by_path' ) ) {
			return 0;
		}
		$slug = DoughBoss_Growth_Settings::get( 'coming_soon_page_slug', '' );
		if ( ! is_string( $slug ) || '' === $slug ) {
			return 0;
		}
		$page = get_page_by_path( $slug, 'OBJECT', 'page' );
		return ( is_object( $page ) && isset( $page->ID ) && (int) $page->ID > 0 ) ? (int) $page->ID : 0;
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
