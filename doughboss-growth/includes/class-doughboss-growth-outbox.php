<?php
/**
 * Shared outbox: durable, idempotent queue for outbound deliveries (conversions, webhooks).
 *
 * Channels register a handler; a 5-minute cron claims due rows one at a time with an atomic
 * conditional UPDATE (single winner), calls the handler and records the outcome. Retries back off
 * 60, 300, 1800, 1800, 1800 seconds, at most 5 attempts, then the row is parked as failed_terminal
 * for an operator. A row left in_flight past the lease is quarantined, never re-sent automatically
 * (a re-send could double-count a conversion).
 *
 * A run is bounded: at most BATCH rows and about TIME_BUDGET_SECONDS of work, because every delivery can wait ten
 * seconds on a provider and a host that kills a long request would leave a delivered row in_flight, which the
 * quarantine rule then parks as ambiguous. Rows not reached stay pending and go out on the next run.
 *
 * Nothing here fails quietly (the house silence rule): a state change that could not be written, a read that failed
 * and a cron event that could not be scheduled are listed in DoughBoss_Growth_Failures, and a failed read is never
 * taken for "nothing is waiting" (that would clear the cron and stall the queue).
 *
 * Payloads must not contain raw personal data; enqueue() refuses them.
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outbox queue and dispatcher.
 */
final class DoughBoss_Growth_Outbox {

	/**
	 * Table name without prefix.
	 */
	const TABLE = 'doughboss_growth_outbox';

	/**
	 * Cron hook and the custom 5-minute schedule.
	 */
	const CRON_HOOK     = 'doughboss_growth_outbox_dispatch';
	const CRON_SCHEDULE = 'doughboss_growth_5min';

	/**
	 * Seconds to wait after failed attempt N (index N-1). Copied from the core POSPal outbox.
	 */
	const BACKOFF_SECONDS = array( 60, 300, 1800, 1800, 1800 );

	/**
	 * Attempts before a row is parked as failed_terminal.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Rows claimed per sweep. Each delivery may wait ten seconds on a provider (and a blocking DNS lookup before that),
	 * so a long batch can outlive the host's request limit; ten rows every five minutes is still 120 an hour.
	 */
	const BATCH = 10;

	/**
	 * Seconds of work after which a sweep stops claiming rows (checked before each claim, so the delivery in progress
	 * is never cut). The rows it did not reach stay pending.
	 */
	const TIME_BUDGET_SECONDS = 20;

	/**
	 * Timing transient for the admin-side resume check, and its length in seconds (fifteen minutes).
	 */
	const RESUME_TRANSIENT = 'doughboss_growth_outbox_resume';
	const RESUME_INTERVAL  = 900;

	/**
	 * Seconds an in_flight claim is honoured before the row is quarantined.
	 */
	const LEASE_SECONDS = 600;

	/**
	 * Registered channel handlers.
	 *
	 * @var array Channel => callable.
	 */
	private static $handlers = array();

	/**
	 * Test seam: the time budget in seconds, honoured only inside the test harness (null = the real budget).
	 *
	 * @var float|null
	 */
	private static $budget_override = null;

