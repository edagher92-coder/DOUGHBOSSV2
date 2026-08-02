<?php
/**
 * Catering enquiry & quote model.
 *
 * Owns the {prefix}doughboss_catering_enquiries table and the server-side
 * quote engine. Catering is lead-shaped (enquiry → quote → deposit → balance →
 * fulfilled), which does not fit the flat checkout `orders` table, so it lives
 * in its own table with its own status lifecycle. Pricing is always recomputed
 * here from the package + settings — browser-reported totals are never trusted.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data model for catering enquiries and their quotes.
 */
class DoughBoss_Catering {

	const STATUS_NEW         = 'new';
	const STATUS_QUOTED      = 'quoted';
	const STATUS_DEPOSIT     = 'deposit_paid';
	const STATUS_CONFIRMED   = 'confirmed';
	const STATUS_BALANCE_DUE = 'balance_due';
	const STATUS_PAID        = 'paid';
	const STATUS_FULFILLED   = 'fulfilled';
	const STATUS_LOST        = 'lost';

	const LEG_DEPOSIT = 'deposit';
	const LEG_BALANCE = 'balance';
	const MAX_QUOTE_AMOUNT = 999999.99;

	/**
	 * Sanity cap on headcount (mirrors DoughBoss_Cart::MAX_QTY's role for the
	 * cart) — bigger events are quoted by hand, not through the enquiry form.
	 */
	const MAX_GUESTS = 1000;

	/**
	 * The enquiries table name for the current site.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_catering_enquiries';
	}

	/**
	 * Lifecycle statuses mapped to human labels.
	 *
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
			self::STATUS_NEW         => __( 'New enquiry', 'doughboss' ),
			self::STATUS_QUOTED      => __( 'Quoted', 'doughboss' ),
			self::STATUS_DEPOSIT     => __( 'Deposit paid', 'doughboss' ),
			self::STATUS_CONFIRMED   => __( 'Confirmed', 'doughboss' ),
			self::STATUS_BALANCE_DUE => __( 'Balance due', 'doughboss' ),
			self::STATUS_PAID        => __( 'Paid in full', 'doughboss' ),
			self::STATUS_FULFILLED   => __( 'Fulfilled', 'doughboss' ),
			self::STATUS_LOST        => __( 'Lost', 'doughboss' ),
		);
	}

	/**
	 * Whether a status string is one we recognise.
	 *
	 * @param string $status Candidate status.
	 * @return bool
	 */
	public static function is_valid_status( $status ) {
		return array_key_exists( $status, self::statuses() );
	}

	/**
	 * Build a quote for a package + headcount, entirely server-side.
	 *
	 * The package price is the base; if a per-head price is set and the
	 * headcount exceeds the package's serve range, the overflow is charged
	 * per head. Delivery is added as a flat fee (the operator confirms distance
	 * pricing). Custom enquiries with no valid package return zeros — staff
	 * quote those by hand.
	 *
	 * @param int    $package_id   Catering package post ID (0 for custom).
	 * @param int    $guest_count  Number of guests.
	 * @param string $order_type   'pickup' or 'delivery'.
	 * @param float  $delivery_fee Flat delivery fee to add (delivery only).
	 * @return array<string,mixed>
	 */
	public static function quote( $package_id, $guest_count, $order_type, $delivery_fee = 0.0 ) {
		$package_id  = absint( $package_id );
		$guest_count = absint( $guest_count );
		$delivery    = 'delivery' === $order_type;
		$fee         = $delivery ? max( 0.0, round( (float) $delivery_fee, 2 ) ) : 0.0;
		$deposit_pct = DoughBoss_Catering_Package::DEFAULT_DEPOSIT_PCT;
		$lead_days   = DoughBoss_Catering_Package::DEFAULT_LEAD_DAYS;
		$subtotal    = 0.0;

		$package = $package_id ? get_post( $package_id ) : null;
		if ( $package && DoughBoss_Catering_Package::POST_TYPE === $package->post_type && 'publish' === $package->post_status ) {
			$base       = (float) get_post_meta( $package_id, DoughBoss_Catering_Package::META_BASE_PRICE, true );
			$per_head   = (float) get_post_meta( $package_id, DoughBoss_Catering_Package::META_PER_HEAD, true );
			$serves_max = (int) get_post_meta( $package_id, DoughBoss_Catering_Package::META_SERVES_MAX, true );

			$subtotal = $base;
			if ( $per_head > 0 && $serves_max > 0 && $guest_count > $serves_max ) {
				$subtotal += $per_head * ( $guest_count - $serves_max );
			}

			$deposit_pct = DoughBoss_Catering_Package::deposit_pct( $package_id );
			$lead_days   = DoughBoss_Catering_Package::lead_days( $package_id );
		}

		$subtotal = round( $subtotal, 2 );
		$total    = round( $subtotal + $fee, 2 );
		$deposit  = round( $total * $deposit_pct / 100, 2 );
		$balance  = round( $total - $deposit, 2 );

		return array(
			'subtotal'     => $subtotal,
			'delivery_fee' => $fee,
			'total'        => $total,
			'deposit_pct'  => $deposit_pct,
			'deposit'      => $deposit,
			'balance'      => $balance,
			'lead_days'    => $lead_days,
			'currency'     => (string) DoughBoss_Settings::get( 'currency_code', 'AUD' ),
		);
	}

