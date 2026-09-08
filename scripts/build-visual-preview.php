<?php
/**
 * Build a static, read-only visual preview from the current theme sources.
 *
 * Usage: php scripts/build-visual-preview.php OUTPUT_DIRECTORY [BASE_PATH]
 * Example GitHub Pages base path: /DOUGHBOSSV2/
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This exporter is CLI-only.\n" );
	exit( 1 );
}

$repo_root = dirname( __DIR__ );
$output    = isset( $argv[1] ) ? rtrim( (string) $argv[1], "/\\" ) : '';
$base_path = isset( $argv[2] ) ? (string) $argv[2] : '/DOUGHBOSSV2/';

if ( '' === $output ) {
	fwrite( STDERR, "Usage: php scripts/build-visual-preview.php OUTPUT_DIRECTORY [BASE_PATH]\n" );
	exit( 1 );
}
if ( file_exists( $output ) ) {
	fwrite( STDERR, "Refusing to overwrite existing output: {$output}\n" );
	exit( 1 );
}
if ( ! preg_match( '#^(?:\./|/[A-Za-z0-9._~/-]*/)$#', $base_path ) || false !== strpos( $base_path, '..' ) ) {
	fwrite( STDERR, "BASE_PATH must be ./ or an absolute URL path ending in / (for example /DOUGHBOSSV2/).\n" );
	exit( 1 );
}

$source_files = array(
	'themes/doughboss-final/functions.php',
	'themes/doughboss-final/header.php',
	'themes/doughboss-final/footer.php',
	'themes/doughboss-final/front-page.php',
	'themes/doughboss-final/page-order.php',
	'themes/doughboss-final/template-parts/locations.php',
	'includes/class-doughboss-shortcodes.php',
	'includes/class-doughboss-images.php',
	'includes/class-doughboss-menu-options.php',
);
$asset_files  = array(
	'themes/doughboss-final/style.css',
	'themes/doughboss-final/assets/theme.js',
	'public/css/doughboss.css',
	'public/css/doughboss-order-page.css',
	'public/css/doughboss-manoush-hero.css',
	'public/js/doughboss.js',
	'public/js/doughboss-shop-status.js',
	'public/js/doughboss-manoush-hero.js',
	'public/fonts/bebasneue-400.woff2',
	'public/fonts/barlow-400.woff2',
	'public/fonts/barlow-500.woff2',
	'public/fonts/barlow-600.woff2',
	'public/fonts/barlow-700.woff2',
	'public/images/doughboss-feast-real-v1.jpg',
	'public/images/menu/real-v1/zaatar-cheese.jpg',
	'public/images/menu/real-v1/sujuk-deluxe.jpg',
	'public/images/menu/real-v1/haloumi-pie.jpg',
	'public/images/menu/real-v1/spinach-pie.jpg',
	'public/images/menu/real-v1/labneh-veggie-wrap.jpg',
	'public/images/menu/real-v1/choco-banana.jpg',
);
$preview_files = array(
	'scripts/visual-preview/wp-shim.php',
	'scripts/visual-preview/preview-api.js',
	'scripts/visual-preview/preview.css',
);
// Copy only the manifest's prebuilt variants, never arbitrary directory contents.
$image_manifest_path = 'public/images/responsive/manifest.json';
$image_manifest = json_decode( file_get_contents( $repo_root . '/' . $image_manifest_path ), true );
if ( ! is_array( $image_manifest ) || 1 !== ( $image_manifest['version'] ?? null ) ) {
	fwrite( STDERR, "Responsive image manifest is missing or invalid.\n" );
	exit( 1 );
}
$source_files[] = $image_manifest_path;
foreach ( $image_manifest['images'] as $original => $entry ) {
	if ( ! in_array( 'public/images/' . $original, $asset_files, true ) ) {
		continue;
	}
	foreach ( array( 'avif', 'webp' ) as $format ) {
		foreach ( $entry['sources'][ $format ] as $variant ) {
			if ( ! preg_match( '#^responsive/[a-z0-9-]+-[1-9][0-9]*\.' . $format . '$#D', $variant['file'] ) ) {
				throw new RuntimeException( 'Unsafe responsive preview asset.' );
			}
			$asset_files[] = 'public/images/' . $variant['file'];
		}
	}
}
$provenance_files = array_merge( array( 'scripts/build-visual-preview.php' ), $source_files, $asset_files, $preview_files );

