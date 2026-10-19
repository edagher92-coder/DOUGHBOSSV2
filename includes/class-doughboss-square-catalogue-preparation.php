<?php
/** Owner-authorized prepare-only admission. No provider write or dispatch authority. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class DoughBoss_Square_Catalogue_Preparation {
	/** Callbacks are controller-owned trusted boundaries, never supplied by a request. */
	public static function prepare( array $approval, callable $read_native_menu, callable $authorize ) {
		try {
			$actor = get_current_user_id();
			if ( ! self::authorized( $actor, $authorize ) ) { return self::error( 403 ); }
			self::keys( $approval, array( 'operation_key', 'target', 'policy' ) );
			self::need( is_string( $approval['operation_key'] ) && preg_match( '/^[a-f0-9]{64}$/D', $approval['operation_key'] ) && is_array( $approval['target'] ) && is_array( $approval['policy'] ) );
			$target = $approval['target']; $policy = $approval['policy'];
			self::need( isset( $target['api_version'] ) && '2025-01-23' === $target['api_version'] );
			$existing = DoughBoss_Square_Catalogue_Operations::find( $approval['operation_key'] );
			self::need( ! is_wp_error( $existing ) );
			if ( null !== $existing ) {
				self::need( is_array( $existing ) && (int) $existing['approved_by'] === $actor && 'prepared' === $existing['state'] && null !== $existing['approval_evidence_json'] );
				$key = $existing['provider_idempotency_key'];
			} else { $key = wp_generate_uuid4(); }
			$menu = $read_native_menu();
			self::need( is_array( $menu ) && count( $menu ) < 200 );
			$packet = DoughBoss_Square_Catalogue_Request::build( $menu, $target, $policy, $key );
			self::need( ! is_wp_error( $packet ) );
			$references = self::used_references( $target, $policy );
			if ( null !== $existing ) { self::retry( $existing, $packet, $actor ); }
			$context = self::context( $target );
			self::need( false === DoughBoss_Square_Catalogue_Operations::scope_conflict( $target, $approval['operation_key'] ) );
			$inventory = null; $reference_facts = null; $expiries = array();
			foreach ( $target['shops'] as $shop ) {
				if ( ! self::authorized( $actor, $authorize ) ) { return self::error( 403 ); }
				self::need( $context === self::context( $target ) );
				$expiries[] = self::identity( DoughBoss_Square::observe_catalogue_identity( $shop['id'] ), $target, $shop );
				if ( ! self::authorized( $actor, $authorize ) ) { return self::error( 403 ); }
				self::need( $context === self::context( $target ) );
				$observed = DoughBoss_Square::observe_catalogue( $shop['id'] );
				$current_inventory = self::catalogue( $observed, $target, $shop );
				if ( null !== $inventory ) { self::need( self::json( $inventory ) === self::json( $current_inventory ) ); }
				$inventory = $current_inventory;
				if ( ! self::authorized( $actor, $authorize ) ) { return self::error( 403 ); }
				self::need( $context === self::context( $target ) );
				$observed_refs = self::references( DoughBoss_Square::observe_catalogue_references( $shop['id'], $references ), $target, $shop, $references, $policy );
				if ( null !== $reference_facts ) { self::need( self::json( $reference_facts ) === self::json( $observed_refs ) ); }
				$reference_facts = $observed_refs;
				self::need( $context === self::context( $target ) );
			}
			self::conflicts( $inventory, $target, $packet['source'] );
			foreach ( $reference_facts as $id => $reference ) {
				if ( isset( $inventory[ $id ] ) && null !== $inventory[ $id ]['version'] ) { self::need( $inventory[ $id ]['version'] === $reference['version'] && $inventory[ $id ]['type'] === $reference['type'] && true !== $inventory[ $id ]['is_deleted'] ); }
			}
			self::fresh_source( $read_native_menu, $packet['source'] );
			self::need( $context === self::context( $target ) && false === DoughBoss_Square_Catalogue_Operations::scope_conflict( $target, $approval['operation_key'] ) );
			if ( ! self::authorized( $actor, $authorize ) ) { return self::error( 403 ); }
			self::need( $context === self::context( $target ) );
			$precommit_guard = static function () use ( $actor, $authorize, $context, $target, $read_native_menu, $packet, $expiries ) {
				try {
					self::fresh_source( $read_native_menu, $packet['source'] );
					if ( ! self::authorized( $actor, $authorize ) || $context !== self::context( $target ) ) { return false; }
					self::expiries( $expiries );
					return self::authorized( $actor, $authorize );
				} catch ( Throwable $error ) { return false; }
			};
			$facts = array( 'policy' => $policy, 'inventory_baseline' => self::inventory_baseline( $inventory ), 'reference_facts' => self::reference_facts( $reference_facts ) );
			$row = DoughBoss_Square_Catalogue_Operations::prepare( array( 'operation_key' => $approval['operation_key'], 'provider_idempotency_key' => $key, 'source' => $packet['source'], 'target' => $target, 'request' => $packet['request'], 'approval_facts' => $facts ), $precommit_guard );
			if ( is_wp_error( $row ) ) { $data = $row->get_error_data(); return self::error( is_array( $data ) && isset( $data['status'] ) && 403 === $data['status'] ? 403 : 409 ); }
			self::need( is_array( $row ) && 'prepared' === $row['state'] && (int) $row['approved_by'] === $actor );
			self::fresh_source( $read_native_menu, $packet['source'] );
			self::need( $context === self::context( $target ) );
			if ( ! self::authorized( $actor, $authorize ) ) { return self::error( 403 ); }
			self::expiries( $expiries );
			return array( 'operation_key' => $row['operation_key'], 'state' => $row['state'], 'version' => (int) $row['version'], 'approved_at' => $row['approved_at_utc'],
				'source_hash' => $row['source_hash'], 'target_hash' => $row['target_hash'], 'request_hash' => $row['request_hash'], 'counts' => $packet['counts'], 'replayed' => null !== $existing, 'dispatch_allowed' => false );
		} catch ( Throwable $error ) { return self::error(); }
	}

	private static function authorized( $actor, callable $authorize ) {
		return is_int( $actor ) && $actor > 0 && $actor === get_current_user_id() && ( current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' ) ) && true === $authorize();
	}

	/** Transient context and its internal version are never returned, persisted or logged. */
	private static function context( array $target ) {
		global $wpdb;
		$tables = array( $wpdb->options, $wpdb->prefix . 'doughboss_locations' );
		$engines = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name IN (%s,%s)', $tables ), ARRAY_A );
		self::need( empty( $wpdb->last_error ) && is_array( $engines ) && 2 === count( $engines ) );
		foreach ( $engines as $engine ) { self::need( isset( $engine['Engine'] ) && 'INNODB' === strtoupper( $engine['Engine'] ) ); }
		// A current locking read bypasses both WP option caches and an existing RR read view.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1 FOR UPDATE", DoughBoss_Settings::OPTION_KEY ) );
		self::need( empty( $wpdb->last_error ) && is_string( $raw ) );
		$stored = maybe_unserialize( $raw );
		self::need( is_array( $stored ) && $stored === get_option( DoughBoss_Settings::OPTION_KEY, array() ) );
		$currency = DoughBoss_Settings::get( 'currency_code', 'AUD' );
		self::need( is_string( $currency ) && DoughBoss_Settings::square_access_token_valid() && 'AUD' === strtoupper( $currency )
			&& $target['environment'] === DoughBoss_Settings::square_mode() && $target['application_id'] === DoughBoss_Settings::square_application_id() && $target['api_version'] === DoughBoss_Settings::square_api_version() );
		$locations = $wpdb->get_results( "SELECT id,slug,is_active FROM {$wpdb->prefix}doughboss_locations WHERE slug IN ('revesby','bankstown','roselands') ORDER BY id FOR UPDATE" );
		self::need( empty( $wpdb->last_error ) && is_array( $locations ) );
		$bindings = array();
		foreach ( $target['shops'] as $shop ) {
			$matches = array_values( array_filter( $locations, static function ( $location ) use ( $shop ) { return isset( $location->slug ) && $shop['slug'] === $location->slug; } ) );
			self::need( 1 === count( $matches ) && (int) $matches[0]->id === $shop['id'] && 1 === (int) $matches[0]->is_active );
			$binding = DoughBoss_Square::shop_binding( $shop['id'] );
			self::need( ! is_wp_error( $binding ) && $binding === array( 'shop_slug' => $shop['slug'], 'square_location_id' => $shop['square_location_id'], 'square_mode' => $target['environment'] ) && empty( $wpdb->last_error ) );
			$bindings[] = $binding;
		}
		return array( 'settings_version' => hash( 'sha256', $raw ), 'token' => DoughBoss_Settings::square_access_token(), 'app' => DoughBoss_Settings::square_application_id(), 'mode' => DoughBoss_Settings::square_mode(), 'pin' => DoughBoss_Settings::square_api_version(), 'currency' => DoughBoss_Settings::get( 'currency_code', 'AUD' ), 'bindings' => $bindings );
	}

	private static function used_references( array $target, array $policy ) {
		$used = array( $policy['tax']['square_tax_id'] => 'TAX' );
		foreach ( $policy['photos'] as $photo ) { if ( 'omit' !== $photo['policy'] ) { $used[ $photo['square_image_id'] ] = 'IMAGE'; } }
		$references = array();
		foreach ( $target['existing_references'] as $reference ) {
			self::need( isset( $used[ $reference['id'] ] ) && $used[ $reference['id'] ] === $reference['type'] && ! isset( $references[ $reference['id'] ] ) );
			$references[ $reference['id'] ] = $reference;
		}
		self::need( count( $references ) === count( $used ) );
		ksort( $references, SORT_STRING ); return array_values( $references );
	}

	private static function retry( array $row, array $packet, $actor ) {
		self::need( (int) $row['approved_by'] === $actor && 'prepared' === $row['state'] && $row['provider_idempotency_key'] === $packet['request']['idempotency_key']
			&& $row['source_algorithm'] === $packet['source']['algorithm'] && $row['source_json'] === $packet['source']['canonical_json'] && $row['source_hash'] === $packet['source']['source_fingerprint']
			&& $row['target_json'] === self::json( $packet['target'] ) && $row['request_json'] === self::json( $packet['request'] ) );
	}

	private static function identity( $view, array $target, array $shop ) {
		self::need( is_array( $view ) && isset( $view['schema_version'], $view['data_state'], $view['merchant_id'], $view['application_id'], $view['environment'], $view['api_version'], $view['shop'], $view['scopes'], $view['expires_at'] )
			&& 1 === $view['schema_version'] && 'credential_identity_observed' === $view['data_state'] && $view['merchant_id'] === $target['merchant_id']
			&& $view['application_id'] === $target['application_id'] && $view['environment'] === $target['environment'] && $view['api_version'] === $target['api_version'] && is_array( $view['shop'] ) && self::json( $view['shop'] ) === self::json( $shop ) && is_array( $view['scopes'] ) );
		foreach ( array( 'MERCHANT_PROFILE_READ', 'ITEMS_READ', 'ITEMS_WRITE' ) as $scope ) { self::need( in_array( $scope, $view['scopes'], true ) ); }
		self::expiries( array( $view['expires_at'] ) ); return $view['expires_at'];
	}

	/** Recheck observed expiration at commit/disclosure; never invent a minimum TTL. */
	private static function expiries( array $expiries ) {
		foreach ( $expiries as $expiry ) {
			self::need( is_string( $expiry ) ); if ( '' === $expiry ) { continue; }
			self::need( preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.[0-9]{1,9})?(Z|[+-]([0-9]{2}):([0-9]{2}))$/D', $expiry, $parts )
				&& checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) && (int) $parts[4] <= 23 && (int) $parts[5] <= 59 && (int) $parts[6] <= 59
				&& ( 'Z' === $parts[7] || ( (int) $parts[8] <= 23 && (int) $parts[9] <= 59 ) ) );
			$seconds = strtotime( preg_replace( '/\.[0-9]{1,9}(?=Z|[+-])/', '', $expiry ) );
			self::need( false !== $seconds && $seconds > time() );
		}
	}

	private static function catalogue( $view, array $target, array $shop ) {
		self::need( is_array( $view ) && isset( $view['schema_version'], $view['data_state'], $view['environment'], $view['shop'], $view['location'], $view['catalogue']['objects'] ) && is_array( $view['location'] )
			&& isset( $view['location']['id'], $view['location']['status'], $view['location']['currency'], $view['location']['merchant_id'] )
			&& 1 === $view['schema_version'] && 'catalogue_observed' === $view['data_state'] && $view['environment'] === $target['environment']
			&& $view['shop'] === array( 'id' => $shop['id'], 'slug' => $shop['slug'] ) && $view['location']['id'] === $shop['square_location_id'] && 'ACTIVE' === $view['location']['status']
			&& 'AUD' === $view['location']['currency'] && $view['location']['merchant_id'] === $target['merchant_id'] );
		$objects = $view['catalogue']['objects'];
		self::need( is_array( $objects ) && array_values( $objects ) === $objects && count( $objects ) <= DoughBoss_Square::CATALOGUE_MAX_TOP_LEVEL );
		$graph = array(); $count = 0;
		foreach ( $objects as $object ) { self::collect( $object, $graph, $count, false ); }
		ksort( $graph, SORT_STRING ); return $graph;
	}

	private static function collect( $object, array &$graph, &$count, $nested ) {
		self::need( ++$count <= DoughBoss_Square::CATALOGUE_MAX_OCCURRENCES && is_array( $object ) && isset( $object['id'], $object['type'], $object['embedded_objects'] ) && is_string( $object['id'] ) && is_array( $object['embedded_objects'] ) );
		if ( isset( $graph[ $object['id'] ] ) ) { self::need( self::json( $graph[ $object['id'] ] ) === self::json( $object ) ); }
		$graph[ $object['id'] ] = $object;
		self::need( ! $nested || 0 === count( $object['embedded_objects'] ) );
		foreach ( $object['embedded_objects'] as $child ) { self::collect( $child, $graph, $count, true ); }
	}

	private static function references( $view, array $target, array $shop, array $references, array $policy ) {
		self::need( is_array( $view ) && isset( $view['schema_version'], $view['data_state'], $view['environment'], $view['api_version'], $view['shop'], $view['objects'] )
			&& 1 === $view['schema_version'] && 'exact_references_observed' === $view['data_state'] && $view['environment'] === $target['environment'] && $view['api_version'] === $target['api_version'] && is_array( $view['shop'] ) && self::json( $view['shop'] ) === self::json( $shop )
			&& is_array( $view['objects'] ) && count( $view['objects'] ) === count( $references ) );
		$expected = array_column( $references, null, 'id' ); $facts = array();
		foreach ( $view['objects'] as $object ) {
			self::need( is_array( $object ) && isset( $object['id'], $object['type'], $object['version'], $object['is_deleted'], $object['data'] ) && is_string( $object['id'] ) && isset( $expected[ $object['id'] ] ) && ! isset( $facts[ $object['id'] ] ) && $object['type'] === $expected[ $object['id'] ]['type'] && $object['version'] === $expected[ $object['id'] ]['version'] && false === $object['is_deleted'] );
			if ( 'TAX' === $object['type'] ) {
				$data = $object['data']; self::need( is_array( $data ) );
				foreach ( array( 'percentage', 'calculation_phase', 'inclusion_type', 'enabled', 'applies_to_custom_amounts' ) as $field ) { self::need( array_key_exists( $field, $data ) && $data[ $field ] === $policy['tax'][ $field ] ); }
				self::need( array_key_exists( 'applies_to_product_set_id', $data ) && null === $data['applies_to_product_set_id'] );
			}
			$facts[ $object['id'] ] = $object;
		}
		ksort( $facts, SORT_STRING ); return $facts;
	}

	private static function conflicts( array $graph, array $target, array $source ) {
		self::need( true === DoughBoss_Square_Catalogue_Operations::validate_admission_graph( $graph, $target, $source ) );
	}

	private static function category_collision_key( $name ) { return DoughBoss_Square_Catalogue_Operations::category_collision_key( $name ); }

	/** Retain only the admission facts; unknown/null observations stay unknown/null. */
	private static function inventory_baseline( array $graph ) {
		$rows = array();
		foreach ( $graph as $object ) {
			$data = is_array( $object['data'] ) ? $object['data'] : array();
			$parent = 'MODIFIER' === $object['type'] ? 'modifier_list_id' : 'item_id';
			$rows[] = array( 'id' => $object['id'], 'type' => $object['type'], 'version' => $object['version'], 'is_deleted' => $object['is_deleted'], 'presence' => $object['presence'], 'parent_id' => in_array( $object['type'], array( 'MODIFIER', 'ITEM_VARIATION' ), true ) && array_key_exists( $parent, $data ) ? $data[ $parent ] : null, 'category_name' => 'CATEGORY' === $object['type'] && array_key_exists( 'name', $data ) ? $data['name'] : null );
		}
		return $rows;
	}

	private static function reference_facts( array $facts ) {
		$rows = array(); $tax_fields = array_fill_keys( array( 'percentage', 'calculation_phase', 'inclusion_type', 'enabled', 'applies_to_custom_amounts', 'applies_to_product_set_id' ), true );
		foreach ( $facts as $object ) { $rows[] = array( 'id' => $object['id'], 'type' => $object['type'], 'version' => $object['version'], 'is_deleted' => $object['is_deleted'], 'tax' => 'TAX' === $object['type'] ? array_intersect_key( $object['data'], $tax_fields ) : null ); }
		return $rows;
	}

	private static function fresh_source( callable $reader, array $source ) {
		$menu = $reader(); self::need( is_array( $menu ) && count( $menu ) < 200 );
		$current = DoughBoss_Square_Catalogue_Source::snapshot( $menu );
		self::need( ! is_wp_error( $current ) && $source['algorithm'] === $current['algorithm'] && $source['canonical_json'] === $current['canonical_json'] && $source['source_fingerprint'] === $current['source_fingerprint'] );
	}

	private static function canonical( array $value ) { if ( array_values( $value ) !== $value ) { ksort( $value, SORT_STRING ); } foreach ( $value as $key => $entry ) { if ( is_array( $entry ) ) { $value[ $key ] = self::canonical( $entry ); } } return $value; }
	private static function json( array $value ) { $json = json_encode( self::canonical( $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS ); self::need( is_string( $json ) && strlen( $json ) <= DoughBoss_Square_Catalogue_Operations::MAX_JSON ); return $json; }
	private static function keys( array $value, array $expected ) { $keys = array_keys( $value ); sort( $keys, SORT_STRING ); sort( $expected, SORT_STRING ); self::need( $keys === $expected ); }
	private static function need( $condition ) { if ( ! $condition ) { throw new UnexpectedValueException( 'Catalogue preparation denied.' ); } }
	private static function error( $status = 409 ) { return new WP_Error( 'doughboss_square_catalogue_preparation', 'The approved catalogue could not be prepared safely. No dispatch was authorized.', array( 'status' => $status ) ); }
}
