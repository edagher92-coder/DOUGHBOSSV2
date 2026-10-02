<?php
/**
 * WP-01 tests: packaging and compatibility tooling. The PHP 7.4 guard (with planted-violation negative
 * controls), the zip builder and validator (run in a throwaway copy of the plugin so their refusals can be
 * provoked), the byte budget, the hygiene rules (no hero flag, no media plugin or zip, no secrets in CI) and the
 * CI workflow contract.
 *
 * @package DoughBoss_Growth
 */

require_once dirname( __DIR__ ) . '/scripts/php74-guard.php';
require_once dirname( __DIR__ ) . '/scripts/budgets.php';

$dbgr_plugin_dir = dirname( __DIR__ );

/**
 * Violation rule ids found in a source string.
 *
 * @param string $code PHP source.
 * @return array Sorted unique rule ids.
 */
function dbgr_guard_rules( $code ) {
	$rules = array();
	foreach ( DBGR_Php74_Guard::scan_source( $code ) as $found ) {
		$rules[] = $found[1];
	}
	$rules = array_values( array_unique( $rules ) );
	sort( $rules );
	return $rules;
}

/**
 * Copy the plugin runtime tree and its scripts into a fresh temp directory.
 *
 * @return string Directory (no trailing slash) holding doughboss-growth.php, includes/, admin/, scripts/ ...
 */
function dbgr_scripts_copy_plugin() {
	$src = dirname( __DIR__ );
	$dst = sys_get_temp_dir() . '/dbgr-plugin-' . bin2hex( random_bytes( 4 ) );
	mkdir( $dst, 0777, true );
	foreach ( array( 'doughboss-growth.php', 'uninstall.php', 'readme.txt' ) as $file ) {
		copy( $src . '/' . $file, $dst . '/' . $file );
	}
	foreach ( array( 'includes', 'admin', 'scripts', 'public', 'content' ) as $dir ) {
		if ( is_dir( $src . '/' . $dir ) ) {
			dbgr_scripts_copy_dir( $src . '/' . $dir, $dst . '/' . $dir );
		}
	}
	return $dst;
}

/**
 * Recursive copy.
 *
 * @param string $from Source.
 * @param string $to   Destination.
 * @return void
 */
function dbgr_scripts_copy_dir( $from, $to ) {
	mkdir( $to, 0777, true );
	foreach ( scandir( $from ) as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		if ( is_dir( $from . '/' . $entry ) ) {
			dbgr_scripts_copy_dir( $from . '/' . $entry, $to . '/' . $entry );
		} else {
			copy( $from . '/' . $entry, $to . '/' . $entry );
		}
	}
}

/**
 * Run a PHP script file in a sub-process.
 *
 * @param string $script Script path.
 * @param array  $args   Arguments.
 * @return array { exit, out, err }
 */
function dbgr_scripts_run( $script, array $args = array() ) {
	$command = array_merge( array( PHP_BINARY, $script ), $args );
	$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	$out     = stream_get_contents( $pipes[1] );
	$err     = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	return array(
		'exit' => proc_close( $process ),
		'out'  => (string) $out,
		'err'  => (string) $err,
	);
}

/**
 * Write a zip with the given entries.
 *
 * @param string $path    Zip path.
 * @param array  $entries Name => contents.
 * @return void
 */
function dbgr_scripts_make_zip( $path, array $entries ) {
	$zip = new ZipArchive();
	$zip->open( $path, ZipArchive::CREATE );
	foreach ( $entries as $name => $contents ) {
		$zip->addFromString( $name, $contents );
	}
	$zip->close();
}

/* ---------------------------------------------------------------------------------------------------------- */
/* PHP 7.4 guard                                                                                               */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'php74 guard POSITIVE control: the whole plugin tree (code, tests and scripts) has no PHP 8-only syntax',
	function () use ( $dbgr_plugin_dir ) {
		$result = DBGR_Php74_Guard::scan_paths( array( $dbgr_plugin_dir ) );
		assert_true( $result['files'] >= 15, 'a real tree was scanned (' . $result['files'] . ' files)' );
		$lines = array();
		foreach ( $result['violations'] as $violation ) {
			$lines[] = basename( $violation[0] ) . ':' . $violation[1] . ' ' . $violation[2];
		}
		assert_same( array(), $lines, 'no violations in the plugin tree' );
	}
);

