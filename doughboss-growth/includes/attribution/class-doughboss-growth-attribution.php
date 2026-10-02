<?php
/**
 * DoughBoss Growth attribution: consented, sanitised source data kept in side records.
 *
 * Feature flag: attribution (the lead_form flag also keeps the lead-record hook alive, see init()).
 * Everything is inert while the flags are off, and fails closed: any error, missing setting or storage problem
 * leaves the visitor, the enquiry and the order exactly as core handles them.
 *
 * What this module does when the flag is on:
 *  - enqueues public/js/dbgr-attribution.js, which reads the landing URL (UTM parameters, click ids, referrer
 *    HOST, landing PATH) and, only with the visitor's consent, keeps it in the first-party cookie dbgr_attr
 *    (90 days, 1.5 KB at most). UTM, referrer host and landing path need measurement consent; click ids need
 *    advertising consent. Without consent nothing is written;
 *  - on core's doughboss_catering_enquiry_created action writes ONE side row per enquiry in
 *    doughboss_growth_lead_meta (and, when there is something to keep, an "enquiry" row in
 *    doughboss_growth_attribution). If core rejected the enquiry the action never fires, so nothing is stored;
 *  - on a successful POST /doughboss/v1/payment-intent stores the cookie's attribution keyed by the returned
 *    payment reference ("payment_ref"); on doughboss_order_created the order inherits it by payment_intent_id,
 *    falling back to the cookie ("order");
 *  - exposes for_subject() for the lead form and the conversions module, and sanitise() which is a port of
 *    sanitiseAttribution() in web/src/lib/attribution-schema.ts (tests/fixtures/attribution-cases.json is the proof).
 *
 * The companion never writes a core table, option or hook result: core stays the only writer of enquiries and
 * orders. Every hook callback catches everything, because an exception here would otherwise reach core's
 * checkout or enquiry code.
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
 * Attribution capture, side records and sanitising.
 */
final class DoughBoss_Growth_Attribution {

	/**
	 * Name of the attribution cookie (written by dbgr-attribution.js).
	 */
	const COOKIE_NAME = 'dbgr_attr';

	/**
	 * Largest cookie value read, in characters (the browser keeps its URL-encoded value within the same budget).
	 */
	const COOKIE_MAX_LENGTH = 1500;

	/**
	 * Script handle.
	 */
	const HANDLE = 'dbgr-attribution';

	/**
	 * Table name suffixes (the WordPress prefix is added at run time).
	 */
	const TABLE_ATTRIBUTION = 'doughboss_growth_attribution';
	const TABLE_LEAD_META   = 'doughboss_growth_lead_meta';

	/**
	 * Allowed subject types for the attribution table.
	 */
	const SUBJECT_TYPES = array( 'payment_ref', 'order', 'enquiry', 'waitlist' );

	/**
	 * Length caps, in Unicode code points exactly like the TypeScript schema (zod 4 counts code points, so an emoji is one).
	 */
	const PARAM_MAX = 120;
	const HOST_MAX  = 253;
	const PATH_MAX  = 200;

