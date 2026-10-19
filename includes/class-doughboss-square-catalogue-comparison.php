<?php
/**
 * Pure native-import candidate comparison, not receipt verification or mapping.
 * The controller proves provenance and before/after authority/source freshness.
 * No database, settings, transport, hooks, dispatch or approval occurs here.
 *
 * @package DoughBoss
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class DoughBoss_Square_Catalogue_Comparison {
	// Local aggregate allowance: all admitted wire pages plus bounded projection
	// metadata/embedded-reference overhead. This is not a Square field maximum.
	const MAX_OBSERVATION_BYTES = DoughBoss_Square::CATALOGUE_MAX_PAGES * DoughBoss_Square::CATALOGUE_MAX_RESPONSE_BYTES + DoughBoss_Square::CATALOGUE_MAX_OCCURRENCES * 2048;
	/** Exact server snapshot plus complete minimized current listing only. */
	public static function compare( array $source, array $observation ) {
		try {
			self::bounded( $observation );
			$source = DoughBoss_Square_Catalogue_Operations::validate_source( $source );
			self::need( ! is_wp_error( $source ) && count( $source['items'] ) >= 1 && count( $source['items'] ) < 200 );
			$graph = self::graph( $observation ); $skus = array(); $expected_skus = array();
			foreach ( $source['items'] as $item ) { $expected_skus[ 'DB-WP-' . $item['website_item_id'] ] = true; }
			foreach ( $graph as $object ) {
				if ( 'ITEM_VARIATION' !== $object['type'] || ! array_key_exists( 'sku', $object['data'] ) ) { continue; }
				$sku = $object['data']['sku'];
				// Other provider SKU text is never included in the report or errors.
				if ( isset( $expected_skus[ $sku ] ) ) { $skus[ $sku ][] = $object['id']; }
			}
			$tally = array( 'matches' => 0, 'conflict' => 0, 'unobserved' => 0 );
			$summary = array( 'source_items' => count( $source['items'] ), 'lineage' => array( 'candidate' => 0, 'conflict' => 0, 'unobserved' => 0 ), 'name' => $tally, 'base_price' => $tally, 'modifiers' => $tally, 'source_modifier_groups' => 0, 'linked_modifier_sets' => 0 );
			$rows = array(); $sets = array();
			foreach ( $source['items'] as $item ) {
				$row = self::row( $item, $graph, $skus );
				foreach ( array( 'lineage', 'name', 'base_price', 'modifiers' ) as $facet ) { ++$summary[ $facet ][ $row[ $facet ]['state'] ]; }
				$summary['source_modifier_groups'] += $row['modifiers']['source_group_count'];
				if ( 'candidate' === $row['lineage']['state'] ) { foreach ( $row['modifiers']['linked_set_ids'] as $id ) { $sets[ $id ] = true; } }
				$rows[] = $row;
			}
			usort( $rows, static function ( $a, $b ) { return $a['website_item_id'] <=> $b['website_item_id']; } );
			$summary['linked_modifier_sets'] = count( $sets );
			foreach ( array( 'lineage', 'name', 'base_price', 'modifiers' ) as $facet ) { self::need( array_sum( $summary[ $facet ] ) === $summary['source_items'] ); }
			return array( 'schema_version' => 1, 'state' => 'candidate_comparison', 'lineage_policy' => 'unapproved_exact_sku', 'source_algorithm' => $source['algorithm'], 'source_fingerprint' => $source['source_fingerprint'],
				'provider_writes_allowed' => false, 'mapping_approved' => false, 'dispatch_allowed' => false,
				'limitations' => array( 'source_display_order' => 'unobserved', 'tax' => 'unobserved', 'shop_effective_pricing' => 'unobserved', 'inventory' => 'unobserved', 'channels' => 'unobserved', 'merchant_ownership' => 'unapproved' ), 'summary' => $summary, 'rows' => $rows );
		} catch ( Throwable $error ) {
			return new WP_Error( 'doughboss_square_catalogue_comparison', 'The complete current source comparison could not be made safely. No mapping or dispatch was authorized.', array( 'status' => 409 ) );
		}
	}

	private static function graph( array $view ) {
		self::keys( $view, array( 'schema_version', 'source', 'data_state', 'observed_at', 'environment', 'shop', 'location', 'reconciliation', 'catalogue' ) );
		self::need( 1 === $view['schema_version'] && 'square' === $view['source'] && 'catalogue_observed' === $view['data_state'] && in_array( $view['environment'], array( 'test', 'live' ), true ) && self::timestamp( $view['observed_at'] ) );
		self::keys( $view['shop'], array( 'id', 'slug' ) ); self::need( self::integer( $view['shop']['id'], 1 ) && in_array( $view['shop']['slug'], array( 'revesby', 'bankstown', 'roselands' ), true ) );
		self::keys( $view['location'], array( 'id', 'status', 'currency', 'merchant_id', 'capabilities_present', 'capabilities' ) );
		self::need( self::id( $view['location']['id'] ) && self::id( $view['location']['merchant_id'] ) && in_array( $view['location']['status'], array( 'ACTIVE', 'INACTIVE' ), true ) && 'AUD' === $view['location']['currency'] && is_bool( $view['location']['capabilities_present'] ) );
		self::list_value( $view['location']['capabilities'], 32 );
		foreach ( $view['location']['capabilities'] as $capability ) { self::need( is_string( $capability ) && preg_match( '/^[A-Z][A-Z0-9_]{0,63}$/D', $capability ) ); }
		self::need( $view['reconciliation'] === array( 'merchant_identity' => 'observed_owner_reconciliation_required', 'catalogue' => 'current_listing_only', 'pricing' => 'base_price_and_raw_overrides_only', 'inventory' => 'not_observed' ) );
		$catalogue = $view['catalogue']; self::keys( $catalogue, array( 'types', 'pages', 'object_count', 'counts', 'objects' ) );
		$types = array( 'ITEM', 'ITEM_VARIATION', 'MODIFIER_LIST', 'MODIFIER', 'IMAGE', 'TAX', 'CATEGORY' );
		self::need( $types === $catalogue['types'] && is_int( $catalogue['pages'] ) && $catalogue['pages'] >= 1 && $catalogue['pages'] <= DoughBoss_Square::CATALOGUE_MAX_PAGES );
		self::list_value( $catalogue['objects'], DoughBoss_Square::CATALOGUE_MAX_TOP_LEVEL ); self::keys( $catalogue['counts'], $types );
		self::need( $catalogue['object_count'] === count( $catalogue['objects'] ) );
		$counts = array_fill_keys( $types, 0 ); $graph = array(); $top = array(); $count = 0;
		foreach ( $catalogue['objects'] as $object ) {
			self::collect( $object, $graph, $count ); self::need( ! isset( $top[ $object['id'] ] ) ); $top[ $object['id'] ] = true; ++$counts[ $object['type'] ];
		}
		foreach ( $types as $type ) { self::need( $catalogue['counts'][ $type ] === $counts[ $type ] ); }
		ksort( $graph, SORT_STRING ); return $graph;
	}

	private static function collect( $object, array &$graph, &$count, $nested = false ) {
		self::need( ++$count <= DoughBoss_Square::CATALOGUE_MAX_OCCURRENCES );
		self::keys( $object, array( 'id', 'type', 'version', 'updated_at', 'is_deleted', 'presence', 'data', 'embedded_objects' ) );
		self::need( self::id( $object['id'] ) && in_array( $object['type'], array( 'ITEM', 'ITEM_VARIATION', 'MODIFIER_LIST', 'MODIFIER', 'IMAGE', 'TAX', 'CATEGORY' ), true ) );
		self::need( null === $object['version'] || self::integer( $object['version'], 0 ) ); self::need( null === $object['updated_at'] || self::timestamp( $object['updated_at'] ) ); self::need( null === $object['is_deleted'] || is_bool( $object['is_deleted'] ) );
		self::keys( $object['presence'], array( 'present_at_all_locations', 'present_at_location_ids', 'absent_at_location_ids' ) );
		self::need( null === $object['presence']['present_at_all_locations'] || is_bool( $object['presence']['present_at_all_locations'] ) );
		foreach ( array( 'present_at_location_ids', 'absent_at_location_ids' ) as $field ) { if ( null !== $object['presence'][ $field ] ) { self::ids( $object['presence'][ $field ], 500 ); } }
		self::need( is_array( $object['data'] ) || ( $object['data'] instanceof stdClass && 0 === count( get_object_vars( $object['data'] ) ) ) ); $object['data'] = (array) $object['data'];
		self::fields( $object ); self::list_value( $object['embedded_objects'], 100 ); self::need( ! $nested || 0 === count( $object['embedded_objects'] ) );
		if ( isset( $graph[ $object['id'] ] ) ) { self::need( self::json( $graph[ $object['id'] ] ) === self::json( $object ) ); } else { $graph[ $object['id'] ] = $object; }
		foreach ( $object['embedded_objects'] as $child ) { self::need( is_array( $child ) && isset( $child['type'] ) && ( ( 'ITEM' === $object['type'] && 'ITEM_VARIATION' === $child['type'] ) || ( 'MODIFIER_LIST' === $object['type'] && 'MODIFIER' === $child['type'] ) ) ); self::collect( $child, $graph, $count, true ); }
	}

	private static function fields( array $object ) {
		$data = $object['data'];
		foreach ( array( 'name', 'sku' ) as $field ) { if ( array_key_exists( $field, $data ) ) { self::need( is_string( $data[ $field ] ) && 1 === preg_match( '//u', $data[ $field ] ) && ( 'sku' !== $field || 'ITEM_VARIATION' === $object['type'] ) ); } }
		foreach ( array( 'item_id', 'modifier_list_id' ) as $field ) { if ( array_key_exists( $field, $data ) ) { self::need( self::id( $data[ $field ] ) ); } }
		foreach ( array( 'variation_ids', 'modifier_ids' ) as $field ) { if ( array_key_exists( $field, $data ) ) { self::ids( $data[ $field ], 100 ); } }
		if ( array_key_exists( 'selection_type', $data ) ) { self::need( 'MODIFIER_LIST' === $object['type'] && in_array( $data['selection_type'], array( 'SINGLE', 'MULTIPLE' ), true ) ); }
		if ( array_key_exists( 'ordinal', $data ) ) { self::need( 'MODIFIER' === $object['type'] && is_int( $data['ordinal'] ) && $data['ordinal'] >= -2147483648 && $data['ordinal'] <= 2147483647 ); }
		if ( array_key_exists( 'price_money', $data ) ) { self::keys( $data['price_money'], array( 'amount', 'currency' ) ); self::need( self::integer( $data['price_money']['amount'], 0 ) && 'AUD' === $data['price_money']['currency'] ); }
		if ( array_key_exists( 'pricing_type', $data ) ) { self::need( in_array( $data['pricing_type'], array( 'FIXED_PRICING', 'VARIABLE_PRICING' ), true ) ); }
		if ( array_key_exists( 'on_by_default', $data ) ) { self::need( is_bool( $data['on_by_default'] ) ); }
		if ( array_key_exists( 'modifier_list_info', $data ) ) {
			self::list_value( $data['modifier_list_info'], 100 );
			foreach ( $data['modifier_list_info'] as $info ) {
				self::need( is_array( $info ) && isset( $info['modifier_list_id'] ) && self::id( $info['modifier_list_id'] ) );
				foreach ( array( 'min_selected_modifiers', 'max_selected_modifiers' ) as $field ) { if ( array_key_exists( $field, $info ) ) { self::need( self::integer( $info[ $field ], -1 ) ); } }
				if ( array_key_exists( 'enabled', $info ) ) { self::need( is_bool( $info['enabled'] ) ); }
				if ( array_key_exists( 'modifier_overrides', $info ) ) {
					self::list_value( $info['modifier_overrides'], 100 ); foreach ( $info['modifier_overrides'] as $override ) { self::need( is_array( $override ) && isset( $override['modifier_id'] ) && self::id( $override['modifier_id'] ) ); if ( array_key_exists( 'on_by_default', $override ) ) { self::need( is_bool( $override['on_by_default'] ) ); } }
				}
			}
		}
	}

	private static function row( array $item, array $graph, array $skus ) {
		$sku = 'DB-WP-' . $item['website_item_id']; $ids = isset( $skus[ $sku ] ) ? self::sorted( $skus[ $sku ] ) : array();
		$lineage = array( 'state' => 'unobserved', 'candidate_variation_ids' => $ids, 'item_id' => null, 'reasons' => array() );
		$name = array( 'state' => 'unobserved', 'source' => $item['name'], 'observed' => null, 'reasons' => array( 'lineage_unresolved' ) );
		$price = array( 'state' => 'unobserved', 'source_minor' => $item['base_price_minor'], 'observed_minor' => null, 'observed_currency' => null, 'reasons' => array( 'lineage_unresolved' ) );
		$modifiers = array( 'state' => 'unobserved', 'source_group_count' => count( $item['modifier_groups'] ), 'linked_set_ids' => array(), 'compared_group_count' => 0, 'missing_facts' => array(), 'reasons' => array( 'lineage_unresolved' ) );
		if ( 0 === count( $ids ) ) { $lineage['reasons'][] = 'sku_not_observed'; }
		elseif ( 1 !== count( $ids ) ) { $lineage['state'] = 'conflict'; $lineage['reasons'][] = 'sku_multiple_variations'; }
		else {
			$variation = $graph[ $ids[0] ]; $data = $variation['data'];
			if ( true === $variation['is_deleted'] ) { $lineage['state'] = 'conflict'; $lineage['reasons'][] = 'variation_deleted'; }
			elseif ( ! isset( $data['item_id'] ) ) { $lineage['reasons'][] = 'parent_reference_unobserved'; }
			elseif ( ! isset( $graph[ $data['item_id'] ] ) || 'ITEM' !== $graph[ $data['item_id'] ]['type'] ) { $lineage['state'] = 'conflict'; $lineage['reasons'][] = 'parent_reference_conflict'; }
			else {
				$parent = $graph[ $data['item_id'] ]; $lineage['item_id'] = $parent['id'];
				if ( true === $parent['is_deleted'] ) { $lineage['state'] = 'conflict'; $lineage['reasons'][] = 'parent_deleted'; }
				elseif ( null === $parent['is_deleted'] || null === $variation['is_deleted'] ) { $lineage['reasons'][] = 'deletion_unobserved'; }
				elseif ( ! array_key_exists( 'variation_ids', $parent['data'] ) ) { $lineage['reasons'][] = 'parent_membership_unobserved'; }
				elseif ( array( $variation['id'] ) !== $parent['data']['variation_ids'] ) { $lineage['state'] = 'conflict'; $lineage['reasons'][] = 'parent_variation_membership_conflict'; }
				else {
					$lineage['state'] = 'candidate';
					$name['reasons'] = array();
					if ( array_key_exists( 'name', $parent['data'] ) ) { $name['observed'] = $parent['data']['name']; $name['state'] = $name['source'] === $name['observed'] ? 'matches' : 'conflict'; if ( 'conflict' === $name['state'] ) { $name['reasons'][] = 'item_name_differs'; } }
					else { $name['reasons'][] = 'item_name_unobserved'; }
					$price['reasons'] = array();
					if ( array_key_exists( 'price_money', $data ) ) { $price['observed_minor'] = $data['price_money']['amount']; $price['observed_currency'] = $data['price_money']['currency']; }
					if ( ! isset( $data['pricing_type'] ) || ! array_key_exists( 'price_money', $data ) ) { $price['reasons'][] = 'fixed_base_price_unobserved'; }
					else { $price['state'] = 'FIXED_PRICING' === $data['pricing_type'] && 'AUD' === $price['observed_currency'] && $price['source_minor'] === $price['observed_minor'] ? 'matches' : 'conflict'; if ( 'conflict' === $price['state'] ) { $price['reasons'][] = 'fixed_base_price_differs'; } }
					$modifiers = self::modifiers( $item['modifier_groups'], $parent, $graph );
				}
			}
		}
		return array( 'website_item_id' => $item['website_item_id'], 'source_name' => $item['name'], 'expected_sku' => $sku, 'lineage' => $lineage, 'name' => $name, 'base_price' => $price, 'modifiers' => $modifiers );
	}

	private static function modifiers( array $groups, array $parent, array $graph ) {
		$out = array( 'state' => 'unobserved', 'source_group_count' => count( $groups ), 'linked_set_ids' => array(), 'compared_group_count' => 0, 'missing_facts' => array(), 'reasons' => array() );
		if ( ! array_key_exists( 'modifier_list_info', $parent['data'] ) ) { $out['missing_facts'][] = 'item_modifier_links_unobserved'; return $out; }
		$expected = array(); foreach ( $groups as $group ) {
			$choices = array(); foreach ( $group['choices'] as $choice ) { $choices[] = array( 'name' => $choice['label'], 'price' => $choice['price_delta_minor'], 'default' => $choice['default'] ); }
			$expected[] = self::signature( array( 'name' => $group['label'], 'selection_type' => 'radio' === $group['type'] ? 'SINGLE' : 'MULTIPLE', 'enabled' => true, 'minimum' => 'radio' === $group['type'] ? 1 : 0, 'maximum' => 'radio' === $group['type'] ? 1 : count( $choices ), 'choices' => $choices ) );
		}
		$infos = $parent['data']['modifier_list_info']; $seen = array();
		foreach ( $infos as $info ) {
			$id = $info['modifier_list_id'];
			if ( isset( $seen[ $id ] ) ) { $out['reasons'][] = 'duplicate_modifier_link'; continue; }
			$seen[ $id ] = true; $out['linked_set_ids'][] = $id;
			if ( ! isset( $graph[ $id ] ) ) { $out['missing_facts'][] = 'linked_set_unobserved'; continue; }
			if ( 'MODIFIER_LIST' !== $graph[ $id ]['type'] || true === $graph[ $id ]['is_deleted'] ) { $out['reasons'][] = 'linked_set_conflict'; continue; }
			$signature = self::definition( $graph[ $id ], $info, $graph, $out );
			if ( null === $signature ) { continue; }
			$match = array_search( $signature, $expected, true );
			if ( false === $match ) { $out['reasons'][] = 'modifier_definition_differs'; }
			else { ++$out['compared_group_count']; unset( $expected[ $match ] ); }
		}
		if ( count( $infos ) !== count( $groups ) ) { $out['reasons'][] = 'modifier_group_count_differs'; }
		$out['linked_set_ids'] = self::sorted( $out['linked_set_ids'] ); $out['missing_facts'] = self::sorted( $out['missing_facts'] ); $out['reasons'] = self::sorted( $out['reasons'] );
		$out['state'] = count( $out['reasons'] ) ? 'conflict' : ( count( $out['missing_facts'] ) ? 'unobserved' : 'matches' ); return $out;
	}

	private static function definition( array $list, array $info, array $graph, array &$out ) {
		$data = $list['data']; $missing = array(); $reasons = array(); $choices = array();
		if ( null === $list['is_deleted'] ) { $missing[] = 'deletion_unobserved'; }
		foreach ( array( 'name' => 'list_name_unobserved', 'selection_type' => 'selection_type_unobserved', 'modifier_ids' => 'modifier_children_unobserved' ) as $field => $code ) { if ( ! array_key_exists( $field, $data ) ) { $missing[] = $code; } }
		foreach ( array( 'enabled' => 'selection_enabled_unobserved', 'min_selected_modifiers' => 'selection_bounds_unobserved', 'max_selected_modifiers' => 'selection_bounds_unobserved', 'modifier_overrides' => 'modifier_overrides_unobserved' ) as $field => $code ) { if ( ! array_key_exists( $field, $info ) ) { $missing[] = $code; } }
		foreach ( array( 'min_selected_modifiers', 'max_selected_modifiers' ) as $field ) { if ( isset( $info[ $field ] ) && $info[ $field ] < 0 ) { $missing[] = 'selection_bounds_unobserved'; } }
		$overrides = array(); foreach ( isset( $info['modifier_overrides'] ) ? $info['modifier_overrides'] : array() as $override ) {
			$id = $override['modifier_id']; if ( isset( $overrides[ $id ] ) ) { $reasons[] = 'duplicate_modifier_override'; }
			$overrides[ $id ] = $override;
			if ( ! isset( $data['modifier_ids'] ) || ! in_array( $id, $data['modifier_ids'], true ) ) { $reasons[] = 'foreign_modifier_override'; }
			if ( ! array_key_exists( 'on_by_default', $override ) ) { $missing[] = 'modifier_override_default_unobserved'; }
		}
		$seen = array(); foreach ( isset( $data['modifier_ids'] ) ? $data['modifier_ids'] : array() as $id ) {
			if ( isset( $seen[ $id ] ) ) { $reasons[] = 'duplicate_modifier_member'; continue; } $seen[ $id ] = true;
			if ( ! isset( $graph[ $id ] ) ) { $missing[] = 'modifier_child_unobserved'; continue; }
			$modifier = $graph[ $id ]; $facts = $modifier['data'];
			if ( 'MODIFIER' !== $modifier['type'] || true === $modifier['is_deleted'] || ! isset( $facts['modifier_list_id'] ) || $facts['modifier_list_id'] !== $list['id'] ) { $reasons[] = 'modifier_child_conflict'; continue; }
			if ( null === $modifier['is_deleted'] ) { $missing[] = 'deletion_unobserved'; }
			foreach ( array( 'name' => 'modifier_name_unobserved', 'price_money' => 'modifier_price_unobserved' ) as $field => $code ) { if ( ! array_key_exists( $field, $facts ) ) { $missing[] = $code; } }
			$has_default = isset( $overrides[ $id ] ) ? array_key_exists( 'on_by_default', $overrides[ $id ] ) : array_key_exists( 'on_by_default', $facts );
			if ( ! $has_default ) { $missing[] = 'modifier_default_unobserved'; }
			if ( isset( $facts['name'], $facts['price_money'] ) && $has_default ) { $choices[] = array( 'name' => $facts['name'], 'price' => $facts['price_money']['amount'], 'default' => isset( $overrides[ $id ] ) ? $overrides[ $id ]['on_by_default'] : $facts['on_by_default'] ); }
		}
		$out['missing_facts'] = array_merge( $out['missing_facts'], $missing ); $out['reasons'] = array_merge( $out['reasons'], $reasons );
		if ( count( $missing ) || count( $reasons ) ) { return null; }
		return self::signature( array( 'name' => $data['name'], 'selection_type' => $data['selection_type'], 'enabled' => $info['enabled'], 'minimum' => $info['min_selected_modifiers'], 'maximum' => $info['max_selected_modifiers'], 'choices' => $choices ) );
	}

	/** Whole definitions are multiset values; labels never establish identity. */
	private static function signature( array $definition ) {
		usort( $definition['choices'], static function ( $a, $b ) { return strcmp( self::json( $a ), self::json( $b ) ); } ); return self::json( $definition );
	}
	private static function bounded( array $value ) {
		$nodes = 0; self::walk( $value, 0, $nodes ); self::need( strlen( self::json( $value ) ) <= self::MAX_OBSERVATION_BYTES );
	}
	private static function walk( $value, $depth, &$nodes ) {
		self::need( ++$nodes <= self::MAX_OBSERVATION_BYTES && $depth <= 32 );
		if ( is_array( $value ) ) { self::need( count( $value ) <= DoughBoss_Square::CATALOGUE_MAX_OCCURRENCES ); foreach ( $value as $entry ) { self::walk( $entry, $depth + 1, $nodes ); } }
		elseif ( $value instanceof stdClass ) { self::need( 0 === count( get_object_vars( $value ) ) ); }
		elseif ( is_string( $value ) ) { self::need( strlen( $value ) <= DoughBoss_Square::CATALOGUE_MAX_RESPONSE_BYTES && 1 === preg_match( '//u', $value ) ); }
		else { self::need( null === $value || is_bool( $value ) || self::integer( $value, -9007199254740991 ) ); }
	}
	private static function timestamp( $value ) { return is_string( $value ) && 1 === preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,9})?(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $value ); }
	private static function id( $value ) { return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9:_-]{1,192}$/D', $value ); }
	private static function integer( $value, $minimum ) { return is_int( $value ) && $value >= $minimum && $value <= 9007199254740991; }
	private static function ids( $value, $maximum ) { self::list_value( $value, $maximum ); foreach ( $value as $id ) { self::need( self::id( $id ) ); } }
	private static function sorted( array $values ) { $values = array_values( array_unique( $values, SORT_STRING ) ); sort( $values, SORT_STRING ); return $values; }
	private static function keys( $value, array $expected ) { self::need( is_array( $value ) ); $keys = array_keys( $value ); sort( $keys, SORT_STRING ); sort( $expected, SORT_STRING ); self::need( $keys === $expected ); }
	private static function list_value( $value, $maximum ) { self::need( is_array( $value ) && array_values( $value ) === $value && count( $value ) <= $maximum ); }
	private static function json( $value ) { $json = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); self::need( is_string( $json ) ); return $json; }
	private static function need( $condition ) { if ( ! $condition ) { throw new UnexpectedValueException( 'Invalid native-import comparison.' ); } }
}
