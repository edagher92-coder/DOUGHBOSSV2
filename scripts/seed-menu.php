<?php
/**
 * Dough Boss — menu seeder (v1 in-store boards).
 *
 * Populates the `doughboss_item` CPT with the real Dough Boss menu — Manoush,
 * Pizza, Pies, Wraps, Desserts, Drinks — with prices, categories and dietary
 * flags, so the WordPress storefront matches the in-store menu boards.
 *
 * IDEMPOTENT: items are matched by exact title; re-running UPDATES the existing
 * item (price/type/dietary/category/description) instead of creating a duplicate.
 * Each seeded item is stamped with meta `_doughboss_seed = v1` so you can find
 * (or bulk-remove) the seeded set later.
 *
 * Usage (run on the WordPress site, with the DoughBoss plugin active):
 *
 *   wp eval-file scripts/seed-menu.php            # create/update the menu
 *   wp eval-file scripts/seed-menu.php dry-run    # report only, write nothing
 *
 * Prices are GST-inclusive AUD, taken from the owner's menu boards. Everything
 * is halal; vegan/vegetarian flags follow the boards.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$db_seed_dry = isset( $args ) && is_array( $args ) && in_array( 'dry-run', $args, true );

/**
 * Emit a line through WP-CLI when available, otherwise echo.
 *
 * @param string $line Message.
 * @return void
 */
