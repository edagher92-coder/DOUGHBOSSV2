<?php
/**
 * WP-06 tests: the landing pages engine and the SEO head.
 *
 * Covers the page definitions (all six shipped files valid, every bad shape refused), the render rules (no block for
 * an unconfirmed claim, core data read at render time, the core form guard), the create-pages button (drafts only,
 * parent and slug checks, idempotent, capability and nonce), the head ownership rules (one title, one description,
 * nothing on a hub page, nothing with an SEO plugin) and the registry wiring. The JSON-LD graph has its own file,
 * test-landing-schema.php; the rendered pages are covered by web/scripts/wp-local/growth/wp06-landing.mjs.
 *
 * @package DoughBoss_Growth
 */

DoughBoss_Growth::load_module( 'ledger' );
DoughBoss_Growth::load_module( 'landing' );

const DBGR_LP_KEYS = array( 'catering-corporate', 'catering-events', 'catering-office-breakfast', 'locations-bankstown', 'locations-revesby', 'locations-roselands' );

/**
 * A valid catering definition to mutate in the negative tests.
 *
 * @return array
 */
function dbgr_lp_def() {
	return array(
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
			array( 'type' => 'claim', 'id' => 'catering-lead-time', 'heading' => 'Lead time' ),
			array( 'type' => 'packages', 'heading' => 'Catering packages' ),
			array( 'type' => 'form' ),
		),
	);
}

/**
 * A valid shop definition.
 *
 * @return array
 */
function dbgr_lp_loc_def() {
	return array(
		'version'       => 1,
		'key'           => 'locations-revesby',
		'kind'          => 'location',
		'parent'        => 'locations',
		'slug'          => 'revesby',
		'location_slug' => 'revesby',
		'page_title'    => 'Revesby',
		'crumb'         => '{name}',
		'title'         => '{name} Shop Details',
		'description'   => 'Dough Boss {name} shop: {details}.',
		'blocks'        => array(
			array( 'type' => 'location', 'heading' => 'Find the shop', 'slots' => array( 'address', 'phone', 'hours' ) ),
		),
	);
}

/* ------------------------------------------------------------------------------------------ */
/* Definitions                                                                                   */
/* ------------------------------------------------------------------------------------------ */

db_test(
	'definitions: the six shipped files are valid, are exactly the contract pages, and no definition names the unannounced product',
	function () {
		dbgr_landing_boot();
		assert_same( array(), DoughBoss_Growth_Landing::definition_problems(), 'no problems in any shipped definition' );
		assert_same( DBGR_LP_KEYS, array_keys( DoughBoss_Growth_Landing::definitions() ), 'exactly the six contract keys, in file order' );
		$paths = array();
		foreach ( DoughBoss_Growth_Landing::definitions() as $key => $def ) {
			$paths[] = $def['parent'] . '/' . $def['slug'];
			assert_same( $key, $def['key'], $key . ': key matches' );
		}
		sort( $paths );
		assert_same(
			array( 'catering/corporate', 'catering/events', 'catering/office-breakfast', 'locations/bankstown', 'locations/revesby', 'locations/roselands' ),
			$paths,
			'the contract URLs'
		);
		$word = strtolower( dbgr_landing_banned_word() );
		$dirs = array( DOUGHBOSS_GROWTH_DIR . 'content/landing', DOUGHBOSS_GROWTH_DIR . 'includes/landing', DOUGHBOSS_GROWTH_DIR . 'public/css' );
		foreach ( $dirs as $dir ) {
			foreach ( glob( $dir . '/*' ) as $file ) {
				assert_false( false !== strpos( strtolower( (string) file_get_contents( $file ) ), $word ), basename( $file ) . ' never spells the unannounced product name' );
				assert_false( false !== strpos( strtolower( basename( $file ) ), $word ), basename( $file ) . ' file name is neutral' );
			}
		}
		foreach ( DBGR_LP_KEYS as $key ) {
			assert_true( false === strpos( $key, 'mini' ), $key . ' is not a product key' );
		}
		dbgr_landing_reset();
	}
);

db_test(
	'definitions: every page title and description is unique and passes the lint with the fixture shops, within the length guideline',
	function () {
		dbgr_landing_boot();
		$titles = array();
		$descs  = array();
		foreach ( DBGR_LP_KEYS as $key ) {
			$texts = DoughBoss_Growth_Landing_SEO::texts( $key );
			assert_true( is_array( $texts ), $key . ': metadata texts resolve' );
			$titles[ $key ] = $texts['title'];
			$descs[ $key ]  = $texts['description'];
			assert_same( array(), DoughBoss_Growth_Landing::lint_text( $texts['title'], 'core-data', true ), $key . ': title lint' );
			assert_same( array(), DoughBoss_Growth_Landing::lint_text( $texts['description'], 'core-data', true ), $key . ': description lint' );
			assert_true( strlen( $texts['title'] ) <= 60, $key . ': title within 60 characters (' . strlen( $texts['title'] ) . ')' );
			assert_true( strlen( $texts['description'] ) <= 155, $key . ': description within 155 characters (' . strlen( $texts['description'] ) . ')' );
			assert_contains( ' | Dough Boss', $texts['title'], $key . ': one brand suffix' );
		}
		assert_same( count( $titles ), count( array_unique( $titles ) ), 'all six titles are unique' );
		assert_same( count( $descs ), count( array_unique( $descs ) ), 'all six descriptions are unique' );
		assert_same( 'Revesby Shop Details | Dough Boss', $titles['locations-revesby'], 'a shop title uses the core name' );
		assert_same( 'Dough Boss Revesby shop: address, phone number and opening hours.', $descs['locations-revesby'], 'the description lists only what core has' );
		assert_same( 'Dough Boss Roselands Centro shop: address.', $descs['locations-roselands'], 'a shop with no phone and no hours says only address' );
		dbgr_landing_reset();
	}
);

