<?php
/**
 * POSPal push outbox — durable retry for the till mirror.
 *
 * Before this module, `DoughBoss_POSPal_Orders::on_order_created` fire-and-forgot
 * the addOnLineOrder call: any POSPal blip silently dropped the till copy of the
 * order. The customer-facing order still committed to WordPress (that path was
 * never blocking on POSPal), but the kitchen till stayed empty.
 *
 * This class introduces a small, WP-native outbox that owns the push:
 *   1. Every POSPal push intent is inserted as a row (kind, entity, payload, idempotency key).
 *   2. `wp_schedule_single_event` fires a cron worker at the row's `next_attempt_at`.
 *   3. On an explicit remote failure, the row is re-scheduled with exponential backoff
 *      (60s → 300s → 1800s → 1800s → 1800s, capped at 5 attempts).
 *      Ambiguous network outcomes stop for human review rather than risk a duplicate.
 *   4. On success, the row is marked 'succeeded' and kept 30 days for audit/visibility.
 *   5. After 5 failures, the row is marked 'failed_terminal' and surfaced in wp-admin.
 *
 * Idempotency: each row carries an `idempotency_key` derived from the WP order id
 * plus the store index. A duplicate enqueue for the same key is rejected at insert
 * time (UNIQUE), so duplicate local enqueue calls share the same row. It does
 * does not prove that an ambiguous remote timeout was rejected by POSPal.
 * Identified successes retain POSPal's stable order number for later positive
 * reconciliation; ambiguous responses remain quarantined for operator review.
 *
 * Fully dormant unless `DoughBoss_Settings::pospal_push_enabled()` is true — the
 * cron worker no-ops, and callers are simply free to skip enqueue when it's off.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Durable outbox for POSPal pushes. Static; register via init().
 */
class DoughBoss_POSPal_Outbox {

	/**
	 * The 'order_push' kind mirrors an online order onto the till. The only kind
	 * shipped in the initial slice — voucher grant/revoke still ride their own
	 * (best-effort) code paths so this table stays purely order-focused.
	 */
	const KIND_ORDER_PUSH = 'order_push';

	/**
	 * Backoff schedule in seconds. attempt 1 fails → wait 60s; 2 → 300s; 3+ → 1800s.
	 * Capped at MAX_ATTEMPTS total, after which the row is marked failed_terminal.
	 */
	const BACKOFF_SECONDS = array( 60, 300, 1800, 1800, 1800 );

	/**
	 * Absolute cap on attempts before we stop retrying and surface the row for
	 * the operator instead. Matches the client memo's promise ("stops after five
	 * tries") — kept short so a stuck row doesn't loop indefinitely.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Cron hook fired by wp_schedule_single_event when a row's next_attempt_at
	 * arrives. Batches all currently-due rows into a single worker sweep.
	 */
	const CRON_HOOK = 'doughboss_pospal_outbox_dispatch';

	/**
	 * Hourly maintenance hook. It prunes old successful rows and positively
	 * verifies recent identified pushes. It never automatically re-pushes an
	 * absent, mismatched or otherwise inconclusive lookup.
	 */
	const RECONCILE_HOOK = 'doughboss_pospal_outbox_reconcile';

	/**
	 * Register the cron worker + the order-created hook. Safe to call always —
	 * the worker self-gates and every enqueue path already checks `pospal_push_enabled()`.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_due' ) );
		add_action( self::RECONCILE_HOOK, array( __CLASS__, 'reconcile_recent' ) );
		add_action( 'init', array( __CLASS__, 'ensure_reconcile_scheduled' ) );
		add_action( 'init', array( __CLASS__, 'ensure_dispatch_scheduled' ) );
	}

	/**
	 * Register the hourly reconciliation cron once. Idempotent — wp-cron only
	 * schedules the recurring event if it isn't already booked. Only booked when
	 * POSPal push is switched on, so a plain WP install stays clean.
	 *
	 * @return void
	 */
	public static function ensure_reconcile_scheduled() {
		if ( ! DoughBoss_Settings::pospal_push_enabled() ) {
			// Unschedule if it was booked and push was later switched off.
			$existing = wp_next_scheduled( self::RECONCILE_HOOK );
			if ( $existing ) {
				wp_unschedule_event( $existing, self::RECONCILE_HOOK );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::RECONCILE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::RECONCILE_HOOK );
		}
	}

