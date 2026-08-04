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
		if ( preg_match( "/WHERE r.idempotency_key = '([^']+)'/", $query, $match ) ) {
			if ( ! isset( $this->redemptions[ $match[1] ] ) ) {
				return null;
			}
			$redemption = $this->redemptions[ $match[1] ];
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
			$this->snapshot = serialize( array( $this->vouchers, $this->redemptions, $this->orders, $this->items, $this->events ) );
			return 0;
		}
		if ( 'ROLLBACK' === $query && null !== $this->snapshot ) {
			list( $this->vouchers, $this->redemptions, $this->orders, $this->items, $this->events ) = unserialize( $this->snapshot );
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
			$data['order_id'] = isset( $data['order_id'] ) ? (int) $data['order_id'] : 0;
			$this->redemptions[ $key ] = $data;
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
				if ( $row['checkout_key'] === $data['checkout_key'] || ( null !== $data['payment_intent_id'] && $row['payment_intent_id'] === $data['payment_intent_id'] ) ) {
					return false;
				}
			}
			$id = $this->next_order_id++;
			$data = array_merge(
				array(
					'version'                   => 1,
					'eta_minutes'               => 0,
					'acknowledged_at'            => null,
					'accepted_at'                => null,
					'cooking_started_at'         => null,
					'ready_at'                   => null,
				),
				$data,
				array( 'id' => $id )
			);
			$this->orders[ $id ] = $data;
			$this->insert_id = $id;
			return 1;
		}
		return 1;
	}

	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		if ( false !== strpos( $table, 'doughboss_voucher_redemptions' ) ) {
			$key = isset( $where['idempotency_key'] ) ? $where['idempotency_key'] : '';
			if ( ! isset( $this->redemptions[ $key ] ) ) {
				return 0;
			}
			$this->redemptions[ $key ] = array_merge( $this->redemptions[ $key ], $data );
			return 1;
		}
		if ( false !== strpos( $table, 'doughboss_orders' ) ) {
			$id = isset( $where['id'] ) ? (int) $where['id'] : 0;
			if ( ! isset( $this->orders[ $id ] ) ) {
				return 0;
			}
			$this->orders[ $id ] = array_merge( $this->orders[ $id ], $data );
			return 1;
		}
		if ( false !== strpos( $table, 'doughboss_vouchers' ) ) {
			$id = isset( $where['id'] ) ? (int) $where['id'] : 0;
			if ( ! isset( $this->vouchers[ $id ] ) ) {
				return 0;
			}
			if ( $this->zero_next_voucher_update ) {
				$this->zero_next_voucher_update = false;
				return 0;
			}
			foreach ( $where as $field => $value ) {
				if ( (string) ( isset( $this->vouchers[ $id ][ $field ] ) ? $this->vouchers[ $id ][ $field ] : '' ) !== (string) $value ) {
					return 0;
				}
			}
			$this->vouchers[ $id ] = array_merge( $this->vouchers[ $id ], $data );
			return 1;
		}
		return 0;
	}

	public function delete( $table, $where, $where_formats = null ) {
		if ( false !== strpos( $table, 'doughboss_voucher_redemptions' ) ) {
			$key = isset( $where['idempotency_key'] ) ? $where['idempotency_key'] : '';
			if ( ! isset( $this->redemptions[ $key ] ) ) {
				return 0;
			}
			unset( $this->redemptions[ $key ] );
			return 1;
		}
		return 0;
	}
}

require __DIR__ . '/../includes/class-doughboss-settings.php';
require __DIR__ . '/../includes/class-doughboss-coupon-code.php';
require __DIR__ . '/../includes/class-doughboss-voucher.php';
require __DIR__ . '/../includes/class-doughboss-cart.php';
require __DIR__ . '/../includes/class-doughboss-order.php';
require __DIR__ . '/../includes/class-doughboss-rest-controller.php';
require __DIR__ . '/../includes/class-doughboss-stripe.php';

/** Expose check-character construction for stable known-vector tests. */
class DoughBoss_Coupon_Code_Probe extends DoughBoss_Coupon_Code {
	public static function checked_part( $data, $index ) {
		return $data . parent::check_char( $data, $index );
	}
}

/** Deterministic cart double that still executes the production totals method. */
class DoughBoss_Coupon_Cart extends DoughBoss_Cart {
	private $test_lines;
	private $test_voucher;

	public function __construct( array $lines, $voucher = '' ) {
		$this->test_lines   = $lines;
		$this->test_voucher = $voucher;
	}

	public function get_lines() {
		return $this->test_lines;
	}

	public function get_voucher_code() {
		return $this->test_voucher;
	}
}

/** Cart double that captures the canonical code persisted by the REST action. */
class DoughBoss_Canonical_Cart extends DoughBoss_Cart {
	public $stored_voucher = '';
	private $subtotal;

	public function __construct( $subtotal ) {
		$this->subtotal = (float) $subtotal;
	}

	public function totals( $order_type = 'pickup' ) {
		unset( $order_type );
		return array(
			'subtotal'      => $this->subtotal,
			'discount'      => 0.0,
			'delivery_fee'  => 0.0,
			'tax'           => 0.0,
			'total'         => $this->subtotal,
			'voucher_code'  => $this->stored_voucher,
			'item_count'    => 1,
		);
	}

	public function set_voucher_code( $code ) {
		$this->stored_voucher = (string) $code;
	}

	public function get_voucher_code() {
		return $this->stored_voucher;
	}

	public function to_array( $order_type = 'pickup' ) {
		return array(
			'items'  => array(),
			'totals' => $this->totals( $order_type ),
		);
	}
}

$pass = 0;
$fail = 0;
function coupon_voucher_ok( $condition, $label ) {
	global $pass, $fail;
	if ( $condition ) {
		++$pass;
		echo "  ok   {$label}\n";
	} else {
		++$fail;
		echo "  FAIL {$label}\n";
	}
}
function coupon_voucher_section( $label ) {
	echo "\n== {$label} ==\n";
}
function coupon_voucher_error( $value, $code ) {
	return is_wp_error( $value ) && $code === $value->get_error_code();
}
function coupon_voucher_method_source( $class, $method ) {
	$reflection = new ReflectionMethod( $class, $method );
	$lines      = file( $reflection->getFileName() );
	return implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
}

