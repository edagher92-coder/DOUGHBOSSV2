<?php
/**
 * Registers the menu-item post type and its category taxonomy.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu items (pizzas, sides, drinks, etc.) modelled as a custom post type.
 */
class DoughBoss_Post_Types {

	const POST_TYPE = 'doughboss_item';
	const TAXONOMY  = 'doughboss_category';

	const META_PRICE     = '_doughboss_price';
	const META_TYPE      = '_doughboss_item_type';
	const META_AVAILABLE = '_doughboss_available';

	const MENU_VERSION_OPTION = 'doughboss_menu_version';

	/**
	 * Hook registration into WordPress.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta' ), 10, 2 );

		// Anything that changes the menu invalidates the cached /menu payload.
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'bump_menu_version' ) );
		add_action( 'deleted_post', array( __CLASS__, 'bump_menu_version_for_post' ), 10, 2 );
		add_action( 'trashed_post', array( __CLASS__, 'bump_menu_version_for_post' ), 10, 2 );
		add_action( 'edited_' . self::TAXONOMY, array( __CLASS__, 'bump_menu_version' ) );
		add_action( 'delete_' . self::TAXONOMY, array( __CLASS__, 'bump_menu_version' ) );
		add_action( 'updated_post_meta', array( __CLASS__, 'bump_menu_version_for_meta' ), 10, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'bump_menu_version_for_meta' ), 10, 3 );
	}

	/**
	 * Current menu cache version.
	 *
	 * @return int
	 */
	public static function menu_version() {
		return max( 1, (int) get_option( self::MENU_VERSION_OPTION, 1 ) );
	}

	/**
	 * Invalidate the cached menu.
	 *
	 * @return void
	 */
	public static function bump_menu_version() {
		update_option( self::MENU_VERSION_OPTION, self::menu_version() + 1, false );
	}

	/**
	 * Bump only when the affected post is a menu item.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post.
	 * @return void
	 */
	public static function bump_menu_version_for_post( $post_id, $post = null ) {
		$post = $post ? $post : get_post( $post_id );
		if ( $post && self::POST_TYPE === $post->post_type ) {
			self::bump_menu_version();
		}
	}

	/**
	 * Bump when a menu item's thumbnail or plugin meta changes via REST/other.
	 *
	 * @param int    $meta_id  Meta ID.
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return void
	 */
	public static function bump_menu_version_for_meta( $meta_id, $post_id, $meta_key ) {
		if ( in_array( $meta_key, array( '_thumbnail_id', self::META_PRICE, self::META_TYPE, self::META_AVAILABLE ), true ) ) {
			self::bump_menu_version_for_post( $post_id );
		}
	}

	/**
	 * Whether a menu item is available to order today (the "86 it" switch).
	 * Missing meta means available, so existing items are unaffected.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_available( $post_id ) {
		$value = get_post_meta( $post_id, self::META_AVAILABLE, true );
		return '' === $value || '0' !== (string) $value;
	}

	/**
	 * Register the post type, taxonomy and meta. Static so the activator can
	 * call it directly before flushing rewrite rules.
	 *
	 * Menu items use their own mapped capability set rather than the generic
	 * `post` caps, so a blog Author cannot publish a sellable item at any price.
	 *
	 * @return void
	 */
	public static function register() {
		$labels = array(
			'name'               => __( 'Menu Items', 'doughboss' ),
			'singular_name'      => __( 'Menu Item', 'doughboss' ),
			'add_new'            => __( 'Add New', 'doughboss' ),
			'add_new_item'       => __( 'Add New Menu Item', 'doughboss' ),
			'edit_item'          => __( 'Edit Menu Item', 'doughboss' ),
			'new_item'           => __( 'New Menu Item', 'doughboss' ),
			'view_item'          => __( 'View Menu Item', 'doughboss' ),
			'search_items'       => __( 'Search Menu Items', 'doughboss' ),
			'not_found'          => __( 'No menu items found.', 'doughboss' ),
			'not_found_in_trash' => __( 'No menu items found in Trash.', 'doughboss' ),
			'all_items'          => __( 'Menu Items', 'doughboss' ),
			'menu_name'          => __( 'DoughBoss', 'doughboss' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => $labels,
				'public'              => true,
				// Items are ordered from the menu page; a bare single-item URL
				// with no price or Add button only competes with it in search.
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => 'doughboss',
				'show_in_rest'        => true,
				'menu_icon'           => 'dashicons-food',
				'has_archive'         => false,
				'rewrite'             => false,
				'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes' ),
				'capability_type'     => array( 'doughboss_item', 'doughboss_items' ),
				'map_meta_cap'        => true,
			)
		);

