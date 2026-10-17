<?php
/** Actual prebuilt asset contracts; no GD, WordPress database or network needed. */
require_once dirname( __DIR__ ) . '/includes/class-doughboss-images.php';
if ( ! defined( 'DOUGHBOSS_PLUGIN_DIR' ) ) {
	define( 'DOUGHBOSS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
	define( 'DOUGHBOSS_PLUGIN_URL', 'https://example.test/plugin/' );
}
if ( ! function_exists( 'wp_getimagesize' ) ) {
	function wp_getimagesize( $path ) { return getimagesize( $path ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
}

db_test( 'bundled images carry only the scoped Apache AVIF MIME mapping', function () {
	$path = DOUGHBOSS_PLUGIN_DIR . 'public/images/.htaccess';
	assert_true( is_file( $path ), 'image MIME configuration is bundled' );
	if ( ! is_file( $path ) ) {
		return;
	}
	$content = file_get_contents( $path );
	assert_true( false !== $content, 'image MIME configuration is readable' );
	if ( false === $content ) {
		return;
	}
	$directives = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $content ) as $line ) {
		$line = trim( $line );
		if ( '' !== $line && '#' !== substr( $line, 0, 1 ) ) {
			$directives[] = $line;
		}
	}
	assert_same( array(
		'<IfModule mod_mime.c>',
		'AddType image/avif .avif',
		'</IfModule>',
	), $directives, 'only the AVIF type is mapped; no rewrites, caching or access rules' );
} );

db_test( 'bundled image manifest matches actual original and encoded assets', function () {
	$base = DOUGHBOSS_PLUGIN_DIR . 'public/images/';
	$manifest = json_decode( file_get_contents( $base . 'responsive/manifest.json' ), true );
	assert_same( 1, $manifest['version'], 'known manifest version' );
	assert_same( 17, count( $manifest['images'] ), 'exact approved rendered photographs are generated' );
	$expected_originals = array(
		'doughboss-feast-real-v1.jpg',
		'menu/real-v1/zaatar-cheese.jpg',
		'menu/real-v1/sujuk-deluxe.jpg',
		'menu/real-v1/haloumi-pie.jpg',
		'menu/real-v1/meat-cheese.jpg',
		'menu/real-v1/zaatar.jpg',
		'menu/real-v1/cheese.jpg',
		'menu/real-v1/meat.jpg',
		'menu/real-v1/sujuk-cheese.jpg',
		'menu/real-v1/spinach-deluxe.jpg',
		'menu/real-v1/pepperoni-cheese.jpg',
		'menu/real-v1/chicken-cheese.jpg',
		'menu/real-v1/bbq-chicken.jpg',
		'menu/real-v1/peri-peri-chicken.jpg',
		'menu/real-v1/spinach-pie.jpg',
		'menu/real-v1/dough-boss-pie.jpg',
		'menu/real-v1/aged-cheese-pie.jpg',
	);
	$actual_originals = array_keys( $manifest['images'] );
	sort( $expected_originals );
	sort( $actual_originals );
	assert_same( $expected_originals, $actual_originals, 'exact approved original coverage' );
	$expected = array( 'manifest.json' );
	foreach ( $manifest['images'] as $original => $entry ) {
		assert_true( 1 === preg_match( '#^(?:menu/real-v1/)?[a-z0-9-]+\.jpg$#D', $original ), 'safe original path' );
		$size = getimagesize( $base . $original );
		assert_same( array( $size[0], $size[1] ), array( $entry['width'], $entry['height'] ), 'original dimensions' );
		assert_same( hash_file( 'sha256', $base . $original ), $entry['source_sha256'], 'original hash' );
		foreach ( array( 'avif', 'webp' ) as $format ) {
			$previous = 0;
			foreach ( $entry['sources'][ $format ] as $variant ) {
				assert_true( 1 === preg_match( '#^responsive/[a-z0-9-]+-' . $variant['width'] . '\.' . $format . '$#D', $variant['file'] ), 'safe typed variant path' );
				$file = $base . $variant['file'];
				$expected[] = basename( $file );
				assert_same( hash_file( 'sha256', $file ), $variant['sha256'], 'variant exact hash' );
				assert_same( filesize( $file ), $variant['bytes'], 'variant actual bytes' );
				assert_true( $variant['bytes'] < filesize( $base . $original ), 'variant saves bytes over the original' );
				assert_true( $variant['width'] > $previous && $variant['width'] <= $size[0], 'unique ascending widths without upsampling' );
				assert_same( (int) round( $size[1] * $variant['width'] / $size[0] ), $variant['height'], 'uncropped aspect ratio' );
				// PHP 7.4 cannot inspect AVIF dimensions; PHP 8.2 CI verifies them.
				if ( 'webp' === $format || PHP_VERSION_ID >= 80200 ) {
					$encoded_size = getimagesize( $file );
					assert_same( array( $variant['width'], $variant['height'] ), array( $encoded_size[0], $encoded_size[1] ), 'actual encoded dimensions' );
					assert_same( 'image/' . $format, $encoded_size['mime'], 'actual encoded format' );
				}
				$previous = $variant['width'];
			}
		}
	}
	$actual = array_values( array_diff( scandir( $base . 'responsive' ), array( '.', '..' ) ) );
	sort( $actual );
	sort( $expected );
	assert_same( $expected, $actual, 'no stale or unmanifested assets' );
} );

db_test( 'responsive renderer keeps escaped original markup and rejects non-bundled paths', function () {
	$image = '<img src="original.jpg" alt="Food &amp; friends" class="hero" width="1080" height="864" loading="eager" decoding="async" fetchpriority="high">';
	$html = DoughBoss_Images::picture( 'doughboss-feast-real-v1.jpg', $image, '104vw' );
	assert_true( 0 === strpos( $html, '<picture class="db-responsive-picture"><source type="image/avif"' ), 'AVIF first' );
	assert_true( false !== strpos( $html, '<source type="image/webp"' ), 'WebP second' );
	assert_true( substr( $html, -strlen( $image . '</picture>' ) ) === $image . '</picture>', 'original image is unchanged and last' );
	assert_same( 2, substr_count( $html, 'sizes="104vw"' ), 'caller supplies actual slot hint' );
	assert_true( false !== strpos( $html, 'doughboss-feast-real-v1-480.avif 480w' ), 'real width candidate' );
	foreach ( array( '../private.jpg', 'https://example.test/image.jpg', 'menu/zaatar.webp', 'missing.jpg', 'menu/real-v1/choco-banana.jpg' ) as $path ) {
		assert_same( $image, DoughBoss_Images::picture( $path, $image ), 'unsupported path retains exact fallback' );
	}
	$escaped = DoughBoss_Images::picture( 'doughboss-feast-real-v1.jpg', $image, '100vw" onload="bad' );
	assert_false( false !== strpos( $escaped, ' onload="bad' ), 'sizes cannot inject an attribute' );
} );

db_test( 'menu responsive data binds to an exact supported original URL', function () {
	$original = DOUGHBOSS_PLUGIN_URL . 'public/images/menu/real-v1/spinach-pie.jpg';
	$data = DoughBoss_Images::responsive_for_url( $original );
	assert_true( is_array( $data ), 'newly covered pie exposes delivery candidates' );
	assert_same( $original, $data['original'], 'selected original is bound exactly' );
	$size = getimagesize( DOUGHBOSS_PLUGIN_DIR . 'public/images/menu/real-v1/spinach-pie.jpg' );
	assert_same( array( $size[0], $size[1] ), array( $data['width'], $data['height'] ), 'original dimensions are explicit' );
	assert_same( array( 'image/avif', 'image/webp' ), array_column( $data['sources'], 'type' ), 'both modern delivery formats are present in priority order' );
	foreach ( $data['sources'] as $source ) {
		assert_true( false !== strpos( $source['srcset'], '/public/images/responsive/spinach-pie-480.' ), 'new variants are connected to public presentation' );
	}
	foreach ( array(
		null, 42, '', 'https://uploads.example.test/spinach-pie.jpg',
		'https://other.example.test/public/images/menu/real-v1/spinach-pie.jpg',
		DOUGHBOSS_PLUGIN_URL . 'public/images/menu/real-v1/choco-banana.jpg',
		DOUGHBOSS_PLUGIN_URL . 'public/images/../private.jpg',
		DOUGHBOSS_PLUGIN_URL . 'public/images/menu/real-v1/Spinach-Pie.jpg',
		$original . '?version=1', $original . '#fragment',
	) as $unsupported ) {
		assert_same( null, DoughBoss_Images::responsive_for_url( $unsupported ), 'non-exact, external, unsupported and featured-image URLs keep their fallback' );
	}
} );
