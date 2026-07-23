<?php
/**
 * Canonical customer-selectable options for each menu family.
 *
 * The browser receives these groups from the REST API, but all selections and
 * price deltas are resolved again here on the server before a cart line is
 * stored. This keeps the WordPress storefront aligned with the reviewed demo
 * without trusting prices submitted by a customer.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu option catalogue and server-side selection resolver.
 */
class DoughBoss_Menu_Options {

	/**
	 * Build one choice.
	 *
	 * @param string $slug    Stable choice identifier.
	 * @param string $label   Customer/kitchen label.
	 * @param float  $price   Price delta.
	 * @param bool   $default Whether this is the radio default.
	 * @return array
	 */
	private static function choice( $slug, $label, $price = 0.0, $default = false ) {
		return array(
			'slug'    => sanitize_key( $slug ),
			'label'   => $label,
			'price'   => round( (float) $price, 2 ),
			'default' => (bool) $default,
		);
	}

	/**
	 * Build one option group.
	 *
	 * @param string $id      Stable group identifier.
	 * @param string $label   Customer-facing label.
	 * @param string $type    radio or check.
	 * @param array  $choices Choices.
	 * @return array
	 */
	private static function group( $id, $label, $type, array $choices ) {
		return array(
			'id'      => sanitize_key( $id ),
			'label'   => $label,
			'type'    => 'check' === $type ? 'check' : 'radio',
			'choices' => $choices,
		);
	}