db_test(
	'definitions negative control: the valid definition passes, and each bad shape is refused with a reason',
	function () {
		dbgr_landing_boot();
		assert_same( array(), DoughBoss_Growth_Landing::validate_definition( dbgr_lp_def(), 'catering-corporate.json' ), 'control: valid catering definition' );
		assert_same( array(), DoughBoss_Growth_Landing::validate_definition( dbgr_lp_loc_def(), 'locations-revesby.json' ), 'control: valid shop definition' );

		$word  = dbgr_landing_banned_word();
		$cases = array(
			'unknown kind'                  => array( array( 'kind' => 'hotel' ), 'kind must be' ),
			'parent does not match kind'    => array( array( 'parent' => 'locations' ), 'parent must be' ),
			'key not kebab-case'            => array( array( 'key' => 'Catering Corporate' ), 'key must be kebab-case' ),
			'key differs from the file'     => array( array( 'key' => 'catering-events' ), 'key must equal the file name' ),
			'slug with a slash'             => array( array( 'slug' => 'a/b' ), 'slug must be kebab-case' ),
			'wrong version'                 => array( array( 'version' => 2 ), 'version must be 1' ),
			'unknown field'                 => array( array( 'noindex' => true ), 'unknown field' ),
			'unannounced product in title'  => array( array( 'title' => $word . ' Catering' ), 'title fails the public-copy lint' ),
			'digit in static description'   => array( array( 'description' => 'Enquire about 24 hour catering from Dough Boss.' ), 'description fails the public-copy lint' ),
			'dollar in title'               => array( array( 'title' => 'Catering from $9' ), 'title fails the public-copy lint' ),
			'dietary word in description'   => array( array( 'description' => 'Halal catering from Dough Boss.' ), 'description fails the public-copy lint' ),
			'fresh is an unconfirmed claim' => array( array( 'description' => 'Fresh catering from Dough Boss.' ), 'description fails the public-copy lint' ),
			'delivery is an unconfirmed claim' => array( array( 'title' => 'Catering Delivery' ), 'title fails the public-copy lint' ),
			'best'                          => array( array( 'description' => 'The best catering from Dough Boss.' ), 'description fails the public-copy lint' ),
			'placeholder on a catering page' => array( array( 'title' => '{name} Catering' ), 'placeholder {name}' ),
			'stray brace'                   => array( array( 'title' => 'Catering {' ), 'stray brace' ),
			'title too long'                => array( array( 'title' => str_repeat( 'Catering ', 8 ) ), 'title is longer than 60' ),
			'description too long'          => array( array( 'description' => str_repeat( 'Catering from Dough Boss. ', 7 ) ), 'description is longer than 155' ),
			'missing crumb'                 => array( array( 'crumb' => ' ' ), 'crumb is required' ),
			'service name missing'          => array( array( 'service_name' => '' ), 'service_name is required' ),
			'location slug on catering'     => array( array( 'location_slug' => 'revesby' ), 'location_slug is for location pages only' ),
			'no blocks'                     => array( array( 'blocks' => array() ), 'blocks must be a list' ),
			'blocks not a list'             => array( array( 'blocks' => array( 'a' => array( 'type' => 'form' ) ) ), 'blocks must be a list' ),
			'location block on catering'    => array( array( 'blocks' => array( array( 'type' => 'location', 'heading' => 'x', 'slots' => array( 'address' ) ) ) ), 'not allowed on a catering page' ),
			'unknown block type'            => array( array( 'blocks' => array( array( 'type' => 'script' ) ) ), 'not allowed on a catering page' ),
			'claim not in the ledger'       => array( array( 'blocks' => array( array( 'type' => 'claim', 'id' => 'invented-claim', 'heading' => 'x' ) ) ), 'is not in the ledger' ),
			'claim without a heading'       => array( array( 'blocks' => array( array( 'type' => 'claim', 'id' => 'catering-lead-time' ) ) ), 'heading is required' ),
			'heading with a digit'          => array( array( 'blocks' => array( array( 'type' => 'claim', 'id' => 'catering-lead-time', 'heading' => 'Within 2 days' ) ) ), 'heading fails the public-copy lint' ),
			'unknown block field'           => array( array( 'blocks' => array( array( 'type' => 'form', 'html' => '<b>x</b>' ) ) ), 'unknown field' ),
			'two packages blocks'           => array( array( 'blocks' => array( array( 'type' => 'packages', 'heading' => 'A' ), array( 'type' => 'packages', 'heading' => 'B' ) ) ), 'only one packages block' ),
			'too many blocks'               => array( array( 'blocks' => array_fill( 0, 13, array( 'type' => 'form' ) ) ), 'blocks must be a list' ),
			'faq with no claims'            => array( array( 'blocks' => array( array( 'type' => 'faq', 'heading' => 'Q', 'claims' => array() ) ) ), 'claims must be a list' ),
		);
		foreach ( $cases as $label => $case ) {
			$def      = array_merge( dbgr_lp_def(), $case[0] );
			$problems = DoughBoss_Growth_Landing::validate_definition( $def, 'catering-corporate.json' );
			assert_true( array() !== $problems, $label . ': refused' );
			assert_contains( $case[1], implode( ' | ', $problems ), $label . ': reason' );
		}

		$loc_cases = array(
			'missing location slug'  => array( array( 'location_slug' => '' ), 'location_slug must be' ),
			'service name on a shop' => array( array( 'service_name' => 'x' ), 'service_name is for catering pages only' ),
			'unknown placeholder'    => array( array( 'title' => '{address} Shop Details' ), 'placeholder {address}' ),
			'no address slot'        => array( array( 'blocks' => array( array( 'type' => 'location', 'heading' => 'x', 'slots' => array( 'phone' ) ) ) ), 'include address' ),
			'unknown slot'           => array( array( 'blocks' => array( array( 'type' => 'location', 'heading' => 'x', 'slots' => array( 'address', 'geo' ) ) ) ), 'slots must be a list' ),
			'packages on a shop'     => array( array( 'blocks' => array( array( 'type' => 'packages', 'heading' => 'x' ) ) ), 'not allowed on a location page' ),
		);
		foreach ( $loc_cases as $label => $case ) {
			$problems = DoughBoss_Growth_Landing::validate_definition( array_merge( dbgr_lp_loc_def(), $case[0] ), 'locations-revesby.json' );
			assert_true( array() !== $problems, $label . ': refused' );
			assert_contains( $case[1], implode( ' | ', $problems ), $label . ': reason' );
		}
		assert_true( array() !== DoughBoss_Growth_Landing::validate_definition( 'not an object', 'x.json' ), 'a non-object is refused' );
		dbgr_landing_reset();
	}
);

db_test(
	'definitions: a folder with bad files loads only the valid ones and reports the rest (invalid JSON, duplicate key or path, oversize, wrong file name)',
	function () {
		dbgr_landing_boot();
		$dir = sys_get_temp_dir() . '/dbgr-landing-defs-' . bin2hex( random_bytes( 4 ) ) . '/';
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . 'catering-corporate.json', json_encode( dbgr_lp_def() ) );
		file_put_contents( $dir . 'broken.json', '{ not json' );
		file_put_contents( $dir . 'huge.json', str_repeat( ' ', DoughBoss_Growth_Landing::MAX_DEFINITION_BYTES + 1 ) );
		$wrong_name = array_merge( dbgr_lp_def(), array( 'key' => 'catering-events', 'slug' => 'events' ) );
		file_put_contents( $dir . 'catering-other.json', json_encode( $wrong_name ) );
		$dup_path = array_merge( dbgr_lp_def(), array( 'key' => 'catering-twin' ) );
		file_put_contents( $dir . 'catering-twin.json', json_encode( $dup_path ) );
		file_put_contents( $dir . 'notes.txt', 'ignored' );
		DoughBoss_Growth_Landing::set_definition_dir_override( $dir );
		assert_same( array( 'catering-corporate' ), array_keys( DoughBoss_Growth_Landing::definitions() ), 'only the one good file is loaded' );
		$problems = implode( ' | ', DoughBoss_Growth_Landing::definition_problems() );
		assert_contains( 'broken.json: not valid JSON', $problems, 'invalid JSON reported' );
		assert_contains( 'huge.json: file is empty, unreadable or too large', $problems, 'oversize reported' );
		assert_contains( 'catering-other.json: key must equal the file name', $problems, 'wrong file name reported' );
		assert_contains( 'catering-twin.json: duplicate key or page path', $problems, 'duplicate page path reported' );

		DoughBoss_Growth_Landing::set_definition_dir_override( $dir . 'missing/' );
		assert_same( array(), DoughBoss_Growth_Landing::definitions(), 'a missing folder means no pages' );
		dbgr_test_rmdir( rtrim( $dir, '/' ) );
		dbgr_landing_reset();
	}
);

/* ------------------------------------------------------------------------------------------ */
/* Shortcode and rendering                                                                       */
/* ------------------------------------------------------------------------------------------ */

db_test(
	'shortcode: off means empty, never the raw tag (the registered placeholder), and on means the page',
	function () {
		dbgr_landing_boot( array() );
		DoughBoss_Growth_Landing::init();
		assert_true( shortcode_exists( 'doughboss_growth_landing' ), 'the tag is always registered once the module runs' );
		assert_same( '', do_shortcode( '[doughboss_growth_landing key="locations-revesby"]' ), 'flag off: empty, not the raw tag' );
		assert_same( '', do_shortcode( 'before [doughboss_growth_landing key="catering-corporate"] after' ) === 'before  after' ? '' : 'raw tag leaked', 'flag off inside text' );

		dbgr_landing_boot();
		DoughBoss_Growth_Landing::init();
		$html = do_shortcode( '[doughboss_growth_landing key="locations-revesby"]' );
		assert_contains( 'data-dbgr-landing="locations-revesby"', $html, 'flag on: the page renders' );
		assert_same( '', do_shortcode( '[doughboss_growth_landing key="no-such-page"]' ), 'unknown key: empty' );
		assert_same( '', do_shortcode( '[doughboss_growth_landing key="BAD KEY"]' ), 'malformed key: empty' );
		assert_same( '', do_shortcode( '[doughboss_growth_landing]' ), 'no key: empty' );
		assert_same( '', do_shortcode( '[doughboss_growth_landing key="catering-corporate"]' ), 'a page with nothing to show renders nothing at all' );
		dbgr_landing_reset();
	}
);

db_test(
	'render: a shop page shows address, phone and hours read from core at render time, escaped, with a visible breadcrumb',
	function () {
		dbgr_landing_boot();
		DoughBoss_Growth_Landing::init();
		$html = DoughBoss_Growth_Landing::render_key( 'locations-revesby' );
		assert_contains( '<address class="dbgr-lp__address">Test Unit 1<br />Revesby Test</address>', $html, 'address, one line each' );
		assert_contains( '<a href="tel:+61255500101">(02) 5550 0101</a>', $html, 'phone as stored, tel link derived to E.164' );
		assert_contains( '<dt>Monday</dt><dd>6:30am to 2:30pm</dd>', $html, 'Monday hours' );
		assert_contains( '<dt>Saturday</dt><dd>7am to 12pm, 1pm to 3pm</dd>', $html, 'two Saturday ranges' );
		assert_not_contains( '<dt>Wednesday</dt>', $html, 'a day with no hours is omitted, never shown as closed' );
		assert_contains( '<nav class="dbgr-lp__crumbs" aria-label="Breadcrumb">', $html, 'breadcrumb nav' );
		assert_contains( '<li><a href="https://doughboss.test/locations/">Locations</a></li>', $html, 'hub link uses the real hub page title and permalink' );
		assert_contains( '<li aria-current="page">Revesby</li>', $html, 'current crumb' );
		assert_not_contains( '$', $html, 'no price on a shop page' );

		$bank = DoughBoss_Growth_Landing::render_key( 'locations-bankstown' );
		assert_contains( '<a href="tel:+61255500102">0255500102</a>', $bank, 'phone digits only: E.164 derived, display as stored' );
		$rose = DoughBoss_Growth_Landing::render_key( 'locations-roselands' );
		assert_not_contains( 'dbgr-lp__phone', $rose, 'no phone in core, no phone section' );
		assert_not_contains( 'dbgr-lp__hours', $rose, 'no hours in core, no hours section' );
		assert_contains( 'Roselands Centro', $rose, 'name from core in the breadcrumb' );

		// Change core: the page follows (nothing is hard-coded).
		DoughBoss_Locations::$rows[0]->address = "New Place 7\nRevesby Test";
		DoughBoss_Growth_Landing::reset_cache();
		assert_contains( 'New Place 7', DoughBoss_Growth_Landing::render_key( 'locations-revesby' ), 'a changed core address is shown' );
		assert_not_contains( 'Test Unit 1', DoughBoss_Growth_Landing::render_key( 'locations-revesby' ), 'and the old one is gone' );

		// Hostile core text is escaped (tags are stripped by the sanitiser, quotes by esc_html).
		DoughBoss_Locations::$rows[0]->address = 'Quote " & <b>bold</b> Street';
		DoughBoss_Growth_Landing::reset_cache();
		$escaped = DoughBoss_Growth_Landing::render_key( 'locations-revesby' );
		assert_contains( 'Quote &quot; &amp; bold Street', $escaped, 'escaped output' );
		assert_not_contains( '<b>', $escaped, 'no raw tag from core data' );
		dbgr_landing_reset();
	}
);

