<?php
/**
 * PHP 7.4 syntax guard for the DoughBoss Growth companion.
 *
 * `php -l` on PHP 8.x happily accepts code that breaks on the host's older PHP. This script reads every
 * .php file with token_get_all() and fails on syntax and functions that need PHP 8:
 *
 *   match, nullsafe (?->), typed class properties, arrow functions (fn), str_contains /
 *   str_starts_with / str_ends_with (and a few other 8.x functions), enum, readonly, named arguments,
 *   constructor property promotion, union types, intersection types, the mixed / never / static return
 *   types, attributes, a trailing comma in a parameter list, catch without a variable, first-class
 *   callable syntax and the 0o octal prefix.
 *
 * It works on a normalised token stream, so a 7.4 run (where PHP 8 syntax tokenises as ordinary strings
 * and symbols) and an 8.x run (which has dedicated tokens) reach the same verdicts.
 *
 * Usage:   php scripts/php74-guard.php [path ...]       (default: the plugin directory)
 * Exit:    0 clean, 1 violations found, 2 usage error.
 *
 * This file is also loaded as a library by tests/test-core-scripts.php (it only runs main() when it is the
 * script PHP was started with). It must itself stay PHP 7.4 clean: the guard scans its own source.
 *
 * @package DoughBoss_Growth
 */

/**
 * Token-level PHP 7.4 compatibility checker.
 */
final class DBGR_Php74_Guard {

	/**
	 * Functions that exist only from PHP 8.0 or later.
	 */
	const BANNED_FUNCTIONS = array(
		'str_contains',
		'str_starts_with',
		'str_ends_with',
		'array_is_list',
		'get_debug_type',
		'get_resource_id',
		'fdiv',
		'preg_last_error_msg',
		'enum_exists',
	);

	/**
	 * Directory names never scanned.
	 */
	const SKIP_DIRS = array( '.git', 'node_modules', 'vendor', 'dist' );

	/**
	 * Modifiers that start a class member declaration.
	 */
	const MODIFIERS = array( 'T_PUBLIC', 'T_PROTECTED', 'T_PRIVATE', 'T_STATIC', 'T_VAR', 'T_FINAL', 'T_ABSTRACT', 'T_READONLY_WORD' );

	/**
	 * Scan files and directories.
	 *
	 * @param array $paths Files or directories.
	 * @return array { files: int, violations: array } violations are array( file, line, rule, detail ).
	 */
	public static function scan_paths( array $paths ) {
		$files      = array();
		$violations = array();
		foreach ( $paths as $path ) {
			if ( is_file( $path ) ) {
				$files[] = $path;
			} elseif ( is_dir( $path ) ) {
				self::collect( $path, $files );
			} else {
				$violations[] = array( $path, 0, 'path', 'path does not exist' );
			}
		}
		sort( $files );
		foreach ( $files as $file ) {
			$code = file_get_contents( $file );
			if ( false === $code ) {
				$violations[] = array( $file, 0, 'read', 'could not read the file' );
				continue;
			}
			foreach ( self::scan_source( $code ) as $found ) {
				$violations[] = array( $file, $found[0], $found[1], $found[2] );
			}
		}
		return array(
			'files'      => count( $files ),
			'violations' => $violations,
		);
	}

	/**
	 * Recursively collect .php files.
	 *
	 * @param string $dir   Directory.
	 * @param array  $files Collected files (by reference).
	 * @return void
	 */
	private static function collect( $dir, array &$files ) {
		$entries = scandir( $dir );
		if ( false === $entries ) {
			return;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$full = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $entry;
			if ( is_link( $full ) ) {
				continue;
			}
			if ( is_dir( $full ) ) {
				if ( ! in_array( $entry, self::SKIP_DIRS, true ) ) {
					self::collect( $full, $files );
				}
				continue;
			}
			if ( '.php' === strtolower( substr( $entry, -4 ) ) ) {
				$files[] = $full;
			}
		}
	}

