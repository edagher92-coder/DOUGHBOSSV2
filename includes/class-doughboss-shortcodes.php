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
		add_shortcode( 'doughboss_manoush_hero', array( $this, 'manoush_hero' ) );
		add_shortcode( 'doughboss_ordering_status', array( $this, 'ordering_status' ) );
	}

	/**
	 * [doughboss_ordering_status] — server-rendered launch/availability notice.
	 *
	 * @return string
	 */
	public function ordering_status() {
		if ( DoughBoss_Settings::ordering_open() ) {
			return '';
		}

		return sprintf(
			'<aside class="db-app db-ordering-status" role="status"><strong>%1$s</strong><p>%2$s</p></aside>',
			esc_html__( 'Online ordering coming soon', 'doughboss' ),
			esc_html( DoughBoss_Settings::ordering_closed_message() )
		);
	}

	/**
	 * [doughboss_manoush_hero] â€” a self-contained decorative hero for classic,
	 * block and template-rendered pages. Optimised local defaults make the
	 * shortcode production-presentable; every image can still be replaced with
	 * a Media Library URL through shortcode attributes.
	 *
	 * @param array<string, string> $atts Shortcode attributes.
	 * @return string
	 */
	public function manoush_hero( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'kicker'        => __( 'Made fresh, every morning', 'doughboss' ),
				'title'         => __( 'The manoush comes together here.', 'doughboss' ),
				'description'   => __( 'Warm bread, generous toppings and the little details that make a bakery visit feel like home.', 'doughboss' ),
				'background_image' => DOUGHBOSS_PLUGIN_URL . 'public/images/doughboss-catering-premium-v1.webp',
				'central_image' => DOUGHBOSS_PLUGIN_URL . 'public/images/catering-fresh-v1.webp',
				'zaatar_image'  => DOUGHBOSS_PLUGIN_URL . 'public/images/catering-zaatar-v1.webp',
				'cheese_image'  => DOUGHBOSS_PLUGIN_URL . 'public/images/catering-cheese-v1.webp',
				'meat_image'    => '',
				'spinach_image' => '',
			),
			$atts,
			'doughboss_manoush_hero'
		);

		$ingredients = array_filter(
			array(
			'zaatar'  => array( 'label' => __( 'Zaatar', 'doughboss' ), 'url' => $atts['zaatar_image'] ),
			'cheese'  => array( 'label' => __( 'Cheese', 'doughboss' ), 'url' => $atts['cheese_image'] ),
			'meat'    => array( 'label' => __( 'Meat', 'doughboss' ), 'url' => $atts['meat_image'] ),
			'spinach' => array( 'label' => __( 'Spinach', 'doughboss' ), 'url' => $atts['spinach_image'] ),
			),
			static function ( $ingredient ) {
				return '' !== $ingredient['url'];
			}
		);

		ob_start();
		?>
		<section class="db-manoush-hero is-assembled" data-db-manoush-hero data-db-scroll-scene>
			<div class="db-mh-backdrop" style="background-image:url('<?php echo esc_url( $atts['background_image'] ); ?>')" aria-hidden="true"></div>
			<div class="db-mh-copy">
				<p class="db-mh-kicker"><?php echo esc_html( $atts['kicker'] ); ?></p>
				<h2><?php echo esc_html( $atts['title'] ); ?></h2>
				<p><?php echo esc_html( $atts['description'] ); ?></p>
				<button class="db-mh-replay" type="button" data-db-manoush-replay><?php esc_html_e( 'Explode the bites', 'doughboss' ); ?></button>
			</div>
			<div class="db-mh-stage" aria-hidden="true">
				<div class="db-mh-world">
					<div class="db-mh-central">
						<?php if ( '' !== $atts['central_image'] ) : ?>
							<img src="<?php echo esc_url( $atts['central_image'] ); ?>" alt="" width="258" height="258" loading="eager" decoding="async" fetchpriority="high" />
						<?php else : ?>
							<span><?php esc_html_e( 'Manoush', 'doughboss' ); ?></span>
						<?php endif; ?>
					</div>
					<?php foreach ( $ingredients as $name => $ingredient ) : ?>
						<div class="db-mh-ingredient db-mh-ingredient--<?php echo esc_attr( $name ); ?>">
							<?php if ( '' !== $ingredient['url'] ) : ?>
								<img src="<?php echo esc_url( $ingredient['url'] ); ?>" alt="" width="118" height="118" loading="eager" decoding="async" />
							<?php else : ?>
								<span><?php echo esc_html( $ingredient['label'] ); ?></span>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
		<?php
		return ob_get_clean();
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
				<p class="db-vc-sub"><?php esc_html_e( 'Pick an offer and enter your mobile to get a single-use code.', 'doughboss' ); ?></p>
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