db_test(
	'render: core failure paths leave the page empty (no active shop with the slug, inactive, database error, bad name, no address)',
	function () {
		dbgr_landing_boot();
		DoughBoss_Growth_Landing::init();
		$reset = function () {
			DoughBoss_Growth_Landing::reset_cache();
		};
		$compose = function ( $key ) use ( $reset ) {
			$reset();
			return DoughBoss_Growth_Landing::compose( $key );
		};
		assert_same( true, $compose( 'locations-revesby' )['ok'], 'control: renders' );

		DoughBoss_Locations::$rows[0]->slug = 'revesby-two';
		$c = $compose( 'locations-revesby' );
		assert_same( false, $c['ok'], 'no core shop with the slug' );
		assert_same( 'core_location_missing', $c['reason'], 'reason' );
		assert_same( '', DoughBoss_Growth_Landing::render_key( 'locations-revesby' ), 'renders nothing' );
		DoughBoss_Locations::$rows[0]->slug = 'revesby';

		DoughBoss_Locations::$rows[0]->is_active = 0;
		assert_same( false, $compose( 'locations-revesby' )['ok'], 'an inactive shop is not shown' );
		DoughBoss_Locations::$rows[0]->is_active = 1;

		DoughBoss_Locations::$throw = true;
		assert_same( false, $compose( 'locations-revesby' )['ok'], 'a database error fails closed' );
		DoughBoss_Locations::$throw = false;

		DoughBoss_Locations::$rows[0]->name = 'Shop ' . dbgr_landing_banned_word();
		assert_same( false, $compose( 'locations-revesby' )['ok'], 'a core name that fails the lint fails the page closed' );
		DoughBoss_Locations::$rows[0]->name = 'Revesby';

		DoughBoss_Locations::$rows[0]->address = "  \n ";
		assert_same( false, $compose( 'locations-revesby' )['ok'], 'no address, no page' );
		DoughBoss_Locations::$rows[0]->address = "Test Unit 1\nRevesby Test";

		DoughBoss_Locations::$rows[0]->phone = 'Call ' . dbgr_landing_banned_word();
		$c = $compose( 'locations-revesby' );
		assert_same( true, $c['ok'], 'a bad phone only drops the phone' );
		assert_same( '', $c['location']['phone'], 'phone dropped' );
		DoughBoss_Locations::$rows[0]->phone = '(02) 5550 0101';

		DoughBoss_Locations::$throw_hours = true;
		$c = $compose( 'locations-revesby' );
		assert_same( true, $c['ok'], 'an hours failure keeps the page' );
		assert_same( array(), $c['location']['hours'], 'with no hours' );
		DoughBoss_Locations::$throw_hours = false;

		DoughBoss_Locations::$hours[1] = array( 'mon' => 'garbage, 25:99-26:00, 09:00-10:00' );
		$c = $compose( 'locations-revesby' );
		assert_same( array( 'mon' => array( array( '09:00', '10:00' ) ) ), array( 'mon' => $c['location']['hours']['mon'] ), 'malformed ranges are dropped, good ones kept' );
		dbgr_landing_reset();
	}
);

db_test(
	'render: no block is rendered for an unconfirmed, unsourced or lint-blocked claim; a confirmed claim appears exactly as written',
	function () {
		dbgr_landing_boot();
		DoughBoss_Growth_Landing::init();
		dbgr_landing_package( 301, 'Box One', '45', 10, 12 );
		$html = DoughBoss_Growth_Landing::render_key( 'catering-corporate' );
		foreach ( array( 'Where we cater', 'Delivery or drop-off', 'Lead time', 'wording to be supplied', 'Gap' ) as $needle ) {
			assert_not_contains( $needle, $html, 'default ledger: "' . $needle . '" is never printed' );
		}
		assert_contains( 'Box One', $html, 'control: the page itself still renders its packages' );

		$dir = dbgr_landing_ledger(
			array(
				dbgr_landing_claim( 'catering-lead-time', 'Lead time wording from the owner' ),
				dbgr_landing_claim( 'catering-service-area', 'Unconfirmed wording', false ),
				dbgr_landing_claim( 'catering-delivery-or-drop-off', 'Pick-up in 2 days', true, array( 'source' => array( 'kind' => 'owner-site', 'ref' => 'https://example.test/' ) ) ),
			)
		);
		$html = DoughBoss_Growth_Landing::render_key( 'catering-corporate' );
		assert_contains( '<h2 class="dbgr-lp__heading">Lead time</h2><p class="dbgr-lp__text">Lead time wording from the owner</p>', $html, 'the confirmed claim, exactly as written' );
		assert_not_contains( 'Unconfirmed wording', $html, 'an unconfirmed claim stays out' );
		assert_not_contains( 'Where we cater', $html, 'and its heading with it' );
		assert_not_contains( 'Pick-up in 2 days', $html, 'a confirmed claim with a digit from a non-owner source is blocked by the lint' );
		assert_not_contains( 'Delivery or drop-off', $html, 'and its heading too' );
		$c = DoughBoss_Growth_Landing::compose( 'catering-corporate' );
		$hidden = array();
		foreach ( $c['hidden'] as $h ) {
			$hidden[ $h[0] ] = $h[1];
		}
		assert_same( 'claim_not_publishable', $hidden['claim:catering-service-area'], 'hidden reason recorded for the admin tab' );
		assert_same( 'claim_not_publishable', $hidden['claim:catering-delivery-or-drop-off'], 'lint-blocked claim is also listed as hidden' );

		// An invalid ledger switches every page off.
		file_put_contents( $dir . '/claims.json', '{ broken' );
		DoughBoss_Growth_Ledger::reset();
		DoughBoss_Growth_Landing::reset_cache();
		assert_same( '', DoughBoss_Growth_Landing::render_key( 'catering-corporate' ), 'a broken ledger file: nothing renders' );
		assert_same( 'ledger_invalid', DoughBoss_Growth_Landing::compose( 'catering-corporate' )['reason'], 'reason' );
		dbgr_test_rmdir( $dir );
		dbgr_landing_reset();
	}
);

db_test(
	'render: the ledger guard switches the shortcode off while the ledger is invalid (feature flag turns off through the filter)',
	function () {
		dbgr_landing_boot();
		DoughBoss_Growth_Ledger::init();
		$dir = dbgr_landing_ledger( array() );
		file_put_contents( $dir . '/claims.json', json_encode( array( 'version' => 1, 'claims' => array( array( 'id' => 'Bad Id', 'text' => 'x', 'confirmed' => false ) ) ) ) );
		DoughBoss_Growth_Ledger::reset();
		assert_false( DoughBoss_Growth_Landing::enabled(), 'landing_pages is off while the ledger is invalid' );
		assert_same( '', DoughBoss_Growth_Landing::shortcode( array( 'key' => 'locations-revesby' ) ), 'the shortcode callback prints nothing' );
		assert_false( DoughBoss_Growth_Landing_SEO::head_enabled(), 'and so is the head' );
		dbgr_test_rmdir( $dir );
		dbgr_landing_reset();
	}
);

