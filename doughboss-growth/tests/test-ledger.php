<?php
/**
 * Claims ledger tests (WP-02): oracle parity with the TypeScript, lint, failure paths, runtime guard, admin tab.
 *
 * @package DoughBoss_Growth
 */

DoughBoss_Growth::load_module( 'ledger' );

/**
 * Write a claims file in the temp dir and point the ledger at it.
 *
 * @param string $contents Raw file contents.
 * @return string Path.
 */
function dbgr_ledger_use_file( $contents ) {
	$path = tempnam( sys_get_temp_dir(), 'dbgr-claims-' );
	file_put_contents( $path, $contents );
	DoughBoss_Growth_Ledger::set_file_override( $path );
	return $path;
}

/**
 * Point the ledger at claims and return the path to remove later.
 *
 * @param array $claims Claims.
 * @return string
 */
function dbgr_ledger_use_claims( array $claims ) {
	return dbgr_ledger_use_file( wp_json_encode( array( 'version' => 1, 'claims' => $claims ) ) );
}

/**
 * Release the file override and delete the temp file.
 *
 * @param string $path Temp path.
 * @return void
 */
function dbgr_ledger_release( $path ) {
	DoughBoss_Growth_Ledger::set_file_override( null );
	if ( is_string( $path ) && is_file( $path ) ) {
		unlink( $path );
	}
}

/**
 * Compare the PHP port with one oracle case. Returns a list of differences (empty = identical).
 *
 * @param array $case Oracle case.
 * @return string[]
 */
function dbgr_ledger_oracle_diff( array $case ) {
	$diff   = array();
	$claims = $case['claims'];
	$expect = $case['expect'];

	$problems = DoughBoss_Growth_Ledger::validate( $claims );
	if ( $problems !== $expect['problems'] ) {
		$diff[] = 'problems differ: php ' . wp_json_encode( $problems ) . ' vs ts ' . wp_json_encode( $expect['problems'] );
	}
	if ( ( array() === $problems ) !== $expect['valid'] ) {
		$diff[] = 'validity differs';
	}
	$ids = array();
	foreach ( DoughBoss_Growth_Ledger::publishable_claims( $claims ) as $c ) {
		$ids[] = $c['id'];
	}
	if ( $ids !== $expect['publishable_ids'] ) {
		$diff[] = 'publishable ids differ: php ' . wp_json_encode( $ids ) . ' vs ts ' . wp_json_encode( $expect['publishable_ids'] );
	}
	foreach ( $expect['texts'] as $id => $text ) {
		$got = DoughBoss_Growth_Ledger::claim_text( $claims, (string) $id );
		if ( $got !== $text ) {
			$diff[] = 'claim text for ' . $id . ' differs';
		}
	}
	return $diff;
}

$dbgr_oracle_path = __DIR__ . '/fixtures/ledger-oracle.json';
$dbgr_oracle      = json_decode( (string) file_get_contents( $dbgr_oracle_path ), true );

db_test(
	'ledger oracle: the fixture loads and is not trivially small',
	function () use ( $dbgr_oracle ) {
		assert_true( is_array( $dbgr_oracle ) && isset( $dbgr_oracle['cases'], $dbgr_oracle['oracle_source']['sha256'] ), 'fixture has cases and the ledger.ts hash' );
		assert_true( count( $dbgr_oracle['cases'] ) >= 30, 'at least 30 oracle cases' );
		$names = array();
		foreach ( $dbgr_oracle['cases'] as $case ) {
			$names[] = $case['name'];
		}
		assert_same( count( $names ), count( array_unique( $names ) ), 'case names are unique' );
		// Every case the work package lists is present.
		foreach ( array( 'valid-mixed', 'duplicate-id', 'non-kebab-uppercase', 'empty-text', 'placeholder-confirm-bracket', 'placeholder-todo', 'placeholder-tbc', 'placeholder-lorem', 'placeholder-xxx', 'confirmed-without-source', 'empty-ref-confirmed' ) as $needed ) {
			assert_true( in_array( $needed, $names, true ), 'oracle case present: ' . $needed );
		}
	}
);

