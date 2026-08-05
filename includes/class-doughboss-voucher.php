<?php
/**
 * Voucher / discount-coupon data model.
 *
 * The plugin is the source of truth for voucher issuance, single-use locking
 * and audit. A voucher can be redeemed online (here, atomically) and — once the
 * POSPal in-store leg is wired — mirrored as a member coupon at the till.
 *
 * Security model (per review): redemption is a single conditional UPDATE
 * (status issued -> redeemed) so two concurrent redeems can never both win, and
 * an immutable redemption row with a UNIQUE idempotency key backs it. Codes are
 * high-entropy. The applied discount is always recomputed server-side from the
 * voucher row against the server-computed cart subtotal — never trusted from a
 * client.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Voucher model + atomic redemption.
 */
class DoughBoss_Voucher {

	/**
	 * Unambiguous code alphabet (no 0/O/1/I/L) for readable, low-collision codes.
	 */
	const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

	/** Voucher meta key used for the short-lived checkout lease. */
	const RESERVATION_META_KEY = '_doughboss_reservation';

	/** Default lease lifetime: Stripe Checkout expires first, with webhook grace. */
	const RESERVATION_TTL_SECONDS = 2700;

	/**
	 * Vouchers table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_vouchers';
	}

	/**
	 * Redemptions (audit) table name.
	 *
	 * @return string
	 */
	public static function redemptions_table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_voucher_redemptions';
	}

	/**
	 * Generate a high-entropy voucher code, e.g. SNOW-7K2D9QXM.
	 *
	 * The random body is built by DoughBoss_Coupon_Code::generate() so new codes
	 * carry deterministic check characters (one per part) and can be typo-/guess-
	 * rejected before a DB lookup. The legacy prefix behaviour is preserved: the
	 * campaign prefix is still prepended as 'PREFIX-...'.
	 *
	 * @param string $prefix Short uppercase prefix (campaign), e.g. 'SNOW'.
	 * @param int    $length Number of random characters (default 8). Used to size
	 *                       the check-character body (kept to two parts).
	 * @return string
	 */
	public static function generate_code( $prefix = 'DB', $length = 8 ) {
		$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $prefix ) );
		$length = max( 6, (int) $length );

		// Split the requested length across two check-bearing parts (e.g. length
		// 8 -> two 4-char groups 'K7QF-3MR9'). Each part's last char is a check.
		$parts    = 2;
		$part_len = max( 2, (int) ceil( $length / $parts ) );

		$body = DoughBoss_Coupon_Code::generate( $parts, $part_len );

		return ( '' !== $prefix ? $prefix . '-' : '' ) . $body;
	}

	/**
	 * Issue (create) a voucher and return its id + code.
	 *
	 * @param array $args {
	 *     type, value, prefix, currency, min_spend, scope, location_id,
	 *     single_use, customer_phone, customer_email, valid_from, valid_to, meta.
	 * }
	 * @return array|WP_Error array{ id:int, code:string } or error.
	 */
	public static function issue( array $args ) {
		global $wpdb;
		$table = self::table();

		$type = isset( $args['type'] ) ? sanitize_key( $args['type'] ) : 'amount';
		if ( ! in_array( $type, array( 'amount', 'percent' ), true ) ) {
			return new WP_Error( 'doughboss_voucher_type', __( 'Invalid voucher type.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$value = round( (float) ( isset( $args['value'] ) ? $args['value'] : 0 ), 2 );
		if ( $value <= 0 ) {
			return new WP_Error( 'doughboss_voucher_value', __( 'Voucher value must be greater than zero.', 'doughboss' ), array( 'status' => 400 ) );
		}
		// A percentage discount can never exceed the whole order; clamp at issue
		// time so a typo'd 500% is stored as 100% (evaluate() also clamps).
		if ( 'percent' === $type && $value > 100 ) {
			$value = 100.00;
		}

		$now  = current_time( 'mysql' );
		$code = '';
		for ( $try = 0; $try < 6; $try++ ) {
			$candidate = self::generate_code( isset( $args['prefix'] ) ? $args['prefix'] : 'DB' );
			$exists    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s", $candidate ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $exists ) {
				$code = $candidate;
				break;
			}
		}
		if ( '' === $code ) {
			return new WP_Error( 'doughboss_voucher_code', __( 'Could not generate a unique voucher code.', 'doughboss' ), array( 'status' => 500 ) );
		}

		$scope = isset( $args['scope'] ) ? sanitize_key( $args['scope'] ) : 'both';
		if ( ! in_array( $scope, array( 'online', 'instore', 'both' ), true ) ) {
			$scope = 'both';
		}

		$data = array(
			'code'           => $code,
			'type'           => $type,
			'value'          => $value,
			'currency'       => isset( $args['currency'] ) ? substr( strtoupper( sanitize_text_field( $args['currency'] ) ), 0, 3 ) : 'AUD',
			'min_spend'      => round( (float) ( isset( $args['min_spend'] ) ? $args['min_spend'] : 0 ), 2 ),
			'scope'          => $scope,
			'location_id'    => isset( $args['location_id'] ) ? absint( $args['location_id'] ) : 0,
			'single_use'     => isset( $args['single_use'] ) ? (int) (bool) $args['single_use'] : 1,
			'status'         => 'issued',
			'customer_phone' => isset( $args['customer_phone'] ) ? substr( sanitize_text_field( $args['customer_phone'] ), 0, 40 ) : '',
			'customer_email' => isset( $args['customer_email'] ) ? sanitize_email( $args['customer_email'] ) : '',
			'campaign'       => isset( $args['campaign'] ) ? substr( sanitize_key( $args['campaign'] ), 0, 40 ) : '',
			'valid_from'     => ! empty( $args['valid_from'] ) ? sanitize_text_field( $args['valid_from'] ) : null,
			'valid_to'       => ! empty( $args['valid_to'] ) ? sanitize_text_field( $args['valid_to'] ) : null,
			'meta'           => ! empty( $args['meta'] ) ? wp_json_encode( $args['meta'] ) : null,
			'created_at'     => $now,
			'updated_at'     => $now,
		);
		$formats = array( '%s', '%s', '%f', '%s', '%f', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		$ok = $wpdb->insert( $table, $data, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $ok ) {
			return new WP_Error( 'doughboss_voucher_insert', __( 'Could not create the voucher.', 'doughboss' ), array( 'status' => 500 ) );
		}

		return array(
			'id'   => (int) $wpdb->insert_id,
			'code' => $code,
		);
	}

	/**
	 * Fetch a voucher row by code (case-insensitive; codes are stored upper-case).
	 *
	 * Resolves by the EXACT (upper-cased, trimmed, space-stripped) code first, so a
	 * stored code keeps matching even when its brand prefix / numeric segment
	 * contains O / 0 / 1. Only if that misses is the input folded through
	 * DoughBoss_Coupon_Code::normalize() as a typo-recovery retry.
	 *
	 * @param string $code Voucher code.
	 * @return object|null
	 */
	public static function find_by_code( $code ) {
		global $wpdb;
		$table = self::table();

		// 1) EXACT match on the lightly-cleaned input (upper-cased, trimmed, internal
		//    whitespace removed) — NO character folding. A stored code carries a brand
		//    prefix and numeric campaign segment (e.g. SNOW110025) that legitimately
		//    contain O / 0 / 1, which the typo-folding normalize() would rewrite. So a
		//    correctly entered code must resolve by its exact stored form first.
		$exact = preg_replace( '/\s+/', '', strtoupper( trim( (string) $code ) ) );
		if ( '' !== $exact ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", $exact ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $row ) {
				return $row;
			}
		}

		// 2) Typo-recovery fallback: fold ambiguous characters (O/0 -> Q, I/L/1 -> 7)
		//    and retry. Runs only when the exact lookup missed and folding actually
		//    changes the string, so it never blocks an exact match and adds no query
		//    for clean codes.
		$folded = strtoupper( trim( (string) DoughBoss_Coupon_Code::normalize( $code ) ) );
		if ( '' === $folded || $folded === $exact ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", $folded ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Work out the discount a voucher applies to a given (server-computed)
	 * subtotal, validating status, window, min-spend and scope. No side effects.
	 *
	 * @param object $row      Voucher row.
	 * @param float  $subtotal Server-computed cart subtotal.
	 * @param string $channel  'online' or 'instore'.
	 * @return array array{ valid:bool, amount:float, reason:string }.
	 */
	public static function evaluate( $row, $subtotal, $channel = 'online' ) {
		$subtotal = max( 0, (float) $subtotal );
		$fail     = array(
			'valid'  => false,
			'amount' => 0.0,
			'reason' => 'invalid',
		);

		if ( ! $row || 'issued' !== $row->status ) {
			return $fail;
		}
		if ( 'both' !== $row->scope && $channel !== $row->scope ) {
			return $fail;
		}
		$now = current_time( 'timestamp' );
		if ( ! empty( $row->valid_from ) && strtotime( $row->valid_from ) > $now ) {
			return $fail;
		}
		if ( ! empty( $row->valid_to ) && strtotime( $row->valid_to ) < $now ) {
			return $fail;
		}
		if ( (float) $row->min_spend > 0 && $subtotal < (float) $row->min_spend ) {
			$fail['reason'] = 'min_spend';
			return $fail;
		}

		if ( 'percent' === $row->type ) {
			$amount = round( $subtotal * (float) $row->value / 100, 2 );
		} else {
			$amount = (float) $row->value;
		}
		$amount = max( 0, min( $amount, $subtotal ) );

		return array(
			'valid'  => true,
			'amount' => $amount,
			'reason' => 'ok',
		);
	}

	/**
	 * Reserve an eligible voucher for one immutable payment checkout.
	 *
	 * The voucher remains `issued` so existing validation/admin screens remain
	 * compatible, but another checkout cannot reserve or redeem it until this
	 * lease expires. The named database lock serializes reservation, redemption,
	 * staff scanning and voiding for this voucher.
	 *
	 * @param string $code            Voucher code.
	 * @param float  $subtotal        Server-computed cart subtotal.
	 * @param string $channel         online|instore.
	 * @param string $reservation_key Immutable SHA-256 checkout key.
	 * @param int    $ttl_seconds     Lease lifetime in seconds.
	 * @return array|WP_Error array{code:string,amount:float,reservation_key:string,expires_at:int}.
	 */
	public static function reserve( $code, $subtotal, $channel, $reservation_key, $ttl_seconds = self::RESERVATION_TTL_SECONDS ) {
		global $wpdb;

		$key = self::normalise_reservation_key( $reservation_key );
		if ( '' === $key ) {
			return new WP_Error( 'doughboss_voucher_reservation_key', __( 'The voucher checkout session is invalid. Please refresh and try again.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$generic = new WP_Error( 'doughboss_voucher_invalid', __( 'This voucher code isnâ€™t valid.', 'doughboss' ), array( 'status' => 422 ) );
		if ( ! DoughBoss_Coupon_Code::validate( $code ) ) {
			return $generic;
		}

		$row = self::find_by_code( $code );
		if ( ! $row ) {
			return $generic;
		}
		$voucher_id = (int) $row->id;
		if ( ! self::acquire_voucher_lock( $voucher_id ) ) {
			return self::busy_error();
		}

		try {
			$row = self::find_by_id( $voucher_id );
			$eval = self::evaluate( $row, $subtotal, $channel );
			if ( ! $row || ! $eval['valid'] ) {
				if ( $row && 'min_spend' === $eval['reason'] ) {
					return new WP_Error( 'doughboss_voucher_min', __( 'Your order doesnâ€™t meet this voucherâ€™s minimum spend.', 'doughboss' ), array( 'status' => 422 ) );
				}
				return $generic;
			}

			$now         = time();
			$ttl         = max( 300, min( 86400, (int) $ttl_seconds ) );
			$expires     = $now + $ttl;
			$reservation = self::row_reservation( $row );
			if ( $reservation && (int) $reservation['expires_at'] > $now ) {
				if ( ! hash_equals( (string) $reservation['key'], $key ) ) {
					return self::reserved_error();
				}
			}

			// A same-owner retry may create/retrieve a Stripe Session much later
			// than its first attempt. Renew before provisioning so the lease always
			// outlives a newly-created Session plus webhook-delivery grace.
			$meta = self::row_meta( $row );
			$meta[ self::RESERVATION_META_KEY ] = array(
				'key'        => $key,
				'expires_at' => $expires,
			);
			$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table(),
				array(
					'meta'       => self::encode_meta( $meta ),
					'updated_at' => current_time( 'mysql' ),
				),
				array(
					'id'     => (int) $row->id,
					'status' => 'issued',
				),
				array( '%s', '%s' ),
				array( '%d', '%s' )
			);
			if ( 1 !== (int) $updated ) {
				$latest             = self::find_by_id( $voucher_id );
				$latest_reservation = self::row_reservation( $latest );
				if ( ! $latest_reservation || ! hash_equals( (string) $latest_reservation['key'], $key ) || (int) $latest_reservation['expires_at'] < $expires ) {
					return new WP_Error( 'doughboss_voucher_reservation_storage', __( 'The voucher could not be reserved safely. Please try again.', 'doughboss' ), array( 'status' => 503 ) );
				}
				$expires = (int) $latest_reservation['expires_at'];
			}

			return array(
				'code'            => (string) $row->code,
				'amount'          => (float) $eval['amount'],
				'reservation_key' => $key,
				'expires_at'      => $expires,
			);
		} finally {
			self::release_voucher_lock( $voucher_id );
		}
	}

	/**
	 * Release a matching checkout lease without touching a newer reservation.
	 *
	 * @param string $code            Voucher code.
	 * @param string $reservation_key Immutable SHA-256 checkout key.
	 * @return bool True when the matching lease is absent after this call.
	 */
	public static function release_reservation( $code, $reservation_key ) {
		global $wpdb;

		$key = self::normalise_reservation_key( $reservation_key );
		$row = '' !== $key ? self::find_by_code( $code ) : null;
		if ( ! $row ) {
			return false;
		}
		$voucher_id = (int) $row->id;
		if ( ! self::acquire_voucher_lock( $voucher_id ) ) {
			return false;
		}

		try {
			$row = self::find_by_id( $voucher_id );
			if ( ! $row ) {
				return false;
			}
			$reservation = self::row_reservation( $row );
			if ( ! $reservation || ! hash_equals( (string) $reservation['key'], $key ) ) {
				return true;
			}
			$meta = self::row_meta( $row );
			unset( $meta[ self::RESERVATION_META_KEY ] );
			$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table(),
				array(
					'meta'       => self::encode_meta( $meta ),
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => (int) $row->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			if ( 1 === (int) $updated ) {
				return true;
			}
			// A concurrent external status transition can legitimately produce a
			// zero-row write. Only report success after proving our lease is gone.
			$latest = self::find_by_id( $voucher_id );
			$current = self::row_reservation( $latest );
			return $latest && ( ! $current || ! hash_equals( (string) $current['key'], $key ) );
		} finally {
			self::release_voucher_lock( $voucher_id );
		}
	}

	/** @return object|null */
	private static function find_by_id( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return array */
	private static function row_meta( $row ) {
		$meta = $row && isset( $row->meta ) && '' !== (string) $row->meta ? json_decode( (string) $row->meta, true ) : array();
		return is_array( $meta ) ? $meta : array();
	}

	/** @return string */
	private static function encode_meta( array $meta ) {
		$json = wp_json_encode( $meta );
		return false === $json ? '{}' : $json;
	}

	/** @return array|null */
	private static function row_reservation( $row ) {
		$meta = self::row_meta( $row );
		if ( empty( $meta[ self::RESERVATION_META_KEY ] ) || ! is_array( $meta[ self::RESERVATION_META_KEY ] ) ) {
			return null;
		}
		$reservation = $meta[ self::RESERVATION_META_KEY ];
		$key         = isset( $reservation['key'] ) ? self::normalise_reservation_key( $reservation['key'] ) : '';
		$expires     = isset( $reservation['expires_at'] ) ? (int) $reservation['expires_at'] : 0;
		return '' !== $key && $expires > 0 ? array( 'key' => $key, 'expires_at' => $expires ) : null;
	}

	/** @return string */
	private static function normalise_reservation_key( $value ) {
		$value = strtolower( sanitize_text_field( (string) $value ) );
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	/** @return bool */
	private static function acquire_voucher_lock( $voucher_id ) {
		global $wpdb;
		$lock = self::voucher_lock_name( $voucher_id );
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 3 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** @return void */
	private static function release_voucher_lock( $voucher_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::voucher_lock_name( $voucher_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** @return string */
	private static function voucher_lock_name( $voucher_id ) {
		return substr( 'dbv_v_' . absint( $voucher_id ), 0, 64 );
	}

	/** @return WP_Error */
	private static function reserved_error() {
		return new WP_Error( 'doughboss_voucher_reserved', __( 'This voucher is already being used in another checkout. Please wait a moment and try again.', 'doughboss' ), array( 'status' => 409 ) );
	}

	/** @return WP_Error */
	private static function busy_error() {
		return new WP_Error( 'doughboss_voucher_busy', __( 'This voucher is busy right now. Please wait a moment and try again.', 'doughboss' ), array( 'status' => 503 ) );
	}

	/**
	 * Atomically redeem a single-use voucher.
	 *
	 * Wins the race via a conditional UPDATE (status issued -> redeemed); only
	 * the winner writes the immutable redemption row. The discount is recomputed
	 * here from the row + subtotal, never taken from the caller.
	 *
	 * @param string $code     Voucher code.
	 * @param float  $subtotal Server-computed cart subtotal.
	 * @param string $channel  'online' or 'instore'.
	 * @param array  $extra    { idempotency_key, reservation_key, location_id, pospal_ticket_no }.
	 * @return array|WP_Error array{ code, amount } or error.
	 */
	public static function redeem( $code, $subtotal, $channel = 'online', array $extra = array() ) {
		global $wpdb;

		// Same opaque error whether not-found or ineligible — no enumeration.
		$generic = new WP_Error( 'doughboss_voucher_invalid', __( 'This voucher code isn’t valid.', 'doughboss' ), array( 'status' => 422 ) );

		// Fast-reject a code that IS in the new check-character format but whose
		// check char doesn't recompute — a typo or guess — before touching the DB.
		// validate() returns true for legacy / unknown-format codes, so a legacy
		// code is never rejected here and always falls through to the DB lookup.
		if ( ! DoughBoss_Coupon_Code::validate( $code ) ) {
			return $generic;
		}

		$idem = isset( $extra['idempotency_key'] ) && '' !== $extra['idempotency_key']
			? substr( sanitize_text_field( $extra['idempotency_key'] ), 0, 64 )
			: '';
		$reservation_key = isset( $extra['reservation_key'] ) && '' !== (string) $extra['reservation_key']
			? self::normalise_reservation_key( $extra['reservation_key'] )
			: '';
		if ( isset( $extra['reservation_key'] ) && '' !== (string) $extra['reservation_key'] && '' === $reservation_key ) {
			return new WP_Error( 'doughboss_voucher_reservation_key', __( 'The voucher checkout session is invalid. Please refresh and try again.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$row = self::find_by_code( $code );
		if ( ! $row ) {
			return $generic;
		}

		$voucher_id = (int) $row->id;
		if ( ! self::acquire_voucher_lock( $voucher_id ) ) {
			return self::busy_error();
		}

		$result     = null;
		$action_row = null;
		try {
			$row = self::find_by_id( $voucher_id );
			if ( ! $row ) {
				return $generic;
			}

			// Every replay reads under the same voucher lock as revert/link. An
			// unlinked audit is reversible, so returning it before serialization
			// could let a concurrent rollback delete the result we just promised.
			if ( '' !== $idem ) {
				$prior = self::redemption_by_key( $idem );
				if ( $prior ) {
					return hash_equals( (string) $row->code, (string) $prior->code )
						? array( 'code' => $prior->code, 'amount' => (float) $prior->amount_applied )
						: $generic;
				}
			}

			$eval = self::evaluate( $row, $subtotal, $channel );
			if ( ! $eval['valid'] ) {
				if ( 'min_spend' === $eval['reason'] ) {
					return new WP_Error( 'doughboss_voucher_min', __( 'Your order doesn’t meet this voucher’s minimum spend.', 'doughboss' ), array( 'status' => 422 ) );
				}
				return $generic;
			}

			$reservation = self::row_reservation( $row );
			if ( $reservation ) {
				$same_lease = '' !== $reservation_key && hash_equals( (string) $reservation['key'], $reservation_key );
				$active     = (int) $reservation['expires_at'] > time();
				// Matching paid checkouts may redeem during webhook-delivery grace
				// after nominal expiry. Active different/no-key callers always lose.
				if ( ( $active && ! $same_lease ) || ( '' !== $reservation_key && ! $same_lease ) ) {
					return self::reserved_error();
				}
			}

			$table         = self::table();
			$now           = current_time( 'mysql' );
			$previous_meta = isset( $row->meta ) && '' !== (string) $row->meta ? (string) $row->meta : '{}';
			$meta          = self::row_meta( $row );
			unset( $meta[ self::RESERVATION_META_KEY ] );

			// Atomic claim + lease removal: only one process can flip issued ->
			// redeemed, and no stale reservation survives a successful claim.
			$claimed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "UPDATE {$table} SET status = %s, meta = %s, updated_at = %s WHERE id = %d AND status = %s", 'redeemed', self::encode_meta( $meta ), $now, $voucher_id, 'issued' )
			);
			if ( 1 !== (int) $claimed ) {
				return new WP_Error( 'doughboss_voucher_used', __( 'This voucher has already been used.', 'doughboss' ), array( 'status' => 409 ) );
			}

			if ( '' === $idem ) {
				$idem = md5( $voucher_id . '|' . $channel . '|' . $now . '|' . wp_rand() );
			}

			$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::redemptions_table(),
				array(
					'voucher_id'       => $voucher_id,
					'channel'          => 'instore' === $channel ? 'instore' : 'online',
					'pospal_ticket_no' => isset( $extra['pospal_ticket_no'] ) ? substr( sanitize_text_field( $extra['pospal_ticket_no'] ), 0, 64 ) : '',
					'location_id'      => isset( $extra['location_id'] ) ? absint( $extra['location_id'] ) : (int) $row->location_id,
					'amount_applied'   => $eval['amount'],
					'idempotency_key'  => $idem,
					'redeemed_at'      => $now,
				),
				array( '%d', '%s', '%s', '%d', '%f', '%s', '%s' )
			);

			// Audit is mandatory. Restore both issued status and the exact prior
			// reservation so an order retry does not expose it to a rival checkout.
			if ( ! $inserted ) {
				$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->prepare( "UPDATE {$table} SET status = %s, meta = %s, updated_at = %s WHERE id = %d AND status = %s", 'issued', $previous_meta, current_time( 'mysql' ), $voucher_id, 'redeemed' )
				);
				return new WP_Error( 'doughboss_voucher_audit', __( 'Could not record the redemption. Please try again.', 'doughboss' ), array( 'status' => 500 ) );
			}

			$action_row = $row;
			$result     = array(
				'code'   => $row->code,
				'amount' => $eval['amount'],
			);
		} finally {
			self::release_voucher_lock( $voucher_id );
		}

		/**
		 * Fires after a voucher is redeemed (e.g. to revoke the mirrored POSPal
		 * coupon so it can't also be used in-store). Wired in a later milestone.
		 *
		 * @param object $row     Voucher row.
		 * @param float  $amount  Amount applied.
		 * @param string $channel Redemption channel.
		 */
		do_action( 'doughboss_voucher_redeemed', $action_row, $result['amount'], $channel );

		return $result;
	}

	/**
	 * Look up a recorded redemption by its idempotency key, for idempotent
	 * replay. Returns an object with the voucher `code` and `amount_applied`,
	 * or null when the key has not been used.
	 *
	 * @param string $idempotency_key Idempotency key.
	 * @return object|null
	 */
	public static function redemption_by_key( $idempotency_key ) {
		global $wpdb;
		$key = substr( sanitize_text_field( (string) $idempotency_key ), 0, 64 );
		if ( '' === $key ) {
			return null;
		}
		$redemptions = self::redemptions_table();
		$vouchers    = self::table();
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT r.amount_applied, v.code FROM {$redemptions} r INNER JOIN {$vouchers} v ON v.id = r.voucher_id WHERE r.idempotency_key = %s", $key )
		);
	}

	/**
	 * Attach an order id to a redemption row, linking an online redemption to the
	 * order it discounted. Link and revert share the voucher lock, then take a
	 * row lock on the audit so a paid order can never be unlinked/reissued by a
	 * concurrent failed worker.
	 *
	 * @param string $idempotency_key Redemption idempotency key.
	 * @param int    $order_id        Order id.
	 * @return bool True when this order owns the redemption after the call.
	 */
	public static function link_redemption_to_order( $idempotency_key, $order_id ) {
		global $wpdb;
		$key = substr( sanitize_text_field( (string) $idempotency_key ), 0, 64 );
		$oid = absint( $order_id );
		if ( '' === $key || ! $oid ) {
			return false;
		}
		$table      = self::redemptions_table();
		$redemption = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT voucher_id, order_id FROM {$table} WHERE idempotency_key = %s", $key )
		);
		$voucher_id = $redemption ? (int) $redemption->voucher_id : 0;
		if ( ! $voucher_id || ! self::acquire_voucher_lock( $voucher_id ) ) {
			return false;
		}

		$transaction = false;
		$committed   = false;
		$linked      = false;
		try {
			$transaction = false !== $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $transaction ) {
				return false;
			}
			$current = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "SELECT voucher_id, order_id FROM {$table} WHERE idempotency_key = %s FOR UPDATE", $key )
			);
			$row = $current && (int) $current->voucher_id === $voucher_id ? self::find_by_id( $voucher_id ) : null;
			if ( ! $row || 'redeemed' !== (string) $row->status ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$transaction = false;
				return false;
			}

			$current_order_id = (int) $current->order_id;
			if ( $oid === $current_order_id ) {
				$linked = true;
			} elseif ( 0 === $current_order_id ) {
				$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->prepare( "UPDATE {$table} SET order_id = %d WHERE idempotency_key = %s AND voucher_id = %d AND order_id = 0", $oid, $key, $voucher_id )
				);
				$linked = 1 === (int) $updated;
			}

			if ( ! $linked ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$transaction = false;
				return false;
			}
			$committed   = false !== $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$transaction = ! $committed;
			return $committed;
		} finally {
			if ( $transaction && ! $committed ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			self::release_voucher_lock( $voucher_id );
		}
	}

	/**
	 * Undo a just-made redemption when the order it was for failed to save:
	 * delete the redemption row and flip the voucher back to issued, so a retry
	 * can redeem it cleanly instead of the customer losing the voucher.
	 *
	 * @param string $idempotency_key Redemption idempotency key.
	 * @param string $reservation_key Optional checkout key to restore as a lease.
	 * @param string $payment_intent_id Optional canonical payment reference to protect a winning order.
	 * @return void
	 */
	public static function revert_redemption( $idempotency_key, $reservation_key = '', $payment_intent_id = '' ) {
		global $wpdb;
		$key                 = substr( sanitize_text_field( (string) $idempotency_key ), 0, 64 );
		$raw_reservation_key = (string) $reservation_key;
		$reservation_key     = '' !== $raw_reservation_key ? self::normalise_reservation_key( $raw_reservation_key ) : '';
		$payment_intent_id   = sanitize_text_field( (string) $payment_intent_id );
		if ( '' !== $raw_reservation_key && '' === $reservation_key ) {
			return;
		}
		if ( '' === $key ) {
			return;
		}
		$redemptions = self::redemptions_table();
		$redemption  = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT voucher_id, order_id FROM {$redemptions} WHERE idempotency_key = %s", $key )
		);
		$voucher_id = $redemption ? (int) $redemption->voucher_id : 0;
		if ( ! $voucher_id ) {
			return;
		}
		if ( ! self::acquire_voucher_lock( $voucher_id ) ) {
			return;
		}

		$locked_voucher_id = $voucher_id;
		$transaction       = false;
		$committed         = false;
		try {
			$transaction = false !== $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $transaction ) {
				return;
			}
			// Re-read after both locks are held; another retry may have already
			// reverted the audit row while this request was waiting.
			$current_redemption = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "SELECT voucher_id, order_id FROM {$redemptions} WHERE idempotency_key = %s FOR UPDATE", $key )
			);
			$current_voucher_id = $current_redemption ? (int) $current_redemption->voucher_id : 0;
			$row = $current_voucher_id === $locked_voucher_id && 0 === (int) $current_redemption->order_id
				? self::find_by_id( $locked_voucher_id )
				: null;
			if ( ! $row || 'redeemed' !== (string) $row->status ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$transaction = false;
				return;
			}

			// Another browser/webhook worker may have committed the paid order
			// after this worker saw its own create error. The provider reference is
			// unique on orders: if it now has a winner, atomically link instead of
			// reissuing the voucher. If revert won earlier, the successful worker's
			// post-create redeem below recreates and links the audit.
			$winning_order_id = '' !== $payment_intent_id && class_exists( 'DoughBoss_Order' )
				? DoughBoss_Order::find_id_by_payment_intent( $payment_intent_id, true )
				: 0;
			if ( ! $winning_order_id && '' !== $reservation_key && class_exists( 'DoughBoss_Order' ) ) {
				$winning_order_id = DoughBoss_Order::find_id_by_checkout_key( $reservation_key, true );
			}
			if ( $winning_order_id ) {
				$linked = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->prepare( "UPDATE {$redemptions} SET order_id = %d WHERE idempotency_key = %s AND voucher_id = %d AND order_id = 0", $winning_order_id, $key, $locked_voucher_id )
				);
				if ( 1 !== (int) $linked ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$transaction = false;
					return;
				}
				$committed   = false !== $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$transaction = ! $committed;
				return;
			}

			$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "DELETE FROM {$redemptions} WHERE idempotency_key = %s AND voucher_id = %d AND order_id = 0", $key, $locked_voucher_id )
			);
			$meta    = self::row_meta( $row );
			if ( '' !== $reservation_key ) {
				$meta[ self::RESERVATION_META_KEY ] = array(
					'key'        => $reservation_key,
					'expires_at' => time() + self::RESERVATION_TTL_SECONDS,
				);
			} else {
				unset( $meta[ self::RESERVATION_META_KEY ] );
			}
			$table   = self::table();
			$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "UPDATE {$table} SET status = %s, meta = %s, updated_at = %s WHERE id = %d AND status = %s", 'issued', self::encode_meta( $meta ), current_time( 'mysql' ), $locked_voucher_id, 'redeemed' )
			);
			if ( 1 !== (int) $deleted || 1 !== (int) $updated ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$transaction = false;
				return;
			}
			$committed   = false !== $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$transaction = ! $committed;
		} finally {
			if ( $transaction && ! $committed ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			self::release_voucher_lock( $locked_voucher_id );
		}
	}

	/**
	 * Defined claim campaigns. Owner-editable via the 'voucher_campaigns'
	 * setting; falls back to the defaults below.
	 *
	 * @return array[] Keyed by slug.
	 */
	public static function campaigns() {
		$stored = DoughBoss_Settings::get( 'voucher_campaigns', null );
		if ( is_array( $stored ) && ! empty( $stored ) ) {
			return $stored;
		}
		return self::default_campaigns();
	}

	/**
	 * Default campaigns — the Dough Boss × Snow Boss student launch: a single
	 * $5 voucher with a daily pool of 100 claims that releases fresh every day.
	 * (The $10 tier that previously shared this pool has been retired — $5 is
	 * the only student voucher going forward.)
	 *
	 * The daily pool is expressed with `cap_group` so the mechanism can still
	 * pool multiple campaigns together in future without a schema change; today
	 * it pools only this one campaign against itself. Owners can override the
	 * whole set via the 'voucher_campaigns' setting.
	 *
	 * @return array[]
	 */
	public static function default_campaigns() {
		return array(
			// Primary Dough Boss student voucher. The slug is what the CLI and REST
			// address the campaign by; codes issued under it use the `DOUGH-` prefix.
			'dough5' => array(
				'slug'      => 'dough5',
				'label'     => '$5 Student Voucher (Dough Boss)',
				'type'      => 'amount',
				'value'     => 5.00,
				'prefix'    => 'DOUGH',
				'daily_cap' => 100,
				'cap_group' => 'student',
				'scope'     => 'both',
				// Student offers are allocated only after the same eligible
				// education email has been entered twice. claim() rechecks this.
				'requires_student_email' => 1,
				'active'    => 1,
			),
			// Backwards-compat alias: any voucher code issued under the retired
			// Snow-Boss dual-brand campaign (SNOW-prefix, slug `snow5`) stays
			// redeemable. The alias is dormant for new issuance (`active` = 0) so
			// nothing NEW is minted under the old brand, and the daily cap sits at
			// zero so the CLI/REST rejects a fresh claim; but existing rows are
			// left untouched and continue to redeem at the till.
			'snow5'  => array(
				'slug'      => 'snow5',
				'label'     => '$5 Student Voucher (legacy — Snow Boss launch)',
				'type'      => 'amount',
				'value'     => 5.00,
				'prefix'    => 'SNOW',
				'daily_cap' => 0,
				'cap_group' => 'student',
				'scope'     => 'both',
				'active'    => 0,
			),
		);
	}

	/**
	 * Whether a campaign is a student offer that requires an education email.
	 *
	 * The student cap group is always protected, even when an older owner-saved
	 * campaign definition predates the requires_student_email flag.
	 *
	 * @param array $campaign Campaign definition.
	 * @return bool
	 */
	public static function campaign_requires_student_email( array $campaign ) {
		return ! empty( $campaign['requires_student_email'] )
			|| ( isset( $campaign['cap_group'] ) && 'student' === sanitize_key( $campaign['cap_group'] ) );
	}

	/**
	 * Validate an education email without accepting lookalike domains.
	 *
	 * @param string $email Candidate address.
	 * @return bool
	 */
	public static function eligible_student_email( $email ) {
		$email = strtolower( sanitize_email( (string) $email ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}

		$at = strrpos( $email, '@' );
		if ( false === $at ) {
			return false;
		}
		$domain = substr( $email, $at + 1 );
		return 1 === preg_match( '/(?:^|\.)edu(?:\.au)?$/i', $domain );
	}

	/**
	 * Whether a student email has already received a voucher in this allocation
	 * group. A student voucher is a one-time benefit, not a daily repeat offer.
	 *
	 * @param array  $campaign Campaign definition.
	 * @param string $email Canonical education email.
	 * @return bool
	 */
	private static function student_email_claimed( array $campaign, $email ) {
		global $wpdb;
		$slugs = self::campaign_slugs_for_allocation( $campaign );
		if ( empty( $slugs ) ) {
			return false;
		}

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		$params       = $slugs;
		$params[]     = strtolower( sanitize_email( $email ) );
		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE campaign IN ({$placeholders}) AND LOWER(customer_email) = %s LIMIT 1",
				$params
			)
		);
	}

	/**
	 * Campaign slugs that share one allocation. This keeps the one-per-student
	 * rule intact even when a legacy campaign shares the same pool.
	 *
	 * @param array $campaign Campaign definition.
	 * @return string[]
	 */
	private static function campaign_slugs_for_allocation( array $campaign ) {
		$group = isset( $campaign['cap_group'] ) ? sanitize_key( $campaign['cap_group'] ) : '';
		if ( '' === $group ) {
			$slug = isset( $campaign['slug'] ) ? sanitize_key( $campaign['slug'] ) : '';
			return '' === $slug ? array() : array( $slug );
		}

		$slugs = array();
		foreach ( self::campaigns() as $candidate ) {
			$candidate_group = isset( $candidate['cap_group'] ) ? sanitize_key( $candidate['cap_group'] ) : '';
			if ( $group === $candidate_group && ! empty( $candidate['slug'] ) ) {
				$slugs[] = sanitize_key( $candidate['slug'] );
			}
		}

		return array_values( array_unique( array_filter( $slugs ) ) );
	}

	/**
	 * How many vouchers a campaign has issued so far today (site-local time).
	 *
	 * @param string $slug Campaign slug.
	 * @return int
	 */
	public static function claimed_today( $slug ) {
		return self::claimed_today_slugs( array( $slug ) );
	}

	/**
	 * How many vouchers were issued today (site-local) across one or more
	 * campaign slugs — the primitive behind both per-campaign and shared-pool
	 * (cap_group) counts.
	 *
	 * @param string[] $slugs Campaign slugs.
	 * @return int
	 */
	public static function claimed_today_slugs( array $slugs ) {
		global $wpdb;
		$slugs = array_values( array_unique( array_filter( array_map( 'sanitize_key', $slugs ) ) ) );
		if ( empty( $slugs ) ) {
			return 0;
		}
		$table        = self::table();
		$start        = current_time( 'Y-m-d' ) . ' 00:00:00';
		$placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		$params       = $slugs;
		$params[]     = $start;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign IN ({$placeholders}) AND created_at >= %s", $params )
		);
	}

	/**
	 * Claims counted today against a campaign's daily cap. When the campaign
	 * declares a `cap_group`, the count is pooled across every campaign sharing
	 * that group; otherwise it is just this campaign's own claims.
	 *
	 * @param array $campaign Campaign definition.
	 * @return int
	 */
	public static function claimed_today_for( array $campaign ) {
		return self::claimed_today_slugs( self::campaign_slugs_for_allocation( $campaign ) );
	}

	/**
	 * Claim a voucher from a daily-capped campaign. Enforces the per-day cap
	 * (released fresh each day) and issues an individual voucher on success.
	 *
	 * @param string $slug Campaign slug.
	 * @param array  $args Extra issue args (customer_phone, customer_email).
	 * @return array|WP_Error array{ id, code } or error.
	 */
	public static function claim( $slug, array $args = array() ) {
		global $wpdb;
		$slug      = sanitize_key( $slug );
		$campaigns = self::campaigns();
		if ( empty( $campaigns[ $slug ] ) || empty( $campaigns[ $slug ]['active'] ) ) {
			return new WP_Error( 'doughboss_campaign', __( 'This offer isn’t available.', 'doughboss' ), array( 'status' => 404 ) );
		}

		$campaign = $campaigns[ $slug ];
		$cap      = (int) ( isset( $campaign['daily_cap'] ) ? $campaign['daily_cap'] : 0 );

		// Student vouchers require the same eligible education email twice. Keep
		// this in the model so REST, CLI and any future UI cannot bypass it.
		$student_email = '';
		if ( self::campaign_requires_student_email( $campaign ) ) {
			$student_email = strtolower( sanitize_email( isset( $args['customer_email'] ) ? $args['customer_email'] : '' ) );
			$confirmation  = strtolower( sanitize_email( isset( $args['customer_email_confirmation'] ) ? $args['customer_email_confirmation'] : '' ) );
			if (
				! self::eligible_student_email( $student_email )
				|| '' === $confirmation
				|| ! hash_equals( $student_email, $confirmation )
			) {
				return new WP_Error(
					'doughboss_student_email',
					__( 'Enter the same valid student email twice. It must end in .edu or .edu.au.', 'doughboss' ),
					array( 'status' => 422 )
				);
			}
			$args['customer_email'] = $student_email;
			unset( $args['customer_email_confirmation'] );
		}

		// Serialize the count -> issue for this campaign's daily pool so two
		// concurrent claims can't both pass the cap check and over-issue. A named
		// DB lock keyed by the shared cap_group (or the slug when ungrouped). A
		// capped campaign fails closed when that lock is unavailable: issuing
		// without serialization can oversubscribe the advertised daily pool.
		$lock = substr( 'dbv_' . ( ! empty( $campaign['cap_group'] ) ? 'g_' . sanitize_key( $campaign['cap_group'] ) : 's_' . $slug ), 0, 64 );
		$got  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 5 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $cap > 0 && 1 !== $got ) {
			return new WP_Error( 'doughboss_campaign_busy', __( 'This offer is busy right now. Please wait a moment and try again.', 'doughboss' ), array( 'status' => 503 ) );
		}

		if ( $cap > 0 && self::claimed_today_for( $campaign ) >= $cap ) {
			if ( 1 === $got ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			return new WP_Error( 'doughboss_campaign_full', __( 'Today’s vouchers have all been claimed — check back tomorrow.', 'doughboss' ), array( 'status' => 409 ) );
		}

		// This check runs while the campaign/pool lock is held, making one
		// allocation per student email across the full campaign period race-safe.
		if ( '' !== $student_email && self::student_email_claimed( $campaign, $student_email ) ) {
			if ( 1 === $got ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			return new WP_Error(
				'doughboss_student_email_used',
				__( 'A voucher has already been allocated to this student email.', 'doughboss' ),
				array( 'status' => 409 )
			);
		}

		$result = self::issue(
			array_merge(
				array(
					'type'       => isset( $campaign['type'] ) ? $campaign['type'] : 'amount',
					'value'      => isset( $campaign['value'] ) ? $campaign['value'] : 0,
					'prefix'     => isset( $campaign['prefix'] ) ? $campaign['prefix'] : 'DB',
					'scope'      => isset( $campaign['scope'] ) ? $campaign['scope'] : 'both',
					'single_use' => 1,
					'campaign'   => $slug,
					'meta'       => array_merge(
						array(
							'campaign' => $slug,
							'label'    => isset( $campaign['label'] ) ? $campaign['label'] : '',
						),
						( isset( $campaign['meta'] ) && is_array( $campaign['meta'] ) ) ? $campaign['meta'] : array()
					),
				),
				$args
			)
		);

		if ( 1 === $got ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		// On a successful issue ($result is the {id,code} array, not a WP_Error),
		// announce the claim so other components (e.g. POSPal sync) can mirror it.
		if ( ! is_wp_error( $result ) && is_array( $result ) && isset( $result['id'], $result['code'] ) ) {
			/**
			 * Fires after a campaign voucher is successfully claimed/issued.
			 *
			 * @param int    $id   New voucher id.
			 * @param string $code New voucher code.
			 * @param string $slug Campaign slug.
			 * @param array  $args Extra issue args passed to claim().
			 */
			do_action( 'doughboss_voucher_claimed', (int) $result['id'], $result['code'], $slug, $args );
		}

		return $result;
	}

	/**
	 * Recent vouchers with their redemption summary (newest first), for admin.
	 *
	 * @param int $limit Max rows.
	 * @return array Row objects.
	 */
	public static function query( $limit = 100 ) {
		global $wpdb;
		$table       = self::table();
		$redemptions = self::redemptions_table();
		$limit       = max( 1, min( 500, (int) $limit ) );
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT v.*, r.redeemed_at, r.amount_applied, r.channel AS redeemed_channel
				FROM {$table} v
				LEFT JOIN {$redemptions} r ON r.voucher_id = v.id
				ORDER BY v.id DESC
				LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Count vouchers in a given status (for the staff dashboard tiles).
	 *
	 * @param string $status issued|redeemed|voided.
	 * @return int
	 */
	public static function count_status( $status ) {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", sanitize_key( $status ) )
		);
	}

	/**
	 * Void an unredeemed voucher.
	 *
	 * @param int $id Voucher id.
	 * @return bool
	 */
	public static function void( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}
		if ( ! self::acquire_voucher_lock( $id ) ) {
			return false;
		}
		try {
			$row = self::find_by_id( $id );
			if ( ! $row || 'issued' !== (string) $row->status ) {
				return false;
			}
			$reservation = self::row_reservation( $row );
			if ( $reservation && (int) $reservation['expires_at'] > time() ) {
				return false;
			}
			$meta = self::row_meta( $row );
			unset( $meta[ self::RESERVATION_META_KEY ] );
			$table = self::table();
			return 1 === (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "UPDATE {$table} SET status = %s, meta = %s, updated_at = %s WHERE id = %d AND status = %s", 'voided', self::encode_meta( $meta ), current_time( 'mysql' ), $id, 'issued' )
			);
		} finally {
			self::release_voucher_lock( $id );
		}
	}
}