db_test(
	'php74 guard NEGATIVE control: every PHP 8-only construct is caught, one rule at a time',
	function () {
		$planted = array(
			'match'                 => '<?php $x = match ( $a ) { 1 => "a", default => "b" };',
			'nullsafe'              => '<?php $x = $obj?->prop;',
			'typed_property'        => '<?php class A { private int $n = 1; }',
			'typed_property_static' => '<?php class A { public static ?array $cache = null; }',
			'arrow_fn'              => '<?php $f = fn( $x ) => $x * 2;',
			'php8_function'         => '<?php if ( str_contains( "abc", "b" ) ) { echo 1; }',
			'php8_function_ns'      => '<?php echo \\str_starts_with( "a", "a" );',
			'php8_function_ends'    => '<?php echo str_ends_with( "a", "a" );',
			'enum'                  => '<?php enum Suit { case Hearts; }',
			'readonly'              => '<?php class A { public readonly int $x; }',
			'named_args'            => '<?php foo( name: 1 );',
			'promotion'             => '<?php class A { public function __construct( private int $x ) {} }',
			'union_type'            => '<?php function f( int|string $x ) {}',
			'union_return'          => '<?php function f(): int|string { return 1; }',
			'php8_type'             => '<?php function f( mixed $x ) {}',
			'static_return'         => '<?php class A { public function f(): static { return $this; } }',
			'attribute'             => "<?php #[Attribute]\nclass A {}",
			'trailing_comma_params' => '<?php function f( $a, $b, ) {}',
			'catch_no_variable'     => '<?php try { f(); } catch ( Exception ) {}',
			'first_class_callable'  => '<?php $f = strlen( ... );',
			'octal_prefix'          => '<?php $n = 0o17;',
		);
		foreach ( $planted as $label => $code ) {
			$aliases  = array(
				'typed_property_static' => 'typed_property',
				'php8_function_ns'      => 'php8_function',
				'php8_function_ends'    => 'php8_function',
				'union_return'          => 'union_type',
			);
			$expected = isset( $aliases[ $label ] ) ? $aliases[ $label ] : $label;
			$found    = dbgr_guard_rules( $code );
			assert_true( in_array( $expected, $found, true ), 'the guard flags ' . $label . ' (found: ' . implode( ',', $found ) . ')' );
		}
	}
);

db_test(
	'php74 guard: valid PHP 7.4 code is NOT flagged (no false positives on the constructs the plugin uses)',
	function () {
		$good = <<<'CODE'
<?php
namespace Foo\Bar;
use Baz\Qux;
final class Good extends \Base implements \Countable {
	const FLAGS = array( 'a' => 1, 'b' => 2 );
	public $plain = 1;
	protected static $instance = null;
	private $list = array();
	public static $cache;
	public function f( array $x = array(), ?Foo $y = null, &$ref = null, ...$rest ): ?int {
		$t = $x ? FOO : BAR;
		foo( $t ? B : C, $t );
		$g = function ( $q ) use ( $t ) {
			return $q . $t;
		};
		if ( preg_match( '/x/', 'x' ) && strpos( 'abc', 'b' ) !== false ) {
			return null;
		}
		switch ( $t ) {
			case FOO:
				break;
			default:
				break;
		}
		return $this->match( $x ) ?: \Foo\bar( 1 );
	}
	public function match( $x ) { return $x; }
	public function str_contains_helper( $x ) { return $x; }
	public function g( $flags = A | B ) {
		try {
			return 1;
		} catch ( \Exception | \Error $e ) {
			return 2;
		}
	}
	#[\ReturnTypeWillChange]
	public function count() { return 0; }
	public function __construct() { $this->list = array_map( 'strtolower', array( 'A' ) ); }
}
function helper( $a ) : bool { return (bool) $a; }
$array = [ 1, 2, 3 ];
$s = "interpolation {$array[0]} and ${s}";
echo match_count( 1 ) . $obj->enum . $obj::READONLY;
CODE;
		assert_same( array(), dbgr_guard_rules( $good ), 'clean 7.4 code produces no violations' );
	}
);