	/**
	 * Create a catering enquiry from (already-trusted-as-strings) input.
	 *
	 * All pricing is recomputed via quote(); any totals in $data are ignored.
	 *
	 * @param array<string,mixed> $data Raw enquiry fields.
	 * @return int|WP_Error New enquiry ID, or error on failure.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$name = isset( $data['customer_name'] ) ? sanitize_text_field( $data['customer_name'] ) : '';
		$mail = isset( $data['customer_email'] ) ? sanitize_email( $data['customer_email'] ) : '';

		if ( '' === $name || ! is_email( $mail ) ) {
			return new WP_Error( 'doughboss_catering_invalid', __( 'A name and a valid email are required.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$package_id  = isset( $data['package_id'] ) ? absint( $data['package_id'] ) : 0;
		$guest_count = isset( $data['guest_count'] ) ? absint( $data['guest_count'] ) : 0;
		$order_type  = ( isset( $data['order_type'] ) && 'delivery' === $data['order_type'] ) ? 'delivery' : 'pickup';
		$delivery    = isset( $data['delivery_fee'] ) ? (float) $data['delivery_fee'] : 0.0;

		if ( $guest_count > self::MAX_GUESTS ) {
			return new WP_Error( 'doughboss_catering_guests', __( 'That guest count is too large for an online enquiry — please call us to arrange it.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$event_date = self::sanitize_date( isset( $data['event_date'] ) ? $data['event_date'] : '' );
		if ( '' !== $event_date && $event_date < current_time( 'Y-m-d' ) ) {
			return new WP_Error( 'doughboss_catering_date', __( 'The event date can’t be in the past.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$quote = self::quote( $package_id, $guest_count, $order_type, $delivery );

		$number = self::generate_enquiry_number();
		$now     = current_time( 'mysql' );

		$row = array(
			'enquiry_number' => $number,
			'location_id'    => isset( $data['location_id'] ) ? absint( $data['location_id'] ) : 0,
			'package_id'     => $package_id,
			'status'         => self::STATUS_NEW,
			'customer_name'  => $name,
			'customer_email' => $mail,
			'customer_phone' => isset( $data['customer_phone'] ) ? sanitize_text_field( $data['customer_phone'] ) : '',
			'event_date'     => $event_date,
			'event_time'     => isset( $data['event_time'] ) ? sanitize_text_field( $data['event_time'] ) : '',
			'guest_count'    => $guest_count,
			'order_type'     => $order_type,
			'address'        => isset( $data['address'] ) ? sanitize_textarea_field( $data['address'] ) : '',
			'dietary'        => isset( $data['dietary'] ) ? sanitize_textarea_field( $data['dietary'] ) : '',
			'notes'          => isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : '',
			'subtotal'       => $quote['subtotal'],
			'delivery_fee'   => $quote['delivery_fee'],
			'quote_total'    => $quote['total'],
			'deposit_amount' => $quote['deposit'],
			'balance_amount' => $quote['balance'],
			'currency'       => $quote['currency'],
			'created_at'     => $now,
			'updated_at'     => $now,
		);

		$formats = array(
			'%s', // enquiry_number.
			'%d', // location_id.
			'%d', // package_id.
			'%s', // status.
			'%s', // customer_name.
			'%s', // customer_email.
			'%s', // customer_phone.
			'%s', // event_date.
			'%s', // event_time.
			'%d', // guest_count.
			'%s', // order_type.
			'%s', // address.
			'%s', // dietary.
			'%s', // notes.
			'%s', // subtotal.
			'%s', // delivery_fee.
			'%s', // quote_total.
			'%s', // deposit_amount.
			'%s', // balance_amount.
			'%s', // currency.
			'%s', // created_at.
			'%s', // updated_at.
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert( self::table(), $row, $formats );
		if ( ! $ok ) {
			return new WP_Error( 'doughboss_catering_db', __( 'Could not save the enquiry. Please try again.', 'doughboss' ), array( 'status' => 500 ) );
		}

		$id = (int) $wpdb->insert_id;

		/**
		 * Fires after a catering enquiry is created.
		 *
		 * @param int   $id   Enquiry ID.
		 * @param array $row  Stored row.
		 */
		do_action( 'doughboss_catering_enquiry_created', $id, $row );

