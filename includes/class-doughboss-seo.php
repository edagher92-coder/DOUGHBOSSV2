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
		add_filter( 'robots_txt', array( $this, 'robots_txt' ), 20, 2 );
		add_filter( 'wp_robots', array( $this, 'robots' ), 20 );
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
		if ( is_page( 'catering' ) || has_shortcode( $content, 'doughboss_catering' ) ) {
			return array(
				'title'       => __( 'Dough Boss Catering | Mini Manoush & Pies Sydney', 'doughboss' ),
				'description' => __( 'Plan a fresh Sydney catering spread with mini zaatar, cheese and meat manoush plus spinach, haloumi, chicken and shanklish pies.', 'doughboss' ),
			);
		}
		// /order/ and /menu/ render the same template; give each its own title.
		if ( is_page( 'order' ) ) {
			return array(
				'title'       => DoughBoss_Settings::ordering_open()
					? __( 'Order Online for Pickup | Dough Boss Revesby', 'doughboss' )
					: __( 'Online Ordering | Dough Boss Revesby', 'doughboss' ),
				'description' => DoughBoss_Settings::ordering_open()
					? __( 'Order fresh-baked manoush, pizza, golden pies and wraps online for pickup from Dough Boss Revesby.', 'doughboss' )
					: __( 'Online ordering at Dough Boss is paused. Browse the menu and check your preferred shop before visiting.', 'doughboss' ),
			);
		}
		if ( is_page( 'menu' ) || has_shortcode( $content, 'doughboss_menu' ) || has_shortcode( $content, 'doughboss_builder' ) || has_shortcode( $content, 'doughboss_cart' ) ) {
			return array(
				'title'       => __( 'Dough Boss Menu | Manoush, Pizza & Pies Sydney', 'doughboss' ),
				'description' => DoughBoss_Settings::ordering_open()
					? __( 'Browse fresh-baked manoush, pizza, golden pies and wraps. Order pickup from Dough Boss Revesby.', 'doughboss' )
					: __( 'Browse the Dough Boss menu of manoush, pizza, pies and wraps. Online ordering is paused.', 'doughboss' ),
			);
		}
		if ( is_page( 'locations' ) ) {
			return array(
				'title'       => __( 'Dough Boss Locations | Lebanese Bakery Sydney', 'doughboss' ),
				'description' => __( 'Visit Dough Boss in Revesby, Bankstown or Roselands for oven-baked manoush, pizza, pies and wraps made fresh to order.', 'doughboss' ),
			);
		}
		if ( is_page( 'vouchers' ) ) {
			return array(
				'title'       => __( 'Dough Boss Student Vouchers | Bankstown', 'doughboss' ),
				'description' => __( 'Check current Dough Boss student voucher eligibility, allocation and redemption terms before claiming a personal single-use code.', 'doughboss' ),
			);
		}
		return array(
			'title'       => __( 'Dough Boss | Fresh Manoush, Pies & Catering Sydney', 'doughboss' ),
			'description' => __( 'Fresh-baked manoush, pizza, golden pies, wraps and catering since 2009. Pickup from Revesby.', 'doughboss' ),
		);
	}

	/**
	 * Advertise the native WordPress sitemap when no SEO plugin owns robots.txt.
	 *
	 * @param string $output Existing virtual robots.txt output.
	 * @param bool   $public Whether search indexing is enabled.
	 * @return string
	 */
	public function robots_txt( $output, $public ) {
		if ( ! $public || $this->dedicated_seo_plugin_active() || false !== stripos( $output, 'Sitemap:' ) ) {
			return $output;
		}
		return rtrim( $output ) . "\nSitemap: " . esc_url_raw( home_url( '/wp-sitemap.xml' ) ) . "\n";
	}
	/**
	 * Add preview hints to WordPress's single robots tag without overriding restrictions.
	 *
	 * @param array<string,mixed> $robots Existing WordPress robots directives.
	 * @return array<string,mixed>
	 */
	public function robots( $robots ) {
		if ( ! is_array( $robots ) || $this->dedicated_seo_plugin_active() || ! $this->relevant_page() || ! get_option( 'blog_public', 1 ) ) {
			return $robots;
		}
		foreach ( array( 'noindex', 'nofollow', 'nosnippet', 'noimageindex' ) as $restriction ) {
			if ( ! empty( $robots[ $restriction ] ) ) {
				return $robots;
			}
		}
		return $robots + array( 'max-image-preview' => 'large', 'max-snippet' => '-1', 'max-video-preview' => '-1' );
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
	 * Published shop facts (doughboss.com.au, October 2026), used only where a
	 * stored location field is blank. Keyed by location slug fragment.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function shop_facts() {
		$every_day = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );
		return array(
			'revesby'   => array(
				'street'   => 'Shop 12/25 Selems Parade',
				'locality' => 'Revesby',
				'postcode' => '2212',
				'phone'    => '(02) 9774 2286',
				'hours'    => array_fill_keys( $every_day, '06:30-14:30' ),
			),
			'bankstown' => array(
				'street'   => '462 Chapel Road',
				'locality' => 'Bankstown',
				'postcode' => '2200',
				'phone'    => '(02) 8764 6783',
				'hours'    => array_fill_keys( array( 'mon', 'tue', 'wed', 'thu', 'fri' ), '07:00-14:00' ),
			),
			'roselands' => array(
				'street'   => 'Shop MM03, Roselands Drive (Roselands Centro)',
				'locality' => 'Roselands',
				'postcode' => '2196',
				'phone'    => '0466 353 133',
				'hours'    => array_fill_keys( $every_day, '08:00-15:00' ),
			),
		);
	}

	/**
	 * Pure: convert mon..sun "HH:MM-HH:MM[, ...]" ranges into Schema.org
	 * OpeningHoursSpecification nodes, grouping days that share a range.
	 *
	 * @param array<string,string> $hours mon..sun => ranges.
	 * @return array<int,array<string,mixed>>
	 */
	public static function opening_hours_spec( array $hours ) {
		$days   = array(
			'mon' => 'Monday',
			'tue' => 'Tuesday',
			'wed' => 'Wednesday',
			'thu' => 'Thursday',
			'fri' => 'Friday',
			'sat' => 'Saturday',
			'sun' => 'Sunday',
		);
		$groups = array();
		foreach ( $days as $key => $day ) {
			if ( empty( $hours[ $key ] ) ) {
				continue;
			}
			foreach ( explode( ',', (string) $hours[ $key ] ) as $range ) {
				if ( ! preg_match( '/^\s*([01][0-9]|2[0-3]):([0-5][0-9])-([01][0-9]|2[0-3]):([0-5][0-9])\s*$/', $range, $m ) ) {
					continue;
				}
				$span = $m[1] . ':' . $m[2] . '-' . $m[3] . ':' . $m[4];
				if ( ! isset( $groups[ $span ] ) ) {
					$groups[ $span ] = array();
				}
				$groups[ $span ][] = $day;
			}
		}
		$out = array();
		foreach ( $groups as $span => $group_days ) {
			list( $opens, $closes ) = explode( '-', $span );
			$out[] = array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => count( $group_days ) > 1 ? $group_days : $group_days[0],
				'opens'     => $opens,
				'closes'    => $closes,
			);
		}
		return $out;
	}

	/**
	 * Pure: one schema.org Bakery node for a shop. Stored location fields win;
	 * the published facts only fill blanks. No price range is claimed.
	 *
	 * @param array  $location     Location row as an array (id, name, slug, suburb, address, phone).
	 * @param array  $stored_hours mon..sun => ranges from stored location hours (may be empty).
	 * @param string $home         Home URL.
	 * @param string $url          Public page for the shop (locations page).
	 * @param string $image        Image URL.
	 * @param string $org_id       Organization @id.
	 * @return array<string,mixed>
	 */
	public static function location_node( array $location, array $stored_hours, $home, $url, $image, $org_id ) {
		$clean = static function ( $value ) {
			return trim( preg_replace( '/\s+/', ' ', is_scalar( $value ) ? (string) $value : '' ) );
		};
		$slug  = strtolower( $clean( isset( $location['slug'] ) ? $location['slug'] : '' ) . ' ' . $clean( isset( $location['name'] ) ? $location['name'] : '' ) );
		$facts = array();
		foreach ( self::shop_facts() as $key => $candidate ) {
			if ( false !== strpos( $slug, $key ) ) {
				$facts = $candidate;
				break;
			}
		}
		$name     = $clean( isset( $location['name'] ) ? $location['name'] : '' );
		$name     = '' === $name && ! empty( $facts['locality'] ) ? $facts['locality'] : $name;
		$name     = 0 === stripos( $name, 'dough boss' ) ? $name : trim( 'Dough Boss ' . $name );
		$street   = $clean( isset( $location['address'] ) ? $location['address'] : '' );
		$street   = '' !== $street ? $street : ( isset( $facts['street'] ) ? $facts['street'] : '' );
		$locality = $clean( isset( $location['suburb'] ) ? $location['suburb'] : '' );
		$locality = '' !== $locality ? $locality : ( isset( $facts['locality'] ) ? $facts['locality'] : '' );
		$phone    = $clean( isset( $location['phone'] ) ? $location['phone'] : '' );
		$phone    = '' !== $phone ? $phone : ( isset( $facts['phone'] ) ? $facts['phone'] : '' );
		$postcode = isset( $facts['postcode'] ) ? $facts['postcode'] : ( preg_match( '/\b(2\d{3})\b/', $street, $pm ) ? $pm[1] : '' );

		$address = array(
			'@type'           => 'PostalAddress',
			'streetAddress'   => $street,
			'addressLocality' => $locality,
			'addressRegion'   => 'NSW',
			'addressCountry'  => 'AU',
		);
		if ( '' !== $postcode ) {
			$address['postalCode'] = $postcode;
		}
		$node = array(
			'@type'              => 'Bakery',
			'@id'                => rtrim( (string) $home, '/' ) . '/#location-' . preg_replace( '/[^a-z0-9-]/', '', strtolower( $clean( isset( $location['slug'] ) ? $location['slug'] : '' ) ) ),
			'name'               => $name,
			'url'                => (string) $url,
			'image'              => (string) $image,
			'servesCuisine'      => 'Lebanese',
			'parentOrganization' => array( '@id' => (string) $org_id ),
			'address'            => $address,
			'hasMap'             => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( trim( $name . ' ' . $street . ' ' . $locality . ' NSW ' . $postcode ) ),
		);
		if ( '' !== $phone ) {
			$node['telephone'] = $phone;
		}
		$opening = self::opening_hours_spec( $stored_hours );
		if ( ! $opening && ! empty( $facts['hours'] ) ) {
			$opening = self::opening_hours_spec( $facts['hours'] );
		}
		if ( $opening ) {
			$node['openingHoursSpecification'] = $opening;
		}
		return $node;
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
		// One schema.org Bakery per active shop. Stored location data wins; the
		// published facts in shop_facts() fill only blank fields/hours.
		$locations_url = home_url( '/locations/' );
		foreach ( DoughBoss_Locations::all( true ) as $location ) {
			$graph[] = self::location_node(
				(array) $location,
				DoughBoss_Locations::weekly_hours( (int) $location->id ),
				$home,
				$locations_url,
				$image,
				$organization
			);
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
		$url   = wp_get_canonical_url( get_post() ) ?: get_permalink();
		$image = DOUGHBOSS_PLUGIN_URL . 'public/images/doughboss-social-card.jpg';
		?>
		<meta name="description" content="<?php echo esc_attr( $copy['description'] ); ?>" />
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
