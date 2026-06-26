<?php
/**
 * WP-CLI commands (loaded only under WP-CLI).
 *
 * Lets the owner exercise the POSPal connector and the voucher engine from the
 * host shell — where outbound network access exists and no web nonce is needed.
 * Secrets are never passed on the command line: the POSPal key is read from the
 * environment / settings by the connector itself.
 *
 *   wp doughboss pospal-test
 *   wp doughboss campaigns
 *   wp doughboss voucher-claim snow5 --phone=0400000000
 *   wp doughboss voucher-list --limit=20
 *   wp doughboss voucher-redeem SNOW-ABCD1234 --subtotal=25 --channel=online
 *   wp doughboss voucher-void 12
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

/**
 * DoughBoss CLI commands.
 */
class DoughBoss_CLI {

	/**
	 * Read-only POSPal connectivity check: lists the account's coupon promotion
	 * rules. Confirms the host, appId/appKey signing and that the discount
	 * coupons exist — without changing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp doughboss pospal-test
	 *
	 * @return void
	 */
	public static function pospal_test() {
		if ( ! DoughBoss_POSPal::ready() ) {
			WP_CLI::error( 'POSPal is not configured. Set pospal_host + pospal_app_id in settings and DOUGHBOSS_POSPAL_APPKEY in the environment, and enable POSPal.' );
		}

		$result = DoughBoss_POSPal::test_connection();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( 'POSPal call failed: ' . $result->get_error_message() );
		}

