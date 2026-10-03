<?php
/**
 * Public copy: every line a visitor can read, with its source and whether it is approved.
 *
 * Tier A lines are the owner's own published words and are printed. DRAFT lines print nothing until an
 * administrator ticks them on the settings screen. Nothing outside content/copy.json is ever printed as copy.
 *
 * @package DoughBoss_Growth_Box
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copy registry.
 */
final class DoughBoss_Growth_Box_Copy {

	/**
	 * Cached lines.
	 *
	 * @var array<string, array<string, string>>|null
	 */
	private static $lines = null;

	/**
	 * Forget the cache (tests only).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$lines = null;
	}

	/**
	 * All lines: id => array( text, tier, source ).
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function lines() {
		if ( null !== self::$lines ) {
			return self::$lines;
		}
		self::$lines = array();
		$path        = DBGRBOX_DIR . 'content/copy.json';
		$raw         = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
		$data        = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || ! isset( $data['lines'] ) || ! is_array( $data['lines'] ) ) {
			return self::$lines;
		}
		foreach ( $data['lines'] as $id => $line ) {
			if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-z0-9_]{1,40}$/D', $id ) || ! is_array( $line ) || ! isset( $line['text'], $line['tier'] ) ) {
				continue;
			}
			self::$lines[ $id ] = array(
				'text'   => (string) $line['text'],
				'tier'   => (string) $line['tier'],
				'source' => isset( $line['source'] ) ? (string) $line['source'] : '',
			);
		}
		return self::$lines;
	}

	/**
	 * Ids of the lines that need an approval before they print.
	 *
	 * @return array<int, string>
	 */
	public static function draft_ids() {
		$ids = array();
		foreach ( self::lines() as $id => $line ) {
			if ( 'A' !== $line['tier'] ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Whether a line may print now.
	 *
	 * @param string $id Line id.
	 * @return bool
	 */
	public static function approved( $id ) {
		$lines = self::lines();
		if ( ! isset( $lines[ $id ] ) ) {
			return false;
		}
		if ( 'A' === $lines[ $id ]['tier'] ) {
			return true;
		}
		$approval = DoughBoss_Growth_Box_Settings::approval( $id );
		return ! empty( $approval );
	}

	/**
	 * The text of a line, or an empty string when the line is unknown or not approved. Plain text: escape it on output.
	 *
	 * @param string $id Line id.
	 * @return string
	 */
	public static function text( $id ) {
		if ( ! self::approved( $id ) ) {
			return '';
		}
		$lines = self::lines();
		return $lines[ $id ]['text'];
	}
}
