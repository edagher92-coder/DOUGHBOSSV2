<?php
/**
 * The image manifest: where the media plugin keeps its files and what each slot is allowed to show.
 *
 * Each slot declares a status ("concept" or "real") and whether it shows AI-generated food (ai_food). The rules
 * live here, in one place, so a later mistake in a template cannot publish AI food:
 *
 * - a slot with ai_food true is never rendered unless its status is "real";
 * - a slot is rendered only if every file it names exists and the fallback file has the declared size;
 * - anything else resolves to a state other than "ok" and the caller prints nothing for it (a text-only section).
 *
 * @package DoughBoss_Growth_Box
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manifest reader and slot resolver.
 */
final class DoughBoss_Growth_Box_Manifest {

	/**
	 * Slot states.
	 */
	const OK            = 'ok';
	const MISSING       = 'missing';
	const BLOCKED_FOOD  = 'blocked: ai_food';
	const INVALID       = 'invalid';
	const NO_MEDIA      = 'no media plugin';

	/**
	 * Image formats in source order, with their MIME type.
	 *
	 * @var array<string, string>
	 */
	private static $formats = array(
		'avif' => 'image/avif',
		'webp' => 'image/webp',
		'jpg'  => 'image/jpeg',
		'png'  => 'image/png',
	);

	/**
	 * Fallback preference for the img element.
	 *
	 * @var array<int, string>
	 */
	private static $fallback_order = array( 'jpg', 'png', 'webp', 'avif' );

	/**
	 * Cached manifest data.
	 *
	 * @var array<string, mixed>|null|false
	 */
	private static $data = null;

	/**
	 * Cached resolved slots.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static $resolved = array();

	/**
	 * Override for tests: array( dir, url ). Null in production.
	 *
	 * @var array<string, string>|null
	 */
	private static $override = null;

	/**
	 * Forget every cache (tests only).
	 *
	 * @param array<string, string>|null $override Optional array( 'dir' => ..., 'url' => ... ).
	 * @return void
	 */
	public static function reset( $override = null ) {
		self::$data     = null;
		self::$resolved = array();
		self::$override = $override;
	}

	/**
	 * The media location: array( dir, url ), both without a trailing slash.
	 *
	 * The default is the DoughBoss Growth Media plugin's assets/box folder. Phase 2 swaps images by uploading a newer
	 * media zip over it, so no code changes. A different location (a CDN, another plugin folder, a child theme) can be
	 * supplied with the filters doughboss_growth_box_media_dir and doughboss_growth_box_media_url.
	 *
	 * @return array<string, string>
	 */
	public static function media() {
		if ( null !== self::$override ) {
			return self::$override;
		}
		$plugin_dir = ( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : dirname( DBGRBOX_DIR ) ) . '/doughboss-growth-media';
		$dir        = apply_filters( 'doughboss_growth_box_media_dir', $plugin_dir . '/assets/box' );
		$url        = apply_filters( 'doughboss_growth_box_media_url', plugins_url( 'assets/box', $plugin_dir . '/doughboss-growth-media.php' ) );
		return array(
			'dir' => untrailingslashit( is_string( $dir ) ? $dir : '' ),
			'url' => untrailingslashit( is_string( $url ) ? $url : '' ),
		);
	}

	/**
	 * The decoded manifest, or null when the media plugin or its manifest is missing or unreadable.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function data() {
		if ( null !== self::$data ) {
			return false === self::$data ? null : self::$data;
		}
		self::$data = false;
		$media      = self::media();
		if ( '' === $media['dir'] || '' === $media['url'] ) {
			return null;
		}
		$path = $media['dir'] . '/manifest.json';
		$raw  = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local media plugin file.
		$json = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( is_array( $json ) && isset( $json['version'] ) && 1 === (int) $json['version'] && isset( $json['slots'] ) && is_array( $json['slots'] ) ) {
			self::$data = $json;
			return self::$data;
		}
		return null;
	}

	/**
	 * Ids of every declared slot.
	 *
	 * @return array<int, string>
	 */
	public static function slot_ids() {
		$data = self::data();
		return null === $data ? array() : array_map( 'strval', array_keys( $data['slots'] ) );
	}

