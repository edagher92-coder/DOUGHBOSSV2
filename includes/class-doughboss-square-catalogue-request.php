<?php
/**
 * Deterministic create-only wire compilation from the trusted native menu.
 *
 * Inputs are internal server-owned data, not uploaded plans or caller prices.
 * Compilation does NOT attest merchant, current provider facts, tax applicability,
 * image authenticity, API compatibility, approval, or permission to dispatch.
 * A later service must obtain the current native source and provider observations,
 * reattest exact referenced versions/content, and approve the exact owner policy.
 * No provider requests or storage occur here.
 *
 * @package DoughBoss
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class DoughBoss_Square_Catalogue_Request {
	/** @return array|WP_Error No executable partial packet is returned on failure. */
	public static function build( array $native_menu, array $target, array $policy, $provider_key ) {
		if ( count( $native_menu ) >= 200 ) { return self::error(); }
		$source = DoughBoss_Square_Catalogue_Source::snapshot( $native_menu );
		return is_wp_error( $source ) ? self::error() : self::build_from_source( $source, $target, $policy, $provider_key );
	}

	/** Shared compiler for a validated normalized source; no raw fields retained. */
	public static function build_from_source( array $source, array $target, array $policy, $provider_key ) {
		try {
			$policy = DoughBoss_Square_Catalogue_Operations::validate_policy_input( $policy );
			self::need( ! is_wp_error( $policy ) );
			$source = DoughBoss_Square_Catalogue_Operations::validate_source( $source );
			self::need( ! is_wp_error( $source ) && count( $source['items'] ) < 200 );
			self::keys( $policy, array( 'schema_version', 'source_fingerprint_algorithm', 'source_fingerprint', 'expected_item_ids', 'availability', 'photos', 'tax', 'category_policy' ) );
			self::need( 1 === $policy['schema_version'] && 'create_from_source' === $policy['category_policy'] && $policy['source_fingerprint_algorithm'] === $source['algorithm'] && $policy['source_fingerprint'] === $source['source_fingerprint'] );
			$items = $source['items'];
			$item_ids = array_column( $items, 'website_item_id' );
			self::list_value( $policy['expected_item_ids'], count( $items ) );
			$expected = $policy['expected_item_ids'];
			foreach ( $expected as $id ) { self::need( self::integer( $id ) ); }
			sort( $expected, SORT_NUMERIC );
			self::need( $expected === $item_ids );
			// Structural guards needed for policy lookups; the existing repository
			// performs the authoritative target and full wire graph validation below.
			self::keys( $target, array( 'merchant_id', 'environment', 'application_id', 'api_version', 'shops', 'mapping_policy', 'existing_references' ) );
			self::list_value( $target['shops'], null );
			self::list_value( $target['existing_references'], null );
			$shops = array(); $references = array();
			foreach ( $target['shops'] as $shop ) {
				self::keys( $shop, array( 'id', 'slug', 'square_location_id' ) );
				self::need( is_string( $shop['slug'] ) && is_string( $shop['square_location_id'] ) );
				$shops[ $shop['slug'] ] = $shop['square_location_id'];
			}
			foreach ( $target['existing_references'] as $reference ) {
				self::keys( $reference, array( 'id', 'type', 'version' ) );
				self::need( is_string( $reference['id'] ) && is_string( $reference['type'] ) );
				$references[ $reference['id'] ] = $reference;
			}
			$availability = self::availability( $policy['availability'], $item_ids, array_keys( $shops ) );
			$photos = self::photos( $policy['photos'], $items, $references );
			self::tax( $policy['tax'], $references );
			$category_names = array_values( array_unique( array_column( $items, 'category' ), SORT_STRING ) );
			usort( $category_names, 'strcmp' );
			$categories = array(); $objects = array();
			foreach ( $category_names as $category ) {
				$id = self::temporary( 'category_' . hash( 'sha256', $category ) );
				$categories[ $category ] = $id;
				$objects[] = array( 'id' => $id, 'type' => 'CATEGORY', 'present_at_all_locations' => true, 'category_data' => array( 'name' => $category ) );
			}
			foreach ( $items as $item ) {
				$id = self::temporary( 'item_' . $item['website_item_id'] );
				$locations = array();
				foreach ( $shops as $slug => $location ) { if ( $availability[ $item['website_item_id'] ][ $slug ] ) { $locations[] = $location; } }
				sort( $locations, SORT_STRING );
				$presence = array( 'present_at_all_locations' => false, 'present_at_location_ids' => $locations, 'absent_at_location_ids' => array() );
				$info = array();
				foreach ( $item['modifier_groups'] as $group ) {
					$list_id = self::temporary( $group['id'] );
					$modifiers = array(); $overrides = array();
					foreach ( $group['choices'] as $choice ) {
						$modifier_id = self::temporary( $choice['id'] );
						$modifiers[] = array( 'id' => $modifier_id, 'type' => 'MODIFIER', 'modifier_data' => array( 'name' => $choice['label'], 'modifier_list_id' => $list_id, 'price_money' => array( 'amount' => $choice['price_delta_minor'], 'currency' => 'AUD' ) ) );
						$overrides[] = array( 'modifier_id' => $modifier_id, 'on_by_default' => $choice['default'] );
					}
					$single = 'radio' === $group['type'];
					$objects[] = array_merge( array( 'id' => $list_id, 'type' => 'MODIFIER_LIST' ), $presence, array( 'modifier_list_data' => array( 'name' => $group['label'], 'selection_type' => $single ? 'SINGLE' : 'MULTIPLE', 'modifiers' => $modifiers ) ) );
					$info[] = array( 'modifier_list_id' => $list_id, 'min_selected_modifiers' => $single ? 1 : 0, 'max_selected_modifiers' => $single ? 1 : count( $modifiers ), 'enabled' => true, 'modifier_overrides' => $overrides );
				}
				$photo = $photos[ $item['website_item_id'] ];
				$description = $item['description'];
				if ( 'representative_category' === $photo['policy'] ) { $description .= "\n\nRepresentative category photo: " . $photo['label']; }
				$description = htmlspecialchars( $description, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$variation = array_merge( array( 'id' => self::temporary( 'item_' . $item['website_item_id'] . '_variation' ), 'type' => 'ITEM_VARIATION' ), $presence, array( 'item_variation_data' => array( 'name' => 'Regular', 'item_id' => $id, 'pricing_type' => 'FIXED_PRICING', 'price_money' => array( 'amount' => $item['base_price_minor'], 'currency' => 'AUD' ) ) ) );
				$objects[] = array_merge( array( 'id' => $id, 'type' => 'ITEM' ), $presence, array( 'item_data' => array( 'name' => $item['name'], 'description_html' => $description, 'variations' => array( $variation ), 'modifier_list_info' => $info, 'tax_ids' => array( $policy['tax']['square_tax_id'] ), 'image_ids' => 'omit' === $photo['policy'] ? array() : array( $photo['square_image_id'] ), 'categories' => array( array( 'id' => $categories[ $item['category'] ] ) ) ) ) );
			}
			$request = array( 'idempotency_key' => $provider_key, 'batches' => array( array( 'objects' => $objects ) ) );
			$graph = DoughBoss_Square_Catalogue_Operations::validate_packet( $target, $request, $provider_key );
			self::need( ! is_wp_error( $graph ) );
			$by_type = array_fill_keys( array( 'ITEM', 'ITEM_VARIATION', 'MODIFIER_LIST', 'MODIFIER', 'CATEGORY' ), 0 );
			foreach ( $graph as $entry ) { ++$by_type[ $entry['type'] ]; }
			return array( 'source' => $source, 'target' => $target, 'request' => $request, 'counts' => array( 'unique_objects' => count( $graph ), 'top_level_objects' => count( $objects ), 'by_type' => $by_type ) );
		} catch ( UnexpectedValueException $error ) { return self::error(); }
	}

	private static function error() { return new WP_Error( 'doughboss_square_catalogue_request_invalid', 'The exact catalogue policy could not be compiled safely. No executable catalogue request or dispatch permission was returned.', array( 'status' => 409 ) ); }

	private static function availability( $rows, array $item_ids, array $shop_slugs ) {
		self::list_value( $rows, count( $item_ids ) );
		$map = array();
		foreach ( $rows as $row ) {
			self::keys( $row, array( 'website_item_id', 'shops' ) );
			$id = $row['website_item_id'];
			self::need( self::integer( $id ) && in_array( $id, $item_ids, true ) && ! isset( $map[ $id ] ) );
			self::keys( $row['shops'], $shop_slugs );
			foreach ( $row['shops'] as $flag ) { self::need( is_bool( $flag ) ); }
			$map[ $id ] = $row['shops'];
		}
		return $map;
	}

	private static function photos( $rows, array $items, array $references ) {
		self::list_value( $rows, count( $items ) );
		$categories = array_column( $items, 'category', 'website_item_id' );
		$map = array();
		foreach ( $rows as $photo ) {
			self::need( is_array( $photo ) && isset( $photo['policy'] ) && in_array( $photo['policy'], array( 'omit', 'exact', 'representative_category' ), true ) );
			$keys = array( 'website_item_id', 'approved', 'policy' );
			if ( 'omit' !== $photo['policy'] ) { $keys[] = 'square_image_id'; }
			if ( 'representative_category' === $photo['policy'] ) { $keys[] = 'category'; $keys[] = 'label'; }
			self::keys( $photo, $keys );
			$id = $photo['website_item_id'];
			self::need( self::integer( $id ) && isset( $categories[ $id ] ) && ! isset( $map[ $id ] ) && true === $photo['approved'] );
			if ( 'omit' !== $photo['policy'] ) { self::reference( $photo['square_image_id'], 'IMAGE', $references ); }
			if ( 'representative_category' === $photo['policy'] ) { self::need( $photo['category'] === $categories[ $id ] ); self::label( $photo['label'] ); }
			$map[ $id ] = $photo;
		}
		return $map;
	}

	private static function tax( $tax, array $references ) {
		self::keys( $tax, array( 'approved', 'enabled', 'square_tax_id', 'version', 'percentage', 'inclusion_type', 'calculation_phase', 'applies_to_custom_amounts', 'applies_to_product_set_id' ) );
		self::need( true === $tax['approved'] && true === $tax['enabled'] && is_bool( $tax['applies_to_custom_amounts'] ) && null === $tax['applies_to_product_set_id'] && in_array( $tax['inclusion_type'], array( 'INCLUSIVE', 'ADDITIVE' ), true ) && in_array( $tax['calculation_phase'], array( 'TAX_SUBTOTAL_PHASE', 'TAX_TOTAL_PHASE' ), true ) );
		self::need( is_string( $tax['percentage'] ) && strlen( $tax['percentage'] ) <= 20 && preg_match( '/^([0-9]{1,3})(?:\.([0-9]{1,4}))?$/D', $tax['percentage'], $match ) );
		self::need( (int) $match[1] < 100 || ( 100 === (int) $match[1] && ( ! isset( $match[2] ) || '' === trim( $match[2], '0' ) ) ) );
		$reference = self::reference( $tax['square_tax_id'], 'TAX', $references );
		self::need( self::integer( $tax['version'] ) && $tax['version'] === $reference['version'] );
	}

	private static function reference( $id, $type, array $references ) {
		self::need( is_string( $id ) && isset( $references[ $id ] ) && $references[ $id ]['type'] === $type && self::integer( $references[ $id ]['version'] ) );
		return $references[ $id ];
	}
	private static function temporary( $source_id ) { $id = '#' . $source_id; self::need( strlen( $id ) <= 191 && preg_match( '/^#[a-z][a-z0-9_-]*$/D', $id ) ); return $id; }
	private static function integer( $value ) { return is_int( $value ) && $value > 0 && $value <= 9007199254740991; }
	private static function need( $condition ) { if ( ! $condition ) { throw new UnexpectedValueException( 'Invalid catalogue compilation input.' ); } }
	private static function keys( $value, array $keys ) { self::need( is_array( $value ) ); $actual = array_keys( $value ); sort( $actual, SORT_STRING ); sort( $keys, SORT_STRING ); self::need( $actual === $keys ); }
	private static function list_value( $value, $exact_count ) { self::need( is_array( $value ) && array_values( $value ) === $value && ( null === $exact_count || count( $value ) === $exact_count ) ); }
	private static function label( $value ) {
		self::need( is_string( $value ) && '' !== $value && 1 === preg_match( '//u', $value ) && 1 === preg_match( '/[^\p{Z}\p{C}\s]/u', $value ) );
		$count = preg_match_all( '/./us', $value, $characters ); self::need( false !== $count );
		foreach ( $characters[0] as $character ) { if ( 4 === strlen( $character ) ) { ++$count; } }
		self::need( $count <= 200 );
	}
}
