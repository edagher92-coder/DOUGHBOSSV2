<?php
/**
 * WP-06 tests: the JSON-LD graph of the landing pages.
 *
 * The pure builder is tested with hand-made contexts (every property needs a source, forbidden properties are
 * refused, the hostile-string and failure paths), then the real page end to end: the graph printed in wp_head for a
 * shop page and a catering page, its ids and urls, and the exact Offer prices from core's package meta.
 *
 * @package DoughBoss_Growth
 */

// The module files define classes only, so loading them has no side effects.
DoughBoss_Growth::load_module( 'ledger' );
DoughBoss_Growth::load_module( 'landing' );

/**
 * A full catering context (service, offers, areas, FAQ).
 *
 * @return array
 */
function dbgr_schema_ctx_catering() {
	return array(
		'home'     => 'https://doughboss.test/',
		'url'      => 'https://doughboss.test/catering/corporate/',
		'crumbs'   => array(
			array( 'Home', 'https://doughboss.test/' ),
			array( 'Catering', 'https://doughboss.test/catering/' ),
			array( 'Corporate catering', 'https://doughboss.test/catering/corporate/' ),
		),
		'kind'     => 'catering',
		'service'  => array( 'name' => 'Corporate catering' ),
		'packages' => array(
			array( 'name' => 'Box A', 'price' => 45.0 ),
			array( 'name' => 'Box B', 'price' => 123.5 ),
		),
		'areas'    => array( 'Place One', 'Place Two' ),
		'faqs'     => array( array( 'Is this a question?', 'This is the answer.' ) ),
	);
}

/**
 * A full shop context.
 *
 * @return array
 */
function dbgr_schema_ctx_location() {
	return array(
		'home'     => 'https://doughboss.test/',
		'url'      => 'https://doughboss.test/locations/revesby/',
		'crumbs'   => array(
			array( 'Home', 'https://doughboss.test/' ),
			array( 'Locations', 'https://doughboss.test/locations/' ),
			array( 'Revesby', 'https://doughboss.test/locations/revesby/' ),
		),
		'kind'     => 'location',
		'location' => array(
			'slug'    => 'revesby',
			'name'    => 'Revesby',
			'address' => "Test Unit 1\nRevesby Test",
			'suburb'  => 'Revesby',
			'phone'   => '(02) 5550 0101',
			'hours'   => array(
				'mon' => array( array( '06:30', '14:30' ) ),
				'sat' => array( array( '07:00', '12:00' ), array( '13:00', '15:00' ) ),
			),
		),
		'faqs'     => array(),
	);
}

/**
 * Every property name in a decoded document, "Type.property".
 *
 * @param mixed  $node   Node.
 * @param array  $out    Collected (by reference).
 * @return void
 */
function dbgr_schema_collect( $node, array &$out ) {
	if ( ! is_array( $node ) ) {
		return;
	}
	$is_list = ( array() !== $node && array_keys( $node ) === range( 0, count( $node ) - 1 ) );
	if ( $is_list ) {
		foreach ( $node as $child ) {
			dbgr_schema_collect( $child, $out );
		}
		return;
	}
	if ( isset( $node['@type'] ) ) {
		$type = is_array( $node['@type'] ) ? 'Bakery' : $node['@type'];
		foreach ( array_keys( $node ) as $property ) {
			$out[ $type . '.' . $property ] = true;
		}
	}
	foreach ( $node as $value ) {
		dbgr_schema_collect( $value, $out );
	}
}

db_test(
	'schema: a full catering graph validates and every property it carries has a source entry',
	function () {
		$doc = DoughBoss_Growth_Landing_Schema::build( dbgr_schema_ctx_catering() );
		assert_true( is_array( $doc ), 'the graph is built' );
		assert_same( 'https://schema.org', $doc['@context'], '@context' );
		assert_same( array(), DoughBoss_Growth_Landing_Schema::validate( $doc ), 'no violations' );
		$props = array();
		dbgr_schema_collect( $doc['@graph'], $props );
		$sources = DoughBoss_Growth_Landing_Schema::sources();
		foreach ( array_keys( $props ) as $pair ) {
			list( $type, $property ) = explode( '.', $pair, 2 );
			assert_true( isset( $sources[ $type ][ $property ] ), $pair . ' has a source entry' );
			assert_true( '' !== trim( (string) $sources[ $type ][ $property ] ), $pair . ' source text is not empty' );
		}
		$types = array_map(
			function ( $n ) {
				return $n['@type'];
			},
			$doc['@graph']
		);
		assert_same( array( 'BreadcrumbList', 'Service', 'FAQPage' ), $types, 'breadcrumb, service and FAQ nodes only' );
	}
);

