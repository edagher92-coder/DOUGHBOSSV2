<?php
/**
 * Test stubs and shared helpers for the timesheet reconciliation module (WP-11).
 *
 * Stubs of the DoughBoss CORE staff-clock classes as the companion sees them. worked_minutes() and
 * break_minutes_for_shift() are VERBATIM copies of core 2.41.0 / 2.43.2 (identical in both:
 * includes/class-doughboss-timeclock.php:523-541 and includes/class-doughboss-staff-badge.php:353-370), so the
 * shim parity test compares the companion with core's own formula. test-recon-reader.php additionally loads
 * the REAL core files in a sub-process when their source is available (DBGR_CORE_SRC or the repository root).
 *
 * Every definition is guarded, so another package's stub of the same class wins without a fatal error.
 *
 * @package DoughBoss_Growth
 */

$GLOBALS['dbgr_recon_storage_ready'] = true;
$GLOBALS['dbgr_recon_users']         = array();

if ( ! class_exists( 'DoughBoss_Timeclock', false ) ) {
	/** Core staff clock (stub). */
	final class DoughBoss_Timeclock {
		/** @return string */
		public static function table() {
			global $wpdb;
			return $wpdb->prefix . 'doughboss_staff_shifts';
		}
		/** @return string */
		public static function events_table() {
			global $wpdb;
			return $wpdb->prefix . 'doughboss_staff_shift_events';
		}
		/** @return bool */
		public static function storage_ready() {
			if ( 'throw' === $GLOBALS['dbgr_recon_storage_ready'] ) {
				throw new RuntimeException( 'storage probe failed' );
			}
			return $GLOBALS['dbgr_recon_storage_ready']; // Raw value: the reader must accept only a strict true.
		}
		/** Return elapsed shift time less only verified, recorded breaks. (verbatim core) */
		public static function worked_minutes( $shift ) {
			if ( ! $shift || empty( $shift->clock_in_utc ) ) {
				return 0;
			}
			$in    = strtotime( $shift->clock_in_utc . ' UTC' );
			$until = ! empty( $shift->clock_out_utc ) ? strtotime( $shift->clock_out_utc . ' UTC' ) : time();
			return max( 0, (int) floor( ( $until - $in ) / 60 ) - self::break_minutes( $shift, $until ) );
		}
		/** Return only stored break minutes; no automatic or assumed deduction exists. (verbatim core) */
		private static function break_minutes( $shift, $until ) {
			return class_exists( 'DoughBoss_Staff_Badge' ) ? DoughBoss_Staff_Badge::break_minutes_for_shift( $shift, $until ) : 0;
		}
	}
}

if ( ! class_exists( 'DoughBoss_Staff_Badge', false ) ) {
	/** Core badge kiosk (stub). */
	final class DoughBoss_Staff_Badge {
		/** @return string */
		public static function breaks_table() {
			global $wpdb;
			return $wpdb->prefix . 'doughboss_staff_breaks';
		}
		/** Calculate only actually-recorded break minutes; there is no auto deduction. (verbatim core) */
		public static function break_minutes_for_shift( $shift, $until = 0 ) {
			if ( ! $shift || empty( $shift->id ) ) {
				return 0;
			}
			global $wpdb;
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT break_start_utc, break_end_utc FROM ' . self::breaks_table() . ' WHERE shift_id = %d ORDER BY id ASC', (int) $shift->id ) ); // phpcs:ignore
			$end  = $until ? (int) $until : time();
			$total = 0;
			foreach ( $rows as $row ) {
				$start = strtotime( $row->break_start_utc . ' UTC' );
				$finish = $row->break_end_utc ? strtotime( $row->break_end_utc . ' UTC' ) : $end;
				if ( $start && $finish > $start ) {
					$total += (int) floor( ( $finish - $start ) / 60 );
				}
			}
			return max( 0, $total );
		}
	}
}

