<?php
/**
 * Test stubs for the landing pages engine and SEO head (WP-06).
 *
 * The file name starts with "0" on purpose: run.php loads tests/stubs-*.php in name order and every definition
 * in the stubs is "first one wins", so this superset has to come BEFORE stubs-consent.php, stubs-recon.php and
 * stubs-waitlist.php, which each define a smaller DoughBoss_Locations. It keeps every behaviour those stubs have
 * (the static $rows and $throw, all(), and the $GLOBALS['dbgr_pages_by_path'] page lookup used by the waitlist
 * tests), so their tests are unaffected, and adds what the landing module reads from core:
 *
 *   - DoughBoss_Locations::get() and ::weekly_hours() (the static $hours maps a location id to a mon..sun array);
 *   - DoughBoss_Catering_Package (the post type and meta key names core uses);
 *   - the WordPress page, post, query and meta functions the engine calls (get_page_by_path with a real parent
 *     chain, get_permalink, wp_insert_post, get_posts, get_post_meta, is_singular, get_queried_object_id).
 *
 * Tests describe the request with dbgr_landing_query() and the site with dbgr_landing_site(); both are reset
 * by dbgr_landing_reset(), which every landing test calls first.
 *
 * @package DoughBoss_Growth
 */

if ( ! class_exists( 'DoughBoss_Locations', false ) ) {
	/** Core shop list plus the two calls the landing module makes. */
	class DoughBoss_Locations {
		/** @var array|null Rows returned by all(). */
		public static $rows = array();
		/** @var bool When true, all() throws (a database failure). */
		public static $throw = false;
		/** @var array Location id => mon..sun => "HH:MM-HH:MM[, HH:MM-HH:MM]". */
		public static $hours = array();
		/** @var bool When true, weekly_hours() throws. */
		public static $throw_hours = false;
		/** @param bool $active_only Active only. @return array|null */
		public static function all( $active_only = false ) {
			if ( self::$throw ) {
				throw new RuntimeException( 'database error' );
			}
			return self::$rows;
		}
		/** @param int $id Id. @return object|null */
		public static function get( $id ) {
			foreach ( (array) self::$rows as $row ) {
				$row = (object) $row;
				if ( (int) $row->id === (int) $id ) {
					return $row;
				}
			}
			return null;
		}
		/** @param int $location_id Id. @return array */
		public static function weekly_hours( $location_id ) {
			if ( self::$throw_hours ) {
				throw new RuntimeException( 'database error' );
			}
			$out = array_fill_keys( array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ), '' );
			if ( isset( self::$hours[ (int) $location_id ] ) ) {
				foreach ( self::$hours[ (int) $location_id ] as $day => $ranges ) {
					$out[ $day ] = $ranges;
				}
			}
			return $out;
		}
	}
}

if ( ! class_exists( 'DoughBoss_Catering_Package', false ) ) {
	/** The names core uses for the catering package post type and its meta (class-doughboss-catering-package.php). */
	class DoughBoss_Catering_Package {
		const POST_TYPE       = 'doughboss_cat_pkg';
		const META_SERVES_MIN = '_doughboss_cat_serves_min';
		const META_SERVES_MAX = '_doughboss_cat_serves_max';
		const META_BASE_PRICE = '_doughboss_cat_base_price';
		const META_PER_HEAD   = '_doughboss_cat_per_head';
		const META_INCLUDES   = '_doughboss_cat_includes';
	}
}

$GLOBALS['dbgr_pages_by_path'] = isset( $GLOBALS['dbgr_pages_by_path'] ) ? $GLOBALS['dbgr_pages_by_path'] : array();
$GLOBALS['dbgr_post_meta']     = array();
$GLOBALS['dbgr_query']         = array( 'id' => 0, 'type' => '' );
$GLOBALS['dbgr_insert_fail']   = false;
$GLOBALS['dbgr_insert_calls']  = array();

/**
 * Forget the landing test state: posts, meta, the query, the location and package fixtures.
 *
 * @return void
 */
