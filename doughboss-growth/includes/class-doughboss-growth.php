<?php
/**
 * DoughBoss Growth bootstrap: core gate, module registry, health endpoint.
 *
 * The companion is inert unless DoughBoss core 2.41.0 or later is active. Modules are registered by
 * file path; a module file is required, and its static init() called, only when the file exists AND one
 * of its feature flags is effectively on. Later work packages therefore just add files.
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstrap and module registry.
 */
final class DoughBoss_Growth {

	/**
	 * Lowest core version the companion supports.
	 */
	const MIN_CORE_VERSION = '2.41.0';

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'doughboss-growth/v1';

	/**
	 * Whether init() has completed the gate and registered everything.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Modules whose init() ran in this request (key => true).
	 *
	 * @var array
	 */
	private static $active = array();

	/**
	 * Why the companion stayed inert: "", "kill_switch" or "core".
	 *
	 * @var string
	 */
	private static $inert_reason = '';

	/**
	 * Test seam for the clock. Production code never sets this.
	 *
	 * @var int|null
	 */
	private static $time_override = null;

	/**
	 * Test seam for the module registry and the directory module files are loaded from.
	 * Production code never sets these.
	 *
	 * @var array|null
	 */
	private static $registry_override = null;

	/**
	 * Directory override paired with the registry override.
	 *
	 * @var string|null
	 */
	private static $base_override = null;

	/**
	 * The module registry. Frozen file layout from the architecture (00 section 4.1).
	 *
	 * A module is a set of files under includes/<module>/. Later work packages simply add the files;
	 * nothing here has to change. Fields:
	 *
	 * - file: the entry file (relative to the plugin directory). Its class owns init() and, when the
	 *   module has tables, a static schema() that returns CREATE TABLE statements.
	 * - class: the entry class. init() runs only when the file exists AND the module is wanted.
	 * - files: further files of the same module, required (when present) just before the entry file.
	 * - features: init() runs when ANY of these flags is effectively on.
	 * - admin_always: also run init() inside wp-admin whatever the flags say (read-only admin screens).
	 * - always: run init() on every request whatever the flags say (still subject to needs_storage). Used by the
	 *   waitlist: a person must always be able to opt out, and stored sign-ups must stay exportable, erasable and
	 *   purged after the flag is switched off. Its init() does nothing public with the flag off except those.
	 * - needs_storage: skipped until the schema is installed.
	 *
	 * Module files must define classes only (no side effects at include time): the activator includes
	 * every present entry file to collect schema() even while its feature is off. init() must be
	 * idempotent and must itself re-check DoughBoss_Growth_Settings::enabled() for the specific feature
	 * it serves.
	 *
	 * @return array
	 */
	public static function modules() {
		if ( null !== self::$registry_override ) {
			return self::$registry_override;
		}
		return array(
			'ledger'      => array(
				'file'          => 'includes/ledger/class-doughboss-growth-ledger.php',
				'class'         => 'DoughBoss_Growth_Ledger',
				'files'         => array(),
				'features'      => array( 'landing_pages', 'seo_head', 'coming_soon', 'waitlist' ),
				'admin_always'  => true,
				'needs_storage' => false,
			),
			'consent'     => array(
				'file'          => 'includes/consent/class-doughboss-growth-consent.php',
				'class'         => 'DoughBoss_Growth_Consent',
				'files'         => array( 'includes/consent/class-doughboss-growth-tags.php' ),
				'features'      => array( 'consent_banner', 'gtm' ),
				'admin_always'  => false,
				'needs_storage' => false,
			),
			'attribution' => array(
				'file'          => 'includes/attribution/class-doughboss-growth-attribution.php',
				'class'         => 'DoughBoss_Growth_Attribution',
				'files'         => array(),
				'features'      => array( 'attribution', 'lead_form' ),
				'admin_always'  => false,
				'needs_storage' => true,
			),
			'waitlist'    => array(
				'file'          => 'includes/waitlist/class-doughboss-growth-waitlist.php',
				'class'         => 'DoughBoss_Growth_Waitlist',
				'files'         => array(
					'includes/waitlist/class-doughboss-growth-waitlist-rest.php',
					'includes/waitlist/class-doughboss-growth-waitlist-privacy.php',
				),
				'features'      => array( 'waitlist' ),
				'admin_always'  => false,
				'always'        => true,
				'needs_storage' => true,
			),
			'coming_soon' => array(
				'file'          => 'includes/waitlist/class-doughboss-growth-coming-soon.php',
				'class'         => 'DoughBoss_Growth_Coming_Soon',
				'files'         => array(),
				'features'      => array( 'coming_soon' ),
				'admin_always'  => false,
				'needs_storage' => false,
			),
			'landing'     => array(
				'file'          => 'includes/landing/class-doughboss-growth-landing.php',
				'class'         => 'DoughBoss_Growth_Landing',
				'files'         => array(
					'includes/landing/class-doughboss-growth-landing-seo.php',
					'includes/landing/class-doughboss-growth-landing-schema.php',
				),
				'features'      => array( 'landing_pages', 'seo_head' ),
				'admin_always'  => true,
				'needs_storage' => false,
			),
			'leads'       => array(
				'file'          => 'includes/leads/class-doughboss-growth-leads.php',
				'class'         => 'DoughBoss_Growth_Leads',
				'files'         => array( 'includes/leads/class-doughboss-growth-party-sizer.php' ),
				'features'      => array( 'lead_form', 'party_sizer' ),
				'admin_always'  => false,
				'needs_storage' => true,
			),
			'conversions' => array(
				'file'          => 'includes/conversions/class-doughboss-growth-conversions.php',
				'class'         => 'DoughBoss_Growth_Conversions',
				'files'         => array(
					'includes/conversions/class-doughboss-growth-ga4.php',
					'includes/conversions/class-doughboss-growth-meta.php',
					'includes/conversions/class-doughboss-growth-offline-export.php',
				),
				'features'      => array( 'server_conversions' ),
				'admin_always'  => false,
				'needs_storage' => true,
			),
			'recon'       => array(
				'file'          => 'includes/recon/class-doughboss-growth-recon-admin.php',
				'class'         => 'DoughBoss_Growth_Recon_Admin',
				'files'         => array(
					'includes/recon/class-doughboss-growth-recon-reader.php',
					'includes/recon/class-doughboss-growth-recon-square.php',
					'includes/recon/class-doughboss-growth-recon-matcher.php',
					'includes/recon/class-doughboss-growth-recon-report.php',
				),
				'features'      => array( 'timesheet_recon' ),
				'admin_always'  => false,
				'needs_storage' => true,
			),
		);
	}

