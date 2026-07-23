<?php
/**
 * Lightweight storefront SEO fallback.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds page metadata and live location JSON-LD when no dedicated SEO plugin is
 * installed. The database remains the source of truth for shop details.
 */
class DoughBoss_SEO {

	/**
	 * Public storefront shortcodes that opt a page into DoughBoss metadata.
	 *
	 * @var string[]
	 */
	private $public_shortcodes = array(
		'doughboss_menu',
		'doughboss_builder',
		'doughboss_cart',
		'doughboss_order_tracking',
		'doughboss_shop_picker',
		'doughboss_catering',
		'doughboss_voucher_claim',
		'doughboss_manoush_hero',
		'doughboss_ordering_status',
	);

	/**
	 * Register front-end hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'document_title_parts', array( $this, 'title_parts' ) );
		add_action( 'wp_head', array( $this, 'head' ), 5 );
	}

	/**
	 * A major SEO plugin should remain the only metadata owner.
	 *
	 * @return bool
	 */
	private function dedicated_seo_plugin_active() {
		return defined( 'WPSEO_VERSION' )
			|| defined( 'RANK_MATH_VERSION' )
			|| defined( 'AIOSEO_VERSION' )
			|| defined( 'SEOPRESS_VERSION' );
	}

	/**
	 * Whether the current singular page renders a public DoughBoss experience.
	 *
	 * @return bool
	 */
	private function relevant_page() {
		if ( is_admin() || ! is_singular() ) {
			return false;
		}
		$post = get_post();
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		foreach ( $this->public_shortcodes as $shortcode ) {
			if ( has_shortcode( $post->post_content, $shortcode ) ) {
				return true;
			}
		}
		return (bool) apply_filters( 'doughboss_seo_relevant_page', false, $post );
	}

	/**
	 * Resolve page-specific copy without guessing from customer data.
	 *
	 * @return array{title:string,description:string}
	 */
	private function page_copy() {
		$post    = get_post();
		$content = $post instanceof WP_Post ? $post->post_content : '';
		if ( has_shortcode( $content, 'doughboss_catering' ) ) {
			return array(
				'title'       => __( 'Dough Boss Catering | Mini Manoush & Pies Sydney', 'doughboss' ),
				'description' => __( 'Plan a fresh Sydney catering spread with mini zaatar, cheese and meat manoush plus spinach, haloumi, chicken and shanklish pies.', 'doughboss' ),
			);
		}
		if ( has_shortcode( $content, 'doughboss_menu' ) || has_shortcode( $content, 'doughboss_builder' ) || has_shortcode( $content, 'doughboss_cart' ) ) {
			return array(
				'title'       => __( 'Dough Boss Menu | Manoush, Pizza & Pies Sydney', 'doughboss' ),
				'description' => __( 'Browse fresh-baked manoush, pizza, golden pies and wraps. Order pickup from Dough Boss Revesby.', 'doughboss' ),
			);
		}
		return array(
			'title'       => __( 'Dough Boss | Fresh Manoush, Pies & Catering Sydney', 'doughboss' ),
			'description' => __( 'Fresh-baked manoush, pizza, golden pies, wraps and catering since 2009. Pickup from Revesby.', 'doughboss' ),
		);
	}

	/**
	 * Improve the native WordPress document title on storefront pages.
	 *
	 * @param array<string,string> $parts Title parts.
	 * @return array<string,string>
	 */
	public function title_parts( $parts ) {
		if ( $this->dedicated_seo_plugin_active() || ! $this->relevant_page() ) {
			return $parts;
		}
		$copy           = $this->page_copy();
		$parts['title'] = $copy['title'];
		unset( $parts['tagline'] );
		return $parts;
	}