echo "=== DoughBoss coupon + voucher behavioral contract ===\n";
$db = new DoughBoss_Coupon_Voucher_DB();
$GLOBALS['wpdb'] = $db;
update_option( 'doughboss_db_version', '1.18.0' );

coupon_voucher_section( 'Code generation, validation and normalization' );
$generated = DoughBoss_Coupon_Code::generate();
coupon_voucher_ok( 1 === preg_match( '/^[A-HJ-KM-NP-Z2-9]{4}-[A-HJ-KM-NP-Z2-9]{4}$/', $generated ), 'generated code uses two unambiguous four-character groups' );
coupon_voucher_ok( DoughBoss_Coupon_Code::validate( $generated ), 'generated check characters validate' );
$replacement = 'A' === substr( $generated, -1 ) ? 'B' : 'A';
$mistyped    = substr( $generated, 0, -1 ) . $replacement;
$lookups     = $db->voucher_lookups;
$rejected    = DoughBoss_Voucher::redeem( $mistyped, 20, 'online', array( 'idempotency_key' => 'bad-check' ) );
coupon_voucher_ok( coupon_voucher_error( $rejected, 'doughboss_voucher_invalid' ) && $lookups === $db->voucher_lookups, 'bad check character is rejected before any voucher lookup' );
coupon_voucher_ok( DoughBoss_Coupon_Code::validate( 'LEGACYCODE' ) && DoughBoss_Coupon_Code::validate( 'SNOW-LEGACY123' ), 'legacy/unknown formats remain database-compatible' );
coupon_voucher_ok( 'QQ777-AB' === DoughBoss_Coupon_Code::normalize( "  o0i1l -- a!b  " ), 'normalization folds ambiguous glyphs and stray separators deterministically' );

$known_body = DoughBoss_Coupon_Code_Probe::checked_part( 'AQ2', 0 ) . '-' . DoughBoss_Coupon_Code_Probe::checked_part( 'B73', 1 );
$known_code = 'DB-' . $known_body;
$known_id   = $db->seed_voucher( $known_code );
$sloppy     = str_replace( array( 'Q', '7' ), array( 'O', 'I' ), strtolower( $known_code ) );
$found      = DoughBoss_Voucher::find_by_code( '  ' . $sloppy . '  ' );
coupon_voucher_ok( $found && $known_id === (int) $found->id && DoughBoss_Coupon_Code::validate( $sloppy ), 'case, whitespace and O/I glyph mistakes recover to the canonical stored code' );

$prefixed_code = 'SNOW110025-' . $known_body;
$prefixed_id   = $db->seed_voucher( $prefixed_code );
$prefixed      = DoughBoss_Voucher::find_by_code( ' snow110025-' . $known_body . ' ' );
coupon_voucher_ok( $prefixed && $prefixed_id === (int) $prefixed->id, 'exact lookup preserves legacy prefixes containing O, 0 and 1' );

$canonical_cart       = new DoughBoss_Canonical_Cart( 24.95 );
$canonical_controller = new DoughBoss_REST_Controller( $canonical_cart );
$canonical_response   = $canonical_controller->cart_apply_voucher(
	new WP_REST_Request(
		array(
			'code'       => '  ' . $sloppy . '  ',
			'order_type' => 'pickup',
		)
	)
);
coupon_voucher_ok(
	$canonical_response instanceof WP_REST_Response && $known_code === $canonical_cart->stored_voucher,
	'cart apply persists the canonical database code after recoverable shopper typos'
);

$canonical_controller->register_routes();
$redeem_route_args = isset( $GLOBALS['__db_rest_args'][ DOUGHBOSS_REST_NAMESPACE . '/voucher/redeem' ] ) ? $GLOBALS['__db_rest_args'][ DOUGHBOSS_REST_NAMESPACE . '/voucher/redeem' ] : array();
$redeem_permission = isset( $redeem_route_args['permission_callback'] ) ? $redeem_route_args['permission_callback'] : null;
$GLOBALS['__db_caps_override'] = array();
$public_redeem_permission = is_callable( $redeem_permission ) ? call_user_func( $redeem_permission ) : false;
$GLOBALS['__db_caps_override'] = array( 'manage_doughboss' );
$manager_redeem_permission = is_callable( $redeem_permission ) ? call_user_func( $redeem_permission ) : false;
$GLOBALS['__db_caps_override'] = null;
coupon_voucher_ok(
	is_array( $redeem_permission ) && 'verify_manage' === $redeem_permission[1]
		&& coupon_voucher_error( $public_redeem_permission, 'doughboss_forbidden' ) && true === $manager_redeem_permission,
	'legacy direct redemption route rejects storefront users and admits managers only'
);

coupon_voucher_section( 'Issuance and eligibility failures' );
coupon_voucher_ok( coupon_voucher_error( DoughBoss_Voucher::issue( array( 'type' => 'bogus', 'value' => 5 ) ), 'doughboss_voucher_type' ), 'issuance rejects an unsupported discount type' );
coupon_voucher_ok( coupon_voucher_error( DoughBoss_Voucher::issue( array( 'type' => 'amount', 'value' => 0 ) ), 'doughboss_voucher_value' ), 'issuance rejects a zero-value voucher' );
$percent_issue = DoughBoss_Voucher::issue( array( 'type' => 'percent', 'value' => 500, 'prefix' => 'TEST' ) );
$percent_row   = is_array( $percent_issue ) ? $db->vouchers[ $percent_issue['id'] ] : array();
coupon_voucher_ok( is_array( $percent_issue ) && 100.0 === (float) $percent_row['value'] && DoughBoss_Coupon_Code::validate( $percent_issue['code'] ), 'percentage issuance clamps at 100% and emits a valid checked code' );

