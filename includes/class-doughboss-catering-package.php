<?php
/**
 * Registers the catering-package post type.
 *
 * Catering packages (Lunch Run, Party Box, Footy Feed, Big Event, …) are
 * owner-editable content, so they are modelled as a custom post type that
 * mirrors the menu-item CPT: title + description + featured image, plus a
 * handful of pricing/serving meta fields used to build a quote.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Catering packages modelled as a custom post type.
 */
class DoughBoss_Catering_Package {

	// WordPress hard-limits post type names to 20 characters (wp_posts.post_type
	// is varchar(20)); register_post_type() silently no-ops past that, which is
	// what 'doughboss_catering_package' (27 chars) was doing on every real
	// WordPress install. Caught via a real WP boot, not the PHP stub.
	const POST_TYPE = 'doughboss_cat_pkg';

	const META_SERVES_MIN  = '_doughboss_cat_serves_min';
	const META_SERVES_MAX  = '_doughboss_cat_serves_max';
	const META_BASE_PRICE  = '_doughboss_cat_base_price';
	const META_PER_HEAD    = '_doughboss_cat_per_head';
	const META_DEPOSIT_PCT = '_doughboss_cat_deposit_pct';
	const META_LEAD_DAYS   = '_doughboss_cat_lead_days';
	const META_INCLUDES    = '_doughboss_cat_includes';

	/**
	 * Default deposit percentage when a package does not set its own.
	 */
	const DEFAULT_DEPOSIT_PCT = 30;

	/**
	 * Default lead time (days) when a package does not set its own.
	 */
	const DEFAULT_LEAD_DAYS = 2;

