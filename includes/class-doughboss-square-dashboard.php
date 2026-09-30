<?php
/**
 * Read-only Square dashboard contract and explicit synthetic preview.
 *
 * @package DoughBoss
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DoughBoss_Square_Dashboard {
	/** Build an owner-only view without database writes or provider requests. */
	public static function view( $mode = 'status', $location = 'all' ) {
		$shops = array( 'revesby' => 'Revesby', 'bankstown' => 'Bankstown', 'roselands' => 'Roselands' );
		if ( ! is_string( $mode ) || ! in_array( $mode, array( 'status', 'demo' ), true )
			|| ! is_string( $location ) || ( 'all' !== $location && ! isset( $shops[ $location ] ) ) ) {
			return new WP_Error( 'doughboss_square_dashboard_input', __( 'Choose a supported dashboard view and location.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$credentials_present = '' !== DoughBoss_Settings::square_application_id()
			&& '' !== DoughBoss_Settings::square_location_id()
			&& DoughBoss_Settings::square_access_token_valid();
		$view = array(
			'schema_version' => 1,
			'source' => 'square',
			'data_state' => 'not_connected',
			'connection' => array(
				'state' => $credentials_present ? 'configured_unverified' : 'not_configured',
				'environment' => DoughBoss_Settings::square_mode(),
				'credentials_present' => (bool) $credentials_present,
				'provider_verified' => false,
			),
			'staff' => array( 'managed_by' => 'square', 'attendance_available' => false ),
			'planned_location' => array( 'label' => 'Planned location', 'state' => 'private_unmapped' ),
			'locations' => array(),
		);
		foreach ( $shops as $id => $name ) {
			$view['locations'][] = array( 'id' => $id, 'name' => $name, 'state' => 'unmapped' );
		}
		if ( 'status' === $mode ) {
			return $view;
		}
		$view['source'] = 'synthetic';
		$view['data_state'] = 'demo';
		$view['demo'] = true;
		$view['currency'] = 'AUD';
		$view['date_label'] = 'Illustrative day (synthetic)';
		$view['selected_location'] = $location;
		$view['summary'] = self::empty_summary();
		$view['location_summaries'] = array();
		$view['orders'] = array();
		$view['sales_series'] = array();
		foreach ( array( '06:00', '08:00', '10:00', '12:00' ) as $label ) {
			$view['sales_series'][] = array( 'label' => $label, 'gross_sales_minor' => 0 );
		}
		foreach ( $shops as $id => $name ) {
			if ( 'all' !== $location && $id !== $location ) {
				continue;
			}
			$shop_summary = self::empty_summary();
			foreach ( self::demo_orders( $id, $name ) as $index => $order ) {
				$view['orders'][] = $order;
				if ( ! $order['paid'] ) {
					continue;
				}
				$shop_summary['gross_sales_minor'] += $order['amount_minor'];
				$shop_summary['refunds_minor'] += $order['refunded_minor'];
				++$shop_summary['orders'];
				$view['sales_series'][ $index ]['gross_sales_minor'] += $order['amount_minor'];
			}
			self::finish_summary( $shop_summary );
			$view['location_summaries'][] = array_merge( array( 'id' => $id, 'name' => $name ), $shop_summary );
			foreach ( array( 'gross_sales_minor', 'refunds_minor', 'orders' ) as $key ) {
				$view['summary'][ $key ] += $shop_summary[ $key ];
			}
		}
		self::finish_summary( $view['summary'] );
		return $view;
	}

	private static function empty_summary() {
		return array( 'gross_sales_minor' => 0, 'refunds_minor' => 0, 'net_sales_minor' => 0, 'orders' => 0, 'average_order_minor' => 0 );
	}

	private static function finish_summary( array &$summary ) {
		$summary['net_sales_minor'] = $summary['gross_sales_minor'] - $summary['refunds_minor'];
		$summary['average_order_minor'] = $summary['orders'] > 0
			? intdiv( 2 * $summary['gross_sales_minor'] + $summary['orders'], 2 * $summary['orders'] ) : 0;
	}

	/** Deliberately fictional records; never inserted into order or staff storage. */
	private static function demo_orders( $id, $name ) {
		$recipes = array(
			array( 'Zaatar', 450, 3, 'Ready for pickup', true, 0 ),
			array( 'Zaatar & Cheese', 850, 2, 'Preparing', true, 0 ),
			array( 'Zaatar', 450, 2, 'Partially refunded', true, 450 ),
			array( 'Zaatar & Cheese', 850, 1, 'Awaiting approval', false, 0 ),
		);
		$orders = array();
		foreach ( $recipes as $index => $recipe ) {
			$orders[] = array(
				'id' => 'DEMO-' . strtoupper( $id ) . '-' . ( $index + 1 ),
				'location_id' => $id,
				'location_name' => $name,
				'customer' => 'Demo customer ' . ( $index + 1 ),
				'status' => $recipe[3],
				'paid' => $recipe[4],
				'refunded_minor' => $recipe[5],
				'amount_minor' => $recipe[1] * $recipe[2],
				'items' => array( array( 'name' => $recipe[0], 'quantity' => $recipe[2], 'unit_price_minor' => $recipe[1] ) ),
			);
		}
		return $orders;
	}
}
