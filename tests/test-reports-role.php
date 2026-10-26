<?php
/**
 * Read-only reports role (2.52.0): permission matrix, shop scope, role upkeep.
 *
 * Runs on the shimmed WordPress surface from tests/bootstrap.php. The plugin's
 * real route table is registered into a recorder, so every registered handler
 * is checked rather than a hand-written list: the reports account must be
 * allowed on exactly five GET routes and refused everywhere else. The same
 * rules are proven against real WordPress in tests/integration/wordpress-mysql.php.
 *
 * @package DoughBoss
 */

require_once dirname( __DIR__ ) . '/includes/class-doughboss-locations.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-staff-scope.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-rest-controller.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-activator.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-migrations.php';

if ( ! class_exists( 'WP_REST_Server' ) ) {
	/** Method constants only. */
	class WP_REST_Server {
		const READABLE   = 'GET';
		const CREATABLE  = 'POST';
		const EDITABLE   = 'POST, PUT, PATCH';
		const DELETABLE  = 'DELETE';
		const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Request double. Other suites call new WP_REST_Request( array( ... ) ), so
	 * an array as the first argument is treated as the parameters of a GET.
	 */
	class WP_REST_Request {
		public $params  = array();
		private $method = 'GET';
		private $route  = '';
		private $headers = array();

		public function __construct( $method = 'GET', $route = '', array $attributes = array() ) {
			unset( $attributes );
			if ( is_array( $method ) ) {
				$this->params = $method;
				return;
			}
			$this->method = (string) $method;
			$this->route  = (string) $route;
		}

		public function get_method() {
			return $this->method;
		}

		public function get_route() {
			return $this->route;
		}

		public function get_param( $key ) {
			return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
		}

		public function get_params() {
			return $this->params;
		}

		public function set_param( $key, $value ) {
			$this->params[ $key ] = $value;
		}

		public function set_header( $name, $value ) {
			$this->headers[ strtolower( str_replace( '-', '_', (string) $name ) ) ] = $value;
		}

		public function get_header( $name ) {
			$key = strtolower( str_replace( '-', '_', (string) $name ) );
			return isset( $this->headers[ $key ] ) ? $this->headers[ $key ] : null;
		}
	}
}

if ( ! function_exists( '__return_true' ) ) {
	function __return_true() {
		return true;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	/** Record the route table instead of registering it. */
	function register_rest_route( $namespace, $route, $args = array() ) {
		$GLOBALS['db_rr_routes'][] = array( $namespace, $route, $args );
		return true;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return ! empty( $GLOBALS['db_rr_user']['caps'][ $capability ] );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return isset( $GLOBALS['db_rr_user']['id'] ) ? (int) $GLOBALS['db_rr_user']['id'] : 0;
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key = '', $single = false ) {
		unset( $single );
		return isset( $GLOBALS['db_rr_meta'][ $user_id ][ $key ] ) ? $GLOBALS['db_rr_meta'][ $user_id ][ $key ] : '';
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ) {
		unset( $action );
		return 'valid-nonce' === $nonce;
	}
}

if ( ! function_exists( 'rest_sanitize_boolean' ) ) {
	function rest_sanitize_boolean( $value ) {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}
}

if ( ! class_exists( 'DoughBoss_RR_Role' ) ) {
	/** Role double backed by $GLOBALS['db_rr_roles']. */
	class DoughBoss_RR_Role {
		public $name;

		public function __construct( $name ) {
			$this->name = $name;
		}

		public function has_cap( $capability ) {
			return ! empty( $GLOBALS['db_rr_roles'][ $this->name ]['caps'][ $capability ] );
		}

		public function add_cap( $capability, $grant = true ) {
			$GLOBALS['db_rr_roles'][ $this->name ]['caps'][ $capability ] = (bool) $grant;
		}

		public function remove_cap( $capability ) {
			unset( $GLOBALS['db_rr_roles'][ $this->name ]['caps'][ $capability ] );
		}
	}
}

if ( ! function_exists( 'get_role' ) ) {
	function get_role( $name ) {
		return isset( $GLOBALS['db_rr_roles'][ $name ] ) ? new DoughBoss_RR_Role( $name ) : false;
	}
}

if ( ! function_exists( 'add_role' ) ) {
	function add_role( $name, $label, $capabilities = array() ) {
		if ( isset( $GLOBALS['db_rr_roles'][ $name ] ) ) {
			return null;
		}
		$GLOBALS['db_rr_roles'][ $name ] = array( 'label' => $label, 'caps' => $capabilities );
		return new DoughBoss_RR_Role( $name );
	}
}

if ( ! class_exists( 'DoughBoss_RR_DB' ) ) {
	/** Fake $wpdb: three active shops with ids 1 to 3. */
	class DoughBoss_RR_DB {
		public $prefix = 'wp_';

		public function prepare( $query ) {
			$args = func_get_args();
			array_shift( $args );
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%d/', (string) (int) $arg, $query, 1 );
			}
			return $query;
		}

		public function get_row( $query ) {
			if ( preg_match( '/id = (\d+)/', (string) $query, $match ) && (int) $match[1] >= 1 && (int) $match[1] <= 3 ) {
				return (object) array( 'id' => (int) $match[1], 'is_active' => 1 );
			}
			return null;
		}

		public function get_results( $query ) {
			unset( $query );
			return array(
				(object) array( 'id' => 1, 'name' => 'Revesby', 'is_active' => 1 ),
				(object) array( 'id' => 2, 'name' => 'Bankstown', 'is_active' => 1 ),
				(object) array( 'id' => 3, 'name' => 'Roselands', 'is_active' => 1 ),
			);
		}
	}
}

/** Capability sets, matching the roles the plugin creates. */
function db_rr_caps( $set ) {
	$kitchen = array( 'read', 'manage_doughboss_kds', 'redeem_doughboss_vouchers', 'clock_doughboss_staff' );
	$sets    = array(
		'reports' => array( 'read', 'view_doughboss_reports' ),
		'kitchen' => $kitchen,
		'manager' => array_merge( $kitchen, array( 'manage_doughboss' ) ),
		'anon'    => array(),
	);
	return $sets[ $set ];
}

/** Sign in as a synthetic user. */
function db_rr_user( $id, array $capabilities, $location = 0 ) {
	$GLOBALS['db_rr_user'] = array( 'id' => (int) $id, 'caps' => array_fill_keys( $capabilities, true ) );
	$GLOBALS['db_rr_meta'] = array();
	if ( $location ) {
		$GLOBALS['db_rr_meta'][ (int) $id ]['doughboss_location_id'] = (int) $location;
	}
}

/** Run a body with the fake database and the multi-shop settings installed. */
function db_rr_with_shops( $body ) {
	$had   = array_key_exists( 'wpdb', $GLOBALS );
	$saved = $had ? $GLOBALS['wpdb'] : null;
	$GLOBALS['wpdb'] = new DoughBoss_RR_DB();
	doughboss_test_set_settings( array( 'single_location_mode' => 1 ) );
	try {
		call_user_func( $body );
	} finally {
		if ( $had ) {
			$GLOBALS['wpdb'] = $saved;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		unset( $GLOBALS['db_rr_user'], $GLOBALS['db_rr_meta'] );
	}
}

function db_rr_controller() {
	$reflection = new ReflectionClass( 'DoughBoss_REST_Controller' );
	return $reflection->newInstanceWithoutConstructor();
}

/** Every registered handler as route, method and permission callback. */
function db_rr_handlers() {
	$GLOBALS['db_rr_routes'] = array();
	db_rr_controller()->register_routes();
	$out = array();
	foreach ( $GLOBALS['db_rr_routes'] as $registration ) {
		$route    = '/doughboss/v1' . preg_replace( '#\(\?P<id>[^)]*\)#', '7', $registration[1] );
		$args     = $registration[2];
		$handlers = isset( $args['methods'] ) ? array( $args ) : $args;
		foreach ( $handlers as $handler ) {
			if ( ! is_array( $handler ) || ! isset( $handler['methods'] ) ) {
				continue;
			}
			$out[] = array(
				'route'      => $route,
				'methods'    => (string) $handler['methods'],
				'permission' => isset( $handler['permission_callback'] ) ? $handler['permission_callback'] : null,
			);
		}
	}
	return $out;
}

function db_rr_five() {
	return array(
		'/doughboss/v1/status',
		'/doughboss/v1/admin/board-summary',
		'/doughboss/v1/admin/preorder-requests',
		'/doughboss/v1/admin/orders',
		'/doughboss/v1/admin/catering-board',
	);
}

function db_rr_status( $error ) {
	if ( ! is_wp_error( $error ) ) {
		return 0;
	}
	$data = $error->get_error_data();
	return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
}

/** Call a handler's permission callback; a throw is reported, never hidden. */
function db_rr_permission( $handler, WP_REST_Request $request ) {
	try {
		return call_user_func( $handler['permission'], $request );
	} catch ( Throwable $e ) {
		return new WP_Error( 'db_rr_threw', $handler['route'] . ': ' . $e->getMessage(), array( 'status' => 500 ) );
	}
}

function db_rr_request( $method, $route ) {
	$request = new WP_REST_Request( $method, $route );
	$request->set_header( 'X-WP-Nonce', 'valid-nonce' );
	return $request;
}

db_test( 'Reports role: each of the five routes is a single GET handler the account may call, with or without a board key', function () {
	db_rr_with_shops( function () {
		$handlers = db_rr_handlers();
		assert_true( count( $handlers ) > 60, 'the real route table was registered' );
		foreach ( array( false, true ) as $with_key ) {
			doughboss_test_set_settings( array( 'single_location_mode' => 1, 'board_access_key' => $with_key ? hash( 'sha256', 'secret' ) : '' ) );
			foreach ( db_rr_five() as $route ) {
				$found = array_values( array_filter( $handlers, function ( $handler ) use ( $route ) {
					return $handler['route'] === $route;
				} ) );
				assert_same( 1, count( $found ), $route . ' has exactly one handler' );
				if ( 1 !== count( $found ) ) {
					continue;
				}
				assert_same( 'GET', $found[0]['methods'], $route . ' is registered for GET only' );
				db_rr_user( 9, db_rr_caps( 'reports' ) );
				assert_same( true, db_rr_permission( $found[0], db_rr_request( 'GET', $route ) ), $route . ' is allowed for the reports account' . ( $with_key ? ' without a board key' : '' ) );
			}
		}
	} );
} );

db_test( 'Reports role: sees every shop with an optional filter, and every write path still refuses it', function () {
	db_rr_with_shops( function () {
		db_rr_user( 9, db_rr_caps( 'reports' ) );
		assert_true( DoughBoss_Staff_Scope::is_reports_reader(), 'the account is a reports reader' );
		assert_same( 0, DoughBoss_Staff_Scope::effective_read_location_id( 0 ), 'no filter means every shop' );
		assert_same( 2, DoughBoss_Staff_Scope::effective_read_location_id( 2 ), 'filter to shop 2' );
		assert_same( 3, DoughBoss_Staff_Scope::effective_read_location_id( 3 ), 'filter to shop 3' );
		$write_scope = DoughBoss_Staff_Scope::effective_location_id( 0 );
		assert_same( 403, db_rr_status( $write_scope ), 'the write-path shop resolver refuses the account' );
		assert_same( 403, db_rr_status( DoughBoss_Staff_Scope::current_location_id() ), 'the current shop resolver refuses the account' );
		assert_true( is_wp_error( DoughBoss_Staff_Scope::can_access_order( (object) array( 'location_id' => 1 ) ) ), 'no order can be accessed through the write-path check' );
	} );
} );

db_test( 'Kitchen accounts stay inside their assigned shop and still need the board key', function () {
	db_rr_with_shops( function () {
		db_rr_user( 5, db_rr_caps( 'kitchen' ), 2 );
		assert_false( DoughBoss_Staff_Scope::is_reports_reader(), 'a kitchen account is not a reports reader' );
		foreach ( array( 'effective_read_location_id', 'effective_location_id' ) as $resolver ) {
			assert_same( 2, DoughBoss_Staff_Scope::$resolver( 0 ), $resolver . ': default is the assigned shop' );
			assert_same( 2, DoughBoss_Staff_Scope::$resolver( 2 ), $resolver . ': own shop is allowed' );
			assert_same( 403, db_rr_status( DoughBoss_Staff_Scope::$resolver( 1 ) ), $resolver . ': shop 1 is refused' );
			assert_same( 403, db_rr_status( DoughBoss_Staff_Scope::$resolver( 3 ) ), $resolver . ': shop 3 is refused' );
		}
		assert_same( true, DoughBoss_Staff_Scope::can_access_order( (object) array( 'location_id' => 2 ) ), 'own shop order is reachable' );
		assert_same( 404, db_rr_status( DoughBoss_Staff_Scope::can_access_order( (object) array( 'location_id' => 1 ) ) ), 'another shop order is not found' );

		db_rr_user( 6, db_rr_caps( 'kitchen' ) );
		assert_true( is_wp_error( DoughBoss_Staff_Scope::effective_read_location_id( 0 ) ), 'a kitchen account without a shop fails closed on a multi-shop site' );

		db_rr_user( 7, db_rr_caps( 'manager' ) );
		assert_same( 0, DoughBoss_Staff_Scope::effective_read_location_id( 0 ), 'a manager sees every shop' );
		assert_same( 3, DoughBoss_Staff_Scope::effective_read_location_id( 3 ), 'a manager may filter' );

		doughboss_test_set_settings( array( 'single_location_mode' => 1, 'board_access_key' => hash( 'sha256', 'secret' ) ) );
		$controller = db_rr_controller();
		db_rr_user( 5, db_rr_caps( 'kitchen' ), 2 );
		$request = db_rr_request( 'GET', '/doughboss/v1/admin/orders' );
		$refused = $controller->verify_board_read_access( $request );
		assert_same( 'doughboss_board_key_required', is_wp_error( $refused ) ? $refused->get_error_code() : '', 'kitchen without the key is refused' );
		$request->set_header( 'X-DoughBoss-Board-Key', 'secret' );
		assert_same( true, $controller->verify_board_read_access( $request ), 'kitchen with the key is allowed' );

		db_rr_user( 8, array_merge( db_rr_caps( 'kitchen' ), array( 'view_doughboss_reports' ) ), 2 );
		assert_false( DoughBoss_Staff_Scope::is_reports_reader(), 'a kitchen account that also holds the reports capability is not a reports reader' );
		assert_same( 403, db_rr_status( DoughBoss_Staff_Scope::effective_read_location_id( 1 ) ), 'it stays scoped to its own shop' );
	} );
} );

db_test( 'Reports role is refused on every other route and method, in both the permission callback and the namespace guard', function () {
	db_rr_with_shops( function () {
		$handlers   = db_rr_handlers();
		$five       = db_rr_five();
		$controller = db_rr_controller();
		$public     = 0;
		foreach ( $handlers as $handler ) {
			db_rr_user( 0, db_rr_caps( 'anon' ) );
			$is_public = true === db_rr_permission( $handler, db_rr_request( 'GET', $handler['route'] ) )
				|| true === db_rr_permission( $handler, db_rr_request( 'POST', $handler['route'] ) );
			if ( $is_public ) {
				++$public;
			}
			foreach ( array_map( 'trim', explode( ',', $handler['methods'] ) ) as $method ) {
				$allowed = 'GET' === $method && in_array( $handler['route'], $five, true );
				$request = db_rr_request( $method, $handler['route'] );
				db_rr_user( 9, db_rr_caps( 'reports' ) );
				$guard = $controller->guard_reports_reader( null, null, $request );
				if ( $allowed ) {
					assert_same( null, $guard, $method . ' ' . $handler['route'] . ' passes the guard' );
				} else {
					assert_same( 'doughboss_reports_read_only', is_wp_error( $guard ) ? $guard->get_error_code() : '', $method . ' ' . $handler['route'] . ' is stopped by the guard' );
					assert_same( 403, db_rr_status( $guard ), $method . ' ' . $handler['route'] . ' guard status' );
				}
				if ( ! $is_public ) {
					assert_same( $allowed, true === db_rr_permission( $handler, $request ), $method . ' ' . $handler['route'] . ' permission callback' );
				}
			}
		}
		assert_true( $public > 0 && $public < count( $handlers ), 'some routes are public and most are not' );
	} );
} );

db_test( 'Reports role is refused on named sensitive routes and on other methods for the five routes', function () {
	db_rr_with_shops( function () {
		$handlers   = db_rr_handlers();
		$controller = db_rr_controller();
		db_rr_user( 9, db_rr_caps( 'reports' ) );
		$named = array(
			array( 'GET', '/doughboss/v1/admin/vouchers' ),
			array( 'POST', '/doughboss/v1/voucher/redeem' ),
			array( 'POST', '/doughboss/v1/voucher/scan' ),
			array( 'GET', '/doughboss/v1/admin/square-dashboard' ),
			array( 'POST', '/doughboss/v1/admin/order/7/status' ),
			array( 'POST', '/doughboss/v1/admin/preorder/7/decision' ),
			array( 'POST', '/doughboss/v1/admin/preorder/7/payment-link' ),
			array( 'GET', '/doughboss/v1/admin/preorder/7/pickup-options' ),
			array( 'GET', '/doughboss/v1/auth/me' ),
			array( 'GET', '/doughboss/v1/admin/catering' ),
		);
		foreach ( $named as $case ) {
			$found = null;
			foreach ( $handlers as $handler ) {
				if ( $handler['route'] === $case[1] && false !== strpos( $handler['methods'], $case[0] ) ) {
					$found = $handler;
				}
			}
			assert_true( null !== $found, $case[0] . ' ' . $case[1] . ' exists' );
			if ( null !== $found ) {
				$result = db_rr_permission( $found, db_rr_request( $case[0], $case[1] ) );
				assert_true( is_wp_error( $result ) && in_array( db_rr_status( $result ), array( 401, 403 ), true ), $case[0] . ' ' . $case[1] . ' is refused' );
			}
		}
		foreach ( db_rr_five() as $route ) {
			foreach ( array( 'HEAD', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE' ) as $method ) {
				$request = db_rr_request( $method, $route );
				assert_same( 403, db_rr_status( $controller->guard_reports_reader( null, null, $request ) ), $method . ' ' . $route . ' is stopped by the guard' );
				$board = $controller->verify_board_read_access( $request );
				$admin = $controller->verify_admin_or_reports_read( $request );
				assert_true( is_wp_error( '/doughboss/v1/status' === $route ? $admin : $board ), $method . ' ' . $route . ' is refused by its permission callback' );
			}
		}
		assert_same( null, $controller->guard_reports_reader( null, null, db_rr_request( 'GET', '/wp/v2/users/me' ) ), 'the guard leaves other namespaces alone' );
	} );
} );

db_test( 'Order history and status filters stay owner-only', function () {
	db_rr_with_shops( function () {
		$controller = db_rr_controller();
		db_rr_user( 9, db_rr_caps( 'reports' ) );
		foreach ( array( array( 'history' => '1' ), array( 'status' => 'completed' ) ) as $params ) {
			$result = $controller->admin_orders( new WP_REST_Request( $params ) );
			assert_same( 'doughboss_forbidden', is_wp_error( $result ) ? $result->get_error_code() : '', 'history or status filter is refused: ' . wp_json_encode_safe( $params ) );
		}
	} );
} );

function wp_json_encode_safe( $value ) {
	return json_encode( $value );
}

db_test( 'Role upkeep: created with only read and the reports capability, healed if tampered, and stamped by the 1.32.0 step', function () {
	$saved = isset( $GLOBALS['db_rr_roles'] ) ? $GLOBALS['db_rr_roles'] : null;
	try {
		$GLOBALS['db_rr_roles'] = array();
		DoughBoss_Activator::add_capabilities();
		assert_true( isset( $GLOBALS['db_rr_roles']['doughboss_reports'] ), 'the reports role is created' );
		$role = isset( $GLOBALS['db_rr_roles']['doughboss_reports'] ) ? $GLOBALS['db_rr_roles']['doughboss_reports'] : array( 'label' => '', 'caps' => array() );
		assert_same( 'DoughBoss Reports (read-only)', $role['label'], 'role display name' );
		$caps = array_keys( array_filter( $role['caps'] ) );
		sort( $caps );
		assert_same( array( 'read', 'view_doughboss_reports' ), $caps, 'only read and view_doughboss_reports' );
		$kitchen = array_keys( array_filter( $GLOBALS['db_rr_roles']['doughboss_kitchen']['caps'] ) );
		$expected = db_rr_caps( 'kitchen' );
		sort( $kitchen );
		sort( $expected );
		assert_same( $expected, $kitchen, 'the kitchen role is unchanged' );
		assert_true( ! empty( $GLOBALS['db_rr_roles']['doughboss_manager']['caps']['manage_doughboss'] ), 'the manager role is unchanged' );

		DoughBoss_Activator::add_capabilities();
		assert_same( $role, $GLOBALS['db_rr_roles']['doughboss_reports'], 'a second run changes nothing' );

		$GLOBALS['db_rr_roles']['doughboss_reports']['caps']['manage_doughboss_kds']      = true;
		$GLOBALS['db_rr_roles']['doughboss_reports']['caps']['redeem_doughboss_vouchers'] = true;
		unset( $GLOBALS['db_rr_roles']['doughboss_reports']['caps']['read'] );
		DoughBoss_Activator::add_capabilities();
		$healed = array_keys( array_filter( $GLOBALS['db_rr_roles']['doughboss_reports']['caps'] ) );
		sort( $healed );
		assert_same( array( 'read', 'view_doughboss_reports' ), $healed, 'elevated capabilities are removed and read is restored' );

		$step = new ReflectionMethod( 'DoughBoss_Migrations', 'upgrade_to_1_32_0' );
		$step->setAccessible( true );
		$step->invoke( null );
		assert_true( true, 'the 1.32.0 step succeeds when the role exists' );
	} finally {
		if ( null === $saved ) {
			unset( $GLOBALS['db_rr_roles'] );
		} else {
			$GLOBALS['db_rr_roles'] = $saved;
		}
	}
} );

db_test( 'Versions and the uninstall path are wired for the new role', function () {
	$root = dirname( __DIR__ ) . '/';
	assert_true( false !== strpos( (string) file_get_contents( $root . 'doughboss.php' ), "define( 'DOUGHBOSS_DB_VERSION', '1.32.0' );" ), 'database version is 1.32.0' );
	assert_true( false !== strpos( (string) file_get_contents( $root . 'doughboss.php' ), "define( 'DOUGHBOSS_VERSION', '2.52.0' );" ), 'plugin version is 2.52.0' );
	assert_true( false !== strpos( (string) file_get_contents( $root . 'includes/class-doughboss-migrations.php' ), "'1.32.0' => 'upgrade_to_1_32_0'" ), 'the migration step is registered' );
	assert_true( false !== strpos( (string) file_get_contents( $root . 'uninstall.php' ), "remove_role( 'doughboss_reports' );" ), 'uninstall removes the role' );
} );

db_test( 'Source scan: the reports capability and reader check are referenced only where reviewed', function () {
	$root    = str_replace( '\\', '/', dirname( __DIR__ ) ) . '/';
	$cap_ok  = array( 'includes/class-doughboss-staff-scope.php', 'includes/class-doughboss-activator.php', 'includes/class-doughboss-migrations.php' );
	$read_ok = array( 'includes/class-doughboss-staff-scope.php', 'includes/class-doughboss-rest-controller.php' );
	$cap_in  = array();
	$read_in = array();
	foreach ( array( 'includes', 'admin' ) as $directory ) {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . $directory, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$relative = substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $root ) );
			$source   = (string) file_get_contents( $file->getPathname() );
			if ( false !== strpos( $source, 'view_doughboss_reports' ) ) {
				$cap_in[] = $relative;
			}
			if ( false !== strpos( $source, 'is_reports_reader(' ) ) {
				$read_in[] = $relative;
			}
		}
	}
	sort( $cap_in );
	sort( $read_in );
	sort( $cap_ok );
	sort( $read_ok );
	assert_same( $cap_ok, $cap_in, 'view_doughboss_reports appears only in the reviewed files' );
	assert_same( $read_ok, $read_in, 'is_reports_reader() is called only in the reviewed files' );
} );