function dbgr_landing_reset() {
	$GLOBALS['dbgr_posts']         = array();
	$GLOBALS['dbgr_post_meta']     = array();
	$GLOBALS['dbgr_pages_by_path'] = array();
	$GLOBALS['dbgr_query']         = array( 'id' => 0, 'type' => '' );
	$GLOBALS['dbgr_insert_fail']   = false;
	$GLOBALS['dbgr_insert_calls']  = array();
	delete_option( 'doughboss_growth_pages' );
	$GLOBALS['dbgr_shortcodes'] = array();
	$GLOBALS['dbgr_hooks']      = is_array( $GLOBALS['dbgr_hooks_baseline'] ) ? $GLOBALS['dbgr_hooks_baseline'] : array();
	$GLOBALS['dbgr_assets']     = array( 'scripts' => array(), 'styles' => array(), 'inline' => array(), 'localized' => array() );
	DoughBoss_Locations::$rows        = array();
	DoughBoss_Locations::$throw       = false;
	DoughBoss_Locations::$hours       = array();
	DoughBoss_Locations::$throw_hours = false;
	if ( class_exists( 'DoughBoss_Growth_Landing', false ) ) {
		DoughBoss_Growth_Landing::reset_cache();
		DoughBoss_Growth_Landing::set_definition_dir_override( null );
	}
	if ( class_exists( 'DoughBoss_Growth_Ledger', false ) ) {
		DoughBoss_Growth_Ledger::set_file_override( null );
		DoughBoss_Growth_Ledger::reset();
	}
}

/**
 * Describe the current front-end request: a singular page with this id (0 = not a singular page).
 *
 * @param int    $id   Queried object id.
 * @param string $type Post type.
 * @return void
 */
function dbgr_landing_query( $id, $type = 'page' ) {
	$GLOBALS['dbgr_query'] = array( 'id' => (int) $id, 'type' => (string) $type );
}

/**
 * Three shops in the shape core's $wpdb->get_results() returns, with weekly hours.
 *
 * @return void
 */
function dbgr_landing_site() {
	DoughBoss_Locations::$rows  = array(
		(object) array(
			'id'        => 1,
			'name'      => 'Revesby',
			'slug'      => 'revesby',
			'suburb'    => 'Revesby',
			'address'   => "Test Unit 1\nRevesby Test",
			'phone'     => '(02) 5550 0101',
			'is_active' => 1,
		),
		(object) array(
			'id'        => 2,
			'name'      => 'Bankstown',
			'slug'      => 'bankstown',
			'suburb'    => 'Bankstown',
			'address'   => 'Test Arcade 2 Bankstown Test',
			'phone'     => '0255500102',
			'is_active' => 1,
		),
		(object) array(
			'id'        => 3,
			'name'      => 'Roselands Centro',
			'slug'      => 'roselands',
			'suburb'    => 'Roselands',
			'address'   => "Test Centre 3\nRoselands Test",
			'phone'     => '',
			'is_active' => 1,
		),
	);
	DoughBoss_Locations::$hours = array(
		1 => array(
			'mon' => '06:30-14:30',
			'tue' => '06:30-14:30',
			'sat' => '07:00-12:00, 13:00-15:00',
		),
		2 => array(
			'mon' => '09:00-17:00',
		),
	);
	$GLOBALS['dbgr_pages_by_path'] = array();
	dbgr_test_add_post( array( 'ID' => 10, 'post_name' => 'catering', 'post_title' => 'Catering', 'post_status' => 'publish' ) );
	dbgr_test_add_post( array( 'ID' => 11, 'post_name' => 'locations', 'post_title' => 'Locations', 'post_status' => 'publish' ) );
}

/**
 * Add a published catering package the way core stores one.
 *
 * @param int    $id       Post id.
 * @param string $name     Title.
 * @param mixed  $price    Base price meta.
 * @param int    $min      Serves min.
 * @param int    $max      Serves max.
 * @param string $includes What is included (one item per line).
 * @param string $status   Post status.
 * @return void
 */
function dbgr_landing_package( $id, $name, $price, $min = 0, $max = 0, $includes = '', $status = 'publish' ) {
	dbgr_test_add_post(
		array(
			'ID'          => $id,
			'post_type'   => 'doughboss_cat_pkg',
			'post_status' => $status,
			'post_title'  => $name,
			'menu_order'  => (int) $id,
		)
	);
	$GLOBALS['dbgr_post_meta'][ $id ] = array(
		'_doughboss_cat_base_price'  => $price,
		'_doughboss_cat_serves_min'  => $min,
		'_doughboss_cat_serves_max'  => $max,
		'_doughboss_cat_includes'    => $includes,
	);
}

/**
 * The materialised path of a post (parent chain of post_name), like WordPress's get_page_uri().
 *
 * @param object $post Post.
 * @return string
 */
