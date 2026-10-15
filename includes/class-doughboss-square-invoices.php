<?php
/**
 * Catering payments through Square Invoices.
 *
 * Staff approve and quote a catering enquiry in WordPress, then send a Square
 * invoice for the deposit or balance leg. Square emails the customer a hosted
 * payment page; DoughBoss never sees card data. The `invoice.payment_made`
 * webhook (re-verified against Square) marks the leg paid.
 *
 * The invoice id is stored in the enquiry's existing per-leg intent column as
 * `sqinv:<id>`, so find_by_intent() and has_payment_preparation() keep working.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Square invoice bridge for catering enquiries.
 */
class DoughBoss_Square_Invoices {

	const INTENT_PREFIX = 'sqinv:';

	/**
	 * Square invoice ids look like "inv:0-ChAb…" — the colon is legal here but
	 * not in canonical_id(). Bounded so prefix + id fits the varchar(64) intent column.
	 *
	 * @param mixed $id Raw id.
	 * @return string Canonical id or ''.
	 */
	public static function canonical_invoice_id( $id ) {
		$id = is_scalar( $id ) ? trim( (string) $id ) : '';
		return preg_match( '/^[A-Za-z0-9:_-]{8,58}$/', $id ) ? $id : '';
	}

	/**
	 * Whether a stored intent value is a Square invoice reference.
	 *
	 * @param mixed $intent Stored intent id.
	 * @return bool
	 */
	public static function is_invoice_intent( $intent ) {
		return is_string( $intent ) && 0 === strpos( $intent, self::INTENT_PREFIX ) && strlen( $intent ) > strlen( self::INTENT_PREFIX );
	}

	/**
	 * Native shop that bills catering. Catering is collected at Revesby, so an
	 * enquiry without an explicit shop resolves to the active `revesby` shop.
	 *
	 * @param array<string,mixed> $enquiry Enquiry row.
	 * @return int Native location id, or 0.
	 */
	public static function billing_location( array $enquiry ) {
		$id = isset( $enquiry['location_id'] ) ? absint( $enquiry['location_id'] ) : 0;
		if ( $id ) {
			return $id;
		}
		if ( ! class_exists( 'DoughBoss_Locations' ) ) {
			return 0;
		}
		foreach ( DoughBoss_Locations::all( true ) as $loc ) {
			if ( isset( $loc->slug ) && 'revesby' === $loc->slug ) {
				return (int) $loc->id;
			}
		}
		return 0;
	}

	/**
	 * Deterministic idempotency key (Square accepts up to 45+ chars on every
	 * endpoint used here; 40 hex keeps us inside all limits).
	 *
	 * @param string $purpose Step name.
	 * @param array  $enquiry Enquiry row.
	 * @param string $leg     Leg.
	 * @param int    $minor   Amount in minor units.
	 * @return string
	 */
	public static function idempotency_key( $purpose, array $enquiry, $leg, $minor ) {
		$seed = implode( '|', array( 'doughboss-catering', $purpose, (string) $enquiry['enquiry_number'], (string) $leg, (string) (int) $minor, DoughBoss_Square::mode() ) );
		return substr( hash( 'sha256', $seed ), 0, 40 );
	}

	/**
	 * Normalise an Australian phone number to E.164, or '' when unsure.
	 *
	 * @param string $phone Raw phone.
	 * @return string
	 */
	public static function e164_au( $phone ) {
		$digits = preg_replace( '/[^0-9+]/', '', (string) $phone );
		if ( preg_match( '/^\+61[2-478][0-9]{8}$/', $digits ) ) {
			return $digits;
		}
		if ( preg_match( '/^0([2-478][0-9]{8})$/', $digits, $m ) ) {
			return '+61' . $m[1];
		}
		return '';
	}

	/**
	 * Payment due date for a leg (Australia/Sydney calendar date).
	 * Deposit: within 2 days. Balance: 2 days before the event, never in the past.
	 *
	 * @param array  $enquiry Enquiry row.
	 * @param string $leg     Leg.
	 * @param string $today   Y-m-d.
	 * @return string Y-m-d
	 */
	public static function due_date( array $enquiry, $leg, $today ) {
		$base = strtotime( $today . ' 12:00:00 UTC' );
		$due  = gmdate( 'Y-m-d', $base + 2 * DAY_IN_SECONDS );
		if ( DoughBoss_Catering::LEG_BALANCE === $leg && ! empty( $enquiry['event_date'] ) ) {
			$event = strtotime( substr( (string) $enquiry['event_date'], 0, 10 ) . ' 12:00:00 UTC' );
			if ( $event ) {
				$due = gmdate( 'Y-m-d', max( $base, $event - 2 * DAY_IN_SECONDS ) );
			}
		}
		if ( ! empty( $enquiry['event_date'] ) ) {
			$event = strtotime( substr( (string) $enquiry['event_date'], 0, 10 ) . ' 12:00:00 UTC' );
			if ( $event && strtotime( $due . ' 12:00:00 UTC' ) > $event ) {
				$due = gmdate( 'Y-m-d', max( $base, $event ) );
			}
		}
		return $due;
	}