	/**
	 * Scan one source string.
	 *
	 * @param string $code PHP source.
	 * @return array List of array( line, rule, detail ).
	 */
	public static function scan_source( $code ) {
		$tokens = self::normalise( $code );
		$found  = array();
		$count  = count( $tokens );
		$stack  = array();       // Brace kinds: class | function | block.
		$parens = 0;             // Paren depth (all kinds).
		$stmt   = array();       // Token indexes of the current statement at class level.
		$var_checked = false;

		for ( $i = 0; $i < $count; $i++ ) {
			$type = $tokens[ $i ][0];
			$text = $tokens[ $i ][1];
			$line = $tokens[ $i ][2];
			$prev = ( $i > 0 ) ? $tokens[ $i - 1 ] : array( '', '', 0 );
			$next = ( $i + 1 < $count ) ? $tokens[ $i + 1 ] : array( '', '', 0 );
			$lc   = strtolower( ltrim( $text, '\\' ) );

			// Attributes.
			if ( 'ATTRIBUTE' === $type ) {
				// #[\ReturnTypeWillChange] is the one tolerated attribute: PHP 7.4 reads it as a comment and
				// PHP 8.1+ uses it to silence a return-type deprecation (WordPress core does the same).
				$benign = ( 1 === preg_match( '/^#\[\\\\?ReturnTypeWillChange\]\s*$/', $text ) )
					|| ( '#[' === $text && 'T_STRING' === $next[0] && 'returntypewillchange' === strtolower( ltrim( $next[1], '\\' ) ) && $i + 2 < $count && ']' === $tokens[ $i + 2 ][0] );
				if ( ! $benign ) {
					$found[] = array( $line, 'attribute', 'attributes (#[...]) need PHP 8.0' );
				}
				continue;
			}
			if ( 'OCTAL_PREFIX' === $type ) {
				$found[] = array( $line, 'octal_prefix', 'the 0o octal prefix needs PHP 8.1' );
				continue;
			}

			// 0o17 reads as the number 0 followed by the name o17 on PHP 7.4.
			if ( 'T_LNUMBER' === $type && '0' === $text && 'T_STRING' === $next[0] && 1 === preg_match( '/^[oO][0-7]+$/', $next[1] ) ) {
				$found[] = array( $line, 'octal_prefix', 'the 0o octal prefix needs PHP 8.1' );
			}

			// Nullsafe operator: the normaliser turned it into "?" immediately followed by "->".
			if ( '?' === $type && 'T_OBJECT_OPERATOR' === $next[0] ) {
				$found[] = array( $line, 'nullsafe', 'nullsafe operator (?->) needs PHP 8.0' );
			}

			if ( 'T_READONLY_WORD' === $type && ! in_array( $prev[0], array( 'T_OBJECT_OPERATOR', 'T_DOUBLE_COLON', 'T_FUNCTION', 'T_CONST' ), true ) ) {
				$found[] = array( $line, 'readonly', 'readonly needs PHP 8.1' );
			}

			if ( 'T_STRING' === $type ) {
				$after_member = in_array( $prev[0], array( 'T_OBJECT_OPERATOR', 'T_DOUBLE_COLON', 'T_FUNCTION', 'T_CONST', 'T_NEW' ), true );

				// match ( ... ) { ... }
				if ( 'match' === $lc && ! $after_member && '(' === $next[0] ) {
					$close = self::matching( $tokens, $i + 1 );
					if ( null !== $close && $close + 1 < $count && '{' === $tokens[ $close + 1 ][0] ) {
						$found[] = array( $line, 'match', 'match expression needs PHP 8.0' );
					}
				}
				// Functions that need PHP 8.
				if ( ! $after_member && '(' === $next[0] && in_array( $lc, self::BANNED_FUNCTIONS, true ) ) {
					$found[] = array( $line, 'php8_function', $lc . '() needs PHP 8' );
				}
				// enum Name { / enum Name: type {
				if ( 'enum' === $lc && ! $after_member && 'T_STRING' === $next[0] && $i + 2 < $count
					&& ( '{' === $tokens[ $i + 2 ][0] || ':' === $tokens[ $i + 2 ][0] || 'T_IMPLEMENTS' === $tokens[ $i + 2 ][0] ) ) {
					$found[] = array( $line, 'enum', 'enum needs PHP 8.1' );
				}
				// readonly modifier.
				if ( 'readonly' === $lc && ! $after_member && '(' !== $next[0] && in_array( $next[0], array( 'T_VARIABLE', 'T_STRING', 'T_STATIC', 'T_FUNCTION', 'T_ARRAY', 'T_CALLABLE', 'T_CLASS', 'T_FINAL', 'T_ABSTRACT', 'T_PUBLIC', 'T_PROTECTED', 'T_PRIVATE', '?' ), true ) ) {
					$found[] = array( $line, 'readonly', 'readonly needs PHP 8.1' );
				}
				// Named arguments: ( or , then a bare name then a single colon.
				if ( ':' === $next[0] && in_array( $prev[0], array( '(', ',' ), true ) && $parens > 0 ) {
					$found[] = array( $line, 'named_args', 'named argument (' . $text . ':) needs PHP 8.0' );
				}
			}

			// Arrow function.
			if ( 'T_FN' === $type ) {
				$found[] = array( $line, 'arrow_fn', 'arrow function (fn) is not allowed' );
			}

			// First-class callable syntax: ( ... )
			if ( '(' === $type && 'T_ELLIPSIS' === $next[0] && $i + 2 < $count && ')' === $tokens[ $i + 2 ][0] ) {
				$found[] = array( $line, 'first_class_callable', 'first-class callable syntax needs PHP 8.1' );
			}

			// catch ( Type ) without a variable.
			if ( 'T_CATCH' === $type && '(' === $next[0] ) {
				$close = self::matching( $tokens, $i + 1 );
				$has   = false;
				if ( null !== $close ) {
					for ( $k = $i + 2; $k < $close; $k++ ) {
						if ( 'T_VARIABLE' === $tokens[ $k ][0] ) {
							$has = true;
						}
					}
					if ( ! $has ) {
						$found[] = array( $line, 'catch_no_variable', 'catch without a variable needs PHP 8.0' );
					}
				}
			}

			// Function and closure signatures.
			if ( 'T_FUNCTION' === $type ) {
				$found = array_merge( $found, self::check_signature( $tokens, $i ) );
			}

			// Typed class properties and brace tracking.
			if ( '(' === $type ) {
				$parens++;
			} elseif ( ')' === $type ) {
				$parens = max( 0, $parens - 1 );
			}
			if ( '{' === $type ) {
				$stack[] = self::brace_kind( $tokens, $i );
				$stmt    = array();
				$var_checked = false;
				continue;
			}
			if ( 'T_CURLY_OPEN' === $type || 'T_DOLLAR_OPEN_CURLY_BRACES' === $type ) {
				$stack[] = 'block';
				continue;
			}
			if ( '}' === $type ) {
				array_pop( $stack );
				$stmt        = array();
				$var_checked = false;
				continue;
			}
			if ( ';' === $type ) {
				$stmt        = array();
				$var_checked = false;
				continue;
			}
			$in_class = ( array() !== $stack && 'class' === $stack[ count( $stack ) - 1 ] && 0 === $parens );
			if ( $in_class ) {
				if ( 'T_VARIABLE' === $type && ! $var_checked ) {
					$var_checked = true;
					$found       = array_merge( $found, self::check_property( $tokens, $stmt, $line ) );
				}
				$stmt[] = $i;
			}
		}
		return $found;
	}

