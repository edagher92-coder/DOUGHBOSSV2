<?php
/** Prebuilt responsive photography shared by the plugin hero and paired theme. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DoughBoss_Images {
	/**
	 * Public candidates for an exact bundled image URL. Featured uploads and
	 * unknown images keep their original fallback.
	 * @param string $url Selected original image URL.
	 * @return array|null
	 */
	public static function responsive_for_url( $url ) {
		if ( ! is_string( $url ) || ! defined( 'DOUGHBOSS_PLUGIN_URL' ) ) { return null; }
		$prefix = DOUGHBOSS_PLUGIN_URL . 'public/images/';
		return 0 === strpos( $url, $prefix ) ? self::source_data( substr( $url, strlen( $prefix ) ) ) : null;
	}

	/**
	 * Keep the caller's escaped original img last and unchanged as fallback.
	 * @param string $path Bundled original path.
	 * @param string $image Trusted, escaped img markup.
	 * @param string $sizes CSS slot sizes supplied by the caller.
	 * @return string
	 */
	public static function picture( $path, $image, $sizes = '100vw' ) {
		$data = self::source_data( $path );
		if ( null === $data ) { return $image; }
		$sources = '';
		foreach ( $data['sources'] as $source ) {
			$sources .= '<source type="' . esc_attr( $source['type'] ) . '" srcset="' . esc_attr( $source['srcset'] ) . '" sizes="' . esc_attr( $sizes ) . '">';
		}
		return '<picture class="db-responsive-picture">' . $sources . $image . '</picture>';
	}

	/** Validate packaged candidates once for both markup and REST presentation. */
	private static function source_data( $path ) {
		static $manifest = null;
		if ( ! defined( 'DOUGHBOSS_PLUGIN_DIR' ) || ! defined( 'DOUGHBOSS_PLUGIN_URL' ) ) {
			return null;
		}
		$base = DOUGHBOSS_PLUGIN_DIR . 'public/images/';
		if ( null === $manifest ) {
			$file = $base . 'responsive/manifest.json';
			$manifest = is_readable( $file ) ? json_decode( file_get_contents( $file ), true ) : false;
		}
		if ( ! is_array( $manifest ) || 1 !== ( $manifest['version'] ?? null ) ||
			! preg_match( '#^(?:menu/real-v1/)?[a-z0-9-]+\.jpg$#D', $path ) ||
			empty( $manifest['images'][ $path ] ) || ! is_readable( $base . $path ) ) {
			return null;
		}
		$entry = $manifest['images'][ $path ];
		$size = wp_getimagesize( $base . $path );
		if ( ! is_array( $size ) || ( $entry['width'] ?? 0 ) !== $size[0] || ( $entry['height'] ?? 0 ) !== $size[1] ) {
			return null;
		}
		$sources = array();
		foreach ( array( 'avif', 'webp' ) as $format ) {
			$candidates = array();
			$last_width = 0;
			foreach ( (array) ( $entry['sources'][ $format ] ?? array() ) as $variant ) {
				if ( ! is_array( $variant ) || ! is_int( $variant['width'] ?? null ) || ! is_int( $variant['height'] ?? null ) ||
					$variant['width'] <= $last_width || $variant['width'] > $size[0] ||
					$variant['height'] !== (int) round( $size[1] * $variant['width'] / $size[0] ) ||
					! is_string( $variant['file'] ?? null ) ||
					! preg_match( '#^responsive/[a-z0-9-]+-' . $variant['width'] . '\.' . $format . '$#D', $variant['file'] ) ||
					! is_readable( $base . $variant['file'] ) ) {
					continue;
				}
				$candidates[] = DOUGHBOSS_PLUGIN_URL . 'public/images/' . $variant['file'] . ' ' . $variant['width'] . 'w';
				$last_width = $variant['width'];
			}
			if ( $candidates ) {
				$sources[] = array( 'type' => 'image/' . $format, 'srcset' => implode( ', ', $candidates ) );
			}
		}
		return $sources ? array( 'original' => DOUGHBOSS_PLUGIN_URL . 'public/images/' . $path, 'width' => $size[0], 'height' => $size[1], 'sources' => $sources ) : null;
	}
}