db_test(
	'render: catering package cards - real price format, serves text, includes, escaped; rows with no real price, a draft or a banned name are not shown',
	function () {
		dbgr_landing_boot();
		DoughBoss_Growth_Landing::init();
		dbgr_landing_package( 301, 'Fish &amp; Chips Box', '45', 10, 12, "Item one\nItem two\n\n" . dbgr_landing_banned_word() . " inside\n" );
		dbgr_landing_package( 302, 'Solo Box', 9.5, 1, 1 );
		dbgr_landing_package( 303, 'Max only', '20', 0, 30 );
		dbgr_landing_package( 304, 'No price', '', 5, 5 );
		dbgr_landing_package( 305, 'Zero price', '0', 5, 5 );
		dbgr_landing_package( 306, 'Draft box', '15', 5, 5, '', 'draft' );
		dbgr_landing_package( 307, 'Box ' . dbgr_landing_banned_word(), '15', 5, 5 );
		dbgr_landing_package( 308, 'Tag <b>Box</b>', '15', 5, 5 );
		$html = DoughBoss_Growth_Landing::render_key( 'catering-corporate' );
		assert_contains( '<h3 class="dbgr-lp__package-name">Fish &amp; Chips Box</h3>', $html, 'entity-encoded title shown once-encoded (no &amp;amp;)' );
		assert_contains( '<p class="dbgr-lp__serves">Serves 10 to 12</p><p class="dbgr-lp__price">$45.00</p>', $html, 'serves range and price' );
		assert_contains( '<li>Item one</li><li>Item two</li>', $html, 'includes, one item per line, blanks dropped' );
		assert_not_contains( 'inside', $html, 'an includes line that fails the lint is dropped' );
		assert_contains( '$9.50', $html, 'two decimals' );
		assert_contains( 'Serves 1', $html, 'a single guest count' );
		assert_contains( 'Serves 30', $html, 'max only' );
		foreach ( array( 'No price', 'Zero price', 'Draft box', 'Box ' . dbgr_landing_banned_word() ) as $hidden ) {
			assert_not_contains( $hidden, $html, '"' . $hidden . '" is not shown' );
		}
		assert_contains( '<h3 class="dbgr-lp__package-name">Tag Box</h3>', $html, 'tags are stripped from a name' );
		assert_not_contains( '&amp;amp;', $html, 'never double-encoded' );

		// No package at all: the block is hidden with a reason and the page keeps whatever else it has.
		dbgr_landing_reset();
		dbgr_landing_boot();
		DoughBoss_Growth_Landing::init();
		$c = DoughBoss_Growth_Landing::compose( 'catering-corporate' );
		assert_same( array(), $c['blocks'], 'nothing to show: no blocks' );
		$reasons = array();
		foreach ( $c['hidden'] as $h ) {
			$reasons[ $h[0] ] = $h[1];
		}
		assert_same( 'no_published_package_with_a_price', $reasons['packages'], 'packages hidden with a reason' );
		dbgr_landing_reset();
	}
);

db_test(
	'core form guard: shown when core renders clean copy and asks core to load its assets; left out when its copy contains the product name, is missing, empty or unrendered',
	function () {
		dbgr_landing_boot();
		DoughBoss_Growth_Landing::init();
		dbgr_landing_package( 301, 'Box One', '45', 10, 12 );
		$map = dbgr_landing_make_pages();

		// Clean core copy.
		dbgr_landing_core_form();
		DoughBoss_Growth_Landing::reset_cache();
		$html = DoughBoss_Growth_Landing::render_key( 'catering-events' );
		assert_contains( '<section class="dbgr-lp__block dbgr-lp__block--form"><div class="db-app db-catering" data-doughboss-catering>', $html, 'the core form is embedded' );
		dbgr_landing_query( $map['catering-events'] );
		assert_same( true, apply_filters( 'doughboss_load_catering_assets', false ), 'core is asked to load its catering assets on this page' );
		assert_same( true, apply_filters( 'doughboss_load_assets', false ), 'and its base storefront assets (the catering style depends on them)' );
		assert_same( true, apply_filters( 'doughboss_load_catering_assets', true ), 'an existing true is kept' );
		dbgr_landing_query( 10 );
		assert_same( false, apply_filters( 'doughboss_load_catering_assets', false ), 'the hub page is untouched' );
		assert_same( 'sentinel', apply_filters( 'doughboss_load_assets', 'sentinel' ), 'a non-bool value is returned unchanged elsewhere' );
		dbgr_landing_query( $map['locations-revesby'] );
		assert_same( false, apply_filters( 'doughboss_load_catering_assets', false ), 'a shop page has no form block, so no catering assets' );

		// Core copy that contains the product working name (as core 2.43.2 does today).
		dbgr_landing_core_form( '<div data-doughboss-catering><p>We will help balance ' . strtolower( dbgr_landing_banned_word() ) . ', pies and favourites.</p></div>' );
		DoughBoss_Growth_Landing::reset_cache();
		$html = DoughBoss_Growth_Landing::render_key( 'catering-events' );
		assert_not_contains( 'data-doughboss-catering', $html, 'the form is left out' );
		assert_not_contains( strtolower( dbgr_landing_banned_word() ), strtolower( $html ), 'the product name never reaches the page' );
		assert_contains( 'Box One', $html, 'the rest of the page is intact' );
		$form = DoughBoss_Growth_Landing::core_form();
		assert_same( false, $form['shown'], 'not shown' );
		assert_same( 'core_form_copy_blocked:product_name', $form['reason'], 'reason names the lint code (not the word)' );
		dbgr_landing_query( $map['catering-events'] );
		assert_same( false, apply_filters( 'doughboss_load_catering_assets', false ), 'and core is not asked to load assets for a form that is not shown' );

		// A halal claim in core copy is blocked too.
		dbgr_landing_core_form( '<div data-doughboss-catering>Halal food</div>' );
		DoughBoss_Growth_Landing::reset_cache();
		assert_same( 'core_form_copy_blocked:halal', DoughBoss_Growth_Landing::core_form()['reason'], 'a dietary word in core copy is blocked' );

		// Empty, not rendered, missing.
		dbgr_landing_core_form( '   ' );
		DoughBoss_Growth_Landing::reset_cache();
		assert_same( 'core_form_empty', DoughBoss_Growth_Landing::core_form()['reason'], 'empty output' );
		dbgr_landing_core_form( 'The shortcode [doughboss_catering] came back raw' );
		DoughBoss_Growth_Landing::reset_cache();
		assert_same( 'core_form_not_rendered', DoughBoss_Growth_Landing::core_form()['reason'], 'a raw tag in the output' );
		remove_shortcode( 'doughboss_catering' );
		DoughBoss_Growth_Landing::reset_cache();
		assert_same( 'core_shortcode_missing', DoughBoss_Growth_Landing::core_form()['reason'], 'core shortcode absent: no raw tag is ever printed' );
		assert_not_contains( '[doughboss_catering', DoughBoss_Growth_Landing::render_key( 'catering-events' ), 'no raw core tag' );
		add_shortcode(
			'doughboss_catering',
			function () {
				throw new RuntimeException( 'boom' );
			}
		);
		DoughBoss_Growth_Landing::reset_cache();
		assert_same( 'core_form_failed', DoughBoss_Growth_Landing::core_form()['reason'], 'an exception in core is contained' );
		dbgr_landing_reset();
	}
);

db_test(
	'indexing: a page with content of its own is indexable; one with only the shared package cards and form is not',
	function () {
		dbgr_landing_boot();
		DoughBoss_Growth_Landing::init();
		dbgr_landing_package( 301, 'Box One', '45', 10, 12 );
		assert_true( DoughBoss_Growth_Landing::is_indexable( 'locations-revesby' ), 'a shop page has its own content' );
		assert_false( DoughBoss_Growth_Landing::is_indexable( 'catering-corporate' ), 'a catering page with no confirmed claim is not indexable' );
		assert_false( DoughBoss_Growth_Landing::is_indexable( 'no-such-page' ), 'an unknown page is not indexable' );
		$dir = dbgr_landing_ledger( array( dbgr_landing_claim( 'catering-lead-time', 'Lead time wording from the owner' ) ) );
		assert_true( DoughBoss_Growth_Landing::is_indexable( 'catering-corporate' ), 'a confirmed claim gives it content of its own' );
		DoughBoss_Locations::$throw = true;
		DoughBoss_Growth_Landing::reset_cache();
		assert_false( DoughBoss_Growth_Landing::is_indexable( 'locations-revesby' ), 'a shop page that cannot render is not indexable' );
		dbgr_test_rmdir( $dir );
		dbgr_landing_reset();
	}
);

/* ------------------------------------------------------------------------------------------ */
/* Creating the pages                                                                            */
/* ------------------------------------------------------------------------------------------ */

db_test(
	'create pages: six DRAFT children under the existing parents, shortcode body, recorded in the option, nothing published',
	function () {
		dbgr_landing_boot();
		$result = DoughBoss_Growth_Landing::create_pages();
		assert_same( array_fill_keys( DBGR_LP_KEYS, 'created' ), $result, 'all six created' );
		$map = DoughBoss_Growth_Landing::page_map();
		assert_same( DBGR_LP_KEYS, array_keys( $map ), 'the option records all six keys' );
		assert_same( $map, get_option( 'doughboss_growth_pages' ), 'stored as key => id' );
		$parents = array( 'catering' => 10, 'locations' => 11 );
		foreach ( $map as $key => $id ) {
			$post = get_post( $id );
			$def  = DoughBoss_Growth_Landing::definition( $key );
			assert_same( 'draft', $post->post_status, $key . ': draft' );
			assert_same( 'page', $post->post_type, $key . ': a page' );
			assert_same( $parents[ $def['parent'] ], $post->post_parent, $key . ': under the existing parent' );
			assert_same( $def['slug'], $post->post_name, $key . ': slug' );
			assert_same( '[doughboss_growth_landing key="' . $key . '"]', $post->post_content, $key . ': body is exactly the shortcode' );
			assert_same( 'closed', $post->comment_status, $key . ': comments closed' );
			assert_true( DoughBoss_Growth_Activator::is_companion_shortcode_only( $post->post_content ), $key . ': recognised by the deactivation drafting' );
			assert_same( home_url( '/' . $def['parent'] . '/' . $def['slug'] . '/' ), get_permalink( $id ), $key . ': the contract URL' );
		}
		assert_same( 6, count( $GLOBALS['dbgr_insert_calls'] ), 'six inserts' );
		assert_same( array(), $GLOBALS['dbgr_post_writes'], 'no existing post was updated' );
		dbgr_landing_reset();
	}
);