foreach ( $provenance_files as $relative ) {
	if ( ! is_file( $repo_root . '/' . $relative ) ) {
		fwrite( STDERR, "Required source is missing: {$relative}\n" );
		exit( 1 );
	}
}

if ( ! mkdir( $output, 0777, true ) && ! is_dir( $output ) ) {
	fwrite( STDERR, "Could not create output directory: {$output}\n" );
	exit( 1 );
}

function doughboss_preview_copy( $repo_root, $output, $relative ) {
	$destination = $output . '/' . $relative;
	$directory   = dirname( $destination );
	if ( ! is_dir( $directory ) && ! mkdir( $directory, 0777, true ) && ! is_dir( $directory ) ) {
		throw new RuntimeException( 'Could not create output directory for ' . $relative );
	}
	if ( ! copy( $repo_root . '/' . $relative, $destination ) ) {
		throw new RuntimeException( 'Could not copy ' . $relative );
	}
}

try {
	foreach ( $asset_files as $relative ) {
		doughboss_preview_copy( $repo_root, $output, $relative );
	}
	if ( ! mkdir( $output . '/preview', 0777, true ) && ! is_dir( $output . '/preview' ) ) {
		throw new RuntimeException( 'Could not create preview asset directory.' );
	}
	foreach ( array( 'preview-api.js', 'preview.css' ) as $filename ) {
		doughboss_preview_copy( $repo_root, $output, 'scripts/visual-preview/' . $filename );
		if ( ! rename( $output . '/scripts/visual-preview/' . $filename, $output . '/preview/' . $filename ) ) {
			throw new RuntimeException( 'Could not stage preview asset ' . $filename );
		}
	}
	@rmdir( $output . '/scripts/visual-preview' );
	@rmdir( $output . '/scripts' );

	$GLOBALS['doughboss_preview_repo_root'] = $repo_root;
	$GLOBALS['doughboss_preview_base_path'] = $base_path;
	require $repo_root . '/scripts/visual-preview/wp-shim.php';
	$fixture_json = json_encode( doughboss_preview_fixture(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
	if ( false === $fixture_json || false === file_put_contents( $output . '/preview/menu-options.js', 'window.DoughBossPreviewOptions = ' . $fixture_json . ";\n" ) ) {
		throw new RuntimeException( 'Could not write the synthetic preview fixture.' );
	}

	$pages = array(
		'index.html'  => array( 'template' => 'front-page.php', 'ordering_open' => true, 'title' => 'Dough Boss visual preview' ),
		'order.html'  => array( 'template' => 'page-order.php', 'ordering_open' => true, 'title' => 'Order page visual preview' ),
		'paused.html' => array( 'template' => 'page-order.php', 'ordering_open' => false, 'title' => 'Paused order page visual preview' ),
	);
	foreach ( $pages as $filename => $page ) {
		$html = doughboss_preview_render_page( $page['template'], $page['ordering_open'], $page['title'] );
		if ( false === file_put_contents( $output . '/' . $filename, $html ) ) {
			throw new RuntimeException( 'Could not write ' . $filename );
		}
	}

	$commit_output = array();
	$commit_status = 0;
	exec( 'git -C ' . escapeshellarg( $repo_root ) . ' rev-parse HEAD', $commit_output, $commit_status );
	$commit = 0 === $commit_status && isset( $commit_output[0] ) && preg_match( '/^[a-f0-9]{40}$/', $commit_output[0] )
		? $commit_output[0]
		: 'unavailable';

	$source_hashes = array();
	foreach ( $provenance_files as $relative ) {
		$source_hashes[ $relative ] = hash_file( 'sha256', $repo_root . '/' . $relative );
	}
	$output_hashes = array();
	$iterator      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $output, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		$relative                   = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $output ) + 1 ) );
		$output_hashes[ $relative ] = hash_file( 'sha256', $file->getPathname() );
	}
	ksort( $output_hashes );
	$manifest = array(
		'preview_only'  => true,
		'source_commit' => $commit,
		'base_path'     => $base_path,
		'pages'         => array_keys( $pages ),
		'source_sha256' => $source_hashes,
		'output_sha256' => $output_hashes,
	);
	$json = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( false === $json || false === file_put_contents( $output . '/manifest.json', $json . "\n" ) ) {
		throw new RuntimeException( 'Could not write manifest.json' );
	}
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Preview build failed: ' . $error->getMessage() . "\n" );
	exit( 1 );
}

fwrite( STDOUT, "Built read-only visual preview at {$output}\n" );
fwrite( STDOUT, "Source commit: {$commit}\n" );