$eligible = (object) array(
	'id' => 90, 'status' => 'issued', 'scope' => 'both', 'valid_from' => null, 'valid_to' => null,
	'min_spend' => 20, 'type' => 'amount', 'value' => 50, 'location_id' => 0,
);
$minimum = DoughBoss_Voucher::evaluate( $eligible, 19.99, 'online' );
coupon_voucher_ok( ! $minimum['valid'] && 'min_spend' === $minimum['reason'], 'minimum-spend failure is distinguished for customer guidance' );
$eligible->valid_from = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) );
$eligible->valid_to   = $eligible->valid_from;
$minimum_boundary = DoughBoss_Voucher::evaluate( $eligible, 20.00, 'online' );
coupon_voucher_ok( $minimum_boundary['valid'] && 20.00 === $minimum_boundary['amount'], 'exact minimum-spend and validity-window boundaries remain eligible' );
$eligible->valid_from = null;
$eligible->valid_to   = null;
$eligible->scope = 'instore';
coupon_voucher_ok( ! DoughBoss_Voucher::evaluate( $eligible, 25, 'online' )['valid'], 'channel mismatch is ineligible' );
$eligible->scope = 'both';
$eligible->valid_from = '2099-01-01 00:00:00';
coupon_voucher_ok( ! DoughBoss_Voucher::evaluate( $eligible, 25, 'online' )['valid'], 'not-yet-valid voucher is ineligible' );
$eligible->valid_from = null;
$eligible->valid_to = '2000-01-01 00:00:00';
coupon_voucher_ok( ! DoughBoss_Voucher::evaluate( $eligible, 25, 'online' )['valid'], 'expired voucher is ineligible' );
$eligible->valid_to = null;
$eligible->status = 'redeemed';
coupon_voucher_ok( ! DoughBoss_Voucher::evaluate( $eligible, 25, 'online' )['valid'], 'non-issued voucher is ineligible' );
coupon_voucher_ok( DoughBoss_Voucher::eligible_student_email( 'student@campus.edu.au' ) && DoughBoss_Voucher::eligible_student_email( 'student@college.edu' ), 'education domains accepted for student eligibility' );
coupon_voucher_ok( ! DoughBoss_Voucher::eligible_student_email( 'student@edu.au.example.com' ) && ! DoughBoss_Voucher::eligible_student_email( 'not-an-email' ), 'lookalike and malformed student emails are rejected' );
$mismatch_claim = DoughBoss_Voucher::claim(
	'dough5',
	array( 'customer_email' => 'student@campus.edu.au', 'customer_email_confirmation' => 'other@campus.edu.au' )
);
coupon_voucher_ok( coupon_voucher_error( $mismatch_claim, 'doughboss_student_email' ), 'student claim rejects a mismatched confirmation before allocation' );

coupon_voucher_section( 'Fixed/percent totals and GST rounding' );
$fixed_eval = (object) array(
	'id' => 91, 'status' => 'issued', 'scope' => 'both', 'valid_from' => null, 'valid_to' => null,
	'min_spend' => 0, 'type' => 'amount', 'value' => 50, 'location_id' => 0,
);
$fixed_amount = DoughBoss_Voucher::evaluate( $fixed_eval, 24.95, 'online' );
$fixed_eval->type = 'percent';
$fixed_eval->value = 10;
$percent_amount = DoughBoss_Voucher::evaluate( $fixed_eval, 24.95, 'online' );
coupon_voucher_ok( $fixed_amount['valid'] && 24.95 === $fixed_amount['amount'], 'fixed discount is capped at the goods subtotal' );
coupon_voucher_ok( $percent_amount['valid'] && 2.50 === $percent_amount['amount'], 'percentage discount rounds 2.495 to AUD cents' );

$fixed_code = 'FIX-' . DoughBoss_Coupon_Code_Probe::checked_part( 'CDE', 0 ) . '-' . DoughBoss_Coupon_Code_Probe::checked_part( 'FGH', 1 );
$fixed_id   = $db->seed_voucher( $fixed_code, array( 'value' => 5.00 ) );
$lines      = array( array( 'name' => 'Test Manoush', 'quantity' => 1, 'unit_price' => 24.95, 'line_total' => 24.95 ) );
update_option( DoughBoss_Settings::OPTION_KEY, array( 'tax_rate' => 10, 'gst_inclusive' => 1, 'delivery_fee' => 2.50 ) );
$inclusive = ( new DoughBoss_Coupon_Cart( $lines, $fixed_code ) )->totals( 'pickup' );
coupon_voucher_ok(
	5.00 === $inclusive['discount'] && 19.95 === $inclusive['total'] && 1.81 === $inclusive['tax'] && $fixed_code === $inclusive['voucher_code'],
	'fixed voucher produces GST-inclusive discounted total and embedded GST rounding'
);
$no_voucher = ( new DoughBoss_Coupon_Cart( $lines ) )->totals( 'delivery' );
coupon_voucher_ok( 0.0 === $no_voucher['discount'] && 27.45 === $no_voucher['total'] && 2.50 === $no_voucher['tax'] && 1 === $no_voucher['item_count'], 'no-voucher GST-inclusive delivery baseline keeps fee, tax and item count authoritative' );
$unknown_voucher = ( new DoughBoss_Coupon_Cart( $lines, 'UNKNOWN-CODE' ) )->totals( 'pickup' );
coupon_voucher_ok( 0.0 === $unknown_voucher['discount'] && '' === $unknown_voucher['voucher_code'] && 24.95 === $unknown_voucher['total'], 'invalid held code silently drops from server totals without a discount' );

$percent_code = 'PCT-' . DoughBoss_Coupon_Code_Probe::checked_part( 'JKM', 0 ) . '-' . DoughBoss_Coupon_Code_Probe::checked_part( 'NPQ', 1 );
$db->seed_voucher( $percent_code, array( 'type' => 'percent', 'value' => 10.00 ) );
update_option( DoughBoss_Settings::OPTION_KEY, array( 'tax_rate' => 10, 'gst_inclusive' => 0, 'delivery_fee' => 2.50 ) );
$exclusive = ( new DoughBoss_Coupon_Cart( $lines, $percent_code ) )->totals( 'delivery' );
coupon_voucher_ok(
	2.50 === $exclusive['discount'] && 2.25 === $exclusive['tax'] && 2.50 === $exclusive['delivery_fee'] && 27.20 === $exclusive['total'],
	'percent voucher produces GST-exclusive tax, delivery and final-total rounding'
);

