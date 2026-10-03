<?php
/**
 * Admin: one small settings screen (capability manage_options), the "Create draft page" button, the slot status
 * table and the copy approvals.
 *
 * @package DoughBoss_Growth_Box
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings screen and handlers.
 */
final class DoughBoss_Growth_Box_Admin {

	/**
	 * Admin page slug.
	 */
	const PAGE_SLUG = 'doughboss-growth-box';

	/**
	 * admin-post action and nonce action for saving the switches and approvals.
	 */
	const ACTION_SAVE = 'dbgrbox_save';

	/**
	 * admin-post action and nonce action for creating the draft page.
	 */
	const ACTION_CREATE = 'dbgrbox_create_page';

	/**
	 * Register the admin hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 30 );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_CREATE, array( __CLASS__, 'handle_create' ) );
	}

	/**
	 * Add the screen under the DoughBoss menu when it exists, otherwise under Settings.
	 *
	 * @return void
	 */
	public static function register_menu() {
		global $admin_page_hooks;
		$parent = ( is_array( $admin_page_hooks ) && isset( $admin_page_hooks['doughboss'] ) ) ? 'doughboss' : 'options-general.php';
		add_submenu_page(
			$parent,
			__( 'Catering box', 'doughboss-growth-box' ),
			__( 'Catering box', 'doughboss-growth-box' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * The settings page URL.
	 *
	 * @param array<string, string> $args Extra query args.
	 * @return string
	 */
	private static function page_url( array $args = array() ) {
		// admin.php?page=slug reaches a submenu page under any parent menu, and it works from admin-post.php where the
		// menus are not built yet.
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Deactivation: the story page goes back to draft so no raw shortcode text is shown.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$page_id = (int) get_option( DoughBoss_Growth_Box_Settings::PAGE_OPTION, 0 );
		if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
			wp_update_post(
				array(
					'ID'          => $page_id,
					'post_status' => 'draft',
				)
			);
		}
	}

	/**
	 * Create the draft story page. Pure of capability and nonce logic (the handler does those). Refuses when the page
	 * already exists, either by the stored id or by a page at the same path.
	 *
	 * @return array<string, mixed> Keys: result (created|exists|failed), id (int).
	 */
	public static function create_draft_page() {
		$stored = (int) get_option( DoughBoss_Growth_Box_Settings::PAGE_OPTION, 0 );
		if ( $stored > 0 ) {
			$status = get_post_status( $stored );
			if ( false !== $status && 'trash' !== $status ) {
				return array(
					'result' => 'exists',
					'id'     => $stored,
				);
			}
		}
		$parent_page = get_page_by_path( 'catering' );
		$parent_id   = ( $parent_page instanceof WP_Post ) ? (int) $parent_page->ID : 0;
		$path        = ( $parent_id > 0 ) ? 'catering/box' : 'box';
		if ( get_page_by_path( $path ) instanceof WP_Post ) {
			return array(
				'result' => 'exists',
				'id'     => 0,
			);
		}
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => DoughBoss_Growth_Box_Copy::text( 'headline' ) !== '' ? ucfirst( strtolower( DoughBoss_Growth_Box_Copy::text( 'headline' ) ) ) : 'Catering box',
				'post_name'    => 'box',
				'post_parent'  => $parent_id,
				'post_content' => '[doughboss_growth_box_story]',
			),
			true
		);
		if ( is_wp_error( $id ) || (int) $id < 1 ) {
			return array(
				'result' => 'failed',
				'id'     => 0,
			);
		}
		update_option( DoughBoss_Growth_Box_Settings::PAGE_OPTION, (int) $id, false );
		return array(
			'result' => 'created',
			'id'     => (int) $id,
		);
	}