	/**
	 * Shared option groups.
	 *
	 * @return array<string,array>
	 */
	private static function groups() {
		return array(
			'style' => self::group(
				'style',
				__( 'Style', 'doughboss' ),
				'radio',
				array(
					self::choice( 'flat', __( 'Flat', 'doughboss' ), 0, true ),
					self::choice( 'folded', __( 'Folded', 'doughboss' ) ),
				)
			),
			'zaatar_style' => self::group(
				'style',
				__( 'Style', 'doughboss' ),
				'radio',
				array(
					self::choice( 'flat', __( 'Flat', 'doughboss' ), 0.50 ),
					self::choice( 'folded', __( 'Folded', 'doughboss' ), 0, true ),
				)
			),
			'zaatar_mix' => self::group(
				'zaatar_mix',
				__( 'Zaatar mix', 'doughboss' ),
				'radio',
				array(
					self::choice( 'classic_zaatar', __( 'Classic zaatar', 'doughboss' ), 0, true ),
					self::choice( 'mixed_zaatar_cheese', __( 'Mixed zaatar & cheese', 'doughboss' ), 0.50 ),
				)
			),
			'crust' => self::group(
				'crust',
				__( 'Crust', 'doughboss' ),
				'radio',
				array(
					self::choice( 'crispy', __( 'Crispy', 'doughboss' ), 0, true ),
					self::choice( 'classic', __( 'Classic', 'doughboss' ) ),
					self::choice( 'wholemeal', __( 'Wholemeal', 'doughboss' ), 2.50 ),
					self::choice( 'gluten_free', __( 'Gluten-free', 'doughboss' ), 3.50 ),
				)
			),
			'base_sauce' => self::group(
				'base_sauce',
				__( 'Base sauce', 'doughboss' ),
				'radio',
				array(
					self::choice( 'tomato', __( 'Tomato (pizza sauce)', 'doughboss' ), 0, true ),
					self::choice( 'garlic', __( 'Garlic', 'doughboss' ) ),
					self::choice( 'bbq', __( 'BBQ', 'doughboss' ) ),
					self::choice( 'none', __( 'No sauce', 'doughboss' ) ),
				)
			),
			'sauce_top' => self::group(
				'sauce_top',
				__( 'Sauce on top', 'doughboss' ),
				'check',
				array(
					self::choice( 'tomato_ketchup', __( 'Tomato ketchup', 'doughboss' ), 1.50 ),
					self::choice( 'smokey_bbq', __( 'Smokey BBQ', 'doughboss' ), 1.50 ),
					self::choice( 'mayo_swirl', __( 'Mayo swirl', 'doughboss' ), 1.50 ),
					self::choice( 'peri_peri', __( 'Peri peri sauce', 'doughboss' ), 1.50 ),
					self::choice( 'spicy_sriracha', __( 'Spicy sriracha', 'doughboss' ), 1.50 ),
				)
			),
			'extra_toppings' => self::group(
				'extra_toppings',
				__( 'Add extra toppings', 'doughboss' ),
				'check',
				array(
					self::choice( 'olives', __( 'Olives', 'doughboss' ), 1 ),
					self::choice( 'spinach', __( 'Spinach', 'doughboss' ), 2 ),
					self::choice( 'garlic_sauce', __( 'Garlic sauce', 'doughboss' ), 2 ),
					self::choice( 'onion', __( 'Onion', 'doughboss' ), 2 ),
					self::choice( 'mushroom', __( 'Mushroom', 'doughboss' ), 2 ),
					self::choice( 'capsicum', __( 'Capsicum', 'doughboss' ), 2 ),
					self::choice( 'tomato', __( 'Tomato', 'doughboss' ), 2 ),
					self::choice( 'sujuk', __( 'Sujuk', 'doughboss' ), 3 ),
					self::choice( 'chicken', __( 'Chicken', 'doughboss' ), 3 ),
					self::choice( 'meat', __( 'Meat (lahme)', 'doughboss' ), 3 ),
					self::choice( 'cheese', __( 'Cheese', 'doughboss' ), 3 ),
					self::choice( 'mozzarella', __( 'Mozzarella', 'doughboss' ), 3 ),
					self::choice( 'halloumi', __( 'Halloumi', 'doughboss' ), 3 ),
					self::choice( 'pepperoni', __( 'Pepperoni', 'doughboss' ), 3 ),
				)
			),
			'remove' => self::group(
				'remove',
				__( 'Remove ingredients', 'doughboss' ),
				'check',
				array(
					self::choice( 'no_cheese', __( 'No cheese', 'doughboss' ) ),
					self::choice( 'no_tomato', __( 'No tomato', 'doughboss' ) ),
					self::choice( 'no_olives', __( 'No olives', 'doughboss' ) ),
					self::choice( 'no_onion', __( 'No onion', 'doughboss' ) ),
					self::choice( 'no_mushroom', __( 'No mushroom', 'doughboss' ) ),
					self::choice( 'no_capsicum', __( 'No capsicum', 'doughboss' ) ),
					self::choice( 'no_cucumber', __( 'No cucumber', 'doughboss' ) ),
					self::choice( 'no_lettuce', __( 'No lettuce', 'doughboss' ) ),
					self::choice( 'no_pickles', __( 'No pickles', 'doughboss' ) ),
					self::choice( 'no_meat', __( 'No meat', 'doughboss' ) ),
					self::choice( 'no_sujuk', __( 'No sujuk', 'doughboss' ) ),
				)
			),
			'wrap_extras' => self::group(
				'wrap_extras',
				__( 'Extras', 'doughboss' ),
				'check',
				array(
					self::choice( 'labneh', __( 'Add labneh', 'doughboss' ), 2.50 ),
					self::choice( 'cheese', __( 'Add cheese', 'doughboss' ), 2.50 ),
				)
			),
			'sesame' => self::group(
				'sesame',
				__( 'Sesame seeds', 'doughboss' ),
				'radio',
				array(
					self::choice( 'no_sesame', __( 'No sesame seeds', 'doughboss' ), 0, true ),
					self::choice( 'with_sesame', __( 'With sesame seeds', 'doughboss' ) ),
				)
			),
			'lemon_chilli' => self::group(
				'lemon_chilli',
				__( 'Lemon & chilli — free', 'doughboss' ),
				'check',
				array(
					self::choice( 'lemon', __( 'Lemon', 'doughboss' ) ),
					self::choice( 'chilli', __( 'Chilli', 'doughboss' ) ),
				)
			),
		);
	}

