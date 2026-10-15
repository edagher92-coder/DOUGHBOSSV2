<?php
/**
 * Durable, provider-neutral payment attempt repository.
 *
 * This is intentionally a persistence boundary, not a gateway adapter. It
 * records the stable facts needed to reconcile a payment without retaining a
 * gateway request, response, pay secret, PAN, CVC or any other card data.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	 exit;
}

/**
 * Stores payment attempts and claims provider events exactly once.
 */
class DoughBoss_Payment_Attempts {

	/** Record an exact split Order identity only; never attest to provider payment. */
	public static function bind_split_square_order( $attempt_id, $order_reference, array $expected ) {
		return DoughBoss_Payment_Legs::bind_order( $attempt_id, $order_reference, $expected );
	}

	/**
	 * Return the plugin-owned payment attempts table.
	 *
	 * @return string
	 */
	 public static function table() {
		 global $wpdb;
		 return $wpdb->prefix . 'doughboss_payment_attempts';
	 }

	/**
	 * Return the plugin-owned event de-duplication table.
	 *
	 * The table deliberately stores an event key and a short outcome only. Raw
	 * webhook bodies can contain payment details and must never be persisted.
	 *
	 * @return string
	 */
	 public static function events_table() {
		 global $wpdb;
		 return $wpdb->prefix . 'doughboss_payment_events';
	 }

	/**
	 * Find an attempt by its database id.
	 *
	 * @param int $attempt_id Attempt id.
	 * @return array|null
	 */
	 public static function find( $attempt_id ) {
		 global $wpdb;
		 $attempt_id = absint( $attempt_id );
		 if ( ! $attempt_id ) {
			 return null;
		 }
		 // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		 $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id = %d LIMIT 1", $attempt_id ), ARRAY_A );
		 return is_array( $row ) ? $row : null;
	 }