	/**
	 * A file name from the manifest is safe if it is a plain file name.
	 *
	 * @param mixed $name File name.
	 * @return bool
	 */
	private static function safe_name( $name ) {
		return is_string( $name ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,120}$/D', $name ) && false === strpos( $name, '..' );
	}

	/**
	 * Resolve a slot: state, the cleaned slot and, when it is renderable, the picture description.
	 *
	 * @param string $id Slot id.
	 * @return array<string, mixed> Keys: state (string), status (string), ai_food (bool), slot (array|null), picture (array|null).
	 */
	public static function resolve( $id ) {
		if ( isset( self::$resolved[ $id ] ) ) {
			return self::$resolved[ $id ];
		}
		self::$resolved[ $id ] = self::compute( $id );
		return self::$resolved[ $id ];
	}

	/**
	 * Compute a slot (uncached).
	 *
	 * @param string $id Slot id.
	 * @return array<string, mixed>
	 */
	private static function compute( $id ) {
		$out  = array(
			'state'   => self::NO_MEDIA,
			'status'  => '',
			'ai_food' => false,
			'kind'    => '',
			'slot'    => null,
			'picture' => null,
		);
		$data = self::data();
		if ( null === $data ) {
			return $out;
		}
		if ( ! isset( $data['slots'][ $id ] ) || ! is_array( $data['slots'][ $id ] ) ) {
			$out['state'] = self::MISSING;
			return $out;
		}
		$slot = $data['slots'][ $id ];

		$status  = isset( $slot['status'] ) ? (string) $slot['status'] : '';
		$ai_food = isset( $slot['ai_food'] ) ? $slot['ai_food'] : null;
		if ( ! in_array( $status, array( 'concept', 'real' ), true ) || ! is_bool( $ai_food ) ) {
			// A slot that does not declare both facts is not trusted: treat it as AI food until it says otherwise.
			$out['state'] = self::INVALID;
			return $out;
		}
		$out['status']  = $status;
		$out['ai_food'] = $ai_food;
		$out['kind']    = isset( $slot['kind'] ) ? (string) $slot['kind'] : '';

		// The AI-food rule: never render AI food unless it has been replaced by a real photograph.
		if ( true === $ai_food && 'real' !== $status ) {
			$out['state'] = self::BLOCKED_FOOD;
			return $out;
		}

		$picture = self::build_picture( $slot );
		if ( null === $picture ) {
			$out['state'] = self::MISSING;
			return $out;
		}
		if ( 'invalid' === $picture ) {
			$out['state'] = self::INVALID;
			return $out;
		}
		$out['state']   = self::OK;
		$out['slot']    = $slot;
		$out['picture'] = $picture;
		return $out;
	}

