<?php
/**
 * Durable approval-before-payment state for pre-order requests.
 *
 * This store deliberately has no payment-provider integration. Callers may
 * create an external payment attempt only after claim_payment() succeeds, and
 * must report uncertain outcomes through hold_payment().
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transactional state machine for approved pre-order requests.
 */
class DoughBoss_Preorder_Approval {

	/** @var string */
	const STATE_PENDING = 'pending';
	/** @var string */
	const STATE_APPROVED_UNPAID = 'approved_unpaid';
	/** @var string */
	const STATE_REJECTED = 'rejected';
	/** @var string */
	const STATE_PAYMENT_ACTIVE = 'payment_active';
	/** @var string */
	const STATE_PAYMENT_HELD = 'payment_held';
	/** @var string */
	const STATE_RELEASED = 'released';

	/**
	 * Create the one pending approval record for an eligible request.
	 *
	 * @param int $request_order_id Pre-order request order ID.
	 * @return array|WP_Error Approval row.
	 */
	public static function ensure_pending( $request_order_id ) {
		$request_order_id = absint( $request_order_id );
		$ready            = self::storage_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		if ( ! $request_order_id ) {
			return self::error( 'doughboss_preorder_request_invalid', __( 'A valid pre-order request is required.', 'doughboss' ), 400 );
		}

		$started = self::begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		try {
			$created = false;
			$order = self::locked_order( $request_order_id );
			$valid = self::validate_pending_request( $order );
			if ( is_wp_error( $valid ) ) {
				self::rollback();
				return $valid;
			}
			$row = self::locked_approval( $request_order_id );
			if ( ! $row ) {
				$now = self::now();
				global $wpdb;
				$inserted = $wpdb->insert(
					self::approvals_table(),
					array(
						'request_order_id' => $request_order_id,
						'state'            => self::STATE_PENDING,
						'version'          => 1,
						'created_at_utc'   => $now,
						'updated_at_utc'   => $now,
					),
					array( '%d', '%s', '%d', '%s', '%s' )
				);
				if ( false === $inserted ) {
					self::rollback();
					return self::error( 'doughboss_preorder_store_failed', __( 'The pre-order approval record could not be saved.', 'doughboss' ), 500 );
				}
				$created = true;
				$row = self::locked_approval( $request_order_id );
			}
			if ( ! self::known_state( $row->state ) ) {
				self::rollback();
				return self::error( 'doughboss_preorder_state_invalid', __( 'The pre-order approval state is invalid.', 'doughboss' ), 409 );
			}
			if ( ! self::commit() ) {
				return self::error( 'doughboss_preorder_store_failed', __( 'The pre-order approval record could not be committed.', 'doughboss' ), 500 );
			}
			$result            = self::row( $row );
			$result['created'] = $created;
			return $result;
		} catch ( Throwable $e ) {
			self::rollback();
			return self::error( 'doughboss_preorder_store_failed', __( 'The pre-order approval record could not be saved.', 'doughboss' ), 500 );
		}
	}

	/**
	 * Fetch a durable approval record without exposing capability material.
	 *
	 * @param int $request_order_id Pre-order request order ID.
	 * @return array|WP_Error Approval row.
	 */
	public static function get( $request_order_id ) {
		$request_order_id = absint( $request_order_id );
		$ready            = self::storage_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		if ( ! $request_order_id ) {
			return self::error( 'doughboss_preorder_request_invalid', __( 'A valid pre-order request is required.', 'doughboss' ), 400 );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::approvals_table() . ' WHERE request_order_id = %d', $request_order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row ) {
			return self::error( 'doughboss_preorder_approval_missing', __( 'No pre-order approval record exists for this request.', 'doughboss' ), 404 );
		}
		if ( ! self::known_state( $row->state ) ) {
			return self::error( 'doughboss_preorder_state_invalid', __( 'The pre-order approval state is invalid.', 'doughboss' ), 409 );
		}
		return self::row( $row );
	}

	/** Return the existing unexpired capability for the internal approver recovery route. */
	public static function recover_payment_token( $request_order_id ) {
		$row = self::approval( absint( $request_order_id ) );
		if ( ! $row || ! in_array( (string) $row->state, array( self::STATE_APPROVED_UNPAID, self::STATE_PAYMENT_ACTIVE ), true ) || self::expired( $row ) ) {
			return self::error( 'doughboss_preorder_payment_unavailable', __( 'Payment-link recovery is not available for this request.', 'doughboss' ), 409 );
		}
		$token = self::capability_token( (int) $row->request_order_id, (int) $row->approved_revision, (string) $row->request_fingerprint, (string) $row->payment_token_expires_at_utc );
		if ( ! $row->payment_token_hash || ! hash_equals( (string) $row->payment_token_hash, hash( 'sha256', $token ) ) ) {
			return self::error( 'doughboss_preorder_payment_unavailable', __( 'Payment-link recovery is not available for this request.', 'doughboss' ), 409 );
		}
		return $token;
	}

