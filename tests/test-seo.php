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
	$json = json_encode( array( '@context' => 'https://schema.org', '@graph' => array( $node ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
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