	/**
	 * Check every named file exists and build the picture description.
	 *
	 * @param array<string, mixed> $slot Slot.
	 * @return array<string, mixed>|string|null Picture array, "invalid" for a bad declaration, null for missing files.
	 */
	private static function build_picture( array $slot ) {
		$media = self::media();
		$w     = isset( $slot['w'] ) ? (int) $slot['w'] : 0;
		$h     = isset( $slot['h'] ) ? (int) $slot['h'] : 0;
		if ( $w < 1 || $h < 1 ) {
			return 'invalid';
		}

		// A slot names its files either as variants (format => [[width, file], ...]) or, for a single-file decor
		// asset, as files (format => file).
		$variants = array();
		if ( isset( $slot['variants'] ) && is_array( $slot['variants'] ) ) {
			foreach ( self::$formats as $fmt => $mime ) {
				if ( empty( $slot['variants'][ $fmt ] ) || ! is_array( $slot['variants'][ $fmt ] ) ) {
					continue;
				}
				foreach ( $slot['variants'][ $fmt ] as $pair ) {
					if ( ! is_array( $pair ) || 2 !== count( $pair ) || ! is_numeric( $pair[0] ) || ! self::safe_name( $pair[1] ) ) {
						return 'invalid';
					}
					$variants[ $fmt ][] = array( (int) $pair[0], (string) $pair[1] );
				}
			}
		} elseif ( isset( $slot['files'] ) && is_array( $slot['files'] ) ) {
			foreach ( self::$formats as $fmt => $mime ) {
				if ( isset( $slot['files'][ $fmt ] ) ) {
					if ( ! self::safe_name( $slot['files'][ $fmt ] ) ) {
						return 'invalid';
					}
					$variants[ $fmt ][] = array( $w, (string) $slot['files'][ $fmt ] );
				}
			}
		}
		if ( empty( $variants ) ) {
			return null;
		}

		foreach ( $variants as $list ) {
			foreach ( $list as $pair ) {
				if ( ! is_file( $media['dir'] . '/' . $pair[1] ) ) {
					return null;
				}
			}
		}

		// The img element: the preferred fallback format at the declared width.
		$fallback_fmt = '';
		foreach ( self::$fallback_order as $fmt ) {
			if ( isset( $variants[ $fmt ] ) ) {
				$fallback_fmt = $fmt;
				break;
			}
		}
		$fallback = null;
		foreach ( $variants[ $fallback_fmt ] as $pair ) {
			if ( $pair[0] === $w ) {
				$fallback = $pair;
				break;
			}
		}
		if ( null === $fallback ) {
			return 'invalid';
		}

		// The declared size must be the real size of the fallback file.
		if ( function_exists( 'getimagesize' ) ) {
			$size = @getimagesize( $media['dir'] . '/' . $fallback[1] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable file is handled below.
			if ( ! is_array( $size ) || abs( (int) $size[0] - $w ) > 1 || abs( (int) $size[1] - $h ) > 1 ) {
				return 'invalid';
			}
		}

		$sources = array();
		foreach ( self::$formats as $fmt => $mime ) {
			if ( ! isset( $variants[ $fmt ] ) ) {
				continue;
			}
			$parts = array();
			foreach ( $variants[ $fmt ] as $pair ) {
				$parts[] = $media['url'] . '/' . $pair[1] . ' ' . $pair[0] . 'w';
			}
			$sources[] = array(
				'type'   => $mime,
				'srcset' => implode( ', ', $parts ),
			);
		}
		return array(
			'sources' => $sources,
			'src'     => $media['url'] . '/' . $fallback[1],
			'srcset'  => self::srcset_for( $variants[ $fallback_fmt ], $media['url'] ),
			'width'   => $w,
			'height'  => $h,
			'alt'     => isset( $slot['alt'] ) ? (string) $slot['alt'] : '',
			'files'   => $variants,
		);
	}

	/**
	 * The srcset string of one format list.
	 *
	 * @param array<int, array<int, mixed>> $list Pairs of width and file.
	 * @param string                        $url  URL base.
	 * @return string
	 */
	private static function srcset_for( array $list, $url ) {
		$parts = array();
		foreach ( $list as $pair ) {
			$parts[] = $url . '/' . $pair[1] . ' ' . $pair[0] . 'w';
		}
		return implode( ', ', $parts );
	}

	/**
	 * The URL of one named file in a single-file slot (for example the liner tile), or an empty string.
	 *
	 * @param string $id     Slot id.
	 * @param string $format png, webp, jpg or avif.
	 * @return string
	 */
	public static function file_url( $id, $format ) {
		$slot = self::resolve( $id );
		if ( self::OK !== $slot['state'] || ! isset( $slot['picture']['files'][ $format ][0][1] ) ) {
			return '';
		}
		$media = self::media();
		return $media['url'] . '/' . $slot['picture']['files'][ $format ][0][1];
	}

	/**
	 * Whether every slot in the list resolves OK and is "real". Used to decide whether a story page may be indexed.
	 *
	 * @param array<int, string> $ids Slot ids.
	 * @return bool
	 */
	public static function all_real( array $ids ) {
		foreach ( $ids as $id ) {
			$r = self::resolve( $id );
			if ( self::OK !== $r['state'] || 'real' !== $r['status'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a slot would render as a concept image right now.
	 *
	 * @param string $id Slot id.
	 * @return bool
	 */
	public static function renders_concept( $id ) {
		$r = self::resolve( $id );
		return self::OK === $r['state'] && 'concept' === $r['status'];
	}
}