	/**
	 * Handle the save form. Capability AND nonce first.
	 *
	 * @return void
	 */
	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss-growth-box' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION_SAVE );
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above by check_admin_referer().
		$user = wp_get_current_user();
		$new  = DoughBoss_Growth_Box_Settings::build(
			is_array( $post ) ? $post : array(),
			DoughBoss_Growth_Box_Settings::stored(),
			DoughBoss_Growth_Box_Copy::draft_ids(),
			( $user instanceof WP_User ) ? (string) $user->user_login : '',
			gmdate( 'Y-m-d' )
		);
		update_option( DoughBoss_Growth_Box_Settings::OPTION, $new, false );
		// A save is the owner's "look again": forget the cached hero media lookup (and any markup-failure marker).
		DoughBoss_Growth_Box_Hero_Media::flush();
		wp_safe_redirect( self::page_url( array( 'dbgrbox_msg' => 'saved' ) ) );
		exit;
	}

	/**
	 * Handle the Create draft page button. Capability AND nonce first.
	 *
	 * @return void
	 */
	public static function handle_create() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss-growth-box' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION_CREATE );
		$made = self::create_draft_page();
		$msg  = in_array( $made['result'], array( 'created', 'exists', 'failed' ), true ) ? $made['result'] : 'failed';
		wp_safe_redirect( self::page_url( array( 'dbgrbox_msg' => $msg ) ) );
		exit;
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss-growth-box' ), '', array( 'response' => 403 ) );
		}
		$messages = array(
			'saved'   => array( 'success', __( 'Settings saved.', 'doughboss-growth-box' ) ),
			'created' => array( 'success', __( 'Draft page created. Nothing is public until you publish it.', 'doughboss-growth-box' ) ),
			'exists'  => array( 'warning', __( 'The story page already exists, so no new page was made.', 'doughboss-growth-box' ) ),
			'failed'  => array( 'error', __( 'The page could not be created.', 'doughboss-growth-box' ) ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only message key from a fixed list.
		$msg_key = isset( $_GET['dbgrbox_msg'] ) ? sanitize_key( wp_unslash( $_GET['dbgrbox_msg'] ) ) : '';

		echo '<div class="wrap"><h1>' . esc_html__( 'Catering box', 'doughboss-growth-box' ) . '</h1>';
		if ( isset( $messages[ $msg_key ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $messages[ $msg_key ][0] ) . ' is-dismissible"><p>' . esc_html( $messages[ $msg_key ][1] ) . '</p></div>';
		}
		if ( DoughBoss_Growth_Box_Settings::disabled() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'DBGRBOX_DISABLE is set in wp-config.php, so everything below is stopped.', 'doughboss-growth-box' ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Every switch is off until you turn it on. Reload the public pages after each change.', 'doughboss-growth-box' ) . '</p>';

		self::render_form();
		self::render_page_box();
		self::render_slots();
		self::render_hero();
		echo '</div>';
	}

	/**
	 * The switches and approvals form.
	 *
	 * @return void
	 */
	private static function render_form() {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE ) . '">';
		wp_nonce_field( self::ACTION_SAVE );

		echo '<h2>' . esc_html__( 'Switches', 'doughboss-growth-box' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		foreach ( DoughBoss_Growth_Box_Settings::switches() as $key => $info ) {
			echo '<tr><th scope="row">' . esc_html( $info[0] ) . '</th><td><label><input type="checkbox" name="sw[' . esc_attr( $key ) . ']" value="1"' . checked( DoughBoss_Growth_Box_Settings::on( $key ), true, false ) . '> ' . esc_html__( 'On', 'doughboss-growth-box' ) . '</label><p class="description">' . esc_html( $info[1] ) . '</p></td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Copy', 'doughboss-growth-box' ) . '</h2>';
		echo '<p>' . esc_html__( 'Lines marked Verified are the owner\'s own published words and print as they are. Draft lines print nothing until you tick them.', 'doughboss-growth-box' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Line', 'doughboss-growth-box' ) . '</th><th>' . esc_html__( 'Status', 'doughboss-growth-box' ) . '</th><th>' . esc_html__( 'Source', 'doughboss-growth-box' ) . '</th></tr></thead><tbody>';
		foreach ( DoughBoss_Growth_Box_Copy::lines() as $id => $line ) {
			echo '<tr><td>' . esc_html( $line['text'] ) . '</td><td>';
			if ( 'A' === $line['tier'] ) {
				echo esc_html__( 'Verified', 'doughboss-growth-box' );
			} else {
				$approval = DoughBoss_Growth_Box_Settings::approval( $id );
				echo '<label><input type="checkbox" name="approve[' . esc_attr( $id ) . ']" value="1"' . checked( ! empty( $approval ), true, false ) . '> ' . esc_html__( 'Approved', 'doughboss-growth-box' ) . '</label>';
				if ( ! empty( $approval ) ) {
					/* translators: 1: approval date, 2: user login. */
					echo '<br><span class="description">' . esc_html( sprintf( __( 'Approved %1$s by %2$s', 'doughboss-growth-box' ), isset( $approval['date'] ) ? $approval['date'] : '', isset( $approval['by'] ) ? $approval['by'] : '' ) ) . '</span>';
				}
			}
			echo '</td><td>' . esc_html( $line['source'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'doughboss-growth-box' ) );
		echo '</form>';
	}

	/**
	 * The draft story page box.
	 *
	 * @return void
	 */
	private static function render_page_box() {
		echo '<h2>' . esc_html__( 'Story page', 'doughboss-growth-box' ) . '</h2>';
		$page_id = (int) get_option( DoughBoss_Growth_Box_Settings::PAGE_OPTION, 0 );
		$status  = ( $page_id > 0 ) ? get_post_status( $page_id ) : false;
		if ( false !== $status && 'trash' !== $status ) {
			$edit    = get_edit_post_link( $page_id );
			$preview = get_preview_post_link( $page_id );
			/* translators: %s: page status such as draft or publish. */
			echo '<p>' . esc_html( sprintf( __( 'The story page exists. Status: %s.', 'doughboss-growth-box' ), $status ) ) . '</p><p>';
			if ( $edit ) {
				echo '<a class="button" href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit page', 'doughboss-growth-box' ) . '</a> ';
			}
			if ( $preview ) {
				echo '<a class="button" href="' . esc_url( $preview ) . '">' . esc_html__( 'Preview', 'doughboss-growth-box' ) . '</a>';
			}
			echo '</p>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_CREATE ) . '">';
		wp_nonce_field( self::ACTION_CREATE );
		echo '<p>' . esc_html__( 'Creates a draft page that holds the story shortcode. It is a child of the Catering page when there is one.', 'doughboss-growth-box' ) . '</p>';
		submit_button( __( 'Create draft page', 'doughboss-growth-box' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * The image slot status table.
	 *
	 * @return void
	 */
	private static function render_slots() {
		echo '<h2>' . esc_html__( 'Image slots', 'doughboss-growth-box' ) . '</h2>';
		$ids = DoughBoss_Growth_Box_Manifest::slot_ids();
		if ( empty( $ids ) ) {
			echo '<p>' . esc_html__( 'No images found. Install and activate the DoughBoss Growth Media plugin. Every box section shows as text only until then.', 'doughboss-growth-box' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Slot', 'doughboss-growth-box' ) . '</th><th>' . esc_html__( 'Declared status', 'doughboss-growth-box' ) . '</th><th>' . esc_html__( 'Shows AI food', 'doughboss-growth-box' ) . '</th><th>' . esc_html__( 'Now', 'doughboss-growth-box' ) . '</th></tr></thead><tbody>';
		foreach ( $ids as $id ) {
			$r = DoughBoss_Growth_Box_Manifest::resolve( $id );
			echo '<tr><td>' . esc_html( $id ) . '</td><td>' . esc_html( $r['status'] ) . '</td><td>' . esc_html( $r['ai_food'] ? __( 'yes', 'doughboss-growth-box' ) : __( 'no', 'doughboss-growth-box' ) ) . '</td><td>' . esc_html( $r['state'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * The home hero video status: is it running, and which of the five videos and the posters were found.
	 *
	 * @return void
	 */
	private static function render_hero() {
		echo '<h2>' . esc_html__( 'Home hero video', 'doughboss-growth-box' ) . '</h2>';
		$found    = DoughBoss_Growth_Box_Hero_Media::find();
		$declared = DoughBoss_Growth_Box_Hero_Media::declared();
		$on       = DoughBoss_Growth_Box_Settings::on( 'home_hero_video' );
		$failed   = DoughBoss_Growth_Box_Hero_Media::markup_failed();
		$have     = ! empty( $found['videos'] );
		$poster   = ! empty( $found['posters']['webp-1080']['url'] );

		if ( DoughBoss_Growth_Box_Settings::disabled() ) {
			$state = __( 'Stopped by DBGRBOX_DISABLE in wp-config.php.', 'doughboss-growth-box' );
		} elseif ( ! $on ) {
			$state = __( 'Off. Tick "Home hero video" above and save to turn it on.', 'doughboss-growth-box' );
		} elseif ( $failed > 0 ) {
			$state = __( 'On, but not running: the home hero on this site is not drawn the way this plugin expects (a core update may have changed it), so it left the photo hero alone. Saving this screen tries again: save, load the home page once, then look here again.', 'doughboss-growth-box' );
		} elseif ( ! $poster ) {
			$state = __( 'On, but not running: the first-frame picture is missing. Install DoughBoss Growth Media 0.2.0.', 'doughboss-growth-box' );
		} elseif ( ! $have ) {
			$state = __( 'On, but not running: no hero video was found. Upload the MP4 files under Media, Add New.', 'doughboss-growth-box' );
		} else {
			$state = __( 'Running on the home page hero.', 'doughboss-growth-box' );
		}
		echo '<p><strong>' . esc_html__( 'Now:', 'doughboss-growth-box' ) . '</strong> ' . esc_html( $state ) . '</p>';

		if ( ! empty( $declared ) ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: declared status such as concept, 2: yes or no. */
					__( 'Declared by the media plugin: %1$s. Shows AI food: %2$s. The label above controls the "Concept preview" tag. The home page is not set to noindex.', 'doughboss-growth-box' ),
					(string) $declared['status'],
					! empty( $declared['ai_food'] ) ? __( 'yes', 'doughboss-growth-box' ) : __( 'no', 'doughboss-growth-box' )
				)
			) . '</p>';
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'File', 'doughboss-growth-box' ) . '</th><th>' . esc_html__( 'Found', 'doughboss-growth-box' ) . '</th><th>' . esc_html__( 'Where', 'doughboss-growth-box' ) . '</th><th>' . esc_html__( 'Size', 'doughboss-growth-box' ) . '</th></tr></thead><tbody>';
		foreach ( DoughBoss_Growth_Box_Hero_Media::video_stems() as $key => $stem ) {
			self::hero_row( $stem . '.mp4', isset( $found['videos'][ $key ] ) ? $found['videos'][ $key ] : null, false );
		}
		foreach ( DoughBoss_Growth_Box_Hero_Media::poster_files() as $key => $info ) {
			self::hero_row( $info[0], isset( $found['posters'][ $key ] ) ? $found['posters'][ $key ] : null, ! empty( $info[1] ) );
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Videos are found by file name in the media plugin folder first, then in the Media Library (a repeat upload that WordPress renamed with -1 or -2 still counts). The video runs when the first-frame picture and at least one video are found.', 'doughboss-growth-box' ) . '</p>';
	}

	/**
	 * One row of the hero media table.
	 *
	 * @param string                    $name     File name.
	 * @param array<string, mixed>|null $item     Found item or null.
	 * @param bool                      $required Whether the file is required for the video to run.
	 * @return void
	 */
	private static function hero_row( $name, $item, $required ) {
		$where = '';
		$size  = '';
		if ( is_array( $item ) ) {
			if ( 'plugin' === $item['source'] ) {
				$where = __( 'Media plugin folder', 'doughboss-growth-box' );
			} else {
				/* translators: %d: Media Library attachment id. */
				$where = sprintf( __( 'Media Library (item %d)', 'doughboss-growth-box' ), (int) $item['id'] );
			}
			$size = ( (int) $item['bytes'] > 0 ) ? size_format( (int) $item['bytes'], 1 ) : '';
		}
		$label = is_array( $item ) ? __( 'yes', 'doughboss-growth-box' ) : ( $required ? __( 'missing (needed)', 'doughboss-growth-box' ) : __( 'not found', 'doughboss-growth-box' ) );
		echo '<tr><td><code>' . esc_html( $name ) . '</code></td><td>' . esc_html( $label ) . '</td><td>' . esc_html( $where ) . '</td><td>' . esc_html( $size ) . '</td></tr>';
	}
}
