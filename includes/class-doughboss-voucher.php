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
	 * @param string $prefix Short uppercase prefix (campaign), e.g. 'SNOW'.
	 * @param int    $length Number of random characters (default 8).
	 * @return string
	 */
	public static function generate_code( $prefix = 'DB', $length = 8 ) {
		$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $prefix ) );
		$length = max( 6, (int) $length );
		$alpha  = self::ALPHABET;
		$n      = strlen( $alpha );

		try {
			$bytes = random_bytes( $length );
		} catch ( Exception $e ) {
			$bytes = wp_generate_password( $length, false, false );
		}

		$code = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$code .= $alpha[ ord( $bytes[ $i ] ) % $n ];
		}
		return ( '' !== $prefix ? $prefix . '-' : '' ) . $code;
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
	 * @param string $code Voucher code.
	 * @return object|null
	 */
	public static function find_by_code( $code ) {
		global $wpdb;
		$code  = strtoupper( trim( (string) $code ) );
		if ( '' === $code ) {
			return null;
		}
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * Atomically redeem a single-use voucher.
	 *
	 * Wins the race via a conditional UPDATE (status issued -> redeemed); only
	 * the winner writes the immutable redemption row. The discount is recomputed
	 * here from the row + subtotal, never taken from the caller.
	 *
	 * @param string $code     Voucher code.
	 * @param float  $subtotal Server-computed cart subtotal.
	 * @param string $channel  'online' or 'instore'.
	 * @param array  $extra    { idempotency_key, location_id, pospal_ticket_no }.
	 * @return array|WP_Error array{ code, amount } or error.
	 */
	public static function redeem( $code, $subtotal, $channel = 'online', array $extra = array() ) {
		global $wpdb;

		$row = self::find_by_code( $code );
		// Same opaque error whether not-found or ineligible — no enumeration.
		$generic = new WP_Error( 'doughboss_voucher_invalid', __( 'This voucher code isn’t valid.', 'doughboss' ), array( 'status' => 422 ) );

		$eval = self::evaluate( $row, $subtotal, $channel );
		if ( ! $row || ! $eval['valid'] ) {
			if ( $row && 'min_spend' === $eval['reason'] ) {
				return new WP_Error( 'doughboss_voucher_min', __( 'Your order doesn’t meet this voucher’s minimum spend.', 'doughboss' ), array( 'status' => 422 ) );
			}
			return $generic;
		}

		$table = self::table();
		$now   = current_time( 'mysql' );

		// Atomic claim: only one redeem can flip issued -> redeemed.
		$claimed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "UPDATE {$table} SET status = %s, updated_at = %s WHERE id = %d AND status = %s", 'redeemed', $now, $row->id, 'issued' )
		);
		if ( 1 !== (int) $claimed ) {
			return new WP_Error( 'doughboss_voucher_used', __( 'This voucher has already been used.', 'doughboss' ), array( 'status' => 409 ) );
		}

		$idem = isset( $extra['idempotency_key'] ) && '' !== $extra['idempotency_key']
			? substr( sanitize_text_field( $extra['idempotency_key'] ), 0, 64 )
			: md5( $row->id . '|' . $channel . '|' . $now . '|' . wp_rand() );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::redemptions_table(),
			array(
				'voucher_id'       => (int) $row->id,
				'channel'          => 'instore' === $channel ? 'instore' : 'online',
				'pospal_ticket_no' => isset( $extra['pospal_ticket_no'] ) ? substr( sanitize_text_field( $extra['pospal_ticket_no'] ), 0, 64 ) : '',
				'location_id'      => isset( $extra['location_id'] ) ? absint( $extra['location_id'] ) : (int) $row->location_id,
				'amount_applied'   => $eval['amount'],
				'idempotency_key'  => $idem,
				'redeemed_at'      => $now,
			),
			array( '%d', '%s', '%s', '%d', '%f', '%s', '%s' )
		);

		/**
		 * Fires after a voucher is redeemed (e.g. to revoke the mirrored POSPal
		 * coupon so it can't also be used in-store). Wired in a later milestone.
		 *
		 * @param object $row     Voucher row.
		 * @param float  $amount  Amount applied.
		 * @param string $channel Redemption channel.
		 */
		do_action( 'doughboss_voucher_redeemed', $row, $eval['amount'], $channel );

		return array(
			'code'   => $row->code,
			'amount' => $eval['amount'],
		);
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
	 * Default campaigns — the Snow Boss student launch: a $5 and a $10 voucher,
	 * each capped at 100 claims per day and released fresh every day.
	 *
	 * @return array[]
	 */
	public static function default_campaigns() {
		return array(
			'snow5'  => array(
				'slug'      => 'snow5',
				'label'     => '$5 off a manoush combo',
				'type'      => 'amount',
				'value'     => 5.00,
				'prefix'    => 'SNOW',
				'daily_cap' => 100,
				'scope'     => 'both',
				'active'    => 1,
			),
			'snow10' => array(
				'slug'      => 'snow10',
				'label'     => '$10 off your first Snow Boss dessert',
				'type'      => 'amount',
				'value'     => 10.00,
				'prefix'    => 'SNOW',
				'daily_cap' => 100,
				'scope'     => 'both',
				'active'    => 1,
			),
		);
	}

	/**
	 * How many vouchers a campaign has issued so far today (site-local time).
	 *
	 * @param string $slug Campaign slug.
	 * @return int
	 */
	public static function claimed_today( $slug ) {
		global $wpdb;
		$table = self::table();
		$start = current_time( 'Y-m-d' ) . ' 00:00:00';
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign = %s AND created_at >= %s", sanitize_key( $slug ), $start )
		);
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
		$slug      = sanitize_key( $slug );
		$campaigns = self::campaigns();
		if ( empty( $campaigns[ $slug ] ) || empty( $campaigns[ $slug ]['active'] ) ) {
			return new WP_Error( 'doughboss_campaign', __( 'This offer isn’t available.', 'doughboss' ), array( 'status' => 404 ) );
		}

		$campaign = $campaigns[ $slug ];
		$cap      = (int) ( isset( $campaign['daily_cap'] ) ? $campaign['daily_cap'] : 0 );
		if ( $cap > 0 && self::claimed_today( $slug ) >= $cap ) {
			return new WP_Error( 'doughboss_campaign_full', __( 'Today’s vouchers have all been claimed — check back tomorrow.', 'doughboss' ), array( 'status' => 409 ) );
		}

		return self::issue(
			array_merge(
				array(
					'type'       => isset( $campaign['type'] ) ? $campaign['type'] : 'amount',
					'value'      => isset( $campaign['value'] ) ? $campaign['value'] : 0,
					'prefix'     => isset( $campaign['prefix'] ) ? $campaign['prefix'] : 'DB',
					'scope'      => isset( $campaign['scope'] ) ? $campaign['scope'] : 'both',
					'single_use' => 1,
					'campaign'   => $slug,
					'meta'       => array(
						'campaign' => $slug,
						'label'    => isset( $campaign['label'] ) ? $campaign['label'] : '',
					),
				),
				$args
			)
		);
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
		$table = self::table();
		return (bool) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "UPDATE {$table} SET status = %s, updated_at = %s WHERE id = %d AND status = %s", 'voided', current_time( 'mysql' ), $id, 'issued' )
		);
	}
}