	/**
	 * Find the one attempt belonging to a provider reference.
	 *
	 * Provider references are globally unique in the durable table. A provider
	 * prefix belongs in the reference itself if an upstream service cannot make
	 * that guarantee.
	 *
	 * @param string $provider_reference Gateway's stable payment reference.
	 * @return array|null
	 */
	public static function find_by_provider_reference( $provider_reference ) {
		 global $wpdb;
		 $provider_reference = self::provider_reference( $provider_reference );
		 if ( '' === $provider_reference ) {
			 return null;
		 }
		 // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		 $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE provider_reference = %s LIMIT 1", $provider_reference ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Find an attempt by its durable idempotency key.
	 *
	 * @param string $attempt_key SHA-256 attempt identity.
	 * @return array|null
	 */
	public static function find_by_attempt_key( $attempt_key ) {
		global $wpdb;
		$attempt_key = self::stable_key( $attempt_key );
		if ( '' === $attempt_key ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE attempt_key = %s LIMIT 1", $attempt_key ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Find an attempt by the durable checkout identity that owns it.
	 *
	 * @param string $checkout_key SHA-256 checkout identity.
	 * @return array|null
	 */
	public static function find_by_checkout_key( $checkout_key ) {
		global $wpdb;
		$checkout_key = self::stable_key( $checkout_key );
		if ( '' === $checkout_key ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE checkout_key = %s LIMIT 1", $checkout_key ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Find an unresolved attempt holding a cart-scoped provider guard.
	 *
	 * This is used by Square protocol v2 before allocating a new browser nonce.
	 * The caller must hold local_reference_lock() while checking and creating so
	 * two tabs cannot both observe an empty guard and dispatch separate charges.
	 *
	 * @param string $provider          Provider slug.
	 * @param string $local_reference   Server-keyed cart guard.
	 * @param string $except_attempt_key Optional attempt identity to ignore.
	 * @return array|WP_Error|null
	 */
	public static function find_blocking_by_local_reference( $provider, $local_reference, $except_attempt_key = '' ) {
		global $wpdb;
		$provider           = self::short_key( $provider, 20 );
		$local_reference    = self::plain_text( $local_reference, 191 );
		$except_attempt_key = self::stable_key( $except_attempt_key );
		if ( '' === $provider || '' === $local_reference ) {
			return null;
		}

		$sql = "SELECT * FROM " . self::table() . " WHERE provider = %s AND local_reference = %s AND status IN ('prepared','dispatching','processing','unknown','succeeded','mismatch')";
		$args = array( $provider, $local_reference );
		if ( '' !== $except_attempt_key ) {
			$sql   .= ' AND attempt_key <> %s';
			$args[] = $except_attempt_key;
		}
		$sql .= ' ORDER BY id DESC LIMIT 1';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );
		if ( null === $row && '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'doughboss_pay_guard_storage', __( 'The existing payment guard could not be checked safely.', 'doughboss' ), array( 'status' => 503 ) );
		}
		if (
			is_array( $row )
			&& 'succeeded' === (string) $row['status']
			&& ! empty( $row['provider_reference'] )
			&& class_exists( 'DoughBoss_Order' )
		) {
			$order_id = DoughBoss_Order::find_id_by_payment_intent( (string) $row['provider_reference'] );
			if ( $order_id && self::mark_order_committed( (string) $row['provider_reference'], $order_id ) ) {
				return null;
			}
		}
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Find any unresolved pre-v2 Square attempt before enabling v2 allocation.
	 *
	 * Legacy attempts did not persist a cart guard and their error path could
	 * reset an already-dispatched request to `created`. They therefore cannot be
	 * scoped safely to one current browser. A deployment-wide drain is the only
	 * fail-closed migration policy that does not invent ownership evidence.
	 *
	 * @return array|WP_Error|null
	 */
	public static function find_blocking_legacy_square() {
		global $wpdb;
		$attempts = self::table();
		$orders   = $wpdb->prefix . 'doughboss_orders';
		$sql      = "SELECT attempts.* FROM {$attempts} attempts LEFT JOIN {$orders} orders ON attempts.provider_reference IS NOT NULL AND orders.payment_intent_id = attempts.provider_reference WHERE attempts.provider = %s AND attempts.status IN ('created','provisioning','processing','unknown','succeeded','mismatch') AND (attempts.safe_metadata_json IS NULL OR attempts.safe_metadata_json NOT LIKE %s) AND (attempts.status <> 'succeeded' OR attempts.provider_reference IS NULL OR orders.id IS NULL) ORDER BY attempts.id ASC LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, 'square', '%"protocol_version":"square-v2"%' ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'doughboss_pay_legacy_storage', __( 'Earlier payment attempts could not be checked safely.', 'doughboss' ), array( 'status' => 503 ) );
		}
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Decode the filtered, non-card metadata stored with an attempt.
	 *
	 * @param array $attempt Attempt row.
	 * @return array
	 */
	public static function metadata( array $attempt ) {
		$metadata = isset( $attempt['safe_metadata_json'] ) ? json_decode( (string) $attempt['safe_metadata_json'], true ) : array();
		return is_array( $metadata ) ? $metadata : array();
	}

	/**
	 * Serialize allocation of one server-keyed local payment guard.
	 *
	 * @param string $local_reference Server-keyed cart guard.
	 * @return bool
	 */
	public static function acquire_local_reference_lock( $local_reference ) {
		global $wpdb;
		$local_reference = self::plain_text( $local_reference, 191 );
		if ( '' === $local_reference ) {
			return false;
		}
		$lock = substr( 'dbpay_' . hash( 'sha256', $local_reference ), 0, 64 );
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 3 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Release a local payment guard lock owned by this database connection.
	 *
	 * @param string $local_reference Server-keyed cart guard.
	 * @return void
	 */
	public static function release_local_reference_lock( $local_reference ) {
		global $wpdb;
		$local_reference = self::plain_text( $local_reference, 191 );
		if ( '' === $local_reference ) {
			return;
		}
		$lock = substr( 'dbpay_' . hash( 'sha256', $local_reference ), 0, 64 );
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Alias retained for gateway call sites that use the shorter name.
	 *
	 * @param string $provider_reference Gateway's stable payment reference.
	 * @return array|null
	 */
	 public static function find_by_reference( $provider_reference ) {
		 return self::find_by_provider_reference( $provider_reference );
	 }

	/**
	 * Create an attempt, or return the existing row for the same provider
	 * reference. The unique database key is the final authority during races.
	 *
	 * Supported data keys: attempt_key, provider, provider_reference,
	 * checkout_key, purpose, context, local_reference, location_id, table_id,
	 * qr_code_id, amount_minor (or amount), currency, status,
	 * provider_status, safe_metadata and last_error.
	 *
	 * @param array $data Safe attempt facts.
	 * @return array|false Persisted row, or false for invalid/storage failure.
	 */
	 public static function create_or_find( array $data ) {
		 global $wpdb;
		 $row = self::normalise_create( $data );
		 if ( false === $row ) {
			 return false;
		 }

		$existing = self::find_by_attempt_key( $row['attempt_key'] );
		if ( '' !== (string) $wpdb->last_error ) { return false; }
		if ( ! $existing ) {
			$existing = self::find_by_checkout_key( $row['checkout_key'] );
			if ( '' !== (string) $wpdb->last_error ) { return false; }
		}
		if ( ! $existing && null !== $row['provider_reference'] ) {
			$existing = self::find_by_provider_reference( $row['provider_reference'] );
			if ( '' !== (string) $wpdb->last_error ) { return false; }
		}
		if ( $existing ) {
			 return $existing;
		 }

		 $inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			 self::table(),
			 $row,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		 if ( false === $inserted ) {
			 // A simultaneous request may have won the UNIQUE provider_reference
			 // race. Re-read it so caller retries are idempotent rather than errors.
			$existing = self::find_by_attempt_key( $row['attempt_key'] );
			if ( '' !== (string) $wpdb->last_error ) { return false; }
			if ( ! $existing ) {
				$existing = self::find_by_checkout_key( $row['checkout_key'] );
				if ( '' !== (string) $wpdb->last_error ) { return false; }
			}
			if ( ! $existing && null !== $row['provider_reference'] ) {
				$existing = self::find_by_provider_reference( $row['provider_reference'] );
				if ( '' !== (string) $wpdb->last_error ) { return false; }
			}
			return $existing;
		 }

		 return self::find( (int) $wpdb->insert_id );
	 }

	/**
	 * Short alias for create_or_find().
	 *
	 * @param array $data Safe attempt facts.
	 * @return array|false
	 */
	 public static function create( array $data ) {
		 return self::create_or_find( $data );
	 }

	/**
	 * Update mutable attempt state. Provider reference, money, purpose and
	 * request context are immutable once written; reconciliation may only amend
	 * the normalised/provider status, safe metadata, safe error and linkage ids.
	 *
	 * @param int   $attempt_id Attempt id.
	 * @param array $changes    Mutable fields.
	 * @return array|false Updated row, or false for invalid/storage failure.
	 */
	 public static function update( $attempt_id, array $changes ) {
		 global $wpdb;
		 $attempt_id = absint( $attempt_id );
		 $attempt = $attempt_id ? self::find( $attempt_id ) : null;
		 if ( ! $attempt ) {
			 return false;
		 }
		$existing_metadata = self::metadata( $attempt );
		$is_square_v2      = 'square' === (string) $attempt['provider']
			&& 'square-v2' === ( isset( $existing_metadata['protocol_version'] ) ? (string) $existing_metadata['protocol_version'] : '' );
		if ( $is_square_v2 && ( array_key_exists( 'status', $changes ) || array_key_exists( 'local_reference', $changes ) ) ) {
			// Square v2 state and cart ownership move only through the conditional,
			// irreversible methods in this repository. Generic updates would allow
			// an out-of-order response to weaken the duplicate-charge guard.
			return false;
		}
		if ( $is_square_v2 && ( array_key_exists( 'safe_metadata', $changes ) || array_key_exists( 'metadata', $changes ) ) ) {
			$candidate = array_key_exists( 'safe_metadata', $changes ) ? $changes['safe_metadata'] : $changes['metadata'];
			if ( ! is_array( $candidate ) ) {
				return false;
			}
			$candidate_json = self::safe_metadata_json( $candidate );
			$candidate      = false !== $candidate_json ? json_decode( $candidate_json, true ) : null;
			if ( ! is_array( $candidate ) ) {
				return false;
			}
			// Orders and tender identity are creation-only. An absent historical
			// tender means card and must never be upgraded by a metadata update.
			foreach ( array( 'orders_protocol', 'orders_snapshot_hash', 'square_tender_kind' ) as $key ) {
				if ( array_key_exists( $key, $candidate ) !== array_key_exists( $key, $existing_metadata ) ) {
					return false;
				}
			}
			foreach ( $existing_metadata as $key => $value ) {
				if ( ! array_key_exists( $key, $candidate ) || $candidate[ $key ] !== $value ) {
					return false;
				}
			}
		}

		 $data = self::normalise_update( $changes );
		 if ( empty( $data ) ) {
			 return self::find( $attempt_id );
		 }
		 $data['updated_at'] = self::utc_now();
		 $ok = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			 self::table(),
			 $data,
			 array( 'id' => $attempt_id ),
			 self::formats_for( $data ),
			 array( '%d' )
		 );
		 if ( false === $ok ) {
			 return false;
		 }
		 return self::find( $attempt_id );
	 }

	/**
	 * Atomically claim the right to create the upstream payment request.
	 *
	 * Only a newly-created, unbound attempt can own that side effect. Concurrent
	 * requests that lose this claim must re-read the durable row instead.
	 *
	 * @param int $attempt_id Attempt id.
	 * @return bool True only for the caller that owns upstream creation.
	 */
	public static function claim_creation( $attempt_id ) {
		global $wpdb;
		$attempt_id = absint( $attempt_id );
		if ( ! $attempt_id ) {
			return false;
		}
		// Each adapter replays the same stable provider identity. Reclaiming an
		// unbound, stale provisioning lease therefore recovers from a PHP crash
		// between the provider request and the durable reference binding.
		$lease_cutoff = gmdate( 'Y-m-d H:i:s', time() - 5 * 60 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::table() . " SET status = %s, updated_at = %s WHERE id = %d AND provider_reference IS NULL AND (status = %s OR (status = %s AND updated_at < %s))",
				'provisioning',
				self::utc_now(),
				$attempt_id,
				'created',
				'provisioning',
				$lease_cutoff
			)
		);
		return 1 === (int) $updated;
	}

	/**
	 * Irreversibly claim a Square v2 payment dispatch.
	 *
	 * Unlike claim_creation(), this state is never reclaimed by elapsed time.
	 * Once the provider boundary may have been crossed, only reconciliation can
	 * move the attempt forward; another payment POST is never permitted.
	 *
	 * @param int $attempt_id Attempt id.
	 * @return bool
	 */
	public static function claim_irreversible_creation( $attempt_id ) {
		global $wpdb;
		$attempt_id = absint( $attempt_id );
		if ( ! $attempt_id ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET status = %s, updated_at = %s WHERE id = %d AND status = %s AND provider = %s AND provider_reference IS NULL',
				'dispatching',
				self::utc_now(),
				$attempt_id,
				'prepared',
				'square'
			)
		);
		return 1 === (int) $updated;
	}

	/**
	 * Bind a distinct Order identity after verified CreateOrder, before payment.
	 *
	 * Owns a short transaction only; no HTTP may occur while these rows are locked.
	 * The expected attempt is the exact pre-dispatch tuple, not browser input.
	 * A collision, failed read/write/commit or changed snapshot retains the hold.
	 *
	 * @param int    $attempt_id      Durable attempt id.
	 * @param string $order_reference Verified Square Order id.
	 * @param array  $expected        Pre-dispatch immutable attempt row.
	 * @return array|false Persisted row, or false without a partial binding.
	 */
	public static function bind_square_order( $attempt_id, $order_reference, array $expected ) {
		global $wpdb;
		$attempt_id = absint( $attempt_id );
		if ( ! $attempt_id || ! is_string( $order_reference )
			|| 1 !== preg_match( '/^[A-Za-z0-9_-]{8,191}\\z/', $order_reference )
			|| ! DoughBoss_Activator::payment_storage_ready() ) {
			return false;
		}
		$expected_metadata = self::metadata( $expected );
		if ( 'square-orders-v1' !== ( isset( $expected_metadata['orders_protocol'] ) ? $expected_metadata['orders_protocol'] : '' )
			|| ! isset( $expected_metadata['orders_snapshot_hash'] )
			|| ! is_string( $expected_metadata['orders_snapshot_hash'] )
			|| ! preg_match( '/^[a-f0-9]{64}\\z/', $expected_metadata['orders_snapshot_hash'] )
			|| false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}
		$committed = false;
		try {
			$locked = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1 FOR UPDATE', $attempt_id ), ARRAY_A );
			if ( ! is_array( $locked ) || 'square' !== $locked['provider'] || 'dispatching' !== $locked['status']
				|| null !== $locked['provider_reference'] || null !== $locked['provider_order_reference'] ) {
				return false;
			}
			foreach ( array( 'id', 'attempt_key', 'checkout_key', 'provider', 'purpose', 'context', 'local_reference', 'location_id', 'table_id', 'qr_code_id', 'amount_minor', 'currency' ) as $field ) {
				if ( ! isset( $expected[ $field ], $locked[ $field ] ) || (string) $locked[ $field ] !== (string) $expected[ $field ] ) {
					return false;
				}
			}
			$metadata = self::metadata( $locked );
			if ( $metadata !== $expected_metadata ) {
				return false;
			}
			foreach ( array( 'protocol_version', 'binding_hash', 'square_shop_slug', 'square_location_id', 'square_mode', 'orders_protocol', 'orders_snapshot_hash' ) as $key ) {
				if ( ! isset( $metadata[ $key ], $expected_metadata[ $key ] ) || $metadata[ $key ] !== $expected_metadata[ $key ] ) {
					return false;
				}
			}
			if ( 'square-v2' !== $metadata['protocol_version'] || ! is_string( $metadata['binding_hash'] )
				|| ! preg_match( '/^[a-f0-9]{64}\\z/', $metadata['binding_hash'] ) ) {
				return false;
			}
			$snapshot = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'doughboss_checkout_snapshots WHERE checkout_key = %s LIMIT 1 FOR UPDATE', $locked['checkout_key'] ), ARRAY_A );
			if ( ! is_array( $snapshot ) || 'prepared' !== $snapshot['status'] || 0 !== (int) $snapshot['order_id']
				|| ! hash_equals( $metadata['orders_snapshot_hash'], (string) $snapshot['payload_hash'] )
				|| ! hash_equals( $metadata['orders_snapshot_hash'], hash( 'sha256', (string) $snapshot['payload_json'] ) ) ) {
				return false;
			}
			$payload = json_decode( (string) $snapshot['payload_json'], true );
			if ( ! is_array( $payload ) || ! isset( $payload['order']['location_id'] )
				|| (string) $payload['order']['location_id'] !== (string) $locked['location_id'] ) {
				return false;
			}
			$projection = DoughBoss_Square_Orders::project( $payload, array(
				'shop_slug' => $metadata['square_shop_slug'],
				'square_location_id' => $metadata['square_location_id'],
				'square_mode' => $metadata['square_mode'],
			), $attempt_id );
			if ( is_wp_error( $projection ) || (string) $projection['amounts']['total'] !== (string) $locked['amount_minor']
				|| $projection['currency'] !== $locked['currency'] ) {
				return false;
			}
			$updated = $wpdb->query( $wpdb->prepare(
				'UPDATE ' . self::table() . ' SET provider_order_reference = %s, updated_at = %s WHERE id = %d AND status = %s AND provider_reference IS NULL AND provider_order_reference IS NULL',
				$order_reference, self::utc_now(), $attempt_id, 'dispatching'
			) );
			if ( 1 !== $updated ) {
				return false;
			}
			$bound = self::find( $attempt_id );
			if ( ! is_array( $bound ) || $order_reference !== $bound['provider_order_reference']
				|| false === $wpdb->query( 'COMMIT' ) ) {
				return false;
			}
			$committed = true;
			return $bound;
		} finally {
			if ( ! $committed ) {
				$wpdb->query( 'ROLLBACK' );
			}
		}
	}

	/**
	 * Bind the result of an irreversible Square v2 dispatch.
	 *
	 * @param int    $attempt_id      Attempt id.
	 * @param string $reference       Provider payment reference.
	 * @param string $status          Normalised status.
	 * @param string $provider_status Provider status.
	 * @return array|false
	 */
	public static function bind_irreversible_reference( $attempt_id, $reference, $status, $provider_status ) {
		global $wpdb;
		$attempt_id      = absint( $attempt_id );
		$reference       = self::provider_reference( $reference );
		$status          = self::short_key( $status, 32 );
		$provider_status = self::plain_text( $provider_status, 32 );
		if ( ! $attempt_id || '' === $reference || '' === $status ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET provider_reference = %s, status = %s, provider_status = %s, updated_at = %s WHERE id = %d AND status = %s AND provider = %s AND provider_reference IS NULL',
				$reference,
				$status,
				$provider_status,
				self::utc_now(),
				$attempt_id,
				'dispatching',
				'square'
			)
		);
		return 1 === (int) $updated ? self::find( $attempt_id ) : false;
	}

	/**
	 * Permanently lock an irreversible dispatch whose outcome is uncertain.
	 *
	 * @param int    $attempt_id Attempt id.
	 * @param string $last_error Safe error label.
	 * @return bool
	 */
	public static function mark_irreversible_unknown( $attempt_id, $last_error = '' ) {
		global $wpdb;
		$attempt_id = absint( $attempt_id );
		if ( ! $attempt_id ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET status = %s, last_error = %s, updated_at = %s WHERE id = %d AND provider = %s AND status IN (%s,%s)',
				'unknown',
				self::safe_error( $last_error ),
				self::utc_now(),
				$attempt_id,
				'square',
				'dispatching',
				'processing'
			)
		);
		return 1 === (int) $updated;
	}

