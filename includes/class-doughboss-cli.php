<?php
/**
 * WP-CLI commands (loaded only under WP-CLI).
 *
 * Lets the owner exercise the POSPal connector and the voucher engine from the
 * host shell — where outbound network access exists and no web nonce is needed.
 * Secrets are never passed on the command line: the POSPal key is read from the
 * environment / settings by the connector itself.
 *
 *   wp doughboss pospal-test
 *   wp doughboss campaigns
 *   wp doughboss voucher-claim snow5 --phone=0400000000
 *   wp doughboss voucher-list --limit=20
 *   wp doughboss voucher-redeem SNOW-ABCD1234 --subtotal=25 --channel=online
 *   wp doughboss voucher-void 12
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

/**
 * DoughBoss CLI commands.
 */
class DoughBoss_CLI {

	/**
	 * Read-only POSPal connectivity check: lists the account's coupon promotion
	 * rules. Confirms the host, appId/appKey signing and that the discount
	 * coupons exist — without changing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp doughboss pospal-test
	 *
	 * @return void
	 */
	public static function pospal_test() {
		if ( ! DoughBoss_POSPal::ready() ) {
			WP_CLI::error( 'POSPal is not configured. Set pospal_host + pospal_app_id in settings and DOUGHBOSS_POSPAL_APPKEY in the environment, and enable POSPal.' );
		}

		$result = DoughBoss_POSPal::test_connection();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( 'POSPal call failed: ' . $result->get_error_message() );
		}

