<?php
/**
 * Settings access helpers.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around the doughboss_settings option.
 *
 * Centralises reads so the rest of the plugin never has to know the option
 * shape, and provides typed getters with sane fallbacks.
 */
class DoughBoss_Settings {

	const OPTION_KEY = 'doughboss_settings';

	/**
	 * Return the full settings array merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Get a single setting by key.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is absent.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Merge a partial array of settings into the stored option (preserving the
	 * keys not supplied) and persist. Used by programmatic config writers such
	 * as the POSPal connect endpoint.
	 *
	 * @param array $partial Keys to set/overwrite.
	 * @return array The merged settings now stored.
	 */
	public static function update( array $partial ) {
		$current = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$merged = array_merge( $current, $partial );
		update_option( self::OPTION_KEY, $merged );
		return $merged;
	}

	/**
	 * Default settings used when nothing is stored yet.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'currency_symbol' => '$',
			'currency_code'   => 'AUD',
			'tax_rate'        => 10,
			'gst_inclusive'   => 1,
			'delivery_fee'    => 0,
			'enable_pickup'   => 1,
			'enable_delivery' => 0,
			// Fresh installs launch in browse-only mode. The owner must explicitly
			// open ordering after the WordPress staging checklist has passed.
			'ordering_open'   => 0,
			'ordering_closed_message' => 'Online ordering is coming soon. You can browse the menu now, and we will let you know when checkout opens.',
			// A deliberately separate, unpaid fallback for the Revesby launch.
			// It captures a customer request while normal checkout is closed; it
			// does not promise a time, reserve capacity, create a payment attempt,
			// or enter the kitchen queue until a staff member accepts it.
			'after_hours_preorders_enabled' => 0,
			'after_hours_preorders_message' => 'Thanks! We have received your pre-order request. It is not confirmed or paid. Revesby will review it first thing in the morning and contact you to confirm.',
			// Single-shop mode: the storefront JS (getConfig/getLocations in
			// public/js/doughboss.js) hides the delivery toggle and pins the shop
			// picker to the first active location while this is 1. Display-only â€”
			// the checkout endpoint's enable_delivery gate is the server-side
			// enforcement. Seeded to 1 by the 1.10.0 migration ("Revesby-only
			// pickup for now"). Flipping to 0 restores the multi-shop picker;
			// delivery additionally needs enable_delivery back on.
			'single_location_mode' => 1,
			// Shop inbox for ordinary online-order notifications. Catering uses its
			// own dedicated inbox below. Blank falls back to the WordPress admin email.
			'orders_email'    => 'orders@doughboss.com.au',
			// Dedicated customer-facing catering contacts. Catering enquiries use
			// this inbox rather than mixing with normal online-order notices.
			'catering_email'  => 'catering@doughboss.com.au',
			'catering_phone'  => '0422487487',
			// Public WordPress page containing [doughboss_order_tracking].
			// Blank is safe: emails still include the order number and matching-
			// email instructions, but no potentially broken tracking link.
			'tracking_page_url' => '',
			// Public Google Business review destination. The Maps listing is a safe
			// fallback until the owner pastes the exact "Ask for reviews" short link.
			'google_review_url' => 'https://www.google.com/maps/search/?api=1&query=Dough+Boss+12+25+Selems+Parade+Revesby+NSW+2212',
			// Keep logged-in sessions for this many days (0 = WordPress default).
			// Set high (e.g. 3650) so shop tablets stay signed in; off by default.
			'staff_session_days' => 0,
			// Kitchen Order Board â€” optional extra access-key layer. Blank (default)
			// means the board is reachable at the normal wp-admin URL, gated only by
			// login + the manage_doughboss_kds capability (the real security
			// boundary). When set, render_board_page() ALSO requires a matching
			// ?key= query arg â€” a memorable, bookmarkable "specific URL" for
			// kitchen staff, layered on top of (never instead of) the WP login +
			// capability check. Only ever written by the random generator in
			// DoughBoss_Admin::generate_board_key() (admin-post actions
			// doughboss_generate_board_key / doughboss_clear_board_key) â€” never
			// accepted as free text. New keys are stored only as a SHA-256 hex
			// digest; the raw value exists only in the one-time owner reveal and
			// the staff URL. Legacy plaintext values from before hashing still
			// verify until regenerated. See verify_board_access_key() below and
			// admin/class-doughboss-admin.php render_board_page().
			'board_access_key' => '',
			// Rate-limiter client-IP resolution. Off by default so REMOTE_ADDR is used
			// verbatim (zero behaviour change). Only enable 'behind_reverse_proxy' when
			// the site sits behind a reverse proxy/CDN/load balancer that you have
			// confirmed strips or overwrites any client-supplied forwarded header
			// before appending its own â€” otherwise a caller could spoof the header and
			// evade the checkout/voucher rate limiter. 'trusted_proxy_header' names the
			// header the proxy sets (its first comma-separated entry is the client IP).
			// See DoughBoss_REST_Controller::client_ip().
			'behind_reverse_proxy' => 0,
			'trusted_proxy_header' => 'X-Forwarded-For',
			'sizes'           => array(),
			'toppings'        => array(),
			// Payments â€” off by default; keys added later. 'payment_gateway' picks
			// which of the two clients below (DoughBoss_Stripe / DoughBoss_Tyro)
			// DoughBoss_Payment routes to; default 'stripe' preserves the exact
			// pre-existing behaviour on every site that never touches this setting.
			'payments_enabled' => 0,
			'payment_gateway'  => 'stripe',
			'stripe_mode'      => 'test',
			'stripe_test_pk'   => '',
			'stripe_test_sk'   => '',
			'stripe_live_pk'   => '',
			'stripe_live_sk'   => '',
			'stripe_test_whsec' => '',
			'stripe_live_whsec' => '',
			// Tyro Connect Pay. Secrets are read env-first and each shop also needs
			// its own Tyro Connect locationId on the location record.
			'tyro_mode'                => 'test',
			'tyro_test_client_id'       => '',
			'tyro_live_client_id'       => '',
			'tyro_test_client_secret'   => '',
			'tyro_live_client_secret'   => '',
			'tyro_test_webhook_secret' => '',
			'tyro_live_webhook_secret' => '',
			'tyro_live_certified'       => 0,
			// Mastercard Payment Gateway Services (MPGS) Hosted Checkout. This is
			// deliberately separate from Tyro Connect: MPGS authenticates with a
			// merchant ID + API password and redirects card entry to Mastercard's
			// hosted page. The API password is env-first and never exposed to JS.
			'mpgs_mode'              => 'test',
			'mpgs_test_merchant_id'  => '',
			'mpgs_live_merchant_id'  => '',
			'mpgs_test_api_password' => '',
			'mpgs_live_api_password' => '',
			'mpgs_test_host'         => 'https://test-tyro.mtf.gateway.mastercard.com',
			'mpgs_live_host'         => '',
			'mpgs_api_version'       => 100,
			'mpgs_live_approved'     => 0,
			// POSPal POS (Open Platform) â€” off by default; Revesby store for the pilot.
			// The secret appKey is read env-first (DOUGHBOSS_POSPAL_APPKEY constant/env);
			// this option is only a fallback and is best left blank where env is set.
			'pospal_enabled'    => 0,
			'pospal_host'       => '',
			'pospal_app_id'     => '',
			'pospal_app_key'    => '',
			// POSPal coupon-rule mapping: which POSPal coupon (ä¼˜æƒ åˆ¸) rule UID
			// represents the $5 student voucher. Blank = grant disabled (the GRANT
			// leg is dormant until this is set). The $10 tier has been retired.
			'pospal_coupon_uid_5'  => '',
			// Additional POSPal stores (multi-store). Store 2 + store 3 each carry their
			// own host / App ID / App Key (env-first DOUGHBOSS_POSPAL_APPKEY_2/_3) and
			// $5 coupon-rule UID. Blank = that store is skipped; store 1 is the
			// legacy single-store fields above.
			'pospal2_label'         => '',
			'pospal2_host'          => '',
			'pospal2_app_id'        => '',
			'pospal2_app_key'       => '',
			'pospal2_coupon_uid_5'  => '',
			'pospal3_label'         => '',
			'pospal3_host'          => '',
			'pospal3_app_id'        => '',
			'pospal3_app_key'       => '',
			'pospal3_coupon_uid_5'  => '',
			// POSPal order push (mirror online orders onto the till) â€” off by default.
			// Orders only push once a product map is built (pospal_product_map, via
			// `wp doughboss pospal-map`). pay_method/pay_online describe how a Stripe-
			// paid order is represented on the POS.
			'pospal_push_orders'          => 0,
			'pospal_order_pay_method'     => 'Cash',
			'pospal_order_pay_method_code' => '',
			'pospal_order_pay_online'     => 0,
			'pospal_product_map'          => array(),
			// Standalone staff console (separate origin, e.g. GitHub Pages) allowed
			// to call the doughboss/v1 routes cross-origin via Application Password.
			'app_origin'        => 'https://edagher92-coder.github.io',
			// Real-time push (Mercure hub) â€” off by default. The publish JWT is a
			// secret, read env-first (DOUGHBOSS_MERCURE_PUBLISH_JWT); this option is
			// only a fallback and is best left blank where env is set.
			'mercure_enabled'       => 0,
			'mercure_hub_url'       => '',
			'mercure_publish_jwt'   => '',
			'mercure_subscribe_jwt' => '',
			'mercure_topic_prefix'  => 'doughboss',
			// ntfy push notifications â€” off by default. The bearer token is a secret,
			// read env-first (DOUGHBOSS_NTFY_TOKEN); this option is only a fallback.
			'ntfy_enabled'  => 0,
			'ntfy_server'   => 'https://ntfy.sh',
			'ntfy_topic'    => '',
			'ntfy_token'    => '',
			'ntfy_priority' => 'high',
			// SMS (ClickSend) â€” off by default. The API key is a secret, read
			// env-first (DOUGHBOSS_CLICKSEND_API_KEY); this option is only a fallback.
			'sms_enabled'           => 0,
			'clicksend_username'    => '',
			'clicksend_api_key'     => '',
			'sms_from'              => '',
			'sms_on_ready'          => 1,
			'sms_on_voucher_claim'  => 0,
			// Customer stage-transition emails â€” sent via native wp_mail, so no
			// external configuration is needed; the toggles are the whole gate.
			'email_on_accepted' => 1,
			'email_on_ready'    => 1,
			'email_staff_copy'  => 0,
			// Receipt printer (CloudPRNT / ePOS) â€” off by default. The shared token
			// is a secret, read env-first (DOUGHBOSS_PRINTER_TOKEN); this option is
			// only a fallback.
			'printer_enabled'  => 0,
			'printer_protocol' => 'cloudprnt',
			'printer_token'    => '',
			'printer_width'    => 48,
			// Customer-facing message templates â€” owner-editable copy for the
			// order-confirmation email and the two SMS messages. Blank means
			// "use the built-in default text" (see the tpl_*() getters below),
			// so leaving a field blank restores the default rather than sending
			// an empty message.
			'tpl_order_email_subject' => '',
			'tpl_order_email_body'    => '',
			'tpl_sms_ready'           => '',
			'tpl_sms_voucher'         => '',
			'tpl_accepted_email_subject' => '',
			'tpl_accepted_email_body'    => '',
			'tpl_ready_email_subject'    => '',
			'tpl_ready_email_body'       => '',
		);
	}

	/**
	 * Allowed origin for the standalone staff console (CORS). Empty disables
	 * cross-origin access. Filterable via 'doughboss_app_origin'.
	 *
	 * @return string
	 */
	public static function app_origin() {
		return untrailingslashit( (string) apply_filters( 'doughboss_app_origin', self::get( 'app_origin', '' ) ) );
	}

