<?php
/** Actual prebuilt asset contracts; no GD, WordPress database or network needed. */
require_once dirname( __DIR__ ) . '/includes/class-doughboss-images.php';
require_once dirname( __DIR__ ) . '/includes/class-doughboss-rest-controller.php';

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $title ) ), '-' );
	}
}

db_test( 'menu image selection never substitutes category or different-product photography', function () {
	if ( ! defined( 'DOUGHBOSS_PLUGIN_DIR' ) ) { define( 'DOUGHBOSS_PLUGIN_DIR', dirname( __DIR__ ) . '/' ); }
	if ( ! defined( 'DOUGHBOSS_PLUGIN_URL' ) ) { define( 'DOUGHBOSS_PLUGIN_URL', 'https://example.test/plugin/' ); }
	$controller = ( new ReflectionClass( 'DoughBoss_REST_Controller' ) )->newInstanceWithoutConstructor();
	$method = new ReflectionMethod( $controller, 'menu_image_url' );
	$method->setAccessible( true );
	foreach ( array( 'Manoush', 'Pizza', 'Pies', 'Wraps', 'Desserts', 'Drinks', '' ) as $category ) {
		assert_same( '', $method->invoke( $controller, 'Unphotographed menu item', $category ), 'unknown name has no borrowed photo for ' . $category );
	}
	$drink_photos = array(
		'Coke 600ml' => 'coca-cola-classic-600ml.jpg',
		'Coke Vanilla 600ml' => 'coca-cola-vanilla-600ml.jpg',
		'Coke Zero 600ml' => 'coca-cola-zero-600ml.png',
		'Sprite 600ml' => 'sprite-600ml.jpg',
		'Fanta 600ml' => 'fanta-orange-600ml.jpg',
	);
	foreach ( $drink_photos as $drink => $file ) {
		assert_same( DOUGHBOSS_PLUGIN_URL . 'public/images/menu/brand-v1/' . $file, $method->invoke( $controller, $drink, 'Drinks' ), 'named 600 mL drink receives its exact bottle: ' . $drink );
		$size = getimagesize( DOUGHBOSS_PLUGIN_DIR . 'public/images/menu/brand-v1/' . $file );
		assert_true( is_array( $size ) && $size[0] >= 600 && $size[1] >= 600, 'distributor original is present at native resolution: ' . $file );
		assert_same( '.png' === substr( $file, -4 ) ? 'image/png' : 'image/jpeg', $size['mime'], 'asset type matches filename' );
	}
	foreach ( array( 'Coke 375ml', 'Diet Coke 600ml', 'Sprite Zero 600ml', 'Fanta Grape 600ml', 'Coke Vanilla Zero 600ml' ) as $drink ) {
		assert_same( '', $method->invoke( $controller, $drink, 'Drinks' ), 'another variant is not substituted: ' . $drink );
	}
	$juice_photos = array(
		'Apple Juice' => 'juice-world-apple-500ml.jpg',
		'Lemon Juice' => 'juice-world-lemon-500ml.jpg',
		'Lemon & Mint Juice' => 'juice-world-lemon-mint-500ml.jpg',
		'Orange Juice' => 'juice-world-orange-500ml.jpg',
		'Orange & Mango Juice' => 'juice-world-orange-mango-500ml.jpg',
		'Orange & Passion Juice' => 'juice-world-orange-passionfruit-500ml.jpg',
	);
	foreach ( $juice_photos as $name => $file ) {
		assert_same( DOUGHBOSS_PLUGIN_URL . 'public/images/menu/brand-v1/' . $file, $method->invoke( $controller, $name, 'Drinks' ), 'exact confirmed Juice World flavour is selected: ' . $name );
		$size = getimagesize( DOUGHBOSS_PLUGIN_DIR . 'public/images/menu/brand-v1/' . $file );
		assert_true( is_array( $size ) && $size[0] >= 300 && $size[1] >= 300 && 'image/jpeg' === $size['mime'], 'manufacturer original is present and decodable: ' . $file );
	}
	$illustrations = array(
		'Cheese, Tomato & Olives' => 'cheese-tomato-olives',
		'Half Meat & Cheese' => 'half-meat-cheese',
		'Labneh Veggie Pizza' => 'labneh-veggie-pizza',
		'Sujuk Special' => 'sujuk-special',
		'Zaatar Veggie Pizza' => 'zaatar-veggie-pizza',
	);
	foreach ( $illustrations as $name => $slug ) {
		$file = 'public/images/menu/generated-v1/' . $slug . '.webp';
		assert_same( DOUGHBOSS_PLUGIN_URL . $file, $method->invoke( $controller, $name, 'Menu' ), 'explicitly generated illustration has an exact SKU mapping' );
		$size = getimagesize( DOUGHBOSS_PLUGIN_DIR . $file );
		assert_true( is_array( $size ) && $size[0] >= 1000 && $size[1] >= 750, 'high-resolution delivery asset exists' );
		assert_same( 'image/webp', $size['mime'], 'efficient WebP delivery, not a renamed PNG' );
	}
	foreach ( array( 'Spinach & Cheese', 'Chicken Pie', 'Shanklish' ) as $name ) {
		assert_same( '', $method->invoke( $controller, $name, 'Pizza' ), 'unverified alias is not a photo match: ' . $name );
	}
	foreach ( array( 'Zaatar' => 'zaatar.jpg', 'Cheese' => 'cheese.jpg', 'Sujuk Deluxe' => 'sujuk-deluxe.jpg', 'Spinach Pie' => 'spinach-pie.jpg' ) as $name => $file ) {
		assert_same( DOUGHBOSS_PLUGIN_URL . 'public/images/menu/real-v1/' . $file, $method->invoke( $controller, $name, 'Menu' ), 'existing exact-name asset remains selected: ' . $name );
	}
} );
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
	assert_same( 27, count( $manifest['images'] ), 'exact approved photographs and labelled illustrations have delivery variants' );
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
		'menu/brand-v1/coca-cola-classic-600ml.jpg', 'menu/brand-v1/coca-cola-vanilla-600ml.jpg',
		'menu/brand-v1/coca-cola-zero-600ml.png', 'menu/brand-v1/sprite-600ml.jpg', 'menu/brand-v1/fanta-orange-600ml.jpg',
		'menu/generated-v1/cheese-tomato-olives.webp', 'menu/generated-v1/half-meat-cheese.webp',
		'menu/generated-v1/labneh-veggie-pizza.webp', 'menu/generated-v1/sujuk-special.webp', 'menu/generated-v1/zaatar-veggie-pizza.webp',
	);
	$actual_originals = array_keys( $manifest['images'] );
	sort( $expected_originals );
	sort( $actual_originals );
	assert_same( $expected_originals, $actual_originals, 'exact approved original coverage' );
	$expected = array( 'manifest.json' );
	foreach ( $manifest['images'] as $original => $entry ) {
		assert_true( 1 === preg_match( '#^(?:(?:menu/real-v1/)?[a-z0-9-]+\.jpg|menu/brand-v1/[a-z0-9-]+\.(?:jpg|png)|menu/generated-v1/[a-z0-9-]+\.webp)$#D', $original ), 'safe original path' );
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

db_test( 'new brand and illustrated menu originals expose phone-sized candidates without changing identity', function () {
	$paths = array(
		'menu/brand-v1/coca-cola-classic-600ml.jpg', 'menu/brand-v1/coca-cola-vanilla-600ml.jpg',
		'menu/brand-v1/coca-cola-zero-600ml.png', 'menu/brand-v1/sprite-600ml.jpg', 'menu/brand-v1/fanta-orange-600ml.jpg',
		'menu/generated-v1/cheese-tomato-olives.webp', 'menu/generated-v1/half-meat-cheese.webp',
		'menu/generated-v1/labneh-veggie-pizza.webp', 'menu/generated-v1/sujuk-special.webp', 'menu/generated-v1/zaatar-veggie-pizza.webp',
	);
	foreach ( $paths as $path ) {
		$url = DOUGHBOSS_PLUGIN_URL . 'public/images/' . $path;
		$data = DoughBoss_Images::responsive_for_url( $url );
		assert_true( is_array( $data ), 'new exact original has responsive delivery: ' . $path );
		if ( ! is_array( $data ) ) { continue; }
		assert_same( $url, $data['original'], 'original identity and illustration path remain unchanged' );
		assert_same( '.png' === substr( $path, -4 ) ? array( 'image/webp' ) : array( 'image/avif', 'image/webp' ), array_column( $data['sources'], 'type' ), 'transparent PNG retains an alpha-capable WebP lane; opaque assets offer AVIF first' );
		foreach ( $data['sources'] as $source ) {
			assert_true( false !== strpos( $source['srcset'], '-168.' ), 'retina narrow-phone thumbnail candidate present' );
			assert_true( false !== strpos( $source['srcset'], '-336.' ), 'retina two-column phone candidate present' );
		}
	}
	foreach ( array( 'menu/brand-v1/../private.jpg', 'menu/generated-v1/half-meat-cheese.jpg', 'menu/brand-v1/coca-cola-zero-600ml.webp', 'menu/generated-v1/half-meat-cheese.webp?x=1', 'menu/generated-v1/half-meat-cheese.webp#fragment', 'menu/brand-v1/missing.jpg' ) as $path ) {
		assert_same( null, DoughBoss_Images::responsive_for_url( DOUGHBOSS_PLUGIN_URL . 'public/images/' . $path ), 'malformed or unmanifested path remains unsupported' );
	}
} );