	/**
	 * Hook registration into WordPress.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta' ), 10, 2 );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_admin_column' ), 10, 2 );
	}

	/**
	 * Register the post type and its meta. Static so the activator can call it
	 * directly before flushing rewrite rules.
	 *
	 * @return void
	 */
	public static function register() {
		$labels = array(
			'name'               => __( 'Catering Packages', 'doughboss' ),
			'singular_name'      => __( 'Catering Package', 'doughboss' ),
			'add_new'            => __( 'Add New', 'doughboss' ),
			'add_new_item'       => __( 'Add New Catering Package', 'doughboss' ),
			'edit_item'          => __( 'Edit Catering Package', 'doughboss' ),
			'new_item'           => __( 'New Catering Package', 'doughboss' ),
			'view_item'          => __( 'View Catering Package', 'doughboss' ),
			'search_items'       => __( 'Search Catering Packages', 'doughboss' ),
			'not_found'          => __( 'No catering packages found.', 'doughboss' ),
			'not_found_in_trash' => __( 'No catering packages found in Trash.', 'doughboss' ),
			'all_items'          => __( 'Catering Packages', 'doughboss' ),
			'menu_name'          => __( 'Catering Packages', 'doughboss' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => $labels,
				'public'          => true,
				'show_ui'         => true,
				'show_in_menu'    => 'doughboss',
				'show_in_rest'    => true,
				'menu_icon'       => 'dashicons-groups',
				'has_archive'     => false,
				'rewrite'         => array( 'slug' => 'catering-package' ),
				'supports'        => array( 'title', 'editor', 'thumbnail', 'page-attributes' ),
				'capability_type' => 'post',
			)
		);

		$number_meta = array(
			self::META_SERVES_MIN  => 'absint',
			self::META_SERVES_MAX  => 'absint',
			self::META_LEAD_DAYS   => 'absint',
			self::META_DEPOSIT_PCT => 'absint',
			self::META_BASE_PRICE  => array( __CLASS__, 'sanitize_amount' ),
			self::META_PER_HEAD    => array( __CLASS__, 'sanitize_amount' ),
		);

		foreach ( $number_meta as $key => $sanitizer ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'              => 'number',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => $sanitizer,
					'auth_callback'     => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}

		register_post_meta(
			self::POST_TYPE,
			self::META_INCLUDES,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_textarea_field',
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/**
	 * Sanitize a money amount to two decimals, never negative.
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	public static function sanitize_amount( $value ) {
		$value = (float) $value;
		return $value < 0 ? 0.0 : round( $value, 2 );
	}

	/**
	 * The deposit percentage for a package, falling back to the default.
	 *
	 * @param int $post_id Package ID.
	 * @return int Clamped 0–100.
	 */
	public static function deposit_pct( $post_id ) {
		$pct = (int) get_post_meta( $post_id, self::META_DEPOSIT_PCT, true );
		if ( $pct <= 0 ) {
			$pct = self::DEFAULT_DEPOSIT_PCT;
		}
		return min( 100, max( 0, $pct ) );
	}

	/**
	 * The lead time (days) for a package, falling back to the default.
	 *
	 * @param int $post_id Package ID.
	 * @return int
	 */
	public static function lead_days( $post_id ) {
		$days = (int) get_post_meta( $post_id, self::META_LEAD_DAYS, true );
		return $days > 0 ? $days : self::DEFAULT_LEAD_DAYS;
	}

	/**
	 * Add the package-details meta box.
	 *
	 * @return void
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'doughboss_catering_details',
			__( 'Package Details', 'doughboss' ),
			array( $this, 'render_meta_box' ),
			self::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * Render the package-details meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'doughboss_save_catering_package', 'doughboss_catering_nonce' );

		$serves_min = get_post_meta( $post->ID, self::META_SERVES_MIN, true );
		$serves_max = get_post_meta( $post->ID, self::META_SERVES_MAX, true );
		$base       = get_post_meta( $post->ID, self::META_BASE_PRICE, true );
		$per_head   = get_post_meta( $post->ID, self::META_PER_HEAD, true );
		$deposit    = get_post_meta( $post->ID, self::META_DEPOSIT_PCT, true );
		$lead       = get_post_meta( $post->ID, self::META_LEAD_DAYS, true );
		$includes   = get_post_meta( $post->ID, self::META_INCLUDES, true );
		?>
		<p>
			<label><strong><?php esc_html_e( 'Serves (guests)', 'doughboss' ); ?></strong></label><br />
			<input type="number" min="0" step="1" name="doughboss_cat_serves_min" placeholder="<?php esc_attr_e( 'min', 'doughboss' ); ?>"
				value="<?php echo esc_attr( $serves_min ); ?>" style="width:48%;" />
			<input type="number" min="0" step="1" name="doughboss_cat_serves_max" placeholder="<?php esc_attr_e( 'max', 'doughboss' ); ?>"
				value="<?php echo esc_attr( $serves_max ); ?>" style="width:48%;" />
		</p>
		<p>
			<label for="doughboss_cat_base_price"><strong><?php esc_html_e( 'Package price', 'doughboss' ); ?></strong></label><br />
			<input type="number" min="0" step="0.01" id="doughboss_cat_base_price" name="doughboss_cat_base_price"
				value="<?php echo esc_attr( $base ); ?>" style="width:100%;" />
		</p>
		<p>
			<label for="doughboss_cat_per_head"><strong><?php esc_html_e( 'Per-head price (optional)', 'doughboss' ); ?></strong></label><br />
			<input type="number" min="0" step="0.01" id="doughboss_cat_per_head" name="doughboss_cat_per_head"
				value="<?php echo esc_attr( $per_head ); ?>" style="width:100%;" />
			<span class="description"><?php esc_html_e( 'If set, used for custom headcounts above the package range.', 'doughboss' ); ?></span>
		</p>
		<p>
			<label for="doughboss_cat_deposit_pct"><strong><?php esc_html_e( 'Deposit %', 'doughboss' ); ?></strong></label><br />
			<input type="number" min="0" max="100" step="1" id="doughboss_cat_deposit_pct" name="doughboss_cat_deposit_pct"
				value="<?php echo esc_attr( $deposit ); ?>" placeholder="<?php echo esc_attr( self::DEFAULT_DEPOSIT_PCT ); ?>" style="width:100%;" />
		</p>
		<p>
			<label for="doughboss_cat_lead_days"><strong><?php esc_html_e( 'Lead time (days)', 'doughboss' ); ?></strong></label><br />
			<input type="number" min="0" step="1" id="doughboss_cat_lead_days" name="doughboss_cat_lead_days"
				value="<?php echo esc_attr( $lead ); ?>" placeholder="<?php echo esc_attr( self::DEFAULT_LEAD_DAYS ); ?>" style="width:100%;" />
		</p>
		<p>
			<label for="doughboss_cat_includes"><strong><?php esc_html_e( "What's included", 'doughboss' ); ?></strong></label><br />
			<textarea id="doughboss_cat_includes" name="doughboss_cat_includes" rows="3" style="width:100%;"><?php echo esc_textarea( $includes ); ?></textarea>
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
		if ( ! isset( $_POST['doughboss_catering_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['doughboss_catering_nonce'] ) ), 'doughboss_save_catering_package' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$ints = array(
			self::META_SERVES_MIN  => 'doughboss_cat_serves_min',
			self::META_SERVES_MAX  => 'doughboss_cat_serves_max',
			self::META_DEPOSIT_PCT => 'doughboss_cat_deposit_pct',
			self::META_LEAD_DAYS   => 'doughboss_cat_lead_days',
		);
		foreach ( $ints as $meta_key => $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, $meta_key, absint( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		$amounts = array(
			self::META_BASE_PRICE => 'doughboss_cat_base_price',
			self::META_PER_HEAD   => 'doughboss_cat_per_head',
		);
		foreach ( $amounts as $meta_key => $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, $meta_key, self::sanitize_amount( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		if ( isset( $_POST['doughboss_cat_includes'] ) ) {
			update_post_meta( $post_id, self::META_INCLUDES, sanitize_textarea_field( wp_unslash( $_POST['doughboss_cat_includes'] ) ) );
		}
	}

	/**
	 * Add Serves / Price / Deposit columns to the list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function admin_columns( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$out['doughboss_cat_serves']  = __( 'Serves', 'doughboss' );
				$out['doughboss_cat_price']   = __( 'Price', 'doughboss' );
				$out['doughboss_cat_deposit'] = __( 'Deposit', 'doughboss' );
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
		switch ( $column ) {
			case 'doughboss_cat_serves':
				$min = (int) get_post_meta( $post_id, self::META_SERVES_MIN, true );
				$max = (int) get_post_meta( $post_id, self::META_SERVES_MAX, true );
				if ( $min && $max ) {
					/* translators: 1: minimum guests, 2: maximum guests. */
					echo esc_html( sprintf( __( '%1$d–%2$d', 'doughboss' ), $min, $max ) );
				} elseif ( $min ) {
					echo esc_html( $min );
				} else {
					echo '—';
				}
				break;
			case 'doughboss_cat_price':
				echo esc_html( DoughBoss_Settings::format_price( (float) get_post_meta( $post_id, self::META_BASE_PRICE, true ) ) );
				break;
			case 'doughboss_cat_deposit':
				echo esc_html( self::deposit_pct( $post_id ) . '%' );
				break;
		}
	}
}