	/**
	 * Return applicable groups for an item.
	 *
	 * @param string $category Menu category name.
	 * @param string $name     Menu item name.
	 * @return array
	 */
	public static function for_item( $category, $name ) {
		$groups = self::groups();

		if ( 'Pizza' === $category ) {
			$result = array( $groups['crust'], $groups['base_sauce'], $groups['sauce_top'], $groups['extra_toppings'] );
			if ( in_array( $name, array( 'Zaatar Veggie Pizza', 'Labneh Veggie Pizza' ), true ) ) {
				$result[] = $groups['wrap_extras'];
			}
			$result[] = $groups['remove'];
			$result[] = $groups['lemon_chilli'];
			return $result;
		}

		if ( 'Pies' === $category ) {
			return array( $groups['sauce_top'], $groups['sesame'], $groups['lemon_chilli'] );
		}

		if ( 'Manoush' === $category ) {
			if ( 'Zaatar' === $name ) {
				return array( $groups['zaatar_style'], $groups['zaatar_mix'], $groups['crust'], $groups['remove'], $groups['lemon_chilli'] );
			}
			if ( 'Zaatar & Cheese' === $name ) {
				return array( $groups['zaatar_style'], $groups['crust'], $groups['remove'], $groups['lemon_chilli'] );
			}
			return array( $groups['style'], $groups['crust'], $groups['remove'], $groups['lemon_chilli'] );
		}

		if ( 'Wraps' === $category ) {
			$result = array();
			if ( in_array( $name, array( 'Zaatar & Veggie', 'Labneh Veggie Wrap' ), true ) ) {
				$result[] = $groups['wrap_extras'];
			}
			$result[] = $groups['remove'];
			$result[] = $groups['lemon_chilli'];
			return $result;
		}

		return array();
	}

	/**
	 * Resolve raw customer selections against canonical groups.
	 *
	 * @param array $groups Applicable canonical groups.
	 * @param mixed $raw    Raw REST request value.
	 * @return array|WP_Error Array with modifiers and total delta.
	 */
	public static function resolve( array $groups, $raw ) {
		$raw       = is_array( $raw ) ? $raw : array();
		$modifiers = array();
		$delta     = 0.0;

		foreach ( $groups as $group ) {
			$selected = isset( $raw[ $group['id'] ] ) ? $raw[ $group['id'] ] : null;
			$wanted   = array();

			if ( 'radio' === $group['type'] ) {
				if ( is_array( $selected ) ) {
					$selected = reset( $selected );
				}
				if ( null === $selected || '' === $selected ) {
					foreach ( $group['choices'] as $choice ) {
						if ( ! empty( $choice['default'] ) ) {
							$selected = $choice['slug'];
							break;
						}
					}
				}
				if ( null === $selected || '' === $selected ) {
					$selected = $group['choices'][0]['slug'];
				}
				$wanted[] = sanitize_key( $selected );
			} else {
				if ( null === $selected || '' === $selected ) {
					$selected = array();
				}
				if ( ! is_array( $selected ) ) {
					$selected = array( $selected );
				}
				foreach ( $selected as $slug ) {
					$slug = sanitize_key( $slug );
					if ( '' !== $slug && ! in_array( $slug, $wanted, true ) ) {
						$wanted[] = $slug;
					}
				}
			}

			$matched = array();
			foreach ( $group['choices'] as $choice ) {
				if ( in_array( $choice['slug'], $wanted, true ) ) {
					$matched[] = $choice['slug'];
					$price     = round( (float) $choice['price'], 2 );
					$delta    += $price;
					$modifiers[] = array(
						'slug'  => $group['id'] . '--' . $choice['slug'],
						'label' => $choice['label'],
						'price' => $price,
					);
				}
			}

			if ( count( $matched ) !== count( $wanted ) ) {
				return new WP_Error(
					'doughboss_invalid_option',
					__( 'One or more selected menu options are not available.', 'doughboss' ),
					array( 'status' => 400 )
				);
			}
		}

		return array(
			'modifiers' => $modifiers,
			'delta'     => round( $delta, 2 ),
		);
	}
}