db_test(
	'ledger oracle: PHP returns identical results to the TypeScript for every case',
	function () use ( $dbgr_oracle ) {
		foreach ( $dbgr_oracle['cases'] as $case ) {
			$diff = dbgr_ledger_oracle_diff( $case );
			assert_same( array(), $diff, 'oracle case ' . $case['name'] );
		}
	}
);

db_test(
	'ledger oracle: negative control, a tampered expectation is detected',
	function () use ( $dbgr_oracle ) {
		$checked = 0;
		foreach ( $dbgr_oracle['cases'] as $case ) {
			if ( 'duplicate-id' !== $case['name'] && 'valid-mixed' !== $case['name'] ) {
				continue;
			}
			$tampered = $case;
			if ( array() === $tampered['expect']['problems'] ) {
				$tampered['expect']['problems'][] = 'baked-fresh: duplicate id';
				$tampered['expect']['valid']      = false;
			} else {
				$tampered['expect']['problems'] = array();
				$tampered['expect']['valid']    = true;
			}
			assert_true( array() !== dbgr_ledger_oracle_diff( $tampered ), 'tampered ' . $case['name'] . ' is reported as a difference' );
			++$checked;
		}
		assert_same( 2, $checked, 'both control cases were found' );
		// And a ledger the TS accepts must not be reported invalid by the PHP (no false positive).
		assert_same( array(), DoughBoss_Growth_Ledger::validate( array( array( 'id' => 'ok-id', 'text' => 'Fine', 'confirmed' => true, 'source' => array( 'kind' => 'owner-site', 'ref' => 'x', 'retrieved' => '2026-10-02' ) ) ) ), 'valid ledger has no problems' );
	}
);

db_test(
	'ledger oracle: the fixture is not stale (hash of web/src/content/ledger.ts)',
	function () use ( $dbgr_oracle ) {
		$ts = dirname( __DIR__ ) . '/../web/src/content/ledger.ts';
		if ( ! is_file( $ts ) ) {
			dbgr_test_skip( 'web/src/content/ledger.ts is not next to the plugin in this checkout; staleness not checked' );
			db_pass();
			return;
		}
		$bytes = str_replace( "\r\n", "\n", (string) file_get_contents( $ts ) );
		assert_same( $dbgr_oracle['oracle_source']['sha256'], hash( 'sha256', $bytes ), 'ledger-oracle.json was generated from the current ledger.ts (re-run web/scripts/wp-oracle/export-ledger-fixtures.ts)' );
	}
);

db_test(
	'ledger oracle: negative control, a changed ledger.ts would be detected by the hash comparison',
	function () use ( $dbgr_oracle ) {
		assert_true( hash( 'sha256', "export const x = 1;\n" ) !== $dbgr_oracle['oracle_source']['sha256'], 'a different source hashes differently' );
	}
);

db_test(
	'ledger: JavaScript trim parity and kebab regex anchoring',
	function () {
		assert_same( '', DoughBoss_Growth_Ledger::js_trim( "\u{00A0}\u{3000}\u{FEFF} \t\n" ), 'unicode spaces and BOM are trimmed like JavaScript' );
		assert_same( "\u{200B}", DoughBoss_Growth_Ledger::js_trim( "\u{200B}" ), 'zero-width space is not trimmed (as in JavaScript)' );
		assert_same( 'a b', DoughBoss_Growth_Ledger::js_trim( "  a b\n" ), 'inner text kept' );
		$problems = DoughBoss_Growth_Ledger::validate( array( array( 'id' => "baked\n", 'text' => 'Text', 'confirmed' => false ) ) );
		assert_same( 1, count( $problems ), 'an id with a trailing newline is not kebab-case' );
	}
);

