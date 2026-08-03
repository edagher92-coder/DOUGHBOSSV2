<?php
/**
 * Standalone, capability-gated kitchen and management portals.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gives shop devices clean, bookmarkable URLs without exposing a public KDS.
 */
class DoughBoss_Portals {

	const ROUTE_VERSION = '1';
	const QUERY_VAR     = 'doughboss_portal';

	/**
	 * Register routes, rendering and branded authentication hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', array( $this, 'register_routes' ), 1 );
		add_action( 'init', array( $this, 'maybe_flush_routes' ), 30 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'render_requested_portal' ), -200 );

		add_action( 'login_enqueue_scripts', array( $this, 'login_branding' ) );
		add_filter( 'login_headerurl', array( $this, 'login_header_url' ) );
		add_filter( 'login_headertext', array( $this, 'login_header_text' ) );
		add_filter( 'login_message', array( $this, 'login_message' ) );
	}

	/**
	 * Register the public-looking paths. Access is still enforced server-side.
	 *
	 * @return void
	 */
	public function register_routes() {
		add_rewrite_rule( '^kitchen/?$', 'index.php?' . self::QUERY_VAR . '=kitchen', 'top' );
		add_rewrite_rule( '^management/?$', 'index.php?' . self::QUERY_VAR . '=management', 'top' );
	}

	/**
	 * Flush once after a plugin update that changes the route contract.
	 *
	 * @return void
	 */
	public function maybe_flush_routes() {
		if ( self::ROUTE_VERSION === get_option( 'doughboss_portal_routes_version', '' ) ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'doughboss_portal_routes_version', self::ROUTE_VERSION, false );
	}

