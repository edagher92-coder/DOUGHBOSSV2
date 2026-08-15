<?php
/**
 * Voucher / discount-coupon data model.
 *
 * The plugin is the source of truth for voucher issuance, single-use locking
 * and audit. A voucher can be redeemed online (here, atomically) and â€” once the
 * POSPal in-store leg is wired â€” mirrored as a member coupon at the till.
 *
 * Security model (per review): redemption is a single conditional UPDATE
 * (status issued -> redeemed) so two concurrent redeems can never both win, and
 * an immutable redemption row with a UNIQUE idempotency key backs it. Codes are
 * high-entropy. The applied discount is always recomputed server-side from the
 * voucher row against the server-computed cart subtotal â€” never trusted from a
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
	 * Immutable voucher-management audit table name.
	 *
	 * @return string
	 */
	public static function audit_table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_voucher_audit';
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
		if ( 'amount' === $type && $value > 5.00 ) {
			return new WP_Error( 'doughboss_voucher_value', __( 'Promotional vouchers cannot exceed $5.00.', 'doughboss' ), array( 'status' => 400 ) );
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
			'min_spend'      => max( 3.00, round( (float) ( isset( $args['min_spend'] ) ? $args['min_spend'] : 3.00 ), 2 ) ),
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
		//    whitespace removed) â€” NO character folding. A stored code carries a brand
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
		$minimum_spend = max( 3.00, (float) $row->min_spend );
		if ( $subtotal < $minimum_spend ) {
			$fail['reason'] = 'min_spend';
			return $fail;
		}

		if ( 'percent' === $row->type ) {
			$amount = round( $subtotal * (float) $row->value / 100, 2 );
		} else {
			$amount = (float) $row->value;
		}
		$amount = max( 0, min( $amount, $subtotal, 5.00 ) );

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
	 * @param string $customer_email  Checkout email. Personal QR vouchers are
	 *                                bound here, at the final payment boundary.
	 * @return array|WP_Error array{code:string,amount:float,reservation_key:string,expires_at:int}.
	 */
	public static function reserve( $code, $subtotal, $channel, $reservation_key, $ttl_seconds = self::RESERVATION_TTL_SECONDS, $customer_email = '' ) {
		global $wpdb;

		$key = self::normalise_reservation_key( $reservation_key );
		if ( '' === $key ) {
			return new WP_Error( 'doughboss_voucher_reservation_key', __( 'The voucher checkout session is invalid. Please refresh and try again.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$generic = new WP_Error( 'doughboss_voucher_invalid', __( 'This voucher code isnÃ¢â‚¬â„¢t valid.', 'doughboss' ), array( 'status' => 422 ) );
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
			// QR claim vouchers are issued to one customer email. The public cart
			// may show an estimated discount, but checkout must only reserve it for
			// that same email. This prevents a photographed/shared QR code being
			// spent by someone else online while preserving legitimate till scans.
			if ( $row && 'online' === $channel && '' !== (string) $row->customer_email && ! hash_equals( strtolower( (string) $row->customer_email ), strtolower( sanitize_email( $customer_email ) ) ) ) {
				return $generic;
			}
			if ( ! $row || ! $eval['valid'] ) {
				if ( $row && 'min_spend' === $eval['reason'] ) {
					return new WP_Error( 'doughboss_voucher_min', __( 'Your order doesnÃ¢â‚¬â„¢t meet this voucherÃ¢â‚¬â„¢s minimum spend.', 'doughboss' ), array( 'status' => 422 ) );
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
	public static function release_reservation( $code, $reservation_key )÷®ü¶‰žËkºwµçIÍlÕÍÑ½µ•É}•µ…¥°t€¤€ü€‘…ÉÍlÕÍÑ½µ•É}•µ…¥°t€è€œœ€¤€¤ì($$$‘½¹™¥Éµ…Ñ¥½¸€€ôÍÑÉÑ½±½Ý•È Í…¹¥Ñ¥é•}•µ…¥° ¥ÍÍ•Ð €‘…ÉÍlÕÍÑ½µ•É}•µ…¥±}½¹™¥Éµ…Ñ¥½¸t€¤€ü€‘…ÉÍlÕÍÑ½µ•É}•µ…¥±}½¹™¥Éµ…Ñ¥½¸t€è€œœ€¤€¤ì($$%¥˜€ ($$$$„Í•±˜èé•±¥¥‰±•}ÍÑÕ‘•¹Ñ}•µ…¥° €‘ÍÑÕ‘•¹Ñ}•µ…¥°€¤($$$%ñð€œœ€ôôô€‘½¹™¥Éµ…Ñ¥½¸($$$%ñð€„¡…Í¡}•ÅÕ…±Ì €‘ÍÑÕ‘•¹Ñ}•µ…¥°°€‘½¹™¥Éµ…Ñ¥½¸€¤($$$¤ì($$$%É•ÑÕÉ¸¹•Ü]A}ÉÉ½È ($$$$$‘½Õ¡‰½ÍÍ}ÍÑÕ‘•¹Ñ}•µ…¥°œ°($$$$%}| €¹Ñ•ÈÑ¡”Í…µ”Ù…±¥ÍÑÕ‘•¹Ð•µ…¥°ÑÝ¥”¸%ÐµÕÍÐ•¹¥¸€¹•‘Ô½È€¹•‘Ô¹…Ô¸œ°€‘½Õ¡‰½ÍÌœ€¤°($$$$%…ÉÉ…ä €ÍÑ…ÑÕÌœ€ôø€ÐÈÈ€¤($$$$¤ì($$%ô($$$‘…ÉÍlÕÍÑ½µ•É}•µ…¥°t€ô€‘ÍÑÕ‘•¹Ñ}•µ…¥°ì($$%Õ¹Í•Ð €‘…ÉÍlÕÍÑ½µ•É}•µ…¥±}½¹™¥Éµ…Ñ¥½¸t€¤ì($%ô(($$¼¼M•É¥…±¥é”Ñ¡”½Õ¹Ð€´ø¥ÍÍÕ”™½ÈÑ¡¥Ì…µÁ…¥¸Ì‘…¥±äÁ½½°Í¼ÑÝ¼($$¼¼½¹ÕÉÉ•¹Ð±…¥µÌ…¸Ð‰½Ñ Á…ÍÌÑ¡”…À¡•¬…¹½Ù•Èµ¥ÍÍÕ”¸¹…µ•($$¼¼±½¬­•å•‰äÑ¡”Í¡…É•…Á}É½ÕÀ€¡½ÈÑ¡”Í±ÕœÝ¡•¸Õ¹É½ÕÁ•¤¸($$¼¼…ÁÁ•…µÁ…¥¸™…¥±Ì±½Í•Ý¡•¸Ñ¡…Ð±½¬¥ÌÕ¹…Ù…¥±…‰±”è¥ÍÍÕ¥¹œ($$¼¼Ý¥Ñ¡½ÕÐÍ•É¥…±¥é…Ñ¥½¸…¸½Ù•ÉÍÕ‰ÍÉ¥‰”Ñ¡”…‘Ù•ÉÑ¥Í•‘…¥±äÁ½½°¸($$‘±½¬€ôÍÕ‰ÍÑÈ €‘‰Ù|œ€¸€ €„•µÁÑä €‘…µÁ…¥¹l…Á}É½ÕÀt€¤€ü€|œ€¸Í…¹¥Ñ¥é•}­•ä €‘…µÁ…¥¹l…Á}É½ÕÀt€¤€è€Í|œ€¸€‘Í±Õœ€¤°€À°€ØÐ€¤ì($$‘½Ð€€ô€¡¥¹Ð¤€‘ÝÁ‘ˆ´ù•Ñ}Ù…È €‘ÝÁ‘ˆ´ùÁÉ•Á…É” €M1PQ}1=, •Ì°€•¤œ°€‘±½¬°€Ô€¤€¤ì€¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($%¥˜€ €‘…À€ø€À€˜˜€Ä€„ôô€‘½Ð€¤ì($$%É•ÑÕÉ¸¹•Ü]A}ÉÉ½È €‘½Õ¡‰½ÍÍ}…µÁ…¥¹}‰ÕÍäœ°}| €Q¡¥Ì½™™•È¥Ì‰ÕÍäÉ¥¡Ð¹½Ü¸A±•…Í”Ý…¥Ð„µ½µ•¹Ð…¹ÑÉä……¥¸¸œ°€‘½Õ¡‰½ÍÌœ€¤°…ÉÉ…ä €ÍÑ…ÑÕÌœ€ôø€ÔÀÌ€¤€¤ì($%ô(($%¥˜€ €‘…À€ø€À€˜˜Í•±˜èé±…¥µ•‘}Ñ½‘…å}™½È €‘…µÁ…¥¸€¤€øô€‘…À€¤ì($$%¥˜€ €Ä€ôôô€‘½Ð€¤ì($$$$‘ÝÁ‘ˆ´ùÅÕ•Éä €‘ÝÁ‘ˆ´ùÁÉ•Á…É” €M1PI1M}1=, •Ì¤œ°€‘±½¬€¤€¤ì€¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($$%ô($$%É•ÑÕÉ¸¹•Ü]A}ÉÉ½È €‘½Õ¡‰½ÍÍ}…µÁ…¥¹}™Õ±°œ°}| €Q½‘…çŠeÌÙ½Õ¡•ÉÌ¡…Ù”…±°‰••¸±…¥µ•ƒŠP¡•¬‰…¬Ñ½µ½ÉÉ½Ü¸œ°€‘½Õ¡‰½ÍÌœ€¤°…ÉÉ…ä €ÍÑ…ÑÕÌœ€ôø€ÐÀä€¤€¤ì($%ô(($$¼¼Q¡¥Ì¡•¬ÉÕ¹ÌÝ¡¥±”Ñ¡”…µÁ…¥¸½Á½½°±½¬¥Ì¡•±°µ…­¥¹œ½¹”($$¼¼…±±½…Ñ¥½¸Á•ÈÍÑÕ‘•¹Ð•µ…¥°…É½ÍÌÑ¡”™Õ±°…µÁ…¥¸Á•É¥½É…”µÍ…™”¸($%¥˜€ €œœ€„ôô€‘ÍÑÕ‘•¹Ñ}•µ…¥°€˜˜Í•±˜èéÍÑÕ‘•¹Ñ}•µ…¥±}±…¥µ• €‘…µÁ…¥¸°€‘ÍÑÕ‘•¹Ñ}•µ…¥°€¤€¤ì($$%¥˜€ €Ä€ôôô€‘½Ð€¤ì($$$$‘ÝÁ‘ˆ´ùÅÕ•Éä €‘ÝÁ‘ˆ´ùÁÉ•Á…É” €M1PI1M}1=, •Ì¤œ°€‘±½¬€¤€¤ì€¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($$%ô($$%É•ÑÕÉ¸¹•Ü]A}ÉÉ½È ($$$$‘½Õ¡‰½ÍÍ}ÍÑÕ‘•¹Ñ}•µ…¥±}ÕÍ•œ°($$$%}| €Ù½Õ¡•È¡…Ì…±É•…‘ä‰••¸…±±½…Ñ•Ñ¼Ñ¡¥ÌÍÑÕ‘•¹Ð•µ…¥°¸œ°€‘½Õ¡‰½ÍÌœ€¤°($$$%…ÉÉ…ä €ÍÑ…ÑÕÌœ€ôø€ÐÀä€¤($$$¤ì($%ô(($$‘É•ÍÕ±Ð€ôÍ•±˜èé¥ÍÍÕ” ($$%…ÉÉ…å}µ•É” ($$$%…ÉÉ…ä ($$$$$ÑåÁ”œ€€€€€€€ôø¥ÍÍ•Ð €‘…µÁ…¥¹lÑåÁ”t€¤€ü€‘…µÁ…¥¹lÑåÁ”t€è€…µ½Õ¹Ðœ°($$$$$Ù…±Õ”œ€€€€€€ôø¥ÍÍ•Ð €‘…µÁ…¥¹lÙ…±Õ”t€¤€ü€‘…µÁ…¥¹lÙ…±Õ”t€è€À°($$$$$µ¥¹}ÍÁ•¹œ€€ôø¥ÍÍ•Ð €‘…µÁ…¥¹lµ¥¹}ÍÁ•¹t€¤€ü€‘…µÁ…¥¹lµ¥¹}ÍÁ•¹t€è€Ì¸ÀÀ°($$$$$ÁÉ•™¥àœ€€€€€ôø¥ÍÍ•Ð €‘…µÁ…¥¹lÁÉ•™¥àt€¤€ü€‘…µÁ…¥¹lÁÉ•™¥àt€è€œ°($$$$$Í½Á”œ€€€€€€ôø¥ÍÍ•Ð €‘…µÁ…¥¹lÍ½Á”t€¤€ü€‘…µÁ…¥¹lÍ½Á”t€è€‰½Ñ œ°($$$$$Í¥¹±•}ÕÍ”œ€ôø€Ä°($$$$$…µÁ…¥¸œ€€€ôø€‘Í±Õœ°($$$$$µ•Ñ„œ€€€€€€€ôø…ÉÉ…å}µ•É” ($$$$$%…ÉÉ…ä ($$$$$$$…µÁ…¥¸œ€ôø€‘Í±Õœ°($$$$$$$±…‰•°œ€€€€ôø¥ÍÍ•Ð €‘…µÁ…¥¹l±…‰•°t€¤€ü€‘…µÁ…¥¹l±…‰•°t€è€œœ°($$$$$$¤°($$$$$$ ¥ÍÍ•Ð €‘…µÁ…¥¹lµ•Ñ„t€¤€˜˜¥Í}…ÉÉ…ä €‘…µÁ…¥¹lµ•Ñ„t€¤€¤€ü€‘…µÁ…¥¹lµ•Ñ„t€è…ÉÉ…ä ¤($$$$$¤°($$$$¤°($$$$‘…ÉÌ($$$¤($$¤ì(($%¥˜€ €Ä€ôôô€‘½Ð€¤ì($$$‘ÝÁ‘ˆ´ùÅÕ•Éä €‘ÝÁ‘ˆ´ùÁÉ•Á…É” €M1PI1M}1=, •Ì¤œ°€‘±½¬€¤€¤ì€¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($%ô(($$¼¼=¸„ÍÕ•ÍÍ™Õ°¥ÍÍÕ”€ ‘É•ÍÕ±Ð¥ÌÑ¡”í¥±½‘•ô…ÉÉ…ä°¹½Ð„]A}ÉÉ½È¤°($$¼¼…¹¹½Õ¹”Ñ¡”±…¥´Í¼½Ñ¡•È½µÁ½¹•¹ÑÌ€¡”¹œ¸A=MA…°Íå¹Œ¤…¸µ¥ÉÉ½È¥Ð¸($%¥˜€ €„¥Í}ÝÁ}•ÉÉ½È €‘É•ÍÕ±Ð€¤€˜˜¥Í}…ÉÉ…ä €‘É•ÍÕ±Ð€¤€˜˜¥ÍÍ•Ð €‘É•ÍÕ±Ñl¥t°€‘É•ÍÕ±Ñl½‘”t€¤€¤ì($$$¼¨¨($$$€¨¥É•Ì…™Ñ•È„…µÁ…¥¸Ù½Õ¡•È¥ÌÍÕ•ÍÍ™Õ±±ä±…¥µ•½¥ÍÍÕ•¸($$$€¨($$$€¨Á…É…´¥¹Ð€€€€‘¥€€9•ÜÙ½Õ¡•È¥¸($$$€¨Á…É…´ÍÑÉ¥¹œ€‘½‘”9•ÜÙ½Õ¡•È½‘”¸($$$€¨Á…É…´ÍÑÉ¥¹œ€‘Í±Õœ…µÁ…¥¸Í±Õœ¸($$$€¨Á…É…´…ÉÉ…ä€€‘…ÉÌáÑÉ„¥ÍÍÕ”…ÉÌÁ…ÍÍ•Ñ¼±…¥´ ¤¸($$$€¨¼($$%‘½}…Ñ¥½¸ €‘½Õ¡‰½ÍÍ}Ù½Õ¡•É}±…¥µ•œ°€¡¥¹Ð¤€‘É•ÍÕ±Ñl¥t°€‘É•ÍÕ±Ñl½‘”t°€‘Í±Õœ°€‘…ÉÌ€¤ì($%ô(($%É•ÑÕÉ¸€‘É•ÍÕ±Ðì(%ô(($¼¨¨($€¨I••¹ÐÙ½Õ¡•ÉÌÝ¥Ñ Ñ¡•¥ÈÉ•‘•µÁÑ¥½¸ÍÕµµ…Éä€¡¹•Ý•ÍÐ™¥ÉÍÐ¤°™½È…‘µ¥¸¸($€¨($€¨Á…É…´¥¹Ð€‘±¥µ¥Ð5…àÉ½ÝÌ¸($€¨É•ÑÕÉ¸…ÉÉ…äI½Ü½‰©•ÑÌ¸($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸ÅÕ•Éä €‘±¥µ¥Ð€ô€ÄÀÀ€¤ì($%±½‰…°€‘ÝÁ‘ˆì($$‘Ñ…‰±”€€€€€€€ôÍ•±˜èéÑ…‰±” ¤ì($$‘É•‘•µÁÑ¥½¹Ì€ôÍ•±˜èéÉ•‘•µÁÑ¥½¹Í}Ñ…‰±” ¤ì($$‘±¥µ¥Ð€€€€€€€ôµ…à €Ä°µ¥¸ €ÔÀÀ°€¡¥¹Ð¤€‘±¥µ¥Ð€¤€¤ì($%É•ÑÕÉ¸€‘ÝÁ‘ˆ´ù•Ñ}É•ÍÕ±ÑÌ €¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä°]½É‘AÉ•ÍÌ¹¹AÉ•Á…É•‘ME0¹%¹Ñ•ÉÁ½±…Ñ•‘9½ÑAÉ•Á…É•($$$‘ÝÁ‘ˆ´ùÁÉ•Á…É” ($$$$‰M1PØ¸¨°È¹É•‘••µ•‘}…Ð°È¹…µ½Õ¹Ñ}…ÁÁ±¥•°È¹¡…¹¹•°LÉ•‘••µ•‘}¡…¹¹•°($$$%I=4ì‘Ñ…‰±•ôØ($$$%1P)=%8ì‘É•‘•µÁÑ¥½¹ÍôÈ=8È¹Ù½Õ¡•É}¥€ôØ¹¥9È¹É•‘•µÁÑ¥½¹}ÍÑ…ÑÕÌ€ô€É•‘••µ•œ($$$%=IH	dØ¹¥M($$$%1%5%P€•ˆ°($$$$‘±¥µ¥Ð($$$¤($$¤ì(%ô(($¼¨¨($€¨½Õ¹ÐÙ½Õ¡•ÉÌ¥¸„¥Ù•¸ÍÑ…ÑÕÌ€¡™½ÈÑ¡”ÍÑ…™˜‘…Í¡‰½…ÉÑ¥±•Ì¤¸($€¨($€¨Á…É…´ÍÑÉ¥¹œ€‘ÍÑ…ÑÕÌ¥ÍÍÕ•‘ñÉ•‘••µ•‘ñÙ½¥‘•¸($€¨É•ÑÕÉ¸¥¹Ð($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸½Õ¹Ñ}ÍÑ…ÑÕÌ €‘ÍÑ…ÑÕÌ€¤ì($%±½‰…°€‘ÝÁ‘ˆì($$‘Ñ…‰±”€ôÍ•±˜èéÑ…‰±” ¤ì($%É•ÑÕÉ¸€¡¥¹Ð¤€‘ÝÁ‘ˆ´ù•Ñ}Ù…È €¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä°]½É‘AÉ•ÍÌ¹¹AÉ•Á…É•‘ME0¹%¹Ñ•ÉÁ½±…Ñ•‘9½ÑAÉ•Á…É•($$$‘ÝÁ‘ˆ´ùÁÉ•Á…É” €‰M1P=U9P ¨¤I=4ì‘Ñ…‰±•ô]!IÍÑ…ÑÕÌ€ô€•Ìˆ°Í…¹¥Ñ¥é•}­•ä €‘ÍÑ…ÑÕÌ€¤€¤($$¤ì(%ô(($¼¨¨($€¨I•½É„µ…¹…•µ•¹Ð…Ñ¥½¸Ý¡¥±”Ñ¡”…±±•ÈÌÑÉ…¹Í…Ñ¥½¸¥Ì½Á•¸¸($€¨($€¨Q¡¥Ì¥Ì¥¹Ñ•¹Ñ¥½¹…±±äÁÉ¥Ù…Ñ”è•Ù•ÉäÁÕ‰±¥ŒÁ…Ñ Ñ¡…Ð¥Ù•ÌÙ½Õ¡•ÈÙ…±Õ”($€¨‰…¬µÕÍÐ¡½±Ñ¡”Á•ÈµÙ½Õ¡•È±½¬…¹ÁÉ½Ù”Ñ¡”…Ñ½È™¥ÉÍÐ¸($€¨($€¨Á…É…´¥¹Ð€€€€‘Ù½Õ¡•É}¥€€€Y½Õ¡•È¥¸($€¨Á…É…´¥¹Ð€€€€‘É•‘•µÁÑ¥½¹}¥I•‘•µÁÑ¥½¸¥°½Èé•É¼™½È…¸Õ¹ÕÍ•Ù½Õ¡•È¸($€¨Á…É…´ÍÑÉ¥¹œ€‘•Ù•¹Ñ}ÑåÁ”€€€I•Ù•ÉÍ…°½ÈÙ½¥…Ñ¥½¸¹…µ”¸($€¨Á…É…´ÍÑÉ¥¹œ€‘É•…Í½¸€€€€€€€5…¹‘…Ñ½Éäµ…¹…•È•áÁ±…¹…Ñ¥½¸¸($€¨Á…É…´¥¹Ð€€€€‘…Ñ½É}¥€€€€€]½É‘AÉ•ÍÌµ…¹…•È¥¸($€¨Á…É…´ÍÑÉ¥¹œ€‘…Ñ½É}¹…µ”€€€5…¹…•È‘¥ÍÁ±…ä¹…µ”Í¹…ÁÍ¡½Ð¸($€¨Á…É…´…ÉÉ…ä€€‘‘•Ñ…¥±Ì€€€€€€9½¸µÍ•¹Í¥Ñ¥Ù”½Á•É…Ñ¥½¹…°•Ù¥‘•¹”¸($€¨É•ÑÕÉ¸‰½½°($€¨¼(%ÁÉ¥Ù…Ñ”ÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸É•½É‘}…Õ‘¥Ñ}•Ù•¹Ð €‘Ù½Õ¡•É}¥°€‘É•‘•µÁÑ¥½¹}¥°€‘•Ù•¹Ñ}ÑåÁ”°€‘É•…Í½¸°€‘…Ñ½É}¥°€‘…Ñ½É}¹…µ”°…ÉÉ…ä€‘‘•Ñ…¥±Ì€ô…ÉÉ…ä ¤€¤ì($%±½‰…°€‘ÝÁ‘ˆì($%É•ÑÕÉ¸™…±Í”€„ôô€‘ÝÁ‘ˆ´ù¥¹Í•ÉÐ €¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($$%Í•±˜èé…Õ‘¥Ñ}Ñ…‰±” ¤°($$%…ÉÉ…ä ($$$$Ù½Õ¡•É}¥œ€€€€ôø…‰Í¥¹Ð €‘Ù½Õ¡•É}¥€¤°($$$$É•‘•µÁÑ¥½¹}¥œ€€ôø…‰Í¥¹Ð €‘É•‘•µÁÑ¥½¹}¥€¤°($$$$•Ù•¹Ñ}ÑåÁ”œ€€€€€ôøÍ…¹¥Ñ¥é•}­•ä €‘•Ù•¹Ñ}ÑåÁ”€¤°($$$$É•…Í½¸œ€€€€€€€€€ôøÍÕ‰ÍÑÈ Í…¹¥Ñ¥é•}Ñ•áÑ}™¥•± €‘É•…Í½¸€¤°€À°€ÔÀÀ€¤°($$$$…Ñ½É}ÕÍ•É}¥œ€€ôø…‰Í¥¹Ð €‘…Ñ½É}¥€¤°($$$$…Ñ½É}¹…µ”œ€€€€€ôøÍÕ‰ÍÑÈ Í…¹¥Ñ¥é•}Ñ•áÑ}™¥•± €‘…Ñ½É}¹…µ”€¤°€À°€ÄäÄ€¤°($$$$‘•Ñ…¥±Í}©Í½¸œ€€€ôøÝÁ}©Í½¹}•¹½‘” €‘‘•Ñ…¥±Ì€¤°($$$$½ÕÉÉ•‘}…Ðœ€€€€ôøÕÉÉ•¹Ñ}Ñ¥µ” €µåÍÅ°œ€¤°($$$¤°($$%…ÉÉ…ä €œ•œ°€œ•œ°€œ•Ìœ°€œ•Ìœ°€œ•œ°€œ•Ìœ°€œ•Ìœ°€œ•Ìœ€¤($$¤ì(%ô(($¼¨¨($€¨I”µ½Á•¸„•¹Õ¥¹•±äµ¥ÌµÍ…¹¹•¥¸µÍÑ½É”Ù½Õ¡•È¸($€¨($€¨=¹±ä„¹…µ•µ…¹…•È…¸É•ÅÕ•ÍÐÑ¡¥ÌÑ¡É½Õ Ñ¡”½¹ÑÉ½±±•È½…‘µ¥¸U$…¹($€¨Ñ¡”½É¥¥¹…°É••¥ÁÐÉ•µ…¥¹Ì½¸Ñ¡”¥µµÕÑ…‰±”É•‘•µÁÑ¥½¸É½Ü¸=¹±¥¹”½È($€¨½É‘•Èµ±¥¹­•É•‘•µÁÑ¥½¹Ì…É”‘•±¥‰•É…Ñ•±ä•á±Õ‘•èÉ•™Õ¹Ñ¡”…ÑÕ…°($€¨Á…åµ•¹Ð¥¹ÍÑ•…½˜É•É•…Ñ¥¹œ„Ù½Õ¡•È…™Ñ•È…¸½¹±¥¹”½É‘•È¸($€¨($€¨Á…É…´¥¹Ð€€€€‘Ù½Õ¡•É}¥Y½Õ¡•È¥¸($€¨Á…É…´ÍÑÉ¥¹œ€‘É•…Í½¸€€€€5…¹‘…Ñ½Éä•áÁ±…¹…Ñ¥½¸™½ÈÑ¡”Ñ¥±°½ÉÉ•Ñ¥½¸¸($€¨Á…É…´¥¹Ð€€€€‘…Ñ½É}¥€€]½É‘AÉ•ÍÌµ…¹…•È¥¸($€¨Á…É…´ÍÑÉ¥¹œ€‘…Ñ½É}¹…µ”5…¹…•È‘¥ÍÁ±…ä¹…µ”¸($€¨É•ÑÕÉ¸…ÉÉ…åñ]A}ÉÉ½È($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸É•Ù•ÉÍ•}É•‘•µÁÑ¥½¸ €‘Ù½Õ¡•É}¥°€‘É•…Í½¸°€‘…Ñ½É}¥°€‘…Ñ½É}¹…µ”€¤ì($%±½‰…°€‘ÝÁ‘ˆì(($$‘Ù½Õ¡•É}¥€ô…‰Í¥¹Ð €‘Ù½Õ¡•É}¥€¤ì($$‘É•…Í½¸€€€€€ôÑÉ¥´ ÍÕ‰ÍÑÈ Í…¹¥Ñ¥é•}Ñ•áÑ}™¥•± €¡ÍÑÉ¥¹œ¤€‘É•…Í½¸€¤°€À°€ÔÀÀ€¤€¤ì($$‘…Ñ½É}¥€€€ô…‰Í¥¹Ð €‘…Ñ½É}¥€¤ì($$‘…Ñ½É}¹…µ”€ôÑÉ¥´ ÍÕ‰ÍÑÈ Í…¹¥Ñ¥é•}Ñ•áÑ}™¥•± €¡ÍÑÉ¥¹œ¤€‘…Ñ½É}¹…µ”€¤°€À°€ÄäÄ€¤€¤ì($%¥˜€ €„€‘Ù½Õ¡•É}¥ñðÍÑÉ±•¸ €‘É•…Í½¸€¤€ð€Ô€¤ì($$%É•ÑÕÉ¸¹•Ü]A}ÉÉ½È €‘½Õ¡‰½ÍÍ}Ù½Õ¡•É}É•Ù•ÉÍ•}É•…Í½¸œ°}| €¥Ù”„Í¡½ÉÐÉ•…Í½¸™½ÈÑ¡”É•Ù•ÉÍ…°¸œ°€‘½Õ¡‰½ÍÌœ€¤°…ÉÉ…ä €ÍÑ…ÑÕÌœ€ôø€ÐÀÀ€¤€¤ì($%ô($%¥˜€ €„€‘…Ñ½É}¥ñð€œœ€ôôô€‘…Ñ½É}¹…µ”€¤ì($$%É•ÑÕÉ¸¹•Ü]A}ÉÉ½È €‘½Õ¡‰½ÍÍ}Ù½Õ¡•É}É•Ù•ÉÍ•}…Ñ½Èœ°}| €Í¥¹•µ¥¸µ…¹…•È¥ÌÉ•ÅÕ¥É•Ñ¼É•Ù•ÉÍ”„Ù½Õ¡•È¸œ°€‘½Õ¡‰½ÍÌœ€¤°…ÉÉ…ä €ÍÑ…ÑÕÌœ€ôø€ÐÀÌ€¤€¤ì($%ô($%¥˜€ €„Í•±˜èé…ÅÕ¥É•}Ù½Õ¡•É}±½¬ €‘Ù½Õ¡•É}¥€¤€¤ì($$%É•ÑÕÉ¸Í•±˜èé‰ÕÍå}•ÉÉ½È ¤ì($%ô(($$‘ÑÉ…¹Í…Ñ¥½¸€ô™…±Í”ì($$‘½µµ¥ÑÑ•€€€ô™…±Í”ì($%ÑÉäì($$%¥˜€ ™…±Í”€ôôô€‘ÝÁ‘ˆ´ùÅÕ•Éä €MQIPQI9MQ%=8œ€¤€¤ì€¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($$$%É•ÑÕÉ¸¹•Ü]A}ÉÉ½È €‘½Õ¡‰½ÍÍ}Ù½Õ¡•É}É•Ù•ÉÍ•}ÍÑ½É…”œ°}| €½Õ±¹½ÐÍ…™•±äÍÑ…ÉÐÑ¡”Ù½Õ¡•ÈÉ•Ù•ÉÍ…°¸A±•…Í”ÑÉä……¥¸¸œ°€‘½Õ¡‰½ÍÌœ€¤°…ÉÉ…ä €ÍÑ…ÑÕÌœ€ôø€ÔÀÌ€¤€¤ì($$%ô($$$‘ÑÉ…¹Í…Ñ¥½¸€ôÑÉÕ”ì($$$‘Ñ…‰±”€€€€€€€ôÍ•±˜èéÑ…‰±” ¤ì($$$‘É•‘•µÁÑ¥½¹Ì€ôÍ•±˜èéÉ•‘•µÁÑ¥½¹Í}Ñ…‰±” ¤ì($$$‘É½Ü€€€€€€€€€ô€‘ÝÁ‘ˆ´ù•Ñ}É½Ü €¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä°]½É‘AÉ•ÍÌ¹¹AÉ•Á…É•‘ME0¹%¹Ñ•ÉÁ½±…Ñ•‘9½ÑAÉ•Á…É•($$$$‘ÝÁ‘ˆ´ùÁÉ•Á…É” €‰M1P€¨I=4ì‘Ñ…‰±•ô]!I¥€ô€•=HUAQˆ°€‘Ù½Õ¡•É}¥€¤($$$¤ì($$%¥˜€ €„€‘É½Üñð€É•‘••µ•œ€„ôô€¡ÍÑÉ¥¹œ¤€‘É½Ü´ùÍÑ…ÑÕÌ€¤ì($$$$‘ÝÁ‘ˆ´ùÅÕ•Éä €I=11	,œ€¤ì€¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($$$$‘ÑÉ…¹Í…Ñ¥½¸€ô™…±Í”ì($$$%É•ÑÕÉ¸¹•Ü]A}ÉÉ½È €‘½Õ¡‰½ÍÍ}Ù½Õ¡•É}É•Ù•ÉÍ•}ÍÑ…Ñ”œ°}| €Q¡¥ÌÙ½Õ¡•È¥Ì¹½Ð…Ù…¥±…‰±”Ñ¼É•Ù•ÉÍ”¸œ°€‘½Õ¡‰½ÍÌœ€¤°…ÉÉ…ä €ÍÑ…ÑÕÌœ€ôø€ÐÀä€¤€¤ì($$%ô(($$$‘É•‘•µÁÑ¥½¸€ô€‘ÝÁ‘ˆ´ù•Ñ}É½Ü €¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä°]½É‘AÉ•ÍÌ¹¹AÉ•Á…É•‘ME0¹%¹Ñ•ÉÁ½±…Ñ•‘9½ÑAÉ•Á…É•($$$$‘ÝÁ‘ˆ´ùÁÉ•Á…É” €‰M1P€¨I=4ì‘É•‘•µÁÑ¥½¹Íô]!IÙ½Õ¡•É}¥€ô€•9É•‘•µÁÑ¥½¹}ÍÑ…ÑÕÌ€ô€•Ì=IH	d¥M1%5%P€Ä=HUAQˆ°€‘Ù½Õ¡•É}¥°€É•‘••µ•œ€¤($$$¤ì($$%¥˜€ €„€‘É•‘•µÁÑ¥½¸ñð€¥¹ÍÑ½É”œ€„ôô€¡ÍÑÉ¥¹œ¤€‘É•‘•µÁÑ¥½¸´ù¡…¹¹•°ñð€„•µÁÑä €‘É•‘•µÁÑ¥½¸´ù½É‘•É}¥€¤€¤ì($$$$‘ÝÁ‘ˆ´ùÅÕ•Éä €I=11	,œ€¤ì€¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($$$$‘ÑÉ…¹Í…Ñ¥½¸€ô™…±Í”ì($$$%É•ÑÕÉ¸¹•Ü]A}ÉÉ½È €‘½Õ¡‰½ÍÍ}Ù½Õ¡•É}É•Ù•ÉÍ•}¡…¹¹•°œ°}| €=¹±ä…¸Õ¹±¥¹­•¥¸µÍÑ½É”Í…¸…¸‰”É•Ù•ÉÍ•¸I•™Õ¹½¹±¥¹”½É‘•ÉÌÑ¡É½Õ Ñ¡•¥ÈÁ…åµ•¹ÐÉ•½É¥¹ÍÑ•…¸œ°€‘½Õ¡‰½ÍÌœ€¤°…ÉÉ…ä €ÍÑ…ÑÕÌœ€ôø€ÐÀä€¤€¤ì($$%ô(($$$‘¹½Ü€ôÕÉÉ•¹Ñ}Ñ¥µ” €µåÍÅ°œ€¤ì($$$‘É•Ù•ÉÍ…±}Í…Ù•€ô€‘ÝÁ‘ˆ´ùÕÁ‘…Ñ” €¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($$$$‘É•‘•µÁÑ¥½¹Ì°($$$%…ÉÉ…ä ($$$$$É•‘•µÁÑ¥½¹}ÍÑ…ÑÕÌœ€€ôø€É•Ù•ÉÍ•œ°($$$$$É•Ù•ÉÍ•‘}…Ðœ€€€€€€€€ôø€‘¹½Ü°($$$$$É•Ù•ÉÍ•‘}‰å}ÕÍ•É}¥œ€ôø€‘…Ñ½É}¥°($$$$$É•Ù•ÉÍ•‘}‰å}¹…µ”œ€€€ôø€‘…Ñ½É}¹…µ”°($$$$$É•Ù•ÉÍ…±}É•…Í½¸œ€€€€ôø€‘É•…Í½¸°($$$$¤°($$$%…ÉÉ…ä €¥œ€ôø€¡¥¹Ð¤€‘É•‘•µÁÑ¥½¸´ù¥°€É•‘•µÁÑ¥½¹}ÍÑ…ÑÕÌœ€ôø€É•‘••µ•œ€¤°($$$%…ÉÉ…ä €œ•Ìœ°€œ•Ìœ°€œ•œ°€œ•Ìœ°€œ•Ìœ€¤°($$$%…ÉÉ…ä €œ•œ°€œ•Ìœ€¤($$$¤ì($$%¥˜€ €Ä€„ôô€¡¥¹Ð¤€‘É•Ù•ÉÍ…±}Í…Ù•€¤ì($$$$‘ÝÁ‘ˆ´ùÅÕ•Éä €I=11	,œ€¤ì€¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($$$$‘ÑÉ…¹Í…Ñ¥½¸€ô™…±Í”ì($$$%É•ÑÕÉ¸¹•Ü]A}ÉÉ½È €‘½Õ¡‰½ÍÍ}Ù½Õ¡•É}É•Ù•ÉÍ•}É…”œ°}| €Q¡…ÐÙ½Õ¡•È¡…¹•‰•™½É”¥Ð½Õ±‰”É•Ù•ÉÍ•¸I•™É•Í …¹¡•¬Ñ¡”Ù½Õ¡•È±½œ¸œ°€‘½Õ¡‰½ÍÌœ€¤°…ÉÉ…ä €ÍÑ…ÑÕÌœ€ôø€ÐÀä€¤€¤ì($$%ô(($$$‘É•¥ÍÍÕ•€ô€‘ÝÁ‘ˆ´ùÕÁ‘…Ñ” €¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($$$$‘Ñ…‰±”°($$$%…ÉÉ…ä €ÍÑ…ÑÕÌœ€ôø€¥ÍÍÕ•œ°€ÕÁ‘…Ñ•‘}…Ðœ€ôø€‘¹½Ü€¤°($$$%…ÉÉ…ä €¥œ€ôø€‘Ù½Õ¡•É}¥°€ÍÑ…ÑÕÌœ€ôø€É•‘••µ•œ€¤°($$$%…ÉÉ…ä €œ•Ìœ°€œ•Ìœ€¤°($$$%…ÉÉ…ä €œ•œ°€œ•Ìœ€¤($$$¤ì($$%¥˜€ €Ä€„ôô€¡¥¹Ð¤€‘É•¥ÍÍÕ•ñð€„Í•±˜èéÉ•½É‘}…Õ‘¥Ñ}•Ù•¹Ð ($$$$‘Ù½Õ¡•É}¥°($$$$¡¥¹Ð¤€‘É•‘•µÁÑ¥½¸´ù¥°($$$$É•Ù•ÉÍ…°œ°($$$$‘É•…Í½¸°($$$$‘…Ñ½É}¥°($$$$‘…Ñ½É}¹…µ”°($$$%…ÉÉ…ä ($$$$$¡…¹¹•°œ€€€€€€€€€€€€€€€ôø€¥¹ÍÑ½É”œ°($$$$$ÑÉ…¹Í…Ñ¥½¹}É•™•É•¹”œ€ôø€¡ÍÑÉ¥¹œ¤€‘É•‘•µÁÑ¥½¸´ùÑÉ…¹Í…Ñ¥½¹}É•™•É•¹”°($$$$$…µ½Õ¹Ñ}…ÁÁ±¥•œ€€€€€€€€ôø€¡™±½…Ð¤€‘É•‘•µÁÑ¥½¸´ù…µ½Õ¹Ñ}…ÁÁ±¥•°($$$$¤($$$¤€¤ì($$$$‘ÝÁ‘ˆ´ùÅÕ•Éä €I=11	,œ€¤ì€¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($$$$‘ÑÉ…¹Í…Ñ¥½¸€ô™…±Í”ì($$$%É•ÑÕÉ¸¹•Ü]A}ÉÉ½È €‘½Õ¡‰½ÍÍ}Ù½Õ¡•É}É•Ù•ÉÍ•}…Õ‘¥Ðœ°}| €½Õ±¹½ÐÉ•½ÉÑ¡”É•Ù•ÉÍ…°°Í¼Ñ¡”Ù½Õ¡•ÈÝ…Ì±•™ÐÕ¹¡…¹•¸œ°€‘½Õ¡‰½ÍÌœ€¤°…ÉÉ…ä €ÍÑ…ÑÕÌœ€ôø€ÔÀÀ€¤€¤ì($$%ô(($$$‘½µµ¥ÑÑ•€€€ô™…±Í”€„ôô€‘ÝÁ‘ˆ´ùÅÕ•Éä €=55%Pœ€¤ì€¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($$$‘ÑÉ…¹Í…Ñ¥½¸€ô™…±Í”ì($$%¥˜€ €„€‘½µµ¥ÑÑ•€¤ì($$$%É•ÑÕÉ¸¹•Ü]A}ÉÉ½È €‘½Õ¡‰½ÍÍ}Ù½Õ¡•É}É•Ù•ÉÍ•}ÍÑ½É…”œ°}| €½Õ±¹½Ð½µÁ±•Ñ”Ñ¡”Ù½Õ¡•ÈÉ•Ù•ÉÍ…°¸¡•¬Ñ¡”Ù½Õ¡•È±½œ‰•™½É”ÑÉå¥¹œ……¥¸¸œ°€‘½Õ¡‰½ÍÌœ€¤°…ÉÉ…ä €ÍÑ…ÑÕÌœ€ôø€ÔÀÌ€¤€¤ì($$%ô($%ô™¥¹…±±äì($$%¥˜€ €‘ÑÉ…¹Í…Ñ¥½¸€˜˜€„€‘½µµ¥ÑÑ•€¤ì($$$$‘ÝÁ‘ˆ´ùÅÕ•Éä €I=11	,œ€¤ì€¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($$%ô($$%Í•±˜èéÉ•±•…Í•}Ù½Õ¡•É}±½¬ €‘Ù½Õ¡•É}¥€¤ì($%ô(($%‘½}…Ñ¥½¸ €‘½Õ¡‰½ÍÍ}Ù½Õ¡•É}É•Ù•ÉÍ•œ°€‘Ù½Õ¡•É}¥°€¡¥¹Ð¤€‘É•‘•µÁÑ¥½¸´ù¥°€‘…Ñ½É}¥€¤ì($%É•ÑÕÉ¸…ÉÉ…ä €Ù½Õ¡•É}¥œ€ôø€‘Ù½Õ¡•É}¥°€É•‘•µÁÑ¥½¹}¥œ€ôø€¡¥¹Ð¤€‘É•‘•µÁÑ¥½¸´ù¥€¤ì(%ô(($¼¨¨($€¨Y½¥…¸Õ¹É•‘••µ•Ù½Õ¡•ÈÝ¥Ñ „¹…µ•µµ…¹…•È…Õ‘¥Ð•¹ÑÉä¸($€¨($€¨Á…É…´¥¹Ð€€€€‘¥€€€€€€€€Y½Õ¡•È¥¸($€¨Á…É…´ÍÑÉ¥¹œ€‘É•…Í½¸€€€€5…¹‘…Ñ½ÉäÉ•…Í½¸¸($€¨Á…É…´¥¹Ð€€€€‘…Ñ½É}¥€€]½É‘AÉ•ÍÌµ…¹…•È¥¸($€¨Á…É…´ÍÑÉ¥¹œ€‘…Ñ½É}¹…µ”5…¹…•È‘¥ÍÁ±…ä¹…µ”¸($€¨É•ÑÕÉ¸‰½½°($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸Ù½¥ €‘¥°€‘É•…Í½¸€ô€œœ°€‘…Ñ½É}¥€ô€À°€‘…Ñ½É}¹…µ”€ô€œœ€¤ì($%±½‰…°€‘ÝÁ‘ˆì($$‘¥€ô…‰Í¥¹Ð €‘¥€¤ì($$‘É•…Í½¸€ôÑÉ¥´ ÍÕ‰ÍÑÈ Í…¹¥Ñ¥é•}Ñ•áÑ}™¥•± €¡ÍÑÉ¥¹œ¤€‘É•…Í½¸€¤°€À°€ÔÀÀ€¤€¤ì($$‘…Ñ½É}¥€ô…‰Í¥¹Ð €‘…Ñ½É}¥€¤ì($$‘…Ñ½É}¹…µ”€ôÑÉ¥´ ÍÕ‰ÍÑÈ Í…¹¥Ñ¥é•}Ñ•áÑ}™¥•± €¡ÍÑÉ¥¹œ¤€‘…Ñ½É}¹…µ”€¤°€À°€ÄäÄ€¤€¤ì($%¥˜€ €„€‘¥ñðÍÑÉ±•¸ €‘É•…Í½¸€¤€ð€Ôñð€„€‘…Ñ½É}¥ñð€œœ€ôôô€‘…Ñ½É}¹…µ”€¤ì($$%É•ÑÕÉ¸™…±Í”ì($%ô($%¥˜€ €„Í•±˜èé…ÅÕ¥É•}Ù½Õ¡•É}±½¬ €‘¥€¤€¤ì($$%É•ÑÕÉ¸™…±Í”ì($%ô($$‘ÑÉ…¹Í…Ñ¥½¸€ô™…±Í”ì($$‘½µµ¥ÑÑ•€€€ô™…±Í”ì($%ÑÉäì($$%¥˜€ ™…±Í”€ôôô€‘ÝÁ‘ˆ´ùÅÕ•Éä €MQIPQI9MQ%=8œ€¤€¤ì€¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($$$%É•ÑÕÉ¸™…±Í”ì($$%ô($$$‘ÑÉ…¹Í…Ñ¥½¸€ôÑÉÕ”ì($$$‘É½Ü€ôÍ•±˜èé™¥¹‘}‰å}¥ €‘¥€¤ì($$%¥˜€ €„€‘É½Üñð€¥ÍÍÕ•œ€„ôô€¡ÍÑÉ¥¹œ¤€‘É½Ü´ùÍÑ…ÑÕÌ€¤ì($$$$‘ÝÁ‘ˆ´ùÅÕ•Éä €I=11	,œ€¤ì€¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($$$$‘ÑÉ…¹Í…Ñ¥½¸€ô™…±Í”ì($$$%É•ÑÕÉ¸™…±Í”ì($$%ô($$$‘É•Í•ÉÙ…Ñ¥½¸€ôÍ•±˜èéÉ½Ý}É•Í•ÉÙ…Ñ¥½¸ €‘É½Ü€¤ì($$%¥˜€ €‘É•Í•ÉÙ…Ñ¥½¸€˜˜€¡¥¹Ð¤€‘É•Í•ÉÙ…Ñ¥½¹l•áÁ¥É•Í}…Ðt€øÑ¥µ” ¤€¤ì($$$$‘ÝÁ‘ˆ´ùÅÕ•Éä €I=11	,œ€¤ì€¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($$$$‘ÑÉ…¹Í…Ñ¥½¸€ô™…±Í”ì($$$%É•ÑÕÉ¸™…±Í”ì($$%ô($$$‘µ•Ñ„€ôÍ•±˜èéÉ½Ý}µ•Ñ„ €‘É½Ü€¤ì($$%Õ¹Í•Ð €‘µ•Ñ…lÍ•±˜èéIMIYQ%=9}5Q}-dt€¤ì($$$‘Ñ…‰±”€ôÍ•±˜èéÑ…‰±” ¤ì($$$‘Ù½¥‘•€ô€Ä€ôôô€¡¥¹Ð¤€‘ÝÁ‘ˆ´ùÅÕ•Éä €¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä°]½É‘AÉ•ÍÌ¹¹AÉ•Á…É•‘ME0¹%¹Ñ•ÉÁ½±…Ñ•‘9½ÑAÉ•Á…É•($$$$‘ÝÁ‘ˆ´ùÁÉ•Á…É” €‰UAQì‘Ñ…‰±•ôMPÍÑ…ÑÕÌ€ô€•Ì°µ•Ñ„€ô€•Ì°ÕÁ‘…Ñ•‘}…Ð€ô€•Ì]!I¥€ô€•9ÍÑ…ÑÕÌ€ô€•Ìˆ°€Ù½¥‘•œ°Í•±˜èé•¹½‘•}µ•Ñ„ €‘µ•Ñ„€¤°ÕÉÉ•¹Ñ}Ñ¥µ” €µåÍÅ°œ€¤°€‘¥°€¥ÍÍÕ•œ€¤($$$¤ì($$%¥˜€ €„€‘Ù½¥‘•ñð€„Í•±˜èéÉ•½É‘}…Õ‘¥Ñ}•Ù•¹Ð €‘¥°€À°€Ù½¥œ°€‘É•…Í½¸°€‘…Ñ½É}¥°€‘…Ñ½É}¹…µ”€¤€¤ì($$$$‘ÝÁ‘ˆ´ùÅÕ•Éä €I=11	,œ€¤ì€¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($$$$‘ÑÉ…¹Í…Ñ¥½¸€ô™…±Í”ì($$$%É•ÑÕÉ¸™…±Í”ì($$%ô($$$‘½µµ¥ÑÑ•€ô™…±Í”€„ôô€‘ÝÁ‘ˆ´ùÅÕ•Éä €=55%Pœ€¤ì€¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($$$‘ÑÉ…¹Í…Ñ¥½¸€ô™…±Í”ì($$%É•ÑÕÉ¸€‘½µµ¥ÑÑ•ì($%ô™¥¹…±±äì($$%¥˜€ €‘ÑÉ…¹Í…Ñ¥½¸€˜˜€„€‘½µµ¥ÑÑ•€¤ì($$$$‘ÝÁ‘ˆ´ùÅÕ•Éä €I=11	,œ€¤ì€¼¼Á¡ÁÌé¥¹½É”]½É‘AÉ•ÍÌ¹¹¥É•Ñ…Ñ…‰…Í•EÕ•Éä($$%ô($$%Í•±˜èéÉ•±•…Í•}Ù½Õ¡•É}±½¬ €‘¥€¤ì($%ô(%ô)ô