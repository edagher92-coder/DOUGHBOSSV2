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
			$pospal_label = __( 'Configured â€” mirroring off', 'doughboss' );
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
						<tr><th><?php esc_html_e( 'Refunded', 'doughboss' ); ?></th><ëÎtîÚ$z{-®éÜj×çFW%÷v–GF…Ò"fÇVSÒ#Ã÷‡V6†òW65öGG"‚—76WB‚G6WGF–æw5²w&–çFW%÷v–GF‚uÒ’òG6WGF–æw5²w&–çFW%÷v–GF‚uÒ¢C‚“²óâ"óà “Ç6Æ73Ò&FW67&—F–öâ#ãÃ÷‡W65ö‡FÖÅöR‚t6†&7FW'2W"Æ–æS¢C‚f÷"âƒÖÒ&öÆÂÂ3"f÷"S†ÖÒârÂvF÷Vv†&÷72r“²óãÂ÷ãÂ÷FCà “Â÷G#à “Â÷F&ÆSà  “Ã÷‡7V&Ö—Eö'WGFöâ‚“²óà “Âöf÷&Óà “ÂöF—cà “Ã÷‡  —Ð  ’ò¢  ’¢&VæFW"&WVF&ÆRÆ&VÂ÷&–6RF&ÆRf÷"6—¦W2÷"F÷–æw2à ’  ’¢&Ò7G&–ærFf–VÆBf–VÆB¶W’‚w6—¦W2r÷"wF÷–æw2r’à ’¢&Ò'&’G&÷w2W†—7F–ær&÷w2à ’¢&Ò7G&–ærF÷EöæÖR÷F–öâæÖRà ’¢&WGW&âfö–@ ’¢ð —&—fFRgVæ7F–öâ&VæFW%÷&WVFW"‚Ff–VÆBÂG&÷w2ÂF÷EöæÖR’° ’G&÷w2ÒV×G’‚G&÷w2’òG&÷w2¢'&’‚'&’‚vÆ&VÂrÓârrÂw&–6RrÓârr’“° ’GF&ÆUö–BÒvF"×&WVFW"ÒrâFf–VÆC° “óà “ÇF&ÆR6Æ73Ò'v–FVfBF"×&WVFW""–CÒ#Ã÷‡V6†òW65öGG"‚GF&ÆUö–B“²óâ"7G–ÆSÒ&Ö‚×v–GFƒ£Scƒ²#à “ÇF†VCãÇG#à “ÇFƒãÃ÷‡W65ö‡FÖÅöR‚tÆ&VÂrÂvF÷Vv†&÷72r“²óãÂ÷Fƒà “ÇF‚7G–ÆSÒ'v–GFƒ£#ƒ²#ãÃ÷‡W65ö‡FÖÅöR‚u&–6RrÂvF÷Vv†&÷72r“²óãÂ÷Fƒà “ÇF‚7G–ÆSÒ'v–GFƒ£Cƒ²#ãÂ÷Fƒà “Â÷G#ãÂ÷F†VCà “ÇF&öG“à “Ã÷‡f÷&V6‚‚G&÷w22F’ÓâG&÷r’¢óà “ÇG#à “ÇFCãÆ–çWBG—SÒ'FW‡B"æÖSÒ#Ã÷‡V6†òW65öGG"‚F÷EöæÖRâu²râFf–VÆBâuÕ²râF’âuÕ¶Æ&VÅÒr“²óâ"fÇVSÒ#Ã÷‡V6†òW65öGG"‚—76WB‚G&÷u²vÆ&VÂuÒ’òG&÷u²vÆ&VÂuÒ¢rr“²óâ"7G–ÆSÒ'v–GFƒ£S²"óãÂ÷FCà “ÇFCãÆ–çWBG—SÒ&çVÖ&W""7FWÒ#ã"Ö–ãÒ#"æÖSÒ#Ã÷‡V6†òW65öGG"‚F÷EöæÖRâu²râFf–VÆBâuÕ²râF’âuÕ·&–6UÒr“²óâ"fÇVSÒ#Ã÷‡V6†òW65öGG"‚—76WB‚G&÷u²w&–6RuÒ’òG&÷u²w&–6RuÒ¢rr“²óâ"7G–ÆSÒ'v–GFƒ£S²"óãÂ÷FCà “ÇFCãÆ'WGFöâ6Æ73Ò&'WGFöâÖÆ–æ²F"×&VÖ÷fR×&÷r"&–ÖÆ&VÃÒ#Ã÷‡W65öGG%öR‚u&VÖ÷fR&÷rrÂvF÷Vv†&÷72r“²óâ#î)ÉSÂö'WGFöããÂ÷FCà “Â÷G#à “Ã÷‡VæFf÷&V6ƒ²óà “Â÷F&öG“à “Â÷F&ÆSà “ÇãÆ'WGFöâ6Æ73Ò&'WGFöâF"ÖFB×&÷r"FF×F&vWCÒ#Ã÷‡V6†òW65öGG"‚GF&ÆUö–B“²óâ#ãÃ÷‡W65ö‡FÖÅöR‚r²FB&÷rrÂvF÷Vv†&÷72r“²óãÂö'WGFöããÂ÷à “Ã÷‡  —Ð  ’ò¢  ’¢ÖævR7F÷&RF&ÆW2æB—77VRöæR×F–ÖR&–çF&ÆR"–ÆöG2à ’  ’¢&WGW&âfö–@ ’¢ð —V&Æ–2gVæ7F–öâ&VæFW%÷F&ÆW5÷vR‚’° ––b‚7W'&VçE÷W6W%ö6â‚6VÆc£¤4’bb7W'&VçE÷W6W%ö6â‚vÖævUö÷F–öç2r’’° —wöF–R‚W65ö‡FÖÅõò‚u–÷RFòæ÷B†fRW&Ö—76–öâFòÖævRF&ÆR"6öFW2ârÂvF÷Vv†&÷72r’“° —Ð  ’F—77VVBÒçVÆÃ° ’FW'&÷"ÒçVÆÃ° ’G&WVW7EöÖWF†öBÒ—76WB‚Eõ4U%dU%²u$UTU5EôÔUD„ôBuÒ’ò7G'F÷WW"‚6æ—F—¦U÷FW‡Eöf–VÆB‚w÷Vç6Æ6‚‚Eõ4U%dU%²u$UTU5EôÔUD„ôBuÒ’’’¢rs° ––b‚uõ5BrÓÓÒG&WVW7EöÖWF†öB’° –6†V6µöFÖ–å÷&VfW&W"‚vF÷Vv†&÷75÷F&ÆU÷"r“° ’F7F–öâÒ—76WB‚Eõõ5E²wF&ÆUö7F–öâuÒ’ò6æ—F—¦Uö¶W’‚w÷Vç6Æ6‚‚Eõõ5E²wF&ÆUö7F–öâuÒ’’¢rs° ––b‚v7&VFRrÓÓÒF7F–öâ’° ’F—77VVBÒF÷Vv„&÷75õF&ÆUõ#£¦7&VFU÷F&ÆR€ –—76WB‚Eõõ5E²vÆö6F–öåö–BuÒ’ò'6–çB‚Eõõ5E²vÆö6F–öåö–BuÒ’¢À –—76WB‚Eõõ5E²wF&ÆUöÆ&VÂuÒ’ò6æ—F—¦U÷FW‡Eöf–VÆB‚w÷Vç6Æ6‚‚Eõõ5E²wF&ÆUöÆ&VÂuÒ’’¢rrÀ –—76WB‚Eõõ5E²wF&ÆU÷¦öæRuÒ’ò6æ—F—¦U÷FW‡Eöf–VÆB‚w÷Vç6Æ6‚‚Eõõ5E²wF&ÆU÷¦öæRuÒ’’¢rrÀ –—76WB‚Eõõ5E²v÷&FW&–æu÷W&ÂuÒ’òW65÷W&Å÷&r‚w÷Vç6Æ6‚‚Eõõ5E²v÷&FW&–æu÷W&ÂuÒ’’¢rp ’“° —ÒVÇ6V–b‚v7&VFU÷6²rÓÓÒF7F–öâ’° ’G&uöÆ&VÇ2Ò—76WB‚Eõõ5E²wF&ÆUöÆ&VÇ2uÒ’ò6æ—F—¦U÷FW‡F&Vöf–VÆB‚w÷Vç6Æ6‚‚Eõõ5E²wF&ÆUöÆ&VÇ2uÒ’’¢rs° ’FÆ&VÇ2Ò&Vu÷7Æ—B‚rõµÇ%ÆâÅÒ²òrÂG&uöÆ&VÇ2ÂÓÂ$Tuõ5Ä•EôäõôTÕE’“° ’F—77VVBÒF÷Vv„&÷75õF&ÆUõ#£¦7&VFU÷6²€ –—76WB‚Eõõ5E²vÆö6F–öåö–BuÒ’ò'6–çB‚Eõõ5E²vÆö6F–öåö–BuÒ’¢À –—5ö'&’‚FÆ&VÇ2’òFÆ&VÇ2¢'&’‚’À –—76WB‚Eõõ5E²wF&ÆU÷¦öæRuÒ’ò6æ—F—¦U÷FW‡Eöf–VÆB‚w÷Vç6Æ6‚‚Eõõ5E²wF&ÆU÷¦öæRuÒ’’¢rrÀ –—76WB‚Eõõ5E²v÷&FW&–æu÷W&ÂuÒ’òW65÷W&Å÷&r‚w÷Vç6Æ6‚‚Eõõ5E²v÷&FW&–æu÷W&ÂuÒ’’¢rp ’“° —ÒVÇ6V–b‚w&÷FFRrÓÓÒF7F–öâ’° ’F—77VVBÒF÷Vv„&÷75õF&ÆUõ#£¦—77VUö6öFR‚—76WB‚Eõõ5E²wF&ÆUö–BuÒ’ò'6–çB‚Eõõ5E²wF&ÆUö–BuÒ’¢“° —ÒVÇ6V–b‚v7F—fFRrÓÓÒF7F–öâÇÂvFV7F—fFRrÓÓÒF7F–öâ’° ’G&W7VÇBÒF÷Vv„&÷75õF&ÆUõ#£§6WEö7F—fR‚—76WB‚Eõõ5E²wF&ÆUö–BuÒ’ò'6–çB‚Eõõ5E²wF&ÆUö–BuÒ’¢Âv7F—fFRrÓÓÒF7F–öâ“° ––b‚—5÷wöW'&÷"‚G&W7VÇB’’° ’FW'&÷"ÒG&W7VÇC° —Ð —Ð ––b‚—5÷wöW'&÷"‚F—77VVB’’° ’FW'&÷"ÒF—77VVC° ’F—77VVBÒçVÆÃ° —ÒVÇ6V–b‚—5ö'&’‚F—77VVB’bb—76WB‚F—77VVE²v6öFRuÒ’’° ’F—77VVBÒ'&’‚F—77VVB“° —Ð —Ð  ’FÆö6F–öç2ÒF÷Vv„&÷75ôÆö6F–öç3£¦ÆÂ‚G'VR“° ’GF&ÆW2ÒF÷Vv„&÷75õF&ÆUõ#£¦ÆÅ÷F&ÆW2‚“° “óà “ÆF—b6Æ73Ò'w&F÷Vv†&÷72ÖFÖ–â#à “ÆƒãÃ÷‡W65ö‡FÖÅöR‚tF–æ–ærF&ÆW2b"6öFW2rÂvF÷Vv†&÷72r“²óãÂöƒà “ÇãÃ÷‡W65ö‡FÖÅöR‚tV6‚&–çFVB6öFR—2W&ÖæVçFÇ’F–VBFòöæR7F÷&RæBF&ÆRâF†R7W7FöÖW"66ç2—BÂVçFW'2F†V—"æÖRB6†V6¶÷WBÂæBF†R¶—F6†Vâ&V6V—fW2&÷F‚F†V—"æÖRæBF&ÆRârÂvF÷Vv†&÷72r“²óãÂ÷à “Ã÷‡–b‚FW'&÷"’¢óà “ÆF—b6Æ73Ò&æ÷F–6Ræ÷F–6RÖW'&÷"#ãÇãÃ÷‡V6†òW65ö‡FÖÂ‚FW'&÷"ÓævWEöW'&÷%öÖW76vR‚’“²óãÂ÷ãÂöF—cà “Ã÷‡VæF–c²óà  “Ã÷‡–b‚F—77VVB’¢óà “ÆF—b6Æ73Ò&æ÷F–6Ræ÷F–6R×v&æ–ær#ãÇãÇ7G&öæsãÃ÷‡V6†òW65ö‡FÖÂ‚ÓÓÒ6÷VçB‚F—77VVB’òõò‚u&–çBF†—2"æ÷rârÂvF÷Vv†&÷72r’¢õò‚u&–çBF†—2"6²æ÷rârÂvF÷Vv†&÷72r’“²óãÂ÷7G&öæsâÃ÷‡W65ö‡FÖÅöR‚tf÷"6V7W&—G’Â&V&W"6öFW2&Ræ÷B7F÷&VBæB6ææ÷B&R6†÷vâv–ââ6fRFòDb÷"&–çB&Vf÷&RÆVf–ærF†—2vRâ&÷FF–ær7&VFW2&WÆ6VÖVçBæB–ÖÖVF–FVÇ’–çfÆ–FFW2F†RöÆB&–çBârÂvF÷Vv†&÷72r“²óãÂ÷ãÂöF—cà “ÆF—b–CÒ&F÷Vv†&÷72×"×&–çB#à “Ã÷‡f÷&V6‚‚F—77VVB2F–æFW‚ÓâG"’¢óà “Ç6V7F–öâ6Æ73Ò&F÷Vv†&÷72×"ÖÆ&VÂ"FF×"ÖÆ&VÃÒ#Ã÷‡V6†òW65öGG"‚G%²vÆ&VÂuÒ“²óâ"7G–ÆSÒ&&6¶w&÷VæC¢6ffc¶&÷&FW#£'‚6öÆ–B3¶Ö‚×v–GFƒ£CCƒ·FF–æs£#‡ƒ·FW‡BÖÆ–vã¦6VçFW#¶Ö&v–ã£#'ƒ¶'&V²Ö–ç6–FS¦fö–C·vRÖ'&V²Ö–ç6–FS¦fö–C²#à “Ç7G–ÆSÒ&föçB×6—¦S£Gƒ¶föçB×vV–v‡C£s¶ÆWGFW"×76–æs¢ã†VÓ¶Ö&v–ã£gƒ²#ãÃ÷‡V6†òW65ö‡FÖÂ‚7&–çFb‚õò‚tDõTt‚$õ52W2rÂvF÷Vv†&÷72r’Â—76WB‚G%²vÆö6F–öåöæÖRuÒ’ò7G'F÷WW"‚G%²vÆö6F–öåöæÖRuÒ’¢rr’“²óãÂ÷à “Æƒ"7G–ÆSÒ&föçB×6—¦S£3ƒ¶Ö&v–ã£‡ƒ²#ãÃ÷‡V6†òW65ö‡FÖÂ‚7&–çFb‚õò‚uD$ÄRW2rÂvF÷Vv†&÷72r’ÂG%²vÆ&VÂuÒ’“²óãÂöƒ#à “Ç7G–ÆSÒ&föçB×6—¦S£‡ƒ²#ãÃ÷‡W65ö‡FÖÅöR‚u66âFò÷&FW"g&öÒF†—2F&ÆRâ6†V6²–÷W"F&ÆRçVÖ&W"Â6†ö÷6R–÷W"fööBæB’6V7W&VÇ’v†Vâ÷&FW&–ær—2÷VâârÂvF÷Vv†&÷72r“²óãÂ÷à “ÆF—b6Æ73Ò&F÷Vv†&÷72×"Ö6öFR"FF×W&ÃÒ#Ã÷‡V6†òW65öGG"‚G%²wW&ÂuÒ“²óâ"FFÖ–æFWƒÒ#Ã÷‡V6†òW65öGG"‚F–æFW‚“²óâ"7G–ÆSÒ&F—7Æ“¦fÆWƒ¶§W7F–g’Ö6öçFVçC¦6VçFW#¶Ö&v–ã£‡ƒ²#ãÂöF—cà “ÇãÆ6öFSãÃ÷‡V6†òW65ö‡FÖÂ‚G%²wW&ÂuÒ“²óãÂö6öFSãÂ÷à “Æ'WGFöâG—SÒ&'WGFöâ"6Æ73Ò&'WGFöâF"ÖF÷væÆöB×""FFÖ–æFWƒÒ#Ã÷‡V6†òW65öGG"‚F–æFW‚“²óâ"FFÖÆ&VÃÒ#Ã÷‡V6†òW65öGG"‚G%²vÆ&VÂuÒ“²óâ"FFÖÆö6F–öãÒ#Ã÷‡V6†òW65öGG"‚—76WB‚G%²vÆö6F–öåöæÖRuÒ’òG%²vÆö6F–öåöæÖRuÒ¢w7F÷&Rr“²óâ#ãÃ÷‡W65ö‡FÖÅöR‚tF÷væÆöB5drrÂvF÷Vv†&÷72r“²óãÂö'WGFöãà “Â÷6V7F–öãà “Ã÷‡VæFf÷&V6ƒ²óà “ÂöF—cà “Ç6Æ73Ò&F"×"×&–çBÖ7F–öç2#ãÆ'WGFöâG—SÒ&'WGFöâ"6Æ73Ò&'WGFöâ'WGFöâ×&–Ö'’"öæ6Æ–6³Ò'v–æF÷rç&–çB‚’#ãÃ÷‡W65ö‡FÖÅöR‚u&–çBò6fR"6²2DbrÂvF÷Vv†&÷72r“²óãÂö'WGFöããÂ÷à “Ç7G–ÆRÖVF–Ò'&–çB#â7wFÖ–æ&"Â6FÖ–æÖVçVÖ–âÂ7wfö÷FW"Âçw&æF÷Vv†&÷72ÖFÖ–ãæƒÂçw&æF÷Vv†&÷72ÖFÖ–ãçÂçw&æF÷Vv†&÷72ÖFÖ–ãæƒ"Âçw&æF÷Vv†&÷72ÖFÖ–ãæf÷&ÒÂçw&æF÷Vv†&÷72ÖFÖ–ãçF&ÆRÂææ÷F–6RÂæF"×"×&–çBÖ7F–öç2ÂæF"ÖF÷væÆöB×'¶F—7Æ“¦æöæR–×÷'FçGÒ7w6öçFVçG¶Ö&v–ã£–×÷'FçGÒ6F÷Vv†&÷72×"×&–çG¶F—7Æ“¦w&–B–×÷'FçC¶w&–B×FV×ÆFRÖ6öÇVÖç3§&WVBƒ"Ãg"“¶v£g‡ÒæF÷Vv†&÷72×"ÖÆ&VÇ¶Ö&v–ã£–×÷'FçC¶Ö‚×v–GFƒ¦æöæR–×÷'FçGÒæF÷Vv†&÷72×"ÖÆ&VÂ7fw¶Ö‚×v–GFƒ£#Cƒ¶†V–v‡C¦WF÷×ÓÂ÷7G–ÆSà “Ç67&—Cà –Fö7VÖVçBæFDWfVçDÆ—7FVæW"‚tDôÔ6öçFVçDÆöFVBrÂgVæ7F–öâ‚’° —f"Ö÷VçG2ÒFö7VÖVçBçVW'•6VÆV7F÷$ÆÂ‚ræF÷Vv†&÷72×"Ö6öFRr“° ––b‚Ö÷VçG2æÆVæwF‚ÇÂG—Vöb&6öFRÓÒvgVæ7F–öâr’&WGW&ã° –Ö÷VçG2æf÷$V6‚†gVæ7F–öâ†Ö÷VçB’° —f"6öFRÒ&6öFRƒÂtÒr“° –6öFRæFDFF†Ö÷VçBævWDGG&–'WFR‚vFF×W&Âr’Ât'—FRr“° –6öFRæÖ¶R‚“° –Ö÷VçBæ–ææW$…DÔÂÒ6öFRæ7&VFU7fuFr‡²6VÆÅ6—¦S¢bÂÖ&v–ã¢BÂ66Æ&ÆS¢G'VRÒ“° —Ò“° –Fö7VÖVçBçVW'•6VÆV7F÷$ÆÂ‚ræF"ÖF÷væÆöB×"r’æf÷$V6‚†gVæ7F–öâ†'WGFöâ’° –'WGFöâæFDWfVçDÆ—7FVæW"‚v6Æ–6²rÂgVæ7F–öâ‚’° —f"Ö÷VçBÒFö7VÖVçBçVW'•6VÆV7F÷"‚ræF÷Vv†&÷72×"Ö6öFU¶FFÖ–æFWƒÒ"r²'WGFöâævWDGG&–'WFR‚vFFÖ–æFW‚r’²r%Òr“° —f"7frÒÖ÷VçBbbÖ÷VçBçVW'•6VÆV7F÷"‚w7frr“° ––b‚7fr’&WGW&ã° —f"&Æö"ÒæWr&Æö"…¶æWr„ÔÅ6W&–Æ—¦W"‚’ç6W&–Æ—¦UFõ7G&–ær‡7fr•ÒÂ·G—S¢v–ÖvR÷7fr·†ÖÃ¶6†'6WC×WFbÓ‚wÒ“° —f"Æ–æ²ÒFö7VÖVçBæ7&VFTVÆVÖVçB‚vr“° –Æ–æ²æ‡&VbÒU$Âæ7&VFTö&¦V7EU$Â†&Æö"“° —f"7F÷&RÒ7G&–ær†'WGFöâævWDGG&–'WFR‚vFFÖÆö6F–öâr’ÇÂw7F÷&Rr’ç&WÆ6R‚õµæ×£Ó•òÕÒ²öv’ÂrÒr“° –Æ–æ²æF÷væÆöBÒvF÷Vv†&÷72Òr²7F÷&R²r×F&ÆRÒr²7G&–ær†'WGFöâævWDGG&–'WFR‚vFFÖÆ&VÂr’ÇÂw"r’ç&WÆ6R‚õµæ×£Ó•òÕÒ²öv’ÂrÒr’²rç7frs° –Æ–æ²æ6Æ–6²‚“° —6WEF–ÖV÷WB†gVæ7F–öâ‚’²U$Âç&Wfö¶Tö&¦V7EU$Â†Æ–æ²æ‡&Vb“²ÒÂ“° —Ò“° —Ò“° —Ò“° “Â÷67&—Cà “Ã÷‡VæF–c²óà  “Æƒ#ãÃ÷‡W65ö‡FÖÅöR‚u&WfW6'’ÆVæ6‚"6²rÂvF÷Vv†&÷72r“²óãÂöƒ#à “ÇãÃ÷‡W65ö‡FÖÅöR‚t7&VFRÆÂ6öæf—&ÖVBF&ÆRÆ&VÇ2–âöæRÖævW"ÖöæÇ’÷W&F–öââF†RÆ&VÇ2&VÆ÷r&RÆVæ6‚FV×ÆFS¢VF—BF†VÒFòÖF6‚F†R‡—6–6Â&WfW6'’fÆö÷"&Vf÷&R7&VF–ærF†R6²ârÂvF÷Vv†&÷72r“²óãÂ÷à “Æf÷&ÒÖWF†öCÒ'÷7B#à “Ã÷‡wöæöæ6Uöf–VÆB‚vF÷Vv†&÷75÷F&ÆU÷"r“²óà “Æ–çWBG—SÒ&†–FFVâ"æÖSÒ'F&ÆUö7F–öâ"fÇVSÒ&7&VFU÷6²"óà “ÇF&ÆR6Æ73Ò&f÷&Ò×F&ÆR#ãÇF&öG“à “ÇG#ãÇFƒãÆÆ&VÂf÷#Ò&F"×6²ÖÆö6F–öâ#ãÃ÷‡W65ö‡FÖÅöR‚u7F÷&RrÂvF÷Vv†&÷72r“²óãÂöÆ&VÃãÂ÷FƒãÇFCãÇ6VÆV7B–CÒ&F"×6²ÖÆö6F–öâ"æÖSÒ&Æö6F–öåö–B"&WV—&VCãÆ÷F–öâfÇVSÒ"#ãÃ÷‡W65ö‡FÖÅöR‚t6†ö÷6R7F÷&RrÂvF÷Vv†&÷72r“²óãÂö÷F–öããÃ÷‡f÷&V6‚‚FÆö6F–öç22FÆö6F–öâ’¢óãÆ÷F–öâfÇVSÒ#Ã÷‡V6†òW65öGG"‚FÆö6F–öâÓæ–B“²óâ"Ã÷‡6VÆV7FVB‚w&WfW6'’rÂ6æ—F—¦U÷F—FÆR‚FÆö6F–öâÓææÖR’“²óããÃ÷‡V6†òW65ö‡FÖÂ‚FÆö6F–öâÓææÖR“²óãÂö÷F–öããÃ÷‡VæFf÷&V6ƒ²óãÂ÷6VÆV7CãÂ÷FCãÂ÷G#à “ÇG#ãÇFƒãÆÆ&VÂf÷#Ò&F"×6²ÖÆ&VÇ2#ãÃ÷‡W65ö‡FÖÅöR‚t6öæf—&ÖVBF&ÆRÆ&VÇ2rÂvF÷Vv†&÷72r“²óãÂöÆ&VÃãÂ÷FƒãÇFCãÇFW‡F&V–CÒ&F"×6²ÖÆ&VÇ2"æÖSÒ'F&ÆUöÆ&VÇ2"&÷w3Ò#""6Æ73Ò&Æ&vR×FW‡B"&WV—&VCãÃ÷‡V6†òW65÷FW‡F&V‚–×ÆöFR‚%Æâ"Â&ævR‚Â"’’“²óãÂ÷FW‡F&VãÇ6Æ73Ò&FW67&—F–öâ#ãÃ÷‡W65ö‡FÖÅöR‚töæRÆ&VÂW"Æ–æR†Ö†–×VÒS’âFVÆWFRç’çVÖ&W"F†B—2æ÷B‡—6–6ÆÇ’öâF†R&WfW6'’fÆö÷"âW†—7F–ærÆ&VÇ2&R&V¦V7FVB&Vf÷&Rç—F†–ær—27&VFVBârÂvF÷Vv†&÷72r“²óãÂ÷ãÂ÷FCãÂ÷G#à “ÇG#ãÇFƒãÆÆ&VÂf÷#Ò&F"×6²×¦öæR#ãÃ÷‡W65ö‡FÖÅöR‚u¦öæRrÂvF÷Vv†&÷72r“²óãÂöÆ&VÃãÂ÷FƒãÇFCãÆ–çWB–CÒ&F"×6²×¦öæR"æÖSÒ'F&ÆU÷¦öæR"G—SÒ'FW‡B"Ö†ÆVæwFƒÒ#ƒ"fÇVSÒ$F–æ–ær&ööÒ"óãÂ÷FCãÂ÷G#à “ÇG#ãÇFƒãÆÆ&VÂf÷#Ò&F"×6²Ö÷&FW&–ær×W&Â#ãÃ÷‡W65ö‡FÖÅöR‚t÷&FW"vRU$ÂrÂvF÷Vv†&÷72r“²óãÂöÆ&VÃãÂ÷FƒãÇFCãÆ–çWB–CÒ&F"×6²Ö÷&FW&–ær×W&Â"æÖSÒ&÷&FW&–æu÷W&Â"G—SÒ'W&Â"6Æ73Ò'&VwVÆ"×FW‡B"&WV—&VBfÇVSÒ#Ã÷‡V6†òW65öGG"‚†öÖU÷W&Â‚rö÷&FW"òr’“²óâ"óãÇ6Æ73Ò&FW67&—F–öâ#ãÃ÷‡W65ö‡FÖÅöR‚uW6RF†RV&Æ—6†VB6ÖR×6—FRvR6öçF–æ–ærF†RF÷Vv„&÷72ÖVçRæB6†V6¶÷WBârÂvF÷Vv†&÷72r“²óãÂ÷ãÂ÷FCãÂ÷G#à “Â÷F&öG“ãÂ÷F&ÆSà “Ã÷‡7V&Ö—Eö'WGFöâ‚õò‚t7&VFR&WfW6'’"6²rÂvF÷Vv†&÷72r’“²óà “Âöf÷&Óà  “Æƒ#ãÃ÷‡W65ö‡FÖÅöR‚tFBF&ÆRrÂvF÷Vv†&÷72r“²óãÂöƒ#à “Æf÷&ÒÖWF†öCÒ'÷7B#à “Ã÷‡wöæöæ6Uöf–VÆB‚vF÷Vv†&÷75÷F&ÆU÷"r“²óà “Æ–çWBG—SÒ&†–FFVâ"æÖSÒ'F&ÆUö7F–öâ"fÇVSÒ&7&VFR"óà “ÇF&ÆR6Æ73Ò&f÷&Ò×F&ÆR#ãÇF&öG“à “ÇG#ãÇFƒãÆÆ&VÂf÷#Ò&F"×F&ÆRÖÆö6F–öâ#ãÃ÷‡W65ö‡FÖÅöR‚u7F÷&RrÂvF÷Vv†&÷72r“²óãÂöÆ&VÃãÂ÷FƒãÇFCãÇ6VÆV7B–CÒ&F"×F&ÆRÖÆö6F–öâ"æÖSÒ&Æö6F–öåö–B"&WV—&VCãÆ÷F–öâfÇVSÒ"#ãÃ÷‡W65ö‡FÖÅöR‚t6†ö÷6R7F÷&RrÂvF÷Vv†&÷72r“²óãÂö÷F–öããÃ÷‡f÷&V6‚‚FÆö6F–öç22FÆö6F–öâ’¢óãÆ÷F–öâfÇVSÒ#Ã÷‡V6†òW65öGG"‚FÆö6F–öâÓæ–B“²óâ#ãÃ÷‡V6†òW65ö‡FÖÂ‚FÆö6F–öâÓææÖR“²óãÂö÷F–öããÃ÷‡VæFf÷&V6ƒ²óãÂ÷6VÆV7CãÂ÷FCãÂ÷G#à “ÇG#ãÇFƒãÆÆ&VÂf÷#Ò&F"×F&ÆRÖÆ&VÂ#ãÃ÷‡W65ö‡FÖÅöR‚uF&ÆRçVÖ&W"òÆ&VÂrÂvF÷Vv†&÷72r“²óãÂöÆ&VÃãÂ÷FƒãÇFCãÆ–çWB–CÒ&F"×F&ÆRÖÆ&VÂ"æÖSÒ'F&ÆUöÆ&VÂ"G—SÒ'FW‡B"Ö†ÆVæwFƒÒ#ƒ"&WV—&VBÆ6V†öÆFW#Ò#""óãÂ÷FCãÂ÷G#à “ÇG#ãÇFƒãÆÆ&VÂf÷#Ò&F"×F&ÆR×¦öæR#ãÃ÷‡W65ö‡FÖÅöR‚u¦öæR†÷F–öæÂ’rÂvF÷Vv†&÷72r“²óãÂöÆ&VÃãÂ÷FƒãÇFCãÆ–çWB–CÒ&F"×F&ÆR×¦öæR"æÖSÒ'F&ÆU÷¦öæR"G—SÒ'FW‡B"Ö†ÆVæwFƒÒ#ƒ"Æ6V†öÆFW#Ò$6÷W'G–&B"óãÂ÷FCãÂ÷G#à “ÇG#ãÇFƒãÆÆ&VÂf÷#Ò&F"Ö÷&FW&–ær×W&Â#ãÃ÷‡W65ö‡FÖÅöR‚tÖVçRvRU$ÂrÂvF÷Vv†&÷72r“²óãÂöÆ&VÃãÂ÷FƒãÇFCãÆ–çWB–CÒ&F"Ö÷&FW&–ær×W&Â"æÖSÒ&÷&FW&–æu÷W&Â"G—SÒ'W&Â"6Æ73Ò'&VwVÆ"×FW‡B"&WV—&VBfÇVSÒ#Ã÷‡V6†òW65öGG"‚†öÖU÷W&Â‚ròr’“²óâ"óãÇ6Æ73Ò&FW67&—F–öâ#ãÃ÷‡W65ö‡FÖÅöR‚t×W7B&RvRöâF†—2v÷&E&W726—FR6öçF–æ–ærF†RF÷Vv„&÷72ÖVçRö6'BârÂvF÷Vv†&÷72r“²óãÂ÷ãÂ÷FCãÂ÷G#à “Â÷F&öG“ãÂ÷F&ÆSà “Ã÷‡7V&Ö—Eö'WGFöâ‚õò‚t7&VFRF&ÆRæB"rÂvF÷Vv†&÷72r’“²óà “Âöf÷&Óà  “Æƒ#ãÃ÷‡W65ö‡FÖÅöR‚tW†—7F–ærF&ÆW2rÂvF÷Vv†&÷72r“²óãÂöƒ#à “ÇF&ÆR6Æ73Ò'v–FVfB7G&—VB#ãÇF†VCãÇG#ãÇFƒãÃ÷‡W65ö‡FÖÅöR‚u7F÷&RrÂvF÷Vv†&÷72r“²óãÂ÷FƒãÇFƒãÃ÷‡W65ö‡FÖÅöR‚uF&ÆRrÂvF÷Vv†&÷72r“²óãÂ÷FƒãÇFƒãÃ÷‡W65ö‡FÖÅöR‚u¦öæRrÂvF÷Vv†&÷72r“²óãÂ÷FƒãÇFƒãÃ÷‡W65ö‡FÖÅöR‚u7FFRrÂvF÷Vv†&÷72r“²óãÂ÷FƒãÇFƒãÃ÷‡W65ö‡FÖÅöR‚tÆ7B66ârÂvF÷Vv†&÷72r“²óãÂ÷FƒãÇFƒãÃ÷‡W65ö‡FÖÅöR‚t7F–öç2rÂvF÷Vv†&÷72r“²óãÂ÷FƒãÂ÷G#ãÂ÷F†VCãÇF&öG“à “Ã÷‡–b‚GF&ÆW2’¢óãÇG#ãÇFB6öÇ7ãÒ#b#ãÃ÷‡W65ö‡FÖÅöR‚tæòF–æ–ærF&ÆW2†fR&VVâ7&VFVBârÂvF÷Vv†&÷72r“²óãÂ÷FCãÂ÷G#ãÃ÷‡VæF–c²óà “Ã÷‡f÷&V6‚‚GF&ÆW22GF&ÆR’¢óà “ÇG#ãÇFCãÃ÷‡V6†òW65ö‡FÖÂ‚GF&ÆRÓæÆö6F–öåöæÖR“²óãÂ÷FCãÇFCãÇ7G&öæsãÃ÷‡V6†òW65ö‡FÖÂ‚GF&ÆRÓæÆ&VÂ“²óãÂ÷7G&öæsãÂ÷FCãÇFCãÃ÷‡V6†òW65ö‡FÖÂ‚GF&ÆRÓç¦öæR“²óãÂ÷FCãÇFCãÃ÷‡V6†òGF&ÆRÓæ—5ö7F—fRòW65ö‡FÖÅõò‚t7F—fRrÂvF÷Vv†&÷72r’¢W65ö‡FÖÅõò‚t–æ7F—fRrÂvF÷Vv†&÷72r“²óãÂ÷FCãÇFCãÃ÷‡V6†òGF&ÆRÓæÆ7E÷66ææVEöBòW65ö‡FÖÂ‚GF&ÆRÓæÆ7E÷66ææVEöB’¢W65ö‡FÖÅõò‚tæWfW"rÂvF÷Vv†&÷72r“²óãÂ÷FCãÇFCà “Æf÷&ÒÖWF†öCÒ'÷7B"7G–ÆSÒ&F—7Æ“¦–æÆ–æS²#ãÃ÷‡wöæöæ6Uöf–VÆB‚vF÷Vv†&÷75÷F&ÆU÷"r“²óãÆ–çWBG—SÒ&†–FFVâ"æÖSÒ'F&ÆUö–B"fÇVSÒ#Ã÷‡V6†òW65öGG"‚GF&ÆRÓæ–B“²óâ"óãÆ–çWBG—SÒ&†–FFVâ"æÖSÒ'F&ÆUö7F–öâ"fÇVSÒ#Ã÷‡V6†òGF&ÆRÓæ—5ö7F—fRòvFV7F—fFRr¢v7F—fFRs²óâ"óãÆ'WGFöâ6Æ73Ò&'WGFöâ#ãÃ÷‡V6†òGF&ÆRÓæ—5ö7F—fRòW65ö‡FÖÅõò‚tFV7F—fFRrÂvF÷Vv†&÷72r’¢W65ö‡FÖÅõò‚t7F—fFRrÂvF÷Vv†&÷72r“²óãÂö'WGFöããÂöf÷&Óà “Æf÷&ÒÖWF†öCÒ'÷7B"7G–ÆSÒ&F—7Æ“¦–æÆ–æS²"öç7V&Ö—CÒ'&WGW&â6öæf—&Ò‚sÃ÷‡V6†òW65ö§2‚õò‚u&÷FFRF†—2#òWfW'’öÆB&–çBæB7F—fRF&ÆR6W76–öâv–ÆÂ7F÷v÷&¶–ær–ÖÖVF–FVÇ’ârÂvF÷Vv†&÷72r’“²óâr“²#ãÃ÷‡wöæöæ6Uöf–VÆB‚vF÷Vv†&÷75÷F&ÆU÷"r“²óãÆ–çWBG—SÒ&†–FFVâ"æÖSÒ'F&ÆUö–B"fÇVSÒ#Ã÷‡V6†òW65öGG"‚GF&ÆRÓæ–B“²óâ"óãÆ–çWBG—SÒ&†–FFVâ"æÖSÒ'F&ÆUö7F–öâ"fÇVSÒ'&÷FFR"óãÆ'WGFöâ6Æ73Ò&'WGFöâ#ãÃ÷‡W65ö‡FÖÅöR‚u&÷FFRb&–çBrÂvF÷Vv†&÷72r“²óãÂö'WGFöããÂöf÷&Óà “Â÷FCãÂ÷G#à “Ã÷‡VæFf÷&V6ƒ²óà “Â÷F&öG“ãÂ÷F&ÆSà “ÂöF—cà “Ã÷‡  —Ð§Ð 