db_test(
	'ledger validate: PHP-only type checks fail closed on malformed JSON shapes',
	function () {
		$bad = array(
			'a string, not an object'           => 'oops',
			'no id'                             => array( 'text' => 'x', 'confirmed' => false ),
			'numeric id'                        => array( 'id' => 5, 'text' => 'x', 'confirmed' => false ),
			'text is an array'                  => array( 'id' => 'a-b', 'text' => array( 'x' ), 'confirmed' => false ),
			'confirmed is a string'             => array( 'id' => 'a-b', 'text' => 'x', 'confirmed' => 'yes' ),
			'confirmed missing'                 => array( 'id' => 'a-b', 'text' => 'x' ),
			'source is a string'                => array( 'id' => 'a-b', 'text' => 'x', 'confirmed' => true, 'source' => 'site' ),
			'source ref missing'                => array( 'id' => 'a-b', 'text' => 'x', 'confirmed' => true, 'source' => array( 'kind' => 'owner-site' ) ),
			'source kind unknown'               => array( 'id' => 'a-b', 'text' => 'x', 'confirmed' => true, 'source' => array( 'kind' => 'rumour', 'ref' => 'r' ) ),
			'source kind missing'               => array( 'id' => 'a-b', 'text' => 'x', 'confirmed' => true, 'source' => array( 'ref' => 'r' ) ),
		);
		foreach ( $bad as $label => $claim ) {
			assert_true( array() !== DoughBoss_Growth_Ledger::validate( array( $claim ) ), 'rejected: ' . $label );
		}
		assert_true( array() !== DoughBoss_Growth_Ledger::validate( 'not a list' ), 'a non-list ledger is rejected' );
		$ok = array( 'id' => 'a-b', 'text' => 'x', 'confirmed' => true, 'source' => array( 'kind' => 'core-data', 'ref' => 'DoughBoss_Locations::get()' ) );
		assert_same( array(), DoughBoss_Growth_Ledger::validate( array( $ok ) ), 'the core-data kind is accepted' );
	}
);

db_test(
	'ledger lint: always-rejected words, any case',
	function () {
		$cases = array(
			'Minis'                       => 'product_name',
			'MINIS are coming'            => 'product_name',
			'our minis'                   => 'product_name',
			'mInIs'                       => 'product_name',
			"Mi\u{200B}nis"               => 'product_name',
			"Mi\u{00AD}nis"               => 'product_name',
			"\u{FF2D}\u{FF49}\u{FF4E}\u{FF49}\u{FF53}" => 'product_name',
			'Halal certified'             => 'halal',
			'HALAL'                       => 'halal',
			'100% vegan friendly'         => 'vegan',
			'Gluten free base'            => 'gluten',
			'gluten-free'                 => 'gluten',
			'Nut-free kitchen'            => 'nut_free',
			'nut free'                    => 'nut_free',
			'Fully certified'             => 'certified',
			'The best in town'            => 'best',
			'BEST bakery'                 => 'best',
			'Voted #1'                    => 'number_one',
			'# 1 choice'                  => 'number_one',
			'Number one in Revesby'       => 'number_one',
		);
		foreach ( $cases as $text => $code ) {
			assert_true( in_array( $code, DoughBoss_Growth_Ledger::lint_public( $text, null ), true ), 'rejects ' . $code . ' in ' . wp_json_encode( $text ) . ' (no source)' );
			assert_true( in_array( $code, DoughBoss_Growth_Ledger::lint_public( $text, 'owner-confirmed' ), true ), 'still rejects ' . $code . ' for owner-confirmed' );
			assert_true( in_array( $code, DoughBoss_Growth_Ledger::lint_public( $text, 'core-data' ), true ), 'still rejects ' . $code . ' for core-data' );
		}
	}
);