	/**
	 * Check a class member declaration that ends in its first variable.
	 *
	 * @param array $tokens Normalised tokens.
	 * @param array $stmt   Indexes of the statement tokens before the variable.
	 * @param int   $line   Line of the variable.
	 * @return array Violations.
	 */
	private static function check_property( array $tokens, array $stmt, $line ) {
		$has_modifier = false;
		$last_modifier = -1;
		foreach ( $stmt as $position => $index ) {
			$type = $tokens[ $index ][0];
			if ( in_array( $type, array( 'T_FUNCTION', 'T_CONST', 'T_USE', 'T_CASE' ), true ) ) {
				return array();
			}
			if ( in_array( $type, self::MODIFIERS, true ) ) {
				$has_modifier  = true;
				$last_modifier = $position;
			}
		}
		if ( ! $has_modifier ) {
			return array();
		}
		// Anything after the last modifier and before the variable is a type.
		if ( $last_modifier + 1 < count( $stmt ) ) {
			return array( array( $line, 'typed_property', 'typed class property is not allowed (no typed properties in this project)' ) );
		}
		return array();
	}

	/**
	 * Check a function or closure signature: parameters and return type.
	 *
	 * @param array $tokens Normalised tokens.
	 * @param int   $at     Index of the T_FUNCTION token.
	 * @return array Violations.
	 */
	private static function check_signature( array $tokens, $at ) {
		$found = array();
		$count = count( $tokens );
		$open  = null;
		for ( $k = $at + 1; $k < $count && $k < $at + 6; $k++ ) {
			if ( '(' === $tokens[ $k ][0] ) {
				$open = $k;
				break;
			}
			if ( ! in_array( $tokens[ $k ][0], array( '&', 'T_STRING' ), true ) ) {
				break;
			}
		}
		if ( null === $open ) {
			return $found;
		}
		$close = self::matching( $tokens, $open );
		if ( null === $close ) {
			return $found;
		}
		$found = array_merge( $found, self::check_param_list( $tokens, $open, $close ) );

		$after = $close + 1;
		// Closure "use" list.
		if ( $after < $count && 'T_USE' === $tokens[ $after ][0] && $after + 1 < $count && '(' === $tokens[ $after + 1 ][0] ) {
			$use_close = self::matching( $tokens, $after + 1 );
			if ( null !== $use_close ) {
				if ( ',' === $tokens[ $use_close - 1 ][0] ) {
					$found[] = array( $tokens[ $use_close ][2], 'trailing_comma_params', 'trailing comma in a closure use list needs PHP 8.0' );
				}
				$after = $use_close + 1;
			}
		}
		// Return type.
		if ( $after < $count && ':' === $tokens[ $after ][0] ) {
			$k = $after + 1;
			while ( $k < $count && ! in_array( $tokens[ $k ][0], array( '{', ';', 'T_DOUBLE_ARROW' ), true ) ) {
				$t = $tokens[ $k ];
				if ( '|' === $t[0] ) {
					$found[] = array( $t[2], 'union_type', 'union types need PHP 8.0' );
				}
				if ( '&' === $t[0] && $k + 1 < $count && 'T_STRING' === $tokens[ $k + 1 ][0] ) {
					$found[] = array( $t[2], 'intersection_type', 'intersection types need PHP 8.1' );
				}
				if ( 'T_STRING' === $t[0] && in_array( strtolower( $t[1] ), array( 'mixed', 'never' ), true ) ) {
					$found[] = array( $t[2], 'php8_type', strtolower( $t[1] ) . ' type needs PHP 8' );
				}
				if ( 'T_STATIC' === $t[0] ) {
					$found[] = array( $t[2], 'static_return', 'static return type needs PHP 8.0' );
				}
				$k++;
			}
		}
		return $found;
	}

