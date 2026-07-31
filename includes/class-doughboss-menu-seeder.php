<?php
/**
 * Menu seeder — populates the doughboss_item CPT with the in-store menu boards.
 *
 * Shared by the WP-CLI command (`wp doughboss seed-menu`) and the admin
 * "Import standard menu" button, so the menu can be created with one click even
 * where WP-CLI isn't available. Idempotent: items are matched by the stable
 * `_doughboss_seed_key` marker meta (falling back to exact title once for items
 * seeded before the marker existed) and UPDATED (not duplicated), so renaming a
 * seeded item in wp-admin no longer duplicates it on re-run. Each item is also
 * stamped meta `_doughboss_seed = v1`.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static seeder. Holds the canonical board data and the create/update routine.
 */
class DoughBoss_Menu_Seeder {

	/**
	 * The Dough Boss in-store menu boards as data: category => list of
	 * [ name, price, type(standard|pizza|side|drink), dietary[], description ].
	 * Prices are GST-inclusive AUD; everything is halal.
	 *
	 * @return array<string,array<int,array>>
	 */
	public static function menu_data() {
		return array(
			'Manoush'  => array(
				array( 'Zaatar', 4.50, 'standard', array( 'vegan', 'halal' ), 'Dried thyme, sumac & toasted sesame mixed with olive oil — served flat or folded.' ),
				array( 'Zaatar & Cheese', 8.50, 'standard', array( 'vegetarian', 'halal' ), 'Zaatar on one half, blended cheese on the other — flat or folded.' ),
				array( 'Cheese', 9.50, 'standard', array( 'vegetarian', 'halal' ), 'A beautiful mix of our blended cheese, baked golden.' ),
				array( 'Meat', 9.00, 'standard', array( 'halal' ), 'Minced lamb blended with spices, onions & tomatoes — flat or folded.' ),
				array( 'Meat & Cheese', 11.00, 'standard', array( 'halal' ), 'Minced lamb with spices, topped with melted cheese.' ),
				array( 'Sujuk & Cheese', 11.00, 'standard', array( 'halal' ), 'Spiced sujuk with melted cheese.' ),
				array( 'Half Meat & Cheese', 11.00, 'standard', array( 'halal' ), 'Half minced meat and half blended cheese.' ),
				array( 'Cheese, Tomato & Olives', 9.50, 'standard', array( 'vegetarian', 'halal' ), 'Blended cheese with tomato and olives.' ),
				array( 'Cheese Kaak', 9.50, 'standard', array( 'vegetarian', 'halal' ), 'Cheese baked in a sesame kaak bread.' ),
			),
			'Pizza'    => array(
				array( 'Zaatar Veggie Pizza', 13.00, 'pizza', array( 'vegetarian', 'halal' ), 'Zaatar with tomato, olives and cheese. Choose your menu sauce and customise at checkout.' ),
				array( 'Labneh Veggie Pizza', 13.00, 'pizza', array( 'vegetarian', 'halal' ), 'Labneh, tomato, olives and fresh vegetables. Choose your menu sauce and customise at checkout.' ),
				array( 'All Meat', 15.00, 'pizza', array( 'halal' ), 'Pepperoni, sujuk, chicken & cheese on a BBQ sauce base.' ),
				array( 'Sujuk Deluxe', 14.00, 'pizza', array( 'halal' ), 'Spiced beef sausage with tomato, capsicum, mushroom, olives & cheese.' ),
				array( 'Spinach Deluxe', 13.00, 'pizza', array( 'vegetarian', 'halal' ), 'Spinach mix, mushroom, tomato, olives & cheese.' ),
				array( 'Veggie Plus', 13.00, 'pizza', array( 'vegetarian', 'halal' ), 'Cheese, tomato, olives, capsicum, onion & mushroom on a garlic sauce base.' ),
				array( 'Pepperoni & Cheese', 13.00, 'pizza', array( 'halal' ), 'A perfect blend of pepperoni & cheese on a tomato sauce base.' ),
				array( 'Dough Boss Special', 15.00, 'pizza', array( 'halal' ), 'Pepperoni, tomato, mushroom, capsicum, onion, black olives & cheese on a tomato base.' ),
				array( 'Chicken & Cheese', 14.00, 'pizza', array( 'halal' ), 'Grilled chicken & mushroom on garlic sauce, topped with cheese.' ),
				array( 'BBQ Chicken', 14.00, 'pizza', array( 'halal' ), 'BBQ sauce base with chicken, onion, capsicum, mushroom & cheese.' ),
				array( 'Peri Peri Chicken', 14.00, 'pizza', array( 'halal' ), 'Grilled chicken, mushroom, capsicum, onion & cheese, finished with peri peri sauce.' ),
				array( 'Garlic Prawns', 15.00, 'pizza', array( 'halal' ), 'Prawns, mushroom, onion, capsicum & cheese on a garlic-tomato base.' ),
			),
			'Pies'     => array(
				array( 'Spinach Pie', 10.00, 'standard', array( 'vegetarian', 'halal' ), 'A triangular turnover of spinach, onion, lemon, spices and cheese.' ),
				array( 'Haloumi', 11.00, 'standard', array( 'vegetarian', 'halal' ), 'Delicious haloumi cheese baked in a pie.' ),
				array( 'Dough Boss Pie', 11.00, 'standard', array( 'halal' ), 'Grilled chicken, capsicum, mushroom & cheese.' ),
				array( 'Aged Cheese', 10.00, 'standard', array( 'vegetarian', 'halal' ), 'Aged white cheese (shanklish) with diced tomatoes & onions.' ),
			),
			'Wraps'    => array(
				array( 'Zaatar & Veggie', 8.50, 'standard', array( 'vegan', 'halal' ), 'Zaatar with fresh tomato, cucumber, olives & mint. Add labneh or cheese +$2.50 each.' ),
				array( 'Labneh Veggie Wrap', 8.50, 'standard', array( 'vegetarian', 'halal' ), 'Labneh with fresh tomato, cucumber, olives and mint. Add cheese +$2.50 if you like.' ),
				array( 'Chicken Delight', 14.00, 'standard', array( 'halal' ), 'Grilled chicken, fresh tomato, lettuce, pickled cucumber & garlic mayo.' ),
				array( 'Ultimate Chicken', 14.00, 'standard', array( 'halal' ), 'Grilled chicken, melted cheese, mushroom, capsicum & lettuce, topped with mayo.' ),
				array( 'Dough Boss Wrap', 14.00, 'standard', array( 'halal' ), 'Sujuk, fresh tomato, pickled cucumber, lettuce & cheese, topped with mayo.' ),
			),
			'Desserts' => array(
				array( 'Choco Banana', 13.00, 'standard', array( 'vegetarian', 'halal' ), 'Nutella chocolate & banana baked in a pie.' ),
			),
			'Drinks'   => array(
				array( 'Spring Water', 3.50, 'drink', array(), 'Still, chilled.' ),
				array( 'Soft Drinks 600ml', 5.00, 'drink', array(), 'Coke, Sprite, Fanta or Solo — ice-cold.' ),
				array( 'Juice', 4.50, 'drink', array(), 'Chilled fruit juice.' ),
			),
		);
	}