db_test(
	'php74 guard: directory scan reports file, line, rule and exits non-zero; skips vendor, dist and node_modules',
	function () {
		$dir = sys_get_temp_dir() . '/dbgr-guard-' . bin2hex( random_bytes( 4 ) );
		foreach ( array( 'src', 'vendor', 'dist', 'node_modules' ) as $sub ) {
			mkdir( $dir . '/' . $sub, 0777, true );
		}
		file_put_contents( $dir . '/src/ok.php', '<?php echo 1;' );
		file_put_contents( $dir . '/src/bad.php', "<?php\n\n\$x = match ( 1 ) { 1 => 2 };\n" );
		foreach ( array( 'vendor', 'dist', 'node_modules' ) as $sub ) {
			file_put_contents( $dir . '/' . $sub . '/skip.php', '<?php $x = $a?->b;' );
		}
		$result = DBGR_Php74_Guard::scan_paths( array( $dir ) );
		assert_same( 2, $result['files'], 'only src/ok.php and src/bad.php were scanned' );
		assert_count( 1, $result['violations'], 'one violation' );
		assert_same( 'bad.php', basename( $result['violations'][0][0] ), 'file reported' );
		assert_same( 3, $result['violations'][0][1], 'line reported' );
		assert_same( 'match', $result['violations'][0][2], 'rule reported' );
		$missing = DBGR_Php74_Guard::scan_paths( array( $dir . '/does-not-exist' ) );
		assert_same( 'path', $missing['violations'][0][2], 'a missing path is a violation, not a silent pass' );

		if ( dbgr_test_can_subprocess() ) {
			$bad = dbgr_scripts_run( dirname( __DIR__ ) . '/scripts/php74-guard.php', array( $dir . '/src' ) );
			assert_same( 1, $bad['exit'], 'the CLI exits 1 on a violation' );
			assert_contains( 'bad.php:3 [match]', $bad['err'], 'CLI message names file, line and rule' );
			$good = dbgr_scripts_run( dirname( __DIR__ ) . '/scripts/php74-guard.php', array( $dir . '/src/ok.php' ) );
			assert_same( 0, $good['exit'], 'the CLI exits 0 on clean input' );
			$usage = dbgr_scripts_run( dirname( __DIR__ ) . '/scripts/php74-guard.php', array( '--bogus' ) );
			assert_same( 2, $usage['exit'], 'an unknown option is a usage error' );
		} else {
			dbgr_test_skip( 'sub-process unavailable: the guard CLI exit codes were not exercised' );
		}
		dbgr_test_rmdir( $dir );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Zip builder and validator                                                                                   */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'build-zip: builds, refuses to overwrite, and two builds are byte-identical (PHP 8+)',
	function () {
		if ( ! dbgr_test_can_subprocess() || ! class_exists( 'ZipArchive' ) ) {
			dbgr_test_skip( 'sub-process or ZipArchive unavailable' );
			return;
		}
		$plugin = dbgr_scripts_copy_plugin();
		$one    = $plugin . '/out/one.zip';
		$two    = $plugin . '/out/two.zip';
		$build  = dbgr_scripts_run( $plugin . '/scripts/build-zip.php', array( $one ) );
		assert_same( 0, $build['exit'], 'build succeeds: ' . $build['err'] );
		assert_contains( 'DoughBoss Growth archive (0.1.0', $build['out'], 'reports the version' );
		assert_true( is_file( $one ), 'zip exists' );

		$again = dbgr_scripts_run( $plugin . '/scripts/build-zip.php', array( $one ) );
		assert_same( 1, $again['exit'], 'a second build onto the same path is refused' );
		assert_contains( 'refusing to replace existing archive', $again['err'], 'with the overwrite message' );
		assert_same( hash_file( 'sha256', $one ), hash_file( 'sha256', $one ), 'sanity' );

		dbgr_scripts_run( $plugin . '/scripts/build-zip.php', array( $two ) );
		if ( method_exists( 'ZipArchive', 'setMtimeName' ) ) {
			assert_same( hash_file( 'sha256', $one ), hash_file( 'sha256', $two ), 'two builds of the same tree are byte-identical' );
		} else {
			dbgr_test_skip( 'ZipArchive::setMtimeName needs PHP 8.0: reproducible-bytes check not run on this PHP' );
		}

		$zip = new ZipArchive();
		$zip->open( $one );
		$names = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$names[] = $zip->getNameIndex( $i );
		}
		$zip->close();
		assert_true( in_array( 'doughboss-growth/doughboss-growth.php', $names, true ), 'main file packaged under the slug directory' );
		assert_same( $names, array_values( array_unique( $names ) ), 'no duplicate entries' );
		foreach ( $names as $name ) {
			assert_matches( '#^doughboss-growth/(doughboss-growth\.php|uninstall\.php|readme\.txt|(includes|admin|public|content|languages)/.+)$#', $name, 'whitelisted path: ' . $name );
		}
		assert_not_contains( 'scripts/', implode( "\n", $names ), 'scripts are not shipped' );
		assert_not_contains( 'tests/', implode( "\n", $names ), 'tests are not shipped' );

		$default = dbgr_scripts_run( $plugin . '/scripts/build-zip.php' );
		assert_same( 0, $default['exit'], 'the default output path works' );
		assert_true( is_file( $plugin . '/dist/doughboss-growth-0.1.0.zip' ), 'default archive name carries the version' );
		dbgr_test_rmdir( $plugin );
	}
);