	/**
	 * Reconcile a bound Square v2 reference without weakening terminal states.
	 *
	 * A provider retrieval may resolve processing/unknown to a terminal result,
	 * but it must never regress a durable order, clear a mismatch, or turn a
	 * completed charge back into processing because responses arrived out of
	 * order.
	 *
	 * @param string $provider_reference Canonical Square payment id.
	 * @param string $status             Normalised provider status.
	 * @param string $provider_status    Raw provider status label.
	 * @return array|false Updated/current row, or false for an invalid transition.
	 */
	public static function reconcile_irreversible_reference( $provider_reference, $status, $provider_status ) {
		global $wpdb;
		$provider_reference = self::provider_reference( $provider_reference );
		$status             = self::short_key( $status, 32 );
		$provider_status    = self::plain_text( $provider_status, 32 );
		$allowed            = array(
			'succeeded'  => array( 'unknown', 'processing', 'succeeded' ),
			'processing' => array( 'unknown', 'processing' ),
			'failed'     => array( 'unknown', 'processing', 'failed', 'voided' ),
			'voided'     => array( 'unknown', 'processing', 'failed', 'voided' ),
			'unknown'    => array( 'unknown', 'processing' ),
			'mismatch'   => array( 'unknown', 'processing', 'succeeded', 'failed', 'voided', 'mismatch' ),
		);
		if ( '' === $provider_reference || ! isset( $allowed[ $status ] ) ) {
			return false;
		}
		$attempt = self::find_by_provider_reference( $provider_reference );
		if ( ! $attempt || 'square' !== (string) $attempt['provider'] ) {
			return false;
		}
		$metadata = self::metadata( $attempt );
		if ( 'square-v2' !== ( isset( $metadata['protocol_version'] ) ? (string) $metadata['protocol_version'] : '' ) ) {
			return false;
		}
		if ( 'order_committed' === (string) $attempt['status'] || 'mismatch' === (string) $attempt['status'] ) {
			return $attempt;
		}
		if ( ! in_array( (string) $attempt['status'], $allowed[ $status ], true ) ) {
			return false;
		}

		$placeholders = implode( ',', array_fill( 0, count( $allowed[ $status ] ), '%s' ) );
		$sql          = 'UPDATE ' . self::table() . " SET status = %s, provider_status = %s, updated_at = %s";
		$args         = array( $status, $provider_status, self::utc_now() );
		if ( 'succeeded' === $status ) {
			$sql   .= ', verified_at = %s';
			$args[] = self::utc_now();
		}
		$sql    .= " WHERE id = %d AND provider = %s AND provider_reference = %s AND status IN ({$placeholders})";
		$args[] = (int) $attempt['id'];
		$args[] = 'square';
		$args[] = $provider_reference;
		$args   = array_merge( $args, $allowed[ $status ] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$updated = $wpdb->query( $wpdb->prepare( $sql, $args ) );
		if ( false === $updated ) {
			return false;
		}
		return self::find( (int) $attempt['id'] );
	}

	/**
	 * Revoke cached release authority when linked Order verification fails.
	 * Keep the paid identity/state and cart hold; this never permits recharging.
	 * A committed receipt is not changed by a late failing read.
	 *
	 * @param array $expected Exact observed marked attempt.
	 * @return bool Whether the same attempt is held or already committed.
	 */
	public static function hold_square_order_verification( array $expected ) {
		global $wpdb;
		$metadata = self::metadata( $expected );
		if ( 'square' !== ( isset( $expected['provider'] ) ? $expected['provider'] : '' )
			|| 'square-orders-v1' !== ( isset( $metadata['orders_protocol'] ) ? $metadata['orders_protocol'] : '' )
			|| empty( $expected['id'] ) || empty( $expected['provider_reference'] ) || empty( $expected['provider_order_reference'] )
			|| ! isset( $expected['safe_metadata_json'] ) ) { return false; }
		$changed = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . ' SET verified_at = NULL, last_error = %s, updated_at = %s WHERE id = %d AND provider = %s AND provider_reference = %s AND provider_order_reference = %s AND safe_metadata_json = %s AND status IN (%s,%s,%s)',
			'order_verification_held', self::utc_now(), $expected['id'], 'square', $expected['provider_reference'],
			$expected['provider_order_reference'], $expected['safe_metadata_json'], 'processing', 'unknown', 'succeeded'
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $changed ) { return false; }
		$fresh = self::find( $expected['id'] );
		if ( ! is_array( $fresh ) || $fresh['provider_reference'] !== $expected['provider_reference']
			|| $fresh['provider_order_reference'] !== $expected['provider_order_reference']
			|| $fresh['safe_metadata_json'] !== $expected['safe_metadata_json'] ) { return false; }
		return 'order_committed' === $fresh['status'] || ( in_array( $fresh['status'], array( 'processing', 'unknown', 'succeeded' ), true ) && null === $fresh['verified_at'] );
	}