coupon_voucher_section( 'Exclusive checkout reservation lease' );
$lease_code = 'LEASE-' . DoughBoss_Coupon_Code_Probe::checked_part( 'AB2', 0 ) . '-' . DoughBoss_Coupon_Code_Probe::checked_part( 'CD3', 1 );
$lease_id   = $db->seed_voucher( $lease_code );
$lease_a    = hash( 'sha256', 'stripe-checkout-a' );
$lease_b    = hash( 'sha256', 'stripe-checkout-b' );
$lease_c    = hash( 'sha256', 'stripe-checkout-c' );
$bad_lease  = DoughBoss_Voucher::reserve( $lease_code, 24.95, 'online', 'browser-controlled-key' );
coupon_voucher_ok( coupon_voucher_error( $bad_lease, 'doughboss_voucher_reservation_key' ), 'reservation rejects a non-SHA-256 checkout key' );
$db->deny_next_voucher_lock = true;
$busy_lease = DoughBoss_Voucher::reserve( $lease_code, 24.95, 'online', $lease_a );
coupon_voucher_ok( coupon_voucher_error( $busy_lease, 'doughboss_voucher_busy' ) && empty( $db->vouchers[ $lease_id ]['meta'] ), 'reservation fails closed when its per-voucher database lock is unavailable' );
$db->zero_next_voucher_update = true;
$zero_lease = DoughBoss_Voucher::reserve( $lease_code, 24.95, 'online', $lease_a );
coupon_voucher_ok( coupon_voucher_error( $zero_lease, 'doughboss_voucher_reservation_storage' ) && empty( $db->vouchers[ $lease_id ]['meta'] ), 'zero-row reservation CAS cannot report a lease that was never stored' );
$reserved_a = DoughBoss_Voucher::reserve( $lease_code, 24.95, 'online', $lease_a );
$replayed_a = DoughBoss_Voucher::reserve( $lease_code, 24.95, 'online', $lease_a, 3600 );
coupon_voucher_ok(
	is_array( $reserved_a ) && is_array( $replayed_a ) && $lease_a === $replayed_a['reservation_key']
		&& $replayed_a['expires_at'] >= $reserved_a['expires_at'] + 899,
	'same-owner retry renews its lease before a newly provisioned Stripe Session'
);
$blocked_b = DoughBoss_Voucher::reserve( $lease_code, 24.95, 'online', $lease_b );
coupon_voucher_ok( coupon_voucher_error( $blocked_b, 'doughboss_voucher_reserved' ), 'second checkout cannot price a session while the first lease is active' );
$wrong_release = DoughBoss_Voucher::release_reservation( $lease_code, $lease_b );
$still_blocked = DoughBoss_Voucher::reserve( $lease_code, 24.95, 'online', $lease_b );
coupon_voucher_ok( true === $wrong_release && coupon_voucher_error( $still_blocked, 'doughboss_voucher_reserved' ), 'non-owner release cannot clear a newer checkout lease' );
$db->zero_next_voucher_update = true;
$zero_release = DoughBoss_Voucher::release_reservation( $lease_code, $lease_a );
$blocked_after_zero_release = DoughBoss_Voucher::reserve( $lease_code, 24.95, 'online', $lease_b );
coupon_voucher_ok( ! $zero_release && coupon_voucher_error( $blocked_after_zero_release, 'doughboss_voucher_reserved' ), 'zero-row release reports failure while the matching lease remains stored' );
$released_a = DoughBoss_Voucher::release_reservation( $lease_code, $lease_a );
$reserved_b = DoughBoss_Voucher::reserve( $lease_code, 24.95, 'online', $lease_b );
coupon_voucher_ok( $released_a && is_array( $reserved_b ) && $lease_b === $reserved_b['reservation_key'], 'owner release after failed payment preparation permits another checkout' );

$lease_meta = json_decode( (string) $db->vouchers[ $lease_id ]['meta'], true );
$lease_meta[ DoughBoss_Voucher::RESERVATION_META_KEY ]['expires_at'] = time() - 1;
$db->vouchers[ $lease_id ]['meta'] = wp_json_encode( $lease_meta );
$reclaimed_c = DoughBoss_Voucher::reserve( $lease_code, 24.95, 'online', $lease_c );
coupon_voucher_ok( is_array( $reclaimed_c ) && $lease_c === $reclaimed_c['reservation_key'], 'expired abandoned lease is reclaimed by a later checkout' );

$void_code = 'VOID-' . DoughBoss_Coupon_Code_Probe::checked_part( 'DE4', 0 ) . '-' . DoughBoss_Coupon_Code_Probe::checked_part( 'FG5', 1 );
$void_id   = $db->seed_voucher( $void_code );
$void_key  = hash( 'sha256', 'reserved-void' );
DoughBoss_Voucher::reserve( $void_code, 20, 'online', $void_key );
$active_void = DoughBoss_Voucher::void( $void_id );
$void_meta = json_decode( (string) $db->vouchers[ $void_id ]['meta'], true );
$void_meta[ DoughBoss_Voucher::RESERVATION_META_KEY ]['expires_at'] = time() - 1;
$db->vouchers[ $void_id ]['meta'] = wp_json_encode( $void_meta );
$expired_void = DoughBoss_Voucher::void( $void_id );
coupon_voucher_ok( ! $active_void && $expired_void && 'voided' === $db->vouchers[ $void_id ]['status'], 'void refuses an active checkout lease but succeeds after that lease expires' );

