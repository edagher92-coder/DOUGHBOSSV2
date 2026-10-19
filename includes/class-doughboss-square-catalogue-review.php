<?php
/** Owner-only observation/draft context. No approval, operation write, or provider mutation. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class DoughBoss_Square_Catalogue_Review {
	/** Exact query selection only; no caller-supplied merchant, source, price, or policy. */
	public static function shops( $value ) {
		if ( ! is_string( $value ) ) { return self::error( 400 ); }
		$selected = explode( ',', $value ); $supported = array( 'revesby', 'bankstown', 'roselands' );
		if ( count( $selected ) < 1 || count( $selected ) > 3 || count( array_unique( $selected ) ) !== count( $selected ) ) { return self::error( 400 ); }
		foreach ( $selected as $slug ) { if ( ! in_array( $slug, $supported, true ) ) { return self::error( 400 ); } }
		return array_values( array_intersect( $supported, $selected ) );
	}

	/** The callbacks are trusted controller boundaries, never HTTP-supplied callbacks. */
	public static function observe( array $slugs, callable $read_native_menu, callable $authorize ) {
		try {
			self::need( $slugs === self::shops( implode( ',', $slugs ) ) );
			$actor = get_current_user_id();
			if ( ! self::authorized( $actor, $authorize ) ) { return self::error( 403 ); }
			$source = self::source( $read_native_menu );
			$context = self::context( $slugs );
			$target = array( 'merchant_id' => '', 'environment' => $context['environment'], 'application_id' => $context['application_id'], 'api_version' => '2025-01-23', 'shops' => $context['shops'], 'mapping_policy' => 'create_only', 'existing_references' => array() );
			$inventory = null; $references = null; $facts = null; $expiries = array();
			foreach ( $target['shops'] as $shop ) {
				if ( ! self::authorized( $actor, $authorize ) ) { return self::error( 403 ); }
				self::need( $context === self::context( $slugs ) );
				$identity = DoughBoss_Square::observe_catalogue_identity( $shop['id'] );
				if ( '' === $target['merchant_id'] ) { self::need( is_array( $identity ) && isset( $identity['merchant_id'] ) ); $target['merchant_id'] = $identity['merchant_id']; }
				$expiries[] = self::identity( $identity, $target, $shop );
				if ( ! self::authorized( $actor, $authorize ) ) { return self::error( 403 ); }
				self::need( $context === self::context( $slugs ) );
				$current = self::catalogue( DoughBoss_Square::observe_catalogue( $shop['id'] ), $target, $shop );
				self::need( null === $inventory || self::json( $inventory ) === self::json( $current ) );
				$inventory = $current;
				$current_references = self::references( $inventory );
				self::need( null === $references || $references === $current_references ); $references = $current_references;
				if ( ! self::authorized( $actor, $authorize ) ) { return self::error( 403 ); }
				self::need( $context === self::context( $slugs ) );
				$current_facts = self::exact( DoughBoss_Square::observe_catalogue_review_references( $shop['id'], $references ), $target, $shop, $references, $inventory );
				self::need( null === $facts || self::json( $facts ) === self::json( $current_facts ) ); $facts = $current_facts;
			}
			self::need( $source === self::source( $read_native_menu ) && $context === self::context( $slugs ) );
			if ( ! self::authorized( $actor, $authorize ) ) { return self::error( 403 ); }
			foreach ( $expiries as $expiry ) { self::expiry( $expiry ); }
			// This fresh key belongs only to an unsent draft. No operation is allocated.
			return self::view( bin2hex( random_bytes( 32 ) ), $source, $target, $facts );
		} catch ( Throwable $error ) { return self::error(); }
	}

	private static function authorized( $actor, callable $authorize ) { return is_int( $actor ) && $actor > 0 && $actor === get_current_user_id() && true === $authorize(); }
	private static function source( callable $reader ) {
		$menu = $reader(); self::need( is_array( $menu ) && count( $menu ) < 200 );
		$snapshot = DoughBoss_Square_Catalogue_Source::snapshot( $menu );
		self::need( ! is_wp_error( $snapshot ) && strlen( $snapshot['canonical_json'] ) <= DoughBoss_Square_Catalogue_Operations::MAX_JSON );
		return $snapshot;
	}

	/** Transient secret-bearing context never leaves this service. Current reads bypass stale caches. */
	private static function context( array $slugs ) {
		global $wpdb;
		$locations_table = $wpdb->prefix . 'doughboss_locations';
		$engines = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name IN (%s,%s)', $wpdb->options, $locations_table ), ARRAY_A );
		self::need( empty( $wpdb->last_error ) && is_array( $engines ) && 2 === count( $engines ) );
		foreach ( $engines as $engine ) { self::need( isset( $engine['Engine'] ) && 'INNODB' === strtoupper( $engine['Engine'] ) ); }
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1 FOR UPDATE", DoughBoss_Settings::OPTION_KEY ) );
		self::need( empty( $wpdb->last_error ) && is_string( $raw ) ); $stored = maybe_unserialize( $raw );
		self::need( is_array( $stored ) && $stored === get_option( DoughBoss_Settings::OPTION_KEY, array() ) );
		$currency = DoughBoss_Settings::get( 'currency_code', 'AUD' );
		self::need( is_string( $currency ) && 'AUD' === strtoupper( $currency ) && '2025-01-23' === DoughBoss_Settings::square_api_version() && DoughBoss_Settings::square_access_token_valid() && '' !== DoughBoss_Settings::square_application_id() );
		$rows = $wpdb->get_results( "SELECT id,slug,is_active FROM {$locations_table} WHERE slug IN ('revesby','bankstown','roselands') ORDER BY id FOR UPDATE", ARRAY_A );
		self::need( empty( $wpdb->last_error ) && is_array( $rows ) ); $shops = array(); $ids = array(); $provider_ids = array();
		foreach ( $slugs as $slug ) {
			$matches = array_values( array_filter( $rows, static function ( $row ) use ( $slug ) { return isset( $row['slug'] ) && $row['slug'] === $slug; } ) );
			self::need( 1 === count( $matches ) && isset( $matches[0]['id'], $matches[0]['is_active'] ) && 1 === (int) $matches[0]['is_active'] );
			$id = (int) $matches[0]['id']; self::need( $id > 0 && $id <= 9007199254740991 && ! isset( $ids[ $id ] ) ); $ids[ $id ] = true;
			$binding = DoughBoss_Square::shop_binding( $id );
			self::need( ! is_wp_error( $binding ) && empty( $wpdb->last_error ) && $binding['shop_slug'] === $slug && $binding['square_mode'] === DoughBoss_Settings::square_mode() && ! isset( $provider_ids[ $binding['square_location_id'] ] ) );
			$provider_ids[ $binding['square_location_id'] ] = true; $shops[] = array( 'id' => $id, 'slug' => $slug, 'square_location_id' => $binding['square_location_id'] );
		}
		return array( 'settings_version' => hash( 'sha256', $raw ), 'token' => DoughBoss_Settings::square_access_token(), 'environment' => DoughBoss_Settings::square_mode(), 'application_id' => DoughBoss_Settings::square_application_id(), 'shops' => $shops );
	}

	private static function identity( $view, array $target, array $shop ) {
		self::need( is_array( $view ) && isset( $view['schema_version'], $view['data_state'], $view['merchant_id'], $view['application_id'], $view['environment'], $view['api_version'], $view['shop'], $view['scopes'], $view['expires_at'] ) && 1 === $view['schema_version'] && 'credential_identity_observed' === $view['data_state'] && $view['merchant_id'] === $target['merchant_id'] && $view['application_id'] === $target['application_id'] && $view['environment'] === $target['environment'] && $view['api_version'] === $target['api_version'] && $view['shop'] === $shop && is_array( $view['scopes'] ) );
		foreach ( array( 'MERCHANT_PROFILE_READ', 'ITEMS_READ' ) as $scope ) { self::need( in_array( $scope, $view['scopes'], true ) ); }
		self::expiry( $view['expires_at'] ); return $view['expires_at'];
	}
	private static function expiry( $value ) {
		self::need( is_string( $value ) ); if ( '' === $value ) { return; }
		self::need( preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,9})?(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $value ) );
		// The identity adapter validates calendar/zone; strip only validated fractions for PHP 7.4.
		$seconds = strtotime( preg_replace( '/\.[0-9]{1,9}(?=Z|[+-])/', '', $value ) );
		self::need( false !== $seconds && $seconds > time() );
	}

	private static function catalogue( $view, array $target, array $shop ) {
		self::need( is_array( $view ) && isset( $view['schema_version'], $view['data_state'], $view['environment'], $view['shop'], $view['location'], $view['catalogue']['objects'] ) && 1 === $view['schema_version'] && 'catalogue_observed' === $view['data_state'] && $view['environment'] === $target['environment'] && $view['shop'] === array( 'id' => $shop['id'], 'slug' => $shop['slug'] ) && is_array( $view['location'] ) );
		$location = $view['location']; self::need( isset( $location['id'], $location['status'], $location['currency'], $location['merchant_id'] ) && $location['id'] === $shop['square_location_id'] && 'ACTIVE' === $location['status'] && 'AUD' === $location['currency'] && $location['merchant_id'] === $target['merchant_id'] );
		$objects = $view['catalogue']['objects']; self::need( is_array( $objects ) && array_values( $objects ) === $objects && count( $objects ) <= DoughBoss_Square::CATALOGUE_MAX_TOP_LEVEL );
		$graph = array(); $count = 0; foreach ( $objects as $object ) { self::collect( $object, $graph, $count, false ); } ksort( $graph, SORT_STRING ); return $graph;
	}
	private static function collect( $object, array &$graph, &$count, $nested ) {
		self::need( ++$count <= DoughBoss_Square::CATALOGUE_MAX_OCCURRENCES && is_array( $object ) && isset( $object['id'], $object['type'], $object['embedded_objects'] ) && is_string( $object['id'] ) && is_array( $object['embedded_objects'] ) && ( ! $nested || 0 === count( $object['embedded_objects'] ) ) );
		self::need( ! isset( $graph[ $object['id'] ] ) || self::json( $graph[ $object['id'] ] ) === self::json( $object ) ); $graph[ $object['id'] ] = $object;
		foreach ( $object['embedded_objects'] as $child ) { self::collect( $child, $graph, $count, true ); }
	}
	private static function references( array $graph ) {
		$references = array();
		foreach ( $graph as $object ) {
			if ( in_array( $object['type'], array( 'TAX', 'IMAGE' ), true ) && isset( $object['is_deleted'], $object['version'] ) && false === $object['is_deleted'] && is_int( $object['version'] ) && $object['version'] > 0 ) { $references[] = array( 'id' => $object['id'], 'type' => $object['type'], 'version' => $object['version'] ); }
		}
		self::need( count( $references ) <= DoughBoss_Square::CATALOGUE_MAX_REFERENCES ); return $references;
	}
	private static function exact( $view, array $target, array $shop, array $references, array $inventory ) {
		self::need( is_array( $view ) && isset( $view['schema_version'], $view['data_state'], $view['environment'], $view['api_version'], $view['shop'], $view['objects'] ) && 1 === $view['schema_version'] && 'exact_references_observed' === $view['data_state'] && $target['environment'] === $view['environment'] && $target['api_version'] === $view['api_version'] && $shop === $view['shop'] && is_array( $view['objects'] ) && count( $references ) === count( $view['objects'] ) );
		$expected = array_column( $references, null, 'id' ); $facts = array();
		foreach ( $view['objects'] as $object ) {
			self::need( is_array( $object ) && isset( $object['id'], $object['type'], $object['version'], $object['is_deleted'], $object['data'] ) && is_string( $object['id'] ) && isset( $expected[ $object['id'] ] ) && ! isset( $facts[ $object['id'] ] ) && $object['type'] === $expected[ $object['id'] ]['type'] && $object['version'] === $expected[ $object['id'] ]['version'] && false === $object['is_deleted'] && is_array( $object['data'] ) );
			if ( 'TAX' === $object['type'] ) {
				$listing = $inventory[ $object['id'] ]['data'];
				foreach ( array( 'percentage', 'calculation_phase', 'inclusion_type', 'enabled', 'applies_to_custom_amounts', 'applies_to_product_set_id' ) as $field ) {
					self::need( array_key_exists( $field, $listing ) === array_key_exists( $field, $object['data'] ) && ( ! array_key_exists( $field, $listing ) || $listing[ $field ] === $object['data'][ $field ] ) );
				}
			}
			$facts[ $object['id'] ] = $object;
		}
		ksort( $facts, SORT_STRING ); return $facts;
	}

	private static function view( $key, array $source, array $target, array $facts ) {
		self::need( is_string( $key ) && preg_match( '/^[a-f0-9]{64}$/D', $key ) ); $taxes = array(); $images = array();
		foreach ( $facts as $object ) {
			$data = $object['data']; $base = array( 'id' => $object['id'], 'type' => $object['type'], 'version' => $object['version'], 'name' => $data['name'] );
			if ( 'IMAGE' === $object['type'] ) {
				$images[] = array_merge( $base, array_intersect_key( $data, array_flip( array( 'url', 'evidence_available', 'evidence_source', 'authenticity_verified', 'licence_verified' ) ) ) );
			} else {
				$required = array( 'percentage', 'calculation_phase', 'inclusion_type', 'enabled', 'applies_to_custom_amounts' ); $complete = true;
				foreach ( $required as $field ) { if ( ! array_key_exists( $field, $data ) ) { $complete = false; } }
				// Missing required facts are not approvable candidates, not proof of an empty inventory.
				if ( ! $complete ) { continue; }
				$candidate = array_merge( $base, array_intersect_key( $data, array_flip( $required ) ) );
				$present = array_key_exists( 'applies_to_product_set_id', $data );
				$candidate['applies_to_product_set_id'] = $present ? $data['applies_to_product_set_id'] : null;
				$candidate['product_set_observed'] = $present;
				$percentage = $candidate['percentage']; $valid_percentage = is_string( $percentage ) && preg_match( '/^([0-9]{1,3})(?:\.([0-9]{1,4}))?$/D', $percentage, $match ) && ( (int) $match[1] < 100 || ( 100 === (int) $match[1] && ( ! isset( $match[2] ) || '' === trim( $match[2], '0' ) ) ) );
				$candidate['policy_compatible'] = $valid_percentage && true === $candidate['enabled'] && $present && null === $candidate['applies_to_product_set_id'];
				$taxes[] = $candidate;
			}
		}
		return array( 'schema_version' => 1, 'operation_key' => $key,
			'source' => array( 'algorithm' => $source['algorithm'], 'source_fingerprint' => $source['source_fingerprint'], 'items' => $source['items'], 'source' => 'wordpress_menu', 'global_availability_only' => true, 'provider_writes_allowed' => false ),
			'target' => $target, 'tax_candidates' => $taxes, 'image_candidates' => $images,
			'dispatch_allowed' => false );
	}
	private static function json( array $value ) { $canonical = self::canonical( $value ); $json = json_encode( $canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS ); self::need( is_string( $json ) && strlen( $json ) <= DoughBoss_Square_Catalogue_Operations::MAX_JSON ); return $json; }
	private static function canonical( array $value ) { if ( array_values( $value ) !== $value ) { ksort( $value, SORT_STRING ); } foreach ( $value as $key => $entry ) { if ( is_array( $entry ) ) { $value[ $key ] = self::canonical( $entry ); } } return $value; }
	private static function need( $condition ) { if ( ! $condition ) { throw new UnexpectedValueException( 'Catalogue review unavailable.' ); } }
	private static function error( $status = 409 ) { return new WP_Error( 'doughboss_square_catalogue_review_unavailable', 'A complete current owner review context could not be observed safely. No approval or dispatch was authorized.', array( 'status' => $status ) ); }
}
