<?php
/**
 * Customer membership and loyalty wallet.
 *
 * The feature is intentionally dormant until an owner enables it in
 * DoughBoss > Rewards. It uses WordPress' own authenticated session after a
 * short-lived email link; DoughBoss never stores a customer password.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DoughBoss_Loyalty {
	const PAGE_SLUG = 'rewards';

	/** @return string */
	public static function members_table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_loyalty_members';
	}

	/** @return string */
	public static function ledger_table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_loyalty_ledger';
	}

	/** @return string */
	public static function tokens_table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_loyalty_tokens';
	}

	/** Register non-invasive hooks. */
	public function init() {
		add_action( 'doughboss_order_created', array( $this, 'maybe_award_order' ), 20, 2 );
		add_action( 'doughboss_order_payment_status_changed', array( $this, 'payment_status_changed' ), 20, 3 );
		add_action( 'admin_post_nopriv_doughboss_loyalty_request_link', array( $this, 'request_link' ) );
		add_action( 'admin_post_doughboss_loyalty_request_link', array( $this, 'request_link' ) );
		add_action( 'admin_post_nopriv_doughboss_loyalty_magic_link', array( $this, 'magic_link' ) );
		add_action( 'admin_post_doughboss_loyalty_magic_link', array( $this, 'magic_link' ) );
		add_action( 'admin_post_doughboss_loyalty_redeem', array( $this, 'redeem' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_post_doughboss_save_loyalty_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_doughboss_create_loyalty_page', array( $this, 'create_page' ) );
		add_action( 'admin_post_doughboss_loyalty_adjust', array( $this, 'adjust_member' ) );
	}

	/** @return bool */
	public static function enabled() {
		return (bool) DoughBoss_Settings::get( 'loyalty_enabled', 0 );
	}

	/** @return array */
	public static function promotions() {
		$promos = DoughBoss_Settings::get( 'loyalty_promos', array() );
		return is_array( $promos ) ? $promos : array();
	}

	/**
	 * Output the public wallet. It deliberately stays useful while the program
	 * is in draft: no points can be earned or redeemed until the owner enables
	 * it, but the page can be reviewed before launch.
	 *
	 * @return string
	 */
	public static function shortcode() {
		$notice = isset( $_GET['db_loyalty'] ) ? sanitize_key( wp_unslash( $_GET['db_loyalty'] ) ) : '';
		ob_start();
		?>
		<section class="db-loyalty" aria-labelledby="db-loyalty-title">
			<div class="db-loyalty__hero">
				<p class="db-loyalty__eyebrow"><?php esc_html_e( 'Dough Boss Rewards', 'doughboss' ); ?></p>
				<h2 id="db-loyalty-title"><?php esc_html_e( 'Good food deserves good rewards.', 'doughboss' ); ?></h2>
				<p><?php esc_html_e( 'Earn 1 point for every $1 of paid food spend. Every 100 points unlocks a personal $5 Dough Boss voucher.', 'doughboss' ); ?></p>
			</div>
			<?php self::render_notice( $notice ); ?>
			<?php if ( ! self::enabled() ) : ?>
				<p class="db-loyalty__draft" role="status"><?php esc_html_e( 'Rewards is being prepared for launch. You can join the waitlist experience now; points and vouchers are not active yet.', 'doughboss' ); ?></p>
			<?php endif; ?>
			<?php if ( is_user_logged_in() ) : ?>
				<?php self::render_wallet( get_current_user_id() ); ?>
			<?php else : ?>
				<form class="db-loyalty__signin" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="doughboss_loyalty_request_link" />
					<?php wp_nonce_field( 'doughboss_loyalty_request_link' ); ?>
					<h3><?php esc_html_e( 'Join or sign in', 'doughboss' ); ?></h3>
					<p><?php esc_html_e( 'We’ll email you a secure sign-in link — no password to remember.', 'doughboss' ); ?></p>
					<label for="db-loyalty-email"><?php esc_html_e( 'Email address', 'doughboss' ); ?></label>
					<input id="db-loyalty-email" name="email" type="email" autocomplete="email" required />
					<label class="db-loyalty__check"><input name="marketing_consent" type="checkbox" value="1" /> <?php esc_html_e( 'Email me occasional Dough Boss offers. Optional.', 'doughboss' ); ?></label>
					<button type="submit"><?php esc_html_e( 'Email my sign-in link', 'doughboss' ); ?></button>
				</form>
			<?php endif; ?>
			<div class="db-loyalty__promos">
				<h3><?php esc_html_e( 'Launch rewards', 'doughboss' ); ?></h3>
				<?php foreach ( self::promotions() as $promo ) : ?>
					<?php if ( empty( $promo['public'] ) ) { continue; } ?>
					<article><strong><?php echo esc_html( isset( $promo['title'] ) ? $promo['title'] : '' ); ?></strong><p><?php echo esc_html( isset( $promo['description'] ) ? $promo['description'] : '' ); ?></p></article>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** @param string $notice @return void */
	private static function render_notice( $notice ) {
		$messages = array(
			'link-sent' => __( 'If that email can receive messages, a secure sign-in link is on its way.', 'doughboss' ),
			'linked'    => __( 'You are signed in to Dough Boss Rewards.', 'doughboss' ),
			'expired'   => __( 'That sign-in link has expired. Please request a fresh one.', 'doughboss' ),
			'redeemed'  => __( 'Your personal reward voucher is ready below.', 'doughboss' ),
			'error'     => __( 'We could not complete that request. Please try again.', 'doughboss' ),
		);
		if ( isset( $messages[ $notice ] ) ) {
			printf( '<p class="db-loyalty__notice" role="status">%s</p>', esc_html( $messages[ $notice ] ) );
		}
	}

	/** @param int $user_id @return void */
	private static function render_wallet( $user_id ) {
		$member = self::member_for_user( $user_id, true );
		if ( ! $member ) {
			return;
		}
		$threshold = (int) DoughBoss_Settings::get( 'loyalty_redemption_points', 100 );
		$value     = (float) DoughBoss_Settings::get( 'loyalty_redemption_amount', 5 );
		$balance   = (int) $member->points_balance;
		?>
		<div class="db-loyalty__wallet">
			<div><p><?php esc_html_e( 'Your balance', 'doughboss' ); ?></p><strong><?php echo esc_html( number_format_i18n( $balance ) ); ?> <?php esc_html_e( 'points', 'doughboss' ); ?></strong><span><?php echo esc_html( $member->tier ); ?></span></div>
			<div><p><?php printf( esc_html__( '%1$d points = $%2$s reward', 'doughboss' ), $threshold, number_format_i18n( $value, 2 ) ); ?></p>
				<?php if ( self::enabled() && $balance >= $threshold ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="doughboss_loyalty_redeem" /><?php wp_nonce_field( 'doughboss_loyalty_redeem' ); ?><button type="submit"><?php printf( esc_html__( 'Redeem $%s voucher', 'doughboss' ), number_format_i18n( $value, 2 ) ); ?></button></form>
				<?php else : ?>
					<p><?php printf( esc_html__( '%d more points until your next reward.', 'doughboss' ), max( 0, $threshold - $balance ) ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php $vouchers = self::recent_vouchers( (string) $member->email ); if ( $vouchers ) : ?>
			<div class="db-loyalty__vouchers"><h3><?php esc_html_e( 'Your reward vouchers', 'doughboss' ); ?></h3><?php foreach ( $vouchers as $voucher ) : ?><p><code><?php echo esc_html( $voucher->code ); ?></code> — <?php echo esc_html( DoughBoss_Settings::format_price( $voucher->value ) ); ?> <?php esc_html_e( 'off', 'doughboss' ); ?></p><?php endforeach; ?></div>
		<?php endif; ?>
		<?php
	}

	/** Request a one-time email sign-in link. */
	public function request_link() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'doughboss_loyalty_request_link' ) ) {
			self::redirect( 'error' );
		}
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) || ! self::request_allowed( $email ) ) {
			self::redirect( 'link-sent' );
		}
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			$username = self::unique_username( $email );
			$user_id  = wp_create_user( $username, wp_generate_password( 32, true, true ), $email );
			if ( is_wp_error( $user_id ) ) { self::redirect( 'link-sent' ); }
			wp_update_user( array( 'ID' => $user_id, 'role' => 'subscriber' ) );
			update_user_meta( $user_id, 'doughboss_loyalty_account', 1 );
			$user = get_user_by( 'id', $user_id );
		}
		if ( ! $user ) { self::redirect( 'link-sent' ); }
		// A loyalty email link must never become an alternate passwordless route
		// into an existing staff/administrator account that happens to share the
		// same email. Only deliberate low-privilege loyalty accounts are eligible.
		if ( ! get_user_meta( $user->ID, 'doughboss_loyalty_account', true ) && ! in_array( 'subscriber', (array) $user->roles, true ) ) {
			self::redirect( 'link-sent' );
		}
		update_user_meta( $user->ID, 'doughboss_loyalty_account', 1 );
		self::member_for_user( (int) $user->ID, true );
		if ( ! empty( $_POST['marketing_consent'] ) ) {
			update_user_meta( $user->ID, 'doughboss_marketing_consent', current_time( 'mysql', true ) );
		}
		$selector = strtolower( wp_generate_password( 12, false, false ) );
		$token    = wp_generate_password( 48, true, true );
		global $wpdb;
		$wpdb->insert( self::tokens_table(), array( 'selector' => $selector, 'token_hash' => hash( 'sha256', $token ), 'user_id' => $user->ID, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + ( 20 * MINUTE_IN_SECONDS ) ), 'created_at' => current_time( 'mysql', true ) ), array( '%s', '%s', '%d', '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$url = add_query_arg( array( 'action' => 'doughboss_loyalty_magic_link', 's' => rawurlencode( $selector ), 't' => rawurlencode( $token ) ), admin_url( 'admin-post.php' ) );
		wp_mail( $email, __( 'Your Dough Boss Rewards sign-in link', 'doughboss' ), sprintf( "%s\n\n%s\n\n%s", __( 'Use this secure link to sign in to Dough Boss Rewards:', 'doughboss' ), esc_url_raw( $url ), __( 'This link expires in 20 minutes. If you did not request it, you can ignore this email.', 'doughboss' ) ) );
		self::redirect( 'link-sent' );
	}

	/** Consume a link exactly once and create a normal WP session. */
	public function magic_link() {
		$selector = isset( $_GET['s'] ) ? sanitize_key( wp_unslash( $_GET['s'] ) ) : '';
		$token = isset( $_GET['t'] ) ? (string) wp_unslash( $_GET['t'] ) : '';
		global $wpdb;
		$row = $selector ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::tokens_table() . ' WHERE selector = %s', $selector ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! $row || $row->consumed_at || strtotime( $row->expires_at . ' UTC' ) < time() || ! hash_equals( (string) $row->token_hash, hash( 'sha256', $token ) ) ) {
			self::redirect( 'expired' );
		}
		$used = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::tokens_table() . ' SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL', current_time( 'mysql', true ), $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( 1 !== (int) $used ) { self::redirect( 'expired' ); }
		if ( ! get_user_meta( (int) $row->user_id, 'doughboss_loyalty_account', true ) ) { self::redirect( 'expired' ); }
		wp_set_current_user( (int) $row->user_id );
		wp_set_auth_cookie( (int) $row->user_id, true, is_ssl() );
		self::redirect( 'linked' );
	}

	/** Award exactly once per paid eligible member order. */
	public function maybe_award_order( $order_id ) {
		if ( ! self::enabled() ) { return; }
		$order = DoughBoss_Order::get( $order_id );
		if ( ! $order || 'paid' !== $order->payment_status || 'cancelled' === $order->status || ! is_email( $order->customer_email ) ) { return; }
		$member = self::member_for_email( $order->customer_email );
		if ( ! $member || self::ledger_exists( 'order:' . (int) $order->id ) ) { return; }
		$spend = max( 0, round( (float) $order->subtotal - (float) $order->discount, 2 ) );
		$base = (int) floor( $spend * max( 1, (int) DoughBoss_Settings::get( 'loyalty_points_per_dollar', 1 ) ) );
		if ( $base < 1 ) { return; }
		$award = self::award_for_order( $member, $order, $base, $spend );
		self::record_points( $member, $award['points'], 'order_earn', 'order:' . (int) $order->id, $spend, (string) $order->currency, $award['campaign'], array( 'order_id' => (int) $order->id, 'base_points' => $base, 'promo_points' => $award['points'] - $base ) );
	}

	/** @param int $order_id @param string $old @param string $new */
	public function payment_status_changed( $order_id, $old, $new ) {
		if ( 'paid' === $new && 'paid' !== $old ) { $this->maybe_award_order( $order_id ); }
		if ( 'refunded' === $new && 'paid' === $old ) { self::reverse_order_points( $order_id ); }
	}

	/** Redeem the fixed rewards value to the established one-time voucher system. */
	public function redeem() {
		if ( ! is_user_logged_in() || ! check_admin_referer( 'doughboss_loyalty_redeem' ) || ! self::enabled() ) { self::redirect( 'error' ); }
		$member = self::member_for_user( get_current_user_id(), false );
		$cost = max( 1, (int) DoughBoss_Settings::get( 'loyalty_redemption_points', 100 ) );
		$value = max( 0.01, (float) DoughBoss_Settings::get( 'loyalty_redemption_amount', 5 ) );
		if ( ! $member || (int) $member->points_balance < $cost ) { self::redirect( 'error' ); }
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		try {
			$voucher = DoughBoss_Voucher::issue( array( 'type' => 'amount', 'value' => $value, 'prefix' => 'DBR', 'currency' => DoughBoss_Settings::get( 'currency_code', 'AUD' ), 'scope' => 'both', 'single_use' => 1, 'customer_email' => $member->email, 'campaign' => 'loyalty_rewards', 'meta' => array( 'member_id' => (int) $member->id, 'points_cost' => $cost ) ) );
			if ( is_wp_error( $voucher ) ) { throw new Exception( 'voucher' ); }
			$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::members_table() . ' SET points_balance = points_balance - %d, lifetime_redeemed = lifetime_redeemed + %d, updated_at = %s WHERE id = %d AND points_balance >= %d', $cost, $cost, current_time( 'mysql', true ), $member->id, $cost ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( 1 !== (int) $updated || ! self::insert_ledger( $member->id, -$cost, 'reward_redeem', 'reward:' . (int) $voucher['id'], 0, DoughBoss_Settings::get( 'currency_code', 'AUD' ), 'rewards_wallet', array( 'voucher_id' => (int) $voucher['id'], 'voucher_code' => $voucher['code'] ) ) ) { throw new Exception( 'ledger' ); }
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::redirect( 'redeemed' );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::redirect( 'error' );
		}
	}

	/** @return object|null */
	public static function member_for_user( $user_id, $create = false ) {
		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) { return null; }
		$member = self::member_for_email( $user->user_email );
		if ( ! $member && $create ) {
			global $wpdb;
			$wpdb->insert( self::members_table(), array( 'user_id' => (int) $user->ID, 'email' => sanitize_email( $user->user_email ), 'status' => 'active', 'points_balance' => 0, 'tier' => 'Dough Club', 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ), array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$member = self::member_for_email( $user->user_email );
			update_user_meta( $user->ID, 'doughboss_loyalty_account', 1 );
		}
		return $member;
	}

	/** @return object|null */
	public static function member_for_email( $email ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::members_table() . ' WHERE email = %s', sanitize_email( $email ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** @return array{points:int,campaign:string} */
	private static function award_for_order( $member, $order, $base, $spend ) {
		$selected = array( 'points' => $base, 'campaign' => '' );
		foreach ( self::promotions() as $promo ) {
			if ( empty( $promo['active'] ) || empty( $promo['type'] ) ) { continue; }
			if ( 'welcome_first_paid' === $promo['type'] && ! self::has_order_earnings( $member->id ) ) {
				$selected = array( 'points' => $base + max( 0, (int) $promo['bonus_points'] ), 'campaign' => sanitize_key( $promo['slug'] ) );
				break;
			}
			if ( 'weekly_multiplier' === $promo['type'] && 'tuesday' === strtolower( wp_date( 'l' ) ) && $spend >= (float) $promo['min_spend'] ) {
				$selected = array( 'points' => $base * max( 1, (int) $promo['multiplier'] ), 'campaign' => sanitize_key( $promo['slug'] ) );
				break;
			}
			if ( 'date_multiplier' === $promo['type'] && ! empty( $promo['starts'] ) && ! empty( $promo['ends'] ) && current_time( 'Y-m-d' ) >= $promo['starts'] && current_time( 'Y-m-d' ) <= $promo['ends'] ) {
				$selected = array( 'points' => $base * max( 1, (int) $promo['multiplier'] ), 'campaign' => sanitize_key( $promo['slug'] ) );
				break;
			}
		}
		return $selected;
	}

	/** @return bool */
	private static function record_points( $member, $points, $type, $event_key, $amount, $currency, $campaign, array $meta ) {
		if ( 0 === (int) $points || self::ledger_exists( $event_key ) ) { return false; }
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		try {
			if ( ! self::insert_ledger( $member->id, $points, $type, $event_key, $amount, $currency, $campaign, $meta ) ) { throw new Exception( 'ledger' ); }
			$earned_delta = in_array( $type, array( 'order_earn', 'refund_reverse' ), true ) ? $points : 0;
			$spend_delta  = in_array( $type, array( 'order_earn', 'refund_reverse' ), true ) ? $amount : 0;
			$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::members_table() . ' SET points_balance = points_balance + %d, lifetime_earned = GREATEST(0, lifetime_earned + %d), lifetime_spend = GREATEST(0, lifetime_spend + %f), updated_at = %s WHERE id = %d', $points, $earned_delta, $spend_delta, current_time( 'mysql', true ), $member->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( 1 !== (int) $updated ) { throw new Exception( 'member' ); }
			self::refresh_tier( $member->id );
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return true;
		} catch ( Throwable $e ) { $wpdb->query( 'ROLLBACK' ); return false; } // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** @return bool */
	private static function insert_ledger( $member_id, $points, $type, $event_key, $amount, $currency, $campaign, array $meta ) {
		global $wpdb;
		return (bool) $wpdb->insert( self::ledger_table(), array( 'member_id' => (int) $member_id, 'event_type' => sanitize_key( $type ), 'points' => (int) $points, 'event_key' => substr( sanitize_text_field( $event_key ), 0, 191 ), 'amount' => (float) $amount, 'currency' => substr( strtoupper( sanitize_text_field( $currency ) ), 0, 3 ), 'campaign' => substr( sanitize_key( $campaign ), 0, 40 ), 'meta' => wp_json_encode( $meta ), 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%s', '%d', '%s', '%f', '%s', '%s', '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function ledger_exists( $event_key ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::ledger_table() . ' WHERE event_key = %s', $event_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	private static function has_order_earnings( $member_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::ledger_table() . " WHERE member_id = %d AND event_type = 'order_earn' LIMIT 1", $member_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	private static function refresh_tier( $member_id ) {
		global $wpdb;
		$spend = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM " . self::ledger_table() . " WHERE member_id = %d AND event_type = 'order_earn' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 365 DAY)", $member_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$tier = $spend >= (float) DoughBoss_Settings::get( 'loyalty_tier_boss_spend', 400 ) ? 'Boss Member' : ( $spend >= (float) DoughBoss_Settings::get( 'loyalty_tier_fresh_spend', 150 ) ? 'Fresh Regular' : 'Dough Club' );
		$wpdb->update( self::members_table(), array( 'tier' => $tier, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $member_id ), array( '%s', '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function reverse_order_points( $order_id ) {
		global $wpdb;
		$earn = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::ledger_table() . ' WHERE event_key = %s', 'order:' . (int) $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! $earn || self::ledger_exists( 'refund:' . (int) $order_id ) ) { return; }
		self::record_points( (object) array( 'id' => $earn->member_id ), -(int) $earn->points, 'refund_reverse', 'refund:' . (int) $order_id, -(float) $earn->amount, $earn->currency, 'refund_reverse', array( 'order_id' => (int) $order_id ) );
	}

	/** @return array */
	private static function recent_vouchers( $email ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT code,value FROM {$wpdb->prefix}doughboss_vouchers WHERE customer_email = %s AND campaign = 'loyalty_rewards' AND status = 'issued' ORDER BY id DESC LIMIT 10", sanitize_email( $email ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function request_allowed( $email ) {
		$key = 'db_loyalty_link_' . hash( 'sha256', strtolower( $email ) );
		$count = (int) get_transient( $key );
		if ( $count >= 3 ) { return false; }
		set_transient( $key, $count + 1, 15 * MINUTE_IN_SECONDS );
		return true;
	}

	private static function unique_username( $email ) {
		$base = sanitize_user( strstr( $email, '@', true ), true );
		$base = $base ? $base : 'doughboss-member';
		$username = $base;
		for ( $i = 2; username_exists( $username ); $i++ ) { $username = $base . $i; }
		return $username;
	}

	private static function redirect( $notice ) {
		wp_safe_redirect( add_query_arg( 'db_loyalty', sanitize_key( $notice ), home_url( '/' . self::PAGE_SLUG . '/' ) ) );
		exit;
	}

	/** Register the owner console. */
	public function admin_menu() {
		add_submenu_page( 'doughboss', __( 'Dough Boss Rewards', 'doughboss' ), __( 'Rewards', 'doughboss' ), current_user_can( 'manage_doughboss' ) ? 'manage_doughboss' : 'manage_options', 'doughboss-rewards', array( $this, 'admin_page' ) );
	}

	public function admin_page() {
		if ( ! current_user_can( 'manage_doughboss' ) && ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to manage rewards.', 'doughboss' ) ); }
		$settings = DoughBoss_Settings::all();
		$members = $this->member_count();
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Dough Boss Rewards', 'doughboss' ); ?></h1>
		<p><?php esc_html_e( 'Passwordless member accounts, controlled points and one-time personal vouchers. Rewards remains independent from card payments and ordering.', 'doughboss' ); ?></p>
		<?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Rewards settings saved.', 'doughboss' ); ?></p></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="doughboss_save_loyalty_settings" /><?php wp_nonce_field( 'doughboss_save_loyalty_settings' ); ?>
		<table class="form-table"><tr><th><?php esc_html_e( 'Program status', 'doughboss' ); ?></th><td><label><input type="checkbox" name="loyalty_enabled" value="1" <?php checked( ! empty( $settings['loyalty_enabled'] ) ); ?> /> <?php esc_html_e( 'Enable points earning and redemptions', 'doughboss' ); ?></label><p class="description"><?php esc_html_e( 'Leave off while the launch content and staff process are reviewed.', 'doughboss' ); ?></p></td></tr>
		<tr><th><?php esc_html_e( 'Earning', 'doughboss' ); ?></th><td><input name="points_per_dollar" type="number" min="1" max="10" value="<?php echo esc_attr( $settings['loyalty_points_per_dollar'] ); ?>" /> <?php esc_html_e( 'points per $1 of paid food spend', 'doughboss' ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Reward', 'doughboss' ); ?></th><td><input name="redemption_points" type="number" min="10" step="10" value="<?php echo esc_attr( $settings['loyalty_redemption_points'] ); ?>" /> <?php esc_html_e( 'points for $', 'doughboss' ); ?><input name="redemption_amount" type="number" min="1" step="0.50" value="<?php echo esc_attr( $settings['loyalty_redemption_amount'] ); ?>" /> <?php esc_html_e( 'personal voucher', 'doughboss' ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Tiers', 'doughboss' ); ?></th><td><?php esc_html_e( 'Dough Club (free) → Fresh Regular at $', 'doughboss' ); ?><input name="fresh_spend" type="number" min="1" value="<?php echo esc_attr( $settings['loyalty_tier_fresh_spend'] ); ?>" /> <?php esc_html_e( '→ Boss Member at $', 'doughboss' ); ?><input name="boss_spend" type="number" min="1" value="<?php echo esc_attr( $settings['loyalty_tier_boss_spend'] ); ?>" /> <?php esc_html_e( 'paid spend in the last 12 months.', 'doughboss' ); ?></td></tr></table>
		<p class="submit"><button class="button button-primary" type="submit"><?php esc_html_e( 'Save rewards settings', 'doughboss' ); ?></button></p>
		<h2><?php esc_html_e( 'Launch promotions', 'doughboss' ); ?></h2><p><?php esc_html_e( 'A customer can receive only one earning promotion per paid order; welcome points are issued on the first eligible paid order. Promotions still cannot run until the parent program is enabled above.', 'doughboss' ); ?></p><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Promotion', 'doughboss' ); ?></th><th><?php esc_html_e( 'Rule', 'doughboss' ); ?></th><th><?php esc_html_e( 'Controls', 'doughboss' ); ?></th></tr></thead><tbody><?php foreach ( self::promotions() as $index => $promo ) : ?><tr><td><strong><?php echo esc_html( $promo['title'] ); ?></strong><br><small><?php echo esc_html( $promo['description'] ); ?></small><input type="hidden" name="promos[<?php echo esc_attr( $index ); ?>][slug]" value="<?php echo esc_attr( $promo['slug'] ); ?>" /></td><td><?php echo esc_html( $promo['rule'] ); ?></td><td><label><input type="checkbox" name="promos[<?php echo esc_attr( $index ); ?>][active]" value="1" <?php checked( ! empty( $promo['active'] ) ); ?> /> <?php esc_html_e( 'Prepared', 'doughboss' ); ?></label><?php if ( 'welcome_first_paid' === $promo['type'] ) : ?><br><label><?php esc_html_e( 'Bonus points', 'doughboss' ); ?> <input type="number" name="promos[<?php echo esc_attr( $index ); ?>][bonus_points]" min="0" max="1000" value="<?php echo esc_attr( $promo['bonus_points'] ); ?>" /></label><?php elseif ( 'date_multiplier' === $promo['type'] ) : ?><br><label><?php esc_html_e( 'Start', 'doughboss' ); ?> <input type="date" name="promos[<?php echo esc_attr( $index ); ?>][starts]" value="<?php echo esc_attr( $promo['starts'] ); ?>" /></label><br><label><?php esc_html_e( 'End', 'doughboss' ); ?> <input type="date" name="promos[<?php echo esc_attr( $index ); ?>][ends]" value="<?php echo esc_attr( $promo['ends'] ); ?>" /></label><?php elseif ( 'weekly_multiplier' === $promo['type'] ) : ?><br><label><?php esc_html_e( 'Minimum spend $', 'doughboss' ); ?> <input type="number" step="1" min="0" name="promos[<?php echo esc_attr( $index ); ?>][min_spend]" value="<?php echo esc_attr( $promo['min_spend'] ); ?>" /></label><?php endif; ?></td></tr><?php endforeach; ?></tbody></table>
		<p class="submit"><button class="button button-primary" type="submit"><?php esc_html_e( 'Save rewards settings and promotions', 'doughboss' ); ?></button></p></form>
		<h2><?php esc_html_e( 'Member operations', 'doughboss' ); ?></h2><p><?php printf( esc_html__( '%d member account(s). Manual adjustments always leave an audit entry; use only for approved service recovery.', 'doughboss' ), $members ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="doughboss_loyalty_adjust" /><?php wp_nonce_field( 'doughboss_loyalty_adjust' ); ?><input name="email" type="email" required placeholder="member@example.com" /> <input name="points" type="number" required placeholder="+/- points" /> <input name="reason" type="text" required maxlength="120" placeholder="Reason" /> <button class="button" type="submit"><?php esc_html_e( 'Record adjustment', 'doughboss' ); ?></button></form>
		<form style="margin-top:18px" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="doughboss_create_loyalty_page" /><?php wp_nonce_field( 'doughboss_create_loyalty_page' ); ?><button class="button" type="submit"><?php esc_html_e( 'Create / open Rewards page', 'doughboss' ); ?></button></form></div>
		<?php
	}

	public function save_settings() {
		$this->require_admin( 'doughboss_save_loyalty_settings' );
		DoughBoss_Settings::update( array( 'loyalty_enabled' => ! empty( $_POST['loyalty_enabled'] ) ? 1 : 0, 'loyalty_points_per_dollar' => max( 1, min( 10, absint( $_POST['points_per_dollar'] ?? 1 ) ) ), 'loyalty_redemption_points' => max( 10, min( 10000, absint( $_POST['redemption_points'] ?? 100 ) ) ), 'loyalty_redemption_amount' => max( 1, min( 100, (float) ( $_POST['redemption_amount'] ?? 5 ) ), 'loyalty_tier_fresh_spend' => max( 1, (float) ( $_POST['fresh_spend'] ?? 150 ) ), 'loyalty_tier_boss_spend' => max( 1, (float) ( $_POST['boss_spend'] ?? 400 ) ), 'loyalty_promos' => self::sanitize_promos( isset( $_POST['promos'] ) && is_array( $_POST['promos'] ) ? wp_unslash( $_POST['promos'] ) : array() ) ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'doughboss-rewards', 'updated' => 1 ), admin_url( 'admin.php' ) ) ); exit;
	}

	public function create_page() {
		$this->require_admin( 'doughboss_create_loyalty_page' );
		$page = get_page_by_path( self::PAGE_SLUG );
		if ( ! $page ) { $id = wp_insert_post( array( 'post_title' => __( 'Rewards', 'doughboss' ), 'post_name' => self::PAGE_SLUG, 'post_content' => '[doughboss_loyalty]', 'post_status' => 'publish', 'post_type' => 'page' ) ); $page = get_post( $id ); }
		wp_safe_redirect( $page ? get_permalink( $page ) : admin_url( 'admin.php?page=doughboss-rewards' ) ); exit;
	}

	public function adjust_member() {
		$this->require_admin( 'doughboss_loyalty_adjust' );
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ); $points = (int) ( $_POST['points'] ?? 0 ); $reason = sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) );
		$member = self::member_for_email( $email );
		if ( $member && $points && $reason ) { self::record_points( $member, $points, 'admin_adjust', 'adjust:' . wp_generate_uuid4(), 0, DoughBoss_Settings::get( 'currency_code', 'AUD' ), 'manual', array( 'reason' => $reason, 'actor_id' => get_current_user_id() ) ); }
		wp_safe_redirect( add_query_arg( array( 'page' => 'doughboss-rewards', 'updated' => 1 ), admin_url( 'admin.php' ) ) ); exit;
	}

	private function require_admin( $nonce ) { if ( ! current_user_can( 'manage_doughboss' ) && ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to manage rewards.', 'doughboss' ) ); } check_admin_referer( $nonce ); }
	private function member_count() { global $wpdb; return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::members_table() ); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	/** Preserve the fixed three-promotion structure while allowing safe values. */
	private static function sanitize_promos( array $submitted ) {
		$promos = self::promotions();
		foreach ( $promos as $index => $promo ) {
			$raw = isset( $submitted[ $index ] ) && is_array( $submitted[ $index ] ) ? $submitted[ $index ] : array();
			$promos[ $index ]['active'] = ! empty( $raw['active'] ) ? 1 : 0;
			if ( 'welcome_first_paid' === $promo['type'] ) { $promos[ $index ]['bonus_points'] = max( 0, min( 1000, absint( $raw['bonus_points'] ?? $promo['bonus_points'] ) ) ); }
			if ( 'weekly_multiplier' === $promo['type'] ) { $promos[ $index ]['min_spend'] = max( 0, min( 500, (float) ( $raw['min_spend'] ?? $promo['min_spend'] ) ) ); }
			if ( 'date_multiplier' === $promo['type'] ) {
				$start = isset( $raw['starts'] ) ? sanitize_text_field( $raw['starts'] ) : '';
				$end   = isset( $raw['ends'] ) ? sanitize_text_field( $raw['ends'] ) : '';
				$promos[ $index ]['starts'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ? $start : '';
				$promos[ $index ]['ends']   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ? $end : '';
				if ( '' !== $promos[ $index ]['starts'] && '' !== $promos[ $index ]['ends'] && $promos[ $index ]['ends'] < $promos[ $index ]['starts'] ) { $promos[ $index ]['active'] = 0; }
			}
		}
		return $promos;
	}
}
