<?php
/**
 * Hero video media: finds the five hero videos and the first-frame posters, and remembers what it found.
 *
 * Why this exists: the host caps a browser upload at 2 MB, so the five MP4 files (1.4 to 1.9 MB each) cannot travel in
 * the media plugin zip. Elie uploads them under Media, Add New, and this class finds them again by file name.
 *
 * Where it looks, in order, for each of the five videos:
 *
 * 1. the media plugin's assets/hero folder (a host with no upload cap can ship everything in one zip, and a file there
 *    always wins);
 * 2. the Media Library, by file name stem. WordPress renames a repeat upload (dbgr-hero-loop-720-av1-1.mp4) and may add
 *    "-scaled", so the stem is matched with an optional "-1" / "-2" / "-scaled" tail. The newest upload wins.
 *
 * The two posters (first frame of the video, WebP) are read from the media plugin's assets/hero folder only.
 *
 * The answer, and a marker saying the core markup was not recognised, are cached in one transient. It is cleared when settings are saved, when an attachment is added, edited or
 * deleted, and when a plugin is installed, activated or deactivated. It also expires after a day and is dropped when the
 * site address changes, so a database copied from a staging site cannot keep serving staging URLs.
 *
 * @package DoughBoss_Growth_Box
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hero media lookup.
 */
final class DoughBoss_Growth_Box_Hero_Media {

	/**
	 * Transient holding the lookup.
	 */
	const TRANSIENT = 'dbgrbox_hero_media';

	/**
	 * Shape of the cached value. Bump it to drop every old cache entry.
	 */
	const CACHE_VERSION = 1;

	/**
	 * The five videos: key => file name stem (no extension). The key is "codec-height" and is also the name of the
	 * data attribute the script reads (data-av1-720).
	 *
	 * @return array<string, string>
	 */
	public static function video_stems() {
		return array(
			'av1-720'   => 'dbgr-hero-loop-720-av1',
			'av1-1080'  => 'dbgr-hero-loop-1080-av1',
			'hevc-720'  => 'dbgr-hero-loop-720-hevc',
			'hevc-1080' => 'dbgr-hero-loop-1080-hevc',
			'h264-720'  => 'dbgr-hero-loop-720-h264',
		);
	}

	/**
	 * The posters in the media plugin: key => array( file name, required ). The 1080 WebP is the picture the hero shows
	 * before (and instead of) the video, so the feature stays off without it. The 720 WebP is shipped for a later
	 * phone-size switch and is not needed.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function poster_files() {
		return array(
			'webp-1080' => array( 'dbgr-hero-poster-1080.webp', true ),
			'webp-720'  => array( 'dbgr-hero-poster-720.webp', false ),
		);
	}

	/**
	 * The media plugin's hero folder: array( dir, url ), both without a trailing slash. Filters let a different place
	 * (a CDN, a child theme) be used, the same way as the box images.
	 *
	 * @return array<string, string>
	 */
	public static function location() {
		$plugin_dir = ( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : dirname( DBGRBOX_DIR ) ) . '/doughboss-growth-media';
		$dir        = apply_filters( 'doughboss_growth_box_hero_dir', $plugin_dir . '/assets/hero' );
		$url        = apply_filters( 'doughboss_growth_box_hero_url', plugins_url( 'assets/hero', $plugin_dir . '/doughboss-growth-media.php' ) );
		return array(
			'dir' => untrailingslashit( is_string( $dir ) ? $dir : '' ),
			'url' => untrailingslashit( is_string( $url ) ? $url : '' ),
		);
	}

	/**
	 * Per-request copy of the lookup.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $memo = null;

	/**
	 * Register the cache-clearing hooks. Called on every request, even under the kill switch, so a stale answer can
	 * never outlive a change to the Media Library.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'add_attachment', array( __CLASS__, 'flush' ) );
		add_action( 'edit_attachment', array( __CLASS__, 'flush' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'flush' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'flush' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'flush' ) );
	}

	/**
	 * Forget the cached lookup. Safe to call with any arguments (hooks pass an id or an array).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$memo = null;
		delete_transient( self::TRANSIENT );
	}

	/**
	 * What identifies this site, so a copied database does not keep another site's URLs.
	 *
	 * @return string
	 */
	private static function site_key() {
		return md5( home_url( '/' ) . '|' . content_url() . '|' . plugins_url() );
	}