		register_taxonomy(
			self::TAXONOMY,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Menu Categories', 'doughboss' ),
					'singular_name' => __( 'Menu Category', 'doughboss' ),
					'add_new_item'  => __( 'Add New Category', 'doughboss' ),
					'edit_item'     => __( 'Edit Category', 'doughboss' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => false,
				'capabilities'      => array(
					'manage_terms' => 'manage_doughboss_categories',
					'edit_terms'   => 'manage_doughboss_categories',
					'delete_terms' => 'manage_doughboss_categories',
					'assign_terms' => 'edit_doughboss_items',
				),
			)
		);

		$auth = function ( $allowed, $meta_key, $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		};

		register_post_meta(
			self::POST_TYPE,
			self::META_PRICE,
			array(
				'type'              => 'number',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_price' ),
				'auth_callback'     => $auth,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_TYPE,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'default'           => 'standard',
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => $auth,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_AVAILABLE,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'show_in_rest'      => true,
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'auth_callback'     => $auth,
			)
		);
	}

	/**
	 * Sanitize a price to two decimal places, never negative.
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	public static function sanitize_price( $value ) {
		$value = (float) $value;
		return $value < 0 ? 0.0 : round( $value, 2 );
	}

	/**
	 * Add the price/type meta box to the editor.
	 *
	 * @return void
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'doughboss_item_details',
			__( 'Item Details', 'doughboss' ),
			array( $this, 'render_meta_box' ),
			self::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * Render the price/type/availability meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'doughboss_save_item', 'doughboss_item_nonce' );

		$price     = get_post_meta( $post->ID, self::META_PRICE, true );
		$type      = get_post_meta( $post->ID, self::META_TYPE, true );
		$type      = $type ? $type : 'standard';
		$available = self::is_available( $post->ID );
		?>
		<p>
			<label for="doughboss_price"><strong><?php esc_html_e( 'Price', 'doughboss' ); ?></strong></label><br />
			<input type="number" step="0.01" min="0" id="doughboss_price" name="doughboss_price"
				value="<?php echo esc_attr( $price ); ?>" style="width:100%;" />
		</p>
		<p>
			<label for="doughboss_item_type"><strong><?php esc_html_e( 'Type', 'doughboss' ); ?></strong></label><br />
			<select id="doughboss_item_type" name="doughboss_item_type" style="width:100%;">
				<option value="standard" <?php selected( $type, 'standard' ); ?>><?php esc_html_e( 'Standard item', 'doughboss' ); ?></option>
				<option value="pizza" <?php selected( $type, 'pizza' ); ?>><?php esc_html_e( 'Specialty pizza', 'doughboss' ); ?></option>
				<option value="side" <?php selected( $type, 'side' ); ?>><?php esc_html_e( 'Side', 'doughboss' ); ?></option>
				<option value="drink" <?php selected( $type, 'drink' ); ?>><?php esc_html_e( 'Drink', 'doughboss' ); ?></option>
			</select>
		</p>
		<p>
			<label for="doughboss_available">
				<input type="checkbox" id="doughboss_available" name="doughboss_available" value="1" <?php checked( $available ); ?> />
				<strong><?php esc_html_e( 'Available to order', 'doughboss' ); ?></strong>
			</label><br />
			<span class="description"><?php esc_html_e( 'Untick to mark this item sold out without unpublishing it.', 'doughboss' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Persist the meta box values.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['doughboss_item_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['doughboss_item_nonce'] ) ), 'doughboss_save_item' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['doughboss_price'] ) ) {
			$price = self::sanitize_price( wp_unslash( $_POST['doughboss_price'] ) );
			update_post_meta( $post_id, self::META_PRICE, $price );
		}

		if ( isset( $_POST['doughboss_item_type'] ) ) {
			$type = sanitize_key( wp_unslash( $_POST['doughboss_item_type'] ) );
			update_post_meta( $post_id, self::META_TYPE, $type );
		}

		// Checkbox: absent from the POST when unticked.
		update_post_meta( $post_id, self::META_AVAILABLE, empty( $_POST['doughboss_available'] ) ? '0' : '1' );
	}
}