	/**
	 * Table name (plugin-owned, derived from wpdb prefix).
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_pospal_outbox';
	}

	/**
	 * Enqueue a POSPal push. Idempotent: a second call with the same idempotency
	 * key returns the existing row id and does not schedule a duplicate cron.
	 *
	 * @param string $kind            Row kind (see KIND_* constants).
	 * @param int    $entity_id       Related entity id (e.g. WP order id) or 0.
	 * @param int    $store_index     1/2/3 — which POSPal store this is for.
	 * @param array  $payload         The exact body to send (minus appId, which the
	 *                                signed call() adds at dispatch time).
	 * @param string $idempotency_key Stable dedupe key; a second enqueue with the
	 *                                same key is treated as an existing row.
	 * @return int|false Inserted (or existing) row id, or false on hard DB failure.
	 */
	public static function enqueue( $kind, $entity_id, $store_index, array $payload, $idempotency_key ) {
		global $wpdb;

		$kind    = sanitize_key( (string) $kind );
		$key     = sanitize_text_field( (string) $idempotency_key );
		$store   = max( 1, (int) $store_index );
		$entity  = max( 0, (int) $entity_id );
		$table   = self::table();

		if ( '' === $kind || '' === $key ) {
			return false;
		}

		$json = wp_json_encode( $payload );
		if ( false === $json ) {
			return false;
		}

		// Idempotency: return the existing row id if we've already enqueued this key.
		$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT id FROM {$table} WHERE idempotency_key = %s", $key )
		);
		if ( $existing ) {
			return (int) $existing;
		}

		// Timestamps: UTC everywhere. Every other timestamp write in this class also
		// uses gmdate() so a comparison of `next_attempt_at <= UTC_TIMESTAMP()` stays
		// consistent on non-UTC sites (mixing `current_time('mysql')` with `gmdate`
		// would offset retries by the site's UTC offset).
		$now_utc = gmdate( 'Y-m-d H:i:s' );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			array(
				'kind'             => $kind,
				'entity_id'        => $entity,
				'store_index'      => $store,
				'payload_json'     => $json,
				'idempotency_key'  => $key,
				'attempts'         => 0,
				'status'           => 'pending',
				'last_error'       => '',
				'next_attempt_at'  => $now_utc,
				'created_at'       => $now_utc,
				'updated_at'       => $now_utc,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( ! $inserted ) {
			return false;
		}

		$id = (int) $wpdb->insert_id;
		self::schedule_soon( 0 );
		return $id;
	}

	/**
	 * Enqueue an order_push from a WP order id + already-built POSPal body. Thin
	 * convenience over enqueue() with the stable "order:{id}:store:{n}" key.
	 *
	 * @param int   $order_id     WP order id.
	 * @param int   $store_index  1/2/3.
	 * @param array $body         Fully-built addOnLineOrder body.
	 * @return int|false Row id or false.
	 */
	public static function enqueue_order_push( $order_id, $store_index, array $body ) {
		$order_id    = absint( $order_id );
		$store_index = max( 1, (int) $store_index );
		if ( ! $order_id ) {
			return false;
		}
		$key = 'order:' . $order_id . ':store:' . $store_index;
		return self::enqueue( self::KIND_ORDER_PUSH, $order_id, $store_index, $body, $key );
	}