db_test(
	'create pages: idempotent, keeps other keys of the option, recreates a deleted page, leaves a trashed one, adopts an orphan with the exact shortcode',
	function () {
		dbgr_landing_boot();
		update_option( 'doughboss_growth_pages', array( 'coming-soon' => array( 'id' => 77 ), 'weird' => 'x' ) );
		DoughBoss_Growth_Landing::create_pages();
		$stored = get_option( 'doughboss_growth_pages' );
		assert_same( array( 77 ), array( $stored['coming-soon']['id'] ), 'another module\'s entry is kept' );
		assert_same( 'x', $stored['weird'], 'and so is junk it does not own' );
		$first = DoughBoss_Growth_Landing::page_map();

		$GLOBALS['dbgr_insert_calls'] = array();
		$again                        = DoughBoss_Growth_Landing::create_pages();
		assert_same( array_fill_keys( DBGR_LP_KEYS, 'exists' ), $again, 'second run: all exist' );
		assert_same( array(), $GLOBALS['dbgr_insert_calls'], 'nothing inserted twice' );
		assert_same( $first, DoughBoss_Growth_Landing::page_map(), 'ids unchanged' );

		// A recorded page that was deleted is created again.
		unset( $GLOBALS['dbgr_posts'][ $first['locations-bankstown'] ] );
		$GLOBALS['dbgr_insert_calls'] = array();
		$res                          = DoughBoss_Growth_Landing::create_pages();
		assert_same( 'created', $res['locations-bankstown'], 'recreated' );
		assert_same( 1, count( $GLOBALS['dbgr_insert_calls'] ), 'only that one' );

		// A trashed page is left alone.
		$GLOBALS['dbgr_posts'][ $first['catering-events'] ]->post_status = 'trash';
		$GLOBALS['dbgr_insert_calls']                                     = array();
		$res                                                              = DoughBoss_Growth_Landing::create_pages();
		assert_same( 'trashed', $res['catering-events'], 'trashed page is reported' );
		assert_same( array(), $GLOBALS['dbgr_insert_calls'], 'and not recreated' );

		// Option lost, pages still there with the exact shortcode: adopted, not duplicated.
		update_option( 'doughboss_growth_pages', array() );
		$GLOBALS['dbgr_posts'][ $first['catering-events'] ]->post_status = 'draft';
		$GLOBALS['dbgr_insert_calls']                                     = array();
		$res                                                              = DoughBoss_Growth_Landing::create_pages();
		assert_same( 'adopted', $res['catering-corporate'], 'adopted' );
		assert_same( array(), $GLOBALS['dbgr_insert_calls'], 'no duplicates inserted' );
		assert_same( $first['catering-corporate'], DoughBoss_Growth_Landing::page_id( 'catering-corporate' ), 'recorded again with the same id' );
		dbgr_landing_reset();
	}
);

db_test(
	'create pages negative control: a missing parent, a slug taken by another page, an insert failure and a bad definition each create nothing',
	function () {
		dbgr_landing_boot();
		// Parent missing for the shop pages.
		unset( $GLOBALS['dbgr_posts'][11] );
		$res = DoughBoss_Growth_Landing::create_pages();
		assert_same( 'created', $res['catering-corporate'], 'control: the catering group is fine' );
		foreach ( array( 'locations-revesby', 'locations-bankstown', 'locations-roselands' ) as $key ) {
			assert_same( 'parent_missing', $res[ $key ], $key . ': parent missing, refused' );
		}
		assert_same( 3, count( DoughBoss_Growth_Landing::page_map() ), 'only the three catering pages are recorded' );
		assert_same( 3, count( $GLOBALS['dbgr_insert_calls'] ), 'and only three inserts happened' );

		// The parent in the bin counts as missing.
		dbgr_landing_boot();
		$GLOBALS['dbgr_posts'][10]->post_status = 'trash';
		$res                                    = DoughBoss_Growth_Landing::create_pages();
		assert_same( 'parent_missing', $res['catering-events'], 'a trashed parent is missing' );
		unset( $GLOBALS['dbgr_posts'][10] );
		$GLOBALS['dbgr_pages_by_path'] = array();

		// Slug taken by a page that is not the companion's.
		dbgr_landing_boot();
		dbgr_test_add_post( array( 'ID' => 500, 'post_name' => 'corporate', 'post_title' => 'Someone else', 'post_parent' => 10, 'post_content' => 'Their own content' ) );
		$res = DoughBoss_Growth_Landing::create_pages();
		assert_same( 'slug_taken', $res['catering-corporate'], 'refused' );
		assert_same( 'Their own content', get_post( 500 )->post_content, 'their page is untouched' );
		assert_same( 'created', $res['catering-events'], 'the others are fine' );
		assert_false( isset( DoughBoss_Growth_Landing::page_map()['catering-corporate'] ), 'and the taken one is not recorded' );
		assert_same( array(), $GLOBALS['dbgr_post_writes'], 'no existing page was written' );

		// A page with a DIFFERENT companion shortcode on that path is also not adopted.
		dbgr_landing_boot();
		dbgr_test_add_post( array( 'ID' => 501, 'post_name' => 'corporate', 'post_parent' => 10, 'post_content' => '[doughboss_growth_landing key="catering-events"]' ) );
		assert_same( 'slug_taken', DoughBoss_Growth_Landing::create_pages()['catering-corporate'], 'a different key is not adopted' );

		// Insert failure.
		dbgr_landing_boot();
		$GLOBALS['dbgr_insert_fail'] = true;
		$res                         = DoughBoss_Growth_Landing::create_pages();
		assert_same( array_fill_keys( DBGR_LP_KEYS, 'insert_failed' ), $res, 'every failed insert is reported' );
		assert_same( array(), DoughBoss_Growth_Landing::page_map(), 'nothing recorded' );
		assert_same( false, get_option( 'doughboss_growth_pages', false ), 'the option is not even written' );

		// No valid definitions at all.
		dbgr_landing_boot();
		DoughBoss_Growth_Landing::set_definition_dir_override( sys_get_temp_dir() . '/dbgr-no-such-folder/' );
		assert_same( array(), DoughBoss_Growth_Landing::create_pages(), 'nothing to create' );
		assert_same( array(), $GLOBALS['dbgr_insert_calls'], 'and nothing inserted' );
		dbgr_landing_reset();
	}
);

db_test(
	'create pages handler: capability AND nonce are enforced, then it redirects to the tab with the result',
	function () {
		dbgr_landing_boot();
		dbgr_test_set_admin( true );
		DoughBoss_Growth_Landing::init();
		assert_true( false !== has_action( 'admin_post_doughboss_growth_create_pages', array( 'DoughBoss_Growth_Landing', 'handle_create_pages' ) ), 'the admin-post action is registered' );

		// No login.
		$died = null;
		try {
			DoughBoss_Growth_Landing::handle_create_pages();
		} catch ( DBGR_Test_Die $e ) {
			$died = $e;
		}
		assert_true( null !== $died, 'a visitor is refused' );
		assert_same( 403, $died->args['response'], '403' );

		// Logged in without the capability (a subscriber).
		dbgr_test_login( array( 'read' ) );
		$_REQUEST['_wpnonce'] = dbgr_test_nonce( 'doughboss_growth_create_pages' );
		$died                 = null;
		try {
			DoughBoss_Growth_Landing::handle_create_pages();
		} catch ( DBGR_Test_Die $e ) {
			$died = $e;
		}
		assert_true( null !== $died, 'a user without the capability is refused even with a valid nonce' );

		// Manager, bad nonce, and a nonce for another action.
		dbgr_test_login( array( 'manage_doughboss' ) );
		foreach ( array( '', 'deadbeef00', dbgr_test_nonce( 'doughboss_growth_save_settings' ) ) as $bad ) {
			$_REQUEST['_wpnonce'] = $bad;
			$died                 = null;
			try {
				DoughBoss_Growth_Landing::handle_create_pages();
			} catch ( DBGR_Test_Die $e ) {
				$died = $e;
			}
			assert_true( null !== $died, 'manager with nonce "' . $bad . '" is refused' );
		}
		assert_same( array(), $GLOBALS['dbgr_insert_calls'], 'every refusal created nothing' );
		assert_same( array(), DoughBoss_Growth_Landing::page_map(), 'and recorded nothing' );

		// Manager with the right nonce.
		$_REQUEST['_wpnonce'] = dbgr_test_nonce( 'doughboss_growth_create_pages' );
		$redirect             = null;
		try {
			DoughBoss_Growth_Landing::handle_create_pages();
		} catch ( DBGR_Test_Redirect $r ) {
			$redirect = $r;
		}
		assert_true( null !== $redirect, 'redirects' );
		assert_contains( 'page=doughboss-growth', $redirect->url, 'to the Growth page' );
		assert_contains( 'tab=landing', $redirect->url, 'on the landing tab' );
		assert_contains( 'dbgr_lp=catering-corporate.created', $redirect->url, 'with the per-page result' );
		assert_same( 6, count( DoughBoss_Growth_Landing::page_map() ), 'six pages recorded' );
		assert_same( 6, count( $GLOBALS['dbgr_insert_calls'] ), 'six inserts' );

		// manage_options also works (the core admin falls back to it).
		dbgr_test_login( array( 'manage_options' ) );
		$_REQUEST['_wpnonce'] = dbgr_test_nonce( 'doughboss_growth_create_pages' );
		$redirect             = null;
		try {
			DoughBoss_Growth_Landing::handle_create_pages();
		} catch ( DBGR_Test_Redirect $r ) {
			$redirect = $r;
		}
		assert_contains( 'catering-corporate.exists', $redirect->url, 'a second click reports the pages as existing' );
		dbgr_landing_reset();
	}
);