	/**
	 * Check the parameter list between two parentheses.
	 *
	 * @param array $tokens Normalised tokens.
	 * @param int   $open   Index of "(".
	 * @param int   $close  Index of ")".
	 * @return array Violations.
	 */
	private static function check_param_list( array $tokens, $open, $close ) {
		$found = array();
		if ( $close - 1 > $open && ',' === $tokens[ $close - 1 ][0] ) {
			$found[] = array( $tokens[ $close ][2], 'trailing_comma_params', 'trailing comma in a parameter list needs PHP 8.0' );
		}
		$depth   = 0;
		$in_type = true;
		for ( $k = $open + 1; $k < $close; $k++ ) {
			$t = $tokens[ $k ];
			if ( '(' === $t[0] || '[' === $t[0] ) {
				$depth++;
				continue;
			}
			if ( ')' === $t[0] || ']' === $t[0] ) {
				$depth--;
				continue;
			}
			if ( 0 !== $depth ) {
				continue;
			}
			if ( ',' === $t[0] ) {
				$in_type = true;
				continue;
			}
			if ( '=' === $t[0] ) {
				$in_type = false;
				continue;
			}
			if ( ! $in_type ) {
				continue;
			}
			if ( 'T_VARIABLE' === $t[0] ) {
				$in_type = false;
				continue;
			}
			if ( in_array( $t[0], array( 'T_PUBLIC', 'T_PROTECTED', 'T_PRIVATE', 'T_READONLY_WORD' ), true ) ) {
				$found[] = array( $t[2], 'promotion', 'constructor property promotion needs PHP 8.0' );
			}
			if ( '|' === $t[0] ) {
				$found[] = array( $t[2], 'union_type', 'union types need PHP 8.0' );
			}
			if ( '&' === $t[0] && $k + 1 < $close && 'T_STRING' === $tokens[ $k + 1 ][0] ) {
				$found[] = array( $t[2], 'intersection_type', 'intersection types need PHP 8.1' );
			}
			if ( 'T_STRING' === $t[0] && in_array( strtolower( $t[1] ), array( 'mixed', 'never' ), true ) ) {
				$found[] = array( $t[2], 'php8_type', strtolower( $t[1] ) . ' type needs PHP 8' );
			}
		}
		return $found;
	}