function dbgr_landing_path( $post ) {
	$parts = array( (string) $post->post_name );
	$guard = 0;
	$parent = (int) $post->post_parent;
	while ( $parent > 0 && $guard < 10 && isset( $GLOBALS['dbgr_posts'][ $parent ] ) ) {
		$p = $GLOBALS['dbgr_posts'][ $parent ];
		array_unshift( $parts, (string) $p->post_name );
		$parent = (int) $p->post_parent;
		++$guard;
	}
	return implode( '/', $parts );
}

if ( ! function_exists( 'get_page_by_path' ) ) {
	/**
	 * A real parent chain lookup, plus the waitlist tests' $GLOBALS['dbgr_pages_by_path'] map.
	 *
	 * @param string $path      Page path.
	 * @param string $output    Ignored.
	 * @param string $post_type Post type.
	 * @return object|null
	 */
	function get_page_by_path( $path, $output = 'OBJECT', $post_type = 'page' ) {
		unset( $output );
		if ( isset( $GLOBALS['dbgr_pages_by_path'][ $path ] ) ) {
			return $GLOBALS['dbgr_pages_by_path'][ $path ];
		}
		foreach ( $GLOBALS['dbgr_posts'] as $post ) {
			if ( $post->post_type === $post_type && 'trash' !== $post->post_status && dbgr_landing_path( $post ) === trim( (string) $path, '/' ) ) {
				return clone $post;
			}
		}
		return null;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	/**
	 * A post object (waitlist tests use a "link" property) or a post id.
	 *
	 * @param object|int $post Post.
	 * @return string
	 */
	function get_permalink( $post = 0 ) {
		if ( is_numeric( $post ) ) {
			$post = isset( $GLOBALS['dbgr_posts'][ (int) $post ] ) ? $GLOBALS['dbgr_posts'][ (int) $post ] : null;
		}
		if ( ! is_object( $post ) ) {
			return '';
		}
		if ( isset( $post->link ) ) {
			return (string) $post->link;
		}
		return isset( $post->post_name ) ? home_url( '/' . dbgr_landing_path( $post ) . '/' ) : '';
	}
}
if ( ! function_exists( 'wp_insert_post' ) ) {
	/**
	 * @param array $fields Post fields.
	 * @param bool  $wp_error Whether to return a WP_Error on failure.
	 * @return int|WP_Error
	 */
	function wp_insert_post( $fields, $wp_error = false ) {
		$GLOBALS['dbgr_insert_calls'][] = $fields;
		if ( ! empty( $GLOBALS['dbgr_insert_fail'] ) ) {
			return $wp_error ? new WP_Error( 'db_insert_error', 'Could not insert post.' ) : 0;
		}
		$id = max( array_merge( array( 99 ), array_keys( $GLOBALS['dbgr_posts'] ) ) ) + 1;
		dbgr_test_add_post( array_merge( array( 'ID' => $id ), $fields ) );
		return $id;
	}
}
if ( ! function_exists( 'get_posts' ) ) {
	/**
	 * Only what the landing module asks for: post_type, post_status, numberposts and the menu_order/title sort.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	function get_posts( $args = array() ) {
		$out = array();
		foreach ( $GLOBALS['dbgr_posts'] as $post ) {
			if ( isset( $args['post_type'] ) && $post->post_type !== $args['post_type'] ) {
				continue;
			}
			if ( isset( $args['post_status'] ) && $post->post_status !== $args['post_status'] ) {
				continue;
			}
			$out[] = clone $post;
		}
		usort(
			$out,
			function ( $a, $b ) {
				$ma = isset( $a->menu_order ) ? (int) $a->menu_order : 0;
				$mb = isset( $b->menu_order ) ? (int) $b->menu_order : 0;
				return ( $ma === $mb ) ? strcmp( (string) $a->post_title, (string) $b->post_title ) : ( $ma - $mb );
			}
		);
		if ( isset( $args['numberposts'] ) ) {
			$out = array_slice( $out, 0, (int) $args['numberposts'] );
		}
		return $out;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	/** @param int $id Post id. @param string $key Meta key. @param bool $single Single. @return mixed */
	function get_post_meta( $id, $key = '', $single = false ) {
		if ( isset( $GLOBALS['dbgr_post_meta'][ (int) $id ][ $key ] ) ) {
			return $GLOBALS['dbgr_post_meta'][ (int) $id ][ $key ];
		}
		return $single ? '' : array();
	}
}
if ( ! function_exists( 'is_singular' ) ) {
	/** @param string|array $post_types Post type(s). @return bool */
	function is_singular( $post_types = '' ) {
		$q = $GLOBALS['dbgr_query'];
		if ( $q['id'] < 1 ) {
			return false;
		}
		return '' === $post_types || in_array( $q['type'], (array) $post_types, true );
	}
}
if ( ! function_exists( 'get_queried_object_id' ) ) {
	/** @return int */
	function get_queried_object_id() {
		return (int) $GLOBALS['dbgr_query']['id'];
	}
}

/**
 * Boot the landing module as the registry would, with the given flags: modules loaded, settings saved, the shops
 * and the two hub pages in place, per-request caches empty. Does NOT call init() (tests choose the context).
 *
 * @param array $features Feature flags (default: landing_pages and seo_head on).
 * @param array $extra    Extra settings merged over the flags.
 * @return void
 */
function dbgr_landing_boot( array $features = array( 'landing_pages' => true, 'seo_head' => true ), array $extra = array() ) {
	dbgr_landing_reset();
	DoughBoss_Growth::load_module( 'ledger' );
	DoughBoss_Growth::load_module( 'landing' );
	update_option( 'doughboss_growth_settings', array_merge( array( 'features' => $features ), $extra ) );
	DoughBoss_Growth_Ledger::reset();
	DoughBoss_Growth_Landing::reset_cache();
	dbgr_landing_site();
}

/**
 * A claim as the ledger file holds it. Confirmed claims get an owner-confirmed source.
 *
 * @param string $id        Claim id.
 * @param string $text      Claim text.
 * @param bool   $confirmed Confirmed.
 * @param array  $extra     Extra fields (question, areas, source override).
 * @return array
 */
function dbgr_landing_claim( $id, $text, $confirmed = true, array $extra = array() ) {
	$claim = array(
		'id'        => $id,
		'text'      => $text,
		'confirmed' => $confirmed,
	);
	if ( $confirmed ) {
		$claim['source'] = array(
			'kind'        => 'owner-confirmed',
			'ref'         => 'test fixture',
			'confirmedOn' => '2026-10-02',
		);
	}
	return array_merge( $claim, $extra );
}

/**
 * Point the ledger at a temporary claims file holding these claims (plus, unless told otherwise, the unconfirmed
 * gaps the definitions reference). Returns the temp directory; remove it with dbgr_test_rmdir().
 *
 * @param array $claims Claims (replace the defaults with the same id).
 * @return string Temp directory.
 */
function dbgr_landing_ledger( array $claims ) {
	DoughBoss_Growth::load_module( 'ledger' );
	DoughBoss_Growth::load_module( 'landing' );
	$by_id = array();
	foreach ( array( 'catering-lead-time', 'catering-service-area', 'catering-delivery-or-drop-off', 'baked-in-house-statement' ) as $id ) {
		$by_id[ $id ] = dbgr_landing_claim( $id, 'Gap placeholder wording', false );
	}
	foreach ( $claims as $claim ) {
		$by_id[ $claim['id'] ] = $claim;
	}
	$dir = sys_get_temp_dir() . '/dbgr-landing-' . bin2hex( random_bytes( 4 ) );
	mkdir( $dir, 0777, true );
	file_put_contents( $dir . '/claims.json', json_encode( array( 'version' => 1, 'claims' => array_values( $by_id ) ) ) );
	DoughBoss_Growth_Ledger::set_file_override( $dir . '/claims.json' );
	DoughBoss_Growth_Landing::reset_cache();
	return $dir;
}

/**
 * Register a stand-in for core's [doughboss_catering] shortcode that returns the given HTML.
 *
 * @param string $html Output.
 * @return void
 */
function dbgr_landing_core_form( $html = '<div class="db-app db-catering" data-doughboss-catering><p>Test catering form</p></div>' ) {
	add_shortcode(
		'doughboss_catering',
		function () use ( $html ) {
			return $html;
		}
	);
}

/**
 * The product working name, built from two parts so no shipped or test file spells it out.
 *
 * @return string
 */
function dbgr_landing_banned_word() {
	return 'Mini' . 's';
}

/**
 * Create all six pages as the admin button would, publish them, and return the key => id map.
 *
 * @param bool $publish Publish them (default true).
 * @return array
 */
function dbgr_landing_make_pages( $publish = true ) {
	DoughBoss_Growth_Landing::create_pages();
	$map = DoughBoss_Growth_Landing::page_map();
	if ( $publish ) {
		foreach ( $map as $id ) {
			wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		}
	}
	return $map;
}

/**
 * Run wp_head for the current request and return what it printed.
 *
 * @return string
 */
function dbgr_landing_head() {
	ob_start();
	do_action( 'wp_head' );
	return (string) ob_get_clean();
}