if ( ! class_exists( 'DoughBoss_Locations', false ) ) {
	/** Core shop list (same minimal shape as stubs-consent.php / stubs-waitlist.php: tests set ::$rows). */
	class DoughBoss_Locations {
		/** @var array|null Rows returned by all(). */
		public static $rows = array();
		/** @var bool When true, all() throws. */
		public static $throw = false;
		/** @param bool $active_only Active only. @return array|null */
		public static function all( $active_only = false ) {
			if ( self::$throw ) {
				throw new RuntimeException( 'database error' );
			}
			return self::$rows;
		}
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	/** @param int $id User id. @return object|false */
	function get_userdata( $id ) {
		return isset( $GLOBALS['dbgr_recon_users'][ (int) $id ] ) ? $GLOBALS['dbgr_recon_users'][ (int) $id ] : false;
	}
}
if ( ! function_exists( 'get_users' ) ) {
	/** @param array $args Args (capability honoured as "has the cap" flag on the fake user). @return array */
	function get_users( $args = array() ) {
		$out = array();
		foreach ( $GLOBALS['dbgr_recon_users'] as $user ) {
			if ( isset( $args['capability'] ) && empty( $user->dbgr_caps[ $args['capability'] ] ) ) {
				continue;
			}
			$out[] = $user;
		}
		return $out;
	}
}
if ( ! function_exists( 'wp_nonce_url' ) ) {
	/** @param string $url URL. @param string $action Action. @param string $name Name. @return string */
	function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) {
		return add_query_arg( $name, wp_create_nonce( $action ), $url );
	}
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	/** @param string $name Name. @return string */
	function sanitize_file_name( $name ) {
		return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $name );
	}
}

/**
 * Core attendance DDL (copied from core 2.41.0 includes/class-doughboss-activator.php:564-632, wp_ prefix).
 *
 * @return array
 */
function dbgr_recon_core_ddl() {
	return array(
		"CREATE TABLE wp_doughboss_staff_shifts (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			staff_name varchar(191) NOT NULL DEFAULT '',
			staff_login varchar(191) NOT NULL DEFAULT '',
			location_id bigint(20) unsigned NOT NULL,
			location_name varchar(191) NOT NULL DEFAULT '',
			timezone_snapshot varchar(64) NOT NULL DEFAULT 'Australia/Sydney',
			clock_in_utc datetime NOT NULL,
			clock_out_utc datetime NULL DEFAULT NULL,
			scheduled_start_local varchar(5) NOT NULL DEFAULT '',
			late_grace_minutes smallint(5) unsigned NOT NULL DEFAULT 0,
			late_minutes smallint(5) unsigned NOT NULL DEFAULT 0,
			open_guard tinyint(1) unsigned NULL DEFAULT 1,
			source varchar(32) NOT NULL DEFAULT 'staff_portal',
			created_at datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_open_guard (user_id,open_guard),
			KEY location_clock_in (location_id,clock_in_utc),
			KEY clock_in_utc (clock_in_utc)
		) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;",
		"CREATE TABLE wp_doughboss_staff_breaks (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			shift_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			break_start_utc datetime NOT NULL,
			break_end_utc datetime NULL DEFAULT NULL,
			open_guard tinyint(1) unsigned NULL DEFAULT 1,
			source varchar(32) NOT NULL DEFAULT 'staff_badge',
			created_at datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY shift_open_guard (shift_id,open_guard),
			KEY user_break_start (user_id,break_start_utc),
			KEY shift_break_start (shift_id,break_start_utc)
		) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;",
		"CREATE TABLE wp_doughboss_staff_shift_events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			shift_id bigint(20) unsigned NOT NULL,
			event_type varchar(32) NOT NULL,
			actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			reason varchar(500) NOT NULL DEFAULT '',
			before_json longtext NULL,
			after_json longtext NULL,
			occurred_at_utc datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY shift_occurred (shift_id,occurred_at_utc),
			KEY actor_occurred (actor_user_id,occurred_at_utc)
		) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4;",
	);
}

/**
 * Set or clear an environment variable for this process.
 *
 * @param string      $name  Name.
 * @param string|null $value Value or null to clear.
 * @return void
 */
function dbgr_recon_env( $name, $value ) {
	if ( null === $value ) {
		putenv( $name );
	} else {
		putenv( $name . '=' . $value );
	}
}

/**
 * Fresh recon environment on SQLite: companion schema installed, core attendance tables created, core
 * schema version set, feature flag on (unless told otherwise), Square env + token set (unless told otherwise).
 *
 * @param array $opts feature (bool, default true), env (string|null, default 'production'), token (bool, default true).
 * @return void
 */