		WP_CLI::success( 'POSPal reachable and the signature was accepted.' );
		WP_CLI::log( 'Coupon promotion rules: ' . wp_json_encode( $result ) );
	}

	/**
	 * Show the daily voucher campaigns with today's claim counts (and shared-pool
	 * usage where campaigns share a cap_group).
	 *
	 * ## EXAMPLES
	 *
	 *     wp doughboss campaigns
	 *
	 * @return void
	 */
	public static function campaigns() {
		$rows = array();
		foreach ( DoughBoss_Voucher::campaigns() as $c ) {
			$cap     = (int) ( isset( $c['daily_cap'] ) ? $c['daily_cap'] : 0 );
			$used    = DoughBoss_Voucher::claimed_today_for( $c );
			$rows[]  = array(
				'slug'          => isset( $c['slug'] ) ? $c['slug'] : '',
				'label'         => isset( $c['label'] ) ? $c['label'] : '',
				'value'         => isset( $c['value'] ) ? $c['value'] : 0,
				'cap_group'     => isset( $c['cap_group'] ) ? $c['cap_group'] : '',
				'daily_cap'     => $cap > 0 ? $cap : '∞',
				'claimed_today' => DoughBoss_Voucher::claimed_today( isset( $c['slug'] ) ? $c['slug'] : '' ),
				'pool_used'     => $used,
				'remaining'     => $cap > 0 ? max( 0, $cap - $used ) : '∞',
				'active'        => empty( $c['active'] ) ? 'no' : 'yes',
			);
		}
		if ( empty( $rows ) ) {
			WP_CLI::log( 'No campaigns defined.' );
			return;
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'slug', 'label', 'value', 'cap_group', 'daily_cap', 'claimed_today', 'pool_used', 'remaining', 'active' ) );
	}

	/**
	 * Claim a voucher from a daily-capped campaign (enforces the shared daily
	 * pool) and print the issued code.
	 *
	 * ## OPTIONS
	 *
	 * <campaign>
	 * : Campaign slug, e.g. snow5 or snow10.
	 *
	 * [--phone=<phone>]
	 * : Customer phone (the POSPal member key).
	 *
	 * [--email=<email>]
	 * : Customer email.
	 *
	 * ## EXAMPLES
	 *
	 *     wp doughboss voucher-claim snow5 --phone=0400000000
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public static function voucher_claim( $args, $assoc_args ) {
		$campaign = isset( $args[0] ) ? $args[0] : '';
		if ( '' === $campaign ) {
			WP_CLI::error( 'Usage: wp doughboss voucher-claim <campaign> [--phone=] [--email=]' );
		}
		$result = DoughBoss_Voucher::claim(
			$campaign,
			array(
				'customer_phone' => isset( $assoc_args['phone'] ) ? $assoc_args['phone'] : '',
				'customer_email' => isset( $assoc_args['email'] ) ? $assoc_args['email'] : '',
			)
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Claimed "%s": code %s (id %d).', $campaign, $result['code'], $result['id'] ) );
	}

	/**
	 * List recent vouchers with their status and any redemption.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : How many to show (default 20).
	 *
	 * ## EXAMPLES
	 *
	 *     wp doughboss voucher-list --limit=20
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public static function voucher_list( $args, $assoc_args ) {
		$limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 20;
		$rows  = DoughBoss_Voucher::query( $limit );
		$items = array();
		foreach ( (array) $rows as $r ) {
			$items[] = array(
				'id'          => $r->id,
				'code'        => $r->code,
				'campaign'    => $r->campaign,
				'type'        => $r->type,
				'value'       => $r->value,
				'status'      => $r->status,
				'redeemed_at' => isset( $r->redeemed_at ) ? (string) $r->redeemed_at : '',
				'amount'      => isset( $r->amount_applied ) ? (string) $r->amount_applied : '',
				'created_at'  => $r->created_at,
			);
		}
		if ( empty( $items ) ) {
			WP_CLI::log( 'No vouchers yet.' );
			return;
		}
		WP_CLI\Utils\format_items( 'table', $items, array( 'id', 'code', 'campaign', 'type', 'value', 'status', 'redeemed_at', 'amount', 'created_at' ) );
	}

	/**
	 * Redeem a voucher (atomic single-use) against a given subtotal.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : The voucher code.
	 *
	 * --subtotal=<amount>
	 * : Server-side cart subtotal to apply against.
	 *
	 * [--channel=<channel>]
	 * : online (default) or instore.
	 *
	 * ## EXAMPLES
	 *
	 *     wp doughboss voucher-redeem SNOW-ABCD1234 --subtotal=25 --channel=online
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public static function voucher_redeem( $args, $assoc_args ) {
		$code = isset( $args[0] ) ? $args[0] : '';
		if ( '' === $code ) {
			WP_CLI::error( 'Usage: wp doughboss voucher-redeem <CODE> --subtotal=<amount> [--channel=online|instore]' );
		}
		$subtotal = isset( $assoc_args['subtotal'] ) ? (float) $assoc_args['subtotal'] : 0;
		$channel  = isset( $assoc_args['channel'] ) && 'instore' === $assoc_args['channel'] ? 'instore' : 'online';
		$result   = DoughBoss_Voucher::redeem( $code, $subtotal, $channel );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Redeemed %s — %s applied.', $result['code'], DoughBoss_Settings::format_price( $result['amount'] ) ) );
	}

	/**
	 * Void an issued (un-redeemed) voucher.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The voucher id.
	 *
	 * ## EXAMPLES
	 *
	 *     wp doughboss voucher-void 12
	 *
	 * @param array $args Positional args.
	 * @return void
	 */
	public static function voucher_void( $args ) {
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( ! $id ) {
			WP_CLI::error( 'Usage: wp doughboss voucher-void <ID>' );
		}
		if ( ! DoughBoss_Voucher::void( $id ) ) {
			WP_CLI::error( 'Could not void — voucher not found or not in the "issued" state.' );
		}
		WP_CLI::success( sprintf( 'Voucher voided: id %d.', $id ) );
	}

	/**
	 * The Dough Boss in-store menu boards as data: category => list of
	 * [ name, price, type(standard|pizza|side|drink), dietary[], description ].
	 * Prices are GST-inclusive AUD; everything is halal.
	 *
	 * @return array<string,array<int,array>>
	 */
	private static function menu_data() {
		return array(
			'Manoush'  => array(
				array( 'Zaatar', 4.50, 'standard', array( 'vegan', 'halal' ), 'Dried thyme, sumac & toasted sesame mixed with olive oil — served flat or folded.' ),
				array( 'Zaatar & Cheese', 8.50, 'standard', array( 'vegetarian', 'halal' ), 'Zaatar on one half, blended cheese on the other — flat or folded.' ),
				array( 'Cheese', 9.50, 'standard', array( 'vegetarian', 'halal' ), 'A beautiful mix of our blended cheese, baked golden.' ),
				array( 'Meat', 9.00, 'standard', array( 'halal' ), 'Minced lamb blended with spices, onions & tomatoes — flat or folded.' ),
				array( 'Meat & Cheese', 11.00, 'standard', array( 'halal' ), 'Minced lamb with spices, topped with melted cheese.' ),
			),
			'Pizza'    => array(
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
				array( 'Spinach & Cheese', 10.00, 'standard', array( 'vegetarian', 'halal' ), 'A triangular turnover of spinach, onion, lemon, spices & cheese.' ),
				array( 'Haloumi', 11.00, 'standard', array( 'vegetarian', 'halal' ), 'Delicious haloumi cheese baked in a pie.' ),
				array( 'Chicken Pie', 11.00, 'standard', array( 'halal' ), 'Grilled chicken, capsicum, mushroom & cheese.' ),
				array( 'Aged Cheese', 10.00, 'standard', array( 'vegetarian', 'halal' ), 'Aged white cheese (shanklish) with diced tomatoes & onions.' ),
			),
			'Wraps'    => array(
				array( 'Zaatar & Veggie', 8.50, 'standard', array( 'vegan', 'halal' ), 'Zaatar with fresh tomato, cucumber, olives & mint. Add labneh or cheese +$2.50 each.' ),
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
	 * Seed the doughboss_item CPT with the in-store menu boards (Manoush, Pizza,
	 * Pies, Wraps, Desserts, Drinks) — prices, categories and dietary flags — so the
	 * WordPress storefront matches the boards. IDEMPOTENT: items are matched by exact
	 * title and UPDATED (not duplicated); each is stamped meta _doughboss_seed=v1.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp doughboss seed-menu
	 *     wp doughboss seed-menu --dry-run
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public static function seed_menu( $args, $assoc_args ) {
		unset( $args );
		$dry = ! empty( $assoc_args['dry-run'] );

		$post_type = DoughBoss_Post_Types::POST_TYPE;
		if ( ! post_type_exists( $post_type ) ) {
			WP_CLI::error( 'DoughBoss is not active (post type ' . $post_type . ' missing).' );
		}
		$taxonomy = DoughBoss_Post_Types::TAXONOMY;

		$created = 0;
		$updated = 0;
		$cats    = 0;
		WP_CLI::log( ( $dry ? '[DRY RUN] ' : '' ) . 'Seeding Dough Boss menu…' );

		foreach ( self::menu_data() as $cat_name => $items ) {
			$term    = term_exists( $cat_name, $taxonomy );
			$term_id = 0;
			if ( ! $term ) {
				if ( $dry ) {
					WP_CLI::log( "  + category: {$cat_name} (would create)" );
				} else {
					$new = wp_insert_term( $cat_name, $taxonomy );
					if ( is_wp_error( $new ) ) {
						WP_CLI::warning( "category {$cat_name}: " . $new->get_error_message() );
						continue;
					}
					$term_id = (int) $new['term_id'];
					++$cats;
					WP_CLI::log( "  + category: {$cat_name}" );
				}
			} else {
				$term_id = (int) ( is_array( $term ) ? $term['term_id'] : $term );
			}

			foreach ( $items as $item ) {
				list( $name, $price, $type, $diet, $desc ) = $item;
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
				$post_id = ! empty( $existing ) ? (int) $existing[0] : 0;
				if ( $dry ) {
					WP_CLI::log( sprintf( '    %s %-22s $%-6.2f [%s]', $post_id ? '~' : '+', $name, $price, $type ) );
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
					WP_CLI::warning( "failed: {$name}" );
					continue;
				}
				update_post_meta( $post_id, DoughBoss_Post_Types::META_PRICE, number_format( (float) $price, 2, '.', '' ) );
				update_post_meta( $post_id, DoughBoss_Post_Types::META_TYPE, $type );
				update_post_meta( $post_id, DoughBoss_Post_Types::META_AVAILABLE, '1' );
				update_post_meta( $post_id, DoughBoss_Post_Types::META_DIETARY, array_values( $diet ) );
				update_post_meta( $post_id, '_doughboss_seed', 'v1' );
				if ( $term_id ) {
					wp_set_object_terms( $post_id, array( $term_id ), $taxonomy, false );
				}
			}
		}
		WP_CLI::success( ( $dry ? '[DRY RUN] ' : '' ) . "Done. Categories created: {$cats}; items created: {$created}; updated: {$updated}." );
	}

	/**
	 * Per-store creds for the order/product commands. Store 1 = null (the legacy
	 * default the connector already uses); 2/3 = explicit creds.
	 *
	 * @param int $n Store number.
	 * @return array|null
	 */
	private static function pospal_store_creds( $n ) {
		$n = max( 1, (int) $n );
		if ( 1 === $n ) {
			return null;
		}
		$s = DoughBoss_Settings::pospal_store( $n );
		return array(
			'host'    => $s['host'],
			'app_id'  => $s['app_id'],
			'app_key' => $s['app_key'],
		);
	}

	/**
	 * Fetch ALL products for a store, paginating queryProductPages.
	 *
	 * @param array|null $creds Store creds.
	 * @return array|WP_Error List of product rows or error.
	 */
	private static function pospal_all_products( $creds ) {
		$all    = array();
		$params = array();
		$guard  = 0;
		do {
			$data = DoughBoss_POSPal::query_products( $creds, $params );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$result = ( is_array( $data ) && isset( $data['result'] ) && is_array( $data['result'] ) ) ? $data['result'] : array();
			$all    = array_merge( $all, $result );
			$pbp    = ( is_array( $data ) && isset( $data['postBackParameter'] ) ) ? $data['postBackParameter'] : null;
			$params = $pbp ? array( 'postBackParameter' => $pbp ) : array();
			++$guard;
		} while ( ! empty( $result ) && $pbp && $guard < 50 );
		return $all;
	}

	/**
	 * List a store's POSPal products (uid + name) — the source for the product map.
	 *
	 * ## OPTIONS
	 *
	 * [--store=<n>]
	 * : Store number (1 = primary/legacy, default 1).
	 *
	 * ## EXAMPLES
	 *
	 *     wp doughboss pospal-products
	 *     wp doughboss pospal-products --store=2
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public static function pospal_products( $args, $assoc_args ) {
		unset( $args );
		$store    = isset( $assoc_args['store'] ) ? (int) $assoc_args['store'] : 1;
		$products = self::pospal_all_products( self::pospal_store_creds( $store ) );
		if ( is_wp_error( $products ) ) {
			WP_CLI::error( 'POSPal products failed: ' . $products->get_error_message() );
		}
		if ( empty( $products ) ) {
			WP_CLI::log( 'No products returned.' );
			return;
		}
		$rows = array();
		foreach ( $products as $p ) {
			$rows[] = array(
				'uid'       => isset( $p['uid'] ) ? (string) $p['uid'] : '',
				'name'      => isset( $p['name'] ) ? (string) $p['name'] : '',
				'sellPrice' => isset( $p['sellPrice'] ) ? (string) $p['sellPrice'] : '',
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'uid', 'name', 'sellPrice' ) );
		WP_CLI::log( count( $rows ) . ' products.' );
	}

	/**
	 * Build the menu-item -> POSPal product uid map by matching names, and save it to
	 * settings (pospal_product_map). Required before order push will work.
	 *
	 * ## OPTIONS
	 *
	 * [--store=<n>]
	 * : Store number (default 1).
	 *
	 * [--dry-run]
	 * : Show matches/misses; write nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp doughboss pospal-map --dry-run
	 *     wp doughboss pospal-map
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public static function pospal_map( $args, $assoc_args ) {
		unset( $args );
		$store    = isset( $assoc_args['store'] ) ? (int) $assoc_args['store'] : 1;
		$dry      = ! empty( $assoc_args['dry-run'] );
		$products = self::pospal_all_products( self::pospal_store_creds( $store ) );
		if ( is_wp_error( $products ) ) {
			WP_CLI::error( 'POSPal products failed: ' . $products->get_error_message() );
		}

		$by_name = array();
		foreach ( $products as $p ) {
			if ( empty( $p['name'] ) || ! isset( $p['uid'] ) ) {
				continue;
			}
			$by_name[ DoughBoss_POSPal_Orders::norm( $p['name'] ) ] = $p['uid'];
		}

		$items = get_posts(
			array(
				'post_type'        => DoughBoss_Post_Types::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'suppress_filters' => false,
			)
		);
		$map    = array();
		$missed = array();
		foreach ( $items as $post ) {
			$key = DoughBoss_POSPal_Orders::norm( $post->post_title );
			if ( '' !== $key && isset( $by_name[ $key ] ) ) {
				$map[ $key ] = $by_name[ $key ];
				WP_CLI::log( sprintf( '  ok  %-24s -> %s', $post->post_title, (string) $by_name[ $key ] ) );
			} else {
				$missed[] = $post->post_title;
				WP_CLI::log( sprintf( '  --  %-24s (no POSPal product match)', $post->post_title ) );
			}
		}

		if ( $dry ) {
			WP_CLI::success( sprintf( '[DRY RUN] %d mapped, %d unmatched. Nothing saved.', count( $map ), count( $missed ) ) );
			return;
		}
		DoughBoss_Settings::update( array( 'pospal_product_map' => $map ) );
		WP_CLI::success( sprintf( '%d items mapped, %d unmatched. Saved.', count( $map ), count( $missed ) ) );
		if ( ! empty( $missed ) ) {
			WP_CLI::warning( 'Unmatched (rename to match POSPal, or map manually): ' . implode( '; ', $missed ) );
		}
	}

	/**
	 * Test-push an existing order to POSPal (use --dry-run to inspect the body first).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Order id.
	 *
	 * [--store=<n>]
	 * : Store number (default 1).
	 *
	 * [--dry-run]
	 * : Print the body + any unmapped items; send nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp doughboss pospal-push-order 42 --dry-run
	 *     wp doughboss pospal-push-order 42
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public static function pospal_push_order( $args, $assoc_args ) {
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( ! $id ) {
			WP_CLI::error( 'Usage: wp doughboss pospal-push-order <ORDER_ID> [--store=] [--dry-run]' );
		}
		$order = DoughBoss_Order::get( $id );
		if ( ! $order ) {
			WP_CLI::error( 'Order not found: ' . $id );
		}
		$items = DoughBoss_Order::get_items( $id );
		$build = DoughBoss_POSPal_Orders::build_body( $order, $items );

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			WP_CLI::log( wp_json_encode( $build['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			if ( ! empty( $build['unmapped'] ) ) {
				WP_CLI::warning( 'Unmapped items (push would be skipped live): ' . implode( '; ', $build['unmapped'] ) );
			}
			WP_CLI::success( '[DRY RUN] Body shown above; nothing sent.' );
			return;
		}
		if ( ! empty( $build['unmapped'] ) ) {
			WP_CLI::error( 'Refusing to push with unmapped items: ' . implode( '; ', $build['unmapped'] ) . '. Run pospal-map first.' );
		}
		$store  = isset( $assoc_args['store'] ) ? (int) $assoc_args['store'] : 1;
		$result = DoughBoss_POSPal::push_order( $build['body'], self::pospal_store_creds( $store ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( 'Push failed: ' . $result->get_error_message() );
		}
		$no = ( is_array( $result ) && isset( $result['orderNo'] ) ) ? (string) $result['orderNo'] : '(no orderNo returned)';
		WP_CLI::success( 'Pushed. POSPal orderNo: ' . $no );
	}
}

WP_CLI::add_command( 'doughboss pospal-test', array( 'DoughBoss_CLI', 'pospal_test' ) );
WP_CLI::add_command( 'doughboss campaigns', array( 'DoughBoss_CLI', 'campaigns' ) );
WP_CLI::add_command( 'doughboss voucher-claim', array( 'DoughBoss_CLI', 'voucher_claim' ) );
WP_CLI::add_command( 'doughboss voucher-list', array( 'DoughBoss_CLI', 'voucher_list' ) );
WP_CLI::add_command( 'doughboss voucher-redeem', array( 'DoughBoss_CLI', 'voucher_redeem' ) );
WP_CLI::add_command( 'doughboss voucher-void', array( 'DoughBoss_CLI', 'voucher_void' ) );
WP_CLI::add_command( 'doughboss seed-menu', array( 'DoughBoss_CLI', 'seed_menu' ) );
WP_CLI::add_command( 'doughboss pospal-products', array( 'DoughBoss_CLI', 'pospal_products' ) );
WP_CLI::add_command( 'doughboss pospal-map', array( 'DoughBoss_CLI', 'pospal_map' ) );
WP_CLI::add_command( 'doughboss pospal-push-order', array( 'DoughBoss_CLI', 'pospal_push_order' ) );
