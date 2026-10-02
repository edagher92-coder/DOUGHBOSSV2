<?php
/**
 * DoughBoss Growth server-side conversions: verified events only, consented identifiers only.
 *
 * Feature flag: server_conversions (needs attribution and at least one configured destination). Off by default; while
 * it is off nothing in this file runs, nothing is queued and no hook is registered. Fails closed: any missing setting,
 * missing consent record, storage problem or unexpected error means the event is NOT queued and the order or enquiry
 * is handled by core exactly as before.
 *
 * What it does when on:
 *  - on core's doughboss_order_created and doughboss_order_payment_status_changed it queues a "purchase" for an order
 *    whose stored payment_status is exactly "paid" (event_id order:{order_number}), and a "refund" when a paid order
 *    becomes "refunded" (event_id refund:{order_number}). A pay-at-shop (unpaid) order never produces a purchase;
 *  - on doughboss_catering_enquiry_created it queues a "generate_lead" (form catering_enquiry, no value,
 *    event_id lead:{enquiry_number});
 *  - each event is queued per channel (ga4, meta) in the shared outbox (UNIQUE channel + event_id, so a replayed hook
 *    never queues twice) ONLY when the consent snapshot stored with the order or enquiry (WP-04) allows that channel:
 *    measurement for GA4, advertising for Meta. No stored snapshot means no consent: nothing is queued;
 *  - the outbox calls the channel handlers in class-doughboss-growth-ga4.php and class-doughboss-growth-meta.php, which
 *    re-check the consent flags carried in the payload before any request is made;
 *  - the weekly Google Ads offline-conversion CSV is built by class-doughboss-growth-offline-export.php.
 *
 * Nothing is dropped quietly (the house silence rule). The ordinary reasons for not queueing an event (not paid yet, no
 * consent, nothing the platform could match) stay silent: they are the business working as designed. The abnormal ones
 * (the order or enquiry cannot be found, has no usable number or amount, core's lookup is gone, the stored consent could
 * not be read, a payload was refused) are listed in DoughBoss_Growth_Failures, and a failed read is never reported as
 * "no consent".
 *
 * Queued payloads hold no name, email, phone, address, notes, IP address or user agent. Hashed email and phone are only
 * ever added for Meta, only when the send_hashed_identifiers setting is on AND the visitor gave advertising consent.
 * Money is held as integer cents and converted to a decimal AUD number once, in the handler. The filter
 * doughboss_growth_conversion_payload may adjust a payload; its output is re-validated against the allow-list below and
 * a result that adds personal data, changes the event identity or the consent flags, or is malformed is refused.
 *
 * The companion never writes a core table, option or hook result: core stays the only writer of orders and enquiries.
 * Every hook callback catches everything, because an exception here would otherwise reach core's checkout or enquiry code.
 *
 * Entry file for the module registry: it must stay free of side effects at include time.
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conversion events: triggers, consent gating, payload allow-list, money handling, admin tab.
 */
final class DoughBoss_Growth_Conversions {

	/**
	 * Feature flag.
	 */
	const FEATURE = 'server_conversions';

	/**
	 * Outbox channels this module owns.
	 */
	const CHANNELS = array( 'ga4', 'meta' );

	/**
	 * Currency. Anything else is not queued: the stored order or enquiry total is only trusted as AUD.
	 */
	const CURRENCY = 'AUD';

	/**
	 * Largest amount in cents (core's MAX_QUOTE_AMOUNT of 999,999.99).
	 */
	const MAX_CENTS = 99999999;

	/**
	 * The one lead form this module reports.
	 */
	const FORM_CATERING = 'catering_enquiry';

	/**
	 * Priority of the core hook callbacks. WP-04 writes its attribution and consent record at priority 20, so this runs after it.
	 */
	const HOOK_PRIORITY = 30;

	/**
	 * Payload schema version.
	 */
	const PAYLOAD_VERSION = 1;

	/**
	 * Events each channel can carry.
	 */
	const CHANNEL_EVENTS = array(
		'ga4'  => array( 'purchase', 'refund', 'generate_lead' ),
		'meta' => array( 'purchase', 'generate_lead' ),
	);

	/**
	 * Skip reasons that mean something is wrong with the data or the system, not with the business case: each is listed
	 * as a failure (conversion_skipped_<reason>) so the owner sees it. Every other reason (inactive, bad_input, not_paid,
	 * not_a_refund_of_a_paid_order and the per-channel no_consent, no_match_keys, not_configured, duplicate) is normal and
	 * silent.
	 */
	const ABNORMAL_SKIPS = array( 'no_order', 'no_order_number', 'no_valid_amount', 'no_core_accessor', 'no_enquiry', 'no_enquiry_number' );