	/**
	 * Register the portal selector query variable.
	 *
	 * @param string[] $vars Public query variables.
	 * @return string[]
	 */
	public function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Render a requested portal before the theme or maintenance shell runs.
	 *
	 * @return void
	 */
	public function render_requested_portal() {
		$portal = sanitize_key( (string) get_query_var( self::QUERY_VAR ) );
		if ( ! in_array( $portal, array( 'kitchen', 'management' ), true ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		if ( 'kitchen' === $portal ) {
			$this->render_kitchen();
		} else {
			$this->render_management();
		}
		exit;
	}

	/**
	 * Render the touch-first kitchen display.
	 *
	 * @return void
	 */
	private function render_kitchen() {
		if ( ! current_user_can( 'manage_doughboss_kds' ) && ! current_user_can( 'manage_doughboss' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view the kitchen.', 'doughboss' ), esc_html__( 'Kitchen access required', 'doughboss' ), array( 'response' => 403 ) );
		}

		$location_scope = DoughBoss_Staff_Scope::current_location_id();
		if ( is_wp_error( $location_scope ) ) {
			wp_die( esc_html( $location_scope->get_error_message() ), esc_html__( 'Kitchen shop assignment required', 'doughboss' ), array( 'response' => 403 ) );
		}

		// Optional board key remains a second factor for kitchen-only users.
		$required_key = DoughBoss_Settings::board_access_key();
		$kds_only     = ! current_user_can( 'manage_doughboss' ) && ! current_user_can( 'manage_options' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page gate, checked with the stored verifier.
		$supplied_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$key_is_valid = DoughBoss_Settings::verify_board_access_key( $supplied_key );
		if ( $kds_only && '' !== $required_key && ! $key_is_valid ) {
			wp_die( esc_html__( 'This kitchen link is missing or has an incorrect access key. Ask a manager for the bookmarked kitchen URL.', 'doughboss' ), esc_html__( 'Kitchen link required', 'doughboss' ), array( 'response' => 403 ) );
		}

		// The purchased 23.8-inch FHD station defaults to MAKE. The smaller second
		// display can bookmark ?screen=catering without changing permissions.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presentation preference.
		$screen_mode = isset( $_GET['screen'] ) ? sanitize_key( wp_unslash( $_GET['screen'] ) ) : 'make';
		if ( ! in_array( $screen_mode, array( 'all', 'make', 'pass', 'catering' ), true ) ) {
			$screen_mode = 'make';
		}

		$titles = array(
			'make'     => __( 'MAKE', 'doughboss' ),
			'pass'     => __( 'PASS & PICKUP', 'doughboss' ),
			'catering' => __( 'CATERING', 'doughboss' ),
			'all'      => __( 'LIVE ORDER BOARD', 'doughboss' ),
		);
		$hints = array(
			'make'     => __( 'New orders, prep and oven flow', 'doughboss' ),
			'pass'     => __( 'Ready orders, collection and pre-orders', 'doughboss' ),
			'catering' => __( 'Catering production and hand-off', 'doughboss' ),
			'all'      => __( 'Kitchen operations', 'doughboss' ),
		);
		$config = array(
			'restUrl'    => esc_url_raw( rest_url( DOUGHBOSS_REST_NAMESPACE ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'boardKey'   => $key_is_valid ? $supplied_key : '',
			'currency'   => DoughBoss_Settings::get( 'currency_symbol', '$' ),
			'pollMs'     => 7000,
			'statuses'   => DoughBoss_Order::statuses(),
			'locations'  => $this->board_locations(),
			'screenMode' => $screen_mode,
			'mercure'    => DoughBoss_Mercure::js_config(),
		);

		$this->portal_headers();
		$this->render_head( $titles[ $screen_mode ] . ' — DoughBoss', 'kitchen' );
		?>
		<body class="doughboss-standalone-portal doughboss-staff-kitchen">
			<?php $this->render_portal_bar( 'kitchen', $screen_mode, $key_is_valid ? $supplied_key : '' ); ?>
			<main class="doughboss-board-wrap doughboss-board--screen-<?php echo esc_attr( $screen_mode ); ?>" id="main">
				<div class="db-board-bar">
					<div class="db-board-title-group">
						<span class="db-board-kicker"><?php esc_html_e( 'Dough Boss', 'doughboss' ); ?></span>
						<h1><?php echo esc_html( $titles[ $screen_mode ] ); ?></h1>
						<p><?php echo esc_html( $hints[ $screen_mode ] ); ?></p>
					</div>
					<div class="db-board-actions">
						<span class="db-board-status" role="status" aria-live="polite"></span>
						<button type="button" class="button db-sound-toggle" aria-pressed="false"><?php esc_html_e( 'Enable sound alerts', 'doughboss' ); ?></button>
					</div>
				</div>
				<div class="db-screen-layout">
					<section id="db-preorder-review" class="db-preorder-review" hidden aria-labelledby="db-preorder-review-title"></section>
					<div id="db-board" class="db-board" aria-live="polite">
						<p class="db-board-loading" role="status"><?php esc_html_e( 'Loading orders…', 'doughboss' ); ?></p>
					</div>
				</div>
			</main>
			<script>window.DoughBossBoard = <?php echo wp_json_encode( $config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;</script>
			<script src="<?php echo esc_url( $this->versioned_asset( 'public/js/doughboss-orderboard.js' ) ); ?>"></script>
			<script src="<?php echo esc_url( $this->versioned_asset( 'public/js/doughboss-portals.js' ) ); ?>"></script>
		</body>
		</html>
		<?php
	}

	/**
	 * Render the owner/manager operating overview without wp-admin chrome.
	 *
	 * @return void
	 */
	private function render_management() {
		if ( ! current_user_can( 'manage_doughboss' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view management.', 'doughboss' ), esc_html__( 'Management access required', 'doughboss' ), array( 'response' => 403 ) );
		}

		require_once DOUGHBOSS_PLUGIN_DIR . 'admin/class-doughboss-admin.php';
		$this->portal_headers();
		$this->render_head( __( 'Management — DoughBoss', 'doughboss' ), 'management' );
		?>
		<body class="doughboss-standalone-portal doughboss-management-portal">
			<?php $this->render_portal_bar( 'management', '', '' ); ?>
			<nav class="db-management-nav" aria-label="<?php esc_attr_e( 'Management sections', 'doughboss' ); ?>">
				<a class="is-active" href="<?php echo esc_url( home_url( '/management/' ) ); ?>"><?php esc_html_e( 'Overview', 'doughboss' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=doughboss' ) ); ?>"><?php esc_html_e( 'Orders', 'doughboss' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=doughboss-catering' ) ); ?>"><?php esc_html_e( 'Catering', 'doughboss' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=doughboss-reports' ) ); ?>"><?php esc_html_e( 'Reports', 'doughboss' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=doughboss-settings' ) ); ?>"><?php esc_html_e( 'Settings', 'doughboss' ); ?></a>
			</nav>
			<main class="db-management-main" id="main">
				<?php ( new DoughBoss_Admin() )->render_dashboard_page(); ?>
			</main>
			<script src="<?php echo esc_url( $this->versioned_asset( 'public/js/doughboss-portals.js' ) ); ?>"></script>
		</body>
		</html>
		<?php
	}

	/**
	 * Shared document head.
	 *
	 * @param string $title Document title.
	 * @param string $portal Portal type.
	 * @return void
	 */
	private function render_head( $title, $portal ) {
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
			<meta name="robots" content="noindex,nofollow,noarchive">
			<meta name="theme-color" content="<?php echo 'kitchen' === $portal ? '#0b0907' : '#f6f2eb'; ?>">
			<title><?php echo esc_html( $title ); ?></title>
			<link rel="stylesheet" href="<?php echo esc_url( $this->versioned_asset( 'public/css/doughboss-portals.css' ) ); ?>">
			<?php if ( 'kitchen' === $portal ) : ?>
				<link rel="stylesheet" href="<?php echo esc_url( $this->versioned_asset( 'public/css/doughboss-orderboard.css' ) ); ?>">
			<?php else : ?>
				<link rel="stylesheet" href="<?php echo esc_url( $this->versioned_asset( 'public/css/doughboss-admin.css' ) ); ?>">
			<?php endif; ?>
		</head>
		<?php
	}

	/**
	 * Shared portal navigation.
	 *
	 * @param string $portal    Active portal.
	 * @param string $screen    Active kitchen screen.
	 * @param string $board_key Verified optional kitchen link key.
	 * @return void
	 */
	private function render_portal_bar( $portal, $screen, $board_key ) {
		$user = wp_get_current_user();
		$kitchen_url = static function ( $target_screen ) use ( $board_key ) {
			$args = array( 'screen' => $target_screen );
			if ( '' !== $board_key ) {
				$args['key'] = $board_key;
			}
			return add_query_arg( $args, home_url( '/kitchen/' ) );
		};
		?>
		<header class="db-portal-bar">
			<a class="db-portal-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'Dough Boss home', 'doughboss' ); ?>">DOUGH BOSS<span>.</span></a>
			<?php if ( 'kitchen' === $portal ) : ?>
				<nav class="db-portal-modes" aria-label="<?php esc_attr_e( 'Kitchen screen', 'doughboss' ); ?>">
					<a class="<?php echo 'make' === $screen ? 'is-active' : ''; ?>" href="<?php echo esc_url( $kitchen_url( 'make' ) ); ?>"><?php esc_html_e( 'Make', 'doughboss' ); ?></a>
					<a class="<?php echo 'pass' === $screen ? 'is-active' : ''; ?>" href="<?php echo esc_url( $kitchen_url( 'pass' ) ); ?>"><?php esc_html_e( 'Pass', 'doughboss' ); ?></a>
					<a class="<?php echo 'catering' === $screen ? 'is-active' : ''; ?>" href="<?php echo esc_url( $kitchen_url( 'catering' ) ); ?>"><?php esc_html_e( 'Catering', 'doughboss' ); ?></a>
				</nav>
			<?php endif; ?>
			<div class="db-portal-account">
				<time class="db-portal-clock" data-db-portal-clock aria-label="<?php esc_attr_e( 'Current time', 'doughboss' ); ?>"></time>
				<button type="button" class="db-portal-action" data-db-fullscreen><?php esc_html_e( 'Full screen', 'doughboss' ); ?></button>
				<?php if ( 'kitchen' === $portal && ( current_user_can( 'manage_doughboss' ) || current_user_can( 'manage_options' ) ) ) : ?>
					<a href="<?php echo esc_url( home_url( '/management/' ) ); ?>"><?php esc_html_e( 'Management', 'doughboss' ); ?></a>
				<?php elseif ( 'management' === $portal && current_user_can( 'manage_doughboss_kds' ) ) : ?>
					<a href="<?php echo esc_url( home_url( '/kitchen/' ) ); ?>"><?php esc_html_e( 'Kitchen', 'doughboss' ); ?></a>
				<?php endif; ?>
				<span class="db-portal-user"><?php echo esc_html( $user->display_name ); ?></span>
				<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Sign out', 'doughboss' ); ?></a>
			</div>
		</header>
		<?php
	}

	/**
	 * Compact list of shops within the current staff scope.
	 *
	 * @return array<int,array{id:int,name:string}>
	 */
	private function board_locations() {
		$scope = DoughBoss_Staff_Scope::current_location_id();
		if ( is_wp_error( $scope ) ) {
			return array();
		}
		$locations = array();
		foreach ( DoughBoss_Locations::all( true ) as $location ) {
			if ( $scope && (int) $location->id !== (int) $scope ) {
				continue;
			}
			$locations[] = array(
				'id'   => (int) $location->id,
				'name' => (string) $location->name,
			);
		}
		return $locations;
	}

	/**
	 * Security and caching headers for staff surfaces.
	 *
	 * @return void
	 */
	private function portal_headers() {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
		header( 'X-Frame-Options: DENY', true );
		header( 'Referrer-Policy: same-origin', true );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=()', true );
	}

	/**
	 * Version a plugin asset URL.
	 *
	 * @param string $path Plugin-relative path.
	 * @return string
	 */
	private function versioned_asset( $path ) {
		return add_query_arg( 'ver', DOUGHBOSS_VERSION, DOUGHBOSS_PLUGIN_URL . ltrim( $path, '/' ) );
	}

	/**
	 * Return which portal is being requested by wp-login, if any.
	 *
	 * @return string
	 */
	private function login_portal() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only login presentation.
		$redirect = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
		if ( false !== strpos( $redirect, '/kitchen/' ) ) {
			return 'kitchen';
		}
		if ( false !== strpos( $redirect, '/management/' ) ) {
			return 'management';
		}
		return '';
	}

	/**
	 * Brand the core login form only when a portal sent the user there.
	 *
	 * @return void
	 */
	public function login_branding() {
		$portal = $this->login_portal();
		if ( '' === $portal ) {
			return;
		}
		?>
		<style>
			body.login{background:radial-gradient(circle at 20% 12%,rgba(226,77,40,.22),transparent 34rem),linear-gradient(135deg,#090705,#1b130e);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
			body.login #login{padding-top:6vh;width:min(92vw,420px)}
			body.login h1 a{background:none!important;color:#fff;height:auto;text-indent:0;width:auto;font-size:2.15rem;font-weight:950;letter-spacing:.08em;line-height:1}
			body.login h1 a::after{color:#e54b2d;content:"."}
			body.login form{border:1px solid rgba(255,255,255,.12);border-radius:20px;box-shadow:0 28px 70px rgba(0,0,0,.38);padding:28px}
			body.login .button-primary{background:#e54b2d;border-color:#c63c1f;border-radius:10px;min-height:46px;font-weight:800}
			body.login input[type=text],body.login input[type=password]{border-radius:10px;min-height:48px}
			body.login #nav a,body.login #backtoblog a{color:#f7dfc2}
			.db-login-context{color:#fff1dd;font-size:1rem;font-weight:700;line-height:1.5;text-align:center}
		</style>
		<?php
	}

	/**
	 * Portal-aware login logo URL.
	 *
	 * @param string $url Default URL.
	 * @return string
	 */
	public function login_header_url( $url ) {
		return '' === $this->login_portal() ? $url : home_url( '/' );
	}

	/**
	 * Portal-aware login logo text.
	 *
	 * @param string $text Default text.
	 * @return string
	 */
	public function login_header_text( $text ) {
		return '' === $this->login_portal() ? $text : 'DOUGH BOSS';
	}

	/**
	 * Explain which protected workspace the user is entering.
	 *
	 * @param string $message Existing login message.
	 * @return string
	 */
	public function login_message( $message ) {
		$portal = $this->login_portal();
		if ( '' === $portal ) {
			return $message;
		}
		$copy = 'kitchen' === $portal
			? __( 'Kitchen staff sign-in. You will return directly to the live production board.', 'doughboss' )
			: __( 'Owner and manager sign-in. You will return directly to the operations overview.', 'doughboss' );
		return '<p class="db-login-context" role="status">' . esc_html( $copy ) . '</p>' . $message;
	}
}