/* ------------------------------------------------------------------------------------------ */
/* Head ownership                                                                                */
/* ------------------------------------------------------------------------------------------ */

db_test(
	'head: a published shop page prints one description, Open Graph and Twitter tags and one JSON-LD script at priority 6; one full title at priority 30; no canonical and no robots tag',
	function () {
		dbgr_landing_boot();
		DoughBoss_Growth_Landing::init();
		assert_same( 6, has_action( 'wp_head', array( 'DoughBoss_Growth_Landing_SEO', 'print_head' ) ), 'wp_head priority 6' );
		assert_same( 30, has_filter( 'document_title_parts', array( 'DoughBoss_Growth_Landing_SEO', 'filter_title_parts' ) ), 'document_title_parts priority 30' );
		$map = dbgr_landing_make_pages();
		dbgr_landing_query( $map['locations-revesby'] );
		$head = dbgr_landing_head();
		assert_same( 1, substr_count( $head, '<meta name="description"' ), 'exactly one meta description' );
		assert_contains( '<meta name="description" content="Dough Boss Revesby shop: address, phone number and opening hours." />', $head, 'description' );
		assert_contains( '<meta property="og:title" content="Revesby Shop Details | Dough Boss" />', $head, 'og:title' );
		assert_contains( '<meta property="og:url" content="https://doughboss.test/locations/revesby/" />', $head, 'og:url is the permalink' );
		assert_contains( '<meta property="og:type" content="website" />', $head, 'og:type' );
		assert_contains( '<meta property="og:locale" content="en_AU" />', $head, 'og:locale' );
		assert_contains( '<meta name="twitter:title" content="Revesby Shop Details | Dough Boss" />', $head, 'twitter:title' );
		assert_contains( '<meta name="twitter:card" content="summary" />', $head, 'summary card when core exposes no image url' );
		assert_not_contains( 'og:image', $head, 'no image tag without a core image url' );
		assert_not_contains( 'rel="canonical"', $head, 'the companion never prints a canonical (WordPress core does)' );
		assert_not_contains( 'name="robots"', $head, 'and no robots tag' );
		assert_same( 1, substr_count( $head, 'application/ld+json' ), 'exactly one JSON-LD script' );

		$parts = apply_filters( 'document_title_parts', array( 'title' => 'Revesby', 'tagline' => 'Tagline', 'site' => 'Dough Boss' ) );
		assert_same( array( 'title' => 'Revesby Shop Details | Dough Boss' ), $parts, 'one full title: tagline and site removed so the brand is not repeated' );

		$robots = apply_filters( 'wp_robots', array( 'max-image-preview' => 'large' ) );
		assert_same( array( 'max-image-preview' => 'large' ), $robots, 'an indexable page keeps WordPress\'s own robots directives' );
		dbgr_landing_reset();
	}
);

db_test(
	'head: a thin catering page (no content of its own yet) is marked noindex through wp_robots, and prints the same single title and description',
	function () {
		dbgr_landing_boot();
		DoughBoss_Growth_Landing::init();
		dbgr_landing_package( 301, 'Box One', '45', 10, 12 );
		$map = dbgr_landing_make_pages();
		dbgr_landing_query( $map['catering-corporate'] );
		$robots = apply_filters( 'wp_robots', array( 'max-image-preview' => 'large', 'index' => true ) );
		assert_same( true, $robots['noindex'], 'noindex' );
		assert_false( isset( $robots['index'] ), 'the index directive is removed, so there is no contradiction' );
		assert_same( 1, substr_count( dbgr_landing_head(), '<meta name="description"' ), 'one description' );
		$parts = apply_filters( 'document_title_parts', array( 'title' => 'x', 'site' => 'y' ) );
		assert_same( 'Corporate Catering Enquiries | Dough Boss', $parts['title'], 'title' );

		$dir = dbgr_landing_ledger( array( dbgr_landing_claim( 'catering-lead-time', 'Lead time wording from the owner' ) ) );
		$robots = apply_filters( 'wp_robots', array( 'max-image-preview' => 'large' ) );
		assert_false( isset( $robots['noindex'] ), 'with a confirmed claim of its own the page is indexable' );
		dbgr_test_rmdir( $dir );
		dbgr_landing_reset();
	}
);

db_test(
	'head: with core exposing its social card the image tags are printed with the 1200 by 630 size',
	function () {
		dbgr_landing_boot();
		$meta = array(
			'title'       => 'T | Dough Boss',
			'description' => 'D',
			'url'         => 'https://doughboss.test/x/',
			'image'       => 'https://doughboss.test/wp-content/plugins/doughboss/public/images/doughboss-social-card.jpg',
			'site_name'   => 'Dough Boss',
		);
		$tags = DoughBoss_Growth_Landing_SEO::meta_tags( $meta );
		assert_contains( '<meta property="og:image" content="https://doughboss.test/wp-content/plugins/doughboss/public/images/doughboss-social-card.jpg" />', $tags, 'og:image' );
		assert_contains( 'og:image:width" content="1200"', $tags, 'width' );
		assert_contains( 'og:image:height" content="630"', $tags, 'height' );
		assert_contains( '<meta name="twitter:card" content="summary_large_image" />', $tags, 'large card' );
		assert_contains( '<meta name="twitter:image" content=', $tags, 'twitter:image' );
		$hostile = DoughBoss_Growth_Landing_SEO::meta_tags( array_merge( $meta, array( 'title' => 'A "quoted" <b>x</b> & y', 'image' => '' ) ) );
		assert_contains( 'content="A &quot;quoted&quot; &lt;b&gt;x&lt;/b&gt; &amp; y"', $hostile, 'attribute values are escaped' );
		dbgr_landing_reset();
	}
);

db_test(
	'head negative control: nothing is printed or changed on a hub page, an unregistered page, a draft-edited page, or with a flag off',
	function () {
		dbgr_landing_boot();
		DoughBoss_Growth_Landing::init();
		$map = dbgr_landing_make_pages();
		$hub_title = array( 'title' => 'Catering', 'site' => 'Dough Boss' );
		foreach ( array( 10, 11 ) as $hub ) {
			dbgr_landing_query( $hub );
			assert_same( '', dbgr_landing_head(), 'hub ' . $hub . ': no head output' );
			assert_same( $hub_title, apply_filters( 'document_title_parts', $hub_title ), 'hub ' . $hub . ': the title is untouched' );
			assert_same( array( 'a' => 1 ), apply_filters( 'wp_robots', array( 'a' => 1 ) ), 'hub ' . $hub . ': robots untouched' );
		}
		dbgr_landing_query( 0 );
		assert_same( '', dbgr_landing_head(), 'not a singular page: nothing' );

		// seo_head off, landing_pages on: no head at all.
		dbgr_landing_boot( array( 'landing_pages' => true ) );
		DoughBoss_Growth_Landing::init();
		$map2 = dbgr_landing_make_pages();
		dbgr_landing_query( $map2['locations-revesby'] );
		assert_same( '', dbgr_landing_head(), 'seo_head off: nothing in the head' );
		assert_contains( 'dbgr-lp', DoughBoss_Growth_Landing::render_key( 'locations-revesby' ), 'control: the page body still renders' );

		// seo_head on, landing_pages off: also nothing (metadata for a page that renders nothing would be wrong).
		dbgr_landing_boot( array( 'seo_head' => true ) );
		DoughBoss_Growth_Landing::init();
		$map3 = dbgr_landing_make_pages();
		dbgr_landing_query( $map3['locations-revesby'] );
		assert_same( '', dbgr_landing_head(), 'landing_pages off: nothing in the head' );
		assert_same( '', do_shortcode( '[doughboss_growth_landing key="locations-revesby"]' ), 'and no body' );

		// Both off.
		dbgr_landing_boot( array() );
		DoughBoss_Growth_Landing::init();
		$map4 = dbgr_landing_make_pages();
		dbgr_landing_query( $map4['locations-revesby'] );
		assert_same( '', dbgr_landing_head(), 'all flags off: nothing' );
		assert_false( has_action( 'wp_head', array( 'DoughBoss_Growth_Landing_SEO', 'print_head' ) ), 'no head hook is registered at all' );
		assert_false( has_filter( 'doughboss_load_assets', array( 'DoughBoss_Growth_Landing', 'filter_load_core_assets' ) ), 'no core asset filter either' );
		dbgr_landing_reset();
	}
);

