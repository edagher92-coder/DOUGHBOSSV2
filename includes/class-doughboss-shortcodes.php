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
				'variant'       => 'bites',
				'kicker'        => __( 'Catering, made fresh', 'doughboss' ),
				'title'         => __( 'The menu comes together here.', 'doughboss' ),
				'description'   => __( 'Mini zaatar, cheese and meat manoush with spinach, haloumi, chicken and shanklish pies.', 'doughboss' ),
				'replay_label'  => __( 'See the spread come together', 'doughboss' ),
				'background_image' => DOUGHBOSS_PLUGIN_URL . 'public/images/doughboss-catering-premium-v1.webp',
				// A single authentic manoush sits at the centre. The former full platter
				// looked like a floating plate when the surrounding layers separated.
				'central_image' => DOUGHBOSS_PLUGIN_URL . 'public/images/catering-fresh-cutout-v2.webp',
				'zaatar_image'  => DOUGHBOSS_PLUGIN_URL . 'public/images/catering-zaatar-cutout-v2.webp',
				'cheese_image'  => DOUGHBOSS_PLUGIN_URL . 'public/images/catering-cheese-cutout-v2.webp',
				'meat_image'    => DOUGHBOSS_PLUGIN_URL . 'public/images/catering-pies-v3.webp',
				'spinach_image' => DOUGHBOSS_PLUGIN_URL . 'public/images/catering-fresh-cutout-v2.webp',
			),
			$atts,
			'doughboss_manoush_hero'
		);
		$variant = in_array( $atts['variant'], array( 'manoush', 'bites' ), true ) ? $atts['variant'] : 'bites';

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
		<section class="db-manoush-hero db-manoush-hero--<?php echo esc_attr( $variant ); ?> is-assembled" data-db-manoush-hero data-db-manoush-variant="<?php echo esc_attr( $variant ); ?>" data-db-scroll-scene>
			<div class="db-mh-backdrop" style="background-image:url('<?php echo esc_url( $atts['background_image'] ); ?>')" aria-hidden="true"></div>
			<div class="db-mh-copy">
				<p class="db-mh-kicker"><?php echo esc_html( $atts['kicker'] ); ?></p>
				<h2><?php echo esc_html( $atts['title'] ); ?></h2>
				<p><?php echo esc_html( $atts['description'] ); ?></p>
				<button class="db-mh-replay" type="button" data-db-manoush-replay><?php echo esc_html( $atts['replay_label'] ); ?></button>
				<span class="db-mh-motion-note" role="status"><?php esc_html_e( 'Animation paused by your device motion setting.', 'doughboss' ); ?></span>
			</div>
			<div class="db-mh-stage" aria-hidden="true">
				<div class="db-mh-world">
					<div class="db-mh-central">
						<?php if ( '' !== $atts['central_image'] ) : ?>
							<img src="<?php echo esc_url( $atts['central_image'] ); ?>" alt="" width="900" height="716" loading="eager" decoding="async" fetchpriority="high" />
						<?php else : ?>
							<span><?php esc_html_e( 'Manoush', 'doughboss' ); ?></span>
						<?php endif; ?>
					</div>
					<?php foreach ( $ingredients as $name => $ingredient ) : ?>
						<div class="db-mh-ingredient db-mh-ingredient--<?php echo esc_attr( $name ); ?>">
							<?php if ( '' !== $ingredient['url'] ) : ?>
								<img src="<?php echo esc_url( $ingredient['url'] ); ?>" alt="" width="240" height="180" loading="lazy" decoding="async" />
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
				<p class="db-vc-sub"><?php esc_html_e( 'Choose the student offer, then confirm your mobile and education email. Your email must end in .edu or .edu.au.', 'doughboss' ); ?></p>
				<?php if ( empty( $campaigns ) ) : ?>
					<p class="db-vc-none"><?php esc_html_e( 'No vouchers are available right now.', 'doughboss' ); ?></p>
				<?php else : ?>
					<div class="db-vc-offers">
						<?php foreach ( $campaigns as $c ) : ?>
							<button type="button" class="db-vc-offer" data-campaign="<?php echo esc_attr( $c['slug'] ); ?>" aria-pressed="false">
								<span class="db-vc-val"><?php echo esc_html( 'percent' === $c['type'] ? $c['value'] . '%' : DoughBoss_Settings::format_price( $c['value'] ) ); ?></span>
								<span class="db-vc-label"><?php echo esc_html( $c['label'] ); ?></span>
							</button>
						<?php endforeach; ?>
					</div>
					<form class="db-vc-form" hidden>
						<label class="db-vc-field">
							<span><?php esc_html_e( 'Mobile number', 'doughboss' ); ?></span>
							<input type="tel" name="phone" inputmode="tel" autocomplete="tel" aria-label="<?php esc_attr_e( 'Mobile number', 'doughboss' ); ?>" placeholder="<?php esc_attr_e( '04xx xxx xxx', 'doughboss' ); ?>" required />
						</label>
						<label class="db-vc-field">
							<span><?php esc_html_e( 'Student email', 'doughboss' ); ?></span>
							<input type="email" name="email" autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" placeholder="<?php esc_attr_e( 'you@student.university.edu.au', 'doughboss' ); ?>" required />
						</label>
						<label class="db-vc-field">
							<span><?php esc_html_e( 'Re-enter student email', 'doughboss' ); ?></span>
							<input type="email" name="email_confirmation" autocomplete="off" inputmode="email" autocapitalize="none" spellcheck="false" placeholder="<?php esc_attr_e( 'Type the same email again', 'doughboss' ); ?>" required />
						</label>
						<p class="db-vc-eligibility"><?php esc_html_e( 'One $5 voucher per eligible student email each day, while the daily allocation lasts. Your code is single use.', 'doughboss' ); ?></p>
						<button type="submit" class="db-btn db-vc-submit"><?php esc_html_e( 'Get my code', 'doughboss' ); ?></button>
					</form>
				<?php endif; ?>
				<div class="db-vc-result" role="status" aria-live="polite" aria-atomic="true" tabindex="-1"></div>
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
		$email         = DoughBoss_Settings::catering_email();
		$phone         = DoughBoss_Settings::catering_phone();
		$phone_digits  = preg_replace( '/[^0-9]/', '', $phone );
		$phone_display = is_string( $phone_digits ) && 10 === strlen( $phone_digits )
			? substr( $phone_digits, 0, 4 ) . ' ' . substr( $phone_digits, 4, 3 ) . ' ' . substr( $phone_digits, 7, 3 )
			: $phone;
		$phone_tel     = is_string( $phone_digits ) && 0 === strpos( $phone_digits, '0' )
			? '+61' . substr( $phone_digits, 1 )
			: '+' . ltrim( (string) $phone_digits, '+' );
		$email_subject = rawurlencode( 'Dough Boss catering enquiry' );
		$email_body    = rawurlencode( "Event date:\nGuest count:\nPreferred pickup time:\nDietary notes:\n" );
		ob_start();
		?>
		<div class="db-app db-catering">
			<section class="dbc-contact" aria-labelledby="dbc-contact-title">
				<p class="dbc-kicker"><?php esc_html_e( 'Catering enquiries', 'doughboss' ); ?></p>
				<h2 class="dbc-h2" id="dbc-contact-title"><?php esc_html_e( 'Tell us what you need', 'doughboss' ); ?></h2>
				<p class="dbc-sub"><?php esc_html_e( 'Share your date, preferred pickup time, guest count and dietary notes. Our catering team will confirm availability, the menu and final price before anything is locked in.', 'doughboss' ); ?></p>
				<div class="dbc-contact-actions">
					<a class="dbc-contact-card" href="<?php echo esc_attr( 'mailto:' . $email . '?subject=' . $email_subject . '&body=' . $email_body ); ?>">
						<strong><?php esc_html_e( 'Email catering', 'doughboss' ); ?></strong>
						<span><?php echo esc_html( $email ); ?></span>
					</a>
					<a class="dbc-contact-card" href="<?php echo esc_attr( 'tel:' . $phone_tel ); ?>">
						<strong><?php esc_html_e( 'Call catering', 'doughboss' ); ?></strong>
						<span><?php echo esc_html( $phone_display ); ?></span>
					</a>
				</div>
				<p class="dbc-coming-soon" role="status"><strong><?php esc_html_e( 'Catering online ordering is coming soon — stay tuned!', 'doughboss' ); ?></strong></p>
			</section>
			<section class="dbc-how" aria-labelledby="dbc-how-title">
				<p class="dbc-kicker"><?php esc_html_e( 'How it works', 'doughboss' ); ?></p>
				<h2 class="dbc-h2" id="dbc-how-title"><?php esc_html_e( 'A fresh spread in three steps', 'doughboss' ); ?></h2>
				<ol class="dbc-how-grid">
					<li><span>01</span><strong><?php esc_html_e( 'Tell us the occasion', 'doughboss' ); ?></strong><p><?php esc_html_e( 'Send the event date, guest count, preferred pickup time and dietary notes.', 'doughboss' ); ?></p></li>
					<li><span>02</span><strong><?php esc_html_e( 'Build the right mix', 'doughboss' ); ?></strong><p><?php esc_html_e( 'We will help balance minis, pies and crowd favourites around your needs.', 'doughboss' ); ?></p></li>
					<li><span>03</span><strong><?php esc_html_e( 'Confirm before we bake', 'doughboss' ); ?></strong><p><?php esc_html_e( 'We confirm availability, final price, collection details and payment first.', 'doughboss' ); ?></p></li>
				</ol>
			</section>
			<section class="dbc-faq" aria-labelledby="dbc-faq-title">
				<p class="dbc-kicker"><?php esc_html_e( 'Catering Q&A', 'doughboss' ); ?></p>
				<h2 class="dbc-h2" id="dbc-faq-title"><?php esc_html_e( 'Good to know before you order', 'doughboss' ); ?></h2>
				<details><summary><?php esc_html_e( 'How much notice should I give?', 'doughboss' ); ?></summary><p><?php esc_html_e( 'As early as you can. Lead time depends on the date, quantity and menu mix, and is confirmed before acceptance.', 'doughboss' ); ?></p></details>
				<details><summary><?php esc_html_e( 'Where is catering prepared?', 'doughboss' ); ?></summary><p><?php esc_html_e( 'Catering is prepared through our Revesby bakery for now. Ask the team what collection or other arrangement is available.', 'doughboss' ); ?></p></details>
				<details><summary><?php esc_html_e( 'Can you help with dietary requirements?', 'doughboss' ); ?></summary><p><?php esc_html_e( 'Tell us about dietary needs and allergies. We can explain suitable choices, but our kitchen handles common allergens and cannot promise an allergen-free environment.', 'doughboss' ); ?></p></details>
				<details><summary><?php esc_html_e( 'Is there a minimum order?', 'doughboss' ); ?></summary><p><?php esc_html_e( 'The team will recommend practical quantities for your guest count and explain any package requirement before you confirm.', 'doughboss' ); ?></p></details>
				<details><summary><?php esc_html_e( 'How do catering payments work?', 'doughboss' ); ?></summary><p><?php esc_html_e( 'The team confirms the price and payment arrangement. Online deposits remain unavailable until the payment gateway completes acceptance.', 'doughboss' ); ?></p></details>
				<details><summary><?php esc_html_e( 'Can I change or cancel my order?', 'doughboss' ); ?></summary><p><?php esc_html_e( 'Contact the catering team as soon as possible. Changes depend on whether ingredients have already been prepared.', 'doughboss' ); ?></p></details>
			</section>
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
			<div class="db-loading" role="status" aria-live="polite"><?php esc_html_e( 'Loading shops…', 'doughboss' ); ?></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [doughboss_menu] — renders the menu grid.
	 *
	 * @return string
	 */
	public function menu( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				// Leave this blank when the cart lives on the same page. Set a
				// site-local URL (for example /cart/) for a dedicated cart page.
				// It is display/navigation only: cart totals remain server-owned.
				'cart_url' => '',
			),
			$atts,
			'doughboss_menu'
		);
		ob_start();
		?>
		<div class="db-app db-menu" data-doughboss-menu data-cart-url="<?php echo esc_url( $atts['cart_url'] ); ?>">
			<div class="db-loading" role="status" aria-live="polite"><?php esc_html_e( 'Loading menu…', 'doughboss' ); ?></div>
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
			<div class="db-loading" role="status" aria-live="polite"><?php esc_html_e( 'Loading pizza builder…', 'doughboss' ); ?></div>
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
			<div class="db-loading" role="status" aria-live="polite"><?php esc_html_e( 'Loading cart…', 'doughboss' ); ?></div>
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
		<div id="track-order" class="db-app db-tracking" data-doughboss-tracking>
			<form class="db-track-form">
				<p class="db-order-kicker"><?php esc_html_e( 'Live order updates', 'doughboss' ); ?></p>
				<h3><?php esc_html_e( 'Track your order', 'doughboss' ); ?></h3>
				<p class="db-track-intro"><?php esc_html_e( 'Enter the order number from your confirmation and the same email used at checkout.', 'doughboss' ); ?></p>
				<label>
					<?php esc_html_e( 'Order number', 'doughboss' ); ?>
					<input type="text" name="number" required maxlength="64" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="DB-000000-XXXX" />
				</label>
				<label>
					<?php esc_html_e( 'Email used on the order', 'doughboss' ); ?>
					<input type="email" name="email" required autocomplete="email" autocapitalize="none" spellcheck="false" />
				</label>
				<button type="submit" class="db-btn db-btn--lg"><?php esc_html_e( 'Check live status', 'doughboss' ); ?></button>
			</form>
			<div class="db-track-result" aria-live="polite"></div>
		</div>
		<?php
		return ob_get_clean();
	}
}
