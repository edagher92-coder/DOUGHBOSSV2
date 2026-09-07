<?php
/**
 * Durable server-owned checkout snapshots for payment recovery.
 *
 * A snapshot contains only the validated order/contact data DoughBoss already
 * stores on the eventual order. It never contains card, wallet, Stripe secret,
 * raw gateway response or browser storage data.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists the immutable order facts needed to fulfil a paid webhook.
 */
class DoughBoss_Checkout_Snapshots {

	/**
	 * Return the snapshots table name.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_checkout_snapshots';
	}

	/**
	 * Store an immutable snapshot or replay the identical existing one.
	 *
	 * @param string $checkout_key Stable server-owned checkout key.
	 * @param array  $payload      Validated order data and server cart lines.
	 * @return array|WP_Error Persisted row.
	 */
	public static function store( $checkout_key, array $payload ) {
		global $wpdb;
		$checkout_key = self::stable_key( $checkout_key );
		if ( '' === $checkout_key ) {
			return new WP_Error( 'doughboss_snapshot_key', __( 'The secure order snapshot is missing its checkout key.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$json = wp_json_encode( $payload );
		if ( false === $json || strlen( $json ) > 262144 ) {
			return new WP_Error( 'doughboss_snapshot_size', __( 'This order is too large to prepare for secure payment.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$payload_hash = hash( 'sha256', $json );
		$existing     = self::find( $checkout_key );
		if ( $existing ) {
			if ( ! hash_equals( (string) $existing['payload_hash'], $payload_hash ) ) {
				return new WP_Error( 'doughboss_snapshot_changed', __( 'Your order changed while payment was being prepared. Please start payment again.', 'doughboss' ), array( 'status' => 409 ) );
			}
			return $existing;
		}

		$now     = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array(
				'checkout_key' => $checkout_key,
				'payload_hash' => $payload_hash,
				'payload_json' => $json,
				'status'       => 'prepared',
				'order_id'     => 0,
				'expires_at'   => $expires,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			// A concurrent request may have won the unique checkout-key race.
			$existing = self::find( $checkout_key );
			if ( $existing && hash_equals( (string) $existing['payload_hash'], $payload_hash ) ) {
				return $existing;
			}
			return new WP_Error( 'doughboss_snapshot_storage', __( 'The order could not be prepared safely for payment.', 'doughboss' ), array( 'status' => 503 ) );
		}

		self::purge_expired();
		return self::find( $checkout_key );
	}

	/**
	 * Find and decode an unexpired snapshot.
	 *
	 * @param string $checkout_key Stable checkout key.
	 * @return array|null
	 */
	public static function find( $checkout_key ) {
		global $wpdb;
		$checkout_key = self::stable_key( $checkout_key );
		if ( '' === $checkout_key ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE checkout_key = %s LIMIT 1', $checkout_key ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		if ( empty( $row['expires_at'] ) || strtotime( (string) $row['expires_at'] . ' UTC' ) < time() ) {
			return null;
		}
		$payload = json_decode( (string) $row['payload_json'], true );
		if ( ! is_array( $payload ) || ! hash_equals( (string) $row['payload_hash'], hash( 'sha256', (string) $row['payload_json'] ) ) ) {
			return null;
		}
		$row['payload'] = $payload;
		return $row;
	}

	/**
	 * Link a fulfilled snapshot to its one order.
	 *
	 * @param string $checkout_key Stable checkout key.
	 * @param int    $order_id     Created order id.
	 * @return bool
	 */
	public static function complete( $checkout_key, $order_id ) {
		global $wpdb;
		$checkout_key = self::stable_key( $checkout_key );
		$order_id     = absint( $order_id );
		if ( '' === $checkout_key || ! $order_id ) {
			return false;
		}
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array(
				'status'     => 'completed',
				'order_id'   => $order_id,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'checkout_key' => $checkout_key ),
			array( '%s', '%d', '%s' ),
			array( '%s' )
		);
		return false !== $updated;
	}

	/**
	 * Opportunistically remove abandoned/completed recovery data after expiry.
	 *
	 * @return void
	 */
	private static function purge_expired() {
		global $wpdb;
		// Keep this lightweight on ordinary checkouts; approximately one request
		// in 50 performs the bounded retention cleanup.
		if ( 1 !== wp_rand( 1, 50 ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE expires_at < %s LIMIT 100', current_time( 'mysql', true ) ) );
	}

	/**
	 * Normalise a SHA-256 checkout key.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private static function stable_key( $value ) {
		$value = strtolower( sanitize_text_field( (string) $value ) );
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}
}