	/**
	 * Current UNIX time. All companion code uses this so tests can control the clock.
	 *
	 * @return int
	 */
	public static function now() {
		return ( null !== self::$time_override ) ? (int) self::$time_override : time();
	}

	/**
	 * Fix the clock (tests only). Pass null to release it.
	 *
	 * @param int|null $timestamp UNIX time or null.
	 * @return void
	 */
	public static function set_time_override( $timestamp ) {
		if ( ! defined( 'DBGR_TESTING' ) ) {
			return; // Honoured only inside the test harness.
		}
		self::$time_override = ( null === $timestamp ) ? null : (int) $timestamp;
	}

	/**
	 * Replace the module registry and the directory its files are loaded from (tests only).
	 * Pass null for both to restore the real registry.
	 *
	 * @param array|null  $modules Registry in the shape of modules().
	 * @param string|null $dir     Directory with a trailing slash.
	 * @return void
	 */
	public static function set_registry_override( $modules, $dir ) {
		if ( ! defined( 'DBGR_TESTING' ) ) {
			return; // Honoured only inside the test harness.
		}
		self::$registry_override = is_array( $modules ) ? $modules : null;
		self::$base_override     = ( is_string( $dir ) && '' !== $dir ) ? $dir : null;
	}

	/**
	 * Directory module files are loaded from.
	 *
	 * @return string
	 */
	private static function base_dir() {
		return ( null !== self::$base_override ) ? self::$base_override : DOUGHBOSS_GROWTH_DIR;
	}

	/**
	 * Forget per-request state (tests only).
	 *
	 * @return void
	 */
	public static function reset_state() {
		self::$booted            = false;
		self::$active            = array();
		self::$inert_reason      = '';
		self::$time_override     = null;
		self::$registry_override = null;
		self::$base_override     = null;
	}

	/**
	 * The active core version, or an empty string when core is not loaded.
	 *
	 * @return string
	 */
	public static function core_version() {
		return defined( 'DOUGHBOSS_VERSION' ) ? (string) constant( 'DOUGHBOSS_VERSION' ) : '';
	}

	/**
	 * The core gate: DoughBoss 2.41.0 or later is loaded and exposes its settings class.
	 *
	 * @return bool
	 */
	public static function core_ready() {
		$version = self::core_version();
		return '' !== $version
			&& version_compare( $version, self::MIN_CORE_VERSION, '>=' )
			&& class_exists( 'DoughBoss_Settings' );
	}

	/**
	 * Why the companion is inert ("", "kill_switch", "core").
	 *
	 * @return string
	 */
	public static function inert_reason() {
		return self::$inert_reason;
	}