db_test(
	'schema: a full shop graph validates; the node reuses core id, page url, address, phone and hours exactly',
	function () {
		$doc = DoughBoss_Growth_Landing_Schema::build( dbgr_schema_ctx_location() );
		assert_true( is_array( $doc ), 'the graph is built' );
		assert_same( array(), DoughBoss_Growth_Landing_Schema::validate( $doc ), 'no violations' );
		$shop = $doc['@graph'][1];
		assert_same( array( 'Bakery', 'Restaurant' ), $shop['@type'], 'type pair' );
		assert_same( 'https://doughboss.test/#location-revesby', $shop['@id'], '@id equals core: {home}/#location-{slug}' );
		assert_same( 'https://doughboss.test/locations/revesby/', $shop['url'], 'url is the landing page permalink, not the home page' );
		assert_same( 'Revesby', $shop['name'], 'name is core name exactly' );
		assert_same( '(02) 5550 0101', $shop['telephone'], 'telephone as core stores it' );
		assert_same( 'Test Unit 1', $shop['address']['streetAddress'], 'street address is the first address line' );
		assert_same( 'Revesby', $shop['address']['addressLocality'], 'locality is the suburb' );
		assert_same( array( '@id' => 'https://doughboss.test/#organization' ), $shop['parentOrganization'], 'organisation is a reference only' );
		$spec = $shop['openingHoursSpecification'];
		assert_same( 3, count( $spec ), 'one specification per range' );
		assert_same( array( 'Monday', 'Saturday', 'Saturday' ), array_map( function ( $s ) {
			return $s['dayOfWeek'];
		}, $spec ), 'Monday to Sunday order' );
		assert_same( array( '07:00', '12:00' ), array( $spec[1]['opens'], $spec[1]['closes'] ), 'first Saturday range' );
		assert_same( array( '13:00', '15:00' ), array( $spec[2]['opens'], $spec[2]['closes'] ), 'second Saturday range' );
		foreach ( array( 'geo', 'sameAs', 'aggregateRating', 'review', 'priceRange', 'hasMenu', 'servesCuisine', 'hasMap', 'image', 'logo', 'description' ) as $forbidden ) {
			assert_false( array_key_exists( $forbidden, $shop ), 'no ' . $forbidden . ' without a confirmed claim' );
		}
		$json = DoughBoss_Growth_Landing_Schema::encode( $doc );
		foreach ( array( '"geo"', 'sameAs', 'ratingValue', 'priceRange', 'servesCuisine' ) as $needle ) {
			assert_not_contains( $needle, $json, 'encoded graph has no ' . $needle );
		}
	}
);

db_test(
	'schema: a shop with no phone and no hours carries neither property (nothing is invented)',
	function () {
		$ctx                        = dbgr_schema_ctx_location();
		$ctx['location']['phone']   = '';
		$ctx['location']['hours']   = array();
		$ctx['location']['suburb']  = '';
		$doc                        = DoughBoss_Growth_Landing_Schema::build( $ctx );
		$shop                       = $doc['@graph'][1];
		assert_false( array_key_exists( 'telephone', $shop ), 'no telephone' );
		assert_false( array_key_exists( 'openingHoursSpecification', $shop ), 'no opening hours' );
		assert_false( array_key_exists( 'addressLocality', $shop['address'] ), 'no locality' );
	}
);

