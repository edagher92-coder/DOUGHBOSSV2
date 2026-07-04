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
		add_shortcode( 'doughboss_shop_picker', array( $this, 'shop_picker' ) );
		add_shortcode( 'doughboss_catering', array( $this, 'catering' ) );
		add_shortcode( 'doughboss_voucher_claim', array( $this, 'voucher_claim' ) );
	}

	/**
	 * [doughboss_voucher_claim] — lets a customer claim a single-use voucher from
	 * an active daily campaign (e.g. the $5 student voucher). The offers
	 * are rendered server-side; doughboss-voucher.js posts the claim to
	 * /voucher/claim and shows the resulting code.
	 *
	 * @return string
	 */
	public function voucher_claim() {
		$campaigns = array();
		if ( class_exists( 'DoughBoss_Voucher' ) ) {
			foreach ( DoughBoss_Voucher::campaigns() as $c ) {
				if ( ! empty( $c['active'] ) ) {
					$campaigns[] = $c;
				}
			}
		}
		ob_start();
		?>
		<div class="db-app db-voucher-claim" data-doughboss-voucher-claim>
			<div class="db-vc-card">
				<h3 class="db-vc-title"><?php esc_html_e( 'Claim your student voucher', 'doughboss' ); ?></h3>
				<p class="db-vc-sub"><?php esc_html_e( 'Pick an offer and enter your mobile to get a single-use code — Dough Boss × Snow Boss.', 'doughboss' ); ?></p>
				<?php if ( empty( $campaigns ) ) : ?>
					<p class="db-vc-none"><?php esc_html_e( 'No vouchers are available right now.', 'doughboss' ); ?></p>
				<?php else : ?>
					<div class="db-vc-offers">
						<?php foreach ( $campaigns as $c ) : ?>
							<button type="button" class="db-vc-offer" data-campaign="<?php echo esc_attr( $c['slug'] ); ?>">
								<span class="db-vc-val"><?php echo esc_html( 'percent' === $c['type'] ? $c['value'] . '%' : DoughBoss_Settings::format_price( $c['value'] ) ); ?></span>
								<span class="db-vc-label"><?php echo esc_html( $c['label'] ); ?></span>
							</button>
						<?php endforeach; ?>
					</div>
					<form class="db-vc-form" hidden>
						<input type="tel" name="phone" inputmode="tel" autocomplete="tel" placeholder="<?php esc_attr_e( 'Mobile number', 'doughboss' ); ?>" required />
						<input type="email" name="email" autocomplete="email" placeholder="<?php esc_attr_e( 'Email (optional)', 'doughboss' ); ?>" />
						<button type="submit" class="db-btn db-vc-submit"><?php esc_html_e( 'Get my code', 'doughboss' ); ?></button>
					</form>
				<?php endif; ?>
				<div class="db-vc-result" aria-live="polite"></div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [doughboss_catering] — renders the catering packages, quote builder and
	 * enquiry form. Hydrated by doughboss-catering.js against the
	 * /catering/* REST routes.
	 *
	 * @return string
	 */
	public function catering() {
		ob_start();
		?>
		<div class="db-app db-catering" data-doughboss-catering>
			<div class="db-loading"><?php esc_html_e( 'Loading catering…', 'doughboss' ); ?></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [doughboss_shop_picker] — lets the customer choose which shop they're
	 * ordering from. The choice is remembered and used to route the order to
	 * that shop's kitchen board. Renders nothing for single-shop sites.
	 *
	 * @return string
	 */
	public function shop_picker() {
		ob_start();
		?>
		<div class="db-app db-shop-picker" data-doughboss-shop>
			<div class="db-loading"><?php esc_html_e( 'Loading shops…', 'doughboss' ); ?></div>
		</div>
		<?php
		return ob_get_clean();
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