	/**
	 * Entry point on plugins_loaded. Registers nothing but an admin notice unless the kill switch
	 * is off and the core gate passes.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$booted ) {
			return;
		}
		if ( DoughBoss_Growth_Settings::kill_switch() ) {
			self::$inert_reason = 'kill_switch';
			add_action( 'admin_notices', array( __CLASS__, 'render_inert_notice' ) );
			return;
		}
		if ( ! self::core_ready() ) {
			self::$inert_reason = 'core';
			add_action( 'admin_notices', array( __CLASS__, 'render_inert_notice' ) );
			return;
		}
		self::$booted = true;

		DoughBoss_Growth_Outbox::init();
		DoughBoss_Growth_Admin::init();
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		self::init_modules();
		self::register_shortcode_stubs();

		do_action( 'doughboss_growth_loaded' );
	}

	/**
	 * Companion shortcode tags (frozen names, 00 section 4.2; the hero tag is cancelled).
	 */
	const SHORTCODES = array(
		'doughboss_growth_landing',
		'doughboss_growth_coming_soon',
		'doughboss_growth_waitlist',
		'doughboss_growth_lead_form',
		'doughboss_growth_party_sizer',
	);

	/**
	 * A page that holds a companion shortcode must never print the raw tag when its feature is off (modules whose
	 * flag is off are not loaded, so nothing else registers the tag). Every companion tag that no module registered
	 * renders an empty string. Only once the companion has a footprint (its schema is installed or it recorded
	 * pages): before that no companion page can exist, and a fresh install stays strictly inert.
	 *
	 * @return void
	 */
	private static function register_shortcode_stubs() {
		if ( ! DoughBoss_Growth_Activator::storage_ready() && array() === DoughBoss_Growth_Activator::page_ids( get_option( DoughBoss_Growth_Activator::PAGES_OPTION, array() ) ) ) {
			return;
		}
		foreach ( self::SHORTCODES as $tag ) {
			if ( ! shortcode_exists( $tag ) ) {
				add_shortcode( $tag, '__return_empty_string' );
			}
		}
	}

	/**
	 * Require and initialise every module that is wanted and present.
	 *
	 * @return void
	 */
	private static function init_modules() {
		foreach ( self::modules() as $key => $module ) {
			if ( ! self::module_wanted( $module ) ) {
				continue;
			}
			if ( ! empty( $module['needs_storage'] ) && ! DoughBoss_Growth_Activator::storage_ready() ) {
				continue;
			}
			try {
				if ( ! self::load_module( $key ) ) {
					// A wanted module whose file or class is missing (a damaged upload) is a failure, not a silent no-op:
					// the settings screen would otherwise show a feature as on with nothing behind it.
					DoughBoss_Growth_Http::log( 'module_load_failed', array( 'module' => $key ) );
					continue;
				}
				if ( is_callable( array( $module['class'], 'init' ) ) ) {
					call_user_func( array( $module['class'], 'init' ) );
					self::$active[ $key ] = true;
				}
			} catch ( Throwable $e ) {
				// A faulty module must never take the site down: it stays inert and the error class is logged.
				unset( self::$active[ $key ] );
				DoughBoss_Growth_Http::log(
					'module_failed',
					array(
						'module' => $key,
						'error'  => get_class( $e ),
					)
				);
			}
		}
	}

	/**
	 * Whether a module's init() ran in this request without throwing. The Growth settings screen compares this with the
	 * flags: a feature that is on while its module is not running is reported there instead of being shown as active.
	 *
	 * @param string $key Registry key.
	 * @return bool
	 */
	public static function module_running( $key ) {
		return is_string( $key ) && isset( self::$active[ $key ] );
	}

