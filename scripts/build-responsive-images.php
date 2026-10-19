<?php
declare(strict_types=1);

/**
 * Builds AVIF and WebP responsive derivatives for the approved DoughBoss photography.
 *
 * Usage:
 *   php scripts/build-responsive-images.php <fresh-output-directory>
 *
 * The destination may be anywhere, but its existing parent must be resolvable.
 * This deliberately refuses to overwrite an existing output directory. Manifest
 * paths remain canonical relative-to-public/images paths under responsive/.
 */

const RESPONSIVE_WIDTHS = [480, 960, 1600];
const WEBP_QUALITY = 75;
const AVIF_QUALITY = 58;
const AVIF_SPEED = 6;

/** @var list<string> */
const APPROVED_IMAGES = [
    'doughboss-feast-real-v1.jpg',
    'menu/real-v1/zaatar-cheese.jpg',
    'menu/real-v1/sujuk-deluxe.jpg',
    'menu/real-v1/haloumi-pie.jpg',
    'menu/real-v1/meat-cheese.jpg',
];

function fail(string $message)
{
    fwrite(STDERR, "Error: {$message}" . PHP_EOL);
    exit(1);
}

function imageHeightForWidth(int $sourceWidth, int $sourceHeight, int $targetWidth): int
{
    return max(1, (int) round(($sourceHeight * $targetWidth) / $sourceWidth));
}

/**
 * @return list<int>
 */
function targetWidths(int $sourceWidth): array
{
    $widths = [];
    foreach (RESPONSIVE_WIDTHS as $width) {
        if ($width <= $sourceWidth) {
            $widths[] = $width;
        }
    }

    if ($sourceWidth <= 2000) {
        $widths[] = $sourceWidth;
    }

    $widths = array_values(array_unique($widths));
    sort($widths, SORT_NUMERIC);

    return $widths;
}

/**
 * @return array{width:int,height:int,source_sha256:string,sources:array{avif:list<array{file:string,width:int,height:int,sha256:string,bytes:int}>,webp:list<array{file:string,width:int,height:int,sha256:string,bytes:int}>}}
 */
function buildImage(string $imageRoot, string $outputDir, string $outputRelativeDir, string $sourceRelativePath): array
{
    $sourcePath = $imageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sourceRelativePath);
    if (!is_file($sourcePath)) {
        fail("Approved source image is missing: {$sourceRelativePath}");
    }

    $imageSize = getimagesize($sourcePath);
    if ($imageSize === false || $imageSize[2] !== IMAGETYPE_JPEG) {
        fail("Approved source image is not a readable JPEG: {$sourceRelativePath}");
    }

    $source = imagecreatefromjpeg($sourcePath);
    if ($source === false) {
        fail("Unable to decode JPEG source: {$sourceRelativePath}");
    }

    $sourceWidth = $imageSize[0];
    $sourceHeight = $imageSize[1];
    $baseName = pathinfo($sourceRelativePath, PATHINFO_FILENAME);
    $derivatives = ['avif' => [], 'webp' => []];

    try {
        foreach (targetWidths($sourceWidth) as $targetWidth) {
            $targetHeight = imageHeightForWidth($sourceWidth, $sourceHeight, $targetWidth);
            $resized = imagecreatetruecolor($targetWidth, $targetHeight);
            if ($resized === false) {
                fail("Unable to allocate {$targetWidth}w image for {$sourceRelativePath}");
            }

            try {
                if (!imagecopyresampled($resized, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight)) {
                    fail("Unable to resize {$sourceRelativePath} to {$targetWidth}w");
                }

                foreach (['avif', 'webp'] as $format) {
                    $fileName = "{$baseName}-{$targetWidth}.{$format}";
                    $destination = $outputDir . DIRECTORY_SEPARATOR . $fileName;
                    $encoded = $format === 'avif'
                        ? imageavif($resized, $destination, AVIF_QUALITY, AVIF_SPEED)
                        : imagewebp($resized, $destination, WEBP_QUALITY);

                    if ($encoded !== true || !is_file($destination)) {
                        fail("Unable to encode {$format} derivative for {$sourceRelativePath} at {$targetWidth}w");
                    }

                    $bytes = filesize($destination);
                    $sha256 = hash_file('sha256', $destination);
                    if ($bytes === false || $sha256 === false) {
                        fail("Unable to inspect {$format} derivative for {$sourceRelativePath} at {$targetWidth}w");
                    }

                    $derivatives[$format][] = [
                        'file' => $outputRelativeDir . '/' . $fileName,
                        'width' => $targetWidth,
                        'height' => $targetHeight,
                        'sha256' => $sha256,
                        'bytes' => $bytes,
                    ];
                }
            } finally {
                imagedestroy($resized);
            }
        }
    } finally {
        imagedestroy($source);
    }

    return [
        'width' => $sourceWidth,
        'height' => $sourceHeight,
        'source_sha256' => hash_file('sha256', $sourcePath),
        'sources' => $derivatives,
    ];
}

function main(array $argv): void
{
    if (count($argv) !== 2) {
        fail('Usage: php scripts/build-responsive-images.php <fresh-output-directory>');
    }

    foreach (['imagecreatefromjpeg', 'imagewebp', 'imageavif'] as $function) {
        if (!function_exists($function)) {
            fail("Required GD codec is unavailable: {$function}");
        }
    }

    $repositoryRoot = dirname(__DIR__);
    $imageRoot = realpath($repositoryRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'images');
    if ($imageRoot === false) {
        fail('Unable to resolve public/images');
    }

    $requestedOutput = $argv[1];
    if (file_exists($requestedOutput)) {
        fail("Output directory must be fresh and must not already exist: {$requestedOutput}");
    }

    $outputParent = realpath(dirname($requestedOutput));
    if ($outputParent === false || !is_dir($outputParent)) {
        fail("Output directory parent must exist: " . dirname($requestedOutput));
    }

    $outputDir = $outputParent . DIRECTORY_SEPARATOR . basename($requestedOutput);
    $outputRelativeDir = 'responsive';

    $baseNames = [];
    foreach (APPROVED_IMAGES as $sourceRelativePath) {
        $baseName = pathinfo($sourceRelativePath, PATHINFO_FILENAME);
        if (isset($baseNames[$baseName])) {
            fail("Approved image basename collision: {$baseName}");
        }
        $baseNames[$baseName] = true;
    }

    if (!mkdir($outputDir, 0775)) {
        fail("Unable to create output directory: {$outputDir}");
    }

    $images = [];
    foreach (APPROVED_IMAGES as $sourceRelativePath) {
        $images[$sourceRelativePath] = buildImage($imageRoot, $outputDir, $outputRelativeDir, $sourceRelativePath);
    }

    $manifest = [
        'version' => 1,
        'images' => $images,
    ];
    $manifestPath = $outputDir . DIRECTORY_SEPARATOR . 'manifest.json';
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (file_put_contents($manifestPath, $json) === false) {
        fail('Unable to write responsive-image manifest');
    }

    fwrite(STDOUT, "Generated " . count($images) . " responsive image sets in {$outputRelativeDir}" . PHP_EOL);
}

if (PHP_SAPI !== 'cli') {
    exit('This generator may only run from the command line.' . PHP_EOL);
}

set_error_handler(static function (int $severity, string $message, string $file, int $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    main($argv);
} catch (Throwable $error) {
    fail($error->getMessage());
}