db_test(
	'ledger lint: digits, currency and percent are allowed only for owner-confirmed and core-data',
	function () {
		$cases = array(
			'Open 7 days'       => 'digit',
			'From $5'           => 'currency',
			'Only 10% off'      => 'percent',
			"\u{FF11}\u{FF10} items"      => 'digit',
			"\u{0663} items"    => 'digit',
			'Price in €'        => 'currency',
			'Save 5 percent %'  => 'digit',
		);
		foreach ( $cases as $text => $code ) {
			foreach ( array( null, 'owner-site', 'public-web', 'rumour', '' ) as $kind ) {
				assert_true( array() !== DoughBoss_Growth_Ledger::lint_public( $text, $kind ), 'rejects ' . wp_json_encode( $text ) . ' for source ' . wp_json_encode( $kind ) );
			}
			assert_same( array(), array_diff( DoughBoss_Growth_Ledger::lint_public( $text, 'owner-confirmed' ), array( 'digit', 'currency', 'percent' ) ), 'owner-confirmed: only number codes ' . $text );
			assert_same( array(), DoughBoss_Growth_Ledger::lint_public( $text, 'owner-confirmed' ), 'owner-confirmed may carry ' . $code );
			assert_same( array(), DoughBoss_Growth_Ledger::lint_public( $text, 'core-data' ), 'core-data may carry ' . $code );
		}
		assert_true( in_array( 'currency', DoughBoss_Growth_Ledger::lint_public( 'From $5', null ), true ), 'dollar sign reported as currency' );
		assert_true( in_array( 'percent', DoughBoss_Growth_Ledger::lint_public( '10%', null ), true ), 'percent reported' );
	}
);

db_test(
	'ledger lint: neutral copy passes, bad input fails closed',
	function () {
		assert_same( array(), DoughBoss_Growth_Ledger::lint_public( DoughBoss_Growth_Settings::DEFAULT_COMING_SOON_HEADLINE, null ), 'default teaser headline is clean' );
		assert_same( array(), DoughBoss_Growth_Ledger::lint_public( DoughBoss_Growth_Settings::DEFAULT_COMING_SOON_BODY, null ), 'default teaser body is clean' );
		assert_same( array(), DoughBoss_Growth_Ledger::lint_public( 'Freshly baked in Revesby', null ), 'ordinary neutral copy passes' );
		assert_same( array( 'best' ), DoughBoss_Growth_Ledger::lint_public( 'Bestow a smile', null ), '"best" at a word start is flagged (fail closed)' );
		assert_same( array( 'encoding' ), DoughBoss_Growth_Ledger::lint_public( "bad \xC3\x28 bytes", null ), 'invalid UTF-8 is a violation' );
		assert_same( array( 'encoding' ), DoughBoss_Growth_Ledger::lint_public( null, null ), 'null is a violation' );
		assert_same( array( 'encoding' ), DoughBoss_Growth_Ledger::lint_public( array( 'Minis' ), null ), 'an array is a violation' );
		assert_true( array() !== DoughBoss_Growth_Ledger::lint_public( 'administration', null ), 'documented false positive: "minis" inside a longer word is rejected, matching the settings sanitiser' );
		assert_true( array() === DoughBoss_Growth_Ledger::lint_public( 'Alphabet and tested', null ), 'words with "best" inside (not at a word start) are not flagged' );
	}
);

db_test(
	'ledger shipped claims.json: valid, no confirmed fact, no banned word anywhere',
	function () {
		$path = dirname( __DIR__ ) . '/content/claims.json';
		assert_true( is_file( $path ), 'content/claims.json ships' );
		$raw = (string) file_get_contents( $path );
		$ok  = DoughBoss_Growth_Ledger::validate();
		assert_same( array(), $ok, 'the shipped ledger is valid' );
		assert_true( DoughBoss_Growth_Ledger::is_valid(), 'is_valid()' );
		$loaded = DoughBoss_Growth_Ledger::load();
		assert_true( count( $loaded['claims'] ) >= 5, 'it lists the owner gaps' );
		foreach ( $loaded['claims'] as $claim ) {
			assert_same( false, $claim['confirmed'], $claim['id'] . ' is a gap (confirmed false)' );
			assert_true( ! isset( $claim['source'] ), $claim['id'] . ' has no source' );
			assert_true( isset( $claim['note'] ) && '' !== trim( $claim['note'] ), $claim['id'] . ' says what is needed' );
			assert_same( array(), DoughBoss_Growth_Ledger::lint_public( $claim['text'], null ), $claim['id'] . ' wording is lint-clean' );
			assert_same( 0, preg_match( '/minis/i', $claim['id'] . ' ' . $claim['text'] . ' ' . $claim['note'] ), $claim['id'] . ' never names the product' );
		}
		assert_same( 0, preg_match( '/minis/i', $raw ), 'the word Minis appears nowhere in claims.json' );
		assert_same( 0, preg_match( '/[$%]/', $raw ), 'no dollar or percent in claims.json' );
		assert_same( array(), DoughBoss_Growth_Ledger::publishable(), 'nothing is publishable from the shipped ledger' );
		assert_same( null, DoughBoss_Growth_Ledger::text( 'catering-lead-time' ), 'a gap renders nothing' );
		assert_same( null, DoughBoss_Growth_Ledger::text( 'no-such-claim' ), 'an unknown id renders nothing' );
	}
);