	/**
	 * Optional extra access-key verifier for the Order Board. Blank (default)
	 * means the board relies solely on WP login + the manage_doughboss_kds
	 * capability. When set, render_board_page() requires a matching ?key=
	 * query argument in addition to that login + capability check â€” a
	 * bookmarkable "specific URL" for kitchen staff, layered on top of the
	 * real auth boundary and enforced again on KDS REST calls. New values are
	 * SHA-256 verifiers rather than recoverable plaintext.
	 *
	 * @return string
	 */
	public static function board_access_key() {
		return trim( (string) self::get( 'board_access_key', '' ) );
	}

	/**
	 * Verify a presented Order Board key against the stored verifier.
	 *
	 * New keys are stored as SHA-256 verifiers so database/config backups cannot
	 * reveal the bookmark secret. A 24-character legacy plaintext value is still
	 * accepted for a safe upgrade path and is replaced the next time the owner
	 * generates a key.
	 *
	 * @param string $supplied Raw key supplied by the staff client.
	 * @return bool
	 */
	public static function verify_board_access_key( $supplied ) {
		$stored   = self::board_access_key();
		$supplied = trim( (string) $supplied );
		if ( '' === $stored ) {
			return true;
		}
		if ( '' === $supplied ) {
			return false;
		}
		if ( 64 === strlen( $stored ) && ctype_xdigit( $stored ) ) {
			return hash_equals( strtolower( $stored ), hash( 'sha256', $supplied ) );
		}
		return hash_equals( $stored, $supplied );
	}

	/**
	 * Whether prices already include tax (GST-inclusive, the Australian norm).
	 *
	 * @return bool
	 */
	public static function gst_inclusive() {
		return (bool) self::get( 'gst_inclusive', 1 );
	}

	/**
	 * Email address that order + catering notifications are sent to (the shop inbox).
	 * Falls back to the WordPress admin email when unset or invalid. Filterable via
	 * 'doughboss_orders_email'.
	 *
	 * @return string
	 */
	public static function orders_email() {
		$email = sanitize_email( (string) self::get( 'orders_email', '' ) );
		if ( ! is_email( $email ) ) {
			$email = (string) get_option( 'admin_email' );
		}
		return (string) apply_filters( 'doughboss_orders_email', $email );
	}

	/**
	 * Customer-facing catering inbox and notification destination.
	 *
	 * @return string
	 */
	public static function catering_email() {
		$email = sanitize_email( (string) self::get( 'catering_email', 'catering@doughboss.com.au' ) );
		return is_email( $email ) ? $email : 'catering@doughboss.com.au';
	}