coupon_voucher_section( 'Stripe reservation and recovery wiring' );
$payment_intent_source = coupon_voucher_method_source( 'DoughBoss_REST_Controller', 'create_payment_intent' );
$reserve_position       = strpos( $payment_intent_source, 'DoughBoss_Voucher::reserve(' );
$stripe_position        = strpos( $payment_intent_source, 'DoughBoss_Stripe::create_checkout_session(' );
coupon_voucher_ok(
	false !== $reserve_position && false !== $stripe_position && $reserve_position < $stripe_position
		&& false !== strpos( $payment_intent_source, '$reserved_voucher_code ? $reserved_voucher_code : $priced_voucher_code' )
		&& false !== strpos( $payment_intent_source, "'voucher_reservation_key'" )
		&& false !== strpos( $payment_intent_source, '$voucher_reservation_key' ),
	'payment preparation leases before Stripe and snapshots the canonical code plus owner key'
);
$snapshot_error_start = strpos( $payment_intent_source, 'if ( is_wp_error( $snapshot ) )' );
$snapshot_error_end   = false !== $snapshot_error_start ? strpos( $payment_intent_source, 'return $snapshot;', $snapshot_error_start ) : false;
$snapshot_error_block = false !== $snapshot_error_start && false !== $snapshot_error_end ? substr( $payment_intent_source, $snapshot_error_start, $snapshot_error_end - $snapshot_error_start ) : '';
coupon_voucher_ok(
	'' !== $snapshot_error_block && false === strpos( $snapshot_error_block, 'release_reservation' ),
	'immutable snapshot conflict never releases a lease while its old Stripe Session may remain payable'
);

$checkout_source = coupon_voucher_method_source( 'DoughBoss_REST_Controller', 'checkout' );
$recovery_source = coupon_voucher_method_source( 'DoughBoss_REST_Controller', 'recover_stripe_order' );
coupon_voucher_ok(
	false !== strpos( $checkout_source, "'reservation_key'" ) && false !== strpos( $checkout_source, '$voucher_reservation_key' )
		&& false !== strpos( $checkout_source, 'revert_redemption( $voucher_idem, $voucher_reservation_key, $payment_intent_id )' )
		&& false !== strpos( $checkout_source, "'unpaid' === \$payment_status || '' === \$voucher_reservation_key" )
		&& false !== strpos( $recovery_source, "'reservation_key'" ) && false !== strpos( $recovery_source, '$voucher_reservation_key' )
		&& false !== strpos( $recovery_source, "'' !== \$voucher_idem && '' === \$voucher_reservation_key" )
		&& false !== strpos( $recovery_source, 'revert_redemption( $voucher_idem, $voucher_reservation_key, $pi_id )' ),
	'browser and webhook recovery compensate only unpaid or unreserved failures, never a paid reserved redemption'
);
$paid_replay_position = strpos( $checkout_source, 'stripe_paid_order_replay( $request )' );
$rate_limit_position  = strpos( $checkout_source, "rate_limited( 'checkout'" );
$live_totals_position = strpos( $checkout_source, '$this->cart->totals( $order_type )' );
$paid_replay_source   = coupon_voucher_method_source( 'DoughBoss_REST_Controller', 'stripe_paid_order_replay' );
coupon_voucher_ok(
	false !== $rate_limit_position && false !== $paid_replay_position && $rate_limit_position < $paid_replay_position
		&& false !== $live_totals_position && $paid_replay_position < $live_totals_position
		&& false !== strpos( $paid_replay_source, 'DoughBoss_Stripe::retrieve_checkout_payment' )
		&& false !== strpos( $paid_replay_source, 'DoughBoss_Checkout_Snapshots::find' )
		&& false !== strpos( $paid_replay_source, 'find_id_by_payment_intent' ),
	'rate-limited webhook-first browser return replays the verified paid order before redeemed-voucher live totals'
);

$stripe_session_source = coupon_voucher_method_source( 'DoughBoss_Stripe', 'create_checkout_session' );
coupon_voucher_ok(
	1 === preg_match( "/'expires_at'\s*=>\s*time\(\) \+ 1860/", $stripe_session_source )
		&& DoughBoss_Voucher::RESERVATION_TTL_SECONDS >= 2460,
	'voucher lease outlives the 31-minute Stripe Session by at least ten minutes'
);

$storefront_source = file_get_contents( __DIR__ . '/../public/js/doughboss.js' );
$cancel_start      = strpos( $storefront_source, "else if (stripeReturnState.get('doughboss_stripe_cancel') === '1')" );
$cancel_end        = false !== $cancel_start ? strpos( $storefront_source, "window.sessionStorage.removeItem('doughbossStripePending')", $cancel_start ) : false;
$cancel_source     = false !== $cancel_start && false !== $cancel_end ? substr( $storefront_source, $cancel_start, $cancel_end - $cancel_start ) : '';
coupon_voucher_ok(
	false !== strpos( $cancel_source, 'paymentAttemptKey = cancelledStripePending.payload.payment_attempt_key;' )
		&& false !== strpos( $cancel_source, 'stripeCancelledPayload = Object.assign({}, cancelledStripePending.payload);' )
		&& false !== strpos( $cancel_source, 'input.readOnly = true;' )
		&& false !== strpos( $storefront_source, 'stripeCancelledPayload ? Object.assign({}, stripeCancelledPayload)' ),
	'cancelled Stripe retry restores and freezes the exact immutable payload before clearing browser state'
);
coupon_voucher_ok(
	false !== strpos( $storefront_source, '< 45 * 60 * 1000' )
		&& false !== strpos( $storefront_source, '}).catch(stripePaidReturnFail);' )
		&& false !== strpos( $storefront_source, 'function stripePaidReturnFail()' ),
	'paid Stripe return remains recoverable through the lease window and can never unlock a second charge on uncertainty'
);

$redeem_source = coupon_voucher_method_source( 'DoughBoss_Voucher', 'redeem' );
$link_source   = coupon_voucher_method_source( 'DoughBoss_Voucher', 'link_redemption_to_order' );
$revert_source = coupon_voucher_method_source( 'DoughBoss_Voucher', 'revert_redemption' );
$redeem_lock_position = strpos( $redeem_source, 'acquire_voucher_lock' );
$replay_read_position = strpos( $redeem_source, 'redemption_by_key' );
coupon_voucher_ok(
	false !== $redeem_lock_position && false !== $replay_read_position && $redeem_lock_position < $replay_read_position
		&& false !== strpos( $link_source, 'acquire_voucher_lock' ) && false !== strpos( $link_source, 'FOR UPDATE' )
		&& false !== strpos( $revert_source, 'acquire_voucher_lock' ) && false !== strpos( $revert_source, 'FOR UPDATE' )
		&& false !== strpos( $revert_source, 'AND order_id = 0' ),
	'redeem replay, audit link and rollback serialize on one voucher and lock the audit row'
);