	/**
	 * What was found: array( videos, posters, site, v ). videos and posters map a key to array( url, source, bytes, id ).
	 *
	 * @return array<string, mixed>
	 */
	public static function find() {
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['v'], $cached['site'], $cached['videos'], $cached['posters'] ) && self::CACHE_VERSION === (int) $cached['v'] && self::site_key() === $cached['site'] ) {
			self::$memo = $cached;
			return self::$memo;
		}
		$found = array(
			'v'       => self::CACHE_VERSION,
			'site'    => self::site_key(),
			'videos'  => self::scan_videos(),
			'posters' => self::scan_posters(),
		);
		set_transient( self::TRANSIENT, $found, DAY_IN_SECONDS );
		self::$memo = $found;
		return self::$memo;
	}

	/**
	 * The media plugin's own files, then the Media Library.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function scan_videos() {
		$out   = array();
		$stems = self::video_stems();
		$where = self::location();
		if ( '' !== $where['dir'] && '' !== $where['url'] ) {
			foreach ( $stems as $key => $stem ) {
				$path = $where['dir'] . '/' . $stem . '.mp4';
				if ( is_file( $path ) ) {
					$out[ $key ] = array(
						'url'    => $where['url'] . '/' . $stem . '.mp4',
						'source' => 'plugin',
						'bytes'  => (int) filesize( $path ),
						'id'     => 0,
					);
				}
			}
		}
		if ( count( $out ) === count( $stems ) ) {
			return $out;
		}
		return array_merge( self::scan_library( $stems, array_keys( $out ) ), $out );
	}

	/**
	 * Find uploads by file name stem. Newest first, so the first match for a stem is the one that is kept.
	 *
	 * @param array<string, string> $stems Key => stem.
	 * @param array<int, string>    $skip  Keys already found elsewhere.
	 * @return array<string, array<string, mixed>>
	 */
	private static function scan_library( array $stems, array $skip ) {
		$out   = array();
		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 60,
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one small lookup, cached in a transient.
					array(
						'key'     => '_wp_attached_file',
						'value'   => 'dbgr-hero-loop-',
						'compare' => 'LIKE',
					),
				),
			)
		);
		$by_stem = array_flip( $stems );
		foreach ( (array) $query->posts as $id ) {
			$id   = (int) $id;
			$file = get_post_meta( $id, '_wp_attached_file', true );
			if ( ! is_string( $file ) ) {
				continue;
			}
			$stem = self::stem_of( $file );
			if ( '' === $stem || ! isset( $by_stem[ $stem ] ) ) {
				continue;
			}
			$key = $by_stem[ $stem ];
			if ( isset( $out[ $key ] ) || in_array( $key, $skip, true ) ) {
				continue;
			}
			$url = wp_get_attachment_url( $id );
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			$path  = get_attached_file( $id );
			$bytes = 0;
			if ( is_string( $path ) && '' !== $path && false === strpos( $path, '://' ) ) {
				if ( ! is_file( $path ) ) {
					continue; // The database row survived but the file did not.
				}
				$bytes = (int) filesize( $path );
			}
			$out[ $key ] = array(
				'url'    => $url,
				'source' => 'library',
				'bytes'  => $bytes,
				'id'     => $id,
			);
		}
		return $out;
	}

	/**
	 * The stem a stored file name stands for, or an empty string when it is not one of ours. Tolerates the tails
	 * WordPress adds: "-1", "-2" (a repeat upload) and "-scaled".
	 *
	 * @param string $relative_path Value of _wp_attached_file, for example 2026/10/dbgr-hero-loop-720-av1-1.mp4.
	 * @return string
	 */
	public static function stem_of( $relative_path ) {
		$name = strtolower( basename( (string) $relative_path ) );
		if ( 1 !== preg_match( '/^(dbgr-hero-loop-(?:720|1080)-(?:av1|hevc|h264))(?:-scaled)?(?:-\d{1,3})?(?:-scaled)?\.mp4$/D', $name, $m ) ) {
			return '';
		}
		return $m[1];
	}

	/**
	 * The posters in the media plugin's hero folder. A poster URL carries a ?ver= stamp from the file's modified time,
	 * so a replaced poster is not served stale from a browser or CDN cache.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function scan_posters() {
		$out   = array();
		$where = self::location();
		if ( '' === $where['dir'] || '' === $where['url'] ) {
			return $out;
		}
		foreach ( self::poster_files() as $key => $info ) {
			$path = $where['dir'] . '/' . $info[0];
			if ( is_file( $path ) ) {
				$out[ $key ] = array(
					'url'    => $where['url'] . '/' . $info[0] . '?ver=' . (int) filemtime( $path ),
					'source' => 'plugin',
					'bytes'  => (int) filesize( $path ),
					'id'     => 0,
				);
			}
		}
		return $out;
	}

	/**
	 * Whether the hero video can run: the 1080 WebP poster and at least one video are present.
	 *
	 * @param array<string, mixed>|null $found Result of find(); null reads it.
	 * @return bool
	 */
	public static function ready( $found = null ) {
		$found = is_array( $found ) ? $found : self::find();
		return empty( $found['fail'] ) && ! empty( $found['posters']['webp-1080']['url'] ) && ! empty( $found['videos'] );
	}

	/**
	 * Record that the core hero markup was not in a shape the edit recognised. The marker lives in the same transient as
	 * the lookup, so it is cleared by the same events (a settings save, a plugin or core update, a day passing) and the
	 * markup is tried again after any of them.
	 *
	 * @return void
	 */
	public static function mark_markup_failed() {
		$found         = self::find();
		$found['fail'] = time();
		self::$memo    = $found;
		set_transient( self::TRANSIENT, $found, DAY_IN_SECONDS );
	}

	/**
	 * Whether the markup edit has failed since the cache was last cleared.
	 *
	 * @return int Unix time of the failure, or 0.
	 */
	public static function markup_failed() {
		$found = self::find();
		return empty( $found['fail'] ) ? 0 : (int) $found['fail'];
	}

	/**
	 * What the media plugin's hero.json declares (status and whether the pictures show AI food), for the status table.
	 * The page never depends on it: the owner's switch is the decision. Empty array when it cannot be read.
	 *
	 * @return array<string, mixed>
	 */
	public static function declared() {
		$where = self::location();
		$path  = $where['dir'] . '/hero.json';
		$raw   = ( '' !== $where['dir'] && is_readable( $path ) ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local media plugin file.
		$json  = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return ( is_array( $json ) && isset( $json['status'] ) ) ? $json : array();
	}
}