db_test(
	'ledger load: every failure leaves the ledger invalid and shows nothing',
	function () {
		$good_claim = array( 'id' => 'ok-claim', 'text' => 'Plain words', 'confirmed' => true, 'source' => array( 'kind' => 'owner-confirmed', 'ref' => 'chat', 'confirmedOn' => '2026-10-02' ) );
		$files      = array(
			'bad json'              => '{ not json',
			'empty file'            => '',
			'json scalar'           => '12',
			'no claims key'         => '{"version":1}',
			'claims is an object'   => '{"claims":{"a":1}}',
			'claims is not a list'  => '{"claims":{"0":{"id":"a"},"2":{"id":"b"}}}',
			'invalid claim inside'  => wp_json_encode( array( 'claims' => array( $good_claim, array( 'id' => 'Bad Id', 'text' => 'x', 'confirmed' => false ) ) ) ),
		);
		foreach ( $files as $label => $contents ) {
			$path = dbgr_ledger_use_file( $contents );
			assert_false( DoughBoss_Growth_Ledger::is_valid(), 'invalid: ' . $label );
			assert_same( array(), DoughBoss_Growth_Ledger::publishable(), 'nothing publishable: ' . $label );
			assert_same( null, DoughBoss_Growth_Ledger::text( 'ok-claim' ), 'no text even for a good claim: ' . $label );
			assert_true( array() !== DoughBoss_Growth_Ledger::validate(), 'a problem is reported: ' . $label );
			dbgr_ledger_release( $path );
		}
		DoughBoss_Growth_Ledger::set_file_override( sys_get_temp_dir() . '/dbgr-does-not-exist-' . getmypid() . '.json' );
		assert_false( DoughBoss_Growth_Ledger::is_valid(), 'a missing file is invalid' );
		assert_same( 'unreadable', DoughBoss_Growth_Ledger::load()['load_error'], 'missing file reported as unreadable' );
		DoughBoss_Growth_Ledger::set_file_override( null );

		$big  = dbgr_ledger_use_file( '{"claims":[],"pad":"' . str_repeat( 'a', DoughBoss_Growth_Ledger::MAX_FILE_BYTES + 10 ) . '"}' );
		assert_false( DoughBoss_Growth_Ledger::is_valid(), 'an oversized file is invalid' );
		assert_same( 'too_large', DoughBoss_Growth_Ledger::load()['load_error'], 'oversized reported' );
		dbgr_ledger_release( $big );

		$path = dbgr_ledger_use_claims( array( $good_claim ) );
		assert_true( DoughBoss_Growth_Ledger::is_valid(), 'control: a good file is valid' );
		dbgr_ledger_release( $path );
	}
);

