<?php
/**
 * Copy the Revesby (master) Square menu into another shop's Square account.
 *
 * Each shop is a separate Square seller, so catalogue objects cannot be shared;
 * they are copied. Source: ListCatalog on the Revesby seller (CATEGORY,
 * MODIFIER_LIST with nested modifiers, ITEM with nested variations, TAX).
 * Target: BatchUpsertCatalogObjects on the target seller with "#"-prefixed
 * temporary ids, which Square replaces and reports in id_mappings.
 *
 * Square rules honoured (https://developer.squareup.com/reference/square/catalog-api/batch-upsert-catalog-objects):
 *  - a batch holds at most 1,000 objects (nested variations/modifiers count),
 *    a request at most 10,000;
 *  - "#" temporary ids are only valid inside ONE batch, so the copy runs in
 *    two phases: categories + modifier lists first, then items that point at
 *    the real ids returned for phase one;
 *  - batches are atomic; an error in one batch does not affect the others.
 *
 * Deliberately NOT copied (listed in the preview): images (re-upload in Square
 * Dashboard or a later release), item options, per-location price overrides,
 * custom attributes, inventory, and TAX objects — each item's taxes are mapped
 * onto the target seller's existing tax with the same name and percentage, or
 * dropped with a warning. Objects already copied (recorded in the id map) are
 * skipped; this is a one-way "add what is missing" copy, never an update or
 * delete in the target.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Master-menu copy between Square sellers.
 */
class DoughBoss_Square_Catalog_Copy {

	/** Non-autoloaded id map: [ mode ][ target_shop ] => { merchant_id, ids: { source_id: target_id } }. */
	const MAP_OPTION = 'doughboss_square_catalog_copy_map';

	/** Master shop. */
	const SOURCE = 'revesby';

	/** ListCatalog page cap (100 objects per page by default). */
	const MAX_PAGES = 60;

	/** Square batch limits. */
	const BATCH_MAX   = 1000;
	const REQUEST_MAX = 10000;

	/* ------------------------------------------------------------------ */
	/* Pure planning (unit tested)                                         */
	/* ------------------------------------------------------------------ */

