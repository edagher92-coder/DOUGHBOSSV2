<?php
/**
 * Internal immutable Square catalogue approval evidence and operation receipts.
 *
 * Validates canonical policy/source/request consistency, retained admission and
 * exact reference facts, ownership and durability. It does not attest provider
 * provenance, current credentials/configuration or freshness at a later send.
 * There is no HTTP, worker, key regeneration or verified transition here.
 *
 * @package DoughBoss
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class DoughBoss_Square_Catalogue_Operations {
	const MAX_JSON = 4194304;
	const MAX_TARGET_JSON = 65536;
	const MAX_INTEGER = 9007199254740991;
	private static $transaction_active = false;

	/** Pure packet validation only: no actor, approval, storage, or dispatch permission. */
	public static function validate_packet( array $target, array $request, $provider_key ) {
		try {
			self::target( $target );
			$graph = self::request( $request, $provider_key, $target );
			self::encode( $target, self::MAX_TARGET_JSON );
			self::encode( $request, self::MAX_JSON );
			return $graph;
		} catch ( UnexpectedValueException $error ) { return self::error(); }
	}

	/** Pure normalized source validation shared by native and persisted compilers. */
	public static function validate_source( array $source ) {
		try { self::audit_bound( $source ); return self::source( self::audit_copy( $source ) );
		} catch ( Throwable $error ) { return self::error(); }
	}

	/** Bound and detach policy containers before compiler traversal. */
	public static function validate_policy_input( array $policy ) {
		try { self::audit_bound( $policy ); self::encode( $policy, self::MAX_JSON ); return self::audit_copy( $policy );
		} catch ( Throwable $error ) { return self::error(); }
	}

	/** Pure construction only; provenance and current authority remain service gates. */
	public static function build_approval_evidence( array $intent, $actor ) {
		try {
			self::audit_bound( $intent ); $intent = self::audit_copy( $intent );
			self::keys( $intent, array( 'operation_key', 'provider_idempotency_key', 'source', 'target', 'request', 'approval_facts' ) );
			self::need( self::integer( $actor, false ) && self::hash_value( $intent['operation_key'] ) && self::uuid( $intent['provider_idempotency_key'] ) );
			$source = self::source( $intent['source'] ); self::target( $intent['target'] ); self::request( $intent['request'], $intent['provider_idempotency_key'], $intent['target'] );
			$bindings = array( 'schema_version' => 1, 'operation_key' => $intent['operation_key'], 'provider_idempotency_key' => $intent['provider_idempotency_key'], 'approved_by' => $actor, 'source_algorithm' => $source['algorithm'], 'source_hash' => $source['source_fingerprint'], 'target_hash' => hash( 'sha256', self::encode( $intent['target'], self::MAX_TARGET_JSON ) ), 'request_hash' => hash( 'sha256', self::encode( $intent['request'], self::MAX_JSON ) ) );
			return self::approval_evidence( $intent['approval_facts'], $source, $intent['target'], $intent['request'], $bindings );
		} catch ( Throwable $error ) { return self::error(); }
	}

	/** Actor/bindings are server-derived; observed approval_facts are immutable. */
	public static function prepare( array $intent, callable $precommit_guard = null ) {
		$actor = self::actor();
		if ( is_wp_error( $actor ) ) { return $actor; }
		try {
			self::audit_bound( $intent ); $intent = self::audit_copy( $intent );
			$fields = array( 'operation_key', 'provider_idempotency_key', 'source', 'target', 'request' );
			if ( array_key_exists( 'approval_facts', $intent ) ) { $fields[] = 'approval_facts'; }
			self::keys( $intent, $fields );
			self::need( self::hash_value( $intent['operation_key'] ) && self::uuid( $intent['provider_idempotency_key'] ) );
			$source = self::source( $intent['source'] );
			self::target( $intent['target'] );
			self::request( $intent['request'], $intent['provider_idempotency_key'], $intent['target'] );
			$target_json = self::encode( $intent['target'], self::MAX_TARGET_JSON );
			$request_json = self::encode( $intent['request'], self::MAX_JSON );
			$immutable = array( 'operation_key' => $intent['operation_key'], 'provider_idempotency_key' => $intent['provider_idempotency_key'], 'source_algorithm' => $source['algorithm'], 'source_json' => $source['canonical_json'], 'source_hash' => $source['source_fingerprint'], 'target_json' => $target_json, 'target_hash' => hash( 'sha256', $target_json ), 'request_json' => $request_json, 'request_hash' => hash( 'sha256', $request_json ), 'approved_by' => $actor, 'approval_evidence_json' => null, 'approval_evidence_hash' => null );
			if ( array_key_exists( 'approval_facts', $intent ) ) {
				$evidence = self::build_approval_evidence( $intent, $actor ); self::need( ! is_wp_error( $evidence ) );
				$immutable['approval_evidence_json'] = self::encode( $evidence, self::MAX_JSON );
				$immutable['approval_evidence_hash'] = hash( 'sha256', $immutable['approval_evidence_json'] );
			}
		} catch ( Throwable $error ) { return self::error(); }
		return self::transaction( $actor, static function () use ( $immutable ) {
			global $wpdb;
			// The initial nonlocking lookup avoids competing gap locks on a new
			// operation. UNIQUE insertion arbitrates creation; the final read locks.
			$existing = self::read( $immutable['operation_key'], false );
			if ( is_wp_error( $existing ) ) { return $existing; }
			if ( null === $existing ) {
				$now = self::now();
				$row = array_merge( $immutable, array( 'version' => 1, 'state' => 'prepared', 'approved_at_utc' => $now, 'last_error' => '', 'created_at_utc' => $now, 'updated_at_utc' => $now ) );
				$formats = array();
				foreach ( $row as $field => $value ) { $formats[] = in_array( $field, array( 'approved_by', 'version' ), true ) ? '%d' : '%s'; }
				$wpdb->insert( self::table(), $row, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			// A UNIQUE collision can identify this exact operation, but never alias
			// another operation owning the same provider key.
			$existing = self::read( $immutable['operation_key'], true );
			if ( is_wp_error( $existing ) || null === $existing ) { return self::error(); }
			foreach ( $immutable as $field => $value ) {
				if ( (string) $existing[ $field ] !== (string) $value ) { return self::error(); }
			}
			return $existing;
		}, $precommit_guard );
	}

	/** Internal read only: validated stored row, null when absent, or a generic error. */
	public static function find( $operation_key ) {
		if ( ! self::hash_value( $operation_key ) || ! self::ready() ) { return self::error(); }
		return self::read( $operation_key, false );
	}

	/** Bounded history preflight only; this does not serialize future dispatch. */
	public static function scope_conflict( array $target, $operation_key ) {
		$actor = self::actor();
		if ( is_wp_error( $actor ) ) { return $actor; }
		if ( ! self::hash_value( $operation_key ) || ! self::ready() ) { return self::error(); }
		try { self::target( $target ); } catch ( UnexpectedValueException $error ) { return self::error(); }
		$conflict = self::history_conflict( $target, $operation_key, false );
		if ( $actor !== self::actor() ) { return self::error( 403 ); }
		return $conflict;
	}

	private static function history_conflict( array $target, $operation_key, $include_prepared ) {
		global $wpdb;
		// Prepared intents are not provider-write evidence. Every other state must
		// be reconciled before another key can prepare under the same merchant.
		// A claim also validates prepared history: malformed durable rows are
		// not evidence that their merchant scope is safe to ignore.
		$keys = $wpdb->get_col( 'SELECT operation_key FROM ' . self::table() . ( $include_prepared ? '' : " WHERE state <> 'prepared'" ) . ' ORDER BY id ASC LIMIT 1001' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( '' !== (string) $wpdb->last_error || ! is_array( $keys ) || count( $keys ) > 1000 ) { return self::error(); }
		$conflict = false;
		foreach ( $keys as $key ) {
			if ( ! self::hash_value( $key ) ) { return self::error(); }
			$row = self::read( $key, false );
			if ( is_wp_error( $row ) || null === $row ) { return self::error(); }
			try { $existing = self::decode( $row['target_json'], self::MAX_TARGET_JSON ); } catch ( UnexpectedValueException $error ) { return self::error(); }
			if ( 'prepared' !== $row['state'] && $key !== $operation_key && $existing['merchant_id'] === $target['merchant_id'] && $existing['environment'] === $target['environment'] ) { $conflict = true; }
		}
		return $conflict;
	}

	/** Only a committed winner may be used by a future dispatcher. */
	public static function claim( $operation_key, $expected_version ) {
		$actor = self::actor();
		if ( is_wp_error( $actor ) ) { return $actor; }
		if ( ! self::hash_value( $operation_key ) || ! is_int( $expected_version ) || $expected_version <= 0 || $expected_version >= PHP_INT_MAX ) { return self::error(); }
		return self::transaction( $actor, static function ( $scope ) use ( $operation_key, $expected_version, $actor ) {
			$row = self::read( $operation_key, true );
			if ( is_wp_error( $row ) || null === $row || (int) $row['version'] !== $expected_version || 'prepared' !== $row['state'] ) { return self::error(); }
			if ( (int) $row['approved_by'] !== $actor ) { return self::error( 403 ); }
			if ( null === $row['approval_evidence_json'] ) { return self::error(); }
			$target = self::decode( $row['target_json'], self::MAX_TARGET_JSON );
			if ( self::scope_lock_name( $scope['database_name'], $target ) !== $scope['lock_name'] || false !== self::history_conflict( $target, $operation_key, true ) ) { return self::error(); }
			return self::update( $row, array( 'state' => 'dispatching', 'dispatched_at_utc' => self::now() ), $scope );
		}, null, $operation_key );
	}

	/** Internal generic code only, never provider error bodies or customer details. */
	public static function hold( $operation_key, $expected_version, $error_code ) {
		if ( ! is_string( $error_code ) || ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $error_code ) ) { return self::error(); }
		return self::mutate( $operation_key, $expected_version, static function ( $row ) use ( $error_code ) {
			if ( 'dispatching' !== $row['state'] ) { return self::error(); }
			return self::update( $row, array( 'state' => 'held', 'last_error' => $error_code ) );
		} );
	}

	/** Complete metadata receipt only; held is NOT an attestation of GET readback. */
	public static function record_receipt( $operation_key, $expected_version, array $receipt ) {
		return self::mutate( $operation_key, $expected_version, static function ( $row ) use ( $receipt ) {
			if ( ! in_array( $row['state'], array( 'dispatching', 'held' ), true ) ) { return self::error(); }
			try {
				$target = self::decode( $row['target_json'], self::MAX_TARGET_JSON );
				$graph = self::request( self::decode( $row['request_json'], self::MAX_JSON ), $row['provider_idempotency_key'], $target );
				self::receipt( $receipt, $graph, $target );
				$json = self::encode( $receipt, self::MAX_JSON );
			} catch ( UnexpectedValueException $error ) { return self::error(); }
			if ( null !== $row['receipt_json'] ) { return $json === $row['receipt_json'] ? $row : self::error(); }
			return self::update( $row, array( 'state' => 'held', 'receipt_json' => $json, 'receipt_hash' => hash( 'sha256', $json ), 'last_error' => 'readback_required' ) );
		} );
	}

	/** Canonical v1 evidence; every accepted policy must regenerate the exact wire packet. */
	private static function approval_evidence( $facts, array $source, array $target, array $request, array $bindings ) {
		self::keys( $facts, array( 'policy', 'inventory_baseline', 'reference_facts' ) );
		self::encode( $facts, self::MAX_JSON );
		$packet = DoughBoss_Square_Catalogue_Request::build_from_source( $source, $target, $facts['policy'], $bindings['provider_idempotency_key'] );
		self::need( ! is_wp_error( $packet ) && self::encode( $packet['request'], self::MAX_JSON ) === self::encode( $request, self::MAX_JSON ) );
		$policy = $facts['policy']; sort( $policy['expected_item_ids'], SORT_NUMERIC );
		foreach ( array( 'availability', 'photos' ) as $rows ) { usort( $policy[ $rows ], static function ( $left, $right ) { return $left['website_item_id'] <=> $right['website_item_id']; } ); }
		$baseline = self::approval_baseline( $facts['inventory_baseline'], $source, $target );
		$references = self::approval_references( $facts['reference_facts'], $target, $policy, $baseline );
		$evidence = array_merge( $bindings, array( 'policy' => $policy, 'inventory_baseline' => $baseline, 'reference_facts' => $references ) );
		self::encode( $evidence, self::MAX_JSON );
		return self::canonical( $evidence );
	}

	private static function approval_baseline( $rows, array $source, array $target ) {
		self::list_value( $rows, 4096, false ); $graph = array();
		foreach ( $rows as &$row ) {
			self::keys( $row, array( 'id', 'type', 'version', 'is_deleted', 'presence', 'parent_id', 'category_name' ) );
			self::need( self::provider_id( $row['id'] ) && ! isset( $graph[ $row['id'] ] ) && in_array( $row['type'], array( 'ITEM', 'ITEM_VARIATION', 'MODIFIER_LIST', 'MODIFIER', 'CATEGORY', 'TAX', 'IMAGE' ), true ) );
			self::need( ( null === $row['version'] || self::integer( $row['version'], true ) ) && ( null === $row['is_deleted'] || is_bool( $row['is_deleted'] ) ) );
			self::keys( $row['presence'], array( 'present_at_all_locations', 'present_at_location_ids', 'absent_at_location_ids' ) );
			self::need( null === $row['presence']['present_at_all_locations'] || is_bool( $row['presence']['present_at_all_locations'] ) );
			foreach ( array( 'present_at_location_ids', 'absent_at_location_ids' ) as $field ) {
				if ( null === $row['presence'][ $field ] ) { continue; }
				self::list_value( $row['presence'][ $field ], 1024, false ); self::unique( $row['presence'][ $field ] );
				foreach ( $row['presence'][ $field ] as $id ) { self::need( self::provider_id( $id ) ); }
				sort( $row['presence'][ $field ], SORT_STRING );
			}
			$child = in_array( $row['type'], array( 'ITEM_VARIATION', 'MODIFIER' ), true );
			self::need( $child ? ( null === $row['parent_id'] || self::provider_id( $row['parent_id'] ) ) : null === $row['parent_id'] );
			self::need( 'CATEGORY' === $row['type'] ? ( is_string( $row['category_name'] ) && strlen( $row['category_name'] ) <= 4096 && 1 === preg_match( '//u', $row['category_name'] ) ) : null === $row['category_name'] );
			$data = array();
			if ( $child ) { $data[ 'MODIFIER' === $row['type'] ? 'modifier_list_id' : 'item_id' ] = $row['parent_id']; }
			if ( 'CATEGORY' === $row['type'] ) { $data['name'] = $row['category_name']; }
			$graph[ $row['id'] ] = array( 'id' => $row['id'], 'type' => $row['type'], 'version' => $row['version'], 'is_deleted' => $row['is_deleted'], 'presence' => $row['presence'], 'data' => $data );
		} unset( $row );
		self::need( true === self::validate_admission_graph( $graph, $target, $source ) );
		usort( $rows, static function ( $left, $right ) { return strcmp( $left['id'], $right['id'] ); } );
		return $rows;
	}

	private static function approval_references( $rows, array $target, array $policy, array $baseline ) {
		self::list_value( $rows, 1000, false ); $expected = array_column( $target['existing_references'], null, 'id' ); $seen = array();
		$used = array( $policy['tax']['square_tax_id'] => 'TAX' );
		foreach ( $policy['photos'] as $photo ) { if ( 'omit' !== $photo['policy'] ) { $used[ $photo['square_image_id'] ] = 'IMAGE'; } }
		self::need( count( $rows ) === count( $expected ) && count( $used ) === count( $expected ) );
		$inventory = array_column( $baseline, null, 'id' );
		$tax_fields = array( 'percentage', 'calculation_phase', 'inclusion_type', 'enabled', 'applies_to_custom_amounts', 'applies_to_product_set_id' );
		foreach ( $rows as $row ) {
			self::keys( $row, array( 'id', 'type', 'version', 'is_deleted', 'tax' ) );
			$id = $row['id'];
			self::need( self::provider_id( $id ) && isset( $expected[ $id ], $used[ $id ] ) && ! isset( $seen[ $id ] ) && $row['type'] === $used[ $id ] && $row['type'] === $expected[ $id ]['type'] && $row['version'] === $expected[ $id ]['version'] && false === $row['is_deleted'] );
			if ( 'TAX' === $row['type'] ) { self::keys( $row['tax'], $tax_fields ); foreach ( $tax_fields as $field ) { self::need( $row['tax'][ $field ] === $policy['tax'][ $field ] ); } self::need( null === $row['tax']['applies_to_product_set_id'] );
			} else { self::need( null === $row['tax'] ); }
			if ( isset( $inventory[ $id ] ) && null !== $inventory[ $id ]['version'] ) { self::need( $inventory[ $id ]['version'] === $row['version'] && $inventory[ $id ]['type'] === $row['type'] && true !== $inventory[ $id ]['is_deleted'] ); }
			$seen[ $id ] = true;
		}
		usort( $rows, static function ( $left, $right ) { return strcmp( $left['id'], $right['id'] ); } );
		return $rows;
	}

	/** Shared create-only admission rules for observed and persisted projections. */
	public static function validate_admission_graph( array $graph, array $target, array $source ) {
		try {
			$categories = array_map( array( __CLASS__, 'category_collision_key' ), array_column( $source['items'], 'category' ) );
			foreach ( $graph as $object ) {
				if ( true === $object['is_deleted'] ) { continue; }
				if ( 'CATEGORY' === $object['type'] ) { self::need( is_array( $object['data'] ) && isset( $object['data']['name'] ) && ! in_array( self::category_collision_key( $object['data']['name'] ), $categories, true ) );
				} elseif ( in_array( $object['type'], array( 'ITEM', 'ITEM_VARIATION', 'MODIFIER_LIST', 'MODIFIER' ), true ) ) { foreach ( $target['shops'] as $shop ) { self::need( self::admission_absent( $object, $graph, $shop['square_location_id'] ) ); } }
			}
			return true;
		} catch ( Throwable $error ) { return self::error(); }
	}
	public static function category_collision_key( $name ) {
		self::need( is_string( $name ) && 1 === preg_match( '//u', $name ) );
		$key = preg_replace( '/[\p{Z}\s]+/u', ' ', $name ); self::need( is_string( $key ) );
		return strtr( trim( $key ), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
	}
	private static function admission_absent( array $object, array $graph, $location ) {
		if ( in_array( $object['type'], array( 'MODIFIER', 'ITEM_VARIATION' ), true ) ) {
			$field = 'MODIFIER' === $object['type'] ? 'modifier_list_id' : 'item_id';
			$parent = is_array( $object['data'] ) && isset( $object['data'][ $field ] ) ? $object['data'][ $field ] : null;
			$parent_type = 'MODIFIER' === $object['type'] ? 'MODIFIER_LIST' : 'ITEM';
			if ( ! is_string( $parent ) || ! isset( $graph[ $parent ] ) || $parent_type !== $graph[ $parent ]['type'] ) { return false; }
			$parent_absent = self::admission_absent( $graph[ $parent ], $graph, $location );
			if ( 'MODIFIER' === $object['type'] || $parent_absent ) { return $parent_absent; }
		}
		$presence = $object['presence'];
		$present = is_array( $presence['present_at_location_ids'] ) ? in_array( $location, $presence['present_at_location_ids'], true ) : null;
		$absent = is_array( $presence['absent_at_location_ids'] ) ? in_array( $location, $presence['absent_at_location_ids'], true ) : null;
		if ( true === $present ) { return false; } if ( true === $absent ) { return true; }
		return false === $presence['present_at_all_locations'] && false === $present;
	}

	private static function audit_bound( array $value ) { $nodes = 0; $bytes = 0; self::audit_walk( $value, 0, $nodes, $bytes ); }
	private static function audit_walk( $value, $depth, &$nodes, &$bytes ) {
		self::need( ++$nodes <= 200000 && $depth <= 32 );
		if ( is_array( $value ) ) { self::need( count( $value ) <= 5000 ); foreach ( $value as $key => $entry ) { if ( is_string( $key ) ) { $bytes += strlen( $key ); self::need( strlen( $key ) <= 65536 && 1 === preg_match( '//u', $key ) ); } self::audit_walk( $entry, $depth + 1, $nodes, $bytes ); }
		} elseif ( is_string( $value ) ) { $bytes += strlen( $value ); self::need( strlen( $value ) <= self::MAX_JSON && 1 === preg_match( '//u', $value ) );
		} elseif ( is_int( $value ) ) { self::need( $value >= -self::MAX_INTEGER && $value <= self::MAX_INTEGER );
		} else { self::need( null === $value || is_bool( $value ) ); }
		self::need( $bytes <= 4 * self::MAX_JSON + self::MAX_TARGET_JSON + 4096 );
	}
	private static function audit_copy( array $value ) { $out = array(); foreach ( $value as $key => $entry ) { $out[ $key ] = is_array( $entry ) ? self::audit_copy( $entry ) : $entry; } return $out; }

	private static function table() { global $wpdb; return $wpdb->prefix . 'doughboss_square_catalogue_operations'; }
	private static function ready() { return PHP_INT_SIZE >= 8 && class_exists( 'DoughBoss_Activator' ) && method_exists( 'DoughBoss_Activator', 'catalogue_operations_storage_ready' ) && DoughBoss_Activator::catalogue_operations_storage_ready(); }
	private static function now() { return gmdate( 'Y-m-d H:i:s' ); }
	private static function error( $status = 409 ) { return new WP_Error( 'doughboss_square_catalogue_operation', 'The catalogue operation could not proceed safely. No new dispatch is permitted.', array( 'status' => $status ) ); }
	private static function actor() {
		$actor = get_current_user_id();
		return is_int( $actor ) && $actor > 0 && ( current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' ) ) ? $actor : self::error( 403 );
	}

	private static function mutate( $key, $version, $operation ) {
		$actor = self::actor();
		if ( is_wp_error( $actor ) ) { return $actor; }
		if ( ! self::hash_value( $key ) || ! is_int( $version ) || $version <= 0 || $version >= PHP_INT_MAX ) { return self::error(); }
		return self::transaction( $actor, static function () use ( $key, $version, $operation, $actor ) {
			$row = self::read( $key, true );
			if ( is_wp_error( $row ) || null === $row || (int) $row['version'] !== $version ) { return self::error(); }
			if ( (int) $row['approved_by'] !== $actor ) { return self::error( 403 ); }
			return $operation( $row );
		} );
	}

	/** No provider I/O or emitted hooks. Failed/ambiguous COMMIT grants no claim. */
	private static function transaction( $actor, $operation, callable $precommit_guard = null, $claim_key = null ) {
		global $wpdb;
		if ( self::$transaction_active ) { return self::error(); }
		self::$transaction_active = true;
		$committed = false; $started = false; $locked = false; $scope = null; $session = null;
		try {
			if ( ! self::ready() ) { return self::error(); }
			$session = self::session();
			if ( is_wp_error( $session ) ) { return $session; }
			// START TRANSACTION implicitly commits an existing transaction. SET
			// TRANSACTION instead fails inside one on MySQL and MariaDB, including
			// an otherwise idle transaction. Never roll back a caller's transaction.
			$isolated = $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! is_int( $isolated ) || $isolated < 0 || '' !== (string) $wpdb->last_error ) { return self::error(); }
			if ( null !== $claim_key ) {
				$row = self::read( $claim_key, false );
				if ( is_wp_error( $row ) || null === $row ) { return self::error(); }
				$scope = $session;
				$scope['lock_name'] = self::scope_lock_name( $session['database_name'], self::decode( $row['target_json'], self::MAX_TARGET_JSON ) );
				// GET_LOCK is recursive on supported servers. Reject a lock already
				// owned by this connection instead of borrowing/releasing its count.
				$owner = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $scope['lock_name'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( '' !== (string) $wpdb->last_error || ( null !== $owner && ( ! self::db_integer( $owner ) || (string) $owner === $session['connection_id'] ) ) ) { return self::error(); }
				$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 1)', $scope['lock_name'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$locked = '1' === (string) $acquired;
				if ( ! $locked || '' !== (string) $wpdb->last_error || ! self::scope_owned( $scope ) ) { return self::error(); }
			}
			if ( ! self::same_session( $session ) ) { return self::error(); }
			$start = $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$started = is_int( $start ) && $start >= 0 && '' === (string) $wpdb->last_error;
			if ( ! $started || ! self::same_session( $session ) || ( null !== $scope && ! self::scope_owned( $scope ) ) ) { return self::error(); }
			$result = $operation( $scope );
			if ( is_wp_error( $result ) ) { return $result; }
			if ( ! is_array( $result ) ) { return self::error(); }
			$current = self::actor();
			if ( is_wp_error( $current ) || $current !== $actor ) { return self::error( 403 ); }
			// Internal service authority only, never a caller-supplied callback.
			if ( null !== $precommit_guard && true !== $precommit_guard() ) { return self::error( 403 ); }
			if ( $actor !== self::actor() ) { return self::error( 403 ); }
			if ( ! self::same_session( $session ) || ( null !== $scope && ! self::scope_owned( $scope ) ) ) { return self::error(); }
			$commit = $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! is_int( $commit ) || $commit < 0 || '' !== (string) $wpdb->last_error || ! self::same_session( $session ) || ( null !== $scope && ! self::scope_owned( $scope ) ) ) { return self::error(); }
			$committed = true;
			return $result;
		} catch ( Throwable $error ) { return self::error();
		} finally {
			$released = true;
			try {
				if ( $started && ! $committed && self::same_session( $session ) ) { $wpdb->query( 'ROLLBACK' ); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( $locked ) {
					$release = $wpdb->get_var( $wpdb->prepare( 'SELECT IF(CONNECTION_ID() = %d, RELEASE_LOCK(%s), NULL)', $session['connection_id'], $scope['lock_name'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$released = '1' === (string) $release && '' === (string) $wpdb->last_error && self::same_session( $session );
				}
			} catch ( Throwable $error ) { $released = false; }
			self::$transaction_active = false;
			if ( ! $released ) { return self::error(); }
		}
	}

	/** Server-global names must isolate databases, WP tables and merchant scopes. */
	private static function scope_lock_name( $database, array $target ) {
		self::need( is_string( $database ) && '' !== $database );
		self::target( $target );
		$tuple = self::encode( array( $database, self::table(), $target['merchant_id'], $target['environment'] ), self::MAX_TARGET_JSON );
		return 'dbsqcat:' . substr( hash( 'sha256', $tuple ), 0, 55 );
	}

	private static function session() {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT DATABASE() AS database_name, CONNECTION_ID() AS connection_id, @@SESSION.autocommit AS autocommit', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) || ! isset( $row['database_name'], $row['connection_id'], $row['autocommit'] ) || ! is_string( $row['database_name'] ) || '' === $row['database_name'] || ! self::db_integer( $row['connection_id'] ) || '1' !== (string) $row['autocommit'] ) { return self::error(); }
		return array( 'database_name' => $row['database_name'], 'connection_id' => (string) $row['connection_id'] );
	}

	private static function same_session( array $session ) {
		$current = self::session();
		return ! is_wp_error( $current ) && $current === $session;
	}

	private static function scope_owned( array $scope ) {
		global $wpdb;
		$owned = $wpdb->get_var( $wpdb->prepare( 'SELECT IF(CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = %d, 1, 0)', $scope['connection_id'], $scope['lock_name'], $scope['connection_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return '1' === (string) $owned && '' === (string) $wpdb->last_error;
	}

	private static function read( $key, $locked ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE operation_key = %s LIMIT 1' . ( $locked ? ' FOR UPDATE' : '' ), $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( '' !== (string) $wpdb->last_error ) { return self::error(); }
		if ( null === $row ) { return null; }
		try { self::stored( $row ); return $row;
		} catch ( UnexpectedValueException $error ) { return self::error(); }
	}

	private static function update( array $row, array $changes, array $scope = null ) {
		global $wpdb;
		$next = array_merge( $row, $changes, array( 'version' => (int) $row['version'] + 1, 'updated_at_utc' => self::now() ) );
		try { self::stored( $next ); } catch ( UnexpectedValueException $error ) { return self::error(); }
		$changes['version'] = $next['version'];
		$changes['updated_at_utc'] = $next['updated_at_utc'];
		$formats = array();
		foreach ( $changes as $field => $value ) { $formats[] = 'version' === $field ? '%d' : '%s'; }
		if ( null === $scope ) {
			$updated = $wpdb->update( self::table(), $changes, array( 'id' => $row['id'], 'operation_key' => $row['operation_key'], 'version' => $row['version'], 'state' => $row['state'] ), $formats, array( '%d', '%s', '%d', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			// wpdb may reconnect and retry a failed query. The write itself must
			// deny that new session, not merely check ownership before the write.
			$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET state = %s, dispatched_at_utc = %s, version = %d, updated_at_utc = %s WHERE id = %d AND operation_key = %s AND version = %d AND state = %s AND CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = %d', $changes['state'], $changes['dispatched_at_utc'], $changes['version'], $changes['updated_at_utc'], $row['id'], $row['operation_key'], $row['version'], $row['state'], $scope['connection_id'], $scope['lock_name'], $scope['connection_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( 1 !== $updated || '' !== (string) $wpdb->last_error ) { return self::error(); }
		$stored = self::read( $row['operation_key'], true );
		return ! is_wp_error( $stored ) && is_array( $stored ) && (int) $stored['version'] === $next['version'] ? $stored : self::error();
	}

	/** Stored hashes, immutable schema, and state invariants are checked on every read. */
	private static function stored( $row ) {
		self::keys( $row, array( 'id', 'operation_key', 'provider_idempotency_key', 'version', 'state', 'source_algorithm', 'source_json', 'source_hash', 'target_json', 'target_hash', 'request_json', 'request_hash', 'approved_by', 'approved_at_utc', 'receipt_json', 'receipt_hash', 'approval_evidence_json', 'approval_evidence_hash', 'readback_json', 'readback_hash', 'last_error', 'created_at_utc', 'updated_at_utc', 'dispatched_at_utc', 'verified_at_utc' ) );
		// Legacy approval evidence stays NULL; v1 is fully revalidated below.
		// Readback remains reserved until independent GET verification exists.
		self::need( null === $row['readback_json'] && null === $row['readback_hash'] );
		self::need( ( null === $row['approval_evidence_json'] ) === ( null === $row['approval_evidence_hash'] ) );
		self::need( self::db_integer( $row['id'] ) && self::db_integer( $row['version'] ) && self::db_integer( $row['approved_by'] ) && self::hash_value( $row['operation_key'] ) && self::uuid( $row['provider_idempotency_key'] ) );
		self::need( in_array( $row['state'], array( 'prepared', 'dispatching', 'held', 'verified' ), true ) );
		foreach ( array( 'approved_at_utc', 'created_at_utc', 'updated_at_utc' ) as $field ) { self::need( self::timestamp( $row[ $field ] ) ); }
		self::need( is_string( $row['last_error'] ) && ( '' === $row['last_error'] || preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $row['last_error'] ) ) );
		foreach ( array( 'source' => self::MAX_JSON, 'target' => self::MAX_TARGET_JSON, 'request' => self::MAX_JSON ) as $kind => $limit ) {
			self::need( is_string( $row[ $kind . '_json' ] ) && strlen( $row[ $kind . '_json' ] ) <= $limit && self::hash_value( $row[ $kind . '_hash' ] ) && hash_equals( $row[ $kind . '_hash' ], hash( 'sha256', $row[ $kind . '_json' ] ) ) );
		}
		$source = self::source( array( 'algorithm' => $row['source_algorithm'], 'canonical_json' => $row['source_json'], 'source_fingerprint' => $row['source_hash'], 'items' => self::decode( $row['source_json'], self::MAX_JSON ) ) );
		$target = self::decode( $row['target_json'], self::MAX_TARGET_JSON );
		self::target( $target );
		$graph = self::request( self::decode( $row['request_json'], self::MAX_JSON ), $row['provider_idempotency_key'], $target );
		if ( null !== $row['approval_evidence_json'] ) {
			self::need( is_string( $row['approval_evidence_json'] ) && strlen( $row['approval_evidence_json'] ) <= self::MAX_JSON && self::hash_value( $row['approval_evidence_hash'] ) && hash_equals( $row['approval_evidence_hash'], hash( 'sha256', $row['approval_evidence_json'] ) ) );
			$evidence = self::decode( $row['approval_evidence_json'], self::MAX_JSON ); self::audit_bound( $evidence );
			self::keys( $evidence, array( 'schema_version', 'operation_key', 'provider_idempotency_key', 'approved_by', 'source_algorithm', 'source_hash', 'target_hash', 'request_hash', 'policy', 'inventory_baseline', 'reference_facts' ) );
			$bindings = array( 'schema_version' => 1, 'operation_key' => $row['operation_key'], 'provider_idempotency_key' => $row['provider_idempotency_key'], 'approved_by' => (int) $row['approved_by'], 'source_algorithm' => $row['source_algorithm'], 'source_hash' => $row['source_hash'], 'target_hash' => $row['target_hash'], 'request_hash' => $row['request_hash'] );
			$validated = self::approval_evidence( array( 'policy' => $evidence['policy'], 'inventory_baseline' => $evidence['inventory_baseline'], 'reference_facts' => $evidence['reference_facts'] ), $source, $target, self::decode( $row['request_json'], self::MAX_JSON ), $bindings );
			self::need( self::encode( $validated, self::MAX_JSON ) === $row['approval_evidence_json'] );
		}
		self::need( ( null === $row['receipt_json'] ) === ( null === $row['receipt_hash'] ) );
		if ( null !== $row['receipt_json'] ) {
			self::need( self::hash_value( $row['receipt_hash'] ) && is_string( $row['receipt_json'] ) && strlen( $row['receipt_json'] ) <= self::MAX_JSON && hash_equals( $row['receipt_hash'], hash( 'sha256', $row['receipt_json'] ) ) );
			self::receipt( self::decode( $row['receipt_json'], self::MAX_JSON ), $graph, $target );
		}
		if ( 'prepared' === $row['state'] ) { self::need( null === $row['dispatched_at_utc'] && null === $row['receipt_json'] && '' === $row['last_error'] );
		} else { self::need( self::timestamp( $row['dispatched_at_utc'] ) ); }
		if ( 'dispatching' === $row['state'] ) { self::need( null === $row['receipt_json'] && '' === $row['last_error'] ); }
		if ( 'verified' === $row['state'] ) { self::need( null !== $row['receipt_json'] && self::timestamp( $row['verified_at_utc'] ) );
		} else { self::need( null === $row['verified_at_utc'] ); }
	}

	/** Revalidate the normalizer result, not an arbitrary uploaded JSON blob. */
	private static function source( $snapshot ) {
		self::keys( $snapshot, array( 'algorithm', 'canonical_json', 'source_fingerprint', 'items' ) );
		self::need( $snapshot['algorithm'] === DoughBoss_Square_Catalogue_Source::ALGORITHM && is_string( $snapshot['canonical_json'] ) && strlen( $snapshot['canonical_json'] ) <= self::MAX_JSON && self::hash_value( $snapshot['source_fingerprint'] ) && hash_equals( $snapshot['source_fingerprint'], hash( 'sha256', $snapshot['canonical_json'] ) ) );
		self::list_value( $snapshot['items'], 5000, true );
		$raw = array();
		foreach ( $snapshot['items'] as $item ) {
			self::keys( $item, array( 'website_item_id', 'name', 'description', 'category', 'type', 'base_price_minor', 'image_url', 'public_availability', 'global_availability_only', 'dietary', 'modifier_groups' ) );
			self::need( self::integer( $item['website_item_id'], false ) && true === $item['global_availability_only'] );
			self::list_value( $item['modifier_groups'], 5000, false );
			$options = array();
			foreach ( $item['modifier_groups'] as $group ) {
				self::keys( $group, array( 'id', 'source_id', 'label', 'type', 'choices' ) );
				self::need( is_string( $group['source_id'] ) && $group['id'] === 'item_' . $item['website_item_id'] . '_option_' . $group['source_id'] );
				self::list_value( $group['choices'], 5000, true );
				$choices = array();
				foreach ( $group['choices'] as $choice ) {
					self::keys( $choice, array( 'id', 'slug', 'label', 'price_delta_minor', 'default' ) );
					self::need( is_string( $choice['slug'] ) && $choice['id'] === $group['id'] . '_choice_' . $choice['slug'] );
					$choices[] = array( 'slug' => $choice['slug'], 'label' => $choice['label'], 'price' => self::minor_text( $choice['price_delta_minor'] ), 'default' => $choice['default'] );
				}
				$options[] = array( 'id' => $group['source_id'], 'label' => $group['label'], 'type' => $group['type'], 'choices' => $choices );
			}
			$raw[] = array( 'id' => $item['website_item_id'], 'name' => $item['name'], 'description' => $item['description'], 'category' => $item['category'], 'type' => $item['type'], 'price' => self::minor_text( $item['base_price_minor'] ), 'image' => $item['image_url'], 'available' => $item['public_availability'], 'dietary' => $item['dietary'], 'options' => $options );
		}
		$normalized = DoughBoss_Square_Catalogue_Source::snapshot( $raw );
		self::need( ! is_wp_error( $normalized ) && $normalized['canonical_json'] === $snapshot['canonical_json'] && self::encode( $snapshot['items'], self::MAX_JSON ) === $snapshot['canonical_json'] );
		return $normalized;
	}

	private static function target( $target ) {
		self::keys( $target, array( 'merchant_id', 'environment', 'application_id', 'api_version', 'shops', 'mapping_policy', 'existing_references' ) );
		self::need( self::provider_id( $target['merchant_id'] ) && self::provider_id( $target['application_id'] ) && in_array( $target['environment'], array( 'test', 'live' ), true ) && 'create_only' === $target['mapping_policy'] );
		self::need( is_string( $target['api_version'] ) && preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $target['api_version'], $date ) && checkdate( (int) $date[2], (int) $date[3], (int) $date[1] ) );
		self::list_value( $target['shops'], 3, true );
		$native = array(); $slugs = array(); $locations = array();
		foreach ( $target['shops'] as $shop ) {
			self::keys( $shop, array( 'id', 'slug', 'square_location_id' ) );
			self::need( self::integer( $shop['id'], false ) && in_array( $shop['slug'], array( 'revesby', 'bankstown', 'roselands' ), true ) && self::provider_id( $shop['square_location_id'] ) );
			self::need( ! isset( $native[ $shop['id'] ] ) && ! isset( $slugs[ $shop['slug'] ] ) && ! isset( $locations[ $shop['square_location_id'] ] ) );
			$native[ $shop['id'] ] = true; $slugs[ $shop['slug'] ] = true; $locations[ $shop['square_location_id'] ] = true;
		}
		self::list_value( $target['existing_references'], 1000, false );
		$refs = array();
		foreach ( $target['existing_references'] as $ref ) {
			self::keys( $ref, array( 'id', 'type', 'version' ) );
			self::need( self::provider_id( $ref['id'] ) && in_array( $ref['type'], array( 'TAX', 'IMAGE', 'CATEGORY' ), true ) && self::integer( $ref['version'], false ) && ! isset( $refs[ $ref['id'] ] ) );
			$refs[ $ref['id'] ] = $ref['type'];
		}
	}

	private static function request( $request, $key, array $target ) {
		self::keys( $request, array( 'idempotency_key', 'batches' ) );
		self::need( $request['idempotency_key'] === $key && self::uuid( $key ) );
		self::list_value( $request['batches'], 1, true );
		self::keys( $request['batches'][0], array( 'objects' ) );
		self::list_value( $request['batches'][0]['objects'], 1000, true );
		$locations = array_column( $target['shops'], 'square_location_id' );
		$graph = array();
		foreach ( $request['batches'][0]['objects'] as $object ) { self::object( $object, null, null, $locations, $graph ); }
		$existing = array_column( $target['existing_references'], 'type', 'id' );
		foreach ( $graph as $id => $entry ) {
			if ( 'ITEM' !== $entry['type'] ) { continue; }
			$data = $entry['data'];
			foreach ( array( 'tax_ids' => 'TAX', 'image_ids' => 'IMAGE' ) as $field => $type ) {
				self::list_value( $data[ $field ], 1000, false );
				self::unique( $data[ $field ] );
				foreach ( $data[ $field ] as $ref ) { self::need( self::provider_id( $ref ) && isset( $existing[ $ref ] ) && $existing[ $ref ] === $type ); }
			}
			self::list_value( $data['categories'], 1000, false );
			$category_ids = array();
			foreach ( $data['categories'] as $category ) {
				self::keys( $category, array( 'id' ) );
				$ref = $category['id'];
				self::need( ( self::temp_id( $ref ) && isset( $graph[ $ref ] ) && 'CATEGORY' === $graph[ $ref ]['type'] ) || ( self::provider_id( $ref ) && isset( $existing[ $ref ] ) && 'CATEGORY' === $existing[ $ref ] ) );
				$category_ids[] = $ref;
			}
			self::unique( $category_ids );
			self::list_value( $data['modifier_list_info'], 1000, false );
			$list_ids = array();
			foreach ( $data['modifier_list_info'] as $info ) {
				self::keys( $info, array( 'modifier_list_id', 'min_selected_modifiers', 'max_selected_modifiers', 'enabled', 'modifier_overrides' ) );
				$list = $info['modifier_list_id'];
				self::need( self::temp_id( $list ) && isset( $graph[ $list ] ) && 'MODIFIER_LIST' === $graph[ $list ]['type'] && true === $info['enabled'] && self::integer( $info['min_selected_modifiers'], true ) && self::integer( $info['max_selected_modifiers'], false ) && $info['max_selected_modifiers'] >= $info['min_selected_modifiers'] );
				$list_ids[] = $list;
				$children = array_column( $graph[ $list ]['data']['modifiers'], 'id' );
				self::list_value( $info['modifier_overrides'], 1000, true );
				$override_ids = array(); $defaults = 0;
				foreach ( $info['modifier_overrides'] as $override ) {
					self::keys( $override, array( 'modifier_id', 'on_by_default' ) );
					self::need( self::temp_id( $override['modifier_id'] ) && in_array( $override['modifier_id'], $children, true ) && is_bool( $override['on_by_default'] ) );
					$override_ids[] = $override['modifier_id']; $defaults += $override['on_by_default'] ? 1 : 0;
				}
				self::unique( $override_ids ); sort( $override_ids, SORT_STRING ); sort( $children, SORT_STRING );
				self::need( $override_ids === $children );
				if ( 'SINGLE' === $graph[ $list ]['data']['selection_type'] ) { self::need( 1 === $info['min_selected_modifiers'] && 1 === $info['max_selected_modifiers'] && 1 === $defaults );
				} else { self::need( 0 === $info['min_selected_modifiers'] && count( $children ) === $info['max_selected_modifiers'] ); }
			}
			self::unique( $list_ids );
		}
		return $graph;
	}

	private static function object( $object, $expected_type, $parent, array $locations, array &$graph ) {
		self::need( is_array( $object ) && isset( $object['type'] ) && is_string( $object['type'] ) );
		$type = $object['type']; $field = strtolower( $type ) . '_data';
		$common = array( 'id', 'type', $field );
		if ( 'MODIFIER' !== $type ) { $common[] = 'present_at_all_locations'; }
		if ( 'MODIFIER' !== $type && 'CATEGORY' !== $type ) { $common[] = 'present_at_location_ids'; $common[] = 'absent_at_location_ids'; }
		self::keys( $object, $common );
		self::need( self::temp_id( $object['id'] ) && ! isset( $graph[ $object['id'] ] ) && count( $graph ) < 1000 && ( null === $expected_type ? in_array( $type, array( 'ITEM', 'MODIFIER_LIST', 'CATEGORY' ), true ) : $type === $expected_type ) );
		$presence = null;
		if ( 'CATEGORY' === $type ) { self::need( true === $object['present_at_all_locations'] );
		} elseif ( 'MODIFIER' === $type ) { $presence = $graph[ $parent ]['presence'];
		} else {
			self::need( false === $object['present_at_all_locations'] );
			self::list_value( $object['present_at_location_ids'], 3, false ); self::unique( $object['present_at_location_ids'] );
			foreach ( $object['present_at_location_ids'] as $location ) {
				self::need( in_array( $location, $locations, true ) && ( 'ITEM_VARIATION' !== $type || in_array( $location, $graph[ $parent ]['presence'], true ) ) );
			}
			self::need( array() === $object['absent_at_location_ids'] );
			$presence = $object['present_at_location_ids'];
		}
		$data = $object[ $field ];
		$fields = array( 'ITEM' => array( 'name', 'description_html', 'variations', 'modifier_list_info', 'tax_ids', 'image_ids', 'categories' ), 'ITEM_VARIATION' => array( 'name', 'item_id', 'pricing_type', 'price_money' ), 'MODIFIER_LIST' => array( 'name', 'selection_type', 'modifiers' ), 'MODIFIER' => array( 'name', 'modifier_list_id', 'price_money' ), 'CATEGORY' => array( 'name' ) );
		self::keys( $data, $fields[ $type ] ); self::name( $data['name'] );
		$graph[ $object['id'] ] = array( 'type' => $type, 'data' => $data, 'presence' => $presence );
		if ( 'ITEM' === $type ) {
			self::need( is_string( $data['description_html'] ) && strlen( $data['description_html'] ) <= 30000 && 1 === preg_match( '//u', $data['description_html'] ) && false === strpos( $data['description_html'], '<' ) && false === strpos( $data['description_html'], '>' ) );
			self::list_value( $data['variations'], 1000, true );
			foreach ( $data['variations'] as $child ) { self::object( $child, 'ITEM_VARIATION', $object['id'], $locations, $graph ); }
		} elseif ( 'MODIFIER_LIST' === $type ) {
			self::need( in_array( $data['selection_type'], array( 'SINGLE', 'MULTIPLE' ), true ) );
			self::list_value( $data['modifiers'], 1000, true );
			foreach ( $data['modifiers'] as $child ) { self::object( $child, 'MODIFIER', $object['id'], $locations, $graph ); }
		} elseif ( 'ITEM_VARIATION' === $type || 'MODIFIER' === $type ) {
			self::need( $data[ 'ITEM_VARIATION' === $type ? 'item_id' : 'modifier_list_id' ] === $parent );
			if ( 'ITEM_VARIATION' === $type ) { self::need( 'FIXED_PRICING' === $data['pricing_type'] ); }
			self::keys( $data['price_money'], array( 'amount', 'currency' ) );
			self::need( self::integer( $data['price_money']['amount'], true ) && 'AUD' === $data['price_money']['currency'] );
		}
	}

	private static function receipt( $receipt, array $graph, array $target ) {
		self::keys( $receipt, array( 'id_mappings', 'objects' ) );
		self::list_value( $receipt['id_mappings'], 1000, true ); self::list_value( $receipt['objects'], 1000, true );
		self::need( count( $receipt['id_mappings'] ) === count( $graph ) && count( $receipt['objects'] ) === count( $graph ) );
		$mapping = array(); $ids = array(); $existing = array_column( $target['existing_references'], 'type', 'id' );
		foreach ( $receipt['id_mappings'] as $entry ) {
			self::keys( $entry, array( 'client_object_id', 'object_id' ) );
			$client = $entry['client_object_id']; $id = $entry['object_id'];
			self::need( self::temp_id( $client ) && isset( $graph[ $client ] ) && self::provider_id( $id ) && ! isset( $mapping[ $client ] ) && ! isset( $ids[ $id ] ) && ! isset( $existing[ $id ] ) );
			$mapping[ $client ] = $id; $ids[ $id ] = $graph[ $client ]['type'];
		}
		$seen = array();
		foreach ( $receipt['objects'] as $object ) {
			self::keys( $object, array( 'id', 'type', 'version' ) );
			self::need( self::provider_id( $object['id'] ) && isset( $ids[ $object['id'] ] ) && ! isset( $seen[ $object['id'] ] ) && $ids[ $object['id'] ] === $object['type'] && self::integer( $object['version'], false ) );
			$seen[ $object['id'] ] = true;
		}
	}

	private static function need( $condition ) { if ( ! $condition ) { throw new UnexpectedValueException( 'Invalid catalogue operation.' ); } }
	private static function keys( $value, array $keys ) { self::need( is_array( $value ) ); $actual = array_keys( $value ); sort( $actual, SORT_STRING ); sort( $keys, SORT_STRING ); self::need( $actual === $keys ); }
	private static function list_value( $value, $max, $nonempty ) { self::need( is_array( $value ) && array_values( $value ) === $value && count( $value ) <= $max && ( ! $nonempty || count( $value ) > 0 ) ); }
	private static function unique( array $values ) { foreach ( $values as $value ) { self::need( is_string( $value ) ); } self::need( count( array_unique( $values, SORT_STRING ) ) === count( $values ) ); }
	private static function hash_value( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value ); }
	private static function uuid( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value ); }
	private static function provider_id( $value ) { return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,191}$/D', $value ); }
	private static function temp_id( $value ) { return is_string( $value ) && strlen( $value ) <= 191 && 1 === preg_match( '/^#[a-z][a-z0-9_-]*$/D', $value ); }
	private static function integer( $value, $zero ) { return is_int( $value ) && $value >= ( $zero ? 0 : 1 ) && $value <= self::MAX_INTEGER; }
	private static function db_integer( $value ) {
		if ( ! is_int( $value ) && ! is_string( $value ) ) { return false; }
		$text = (string) $value; $max = (string) ( PHP_INT_MAX - 1 );
		return 1 === preg_match( '/^[1-9][0-9]*$/D', $text ) && ( strlen( $text ) < strlen( $max ) || ( strlen( $text ) === strlen( $max ) && strcmp( $text, $max ) <= 0 ) );
	}
	private static function minor_text( $value ) { self::need( self::integer( $value, true ) ); return intdiv( $value, 100 ) . '.' . str_pad( (string) ( $value % 100 ), 2, '0', STR_PAD_LEFT ); }
	private static function name( $value ) {
		self::need( is_string( $value ) && '' !== $value && 1 === preg_match( '//u', $value ) );
		$count = preg_match_all( '/./us', $value, $characters ); self::need( false !== $count );
		foreach ( $characters[0] as $character ) { if ( 4 === strlen( $character ) ) { ++$count; } }
		self::need( $count <= 200 );
	}
	private static function timestamp( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value ) ) { return false; }
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value;
	}
	private static function canonical( array $value ) { if ( array_values( $value ) !== $value ) { ksort( $value, SORT_STRING ); } foreach ( $value as $key => $entry ) { if ( is_array( $entry ) ) { $value[ $key ] = self::canonical( $entry ); } } return $value; }
	private static function encode( array $value, $limit ) { $json = json_encode( self::canonical( $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS ); self::need( is_string( $json ) && strlen( $json ) <= $limit ); return $json; }
	private static function decode( $json, $limit ) { self::need( is_string( $json ) && strlen( $json ) <= $limit ); $value = json_decode( $json, true, 128 ); self::need( JSON_ERROR_NONE === json_last_error() && is_array( $value ) ); return $value; }
}