db_test(
	'ledger text: confirmed, sourced and lint-clean claims render; everything else does not',
	function () {
		$src = function ( $kind ) {
			return array( 'kind' => $kind, 'ref' => 'where', 'retrieved' => '2026-10-02', 'confirmedOn' => '2026-10-02' );
		};
		$path = dbgr_ledger_use_claims(
			array(
				array( 'id' => 'shown-one', 'text' => 'Baked fresh each morning', 'confirmed' => true, 'source' => $src( 'owner-site' ) ),
				array( 'id' => 'unconfirmed', 'text' => 'Baked fresh each morning', 'confirmed' => false, 'source' => $src( 'owner-site' ) ),
				array( 'id' => 'owner-number', 'text' => 'Open 7 days', 'confirmed' => true, 'source' => $src( 'owner-confirmed' ) ),
				array( 'id' => 'web-number', 'text' => 'Open 7 days', 'confirmed' => true, 'source' => $src( 'public-web' ) ),
				array( 'id' => 'owner-halal', 'text' => 'Halal certified', 'confirmed' => true, 'source' => $src( 'owner-confirmed' ) ),
				array( 'id' => 'owner-product', 'text' => 'Try the Minis', 'confirmed' => true, 'source' => $src( 'owner-confirmed' ) ),
			)
		);
		assert_same( 'Baked fresh each morning', DoughBoss_Growth_Ledger::text( 'shown-one' ), 'confirmed, sourced, clean text renders' );
		assert_same( null, DoughBoss_Growth_Ledger::text( 'unconfirmed' ), 'unconfirmed claim with a source does not render' );
		assert_same( 'Open 7 days', DoughBoss_Growth_Ledger::text( 'owner-number' ), 'owner-confirmed may carry digits' );
		assert_same( null, DoughBoss_Growth_Ledger::text( 'web-number' ), 'public-web may not carry digits' );
		assert_same( null, DoughBoss_Growth_Ledger::text( 'owner-halal' ), 'dietary wording is blocked even when owner-confirmed' );
		assert_same( null, DoughBoss_Growth_Ledger::text( 'owner-product' ), 'the product word is blocked even when owner-confirmed' );
		assert_same( null, DoughBoss_Growth_Ledger::text( 7 ), 'a non-string id renders nothing' );
		assert_same( array( 'shown-one', 'owner-number' ), array_keys( DoughBoss_Growth_Ledger::publishable() ), 'publishable() lists only the safe claims, in order' );
		$loaded = DoughBoss_Growth_Ledger::load();
		$status = array();
		foreach ( $loaded['claims'] as $claim ) {
			$status[ $claim['id'] ] = DoughBoss_Growth_Ledger::status( $claim );
		}
		assert_same( 'published', $status['shown-one'], 'status published' );
		assert_same( 'gap', $status['unconfirmed'], 'status gap' );
		assert_same( 'blocked_lint', $status['web-number'], 'status blocked_lint' );
		assert_same( 'blocked_lint', $status['owner-product'], 'status blocked_lint for the product word' );
		dbgr_ledger_release( $path );
	}
);

db_test(
	'ledger runtime: an invalid ledger switches off landing_pages, seo_head and coming_soon only',
	function () {
		update_option(
			DoughBoss_Growth_Settings::OPTION,
			array(
				'features' => array(
					'landing_pages' => true,
					'seo_head'      => true,
					'coming_soon'   => true,
					'consent_banner' => true,
				),
			)
		);
		DoughBoss_Growth_Ledger::init();

		$path = dbgr_ledger_use_file( '{ broken' );
		assert_false( DoughBoss_Growth_Settings::enabled( 'landing_pages' ), 'landing_pages off while invalid' );
		assert_false( DoughBoss_Growth_Settings::enabled( 'seo_head' ), 'seo_head off while invalid' );
		assert_false( DoughBoss_Growth_Settings::enabled( 'coming_soon' ), 'coming_soon off while invalid' );
		assert_true( DoughBoss_Growth_Settings::enabled( 'consent_banner' ), 'unrelated feature unaffected' );
		dbgr_ledger_release( $path );

		$path = dbgr_ledger_use_claims( array() );
		assert_true( DoughBoss_Growth_Settings::enabled( 'landing_pages' ), 'control: a valid ledger leaves landing_pages on' );
		assert_true( DoughBoss_Growth_Settings::enabled( 'coming_soon' ), 'control: and coming_soon' );
		dbgr_ledger_release( $path );

		$path = dbgr_ledger_use_file( '{ broken' );
		assert_same( false, DoughBoss_Growth_Ledger::guard_features( true, 'landing_pages' ), 'the guard turns a guarded feature off while invalid' );
		assert_same( true, DoughBoss_Growth_Ledger::guard_features( true, 'waitlist' ), 'the guard leaves an unguarded feature alone' );
		dbgr_ledger_release( $path );
		assert_same( false, DoughBoss_Growth_Ledger::guard_features( false, 'landing_pages' ), 'the guard never turns a feature on' );
		assert_same( null, DoughBoss_Growth_Ledger::guard_features( null, 'landing_pages' ), 'a non-true value is passed through untouched' );
	}
);

