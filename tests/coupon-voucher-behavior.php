<?php
/**
 * Coupon and voucher behavioral contract (offline, in-memory only).
 *
 * Exercises coupon formatting, voucher eligibility and money calculations,
 * atomic redemption/replay/revert behavior, order persistence, and the payloads
 * consumed by admin/KDS views without WordPress, a payment provider, or network.
 *
 * Run: php tests/coupon-voucher-behavior.php
 *
 * @package DoughBoss\Tests
 */

require __DIR__ . '/wp-stubs.php';

if ( ! defined( 'DOUGHBOSS_REST_NAMESPACE' ) ) {
	define( 'DOUGHBOSS_REST_NAMESPACE', 'doughboss/v1' );
}
if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $data ) {
		return $data instanceof WP_REST_Response ? $data : new WP_REST_Response( $data );
	}
}

// Warnings in a test double can otherwise leave a green exit code and conceal
// false confidence (for example, reading a redemption row that was never made).
set_error_handler(
	static function ( $severity, $message, $file, $line ) {
		if ( 0 === ( error_reporting() & $severity ) ) {
			return false;
		}
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

/** In-memory persistence double for voucher + order behavior. */
class DoughBoss_Coupon_Voucher_DB extends DB_Stub {
	public $vouchers = array();
	public $redemptions = array();
	public $voucher_audit = array();
	public $orders = array();
	public $items = array();
	public $events = array();
	public $voucher_lookups = 0;
	public $fail_next_redemption_insert = false;
	public $fail_order_inserts = 0;
	public $claim_race_voucher_id = 0;
	public $deny_next_voucher_lock = false;
	public $zero_next_voucher_update = false;
	private $next_voucher_id = 1;
	private $next_redemption_id = 1;
	private $next_order_id = 1;
	private $next_item_id = 1;
	private $snapshot = null;

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$index = 0;
		return preg_replace_callback(
			'/%[dfs]/',
			static function ( $match ) use ( &$args, &$index ) {
				$value = isset( $args[ $index ] ) ? $args[ $index ] : '';
				++$index;
				if ( '%d' === $match[0] ) {
					return (string) (int) $value;
				}
				if ( '%f' === $match[0] ) {
					return (string) (float) $value;
				}
				return "'" . str_replace( "'", "''", (string) $value ) . "'";
			},
			$query
		);
	}

	private function output_row( array $row, $output = OBJECT ) {
		return ARRAY_A === $output ? $row : (object) $row;
	}

	public function seed_voucher( $code, array $overrides = array() ) {
		$id = $this->next_voucher_id++;
		$this->vouchers[ $id ] = array_merge(
			array(
				'id'             => $id,
				'code'           => $code,
				'type'           => 'amount',
				'value'          => 5.00,
				'currency'       => 'AUD',
				'min_spend'      => 0.00,
				'scope'          => 'both',
				'location_id'    => 0,
				'single_use'     => 1,
				'status'         => 'issued',
				'customer_phone' => '',
				'customer_email' => '',
				'campaign'       => '',
				'valid_from'     => null,
				'valid_to'       => null,
				'meta'           => null,
				'created_at'     => '2026-07-06 00:00:00',
				'updated_at'     => '2026-07-06 00:00:00',
			),
			$overrides
		);
		return $id;
	}

	public function get_var( $query = null ) {
		$query = (string) $query;
		if ( preg_match( "/SELECT id FROM wp_doughboss_vouchers WHERE code = '([^']+)'/", $query, $match ) ) {
			foreach ( $this->vouchers as $row ) {
				if ( $row['code'] === $match[1] ) {
					return (int) $row['id'];
				}
			}
			return null;
		}
		if ( preg_match( "/SELECT id FROM wp_doughboss_vouchers WHERE campaign IN \\(([^)]+)\\) AND LOWER\\(customer_email\\) = '([^']+)' LIMIT 1/", $query, $match ) ) {
			$campaigns = array_map( static function ( $slug ) { return trim( $slug, " '" ); }, explode( ',', $match[1] ) );
			$email     = strtolower( str_replace( "''", "'", $match[2] ) );
			foreach ( $this->vouchers as $row ) {
				if ( in_array( $row['campaign'], $campaigns, true ) && strtolower( $row['customer_email'] ) === $email ) {
					return (int) $row['id'];
				}
			}
			return null;
		}
		if ( preg_match( "/SELECT voucher_id FROM wp_doughboss_voucher_redemptions WHERE idempotency_key = '([^']+)'/", $query, $match ) ) {
			return isset( $this->redemptions[ $match[1] ] ) ? (int) $this->redemptions[ $match[1] ]['voucher_id'] : null;
		}
		// wp-stubs.php deliberately generates the same readable password each time.
		// Treat the pre-insert order-number probe as collision-free so a second
		// in-memory order can reach the persistence behavior under test.
		if ( preg_match( "/SELECT id FROM wp_doughboss_orders WHERE order_number = '([^']+)'/", $query ) ) {
			return null;
		}
		if ( preg_match( "/SELECT id FROM wp_doughboss_orders WHERE (checkout_key|payment_intent_id) = '([^']+)'/", $query, $match ) ) {
			foreach ( $this->orders as $row ) {
				if ( (string) ( isset( $row[ $match[1] ] ) ? $row[ $match[1] ] : '' ) === $match[2] ) {
					return (int) $row['id'];
				}
			}
			return null;
		}
		if ( false !== strpos( $query, 'SELECT COUNT(*) FROM wp_doughboss_orders' ) ) {
			return count( $this->filtered_orders( $query ) );
		}
		if ( 0 === strpos( $query, 'SELECT GET_LOCK(' ) ) {
			if ( $this->deny_next_voucher_lock ) {
				$this->deny_next_voucher_lock = false;
				return 0;
			}
			return 1;
		}
		return null;
	}

	public function get_row( $query = null, $output = OBJECT, $offset = 0 ) {
		$query = (string) $query;
		if ( preg_match( "/SELECT \* FROM wp_doughboss_vouchers WHERE code = '([^']+)'/", $query, $match ) ) {
			++$this->voucher_lookups;
			foreach ( $this->vouchers as $row ) {
				if ( $row['code'] === $match[1] ) {
					return $this->output_row( $row, $output );
				}
			}
			return null;
		}
		if ( preg_match( '/SELECT \* FROM wp_doughboss_vouchers WHERE id = (\d+)/', $query, $match ) ) {
			$id = (int) $match[1];
			return isset( $this->vouchers[ $id ] ) ? $this->output_row( $this->vouchers[ $id ], $output ) : null;
		}
		if ( preg_match( "/SELECT \* FROM wp_doughboss_voucher_redemptions WHERE voucher_id = (\d+) AND redemption_status = 'redeemed'/", $query, $match ) ) {
			$voucher_id = (int) $match[1];
			$matches = array_filter(
				$this->redemptions,
				static function ( $row ) use ( $voucher_id ) {
					return (int) $row['voucher_id'] === $voucher_id && ( ! isset( $row['redemption_status'] ) || 'redeemed' === $row['redemption_status'] );
				}
			);
			if ( empty( $matches ) ) {
				return null;
			}
			usort( $matches, static function ( $a, $b ) { return (int) $b['id'] <=> (int) $a['id']; } );
			return $this->output_row( $matches[0], $output );
		}
		if ( preg_match( "/WHERE r.idempotency_key = '([^']+)'/", $query, $match ) ) {
			if ( ! isset( $this->redemptions[ $match[1] ] ) ) {
				return null;
			}
			$redemption = $this->redemptions[ $match[1] ];
			if ( isset( $redemption['redemption_status'] ) && 'redeemed' !== $redemption['redemption_status'] ) {
				return null;
			}
			$voucher    = $this->vouchers[ $redemption['voucher_id'] ];
			return $this->output_row(
				array(
					'amount_applied' => $redemption['amount_applied'],
					'code'           => $voucher['code'],
				),
				$output
			);
		}
		if ( preg_match( "/SELECT voucher_id, order_id FROM wp_doughboss_voucher_redemptions WHERE idempotency_key = '([^']+)'/", $query, $match ) ) {
			if ( ! isset( $this->redemptions[ $match[1] ] ) ) {
				return null;
			}
			$row = $this->redemptions[ $match[1] ];
			return $this->output_row(
				array(
					'voucher_id' => (int) $row['voucher_id'],
					'order_id'   => isset( $row['order_id'] ) ? (int) $row['order_id'] : 0,
				),
				$output
			);
		}
		if ( preg_match( '/SELECT \* FROM wp_doughboss_orders WHERE (id|order_number) = (?:\'([^\']+)\'|(\d+))/', $query, $match ) ) {
			$value = '' !== ( isset( $match[2] ) ? $match[2] : '' ) ? $match[2] : $match[3];
			foreach ( $this->orders as $row ) {
				if ( (string) $row[ $match[1] ] === (string) $value ) {
					return $this->output_row( $row, $output );
				}
			}
		}
		return null;
	}

	private function filtered_orders( $query ) {
		$rows = array_values( $this->orders );
		if ( false !== strpos( $query, "status NOT IN ( 'completed', 'cancelled' )" ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) {
						return ! in_array( $row['status'], array( 'completed', 'cancelled' ), true )
							&& 'preorder_request' !== $row['order_source'];
					}
				)
			);
		}
		if ( preg_match( '/\blocation_id = (\d+)/', $query, $match ) ) {
			$location_id = (int) $match[1];
			$rows = array_values( array_filter( $rows, static function ( $row ) use ( $location_id ) { return (int) $row['location_id'] === $location_id; } ) );
		}
		if ( preg_match( "/\bstatus = '([^']+)'/", $query, $match ) ) {
			$status = $match[1];
			$rows = array_values( array_filter( $rows, static function ( $row ) use ( $status ) { return $row['status'] === $status; } ) );
		}
		usort( $rows, static function ( $a, $b ) { return strcmp( $a['created_at'], $b['created_at'] ); } );
		return $rows;
	}

	public function get_results( $query = null, $output = OBJECT ) {
		$query = (string) $query;
		if ( false !== strpos( $query, 'FROM wp_doughboss_order_items' ) ) {
			$order_ids = array();
			if ( preg_match( '/order_id = (\d+)/', $query, $match ) ) {
				$order_ids[] = (int) $match[1];
			} elseif ( preg_match( '/order_id IN \(([^)]+)\)/', $query, $match ) ) {
				$order_ids = array_map( 'intval', explode( ',', $match[1] ) );
			}
			$rows = array_values( array_filter( $this->items, static function ( $row ) use ( $order_ids ) { return in_array( (int) $row['order_id'], $order_ids, true ); } ) );
			return ARRAY_A === $output ? $rows : array_map( static function ( $row ) { return (object) $row; }, $rows );
		}
		if ( false !== strpos( $query, 'FROM wp_doughboss_orders' ) ) {
			$rows = $this->filtered_orders( $query );
			return ARRAY_A === $output ? $rows : array_map( static function ( $row ) { return (object) $row; }, $rows );
		}
		if ( false !== strpos( $query, 'FROM wp_doughboss_vouchers v' ) ) {
			$rows = array();
			foreach ( array_reverse( $this->vouchers, true ) as $voucher ) {
				$redemption = null;
				foreach ( $this->redemptions as $candidate ) {
					if ( (int) $candidate['voucher_id'] === (int) $voucher['id'] ) {
						$redemption = $candidate;
						break;
					}
				}
				$rows[] = (object) array_merge(
					$voucher,
					array(
						'redeemed_at'      => $redemption ? $redemption['redeemed_at'] : null,
						'amount_applied'    => $redemption ? $redemption['amount_applied'] : null,
						'redeemed_channel'  => $redemption ? $redemption['channel'] : null,
					)
				);
			}
			return $rows;
		}
		return array();
	}

	public function query( $query ) {
		$query = (string) $query;
		if ( 'START TRANSACTION' === $query ) {
			$this->snapshot = serialize( array( $this->vouchers, $this->redemptions, $this->voucher_audit, $this->orders, $this->items, $this->events ) );
			return 0;
		}
		if ( 'ROLLBACK' === $query && null !== $this->snapshot ) {
			list( $this->vouchers, $this->redemptions, $this->voucher_audit, $this->orders, $this->items, $this->events ) = unserialize( $this->snapshot );
			$this->snapshot = null;
			return 0;
		}
		if ( 'COMMIT' === $query ) {
			$this->snapshot = null;
			return 0;
		}
		if ( preg_match( "/UPDATE wp_doughboss_voucher_redemptions SET order_id = (\d+) WHERE idempotency_key = '([^']+)' AND voucher_id = (\d+) AND order_id = 0/", $query, $match ) ) {
			$order_id = (int) $match[1];
			$key      = $match[2];
			$voucher_id = (int) $match[3];
			if ( ! isset( $this->redemptions[ $key ] ) || (int) $this->redemptions[ $key ]['voucher_id'] !== $voucher_id ) {
				return 0;
			}
			$current_order_id = isset( $this->redemptions[ $key ]['order_id'] ) ? (int) $this->redemptions[ $key ]['order_id'] : 0;
			if ( 0 !== $current_order_id ) {
				return 0;
			}
			$this->redemptions[ $key ]['order_id'] = $order_id;
			return 1;
		}
		if ( preg_match( "/DELETE FROM wp_doughboss_voucher_redemptions WHERE idempotency_key = '([^']+)' AND voucher_id = (\d+) AND order_id = 0/", $query, $match ) ) {
			$key        = $match[1];
			$voucher_id = (int) $match[2];
			if ( ! isset( $this->redemptions[ $key ] ) || (int) $this->redemptions[ $key ]['voucher_id'] !== $voucher_id || 0 !== (int) $this->redemptions[ $key ]['order_id'] ) {
				return 0;
			}
			unset( $this->redemptions[ $key ] );
			return 1;
		}
		if ( preg_match( "/UPDATE wp_doughboss_vouchers SET status = '([^']+)'(?:, meta = '([^']*)')?, updated_at = '([^']+)' WHERE id = (\d+) AND status = '([^']+)'/", $query, $match ) ) {
			$id = (int) $match[4];
			// Simulate a second database connection winning after evaluate() read
			// "issued" but before this worker's conditional claim reaches MySQL.
			if ( $id === (int) $this->claim_race_voucher_id && 'redeemed' === $match[1] ) {
				$this->vouchers[ $id ]['status'] = 'redeemed';
				$this->claim_race_voucher_id = 0;
				return 0;
			}
			if ( ! isset( $this->vouchers[ $id ] ) || $this->vouchers[ $id ]['status'] !== $match[5] ) {
				return 0;
			}
			$this->vouchers[ $id ]['status']     = $match[1];
			if ( isset( $match[2] ) && '' !== $match[2] ) {
				$this->vouchers[ $id ]['meta'] = str_replace( "''", "'", $match[2] );
			}
			$this->vouchers[ $id ]['updated_at'] = $match[3];
			return 1;
		}
		return 0;
	}

	public function insert( $table, $data, $formats = null ) {
		if ( false !== strpos( $table, 'doughboss_voucher_redemptions' ) ) {
			if ( $this->fail_next_redemption_insert ) {
				$this->fail_next_redemption_insert = false;
				return false;
			}
			$key = $data['idempotency_key'];
			if ( isset( $this->redemptions[ $key ] ) ) {
				return false;
			}
			$data['id'] = $this->next_redemption_id++;
			$data['order_id'] = isset( $data['order_id'] ) ? (int) $data['order_id'] : 0;
			$this->redemptions[ $key ] = $data;
			return 1;
		}
		if ( false !== strpos( $table, 'doughboss_voucher_audit' ) ) {
			$this->voucher_audit[] = $data;
			return 1;
		}
		if ( false !== strpos( $table, 'doughboss_vouchers' ) ) {
			foreach ( $this->vouchers as $row ) {
				if ( $row['code'] === $data['code'] ) {
					return false;
				}
			}
			$id = $this->next_voucher_id++;
			$data['id'] = $id;
			$this->vouchers[ $id ] = $data;
			$this->insert_id = $id;
			return 1;
		}
		if ( false !== strpos( $table, 'doughboss_order_items' ) ) {
			$data['id'] = $this->next_item_id++;
			$this->items[] = $data;
			return 1;
		}
		if ( false !== strpos( $table, 'doughboss_order_events' ) ) {
			if ( isset( $this->events[ $data['event_key'] ] ) ) {
				return false;
			}
			$this->events[ $data['event_key'] ] = $data;
			return 1;
		}
		if ( false !== strpos( $table, 'doughboss_orders' ) ) {
			if ( $this->fail_order_inserts > 0 ) {
				--$this->fail_order_inserts;
				return false;
			}
			foreach ( $this->orders as $row ) {
				if ( $row['checkout_key'] === $data['checkout_key'] || ( null !== ÷Ÿ9¶‰žËkºwµç}‰”èé¡•­•‘}Á…ÉÐ €œ°€Ä€¤ì(‘É…•}¥€€€ô€‘‘ˆ´ùÍ••‘}Ù½Õ¡•È €‘É…•}½‘”€¤ì(‘‘ˆ´ù±…¥µ}É…•}Ù½Õ¡•É}¥€ô€‘É…•}¥ì(‘É…•}±½Í•È€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•‘••´ €‘É…•}½‘”°€ÈÀ°€½¹±¥¹”œ°…ÉÉ…ä €¥‘•µÁ½Ñ•¹å}­•äœ€ôø€É…”µ±½Í•Èœ€¤€¤ì)½ÕÁ½¹}Ù½Õ¡•É}½¬ ½ÕÁ½¹}Ù½Õ¡•É}•ÉÉ½È €‘É…•}±½Í•È°€‘½Õ¡‰½ÍÍ}Ù½Õ¡•É}ÕÍ•œ€¤€˜˜€„¥ÍÍ•Ð €‘‘ˆ´ùÉ•‘•µÁÑ¥½¹ÍlÉ…”µ±½Í•Èt€¤°€½¹‘¥Ñ¥½¹…°±…¥´É•©•ÑÌ„Ý½É­•ÈÑ¡…Ð±½Í•ÌÑ¡”¥ÍÍÕ•µÑ¼µÉ•‘••µ•‘…Ñ…‰…Í”É…”œ€¤ì((‘…Õ‘¥Ñ}½‘”€ô€U´œ€¸½Õ¡	½ÍÍ}½ÕÁ½¹}½‘•}AÉ½‰”èé¡•­•‘}Á…ÉÐ €IMPœ°€À€¤€¸€œ´œ€¸½Õ¡	½ÍÍ}½ÕÁ½¹}½‘•}AÉ½‰”èé¡•­•‘}Á…ÉÐ €UY\œ°€Ä€¤ì(‘…Õ‘¥Ñ}¥€€€ô€‘‘ˆ´ùÍ••‘}Ù½Õ¡•È €‘…Õ‘¥Ñ}½‘”€¤ì(‘‘ˆ´ù™…¥±}¹•áÑ}É•‘•µÁÑ¥½¹}¥¹Í•ÉÐ€ôÑÉÕ”ì(‘…Õ‘¥Ñ}™…¥°€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•‘••´ €‘…Õ‘¥Ñ}½‘”°€ÈÀ°€½¹±¥¹”œ°…ÉÉ…ä €¥‘•µÁ½Ñ•¹å}­•äœ€ôø€…Õ‘¥Ðµ™…¥°œ€¤€¤ì)½ÕÁ½¹}Ù½Õ¡•É}½¬ ½ÕÁ½¹}Ù½Õ¡•É}•ÉÉ½È €‘…Õ‘¥Ñ}™…¥°°€‘½Õ¡‰½ÍÍ}Ù½Õ¡•É}…Õ‘¥Ðœ€¤€˜˜€¥ÍÍÕ•œ€ôôô€‘‘ˆ´ùÙ½Õ¡•ÉÍl€‘…Õ‘¥Ñ}¥ulÍÑ…ÑÕÌt€˜˜€„¥ÍÍ•Ð €‘‘ˆ´ùÉ•‘•µÁÑ¥½¹Íl…Õ‘¥Ðµ™…¥°t€¤°€µ…¹‘…Ñ½Éäµ…Õ‘¥Ð™…¥±ÕÉ”É½±±ÌÑ¡”Ù½Õ¡•È±…¥´‰…¬Ñ¼¥ÍÍÕ•œ€¤ì((‘Ñ¥±±}É•Ù•ÉÍ•}½‘”€ô€Q%10´œ€¸½Õ¡	½ÍÍ}½ÕÁ½¹}½‘•}AÉ½‰”èé¡•­•‘}Á…ÉÐ €œ°€À€¤€¸€œ´œ€¸½Õ¡	½ÍÍ}½ÕÁ½¹}½‘•}AÉ½‰”èé¡•­•‘}Á…ÉÐ €),œ°€Ä€¤ì(‘Ñ¥±±}É•Ù•ÉÍ•}¥€€€ô€‘‘ˆ´ùÍ••‘}Ù½Õ¡•È €‘Ñ¥±±}É•Ù•ÉÍ•}½‘”€¤ì(‘Ñ¥±±}É•‘••´€€€€€€€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•‘••´ ($‘Ñ¥±±}É•Ù•ÉÍ•}½‘”°($ÄÈ¸ÀÀ°($¥¹ÍÑ½É”œ°(%…ÉÉ…ä ($$¥‘•µÁ½Ñ•¹å}­•äœ€€€€ôø€Ñ¥±°µÉ•Ù•ÉÍ…°µ½É¥¥¹…°œ°($$Á½ÍÁ…±}Ñ¥­•Ñ}¹¼œ€€€ôø€A=LµQMP´ÄÀÀÄœ°($$É•‘••µ•‘}‰å}ÕÍ•É}¥œ€ôø€ÈÈ°($$É•‘••µ•‘}‰å}¹…µ”œ€€€ôø€…Í¡¥•È=¹”œ°($¤(¤ì(‘Ñ¥±±}µ¥ÍÍ¥¹}É•…Í½¸€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•Ù•ÉÍ•}É•‘•µÁÑ¥½¸ €‘Ñ¥±±}É•Ù•ÉÍ•}¥°€¹¼œ°€à°€Y½Õ¡•È5…¹…•Èœ€¤ì(‘Ñ¥±±}É•Ù•ÉÍ…°€€€€€€€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•Ù•ÉÍ•}É•‘•µÁÑ¥½¸ €‘Ñ¥±±}É•Ù•ÉÍ•}¥°€ÕÁ±¥…Ñ”Í…¸…ÐÑ¥±°œ°€à°€Y½Õ¡•È5…¹…•Èœ€¤ì(‘Ñ¥±±}Í•½¹‘}É•Ù•ÉÍ”€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•Ù•ÉÍ•}É•‘•µÁÑ¥½¸ €‘Ñ¥±±}É•Ù•ÉÍ•}¥°€M•½¹½ÉÉ•Ñ¥½¸…ÑÑ•µÁÐœ°€à°€Y½Õ¡•È5…¹…•Èœ€¤ì(‘Ñ¥±±}É•‘••µ}……¥¸€€€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•‘••´ ($‘Ñ¥±±}É•Ù•ÉÍ•}½‘”°($ÄÈ¸ÀÀ°($¥¹ÍÑ½É”œ°(%…ÉÉ…ä ($$¥‘•µÁ½Ñ•¹å}­•äœ€€€€ôø€Ñ¥±°µÉ•Ù•ÉÍ…°µÉ•ÑÉäœ°($$Á½ÍÁ…±}Ñ¥­•Ñ}¹¼œ€€€ôø€A=LµQMP´ÄÀÀÈœ°($$É•‘••µ•‘}‰å}ÕÍ•É}¥œ€ôø€ÈÈ°($$É•‘••µ•‘}‰å}¹…µ”œ€€€ôø€…Í¡¥•È=¹”œ°($¤(¤ì)½ÕÁ½¹}Ù½Õ¡•É}½¬ (%¥Í}…ÉÉ…ä €‘Ñ¥±±}É•‘••´€¤€˜˜½ÕÁ½¹}Ù½Õ¡•É}•ÉÉ½È €‘Ñ¥±±}µ¥ÍÍ¥¹}É•…Í½¸°€‘½Õ¡‰½ÍÍ}Ù½Õ¡•É}É•Ù•ÉÍ•}É•…Í½¸œ€¤($$˜˜¥Í}…ÉÉ…ä €‘Ñ¥±±}É•Ù•ÉÍ…°€¤€˜˜€¥ÍÍÕ•œ€ôôô€‘‘ˆ´ùÙ½Õ¡•ÉÍl€‘Ñ¥±±}É•Ù•ÉÍ•}¥ulÍÑ…ÑÕÌt($$˜˜€Ä€ôôô½Õ¹Ð €‘‘ˆ´ùÙ½Õ¡•É}…Õ‘¥Ð€¤€˜˜€É•Ù•ÉÍ…°œ€ôôô€‘‘ˆ´ùÙ½Õ¡•É}…Õ‘¥ÑlÁul•Ù•¹Ñ}ÑåÁ”t($$˜˜½ÕÁ½¹}Ù½Õ¡•É}•ÉÉ½È €‘Ñ¥±±}Í•½¹‘}É•Ù•ÉÍ”°€‘½Õ¡‰½ÍÍ}Ù½Õ¡•É}É•Ù•ÉÍ•}ÍÑ…Ñ”œ€¤($$˜˜¥Í}…ÉÉ…ä €‘Ñ¥±±}É•‘••µ}……¥¸€¤€˜˜€É•‘••µ•œ€ôôô€‘‘ˆ´ùÙ½Õ¡•ÉÍl€‘Ñ¥±±}É•Ù•ÉÍ•}¥ulÍÑ…ÑÕÌt°($¥¸µÍÑ½É”µ¥ÌµÍ…¸É•Ù•ÉÍ…°É•ÅÕ¥É•Ì„µ…¹…•ÈÉ•…Í½¸°­••ÁÌ…¸…Õ‘¥ÐÉ½Ü…¹…¸½¹±ä¡…ÁÁ•¸½¹”œ(¤ì((‘É•Ù•ÉÑ}½‘”€ô€IX´œ€¸½Õ¡	½ÍÍ}½ÕÁ½¹}½‘•}AÉ½‰”èé¡•­•‘}Á…ÉÐ €aehœ°€À€¤€¸€œ´œ€¸½Õ¡	½ÍÍ}½ÕÁ½¹}½‘•}AÉ½‰”èé¡•­•‘}Á…ÉÐ €œÈÌÐœ°€Ä€¤ì(‘É•Ù•ÉÑ}¥€€€ô€‘‘ˆ´ùÍ••‘}Ù½Õ¡•È €‘É•Ù•ÉÑ}½‘”€¤ì(‘‰•™½É•}É•Ù•ÉÐ€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•‘••´ €‘É•Ù•ÉÑ}½‘”°€ÈÀ°€½¹±¥¹”œ°…ÉÉ…ä €¥‘•µÁ½Ñ•¹å}­•äœ€ôø€½É‘•ÈµÉ•Ù•ÉÐ´Äœ€¤€¤ì)½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•Ù•ÉÑ}É•‘•µÁÑ¥½¸ €½É‘•ÈµÉ•Ù•ÉÐ´Äœ€¤ì(‘…™Ñ•É}É•Ù•ÉÐ€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•‘••´ €‘É•Ù•ÉÑ}½‘”°€ÈÀ°€½¹±¥¹”œ°…ÉÉ…ä €¥‘•µÁ½Ñ•¹å}­•äœ€ôø€½É‘•ÈµÉ•Ù•ÉÐ´Äœ€¤€¤ì)½ÕÁ½¹}Ù½Õ¡•É}½¬ ¥Í}…ÉÉ…ä €‘‰•™½É•}É•Ù•ÉÐ€¤€˜˜¥Í}…ÉÉ…ä €‘…™Ñ•É}É•Ù•ÉÐ€¤€˜˜€É•‘••µ•œ€ôôô€‘‘ˆ´ùÙ½Õ¡•ÉÍl€‘É•Ù•ÉÑ}¥ulÍÑ…ÑÕÌt€˜˜¥ÍÍ•Ð €‘‘ˆ´ùÉ•‘•µÁÑ¥½¹Íl½É‘•ÈµÉ•Ù•ÉÐ´Ät€¤°€É•Ù•ÉÐÝ¥¹¹¥¹œ‰•™½É”Í…µ”µ­•äÉ•Á±…ä™½É•Ì„™É•Í É•‘•µÁÑ¥½¸¥¹ÍÑ•…½˜É•ÑÕÉ¹¥¹œ„‘•±•Ñ•…Õ‘¥Ðœ€¤ì((‘Á…¥‘}™…¥±ÕÉ•}½‘”€ô€A%´œ€¸½Õ¡	½ÍÍ}½ÕÁ½¹}½‘•}AÉ½‰”èé¡•­•‘}Á…ÉÐ € àœ°€À€¤€¸€œ´œ€¸½Õ¡	½ÍÍ}½ÕÁ½¹}½‘•}AÉ½‰”èé¡•­•‘}Á…ÉÐ €),äœ°€Ä€¤ì(‘Á…¥‘}™…¥±ÕÉ•}¥€€€ô€‘‘ˆ´ùÍ••‘}Ù½Õ¡•È €‘Á…¥‘}™…¥±ÕÉ•}½‘”€¤ì(‘Á…¥‘}™…¥±ÕÉ•}­•ä€€ô¡…Í  €Í¡„ÈÔØœ°€Á…¥µÉ•Í•ÉÙ•µ½É‘•Èµ™…¥±ÕÉ”œ€¤ì)½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•Í•ÉÙ” €‘Á…¥‘}™…¥±ÕÉ•}½‘”°€ÈÀ°€½¹±¥¹”œ°€‘Á…¥‘}™…¥±ÕÉ•}­•ä€¤ì(‘Á…¥‘}™…¥±ÕÉ•}™¥ÉÍÐ€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•‘••´ €‘Á…¥‘}™…¥±ÕÉ•}½‘”°€ÈÀ°€½¹±¥¹”œ°…ÉÉ…ä €¥‘•µÁ½Ñ•¹å}­•äœ€ôø€Á…¥µÉ•Í•ÉÙ•µ™…¥±ÕÉ”œ°€É•Í•ÉÙ…Ñ¥½¹}­•äœ€ôø€‘Á…¥‘}™…¥±ÕÉ•}­•ä€¤€¤ì(¼¼Ù•É¥™¥•Á…¥½É‘•Èµ¥¹Í•ÉÐ™…¥±ÕÉ”‘•±¥‰•É…Ñ•±ä‘½•Ì¹½Ð…±°É•Ù•ÉÑ}É•‘•µÁÑ¥½¸ ¤¸(‘Á…¥‘}™…¥±ÕÉ•}É•ÑÉä€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•‘••´ €‘Á…¥‘}™…¥±ÕÉ•}½‘”°€äää°€½¹±¥¹”œ°…ÉÉ…ä €¥‘•µÁ½Ñ•¹å}­•äœ€ôø€Á…¥µÉ•Í•ÉÙ•µ™…¥±ÕÉ”œ°€É•Í•ÉÙ…Ñ¥½¹}­•äœ€ôø€‘Á…¥‘}™…¥±ÕÉ•}­•ä€¤€¤ì(‘Á…¥‘}™…¥±ÕÉ•}É¥Ù…°€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•Í•ÉÙ” €‘Á…¥‘}™…¥±ÕÉ•}½‘”°€ÈÀ°€½¹±¥¹”œ°¡…Í  €Í¡„ÈÔØœ°€Á…¥µÉ•Í•ÉÙ•µÉ¥Ù…°œ€¤€¤ì)½ÕÁ½¹}Ù½Õ¡•É}½¬ (%¥Í}…ÉÉ…ä €‘Á…¥‘}™…¥±ÕÉ•}™¥ÉÍÐ€¤€˜˜€‘Á…¥‘}™…¥±ÕÉ•}É•ÑÉä€ôôô€‘Á…¥‘}™…¥±ÕÉ•}™¥ÉÍÐ($$˜˜€É•‘••µ•œ€ôôô€‘‘ˆ´ùÙ½Õ¡•ÉÍl€‘Á…¥‘}™…¥±ÕÉ•}¥ulÍÑ…ÑÕÌt($$˜˜¥ÍÍ•Ð €‘‘ˆ´ùÉ•‘•µÁÑ¥½¹ÍlÁ…¥µÉ•Í•ÉÙ•µ™…¥±ÕÉ”t€¤€˜˜€À€ôôô€¡¥¹Ð¤€‘‘ˆ´ùÉ•‘•µÁÑ¥½¹ÍlÁ…¥µÉ•Í•ÉÙ•µ™…¥±ÕÉ”ul½É‘•É}¥t($$˜˜½ÕÁ½¹}Ù½Õ¡•É}•ÉÉ½È €‘Á…¥‘}™…¥±ÕÉ•}É¥Ù…°°€‘½Õ¡‰½ÍÍ}Ù½Õ¡•É}¥¹Ù…±¥œ€¤°($Á…¥É•Í•ÉÙ•½É‘•È™…¥±ÕÉ”ÍÑ…åÌ½¹ÍÕµ•…¹É•Á±…å…‰±”Ý¡¥±”•Ù•ÉäÉ¥Ù…°¡•­½ÕÐÉ•µ…¥¹Ì‰±½­•œ(¤ì((‘½Ý¹•É}½‘”€ô€=]9H´œ€¸½Õ¡	½ÍÍ}½ÕÁ½¹}½‘•}AÉ½‰”èé¡•­•‘}Á…ÉÐ €-4àœ°€À€¤€¸€œ´œ€¸½Õ¡	½ÍÍ}½ÕÁ½¹}½‘•}AÉ½‰”èé¡•­•‘}Á…ÉÐ €9@äœ°€Ä€¤ì(‘½Ý¹•É}¥€€€ô€‘‘ˆ´ùÍ••‘}Ù½Õ¡•È €‘½Ý¹•É}½‘”€¤ì(‘½Ý¹•É}­•ä€€ô¡…Í  €Í¡„ÈÔØœ°€É•Í•ÉÙ•µ½Ý¹•Èµ¡•­½ÕÐœ€¤ì(‘É¥Ù…±}­•ä€€ô¡…Í  €Í¡„ÈÔØœ°€É•Í•ÉÙ•µÉ¥Ù…°µ¡•­½ÕÐœ€¤ì)½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•Í•ÉÙ” €‘½Ý¹•É}½‘”°€ÈÀ°€½¹±¥¹”œ°€‘½Ý¹•É}­•ä€¤ì(‘É•Í•ÉÙ•‘}Í…¸€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•‘••´ €‘½Ý¹•É}½‘”°€ÈÀ°€¥¹ÍÑ½É”œ°…ÉÉ…ä €¥‘•µÁ½Ñ•¹å}­•äœ€ôø€É•Í•ÉÙ•µÍ…¸œ°€Á½ÍÁ…±}Ñ¥­•Ñ}¹¼œ€ôø€A=LµIMIY´Äœ°€É•‘••µ•‘}‰å}ÕÍ•É}¥œ€ôø€ÈÈ°€É•‘••µ•‘}‰å}¹…µ”œ€ôø€…Í¡¥•È=¹”œ€¤€¤ì(‘É•Í•ÉÙ•‘}ÝÉ½¹œ€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•‘••´ €‘½Ý¹•É}½‘”°€ÈÀ°€½¹±¥¹”œ°…ÉÉ…ä €¥‘•µÁ½Ñ•¹å}­•äœ€ôø€É•Í•ÉÙ•µÝÉ½¹œœ°€É•Í•ÉÙ…Ñ¥½¹}­•äœ€ôø€‘É¥Ù…±}­•ä€¤€¤ì(‘É•Í•ÉÙ•‘}½Ý¹•È€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•‘••´ €‘½Ý¹•É}½‘”°€ÈÀ°€½¹±¥¹”œ°…ÉÉ…ä €¥‘•µÁ½Ñ•¹å}­•äœ€ôø€É•Í•ÉÙ•µ½Ý¹•Èœ°€É•Í•ÉÙ…Ñ¥½¹}­•äœ€ôø€‘½Ý¹•É}­•ä€¤€¤ì(‘½Ý¹•É}µ•Ñ…}…™Ñ•È€ô©Í½¹}‘•½‘” €¡ÍÑÉ¥¹œ¤€‘‘ˆ´ùÙ½Õ¡•ÉÍl€‘½Ý¹•É}¥ulµ•Ñ„t°ÑÉÕ”€¤ì)½ÕÁ½¹}Ù½Õ¡•É}½¬ ½ÕÁ½¹}Ù½Õ¡•É}•ÉÉ½È €‘É•Í•ÉÙ•‘}Í…¸°€‘½Õ¡‰½ÍÍ}Ù½Õ¡•É}É•Í•ÉÙ•œ€¤€˜˜½ÕÁ½¹}Ù½Õ¡•É}•ÉÉ½È €‘É•Í•ÉÙ•‘}ÝÉ½¹œ°€‘½Õ¡‰½ÍÍ}Ù½Õ¡•É}É•Í•ÉÙ•œ€¤°€…Ñ¥Ù”¡•­½ÕÐ±•…Í”É•©•ÑÌÍÑ…™˜½¹¼µ­•ä…¹É¥Ù…°µ­•äÉ•‘•µÁÑ¥½¸œ€¤ì)½ÕÁ½¹}Ù½Õ¡•É}½¬ ¥Í}…ÉÉ…ä €‘É•Í•ÉÙ•‘}½Ý¹•È€¤€˜˜€É•‘••µ•œ€ôôô€‘‘ˆ´ùÙ½Õ¡•ÉÍl€‘½Ý¹•É}¥ulÍÑ…ÑÕÌt€˜˜•µÁÑä €‘½Ý¹•É}µ•Ñ…}…™Ñ•Él½Õ¡	½ÍÍ}Y½Õ¡•ÈèéIMIYQ%=9}5Q}-dt€¤°€±•…Í”½Ý¹•ÈÉ•‘••µÌ½¹”…¹…Ñ½µ¥…±±ä±•…ÉÌÉ•Í•ÉÙ…Ñ¥½¸µ•Ñ…‘…Ñ„œ€¤ì(‘É•Í•ÉÙ•‘}½Ý¹•É}É•Á±…ä€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•‘••´ €‘½Ý¹•É}½‘”°€äää°€½¹±¥¹”œ°…ÉÉ…ä €¥‘•µÁ½Ñ•¹å}­•äœ€ôø€É•Í•ÉÙ•µ½Ý¹•Èœ°€É•Í•ÉÙ…Ñ¥½¹}­•äœ€ôø€‘½Ý¹•É}­•ä€¤€¤ì)½ÕÁ½¹}Ù½Õ¡•É}½¬ €‘É•Í•ÉÙ•‘}½Ý¹•É}É•Á±…ä€ôôô€‘É•Í•ÉÙ•‘}½Ý¹•È°€É•Í•ÉÙ•¡•­½ÕÐÉ•ÑÉäÉ•Á±…åÌ¥ÑÌ¥µµÕÑ…‰±”É•‘•µÁÑ¥½¸É•ÍÕ±Ðœ€¤ì()½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•Ù•ÉÑ}É•‘•µÁÑ¥½¸ €É•Í•ÉÙ•µ½Ý¹•Èœ°€‘½Ý¹•É}­•ä€¤ì(‘É•ÍÑ½É•‘}µ•Ñ„€ô©Í½¹}‘•½‘” €¡ÍÑÉ¥¹œ¤€‘‘ˆ´ùÙ½Õ¡•ÉÍl€‘½Ý¹•É}¥ulµ•Ñ„t°ÑÉÕ”€¤ì(‘É¥Ù…±}…™Ñ•É}É•Ù•ÉÐ€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•Í•ÉÙ” €‘½Ý¹•É}½‘”°€ÈÀ°€½¹±¥¹”œ°€‘É¥Ù…±}­•ä€¤ì)½ÕÁ½¹}Ù½Õ¡•É}½¬ ($¥ÍÍÕ•œ€ôôô€‘‘ˆ´ùÙ½Õ¡•ÉÍl€‘½Ý¹•É}¥ulÍÑ…ÑÕÌt€˜˜€„¥ÍÍ•Ð €‘‘ˆ´ùÉ•‘•µÁÑ¥½¹ÍlÉ•Í•ÉÙ•µ½Ý¹•Èt€¤($$˜˜€‘½Ý¹•É}­•ä€ôôô€‘É•ÍÑ½É•‘}µ•Ñ…l½Õ¡	½ÍÍ}Y½Õ¡•ÈèéIMIYQ%=9}5Q}-dul­•ät($$˜˜½ÕÁ½¹}Ù½Õ¡•É}•ÉÉ½È €‘É¥Ù…±}…™Ñ•É}É•Ù•ÉÐ°€‘½Õ¡‰½ÍÍ}Ù½Õ¡•É}É•Í•ÉÙ•œ€¤°($™…¥±•µ½É‘•È½µÁ•¹Í…Ñ¥½¸É•ÍÑ½É•ÌÑ¡”Í…µ”½Ý¹•È±•…Í”…¹‰±½­Ì„É¥Ù…°¡•­½ÕÐœ(¤ì(‘½Ý¹•É}É•ÑÉä€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•‘••´ €‘½Ý¹•É}½‘”°€ÈÀ°€½¹±¥¹”œ°…ÉÉ…ä €¥‘•µÁ½Ñ•¹å}­•äœ€ôø€É•Í•ÉÙ•µ½Ý¹•Èœ°€É•Í•ÉÙ…Ñ¥½¹}­•äœ€ôø€‘½Ý¹•É}­•ä€¤€¤ì(‘Ý¥¹¹¥¹}Á¤€€ô€Á¥}Ñ•ÍÑ}Ý¥¹¹¥¹}½É‘•É|ÄÈÌÐÔœì(‘‘ˆ´ù½É‘•ÉÍlÜÜÝt€ô…ÉÉ…ä €¥œ€ôø€ÜÜÜ°€¡•­½ÕÑ}­•äœ€ôø€‘½Ý¹•É}­•ä°€Á…åµ•¹Ñ}¥¹Ñ•¹Ñ}¥œ€ôø€‘Ý¥¹¹¥¹}Á¤€¤ì)½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•Ù•ÉÑ}É•‘•µÁÑ¥½¸ €É•Í•ÉÙ•µ½Ý¹•Èœ°€‘½Ý¹•É}­•ä°€‘Ý¥¹¹¥¹}Á¤€¤ì)½Õ¡	½ÍÍ}Y½Õ¡•Èèé±¥¹­}É•‘•µÁÑ¥½¹}Ñ½}½É‘•È €É•Í•ÉÙ•µ½Ý¹•Èœ°€ÜÜÜ€¤ì)½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•Ù•ÉÑ}É•‘•µÁÑ¥½¸ €É•Í•ÉÙ•µ½Ý¹•Èœ°€‘½Ý¹•É}­•ä°€‘Ý¥¹¹¥¹}Á¤€¤ì)½ÕÁ½¹}Ù½Õ¡•É}½¬ (%¥Í}…ÉÉ…ä €‘½Ý¹•É}É•ÑÉä€¤€˜˜€É•‘••µ•œ€ôôô€‘‘ˆ´ùÙ½Õ¡•ÉÍl€‘½Ý¹•É}¥ulÍÑ…ÑÕÌt($$˜˜€ÜÜÜ€ôôô€¡¥¹Ð¤€‘‘ˆ´ùÉ•‘•µÁÑ¥½¹ÍlÉ•Í•ÉÙ•µ½Ý¹•Èul½É‘•É}¥t°($Ý¥¹¹¥¹œÁ…¥½É‘•È¥Ì…Ñ½µ¥…±±ä±¥¹­•…¹„±…Ñ”™…¥±•Ý½É­•È…¹¹½ÐÉ•¥ÍÍÕ”¥ÑÌÙ½Õ¡•Èœ(¤ì)Õ¹Í•Ð €‘‘ˆ´ù½É‘•ÉÍlÜÜÝt€¤ì((‘±•…Í•}…Õ‘¥Ñ}½‘”€ô€5Q´œ€¸½Õ¡	½ÍÍ}½ÕÁ½¹}½‘•}AÉ½‰”èé¡•­•‘}Á…ÉÐ €EHÈœ°€À€¤€¸€œ´œ€¸½Õ¡	½ÍÍ}½ÕÁ½¹}½‘•}AÉ½‰”èé¡•­•‘}Á…ÉÐ €MPÌœ°€Ä€¤ì(‘±•…Í•}…Õ‘¥Ñ}¥€€€ô€‘‘ˆ´ùÍ••‘}Ù½Õ¡•È €‘±•…Í•}…Õ‘¥Ñ}½‘”°…ÉÉ…ä €µ•Ñ„œ€ôøÝÁ}©Í½¹}•¹½‘” …ÉÉ…ä €…µÁ…¥¹}¹½Ñ”œ€ôø€­••Àµµ”œ€¤€¤€¤€¤ì(‘±•…Í•}…Õ‘¥Ñ}­•ä€€ô¡…Í  €Í¡„ÈÔØœ°€±•…Í”µ…Õ‘¥Ðµ½Ý¹•Èœ€¤ì)½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•Í•ÉÙ” €‘±•…Í•}…Õ‘¥Ñ}½‘”°€ÈÀ°€½¹±¥¹”œ°€‘±•…Í•}…Õ‘¥Ñ}­•ä€¤ì(‘‘ˆ´ù™…¥±}¹•áÑ}É•‘•µÁÑ¥½¹}¥¹Í•ÉÐ€ôÑÉÕ”ì(‘±•…Í•}…Õ‘¥Ñ}™…¥°€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•‘••´ €‘±•…Í•}…Õ‘¥Ñ}½‘”°€ÈÀ°€½¹±¥¹”œ°…ÉÉ…ä €¥‘•µÁ½Ñ•¹å}­•äœ€ôø€±•…Í”µ…Õ‘¥Ðµ™…¥°œ°€É•Í•ÉÙ…Ñ¥½¹}­•äœ€ôø€‘±•…Í•}…Õ‘¥Ñ}­•ä€¤€¤ì(‘±•…Í•}…Õ‘¥Ñ}µ•Ñ„€ô©Í½¹}‘•½‘” €¡ÍÑÉ¥¹œ¤€‘‘ˆ´ùÙ½Õ¡•ÉÍl€‘±•…Í•}…Õ‘¥Ñ}¥ulµ•Ñ„t°ÑÉÕ”€¤ì)½ÕÁ½¹}Ù½Õ¡•É}½¬ (%½ÕÁ½¹}Ù½Õ¡•É}•ÉÉ½È €‘±•…Í•}…Õ‘¥Ñ}™…¥°°€‘½Õ¡‰½ÍÍ}Ù½Õ¡•É}…Õ‘¥Ðœ€¤€˜˜€¥ÍÍÕ•œ€ôôô€‘‘ˆ´ùÙ½Õ¡•ÉÍl€‘±•…Í•}…Õ‘¥Ñ}¥ulÍÑ…ÑÕÌt($$˜˜€­••Àµµ”œ€ôôô€‘±•…Í•}…Õ‘¥Ñ}µ•Ñ…l…µÁ…¥¹}¹½Ñ”t€˜˜€‘±•…Í•}…Õ‘¥Ñ}­•ä€ôôô€‘±•…Í•}…Õ‘¥Ñ}µ•Ñ…l½Õ¡	½ÍÍ}Y½Õ¡•ÈèéIMIYQ%=9}5Q}-dul­•ät°($…Õ‘¥Ð¥¹Í•ÉÑ¥½¸™…¥±ÕÉ”É•ÍÑ½É•ÌÑ¡”•á…ÐÁÉ”µ±…¥´µ•Ñ…‘…Ñ„…¹½Ý¹•È±•…Í”œ(¤ì((‘•áÁ¥É•‘}Í…¹}½‘”€ô€=1´œ€¸½Õ¡	½ÍÍ}½ÕÁ½¹}½‘•}AÉ½‰”èé¡•­•‘}Á…ÉÐ €UXÐœ°€À€¤€¸€œ´œ€¸½Õ¡	½ÍÍ}½ÕÁ½¹}½‘•}AÉ½‰”èé¡•­•‘}Á…ÉÐ €]`Ôœ°€Ä€¤ì(‘•áÁ¥É•‘}Í…¹}¥€€€ô€‘‘ˆ´ùÍ••‘}Ù½Õ¡•È €‘•áÁ¥É•‘}Í…¹}½‘”€¤ì(‘•áÁ¥É•‘}Í…¹}­•ä€€ô¡…Í  €Í¡„ÈÔØœ°€•áÁ¥É•µÍ…¸µ½Ý¹•Èœ€¤ì)½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•Í•ÉÙ” €‘•áÁ¥É•‘}Í…¹}½‘”°€ÈÀ°€½¹±¥¹”œ°€‘•áÁ¥É•‘}Í…¹}­•ä€¤ì(‘•áÁ¥É•‘}Í…¹}µ•Ñ„€ô©Í½¹}‘•½‘” €¡ÍÑÉ¥¹œ¤€‘‘ˆ´ùÙ½Õ¡•ÉÍl€‘•áÁ¥É•‘}Í…¹}¥ulµ•Ñ„t°ÑÉÕ”€¤ì(‘•áÁ¥É•‘}Í…¹}µ•Ñ…l½Õ¡	½ÍÍ}Y½Õ¡•ÈèéIMIYQ%=9}5Q}-dul•áÁ¥É•Í}…Ðt€ôÑ¥µ” ¤€´€Äì(‘‘ˆ´ùÙ½Õ¡•ÉÍl€‘•áÁ¥É•‘}Í…¹}¥ulµ•Ñ„t€ôÝÁ}©Í½¹}•¹½‘” €‘•áÁ¥É•‘}Í…¹}µ•Ñ„€¤ì(‘•áÁ¥É•‘}Í…¸€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•‘••´ €‘•áÁ¥É•‘}Í…¹}½‘”°€ÈÀ°€¥¹ÍÑ½É”œ°…ÉÉ…ä €¥‘•µÁ½Ñ•¹å}­•äœ€ôø€•áÁ¥É•µÍÑ…™˜µÍ…¸œ°€Á½ÍÁ…±}Ñ¥­•Ñ}¹¼œ€ôø€A=LµaA%I´Äœ°€É•‘••µ•‘}‰å}ÕÍ•É}¥œ€ôø€ÈÈ°€É•‘••µ•‘}‰å}¹…µ”œ€ôø€…Í¡¥•È=¹”œ€¤€¤ì)½ÕÁ½¹}Ù½Õ¡•É}½¬ ¥Í}…ÉÉ…ä €‘•áÁ¥É•‘}Í…¸€¤€˜˜€É•‘••µ•œ€ôôô€‘‘ˆ´ùÙ½Õ¡•ÉÍl€‘•áÁ¥É•‘}Í…¹}¥ulÍÑ…ÑÕÌt°€•áÁ¥É•…‰…¹‘½¹•±•…Í”¹¼±½¹•È‰±½­Ì…¸Õ¹É•Í•ÉÙ•ÍÑ…™˜É•‘•µÁÑ¥½¸œ€¤ì((‘±½­}½‘”€ô€1=,´œ€¸½Õ¡	½ÍÍ}½ÕÁ½¹}½‘•}AÉ½‰”èé¡•­•‘}Á…ÉÐ €ehØœ°€À€¤€¸€œ´œ€¸½Õ¡	½ÍÍ}½ÕÁ½¹}½‘•}AÉ½‰”èé¡•­•‘}Á…ÉÐ €œÈÌÐœ°€Ä€¤ì(‘±½­}¥€€€ô€‘‘ˆ´ùÍ••‘}Ù½Õ¡•È €‘±½­}½‘”€¤ì(‘‘ˆ´ù‘•¹å}¹•áÑ}Ù½Õ¡•É}±½¬€ôÑÉÕ”ì(‘±½­}É•‘••´€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•‘••´ €‘±½­}½‘”°€ÈÀ°€½¹±¥¹”œ°…ÉÉ…ä €¥‘•µÁ½Ñ•¹å}­•äœ€ôø€±½¬µÕ¹…Ù…¥±…‰±”œ€¤€¤ì)½ÕÁ½¹}Ù½Õ¡•É}½¬ ½ÕÁ½¹}Ù½Õ¡•É}•ÉÉ½È €‘±½­}É•‘••´°€‘½Õ¡‰½ÍÍ}Ù½Õ¡•É}‰ÕÍäœ€¤€˜˜€¥ÍÍÕ•œ€ôôô€‘‘ˆ´ùÙ½Õ¡•ÉÍl€‘±½­}¥ulÍÑ…ÑÕÌt°€É•‘•µÁÑ¥½¸™…¥±Ì±½Í•Ý¡•¸¥ÑÌÁ•ÈµÙ½Õ¡•È±½¬¥ÌÕ¹…Ù…¥±…‰±”œ€¤ì()½ÕÁ½¹}Ù½Õ¡•É}Í•Ñ¥½¸ €=É‘•ÈÁ•ÉÍ¥ÍÑ•¹”…¹…‘µ¥¸½-LÁ…å±½…‘Ìœ€¤ì)ÕÁ‘…Ñ•}½ÁÑ¥½¸ ½Õ¡	½ÍÍ}M•ÑÑ¥¹Ìèé=AQ%=9}-d°…ÉÉ…ä €Ñ…á}É…Ñ”œ€ôø€ÄÀ°€ÍÑ}¥¹±ÕÍ¥Ù”œ€ôø€Ä°€‘•±¥Ù•Éå}™•”œ€ôø€À°€ÕÉÉ•¹å}½‘”œ€ôø€Uœ€¤€¤ì(‘½É‘•É}‘…Ñ„€ô…ÉÉ…ä ($½É‘•É}ÑåÁ”œ€ôø€Á¥­ÕÀœ°€±½…Ñ¥½¹}¥œ€ôø€Ä°€ÕÍÑ½µ•É}¹…µ”œ€ôø€Y½Õ¡•ÈÕÍÑ½µ•Èœ°($ÕÍÑ½µ•É}•µ…¥°œ€ôø€ÕÍÑ½µ•É•á…µÁ±”¹Ñ•ÍÐœ°€ÕÍÑ½µ•É}Á¡½¹”œ€ôø€œÀÐÀÀÀÀÀÀÀÀœ°($…‘‘É•ÍÌœ€ôø€œœ°€¹½Ñ•Ìœ€ôø€œœ°€ÍÕ‰Ñ½Ñ…°œ€ôø€‘¥¹±ÕÍ¥Ù•lÍÕ‰Ñ½Ñ…°t°€Ñ…àœ€ôø€‘¥¹±ÕÍ¥Ù•lÑ…àt°($‘•±¥Ù•Éå}™•”œ€ôø€‘¥¹±ÕÍ¥Ù•l‘•±¥Ù•Éå}™•”t°€Ñ½Ñ…°œ€ôø€‘¥¹±ÕÍ¥Ù•lÑ½Ñ…°t°€‘¥Í½Õ¹Ðœ€ôø€‘¥¹±ÕÍ¥Ù•l‘¥Í½Õ¹Ðt°($Ù½Õ¡•É}½‘”œ€ôø€‘™¥á•‘}½‘”°€Á…åµ•¹Ñ}ÍÑ…ÑÕÌœ€ôø€Õ¹Á…¥œ°€Á…åµ•¹Ñ}µ•Ñ¡½œ€ôø€œœ°($Á…åµ•¹Ñ}¥¹Ñ•¹Ñ}¥œ€ôø€œœ°€¡•­½ÕÑ}­•äœ€ôøÍÑÉ}É•Á•…Ð €„œ°€ØÐ€¤°(¤ì(‘½É‘•É}±¥¹•Ì€ô…ÉÉ…ä (%…ÉÉ…ä €¥Ñ•µ}¥œ€ôø€Ü°€¹…µ”œ€ôø€Q•ÍÐ5…¹½ÕÍ œ°€Í¥é”œ€ôø€œœ°€Ñ½ÁÁ¥¹Ìœ€ôø…ÉÉ…ä ¤°€ÅÕ…¹Ñ¥Ñäœ€ôø€Ä°€Õ¹¥Ñ}ÁÉ¥”œ€ôø€ÈÐ¸äÔ°€±¥¹•}Ñ½Ñ…°œ€ôø€ÈÐ¸äÔ€¤°(¤ì(‘É•…Ñ•€ô½Õ¡	½ÍÍ}=É‘•ÈèéÉ•…Ñ” €‘½É‘•É}‘…Ñ„°€‘½É‘•É}±¥¹•Ì€¤ì(‘½É‘•É}¥€ô¥Í}…ÉÉ…ä €‘É•…Ñ•€¤€ü€¡¥¹Ð¤€‘É•…Ñ•‘l½É‘•É}¥t€è€Àì)½Õ¡	½ÍÍ}Y½Õ¡•Èèé±¥¹­}É•‘•µÁÑ¥½¹}Ñ½}½É‘•È €¡•­½ÕÐµ™¥á•´Äœ°€‘½É‘•É}¥€¤ì(‘Í…Ù•€ô½Õ¡	½ÍÍ}=É‘•Èèé•Ð €‘½É‘•É}¥€¤ì)½ÕÁ½¹}Ù½Õ¡•É}½¬ €‘Í…Ù•€˜˜€Ô¸ÀÀ€ôôô€¡™±½…Ð¤€‘Í…Ù•´ù‘¥Í½Õ¹Ð€˜˜€‘™¥á•‘}½‘”€ôôô€‘Í…Ù•´ùÙ½Õ¡•É}½‘”€˜˜€Ää¸äÔ€ôôô€¡™±½…Ð¤€‘Í…Ù•´ùÑ½Ñ…°°€Í…Ù•½É‘•È‘ÕÉ…‰±äÁÉ•Í•ÉÙ•ÌÙ½Õ¡•È½‘”°‘¥Í½Õ¹Ð…¹‘¥Í½Õ¹Ñ•Ñ½Ñ…°œ€¤ì)½ÕÁ½¹}Ù½Õ¡•É}½¬ €‘½É‘•É}¥€ôôô€¡¥¹Ð¤€‘‘ˆ´ùÉ•‘•µÁÑ¥½¹Íl¡•­½ÕÐµ™¥á•´Äul½É‘•É}¥t°€É•‘•µÁÑ¥½¸…Õ‘¥ÐÉ½Ü±¥¹­ÌÑ¼Ñ¡”•á…ÐÍ…Ù•½É‘•È¥œ€¤ì)½Õ¡	½ÍÍ}Y½Õ¡•Èèé±¥¹­}É•‘•µÁÑ¥½¹}Ñ½}½É‘•È €¡•­½ÕÐµ™¥á•´Äœ°€‘½É‘•É}¥€¬€äää€¤ì)½ÕÁ½¹}Ù½Õ¡•É}½¬ €‘½É‘•É}¥€ôôô€¡¥¹Ð¤€‘‘ˆ´ùÉ•‘•µÁÑ¥½¹Íl¡•­½ÕÐµ™¥á•´Äul½É‘•É}¥t°€±…Ñ”É•Á±…ä…¹¹½Ð½Ù•ÉÝÉ¥Ñ”„É•‘•µÁÑ¥½¸…Õ‘¥Ð…±É•…‘ä½Ý¹•‰ä…¹½Ñ¡•È½É‘•Èœ€¤ì((‘ÁÕ‰±¥Œ€ô½Õ¡	½ÍÍ}=É‘•ÈèéÁÕ‰±¥}Ù¥•Ü €‘Í…Ù•€¤ì(‘…‘µ¥¸€€ô½Õ¡	½ÍÍ}=É‘•ÈèéÅÕ•Éä …ÉÉ…ä €±½…Ñ¥½¹}¥œ€ôø€Ä€¤€¤ì(‘­‘Ì€€€€ô½Õ¡	½ÍÍ}=É‘•Èèé…Ñ¥Ù•}½É‘•ÉÌ €ÄÀÀ°€Ä€¤ì)½ÕÁ½¹}Ù½Õ¡•É}½¬ €Ô¸ÀÀ€ôôô€‘ÁÕ‰±¥l‘¥Í½Õ¹Ðt€˜˜€‘™¥á•‘}½‘”€ôôô€‘ÁÕ‰±¥lÙ½Õ¡•É}½‘”t€˜˜€Ää¸äÔ€ôôô€‘ÁÕ‰±¥lÑ½Ñ…°t°€ÕÍÑ½µ•È½…‘µ¥¸µÍ…™”½É‘•ÈÁÉ½©•Ñ¥½¸…ÉÉ¥•ÌÑ¡”Á•ÉÍ¥ÍÑ•Ù½Õ¡•ÈÉ•ÍÕ±Ðœ€¤ì)½ÕÁ½¹}Ù½Õ¡•É}½¬ €Ä€ôôô€‘…‘µ¥¹lÑ½Ñ…°t€˜˜€‘™¥á•‘}½‘”€ôôô€‘…‘µ¥¹l¥Ñ•µÌulÁt´ùÙ½Õ¡•É}½‘”€˜˜€Ô¸ÀÀ€ôôô€¡™±½…Ð¤€‘…‘µ¥¹l¥Ñ•µÌulÁt´ù‘¥Í½Õ¹Ð°€…‘µ¥¸½É‘•ÈÅÕ•Éä•áÁ½Í•ÌÑ¡”‘ÕÉ…‰±”Ù½Õ¡•È…¹‘¥Í½Õ¹Ð™¥•±‘Ìœ€¤ì)½ÕÁ½¹}Ù½Õ¡•É}½¬ €Ä€ôôô½Õ¹Ð €‘­‘Ì€¤€˜˜€Ää¸äÔ€ôôô€‘­‘ÍlÁulÑ½Ñ…°t€˜˜€Q•ÍÐ5…¹½ÕÍ œ€ôôô€‘­‘ÍlÁul¥Ñ•µÌulÁul¹…µ”t°€-LÁ…å±½…‘¥ÍÁ±…åÌÑ¡”…ÕÑ¡½É¥Ñ…Ñ¥Ù”‘¥Í½Õ¹Ñ•Ñ½Ñ…°Ý¥Ñ Ñ¡”Í…Ù•½É‘•È±¥¹•Ìœ€¤ì((‘…Ñ¥Ù¥Ñä€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÅÕ•Éä €ÄÀÀ€¤ì(‘™¥á•‘}…Ñ¥Ù¥Ñä€ô…ÉÉ…å}Ù…±Õ•Ì …ÉÉ…å}™¥±Ñ•È €‘…Ñ¥Ù¥Ñä°ÍÑ…Ñ¥Œ™Õ¹Ñ¥½¸€ €‘É½Ü€¤ÕÍ”€ €‘™¥á•‘}¥€¤ìÉ•ÑÕÉ¸€¡¥¹Ð¤€‘É½Ü´ù¥€ôôô€‘™¥á•‘}¥ìô€¤€¤ì)½ÕÁ½¹}Ù½Õ¡•É}½¬ €Ä€ôôô½Õ¹Ð €‘™¥á•‘}…Ñ¥Ù¥Ñä€¤€˜˜€½¹±¥¹”œ€ôôô€‘™¥á•‘}…Ñ¥Ù¥ÑålÁt´ùÉ•‘••µ•‘}¡…¹¹•°€˜˜€Ô¸ÀÀ€ôôô€¡™±½…Ð¤€‘™¥á•‘}…Ñ¥Ù¥ÑålÁt´ù…µ½Õ¹Ñ}…ÁÁ±¥•°€…‘µ¥¸Ù½Õ¡•È…Ñ¥Ù¥ÑäÉ•Á½ÉÑÌÑ¡”É•‘•µÁÑ¥½¸¡…¹¹•°…¹…ÁÁ±¥•…µ½Õ¹Ðœ€¤ì((‘™…¥±•‘}½‘”€ô€%0´œ€¸½Õ¡	½ÍÍ}½ÕÁ½¹}½‘•}AÉ½‰”èé¡•­•‘}Á…ÉÐ €œÔØÜœ°€À€¤€¸€œ´œ€¸½Õ¡	½ÍÍ}½ÕÁ½¹}½‘•}AÉ½‰”èé¡•­•‘}Á…ÉÐ €œàåœ°€Ä€¤ì(‘™…¥±•‘}¥€€€ô€‘‘ˆ´ùÍ••‘}Ù½Õ¡•È €‘™…¥±•‘}½‘”€¤ì(‘™…¥±•‘}É•‘••´€ô½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•‘••´ €‘™…¥±•‘}½‘”°€ÈÀ°€½¹±¥¹”œ°…ÉÉ…ä €¥‘•µÁ½Ñ•¹å}­•äœ€ôø€™…¥±•µ½É‘•ÈµÉ•‘•µÁÑ¥½¸œ€¤€¤ì(‘™…¥±•‘}‘…Ñ„€ô€‘½É‘•É}‘…Ñ„ì(‘™…¥±•‘}‘…Ñ…l¡•­½ÕÑ}­•ät€ôÍÑÉ}É•Á•…Ð €ˆœ°€ØÐ€¤ì(‘™…¥±•‘}‘…Ñ…lÙ½Õ¡•É}½‘”t€ô€‘™…¥±•‘}½‘”ì(‘‘ˆ´ù™…¥±}½É‘•É}¥¹Í•ÉÑÌ€ô€Ôì(‘™…¥±•‘}½É‘•È€ô½Õ¡	½ÍÍ}=É‘•ÈèéÉ•…Ñ” €‘™…¥±•‘}‘…Ñ„°€‘½É‘•É}±¥¹•Ì€¤ì)¥˜€ ¥Í}ÝÁ}•ÉÉ½È €‘™…¥±•‘}½É‘•È€¤€¤ì(%½Õ¡	½ÍÍ}Y½Õ¡•ÈèéÉ•Ù•ÉÑ}É•‘•µÁÑ¥½¸ €™…¥±•µ½É‘•ÈµÉ•‘•µÁÑ¥½¸œ€¤ì)ô)½ÕÁ½¹}Ù½Õ¡•É}½¬ (%¥Í}…ÉÉ…ä €‘™…¥±•‘}É•‘••´€¤€˜˜½ÕÁ½¹}Ù½Õ¡•É}•ÉÉ½È €‘™…¥±•‘}½É‘•È°€‘½Õ¡‰½ÍÍ}‘‰}•ÉÉ½Èœ€¤($$˜˜€¥ÍÍÕ•œ€ôôô€‘‘ˆ´ùÙ½Õ¡•ÉÍl€‘™…¥±•‘}¥ulÍÑ…ÑÕÌt€˜˜€„¥ÍÍ•Ð €‘‘ˆ´ùÉ•‘•µÁÑ¥½¹Íl™…¥±•µ½É‘•ÈµÉ•‘•µÁÑ¥½¸t€¤°($™…¥±•½É‘•ÈÁ•ÉÍ¥ÍÑ•¹”Á±ÕÌ¡•­½ÕÐÉ•Ù•ÉÐ±•…Ù•ÌÑ¡”Ù½Õ¡•ÈÉ•ÕÍ…‰±”…¹Õ¹±¥¹­•œ(¤ì()•¡¼€‰q¸ôôôIMU1Pèì‘Á…ÍÍôÁ…ÍÍ•ƒ
Üì‘™…¥±ô™…¥±•€ôôõq¸ˆì)•á¥Ð €‘™…¥°€ü€Ä€è€À€¤ì(