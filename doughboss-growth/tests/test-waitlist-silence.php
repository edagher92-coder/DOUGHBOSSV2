<?php
/**
 * VIP waitlist silence-rule and robustness tests.
 *
 * House rule: a failed read or write must be visible to the owner or the requester, never rendered as empty, "nothing
 * found" or success; and a person must always be able to leave the list, be exported and be erased.
 *
 *   F2  The privacy exporter and eraser say so when the list cannot be read (or storage is not ready), and note it.
 *   F4  Every failure branch of signup() is told apart (storage, mail), noted in the failure log without personal data,
 *       shown on the VIP waitlist tab, and still looks identical to the visitor.
 *   F5  The opt-out token is verified FIRST and only invalid attempts count against the per-address bucket, so a shared
 *       address cannot lock real people out; a limiter storage error never blocks a valid opt-out, and never lets an
 *       invalid token through.
 *   F11 The retention purge reports what failed (never an error collapsed to 0), leaves a row whose suppression or delete
 *       failed for the next run, and the tab says the last clean-up failed until a clean one runs.
 *   F10 The staff CSV streams page by page, is byte-identical for the same data, and a failed page ends the file with an
 *       error line instead of a short file that looks complete.
 *
 * Self-contained (own helpers, own prefix dbgr_wls_), so `php tests/run.php waitlist-silence` runs on its own. Note that
 * this file sorts BEFORE test-waitlist.php, so it must not rely on that file's helpers.
 *
 * @package DoughBoss_Growth
 */

/** Placeholder sender. Not a real company; the plugin hard-codes no name at all. */
const DBGR_WLS_SENDER = 'Example Trading Pty Ltd';

/** The one address every visitor shares in the shared-proxy scenario. */
const DBGR_WLS_SHARED_IP = '203.0.113.50';

/**
 * Boot the module: SQLite with the real tables, settings saved as the owner would.
 *
 * @param array $extra    Extra settings merged over the defaults.
 * @param array $features Feature overrides (default: waitlist on).
 * @return void
 */
function dbgr_wls_boot( array $extra = array(), array $features = array() ) {
	$GLOBALS['wpdb']->use_sqlite();
	DoughBoss_Growth::load_module( 'ledger' );
	DoughBoss_Growth::load_module( 'waitlist' );
	foreach ( DoughBoss_Growth_Waitlist::schema() as $sql ) {
		$GLOBALS['wpdb']->create_table_from_mysql( $sql );
	}
	foreach ( DoughBoss_Growth_Rate_Limit::schema() as $sql ) {
		$GLOBALS['wpdb']->create_table_from_mysql( $sql );
	}
	foreach ( DoughBoss_Growth_Outbox::schema() as $sql ) {
		$GLOBALS['wpdb']->create_table_from_mysql( $sql );
	}
	update_option( DoughBoss_Growth_Activator::DB_VERSION_OPTION, DOUGHBOSS_GROWTH_DB_VERSION );
	update_option(
		DoughBoss_Growth_Settings::OPTION,
		array_merge(
			array(
				'features'           => array_merge( array( 'waitlist' => true ), $features ),
				'sender_legal_name'  => DBGR_WLS_SENDER,
				'privacy_policy_url' => 'https://example.com.au/privacy/',
			),
			$extra
		)
	);
	DoughBoss_Locations::$throw = false;
	DoughBoss_Locations::$rows  = array();
	DoughBoss_Growth_Waitlist::reset_state();
	DoughBoss_Growth_Waitlist_Rest::register_routes();
	$_SERVER['REMOTE_ADDR'] = DBGR_WLS_SHARED_IP;
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$GLOBALS['dbgr_wls_ip_n']  = 0;
}

/**
 * SQL literal for a seed value.
 *
 * @param mixed $value Value.
 * @return string
 */
function dbgr_wls_q( $value ) {
	if ( null === $value ) {
		return 'NULL';
	}
	return is_int( $value ) ? (string) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
}

/**
 * Insert one waitlist row straight into the table.
 *
 * @param int         $n       Number used to make the address (person{n}@example.com).
 * @param string      $status  pending, confirmed or unsubscribed.
 * @param string|null $created Created time (UTC datetime); default one day before the test clock.
 * @param string|null $stamp   confirmed or unsubscribed time.
 * @return array { id, email, hash, token } token is the opt-out token a mail would carry.
 */
function dbgr_wls_seed_person( $n, $status = 'confirmed', $created = null, $stamp = null ) {
	$email   = 'person' . (int) $n . '@example.com';
	$hash    = hash( 'sha256', $email );
	$created = ( null === $created ) ? gmdate( 'Y-m-d H:i:s', DoughBoss_Growth::now() - DAY_IN_SECONDS ) : $created;
	$stamp   = ( null === $stamp ) ? $created : $stamp;
	$conf    = ( 'confirmed' === $status ) ? dbgr_wls_q( $stamp ) : 'NULL';
	$unsub   = ( 'unsubscribed' === $status ) ? dbgr_wls_q( $stamp ) : 'NULL';
	$token   = ( 'pending' === $status ) ? dbgr_wls_q( hash( 'sha256', 'pending-token-' . $n ) ) : 'NULL';
	$GLOBALS['wpdb']->sqlite_raw( 'INSERT INTO wp_doughboss_growth_waitlist (email, email_hash, consent_marketing, consent_text_version, consent_text_hash, consent_at_utc, consent_source_path, status, confirm_token_hash, confirmed_at_utc, unsubscribed_at_utc, created_at, updated_at) VALUES (' . implode( ', ', array( dbgr_wls_q( $email ), dbgr_wls_q( $hash ), '1', "'wl-test'", dbgr_wls_q( str_repeat( 'f', 64 ) ), dbgr_wls_q( $created ), "'/'", dbgr_wls_q( $status ), $token, $conf, $unsub, dbgr_wls_q( $created ), dbgr_wls_q( $created ) ) ) . ')' );
	$id = (int) $GLOBALS['wpdb']->sqlite_raw( 'SELECT last_insert_rowid() AS id' )[0]['id'];
	return array(
		'id'    => $id,
		'email' => $email,
		'hash'  => $hash,
		'token' => DoughBoss_Growth_Waitlist::unsubscribe_token( $id, $hash ),
	);
}

/**
 * A row's status, or null when the row does not exist.
 *
 * @param int $id Row id.
 * @return string|null
 */
function dbgr_wls_status( $id ) {
	$rows = $GLOBALS['wpdb']->sqlite_raw( 'SELECT status FROM wp_doughboss_growth_waitlist WHERE id = ' . (int) $id );
	return isset( $rows[0]['status'] ) ? $rows[0]['status'] : null;
}

/**
 * Number of rows on the suppression list.
 *
 * @return int
 */
function dbgr_wls_suppressed_count() {
	return (int) $GLOBALS['wpdb']->sqlite_raw( 'SELECT COUNT(*) AS n FROM wp_doughboss_growth_suppression' )[0]['n'];
}

/**
 * Hits held by the link-action bucket for the current address (0 when there is no bucket).
 *
 * @return int
 */
function dbgr_wls_action_hits() {
	$rows = $GLOBALS['wpdb']->sqlite_raw( "SELECT hits FROM wp_doughboss_growth_rate WHERE bucket_key LIKE 'wl:act:%'" );
	return isset( $rows[0]['hits'] ) ? (int) $rows[0]['hits'] : 0;
}

/**
 * Recorded failure codes, newest first.
 *
 * @return array
 */
function dbgr_wls_codes() {
	return array_column( DoughBoss_Growth_Failures::all(), 'code' );
}

/**
 * One recorded failure, or null.
 *
 * @param string $code Failure code.
 * @return array|null
 */
function dbgr_wls_failure( $code ) {
	foreach ( DoughBoss_Growth_Failures::all() as $record ) {
		if ( $record['code'] === $code ) {
			return $record;
		}
	}
	return null;
}

/**
 * One context value of a recorded failure ('missing' when the failure or the key is absent).
 *
 * @param string $code Failure code.
 * @param string $key  Context key.
 * @return string
 */
function dbgr_wls_ctx( $code, $key ) {
	$record = dbgr_wls_failure( $code );
	return ( null !== $record && isset( $record['context'][ $key ] ) ) ? $record['context'][ $key ] : 'missing';
}

/**
 * POST /waitlist/unsubscribe.
 *
 * @param mixed $id    Row id.
 * @param mixed $token Token.
 * @return WP_REST_Response
 */
function dbgr_wls_rest_optout( $id, $token ) {
	return dbgr_test_rest_dispatch( 'POST', '/doughboss-growth/v1/waitlist/unsubscribe', array( 'id' => $id, 'token' => $token ) );
}

/**
 * Run the email-link page and return what it printed and the status it sent.
 *
 * @param string $action   confirm or unsubscribe.
 * @param mixed  $id       Row id.
 * @param mixed  $token    Token.
 * @param bool   $one_click True: the arguments are in the URL and the body is the mail client's one-click body. False: a button POST.
 * @return array { status: int, html: string }
 */
function dbgr_wls_link( $action, $id, $token, $one_click = true ) {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	if ( $one_click ) {
		$_GET  = array( 'dbgr_wl' => $action, 'i' => (string) $id, 't' => (string) $token );
		$_POST = array( 'List-Unsubscribe' => 'One-Click' );
	} else {
		$_GET  = array();
		$_POST = array( 'dbgr_wl' => $action, 'i' => (string) $id, 't' => (string) $token );
	}
	$GLOBALS['dbgr_headers'] = array();
	ob_start();
	DoughBoss_Growth_Waitlist::maybe_handle_link();
	$html   = (string) ob_get_clean();
	$status = 0;
	foreach ( $GLOBALS['dbgr_headers'] as $header ) {
		if ( 1 === preg_match( '/^HTTP status (\d+)$/', $header, $m ) ) {
			$status = (int) $m[1];
		}
	}
	$_GET  = array();
	$_POST = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	return array(
		'status' => $status,
		'html'   => $html,
	);
}

/**
 * Sign up through the REST route with a valid token, from a fresh visitor address each time.
 *
 * @param string $email Address.
 * @return WP_REST_Response
 */
