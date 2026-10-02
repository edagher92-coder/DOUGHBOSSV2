<?php
/**
 * DoughBoss Growth VIP waitlist: storage, double opt-in, retention, export and the email-link pages.
 *
 * Feature flag: waitlist. It cannot be enabled while the sender legal name or the privacy-policy URL is empty
 * (enforced by DoughBoss_Growth_Settings and re-checked here). Everything fails closed: a storage error, a missing
 * setting or a failed email means nothing is stored (or the half-stored row is removed) and the visitor is told to
 * try later. No IP address or user agent is stored. The site never stores a password, card or payment detail here.
 *
 * What stays available while the flag is OFF (the module registry must initialise this class even then, see the
 * hand-off note): the opt-out link page (a person must always be able to leave the list), the WordPress personal
 * data exporter and eraser, the retention purge, and the staff CSV export. Sign-up, confirmation and the shortcode
 * output need the flag.
 *
 * Entry file for the module registry: it must stay free of side effects at include time.
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * VIP waitlist.
 */
final class DoughBoss_Growth_Waitlist {

	/**
	 * Table names without the WordPress prefix.
	 */
	const TABLE             = 'doughboss_growth_waitlist';
	const SUPPRESSION_TABLE = 'doughboss_growth_suppression';

	/**
	 * Row statuses.
	 */
	const STATUS_PENDING      = 'pending';
	const STATUS_CONFIRMED    = 'confirmed';
	const STATUS_UNSUBSCRIBED = 'unsubscribed';

	/**
	 * Cron hook for the daily retention purge (name frozen by the architecture).
	 */
	const PURGE_HOOK = 'doughboss_growth_retention_purge';

	/**
	 * admin-post action and nonce action for the staff CSV export (name frozen by the architecture).
	 */
	const EXPORT_ACTION = 'doughboss_growth_export_waitlist';

	/**
	 * Outbox channel for the optional notification webhook. Its own name, so it cannot collide with the
	 * generic channels the conversions module registers.
	 */
	const OUTBOX_CHANNEL = 'waitlist_hook';

	/**
	 * Query variable carried by the links in the emails.
	 */
	const QUERY_VAR = 'dbgr_wl';

	/**
	 * Script and style handles.
	 */
	const HANDLE_SCRIPT = 'dbgr-waitlist';
	const HANDLE_STYLE  = 'dbgr-coming-soon';

	/**
	 * A form token younger than this many seconds is refused (a bot posts instantly) and one older than
	 * MAX_FORM_AGE is refused (the page is stale). 3 s and 24 h are the work-breakdown figures.
	 */
	const MIN_FORM_AGE = 3;
	const MAX_FORM_AGE = 86400;

	/**
	 * Rate limits: 5 an hour per hashed IP, 3 a day per email hash, 300 a day for the whole form (a circuit
	 * breaker). The per-IP limit for the link actions (confirm and opt-out) is an engineering proposal.
	 */
	const LIMIT_IP       = 5;
	const LIMIT_EMAIL    = 3;
	const LIMIT_GLOBAL   = 300;
	const LIMIT_ACTION   = 30;
	const WINDOW_IP      = 3600;
	const WINDOW_DAY     = 86400;

	/**
	 * Days an opted-out row keeps its details before it is reduced to a suppression hash.
	 */
	const UNSUBSCRIBED_ROW_DAYS = 30;

	/**
	 * Field caps.
	 */
	const MAX_EMAIL = 191;
	const MAX_NAME  = 80;
	const MAX_PATH  = 200;

	/**
	 * Store slugs the analytics taxonomy knows (the waitlist_submit.store enum, see content/events.json).
	 */
	const STORE_SLUGS = array( 'revesby', 'bankstown', 'roselands' );

	/**
	 * CSV export status choices.
	 */
	const EXPORT_STATUSES = array( 'confirmed', 'pending', 'unsubscribed', 'all' );

	/**
	 * Rows read (and sent) at a time by the staff CSV export. Fixed, so the memory it needs does not grow with the list.
	 */
	const EXPORT_PAGE_SIZE = 500;

	/**
	 * Failure-log codes noted by this module (DoughBoss_Growth_Failures). Codes and stages only, never personal data.
	 */
	const FAIL_SIGNUP_STORAGE = 'waitlist_signup_storage_failed';
	const FAIL_SIGNUP_MAIL    = 'waitlist_signup_mail_failed';
	const FAIL_SIGNUP_TOKEN   = 'waitlist_signup_token_failed';
	const FAIL_OPTOUT         = 'waitlist_optout_failed';
	const FAIL_LIMITER        = 'waitlist_limiter_failed';
	const FAIL_PURGE          = 'waitlist_purge_failed';
	const FAIL_CSV            = 'waitlist_csv_export_failed';

