<?php
/**
 * DoughBoss Growth waitlist privacy tooling: the WordPress personal-data exporter and eraser.
 *
 * They appear in Tools, Export Personal Data and Erase Personal Data next to core's own (core's privacy class is not
 * touched). They stay registered whether or not the waitlist flag is on, because stored data must stay exportable and
 * erasable after a feature is switched off.
 *
 * Erasure removes the row (every personal field goes). An opt-out stays recorded as a hash on the suppression list so
 * the person is not mailed again; that is reported to WordPress as "retained". A person who never opted out is not added
 * to the suppression list unless the filter doughboss_growth_waitlist_suppress_on_erase returns true.
 *
 * Silence rule: a list that cannot be read is never reported as "nothing found". WordPress counts an exporter that
 * returns done as complete, so on a failed read (or storage that is not ready) the exporter puts ONE visible item in the
 * export saying the list could not be read and to run the export again, and the eraser reports the entry as retained with
 * a message. Each is also noted in the owner failure list (stage only, never personal data).
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exporter and eraser.
 */
final class DoughBoss_Growth_Waitlist_Privacy {

	/**
	 * Registry key shared by the exporter and the eraser.
	 */
	const KEY = 'doughboss-growth-waitlist';

	/**
	 * Hook the exporter and eraser. Idempotent.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Add the exporter.
	 *
	 * @param mixed $exporters Registered exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		if ( ! is_array( $exporters ) ) {
			$exporters = array();
		}
		$exporters[ self::KEY ] = array(
			'exporter_friendly_name' => __( 'VIP waitlist', 'doughboss-growth' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Add the eraser.
	 *
	 * @param mixed $erasers Registered erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		if ( ! is_array( $erasers ) ) {
			$erasers = array();
		}
		$erasers[ self::KEY ] = array(
			'eraser_friendly_name' => __( 'VIP waitlist', 'doughboss-growth' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * The email hash for a request address, or an empty string when it is not a usable address.
	 *
	 * @param mixed $email_address Address from the privacy request.
	 * @return string
	 */
	private static function hash_for( $email_address ) {
		$email = DoughBoss_Growth_Waitlist::normalise_email( $email_address );
		return ( '' === $email ) ? '' : DoughBoss_Growth_Waitlist::email_hash( $email );
	}

	/**
	 * Exporter callback.
	 *
	 * @param string $email_address Address from the request.
	 * @param int    $page          Page (one page is always enough: one row per address).
	 * @return array { data: array, done: bool }
	 */
	public static function export( $email_address, $page = 1 ) {
		unset( $page );
		$out  = array(
			'data' => array(),
			'done' => true,
		);
		$hash = self::hash_for( $email_address );
		if ( '' === $hash ) {
			return $out; // Not a usable address: nothing to look for.
		}
		if ( ! DoughBoss_Growth_Activator::storage_ready() ) {
			return self::export_unreadable( 'storage_not_ready' );
		}
		$row = DoughBoss_Growth_Waitlist::row_by_hash( $hash );
		if ( false === $row ) {
			return self::export_unreadable( 'row_read' ); // A database error is not "this person is not on the list".
		}
		if ( ! is_array( $row ) ) {
			return $out; // Read fine, and the person is not on the list.
		}
		$fields = array(
			array( __( 'Email', 'doughboss-growth' ), $row['email'] ),
			array( __( 'First name', 'doughboss-growth' ), $row['first_name'] ),
			array( __( 'Mobile', 'doughboss-growth' ), $row['mobile_e164'] ),
			array( __( 'Preferred shop (shop number)', 'doughboss-growth' ), $row['store_pref'] ),
			array( __( 'Marketing consent given', 'doughboss-growth' ), ( 1 === (int) $row['consent_marketing'] ) ? __( 'Yes', 'doughboss-growth' ) : __( 'No', 'doughboss-growth' ) ),
			array( __( 'Consent wording version', 'doughboss-growth' ), $row['consent_text_version'] ),
			array( __( 'Consent given at (UTC)', 'doughboss-growth' ), $row['consent_at_utc'] ),
			array( __( 'Page the sign-up was made on', 'doughboss-growth' ), $row['consent_source_path'] ),
			array( __( 'Status', 'doughboss-growth' ), $row['status'] ),
			array( __( 'Confirmed at (UTC)', 'doughboss-growth' ), $row['confirmed_at_utc'] ),
			array( __( 'Unsubscribed at (UTC)', 'doughboss-growth' ), $row['unsubscribed_at_utc'] ),
			array( __( 'Signed up at (UTC)', 'doughboss-growth' ), $row['created_at'] ),
		);
		$data   = array();
		foreach ( $fields as $field ) {
			if ( null === $field[1] || '' === (string) $field[1] ) {
				continue;
			}
			$data[] = array(
				'name'  => $field[0],
				'value' => (string) $field[1],
			);
		}
		$out['data'][] = array(
			'group_id'    => self::KEY,
			'group_label' => __( 'VIP waitlist', 'doughboss-growth' ),
			'item_id'     => 'waitlist-' . (int) $row['id'],
			'data'        => $data,
		);
		return $out;
	}

