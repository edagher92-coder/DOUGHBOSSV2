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

	/**
	 * Hook registration into WordPress.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta' ), 10, 2 );

		// Menu Items list table: price + availability columns and a one-tap toggle.
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_admin_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_doughboss_toggle_availability', array( $this, 'handle_toggle_availability' ) );
	}

	/**
	 * Whether a menu item is available to order (default true; only an explicit
	 * "0" means sold out).
	 *
	 * @param int $post_id Item ID.
	 * @return bool
	 */
	public static function is_available( $post_id ) {
		return '0' !== (string) get_post_meta( $post_id, self::META_AVAILABLE, true );
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

		register_post_meta(
			self::POST_TYPE,
			self::META_AVAILABLE,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'default'           => '1',
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
		<p style="margin-top:12px;border-top:1px solid #eee;padding-top:10px;">
			<label for="doughboss_available">
				<input type="checkbox" id="doughboss_available" name="doughboss_available" value="1" <?php checked( $available ); ?> />
				<strong><?php esc_html_e( 'Available for ordering', 'doughboss' ); ?></strong>
			</label><br />
			<span class="description"><?php esc_html_e( 'Uncheck to mark this item sold out — it stays on the menu but can\'t be added to a cart.', 'doughboss' ); ?></span>
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

		// The meta box always renders the availability checkbox, so its absence
		// from the POST means the box was unticked (sold out).
		update_post_meta( $post_id, self::META_AVAILABLE, empty( $_POST['doughboss_available'] ) ? '0' : '1' );
	}

	/**
	 * Add Price + Availability columns to the Menu Items list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function admin_columns( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$out['doughboss_price']        = __( 'Price', 'doughboss' );
				$out['doughboss_availability'] = __( 'Availability', 'doughboss' );
			}
			$out[ $key ] = $label;
		}
		return $out;
	}

	/**
	 * Render the custom list-table columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_admin_column( $column, $post_id ) {
		if ( 'doughboss_price' === $column ) {
			$price = (float) get_post_meta( $post_id, self::META_PRICE, true );
			echo esc_html( DoughBoss_Settings::format_price( $price ) );
		} elseif ( 'doughboss_availability' === $column ) {
			if ( self::is_available( $post_id ) ) {
				echo '<span style="display:inline-block;padding:2px 9px;border-radius:11px;font-size:11px;font-weight:600;background:#e7f6e7;color:#1a7f37;">' . esc_html__( 'Available', 'doughboss' ) . '</span>';
			} else {
				echo '<span style="display:inline-block;padding:2px 9px;border-radius:11px;font-size:11px;font-weight:600;background:#fdecea;color:#b3261e;">' . esc_html__( 'Sold out', 'doughboss' ) . '</span>';
			}
		}
	}

	/**
	 * Add a one-tap "Mark sold out / Mark available" row action.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public function row_actions( $actions, $post ) {
		if ( self::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		$url   = wp_nonce_url(
			admin_url( 'admin-post.php?action=doughboss_toggle_availability&post=' . $post->ID ),
			'doughboss_toggle_' . $post->ID
		);
		$label = self::is_available( $post->ID ) ? __( 'Mark sold out', 'doughboss' ) : __( 'Mark available', 'doughboss' );

		$actions['doughboss_toggle'] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		return $actions;
	}

	/**
	 * Handle the availability toggle from the list-table row action.
	 *
	 * @return void
	 */
	public function handle_toggle_availability() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_toggle_' . $post_id );

		update_post_meta( $post_id, self::META_AVAILABLE, self::is_available( $post_id ) ? '0' : '1' );

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url( 'edit.php?post_type=' . self::POST_TYPE );
		}
		wp_safe_redirect( $redirect );
		exit;
	}
}