coupon_voucher_section( 'Atomic redemption, replay, reuse and rollback' );
$first = DoughBoss_Voucher::redeem( $fixed_code, 24.95, 'online', array( 'idempotency_key' => 'checkout-fixed-1' ) );
coupon_voucher_ok( is_array( $first ) && 5.00 === $first['amount'] && 'redeemed' === $db->vouchers[ $fixed_id ]['status'], 'first redemption atomically consumes the issued voucher' );
$fixed_redemption_count = count( $db->redemptions );
$replay = DoughBoss_Voucher::redeem( $fixed_code, 999.00, 'online', array( 'idempotency_key' => 'checkout-fixed-1' ) );
coupon_voucher_ok( $replay === $first && $fixed_redemption_count === count( $db->redemptions ), 'same idempotency key replays the recorded amount without a second redemption' );
$reuse = DoughBoss_Voucher::redeem( $fixed_code, 24.95, 'online', array( 'idempotency_key' => 'checkout-fixed-2' ) );
coupon_voucher_ok( coupon_voucher_error( $reuse, 'doughboss_voucher_invalid' ) && $fixed_redemption_count === count( $db->redemptions ), 'used voucher cannot be consumed again under a different key' );
$other_code = 'OTHER-' . DoughBoss_Coupon_Code_Probe::checked_part( 'GH6', 0 ) . '-' . DoughBoss_Coupon_Code_Probe::checked_part( 'JK7', 1 );
$other_id   = $db->seed_voucher( $other_code );
$cross_code_replay = DoughBoss_Voucher::redeem( $other_code, 24.95, 'online', array( 'idempotency_key' => 'checkout-fixed-1' ) );
coupon_voucher_ok( coupon_voucher_error( $cross_code_replay, 'doughboss_voucher_invalid' ) && 'issued' === $db->vouchers[ $other_id ]['status'], 'idempotency key cannot replay a different voucher code' );

$race_code = 'RACE-' . DoughBoss_Coupon_Code_Probe::checked_part( 'BCD', 0 ) . '-' . DoughBoss_Coupon_Code_Probe::checked_part( 'EFG', 1 );
$race_id   = $db->seed_voucher( $race_code );
$db->claim_race_voucher_id = $race_id;
$race_loser = DoughBoss_Voucher::redeem( $race_code, 20, 'online', array( 'idempotency_key' => 'race-loser' ) );
coupon_voucher_ok( coupon_voucher_error( $race_loser, 'doughboss_voucher_used' ) && ! isset( $db->redemptions['race-loser'] ), 'conditional claim rejects a worker that loses the issued-to-redeemed database race' );

$audit_code = 'AUD-' . DoughBoss_Coupon_Code_Probe::checked_part( 'RST', 0 ) . '-' . DoughBoss_Coupon_Code_Probe::checked_part( 'UVW', 1 );
$audit_id   = $db->seed_voucher( $audit_code );
$db->fail_next_redemption_insert = true;
$audit_fail = DoughBoss_Voucher::redeem( $audit_code, 20, 'online', array( 'idempotency_key' => 'audit-fail' ) );
coupon_voucher_ok( coupon_voucher_error( $audit_fail, 'doughboss_voucher_audit' ) && 'issued' === $db->vouchers[ $audit_id ]['status'] && ! isset( $db->redemptions['audit-fail'] ), 'mandatory-audit failure rolls the voucher claim back to issued' );

$revert_code = 'REV-' . DoughBoss_Coupon_Code_Probe::checked_part( 'XYZ', 0 ) . '-' . DoughBoss_Coupon_Code_Probe::checked_part( '234', 1 );
$revert_id   = $db->seed_voucher( $revert_code );
$before_revert = DoughBoss_Voucher::redeem( $revert_code, 20, 'online', array( 'idempotency_key' => 'order-revert-1' ) );
DoughBoss_Voucher::revert_redemption( 'order-revert-1' );
$after_revert = DoughBoss_Voucher::redeem( $revert_code, 20, 'online', array( 'idempotency_key' => 'order-revert-1' ) );
coupon_voucher_ok( is_array( $before_revert ) && is_array( $after_revert ) && 'redeemed' === $db->vouchers[ $revert_id ]['status'] && isset( $db->redemptions['order-revert-1'] ), 'revert winning before same-key replay forces a fresh redemption instead of returning a deleted audit' );

$paid_failure_code = 'PAID-' . DoughBoss_Coupon_Code_Probe::checked_part( 'GH8', 0 ) . '-' . DoughBoss_Coupon_Code_Probe::checked_part( 'JK9', 1 );
$paid_failure_id   = $db->seed_voucher( $paid_failure_code );
$paid_failure_key  = hash( 'sha256', 'paid-reserved-order-failure' );
DoughBoss_Voucher::reserve( $paid_failure_code, 20, 'online', $paid_failure_key );
$paid_failure_first = DoughBoss_Voucher::redeem( $paid_failure_code, 20, 'online', array( 'idempotency_key' => 'paid-reserved-failure', 'reservation_key' => $paid_failure_key ) );
// A verified paid order-insert failure deliberately does not call revert_redemption().
$paid_failure_retry = DoughBoss_Voucher::redeem( $paid_failure_code, 999, 'online', array( 'idempotency_key' => 'paid-reserved-failure', 'reservation_key' => $paid_failure_key ) );
$paid_failure_rival = DoughBoss_Voucher::reserve( $paid_failure_code, 20, 'online', hash( 'sha256', 'paid-reserved-rival' ) );
coupon_voucher_ok(
	is_array( $paid_failure_first ) && $paid_failure_retry === $paid_failure_first
		&& 'redeemed' === $db->vouchers[ $paid_failure_id ]['status']
		&& isset( $db->redemptions['paid-reserved-failure'] ) && 0 === (int) $db->redemptions['paid-reserved-failure']['order_id']
		&& coupon_voucher_error( $paid_failure_rival, 'doughboss_voucher_invalid' ),
	'paid reserved order failure stays consumed and replayable while every rival checkout remains blocked'
);

