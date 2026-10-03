<?php
/**
 * DoughBoss Growth waitlist REST routes (namespace doughboss-growth/v1).
 *
 *   GET  /form-token         public, no-store: an HMAC form token (a minimum-fill-time check that a cached page cannot break)
 *   POST /waitlist           public: token + honeypot + rate limits + validation, then a double opt-in email
 *   POST /waitlist/confirm   public: id + token from the email link (a button posts it, so a mail scanner cannot confirm)
 *   POST /waitlist/unsubscribe  public: id + token from any message (also works while the waitlist flag is off)
 *
 * Every route answers with Cache-Control: no-store. Every failure fails closed: a storage problem is a 503 and nothing
 * is saved. Confirm and unsubscribe accept POST only.
 *
 * Leaving the list is never held back by the per-address limit: the opt-out token is verified first and only an INVALID
 * attempt counts against the bucket (DoughBoss_Growth_Waitlist::unsubscribe_attempt()). Every failure is also noted in the
 * owner failure list (codes and stages only); the visitor is told the same neutral thing whatever the cause.
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Waitlist REST controller.
 */
final class DoughBoss_Growth_Waitlist_Rest {

	/**
	 * Hook the route registration. Called by the waitlist module's init() when the waitlist is on.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the unsubscribe route even while the waitlist is off (a person must always be able to leave).
	 * Called by the waitlist module's init() in every case.
	 *
	 * @return void
	 */
	public static function register_unsubscribe_only() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_unsubscribe_route' ) );
	}

	/**
	 * Register all four routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = DoughBoss_Growth::REST_NAMESPACE;
		register_rest_route(
			$ns,
			'/form-token',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_form_token' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$ns,
			'/waitlist',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_signup' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$ns,
			'/waitlist/confirm',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_confirm' ),
				'permission_callback' => '__return_true',
			)
		);
		self::register_unsubscribe_route();
	}

	/**
	 * Register the unsubscribe route.
	 *
	 * @return void
	 */
	public static function register_unsubscribe_route() {
		register_rest_route(
			DoughBoss_Growth::REST_NAMESPACE,
			'/waitlist/unsubscribe',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_unsubscribe' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * A response that is never cached.
	 *
	 * @param array $data    Body.
	 * @param int   $status  HTTP status.
	 * @param int   $retry   Retry-After seconds (0 = none).
	 * @return WP_REST_Response
	 */
	private static function respond( array $data, $status, $retry = 0 ) {
		$response = new WP_REST_Response( $data, (int) $status );
		$response->header( 'Cache-Control', 'no-store' );
		if ( $retry > 0 ) {
			$response->header( 'Retry-After', (string) (int) $retry );
		}
		return $response;
	}

	/**
	 * An error body.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Neutral message.
	 * @param int    $status  HTTP status.
	 * @param string $field   Field the message is about (optional).
	 * @param int    $retry   Retry-After seconds.
	 * @return WP_REST_Response
	 */
	private static function fail( $code, $message, $status, $field = '', $retry = 0 ) {
		$body = array(
			'success' => false,
			'code'    => $code,
			'message' => $message,
		);
		if ( '' !== $field ) {
			$body['field'] = $field;
		}
		return self::respond( $body, $status, $retry );
	}

	/**
	 * GET /form-token.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_form_token() {
		if ( ! DoughBoss_Growth_Waitlist::enabled() ) {
			return self::fail( 'dbgr_unavailable', __( 'Sign-ups are not available right now.', 'doughboss-growth' ), 503 );
		}
		return self::respond( array( 'token' => DoughBoss_Growth_Waitlist::issue_form_token() ), 200 );
	}

	/**
	 * The message every successful (and every look-alike) sign-up gets. It is the same for a new address, a
	 * pending one, an already confirmed one, an opted-out one and the honeypot, so it reveals nothing.
	 *
	 * @return string
	 */
	public static function neutral_message() {
		return __( 'Thanks. If this email address can join the list, we have sent a message to confirm it. Please check your inbox, and your spam folder.', 'doughboss-growth' );
	}

	/**
	 * Read a request parameter as a string (arrays and objects become empty so they cannot be smuggled in).
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $name    Parameter.
	 * @return mixed String, int or bool as sent; null when absent or structured.
	 */
	private static function param( $request, $name ) {
		$value = $request->get_param( $name );
		return ( is_scalar( $value ) ) ? $value : null;
	}

	/**
	 * POST /waitlist.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_signup( $request ) {
		if ( ! DoughBoss_Growth_Waitlist::enabled() ) {
			return self::fail( 'dbgr_unavailable', __( 'Sign-ups are not available right now.', 'doughboss-growth' ), 503 );
		}

		// 1. Honeypot: a filled "website" field is a bot. Answer exactly like a success, store nothing, count nothing.
		$trap = self::param( $request, 'website' );
		if ( null !== $trap && '' !== trim( (string) $trap ) ) {
			return self::respond(
				array(
					'success' => true,
					'message' => self::neutral_message(),
				),
				200
			);
		}

		// 2. Form token: signed, not too young (3 s), not too old (24 h).
		$state = DoughBoss_Growth_Waitlist::check_form_token( self::param( $request, 'token' ) );
		if ( 'ok' !== $state ) {
			if ( 'early' === $state ) {
				return self::fail( 'dbgr_token_early', __( 'Please wait a moment and press the button again.', 'doughboss-growth' ), 400 );
			}
			return self::fail( 'dbgr_token_invalid', __( 'This form has expired. Please reload the page and try again.', 'doughboss-growth' ), 400 );
		}

		// 3. Limits that need no input: per hashed address and the daily circuit breaker.
		$limit = DoughBoss_Growth_Waitlist::limit_visitor();
		if ( ! $limit['allowed'] ) {
			return self::limit_failure( $limit, 'signup' );
		}

		// 4. Validation.
		$check = DoughBoss_Growth_Waitlist::validate(
			array(
				'email'           => self::param( $request, 'email' ),
				'first_name'      => self::param( $request, 'first_name' ),
				'mobile'          => self::param( $request, 'mobile' ),
				'store'           => self::param( $request, 'store' ),
				'consent'         => self::param( $request, 'consent' ),
				'consent_version' => self::param( $request, 'consent_version' ),
				'path'            => self::param( $request, 'path' ),
			)
		);
		if ( ! $check['ok'] ) {
			$errors = $check['errors'];
			if ( isset( $errors['consent_version'] ) ) {
				return self::fail( 'dbgr_consent_changed', __( 'The wording on this form has changed. Please reload the page and read it again.', 'doughboss-growth' ), 400, 'consent' );
			}
			if ( isset( $errors['consent'] ) ) {
				return self::fail( 'dbgr_consent_required', __( 'Please tick the box to join the list.', 'doughboss-growth' ), 400, 'consent' );
			}
			if ( isset( $errors['email'] ) ) {
				return self::fail( 'dbgr_invalid_email', __( 'Please enter a valid email address.', 'doughboss-growth' ), 400, 'email' );
			}
			if ( isset( $errors['mobile'] ) ) {
				return self::fail( 'dbgr_invalid_mobile', __( 'Please enter an Australian mobile number, or leave it blank.', 'doughboss-growth' ), 400, 'mobile' );
			}
			return self::fail( 'dbgr_invalid', __( 'Please check your details and try again.', 'doughboss-growth' ), 400 );
		}

		// 5. Per-email limit (3 a day), after validation so junk never uses an address's quota.
		$email_limit = DoughBoss_Growth_Waitlist::limit_email( DoughBoss_Growth_Waitlist::email_hash( $check['clean']['email'] ) );
		if ( ! $email_limit['allowed'] ) {
			return self::limit_failure( $email_limit, 'signup' );
		}

		// 6. Store and send. Only a storage or mail failure is told apart from a normal answer.
		// Storage, mail and token errors are told apart for the owner (the failure list) but NOT for the visitor: one neutral
		// answer, so the response cannot reveal whether an address is new (only a new or pending address sends mail).
		$result = DoughBoss_Growth_Waitlist::signup( $check['clean'] );
		if ( 0 === strpos( (string) $result['result'], 'error' ) ) {
			return self::fail( 'dbgr_unavailable', __( 'We could not save your details just now. Please try again later.', 'doughboss-growth' ), 503 );
		}
		return self::respond(
			array(
				'success' => true,
				'message' => self::neutral_message(),
			),
			200
		);
	}

	/**
	 * The answer for a refused rate-limit result. A plain "too many attempts" is the visitor's doing; a limiter that could
	 * not count (storage error) is a failure the owner should see, so it is noted (route and reason only).
	 *
	 * @param array  $limit Result of the limiter.
	 * @param string $route Which route asked (signup, confirm).
	 * @return WP_REST_Response
	 */
	private static function limit_failure( array $limit, $route = '' ) {
		if ( 'limited' === $limit['reason'] ) {
			return self::fail( 'dbgr_rate_limited', __( 'Too many attempts. Please try again later.', 'doughboss-growth' ), 429, '', (int) $limit['retry_after'] );
		}
		// Storage error or invalid arguments: fail closed, nothing is saved.
		DoughBoss_Growth_Waitlist::note_failure(
			DoughBoss_Growth_Waitlist::FAIL_LIMITER,
			array(
				'route'  => $route,
				'reason' => isset( $limit['reason'] ) ? $limit['reason'] : 'unknown',
			)
		);
		return self::fail( 'dbgr_unavailable', __( 'We could not save your details just now. Please try again later.', 'doughboss-growth' ), 503 );
	}

	/**
	 * POST /waitlist/confirm (id and token from the email link).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_confirm( $request ) {
		if ( ! DoughBoss_Growth_Waitlist::enabled() ) {
			return self::fail( 'dbgr_unavailable', __( 'Sign-ups are not available right now.', 'doughboss-growth' ), 503 );
		}
		$limit = DoughBoss_Growth_Waitlist::limit_action();
		if ( ! $limit['allowed'] ) {
			return self::limit_failure( $limit, 'confirm' );
		}
		$result = DoughBoss_Growth_Waitlist::confirm( self::param( $request, 'id' ), self::param( $request, 'token' ) );
		if ( 'confirmed' === $result['result'] ) {
			return self::respond(
				array(
					'success' => true,
					'message' => __( 'You are on the VIP list. Thank you.', 'doughboss-growth' ),
				),
				200
			);
		}
		if ( 'invalid' === $result['result'] ) {
			return self::fail( 'dbgr_link_invalid', __( 'This link is not valid or has already been used.', 'doughboss-growth' ), 400 );
		}
		return self::fail( 'dbgr_unavailable', __( 'We could not process this just now. Please try again later.', 'doughboss-growth' ), 503 );
	}

	/**
	 * POST /waitlist/unsubscribe (id and token from any message). Works whatever the waitlist flag says.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_unsubscribe( $request ) {
		// The token is verified first and only an invalid attempt counts against the per-address limit (a valid one is
		// never refused for it, and a limiter storage error cannot block it). 200 only when the opt-out really happened.
		$result = DoughBoss_Growth_Waitlist::unsubscribe_attempt( self::param( $request, 'id' ), self::param( $request, 'token' ) );
		if ( 'unsubscribed' === $result['result'] ) {
			return self::respond(
				array(
					'success' => true,
					'message' => __( 'You have been unsubscribed. You will not receive any more messages from us.', 'doughboss-growth' ),
				),
				200
			);
		}
		if ( 'invalid' === $result['result'] ) {
			return self::fail( 'dbgr_link_invalid', __( 'This link is not valid or has already been used.', 'doughboss-growth' ), 400 );
		}
		if ( 'limited' === $result['result'] ) {
			return self::fail( 'dbgr_rate_limited', __( 'Too many attempts. Please try again later.', 'doughboss-growth' ), 429, '', (int) $result['retry_after'] );
		}
		return self::fail( 'dbgr_unavailable', __( 'We could not process this just now. Please try again later.', 'doughboss-growth' ), 503 );
	}
}
