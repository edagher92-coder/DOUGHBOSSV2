<?php
/**
 * WordPress core Privacy Tools integration.
 *
 * Registers personal-data exporters and erasers (Tools → Export/Erase Personal
 * Data) for the three custom tables that hold customer PII: orders, catering
 * enquiries and vouchers, all keyed on customer_email. Erasure REDACTS the PII
 * fields in place (name/email/phone/address → placeholders) and never deletes
 * rows — order numbers and financial totals must survive an erasure request
 * (AU tax law expects ~5-year retention of transaction records).
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Personal-data exporters and erasers for DoughBoss customer records.
 */
class DoughBoss_Privacy {

	/**
	 * Rows processed per exporter/eraser pass (core calls back until done).
	 */
	const PAGE_SIZE = 100;

	/**
	 * Register with core's privacy tools.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_erasers' ) );
	}

	/**
	 * Add our exporters to the registry.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public function register_exporters( $exporters ) {
		$exporters['doughboss-orders'] = array(
			'exporter_friendly_name' => __( 'DoughBoss Orders', 'doughboss' ),
			'callback'               => array( $this, 'export_orders' ),
		);
		$exporters['doughboss-catering'] = array(
			'exporter_friendly_name' => __( 'DoughBoss Catering Enquiries', 'doughboss' ),
			'callback'               => array( $this, 'export_catering' ),
		);
		$exporters['doughboss-vouchers'] = array(
			'exporter_friendly_name' => __( 'DoughBoss Vouchers', 'doughboss' ),
			'callback'               => array( $this, 'export_vouchers' ),
		);
		return $exporters;
	}

	/**
	 * Add our erasers to the registry.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function register_erasers( $erasers ) {
		$erasers['doughboss-orders'] = array(
			'eraser_friendly_name' => __( 'DoughBoss Orders', 'doughboss' ),
			'callback'             => array( $this, 'erase_orders' ),
		);
		$erasers['doughboss-catering'] = array(
			'eraser_friendly_name' => __( 'DoughBoss Catering Enquiries', 'doughboss' ),
			'callback'             => array( $this, 'erase_catering' ),
		);
		$erasers['doughboss-vouchers'] = array(
			'eraser_friendly_name' => __( 'DoughBoss Vouchers', 'doughboss' ),
			'callback'             => array( $this, 'erase_vouchers' ),
		);
		return $erasers;
	}

	/**
	 * Fetch one page of rows matching the requested email from a table.
	 *
	 * @param string $table Full table name (built from $wpdb->prefix).
	 * @param string $email Requested email address.
	 * @param int    $page  1-based page number (0 offset = always-first-page).
	 * @return object[]
	 */
	private function rows_for_email( $table, $email, $page ) {
		global $wpdb;
		$email = sanitize_email( $email );
		if ( '' === $email ) {
			return array();
		}
		$offset = ( max( 1, (int) $page ) - 1 ) * self::PAGE_SIZE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE customer_email = %s ORDER BY id ASC LIMIT %d OFFSET %d", $email, self::PAGE_SIZE, $offset )
		);
	}

	/**
	 * Exporter: orders placed with the requested email.
	 *
	 * @param string $email_address Requested email.
	 * @param int    $page          Page number.
	 * @return array{data:array,done:bool}
	 */
	public function export_orders( $email_address, $page = 1 ) {
		global $wpdb;
		$rows  = $this->rows_for_email( $wpdb->prefix . 'doughboss_orders', $email_address, $page );
		$items = array();

		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'    => 'doughboss_orders',
				'group_label' => __( 'DoughBoss Orders', 'doughboss' ),
				'item_id'     => 'doughboss-order-' . (int) $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Order number', 'doughboss' ),
						'value' => $row->order_number,
					),
					array(
						'name'  => __( 'Customer name', 'doughboss' ),
						'value' => $row->customer_name,
					),
					array(
						'name'  => __( 'Email', 'doughboss' ),
						'value' => $row->customer_email,
					),
					array(
						'name'  => __( 'Phone', 'doughboss' ),
						'value' => $row->customer_phone,
					),
					array(
						'name'  => __( 'Delivery address', 'doughboss' ),
						'value' => (string) $row->address,
					),
					array(
						'name'  => __( 'Notes', 'doughboss' ),
						'value' => (string) $row->notes,
					),
					array(
						'name'  => __( 'Order type', 'doughboss' ),
						'value' => $row->order_type,
					),
					array(
						'name'  => __( 'Dining table', 'doughboss' ),
						'value' => isset( $row->table_label ) ? (string) $row->table_label : '',
					),
					array(
						'name'  => __( 'Total', 'doughboss' ),
						'value' => $row->total . ' ' . $row->currency,
					),
					array(
						'name'  => __( 'Placed at', 'doughboss' ),
						'value' => (string) $row->created_at,
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => count( $rows ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Exporter: catering enquiries made with the requested email.
	 *
	 * @param string $email_address Requested email.
	 * @param int    $page          Page number.
	 * @return array{data:array,done:bool}
	 */
	public function export_catering( $email_address, $page = 1 ) {
		global $wpdb;
		$rows  = $this->rows_for_email( $wpdb->prefix . 'doughboss_catering_enquiries', $email_address, $page );
		$items = array();

		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'    => 'doughboss_catering',
				'group_label' => __( 'DoughBoss Catering Enquiries', 'doughboss' ),
				'item_id'     => 'doughboss-catering-' . (int) $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Enquiry number', 'doughboss' ),
						'value' => $row->enquiry_number,
					),
					array(
						'name'  => __( 'Customer name', 'doughboss' ),
						'value' => $row->customer_name,
					),
					array(
						'name'  => __( 'Email', 'doughboss' ),
						'value' => $row->customer_email,
					),
					array(
						'name'  => __( 'Phone', 'doughboss' ),
						'value' => $row->customer_phone,
					),
					array(
						'name'  => __( 'Event address', 'doughboss' ),
						'value' => (string) $row->address,
					),
					array(
						'name'  => __( 'Event date', 'doughboss' ),
						'value' => (string) $row->event_date,
					),
					array(
						'name'  => __( 'Dietary requirements', 'doughboss' ),
						'value' => (string) $row->dietary,
					),
					array(
						'name'  => __( 'Notes', 'doughboss' ),
						'value' => (string) $row->notes,
					),
					array(
						'name'  => __( 'Quote total', 'doughboss' ),
						'value' => $row->quote_total . ' ' . $row->currency,
					),
					array(
						'name'  => __( 'Enquired at', 'doughboss' ),
						'value' => (string) $row->created_at,
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => count( $rows ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Exporter: vouchers issued to the requested email.
	 *
	 * @param string $email_address Requested email.
	 * @param int    $page          Page number.
	 * @return array{data:array,done:bool}
	 */
	public function export_vouchers( $email_address, $page = 1 ) {
		global $wpdb;
		$rows  = $this->rows_for_email( $wpdb->prefix . 'doughboss_vouchers', $email_address, $page );
		$items = array();

		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'    => 'doughboss_vouchers',
				'group_label' => __( 'DoughBoss Vouchers', 'doughboss' ),
				'item_id'     => 'doughboss-voucher-' . (int) $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Voucher code', 'doughboss' ),
						'value' => $row->code,
					),
					array(
						'name'  => __( 'Email', 'doughboss' ),
						'value' => $row->customer_email,
					),
					array(
						'name'  => __( 'Phone', 'doughboss' ),
						'value' => $row->customer_phone,
					),
					array(
						'name'  => __( 'Value', 'doughboss' ),
						'value' => ( 'percent' === $row->type ) ? $row->value . '%' : $row->value . ' ' . $row->currency,
					),
					array(
						'name'  => __( 'Status', 'doughboss' ),
						'value' => $row->status,
					),
					array(
						'name'  => __( 'Issued at', 'doughboss' ),
						'value' => (string) $row->created_at,
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => count( $rows ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Redact the PII columns of every row matching the email in one table.
	 *
	 * Rows are never deleted — financial totals and order/enquiry numbers stay
	 * for accounting. Redacted rows stop matching the requested email, so each
	 * pass re-reads the first page until no matches remain.
	 *
	 * @param string $table   Full table name (built from $wpdb->prefix).
	 * @param string $email   Requested email.
	 * @param array  $updates Column => placeholder map to write.
	 * @param array  $formats wpdb formats for $updates.
	 * @return array{removed:int,done:bool}
	 */
	private function redact_rows( $table, $email, array $updates, array $formats ) {
		global $wpdb;
		$email = sanitize_email( $email );
		if ( '' === $email ) {
			return array(
				'removed' => 0,
				'done'    => true,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE customer_email = %s ORDER BY id ASC LIMIT %d", $email, self::PAGE_SIZE )
		);

		$removed = 0;
		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ok = $wpdb->update( $table, $updates, array( 'id' => (int) $id ), $formats, array( '%d' ) );
			if ( false !== $ok ) {
				++$removed;
			}
		}

		return array(
			'removed' => $removed,
			'done'    => count( $ids ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Eraser: redact PII on orders (rows retained for accounting).
	 *
	 * @param string $email_address Requested email.
	 * @param int    $page          Page number (unused — see redact_rows()).
	 * @return array
	 */
	public function erase_orders( $email_address, $page = 1 ) {
		unset( $page );
		global $wpdb;

		$result = $this->redact_rows(
			$wpdb->prefix . 'doughboss_orders',
			$email_address,
			array(
				'customer_name'  => __( 'Anonymous', 'doughboss' ),
				'customer_email' => wp_privacy_anonymize_data( 'email' ),
				'customer_phone' => '',
				'address'        => '',
				'notes'          => '',
				'updated_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return array(
			'items_removed'  => $result['removed'] > 0,
			'items_retained' => $result['removed'] > 0,
			'messages'       => $result['removed'] > 0
				? array( __( 'Order records were anonymised; order numbers and totals are retained for accounting.', 'doughboss' ) )
				: array(),
			'done'           => $result['done'],
		);
	}

	/**
	 * Eraser: redact PII on catering enquiries (rows retained for accounting).
	 *
	 * @param string $email_address Requested email.
	 * @param int    $page          Page number (unused — see redact_rows()).
	 * @return array
	 */
	public function erase_catering( $email_address, $page = 1 ) {
		unset( $page );
		global $wpdb;

		$result = $this->redact_rows(
			$wpdb->prefix . 'doughboss_catering_enquiries',
			$email_address,
			array(
				'customer_name'  => __( 'Anonymous', 'doughboss' ),
				'customer_email' => wp_privacy_anonymize_data( 'email' ),
				'customer_phone' => '',
				'address'        => '',
				'dietary'        => '',
				'notes'          => '',
				'updated_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return array(
			'items_removed'  => $result['removed'] > 0,
			'items_retained' => $result['removed'] > 0,
			'messages'       => $result['removed'] > 0
				? array( __( 'Catering enquiries were anonymised; enquiry numbers and totals are retained for accounting.', 'doughboss' ) )
				: array(),
			'done'           => $result['done'],
		);
	}

	/**
	 * Eraser: redact PII on vouchers (rows retained for redemption audit).
	 *
	 * @param string $email_address Requested email.
	 * @param int    $page          Page number (unused — see redact_rows()).
	 * @return array
	 */
	public function erase_vouchers( $email_address, $page = 1 ) {
		unset( $page );
		global $wpdb;

		$result = $this->redact_rows(
			$wpdb->prefix . 'doughboss_vouchers',
			$email_address,
			array(
				'customer_email' => wp_privacy_anonymize_data( 'email' ),
				'customer_phone' => '',
				'updated_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s' )
		);

		return array(
			'items_removed'  => $result['removed'] > 0,
			'items_retained' => $result['removed'] > 0,
			'messages'       => $result['removed'] > 0
				? array( __( 'Voucher records were anonymised; codes and values are retained for the redemption audit trail.', 'doughboss' ) )
				: array(),
			'done'           => $result['done'],
		);
	}
}
