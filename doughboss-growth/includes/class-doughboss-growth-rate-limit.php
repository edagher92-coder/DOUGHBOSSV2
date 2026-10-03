<?php
/**
 * Fail-closed fixed-window rate limiter on table {prefix}doughboss_growth_rate.
 *
 * Unlike a fail-open limiter, any storage problem (missing table, $wpdb error, bad arguments)
 * DENIES the request. Counting is atomic: every step is a single conditional UPDATE or an
 * INSERT IGNORE, so two concurrent requests can never both take the last slot.
 *
 * Raw IP addresses are never stored: callers pass bucket keys, and hash_ip() derives a
 * daily-rotating salted hash.
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rate limiter.
 */
final class DoughBoss_Growth_Rate_Limit {

	/**
	 * Table name without prefix.
	 */
	const TABLE = 'doughboss_growth_rate';

	/**
	 * Maximum stored bucket key length (longer keys are hashed).
	 */
	const MAX_KEY = 100;

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
	 * CREATE TABLE statements for dbDelta (collected by the activator).
	 *
	 * @return array
	 */
	public static function schema() {
		global $wpdb;
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();
		return array(
			"CREATE TABLE {$table} (
  bucket_key varchar(100) NOT NULL,
  window_start int(10) unsigned NOT NULL,
  hits int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (bucket_key),
  KEY window_start (window_start)
) {$collate};",
		);
	}

	/**
	 * Count one hit against a bucket.
	 *
	 * @param string $bucket Bucket key (for example "ip:<hash>", "email:<hash>", "global").
	 * @param int    $limit  Maximum hits per window (>= 1).
	 * @param int    $window Window length in seconds (>= 1).
	 * @return array { allowed: bool, reason: string (ok|limited|storage_error|invalid), retry_after: int }
	 */
	public static function hit( $bucket, $limit, $window ) {
		$denied = array(
			'allowed'     => false,
			'reason'      => 'invalid',
			'retry_after' => 0,
		);
		$limit  = (int) $limit;
		$window = (int) $window;
		if ( ! is_string( $bucket ) || '' === $bucket || $limit < 1 || $window < 1 ) {
			return $denied;
		}
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ) {
			$denied['reason'] = 'storage_error';
			return $denied;
		}
		if ( strlen( $bucket ) > self::MAX_KEY ) {
			$bucket = 'h:' . hash( 'sha256', $bucket );
		}

		$now          = DoughBoss_Growth::now();
		$window_start = $now - ( $now % $window );
		$retry_after  = max( 1, $window_start + $window - $now );
		$table        = self::table();

		// 1. Take a slot in the current window if one is free.
		$taken = self::increment( $table, $bucket, $window_start, $limit );
		if ( null === $taken ) {
			$denied['reason'] = 'storage_error';
			return $denied;
		}
		if ( $taken ) {
			return array(
				'allowed'     => true,
				'reason'      => 'ok',
				'retry_after' => 0,
			);
		}

		// 2. The bucket may belong to an older window: reset it to this window with one hit.
		$reset = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"UPDATE {$table} SET window_start = %d, hits = 1 WHERE bucket_key = %s AND window_start < %d",
				$window_start,
				$bucket,
				$window_start
			)
		);
		if ( false === $reset || '' !== (string) $wpdb->last_error ) {
			$denied['reason'] = 'storage_error';
			return $denied;
		}
		if ( 1 === (int) $reset ) {
			return array(
				'allowed'     => true,
				'reason'      => 'ok',
				'retry_after' => 0,
			);
		}

		// 3. First sighting of the bucket.
		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (bucket_key, window_start, hits) VALUES (%s, %d, 1)",
				$bucket,
				$window_start
			)
		);
		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			$denied['reason'] = 'storage_error';
			return $denied;
		}
		if ( 1 === (int) $inserted ) {
			return array(
				'allowed'     => true,
				'reason'      => 'ok',
				'retry_after' => 0,
			);
		}

		// 4. Someone else created it between steps 2 and 3: try the increment once more.
		$taken = self::increment( $table, $bucket, $window_start, $limit );
		if ( null === $taken ) {
			$denied['reason'] = 'storage_error';
			return $denied;
		}
		if ( $taken ) {
			return array(
				'allowed'     => true,
				'reason'      => 'ok',
				'retry_after' => 0,
			);
		}
		return array(
			'allowed'     => false,
			'reason'      => 'limited',
			'retry_after' => $retry_after,
		);
	}

	/**
	 * Apply several buckets in order; stops at the first denial. All must allow.
	 *
	 * @param array $buckets List of array( bucket, limit, window ).
	 * @return array Same shape as hit(), for the first denial or the last success.
	 */
	public static function hit_all( array $buckets ) {
		$result = array(
			'allowed'     => false,
			'reason'      => 'invalid',
			'retry_after' => 0,
		);
		foreach ( $buckets as $spec ) {
			if ( ! is_array( $spec ) || 3 !== count( $spec ) ) {
				return array(
					'allowed'     => false,
					'reason'      => 'invalid',
					'retry_after' => 0,
				);
			}
			$result = self::hit( $spec[0], $spec[1], $spec[2] );
			if ( ! $result['allowed'] ) {
				return $result;
			}
		}
		return $result;
	}

	/**
	 * Hash an IP for bucketing. The salt rotates daily, so the hash cannot be correlated across days
	 * and is useless without the site's salt. The raw IP is never stored or logged.
	 *
	 * @param string $ip IP address.
	 * @return string 32 hex characters, or "unknown" for an invalid address.
	 */
	public static function hash_ip( $ip ) {
		if ( ! is_string( $ip ) || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return 'unknown';
		}
		$ip  = self::bucket_address( $ip );
		$day = gmdate( 'Y-m-d', DoughBoss_Growth::now() );
		return substr( hash_hmac( 'sha256', $ip . '|' . $day, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * The part of an address that identifies one visitor for rate limiting. An IPv6 visitor normally controls a
	 * whole /64 (privacy addresses rotate inside it), so IPv6 is bucketed by its /64 prefix; otherwise every new
	 * address in the same network would get a fresh per-address allowance. An IPv4-mapped IPv6 address counts as
	 * its IPv4 address (its /64 is shared by every IPv4 visitor).
	 *
	 * @param string $ip A valid IP address.
	 * @return string
	 */
	public static function bucket_address( $ip ) {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $ip;
		}
		$packed = function_exists( 'inet_pton' ) ? inet_pton( $ip ) : false;
		if ( ! is_string( $packed ) || 16 !== strlen( $packed ) ) {
			return $ip;
		}
		if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
			$v4 = inet_ntop( substr( $packed, 12 ) );
			return is_string( $v4 ) ? $v4 : $ip;
		}
		return bin2hex( substr( $packed, 0, 8 ) ) . '::/64';
	}

	/**
	 * Client address from REMOTE_ADDR only. Forwarded-for headers are not trusted; a site behind a
	 * known proxy can supply the address through the doughboss_growth_client_ip filter.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = apply_filters( 'doughboss_growth_client_ip', $ip );
		return ( is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) ? $ip : '';
	}

	/**
	 * Delete buckets whose window ended more than a day ago. Best effort housekeeping.
	 *
	 * @return int Rows removed (0 on error).
	 */
	public static function purge_expired() {
		global $wpdb;
		$table  = self::table();
		$cutoff = DoughBoss_Growth::now() - DAY_IN_SECONDS;
		$rows   = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "DELETE FROM {$table} WHERE window_start < %d", $cutoff )
		);
		return ( false === $rows ) ? 0 : (int) $rows;
	}

	/**
	 * Increment a bucket inside its current window if it is below the limit.
	 *
	 * @param string $table        Table.
	 * @param string $bucket       Bucket.
	 * @param int    $window_start Window start.
	 * @param int    $limit        Limit.
	 * @return bool|null True when a slot was taken, false when full or absent, null on a storage error.
	 */
	private static function increment( $table, $bucket, $window_start, $limit ) {
		global $wpdb;
		$rows = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"UPDATE {$table} SET hits = hits + 1 WHERE bucket_key = %s AND window_start = %d AND hits < %d",
				$bucket,
				$window_start,
				$limit
			)
		);
		if ( false === $rows || '' !== (string) $wpdb->last_error ) {
			return null;
		}
		return 1 === (int) $rows;
	}
}
