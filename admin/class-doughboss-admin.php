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
			__( 'DoughBoss Settings', 'doughboss' ),
			__( 'Settings', 'doughboss' ),
			$this->cap(),
			'doughboss-settings',
			array( $this, 'render_settings_page' )
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
		$clean['delivery_fee']    = isset( $input['delivery_fee'] ) ? max( 0, (float) $input['delivery_fee'] ) : 0;
		$clean['enable_pickup']   = empty( $input['enable_pickup'] ) ? 0 : 1;
		$clean['enable_delivery'] = empty( $input['enable_delivery'] ) ? 0 : 1;
		$clean['ordering_open']   = empty( $input['ordering_open'] ) ? 0 : 1;

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
		if (!sel.matches('.db-status-select')) { return; }
		var id = sel.getAttribute('data-order');
		sel.disabled = true;
		fetch(DoughBossAdmin.restUrl + '/admin/order/' + id + '/status', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': DoughBossAdmin.nonce },
			body: JSON.stringify({ status: sel.value })
		}).then(function (r) { return r.json(); }).then(function () {
			sel.disabled = false;
			var row = sel.closest('tr');
			if (row) { row.style.transition = 'background .6s'; row.style.background = '#eaffea'; setTimeout(function(){ row.style.background=''; }, 800); }
		}).catch(function () { sel.disabled = false; alert('Could not update order status.'); });
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
						<th><label for="db-tax-rate"><?php esc_html_e( 'Tax rate (%)', 'doughboss' ); ?></label></th>
						<td><input type="number" step="0.01" min="0" id="db-tax-rate" class="small-text" name="<?php echo esc_attr( $opt ); ?>[tax_rate]" value="<?php echo esc_attr( $settings['tax_rate'] ); ?>" /></td>
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
