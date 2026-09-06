<?php
/**
 * Front-end shortcodes.
 *
 * The menu is server-rendered (search engines and no-JS visitors see real
 * items, prices and schema.org markup); the JavaScript then enhances it. The
 * builder, cart and tracking containers are hydrated by the JavaScript.
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
	 * Cart service (for the badge's initial state).
	 *
	 * @var DoughBoss_Cart
	 */
	private $cart;

	/**
	 * Constructor.
	 *
	 * @param DoughBoss_Cart $cart Cart service.
	 */
	public function __construct( DoughBoss_Cart $cart ) {
		$this->cart = $cart;
	}

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
		add_shortcode( 'doughboss_cart_button', array( $this, 'cart_button' ) );
	}

	/**
	 * Resolve and clamp the heading level attribute (2–4).
	 *
	 * @param array $atts Shortcode attributes.
	 * @return int
	 */
	private function heading_level( $atts ) {
		$level = isset( $atts['heading_level'] ) ? (int) $atts['heading_level'] : 2;
		return max( 2, min( 4, $level ) );
	}

	/**
	 * Common container attributes. Assets are enqueued from the shortcode
	 * itself so page builders, block templates and synced patterns — where
	 * has_shortcode() on post_content finds nothing — still get the CSS/JS.
	 *
	 * @param int $level Heading level.
	 * @return string
	 */
	private function container_attrs( $level ) {
		DoughBoss_Assets::enqueue_now();
		return ' data-heading-level="' . esc_attr( $level ) . '"';
	}

	/**
	 * [doughboss_menu heading_level="2"] — server-rendered menu grid.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function menu( $atts = array() ) {
		$atts  = shortcode_atts( array( 'heading_level' => 2 ), $atts, 'doughboss_menu' );
		$level = $this->heading_level( $atts );
		$items = DoughBoss_REST_Controller::menu_items();
		$open  = DoughBoss_Settings::ordering_open();

		ob_start();
		?>
		<div class="db-app db-menu" data-doughboss-menu data-doughboss-ssr="1"<?php echo $this->container_attrs( $level ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
			<?php if ( empty( $items ) ) : ?>
				<p class="db-empty"><?php esc_html_e( 'No menu items yet.', 'doughboss' ); ?></p>
			<?php else : ?>
				<?php
				$groups = array();
				foreach ( $items as $item ) {
					$groups[ $item['category'] ][] = $item;
				}
				$h1 = 'h' . $level;
				$h2 = 'h' . ( $level + 1 );
				foreach ( $groups as $category => $group ) :
					?>
					<<?php echo $h1; // phpcs:ignore WordPress.Security.EscapeOutput ?> class="db-category"><?php echo esc_html( $category ); ?></<?php echo $h1; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
					<div class="db-grid">
						<?php foreach ( $group as $item ) : ?>
							<?php $available = ! empty( $item['available'] ); ?>
							<?php // Markup mirrors menuCard() in public/js/doughboss.js so the SSR and fetched menus are pixel-identical. ?>
							<article class="db-card<?php echo $available ? '' : ' db-card--unavailable'; ?>" data-item-id="<?php echo esc_attr( $item['id'] ); ?>" data-available="<?php echo $available ? '1' : '0'; ?>">
								<div class="db-card-media">
									<?php if ( ! empty( $item['image'] ) ) : ?>
										<img class="db-card-img" src="<?php echo esc_url( $item['image'] ); ?>"
											<?php if ( ! empty( $item['srcset'] ) ) : ?>srcset="<?php echo esc_attr( $item['srcset'] ); ?>" sizes="(max-width: 560px) 100vw, 320px"<?php endif; ?>
											<?php if ( ! empty( $item['image_width'] ) ) : ?>width="<?php echo esc_attr( $item['image_width'] ); ?>" height="<?php echo esc_attr( $item['image_height'] ); ?>"<?php endif; ?>
											loading="lazy" decoding="async" alt="<?php echo esc_attr( $item['name'] ); ?>" />
									<?php else : ?>
										<div class="db-card-img db-card-img--placeholder" aria-hidden="true"></div>
									<?php endif; ?>
									<?php if ( ! $available ) : ?>
										<span class="db-badge db-badge--soldout"><?php esc_html_e( 'Sold out', 'doughboss' ); ?></span>
									<?php endif; ?>
								</div>
								<div class="db-card-body">
									<<?php echo $h2; // phpcs:ignore WordPress.Security.EscapeOutput ?> class="db-card-title"><?php echo esc_html( $item['name'] ); ?></<?php echo $h2; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
									<?php if ( ! empty( $item['description'] ) ) : ?>
										<p class="db-card-desc"><?php echo esc_html( $item['description'] ); ?></p>
									<?php endif; ?>
									<?php if ( ! $available ) : ?>
										<p class="db-card-note"><?php esc_html_e( 'This item is unavailable right now.', 'doughboss' ); ?></p>
									<?php endif; ?>
									<div class="db-card-foot">
										<span class="db-price"><?php echo esc_html( DoughBoss_Settings::format_price( $item['price'] ) ); ?></span>
										<button type="button" class="db-btn db-add" data-item-id="<?php echo esc_attr( $item['id'] ); ?>"
											<?php disabled( ! $available || ! $open ); ?> <?php echo ( ! $available || ! $open ) ? 'aria-disabled="true"' : ''; ?>>
											<?php esc_html_e( 'Add to cart', 'doughboss' ); ?>
										</button>
									</div>
								</div>
							</article>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
				<?php echo $this->menu_schema( $groups ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * schema.org Menu markup so the menu is machine-readable for search.
	 *
	 * @param array $groups Items grouped by category.
	 * @return string
	 */
	private function menu_schema( array $groups ) {
		$currency = (string) DoughBoss_Settings::get( 'currency_code', 'AUD' );
		$sections = array();
		foreach ( $groups as $category => $items ) {
			$menu_items = array();
			foreach ( $items as $item ) {
				$entry = array(
					'@type'  => 'MenuItem',
					'name'   => $item['name'],
					'offers' => array(
						'@type'         => 'Offer',
						'price'         => number_format( (float) $item['price'], 2, '.', '' ),
						'priceCurrency' => $currency,
						'availability'  => ! empty( $item['available'] ) ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut',
					),
				);
				if ( ! empty( $item['description'] ) ) {
					$entry['description'] = $item['description'];
				}
				if ( ! empty( $item['image'] ) ) {
					$entry['image'] = $item['image'];
				}
				$menu_items[] = $entry;
			}
			$sections[] = array(
				'@type'       => 'MenuSection',
				'name'        => $category,
				'hasMenuItem' => $menu_items,
			);
		}

		$schema = array(
			'@context'       => 'https://schema.org',
			'@type'          => 'Menu',
			'name'           => wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ),
			'hasMenuSection' => $sections,
		);

		return '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
	}

	/**
	 * [doughboss_builder] — renders the custom pizza builder.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function builder( $atts = array() ) {
		$atts  = shortcode_atts( array( 'heading_level' => 2 ), $atts, 'doughboss_builder' );
		$level = $this->heading_level( $atts );
		ob_start();
		?>
		<div class="db-app db-builder" data-doughboss-builder<?php echo $this->container_attrs( $level ); // phpcs:ignore WordPress.Security.EscapeOutput ?> aria-busy="true">
			<div class="db-loading"><?php esc_html_e( 'Loading pizza builder…', 'doughboss' ); ?></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [doughboss_cart] — renders the cart and checkout form.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function cart( $atts = array() ) {
		$atts  = shortcode_atts( array( 'heading_level' => 2 ), $atts, 'doughboss_cart' );
		$level = $this->heading_level( $atts );
		ob_start();
		?>
		<div class="db-app db-cart" data-doughboss-cart<?php echo $this->container_attrs( $level ); // phpcs:ignore WordPress.Security.EscapeOutput ?> aria-busy="true">
			<div class="db-loading"><?php esc_html_e( 'Loading cart…', 'doughboss' ); ?></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [doughboss_cart_button] — a cart badge/link (count + total) for headers
	 * and a sticky mobile bar. Hidden until the cart has something in it.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function cart_button( $atts = array() ) {
		$atts = shortcode_atts( array( 'href' => '' ), $atts, 'doughboss_cart_button' );
		$href = $atts['href'] ? $atts['href'] : '#';
		DoughBoss_Assets::enqueue_now();
		ob_start();
		?>
		<a class="db-app db-cart-badge" data-doughboss-cart-badge href="<?php echo esc_url( $href ); ?>" hidden>
			<span class="db-cart-badge-icon" aria-hidden="true">🛒</span>
			<span class="db-cart-badge-count" aria-label="<?php esc_attr_e( 'Items in cart', 'doughboss' ); ?>"></span>
			<span class="db-cart-badge-total"></span>
			<span class="db-cart-badge-label"><?php esc_html_e( 'View cart', 'doughboss' ); ?></span>
		</a>
		<?php
		return ob_get_clean();
	}

	/**
	 * [doughboss_order_tracking] — renders the order lookup form.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function order_tracking( $atts = array() ) {
		$atts  = shortcode_atts( array( 'heading_level' => 2 ), $atts, 'doughboss_order_tracking' );
		$level = $this->heading_level( $atts );
		$h     = 'h' . $level;
		ob_start();
		?>
		<div class="db-app db-tracking" data-doughboss-tracking<?php echo $this->container_attrs( $level ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
			<?php // Same structure the JS builds when no form is present (renderTracking), so the CSS applies identically. ?>
			<form class="db-track-form">
				<<?php echo $h; // phpcs:ignore WordPress.Security.EscapeOutput ?> class="db-track-heading"><?php esc_html_e( 'Track your order', 'doughboss' ); ?></<?php echo $h; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
				<div class="db-field">
					<label class="db-label" for="db-track-number"><?php esc_html_e( 'Order number', 'doughboss' ); ?></label>
					<input class="db-input" type="text" id="db-track-number" name="number" required autocomplete="off" autocapitalize="characters" enterkeyhint="next" />
				</div>
				<div class="db-field">
					<label class="db-label" for="db-track-email"><?php esc_html_e( 'Email', 'doughboss' ); ?></label>
					<input class="db-input" type="email" id="db-track-email" name="email" required autocomplete="email" inputmode="email" enterkeyhint="go" />
				</div>
				<button type="submit" class="db-btn db-btn--lg"><?php esc_html_e( 'Find my order', 'doughboss' ); ?></button>
			</form>
			<div class="db-track-result" aria-live="polite"></div>
		</div>
		<?php
		return ob_get_clean();
	}
}