	/**
	 * Index of the bracket that closes the one at $open.
	 *
	 * @param array $tokens Normalised tokens.
	 * @param int   $open   Index of an opening ( [ or {.
	 * @return int|null
	 */
	private static function matching( array $tokens, $open ) {
		$pairs = array(
			'(' => ')',
			'[' => ']',
			'{' => '}',
		);
		$start = $tokens[ $open ][0];
		if ( ! isset( $pairs[ $start ] ) ) {
			return null;
		}
		$end   = $pairs[ $start ];
		$depth = 0;
		$count = count( $tokens );
		for ( $k = $open; $k < $count; $k++ ) {
			if ( $start === $tokens[ $k ][0] ) {
				$depth++;
			} elseif ( $end === $tokens[ $k ][0] ) {
				$depth--;
				if ( 0 === $depth ) {
					return $k;
				}
			}
		}
		return null;
	}

	/**
	 * What kind of block a "{" opens: class, function or block.
	 *
	 * @param array $tokens Normalised tokens.
	 * @param int   $at     Index of "{".
	 * @return string
	 */
	private static function brace_kind( array $tokens, $at ) {
		for ( $k = $at - 1; $k >= 0; $k-- ) {
			$type = $tokens[ $k ][0];
			if ( ';' === $type || '{' === $type || '}' === $type ) {
				break;
			}
			if ( in_array( $type, array( 'T_CLASS', 'T_INTERFACE', 'T_TRAIT' ), true ) && ( 0 === $k || 'T_DOUBLE_COLON' !== $tokens[ $k - 1 ][0] ) ) {
				return 'class';
			}
			if ( 'T_FUNCTION' === $type || 'T_FN' === $type ) {
				return 'function';
			}
		}
		return 'block';
	}

