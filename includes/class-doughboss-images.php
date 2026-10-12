<?php
/** Prebuilt responsive photography shared by the plugin hero and paired theme. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DoughBoss_Images {
	/**
	 * Keep the caller's original image as the last, unchanged fallback.
	 * No encoding, attachment mutation or provider request runs on a page view.
	 *
	 * @param string $path Original path relative to public/images.
	 * @param string $image Trusted, already escaped img markup from the caller.
	 * @param string $sizes CSS slot sizes supplied by the presentation caller.
	 * @return string
	 */
	public static function picture( $path, $image, $sizes = '100vw' ) {
		static $manifest = null;
		if ( ! defined( 'DOUGHBOSS_PLUGIN_DIR' ) || ! defined( 'DOUGHBOSS_PLUGIN_URL' ) ) {
			return $image;
		}
		$base = DOUGHBOSS_PLUGIN_DIR . 'public/images/';
		if ( null === $manifest ) {
			$file = $base . 'responsive/manifest.json';
			$manifest = is_readable( $file ) ? json_decode( file_get_contents( $file ), true ) : false;
		}
		if ( ! is_array( $manifest ) || 1 !== ( $manifest['version'] ?? null ) ||
			! preg_match( '#^(?:menu/real-v1/)?[a-z0-9-]+\.jpg$#D', $path ) ||
			empty( $manifest['images'][ $path ] ) || ! is_readable( $base . $path ) ) {
			return $image;
		}
		$entry = $manifest['images'][ $path ];
		$size = wp_getimagesize( $base . $path );
		if ( ! is_array( $size ) || ( $entry['width'] ?? 0 ) !== $size[0] || ( $entry['height'] ?? 0 ) !== $size[1] ) {
			return $image;
		}
		$sources = '';
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
				$sources .= '<source type="image/' . $format . '" srcset="' . esc_attr( implode( ', ', $candidates ) ) . '" sizes="' . esc_attr( $sizes ) . '">';
			}
		}
		return $sources ? '<picture class="db-responsive-picture">' . $sources . $image . '</picture>' : $image;
	}
}