	/**
	 * Durably retain one already-verified Stripe Checkout payment when current
	 * policy prevents creation of its ordinary fulfilment order.
	 *
	 * This accepts only the provider facts that the caller has already verified;
	 * it makes no HTTP request and deliberately does not acknowledge an event.
	 * The exact attempt, still-prepared snapshot and competing order identities
	 * are locked together so a paid hold cannot overwrite a completed receipt.
	 *
	 * @param string $checkout_key         Durable checkout identity.
	 * @param string $checkout_session_id  Exact Stripe Checkout Session identity.
	 * @param string $payment_intent_id    Canonical paid Stripe PaymentIntent.
	 * @param int    $amount_minor         Verified paid amount.
	 * @param string $currency             Verified paid ISO currency.
	 * @param string $hold_reason          Stable, operator-safe reconciliation reason.
	 * @return array|false Held attempt, including exact replay, or false without a durable hold.
	 */
	public static function hold_paid_stripe_order( $checkout_key, $checkout_session_id, $payment_intent_id, $amount_minor, $currency, $hold_reason ) {
		global $wpdb;

		$checkout_key        = self::stable_key( $checkout_key );
		$checkout_session_id = self::provider_reference( $checkout_session_id );
		$payment_intent_id   = self::provider_reference( $payment_intent_id );
		$currency            = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $currency ) );
		$hold_reason         = self::short_key( $hold_reason, 64 );
		$amount_valid        = is_int( $amount_minor ) || ( is_string( $amount_minor ) && preg_match( '/^[1-9][0-9]*\z/', $amount_minor ) );
		$amount_minor        = $amount_valid ? (int) $amount_minor : 0;

		if (
			'' === $checkout_key
			|| 1 !== preg_match( '/^cs_(?:test|live)_[A-Za-z0-9_]{8,191}\z/', $checkout_session_id )
			|| 1 !== preg_match( '/^pi_[A-Za-z0-9_]{8,191}\z/', $payment_intent_id )
			|| ! $amount_valid
			|| $amount_minor < 1
			|| 3 !== strlen( $currency )
			|| '' === $hold_reason
			|| ! DoughBoss_Activator::payment_storage_ready()
			|| ! class_exists( 'DoughBoss_Order' )
			|| false === $wpdb->query( 'START TRANSACTION' ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		) {
			return false;
		}

		$committed = false;
		try {
			$locked = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE checkout_key = %s LIMIT 1 FOR UPDATE', $checkout_key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if (
				! is_array( $locked )
				|| 'stripe' !== (string) $locked['provider']
				|| 'order' !== (string) $locked['purpose']
				|| ! in_array( (string) $locked['context'], array( 'web', 'table_qr' ), true )
				|| ! hash_equals( $checkout_key, (string) $locked['checkout_key'] )
				|| ! hash_equals( $checkout_session_id, (string) $locked['provider_reference'] )
				|| ! empty( $locked['provider_order_reference'] )
				|| $amount_minor !== (int) $locked['amount_minor']
				|| ! hash_equals( $currency, strtoupper( (string) $locked['currency'] ) )
				|| ! in_array( (string) $locked['status'], array( 'processing', 'unknown', 'succeeded' ), true )
			) {
				return false;
			}

			$snapshot = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'doughboss_checkout_snapshots WHERE checkout_key = %s LIMIT 1 FOR UPDATE', $checkout_key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if (
				! is_array( $snapshot )
				|| 'prepared' !== (string) $snapshot['status']
				|| 0 !== (int) $snapshot['order_id']
				|| ! hash_equals( (string) $snapshot['payload_hash'], hash( 'sha256', (string) $snapshot['payload_json'] ) )
			) {
				return false;
			}
			$payload = json_decode( (string) $snapshot['payload_json'], true );
			$order   = is_array( $payload ) && isset( $payload['order'] ) && is_array( $payload['order'] ) ? $payload['order'] : array();
			if (
				empty( $order )
				|| ! hash_equals( $checkout_key, isset( $order['checkout_key'] ) ? (string) $order['checkout_key'] : '' )
				|| $amount_minor !== DoughBoss_Payment::to_minor_units( isset( $order['total'] ) ? (float) $order['total'] : -1 )
				|| ! hash_equals( $currency, strtoupper( isset( $order['currency'] ) ? (string) $order['currency'] : '' ) )
				|| (int) $locked['location_id'] !== (int) ( isset( $order['location_id'] ) ? $order['location_id'] : 0 )
				|| (int) $locked['table_id'] !== (int) ( isset( $order['table_id'] ) ? $order['table_id'] : 0 )
				|| (int) $locked['qr_code_id'] !== (int) ( isset( $order['table_qr_code_id'] ) ? $order['table_qr_code_id'] : 0 )
				|| ( 'web' === (string) $locked['context'] && 'web' !== ( isset( $order['order_source'] ) ? (string) $order['order_source'] : 'web' ) )
				|| ( 'table_qr' === (string) $locked['context'] && 'table_qr' !== ( isset( $order['order_source'] ) ? (string) $order['order_source'] : '' ) )
			) {
				return false;
			}

			if ( DoughBoss_Order::find_id_by_checkout_key( $checkout_key, true ) || DoughBoss_Order::find_id_by_payment_intent( $payment_intent_id, true ) ) {
				return false;
			}

			$metadata = self::metadata( $locked );
			if (
				( isset( $metadata['stripe_payment_intent_id'] ) && ! hash_equals( $payment_intent_id, (string) $metadata['stripe_payment_intent_id'] ) )
				|| ( isset( $metadata['reconciliation_hold'] ) && ! hash_equals( $hold_reason, (string) $metadata['reconciliation_hold'] ) )
			) {
				return false;
			}
			$metadata['stripe_payment_intent_id'] = $payment_intent_id;
			$metadata['reconciliation_hold']      = $hold_reason;
			$metadata_json                        = self::safe_metadata_json( $metadata );
			if ( false === $metadata_json ) {
				return false;
			}

			$already_held = 'succeeded' === (string) $locked['status']
				&& 'succeeded' === (string) $locked['provider_status']
				&& ! empty( $locked['verified_at'] )
				&& hash_equals( $metadata_json, isset( $locked['safe_metadata_json'] ) ? (string) $locked['safe_metadata_json'] : '' );
			if ( ! $already_held ) {
				$updated = $wpdb->query( $wpdb->prepare(
					'UPDATE ' . self::table() . ' SET status = %s, provider_status = %s, safe_metadata_json = %s, last_error = %s, verified_at = %s, updated_at = %s WHERE id = %d AND provider = %s AND purpose = %s AND context = %s AND checkout_key = %s AND provider_reference = %s AND provider_order_reference IS NULL AND amount_minor = %d AND currency = %s AND safe_metadata_json = %s',
					'succeeded', 'succeeded', $metadata_json, $hold_reason, self::utc_now(), self::utc_now(), (int) $locked['id'], 'stripe', 'order', (string) $locked['context'], $checkout_key, $checkout_session_id, $amount_minor, $currency, isset( $locked['safe_metadata_json'] ) ? (string) $locked['safe_metadata_json'] : ''
				) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				if ( 1 !== $updated ) {
					return false;
				}
			}

			$held = self::find( (int) $locked['id'] );
			if (
				! is_array( $held )
				|| 'succeeded' !== (string) $held['status']
				|| 'succeeded' !== (string) $held['provider_status']
				|| empty( $held['verified_at'] )
				|| ! hash_equals( $checkout_session_id, (string) $held['provider_reference'] )
				|| ! hash_equals( $metadata_json, isset( $held['safe_metadata_json'] ) ? (string) $held['safe_metadata_json'] : '' )
				|| false === $wpdb->query( 'COMMIT' ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			) {
				return false;
			}
			$committed = true;
		} finally {
			if ( ! $committed ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}

		$verified = self::find( (int) $locked['id'] );
		if (
			! is_array( $verified )
			|| 'succeeded' !== (string) $verified['status']
			|| 'succeeded' !== (string) $verified['provider_status']
			|| empty( $verified['verified_at'] )
			|| ! hash_equals( $checkout_session_id, (string) $verified['provider_reference'] )
			|| ! hash_equals( $metadata_json, isset( $verified['safe_metadata_json'] ) ? (string) $verified['safe_metadata_json'] : '' )
			|| DoughBoss_Order::find_id_by_checkout_key( $checkout_key )
			|| DoughBoss_Order::find_id_by_payment_intent( $payment_intent_id )
		) {
			return false;
		}

		return $verified;
	}

	/**
	 * Release a Square cart guard only after its paid order is durable.
	 *
	 * @param string $provider_reference Canonical Square payment id.
	 * @param int    $order_id           Durable order id.
	 * @return bool
	 */
	public static function mark_order_committed( $provider_reference, $order_id ) {
		global $wpdb;
		$provider_reference = self::provider_reference( $provider_reference );
		$order_id           = absint( $order_id );
		if ( '' === $provider_reference || ! $order_id ) {
			return false;
		}
		if ( ! class_exists( 'DoughBoss_Order' ) || $order_id !== DoughBoss_Order::find_id_by_payment_intent( $provider_reference ) ) {
			return false;
		}
		$attempt = self::find_by_provider_reference( $provider_reference );
		if ( ! $attempt || 'square' !== (string) $attempt['provider'] ) {
			return false;
		}
		$existing_metadata = self::metadata( $attempt );
		if ( 'square-v2' !== ( isset( $existing_metadata['protocol_version'] ) ? (string) $existing_metadata['protocol_version'] : '' ) ) {
			return false;
		}
		if ( 'order_committed' === (string) $attempt['status'] ) {
			return isset( $existing_metadata['committed_order'] ) && (int) $existing_metadata['committed_order'] === $order_id;
		}
		if ( 'succeeded' !== (string) $attempt['status'] ) {
			return false;
		}
		$metadata                    = $existing_metadata;
		$metadata['committed_order'] = $order_id;
		$data                        = self::normalise_update(
			array(
				'status'        => 'order_committed',
				'safe_metadata' => $metadata,
			)
		);
		$data['updated_at'] = self::utc_now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$updated = $wpdb->update(
			self::table(),
			$data,
			array( 'id' => (int) $attempt['id'], 'provider_reference' => $provider_reference, 'status' => 'succeeded' ),
			self::formats_for( $data ),
			array( '%d', '%s', '%s' )
		);
		return 1 === (int) $updated;
	}

	/**
	 * Release an owned, still-unbound creation lease after a safe-to-replay
	 * provider/storage failure.
	 *
	 * @param int    $attempt_id Attempt id.
	 * @param string $last_error Safe error identifier only.
	 * @return bool
	 */
	public static function release_creation( $attempt_id, $last_error = '' ) {
		global $wpdb;
		$attempt_id = absint( $attempt_id );
		if ( ! $attempt_id ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::table() . " SET status = %s, last_error = %s, updated_at = %s WHERE id = %d AND status = %s AND provider_reference IS NULL",
				'created',
				self::safe_error( $last_error ),
				self::utc_now(),
				$attempt_id,
				'provisioning'
			)
		);
		return 1 === (int) $updated;
	}

	/**
	 * Atomically bind one provider reference to an owned creation attempt.
	 *
	 * @param int    $attempt_id      Attempt id.
	 * @param string $reference       Provider payment reference.
	 * @param string $status          Normalised attempt status.
	 * @param string $provider_status Provider status.
	 * @return array|false Bound row, or false when the attempt is no longer owned.
	 */
	public static function bind_provider_reference( $attempt_id, $reference, $status, $provider_status ) {
		global $wpdb;
		$attempt_id      = absint( $attempt_id );
		$reference       = self::provider_reference( $reference );
		$status          = self::short_key( $status, 32 );
		$provider_status = self::plain_text( $provider_status, 32 );
		if ( ! $attempt_id || '' === $reference || '' === $status ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::table() . " SET provider_reference = %s, status = %s, provider_status = %s, updated_at = %s WHERE id = %d AND status = %s AND provider_reference IS NULL",
				$reference,
				$status,
				$provider_status,
				self::utc_now(),
				$attempt_id,
				'provisioning'
			)
		);
		return 1 === (int) $updated ? self::find( $attempt_id ) : false;
	}
	/**
	 * Update by a stable provider reference, useful for webhook reconciliation.
	 *
	 * @param string $provider_reference Gateway's stable payment reference.
	 * @param array  $changes            Mutable fields.
	 * @return array|false
	 */
	 public static function update_by_provider_reference( $provider_reference, array $changes ) {
		 $attempt = self::find_by_provider_reference( $provider_reference );
		 return $attempt ? self::update( (int) $attempt['id'], $changes ) : false;
	 }

	/**
	 * Atomically claim an upstream event key. A duplicate key returns false and
	 * never writes a raw payload; callers should stop processing in that case.
	 *
	 * @param string $event_key          SHA-256 event identity (64 lowercase hex).
	 * @param string $provider           Provider slug.
	 * @param string $provider_reference Provider payment reference.
	 * @param string $event_type         Safe provider event name.
	 * @return bool True only for the request that claimed the event.
	 */
	 public static function claim_event( $event_key, $provider, $provider_reference, $event_type ) {
		 global $wpdb;
		 $event_key          = self::event_key( $event_key );
		 $provider           = self::short_key( $provider, 20 );
		 $provider_reference = self::provider_reference( $provider_reference );
		 $event_type         = self::short_key( $event_type, 64 );
		 if ( '' === $event_key || '' === $provider || '' === $provider_reference || '' === $event_type ) {
			 return false;
		 }
		 $inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			 self::events_table(),
			 array(
				'event_key'          => $event_key,
				'provider'           => $provider,
				'provider_reference' => $provider_reference,
				'event_type'         => $event_type,
				'outcome'            => 'received',
				'created_at'         => self::utc_now(),
				'updated_at'         => self::utc_now(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		 );
		if ( 1 === (int) $inserted ) {
			return true;
		}
		// A failed delivery leaves outcome=retry. A process that fatals before it
		// can record retry leaves received; reclaim that only after a five-minute
		// lease. Normal webhook work completes in milliseconds, so concurrent
		// deliveries still cannot both own the same event.
		$lease_cutoff = gmdate( 'Y-m-d H:i:s', time() - 5 * 60 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$reclaimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::events_table() . " SET outcome = %s, updated_at = %s WHERE event_key = %s AND (outcome = %s OR (outcome = %s AND updated_at < %s))",
				'received',
				self::utc_now(),
				$event_key,
				'retry',
				'received',
				$lease_cutoff
			)
		);
		return 1 === (int) $reclaimed;
	}

	/**
	 * Return the safe processing state for an already-claimed provider event.
	 *
	 * A null result means no durable row could be read and callers must return a
	 * retryable HTTP error rather than incorrectly acknowledging a duplicate.
	 *
	 * @param string $event_key SHA-256 event identity.
	 * @return string|null
	 */
	public static function event_outcome( $event_key ) {
		global $wpdb;
		$event_key = self::event_key( $event_key );
		if ( '' === $event_key ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$outcome = $wpdb->get_var( $wpdb->prepare( "SELECT outcome FROM " . self::events_table() . " WHERE event_key = %s LIMIT 1", $event_key ) );
		if ( ! is_string( $outcome ) || '' === $outcome ) {
			return null;
		}
		return self::short_key( $outcome, 32 );
	}

	/**
	 * Mark a claimed event with its safe terminal/retry outcome.
	 *
	 * @param string $event_key SHA-256 event identity.
	 * @param string $outcome   Short, safe processing result.
	 * @return bool
	 */
	 public static function complete_event( $event_key, $outcome ) {
		 global $wpdb;
		 $event_key = self::event_key( $event_key );
		 $outcome   = self::short_key( $outcome, 32 );
		 if ( '' === $event_key || '' === $outcome ) {
			 return false;
		 }
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::events_table(),
			array( 'outcome' => $outcome, 'updated_at' => self::utc_now() ),
			array( 'event_key' => $event_key ),
			array( '%s', '%s' ),
			 array( '%s' )
		 );
		if ( false === $updated ) {
			return false;
		}
		if ( $updated ) {
			return true;
		}
		// A same-second repeat may have no changed values. Treat that existing
		// claim as a successful idempotent completion, not a processing failure.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . self::events_table() . " WHERE event_key = %s LIMIT 1", $event_key ) );
	}

	/**
	 * Normalise data accepted at initial creation.
	 *
	 * @param array $data Input.
	 * @return array|false
	 */
	 private static function normalise_create( array $data ) {
		$attempt_key = self::stable_key( isset( $data['attempt_key'] ) ? $data['attempt_key'] : '' );
		$checkout_key = self::stable_key( isset( $data['checkout_key'] ) ? $data['checkout_key'] : '' );
		$provider  = self::short_key( isset( $data['provider'] ) ? $data['provider'] : '', 20 );
		$reference = self::provider_reference( isset( $data['provider_reference'] ) ? $data['provider_reference'] : '' );
		 $amount    = isset( $data['amount_minor'] ) ? (int) $data['amount_minor'] : ( isset( $data['amount'] ) ? (int) $data['amount'] : -1 );
		 $currency  = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( isset( $data['currency'] ) ? $data['currency'] : '' ) ) );
		if ( '' === $attempt_key || '' === $checkout_key || '' === $provider || $amount < 0 || 3 !== strlen( $currency ) ) {
			 return false;
		 }
		$metadata = self::safe_metadata_json( isset( $data['safe_metadata'] ) ? $data['safe_metadata'] : ( isset( $data['metadata'] ) ? $data['metadata'] : array() ) );
		 if ( false === $metadata ) {
			 return false;
		 }
		$now = self::utc_now();
		return array(
			'attempt_key'        => $attempt_key,
			'provider'           => $provider,
			'provider_reference' => '' === $reference ? null : $reference,
			'checkout_key'       => $checkout_key,
			'purpose'            => self::short_key( isset( $data['purpose'] ) ? $data['purpose'] : 'payment', 32 ),
			'context'            => self::short_key( isset( $data['context'] ) ? $data['context'] : 'checkout', 32 ),
			'local_reference'    => self::plain_text( isset( $data['local_reference'] ) ? $data['local_reference'] : '', 191 ),
			 'location_id'        => absint( isset( $data['location_id'] ) ? $data['location_id'] : 0 ),
			 'table_id'           => absint( isset( $data['table_id'] ) ? $data['table_id'] : 0 ),
			 'qr_code_id'         => absint( isset( $data['qr_code_id'] ) ? $data['qr_code_id'] : 0 ),
			 'amount_minor'       => $amount,
			 'currency'           => $currency,
			 'status'             => self::short_key( isset( $data['status'] ) ? $data['status'] : 'pending', 32 ),
			'provider_status'    => self::plain_text( isset( $data['provider_status'] ) ? $data['provider_status'] : '', 32 ),
			 'safe_metadata_json' => $metadata,
			 'last_error'         => self::safe_error( isset( $data['last_error'] ) ? $data['last_error'] : '' ),
			'created_at'         => $now,
			'updated_at'         => $now,
			'verified_at'        => null,
		 );
	 }

	/**
	 * Normalise only fields that are allowed to change after creation.
	 *
	 * @param array $changes Input.
	 * @return array
	 */
	 private static function normalise_update( array $changes ) {
		 $data = array();
		foreach ( array( 'status' => 32, 'provider_status' => 32, 'last_error' => 64, 'local_reference' => 191 ) as $field => $length ) {
			 if ( ! array_key_exists( $field, $changes ) ) {
				 continue;
			 }
			$data[ $field ] = 'last_error' === $field ? self::safe_error( $changes[ $field ] ) : ( 'status' === $field ? self::short_key( $changes[ $field ], $length ) : self::plain_text( $changes[ $field ], $length ) );
		}

		if ( array_key_exists( 'safe_metadata', $changes ) || array_key_exists( 'metadata', $changes ) ) {
			$metadata = self::safe_metadata_json( array_key_exists( 'safe_metadata', $changes ) ? $changes['safe_metadata'] : $changes['metadata'] );
			if ( false !== $metadata ) {
				$data['safe_metadata_json'] = $metadata;
			}
		}
		if ( array_key_exists( 'verified_at', $changes ) && ! empty( $changes['verified_at'] ) ) {
			$data['verified_at'] = self::utc_now();
		}
		 return $data;
	 }

	/**
	 * Restrict metadata to safe scalar facts. Secret/card/raw-payload-shaped
	 * keys are removed recursively; a PAN-shaped value invalidates that value.
	 *
	 * @param mixed $metadata Input metadata.
	 * @return string|false JSON safe for durable storage.
	 */
	 private static function safe_metadata_json( $metadata ) {
		 if ( ! is_array( $metadata ) ) {
			 return false;
		 }
		 $safe = self::safe_metadata( $metadata );
		 $json = wp_json_encode( $safe );
		 return ( false === $json || strlen( $json ) > 8192 ) ? false : $json;
	 }

	/**
	 * @param array $metadata Input metadata.
	 * @param int   $depth    Recursion guard.
	 * @return array
	 */
	 private static function safe_metadata( array $metadata, $depth = 0 ) {
		 if ( $depth > 3 ) {
			 return array();
		 }
		 $safe = array();
		 foreach ( $metadata as $key => $value ) {
			 $key = self::plain_text( $key, 64 );
			 if ( '' === $key || preg_match( '/(?:secret|card|pan|cvc|cvv|expiry|expir|cryptogram|track|payload|request|response|body|token|source)/i', $key ) ) {
				 continue;
			 }
			 if ( is_array( $value ) ) {
				 $safe[ $key ] = self::safe_metadata( $value, $depth + 1 );
			 } elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				 $safe[ $key ] = $value;
			 } elseif ( is_scalar( $value ) ) {
				 $value = self::plain_text( $value, 256 );
				 if ( '' !== $value && ! self::looks_like_card_number( $value ) ) {
					 $safe[ $key ] = $value;
				 }
			 }
		 }
		 return $safe;
	 }

	/**
	 * @param array $data Normalised update data.
	 * @return array
	 */
	 private static function formats_for( array $data ) {
		 $formats = array();
		 foreach ( $data as $field => $unused ) {
			$formats[] = in_array( $field, array( 'location_id', 'table_id', 'qr_code_id' ), true ) ? '%d' : '%s';
		 }
		 return $formats;
	 }

	/**
	 * @param mixed $value Candidate reference.
	 * @return string
	 */
	 private static function provider_reference( $value ) {
		 $value = self::plain_text( $value, 191 );
		 return self::looks_like_card_number( $value ) ? '' : $value;
	 }

	/**
	 * @param mixed $value Candidate event key.
	 * @return string
	 */
	private static function event_key( $value ) {
		return self::stable_key( $value );
	}

	/**
	 * @param mixed $value Candidate SHA-256 key.
	 * @return string
	 */
	private static function stable_key( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	/**
	 * @param mixed $value Candidate value.
	 * @param int   $length Maximum length.
	 * @return string
	 */
	 private static function short_key( $value, $length ) {
		 return substr( sanitize_key( (string) $value ), 0, (int) $length );
	 }

	/**
	 * @param mixed $value Candidate text.
	 * @param int   $length Maximum length.
	 * @return string
	 */
	 private static function plain_text( $value, $length ) {
		 return substr( sanitize_text_field( (string) $value ), 0, (int) $length );
	 }

	/**
	 * Store error identifiers only, never provider error prose or raw bodies.
	 *
	 * @param mixed $value Candidate error.
	 * @return string
	 */
	 private static function safe_error( $value ) {
		 $value = self::plain_text( $value, 64 );
		 return preg_replace( '/[^A-Za-z0-9_.:-]/', '', $value );
	 }

	/**
	 * Conservative payment-card number guard for any value headed to storage.
	 *
	 * @param string $value Candidate string.
	 * @return bool
	 */
	 private static function looks_like_card_number( $value ) {
		 $digits = preg_replace( '/[\s-]/', '', (string) $value );
		 return preg_match( '/^\d{13,19}$/', $digits ) === 1;
	 }

	/**
	 * @return string UTC MySQL timestamp.
	 */
	 private static function utc_now() {
		 return gmdate( 'Y-m-d H:i:s' );
	 }
}