function db_seed_log( $line ) {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::log( $line );
	} else {
		echo $line, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

$db_post_type = 'doughboss_item';     // DoughBoss_Post_Types::POST_TYPE
$db_taxonomy  = 'doughboss_category'; // DoughBoss_Post_Types::TAXONOMY
$db_meta_price     = '_doughboss_price';
$db_meta_type      = '_doughboss_item_type';
$db_meta_available = '_doughboss_available';
$db_meta_dietary   = '_doughboss_dietary';
$db_meta_seed      = '_doughboss_seed';

if ( ! post_type_exists( $db_post_type ) ) {
	db_seed_log( 'ERROR: the DoughBoss plugin is not active (post type ' . $db_post_type . ' missing). Aborting.' );
	return;
}

/*
 * Menu data. Categories are ordered; each item: name, price, type
 * (standard|pizza|side|drink), dietary flags (vegan|vegetarian|gluten_free|halal),
 * and a short description.
 */
$db_menu = array(
	'Manoush' => array(
		array( 'Zaatar', 4.50, 'standard', array( 'vegan', 'halal' ), 'Dried thyme, sumac & toasted sesame mixed with olive oil — served flat or folded.' ),
		array( 'Zaatar & Cheese', 8.50, 'standard', array( 'vegetarian', 'halal' ), 'Zaatar on one half, blended cheese on the other — flat or folded.' ),
		array( 'Cheese', 9.50, 'standard', array( 'vegetarian', 'halal' ), 'A beautiful mix of our blended cheese, baked golden.' ),
		array( 'Meat', 9.00, 'standard', array( 'halal' ), 'Minced lamb blended with spices, onions & tomatoes — flat or folded.' ),
		array( 'Meat & Cheese', 11.00, 'standard', array( 'halal' ), 'Minced lamb with spices, topped with melted cheese.' ),
	),
	'Pizza' => array(
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
	'Pies' => array(
		array( 'Spinach & Cheese', 10.00, 'standard', array( 'vegetarian', 'halal' ), 'A triangular turnover of spinach, onion, lemon, spices & cheese.' ),
		array( 'Haloumi', 11.00, 'standard', array( 'vegetarian', 'halal' ), 'Delicious haloumi cheese baked in a pie.' ),
		array( 'Chicken Pie', 11.00, 'standard', array( 'halal' ), 'Grilled chicken, capsicum, mushroom & cheese.' ),
		array( 'Aged Cheese', 10.00, 'standard', array( 'vegetarian', 'halal' ), 'Aged white cheese (shanklish) with diced tomatoes & onions.' ),
	),
	'Wraps' => array(
		array( 'Zaatar & Veggie', 8.50, 'standard', array( 'vegan', 'halal' ), 'Zaatar with fresh tomato, cucumber, olives & mint. Add labneh or cheese +$2.50 each.' ),
		array( 'Chicken Delight', 14.00, 'standard', array( 'halal' ), 'Grilled chicken, fresh tomato, lettuce, pickled cucumber & garlic mayo.' ),
		array( 'Ultimate Chicken', 14.00, 'standard', array( 'halal' ), 'Grilled chicken, melted cheese, mushroom, capsicum & lettuce, topped with mayo.' ),
		array( 'Dough Boss Wrap', 14.00, 'standard', array( 'halal' ), 'Sujuk, fresh tomato, pickled cucumber, lettuce & cheese, topped with mayo.' ),
	),
	'Desserts' => array(
		array( 'Choco Banana', 13.00, 'standard', array( 'vegetarian', 'halal' ), 'Nutella chocolate & banana baked in a pie.' ),
	),
	'Drinks' => array(
		array( 'Spring Water', 3.50, 'drink', array(), 'Still, chilled.' ),
		array( 'Soft Drinks 600ml', 5.00, 'drink', array(), 'Coke, Sprite, Fanta or Solo — ice-cold.' ),
		array( 'Juice', 4.50, 'drink', array(), 'Chilled fruit juice.' ),
	),
);

$db_created = 0;
$db_updated = 0;
$db_cat_made = 0;

db_seed_log( ( $db_seed_dry ? '[DRY RUN] ' : '' ) . 'Seeding Dough Boss menu (' . count( $db_menu ) . ' categories)…' );

foreach ( $db_menu as $db_cat_name => $db_items ) {

	// Ensure the category term exists.
	$db_term = term_exists( $db_cat_name, $db_taxonomy );
	if ( ! $db_term ) {
		if ( $db_seed_dry ) {
			db_seed_log( "  + category: {$db_cat_name} (would create)" );
			$db_term_id = 0;
		} else {
			$db_new = wp_insert_term( $db_cat_name, $db_taxonomy );
			if ( is_wp_error( $db_new ) ) {
				db_seed_log( "  ! category {$db_cat_name}: " . $db_new->get_error_message() );
				continue;
			}
			$db_term_id = (int) $db_new['term_id'];
			++$db_cat_made;
			db_seed_log( "  + category: {$db_cat_name}" );
		}
	} else {
		$db_term_id = (int) ( is_array( $db_term ) ? $db_term['term_id'] : $db_term );
	}

	foreach ( $db_items as $db_item ) {
		list( $db_name, $db_price, $db_type, $db_diet, $db_desc ) = $db_item;

		// Find an existing item by exact title (idempotent).
		$db_existing = get_posts(
			array(
				'post_type'        => $db_post_type,
				'post_status'      => 'any',
				'title'            => $db_name,
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		$db_post_id = ! empty( $db_existing ) ? (int) $db_existing[0] : 0;

		if ( $db_seed_dry ) {
			db_seed_log( sprintf( '    %s %-22s $%-6.2f [%s] %s', $db_post_id ? '~' : '+', $db_name, $db_price, $db_type, implode( ',', $db_diet ) ) );
			continue;
		}

		$db_postarr = array(
			'post_type'    => $db_post_type,
			'post_status'  => 'publish',
			'post_title'   => $db_name,
			'post_content' => $db_desc,
		);
		if ( $db_post_id ) {
			$db_postarr['ID'] = $db_post_id;
			wp_update_post( $db_postarr );
			++$db_updated;
		} else {
			$db_post_id = (int) wp_insert_post( $db_postarr );
			++$db_created;
		}
		if ( ! $db_post_id ) {
			db_seed_log( "    ! failed: {$db_name}" );
			continue;
		}

		update_post_meta( $db_post_id, $db_meta_price, number_format( (float) $db_price, 2, '.', '' ) );
		update_post_meta( $db_post_id, $db_meta_type, $db_type );
		update_post_meta( $db_post_id, $db_meta_available, '1' );
		update_post_meta( $db_post_id, $db_meta_dietary, array_values( $db_diet ) );
		update_post_meta( $db_post_id, $db_meta_seed, 'v1' );
		if ( $db_term_id ) {
			wp_set_object_terms( $db_post_id, array( $db_term_id ), $db_taxonomy, false );
		}
		db_seed_log( sprintf( '    %s %-22s $%-6.2f', $db_post_id ? 'ok' : '..', $db_name, $db_price ) );
	}
}

db_seed_log(
	( $db_seed_dry ? '[DRY RUN] ' : '' ) .
	"Done. Categories created: {$db_cat_made}; items created: {$db_created}; items updated: {$db_updated}."
);
