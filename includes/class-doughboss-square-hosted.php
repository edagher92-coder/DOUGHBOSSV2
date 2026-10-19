<?php
/**
 * Square-hosted storefront checkout (Checkout API payment links).
 *
 * The customer is redirected to Square's own checkout page for the exact
 * itemised Order this site already projects for the embedded flow. Nothing in
 * the browser return is trusted: an order is created only after this server
 * re-reads the bound Square Order and its single Payment and proves a
 * COMPLETED payment for the exact amount, currency and Square location bound
 * to the durable attempt. No card data ever reaches this site.
 *
 * Square references used (all fields cited in HANDOFF/2.47.0.md):
 *  - POST /v2/online-checkout/payment-links  https://developer.squareup.com/reference/square/checkout-api/create-payment-link
 *  - DELETE /v2/online-checkout/payment-links/{id}  https://developer.squareup.com/reference/square/checkout-api/delete-payment-link
 *  - GET /v2/orders/{id}  https://developer.squareup.com/reference/square/orders-api/retrieve-order
 *  - GET /v2/payments/{id}  https://developer.squareup.com/reference/square/payments-api/get-payment
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Square-hosted checkout orchestration and pure validators.
 */
class DoughBoss_Square_Hosted {

	/** Lifetime of one hosted checkout; matches the voucher lease and browser resume window. */
	const LINK_TTL = 2700;

	/** Extra grace before an abandoned link is retired by the sweep. */
	const SWEEP_GRACE = 900;

	/** WP-Cron hook that retires abandoned links and recovers missed payments. */
	const SWEEP_HOOK = 'doughboss_square_hosted_sweep';

	/** Square payment_note limit. */
	const PAYMENT_NOTE_MAX = 500;

	/** Square checkout_options.redirect_url limit. */
	const REDIRECT_URL_MAX = 2048;

	/** Square pre_populated_data.buyer_email limit. */
	const BUYER_EMAIL_MAX = 256;

	/** Return marker carried in the same-site redirect URL. */
	const RETURN_PARAM = 'doughboss_square_return';

	/**
	 * Register the sweep schedule.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Add a 15-minute interval for the sweep.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}
		if ( ! isset( $schedules['doughboss_fifteen_minutes'] ) ) {
			$schedules['doughboss_fifteen_minutes'] = array( 'interval' => 15 * 60, 'display' => 'Every 15 minutes (DoughBoss)' );
		}
		return $schedules;
	}

	/**
	 * Keep the sweep booked while Square is the gateway (hosted attempts can
	 * outlive a later switch back to embedded), and unbook it otherwise.
	 *
	 * @return void
	 */
	public static function schedule() {
		$next = wp_next_scheduled( self::SWEEP_HOOK );
		if ( 'square' !== DoughBoss_Settings::payment_gateway() ) {
			if ( $next ) {
				wp_unschedule_event( $next, self::SWEEP_HOOK );
			}
			return;
		}
		if ( ! $next ) {
			wp_schedule_event( time() + 15 * 60, 'doughboss_fifteen_minutes', self::SWEEP_HOOK );
		}
	}

	/**
	 * Whether new storefront Square payments use the hosted page.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return 'square' === DoughBoss_Settings::payment_gateway() && 'hosted' === DoughBoss_Settings::square_checkout_style();
	}

	/**
	 * Whether a durable attempt was created for Square-hosted checkout.
	 *
	 * @param mixed $attempt Attempt row.
	 * @return bool
	 */
	public static function is_hosted_attempt( $attempt ) {
		if ( ! is_array( $attempt ) || ! isset( $attempt['provider'] ) || 'square' !== (string) $attempt['provider'] ) {
			return false;
		}
		$metadata = DoughBoss_Payment_Attempts::metadata( $attempt );
		return isset( $metadata['checkout_style'] ) && 'hosted' === $metadata['checkout_style'];
	}

	/* ------------------------------------------------------------------ */
	/* Pure helpers (no database, settings or HTTP)                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Australian phone number in E.164 for Square's pre-populated checkout.
	 * Anything that is not a recognisable AU mobile/landline returns ''.
	 *
	 * @param mixed $phone Customer-entered phone.
	 * @return string
	 */
	public static function au_e164( $phone ) {
		if ( ! is_string( $phone ) || strlen( $phone ) > 40 ) {
			return '';
		}
		$trimmed = trim( $phone );
		if ( '' === $trimmed || ! preg_match( '/^\+?[0-9 ()-]+$/', $trimmed ) ) {
			return '';
		}
		$plus   = 0 === strpos( $trimmed, '+' );
		$digits = preg_replace( '/[^0-9]/', '', $trimmed );
		if ( $plus || 0 === strpos( $digits, '61' ) && 11 === strlen( $digits ) ) {
			$national = 0 === strpos( $digits, '61' ) ? substr( $digits, 2 ) : '';
		} elseif ( 10 === strlen( $digits ) && '0' === $digits[0] ) {
			$national = substr( $digits, 1 );
		} else {
			$national = '';
		}
		return 1 === preg_match( '/^[23478][0-9]{8}\z/', $national ) ? '+61' . $national : '';
	}

	/**
	 * Build the same-site hosted return URL carrying the checkout key.
	 *
	 * @param string $base         Already same-site-normalised return URL.
	 * @param string $checkout_key 64-hex checkout identity.
	 * @param string $home_url     Site home URL.
	 * @return string '' when unsafe.
	 */
	public static function return_url( $base, $checkout_key, $home_url ) {
		if ( ! is_string( $checkout_key ) || 1 !== preg_match( '/^[a-f0-9]{64}\z/', $checkout_key ) || ! self::same_site_https( $base, $home_url ) ) {
			return '';
		}
		$base = (string) $base;
		$hash = strpos( $base, '#' );
		if ( false !== $hash ) {
			$base = substr( $base, 0, $hash );
		}
		$url = $base . ( false === strpos( $base, '?' ) ? '?' : '&' ) . self::RETURN_PARAM . '=' . $checkout_key;
		return strlen( $url ) <= self::REDIRECT_URL_MAX ? $url : '';
	}

