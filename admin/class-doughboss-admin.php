<?php
/**
 * Admin screens: orders management and settings.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires up the wp-admin experience for DoughBoss.
 */
class DoughBoss_Admin {

	const SETTINGS_GROUP = 'doughboss_settings_group';
	const CAP            = 'manage_doughboss';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_doughboss_save_location', array( $this, 'handle_save_location' ) );
		add_action( 'admin_post_doughboss_delete_location', array( $this, 'handle_delete_location' ) );
		add_action( 'admin_post_doughboss_issue_voucher', array( $this, 'handle_issue_voucher' ) );
		add_action( 'admin_post_doughboss_void_voucher', array( $this, 'handle_void_voucher' ) );
	}

	/**
	 * The capability required for management screens.
	 *
	 * @return string
	 */
	private function cap() {
		return current_user_can( self::CAP ) ? self::CAP : 'manage_options';
	}

	/**
	 * Capability for the in-store scan dashboard. Prefers the dedicated redeem
	 * cap (so a low-privilege kitchen tablet can reach the scanner) and falls
	 * back to manage_options so owners always see it.
	 *
	 * @return string
	 */
	private function scan_cap() {
		return current_user_can( 'redeem_doughboss_vouchers' ) ? 'redeem_doughboss_vouchers' : 'manage_options';
	}

	/**
	 * Register the top-level menu and sub-pages. The Menu Items CPT and its
	 * category taxonomy attach automatically via `show_in_menu`.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'DoughBoss', 'doughboss' ),
			__( 'DoughBoss', 'doughboss' ),
			$this->cap(),
			'doughboss',
			array( $this, 'render_orders_page' ),
			'dashicons-food',
			26
		);

		add_submenu_page(
			'doughboss',
			__( 'Orders', 'doughboss' ),
			__( 'Orders', 'doughboss' ),
			$this->cap(),
			'doughboss',
			array( $this, 'render_orders_page' )
		);

		add_submenu_page(
			'doughboss',
			__( 'Catering Enquiries', 'doughboss' ),
			__( 'Catering', 'doughboss' ),
			$this->cap(),
			'doughboss-catering',
			array( $this, 'render_catering_page' )
		);

		add_submenu_page(
			'doughboss',
			__( 'Shops / Locations', 'doughboss' ),
			__( 'Shops', 'doughboss' ),
			$this->cap(),
			'doughboss-locations',
			array( $this, 'render_locations_page' )
		);

		add_submenu_page(
			'doughboss',
			__( 'Vouchers', 'doughboss' ),
			__( 'Vouchers', 'doughboss' ),
			$this->cap(),
			'doughboss-vouchers',
			array( $this, 'render_vouchers_page' )
		);

		add_submenu_page(
			'doughboss',
			__( 'DoughBoss Settings', 'doughboss' ),
			__( 'Settings', 'doughboss' ),
			$this->cap(),
			'doughboss-settings',
			array( $this, 'render_settings_page' )
		);

		// Standalone, tablet-friendly live order board. Registered with the
		// kitchen capability so a low-privilege "DoughBoss Kitchen" user can
		// reach it without a full admin login on a shop device.
		add_menu_page(
			__( 'Order Board', 'doughboss' ),
			__( 'Order Board', 'doughboss' ),
			'manage_doughboss_kds',
			'doughboss-board',
			array( $this, 'render_board_page' ),
			'dashicons-screenoptions',
			27
		);

		// Standalone, tablet-friendly voucher scanner for staff/till. Uses the
		// dedicated redeem capability so a "DoughBoss Kitchen" device can scan &
		// redeem without owner privileges (and without being able to issue value).
		add_menu_page(
			__( 'Voucher Scan', 'doughboss' ),
			__( 'Voucher Scan', 'doughboss' ),
			$this->scan_cap(),
			'doughboss-scan',
			array( $this, 'render_scan_page' ),
			'dashicons-tickets-alt',
			28
		);
	}

	/**
	 * Register the settings option with a sanitizing callback.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			DoughBoss_Settings::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Sanitize the entire settings payload coming from the settings form.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();
		$clean = array();

		$clean['currency_symbol'] = isset( $input['currency_symbol'] ) ? sanitize_text_field( $input['currency_symbol'] ) : '$';
		$clean['currency_code']   = isset( $input['currency_code'] ) ? sanitize_text_field( $input['currency_code'] ) : 'USD';
		$clean['tax_rate']        = isset( $input['tax_rate'] ) ? max( 0, (float) $input['tax_rate'] ) : 0;
		$clean['gst_inclusive']   = empty( $input['gst_inclusive'] ) ? 0 : 1;
		$clean['delivery_fee']    = isset( $input['delivery_fee'] ) ? max( 0, (float) $input['delivery_fee'] ) : 0;
		$clean['enable_pickup']   = empty( $input['enable_pickup'] ) ? 0 : 1;
		$clean['enable_delivery'] = empty( $input['enable_delivery'] ) ? 0 : 1;
		$clean['ordering_open']   = empty( $input['ordering_open'] ) ? 0 : 1;

		// Payments (Stripe). Keys are stored for the active mode; secret keys are
		// only ever used in server-side calls and are never sent to the browser.
		$clean['payments_enabled'] = empty( $input['payments_enabled'] ) ? 0 : 1;
		$clean['stripe_mode']      = ( isset( $input['stripe_mode'] ) && 'live' === $input['stripe_mode'] ) ? 'live' : 'test';
		$clean['stripe_test_pk']   = isset( $input['stripe_test_pk'] ) ? sanitize_text_field( $input['stripe_test_pk'] ) : '';
		$clean['stripe_test_sk']   = isset( $input['stripe_test_sk'] ) ? sanitize_text_field( $input['stripe_test_sk'] ) : '';
		$clean['stripe_live_pk']   = isset( $input['stripe_live_pk'] ) ? sanitize_text_field( $input['stripe_live_pk'] ) : '';
		$clean['stripe_live_sk']   = isset( $input['stripe_live_sk'] ) ? sanitize_text_field( $input['stripe_live_sk'] ) : '';
		$clean['stripe_test_whsec'] = isset( $input['stripe_test_whsec'] ) ? sanitize_text_field( $input['stripe_test_whsec'] ) : '';
		$clean['stripe_live_whsec'] = isset( $input['stripe_live_whsec'] ) ? sanitize_text_field( $input['stripe_live_whsec'] ) : '';

		// POSPal POS (Open Platform) — Revesby pilot. The secret appKey is read
		// env-first (DOUGHBOSS_POSPAL_APPKEY); this field is only a fallback.
		$clean['pospal_enabled'] = empty( $input['pospal_enabled'] ) ? 0 : 1;
		$clean['pospal_host']    = isset( $input['pospal_host'] ) ? esc_url_raw( trim( (string) $input['pospal_host'] ) ) : '';
		$clean['pospal_app_id']  = isset( $input['pospal_app_id'] ) ? sanitize_text_field( $input['pospal_app_id'] ) : '';
		$clean['pospal_app_key'] = isset( $input['pospal_app_key'] ) ? sanitize_text_field( $input['pospal_app_key'] ) : '';
		$clean['pospal_coupon_uid_5']  = isset( $input['pospal_coupon_uid_5'] ) ? sanitize_text_field( $input['pospal_coupon_uid_5'] ) : '';
		$clean['pospal_coupon_uid_10'] = isset( $input['pospal_coupon_uid_10'] ) ? sanitize_text_field( $input['pospal_coupon_uid_10'] ) : '';
		// Additional POSPal stores (multi-store): store 2 + store 3.
		foreach ( array( 2, 3 ) as $sn ) {
			$clean[ 'pospal' . $sn . '_label' ]         = isset( $input[ 'pospal' . $sn . '_label' ] ) ? sanitize_text_field( $input[ 'pospal' . $sn . '_label' ] ) : '';
			$clean[ 'pospal' . $sn . '_host' ]          = isset( $input[ 'pospal' . $sn . '_host' ] ) ? esc_url_raw( trim( (string) $input[ 'pospal' . $sn . '_host' ] ) ) : '';
			$clean[ 'pospal' . $sn . '_app_id' ]        = isset( $input[ 'pospal' . $sn . '_app_id' ] ) ? sanitize_text_field( $input[ 'pospal' . $sn . '_app_id' ] ) : '';
			$clean[ 'pospal' . $sn . '_app_key' ]       = isset( $input[ 'pospal' . $sn . '_app_key' ] ) ? sanitize_text_field( $input[ 'pospal' . $sn . '_app_key' ] ) : '';
			$clean[ 'pospal' . $sn . '_coupon_uid_5' ]  = isset( $input[ 'pospal' . $sn . '_coupon_uid_5' ] ) ? sanitize_text_field( $input[ 'pospal' . $sn . '_coupon_uid_5' ] ) : '';
			$clean[ 'pospal' . $sn . '_coupon_uid_10' ] = isset( $input[ 'pospal' . $sn . '_coupon_uid_10' ] ) ? sanitize_text_field( $input[ 'pospal' . $sn . '_coupon_uid_10' ] ) : '';
		}

		// Phase 2 — real-time & notifications. Off by default; fully dormant until
		// configured. Secret fields (publish JWT, ntfy token, ClickSend API key,
		// printer token) render blank in the form, so a blank submission PRESERVES
		// the previously stored value rather than wiping it (see keep_secret()).
		$existing = DoughBoss_Settings::all();

		// Mercure real-time push.
		$clean['mercure_enabled']       = empty( $input['mercure_enabled'] ) ? 0 : 1;
		$clean['mercure_hub_url']       = isset( $input['mercure_hub_url'] ) ? esc_url_raw( trim( (string) $input['mercure_hub_url'] ) ) : '';
		$clean['mercure_publish_jwt']   = $this->keep_secret( $input, $existing, 'mercure_publish_jwt' );
		$clean['mercure_subscribe_jwt'] = isset( $input['mercure_subscribe_jwt'] ) ? sanitize_text_field( $input['mercure_subscribe_jwt'] ) : '';
		$clean['mercure_topic_prefix']  = isset( $input['mercure_topic_prefix'] ) ? sanitize_text_field( $input['mercure_topic_prefix'] ) : 'doughboss';

		// ntfy push notifications.
		$clean['ntfy_enabled']  = empty( $input['ntfy_enabled'] ) ? 0 : 1;
		$clean['ntfy_server']   = isset( $input['ntfy_server'] ) ? esc_url_raw( trim( (string) $input['ntfy_server'] ) ) : 'https://ntfy.sh';
		$clean['ntfy_topic']    = isset( $input['ntfy_topic'] ) ? sanitize_text_field( $input['ntfy_topic'] ) : '';
		$clean['ntfy_token']    = $this->keep_secret( $input, $existing, 'ntfy_token' );
		$clean['ntfy_priority'] = ( isset( $input['ntfy_priority'] ) && in_array( $input['ntfy_priority'], array( 'high', 'default', 'low' ), true ) ) ? $input['ntfy_priority'] : 'high';

		// SMS (ClickSend).
		$clean['sms_enabled']          = empty( $input['sms_enabled'] ) ? 0 : 1;
		$clean['clicksend_username']   = isset( $input['clicksend_username'] ) ? sanitize_text_field( $input['clicksend_username'] ) : '';
		$clean['clicksend_api_key']    = $this->keep_secret( $input, $existing, 'clicksend_api_key' );
		$clean['sms_from']             = isset( $input['sms_from'] ) ? sanitize_text_field( $input['sms_from'] ) : '';
		$clean['sms_on_ready']         = empty( $input['sms_on_ready'] ) ? 0 : 1;
		$clean['sms_on_voucher_claim'] = empty( $input['sms_on_voucher_claim'] ) ? 0 : 1;

		// Receipt printer (CloudPRNT / ePOS).
		$clean['printer_enabled']  = empty( $input['printer_enabled'] ) ? 0 : 1;
		$clean['printer_protocol'] = ( isset( $input['printer_protocol'] ) && 'epos' === $input['printer_protocol'] ) ? 'epos' : 'cloudprnt';
		$clean['printer_token']    = $this->keep_secret( $input, $existing, 'printer_token' );
		$clean['printer_width']    = isset( $input['printer_width'] ) ? max( 1, absint( $input['printer_width'] ) ) : 48;

		$clean['sizes']    = $this->sanitize_rows( isset( $input['sizes'] ) ? $input['sizes'] : array() );
		$clean['toppings'] = $this->sanitize_rows( isset( $input['toppings'] ) ? $input['toppings'] : array() );

		return $clean;
	}

	/**
	 * Keep-current handling for a write-only secret field. Phase 2 secret inputs
	 * render blank (never echoing the stored secret back to the browser), so an
	 * empty submission means "leave unchanged" — we preserve the previously stored
	 * value rather than overwriting it with ''. A non-empty submission replaces it.
	 *
	 * @param array  $input    Raw submitted settings.
	 * @param array  $existing Currently stored settings (merged over defaults).
	 * @param string $key      Secret setting key.
	 * @return string
	 */
	private function keep_secret( $input, $existing, $key ) {
		$submitted = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
		if ( '' === $submitted ) {
			return isset( $existing[ $key ] ) ? (string) $existing[ $key ] : '';
		}
		return sanitize_text_field( $submitted );
	}

	/**
	 * Sanitize a repeatable list of {label, price} rows into {slug,label,price}.
	 *
	 * @param mixed $rows Raw rows.
	 * @return array[]
	 */
	private function sanitize_rows( $rows ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$clean = array();
		$seen  = array();

		foreach ( $rows as $row ) {
			if ( empty( $row['label'] ) ) {
				continue;
			}
			$label = sanitize_text_field( $row['label'] );
			$slug  = sanitize_title( $label );
			if ( '' === $slug || isset( $seen[ $slug ] ) ) {
				$slug = $slug ? $slug . '-' . wp_rand( 100, 999 ) : 'item-' . wp_rand( 100, 999 );
			}
			$seen[ $slug ] = true;

			$clean[] = array(
				'slug'  => $slug,
				'label' => $label,
				'price' => isset( $row['price'] ) ? max( 0, round( (float) $row['price'], 2 ) ) : 0,
			);
		}

		return $clean;
	}

	/**
	 * Enqueue admin assets on our screens only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( $hook, 'doughboss' ) ) {
			return;
		}

		wp_enqueue_style(
			'doughboss-admin',
			DOUGHBOSS_PLUGIN_URL . 'public/css/doughboss-admin.css',
			array(),
			DOUGHBOSS_VERSION
		);

		// Small inline script for live status changes & settings repeaters.
		wp_register_script( 'doughboss-admin', false, array(), DOUGHBOSS_VERSION, true );
		wp_enqueue_script( 'doughboss-admin' );
		wp_localize_script(
			'doughboss-admin',
			'DoughBossAdmin',
			array(
				'restUrl' => esc_url_raw( rest_url( DOUGHBOSS_REST_NAMESPACE ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
		wp_add_inline_script( 'doughboss-admin', $this->inline_admin_js() );

		// The voucher scanner ships its own modern dashboard app + styles, loaded
		// only on its screen.
		if ( false !== strpos( $hook, 'doughboss-scan' ) ) {
			wp_enqueue_style(
				'doughboss-voucher-scan',
				DOUGHBOSS_PLUGIN_URL . 'public/css/doughboss-voucher-scan.css',
				array(),
				DOUGHBOSS_VERSION
			);
			wp_enqueue_script(
				'doughboss-voucher-scan',
				DOUGHBOSS_PLUGIN_URL . 'public/js/doughboss-voucher-scan.js',
				array(),
				DOUGHBOSS_VERSION,
				true
			);
			wp_localize_script(
				'doughboss-voucher-scan',
				'DoughBossScan',
				array(
					'restUrl'  => esc_url_raw( rest_url( DOUGHBOSS_REST_NAMESPACE ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'currency' => DoughBoss_Settings::get( 'currency_symbol', '$' ),
				)
			);
		}

		// The live order board ships its own (larger) app + styles, loaded only
		// on its screen.
		if ( false !== strpos( $hook, 'doughboss-board' ) ) {
			wp_enqueue_style(
				'doughboss-orderboard',
				DOUGHBOSS_PLUGIN_URL . 'public/css/doughboss-orderboard.css',
				array(),
				DOUGHBOSS_VERSION
			);
			wp_enqueue_script(
				'doughboss-orderboard',
				DOUGHBOSS_PLUGIN_URL . 'public/js/doughboss-orderboard.js',
				array(),
				DOUGHBOSS_VERSION,
				true
			);
			wp_localize_script(
				'doughboss-orderboard',
				'DoughBossBoard',
				array(
					'restUrl'   => esc_url_raw( rest_url( DOUGHBOSS_REST_NAMESPACE ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'currency'  => DoughBoss_Settings::get( 'currency_symbol', '$' ),
					'pollMs'    => 7000,
					'statuses'  => DoughBoss_Order::statuses(),
					'locations' => $this->board_locations(),
					// Mercure real-time config (public hub URL + topic only; never the
					// publish JWT). Empty/disabled => the board stays on its poll.
					'mercure'   => DoughBoss_Mercure::js_config(),
				)
			);
		}
	}

	/**
	 * Compact list of shops for the board's shop filter.
	 *
	 * @return array<int,array{id:int,name:string}>
	 */
	private function board_locations() {
		$out = array();
		foreach ( DoughBoss_Locations::all() as $loc ) {
			$out[] = array( 'id' => (int) $loc->id, 'name' => $loc->name );
		}
		return $out;
	}

	/**
	 * Inline JS powering the orders status dropdowns and settings repeaters.
	 *
	 * @return string
	 */
	private function inline_admin_js() {
		return <<<'JS'
(function () {
	document.addEventListener('change', function (e) {
		var sel = e.target;
		var isOrder = sel.matches('.db-status-select');
		var isCatering = sel.matches('.db-catering-status');
		if (!isOrder && !isCatering) { return; }
		var id = sel.getAttribute(isOrder ? 'data-order' : 'data-enquiry');
		var endpoint = isOrder
			? DoughBossAdmin.restUrl + '/admin/order/' + id + '/status'
			: DoughBossAdmin.restUrl + '/admin/catering/' + id + '/status';
		sel.disabled = true;
		fetch(endpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': DoughBossAdmin.nonce },
			body: JSON.stringify({ status: sel.value })
		}).then(function (r) { return r.json(); }).then(function () {
			sel.disabled = false;
			var row = sel.closest('tr');
			if (row) { row.style.transition = 'background .6s'; row.style.background = '#eaffea'; setTimeout(function(){ row.style.background=''; }, 800); }
		}).catch(function () { sel.disabled = false; alert('Could not update status.'); });
	});

	document.addEventListener('click', function (e) {
		var addBtn = e.target.closest('.db-add-row');
		if (addBtn) {
			e.preventDefault();
			var body = document.querySelector('#' + addBtn.getAttribute('data-target') + ' tbody');
			var tpl = body.querySelector('tr');
			var clone = tpl.cloneNode(true);
			clone.querySelectorAll('input').forEach(function (i) { i.value = ''; });
			body.appendChild(clone);
			return;
		}
		var removeBtn = e.target.closest('.db-remove-row');
		if (removeBtn) {
			e.preventDefault();
			var tr = removeBtn.closest('tr');
			var tbody = tr.parentNode;
			if (tbody.querySelectorAll('tr').length > 1) { tr.remove(); }
			else { tr.querySelectorAll('input').forEach(function (i) { i.value = ''; }); }
			return;
		}

		var testBtn = e.target.closest('#db-mercure-test');
		if (testBtn) {
			e.preventDefault();
			var out = document.getElementById('db-mercure-test-result');
			testBtn.disabled = true;
			if (out) { out.textContent = '…'; out.style.color = ''; }
			fetch(DoughBossAdmin.restUrl + '/mercure/test', {
				headers: { 'X-WP-Nonce': DoughBossAdmin.nonce }
			}).then(function (r) { return r.json(); }).then(function (d) {
				testBtn.disabled = false;
				if (!out) { return; }
				out.textContent = (d && d.message) ? d.message : 'Done.';
				out.style.color = (d && d.ok) ? '#1f7a37' : '#b32d2e';
			}).catch(function () {
				testBtn.disabled = false;
				if (out) { out.textContent = 'Request failed.'; out.style.color = '#b32d2e'; }
			});
		}

		var pvBtn = e.target.closest('#db-pospal-verify');
		if (pvBtn) {
			e.preventDefault();
			var pvOut = document.getElementById('db-pospal-verify-result');
			pvBtn.disabled = true;
			if (pvOut) { pvOut.textContent = '…'; pvOut.style.color = ''; }
			fetch(DoughBossAdmin.restUrl + '/pospal/verify-coupons?store=' + encodeURIComponent((document.getElementById('db-pospal-store') || {}).value || '1'), {
				headers: { 'X-WP-Nonce': DoughBossAdmin.nonce }
			}).then(function (r) { return r.json(); }).then(function (d) {
				pvBtn.disabled = false;
				if (!pvOut) { return; }
				pvOut.textContent = (d && d.message) ? d.message : 'Done.';
				pvOut.style.color = (d && d.ok) ? '#1f7a37' : '#b32d2e';
			}).catch(function () {
				pvBtn.disabled = false;
				if (pvOut) { pvOut.textContent = 'Request failed.'; pvOut.style.color = '#b32d2e'; }
			});
		}

		var tgBtn = e.target.closest('#db-pospal-test-grant');
		if (tgBtn) {
			e.preventDefault();
			var tgOut = document.getElementById('db-pospal-test-result');
			var phoneEl = document.getElementById('db-pospal-test-phone');
			var valEl = document.getElementById('db-pospal-test-value');
			tgBtn.disabled = true;
			if (tgOut) { tgOut.textContent = '…'; tgOut.style.color = ''; }
			fetch(DoughBossAdmin.restUrl + '/pospal/test-grant', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': DoughBossAdmin.nonce },
				body: JSON.stringify({ phone: phoneEl ? phoneEl.value : '', value: valEl ? valEl.value : '5', store: (document.getElementById('db-pospal-store') || {}).value || '1' })
			}).then(function (r) { return r.json(); }).then(function (d) {
				tgBtn.disabled = false;
				if (tgOut) {
					tgOut.textContent = (d && d.message ? d.message : 'Done.') + (d && d.response ? '\n' + JSON.stringify(d.response) : '');
					tgOut.style.color = (d && d.ok) ? '#1f7a37' : '#b32d2e';
				}
				var rev = document.getElementById('db-pospal-test-revoke');
				if (rev && d && d.ok && d.member_uid && d.coupon_ref) {
					rev.style.display = '';
					rev.setAttribute('data-uid', d.member_uid);
					rev.setAttribute('data-ref', d.coupon_ref);
				}
			}).catch(function () {
				tgBtn.disabled = false;
				if (tgOut) { tgOut.textContent = 'Request failed.'; tgOut.style.color = '#b32d2e'; }
			});
		}

		var pbBtn = e.target.closest('#db-pospal-probe');
		if (pbBtn) {
			e.preventDefault();
			var pbOut = document.getElementById('db-pospal-test-result');
			var pbPhone = document.getElementById('db-pospal-test-phone');
			var pbVal = document.getElementById('db-pospal-test-value');
			pbBtn.disabled = true;
			if (pbOut) { pbOut.textContent = 'Probing…'; pbOut.style.color = ''; }
			fetch(DoughBossAdmin.restUrl + '/pospal/probe-grant', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': DoughBossAdmin.nonce },
				body: JSON.stringify({ phone: pbPhone ? pbPhone.value : '', value: pbVal ? pbVal.value : '5', store: (document.getElementById('db-pospal-store') || {}).value || '1' })
			}).then(function (r) { return r.json(); }).then(function (d) {
				pbBtn.disabled = false;
				if (pbOut) {
					pbOut.textContent = (d && d.message) ? d.message : 'Done.';
					pbOut.style.color = (d && d.ok) ? '#1f7a37' : '#b32d2e';
				}
			}).catch(function () {
				pbBtn.disabled = false;
				if (pbOut) { pbOut.textContent = 'Request failed.'; pbOut.style.color = '#b32d2e'; }
			});
		}

		var trBtn = e.target.closest('#db-pospal-test-revoke');
		if (trBtn) {
			e.preventDefault();
			var trOut = document.getElementById('db-pospal-test-result');
			trBtn.disabled = true;
			fetch(DoughBossAdmin.restUrl + '/pospal/test-revoke', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': DoughBossAdmin.nonce },
				body: JSON.stringify({ customer_uid: trBtn.getAttribute('data-uid'), coupon_ref: trBtn.getAttribute('data-ref'), store: (document.getElementById('db-pospal-store') || {}).value || '1' })
			}).then(function (r) { return r.json(); }).then(function (d) {
				trBtn.disabled = false;
				if (trOut) { trOut.textContent = (d && d.message) ? d.message : 'Done.'; trOut.style.color = (d && d.ok) ? '#1f7a37' : '#b32d2e'; }
			}).catch(function () {
				trBtn.disabled = false;
				if (trOut) { trOut.textContent = 'Request failed.'; trOut.style.color = '#b32d2e'; }
			});
		}
	});
}());
JS;
	}

	/**
	 * Render the Orders management page.
	 *
	 * @return void
	 */
	public function render_orders_page() {
		if ( ! current_user_can( $this->cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}

		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$per_page = 20;
		$result   = DoughBoss_Order::query(
			array(
				'status'   => $status,
				'search'   => $search,
				'per_page' => $per_page,
				'page'     => $paged,
			)
		);

		$statuses    = DoughBoss_Order::statuses();
		$total_pages = (int) ceil( $result['total'] / $per_page );
		?>
		<div class="wrap doughboss-orders">
			<h1><?php esc_html_e( 'Orders', 'doughboss' ); ?></h1>

			<form method="get" class="db-orders-filter">
				<input type="hidden" name="page" value="doughboss" />
				<select name="status">
					<option value=""><?php esc_html_e( 'All statuses', 'doughboss' ); ?></option>
					<?php foreach ( $statuses as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search orders…', 'doughboss' ); ?>" />
				<button class="button"><?php esc_html_e( 'Filter', 'doughboss' ); ?></button>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Order #', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Type', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Items', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Total', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Placed', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Status', 'doughboss' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $result['items'] ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No orders yet.', 'doughboss' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $result['items'] as $order ) : ?>
							<?php $items = DoughBoss_Order::get_items( $order->id ); ?>
							<tr>
								<td><strong><?php echo esc_html( $order->order_number ); ?></strong></td>
								<td>
									<?php echo esc_html( $order->customer_name ); ?><br />
									<small><?php echo esc_html( $order->customer_email ); ?></small><br />
									<small><?php echo esc_html( $order->customer_phone ); ?></small>
								</td>
								<td><?php echo esc_html( ucfirst( $order->order_type ) ); ?></td>
								<td>
									<ul class="db-item-list">
										<?php foreach ( $items as $item ) : ?>
											<li>
												<?php echo esc_html( $item['quantity'] . '× ' . $item['name'] ); ?>
												<?php if ( ! empty( $item['toppings'] ) ) : ?>
													<small>(<?php echo esc_html( implode( ', ', wp_list_pluck( $item['toppings'], 'label' ) ) ); ?>)</small>
												<?php endif; ?>
											</li>
										<?php endforeach; ?>
									</ul>
								</td>
								<td>
									<?php echo esc_html( DoughBoss_Settings::format_price( $order->total ) ); ?>
									<?php if ( isset( $order->discount ) && (float) $order->discount > 0 ) : ?>
										<br /><small style="color:#1f8a54;">
										<?php
										printf(
											/* translators: 1: discount amount, 2: voucher code. */
											esc_html__( '−%1$s voucher %2$s', 'doughboss' ),
											esc_html( DoughBoss_Settings::format_price( (float) $order->discount ) ),
											esc_html( $order->voucher_code )
										);
										?>
										</small>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( mysql2date( 'M j, g:i a', $order->created_at ) ); ?></td>
								<td>
									<select class="db-status-select" data-order="<?php echo esc_attr( $order->id ); ?>">
										<?php foreach ( $statuses as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $order->status, $key ); ?>>
												<?php echo esc_html( $label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $total_pages,
								'prev_text' => '‹',
								'next_text' => '›',
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Catering Enquiries management page.
	 *
	 * @return void
	 */
	public function render_catering_page() {
		if ( ! current_user_can( $this->cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}

		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$per_page = 20;
		$result   = DoughBoss_Catering::query(
			array(
				'status'   => $status,
				'search'   => $search,
				'per_page' => $per_page,
				'page'     => $paged,
			)
		);

		$statuses    = DoughBoss_Catering::statuses();
		$total_pages = (int) ceil( $result['total'] / $per_page );
		?>
		<div class="wrap doughboss-orders">
			<h1><?php esc_html_e( 'Catering Enquiries', 'doughboss' ); ?></h1>

			<form method="get" class="db-orders-filter">
				<input type="hidden" name="page" value="doughboss-catering" />
				<select name="status">
					<option value=""><?php esc_html_e( 'All statuses', 'doughboss' ); ?></option>
					<?php foreach ( $statuses as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search enquiries…', 'doughboss' ); ?>" />
				<button class="button"><?php esc_html_e( 'Filter', 'doughboss' ); ?></button>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Enquiry #', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Event', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Package', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Guests', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Quote / Deposit', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Received', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Status', 'doughboss' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $result['items'] ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'No catering enquiries yet.', 'doughboss' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $result['items'] as $row ) : ?>
							<?php
							$package = (int) $row['package_id'] ? get_the_title( (int) $row['package_id'] ) : __( 'Custom', 'doughboss' );
							$event   = '';
							if ( ! empty( $row['event_date'] ) ) {
								$event = mysql2date( 'M j, Y', $row['event_date'] );
								if ( ! empty( $row['event_time'] ) ) {
									$event .= ' · ' . $row['event_time'];
								}
							}
							?>
							<tr>
								<td><strong><?php echo esc_html( $row['enquiry_number'] ); ?></strong><br /><small><?php echo esc_html( ucfirst( $row['order_type'] ) ); ?></small></td>
								<td>
									<?php echo esc_html( $row['customer_name'] ); ?><br />
									<small><?php echo esc_html( $row['customer_email'] ); ?></small><br />
									<small><?php echo esc_html( $row['customer_phone'] ); ?></small>
								</td>
								<td><?php echo $event ? esc_html( $event ) : '—'; ?></td>
								<td><?php echo esc_html( $package ? $package : '—' ); ?></td>
								<td><?php echo esc_html( (int) $row['guest_count'] ? (string) (int) $row['guest_count'] : '—' ); ?></td>
								<td>
									<?php echo esc_html( DoughBoss_Settings::format_price( $row['quote_total'] ) ); ?><br />
									<small><?php echo esc_html( DoughBoss_Settings::format_price( $row['deposit_amount'] ) ); ?> <?php esc_html_e( 'deposit', 'doughboss' ); ?></small>
								</td>
								<td><?php echo esc_html( mysql2date( 'M j, g:i a', $row['created_at'] ) ); ?></td>
								<td>
									<select class="db-catering-status" data-enquiry="<?php echo esc_attr( $row['id'] ); ?>">
										<?php foreach ( $statuses as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $row['status'], $key ); ?>>
												<?php echo esc_html( $label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $total_pages,
								'prev_text' => '‹',
								'next_text' => '›',
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the live Order Board screen. The board app (JS) fills #db-board.
	 *
	 * @return void
	 */
	public function render_board_page() {
		if ( ! current_user_can( 'manage_doughboss_kds' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}
		?>
		<div class="wrap doughboss-board-wrap">
			<div class="db-board-bar">
				<h1><?php esc_html_e( 'Live Order Board', 'doughboss' ); ?></h1>
				<div class="db-board-actions">
					<span class="db-board-status" role="status" aria-live="polite"></span>
					<button type="button" class="button db-sound-toggle" aria-pressed="false">
						<?php esc_html_e( '🔔 Enable sound alerts', 'doughboss' ); ?>
					</button>
				</div>
			</div>
			<div id="db-board" class="db-board" aria-live="polite">
				<p class="db-board-loading"><?php esc_html_e( 'Loading orders…', 'doughboss' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Shops / Locations management screen (list + add/edit form).
	 *
	 * @return void
	 */
	public function render_locations_page() {
		if ( ! current_user_can( $this->cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$editing = $edit_id ? DoughBoss_Locations::get( $edit_id ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg     = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';

		$f = function ( $key, $default = '' ) use ( $editing ) {
			return $editing && isset( $editing->$key ) ? $editing->$key : $default;
		};
		?>
		<div class="wrap doughboss-locations">
			<h1><?php esc_html_e( 'Shops / Locations', 'doughboss' ); ?></h1>
			<?php if ( 'saved' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Shop saved.', 'doughboss' ); ?></p></div>
			<?php elseif ( 'deleted' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Shop deleted.', 'doughboss' ); ?></p></div>
			<?php endif; ?>

			<table class="wp-list-table widefat fixed striped" style="margin-bottom:1.5rem;">
				<thead><tr>
					<th><?php esc_html_e( 'Shop', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Suburb', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Fulfilment', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Active', 'doughboss' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
					<?php $rows = DoughBoss_Locations::all(); ?>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No shops yet. Add your first one below.', 'doughboss' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $loc ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $loc->name ); ?></strong><br /><small><?php echo esc_html( $loc->phone ); ?></small></td>
								<td><?php echo esc_html( $loc->suburb ); ?></td>
								<td>
									<?php
									$ful = array();
									if ( $loc->pickup_enabled ) {
										$ful[] = __( 'Pickup', 'doughboss' );
									}
									if ( $loc->delivery_enabled ) {
										$ful[] = __( 'Delivery', 'doughboss' );
									}
									echo esc_html( $ful ? implode( ' + ', $ful ) : '—' );
									?>
								</td>
								<td><?php echo $loc->is_active ? '✓' : '—'; ?></td>
								<td>
									<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'doughboss-locations', 'edit' => $loc->id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'doughboss' ); ?></a> |
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=doughboss_delete_location&id=' . $loc->id ), 'doughboss_delete_location_' . $loc->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this shop?', 'doughboss' ) ); ?>');" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'doughboss' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php echo $editing ? esc_html__( 'Edit shop', 'doughboss' ) : esc_html__( 'Add a shop', 'doughboss' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="doughboss_save_location" />
				<input type="hidden" name="id" value="<?php echo esc_attr( (int) $f( 'id', 0 ) ); ?>" />
				<?php wp_nonce_field( 'doughboss_save_location' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="db-loc-name"><?php esc_html_e( 'Name', 'doughboss' ); ?></label></th>
						<td><input name="name" id="db-loc-name" type="text" class="regular-text" required value="<?php echo esc_attr( $f( 'name' ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="db-loc-suburb"><?php esc_html_e( 'Suburb', 'doughboss' ); ?></label></th>
						<td><input name="suburb" id="db-loc-suburb" type="text" class="regular-text" value="<?php echo esc_attr( $f( 'suburb' ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="db-loc-address"><?php esc_html_e( 'Address', 'doughboss' ); ?></label></th>
						<td><textarea name="address" id="db-loc-address" class="large-text" rows="2"><?php echo esc_textarea( $f( 'address' ) ); ?></textarea></td>
					</tr>
					<tr>
						<th><label for="db-loc-phone"><?php esc_html_e( 'Phone', 'doughboss' ); ?></label></th>
						<td><input name="phone" id="db-loc-phone" type="text" class="regular-text" value="<?php echo esc_attr( $f( 'phone' ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="db-loc-postcodes"><?php esc_html_e( 'Delivery postcodes', 'doughboss' ); ?></label></th>
						<td><input name="postcodes" id="db-loc-postcodes" type="text" class="regular-text" value="<?php echo esc_attr( $f( 'postcodes' ) ); ?>" /><p class="description"><?php esc_html_e( 'Comma-separated, used to route delivery orders to this shop.', 'doughboss' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="db-loc-prep"><?php esc_html_e( 'Default prep time (min)', 'doughboss' ); ?></label></th>
						<td><input name="prep_time_default" id="db-loc-prep" type="number" min="0" class="small-text" value="<?php echo esc_attr( $f( 'prep_time_default', 20 ) ); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Fulfilment', 'doughboss' ); ?></th>
						<td>
							<label><input type="checkbox" name="pickup_enabled" value="1" <?php checked( $editing ? $editing->pickup_enabled : 1, 1 ); ?> /> <?php esc_html_e( 'Pickup', 'doughboss' ); ?></label><br />
							<label><input type="checkbox" name="delivery_enabled" value="1" <?php checked( $editing ? $editing->delivery_enabled : 0, 1 ); ?> /> <?php esc_html_e( 'Delivery', 'doughboss' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Active', 'doughboss' ); ?></th>
						<td><label><input type="checkbox" name="is_active" value="1" <?php checked( $editing ? $editing->is_active : 1, 1 ); ?> /> <?php esc_html_e( 'Accept orders for this shop', 'doughboss' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button( $editing ? __( 'Update shop', 'doughboss' ) : __( 'Add shop', 'doughboss' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the add/edit shop form submission.
	 *
	 * @return void
	 */
	public function handle_save_location() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_save_location' );

		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$data = array(
			'name'              => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
			'suburb'            => isset( $_POST['suburb'] ) ? wp_unslash( $_POST['suburb'] ) : '',
			'address'           => isset( $_POST['address'] ) ? wp_unslash( $_POST['address'] ) : '',
			'phone'             => isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '',
			'postcodes'         => isset( $_POST['postcodes'] ) ? wp_unslash( $_POST['postcodes'] ) : '',
			'prep_time_default' => isset( $_POST['prep_time_default'] ) ? (int) $_POST['prep_time_default'] : 20,
			'pickup_enabled'    => isset( $_POST['pickup_enabled'] ) ? 1 : 0,
			'delivery_enabled'  => isset( $_POST['delivery_enabled'] ) ? 1 : 0,
			'is_active'         => isset( $_POST['is_active'] ) ? 1 : 0,
		);

		if ( $id ) {
			DoughBoss_Locations::update( $id, $data );
		} else {
			DoughBoss_Locations::create( $data );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'doughboss-locations', 'msg' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle deleting a shop.
	 *
	 * @return void
	 */
	public function handle_delete_location() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_delete_location_' . $id );

		if ( $id ) {
			DoughBoss_Locations::delete( $id );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'doughboss-locations', 'msg' => 'deleted' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the Vouchers management screen: daily-campaign tracking, a create
	 * form, and a recent-vouchers list with redemption status + void.
	 *
	 * @return void
	 */
	public function render_scan_page() {
		if ( ! current_user_can( $this->scan_cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}
		?>
		<div class="wrap db-scan-wrap">
			<div id="doughboss-scan-app" class="db-scan" aria-live="polite">
				<noscript><?php esc_html_e( 'The voucher scanner needs JavaScript enabled.', 'doughboss' ); ?></noscript>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the owner Vouchers management screen (create, void, campaign
	 * tracker, full list).
	 *
	 * @return void
	 */
	public function render_vouchers_page() {
		if ( ! current_user_can( $this->cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg       = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$new_code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$campaigns = DoughBoss_Voucher::campaigns();
		$vouchers  = DoughBoss_Voucher::query( 100 );
		?>
		<div class="wrap doughboss-vouchers">
			<h1><?php esc_html_e( 'Vouchers', 'doughboss' ); ?></h1>
			<?php if ( 'issued' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Voucher created:', 'doughboss' ); ?> <code><?php echo esc_html( $new_code ); ?></code></p></div>
			<?php elseif ( 'voided' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Voucher voided.', 'doughboss' ); ?></p></div>
			<?php elseif ( 'error' === $msg ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not create the voucher — check the value and try again.', 'doughboss' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Daily campaigns', 'doughboss' ); ?></h2>
			<table class="wp-list-table widefat fixed striped" style="margin-bottom:1.5rem;max-width:820px;">
				<thead><tr>
					<th><?php esc_html_e( 'Campaign', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Value', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Daily cap', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Claimed today', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Remaining', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Active', 'doughboss' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $campaigns as $c ) : ?>
					<?php
					$cap       = (int) ( isset( $c['daily_cap'] ) ? $c['daily_cap'] : 0 );
					$shared    = ! empty( $c['cap_group'] );
					$claimed   = DoughBoss_Voucher::claimed_today( $c['slug'] );
					$pool_used = DoughBoss_Voucher::claimed_today_for( $c );
					$remaining = $cap > 0 ? (string) max( 0, $cap - $pool_used ) : '∞';
					$cap_label = $cap > 0 ? (string) $cap . ( $shared ? ' ' . __( '(shared)', 'doughboss' ) : '' ) : '∞';
					?>
					<tr>
						<td><strong><?php echo esc_html( $c['label'] ); ?></strong><br /><small><?php echo esc_html( $c['slug'] ); ?></small></td>
						<td><?php echo esc_html( 'percent' === $c['type'] ? $c['value'] . '%' : DoughBoss_Settings::format_price( $c['value'] ) ); ?></td>
						<td><?php echo esc_html( $cap_label ); ?></td>
						<td><?php echo esc_html( (string) $claimed ); ?></td>
						<td><?php echo esc_html( $remaining ); ?></td>
						<td><?php echo empty( $c['active'] ) ? '—' : '✓'; ?></td>
					</tr>
				<?php endforeach; ?>
				<?php
				// When campaigns share a daily pool, show the combined total so the
				// "Remaining" columns (which all show the same shared figure) read clearly.
				$groups = array();
				foreach ( $campaigns as $c ) {
					if ( ! empty( $c['cap_group'] ) ) {
						$groups[ $c['cap_group'] ] = (int) ( isset( $c['daily_cap'] ) ? $c['daily_cap'] : 0 );
					}
				}
				foreach ( $groups as $g => $g_cap ) {
					$g_used = DoughBoss_Voucher::claimed_today_for( array( 'cap_group' => $g ) );
					?>
					<tr style="background:#f6f7f7;">
						<td colspan="3"><em><?php printf( esc_html__( 'Shared daily pool (%s)', 'doughboss' ), esc_html( $g ) ); ?></em></td>
						<td><?php echo esc_html( (string) $g_used ); ?></td>
						<td><?php echo esc_html( $g_cap > 0 ? (string) max( 0, $g_cap - $g_used ) : '∞' ); ?></td>
						<td>—</td>
					</tr>
					<?php
				}
				?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Create a voucher', 'doughboss' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="doughboss_issue_voucher" />
				<?php wp_nonce_field( 'doughboss_issue_voucher' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="db-v-type"><?php esc_html_e( 'Type', 'doughboss' ); ?></label></th>
						<td><select name="type" id="db-v-type">
							<option value="amount"><?php esc_html_e( 'Amount off ($)', 'doughboss' ); ?></option>
							<option value="percent"><?php esc_html_e( 'Percent off (%)', 'doughboss' ); ?></option>
						</select></td>
					</tr>
					<tr>
						<th><label for="db-v-value"><?php esc_html_e( 'Value', 'doughboss' ); ?></label></th>
						<td><input name="value" id="db-v-value" type="number" step="0.01" min="0" class="small-text" required /></td>
					</tr>
					<tr>
						<th><label for="db-v-prefix"><?php esc_html_e( 'Code prefix', 'doughboss' ); ?></label></th>
						<td><input name="prefix" id="db-v-prefix" type="text" class="regular-text" value="SNOW" /></td>
					</tr>
					<tr>
						<th><label for="db-v-min"><?php esc_html_e( 'Minimum spend', 'doughboss' ); ?></label></th>
						<td><input name="min_spend" id="db-v-min" type="number" step="0.01" min="0" class="small-text" value="0" /></td>
					</tr>
					<tr>
						<th><label for="db-v-scope"><?php esc_html_e( 'Where', 'doughboss' ); ?></label></th>
						<td><select name="scope" id="db-v-scope">
							<option value="both"><?php esc_html_e( 'Online & in-store', 'doughboss' ); ?></option>
							<option value="online"><?php esc_html_e( 'Online only', 'doughboss' ); ?></option>
							<option value="instore"><?php esc_html_e( 'In-store only', 'doughboss' ); ?></option>
						</select></td>
					</tr>
					<tr>
						<th><label for="db-v-to"><?php esc_html_e( 'Valid until', 'doughboss' ); ?></label></th>
						<td><input name="valid_to" id="db-v-to" type="date" /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Create voucher', 'doughboss' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Recent vouchers', 'doughboss' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr>
					<th><?php esc_html_e( 'Code', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Discount', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Campaign', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Status', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Created', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Redeemed', 'doughboss' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $vouchers ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No vouchers yet. Create one above, or they appear here as customers claim them.', 'doughboss' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $vouchers as $v ) : ?>
						<tr>
							<td><code><?php echo esc_html( $v->code ); ?></code></td>
							<td><?php echo esc_html( 'percent' === $v->type ? $v->value . '%' : DoughBoss_Settings::format_price( $v->value ) ); ?></td>
							<td><?php echo esc_html( $v->campaign ? $v->campaign : '—' ); ?></td>
							<td><?php echo esc_html( ucfirst( $v->status ) ); ?></td>
							<td><?php echo esc_html( $v->customer_phone ? $v->customer_phone : ( $v->customer_email ? $v->customer_email : '—' ) ); ?></td>
							<td><?php echo esc_html( mysql2date( 'j M, g:ia', $v->created_at ) ); ?></td>
							<td><?php echo $v->redeemed_at ? esc_html( mysql2date( 'j M, g:ia', $v->redeemed_at ) . ' · ' . $v->redeemed_channel ) : '—'; ?></td>
							<td>
								<?php if ( 'issued' === $v->status ) : ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=doughboss_void_voucher&id=' . $v->id ), 'doughboss_void_voucher_' . $v->id ) ); ?>" style="color:#b32d2e;" onclick="return confirm('<?php echo esc_js( __( 'Void this voucher?', 'doughboss' ) ); ?>');"><?php esc_html_e( 'Void', 'doughboss' ); ?></a>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Handle the create-voucher form submission.
	 *
	 * @return void
	 */
	public function handle_issue_voucher() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_issue_voucher' );

		$result = DoughBoss_Voucher::issue(
			array(
				'type'      => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'amount',
				'value'     => isset( $_POST['value'] ) ? (float) wp_unslash( $_POST['value'] ) : 0,
				'prefix'    => isset( $_POST['prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['prefix'] ) ) : 'DB',
				'min_spend' => isset( $_POST['min_spend'] ) ? (float) wp_unslash( $_POST['min_spend'] ) : 0,
				'scope'     => isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'both',
				'valid_to'  => ( isset( $_POST['valid_to'] ) && '' !== $_POST['valid_to'] ) ? sanitize_text_field( wp_unslash( $_POST['valid_to'] ) ) . ' 23:59:59' : '',
			)
		);

		$args = array( 'page' => 'doughboss-vouchers' );
		if ( is_wp_error( $result ) ) {
			$args['msg'] = 'error';
		} else {
			$args['msg']  = 'issued';
			$args['code'] = $result['code'];
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle voiding an unredeemed voucher.
	 *
	 * @return void
	 */
	public function handle_void_voucher() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_void_voucher_' . $id );
		if ( $id ) {
			DoughBoss_Voucher::void( $id );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'doughboss-vouchers', 'msg' => 'voided' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the Settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( $this->cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}

		$settings = DoughBoss_Settings::all();
		?>
		<div class="wrap doughboss-settings">
			<h1><?php esc_html_e( 'DoughBoss Settings', 'doughboss' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::SETTINGS_GROUP ); ?>
				<?php $opt = DoughBoss_Settings::OPTION_KEY; ?>

				<h2><?php esc_html_e( 'Store', 'doughboss' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="db-ordering-open"><?php esc_html_e( 'Accept orders', 'doughboss' ); ?></label></th>
						<td><input type="checkbox" id="db-ordering-open" name="<?php echo esc_attr( $opt ); ?>[ordering_open]" value="1" <?php checked( $settings['ordering_open'], 1 ); ?> />
							<span class="description"><?php esc_html_e( 'Uncheck to temporarily pause online ordering.', 'doughboss' ); ?></span></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Fulfilment', 'doughboss' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enable_pickup]" value="1" <?php checked( $settings['enable_pickup'], 1 ); ?> /> <?php esc_html_e( 'Pickup', 'doughboss' ); ?></label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enable_delivery]" value="1" <?php checked( $settings['enable_delivery'], 1 ); ?> /> <?php esc_html_e( 'Delivery', 'doughboss' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><label for="db-currency-symbol"><?php esc_html_e( 'Currency symbol', 'doughboss' ); ?></label></th>
						<td><input type="text" id="db-currency-symbol" class="small-text" name="<?php echo esc_attr( $opt ); ?>[currency_symbol]" value="<?php echo esc_attr( $settings['currency_symbol'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="db-currency-code"><?php esc_html_e( 'Currency code', 'doughboss' ); ?></label></th>
						<td><input type="text" id="db-currency-code" class="small-text" name="<?php echo esc_attr( $opt ); ?>[currency_code]" value="<?php echo esc_attr( $settings['currency_code'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="db-tax-rate"><?php esc_html_e( 'Tax / GST rate (%)', 'doughboss' ); ?></label></th>
						<td><input type="number" step="0.01" min="0" id="db-tax-rate" class="small-text" name="<?php echo esc_attr( $opt ); ?>[tax_rate]" value="<?php echo esc_attr( $settings['tax_rate'] ); ?>" />
							<span class="description"><?php esc_html_e( 'Australian GST is 10%.', 'doughboss' ); ?></span></td>
					</tr>
					<tr>
						<th><label for="db-gst-inclusive"><?php esc_html_e( 'Prices include GST', 'doughboss' ); ?></label></th>
						<td><input type="checkbox" id="db-gst-inclusive" name="<?php echo esc_attr( $opt ); ?>[gst_inclusive]" value="1" <?php checked( ! empty( $settings['gst_inclusive'] ), true ); ?> />
							<span class="description"><?php esc_html_e( 'Tick for Australia: menu prices already include GST (tax is shown as a component, not added on top).', 'doughboss' ); ?></span></td>
					</tr>
					<tr>
						<th><label for="db-delivery-fee"><?php esc_html_e( 'Delivery fee', 'doughboss' ); ?></label></th>
						<td><input type="number" step="0.01" min="0" id="db-delivery-fee" class="small-text" name="<?php echo esc_attr( $opt ); ?>[delivery_fee]" value="<?php echo esc_attr( $settings['delivery_fee'] ); ?>" /></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Pizza Sizes', 'doughboss' ); ?></h2>
				<p class="description"><?php esc_html_e( 'The base price of a plain pizza of each size. Used by the custom pizza builder.', 'doughboss' ); ?></p>
				<?php $this->render_repeater( 'sizes', $settings['sizes'], $opt ); ?>

				<h2><?php esc_html_e( 'Toppings', 'doughboss' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Each topping and the price added when selected in the builder.', 'doughboss' ); ?></p>
				<?php $this->render_repeater( 'toppings', $settings['toppings'], $opt ); ?>

				<h2><?php esc_html_e( 'Payments (Stripe)', 'doughboss' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Optional. Take card payments at checkout via Stripe. Off by default — start in Test mode with your test keys, then switch to Live. Card payments apply only once payments are on AND keys are set for the active mode.', 'doughboss' ); ?>
						<?php
						if ( ! class_exists( 'DoughBoss_Stripe' ) || ! DoughBoss_Stripe::ready() ) {
							echo ' <strong>' . esc_html__( 'Status: card payments are OFF.', 'doughboss' ) . '</strong>';
						} else {
							/* translators: %s: Stripe mode (Test or Live). */
							echo ' <strong style="color:#1f8a54;">' . esc_html( sprintf( __( 'Status: card payments are ON (%s mode).', 'doughboss' ), DoughBoss_Settings::stripe_mode() === 'live' ? __( 'Live', 'doughboss' ) : __( 'Test', 'doughboss' ) ) ) . '</strong>';
						}
						?>
					</p>
					<?php $mode = isset( $settings['stripe_mode'] ) && 'live' === $settings['stripe_mode'] ? 'live' : 'test'; ?>
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="db-payments-enabled"><?php esc_html_e( 'Accept card payments', 'doughboss' ); ?></label></th>
							<td><input type="checkbox" id="db-payments-enabled" name="<?php echo esc_attr( $opt ); ?>[payments_enabled]" value="1" <?php checked( ! empty( $settings['payments_enabled'] ), true ); ?> />
								<span class="description"><?php esc_html_e( 'When on (and keys are set for the active mode), customers pay by card before the order is placed.', 'doughboss' ); ?></span></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Mode', 'doughboss' ); ?></th>
							<td>
								<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[stripe_mode]" value="test" <?php checked( 'test' === $mode, true ); ?> /> <?php esc_html_e( 'Test', 'doughboss' ); ?></label>&nbsp;&nbsp;
								<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[stripe_mode]" value="live" <?php checked( 'live' === $mode, true ); ?> /> <?php esc_html_e( 'Live', 'doughboss' ); ?></label>
							</td>
						</tr>
						<tr>
							<th><label for="db-stripe-test-pk"><?php esc_html_e( 'Test publishable key', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-stripe-test-pk" class="regular-text" autocomplete="off" placeholder="pk_test_&hellip;" name="<?php echo esc_attr( $opt ); ?>[stripe_test_pk]" value="<?php echo esc_attr( isset( $settings['stripe_test_pk'] ) ? $settings['stripe_test_pk'] : '' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-stripe-test-sk"><?php esc_html_e( 'Test secret key', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-stripe-test-sk" class="regular-text" autocomplete="off" placeholder="sk_test_&hellip;" name="<?php echo esc_attr( $opt ); ?>[stripe_test_sk]" value="<?php echo esc_attr( isset( $settings['stripe_test_sk'] ) ? $settings['stripe_test_sk'] : '' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-stripe-live-pk"><?php esc_html_e( 'Live publishable key', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-stripe-live-pk" class="regular-text" autocomplete="off" placeholder="pk_live_&hellip;" name="<?php echo esc_attr( $opt ); ?>[stripe_live_pk]" value="<?php echo esc_attr( isset( $settings['stripe_live_pk'] ) ? $settings['stripe_live_pk'] : '' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-stripe-live-sk"><?php esc_html_e( 'Live secret key', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-stripe-live-sk" class="regular-text" autocomplete="off" placeholder="sk_live_&hellip;" name="<?php echo esc_attr( $opt ); ?>[stripe_live_sk]" value="<?php echo esc_attr( isset( $settings['stripe_live_sk'] ) ? $settings['stripe_live_sk'] : '' ); ?>" />
								<p class="description"><?php esc_html_e( 'Find your keys in the Stripe Dashboard → Developers → API keys. Secret keys are used only on the server.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="db-stripe-test-whsec"><?php esc_html_e( 'Test webhook secret', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-stripe-test-whsec" class="regular-text" autocomplete="off" placeholder="whsec_&hellip;" name="<?php echo esc_attr( $opt ); ?>[stripe_test_whsec]" value="<?php echo esc_attr( isset( $settings['stripe_test_whsec'] ) ? $settings['stripe_test_whsec'] : '' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-stripe-live-whsec"><?php esc_html_e( 'Live webhook secret', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-stripe-live-whsec" class="regular-text" autocomplete="off" placeholder="whsec_&hellip;" name="<?php echo esc_attr( $opt ); ?>[stripe_live_whsec]" value="<?php echo esc_attr( isset( $settings['stripe_live_whsec'] ) ? $settings['stripe_live_whsec'] : '' ); ?>" />
								<p class="description">
									<?php esc_html_e( 'Stripe Dashboard → Developers → Webhooks. Add an endpoint pointing to:', 'doughboss' ); ?>
									<code><?php echo esc_html( rest_url( DOUGHBOSS_REST_NAMESPACE . '/catering/stripe-webhook' ) ); ?></code>
									<?php esc_html_e( 'and subscribe to payment_intent.succeeded. Then paste its signing secret here.', 'doughboss' ); ?>
								</p></td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'POSPal POS (in-store coupons)', 'doughboss' ); ?></h2>
					<p class="description" style="max-width:760px;">
						<?php esc_html_e( 'Connect the Revesby POSPal till so issued vouchers can be mirrored as member coupons and reconciled. Off by default. Enter the area host, App ID and App Key from your POSPal Open Platform account, save, then test the connection.', 'doughboss' ); ?>
						<?php
						if ( class_exists( 'DoughBoss_POSPal' ) && DoughBoss_POSPal::ready() ) {
							echo ' <strong style="color:#1f8a54;">' . esc_html__( 'Status: POSPal is configured and enabled.', 'doughboss' ) . '</strong>';
						} else {
							echo ' <strong style="color:#a15c00;">' . esc_html__( 'Status: not connected yet.', 'doughboss' ) . '</strong>';
						}
						?>
					</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable POSPal', 'doughboss' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[pospal_enabled]" value="1" <?php checked( ! empty( $settings['pospal_enabled'] ), true ); ?> /> <?php esc_html_e( 'Connect this site to POSPal', 'doughboss' ); ?></label></td>
						</tr>
						<tr>
							<th><label for="db-pospal-host"><?php esc_html_e( 'Area host', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-pospal-host" class="regular-text" autocomplete="off" placeholder="https://area28-win.pospal.cn:443" name="<?php echo esc_attr( $opt ); ?>[pospal_host]" value="<?php echo esc_attr( isset( $settings['pospal_host'] ) ? $settings['pospal_host'] : '' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-pospal-app-id"><?php esc_html_e( 'App ID', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-pospal-app-id" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[pospal_app_id]" value="<?php echo esc_attr( isset( $settings['pospal_app_id'] ) ? $settings['pospal_app_id'] : '' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-pospal-app-key"><?php esc_html_e( 'App Key', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-pospal-app-key" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[pospal_app_key]" value="<?php echo esc_attr( isset( $settings['pospal_app_key'] ) ? $settings['pospal_app_key'] : '' ); ?>" />
								<p class="description"><?php esc_html_e( 'The secret key is used only to sign server-side calls. For best security set it as the DOUGHBOSS_POSPAL_APPKEY environment variable instead of here; this field is a fallback.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="db-pospal-uid5"><?php esc_html_e( '$5 coupon rule UID', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-pospal-uid5" class="regular-text" autocomplete="off" inputmode="numeric" placeholder="e.g. 1782386149561969589" name="<?php echo esc_attr( $opt ); ?>[pospal_coupon_uid_5]" value="<?php echo esc_attr( isset( $settings['pospal_coupon_uid_5'] ) ? $settings['pospal_coupon_uid_5'] : '' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-pospal-uid10"><?php esc_html_e( '$10 coupon rule UID', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-pospal-uid10" class="regular-text" autocomplete="off" inputmode="numeric" placeholder="e.g. 1782388334407537808" name="<?php echo esc_attr( $opt ); ?>[pospal_coupon_uid_10]" value="<?php echo esc_attr( isset( $settings['pospal_coupon_uid_10'] ) ? $settings['pospal_coupon_uid_10'] : '' ); ?>" />
								<p class="description"><?php esc_html_e( 'The POSPal coupon-rule UID for each student voucher value (copied from your POSPal coupon list). Granting stays off until at least one is set.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Verify coupons', 'doughboss' ); ?></th>
							<td>
								<select id="db-pospal-store" style="margin-right:6px;">
									<option value="1"><?php esc_html_e( 'Store 1', 'doughboss' ); ?></option>
									<option value="2"><?php esc_html_e( 'Store 2', 'doughboss' ); ?></option>
									<option value="3"><?php esc_html_e( 'Store 3', 'doughboss' ); ?></option>
								</select>
								<button type="button" class="button" id="db-pospal-verify"><?php esc_html_e( 'Verify coupon setup', 'doughboss' ); ?></button>
								<span id="db-pospal-verify-result" class="description" style="margin-left:8px;"></span>
								<p class="description"><?php esc_html_e( 'Read-only: checks the selected store\'s POSPal connection and that its UIDs match real coupon rules. This store dropdown also applies to the Test grant below. Save your changes first.', 'doughboss' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Test grant', 'doughboss' ); ?></th>
							<td>
								<input type="text" id="db-pospal-test-phone" class="regular-text" inputmode="tel" autocomplete="off" style="max-width:240px;" placeholder="<?php esc_attr_e( 'Throwaway test phone, e.g. 0400000000', 'doughboss' ); ?>" />
								<select id="db-pospal-test-value">
									<option value="5"><?php esc_html_e( '$5 coupon', 'doughboss' ); ?></option>
									<option value="10"><?php esc_html_e( '$10 coupon', 'doughboss' ); ?></option>
								</select>
								<button type="button" class="button" id="db-pospal-test-grant"><?php esc_html_e( 'Send test coupon', 'doughboss' ); ?></button>
								<button type="button" class="button" id="db-pospal-probe"><?php esc_html_e( 'Probe methods', 'doughboss' ); ?></button>
								<button type="button" class="button" id="db-pospal-test-revoke" style="display:none;"><?php esc_html_e( 'Revoke test', 'doughboss' ); ?></button>
								<p id="db-pospal-test-result" class="description" style="margin-top:8px; white-space:pre-wrap; word-break:break-word;"></p>
								<p class="description"><?php esc_html_e( 'Writes a test member + grants the mapped coupon in POSPal and shows the raw response — use a throwaway phone, then Revoke. This is how the exact coupon-grant method is confirmed.', 'doughboss' ); ?></p>
							</td>
						</tr>
					</table>

					<h3><?php esc_html_e( 'Additional stores (multi-store)', 'doughboss' ); ?></h3>
					<p class="description" style="max-width:760px;">
						<?php esc_html_e( 'Optional. Add Bankstown and Roselands here — each is a separate POSPal account with its own host, App ID, App Key and $5/$10 coupon-rule UIDs. A claimed voucher is granted to EVERY configured store. Leave a store blank to skip it. After saving, use the store dropdown above (Store 2 / Store 3) to Verify and Test each one.', 'doughboss' ); ?>
					</p>
					<?php foreach ( array( 2, 3 ) as $sn ) : ?>
						<h4>
							<?php
							/* translators: %d: store number. */
							printf( esc_html__( 'Store %d', 'doughboss' ), (int) $sn );
							?>
						</h4>
						<table class="form-table" role="presentation">
							<tr>
								<th><?php esc_html_e( 'Label', 'doughboss' ); ?></th>
								<td><input type="text" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr( 2 === $sn ? 'Bankstown' : 'Roselands' ); ?>" name="<?php echo esc_attr( $opt ); ?>[pospal<?php echo (int) $sn; ?>_label]" value="<?php echo esc_attr( isset( $settings[ 'pospal' . $sn . '_label' ] ) ? $settings[ 'pospal' . $sn . '_label' ] : '' ); ?>" /></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Area host', 'doughboss' ); ?></th>
								<td><input type="text" class="regular-text" autocomplete="off" placeholder="https://areaXX-win.pospal.cn:443" name="<?php echo esc_attr( $opt ); ?>[pospal<?php echo (int) $sn; ?>_host]" value="<?php echo esc_attr( isset( $settings[ 'pospal' . $sn . '_host' ] ) ? $settings[ 'pospal' . $sn . '_host' ] : '' ); ?>" /></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'App ID', 'doughboss' ); ?></th>
								<td><input type="text" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[pospal<?php echo (int) $sn; ?>_app_id]" value="<?php echo esc_attr( isset( $settings[ 'pospal' . $sn . '_app_id' ] ) ? $settings[ 'pospal' . $sn . '_app_id' ] : '' ); ?>" /></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'App Key', 'doughboss' ); ?></th>
								<td><input type="password" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[pospal<?php echo (int) $sn; ?>_app_key]" value="<?php echo esc_attr( isset( $settings[ 'pospal' . $sn . '_app_key' ] ) ? $settings[ 'pospal' . $sn . '_app_key' ] : '' ); ?>" />
									<p class="description">
										<?php
										/* translators: %d: store number. */
										printf( esc_html__( 'For best security set the DOUGHBOSS_POSPAL_APPKEY_%d environment variable instead; this field is a fallback.', 'doughboss' ), (int) $sn );
										?>
									</p></td>
							</tr>
							<tr>
								<th><?php esc_html_e( '$5 coupon rule UID', 'doughboss' ); ?></th>
								<td><input type="text" class="regular-text" autocomplete="off" inputmode="numeric" name="<?php echo esc_attr( $opt ); ?>[pospal<?php echo (int) $sn; ?>_coupon_uid_5]" value="<?php echo esc_attr( isset( $settings[ 'pospal' . $sn . '_coupon_uid_5' ] ) ? $settings[ 'pospal' . $sn . '_coupon_uid_5' ] : '' ); ?>" /></td>
							</tr>
							<tr>
								<th><?php esc_html_e( '$10 coupon rule UID', 'doughboss' ); ?></th>
								<td><input type="text" class="regular-text" autocomplete="off" inputmode="numeric" name="<?php echo esc_attr( $opt ); ?>[pospal<?php echo (int) $sn; ?>_coupon_uid_10]" value="<?php echo esc_attr( isset( $settings[ 'pospal' . $sn . '_coupon_uid_10' ] ) ? $settings[ 'pospal' . $sn . '_coupon_uid_10' ] : '' ); ?>" /></td>
							</tr>
						</table>
					<?php endforeach; ?>

					<h2><?php esc_html_e( 'Real-time &amp; Notifications', 'doughboss' ); ?></h2>
					<p class="description" style="max-width:760px;">
						<?php esc_html_e( 'Optional push, SMS and printing channels. Each group is off by default and stays fully dormant until you enable it and supply its settings. Secret fields (the JWT, token and API key below) are write-only: they show blank and are kept as-is unless you type a new value.', 'doughboss' ); ?>
					</p>

					<h3><?php esc_html_e( 'Mercure (real-time order board)', 'doughboss' ); ?></h3>
					<p class="description" style="max-width:760px;">
						<?php esc_html_e( 'Stream live order updates over a Mercure hub instead of polling. For best security set the publish JWT as the DOUGHBOSS_MERCURE_PUBLISH_JWT environment variable; the field below is a fallback.', 'doughboss' ); ?>
					</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable Mercure', 'doughboss' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[mercure_enabled]" value="1" <?php checked( ! empty( $settings['mercure_enabled'] ), true ); ?> /> <?php esc_html_e( 'Publish real-time updates to a Mercure hub', 'doughboss' ); ?></label></td>
						</tr>
						<tr>
							<th><label for="db-mercure-hub"><?php esc_html_e( 'Hub URL', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-mercure-hub" class="regular-text" autocomplete="off" placeholder="https://hub.example.com/.well-known/mercure" name="<?php echo esc_attr( $opt ); ?>[mercure_hub_url]" value="<?php echo esc_attr( isset( $settings['mercure_hub_url'] ) ? $settings['mercure_hub_url'] : '' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-mercure-pub-jwt"><?php esc_html_e( 'Publish JWT', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-mercure-pub-jwt" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[mercure_publish_jwt]" value="" />
								<p class="description"><?php esc_html_e( 'Server-side publisher credential. Leave blank to keep the current value.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="db-mercure-sub-jwt"><?php esc_html_e( 'Subscribe JWT', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-mercure-sub-jwt" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[mercure_subscribe_jwt]" value="" />
								<p class="description"><?php esc_html_e( 'Optional subscriber token handed to the order board. Leave blank to keep the current value.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="db-mercure-topic"><?php esc_html_e( 'Topic prefix', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-mercure-topic" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[mercure_topic_prefix]" value="<?php echo esc_attr( isset( $settings['mercure_topic_prefix'] ) ? $settings['mercure_topic_prefix'] : 'doughboss' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Connection', 'doughboss' ); ?></th>
							<td>
								<button type="button" class="button" id="db-mercure-test"><?php esc_html_e( 'Test connection', 'doughboss' ); ?></button>
								<span id="db-mercure-test-result" class="description" style="margin-left:8px;"></span>
								<p class="description"><?php esc_html_e( 'Sends a test publish to the hub and reports whether the JWT was accepted. Save your changes first — the test uses the stored hub URL and publish JWT.', 'doughboss' ); ?></p>
							</td>
						</tr>
					</table>

					<h3><?php esc_html_e( 'ntfy (staff push alerts)', 'doughboss' ); ?></h3>
					<p class="description" style="max-width:760px;">
						<?php esc_html_e( 'Push a notification to staff phones via ntfy when a new order arrives. For best security set the token as the DOUGHBOSS_NTFY_TOKEN environment variable; the field below is a fallback.', 'doughboss' ); ?>
					</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable ntfy', 'doughboss' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[ntfy_enabled]" value="1" <?php checked( ! empty( $settings['ntfy_enabled'] ), true ); ?> /> <?php esc_html_e( 'Send push notifications via ntfy', 'doughboss' ); ?></label></td>
						</tr>
						<tr>
							<th><label for="db-ntfy-server"><?php esc_html_e( 'Server', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-ntfy-server" class="regular-text" autocomplete="off" placeholder="https://ntfy.sh" name="<?php echo esc_attr( $opt ); ?>[ntfy_server]" value="<?php echo esc_attr( isset( $settings['ntfy_server'] ) ? $settings['ntfy_server'] : 'https://ntfy.sh' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-ntfy-topic"><?php esc_html_e( 'Topic', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-ntfy-topic" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[ntfy_topic]" value="<?php echo esc_attr( isset( $settings['ntfy_topic'] ) ? $settings['ntfy_topic'] : '' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-ntfy-token"><?php esc_html_e( 'Access token', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-ntfy-token" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[ntfy_token]" value="" />
								<p class="description"><?php esc_html_e( 'Only needed for protected topics. Leave blank to keep the current value.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="db-ntfy-priority"><?php esc_html_e( 'Priority', 'doughboss' ); ?></label></th>
							<td>
								<?php $ntfy_priority = isset( $settings['ntfy_priority'] ) ? $settings['ntfy_priority'] : 'high'; ?>
								<select id="db-ntfy-priority" name="<?php echo esc_attr( $opt ); ?>[ntfy_priority]">
									<option value="high" <?php selected( 'high', $ntfy_priority ); ?>><?php esc_html_e( 'High', 'doughboss' ); ?></option>
									<option value="default" <?php selected( 'default', $ntfy_priority ); ?>><?php esc_html_e( 'Default', 'doughboss' ); ?></option>
									<option value="low" <?php selected( 'low', $ntfy_priority ); ?>><?php esc_html_e( 'Low', 'doughboss' ); ?></option>
								</select>
							</td>
						</tr>
					</table>

					<h3><?php esc_html_e( 'SMS (ClickSend)', 'doughboss' ); ?></h3>
					<p class="description" style="max-width:760px;">
						<?php esc_html_e( 'Text customers when their order is ready (and optionally their voucher code on claim) via ClickSend. For best security set the API key as the DOUGHBOSS_CLICKSEND_API_KEY environment variable; the field below is a fallback.', 'doughboss' ); ?>
					</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable SMS', 'doughboss' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[sms_enabled]" value="1" <?php checked( ! empty( $settings['sms_enabled'] ), true ); ?> /> <?php esc_html_e( 'Send SMS via ClickSend', 'doughboss' ); ?></label></td>
						</tr>
						<tr>
							<th><label for="db-clicksend-username"><?php esc_html_e( 'Username', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-clicksend-username" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[clicksend_username]" value="<?php echo esc_attr( isset( $settings['clicksend_username'] ) ? $settings['clicksend_username'] : '' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-clicksend-api-key"><?php esc_html_e( 'API key', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-clicksend-api-key" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[clicksend_api_key]" value="" />
								<p class="description"><?php esc_html_e( 'Server-side credential. Leave blank to keep the current value.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="db-sms-from"><?php esc_html_e( 'From (sender ID)', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-sms-from" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[sms_from]" value="<?php echo esc_attr( isset( $settings['sms_from'] ) ? $settings['sms_from'] : '' ); ?>" />
								<p class="description"><?php esc_html_e( 'A registered alphanumeric sender ID or number. Leave blank to use the ClickSend default.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'When to text', 'doughboss' ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[sms_on_ready]" value="1" <?php checked( ! empty( $settings['sms_on_ready'] ), true ); ?> /> <?php esc_html_e( 'SMS the customer when their order is ready', 'doughboss' ); ?></label><br />
								<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[sms_on_voucher_claim]" value="1" <?php checked( ! empty( $settings['sms_on_voucher_claim'] ), true ); ?> /> <?php esc_html_e( 'SMS the voucher code to the customer when a voucher is claimed', 'doughboss' ); ?></label>
							</td>
						</tr>
					</table>

					<h3><?php esc_html_e( 'Receipt printer', 'doughboss' ); ?></h3>
					<p class="description" style="max-width:760px;">
						<?php esc_html_e( 'Print kitchen/customer dockets to a network receipt printer. For best security set the shared token as the DOUGHBOSS_PRINTER_TOKEN environment variable; the field below is a fallback.', 'doughboss' ); ?>
					</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable printing', 'doughboss' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[printer_enabled]" value="1" <?php checked( ! empty( $settings['printer_enabled'] ), true ); ?> /> <?php esc_html_e( 'Print receipts for new orders', 'doughboss' ); ?></label></td>
						</tr>
						<tr>
							<th><label for="db-printer-protocol"><?php esc_html_e( 'Protocol', 'doughboss' ); ?></label></th>
							<td>
								<?php $printer_protocol = isset( $settings['printer_protocol'] ) && 'epos' === $settings['printer_protocol'] ? 'epos' : 'cloudprnt'; ?>
								<select id="db-printer-protocol" name="<?php echo esc_attr( $opt ); ?>[printer_protocol]">
									<option value="cloudprnt" <?php selected( 'cloudprnt', $printer_protocol ); ?>><?php esc_html_e( 'Star CloudPRNT', 'doughboss' ); ?></option>
									<option value="epos" <?php selected( 'epos', $printer_protocol ); ?>><?php esc_html_e( 'Epson ePOS', 'doughboss' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="db-printer-token"><?php esc_html_e( 'Shared token', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-printer-token" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $opt ); ?>[printer_token]" value="" />
								<p class="description"><?php esc_html_e( 'Used to authenticate the printer/poll exchange. Leave blank to keep the current value.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="db-printer-width"><?php esc_html_e( 'Receipt width (chars)', 'doughboss' ); ?></label></th>
							<td><input type="number" min="1" step="1" id="db-printer-width" class="small-text" name="<?php echo esc_attr( $opt ); ?>[printer_width]" value="<?php echo esc_attr( isset( $settings['printer_width'] ) ? $settings['printer_width'] : 48 ); ?>" />
								<p class="description"><?php esc_html_e( 'Characters per line: 48 for an 80mm roll, 32 for 58mm.', 'doughboss' ); ?></p></td>
						</tr>
					</table>

					<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a repeatable label/price table for sizes or toppings.
	 *
	 * @param string $field    Field key ('sizes' or 'toppings').
	 * @param array  $rows     Existing rows.
	 * @param string $opt_name Option name.
	 * @return void
	 */
	private function render_repeater( $field, $rows, $opt_name ) {
		$rows    = ! empty( $rows ) ? $rows : array( array( 'label' => '', 'price' => '' ) );
		$table_id = 'db-repeater-' . $field;
		?>
		<table class="widefat db-repeater" id="<?php echo esc_attr( $table_id ); ?>" style="max-width:560px;">
			<thead><tr>
				<th><?php esc_html_e( 'Label', 'doughboss' ); ?></th>
				<th style="width:120px;"><?php esc_html_e( 'Price', 'doughboss' ); ?></th>
				<th style="width:40px;"></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $rows as $i => $row ) : ?>
					<tr>
						<td><input type="text" name="<?php echo esc_attr( $opt_name . '[' . $field . '][' . $i . '][label]' ); ?>" value="<?php echo esc_attr( isset( $row['label'] ) ? $row['label'] : '' ); ?>" style="width:100%;" /></td>
						<td><input type="number" step="0.01" min="0" name="<?php echo esc_attr( $opt_name . '[' . $field . '][' . $i . '][price]' ); ?>" value="<?php echo esc_attr( isset( $row['price'] ) ? $row['price'] : '' ); ?>" style="width:100%;" /></td>
						<td><button class="button-link db-remove-row" aria-label="<?php esc_attr_e( 'Remove row', 'doughboss' ); ?>">✕</button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button class="button db-add-row" data-target="<?php echo esc_attr( $table_id ); ?>"><?php esc_html_e( '+ Add row', 'doughboss' ); ?></button></p>
		<?php
	}
}
