<?php
/**
 * Membership tiers as Square Customer Groups: Student, Tradie, Club.
 *
 * Square is the system of record. Tier perks are Square Catalog pricing rules
 * (customer_group_ids_any) that the POS applies automatically when a tier
 * member is attached to the sale. This class only:
 *  - finds/creates the three groups by name (cached ids per mode + shop),
 *  - adds/removes a customer (found or created by email, else phone) to a tier
 *    after staff verify eligibility (student ID / trade licence), and
 *  - keeps the paid Club group in step with Square Subscriptions webhooks
 *    (ACTIVE → in the group; anything else → removed).
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DoughBoss_Square_Tiers {

	const TIERS        = array( 'student', 'tradie', 'club' );
	const GROUP_OPTION = 'doughboss_square_tier_groups';

	/**
	 * Square group name for a tier.
	 *
	 * @param string $tier Tier slug.
	 * @return string
	 */
	public static function group_name( $tier ) {
		$names = array(
			'student' => 'DoughBoss Student',
			'tradie'  => 'DoughBoss Tradie',
			'club'    => 'DoughBoss Club',
		);
		return isset( $names[ $tier ] ) ? $names[ $tier ] : '';
	}

	/**
	 * @param mixed $tier Candidate.
	 * @return string Valid tier or ''.
	 */
	public static function tier( $tier ) {
		$tier = is_string( $tier ) ? strtolower( trim( $tier ) ) : '';
		return in_array( $tier, self::TIERS, true ) ? $tier : '';
	}

	/**
	 * Native shop that owns tier data (Revesby until the other shops have
	 * their own Square accounts).
	 *
	 * @return int
	 */
	public static function home_location() {
		return class_exists( 'DoughBoss_Square_Invoices' ) ? DoughBoss_Square_Invoices::billing_location( array() ) : 0;
	}

	/**
	 * Group id for a tier, creating the group in Square on first use.
	 *
	 * @param string $tier     Tier.
	 * @param int    $location Native location.
	 * @return string|WP_Error
	 */
	public static function group_id( $tier, $location ) {
		$tier = self::tier( $tier );
		if ( '' === $tier ) {
			return new WP_Error( 'doughboss_tier', __( 'Unknown tier.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$cache_key = DoughBoss_Square::mode() . ':' . (int) $location . ':' . $tier;
		$cache     = get_option( self::GROUP_OPTION, array() );
		$cache     = is_array( $cache ) ? $cache : array();
		if ( ! empty( $cache[ $cache_key ] ) ) {
			return (string) $cache[ $cache_key ];
		}
		$name = self::group_name( $tier );
		// One page (up to 50 groups) is ample for a single shop's groups.
		$list = DoughBoss_Square::commerce_request( 'GET', '/v2/customers/groups', array(), $location );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$found = self::find_group( isset( $list['groups'] ) ? $list['groups'] : array(), $name );
		if ( '' === $found ) {
			$created = DoughBoss_Square::commerce_request(
				'POST',
				'/v2/customers/groups',
				array(
					'idempotency_key' => substr( hash( 'sha256', 'doughboss-tier-group|' . $cache_key ), 0, 40 ),
					'group'           => array( 'name' => $name ),
				),
				$location
			);
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$found = isset( $created['group']['id'] ) ? (string) $created['group']['id'] : '';
		}
		if ( '' === $found || ! preg_match( '/^[A-Za-z0-9_-]{4,64}$/', $found ) ) {
			return new WP_Error( 'doughboss_tier_group', __( 'Square did not return the tier group.', 'doughboss' ), array( 'status' => 502 ) );
		}
		$cache[ $cache_key ] = $found;
		update_option( self::GROUP_OPTION, $cache, false );
		return $found;
	}

	/**
	 * Pure: exact-name match in a groups list.
	 *
	 * @param mixed  $groups Square groups array.
	 * @param string $name   Name.
	 * @return string Group id or ''.
	 */
	public static function find_group( $groups, $name ) {
		if ( ! is_array( $groups ) ) {
			return '';
		}
		foreach ( $groups as $group ) {
			if ( is_array( $group ) && isset( $group['name'], $group['id'] ) && 0 === strcasecmp( trim( (string) $group['name'] ), $name ) ) {
				return (string) $group['id'];
			}
		}
		return '';
	}

	/**
	 * Find a Square customer by email (then phone), or create one.
	 *
	 * @param array $person   name, email, phone.
	 * @param int   $location Native location.
	 * @return string|WP_Error Customer id.
	 */
	public static function customer( array $person, $location ) {
		$email = isset( $person['email'] ) ? sanitize_email( (string) $person['email'] ) : '';
		$phone = class_exists( 'DoughBoss_Square_Invoices' ) ? DoughBoss_Square_Invoices::e164_au( isset( $person['phone'] ) ? $person['phone'] : '' ) : '';
		if ( '' === $email && '' === $phone ) {
			return new WP_Error( 'doughboss_tier_contact', __( 'Enter an email or Australian phone number.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$filters = array();
		if ( '' !== $email ) {
			$filters[] = array( 'email_address' => array( 'exact' => $email ) );
		}
		if ( '' !== $phone ) {
			$filters[] = array( 'phone_number' => array( 'exact' => $phone ) );
		}
		foreach ( $filters as $filter ) {
			$search = DoughBoss_Square::commerce_request( 'POST', '/v2/customers/search', array( 'limit' => 1, 'query' => array( 'filter' => $filter ) ), $location );
			if ( is_wp_error( $search ) ) {
				return $search;
			}
			if ( ! empty( $search['customers'][0]['id'] ) ) {
				return (string) $search['customers'][0]['id'];
			}
		}
		$parts = preg_split( '/\s+/', trim( isset( $person['name'] ) ? (string) $person['name'] : '' ), 2 );
		$body  = array(
			'idempotency_key' => substr( hash( 'sha256', 'doughboss-tier-customer|' . strtolower( $email ) . '|' . $phone ), 0, 40 ),
			'given_name'      => isset( $parts[0] ) ? substr( sanitize_text_field( $parts[0] ), 0, 100 ) : '',
			'family_name'     => isset( $parts[1] ) ? substr( sanitize_text_field( $parts[1] ), 0, 100 ) : '',
			'note'            => 'Created by DoughBoss tiers',
		);
		if ( '' !== $email ) {
			$body['email_address'] = $email;
		}
		if ( '' !== $phone ) {
			$body['phone_number'] = $phone;
		}
		$created = DoughBoss_Square::commerce_request( 'POST', '/v2/customers', $body, $location );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		return ! empty( $created['customer']['id'] ) ? (string) $created['customer']['id'] : new WP_Error( 'doughboss_pay_api', __( 'Square did not create the customer.', 'doughboss' ), array( 'status' => 502 ) );
	}

	/**
	 * Add or remove a customer from a tier group.
	 *
	 * @param string $tier        Tier.
	 * @param string $customer_id Square customer id.
	 * @param bool   $member      True to add, false to remove.
	 * @param int    $location    Native location.
	 * @return true|WP_Error
	 */
	public static function set_membership( $tier, $customer_id, $member, $location ) {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{4,64}$/', (string) $customer_id ) ) {
			return new WP_Error( 'doughboss_tier_customer', __( 'Invalid Square customer.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$group = self::group_id( $tier, $location );
		if ( is_wp_error( $group ) ) {
			return $group;
		}
		$res = DoughBoss_Square::commerce_request( $member ? 'PUT' : 'DELETE', '/v2/customers/' . $customer_id . '/groups/' . $group, array(), $location );
		if ( is_wp_error( $res ) ) {
			$data = $res->get_error_data();
			// Removing someone who is not in the group is already the goal.
			if ( ! $member && is_array( $data ) && isset( $data['square_http'] ) && 404 === (int) $data['square_http'] ) {
				return true;
			}
			return $res;
		}
		return true;
	}

	/**
	 * Staff action: verify-then-assign (or remove) a tier.
	 *
	 * @param string $tier   Tier.
	 * @param array  $person name/email/phone.
	 * @param bool   $member Add (true) or remove (false).
	 * @return array|WP_Error
	 */
	public static function assign( $tier, array $person, $member = true ) {
		$tier = self::tier( $tier );
		if ( '' === $tier ) {
			return new WP_Error( 'doughboss_tier', __( 'Choose Student, Tradie or Club.', 'doughboss' ), array( 'status' => 400 ) );
		}
		$location = self::home_location();
		$binding  = DoughBoss_Square::commerce_binding( $location );
		if ( is_wp_error( $binding ) ) {
			return new WP_Error( 'doughboss_tier_square_off', __( 'Square isn’t ready for this shop yet (check Square settings).', 'doughboss' ), array( 'status' => 409 ) );
		}
		$customer = self::customer( $person, $location );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}
		$done = self::set_membership( $tier, $customer, (bool) $member, $location );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		return array( 'tier' => $tier, 'customer_id' => $customer, 'member' => (bool) $member );
	}

	/**
	 * Pure: does a Square subscription status mean Club membership?
	 *
	 * @param mixed $status Square status.
	 * @return bool
	 */
	public static function club_active( $status ) {
		return 'ACTIVE' === $status;
	}

	/**
	 * Webhook: subscription.created / subscription.updated → Club group sync.
	 *
	 * Only subscriptions to the configured Club plan variation are acted on.
	 *
	 * @param string $subscription_id Square subscription id.
	 * @return string processed|ignored|retry
	 */
	public static function reconcile_subscription( $subscription_id ) {
		$subscription_id = DoughBoss_Square::canonical_id( $subscription_id );
		$plan            = trim( (string) DoughBoss_Settings::get( 'square_club_plan_variation_id', '' ) );
		if ( '' === $subscription_id || '' === $plan ) {
			return 'ignored';
		}
		$location = self::home_location();
		if ( is_wp_error( DoughBoss_Square::commerce_binding( $location ) ) ) {
			return 'retry';
		}
		$res = DoughBoss_Square::commerce_request( 'GET', '/v2/subscriptions/' . $subscription_id, array(), $location );
		if ( is_wp_error( $res ) || empty( $res['subscription'] ) || ! is_array( $res['subscription'] ) ) {
			return 'retry';
		}
		$sub = $res['subscription'];
		if ( ! isset( $sub['plan_variation_id'], $sub['customer_id'] ) || ! hash_equals( $plan, (string) $sub['plan_variation_id'] ) ) {
			return 'ignored';
		}
		$done = self::set_membership( 'club', (string) $sub['customer_id'], self::club_active( isset( $sub['status'] ) ? $sub['status'] : '' ), $location );
		return is_wp_error( $done ) ? 'retry' : 'processed';
	}
}
