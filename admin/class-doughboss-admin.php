<?php
/**
 * Admin screens: orders management and settings.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires up the wp-admin experience for DoughBoss.
 */
class DoughBoss_Admin {

	const SETTINGS_GROUP = 'doughboss_settings_group';
	const CAP            = 'manage_doughboss';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_doughboss_save_location', array( $this, 'handle_save_location' ) );
		add_action( 'admin_post_doughboss_delete_location', array( $this, 'handle_delete_location' ) );
		add_action( 'admin_post_doughboss_issue_voucher', array( $this, 'handle_issue_voucher' ) );
		add_action( 'admin_post_doughboss_void_voucher', array( $this, 'handle_void_voucher' ) );
	}

	/**
	 * The capability required for management screens.
	 *
	 * @return string
	 */
	private function cap() {
		return current_user_can( self::CAP ) ? self::CAP : 'manage_options';
	}

	/**
	 * Register the top-level menu and sub-pages. The Menu Items CPT and its
	 * category taxonomy attach automatically via `show_in_menu`.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'DoughBoss', 'doughboss' ),
			__( 'DoughBoss', 'doughboss' ),
			$this->cap(),
			'doughboss',
			array( $this, 'render_orders_page' ),
			'dashicons-food',
			26
		);

		add_submenu_page(
			'doughboss',
			__( 'Orders', 'doughboss' ),
			__( 'Orders', 'doughboss' ),
			$this->cap(),
			'doughboss',
			array( $this, 'render_orders_page' )
		);

		add_submenu_page(
			'doughboss',
			__( 'Catering Enquiries', 'doughboss' ),
			__( 'Catering', 'doughboss' ),
			$this->cap(),
			'doughboss-catering',
			array( $this, 'render_catering_page' )
		);

		add_submenu_page(
			'doughboss',
			__( 'Shops / Locations', 'doughboss' ),
			__( 'Shops', 'doughboss' ),
			$this->cap(),
			'doughboss-locations',
			array( $this, 'render_locations_page' )
		);

		add_submenu_page(
			'doughboss',
			__( 'Vouchers', 'doughboss' ),
			__( 'Vouchers', 'doughboss' ),
			$this->cap(),
			'doughboss-vouchers',
			array( $this, 'render_vouchers_page' )
		);

		add_submenu_page(
			'doughboss',
			__( 'DoughBoss Settings', 'doughboss' ),
			__( 'Settings', 'doughboss' ),
			$this->cap(),
			'doughboss-settings',
			array( $this, 'render_settings_page' )
		);

		// Standalone, tablet-friendly live order board. Registered with the
		// kitchen capability so a low-privilege "DoughBoss Kitchen" user can
		// reach it without a full admin login on a shop device.
		add_menu_page(
			__( 'Order Board', 'doughboss' ),
			__( 'Order Board', 'doughboss' ),
			'manage_doughboss_kds',
			'doughboss-board',
			array( $this, 'render_board_page' ),
			'dashicons-screenoptions',
			27
		);
	}

	/**
	 * Register the settings option with a sanitizing callback.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			DoughBoss_Settings::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Sanitize the entire settings payload coming from the settings form.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();
		$clean = array();

		$clean['currency_symbol'] = isset( $input['currency_symbol'] ) ? sanitize_text_field( $input['currency_symbol'] ) : '$';
		$clean['currency_code']   = isset( $input['currency_code'] ) ? sanitize_text_field( $input['currency_code'] ) : 'USD';
		$clean['tax_rate']        = isset( $input['tax_rate'] ) ? max( 0, (float) $input['tax_rate'] ) : 0;
		$clean['gst_inclusive']   = empty( $input['gst_inclusive'] ) ? 0 : 1;
		$clean['delivery_fee']    = isset( $input['delivery_fee'] ) ? max( 0, (float) $input['delivery_fee'] ) : 0;
		$clean['enable_pickup']   = empty( $input['enable_pickup'] ) ? 0 : 1;
		$clean['enable_delivery'] = empty( $input['enable_delivery'] ) ? 0 : 1;
		$clean['ordering_open']   = empty( $input['ordering_open'] ) ? 0 : 1;

		// Payments (Stripe). Keys are stored for the active mode; secret keys are
		// only ever used in server-side calls and are never sent to the browser.
		$clean['payments_enabled'] = empty( $input['payments_enabled'] ) ? 0 : 1;
		$clean['stripe_mode']      = ( isset( $input['stripe_mode'] ) && 'live' === $input['stripe_mode'] ) ? 'live' : 'test';
		$clean['stripe_test_pk']   = isset( $input['stripe_test_pk'] ) ? sanitize_text_field( $input['stripe_test_pk'] ) : '';
		$clean['stripe_test_sk']   = isset( $input['stripe_test_sk'] ) ? sanitize_text_field( $input['stripe_test_sk'] ) : '';
		$clean['stripe_live_pk']   = isset( $input['stripe_live_pk'] ) ? sanitize_text_field( $input['stripe_live_pk'] ) : '';
		$clean['stripe_live_sk']   = isset( $input['stripe_live_sk'] ) ? sanitize_text_field( $input['stripe_live_sk'] ) : '';
		$clean['stripe_test_whsec'] = isset( $input['stripe_test_whsec'] ) ? sanitize_text_field( $input['stripe_test_whsec'] ) : '';
		$clean['stripe_live_whsec'] = isset( $input['stripe_live_whsec'] ) ? sanitize_text_field( $input['stripe_live_whsec'] ) : '';

		$clean['sizes']    = $this->sanitize_rows( isset( $input['sizes'] ) ? $input['sizes'] : array() );
		$clean['toppings'] = $this->sanitize_rows( isset( $input['toppings'] ) ? $input['toppings'] : array() );

		return $clean;
	}

	/**
	 * Sanitize a repeatable list of {label, price} rows into {slug,label,price}.
	 *
	 * @param mixed $rows Raw rows.
	 * @return array[]
	 */
	private function sanitize_rows( $rows ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$clean = array();
		$seen  = array();

		foreach ( $rows as $row ) {
			if ( empty( $row['label'] ) ) {
				continue;
			}
			$label = sanitize_text_field( $row['label'] );
			$slug  = sanitize_title( $label );
			if ( '' === $slug || isset( $seen[ $slug ] ) ) {
				$slug = $slug ? $slug . '-' . wp_rand( 100, 999 ) : 'item-' . wp_rand( 100, 999 );
			}
			$seen[ $slug ] = true;

			$clean[] = array(
				'slug'  => $slug,
				'label' => $label,
				'price' => isset( $row['price'] ) ? max( 0, round( (float) $row['price'], 2 ) ) : 0,
			);
		}

		return $clean;
	}

	/**
	 * Enqueue admin assets on our screens only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( $hook, 'doughboss' ) ) {
			return;
		}

		wp_enqueue_style(
			'doughboss-admin',
			DOUGHBOSS_PLUGIN_URL . 'public/css/doughboss-admin.css',
			array(),
			DOUGHBOSS_VERSION
		);

		// Small inline script for live status changes & settings repeaters.
		wp_register_script( 'doughboss-admin', false, array(), DOUGHBOSS_VERSION, true );
		wp_enqueue_script( 'doughboss-admin' );
		wp_localize_script(
			'doughboss-admin',
			'DoughBossAdmin',
			array(
				'restUrl' => esc_url_raw( rest_url( DOUGHBOSS_REST_NAMESPACE ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
		wp_add_inline_script( 'doughboss-admin', $this->inline_admin_js() );

		// The live order board ships its own (larger) app + styles, loaded only
		// on its screen.
		if ( false !== strpos( $hook, 'doughboss-board' ) ) {
			wp_enqueue_style(
				'doughboss-orderboard',
				DOUGHBOSS_PLUGIN_URL . 'public/css/doughboss-orderboard.css',
				array(),
				DOUGHBOSS_VERSION
			);
			wp_enqueue_script(
				'doughboss-orderboard',
				DOUGHBOSS_PLUGIN_URL . 'public/js/doughboss-orderboard.js',
				array(),
				DOUGHBOSS_VERSION,
				true
			);
			wp_localize_script(
				'doughboss-orderboard',
				'DoughBossBoard',
				array(
					'restUrl'   => esc_url_raw( rest_url( DOUGHBOSS_REST_NAMESPACE ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'currency'  => DoughBoss_Settings::get( 'currency_symbol', '$' ),
					'pollMs'    => 7000,
					'statuses'  => DoughBoss_Order::statuses(),
					'locations' => $this->board_locations(),
				)
			);
		}
	}

	/**
	 * Compact list of shops for the board's shop filter.
	 *
	 * @return array<int,array{id:int,name:string}>
	 */
	private function board_locations() {
		$out = array();
		foreach ( DoughBoss_Locations::all() as $loc ) {
			$out[] = array( 'id' => (int) $loc->id, 'name' => $loc->name );
		}
		return $out;
	}

	/**
	 * Inline JS powering the orders status dropdowns and settings repeaters.
	 *
	 * @return string
	 */
	private function inline_admin_js() {
		return <<<'JS'
(function () {
	document.addEventListener('change', function (e) {
		var sel = e.target;
		var isOrder = sel.matches('.db-status-select');
		var isCatering = sel.matches('.db-catering-status');
		if (!isOrder && !isCatering) { return; }
		var id = sel.getAttribute(isOrder ? 'data-order' : 'data-enquiry');
		var endpoint = isOrder
			? DoughBossAdmin.restUrl + '/admin/order/' + id + '/status'
			: DoughBossAdmin.restUrl + '/admin/catering/' + id + '/status';
		sel.disabled = true;
		fetch(endpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': DoughBossAdmin.nonce },
			body: JSON.stringify({ status: sel.value })
		}).then(function (r) { return r.json(); }).then(function () {
			sel.disabled = false;
			var row = sel.closest('tr');
			if (row) { row.style.transition = 'background .6s'; row.style.background = '#eaffea'; setTimeout(function(){ row.style.background=''; }, 800); }
		}).catch(function () { sel.disabled = false; alert('Could not update status.'); });
	});

	document.addEventListener('click', function (e) {
		var addBtn = e.target.closest('.db-add-row');
		if (addBtn) {
			e.preventDefault();
			var body = document.querySelector('#' + addBtn.getAttribute('data-target') + ' tbody');
			var tpl = body.querySelector('tr');
			var clone = tpl.cloneNode(true);
			clone.querySelectorAll('input').forEach(function (i) { i.value = ''; });
			body.appendChild(clone);
			return;
		}
		var removeBtn = e.target.closest('.db-remove-row');
		if (removeBtn) {
			e.preventDefault();
			var tr = removeBtn.closest('tr');
			var tbody = tr.parentNode;
			if (tbody.querySelectorAll('tr').length > 1) { tr.remove(); }
			else { tr.querySelectorAll('input').forEach(function (i) { i.value = ''; }); }
		}
	});
}());
JS;
	}

	/**
	 * Render the Orders management page.
	 *
	 * @return void
	 */
	public function render_orders_page() {
		if ( ! current_user_can( $this->cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}

		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$per_page = 20;
		$result   = DoughBoss_Order::query(
			array(
				'status'   => $status,
				'search'   => $search,
				'per_page' => $per_page,
				'page'     => $paged,
			)
		);

		$statuses    = DoughBoss_Order::statuses();
		$total_pages = (int) ceil( $result['total'] / $per_page );
		?>
		<div class="wrap doughboss-orders">
			<h1><?php esc_html_e( 'Orders', 'doughboss' ); ?></h1>

			<form method="get" class="db-orders-filter">
				<input type="hidden" name="page" value="doughboss" />
				<select name="status">
					<option value=""><?php esc_html_e( 'All statuses', 'doughboss' ); ?></option>
					<?php foreach ( $statuses as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search orders…', 'doughboss' ); ?>" />
				<button class="button"><?php esc_html_e( 'Filter', 'doughboss' ); ?></button>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Order #', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Type', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Items', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Total', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Placed', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Status', 'doughboss' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $result['items'] ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No orders yet.', 'doughboss' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $result['items'] as $order ) : ?>
							<?php $items = DoughBoss_Order::get_items( $order->id ); ?>
							<tr>
								<td><strong><?php echo esc_html( $order->order_number ); ?></strong></td>
								<td>
									<?php echo esc_html( $order->customer_name ); ?><br />
									<small><?php echo esc_html( $order->customer_email ); ?></small><br />
									<small><?php echo esc_html( $order->customer_phone ); ?></small>
								</td>
								<td><?php echo esc_html( ucfirst( $order->order_type ) ); ?></td>
								<td>
									<ul class="db-item-list">
										<?php foreach ( $items as $item ) : ?>
											<li>
												<?php echo esc_html( $item['quantity'] . '× ' . $item['name'] ); ?>
												<?php if ( ! empty( $item['toppings'] ) ) : ?>
													<small>(<?php echo esc_html( implode( ', ', wp_list_pluck( $item['toppings'], 'label' ) ) ); ?>)</small>
												<?php endif; ?>
											</li>
										<?php endforeach; ?>
									</ul>
								</td>
								<td><?php echo esc_html( DoughBoss_Settings::format_price( $order->total ) ); ?></td>
								<td><?php echo esc_html( mysql2date( 'M j, g:i a', $order->created_at ) ); ?></td>
								<td>
									<select class="db-status-select" data-order="<?php echo esc_attr( $order->id ); ?>">
										<?php foreach ( $statuses as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $order->status, $key ); ?>>
												<?php echo esc_html( $label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $total_pages,
								'prev_text' => '‹',
								'next_text' => '›',
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Catering Enquiries management page.
	 *
	 * @return void
	 */
	public function render_catering_page() {
		if ( ! current_user_can( $this->cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}

		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$per_page = 20;
		$result   = DoughBoss_Catering::query(
			array(
				'status'   => $status,
				'search'   => $search,
				'per_page' => $per_page,
				'page'     => $paged,
			)
		);

		$statuses    = DoughBoss_Catering::statuses();
		$total_pages = (int) ceil( $result['total'] / $per_page );
		?>
		<div class="wrap doughboss-orders">
			<h1><?php esc_html_e( 'Catering Enquiries', 'doughboss' ); ?></h1>

			<form method="get" class="db-orders-filter">
				<input type="hidden" name="page" value="doughboss-catering" />
				<select name="status">
					<option value=""><?php esc_html_e( 'All statuses', 'doughboss' ); ?></option>
					<?php foreach ( $statuses as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search enquiries…', 'doughboss' ); ?>" />
				<button class="button"><?php esc_html_e( 'Filter', 'doughboss' ); ?></button>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Enquiry #', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Event', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Package', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Guests', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Quote / Deposit', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Received', 'doughboss' ); ?></th>
						<th><?php esc_html_e( 'Status', 'doughboss' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $result['items'] ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'No catering enquiries yet.', 'doughboss' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $result['items'] as $row ) : ?>
							<?php
							$package = (int) $row['package_id'] ? get_the_title( (int) $row['package_id'] ) : __( 'Custom', 'doughboss' );
							$event   = '';
							if ( ! empty( $row['event_date'] ) ) {
								$event = mysql2date( 'M j, Y', $row['event_date'] );
								if ( ! empty( $row['event_time'] ) ) {
									$event .= ' · ' . $row['event_time'];
								}
							}
							?>
							<tr>
								<td><strong><?php echo esc_html( $row['enquiry_number'] ); ?></strong><br /><small><?php echo esc_html( ucfirst( $row['order_type'] ) ); ?></small></td>
								<td>
									<?php echo esc_html( $row['customer_name'] ); ?><br />
									<small><?php echo esc_html( $row['customer_email'] ); ?></small><br />
									<small><?php echo esc_html( $row['customer_phone'] ); ?></small>
								</td>
								<td><?php echo $event ? esc_html( $event ) : '—'; ?></td>
								<td><?php echo esc_html( $package ? $package : '—' ); ?></td>
								<td><?php echo esc_html( (int) $row['guest_count'] ? (string) (int) $row['guest_count'] : '—' ); ?></td>
								<td>
									<?php echo esc_html( DoughBoss_Settings::format_price( $row['quote_total'] ) ); ?><br />
									<small><?php echo esc_html( DoughBoss_Settings::format_price( $row['deposit_amount'] ) ); ?> <?php esc_html_e( 'deposit', 'doughboss' ); ?></small>
								</td>
								<td><?php echo esc_html( mysql2date( 'M j, g:i a', $row['created_at'] ) ); ?></td>
								<td>
									<select class="db-catering-status" data-enquiry="<?php echo esc_attr( $row['id'] ); ?>">
										<?php foreach ( $statuses as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $row['status'], $key ); ?>>
												<?php echo esc_html( $label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $total_pages,
								'prev_text' => '‹',
								'next_text' => '›',
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the live Order Board screen. The board app (JS) fills #db-board.
	 *
	 * @return void
	 */
	public function render_board_page() {
		if ( ! current_user_can( 'manage_doughboss_kds' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}
		?>
		<div class="wrap doughboss-board-wrap">
			<div class="db-board-bar">
				<h1><?php esc_html_e( 'Live Order Board', 'doughboss' ); ?></h1>
				<div class="db-board-actions">
					<span class="db-board-status" role="status" aria-live="polite"></span>
					<button type="button" class="button db-sound-toggle" aria-pressed="false">
						<?php esc_html_e( '🔔 Enable sound alerts', 'doughboss' ); ?>
					</button>
				</div>
			</div>
			<div id="db-board" class="db-board" aria-live="polite">
				<p class="db-board-loading"><?php esc_html_e( 'Loading orders…', 'doughboss' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Shops / Locations management screen (list + add/edit form).
	 *
	 * @return void
	 */
	public function render_locations_page() {
		if ( ! current_user_can( $this->cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$editing = $edit_id ? DoughBoss_Locations::get( $edit_id ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg     = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';

		$f = function ( $key, $default = '' ) use ( $editing ) {
			return $editing && isset( $editing->$key ) ? $editing->$key : $default;
		};
		?>
		<div class="wrap doughboss-locations">
			<h1><?php esc_html_e( 'Shops / Locations', 'doughboss' ); ?></h1>
			<?php if ( 'saved' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Shop saved.', 'doughboss' ); ?></p></div>
			<?php elseif ( 'deleted' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Shop deleted.', 'doughboss' ); ?></p></div>
			<?php endif; ?>

			<table class="wp-list-table widefat fixed striped" style="margin-bottom:1.5rem;">
				<thead><tr>
					<th><?php esc_html_e( 'Shop', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Suburb', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Fulfilment', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Active', 'doughboss' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
					<?php $rows = DoughBoss_Locations::all(); ?>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No shops yet. Add your first one below.', 'doughboss' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $loc ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $loc->name ); ?></strong><br /><small><?php echo esc_html( $loc->phone ); ?></small></td>
								<td><?php echo esc_html( $loc->suburb ); ?></td>
								<td>
									<?php
									$ful = array();
									if ( $loc->pickup_enabled ) {
										$ful[] = __( 'Pickup', 'doughboss' );
									}
									if ( $loc->delivery_enabled ) {
										$ful[] = __( 'Delivery', 'doughboss' );
									}
									echo esc_html( $ful ? implode( ' + ', $ful ) : '—' );
									?>
								</td>
								<td><?php echo $loc->is_active ? '✓' : '—'; ?></td>
								<td>
									<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'doughboss-locations', 'edit' => $loc->id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'doughboss' ); ?></a> |
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=doughboss_delete_location&id=' . $loc->id ), 'doughboss_delete_location_' . $loc->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this shop?', 'doughboss' ) ); ?>');" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'doughboss' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php echo $editing ? esc_html__( 'Edit shop', 'doughboss' ) : esc_html__( 'Add a shop', 'doughboss' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="doughboss_save_location" />
				<input type="hidden" name="id" value="<?php echo esc_attr( (int) $f( 'id', 0 ) ); ?>" />
				<?php wp_nonce_field( 'doughboss_save_location' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="db-loc-name"><?php esc_html_e( 'Name', 'doughboss' ); ?></label></th>
						<td><input name="name" id="db-loc-name" type="text" class="regular-text" required value="<?php echo esc_attr( $f( 'name' ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="db-loc-suburb"><?php esc_html_e( 'Suburb', 'doughboss' ); ?></label></th>
						<td><input name="suburb" id="db-loc-suburb" type="text" class="regular-text" value="<?php echo esc_attr( $f( 'suburb' ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="db-loc-address"><?php esc_html_e( 'Address', 'doughboss' ); ?></label></th>
						<td><textarea name="address" id="db-loc-address" class="large-text" rows="2"><?php echo esc_textarea( $f( 'address' ) ); ?></textarea></td>
					</tr>
					<tr>
						<th><label for="db-loc-phone"><?php esc_html_e( 'Phone', 'doughboss' ); ?></label></th>
						<td><input name="phone" id="db-loc-phone" type="text" class="regular-text" value="<?php echo esc_attr( $f( 'phone' ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="db-loc-postcodes"><?php esc_html_e( 'Delivery postcodes', 'doughboss' ); ?></label></th>
						<td><input name="postcodes" id="db-loc-postcodes" type="text" class="regular-text" value="<?php echo esc_attr( $f( 'postcodes' ) ); ?>" /><p class="description"><?php esc_html_e( 'Comma-separated, used to route delivery orders to this shop.', 'doughboss' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="db-loc-prep"><?php esc_html_e( 'Default prep time (min)', 'doughboss' ); ?></label></th>
						<td><input name="prep_time_default" id="db-loc-prep" type="number" min="0" class="small-text" value="<?php echo esc_attr( $f( 'prep_time_default', 20 ) ); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Fulfilment', 'doughboss' ); ?></th>
						<td>
							<label><input type="checkbox" name="pickup_enabled" value="1" <?php checked( $editing ? $editing->pickup_enabled : 1, 1 ); ?> /> <?php esc_html_e( 'Pickup', 'doughboss' ); ?></label><br />
							<label><input type="checkbox" name="delivery_enabled" value="1" <?php checked( $editing ? $editing->delivery_enabled : 0, 1 ); ?> /> <?php esc_html_e( 'Delivery', 'doughboss' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Active', 'doughboss' ); ?></th>
						<td><label><input type="checkbox" name="is_active" value="1" <?php checked( $editing ? $editing->is_active : 1, 1 ); ?> /> <?php esc_html_e( 'Accept orders for this shop', 'doughboss' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button( $editing ? __( 'Update shop', 'doughboss' ) : __( 'Add shop', 'doughboss' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the add/edit shop form submission.
	 *
	 * @return void
	 */
	public function handle_save_location() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_save_location' );

		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$data = array(
			'name'              => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
			'suburb'            => isset( $_POST['suburb'] ) ? wp_unslash( $_POST['suburb'] ) : '',
			'address'           => isset( $_POST['address'] ) ? wp_unslash( $_POST['address'] ) : '',
			'phone'             => isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '',
			'postcodes'         => isset( $_POST['postcodes'] ) ? wp_unslash( $_POST['postcodes'] ) : '',
			'prep_time_default' => isset( $_POST['prep_time_default'] ) ? (int) $_POST['prep_time_default'] : 20,
			'pickup_enabled'    => isset( $_POST['pickup_enabled'] ) ? 1 : 0,
			'delivery_enabled'  => isset( $_POST['delivery_enabled'] ) ? 1 : 0,
			'is_active'         => isset( $_POST['is_active'] ) ? 1 : 0,
		);

		if ( $id ) {
			DoughBoss_Locations::update( $id, $data );
		} else {
			DoughBoss_Locations::create( $data );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'doughboss-locations', 'msg' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle deleting a shop.
	 *
	 * @return void
	 */
	public function handle_delete_location() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_delete_location_' . $id );

		if ( $id ) {
			DoughBoss_Locations::delete( $id );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'doughboss-locations', 'msg' => 'deleted' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the Vouchers management screen: daily-campaign tracking, a create
	 * form, and a recent-vouchers list with redemption status + void.
	 *
	 * @return void
	 */
	public function render_vouchers_page() {
		if ( ! current_user_can( $this->cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg       = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$new_code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$campaigns = DoughBoss_Voucher::campaigns();
		$vouchers  = DoughBoss_Voucher::query( 100 );
		?>
		<div class="wrap doughboss-vouchers">
			<h1><?php esc_html_e( 'Vouchers', 'doughboss' ); ?></h1>
			<?php if ( 'issued' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Voucher created:', 'doughboss' ); ?> <code><?php echo esc_html( $new_code ); ?></code></p></div>
			<?php elseif ( 'voided' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Voucher voided.', 'doughboss' ); ?></p></div>
			<?php elseif ( 'error' === $msg ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not create the voucher — check the value and try again.', 'doughboss' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Daily campaigns', 'doughboss' ); ?></h2>
			<table class="wp-list-table widefat fixed striped" style="margin-bottom:1.5rem;max-width:820px;">
				<thead><tr>
					<th><?php esc_html_e( 'Campaign', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Value', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Daily cap', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Claimed today', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Remaining', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Active', 'doughboss' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $campaigns as $c ) : ?>
					<?php
					$cap       = (int) ( isset( $c['daily_cap'] ) ? $c['daily_cap'] : 0 );
					$shared    = ! empty( $c['cap_group'] );
					$claimed   = DoughBoss_Voucher::claimed_today( $c['slug'] );
					$pool_used = DoughBoss_Voucher::claimed_today_for( $c );
					$remaining = $cap > 0 ? (string) max( 0, $cap - $pool_used ) : '∞';
					$cap_label = $cap > 0 ? (string) $cap . ( $shared ? ' ' . __( '(shared)', 'doughboss' ) : '' ) : '∞';
					?>
					<tr>
						<td><strong><?php echo esc_html( $c['label'] ); ?></strong><br /><small><?php echo esc_html( $c['slug'] ); ?></small></td>
						<td><?php echo esc_html( 'percent' === $c['type'] ? $c['value'] . '%' : DoughBoss_Settings::format_price( $c['value'] ) ); ?></td>
						<td><?php echo esc_html( $cap_label ); ?></td>
						<td><?php echo esc_html( (string) $claimed ); ?></td>
						<td><?php echo esc_html( $remaining ); ?></td>
						<td><?php echo empty( $c['active'] ) ? '—' : '✓'; ?></td>
					</tr>
				<?php endforeach; ?>
				<?php
				// When campaigns share a daily pool, show the combined total so the
				// "Remaining" columns (which all show the same shared figure) read clearly.
				$groups = array();
				foreach ( $campaigns as $c ) {
					if ( ! empty( $c['cap_group'] ) ) {
						$groups[ $c['cap_group'] ] = (int) ( isset( $c['daily_cap'] ) ? $c['daily_cap'] : 0 );
					}
				}
				foreach ( $groups as $g => $g_cap ) {
					$g_used = DoughBoss_Voucher::claimed_today_for( array( 'cap_group' => $g ) );
					?>
					<tr style="background:#f6f7f7;">
						<td colspan="3"><em><?php printf( esc_html__( 'Shared daily pool (%s)', 'doughboss' ), esc_html( $g ) ); ?></em></td>
						<td><?php echo esc_html( (string) $g_used ); ?></td>
						<td><?php echo esc_html( $g_cap > 0 ? (string) max( 0, $g_cap - $g_used ) : '∞' ); ?></td>
						<td>—</td>
					</tr>
					<?php
				}
				?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Create a voucher', 'doughboss' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="doughboss_issue_voucher" />
				<?php wp_nonce_field( 'doughboss_issue_voucher' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="db-v-type"><?php esc_html_e( 'Type', 'doughboss' ); ?></label></th>
						<td><select name="type" id="db-v-type">
							<option value="amount"><?php esc_html_e( 'Amount off ($)', 'doughboss' ); ?></option>
							<option value="percent"><?php esc_html_e( 'Percent off (%)', 'doughboss' ); ?></option>
						</select></td>
					</tr>
					<tr>
						<th><label for="db-v-value"><?php esc_html_e( 'Value', 'doughboss' ); ?></label></th>
						<td><input name="value" id="db-v-value" type="number" step="0.01" min="0" class="small-text" required /></td>
					</tr>
					<tr>
						<th><label for="db-v-prefix"><?php esc_html_e( 'Code prefix', 'doughboss' ); ?></label></th>
						<td><input name="prefix" id="db-v-prefix" type="text" class="regular-text" value="SNOW" /></td>
					</tr>
					<tr>
						<th><label for="db-v-min"><?php esc_html_e( 'Minimum spend', 'doughboss' ); ?></label></th>
						<td><input name="min_spend" id="db-v-min" type="number" step="0.01" min="0" class="small-text" value="0" /></td>
					</tr>
					<tr>
						<th><label for="db-v-scope"><?php esc_html_e( 'Where', 'doughboss' ); ?></label></th>
						<td><select name="scope" id="db-v-scope">
							<option value="both"><?php esc_html_e( 'Online & in-store', 'doughboss' ); ?></option>
							<option value="online"><?php esc_html_e( 'Online only', 'doughboss' ); ?></option>
							<option value="instore"><?php esc_html_e( 'In-store only', 'doughboss' ); ?></option>
						</select></td>
					</tr>
					<tr>
						<th><label for="db-v-to"><?php esc_html_e( 'Valid until', 'doughboss' ); ?></label></th>
						<td><input name="valid_to" id="db-v-to" type="date" /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Create voucher', 'doughboss' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Recent vouchers', 'doughboss' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr>
					<th><?php esc_html_e( 'Code', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Discount', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Campaign', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Status', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Created', 'doughboss' ); ?></th>
					<th><?php esc_html_e( 'Redeemed', 'doughboss' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $vouchers ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No vouchers yet. Create one above, or they appear here as customers claim them.', 'doughboss' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $vouchers as $v ) : ?>
						<tr>
							<td><code><?php echo esc_html( $v->code ); ?></code></td>
							<td><?php echo esc_html( 'percent' === $v->type ? $v->value . '%' : DoughBoss_Settings::format_price( $v->value ) ); ?></td>
							<td><?php echo esc_html( $v->campaign ? $v->campaign : '—' ); ?></td>
							<td><?php echo esc_html( ucfirst( $v->status ) ); ?></td>
							<td><?php echo esc_html( $v->customer_phone ? $v->customer_phone : ( $v->customer_email ? $v->customer_email : '—' ) ); ?></td>
							<td><?php echo esc_html( mysql2date( 'j M, g:ia', $v->created_at ) ); ?></td>
							<td><?php echo $v->redeemed_at ? esc_html( mysql2date( 'j M, g:ia', $v->redeemed_at ) . ' · ' . $v->redeemed_channel ) : '—'; ?></td>
							<td>
								<?php if ( 'issued' === $v->status ) : ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=doughboss_void_voucher&id=' . $v->id ), 'doughboss_void_voucher_' . $v->id ) ); ?>" style="color:#b32d2e;" onclick="return confirm('<?php echo esc_js( __( 'Void this voucher?', 'doughboss' ) ); ?>');"><?php esc_html_e( 'Void', 'doughboss' ); ?></a>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Handle the create-voucher form submission.
	 *
	 * @return void
	 */
	public function handle_issue_voucher() {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_issue_voucher' );

		$result = DoughBoss_Voucher::issue(
			array(
				'type'      => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'amount',
				'value'     => isset( $_POST['value'] ) ? (float) wp_unslash( $_POST['value'] ) : 0,
				'prefix'    => isset( $_POST['prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['prefix'] ) ) : 'DB',
				'min_spend' => isset( $_POST['min_spend'] ) ? (float) wp_unslash( $_POST['min_spend'] ) : 0,
				'scope'     => isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'both',
				'valid_to'  => ( isset( $_POST['valid_to'] ) && '' !== $_POST['valid_to'] ) ? sanitize_text_field( wp_unslash( $_POST['valid_to'] ) ) . ' 23:59:59' : '',
			)
		);

		$args = array( 'page' => 'doughboss-vouchers' );
		if ( is_wp_error( $result ) ) {
			$args['msg'] = 'error';
		} else {
			$args['msg']  = 'issued';
			$args['code'] = $result['code'];
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle voiding an unredeemed voucher.
	 *
	 * @return void
	 */
	public function handle_void_voucher() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'doughboss' ) );
		}
		check_admin_referer( 'doughboss_void_voucher_' . $id );
		if ( $id ) {
			DoughBoss_Voucher::void( $id );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'doughboss-vouchers', 'msg' => 'voided' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the Settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( $this->cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'doughboss' ) );
		}

		$settings = DoughBoss_Settings::all();
		?>
		<div class="wrap doughboss-settings">
			<h1><?php esc_html_e( 'DoughBoss Settings', 'doughboss' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::SETTINGS_GROUP ); ?>
				<?php $opt = DoughBoss_Settings::OPTION_KEY; ?>

				<h2><?php esc_html_e( 'Store', 'doughboss' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="db-ordering-open"><?php esc_html_e( 'Accept orders', 'doughboss' ); ?></label></th>
						<td><input type="checkbox" id="db-ordering-open" name="<?php echo esc_attr( $opt ); ?>[ordering_open]" value="1" <?php checked( $settings['ordering_open'], 1 ); ?> />
							<span class="description"><?php esc_html_e( 'Uncheck to temporarily pause online ordering.', 'doughboss' ); ?></span></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Fulfilment', 'doughboss' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enable_pickup]" value="1" <?php checked( $settings['enable_pickup'], 1 ); ?> /> <?php esc_html_e( 'Pickup', 'doughboss' ); ?></label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enable_delivery]" value="1" <?php checked( $settings['enable_delivery'], 1 ); ?> /> <?php esc_html_e( 'Delivery', 'doughboss' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><label for="db-currency-symbol"><?php esc_html_e( 'Currency symbol', 'doughboss' ); ?></label></th>
						<td><input type="text" id="db-currency-symbol" class="small-text" name="<?php echo esc_attr( $opt ); ?>[currency_symbol]" value="<?php echo esc_attr( $settings['currency_symbol'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="db-currency-code"><?php esc_html_e( 'Currency code', 'doughboss' ); ?></label></th>
						<td><input type="text" id="db-currency-code" class="small-text" name="<?php echo esc_attr( $opt ); ?>[currency_code]" value="<?php echo esc_attr( $settings['currency_code'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="db-tax-rate"><?php esc_html_e( 'Tax / GST rate (%)', 'doughboss' ); ?></label></th>
						<td><input type="number" step="0.01" min="0" id="db-tax-rate" class="small-text" name="<?php echo esc_attr( $opt ); ?>[tax_rate]" value="<?php echo esc_attr( $settings['tax_rate'] ); ?>" />
							<span class="description"><?php esc_html_e( 'Australian GST is 10%.', 'doughboss' ); ?></span></td>
					</tr>
					<tr>
						<th><label for="db-gst-inclusive"><?php esc_html_e( 'Prices include GST', 'doughboss' ); ?></label></th>
						<td><input type="checkbox" id="db-gst-inclusive" name="<?php echo esc_attr( $opt ); ?>[gst_inclusive]" value="1" <?php checked( ! empty( $settings['gst_inclusive'] ), true ); ?> />
							<span class="description"><?php esc_html_e( 'Tick for Australia: menu prices already include GST (tax is shown as a component, not added on top).', 'doughboss' ); ?></span></td>
					</tr>
					<tr>
						<th><label for="db-delivery-fee"><?php esc_html_e( 'Delivery fee', 'doughboss' ); ?></label></th>
						<td><input type="number" step="0.01" min="0" id="db-delivery-fee" class="small-text" name="<?php echo esc_attr( $opt ); ?>[delivery_fee]" value="<?php echo esc_attr( $settings['delivery_fee'] ); ?>" /></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Pizza Sizes', 'doughboss' ); ?></h2>
				<p class="description"><?php esc_html_e( 'The base price of a plain pizza of each size. Used by the custom pizza builder.', 'doughboss' ); ?></p>
				<?php $this->render_repeater( 'sizes', $settings['sizes'], $opt ); ?>

				<h2><?php esc_html_e( 'Toppings', 'doughboss' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Each topping and the price added when selected in the builder.', 'doughboss' ); ?></p>
				<?php $this->render_repeater( 'toppings', $settings['toppings'], $opt ); ?>

				<h2><?php esc_html_e( 'Payments (Stripe)', 'doughboss' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Optional. Take card payments at checkout via Stripe. Off by default — start in Test mode with your test keys, then switch to Live. Card payments apply only once payments are on AND keys are set for the active mode.', 'doughboss' ); ?>
						<?php
						if ( ! class_exists( 'DoughBoss_Stripe' ) || ! DoughBoss_Stripe::ready() ) {
							echo ' <strong>' . esc_html__( 'Status: card payments are OFF.', 'doughboss' ) . '</strong>';
						} else {
							/* translators: %s: Stripe mode (Test or Live). */
							echo ' <strong style="color:#1f8a54;">' . esc_html( sprintf( __( 'Status: card payments are ON (%s mode).', 'doughboss' ), DoughBoss_Settings::stripe_mode() === 'live' ? __( 'Live', 'doughboss' ) : __( 'Test', 'doughboss' ) ) ) . '</strong>';
						}
						?>
					</p>
					<?php $mode = isset( $settings['stripe_mode'] ) && 'live' === $settings['stripe_mode'] ? 'live' : 'test'; ?>
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="db-payments-enabled"><?php esc_html_e( 'Accept card payments', 'doughboss' ); ?></label></th>
							<td><input type="checkbox" id="db-payments-enabled" name="<?php echo esc_attr( $opt ); ?>[payments_enabled]" value="1" <?php checked( ! empty( $settings['payments_enabled'] ), true ); ?> />
								<span class="description"><?php esc_html_e( 'When on (and keys are set for the active mode), customers pay by card before the order is placed.', 'doughboss' ); ?></span></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Mode', 'doughboss' ); ?></th>
							<td>
								<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[stripe_mode]" value="test" <?php checked( 'test' === $mode, true ); ?> /> <?php esc_html_e( 'Test', 'doughboss' ); ?></label>&nbsp;&nbsp;
								<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[stripe_mode]" value="live" <?php checked( 'live' === $mode, true ); ?> /> <?php esc_html_e( 'Live', 'doughboss' ); ?></label>
							</td>
						</tr>
						<tr>
							<th><label for="db-stripe-test-pk"><?php esc_html_e( 'Test publishable key', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-stripe-test-pk" class="regular-text" autocomplete="off" placeholder="pk_test_&hellip;" name="<?php echo esc_attr( $opt ); ?>[stripe_test_pk]" value="<?php echo esc_attr( isset( $settings['stripe_test_pk'] ) ? $settings['stripe_test_pk'] : '' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-stripe-test-sk"><?php esc_html_e( 'Test secret key', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-stripe-test-sk" class="regular-text" autocomplete="off" placeholder="sk_test_&hellip;" name="<?php echo esc_attr( $opt ); ?>[stripe_test_sk]" value="<?php echo esc_attr( isset( $settings['stripe_test_sk'] ) ? $settings['stripe_test_sk'] : '' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-stripe-live-pk"><?php esc_html_e( 'Live publishable key', 'doughboss' ); ?></label></th>
							<td><input type="text" id="db-stripe-live-pk" class="regular-text" autocomplete="off" placeholder="pk_live_&hellip;" name="<?php echo esc_attr( $opt ); ?>[stripe_live_pk]" value="<?php echo esc_attr( isset( $settings['stripe_live_pk'] ) ? $settings['stripe_live_pk'] : '' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-stripe-live-sk"><?php esc_html_e( 'Live secret key', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-stripe-live-sk" class="regular-text" autocomplete="off" placeholder="sk_live_&hellip;" name="<?php echo esc_attr( $opt ); ?>[stripe_live_sk]" value="<?php echo esc_attr( isset( $settings['stripe_live_sk'] ) ? $settings['stripe_live_sk'] : '' ); ?>" />
								<p class="description"><?php esc_html_e( 'Find your keys in the Stripe Dashboard → Developers → API keys. Secret keys are used only on the server.', 'doughboss' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="db-stripe-test-whsec"><?php esc_html_e( 'Test webhook secret', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-stripe-test-whsec" class="regular-text" autocomplete="off" placeholder="whsec_&hellip;" name="<?php echo esc_attr( $opt ); ?>[stripe_test_whsec]" value="<?php echo esc_attr( isset( $settings['stripe_test_whsec'] ) ? $settings['stripe_test_whsec'] : '' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="db-stripe-live-whsec"><?php esc_html_e( 'Live webhook secret', 'doughboss' ); ?></label></th>
							<td><input type="password" id="db-stripe-live-whsec" class="regular-text" autocomplete="off" placeholder="whsec_&hellip;" name="<?php echo esc_attr( $opt ); ?>[stripe_live_whsec]" value="<?php echo esc_attr( isset( $settings['stripe_live_whsec'] ) ? $settings['stripe_live_whsec'] : '' ); ?>" />
								<p class="description">
									<?php esc_html_e( 'Stripe Dashboard → Developers → Webhooks. Add an endpoint pointing to:', 'doughboss' ); ?>
									<code><?php echo esc_html( rest_url( DOUGHBOSS_REST_NAMESPACE . '/catering/stripe-webhook' ) ); ?></code>
									<?php esc_html_e( 'and subscribe to payment_intent.succeeded. Then paste its signing secret here.', 'doughboss' ); ?>
								</p></td>
						</tr>
					</table>

					<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a repeatable label/price table for sizes or toppings.
	 *
	 * @param string $field    Field key ('sizes' or 'toppings').
	 * @param array  $rows     Existing rows.
	 * @param string $opt_name Option name.
	 * @return void
	 */
	private function render_repeater( $field, $rows, $opt_name ) {
		$rows    = ! empty( $rows ) ? $rows : array( array( 'label' => '', 'price' => '' ) );
		$table_id = 'db-repeater-' . $field;
		?>
		<table class="widefat db-repeater" id="<?php echo esc_attr( $table_id ); ?>" style="max-width:560px;">
			<thead><tr>
				<th><?php esc_html_e( 'Label', 'doughboss' ); ?></th>
				<th style="width:120px;"><?php esc_html_e( 'Price', 'doughboss' ); ?></th>
				<th style="width:40px;"></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $rows as $i => $row ) : ?>
					<tr>
						<td><input type="text" name="<?php echo esc_attr( $opt_name . '[' . $field . '][' . $i . '][label]' ); ?>" value="<?php echo esc_attr( isset( $row['label'] ) ? $row['label'] : '' ); ?>" style="width:100%;" /></td>
						<td><input type="number" step="0.01" min="0" name="<?php echo esc_attr( $opt_name . '[' . $field . '][' . $i . '][price]' ); ?>" value="<?php echo esc_attr( isset( $row['price'] ) ? $row['price'] : '' ); ?>" style="width:100%;" /></td>
						<td><button class="button-link db-remove-row" aria-label="<?php esc_attr_e( 'Remove row', 'doughboss' ); ?>">✕</button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button class="button db-add-row" data-target="<?php echo esc_attr( $table_id ); ?>"><?php esc_html_e( '+ Add row', 'doughboss' ); ?></button></p>
		<?php
	}
}