	/**
	 * Hook the cron schedule, the dispatcher and the admin-side resume check.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'dispatch' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_resume' ) );
	}

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * CREATE TABLE statements for dbDelta.
	 *
	 * @return array
	 */
	public static function schema() {
		global $wpdb;
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();
		return array(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  channel varchar(20) NOT NULL,
  event_name varchar(64) NOT NULL,
  event_id varchar(120) NOT NULL,
  subject_type varchar(20) NOT NULL DEFAULT '',
  subject_id varchar(64) NOT NULL DEFAULT '',
  payload_json longtext NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  next_attempt_at datetime NOT NULL,
  last_error varchar(190) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY channel_event (channel,event_id),
  KEY due (status,next_attempt_at)
) {$collate};",
		);
	}

	/**
	 * Add the 5-minute cron interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'Every five minutes (DoughBoss Growth)',
		);
		return $schedules;
	}

	/**
	 * Register the handler for a channel. The handler receives the row as an array (with the decoded
	 * payload under "payload") and returns true when delivered, or a WP_Error to retry. A WP_Error whose
	 * error data contains terminal => true is parked immediately. Anything else counts as a retryable failure.
	 *
	 * @param string   $channel  Channel key, 2 to 20 of a-z 0-9 underscore.
	 * @param callable $callback Handler.
	 * @return bool False when the arguments are invalid (nothing is registered).
	 */
	public static function register_channel( $channel, $callback ) {
		if ( ! is_string( $channel ) || 1 !== preg_match( '/^[a-z0-9_]{2,20}$/D', $channel ) || ! is_callable( $callback ) ) {
			return false;
		}
		self::$handlers[ $channel ] = $callback;
		return true;
	}

	/**
	 * Registered channel keys.
	 *
	 * @return array
	 */
	public static function channels() {
		return array_keys( self::$handlers );
	}

	/**
	 * Forget every handler (test seam; production code never calls this).
	 *
	 * @return void
	 */
	public static function reset_handlers() {
		self::$handlers        = array();
		self::$budget_override = null;
	}

	/**
	 * Set the dispatch time budget (tests only; ignored outside the harness). Pass null to release it.
	 *
	 * @param float|int|null $seconds Budget in seconds, or null.
	 * @return void
	 */
	public static function set_time_budget_override( $seconds ) {
		if ( ! defined( 'DBGR_TESTING' ) ) {
			return;
		}
		self::$budget_override = ( null === $seconds ) ? null : max( 0.0, (float) $seconds );
	}

	/**
	 * Seconds a sweep may spend before it stops claiming rows.
	 *
	 * @return float
	 */
	private static function time_budget() {
		return ( null !== self::$budget_override ) ? self::$budget_override : (float) self::TIME_BUDGET_SECONDS;
	}

	/**
	 * List a failure for the owner (the Recent failures list on the Growth settings screen). Never throws.
	 *
	 * @param string $code    Failure code.
	 * @param array  $context Scalar facts without personal data.
	 * @return void
	 */
	private static function note_failure( $code, array $context ) {
		if ( class_exists( 'DoughBoss_Growth_Failures', false ) ) {
			DoughBoss_Growth_Failures::record( $code, $context );
		}
	}

	/**
	 * Backoff in seconds after the given number of failed attempts.
	 *
	 * @param int $attempts Failed attempts so far (1-based).
	 * @return int
	 */
	public static function backoff_for( $attempts ) {
		$index = max( 0, min( count( self::BACKOFF_SECONDS ) - 1, (int) $attempts - 1 ) );
		return self::BACKOFF_SECONDS[ $index ];
	}

	/**
	 * Whether a payload carries raw personal data. Checks keys (email, phone, names, address, IP, user
	 * agent and the short hashed-identifier keys) and email-shaped string values. Hashed identifiers
	 * are accepted only when allowed (the send_hashed_identifiers setting) and only as 64-char hex.
	 *
	 * @param mixed $payload        Payload.
	 * @param bool  $allow_hashed   Whether hashed identifiers are permitted.
	 * @return bool True when personal data is present.
	 */
	public static function payload_has_pii( $payload, $allow_hashed = false ) {
		if ( is_object( $payload ) ) {
			return true;
		}
		if ( ! is_array( $payload ) ) {
			return is_string( $payload ) && 1 === preg_match( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $payload );
		}
		foreach ( $payload as $key => $value ) {
			if ( is_string( $key ) ) {
				$lower = strtolower( $key );
				if ( 1 === preg_match( '/^(email|e_mail|e-mail|phone|mobile|telephone|first_name|last_name|full_name|customer_name|name|address|street|ip|ip_address|client_ip_address|user_agent|client_user_agent)$/D', $lower ) ) {
					return true;
				}
				if ( 1 === preg_match( '/^(em|ph|fn|ln|external_id)$/D', $lower ) ) {
					if ( ! $allow_hashed || ! self::is_hash_value( $value ) ) {
						return true;
					}
					continue;
				}
			}
			if ( self::payload_has_pii( $value, $allow_hashed ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Queue a delivery. Idempotent on (channel, event_id).
	 *
	 * @param string $channel      Channel key.
	 * @param string $event_name   Event name.
	 * @param string $event_id     Idempotency key, unique per channel.
	 * @param string $subject_type Subject type (for example "order").
	 * @param string $subject_id   Subject id.
	 * @param array  $payload      Payload without raw personal data.
	 * @return string queued, duplicate, rejected (invalid input or personal data) or error (storage).
	 */
	public static function enqueue( $channel, $event_name, $event_id, $subject_type, $subject_id, array $payload ) {
		if ( ! self::valid_token( $channel, '/^[a-z0-9_]{2,20}$/D' )
			|| ! self::valid_token( $event_name, '/^[a-z0-9_]{2,64}$/D' )
			|| ! self::valid_token( $event_id, '/^[A-Za-z0-9:_.\-]{1,120}$/D' )
			|| ! self::valid_token( $subject_type, '/^[a-z_]{0,20}$/D' )
			|| ! self::valid_token( $subject_id, '/^[A-Za-z0-9:_.\-]{0,64}$/D' ) ) {
			return 'rejected';
		}
		$allow_hashed = ( 1 === (int) DoughBoss_Growth_Settings::get( 'send_hashed_identifiers', 0 ) );
		if ( self::payload_has_pii( $payload, $allow_hashed ) ) {
			return 'rejected';
		}
		$json = wp_json_encode( $payload );
		if ( ! is_string( $json ) || strlen( $json ) > 60000 ) {
			return 'rejected';
		}

		global $wpdb;
		$table = self::table();
		$now   = gmdate( 'Y-m-d H:i:s', DoughBoss_Growth::now() );
		$rows  = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (channel, event_name, event_id, subject_type, subject_id, payload_json, status, attempts, next_attempt_at, last_error, created_at, updated_at) VALUES (%s, %s, %s, %s, %s, %s, 'pending', 0, %s, '', %s, %s)",
				$channel,
				$event_name,
				$event_id,
				$subject_type,
				$subject_id,
				$json,
				$now,
				$now,
				$now
			)
		);
		if ( false === $rows || '' !== (string) $wpdb->last_error ) {
			return 'error';
		}
		if ( 1 !== (int) $rows ) {
			return 'duplicate';
		}
		self::ensure_scheduled();
		return 'queued';
	}

	/**
	 * Make sure the dispatch cron event exists. A schedule that cannot be written (a locked cron option, a refused
	 * event) is listed for the owner: the row is queued either way, but nothing would send it.
	 *
	 * @return bool True when the event exists afterwards.
	 */
	public static function ensure_scheduled() {
		if ( false !== wp_next_scheduled( self::CRON_HOOK ) ) {
			return true;
		}
		$scheduled = wp_schedule_event( DoughBoss_Growth::now() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK );
		if ( false === $scheduled || is_wp_error( $scheduled ) ) {
			self::note_failure( 'outbox_schedule_failed', array( 'stage' => 'ensure_scheduled' ) );
			return false;
		}
		return true;
	}

	/**
	 * Admin-side safety net: if a channel handler is registered and rows are waiting but nothing is scheduled
	 * (the cron was cleared while a feature was off), schedule it again.
	 *
	 * It runs on every admin_init, which includes admin-ajax for logged-out visitors, and "a handler registered and no
	 * cron" is the normal idle state, so it must cost almost nothing: only a manager gets past the capability check, and a
	 * transient lets a manager count the waiting rows at most once every fifteen minutes. enqueue() already schedules the
	 * cron, so this only repairs the rare case where that was lost.
	 *
	 * @return void
	 */
	public static function maybe_resume() {
		if ( array() === self::$handlers || false !== wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		$can = class_exists( 'DoughBoss_Growth_Admin', false ) ? DoughBoss_Growth_Admin::user_can_manage() : current_user_can( 'manage_options' );
		if ( ! $can || false !== get_transient( self::RESUME_TRANSIENT ) ) {
			return;
		}
		set_transient( self::RESUME_TRANSIENT, 1, self::RESUME_INTERVAL );
		$waiting = self::waiting_count();
		if ( null !== $waiting && $waiting > 0 ) {
			self::ensure_scheduled();
		}
	}

	/**
	 * Rows waiting for a registered channel.
	 *
	 * @return int|null The count, or null when it could not be read (listed as a failure): a failed read is not "nothing is
	 *                  waiting", and a caller must not act on it as if it were.
	 */
	public static function waiting_count() {
		global $wpdb;
		$channels = self::channels();
		if ( array() === $channels ) {
			return 0;
		}
		$table        = self::table();
		$placeholders = implode( ', ', array_fill( 0, count( $channels ), '%s' ) );
		$count        = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = 'pending' AND channel IN ({$placeholders})",
				$channels
			)
		);
		if ( null === $count || '' !== (string) $wpdb->last_error ) {
			self::note_failure( 'outbox_read_failed', array( 'stage' => 'waiting_count' ) );
			return null;
		}
		return (int) $count;
	}

	/**
	 * Cron worker: quarantine stale claims, then claim and deliver due rows one at a time.
	 *
	 * @return array Counts: claimed, sent, retried, terminal, quarantined.
	 */
	public static function dispatch() {
		$summary = array(
			'claimed'     => 0,
			'sent'        => 0,
			'retried'     => 0,
			'terminal'    => 0,
			'quarantined' => 0,
		);
		if ( DoughBoss_Growth_Settings::kill_switch() ) {
			return $summary;
		}
		$started  = microtime( true );
		$channels = self::channels();
		if ( array() === $channels ) {
			// Nothing can be delivered while every feature that uses the outbox is off: stop the cron.
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return $summary;
		}

		global $wpdb;
		$table = self::table();
		$now   = DoughBoss_Growth::now();
		$ts    = gmdate( 'Y-m-d H:i:s', $now );

		// 1. Quarantine claims whose worker may have died after the provider accepted the request.
		$quarantined = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'failed_terminal', last_error = 'ambiguous_in_flight', updated_at = %s WHERE status = 'in_flight' AND updated_at < %s",
				$ts,
				gmdate( 'Y-m-d H:i:s', $now - self::LEASE_SECONDS )
			)
		);
		$summary['quarantined'] = ( false === $quarantined ) ? 0 : (int) $quarantined;
		if ( false === $quarantined ) {
			self::note_failure( 'outbox_update_failed', array( 'state' => 'quarantine' ) );
		}

		// 2. Candidate ids for registered channels only.
		$placeholders = implode( ', ', array_fill( 0, count( $channels ), '%s' ) );
		$args         = array_merge( $channels, array( $ts, self::BATCH ) );
		$ids          = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = 'pending' AND channel IN ({$placeholders}) AND next_attempt_at <= %s ORDER BY id ASC LIMIT %d",
				$args
			)
		);
		if ( ! is_array( $ids ) || '' !== (string) $wpdb->last_error ) {
			// An empty answer from a failed read is not "nothing is due". Nothing is claimed and the cron is left alone.
			self::note_failure( 'outbox_read_failed', array( 'stage' => 'due_rows' ) );
			return $summary;
		}

		foreach ( $ids as $id ) {
			if ( ( microtime( true ) - $started ) >= self::time_budget() ) {
				break; // Out of time: the rows not reached stay pending and go out on the next run.
			}
			$row = self::claim( (int) $id );
			if ( null === $row ) {
				continue; // Another worker won this row.
			}
			$summary['claimed']++;
			$final = self::record( $row, self::deliver( $row ) );
			if ( 'sent' === $final ) {
				$summary['sent']++;
			} elseif ( 'retry' === $final ) {
				$summary['retried']++;
			} else {
				$summary['terminal']++;
			}
		}

		if ( 0 === self::waiting_count() ) { // null (could not be read) is not 0: the cron stays so the rows are tried again.
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
		return $summary;
	}

	/**
	 * Atomically claim one row (pending to in_flight). Exactly one concurrent caller wins.
	 *
	 * @param int $id Row id.
	 * @return array|null The row, or null when the claim was lost or the row is gone.
	 */
	public static function claim( $id ) {
		global $wpdb;
		$table    = self::table();
		$claim_ts = gmdate( 'Y-m-d H:i:s', DoughBoss_Growth::now() );
		$affected = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'in_flight', updated_at = %s WHERE id = %d AND status = 'pending' AND next_attempt_at <= %s",
				$claim_ts,
				(int) $id,
				$claim_ts
			)
		);
		if ( 1 !== (int) $affected ) {
			return null;
		}
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			// The claim is ours but the row could not be read back, so no handler has seen it. Hand it back instead of leaving
			// it in flight, where the quarantine rule would park a row that was never sent as "ambiguous".
			self::note_failure( 'outbox_read_failed', array( 'stage' => 'claim' ) );
			$released = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'pending', updated_at = %s WHERE id = %d AND status = 'in_flight'",
					$claim_ts,
					(int) $id
				)
			);
			self::check_write( $released, array(), 'release' );
			return null;
		}
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Call the channel handler. Never throws.
	 *
	 * @param array $row Claimed row.
	 * @return array { state: sent|retry|terminal, error: string }
	 */
	private static function deliver( array $row ) {
		$channel = isset( $row['channel'] ) ? (string) $row['channel'] : '';
		if ( ! isset( self::$handlers[ $channel ] ) ) {
			return array(
				'state' => 'retry',
				'error' => 'no_handler',
			);
		}
		$payload = json_decode( isset( $row['payload_json'] ) ? (string) $row['payload_json'] : '', true );
		if ( ! is_array( $payload ) ) {
			return array(
				'state' => 'terminal',
				'error' => 'bad_payload',
			);
		}
		$row['payload'] = $payload;
		unset( $row['payload_json'] );
		try {
			$result = call_user_func( self::$handlers[ $channel ], $row );
		} catch ( Exception $e ) {
			return array(
				'state' => 'retry',
				'error' => 'handler_exception',
			);
		} catch ( Error $e ) {
			return array(
				'state' => 'retry',
				'error' => 'handler_error',
			);
		}
		if ( true === $result ) {
			return array(
				'state' => 'sent',
				'error' => '',
			);
		}
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			return array(
				'state' => ( is_array( $data ) && ! empty( $data['terminal'] ) ) ? 'terminal' : 'retry',
				'error' => preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $result->get_error_code() ) ),
			);
		}
		return array(
			'state' => 'retry',
			'error' => 'handler_failed',
		);
	}

	/**
	 * Persist the outcome of one delivery attempt.
	 *
	 * @param array $row     Claimed row.
	 * @param array $outcome From deliver().
	 * @return string The state the row ended in: sent, retry or terminal (a retry that used the last attempt is terminal).
	 */
	private static function record( array $row, array $outcome ) {
		global $wpdb;
		$table    = self::table();
		$id       = (int) $row['id'];
		$attempts = (int) $row['attempts'] + 1;
		$now      = DoughBoss_Growth::now();
		$ts       = gmdate( 'Y-m-d H:i:s', $now );
		$error    = substr( DoughBoss_Growth_Http::redact_text( $outcome['error'] ), 0, 190 );

		$state = $outcome['state'];
		if ( 'retry' === $state && $attempts >= self::MAX_ATTEMPTS ) {
			$state = 'terminal';
		}
		if ( 'sent' === $state ) {
			$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'sent', attempts = %d, last_error = '', updated_at = %s WHERE id = %d AND status = 'in_flight'",
					$attempts,
					$ts,
					$id
				)
			);
			self::check_write( $result, $row, 'sent' );
			return 'sent';
		}
		if ( 'terminal' === $state ) {
			$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'failed_terminal', attempts = %d, last_error = %s, updated_at = %s WHERE id = %d AND status = 'in_flight'",
					$attempts,
					$error,
					$ts,
					$id
				)
			);
			self::check_write( $result, $row, 'terminal' );
			return 'terminal';
		}
		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'pending', attempts = %d, last_error = %s, next_attempt_at = %s, updated_at = %s WHERE id = %d AND status = 'in_flight'",
				$attempts,
				$error,
				gmdate( 'Y-m-d H:i:s', $now + self::backoff_for( $attempts ) ),
				$ts,
				$id
			)
		);
		self::check_write( $result, $row, 'retry' );
		return 'retry';
	}

	/**
	 * List a failed state write. When the write after a delivery fails the row stays in_flight, and ten minutes later the
	 * quarantine rule parks it as "ambiguous" although it may have been delivered: the owner is told now, not then.
	 * An UPDATE that matched no row (another worker already moved it) is not an error.
	 *
	 * @param int|false $result Result of $wpdb->query().
	 * @param array     $row    The claimed row (for its channel), or an empty array.
	 * @param string    $state  The state the row was meant to reach.
	 * @return void
	 */
	private static function check_write( $result, array $row, $state ) {
		global $wpdb;
		if ( false !== $result && '' === (string) $wpdb->last_error ) {
			return;
		}
		$channel = isset( $row['channel'] ) ? (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $row['channel'] ) ) : '';
		$context = array( 'state' => $state );
		if ( '' !== $channel ) {
			$context['channel'] = $channel;
		}
		self::note_failure( 'outbox_update_failed', $context );
	}

	/**
	 * Whether a value is a string matching a pattern.
	 *
	 * @param mixed  $value   Value.
	 * @param string $pattern Regex.
	 * @return bool
	 */
	private static function valid_token( $value, $pattern ) {
		return is_string( $value ) && 1 === preg_match( $pattern, $value );
	}

	/**
	 * Whether a value is a 64-char lowercase hex digest.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_hash_value( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
	}
}