function dbgr_wls_signup( $email ) {
	$GLOBALS['dbgr_wls_ip_n']++;
	$_SERVER['REMOTE_ADDR'] = '198.51.' . intdiv( $GLOBALS['dbgr_wls_ip_n'], 250 ) . '.' . ( 1 + ( $GLOBALS['dbgr_wls_ip_n'] % 250 ) );
	$token                  = DoughBoss_Growth_Waitlist::issue_form_token();
	dbgr_test_advance( 4 );
	return dbgr_test_rest_dispatch(
		'POST',
		'/doughboss-growth/v1/waitlist',
		array(
			'email'           => $email,
			'first_name'      => '',
			'mobile'          => '',
			'store'           => '',
			'consent'         => 1,
			'consent_version' => DoughBoss_Growth_Waitlist::consent_version(),
			'website'         => '',
			'token'           => $token,
			'path'            => '/coming-soon/',
		)
	);
}

/**
 * The "clean" part of a valid sign-up for an address.
 *
 * @param string $email Address.
 * @return array
 */
function dbgr_wls_clean( $email ) {
	$check = DoughBoss_Growth_Waitlist::validate(
		array(
			'email'           => $email,
			'consent'         => 1,
			'consent_version' => DoughBoss_Growth_Waitlist::consent_version(),
		)
	);
	return $check['clean'];
}

/**
 * The waitlist tab as a manager sees it.
 *
 * @return string
 */
function dbgr_wls_tab() {
	dbgr_test_set_admin( true );
	dbgr_test_login( array( 'manage_options' ) );
	ob_start();
	DoughBoss_Growth_Waitlist::render_tab();
	return (string) ob_get_clean();
}

/* ---------------------------------------------------------------------------------------------------------- */
/* F2: the privacy exporter and eraser never go quiet                                                           */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'F2 exporter: a failed read of the list is a visible "could not be read, run the export again" item and a recorded failure, never an empty finished export',
	function () {
		dbgr_wls_boot();
		dbgr_wls_seed_person( 1 );

		// Control: a healthy read exports the person and records nothing.
		$good = DoughBoss_Growth_Waitlist_Privacy::export( 'person1@example.com', 1 );
		assert_contains( 'person1@example.com', json_encode( $good['data'] ), 'control: the stored email is exported' );
		assert_same( array(), dbgr_wls_codes(), 'control: a healthy export records no failure' );

		$GLOBALS['wpdb']->fail_on( '/^SELECT \* FROM wp_doughboss_growth_waitlist WHERE email_hash/' );
		$out  = DoughBoss_Growth_Waitlist_Privacy::export( 'person1@example.com', 1 );
		$flat = json_encode( $out['data'] );
		$GLOBALS['wpdb']->clear_failures();

		assert_true( $out['done'], 'the exporter still finishes (WordPress would otherwise ask for the next page for ever)' );
		assert_count( 1, $out['data'], 'the failed read leaves one visible group in the export' );
		assert_same( DoughBoss_Growth_Waitlist_Privacy::KEY, $out['data'][0]['group_id'], 'in the waitlist group' );
		assert_contains( 'could not be read', $flat, 'it says the list could not be read' );
		assert_contains( 'run the export again', $flat, 'and tells the requester to run the export again' );
		assert_not_contains( 'person1@example.com', $flat, 'the notice carries no personal data' );
		assert_true( null !== dbgr_wls_failure( 'waitlist_privacy_export_failed' ), 'the failure is in the owner failure log' );
		assert_same( 'row_read', dbgr_wls_ctx( 'waitlist_privacy_export_failed', 'stage' ), 'with the stage that failed' );
		assert_not_contains( 'person1', json_encode( DoughBoss_Growth_Failures::all() ), 'and no personal data in the log' );
	}
);

db_test(
	'F2 exporter: an address that is simply not on the list, or is malformed, still exports nothing and records nothing (controls)',
	function () {
		dbgr_wls_boot();
		dbgr_wls_seed_person( 1 );
		$none = DoughBoss_Growth_Waitlist_Privacy::export( 'nobody@example.com', 1 );
		assert_same( array(), $none['data'], 'not on the list: nothing to export' );
		assert_true( $none['done'], 'done' );
		$junk = DoughBoss_Growth_Waitlist_Privacy::export( "x@example.com\nBcc: y", 1 );
		assert_same( array(), $junk['data'], 'malformed address: nothing to export' );
		assert_same( array(), dbgr_wls_codes(), 'neither is a failure' );
	}
);

db_test(
	'F2 exporter and eraser: storage that is not ready is said out loud (a visible item, a retained report) and recorded, never a silent empty answer',
	function () {
		dbgr_wls_boot();
		dbgr_wls_seed_person( 1 );
		delete_option( DoughBoss_Growth_Activator::DB_VERSION_OPTION );
		assert_false( DoughBoss_Growth_Activator::storage_ready(), 'set-up: storage is not ready' );

		$out  = DoughBoss_Growth_Waitlist_Privacy::export( 'person1@example.com', 1 );
		$flat = json_encode( $out['data'] );
		assert_true( $out['done'], 'export finishes' );
		assert_count( 1, $out['data'], 'one visible group, not an empty export' );
		assert_contains( 'could not be read', $flat, 'exporter: says the list could not be read' );
		assert_contains( 'run the export again', $flat, 'exporter: asks for a second run' );
		assert_same( 'storage_not_ready', dbgr_wls_ctx( 'waitlist_privacy_export_failed', 'stage' ), 'exporter: failure recorded with its stage' );

		$erase = DoughBoss_Growth_Waitlist_Privacy::erase( 'person1@example.com', 1 );
		assert_false( $erase['items_removed'], 'eraser: nothing removed' );
		assert_true( $erase['items_retained'], 'eraser: reported as retained, not as a clean pass' );
		assert_count( 1, $erase['messages'], 'eraser: with a message' );
		assert_contains( 'nothing was erased', $erase['messages'][0], 'eraser: the message says nothing was erased' );
		assert_true( $erase['done'], 'eraser: done (the requester is told, and can ask again)' );
		assert_same( 'storage_not_ready', dbgr_wls_ctx( 'waitlist_erase_failed', 'stage' ), 'eraser: failure recorded with its stage' );

		// Control: a malformed address is not a storage problem even when storage is not ready.
		DoughBoss_Growth_Failures::clear();
		$junk = DoughBoss_Growth_Waitlist_Privacy::export( 'not-an-address', 1 );
		assert_same( array(), $junk['data'], 'control: a malformed address exports nothing' );
		$junk = DoughBoss_Growth_Waitlist_Privacy::erase( 'not-an-address', 1 );
		assert_false( $junk['items_retained'], 'control: and erases nothing without a message' );
		assert_same( array(), dbgr_wls_codes(), 'control: and records nothing' );
	}
);

db_test(
	'F2 eraser: the branches that already told the requester (read error, failed delete) now also reach the owner failure log; the row is kept',
	function () {
		dbgr_wls_boot();
		$p = dbgr_wls_seed_person( 1 );

		$GLOBALS['wpdb']->fail_on( '/^SELECT \* FROM wp_doughboss_growth_waitlist WHERE email_hash/' );
		$r = DoughBoss_Growth_Waitlist_Privacy::erase( 'person1@example.com', 1 );
		$GLOBALS['wpdb']->clear_failures();
		assert_false( $r['items_removed'], 'read error: nothing removed' );
		assert_true( $r['items_retained'], 'read error: retained' );
		assert_count( 1, $r['messages'], 'read error: the requester is told' );
		assert_same( 'row_read', dbgr_wls_ctx( 'waitlist_erase_failed', 'stage' ), 'read error: the owner can see it too' );

		DoughBoss_Growth_Failures::clear();
		$GLOBALS['wpdb']->fail_on( '/^DELETE FROM wp_doughboss_growth_waitlist WHERE id/' );
		$r = DoughBoss_Growth_Waitlist_Privacy::erase( 'person1@example.com', 1 );
		$GLOBALS['wpdb']->clear_failures();
		assert_false( $r['items_removed'], 'delete error: nothing removed' );
		assert_true( $r['items_retained'], 'delete error: retained' );
		assert_same( 'delete', dbgr_wls_ctx( 'waitlist_erase_failed', 'stage' ), 'delete error: recorded with its stage' );
		assert_same( 'confirmed', dbgr_wls_status( $p['id'] ), 'the row is still there to be erased on the next try' );

		// Control: the same request works once storage works.
		DoughBoss_Growth_Failures::clear();
		$r = DoughBoss_Growth_Waitlist_Privacy::erase( 'person1@example.com', 1 );
		assert_true( $r['items_removed'], 'control: erased when storage works' );
		assert_same( array(), dbgr_wls_codes(), 'control: nothing recorded' );
	}
);