	/** @return array Only the listed keys of $data that are present. */
	private static function pick( $data, array $keys ) {
		$out = array();
		if ( ! is_array( $data ) ) {
			return $out;
		}
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$out[ $key ] = $data[ $key ];
			}
		}
		return $out;
	}

	/** @return bool */
	private static function id_ok( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/D', $id );
	}

	/** @return string "name|percentage" tax identity. */
	public static function tax_key( $tax ) {
		$data = isset( $tax['tax_data'] ) && is_array( $tax['tax_data'] ) ? $tax['tax_data'] : array();
		$name = isset( $data['name'] ) && is_string( $data['name'] ) ? strtolower( trim( $data['name'] ) ) : '';
		$pct  = isset( $data['percentage'] ) && is_scalar( $data['percentage'] ) ? rtrim( rtrim( number_format( (float) $data['percentage'], 4, '.', '' ), '0' ), '.' ) : '';
		return $name . '|' . $pct;
	}

	/**
	 * Build the copy plan.
	 *
	 * @param array $source       Top-level ListCatalog objects from the master seller.
	 * @param array $target_taxes TAX objects already in the target seller.
	 * @param array $map          Already-copied ids { source_id: target_id }.
	 * @return array { categories, modifier_lists, items, counts, warnings }
	 *               Phase-two references to phase-one objects are "@<source_id>" placeholders.
	 */
	public static function build_plan( array $source, array $target_taxes, array $map ) {
		$warnings = array();
		$counts   = array( 'categories' => 0, 'modifier_lists' => 0, 'modifiers' => 0, 'items' => 0, 'variations' => 0, 'skipped_existing' => 0, 'skipped_other' => 0 );
		$target_tax_by_key = array();
		foreach ( $target_taxes as $tax ) {
			if ( is_array( $tax ) && isset( $tax['id'], $tax['type'] ) && 'TAX' === $tax['type'] && empty( $tax['is_deleted'] ) && self::id_ok( $tax['id'] ) ) {
				$target_tax_by_key[ self::tax_key( $tax ) ] = $tax['id'];
			}
		}
		$by_type = array( 'CATEGORY' => array(), 'MODIFIER_LIST' => array(), 'ITEM' => array(), 'TAX' => array() );
		foreach ( $source as $object ) {
			if ( ! is_array( $object ) || ! isset( $object['type'], $object['id'] ) || ! self::id_ok( $object['id'] ) || ! empty( $object['is_deleted'] ) || ! isset( $by_type[ $object['type'] ] ) ) {
				continue;
			}
			$by_type[ $object['type'] ][ $object['id'] ] = $object;
		}
		$tax_map = array();
		$images  = 0;
		$options = 0;
		$overrides = 0;
		foreach ( $by_type['TAX'] as $id => $tax ) {
			$key = self::tax_key( $tax );
			if ( isset( $target_tax_by_key[ $key ] ) ) {
				$tax_map[ $id ] = $target_tax_by_key[ $key ];
			} else {
				$warnings[] = sprintf( 'Tax "%s" has no matching tax (same name and percentage) in the target account; items will be copied without it. Create it in the target Square Dashboard and copy again for new items.', isset( $tax['tax_data']['name'] ) ? (string) $tax['tax_data']['name'] : $id );
			}
		}
		// Phase one: categories (one batch, parent links stay inside it).
		$categories = array();
		foreach ( $by_type['CATEGORY'] as $id => $object ) {
			if ( isset( $map[ $id ] ) ) {
				++$counts['skipped_existing'];
				continue;
			}
			$data = self::pick( isset( $object['category_data'] ) ? $object['category_data'] : array(), array( 'name', 'category_type', 'online_visibility', 'parent_category' ) );
			if ( isset( $data['parent_category'] ) ) {
				$parent = isset( $data['parent_category']['id'] ) ? $data['parent_category']['id'] : '';
				if ( isset( $map[ $parent ] ) ) {
					$data['parent_category'] = array( 'id' => $map[ $parent ] );
				} elseif ( isset( $by_type['CATEGORY'][ $parent ] ) ) {
					$data['parent_category'] = array( 'id' => '#' . $parent );
				} else {
					unset( $data['parent_category'] );
				}
			}
			$categories[] = array( 'type' => 'CATEGORY', 'id' => '#' . $id, 'present_at_all_locations' => true, 'category_data' => $data );
			++$counts['categories'];
		}
		// Phase one: modifier lists with nested modifiers (self-contained).
		$lists = array();
		foreach ( $by_type['MODIFIER_LIST'] as $id => $object ) {
			if ( isset( $map[ $id ] ) ) {
				++$counts['skipped_existing'];
				continue;
			}
			$data = self::pick( isset( $object['modifier_list_data'] ) ? $object['modifier_list_data'] : array(), array( 'name', 'ordinal', 'selection_type', 'modifier_type', 'internal_name', 'min_selected_modifiers', 'max_selected_modifiers', 'max_length', 'text_required' ) );
			$mods = array();
			$source_mods = isset( $object['modifier_list_data']['modifiers'] ) && is_array( $object['modifier_list_data']['modifiers'] ) ? $object['modifier_list_data']['modifiers'] : array();
			foreach ( $source_mods as $mod ) {
				if ( ! is_array( $mod ) || ! isset( $mod['id'] ) || ! self::id_ok( $mod['id'] ) || ! empty( $mod['is_deleted'] ) ) {
					continue;
				}
				if ( ! empty( $mod['modifier_data']['image_id'] ) ) {
					++$images;
				}
				$mod_data = self::pick( isset( $mod['modifier_data'] ) ? $mod['modifier_data'] : array(), array( 'name', 'price_money', 'ordinal', 'on_by_default' ) );
				$mod_data['modifier_list_id'] = '#' . $id;
				$mods[] = array( 'type' => 'MODIFIER', 'id' => '#' . $mod['id'], 'present_at_all_locations' => true, 'modifier_data' => $mod_data );
				++$counts['modifiers'];
			}
			if ( $mods ) {
				$data['modifiers'] = $mods;
			}
			$lists[] = array( 'type' => 'MODIFIER_LIST', 'id' => '#' . $id, 'present_at_all_locations' => true, 'modifier_list_data' => $data );
			++$counts['modifier_lists'];
		}
		// Phase two: items with nested variations.
		$items = array();
		foreach ( $by_type['ITEM'] as $id => $object ) {
			if ( isset( $map[ $id ] ) ) {
				++$counts['skipped_existing'];
				continue;
			}
			$source_data = isset( $object['item_data'] ) && is_array( $object['item_data'] ) ? $object['item_data'] : array();
			$type        = isset( $source_data['product_type'] ) ? $source_data['product_type'] : 'REGULAR';
			if ( ! in_array( $type, array( 'REGULAR', 'FOOD_AND_BEV' ), true ) ) {
				++$counts['skipped_other'];
				$warnings[] = sprintf( 'Item "%s" (%s) is not a regular/food item and was not copied.', isset( $source_data['name'] ) ? (string) $source_data['name'] : $id, is_string( $type ) ? $type : '?' );
				continue;
			}
			$data = self::pick( $source_data, array( 'name', 'abbreviation', 'label_color', 'is_taxable', 'available_online', 'available_for_pickup', 'available_electronically', 'product_type', 'skip_modifier_screen', 'sort_name', 'food_and_beverage_details' ) );
			if ( isset( $source_data['description_html'] ) && is_string( $source_data['description_html'] ) ) {
				$data['description_html'] = $source_data['description_html'];
			} elseif ( isset( $source_data['description'] ) ) {
				$data['description'] = $source_data['description'];
			}
			if ( ! empty( $source_data['image_ids'] ) ) {
				++$images;
			}
			if ( ! empty( $source_data['item_options'] ) ) {
				++$options;
			}
			$category_refs = array();
			if ( isset( $source_data['categories'] ) && is_array( $source_data['categories'] ) ) {
				foreach ( $source_data['categories'] as $category ) {
					if ( isset( $category['id'] ) && ( isset( $by_type['CATEGORY'][ $category['id'] ] ) || isset( $map[ $category['id'] ] ) ) ) {
						$ref = array( 'id' => '@' . $category['id'] );
						if ( isset( $category['ordinal'] ) ) {
							$ref['ordinal'] = $category['ordinal'];
						}
						$category_refs[] = $ref;
					}
				}
			} elseif ( isset( $source_data['category_id'] ) && ( isset( $by_type['CATEGORY'][ $source_data['category_id'] ] ) || isset( $map[ $source_data['category_id'] ] ) ) ) {
				$category_refs[] = array( 'id' => '@' . $source_data['category_id'] );
			}
			if ( $category_refs ) {
				$data['categories'] = $category_refs;
			}
			if ( isset( $source_data['reporting_category']['id'] ) && ( isset( $by_type['CATEGORY'][ $source_data['reporting_category']['id'] ] ) || isset( $map[ $source_data['reporting_category']['id'] ] ) ) ) {
				$data['reporting_category'] = array( 'id' => '@' . $source_data['reporting_category']['id'] );
			}
			if ( isset( $source_data['tax_ids'] ) && is_array( $source_data['tax_ids'] ) ) {
				$taxes = array();
				foreach ( $source_data['tax_ids'] as $tax_id ) {
					if ( isset( $tax_map[ $tax_id ] ) ) {
						$taxes[] = $tax_map[ $tax_id ];
					}
				}
				if ( $taxes ) {
					$data['tax_ids'] = array_values( array_unique( $taxes ) );
				}
			}
			if ( isset( $source_data['modifier_list_info'] ) && is_array( $source_data['modifier_list_info'] ) ) {
				$infos = array();
				foreach ( $source_data['modifier_list_info'] as $info ) {
					$list_id = isset( $info['modifier_list_id'] ) ? $info['modifier_list_id'] : '';
					if ( ! isset( $by_type['MODIFIER_LIST'][ $list_id ] ) && ! isset( $map[ $list_id ] ) ) {
						continue;
					}
					$copy = self::pick( $info, array( 'min_selected_modifiers', 'max_selected_modifiers', 'enabled', 'ordinal', 'hidden_from_customer' ) );
					$copy['modifier_list_id'] = '@' . $list_id;
					if ( isset( $info['modifier_overrides'] ) && is_array( $info['modifier_overrides'] ) ) {
						$copy['modifier_overrides'] = array();
						foreach ( $info['modifier_overrides'] as $override ) {
							if ( isset( $override['modifier_id'] ) && self::id_ok( $override['modifier_id'] ) ) {
								$copy['modifier_overrides'][] = array_merge( self::pick( $override, array( 'on_by_default' ) ), array( 'modifier_id' => '@' . $override['modifier_id'] ) );
							}
						}
					}
					$infos[] = $copy;
				}
				if ( $infos ) {
					$data['modifier_list_info'] = $infos;
				}
			}
			$variations = array();
			$source_variations = isset( $source_data['variations'] ) && is_array( $source_data['variations'] ) ? $source_data['variations'] : array();
			foreach ( $source_variations as $variation ) {
				if ( ! is_array( $variation ) || ! isset( $variation['id'] ) || ! self::id_ok( $variation['id'] ) || ! empty( $variation['is_deleted'] ) ) {
					continue;
				}
				$vdata = self::pick( isset( $variation['item_variation_data'] ) ? $variation['item_variation_data'] : array(), array( 'name', 'sku', 'ordinal', 'pricing_type', 'price_money', 'sellable', 'stockable' ) );
				if ( ! empty( $variation['item_variation_data']['location_overrides'] ) ) {
					++$overrides;
				}
				if ( ! empty( $variation['item_variation_data']['item_option_values'] ) ) {
					++$options;
				}
				$vdata['item_id'] = '#' . $id;
				$variations[] = array( 'type' => 'ITEM_VARIATION', 'id' => '#' . $variation['id'], 'present_at_all_locations' => true, 'item_variation_data' => $vdata );
				++$counts['variations'];
			}
			if ( ! $variations ) {
				++$counts['skipped_other'];
				$warnings[] = sprintf( 'Item "%s" has no variations and was not copied.', isset( $source_data['name'] ) ? (string) $source_data['name'] : $id );
				continue;
			}
			$data['variations'] = $variations;
			$items[] = array( 'type' => 'ITEM', 'id' => '#' . $id, 'present_at_all_locations' => true, 'item_data' => $data );
			++$counts['items'];
		}
		if ( $images ) {
			$warnings[] = sprintf( '%d item/modifier image(s) are not copied; upload photos in the target Square Dashboard.', $images );
		}
		if ( $options ) {
			$warnings[] = sprintf( '%d item option reference(s) (Square "options") are not copied; affected variations are copied by name only.', $options );
		}
		if ( $overrides ) {
			$warnings[] = sprintf( '%d per-location price/stock override(s) are not copied; the base price is used.', $overrides );
		}
		if ( count( $categories ) > self::BATCH_MAX ) {
			$warnings[] = 'More than 1,000 categories: copy refused (they must fit one atomic batch).';
		}
		return array( 'categories' => $categories, 'modifier_lists' => $lists, 'items' => $items, 'counts' => $counts, 'warnings' => $warnings );
	}

	/** Objects a top-level object occupies in a batch (itself plus nested). */
	public static function weight( array $object ) {
		if ( isset( $object['item_data']['variations'] ) ) {
			return 1 + count( $object['item_data']['variations'] );
		}
		if ( isset( $object['modifier_list_data']['modifiers'] ) ) {
			return 1 + count( $object['modifier_list_data']['modifiers'] );
		}
		return 1;
	}

	/**
	 * Split objects into batches (≤1,000 weighted objects each) and the batches
	 * into requests (≤10,000 each).
	 *
	 * @param array $objects Top-level objects.
	 * @return array<int,array<int,array>> requests → batches → objects.
	 */
	public static function chunk( array $objects ) {
		$batches = array();
		$current = array();
		$weight  = 0;
		foreach ( $objects as $object ) {
			$w = self::weight( $object );
			if ( $current && $weight + $w > self::BATCH_MAX ) {
				$batches[] = $current;
				$current   = array();
				$weight    = 0;
			}
			$current[] = $object;
			$weight   += $w;
		}
		if ( $current ) {
			$batches[] = $current;
		}
		$requests = array();
		$req      = array();
		$total    = 0;
		foreach ( $batches as $batch ) {
			$w = array_sum( array_map( array( __CLASS__, 'weight' ), $batch ) );
			if ( $req && $total + $w > self::REQUEST_MAX ) {
				$requests[] = $req;
				$req        = array();
				$total      = 0;
			}
			$req[]  = $batch;
			$total += $w;
		}
		if ( $req ) {
			$requests[] = $req;
		}
		return $requests;
	}

	/**
	 * Replace "@<source_id>" placeholders with copied target ids; references
	 * that were not copied are removed.
	 *
	 * @param array $item ITEM object from the plan.
	 * @param array $map  { source_id: target_id }.
	 * @return array
	 */
	public static function resolve_item( array $item, array $map ) {
		$resolve = static function ( $value ) use ( $map ) {
			return is_string( $value ) && 0 === strpos( $value, '@' ) && isset( $map[ substr( $value, 1 ) ] ) ? $map[ substr( $value, 1 ) ] : null;
		};
		$data = $item['item_data'];
		if ( isset( $data['categories'] ) ) {
			$cats = array();
			foreach ( $data['categories'] as $category ) {
				$id = $resolve( $category['id'] );
				if ( null !== $id ) {
					$category['id'] = $id;
					$cats[]         = $category;
				}
			}
			if ( $cats ) {
				$data['categories'] = $cats;
			} else {
				unset( $data['categories'] );
			}
		}
		if ( isset( $data['reporting_category'] ) ) {
			$id = $resolve( $data['reporting_category']['id'] );
			if ( null === $id ) {
				unset( $data['reporting_category'] );
			} else {
				$data['reporting_category'] = array( 'id' => $id );
			}
		}
		if ( isset( $data['modifier_list_info'] ) ) {
			$infos = array();
			foreach ( $data['modifier_list_info'] as $info ) {
				$id = $resolve( $info['modifier_list_id'] );
				if ( null === $id ) {
					continue;
				}
				$info['modifier_list_id'] = $id;
				if ( isset( $info['modifier_overrides'] ) ) {
					$overrides = array();
					foreach ( $info['modifier_overrides'] as $override ) {
						$mid = $resolve( $override['modifier_id'] );
						if ( null !== $mid ) {
							$override['modifier_id'] = $mid;
							$overrides[]             = $override;
						}
					}
					if ( $overrides ) {
						$info['modifier_overrides'] = $overrides;
					} else {
						unset( $info['modifier_overrides'] );
					}
				}
				$infos[] = $info;
			}
			if ( $infos ) {
				$data['modifier_list_info'] = $infos;
			} else {
				unset( $data['modifier_list_info'] );
			}
		}
		$item['item_data'] = $data;
		return $item;
	}

	/** Stable digest of what an apply would send. */
	public static function plan_hash( array $plan, array $target_binding ) {
		return hash( 'sha256', wp_json_encode( array( $plan['categories'], $plan['modifier_lists'], $plan['items'], $target_binding ) ) );
	}

	/** @return array id_mappings → { source_id: target_id } for "#" client ids. */
	public static function mappings( $response ) {
		$out = array();
		if ( ! is_array( $response ) || ! isset( $response['id_mappings'] ) || ! is_array( $response['id_mappings'] ) ) {
			return $out;
		}
		foreach ( $response['id_mappings'] as $mapping ) {
			if ( isset( $mapping['client_object_id'], $mapping['object_id'] ) && is_string( $mapping['client_object_id'] ) && 0 === strpos( $mapping['client_object_id'], '#' )
				&& self::id_ok( substr( $mapping['client_object_id'], 1 ) ) && self::id_ok( $mapping['object_id'] ) ) {
				$out[ substr( $mapping['client_object_id'], 1 ) ] = $mapping['object_id'];
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Provider I/O                                                        */
	/* ------------------------------------------------------------------ */

	/** @return array|WP_Error Binding for a shop slug. */
	private static function binding( $shop ) {
		$native = DoughBoss_Square_OAuth::native_location_id( $shop );
		if ( ! $native ) {
			return new WP_Error( 'doughboss_catalog_copy', __( 'That shop does not exist on this site.', 'doughboss' ) );
		}
		return DoughBoss_Square::shop_binding( $native );
	}

	/** @return array|WP_Error All top-level objects of the given types. */
	private static function list_all( array $binding, $types ) {
		$objects = array();
		$cursor  = '';
		for ( $page = 0; $page < self::MAX_PAGES; ++$page ) {
			$path = '/v2/catalog/list?types=' . rawurlencode( $types ) . ( '' !== $cursor ? '&cursor=' . rawurlencode( $cursor ) : '' );
			$res  = DoughBoss_Square::catalog_copy_request( 'GET', $path, array(), $binding );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			if ( isset( $res['objects'] ) && is_array( $res['objects'] ) ) {
				foreach ( $res['objects'] as $object ) {
					$objects[] = $object;
				}
			}
			$cursor = isset( $res['cursor'] ) && is_string( $res['cursor'] ) ? $res['cursor'] : '';
			if ( '' === $cursor ) {
				return $objects;
			}
		}
		return new WP_Error( 'doughboss_catalog_copy', __( 'The master catalogue is larger than this copy tool supports.', 'doughboss' ) );
	}

	/** @return array Already-copied ids for a target (empty when it now points at another seller). */
	private static function stored_map( $target, array $binding ) {
		$all  = get_option( self::MAP_OPTION, array() );
		$mode = $binding['square_mode'];
		$row  = is_array( $all ) && isset( $all[ $mode ][ $target ] ) && is_array( $all[ $mode ][ $target ] ) ? $all[ $mode ][ $target ] : array();
		if ( ! isset( $row['merchant_id'], $row['ids'] ) || ! isset( $binding['merchant_id'] ) || $row['merchant_id'] !== $binding['merchant_id'] || ! is_array( $row['ids'] ) ) {
			return array();
		}
		return $row['ids'];
	}

	/** @return void */
	private static function save_map( $target, array $binding, array $ids ) {
		$all = get_option( self::MAP_OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		$all[ $binding['square_mode'] ][ $target ] = array( 'merchant_id' => $binding['merchant_id'], 'ids' => $ids, 'updated_at' => time() );
		update_option( self::MAP_OPTION, $all, false );
	}

	/**
	 * Read both sellers and build the plan (no writes anywhere).
	 *
	 * @param string $target Target shop.
	 * @return array|WP_Error { plan, hash, target_binding }
	 */
	public static function preview( $target ) {
		if ( ! DoughBoss_Square_OAuth::valid_shop( $target ) || self::SOURCE === $target || ! DoughBoss_Square_OAuth::is_connected( $target ) ) {
			return new WP_Error( 'doughboss_catalog_copy', __( 'Connect the target shop’s own Square account (and pick its location) before copying the menu.', 'doughboss' ) );
		}
		$source_binding = self::binding( self::SOURCE );
		$target_binding = self::binding( $target );
		if ( is_wp_error( $source_binding ) || is_wp_error( $target_binding ) || ! isset( $target_binding['merchant_id'] ) ) {
			return new WP_Error( 'doughboss_catalog_copy', __( 'Both Revesby and the target shop need a mapped Square location in the current mode.', 'doughboss' ) );
		}
		if ( $source_binding['square_location_id'] === $target_binding['square_location_id'] || ( isset( $source_binding['merchant_id'] ) && $source_binding['merchant_id'] === $target_binding['merchant_id'] ) ) {
			return new WP_Error( 'doughboss_catalog_copy', __( 'The target shop is connected to the same Square account as Revesby — nothing to copy.', 'doughboss' ) );
		}
		$source = self::list_all( $source_binding, 'CATEGORY,MODIFIER_LIST,ITEM,TAX' );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$taxes = self::list_all( $target_binding, 'TAX' );
		if ( is_wp_error( $taxes ) ) {
			return $taxes;
		}
		$plan = self::build_plan( $source, $taxes, self::stored_map( $target, $target_binding ) );
		return array( 'plan' => $plan, 'hash' => self::plan_hash( $plan, $target_binding ), 'target_binding' => $target_binding );
	}

	/**
	 * Apply a previewed plan. The plan is rebuilt from current data and must
	 * hash to the previewed value, so what the owner approved is what is sent.
	 *
	 * @param string $target        Target shop.
	 * @param string $expected_hash Hash shown in the preview.
	 * @return array|WP_Error { created, errors }
	 */
	public static function apply( $target, $expected_hash ) {
		$preview = self::preview( $target );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		if ( ! is_string( $expected_hash ) || ! hash_equals( $preview['hash'], $expected_hash ) ) {
			return new WP_Error( 'doughboss_catalog_copy_changed', __( 'The menu or the target account changed since the preview. Preview again before copying.', 'doughboss' ) );
		}
		$plan    = $preview['plan'];
		$binding = $preview['target_binding'];
		if ( count( $plan['categories'] ) > self::BATCH_MAX ) {
			return new WP_Error( 'doughboss_catalog_copy', __( 'Too many categories to copy in one atomic batch.', 'doughboss' ) );
		}
		$map     = self::stored_map( $target, $binding );
		$created = 0;
		$errors  = array();
		// Phase one: categories in one batch (parent links), then modifier lists.
		$phase_one = array();
		if ( $plan['categories'] ) {
			$phase_one[] = array( $plan['categories'] );
		}
		foreach ( self::chunk( $plan['modifier_lists'] ) as $request ) {
			$phase_one[] = $request;
		}
		foreach ( array( 1, 2 ) as $phase ) {
			if ( 2 === $phase ) {
				$items = array();
				foreach ( $plan['items'] as $item ) {
					$items[] = self::resolve_item( $item, $map );
				}
				$requests = self::chunk( $items );
			} else {
				$requests = $phase_one;
			}
			foreach ( $requests as $index => $batches ) {
				$body = array(
					'idempotency_key' => substr( hash( 'sha256', 'doughboss-catalog-copy|' . $target . '|' . $expected_hash . '|' . $phase . '|' . $index ), 0, 64 ),
					'batches'         => array_map( static function ( $objects ) { return array( 'objects' => $objects ); }, $batches ),
				);
				$res = DoughBoss_Square::catalog_copy_request( 'POST', '/v2/catalog/batch-upsert', $body, $binding );
				if ( is_wp_error( $res ) ) {
					$data     = $res->get_error_data();
					$errors[] = sprintf( 'Phase %d request %d failed (%s).', $phase, $index + 1, is_array( $data ) && ! empty( $data['square_code'] ) ? $data['square_code'] : $res->get_error_code() );
					continue;
				}
				$new      = self::mappings( $res );
				$created += count( $new );
				foreach ( $new as $source_id => $target_id ) {
					$map[ $source_id ] = $target_id;
				}
				self::save_map( $target, $binding, $map );
			}
		}
		return array( 'created' => $created, 'errors' => $errors );
	}
}
