<?php
/**
 * Seed a synthetic storefront in the disposable integration site.
 *
 * This fixture contains invented products and must never run on production.
 *
 * @package DoughBoss
 */

if ( 'local' !== wp_get_environment_type() || 'doughboss_wp_test' !== DB_NAME ) {
	fwrite( STDERR, "Refusing to seed outside the disposable DoughBoss integration site.\n" );
	exit( 2 );
}

DoughBoss_Post_Types::register();
$existing = get_posts(
	array(
		'post_type'      => DoughBoss_Post_Types::POST_TYPE,
		'post_status'    => 'any',
		'numberposts'    => -1,
		'fields'         => 'ids',
	)
);
foreach ( $existing as $post_id ) {
	wp_delete_post( $post_id, true );
}

$items = array(
	array( 'Manoush', 'Zaatar & Cheese', 'A synthetic test item with fresh herbs.', 8.50, 'manoush', array( 'vegetarian' ) ),
	array( 'Manoush', 'Labneh Veggie', 'A synthetic test item with vegetables.', 11.50, 'manoush', array( 'vegetarian' ) ),
	array( 'Pizza', 'Sujuk Deluxe', 'A synthetic test pizza with selectable options.', 17.50, 'pizza', array( 'halal' ) ),
	array( 'Pizza', 'Veggie Plus', 'A synthetic test pizza with colourful vegetables.', 16.00, 'pizza', array( 'vegetarian' ) ),
	array( 'Pies', 'Spinach Pie', 'A synthetic baked spinach pie.', 7.00, 'pie', array( 'vegan' ) ),
	array( 'Drinks', 'Spring Water', 'A synthetic bottled drink.', 3.00, 'drink', array() ),
);

foreach ( $items as $position => $item ) {
	$term = term_exists( $item[0], DoughBoss_Post_Types::TAXONOMY );
	if ( ! $term ) {
		$term = wp_insert_term( $item[0], DoughBoss_Post_Types::TAXONOMY );
	}
	$term_id = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
	$post_id = wp_insert_post(
		array(
			'post_type'    => DoughBoss_Post_Types::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => $item[1],
			'post_excerpt' => $item[2],
			'menu_order'   => $position,
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		fwrite( STDERR, $post_id->get_error_message() . "\n" );
		exit( 1 );
	}
	wp_set_object_terms( $post_id, array( $term_id ), DoughBoss_Post_Types::TAXONOMY );
	update_post_meta( $post_id, DoughBoss_Post_Types::META_PRICE, $item[3] );
	update_post_meta( $post_id, DoughBoss_Post_Types::META_TYPE, $item[4] );
	update_post_meta( $post_id, DoughBoss_Post_Types::META_AVAILABLE, '1' );
	update_post_meta( $post_id, DoughBoss_Post_Types::META_DIETARY, $item[5] );
}

$settings = DoughBoss_Settings::all();
$settings['ordering_open']       = 1;
$settings['payments_enabled']    = 0;
$settings['enable_pickup']       = 1;
$settings['enable_delivery']     = 0;
$settings['single_location_mode'] = 1;
$settings['pospal_enabled']      = 0;
$settings['pospal_push_orders']  = 0;
update_option( 'doughboss_settings', $settings, false );

$page = get_page_by_path( 'order' );
if ( ! $page ) {
	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Order',
			'post_name'    => 'order',
			'post_content' => '',
		),
		true
	);
	if ( is_wp_error( $page_id ) ) {
		fwrite( STDERR, $page_id->get_error_message() . "\n" );
		exit( 1 );
	}
}

echo "Synthetic browser fixture ready.\n";