		return $id;
	}

	/**
	 * Fetch a single enquiry by ID.
	 *
	 * @param int $id Enquiry ID.
	 * @return array<string,mixed>|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$id = absint( $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Fetch a single enquiry by its public number.
	 *
	 * @param string $number Enquiry number.
	 * @return array<string,mixed>|null
	 */
	public static function get_by_number( $number ) {
		global $wpdb;
		$number = sanitize_text_field( $number );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE enquiry_number = %s', $number ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Move an enquiry to a new lifecycle status.
	 *
	 * @param int    $id     Enquiry ID.
	 * @param string $status New status (must be a known status).
	 * @return bool
	 */
	public static function update_status( $id, $status ) {
		if ( ! self::is_valid_status( $status ) ) {
			return false;
		}

		$id = absint( $id );

		global $wpdb;
		$data   = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql' ),
		);
		$format = array( '%s', '%s' );

		if ( self::STATUS_QUOTED === $status ) {
			$enquiry = self::get( $id );
			if ( $enquiry && empty( $enquiry['quoted_at'] ) ) {
				$data['quoted_at'] = current_time( 'mysql' );
				$format[]          = '%s';
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->update(
			self::table(),
			$data,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		if ( false !== $ok ) {
			/**
			 * Fires when a catering enquiry changes status.
			 *
			 * @param int    $id     Enquiry ID.
			 * @param string $status New status.
			 */
			do_action( 'doughboss_catering_status_changed', absint( $id ), $status );
		}

		return false !== $ok;
	}

	/**
	 * The payable amount (major units) for a payment leg of an enquiry.
	 *
	 * @param array<string,mixed> $enquiry Enquiry row.
	 * @param string              $leg     'deposit' or 'balance'.
	 * @return float
	 */
	public static function leg_amount( $enquiry, $leg ) {
		if ( ! is_array( $enquiry ) ) {
			return 0.0;
		}
		return self::LEG_BALANCE === $leg ? (float) $enquiry['balance_amount'] : (float) $enquiry['deposit_amount'];
	}

	/**
	 * Whether a payment leg has already been paid.
	 *
	 * @param array<string,mixed> $enquiry Enquiry row.
	 * @param string              $leg     'deposit' or 'balance'.
	 * @return bool
	 */
	public static function is_paid( $enquiry, $leg ) {
		if ( ! is_array( $enquiry ) ) {
			return false;
		}
		$col = self::LEG_BALANCE === $leg ? 'balance_paid_at' : 'deposit_paid_at';
		return ! empty( $enquiry[ $col ] ) && '0000-00-00 00:00:00' !== $enquiry[ $col ];
	}

	/**
	 * Store the PaymentIntent id for a payment leg.
	 *
	 * @param int    $id        Enquiry ID.
	 * @param string $leg       'deposit' or 'balance'.
	 * @param string $intent_id Stripe PaymentIntent id.
	 * @return bool
	 */
	public static function set_intent( $id, $leg, $intent_id ) {
		global $wpdb;
		$column    = self::LEG_BALANCE === $leg ? 'balance_intent_id' : 'deposit_intent_id';
		$intent_id = sanitize_text_field( $intent_id );
		if ( '' === $intent_id ) {
			return false;
		}

		// Never replace a payment reference after preparation has started. A
		// retry carrying the same canonical reference remains idempotent.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ok = $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::table() . " SET {$column} = %s, updated_at = %s WHERE id = %d AND ({$column} = '' OR {$column} = %s)",
				$intent_id,
				current_time( 'mysql' ),
				absint( $id ),
				$intent_id
			)
		);
		if ( false === $ok ) {
			return false;
		}
		$fresh = self::get( $id );
		return $fresh && hash_equals( (string) $fresh[ $column ], $intent_id );
	}

	/**
	 * Acquire the per-enquiry serialization lock shared by quoting and payment
	 * preparation. This closes the otherwise unavoidable race between staff
	 * repricing a custom job and a customer starting its deposit payment.
	 *
	 * @param int $id Enquiry ID.
	 * @return bool
	 */
	public static function acquire_quote_lock( $id ) {
		global $wpdb;
		$lock = self::quote_lock_name( $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 5 ) );
	}

	/**
	 * Release the per-enquiry quote/payment lock.
	 *
	 * @param int $id Enquiry ID.
	 * @return void
	 */
	public static function release_quote_lock( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::quote_lock_name( $id ) ) );
	}

	/**
	 * Whether any durable or provider-side preparation has locked this quote.
	 *
	 * @param array<string,mixed> $enquiry Enquiry row.
	 * @return bool
	 */
	public static function has_payment_preparation( array $enquiry ) {
		if (
			! empty( $enquiry['deposit_intent_id'] )
			|| ! empty( $enquiry['balance_intent_id'] )
			|| self::is_paid( $enquiry, self::LEG_DEPOSIT )
			|| self::is_paid( $enquiry, self::LEG_BALANCE )
		) {
			return true;
		}

		global $wpdb;
		$attempts = $wpdb->prefix . 'doughboss_payment_attempts';
		$deposit  = (string) $enquiry['enquiry_number'] . ':' . self::LEG_DEPOSIT;
		$balance  = (string) $enquiry['enquiry_number'] . ':' . self::LEG_BALANCE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$attempts} WHERE context = %s AND local_reference IN (%s, %s) LIMIT 1",
				'catering',
				$deposit,
				$balance
			)
		);
		return ! empty( $found );
	}

	/**
	 * Save a staff-authored custom quote using server-side money calculations.
	 * Only the component amounts and deposit percentage are accepted; total,
	 * deposit, balance and AUD currency are derived and stored by the server.
	 *
	 * @param int   $id           Enquiry ID.
	 * @param mixed $subtotal_raw Staff-entered subtotal in AUD.
	 * @param mixed $delivery_raw Staff-entered delivery fee in AUD.
	 * @param mixed $deposit_raw  Staff-entered deposit percentage.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function set_custom_quote( $id, $subtotal_raw, $delivery_raw, $deposit_raw ) {
		$id = absint( $id );
		if ( ! $id || ! is_numeric( $subtotal_raw ) || ! is_numeric( $delivery_raw ) || ! is_numeric( $deposit_raw ) ) {
			return new WP_Error( 'doughboss_catering_quote_invalid', __( 'Enter valid quote amounts and a deposit percentage.', 'doughboss' ), array( 'status' => 400 ) );
		}

		$subtotal = round( (float) $subtotal_raw, 2 );
		$delivery = round( (float) $delivery_raw, 2 );
		$pct_raw  = (float) $deposit_raw;
		$pct      = (int) $pct_raw;
		if (
			! is_finite( $subtotal ) || ! is_finite( $delivery ) || ! is_finite( $pct_raw )
			|| $subtotal < 0 || $delivery < 0
			|| $subtotal > self::MAX_QUOTE_AMOUNT || $delivery > self::MAX_QUOTE_AMOUNT
			|| abs( $pct_raw - $pct ) > 0.0001 || $pct < 1 || $pct > 100
		) {
			return new WP_Error( 'doughboss_catering_quote_invalid', __( 'The quote must be a positive AUD amount with a deposit between 1% and 100%.', 'doughboss' ), array( 'status' => 400 ) );
		}
		if ( 'AUD' !== strtoupper( (string) DoughBoss_Settings::get( 'currency_code', 'AUD' ) ) ) {
			return new WP_Error( 'doughboss_catering_quote_currency', __( 'Custom catering quotes are available in AUD only.', 'doughboss' ), array( 'status' => 409 ) );
		}
		if ( ! self::acquire_quote_lock( $id ) ) {
			return new WP_Error( 'doughboss_catering_quote_busy', __( 'This enquiry is busy. Please wait a moment and try again.', 'doughboss' ), array( 'status' => 503 ) );
		}

		try {
			$before = self::get( $id );
			if ( ! $before || 0 !== (int) $before['package_id'] ) {
				return new WP_Error( 'doughboss_catering_quote_custom', __( 'Only custom catering enquiries can be manually quoted.', 'doughboss' ), array( 'status' => 409 ) );
			}
			if ( ! in_array( (string) $before['status'], array( self::STATUS_NEW, self::STATUS_QUOTED ), true ) || self::has_payment_preparation( $before ) ) {
				return new WP_Error( 'doughboss_catering_quote_locked', __( 'This quote is locked because payment preparation or fulfilment has begun.', 'doughboss' ), array( 'status' => 409 ) );
			}

			// Pickup jobs never receive a delivery fee, regardless of what a stale
			// admin form submits. Fulfilment type is stored server-side.
			$delivery = 'delivery' === (string) $before['order_type'] ? $delivery : 0.0;
			$total    = round( $subtotal + $delivery, 2 );
			if ( $total <= 0 || $total > self::MAX_QUOTE_AMOUNT ) {
				return new WP_Error( 'doughboss_catering_quote_invalid', __( 'The quote must be a positive AUD amount.', 'doughboss' ), array( 'status' => 400 ) );
			}
			$deposit = round( $total * $pct / 100, 2 );
			$balance = round( $total - $deposit, 2 );
			if ( $deposit < 0.01 ) {
				return new WP_Error( 'doughboss_catering_quote_invalid', __( 'The deposit must be at least A$0.01.', 'doughboss' ), array( 'status' => 400 ) );
			}
			if (
				self::STATUS_QUOTED === (string) $before['status']
				&& abs( (float) $before['subtotal'] - $subtotal ) < 0.001
				&& abs( (float) $before['delivery_fee'] - $delivery ) < 0.001
				&& abs( (float) $before['quote_total'] - $total ) < 0.001
				&& abs( (float) $before['deposit_amount'] - $deposit ) < 0.001
				&& abs( (float) $before['balance_amount'] - $balance ) < 0.001
			) {
				return $before;
			}
			$now     = current_time( 'mysql' );
			global $wpdb;
			// The conditional write is the final authority even inside the named
			// lock. It prevents stale or non-cooperating callers from repricing a
			// payment-bound enquiry.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE " . self::table() . " SET subtotal = %f, delivery_fee = %f, quote_total = %f, deposit_amount = %f, balance_amount = %f, currency = %s, status = %s, quoted_at = %s, updated_at = %s WHERE id = %d AND package_id = 0 AND status IN (%s, %s) AND deposit_intent_id = '' AND balance_intent_id = '' AND (deposit_paid_at IS NULL OR deposit_paid_at = '0000-00-00 00:00:00') AND (balance_paid_at IS NULL OR balance_paid_at = '0000-00-00 00:00:00')",
					$subtotal,
					$delivery,
					$total,
					$deposit,
					$balance,
					'AUD',
					self::STATUS_QUOTED,
					$now,
					$now,
					$id,
					self::STATUS_NEW,
					self::STATUS_QUOTED
				)
			);
			if ( 1 !== (int) $updated ) {
				return new WP_Error( 'doughboss_catering_quote_locked', __( 'The quote changed or became payment-bound. Reload before trying again.', 'doughboss' ), array( 'status' => 409 ) );
			}

			$after = self::get( $id );
			/**
			 * Fires after a manager changes a custom catering quote.
			 *
			 * Audit consumers receive both immutable snapshots and the actor ID.
			 *
			 * @param int   $id       Enquiry ID.
			 * @param array $before   Previous stored enquiry.
			 * @param array $after    Updated stored enquiry.
			 * @param int   $actor_id WordPress user ID that changed the quote.
			 */
			do_action( 'doughboss_catering_quote_updated', $id, $before, $after, get_current_user_id() );
			if ( self::STATUS_QUOTED !== (string) $before['status'] ) {
				do_action( 'doughboss_catering_status_changed', $id, self::STATUS_QUOTED );
			}
			return $after;
		} finally {
			self::release_quote_lock( $id );
		}
	}

	/**
	 * Stable MySQL lock key for one catering enquiry.
	 *
	 * @param int $id Enquiry ID.
	 * @return string
	 */
	private static function quote_lock_name( $id ) {
		return substr( 'doughboss_catering_quote_' . absint( $id ), 0, 64 );
	}

	/**
	 * Mark a payment leg paid and advance the lifecycle status. Idempotent:
	 * re-marking an already-paid leg is a no-op success (safe for webhooks).
	 *
	 * @param int    $id  Enquiry ID.
	 * @param string $leg 'deposit' or 'balance'.
	 * @return bool
	 */
	public static function mark_paid( $id, $leg ) {
		$id      = absint( $id );
		$enquiry = self::get( $id );
		if ( ! $enquiry ) {
			return false;
		}
		if ( self::is_paid( $enquiry, $leg ) ) {
			return true;
		}

		global $wpdb;
		$now         = current_time( 'mysql' );
		$is_bal      = self::LEG_BALANCE === $leg;
		$settles_all = ! $is_bal && (float) $enquiry['balance_amount'] <= 0.0;
		$paid_column = $is_bal ? 'balance_paid_at' : 'deposit_paid_at';
		$status      = ( $is_bal || $settles_all ) ? self::STATUS_PAID : self::STATUS_DEPOSIT;

		// Claim this leg with one conditional write. The browser confirmation and
		// the provider webhook can arrive at the same instant; only the request
		// that changes NULL -> paid owns the lifecycle side effects below. A late
		// deposit event must also never regress an enquiry that staff have already
		// confirmed, fulfilled, or advanced to the balance/paid stages.
		if ( $is_bal ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE " . self::table() . " SET status = %s, {$paid_column} = %s, updated_at = %s WHERE id = %d AND ({$paid_column} IS NULL OR {$paid_column} = '0000-00-00 00:00:00')",
					$status,
					$now,
					$now,
					$id
				)
			);
		} elseif ( $settles_all ) {
			// A 100% deposit is the complete payment. Record the zero balance as
			// settled in the same atomic write so the enquiry cannot get stuck in
			// deposit-paid with no payable balance leg.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE " . self::table() . " SET status = CASE WHEN status IN (%s, %s, %s) THEN status ELSE %s END, deposit_paid_at = %s, balance_paid_at = CASE WHEN balance_paid_at IS NULL OR balance_paid_at = '0000-00-00 00:00:00' THEN %s ELSE balance_paid_at END, updated_at = %s WHERE id = %d AND (deposit_paid_at IS NULL OR deposit_paid_at = '0000-00-00 00:00:00')",
					self::STATUS_CONFIRMED,
					self::STATUS_PAID,
					self::STATUS_FULFILLED,
					$status,
					$now,
					$now,
					$now,
					$id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE " . self::table() . " SET status = CASE WHEN status IN (%s, %s, %s, %s) THEN status ELSE %s END, {$paid_column} = %s, updated_at = %s WHERE id = %d AND ({$paid_column} IS NULL OR {$paid_column} = '0000-00-00 00:00:00')",
					self::STATUS_CONFIRMED,
					self::STATUS_BALANCE_DUE,
					self::STATUS_PAID,
					self::STATUS_FULFILLED,
					$status,
					$now,
					$now,
					$id
				)
			);
		}

		if ( false === $updated ) {
			return false;
		}
		if ( 0 === (int) $updated ) {
			$fresh = self::get( $id );
			return self::is_paid( $fresh, $leg );
		}

		$fresh  = self::get( $id );
		$status = $fresh && isset( $fresh['status'] ) ? (string) $fresh['status'] : $status;

		/**
		 * Fires when a catering payment leg is recorded as paid.
		 *
		 * @param int    $id  Enquiry ID.
		 * @param string $leg 'deposit' or 'balance'.
		 */
		do_action( 'doughboss_catering_payment', $id, $leg );
		do_action( 'doughboss_catering_status_changed', $id, $status );
		return true;
	}

	/**
	 * Find an enquiry by either of its stored PaymentIntent ids (webhook lookup).
	 *
	 * @param string $intent_id Stripe PaymentIntent id.
	 * @return array<string,mixed>|null
	 */
	public static function find_by_intent( $intent_id ) {
		global $wpdb;
		$intent_id = sanitize_text_field( $intent_id );
		if ( '' === $intent_id ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE deposit_intent_id = %s OR balance_intent_id = %s', $intent_id, $intent_id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Query enquiries for the admin list table (filter + search + paginate).
	 *
	 * @param array<string,mixed> $args status, search, per_page, page.
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'   => '',
				'search'   => '',
				'per_page' => 20,
				'page'     => 1,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['status'] && self::is_valid_status( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '( customer_name LIKE %s OR customer_email LIKE %s OR enquiry_number LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, (int) $args['per_page'] );
		$offset    = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;
		$table     = self::table();

		if ( $params ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
		}

		$list_params = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d", $list_params ), ARRAY_A );

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Generate a unique, human-readable enquiry number (CAT-YYMMDD-XXXX).
	 *
	 * @return string
	 */
	private static function generate_enquiry_number() {
		global $wpdb;

		$prefix = 'CAT-' . gmdate( 'ymd' ) . '-';
		for ( $attempt = 0; $attempt < 20; $attempt++ ) {
			$candidate = $prefix . wp_rand( 1000, 9999 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE enquiry_number = %s', $candidate ) );
			if ( ! $exists ) {
				return $candidate;
			}
		}

		// Extremely unlikely fallback: append more entropy.
		return $prefix . wp_rand( 1000, 9999 ) . '-' . wp_rand( 100, 999 );
	}

	/**
	 * Validate/normalise a Y-m-d date string; empty when invalid.
	 *
	 * @param string $value Raw date.
	 * @return string Normalised 'Y-m-d' or ''.
	 */
	private static function sanitize_date( $value ) {
		$value = sanitize_text_field( $value );
		$date  = DateTime::createFromFormat( 'Y-m-d', $value );
		if ( $date && $date->format( 'Y-m-d' ) === $value ) {
			return $value;
		}
		return '';
	}
}