db_test(
	'F2 eraser: an unreadable opt-out list is not reported as "nothing retained", and a failed owner-chosen suppression write is said out loud and recorded; the row is still erased',
	function () {
		dbgr_wls_boot();
		$p = dbgr_wls_seed_person( 1 );
		$GLOBALS['wpdb']->fail_on( '/FROM wp_doughboss_growth_suppression/' );
		$r = DoughBoss_Growth_Waitlist_Privacy::erase( 'person1@example.com', 1 );
		$GLOBALS['wpdb']->clear_failures();
		assert_true( $r['items_removed'], 'the row itself is erased' );
		assert_true( $r['items_retained'], 'the unread opt-out list is reported as possibly retained, not as a clean pass' );
		assert_count( 1, $r['messages'], 'with one message' );
		assert_contains( 'could not be read', $r['messages'][0], 'which says the opt-out list could not be read' );
		assert_same( 'suppression_read', dbgr_wls_ctx( 'waitlist_erase_failed', 'stage' ), 'and the owner can see it' );
		assert_same( null, dbgr_wls_status( $p['id'] ), 'the row is gone' );

		// The owner has chosen to keep erased people off the list (the filter), but the write fails.
		DoughBoss_Growth_Failures::clear();
		$q = dbgr_wls_seed_person( 2 );
		add_filter( 'doughboss_growth_waitlist_suppress_on_erase', '__return_true' );
		$GLOBALS['wpdb']->fail_on( '/INSERT IGNORE INTO wp_doughboss_growth_suppression/' );
		$r = DoughBoss_Growth_Waitlist_Privacy::erase( 'person2@example.com', 1 );
		$GLOBALS['wpdb']->clear_failures();
		assert_true( $r['items_removed'], 'the row is erased' );
		assert_false( $r['items_retained'], 'nothing is claimed as retained when the hash was not written' );
		assert_count( 1, $r['messages'], 'but the requester is told the opt-out list was not updated' );
		assert_contains( 'could not be updated', $r['messages'][0], 'in plain words' );
		assert_same( 'suppress', dbgr_wls_ctx( 'waitlist_erase_failed', 'stage' ), 'and the owner can see it' );
		assert_same( 0, dbgr_wls_suppressed_count(), 'nothing was suppressed' );

		// Control: with the owner filter on and storage healthy the hash is kept and reported.
		DoughBoss_Growth_Failures::clear();
		dbgr_wls_seed_person( 3 );
		$r = DoughBoss_Growth_Waitlist_Privacy::erase( 'person3@example.com', 1 );
		assert_true( $r['items_retained'], 'control: retained and reported when it works' );
		assert_same( 1, dbgr_wls_suppressed_count(), 'control: one suppression hash' );
		assert_same( array(), dbgr_wls_codes(), 'control: nothing recorded' );
		unset( $q );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* F5: a person can always leave; only INVALID attempts count against the address                               */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'F5 shared address: forty people opting out from one address inside the hour all succeed through the REST route (one-click POST) and each is suppressed',
	function () {
		dbgr_wls_boot();
		$people = array();
		for ( $n = 1; $n <= 40; $n++ ) {
			$people[] = dbgr_wls_seed_person( $n );
		}
		$statuses = array();
		foreach ( $people as $p ) {
			$statuses[] = dbgr_wls_rest_optout( (string) $p['id'], $p['token'] )->get_status();
		}
		assert_same( array_fill( 0, 40, 200 ), $statuses, 'every one of the forty gets 200 (before the fix, the 31st onward got 429)' );
		$left = 0;
		foreach ( $people as $p ) {
			if ( 'unsubscribed' === dbgr_wls_status( $p['id'] ) ) {
				$left++;
			}
		}
		assert_same( 40, $left, 'all forty rows are opted out' );
		assert_same( 40, dbgr_wls_suppressed_count(), 'and all forty are on the suppression list' );
	}
);

db_test(
	'F5 shared address: forty people opting out through the email-link page (mail-client one-click POST, and the button POST) all succeed',
	function () {
		dbgr_wls_boot();
		$statuses = array();
		$people   = array();
		for ( $n = 1; $n <= 40; $n++ ) {
			$p          = dbgr_wls_seed_person( $n );
			$people[]   = $p;
			$page       = dbgr_wls_link( 'unsubscribe', $p['id'], $p['token'], 0 === $n % 2 );
			$statuses[] = $page['status'];
			assert_contains( 'You have been unsubscribed', $page['html'], 'person ' . $n . ' is told it worked' );
		}
		assert_same( array_fill( 0, 40, 200 ), $statuses, 'every link-page opt-out gets 200' );
		foreach ( $people as $p ) {
			assert_same( 'unsubscribed', dbgr_wls_status( $p['id'] ), 'row ' . $p['id'] . ' is opted out' );
		}
		assert_same( 40, dbgr_wls_suppressed_count(), 'all forty suppressed' );
	}
);

db_test(
	'F5 valid opt-outs are not counted: the bucket stays empty after forty of them, and then an invalid attempt is the first one counted',
	function () {
		dbgr_wls_boot();
		for ( $n = 1; $n <= 40; $n++ ) {
			$p = dbgr_wls_seed_person( $n );
			dbgr_wls_rest_optout( (string) $p['id'], $p['token'] );
		}
		assert_same( 0, dbgr_wls_action_hits(), 'valid-token opt-outs leave no hits in the per-address bucket' );
		$bad = dbgr_wls_rest_optout( '1', str_repeat( 'b', 32 ) );
		assert_same( 400, $bad->get_status(), 'the first invalid attempt is a plain invalid-link answer, not a rate limit' );
		assert_same( 1, dbgr_wls_action_hits(), 'and it is the one hit counted' );
	}
);

db_test(
	'F5 NEGATIVE CONTROL: invalid tokens are still rate limited (30 an hour per address) on the REST route and on the link page, and the limit resets with its window',
	function () {
		dbgr_wls_boot();
		$p = dbgr_wls_seed_person( 1 );

		$statuses = array();
		for ( $i = 1; $i <= 30; $i++ ) {
			// A mix of everything that is invalid: wrong token for a real row, an unknown row, a malformed token.
			if ( 0 === $i % 3 ) {
				$r = dbgr_wls_rest_optout( '99999', str_repeat( 'b', 32 ) );
			} elseif ( 1 === $i % 3 ) {
				$r = dbgr_wls_rest_optout( (string) $p['id'], str_repeat( 'b', 32 ) );
			} else {
				$r = dbgr_wls_rest_optout( (string) $p['id'], 'not-a-token' );
			}
			$statuses[] = $r->get_status();
		}
		assert_same( array_fill( 0, 30, 400 ), $statuses, 'the first thirty invalid attempts are answered as invalid links' );
		assert_same( 30, dbgr_wls_action_hits(), 'and all thirty were counted' );

		$limited = dbgr_wls_rest_optout( (string) $p['id'], str_repeat( 'c', 32 ) );
		assert_same( 429, $limited->get_status(), 'the thirty-first invalid attempt from the address is refused: 429' );
		assert_same( 'dbgr_rate_limited', $limited->get_data()['code'], 'with the rate-limit code' );
		assert_true( isset( $limited->get_headers()['Retry-After'] ) && (int) $limited->get_headers()['Retry-After'] > 0, 'and a Retry-After' );

		$page = dbgr_wls_link( 'unsubscribe', $p['id'], str_repeat( 'd', 32 ) );
		assert_same( 429, $page['status'], 'the link page refuses a further invalid attempt too: 429' );
		assert_contains( 'Too many attempts', $page['html'], 'with the same plain message' );
		assert_same( 'confirmed', dbgr_wls_status( $p['id'] ), 'nobody was opted out by any of this' );
		assert_same( 0, dbgr_wls_suppressed_count(), 'and nobody was suppressed' );

		dbgr_test_advance( 3601 );
		assert_same( 400, dbgr_wls_rest_optout( (string) $p['id'], str_repeat( 'b', 32 ) )->get_status(), 'the allowance returns with the next window' );
	}
);

db_test(
	'F5 shared address: someone who exhausts the bucket with bad tokens cannot lock real people out; a valid token still works, and bad ones are still refused',
	function () {
		dbgr_wls_boot();
		$a = dbgr_wls_seed_person( 1 );
		$b = dbgr_wls_seed_person( 2 );
		$c = dbgr_wls_seed_person( 3 );
		for ( $i = 0; $i < 30; $i++ ) {
			dbgr_wls_rest_optout( '99999', str_repeat( 'b', 32 ) );
		}
		assert_same( 429, dbgr_wls_rest_optout( '99999', str_repeat( 'b', 32 ) )->get_status(), 'set-up: the bucket is full' );

		$r = dbgr_wls_rest_optout( (string) $a['id'], $a['token'] );
		assert_same( 200, $r->get_status(), 'REST: a valid token opts out although the bucket is full' );
		assert_same( 'unsubscribed', dbgr_wls_status( $a['id'] ), 'REST: and the row is opted out' );

		$page = dbgr_wls_link( 'unsubscribe', $b['id'], $b['token'] );
		assert_same( 200, $page['status'], 'link page: a valid one-click opt-out works although the bucket is full' );
		assert_same( 'unsubscribed', dbgr_wls_status( $b['id'] ), 'link page: and the row is opted out' );

		// Negative controls in the same state.
		assert_same( 429, dbgr_wls_rest_optout( (string) $c['id'], str_repeat( 'b', 32 ) )->get_status(), 'control: a wrong token for a real row is still refused' );
		assert_same( 429, dbgr_wls_link( 'unsubscribe', $c['id'], str_repeat( 'b', 32 ) )['status'], 'control: and on the link page' );
		assert_same( 'confirmed', dbgr_wls_status( $c['id'] ), 'control: the third person is untouched by the invalid attempts' );
		assert_same( 2, dbgr_wls_suppressed_count(), 'control: exactly the two valid opt-outs are suppressed' );
	}
);

db_test(
	'F5 limiter storage error: a valid-token opt-out still works (REST and link page); an invalid token still fails closed (503) and the failure is recorded',
	function () {
		dbgr_wls_boot();
		$a = dbgr_wls_seed_person( 1 );
		$b = dbgr_wls_seed_person( 2 );
		$c = dbgr_wls_seed_person( 3 );
		$GLOBALS['wpdb']->fail_on( '/doughboss_growth_rate/' );

		$r = dbgr_wls_rest_optout( (string) $a['id'], $a['token'] );
		assert_same( 200, $r->get_status(), 'REST: a valid token opts out while the limiter table is failing' );
		assert_same( 'unsubscribed', dbgr_wls_status( $a['id'] ), 'REST: row opted out' );
		$page = dbgr_wls_link( 'unsubscribe', $b['id'], $b['token'] );
		assert_same( 200, $page['status'], 'link page: a valid token opts out while the limiter table is failing' );
		assert_same( 'unsubscribed', dbgr_wls_status( $b['id'] ), 'link page: row opted out' );

		// NEGATIVE CONTROLS: an invalid token is never let through because the limiter is down.
		$bad = dbgr_wls_rest_optout( (string) $c['id'], str_repeat( 'b', 32 ) );
		assert_same( 503, $bad->get_status(), 'REST: an invalid token fails closed with 503, not 400 and not 200' );
		assert_same( 'dbgr_unavailable', $bad->get_data()['code'], 'REST: unavailable code' );
		$bad_page = dbgr_wls_link( 'unsubscribe', $c['id'], str_repeat( 'b', 32 ) );
		assert_same( 503, $bad_page['status'], 'link page: an invalid token fails closed with 503' );
		assert_not_contains( 'You have been unsubscribed', $bad_page['html'], 'link page: and never claims success' );
		assert_same( 'confirmed', dbgr_wls_status( $c['id'] ), 'the third person was not opted out by an invalid token' );
		assert_same( 2, dbgr_wls_suppressed_count(), 'only the two valid opt-outs are suppressed' );
		$GLOBALS['wpdb']->clear_failures();

		assert_true( null !== dbgr_wls_failure( 'waitlist_limiter_failed' ), 'the limiter failure reached the owner failure log' );
		assert_same( 'optout', dbgr_wls_ctx( 'waitlist_limiter_failed', 'route' ), 'recorded against the opt-out route' );
	}
);

db_test(
	'F5 NEGATIVE CONTROL: an invalid token cannot opt anyone out in any state (fresh bucket, full bucket, failing limiter, flag off), whatever form the invalidity takes',
	function () {
		dbgr_wls_boot();
		$a = dbgr_wls_seed_person( 1 );
		$b = dbgr_wls_seed_person( 2 );

		$attempts = array(
			'wrong token, real row'            => array( (string) $a['id'], str_repeat( 'b', 32 ) ),
			'the OTHER row\'s valid token'     => array( (string) $a['id'], $b['token'] ),
			'right token, wrong id'            => array( (string) ( $a['id'] + 50 ), $a['token'] ),
			'zero id'                          => array( '0', $a['token'] ),
			'negative id'                      => array( '-1', $a['token'] ),
			'upper-case token'                 => array( (string) $a['id'], strtoupper( $a['token'] ) ),
			'31 character token'               => array( (string) $a['id'], substr( $a['token'], 0, 31 ) ),
			'33 character token'               => array( (string) $a['id'], $a['token'] . '0' ),
			'empty token'                      => array( (string) $a['id'], '' ),
			'array token'                      => array( (string) $a['id'], array( $a['token'] ) ),
			'array id'                         => array( array( (string) $a['id'] ), $a['token'] ),
		);
		$states = array(
			'fresh bucket' => function () {},
			'full bucket'  => function () {
				for ( $i = 0; $i < 30; $i++ ) {
					dbgr_wls_rest_optout( '99999', str_repeat( 'e', 32 ) );
				}
			},
			'failing limiter' => function () {
				$GLOBALS['wpdb']->fail_on( '/doughboss_growth_rate/' );
			},
			'waitlist flag off' => function () {
				update_option( DoughBoss_Growth_Settings::OPTION, array( 'features' => array( 'waitlist' => false ), 'sender_legal_name' => DBGR_WLS_SENDER, 'privacy_policy_url' => 'https://example.com.au/privacy/' ) );
			},
		);
		foreach ( $states as $state => $setup ) {
			$GLOBALS['wpdb']->sqlite_raw( 'DELETE FROM wp_doughboss_growth_rate' );
			$GLOBALS['wpdb']->clear_failures();
			$setup();
			foreach ( $attempts as $label => $args ) {
				$r = dbgr_wls_rest_optout( $args[0], $args[1] );
				assert_true( 200 !== $r->get_status(), $state . ' / ' . $label . ': REST never says success (got ' . $r->get_status() . ')' );
				if ( ! is_array( $args[0] ) && ! is_array( $args[1] ) ) {
					$page = dbgr_wls_link( 'unsubscribe', $args[0], $args[1], false );
					assert_true( 200 !== $page['status'], $state . ' / ' . $label . ': link page never says success (got ' . $page['status'] . ')' );
					assert_not_contains( 'You have been unsubscribed', $page['html'], $state . ' / ' . $label . ': link page never claims it' );
				}
			}
			assert_same( 'confirmed', dbgr_wls_status( $a['id'] ), $state . ': person A was not opted out' );
			assert_same( 'confirmed', dbgr_wls_status( $b['id'] ), $state . ': person B was not opted out' );
			assert_same( 0, dbgr_wls_suppressed_count(), $state . ': nobody was suppressed' );
		}
		$GLOBALS['wpdb']->clear_failures();

		// Control: with the flag off the real token still works (a person must always be able to leave).
		assert_same( 200, dbgr_wls_rest_optout( (string) $a['id'], $a['token'] )->get_status(), 'control: the real token opts out even with the flag off' );
		assert_same( 'unsubscribed', dbgr_wls_status( $a['id'] ), 'control: A is opted out' );
	}
);

db_test(
	'F5 one-click: the answer is 200 only when the opt-out really happened; a failed suppression or update is a 503 on both entry points, is recorded, and the person can retry (nothing was counted against them)',
	function () {
		dbgr_wls_boot();
		$a = dbgr_wls_seed_person( 1 );

		$GLOBALS['wpdb']->fail_on( '/INSERT IGNORE INTO wp_doughboss_growth_suppression/' );
		$r = dbgr_wls_rest_optout( (string) $a['id'], $a['token'] );
		assert_same( 503, $r->get_status(), 'REST: a failed suppression write is 503, never 200' );
		$page = dbgr_wls_link( 'unsubscribe', $a['id'], $a['token'] );
		assert_same( 503, $page['status'], 'link page: a failed suppression write is 503, never 200' );
		assert_not_contains( 'You have been unsubscribed', $page['html'], 'link page: and never says it worked' );
		$GLOBALS['wpdb']->clear_failures();
		assert_same( 'confirmed', dbgr_wls_status( $a['id'] ), 'the row is unchanged' );
		assert_same( 'suppress', dbgr_wls_ctx( 'waitlist_optout_failed', 'stage' ), 'the owner can see the failed opt-out and where it failed' );
		assert_same( 0, dbgr_wls_action_hits(), 'a failed valid opt-out is not counted against the address' );

		DoughBoss_Growth_Failures::clear();
		$GLOBALS['wpdb']->fail_on( '/^UPDATE wp_doughboss_growth_waitlist SET status = \'unsubscribed\'/' );
		assert_same( 503, dbgr_wls_rest_optout( (string) $a['id'], $a['token'] )->get_status(), 'REST: a failed status update is 503' );
		$GLOBALS['wpdb']->clear_failures();
		assert_same( 'update', dbgr_wls_ctx( 'waitlist_optout_failed', 'stage' ), 'the failed update is recorded with its stage' );

		DoughBoss_Growth_Failures::clear();
		$GLOBALS['wpdb']->fail_on( '/^SELECT \* FROM wp_doughboss_growth_waitlist WHERE id/' );
		assert_same( 503, dbgr_wls_rest_optout( (string) $a['id'], $a['token'] )->get_status(), 'REST: a failed row read is 503, not "invalid link"' );
		$GLOBALS['wpdb']->clear_failures();
		assert_same( 'row_read', dbgr_wls_ctx( 'waitlist_optout_failed', 'stage' ), 'the failed read is recorded with its stage' );

		// Retry once storage is healthy.
		assert_same( 200, dbgr_wls_rest_optout( (string) $a['id'], $a['token'] )->get_status(), 'the retry succeeds' );
		assert_same( 'unsubscribed', dbgr_wls_status( $a['id'] ), 'and the person is out' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* F4: sign-up failures are told apart, recorded without personal data, and shown on the tab                    */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'F4 mail failure: signup() says error_mail (not a generic error), rolls the row back, and records a mail failure with no personal data',
	function () {
		dbgr_wls_boot();
		$GLOBALS['dbgr_mail_fails'] = true;
		$result                     = DoughBoss_Growth_Waitlist::signup( dbgr_wls_clean( 'mailfail@example.com' ) );
		$GLOBALS['dbgr_mail_fails'] = false;
		assert_same( 'error_mail', $result['result'], 'the result names the cause: mail' );
		assert_same( array(), $GLOBALS['wpdb']->sqlite_raw( 'SELECT id FROM wp_doughboss_growth_waitlist' ), 'the half-made row was removed' );
		$failure = dbgr_wls_failure( 'waitlist_signup_mail_failed' );
		assert_true( null !== $failure, 'a mail failure is in the failure log' );
		assert_same( 1, null === $failure ? 0 : $failure['count'], 'counted once' );
		assert_same( 'confirmation', dbgr_wls_ctx( 'waitlist_signup_mail_failed', 'stage' ), 'with its stage' );
		assert_same( array(), array_diff( dbgr_wls_codes(), array( 'waitlist_signup_mail_failed' ) ), 'and nothing else is blamed (the row was not a storage failure)' );
		$dump = json_encode( DoughBoss_Growth_Failures::all() );
		assert_not_contains( 'mailfail', $dump, 'the log holds no email address' );
		assert_not_contains( hash( 'sha256', 'mailfail@example.com' ), $dump, 'and no hash of one' );
		assert_not_contains( '@', $dump, 'and nothing address-shaped' );
		assert_not_contains( DBGR_WLS_SHARED_IP, $dump, 'and no visitor address' );
	}
);

db_test(
	'F4 storage failures: a suppression read, a row read and an INSERT each give error_storage with their own stage; the row is never half-made',
	function () {
		$cases = array(
			'suppression_read' => '/FROM wp_doughboss_growth_suppression/',
			'row_read'         => '/^SELECT \* FROM wp_doughboss_growth_waitlist WHERE email_hash/',
			'insert'           => '/INSERT IGNORE INTO wp_doughboss_growth_waitlist/',
		);
		foreach ( $cases as $stage => $pattern ) {
			dbgr_wls_boot();
			$GLOBALS['wpdb']->fail_on( $pattern );
			$result = DoughBoss_Growth_Waitlist::signup( dbgr_wls_clean( 'storagefail@example.com' ) );
			$GLOBALS['wpdb']->clear_failures();
			assert_same( 'error_storage', $result['result'], $stage . ': the result names the cause: storage' );
			assert_same( array(), $GLOBALS['wpdb']->sqlite_raw( 'SELECT id FROM wp_doughboss_growth_waitlist' ), $stage . ': nothing stored' );
			assert_same( array(), $GLOBALS['dbgr_mail'], $stage . ': no email sent' );
			assert_same( $stage, dbgr_wls_ctx( 'waitlist_signup_storage_failed', 'stage' ), $stage . ': recorded with its stage' );
			assert_false( in_array( 'waitlist_signup_mail_failed', dbgr_wls_codes(), true ), $stage . ': not blamed on mail' );
			assert_not_contains( 'storagefail', json_encode( DoughBoss_Growth_Failures::all() ), $stage . ': no email address in the log' );
			DoughBoss_Growth_Failures::clear();
		}
	}
);

db_test(
	'F4 resend path: a pending address that cannot be updated is error_storage, one whose email cannot be sent is error_mail; the pending row is kept for the next try',
	function () {
		dbgr_wls_boot();
		$p = dbgr_wls_seed_person( 1, 'pending' );
		$GLOBALS['wpdb']->fail_on( '/^UPDATE wp_doughboss_growth_waitlist SET confirm_token_hash/' );
		$result = DoughBoss_Growth_Waitlist::signup( dbgr_wls_clean( 'person1@example.com' ) );
		$GLOBALS['wpdb']->clear_failures();
		assert_same( 'error_storage', $result['result'], 'a failed token refresh is storage' );
		assert_same( 'resend_update', dbgr_wls_ctx( 'waitlist_signup_storage_failed', 'stage' ), 'recorded with its stage' );

		DoughBoss_Growth_Failures::clear();
		$GLOBALS['dbgr_mail_fails'] = true;
		$result                     = DoughBoss_Growth_Waitlist::signup( dbgr_wls_clean( 'person1@example.com' ) );
		$GLOBALS['dbgr_mail_fails'] = false;
		assert_same( 'error_mail', $result['result'], 'a failed resend email is mail' );
		assert_same( 'resend', dbgr_wls_ctx( 'waitlist_signup_mail_failed', 'stage' ), 'recorded with its stage' );
		assert_same( 'pending', dbgr_wls_status( $p['id'] ), 'the existing pending row is kept' );

		DoughBoss_Growth_Failures::clear();
		$result = DoughBoss_Growth_Waitlist::signup( dbgr_wls_clean( 'person1@example.com' ) );
		assert_same( 'resent', $result['result'], 'control: healthy storage and mail resend' );
		assert_same( array(), dbgr_wls_codes(), 'control: and record nothing' );
	}
);

db_test(
	'F4 odd storage answers: an INSERT whose row cannot be found again, and a pending row with missing fields, are error_storage with their own stage (never silent, never mail)',
	function () {
		dbgr_wls_boot();
		// The INSERT works but reports no id and the row cannot be looked up again.
		$GLOBALS['wpdb']->respond(
			'/^SELECT \* FROM wp_doughboss_growth_waitlist WHERE email_hash/',
			function () {
				return array();
			}
		);
		$GLOBALS['wpdb']->respond(
			'/^INSERT IGNORE INTO wp_doughboss_growth_waitlist/',
			function ( $sql ) {
				$GLOBALS['wpdb']->sqlite_raw( str_replace( 'INSERT IGNORE INTO', 'INSERT OR IGNORE INTO', $sql ) );
				$GLOBALS['wpdb']->insert_id = 0;
				return 1;
			}
		);
		$result = DoughBoss_Growth_Waitlist::signup( dbgr_wls_clean( 'lost@example.com' ) );
		assert_same( 'error_storage', $result['result'], 'a stored row that cannot be found again is a storage error' );
		assert_same( 'row_lookup', dbgr_wls_ctx( 'waitlist_signup_storage_failed', 'stage' ), 'recorded with its stage' );
		assert_same( array(), $GLOBALS['dbgr_mail'], 'and no email went out for a row nobody can point at' );
		assert_false( in_array( 'waitlist_signup_mail_failed', dbgr_wls_codes(), true ), 'not blamed on mail' );
	}
);

db_test(
	'F4 odd storage answers: a pending row that is missing its address is error_storage (stage resend_row), not a silent failure',
	function () {
		dbgr_wls_boot();
		$hash = hash( 'sha256', 'person1@example.com' );
		$GLOBALS['wpdb']->sqlite_raw( "INSERT INTO wp_doughboss_growth_waitlist (email, email_hash, consent_marketing, consent_text_version, consent_text_hash, consent_at_utc, consent_source_path, status, created_at, updated_at) VALUES ('', '{$hash}', 1, 'wl-test', '" . str_repeat( 'f', 64 ) . "', '2026-10-01 00:00:00', '/', 'pending', '2026-10-01 00:00:00', '2026-10-01 00:00:00')" );
		$result = DoughBoss_Growth_Waitlist::signup( dbgr_wls_clean( 'person1@example.com' ) );
		assert_same( 'error_storage', $result['result'], 'a damaged pending row is a storage error' );
		assert_same( 'resend_row', dbgr_wls_ctx( 'waitlist_signup_storage_failed', 'stage' ), 'recorded with its stage' );
		assert_same( array(), $GLOBALS['dbgr_mail'], 'no email sent to an address nobody can read back' );
	}
);

db_test(
	'F4 rollback: if the half-made row cannot be removed after a mail failure, that is recorded too (it is a failed write the owner should see)',
	function () {
		dbgr_wls_boot();
		$GLOBALS['dbgr_mail_fails'] = true;
		$GLOBALS['wpdb']->fail_on( '/^DELETE FROM wp_doughboss_growth_waitlist WHERE id = [0-9]+ AND status = \'pending\'/' );
		$result = DoughBoss_Growth_Waitlist::signup( dbgr_wls_clean( 'stuck@example.com' ) );
		$GLOBALS['wpdb']->clear_failures();
		$GLOBALS['dbgr_mail_fails'] = false;
		assert_same( 'error_mail', $result['result'], 'the visitor-facing cause is still mail' );
		assert_true( in_array( 'waitlist_signup_mail_failed', dbgr_wls_codes(), true ), 'the mail failure is recorded' );
		assert_same( 'rollback', dbgr_wls_ctx( 'waitlist_signup_storage_failed', 'stage' ), 'and so is the failed rollback' );
	}
);

db_test(
	'F4 visitor view: every failure cause gets the SAME neutral 503 (no oracle for whether an address is new), unchanged in wording; success records nothing',
	function () {
		dbgr_wls_boot();
		$GLOBALS['dbgr_mail_fails'] = true;
		$mail                       = dbgr_wls_signup( 'one@example.com' );
		$GLOBALS['dbgr_mail_fails'] = false;
		$GLOBALS['wpdb']->fail_on( '/INSERT IGNORE INTO wp_doughboss_growth_waitlist/' );
		$storage = dbgr_wls_signup( 'two@example.com' );
		$GLOBALS['wpdb']->clear_failures();
		$GLOBALS['wpdb']->fail_on( '/FROM wp_doughboss_growth_suppression/' );
		$read = dbgr_wls_signup( 'three@example.com' );
		$GLOBALS['wpdb']->clear_failures();

		assert_same( 503, $mail->get_status(), 'mail failure: 503' );
		assert_same( $mail->get_status(), $storage->get_status(), 'same status for a storage failure' );
		assert_same( $mail->get_data(), $storage->get_data(), 'same body for a storage failure' );
		assert_same( $mail->get_data(), $read->get_data(), 'same body for a read failure' );
		assert_same( 'dbgr_unavailable', $mail->get_data()['code'], 'code unchanged' );
		assert_same( 'We could not save your details just now. Please try again later.', $mail->get_data()['message'], 'wording unchanged' );
		assert_same( array(), array_diff_key( $mail->get_headers(), $storage->get_headers() ), 'same headers' );
		foreach ( array( 'waitlist_signup_mail_failed', 'waitlist_signup_storage_failed' ) as $code ) {
			assert_true( null !== dbgr_wls_failure( $code ), 'and yet the owner log has ' . $code );
		}

		// Control: a healthy sign-up works and adds no failure.
		DoughBoss_Growth_Failures::clear();
		assert_same( 200, dbgr_wls_signup( 'four@example.com' )->get_status(), 'control: a healthy sign-up works' );
		assert_same( array(), dbgr_wls_codes(), 'control: and records nothing' );
	}
);

db_test(
	'F4 limiter failure: a limiter storage error on the sign-up route is a 503 (unchanged) and is now recorded for the owner',
	function () {
		dbgr_wls_boot();
		$GLOBALS['wpdb']->fail_on( '/doughboss_growth_rate/' );
		$r = dbgr_wls_signup( 'limiter@example.com' );
		$GLOBALS['wpdb']->clear_failures();
		assert_same( 503, $r->get_status(), '503 as before' );
		assert_true( null !== dbgr_wls_failure( 'waitlist_limiter_failed' ), 'recorded' );
		assert_same( 'signup', dbgr_wls_ctx( 'waitlist_limiter_failed', 'route' ), 'against the sign-up route' );

		// Control: a plain "too many attempts" is the visitor's doing, not a failure.
		DoughBoss_Growth_Failures::clear();
		for ( $i = 0; $i < 6; $i++ ) {
			$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
			$GLOBALS['dbgr_wls_ip_n'] = 0;
			$token = DoughBoss_Growth_Waitlist::issue_form_token();
			dbgr_test_advance( 4 );
			$last = dbgr_test_rest_dispatch( 'POST', '/doughboss-growth/v1/waitlist', array( 'email' => 'x' . $i . '@example.com', 'consent' => 1, 'consent_version' => DoughBoss_Growth_Waitlist::consent_version(), 'token' => $token ) );
		}
		assert_same( 429, $last->get_status(), 'control: the sixth sign-up from one address is rate limited' );
		assert_false( in_array( 'waitlist_limiter_failed', dbgr_wls_codes(), true ), 'control: a rate limit is not recorded as a failure' );
	}
);

db_test(
	'F4 tab: the VIP waitlist tab shows the last sign-up failure (plain words and the code, no personal data), and "None recorded" when there is none',
	function () {
		dbgr_wls_boot();
		$tab = dbgr_wls_tab();
		assert_contains( 'Last sign-up failure', $tab, 'the row exists' );
		assert_contains( 'None recorded', $tab, 'and says none when none is recorded' );

		$GLOBALS['dbgr_mail_fails'] = true;
		DoughBoss_Growth_Waitlist::signup( dbgr_wls_clean( 'tabmail@example.com' ) );
		$GLOBALS['dbgr_mail_fails'] = false;
		$tab = dbgr_wls_tab();
		assert_contains( 'Last sign-up failure', $tab, 'row still there' );
		assert_contains( 'could not be sent', $tab, 'plain words: the confirmation email could not be sent' );
		assert_contains( 'waitlist_signup_mail_failed', $tab, 'and the code' );
		assert_not_contains( 'tabmail', $tab, 'no email address on the screen' );
		assert_not_contains( 'None recorded', $tab, 'no longer says none' );

		// A later, different failure is the one shown (newest first).
		dbgr_test_advance( 120 );
		$GLOBALS['wpdb']->fail_on( '/INSERT IGNORE INTO wp_doughboss_growth_waitlist/' );
		DoughBoss_Growth_Waitlist::signup( dbgr_wls_clean( 'tabstore@example.com' ) );
		$GLOBALS['wpdb']->clear_failures();
		$tab = dbgr_wls_tab();
		assert_contains( 'waitlist_signup_storage_failed', $tab, 'the newest sign-up failure is shown' );
		assert_not_contains( 'tabstore', $tab, 'still no personal data' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* F11: the retention purge reports what failed                                                                 */
/* ---------------------------------------------------------------------------------------------------------- */

/**
 * Seed the three kinds of expired row and return the setting that makes confirmed rows expire.
 *
 * @return void
 */
function dbgr_wls_seed_expired() {
	$ago = function ( $days ) {
		return gmdate( 'Y-m-d H:i:s', DoughBoss_Growth::now() - ( $days * DAY_IN_SECONDS ) );
	};
	dbgr_wls_seed_person( 1, 'pending', $ago( 40 ) );
	dbgr_wls_seed_person( 2, 'unsubscribed', $ago( 100 ), $ago( 40 ) );
	dbgr_wls_seed_person( 3, 'confirmed', $ago( 900 ), $ago( 899 ) );
}

db_test(
	'F11 purge: a failed DELETE is null in the result (never collapsed to 0), counted as failed, and recorded with the stage and counts only; the steps that worked still ran',
	function () {
		dbgr_wls_boot( array( 'retention_confirmed_months' => 12 ) );
		dbgr_wls_seed_expired();

		$GLOBALS['wpdb']->fail_on( "/^DELETE FROM wp_doughboss_growth_waitlist WHERE status = 'pending'/" );
		$out = DoughBoss_Growth_Waitlist::purge();
		$GLOBALS['wpdb']->clear_failures();
		assert_same( null, array_key_exists( 'pending', $out ) ? $out['pending'] : 'missing', 'the failed step is null, not 0' );
		assert_same( 1, isset( $out['unsubscribed'] ) ? $out['unsubscribed'] : 'missing', 'the opted-out step still ran' );
		assert_same( 1, isset( $out['confirmed'] ) ? $out['confirmed'] : 'missing', 'the confirmed step still ran' );
		assert_same( 1, isset( $out['failed'] ) ? $out['failed'] : 'missing', 'one failed step is counted' );
		assert_true( null !== dbgr_wls_failure( 'waitlist_purge_failed' ), 'the failure is in the owner failure log' );
		assert_contains( 'pending_delete', dbgr_wls_ctx( 'waitlist_purge_failed', 'stage' ), 'with the stage' );
		assert_same( '1', dbgr_wls_ctx( 'waitlist_purge_failed', 'failed' ), 'and the failed count' );
		$dump = json_encode( DoughBoss_Growth_Failures::all() );
		assert_not_contains( 'person', $dump, 'no personal data in the log' );
		assert_not_contains( '@', $dump, 'nothing address-shaped in the log' );
		assert_same( 'pending', dbgr_wls_status( 1 ), 'the pending row is still there for the next run' );
	}
);

db_test(
	'F11 purge: every failing read or delete is null and recorded (opted-out read, confirmed delete, limiter buckets); a total outage deletes nothing and says so',
	function () {
		dbgr_wls_boot( array( 'retention_confirmed_months' => 12 ) );
		dbgr_wls_seed_expired();
		$GLOBALS['wpdb']->fail_all();
		$out = DoughBoss_Growth_Waitlist::purge();
		$GLOBALS['wpdb']->clear_failures();
		foreach ( array( 'pending', 'unsubscribed', 'confirmed' ) as $key ) {
			assert_same( null, array_key_exists( $key, $out ) ? $out[ $key ] : 'missing', $key . ': null on a database error, not 0' );
		}
		assert_true( ( isset( $out['failed'] ) ? $out['failed'] : 0 ) >= 4, 'every failed step is counted (limiter buckets, pending, opted-out read, confirmed)' );
		$stage = dbgr_wls_ctx( 'waitlist_purge_failed', 'stage' );
		foreach ( array( 'rate_buckets', 'pending_delete', 'optout_read', 'confirmed_delete' ) as $expected ) {
			assert_contains( $expected, $stage, 'stage ' . $expected . ' is named' );
		}
		assert_same( 3, (int) $GLOBALS['wpdb']->sqlite_raw( 'SELECT COUNT(*) AS n FROM wp_doughboss_growth_waitlist' )[0]['n'], 'nothing was deleted' );
	}
);

db_test(
	'F11 purge: a failed suppression write or a failed DELETE leaves the opted-out row for the next run and is counted as failed; the next clean run removes it and clears the failure',
	function () {
		dbgr_wls_boot();
		$ago = function ( $days ) {
			return gmdate( 'Y-m-d H:i:s', DoughBoss_Growth::now() - ( $days * DAY_IN_SECONDS ) );
		};
		$a = dbgr_wls_seed_person( 1, 'unsubscribed', $ago( 100 ), $ago( 40 ) );
		$b = dbgr_wls_seed_person( 2, 'unsubscribed', $ago( 100 ), $ago( 40 ) );

		// A failed suppression write: neither row may be dropped, and both are counted.
		$GLOBALS['wpdb']->fail_on( '/INSERT IGNORE INTO wp_doughboss_growth_suppression/' );
		$out = DoughBoss_Growth_Waitlist::purge();
		$GLOBALS['wpdb']->clear_failures();
		assert_same( 0, isset( $out['unsubscribed'] ) ? $out['unsubscribed'] : 'missing', 'nothing reduced' );
		assert_same( 2, isset( $out['failed'] ) ? $out['failed'] : 'missing', 'both rows counted as failed' );
		assert_contains( 'suppress', dbgr_wls_ctx( 'waitlist_purge_failed', 'stage' ), 'recorded: suppress' );
		assert_same( 'unsubscribed', dbgr_wls_status( $a['id'] ), 'row A kept (its opt-out is not safely recorded)' );
		assert_same( 'unsubscribed', dbgr_wls_status( $b['id'] ), 'row B kept' );

		// A failed DELETE: the row stays, and is counted.
		DoughBoss_Growth_Failures::clear();
		$GLOBALS['wpdb']->fail_on( "/^DELETE FROM wp_doughboss_growth_waitlist WHERE id = [0-9]+ AND status = 'unsubscribed'/" );
		$out = DoughBoss_Growth_Waitlist::purge();
		$GLOBALS['wpdb']->clear_failures();
		assert_same( 0, isset( $out['unsubscribed'] ) ? $out['unsubscribed'] : 'missing', 'nothing reduced when the DELETE fails' );
		assert_same( 2, isset( $out['failed'] ) ? $out['failed'] : 'missing', 'both counted as failed' );
		assert_contains( 'optout_delete', dbgr_wls_ctx( 'waitlist_purge_failed', 'stage' ), 'recorded: optout_delete' );
		assert_same( 'unsubscribed', dbgr_wls_status( $a['id'] ), 'row A still there for the next run' );

		// The next run, with storage healthy, finishes the job and the failure no longer stands.
		$out = DoughBoss_Growth_Waitlist::purge();
		assert_same( 2, isset( $out['unsubscribed'] ) ? $out['unsubscribed'] : 'missing', 'the next run reduces both' );
		assert_same( 0, isset( $out['failed'] ) ? $out['failed'] : 'missing', 'with nothing failed' );
		assert_same( null, dbgr_wls_status( $a['id'] ), 'row A gone' );
		assert_same( 2, dbgr_wls_suppressed_count(), 'both suppression hashes exist' );
		assert_false( in_array( 'waitlist_purge_failed', dbgr_wls_codes(), true ), 'a clean run clears the earlier failure' );
	}
);

db_test(
	'F11 purge: a delete that finds the row already gone (an erasure got there first) is not a failure; a healthy run returns whole numbers and records nothing (controls)',
	function () {
		dbgr_wls_boot( array( 'retention_confirmed_months' => 12 ) );
		dbgr_wls_seed_expired();
		$out = DoughBoss_Growth_Waitlist::purge();
		assert_same( array( 'pending' => 1, 'unsubscribed' => 1, 'confirmed' => 1, 'failed' => 0 ), $out, 'a healthy run: counts, and nothing failed' );
		assert_same( array(), dbgr_wls_codes(), 'and nothing recorded' );

		$ago = gmdate( 'Y-m-d H:i:s', DoughBoss_Growth::now() - ( 40 * DAY_IN_SECONDS ) );
		$p   = dbgr_wls_seed_person( 9, 'unsubscribed', $ago, $ago );
		$GLOBALS['wpdb']->respond(
			"/^DELETE FROM wp_doughboss_growth_waitlist WHERE id = [0-9]+ AND status = 'unsubscribed'/",
			function () {
				return 0;
			}
		);
		$out = DoughBoss_Growth_Waitlist::purge();
		assert_same( 0, isset( $out['failed'] ) ? $out['failed'] : 'missing', 'a delete that matched no row is not a failure' );
		assert_same( array(), dbgr_wls_codes(), 'and is not recorded' );
		unset( $p );
	}
);

db_test(
	'F11 purge: a failure of the limiter-bucket housekeeping (which holds address hashes) is no longer ignored',
	function () {
		dbgr_wls_boot();
		$old = DoughBoss_Growth::now() - ( 3 * DAY_IN_SECONDS );
		$GLOBALS['wpdb']->sqlite_raw( "INSERT INTO wp_doughboss_growth_rate (bucket_key, window_start, hits) VALUES ('wl:email:" . hash( 'sha256', 'gone@example.com' ) . "', {$old}, 1)" );
		$GLOBALS['wpdb']->fail_on( '/^DELETE FROM wp_doughboss_growth_rate/' );
		$out = DoughBoss_Growth_Waitlist::purge();
		$GLOBALS['wpdb']->clear_failures();
		assert_same( 1, isset( $out['failed'] ) ? $out['failed'] : 'missing', 'counted' );
		assert_same( 'rate_buckets', dbgr_wls_ctx( 'waitlist_purge_failed', 'stage' ), 'recorded with its stage' );
		assert_same( 1, (int) $GLOBALS['wpdb']->sqlite_raw( 'SELECT COUNT(*) AS n FROM wp_doughboss_growth_rate' )[0]['n'], 'the stale bucket is still there (so the failure is real)' );

		DoughBoss_Growth_Failures::clear();
		DoughBoss_Growth_Waitlist::purge();
		assert_same( 0, (int) $GLOBALS['wpdb']->sqlite_raw( 'SELECT COUNT(*) AS n FROM wp_doughboss_growth_rate' )[0]['n'], 'control: the next run removes it' );
		assert_same( array(), dbgr_wls_codes(), 'control: and records nothing' );
	}
);

db_test(
	'F11 cron path and tab: a purge that fails when WP-Cron runs it is visible on the tab as "The last clean-up failed: <code>", and the line goes after a clean run',
	function () {
		dbgr_wls_boot( array( 'retention_confirmed_months' => 12 ) );
		dbgr_wls_seed_expired();
		DoughBoss_Growth_Waitlist::init();

		$tab = dbgr_wls_tab();
		assert_not_contains( 'The last clean-up failed', $tab, 'control: nothing failed yet, nothing said' );

		$GLOBALS['wpdb']->fail_on( "/^DELETE FROM wp_doughboss_growth_waitlist WHERE status = 'confirmed'/" );
		do_action( DoughBoss_Growth_Waitlist::PURGE_HOOK ); // The only caller is WP-Cron, which discards the return value.
		$GLOBALS['wpdb']->clear_failures();
		assert_true( null !== dbgr_wls_failure( 'waitlist_purge_failed' ), 'the cron run recorded its failure' );

		$tab = dbgr_wls_tab();
		assert_contains( 'The last clean-up failed: waitlist_purge_failed', $tab, 'the tab says the last clean-up failed, with the code' );
		assert_contains( 'confirmed_delete', $tab, 'and which step' );

		do_action( DoughBoss_Growth_Waitlist::PURGE_HOOK );
		$tab = dbgr_wls_tab();
		assert_not_contains( 'The last clean-up failed', $tab, 'a clean run takes the line away' );
	}
);

db_test(
	'F11 tab: when the sign-up counts cannot be read, the tab says so instead of leaving the rows out',
	function () {
		dbgr_wls_boot();
		dbgr_wls_seed_person( 1 );
		$tab = dbgr_wls_tab();
		assert_contains( 'Waiting to confirm', $tab, 'control: counts shown when readable' );
		$GLOBALS['wpdb']->fail_on( '/GROUP BY status/' );
		$tab = dbgr_wls_tab();
		$GLOBALS['wpdb']->clear_failures();
		assert_not_contains( 'Waiting to confirm', $tab, 'no invented counts' );
		assert_contains( 'could not be read', $tab, 'the tab says the counts could not be read' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* F10: the staff CSV streams, stays byte-identical, and an incomplete file says so                              */
/* ---------------------------------------------------------------------------------------------------------- */

/**
 * The fixed five-row data set whose CSV was captured from the ORIGINAL (in-memory) implementation.
 *
 * @return void
 */
function dbgr_wls_seed_golden() {
	$rows = array(
		array( 1, 'a@example.com', 'Ann, "A" Lee', '+61412345678', 1, 1, '2026-09-01 01:02:03', '/coming-soon/', 'confirmed', '2026-09-01 01:05:00', null, '2026-09-01 01:02:03' ),
		array( 2, 'b@example.com', "Line1\nLine2", null, 2, 1, '2026-09-02 02:02:03', '', 'pending', null, null, '2026-09-02 02:02:03' ),
		array( 3, 'c@example.com', null, null, null, 1, '2026-09-03 03:02:03', '/coming-soon/', 'unsubscribed', '2026-09-03 03:05:00', '2026-09-04 04:00:00', '2026-09-03 03:02:03' ),
		array( 4, 'd@example.com', '=HYPERLINK("x")', '+61400000000', 3, 0, '2026-09-05 05:02:03', '/p/', 'confirmed', '2026-09-05 05:05:00', null, '2026-09-05 05:02:03' ),
		array( 5, 'e@example.com', "O'Brien @home", null, null, 1, '2026-09-06 06:02:03', '/coming-soon/', 'confirmed', '2026-09-06 06:05:00', null, '2026-09-06 06:02:03' ),
	);
	foreach ( $rows as $r ) {
		$GLOBALS['wpdb']->sqlite_raw( 'INSERT INTO wp_doughboss_growth_waitlist (id, email, email_hash, first_name, mobile_e164, store_pref, consent_marketing, consent_text_version, consent_text_hash, consent_at_utc, consent_source_path, status, confirm_token_hash, confirmed_at_utc, unsubscribed_at_utc, created_at, updated_at) VALUES (' . implode( ', ', array( dbgr_wls_q( $r[0] ), dbgr_wls_q( $r[1] ), dbgr_wls_q( hash( 'sha256', $r[1] ) ), dbgr_wls_q( $r[2] ), dbgr_wls_q( $r[3] ), dbgr_wls_q( $r[4] ), dbgr_wls_q( $r[5] ), "'wl-0123456789ab'", dbgr_wls_q( str_repeat( 'f', 64 ) ), dbgr_wls_q( $r[6] ), dbgr_wls_q( $r[7] ), dbgr_wls_q( $r[8] ), 'NULL', dbgr_wls_q( $r[9] ), dbgr_wls_q( $r[10] ), dbgr_wls_q( $r[11] ), dbgr_wls_q( $r[11] ) ) ) . ')' );
	}
}

/**
 * The golden CSV for a status (captured from the original implementation before it was changed).
 *
 * @param string $status all, confirmed, pending or unsubscribed.
 * @return string
 */
function dbgr_wls_golden( $status ) {
	$header = "id,email,first_name,mobile,store_id,status,consent_marketing,consent_text_version,consent_at_utc,confirmed_at_utc,unsubscribed_at_utc,signup_path,created_at\n";
	$one    = <<<'CSV'
1,a@example.com,"Ann, ""A"" Lee",'+61412345678,1,confirmed,1,wl-0123456789ab,"2026-09-01 01:02:03","2026-09-01 01:05:00",,/coming-soon/,"2026-09-01 01:02:03"
CSV;
	$two    = <<<'CSV'
2,b@example.com,"Line1
Line2",,2,pending,1,wl-0123456789ab,"2026-09-02 02:02:03",,,,"2026-09-02 02:02:03"
CSV;
	$three  = <<<'CSV'
3,c@example.com,,,,unsubscribed,1,wl-0123456789ab,"2026-09-03 03:02:03","2026-09-03 03:05:00","2026-09-04 04:00:00",/coming-soon/,"2026-09-03 03:02:03"
CSV;
	$four   = <<<'CSV'
4,d@example.com,"'=HYPERLINK(""x"")",'+61400000000,3,confirmed,0,wl-0123456789ab,"2026-09-05 05:02:03","2026-09-05 05:05:00",,/p/,"2026-09-05 05:02:03"
CSV;
	$five   = <<<'CSV'
5,e@example.com,"O'Brien @home",,,confirmed,1,wl-0123456789ab,"2026-09-06 06:02:03","2026-09-06 06:05:00",,/coming-soon/,"2026-09-06 06:02:03"
CSV;
	$by     = array(
		'all'          => array( $one, $two, $three, $four, $five ),
		'confirmed'    => array( $one, $four, $five ),
		'pending'      => array( $two ),
		'unsubscribed' => array( $three ),
	);
	return $header . implode( "\n", $by[ $status ] ) . "\n";
}

/**
 * Run the CSV export handler as a manager with a valid nonce and return what it printed.
 *
 * @param string $status Status to export.
 * @return string
 */
function dbgr_wls_download( $status ) {
	dbgr_test_login( array( 'manage_options' ) );
	$_POST    = array( 'status' => $status, '_wpnonce' => dbgr_test_nonce( 'doughboss_growth_export_waitlist' ) );
	$_REQUEST = $_POST;
	ob_start();
	try {
		DoughBoss_Growth_Waitlist::handle_export();
	} catch ( Exception $e ) {
		ob_end_clean();
		throw $e;
	}
	return (string) ob_get_clean();
}

/**
 * Add N bulk rows (ids continue after any existing row), all confirmed.
 *
 * @param int $count Rows to add.
 * @return void
 */
function dbgr_wls_seed_bulk( $count ) {
	$GLOBALS['wpdb']->sqlite_raw( 'WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < ' . (int) $count . ") INSERT INTO wp_doughboss_growth_waitlist (email, email_hash, consent_marketing, consent_text_version, consent_text_hash, consent_at_utc, consent_source_path, status, confirmed_at_utc, created_at, updated_at) SELECT 'bulk' || n || '@example.com', printf('%064d', n), 1, 'wl-test', '" . str_repeat( 'f', 64 ) . "', '2026-09-01 00:00:00', '/', 'confirmed', '2026-09-01 00:00:00', '2026-09-01 00:00:00', '2026-09-01 00:00:00' FROM seq" );
}

/**
 * The first column of every data line of a CSV (the file has no multi-line cells in the bulk set).
 *
 * @param string $csv CSV text.
 * @return array
 */
function dbgr_wls_ids( $csv ) {
	$ids   = array();
	$lines = explode( "\n", rtrim( $csv, "\n" ) );
	array_shift( $lines );
	foreach ( $lines as $line ) {
		$ids[] = substr( $line, 0, (int) strpos( $line, ',' ) );
	}
	return $ids;
}

db_test(
	'F10 CSV: for the same data the file is byte-identical to the original implementation (header, column order, quoting and formula protection), both as built and as downloaded',
	function () {
		dbgr_wls_boot();
		dbgr_wls_seed_golden();
		foreach ( array( 'all', 'confirmed', 'pending', 'unsubscribed' ) as $status ) {
			assert_same( dbgr_wls_golden( $status ), DoughBoss_Growth_Waitlist::build_csv( $status ), 'build_csv(' . $status . ') matches the original bytes' );
			assert_same( dbgr_wls_golden( $status ), dbgr_wls_download( $status ), 'the download for ' . $status . ' matches the original bytes' );
		}
		$all = dbgr_wls_download( 'all' );
		assert_contains( "'=HYPERLINK", $all, 'formula protection is intact in the stream' );
		assert_contains( "'+61400000000", $all, 'a leading plus is still neutralised' );
		assert_not_contains( hash( 'sha256', 'a@example.com' ), $all, 'no hash in the file' );
		assert_same( array(), $GLOBALS['wpdb']->unprepared, 'every statement was prepared' );
	}
);

db_test(
	'F10 CSV: the download streams page by page (500 rows a page): the first page is output before the second is read, every row appears once, in id order, across the page edges',
	function () {
		dbgr_wls_boot();
		dbgr_wls_seed_bulk( 1203 );
		$lengths = array();
		$GLOBALS['wpdb']->respond(
			'/^SELECT \* FROM wp_doughboss_growth_waitlist WHERE id >/',
			function ( $sql ) use ( &$lengths ) {
				$lengths[] = ob_get_length(); // Bytes already sent to the visitor when this page is read.
				return $GLOBALS['wpdb']->sqlite_raw( $sql );
			}
		);
		$csv = dbgr_wls_download( 'all' );
		assert_true( count( $lengths ) >= 3 && count( $lengths ) <= 4, 'three page reads (500, 500, 203), at most one more to see the end (got ' . count( $lengths ) . ')' );
		assert_same( 0, $lengths[0], 'nothing is output before the first page has been read' );
		assert_true( $lengths[1] > 0, 'the first page was already sent when the second was read (streamed, not built in memory)' );
		assert_true( $lengths[2] > $lengths[1], 'and the second page was sent before the third was read' );
		assert_same( array_map( 'strval', range( 1, 1203 ) ), dbgr_wls_ids( $csv ), 'every row appears exactly once, in id order, across the page edges' );
		assert_not_contains( 'ERROR,', $csv, 'a complete export carries no error line' );
		foreach ( $GLOBALS['wpdb']->queries_matching( '/^SELECT \* FROM wp_doughboss_growth_waitlist WHERE id >/' ) as $sql ) {
			assert_contains( 'LIMIT 500', $sql, 'each page read is capped at 500 rows' );
		}
	}
);

db_test(
	'F10 CSV: build_csv gives the same bytes as the download for a multi-page list; a page that fails mid-way is null from build_csv, never a short file',
	function () {
		dbgr_wls_boot();
		dbgr_wls_seed_bulk( 1203 );
		$built = DoughBoss_Growth_Waitlist::build_csv( 'all' );
		assert_same( $built, dbgr_wls_download( 'all' ), 'same bytes through both paths' );
		assert_same( 1204, count( explode( "\n", rtrim( (string) $built, "\n" ) ) ), 'header plus every row' );
		$GLOBALS['wpdb']->fail_on( '/WHERE id > 500\b/' );
		assert_same( null, DoughBoss_Growth_Waitlist::build_csv( 'all' ), 'a failed second page: build_csv is null, not the first 500 rows' );
		$GLOBALS['wpdb']->clear_failures();
	}
);

db_test(
	'F10 CSV: a page that fails mid-stream stops the file and ends it with an ERROR line (so it can never look complete), and the failure is recorded',
	function () {
		dbgr_wls_boot();
		dbgr_wls_seed_bulk( 1203 );
		$GLOBALS['wpdb']->fail_on( '/WHERE id > 500\b/' );
		$csv = dbgr_wls_download( 'all' );
		$GLOBALS['wpdb']->clear_failures();

		$lines = explode( "\n", rtrim( $csv, "\n" ) );
		assert_same( 'id', substr( $lines[0], 0, 2 ), 'the header went out first' );
		assert_same( 500 + 2, count( $lines ), 'header, the 500 rows already sent, and one closing line' );
		$last = $lines[ count( $lines ) - 1 ];
		assert_same( 'ERROR,', substr( $last, 0, 6 ), 'the file ends with an ERROR line' );
		assert_contains( 'incomplete', $last, 'which says the file is incomplete' );
		$ids = dbgr_wls_ids( implode( "\n", array_slice( $lines, 0, 501 ) ) . "\n" );
		assert_same( array_map( 'strval', range( 1, 500 ) ), $ids, 'the rows before the failure are intact and nothing after it was invented' );
		assert_true( null !== dbgr_wls_failure( 'waitlist_csv_export_failed' ), 'the failure is in the owner failure log' );
		assert_same( 'page_read', dbgr_wls_ctx( 'waitlist_csv_export_failed', 'stage' ), 'with its stage' );
		assert_not_contains( '@', json_encode( DoughBoss_Growth_Failures::all() ), 'and no personal data' );
	}
);

db_test(
	'F10 CSV: a page that does not move forward (the same ids again) ends the file with the ERROR line instead of looping or repeating rows',
	function () {
		dbgr_wls_boot();
		dbgr_wls_seed_bulk( 1203 );
		$reads = 0;
		$GLOBALS['wpdb']->respond(
			'/^SELECT \* FROM wp_doughboss_growth_waitlist WHERE id >/',
			function ( $sql ) use ( &$reads ) {
				$reads++;
				if ( $reads > 6 ) {
					return array(); // Safety net so a regression fails this test instead of hanging the run.
				}
				// Every read, whatever the cursor, answers with the FIRST page again.
				return $GLOBALS['wpdb']->sqlite_raw( 'SELECT * FROM wp_doughboss_growth_waitlist WHERE id > 0 ORDER BY id ASC LIMIT 500' );
			}
		);
		$csv = dbgr_wls_download( 'all' );
		assert_same( 2, $reads, 'the stuck second page is noticed straight away (two reads, not a loop)' );
		$lines = explode( "\n", rtrim( $csv, "\n" ) );
		assert_same( 'ERROR,', substr( $lines[ count( $lines ) - 1 ], 0, 6 ), 'the file ends with the ERROR line' );
		assert_same( 500 + 2, count( $lines ), 'header, the first page once, and the ERROR line: no repeated rows' );
		assert_same( 'page_read', dbgr_wls_ctx( 'waitlist_csv_export_failed', 'stage' ), 'and the failure is recorded' );
	}
);

db_test(
	'F10 CSV: when the very first page cannot be read nothing is sent at all (the existing clean 500 error), and the failure is recorded; the same for storage not ready',
	function () {
		dbgr_wls_boot();
		dbgr_wls_seed_golden();
		$GLOBALS['wpdb']->fail_on( '/^SELECT \* FROM wp_doughboss_growth_waitlist WHERE id >/' );
		$printed = '';
		$died    = null;
		ob_start();
		try {
			dbgr_test_login( array( 'manage_options' ) );
			$_POST    = array( 'status' => 'all', '_wpnonce' => dbgr_test_nonce( 'doughboss_growth_export_waitlist' ) );
			$_REQUEST = $_POST;
			DoughBoss_Growth_Waitlist::handle_export();
		} catch ( DBGR_Test_Die $e ) {
			$died = $e;
		}
		$printed = (string) ob_get_clean();
		$GLOBALS['wpdb']->clear_failures();
		assert_true( null !== $died, 'a first-page failure ends in the error page' );
		assert_same( 500, null === $died ? 0 : $died->args['response'], 'status 500' );
		assert_contains( 'Nothing was downloaded', null === $died ? '' : $died->getMessage(), 'the existing wording' );
		assert_same( '', $printed, 'and not one byte of a file was printed' );
		assert_same( 'first_page', dbgr_wls_ctx( 'waitlist_csv_export_failed', 'stage' ), 'recorded with its stage' );

		DoughBoss_Growth_Failures::clear();
		delete_option( DoughBoss_Growth_Activator::DB_VERSION_OPTION );
		$died = null;
		ob_start();
		try {
			DoughBoss_Growth_Waitlist::handle_export();
		} catch ( DBGR_Test_Die $e ) {
			$died = $e;
		}
		$printed = (string) ob_get_clean();
		assert_true( null !== $died, 'storage not ready: the error page, not an empty file' );
		assert_same( '', $printed, 'and nothing printed' );
	}
);

db_test(
	'F10 CSV: a genuinely empty list is a complete header-only file with no error line (an empty list and a failed read are told apart); capability and nonce are still enforced',
	function () {
		dbgr_wls_boot();
		$csv = dbgr_wls_download( 'confirmed' );
		assert_same( 1, count( explode( "\n", rtrim( $csv, "\n" ) ) ), 'header only' );
		assert_not_contains( 'ERROR', $csv, 'no error line for an honestly empty list' );
		assert_same( strtok( dbgr_wls_golden( 'all' ), "\n" ) . "\n", $csv, 'the header is the same bytes as the original implementation wrote' );

		// Negative controls on the way in.
		dbgr_wls_seed_golden();
		foreach ( array( 'visitor' => null, 'editor' => array( 'edit_posts' ) ) as $who => $caps ) {
			dbgr_test_logout();
			if ( null !== $caps ) {
				dbgr_test_login( $caps );
			}
			$_POST    = array( 'status' => 'all', '_wpnonce' => dbgr_test_nonce( 'doughboss_growth_export_waitlist' ) );
			$_REQUEST = $_POST;
			$printed  = '';
			ob_start();
			try {
				DoughBoss_Growth_Waitlist::handle_export();
				$died = false;
			} catch ( DBGR_Test_Die $e ) {
				$died = true;
			}
			$printed = (string) ob_get_clean();
			assert_true( $died, $who . ' with a valid nonce but no capability is refused' );
			assert_same( '', $printed, $who . ': nothing printed' );
		}
		dbgr_test_login( array( 'manage_options' ) );
		foreach ( array( '', 'forged' ) as $nonce ) {
			$_POST    = array( 'status' => 'all', '_wpnonce' => $nonce );
			$_REQUEST = $_POST;
			ob_start();
			try {
				DoughBoss_Growth_Waitlist::handle_export();
				$died = false;
			} catch ( DBGR_Test_Die $e ) {
				$died = true;
			}
			$printed = (string) ob_get_clean();
			assert_true( $died, 'a manager with nonce "' . $nonce . '" is refused' );
			assert_same( '', $printed, 'and nothing printed' );
		}
	}
);