function dbgr_recon_setup( array $opts = array() ) {
	$opts = array_merge(
		array(
			'feature' => true,
			'env'     => 'production',
			'token'   => true,
		),
		$opts
	);
	$GLOBALS['dbgr_recon_storage_ready'] = true;
	DoughBoss_Locations::$rows  = array(
		(object) array( 'id' => '1', 'name' => 'Revesby (test)', 'is_active' => '1' ),
		(object) array( 'id' => '2', 'name' => 'Bankstown (test)', 'is_active' => '1' ),
		(object) array( 'id' => '3', 'name' => 'Roselands Centro (test)', 'is_active' => '1' ),
	);
	DoughBoss_Locations::$throw = false;
	$GLOBALS['dbgr_recon_users'] = array();
	DoughBoss_Growth_Recon_Admin::reset_state();
	$GLOBALS['wpdb']->use_sqlite();
	foreach ( dbgr_recon_core_ddl() as $sql ) {
		$GLOBALS['wpdb']->create_table_from_mysql( $sql );
	}
	DoughBoss_Growth_Activator::install();
	dbgr_test_core_option( 'doughboss_db_version', '1.23.0' );
	update_option( 'doughboss_growth_settings', array( 'features' => array( 'timesheet_recon' => (bool) $opts['feature'] ) ) );
	dbgr_recon_env( 'DOUGHBOSS_GROWTH_SQUARE_ENV', $opts['env'] );
	dbgr_recon_env( 'DOUGHBOSS_GROWTH_SQUARE_LABOUR_TOKEN', $opts['token'] ? 'dbgr-test-labour-token-not-real-0001' : null );
	$GLOBALS['wpdb']->reset_log();
}

/**
 * Clear the environment variables a recon test set.
 *
 * @return void
 */
function dbgr_recon_teardown() {
	dbgr_recon_env( 'DOUGHBOSS_GROWTH_SQUARE_ENV', null );
	dbgr_recon_env( 'DOUGHBOSS_GROWTH_SQUARE_LABOUR_TOKEN', null );
	DoughBoss_Growth_Recon_Admin::capture_downloads( false );
	DoughBoss_Locations::$rows  = array();
	DoughBoss_Locations::$throw = false;
}

/**
 * Dump every recon table (for data-minimisation checks).
 *
 * @return string
 */
function dbgr_recon_dump() {
	$out = array();
	foreach ( array( 'run', 'row', 'xref' ) as $t ) {
		$out[ $t ] = $GLOBALS['wpdb']->sqlite_raw( 'SELECT * FROM wp_doughboss_growth_recon_' . $t );
	}
	return (string) wp_json_encode( $out );
}

/**
 * Personal or secret strings that must never be stored by the module.
 *
 * @return array
 */
function dbgr_recon_forbidden_strings() {
	return array( 'Zed Example', 'zed.example.login', 'private detail', 'Shop name snapshot', 'Kitchen hand', 'Lunch', 'dbgr-test-labour-token', '@example' );
}

/**
 * Add a fake WordPress user (for name resolution at render time).
 *
 * @param int    $id    Id.
 * @param string $name  Display name.
 * @param string $email Email.
 * @param bool   $staff Has clock_doughboss_staff.
 * @return void
 */
function dbgr_recon_user( $id, $name, $email, $staff = true ) {
	$GLOBALS['dbgr_recon_users'][ (int) $id ] = (object) array(
		'ID'           => (int) $id,
		'display_name' => $name,
		'user_email'   => $email,
		'dbgr_caps'    => $staff ? array( 'clock_doughboss_staff' => true ) : array(),
	);
}

/**
 * Seed one core shift (and its breaks / manager_closed event) straight into SQLite, bypassing the query
 * log (it is test set-up done "by core", not companion code).
 *
 * @param array $s id, user, loc, in, out (null = open), tz, breaks (list of array( start, end|null )), mc (bool),
 *                 name, login, reason.
 * @return void
 */
