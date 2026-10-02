<?php
/**
 * DoughBoss Growth landing pages engine.
 *
 * Six contract pages (catering corporate, office breakfast and events; the Revesby, Bankstown and Roselands shop
 * pages) are ordinary WordPress pages, created as DRAFTS by an admin button under the existing "catering" and
 * "locations" pages. Each page's body is one shortcode, [doughboss_growth_landing key="..."]. The copy of a page is
 * defined in content/landing/<key>.json: neutral headings, claim ids from the claims ledger, and slots that are
 * read from core at render time (a shop's address, phone and opening hours, the catering packages and their
 * prices, and the core catering form). The templates hold no free factual prose, so an unconfirmed claim cannot
 * reach a customer: a block whose claim the ledger does not publish is left out whole (fail closed).
 *
 * Feature flag landing_pages (the search head is the separate seo_head flag, see the SEO class). Both are off by
 * default and are switched off while the claims ledger is invalid (the ledger module's guard).
 *
 * Nothing here writes a core table, option, post type, role or capability. The one thing it writes outside its own
 * option is the page posts the admin button creates, as the architecture specifies (00 section 3.2). No page is
 * defined for the unannounced product: the engine renders whatever definition files exist and none names it.
 *
 * Entry file for the module registry: no side effects at include time.
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Landing pages.
 */
final class DoughBoss_Growth_Landing {

	/**
	 * Shortcode tag.
	 */
	const SHORTCODE = 'doughboss_growth_landing';

	/**
	 * admin-post action and nonce action for the "Create landing pages" button.
	 */
	const CREATE_ACTION = 'doughboss_growth_create_pages';

	/**
	 * Stylesheet handle.
	 */
	const STYLE_HANDLE = 'dbgr-landing';

	/**
	 * Folder (inside the plugin) that holds one definition file per page key.
	 */
	const DEFINITION_DIR = 'content/landing/';

	/**
	 * Largest definition file read, in bytes.
	 */
	const MAX_DEFINITION_BYTES = 65536;

	/**
	 * Page key shape (also the definition file name without .json).
	 */
	const KEY_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

	/**
	 * Page kind => the existing parent page slug it lives under.
	 */
	const PARENTS = array(
		'catering' => 'catering',
		'location' => 'locations',
	);

	/**
	 * Block types each kind may use.
	 */
	const BLOCKS_BY_KIND = array(
		'catering' => array( 'claim', 'faq', 'packages', 'form' ),
		'location' => array( 'claim', 'faq', 'location' ),
	);

	/**
	 * Slots a location block may show.
	 */
	const LOCATION_SLOTS = array( 'address', 'phone', 'hours' );

	/**
	 * Placeholders a location definition may use in title, description and crumb.
	 */
	const PLACEHOLDERS = array( 'name', 'suburb', 'details' );

	/**
	 * Words that make a page title or description a claim the ledger has not confirmed. Applied to the title,
	 * description, crumb and service name (not to block headings or core names).
	 */
	const CLAIM_WORDS = '/\b(?:fresh|fresher|freshest|deliver(?:y|ies|ed|ing)?|free|cheap(?:er|est)?|award|awards|famous|guarantee|guaranteed|licensed|authentic|homemade|handmade)\b/iu';

	/**
	 * Most blocks a definition may hold, and most claim ids in a faq block.
	 */
	const MAX_BLOCKS = 12;
	const MAX_FAQ    = 10;

	/**
	 * Most catering packages read from core.
	 */
	const MAX_PACKAGES = 50;

	/**
	 * Result codes of the create button, with a human sentence for each.
	 */
	const RESULT_CODES = array( 'created', 'adopted', 'exists', 'parent_missing', 'slug_taken', 'insert_failed', 'trashed' );

	/**
	 * Per-request caches.
	 *
	 * @var array|null
	 */
	private static $defs = null;

	/**
	 * Definition problems found while loading.
	 *
	 * @var array
	 */
	private static $problems = array();

	/**
	 * Composed pages by key.
	 *
	 * @var array
	 */
	private static $composed = array();

	/**
	 * Core catering package list for this request.
	 *
	 * @var array|null
	 */
	private static $packages = null;

	/**
	 * Core catering form check for this request.
	 *
	 * @var array|null
	 */
	private static $form = null;

	/**
	 * Test seam: another definitions folder. Production code never sets this.
	 *
	 * @var string|null
	 */
	private static $dir_override = null;