	/**
	 * Schedule a run_due sweep in $offset seconds (default: as soon as possible).
	 * Idempotent — wp_schedule_single_event silently no-ops on a duplicate schedule
	 * within 10 minutes, so callers can enqueue freely without deduping themselves.
	 *
	 * @param int $offset Seconds from now.
	 * @return void
	 */
	public static function schedule_soon( $offset = 0 ) {
		$when = time() + max( 0, (int) $offset );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( $when, self::CRON_HOOK );
			return;
		}
		// If our next scheduled tick is after $when, bring it forward.
		$existing = wp_next_scheduled( self::CRON_HOOK );
		if ( $existing && $existing > $when ) {
			wp_unschedule_event( $existing, self::CRON_HOOK );
			wp_schedule_single_event( $when, self::CRON_HOOK );
		}
	}

	/**
	 * Re-arm durable work after activation, restart or cron loss.
	 *
	 * Deactivation deliberately clears scheduled hooks but preserves the outbox.
	 * On the next active request, schedule the earliest pending row or an immediate
	 * sweep when an abandoned in-flight lease needs to be quarantined for review.
	 *
	 * @return void
	 */
	public static function ensure_dispatch_scheduled() {
		global $wpdb;

		if ( ! DoughBoss_Settings::pospal_push_enabled() ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return;
		}
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		$table        = self::table();
		$lease_cutoff = gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS );
		$stale        = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = 'in_flight' AND updated_at < %s",
				$lease_cutoff
			)
		);
		if ( $stale > 0 ) {
			self::schedule_soon( 0 );
			return;
		}

		self::schedule_next_pending();
	}

	/**
	 * Arm the worker for the earliest pending retry in the database.
	 *
	 * A sweep may process a newly-enqueued row while older failures are not due
	 * yet. Deriving the next wake from the whole queue prevents those future
	 * retries being stranded after the current cron event is consumed.
	 *
	 * @return void
	 */
	private static function schedule_next_pending() {
		global $wpdb;
		$table = self::table();
		$next  = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT MIN(next_attempt_at) FROM {$table} WHERE status = 'pending'"
		);
		if ( ! $next ) {
			return;
		}
		$next_ts = strtotime( (string) $next . ' UTC' );
		if ( false !== $next_ts ) {
			self::schedule_soon( max( 60, $next_ts - time() ) );
		}
	}

	/**
	 * Cron worker: dispatch every row whose next_attempt_at is due. Capped at 25
	 * rows per sweep so a large backlog can't monopolise a WP request; anything
	 * left is picked up by the next scheduled sweep.
	 *
	 * @return void
	 */
	public static function run_due() {
		global $wpdb;

		if ( ! DoughBoss_Settings::pospal_push_enabled() ) {
			return;
		}

		$table  = self::table();
		$now    = gmdate( 'Y-m-d H:i:s' );
		$batch  = 25;

		// 1. Quarantine orphaned in_flight rows. A previous worker may have died
		// after POSPal accepted the request but before the success row was saved.
		// Automatic re-push would risk a duplicate till order, so require an operator
		// to check POSPal before using the manual resend control.
		$lease_cutoff = gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"UPDATE {$table}
				   SET status = 'failed_terminal', last_error = 'ambiguous_in_flight', updated_at = %s
				 WHERE status = 'in_flight' AND updated_at < %s",
				$now,
				$lease_cutoff
			)
		);

		// 2. Grab candidate ids up to the batch size.
		$ids = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE status = 'pending'
				   AND next_attempt_at <= %s
				 ORDER BY id ASC
				 LIMIT %d",
				$now,
				$batch
			)
		);
		if ( empty( $ids ) ) {
			self::schedule_next_pending();
			return;
		}

		$claimed = 0;
		foreach ( $ids as $id ) {
			// Atomic claim: only one worker can flip pending -> in_flight for a given
			// row. A concurrent sweep (WP-CLI cron event run, second frontend) will
			// see 0 rows updated and skip this row cleanly — no double-push.
			//
			// The lease timestamp is taken fresh PER ROW (not the sweep-start $now),
			// and next_attempt_at is re-checked here too: with up to 25 blocking HTTP
			// calls a sweep can run long enough that a row selected earlier is no
			// longer due (e.g. another worker retried and re-scheduled it further
			// out) by the time this claim runs.
			$claim_ts = gmdate( 'Y-m-d H:i:s' );
			$affected = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare(
					"UPDATE {$table}
					   SET status = 'in_flight', updated_at = %s
					 WHERE id = %d AND status = 'pending' AND next_attempt_at <= %s",
					$claim_ts,
					(int) $id,
					$claim_ts
				)
			);
			if ( 1 !== (int) $affected ) {
				continue;
			}
			$claimed++;
			$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id )
			);
			if ( ! $row ) {
				continue;
			}
			self::dispatch_row( $row );
		}

		// If we hit the batch cap and still had claims, there may be more waiting —
		// re-arm a fast sweep so the backlog drains without a full cron interval wait.
		if ( count( $ids ) === $batch && $claimed > 0 ) {
			self::schedule_soon( 60 );
			return;
		}

		// Recompute from the complete queue rather than only the rows touched in
		// this batch; another pending row may have a later retry time.
		self::schedule_next_pending();
	}

	/**
	 * Dispatch a single outbox row: blocking POSPal call (we need the response to
	 * know pass/fail), then success-mark or backoff-and-retry.
	 *
	 * @param object $row Outbox row.
	 * @return int Unix timestamp of the next scheduled retry (0 if no retry).
	 */
	private static function dispatch_row( $row ) {
		global $wpdb;

		$table   = self::table();
		$id      = (int) $row->id;
		$now_utc = gmdate( 'Y-m-d H:i:s' );
		$store_n = max( 1, (int) $row->store_index );
		$store   = DoughBoss_Settings::pospal_store( $store_n );
		$payload = json_decode( (string) $row->payload_json, true );
		if ( ! is_array( $payload ) ) {
			self::mark_terminal( $id, 'invalid_payload', (int) $row->attempts + 1 );
			return 0;
		}

		$creds = array(
			'host'    => (string) $store['host'],
			'app_id'  => (string) $store['app_id'],
			'app_key' => (string) $store['app_key'],
		);
		if ( '' === $creds['host'] || '' === $creds['app_id'] || '' === $creds['app_key'] ) {
			// Store not configured (or was un-configured after enqueue). Release the
			// row back to pending so a later sweep retries once creds are in place.
			$next_ts = time() + 30 * MINUTE_IN_SECONDS;
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$table,
				array(
					'status'          => 'pending',
					'last_error'      => 'store_unconfigured',
					'next_attempt_at' => gmdate( 'Y-m-d H:i:s', $next_ts ),
					'updated_at'      => $now_utc,
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			return $next_ts;
		}

		// Blocking call so we get the actual pass/fail: fire-and-forget was the
		// bug we're fixing here, and this cron path is off the customer request.
		$result   = DoughBoss_POSPal::push_order( $payload, $creds, true );
		$attempts = (int) $row->attempts + 1;

		if ( ! is_wp_error( $result ) ) {
			$pospal_no = ( is_array( $result ) && isset( $result['orderNo'] ) ) ? sanitize_text_field( (string) $result['orderNo'] ) : '';
			if ( ! preg_match( '/^[A-Za-z0-9._:-]{1,64}$/', $pospal_no ) ) {
				// A success response without POSPal's stable identifier is ambiguous:
				// it must be checked at the till before any manual resend.
				self::mark_terminal( $id, 'ambiguous_missing_order_no', $attempts );
				return 0;
			}
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$table,
				array(
					'status'      => 'succeeded',
					'attempts'    => $attempts,
					'last_error'  => '',
					'remote_reference' => $pospal_no,
					'updated_at'  => $now_utc,
				),
				array( 'id' => $id ),
				array( '%s', '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);
			// Log the POSPal-assigned orderNo (its own identifier, not ours): this is
			// the key reconciliation needs to persist and query by — see the
			// re-enable contract on reconcile_recent(). Safe to log: a till sequence
			// number, no PII.
			self::log(
				'#' . $id . ' pushed (attempt ' . $attempts . ')'
				. ( '' !== $pospal_no ? ' — POSPal orderNo ' . $pospal_no : '' )
			);
			return 0;
		}

		$error_code = substr( (string) $result->get_error_code(), 0, 64 );
		if ( 'doughboss_pospal_network' === $error_code ) {
			// A transport timeout/error does not prove POSPal rejected the order. Stop
			// here so staff can check the till before deliberately resending it.
			self::mark_terminal( $id, 'ambiguous_network', $attempts );
			return 0;
		}

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			self::mark_terminal( $id, $error_code, $attempts );
			return 0;
		}

		// Backoff index is 0-based; attempts 1..N maps to BACKOFF_SECONDS[0..N-1].
		$idx     = min( $attempts - 1, count( self::BACKOFF_SECONDS ) - 1 );
		$delay   = (int) self::BACKOFF_SECONDS[ $idx ];
		$next_ts = time() + $delay;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			array(
				'status'          => 'pending',
				'attempts'        => $attempts,
				'last_error'      => $error_code,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', $next_ts ),
				'updated_at'      => $now_utc,
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
		self::log( '#' . $id . ' failed (' . $error_code . ') — retry in ' . $delay . 's' );
		return $next_ts;
	}

	/**
	 * Mark a row failed after MAX_ATTEMPTS so it stops retrying and surfaces in
	 * the admin notice for a human to handle.
	 *
	 * @param int    $id       Row id.
	 * @param string $error    Last error code.
	 * @param int    $attempts Attempts used.
	 * @return void
	 */
	private static function mark_terminal( $id, $error, $attempts = 0 ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array(
				'status'      => 'failed_terminal',
				'attempts'    => (int) $attempts,
				'last_error'  => substr( sanitize_text_field( (string) $error ), 0, 64 ),
				'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
		self::log( '#' . (int) $id . ' terminal (' . $error . ')' );
	}

	/**
	 * Count of rows the operator should see in wp-admin: anything terminal, plus
	 * anything still pending but past its 3rd attempt (a warning, not just a fail).
	 *
	 * @return array{ terminal:int, retryable_terminal:int, ambiguous:int, retrying:int }
	 */
	public static function counts_for_alert() {
		global $wpdb;
		$table    = self::table();
		$terminal = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$table} WHERE status = 'failed_terminal'"
		);
		$ambiguous = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$table} WHERE status = 'failed_terminal' AND last_error IN ('ambiguous_network', 'ambiguous_in_flight', 'ambiguous_missing_order_no')"
		);
		$retrying = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$table} WHERE status = 'pending' AND attempts >= 3"
		);
		return array(
			'terminal'           => $terminal,
			'retryable_terminal' => max( 0, $terminal - $ambiguous ),
			'ambiguous'          => $ambiguous,
			'retrying'           => $retrying,
		);
	}

	/**
	 * List rows for the admin visibility notice (most recent problems first).
	 *
	 * @param int $limit Max rows to return.
	 * @return array<int,object>
	 */
	public static function list_problem_rows( $limit = 20 ) {
		global $wpdb;
		$table = self::table();
		$limit = max( 1, min( 100, (int) $limit ) );
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE status = 'failed_terminal'
				    OR ( status = 'pending' AND attempts >= 3 )
				 ORDER BY updated_at DESC
				 LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * List ambiguous rows separately so ordinary retries cannot hide a till-check
	 * action from the operator notice.
	 *
	 * @param int $limit Max rows to return.
	 * @return array<int,object>
	 */
	public static function list_ambiguous_rows( $limit = 100 ) {
		global $wpdb;
		$table = self::table();
		$limit = max( 1, min( 500, (int) $limit ) );
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE status = 'failed_terminal'
				   AND last_error IN ('ambiguous_network', 'ambiguous_in_flight', 'ambiguous_missing_order_no')
				 ORDER BY updated_at ASC
				 LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Manual re-push: reset a row (or safe failed_terminal rows) to pending so the
	 * next cron sweep re-tries. Bulk retry always excludes ambiguous transport or
	 * abandoned-worker outcomes. An individual ambiguous row can be released only
	 * after the admin handler records the operator's explicit till check.
	 *
	 * @param int|null $id                  Row id, or null to reset safe terminal rows.
	 * @param bool     $allow_ambiguous     Whether an explicitly selected ambiguous row may be reset.
	 * @param string   $expected_updated_at Exact attempt timestamp from the confirmed operator form.
	 * @param string   $expected_error      Exact ambiguous state from the confirmed operator form.
	 * @return int Rows updated.
	 */
	public static function reset_for_retry( $id = null, $allow_ambiguous = false, $expected_updated_at = '', $expected_error = '' ) {
		global $wpdb;
		$table = self::table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		if ( null !== $id ) {
			if ( $allow_ambiguous ) {
				$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->prepare(
						"SELECT payload_json, last_error, updated_at FROM {$table} WHERE id = %d AND status = 'failed_terminal'",
						(int) $id
					)
				);
				$payload = $row ? json_decode( (string) $row->payload_json, true ) : null;
				if (
					! $row
					|| ! in_array( (string) $row->last_error, array( 'ambiguous_network', 'ambiguous_in_flight', 'ambiguous_missing_order_no' ), true )
					|| ! is_array( $payload )
					|| empty( $payload['daySeq'] )
					|| (string) $row->updated_at !== (string) $expected_updated_at
					|| (string) $row->last_error !== (string) $expected_error
				) {
					return 0;
				}
			}
			$ambiguity_guard = $allow_ambiguous ? ' AND last_error = %s AND updated_at = %s' : " AND last_error NOT IN ('ambiguous_network', 'ambiguous_in_flight', 'ambiguous_missing_order_no')";
			$prepare_args    = array( $now, $now, (int) $id );
			if ( $allow_ambiguous ) {
				$prepare_args[] = (string) $expected_error;
				$prepare_args[] = (string) $expected_updated_at;
			}
			$updated = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare(
					"UPDATE {$table}
					    SET status = 'pending', attempts = 0, last_error = '',
					        next_attempt_at = %s, updated_at = %s
					  WHERE id = %d AND status = 'failed_terminal'{$ambiguity_guard}",
					$prepare_args
				)
			);
		} else {
			// Reset every terminal row in one query — the operator's "resend all" path.
			$updated = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare(
					"UPDATE {$table}
					   SET status = 'pending', attempts = 0, last_error = '',
					       next_attempt_at = %s, updated_at = %s
					 WHERE status = 'failed_terminal'
					   AND last_error NOT IN ('ambiguous_network', 'ambiguous_in_flight', 'ambiguous_missing_order_no')",
					$now,
					$now
				)
			);
		}

		if ( $updated > 0 ) {
			if ( null !== $id && $allow_ambiguous ) {
				self::log( '#' . (int) $id . ' released for manual retry after operator till-check confirmation' );
			}
			self::schedule_soon( 0 );
		}
		return $updated;
	}

	/**
	 * Hourly maintenance for recent successful rows.
	 *
	 * Reconcile only rows that retain POSPal's stable orderNo. An absent or
	 * inconclusive lookup is never treated as absence and never triggers a re-push.
	 *
	 * @return void
	 */
	public static function reconcile_recent() {
		global $wpdb;

		// Housekeeping runs even when push is currently disabled: rows created
		// while push WAS enabled must still age out. (The hook itself is only
		// scheduled while push is on — see ensure_reconcile_scheduled() — but a
		// final sweep before unscheduling, or a WP-CLI invocation, still prunes.)
		$pruned = self::prune_succeeded( 30 );
		if ( $pruned > 0 ) {
			self::log( 'reconcile pruned ' . $pruned . ' succeeded row(s) older than 30 days' );
		}

		if ( ! DoughBoss_Settings::pospal_push_enabled() ) {
			return;
		}

		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS );
		// Only reconcile a small window: this call talks to POSPal once per row, so
		// keep the batch small. The hourly cadence with a 2-hour lookback still
		// double-checks every recent order at least once before it ages out.
		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE status = 'succeeded'
				   AND remote_reference <> ''
				   AND updated_at >= %s
				 ORDER BY updated_at ASC
				 LIMIT 25",
				$since
			)
		);
		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$store = DoughBoss_Settings::pospal_store( max( 1, (int) $row->store_index ) );
			$creds = array( 'host' => (string) $store['host'], 'app_id' => (string) $store['app_id'], 'app_key' => (string) $store['app_key'] );
			if ( '' === $creds['host'] || '' === $creds['app_id'] || '' === $creds['app_key'] ) {
				continue;
			}
			$check = DoughBoss_POSPal::query_order_by_no( (string) $row->remote_reference, $creds );
			if ( is_wp_error( $check ) || ! is_array( $check ) || (string) ( $check['orderNo'] ?? '' ) !== (string) $row->remote_reference ) {
				self::log( '#' . (int) $row->id . ' reconciliation inconclusive; no re-push performed' );
				continue;
			}
			self::log( '#' . (int) $row->id . ' reconciliation confirmed' );
		}
	}

	/**
	 * Prune rows older than $days days that already succeeded — kept for a small
	 * audit window (30 days by default) then removed so this table stays tiny.
	 * Terminal rows are never pruned automatically; the operator clears them.
	 *
	 * @param int $days Age in days.
	 * @return int Rows deleted.
	 */
	public static function prune_succeeded( $days = 30 ) {
		global $wpdb;
		$table    = self::table();
		$days     = max( 1, (int) $days );
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status = 'succeeded' AND updated_at < %s",
				$cutoff
			)
		);
	}

	/**
	 * Operator log line (status + row id only — never payload/PII/secrets).
	 *
	 * @param string $message Short status string.
	 * @return void
	 */
	private static function log( $message ) {
		if ( function_exists( 'error_log' ) ) {
			error_log( 'DoughBoss POSPal outbox: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
