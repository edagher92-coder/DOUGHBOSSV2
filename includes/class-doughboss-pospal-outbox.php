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
 *   3. On failure, the row is re-scheduled with exponential backoff
 *      (60s → 300s → 1800s → 1800s → 1800s, capped at 5 attempts).
 *   4. On success, the row is marked 'succeeded' and kept 30 days for audit/visibility.
 *   5. After 5 failures, the row is marked 'failed_terminal' and surfaced in wp-admin.
 *
 * Idempotency: each row carries an `idempotency_key` derived from the WP order id
 * plus the store index. A duplicate enqueue for the same key is rejected at insert
 * time (UNIQUE), so a retry cascade or a reconciliation cron cannot double-push.
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
	 * Hourly cron hook for outbox housekeeping (pruning long-succeeded rows).
	 *
	 * This hook originally also "reconciled" succeeded rows against POSPal's
	 * queryOrderByNo and auto-requeued anything that looked missing. That check
	 * was disabled deliberately: the lookup key it used (our WP order number,
	 * sent as `daySeq`) is NOT the `orderNo` POSPal assigns and expects in
	 * queryOrderByNo, so every lookup was against an identifier POSPal never
	 * issued. Depending on POSPal's not-found response shape that made the
	 * check either a permanent no-op (error responses) or, worse, an hourly
	 * auto-requeue of orders that had in fact landed — i.e. duplicate ring-ins
	 * on the till. See reconcile_recent() for the re-enable requirements.
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
	}

	/**
	 * Register the hourly housekeeping cron once. Idempotent — wp-cron only
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

		// 1. Reclaim orphaned in_flight rows — a previous worker died mid-dispatch
		// (crash, timeout, SIGKILL). Rows older than the lease window are safe to
		// take back; wp_cron's default max_execution guarantees this is always past.
		$lease_cutoff = gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"UPDATE {$table}
				   SET status = 'pending', updated_at = %s
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
			return;
		}

		$next_wake = 0;
		$claimed   = 0;
		foreach ( $ids as $id ) {
			// Atomic claim: only one worker can flip pending -> in_flight for a given
			// row. A concurrent sweep (WP-CLI cron event run, second frontend) will
			// see 0 rows updated and skip this row cleanly — no double-push.
			//
			// The lease timestamp is taken fresh PER ROW (not the sweep-start $now):
			// with 25 blocking HTTP calls a sweep can outlive the 10-minute reclaim
			// window, and a stale sweep-start stamp would let a concurrent worker
			// reclaim a row that is still genuinely in flight. The claim also
			// re-checks next_attempt_at so a row selected by an earlier query but
			// already retried-and-backed-off by another worker isn't pushed early.
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
			$scheduled_at = self::dispatch_row( $row );
			if ( $scheduled_at && ( 0 === $next_wake || $scheduled_at < $next_wake ) ) {
				$next_wake = $scheduled_at;
			}
		}

		// If we hit the batch cap and still had claims, there may be more waiting —
		// re-arm a fast sweep so the backlog drains without a full cron interval wait.
		if ( count( $ids ) === $batch && $claimed > 0 ) {
			self::schedule_soon( 60 );
			return;
		}

		if ( $next_wake ) {
			self::schedule_soon( max( 60, $next_wake - time() ) );
		}
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
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$table,
				array(
					'status'      => 'succeeded',
					'attempts'    => $attempts,
					'last_error'  => '',
					'updated_at'  => $now_utc,
				),
				array( 'id' => $id ),
				array( '%s', '%d', '%s', '%s' ),
				array( '%d' )
			);
			// Log the POSPal-assigned orderNo (its own identifier, not ours): this is
			// the key a future reconciliation must persist and query by. Safe to log —
			// a till sequence number, no PII. See reconcile_recent() for the plan.
			$pospal_no = ( is_array( $result ) && isset( $result['orderNo'] ) ) ? (string) $result['orderNo'] : '';
			self::log(
				'#' . $id . ' pushed (attempt ' . $attempts . ')'
				. ( '' !== $pospal_no ? ' — POSPal orderNo ' . $pospal_no : '' )
			);
			return 0;
		}

		$error_code = substr( (string) $result->get_error_code(), 0, 64 );

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
	 * @return array{ terminal:int, retrying:int }
	 */
	public static function counts_for_alert() {
		global $wpdb;
		$table    = self::table();
		$terminal = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$table} WHERE status = 'failed_terminal'"
		);
		$retrying = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$table} WHERE status = 'pending' AND attempts >= 3"
		);
		return array( 'terminal' => $terminal, 'retrying' => $retrying );
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
	 * Manual re-push: reset a row (or all failed_terminal rows) to pending so the
	 * next cron sweep re-tries. Called from the admin "Re-send" button.
	 *
	 * @param int|null $id Row id, or null to reset every failed_terminal row.
	 * @return int Rows updated.
	 */
	public static function reset_for_retry( $id = null ) {
		global $wpdb;
		$table = self::table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		if ( null !== $id ) {
			$updated = (int) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$table,
				array(
					'status'          => 'pending',
					'attempts'        => 0,
					'last_error'      => '',
					'next_attempt_at' => $now,
					'updated_at'      => $now,
				),
				array( 'id' => (int) $id ),
				array( '%s', '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			// Reset every terminal row in one query — the operator's "resend all" path.
			$updated = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare(
					"UPDATE {$table}
					   SET status = 'pending', attempts = 0, last_error = '',
					       next_attempt_at = %s, updated_at = %s
					 WHERE status = 'failed_terminal'",
					$now,
					$now
				)
			);
		}

		if ( $updated > 0 ) {
			self::schedule_soon( 0 );
		}
		return $updated;
	}

	/**
	 * Hourly outbox housekeeping. Currently: prune long-succeeded rows only.
	 *
	 * The original "safety-net reconciliation" this hook shipped with — query
	 * POSPal `queryOrderByNo` for every recently-succeeded row and auto-requeue
	 * anything that looked missing — has been REMOVED, deliberately, because it
	 * was verifying against the wrong identifier:
	 *
	 *   - The lookup key it used was our WP order number (the value build_body()
	 *     sends as `daySeq`), but `queryOrderByNo` takes POSPal's own assigned
	 *     `orderNo` — which the push RESPONSE returns and dispatch_row() never
	 *     persisted. POSPal never issued the key we were querying with.
	 *   - Depending on POSPal's (uncaptured, unverified) not-found response
	 *     shape, that made the check either a permanent silent no-op (WP_Error
	 *     branch skipped every row) or an unbounded hourly duplicate ring-in
	 *     loop: success-with-empty-data satisfied the "missing" test, the row
	 *     was requeued with attempts reset to 0, re-pushed, re-succeeded, and
	 *     re-entered the reconcile window — forever.
	 *
	 * Re-enable contract (do NOT restore the old loop without ALL of these):
	 *   1. Persist the POSPal-assigned `orderNo` from the push response onto the
	 *      outbox row (new column: activator create_tables() + ordered migration
	 *      + DB version bump per CLAUDE.md), and query by THAT key.
	 *   2. Capture POSPal's real not-found response against a live/staging till
	 *      and encode it as a fixture — the "missing" test must match a verified
	 *      shape, not an assumed one.
	 *   3. On a confirmed miss, do not auto-requeue: surface the row for human
	 *      confirmation (the failed_terminal + admin-notice + Re-send pattern
	 *      already exists), so an ambiguous response can never re-ring an order.
	 *
	 * Until then, lost-after-success pushes are covered by the operator-visible
	 * failure path (counts_for_alert/list_problem_rows + manual Re-send), and
	 * dispatch_row() logs the POSPal orderNo on every success so real response
	 * shapes accumulate in the logs for step 2.
	 *
	 * @return void
	 */
	public static function reconcile_recent() {
		// Housekeeping runs even when push is currently disabled: rows created
		// while push WAS enabled must still age out. (The hook itself is only
		// scheduled while push is on — see ensure_reconcile_scheduled() — but a
		// final sweep before unscheduling, or a WP-CLI invocation, still prunes.)
		$pruned = self::prune_succeeded( 30 );
		if ( $pruned > 0 ) {
			self::log( 'housekeeping pruned ' . $pruned . ' succeeded row(s) older than 30 days' );
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