$owner_code = 'OWNER-' . DoughBoss_Coupon_Code_Probe::checked_part( 'KM8', 0 ) . '-' . DoughBoss_Coupon_Code_Probe::checked_part( 'NP9', 1 );
$owner_id   = $db->seed_voucher( $owner_code );
$owner_key  = hash( 'sha256', 'reserved-owner-checkout' );
$rival_key  = hash( 'sha256', 'reserved-rival-checkout' );
DoughBoss_Voucher::reserve( $owner_code, 20, 'online', $owner_key );
$reserved_scan = DoughBoss_Voucher::redeem( $owner_code, 20, 'instore', array( 'idempotency_key' => 'reserved-scan' ) );
$reserved_wrong = DoughBoss_Voucher::redeem( $owner_code, 20, 'online', array( 'idempotency_key' => 'reserved-wrong', 'reservation_key' => $rival_key ) );
$reserved_owner = DoughBoss_Voucher::redeem( $owner_code, 20, 'online', array( 'idempotency_key' => 'reserved-owner', 'reservation_key' => $owner_key ) );
$owner_meta_after = json_decode( (string) $db->vouchers[ $owner_id ]['meta'], true );
coupon_voucher_ok( coupon_voucher_error( $reserved_scan, 'doughboss_voucher_reserved' ) && coupon_voucher_error( $reserved_wrong, 'doughboss_voucher_reserved' ), 'active checkout lease rejects staff/no-key and rival-key redemption' );
coupon_voucher_ok( is_array( $reserved_owner ) && 'redeemed' === $db->vouchers[ $owner_id ]['status'] && empty( $owner_meta_after[ DoughBoss_Voucher::RESERVATION_META_KEY ] ), 'lease owner redeems once and atomically clears reservation metadata' );
$reserved_owner_replay = DoughBoss_Voucher::redeem( $owner_code, 999, 'online', array( 'idempotency_key' => 'reserved-owner', 'reservation_key' => $owner_key ) );
coupon_voucher_ok( $reserved_owner_replay === $reserved_owner, 'reserved checkout retry replays its immutable redemption result' );

DoughBoss_Voucher::revert_redemption( 'reserved-owner', $owner_key );
$restored_meta = json_decode( (string) $db->vouchers[ $owner_id ]['meta'], true );
$rival_after_revert = DoughBoss_Voucher::reserve( $owner_code, 20, 'online', $rival_key );
coupon_voucher_ok(
	'issued' === $db->vouchers[ $owner_id ]['status'] && ! isset( $db->redemptions['reserved-owner'] )
		&& $owner_key === $restored_meta[ DoughBoss_Voucher::RESERVATION_META_KEY ]['key']
		&& coupon_voucher_error( $rival_after_revert, 'doughboss_voucher_reserved' ),
	'failed-order compensation restores the same owner lease and blocks a rival checkout'
);
$owner_retry = DoughBoss_Voucher::redeem( $owner_code, 20, 'online', array( 'idempotency_key' => 'reserved-owner', 'reservation_key' => $owner_key ) );
$winning_pi  = 'pi_test_winning_order_12345';
$db->orders[777] = array( 'id' => 777, 'checkout_key' => $owner_key, 'payment_intent_id' => $winning_pi );
DoughBoss_Voucher::revert_redemption( 'reserved-owner', $owner_key, $winning_pi );
DoughBoss_Voucher::link_redemption_to_order( 'reserved-owner', 777 );
DoughBoss_Voucher::revert_redemption( 'reserved-owner', $owner_key, $winning_pi );
coupon_voucher_ok(
	is_array( $owner_retry ) && 'redeemed' === $db->vouchers[ $owner_id ]['status']
		&& 777 === (int) $db->redemptions['reserved-owner']['order_id'],
	'winning paid order is atomically linked and a late failed worker cannot reissue its voucher'
);
unset( $db->orders[777] );

$lease_audit_code = 'META-' . DoughBoss_Coupon_Code_Probe::checked_part( 'QR2', 0 ) . '-' . DoughBoss_Coupon_Code_Probe::checked_part( 'ST3', 1 );
$lease_audit_id   = $db->seed_voucher( $lease_audit_code, array( 'meta' => wp_json_encode( array( 'campaign_note' => 'keep-me' ) ) ) );
$lease_audit_key  = hash( 'sha256', 'lease-audit-owner' );
DoughBoss_Voucher::reserve( $lease_audit_code, 20, 'online', $lease_audit_key );
$db->fail_next_redemption_insert = true;
$lease_audit_fail = DoughBoss_Voucher::redeem( $lease_audit_code, 20, 'online', array( 'idempotency_key' => 'lease-audit-fail', 'reservation_key' => $lease_audit_key ) );
$lease_audit_meta = json_decode( (string) $db->vouchers[ $lease_audit_id ]['meta'], true );
coupon_voucher_ok(
	coupon_voucher_error( $lease_audit_fail, 'doughboss_voucher_audit' ) && 'issued' === $db->vouchers[ $lease_audit_id ]['status']
		&& 'keep-me' === $lease_audit_meta['campaign_note'] && $lease_audit_key === $lease_audit_meta[ DoughBoss_Voucher::RESERVATION_META_KEY ]['key'],
	'audit insertion failure restores the exact pre-claim metadata and owner lease'
);

$expired_scan_code = 'OLD-' . DoughBoss_Coupon_Code_Probe::checked_part( 'UV4', 0 ) . '-' . DoughBoss_Coupon_Code_Probe::checked_part( 'WX5', 1 );
$expired_scan_id   = $db->seed_voucher( $expired_scan_code );
$expired_scan_key  = hash( 'sha256', 'expired-scan-owner' );
DoughBoss_Voucher::reserve( $expired_scan_code, 20, 'online', $expired_scan_key );
$expired_scan_meta = json_decode( (string) $db->vouchers[ $expired_scan_id ]['meta'], true );
$expired_scan_meta[ DoughBoss_Voucher::RESERVATION_META_KEY ]['expires_at'] = time() - 1;
$db->vouchers[ $expired_scan_id ]['meta'] = wp_json_encode( $expired_scan_meta );
$expired_scan = DoughBoss_Voucher::redeem( $expired_scan_code, 20, 'instore', array( 'idempotency_key' => 'expired-staff-scan' ) );
coupon_voucher_ok( is_array( $expired_scan ) && 'redeemed' === $db->vouchers[ $expired_scan_id ]['status'], 'expired abandoned lease no longer blocks an unreserved staff redemption' );

