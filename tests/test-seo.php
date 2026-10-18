<?php
/**
 * Local SEO JSON-LD: one schema.org Bakery per shop (pure node builder).
 *
 * @package DoughBoss
 */

require_once dirname( __DIR__ ) . '/includes/class-doughboss-seo.php';

/**
 * Encode exactly as DoughBoss_SEO::head() does, then decode to prove validity.
 *
 * @param array $node Node.
 * @return array
 */
function db_test_seo_roundtrip( array $node ) {
	$json = json_encode( array( '@context' => 'https://schema.org', '@graph' => array( $node ) ), DoughBoss_SEO::SCHEMA_JSON_FLAGS );
	assert_true( is_string( $json ) && '' !== $json, 'node encodes to JSON' );
	$decoded = json_decode( (string) $json, true );
	assert_same( JSON_ERROR_NONE, json_last_error(), 'JSON-LD parses back without error' );
	return $decoded['@graph'][0];
}

db_test(
	'Bakery JSON-LD falls back to the published shop facts when stored fields are blank',
	function () {
		$expect = array(
			'revesby'   => array( 'Dough Boss Revesby', 'Shop 12/25 Selems Parade', 'Revesby', '2212', '(02) 9774 2286', array( array( 7, '06:30', '14:30' ) ) ),
			'bankstown' => array( 'Dough Boss Bankstown', '462 Chapel Road', 'Bankstown', '2200', '(02) 8764 6783', array( array( 5, '07:00', '14:00' ) ) ),
			'roselands' => array( 'Dough Boss Roselands', 'Shop MM03, Roselands Drive (Roselands Centro)', 'Roselands', '2196', '0466 353 133', array( array( 7, '08:00', '15:00' ) ) ),
		);
		foreach ( $expect as $slug => $facts ) {
			$node = db_test_seo_roundtrip(
				DoughBoss_SEO::location_node(
					array( 'id' => 1, 'name' => ucfirst( $slug ), 'slug' => $slug, 'suburb' => '', 'address' => '', 'phone' => '' ),
					array(),
					'https://doughboss.example/',
					'https://doughboss.example/locations/',
					'https://doughboss.example/card.jpg',
					'https://doughboss.example/#organization'
				)
			);
			assert_same( 'Bakery', $node['@type'], $slug . ': Bakery type' );
			assert_same( $facts[0], $node['name'], $slug . ': name' );
			assert_same( 'Lebanese', $node['servesCuisine'], $slug . ': cuisine' );
			assert_same( 'https://doughboss.example/locations/', $node['url'], $slug . ': url' );
			assert_same( $facts[1], $node['address']['streetAddress'], $slug . ': street' );
			assert_same( $facts[2], $node['address']['addressLocality'], $slug . ': locality' );
			assert_same( $facts[3], $node['address']['postalCode'], $slug . ': postcode' );
			assert_same( 'NSW', $node['address']['addressRegion'], $slug . ': region' );
			assert_same( 'AU', $node['address']['addressCountry'], $slug . ': country' );
			assert_same( $facts[4], $node['telephone'], $slug . ': phone' );
			assert_false( array_key_exists( 'priceRange', $node ), $slug . ': no invented price range' );
			assert_same( count( $facts[5] ), count( $node['openingHoursSpecification'] ), $slug . ': one grouped hours spec' );
			$spec = $node['openingHoursSpecification'][0];
			assert_same( 'OpeningHoursSpecification', $spec['@type'], $slug . ': spec type' );
			assert_same( $facts[5][0][0], count( (array) $spec['dayOfWeek'] ), $slug . ': days' );
			assert_same( $facts[5][0][1], $spec['opens'], $slug . ': opens' );
			assert_same( $facts[5][0][2], $spec['closes'], $slug . ': closes' );
		}
		$bankstown = DoughBoss_SEO::location_node( array( 'name' => 'Bankstown', 'slug' => 'bankstown' ), array(), 'https://x.example/', 'https://x.example/locations/', '', '' );
		assert_same( array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday' ), $bankstown['openingHoursSpecification'][0]['dayOfWeek'], 'Bankstown is weekdays only' );
	}
);

db_test(
	'Bakery JSON-LD prefers stored location data and hours',
	function () {
		$node = db_test_seo_roundtrip(
			DoughBoss_SEO::location_node(
				array( 'id' => 3, 'name' => 'Dough Boss Revesby', 'slug' => 'revesby', 'suburb' => 'Revesby', 'address' => "Shop 12/25 Selems Parade\nRevesby NSW 2212", 'phone' => '02 9774 2286' ),
				array( 'mon' => '06:30-14:30', 'tue' => '06:30-14:30', 'sat' => '07:00-12:00, 13:00-14:00', 'sun' => 'garbage' ),
				'https://doughboss.example/',
				'https://doughboss.example/locations/',
				'https://doughboss.example/card.jpg',
				'https://doughboss.example/#organization'
			)
		);
		assert_same( 'Dough Boss Revesby', $node['name'], 'stored name is not double-prefixed' );
		assert_same( 'Shop 12/25 Selems Parade Revesby NSW 2212', $node['address']['streetAddress'], 'stored address wins, whitespace collapsed' );
		assert_same( '02 9774 2286', $node['telephone'], 'stored phone wins' );
		assert_same( 'https://doughboss.example/#location-revesby', $node['@id'], 'stable @id' );
		assert_same( 3, count( $node['openingHoursSpecification'] ), 'stored hours win and split ranges are kept' );
		assert_same( array( 'Monday', 'Tuesday' ), $node['openingHoursSpecification'][0]['dayOfWeek'], 'shared ranges are grouped' );
		assert_same( 'Saturday', $node['openingHoursSpecification'][1]['dayOfWeek'], 'single day stays a string' );
		assert_same( array(), DoughBoss_SEO::opening_hours_spec( array( 'mon' => '25:00-26:00' ) ), 'invalid times are dropped' );

		$unknown = DoughBoss_SEO::location_node( array( 'name' => 'Parramatta', 'slug' => 'parramatta' ), array(), 'https://x.example/', 'https://x.example/locations/', '', '' );
		assert_false( array_key_exists( 'openingHoursSpecification', $unknown ), 'no invented hours for an unknown shop' );
		assert_false( array_key_exists( 'telephone', $unknown ), 'no invented phone for an unknown shop' );
		assert_false( array_key_exists( 'postalCode', $unknown['address'] ), 'no invented postcode' );
	}
);

db_test(
	'theme opts the front page and locations page into the plugin JSON-LD',
	function () {
		$functions = (string) file_get_contents( dirname( __DIR__ ) . '/themes/doughboss-final/functions.php' );
		assert_true( false !== strpos( $functions, "add_filter( 'doughboss_seo_relevant_page', 'doughboss_final_seo_relevant_page', 10, 2 );" ), 'theme filter registered' );
		assert_true( false !== strpos( $functions, 'return is_front_page() || is_page( doughboss_final_seo_covered_pages() );' ), 'front page covered' );
		assert_true( 1 === preg_match( "/function doughboss_final_seo_covered_pages\(\) \{\s*return array\([^)]*'locations'/", $functions ), 'locations page covered' );
		$seo = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-doughboss-seo.php' );
		assert_true( false !== strpos( $seo, '<script type="application/ld+json">' ), 'JSON-LD is emitted in head()' );
	}
);

db_test(
	'catering discovery copy describes corporate office and event enquiries without unconfirmed promises',
	function () {
		$copy = DoughBoss_SEO::discovery_copy( 'catering' );
		assert_true( false !== strpos( $copy['title'], 'Corporate & Event Catering Sydney' ), 'title names the real discovery intent' );
		assert_true( false !== strpos( $copy['title'], 'Revesby' ), 'title identifies the pickup bakery' );
		foreach ( array( 'Office meetings', 'business lunches', 'events', 'mini manoush', 'pies', 'platters', 'Revesby pickup', 'availability and final pricing confirmed' ) as $fact ) {
			assert_true( false !== strpos( $copy['description'], $fact ), 'description includes ' . $fact );
		}
		assert_same( 0, preg_match( '/delivery|same.day|guarantee|free|24\/7|unlimited|allergen.free/i', implode( ' ', $copy ) ), 'no coverage, timing, price or dietary promises are invented' );
		assert_same( null, DoughBoss_SEO::discovery_copy( 'unknown' ), 'unsupported public pages do not acquire guessed copy' );
	}
);

db_test(
	'members discovery copy describes membership status without claiming activation',
	function () {
		$copy = DoughBoss_SEO::discovery_copy( 'members-events' );
		assert_true( false !== strpos( $copy['title'], 'Members & Events' ), 'hub gets a distinct title' );
		foreach ( array( 'order updates', 'catering contacts', 'app shortcuts', 'current Square membership status', 'Revesby' ) as $fact ) {
			assert_true( false !== strpos( $copy['description'], $fact ), 'public hub status includes ' . $fact );
		}
		assert_same( 0, preg_match( '/join now|sign up now|earn points|buy.*gift card/i', implode( ' ', $copy ) ), 'inactive Square features are not advertised as usable' );
	}
);

db_test(
	'canonical catering WebPage and Service reference the existing organization without speculative rich-result claims',
	function () {
		$copy = DoughBoss_SEO::discovery_copy( 'catering' );
		$home = 'https://doughboss.example/bakery/';
		$url = $home . 'catering/';
		$nodes = DoughBoss_SEO::page_nodes( $copy, $url, $home, true );
		assert_same( 2, count( $nodes ), 'one public page and one real catering service' );
		$page = db_test_seo_roundtrip( $nodes[0] );
		$service = db_test_seo_roundtrip( $nodes[1] );
		assert_same( 'WebPage', $page['@type'], 'canonical page context' );
		assert_same( $url, $page['url'], 'canonical URL is retained' );
		assert_same( $url . '#webpage', $page['@id'], 'stable page identity' );
		assert_same( $home . '#website', $page['isPartOf']['@id'], 'page links the existing website' );
		assert_same( $home . '#organization', $page['about']['@id'], 'page links the existing organization' );
		assert_same( $copy['title'], $page['name'], 'visible discovery title is reused' );
		assert_same( $copy['description'], $page['description'], 'public discovery description is reused' );
		assert_same( 'Service', $service['@type'], 'catering is a service, not a scheduled event' );
		assert_same( $service['@id'], $page['mainEntity']['@id'], 'page and service are connected' );
		assert_same( $home . '#organization', $service['provider']['@id'], 'no duplicate organization is invented' );
		assert_same( $url, $service['url'], 'service points to the public catering page' );
		assert_same( 'Corporate, office and event catering', $service['serviceType'], 'service intent follows public catering copy' );
		foreach ( array( 'offers', 'price', 'priceRange', 'aggregateRating', 'review', 'areaServed', 'availableChannel', 'maximumAttendeeCapacity', 'startDate', 'endDate' ) as $unsupported ) {
			assert_false( array_key_exists( $unsupported, $service ), 'no invented ' . $unsupported );
		}
	}
);

db_test(
	'non-catering page graph stays public and uses the supplied canonical rather than request data',
	function () {
		$copy = DoughBoss_SEO::discovery_copy( 'members-events' );
		$nodes = DoughBoss_SEO::page_nodes( $copy, 'https://doughboss.example/members-events/', 'https://doughboss.example/' );
		assert_same( 1, count( $nodes ), 'public membership information is not an active signup service' );
		assert_same( 'WebPage', $nodes[0]['@type'], 'informational page only' );
		assert_false( array_key_exists( 'mainEntity', $nodes[0] ), 'inactive membership service is not inferred' );
		$menu = DoughBoss_SEO::page_nodes( array( 'title' => 'Menu', 'description' => 'Public menu' ), 'https://doughboss.example/order/', 'https://doughboss.example/' );
		assert_same( 'https://doughboss.example/order/', $menu[0]['url'], 'deliberately consolidated menu canonical is preserved' );
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-doughboss-seo.php' );
		assert_same( 0, preg_match( '/\$_(?:GET|POST|COOKIE|REQUEST|SESSION)\b/', $source ), 'metadata does not read customer request, cookie or session state' );
		assert_true( false !== strpos( $source, 'wp_get_canonical_url( get_post() ) ?: get_permalink()' ), 'WordPress remains the canonical owner' );
		assert_same( false, strpos( $source, 'rel="canonical"' ), 'fallback does not emit a second canonical tag' );
	}
);

db_test(
	'JSON-LD cannot close its script element through stored location or metadata text',
	function () {
		$unsafe = '</script><script>alert("synthetic")</script><!-- & \' >';
		$copy = array( 'title' => $unsafe, 'description' => 'Synthetic ' . $unsafe );
		$nodes = DoughBoss_SEO::page_nodes( $copy, 'https://doughboss.example/catering/', 'https://doughboss.example/', true );
		$nodes[] = DoughBoss_SEO::location_node( array( 'name' => $unsafe, 'slug' => 'synthetic', 'address' => $unsafe ), array(), 'https://doughboss.example/', 'https://doughboss.example/locations/', '', 'https://doughboss.example/#organization' );
		$json = json_encode( array( '@context' => 'https://schema.org', '@graph' => $nodes ), DoughBoss_SEO::SCHEMA_JSON_FLAGS );
		assert_true( is_string( $json ), 'script-safe JSON encodes successfully' );
		assert_same( false, stripos( $json, '</script' ), 'no literal closing script can be emitted' );
		assert_same( false, strpos( $json, '<' ), 'HTML tag openers are hex escaped' );
		assert_same( false, strpos( $json, '>' ), 'HTML tag closers are hex escaped' );
		assert_same( false, strpos( $json, '&' ), 'ampersands are hex escaped' );
		$decoded = json_decode( $json, true );
		assert_same( JSON_ERROR_NONE, json_last_error(), 'hardened JSON remains valid' );
		assert_same( $unsafe, $decoded['@graph'][0]['name'], 'encoding preserves the original text value' );
		$wrapped = '<script type="application/ld+json">' . $json . '</script>';
		assert_same( 1, substr_count( $wrapped, '<script' ), 'exactly the intended script starts' );
		assert_same( 1, substr_count( $wrapped, '</script>' ), 'exactly the intended script ends' );
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-doughboss-seo.php' );
		assert_true( false !== strpos( $source, 'wp_json_encode( $this->schema( $copy, $url ), self::SCHEMA_JSON_FLAGS )' ), 'production head uses the tested safe flags' );
	}
);

db_test(
	'discovery metadata uses existing routing and yields to a dedicated SEO owner',
	function () {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-doughboss-seo.php' );
		assert_true( false !== strpos( $source, "return self::discovery_copy( 'catering' );" ), 'catering page uses tested discovery copy' );
		assert_true( false !== strpos( $source, "is_page( 'members-events' )" ), 'public members page has an explicit route' );
		assert_true( false !== strpos( $source, "return self::discovery_copy( 'members-events' );" ), 'members route uses tested inactive-feature copy' );
		foreach ( array( 'WPSEO_VERSION', 'RANK_MATH_VERSION', 'AIOSEO_VERSION', 'SEOPRESS_VERSION' ) as $owner ) {
			assert_true( false !== strpos( $source, "defined( '" . $owner . "' )" ), 'existing ' . $owner . ' ownership preserved' );
		}
		assert_same( 1, preg_match( '/public function head\(\) \{\s*if \( \$this->dedicated_seo_plugin_active\(\) \|\| ! \$this->relevant_page\(\) \) \{\s*return;/', $source ), 'head exits before any description, share or schema output for another owner' );
	}
);