db_test(
	'ledger runtime: the guard fails closed on an unexpected error and is registered once',
	function () {
		DoughBoss_Growth_Ledger::init();
		DoughBoss_Growth_Ledger::init();
		assert_same( 1, dbgr_test_hook_count( 'doughboss_growth_feature_enabled' ), 'the feature guard is registered exactly once' );
		// A directory in place of the file: unreadable, so invalid, not an exception.
		DoughBoss_Growth_Ledger::set_file_override( sys_get_temp_dir() );
		assert_false( DoughBoss_Growth_Ledger::is_valid(), 'a directory path is invalid' );
		DoughBoss_Growth_Ledger::set_file_override( null );
	}
);

db_test(
	'ledger runtime: the module registry runs init() and the real feature stays off for an invalid ledger',
	function () {
		update_option( DoughBoss_Growth_Settings::OPTION, array( 'features' => array( 'coming_soon' => true ) ) );
		$path = dbgr_ledger_use_file( 'nonsense' );
		DoughBoss_Growth::init();
		assert_true( DoughBoss_Growth::health()['modules_active']['ledger'] ?? false, 'the ledger module initialised (coming_soon flag is on)' );
		assert_false( DoughBoss_Growth_Settings::enabled( 'coming_soon' ), 'coming_soon is inert with an invalid ledger' );
		dbgr_ledger_release( $path );
	}
);

db_test(
	'ledger admin: the Claims tab lists every claim, status and source, read only, escaped',
	function () {
		$payload = '<script>alert(1)</script>';
		$path    = dbgr_ledger_use_claims(
			array(
				array( 'id' => 'shown-one', 'text' => 'Baked fresh each morning', 'confirmed' => true, 'source' => array( 'kind' => 'owner-site', 'ref' => 'site/page ' . $payload, 'retrieved' => '2026-10-02' ) ),
				array( 'id' => 'a-gap', 'text' => 'Delivery wording', 'confirmed' => false, 'note' => 'needs Elie ' . $payload ),
			)
		);
		dbgr_test_set_admin( true );
		dbgr_test_login( array( 'manage_options' ) );
		DoughBoss_Growth_Ledger::init();
		add_filter(
			'doughboss_growth_ledger_blocks',
			function () {
				return array(
					array( 'page' => 'Catering', 'block' => 'Lead time', 'claims' => array( 'a-gap' ) ),
					array( 'page' => 'Home', 'block' => 'Story', 'claims' => array( 'shown-one' ) ),
					array( 'page' => 'Catering', 'block' => 'Area', 'claims' => array( 'not-in-ledger', 'shown-one' ) ),
					'garbage',
				);
			}
		);
		do_action( 'doughboss_growth_admin_tabs' );
		ob_start();
		DoughBoss_Growth_Ledger::render_admin_tab();
		$html = ob_get_clean();
		assert_contains( 'shown-one', $html, 'lists the published claim' );
		assert_contains( 'Published', $html, 'shows its status' );
		assert_contains( 'a-gap', $html, 'lists the gap' );
		assert_contains( 'Gap: not confirmed', $html, 'shows the gap status' );
		assert_contains( 'owner-site | site/page', $html, 'shows the source' );
		assert_not_contains( $payload, $html, 'markup in data is never printed raw' );
		assert_contains( '&lt;script&gt;', $html, 'it is escaped instead' );
		assert_not_contains( '<form', $html, 'read only: no form' );
		assert_not_contains( '<input', $html, 'read only: no input' );
		assert_contains( 'Catering, block &quot;Lead time&quot;: waiting for a-gap', $html, 'a hidden block is listed with the claim it waits for' );
		assert_contains( 'Catering, block &quot;Area&quot;: waiting for not-in-ledger', $html, 'an unknown claim id hides the block' );
		assert_not_contains( 'Home, block', $html, 'a block whose claims are all publishable is not listed as hidden' );
		assert_contains( 'Ledger status: valid', $html, 'valid status shown' );
		dbgr_ledger_release( $path );
	}
);

