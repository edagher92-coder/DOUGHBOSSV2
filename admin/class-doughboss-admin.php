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
		add_action( 'admin_notices', array( $this, 'tax_notice' ) );
		// Let a DoughBoss Manager (who lacks manage_options) save the settings form.
		add_filter( 'option_page_capability_' . self::SETTINGS_GROUP, array( $this, 'settings_capability' ) );
	}

	/**
	 * The capability required for management screens. A constant, not a
	 * function of who is asking: administrators hold it via activation.
	 *
	 * @return string
	 */
	private function cap() {
		return self::CAP;
	}

	/**
	 * Capability used by options.php when saving our settings group.
	 *
	 * @return string
	 */
	public function settings_capability() {
		return self::CAP;
	}

	/**
	 * Register the top-level menu and sub-pages, with a live count of pending
	 * orders on the menu label so a new order is visible from anywhere in
	 * wp-admin.
	 *
	 * @return void
	 */
	public function register_menu() {
		$counts  = DoughBoss_Order::counts_by_status();
		$pending = isset( $counts['pending'] ) ? (int) $counts['pending'] : 0;
		$label   = __( 'DoughBoss', 'doughboss' );
		if ( $pending > 0 ) {
			$label .= sprintf( ' <span class="update-plugins count-%1$d" aria-hidden="true"><span class="plugin-count">%1$d</span></span>', $pending );
		}

		add_menu_page(
			__( 'DoughBoss', 'doughboss' ),
			$label,
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
	 * Nag until the shop has decided its tax setting. The plugin ships with a
	 * 0% rate deliberately — guessing a rate is worse than asking.
	 *
	 * @return void
	 */
	public function tax_notice() {
		if ( ! current_user_can( $this->cap() ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'doughboss' ) ) {
			return;
		}
		if ( (float) DoughBoss_Settings::get( 'tax_rate', 0 ) > 0 || get_option( 'doughboss_tax_notice_dismissed' ) ) {
			return;
		}
		if ( isset( $_GET['doughboss_dismiss_tax'] ) && check_admin_referer( 'doughboss_dismiss_tax' ) ) {
			update_option( 'doughboss_tax_notice_dismissed', 1, false );
			return;
		}
		$dismiss = wp_nonce_url( add_query_arg( 'doughboss_dismiss_tax', '1' ), 'doughboss_dismiss_tax' );
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'DoughBoss: no tax rate is set.', 'doughboss' ); ?></strong>
				<?php esc_html_e( 'Orders will record 0% tax until you set your GST rate under Settings → Tax. Confirm with your accountant which items are taxable (in Australia, hot prepared food is; plain bread is not).', 'doughboss' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=doughboss-settings' ) ); ?>"><?php esc_html_e( 'Open settings', 'doughboss' ); ?></a>
				&nbsp;·&nbsp;
				<a href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'We do not charge tax — dismiss', 'doughboss' ); ?></a>
			</p>
		</div>
		<?php
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
		$input    = is_array( $input ) ? $input : array();
		$defaults = DoughBoss_Settings::defaults();
		$clean    = array();

		$clean['currency_symbol']         = isset( $input['currency_symbol'] ) ? sanitize_text_field( $input['currency_symbol'] ) : $defaults['currency_symbol'];
		$clean['currency_code']           = isset( $input['currency_code'] ) ? strtoupper( substr( sanitize_text_field( $input['currency_code'] ), 0, 3 ) ) : $defaults['currency_code'];
		$clean['tax_rate']                = isset( $input['tax_rate'] ) ? min( 100, max( 0, round( (float) $input['tax_rate'], 2 ) ) ) : 0;
		$clean['tax_label']               = isset( $input['tax_label'] ) && '' !== trim( $input['tax_label'] ) ? sanitize_text_field( $input['tax_label'] ) : $defaults['tax_label'];
		$clean['prices_include_tax']      = empty( $input['prices_include_tax'] ) ? 0 : 1;
		$clean['tax_applies_to_delivery'] = empty( $input['tax_applies_to_delivery'] ) ? 0 : 1;
		$clean['delivery_fee']            = isset( $input['delivery_fee'] ) ? max( 0, round( (float) $input['delivery_fee'], 2 ) ) : 0;
		$clean['min_order']               = isset( $input['min_order'] ) ? max( 0, round( (float) $input['min_order'], 2 ) ) : 0;
		$clean['enable_pickup']           = empty( $input['enable_pickup'] ) ? 0 : 1;
		$clean['enable_delivery']         = empty( $input['enable_delivery'] ) ? 0 : 1;
		$clean['ordering_open']           = empty( $input['ordering_open'] ) ? 0 : 1;
		$clean['store_phone']             = isset( $input['store_phone'] ) ? sanitize_text_field( $input['store_phone'] ) : '';
		$clean['pay_note']                = isset( $input['pay_note'] ) ? sanitize_text_field( $input['pay_note'] ) : '';
		$clean['tracking_page_url']       = isset( $input['tracking_page_url'] ) ? esc_url_raw( $input['tracking_page_url'] ) : '';
		$clean['menu_page_url']           = isset( $input['menu_page_url'] ) ? esc_url_raw( $input['menu_page_url'] ) : '';

		$clean['sizes']    = $this->sanitize_rows( isset( $input['sizes'] ) ? $input['sizes'] : array() );
		$clean['toppings'] = $this->sanitize_rows( isset( $input['toppings'] ) ? $input['toppings'] : array() );

		if ( ! $clean['enable_pickup'] && ! $clean['enable_delivery'] ) {
			add_settings_error( DoughBoss_Settings::OPTION_KEY, 'doughboss_no_fulfilment', __( 'At least one of Pickup or Delivery must be enabled, or nobody can check out. Pickup has been re-enabled.', 'doughboss' ) );
			$clean['enable_pickup'] = 1;
		}
		if ( empty( $clean['sizes'] ) ) {
			add_settings_error( DoughBoss_Settings::OPTION_KEY, 'doughboss_no_sizes', __( 'No pizza sizes are configured, so the pizza builder will show "No pizza sizes configured yet."', 'doughboss' ), 'warning' );
		}

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
			if ( ! is_array( $row ) || empty( $row['label'] ) ) {
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

		wp_register_script( 'doughboss-admin', false, array(), DOUGHBOSS_VERSION, true );
		wp_enqueue_script( 'doughboss-admin' );
		wp_localize_script(
			'doughboss-admin',
			'DoughBossAdmin',
			array(
				'restUrl' => esc_url_raw( rest_url( DOUGHBOSS_REST_NAMESPACE ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'now'     => gmdate( 'Y-m-d H:i:s' ),
				'i18n'    => array(
					'updateFailed' => __( 'Could not update the order status. Please try again.', 'doughboss' ),
					'updated'      => __( 'Order %1$s marked %2$s.', 'doughboss' ),
					'newOrders'    => __( '%d new order(s) — refresh to see them.', 'doughboss' ),
					'refresh'      => __( 'Refresh now', 'doughboss' ),
					'mute'         => __( 'Mute new-order sound', 'doughboss' ),
					'unmute'       => __( 'Unmute new-order sound', 'doughboss' ),
				),
			)
		);
		wp_add_inline_script( 'doughboss-admin', $this->inline_admin_js() );
	}

	/**
	 * Inline JS: honest status updates, new-order polling with a chime, and
	 * the settings repeaters.
	 *
	 * @return string
	 */
	private function inline_admin_js() {
		return <<<'JS'
(function () {
	var A = window.DoughBossAdmin || {};
	var I18N = A.i18n || {};

	function live(msg) {
		var el = document.getElementById('db-admin-live');
		if (el) { el.textContent = msg; }
	}

	/* ---- Status select: only report success when the server said so ---- */
	document.addEventListener('change', function (e) {
		var sel = e.target;
		if (!sel.matches('.db-status-select')) { return; }
		var id = sel.getAttribute('data-order');
		var previous = sel.getAttribute('data-current');
		var row = sel.closest('tr');
		sel.disabled = true;
		fetch(A.restUrl + '/admin/order/' + id + '/status', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': A.nonce },
			body: JSON.stringify({ status: sel.value })
		}).then(function (r) {
			return r.json().then(function (json) {
				if (!r.ok || !json || !json.success) {
					throw new Error((json && json.message) || I18N.updateFailed);
				}
				return json;
			});
		}).then(function () {
			sel.disabled = false;
			sel.setAttribute('data-current', sel.value);
			if (row) {
				row.classList.add('db-row-saved');
				setTimeout(function () { row.classList.remove('db-row-saved'); }, 900);
			}
			var label = sel.options[sel.selectedIndex].text.trim();
			live((I18N.updated || 'Order %1$s marked %2$s.').replace('%1$s', sel.getAttribute('data-number')).replace('%2$s', label));
		}).catch(function (err) {
			sel.disabled = false;
			sel.value = previous;
			if (row) {
				row.classList.add('db-row-error');
				setTimeout(function () { row.classList.remove('db-row-error'); }, 1500);
			}
			var note = document.getElementById('db-admin-error');
			if (note) { note.textContent = err.message || I18N.updateFailed; note.hidden = false; }
			live(err.message || I18N.updateFailed);
		});
	});

	/* ---- New-order alert: poll, chime, badge ---- */
	var ordersScreen = document.querySelector('.doughboss-orders');
	if (ordersScreen && A.restUrl) {
		var since = A.now;
		var seen = 0;
		var muted = window.localStorage ? localStorage.getItem('doughboss_mute') === '1' : false;
		var muteBtn = document.getElementById('db-mute');
		var banner = document.getElementById('db-new-orders');
		var baseTitle = document.title;

		function setMute(state) {
			muted = state;
			try { localStorage.setItem('doughboss_mute', state ? '1' : '0'); } catch (e) {}
			if (muteBtn) {
				muteBtn.textContent = state ? (I18N.unmute || 'Unmute') : (I18N.mute || 'Mute');
				muteBtn.setAttribute('aria-pressed', state ? 'true' : 'false');
			}
		}
		if (muteBtn) {
			setMute(muted);
			muteBtn.addEventListener('click', function () { setMute(!muted); });
		}

		function chime() {
			if (muted) { return; }
			try {
				var Ctx = window.AudioContext || window.webkitAudioContext;
				var ctx = new Ctx();
				[880, 1175].forEach(function (freq, i) {
					var osc = ctx.createOscillator();
					var gain = ctx.createGain();
					osc.type = 'sine';
					osc.frequency.value = freq;
					gain.gain.value = 0.0001;
					osc.connect(gain);
					gain.connect(ctx.destination);
					var t = ctx.currentTime + i * 0.18;
					osc.start(t);
					gain.gain.exponentialRampToValueAtTime(0.25, t + 0.02);
					gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.17);
					osc.stop(t + 0.2);
				});
			} catch (e) { /* audio blocked until a user gesture — the banner still shows */ }
		}

		function poll() {
			fetch(A.restUrl + '/admin/orders/new-count?since=' + encodeURIComponent(since), {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': A.nonce }
			}).then(function (r) { return r.ok ? r.json() : null; }).then(function (json) {
				if (!json) { return; }
				if (json.count > seen) {
					seen = json.count;
					if (banner) {
						banner.hidden = false;
						banner.querySelector('.db-new-count').textContent = (I18N.newOrders || '%d new order(s)').replace('%d', seen);
					}
					document.title = '(' + seen + ') ' + baseTitle;
					chime();
					live((I18N.newOrders || '%d new order(s)').replace('%d', seen));
				}
			}).catch(function () {});
		}
		setInterval(function () { if (!document.hidden) { poll(); } }, 20000);
	}

	/* ---- Settings repeaters ---- */
	document.addEventListener('click', function (e) {
		var addBtn = e.target.closest('.db-add-row');
		if (addBtn) {
			e.preventDefault();
			var body = document.querySelector('#' + addBtn.getAttribute('data-target') + ' tbody');
			var tpl = body.querySelector('tr');
			var clone = tpl.cloneNode(true);
			var index = body.querySelectorAll('tr').length;
			clone.querySelectorAll('input').forEach(function (i) {
				i.value = '';
				i.name = i.name.replace(/\[\d+\]/, '[' + index + ']');
				if (i.getAttribute('aria-label')) {
					i.setAttribute('aria-label', i.getAttribute('aria-label').replace(/\d+$/, String(index + 1)));
				}
			});
			body.appendChild(clone);
			clone.querySelector('input').focus();
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
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'active'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $search ) {
			$status = isset( $_GET['status'] ) ? $status : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$per_page = 25;
		$result   = DoughBoss_Order::query(
			array(
				'status'   => $status,
				'search'   => $search,
				'per_page' => $per_page,
				'page'     => $paged,
			)
		);

		$statuses    = DoughBoss_Order::statuses();
		$counts      = DoughBoss_Order::counts_by_status();
		$total_all   = array_sum( $counts );
		$active_n    = 0;
		foreach ( DoughBoss_Order::active_statuses() as $s ) {
			$active_n += isset( $counts[ $s ] ) ? $counts[ $s ] : 0;
		}
		$total_pages = (int) ceil( $result['total'] / $per_page );
		$items_by    = DoughBoss_Order::get_items_for_orders( wp_list_pluck( $result['items'], 'id' ) );
		$base_url    = admin_url( 'admin.php?page=doughboss' );
		?>
		<div class="wrap doughboss-orders">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Orders', 'doughboss' ); ?></h1>
			<button type="button" class="button db-mute-btn" id="db-mute" aria-pressed="false"><?php esc_html_e( 'Mute new-order sound', 'doughboss' ); ?></button>
			<hr class="wp-header-end" />

			<div id="db-admin-live" class="screen-reader-text" aria-live="polite"></div>
			<div id="db-admin-error" class="notice notice-error" hidden></div>
			<div id="db-new-orders" class="notice notice-info db-new-orders" hidden>
				<p><strong class="db-new-count"></strong> <a href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Refresh now', 'doughboss' ); ?></a></p>
			</div>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'active', $base_url ) ); ?>" <?php echo 'active' === $status ? 'class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'Active', 'doughboss' ); ?> <span class="count">(<?php echo (int) $active_n; ?>)</span></a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', '', $base_url ) ); ?>" <?php echo '' === $status ? 'class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'All', 'doughboss' ); ?> <span class="count">(<?php echo (int) $total_all; ?>)</span></a> |</li>
				<?php foreach ( $statuses as $key => $label ) : ?>
					<li><a href="<?php echo esc_url( add_query_arg( 'status', $key, $base_url ) ); ?>" <?php echo $status === $key ? 'class="current" aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?> <span class="count">(<?php echo isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0; ?>)</span></a><?php echo array_key_last( $statuses ) === $key ? '' : ' |'; ?></li>
				<?php endforeach; ?>
			</ul>

			<form method="get" class="db-orders-filter search-form">
				<input type="hidden" name="page" value="doughboss" />
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />
				<label class="screen-reader-text" for="db-order-search"><?php esc_html_e( 'Search orders', 'doughboss' ); ?></label>
				<input type="search" id="db-order-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Order number, name, email or phone…', 'doughboss' ); ?>" />
				<button class="button"><?php esc_html_e( 'Search', 'doughboss' ); ?></button>
			</form>

			<table class="wp-list-table widefat striped db-orders-table">
				<thead>
					<tr>
						<th scope="col" class="db-col-number"><?php esc_html_e( 'Order #', 'doughboss' ); ?></th>
						<th scope="col" class="db-col-customer"><?php esc_html_e( 'Customer', 'doughboss' ); ?></th>
						<th scope="col" class="db-col-type"><?php esc_html_e( 'Type & where', 'doughboss' ); ?></th>
						<th scope="col" class="db-col-items"><?php esc_html_e( 'Items & notes', 'doughboss' ); ?></th>
						<th scope="col" class="db-col-total"><?php esc_html_e( 'Total', 'doughboss' ); ?></th>
						<th scope="col" class="db-col-placed"><?php esc_html_e( 'Placed', 'doughboss' ); ?></th>
						<th scope="col" class="db-col-status"><?php esc_html_e( 'Status', 'doughboss' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $result['items'] ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No orders match.', 'doughboss' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $result['items'] as $order ) : ?>
							<?php
							$items      = isset( $items_by[ (int) $order->id ] ) ? $items_by[ (int) $order->id ] : array();
							$tel        = preg_replace( '/[^\d+]/', '', (string) $order->customer_phone );
							$is_deliver = 'delivery' === $order->order_type;
							?>
							<tr class="db-order-row db-order-row--<?php echo esc_attr( $order->status ); ?>">
								<td class="db-col-number">
									<strong><?php echo esc_html( $order->order_number ); ?></strong>
									<?php if ( ! (int) $order->email_sent && '' !== (string) $order->email_error ) : ?>
										<br /><span class="db-mail-warning" title="<?php echo esc_attr( $order->email_error ); ?>"><?php esc_html_e( 'Email failed', 'doughboss' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="db-col-customer">
									<strong><?php echo esc_html( $order->customer_name ); ?></strong><br />
									<?php if ( $tel ) : ?>
										<a href="tel:<?php echo esc_attr( $tel ); ?>"><?php echo esc_html( $order->customer_phone ); ?></a><br />
									<?php else : ?>
										<?php echo esc_html( $order->customer_phone ); ?><br />
									<?php endif; ?>
									<small><a href="mailto:<?php echo esc_attr( $order->customer_email ); ?>"><?php echo esc_html( $order->customer_email ); ?></a></small>
								</td>
								<td class="db-col-type">
									<span class="db-type-pill db-type-pill--<?php echo esc_attr( $order->order_type ); ?>"><?php echo esc_html( $is_deliver ? __( 'Delivery', 'doughboss' ) : __( 'Pickup', 'doughboss' ) ); ?></span>
									<?php if ( $is_deliver ) : ?>
										<div class="db-address">
											<?php if ( '' !== trim( (string) $order->address ) ) : ?>
												<?php echo nl2br( esc_html( $order->address ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
											<?php else : ?>
												<em class="db-missing"><?php esc_html_e( 'No address recorded', 'doughboss' ); ?></em>
											<?php endif; ?>
										</div>
									<?php endif; ?>
								</td>
								<td class="db-col-items">
									<ul class="db-item-list">
										<?php foreach ( $items as $item ) : ?>
											<li>
												<strong><?php echo esc_html( $item['quantity'] ); ?>×</strong> <?php echo esc_html( $item['name'] ); ?>
												<?php if ( ! empty( $item['size'] ) && false === strpos( $item['name'], $item['size'] ) ) : ?>
													<small><?php echo esc_html( $item['size'] ); ?></small>
												<?php endif; ?>
												<?php if ( ! empty( $item['toppings'] ) ) : ?>
													<small>(<?php echo esc_html( implode( ', ', wp_list_pluck( $item['toppings'], 'label' ) ) ); ?>)</small>
												<?php endif; ?>
											</li>
										<?php endforeach; ?>
									</ul>
									<?php if ( '' !== trim( (string) $order->notes ) ) : ?>
										<div class="db-notes"><strong><?php esc_html_e( 'Notes:', 'doughboss' ); ?></strong> <?php echo nl2br( esc_html( $order->notes ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
									<?php endif; ?>
								</td>
								<td class="db-col-total">
									<?php echo esc_html( DoughBoss_Settings::format_price( $order->total ) ); ?>
									<?php if ( (float) $order->tax > 0 ) : ?>
										<br /><small><?php echo (int) $order->tax_inclusive ? esc_html__( 'incl.', 'doughboss' ) : esc_html__( 'plus', 'doughboss' ); ?> <?php echo esc_html( DoughBoss_Settings::tax_label() . ' ' . DoughBoss_Settings::format_price( $order->tax ) ); ?></small>
									<?php endif; ?>
								</td>
								<td class="db-col-placed">
									<?php
									// created_at is stored in UTC; show it in the site's timezone.
									$local = get_date_from_gmt( $order->created_at, 'Y-m-d H:i:s' );
									echo esc_html( mysql2date( 'D j M, g:i a', $local ) );
									?>
								</td>
								<td class="db-col-status">
									<label class="screen-reader-text" for="db-status-<?php echo esc_attr( $order->id ); ?>">
										<?php
										/* translators: %s: order number. */
										echo esc_html( sprintf( __( 'Status for order %s', 'doughboss' ), $order->order_number ) );
										?>
									</label>
									<select class="db-status-select" id="db-status-<?php echo esc_attr( $order->id ); ?>" data-order="<?php echo esc_attr( $order->id ); ?>" data-number="<?php echo esc_attr( $order->order_number ); ?>" data-current="<?php echo esc_attr( $order->status ); ?>">
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
		$opt      = DoughBoss_Settings::OPTION_KEY;
		?>
		<div class="wrap doughboss-settings">
			<h1><?php esc_html_e( 'DoughBoss Settings', 'doughboss' ); ?></h1>
			<?php settings_errors( DoughBoss_Settings::OPTION_KEY ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( self::SETTINGS_GROUP ); ?>

				<h2><?php esc_html_e( 'Store', 'doughboss' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="db-ordering-open"><?php esc_html_e( 'Accept orders', 'doughboss' ); ?></label></th>
						<td><input type="checkbox" id="db-ordering-open" name="<?php echo esc_attr( $opt ); ?>[ordering_open]" value="1" <?php checked( $settings['ordering_open'], 1 ); ?> />
							<span class="description"><?php esc_html_e( 'Untick to pause online ordering. The storefront shows a "closed" notice and disables ordering.', 'doughboss' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Fulfilment', 'doughboss' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enable_pickup]" value="1" <?php checked( $settings['enable_pickup'], 1 ); ?> /> <?php esc_html_e( 'Pickup', 'doughboss' ); ?></label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enable_delivery]" value="1" <?php checked( $settings['enable_delivery'], 1 ); ?> /> <?php esc_html_e( 'Delivery', 'doughboss' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="db-delivery-fee"><?php esc_html_e( 'Delivery fee', 'doughboss' ); ?></label></th>
						<td><input type="number" step="0.01" min="0" id="db-delivery-fee" class="small-text" name="<?php echo esc_attr( $opt ); ?>[delivery_fee]" value="<?php echo esc_attr( $settings['delivery_fee'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="db-min-order"><?php esc_html_e( 'Minimum order', 'doughboss' ); ?></label></th>
						<td><input type="number" step="0.01" min="0" id="db-min-order" class="small-text" name="<?php echo esc_attr( $opt ); ?>[min_order]" value="<?php echo esc_attr( $settings['min_order'] ); ?>" />
							<span class="description"><?php esc_html_e( 'Subtotal required before checkout. 0 = no minimum.', 'doughboss' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><label for="db-store-phone"><?php esc_html_e( 'Store phone', 'doughboss' ); ?></label></th>
						<td><input type="text" id="db-store-phone" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[store_phone]" value="<?php echo esc_attr( $settings['store_phone'] ); ?>" autocomplete="tel" />
							<span class="description"><?php esc_html_e( 'Shown on the storefront and in confirmation emails as a fallback.', 'doughboss' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><label for="db-pay-note"><?php esc_html_e( 'Payment note', 'doughboss' ); ?></label></th>
						<td><input type="text" id="db-pay-note" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[pay_note]" value="<?php echo esc_attr( $settings['pay_note'] ); ?>" placeholder="<?php esc_attr_e( 'No payment now — pay when you collect.', 'doughboss' ); ?>" />
							<span class="description"><?php esc_html_e( 'Shown under the Place order button. Tell customers how and when they pay.', 'doughboss' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><label for="db-menu-url"><?php esc_html_e( 'Menu page URL', 'doughboss' ); ?></label></th>
						<td><input type="url" id="db-menu-url" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[menu_page_url]" value="<?php echo esc_attr( $settings['menu_page_url'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="db-tracking-url"><?php esc_html_e( 'Order tracking page URL', 'doughboss' ); ?></label></th>
						<td><input type="url" id="db-tracking-url" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[tracking_page_url]" value="<?php echo esc_attr( $settings['tracking_page_url'] ); ?>" />
							<span class="description"><?php esc_html_e( 'The page containing [doughboss_order_tracking]. Used for the tracking link in emails.', 'doughboss' ); ?></span></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Currency & tax', 'doughboss' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="db-currency-symbol"><?php esc_html_e( 'Currency symbol', 'doughboss' ); ?></label></th>
						<td><input type="text" id="db-currency-symbol" class="small-text" name="<?php echo esc_attr( $opt ); ?>[currency_symbol]" value="<?php echo esc_attr( $settings['currency_symbol'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="db-currency-code"><?php esc_html_e( 'Currency code', 'doughboss' ); ?></label></th>
						<td><input type="text" id="db-currency-code" class="small-text" maxlength="3" name="<?php echo esc_attr( $opt ); ?>[currency_code]" value="<?php echo esc_attr( $settings['currency_code'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="db-tax-rate"><?php esc_html_e( 'Tax rate (%)', 'doughboss' ); ?></label></th>
						<td><input type="number" step="0.01" min="0" max="100" id="db-tax-rate" class="small-text" name="<?php echo esc_attr( $opt ); ?>[tax_rate]" value="<?php echo esc_attr( $settings['tax_rate'] ); ?>" />
							<span class="description"><?php esc_html_e( 'Leave at 0 if you do not charge tax. Confirm the rate and which items are taxable with your accountant.', 'doughboss' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><label for="db-tax-label"><?php esc_html_e( 'Tax label', 'doughboss' ); ?></label></th>
						<td><input type="text" id="db-tax-label" class="small-text" name="<?php echo esc_attr( $opt ); ?>[tax_label]" value="<?php echo esc_attr( $settings['tax_label'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tax model', 'doughboss' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[prices_include_tax]" value="1" <?php checked( $settings['prices_include_tax'], 1 ); ?> /> <?php esc_html_e( 'Menu prices already include tax (Australian convention — the receipt shows the tax included)', 'doughboss' ); ?></label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[tax_applies_to_delivery]" value="1" <?php checked( $settings['tax_applies_to_delivery'], 1 ); ?> /> <?php esc_html_e( 'Tax applies to the delivery fee', 'doughboss' ); ?></label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Pizza Sizes', 'doughboss' ); ?></h2>
				<p class="description"><?php esc_html_e( 'The base price of a plain pizza of each size. Used by the custom pizza builder.', 'doughboss' ); ?></p>
				<?php $this->render_repeater( 'sizes', $settings['sizes'], $opt, __( 'Size', 'doughboss' ) ); ?>

				<h2><?php esc_html_e( 'Toppings', 'doughboss' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Each topping and the price added when selected in the builder.', 'doughboss' ); ?></p>
				<?php $this->render_repeater( 'toppings', $settings['toppings'], $opt, __( 'Topping', 'doughboss' ) ); ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a repeatable label/price table for sizes or toppings.
	 *
	 * Both buttons are type="button": inside a <form> a bare <button> is a
	 * submit button, and the first one in tree order is the form's implicit
	 * submission target — so pressing Enter in any text field used to trigger
	 * the first "✕ Remove row" and silently delete a pizza size.
	 *
	 * @param string $field    Field key ('sizes' or 'toppings').
	 * @param array  $rows     Existing rows.
	 * @param string $opt_name Option name.
	 * @param string $noun     Singular noun for accessible labels.
	 * @return void
	 */
	private function render_repeater( $field, $rows, $opt_name, $noun ) {
		$rows     = ! empty( $rows ) ? $rows : array(
			array(
				'label' => '',
				'price' => '',
			),
		);
		$table_id = 'db-repeater-' . $field;
		?>
		<table class="widefat db-repeater" id="<?php echo esc_attr( $table_id ); ?>" style="max-width:560px;">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Label', 'doughboss' ); ?></th>
				<th scope="col" style="width:120px;"><?php esc_html_e( 'Price', 'doughboss' ); ?></th>
				<th scope="col" style="width:40px;"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'doughboss' ); ?></span></th>
			</tr></thead>
			<tbody>
				<?php foreach ( array_values( $rows ) as $i => $row ) : ?>
					<tr>
						<td><input type="text" name="<?php echo esc_attr( $opt_name . '[' . $field . '][' . $i . '][label]' ); ?>" value="<?php echo esc_attr( isset( $row['label'] ) ? $row['label'] : '' ); ?>" style="width:100%;" aria-label="<?php echo esc_attr( sprintf( '%s label, row %d', $noun, $i + 1 ) ); ?>" /></td>
						<td><input type="number" step="0.01" min="0" name="<?php echo esc_attr( $opt_name . '[' . $field . '][' . $i . '][price]' ); ?>" value="<?php echo esc_attr( isset( $row['price'] ) ? $row['price'] : '' ); ?>" style="width:100%;" aria-label="<?php echo esc_attr( sprintf( '%s price, row %d', $noun, $i + 1 ) ); ?>" /></td>
						<td><button type="button" class="button-link db-remove-row" aria-label="<?php echo esc_attr( sprintf( __( 'Remove %s row %d', 'doughboss' ), $noun, $i + 1 ) ); ?>">✕</button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button db-add-row" data-target="<?php echo esc_attr( $table_id ); ?>"><?php esc_html_e( '+ Add row', 'doughboss' ); ?></button></p>
		<?php
	}
}
