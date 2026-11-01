<?php
/**
 * Read-only home cards: new catering enquiries and the catering production queue.
 *
 * @package DoughBoss
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DoughBoss_Home {
	/**
	 * Whether the home section is switched on.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) DoughBoss_Settings::get( 'crm_home_enabled', 0 );
	}

	/**
	 * The management section to show for a requested ?section= value.
	 *
	 * Only the exact values count. "home" exists only while the switch is on;
	 * anything else, including "home" with the switch off, is the overview.
	 *
	 * @param mixed $requested The raw request value, or null when absent.
	 * @return string home, crm or overview.
	 */
	public static function management_section( $requested ) {
		if ( ! is_string( $requested ) ) {
			return 'overview';
		}
		if ( 'crm' === $requested ) {
			return 'crm';
		}
		if ( 'home' === $requested && self::is_enabled() ) {
			return 'home';
		}
		return 'overview';
	}

	/**
	 * Count of new catering enquiries, or the reason the read failed.
	 *
	 * A count only: no enquiry rows (and so no customer details) are read for
	 * the home screen. A failed read is unseen, never a count of zero.
	 *
	 * @return array
	 */
	public static function card_new_enquiries() {
		if ( ! DoughBoss_CRM::can_access() ) {
			return array( 'state' => 'unseen', 'reason' => 'doughboss_crm_forbidden' );
		}
		$result = DoughBoss_Catering::status_count_checked( DoughBoss_Catering::STATUS_NEW );
		if ( is_wp_error( $result ) ) {
			return array( 'state' => 'unseen', 'reason' => $result->get_error_code() );
		}
		return array( 'state' => 'live', 'count' => (int) $result, 'read_at' => gmdate( 'c' ) );
	}

	/**
	 * Count of catering jobs booked for production, or the reason the read failed.
	 *
	 * The production queue is deposit paid, confirmed, balance due or paid. A failed
	 * read is unseen, never a count of zero.
	 *
	 * @return array
	 */
	public static function card_production_queue() {
		if ( ! DoughBoss_CRM::can_access() ) {
			return array( 'state' => 'unseen', 'reason' => 'doughboss_crm_forbidden' );
		}
		$result = DoughBoss_Catering::production_queue_count_checked( 0 );
		if ( is_wp_error( $result ) ) {
			return array( 'state' => 'unseen', 'reason' => $result->get_error_code() );
		}
		return array( 'state' => 'live', 'count' => (int) $result, 'read_at' => gmdate( 'c' ) );
	}

	/**
	 * Echo the home section.
	 */
	public static function render() {
		echo '<section class="db-home">';
		echo '<h1>' . esc_html__( 'Home', 'doughboss' ) . '</h1>';
		self::render_card(
			__( 'New catering enquiries', 'doughboss' ),
			self::card_new_enquiries(),
			home_url( '/management/?section=crm&crm_status=new' ),
			__( 'View new enquiries', 'doughboss' ),
			__( 'Unseen — enquiries could not be read', 'doughboss' )
		);
		self::render_card(
			__( 'Catering production queue', 'doughboss' ),
			self::card_production_queue(),
			home_url( '/management/?section=crm' ),
			__( 'View catering jobs', 'doughboss' ),
			__( 'Unseen — the production queue could not be read', 'doughboss' )
		);
		echo '</section>';
	}

	/**
	 * Echo one card: its count and read time when live, or the unseen line.
	 *
	 * @param string $title       Card heading.
	 * @param array  $card        A card_* result.
	 * @param string $link_url    Where the card's link goes.
	 * @param string $link_text   The link's text.
	 * @param string $unseen_text What a person reads when the count could not be read.
	 */
	private static function render_card( $title, array $card, $link_url, $link_text, $unseen_text ) {
		echo '<div class="db-home-card">';
		echo '<h2>' . esc_html( $title ) . '</h2>';
		if ( isset( $card['state'] ) && 'live' === $card['state'] ) {
			echo '<p>' . esc_html( $card['count'] ) . '</p>';
			echo '<p>' . esc_html__( 'Read at', 'doughboss' ) . ' <time>' . esc_html( $card['read_at'] ) . '</time></p>';
			echo '<a href="' . esc_url( $link_url ) . '">' . esc_html( $link_text ) . '</a>';
		} else {
			echo '<p>' . esc_html( $unseen_text ) . '</p>';
		}
		echo '</div>';
	}
}
