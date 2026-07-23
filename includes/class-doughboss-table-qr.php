<?php
/**
 * Secure store/table QR ordering context.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves opaque QR bearer codes to short-lived, cart-bound table sessions.
 */
class DoughBoss_Table_QR {

	const COOKIE      = 'doughboss_table_session';
	const QUERY_ARG   = 'doughboss_table';
	const SESSION_TTL = 28800;

	/** @var DoughBoss_Cart|null */
	private static $cart;

	/**
	 * Register the scan entry point.
	 *
	 * @param DoughBoss_Cart $cart Cart service.
	 * @return void
	 */
	public static function init( DoughBoss_Cart $cart ) {
		self::$cart = $cart;
		add_action( 'template_redirect', array( __CLASS__, 'handle_scan' ), 0 );
	}

	/**
	 * Whether this request claims an existing table session.
	 *
	 * @return bool
	 */
	public static function has_session_cookie() {
		return isset( $_COOKIE[ self::COOKIE ] ) && '' !== trim( (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) );
	}

	/**
	 * Convert a scanned bearer code into a clean, server-bound session.
	 *
	 * @return void
	 */
	public static function handle_scan() {
		if ( ! isset( $_GET[ self::QUERY_ARG ] ) || ! self::$cart ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$raw = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) wp_unslash( $_GET[ self::QUERY_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( strlen( $raw ) < 32 || strlen( $raw ) > 128 ) {
			self::clear_cookie();
			wp_die( esc_html__( 'This table QR code is not valid. Please ask a staff member for help.', 'doughboss' ), esc_html__( 'Invalid table QR', 'doughboss' ), array( 'response' => 400 ) );
		}

		$result = self::start_from_code( $raw, self::$cart );
		if ( is_wp_error( $result ) ) {
			self::clear_cookie();
			$error_data = $result->get_error_data();
			$status = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 410;
			wp_die( esc_html( $result->get_error_message() ), esc_html__( 'Table ordering unavailable', 'doughboss' ), array( 'response' => $status ) );
		}

		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
			header( 'Cache-Control: no-store, private' );
		}
		wp_safe_redirect( remove_query_arg( self::QUERY_ARG ), 303 );
		exit;
	}

	/**
	 * Create a fresh table session from a raw, one-table QR code.
	 *
	 * @param string          $raw  Raw bearer code.
	 * @param DoughBoss_Cart $cart Cart service.
	 * @return array|WP_Error
	 */
	public static function start_from_code( $raw, DoughBoss_Cart $cart ) {
		global $wpdb;
		$codes     = $wpdb->prefix . 'doughboss_table_qr_codes';
		$tables    = $wpdb->prefix . 'doughboss_dining_tables';
		$locations = $wpdb->prefix . 'doughboss_locations';
		$sessions  = $wpdb->prefix . 'doughboss_table_sessions';
		$hash      = hash( 'sha256', (string) $raw );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT q.id AS qr_code_id, q.table_id, t.label AS table_label, t.location_id, l.name AS location_name
			FROM {$codes} q
			INNER JOIN {$tables} t ON t.id = q.table_id AND t.current_qr_code_id = q.id
			INNER JOIN {$locations} l ON l.id = t.location_id
			WHERE q.token_hash = %s AND q.status = 'active' AND t.is_active = 1 AND l.is_active = 1
			LIMIT 1",
			$hash
		) );
		if ( ! $row ) {
			return new WP_Error( 'doughboss_table_qr_invalid', __( 'This table QR code has expired or is no longer active. Please ask a staff member for help.', 'doughboss' ), array( 'status' => 410 ) );
		}