		WP_CLI::success( 'POSPal reachable and the signature was accepted.' );
		WP_CLI::log( 'Coupon promotion rules: ' . wp_json_encode( $result ) );
	}

	/**
	 * Show the daily voucher campaigns with today's claim counts (and shared-pool
	 * usage where campaigns share a cap_group).
	 *
	 * ## EXAMPLES
	 *
	 *     wp doughboss campaigns
	 *
	 * @return void
	 */
	public static function campaigns() {
		$rows = array();
		foreach ( DoughBoss_Voucher::campaigns() as $c ) {
			$cap     = (int) ( isset( $c['daily_cap'] ) ? $c['daily_cap'] : 0 );
			$used    = DoughBoss_Voucher::claimed_today_for( $c );
			$rows[]  = array(
				'slug'          => isset( $c['slug'] ) ? $c['slug'] : '',
				'label'         => isset( $c['label'] ) ? $c['label'] : '',
				'value'         => isset( $c['value'] ) ? $c['value'] : 0,
				'cap_group'     => isset( $c['cap_group'] ) ? $c['cap_group'] : '',
				'daily_cap'     => $cap > 0 ? $cap : '∞',
				'claimed_today' => DoughBoss_Voucher::claimed_today( isset( $c['slug'] ) ? $c['slug'] : '' ),
				'pool_used'     => $used,
				'remaining'     => $cap > 0 ? max( 0, $cap - $used ) : '∞',
				'active'        => empty( $c['active'] ) ? 'no' : 'yes',
			);
		}
		if ( empty( $rows ) ) {
			WP_CLI::log( 'No campaigns defined.' );
			return;
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'slug', 'label', 'value', 'cap_group', 'daily_cap', 'claimed_today', 'pool_used', 'remaining', 'active' ) );
	}

	/**
	 * Claim a voucher from a daily-capped campaign (enforces the shared daily
	 * pool) and print the issued code.
	 *
	 * ## OPTIONS
	 *
	 * <campaign>
	 * : Campaign slug, e.g. snow5 or snow10.
	 *
	 * [--phone=<phone>]
	 * : Customer phone (the POSPal member key).
	 *
	 * [--email=<email>]
	 * : Customer email.
	 *
	 * ## EXAMPLES
	 *
	 *     wp doughboss voucher-claim snow5 --phone=0400000000
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public static function voucher_claim( $args, $assoc_args ) {
		$campaign = isset( $args[0] ) ? $args[0] : '';
		if ( '' === $campaign ) {
			WP_CLI::error( 'Usage: wp doughboss voucher-claim <campaign> [--phone=] [--email=]' );
		}
		$result = DoughBoss_Voucher::claim(
			$campaign,
			array(
				'customer_phone' => isset( $assoc_args['phone'] ) ? $assoc_args['phone'] : '',
				'customer_email' => isset( $assoc_args['email'] ) ? $assoc_args['email'] : '',
			)
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Claimed "%s": code %s (id %d).', $campaign, $result['code'], $result['id'] ) );
	}

	/**
	 * List recent vouchers with their status and any redemption.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : How many to show (default 20).
	 *
	 * ## EXAMPLES
	 *
	 *     wp doughboss voucher-list --limit=20
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public static function voucher_list( $args, $assoc_args ) {
		$limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 20;
		$rows  = DoughBoss_Voucher::query( $limit );
		$items = array();
		foreach ( (array) $rows as $r ) {
			$items[] = array(
				'id'          => $r->id,
				'code'        => $r->code,
				'campaign'    => $r->campaign,
				'type'        => $r->type,
				'value'       => $r->value,
				'status'      => $r->status,
				'redeemed_at' => isset( $r->redeemed_at ) ? (string) $r->redeemed_at : '',
				'amount'      => isset( $r->amount_applied ) ? (string) $r->amount_applied : '',
				'created_at'  => $r->created_at,
			);
		}
		if ( empty( $items ) ) {
			WP_CLI::log( 'No vouchers yet.' );
			return;
		}
		WP_CLI\Utils\format_items( 'table', $items, array( 'id', 'code', 'campaign', 'type', 'value', 'status', 'redeemed_at', 'amount', 'created_at' ) );
	}

	/**
	 * Redeem a voucher (atomic single-use) against a given subtotal.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : The voucher code.
	 *
	 * --subtotal=<amount>
	 * : Server-side cart subtotal to apply against.
	 *
	 * [--channel=<channel>]
	 * : online (default) or instore.
	 *
	 * ## EXAMPLES
	 *
	 *     wp doughboss voucher-redeem SNOW-ABCD1234 --subtotal=25 --channel=online
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public static function voucher_redeem( $args, $assoc_args ) {
		$code = isset( $args[0] ) ? $args[0] : '';
		if ( '' === $code ) {
			WP_CLI::error( 'Usage: wp doughboss voucher-redeem <CODE> --subtotal=<amount> [--channel=online|instore]' );
		}
		$subtotal = isset( $assoc_args['subtotal'] ) ? (float) $assoc_args['subtotal'] : 0;
		$channel  = isset( $assoc_args['channel'] ) && 'instore' === $assoc_args['channel'] ? 'instore' : 'online';
		$result   = DoughBoss_Voucher::redeem( $code, $subtotal, $channel );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Redeemed %s — %s applied.', $result['code'], DoughBoss_Settings::format_price( $result['amount'] ) ) );
	}

	/**
	 * Void an issued (un-redeemed) voucher.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The voucher id.
	 *
	 * ## EXAMPLES
	 *
	 *     wp doughboss voucher-void 12
	 *
	 * @param array $args Positional args.
	 * @return void
	 */
	public static function voucher_void( $args ) {
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( ! $id ) {
			WP_CLI::error( 'Usage: wp doughboss voucher-void <ID>' );
		}
		if ( ! DoughBoss_Voucher::void( $id ) ) {
			WP_CLI::error( 'Could not void — voucher not found or not in the "issued" state.' );
		}
		WP_CLI::success( sprintf( 'Voucher voided: id %d.', $id ) );
	}
}

WP_CLI::add_command( 'doughboss pospal-test', array( 'DoughBoss_CLI', 'pospal_test' ) );
WP_CLI::add_command( 'doughboss campaigns', array( 'DoughBoss_CLI', 'campaigns' ) );
WP_CLI::add_command( 'doughboss voucher-claim', array( 'DoughBoss_CLI', 'voucher_claim' ) );
WP_CLI::add_command( 'doughboss voucher-list', array( 'DoughBoss_CLI', 'voucher_list' ) );
WP_CLI::add_command( 'doughboss voucher-redeem', array( 'DoughBoss_CLI', 'voucher_redeem' ) );
WP_CLI::add_command( 'doughboss voucher-void', array( 'DoughBoss_CLI', 'voucher_void' ) );