	/**
	 * Whether a URL is HTTPS on exactly the site's own host.
	 *
	 * @param mixed  $url      Candidate.
	 * @param string $home_url Site home URL.
	 * @return bool
	 */
	public static function same_site_https( $url, $home_url ) {
		if ( ! is_string( $url ) || '' === $url || preg_match( '/[\s\x00-\x1F\x7F]/', $url ) ) {
			return false;
		}
		$parts = parse_url( $url );
		$home  = parse_url( (string) $home_url );
		return is_array( $parts ) && is_array( $home ) && isset( $parts['scheme'], $parts['host'], $home['host'] )
			&& 'https' === strtolower( $parts['scheme'] )
			&& strtolower( $parts['host'] ) === strtolower( $home['host'] )
			&& ! isset( $parts['user'] ) && ! isset( $parts['pass'] )
			&& ( ! isset( $parts['port'] ) || 443 === (int) $parts['port'] );
	}

	/**
	 * Extract the checkout key from a hosted return URL query.
	 *
	 * @param mixed $value Raw parameter.
	 * @return string '' unless a canonical 64-hex key.
	 */
	public static function returned_checkout_key( $value ) {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return 1 === preg_match( '/^[a-f0-9]{64}\z/', $value ) ? $value : '';
	}

	/**
	 * Whether a Square payment-link URL is on Square's checkout hosts for the mode.
	 *
	 * @param mixed  $url  Candidate URL.
	 * @param string $mode 'test' or 'live'.
	 * @return bool
	 */
	public static function checkout_url_valid( $url, $mode ) {
		if ( ! is_string( $url ) || strlen( $url ) > 512 || preg_match( '/[\s\x00-\x1F\x7F"\'<>]/', $url ) ) {
			return false;
		}
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] )
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
			return false;
		}
		$host  = strtolower( $parts['host'] );
		$hosts = 'live' === $mode ? array( 'square.link', 'checkout.square.site' ) : array( 'sandbox.square.link', 'sandbox.checkout.square.site' );
		return in_array( $host, $hosts, true )
			|| ( 'test' === $mode && 1 === preg_match( '/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.squareupsandbox\.com\z/', $host ) );
	}

	/**
	 * Exact CreatePaymentLink body for one retained itemised projection.
	 *
	 * @param array  $projection   DoughBoss_Square_Orders::project() result.
	 * @param string $checkout_key 64-hex checkout identity (idempotency source).
	 * @param string $redirect_url Same-site return URL (already validated).
	 * @param string $buyer_email  Snapshot customer email.
	 * @param string $buyer_phone  Snapshot customer phone.
	 * @return array|WP_Error
	 */
	public static function payment_link_body( array $projection, $checkout_key, $redirect_url, $buyer_email, $buyer_phone ) {
		if ( ! isset( $projection['order'], $projection['order']['location_id'], $projection['order']['reference_id'], $projection['order']['line_items'] )
			|| ! is_array( $projection['order']['line_items'] ) || empty( $projection['order']['line_items'] )
			|| ! is_string( $checkout_key ) || 1 !== preg_match( '/^[a-f0-9]{64}\z/', $checkout_key )
			|| ! is_string( $redirect_url ) || '' === $redirect_url || strlen( $redirect_url ) > self::REDIRECT_URL_MAX
			|| 0 !== strpos( $redirect_url, 'https://' ) ) {
			return self::shape_error();
		}
		$order = $projection['order'];
		// Square owns a link Order's lifecycle (DRAFT, then OPEN on checkout).
		unset( $order['state'] );
		$body = array(
			'idempotency_key'  => DoughBoss_Square::idempotency_key( 'plink', $checkout_key ),
			'order'            => $order,
			'checkout_options' => array(
				'redirect_url'             => $redirect_url,
				'ask_for_shipping_address' => false,
				'accepted_payment_methods' => array(
					'apple_pay'         => true,
					'google_pay'        => true,
					'cash_app_pay'      => false,
					'afterpay_clearpay' => false,
				),
				'allow_tipping'            => false,
				'enable_coupon'            => false,
				'enable_loyalty'           => false,
			),
			'payment_note'     => substr( 'DoughBoss web order ' . (string) $order['reference_id'], 0, self::PAYMENT_NOTE_MAX ),
		);
		$prefill = array();
		$email   = is_string( $buyer_email ) ? trim( $buyer_email ) : '';
		if ( '' !== $email && strlen( $email ) <= self::BUYER_EMAIL_MAX && is_email( $email ) ) {
			$prefill['buyer_email'] = $email;
		}
		$phone = self::au_e164( $buyer_phone );
		if ( '' !== $phone ) {
			$prefill['buyer_phone_number'] = $phone;
		}
		if ( $prefill ) {
			$body['pre_populated_data'] = $prefill;
		}
		return $body;
	}

	/**
	 * Validate a CreatePaymentLink response.
	 *
	 * @param mixed  $response Decoded response.
	 * @param string $mode     'test' or 'live'.
	 * @return array|WP_Error { id, url, order_id }
	 */
	public static function link_facts( $response, $mode ) {
		$link = is_array( $response ) && isset( $response['payment_link'] ) && is_array( $response['payment_link'] ) ? $response['payment_link'] : array();
		$id   = isset( $link['id'] ) && is_string( $link['id'] ) ? $link['id'] : '';
		$url  = isset( $link['url'] ) && is_string( $link['url'] ) ? $link['url'] : '';
		$long = isset( $link['long_url'] ) && is_string( $link['long_url'] ) ? $link['long_url'] : '';
		$oid  = isset( $link['order_id'] ) && is_string( $link['order_id'] ) ? $link['order_id'] : '';
		if ( ! self::checkout_url_valid( $url, $mode ) && self::checkout_url_valid( $long, $mode ) ) {
			$url = $long;
		}
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{4,192}\z/', $id ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{8,191}\z/', $oid )
			|| ! self::checkout_url_valid( $url, $mode ) || strlen( $url ) > 256
			|| ( array_key_exists( 'errors', (array) $response ) && ! empty( $response['errors'] ) ) ) {
			return self::shape_error();
		}
		return array( 'id' => $id, 'url' => $url, 'order_id' => $oid );
	}

	/**
	 * Classify an Order read without treating Order state as payment proof.
	 *
	 * @param mixed  $response       Decoded GET /v2/orders/{id} response.
	 * @param string $bound_order_id Bound Order id.
	 * @return array { state: unpaid|canceled|tendered|invalid, payment_id? }
	 */
	public static function order_payment_state( $response, $bound_order_id ) {
		$order = is_array( $response ) && isset( $response['order'] ) && is_array( $response['order'] ) ? $response['order'] : null;
		if ( ! $order || ! isset( $order['id'], $order['state'] ) || ! is_string( $bound_order_id ) || $order['id'] !== $bound_order_id ) {
			return array( 'state' => 'invalid' );
		}
		$tenders = array_key_exists( 'tenders', $order ) ? $order['tenders'] : array();
		if ( ! is_array( $tenders ) ) {
			return array( 'state' => 'invalid' );
		}
		if ( empty( $tenders ) ) {
			return array( 'state' => 'CANCELED' === $order['state'] ? 'canceled' : 'unpaid' );
		}
		if ( 1 !== count( $tenders ) ) {
			return array( 'state' => 'invalid' );
		}
		$tender = reset( $tenders );
		$id     = is_array( $tender ) && isset( $tender['payment_id'] ) && is_string( $tender['payment_id'] ) ? $tender['payment_id'] : '';
		return 1 === preg_match( '/^[A-Za-z0-9_-]{8,191}\z/', $id ) ? array( 'state' => 'tendered', 'payment_id' => $id ) : array( 'state' => 'invalid' );
	}

	/**
	 * Prove one COMPLETED hosted payment for exactly the bound attempt.
	 *
	 * @param array  $order_response     GET /v2/orders/{id} response.
	 * @param array  $payment            GET /v2/payments/{id} `payment` object.
	 * @param array  $projection         Exact retained projection (location/amount/reference).
	 * @param string $bound_order_id     Order id bound on the attempt.
	 * @param string $square_location_id Square location bound on the attempt.
	 * @return array|WP_Error { payment_id, order_id }
	 */
	public static function paid_proof( array $order_response, array $payment, array $projection, $bound_order_id, $square_location_id ) {
		if ( ! is_string( $square_location_id ) || '' === $square_location_id
			|| ! isset( $projection['order']['location_id'] ) || $projection['order']['location_id'] !== $square_location_id
			|| ! isset( $payment['location_id'] ) || $payment['location_id'] !== $square_location_id ) {
			return self::proof_error();
		}
		$facts = DoughBoss_Square_Orders::validate_hosted_response( $order_response, $projection, $payment, $bound_order_id );
		if ( is_wp_error( $facts ) ) {
			return self::proof_error();
		}
		return array( 'payment_id' => $payment['id'], 'order_id' => $bound_order_id );
	}

	/* ------------------------------------------------------------------ */
	/* Orchestration                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Prepare a durable attempt and return Square's hosted checkout URL.
	 *
	 * Never charges: a payment link moves no money until the buyer pays on
	 * Square's page. The idempotency key is derived from the checkout key, so a
	 * replay returns the same link (and the same Square Order).
	 *
	 * @param int    $amount_minor Server-computed cents.
	 * @param string $currency     Server-computed ISO currency.
	 * @param array  $metadata     Checkout binding (same keys as embedded) plus return_url.
	 * @return array|WP_Error { state: redirect|confirm, checkout_url, attempt_id }
	 */
	public static function create_checkout( $amount_minor, $currency, array $metadata ) {
		global $wpdb;
		$amount_minor     = absint( $amount_minor );
		$currency         = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $currency ) );
		$checkout_key     = isset( $metadata['checkout_key'] ) ? strtolower( sanitize_text_field( (string) $metadata['checkout_key'] ) ) : '';
		$location_id      = isset( $metadata['location_id'] ) ? absint( $metadata['location_id'] ) : 0;
		$table_id         = isset( $metadata['table_id'] ) ? absint( $metadata['table_id'] ) : 0;
		$qr_code_id       = isset( $metadata['qr_code_id'] ) ? absint( $metadata['qr_code_id'] ) : 0;
		$protocol         = isset( $metadata['protocol_version'] ) ? sanitize_key( (string) $metadata['protocol_version'] ) : '';
		$attempt_identity = isset( $metadata['attempt_identity'] ) ? strtolower( sanitize_text_field( (string) $metadata['attempt_identity'] ) ) : '';
		$binding_hash     = isset( $metadata['binding_hash'] ) ? strtolower( sanitize_text_field( (string) $metadata['binding_hash'] ) ) : '';
		$cart_guard       = isset( $metadata['cart_guard'] ) ? strtolower( sanitize_text_field( (string) $metadata['cart_guard'] ) ) : '';
		$voucher_code     = isset( $metadata['voucher_code'] ) ? sanitize_text_field( (string) $metadata['voucher_code'] ) : '';
		$voucher_key      = isset( $metadata['voucher_reservation_key'] ) ? strtolower( sanitize_text_field( (string) $metadata['voucher_reservation_key'] ) ) : '';
		$context          = isset( $metadata['context'] ) ? substr( sanitize_key( (string) $metadata['context'] ), 0, 32 ) : ( $table_id ? 'table_qr' : 'web' );
		$return_base      = isset( $metadata['return_url'] ) ? (string) $metadata['return_url'] : '';
		$purpose          = 'order';

		if ( $amount_minor < 1 || 3 !== strlen( $currency )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $checkout_key ) || ! $location_id || 'square-v2' !== $protocol
			|| ! preg_match( '/^[a-f0-9]{64}$/', $attempt_identity ) || ! preg_match( '/^[a-f0-9]{64}$/', $binding_hash )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $cart_guard )
			|| ! in_array( $context, array( 'web', 'table_qr' ), true )
			|| ( '' === $voucher_code && '' !== $voucher_key )
			|| ( '' !== $voucher_code && ! preg_match( '/^[a-f0-9]{64}$/', $voucher_key ) ) ) {
			return new WP_Error( 'doughboss_pay_request', __( 'The payment request is incomplete.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$redirect_url = self::return_url( $return_base, $checkout_key, home_url( '/' ) );
		if ( '' === $redirect_url ) {
			return new WP_Error( 'doughboss_square_hosted_return', __( 'Secure Square checkout needs this site to be served over HTTPS.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$shop_binding = DoughBoss_Square::shop_binding( $location_id );
		if ( is_wp_error( $shop_binding ) ) {
			return $shop_binding;
		}

		$safe_metadata = $metadata;
		foreach ( array( 'tender_kind', 'square_tender_kind', 'attempt_key', 'checkout_key', 'source_id', 'verification_token', 'preorder_payment_token', 'orders_protocol', 'orders_snapshot_hash', 'return_url', 'checkout_style', 'square_payment_link_id', 'hosted_checkout_url' ) as $transient ) {
			unset( $safe_metadata[ $transient ] );
		}
		$safe_metadata['square_shop_slug']   = $shop_binding['shop_slug'];
		$safe_metadata['square_location_id'] = $shop_binding['square_location_id'];
		$safe_metadata['square_mode']        = $shop_binding['square_mode'];
		$safe_metadata['square_tender_kind'] = 'card';
		$safe_metadata['checkout_style']     = 'hosted';

		$attempt_key = hash( 'sha256', 'square-v2|' . $attempt_identity );
		if ( ! DoughBoss_Payment_Attempts::acquire_local_reference_lock( $cart_guard ) ) {
			return new WP_Error( 'doughboss_pay_busy', __( 'This cart payment is busy. Please wait a moment and try again.', 'doughboss' ), array( 'status' => 503 ) );
		}
		try {
			$legacy = DoughBoss_Payment_Attempts::find_blocking_legacy_square();
			if ( is_wp_error( $legacy ) ) {
				return $legacy;
			}
			if ( $legacy ) {
				return new WP_Error( 'doughboss_pay_legacy_pending', __( 'Card payments are temporarily unavailable while an earlier Square payment is reconciled. Please contact the shop before paying again.', 'doughboss' ), array( 'status' => 409, 'payment_pending' => true, 'retry_safe' => false ) );
			}
			$blocking = DoughBoss_Payment_Attempts::find_blocking_by_local_reference( 'square', $cart_guard, $attempt_key );
			if ( is_wp_error( $blocking ) ) {
				return $blocking;
			}
			if ( $blocking ) {
				return self::pending( $blocking, 'doughboss_pay_cart_pending', __( 'A secure Square checkout for this cart is already open or being confirmed. Finish it in the other window, or wait for it to expire before starting again. Please do not pay twice.', 'doughboss' ) );
			}
			$attempt = DoughBoss_Payment_Attempts::find_by_attempt_key( $attempt_key );
			if ( '' !== (string) $wpdb->last_error ) {
				return self::storage_error();
			}
			if ( ! $attempt ) {
				$attempt = DoughBoss_Payment_Attempts::find_by_checkout_key( $checkout_key );
				if ( '' !== (string) $wpdb->last_error ) {
					return self::storage_error();
				}
			}
			if ( ! $attempt ) {
				if ( ! DoughBoss_Activator::payment_storage_ready() ) {
					return self::storage_error();
				}
				$snapshot = DoughBoss_Checkout_Snapshots::find( $checkout_key );
				if ( '' !== (string) $wpdb->last_error || ! is_array( $snapshot )
					|| 'prepared' !== $snapshot['status'] || 0 !== (int) $snapshot['order_id']
					|| ! isset( $snapshot['payload']['binding_hash'], $snapshot['payload']['order']['location_id'] )
					|| ! is_string( $snapshot['payload']['binding_hash'] )
					|| ! hash_equals( $binding_hash, $snapshot['payload']['binding_hash'] )
					|| (string) $location_id !== (string) $snapshot['payload']['order']['location_id'] ) {
					return self::snapshot_error();
				}
				// Pure preflight with a provisional reference; dispatch projects the persisted id.
				$projection = DoughBoss_Square_Orders::project( $snapshot['payload'], $shop_binding, 1 );
				if ( is_wp_error( $projection ) || $projection['amounts']['total'] !== $amount_minor || $projection['currency'] !== $currency ) {
					return self::snapshot_error();
				}
				$safe_metadata['orders_protocol']      = 'square-orders-v1';
				$safe_metadata['orders_snapshot_hash'] = $snapshot['payload_hash'];
				$attempt = DoughBoss_Payment_Attempts::create_or_find( array(
					'attempt_key'     => $attempt_key,
					'checkout_key'    => $checkout_key,
					'provider'        => 'square',
					'purpose'         => $purpose,
					'context'         => $context,
					'local_reference' => $cart_guard,
					'location_id'     => $location_id,
					'table_id'        => $table_id,
					'qr_code_id'      => $qr_code_id,
					'amount_minor'    => $amount_minor,
					'currency'        => $currency,
					'status'          => 'prepared',
					'safe_metadata'   => $safe_metadata,
				) );
			}
		} finally {
			DoughBoss_Payment_Attempts::release_local_reference_lock( $cart_guard );
		}
		if ( ! is_array( $attempt ) ) {
			return self::storage_error();
		}
		$stored = DoughBoss_Payment_Attempts::metadata( $attempt );
		if ( 'square' !== (string) $attempt['provider']
			|| (int) $attempt['amount_minor'] !== $amount_minor
			|| strtoupper( (string) $attempt['currency'] ) !== $currency
			|| ! hash_equals( (string) $attempt['attempt_key'], $attempt_key )
			|| ! hash_equals( (string) $attempt['checkout_key'], $checkout_key )
			|| ! hash_equals( (string) $attempt['purpose'], $purpose )
			|| ! hash_equals( (string) $attempt['context'], $context )
			|| ! hash_equals( (string) $attempt['local_reference'], $cart_guard )
			|| (int) $attempt['location_id'] !== $location_id
			|| (int) $attempt['table_id'] !== $table_id
			|| (int) $attempt['qr_code_id'] !== $qr_code_id ) {
			return new WP_Error( 'doughboss_pay_attempt_changed', __( 'Your order changed while payment was being prepared. Please start payment again.', 'doughboss' ), array( 'status' => 409 ) );
		}
		if ( ! self::is_hosted_attempt( $attempt ) ) {
			return self::pending( $attempt, 'doughboss_square_style_changed', __( 'This payment was started with the on-site card form. Please do not pay again; refresh the page or contact the shop so we can confirm it.', 'doughboss' ) );
		}
		if ( 'square-v2' !== ( isset( $stored['protocol_version'] ) ? (string) $stored['protocol_version'] : '' )
			|| empty( $stored['binding_hash'] ) || ! hash_equals( $binding_hash, (string) $stored['binding_hash'] )
			|| 'square-orders-v1' !== ( isset( $stored['orders_protocol'] ) ? $stored['orders_protocol'] : '' )
			|| ! isset( $stored['orders_snapshot_hash'] ) || ! is_string( $stored['orders_snapshot_hash'] ) ) {
			return new WP_Error( 'doughboss_pay_legacy_locked', __( 'This payment attempt cannot be retried automatically. Please contact the shop before paying again.', 'doughboss' ), array( 'status' => 409, 'payment_pending' => true, 'attempt_id' => (int) $attempt['id'] ) );
		}

		$status = (string) $attempt['status'];
		if ( in_array( $status, array( 'failed', 'voided' ), true ) ) {
			return self::closed_error( $attempt );
		}
		if ( ! empty( $attempt['provider_reference'] ) || in_array( $status, array( 'processing', 'unknown', 'succeeded', 'order_committed', 'mismatch' ), true ) ) {
			// A payment already exists or is being reconciled: only confirmation may follow.
			return array( 'state' => 'confirm', 'checkout_url' => '', 'attempt_id' => (int) $attempt['id'] );
		}
		if ( 'prepared' === $status ) {
			$claimed = '' !== $voucher_code
				? DoughBoss_Voucher::claim_payment_dispatch( $voucher_code, $voucher_key, (int) $attempt['id'] )
				: DoughBoss_Payment_Attempts::claim_irreversible_creation( (int) $attempt['id'] );
			if ( is_wp_error( $claimed ) ) {
				return $claimed;
			}
			$attempt = DoughBoss_Payment_Attempts::find( (int) $attempt['id'] );
			if ( ! is_array( $attempt ) ) {
				return self::storage_error();
			}
		}
		if ( 'dispatching' !== (string) $attempt['status'] || ! empty( $attempt['provider_reference'] ) ) {
			return self::pending( $attempt, 'doughboss_pay_provisioning' );
		}
		return self::dispatch_link( $attempt, $redirect_url );
	}

	/**
	 * Create (or idempotently replay) and bind the payment link for a claimed attempt.
	 *
	 * @param array  $attempt      Dispatching hosted attempt.
	 * @param string $redirect_url Same-site return URL.
	 * @return array|WP_Error
	 */
	private static function dispatch_link( array $attempt, $redirect_url ) {
		$metadata = DoughBoss_Payment_Attempts::metadata( $attempt );
		$mode     = isset( $metadata['square_mode'] ) ? (string) $metadata['square_mode'] : '';
		if ( ! empty( $metadata['square_payment_link_id'] ) && ! empty( $attempt['provider_order_reference'] )
			&& isset( $metadata['hosted_checkout_url'] ) && self::checkout_url_valid( $metadata['hosted_checkout_url'], $mode ) ) {
			return array( 'state' => 'redirect', 'checkout_url' => (string) $metadata['hosted_checkout_url'], 'attempt_id' => (int) $attempt['id'] );
		}
		$binding = DoughBoss_Square::attempt_binding( $attempt );
		if ( is_wp_error( $binding ) ) {
			return $binding;
		}
		$projection = DoughBoss_Square::attempt_projection( $attempt, $binding );
		if ( is_wp_error( $projection ) ) {
			return $projection;
		}
		$snapshot = DoughBoss_Checkout_Snapshots::find_for_irreversible_payment( (string) $attempt['checkout_key'] );
		$order    = is_array( $snapshot ) && isset( $snapshot['payload']['order'] ) && is_array( $snapshot['payload']['order'] ) ? $snapshot['payload']['order'] : array();
		if ( ! $order || ! isset( $snapshot['payload_hash'], $metadata['orders_snapshot_hash'] ) || ! hash_equals( (string) $metadata['orders_snapshot_hash'], (string) $snapshot['payload_hash'] ) ) {
			return self::snapshot_error();
		}
		$body = self::payment_link_body(
			$projection,
			(string) $attempt['checkout_key'],
			$redirect_url,
			isset( $order['customer_email'] ) ? (string) $order['customer_email'] : '',
			isset( $order['customer_phone'] ) ? (string) $order['customer_phone'] : ''
		);
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$response = DoughBoss_Square::online_checkout_request( 'POST', '/v2/online-checkout/payment-links', $body, $binding );
		if ( is_wp_error( $response ) ) {
			$data = $response->get_error_data();
			$http = is_array( $data ) && isset( $data['square_http'] ) ? (int) $data['square_http'] : 0;
			if ( 'doughboss_pay_api' === $response->get_error_code() && $http >= 400 && $http < 500 && ! in_array( $http, array( 408, 409, 429 ), true ) ) {
				// Square refused to create the link. No checkout page exists, so
				// no money can move: close this attempt so a new one may start.
				if ( DoughBoss_Payment_Attempts::mark_irreversible_failed( (int) $attempt['id'], 'square_hosted_link_refused' ) ) {
					return self::closed_error( $attempt, __( 'We could not open the secure Square checkout. No payment was taken — please check your details and try again.', 'doughboss' ) );
				}
			}
			// Transport/5xx: the same idempotency key will replay the same link.
			return new WP_Error( 'doughboss_square_hosted_unavailable', __( 'Square checkout could not be opened right now. No payment was taken. Please try again in a moment.', 'doughboss' ), array( 'status' => 503, 'payment_pending' => false, 'retry_safe' => true, 'retry_same_attempt' => true, 'attempt_id' => (int) $attempt['id'] ) );
		}
		$facts = self::link_facts( $response, $mode );
		if ( is_wp_error( $facts ) ) {
			// The URL is never handed out, so nobody can pay this response.
			DoughBoss_Payment_Attempts::mark_irreversible_failed( (int) $attempt['id'], 'square_hosted_link_shape' );
			return self::closed_error( $attempt );
		}
		if ( ! empty( $attempt['provider_order_reference'] ) && $attempt['provider_order_reference'] !== $facts['order_id'] ) {
			return self::pending( $attempt, 'doughboss_square_hosted_order_changed' );
		}
		$order_read = DoughBoss_Square::online_checkout_request( 'GET', '/v2/orders/' . rawurlencode( $facts['order_id'] ), array(), $binding );
		if ( is_wp_error( $order_read ) ) {
			return new WP_Error( 'doughboss_square_hosted_unavailable', __( 'Square checkout could not be opened right now. No payment was taken. Please try again in a moment.', 'doughboss' ), array( 'status' => 503, 'payment_pending' => false, 'retry_safe' => true, 'retry_same_attempt' => true, 'attempt_id' => (int) $attempt['id'] ) );
		}
		$unpaid = DoughBoss_Square_Orders::validate_hosted_response( $order_read, $projection, null, $facts['order_id'] );
		if ( is_wp_error( $unpaid ) ) {
			// Square's Order does not match the retained economics: never hand it out.
			$deleted = DoughBoss_Square::online_checkout_request( 'DELETE', '/v2/online-checkout/payment-links/' . rawurlencode( $facts['id'] ), array(), $binding );
			if ( ! is_wp_error( $deleted ) ) {
				DoughBoss_Payment_Attempts::mark_irreversible_failed( (int) $attempt['id'], 'square_hosted_order_drift' );
			} else {
				DoughBoss_Payment_Attempts::mark_irreversible_unknown( (int) $attempt['id'], 'square_hosted_order_drift' );
			}
			return self::pending( $attempt, 'doughboss_square_hosted_order_changed', __( 'The secure Square checkout did not match your order, so it was not opened. No payment was taken; please contact the shop.', 'doughboss' ) );
		}
		if ( empty( $attempt['provider_order_reference'] ) ) {
			$bound = DoughBoss_Payment_Attempts::bind_square_order( (int) $attempt['id'], $facts['order_id'], $attempt );
			if ( ! is_array( $bound ) ) {
				$bound = DoughBoss_Payment_Attempts::find( (int) $attempt['id'] );
				if ( ! is_array( $bound ) || $facts['order_id'] !== $bound['provider_order_reference'] ) {
					return self::pending( $attempt, 'doughboss_pay_order_binding' );
				}
			}
			$attempt = $bound;
		}
		$metadata                           = DoughBoss_Payment_Attempts::metadata( $attempt );
		$metadata['square_payment_link_id'] = $facts['id'];
		$metadata['hosted_checkout_url']    = $facts['url'];
		$metadata['hosted_link_created']    = time();
		$saved = DoughBoss_Payment_Attempts::update( (int) $attempt['id'], array( 'safe_metadata' => $metadata ) );
		$saved_meta = is_array( $saved ) ? DoughBoss_Payment_Attempts::metadata( $saved ) : array();
		if ( ! isset( $saved_meta['square_payment_link_id'], $saved_meta['hosted_checkout_url'] )
			|| $facts['id'] !== $saved_meta['square_payment_link_id'] || $facts['url'] !== $saved_meta['hosted_checkout_url'] ) {
			// Never hand out a URL whose link could not be retired later.
			return new WP_Error( 'doughboss_square_hosted_unavailable', __( 'Square checkout could not be opened right now. No payment was taken. Please try again in a moment.', 'doughboss' ), array( 'status' => 503, 'payment_pending' => false, 'retry_safe' => true, 'retry_same_attempt' => true, 'attempt_id' => (int) $attempt['id'] ) );
		}
		return array( 'state' => 'redirect', 'checkout_url' => $facts['url'], 'attempt_id' => (int) $attempt['id'] );
	}

	/**
	 * Re-read Square and classify a hosted attempt. Binds a proven payment.
	 *
	 * @param array $attempt Hosted attempt.
	 * @return array|WP_Error { state: paid|unpaid|pending|failed, payment_id?, attempt }
	 */
	public static function confirm( array $attempt ) {
		$fresh = DoughBoss_Payment_Attempts::find( isset( $attempt['id'] ) ? (int) $attempt['id'] : 0 );
		if ( ! is_array( $fresh ) || ! self::is_hosted_attempt( $fresh ) || 'order' !== (string) $fresh['purpose'] ) {
			return new WP_Error( 'doughboss_pay_attempt', __( 'The payment attempt could not be reconciled.', 'doughboss' ), array( 'status' => 409 ) );
		}
		$attempt = $fresh;
		$status  = (string) $attempt['status'];
		if ( in_array( $status, array( 'failed', 'voided' ), true ) ) {
			return array( 'state' => 'failed', 'attempt' => $attempt );
		}
		if ( 'order_committed' === $status && ! empty( $attempt['provider_reference'] ) ) {
			return array( 'state' => 'paid', 'payment_id' => (string) $attempt['provider_reference'], 'attempt' => $attempt );
		}
		if ( 'mismatch' === $status ) {
			return self::pending( $attempt, 'doughboss_pay_mismatch', __( 'Your Square payment needs staff review before the order is sent. Please do not pay again; the shop will contact you.', 'doughboss' ) );
		}
		if ( 'succeeded' === $status && ! empty( $attempt['provider_reference'] ) && ! empty( $attempt['verified_at'] )
			&& class_exists( 'DoughBoss_Order' ) && DoughBoss_Order::find_id_by_payment_intent( (string) $attempt['provider_reference'] ) ) {
			// Already proven and fulfilled; only bookkeeping may need repair.
			return array( 'state' => 'paid', 'payment_id' => (string) $attempt['provider_reference'], 'attempt' => $attempt );
		}
		$metadata = DoughBoss_Payment_Attempts::metadata( $attempt );
		$binding  = DoughBoss_Square::attempt_binding( $attempt );
		if ( is_wp_error( $binding ) ) {
			return self::pending( $attempt, $binding->get_error_code() );
		}
		$order_id = isset( $attempt['provider_order_reference'] ) ? (string) $attempt['provider_order_reference'] : '';
		if ( '' === $order_id ) {
			return in_array( $status, array( 'prepared', 'dispatching' ), true ) && empty( $attempt['provider_reference'] )
				? array( 'state' => 'unpaid', 'attempt' => $attempt )
				: self::pending( $attempt, 'doughboss_pay_order_binding' );
		}
		$order_read = DoughBoss_Square::online_checkout_request( 'GET', '/v2/orders/' . rawurlencode( $order_id ), array(), $binding );
		if ( is_wp_error( $order_read ) ) {
			return self::pending( $attempt, 'doughboss_pay_order_unavailable', __( 'We could not reach Square to confirm your payment yet. Please do not pay again; this page will keep checking.', 'doughboss' ) );
		}
		$state = self::order_payment_state( $order_read, $order_id );
		if ( 'invalid' === $state['state'] ) {
			if ( 'dispatching' === $status ) {
				DoughBoss_Payment_Attempts::mark_irreversible_unknown( (int) $attempt['id'], 'square_hosted_tenders' );
			}
			return self::pending( $attempt, 'doughboss_square_hosted_review', __( 'Your Square payment needs staff review before the order is sent. Please do not pay again; the shop will contact you.', 'doughboss' ) );
		}
		if ( 'tendered' !== $state['state'] ) {
			if ( ! empty( $attempt['provider_reference'] ) ) {
				// A bound payment whose Order lost its tender (refund/void) is held.
				return self::pending( $attempt, 'doughboss_square_hosted_review' );
			}
			return array( 'state' => 'canceled' === $state['state'] ? 'failed' : 'unpaid', 'attempt' => $attempt, 'order_canceled' => 'canceled' === $state['state'] );
		}
		$payment_id = $state['payment_id'];
		if ( ! empty( $attempt['provider_reference'] ) && $attempt['provider_reference'] !== $payment_id ) {
			return self::pending( $attempt, 'doughboss_square_hosted_review' );
		}
		$payment_read = DoughBoss_Square::online_checkout_request( 'GET', '/v2/payments/' . rawurlencode( $payment_id ), array(), $binding );
		$payment      = ! is_wp_error( $payment_read ) && isset( $payment_read['payment'] ) && is_array( $payment_read['payment'] ) ? $payment_read['payment'] : null;
		if ( ! $payment || ! isset( $payment['id'] ) || $payment_id !== $payment['id'] ) {
			return self::pending( $attempt, 'doughboss_pay_unavailable', __( 'We could not reach Square to confirm your payment yet. Please do not pay again; this page will keep checking.', 'doughboss' ) );
		}
		$provider_status = isset( $payment['status'] ) && is_string( $payment['status'] ) ? $payment['status'] : '';
		if ( in_array( $provider_status, array( 'APPROVED', 'PENDING' ), true ) ) {
			return array( 'state' => 'pending', 'attempt' => $attempt );
		}
		if ( in_array( $provider_status, array( 'FAILED', 'CANCELED' ), true ) && empty( $attempt['provider_reference'] ) ) {
			return array( 'state' => 'unpaid', 'attempt' => $attempt );
		}
		$projection = 'COMPLETED' === $provider_status ? DoughBoss_Square::attempt_projection( $attempt, $binding ) : null;
		$proof      = is_array( $projection ) ? self::paid_proof( $order_read, $payment, $projection, $order_id, (string) $binding['square_location_id'] ) : self::proof_error();
		if ( is_wp_error( $proof ) ) {
			if ( empty( $attempt['provider_reference'] ) && 'dispatching' === $status ) {
				// Money may have moved but does not match: quarantine for staff.
				DoughBoss_Payment_Attempts::bind_irreversible_reference( (int) $attempt['id'], $payment_id, 'mismatch', substr( $provider_status, 0, 32 ) );
			}
			$held = DoughBoss_Payment_Attempts::find( (int) $attempt['id'] );
			return self::pending( is_array( $held ) ? $held : $attempt, 'doughboss_pay_mismatch', __( 'Your Square payment needs staff review before the order is sent. Please do not pay again; the shop will contact you.', 'doughboss' ) );
		}
		if ( empty( $attempt['provider_reference'] ) ) {
			$bound = DoughBoss_Payment_Attempts::bind_irreversible_reference( (int) $attempt['id'], $payment_id, 'succeeded', 'COMPLETED' );
			if ( ! is_array( $bound ) ) {
				$bound = DoughBoss_Payment_Attempts::find( (int) $attempt['id'] );
				if ( ! is_array( $bound ) || $payment_id !== (string) $bound['provider_reference'] ) {
					return self::pending( $attempt, 'doughboss_pay_binding' );
				}
			}
		}
		$reconciled = DoughBoss_Payment_Attempts::reconcile_irreversible_reference( $payment_id, 'succeeded', 'COMPLETED' );
		if ( ! is_array( $reconciled ) || ! in_array( (string) $reconciled['status'], array( 'succeeded', 'order_committed' ), true )
			|| ( 'succeeded' === (string) $reconciled['status'] && empty( $reconciled['verified_at'] ) ) ) {
			return self::pending( $attempt, 'doughboss_pay_reconcile' );
		}
		return array( 'state' => 'paid', 'payment_id' => $payment_id, 'attempt' => $reconciled );
	}

	/**
	 * Retire an abandoned unpaid hosted checkout (delete link, close attempt,
	 * release the voucher). Never touches a tendered Order.
	 *
	 * @param array $attempt Hosted attempt.
	 * @return string retired|tendered|error
	 */
	public static function retire_unpaid( array $attempt ) {
		$attempt = DoughBoss_Payment_Attempts::find( isset( $attempt['id'] ) ? (int) $attempt['id'] : 0 );
		if ( ! is_array( $attempt ) || ! self::is_hosted_attempt( $attempt ) || 'dispatching' !== (string) $attempt['status'] || ! empty( $attempt['provider_reference'] ) ) {
			return 'error';
		}
		$metadata = DoughBoss_Payment_Attempts::metadata( $attempt );
		$order_id = isset( $attempt['provider_order_reference'] ) ? (string) $attempt['provider_order_reference'] : '';
		$link_id  = isset( $metadata['square_payment_link_id'] ) ? (string) $metadata['square_payment_link_id'] : '';
		if ( '' !== $order_id || '' !== $link_id ) {
			$binding = DoughBoss_Square::attempt_binding( $attempt );
			if ( is_wp_error( $binding ) ) {
				return 'error';
			}
			if ( '' !== $order_id ) {
				$before = self::order_payment_state( DoughBoss_Square::online_checkout_request( 'GET', '/v2/orders/' . rawurlencode( $order_id ), array(), $binding ), $order_id );
				if ( 'tendered' === $before['state'] ) {
					return 'tendered';
				}
				if ( 'invalid' === $before['state'] ) {
					return 'error';
				}
			}
			if ( '' !== $link_id && 1 === preg_match( '/^[A-Za-z0-9_-]{4,192}\z/', $link_id ) ) {
				$deleted = DoughBoss_Square::online_checkout_request( 'DELETE', '/v2/online-checkout/payment-links/' . rawurlencode( $link_id ), array(), $binding );
				$data    = is_wp_error( $deleted ) ? $deleted->get_error_data() : null;
				if ( is_wp_error( $deleted ) && ! ( is_array( $data ) && isset( $data['square_http'] ) && 404 === (int) $data['square_http'] ) ) {
					return 'error';
				}
			}
			if ( '' !== $order_id ) {
				$after = self::order_payment_state( DoughBoss_Square::online_checkout_request( 'GET', '/v2/orders/' . rawurlencode( $order_id ), array(), $binding ), $order_id );
				if ( 'tendered' === $after['state'] ) {
					return 'tendered';
				}
				// With the link deleted Square cancels the unpaid Order. Before a
				// link id was stored the URL was never handed out, so no buyer can pay.
				if ( 'canceled' !== $after['state'] && ! ( '' === $link_id && 'unpaid' === $after['state'] ) ) {
					return 'error';
				}
			}
		}
		if ( ! DoughBoss_Payment_Attempts::mark_irreversible_failed( (int) $attempt['id'], 'square_hosted_expired' ) ) {
			return 'error';
		}
		if ( ! empty( $metadata['voucher_code'] ) && ! empty( $metadata['voucher_reservation_key'] ) ) {
			$released = DoughBoss_Voucher::release_payment_reservation( (string) $metadata['voucher_code'], (string) $metadata['voucher_reservation_key'], (int) $attempt['id'] );
			if ( is_wp_error( $released ) ) {
				return 'error';
			}
		}
		return 'retired';
	}

	/**
	 * Hosted attempts old enough for the sweep.
	 *
	 * @return array[]
	 */
	public static function stale_attempts() {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::LINK_TTL + self::SWEEP_GRACE ) );
		$rows   = DoughBoss_Payment_Attempts::find_stale_hosted_square( $cutoff, 10 );
		return array_values( array_filter( $rows, array( __CLASS__, 'is_hosted_attempt' ) ) );
	}

	/**
	 * Find the hosted attempt a signed webhook event refers to. The payload
	 * values are lookup hints only; confirm() re-reads Square.
	 *
	 * @param string $resource  Event data.type.
	 * @param string $reference Event data.id.
	 * @param array  $data      Event data.
	 * @return array|null
	 */
	public static function attempt_for_event( $resource, $reference, array $data ) {
		$attempt = null;
		if ( 'payment' === $resource ) {
			$attempt = DoughBoss_Payment_Attempts::find_by_provider_reference( $reference );
			if ( ! self::is_hosted_attempt( $attempt ) ) {
				$hint    = isset( $data['object']['payment']['order_id'] ) && is_string( $data['object']['payment']['order_id'] ) ? $data['object']['payment']['order_id'] : '';
				$attempt = '' !== $hint ? DoughBoss_Payment_Attempts::find_by_provider_order_reference( $hint ) : null;
			}
		} elseif ( 'order_updated' === $resource || 'order_created' === $resource ) {
			$attempt = DoughBoss_Payment_Attempts::find_by_provider_order_reference( $reference );
		}
		return self::is_hosted_attempt( $attempt ) ? $attempt : null;
	}

	/* ------------------------------------------------------------------ */
	/* Errors                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Fail-closed payment-pending error (never permits a second payment).
	 *
	 * @param array|null $attempt Attempt.
	 * @param string     $code    Error code.
	 * @param string     $message Optional message.
	 * @return WP_Error
	 */
	private static function pending( $attempt, $code, $message = '' ) {
		$attempt = is_array( $attempt ) ? $attempt : array();
		return new WP_Error(
			sanitize_key( $code ) ? sanitize_key( $code ) : 'doughboss_pay_pending',
			'' !== $message ? $message : __( 'Your payment may already be complete. Please do not pay again — contact the shop so we can confirm it for you.', 'doughboss' ),
			array(
				'status'          => 409,
				'outcome'         => 'unknown',
				'payment_pending' => true,
				'retry_safe'      => false,
				'attempt_id'      => isset( $attempt['id'] ) ? (int) $attempt['id'] : 0,
			)
		);
	}

	/**
	 * The attempt is closed without any payment; a new attempt may start.
	 *
	 * @param array  $attempt Attempt.
	 * @param string $message Optional message.
	 * @return WP_Error
	 */
	private static function closed_error( array $attempt, $message = '' ) {
		return new WP_Error(
			'doughboss_square_hosted_closed',
			'' !== $message ? $message : __( 'That Square checkout has closed without payment. Please start payment again.', 'doughboss' ),
			array( 'status' => 409, 'outcome' => 'terminal_no_charge', 'payment_pending' => false, 'retry_safe' => true, 'attempt_id' => isset( $attempt['id'] ) ? (int) $attempt['id'] : 0 )
		);
	}

	/** @return WP_Error */
	private static function storage_error() {
		return new WP_Error( 'doughboss_pay_storage', __( 'The payment could not be recorded safely.', 'doughboss' ), array( 'status' => 503 ) );
	}

	/** @return WP_Error */
	private static function snapshot_error() {
		return new WP_Error( 'doughboss_pay_order_snapshot', __( 'The saved itemized order could not be verified. Please contact the shop.', 'doughboss' ), array( 'status' => 409 ) );
	}

	/** @return WP_Error */
	private static function shape_error() {
		return new WP_Error( 'doughboss_square_hosted_shape', __( 'The secure Square checkout could not be prepared.', 'doughboss' ), array( 'status' => 502 ) );
	}

	/** @return WP_Error */
	private static function proof_error() {
		return new WP_Error( 'doughboss_square_hosted_unproven', __( 'The Square payment could not be proven for this order.', 'doughboss' ), array( 'status' => 409 ) );
	}
}