		$cart_token    = $cart->begin_table_session();
		$session_token = self::random_token();
		$now           = current_time( 'mysql', true );
		$expires       = gmdate( 'Y-m-d H:i:s', time() + self::SESSION_TTL );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			$sessions,
			array(
				'session_hash'   => hash( 'sha256', $session_token ),
				'qr_code_id'      => (int) $row->qr_code_id,
				'cart_token_hash' => hash( 'sha256', $cart_token ),
				'expires_at'      => $expires,
				'last_seen_at'    => $now,
				'created_at'      => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'doughboss_table_session_failed', __( 'Table ordering could not be started. Please try scanning again.', 'doughboss' ), array( 'status' => 503 ) );
		}
		$session_id = (int) $wpdb->insert_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$codes} SET last_scanned_at = %s WHERE id = %d", $now, (int) $row->qr_code_id ) );
		self::set_cookie( $session_token );
		return array( 'session_id' => $session_id, 'expires_at' => $expires );
	}

	/**
	 * Resolve and revalidate the current table session.
	 *
	 * @param string $cart_token Current cart token.
	 * @return array|WP_Error
	 */
	public static function current_context( $cart_token ) {
		global $wpdb;
		if ( ! self::has_session_cookie() ) {
			return new WP_Error( 'doughboss_table_session_missing', __( 'Your table session is missing. Please scan the table QR code again.', 'doughboss' ), array( 'status' => 401 ) );
		}
		$session_token = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		if ( strlen( $session_token ) < 32 ) {
			return new WP_Error( 'doughboss_table_session_invalid', __( 'Your table session is invalid. Please scan the table QR code again.', 'doughboss' ), array( 'status' => 401 ) );
		}

		$sessions = $wpdb->prefix . 'doughboss_table_sessions';
		$codes = $wpdb->prefix . 'doughboss_table_qr_codes';
		$tables = $wpdb->prefix . 'doughboss_dining_tables';
		$locations = $wpdb->prefix . 'doughboss_locations';
		// Re-check code rotation/revocation and active store/table on every money path.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT s.id AS session_id, s.expires_at, q.id AS qr_code_id, t.id AS table_id, t.label AS table_label, t.location_id, l.name AS location_name
			FROM {$sessions} s
			INNER JOIN {$codes} q ON q.id = s.qr_code_id AND q.status = 'active'
			INNER JOIN {$tables} t ON t.id = q.table_id AND t.current_qr_code_id = q.id AND t.is_active = 1
			INNER JOIN {$locations} l ON l.id = t.location_id AND l.is_active = 1
			WHERE s.session_hash = %s AND s.cart_token_hash = %s AND s.expires_at > %s
			LIMIT 1",
			hash( 'sha256', $session_token ),
			hash( 'sha256', (string) $cart_token ),
			current_time( 'mysql', true )
		) );
		if ( ! $row ) {
			return new WP_Error( 'doughboss_table_session_expired', __( 'This table session has expired or was replaced. Please scan the table QR code again.', 'doughboss' ), array( 'status' => 410 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$sessions} SET last_seen_at = %s WHERE id = %d", current_time( 'mysql', true ), (int) $row->session_id ) );
		return array(
			'session_id'   => (int) $row->session_id,
			'qr_code_id'   => (int) $row->qr_code_id,
			'table_id'     => (int) $row->table_id,
			'table_label'  => $row->table_label,
			'location_id'  => (int) $row->location_id,
			'location_name' => $row->location_name,
			'expires_at'   => mysql_to_rfc3339( $row->expires_at ),
		);
	}

	/**
	 * Create a table and its first printable QR code.
	 *
	 * @param int    $location_id Location ID.
	 * @param string $label       Human table label.
	 * @param string $zone        Optional zone.
	 * @param string $ordering_url Same-site page containing the ordering menu.
	 * @return array|WP_Error
	 */
	public static function create_table( $location_id, $label, $zone = '', $ordering_url = '' ) {
		global $wpdb;
		$location_id = absint( $location_id );
		$label = sanitize_text_field( $label );
		$zone = sanitize_text_field( $zone );
		$ordering_url = esc_url_raw( $ordering_url ? $ordering_url : home_url( '/' ) );
		if ( ! self::is_same_origin_url( $ordering_url ) ) {
			return new WP_Error( 'doughboss_table_url_invalid', __( 'The ordering page must be on this WordPress site.', 'doughboss' ), array( 'status' => 400 ) );
		}
		if ( ! $location_id || '' === $label || ! DoughBoss_Locations::is_valid( $location_id ) ) {
			return new WP_Error( 'doughboss_table_invalid', __( 'Choose an active store and enter a table label.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( $wpdb->prefix . 'doughboss_dining_tables', array( 'location_id' => $location_id, 'label' => $label, 'zone' => $zone, 'ordering_url' => $ordering_url, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' ) );
		if ( false === $ok ) {
			return new WP_Error( 'doughboss_table_exists', __( 'That table already exists at this store.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$table_id = (int) $wpdb->insert_id;
		$issued   = self::issue_code( $table_id );
		if ( is_wp_error( $issued ) ) {
			// Avoid leaving an unusable table row that then blocks a clean retry.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $wpdb->prefix . 'doughboss_dining_tables', array( 'id' => $table_id ), array( '%d' ) );
		}
		return $issued;
	}

	/**
	 * List tables for the management screen without exposing bearer codes.
	 *
	 * @return array
	 */
	public static function all_tables() {
		global $wpdb;
		$tables = $wpdb->prefix . 'doughboss_dining_tables';
		$locations = $wpdb->prefix . 'doughboss_locations';
		$codes = $wpdb->prefix . 'doughboss_table_qr_codes';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			"SELECT t.*, l.name AS location_name, q.created_at AS qr_created_at, q.last_scanned_at
			FROM {$tables} t
			INNER JOIN {$locations} l ON l.id = t.location_id
			LEFT JOIN {$codes} q ON q.id = t.current_qr_code_id
			ORDER BY l.sort_order ASC, l.name ASC, t.sort_order ASC, t.label ASC"
		);
	}

	/**
	 * Activate or deactivate a table. Deactivation invalidates its sessions.
	 *
	 * @param int  $table_id Table ID.
	 * @param bool $active   Desired state.
	 * @return bool|WP_Error
	 */
	public static function set_active( $table_id, $active ) {
		global $wpdb;
		$table_id = absint( $table_id );
		$tables   = $wpdb->prefix . 'doughboss_dining_tables';
		if ( ! $table_id ) {
			return new WP_Error( 'doughboss_table_not_found', __( 'Table not found.', 'doughboss' ), array( 'status' => 404 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update( $tables, array( 'is_active' => $active ? 1 : 0, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $table_id ), array( '%d', '%s' ), array( '%d' ) );
		if ( false === $updated ) {
			return new WP_Error( 'doughboss_table_update_failed', __( 'Could not update that table.', 'doughboss' ), array( 'status' => 500 ) );
		}
		if ( 0 === $updated ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tables} WHERE id = %d", $table_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $exists ) {
				return new WP_Error( 'doughboss_table_not_found', __( 'Table not found.', 'doughboss' ), array( 'status' => 404 ) );
			}
		}
		return true;
	}

	/**
	 * Rotate a table QR, immediately invalidating every older code/session.
	 * Raw code is returned once and never stored.
	 *
	 * @param int $table_id Table ID.
	 * @return array|WP_Error
	 */
	public static function issue_code( $table_id ) {
		global $wpdb;
		$table_id = absint( $table_id );
		$tables = $wpdb->prefix . 'doughboss_dining_tables';
		$codes = $wpdb->prefix . 'doughboss_table_qr_codes';
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// Serialize rotations for one table so two manager requests cannot both
		// appear to issue the current printable code.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables} WHERE id = %d LIMIT 1 FOR UPDATE", $table_id ) );
		if ( ! $table ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'doughboss_table_not_found', __( 'Table not found.', 'doughboss' ), array( 'status' => 404 ) );
		}
		$raw = self::random_token();
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$codes} SET status = 'revoked', revoked_at = %s WHERE table_id = %d AND status = 'active'", $now, $table_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( $codes, array( 'table_id' => $table_id, 'token_hash' => hash( 'sha256', $raw ), 'status' => 'active', 'created_by' => get_current_user_id(), 'created_at' => $now ), array( '%d', '%s', '%s', '%d', '%s' ) );
		if ( false === $ok ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'doughboss_qr_failed', __( 'Could not create the table QR code.', 'doughboss' ), array( 'status' => 500 ) );
		}
		$code_id = (int) $wpdb->insert_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update( $tables, array( 'current_qr_code_id' => $code_id, 'updated_at' => $now ), array( 'id' => $table_id ), array( '%d', '%s' ), array( '%d' ) );
		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'doughboss_qr_failed', __( 'Could not bind the table QR code.', 'doughboss' ), array( 'status' => 500 ) );
		}
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array(
			'table_id' => $table_id,
			'qr_code_id' => $code_id,
			'code' => $raw,
			'url' => add_query_arg( self::QUERY_ARG, rawurlencode( $raw ), $table->ordering_url ? $table->ordering_url : home_url( '/' ) ),
			'label' => $table->label,
			'location_id' => (int) $table->location_id,
		);
	}

	/** @return string */
	private static function random_token() {
		return rtrim( strtr( base64_encode( random_bytes( 24 ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Require the exact WordPress origin so a bearer QR cannot be downgraded to
	 * plaintext HTTP or sent to another service on the same hostname.
	 *
	 * @param string $url Candidate ordering URL.
	 * @return bool
	 */
	private static function is_same_origin_url( $url ) {
		$target = wp_parse_url( $url );
		$home   = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $target ) || ! is_array( $home ) || ! empty( $target['user'] ) || ! empty( $target['pass'] ) ) {
			return false;
		}
		$target_scheme = strtolower( isset( $target['scheme'] ) ? $target['scheme'] : '' );
		$home_scheme   = strtolower( isset( $home['scheme'] ) ? $home['scheme'] : '' );
		$target_host   = strtolower( isset( $target['host'] ) ? $target['host'] : '' );
		$home_host     = strtolower( isset( $home['host'] ) ? $home['host'] : '' );
		$target_port   = isset( $target['port'] ) ? (int) $target['port'] : ( 'https' === $target_scheme ? 443 : 80 );
		$home_port     = isset( $home['port'] ) ? (int) $home['port'] : ( 'https' === $home_scheme ? 443 : 80 );
		return in_array( $target_scheme, array( 'http', 'https' ), true )
			&& $target_scheme === $home_scheme
			&& '' !== $target_host
			&& $target_host === $home_host
			&& $target_port === $home_port;
	}

	/** @param string $token Raw session token. @return void */
	private static function set_cookie( $token ) {
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, $token, array( 'expires' => time() + self::SESSION_TTL, 'path' => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/', 'domain' => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		}
		$_COOKIE[ self::COOKIE ] = $token;
	}

	/** @return void */
	private static function clear_cookie() {
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, '', array( 'expires' => time() - HOUR_IN_SECONDS, 'path' => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/', 'domain' => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		}
		unset( $_COOKIE[ self::COOKIE ] );
	}
}