	/** Persist only the customer mail acceptance result; approval state remains immutable. */
	public static function record_payment_link_delivery( $request_order_id, $accepted ) {
		global $wpdb;
		return false !== $wpdb->update(
			self::approvals_table(),
			array(
				'payment_link_delivery_status' => $accepted ? 'accepted' : 'failed',
				'payment_link_delivery_attempted_at_utc' => self::now(),
			),
			array( 'request_order_id' => absint( $request_order_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Record one irreversible staff approval or rejection decision.
	 *
	 * @param int    $request_order_id Pre-order request order ID.
	 * @param string $decision         approve or reject.
	 * @param int    $actor_user_id    Non-zero staff user ID.
	 * @param string $actor_name       Staff display name.
	 * @param string $event_key        Durable decision idempotency key.
	 * @param string $reason            Optional decision reason.
	 * @param int    $expected_revision Request revision shown to the approver.
	 * @return array|WP_Error Approval result; approve includes capability_token.
	 */
	public static function decide( $request_order_id, $decision, $actor_user_id, $actor_name, $event_key, $reason = '', $expected_revision = 0 ) {
		$request_order_id = absint( $request_order_id );
		$decision         = sanitize_key( $decision );
		$actor_user_id    = absint( $actor_user_id );
		$actor_name       = sanitize_text_field( $actor_name );
		$event_key        = trim( sanitize_text_field( $event_key ) );
		$reason           = sanitize_textarea_field( $reason );
		$expected_revision = absint( $expected_revision );
		$ready            = self::storage_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		if ( ! $request_order_id || ! in_array( $decision, array( 'approve', 'reject' ), true ) || ! $actor_user_id || '' === $actor_name || '' === $event_key || strlen( $event_key ) > 191 ) {
			return self::error( 'doughboss_preorder_decision_invalid', __( 'A valid pre-order decision, staff member, and decision key are required.', 'doughboss' ), 400 );
		}

		$started = self::begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		try {
			$event = self::locked_event( $event_key );
			if ( $event ) {
				if ( (int) $event->request_order_id !== $request_order_id || ! self::decision_matches_state( $decision, $event->state ) ) {
					self::rollback();
					return self::error( 'doughboss_preorder_event_conflict', __( 'That decision key has already been used for another pre-order decision.', 'doughboss' ), 409 );
				}
				$result = self::decision_result( $event );
				if ( is_wp_error( $result ) ) {
					self::rollback();
					return $result;
				}
				$result['replayed'] = true;
				if ( ! self::commit() ) {
					return self::error( 'doughboss_preorder_store_failed', __( 'The pre-order decision could not be committed.', 'doughboss' ), 500 );
				}
				return $result;
			}

			$order = self::locked_order( $request_order_id );
			$valid = self::validate_pending_request( $order );
			if ( is_wp_error( $valid ) ) {
				self::rollback();
				return $valid;
			}
			if ( $expected_revision && (int) $order->version !== $expected_revision ) {
				self::rollback();
				return self::error( 'doughboss_preorder_revision_conflict', __( 'This pre-order request changed after it was shown. Refresh it before deciding.', 'doughboss' ), 409 );
			}
			$row = self::locked_approval( $request_order_id );
			if ( ! $row ) {
				$pending = self::insert_pending( $request_order_id );
				if ( is_wp_error( $pending ) ) {
					self::rollback();
					return $pending;
				}
				$row = self::locked_approval( $request_order_id );
			}
			if ( ! self::known_state( $row->state ) ) {
				self::rollback();
				return self::error( 'doughboss_preorder_state_invalid', __( 'The pre-order approval state is invalid.', 'doughboss' ), 409 );
			}
			if ( self::STATE_PENDING !== $row->state ) {
				self::rollback();
				return self::error( 'doughboss_preorder_already_decided', __( 'This pre-order request has already been decided.', 'doughboss' ), 409 );
			}

			$now         = self::now();
			$fingerprint = self::fingerprint_from_locked_order( $order );
			$data        = array(
				'state'              => 'approve' === $decision ? self::STATE_APPROVED_UNPAID : self::STATE_REJECTED,
				'approved_revision'  => 'approve' === $decision ? (int) $order->version : null,
				'request_fingerprint'=> $fingerprint,
				'approver_user_id'   => $actor_user_id,
				'approver_name'      => $actor_name,
				'decided_at_utc'     => $now,
				'decision_event_key' => $event_key,
				'decision_reason'    => '' === $reason ? null : $reason,
				'updated_at_utc'     => $now,
			);
			if ( 'approve' === $decision ) {
				$expires = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
				$token   = self::capability_token( $request_order_id, (int) $order->version, $fingerprint, $expires );
				$data['payment_token_hash']           = hash( 'sha256', $token );
				$data['payment_token_expires_at_utc'] = $expires;
				$data['payment_checkout_key']         = self::checkout_key( $request_order_id, (int) $order->version, $fingerprint );
			} else {
				$data['payment_token_hash']           = null;
				$data['payment_token_expires_at_utc'] = null;
				$data['payment_checkout_key']         = null;
			}
			if ( ! self::compare_and_set( $row, $data ) ) {
				self::rollback();
				return self::error( 'doughboss_preorder_decision_conflict', __( 'This pre-order request changed before its decision could be recorded.', 'doughboss' ), 409 );
			}
			if ( 'reject' === $decision && ! self::cancel_request_for_rejection( $order, $actor_user_id, $event_key, $now ) ) {
				self::rollback();
				return self::error( 'doughboss_preorder_decision_conflict', __( 'The rejected pre-order request could not be cancelled safely.', 'doughboss' ), 409 );
			}
			$row = self::locked_approval( $request_order_id );
			$result = self::decision_result( $row );
			if ( is_wp_error( $result ) ) {
				self::rollback();
				return $result;
			}
			$result['replayed'] = false;
			if ( ! self::commit() ) {
				return self::error( 'doughboss_preorder_store_failed', __( 'The pre-order decision could not be committed.', 'doughboss' ), 500 );
			}
			return $result;
		} catch ( Throwable $e ) {
			self::rollback();
			return self::error( 'doughboss_preorder_store_failed', __( 'The pre-order decision could not be saved.', 'doughboss' ), 500 );
		}
	}

	/**
	 * Verify a capability token without mutating approval state.
	 *
	 * @param int    $request_order_id Pre-order request order ID.
	 * @param string $raw_token        Raw capability token.
	 * @param bool   $allow_started    Also allow an already started payment.
	 * @param bool   $allow_expired_started Allow an expired started capability only so an exact paid provider session can be verified.
	 * @return array|WP_Error Approval row.
	 */
	public static function verify_payment_token( $request_order_id, $raw_token, $allow_started = false, $allow_expired_started = false ) {
		$request_order_id = absint( $request_order_id );
		$raw_token        = trim( (string) $raw_token );
		$ready            = self::storage_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		if ( ! $request_order_id || '' === $raw_token ) {
			return self::error( 'doughboss_preorder_payment_token_invalid', __( 'A valid payment capability is required.', 'doughboss' ), 403 );
		}
		$row = self::approval( $request_order_id );
		return self::valid_token_row( $row, $raw_token, (bool) $allow_started, (bool) $allow_expired_started );
	}

	/**
	 * Atomically claim the approved payment capability for one payment attempt.
	 *
	 * @param int    $request_order_id Pre-order request order ID.
	 * @param string $raw_token        Raw capability token.
	 * @return array|WP_Error Approval row plus payment_checkout_key.
	 */
	public static function claim_payment( $request_order_id, $raw_token ) {
		$request_order_id = absint( $request_order_id );
		$raw_token        = trim( (string) $raw_token );
		$ready            = self::storage_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		if ( ! $request_order_id || '' === $raw_token ) {
			return self::error( 'doughboss_preorder_payment_token_invalid', __( 'A valid payment capability is required.', 'doughboss' ), 403 );
		}
		$started = self::begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		try {
			$row   = self::locked_approval( $request_order_id );
			$valid = self::valid_token_row( $row, $raw_token, true, false );
			if ( is_wp_error( $valid ) ) {
				self::rollback();
				return $valid;
			}
			if ( self::STATE_PAYMENT_ACTIVE === $row->state ) {
				if ( ! self::commit() ) {
					return self::error( 'doughboss_preorder_store_failed', __( 'The payment claim could not be committed.', 'doughboss' ), 500 );
				}
				return self::payment_result( $row, true );
			}
			if ( self::STATE_APPROVED_UNPAID !== $row->state ) {
				self::rollback();
				return self::error( 'doughboss_preorder_payment_unavailable', __( 'Payment is not available for this pre-order request.', 'doughboss' ), 409 );
			}
			if ( self::expired( $row ) ) {
				self::rollback();
				return self::error( 'doughboss_preorder_payment_token_expired', __( 'This payment capability has expired.', 'doughboss' ), 403 );
			}
			$expires = strtotime( (string) $row->payment_token_expires_at_utc . ' UTC' );
			if ( false === $expires || $expires < time() + ( 35 * MINUTE_IN_SECONDS ) ) {
				self::rollback();
				return self::error( 'doughboss_preorder_payment_start_cutoff', __( 'This payment link is too close to expiry to start a new payment. Please contact the shop.', 'doughboss' ), 409 );
			}
			if ( ! self::compare_and_set( $row, array( 'state' => self::STATE_PAYMENT_ACTIVE, 'updated_at_utc' => self::now() ) ) ) {
				self::rollback();
				return self::error( 'doughboss_preorder_payment_conflict', __( 'This payment was already started elsewhere.', 'doughboss' ), 409 );
			}
			$row = self::locked_approval( $request_order_id );
			if ( ! self::commit() ) {
				return self::error( 'doughboss_preorder_store_failed', __( 'The payment claim could not be committed.', 'doughboss' ), 500 );
			}
			return self::payment_result( $row, false );
		} catch ( Throwable $e ) {
			self::rollback();
			return self::error( 'doughboss_preorder_store_failed', __( 'The payment claim could not be saved.', 'doughboss' ), 500 );
		}
	}

	/**
	 * Mark an uncertain provider result held; it cannot reopen the capability.
	 *
	 * @param int $request_order_id Pre-order request order ID.
	 * @return array|WP_Error Approval row.
	 */
	public static function hold_payment( $request_order_id ) {
		return self::transition_payment_state( $request_order_id, array( self::STATE_APPROVED_UNPAID, self::STATE_PAYMENT_ACTIVE ), self::STATE_PAYMENT_HELD, 'doughboss_preorder_payment_hold_failed', __( 'The payment could not be placed on hold.', 'doughboss' ) );
	}

	/**
	 * Link the provider-agnostic payment-attempt identifier to a claimed payment.
	 *
	 * @param int $request_order_id Pre-order request order ID.
	 * @param int $attempt_id       Durable payment attempt ID.
	 * @return array|WP_Error Approval row.
	 */
	public static function link_payment_attempt( $request_order_id, $attempt_id ) {
		$request_order_id = absint( $request_order_id );
		$attempt_id       = absint( $attempt_id );
		$ready            = self::storage_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		if ( ! $request_order_id || ! $attempt_id ) {
			return self::error( 'doughboss_preorder_payment_attempt_invalid', __( 'A valid pre-order request and payment attempt are required.', 'doughboss' ), 400 );
		}
		$started = self::begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		try {
			$row = self::locked_approval( $request_order_id );
			if ( ! $row || ! in_array( $row->state, array( self::STATE_PAYMENT_ACTIVE, self::STATE_PAYMENT_HELD ), true ) ) {
				self::rollback();
				return self::error( 'doughboss_preorder_payment_unavailable', __( 'A payment attempt can only be linked after payment has started.', 'doughboss' ), 409 );
			}
			if ( $row->payment_attempt_id ) {
				if ( $attempt_id !== (int) $row->payment_attempt_id ) {
					self::rollback();
					return self::error( 'doughboss_preorder_payment_attempt_conflict', __( 'A different payment attempt is already linked to this pre-order request.', 'doughboss' ), 409 );
				}
				if ( ! self::commit() ) {
					return self::error( 'doughboss_preorder_store_failed', __( 'The payment attempt link could not be committed.', 'doughboss' ), 500 );
				}
				return self::row( $row );
			}
			if ( ! self::compare_and_set( $row, array( 'payment_attempt_id' => $attempt_id, 'updated_at_utc' => self::now() ) ) ) {
				self::rollback();
				return self::error( 'doughboss_preorder_payment_attempt_conflict', __( 'The payment attempt could not be linked.', 'doughboss' ), 409 );
			}
			$row = self::locked_approval( $request_order_id );
			if ( ! self::commit() ) {
				return self::error( 'doughboss_preorder_store_failed', __( 'The payment attempt link could not be committed.', 'doughboss' ), 500 );
			}
			return self::row( $row );
		} catch ( Throwable $e ) {
			self::rollback();
			return self::error( 'doughboss_preorder_store_failed', __( 'The payment attempt link could not be saved.', 'doughboss' ), 500 );
		}
	}

	/**
	 * Release a paid request to one fulfillment order, exactly once.
	 *
	 * @param int $request_order_id     Pre-order request order ID.
	 * @param int $fulfillment_order_id Newly created fulfillment order ID.
	 * @return array|WP_Error Approval row.
	 */
	public static function release( $request_order_id, $fulfillment_order_id ) {
		$request_order_id     = absint( $request_order_id );
		$fulfillment_order_id = absint( $fulfillment_order_id );
		$ready                = self::storage_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		if ( ! $request_order_id || ! $fulfillment_order_id || $request_order_id === $fulfillment_order_id ) {
			return self::error( 'doughboss_preorder_fulfillment_invalid', __( 'A distinct valid fulfillment order is required.', 'doughboss' ), 400 );
		}
		$started = self::begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		try {
			$current = self::locked_approval( $request_order_id );
			if ( ! $current ) {
				self::rollback();
				return self::error( 'doughboss_preorder_approval_missing', __( 'No pre-order approval record exists for this request.', 'doughboss' ), 404 );
			}
			$row = self::release_locked( $request_order_id, $fulfillment_order_id, (string) $current->payment_checkout_key );
			if ( is_wp_error( $row ) ) {
				self::rollback();
				return $row;
			}
			if ( ! self::commit() ) {
				return self::error( 'doughboss_preorder_store_failed', __( 'The fulfillment release could not be committed.', 'doughboss' ), 500 );
			}
			return self::row( $row );
		} catch ( Throwable $e ) {
			self::rollback();
			return self::error( 'doughboss_preorder_store_failed', __( 'The fulfillment release could not be saved.', 'doughboss' ), 500 );
		}
	}

	/**
	 * Link a paid fulfillment order while the caller already owns a transaction.
	 *
	 * This method does not start, commit, or roll back a transaction. It is
	 * intended for Order::create() to call after inserting the fulfillment order
	 * and before it commits or fires order-created hooks.
	 *
	 * @param int    $request_order_id     Pre-order request order ID.
	 * @param int    $fulfillment_order_id Newly created fulfillment order ID.
	 * @param string $checkout_key         Server-derived payment checkout key.
	 * @return array|WP_Error Approval row.
	 */
	public static function release_locked( $request_order_id, $fulfillment_order_id, $checkout_key ) {
		$request_order_id     = absint( $request_order_id );
		$fulfillment_order_id = absint( $fulfillment_order_id );
		$checkout_key         = strtolower( trim( (string) $checkout_key ) );
		if ( ! $request_order_id || ! $fulfillment_order_id || $request_order_id === $fulfillment_order_id || 1 !== preg_match( '/^[a-f0-9]{64}$/', $checkout_key ) ) {
			return self::error( 'doughboss_preorder_fulfillment_invalid', __( 'A distinct valid fulfillment order and checkout key are required.', 'doughboss' ), 400 );
		}
		$row = self::locked_approval( $request_order_id );
		if ( ! $row || ! self::known_state( $row->state ) ) {
			return self::error( 'doughboss_preorder_approval_missing', __( 'No valid pre-order approval record exists for this request.', 'doughboss' ), 404 );
		}
		$request = self::locked_order( $request_order_id );
		if ( ! $request || 'preorder_request' !== $request->order_source || ! $row->request_fingerprint || ! hash_equals( (string) $row->request_fingerprint, self::fingerprint_from_locked_order( $request ) ) ) {
			return self::error( 'doughboss_preorder_fingerprint_conflict', __( 'The pre-order request changed after approval and cannot be released.', 'doughboss' ), 409 );
		}
		if ( ! $row->payment_checkout_key || ! hash_equals( (string) $row->payment_checkout_key, $checkout_key ) ) {
			return self::error( 'doughboss_preorder_checkout_conflict', __( 'The payment checkout key does not match this pre-order approval.', 'doughboss' ), 409 );
		}
		if ( self::STATE_RELEASED === $row->state ) {
			if ( $fulfillment_order_id !== (int) $row->fulfillment_order_id ) {
				return self::error( 'doughboss_preorder_release_conflict', __( 'This pre-order request is already linked to another fulfillment order.', 'doughboss' ), 409 );
			}
			return self::row( $row );
		}
		if ( ! in_array( $row->state, array( self::STATE_PAYMENT_ACTIVE, self::STATE_PAYMENT_HELD ), true ) ) {
			return self::error( 'doughboss_preorder_release_unavailable', __( 'This pre-order request is not ready for fulfillment release.', 'doughboss' ), 409 );
		}
		$fulfillment = self::locked_order( $fulfillment_order_id );
		if ( ! $fulfillment ) {
			return self::error( 'doughboss_preorder_fulfillment_missing', __( 'The fulfillment order could not be found.', 'doughboss' ), 404 );
		}
		if (
			'web' !== (string) $fulfillment->order_source
			|| 'paid' !== (string) $fulfillment->payment_status
			|| 'stripe' !== (string) $fulfillment->payment_method
			|| empty( $fulfillment->payment_intent_id )
			|| empty( $fulfillment->checkout_key )
			|| ! hash_equals( $checkout_key, (string) $fulfillment->checkout_key )
			|| ! hash_equals( strtoupper( (string) $request->currency ), strtoupper( (string) $fulfillment->currency ) )
		) {
			return self::error( 'doughboss_preorder_fulfillment_unverified', __( 'Only a verified paid Stripe order can release this pre-order request.', 'doughboss' ), 409 );
		}
		if ( $fulfillment->source_request_id && $request_order_id !== (int) $fulfillment->source_request_id ) {
			return self::error( 'doughboss_preorder_fulfillment_conflict', __( 'That fulfillment order is already linked to another pre-order request.', 'doughboss' ), 409 );
		}
		if ( ! $fulfillment->source_request_id && ! self::link_fulfillment_order( $fulfillment_order_id, $request_order_id ) ) {
			return self::error( 'doughboss_preorder_fulfillment_conflict', __( 'The fulfillment order could not be linked to this pre-order request.', 'doughboss' ), 409 );
		}
		if ( ! self::compare_and_set( $row, array( 'state' => self::STATE_RELEASED, 'fulfillment_order_id' => $fulfillment_order_id, 'released_at_utc' => self::now(), 'updated_at_utc' => self::now() ) ) ) {
			return self::error( 'doughboss_preorder_release_conflict', __( 'The fulfillment release could not be recorded.', 'doughboss' ), 409 );
		}
		$row = self::locked_approval( $request_order_id );
		return self::row( $row );
	}

	/**
	 * Fingerprint the current immutable request facts and item snapshot.
	 *
	 * @param int $request_order_id Pre-order request order ID.
	 * @return string|WP_Error SHA-256 fingerprint.
	 */
	public static function fingerprint_request( $request_order_id ) {
		$request_order_id = absint( $request_order_id );
		$ready            = self::storage_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		if ( ! $request_order_id ) {
			return self::error( 'doughboss_preorder_request_invalid', __( 'A valid pre-order request is required.', 'doughboss' ), 400 );
		}
		global $wpdb;
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::orders_table() . ' WHERE id = %d', $request_order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $order || 'preorder_request' !== $order->order_source ) {
			return self::error( 'doughboss_preorder_request_missing', __( 'The pre-order request could not be found.', 'doughboss' ), 404 );
		}
		return self::fingerprint_from_order( $order, false );
	}

	/** @return array|WP_Error */
	private static function transition_payment_state( $request_order_id, $from, $to, $error_code, $error_message ) {
		$request_order_id = absint( $request_order_id );
		$ready            = self::storage_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		if ( ! $request_order_id ) {
			return self::error( 'doughboss_preorder_request_invalid', __( 'A valid pre-order request is required.', 'doughboss' ), 400 );
		}
		$started = self::begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		try {
			$row = self::locked_approval( $request_order_id );
			if ( ! $row || ! self::known_state( $row->state ) ) {
				self::rollback();
				return self::error( 'doughboss_preorder_state_invalid', __( 'The pre-order approval state is invalid.', 'doughboss' ), 409 );
			}
			if ( $to === $row->state ) {
				if ( ! self::commit() ) {
					return self::error( 'doughboss_preorder_store_failed', __( 'The payment state could not be committed.', 'doughboss' ), 500 );
				}
				return self::row( $row );
			}
			$from_states = is_array( $from ) ? $from : array( $from );
			if ( ! in_array( $row->state, $from_states, true ) || ! self::compare_and_set( $row, array( 'state' => $to, 'updated_at_utc' => self::now() ) ) ) {
				self::rollback();
				return self::error( $error_code, $error_message, 409 );
			}
			$row = self::locked_approval( $request_order_id );
			if ( ! self::commit() ) {
				return self::error( 'doughboss_preorder_store_failed', __( 'The payment state could not be committed.', 'doughboss' ), 500 );
			}
			return self::row( $row );
		} catch ( Throwable $e ) {
			self::rollback();
			return self::error( 'doughboss_preorder_store_failed', __( 'The payment state could not be saved.', 'doughboss' ), 500 );
		}
	}

	/** @return array|WP_Error */
	private static function storage_ready() {
		if ( version_compare( (string) get_option( 'doughboss_db_version', '0' ), '1.24.0', '<' ) || ! class_exists( 'DoughBoss_Activator' ) || ! DoughBoss_Activator::preorder_approval_storage_ready() ) {
			return self::error( 'doughboss_preorder_storage_unavailable', __( 'Pre-order approval is temporarily unavailable while storage is upgraded.', 'doughboss' ), 503 );
		}
		return true;
	}

	/** @return bool|WP_Error */
	private static function begin() {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return self::error( 'doughboss_preorder_transaction_unavailable', __( 'Pre-order approval is temporarily unavailable. Please try again.', 'doughboss' ), 503 );
		}
		return true;
	}

	/** @return bool */
	private static function commit() {
		global $wpdb;
		return false !== $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** @return void */
	private static function rollback() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** @return object|null */
	private static function locked_order( $order_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::orders_table() . ' WHERE id = %d FOR UPDATE', $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/** @return object|null */
	private static function locked_approval( $request_order_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::approvals_table() . ' WHERE request_order_id = %d FOR UPDATE', $request_order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/** @return object|null */
	private static function locked_event( $event_key ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::approvals_table() . ' WHERE decision_event_key = %s FOR UPDATE', $event_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/** @return object|null */
	private static function approval( $request_order_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::approvals_table() . ' WHERE request_order_id = %d', $request_order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/** @return array|WP_Error */
	private static function validate_pending_request( $order ) {
		if ( ! $order ) {
			return self::error( 'doughboss_preorder_request_missing', __( 'The pre-order request could not be found.', 'doughboss' ), 404 );
		}
		if ( 'preorder_request' !== $order->order_source || 'pending' !== $order->status || 'unpaid' !== $order->payment_status ) {
			return self::error( 'doughboss_preorder_request_ineligible', __( 'Only a pending unpaid pre-order request can be reviewed.', 'doughboss' ), 409 );
		}
		return true;
	}

	/** @return array|WP_Error */
	private static function insert_pending( $request_order_id ) {
		global $wpdb;
		$now = self::now();
		if ( false === $wpdb->insert( self::approvals_table(), array( 'request_order_id' => $request_order_id, 'state' => self::STATE_PENDING, 'version' => 1, 'created_at_utc' => $now, 'updated_at_utc' => $now ), array( '%d', '%s', '%d', '%s', '%s' ) ) ) {
			return self::error( 'doughboss_preorder_store_failed', __( 'The pre-order approval record could not be saved.', 'doughboss' ), 500 );
		}
		return true;
	}

	/**
	 * Cancel a rejected request and append its lifecycle audit event.
	 *
	 * The caller holds the order lock and transaction. This intentionally does
	 * not use DoughBoss_Order::transition(), because that public runtime path
	 * emits order-status hooks and must not turn a rejected request into kitchen
	 * work.
	 *
	 * @param object $order         Locked request order.
	 * @param int    $actor_user_id Staff user ID.
	 * @param string $decision_key  Approval decision idempotency key.
	 * @param string $now           UTC MySQL timestamp.
	 * @return bool
	 */
	private static function cancel_request_for_rejection( $order, $actor_user_id, $decision_key, $now ) {
		global $wpdb;
		$new_version = (int) $order->version + 1;
		$updated = $wpdb->update(
			self::orders_table(),
			array(
				'status'            => 'cancelled',
				'version'           => $new_version,
				'status_changed_at' => $now,
				'cancelled_at'      => $now,
				'updated_at'        => $now,
			),
			array(
				'id'      => (int) $order->id,
				'status'  => 'pending',
				'version' => (int) $order->version,
			),
			array( '%s', '%d', '%s', '%s', '%s' ),
			array( '%d', '%s', '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 !== $updated ) {
			return false;
		}
		$event_key = 'preorder-reject:' . hash( 'sha256', $decision_key );
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'doughboss_order_events',
			array(
				'order_id'      => (int) $order->id,
				'order_version' => $new_version,
				'event_type'    => 'status_changed',
				'from_status'   => 'pending',
				'to_status'     => 'cancelled',
				'actor_type'    => 'staff',
				'actor_id'      => $actor_user_id,
				'reason_code'   => 'preorder_rejected',
				'event_key'     => $event_key,
				'occurred_at'   => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $inserted;
	}

	/** @return string */
	private static function fingerprint_from_locked_order( $order ) {
		return self::fingerprint_from_order( $order, true );
	}

	/** @return string */
	private static function fingerprint_from_order( $order, $locking ) {
		global $wpdb;
		$sql = 'SELECT id,item_id,name,size,toppings,quantity,unit_price,line_total FROM ' . self::items_table() . ' WHERE order_id = %d ORDER BY id ASC' . ( $locking ? ' FOR UPDATE' : '' );
		$items = (array) $wpdb->get_results( $wpdb->prepare( $sql, (int) $order->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$fields = array( 'id', 'order_number', 'version', 'location_id', 'status', 'order_type', 'order_source', 'customer_name', 'customer_email', 'customer_phone', 'address', 'notes', 'subtotal', 'tax', 'delivery_fee', 'total', 'discount', 'voucher_code', 'currency', 'payment_status', 'payment_method', 'promised_ready_from_utc', 'promised_ready_by_utc', 'timezone_snapshot' );
		$snapshot = array();
		foreach ( $fields as $field ) {
			$snapshot[ $field ] = isset( $order->{$field} ) ? (string) $order->{$field} : '';
		}
		$snapshot['items'] = $items;
		return hash( 'sha256', wp_json_encode( $snapshot ) );
	}

	/** @return array|WP_Error */
	private static function valid_token_row( $row, $raw_token, $allow_started, $allow_expired_started = false ) {
		$allowed_states = $allow_started
			? array( self::STATE_APPROVED_UNPAID, self::STATE_PAYMENT_ACTIVE, self::STATE_PAYMENT_HELD, self::STATE_RELEASED )
			: array( self::STATE_APPROVED_UNPAID );
		if ( ! $row || ! self::known_state( $row->state ) || ! in_array( $row->state, $allowed_states, true ) ) {
			return self::error( 'doughboss_preorder_payment_unavailable', __( 'Payment is not available for this pre-order request.', 'doughboss' ), 409 );
		}
		if ( ! $row->payment_token_hash || ! hash_equals( (string) $row->payment_token_hash, hash( 'sha256', $raw_token ) ) ) {
			return self::error( 'doughboss_preorder_payment_token_invalid', __( 'This payment capability is invalid.', 'doughboss' ), 403 );
		}
		if ( self::expired( $row ) && ( ! $allow_expired_started || self::STATE_APPROVED_UNPAID === $row->state ) ) {
			return self::error( 'doughboss_preorder_payment_token_expired', __( 'This payment capability has expired.', 'doughboss' ), 403 );
		}
		return self::row( $row );
	}

	/** @return bool */
	private static function expired( $row ) {
		return ! $row->payment_token_expires_at_utc || self::now() >= (string) $row->payment_token_expires_at_utc;
	}

	/** @return bool */
	private static function compare_and_set( $row, array $data ) {
		global $wpdb;
		$data['version'] = (int) $row->version + 1;
		return 1 === $wpdb->update( self::approvals_table(), $data, array( 'request_order_id' => (int) $row->request_order_id, 'version' => (int) $row->version, 'state' => (string) $row->state ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** @return bool */
	private static function link_fulfillment_order( $fulfillment_order_id, $request_order_id ) {
		global $wpdb;
		return 1 === $wpdb->update( self::orders_table(), array( 'source_request_id' => $request_order_id, 'updated_at' => self::now() ), array( 'id' => $fulfillment_order_id, 'source_request_id' => null ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** @return array|WP_Error */
	private static function decision_result( $row ) {
		if ( ! $row || ! self::known_state( $row->state ) ) {
			return self::error( 'doughboss_preorder_state_invalid', __( 'The pre-order approval state is invalid.', 'doughboss' ), 409 );
		}
		$result = self::row( $row );
		if ( self::approval_derived_state( $row->state ) ) {
			$result['capability_token'] = self::capability_token( (int) $row->request_order_id, (int) $row->approved_revision, (string) $row->request_fingerprint, (string) $row->payment_token_expires_at_utc );
		}
		return $result;
	}

	/** @return array */
	private static function payment_result( $row, $replayed ) {
		$result = self::row( $row );
		$result['payment_checkout_key'] = (string) $row->payment_checkout_key;
		$result['replayed']             = (bool) $replayed;
		return $result;
	}

	/** @return array */
	private static function row( $row ) {
		return array(
			'request_order_id'             => (int) $row->request_order_id,
			'state'                        => (string) $row->state,
			'version'                      => (int) $row->version,
			'approved_revision'            => null === $row->approved_revision ? null : (int) $row->approved_revision,
			'request_fingerprint'          => $row->request_fingerprint ? (string) $row->request_fingerprint : null,
			'approver_user_id'             => null === $row->approver_user_id ? null : (int) $row->approver_user_id,
			'approver_name'                => (string) $row->approver_name,
			'decided_at_utc'               => $row->decided_at_utc ? (string) $row->decided_at_utc : null,
			'decision_event_key'           => $row->decision_event_key ? (string) $row->decision_event_key : null,
			'decision_reason'              => $row->decision_reason ? (string) $row->decision_reason : null,
			'payment_token_expires_at_utc' => $row->payment_token_expires_at_utc ? (string) $row->payment_token_expires_at_utc : null,
			'payment_checkout_key'         => $row->payment_checkout_key ? (string) $row->payment_checkout_key : null,
			'payment_attempt_id'           => null === $row->payment_attempt_id ? null : (int) $row->payment_attempt_id,
			'payment_link_delivery_status' => isset( $row->payment_link_delivery_status ) ? (string) $row->payment_link_delivery_status : 'not_attempted',
			'payment_link_delivery_attempted_at_utc' => ! empty( $row->payment_link_delivery_attempted_at_utc ) ? (string) $row->payment_link_delivery_attempted_at_utc : null,
			'fulfillment_order_id'         => null === $row->fulfillment_order_id ? null : (int) $row->fulfillment_order_id,
			'released_at_utc'              => $row->released_at_utc ? (string) $row->released_at_utc : null,
			'created_at_utc'               => (string) $row->created_at_utc,
			'updated_at_utc'               => (string) $row->updated_at_utc,
		);
	}

	/** @return bool */
	private static function decision_matches_state( $decision, $state ) {
		return ( 'approve' === $decision && self::approval_derived_state( $state ) ) || ( 'reject' === $decision && self::STATE_REJECTED === $state );
	}

	/** @return bool */
	private static function approval_derived_state( $state ) {
		return in_array( $state, array( self::STATE_APPROVED_UNPAID, self::STATE_PAYMENT_ACTIVE, self::STATE_PAYMENT_HELD, self::STATE_RELEASED ), true );
	}

	/** @return bool */
	private static function known_state( $state ) {
		return in_array( $state, array( self::STATE_PENDING, self::STATE_APPROVED_UNPAID, self::STATE_REJECTED, self::STATE_PAYMENT_ACTIVE, self::STATE_PAYMENT_HELD, self::STATE_RELEASED ), true );
	}

	/** @return string */
	private static function capability_token( $request_order_id, $approved_revision, $fingerprint, $expires ) {
		return hash_hmac( 'sha256', implode( '|', array( 'doughboss-preorder-capability-v1', $request_order_id, $approved_revision, $fingerprint, $expires ) ), wp_salt( 'auth' ) );
	}

	/** @return string */
	private static function checkout_key( $request_order_id, $approved_revision, $fingerprint ) {
		return hash_hmac( 'sha256', implode( '|', array( 'doughboss-preorder-checkout-v1', $request_order_id, $approved_revision, $fingerprint ) ), wp_salt( 'auth' ) );
	}

	/** @return string */
	private static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/** @return string */
	private static function approvals_table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_preorder_approvals';
	}

	/** @return string */
	private static function orders_table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_orders';
	}

	/** @return string */
	private static function items_table() {
		global $wpdb;
		return $wpdb->prefix . 'doughboss_order_items';
	}

	/** @return WP_Error */
	private static function error( $code, $message, $status ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