db_test(
	'head: an incomplete page prints nothing - core shop missing, title too long, lint failure in the expanded text',
	function () {
		dbgr_landing_boot();
		DoughBoss_Growth_Landing::init();
		$map = dbgr_landing_make_pages();
		dbgr_landing_query( $map['locations-revesby'] );
		assert_contains( 'description', dbgr_landing_head(), 'control: prints' );

		DoughBoss_Locations::$rows[0]->slug = 'someone-else';
		DoughBoss_Growth_Landing::reset_cache();
		assert_same( '', dbgr_landing_head(), 'core has no such shop: no metadata, no JSON-LD' );
		$robots = apply_filters( 'wp_robots', array() );
		assert_same( true, $robots['noindex'], 'and the empty page is noindex' );
		DoughBoss_Locations::$rows[0]->slug = 'revesby';

		DoughBoss_Locations::$rows[0]->name = str_repeat( 'Longname ', 9 );
		DoughBoss_Growth_Landing::reset_cache();
		assert_same( '', dbgr_landing_head(), 'a title over the hard limit prints nothing' );
		DoughBoss_Locations::$rows[0]->name = 'Revesby';

		// A hub page that is no longer a page with a real URL.
		DoughBoss_Growth_Landing::reset_cache();
		unset( $GLOBALS['dbgr_posts'][11] );
		$orphan = dbgr_landing_head();
		assert_same( 0, substr_count( $orphan, 'application/ld+json' ), 'no hub page: no breadcrumb, so no JSON-LD graph' );
		assert_contains( '<meta name="description"', $orphan, 'the title and description are still valid and still printed' );
		dbgr_landing_reset();
	}
);

/* ------------------------------------------------------------------------------------------ */
/* SEO plugin coexistence (constants cannot be undefined, so each case runs in its own process)  */
/* ------------------------------------------------------------------------------------------ */

/**
 * Run the head for a Revesby page in a fresh process where an SEO plugin constant is defined.
 *
 * @param string $constant Constant to define ("" for none).
 * @param array  $settings Extra settings.
 * @return array|null Decoded result or null when the process failed.
 */
function dbgr_lp_sub_head( $constant, array $settings = array() ) {
	$prelude = ( '' === $constant ) ? '' : "define('" . $constant . "', '1.0');";
	$code    = <<<'PHPCODE'
dbgr_landing_boot( array( 'landing_pages' => true, 'seo_head' => true ), %s );
DoughBoss_Growth_Landing::init();
$map = dbgr_landing_make_pages();
dbgr_landing_query( $map['locations-revesby'] );
$head   = dbgr_landing_head();
$title  = apply_filters( 'document_title_parts', array( 'title' => 'Original', 'site' => 'Site' ) );
$robots = apply_filters( 'wp_robots', array( 'max-image-preview' => 'large' ) );
$thin   = array();
echo json_encode( array(
	'description' => substr_count( $head, '<meta name="description"' ),
	'og'          => substr_count( $head, 'property="og:' ),
	'twitter'     => substr_count( $head, 'name="twitter:' ),
	'jsonld'      => substr_count( $head, 'application/ld+json' ),
	'title'       => $title,
	'robots'      => $robots,
	'body'        => ( false !== strpos( DoughBoss_Growth_Landing::render_key( 'locations-revesby' ), 'data-dbgr-landing' ) ),
) );
PHPCODE;
	$code    = sprintf( $code, var_export( $settings, true ) );
	$run     = dbgr_test_subprocess( $code, $prelude );
	if ( 0 !== $run['exit'] ) {
		return null;
	}
	$decoded = json_decode( trim( $run['out'] ), true );
	return is_array( $decoded ) ? $decoded : null;
}

db_test(
	'SEO plugin: with a dedicated SEO plugin active the companion prints nothing in the head and leaves the title alone; with seo_jsonld_with_seo_plugin it prints the JSON-LD graph only',
	function () {
		if ( ! dbgr_test_can_subprocess() ) {
			dbgr_test_skip( 'cannot start a sub-process here, so the SEO plugin constant cases did not run' );
			return;
		}
		// Control: no SEO plugin.
		$none = dbgr_lp_sub_head( '' );
		assert_true( is_array( $none ), 'control sub-process ran' );
		assert_same( 1, $none['description'], 'control: one description' );
		assert_same( 1, $none['jsonld'], 'control: one JSON-LD' );
		assert_same( 'Revesby Shop Details | Dough Boss', $none['title']['title'], 'control: companion title' );

		foreach ( array( 'WPSEO_VERSION', 'RANK_MATH_VERSION', 'AIOSEO_VERSION', 'SEOPRESS_VERSION' ) as $constant ) {
			$r = dbgr_lp_sub_head( $constant );
			assert_true( is_array( $r ), $constant . ': sub-process ran' );
			if ( ! is_array( $r ) ) {
				continue;
			}
			assert_same( 0, $r['description'], $constant . ': no meta description' );
			assert_same( 0, $r['og'], $constant . ': no Open Graph tags' );
			assert_same( 0, $r['twitter'], $constant . ': no Twitter tags' );
			assert_same( 0, $r['jsonld'], $constant . ': no JSON-LD by default' );
			assert_same( array( 'title' => 'Original', 'site' => 'Site' ), $r['title'], $constant . ': the title is untouched' );
			assert_same( array( 'max-image-preview' => 'large' ), $r['robots'], $constant . ': robots untouched' );
			assert_true( $r['body'], $constant . ': the page body still renders' );
		}

		$with = dbgr_lp_sub_head( 'WPSEO_VERSION', array( 'seo_jsonld_with_seo_plugin' => 1 ) );
		assert_true( is_array( $with ), 'opt-in sub-process ran' );
		if ( is_array( $with ) ) {
			assert_same( 1, $with['jsonld'], 'opt-in: the JSON-LD graph is printed' );
			assert_same( 0, $with['description'], 'opt-in: still no meta description' );
			assert_same( 0, $with['og'], 'opt-in: still no Open Graph tags' );
			assert_same( array( 'title' => 'Original', 'site' => 'Site' ), $with['title'], 'opt-in: title untouched' );
		}
	}
);

/* ------------------------------------------------------------------------------------------ */
/* Admin tab                                                                                     */
/* ------------------------------------------------------------------------------------------ */

db_test(
	'admin tab: lists each page with its title and description for an SEO plugin, the hidden blocks and reasons, and the create form with a nonce; managers only',
	function () {
		dbgr_landing_boot();
		dbgr_test_set_admin( true );
		DoughBoss_Growth_Landing::init();
		dbgr_landing_package( 301, 'Box One', '45', 10, 12 );
		do_action( 'doughboss_growth_admin_tabs' );

		dbgr_test_login( array( 'read' ) );
		ob_start();
		DoughBoss_Growth_Landing::render_admin_tab();
		assert_same( '', ob_get_clean(), 'a user without the capability sees nothing' );

		dbgr_test_login( array( 'manage_doughboss' ) );
		ob_start();
		DoughBoss_Growth_Landing::render_admin_tab();
		$html = ob_get_clean();
		assert_contains( 'name="action" value="doughboss_growth_create_pages"', $html, 'create form posts the right action' );
		assert_contains( 'name="_wpnonce"', $html, 'with a nonce' );
		assert_contains( '/catering/corporate/', $html, 'page path' );
		assert_contains( 'value="Corporate Catering Enquiries | Dough Boss"', $html, 'title for the SEO plugin (readonly input)' );
		assert_contains( 'readonly="readonly"', $html, 'read only' );
		assert_contains( 'Revesby Shop Details | Dough Boss', $html, 'shop title' );
		assert_contains( 'Not created yet', $html, 'state before the button is pressed' );
		assert_contains( 'Waiting for a confirmed, sourced claim in the ledger.', $html, 'hidden claim reason' );
		assert_contains( 'claim:catering-lead-time', $html, 'hidden block listed' );
		assert_contains( 'Marked noindex: no content of its own yet', $html, 'thin catering pages are flagged noindex' );
		assert_contains( 'The core catering shortcode is not available.', $html, 'core form status (no core shortcode in this fixture)' );
		assert_not_contains( '<script', $html, 'no script in the tab' );

		DoughBoss_Growth_Landing::create_pages();
		dbgr_test_login( array( 'manage_doughboss' ) );
		$_GET['dbgr_lp'] = 'catering-corporate.created,locations-revesby.slug_taken,bogus-key.created,catering-events.<script>';
		ob_start();
		DoughBoss_Growth_Landing::render_admin_tab();
		$html = ob_get_clean();
		assert_contains( 'Draft', $html, 'state after creation' );
		assert_contains( 'catering/corporate: Draft created.', $html, 'result notice' );
		assert_contains( 'locations/revesby: Not created: another page already uses this address.', $html, 'result notice for a refusal' );
		assert_not_contains( 'bogus-key', $html, 'an unknown key from the query string is ignored' );
		assert_not_contains( '<script>', $html, 'query input is never echoed raw' );
		assert_contains( 'post.php?post=', $html, 'edit link' );

		// Hostile definition problems are escaped.
		$dir = sys_get_temp_dir() . '/dbgr-landing-adm-' . bin2hex( random_bytes( 4 ) ) . '/';
		mkdir( $dir, 0777, true );
		$hostile_field = 'x"><img src=x onerror=alert(1)>.json';
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			// NTFS forbids the hostile filename; exercise the same escaped problem sink through an unknown field.
			file_put_contents( $dir . 'hostile.json', json_encode( array_merge( dbgr_lp_def(), array( $hostile_field => true ) ) ) );
		} else {
			// Preserve the original filename-derived negative control on filesystems that support it.
			file_put_contents( $dir . $hostile_field, '{ nope' );
		}
		DoughBoss_Growth_Landing::set_definition_dir_override( $dir );
		ob_start();
		DoughBoss_Growth_Landing::render_admin_tab();
		$html = ob_get_clean();
		assert_not_contains( '<img', $html, 'hostile definition input is escaped' );
		assert_contains( '&lt;img src=x onerror=alert(1)&gt;.json', $html, 'and shown as text' );
		assert_contains( 'Some page definitions are invalid and are left out', $html, 'problems are listed' );
		unset( $_GET['dbgr_lp'] );
		foreach ( glob( $dir . '*' ) as $f ) {
			unlink( $f );
		}
		rmdir( $dir );
		dbgr_landing_reset();
	}
);

