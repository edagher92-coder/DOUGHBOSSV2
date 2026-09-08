<?php
/**
 * Execute the server's menu option resolver against customer-selection cases.
 * Uses the existing pure-function shim; no WordPress, database or HTTP proof.
 *
 * @package DoughBoss
 */

require_once dirname( __DIR__ ) . '/includes/class-doughboss-menu-options.php';

db_test(
	'menu option defaults preserve the advertised base price',
	function () {
		$result = DoughBoss_Menu_Options::resolve( DoughBoss_Menu_Options::for_item( 'Pizza', 'All Meat' ), array() );
		assert_false( is_wp_error( $result ), 'default options resolve' );
		assert_same( 0.0, $result['delta'], 'default pizza options add no charge' );
		assert_same( array( 'crust--crispy', 'base_sauce--tomato' ), array_column( $result['modifiers'], 'slug' ), 'server supplies canonical defaults' );
		$zaatar = DoughBoss_Menu_Options::resolve( DoughBoss_Menu_Options::for_item( 'Manoush', 'Zaatar' ), array() );
		assert_same( 0.0, $zaatar['delta'], 'folded Zaatar keeps its base price' );
		assert_same( 'style--folded', $zaatar['modifiers'][0]['slug'], 'Zaatar uses its own default style' );
	}
);

db_test(
	'menu option charges are canonical and duplicate extras count once',
	function () {
		$result = DoughBoss_Menu_Options::resolve(
			DoughBoss_Menu_Options::for_item( 'Pizza', 'All Meat' ),
			array(
				'crust'          => 'gluten_free',
				'extra_toppings' => array( 'cheese', 'cheese' ),
				'sauce_top'      => array( 'smokey_bbq' ),
				'lemon_chilli'   => array( 'lemon' ),
				'price'          => -500,
			)
		);
		assert_false( is_wp_error( $result ), 'valid option slugs resolve' );
		assert_same( 8.0, $result['delta'], '3.50 crust plus 3.00 cheese plus 1.50 sauce; client price ignored' );
		$slugs = array_column( $result['modifiers'], 'slug' );
		assert_same( 1, count( array_keys( $slugs, 'extra_toppings--cheese', true ) ), 'duplicate cheese is stored once' );
		assert_true( in_array( 'lemon_chilli--lemon', $slugs, true ), 'free kitchen instruction survives' );
	}
);

db_test(
	'menu option resolver rejects unavailable canonical selections',
	function () {
		$groups = DoughBoss_Menu_Options::for_item( 'Pizza', 'All Meat' );
		foreach ( array( array( 'crust' => 'not-a-crust' ), array( 'extra_toppings' => array( 'not-a-topping' ) ) ) as $raw ) {
			$result = DoughBoss_Menu_Options::resolve( $groups, $raw );
			assert_true( is_wp_error( $result ), 'unknown choice is rejected' );
			assert_same( 'doughboss_invalid_option', $result->get_error_code(), 'stable invalid-option error' );
			assert_same( 400, $result->get_error_data()['status'], 'invalid choice is a customer error' );
		}
	}
);

db_test(
	'menu family options remain distinct',
	function () {
		assert_same( array(), DoughBoss_Menu_Options::for_item( 'Drinks', 'Apple Juice' ), 'drinks have no food modifiers' );
		$pie_ids = array_column( DoughBoss_Menu_Options::for_item( 'Pies', 'Aged Cheese' ), 'id' );
		assert_same( array( 'sauce_top', 'sesame', 'lemon_chilli' ), $pie_ids, 'pies do not receive pizza crust choices' );
		$result = DoughBoss_Menu_Options::resolve( DoughBoss_Menu_Options::for_item( 'Manoush', 'Zaatar' ), array( 'style' => 'flat' ) );
		assert_same( 0.5, $result['delta'], 'flat Zaatar adds the canonical fifty cents' );
	}
);