	/**
	 * Whether a module should be initialised now.
	 *
	 * @param array $module Registry entry.
	 * @return bool
	 */
	private static function module_wanted( array $module ) {
		if ( ! empty( $module['always'] ) ) {
			return true;
		}
		if ( ! empty( $module['admin_always'] ) && is_admin() ) {
			return true;
		}
		foreach ( $module['features'] as $feature ) {
			if ( DoughBoss_Growth_Settings::enabled( $feature ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Require a module's main file if it exists. Modules use this to load the ledger and other
	 * libraries they depend on.
	 *
	 * @param string $key Registry key.
	 * @return bool True when the module class is available.
	 */
	public static function load_module( $key ) {
		$modules = self::modules();
		if ( ! isset( $modules[ $key ] ) ) {
			return false;
		}
		$module = $modules[ $key ];
		if ( class_exists( $module['class'], false ) ) {
			return true;
		}
		$path = self::base_dir() . $module['file'];
		if ( ! is_file( $path ) ) {
			return false;
		}
		foreach ( $module['files'] as $extra ) {
			$extra_path = self::base_dir() . $extra;
			if ( is_file( $extra_path ) ) {
				require_once $extra_path;
			}
		}
		require_once $path;
		return class_exists( $module['class'], false );
	}

	/**
	 * Every CREATE TABLE statement for dbDelta: the shared infrastructure plus each module that
	 * is present on disk and exposes a static schema() (regardless of its feature flag).
	 *
	 * @return array
	 */
	public static function schemas() {
		$schemas = array_merge(
			DoughBoss_Growth_Rate_Limit::schema(),
			DoughBoss_Growth_Outbox::schema()
		);
		foreach ( self::modules() as $key => $module ) {
			try {
				if ( ! self::load_module( $key ) || ! is_callable( array( $module['class'], 'schema' ) ) ) {
					continue;
				}
				$extra = call_user_func( array( $module['class'], 'schema' ) );
			} catch ( Throwable $e ) {
				DoughBoss_Growth_Http::log(
					'schema_failed',
					array(
						'module' => $key,
						'error'  => get_class( $e ),
					)
				);
				continue;
			}
			if ( is_array( $extra ) ) {
				foreach ( $extra as $sql ) {
					if ( is_string( $sql ) && '' !== $sql ) {
						$schemas[] = $sql;
					}
				}
			}
		}
		return $schemas;
	}

	/**
	 * Register the REST routes.
	 *
	 * @return void
	 */
	public static function register_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_health' ),
				'permission_callback' => array( __CLASS__, 'rest_health_permission' ),
			)
		);
	}

	/**
	 * Health is for managers only.
	 *
	 * @return bool
	 */
	public static function rest_health_permission() {
		return DoughBoss_Growth_Admin::user_can_manage();
	}

	/**
	 * GET /doughboss-growth/v1/health.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_health() {
		$response = rest_ensure_response( self::health() );
		if ( is_object( $response ) && method_exists( $response, 'header' ) ) {
			$response->header( 'Cache-Control', 'no-store' );
		}
		return $response;
	}

	/**
	 * Flags and readiness booleans, plus the recorded failure codes with their counts (DoughBoss_Growth_Failures).
	 * Never contains a secret value, token or personal data.
	 *
	 * @return array
	 */
	public static function health() {
		$effective  = array();
		$configured = DoughBoss_Growth_Settings::configured_features();
		foreach ( DoughBoss_Growth_Settings::FEATURES as $feature ) {
			$effective[ $feature ] = DoughBoss_Growth_Settings::enabled( $feature );
		}
		$modules = array();
		$present = array();
		foreach ( self::modules() as $key => $module ) {
			$modules[ $key ] = isset( self::$active[ $key ] );
			$present[ $key ] = is_file( self::base_dir() . $module['file'] );
		}
		$stored = get_option( DoughBoss_Growth_Activator::DB_VERSION_OPTION, '' );
		return array(
			'plugin_version'   => DOUGHBOSS_GROWTH_VERSION,
			'db_version'       => is_string( $stored ) ? $stored : '',
			'storage_ready'    => DoughBoss_Growth_Activator::storage_ready(),
			'core_ready'       => self::core_ready(),
			'core_version'     => self::core_version(),
			'kill_switch'      => DoughBoss_Growth_Settings::kill_switch(),
			'flags'            => $effective,
			'flags_configured' => $configured,
			'modules_active'   => $modules,
			'modules_present'  => $present,
			'outbox_channels'  => count( DoughBoss_Growth_Outbox::channels() ),
			'outbox_scheduled' => ( false !== wp_next_scheduled( DoughBoss_Growth_Outbox::CRON_HOOK ) ),
			'confirm_gaps'     => array_keys( DoughBoss_Growth_Settings::confirm_gaps() ),
			'failures'         => array_column( DoughBoss_Growth_Failures::all(), 'count', 'code' ),
		);
	}

	/**
	 * Admin notice shown while the companion is inert.
	 *
	 * @return void
	 */
	public static function render_inert_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( 'kill_switch' === self::$inert_reason ) {
			$message = __( 'DoughBoss Growth is stopped by the DOUGHBOSS_GROWTH_DISABLE setting in wp-config.php. Nothing it provides is active.', 'doughboss-growth' );
		} else {
			/* translators: %s: minimum DoughBoss version. */
			$message = sprintf( __( 'DoughBoss Growth needs the DoughBoss plugin %s or later to be active. It is inactive until then and changes nothing on your site.', 'doughboss-growth' ), self::MIN_CORE_VERSION );
		}
		echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
	}
}