	/**
	 * Hook everything. Re-checks the flag itself for everything that needs it.
	 *
	 * @return void
	 */
	public static function init() {
		// Always (flag on or off): the ways a person leaves, is exported or is erased, and the purge.
		if ( class_exists( 'DoughBoss_Growth_Waitlist_Privacy' ) ) {
			DoughBoss_Growth_Waitlist_Privacy::init();
		}
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_link' ), 1 );
		add_action( self::PURGE_HOOK, array( __CLASS__, 'purge' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( __CLASS__, 'handle_export' ) );
		if ( is_admin() ) {
			add_action( 'doughboss_growth_admin_tabs', array( __CLASS__, 'register_tab' ) );
		}
		// A page that holds a companion shortcode must never show the raw tag, whatever the flags say.
		if ( ! shortcode_exists( 'doughboss_growth_waitlist' ) ) {
			add_shortcode( 'doughboss_growth_waitlist', '__return_empty_string' );
		}
		if ( ! shortcode_exists( 'doughboss_growth_coming_soon' ) ) {
			add_shortcode( 'doughboss_growth_coming_soon', '__return_empty_string' );
		}

		if ( ! self::enabled() ) {
			// Leaving the list must keep working even while sign-ups are off.
			if ( class_exists( 'DoughBoss_Growth_Waitlist_Rest' ) ) {
				DoughBoss_Growth_Waitlist_Rest::register_unsubscribe_only();
			}
			return;
		}
		add_shortcode( 'doughboss_growth_waitlist', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_style_for_post' ) );
		if ( class_exists( 'DoughBoss_Growth_Waitlist_Rest' ) ) {
			DoughBoss_Growth_Waitlist_Rest::register();
		}
		if ( class_exists( 'DoughBoss_Growth_Outbox' ) ) {
			DoughBoss_Growth_Outbox::register_channel( self::OUTBOX_CHANNEL, array( __CLASS__, 'deliver_webhook' ) );
		}
		if ( false === wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( DoughBoss_Growth::now() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Schema and configuration                                                                     */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Full waitlist table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Full suppression table name.
	 *
	 * @return string
	 */
	public static function suppression_table() {
		global $wpdb;
		return $wpdb->prefix . self::SUPPRESSION_TABLE;
	}

	/**
	 * CREATE TABLE statements for dbDelta (collected by the activator for every module on disk).
	 *
	 * @return array
	 */
	public static function schema() {
		global $wpdb;
		$table   = self::table();
		$sup     = self::suppression_table();
		$collate = $wpdb->get_charset_collate();
		return array(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(191) NOT NULL,
  email_hash char(64) NOT NULL,
  first_name varchar(80) DEFAULT NULL,
  mobile_e164 varchar(18) DEFAULT NULL,
  store_pref bigint(20) unsigned DEFAULT NULL,
  interests_json text DEFAULT NULL,
  consent_marketing tinyint(1) NOT NULL DEFAULT 0,
  consent_text_version varchar(20) NOT NULL DEFAULT '',
  consent_text_hash char(64) NOT NULL DEFAULT '',
  consent_at_utc datetime NOT NULL,
  consent_source_path varchar(200) NOT NULL DEFAULT '',
  status varchar(12) NOT NULL DEFAULT 'pending',
  confirm_token_hash char(64) DEFAULT NULL,
  confirmed_at_utc datetime DEFAULT NULL,
  unsubscribed_at_utc datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY email_hash (email_hash),
  KEY status_created (status,created_at)
) {$collate};",
			"CREATE TABLE {$sup} (
  email_hash char(64) NOT NULL,
  reason varchar(20) NOT NULL DEFAULT 'unsubscribed',
  created_at datetime NOT NULL,
  PRIMARY KEY  (email_hash)
) {$collate};",
		);
	}

	/**
	 * The legal name of the sender, or an empty string when it is missing or unusable. The unannounced
	 * product's working name is refused here too (checked with the shared public-copy lint, so this file
	 * carries no copy of that word); when the lint is not available the answer is "unusable" (fail closed).
	 *
	 * @return string
	 */
	public static function sender_name() {
		$name = DoughBoss_Growth_Settings::get( 'sender_legal_name', '' );
		if ( ! is_string( $name ) || '' === trim( $name ) ) {
			return '';
		}
		$name = trim( $name );
		if ( ! class_exists( 'DoughBoss_Growth_Ledger', false ) && class_exists( 'DoughBoss_Growth' ) ) {
			DoughBoss_Growth::load_module( 'ledger' );
		}
		if ( ! class_exists( 'DoughBoss_Growth_Ledger', false ) ) {
			return '';
		}
		if ( in_array( 'product_name', DoughBoss_Growth_Ledger::lint_public( $name, 'owner-confirmed' ), true ) || in_array( 'encoding', DoughBoss_Growth_Ledger::lint_public( $name, 'owner-confirmed' ), true ) ) {
			return '';
		}
		return $name;
	}

	/**
	 * The privacy-policy URL as saved (absolute http(s) or a site-relative path), or an empty string.
	 *
	 * @return string
	 */
	public static function privacy_url() {
		$url = DoughBoss_Growth_Settings::get( 'privacy_policy_url', '' );
		return ( is_string( $url ) && '' !== $url ) ? $url : '';
	}

	/**
	 * The privacy-policy URL as an absolute address (for email).
	 *
	 * @return string
	 */
	public static function privacy_url_absolute() {
		$url = self::privacy_url();
		if ( '' !== $url && 0 === strpos( $url, '/' ) ) {
			return home_url( $url );
		}
		return $url;
	}

	/**
	 * Whether the owner inputs that the Spam Act needs are present: sender legal name and privacy-policy URL.
	 *
	 * @return bool
	 */
	public static function configured() {
		// No translation call here: enabled() runs at plugins_loaded, before WordPress may load a text domain.
		return '' !== self::sender_name() && '' !== self::privacy_url();
	}

	/**
	 * Whether the waitlist is on and complete: the flag (with its dependencies and the kill switch), the owner
	 * inputs and the database tables.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return DoughBoss_Growth_Settings::enabled( 'waitlist' )
			&& self::configured()
			&& DoughBoss_Growth_Activator::storage_ready();
	}

	/**
	 * The exact consent wording shown beside the unticked box. It names the sender, covers email (and text
	 * message only if a mobile number is given) and says how to withdraw. No product, price or date appears.
	 *
	 * @return string Empty when the sender name is missing.
	 */
	public static function consent_text() {
		$name = self::sender_name();
		if ( '' === $name ) {
			return '';
		}
		/* translators: %1$s: the sender's legal name. */
		return sprintf( __( 'I agree that %1$s may contact me by email, and by text message if I give a mobile number, with news about VIP first looks. I can withdraw this at any time using the unsubscribe link in any message, or by contacting %1$s.', 'doughboss-growth' ), $name );
	}

	/**
	 * SHA-256 of the exact consent wording.
	 *
	 * @return string
	 */
	public static function consent_hash() {
		return hash( 'sha256', self::consent_text() );
	}

	/**
	 * The consent wording version, derived from the wording itself so any change (including the sender name)
	 * is a new version and a stale form is refused.
	 *
	 * @return string "wl-" and 12 hex characters.
	 */
	public static function consent_version() {
		return 'wl-' . substr( self::consent_hash(), 0, 12 );
	}

	/**
	 * Active core shops that may be chosen as a store preference.
	 *
	 * @return array Id (int) => array( name, slug ). Empty when core cannot be read (fail closed).
	 */
	public static function stores() {
		if ( ! class_exists( 'DoughBoss_Locations' ) || ! is_callable( array( 'DoughBoss_Locations', 'all' ) ) ) {
			return array();
		}
		try {
			$rows = call_user_func( array( 'DoughBoss_Locations', 'all' ), true );
		} catch ( Throwable $e ) {
			return array();
		}
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$row = is_object( $row ) ? get_object_vars( $row ) : ( is_array( $row ) ? $row : array() );
			$id  = isset( $row['id'] ) ? (int) $row['id'] : 0;
			if ( $id < 1 ) {
				continue;
			}
			$name = ( isset( $row['name'] ) && is_string( $row['name'] ) ) ? sanitize_text_field( $row['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$slug        = ( isset( $row['slug'] ) && is_string( $row['slug'] ) ) ? $row['slug'] : '';
			$out[ $id ] = array(
				'name' => $name,
				'slug' => in_array( $slug, self::STORE_SLUGS, true ) ? $slug : '',
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Tokens                                                                                       */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * A form token: the issue time and an HMAC of it. Replaces a WordPress nonce so a cached page cannot break
	 * the submission, and doubles as a minimum-fill-time check. Nothing is stored.
	 *
	 * @return string
	 */
	public static function issue_form_token() {
		$stamp = DoughBoss_Growth::now();
		return $stamp . '.' . self::form_mac( (string) $stamp );
	}

	/**
	 * Check a form token.
	 *
	 * @param mixed $token Token from the request.
	 * @return string ok, early (younger than MIN_FORM_AGE), expired (older than MAX_FORM_AGE) or invalid.
	 */
	public static function check_form_token( $token ) {
		if ( ! is_string( $token ) || 1 !== preg_match( '/^([0-9]{9,11})\.([a-f0-9]{32})$/D', $token, $m ) ) {
			return 'invalid';
		}
		if ( ! hash_equals( self::form_mac( $m[1] ), $m[2] ) ) {
			return 'invalid';
		}
		$age = DoughBoss_Growth::now() - (int) $m[1];
		if ( $age < self::MIN_FORM_AGE ) {
			return 'early';
		}
		if ( $age > self::MAX_FORM_AGE ) {
			return 'expired';
		}
		return 'ok';
	}

	/**
	 * HMAC for a form token.
	 *
	 * @param string $stamp Issue time.
	 * @return string 32 hex characters.
	 */
	private static function form_mac( $stamp ) {
		return substr( hash_hmac( 'sha256', 'dbgr-wl-form|' . $stamp, wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * The opt-out token for a row. Derived, not stored, so it stays valid for every later message and needs no
	 * column. Bound to the row id and the email hash.
	 *
	 * @param int    $id         Row id.
	 * @param string $email_hash Email hash.
	 * @return string 32 hex characters.
	 */
	public static function unsubscribe_token( $id, $email_hash ) {
		return substr( hash_hmac( 'sha256', 'dbgr-wl-unsub|' . (int) $id . '|' . $email_hash, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * The opt-out link for a row. Later messages to the list must carry it.
	 *
	 * @param int    $id         Row id.
	 * @param string $email_hash Email hash.
	 * @return string
	 */
	public static function unsubscribe_url( $id, $email_hash ) {
		return add_query_arg(
			array(
				self::QUERY_VAR => 'unsubscribe',
				'i'             => (string) (int) $id,
				't'             => self::unsubscribe_token( $id, $email_hash ),
			),
			home_url( '/' )
		);
	}

	/**
	 * A new single-use confirmation token (160 random bits as 40 hex characters).
	 *
	 * @return string Empty when no secure randomness is available (the caller then fails closed).
	 */
	private static function new_confirm_token() {
		try {
			return bin2hex( random_bytes( 20 ) );
		} catch ( Throwable $e ) {
			return '';
		}
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Input handling                                                                               */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Normalise an email address: trimmed, lower case, valid, 191 characters at most, no control characters or
	 * separators that could inject a header.
	 *
	 * @param mixed $raw Raw value.
	 * @return string Empty when invalid.
	 */
	public static function normalise_email( $raw ) {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$email = strtolower( trim( $raw ) );
		if ( '' === $email || strlen( $email ) > self::MAX_EMAIL || 1 === preg_match( '/[\x00-\x20\x7f<>,;"\\\\]/', $email ) ) {
			return '';
		}
		if ( sanitize_email( $email ) !== $email || false === is_email( $email ) ) {
			return '';
		}
		return $email;
	}

	/**
	 * Hash of a normalised email address (SHA-256, as in the architecture).
	 *
	 * @param string $email Normalised email.
	 * @return string
	 */
	public static function email_hash( $email ) {
		return hash( 'sha256', $email );
	}

	/**
	 * Normalise an Australian mobile number to E.164.
	 *
	 * @param mixed $raw Raw value.
	 * @return string|false Empty string when none was given, "+614XXXXXXXX" when valid, false when invalid.
	 */
	public static function normalise_mobile( $raw ) {
		if ( null === $raw || '' === $raw ) {
			return '';
		}
		if ( ! is_string( $raw ) ) {
			return false;
		}
		$digits = preg_replace( '/[\s().-]/', '', $raw );
		if ( '' === $digits ) {
			return '';
		}
		if ( 1 === preg_match( '/^(?:\+61|61|0)?(4[0-9]{8})$/D', $digits, $m ) ) {
			return '+61' . $m[1];
		}
		return false;
	}

	/**
	 * A first name: tags and control characters removed, 80 characters at most. Optional.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function clean_name( $raw ) {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$name = sanitize_text_field( $raw );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $name, 0, self::MAX_NAME, 'UTF-8' );
		}
		return substr( $name, 0, self::MAX_NAME );
	}

	/**
	 * The path of the page the sign-up happened on (path only, no query, no host).
	 *
	 * @param mixed $raw Raw value.
	 * @return string Empty when not a plain path.
	 */
	public static function clean_path( $raw ) {
		if ( ! is_string( $raw ) || 1 !== preg_match( '#^/[A-Za-z0-9/_.~%-]{0,' . ( self::MAX_PATH - 1 ) . '}$#D', $raw ) ) {
			return '';
		}
		return $raw;
	}

	/**
	 * Whether a consent value is an explicit "yes": the number 1, the string "1" or boolean true. Anything else
	 * (absent, 0, "on", "yes", an array) is not consent.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function consent_given( $value ) {
		return true === $value || 1 === $value || '1' === $value;
	}

	/**
	 * Validate a sign-up request.
	 *
	 * @param array $in Raw input (email, first_name, mobile, store, consent, consent_version, path).
	 * @return array { ok: bool, errors: array field => code, clean: array }
	 */
	public static function validate( array $in ) {
		$errors = array();

		$email = self::normalise_email( isset( $in['email'] ) ? $in['email'] : '' );
		if ( '' === $email ) {
			$errors['email'] = 'invalid';
		}

		$mobile = self::normalise_mobile( isset( $in['mobile'] ) ? $in['mobile'] : '' );
		if ( false === $mobile ) {
			$errors['mobile'] = 'invalid';
			$mobile           = '';
		}

		$store     = null;
		$raw_store = isset( $in['store'] ) ? $in['store'] : '';
		if ( is_int( $raw_store ) || ( is_string( $raw_store ) && 1 === preg_match( '/^[0-9]{1,10}$/D', $raw_store ) ) ) {
			$store_id = (int) $raw_store;
			if ( $store_id > 0 ) {
				if ( array_key_exists( $store_id, self::stores() ) ) {
					$store = $store_id;
				} else {
					$errors['store'] = 'invalid';
				}
			}
		} elseif ( ! ( '' === $raw_store || null === $raw_store || 'none' === $raw_store ) ) {
			$errors['store'] = 'invalid';
		}

		if ( ! self::consent_given( isset( $in['consent'] ) ? $in['consent'] : null ) ) {
			$errors['consent'] = 'required';
		}
		if ( ! isset( $in['consent_version'] ) || ! is_string( $in['consent_version'] ) || ! hash_equals( self::consent_version(), $in['consent_version'] ) ) {
			$errors['consent_version'] = 'changed';
		}

		return array(
			'ok'     => ( array() === $errors ),
			'errors' => $errors,
			'clean'  => array(
				'email'      => $email,
				'first_name' => self::clean_name( isset( $in['first_name'] ) ? $in['first_name'] : '' ),
				'mobile'     => $mobile,
				'store'      => $store,
				'path'       => self::clean_path( isset( $in['path'] ) ? $in['path'] : '' ),
			),
		);
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Rate limiting                                                                                */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * The hashed visitor address for rate-limit buckets (never stored raw).
	 *
	 * @return string
	 */
	public static function visitor_bucket() {
		return 'ip:' . DoughBoss_Growth_Rate_Limit::hash_ip( DoughBoss_Growth_Rate_Limit::client_ip() );
	}

	/**
	 * Apply the sign-up limits that need no email: 5 an hour per hashed address, then the 300 a day breaker.
	 *
	 * @return array Same shape as DoughBoss_Growth_Rate_Limit::hit().
	 */
	public static function limit_visitor() {
		return DoughBoss_Growth_Rate_Limit::hit_all(
			array(
				array( self::visitor_bucket(), self::LIMIT_IP, self::WINDOW_IP ),
				array( 'wl:global', self::LIMIT_GLOBAL, self::WINDOW_DAY ),
			)
		);
	}

	/**
	 * Apply the per-email limit (3 a day).
	 *
	 * @param string $email_hash Email hash.
	 * @return array Same shape as DoughBoss_Growth_Rate_Limit::hit().
	 */
	public static function limit_email( $email_hash ) {
		return DoughBoss_Growth_Rate_Limit::hit( 'wl:email:' . $email_hash, self::LIMIT_EMAIL, self::WINDOW_DAY );
	}

	/**
	 * Apply the per-address limit for the link actions (confirm and opt-out), so a token cannot be guessed at speed.
	 *
	 * The opt-out does NOT call this first: see unsubscribe_attempt(), which counts only INVALID attempts.
	 *
	 * @return array Same shape as DoughBoss_Growth_Rate_Limit::hit().
	 */
	public static function limit_action() {
		return DoughBoss_Growth_Rate_Limit::hit( 'wl:act:' . self::visitor_bucket(), self::LIMIT_ACTION, self::WINDOW_IP );
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Failures the owner can see                                                                   */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Note one failure for the owner: it goes to the shared failure list (DoughBoss_Growth_Failures, shown on the Growth
	 * settings screen and on the VIP waitlist tab), and to the debug log when that is on. The code is one of the FAIL_*
	 * names (or another snake_case name ending _failed); the context is a stage or a count and NEVER personal data (no
	 * email, name, token, address or hash). Never throws, so noting a failure cannot be what breaks a request.
	 *
	 * @param string $code    Failure code.
	 * @param array  $context Scalar facts without personal data (stage, route, counts).
	 * @return void
	 */
	public static function note_failure( $code, array $context = array() ) {
		try {
			if ( class_exists( 'DoughBoss_Growth_Http', false ) ) {
				DoughBoss_Growth_Http::log( $code, $context ); // Debug log, action and the failure list in one call.
			} elseif ( class_exists( 'DoughBoss_Growth_Failures', false ) ) {
				DoughBoss_Growth_Failures::record( $code, $context );
			}
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * The most recent failure whose code starts with a prefix, from the shared failure list.
	 *
	 * @param string $prefix Code prefix (or a whole code).
	 * @return array|null array( code, count, first_seen, last_seen, context ), or null when none is recorded.
	 */
	private static function latest_failure( $prefix ) {
		if ( ! class_exists( 'DoughBoss_Growth_Failures', false ) ) {
			return null;
		}
		foreach ( DoughBoss_Growth_Failures::all() as $record ) { // Newest first.
			if ( 0 === strpos( $record['code'], $prefix ) ) {
				return $record;
			}
		}
		return null;
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Storage                                                                                      */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Run a SELECT that returns one row.
	 *
	 * @param string $sql Prepared SQL.
	 * @return array|null|false The row, null when there is none, false on a database error.
	 */
	private static function fetch_row( $sql ) {
		global $wpdb;
		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- the caller passes $wpdb->prepare() output.
		if ( '' !== (string) $wpdb->last_error ) {
			return false;
		}
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Row by email hash.
	 *
	 * @param string $hash Email hash.
	 * @return array|null|false
	 */
	public static function row_by_hash( $hash ) {
		global $wpdb;
		$table = self::table();
		return self::fetch_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email_hash = %s LIMIT 1", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Row by id.
	 *
	 * @param int $id Row id.
	 * @return array|null|false
	 */
	public static function row_by_id( $id ) {
		global $wpdb;
		$table = self::table();
		return self::fetch_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Whether an address is on the suppression list.
	 *
	 * @param string $hash Email hash.
	 * @return bool|null True or false, null on a database error.
	 */
	public static function is_suppressed( $hash ) {
		global $wpdb;
		$table  = self::suppression_table();
		$reason = $wpdb->get_var( $wpdb->prepare( "SELECT reason FROM {$table} WHERE email_hash = %s LIMIT 1", $hash ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' !== (string) $wpdb->last_error ) {
			return null;
		}
		return ( null !== $reason && false !== $reason && '' !== (string) $reason );
	}

	/**
	 * Record an address as suppressed. Idempotent.
	 *
	 * @param string $hash   Email hash.
	 * @param string $reason unsubscribed or erased.
	 * @return bool False on a database error.
	 */
	public static function suppress( $hash, $reason ) {
		global $wpdb;
		$table = self::suppression_table();
		$rows  = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (email_hash, reason, created_at) VALUES (%s, %s, %s)",
				$hash,
				( 'erased' === $reason ) ? 'erased' : 'unsubscribed',
				gmdate( 'Y-m-d H:i:s', DoughBoss_Growth::now() )
			)
		);
		return ( false !== $rows && '' === (string) $wpdb->last_error );
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Sign-up, confirm, opt-out                                                                    */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Store a validated sign-up and send the confirmation email.
	 *
	 * Every outcome except an error looks the same to the visitor (no account enumeration): a new row, a pending
	 * row that is sent a fresh link, an address already confirmed, an opted-out address and a suppressed address.
	 * Errors are told apart for the OWNER only: error_storage (a read or write that failed) and error_mail (the
	 * confirmation email could not be sent) are each noted in the failure list with a stage, never with personal data;
	 * the visitor gets the same neutral answer for all of them.
	 *
	 * @param array $clean The "clean" part of validate().
	 * @return array { result: created|resent|noop|error_storage|error_mail|error, stage?: string }
	 */
	public static function signup( array $clean ) {
		global $wpdb;
		$fail = array( 'result' => 'error' );
		if ( ! self::enabled() || empty( $clean['email'] ) || ! is_string( $clean['email'] ) ) {
			return $fail;
		}
		$email = $clean['email'];
		$hash  = self::email_hash( $email );

		$suppressed = self::is_suppressed( $hash );
		if ( null === $suppressed ) {
			return self::signup_failed( 'storage', 'suppression_read' );
		}
		if ( $suppressed ) {
			return array( 'result' => 'noop' );
		}

		$row = self::row_by_hash( $hash );
		if ( false === $row ) {
			return self::signup_failed( 'storage', 'row_read' );
		}
		if ( null !== $row ) {
			if ( self::STATUS_PENDING !== $row['status'] ) {
				return array( 'result' => 'noop' );
			}
			return self::resend_confirmation( $row );
		}

		$token = self::new_confirm_token();
		if ( '' === $token ) {
			return self::signup_failed( 'token', 'confirm_token' );
		}
		$now   = gmdate( 'Y-m-d H:i:s', DoughBoss_Growth::now() );
		$table = self::table();

		// NULL where nothing was given, so an optional field is never stored as an empty string.
		$cols   = array( 'email', 'email_hash', 'first_name', 'mobile_e164', 'store_pref', 'consent_marketing', 'consent_text_version', 'consent_text_hash', 'consent_at_utc', 'consent_source_path', 'status', 'confirm_token_hash', 'created_at', 'updated_at' );
		$marks  = array( '%s', '%s', ( '' === $clean['first_name'] ) ? 'NULL' : '%s', ( '' === $clean['mobile'] ) ? 'NULL' : '%s', ( null === $clean['store'] ) ? 'NULL' : '%d', '1', '%s', '%s', '%s', '%s', "'pending'", '%s', '%s', '%s' );
		$values = array( $email, $hash );
		if ( '' !== $clean['first_name'] ) {
			$values[] = $clean['first_name'];
		}
		if ( '' !== $clean['mobile'] ) {
			$values[] = $clean['mobile'];
		}
		if ( null !== $clean['store'] ) {
			$values[] = (int) $clean['store'];
		}
		$values = array_merge( $values, array( self::consent_version(), self::consent_hash(), $now, $clean['path'], hash( 'sha256', $token ), $now, $now ) );

		$sql  = "INSERT IGNORE INTO {$table} (" . implode( ', ', $cols ) . ') VALUES (' . implode( ', ', $marks ) . ')';
		$rows = $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders are fixed above.
		if ( false === $rows || '' !== (string) $wpdb->last_error ) {
			return self::signup_failed( 'storage', 'insert' );
		}
		if ( 1 !== (int) $rows ) {
			// Another request stored the same address a moment ago: it sends the email.
			return array( 'result' => 'noop' );
		}
		$id = (int) $wpdb->insert_id;
		if ( $id < 1 ) {
			$found = self::row_by_hash( $hash );
			$id    = ( is_array( $found ) && isset( $found['id'] ) ) ? (int) $found['id'] : 0;
		}
		if ( $id < 1 ) {
			return self::signup_failed( 'storage', 'row_lookup' );
		}
		if ( ! self::send_confirmation_email( $id, $email, $hash, $token ) ) {
			// Never leave a half-made sign-up: no email went out, so remove the row. A row that cannot be removed is a
			// failed write the owner should see too (the person can simply sign up again: a pending row is sent a fresh link).
			$gone = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d AND status = 'pending'", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $gone || '' !== (string) $wpdb->last_error ) {
				self::note_failure( self::FAIL_SIGNUP_STORAGE, array( 'stage' => 'rollback' ) );
			}
			return self::signup_failed( 'mail', 'confirmation' );
		}
		return array( 'result' => 'created' );
	}

	/**
	 * A sign-up error: noted for the owner (code and stage only) and returned as a distinct internal result. The visitor
	 * is told the same neutral thing whatever the cause (see DoughBoss_Growth_Waitlist_Rest::handle_signup()).
	 *
	 * @param string $kind  storage, mail or token.
	 * @param string $stage Where it failed (a short snake_case name).
	 * @return array { result: error_storage|error_mail|error, stage: string }
	 */
	private static function signup_failed( $kind, $stage ) {
		if ( 'mail' === $kind ) {
			self::note_failure( self::FAIL_SIGNUP_MAIL, array( 'stage' => $stage ) );
			$result = 'error_mail';
		} elseif ( 'storage' === $kind ) {
			self::note_failure( self::FAIL_SIGNUP_STORAGE, array( 'stage' => $stage ) );
			$result = 'error_storage';
		} else {
			self::note_failure( self::FAIL_SIGNUP_TOKEN, array( 'stage' => $stage ) );
			$result = 'error';
		}
		return array(
			'result' => $result,
			'stage'  => $stage,
		);
	}

	/**
	 * Give a pending row a fresh confirmation token and send the email again.
	 *
	 * @param array $row Pending row.
	 * @return array { result: resent|error_storage|error_mail|error, stage?: string }
	 */
	private static function resend_confirmation( array $row ) {
		global $wpdb;
		$token = self::new_confirm_token();
		if ( '' === $token ) {
			return self::signup_failed( 'token', 'confirm_token' );
		}
		if ( empty( $row['id'] ) || empty( $row['email'] ) || empty( $row['email_hash'] ) ) {
			return self::signup_failed( 'storage', 'resend_row' );
		}
		$table = self::table();
		$rows  = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"UPDATE {$table} SET confirm_token_hash = %s, updated_at = %s WHERE id = %d AND status = 'pending'",
				hash( 'sha256', $token ),
				gmdate( 'Y-m-d H:i:s', DoughBoss_Growth::now() ),
				(int) $row['id']
			)
		);
		if ( 1 !== (int) $rows || '' !== (string) $wpdb->last_error ) {
			return self::signup_failed( 'storage', 'resend_update' );
		}
		if ( ! self::send_confirmation_email( (int) $row['id'], (string) $row['email'], (string) $row['email_hash'], $token ) ) {
			return self::signup_failed( 'mail', 'resend' );
		}
		return array( 'result' => 'resent' );
	}

	/**
	 * Send the double opt-in email. It names the sender, carries the confirmation link and a working opt-out
	 * link, says what happens if the person ignores it, and mentions no product, price or date.
	 *
	 * @param int    $id         Row id.
	 * @param string $email      Recipient (already validated).
	 * @param string $email_hash Email hash.
	 * @param string $token      Confirmation token (raw).
	 * @return bool
	 */
	private static function send_confirmation_email( $id, $email, $email_hash, $token ) {
		$sender = self::sender_name();
		if ( '' === $sender ) {
			return false;
		}
		$confirm = add_query_arg(
			array(
				self::QUERY_VAR => 'confirm',
				'i'             => (string) (int) $id,
				't'             => $token,
			),
			home_url( '/' )
		);
		$unsub   = self::unsubscribe_url( $id, $email_hash );

		$lines   = array(
			__( 'Hello,', 'doughboss-growth' ),
			'',
			/* translators: %s: the sender's legal name. */
			sprintf( __( 'Someone asked to join the VIP list from %s using this email address. If that was you, please confirm.', 'doughboss-growth' ), $sender ),
			'',
			__( 'Open this link, then press the Confirm button:', 'doughboss-growth' ),
			$confirm,
			'',
			__( 'If you did not ask to join, do nothing. You will not be added and no messages will be sent to you.', 'doughboss-growth' ),
			'',
			__( 'To make sure you never hear from us, use this link at any time:', 'doughboss-growth' ),
			$unsub,
			'',
			__( 'Privacy policy:', 'doughboss-growth' ) . ' ' . self::privacy_url_absolute(),
			'',
			/* translators: %s: the sender's legal name. */
			sprintf( __( 'Sent by %s.', 'doughboss-growth' ), $sender ),
		);
		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			'List-Unsubscribe: <' . $unsub . '>',
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
		);
		$subject = __( 'Please confirm your VIP list sign-up', 'doughboss-growth' );
		return true === wp_mail( $email, $subject, implode( "\n", $lines ), $headers );
	}

	/**
	 * Confirm a pending sign-up (double opt-in). The token is single use.
	 *
	 * @param mixed $id    Row id from the link.
	 * @param mixed $token Token from the link.
	 * @return array { result: confirmed|invalid|error }
	 */
	public static function confirm( $id, $token ) {
		global $wpdb;
		if ( ! self::enabled() ) {
			return array( 'result' => 'error' );
		}
		$id = self::clean_id( $id );
		if ( $id < 1 || ! is_string( $token ) || 1 !== preg_match( '/^[a-f0-9]{40}$/D', $token ) ) {
			return array( 'result' => 'invalid' );
		}
		$row = self::row_by_id( $id );
		if ( false === $row ) {
			return array( 'result' => 'error' );
		}
		if ( null === $row || self::STATUS_PENDING !== $row['status'] || empty( $row['confirm_token_hash'] ) || ! hash_equals( (string) $row['confirm_token_hash'], hash( 'sha256', $token ) ) ) {
			return array( 'result' => 'invalid' );
		}
		$now   = gmdate( 'Y-m-d H:i:s', DoughBoss_Growth::now() );
		$table = self::table();
		$rows  = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'confirmed', confirmed_at_utc = %s, confirm_token_hash = NULL, updated_at = %s WHERE id = %d AND status = 'pending' AND confirm_token_hash = %s",
				$now,
				$now,
				$id,
				hash( 'sha256', $token )
			)
		);
		if ( false === $rows || '' !== (string) $wpdb->last_error ) {
			return array( 'result' => 'error' );
		}
		if ( 1 !== (int) $rows ) {
			return array( 'result' => 'invalid' ); // Lost a race with another click: the first one won.
		}
		do_action( 'doughboss_growth_waitlist_confirmed', $id );
		self::queue_webhook( $id, isset( $row['store_pref'] ) ? $row['store_pref'] : null, $now );
		return array( 'result' => 'confirmed' );
	}

	/**
	 * Opt a person out. Works whatever the waitlist flag says: a person must always be able to leave. Idempotent.
	 *
	 * The token is checked here, against the row, and NOTHING in this method touches the rate limiter. A storage problem
	 * (a read, the suppression write or the status update) is an error that is noted for the owner (stage only) and is
	 * never reported as an invalid link or as success.
	 *
	 * @param mixed $id    Row id from the link.
	 * @param mixed $token Opt-out token from the link.
	 * @return array { result: unsubscribed|invalid|error }
	 */
	public static function unsubscribe( $id, $token ) {
		global $wpdb;
		if ( ! DoughBoss_Growth_Activator::storage_ready() ) {
			self::note_failure( self::FAIL_OPTOUT, array( 'stage' => 'storage_not_ready' ) );
			return array( 'result' => 'error' );
		}
		$id = self::clean_id( $id );
		if ( $id < 1 || ! is_string( $token ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $token ) ) {
			return array( 'result' => 'invalid' );
		}
		$row = self::row_by_id( $id );
		if ( false === $row ) {
			self::note_failure( self::FAIL_OPTOUT, array( 'stage' => 'row_read' ) );
			return array( 'result' => 'error' );
		}
		// A missing row and a wrong token look the same: no way to probe which ids exist.
		if ( null === $row || empty( $row['email_hash'] ) || ! hash_equals( self::unsubscribe_token( $id, (string) $row['email_hash'] ), $token ) ) {
			return array( 'result' => 'invalid' );
		}
		if ( ! self::suppress( (string) $row['email_hash'], 'unsubscribed' ) ) {
			self::note_failure( self::FAIL_OPTOUT, array( 'stage' => 'suppress' ) );
			return array( 'result' => 'error' );
		}
		if ( self::STATUS_UNSUBSCRIBED === $row['status'] ) {
			return array( 'result' => 'unsubscribed' );
		}
		$now   = gmdate( 'Y-m-d H:i:s', DoughBoss_Growth::now() );
		$table = self::table();
		$rows  = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'unsubscribed', unsubscribed_at_utc = %s, confirm_token_hash = NULL, updated_at = %s WHERE id = %d AND status <> 'unsubscribed'",
				$now,
				$now,
				$id
			)
		);
		if ( false === $rows || '' !== (string) $wpdb->last_error ) {
			self::note_failure( self::FAIL_OPTOUT, array( 'stage' => 'update' ) );
			return array( 'result' => 'error' );
		}
		return array( 'result' => 'unsubscribed' );
	}

	/**
	 * An opt-out request from a visitor: the REST route and the email-link page both come here.
	 *
	 * The token is verified FIRST, and only an INVALID attempt (malformed, unknown id, wrong token) is counted against
	 * the per-address bucket (30 an hour, shared with confirming). A valid token is never counted and never held back by
	 * the bucket, so a shared address (a host proxy, or a mail client posting forty one-click links) cannot lock people
	 * out of leaving the list, and neither can a limiter storage error: the limiter is not consulted for a valid token at
	 * all. An invalid token is still limited and still fails closed when the limiter cannot count it, so a token cannot be
	 * guessed at speed and a broken limiter never turns an invalid link into an opt-out. A failed valid opt-out (storage)
	 * is an error, not counted, so the person can simply try again.
	 *
	 * @param mixed $id    Row id from the link.
	 * @param mixed $token Opt-out token from the link.
	 * @return array { result: unsubscribed|invalid|limited|error, retry_after: int }
	 */
	public static function unsubscribe_attempt( $id, $token ) {
		$result = self::unsubscribe( $id, $token );
		$out    = array(
			'result'      => $result['result'],
			'retry_after' => 0,
		);
		if ( 'invalid' !== $result['result'] ) {
			return $out; // Opted out, or a storage error: neither is a bad attempt.
		}
		$limit = self::limit_action();
		if ( $limit['allowed'] ) {
			return $out; // An invalid link, counted.
		}
		if ( 'limited' === $limit['reason'] ) {
			return array(
				'result'      => 'limited',
				'retry_after' => (int) $limit['retry_after'],
			);
		}
		// The limiter could not count the invalid attempt: fail closed.
		self::note_failure(
			self::FAIL_LIMITER,
			array(
				'route'  => 'optout',
				'reason' => isset( $limit['reason'] ) ? $limit['reason'] : 'unknown',
			)
		);
		return array(
			'result'      => 'error',
			'retry_after' => 0,
		);
	}

	/**
	 * A row id from a link or request: digits only, 12 at most, otherwise 0.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private static function clean_id( $value ) {
		if ( is_int( $value ) ) {
			return max( 0, $value );
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[0-9]{1,12}$/D', $value ) ) {
			return (int) $value;
		}
		return 0;
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Notification webhook (off unless a URL and a secret are configured)                          */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Queue the "waitlist confirmed" notification: id, store and time only, never an email or a name.
	 *
	 * @param int        $id         Row id.
	 * @param mixed      $store_pref Core shop id or null.
	 * @param string     $confirmed  UTC datetime.
	 * @return string The outbox result, or "off" when the webhook is not configured.
	 */
	private static function queue_webhook( $id, $store_pref, $confirmed ) {
		if ( '' === (string) DoughBoss_Growth_Settings::get( 'notify_webhook_url', '' ) || ! DoughBoss_Growth_Settings::has_secret( 'DOUGHBOSS_GROWTH_WEBHOOK_SECRET' ) ) {
			return 'off';
		}
		$result = DoughBoss_Growth_Outbox::enqueue(
			self::OUTBOX_CHANNEL,
			'waitlist_confirmed',
			'waitlist:' . (int) $id,
			'waitlist',
			(string) (int) $id,
			array(
				'event'        => 'waitlist.confirmed',
				'id'           => (int) $id,
				'store_pref'   => ( null === $store_pref || '' === $store_pref ) ? null : (int) $store_pref,
				'confirmed_at' => str_replace( ' ', 'T', $confirmed ) . 'Z',
			)
		);
		if ( 'queued' !== $result && 'duplicate' !== $result ) {
			DoughBoss_Growth_Http::log( 'waitlist_webhook_enqueue', array( 'result' => $result ) );
		}
		return $result;
	}

	/**
	 * Outbox handler: POST the signed notification. The signature is an HMAC-SHA256 of the exact body.
	 *
	 * @param array $row Outbox row with the decoded payload.
	 * @return true|WP_Error
	 */
	public static function deliver_webhook( array $row ) {
		$url    = (string) DoughBoss_Growth_Settings::get( 'notify_webhook_url', '' );
		$secret = DoughBoss_Growth_Settings::secret( 'DOUGHBOSS_GROWTH_WEBHOOK_SECRET' );
		if ( '' === $url || '' === $secret || ! isset( $row['payload'] ) || ! is_array( $row['payload'] ) ) {
			return new WP_Error( 'not_configured', 'not configured', array( 'terminal' => true ) );
		}
		$body = wp_json_encode( $row['payload'] );
		if ( ! is_string( $body ) ) {
			return new WP_Error( 'bad_payload', 'bad payload', array( 'terminal' => true ) );
		}
		$result = DoughBoss_Growth_Http::request(
			'POST',
			$url,
			array(
				'body'    => $body,
				'headers' => array(
					'Content-Type'                => 'application/json',
					'X-DoughBoss-Growth-Signature' => 'sha256=' . hash_hmac( 'sha256', $body, $secret ),
				),
			)
		);
		if ( ! empty( $result['ok'] ) ) {
			return true;
		}
		$status   = isset( $result['status'] ) ? (int) $result['status'] : 0;
		$terminal = ( $status >= 400 && $status < 500 && 408 !== $status && 429 !== $status ) || 'url_not_allowed' === ( isset( $result['error'] ) ? $result['error'] : '' );
		return new WP_Error( $terminal ? 'rejected' : 'delivery_failed', 'delivery failed', array( 'terminal' => $terminal ) );
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Retention                                                                                    */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Daily retention purge. Pending rows go after retention_pending_days (30 by default); opted-out rows are
	 * reduced to a suppression hash after 30 days; confirmed rows go only when the owner has set
	 * retention_confirmed_months (until then they are never deleted automatically).
	 *
	 * A failure is never collapsed into "nothing to do". Each count is the rows removed by that step, or null when the
	 * step itself failed (a database error); "failed" is how many steps or rows failed. An opted-out row whose
	 * suppression hash could not be recorded, or whose DELETE failed, is left where it is for the next run and counted
	 * as failed. Any failure is noted in the failure list (stage names and counts only) and shown on the VIP waitlist
	 * tab until a clean run clears it. WP-Cron discards the return value, so the note is what the owner sees.
	 *
	 * @return array { pending: int|null, unsubscribed: int|null, confirmed: int|null, failed: int }
	 */
	public static function purge() {
		global $wpdb;
		$out = array(
			'pending'      => 0,
			'unsubscribed' => 0,
			'confirmed'    => 0,
			'failed'       => 0,
		);
		if ( ! DoughBoss_Growth_Activator::storage_ready() ) {
			return $out;
		}
		$table  = self::table();
		$now    = DoughBoss_Growth::now();
		$stages = array();

		// The limiter's buckets include "wl:email:<sha256 of the address>": drop every bucket whose window ended more
		// than a day ago, so an address hash never outlives its rate window (or an erasure) in that table. The limiter
		// reports an error as 0 rows, so the database error flag is what tells a failure from an empty table.
		DoughBoss_Growth_Rate_Limit::purge_expired();
		if ( '' !== (string) $wpdb->last_error ) {
			$stages[] = 'rate_buckets';
			$out['failed']++;
		}

		$days = (int) DoughBoss_Growth_Settings::get( 'retention_pending_days', 30 );
		if ( $days < 1 ) {
			$days = 30;
		}
		$rows = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "DELETE FROM {$table} WHERE status = 'pending' AND created_at < %s", gmdate( 'Y-m-d H:i:s', $now - ( $days * DAY_IN_SECONDS ) ) )
		);
		if ( false === $rows || '' !== (string) $wpdb->last_error ) {
			$out['pending'] = null;
			$stages[]       = 'pending_delete';
			$out['failed']++;
		} else {
			$out['pending'] = (int) $rows;
		}

		// Opted-out rows: make sure the suppression hash exists, then drop the details.
		$cutoff = gmdate( 'Y-m-d H:i:s', $now - ( self::UNSUBSCRIBED_ROW_DAYS * DAY_IN_SECONDS ) );
		$old    = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT id, email_hash FROM {$table} WHERE status = 'unsubscribed' AND unsubscribed_at_utc < %s ORDER BY id ASC LIMIT 500", $cutoff ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $old ) ) {
			$out['unsubscribed'] = null;
			$stages[]            = 'optout_read';
			$out['failed']++;
		} else {
			$removed         = 0;
			$suppress_failed = 0;
			$delete_failed   = 0;
			foreach ( $old as $item ) {
				if ( empty( $item['email_hash'] ) || empty( $item['id'] ) || ! self::suppress( (string) $item['email_hash'], 'unsubscribed' ) ) {
					$suppress_failed++; // Never drop the details unless the opt-out is safely recorded; the row waits for the next run.
					continue;
				}
				$gone = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d AND status = 'unsubscribed'", (int) $item['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( false === $gone || '' !== (string) $wpdb->last_error ) {
					$delete_failed++; // The row stays for the next run.
					continue;
				}
				$removed += (int) $gone; // 0 means it was already gone (an erasure got there first): not a failure.
			}
			$out['unsubscribed'] = $removed;
			if ( $suppress_failed > 0 ) {
				$stages[]      = 'suppress';
				$out['failed'] += $suppress_failed;
			}
			if ( $delete_failed > 0 ) {
				$stages[]      = 'optout_delete';
				$out['failed'] += $delete_failed;
			}
		}

		$months = DoughBoss_Growth_Settings::get( 'retention_confirmed_months', null );
		if ( is_int( $months ) && $months >= 1 ) {
			$limit = strtotime( '-' . $months . ' months', $now );
			if ( false === $limit ) {
				$out['confirmed'] = null;
				$stages[]         = 'confirmed_cutoff';
				$out['failed']++;
			} else {
				$rows = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->prepare( "DELETE FROM {$table} WHERE status = 'confirmed' AND confirmed_at_utc < %s", gmdate( 'Y-m-d H:i:s', $limit ) )
				);
				if ( false === $rows || '' !== (string) $wpdb->last_error ) {
					$out['confirmed'] = null;
					$stages[]         = 'confirmed_delete';
					$out['failed']++;
				} else {
					$out['confirmed'] = (int) $rows;
				}
			}
		}

		if ( $out['failed'] > 0 ) {
			self::note_failure(
				self::FAIL_PURGE,
				array(
					'stage'   => implode( ',', $stages ),
					'failed'  => $out['failed'],
					'removed' => (int) $out['pending'] + (int) $out['unsubscribed'] + (int) $out['confirmed'],
				)
			);
		} elseif ( class_exists( 'DoughBoss_Growth_Failures', false ) ) {
			DoughBoss_Growth_Failures::clear( self::FAIL_PURGE ); // A clean run: an earlier failure no longer stands.
		}
		return $out;
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Shortcode and assets                                                                         */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Register (not enqueue) the script and style. Safe to call more than once; they are enqueued when a form is rendered.
	 *
	 * @return void
	 */
	public static function register_assets() {
		wp_register_style( self::HANDLE_STYLE, DOUGHBOSS_GROWTH_URL . 'public/css/dbgr-coming-soon.css', array(), DOUGHBOSS_GROWTH_VERSION );
		wp_register_script( self::HANDLE_SCRIPT, DOUGHBOSS_GROWTH_URL . 'public/js/dbgr-waitlist.js', array(), DOUGHBOSS_GROWTH_VERSION, true );
	}

	/**
	 * Whether the browser configuration was already printed for this request.
	 *
	 * @var bool
	 */
	private static $config_done = false;

	/**
	 * Forget the per-request state (tests only).
	 *
	 * @return void
	 */
	public static function reset_state() {
		self::$config_done = false;
	}

	/**
	 * Enqueue the style and the script, and print the browser configuration once when a form is on the page.
	 *
	 * @param bool $with_config Whether the page has a form (the configuration is only needed then).
	 * @return void
	 */
	public static function enqueue_assets( $with_config ) {
		self::register_assets();
		wp_enqueue_style( self::HANDLE_STYLE );
		wp_enqueue_script( self::HANDLE_SCRIPT );
		if ( $with_config && ! self::$config_done ) {
			self::$config_done = true;
			wp_localize_script( self::HANDLE_SCRIPT, 'DoughBossGrowthWaitlist', self::browser_config() );
		}
	}

	/**
	 * wp_enqueue_scripts: on a single post or page that holds one of this module's shortcodes, put the stylesheet in the
	 * head, so the form is styled the moment it paints (otherwise it is enqueued while the shortcode renders, too late for
	 * the head). Only the style: the script and its configuration still load in the footer when a form is rendered.
	 *
	 * @return void
	 */
	public static function enqueue_style_for_post() {
		if ( is_admin() || ! function_exists( 'is_singular' ) || ! is_singular() || ! function_exists( 'get_queried_object_id' ) ) {
			return;
		}
		$post = get_post( get_queried_object_id() );
		if ( ! is_object( $post ) || ! isset( $post->post_content ) || ! is_string( $post->post_content ) ) {
			return;
		}
		if ( has_shortcode( $post->post_content, 'doughboss_growth_waitlist' ) || has_shortcode( $post->post_content, 'doughboss_growth_coming_soon' ) ) {
			self::register_assets();
			wp_enqueue_style( self::HANDLE_STYLE );
		}
	}

	/**
	 * The stylesheet link to print right here, once, when the head is already past (a style enqueued while a shortcode
	 * renders would otherwise print in the footer, after the markup it styles: an unstyled flash and a layout shift).
	 * Returns the link markup (to put before the form) or an empty string when the head still has it to print, or when it
	 * was printed already, so two forms on one page print one link.
	 *
	 * @return string
	 */
	public static function early_style() {
		if ( ! did_action( 'wp_head' ) || wp_style_is( self::HANDLE_STYLE, 'done' ) ) {
			return '';
		}
		ob_start();
		wp_print_styles( self::HANDLE_STYLE );
		return (string) ob_get_clean();
	}

	/**
	 * Browser configuration for dbgr-waitlist.js. No secret and no personal data.
	 *
	 * @return array
	 */
	public static function browser_config() {
		return array(
			'tokenUrl'  => rest_url( DoughBoss_Growth::REST_NAMESPACE . '/form-token' ),
			'signupUrl' => rest_url( DoughBoss_Growth::REST_NAMESPACE . '/waitlist' ),
			'minAge'    => self::MIN_FORM_AGE,
			'strings'   => array(
				'consent'  => __( 'Please tick the box to join the list.', 'doughboss-growth' ),
				'email'    => __( 'Please enter a valid email address.', 'doughboss-growth' ),
				'wait'     => __( 'One moment', 'doughboss-growth' ),
				'network'  => __( 'We could not reach the server. Please try again.', 'doughboss-growth' ),
				'generic'  => __( 'Something went wrong. Please try again later.', 'doughboss-growth' ),
				'sending'  => __( 'Sending', 'doughboss-growth' ),
				'noscript' => __( 'This form needs JavaScript to be switched on.', 'doughboss-growth' ),
			),
		);
	}

	/**
	 * Shortcode [doughboss_growth_waitlist store=""]. Returns an empty string unless the waitlist is on and complete.
	 *
	 * @param mixed $atts Shortcode attributes: store (core shop id or slug to pre-select).
	 * @return string
	 */
	public static function shortcode( $atts = array() ) {
		return self::render_form( is_array( $atts ) ? $atts : array() );
	}

	/**
	 * Build the form markup. The consent box is unticked and required; nothing here names a product.
	 *
	 * @param array $atts Attributes (store).
	 * @return string
	 */
	public static function render_form( array $atts = array() ) {
		if ( ! self::enabled() ) {
			return '';
		}
		$consent = self::consent_text();
		if ( '' === $consent ) {
			return '';
		}
		static $instance = 0;
		$instance++;
		$uid = 'dbgr-wl-' . $instance;

		$wanted = isset( $atts['store'] ) ? trim( (string) $atts['store'] ) : '';
		$stores = self::stores();

		self::enqueue_assets( true );

		$h  = self::early_style();
		$h .= '<form class="dbgr-wl" id="' . esc_attr( $uid ) . '" data-dbgr-waitlist method="post" action="#" novalidate aria-labelledby="' . esc_attr( $uid ) . '-legend">';
		$h .= '<div data-dbgr-wl-fields>';
		$h .= '<p class="dbgr-wl__legend" id="' . esc_attr( $uid ) . '-legend">' . esc_html__( 'Your details', 'doughboss-growth' ) . '</p>';

		$h .= '<div class="dbgr-wl__field"><label for="' . esc_attr( $uid ) . '-email">' . esc_html__( 'Email', 'doughboss-growth' ) . ' <span class="dbgr-wl__req">' . esc_html__( '(required)', 'doughboss-growth' ) . '</span></label>';
		$h .= '<input type="email" id="' . esc_attr( $uid ) . '-email" name="email" autocomplete="email" inputmode="email" maxlength="' . esc_attr( (string) self::MAX_EMAIL ) . '" required /></div>';

		$h .= '<div class="dbgr-wl__field"><label for="' . esc_attr( $uid ) . '-name">' . esc_html__( 'First name', 'doughboss-growth' ) . ' <span class="dbgr-wl__opt">' . esc_html__( '(optional)', 'doughboss-growth' ) . '</span></label>';
		$h .= '<input type="text" id="' . esc_attr( $uid ) . '-name" name="first_name" autocomplete="given-name" maxlength="' . esc_attr( (string) self::MAX_NAME ) . '" /></div>';

		$h .= '<div class="dbgr-wl__field"><label for="' . esc_attr( $uid ) . '-mobile">' . esc_html__( 'Mobile', 'doughboss-growth' ) . ' <span class="dbgr-wl__opt">' . esc_html__( '(optional)', 'doughboss-growth' ) . '</span></label>';
		$h .= '<input type="tel" id="' . esc_attr( $uid ) . '-mobile" name="mobile" autocomplete="tel" inputmode="tel" maxlength="20" /></div>';

		if ( array() !== $stores ) {
			$h .= '<div class="dbgr-wl__field"><label for="' . esc_attr( $uid ) . '-store">' . esc_html__( 'Favourite shop', 'doughboss-growth' ) . ' <span class="dbgr-wl__opt">' . esc_html__( '(optional)', 'doughboss-growth' ) . '</span></label>';
			$h .= '<select id="' . esc_attr( $uid ) . '-store" name="store"><option value="" data-slug="none">' . esc_html__( 'No preference', 'doughboss-growth' ) . '</option>';
			foreach ( $stores as $id => $store ) {
				$selected = ( '' !== $wanted && ( (string) $id === $wanted || $store['slug'] === $wanted ) ) ? ' selected="selected"' : '';
				$h       .= '<option value="' . esc_attr( (string) $id ) . '" data-slug="' . esc_attr( '' !== $store['slug'] ? $store['slug'] : 'none' ) . '"' . $selected . '>' . esc_html( $store['name'] ) . '</option>';
			}
			$h .= '</select></div>';
		}

		// Honeypot: real visitors never see or reach it. A filled value is answered with a fake success.
		$h .= '<div class="dbgr-wl__hp" aria-hidden="true"><label for="' . esc_attr( $uid ) . '-website">' . esc_html__( 'Leave this field empty', 'doughboss-growth' ) . '</label>';
		$h .= '<input type="text" id="' . esc_attr( $uid ) . '-website" name="website" tabindex="-1" autocomplete="off" value="" /></div>';

		// The consent box is separate, unticked and required.
		$h .= '<div class="dbgr-wl__consent"><label for="' . esc_attr( $uid ) . '-consent"><input type="checkbox" id="' . esc_attr( $uid ) . '-consent" name="consent" value="1" required /> <span>' . esc_html( $consent ) . '</span></label></div>';
		$h .= '<input type="hidden" name="consent_version" value="' . esc_attr( self::consent_version() ) . '" />';
		$h .= '<input type="hidden" name="token" value="" />';

		$h .= '<div class="dbgr-wl__privacy"><a href="' . esc_url( self::privacy_url() ) . '">' . esc_html__( 'Privacy policy', 'doughboss-growth' ) . '</a></div>';
		$h .= '<div class="dbgr-wl__actions"><button type="submit" class="dbgr-wl__button">' . esc_html__( 'Join the VIP list', 'doughboss-growth' ) . '</button></div>';
		$h .= '</div>';
		$h .= '<p class="dbgr-wl__status" data-dbgr-wl-status role="status" aria-live="polite"></p>';
		$h .= '<noscript><p class="dbgr-wl__noscript">' . esc_html__( 'This form needs JavaScript to be switched on.', 'doughboss-growth' ) . '</p></noscript>';
		$h .= '</form>';
		return $h;
	}

	/* ------------------------------------------------------------------------------------------ */
	/* The email-link pages (confirm and opt-out), POST only to change anything                     */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * template_redirect: answer the confirmation and opt-out links. A GET only shows a button (so a mail scanner
	 * that follows the link changes nothing); the button POSTs, and so does a mail client's one-click opt-out.
	 *
	 * @return void
	 */
	public static function maybe_handle_link() {
		if ( is_admin() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- the link token is the credential; nothing changes without a POST.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$source = ( 'POST' === $method && isset( $_POST[ self::QUERY_VAR ] ) ) ? $_POST : $_GET; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is validated below.
		if ( ! isset( $source[ self::QUERY_VAR ] ) || ! is_string( $source[ self::QUERY_VAR ] ) ) {
			return;
		}
		$action = wp_unslash( $source[ self::QUERY_VAR ] );
		if ( 'confirm' !== $action && 'unsubscribe' !== $action ) {
			return;
		}
		$id    = isset( $source['i'] ) ? wp_unslash( $source['i'] ) : '';
		$token = isset( $source['t'] ) ? wp_unslash( $source['t'] ) : '';
		// phpcs:enable

		$sender = self::sender_name();
		$who    = ( '' !== $sender ) ? $sender : __( 'us', 'doughboss-growth' );
		$title  = ( 'confirm' === $action ) ? __( 'Confirm your VIP list sign-up', 'doughboss-growth' ) : __( 'Unsubscribe', 'doughboss-growth' );

		if ( 'confirm' === $action && ! self::enabled() ) {
			self::send_page( 503, $title, __( 'Sign-ups are not available right now.', 'doughboss-growth' ), '' );
			return;
		}
		if ( 'unsubscribe' === $action && ! DoughBoss_Growth_Activator::storage_ready() ) {
			self::send_page( 503, $title, __( 'We could not process this just now. Please try again later.', 'doughboss-growth' ), '' );
			return;
		}
		if ( ! is_string( $id ) || 1 !== preg_match( '/^[0-9]{1,12}$/D', $id ) || ! is_string( $token ) || 1 !== preg_match( '/^[a-f0-9]{32,40}$/D', $token ) ) {
			self::send_page( 400, $title, __( 'This link is not valid or has already been used.', 'doughboss-growth' ), '' );
			return;
		}

		if ( 'POST' !== $method ) {
			$intro = ( 'confirm' === $action )
				/* translators: %s: the sender's legal name. */
				? sprintf( __( 'Press the button to finish joining the VIP list from %s.', 'doughboss-growth' ), $who )
				/* translators: %s: the sender's legal name. */
				: sprintf( __( 'Press the button to stop receiving messages from %s.', 'doughboss-growth' ), $who );
			$label = ( 'confirm' === $action ) ? __( 'Confirm', 'doughboss-growth' ) : __( 'Unsubscribe', 'doughboss-growth' );
			$form  = '<form method="post" action="' . esc_url( add_query_arg( array( self::QUERY_VAR => $action, 'i' => $id, 't' => $token ), home_url( '/' ) ) ) . '">'
				. '<input type="hidden" name="' . esc_attr( self::QUERY_VAR ) . '" value="' . esc_attr( $action ) . '" />'
				. '<input type="hidden" name="i" value="' . esc_attr( $id ) . '" />'
				. '<input type="hidden" name="t" value="' . esc_attr( $token ) . '" />'
				. '<p><button type="submit" class="dbgr-wl__button">' . esc_html( $label ) . '</button></p></form>';
			self::send_page( 200, $title, $intro, $form );
			return;
		}

		if ( 'confirm' === $action ) {
			$limit = self::limit_action();
			if ( ! $limit['allowed'] ) {
				if ( 'limited' !== $limit['reason'] ) {
					self::note_failure(
						self::FAIL_LIMITER,
						array(
							'route'  => 'confirm',
							'reason' => isset( $limit['reason'] ) ? $limit['reason'] : 'unknown',
						)
					);
				}
				self::send_page( 'limited' === $limit['reason'] ? 429 : 503, $title, __( 'Too many attempts. Please try again later.', 'doughboss-growth' ), '' );
				return;
			}
			$result = self::confirm( $id, $token );
		} else {
			// Leaving the list is never held back by the per-address limit: only an invalid attempt counts against it.
			$result = self::unsubscribe_attempt( $id, $token );
		}
		switch ( $result['result'] ) {
			case 'confirmed':
				self::send_page( 200, $title, __( 'You are on the VIP list. Thank you.', 'doughboss-growth' ), '' );
				break;
			case 'unsubscribed':
				self::send_page( 200, $title, __( 'You have been unsubscribed. You will not receive any more messages from us.', 'doughboss-growth' ), '' );
				break;
			case 'invalid':
				self::send_page( 400, $title, __( 'This link is not valid or has already been used.', 'doughboss-growth' ), '' );
				break;
			case 'limited':
				self::send_page( 429, $title, __( 'Too many attempts. Please try again later.', 'doughboss-growth' ), '' );
				break;
			default:
				self::send_page( 503, $title, __( 'We could not process this just now. Please try again later.', 'doughboss-growth' ), '' );
		}
	}

	/**
	 * Print a small self-contained page and stop. It is never cached or indexed and sends no referrer (the link
	 * carries a secret).
	 *
	 * @param int    $status HTTP status.
	 * @param string $title  Page title and heading.
	 * @param string $text   Plain-text message.
	 * @param string $form   Trusted HTML for a form (already escaped by the caller), or an empty string.
	 * @return void
	 */
	private static function send_page( $status, $title, $text, $form ) {
		status_header( (int) $status );
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=UTF-8' );
			header( 'Referrer-Policy: no-referrer' );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
		$privacy = self::privacy_url();
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" />';
		echo '<meta name="robots" content="noindex, nofollow" /><meta name="referrer" content="no-referrer" />';
		echo '<title>' . esc_html( $title ) . '</title>';
		echo '<link rel="stylesheet" href="' . esc_url( DOUGHBOSS_GROWTH_URL . 'public/css/dbgr-coming-soon.css' ) . '" /></head>';
		echo '<body class="dbgr-wl-page"><main class="dbgr-wl-page__main"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $text ) . '</p>';
		echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built.
		if ( '' !== $privacy ) {
			echo '<p class="dbgr-wl__privacy"><a href="' . esc_url( $privacy ) . '">' . esc_html__( 'Privacy policy', 'doughboss-growth' ) . '</a></p>';
		}
		echo '<p class="dbgr-wl__privacy"><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Back to the website', 'doughboss-growth' ) . '</a></p>';
		echo '</main></body></html>';
		self::terminate();
	}

	/**
	 * Stop the request. Inside the test harness this returns instead, so a test can read the output.
	 *
	 * @return void
	 */
	public static function terminate() {
		if ( defined( 'DBGR_TESTING' ) ) {
			return;
		}
		exit;
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Staff export                                                                                 */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Neutralise a CSV cell so a spreadsheet cannot run it as a formula.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$text = ( null === $value ) ? '' : (string) $value;
		if ( '' !== $text && 1 === preg_match( '/^[=+\-@\t\r]/', $text ) ) {
			return "'" . $text;
		}
		return $text;
	}

	/**
	 * The staff CSV header row (the column set; no token, no hash).
	 *
	 * @return array
	 */
	private static function csv_header() {
		return array( 'id', 'email', 'first_name', 'mobile', 'store_id', 'status', 'consent_marketing', 'consent_text_version', 'consent_at_utc', 'confirmed_at_utc', 'unsubscribed_at_utc', 'signup_path', 'created_at' );
	}

	/**
	 * One staff CSV data row. Every cell is neutralised so a spreadsheet cannot run it as a formula.
	 *
	 * @param array $r Row from the waitlist table.
	 * @return array
	 */
	private static function csv_row( array $r ) {
		return array_map(
			array( __CLASS__, 'csv_cell' ),
			array(
				$r['id'],
				$r['email'],
				$r['first_name'],
				$r['mobile_e164'],
				$r['store_pref'],
				$r['status'],
				$r['consent_marketing'],
				$r['consent_text_version'],
				$r['consent_at_utc'],
				$r['confirmed_at_utc'],
				$r['unsubscribed_at_utc'],
				$r['consent_source_path'],
				$r['created_at'],
			)
		);
	}

	/**
	 * One page of the staff export: the next EXPORT_PAGE_SIZE rows after an id, oldest first.
	 *
	 * @param string $status   confirmed, pending, unsubscribed or all.
	 * @param int    $after_id Last id already read (0 for the first page).
	 * @return array|null Rows (empty when there are no more), or null on a database error.
	 */
	private static function export_page( $status, $after_id ) {
		global $wpdb;
		$table = self::table();
		if ( 'all' === $status ) {
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", (int) $after_id, self::EXPORT_PAGE_SIZE ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d AND status = %s ORDER BY id ASC LIMIT %d", (int) $after_id, $status, self::EXPORT_PAGE_SIZE ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return null;
		}
		return $rows;
	}

	/**
	 * Write the staff CSV to a stream, one page at a time (EXPORT_PAGE_SIZE rows), so neither memory nor the time to the
	 * first byte grows with the list. The header and the column set are the same as ever; every cell goes through
	 * csv_cell() (formula protection).
	 *
	 * If a page cannot be read part way, writing stops and the file ends with an ERROR line saying it is incomplete, and
	 * the failure is noted for the owner: a short file must never look like a complete one. (A list that is genuinely
	 * empty is a header-only file with no error line.)
	 *
	 * @param resource   $handle     Writable stream.
	 * @param string     $status     confirmed, pending, unsubscribed or all.
	 * @param array|null $first_page Rows of the first page when the caller has already read it (so that a failure there can
	 *                               be reported before any byte of the file has been sent), or null to read it here.
	 * @return bool True when the whole list was written; false when a page could not be read (the file is incomplete).
	 */
	public static function write_csv( $handle, $status, $first_page = null ) {
		fputcsv( $handle, self::csv_header() );
		$after = 0;
		$rows  = is_array( $first_page ) ? $first_page : self::export_page( $status, $after );
		while ( true ) {
			if ( null === $rows ) {
				fputcsv( $handle, array( 'ERROR', __( 'The export stopped part way because the list could not be read. This file is incomplete. Please do not use it; download it again.', 'doughboss-growth' ) ) );
				self::note_failure( self::FAIL_CSV, array( 'stage' => 'page_read' ) );
				return false;
			}
			if ( array() === $rows ) {
				return true;
			}
			$count     = count( $rows );
			$page_last = isset( $rows[ $count - 1 ]['id'] ) ? (int) $rows[ $count - 1 ]['id'] : 0;
			if ( $page_last <= $after ) {
				$rows = null; // A page that does not move forward would loop for ever: treat it as unreadable.
				continue;
			}
			foreach ( $rows as $r ) {
				fputcsv( $handle, self::csv_row( $r ) );
			}
			if ( $count < self::EXPORT_PAGE_SIZE ) {
				return true; // A short page is the last page.
			}
			$after = $page_last;
			$rows  = self::export_page( $status, $after );
		}
	}

	/**
	 * Build the staff CSV as a string (the same bytes the download streams). No token, no hash. The download itself
	 * does not use this (it would hold the whole list in memory); it is for callers that need the text.
	 *
	 * @param string $status confirmed, pending, unsubscribed or all.
	 * @return string|null The CSV, or null on a database error (never a partial file).
	 */
	public static function build_csv( $status ) {
		if ( ! in_array( $status, self::EXPORT_STATUSES, true ) || ! DoughBoss_Growth_Activator::storage_ready() ) {
			return null;
		}
		$first = self::export_page( $status, 0 );
		if ( null === $first ) {
			return null;
		}
		$handle = fopen( 'php://temp', 'r+' );
		if ( false === $handle ) {
			return null;
		}
		$complete = self::write_csv( $handle, $status, $first );
		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );
		return ( $complete && is_string( $csv ) ) ? $csv : null;
	}

	/**
	 * admin-post handler for the CSV export. Capability AND nonce first. The file is streamed page by page; the first
	 * page is read before anything is sent, so a list that cannot be read at all is still the plain error page (nothing
	 * downloaded), and a failure after that ends the file with an ERROR line (see write_csv()).
	 *
	 * @return void
	 */
	public static function handle_export() {
		if ( ! DoughBoss_Growth_Admin::user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to export this list.', 'doughboss-growth' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::EXPORT_ACTION );
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'confirmed';
		if ( ! in_array( $status, self::EXPORT_STATUSES, true ) ) {
			$status = 'confirmed';
		}
		$first = null;
		if ( DoughBoss_Growth_Activator::storage_ready() ) {
			$first = self::export_page( $status, 0 );
			if ( null === $first ) {
				self::note_failure( self::FAIL_CSV, array( 'stage' => 'first_page' ) );
			}
		} else {
			self::note_failure( self::FAIL_CSV, array( 'stage' => 'storage_not_ready' ) );
		}
		$out = ( null === $first ) ? false : fopen( 'php://output', 'w' );
		if ( null === $first || false === $out ) {
			wp_die( esc_html__( 'The export could not be built. Nothing was downloaded.', 'doughboss-growth' ), '', array( 'response' => 500 ) );
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/csv; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="vip-list-' . $status . '.csv"' );
			header( 'X-Content-Type-Options: nosniff' );
		}
		self::write_csv( $out, $status, $first ); // CSV download: cells are formula-neutralised in csv_row().
		fclose( $out );
		self::terminate();
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Admin tab                                                                                    */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Add the "VIP waitlist" tab.
	 *
	 * @return void
	 */
	public static function register_tab() {
		if ( class_exists( 'DoughBoss_Growth_Admin' ) ) {
			DoughBoss_Growth_Admin::add_tab( 'waitlist', __( 'VIP waitlist', 'doughboss-growth' ), array( __CLASS__, 'render_tab' ) );
		}
	}

	/**
	 * Owner decisions and gaps for this module (admin-only text).
	 *
	 * @return array Code => text beginning "[CONFIRM: ...".
	 */
	public static function confirm_gaps() {
		$gaps     = array();
		$settings = DoughBoss_Growth_Settings::get_all();
		if ( '' === self::sender_name() ) {
			$gaps['sender_legal_name'] = '[CONFIRM: sender legal name (and ABN if shown). Required before the waitlist can be enabled. No company name is built into the plugin.]';
		}
		if ( '' === self::privacy_url() ) {
			$gaps['privacy_policy_url'] = '[CONFIRM: privacy-policy URL. Required before the waitlist can be enabled.]';
		}
		if ( null === $settings['retention_confirmed_months'] ) {
			$gaps['retention_confirmed_months'] = '[CONFIRM: how long confirmed sign-ups are kept. Until it is set, confirmed rows are never deleted automatically.]';
		}
		$gaps['sender_contact']    = '[CONFIRM: sender contact details (a postal address or phone) for the email footer. The Spam Act needs accurate sender identification; only the legal name is printed today.]';
		$gaps['consent_wording']   = '[CONFIRM: Elie (and a solicitor) to review the consent wording. It covers email, and text message if a mobile number is given. This plugin sends no text messages; any later SMS needs its own working opt-out.]';
		$gaps['suppression']       = '[CONFIRM: whether an erased person may stay on the opt-out list as a hash. Today a person who opted out stays suppressed after erasure, and an erased person who never opted out is not suppressed. A filter can change the second case.]';
		$gaps['client_ip']         = '[CONFIRM: whether the host puts every visitor behind one proxy address. If it does, the 5-an-hour per-address limit is shared by everyone and a real visitor address must be supplied through the doughboss_growth_client_ip filter.]';
		$gaps['resubscribe']       = '[CONFIRM: what happens when a person who opted out asks to join again. Today the form answers as normal but stores and sends nothing; they must contact the business.]';
		return $gaps;
	}

	/**
	 * A recorded failure in plain words for the owner: what happened, the code, the stage, when and how often. No personal
	 * data is ever in a record, so none can be shown.
	 *
	 * @param array $record array( code, count, first_seen, last_seen, context ) from DoughBoss_Growth_Failures::all().
	 * @return string
	 */
	private static function describe_failure( array $record ) {
		$words = array(
			self::FAIL_SIGNUP_MAIL    => __( 'The confirmation email could not be sent.', 'doughboss-growth' ),
			self::FAIL_SIGNUP_STORAGE => __( 'The sign-up could not be saved or checked because the database did not answer.', 'doughboss-growth' ),
			self::FAIL_SIGNUP_TOKEN   => __( 'A secure confirmation link could not be made.', 'doughboss-growth' ),
		);
		$what  = isset( $words[ $record['code'] ] ) ? $words[ $record['code'] ] : __( 'A problem was recorded.', 'doughboss-growth' );
		$when  = ( $record['last_seen'] > 0 ) ? wp_date( 'j M Y, g:i a', $record['last_seen'] ) : __( 'an unknown time', 'doughboss-growth' );
		$stage = ( isset( $record['context']['stage'] ) && '' !== $record['context']['stage'] ) ? ', ' . $record['context']['stage'] : '';
		/* translators: 1: what happened, 2: failure code and stage, 3: date and time last seen, 4: how many times. */
		return sprintf( __( '%1$s (%2$s. Last seen %3$s, %4$s.)', 'doughboss-growth' ), $what, $record['code'] . $stage, $when, sprintf( _n( '%d time', '%d times', (int) $record['count'], 'doughboss-growth' ), (int) $record['count'] ) );
	}

	/**
	 * Row counts by status.
	 *
	 * @return array|null Status => count, or null on a database error.
	 */
	public static function counts() {
		global $wpdb;
		$table  = self::table();
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$table} WHERE id > %d GROUP BY status", 0 ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return null;
		}
		$counts = array(
			self::STATUS_PENDING      => 0,
			self::STATUS_CONFIRMED    => 0,
			self::STATUS_UNSUBSCRIBED => 0,
		);
		foreach ( $rows as $r ) {
			if ( isset( $r['status'], $counts[ $r['status'] ] ) ) {
				$counts[ $r['status'] ] = (int) $r['n'];
			}
		}
		return $counts;
	}

	/**
	 * Render the tab body.
	 *
	 * @return void
	 */
	public static function render_tab() {
		echo '<h2>' . esc_html__( 'VIP waitlist', 'doughboss-growth' ) . '</h2>';
		$rows   = array();
		$rows[] = array( __( 'Waitlist feature', 'doughboss-growth' ), DoughBoss_Growth_Settings::enabled( 'waitlist' ) ? __( 'On', 'doughboss-growth' ) : __( 'Off', 'doughboss-growth' ) );
		$rows[] = array( __( 'Sign-ups open', 'doughboss-growth' ), self::enabled() ? __( 'Yes', 'doughboss-growth' ) : __( 'No (needs the feature, sender legal name, privacy-policy URL and database tables)', 'doughboss-growth' ) );
		$rows[] = array( __( 'Sender legal name', 'doughboss-growth' ), '' !== self::sender_name() ? self::sender_name() : __( 'Not set', 'doughboss-growth' ) );
		$rows[] = array( __( 'Privacy-policy URL', 'doughboss-growth' ), '' !== self::privacy_url() ? self::privacy_url() : __( 'Not set', 'doughboss-growth' ) );
		$months = DoughBoss_Growth_Settings::get( 'retention_confirmed_months', null );
		$rows[] = array( __( 'Confirmed rows kept for', 'doughboss-growth' ), is_int( $months ) ? sprintf( /* translators: %d: months. */ _n( '%d month', '%d months', $months, 'doughboss-growth' ), $months ) : __( 'Not set: never deleted automatically', 'doughboss-growth' ) );
		$rows[] = array( __( 'Notification webhook', 'doughboss-growth' ), ( '' !== (string) DoughBoss_Growth_Settings::get( 'notify_webhook_url', '' ) && DoughBoss_Growth_Settings::has_secret( 'DOUGHBOSS_GROWTH_WEBHOOK_SECRET' ) ) ? __( 'Configured', 'doughboss-growth' ) : __( 'Off', 'doughboss-growth' ) );
		$ready  = DoughBoss_Growth_Activator::storage_ready();
		$counts = $ready ? self::counts() : null;
		if ( null !== $counts ) {
			$rows[] = array( __( 'Confirmed', 'doughboss-growth' ), (string) $counts[ self::STATUS_CONFIRMED ] );
			$rows[] = array( __( 'Waiting to confirm', 'doughboss-growth' ), (string) $counts[ self::STATUS_PENDING ] );
			$rows[] = array( __( 'Opted out (details kept 30 days)', 'doughboss-growth' ), (string) $counts[ self::STATUS_UNSUBSCRIBED ] );
		} elseif ( $ready ) {
			// A failed read is not "no sign-ups": say so rather than leave the counts out.
			$rows[] = array( __( 'Sign-up counts', 'doughboss-growth' ), __( 'The counts could not be read just now. Please reload this page.', 'doughboss-growth' ) );
		}
		$signup_failure = self::latest_failure( 'waitlist_signup_' );
		$rows[]         = array( __( 'Last sign-up failure', 'doughboss-growth' ), ( null === $signup_failure ) ? __( 'None recorded', 'doughboss-growth' ) : self::describe_failure( $signup_failure ) );

		$purge_failure = self::latest_failure( self::FAIL_PURGE );
		if ( null !== $purge_failure ) {
			$stage = isset( $purge_failure['context']['stage'] ) ? $purge_failure['context']['stage'] : '';
			echo '<div class="notice notice-error inline"><p>' . esc_html(
				sprintf(
					/* translators: 1: failure code, 2: the clean-up steps that failed (may be empty). */
					__( 'The last clean-up failed: %1$s%2$s. Some old sign-ups may not have been removed yet. It tries again at the next daily clean-up.', 'doughboss-growth' ),
					$purge_failure['code'],
					( '' !== $stage ) ? ' (' . $stage . ')' : ''
				)
			) . '</p></div>';
		}
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><th scope="row">' . esc_html( $r[0] ) . '</th><td>' . esc_html( $r[1] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$gaps = self::confirm_gaps();
		echo '<h3>' . esc_html__( 'Owner decisions still outstanding', 'doughboss-growth' ) . '</h3><ul class="ul-disc">';
		foreach ( $gaps as $text ) {
			echo '<li>' . esc_html( DoughBoss_Growth_Admin::plain_gap( $text ) ) . '</li>';
		}
		echo '</ul>';

		echo '<h3>' . esc_html__( 'Export', 'doughboss-growth' ) . '</h3>';
		echo '<p>' . esc_html__( 'Only confirmed sign-ups may be sent marketing. Export the others only to deal with a request.', 'doughboss-growth' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::EXPORT_ACTION ) . '" />';
		wp_nonce_field( self::EXPORT_ACTION );
		echo '<p><label for="dbgr-wl-export-status">' . esc_html__( 'Which sign-ups', 'doughboss-growth' ) . '</label> <select id="dbgr-wl-export-status" name="status">';
		foreach ( self::EXPORT_STATUSES as $s ) {
			echo '<option value="' . esc_attr( $s ) . '">' . esc_html( $s ) . '</option>';
		}
		echo '</select></p>';
		submit_button( __( 'Download CSV', 'doughboss-growth' ), 'secondary' );
		echo '</form>';
	}
}
