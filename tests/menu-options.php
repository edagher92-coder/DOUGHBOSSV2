<?php
/** Focused contract tests for canonical menu options and server pricing. */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-menu-options.php';

$passed = 0;
$failed = 0;
function menu_options_ok( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) { ++$passed; echo "  ok   {$label}\n"; }
	else { ++$failed; echo "  FAIL {$label}\n"; }
}

echo "=== DoughBoss menu options test ===\n";
$pizza = DoughBoss_Menu_Options::for_item( 'Pizza', 'Pepperoni Pizza' );
menu_options_ok( array( 'crust', 'base_sauce', 'sauce_top', 'extra_toppings', 'remove', 'lemon_chilli' ) === wp_list_pluck( $pizza, 'id' ), 'pizza exposes the reviewed option groups in order' );
$defaults = DoughBoss_Menu_Options::resolve( $pizza, array() );
menu_options_ok( ! is_wp_error( $defaults ) && 0.0 === $defaults['delta'], 'missing radio values resolve to canonical no-cost defaults' );
menu_options_ok( ! is_wp_error( $defaults ) && array( 'crust--crispy', 'base_sauce--tomato' ) === array_slice( wp_list_pluck( $defaults['modifiers'], 'slug' ), 0, 2 ), 'default choices are saved as kitchen-visible modifiers' );

$priced = DoughBoss_Menu_Options::resolve( $pizza, array( 'crust' => 'gluten_free', 'base_sauce' => 'bbq', 'sauce_top' => array( 'peri_peri', 'mayo_swirl', 'peri_peri' ), 'extra_toppings' => array( 'halloumi', 'olives' ), 'remove' => 'no_onion', 'lemon_chilli' => array( 'lemon', 'chilli' ) ) );
menu_options_ok( ! is_wp_error( $priced ) && 10.5 === $priced['delta'], 'server totals paid option deltas once and preserves free modifiers' );
menu_options_ok( ! is_wp_error( $priced ) && in_array( 'lemon_chilli--chilli', wp_list_pluck( $priced['modifiers'], 'slug' ), true ), 'selected free options are retained for the kitchen' );

$invalid = DoughBoss_Menu_Options::resolve( $pizza, array( 'crust' => 'not-a-real-crust' ) );
menu_options_ok( is_wp_error( $invalid ) && 'doughboss_invalid_option' === $invalid->get_error_code(), 'unknown selections are rejected instead of silently repriced' );

$zaatar = DoughBoss_Menu_Options::for_item( 'Manoush', 'Zaatar' );
$zaatar_default = DoughBoss_Menu_Options::resolve( $zaatar, array() );
menu_options_ok( ! is_wp_error( $zaatar_default ) && 0.0 === $zaatar_default['delta'], 'Zaatar defaults to folded classic mix at the base price' );
menu_options_ok( array() === DoughBoss_Menu_Options::for_item( 'Drinks', 'Water' ), 'items outside configured families expose no unsupported options' );

echo "=== RESULT: {$passed} passed · {$failed} failed ===\n";
exit( $failed ? 1 : 0 );