db_test(
	'schema: the service offers equal the core package prices exactly, skip a package with no real price, and areaServed needs a confirmed area',
	function () {
		$ctx             = dbgr_schema_ctx_catering();
		$ctx['packages'] = array(
			array( 'name' => 'Box A', 'price' => 45.0 ),
			array( 'name' => 'Box B', 'price' => 123.5 ),
			array( 'name' => 'Free box', 'price' => 0.0 ),
			array( 'name' => 'Bad price', 'price' => '12' ),
			array( 'name' => '', 'price' => 10.0 ),
		);
		$doc             = DoughBoss_Growth_Landing_Schema::build( $ctx );
		$service         = $doc['@graph'][1];
		assert_same( 'https://doughboss.test/#organization', $service['provider']['@id'], 'provider is the core organisation id' );
		assert_same( 2, count( $service['offers'] ), 'only the two packages with a real numeric price' );
		assert_same( array( '45.00', '123.50' ), array( $service['offers'][0]['price'], $service['offers'][1]['price'] ), 'prices, two decimals' );
		assert_same( array( 45.0, 123.5 ), array( (float) $service['offers'][0]['price'], (float) $service['offers'][1]['price'] ), 'numerically equal to the meta' );
		assert_same( 'AUD', $service['offers'][0]['priceCurrency'], 'currency' );
		assert_same( array( 'Place One', 'Place Two' ), array( $service['areaServed'][0]['name'], $service['areaServed'][1]['name'] ), 'confirmed areas' );

		$ctx['areas'] = array();
		$doc          = DoughBoss_Growth_Landing_Schema::build( $ctx );
		assert_false( array_key_exists( 'areaServed', $doc['@graph'][1] ), 'no confirmed area, no areaServed' );
		$ctx['packages'] = array();
		$doc             = DoughBoss_Growth_Landing_Schema::build( $ctx );
		assert_false( array_key_exists( 'offers', $doc['@graph'][1] ), 'no package with a price, no offers' );
		assert_same( '1.10', DoughBoss_Growth_Landing_Schema::price_string( 1.1 ), 'price_string pads to two decimals' );
		assert_same( '1234.50', DoughBoss_Growth_Landing_Schema::price_string( 1234.5 ), 'price_string has no thousands separator' );
	}
);

db_test(
	'schema: a FAQPage appears only when there is a confirmed question and answer',
	function () {
		$ctx         = dbgr_schema_ctx_catering();
		$ctx['faqs'] = array();
		$doc         = DoughBoss_Growth_Landing_Schema::build( $ctx );
		$types       = array_map( function ( $n ) {
			return $n['@type'];
		}, $doc['@graph'] );
		assert_false( in_array( 'FAQPage', $types, true ), 'no FAQPage without FAQ claims' );

		$ctx['faqs'] = array( array( '', 'answer without a question' ), array( 'question without an answer', ' ' ), array( 'Real question', 'Real answer' ) );
		$doc         = DoughBoss_Growth_Landing_Schema::build( $ctx );
		$faq         = $doc['@graph'][2];
		assert_same( 'FAQPage', $faq['@type'], 'FAQPage present' );
		assert_same( 1, count( $faq['mainEntity'] ), 'incomplete pairs are dropped' );
		assert_same( 'Real answer', $faq['mainEntity'][0]['acceptedAnswer']['text'], 'answer text' );
	}
);

db_test(
	'schema: breadcrumb positions, names and urls; fewer than two crumbs is refused',
	function () {
		$doc   = DoughBoss_Growth_Landing_Schema::build( dbgr_schema_ctx_catering() );
		$crumb = $doc['@graph'][0];
		assert_same( 'https://doughboss.test/catering/corporate/#breadcrumb', $crumb['@id'], 'breadcrumb id' );
		assert_same( array( 1, 2, 3 ), array_map( function ( $i ) {
			return $i['position'];
		}, $crumb['itemListElement'] ), 'positions' );
		assert_same( 'https://doughboss.test/catering/', $crumb['itemListElement'][1]['item'], 'parent url' );
		$ctx           = dbgr_schema_ctx_catering();
		$ctx['crumbs'] = array( array( 'Home', 'https://doughboss.test/' ) );
		assert_same( null, DoughBoss_Growth_Landing_Schema::build( $ctx ), 'a single crumb is refused' );
	}
);