db_test(
	'build-zip: refuses a version mismatch in each of the four places, and invalid metadata',
	function () {
		if ( ! dbgr_test_can_subprocess() || ! class_exists( 'ZipArchive' ) ) {
			dbgr_test_skip( 'sub-process or ZipArchive unavailable' );
			return;
		}
		$cases = array(
			'plugin header'    => array( 'doughboss-growth.php', ' * Version:           0.1.0', ' * Version:           0.1.1' ),
			'constant'         => array( 'doughboss-growth.php', "define( 'DOUGHBOSS_GROWTH_VERSION', '0.1.0' );", "define( 'DOUGHBOSS_GROWTH_VERSION', '0.2.0' );" ),
			'readme stable'    => array( 'readme.txt', 'Stable tag: 0.1.0', 'Stable tag: 0.1.2' ),
			'changelog'        => array( 'readme.txt', '= 0.1.0 =', '= 0.0.9 =' ),
			'non-semver'       => array( 'readme.txt', 'Stable tag: 0.1.0', 'Stable tag: trunk' ),
			'DB version gone'  => array( 'doughboss-growth.php', "define( 'DOUGHBOSS_GROWTH_DB_VERSION', '1.0.0' );", '' ),
		);
		foreach ( $cases as $label => $case ) {
			$plugin = dbgr_scripts_copy_plugin();
			$file   = $plugin . '/' . $case[0];
			$text   = file_get_contents( $file );
			assert_contains( $case[1], $text, $label . ': the fixture text to change exists' );
			file_put_contents( $file, str_replace( $case[1], $case[2], $text ) );
			$result = dbgr_scripts_run( $plugin . '/scripts/build-zip.php', array( $plugin . '/out.zip' ) );
			assert_same( 1, $result['exit'], $label . ': build refused' );
			assert_false( is_file( $plugin . '/out.zip' ), $label . ': no archive written' );
			dbgr_test_rmdir( $plugin );
		}
		// The unmodified copy is a positive control: the same fixtures build when untouched.
		$plugin = dbgr_scripts_copy_plugin();
		assert_same( 0, dbgr_scripts_run( $plugin . '/scripts/build-zip.php', array( $plugin . '/out.zip' ) )['exit'], 'control: an untouched copy builds' );
		dbgr_test_rmdir( $plugin );
	}
);

db_test(
	'build-zip: refuses an archive over the 1.0 MB budget, hidden/secret-looking files and linked files; writes nothing',
	function () {
		if ( ! dbgr_test_can_subprocess() || ! class_exists( 'ZipArchive' ) ) {
			dbgr_test_skip( 'sub-process or ZipArchive unavailable' );
			return;
		}
		assert_same( 1000000, DBGR_Budgets::ZIP_MAX_BYTES, 'the budget is 1.0 MB' );

		$plugin = dbgr_scripts_copy_plugin();
		is_dir( $plugin . '/content' ) || mkdir( $plugin . '/content', 0777, true ); // The plugin ships a content/ folder since WP-02.
		file_put_contents( $plugin . '/content/blob.bin', random_bytes( 1100000 ) ); // Incompressible.
		$result = dbgr_scripts_run( $plugin . '/scripts/build-zip.php', array( $plugin . '/out.zip' ) );
		assert_same( 1, $result['exit'], 'over budget: refused' );
		assert_contains( 'over budget', $result['err'], 'with the budget message' );
		assert_false( is_file( $plugin . '/out.zip' ), 'no archive left behind' );
		$left = glob( $plugin . '/.dbgr-build-*' );
		assert_same( array(), $left ? $left : array(), 'no temporary file left behind' );
		dbgr_test_rmdir( $plugin );

		foreach ( array( '.env', 'secret.pem', 'dump.sql', '.DS_Store', 'tool.sh', 'bundle.js.map', 'module.mjs', 'archive.zip' ) as $bad ) {
			$plugin = dbgr_scripts_copy_plugin();
			if ( ! is_dir( $plugin . '/public' ) ) {
				mkdir( $plugin . '/public', 0777, true );
			}
			file_put_contents( $plugin . '/public/' . $bad, 'x' );
			$result = dbgr_scripts_run( $plugin . '/scripts/build-zip.php', array( $plugin . '/out.zip' ) );
			assert_same( 1, $result['exit'], 'refused: public/' . $bad );
			dbgr_test_rmdir( $plugin );
		}

		$plugin = dbgr_scripts_copy_plugin();
		symlink( '/etc/hostname', $plugin . '/includes/linked.php' );
		$result = dbgr_scripts_run( $plugin . '/scripts/build-zip.php', array( $plugin . '/out.zip' ) );
		assert_same( 1, $result['exit'], 'a symlink inside a runtime directory is refused' );
		assert_contains( 'linked', $result['err'], 'with the link message' );
		dbgr_test_rmdir( $plugin );
	}
);