	/**
	 * Square order body for one payment leg (pure).
	 *
	 * @param array  $enquiry            Enquiry row.
	 * @param string $leg                Leg.
	 * @param int    $minor              Amount in minor units.
	 * @param string $square_location_id Square location.
	 * @param string $customer_id        Square customer id.
	 * @return array
	 */
	public static function order_body( array $enquiry, $leg, $minor, $square_location_id, $customer_id ) {
		$label = DoughBoss_Catering::LEG_BALANCE === $leg ? __( 'Catering balance', 'doughboss' ) : __( 'Catering deposit', 'doughboss' );
		$event = ! empty( $enquiry['event_date'] ) ? ' — ' . substr( (string) $enquiry['event_date'], 0, 10 ) : '';
		return array(
			'idempotency_key' => self::idempotency_key( 'order', $enquiry, $leg, $minor ),
			'order'           => array(
				'location_id'  => $square_location_id,
				'reference_id' => substr( (string) $enquiry['enquiry_number'] . ':' . $leg, 0, 40 ),
				'customer_id'  => $customer_id,
				'line_items'   => array(
					array(
						'name'             => substr( $label . ' ' . $enquiry['enquiry_number'] . $event, 0, 255 ),
						'quantity'         => '1',
						'base_price_money' => array( 'amount' => (int) $minor, 'currency' => 'AUD' ),
					),
				),
			),
		);
	}

	/**
	 * Square invoice body (pure).
	 *
	 * @param array  $enquiry            Enquiry row.
	 * @param string $leg                Leg.
	 * @param int    $minor              Amount.
	 * @param string $square_location_id Square location.
	 * @param string $order_id           Square order id.
	 * @param string $customer_id        Square customer id.
	 * @param string $today              Y-m-d.
	 * @return array
	 */
	public static function invoice_body( array $enquiry, $leg, $minor, $square_location_id, $order_id, $customer_id, $today ) {
		$is_balance = DoughBoss_Catering::LEG_BALANCE === $leg;
		$title      = $is_balance ? __( 'Dough Boss catering — balance', 'doughboss' ) : __( 'Dough Boss catering — deposit', 'doughboss' );
		$desc       = sprintf(
			/* translators: 1: enquiry number, 2: event date, 3: guest count */
			__( 'Catering %1$s for %2$s (%3$s guests). Pickup from Dough Boss Revesby, Shop 12/25 Selems Parade, Revesby NSW 2212.', 'doughboss' ),
			(string) $enquiry['enquiry_number'],
			! empty( $enquiry['event_date'] ) ? substr( (string) $enquiry['event_date'], 0, 10 ) : __( 'your event', 'doughboss' ),
			(string) (int) $enquiry['guest_count']
		);
		return array(
			'idempotency_key' => self::idempotency_key( 'invoice', $enquiry, $leg, $minor ),
			'invoice'         => array(
				'location_id'              => $square_location_id,
				'order_id'                 => $order_id,
				'primary_recipient'        => array( 'customer_id' => $customer_id ),
				'invoice_number'           => substr( (string) $enquiry['enquiry_number'] . ( $is_balance ? '-B' : '-D' ), 0, 191 ),
				'title'                    => $title,
				'description'              => substr( $desc, 0, 65536 ),
				'delivery_method'          => 'EMAIL',
				'payment_requests'         => array(
					array(
						'request_type'             => 'BALANCE',
						'due_date'                 => self::due_date( $enquiry, $leg, $today ),
						'automatic_payment_source' => 'NONE',
					),
				),
				'accepted_payment_methods' => array(
					'card'              => true,
					'square_gift_card'  => false,
					'bank_account'      => false,
					'buy_now_pay_later' => false,
				),
			),
		);
	}

