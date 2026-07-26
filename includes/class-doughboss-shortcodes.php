<?php
/**
 * Front-end shortcodes.
 *
 * Each shortcode renders a lightweight container that the bundled JavaScript
 * hydrates by calling the REST API.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the storefront shortcodes.
 */
class DoughBoss_Shortcodes {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_shortcode( 'doughboss_menu', array( $this, 'menu' ) );
		add_shortcode( 'doughboss_builder', array( $this, 'builder' ) );
		add_shortcode( 'doughboss_cart', array( $this, 'cart' ) );
		add_shortcode( 'doughboss_order_tracking', array( $this, 'order_tracking' ) );
	}

	/**
	 * [doughboss_menu] — renders the menu grid.
	 *
	 * @return string
	 */
	public function menu() {
		ob_start();
		?>
		<div class="db-app db-menu" data-doughboss-menu>
			<div class="db-loading"><?php esc_html_e( 'Loading menu…', 'doughboss' ); ?></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [doughboss_builder] — renders the custom pizza builder.
	 *
	 * @return string
	 */
	public function builder() {
		ob_start();
		?>
		<div class="db-app db-builder" data-doughboss-builder>
			<div class="db-loading"><?php esc_html_e( 'Loading pizza builder…', 'doughboss' ); ?></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [doughboss_cart] — renders the cart and checkout form.
	 *
	 * @return string
	 */
	public function cart() {
		ob_start();
		?>
		<div class="db-app db-cart" data-doughboss-cart>
			<div class="db-loading"><?php esc_html_e( 'Loading cart…', 'doughboss' ); ?></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [doughboss_order_tracking] — renders the order lookup form.
	 *
	 * @return string
	 */
	public function order_tracking() {
		ob_start();
		?>
		<div class="db-app db-tracking" data-doughboss-tracking>
			<form class="db-track-form">
				<h3><?php esc_html_e( 'Track your order', 'doughboss' ); ?></h3>
				<label>
					<?php esc_html_e( 'Order number', 'doughboss' ); ?>
					<input type="text" name="number" required placeholder="DB-000000-XXXX" />
				</label>
				<label>
					<?php esc_html_e( 'Email used on the order', 'doughboss' ); ?>
					<input type="email" name="email" required />
				</label>
				<button type="submit" class="db-btn"><?php esc_html_e( 'Check status', 'doughboss' ); ?></button>
			</form>
			<div class="db-track-result" aria-live="polite"></div>
		</div>
		<?php
		return ob_get_clean();
	}
}