	/**
	 * Customer-facing Australian mobile number, stored as digits only.
	 *
	 * @return string
	 */
	public static function catering_phone() {
		$phone = preg_replace( '/[^0-9+]/', '', (strinïŽ<¶‰žËkºwµç@ôø€¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €Á½ÍÁ…°œ€¸€‘¸€¸€}±…‰•°œ°€œœ€¤°($$$$¡½ÍÐœ€€€€ôøÕ¹ÑÉ…¥±¥¹Í±…Í¡¥Ð €¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €Á½ÍÁ…°œ€¸€‘¸€¸€}¡½ÍÐœ°€œœ€¤€¤°($$$$…ÁÁ}¥œ€€ôø€¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €Á½ÍÁ…°œ€¸€‘¸€¸€}…ÁÁ}¥œ°€œœ€¤°($$$$…ÁÁ}­•äœ€ôøÍ•±˜èéÁ½ÍÁ…±}ÍÑ½É•}­•ä €‘¸€¤°($$$$Õ¥Ôœ€€€€ôø€¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €Á½ÍÁ…°œ€¸€‘¸€¸€}½ÕÁ½¹}Õ¥‘|Ôœ°€œœ€¤°($$$$¼¨ÑÉ…¹Í±…Ñ½ÉÌè€•èÍÑ½É”¹Õµ‰•È¸€¨¼($$$$‘•™…Õ±Ðœ€ôøÍÁÉ¥¹Ñ˜ }| €MÑ½É”€•œ°€‘½Õ¡‰½ÍÌœ€¤°€‘¸€¤°($$$¤ì($%ô(($$‘ÍÑ½É•Ì€ô…ÉÉ…ä ¤ì($%™½É•… € €‘É…Ü…Ì€‘Ì€¤ì($$%¥˜€ €œœ€ôôô€‘Íl¡½ÍÐtñð€œœ€ôôô€‘Íl…ÁÁ}¥tñð€œœ€ôôô€‘Íl…ÁÁ}­•ät€¤ì($$$%½¹Ñ¥¹Õ”ì€¼¼M­¥À¥¹½µÁ±•Ñ•±äµ½¹™¥ÕÉ•ÍÑ½É•Ì¸($$%ô($$$‘Íl±…‰•°t€ô€œœ€„ôô€‘Íl±…‰•°t€ü€‘Íl±…‰•°t€è€‘Íl‘•™…Õ±Ðtì($$%Õ¹Í•Ð €‘Íl‘•™…Õ±Ðt€¤ì($$$‘ÍÑ½É•Ímt€ô€‘Ìì($%ô($%É•ÑÕÉ¸€‘ÍÑ½É•Ìì(%ô(($¼¨¨($€¨Í¥¹±”A=MA…°ÍÑ½É”Ì½¹™¥œ‰ä¹Õµ‰•È€ Ä€ô±•…ä½ÁÉ¥µ…Éä°€È°€Ì¤É•…É‘±•ÍÌ($€¨½˜Ý¡•Ñ¡•È¥Ð¥Ì™Õ±±ä½¹™¥ÕÉ•ƒŠPÕÍ•‰äÑ¡”Á•ÈµÍÑ½É”…‘µ¥¸Y•É¥™ä½Q•ÍÐ($€¨Ñ½½±ÌÍ¼…¸¥¹½µÁ±•Ñ”ÍÑ½É”É•Á½ÉÑÌ±•…É±ä¥¹ÍÑ•…½˜™…±±¥¹œ‰…¬Í¥±•¹Ñ±ä¸($€¨($€¨Á…É…´¥¹Ð€‘¸MÑ½É”¹Õµ‰•È¸($€¨É•ÑÕÉ¸…ÉÉ…äì±…‰•°°¡½ÍÐ°…ÁÁ}¥°…ÁÁ}­•ä°Õ¥Ôô¸($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸Á½ÍÁ…±}ÍÑ½É” €‘¸€¤ì($$‘¸€ôµ…à €Ä°€¡¥¹Ð¤€‘¸€¤ì($%¥˜€ €Ä€ôôô€‘¸€¤ì($$$‘±…‰•°Ä€ô€¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €Á½ÍÁ…±}±…‰•°œ°€œœ€¤ì($$%É•ÑÕÉ¸…ÉÉ…ä ($$$$±…‰•°œ€€€ôø€œœ€„ôô€‘±…‰•°Ä€ü€‘±…‰•°Ä€è}| €MÑ½É”€Äœ°€‘½Õ¡‰½ÍÌœ€¤°($$$$¡½ÍÐœ€€€€ôøÍ•±˜èéÁ½ÍÁ…±}¡½ÍÐ ¤°($$$$…ÁÁ}¥œ€€ôøÍ•±˜èéÁ½ÍÁ…±}…ÁÁ}¥ ¤°($$$$…ÁÁ}­•äœ€ôøÍ•±˜èéÁ½ÍÁ…±}…ÁÁ}­•ä ¤°($$$$Õ¥Ôœ€€€€ôøÍ•±˜èéÁ½ÍÁ…±}½ÕÁ½¹}Õ¥‘|Ô ¤°($$$¤ì($%ô($$‘±…‰•°€ô€¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €Á½ÍÁ…°œ€¸€‘¸€¸€}±…‰•°œ°€œœ€¤ì($%É•ÑÕÉ¸…ÉÉ…ä ($$$¼¨ÑÉ…¹Í±…Ñ½ÉÌè€•èÍÑ½É”¹Õµ‰•È¸€¨¼($$$±…‰•°œ€€€ôø€œœ€„ôô€‘±…‰•°€ü€‘±…‰•°€èÍÁÉ¥¹Ñ˜ }| €MÑ½É”€•œ°€‘½Õ¡‰½ÍÌœ€¤°€‘¸€¤°($$$¡½ÍÐœ€€€€ôøÕ¹ÑÉ…¥±¥¹Í±…Í¡¥Ð €¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €Á½ÍÁ…°œ€¸€‘¸€¸€}¡½ÍÐœ°€œœ€¤€¤°($$$…ÁÁ}¥œ€€ôø€¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €Á½ÍÁ…°œ€¸€‘¸€¸€}…ÁÁ}¥œ°€œœ€¤°($$$…ÁÁ}­•äœ€ôøÍ•±˜èéÁ½ÍÁ…±}ÍÑ½É•}­•ä €‘¸€¤°($$$Õ¥Ôœ€€€€ôø€¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €Á½ÍÁ…°œ€¸€‘¸€¸€}½ÕÁ½¹}Õ¥‘|Ôœ°€œœ€¤°($$¤ì(%ô(($¼¨¨($€¨]¡•Ñ¡•ÈÑ¡”5•ÉÕÉ”É•…°µÑ¥µ”ÁÕÍ ¥¹Ñ•É…Ñ¥½¸¥ÌÍÝ¥Ñ¡•½¸‰äÑ¡”½Á•É…Ñ½È¸($€¨($€¨É•ÑÕÉ¸‰½½°($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸µ•ÉÕÉ•}•¹…‰±• ¤ì($%É•ÑÕÉ¸€¡‰½½°¤Í•±˜èé•Ð €µ•ÉÕÉ•}•¹…‰±•œ°€À€¤ì(%ô(($¼¨¨($€¨5•ÉÕÉ”¡ÕˆUI0°ÑÉ…¥±¥¹œÍ±…Í É•µ½Ù•€¡”¹œ¸¡ÑÑÁÌè¼½¡Õˆ¹•á…µÁ±”¹½´¼¹Ý•±°µ­¹½Ý¸½µ•ÉÕÉ”¤¸($€¨($€¨É•ÑÕÉ¸ÍÑÉ¥¹œ($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸µ•ÉÕÉ•}¡Õ‰}ÕÉ° ¤ì($%É•ÑÕÉ¸Õ¹ÑÉ…¥±¥¹Í±…Í¡¥Ð €¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €µ•ÉÕÉ•}¡Õ‰}ÕÉ°œ°€œœ€¤€¤ì(%ô(($¼¨¨($€¨5•ÉÕÉ”ÁÕ‰±¥Í¡•È)]P¸I•…•¹Øµ™¥ÉÍÐƒŠPÑ¡”½¹ÍÑ…¹Ð($€¨=U!	=MM}5IUI}AU	1%M!})]P½ÈÑ¡”µ…Ñ¡¥¹œ•¹Ù¥É½¹µ•¹ÐÙ…É¥…‰±”Ñ…­”($€¨ÁÉ••‘•¹”½Ù•ÈÑ¡”ÍÑ½É•½ÁÑ¥½¸°Í¼Ñ¡”Í•É•Ð…¸‰”­•ÁÐ½ÕÐ½˜Ñ¡”($€¨‘…Ñ…‰…Í”€¡…¹Ñ¡•É•™½É”½ÕÐ½˜‰…­ÕÁÌ¤¸=¹±ä•Ù•ÈÕÍ•Í•ÉÙ•ÈµÍ¥‘”Ñ¼($€¨…ÕÑ¡•¹Ñ¥…Ñ”ÁÕ‰±¥Í¡•ÌÑ¼Ñ¡”¡Õˆì¹•Ù•È•¡½•Ñ¼„±¥•¹Ð¸($€¨($€¨É•ÑÕÉ¸ÍÑÉ¥¹œ($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸µ•ÉÕÉ•}ÁÕ‰±¥Í¡}©ÝÐ ¤ì($%¥˜€ ‘•™¥¹• €=U!	=MM}5IUI}AU	1%M!})]Pœ€¤€˜˜€œœ€„ôô€¡ÍÑÉ¥¹œ¤=U!	=MM}5IUI}AU	1%M!})]P€¤ì($$%É•ÑÕÉ¸€¡ÍÑÉ¥¹œ¤=U!	=MM}5IUI}AU	1%M!})]Pì($%ô($$‘•¹Ø€ô•Ñ•¹Ø €=U!	=MM}5IUI}AU	1%M!})]Pœ€¤ì($%¥˜€ ™…±Í”€„ôô€‘•¹Ø€˜˜€œœ€„ôô€‘•¹Ø€¤ì($$%É•ÑÕÉ¸€¡ÍÑÉ¥¹œ¤€‘•¹Øì($%ô($%É•ÑÕÉ¸€¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €µ•ÉÕÉ•}ÁÕ‰±¥Í¡}©ÝÐœ°€œœ€¤ì(%ô(($¼¨¨($€¨5•ÉÕÉ”ÍÕ‰ÍÉ¥‰•È)]P°¡…¹‘•Ñ¼‰É½ÝÍ•È±¥•¹ÑÌÍ¼Ñ¡•äµ…äÍÕ‰ÍÉ¥‰”Ñ¼($€¨Ñ½Á¥Ì¸9½Ð„ÁÕ‰±¥Í É•‘•¹Ñ¥…°°Í¼¥Ð¥ÌÉ•…™É½´Ñ¡”ÍÑ½É•½ÁÑ¥½¸¸($€¨($€¨É•ÑÕÉ¸ÍÑÉ¥¹œ($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸µ•ÉÕÉ•}ÍÕ‰ÍÉ¥‰•}©ÝÐ ¤ì($%É•ÑÕÉ¸€¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €µ•ÉÕÉ•}ÍÕ‰ÍÉ¥‰•}©ÝÐœ°€œœ€¤ì(%ô(($¼¨¨($€¨AÉ•™¥àÕÍ•Ý¡•¸½µÁ½Í¥¹œ5•ÉÕÉ”Ñ½Á¥ŒUI%Ì½¹…µ•Ì¸($€¨($€¨É•ÑÕÉ¸ÍÑÉ¥¹œ($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸µ•ÉÕÉ•}Ñ½Á¥}ÁÉ•™¥à ¤ì($%É•ÑÕÉ¸€¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €µ•ÉÕÉ•}Ñ½Á¥}ÁÉ•™¥àœ°€‘½Õ¡‰½ÍÌœ€¤ì(%ô(($¼¨¨($€¨]¡•Ñ¡•È5•ÉÕÉ”¥Ì‰½Ñ •¹…‰±•…¹Ñ¡”µ¥¹¥µÕ´½¹™¥œ€¡¡ÕˆUI0€¬ÁÕ‰±¥Í ($€¨)]P¤¥ÌÁÉ•Í•¹Ð°Í¼Ñ¡”Í•ÉÙ•ÈÍ¡½Õ±…ÑÕ…±±äÁÕ‰±¥Í É•…°µÑ¥µ”ÕÁ‘…Ñ•Ì¸($€¨($€¨É•ÑÕÉ¸‰½½°($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸µ•ÉÕÉ•}É•…‘ä ¤ì($%É•ÑÕÉ¸Í•±˜èéµ•ÉÕÉ•}•¹…‰±• ¤€˜˜€œœ€„ôôÍ•±˜èéµ•ÉÕÉ•}¡Õ‰}ÕÉ° ¤€˜˜€œœ€„ôôÍ•±˜èéµ•ÉÕÉ•}ÁÕ‰±¥Í¡}©ÝÐ ¤ì(%ô(($¼¨¨($€¨]¡•Ñ¡•ÈÑ¡”¹Ñ™äÁÕÍ µ¹½Ñ¥™¥…Ñ¥½¸¥¹Ñ•É…Ñ¥½¸¥ÌÍÝ¥Ñ¡•½¸‰äÑ¡”½Á•É…Ñ½È¸($€¨($€¨É•ÑÕÉ¸‰½½°($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸¹Ñ™å}•¹…‰±• ¤ì($%É•ÑÕÉ¸€¡‰½½°¤Í•±˜èé•Ð €¹Ñ™å}•¹…‰±•œ°€À€¤ì(%ô(($¼¨¨($€¨¹Ñ™äÍ•ÉÙ•È‰…Í”UI0°ÑÉ…¥±¥¹œÍ±…Í É•µ½Ù•€¡‘•™…Õ±Ð¡ÑÑÁÌè¼½¹Ñ™ä¹Í ¤¸($€¨($€¨É•ÑÕÉ¸ÍÑÉ¥¹œ($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸¹Ñ™å}Í•ÉÙ•È ¤ì($$‘Í•ÉÙ•È€ôÕ¹ÑÉ…¥±¥¹Í±…Í¡¥Ð €¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €¹Ñ™å}Í•ÉÙ•Èœ°€¡ÑÑÁÌè¼½¹Ñ™ä¹Í œ€¤€¤ì($%É•ÑÕÉ¸€œœ€„ôô€‘Í•ÉÙ•È€ü€‘Í•ÉÙ•È€è€¡ÑÑÁÌè¼½¹Ñ™ä¹Í œì(%ô(($¼¨¨($€¨¹Ñ™äÑ½Á¥ŒÑ¼ÁÕ‰±¥Í Ñ¼€¡‰±…¹¬Ý¡•¸Õ¹½¹™¥ÕÉ•¤¸($€¨($€¨É•ÑÕÉ¸ÍÑÉ¥¹œ($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸¹Ñ™å}Ñ½Á¥Œ ¤ì($%É•ÑÕÉ¸€¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €¹Ñ™å}Ñ½Á¥Œœ°€œœ€¤ì(%ô(($¼¨¨($€¨¹Ñ™ä‰•…É•ÈÑ½­•¸¸I•…•¹Øµ™¥ÉÍÐƒŠPÑ¡”½¹ÍÑ…¹Ð=U!	=MM}9Qe}Q=-8½ÈÑ¡”($€¨µ…Ñ¡¥¹œ•¹Ù¥É½¹µ•¹ÐÙ…É¥…‰±”Ñ…­”ÁÉ••‘•¹”½Ù•ÈÑ¡”ÍÑ½É•½ÁÑ¥½¸°Í¼Ñ¡”($€¨Í•É•Ð…¸‰”­•ÁÐ½ÕÐ½˜Ñ¡”‘…Ñ…‰…Í”€¡…¹Ñ¡•É•™½É”½ÕÐ½˜‰…­ÕÁÌ¤¸=¹±ä($€¨•Ù•ÈÕÍ•Í•ÉÙ•ÈµÍ¥‘”Ñ¼…ÕÑ¡•¹Ñ¥…Ñ”ÁÕ‰±¥Í¡•Ìì¹•Ù•È•¡½•Ñ¼„±¥•¹Ð¸($€¨($€¨É•ÑÕÉ¸ÍÑÉ¥¹œ($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸¹Ñ™å}Ñ½­•¸ ¤ì($%¥˜€ ‘•™¥¹• €=U!	=MM}9Qe}Q=-8œ€¤€˜˜€œœ€„ôô€¡ÍÑÉ¥¹œ¤=U!	=MM}9Qe}Q=-8€¤ì($$%É•ÑÕÉ¸€¡ÍÑÉ¥¹œ¤=U!	=MM}9Qe}Q=-8ì($%ô($$‘•¹Ø€ô•Ñ•¹Ø €=U!	=MM}9Qe}Q=-8œ€¤ì($%¥˜€ ™…±Í”€„ôô€‘•¹Ø€˜˜€œœ€„ôô€‘•¹Ø€¤ì($$%É•ÑÕÉ¸€¡ÍÑÉ¥¹œ¤€‘•¹Øì($%ô($%É•ÑÕÉ¸€¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €¹Ñ™å}Ñ½­•¸œ°€œœ€¤ì(%ô(($¼¨¨($€¨¹Ñ™äµ•ÍÍ…”ÁÉ¥½É¥Ñä€¡‘•™…Õ±Ð€¡¥ œ¤¸($€¨($€¨É•ÑÕÉ¸ÍÑÉ¥¹œ($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸¹Ñ™å}ÁÉ¥½É¥Ñä ¤ì($%É•ÑÕÉ¸€¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €¹Ñ™å}ÁÉ¥½É¥Ñäœ°€¡¥ œ€¤ì(%ô(($¼¨¨($€¨]¡•Ñ¡•È¹Ñ™ä¥Ì‰½Ñ •¹…‰±•…¹„Ñ½Á¥Œ¥Ì½¹™¥ÕÉ•°Í¼Ñ¡”Í•ÉÙ•ÈÍ¡½Õ±($€¨…ÑÕ…±±äÁÕ‰±¥Í ¹½Ñ¥™¥…Ñ¥½¹Ì¸($€¨($€¨É•ÑÕÉ¸‰½½°($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸¹Ñ™å}É•…‘ä ¤ì($%É•ÑÕÉ¸Í•±˜èé¹Ñ™å}•¹…‰±• ¤€˜˜€œœ€„ôôÍ•±˜èé¹Ñ™å}Ñ½Á¥Œ ¤ì(%ô(($¼¨¨($€¨]¡•Ñ¡•ÈÑ¡”M5L€¡±¥­M•¹¤¥¹Ñ•É…Ñ¥½¸¥ÌÍÝ¥Ñ¡•½¸‰äÑ¡”½Á•É…Ñ½È¸($€¨($€¨É•ÑÕÉ¸‰½½°($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸ÍµÍ}•¹…‰±• ¤ì($%É•ÑÕÉ¸€¡‰½½°¤Í•±˜èé•Ð €ÍµÍ}•¹…‰±•œ°€À€¤ì(%ô(($¼¨¨($€¨±¥­M•¹…½Õ¹ÐÕÍ•É¹…µ”¸($€¨($€¨É•ÑÕÉ¸ÍÑÉ¥¹œ($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸±¥­Í•¹‘}ÕÍ•É¹…µ” ¤ì($%É•ÑÕÉ¸€¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €±¥­Í•¹‘}ÕÍ•É¹…µ”œ°€œœ€¤ì(%ô(($¼¨¨($€¨±¥­M•¹A$­•ä¸I•…•¹Øµ™¥ÉÍÐƒŠPÑ¡”½¹ÍÑ…¹Ð=U!	=MM}1%-M9}A%}-d($€¨½ÈÑ¡”µ…Ñ¡¥¹œ•¹Ù¥É½¹µ•¹ÐÙ…É¥…‰±”Ñ…­”ÁÉ••‘•¹”½Ù•ÈÑ¡”ÍÑ½É•½ÁÑ¥½¸°($€¨Í¼Ñ¡”Í•É•Ð…¸‰”­•ÁÐ½ÕÐ½˜Ñ¡”‘…Ñ…‰…Í”€¡…¹Ñ¡•É•™½É”½ÕÐ½˜‰…­ÕÁÌ¤¸($€¨=¹±ä•Ù•ÈÕÍ•Í•ÉÙ•ÈµÍ¥‘”Ñ¼…ÕÑ¡•¹Ñ¥…Ñ”Ñ¡”A$ì¹•Ù•È•¡½•Ñ¼„±¥•¹Ð¸($€¨($€¨É•ÑÕÉ¸ÍÑÉ¥¹œ($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸±¥­Í•¹‘}…Á¥}­•ä ¤ì($%¥˜€ ‘•™¥¹• €=U!	=MM}1%-M9}A%}-dœ€¤€˜˜€œœ€„ôô€¡ÍÑÉ¥¹œ¤=U!	=MM}1%-M9}A%}-d€¤ì($$%É•ÑÕÉ¸€¡ÍÑÉ¥¹œ¤=U!	=MM}1%-M9}A%}-dì($%ô($$‘•¹Ø€ô•Ñ•¹Ø €=U!	=MM}1%-M9}A%}-dœ€¤ì($%¥˜€ ™…±Í”€„ôô€‘•¹Ø€˜˜€œœ€„ôô€‘•¹Ø€¤ì($$%É•ÑÕÉ¸€¡ÍÑÉ¥¹œ¤€‘•¹Øì($%ô($%É•ÑÕÉ¸€¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €±¥­Í•¹‘}…Á¥}­•äœ°€œœ€¤ì(%ô(($¼¨¨($€¨Q¡”Í•¹‘•È%€¼™É½´µ¹Õµ‰•ÈÕÍ•™½È½ÕÑ‰½Õ¹M5L¸($€¨($€¨É•ÑÕÉ¸ÍÑÉ¥¹œ($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸ÍµÍ}™É½´ ¤ì($%É•ÑÕÉ¸€¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €ÍµÍ}™É½´œ°€œœ€¤ì(%ô(($¼¨¨($€¨]¡•Ñ¡•ÈÑ¼Ñ•áÐÑ¡”ÕÍÑ½µ•ÈÝ¡•¸Ñ¡•¥È½É‘•È¥Ìµ…É­•É•…‘ä€¡‘•™…Õ±Ð½¸¤¸($€¨($€¨É•ÑÕÉ¸‰½½°($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸ÍµÍ}½¹}É•…‘ä ¤ì($%É•ÑÕÉ¸€¡‰½½°¤Í•±˜èé•Ð €ÍµÍ}½¹}É•…‘äœ°€Ä€¤ì(%ô(($¼¨¨($€¨]¡•Ñ¡•ÈÑ¼Ñ•áÐÑ¡”Ù½Õ¡•È½‘”Ñ¼Ñ¡”ÕÍÑ½µ•ÈÝ¡•¸„Ù½Õ¡•È¥Ì±…¥µ•($€¨€¡‘•™…Õ±Ð½™˜¤¸($€¨($€¨É•ÑÕÉ¸‰½½°($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸ÍµÍ}½¹}Ù½Õ¡•É}±…¥´ ¤ì($%É•ÑÕÉ¸€¡‰½½°¤Í•±˜èé•Ð €ÍµÍ}½¹}Ù½Õ¡•É}±…¥´œ°€À€¤ì(%ô(($¼¨¨($€¨]¡•Ñ¡•ÈÑ¼•µ…¥°Ñ¡”ÕÍÑ½µ•ÈÝ¡•¸Ñ¡•¥È½É‘•È¥Ì…•ÁÑ•€¡‘•™…Õ±Ð½¸¤¸($€¨($€¨É•ÑÕÉ¸‰½½°($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸•µ…¥±}½¹}…•ÁÑ• ¤ì($%É•ÑÕÉ¸€¡‰½½°¤Í•±˜èé•Ð €•µ…¥±}½¹}…•ÁÑ•œ°€Ä€¤ì(%ô(($¼¨¨($€¨]¡•Ñ¡•ÈÑ¼•µ…¥°Ñ¡”ÕÍÑ½µ•ÈÝ¡•¸Ñ¡•¥È½É‘•È¥Ìµ…É­•É•…‘ä™½È($€¨Á¥­ÕÀ€¡‘•™…Õ±Ð½¸¤¸($€¨($€¨É•ÑÕÉ¸‰½½°($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸•µ…¥±}½¹}É•…‘ä ¤ì($%É•ÑÕÉ¸€¡‰½½°¤Í•±˜èé•Ð €•µ…¥±}½¹}É•…‘äœ°€Ä€¤ì(%ô(($¼¨¨($€¨]¡•Ñ¡•ÈÑ¼Í•¹Ñ¡”Í¡½À¥¹‰½à€¡½É‘•ÉÍ}•µ…¥° ¤¤„½Áä½˜•… ÍÑ…”($€¨•µ…¥°€¡‘•™…Õ±Ð½™˜¤¸($€¨($€¨É•ÑÕÉ¸‰½½°($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸•µ…¥±}ÍÑ…™™}½Áä ¤ì($%É•ÑÕÉ¸€¡‰½½°¤Í•±˜èé•Ð €•µ…¥±}ÍÑ…™™}½Áäœ°€À€¤ì(%ô(($¼¨¨($€¨]¡•Ñ¡•ÈM5L¥Ì‰½Ñ •¹…‰±•…¹™Õ±±ä½¹™¥ÕÉ•€¡ÕÍ•É¹…µ”€¬A$­•ä¤°Í¼($€¨Ñ¡”Í•ÉÙ•ÈÍ¡½Õ±…ÑÕ…±±äÍ•¹µ•ÍÍ…•Ì¸($€¨($€¨É•ÑÕÉ¸‰½½°($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸ÍµÍ}É•…‘ä ¤ì($%É•ÑÕÉ¸Í•±˜èéÍµÍ}•¹…‰±• ¤€˜˜€œœ€„ôôÍ•±˜èé±¥­Í•¹‘}ÕÍ•É¹…µ” ¤€˜˜€œœ€„ôôÍ•±˜èé±¥­Í•¹‘}…Á¥}­•ä ¤ì(%ô(($¼¨¨($€¨]¡•Ñ¡•ÈÑ¡”É••¥ÁÐµÁÉ¥¹Ñ•È¥¹Ñ•É…Ñ¥½¸¥ÌÍÝ¥Ñ¡•½¸‰äÑ¡”½Á•É…Ñ½È¸($€¨($€¨É•ÑÕÉ¸‰½½°($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸ÁÉ¥¹Ñ•É}•¹…‰±• ¤ì($%É•ÑÕÉ¸€¡‰½½°¤Í•±˜èé•Ð €ÁÉ¥¹Ñ•É}•¹…‰±•œ°€À€¤ì(%ô(($¼¨¨($€¨I••¥ÁÐÁÉ¥¹Ñ•ÈÁÉ½Ñ½½°è€±½Õ‘ÁÉ¹Ðœ½È€•Á½Ìœ€¡‘•™…Õ±Ð€±½Õ‘ÁÉ¹Ðœ¤¸($€¨($€¨É•ÑÕÉ¸ÍÑÉ¥¹œ($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸ÁÉ¥¹Ñ•É}ÁÉ½Ñ½½° ¤ì($%É•ÑÕÉ¸€•Á½Ìœ€ôôôÍ•±˜èé•Ð €ÁÉ¥¹Ñ•É}ÁÉ½Ñ½½°œ°€±½Õ‘ÁÉ¹Ðœ€¤€ü€•Á½Ìœ€è€±½Õ‘ÁÉ¹Ðœì(%ô(($¼¨¨($€¨I••¥ÁÐÁÉ¥¹Ñ•ÈÍ¡…É•Ñ½­•¸¸I•…•¹Øµ™¥ÉÍÐƒŠPÑ¡”½¹ÍÑ…¹Ð($€¨=U!	=MM}AI%9QI}Q=-8½ÈÑ¡”µ…Ñ¡¥¹œ•¹Ù¥É½¹µ•¹ÐÙ…É¥…‰±”Ñ…­”ÁÉ••‘•¹”($€¨½Ù•ÈÑ¡”ÍÑ½É•½ÁÑ¥½¸°Í¼Ñ¡”Í•É•Ð…¸‰”­•ÁÐ½ÕÐ½˜Ñ¡”‘…Ñ…‰…Í”€¡…¹($€¨Ñ¡•É•™½É”½ÕÐ½˜‰…­ÕÁÌ¤¸UÍ•Ñ¼…ÕÑ¡•¹Ñ¥…Ñ”Ñ¡”ÁÉ¥¹Ñ•È½Á½±°•á¡…¹”ì($€¨¹•Ù•È•¡½•Ñ¼„±¥•¹Ð¸($€¨($€¨É•ÑÕÉ¸ÍÑÉ¥¹œ($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸ÁÉ¥¹Ñ•É}Ñ½­•¸ ¤ì($%¥˜€ ‘•™¥¹• €=U!	=MM}AI%9QI}Q=-8œ€¤€˜˜€œœ€„ôô€¡ÍÑÉ¥¹œ¤=U!	=MM}AI%9QI}Q=-8€¤ì($$%É•ÑÕÉ¸€¡ÍÑÉ¥¹œ¤=U!	=MM}AI%9QI}Q=-8ì($%ô($$‘•¹Ø€ô•Ñ•¹Ø €=U!	=MM}AI%9QI}Q=-8œ€¤ì($%¥˜€ ™…±Í”€„ôô€‘•¹Ø€˜˜€œœ€„ôô€‘•¹Ø€¤ì($$%É•ÑÕÉ¸€¡ÍÑÉ¥¹œ¤€‘•¹Øì($%ô($%É•ÑÕÉ¸€¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €ÁÉ¥¹Ñ•É}Ñ½­•¸œ°€œœ€¤ì(%ô(($¼¨¨($€¨I••¥ÁÐÝ¥‘Ñ ¥¸¡…É…Ñ•ÉÌ€¡‘•™…Õ±Ð€Ðà™½È…¸€àÁµ´É½±°¤¸($€¨($€¨É•ÑÕÉ¸¥¹Ð($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸ÁÉ¥¹Ñ•É}Ý¥‘Ñ  ¤ì($%É•ÑÕÉ¸€¡¥¹Ð¤Í•±˜èé•Ð €ÁÉ¥¹Ñ•É}Ý¥‘Ñ œ°€Ðà€¤ì(%ô(($¼¨¨($€¨]¡•Ñ¡•ÈÑ¡”ÁÉ¥¹Ñ•È¥Ì‰½Ñ •¹…‰±•…¹„Í¡…É•Ñ½­•¸¥ÌÍ•Ð°Í¼Ñ¡”Í•ÉÙ•È($€¨Í¡½Õ±…ÑÕ…±±ä•µ¥ÐÉ••¥ÁÑÌ¸($€¨($€¨É•ÑÕÉ¸‰½½°($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸ÁÉ¥¹Ñ•É}É•…‘ä ¤ì($%É•ÑÕÉ¸Í•±˜èéÁÉ¥¹Ñ•É}•¹…‰±• ¤€˜˜€œœ€„ôôÍ•±˜èéÁÉ¥¹Ñ•É}Ñ½­•¸ ¤ì(%ô(($¼¨¨($€¨=É‘•Èµ½¹™¥Éµ…Ñ¥½¸•µ…¥°ÍÕ‰©•Ð¸=Ý¹•Èµ•‘¥Ñ…‰±”€¡½Õ¡	½ÍÌƒŠH5•ÍÍ…”($€¨Q•µÁ±…Ñ•Ì¤ì‰±…¹¬É•ÍÑ½É•ÌÑ¡”‰Õ¥±Ðµ¥¸‘•™…Õ±Ð¸MÕÁÁ½ÉÑÌÑ¡”($€¨íÍ¥Ñ•}¹…µ•ô½í½É‘•É}¹Õµ‰•ÉôÁ±…•¡½±‘•ÉÌƒŠPÍ•”É•¹‘•É}Ñ•µÁ±…Ñ” ¤¸($€¨($€¨É•ÑÕÉ¸ÍÑÉ¥¹œ($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸ÑÁ±}½É‘•É}•µ…¥±}ÍÕ‰©•Ð ¤ì($$‘Ø€ôÑÉ¥´ €¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €ÑÁ±}½É‘•É}•µ…¥±}ÍÕ‰©•Ðœ°€œœ€¤€¤ì($%É•ÑÕÉ¸€œœ€„ôô€‘Ø€ü€‘Ø€è€míÍ¥Ñ•}¹…µ•õt=É‘•Èí½É‘•É}¹Õµ‰•ÉôÉ••¥Ù•œì(%ô(($¼¨¨($€¨=É‘•Èµ½¹™¥Éµ…Ñ¥½¸•µ…¥°‰½‘ä¸=Ý¹•Èµ•‘¥Ñ…‰±”ì‰±…¹¬É•ÍÑ½É•ÌÑ¡”($€¨‰Õ¥±Ðµ¥¸‘•™…Õ±Ð¸MÕÁÁ½ÉÑÌíÕÍÑ½µ•É}¹…µ•ô½í½É‘•É}¹Õµ‰•Éô½í¥Ñ•µÍô½íÑ½Ñ…±ô¼($€¨íÑÉ…­¥¹}ÕÉ±ô½íÑÉ…­¥¹}¥¹ÍÑÉÕÑ¥½¹Íô¸($€¨($€¨É•ÑÕÉ¸ÍÑÉ¥¹œ($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸ÑÁ±}½É‘•É}•µ…¥±}‰½‘ä ¤ì($$‘Ø€ô€¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €ÑÁ±}½É‘•É}•µ…¥±}‰½‘äœ°€œœ€¤ì($%É•ÑÕÉ¸€œœ€„ôôÑÉ¥´ €‘Ø€¤($$$ü€‘Ø($$$è€‰!¤íÕÍÑ½µ•É}¹…µ•ô±q¹q¹Q¡…¹­Ì™½Èå½ÕÈ½É‘•Èí½É‘•É}¹Õµ‰•Éô¸!•É”ÌÝ¡…ÐÝ”½Ðéq¹q¹í¥Ñ•µÍõq¹q¹Q½Ñ…°èíÑ½Ñ…±õq¹q¹íÑÉ…­¥¹}¥¹ÍÑÉÕÑ¥½¹Íõq¸ˆì(%ô(($¼¨¨($€¨€‰=É‘•ÈÉ•…‘äˆM5LÑ•áÐ¸=Ý¹•Èµ•‘¥Ñ…‰±”ì‰±…¹¬É•ÍÑ½É•ÌÑ¡”‰Õ¥±Ðµ¥¸($€¨‘•™…Õ±Ð¸MÕÁÁ½ÉÑÌí½É‘•É}¹Õµ‰•Éô¸($€¨($€¨É•ÑÕÉ¸ÍÑÉ¥¹œ($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸ÑÁ±}ÍµÍ}É•…‘ä ¤ì($$‘Ø€ôÑÉ¥´ €¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €ÑÁ±}ÍµÍ}É•…‘äœ°€œœ€¤€¤ì($%É•ÑÕÉ¸€œœ€„ôô€‘Ø€ü€‘Ø€è€½Õ¡	½ÍÌ½É‘•È€í½É‘•É}¹Õµ‰•ÉôèíÍÑ…ÑÕÍ}±…‰•±ô¸í¡…¹‘½™™}µ•ÍÍ…•ôœì(%ô(($¼¨¨($€¨Y½Õ¡•Èµ±…¥µ•M5LÑ•áÐ¸=Ý¹•Èµ•‘¥Ñ…‰±”ì‰±…¹¬É•ÍÑ½É•ÌÑ¡”‰Õ¥±Ðµ¥¸($€¨‘•™…Õ±Ð¸MÕÁÁ½ÉÑÌí½‘•ô¸($€¨($€¨É•ÑÕÉ¸ÍÑÉ¥¹œ($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸ÑÁ±}ÍµÍ}Ù½Õ¡•È ¤ì($$‘Ø€ôÑÉ¥´ €¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €ÑÁ±}ÍµÍ}Ù½Õ¡•Èœ°€œœ€¤€¤ì($%É•ÑÕÉ¸€œœ€„ôô€‘Ø€ü€‘Ø€è€e½ÕÈ½Õ¡	½ÍÌÙ½Õ¡•È¥ÌÉ•…‘äèí½‘•ô¸M¡½ÜÑ¡¥Ì½‘”Ñ¼É•‘••´¸œì(%ô(($¼¨¨($€¨€‰=É‘•È…•ÁÑ•ˆÍÑ…”•µ…¥°ÍÕ‰©•Ð¸=Ý¹•Èµ•‘¥Ñ…‰±”ì‰±…¹¬É•ÍÑ½É•ÌÑ¡”($€¨‰Õ¥±Ðµ¥¸‘•™…Õ±Ð¸MÕÁÁ½ÉÑÌíÕÍÑ½µ•É}¹…µ•ô½í½É‘•É}¹Õµ‰•Éô½í•Ñ…}µ¥¹ÕÑ•Íô¼($€¨íÑ½Ñ…±ô½íÍÑ…ÑÕÍ}±…‰•±ô¸($€¨($€¨É•ÑÕÉ¸ÍÑÉ¥¹œ($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸ÑÁ±}…•ÁÑ•‘}•µ…¥±}ÍÕ‰©•Ð ¤ì($$‘Ø€ôÑÉ¥´ €¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €ÑÁ±}…•ÁÑ•‘}•µ…¥±}ÍÕ‰©•Ðœ°€œœ€¤€¤ì($%É•ÑÕÉ¸€œœ€„ôô€‘Ø€ü€‘Ø€è€‰]”É”½¸¥Ð„=É‘•Èí½É‘•É}¹Õµ‰•Éô¥Ì‰•¥¹œÁÉ•Á…É•ˆì(%ô(($¼¨¨($€¨€‰=É‘•È…•ÁÑ•ˆÍÑ…”•µ…¥°‰½‘ä¸=Ý¹•Èµ•‘¥Ñ…‰±”ì‰±…¹¬É•ÍÑ½É•ÌÑ¡”($€¨‰Õ¥±Ðµ¥¸‘•™…Õ±Ð¸Q¡”‰Õ¥±Ðµ¥¸‘•™…Õ±Ð¡…ÌÑÝ¼Ù…É¥…¹ÑÌè½¹”Ý¥Ñ Ñ¡”($€¨€‰É•…‘ä¥¸…‰½ÕÐí•Ñ…}µ¥¹ÕÑ•Íôµ¥¹ÕÑ•Ìˆ±¥¹”…¹„¹•ÕÑÉ…°½¹”ÕÍ•Ý¡•¸($€¨¹¼QÝ…Ì¥Ù•¸€¡•Ñ„€À¤°Í¼Ñ¡”ÕÍÑ½µ•È¹•Ù•ÈÉ•…‘Ì€‰¥¸…‰½ÕÐ€À($€¨µ¥¹ÕÑ•Ìˆ¸ÕÍÑ½´Ñ•µÁ±…Ñ”¥ÌÉ•ÑÕÉ¹•…Ìµ¥Ì•¥Ñ¡•ÈÝ…ä¸($€¨($€¨Á…É…´‰½½°€‘Ý¥Ñ¡}•Ñ„]¡•Ñ¡•È…¸QÝ…Ì¥Ù•¸€¡Í•±•ÑÌÑ¡”‘•™…Õ±ÐÙ…É¥…¹Ð¤¸($€¨É•ÑÕÉ¸ÍÑÉ¥¹œ($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸ÑÁ±}…•ÁÑ•‘}•µ…¥±}‰½‘ä €‘Ý¥Ñ¡}•Ñ„€ôÑÉÕ”€¤ì($$‘Ø€ô€¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €ÑÁ±}…•ÁÑ•‘}•µ…¥±}‰½‘äœ°€œœ€¤ì($%¥˜€ €œœ€„ôôÑÉ¥´ €‘Ø€¤€¤ì($$%É•ÑÕÉ¸€‘Øì($%ô($%¥˜€ €‘Ý¥Ñ¡}•Ñ„€¤ì($$%É•ÑÕÉ¸€‰!¤íÕÍÑ½µ•É}¹…µ•ô±q¹q¹É•…Ð¹•ÝÌƒŠP½ÕÈ‰…­•ÉÌ¡…Ù”ÍÑ…ÉÑ•½¸å½ÕÈ½É‘•Èí½É‘•É}¹Õµ‰•Éô¸%ÐÍ¡½Õ±‰”É•…‘ä¥¸…‰½ÕÐí•Ñ…}µ¥¹ÕÑ•Íôµ¥¹ÕÑ•Ì¹q¹q¹=É‘•ÈÑ½Ñ…°èíÑ½Ñ…±õq¹q¹íÑÉ…­¥¹}¥¹ÍÑÉÕÑ¥½¹Íõq¹q¹Q¡…¹­Ì™½È¡½½Í¥¹œÕÌƒŠPÍ•”å½ÔÍ½½¸…q¸ˆì($%ô($%É•ÑÕÉ¸€‰!¤íÕÍÑ½µ•É}¹…µ•ô±q¹q¹É•…Ð¹•ÝÌƒŠP½ÕÈ‰…­•ÉÌ¡…Ù”ÍÑ…ÉÑ•½¸å½ÕÈ½É‘•Èí½É‘•É}¹Õµ‰•Éô¸]”±°±•Ðå½Ô­¹½ÜÑ¡”µ½µ•¹Ð¥ÐÌÉ•…‘ä¹q¹q¹=É‘•ÈÑ½Ñ…°èíÑ½Ñ…±õq¹q¹íÑÉ…­¥¹}¥¹ÍÑÉÕÑ¥½¹Íõq¹q¹Q¡…¹­Ì™½È¡½½Í¥¹œÕÌƒŠPÍ•”å½ÔÍ½½¸…q¸ˆì(%ô(($¼¨¨($€¨€‰=É‘•ÈÉ•…‘äˆÍÑ…”•µ…¥°ÍÕ‰©•Ð¸=Ý¹•Èµ•‘¥Ñ…‰±”ì‰±…¹¬É•ÍÑ½É•ÌÑ¡”($€¨‰Õ¥±Ðµ¥¸‘•™…Õ±Ð¸MÕÁÁ½ÉÑÌÑ¡”Í…µ”Á±…•¡½±‘•ÉÌ…ÌÑ¡”…•ÁÑ••µ…¥°¸($€¨($€¨É•ÑÕÉ¸ÍÑÉ¥¹œ($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸ÑÁ±}É•…‘å}•µ…¥±}ÍÕ‰©•Ð ¤ì($$‘Ø€ôÑÉ¥´ €¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €ÑÁ±}É•…‘å}•µ…¥±}ÍÕ‰©•Ðœ°€œœ€¤€¤ì($%É•ÑÕÉ¸€œœ€„ôô€‘Ø€ü€‘Ø€è€=É‘•Èí½É‘•É}¹Õµ‰•ÉôèíÍÑ…ÑÕÍ}±…‰•±ôœì(%ô(($¼¨¨($€¨€‰=É‘•ÈÉ•…‘äˆÍÑ…”•µ…¥°‰½‘ä¸=Ý¹•Èµ•‘¥Ñ…‰±”ì‰±…¹¬É•ÍÑ½É•ÌÑ¡”($€¨‰Õ¥±Ðµ¥¸‘•™…Õ±Ð¸($€¨($€¨É•ÑÕÉ¸ÍÑÉ¥¹œ($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸ÑÁ±}É•…‘å}•µ…¥±}‰½‘ä ¤ì($$‘Ø€ô€¡ÍÑÉ¥¹œ¤Í•±˜èé•Ð €ÑÁ±}É•…‘å}•µ…¥±}‰½‘äœ°€œœ€¤ì($%É•ÑÕÉ¸€œœ€„ôôÑÉ¥´ €‘Ø€¤($$$ü€‘Ø($$$è€‰!¤íÕÍÑ½µ•É}¹…µ•ô±q¹q¹e½ÕÈ½É‘•Èí½É‘•É}¹Õµ‰•Éô¥ÌíÍÑ…ÑÕÍ}±…‰•±ô¸í¡…¹‘½™™}µ•ÍÍ…•õq¹q¹=É‘•ÈÑ½Ñ…°èíÑ½Ñ…±õq¹q¹íÑÉ…­¥¹}¥¹ÍÑÉÕÑ¥½¹Íõq¹q¹M•”å½ÔÍ½½¸…q¸ˆì(%ô(($¼¨¨($€¨I•Á±…”íÁ±…•¡½±‘•ÉôÑ½­•¹Ì¥¸„µ•ÍÍ…”Ñ•µÁ±…Ñ”Ý¥Ñ Ñ¡”¥Ù•¸Ù…±Õ•Ì¸($€¨U¹­¹½Ý¸Á±…•¡½±‘•ÉÌ…É”±•™Ð…Ì±¥Ñ•É…°Ñ•áÐÉ…Ñ¡•ÈÑ¡…¸Í¥±•¹Ñ±ä($€¨‰±…¹­•°Í¼„ÑåÁ¼¥¸„ÕÍÑ½´Ñ•µÁ±…Ñ”ÍÑ…åÌÙ¥Í¥‰±”¥¹ÍÑ•…½˜¡¥‘‘•¸¸($€¨($€¨Á…É…´ÍÑÉ¥¹œ€‘Ñ•µÁ±…Ñ”Q•µÁ±…Ñ”Ñ•áÐ½¹Ñ…¥¹¥¹œíÁ±…•¡½±‘•ÉôÑ½­•¹Ì¸($€¨Á…É…´…ÉÉ…ä€€‘Ù…ÉÌ€€€€5…À½˜Á±…•¡½±‘•È¹…µ”€¡Ý¥Ñ¡½ÕÐ‰É…•Ì¤€ôøÙ…±Õ”¸($€¨É•ÑÕÉ¸ÍÑÉ¥¹œ($€¨¼(%ÁÕ‰±¥ŒÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸É•¹‘•É}Ñ•µÁ±…Ñ” €‘Ñ•µÁ±…Ñ”°…ÉÉ…ä€‘Ù…ÉÌ€¤ì($$‘Í•…É €€ô…ÉÉ…ä ¤ì($$‘É•Á±…”€ô…ÉÉ…ä ¤ì($%™½É•… € €‘Ù…ÉÌ…Ì€‘­•ä€ôø€‘Ù…±Õ”€¤ì($$$‘Í•…É¡mt€€ô€ìœ€¸€‘­•ä€¸€ôœì($$$‘É•Á±…•mt€ô€¡ÍÑÉ¥¹œ¤€‘Ù…±Õ”ì($%ô($%É•ÑÕÉ¸ÍÑÉ}É•Á±…” €‘Í•…É °€‘É•Á±…”°€¡ÍÑÉ¥¹œ¤€‘Ñ•µÁ±…Ñ”€¤ì(%ô)ô(