	/**
	 * Convert weekly location hours into Schema.org specifications.
	 *
	 * @param int $location_id Location id.
	 * @return array<int,array<string,mixed>>
	 */
	private function opening_hours( $location_id ) {
		$days  = array(
			'mon' => 'Monday',
			'tue' => 'Tuesday',
			'wed' => 'Wednesday',
			'thu' => 'Thursday',
			'fri' => 'Friday',
			'sat' => 'Saturday',
			'sun' => 'Sunday',
		);
		$hours = DoughBoss_Locations::weekly_hours( $location_id );
		$out   = array();
		foreach ( $days as $key => $day ) {
			if ( empty( $hours[ $key ] ) ) {
				continue;
			}
			foreach ( explode( ',', $hours[ $key ] ) as $range ) {
				if ( ! preg_match( '/^\s*([0-2][0-9]:[0-5][0-9])-([0-2][0-9]:[0-5][0-9])\s*$/', $range, $matches ) ) {
					continue;
				}
				$out[] = array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => $day,
					'opens'     => $matches[1],
					'closes'    => $matches[2],
				);
			}
		}
		return $out;
	}

	/**
	 * Build the production-domain schema graph from live shop records.
	 *
	 * @return array<string,mixed>
	 */
	private function schema() {
		$home         = home_url( '/' );
		$organization = trailingslashit( $home ) . '#organization';
		$image        = DOUGHBOSS_PLUGIN_URL . 'public/images/doughboss-social-card.jpg';
		$graph        = array(
			array(
				'@type'        => 'Organization',
				'@id'          => $organization,
				'name'         => get_bloginfo( 'name' ) ?: 'Dough Boss',
				'url'          => $home,
				'foundingDate' => '2009',
				'image'        => $image,
			),
			array(
				'@type'     => 'WebSite',
				'@id'       => trailingslashit( $home ) . '#website',
				'name'      => get_bloginfo( 'name' ) ?: 'Dough Boss',
				'url'       => $home,
				'inLanguage'=> 'en-AU',
				'publisher' => array( '@id' => $organization ),
			),
		);
		foreach ( DoughBoss_Locations::all( true ) as $location ) {
			$node = array(
				'@type'              => array( 'Bakery', 'Restaurant' ),
				'@id'                => trailingslashit( $home ) . '#location-' . sanitize_title( $location->slug ),
				'name'               => $location->name,
				'url'                => $home,
				'telephone'          => $location->phone,
				'image'              => $image,
				'priceRange'         => '$',
				'servesCuisine'      => array( 'Manoush', 'Mediterranean', 'Pizza' ),
				'parentOrganization' => array( '@id' => $organization ),
				'address'            => array(
					'@type'           => 'PostalAddress',
					'streetAddress'   => preg_replace( '/\s+/', ' ', (string) $location->address ),
					'addressLocality' => $location->suburb,
					'addressRegion'   => 'NSW',
					'addressCountry'  => 'AU',
				),
			);
			$opening = $this->opening_hours( (int) $location->id );
			if ( $opening ) {
				$node['openingHoursSpecification'] = $opening;
			}
			$graph[] = $node;
		}
		return array( '@context' => 'https://schema.org', '@graph' => $graph );
	}

	/**
	 * Emit metadata only when DoughBoss is the active metadata owner.
	 *
	 * @return void
	 */
	public function head() {
		if ( $this->dedicated_seo_plugin_active() || ! $this->relevant_page() ) {
			return;
		}
		$copy  = $this->page_copy();
		$url   = get_permalink();
		$image = DOUGHBOSS_PLUGIN_URL . 'public/images/doughboss-social-card.jpg';
		?>
		<meta name="description" content="<?php echo esc_attr( $copy['description'] ); ?>" />
		<meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1" />
		<meta property="og:type" content="website" />
		<meta property="og:locale" content="en_AU" />
		<meta property="og:site_name" content="<?php echo esc_attr( get_bloginfo( 'name' ) ?: 'Dough Boss' ); ?>" />
		<meta property="og:title" content="<?php echo esc_attr( $copy['title'] ); ?>" />
		<meta property="og:description" content="<?php echo esc_attr( $copy['description'] ); ?>" />
		<meta property="og:url" content="<?php echo esc_url( $url ); ?>" />
		<meta property="og:image" content="<?php echo esc_url( $image ); ?>" />
		<meta property="og:image:width" content="1200" />
		<meta property="og:image:height" content="630" />
		<meta property="og:image:alt" content="<?php esc_attr_e( 'Fresh zaatar manoush at Dough Boss', 'doughboss' ); ?>" />
		<meta name="twitter:card" content="summary_large_image" />
		<meta name="twitter:title" content="<?php echo esc_attr( $copy['title'] ); ?>" />
		<meta name="twitter:description" content="<?php echo esc_attr( $copy['description'] ); ?>" />
		<meta name="twitter:image" content="<?php echo esc_url( $image ); ?>" />
		<script type="application/ld+json"><?php echo wp_json_encode( $this->schema(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
		<?php
	}
}