	/**
	 * The export for a list that could not be read: one visible item that says so (WordPress would otherwise count the
	 * exporter as complete and leave the person's waitlist data out of the file with no sign), and a note for the owner.
	 * The exporter still reports done, or WordPress would ask for the next page for ever.
	 *
	 * @param string $stage Where it failed (storage_not_ready or row_read).
	 * @return array { data: array, done: bool }
	 */
	private static function export_unreadable( $stage ) {
		DoughBoss_Growth_Waitlist::note_failure( 'waitlist_privacy_export_failed', array( 'stage' => $stage ) );
		return array(
			'data' => array(
				array(
					'group_id'    => self::KEY,
					'group_label' => __( 'VIP waitlist', 'doughboss-growth' ),
					'item_id'     => 'waitlist-unreadable',
					'data'        => array(
						array(
							'name'  => __( 'VIP waitlist', 'doughboss-growth' ),
							'value' => __( 'The VIP waitlist could not be read, please run the export again.', 'doughboss-growth' ),
						),
					),
				),
			),
			'done' => true,
		);
	}

	/**
	 * Eraser callback.
	 *
	 * @param string $email_address Address from the request.
	 * @param int    $page          Page.
	 * @return array { items_removed: bool, items_retained: bool, messages: array, done: bool }
	 */
	public static function erase( $email_address, $page = 1 ) {
		global $wpdb;
		unset( $page );
		$result = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
		$hash   = self::hash_for( $email_address );
		if ( '' === $hash ) {
			return $result; // Not a usable address: nothing to erase.
		}
		$unreadable = __( 'The VIP waitlist could not be read just now, so nothing was erased. Please try again.', 'doughboss-growth' );
		if ( ! DoughBoss_Growth_Activator::storage_ready() ) {
			DoughBoss_Growth_Waitlist::note_failure( 'waitlist_erase_failed', array( 'stage' => 'storage_not_ready' ) );
			$result['items_retained'] = true;
			$result['messages'][]     = $unreadable;
			return $result;
		}
		$row = DoughBoss_Growth_Waitlist::row_by_hash( $hash );
		if ( false === $row ) {
			DoughBoss_Growth_Waitlist::note_failure( 'waitlist_erase_failed', array( 'stage' => 'row_read' ) );
			$result['items_retained'] = true;
			$result['messages'][]     = $unreadable;
			return $result;
		}
		if ( is_array( $row ) ) {
			$table = DoughBoss_Growth_Waitlist::table();
			$gone  = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d", (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( 1 === (int) $gone ) {
				$result['items_removed'] = true;
			} else {
				DoughBoss_Growth_Waitlist::note_failure( 'waitlist_erase_failed', array( 'stage' => 'delete' ) );
				$result['items_retained'] = true;
				$result['messages'][]     = __( 'The VIP waitlist entry could not be removed just now. Please try again.', 'doughboss-growth' );
				return $result;
			}
		}

		// An opt-out must outlive the erasure, or the person could be mailed again. Anyone else is left off the
		// list unless the owner decides otherwise (a filter, because that is an owner and legal decision).
		$suppressed = DoughBoss_Growth_Waitlist::is_suppressed( $hash );
		if ( null === $suppressed ) {
			// The opt-out list could not be read: do not claim there is nothing on it.
			DoughBoss_Growth_Waitlist::note_failure( 'waitlist_erase_failed', array( 'stage' => 'suppression_read' ) );
			$result['items_retained'] = true;
			$result['messages'][]     = __( 'The opt-out list could not be read just now. If this address had opted out, a one-way hash of it is still kept so that no more messages are sent to it.', 'doughboss-growth' );
			return $result;
		}
		if ( true !== $suppressed && true === apply_filters( 'doughboss_growth_waitlist_suppress_on_erase', false ) ) {
			if ( DoughBoss_Growth_Waitlist::suppress( $hash, 'erased' ) ) {
				$suppressed = true;
			} else {
				DoughBoss_Growth_Waitlist::note_failure( 'waitlist_erase_failed', array( 'stage' => 'suppress' ) );
				$result['messages'][] = __( 'The opt-out list could not be updated just now, so this address was not added to it.', 'doughboss-growth' );
			}
		}
		if ( true === $suppressed ) {
			$result['items_retained'] = true;
			$result['messages'][]     = __( 'A one-way hash of this address is kept on the opt-out list so that no more messages are sent to it.', 'doughboss-growth' );
		}
		return $result;
	}
}
