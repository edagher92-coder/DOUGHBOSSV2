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
		if ( ! $existing ) {
			$existing = self::find_by_checkout_key( $row['checkout_key'] );
		}
		if ( ! $existing && null !== $row['provider_reference'] ) {
			$existing = self::find_by_provider_reference( $row['provider_reference'] );
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
			if ( ! $existing ) {
				$existing = self::find_by_checkout_key( $row['checkout_key'] );
			}
			if ( ! $existing && null !== $row['provider_reference'] ) {
				$existing = self::find_by_provider_reference( $row['provider_reference'] );
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
		 if ( ! $attempt_id || ! self::find( $attempt_id ) ) {
			 return false;
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::table() . " SET status = %s, updated_at = %s WHERE id = %d AND status = %s AND provider_reference IS NULL",
				'provisioning',
				self::utc_now(),
				$attempt_id,
				'created'
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
		// A failed earlier delivery deliberately leaves outcome=retry. Exactly one
		// later delivery can reclaim it; received and completed outcomes remain
		// duplicates and therefore cannot be processed twice.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$reclaimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::events_table() . " SET outcome = %s, updated_at = %s WHERE event_key = %s AND outcome = %s",
				'received',
				self::utc_now(),
				$event_key,
				'retry'
			)
		);
		return 1 === (int) $reclaimed;
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