db_test(
	'budgets.php: reports within/over for an archive and treats a missing or empty file as a failure',
	function () {
		$tmp = sys_get_temp_dir() . '/dbgr-budget-' . bin2hex( random_bytes( 4 ) ) . '.bin';
		file_put_contents( $tmp, str_repeat( 'x', 500000 ) );
		$r = DBGR_Budgets::check_zip( $tmp );
		assert_true( $r['ok'], 'half the budget is within budget' );
		assert_contains( '50%', $r['message'], 'percentage reported' );
		file_put_contents( $tmp, str_repeat( 'x', 1000000 ) );
		assert_true( DBGR_Budgets::check_zip( $tmp )['ok'], 'exactly the budget is allowed' );
		file_put_contents( $tmp, str_repeat( 'x', 1000001 ) );
		$r = DBGR_Budgets::check_zip( $tmp );
		assert_false( $r['ok'], 'one byte over is refused' );
		assert_contains( 'OVER', $r['message'], 'message says OVER' );
		file_put_contents( $tmp, '' );
		assert_false( DBGR_Budgets::check_zip( $tmp )['ok'], 'an empty file is refused' );
		unlink( $tmp );
		assert_false( DBGR_Budgets::check_zip( $tmp )['ok'], 'a missing file is refused' );
	}
);

db_test(
	'validate-zip: accepts a fresh build and byte-compares it against the CURRENT tree',
	function () {
		if ( ! dbgr_test_can_subprocess() || ! class_exists( 'ZipArchive' ) ) {
			dbgr_test_skip( 'sub-process or ZipArchive unavailable' );
			return;
		}
		$plugin = dbgr_scripts_copy_plugin();
		$zip    = $plugin . '/out.zip';
		dbgr_scripts_run( $plugin . '/scripts/build-zip.php', array( $zip ) );
		$ok = dbgr_scripts_run( $plugin . '/scripts/validate-zip.php', array( $zip ) );
		assert_same( 0, $ok['exit'], 'a fresh build validates: ' . $ok['err'] );
		assert_contains( 'valid (0.1.0', $ok['out'], 'reports the version' );

		// Stale zip: change a source byte after the build.
		$file = $plugin . '/includes/class-doughboss-growth-http.php';
		file_put_contents( $file, file_get_contents( $file ) . "\n// edited after the build\n" );
		$stale = dbgr_scripts_run( $plugin . '/scripts/validate-zip.php', array( $zip ) );
		assert_same( 1, $stale['exit'], 'a zip that no longer matches the tree is refused' );
		assert_contains( 'content mismatch', $stale['err'], 'with the content mismatch message' );

		// Added file after the build.
		dbgr_scripts_copy_dir( dirname( __DIR__ ) . '/includes', $plugin . '/includes-copy' );
		dbgr_test_rmdir( $plugin . '/includes-copy' );
		$plugin2 = dbgr_scripts_copy_plugin();
		$zip2    = $plugin2 . '/out.zip';
		dbgr_scripts_run( $plugin2 . '/scripts/build-zip.php', array( $zip2 ) );
		file_put_contents( $plugin2 . '/includes/new-file.php', "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n" );
		$added = dbgr_scripts_run( $plugin2 . '/scripts/validate-zip.php', array( $zip2 ) );
		assert_same( 1, $added['exit'], 'a file added after the build is a tree mismatch' );
		assert_contains( 'file mismatch', $added['err'], 'file-set mismatch reported' );
		dbgr_test_rmdir( $plugin );
		dbgr_test_rmdir( $plugin2 );

		// Usage errors.
		$none = dbgr_scripts_run( dirname( __DIR__ ) . '/scripts/validate-zip.php', array( '/nonexistent.zip' ) );
		assert_same( 1, $none['exit'], 'a missing archive is refused' );
	}
);

