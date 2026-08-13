≠rá^—f•ñÿ¶{M¨y 'v√Æ∂õ≠<?php
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
		add_action( 'admin_post_doughboss_claim_voucher', array( $this, 'handle_claim_voucher' ) );
		add_action( 'admin_post_doughboss_void_voucher', array( $this, 'handle_void_voucher' ) );
		add_action( 'admin_post_doughboss_seed_menu', array( $this, 'handle_seed_menu' ) );
		add_action( 'admin_post_doughboss_save_templates', array( $this, 'handle_save_templates' ) );
		add_action( 'admin_post_doughboss_clear_payment_issues', array( $this, 'handle_clear_payment_issues' ) );
		add_action( 'admin_post_doughboss_refund_order', array( $this, 'handle_refund_order' ) );
		add_action( 'admin_post_doughboss_export_report', array( $this, 'handle_export_report' ) );
		add_action( 'admin_post_doughboss_clear_pospal_alerts', array( $this, 'handle_clear_pospal_alerts' ) );
		add_action( 'admin_notices', array( $this, 'render_pospal_unmapped_notice' ) );
		add_action( 'admin_post_doughboss_pospal_outbox_resend', array( $this, 'handle_pospal_outbox_resend' ) );
		add_action( 'admin_notices', array( $this, 'render_pospal_outbox_notice' ) );
		add_action( 'admin_post_doughboss_generate_board_key', array( $this, 'handle_generate_board_key' ) );
		add_action( 'admin_post_doughboss_clear_board_key', array( $this, 'handle_clear_board_key' ) );
		add_action( 'admin_notices', array( $this, 'render_board_key_reveal_notice' ) );
		add_action( 'admin_post_doughboss_clear_delivery_notice', array( $this, 'handle_clear_delivery_notice' ) );
		add_action( 'admin_notices', array( $this, 'render_delivery_autodisabled_notice' ) );
		add_action( 'admin_notices', array( $this, 'render_migration_notice' ) );
	}

	/**
	 * Tell owners when the lifecycle migration failed closed.
	 *
	 * @return void
	 */
	public function render_migration_notice() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$error = (string) get_option( 'doughboss_migration_error', '' );
		if ( '' === $error ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p><strong><?php esc_html_e( 'DoughBoss order upgrade needs attention.', 'doughboss' ); ?></strong></p>
			<p><?php echo esc_html( $error ); ?> <?php esc_html_e( 'Online checkout and staff order changes are paused to protect order history. Ask the site administrator to verify the DoughBoss Orders, Order Items and Order Events tables use InnoDB, then retry the plugin upgrade.', 'doughboss' ); ?></p>
		</div>
		<?php
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
			__( 'Operations Dashboard', 'doughboss' ),
			__( 'Dashboard', 'doughboss' ),
			$this->cap(),
			'doughboss-dashboard',
			array( $this, 'render_dashboard_page' )
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
			__( 'Dining Tables & QR Codes', 'doughboss' ),
			__( 'Tables & QR', 'doughboss' ),
			$this->cap(),
			'doughboss-tables',
			array( $this, 'render_tables_page' )
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

		// Owner-only: the customer-facing copy sent by email/SMS. A dedicated
		// page rather than a Settings tab so it's never touched by a Settings
		// save from a different tab, and saves via its own admin-post handler
		// (a true partial DoughBoss_Settings::update(), not the full Settings-API
		// rebuild) so it can never wipe an unrelated setting.
		add_submenu_page(
			'doughboss',
			__( 'Message Templates', 'doughboss' ),
			__( 'Message Templates', 'doughboss' ),
			$this->cap(),
			'doughboss-templates',
			array( $this, 'render_templates_page' )
		);

		add_submenu_page(
			'doughboss',
			__( 'Reports', 'doughboss' ),
			__( 'Reports', 'doughboss' ),
			$this->cap(),
			'doughboss-reports',
			array( $this, 'render_reports_page' )
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
	 * Render an evidence-only operating snapshot for owners and managers.
	 *
	 * This intentionally makes no remote payment or POS calls. It is a compact
	 * view over rows already recorded by DoughBoss, so missing integrations and
	 * empty histories are displayed as such rather than inferred as success.
	 *
	 * @return void
	 */
	public function render_dashboard_page() {
		if ( ! current_user_can( $this->cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}

		$location = isset( $_GET['location'] ) ? absint( $_GET['location'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only dashboard filter.
		list( $start, $end ) = DoughBoss_Reports::today_bounds();
		$summary        = DoughBoss_Reports::summary( $start, $end, $location );
		$payment_mix    = DoughBoss_Reports::payment_mix( $start, $end, $location );
		$attempts       = DoughBoss_Reports::payment_attempt_statuses( $start, $end, $location );
		$kitchen_timing = DoughBoss_Reports::kitchen_timing( $start, $end, $location );
		$pospal         = DoughBoss_Reports::pospal_sync_snapshot();
		$catering       = DoughBoss_Reports::catering_pipeline();
		$locations      = $this->location_names();
		$multi_shop     = count( $locations ) > 1;
		$unpaid         = isset( $payment_mix['unpaid'] ) ? $payment_mix['unpaid'] : array( 'orders' => 0, 'revenue' => 0.0 );
		$paid           = isset( $payment_mix['paid'] ) ? $payment_mix['paid'] : array( 'orders' => 0, 'revenue' => 0.0 );
		$refunded       = isset( $payment_mix['refunded'] ) ? $payment_mix['refunded'] : array( 'orders' => 0, 'revenue' => 0.0 );
		$attempt_states = isset( $attempts['statuses'] ) ? $attempts['statuses'] : array();
		$failed_attempts = (int) ( isset( $attempt_states['failed'] ) ? $attempt_states['failed'] : 0 )
			+ (int) ( isset( $attempt_states['unknown'] ) ? $attempt_states['unknown'] : 0 )
			+ (int) ( isset( $attempt_states['mismatch'] ) ? $attempt_states['mismatch'] : 0 )
			+ (int) ( isset( $attempt_states['recovery_required'] ) ? $attempt_states['recovery_required'] : 0 );

		$pospal_label = __( 'Not connected', 'doughboss' );
		$pospal_note  = __( 'No POSPal configuration is available to this site.', 'doughboss' );
		if ( 'push_disabled' === $pospal['state'] ) {
			$pospal_label = __( 'Configured ‚Äî mirroring off', 'doughboss' );
			$pospal_note  = __( 'Voucher settings may be configured, but online orders are not being mirrored to POSPal.', 'doughboss' );
		} elseif ( 'outbox_unavailable' === $pospal['state'] ) {
			$pospal_label = __( 'Sync storage unavailable', 'doughboss' );
			$pospal_note  = __( 'The POSPal outbox needs a completed database upgrade before sync can be measured.', 'doughboss' );
		} elseif ( 'attention' === $pospal['state'] ) {
			$pospal_label = __( 'Needs attention', 'doughboss' );
			$pospal_note  = sprintf(
				/* translators: 1: queued count, 2: terminal count, 3: retrying count. */
				__( '%1$d queued, %2$d terminal and %3$d retrying local sync record(s).', 'doughboss' ),
				(int) $pospal['queued'],
				(int) $pospal['terminal'],
				(int) $pospal['retrying']
			);
		} elseif ( 'monitoring' === $pospal['state'] ) {
			$pospal_label = __( 'Local sync monitoring', 'doughboss' );
			$pospal_note  = sprintf(
				/* translators: 1: configured stores, 2: queued records. */
				__( '%1$d configured store(s); %2$d local sync record(s) waiting. This does not claim remote till reachability.', 'doughboss' ),
				(int) $pospal['stores'],
				(int) $pospal['queued']
			);
		}
		?>
		<div class="wrap doughboss-dashboard">
			<h1><?php esc_html_e( 'Operations Dashboard', 'doughboss' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Today in the site timezone. Figures use stored DoughBoss records only; this page never calls payment gateways or POSPal.', 'doughboss' ); ?></p>

			<form method="get" style="margin:12px 0 20px;">
				<input type="hidden" name="page" value="doughboss-dashboard" />
				<?php if ( $multi_shop ) : ?>
					<label for="db-dashboard-location"><?php esc_html_e( 'Shop', 'doughboss' ); ?></label>
					<select id="db-dashboard-location" name="location">
						<option value="0"><?php esc_html_e( 'All shops', 'doughboss' ); ?></option>
						<?php foreach ( $locations as $location_id => $location_name ) : ?>
							<option value="<?php echo esc_attr( $location_id ); ?>" <?php selected( $location, $location_id ); ?>><?php echo esc_html( $location_name ); ?></option>
						<?php endforeach; ?>
					</select>
					<button class="button"><?php esc_html_e( 'Apply', 'doughboss' ); ?></button>
				<?php endif; ?>
				<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=doughboss' ) ); ?>"><?php esc_html_e( 'View orders', 'doughboss' ); ?></a>
				<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=doughboss-reports' ) ); ?>"><?php esc_html_e( 'Open reports', 'doughboss' ); ?></a>
			</form>

			<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px;">
				<div class="card" style="min-width:160px;margin:0;padding:12px 16px;"><p style="margin:0;color:#646970;"><?php esc_html_e( 'Orders', 'doughboss' ); ?></p><p style="font-size:1.7em;margin:3px 0 0;"><strong><?php echo esc_html( number_format_i18n( $summary['orders'] ) ); ?></strong></p></div>
				<div class="card" style="min-width:160px;margin:0;padding:12px 16px;"><p style="margin:0;color:#646970;"><?php esc_html_e( 'Gross sales', 'doughboss' ); ?></p><p style="font-size:1.7em;margin:3px 0 0;"><strong><?php echo esc_html( DoughBoss_Settings::format_price( $summary['revenue'] ) ); ?></strong></p></div>
				<div class="card" style="min-width:160px;margin:0;padding:12px 16px;"><p style="margin:0;color:#646970;"><?php esc_html_e( 'Average order value', 'doughboss' ); ?></p><p style="font-size:1.7em;margin:3px 0 0;"><strong><?php echo esc_html( $summary['orders'] > 0 ? DoughBoss_Settings::format_price( $summary['aov'] ) : __( 'No data', 'doughboss' ) ); ?></strong></p></div>
				<div class="card" style="min-width:200px;margin:0;padding:12px 16px;"><p style="margin:0;color:#646970;"><?php esc_html_e( 'Kitchen active cooking time', 'doughboss' ); ?></p><p style="font-size:1.3em;margin:3px 0 0;"><strong><?php echo esc_html( $kitchen_timing['available'] && $kitchen_timing['samples'] > 0 ? sprintf( __( '%1$s min average', 'doughboss' ), number_format_i18n( $kitchen_timing['average_minutes'], 1 ) ) : __( 'No data', 'doughboss' ) ); ?></strong></p><small><?php echo esc_html( $kitchen_timing['available'] && $kitchen_timing['samples'] > 0 ? sprintf( _n( '%s ready order', '%s ready orders', $kitchen_timing['samples'], 'doughboss' ), number_format_i18n( $kitchen_timing['samples'] ) ) : __( 'Requires cooking and ready timestamps.', 'doughboss' ) ); ?></small></div>
			</div>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:18px;max-width:1100px;">
				<section class="card" style="margin:0;padding:16px;"><h2 style="margin-top:0;"><?php esc_html_e( 'Payments', 'doughboss' ); ?></h2>
					<table class="widefat striped"><tbody>
						<tr><th><?php esc_html_e( 'Paid / captured', 'doughboss' ); ?></th><td><?php echo esc_html( sprintf( __( '%1$s (%2$s orders)', 'doughboss' ), DoughBoss_Settings::format_price( $paid['revenue'] ), number_format_i18n( $paid['orders'] ) ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Unpaid / collect in store', 'doughboss' ); ?></th><td><?php echo esc_html( sprintf( __( '%1$s (%2$s orders)', 'doughboss' ), DoughBoss_Settings::format_price( $unpaid['revenue'] ), number_format_i18n( $unpaid['orders'] ) ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Refunded', 'doughboss' ); ?></th><ÎŒzﬁ⁄$z{-ÆÈ‹j◊ù&ñÁFW%˜vñGFÖ“"f«VS“#√˜áV6ÜÚW65ˆGG"Çó76WBÇG6WGFñÊw5≤w&ñÁFW%˜vñGFÇu“íÚG6WGFñÊw5≤w&ñÁFW%˜vñGFÇu“¢CÇì≤Û‚"Û‡†êêêêêêêì«6∆73“&FW67&óFñˆ‚#„√˜áW65ˆáF÷≈ˆRÇt6Ü&7FW'2W"∆ñÊS¢CÇf˜"‚É÷“&ˆ∆¬¬3"f˜"SÜ÷“‚r¬vF˜VvÜ&˜72rì≤Û„¬˜„¬˜FC‡†êêêêêì¬˜G#‡†êêêêì¬˜F&∆S‡††êêêêì√˜á7V&÷óEˆ'WGFˆ‚Çì≤Û‡†êêì¬ˆf˜&”‡†êì¬ˆFóc‡†êì√˜á †ó–††íÚ¢††í¢&VÊFW"&WVF&∆R∆&V¬˜&ñ6RF&∆Rf˜"6ó¶W2˜"F˜ñÊw2‡†í††í¢&“7G&ñÊrFfñV∆BfñV∆B∂WíÇw6ó¶W2r˜"wF˜ñÊw2rí‡†í¢&“'&íG&˜w2WÜó7FñÊr&˜w2‡†í¢&“7G&ñÊrF˜EˆÊ÷R˜Fñˆ‚Ê÷R‡†í¢&WGW&‚fˆñ@†í¢†ó&ófFRgVÊ7Fñˆ‚&VÊFW%˜&WVFW"ÇFfñV∆B¬G&˜w2¬F˜EˆÊ÷Rí∞†êíG&˜w2“V◊GíÇG&˜w2íÚG&˜w2¢'&íÇ'&íÇv∆&V¬r”‚rr¬w&ñ6Rr”‚rríì∞†êíGF&∆UˆñB“vF"◊&WVFW"“r‚FfñV∆C∞†êìÛ‡†êì«F&∆R6∆73“'vñFVfBF"◊&WVFW""ñC“#√˜áV6ÜÚW65ˆGG"ÇGF&∆UˆñBì≤Û‚"7Gñ∆S“&÷Ç◊vñGFÉ£ScÉ≤#‡†êêì«FÜVC„«G#‡†êêêì«FÉ„√˜áW65ˆáF÷≈ˆRÇt∆&V¬r¬vF˜VvÜ&˜72rì≤Û„¬˜FÉ‡†êêêì«FÇ7Gñ∆S“'vñGFÉ£#É≤#„√˜áW65ˆáF÷≈ˆRÇu&ñ6Rr¬vF˜VvÜ&˜72rì≤Û„¬˜FÉ‡†êêêì«FÇ7Gñ∆S“'vñGFÉ£CÉ≤#„¬˜FÉ‡†êêì¬˜G#„¬˜FÜVC‡†êêì«F&ˆGì‡†êêêì√˜áf˜&V6ÇÇG&˜w22Fí”‚G&˜rí¢Û‡†êêêêì«G#‡†êêêêêì«FC„∆ñÁWBGóS“'FWáB"Ê÷S“#√˜áV6ÜÚW65ˆGG"ÇF˜EˆÊ÷R‚u≤r‚FfñV∆B‚u’≤r‚Fí‚u’∂∆&V≈“rì≤Û‚"f«VS“#√˜áV6ÜÚW65ˆGG"Çó76WBÇG&˜u≤v∆&V¬u“íÚG&˜u≤v∆&V¬u“¢rrì≤Û‚"7Gñ∆S“'vñGFÉ£S≤"Û„¬˜FC‡†êêêêêì«FC„∆ñÁWBGóS“&ÁV÷&W""7FW“#„"÷ñ„“#"Ê÷S“#√˜áV6ÜÚW65ˆGG"ÇF˜EˆÊ÷R‚u≤r‚FfñV∆B‚u’≤r‚Fí‚u’∑&ñ6U“rì≤Û‚"f«VS“#√˜áV6ÜÚW65ˆGG"Çó76WBÇG&˜u≤w&ñ6Ru“íÚG&˜u≤w&ñ6Ru“¢rrì≤Û‚"7Gñ∆S“'vñGFÉ£S≤"Û„¬˜FC‡†êêêêêì«FC„∆'WGFˆ‚6∆73“&'WGFˆ‚÷∆ñÊ≤F"◊&V÷˜fR◊&˜r"&ñ÷∆&V√“#√˜áW65ˆGG%ˆRÇu&V÷˜fR&˜rr¬vF˜VvÜ&˜72rì≤Û‚#Ó)…S¬ˆ'WGFˆ„„¬˜FC‡†êêêêì¬˜G#‡†êêêì√˜áVÊFf˜&V6É≤Û‡†êêì¬˜F&ˆGì‡†êì¬˜F&∆S‡†êì«„∆'WGFˆ‚6∆73“&'WGFˆ‚F"÷FB◊&˜r"FF◊F&vWC“#√˜áV6ÜÚW65ˆGG"ÇGF&∆UˆñBì≤Û‚#„√˜áW65ˆáF÷≈ˆRÇr≤FB&˜rr¬vF˜VvÜ&˜72rì≤Û„¬ˆ'WGFˆ„„¬˜‡†êì√˜á †ó–††íÚ¢††í¢÷ÊvR7F˜&RF&∆W2ÊBó77VRˆÊR◊Fñ÷R&ñÁF&∆R"ñ∆ˆG2‡†í††í¢&WGW&‚fˆñ@†í¢†óV&∆ñ2gVÊ7Fñˆ‚&VÊFW%˜F&∆W5˜vRÇí∞†êññbÇ7W'&VÁE˜W6W%ˆ6‚Ç6V∆c£§4íbb7W'&VÁE˜W6W%ˆ6‚Çv÷ÊvUˆ˜FñˆÁ2ríí∞†êêówˆFñRÇW65ˆáF÷≈ıÚÇuñ˜RFÚÊ˜BÜfRW&÷ó76ñˆ‚FÚ÷ÊvRF&∆R"6ˆFW2‚r¬vF˜VvÜ&˜72ríì∞†êó–††êíFó77VVB“ÁV∆√∞†êíFW'&˜"“ÁV∆√∞†êíG&WVW7Eˆ÷WFÜˆB“ó76WBÇEı4U%dU%≤u$UTU5EÙ‘UDÑÙBu“íÚ7G'F˜WW"Ç6ÊóFó¶U˜FWáEˆfñV∆BÇw˜VÁ6∆6ÇÇEı4U%dU%≤u$UTU5EÙ‘UDÑÙBu“ííí¢rs∞†êññbÇuı5Br””“G&WVW7Eˆ÷WFÜˆBí∞†êêñ6ÜV6µˆF÷ñÂ˜&VfW&W"ÇvF˜VvÜ&˜75˜F&∆U˜"rì∞†êêíF7Fñˆ‚“ó76WBÇEıı5E≤wF&∆Uˆ7Fñˆ‚u“íÚ6ÊóFó¶Uˆ∂WíÇw˜VÁ6∆6ÇÇEıı5E≤wF&∆Uˆ7Fñˆ‚u“íí¢rs∞†êêññbÇv7&VFRr””“F7Fñˆ‚í∞†êêêíFó77VVB“F˜VvÑ&˜75ıF&∆Uı#£¶7&VFU˜F&∆RÄ†êêêêñó76WBÇEıı5E≤v∆ˆ6FñˆÂˆñBu“íÚ'6ñÁBÇEıı5E≤v∆ˆ6FñˆÂˆñBu“í¢¿†êêêêñó76WBÇEıı5E≤wF&∆Uˆ∆&V¬u“íÚ6ÊóFó¶U˜FWáEˆfñV∆BÇw˜VÁ6∆6ÇÇEıı5E≤wF&∆Uˆ∆&V¬u“íí¢rr¿†êêêêñó76WBÇEıı5E≤wF&∆U˜¶ˆÊRu“íÚ6ÊóFó¶U˜FWáEˆfñV∆BÇw˜VÁ6∆6ÇÇEıı5E≤wF&∆U˜¶ˆÊRu“íí¢rr¿†êêêêñó76WBÇEıı5E≤v˜&FW&ñÊu˜W&¬u“íÚW65˜W&≈˜&rÇw˜VÁ6∆6ÇÇEıı5E≤v˜&FW&ñÊu˜W&¬u“íí¢rp†êêêíì∞†êêó“V«6VñbÇv7&VFU˜6≤r””“F7Fñˆ‚í∞†êêêíG&uˆ∆&V«2“ó76WBÇEıı5E≤wF&∆Uˆ∆&V«2u“íÚ6ÊóFó¶U˜FWáF&VˆfñV∆BÇw˜VÁ6∆6ÇÇEıı5E≤wF&∆Uˆ∆&V«2u“íí¢rs∞†êêêíF∆&V«2“&Vu˜7∆óBÇrıµ«%∆‚≈“≤Úr¬G&uˆ∆&V«2¬”¬$Tuı5ƒïEÙ‰ıÙT’Eíì∞†êêêíFó77VVB“F˜VvÑ&˜75ıF&∆Uı#£¶7&VFU˜6≤Ä†êêêêñó76WBÇEıı5E≤v∆ˆ6FñˆÂˆñBu“íÚ'6ñÁBÇEıı5E≤v∆ˆ6FñˆÂˆñBu“í¢¿†êêêêñó5ˆ'&íÇF∆&V«2íÚF∆&V«2¢'&íÇí¿†êêêêñó76WBÇEıı5E≤wF&∆U˜¶ˆÊRu“íÚ6ÊóFó¶U˜FWáEˆfñV∆BÇw˜VÁ6∆6ÇÇEıı5E≤wF&∆U˜¶ˆÊRu“íí¢rr¿†êêêêñó76WBÇEıı5E≤v˜&FW&ñÊu˜W&¬u“íÚW65˜W&≈˜&rÇw˜VÁ6∆6ÇÇEıı5E≤v˜&FW&ñÊu˜W&¬u“íí¢rp†êêêíì∞†êêó“V«6VñbÇw&˜FFRr””“F7Fñˆ‚í∞†êêêíFó77VVB“F˜VvÑ&˜75ıF&∆Uı#£¶ó77VUˆ6ˆFRÇó76WBÇEıı5E≤wF&∆UˆñBu“íÚ'6ñÁBÇEıı5E≤wF&∆UˆñBu“í¢ì∞†êêó“V«6VñbÇv7FófFRr””“F7Fñˆ‚«¬vFV7FófFRr””“F7Fñˆ‚í∞†êêêíG&W7V«B“F˜VvÑ&˜75ıF&∆Uı#£ß6WEˆ7FófRÇó76WBÇEıı5E≤wF&∆UˆñBu“íÚ'6ñÁBÇEıı5E≤wF&∆UˆñBu“í¢¬v7FófFRr””“F7Fñˆ‚ì∞†êêêññbÇó5˜wˆW'&˜"ÇG&W7V«Bíí∞†êêêêíFW'&˜"“G&W7V«C∞†êêêó–†êêó–†êêññbÇó5˜wˆW'&˜"ÇFó77VVBíí∞†êêêíFW'&˜"“Fó77VVC∞†êêêíFó77VVB“ÁV∆√∞†êêó“V«6VñbÇó5ˆ'&íÇFó77VVBíbbó76WBÇFó77VVE≤v6ˆFRu“íí∞†êêêíFó77VVB“'&íÇFó77VVBì∞†êêó–†êó–††êíF∆ˆ6FñˆÁ2“F˜VvÑ&˜75Ù∆ˆ6FñˆÁ3£¶∆¬ÇG'VRì∞†êíGF&∆W2“F˜VvÑ&˜75ıF&∆Uı#£¶∆≈˜F&∆W2Çì∞†êìÛ‡†êì∆Fób6∆73“'w&F˜VvÜ&˜72÷F÷ñ‚#‡†êêì∆É„√˜áW65ˆáF÷≈ˆRÇtFñÊñÊrF&∆W2b"6ˆFW2r¬vF˜VvÜ&˜72rì≤Û„¬ˆÉ‡†êêì«„√˜áW65ˆáF÷≈ˆRÇtV6Ç&ñÁFVB6ˆFRó2W&÷ÊVÁF«íFñVBFÚˆÊR7F˜&RÊBF&∆R‚FÜR7W7Fˆ÷W"66Á2óB¬VÁFW'2FÜVó"Ê÷RB6ÜV6∂˜WB¬ÊBFÜR∂óF6ÜV‚&V6VófW2&˜FÇFÜVó"Ê÷RÊBF&∆R‚r¬vF˜VvÜ&˜72rì≤Û„¬˜‡†êêì√˜áñbÇFW'&˜"í¢Û‡†êêêì∆Fób6∆73“&Ê˜Fñ6RÊ˜Fñ6R÷W'&˜"#„«„√˜áV6ÜÚW65ˆáF÷¬ÇFW'&˜"”ÊvWEˆW'&˜%ˆ÷W76vRÇíì≤Û„¬˜„¬ˆFóc‡†êêì√˜áVÊFñc≤Û‡††êêì√˜áñbÇFó77VVBí¢Û‡†êêêì∆Fób6∆73“&Ê˜Fñ6RÊ˜Fñ6R◊v&ÊñÊr#„«„«7G&ˆÊs„√˜áV6ÜÚW65ˆáF÷¬Ç””“6˜VÁBÇFó77VVBíÚıÚÇu&ñÁBFÜó2"Ê˜r‚r¬vF˜VvÜ&˜72rí¢ıÚÇu&ñÁBFÜó2"6≤Ê˜r‚r¬vF˜VvÜ&˜72ríì≤Û„¬˜7G&ˆÊs‚√˜áW65ˆáF÷≈ˆRÇtf˜"6V7W&óGí¬&V&W"6ˆFW2&RÊ˜B7F˜&VBÊB6ÊÊ˜B&R6Ü˜v‚vñ‚‚6fRFÚDb˜"&ñÁB&Vf˜&R∆VfñÊrFÜó2vR‚&˜FFñÊr7&VFW2&W∆6V÷VÁBÊBñ÷÷VFñFV«íñÁf∆ñFFW2FÜRˆ∆B&ñÁB‚r¬vF˜VvÜ&˜72rì≤Û„¬˜„¬ˆFóc‡†êêêì∆FóbñC“&F˜VvÜ&˜72◊"◊&ñÁB#‡†êêêì√˜áf˜&V6ÇÇFó77VVB2FñÊFWÇ”‚G"í¢Û‡†êêêêì«6V7Fñˆ‚6∆73“&F˜VvÜ&˜72◊"÷∆&V¬"FF◊"÷∆&V√“#√˜áV6ÜÚW65ˆGG"ÇG%≤v∆&V¬u“ì≤Û‚"7Gñ∆S“&&6∂w&˜VÊC¢6ffc∂&˜&FW#£'Ç6ˆ∆ñB3∂÷Ç◊vñGFÉ£CCÉ∑FFñÊs£#áÉ∑FWáB÷∆ñv„¶6VÁFW#∂÷&vñ„£#'É∂'&V≤÷ñÁ6ñFS¶fˆñC∑vR÷'&V≤÷ñÁ6ñFS¶fˆñC≤#‡†êêêêêì«7Gñ∆S“&fˆÁB◊6ó¶S£GÉ∂fˆÁB◊vVñváC£s∂∆WGFW"◊76ñÊs¢„ÜV”∂÷&vñ„£gÉ≤#„√˜áV6ÜÚW65ˆáF÷¬Ç7&ñÁFbÇıÚÇtDıTtÇ$ı52W2r¬vF˜VvÜ&˜72rí¬ó76WBÇG%≤v∆ˆ6FñˆÂˆÊ÷Ru“íÚ7G'F˜WW"ÇG%≤v∆ˆ6FñˆÂˆÊ÷Ru“í¢rríì≤Û„¬˜‡†êêêêêì∆É"7Gñ∆S“&fˆÁB◊6ó¶S£3É∂÷&vñ„£áÉ≤#„√˜áV6ÜÚW65ˆáF÷¬Ç7&ñÁFbÇıÚÇuD$ƒRW2r¬vF˜VvÜ&˜72rí¬G%≤v∆&V¬u“íì≤Û„¬ˆÉ#‡†êêêêêì«7Gñ∆S“&fˆÁB◊6ó¶S£áÉ≤#„√˜áW65ˆáF÷≈ˆRÇu66‚FÚ˜&FW"g&ˆ“FÜó2F&∆R‚6ÜV6≤ñ˜W"F&∆RÁV÷&W"¬6Üˆ˜6Rñ˜W"fˆˆBÊBí6V7W&V«ívÜV‚˜&FW&ñÊró2˜V‚‚r¬vF˜VvÜ&˜72rì≤Û„¬˜‡†êêêêêì∆Fób6∆73“&F˜VvÜ&˜72◊"÷6ˆFR"FF◊W&√“#√˜áV6ÜÚW65ˆGG"ÇG%≤wW&¬u“ì≤Û‚"FF÷ñÊFWÉ“#√˜áV6ÜÚW65ˆGG"ÇFñÊFWÇì≤Û‚"7Gñ∆S“&Fó7∆ì¶f∆WÉ∂ßW7Fñgí÷6ˆÁFVÁC¶6VÁFW#∂÷&vñ„£áÉ≤#„¬ˆFóc‡†êêêêêì«„∆6ˆFS„√˜áV6ÜÚW65ˆáF÷¬ÇG%≤wW&¬u“ì≤Û„¬ˆ6ˆFS„¬˜‡†êêêêêì∆'WGFˆ‚GóS“&'WGFˆ‚"6∆73“&'WGFˆ‚F"÷F˜vÊ∆ˆB◊""FF÷ñÊFWÉ“#√˜áV6ÜÚW65ˆGG"ÇFñÊFWÇì≤Û‚"FF÷∆&V√“#√˜áV6ÜÚW65ˆGG"ÇG%≤v∆&V¬u“ì≤Û‚"FF÷∆ˆ6Fñˆ„“#√˜áV6ÜÚW65ˆGG"Çó76WBÇG%≤v∆ˆ6FñˆÂˆÊ÷Ru“íÚG%≤v∆ˆ6FñˆÂˆÊ÷Ru“¢w7F˜&Rrì≤Û‚#„√˜áW65ˆáF÷≈ˆRÇtF˜vÊ∆ˆB5drr¬vF˜VvÜ&˜72rì≤Û„¬ˆ'WGFˆ„‡†êêêêì¬˜6V7Fñˆ„‡†êêêì√˜áVÊFf˜&V6É≤Û‡†êêêì¬ˆFóc‡†êêêì«6∆73“&F"◊"◊&ñÁB÷7FñˆÁ2#„∆'WGFˆ‚GóS“&'WGFˆ‚"6∆73“&'WGFˆ‚'WGFˆ‚◊&ñ÷'í"ˆÊ6∆ñ6≥“'vñÊF˜rÁ&ñÁBÇí#„√˜áW65ˆáF÷≈ˆRÇu&ñÁBÚ6fR"6≤2Dbr¬vF˜VvÜ&˜72rì≤Û„¬ˆ'WGFˆ„„¬˜‡†êêêì«7Gñ∆R÷VFñ“'&ñÁB#‚7wF÷ñÊ&"¬6F÷ñÊ÷VÁV÷ñ‚¬7wfˆ˜FW"¬Áw&ÊF˜VvÜ&˜72÷F÷ñ„ÊÉ¬Áw&ÊF˜VvÜ&˜72÷F÷ñ„Á¬Áw&ÊF˜VvÜ&˜72÷F÷ñ„ÊÉ"¬Áw&ÊF˜VvÜ&˜72÷F÷ñ„Êf˜&“¬Áw&ÊF˜VvÜ&˜72÷F÷ñ„ÁF&∆R¬ÊÊ˜Fñ6R¬ÊF"◊"◊&ñÁB÷7FñˆÁ2¬ÊF"÷F˜vÊ∆ˆB◊'∂Fó7∆ì¶ÊˆÊRñ◊˜'FÁG“7w6ˆÁFVÁG∂÷&vñ„£ñ◊˜'FÁG“6F˜VvÜ&˜72◊"◊&ñÁG∂Fó7∆ì¶w&ñBñ◊˜'FÁC∂w&ñB◊FV◊∆FR÷6ˆ«V÷Á3ß&WVBÉ"√g"ì∂v£gá“ÊF˜VvÜ&˜72◊"÷∆&V«∂÷&vñ„£ñ◊˜'FÁC∂÷Ç◊vñGFÉ¶ÊˆÊRñ◊˜'FÁG“ÊF˜VvÜ&˜72◊"÷∆&V¬7fw∂÷Ç◊vñGFÉ£#CÉ∂ÜVñváC¶WF˜◊”¬˜7Gñ∆S‡†êêêì«67&óC‡†êêêñFˆ7V÷VÁBÊFDWfVÁD∆ó7FVÊW"ÇtDÙ‘6ˆÁFVÁD∆ˆFVBr¬gVÊ7Fñˆ‚Çí∞†êêêêóf"÷˜VÁG2“Fˆ7V÷VÁBÁVW'ï6V∆V7F˜$∆¬ÇrÊF˜VvÜ&˜72◊"÷6ˆFRrì∞†êêêêññbÇ÷˜VÁG2Ê∆VÊwFÇ«¬GóVˆb&6ˆFR”“vgVÊ7Fñˆ‚rí&WGW&„∞†êêêêñ÷˜VÁG2Êf˜$V6ÇÜgVÊ7Fñˆ‚Ü÷˜VÁBí∞†êêêêêóf"6ˆFR“&6ˆFRÉ¬t“rì∞†êêêêêñ6ˆFRÊFDFFÜ÷˜VÁBÊvWDGG&ñ'WFRÇvFF◊W&¬rí¬t'óFRrì∞†êêêêêñ6ˆFRÊ÷∂RÇì∞†êêêêêñ÷˜VÁBÊñÊÊW$ÖD‘¬“6ˆFRÊ7&VFU7fuFrá≤6V∆≈6ó¶S¢b¬÷&vñ„¢B¬66∆&∆S¢G'VR“ì∞†êêêêó“ì∞†êêêêñFˆ7V÷VÁBÁVW'ï6V∆V7F˜$∆¬ÇrÊF"÷F˜vÊ∆ˆB◊"ríÊf˜$V6ÇÜgVÊ7Fñˆ‚Ü'WGFˆ‚í∞†êêêêêñ'WGFˆ‚ÊFDWfVÁD∆ó7FVÊW"Çv6∆ñ6≤r¬gVÊ7Fñˆ‚Çí∞†êêêêêêóf"÷˜VÁB“Fˆ7V÷VÁBÁVW'ï6V∆V7F˜"ÇrÊF˜VvÜ&˜72◊"÷6ˆFU∂FF÷ñÊFWÉ“"r≤'WGFˆ‚ÊvWDGG&ñ'WFRÇvFF÷ñÊFWÇrí≤r%“rì∞†êêêêêêóf"7fr“÷˜VÁBbb÷˜VÁBÁVW'ï6V∆V7F˜"Çw7frrì∞†êêêêêêññbÇ7frí&WGW&„∞†êêêêêêóf"&∆ˆ"“ÊWr&∆ˆ"Ö∂ÊWrÑ‘≈6W&ñ∆ó¶W"ÇíÁ6W&ñ∆ó¶UFı7G&ñÊrá7frï“¬∑GóS¢vñ÷vR˜7fr∑Ü÷√∂6Ü'6WC◊WFb”Çw“ì∞†êêêêêêóf"∆ñÊ≤“Fˆ7V÷VÁBÊ7&VFTV∆V÷VÁBÇvrì∞†êêêêêêñ∆ñÊ≤Êá&Vb“U$¬Ê7&VFTˆ&¶V7EU$¬Ü&∆ˆ"ì∞†êêêêêêóf"7F˜&R“7G&ñÊrÜ'WGFˆ‚ÊvWDGG&ñ'WFRÇvFF÷∆ˆ6Fñˆ‚rí«¬w7F˜&RríÁ&W∆6RÇıµÊ◊£”ïÚ’“≤ˆví¬r“rì∞†êêêêêêñ∆ñÊ≤ÊF˜vÊ∆ˆB“vF˜VvÜ&˜72“r≤7F˜&R≤r◊F&∆R“r≤7G&ñÊrÜ'WGFˆ‚ÊvWDGG&ñ'WFRÇvFF÷∆&V¬rí«¬w"ríÁ&W∆6RÇıµÊ◊£”ïÚ’“≤ˆví¬r“rí≤rÁ7frs∞†êêêêêêñ∆ñÊ≤Ê6∆ñ6≤Çì∞†êêêêêêó6WEFñ÷V˜WBÜgVÊ7Fñˆ‚Çí≤U$¬Á&Wfˆ∂Tˆ&¶V7EU$¬Ü∆ñÊ≤Êá&Vbì≤“¬ì∞†êêêêêó“ì∞†êêêêó“ì∞†êêêó“ì∞†êêêì¬˜67&óC‡†êêì√˜áVÊFñc≤Û‡††êêì∆É#„√˜áW65ˆáF÷≈ˆRÇu&WfW6'í∆VÊ6Ç"6≤r¬vF˜VvÜ&˜72rì≤Û„¬ˆÉ#‡†êêì«„√˜áW65ˆáF÷≈ˆRÇt7&VFR∆¬6ˆÊfó&÷VBF&∆R∆&V«2ñ‚ˆÊR÷ÊvW"÷ˆÊ«í˜W&Fñˆ‚‚FÜR∆&V«2&V∆˜r&R∆VÊ6ÇFV◊∆FS¢VFóBFÜV“FÚ÷F6ÇFÜRáó6ñ6¬&WfW6'íf∆ˆ˜"&Vf˜&R7&VFñÊrFÜR6≤‚r¬vF˜VvÜ&˜72rì≤Û„¬˜‡†êêì∆f˜&“÷WFÜˆC“'˜7B#‡†êêêì√˜áwˆÊˆÊ6UˆfñV∆BÇvF˜VvÜ&˜75˜F&∆U˜"rì≤Û‡†êêêì∆ñÁWBGóS“&ÜñFFV‚"Ê÷S“'F&∆Uˆ7Fñˆ‚"f«VS“&7&VFU˜6≤"Û‡†êêêì«F&∆R6∆73“&f˜&“◊F&∆R#„«F&ˆGì‡†êêêì«G#„«FÉ„∆∆&V¬f˜#“&F"◊6≤÷∆ˆ6Fñˆ‚#„√˜áW65ˆáF÷≈ˆRÇu7F˜&Rr¬vF˜VvÜ&˜72rì≤Û„¬ˆ∆&V√„¬˜FÉ„«FC„«6V∆V7BñC“&F"◊6≤÷∆ˆ6Fñˆ‚"Ê÷S“&∆ˆ6FñˆÂˆñB"&WVó&VC„∆˜Fñˆ‚f«VS“"#„√˜áW65ˆáF÷≈ˆRÇt6Üˆ˜6R7F˜&Rr¬vF˜VvÜ&˜72rì≤Û„¬ˆ˜Fñˆ„„√˜áf˜&V6ÇÇF∆ˆ6FñˆÁ22F∆ˆ6Fñˆ‚í¢Û„∆˜Fñˆ‚f«VS“#√˜áV6ÜÚW65ˆGG"ÇF∆ˆ6Fñˆ‚”ÊñBì≤Û‚"√˜á6V∆V7FVBÇw&WfW6'ír¬6ÊóFó¶U˜FóF∆RÇF∆ˆ6Fñˆ‚”ÊÊ÷Ríì≤Û„„√˜áV6ÜÚW65ˆáF÷¬ÇF∆ˆ6Fñˆ‚”ÊÊ÷Rì≤Û„¬ˆ˜Fñˆ„„√˜áVÊFf˜&V6É≤Û„¬˜6V∆V7C„¬˜FC„¬˜G#‡†êêêì«G#„«FÉ„∆∆&V¬f˜#“&F"◊6≤÷∆&V«2#„√˜áW65ˆáF÷≈ˆRÇt6ˆÊfó&÷VBF&∆R∆&V«2r¬vF˜VvÜ&˜72rì≤Û„¬ˆ∆&V√„¬˜FÉ„«FC„«FWáF&VñC“&F"◊6≤÷∆&V«2"Ê÷S“'F&∆Uˆ∆&V«2"&˜w3“#""6∆73“&∆&vR◊FWáB"&WVó&VC„√˜áV6ÜÚW65˜FWáF&VÇñ◊∆ˆFRÇ%∆‚"¬&ÊvRÇ¬"ííì≤Û„¬˜FWáF&V„«6∆73“&FW67&óFñˆ‚#„√˜áW65ˆáF÷≈ˆRÇtˆÊR∆&V¬W"∆ñÊRÜ÷Üñ◊V“Sí‚FV∆WFRÁíÁV÷&W"FÜBó2Ê˜Báó6ñ6∆«íˆ‚FÜR&WfW6'íf∆ˆ˜"‚WÜó7FñÊr∆&V«2&R&V¶V7FVB&Vf˜&RÁóFÜñÊró27&VFVB‚r¬vF˜VvÜ&˜72rì≤Û„¬˜„¬˜FC„¬˜G#‡†êêêì«G#„«FÉ„∆∆&V¬f˜#“&F"◊6≤◊¶ˆÊR#„√˜áW65ˆáF÷≈ˆRÇu¶ˆÊRr¬vF˜VvÜ&˜72rì≤Û„¬ˆ∆&V√„¬˜FÉ„«FC„∆ñÁWBñC“&F"◊6≤◊¶ˆÊR"Ê÷S“'F&∆U˜¶ˆÊR"GóS“'FWáB"÷Ü∆VÊwFÉ“#É"f«VS“$FñÊñÊr&ˆˆ“"Û„¬˜FC„¬˜G#‡†êêêì«G#„«FÉ„∆∆&V¬f˜#“&F"◊6≤÷˜&FW&ñÊr◊W&¬#„√˜áW65ˆáF÷≈ˆRÇt˜&FW"vRU$¬r¬vF˜VvÜ&˜72rì≤Û„¬ˆ∆&V√„¬˜FÉ„«FC„∆ñÁWBñC“&F"◊6≤÷˜&FW&ñÊr◊W&¬"Ê÷S“&˜&FW&ñÊu˜W&¬"GóS“'W&¬"6∆73“'&VwV∆"◊FWáB"&WVó&VBf«VS“#√˜áV6ÜÚW65ˆGG"ÇÜˆ÷U˜W&¬Çrˆ˜&FW"Úríì≤Û‚"Û„«6∆73“&FW67&óFñˆ‚#„√˜áW65ˆáF÷≈ˆRÇuW6RFÜRV&∆ó6ÜVB6÷R◊6óFRvR6ˆÁFñÊñÊrFÜRF˜VvÑ&˜72÷VÁRÊB6ÜV6∂˜WB‚r¬vF˜VvÜ&˜72rì≤Û„¬˜„¬˜FC„¬˜G#‡†êêêì¬˜F&ˆGì„¬˜F&∆S‡†êêêì√˜á7V&÷óEˆ'WGFˆ‚ÇıÚÇt7&VFR&WfW6'í"6≤r¬vF˜VvÜ&˜72ríì≤Û‡†êêì¬ˆf˜&”‡††êêì∆É#„√˜áW65ˆáF÷≈ˆRÇtFBF&∆Rr¬vF˜VvÜ&˜72rì≤Û„¬ˆÉ#‡†êêì∆f˜&“÷WFÜˆC“'˜7B#‡†êêêì√˜áwˆÊˆÊ6UˆfñV∆BÇvF˜VvÜ&˜75˜F&∆U˜"rì≤Û‡†êêêì∆ñÁWBGóS“&ÜñFFV‚"Ê÷S“'F&∆Uˆ7Fñˆ‚"f«VS“&7&VFR"Û‡†êêêì«F&∆R6∆73“&f˜&“◊F&∆R#„«F&ˆGì‡†êêêì«G#„«FÉ„∆∆&V¬f˜#“&F"◊F&∆R÷∆ˆ6Fñˆ‚#„√˜áW65ˆáF÷≈ˆRÇu7F˜&Rr¬vF˜VvÜ&˜72rì≤Û„¬ˆ∆&V√„¬˜FÉ„«FC„«6V∆V7BñC“&F"◊F&∆R÷∆ˆ6Fñˆ‚"Ê÷S“&∆ˆ6FñˆÂˆñB"&WVó&VC„∆˜Fñˆ‚f«VS“"#„√˜áW65ˆáF÷≈ˆRÇt6Üˆ˜6R7F˜&Rr¬vF˜VvÜ&˜72rì≤Û„¬ˆ˜Fñˆ„„√˜áf˜&V6ÇÇF∆ˆ6FñˆÁ22F∆ˆ6Fñˆ‚í¢Û„∆˜Fñˆ‚f«VS“#√˜áV6ÜÚW65ˆGG"ÇF∆ˆ6Fñˆ‚”ÊñBì≤Û‚#„√˜áV6ÜÚW65ˆáF÷¬ÇF∆ˆ6Fñˆ‚”ÊÊ÷Rì≤Û„¬ˆ˜Fñˆ„„√˜áVÊFf˜&V6É≤Û„¬˜6V∆V7C„¬˜FC„¬˜G#‡†êêêì«G#„«FÉ„∆∆&V¬f˜#“&F"◊F&∆R÷∆&V¬#„√˜áW65ˆáF÷≈ˆRÇuF&∆RÁV÷&W"Ú∆&V¬r¬vF˜VvÜ&˜72rì≤Û„¬ˆ∆&V√„¬˜FÉ„«FC„∆ñÁWBñC“&F"◊F&∆R÷∆&V¬"Ê÷S“'F&∆Uˆ∆&V¬"GóS“'FWáB"÷Ü∆VÊwFÉ“#É"&WVó&VB∆6VÜˆ∆FW#“#""Û„¬˜FC„¬˜G#‡†êêêì«G#„«FÉ„∆∆&V¬f˜#“&F"◊F&∆R◊¶ˆÊR#„√˜áW65ˆáF÷≈ˆRÇu¶ˆÊRÜ˜FñˆÊ¬ír¬vF˜VvÜ&˜72rì≤Û„¬ˆ∆&V√„¬˜FÉ„«FC„∆ñÁWBñC“&F"◊F&∆R◊¶ˆÊR"Ê÷S“'F&∆U˜¶ˆÊR"GóS“'FWáB"÷Ü∆VÊwFÉ“#É"∆6VÜˆ∆FW#“$6˜W'Gñ&B"Û„¬˜FC„¬˜G#‡†êêêì«G#„«FÉ„∆∆&V¬f˜#“&F"÷˜&FW&ñÊr◊W&¬#„√˜áW65ˆáF÷≈ˆRÇt÷VÁRvRU$¬r¬vF˜VvÜ&˜72rì≤Û„¬ˆ∆&V√„¬˜FÉ„«FC„∆ñÁWBñC“&F"÷˜&FW&ñÊr◊W&¬"Ê÷S“&˜&FW&ñÊu˜W&¬"GóS“'W&¬"6∆73“'&VwV∆"◊FWáB"&WVó&VBf«VS“#√˜áV6ÜÚW65ˆGG"ÇÜˆ÷U˜W&¬ÇrÚríì≤Û‚"Û„«6∆73“&FW67&óFñˆ‚#„√˜áW65ˆáF÷≈ˆRÇt◊W7B&RvRˆ‚FÜó2v˜&E&W726óFR6ˆÁFñÊñÊrFÜRF˜VvÑ&˜72÷VÁRˆ6'B‚r¬vF˜VvÜ&˜72rì≤Û„¬˜„¬˜FC„¬˜G#‡†êêêì¬˜F&ˆGì„¬˜F&∆S‡†êêêì√˜á7V&÷óEˆ'WGFˆ‚ÇıÚÇt7&VFRF&∆RÊB"r¬vF˜VvÜ&˜72ríì≤Û‡†êêì¬ˆf˜&”‡††êêì∆É#„√˜áW65ˆáF÷≈ˆRÇtWÜó7FñÊrF&∆W2r¬vF˜VvÜ&˜72rì≤Û„¬ˆÉ#‡†êêì«F&∆R6∆73“'vñFVfB7G&óVB#„«FÜVC„«G#„«FÉ„√˜áW65ˆáF÷≈ˆRÇu7F˜&Rr¬vF˜VvÜ&˜72rì≤Û„¬˜FÉ„«FÉ„√˜áW65ˆáF÷≈ˆRÇuF&∆Rr¬vF˜VvÜ&˜72rì≤Û„¬˜FÉ„«FÉ„√˜áW65ˆáF÷≈ˆRÇu¶ˆÊRr¬vF˜VvÜ&˜72rì≤Û„¬˜FÉ„«FÉ„√˜áW65ˆáF÷≈ˆRÇu7FFRr¬vF˜VvÜ&˜72rì≤Û„¬˜FÉ„«FÉ„√˜áW65ˆáF÷≈ˆRÇt∆7B66‚r¬vF˜VvÜ&˜72rì≤Û„¬˜FÉ„«FÉ„√˜áW65ˆáF÷≈ˆRÇt7FñˆÁ2r¬vF˜VvÜ&˜72rì≤Û„¬˜FÉ„¬˜G#„¬˜FÜVC„«F&ˆGì‡†êêì√˜áñbÇGF&∆W2í¢Û„«G#„«FB6ˆ«7„“#b#„√˜áW65ˆáF÷≈ˆRÇtÊÚFñÊñÊrF&∆W2ÜfR&VV‚7&VFVB‚r¬vF˜VvÜ&˜72rì≤Û„¬˜FC„¬˜G#„√˜áVÊFñc≤Û‡†êêì√˜áf˜&V6ÇÇGF&∆W22GF&∆Rí¢Û‡†êêì«G#„«FC„√˜áV6ÜÚW65ˆáF÷¬ÇGF&∆R”Ê∆ˆ6FñˆÂˆÊ÷Rì≤Û„¬˜FC„«FC„«7G&ˆÊs„√˜áV6ÜÚW65ˆáF÷¬ÇGF&∆R”Ê∆&V¬ì≤Û„¬˜7G&ˆÊs„¬˜FC„«FC„√˜áV6ÜÚW65ˆáF÷¬ÇGF&∆R”Á¶ˆÊRì≤Û„¬˜FC„«FC„√˜áV6ÜÚGF&∆R”Êó5ˆ7FófRÚW65ˆáF÷≈ıÚÇt7FófRr¬vF˜VvÜ&˜72rí¢W65ˆáF÷≈ıÚÇtñÊ7FófRr¬vF˜VvÜ&˜72rì≤Û„¬˜FC„«FC„√˜áV6ÜÚGF&∆R”Ê∆7E˜66ÊÊVEˆBÚW65ˆáF÷¬ÇGF&∆R”Ê∆7E˜66ÊÊVEˆBí¢W65ˆáF÷≈ıÚÇtÊWfW"r¬vF˜VvÜ&˜72rì≤Û„¬˜FC„«FC‡†êêêì∆f˜&“÷WFÜˆC“'˜7B"7Gñ∆S“&Fó7∆ì¶ñÊ∆ñÊS≤#„√˜áwˆÊˆÊ6UˆfñV∆BÇvF˜VvÜ&˜75˜F&∆U˜"rì≤Û„∆ñÁWBGóS“&ÜñFFV‚"Ê÷S“'F&∆UˆñB"f«VS“#√˜áV6ÜÚW65ˆGG"ÇGF&∆R”ÊñBì≤Û‚"Û„∆ñÁWBGóS“&ÜñFFV‚"Ê÷S“'F&∆Uˆ7Fñˆ‚"f«VS“#√˜áV6ÜÚGF&∆R”Êó5ˆ7FófRÚvFV7FófFRr¢v7FófFRs≤Û‚"Û„∆'WGFˆ‚6∆73“&'WGFˆ‚#„√˜áV6ÜÚGF&∆R”Êó5ˆ7FófRÚW65ˆáF÷≈ıÚÇtFV7FófFRr¬vF˜VvÜ&˜72rí¢W65ˆáF÷≈ıÚÇt7FófFRr¬vF˜VvÜ&˜72rì≤Û„¬ˆ'WGFˆ„„¬ˆf˜&”‡†êêêì∆f˜&“÷WFÜˆC“'˜7B"7Gñ∆S“&Fó7∆ì¶ñÊ∆ñÊS≤"ˆÁ7V&÷óC“'&WGW&‚6ˆÊfó&“Çs√˜áV6ÜÚW65ˆß2ÇıÚÇu&˜FFRFÜó2#ÚWfW'íˆ∆B&ñÁBÊB7FófRF&∆R6W76ñˆ‚vñ∆¬7F˜v˜&∂ñÊrñ÷÷VFñFV«í‚r¬vF˜VvÜ&˜72ríì≤Û‚rì≤#„√˜áwˆÊˆÊ6UˆfñV∆BÇvF˜VvÜ&˜75˜F&∆U˜"rì≤Û„∆ñÁWBGóS“&ÜñFFV‚"Ê÷S“'F&∆UˆñB"f«VS“#√˜áV6ÜÚW65ˆGG"ÇGF&∆R”ÊñBì≤Û‚"Û„∆ñÁWBGóS“&ÜñFFV‚"Ê÷S“'F&∆Uˆ7Fñˆ‚"f«VS“'&˜FFR"Û„∆'WGFˆ‚6∆73“&'WGFˆ‚#„√˜áW65ˆáF÷≈ˆRÇu&˜FFRb&ñÁBr¬vF˜VvÜ&˜72rì≤Û„¬ˆ'WGFˆ„„¬ˆf˜&”‡†êêì¬˜FC„¬˜G#‡†êêì√˜áVÊFf˜&V6É≤Û‡†êêì¬˜F&ˆGì„¬˜F&∆S‡†êì¬ˆFóc‡†êì√˜á †ó–ß–