	/* ------------------------------------------------------------------------------------------ */
	/* Wiring                                                                                       */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Hook everything. Re-checks the flag itself. A channel's outbox handler is registered only when that channel's
	 * destination is fully configured (id plus secret), so rows for an unconfigured channel are never claimed.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! DoughBoss_Growth_Settings::enabled( self::FEATURE ) ) {
			return;
		}
		if ( class_exists( 'DoughBoss_Growth_Ga4', false ) && self::destination_ready( 'ga4' ) ) {
			DoughBoss_Growth_Outbox::register_channel( 'ga4', array( 'DoughBoss_Growth_Ga4', 'deliver' ) );
		}
		if ( class_exists( 'DoughBoss_Growth_Meta', false ) && self::destination_ready( 'meta' ) ) {
			DoughBoss_Growth_Outbox::register_channel( 'meta', array( 'DoughBoss_Growth_Meta', 'deliver' ) );
		}
		add_action( 'doughboss_order_created', array( __CLASS__, 'on_order_created' ), self::HOOK_PRIORITY, 2 );
		add_action( 'doughboss_order_payment_status_changed', array( __CLASS__, 'on_order_payment_status_changed' ), self::HOOK_PRIORITY, 3 );
		add_action( 'doughboss_catering_enquiry_created', array( __CLASS__, 'on_enquiry_created' ), self::HOOK_PRIORITY, 2 );
		add_action( 'doughboss_growth_admin_tabs', array( __CLASS__, 'register_tab' ) );
		if ( class_exists( 'DoughBoss_Growth_Offline_Export', false ) ) {
			DoughBoss_Growth_Offline_Export::init();
		}
	}

	/**
	 * Whether the feature is on and storage is installed.
	 *
	 * @return bool
	 */
	private static function active() {
		return DoughBoss_Growth_Settings::enabled( self::FEATURE ) && DoughBoss_Growth_Activator::storage_ready();
	}

	/**
	 * Whether a channel's destination is fully configured (id from settings, secret from environment or constant).
	 *
	 * @param string $channel ga4 or meta.
	 * @return bool
	 */
	public static function destination_ready( $channel ) {
		$settings = DoughBoss_Growth_Settings::get_all();
		if ( 'ga4' === $channel ) {
			return '' !== $settings['ga4_measurement_id'] && DoughBoss_Growth_Settings::has_secret( 'DOUGHBOSS_GROWTH_GA4_API_SECRET' );
		}
		if ( 'meta' === $channel ) {
			return '' !== $settings['meta_pixel_id'] && DoughBoss_Growth_Settings::has_secret( 'DOUGHBOSS_GROWTH_META_CAPI_TOKEN' );
		}
		return false;
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Core hooks                                                                                   */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Action doughboss_order_created( $order_id, $data ): a purchase when the order is already paid.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $data     Order data (unused: the stored row is the authority).
	 * @return void
	 */
	public static function on_order_created( $order_id, $data = array() ) {
		unset( $data );
		try {
			self::process_order( $order_id, 'purchase', '' );
		} catch ( Throwable $e ) {
			self::log_failure( 'order_created', $e );
		}
	}

	/**
	 * Action doughboss_order_payment_status_changed( $order_id, $previous, $current ): a purchase when the order
	 * becomes paid, a refund when a paid order becomes refunded.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $previous Previous payment status.
	 * @param mixed $current  New payment status.
	 * @return void
	 */
	public static function on_order_payment_status_changed( $order_id, $previous = '', $current = '' ) {
		try {
			if ( 'paid' === $current ) {
				self::process_order( $order_id, 'purchase', is_string( $previous ) ? $previous : '' );
			} elseif ( 'refunded' === $current ) {
				self::process_order( $order_id, 'refund', is_string( $previous ) ? $previous : '' );
			}
		} catch ( Throwable $e ) {
			self::log_failure( 'order_payment_status', $e );
		}
	}

	/**
	 * Action doughboss_catering_enquiry_created( $id, $row ): a generate_lead.
	 *
	 * @param mixed $id  Enquiry id.
	 * @param mixed $row Stored enquiry row.
	 * @return void
	 */
	public static function on_enquiry_created( $id, $row = array() ) {
		unset( $row );
		try {
			self::process_enquiry( $id );
		} catch ( Throwable $e ) {
			self::log_failure( 'enquiry_created', $e );
		}
	}

	/**
	 * Queue the events for one order. Public so tests (and a future replay tool) can drive it; hook callbacks wrap it.
	 *
	 * @param mixed  $order_id Order id.
	 * @param string $event    purchase or refund.
	 * @param string $previous Previous payment status (a refund needs "paid").
	 * @return array Channel => status (queued, duplicate, rejected, error, no_consent, not_configured, no_match_keys,
	 *               unsupported, read_failed), or array( 'skipped' => reason ) when nothing could be considered. read_failed
	 *               means the stored consent could not be read: the event was NOT judged and NOT queued (it is not no_consent).
	 */
	public static function process_order( $order_id, $event, $previous ) {
		$id = is_numeric( $order_id ) ? (int) $order_id : 0;
		if ( $id < 1 || ! in_array( $event, array( 'purchase', 'refund' ), true ) ) {
			return array( 'skipped' => 'bad_input' );
		}
		if ( ! self::active() ) {
			return array( 'skipped' => 'inactive' );
		}
		if ( ! self::core_accessor( 'DoughBoss_Order' ) ) {
			return self::skipped( 'no_core_accessor', $event );
		}
		$order = self::load_order( $id );
		if ( null === $order ) {
			return self::skipped( 'no_order', $event );
		}
		$status = ( isset( $order->payment_status ) && is_string( $order->payment_status ) ) ? $order->payment_status : '';
		if ( 'purchase' === $event && 'paid' !== $status ) {
			return array( 'skipped' => 'not_paid' ); // Pay-at-shop and unpaid orders are never a purchase.
		}
		if ( 'refund' === $event && ( 'refunded' !== $status || 'paid' !== $previous ) ) {
			return array( 'skipped' => 'not_a_refund_of_a_paid_order' );
		}
		$number = self::clean_ref( isset( $order->order_number ) ? $order->order_number : '' );
		if ( '' === $number ) {
			return self::skipped( 'no_order_number', $event );
		}
		$currency = ( isset( $order->currency ) && is_string( $order->currency ) ) ? strtoupper( trim( $order->currency ) ) : '';
		$cents    = self::to_cents( isset( $order->total ) ? $order->total : null );
		if ( self::CURRENCY !== $currency || null === $cents || $cents < 1 ) {
			if ( self::CURRENCY === $currency && 0 === $cents ) {
				return array( 'skipped' => 'no_valid_amount' ); // A genuine zero total (a fully discounted order) is not a purchase, and not a fault.
			}
			return self::skipped( 'no_valid_amount', $event ); // An amount or currency core did not give us in a readable form.
		}
		$base    = array(
			'v'              => self::PAYLOAD_VERSION,
			'event'          => $event,
			'event_id'       => ( 'purchase' === $event ? 'order:' : 'refund:' ) . $number,
			'event_time'     => DoughBoss_Growth::now(),
			'currency'       => self::CURRENCY,
			'transaction_id' => $number,
			'value_cents'    => $cents,
		);
		$contact = array(
			'email' => isset( $order->customer_email ) ? $order->customer_email : '',
			'phone' => isset( $order->customer_phone ) ? $order->customer_phone : '',
		);
		return self::queue( $base, 'order', $id, $contact );
	}

	/**
	 * Queue the lead event for one catering enquiry.
	 *
	 * @param mixed $enquiry_id Enquiry id.
	 * @return array See process_order().
	 */
	public static function process_enquiry( $enquiry_id ) {
		$id = is_numeric( $enquiry_id ) ? (int) $enquiry_id : 0;
		if ( $id < 1 ) {
			return array( 'skipped' => 'bad_input' );
		}
		if ( ! self::active() ) {
			return array( 'skipped' => 'inactive' );
		}
		if ( ! self::core_accessor( 'DoughBoss_Catering' ) ) {
			return self::skipped( 'no_core_accessor', 'generate_lead' );
		}
		$enquiry = self::load_enquiry( $id );
		if ( null === $enquiry ) {
			return self::skipped( 'no_enquiry', 'generate_lead' );
		}
		$number = self::clean_ref( isset( $enquiry['enquiry_number'] ) ? $enquiry['enquiry_number'] : '' );
		if ( '' === $number ) {
			return self::skipped( 'no_enquiry_number', 'generate_lead' );
		}
		$base    = array(
			'v'          => self::PAYLOAD_VERSION,
			'event'      => 'generate_lead',
			'event_id'   => 'lead:' . $number,
			'event_time' => DoughBoss_Growth::now(),
			'currency'   => self::CURRENCY,
			'form'       => self::FORM_CATERING,
		);
		$contact = array(
			'email' => isset( $enquiry['customer_email'] ) ? $enquiry['customer_email'] : '',
			'phone' => isset( $enquiry['customer_phone'] ) ? $enquiry['customer_phone'] : '',
		);
		return self::queue( $base, 'enquiry', $id, $contact );
	}

	/**
	 * Whether core's accessor class and its get() method exist (a core change can remove either).
	 *
	 * @param string $class Core class name.
	 * @return bool
	 */
	private static function core_accessor( $class ) {
		return class_exists( $class ) && is_callable( array( $class, 'get' ) );
	}

	/**
	 * A skip result. An abnormal reason (see ABNORMAL_SKIPS) is also listed as a failure, with only the event name as context.
	 *
	 * @param string $reason Skip reason.
	 * @param string $event  purchase, refund or generate_lead.
	 * @return array array( 'skipped' => reason ).
	 */
	private static function skipped( $reason, $event ) {
		if ( in_array( $reason, self::ABNORMAL_SKIPS, true ) ) {
			self::note_failure( 'conversion_skipped_' . $reason, array( 'event' => $event ) );
		}
		return array( 'skipped' => $reason );
	}

	/**
	 * List a failure for the owner (the Recent failures list on the Growth settings screen). Never throws.
	 *
	 * @param string $code    Failure code.
	 * @param array  $context Scalar facts without personal data.
	 * @return void
	 */
	private static function note_failure( $code, array $context ) {
		if ( class_exists( 'DoughBoss_Growth_Failures', false ) ) {
			DoughBoss_Growth_Failures::record( $code, $context );
		}
	}

	/**
	 * Core order row through core's own accessor.
	 *
	 * @param int $id Order id.
	 * @return object|null
	 */
	private static function load_order( $id ) {
		if ( ! class_exists( 'DoughBoss_Order' ) || ! is_callable( array( 'DoughBoss_Order', 'get' ) ) ) {
			return null;
		}
		$order = call_user_func( array( 'DoughBoss_Order', 'get' ), $id );
		return is_object( $order ) ? $order : null;
	}

	/**
	 * Core catering enquiry row through core's own accessor.
	 *
	 * @param int $id Enquiry id.
	 * @return array|null
	 */
	private static function load_enquiry( $id ) {
		if ( ! class_exists( 'DoughBoss_Catering' ) || ! is_callable( array( 'DoughBoss_Catering', 'get' ) ) ) {
			return null;
		}
		$row = call_user_func( array( 'DoughBoss_Catering', 'get' ), $id );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * A reference (order or enquiry number) that is safe in an event id: 1 to 32 of letters, digits, dot, underscore,
	 * hyphen, starting with a letter or digit. Anything else gives ''.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function clean_ref( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		return ( 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9_.\-]{0,31}\z/', $value ) ) ? $value : '';
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Queueing                                                                                     */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Queue one event on every channel that is configured and consented to.
	 *
	 * @param array  $base         Channel-neutral payload (see process_order()).
	 * @param string $subject_type order or enquiry.
	 * @param int    $subject_id   Subject id.
	 * @param array  $contact      email and phone, used only to compute hashes in memory; never stored.
	 * @return array Channel => status.
	 */
	private static function queue( array $base, $subject_type, $subject_id, array $contact ) {
		$stored = self::stored_subject( $subject_type, $subject_id );
		if ( false === $stored ) {
			// The stored consent could not be read. The event is neither queued nor judged: this is NOT "no consent".
			self::note_failure(
				'conversion_read_failed',
				array(
					'event' => $base['event'],
					'stage' => 'attribution',
				)
			);
			$out = array();
			foreach ( self::CHANNELS as $channel ) {
				$out[ $channel ] = 'read_failed';
			}
			return $out;
		}
		if ( null === $stored ) {
			return array(
				'ga4'  => 'no_consent',
				'meta' => 'no_consent',
			);
		}
		$out = array();
		foreach ( self::CHANNELS as $channel ) {
			$out[ $channel ] = self::queue_channel( $channel, $base, $stored, $subject_type, $subject_id, $contact );
		}
		return $out;
	}

	/**
	 * The attribution and consent record stored with an order or enquiry (WP-04).
	 *
	 * @param string $subject_type order or enquiry.
	 * @param int    $subject_id   Subject id.
	 * @return array|null|false The record; null when there is none (the visitor gave no consent record, which is ordinary);
	 *                          false when it could not be read (the database failed, or the attribution module is missing).
	 */
	private static function stored_subject( $subject_type, $subject_id ) {
		if ( ! class_exists( 'DoughBoss_Growth_Attribution', false ) && ! DoughBoss_Growth::load_module( 'attribution' ) ) {
			return false;
		}
		$stored = DoughBoss_Growth_Attribution::for_subject( $subject_type, $subject_id );
		if ( false === $stored ) {
			return false;
		}
		if ( ! is_array( $stored ) || ! isset( $stored['consent'] ) || ! is_array( $stored['consent'] ) ) {
			return null;
		}
		return $stored;
	}

	/**
	 * Build, filter, validate and enqueue the payload for one channel.
	 *
	 * @param string $channel      ga4 or meta.
	 * @param array  $base         Channel-neutral payload.
	 * @param array  $stored       Result of Attribution::for_subject().
	 * @param string $subject_type Subject type.
	 * @param int    $subject_id   Subject id.
	 * @param array  $contact      email and phone for hashing.
	 * @return string Status.
	 */
	private static function queue_channel( $channel, array $base, array $stored, $subject_type, $subject_id, array $contact ) {
		if ( ! in_array( $base['event'], self::CHANNEL_EVENTS[ $channel ], true ) ) {
			return 'unsupported';
		}
		if ( ! self::destination_ready( $channel ) ) {
			return 'not_configured';
		}
		$consent = $stored['consent'];
		$allowed = ( 'ga4' === $channel ) ? ( true === ( isset( $consent['measurement'] ) ? $consent['measurement'] : false ) ) : ( true === ( isset( $consent['advertising'] ) ? $consent['advertising'] : false ) );
		if ( ! $allowed ) {
			return 'no_consent';
		}
		$payload            = $base;
		$payload['consent'] = array(
			'm' => ( true === ( isset( $consent['measurement'] ) ? $consent['measurement'] : false ) ) ? 1 : 0,
			'a' => ( true === ( isset( $consent['advertising'] ) ? $consent['advertising'] : false ) ) ? 1 : 0,
			'v' => ( isset( $consent['version'] ) && is_string( $consent['version'] ) ) ? $consent['version'] : '',
		);
		if ( 'meta' === $channel ) {
			if ( ! class_exists( 'DoughBoss_Growth_Meta', false ) ) {
				return 'not_configured';
			}
			$keys = DoughBoss_Growth_Meta::match_keys( $stored, $contact, DoughBoss_Growth_Settings::get_all() );
			if ( array() === $keys ) {
				return 'no_match_keys'; // Nothing the platform could match the event to: do not send an empty event.
			}
			$payload = array_merge( $payload, $keys );
		}

		$checked = self::validate_payload( $payload, $channel );
		if ( null === $checked ) {
			// Built by this class from verified fields, so a refusal here is a fault (or a core row of an unexpected shape).
			self::note_failure(
				'conversion_payload_rejected',
				array(
					'channel' => $channel,
					'event'   => $base['event'],
					'stage'   => 'validate',
				)
			);
			return 'rejected';
		}
		$filtered = apply_filters( 'doughboss_growth_conversion_payload', $checked, $channel, $checked['event'] );
		$final    = self::validate_filtered( $filtered, $checked, $channel );
		if ( null === $final ) {
			DoughBoss_Growth_Http::log(
				'conversion_payload_refused',
				array(
					'channel' => $channel,
					'event'   => $checked['event'],
				)
			);
			return 'rejected';
		}
		$status = DoughBoss_Growth_Outbox::enqueue( $channel, $final['event'], $final['event_id'], $subject_type, (string) $subject_id, $final );
		if ( 'error' === $status ) {
			DoughBoss_Growth_Http::log(
				'conversion_enqueue_failed',
				array(
					'channel' => $channel,
					'event'   => $final['event'],
				)
			);
		} elseif ( 'rejected' === $status ) {
			self::note_failure(
				'conversion_payload_rejected',
				array(
					'channel' => $channel,
					'event'   => $final['event'],
					'stage'   => 'enqueue',
				)
			);
		}
		return $status;
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Payload allow-list                                                                           */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Validate a payload against the allow-list for a channel. Unknown keys are dropped. Returns null (refused) when
	 * anything required is missing or malformed, when the input carries personal data, or when a channel-specific key
	 * is on the wrong channel. Used when queueing, on the filter's output, and again by each handler before sending.
	 *
	 * @param mixed  $payload Payload.
	 * @param string $channel ga4 or meta.
	 * @return array|null The clean payload.
	 */
	public static function validate_payload( $payload, $channel ) {
		if ( ! is_array( $payload ) || ! isset( self::CHANNEL_EVENTS[ $channel ] ) ) {
			return null;
		}
		$allow_hashed = ( 'meta' === $channel && 1 === (int) DoughBoss_Growth_Settings::get( 'send_hashed_identifiers', 0 ) );
		if ( DoughBoss_Growth_Outbox::payload_has_pii( $payload, $allow_hashed ) ) {
			return null;
		}
		if ( ! isset( $payload['v'] ) || self::PAYLOAD_VERSION !== $payload['v'] ) {
			return null;
		}
		$event = isset( $payload['event'] ) ? $payload['event'] : null;
		if ( ! is_string( $event ) || ! in_array( $event, self::CHANNEL_EVENTS[ $channel ], true ) ) {
			return null;
		}
		$clean = array(
			'v'     => self::PAYLOAD_VERSION,
			'event' => $event,
		);

		$event_id = isset( $payload['event_id'] ) ? $payload['event_id'] : null;
		$prefixes = array(
			'purchase'      => 'order:',
			'refund'        => 'refund:',
			'generate_lead' => 'lead:',
		);
		if ( ! is_string( $event_id ) || 0 !== strpos( $event_id, $prefixes[ $event ] ) || '' === self::clean_ref( substr( $event_id, strlen( $prefixes[ $event ] ) ) ) ) {
			return null;
		}
		$clean['event_id'] = $event_id;

		if ( ! isset( $payload['event_time'] ) || ! is_int( $payload['event_time'] ) || $payload['event_time'] < 1 ) {
			return null;
		}
		$clean['event_time'] = $payload['event_time'];

		if ( ! isset( $payload['currency'] ) || self::CURRENCY !== $payload['currency'] ) {
			return null;
		}
		$clean['currency'] = self::CURRENCY;

		if ( 'generate_lead' === $event ) {
			if ( ! isset( $payload['form'] ) || self::FORM_CATERING !== $payload['form'] || isset( $payload['value_cents'] ) || isset( $payload['transaction_id'] ) ) {
				return null; // A lead carries no value and no transaction.
			}
			$clean['form'] = self::FORM_CATERING;
		} else {
			$ref = isset( $payload['transaction_id'] ) ? $payload['transaction_id'] : null;
			if ( ! is_string( $ref ) || self::clean_ref( $ref ) !== $ref || substr( $event_id, strlen( $prefixes[ $event ] ) ) !== $ref ) {
				return null;
			}
			$cents = isset( $payload['value_cents'] ) ? $payload['value_cents'] : null;
			if ( ! is_int( $cents ) || $cents < 1 || $cents > self::MAX_CENTS || isset( $payload['form'] ) ) {
				return null;
			}
			$clean['transaction_id'] = $ref;
			$clean['value_cents']    = $cents;
		}

		$consent = isset( $payload['consent'] ) ? $payload['consent'] : null;
		if ( ! is_array( $consent ) || ! isset( $consent['m'], $consent['a'] ) || ! in_array( $consent['m'], array( 0, 1 ), true ) || ! in_array( $consent['a'], array( 0, 1 ), true ) ) {
			return null;
		}
		$version = isset( $consent['v'] ) ? $consent['v'] : '';
		if ( ! is_string( $version ) || 1 !== preg_match( '/\A[A-Za-z0-9._\-]{0,20}\z/', $version ) ) {
			return null;
		}
		$clean['consent'] = array(
			'm' => $consent['m'],
			'a' => $consent['a'],
			'v' => $version,
		);

		foreach ( array( 'fbc', 'em', 'ph' ) as $key ) {
			if ( ! isset( $payload[ $key ] ) ) {
				continue;
			}
			if ( 'meta' !== $channel ) {
				return null; // Advertising match keys never go to GA4.
			}
			$value = $payload[ $key ];
			if ( 'fbc' === $key ) {
				if ( ! is_string( $value ) || 1 !== preg_match( '/\Afb\.1\.[0-9]{10,16}\.[A-Za-z0-9_\-]{10,120}\z/', $value ) ) {
					return null;
				}
			} elseif ( ! $allow_hashed || 1 !== $clean['consent']['a'] || ! is_string( $value ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $value ) ) {
				return null; // Hashed identifiers: only when the setting is on and advertising consent was given.
			}
			$clean[ $key ] = $value;
		}
		return $clean;
	}

	/**
	 * Validate the output of the doughboss_growth_conversion_payload filter against the payload it started from.
	 * Refused when it is not an array, is invalid, adds personal data, or changes the event identity, the consent
	 * flags or the channel-specific keys that decide who may receive it.
	 *
	 * @param mixed  $filtered Filter result.
	 * @param array  $original Payload before the filter (already valid).
	 * @param string $channel  Channel.
	 * @return array|null
	 */
	private static function validate_filtered( $filtered, array $original, $channel ) {
		$clean = self::validate_payload( $filtered, $channel );
		if ( null === $clean ) {
			return null;
		}
		foreach ( array( 'event', 'event_id', 'consent', 'currency' ) as $fixed ) {
			if ( $clean[ $fixed ] !== $original[ $fixed ] ) {
				return null;
			}
		}
		foreach ( array( 'fbc', 'em', 'ph' ) as $key ) {
			$was = array_key_exists( $key, $original ) ? $original[ $key ] : null;
			$now = array_key_exists( $key, $clean ) ? $clean[ $key ] : null;
			if ( $was !== $now ) {
				return null; // A filter may not add, change or remove a match key.
			}
		}
		return $clean;
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Money and encoding                                                                           */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * A decimal amount (core stores money as DECIMAL(10,2), read back as a string or float) as integer cents, without
	 * binary floating-point drift for strings. Half a cent rounds up. Negative, non-numeric, non-finite, scientific
	 * notation and anything above MAX_CENTS give null.
	 *
	 * @param mixed $amount Amount in dollars.
	 * @return int|null
	 */
	public static function to_cents( $amount ) {
		if ( is_int( $amount ) ) {
			$cents = $amount * 100;
		} elseif ( is_float( $amount ) ) {
			if ( is_nan( $amount ) || is_infinite( $amount ) || $amount < 0 || $amount > ( self::MAX_CENTS / 100 ) + 1 ) {
				return null;
			}
			$cents = (int) round( $amount * 100 );
		} elseif ( is_string( $amount ) && 1 === preg_match( '/\A\s*([0-9]{1,8})(?:\.([0-9]{1,6}))?\s*\z/', $amount, $m ) ) {
			$fraction = isset( $m[2] ) ? str_pad( $m[2], 3, '0' ) : '000';
			$cents    = ( (int) $m[1] * 100 ) + (int) substr( $fraction, 0, 2 ) + ( ( (int) $fraction[2] >= 5 ) ? 1 : 0 );
		} else {
			return null;
		}
		if ( $cents < 0 || $cents > self::MAX_CENTS ) {
			return null;
		}
		return $cents;
	}

	/**
	 * Integer cents as the decimal AUD number the platforms take (35.99 for 3599).
	 *
	 * @param int $cents Cents.
	 * @return float
	 */
	public static function cents_to_value( $cents ) {
		return round( ( (int) $cents ) / 100, 2 );
	}

	/**
	 * JSON for a request body. serialize_precision is pinned to -1 while encoding, so a float such as 35.99 is written
	 * as 35.99 whatever the host's php.ini says (17 would write 35.990000000000002).
	 *
	 * @param mixed $data Data.
	 * @return string Empty string when encoding fails.
	 */
	public static function encode_json( $data ) {
		if ( ! function_exists( 'ini_set' ) ) {
			$plain = wp_json_encode( $data ); // Hosts that disable ini_set() keep their own precision.
			return is_string( $plain ) ? $plain : '';
		}
		$previous = ini_get( 'serialize_precision' );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- restored straight after the encode; needed for exact decimal output.
		ini_set( 'serialize_precision', '-1' );
		$json = wp_json_encode( $data );
		if ( false !== $previous ) {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- restoring the host's value.
			ini_set( 'serialize_precision', (string) $previous );
		}
		return is_string( $json ) ? $json : '';
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Logging                                                                                      */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Log a failure without any data: only the stage and the exception class.
	 *
	 * @param string    $stage Where it happened.
	 * @param Throwable $e     The error.
	 * @return void
	 */
	private static function log_failure( $stage, $e ) {
		if ( class_exists( 'DoughBoss_Growth_Http' ) ) {
			DoughBoss_Growth_Http::log(
				'conversions_failed',
				array(
					'stage' => $stage,
					'error' => get_class( $e ),
				)
			);
		}
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Admin tab                                                                                    */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Add the "Conversions" tab. Called from the doughboss_growth_admin_tabs action.
	 *
	 * @return void
	 */
	public static function register_tab() {
		if ( class_exists( 'DoughBoss_Growth_Admin' ) ) {
			DoughBoss_Growth_Admin::add_tab( 'conversions', __( 'Conversions', 'doughboss-growth' ), array( __CLASS__, 'render_tab' ) );
		}
	}

	/**
	 * Owner decisions and unverified facts for this module (admin-only text).
	 *
	 * @return array Code => text beginning "[CONFIRM: ...".
	 */
	public static function confirm_gaps() {
		$settings = DoughBoss_Growth_Settings::get_all();
		$gaps     = array();
		if ( '' === $settings['ga4_measurement_id'] || ! DoughBoss_Growth_Settings::has_secret( 'DOUGHBOSS_GROWTH_GA4_API_SECRET' ) ) {
			$gaps['ga4_destination'] = '[CONFIRM: GA4 measurement id (Settings) and the Measurement Protocol secret (DOUGHBOSS_GROWTH_GA4_API_SECRET) from the property owned by the right entity. GA4 events are not queued until both exist.]';
		}
		if ( '' === $settings['meta_pixel_id'] || ! DoughBoss_Growth_Settings::has_secret( 'DOUGHBOSS_GROWTH_META_CAPI_TOKEN' ) ) {
			$gaps['meta_destination'] = '[CONFIRM: Meta pixel id (Settings) and the Conversions API token (DOUGHBOSS_GROWTH_META_CAPI_TOKEN) from the account owned by the right entity. Meta events are not queued until both exist.]';
		}
		$gaps['privacy_policy']    = '[CONFIRM: the privacy policy must say that order and enquiry details and consented advertising identifiers are sent from the server to Google and Meta before this is enabled.]';
		$gaps['value_basis']       = '[CONFIRM: whether order and quote totals sent as conversion values should include GST. The stored core total is sent as it is.]';
		$gaps['ga4_validation']    = '[CONFIRM: run a sample event through the GA4 Measurement Protocol validation endpoint before relying on it. The live endpoint accepts and silently ignores malformed events.]';
		$gaps['ga4_client_id']     = '[CONFIRM: events are sent with a per-order pseudonymous client id because the browser GA client id is not captured (attribution does not store it). They count as separate users and are not tied to the visitor session.]';
		$gaps['meta_api_version']  = '[CONFIRM: the Meta Graph API version used for the Conversions API (' . ( class_exists( 'DoughBoss_Growth_Meta', false ) ? DoughBoss_Growth_Meta::API_VERSION : 'unknown' ) . ') and the event source URL (the site home page) against the current Meta documentation.]';
		$gaps['ads_names']         = '[CONFIRM: the Google Ads conversion action names in the offline export must match the account exactly.]';
		$gaps['lead_double_count'] = '[CONFIRM: the browser also sends generate_lead through Tag Manager. In GA4 keep only one source (the server or the tag), or the lead counts twice.]';
		return $gaps;
	}

	/**
	 * Counts of outbox rows for this module's channels, by channel and status.
	 *
	 * @return array|null Channel => status => count, or null when storage cannot be read.
	 */
	private static function outbox_counts() {
		global $wpdb;
		$table = DoughBoss_Growth_Outbox::table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT channel, status, COUNT(*) AS n FROM {$table} WHERE channel IN (%s, %s) GROUP BY channel, status ORDER BY channel, status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from the WordPress prefix and a constant.
				'ga4',
				'meta'
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return null;
		}
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['channel'] ][ (string) $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Outbox rows that need a person, for EVERY channel in the queue (this module's two and the waitlist's), by channel and
	 * last error: rows given up on (failed_terminal) and rows that have been waiting over an hour (a retry in progress is
	 * normally minutes, so an hour means something is stuck). last_error is an error code already redacted by the outbox.
	 *
	 * @return array|null List of array( channel, status, last_error, n ), empty when the queue is healthy, or null when it
	 *                    could not be read (the caller must say so rather than show a quiet queue).
	 */
	private static function queue_problems() {
		global $wpdb;
		$table  = DoughBoss_Growth_Outbox::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', DoughBoss_Growth::now() - HOUR_IN_SECONDS );
		$rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT channel, status, last_error, COUNT(*) AS n FROM {$table} WHERE status = %s OR ( status = %s AND created_at < %s ) GROUP BY channel, status, last_error ORDER BY channel, status, n DESC LIMIT 60", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from the WordPress prefix and a constant.
				'failed_terminal',
				'pending',
				$cutoff
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return null;
		}
		return $rows;
	}

	/**
	 * The notice above the queue counts: which channels have messages that were given up on or are stuck, with the last
	 * error. Shown for every channel, not only ga4 and meta. When the check itself cannot run it says so.
	 *
	 * @return void
	 */
	private static function render_queue_problems() {
		$problems = self::queue_problems();
		if ( null === $problems ) {
			echo '<div class="notice notice-warning inline" data-dbgr-queue-problems><p>' . esc_html__( 'The queue could not be checked for messages that did not go through, so the counts below may not show everything.', 'doughboss-growth' ) . '</p></div>';
			return;
		}
		if ( array() === $problems ) {
			return;
		}
		echo '<div class="notice notice-error inline" data-dbgr-queue-problems><p><strong>' . esc_html__( 'Some messages to Google, Meta or other services did not go through.', 'doughboss-growth' ) . '</strong></p><ul class="ul-disc">';
		foreach ( $problems as $row ) {
			$n     = max( 1, (int) $row['n'] );
			$error = ( '' !== (string) $row['last_error'] ) ? (string) $row['last_error'] : __( 'none recorded', 'doughboss-growth' );
			if ( 'failed_terminal' === $row['status'] ) {
				/* translators: 1: number of messages, 2: the last error recorded. */
				$text = sprintf( _n( '%1$d message was given up on and will not be sent again (last error: %2$s).', '%1$d messages were given up on and will not be sent again (last error: %2$s).', $n, 'doughboss-growth' ), $n, $error );
			} else {
				/* translators: 1: number of messages, 2: the last error recorded. */
				$text = sprintf( _n( '%1$d message has been waiting for over an hour (last error: %2$s).', '%1$d messages have been waiting for over an hour (last error: %2$s).', $n, 'doughboss-growth' ), $n, $error );
			}
			echo '<li><code>' . esc_html( (string) $row['channel'] ) . '</code> ' . esc_html( $text ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Render the tab: status, queue counts, gaps and the offline export form. Secrets are never shown, only whether
	 * each is set.
	 *
	 * @return void
	 */
	public static function render_tab() {
		if ( ! DoughBoss_Growth_Admin::user_can_manage() ) {
			return;
		}
		$settings = DoughBoss_Growth_Settings::get_all();
		echo '<h2>' . esc_html__( 'Server-side conversions', 'doughboss-growth' ) . '</h2>';
		echo '<p>' . esc_html__( 'Sends a purchase for a paid order, a refund when a paid order is refunded and a lead for a catering enquiry, from the server. An event is sent to Google Analytics only with measurement consent and to Meta only with advertising consent, using the choice the visitor made. Orders paid at the shop are not purchases until they are paid. No name, email, phone number or address is sent.', 'doughboss-growth' ) . '</p>';

		echo '<table class="widefat striped" style="max-width:640px"><tbody>';
		self::status_row( __( 'Feature', 'doughboss-growth' ), DoughBoss_Growth_Settings::enabled( self::FEATURE ) ? __( 'On', 'doughboss-growth' ) : __( 'Off', 'doughboss-growth' ) );
		self::status_row( __( 'GA4 destination ready', 'doughboss-growth' ), self::destination_ready( 'ga4' ) ? __( 'Yes', 'doughboss-growth' ) : __( 'No', 'doughboss-growth' ) );
		self::status_row( __( 'Meta destination ready', 'doughboss-growth' ), self::destination_ready( 'meta' ) ? __( 'Yes', 'doughboss-growth' ) : __( 'No', 'doughboss-growth' ) );
		self::status_row( __( 'Hashed email and phone (Meta only)', 'doughboss-growth' ), 1 === (int) $settings['send_hashed_identifiers'] ? __( 'On', 'doughboss-growth' ) : __( 'Off', 'doughboss-growth' ) );
		echo '</tbody></table>';

		$counts = self::outbox_counts();
		echo '<h3>' . esc_html__( 'Queue', 'doughboss-growth' ) . '</h3>';
		self::render_queue_problems();
		if ( null === $counts ) {
			echo '<p>' . esc_html__( 'The queue could not be read.', 'doughboss-growth' ) . '</p>';
		} elseif ( array() === $counts ) {
			echo '<p>' . esc_html__( 'Nothing has been queued.', 'doughboss-growth' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>' . esc_html__( 'Channel', 'doughboss-growth' ) . '</th><th>' . esc_html__( 'Status', 'doughboss-growth' ) . '</th><th>' . esc_html__( 'Rows', 'doughboss-growth' ) . '</th></tr></thead><tbody>';
			foreach ( $counts as $channel => $by_status ) {
				foreach ( $by_status as $status => $n ) {
					echo '<tr><td>' . esc_html( $channel ) . '</td><td>' . esc_html( $status ) . '</td><td>' . esc_html( (string) $n ) . '</td></tr>';
				}
			}
			echo '</tbody></table>';
		}

		echo '<h3>' . esc_html__( 'Owner decisions and unverified items', 'doughboss-growth' ) . '</h3><ul class="ul-disc">';
		foreach ( self::confirm_gaps() as $text ) {
			echo '<li>' . esc_html( DoughBoss_Growth_Admin::plain_gap( $text ) ) . '</li>';
		}
		echo '</ul>';

		if ( class_exists( 'DoughBoss_Growth_Offline_Export', false ) ) {
			DoughBoss_Growth_Offline_Export::render_form();
		}
	}

	/**
	 * One read-only status row.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @return void
	 */
	private static function status_row( $label, $value ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}
}
