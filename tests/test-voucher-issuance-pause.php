<?php
/**
 * Legacy promotional-voucher issuance pause tests.
 *
 * @package DoughBoss
 */

if ( ! function_exists( 'do_action' ) ) {
	/** @return void */
	function do_action( $hook ) {
		$GLOBALS['doughboss_test_actions'][] = $hook;
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	/** @return void */
	function esc_html_e( $text, $domain = 'default' ) {
		echo esc_html__( $text, $domain );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/** @return string|false */
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

/** Small in-memory database double for the narrowly exercised voucher paths. */
class DoughBoss_Legacy_Voucher_Pause_Wpdb {
	/** @var string */
	public $prefix = 'wp_';
	/** @var int */
	public $get_var_calls = 0;
	/** @var int */
	public $insert_calls = 0;
	/** @var int */
	public $insert_id = 714;

	/** @return string */
	public function prepare( $query ) {
		return $query;
	}

	/** @return int|string|null */
	public function get_var( $query ) {
		++$this->get_var_calls;
		return false !== strpos( (string) $query, 'GET_LOCK' ) ? 1 : null;
	}

	/** @return array */
	public function get_results( $query ) {
		unset( $query );
		return array();
	}

	/** @return bool */
	public function insert( $table, $data, $formats = null ) {
		unset( $table, $data, $formats );
		++$this->insert_calls;
		return true;
	}

	/** @return int */
	public function query( $query ) {
		unset( $query );
		return 1;
	}
}

if ( ! class_exists( 'DoughBoss_Coupon_Code' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-doughboss-coupon-code.php';
}
if ( ! class_exists( 'DoughBoss_Voucher' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-doughboss-voucher.php';
}
if ( ! class_exists( 'DoughBoss_Locations' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-doughboss-locations.php';
}
if ( ! class_exists( 'DoughBoss_Admin' ) ) {
	require_once dirname( __DIR__ ) . '/admin/class-doughboss-admin.php';
}
if ( ! class_exists( 'DoughBoss_Shortcodes' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-doughboss-shortcodes.php';
}

db_test( 'legacy voucher issuance defaults to paused', function () {
	assert_same( 0, DoughBoss_Settings::defaults()['legacy_voucher_issuance_enabled'], 'legacy voucher issuance default is off' );
	assert_false( DoughBoss_Settings::legacy_voucher_issuance_enabled(), 'missing legacy voucher issuance setting fails closed' );
} );

db_test( 'paused issuance blocks manual and active-campaign voucher creation before database work', function () {
	$GLOBALS['wpdb'] = new DoughBoss_Legacy_Voucher_Pause_Wpdb();
	$GLOBALS['doughboss_test_actions'] = array();
	DoughBoss_Settings::update(
		array(
			'voucher_campaigns' => array(
				'active-saved-campaign' => array(
					'slug' => 'active-saved-campaign',
					'label' => 'Saved active campaign',
					'type' => 'amount',
					'value' => 5,
					'daily_cap' => 10,
					'active' => 1,
				),
			),
		)
	);

	$manual = DoughBoss_Voucher::issue( array( 'type' => 'amount', 'value' => 5, 'prefix' => 'MANUAL' ) );
	$claim  = DoughBoss_Voucher::claim( 'active-saved-campaign' );

	assert_same( 'doughboss_voucher_issuance_paused', is_wp_error( $manual ) ? $manual->get_error_code() : '', 'paused manual issue is rejected' );
	assert_same( 'doughboss_voucher_issuance_paused', is_wp_error( $claim ) ? $claim->get_error_code() : '', 'paused active saved campaign cannot bypass the gate' );
	assert_same( 1, DoughBoss_Voucher::campaigns()['active-saved-campaign']['active'], 'paused read does not overwrite stored campaign activity' );
	assert_same( 0, $GLOBALS['wpdb']->get_var_calls, 'paused issue and claim do not touch the database' );
	assert_same( 0, $GLOBALS['wpdb']->insert_calls, 'paused issue and claim do not insert voucher rows' );
	assert_same( array(), $GLOBALS['doughboss_test_actions'], 'paused issue and claim do not fire provider or SMS claim hooks' );
} );

db_test( 'enabled legacy voucher issuance keeps the existing issue path available', function () {
	$GLOBALS['wpdb'] = new DoughBoss_Legacy_Voucher_Pause_Wpdb();
	DoughBoss_Settings::update( array( 'legacy_voucher_issuance_enabled' => 1 ) );

	$issued = DoughBoss_Voucher::issue( array( 'type' => 'amount', 'value' => 5, 'prefix' => 'TEST' ) );

	assert_false( is_wp_error( $issued ), 'enabled issuance returns a voucher result' );
	assert_same( 714, $issued['id'], 'enabled issuance returns the durable insert id' );
	assert_same( 1, $GLOBALS['wpdb']->insert_calls, 'enabled issuance performs its existing voucher insert' );
} );

db_test( 'enabled issuance keeps the existing active-campaign claim path available', function () {
	$GLOBALS['wpdb'] = new DoughBoss_Legacy_Voucher_Pause_Wpdb();
	$GLOBALS['doughboss_test_actions'] = array();
	DoughBoss_Settings::update(
		array(
			'legacy_voucher_issuance_enabled' => 1,
			'voucher_campaigns' => array(
				'campaign-enabled' => array(
					'slug' => 'campaign-enabled',
					'label' => 'Enabled campaign',
					'type' => 'amount',
					'value' => 5,
					'daily_cap' => 0,
					'active' => 1,
				),
			),
		)
	);

	$claimed = DoughBoss_Voucher::claim( 'campaign-enabled' );

	assert_false( is_wp_error( $claimed ), 'enabled active campaign returns a voucher result' );
	assert_same( 1, $GLOBALS['wpdb']->insert_calls, 'enabled active campaign reaches the durable insert' );
	assert_true( in_array( 'doughboss_voucher_claimed', $GLOBALS['doughboss_test_actions'], true ), 'enabled active campaign keeps the existing claim hook' );
} );

db_test( 'admin setting persists an explicit enable and fails closed when omitted', function () {
	$GLOBALS['wpdb'] = new DoughBoss_Legacy_Voucher_Pause_Wpdb();
	DoughBoss_Settings::update(
		array(
			'legacy_voucher_issuance_enabled' => 1,
			'voucher_campaigns' => array( 'retained' => array( 'slug' => 'retained', 'active' => 1 ) ),
		)
	);
	$admin = new DoughBoss_Admin();

	$enabled = $admin->sanitize_settings( array( 'legacy_voucher_issuance_enabled' => '1' ) );
	assert_same( 1, $enabled['legacy_voucher_issuance_enabled'], 'admin setting accepts an explicit enable' );
	DoughBoss_Settings::update( $enabled );
	assert_true( DoughBoss_Settings::legacy_voucher_issuance_enabled(), 'sanitized admin enable persists' );

	$omitted = $admin->sanitize_settings( array() );
	assert_same( 0, $omitted['legacy_voucher_issuance_enabled'], 'omitted admin checkbox remains paused' );
	assert_same( 1, $omitted['voucher_campaigns']['retained']['active'], 'admin setting save preserves stored campaign configuration' );
} );

db_test( 'paused public voucher offer has no campaign control or claim form', function () {
	DoughBoss_Settings::update( array( 'legacy_voucher_issuance_enabled' => 0 ) );
	$shortcodes = ( new ReflectionClass( 'DoughBoss_Shortcodes' ) )->newInstanceWithoutConstructor();
	$html = $shortcodes->voucher_claim();
	assert_true( false !== strpos( $html, 'Voucher updates' ), 'paused voucher title reflects the issuance pause' );
	assert_false( false !== strpos( $html, 'Claim your student voucher' ), 'paused voucher title does not invite new claims' );

	assert_true( false !== strpos( $html, 'Legacy promotional vouchers are currently paused.' ), 'public voucher area states that issuance is paused' );
	assert_false( false !== strpos( $html, 'data-campaign=' ), 'public paused voucher area has no campaign controls' );
	assert_false( false !== strpos( $html, '<form' ), 'public paused voucher area has no claim form' );
} );

db_test( 'paused issuance does not change the existing issued-voucher evaluation contract', function () {
	DoughBoss_Settings::update( array( 'legacy_voucher_issuance_enabled' => 0 ) );
	$issued_voucher = (object) array(
		'code' => 'LEGACY-ABCD-EFGH',
		'status' => 'issued',
		'type' => 'amount',
		'value' => 5,
		'min_spend' => 3,
		'scope' => 'both',
		'valid_from' => null,
		'valid_to' => null,
	);
	$evaluation = DoughBoss_Voucher::evaluate( $issued_voucher, 10, 'online' );

	assert_true( $evaluation['valid'], 'an existing issued voucher remains valid while issuance is paused' );
	assert_same( 5.0, $evaluation['amount'], 'an existing issued voucher keeps its discount value while issuance is paused' );
} );