	/**
	 * Tokenise and normalise to the PHP 7.4 token model: whitespace, comments and open tags are dropped;
	 * names that PHP 8 tokenises as one token are merged into one T_STRING; PHP 8 only tokens are mapped
	 * to the 7.4 form (nullsafe becomes "?" then "->", match/enum/readonly become T_STRING or a marker);
	 * attributes (which 7.4 reads as a comment) become an ATTRIBUTE token.
	 *
	 * @param string $code Source.
	 * @return array List of array( type, text, line ). Single characters use the character as the type.
	 */
	public static function normalise( $code ) {
		$raw    = token_get_all( $code );
		$tokens = array();
		$line   = 1;
		$skip   = array( 'T_WHITESPACE', 'T_DOC_COMMENT', 'T_OPEN_TAG', 'T_OPEN_TAG_WITH_ECHO', 'T_CLOSE_TAG', 'T_INLINE_HTML' );
		foreach ( $raw as $token ) {
			if ( ! is_array( $token ) ) {
				$tokens[] = array( $token, $token, $line );
				continue;
			}
			$name  = token_name( $token[0] );
			$text  = $token[1];
			$start = $token[2];
			$line  = $start + substr_count( $text, "\n" );
			if ( in_array( $name, $skip, true ) ) {
				continue;
			}
			if ( 'T_COMMENT' === $name ) {
				if ( '#[' === substr( $text, 0, 2 ) ) {
					$tokens[] = array( 'ATTRIBUTE', $text, $start );
				}
				continue;
			}
			if ( 'T_ATTRIBUTE' === $name ) {
				$tokens[] = array( 'ATTRIBUTE', $text, $start );
			} elseif ( 'T_NULLSAFE_OBJECT_OPERATOR' === $name ) {
				$tokens[] = array( '?', '?', $start );
				$tokens[] = array( 'T_OBJECT_OPERATOR', '->', $start );
			} elseif ( 'T_MATCH' === $name || 'T_ENUM' === $name ) {
				$tokens[] = array( 'T_STRING', $text, $start );
			} elseif ( 'T_READONLY' === $name ) {
				$tokens[] = array( 'T_READONLY_WORD', $text, $start );
			} elseif ( 'T_NAME_QUALIFIED' === $name || 'T_NAME_FULLY_QUALIFIED' === $name || 'T_NAME_RELATIVE' === $name ) {
				$tokens[] = array( 'T_STRING', $text, $start );
			} elseif ( 'T_LNUMBER' === $name && 1 === preg_match( '/^0[oO][0-7]/', $text ) ) {
				$tokens[] = array( 'OCTAL_PREFIX', $text, $start );
			} else {
				$tokens[] = array( $name, $text, $start );
			}
		}

		// Merge Foo \ Bar sequences (the 7.4 tokenisation) into one name so both runtimes agree.
		$merged = array();
		$total  = count( $tokens );
		for ( $i = 0; $i < $total; $i++ ) {
			$token = $tokens[ $i ];
			if ( 'T_STRING' !== $token[0] && 'T_NS_SEPARATOR' !== $token[0] ) {
				$merged[] = $token;
				continue;
			}
			$text = $token[1];
			$last = $token[0];
			while ( $i + 1 < $total ) {
				$candidate = $tokens[ $i + 1 ];
				if ( 'T_NS_SEPARATOR' === $candidate[0] || ( 'T_STRING' === $candidate[0] && 'T_NS_SEPARATOR' === $last ) ) {
					$text .= $candidate[1];
					$last  = $candidate[0];
					$i++;
				} else {
					break;
				}
			}
			$merged[] = array( 'T_STRING', $text, $token[2] );
		}
		return $merged;
	}

	/**
	 * Command line entry point.
	 *
	 * @param array $argv Arguments.
	 * @return int Exit code.
	 */
	public static function main( array $argv ) {
		$args  = array_slice( $argv, 1 );
		$quiet = false;
		$paths = array();
		foreach ( $args as $arg ) {
			if ( '--quiet' === $arg ) {
				$quiet = true;
			} elseif ( '--help' === $arg || '-h' === $arg ) {
				echo "Usage: php scripts/php74-guard.php [--quiet] [path ...]\n";
				return 0;
			} elseif ( '--' === substr( $arg, 0, 2 ) ) {
				fwrite( STDERR, "Unknown option: {$arg}\n" );
				return 2;
			} else {
				$paths[] = $arg;
			}
		}
		if ( array() === $paths ) {
			$paths[] = dirname( __DIR__ );
		}
		$result = self::scan_paths( $paths );
		foreach ( $result['violations'] as $violation ) {
			fwrite( STDERR, sprintf( "%s:%d [%s] %s\n", $violation[0], $violation[1], $violation[2], $violation[3] ) );
		}
		if ( array() !== $result['violations'] ) {
			fwrite( STDERR, sprintf( "FAIL: %d PHP 7.4 violation(s) in %d file(s) scanned.\n", count( $result['violations'] ), $result['files'] ) );
			return 1;
		}
		if ( ! $quiet ) {
			echo sprintf( "PHP 7.4 guard: %d file(s) scanned, no violations.\n", $result['files'] );
		}
		return 0;
	}
}

if ( PHP_SAPI === 'cli' && isset( $_SERVER['argv'][0] ) && realpath( $_SERVER['argv'][0] ) === realpath( __FILE__ ) ) {
	exit( DBGR_Php74_Guard::main( $_SERVER['argv'] ) );
}