db_test(
	'validate-zip NEGATIVE controls: traversal, wrong root, extra directories, duplicates, hidden files, missing guard, bad versions, over budget',
	function () {
		if ( ! dbgr_test_can_subprocess() || ! class_exists( 'ZipArchive' ) ) {
			dbgr_test_skip( 'sub-process or ZipArchive unavailable' );
			return;
		}
		$plugin  = dbgr_scripts_copy_plugin();
		$script  = $plugin . '/scripts/validate-zip.php';
		$build   = $plugin . '/good.zip';
		dbgr_scripts_run( $plugin . '/scripts/build-zip.php', array( $build ) );
		$good = new ZipArchive();
		$good->open( $build );
		$entries = array();
		for ( $i = 0; $i < $good->numFiles; $i++ ) {
			$name             = $good->getNameIndex( $i );
			$entries[ $name ] = $good->getFromName( $name );
		}
		$good->close();
		assert_same( 0, dbgr_scripts_run( $script, array( $build ) )['exit'], 'control: the untouched build validates' );

		$mutations = array(
			'path traversal'        => $entries + array( 'doughboss-growth/includes/../../evil.php' => '<?php' ),
			'wrong root directory'  => array( 'wrong-root/doughboss-growth.php' => '<?php' ) + $entries,
			'tests directory'       => $entries + array( 'doughboss-growth/tests/x.php' => '<?php' ),
			'scripts directory'     => $entries + array( 'doughboss-growth/scripts/build-zip.php' => '<?php' ),
			'hidden file'           => $entries + array( 'doughboss-growth/includes/.hidden' => 'x' ),
			'env file'              => $entries + array( 'doughboss-growth/includes/prod.env' => 'KEY=value' ),
			'directory entry'       => $entries + array( 'doughboss-growth/includes/sub/' => '' ),
			'backslash path'        => $entries + array( 'doughboss-growth\\includes\\x.php' => '<?php' ),
		);
		foreach ( $mutations as $label => $changed ) {
			$path = $plugin . '/mut.zip';
			@unlink( $path );
			dbgr_scripts_make_zip( $path, $changed );
			$r = dbgr_scripts_run( $script, array( $path ) );
			assert_same( 1, $r['exit'], 'refused: ' . $label . ' (' . trim( $r['err'] ) . ')' );
		}

		// Missing required entry.
		$less = $entries;
		unset( $less['doughboss-growth/includes/class-doughboss-growth-settings.php'] );
		$path = $plugin . '/less.zip';
		dbgr_scripts_make_zip( $path, $less );
		$r = dbgr_scripts_run( $script, array( $path ) );
		assert_same( 1, $r['exit'], 'refused: a required entry is missing' );
		assert_contains( 'required archive entry missing', $r['err'], 'with the missing-entry message' );

		// A PHP file without the direct-access guard.
		$unguarded = $entries;
		$unguarded['doughboss-growth/includes/class-doughboss-growth-http.php'] = "<?php\nfinal class Unguarded {}\n";
		$path = $plugin . '/unguarded.zip';
		dbgr_scripts_make_zip( $path, $unguarded );
		$r = dbgr_scripts_run( $script, array( $path ) );
		assert_same( 1, $r['exit'], 'refused: a PHP file without an ABSPATH guard' );
		assert_contains( 'direct-access guard', $r['err'], 'with the guard message' );

		// Archive and tree agree with each other but the four version places do not.
		$plugin3 = dbgr_scripts_copy_plugin();
		$readme  = file_get_contents( $plugin3 . '/readme.txt' );
		file_put_contents( $plugin3 . '/readme.txt', str_replace( 'Stable tag: 0.1.0', 'Stable tag: 0.1.9', $readme ) );
		$entries2                                 = $entries;
		$entries2['doughboss-growth/readme.txt'] = str_replace( 'Stable tag: 0.1.0', 'Stable tag: 0.1.9', $entries['doughboss-growth/readme.txt'] );
		$path                                     = $plugin3 . '/ver.zip';
		dbgr_scripts_make_zip( $path, $entries2 );
		$r = dbgr_scripts_run( $plugin3 . '/scripts/validate-zip.php', array( $path ) );
		assert_same( 1, $r['exit'], 'refused: archive version metadata mismatch' );
		assert_contains( 'version mismatch', $r['err'], 'with the version message' );

		// Over budget: pad a content file in both the tree and the archive so they still agree.
		$plugin4 = dbgr_scripts_copy_plugin();
		is_dir( $plugin4 . '/content' ) || mkdir( $plugin4 . '/content', 0777, true );
		$blob = random_bytes( 1100000 );
		file_put_contents( $plugin4 . '/content/blob.bin', $blob );
		$entries3                               = $entries;
		$entries3['doughboss-growth/content/blob.bin'] = $blob;
		$path                                   = $plugin4 . '/big.zip';
		dbgr_scripts_make_zip( $path, $entries3 );
		$r = dbgr_scripts_run( $plugin4 . '/scripts/validate-zip.php', array( $path ) );
		assert_same( 1, $r['exit'], 'refused: archive over budget even though it matches the tree' );
		assert_contains( 'over budget', $r['err'], 'with the budget message' );
		foreach ( array( $plugin, $plugin3, $plugin4 ) as $dir ) {
			dbgr_test_rmdir( $dir );
		}
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* Hygiene: scope decisions                                                                                    */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'scope: no hero flag, no media plugin or media zip, and no 3D / WebGL / frame-player code in anything that ships or runs (docs/ hand-off notes may describe the cancellation)',
	function () use ( $dbgr_plugin_dir ) {
		$banned = array(
			'hero' . '_enhanced',
			'growth' . '-media',
			'Growth' . '_Media',
			'media' . ' pack',
			'media' . '-pack',
			'web' . 'gl',
			'three' . '.js',
			'.g' . 'lb',
			'frame' . ' player',
			'hero' . '-manifest',
		);
		$hits = array();
		$iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dbgr_plugin_dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iter as $file ) {
			$path = $file->getPathname();
			if ( ! $file->isFile() || preg_match( '#/(dist|node_modules|vendor|docs)/#', $path ) || false !== strpos( $path, '/tests/test-core-scripts.php' ) ) {
				continue;
			}
			$text = strtolower( (string) file_get_contents( $path ) );
			foreach ( $banned as $needle ) {
				if ( false !== strpos( $text, strtolower( $needle ) ) ) {
					$hits[] = str_replace( $dbgr_plugin_dir . '/', '', $path ) . ' has "' . $needle . '"';
				}
			}
			// The word "hero" must not appear in settings, admin, readme, scripts or the main file.
			if ( preg_match( '#/(includes/class-doughboss-growth-(settings|activator)|admin/|readme\.txt|doughboss-growth\.php|scripts/)#', $path ) && false !== strpos( $text, 'hero' ) ) {
				$hits[] = str_replace( $dbgr_plugin_dir . '/', '', $path ) . ' mentions the cancelled hero work';
			}
		}
		assert_same( array(), $hits, 'no cancelled-scope reference' );
		assert_false( is_dir( dirname( $dbgr_plugin_dir ) . '/doughboss-growth' . '-media' ), 'the media plugin directory is gone' );
	}
);