db_test(
	'ledger admin: tab is registered on the admin tabs action; invalid ledger shows problems and a notice',
	function () {
		$path = dbgr_ledger_use_claims( array( array( 'id' => 'Bad Id', 'text' => 'x', 'confirmed' => true ) ) );
		dbgr_test_set_admin( true );
		dbgr_test_login( array( 'manage_options' ) );
		DoughBoss_Growth_Ledger::init();
		ob_start();
		DoughBoss_Growth_Admin::render_page();
		$page = ob_get_clean();
		assert_contains( 'Claims', $page, 'the Claims tab link exists' );
		$_GET['tab'] = 'claims';
		ob_start();
		DoughBoss_Growth_Admin::render_page();
		$tab = ob_get_clean();
		assert_contains( 'Ledger status: invalid', $tab, 'invalid status is stated' );
		assert_contains( 'Bad Id: id must be kebab-case', $tab, 'the problem is listed' );
		ob_start();
		do_action( 'admin_notices' );
		$notice = ob_get_clean();
		assert_contains( 'claims ledger is invalid', $notice, 'manager sees the invalid-ledger notice' );

		dbgr_test_login( array() );
		ob_start();
		do_action( 'admin_notices' );
		$notice = ob_get_clean();
		assert_not_contains( 'claims ledger is invalid', $notice, 'a user without the capability sees no notice' );
		ob_start();
		DoughBoss_Growth_Ledger::render_admin_tab();
		$denied = ob_get_clean();
		assert_same( '', $denied, 'the tab renders nothing without the capability' );
		dbgr_ledger_release( $path );

		$path = dbgr_ledger_use_claims( array() );
		dbgr_test_login( array( 'manage_options' ) );
		ob_start();
		do_action( 'admin_notices' );
		$notice = ob_get_clean();
		assert_not_contains( 'claims ledger is invalid', $notice, 'no notice for a valid ledger' );
		dbgr_ledger_release( $path );
	}
);

db_test(
	'ledger: the module file is side-effect free, ABSPATH guarded and PHP 7.4 clean',
	function () {
		$file = dirname( __DIR__ ) . '/includes/ledger/class-doughboss-growth-ledger.php';
		$code = (string) file_get_contents( $file );
		assert_contains( "defined( 'ABSPATH' )", $code, 'ABSPATH guard' );
		assert_contains( 'final class DoughBoss_Growth_Ledger', $code, 'final class' );
		assert_same( 0, preg_match( '/\bwp_remote_|\bfile_put_contents\(|update_option\(|add_option\(|set_transient\(|\$wpdb/', $code ), 'no network, file, option, transient or database write' );
		assert_same( 0, preg_match( '/\bmatch\s*\(|\?->|\bfn\s*\(|str_contains|str_starts_with|str_ends_with/', $code ), 'no PHP 8 only syntax' );
		$sub = dbgr_test_subprocess( 'echo class_exists( "DoughBoss_Growth_Ledger", false ) ? "pre" : "none"; require ' . var_export( $file, true ) . '; echo class_exists( "DoughBoss_Growth_Ledger", false ) ? "-loaded" : "-missing"; echo count( $GLOBALS["dbgr_hooks"] ) === count( $GLOBALS["dbgr_hooks_baseline"] ) ? "-nohooks" : "-hooks";' );
		assert_same( 'none-loaded-nohooks', $sub['out'], 'including the file registers no hook' );
	}
);