function dbgr_recon_seed_shift( array $s ) {
	$s   = array_merge(
		array(
			'out'    => null,
			'tz'     => 'Australia/Sydney',
			'breaks' => array(),
			'mc'     => false,
			'name'   => 'Zed Example Staffname',
			'login'  => 'zed.example.login',
			'reason' => 'Forgot to clock out, manager note with private detail',
			'source' => 'staff_badge',
		),
		$s
	);
	$q   = function ( $v ) {
		return null === $v ? 'NULL' : "'" . str_replace( "'", "''", (string) $v ) . "'";
	};
	$pdo = $GLOBALS['wpdb'];
	$pdo->sqlite_raw(
		'INSERT INTO wp_doughboss_staff_shifts (id, user_id, staff_name, staff_login, location_id, location_name, timezone_snapshot, clock_in_utc, clock_out_utc, open_guard, source, updated_at) VALUES ('
		. (int) $s['id'] . ', ' . (int) $s['user'] . ', ' . $q( $s['name'] ) . ', ' . $q( $s['login'] ) . ', ' . (int) $s['loc'] . ", 'Shop name snapshot', " . $q( $s['tz'] ) . ', ' . $q( $s['in'] ) . ', ' . $q( $s['out'] ) . ', ' . ( null === $s['out'] ? '1' : 'NULL' ) . ', ' . $q( $s['source'] ) . ', ' . $q( null === $s['out'] ? $s['in'] : $s['out'] ) . ')'
	);
	foreach ( $s['breaks'] as $index => $b ) {
		$pdo->sqlite_raw(
			'INSERT INTO wp_doughboss_staff_breaks (id, shift_id, user_id, break_start_utc, break_end_utc, open_guard) VALUES ('
			. ( (int) $s['id'] * 100 + $index ) . ', ' . (int) $s['id'] . ', ' . (int) $s['user'] . ', ' . $q( $b[0] ) . ', ' . $q( $b[1] ) . ', ' . ( null === $b[1] ? '1' : 'NULL' ) . ')'
		);
	}
	if ( $s['mc'] ) {
		$pdo->sqlite_raw(
			'INSERT INTO wp_doughboss_staff_shift_events (shift_id, event_type, actor_user_id, reason, before_json, after_json, occurred_at_utc) VALUES ('
			. (int) $s['id'] . ", 'manager_closed', 1, " . $q( $s['reason'] ) . ", '{\"staff_name\":\"Zed Example Staffname\"}', '{}', " . $q( $s['out'] ) . ')'
		);
	}
}

/**
 * Confirm a mapping row directly (test set-up).
 *
 * @param string $kind   employee|location.
 * @param int    $local  Local id.
 * @param string $square Square id.
 * @param string $env    Environment.
 * @return void
 */
function dbgr_recon_map( $kind, $local, $square, $env = 'production' ) {
	$GLOBALS['wpdb']->sqlite_raw(
		"INSERT INTO wp_doughboss_growth_recon_xref (kind, environment, local_id, square_id, status, active_guard, confirmed_by, confirmed_at) VALUES ('" . $kind . "', '" . $env . "', " . (int) $local . ", '" . $square . "', 'confirmed', 1, 1, '2026-10-01 00:00:00')"
	);
}

/**
 * A raw Square timecard (API shape).
 *
 * @param string      $id     Id.
 * @param string      $member Team member id.
 * @param string      $loc    Location id.
 * @param string      $start  RFC 3339.
 * @param string|null $end    RFC 3339 or null (OPEN).
 * @param array       $extra  Overrides (breaks, version, updated_at, timezone, wage, ...).
 * @return array
 */
function dbgr_recon_tc( $id, $member, $loc, $start, $end, array $extra = array() ) {
	return array_merge(
		array(
			'id'                      => $id,
			'team_member_id'          => $member,
			'location_id'             => $loc,
			'timezone'                => 'Australia/Sydney',
			'start_at'                => $start,
			'end_at'                  => $end,
			'status'                  => null === $end ? 'OPEN' : 'CLOSED',
			'version'                 => 1,
			'breaks'                  => array(),
			'wage'                    => array(
				'title'       => 'Kitchen hand (synthetic)',
				'hourly_rate' => array(
					'amount'   => 0,
					'currency' => 'AUD',
				),
			),
			'declared_cash_tip_money' => array(
				'amount'   => 0,
				'currency' => 'AUD',
			),
			'created_at'              => $start,
			'updated_at'              => null === $end ? $start : $end,
		),
		$extra
	);
}

