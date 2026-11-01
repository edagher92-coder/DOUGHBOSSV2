<?php
/**
 * Read-only home card for new catering enquiries.
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
	 * Count of new catering enquiries, or the reason the read failed.
	 *
	 * @return array
	 */
	public static function card_new_enquiries() {
		$result = DoughBoss_CRM::inbox( array( 'crm_status' => 'new' ) );
		if ( is_wp_error( $result ) ) {
			return array( 'state' => 'unseen', 'reason' => $result->get_error_code() );
		}
		return array( 'state' => 'live', 'count' => (int) $result['total'], 'read_at' => gmdate( 'c' ) );
	}

	/**
	 * Echo the home section.
	 */
	public static function render() {
		$card = self::card_new_enquiries();

		echo '<section class="db-home">';
		echo '<h1>' . esc_html__( 'Home', 'doughboss' ) . '</h1>';
		echo '<div class="db-home-card">';
		echo '<h2>' . esc_html__( 'New catering enquiries', 'doughboss' ) . '</h2>';
		if ( 'live' === $card['state'] ) {
			echo '<p>' . esc_html( $card['count'] ) . '</p>';
			echo '<p>' . esc_html__( 'Read at', 'doughboss' ) . ' <time>' . esc_html( $card['read_at'] ) . '</time></p>';
			echo '<a href="' . esc_url( home_url( '/management/?section=crm&crm_status=new' ) ) . '">' . esc_html__( 'View new enquiries', 'doughboss' ) . '</a>';
		} else {
			echo '<p>' . esc_html__( 'Unseen — enquiries could not be read', 'doughboss' ) . '</p>';
		}
		echo '</div>';
		echo '</section>';
	}
}