db_test(
	'schema negative control: every forbidden or unsourced property is reported by validate()',
	function () {
		$base = DoughBoss_Growth_Landing_Schema::build( dbgr_schema_ctx_location() );
		assert_same( array(), DoughBoss_Growth_Landing_Schema::validate( $base ), 'control: the clean graph passes' );

		$tamper = array(
			'geo'             => array( '@type' => 'GeoCoordinates', 'latitude' => '1', 'longitude' => '2' ),
			'sameAs'          => array( 'https://example.test/profile' ),
			'aggregateRating' => array( '@type' => 'AggregateRating', 'ratingValue' => '4.9' ),
			'priceRange'      => '$$',
			'hasMenu'         => 'https://example.test/menu',
			'servesCuisine'   => 'Anything',
			'image'           => 'https://example.test/a.jpg',
			'foundingDate'    => '2009',
			'awards'          => 'x',
		);
		foreach ( $tamper as $property => $value ) {
			$doc                          = $base;
			$doc['@graph'][1][ $property ] = $value;
			$problems                     = DoughBoss_Growth_Landing_Schema::validate( $doc );
			assert_true( array() !== $problems, 'adding ' . $property . ' is reported' );
			assert_contains( '"' . $property . '"', implode( ' | ', $problems ), 'the report names ' . $property );
		}

		$doc                             = $base;
		$doc['@graph'][1]['address']['postalCode'] = '0000';
		assert_true( array() !== DoughBoss_Growth_Landing_Schema::validate( $doc ), 'a postalCode nobody sourced is reported' );

		$doc                         = $base;
		$doc['@graph'][]             = array( '@type' => 'LocalBusiness', 'name' => 'Unlisted type' );
		assert_true( array() !== DoughBoss_Growth_Landing_Schema::validate( $doc ), 'a type with no source entry is reported' );

		assert_true( array() !== DoughBoss_Growth_Landing_Schema::validate( 'not a document' ), 'a non-document is reported' );
		assert_true( array() !== DoughBoss_Growth_Landing_Schema::validate( array( '@context' => 'https://example.test', '@graph' => array() ) ), 'a foreign @context is reported' );
	}
);

db_test(
	'schema: fail closed - bad context returns null, never a partial graph',
	function () {
		$cases = array(
			'unknown kind'        => array( 'kind' => 'hotel' ),
			'relative page url'   => array( 'url' => '/catering/corporate/' ),
			'javascript home url' => array( 'home' => 'javascript:alert(1)' ),
			'empty home'          => array( 'home' => '' ),
			'crumb with no name'  => array( 'crumbs' => array( array( 'Home', 'https://doughboss.test/' ), array( ' ', 'https://doughboss.test/x/' ) ) ),
			'crumb with bad url'  => array( 'crumbs' => array( array( 'Home', 'https://doughboss.test/' ), array( 'X', 'ftp://x/' ) ) ),
			'no service name'     => array( 'service' => array( 'name' => '' ) ),
		);
		foreach ( $cases as $label => $override ) {
			assert_same( null, DoughBoss_Growth_Landing_Schema::build( array_merge( dbgr_schema_ctx_catering(), $override ) ), $label . ' -> null' );
		}
		$bad_shop = array(
			'no address'      => array( 'address' => " \n " ),
			'bad slug'        => array( 'slug' => 'Not A Slug' ),
			'empty name'      => array( 'name' => ' ' ),
		);
		foreach ( $bad_shop as $label => $override ) {
			$ctx             = dbgr_schema_ctx_location();
			$ctx['location'] = array_merge( $ctx['location'], $override );
			assert_same( null, DoughBoss_Growth_Landing_Schema::build( $ctx ), $label . ' -> null' );
		}
		$ctx             = dbgr_schema_ctx_location();
		$ctx['location'] = null;
		assert_same( null, DoughBoss_Growth_Landing_Schema::build( $ctx ), 'no location -> null' );
		assert_same( 'Test Unit 1', DoughBoss_Growth_Landing_Schema::first_line( "\n  Test   Unit 1 \nRevesby" ), 'first_line collapses whitespace and skips blank lines' );
	}
);

db_test(
	'schema: encode() cannot be broken out of - script close tags, ampersands and quotes are escaped and round-trip',
	function () {
		$hostile = '</script><script>alert(1)</script> & "quoted" \'single\' <b>x</b> caf' . "\u{e9}";
		$ctx     = dbgr_schema_ctx_catering();
		$ctx['packages'][0]['name'] = $hostile;
		$doc  = DoughBoss_Growth_Landing_Schema::build( $ctx );
		$json = DoughBoss_Growth_Landing_Schema::encode( $doc );
		assert_not_contains( '</script>', strtolower( $json ), 'no raw script close tag' );
		assert_not_contains( '<script', strtolower( $json ), 'no raw script open tag' );
		assert_not_contains( '<b>', $json, 'no raw tag at all' );
		assert_contains( 'caf' . "\u{e9}", $json, 'unicode kept readable' );
		$back = json_decode( $json, true );
		assert_same( $hostile, $back['@graph'][1]['offers'][0]['name'], 'decodes back to the exact value' );
	}
);