db_test(
	'scope: the word Minis appears in no shipped file (teaser direction) and every shipped PHP file has an ABSPATH guard',
	function () use ( $dbgr_plugin_dir ) {
		$missing_guard = array();
		$minis         = array();
		foreach ( array( 'includes', 'admin', 'public', 'content' ) as $sub ) {
			if ( ! is_dir( $dbgr_plugin_dir . '/' . $sub ) ) {
				continue;
			}
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dbgr_plugin_dir . '/' . $sub, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				$path = $file->getPathname();
				$text = (string) file_get_contents( $path );
				if ( 1 === preg_match( '/minis/i', $text ) && ! preg_match( '#class-doughboss-growth-settings\.php$#', $path ) ) {
					$minis[] = $path;
				}
				if ( '.php' === substr( $path, -4 ) && ! preg_match( "/defined\\(\\s*'ABSPATH'\\s*\\)/", $text ) ) {
					$missing_guard[] = str_replace( $dbgr_plugin_dir . '/', '', $path );
				}
			}
		}
		assert_same( array(), $minis, 'the working name appears only in the settings guard that REJECTS it' );
		assert_same( array(), $missing_guard, 'every shipped PHP file is guarded' );
		$main = file_get_contents( $dbgr_plugin_dir . '/doughboss-growth.php' );
		assert_matches( "/defined\\( 'ABSPATH' \\)/", $main, 'main file guarded' );
		$uninstall = file_get_contents( $dbgr_plugin_dir . '/uninstall.php' );
		assert_matches( "/defined\\( 'WP_UNINSTALL_PLUGIN' \\)/", $uninstall, 'uninstall.php guarded by WP_UNINSTALL_PLUGIN' );
	}
);