	/**
	 * Create (or resume) and publish a Square invoice for one catering leg.
	 *
	 * @param int    $enquiry_id Enquiry id.
	 * @param string $leg        'deposit' or 'balance'.
	 * @return array|WP_Error { invoice_id, status, public_url, amount }.
	 */
	public static function send( $enquiry_id, $leg ) {
		$leg = DoughBoss_Catering::LEG_BALANCE === $leg ? DoughBoss_Catering::LEG_BALANCE : DoughBoss_Catering::LEG_DEPOSIT;
		$enquiry = DoughBoss_Catering::get( absint( $enquiry_id ) );
		if ( ! $enquiry ) {
			return new WP_Error( 'doughboss_catering_missing', __( 'Catering enquiry not found.', 'doughboss' ), array( 'status' => 404 ) );
		}
		if ( DoughBoss_Catering::is_paid( $enquiry, $leg ) ) {
			return new WP_Error( 'doughboss_catering_paid', __( 'That payment has already been received.', 'doughboss' ), array( 'status' => 409 ) );
		}
		if ( DoughBoss_Catering::LEG_BALANCE === $leg && ! DoughBoss_Catering::is_paid( $enquiry, DoughBoss_Catering::LEG_DEPOSIT ) ) {
			return new WP_Error( 'doughboss_catering_order', __( 'Send the deposit invoice first.', 'doughboss' ), array( 'status' => 409 ) );
		}
		if ( ! is_email( (string) $enquiry['customer_email'] ) ) {
			return new WP_Error( 'doughboss_catering_email', __( 'The customer needs a valid email to receive a Square invoice.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$minor = DoughBoss_Square::to_minor_units( DoughBoss_Catering::leg_amount( $enquiry, $leg ) );
		if ( $minor < 100 ) {
			return new WP_Error( 'doughboss_catering_amount', __( 'Save a quote with an amount of at least $1.00 before invoicing.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$location = self::billing_location( $enquiry );
		$binding  = DoughBoss_Square::commerce_binding( $location );
		if ( is_wp_error( $binding ) ) {
			return new WP_Error( 'doughboss_catering_square_off', __( 'Square isn’t ready for this shop yet. In DoughBoss → Settings check: payments on, gateway = Square, the shop’s Square location ID, and a valid access token (plus live approval in live mode).', 'doughboss' ), array( 'status' => 409 ) );
		}

		$lock_id = (int) $enquiry['id'];
		if ( ! DoughBoss_Catering::acquire_quote_lock( $lock_id ) ) {
			return new WP_Error( 'doughboss_catering_quote_busy', __( 'This enquiry is busy. Please wait a moment and try again.', 'doughboss' ), array( 'status' => 503 ) );
		}
		try {
			$enquiry = DoughBoss_Catering::get( $lock_id );
			if ( ! $enquiry ) {
				return new WP_Error( 'doughboss_catering_missing', __( 'Catering enquiry not found.', 'doughboss' ), array( 'status' => 404 ) );
			}
			$column  = DoughBoss_Catering::LEG_BALANCE === $leg ? 'balance_intent_id' : 'deposit_intent_id';
			$stored  = isset( $enquiry[ $column ] ) ? (string) $enquiry[ $column ] : '';
			if ( '' !== $stored && ! self::is_invoice_intent( $stored ) ) {
				return new WP_Error( 'doughboss_catering_prepared', __( 'Another payment method was already started for this payment.', 'doughboss' ), array( 'status' => 409 ) );
			}

			if ( self::is_invoice_intent( $stored ) ) {
				$invoice = self::get_invoice( substr( $stored, strlen( self::INTENT_PREFIX ) ), $location );
				if ( is_wp_error( $invoice ) ) {
					return $invoice;
				}
			} else {
				$customer_id = self::customer_id( $enquiry, $location );
				if ( is_wp_error( $customer_id ) ) {
					return $customer_id;
				}
				$order = DoughBoss_Square::commerce_request( 'POST', '/v2/orders', self::order_body( $enquiry, $leg, $minor, $binding['square_location_id'], $customer_id ), $location );
				if ( is_wp_error( $order ) ) {
					return $order;
				}
				$order_id = isset( $order['order']['id'] ) ? (string) $order['order']['id'] : '';
				$total    = isset( $order['order']['total_money']['amount'] ) ? (int) $order['order']['total_money']['amount'] : -1;
				if ( '' === $order_id || $total !== $minor ) {
					return new WP_Error( 'doughboss_pay_api', __( 'Square returned an unexpected order total. Nothing was sent.', 'doughboss' ), array( 'status' => 502 ) );
				}
				$created = DoughBoss_Square::commerce_request(
					'POST',
					'/v2/invoices',
					self::invoice_body( $enquiry, $leg, $minor, $binding['square_location_id'], $order_id, $customer_id, current_time( 'Y-m-d' ) ),
					$location
				);
				if ( is_wp_error( $created ) || empty( $created['invoice']['id'] ) ) {
					return is_wp_error( $created ) ? $created : new WP_Error( 'doughboss_pay_api', __( 'Square did not create the invoice.', 'doughboss' ), array( 'status' => 502 ) );
				}
				$invoice = $created['invoice'];
				if ( '' === self::canonical_invoice_id( isset( $invoice['id'] ) ? $invoice['id'] : '' ) ) {
					return new WP_Error( 'doughboss_catering_invoice', __( 'Square returned an unexpected invoice id. Check Square before retrying.', 'doughboss' ), array( 'status' => 502 ) );
				}
				if ( ! DoughBoss_Catering::set_intent( $enquiry['id'], $leg, self::INTENT_PREFIX . self::canonical_invoice_id( isset( $invoice['id'] ) ? $invoice['id'] : '' ) ) ) {
					return new WP_Error( 'doughboss_catering_prepared', __( 'Could not record the Square invoice. Check Square before retrying.', 'doughboss' ), array( 'status' => 409 ) );
				}
			}

			if ( isset( $invoice['status'] ) && 'DRAFT' === $invoice['status'] ) {
				$published = DoughBoss_Square::commerce_request(
					'POST',
					'/v2/invoices/' . self::canonical_invoice_id( $invoice['id'] ) . '/publish',
					array(
						'version'         => isset( $invoice['version'] ) ? (int) $invoice['version'] : 0,
						'idempotency_key' => self::idempotency_key( 'publish', $enquiry, $leg, $minor ),
					),
					$location
				);
				if ( is_wp_error( $published ) ) {
					return $published;
				}
				$invoice = isset( $published['invoice'] ) ? $published['invoice'] : $invoice;
			}

			if ( DoughBoss_Catering::STATUS_NEW === $enquiry['status'] ) {
				DoughBoss_Catering::update_status( $enquiry['id'], DoughBoss_Catering::STATUS_QUOTED );
			}

			/**
			 * Fires after a catering Square invoice is published (or re-read).
			 *
			 * @param int    $enquiry_id Enquiry id.
			 * @param string $leg        Leg.
			 * @param array  $invoice    Square invoice object.
			 */
			do_action( 'doughboss_catering_invoice_sent', (int) $enquiry['id'], $leg, $invoice );

			return array(
				'invoice_id' => (string) $invoice['id'],
				'status'     => isset( $invoice['status'] ) ? (string) $invoice['status'] : '',
				'public_url' => isset( $invoice['public_url'] ) ? esc_url_raw( (string) $invoice['public_url'] ) : '',
				'amount'     => $minor / 100,
			);
		} finally {
			DoughBoss_Catering::release_quote_lock( $lock_id );
		}
	}

	/**
	 * Find or create the Square customer for an enquiry (by exact email).
	 *
	 * @param array $enquiry  Enquiry row.
	 * @param int   $location Native location.
	 * @return string|WP_Error
	 */
	private static function customer_id( array $enquiry, $location ) {
		$email  = sanitize_email( (string) $enquiry['customer_email'] );
		$search = DoughBoss_Square::commerce_request(
			'POST',
			'/v2/customers/search',
			array(
				'limit' => 1,
				'query' => array( 'filter' => array( 'email_address' => array( 'exact' => $email ) ) ),
			),
			$location
		);
		if ( is_wp_error( $search ) ) {
			return $search;
		}
		if ( ! empty( $search['customers'][0]['id'] ) ) {
			return (string) $search['customers'][0]['id'];
		}

		$name  = trim( (string) $enquiry['customer_name'] );
		$parts = preg_split( '/\s+/', $name, 2 );
		$body  = array(
			'idempotency_key' => self::idempotency_key( 'customer', $enquiry, 'any', 0 ),
			'given_name'      => isset( $parts[0] ) ? substr( $parts[0], 0, 100 ) : '',
			'family_name'     => isset( $parts[1] ) ? substr( $parts[1], 0, 100 ) : '',
			'email_address'   => $email,
			'reference_id'    => substr( (string) $enquiry['enquiry_number'], 0, 40 ),
			'note'            => 'Created by DoughBoss catering',
		);
		$phone = self::e164_au( isset( $enquiry['customer_phone'] ) ? $enquiry['customer_phone'] : '' );
		if ( '' !== $phone ) {
			$body['phone_number'] = $phone;
		}
		$created = DoughBoss_Square::commerce_request( 'POST', '/v2/customers', $body, $location );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		if ( empty( $created['customer']['id'] ) ) {
			return new WP_Error( 'doughboss_pay_api', __( 'Square did not create the customer.', 'doughboss' ), array( 'status' => 502 ) );
		}
		return (string) $created['customer']['id'];
	}

	/**
	 * Re-read an invoice from Square.
	 *
	 * @param string $invoice_id Square invoice id.
	 * @param int    $location   Native location.
	 * @return array|WP_Error Invoice object.
	 */
	public static function get_invoice( $invoice_id, $location ) {
		$invoice_id = self::canonical_invoice_id( $invoice_id );
		if ( '' === $invoice_id ) {
			return new WP_Error( 'doughboss_pay_api', __( 'Invalid Square invoice reference.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$res = DoughBoss_Square::commerce_request( 'GET', '/v2/invoices/' . self::canonical_invoice_id( $invoice_id ), array(), $location );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res['invoice']['id'] ) || ! hash_equals( $invoice_id, (string) $res['invoice']['id'] ) ) {
			return new WP_Error( 'doughboss_pay_api', __( 'Square returned a different invoice.', 'doughboss' ), array( 'status' => 502 ) );
		}
		return $res['invoice'];
	}

	/**
	 * Whether a re-read invoice proves full payment of the expected leg (pure).
	 *
	 * @param array  $invoice            Square invoice.
	 * @param int    $expected_minor     Expected amount.
	 * @param string $square_location_id Expected Square location.
	 * @return bool
	 */
	public static function invoice_settles( array $invoice, $expected_minor, $square_location_id ) {
		if ( ! isset( $invoice['status'] ) || 'PAID' !== $invoice['status'] ) {
			return false;
		}
		if ( ! isset( $invoice['location_id'] ) || ! hash_equals( (string) $square_location_id, (string) $invoice['location_id'] ) ) {
			return false;
		}
		$requests = isset( $invoice['payment_requests'] ) && is_array( $invoice['payment_requests'] ) ? $invoice['payment_requests'] : array();
		$paid     = 0;
		$due      = 0;
		foreach ( $requests as $req ) {
			if ( isset( $req['total_completed_amount_money']['amount'], $req['total_completed_amount_money']['currency'] ) && 'AUD' === $req['total_completed_amount_money']['currency'] ) {
				$paid += (int) $req['total_completed_amount_money']['amount'];
			}
			if ( isset( $req['computed_amount_money']['amount'] ) ) {
				$due += (int) $req['computed_amount_money']['amount'];
			}
		}
		return (int) $expected_minor > 0 && $due === (int) $expected_minor && $paid >= (int) $expected_minor;
	}

	/**
	 * Webhook handler for invoice.* events. Re-reads the invoice and marks the
	 * matching catering leg paid only when Square proves full payment.
	 *
	 * @param string $invoice_id Square invoice id from the event.
	 * @return string Outcome: processed|ignored|pending|retry.
	 */
	public static function reconcile( $invoice_id ) {
		$invoice_id = self::canonical_invoice_id( $invoice_id );
		if ( '' === $invoice_id ) {
			return 'ignored';
		}
		$enquiry = DoughBoss_Catering::find_by_intent( self::INTENT_PREFIX . $invoice_id );
		if ( ! $enquiry ) {
			return 'ignored';
		}
		$leg = hash_equals( (string) $enquiry['balance_intent_id'], self::INTENT_PREFIX . $invoice_id ) ? DoughBoss_Catering::LEG_BALANCE : DoughBoss_Catering::LEG_DEPOSIT;
		if ( DoughBoss_Catering::is_paid( $enquiry, $leg ) ) {
			return 'processed';
		}
		$location = self::billing_location( $enquiry );
		$binding  = DoughBoss_Square::commerce_binding( $location );
		if ( is_wp_error( $binding ) ) {
			return 'retry';
		}
		$invoice = self::get_invoice( $invoice_id, $location );
		if ( is_wp_error( $invoice ) ) {
			return 'retry';
		}
		$expected = DoughBoss_Square::to_minor_units( DoughBoss_Catering::leg_amount( $enquiry, $leg ) );
		if ( ! self::invoice_settles( $invoice, $expected, $binding['square_location_id'] ) ) {
			return 'pending';
		}
		return DoughBoss_Catering::mark_paid( $enquiry['id'], $leg ) ? 'processed' : 'retry';
	}
}