/* ------------------------------------------------------------------------------------------ */
/* The real page, end to end                                                                     */
/* ------------------------------------------------------------------------------------------ */

/**
 * The JSON-LD documents printed in a head string.
 *
 * @param string $head Head HTML.
 * @return array
 */
function dbgr_schema_scripts( $head ) {
	$out = array();
	if ( preg_match_all( '#<script type="application/ld\+json">(.*?)</script>#s', $head, $m ) ) {
		foreach ( $m[1] as $json ) {
			$out[] = json_decode( $json, true );
		}
	}
	return $out;
}

db_test(
	'schema end to end: the Revesby page head prints ONE graph whose shop node is core data, id, url and hours',
	function () {
		dbgr_landing_boot();
		DoughBoss_Growth_Landing::init();
		$map = dbgr_landing_make_pages();
		dbgr_landing_query( $map['locations-revesby'] );
		$head    = dbgr_landing_head();
		$scripts = dbgr_schema_scripts( $head );
		assert_same( 1, count( $scripts ), 'exactly one JSON-LD script' );
		assert_same( 1, substr_count( $head, 'application/ld+json' ), 'and one script tag' );
		assert_same( array(), DoughBoss_Growth_Landing_Schema::validate( $scripts[0] ), 'every property has a source' );
		$shop = $scripts[0]['@graph'][1];
		assert_same( 'https://doughboss.test/#location-revesby', $shop['@id'], 'shop @id equals the core id' );
		assert_same( 'https://doughboss.test/locations/revesby/', $shop['url'], 'shop url equals the page permalink' );
		assert_same( 'Revesby', $shop['name'], 'name from core' );
		assert_same( '(02) 5550 0101', $shop['telephone'], 'phone from core' );
		assert_same( 4, count( $shop['openingHoursSpecification'] ), 'hours from core weekly_hours(): Monday, Tuesday and two Saturday ranges' );
		$crumbs = $scripts[0]['@graph'][0]['itemListElement'];
		assert_same( array( 'Home', 'Locations', 'Revesby' ), array_map( function ( $i ) {
			return $i['name'];
		}, $crumbs ), 'breadcrumb names (the parent title is the real hub page title)' );
		assert_same( 'https://doughboss.test/locations/', $crumbs[1]['item'], 'hub url' );
		assert_same( 'https://doughboss.test/locations/revesby/', $crumbs[2]['item'], 'page url' );
		dbgr_landing_reset();
	}
);

db_test(
	'schema end to end: shop page data follows core - change the address and hours and the graph changes, nothing is hard-coded',
	function () {
		dbgr_landing_boot();
		DoughBoss_Growth_Landing::init();
		$map = dbgr_landing_make_pages();
		dbgr_landing_query( $map['locations-bankstown'] );
		$before = dbgr_schema_scripts( dbgr_landing_head() );
		assert_same( 'Test Arcade 2 Bankstown Test', $before[0]['@graph'][1]['address']['streetAddress'], 'address read from core' );

		DoughBoss_Locations::$rows[1]->address = "Another Place 9\nBankstown Test";
		DoughBoss_Locations::$rows[1]->phone   = '(02) 5550 0999';
		DoughBoss_Locations::$hours[2]         = array( 'fri' => '10:00-11:00' );
		DoughBoss_Growth_Landing::reset_cache();
		$after = dbgr_schema_scripts( dbgr_landing_head() );
		assert_same( 'Another Place 9', $after[0]['@graph'][1]['address']['streetAddress'], 'the new address is read at render time' );
		assert_same( '(02) 5550 0999', $after[0]['@graph'][1]['telephone'], 'the new phone' );
		assert_same( 'Friday', $after[0]['@graph'][1]['openingHoursSpecification'][0]['dayOfWeek'], 'the new hours' );
		assert_same( 1, count( $after[0]['@graph'][1]['openingHoursSpecification'] ), 'and only the new hours' );
		dbgr_landing_reset();
	}
);