/**
 * Script the fake Square labour API. $store holds every timecard; the fake answers each search by applying
 * the request's filter itself (so a scope assertion in the client is tested against a correct server), pages
 * with the request's limit and an opaque cursor. Options let a test break the server on purpose.
 *
 * @param array $cards Raw timecards.
 * @param array $opts  status_on (array( call# => http status )), ignore_filter (bool), body_on (array( call# => raw body )),
 *                     loop_cursor (bool).
 * @return void
 */
function dbgr_recon_fake_square( array $cards, array $opts = array() ) {
	$GLOBALS['dbgr_recon_square'] = array(
		'cards' => $cards,
		'opts'  => $opts,
		'calls' => 0,
		'seen'  => array(),
	);
	dbgr_test_http_expect(
		'https://connect.squareup.com/v2/labor/timecards/search',
		'dbgr_recon_fake_square_respond',
		array(
			'method' => 'POST',
			'times'  => 0,
		)
	);
}

/**
 * Fake Square responder.
 *
 * @param string $url  URL.
 * @param array  $args Request args.
 * @return array
 */
function dbgr_recon_fake_square_respond( $url, $args ) {
	unset( $url );
	$state = &$GLOBALS['dbgr_recon_square'];
	$state['calls']++;
	$call = $state['calls'];
	$opts = $state['opts'];
	$state['seen'][] = array(
		'headers' => $args['headers'],
		'body'    => json_decode( (string) $args['body'], true ),
	);
	if ( isset( $opts['status_on'][ $call ] ) ) {
		return dbgr_test_http_response( $opts['status_on'][ $call ], '{"errors":[{"category":"RATE_LIMIT_ERROR","code":"RATE_LIMITED"}]}' );
	}
	if ( isset( $opts['body_on'][ $call ] ) ) {
		return dbgr_test_http_response( 200, $opts['body_on'][ $call ] );
	}
	$req    = json_decode( (string) $args['body'], true );
	$filter = $req['query']['filter'];
	$match  = array();
	foreach ( $state['cards'] as $card ) {
		if ( empty( $opts['ignore_filter'] ) ) {
			if ( ! in_array( $card['location_id'], $filter['location_ids'], true ) ) {
				continue;
			}
			if ( isset( $filter['status'] ) && $card['status'] !== $filter['status'] ) {
				continue;
			}
			if ( isset( $filter['start'] ) ) {
				$start = DoughBoss_Growth_Recon_Square::parse_time( $card['start_at'] );
				if ( $start < DoughBoss_Growth_Recon_Square::parse_time( $filter['start']['start_at'] ) || $start > DoughBoss_Growth_Recon_Square::parse_time( $filter['start']['end_at'] ) ) {
					continue;
				}
			}
		}
		$match[] = $card;
	}
	$offset = isset( $req['cursor'] ) ? (int) substr( $req['cursor'], 7 ) : 0;
	$limit  = (int) $req['limit'];
	$page   = array_slice( $match, $offset, $limit );
	$body   = array( 'timecards' => $page );
	if ( $offset + $limit < count( $match ) ) {
		$body['cursor'] = ! empty( $opts['loop_cursor'] ) ? 'cursor-0' : 'cursor-' . ( $offset + $limit );
	}
	return dbgr_test_http_response( 200, wp_json_encode( $body ) );
}

/**
 * Every SQL statement the companion issued that writes to a table outside its own namespace.
 *
 * @return array
 */
function dbgr_recon_core_writes() {
	$out = array();
	foreach ( $GLOBALS['wpdb']->queries as $q ) {
		$sql = $q['sql'];
		// 1. A write verb aimed at any table outside wp_doughboss_growth_*.
		if ( 1 === preg_match( '/^\s*(INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM|DROP\s+TABLE(?:\s+IF\s+EXISTS)?|ALTER\s+TABLE|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|TRUNCATE(?:\s+TABLE)?)\s+`?([A-Za-z0-9_]+)`?/i', $sql, $m ) && 0 !== strpos( $m[2], 'wp_doughboss_growth_' ) ) {
			$out[] = $sql;
			continue;
		}
		// 2. Any statement that names a core table and is not a plain read.
		if ( 1 === preg_match( '/\bwp_(?!doughboss_growth_)[A-Za-z0-9_]+/', $sql ) && 1 !== preg_match( '/^\s*(SELECT|SHOW)\b/i', $sql ) ) {
			$out[] = $sql;
		}
	}
	return $out;
}
