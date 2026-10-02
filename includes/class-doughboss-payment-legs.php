<?php
/**
 * Inert Square split-funding ledger. Records facts, never dispatches or releases.
 * Provider authenticity and current shop/approval authority belong to the future
 * transport boundary. No caller of this class obtains payment/fulfillment proof.
 *
 * @package DoughBoss
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class DoughBoss_Payment_Legs {

	public static function sets_table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_payment_sets';
	}

	public static function legs_table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_payment_legs';
	}

	/** Stable server leg identity, not a browser nonce or provider source token. */
	public static function leg_reference( $parent_id, $index ) {
		return is_int( $parent_id ) && $parent_id > 0 && is_int( $index ) && in_array( $index, array( 0, 1 ), true )
			? 'db-split-' . $parent_id . '-' . $index : '';
	}

	/** Called by the parent repository; bind before any immutable set exists. */
	public static function bind_order( $attempt_id, $reference, array $expected ) {
		global $wpdb;
		if ( ! is_int( $attempt_id ) || $attempt_id <= 0 || ! self::identity( $reference ) || ! isset( $expected['id'] ) || (string) $attempt_id !== (string) $expected['id']
			|| ! array_key_exists( 'provider_order_reference', $expected ) || ( null !== $expected['provider_order_reference'] && $reference !== $expected['provider_order_reference'] ) ) {
			return false;
		}
		return self::transaction( static function () use ( $expected, $reference, $attempt_id, $wpdb ) {
			$parent = self::lock_parent( $expected, false );
			if ( false === $parent || ( null !== $parent['provider_order_reference'] && $reference !== $parent['provider_order_reference'] ) ) { return false; }
			if ( null !== $parent['provider_order_reference'] ) { return $parent; }
			$set = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::sets_table() . ' WHERE payment_attempt_id = %d FOR UPDATE', $attempt_id ), ARRAY_A );
			if ( null !== $set || '' !== $wpdb->last_error ) { return false; }
			$ok = $wpdb->query( $wpdb->prepare( 'UPDATE ' . DoughBoss_Payment_Attempts::table() . ' SET provider_order_reference = %s, updated_at = %s WHERE id = %d AND status = %s AND provider_reference IS NULL AND provider_order_reference IS NULL', $reference, self::now(), $attempt_id, 'dispatching' ) );
			if ( 1 !== $ok ) { return false; }
			$bound = DoughBoss_Payment_Attempts::find( $attempt_id );
			return is_array( $bound ) && $reference === $bound['provider_order_reference'] ? $bound : false;
		} );
	}

	/** Prepare exactly one gift plan; unique parent ownership serializes replay. */
	public static function prepare( array $expected ) {
		global $wpdb;
		return self::transaction( static function () use ( $expected, $wpdb ) {
			$parent = self::lock_parent( $expected );
			if ( false === $parent ) { return false; }
			$plan = self::lock_plan( $parent );
			if ( false === $plan ) { return false; }
			if ( null !== $plan['set'] ) { return $plan; }
			$hash = self::binding_hash( $parent );
			$time = self::now();
			$ok = $wpdb->insert( self::sets_table(), array(
				'payment_attempt_id' => (int) $parent['id'], 'version' => 1, 'state' => 'funding', 'binding_hash' => $hash,
				'pay_order_key' => hash( 'sha256', 'square-split-pay-order|' . $parent['attempt_key'] ),
				'created_at_utc' => $time, 'updated_at_utc' => $time,
			), array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' ) );
			if ( 1 !== $ok || ! self::insert_leg( $parent, $hash, 0, (int) $parent['amount_minor'] ) ) { return false; }
			return self::lock_plan( $parent );
		} );
	}

	/** A successful claim is irreversible; clocks never grant a second owner. */
	public static function claim( array $expected, $version, $index ) {
		return self::mutate( $expected, $version, static function ( $parent, $plan ) use ( $index ) {
			if ( ! self::index( $index ) || 'funding' !== $plan['set']['state'] || ! isset( $plan['legs'][ $index ] ) || 'planned' !== $plan['legs'][ $index ]['state'] ) { return false; }
			return self::update_leg( $plan['legs'][ $index ], array( 'state' => 'claimed' ) ) && self::bump( $plan['set'] );
		} );
	}

	/** Pure validation: an observation is NOT authentic provider or paid proof. */
	public static function validate_observation( array $parent, array $leg, array $payment ) {
		$index = isset( $leg['leg_index'] ) ? self::db_integer( $leg['leg_index'], 1, true ) : null;
		$id = isset( $parent['id'] ) ? self::db_integer( $parent['id'], PHP_INT_MAX ) : null;
		$requested = isset( $leg['requested_amount_minor'] ) ? self::db_integer( $leg['requested_amount_minor'], DoughBoss_Square_Orders::MAX_MONEY ) : null;
		$metadata = isset( $parent['safe_metadata_json'] ) && is_string( $parent['safe_metadata_json'] ) ? json_decode( $parent['safe_metadata_json'], true ) : null;
		$money = isset( $payment['amount_money'] ) && is_array( $payment['amount_money'] ) ? $payment['amount_money'] : array();
		$details = isset( $payment['card_details'] ) && is_array( $payment['card_details'] ) ? $payment['card_details'] : array();
		$card = isset( $details['card'] ) && is_array( $details['card'] ) ? $details['card'] : array();
		if ( null === $index || null === $id || null === $requested || ! is_array( $metadata )
			|| ! isset( $leg['payment_attempt_id'], $leg['tender_kind'], $leg['currency'], $parent['currency'], $parent['provider_order_reference'], $metadata['square_location_id'] )
			|| (string) $id !== (string) $leg['payment_attempt_id'] || $leg['tender_kind'] !== ( 0 === $index ? 'gift_card' : 'card' )
			|| $leg['currency'] !== $parent['currency'] || ! is_string( $leg['currency'] ) || 1 !== preg_match( '/^[A-Z]{3}\z/', $leg['currency'] )
			|| ! isset( $payment['id'], $payment['order_id'], $payment['location_id'], $payment['reference_id'], $payment['status'], $payment['source_type'], $details['status'], $card['card_brand'], $money['amount'], $money['currency'] )
			|| ! self::identity( $payment['id'] ) || ! self::identity( $parent['provider_order_reference'] )
			|| $payment['order_id'] !== $parent['provider_order_reference'] || $payment['location_id'] !== $metadata['square_location_id']
			|| $payment['reference_id'] !== self::leg_reference( $id, $index ) || 'APPROVED' !== $payment['status'] || 'CARD' !== $payment['source_type'] || 'AUTHORIZED' !== $details['status']
			|| ! is_string( $card['card_brand'] ) || 1 !== preg_match( '/^[A-Z][A-Z0-9_]{1,31}\z/', $card['card_brand'] )
			|| ( 0 === $index ? 'SQUARE_GIFT_CARD' !== $card['card_brand'] : 'SQUARE_GIFT_CARD' === $card['card_brand'] )
			|| ! is_int( $money['amount'] ) || $money['amount'] <= 0 || $money['amount'] > $requested || $money['currency'] !== $leg['currency']
			|| ( 1 === $index && $money['amount'] !== $requested ) || ! empty( $payment['errors'] ) ) { return false; }
		return array( 'provider_payment_reference' => $payment['id'], 'authorized_amount_minor' => $money['amount'] );
	}

	/** Retain exact APPROVED facts, including callbacks arriving after cancellation. */
	public static function observe( array $expected, $version, $index, array $payment ) {
		return self::mutate( $expected, $version, static function ( $parent, $plan ) use ( $index, $payment ) {
			if ( ! self::index( $index ) || ! isset( $plan['legs'][ $index ] ) ) { return false; }
			$leg = $plan['legs'][ $index ];
			$facts = self::validate_observation( $parent, $leg, $payment );
			if ( false === $facts ) { return false; }
			if ( 'authorized' === $leg['state'] ) {
				return $leg['provider_payment_reference'] === $facts['provider_payment_reference'] && (int) $leg['authorized_amount_minor'] === $facts['authorized_amount_minor'];
			}
			if ( ! in_array( $leg['state'], array( 'claimed', 'unknown' ), true ) || null !== $leg['provider_payment_reference'] || null !== $leg['authorized_amount_minor'] ) { return false; }
			$facts['state'] = 'authorized';
			$facts['last_error'] = '';
			return self::update_leg( $leg, $facts ) && self::bump( $plan['set'], 'held' === $plan['set']['state'] ? 'funding' : $plan['set']['state'] );
		} );
	}

	/** Derive the one optional bank-card leg from the locked gift authorization. */
	public static function prepare_remainder( array $expected, $version, array $gift ) {
		return self::mutate( $expected, $version, static function ( $parent, $plan ) use ( $gift ) {
			if ( 'funding' !== $plan['set']['state'] || ! isset( $plan['legs'][0] ) || $gift !== $plan['legs'][0] || 'authorized' !== $gift['state'] ) { return false; }
			$remaining = (int) $parent['amount_minor'] - (int) $gift['authorized_amount_minor'];
			if ( 0 === $remaining ) { return 1 === count( $plan['legs'] ); }
			if ( $remaining < 0 ) { return false; }
			if ( isset( $plan['legs'][1] ) ) { return $remaining === (int) $plan['legs'][1]['requested_amount_minor']; }
			return self::insert_leg( $parent, $plan['set']['binding_hash'], 1, $remaining ) && self::bump( $plan['set'] );
		} );
	}

	/** Ambiguous outcomes retain the claimed identity and block new dispatch. */
	public static function hold_unknown( array $expected, $version, $index, $error ) {
		if ( ! in_array( $error, array( 'authorization_unknown', 'authorization_timeout', 'authorization_response_invalid', 'provider_unavailable' ), true ) ) { return false; }
		return self::mutate( $expected, $version, static function ( $parent, $plan ) use ( $index, $error ) {
			if ( ! self::index( $index ) || ! isset( $plan['legs'][ $index ] ) || ! in_array( $plan['legs'][ $index ]['state'], array( 'claimed', 'unknown' ), true ) ) { return false; }
			$leg = $plan['legs'][ $index ];
			if ( 'unknown' === $leg['state'] && $error === $leg['last_error'] ) { return true; }
			return self::update_leg( $leg, array( 'state' => 'unknown', 'last_error' => $error ) ) && self::bump( $plan['set'], 'canceling' === $plan['set']['state'] ? 'canceling' : 'held' );
		} );
	}

	/** A durable tombstone, not a claim that money has been voided or refunded. */
	public static function request_cancellation( array $expected, $version ) {
		return self::mutate( $expected, $version, static function ( $parent, $plan ) {
			return 'canceling' === $plan['set']['state'] || self::bump( $plan['set'], 'canceling' );
		} );
	}

	private static function mutate( array $expected, $version, $operation ) {
		if ( ! is_int( $version ) || $version <= 0 || $version >= PHP_INT_MAX ) { return false; }
		return self::transaction( static function () use ( $expected, $version, $operation ) {
			$parent = self::lock_parent( $expected );
			$plan = false !== $parent ? self::lock_plan( $parent ) : false;
			if ( false === $plan || null === $plan['set'] || $version !== (int) $plan['set']['version'] || ! $operation( $parent, $plan ) ) { return false; }
			return self::lock_plan( $parent );
		} );
	}

	/** No I/O or hooks in transactions; failed final COMMIT always rolls back. */
	private static function transaction( $operation ) {
		global $wpdb;
		if ( PHP_INT_SIZE < 8 || ! DoughBoss_Activator::payment_storage_ready() || ! DoughBoss_Activator::payment_ledger_storage_ready() || false === $wpdb->query( 'START TRANSACTION' ) ) { return false; }
		$committed = false;
		try {
			$result = $operation();
			if ( false === $result || false === $wpdb->query( 'COMMIT' ) ) { return false; }
			$committed = true;
			return $result;
		} finally {
			if ( ! $committed ) { $wpdb->query( 'ROLLBACK' ); }
		}
	}

	/** Parent and retained snapshot are the only economic/identity authorities. */
	private static function lock_parent( array $expected, $bound = true ) {
		global $wpdb;
		$id = isset( $expected['id'] ) ? self::db_integer( $expected['id'], PHP_INT_MAX ) : null;
		if ( null === $id ) { return false; }
		$parent = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DoughBoss_Payment_Attempts::table() . ' WHERE id = %d LIMIT 1 FOR UPDATE', $id ), ARRAY_A );
		if ( ! is_array( $parent ) || 'square' !== $parent['provider'] || 'order' !== $parent['purpose'] || 'dispatching' !== $parent['status'] || null !== $parent['provider_reference'] || ! in_array( $parent['context'], array( 'web', 'approved_preorder' ), true )
			|| ! isset( $expected['status'] ) || 'dispatching' !== $expected['status'] || ! array_key_exists( 'provider_reference', $expected ) || null !== $expected['provider_reference'] ) { return false; }
		foreach ( array( 'id', 'attempt_key', 'checkout_key', 'provider', 'purpose', 'context', 'local_reference', 'location_id', 'table_id', 'qr_code_id', 'amount_minor', 'currency', 'safe_metadata_json' ) as $field ) {
			if ( ! isset( $expected[ $field ], $parent[ $field ] ) || ! is_scalar( $expected[ $field ] ) || (string) $expected[ $field ] !== (string) $parent[ $field ] ) { return false; }
		}
		if ( $bound && ( ! self::identity( $parent['provider_order_reference'] ) || ! isset( $expected['provider_order_reference'] ) || $expected['provider_order_reference'] !== $parent['provider_order_reference'] ) ) { return false; }
		$meta = json_decode( $parent['safe_metadata_json'], true );
		if ( ! is_array( $meta ) || ! isset( $meta['protocol_version'], $meta['orders_protocol'], $meta['orders_snapshot_hash'], $meta['binding_hash'], $meta['square_shop_slug'], $meta['square_location_id'], $meta['square_mode'], $meta['square_tender_kind'] )
			|| 'square-v2' !== $meta['protocol_version'] || 'square-split-v1' !== $meta['orders_protocol'] || 'gift_card' !== $meta['square_tender_kind']
			|| ! self::hash_value( $meta['orders_snapshot_hash'] ) || ! self::hash_value( $meta['binding_hash'] )
			|| ! in_array( $meta['square_shop_slug'], array( 'revesby', 'bankstown', 'roselands' ), true ) || ! in_array( $meta['square_mode'], array( 'test', 'live' ), true )
			|| ! is_string( $meta['square_location_id'] ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{4,64}\z/', $meta['square_location_id'] )
			|| null === self::db_integer( $parent['amount_minor'], DoughBoss_Square_Orders::MAX_MONEY ) ) { return false; }
		$snapshot = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'doughboss_checkout_snapshots WHERE checkout_key = %s LIMIT 1 FOR UPDATE', $parent['checkout_key'] ), ARRAY_A );
		if ( ! is_array( $snapshot ) || 'prepared' !== $snapshot['status'] || 0 !== (int) $snapshot['order_id'] || ! is_string( $snapshot['payload_json'] )
			|| $meta['orders_snapshot_hash'] !== $snapshot['payload_hash'] || ! hash_equals( $meta['orders_snapshot_hash'], hash( 'sha256', $snapshot['payload_json'] ) ) ) { return false; }
		$payload = json_decode( $snapshot['payload_json'], true );
		if ( ! is_array( $payload ) || ! isset( $payload['order']['location_id'], $payload['binding_hash'] ) || (int) $parent['location_id'] !== $payload['order']['location_id'] || $meta['binding_hash'] !== $payload['binding_hash'] ) { return false; }
		$binding = array( 'shop_slug' => $meta['square_shop_slug'], 'square_location_id' => $meta['square_location_id'], 'square_mode' => $meta['square_mode'] );
		if ( isset( $payload['square_binding'] ) && $binding !== $payload['square_binding'] ) { return false; }
		if ( 'approved_preorder' === $parent['context'] ) {
			$a = isset( $payload['approval'] ) && is_array( $payload['approval'] ) ? $payload['approval'] : array();
			if ( ! isset( $a['request_id'], $a['approved_revision'], $a['request_fingerprint'], $meta['approval_id'], $meta['approved_revision'], $meta['approval_fingerprint'] )
				|| ! is_int( $a['request_id'] ) || $a['request_id'] <= 0 || ! is_int( $a['approved_revision'] ) || $a['approved_revision'] <= 0 || ! self::hash_value( $a['request_fingerprint'] )
				|| $a['request_id'] !== $meta['approval_id'] || $a['approved_revision'] !== $meta['approved_revision'] || $a['request_fingerprint'] !== $meta['approval_fingerprint'] ) { return false; }
		} elseif ( array_key_exists( 'approval', $payload ) ) { return false; }
		$projection = DoughBoss_Square_Orders::project( $payload, $binding, $id );
		return ! is_wp_error( $projection ) && $projection['amounts']['total'] === (int) $parent['amount_minor'] && $projection['currency'] === $parent['currency'] ? $parent : false;
	}

	private static function binding_hash( array $parent ) {
		$tuple = array();
		foreach ( array( 'id', 'attempt_key', 'checkout_key', 'provider', 'purpose', 'context', 'local_reference', 'location_id', 'table_id', 'qr_code_id', 'amount_minor', 'currency', 'safe_metadata_json', 'provider_order_reference' ) as $field ) { $tuple[ $field ] = (string) $parent[ $field ]; }
		return hash( 'sha256', wp_json_encode( $tuple ) );
	}

	/** All reads are locking reads: parent -> set -> ascending legs. */
	private static function lock_plan( array $parent ) {
		global $wpdb;
		$set = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::sets_table() . ' WHERE payment_attempt_id = %d FOR UPDATE', $parent['id'] ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) { return false; }
		$legs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::legs_table() . ' WHERE payment_attempt_id = %d ORDER BY leg_index ASC FOR UPDATE', $parent['id'] ), ARRAY_A );
		if ( ! is_array( $legs ) || '' !== $wpdb->last_error || ( null === $set && count( $legs ) > 0 ) ) { return false; }
		if ( null === $set ) { return array( 'set' => null, 'legs' => array() ); }
		$hash = self::binding_hash( $parent );
		if ( ! is_array( $set ) || $hash !== $set['binding_hash'] || null === self::db_integer( $set['version'], PHP_INT_MAX - 1 )
			|| ! in_array( $set['state'], array( 'funding', 'held', 'canceling' ), true ) || $set['pay_order_key'] !== hash( 'sha256', 'square-split-pay-order|' . $parent['attempt_key'] ) || count( $legs ) < 1 || count( $legs ) > 2 ) { return false; }
		foreach ( $legs as $index => $leg ) {
			$requested = self::db_integer( $leg['requested_amount_minor'], DoughBoss_Square_Orders::MAX_MONEY );
			$authorized = null === $leg['authorized_amount_minor'] ? null : self::db_integer( $leg['authorized_amount_minor'], DoughBoss_Square_Orders::MAX_MONEY );
			if ( (string) $index !== (string) $leg['leg_index'] || $hash !== $leg['binding_hash'] || $leg['currency'] !== $parent['currency'] || $leg['tender_kind'] !== ( 0 === $index ? 'gift_card' : 'card' )
				|| null === $requested || $leg['dispatch_key'] !== self::dispatch_key( $parent, $index ) || ! in_array( $leg['state'], array( 'planned', 'claimed', 'unknown', 'authorized' ), true )
				|| ( 0 === $index && $requested !== (int) $parent['amount_minor'] )
				|| ( 'authorized' === $leg['state'] ? ( null === $authorized || $authorized > $requested || ! self::identity( $leg['provider_payment_reference'] ) || ( 1 === $index && $authorized !== $requested ) ) : ( null !== $leg['authorized_amount_minor'] || null !== $leg['provider_payment_reference'] ) )
				|| ( 1 === $index && ( 'authorized' !== $legs[0]['state'] || $requested !== (int) $parent['amount_minor'] - (int) $legs[0]['authorized_amount_minor'] ) ) ) { return false; }
		}
		return array( 'set' => $set, 'legs' => $legs );
	}

	private static function insert_leg( array $parent, $hash, $index, $amount ) {
		global $wpdb;
		if ( ! is_int( $amount ) || $amount <= 0 || $amount > DoughBoss_Square_Orders::MAX_MONEY ) { return false; }
		return 1 === $wpdb->insert( self::legs_table(), array(
			'payment_attempt_id' => (int) $parent['id'], 'leg_index' => $index, 'binding_hash' => $hash, 'tender_kind' => 0 === $index ? 'gift_card' : 'card',
			'requested_amount_minor' => $amount, 'currency' => $parent['currency'], 'dispatch_key' => self::dispatch_key( $parent, $index ),
			'created_at_utc' => self::now(), 'updated_at_utc' => self::now(),
		), array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ) );
	}

	private static function dispatch_key( array $parent, $index ) { return hash( 'sha256', 'square-split-authorize|' . $parent['attempt_key'] . '|' . $index ); }

	private static function update_leg( array $leg, array $changes ) {
		global $wpdb;
		$changes['updated_at_utc'] = self::now();
		$formats = array();
		foreach ( $changes as $field => $value ) { $formats[] = 'authorized_amount_minor' === $field ? '%d' : '%s'; }
		return 1 === $wpdb->update( self::legs_table(), $changes, array( 'id' => $leg['id'], 'state' => $leg['state'] ), $formats, array( '%d', '%s' ) );
	}

	private static function bump( array $set, $state = null ) {
		global $wpdb;
		return 1 === $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::sets_table() . ' SET version = version + 1, state = %s, updated_at_utc = %s WHERE payment_attempt_id = %d AND version = %d', null === $state ? $set['state'] : $state, self::now(), $set['payment_attempt_id'], $set['version'] ) );
	}

	private static function db_integer( $value, $max, $zero = false ) {
		if ( ! is_int( $value ) && ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]*)\z/', $value ) ) ) { return null; }
		$text = (string) $value;
		$limit = (string) $max;
		if ( strlen( $text ) > strlen( $limit ) || ( strlen( $text ) === strlen( $limit ) && strcmp( $text, $limit ) > 0 ) ) { return null; }
		$number = (int) $value;
		return $number >= ( $zero ? 0 : 1 ) ? $number : null;
	}

	private static function index( $value ) { return is_int( $value ) && in_array( $value, array( 0, 1 ), true ); }
	private static function identity( $value ) { return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_-]{8,191}\z/', $value ) && 1 !== preg_match( '/^[0-9]{13,19}\z/', $value ); }
	private static function hash_value( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}\z/', $value ); }
	private static function now() { return gmdate( 'Y-m-d H:i:s' ); }
}
