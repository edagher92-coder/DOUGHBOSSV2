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

	const META_PRICE = '_doughboss_price';
	const META_TYPE  = '_doughboss_item_type';

	/**
	 * Hook registration into WordPress.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta' ), 10, 2 );
	}

	/**
	 * Register the post type, taxonomy and meta. Static so the activator can
	 * call it directly before flushing rewrite rules.
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
				'labels'        => $labels,
				'public'        => true,
				'show_ui'       => true,
				'show_in_menu'  => 'doughboss',
				'show_in_rest'  => true,
				'menu_icon'     => 'dashicons-food',
				'has_archive'   => false,
				'rewrite'       => array( 'slug' => 'menu' ),
				'supports'      => array( 'title', 'editor', 'thumbnail', 'page-attributes' ),
				'capability_type' => 'post',
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
				'public'            => true,
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'menu-category' ),
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_PRICE,
			array(
				'type'              => 'number',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_price' ),
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
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
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
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
	 * Render the price/type meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'doughboss_save_item', 'doughboss_item_nonce' );

		$price = get_post_meta( $post->ID, self::META_PRICE, true );
		$type  = get_post_meta( $post->ID, self::META_TYPE, true );
		$type  = $type ? $type : 'standard';
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
	}
}
