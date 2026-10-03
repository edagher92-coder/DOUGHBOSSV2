<?php
/**
 * Settings: one option, one switch per feature. Every feature switch is off until the owner turns it on. The only
 * switch that starts ticked is the "Concept preview" label on the hero video, so the label is there the moment the
 * video is switched on (the owner can untick it on the settings screen).
 *
 * @package DoughBoss_Growth_Box
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Switch and approval storage.
 */
final class DoughBoss_Growth_Box_Settings {

	/**
	 * Option holding the switches (key => 1) and the copy approvals.
	 */
	const OPTION = 'doughboss_growth_box';

	/**
	 * Option holding the id of the draft story page the admin button created.
	 */
	const PAGE_OPTION = 'doughboss_growth_box_page_id';

	/**
	 * The switches, in screen order. Key => array( label, help ).
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function switches() {
		return array(
			'story'         => array(
				__( 'Story page', 'doughboss-growth-box' ),
				__( 'Shows the long-form box story wherever the story shortcode is placed. Administrators can always preview it.', 'doughboss-growth-box' ),
			),
			'home_band'     => array(
				__( 'Home band', 'doughboss-growth-box' ),
				__( 'A slim band under the home hero.', 'doughboss-growth-box' ),
			),
			'catering_band' => array(
				__( 'Catering band', 'doughboss-growth-box' ),
				__( 'A slim band under the catering page hero.', 'doughboss-growth-box' ),
			),
			'band_image'    => array(
				__( 'Concept image in the bands', 'doughboss-growth-box' ),
				__( 'Adds the concept picture of the box to the bands. While it is a concept picture the page carries a "Concept preview" chip and is hidden from search engines (noindex), which includes the home page. Leave this off to keep the bands text only.', 'doughboss-growth-box' ),
			),
			'strip'         => array(
				__( 'Site-wide strip', 'doughboss-growth-box' ),
				__( 'A "Feed the whole table." strip with the liner pattern above the footer on every public page. Also switches on the strip, badge and divider shortcodes. Text and pattern only, no photo.', 'doughboss-growth-box' ),
			),
			'home_hero_video' => array(
				__( 'Home hero video', 'doughboss-growth-box' ),
				__( 'Plays a short, silent, looping video in the home page hero (the home hero only). Needs the five MP4 files in the Media Library (or inside the media plugin) and the first-frame picture from DoughBoss Growth Media 0.2.0; until both are found it stays off by itself and the photo hero is untouched. The video and its picture show AI-generated food: showing them is the owner\'s own decision of 3 October 2026, so they are a concept and carry the label below. This switch does not make the home page noindex.', 'doughboss-growth-box' ),
			),
			'hero_chip'     => array(
				__( 'Show "Concept preview" label on the hero video', 'doughboss-growth-box' ),
				__( 'A small label in a corner of the hero while the video is on. Ticked by default. The label is what tells visitors the picture is a concept, so untick it only when the hero shows a real photograph or real footage.', 'doughboss-growth-box' ),
			),
		);
	}

	/**
	 * Switches that count as on before the owner has ever saved the screen, and for a switch added by a later release.
	 * Everything not listed here defaults to off.
	 *
	 * @return array<string, int>
	 */
	public static function defaults() {
		return array(
			'hero_chip' => 1,
		);
	}

	/**
	 * Whether the kill switch is set in wp-config.php.
	 *
	 * @return bool
	 */
	public static function disabled() {
		return defined( 'DBGRBOX_DISABLE' ) && true === (bool) DBGRBOX_DISABLE;
	}

	/**
	 * The stored settings, always an array.
	 *
	 * @return array<string, mixed>
	 */
	public static function stored() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Whether a switch is on. Off unless the option says exactly 1 (or, for a key never saved, unless self::defaults()
	 * says 1), and always off under the kill switch.
	 *
	 * @param string $key Switch key.
	 * @return bool
	 */
	public static function on( $key ) {
		if ( self::disabled() || ! array_key_exists( $key, self::switches() ) ) {
			return false;
		}
		$stored = self::stored();
		if ( ! array_key_exists( $key, $stored ) ) {
			$defaults = self::defaults();
			return isset( $defaults[ $key ] ) && 1 === $defaults[ $key ];
		}
		return 1 === $stored[ $key ] || '1' === $stored[ $key ] || true === $stored[ $key ];
	}

	/**
	 * Whether a shortcode may render for the current visitor: its switch is on, or the visitor is an
	 * administrator previewing it (capability manage_options). The kill switch overrides both.
	 *
	 * @param string $key Switch key.
	 * @return bool
	 */
	public static function can_render( $key ) {
		if ( self::disabled() ) {
			return false;
		}
		return self::on( $key ) || current_user_can( 'manage_options' );
	}

	/**
	 * Whether a copy line has been approved by name. Returns the approval record or an empty array.
	 *
	 * @param string $line_id Copy line id.
	 * @return array<string, string>
	 */
	public static function approval( $line_id ) {
		$stored = self::stored();
		if ( isset( $stored['approved'][ $line_id ] ) && is_array( $stored['approved'][ $line_id ] ) && ! empty( $stored['approved'][ $line_id ]['date'] ) ) {
			return $stored['approved'][ $line_id ];
		}
		return array();
	}

	/**
	 * Build the new option value from a submitted form. Pure: no capability or nonce logic here, the caller does that.
	 *
	 * @param array<string, mixed> $post     Unslashed form values: sw[key]=1, approve[line]=1.
	 * @param array<string, mixed> $existing Current stored option.
	 * @param array<int, string>   $draft_ids Ids of the DRAFT copy lines that may be approved.
	 * @param string               $who      User login recording the approval.
	 * @param string               $today    Y-m-d.
	 * @return array<string, mixed>
	 */
	public static function build( array $post, array $existing, array $draft_ids, $who, $today ) {
		$new = array();
		$sw  = ( isset( $post['sw'] ) && is_array( $post['sw'] ) ) ? $post['sw'] : array();
		foreach ( array_keys( self::switches() ) as $key ) {
			$new[ $key ] = ( isset( $sw[ $key ] ) && '1' === (string) $sw[ $key ] ) ? 1 : 0;
		}
		$ap       = ( isset( $post['approve'] ) && is_array( $post['approve'] ) ) ? $post['approve'] : array();
		$approved = array();
		foreach ( $draft_ids as $line_id ) {
			if ( ! isset( $ap[ $line_id ] ) || '1' !== (string) $ap[ $line_id ] ) {
				continue;
			}
			if ( isset( $existing['approved'][ $line_id ]['date'] ) ) {
				$approved[ $line_id ] = $existing['approved'][ $line_id ];
			} else {
				$approved[ $line_id ] = array(
					'date' => (string) $today,
					'by'   => (string) $who,
				);
			}
		}
		$new['approved'] = $approved;
		return $new;
	}
}