db_test(
	'scope: shipped code uses wp_remote_* only through the HTTP wrapper and never hard-codes a provider host or secret',
	function () use ( $dbgr_plugin_dir ) {
		$offenders = array();
		foreach ( array( 'includes', 'admin' ) as $sub ) {
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dbgr_plugin_dir . '/' . $sub, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				$path = $file->getPathname();
				$text = (string) file_get_contents( $path );
				if ( ! preg_match( '#class-doughboss-growth-http\.php$#', $path ) && preg_match( '/\bwp_(safe_)?remote_(request|get|post|head)\s*\(/', $text ) ) {
					$offenders[] = basename( $path ) . ' calls wp_remote_* directly';
				}
				if ( preg_match( '#https?://(www\.)?(google|facebook|graph\.facebook|googletagmanager|square|squareup)#i', $text ) ) {
					$offenders[] = basename( $path ) . ' hard-codes a provider URL';
				}
				if ( preg_match( '/(api[_-]?secret|capi[_-]?token|bearer)\s*[=:]\s*[\'"][A-Za-z0-9_\-]{12,}/i', $text ) ) {
					$offenders[] = basename( $path ) . ' looks like it embeds a secret';
				}
			}
		}
		assert_same( array(), $offenders, 'no direct HTTP, provider host or secret literal' );
	}
);

/* ---------------------------------------------------------------------------------------------------------- */
/* CI workflow contract                                                                                        */
/* ---------------------------------------------------------------------------------------------------------- */

db_test(
	'CI workflow: PHP 7.4 and 8.2 matrix, lint, 7.4 guard, tests, ES5 gate, node tests, zip build and validate; no secrets, read-only token',
	function () use ( $dbgr_plugin_dir ) {
		$path = dirname( $dbgr_plugin_dir ) . '/.github/workflows/growth-ci.yml';
		if ( ! is_file( $path ) ) {
			dbgr_test_skip( 'workflow file not present in this checkout' );
			return;
		}
		$yml = (string) file_get_contents( $path );
		assert_matches( '/php-version:\s*\["7\.4",\s*"8\.2"\]/', $yml, 'matrix has 7.4 and 8.2' );
		assert_contains( 'php -l', $yml, 'lint step' );
		assert_contains( 'scripts/php74-guard.php', $yml, '7.4 guard step' );
		assert_contains( 'tests/run.php', $yml, 'PHP test step' );
		assert_contains( 'scripts/es5-check.mjs', $yml, 'ES5 gate step' );
		assert_contains( 'node --test', $yml, 'node tests step' );
		assert_contains( 'scripts/build-zip.php', $yml, 'zip build step' );
		assert_contains( 'scripts/validate-zip.php', $yml, 'zip validate step' );
		assert_contains( 'scripts/budgets.php', $yml, 'budget step' );
		assert_matches( '/permissions:\s*\n\s*contents:\s*read/', $yml, 'read-only token permissions' );
		assert_not_contains( 'secrets.', $yml, 'no secret reference' );
		assert_not_contains( '${{ secrets', $yml, 'no secret expression' );
		assert_not_contains( 'pull_request_target', $yml, 'no privileged pull-request trigger' );
		assert_same( 0, preg_match( '/hero|media/i', $yml ), 'no hero or media-pack step' );
		assert_matches( '/ACORN_PATH/', $yml, 'acorn comes from a scratch install, not the repository' );
		$uses = array();
		preg_match_all( '/uses:\s*(\S+)/', $yml, $uses );
		foreach ( $uses[1] as $action ) {
			assert_matches( '#^[a-z0-9-]+/[a-z0-9-]+@v[0-9]+$#i', $action, 'action pinned to a major version: ' . $action );
		}
	}
);