	/* ------------------------------------------------------------------------------------------ */
	/* Boot                                                                                         */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Hook everything. Re-checks the flag itself, because the module also loads inside wp-admin whatever the flags
	 * say (the read-only tab and the create button need it).
	 *
	 * @return void
	 */
	public static function init() {
		// A page that holds the shortcode must never print the raw tag, whatever the flags say.
		if ( ! shortcode_exists( self::SHORTCODE ) ) {
			add_shortcode( self::SHORTCODE, '__return_empty_string' );
		}
		if ( is_admin() ) {
			add_action( 'doughboss_growth_admin_tabs', array( __CLASS__, 'register_tab' ) );
			add_action( 'admin_post_' . self::CREATE_ACTION, array( __CLASS__, 'handle_create_pages' ) );
			add_filter( 'doughboss_growth_ledger_blocks', array( __CLASS__, 'declare_blocks' ) );
		}
		if ( ! self::enabled() ) {
			return;
		}
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 20 );
		add_filter( 'doughboss_load_assets', array( __CLASS__, 'filter_load_core_assets' ), 20 );
		add_filter( 'doughboss_load_catering_assets', array( __CLASS__, 'filter_load_core_assets' ), 20 );
		if ( class_exists( 'DoughBoss_Growth_Landing_SEO' ) ) {
			DoughBoss_Growth_Landing_SEO::init();
		}
	}

	/**
	 * Whether the landing pages feature is effectively on (flag, kill switch, ledger guard).
	 *
	 * @return bool
	 */
	public static function enabled() {
		return DoughBoss_Growth_Settings::enabled( 'landing_pages' );
	}

	/**
	 * Forget the per-request caches.
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$defs     = null;
		self::$problems = array();
		self::$composed = array();
		self::$packages = null;
		self::$form     = null;
	}

	/**
	 * Point the engine at another definitions folder (tests only). Pass null to restore.
	 *
	 * @param string|null $dir Directory with a trailing slash, or null.
	 * @return void
	 */
	public static function set_definition_dir_override( $dir ) {
		if ( ! defined( 'DBGR_TESTING' ) ) {
			return; // Honoured only inside the test harness.
		}
		self::$dir_override = ( is_string( $dir ) && '' !== $dir ) ? $dir : null;
		self::reset_cache();
	}

	/**
	 * The claims ledger class, loaded on demand.
	 *
	 * @return bool Whether DoughBoss_Growth_Ledger is available.
	 */
	private static function ledger_available() {
		if ( ! class_exists( 'DoughBoss_Growth_Ledger', false ) && class_exists( 'DoughBoss_Growth' ) ) {
			DoughBoss_Growth::load_module( 'ledger' );
		}
		return class_exists( 'DoughBoss_Growth_Ledger', false );
	}

	/**
	 * Public-copy lint plus, for page metadata, the words that make a statement an unconfirmed claim.
	 *
	 * @param mixed       $text        Text.
	 * @param string|null $source_kind Ledger source kind ("core-data" for core values, null for static text).
	 * @param bool        $metadata    Also apply the metadata claim-word list.
	 * @return string[] Violation codes (empty = clean).
	 */
	public static function lint_text( $text, $source_kind, $metadata = false ) {
		if ( ! self::ledger_available() ) {
			return array( 'ledger_unavailable' );
		}
		$codes = DoughBoss_Growth_Ledger::lint_public( $text, $source_kind );
		if ( $metadata && is_string( $text ) && 1 === preg_match( self::CLAIM_WORDS, $text ) ) {
			$codes[] = 'claim_word';
		}
		return $codes;
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Definitions                                                                                  */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Folder the definition files are read from.
	 *
	 * @return string
	 */
	public static function definition_dir() {
		if ( null !== self::$dir_override ) {
			return self::$dir_override;
		}
		$base = defined( 'DOUGHBOSS_GROWTH_DIR' ) ? (string) constant( 'DOUGHBOSS_GROWTH_DIR' ) : dirname( dirname( __DIR__ ) ) . '/';
		return rtrim( $base, '/\\' ) . '/' . self::DEFINITION_DIR;
	}

	/**
	 * Every valid page definition, keyed by page key, in file name order. An invalid file is left out (the reason
	 * is in definition_problems()).
	 *
	 * @return array
	 */
	public static function definitions() {
		self::load_definitions();
		return self::$defs;
	}

	/**
	 * One definition, or null.
	 *
	 * @param string $key Page key.
	 * @return array|null
	 */
	public static function definition( $key ) {
		if ( ! is_string( $key ) ) {
			return null;
		}
		$all = self::definitions();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Problems found while loading the definition files ("file: message").
	 *
	 * @return string[]
	 */
	public static function definition_problems() {
		self::load_definitions();
		return self::$problems;
	}

	/**
	 * Read and validate the definition files once per request.
	 *
	 * @return void
	 */
	private static function load_definitions() {
		if ( null !== self::$defs ) {
			return;
		}
		self::$defs     = array();
		self::$problems = array();
		$files          = glob( self::definition_dir() . '*.json' );
		if ( ! is_array( $files ) ) {
			$files = array();
		}
		sort( $files );
		$paths = array();
		foreach ( $files as $file ) {
			$name = basename( $file );
			$size = filesize( $file );
			if ( false === $size || $size > self::MAX_DEFINITION_BYTES ) {
				self::$problems[] = $name . ': file is empty, unreadable or too large';
				continue;
			}
			$json = file_get_contents( $file );
			$data = is_string( $json ) ? json_decode( $json, true, 8 ) : null;
			if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
				self::$problems[] = $name . ': not valid JSON';
				continue;
			}
			$problems = self::validate_definition( $data, $name );
			if ( array() !== $problems ) {
				foreach ( $problems as $problem ) {
					self::$problems[] = $name . ': ' . $problem;
				}
				continue;
			}
			$path = $data['parent'] . '/' . $data['slug'];
			if ( isset( self::$defs[ $data['key'] ] ) || isset( $paths[ $path ] ) ) {
				self::$problems[] = $name . ': duplicate key or page path, left out';
				continue;
			}
			$paths[ $path ]           = true;
			self::$defs[ $data['key'] ] = $data;
		}
	}

	/**
	 * Every problem in one definition. An empty array means it is valid.
	 *
	 * @param mixed  $d    Decoded definition.
	 * @param string $file File name (the key must match it).
	 * @return string[]
	 */
	public static function validate_definition( $d, $file ) {
		if ( ! is_array( $d ) ) {
			return array( 'definition must be an object' );
		}
		$problems = array();
		$known    = array( 'version', 'key', 'kind', 'parent', 'slug', 'location_slug', 'page_title', 'crumb', 'title', 'description', 'service_name', 'blocks' );
		foreach ( array_keys( $d ) as $field ) {
			if ( ! in_array( $field, $known, true ) ) {
				$problems[] = 'unknown field "' . ( is_string( $field ) ? $field : '?' ) . '"';
			}
		}
		if ( ! isset( $d['version'] ) || 1 !== $d['version'] ) {
			$problems[] = 'version must be 1';
		}
		$key = ( isset( $d['key'] ) && is_string( $d['key'] ) ) ? $d['key'] : '';
		if ( 1 !== preg_match( self::KEY_PATTERN, $key ) || strlen( $key ) > 60 ) {
			$problems[] = 'key must be kebab-case';
		} elseif ( $key . '.json' !== $file ) {
			$problems[] = 'key must equal the file name';
		}
		$kind = ( isset( $d['kind'] ) && is_string( $d['kind'] ) ) ? $d['kind'] : '';
		if ( ! isset( self::PARENTS[ $kind ] ) ) {
			$problems[] = 'kind must be catering or location';
			return $problems;
		}
		if ( ! isset( $d['parent'] ) || self::PARENTS[ $kind ] !== $d['parent'] ) {
			$problems[] = 'parent must be "' . self::PARENTS[ $kind ] . '" for a ' . $kind . ' page';
		}
		if ( ! isset( $d['slug'] ) || ! is_string( $d['slug'] ) || 1 !== preg_match( self::KEY_PATTERN, $d['slug'] ) || strlen( $d['slug'] ) > 60 ) {
			$problems[] = 'slug must be kebab-case';
		}
		if ( 'location' === $kind ) {
			if ( ! isset( $d['location_slug'] ) || ! is_string( $d['location_slug'] ) || 1 !== preg_match( self::KEY_PATTERN, $d['location_slug'] ) ) {
				$problems[] = 'location_slug must be the core location slug';
			}
			if ( isset( $d['service_name'] ) ) {
				$problems[] = 'service_name is for catering pages only';
			}
		} else {
			if ( isset( $d['location_slug'] ) ) {
				$problems[] = 'location_slug is for location pages only';
			}
			if ( ! isset( $d['service_name'] ) || ! is_string( $d['service_name'] ) || '' === trim( $d['service_name'] ) || strlen( $d['service_name'] ) > 80 ) {
				$problems[] = 'service_name is required (80 characters at most)';
			} elseif ( array() !== self::lint_text( $d['service_name'], null, true ) ) {
				$problems[] = 'service_name fails the public-copy lint';
			}
		}
		$allowed_placeholders = ( 'location' === $kind ) ? self::PLACEHOLDERS : array();
		foreach ( array(
			'page_title'  => 120,
			'crumb'       => 80,
			'title'       => 60,
			'description' => 155,
		) as $field => $max ) {
			if ( ! isset( $d[ $field ] ) || ! is_string( $d[ $field ] ) || '' === trim( $d[ $field ] ) ) {
				$problems[] = $field . ' is required';
				continue;
			}
			$text = $d[ $field ];
			if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text ) ) > $max ) {
				$problems[] = $field . ' is longer than ' . $max . ' characters';
			}
			$stripped = $text;
			if ( preg_match_all( '/\{([a-z_]+)\}/', $text, $found ) ) {
				foreach ( $found[1] as $name ) {
					if ( ! in_array( $name, $allowed_placeholders, true ) ) {
						$problems[] = $field . ' uses the placeholder {' . $name . '}, which is not allowed here';
					}
				}
				$stripped = preg_replace( '/\{[a-z_]+\}/', '', $text );
			}
			if ( false !== strpos( (string) $stripped, '{' ) || false !== strpos( (string) $stripped, '}' ) ) {
				$problems[] = $field . ' has a stray brace';
			}
			if ( array() !== self::lint_text( $stripped, null, 'page_title' !== $field ) ) {
				$problems[] = $field . ' fails the public-copy lint';
			}
		}
		return array_merge( $problems, self::validate_blocks( $d, $kind ) );
	}

	/**
	 * Problems in the block list of a definition.
	 *
	 * @param array  $d    Definition.
	 * @param string $kind Page kind.
	 * @return string[]
	 */
	private static function validate_blocks( array $d, $kind ) {
		if ( ! isset( $d['blocks'] ) || ! is_array( $d['blocks'] ) || array() === $d['blocks'] || array_values( $d['blocks'] ) !== $d['blocks'] || count( $d['blocks'] ) > self::MAX_BLOCKS ) {
			return array( 'blocks must be a list of 1 to ' . self::MAX_BLOCKS . ' blocks' );
		}
		$problems  = array();
		$ledger_ok = self::ledger_available();
		$ids       = array();
		if ( $ledger_ok ) {
			$loaded = DoughBoss_Growth_Ledger::load();
			if ( '' === $loaded['load_error'] ) {
				foreach ( $loaded['claims'] as $claim ) {
					if ( is_array( $claim ) && isset( $claim['id'] ) && is_string( $claim['id'] ) ) {
						$ids[ $claim['id'] ] = true;
					}
				}
			}
		}
		$seen = array();
		foreach ( $d['blocks'] as $i => $block ) {
			$where = 'block ' . $i;
			if ( ! is_array( $block ) || ! isset( $block['type'] ) || ! is_string( $block['type'] ) ) {
				$problems[] = $where . ' needs a type';
				continue;
			}
			$type = $block['type'];
			if ( ! in_array( $type, self::BLOCKS_BY_KIND[ $kind ], true ) ) {
				$problems[] = $where . ': type "' . $type . '" is not allowed on a ' . $kind . ' page';
				continue;
			}
			$fields = array( 'type' );
			if ( 'form' !== $type ) {
				$fields[] = 'heading';
				if ( ! isset( $block['heading'] ) || ! is_string( $block['heading'] ) || '' === trim( $block['heading'] ) || strlen( $block['heading'] ) > 80 ) {
					$problems[] = $where . ': heading is required (80 characters at most)';
				} elseif ( array() !== self::lint_text( $block['heading'], null ) ) {
					$problems[] = $where . ': heading fails the public-copy lint';
				}
			}
			if ( 'claim' === $type ) {
				$fields[] = 'id';
				if ( ! isset( $block['id'] ) || ! is_string( $block['id'] ) || 1 !== preg_match( self::KEY_PATTERN, $block['id'] ) ) {
					$problems[] = $where . ': claim id must be kebab-case';
				} elseif ( array() !== $ids && ! isset( $ids[ $block['id'] ] ) ) {
					$problems[] = $where . ': claim "' . $block['id'] . '" is not in the ledger';
				}
			}
			if ( 'faq' === $type ) {
				$fields[] = 'claims';
				if ( ! isset( $block['claims'] ) || ! is_array( $block['claims'] ) || array() === $block['claims'] || count( $block['claims'] ) > self::MAX_FAQ ) {
					$problems[] = $where . ': claims must be a list of 1 to ' . self::MAX_FAQ . ' claim ids';
				} else {
					foreach ( $block['claims'] as $claim_id ) {
						if ( ! is_string( $claim_id ) || 1 !== preg_match( self::KEY_PATTERN, $claim_id ) || ( array() !== $ids && ! isset( $ids[ $claim_id ] ) ) ) {
							$problems[] = $where . ': faq claim id is invalid or not in the ledger';
						}
					}
				}
			}
			if ( 'location' === $type ) {
				$fields[] = 'slots';
				if ( ! isset( $block['slots'] ) || ! is_array( $block['slots'] ) || array() === $block['slots'] || array_diff( $block['slots'], self::LOCATION_SLOTS ) !== array() || ! in_array( 'address', $block['slots'], true ) ) {
					$problems[] = $where . ': slots must be a list drawn from address, phone and hours, and include address';
				}
			}
			foreach ( array_keys( $block ) as $field ) {
				if ( ! in_array( $field, $fields, true ) ) {
					$problems[] = $where . ': unknown field "' . ( is_string( $field ) ? $field : '?' ) . '"';
				}
			}
			if ( in_array( $type, array( 'packages', 'form', 'location' ), true ) ) {
				if ( isset( $seen[ $type ] ) ) {
					$problems[] = $where . ': only one ' . $type . ' block is allowed';
				}
				$seen[ $type ] = true;
			}
		}
		return $problems;
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Pages                                                                                        */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Key => page id from the doughboss_growth_pages option, sanitised. Other keys in the option (other modules)
	 * are ignored here and kept untouched by the writer.
	 *
	 * @return array
	 */
	public static function page_map() {
		$stored = get_option( DoughBoss_Growth_Activator::PAGES_OPTION, array() );
		$out    = array();
		if ( ! is_array( $stored ) ) {
			return $out;
		}
		foreach ( $stored as $key => $value ) {
			if ( is_array( $value ) && isset( $value['id'] ) ) {
				$value = $value['id'];
			}
			if ( is_string( $key ) && 1 === preg_match( self::KEY_PATTERN, $key ) && ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && (int) $value > 0 ) {
				$out[ $key ] = (int) $value;
			}
		}
		return $out;
	}

	/**
	 * The page id recorded for a key, or 0.
	 *
	 * @param string $key Page key.
	 * @return int
	 */
	public static function page_id( $key ) {
		$map = self::page_map();
		return isset( $map[ $key ] ) ? $map[ $key ] : 0;
	}

	/**
	 * The key of the companion landing page being viewed, or "". A page counts only when its id is recorded for a
	 * valid definition AND its content is still exactly that key's shortcode.
	 *
	 * @return string
	 */
	public static function current_key() {
		if ( ! function_exists( 'is_singular' ) || ! is_singular( 'page' ) ) {
			return '';
		}
		$id = (int) get_queried_object_id();
		if ( $id < 1 ) {
			return '';
		}
		foreach ( self::page_map() as $key => $page_id ) {
			if ( $page_id !== $id ) {
				continue;
			}
			if ( null === self::definition( $key ) ) {
				return '';
			}
			$post = get_post( $id );
			if ( ! is_object( $post ) || ! isset( $post->post_type, $post->post_content ) || 'page' !== $post->post_type ) {
				return '';
			}
			return ( self::shortcode_key( (string) $post->post_content ) === $key ) ? $key : '';
		}
		return '';
	}

	/**
	 * The key named by page content that is exactly one landing shortcode (optionally inside the block editor's
	 * shortcode comments). "" for anything else.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function shortcode_key( $content ) {
		$content = trim( (string) $content );
		$content = preg_replace( '/^<!--\s*wp:shortcode\s*-->/', '', $content );
		$content = preg_replace( '/<!--\s*\/wp:shortcode\s*-->$/', '', (string) $content );
		$content = trim( (string) $content );
		if ( 1 === preg_match( '/^\[' . self::SHORTCODE . '\s+key=(?:"([a-z0-9-]+)"|\'([a-z0-9-]+)\')\s*\]$/D', $content, $m ) ) {
			return ( isset( $m[2] ) && '' !== $m[2] ) ? $m[2] : $m[1];
		}
		return '';
	}

	/**
	 * The existing parent page of a definition (the hub), or null when it is missing or in the bin.
	 *
	 * @param array $def Definition.
	 * @return array|null array( id, title, url ).
	 */
	public static function parent_page( array $def ) {
		$post = get_page_by_path( $def['parent'], 'OBJECT', 'page' );
		if ( ! is_object( $post ) || ! isset( $post->ID, $post->post_title ) || in_array( isset( $post->post_status ) ? $post->post_status : '', array( 'trash', 'auto-draft' ), true ) ) {
			return null;
		}
		$title = sanitize_text_field( (string) $post->post_title );
		$url   = get_permalink( $post );
		if ( '' === $title || ! is_string( $url ) || 1 !== preg_match( '#^https?://#i', $url ) ) {
			return null;
		}
		return array(
			'id'    => (int) $post->ID,
			'title' => $title,
			'url'   => $url,
		);
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Core data (read only, at render time)                                                        */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * One active shop from core, by its slug, with address, phone and weekly hours. Null when core has no active
	 * shop with that slug, the address is empty, or a value fails the public-copy lint (fail closed).
	 *
	 * @param string $slug Core location slug.
	 * @return array|null array( id, slug, name, suburb, address, phone, hours ).
	 */
	public static function core_location( $slug ) {
		if ( ! class_exists( 'DoughBoss_Locations' ) || ! is_callable( array( 'DoughBoss_Locations', 'all' ) ) ) {
			return null;
		}
		try {
			$rows = DoughBoss_Locations::all( true );
		} catch ( Throwable $e ) {
			return null;
		}
		if ( ! is_array( $rows ) ) {
			return null;
		}
		foreach ( $rows as $row ) {
			$row = is_object( $row ) ? get_object_vars( $row ) : ( is_array( $row ) ? $row : array() );
			if ( ! isset( $row['id'], $row['slug'] ) || sanitize_title( (string) $row['slug'] ) !== $slug ) {
				continue;
			}
			if ( isset( $row['is_active'] ) && 1 !== (int) $row['is_active'] ) {
				continue;
			}
			$name    = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';
			$address = isset( $row['address'] ) ? sanitize_textarea_field( (string) $row['address'] ) : '';
			$suburb  = isset( $row['suburb'] ) ? sanitize_text_field( (string) $row['suburb'] ) : '';
			$phone   = isset( $row['phone'] ) ? sanitize_text_field( (string) $row['phone'] ) : '';
			if ( '' === $name || '' === DoughBoss_Growth_Landing_Schema::first_line( $address ) ) {
				return null;
			}
			foreach ( array( $name, $address ) as $required ) {
				if ( array() !== self::lint_text( $required, 'core-data' ) ) {
					return null;
				}
			}
			if ( '' !== $suburb && array() !== self::lint_text( $suburb, 'core-data' ) ) {
				$suburb = '';
			}
			if ( '' !== $phone && ( array() !== self::lint_text( $phone, 'core-data' ) || strlen( $phone ) > 40 ) ) {
				$phone = '';
			}
			return array(
				'id'      => (int) $row['id'],
				'slug'    => $slug,
				'name'    => $name,
				'suburb'  => $suburb,
				'address' => $address,
				'phone'   => $phone,
				'hours'   => self::core_hours( (int) $row['id'] ),
			);
		}
		return null;
	}

	/**
	 * Weekly hours for a core location, as day key => list of array( opens, closes ). Anything that does not match
	 * core's own HH:MM-HH:MM format is dropped. Empty when core has none or fails.
	 *
	 * @param int $location_id Core location id.
	 * @return array
	 */
	private static function core_hours( $location_id ) {
		if ( ! is_callable( array( 'DoughBoss_Locations', 'weekly_hours' ) ) ) {
			return array();
		}
		try {
			$raw = DoughBoss_Locations::weekly_hours( $location_id );
		} catch ( Throwable $e ) {
			return array();
		}
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ) as $day ) {
			if ( empty( $raw[ $day ] ) || ! is_string( $raw[ $day ] ) ) {
				continue;
			}
			foreach ( explode( ',', $raw[ $day ] ) as $range ) {
				if ( 1 === preg_match( '/^\s*([0-2][0-9]:[0-5][0-9])\s*-\s*([0-2][0-9]:[0-5][0-9])\s*$/', $range, $m ) ) {
					$out[ $day ][] = array( $m[1], $m[2] );
				}
			}
		}
		return $out;
	}

	/**
	 * Published catering packages from core that have a name and a real price. Cached for the request.
	 *
	 * @return array List of array( id, name, price, serves_min, serves_max, includes ).
	 */
	public static function core_packages() {
		if ( null !== self::$packages ) {
			return self::$packages;
		}
		self::$packages = array();
		if ( ! class_exists( 'DoughBoss_Catering_Package' ) ) {
			return self::$packages;
		}
		try {
			$posts = get_posts(
				array(
					'post_type'   => DoughBoss_Catering_Package::POST_TYPE,
					'post_status' => 'publish',
					'numberposts' => self::MAX_PACKAGES,
					'orderby'     => array(
						'menu_order' => 'ASC',
						'title'      => 'ASC',
					),
				)
			);
		} catch ( Throwable $e ) {
			return self::$packages;
		}
		foreach ( (array) $posts as $post ) {
			if ( ! is_object( $post ) || ! isset( $post->ID, $post->post_title ) ) {
				continue;
			}
			$id    = (int) $post->ID;
			$name  = sanitize_text_field( html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ) );
			$price = get_post_meta( $id, DoughBoss_Catering_Package::META_BASE_PRICE, true );
			if ( '' === $name || array() !== self::lint_text( $name, 'core-data' ) || ! is_numeric( $price ) || (float) $price <= 0 || (float) $price > 1000000 ) {
				continue; // No name or no real price in core: no card and no Offer.
			}
			$includes = array();
			$raw      = sanitize_textarea_field( html_entity_decode( (string) get_post_meta( $id, DoughBoss_Catering_Package::META_INCLUDES, true ), ENT_QUOTES, 'UTF-8' ) );
			foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
				$line = trim( (string) $line );
				if ( '' !== $line && strlen( $line ) <= 120 && array() === self::lint_text( $line, 'core-data' ) && count( $includes ) < 12 ) {
					$includes[] = $line;
				}
			}
			self::$packages[] = array(
				'id'         => $id,
				'name'       => $name,
				'price'      => round( (float) $price, 2 ),
				'serves_min' => max( 0, min( 100000, (int) get_post_meta( $id, DoughBoss_Catering_Package::META_SERVES_MIN, true ) ) ),
				'serves_max' => max( 0, min( 100000, (int) get_post_meta( $id, DoughBoss_Catering_Package::META_SERVES_MAX, true ) ) ),
				'includes'   => $includes,
			);
		}
		return self::$packages;
	}

	/**
	 * The core catering form (the [doughboss_catering] shortcode) and whether it may be shown.
	 *
	 * It is shown only when core's shortcode exists, renders, and its text passes the public-copy lint. The
	 * lint matters: core's own form copy contains the unannounced product's working name, which must not appear
	 * on any public page (teaser-direction.md). While it does, the block is left out and the reason is shown in
	 * the admin tab. The fix belongs in core, not here.
	 *
	 * @return array array( shown (bool), html (string), reason (string) ).
	 */
	public static function core_form() {
		if ( null !== self::$form ) {
			return self::$form;
		}
		self::$form = array(
			'shown'  => false,
			'html'   => '',
			'reason' => 'core_shortcode_missing',
		);
		if ( ! shortcode_exists( 'doughboss_catering' ) ) {
			return self::$form;
		}
		try {
			$html = do_shortcode( '[doughboss_catering]' );
		} catch ( Throwable $e ) {
			self::$form['reason'] = 'core_form_failed';
			return self::$form;
		}
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			self::$form['reason'] = 'core_form_empty';
			return self::$form;
		}
		if ( false !== strpos( $html, '[doughboss_catering' ) ) {
			self::$form['reason'] = 'core_form_not_rendered';
			return self::$form;
		}
		$codes = self::lint_text( $html, 'core-data' );
		if ( array() !== $codes ) {
			self::$form['reason'] = 'core_form_copy_blocked:' . implode( ',', $codes );
			return self::$form;
		}
		self::$form = array(
			'shown'  => true,
			'html'   => $html,
			'reason' => '',
		);
		return self::$form;
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Composing a page                                                                             */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Resolve everything a page needs: its rendered blocks, the core data behind them, and why a block is hidden.
	 * Cached for the request. Never throws.
	 *
	 * @param string $key Page key.
	 * @return array {
	 *     ok: bool, reason: string, blocks: array of array( type, html ), own_blocks: int, hidden: array of
	 *     array( block, reason ), placeholders: array, location: array|null, packages: array (name, price),
	 *     faqs: array, areas: array
	 * }
	 */
	public static function compose( $key ) {
		if ( is_string( $key ) && isset( self::$composed[ $key ] ) ) {
			return self::$composed[ $key ];
		}
		$result = array(
			'ok'           => false,
			'reason'       => '',
			'blocks'       => array(),
			'own_blocks'   => 0,
			'hidden'       => array(),
			'placeholders' => array(),
			'location'     => null,
			'packages'     => array(),
			'faqs'         => array(),
			'areas'        => array(),
		);
		try {
			$result = self::compose_page( $key, $result );
		} catch ( Throwable $e ) {
			DoughBoss_Growth_Http::log( 'landing_compose_failed', array( 'error' => get_class( $e ) ) );
			$result       = array_merge( $result, array( 'ok' => false, 'reason' => 'error', 'blocks' => array(), 'own_blocks' => 0 ) );
		}
		if ( is_string( $key ) ) {
			self::$composed[ $key ] = $result;
		}
		return $result;
	}

	/**
	 * The body of compose().
	 *
	 * @param string $key    Page key.
	 * @param array  $result Starting result.
	 * @return array
	 */
	private static function compose_page( $key, array $result ) {
		$def = self::definition( $key );
		if ( null === $def ) {
			$result['reason'] = 'unknown_page';
			return $result;
		}
		if ( ! self::ledger_available() || ! DoughBoss_Growth_Ledger::is_valid() ) {
			$result['reason'] = 'ledger_invalid';
			return $result;
		}
		$location = null;
		if ( 'location' === $def['kind'] ) {
			$location = self::core_location( $def['location_slug'] );
			if ( null === $location ) {
				$result['reason'] = 'core_location_missing';
				return $result;
			}
			$result['location'] = $location;
		}

		foreach ( $def['blocks'] as $block ) {
			switch ( $block['type'] ) {
				case 'claim':
					self::compose_claim( $block, $result );
					break;
				case 'faq':
					self::compose_faq( $block, $result );
					break;
				case 'packages':
					self::compose_packages( $block, $result );
					break;
				case 'form':
					self::compose_form( $result );
					break;
				case 'location':
					self::compose_location( $block, $location, $result );
					break;
			}
		}
		if ( 'catering' === $def['kind'] ) {
			$result['areas'] = self::confirmed_areas();
		}
		$result['placeholders'] = self::placeholders( $def, $location );
		$result['ok']           = true;
		return $result;
	}

	/**
	 * Values for {name}, {suburb} and {details}.
	 *
	 * @param array      $def      Definition.
	 * @param array|null $location Core location.
	 * @return array
	 */
	private static function placeholders( array $def, $location ) {
		if ( null === $location ) {
			return array();
		}
		$slots = array( 'address' );
		foreach ( $def['blocks'] as $block ) {
			if ( 'location' === $block['type'] ) {
				$slots = $block['slots'];
			}
		}
		$present = array();
		if ( in_array( 'address', $slots, true ) ) {
			$present[] = __( 'address', 'doughboss-growth' );
		}
		if ( in_array( 'phone', $slots, true ) && '' !== $location['phone'] ) {
			$present[] = __( 'phone number', 'doughboss-growth' );
		}
		if ( in_array( 'hours', $slots, true ) && array() !== $location['hours'] ) {
			$present[] = __( 'opening hours', 'doughboss-growth' );
		}
		$last    = array_pop( $present );
		$details = ( array() === $present ) ? (string) $last : implode( ', ', $present ) . ' ' . __( 'and', 'doughboss-growth' ) . ' ' . $last;
		$values  = array(
			'name'    => $location['name'],
			'details' => $details,
		);
		if ( '' !== $location['suburb'] ) {
			$values['suburb'] = $location['suburb'];
		}
		return $values;
	}

	/**
	 * Replace the placeholders in a template. Null when one is left unresolved.
	 *
	 * @param string $template Template.
	 * @param array  $values   Placeholder => value.
	 * @return string|null
	 */
	public static function expand( $template, array $values ) {
		$out = $template;
		foreach ( $values as $name => $value ) {
			$out = str_replace( '{' . $name . '}', (string) $value, (string) $out );
		}
		return ( false !== strpos( (string) $out, '{' ) || false !== strpos( (string) $out, '}' ) ) ? null : $out;
	}

	/**
	 * Open a block element.
	 *
	 * @param string $type    Block type.
	 * @param string $heading Heading text ("" for none).
	 * @return string
	 */
	private static function open_block( $type, $heading ) {
		$html = '<section class="dbgr-lp__block dbgr-lp__block--' . esc_attr( $type ) . '">';
		if ( '' !== $heading ) {
			$html .= '<h2 class="dbgr-lp__heading">' . esc_html( $heading ) . '</h2>';
		}
		return $html;
	}

	/**
	 * A ledger claim block. Left out unless the ledger publishes the claim.
	 *
	 * @param array $block  Block definition.
	 * @param array $result Result (by reference).
	 * @return void
	 */
	private static function compose_claim( array $block, array &$result ) {
		$text = DoughBoss_Growth_Ledger::text( $block['id'] );
		if ( null === $text || '' === trim( $text ) ) {
			$result['hidden'][] = array( 'claim:' . $block['id'], 'claim_not_publishable' );
			return;
		}
		$result['blocks'][] = array(
			'type' => 'claim',
			'html' => self::open_block( 'claim', $block['heading'] ) . '<p class="dbgr-lp__text">' . esc_html( $text ) . '</p></section>',
		);
		++$result['own_blocks'];
	}

	/**
	 * A FAQ block. Each entry needs a publishable claim that carries a question.
	 *
	 * @param array $block  Block definition.
	 * @param array $result Result (by reference).
	 * @return void
	 */
	private static function compose_faq( array $block, array &$result ) {
		$all   = DoughBoss_Growth_Ledger::publishable();
		$items = '';
		foreach ( $block['claims'] as $claim_id ) {
			if ( ! isset( $all[ $claim_id ] ) ) {
				$result['hidden'][] = array( 'faq:' . $claim_id, 'claim_not_publishable' );
				continue;
			}
			$claim    = $all[ $claim_id ];
			$question = ( isset( $claim['question'] ) && is_string( $claim['question'] ) ) ? trim( $claim['question'] ) : '';
			$kind     = ( isset( $claim['source']['kind'] ) && is_string( $claim['source']['kind'] ) ) ? $claim['source']['kind'] : null;
			if ( '' === $question || array() !== self::lint_text( $question, $kind ) ) {
				$result['hidden'][] = array( 'faq:' . $claim_id, 'claim_has_no_question' );
				continue;
			}
			$result['faqs'][] = array( $question, $claim['text'] );
			$items           .= '<div class="dbgr-lp__faq"><h3 class="dbgr-lp__question">' . esc_html( $question ) . '</h3><p class="dbgr-lp__text">' . esc_html( $claim['text'] ) . '</p></div>';
		}
		if ( '' === $items ) {
			return;
		}
		$result['blocks'][] = array(
			'type' => 'faq',
			'html' => self::open_block( 'faq', $block['heading'] ) . $items . '</section>',
		);
		++$result['own_blocks'];
	}

	/**
	 * The catering package cards: live core packages with a real price.
	 *
	 * @param array $block  Block definition.
	 * @param array $result Result (by reference).
	 * @return void
	 */
	private static function compose_packages( array $block, array &$result ) {
		$packages = self::core_packages();
		if ( array() === $packages ) {
			$result['hidden'][] = array( 'packages', 'no_published_package_with_a_price' );
			return;
		}
		$html = self::open_block( 'packages', $block['heading'] ) . '<ul class="dbgr-lp__packages">';
		foreach ( $packages as $package ) {
			$html .= '<li class="dbgr-lp__package"><h3 class="dbgr-lp__package-name">' . esc_html( $package['name'] ) . '</h3>';
			$serves = self::serves_text( $package['serves_min'], $package['serves_max'] );
			if ( '' !== $serves ) {
				$html .= '<p class="dbgr-lp__serves">' . esc_html( $serves ) . '</p>';
			}
			$html .= '<p class="dbgr-lp__price">' . esc_html( self::format_price( $package['price'] ) ) . '</p>';
			if ( array() !== $package['includes'] ) {
				$html .= '<ul class="dbgr-lp__includes">';
				foreach ( $package['includes'] as $line ) {
					$html .= '<li>' . esc_html( $line ) . '</li>';
				}
				$html .= '</ul>';
			}
			$html .= '</li>';
			$result['packages'][] = array(
				'name'  => $package['name'],
				'price' => $package['price'],
			);
		}
		$html              .= '</ul></section>';
		$result['blocks'][] = array(
			'type' => 'packages',
			'html' => $html,
		);
	}

	/**
	 * "Serves 10 to 12" from core's guest range, or "" when core has none.
	 *
	 * @param int $min Minimum guests.
	 * @param int $max Maximum guests.
	 * @return string
	 */
	private static function serves_text( $min, $max ) {
		if ( $min > 0 && $max > $min ) {
			/* translators: 1: smallest guest count, 2: largest guest count. */
			return sprintf( __( 'Serves %1$d to %2$d', 'doughboss-growth' ), $min, $max );
		}
		$one = ( $max > 0 ) ? $max : $min;
		if ( $one > 0 ) {
			/* translators: %d: guest count. */
			return sprintf( __( 'Serves %d', 'doughboss-growth' ), $one );
		}
		return '';
	}

	/**
	 * A price the way core shows it (core's currency symbol setting when available).
	 *
	 * @param float $price Price.
	 * @return string
	 */
	private static function format_price( $price ) {
		if ( class_exists( 'DoughBoss_Settings' ) && is_callable( array( 'DoughBoss_Settings', 'format_price' ) ) ) {
			try {
				$text = DoughBoss_Settings::format_price( $price );
				if ( is_string( $text ) && '' !== trim( $text ) ) {
					return $text;
				}
			} catch ( Throwable $e ) {
				// Fall through to the plain dollar format.
				unset( $e );
			}
		}
		return '$' . number_format( (float) $price, 2, '.', ',' );
	}

	/**
	 * The core catering form block (see core_form()).
	 *
	 * @param array $result Result (by reference).
	 * @return void
	 */
	private static function compose_form( array &$result ) {
		$form = self::core_form();
		if ( true !== $form['shown'] ) {
			$result['hidden'][] = array( 'form', $form['reason'] );
			return;
		}
		$result['blocks'][] = array(
			'type' => 'form',
			'html' => '<section class="dbgr-lp__block dbgr-lp__block--form">' . $form['html'] . '</section>', // Core's own escaped output.
		);
	}

	/**
	 * The shop details block: address, phone and opening hours from core.
	 *
	 * @param array      $block    Block definition.
	 * @param array|null $location Core location.
	 * @param array      $result   Result (by reference).
	 * @return void
	 */
	private static function compose_location( array $block, $location, array &$result ) {
		if ( null === $location ) {
			return;
		}
		$html = self::open_block( 'location', $block['heading'] );
		$any  = false;
		if ( in_array( 'address', $block['slots'], true ) ) {
			$lines = array();
			foreach ( preg_split( '/\r\n|\r|\n/', $location['address'] ) as $line ) {
				$line = trim( (string) $line );
				if ( '' !== $line ) {
					$lines[] = esc_html( $line );
				}
			}
			$html .= '<h3 class="dbgr-lp__label">' . esc_html__( 'Address', 'doughboss-growth' ) . '</h3><address class="dbgr-lp__address">' . implode( '<br />', $lines ) . '</address>';
			$any   = true;
		}
		if ( in_array( 'phone', $block['slots'], true ) && '' !== $location['phone'] ) {
			$tel   = self::phone_e164( $location['phone'] );
			$label = esc_html( $location['phone'] );
			$html .= '<h3 class="dbgr-lp__label">' . esc_html__( 'Phone', 'doughboss-growth' ) . '</h3><p class="dbgr-lp__phone">' . ( '' !== $tel ? '<a href="' . esc_url( 'tel:' . $tel ) . '">' . $label . '</a>' : $label ) . '</p>';
		}
		if ( in_array( 'hours', $block['slots'], true ) && array() !== $location['hours'] ) {
			$html .= '<h3 class="dbgr-lp__label">' . esc_html__( 'Opening hours', 'doughboss-growth' ) . '</h3><dl class="dbgr-lp__hours">';
			foreach ( self::day_labels() as $day => $label ) {
				if ( empty( $location['hours'][ $day ] ) ) {
					continue;
				}
				$ranges = array();
				foreach ( $location['hours'][ $day ] as $range ) {
					$ranges[] = self::clock( $range[0] ) . ' ' . __( 'to', 'doughboss-growth' ) . ' ' . self::clock( $range[1] );
				}
				$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( implode( ', ', $ranges ) ) . '</dd>';
			}
			$html .= '</dl>';
		}
		if ( ! $any ) {
			return;
		}
		$result['blocks'][] = array(
			'type' => 'location',
			'html' => $html . '</section>',
		);
		++$result['own_blocks'];
	}

	/**
	 * Day key => English day name.
	 *
	 * @return array
	 */
	public static function day_labels() {
		return array(
			'mon' => __( 'Monday', 'doughboss-growth' ),
			'tue' => __( 'Tuesday', 'doughboss-growth' ),
			'wed' => __( 'Wednesday', 'doughboss-growth' ),
			'thu' => __( 'Thursday', 'doughboss-growth' ),
			'fri' => __( 'Friday', 'doughboss-growth' ),
			'sat' => __( 'Saturday', 'doughboss-growth' ),
			'sun' => __( 'Sunday', 'doughboss-growth' ),
		);
	}

	/**
	 * "06:30" as "6:30am". "24:00" and above are shown as they are stored.
	 *
	 * @param string $hhmm Time as HH:MM.
	 * @return string
	 */
	public static function clock( $hhmm ) {
		$hour   = (int) substr( $hhmm, 0, 2 );
		$minute = substr( $hhmm, 3, 2 );
		if ( $hour > 23 ) {
			return $hhmm;
		}
		$suffix = ( $hour >= 12 ) ? 'pm' : 'am';
		$h12    = $hour % 12;
		if ( 0 === $h12 ) {
			$h12 = 12;
		}
		return $h12 . ( '00' === $minute ? '' : ':' . $minute ) . $suffix;
	}

	/**
	 * An international phone number from an Australian one, or "" when it cannot be derived with certainty.
	 *
	 * @param string $phone Phone as stored in core.
	 * @return string
	 */
	public static function phone_e164( $phone ) {
		$digits = preg_replace( '/[^0-9]/', '', $phone );
		if ( ! is_string( $digits ) ) {
			return '';
		}
		if ( 10 === strlen( $digits ) && '0' === $digits[0] ) {
			return '+61' . substr( $digits, 1 );
		}
		if ( 11 === strlen( $digits ) && 0 === strpos( $digits, '61' ) ) {
			return '+' . $digits;
		}
		return '';
	}

	/**
	 * Service area names from the ledger claim, only when it is publishable AND carries an "areas" list of place
	 * names (the claim text itself is a sentence, not a place name). Empty otherwise.
	 *
	 * @return array
	 */
	private static function confirmed_areas() {
		$all = DoughBoss_Growth_Ledger::publishable();
		if ( ! isset( $all['catering-service-area'] ) ) {
			return array();
		}
		$claim = $all['catering-service-area'];
		$kind  = ( isset( $claim['source']['kind'] ) && is_string( $claim['source']['kind'] ) ) ? $claim['source']['kind'] : null;
		if ( ! isset( $claim['areas'] ) || ! is_array( $claim['areas'] ) ) {
			return array();
		}
		$areas = array();
		foreach ( $claim['areas'] as $area ) {
			if ( is_string( $area ) && '' !== trim( $area ) && strlen( $area ) <= 80 && array() === self::lint_text( $area, $kind ) && count( $areas ) < 10 ) {
				$areas[] = trim( $area );
			}
		}
		return $areas;
	}

	/**
	 * Whether a page has content of its own, so it may be indexed: at least one ledger claim, FAQ or shop block
	 * rendered. Package cards and the core form are the same on every catering page, so on their own they do not
	 * count (the SEO notes warn against near-duplicate pages).
	 *
	 * @param string $key Page key.
	 * @return bool
	 */
	public static function is_indexable( $key ) {
		$composed = self::compose( $key );
		return true === $composed['ok'] && $composed['own_blocks'] > 0;
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Front end                                                                                    */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Shortcode callback.
	 *
	 * @param mixed $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		if ( ! self::enabled() ) {
			return '';
		}
		$atts = shortcode_atts( array( 'key' => '' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		$key  = is_string( $atts['key'] ) ? $atts['key'] : '';
		if ( 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
			return '';
		}
		return self::render_key( $key );
	}

	/**
	 * The HTML of one page. An empty string when the page cannot render or has no block to show.
	 *
	 * @param string $key Page key.
	 * @return string
	 */
	public static function render_key( $key ) {
		$def      = self::definition( $key );
		$composed = self::compose( $key );
		if ( null === $def || true !== $composed['ok'] || array() === $composed['blocks'] ) {
			return '';
		}
		$html = '<div class="dbgr-lp dbgr-lp--' . esc_attr( $def['kind'] ) . '" data-dbgr-landing="' . esc_attr( $key ) . '">';
		$html .= self::breadcrumb_html( $def, $composed );
		foreach ( $composed['blocks'] as $block ) {
			$html .= $block['html'];
		}
		return $html . '</div>';
	}

	/**
	 * The visible breadcrumb: Home, the hub page, this page. Empty when the hub page cannot be found.
	 *
	 * @param array $def      Definition.
	 * @param array $composed Composed page.
	 * @return string
	 */
	private static function breadcrumb_html( array $def, array $composed ) {
		$parent = self::parent_page( $def );
		$crumb  = self::expand( $def['crumb'], $composed['placeholders'] );
		if ( null === $parent || null === $crumb || array() !== self::lint_text( $crumb, 'core-data' ) ) {
			return '';
		}
		return '<nav class="dbgr-lp__crumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'doughboss-growth' ) . '"><ol>'
			. '<li><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Home', 'doughboss-growth' ) . '</a></li>'
			. '<li><a href="' . esc_url( $parent['url'] ) . '">' . esc_html( $parent['title'] ) . '</a></li>'
			. '<li aria-current="page">' . esc_html( $crumb ) . '</li>'
			. '</ol></nav>';
	}

	/**
	 * Load the stylesheet on a companion page that renders something.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		$key = self::current_key();
		if ( '' === $key ) {
			return;
		}
		$composed = self::compose( $key );
		if ( true !== $composed['ok'] || array() === $composed['blocks'] ) {
			return;
		}
		wp_enqueue_style( self::STYLE_HANDLE, DOUGHBOSS_GROWTH_URL . 'public/css/dbgr-landing.css', array(), DOUGHBOSS_GROWTH_VERSION );
	}

	/**
	 * Filter callback for core's doughboss_load_assets and doughboss_load_catering_assets: ask core to load its
	 * storefront and catering assets on a companion page that shows the core catering form. It only ever turns
	 * loading ON for those pages; any other value is returned unchanged.
	 *
	 * @param mixed $load Whether core already loads the assets.
	 * @return mixed
	 */
	public static function filter_load_core_assets( $load ) {
		if ( true === $load ) {
			return $load;
		}
		$key = self::current_key();
		if ( '' === $key ) {
			return $load;
		}
		$composed = self::compose( $key );
		if ( true !== $composed['ok'] ) {
			return $load;
		}
		foreach ( $composed['blocks'] as $block ) {
			if ( 'form' === $block['type'] ) {
				return true;
			}
		}
		return $load;
	}

	/**
	 * Tell the ledger tab which claims each page block needs, so a hidden block is listed there.
	 *
	 * @param mixed $blocks Blocks declared so far.
	 * @return mixed
	 */
	public static function declare_blocks( $blocks ) {
		if ( ! is_array( $blocks ) ) {
			return $blocks;
		}
		foreach ( self::definitions() as $key => $def ) {
			foreach ( $def['blocks'] as $block ) {
				if ( 'claim' === $block['type'] ) {
					$blocks[] = array(
						'page'   => $key,
						'block'  => $block['heading'],
						'claims' => array( $block['id'] ),
					);
				} elseif ( 'faq' === $block['type'] ) {
					$blocks[] = array(
						'page'   => $key,
						'block'  => $block['heading'],
						'claims' => $block['claims'],
					);
				}
			}
		}
		return $blocks;
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Creating the pages                                                                           */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Create the landing pages as DRAFTS under their existing parent pages. Per page, and never publishing:
	 *
	 *  - the page is already recorded and still exists: "exists" (nothing changes);
	 *  - it was moved to the bin: "trashed" (never recreated behind the owner's back);
	 *  - the parent page (catering, locations) is missing: "parent_missing", nothing created;
	 *  - the path is taken by a page that is not the companion's: "slug_taken", nothing created. A page that holds
	 *    exactly this key's shortcode is adopted ("adopted") and recorded;
	 *  - otherwise the draft is inserted and recorded ("created"); a failed insert is "insert_failed".
	 *
	 * The option doughboss_growth_pages is rewritten only when it changed, and entries of other keys are kept.
	 *
	 * @return array Key => result code, for every valid definition.
	 */
	public static function create_pages() {
		$raw = get_option( DoughBoss_Growth_Activator::PAGES_OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$map = self::page_map();
		$out = array();
		foreach ( self::definitions() as $key => $def ) {
			if ( isset( $map[ $key ] ) ) {
				$post = get_post( $map[ $key ] );
				if ( is_object( $post ) && isset( $post->post_type ) && 'page' === $post->post_type ) {
					$out[ $key ] = ( isset( $post->post_status ) && 'trash' === $post->post_status ) ? 'trashed' : 'exists';
					continue;
				}
				unset( $map[ $key ] ); // The recorded page no longer exists; fall through and create it again.
			}
			$parent = self::parent_page( $def );
			if ( null === $parent ) {
				$out[ $key ] = 'parent_missing';
				continue;
			}
			$taken = get_page_by_path( $def['parent'] . '/' . $def['slug'], 'OBJECT', 'page' );
			if ( is_object( $taken ) && isset( $taken->ID ) ) {
				if ( self::shortcode_key( isset( $taken->post_content ) ? (string) $taken->post_content : '' ) === $key ) {
					$map[ $key ] = (int) $taken->ID;
					$out[ $key ] = 'adopted';
				} else {
					$out[ $key ] = 'slug_taken';
				}
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'      => 'page',
					'post_status'    => 'draft',
					'post_title'     => $def['page_title'],
					'post_name'      => $def['slug'],
					'post_parent'    => $parent['id'],
					'post_content'   => '[' . self::SHORTCODE . ' key="' . $key . '"]',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				),
				true
			);
			if ( is_wp_error( $id ) || (int) $id < 1 ) {
				$out[ $key ] = 'insert_failed';
				continue;
			}
			$map[ $key ] = (int) $id;
			$out[ $key ] = 'created';
		}
		$next = $raw;
		foreach ( self::definitions() as $key => $def ) {
			unset( $next[ $key ] );
			if ( isset( $map[ $key ] ) ) {
				$next[ $key ] = $map[ $key ];
			}
		}
		if ( $next !== $raw ) {
			update_option( DoughBoss_Growth_Activator::PAGES_OPTION, $next, true );
		}
		return $out;
	}

	/**
	 * Admin-post handler for the create button. Capability AND nonce first.
	 *
	 * @return void
	 */
	public static function handle_create_pages() {
		if ( ! DoughBoss_Growth_Admin::user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to create these pages.', 'doughboss-growth' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::CREATE_ACTION );
		$parts = array();
		try {
			foreach ( self::create_pages() as $key => $code ) {
				$parts[] = $key . '.' . $code;
			}
		} catch ( Throwable $e ) {
			DoughBoss_Growth_Http::log( 'landing_create_failed', array( 'error' => get_class( $e ) ) );
			$parts = array( 'error' );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => DoughBoss_Growth_Admin::PAGE_SLUG,
					'tab'     => 'landing',
					'dbgr_lp' => implode( ',', $parts ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Admin tab                                                                                    */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Register the Landing pages tab.
	 *
	 * @return void
	 */
	public static function register_tab() {
		DoughBoss_Growth_Admin::add_tab( 'landing', __( 'Landing pages', 'doughboss-growth' ), array( __CLASS__, 'render_admin_tab' ) );
	}

	/**
	 * Sentence for a result code.
	 *
	 * @param string $code Result code.
	 * @return string
	 */
	private static function result_text( $code ) {
		$map = array(
			'created'       => __( 'Draft created.', 'doughboss-growth' ),
			'adopted'       => __( 'A page with this shortcode already existed and is now recorded.', 'doughboss-growth' ),
			'exists'        => __( 'Already created, left as it is.', 'doughboss-growth' ),
			'parent_missing' => __( 'Not created: the parent page does not exist.', 'doughboss-growth' ),
			'slug_taken'    => __( 'Not created: another page already uses this address.', 'doughboss-growth' ),
			'insert_failed' => __( 'Not created: WordPress could not save the page.', 'doughboss-growth' ),
			'trashed'       => __( 'Left alone: the page is in the bin.', 'doughboss-growth' ),
		);
		return isset( $map[ $code ] ) ? $map[ $code ] : '';
	}

	/**
	 * Sentence for a reason a block or page is hidden.
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	private static function reason_text( $reason ) {
		if ( 0 === strpos( $reason, 'core_form_copy_blocked' ) ) {
			return __( 'The core catering form is left out because its own text fails the public-copy lint (it contains the unannounced product name). Core must neutralise that copy first.', 'doughboss-growth' );
		}
		$map = array(
			'claim_not_publishable'             => __( 'Waiting for a confirmed, sourced claim in the ledger.', 'doughboss-growth' ),
			'claim_has_no_question'             => __( 'The claim has no question, so it is not shown as an FAQ.', 'doughboss-growth' ),
			'no_published_package_with_a_price' => __( 'Core has no published catering package with a name and a price.', 'doughboss-growth' ),
			'core_shortcode_missing'            => __( 'The core catering shortcode is not available.', 'doughboss-growth' ),
			'core_form_empty'                   => __( 'The core catering form rendered nothing.', 'doughboss-growth' ),
			'core_form_not_rendered'            => __( 'The core catering form did not render.', 'doughboss-growth' ),
			'core_form_failed'                  => __( 'The core catering form failed to render.', 'doughboss-growth' ),
			'core_location_missing'             => __( 'Core has no active shop with this page\'s slug, or its name or address fails the lint.', 'doughboss-growth' ),
			'ledger_invalid'                    => __( 'The claims ledger is invalid or unreadable.', 'doughboss-growth' ),
			'error'                             => __( 'An error stopped the page from composing.', 'doughboss-growth' ),
		);
		return isset( $map[ $reason ] ) ? $map[ $reason ] : $reason;
	}

	/**
	 * The Landing pages tab: status, the create button, and every page with the title and description an operator
	 * pastes into an SEO plugin. Escapes everything; the only form is the create button (capability and nonce).
	 *
	 * @return void
	 */
	public static function render_admin_tab() {
		if ( ! DoughBoss_Growth_Admin::user_can_manage() ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Landing pages', 'doughboss-growth' ) . '</h2>';
		echo '<p>' . esc_html__( 'Six contract pages are created as drafts under the existing Catering and Locations pages. Publishing each one is your decision, in the normal page editor. Nothing here changes the Catering or Locations pages themselves.', 'doughboss-growth' ) . '</p>';
		self::render_result_notice();

		$def_problems = self::definition_problems();
		if ( array() !== $def_problems ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Some page definitions are invalid and are left out:', 'doughboss-growth' ) . '</strong></p><ul>';
			foreach ( $def_problems as $problem ) {
				echo '<li>' . esc_html( $problem ) . '</li>';
			}
			echo '</ul></div>';
		}

		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		self::status_row( __( 'Landing pages feature', 'doughboss-growth' ), self::enabled() ? __( 'On', 'doughboss-growth' ) : __( 'Off', 'doughboss-growth' ) );
		$head = class_exists( 'DoughBoss_Growth_Landing_SEO' ) && DoughBoss_Growth_Landing_SEO::head_enabled();
		self::status_row( __( 'Search metadata (needs both flags)', 'doughboss-growth' ), $head ? __( 'On', 'doughboss-growth' ) : __( 'Off', 'doughboss-growth' ) );
		$plugin = class_exists( 'DoughBoss_Growth_Landing_SEO' ) && DoughBoss_Growth_Landing_SEO::seo_plugin_active();
		if ( $plugin ) {
			$mode = DoughBoss_Growth_Landing_SEO::jsonld_with_seo_plugin() ? __( 'An SEO plugin is active: the companion prints only the structured data. Paste the title and description below into that plugin.', 'doughboss-growth' ) : __( 'An SEO plugin is active: the companion prints nothing in the page head. Paste the title and description below into that plugin.', 'doughboss-growth' );
		} else {
			$mode = __( 'No SEO plugin detected: the companion prints the title, description, social tags and structured data on its own pages.', 'doughboss-growth' );
		}
		self::status_row( __( 'Head ownership', 'doughboss-growth' ), $mode );
		$form = self::core_form();
		self::status_row( __( 'Core catering form block', 'doughboss-growth' ), $form['shown'] ? __( 'Shown on catering pages', 'doughboss-growth' ) : self::reason_text( $form['reason'] ) );
		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:1em 0">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::CREATE_ACTION ) . '" />';
		wp_nonce_field( self::CREATE_ACTION );
		submit_button( __( 'Create landing pages', 'doughboss-growth' ), 'primary', 'submit', false );
		echo ' <span class="description">' . esc_html__( 'Creates only the missing pages, as drafts. Never publishes, never overwrites.', 'doughboss-growth' ) . '</span>';
		echo '</form>';

		$map = self::page_map();
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Page', 'doughboss-growth' ), __( 'State', 'doughboss-growth' ), __( 'Title and description (for an SEO plugin)', 'doughboss-growth' ), __( 'Hidden or waiting', 'doughboss-growth' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$defs = self::definitions();
		if ( array() === $defs ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No valid page definitions.', 'doughboss-growth' ) . '</td></tr>';
		}
		foreach ( $defs as $key => $def ) {
			self::render_page_row( $key, $def, isset( $map[ $key ] ) ? $map[ $key ] : 0 );
		}
		echo '</tbody></table>';
	}

	/**
	 * One status row.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @return void
	 */
	private static function status_row( $label, $value ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	/**
	 * The result of the last create click (from the redirect query), shown as a notice.
	 *
	 * @return void
	 */
	private static function render_result_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only result flags after a redirect.
		$raw = isset( $_GET['dbgr_lp'] ) ? sanitize_text_field( wp_unslash( $_GET['dbgr_lp'] ) ) : '';
		if ( '' === $raw ) {
			return;
		}
		$defs  = self::definitions();
		$lines = array();
		foreach ( explode( ',', $raw ) as $part ) {
			$bits = explode( '.', $part );
			if ( 2 === count( $bits ) && isset( $defs[ $bits[0] ] ) && in_array( $bits[1], self::RESULT_CODES, true ) ) {
				$lines[] = $defs[ $bits[0] ]['parent'] . '/' . $defs[ $bits[0] ]['slug'] . ': ' . self::result_text( $bits[1] );
			}
		}
		if ( array() === $lines ) {
			return;
		}
		echo '<div class="notice notice-info is-dismissible"><ul>';
		foreach ( $lines as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * One page row of the admin table.
	 *
	 * @param string $key     Page key.
	 * @param array  $def     Definition.
	 * @param int    $page_id Recorded page id or 0.
	 * @return void
	 */
	private static function render_page_row( $key, array $def, $page_id ) {
		$composed = self::compose( $key );
		$post     = $page_id > 0 ? get_post( $page_id ) : null;
		$state    = __( 'Not created yet', 'doughboss-growth' );
		if ( is_object( $post ) && isset( $post->post_status ) ) {
			$state = ucfirst( (string) $post->post_status );
		}
		echo '<tr><td><code>/' . esc_html( $def['parent'] . '/' . $def['slug'] ) . '/</code>';
		if ( is_object( $post ) ) {
			echo '<br /><a href="' . esc_url( admin_url( 'post.php?post=' . (int) $page_id . '&action=edit' ) ) . '">' . esc_html__( 'Edit', 'doughboss-growth' ) . '</a>';
		}
		echo '</td><td>' . esc_html( $state );
		if ( true === $composed['ok'] ) {
			echo '<br />' . esc_html( $composed['own_blocks'] > 0 ? __( 'Indexable', 'doughboss-growth' ) : __( 'Marked noindex: no content of its own yet', 'doughboss-growth' ) );
		}
		echo '</td><td>';
		$texts = class_exists( 'DoughBoss_Growth_Landing_SEO' ) ? DoughBoss_Growth_Landing_SEO::texts( $key ) : null;
		if ( null === $texts ) {
			echo esc_html( true === $composed['ok'] ? __( 'Not available: a title or description fails the lint or is too long.', 'doughboss-growth' ) : self::reason_text( $composed['reason'] ) );
		} else {
			echo '<label class="screen-reader-text" for="dbgr-lp-t-' . esc_attr( $key ) . '">' . esc_html__( 'Title', 'doughboss-growth' ) . '</label>';
			echo '<input type="text" readonly="readonly" class="large-text" id="dbgr-lp-t-' . esc_attr( $key ) . '" value="' . esc_attr( $texts['title'] ) . '" />';
			echo '<label class="screen-reader-text" for="dbgr-lp-d-' . esc_attr( $key ) . '">' . esc_html__( 'Description', 'doughboss-growth' ) . '</label>';
			echo '<textarea readonly="readonly" class="large-text" rows="2" id="dbgr-lp-d-' . esc_attr( $key ) . '">' . esc_textarea( $texts['description'] ) . '</textarea>';
			/* translators: 1: title length, 2: description length. */
			echo '<span class="description">' . esc_html( sprintf( __( 'Title %1$d characters, description %2$d characters.', 'doughboss-growth' ), function_exists( 'mb_strlen' ) ? mb_strlen( $texts['title'], 'UTF-8' ) : strlen( $texts['title'] ), function_exists( 'mb_strlen' ) ? mb_strlen( $texts['description'], 'UTF-8' ) : strlen( $texts['description'] ) ) ) . '</span>';
		}
		echo '</td><td>';
		if ( true !== $composed['ok'] ) {
			echo esc_html( self::reason_text( $composed['reason'] ) );
		} elseif ( array() === $composed['hidden'] ) {
			echo esc_html__( 'Nothing hidden.', 'doughboss-growth' );
		} else {
			echo '<ul>';
			foreach ( $composed['hidden'] as $hidden ) {
				echo '<li><code>' . esc_html( $hidden[0] ) . '</code>: ' . esc_html( self::reason_text( $hidden[1] ) ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</td></tr>';
	}
}