db_test(
	'admin tab: registered under the companion shell, and the ledger tab lists the page blocks that are waiting for a claim',
	function () {
		dbgr_landing_boot( array() );
		dbgr_test_set_admin( true );
		DoughBoss_Growth_Landing::init();
		DoughBoss_Growth_Ledger::init();
		assert_true( shortcode_exists( 'doughboss_growth_landing' ), 'the empty placeholder shortcode is registered even with the flag off' );
		assert_false( has_action( 'wp_head', array( 'DoughBoss_Growth_Landing_SEO', 'print_head' ) ), 'no front-end hook with the flag off' );
		do_action( 'doughboss_growth_admin_tabs' );
		assert_true( has_action( 'doughboss_growth_admin_tabs', array( 'DoughBoss_Growth_Landing', 'register_tab' ) ) !== false, 'tab callback registered' );
		dbgr_test_login( array( 'manage_doughboss' ) );
		ob_start();
		DoughBoss_Growth_Admin::render_page();
		$page = ob_get_clean();
		assert_contains( '>Landing pages</a>', $page, 'the tab is in the shell navigation' );

		$blocks = apply_filters( 'doughboss_growth_ledger_blocks', array() );
		$mine   = array_values( array_filter( $blocks, function ( $b ) {
			return 'catering-corporate' === $b['page'];
		} ) );
		assert_same( 3, count( $mine ), 'three claim blocks declared for the corporate page' );
		assert_same( array( 'catering-service-area' ), $mine[0]['claims'], 'with the claim ids they need' );
		$hidden = DoughBoss_Growth_Ledger::hidden_blocks();
		assert_true( count( $hidden ) >= 9, 'all nine claim blocks (three claims on each of three catering pages) are listed as hidden while the claims are gaps' );
		assert_same( 'not an array', apply_filters( 'doughboss_growth_ledger_blocks', 'not an array' ), 'a non-array value is passed through unchanged' );
		dbgr_landing_reset();
	}
);

/* ------------------------------------------------------------------------------------------ */
/* Registry wiring                                                                               */
/* ------------------------------------------------------------------------------------------ */

db_test(
	'registry: with the flags on the real bootstrap loads the landing module and hooks the front end; with them off nothing is hooked',
	function () {
		dbgr_landing_reset();
		DoughBoss_Locations::$rows = array();
		update_option( 'doughboss_growth_settings', array( 'features' => array( 'landing_pages' => true, 'seo_head' => true ) ) );
		DoughBoss_Growth::init();
		assert_true( DoughBoss_Growth::health()['modules_active']['landing'], 'the landing module is active' );
		assert_true( DoughBoss_Growth::health()['modules_present']['landing'], 'and present on disk' );
		assert_true( shortcode_exists( 'doughboss_growth_landing' ), 'shortcode registered' );
		assert_same( 6, has_action( 'wp_head', array( 'DoughBoss_Growth_Landing_SEO', 'print_head' ) ), 'head hook' );
		assert_same( 30, has_filter( 'document_title_parts', array( 'DoughBoss_Growth_Landing_SEO', 'filter_title_parts' ) ), 'title hook' );
		assert_same( 20, has_action( 'wp_enqueue_scripts', array( 'DoughBoss_Growth_Landing', 'enqueue_assets' ) ), 'style hook' );

		dbgr_landing_reset();
		update_option( 'doughboss_growth_settings', array( 'features' => array() ) );
		DoughBoss_Growth::init();
		assert_false( has_action( 'wp_head', array( 'DoughBoss_Growth_Landing_SEO', 'print_head' ) ), 'flags off: no head hook' );
		assert_false( has_action( 'wp_enqueue_scripts', array( 'DoughBoss_Growth_Landing', 'enqueue_assets' ) ), 'flags off: no style hook' );
		dbgr_landing_reset();
	}
);

db_test(
	'assets: the stylesheet is enqueued only on a companion page that has something to show',
	function () {
		dbgr_landing_boot();
		DoughBoss_Growth_Landing::init();
		$map = dbgr_landing_make_pages();
		dbgr_landing_query( $map['locations-revesby'] );
		do_action( 'wp_enqueue_scripts' );
		assert_true( isset( $GLOBALS['dbgr_assets']['styles']['dbgr-landing'] ) && $GLOBALS['dbgr_assets']['styles']['dbgr-landing']['enqueued'], 'enqueued on a shop page' );
		assert_same( DOUGHBOSS_GROWTH_URL . 'public/css/dbgr-landing.css', $GLOBALS['dbgr_assets']['styles']['dbgr-landing']['src'], 'from the companion public folder' );
		assert_same( DOUGHBOSS_GROWTH_VERSION, $GLOBALS['dbgr_assets']['styles']['dbgr-landing']['ver'], 'versioned' );
		assert_true( is_file( DOUGHBOSS_GROWTH_DIR . 'public/css/dbgr-landing.css' ), 'and the file exists' );

		$GLOBALS['dbgr_assets']['styles'] = array();
		dbgr_landing_query( 11 );
		do_action( 'wp_enqueue_scripts' );
		assert_same( array(), $GLOBALS['dbgr_assets']['styles'], 'the hub page loads nothing of ours' );

		dbgr_landing_query( $map['catering-corporate'] );
		do_action( 'wp_enqueue_scripts' );
		assert_same( array(), $GLOBALS['dbgr_assets']['styles'], 'a catering page with nothing to show loads nothing' );
		dbgr_landing_reset();
	}
);

db_test(
	'static: no landing file writes a core table, option, role or capability, or makes a request',
	function () {
		$files = array(
			DOUGHBOSS_GROWTH_DIR . 'includes/landing/class-doughboss-growth-landing.php',
			DOUGHBOSS_GROWTH_DIR . 'includes/landing/class-doughboss-growth-landing-seo.php',
			DOUGHBOSS_GROWTH_DIR . 'includes/landing/class-doughboss-growth-landing-schema.php',
		);
		foreach ( $files as $file ) {
			$code = (string) file_get_contents( $file );
			assert_matches( '/^<\?php/', $code, basename( $file ) . ' is a PHP file' );
			assert_contains( "if ( ! defined( 'ABSPATH' ) )", $code, basename( $file ) . ' has the ABSPATH guard' );
			foreach ( array( 'wp_remote_', 'curl_', 'file_get_contents( \'http', '$wpdb', 'add_role', 'add_cap', 'register_post_type', 'delete_option', 'update_post_meta', 'wp_delete_post', 'update_user_meta' ) as $needle ) {
				assert_false( false !== strpos( $code, $needle ), basename( $file ) . ' has no ' . $needle );
			}
			assert_false( 1 === preg_match( '/\binnerHTML\b|\beval\s*\(|\bcreate_function\b|`/', $code ), basename( $file ) . ' has no dynamic-code sink' );
		}
		$main = (string) file_get_contents( $files[0] );
		preg_match_all( '/update_option\(\s*([^,]+),/', $main, $m );
		assert_same( array( 'DoughBoss_Growth_Activator::PAGES_OPTION' ), array_values( array_unique( array_map( 'trim', $m[1] ) ) ), 'the only option written is doughboss_growth_pages' );
		assert_same( 'doughboss_growth_pages', DoughBoss_Growth_Activator::PAGES_OPTION, 'which carries the companion prefix' );
		dbgr_landing_reset();
	}
);