	/**
	 * Create/update the menu items + category terms.
	 *
	 * @param bool $dry When true, report what would change without writing.
	 * @return array{created:int,updated:int,categories:int,total:int,dry:bool}
	 */
	public static function seed( $dry = false ) {
		$post_type = DoughBoss_Post_Types::POST_TYPE;
		$taxonomy  = DoughBoss_Post_Types::TAXONOMY;

		$created = 0;
		$updated = 0;
		$cats    = 0;
		$total   = 0;

		foreach ( self::menu_data() as $cat_name => $items ) {
			$term    = term_exists( $cat_name, $taxonomy );
			$term_id = 0;
			if ( ! $term ) {
				if ( $dry ) {
					++$cats;
				} else {
					$new = wp_insert_term( $cat_name, $taxonomy );
					if ( is_wp_error( $new ) ) {
						continue;
					}
					$term_id = (int) $new['term_id'];
					++$cats;
				}
			} else {
				$term_id = (int) ( is_array( $term ) ? $term['term_id'] : $term );
			}

			foreach ( $items as $item ) {
				++$total;
				list( $name, $price, $type, $diet, $desc ) = $item;

				// Match on the stable seed-key marker first so an item renamed in
				// wp-admin is still recognised (and updated, not duplicated).
				$seed_key = sanitize_title( $cat_name . ' ' . $name );
				$existing = get_posts(
					array(
						'post_type'        => $post_type,
						'post_status'      => 'any',
						'posts_per_page'   => 1,
						'fields'           => 'ids',
						'meta_key'         => '_doughboss_seed_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_value'       => $seed_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
						'suppress_filters' => false,
					)
				);
				if ( empty( $existing ) ) {
					// Two menu-board corrections rename existing seeded products. Match
					// their retired stable keys once so re-importing updates rather than
					// duplicates the live menu item.
					$legacy_seed_keys = array(
						'pies-dough-boss-pie' => 'pies-chicken-pie',
						'pies-spinach-pie'    => 'pies-spinach-cheese',
					);
					if ( isset( $legacy_seed_keys[ $seed_key ] ) ) {
						$existing = get_posts(
							array(
								'post_type'        => $post_type,
								'post_status'      => 'any',
								'posts_per_page'   => 1,
								'fields'           => 'ids',
								'meta_key'         => '_doughboss_seed_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
								'meta_value'       => $legacy_seed_keys[ $seed_key ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
								'suppress_filters' => false,
							)
						);
					}
				}
				if ( empty( $existing ) ) {
					// Legacy fallback: items seeded before the marker existed only
					// match by title; they get stamped with the key below.
					$existing = get_posts(
						array(
							'post_type'        => $post_type,
							'post_status'      => 'any',
							'title'            => $name,
							'posts_per_page'   => 1,
							'fields'           => 'ids',
							'suppress_filters' => false,
						)
					);
				}
				$post_id = ! empty( $existing ) ? (int) $existing[0] : 0;
				if ( $dry ) {
					if ( $post_id ) {
						++$updated;
					} else {
						++$created;
					}
					continue;
				}
				$postarr = array(
					'post_type'    => $post_type,
					'post_status'  => 'publish',
					'post_title'   => $name,
					'post_content' => $desc,
				);
				if ( $post_id ) {
					$postarr['ID'] = $post_id;
					wp_update_post( $postarr );
					++$updated;
				} else {
					$post_id = (int) wp_insert_post( $postarr );
					++$created;
				}
				if ( ! $post_id ) {
					continue;
				}
				update_post_meta( $post_id, DoughBoss_Post_Types::META_PRICE, number_format( (float) $price, 2, '.', '' ) );
				update_post_meta( $post_id, DoughBoss_Post_Types::META_TYPE, $type );
				update_post_meta( $post_id, DoughBoss_Post_Types::META_AVAILABLE, '1' );
				update_post_meta( $post_id, DoughBoss_Post_Types::META_DIETARY, array_values( $diet ) );
				update_post_meta( $post_id, '_doughboss_seed', 'v1' );
				update_post_meta( $post_id, '_doughboss_seed_key', $seed_key );
				if ( $term_id ) {
					wp_set_object_terms( $post_id, array( $term_id ), $taxonomy, false );
				}
			}
		}

		return array(
			'created'    => $created,
			'updated'    => $updated,
			'categories' => $cats,
			'total'      => $total,
			'dry'        => (bool) $dry,
		);
	}
}
