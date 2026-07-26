<?php
/**
 * Role-aware presentation for DoughBoss operational staff.
 *
 * Kitchen and manager accounts should land in their operational workspace, not
 * in an unrelated WordPress screen. This class deliberately changes only
 * presentation and navigation; existing capability checks on every admin and
 * REST endpoint remain the source of access control.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DoughBoss_Staff_Experience {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'login_redirect', array( $this, 'redirect_after_login' ), 100, 3 );
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
		add_action( 'admin_menu', array( $this, 'trim_staff_menu' ), 999 );
		add_action( 'admin_head', array( $this, 'print_admin_styles' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'print_login_styles' ) );
	}

	/**
	 * Redirect operational accounts to the surface they actually need.
	 *
	 * @param string           $redirect_to Requested fallback URL.
	 * @param string           $requested   Requested redirect URL.
	 * @param WP_User|WP_Error $user        Authenticated user when successful.
	 * @return string
	 */
	public function redirect_after_login( $redirect_to, $requested, $user ) {
		unset( $requested );

		if ( ! ( $user instanceof WP_User ) ) {
			return $redirect_to;
		}

		if ( in_array( 'doughboss_kitchen', (array) $user->roles, true ) ) {
			return admin_url( 'admin.php?page=doughboss-board' );
		}

		if ( in_array( 'doughboss_manager', (array) $user->roles, true ) ) {
			return admin_url( 'admin.php?page=doughboss-dashboard' );
		}

		return $redirect_to;
	}

	/**
	 * Add a stable role class to the admin body.
	 *
	 * @param string $classes Existing classes.
	 * @return string
	 */
	public function admin_body_class( $classes ) {
		$role = $this->current_staff_role();

		if ( 'kitchen' === $role ) {
			$classes .= ' doughboss-staff doughboss-staff-kitchen';
		} elseif ( 'manager' === $role ) {
			$classes .= ' doughboss-staff doughboss-staff-manager';
		}

		return $classes;
	}

	/**
	 * Remove unrelated WordPress destinations for operational staff.
	 *
	 * This is a navigation simplification, not a security boundary. Capabilities
	 * remain enforced by WordPress and the DoughBoss endpoints themselves.
	 *
	 * @return void
	 */
	public function trim_staff_menu() {
		$role = $this->current_staff_role();
		if ( '' === $role ) {
			return;
		}

		$core_pages = array(
			'index.php',
			'edit.php',
			'upload.php',
			'edit.php?post_type=page',
			'edit-comments.php',
			'themes.php',
			'plugins.php',
			'users.php',
			'tools.php',
			'options-general.php',
		);

		foreach ( $core_pages as $page ) {
			remove_menu_page( $page );
		}

		if ( 'kitchen' === $role ) {
			// The KDS is full-screen below, so even the remaining sidebar is noise.
			remove_menu_page( 'admin.php?page=doughboss' );
			remove_menu_page( 'admin.php?page=doughboss-dashboard' );
		}
	}

	/**
	 * Return the current user's operational role, if any.
	 *
	 * @return string kitchen, manager or an empty string.
	 */
	private function current_staff_role() {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return '';
		}

		$roles = (array) $user->roles;
		if ( in_array( 'doughboss_kitchen', $roles, true ) ) {
			return 'kitchen';
		}
		if ( in_array( 'doughboss_manager', $roles, true ) ) {
			return 'manager';
		}

		return '';
	}

	/**
	 * Print restrained branding for the admin surfaces used by staff.
	 *
	 * @return void
	 */
	public function print_admin_styles() {
		$role = $this->current_staff_role();
		if ( '' === $role ) {
			return;
		}
		?>
		<style id="doughboss-staff-experience">
			:root{--db-ink:#110d0a;--db-paper:#f8f3e9;--db-cream:#efe5d3;--db-fire:#e24d28;--db-fire-dark:#b83118;--db-gold:#e7b355;--db-green:#236b4b;}
			body.doughboss-staff{background:var(--db-paper);font-family:Barlow,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}
			body.doughboss-staff #wpadminbar{background:var(--db-ink);}
			body.doughboss-staff #wpadminbar .ab-item,body.doughboss-staff #wpadminbar a.ab-item{color:#f8f3e9;}
			body.doughboss-staff #wpadminbar #wp-admin-bar-wp-logo,body.doughboss-staff #wpadminbar #wp-admin-bar-comments,body.doughboss-staff #wpadminbar #wp-admin-bar-new-content{display:none;}
			body.doughboss-staff-manager #adminmenuwrap,body.doughboss-staff-manager #adminmenuback{background:var(--db-ink);}
			body.doughboss-staff-manager #adminmenu a{color:#e7ddcd;}
			body.doughboss-staff-manager #adminmenu .wp-has-current-submenu>a.menu-top,body.doughboss-staff-manager #adminmenu .current>a.menu-top,body.doughboss-staff-manager #adminmenu a:hover{background:var(--db-fire);color:#fff;}
			body.doughboss-staff-manager #wpcontent{background:var(--db-paper);}
			body.doughboss-staff-manager .wrap>h1,body.doughboss-staff-manager .wrap h2{font-family:"Arial Narrow",Impact,sans-serif;letter-spacing:.035em;color:var(--db-ink);}
			body.doughboss-staff-manager .button-primary{background:var(--db-fire);border-color:var(--db-fire-dark);box-shadow:none;text-shadow:none;}
			body.doughboss-staff-manager .button-primary:hover,body.doughboss-staff-manager .button-primary:focus{background:var(--db-fire-dark);border-color:var(--db-fire-dark);}
			body.doughboss-staff-kitchen #wpadminbar,body.doughboss-staff-kitchen #adminmenumain,body.doughboss-staff-kitchen #wpfooter{display:none;}
			body.doughboss-staff-kitchen #wpcontent,body.doughboss-staff-kitchen #wpbody-content{margin:0;padding:0;}
			body.doughboss-staff-kitchen .doughboss-board-wrap{--dbb-ink:#fff8ee;--dbb-ink-soft:#e1d4c1;--dbb-muted:#c5b7a3;--dbb-muted-2:#9c8d79;--dbb-paper:#fffaf1;--dbb-paper-soft:#fff6e6;--dbb-lane-bg:rgba(255,255,255,.08);--dbb-line:rgba(255,255,255,.15);--dbb-blue:#e7b355;--dbb-red:#ef5b37;--dbb-green:#4ab879;--dbb-green-dark:#236b4b;--dbb-amber:#e7a52e;--dbb-danger:#ef5b37;--dbb-chip-bg:#2b211b;--dbb-chip-ink:#fff8ee;--dbb-chip-delivery-bg:#6b3b1c;--dbb-chip-delivery-ink:#ffe4aa;--dbb-chip-shop-bg:#2f4b43;--dbb-chip-shop-ink:#d7f4e5;background:radial-gradient(100% 80% at 100% 0%,#4a2719 0%,transparent 48%),radial-gradient(80% 70% at 0% 100%,#233b32 0%,transparent 52%),var(--db-ink);min-height:100vh;margin:0;padding:clamp(16px,3vw,34px);}
			body.doughboss-staff-kitchen .db-board-bar{padding:0 0 16px;border-bottom:1px solid rgba(255,255,255,.14);}
			body.doughboss-staff-kitchen .db-board-bar h1{font-family:"Arial Narrow",Impact,sans-serif;font-size:clamp(34px,5vw,58px);letter-spacing:.045em;text-transform:uppercase;color:#fff8ee;}
			body.doughboss-staff-kitchen .db-board-status{color:#e7ddcd;font-weight:600;}
			body.doughboss-staff-kitchen .db-lane{backdrop-filter:blur(12px);border:1px solid rgba(255,255,255,.12);}
			body.doughboss-staff-kitchen .db-lane-title{font-family:"Arial Narrow",Impact,sans-serif;font-size:1.45rem;letter-spacing:.04em;text-transform:uppercase;color:#fff8ee;}
			body.doughboss-staff-kitchen .db-card{box-shadow:0 18px 35px rgba(0,0,0,.18);}
			body.doughboss-staff-kitchen .db-card button,body.doughboss-staff-kitchen .db-board-actions .button{min-height:48px;border-radius:12px;font-weight:800;}
			@media(max-width:782px){body.doughboss-staff-kitchen #wpbody{padding-top:0;}body.doughboss-staff-kitchen .doughboss-board-wrap{padding:16px 12px;}}
		</style>
		<?php
	}

	/**
	 * Brand the standard WordPress sign-in form without changing its security,
	 * validation or password-reset behaviour.
	 *
	 * @return void
	 */
	public function print_login_styles() {
		?>
		<style id="doughboss-login-experience">
			body.login{background:radial-gradient(90% 90% at 90% 0%,#5b2b19 0%,transparent 52%),radial-gradient(70% 70% at 0% 100%,#294238 0%,transparent 55%),#110d0a;}
			body.login #login{width:min(92vw,390px);padding:8vh 0 0;}
			body.login #login h1 a{background:none!important;width:auto;height:auto;text-indent:0;overflow:visible;color:#fff8ee;font:700 clamp(34px,9vw,52px)/1 "Arial Narrow",Impact,sans-serif;letter-spacing:.08em;text-transform:uppercase;text-align:center;}
			body.login #login h1 a:after{content:"KITCHEN & MANAGEMENT";display:block;margin-top:8px;color:#e7b355;font:700 11px/1.2 system-ui,sans-serif;letter-spacing:.26em;}
			body.login form{border:1px solid rgba(255,255,255,.16);border-radius:18px;background:rgba(18,13,10,.78);box-shadow:0 28px 70px rgba(0,0,0,.48);padding:28px;}
			body.login label,body.login .forgetmenot label{color:#f2e7d6;}
			body.login input[type="text"],body.login input[type="password"]{border:1px solid rgba(255,255,255,.2);border-radius:10px;background:rgba(0,0,0,.28);color:#fff;padding:10px 12px;}
			body.login .button-primary{width:100%;min-height:46px;border:0;border-radius:11px;background:#e24d28;box-shadow:none;font-weight:800;text-shadow:none;}
			body.login .button-primary:hover,body.login .button-primary:focus{background:#b83118;}
			body.login #nav a,body.login #backtoblog a,body.login .privacy-policy-link a{color:#e7ddcd;}
		</style>
		<?php
	}
}