db_test(
	'schema end to end: catering page Offers equal core package meta exactly; no price in core, no Offer',
	function () {
		dbgr_landing_boot();
		DoughBoss_Growth_Landing::init();
		dbgr_landing_package( 301, 'Box One', '45', 10, 12 );
		dbgr_landing_package( 302, 'Box Two', 123.5, 20, 25 );
		dbgr_landing_package( 303, 'Box Three', '0', 5, 5 );
		dbgr_landing_package( 304, 'Box Four', 'abc', 5, 5 );
		dbgr_landing_package( 305, 'Box Five', '99.999', 5, 5 );
		dbgr_landing_package( 306, 'Draft box', '10', 5, 5, '', 'draft' );
		$map = dbgr_landing_make_pages();
		dbgr_landing_query( $map['catering-events'] );
		$scripts = dbgr_schema_scripts( dbgr_landing_head() );
		assert_same( 1, count( $scripts ), 'one script' );
		$service = $scripts[0]['@graph'][1];
		assert_same( 'Service', $service['@type'], 'service node' );
		assert_same( 'Event catering', $service['name'], 'service name from the definition' );
		$offers = array();
		foreach ( $service['offers'] as $offer ) {
			$offers[ $offer['name'] ] = $offer['price'];
		}
		assert_same( array( 'Box One' => '45.00', 'Box Two' => '123.50', 'Box Five' => '100.00' ), $offers, 'prices exactly as core stores them (rounded to two places); no zero, non-numeric or draft package' );
		assert_false( array_key_exists( 'areaServed', $service ), 'no confirmed service area, so no areaServed' );
		dbgr_landing_reset();
	}
);

db_test(
	'schema end to end: areaServed and FAQPage appear only from confirmed claims that carry the structured fields',
	function () {
		$dir = dbgr_landing_ledger(
			array(
				dbgr_landing_claim( 'catering-service-area', 'Sentence about the area', true, array( 'areas' => array( 'Place One', 'Place Two' ) ) ),
			)
		);
		dbgr_landing_boot();
		// dbgr_landing_boot() resets the ledger override; set it again for this test.
		DoughBoss_Growth_Ledger::set_file_override( $dir . '/claims.json' );
		DoughBoss_Growth_Landing::reset_cache();
		DoughBoss_Growth_Landing::init();
		dbgr_landing_package( 301, 'Box One', '45', 10, 12 );
		$map = dbgr_landing_make_pages();
		dbgr_landing_query( $map['catering-corporate'] );
		$scripts = dbgr_schema_scripts( dbgr_landing_head() );
		assert_same( array( 'Place One', 'Place Two' ), array_map( function ( $a ) {
			return $a['name'];
		}, $scripts[0]['@graph'][1]['areaServed'] ), 'areaServed from the confirmed claim areas' );
		$types = array_map( function ( $n ) {
			return $n['@type'];
		}, $scripts[0]['@graph'] );
		assert_false( in_array( 'FAQPage', $types, true ), 'no FAQ block, no FAQPage' );

		// A claim with a hostile area name never reaches the graph.
		$bad = dbgr_landing_ledger(
			array(
				dbgr_landing_claim( 'catering-service-area', 'Sentence about the area', true, array( 'areas' => array( 'Good Place', 'Halal Place', dbgr_landing_banned_word() . ' Place' ) ) ),
			)
		);
		DoughBoss_Growth_Ledger::set_file_override( $bad . '/claims.json' );
		DoughBoss_Growth_Landing::reset_cache();
		$scripts = dbgr_schema_scripts( dbgr_landing_head() );
		assert_same( array( 'Good Place' ), array_map( function ( $a ) {
			return $a['name'];
		}, $scripts[0]['@graph'][1]['areaServed'] ), 'areas that fail the public-copy lint are dropped' );

		// A claim that is only a sentence (no areas list) gives no areaServed.
		$plain = dbgr_landing_ledger( array( dbgr_landing_claim( 'catering-service-area', 'Sentence about the area', true ) ) );
		DoughBoss_Growth_Ledger::set_file_override( $plain . '/claims.json' );
		DoughBoss_Growth_Landing::reset_cache();
		$scripts = dbgr_schema_scripts( dbgr_landing_head() );
		assert_false( array_key_exists( 'areaServed', $scripts[0]['@graph'][1] ), 'a sentence is not a place name: no areaServed' );
		foreach ( array( $dir, $bad, $plain ) as $d ) {
			dbgr_test_rmdir( $d );
		}
		dbgr_landing_reset();
	}
);