	/**
	 * The fields that need measurement consent (UTM, referrer host, landing path, first-seen time) and the
	 * click ids that need advertising consent. Keys are the camelCase names of attribution-schema.ts.
	 */
	const MEASUREMENT_FIELDS = array( 'utmSource', 'utmMedium', 'utmCampaign', 'utmTerm', 'utmContent', 'referrerHost', 'landingPath', 'firstSeenAt' );
	const ADVERTISING_FIELDS = array( 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid' );

	/**
	 * Shape order of attribution-schema.ts (the order sanitise() returns keys in).
	 */
	const FIELDS = array(
		'utmSource',
		'utmMedium',
		'utmCampaign',
		'utmTerm',
		'utmContent',
		'gclid',
		'gbraid',
		'wbraid',
		'fbclid',
		'msclkid',
		'referrerHost',
		'landingPath',
		'firstSeenAt',
	);

	/**
	 * The route suffixes this module watches (under the core REST namespace).
	 */
	const ROUTE_ENQUIRY        = '/catering/enquiry';
	const ROUTE_PAYMENT_INTENT = '/payment-intent';

	/**
	 * Largest sizes of the values taken from the lead form (they match the lead_meta columns).
	 */
	const COMPANY_MAX      = 120;
	const SEGMENT_MAX      = 40;
	const LANDING_KEY_MAX  = 64;
	const TEXT_VERSION_MAX = 20;

	/**
	 * dbgr_* request parameters stashed for the enquiry that core is about to create.
	 *
	 * @var array|null
	 */
	private static $stash = null;

	/* ------------------------------------------------------------------------------------------ */
	/* Wiring                                                                                       */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Hook everything. Re-checks the flags itself.
	 *
	 * The lead-record hook is also wanted while only lead_form is on (the corporate form sends its company,
	 * segment and marketing consent to be recorded here); the capture, the payment and order hooks and the
	 * script need attribution itself.
	 *
	 * @return void
	 */
	public static function init() {
		$attribution = DoughBoss_Growth_Settings::enabled( 'attribution' );
		$lead_form   = DoughBoss_Growth_Settings::enabled( 'lead_form' );
		if ( ! $attribution && ! $lead_form ) {
			return;
		}
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'stash_enquiry_params' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'on_rest_post_dispatch' ), 10, 3 );
		add_action( 'doughboss_catering_enquiry_created', array( __CLASS__, 'on_enquiry_created' ), 20, 2 );
		if ( $attribution ) {
			add_action( 'doughboss_order_created', array( __CLASS__, 'on_order_created' ), 20, 2 );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
			add_action( 'doughboss_growth_admin_tabs', array( __CLASS__, 'register_tab' ) );
		}
	}

	/**
	 * Forget per-request state (tests only).
	 *
	 * @return void
	 */
	public static function reset_state() {
		self::$stash = null;
	}

	/**
	 * Enqueue the capture script. It depends on the consent script, which only exists while the consent banner
	 * is on; without the banner there is nobody to ask, so nothing is enqueued (fail closed).
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! DoughBoss_Growth_Settings::enabled( 'attribution' ) || ! DoughBoss_Growth_Settings::enabled( 'consent_banner' ) ) {
			return;
		}
		if ( ! class_exists( 'DoughBoss_Growth_Consent', false ) && ! DoughBoss_Growth::load_module( 'consent' ) ) {
			return; // No consent module, no script (fail closed).
		}
		wp_enqueue_script( self::HANDLE, DOUGHBOSS_GROWTH_URL . 'public/js/dbgr-attribution.js', array( DoughBoss_Growth_Consent::HANDLE_CONSENT ), DOUGHBOSS_GROWTH_VERSION, true );
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Storage                                                                                      */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Attribution table name.
	 *
	 * @return string
	 */
	public static function attribution_table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_ATTRIBUTION;
	}

	/**
	 * Lead meta table name.
	 *
	 * @return string
	 */
	public static function lead_meta_table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_LEAD_META;
	}

	/**
	 * CREATE TABLE statements for dbDelta (called by the activator whatever the flags say).
	 *
	 * @return array
	 */
	public static function schema() {
		global $wpdb;
		$attribution = self::attribution_table();
		$lead_meta   = self::lead_meta_table();
		$collate     = $wpdb->get_charset_collate();
		return array(
			"CREATE TABLE {$attribution} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  subject_type varchar(20) NOT NULL,
  subject_id varchar(100) NOT NULL,
  attribution_json text NOT NULL,
  consent_json text NOT NULL,
  captured_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY subject (subject_type,subject_id)
) {$collate};",
			"CREATE TABLE {$lead_meta} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  enquiry_id bigint(20) unsigned NOT NULL,
  segment varchar(40) NOT NULL DEFAULT '',
  company_name varchar(120) NOT NULL DEFAULT '',
  landing_key varchar(64) NOT NULL DEFAULT '',
  attribution_json text NOT NULL,
  consent_marketing tinyint(1) NOT NULL DEFAULT 0,
  consent_text_version varchar(20) NOT NULL DEFAULT '',
  consent_at_utc datetime DEFAULT NULL,
  lead_score int(11) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY enquiry (enquiry_id)
) {$collate};",
		);
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Sanitising: a port of web/src/lib/attribution-schema.ts                                      */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Salvage whatever valid fields exist in untrusted input and drop the rest. Never throws: a malformed
	 * attribution blob must not break an enquiry or an order. Same results as sanitiseAttribution() in
	 * attribution-schema.ts for every field, with ONE deliberate hardening: referrerHost must also be a plain
	 * host name (letters, digits, dots, hyphens), which the TypeScript schema does not check. The fixtures record
	 * both results for the cases where they differ.
	 *
	 * @param mixed $raw Untrusted input (usually a decoded cookie).
	 * @return array Field => value, in the order of attribution-schema.ts.
	 */
	public static function sanitise( $raw ) {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( self::FIELDS as $field ) {
			if ( ! array_key_exists( $field, $raw ) ) {
				continue;
			}
			$value = self::sanitise_field( $field, $raw[ $field ] );
			if ( null !== $value ) {
				$out[ $field ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Sanitise one field.
	 *
	 * @param string $field Field name from FIELDS.
	 * @param mixed  $value Untrusted value.
	 * @return string|null The clean value or null when it is not acceptable.
	 */
	private static function sanitise_field( $field, $value ) {
		if ( 'firstSeenAt' === $field ) {
			return self::clean_datetime( $value );
		}
		if ( 'referrerHost' === $field ) {
			$host = self::clean_text( $value, self::HOST_MAX, false );
			if ( null === $host || 1 !== preg_match( '/\A[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\z/', $host ) ) {
				return null;
			}
			return $host;
		}
		if ( 'landingPath' === $field ) {
			$path = self::clean_text( $value, self::PATH_MAX, false );
			if ( null === $path || 1 !== preg_match( '/\A\/[^?#' . self::ws_class() . ']*\z/u', $path ) ) {
				return null;
			}
			return $path;
		}
		return self::clean_text( $value, self::PARAM_MAX, true );
	}

	/**
	 * The characters JavaScript treats as white space (String.prototype.trim and the regex \s): the same set on both sides.
	 *
	 * @return string Regex character-class body for a pattern with the u modifier.
	 */
	private static function ws_class() {
		return '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';
	}

	/**
	 * Trim, require 1..$max code points, and (for free-text parameters) refuse control characters.
	 *
	 * @param mixed $value        Untrusted value.
	 * @param int   $max          Cap in Unicode code points.
	 * @param bool  $control_scan Whether control characters U+0000 to U+001F and U+007F are refused.
	 * @return string|null
	 */
	private static function clean_text( $value, $max, $control_scan ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( strlen( $value ) > ( $max * 4 ) + 64 ) {
			return null; // Far over any cap even after trimming: refuse early.
		}
		$trimmed = preg_replace( '/\A[' . self::ws_class() . ']+|[' . self::ws_class() . ']+\z/u', '', $value );
		if ( ! is_string( $trimmed ) || '' === $trimmed ) {
			return null; // Invalid UTF-8 (preg returns null) or nothing left.
		}
		if ( $control_scan && 1 === preg_match( '/[\x00-\x1f\x7f]/', $trimmed ) ) {
			return null;
		}
		if ( self::code_point_count( $trimmed ) > $max ) {
			return null;
		}
		return $trimmed;
	}

	/**
	 * Number of Unicode code points in a valid UTF-8 string (zod 4 measures string length this way).
	 *
	 * @param string $text Valid UTF-8.
	 * @return int
	 */
	private static function code_point_count( $text ) {
		return (int) preg_match_all( '/./su', $text );
	}

	/**
	 * Zod 4 z.iso.datetime(): date, "T", hh:mm:ss with optional fraction, then "Z". No offsets, no local time, no trimming.
	 *
	 * @param mixed $value Untrusted value.
	 * @return string|null
	 */
	private static function clean_datetime( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > 64 ) {
			return null;
		}
		$date = '(?:(?:\d\d[2468][048]|\d\d[13579][26]|\d\d0[48]|[02468][048]00|[13579][26]00)-02-29|\d{4}-(?:(?:0[13578]|1[02])-(?:0[1-9]|[12]\d|3[01])|(?:0[469]|11)-(?:0[1-9]|[12]\d|30)|(?:02)-(?:0[1-9]|1\d|2[0-8])))';
		$time = '(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?';
		return ( 1 === preg_match( '/\A' . $date . 'T' . $time . 'Z\z/', $value ) ) ? $value : null;
	}

	/**
	 * Remove the fields the visitor has not consented to.
	 *
	 * @param array $attribution Sanitised attribution.
	 * @param array $consent     Snapshot from consent_snapshot().
	 * @return array
	 */
	public static function filter_by_consent( array $attribution, array $consent ) {
		$out = array();
		foreach ( $attribution as $field => $value ) {
			if ( in_array( $field, self::MEASUREMENT_FIELDS, true ) && ! empty( $consent['measurement'] ) ) {
				$out[ $field ] = $value;
			} elseif ( in_array( $field, self::ADVERTISING_FIELDS, true ) && ! empty( $consent['advertising'] ) ) {
				$out[ $field ] = $value;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Reading the visitor                                                                          */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * The visitor's consent as the server sees it, as plain booleans. Closed when the consent module is not
	 * available, and for a signed-in manager (staff keying an order or enquiry; fail closed).
	 *
	 * @return array measurement (bool), advertising (bool), chosen (bool), version (string).
	 */
	public static function consent_snapshot() {
		$snapshot = array(
			'measurement' => false,
			'advertising' => false,
			'chosen'      => false,
			'version'     => '',
		);
		if ( class_exists( 'DoughBoss_Growth_Admin' ) && DoughBoss_Growth_Admin::user_can_manage() ) {
			return $snapshot; // A signed-in manager is staff at work, not a visitor: their browser's cookies are never attributed to an enquiry or order.
		}
		if ( ! class_exists( 'DoughBoss_Growth_Consent', false ) && ! DoughBoss_Growth::load_module( 'consent' ) ) {
			return $snapshot;
		}
		$current = DoughBoss_Growth_Consent::current();
		if ( ! is_array( $current ) ) {
			return $snapshot;
		}
		$snapshot['measurement'] = ( true === ( isset( $current['measurement'] ) ? $current['measurement'] : false ) );
		$snapshot['advertising'] = ( true === ( isset( $current['advertising'] ) ? $current['advertising'] : false ) );
		$snapshot['chosen']      = ( true === ( isset( $current['chosen'] ) ? $current['chosen'] : false ) );
		$snapshot['version']     = ( isset( $current['version'] ) && is_string( $current['version'] ) ) ? substr( $current['version'], 0, self::TEXT_VERSION_MAX ) : '';
		return $snapshot;
	}

	/**
	 * Decode and sanitise a raw dbgr_attr cookie value. Anything malformed gives an empty array.
	 *
	 * @param mixed $raw Raw cookie value (PHP has already URL-decoded it; WordPress may have slashed it).
	 * @return array
	 */
	public static function parse_cookie( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > self::COOKIE_MAX_LENGTH ) {
			return array();
		}
		$data = json_decode( wp_unslash( $raw ), true );
		return is_array( $data ) ? self::sanitise( $data ) : array();
	}

	/**
	 * What this visitor's cookie says, sanitised and cut down to what they have consented to. Empty without consent.
	 *
	 * @param array|null $consent Snapshot to apply (default: the current request's).
	 * @return array
	 */
	public static function current_attribution( $consent = null ) {
		if ( ! is_array( $consent ) ) {
			$consent = self::consent_snapshot();
		}
		if ( empty( $consent['measurement'] ) && empty( $consent['advertising'] ) ) {
			return array();
		}
		$raw = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? $_COOKIE[ self::COOKIE_NAME ] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and sanitised field by field in parse_cookie().
		return self::filter_by_consent( self::parse_cookie( $raw ), $consent );
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Side records                                                                                 */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Normalise a subject id for its type, or '' when it is not acceptable.
	 *
	 * @param string $type Subject type.
	 * @param mixed  $id   Raw id.
	 * @return string
	 */
	public static function normalise_subject_id( $type, $id ) {
		if ( ! is_string( $type ) || ! in_array( $type, self::SUBJECT_TYPES, true ) ) {
			return '';
		}
		if ( 'payment_ref' === $type ) {
			return ( is_string( $id ) && 1 === preg_match( '/\A[A-Za-z0-9_-]{1,100}\z/', $id ) ) ? $id : '';
		}
		if ( is_int( $id ) ) {
			return ( $id > 0 ) ? (string) $id : '';
		}
		return ( is_string( $id ) && 1 === preg_match( '/\A[1-9][0-9]{0,18}\z/', $id ) ) ? $id : '';
	}

	/**
	 * Store attribution and the consent it was captured under for one subject. The first write wins: a second
	 * write for the same subject changes nothing.
	 *
	 * @param string $type        Subject type.
	 * @param mixed  $id          Subject id.
	 * @param array  $attribution Attribution (re-sanitised and cut down to the consent given here).
	 * @param array  $consent     Consent snapshot.
	 * @return bool True when a row for the subject exists afterwards; false on invalid input or a storage error.
	 */
	public static function write_subject( $type, $id, array $attribution, array $consent ) {
		global $wpdb;
		$subject_id = self::normalise_subject_id( $type, $id );
		if ( '' === $subject_id ) {
			return false;
		}
		$attribution = self::filter_by_consent( self::sanitise( $attribution ), $consent );
		$attr_json    = self::encode( $attribution );
		$consent_json = self::encode( self::consent_for_storage( $consent ) );
		if ( '' === $attr_json || '' === $consent_json ) {
			return false;
		}
		$table  = self::attribution_table();
		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (subject_type, subject_id, attribution_json, consent_json, captured_at) VALUES (%s, %s, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from the WordPress prefix and a constant.
				$type,
				$subject_id,
				$attr_json,
				$consent_json,
				gmdate( 'Y-m-d H:i:s', DoughBoss_Growth::now() )
			)
		);
		if ( false === $result ) {
			self::log_event( 'attribution_write_failed', 'subject' );
			return false;
		}
		return true;
	}

	/**
	 * Read the stored attribution for a subject (for the lead form and the conversions module).
	 *
	 * Three answers, and callers must tell them apart: an array (the row), null (there is no row, or the subject is not
	 * acceptable: ordinary, nothing to report) and false (the database could not be read: the failure is listed for the
	 * owner, and a caller must NOT treat it as "no row", which would turn a storage fault into "no consent").
	 *
	 * @param string $type Subject type: payment_ref, order, enquiry or waitlist.
	 * @param mixed  $id   Subject id.
	 * @return array|null|false array( 'attribution' => array, 'consent' => array, 'captured_at' => string ), null when there is no row, false when the read failed.
	 */
	public static function for_subject( $type, $id ) {
		global $wpdb;
		$subject_id = self::normalise_subject_id( $type, $id );
		if ( '' === $subject_id ) {
			return null;
		}
		$table = self::attribution_table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT attribution_json, consent_json, captured_at FROM {$table} WHERE subject_type = %s AND subject_id = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from the WordPress prefix and a constant.
				$type,
				$subject_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			self::log_event( 'attribution_read_failed', 'subject' );
			return false;
		}
		if ( ! is_array( $row ) ) {
			return null;
		}
		return self::decode_row( $row );
	}

	/**
	 * A stored attribution row (as selected from the attribution table) in the shape for_subject() returns. Public so a
	 * reader that already holds the row (the offline export reads a page of them at once) decodes it exactly as
	 * for_subject() does, instead of asking the database again for each one.
	 *
	 * @param array $row Row with attribution_json, consent_json and (optional) captured_at.
	 * @return array array( 'attribution' => array, 'consent' => array, 'captured_at' => string ).
	 */
	public static function decode_row( array $row ) {
		$attribution = json_decode( isset( $row['attribution_json'] ) ? (string) $row['attribution_json'] : '', true );
		$consent     = json_decode( isset( $row['consent_json'] ) ? (string) $row['consent_json'] : '', true );
		$consent     = is_array( $consent ) ? $consent : array();
		return array(
			'attribution' => is_array( $attribution ) ? self::sanitise( $attribution ) : array(),
			'consent'     => array(
				'measurement' => ( true === ( isset( $consent['measurement'] ) ? $consent['measurement'] : false ) ),
				'advertising' => ( true === ( isset( $consent['advertising'] ) ? $consent['advertising'] : false ) ),
				'chosen'      => ( true === ( isset( $consent['chosen'] ) ? $consent['chosen'] : false ) ),
				'version'     => ( isset( $consent['version'] ) && is_string( $consent['version'] ) ) ? substr( $consent['version'], 0, self::TEXT_VERSION_MAX ) : '',
			),
			'captured_at' => isset( $row['captured_at'] ) ? (string) $row['captured_at'] : '',
		);
	}

	/**
	 * Store the current visitor's cookie attribution for a subject. Used by this module's own hooks and available
	 * to other modules (for example the waitlist). Writes nothing when there is nothing consented to keep.
	 *
	 * @param string $type Subject type.
	 * @param mixed  $id   Subject id.
	 * @return bool True when a row for the subject exists afterwards.
	 */
	public static function record_current( $type, $id ) {
		$consent     = self::consent_snapshot();
		$attribution = self::current_attribution( $consent );
		if ( array() === $attribution ) {
			return false;
		}
		return self::write_subject( $type, $id, $attribution, $consent );
	}

	/**
	 * Consent keys that are stored with a subject.
	 *
	 * @param array $consent Snapshot.
	 * @return array
	 */
	private static function consent_for_storage( array $consent ) {
		return array(
			'measurement' => ! empty( $consent['measurement'] ),
			'advertising' => ! empty( $consent['advertising'] ),
			'chosen'      => ! empty( $consent['chosen'] ),
			'version'     => ( isset( $consent['version'] ) && is_string( $consent['version'] ) ) ? substr( $consent['version'], 0, self::TEXT_VERSION_MAX ) : '',
		);
	}

	/**
	 * JSON for storage. An empty array is stored as an object.
	 *
	 * @param array $data Data.
	 * @return string Empty string when encoding fails.
	 */
	private static function encode( array $data ) {
		$json = wp_json_encode( ( array() === $data ) ? new stdClass() : $data );
		return is_string( $json ) ? $json : '';
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Core catering enquiries                                                                      */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Whether a REST request is a POST to a route of the core namespace.
	 *
	 * @param mixed  $request WP_REST_Request.
	 * @param string $suffix  Route suffix such as /catering/enquiry.
	 * @return bool
	 */
	private static function is_core_post( $request, $suffix ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) || ! method_exists( $request, 'get_method' ) ) {
			return false;
		}
		if ( 'POST' !== strtoupper( (string) $request->get_method() ) ) {
			return false;
		}
		$namespace = defined( 'DOUGHBOSS_REST_NAMESPACE' ) ? (string) constant( 'DOUGHBOSS_REST_NAMESPACE' ) : 'doughboss/v1';
		return ( '/' . trim( $namespace, '/' ) . $suffix ) === rtrim( (string) $request->get_route(), '/' );
	}

	/**
	 * Filter rest_request_before_callbacks: remember the dbgr_* fields of a catering enquiry request (that route
	 * only) so the lead record can be written in the same hook as core's insert. Returns $response untouched.
	 *
	 * @param mixed $response Response so far.
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  WP_REST_Request.
	 * @return mixed
	 */
	public static function stash_enquiry_params( $response, $handler = null, $request = null ) {
		unset( $handler );
		try {
			self::$stash = null;
			if ( is_wp_error( $response ) || ! self::is_core_post( $request, self::ROUTE_ENQUIRY ) ) {
				return $response;
			}
			$email = sanitize_email( self::string_param( $request, 'customer_email' ) );
			if ( '' === $email ) {
				return $response;
			}
			self::$stash = array(
				'email'        => strtolower( $email ),
				'company'      => self::cap_text( sanitize_text_field( self::string_param( $request, 'dbgr_company' ) ), self::COMPANY_MAX ),
				'segment'      => self::cap_key( self::string_param( $request, 'dbgr_segment' ), self::SEGMENT_MAX ),
				'landing_key'  => self::cap_key( self::string_param( $request, 'dbgr_landing_key' ), self::LANDING_KEY_MAX ),
				'marketing'    => ( '1' === self::string_param( $request, 'dbgr_consent_marketing' ) ),
				'text_version' => self::clean_version( self::string_param( $request, 'dbgr_consent_text_version' ) ),
			);
		} catch ( Throwable $e ) {
			self::$stash = null;
			self::log_failure( 'stash', $e );
		}
		return $response;
	}

	/**
	 * A request parameter as a string ('' when absent or not a string).
	 *
	 * @param object $request WP_REST_Request.
	 * @param string $name    Parameter name.
	 * @return string
	 */
	private static function string_param( $request, $name ) {
		if ( ! method_exists( $request, 'get_param' ) ) {
			return '';
		}
		$value = $request->get_param( $name );
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Cap plain text at $max characters (code points).
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum characters.
	 * @return string
	 */
	private static function cap_text( $text, $max ) {
		if ( 1 === preg_match( '/\A.{0,' . (int) $max . '}/su', $text, $match ) ) {
			return $match[0];
		}
		return '';
	}

	/**
	 * A lower-case key (letters, digits, dash, underscore) or '' when it is empty or too long.
	 *
	 * @param string $text Raw text.
	 * @param int    $max  Maximum length.
	 * @return string
	 */
	private static function cap_key( $text, $max ) {
		$key = sanitize_key( $text );
		return ( strlen( $key ) <= $max ) ? $key : '';
	}

	/**
	 * A consent wording version: 1 to 20 letters, digits, dots, dashes or underscores, else ''.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function clean_version( $text ) {
		$text = trim( $text );
		return ( 1 === preg_match( '/\A[A-Za-z0-9._-]{1,' . self::TEXT_VERSION_MAX . '}\z/', $text ) ) ? $text : '';
	}

	/**
	 * Action doughboss_catering_enquiry_created( $id, $row ): write exactly one lead_meta row for the enquiry
	 * (and an "enquiry" attribution row when there is something consented to keep). Catches everything.
	 *
	 * @param mixed $id  Enquiry id.
	 * @param mixed $row Stored enquiry row.
	 * @return void
	 */
	public static function on_enquiry_created( $id, $row = array() ) {
		try {
			$enquiry_id = is_numeric( $id ) ? (int) $id : 0;
			if ( $enquiry_id < 1 || ! DoughBoss_Growth_Activator::storage_ready() ) {
				return;
			}
			$tracking = DoughBoss_Growth_Settings::enabled( 'attribution' );
			if ( ! $tracking && ! DoughBoss_Growth_Settings::enabled( 'lead_form' ) ) {
				return;
			}
			$stash       = self::take_stash( $row );
			$consent     = self::consent_snapshot();
			$attribution = $tracking ? self::current_attribution( $consent ) : array();

			$marketing = ( is_array( $stash ) && true === $stash['marketing'] && '' !== $stash['text_version'] );
			$meta      = array(
				'segment'              => is_array( $stash ) ? $stash['segment'] : '',
				'company_name'         => is_array( $stash ) ? $stash['company'] : '',
				'landing_key'          => is_array( $stash ) ? $stash['landing_key'] : '',
				'consent_marketing'    => $marketing ? 1 : 0,
				'consent_text_version' => $marketing ? $stash['text_version'] : '',
			);

			$inserted = self::insert_lead_meta( $enquiry_id, $meta, $attribution );
			if ( $tracking && ( array() !== $attribution || ! empty( $consent['measurement'] ) || ! empty( $consent['advertising'] ) ) ) {
				self::write_subject( 'enquiry', $enquiry_id, $attribution, $consent );
			}
			if ( $inserted ) {
				do_action( 'doughboss_growth_lead_recorded', $enquiry_id, $meta );
			}
		} catch ( Throwable $e ) {
			self::log_failure( 'enquiry', $e );
		}
	}

	/**
	 * Take the stashed lead-form fields when they belong to this enquiry (same email), then forget them.
	 *
	 * @param mixed $row Stored enquiry row.
	 * @return array|null
	 */
	private static function take_stash( $row ) {
		$stash       = self::$stash;
		self::$stash = null;
		if ( ! is_array( $stash ) || ! is_array( $row ) || ! isset( $row['customer_email'] ) || ! is_string( $row['customer_email'] ) ) {
			return null;
		}
		return ( strtolower( $row['customer_email'] ) === $stash['email'] ) ? $stash : null;
	}

	/**
	 * Insert the lead_meta row once per enquiry.
	 *
	 * @param int   $enquiry_id  Enquiry id.
	 * @param array $meta        Lead fields.
	 * @param array $attribution Consented attribution.
	 * @return bool True when a new row was inserted.
	 */
	private static function insert_lead_meta( $enquiry_id, array $meta, array $attribution ) {
		global $wpdb;
		$attr_json = self::encode( $attribution );
		if ( '' === $attr_json ) {
			return false;
		}
		$now      = gmdate( 'Y-m-d H:i:s', DoughBoss_Growth::now() );
		$consent  = ( 1 === $meta['consent_marketing'] ) ? $now : '';
		$table    = self::lead_meta_table();
		$affected = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (enquiry_id, segment, company_name, landing_key, attribution_json, consent_marketing, consent_text_version, consent_at_utc, created_at) VALUES (%d, %s, %s, %s, %s, %d, %s, NULLIF(%s, ''), %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from the WordPress prefix and a constant.
				$enquiry_id,
				$meta['segment'],
				$meta['company_name'],
				$meta['landing_key'],
				$attr_json,
				$meta['consent_marketing'],
				$meta['consent_text_version'],
				$consent,
				$now
			)
		);
		if ( false === $affected ) {
			self::log_event( 'attribution_write_failed', 'lead_meta' );
		}
		return ( 1 === (int) $affected );
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Core orders                                                                                  */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Filter rest_post_dispatch: after a successful POST /payment-intent keep the cookie's attribution under the
	 * returned payment reference. Always returns the response untouched. Also drops any stashed enquiry fields.
	 *
	 * @param mixed $response Response.
	 * @param mixed $server   Server (unused).
	 * @param mixed $request  WP_REST_Request.
	 * @return mixed
	 */
	public static function on_rest_post_dispatch( $response, $server = null, $request = null ) {
		unset( $server );
		self::$stash = null;
		try {
			if ( ! DoughBoss_Growth_Settings::enabled( 'attribution' ) || ! DoughBoss_Growth_Activator::storage_ready() ) {
				return $response;
			}
			if ( ! self::is_core_post( $request, self::ROUTE_PAYMENT_INTENT ) || ! is_object( $response ) ) {
				return $response;
			}
			if ( ! method_exists( $response, 'get_status' ) || ! method_exists( $response, 'get_data' ) || 200 !== (int) $response->get_status() ) {
				return $response;
			}
			$data = $response->get_data();
			if ( is_array( $data ) && isset( $data['payment_intent'] ) ) {
				self::record_current( 'payment_ref', $data['payment_intent'] );
			}
		} catch ( Throwable $e ) {
			self::log_failure( 'payment_ref', $e );
		}
		return $response;
	}

	/**
	 * Action doughboss_order_created( $order_id, $data ): the order inherits the attribution stored under its
	 * payment_intent_id, or falls back to the cookie. Catches everything.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $data     Order data passed by core.
	 * @return void
	 */
	public static function on_order_created( $order_id, $data = array() ) {
		try {
			$id = is_numeric( $order_id ) ? (int) $order_id : 0;
			if ( $id < 1 || ! DoughBoss_Growth_Settings::enabled( 'attribution' ) || ! DoughBoss_Growth_Activator::storage_ready() ) {
				return;
			}
			$reference = self::order_payment_reference( $id, $data );
			if ( '' !== $reference ) {
				$stored = self::for_subject( 'payment_ref', $reference );
				if ( is_array( $stored ) ) {
					self::write_subject( 'order', $id, $stored['attribution'], $stored['consent'] );
					return;
				}
				// null: nothing was stored under that reference. false: the read failed (for_subject() has already listed it).
				// Either way the visitor's own cookie, with their consent as it is right now, is the only other source.
			}
			$consent     = self::consent_snapshot();
			$attribution = self::current_attribution( $consent );
			if ( array() !== $attribution ) {
				self::write_subject( 'order', $id, $attribution, $consent );
			}
		} catch ( Throwable $e ) {
			self::log_failure( 'order', $e );
		}
	}

	/**
	 * The payment reference of an order: from core's order row, else from the action data. '' when unknown or unusable.
	 *
	 * @param int   $order_id Order id.
	 * @param mixed $data     Order data from the action.
	 * @return string
	 */
	private static function order_payment_reference( $order_id, $data ) {
		$candidate = '';
		if ( class_exists( 'DoughBoss_Order' ) && is_callable( array( 'DoughBoss_Order', 'get' ) ) ) {
			$order = call_user_func( array( 'DoughBoss_Order', 'get' ), $order_id );
			if ( is_object( $order ) && isset( $order->payment_intent_id ) && is_string( $order->payment_intent_id ) ) {
				$candidate = $order->payment_intent_id;
			}
		}
		if ( '' === $candidate && is_array( $data ) && isset( $data['payment_intent_id'] ) && is_string( $data['payment_intent_id'] ) ) {
			$candidate = $data['payment_intent_id'];
		}
		return self::normalise_subject_id( 'payment_ref', trim( $candidate ) );
	}

	/**
	 * Log a storage problem without any data: only the event and the stage.
	 *
	 * @param string $event Event name.
	 * @param string $stage Where it happened.
	 * @return void
	 */
	private static function log_event( $event, $stage ) {
		if ( class_exists( 'DoughBoss_Growth_Http' ) ) {
			DoughBoss_Growth_Http::log( $event, array( 'stage' => $stage ) );
		}
	}

	/**
	 * Log a failure without any personal data: only the stage and the exception class.
	 *
	 * @param string    $stage Where it happened.
	 * @param Throwable $e     The error.
	 * @return void
	 */
	private static function log_failure( $stage, $e ) {
		if ( class_exists( 'DoughBoss_Growth_Http' ) ) {
			DoughBoss_Growth_Http::log(
				'attribution_failed',
				array(
					'stage' => $stage,
					'error' => get_class( $e ),
				)
			);
		}
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Admin tab                                                                                    */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Add the "Attribution" tab. Called from the doughboss_growth_admin_tabs action.
	 *
	 * @return void
	 */
	public static function register_tab() {
		if ( class_exists( 'DoughBoss_Growth_Admin' ) ) {
			DoughBoss_Growth_Admin::add_tab( 'attribution', __( 'Attribution', 'doughboss-growth' ), array( __CLASS__, 'render_tab' ) );
		}
	}

	/**
	 * The [CONFIRM] gaps and wiring notes for this module (admin-only text).
	 *
	 * @return array Code => text.
	 */
	public static function confirm_gaps() {
		$gaps = array();
		if ( ! DoughBoss_Growth_Settings::enabled( 'consent_banner' ) ) {
			$gaps['consent_banner'] = '[CONFIRM: attribution captures nothing while the consent banner is off, because there is no one to ask. Switch the consent banner on first.]';
		}
		if ( '' === (string) DoughBoss_Growth_Settings::get( 'privacy_policy_url', '' ) ) {
			$gaps['privacy_policy_url'] = '[CONFIRM: privacy-policy URL. The policy must describe the dbgr_attr cookie (90 days) and that the source of an enquiry or order is stored with it.]';
		}
		$gaps['retention']     = '[CONFIRM: how long attribution and lead records are kept. There is no automatic deletion yet, and nothing is removed when an enquiry or order is erased through the WordPress privacy tools. Decide a retention period with your accountant or solicitor.]';
		$gaps['payment_refs']  = '[CONFIRM: only payment references returned by the Square, Tyro and card routes are linked to orders. A Stripe Checkout redirect returns no payment_intent value, so such an order uses the visitor\'s cookie at the moment the order is created.]';
		$gaps['lead_consent']  = '[CONFIRM: marketing consent is recorded for an enquiry only when the lead form sends both the ticked box and the wording version it showed (dbgr_consent_marketing and dbgr_consent_text_version). Without the version the consent is stored as not given.]';
		return $gaps;
	}

	/**
	 * Render the tab body: counts and the latest lead records. No names, emails or click ids are shown.
	 *
	 * @return void
	 */
	public static function render_tab() {
		echo '<h2>' . esc_html__( 'Attribution', 'doughboss-growth' ) . '</h2>';
		$ready = DoughBoss_Growth_Activator::storage_ready();
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		$rows = array(
			array( __( 'Attribution', 'doughboss-growth' ), DoughBoss_Growth_Settings::enabled( 'attribution' ) ? __( 'On', 'doughboss-growth' ) : __( 'Off', 'doughboss-growth' ) ),
			array( __( 'Consent banner', 'doughboss-growth' ), DoughBoss_Growth_Settings::enabled( 'consent_banner' ) ? __( 'On', 'doughboss-growth' ) : __( 'Off (nothing is captured)', 'doughboss-growth' ) ),
			array( __( 'Storage', 'doughboss-growth' ), $ready ? __( 'Ready', 'doughboss-growth' ) : __( 'Not ready', 'doughboss-growth' ) ),
		);
		if ( $ready ) {
			global $wpdb;
			$attribution = self::attribution_table();
			$lead_meta   = self::lead_meta_table();
			// A count that could not be read is said so, never printed as 0: a database error makes get_var() return null, which
			// (int) turns into a calm "0" next to a table that may hold thousands of rows.
			$unread = __( 'Could not be read', 'doughboss-growth' );
			foreach ( self::SUBJECT_TYPES as $type ) {
				$count  = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare( "SELECT COUNT(*) FROM {$attribution} WHERE subject_type = %s", $type ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from the WordPress prefix and a constant.
				);
				$failed = ( null === $count || '' !== (string) $wpdb->last_error );
				$rows[] = array( sprintf( /* translators: %s: subject type. */ __( 'Attribution rows: %s', 'doughboss-growth' ), $type ), $failed ? $unread : (string) (int) $count );
			}
			$leads  = $wpdb->get_var( "SELECT COUNT(*) FROM {$lead_meta}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- no variables; table name built from the WordPress prefix and a constant.
			$failed = ( null === $leads || '' !== (string) $wpdb->last_error );
			$rows[] = array( __( 'Lead records', 'doughboss-growth' ), $failed ? $unread : (string) (int) $leads );
		}
		foreach ( $rows as $row ) {
			echo '<tr><th scope="row">' . esc_html( $row[0] ) . '</th><td>' . esc_html( $row[1] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		if ( $ready ) {
			self::render_recent_leads();
		}

		echo '<h3>' . esc_html__( 'Owner decisions and wiring still outstanding', 'doughboss-growth' ) . '</h3><ul class="ul-disc">';
		foreach ( self::confirm_gaps() as $text ) {
			echo '<li>' . esc_html( DoughBoss_Growth_Admin::plain_gap( $text ) ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * The latest lead records (enquiry number, segment, source, medium, campaign, whether a click id was kept).
	 *
	 * @return void
	 */
	private static function render_recent_leads() {
		global $wpdb;
		$lead_meta = self::lead_meta_table();
		$rows      = $wpdb->get_results( "SELECT enquiry_id, segment, attribution_json, created_at FROM {$lead_meta} ORDER BY id DESC LIMIT 10", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- no variables; table name built from the WordPress prefix and a constant.
		$failed    = ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ); // A failed read must not look like an empty list.
		echo '<h3>' . esc_html__( 'Latest lead records', 'doughboss-growth' ) . '</h3>';
		if ( $failed ) {
			echo '<p>' . esc_html__( 'The latest lead records could not be read.', 'doughboss-growth' ) . '</p>';
			return;
		}
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'None yet.', 'doughboss-growth' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
		foreach ( array( __( 'Enquiry', 'doughboss-growth' ), __( 'Segment', 'doughboss-growth' ), __( 'Source', 'doughboss-growth' ), __( 'Medium', 'doughboss-growth' ), __( 'Campaign', 'doughboss-growth' ), __( 'Ad click id kept', 'doughboss-growth' ), __( 'Recorded (UTC)', 'doughboss-growth' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$decoded = json_decode( isset( $row['attribution_json'] ) ? (string) $row['attribution_json'] : '', true );
			$attr    = is_array( $decoded ) ? self::sanitise( $decoded ) : array();
			$has_id  = false;
			foreach ( self::ADVERTISING_FIELDS as $field ) {
				if ( isset( $attr[ $field ] ) ) {
					$has_id = true;
				}
			}
			echo '<tr>';
			echo '<td>' . esc_html( (string) (int) $row['enquiry_id'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['segment'] ) . '</td>';
			echo '<td>' . esc_html( isset( $attr['utmSource'] ) ? $attr['utmSource'] : '' ) . '</td>';
			echo '<td>' . esc_html( isset( $attr['utmMedium'] ) ? $attr['utmMedium'] : '' ) . '</td>';
			echo '<td>' . esc_html( isset( $attr['utmCampaign'] ) ? $attr['utmCampaign'] : '' ) . '</td>';
			echo '<td>' . esc_html( $has_id ? __( 'Yes', 'doughboss-growth' ) : __( 'No', 'doughboss-growth' ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['created_at'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
