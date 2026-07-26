<?php
/**
 * Plugin Name:       DoughBoss Migration Gate
 * Description:       Temporarily shields the public site and DoughBoss REST ordering routes during the live migration.
 * Version:           1.0.1
 * Author:            DoughBoss
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DoughBoss_Migration_Gate {
	/**
	 * Register the temporary public and REST gates.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'template_redirect', array( __CLASS__, 'protect_public_site' ), -100 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'protect_doughboss_rest' ), 5, 3 );
	}

	/**
	 * Administrators, staff, and other authenticated test users may use the site.
	 *
	 * @return bool
	 */
	private static function may_bypass() {
		return current_user_can( 'manage_options' )
			|| current_user_can( 'manage_doughboss' )
			|| current_user_can( 'manage_doughboss_kds' );
	}

	/**
	 * Return a reversible, cache-safe 503 migration page to logged-out visitors.
	 *
	 * @return void
	 */
	public static function protect_public_site() {
		if ( self::may_bypass() ) {
			return;
		}

		status_header( 503 );
		nocache_headers();
		header( 'Retry-After: 3600' );
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );

		$login_url = wp_login_url();
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<meta name="robots" content="noindex,nofollow">
			<title><?php esc_html_e( 'DoughBoss — Back soon', 'doughboss-migration-gate' ); ?></title>
			<style>
				:root { color-scheme: dark; }
				* { box-sizing: border-box; }
				body {
					margin: 0;
					min-height: 100vh;
					display: grid;
					place-items: center;
					padding: 24px;
					background:
						radial-gradient(circle at 22% 10%, rgba(220, 49, 40, .2), transparent 36%),
						linear-gradient(145deg, #0d0d0d, #19140f);
					color: #fffaf1;
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
				}
				main { width: min(680px, 100%); text-align: center; }
				.mark {
					display: inline-grid;
					place-items: center;
					width: 76px;
					height: 76px;
					margin-bottom: 24px;
					border-radius: 50%;
					background: #e5392d;
					color: #fff;
					font-size: 36px;
					font-weight: 900;
					letter-spacing: -2px;
					box-shadow: 0 18px 60px rgba(229, 57, 45, .28);
				}
				h1 {
					margin: 0;
					font-size: clamp(42px, 10vw, 82px);
					line-height: .94;
					letter-spacing: -.055em;
				}
				p {
					margin: 22px auto 0;
					max-width: 520px;
					color: #d9cec0;
					font-size: clamp(18px, 3vw, 22px);
					line-height: 1.55;
				}
				.fresh { color: #ff685b; }
				a {
					display: inline-block;
					margin-top: 36px;
					color: #9c9389;
					font-size: 13px;
					text-underline-offset: 4px;
				}
			</style>
		</head>
		<body>
			<main>
				<div class="mark" aria-hidden="true">DB</div>
				<h1><?php esc_html_e( 'Something fresh is baking.', 'doughboss-migration-gate' ); ?></h1>
				<p>
					<?php esc_html_e( 'We are preparing the new DoughBoss experience.', 'doughboss-migration-gate' ); ?>
					<span class="fresh"><?php esc_html_e( 'Back soon.', 'doughboss-migration-gate' ); ?></span>
				</p>
				<a href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Staff access', 'doughboss-migration-gate' ); ?></a>
			</main>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Prevent anonymous access to DoughBoss ordering APIs during migration.
	 *
	 * @param mixed           $result  Response to replace, or null.
	 * @param WP_REST_Server  $server  REST server.
	 * @param WP_REST_Request $request Current request.
	 * @return mixed
	 */
	public static function protect_doughboss_rest( $result, $server, $request ) {
		unset( $server );

		if ( null !== $result || self::may_bypass() ) {
			return $result;
		}

		$route = (string) $request->get_route();
		if ( 0 !== strpos( $route, '/doughboss/v1/' ) && '/doughboss/v1' !== $route ) {
			return $result;
		}

		return new WP_Error(
			'doughboss_migration_in_progress',
			__( 'The new DoughBoss ordering experience is being prepared. Please try again soon.', 'doughboss-migration-gate' ),
			array( 'status' => 503 )
		);
	}
}

DoughBoss_Migration_Gate::boot();