$lock_code = 'LOCK-' . DoughBoss_Coupon_Code_Probe::checked_part( 'YZ6', 0 ) . '-' . DoughBoss_Coupon_Code_Probe::checked_part( '234', 1 );
$lock_id   = $db->seed_voucher( $lock_code );
$db->deny_next_voucher_lock = true;
$lock_redeem = DoughBoss_Voucher::redeem( $lock_code, 20, 'online', array( 'idempotency_key' => 'lock-unavailable' ) );
coupon_voucher_ok( coupon_voucher_error( $lock_redeem, 'doughboss_voucher_busy' ) && 'issued' === $db->vouchers[ $lock_id ]['status'], 'redemption fails closed when its per-voucher lock is unavailable' );

coupon_voucher_section( 'Order persistence and admin/KDS payloads' );
update_option( DoughBoss_Settings::OPTION_KEY, array( 'tax_rate' => 10, 'gst_inclusive' => 1, 'delivery_fee' => 0, 'currency_code' => 'AUD' ) );
$order_data = array(
	'order_type' => 'pickup', 'location_id' => 1, 'customer_name' => 'Voucher Customer',
	'customer_email' => 'customer@example.test', 'customer_phone' => '0400000000',
	'address' => '', 'notes' => '', 'subtotal' => $inclusive['subtotal'], 'tax' => $inclusive['tax'],
	'delivery_fee' => $inclusive['delivery_fee'], 'total' => $inclusive['total'], 'discount' => $inclusive['discount'],
	'voucher_code' => $fixed_code, 'payment_status' => 'unpaid', 'payment_method' => '',
	'payment_intent_id' => '', 'checkout_key' => str_repeat( 'a', 64 ),
);
$order_lines = array(
	array( 'item_id' => 7, 'name' => 'Test Manoush', 'size' => '', 'toppings' => array(), 'quantity' => 1, 'unit_price' => 24.95, 'line_total' => 24.95 ),
);
$created = DoughBoss_Order::create( $order_data, $order_lines );
$order_id = is_array( $created ) ? (int) $created['order_id'] : 0;
DoughBoss_Voucher::link_redemption_to_order( 'checkout-fixed-1', $order_id );
$saved = DoughBoss_Order::get( $order_id );
coupon_voucher_ok( $saved && 5.00 === (float) $saved->discount && $fixed_code === $saved->voucher_code && 19.95 === (float) $saved->total, 'saved order durably preserves voucher code, discount and discounted total' );
coupon_voucher_ok( $order_id === (int) $db->redemptions['checkout-fixed-1']['order_id'], 'redemption audit row links to the exact saved order id' );
DoughBoss_Voucher::link_redemption_to_order( 'checkout-fixed-1', $order_id + 999 );
coupon_voucher_ok( $order_id === (int) $db->redemptions['checkout-fixed-1']['order_id'], 'late replay cannot overwrite a redemption audit already owned by another order' );

$public = DoughBoss_Order::public_view( $saved );
$admin  = DoughBoss_Order::query( array( 'location_id' => 1 ) );
$kds    = DoughBoss_Order::active_orders( 100, 1 );
coupon_voucher_ok( 5.00 === $public['discount'] && $fixed_code === $public['voucher_code'] && 19.95 === $public['total'], 'customer/admin-safe order projection carries the persisted voucher result' );
coupon_voucher_ok( 1 === $admin['total'] && $fixed_code === $admin['items'][0]->voucher_code && 5.00 === (float) $admin['items'][0]->discount, 'admin order query exposes the durable voucher and discount fields' );
coupon_voucher_ok( 1 === count( $kds ) && 19.95 === $kds[0]['total'] && 'Test Manoush' === $kds[0]['items'][0]['name'], 'KDS payload displays the authoritative discounted total with the saved order lines' );

$activity = DoughBoss_Voucher::query( 100 );
$fixed_activity = array_values( array_filter( $activity, static function ( $row ) use ( $fixed_id ) { return (int) $row->id === $fixed_id; } ) );
coupon_voucher_ok( 1 === count( $fixed_activity ) && 'online' === $fixed_activity[0]->redeemed_channel && 5.00 === (float) $fixed_activity[0]->amount_applied, 'admin voucher activity reports the redemption channel and applied amount' );

$failed_code = 'FAIL-' . DoughBoss_Coupon_Code_Probe::checked_part( '567', 0 ) . '-' . DoughBoss_Coupon_Code_Probe::checked_part( '89A', 1 );
$failed_id   = $db->seed_voucher( $failed_code );
$failed_redeem = DoughBoss_Voucher::redeem( $failed_code, 20, 'online', array( 'idempotency_key' => 'failed-order-redemption' ) );
$failed_data = $order_data;
$failed_data['checkout_key'] = str_repeat( 'b', 64 );
$failed_data['voucher_code'] = $failed_code;
$db->fail_order_inserts = 5;
$failed_order = DoughBoss_Order::create( $failed_data, $order_lines );
if ( is_wp_error( $failed_order ) ) {
	DoughBoss_Voucher::revert_redemption( 'failed-order-redemption' );
}
coupon_voucher_ok(
	is_array( $failed_redeem ) && coupon_voucher_error( $failed_order, 'doughboss_db_error' )
		&& 'issued' === $db->vouchers[ $failed_id ]['status'] && ! isset( $db->redemptions['failed-order-redemption'] ),
	'failed order persistence plus checkout revert leaves the voucher reusable and unlinked'
);

echo "\n=== RESULT: {$pass} passed · {$fail} failed ===\n";
exit( $fail ? 1 : 0 );