db_test(
	'schema end to end: a FAQ block makes a FAQPage only for a confirmed claim that has a question',
	function () {
		$tmp = sys_get_temp_dir() . '/dbgr-landing-faq-' . bin2hex( random_bytes( 4 ) ) . '/';
		mkdir( $tmp, 0777, true );
		$def = array(
			'version'      => 1,
			'key'          => 'catering-corporate',
			'kind'         => 'catering',
			'parent'       => 'catering',
			'slug'         => 'corporate',
			'page_title'   => 'Corporate catering',
			'crumb'        => 'Corporate catering',
			'title'        => 'Corporate Catering Enquiries',
			'description'  => 'Enquire about corporate catering from Dough Boss.',
			'service_name' => 'Corporate catering',
			'blocks'       => array(
				array( 'type' => 'faq', 'heading' => 'Questions', 'claims' => array( 'faq-one', 'faq-two', 'faq-three' ) ),
			),
		);
		file_put_contents( $tmp . 'catering-corporate.json', json_encode( $def ) );
		$dir = dbgr_landing_ledger(
			array(
				dbgr_landing_claim( 'faq-one', 'Answer one', true, array( 'question' => 'Question one?' ) ),
				dbgr_landing_claim( 'faq-two', 'Answer two without a question', true ),
				dbgr_landing_claim( 'faq-three', 'Answer three unconfirmed', false, array( 'question' => 'Question three?' ) ),
			)
		);
		dbgr_landing_boot();
		DoughBoss_Growth_Ledger::set_file_override( $dir . '/claims.json' );
		DoughBoss_Growth_Landing::set_definition_dir_override( $tmp );
		DoughBoss_Growth_Landing::init();
		$map = dbgr_landing_make_pages();
		dbgr_landing_query( $map['catering-corporate'] );
		$scripts = dbgr_schema_scripts( dbgr_landing_head() );
		$faq     = null;
		foreach ( $scripts[0]['@graph'] as $node ) {
			if ( 'FAQPage' === $node['@type'] ) {
				$faq = $node;
			}
		}
		assert_true( null !== $faq, 'FAQPage present' );
		assert_same( 1, count( $faq['mainEntity'] ), 'only the confirmed claim with a question' );
		assert_same( 'Question one?', $faq['mainEntity'][0]['name'], 'question' );
		assert_same( 'Answer one', $faq['mainEntity'][0]['acceptedAnswer']['text'], 'answer' );
		$html = DoughBoss_Growth_Landing::render_key( 'catering-corporate' );
		assert_contains( 'Question one?', $html, 'visible FAQ shows the confirmed question' );
		assert_not_contains( 'Question three?', $html, 'an unconfirmed question is not shown' );
		assert_not_contains( 'Answer two', $html, 'a claim with no question is not shown as FAQ' );
		dbgr_test_rmdir( $dir );
		dbgr_test_rmdir( rtrim( $tmp, '/' ) );
		dbgr_landing_reset();
	}
);

db_test(
	'schema end to end: the graph is never printed for the hub pages, an unregistered page or an edited page',
	function () {
		dbgr_landing_boot();
		DoughBoss_Growth_Landing::init();
		$map = dbgr_landing_make_pages();
		foreach ( array( 10, 11 ) as $hub ) {
			dbgr_landing_query( $hub );
			assert_same( '', dbgr_landing_head(), 'hub page ' . $hub . ' prints nothing' );
		}
		dbgr_landing_query( 999 );
		assert_same( '', dbgr_landing_head(), 'an unknown page prints nothing' );
		dbgr_landing_query( $map['locations-revesby'], 'post' );
		assert_same( '', dbgr_landing_head(), 'a post (not a page) prints nothing' );
		wp_update_post( array( 'ID' => $map['locations-revesby'], 'post_content' => '[doughboss_growth_landing key="locations-revesby"] and text the owner typed' ) );
		dbgr_landing_query( $map['locations-revesby'] );
		assert_same( '', dbgr_landing_head(), 'a page the owner edited past the single shortcode prints nothing' );
		wp_update_post( array( 'ID' => $map['locations-revesby'], 'post_content' => '[doughboss_growth_landing key="locations-bankstown"]' ) );
		assert_same( '', dbgr_landing_head(), 'a page whose shortcode names a different key prints nothing' );
		dbgr_landing_reset();
	}
);
