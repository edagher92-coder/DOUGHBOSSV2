<?php
/**
 * Timesheet reconciliation: read-only Square Labor and Team client.
 *
 * Calls exactly two endpoints, both read-only: POST /v2/labor/timecards/search (TIMECARDS_READ) and
 * POST /v2/team-members/search (EMPLOYEES_READ, used only to SUGGEST mappings on request). The token comes
 * from DOUGHBOSS_GROWTH_SQUARE_LABOUR_TOKEN (environment first, then a wp-config constant) and is a separate
 * read-only token, never the storefront token. It is never stored, printed or logged; outbound requests go
 * through DoughBoss_Growth_Http, which redacts the Authorization header in every log line.
 *
 * Fail closed: any transport error, non-2xx status (429 and 5xx included), "errors" in a 2xx body, a body
 * that is truncated or not JSON, a page loop that does not end within the page cap, a repeated cursor, or a
 * response that contains anything outside the request's scope (Square silently ignores an invalid filter,
 * docs/square/api-integration-notes.md section 4.11) returns a WP_Error and the run is FAILED. There is no
 * partial result.
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Square read client for reconciliation.
 */
final class DoughBoss_Growth_Recon_Square {

	/**
	 * Pinned Square API version (current at 2026-10-02, api-integration-notes.md section 0). Timecards need
	 * 2025-05-21 or later; the retired Shift endpoints answer 410.
	 */
	const SQUARE_VERSION = '2026-09-16';

	/**
	 * Oldest version that has the Timecards API.
	 */
	const MIN_SQUARE_VERSION = '2025-05-21';

	/**
	 * Secret names (environment first, then wp-config constant).
	 */
	const TOKEN_NAME = 'DOUGHBOSS_GROWTH_SQUARE_LABOUR_TOKEN';
	const ENV_NAME   = 'DOUGHBOSS_GROWTH_SQUARE_ENV';

	/**
	 * Hosts per environment. Any other DOUGHBOSS_GROWTH_SQUARE_ENV value means "not configured".
	 */
	const BASE_URLS = array(
		'production' => 'https://connect.squareup.com',
		'sandbox'    => 'https://connect.squareupsandbox.com',
	);

	/**
	 * Page size. Square allows up to 200, but the companion's HTTP wrapper keeps at most 64 KiB of a body
	 * (DoughBoss_Growth_Http::MAX_BODY_BYTES), and 200 timecards with breaks exceed that. A truncated body
	 * is detected and fails the run; it is never parsed as a partial page.
	 */
	const PAGE_LIMIT = 40;

	/**
	 * Most pages one search may take before the run is FAILED as incomplete (50 x 40 = 2,000 timecards, far
	 * above three shops over a 31-day manual range). With two searches and the 10 s request ceiling a run
	 * spends at most 1,000 s on Square, below DoughBoss_Growth_Recon_Report::STALE_RUN_SECONDS.
	 */
	const MAX_PAGES = 50;

	/**
	 * Square object ids the companion accepts (letters, digits, "_" and "-", at most 64).
	 */
	const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';

	/**
	 * The configured Square environment ("production" or "sandbox"), or "" when unset or unknown.
	 *
	 * @return string
	 */
	public static function environment() {
		$env = strtolower( trim( DoughBoss_Growth_Settings::secret( self::ENV_NAME ) ) );
		return array_key_exists( $env, self::BASE_URLS ) ? $env : '';
	}

	/**
	 * Whether the read-only labour token is present (presence only; the value is never returned here).
	 *
	 * @return bool
	 */
	public static function has_token() {
		return DoughBoss_Growth_Settings::has_secret( self::TOKEN_NAME );
	}

	/**
	 * Why Square cannot be called, or "" when it can.
	 *
	 * @return string
	 */
	public static function not_ready_reason() {
		if ( '' === self::environment() ) {
			return 'square_env_missing';
		}
		if ( ! self::has_token() ) {
			return 'square_token_missing';
		}
		if ( strcmp( self::SQUARE_VERSION, self::MIN_SQUARE_VERSION ) < 0 ) {
			return 'square_version_too_old';
		}
		return '';
	}

	/**
	 * Whether a value is an acceptable Square id.
	 *
	 * @param mixed $id Value.
	 * @return bool
	 */
	public static function valid_id( $id ) {
		return is_string( $id ) && 1 === preg_match( self::ID_PATTERN, $id );
	}

	/**
	 * RFC 3339 UTC string for a UNIX time.
	 *
	 * @param int $ts UNIX time.
	 * @return string
	 */
	public static function rfc3339( $ts ) {
		return gmdate( 'Y-m-d\TH:i:s\Z', (int) $ts );
	}

	/**
	 * Parse an RFC 3339 instant ("2026-10-03T22:00:00+10:00", "...Z", optional fraction) to a UNIX time,
	 * without any timezone database lookup. Fractions are dropped (Square timecard instants are whole minutes).
	 *
	 * @param mixed $value Value.
	 * @return int|null Null when the value is not a valid instant.
	 */
	public static function parse_time( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > 40 ) {
			return null;
		}
		if ( 1 !== preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})[Tt]([0-9]{2}):([0-9]{2})(?::([0-9]{2})(?:\.[0-9]{1,9})?)?([Zz]|[+-][0-9]{2}:[0-9]{2})$/D', $value, $m ) ) {
			return null;
		}
		$year   = (int) $m[1];
		$month  = (int) $m[2];
		$day    = (int) $m[3];
		$hour   = (int) $m[4];
		$minute = (int) $m[5];
		$second = ( isset( $m[6] ) && '' !== $m[6] ) ? (int) $m[6] : 0;
		if ( ! checkdate( $month, $day, $year ) || $hour > 23 || $minute > 59 || $second > 59 ) {
			return null;
		}
		$offset = 0;
		if ( 'Z' !== strtoupper( $m[7] ) ) {
			$sign      = ( '-' === $m[7][0] ) ? -1 : 1;
			$off_hour  = (int) substr( $m[7], 1, 2 );
			$off_min   = (int) substr( $m[7], 4, 2 );
			if ( $off_hour > 23 || $off_min > 59 ) {
				return null;
			}
			$offset = $sign * ( $off_hour * 3600 + $off_min * 60 );
		}
		return gmmktime( $hour, $minute, $second, $month, $day, $year ) - $offset;
	}

	/**
	 * Validate and normalise one Square timecard to the matcher's shape. Only ids, instants, location,
	 * timezone, status, version and breaks are kept: wage, tips and names are dropped here.
	 *
	 * @param mixed $raw Decoded timecard.
	 * @return array|WP_Error
	 */
	public static function normalise_timecard( $raw ) {
		$bad = new WP_Error( 'square_response_invalid', 'A Square timecard is missing a required field or has an invalid value.' );
		if ( ! is_array( $raw ) ) {
			return $bad;
		}
		foreach ( array( 'id', 'team_member_id', 'location_id' ) as $key ) {
			if ( ! isset( $raw[ $key ] ) || ! self::valid_id( $raw[ $key ] ) ) {
				return $bad;
			}
		}
		$status = isset( $raw['status'] ) ? $raw['status'] : null;
		if ( 'OPEN' !== $status && 'CLOSED' !== $status ) {
			return $bad;
		}
		$start = isset( $raw['start_at'] ) ? self::parse_time( $raw['start_at'] ) : null;
		if ( null === $start ) {
			return $bad;
		}
		$end = null;
		if ( isset( $raw['end_at'] ) && null !== $raw['end_at'] ) {
			$end = self::parse_time( $raw['end_at'] );
			if ( null === $end ) {
				return $bad;
			}
		}
		// A CLOSED timecard has an end; an OPEN one has none. Anything else is inconsistent.
		if ( ( 'CLOSED' === $status ) !== ( null !== $end ) ) {
			return $bad;
		}
		if ( null !== $end && $end < $start ) {
			return $bad;
		}
		$timezone = '';
		if ( isset( $raw['timezone'] ) ) {
			if ( ! is_string( $raw['timezone'] ) || 1 !== preg_match( '/^[A-Za-z_]{1,32}(\/[A-Za-z0-9_+-]{1,32}){0,2}$/D', $raw['timezone'] ) ) {
				return $bad;
			}
			$timezone = $raw['timezone'];
		}
		$version = 0;
		if ( isset( $raw['version'] ) ) {
			if ( ! is_int( $raw['version'] ) || $raw['version'] < 0 ) {
				return $bad;
			}
			$version = $raw['version'];
		}
		$updated = null;
		if ( isset( $raw['updated_at'] ) ) {
			$updated = self::parse_time( $raw['updated_at'] );
			if ( null === $updated ) {
				return $bad;
			}
		}
		$breaks = array();
		if ( isset( $raw['breaks'] ) ) {
			if ( ! is_array( $raw['breaks'] ) ) {
				return $bad;
			}
			foreach ( $raw['breaks'] as $break ) {
				if ( ! is_array( $break ) || ! isset( $break['id'] ) || ! self::valid_id( $break['id'] ) ) {
					return $bad;
				}
				$b_start = isset( $break['start_at'] ) ? self::parse_time( $break['start_at'] ) : null;
				if ( null === $b_start ) {
					return $bad;
				}
				$b_end = null;
				if ( isset( $break['end_at'] ) && null !== $break['end_at'] ) {
					$b_end = self::parse_time( $break['end_at'] );
					if ( null === $b_end || $b_end < $b_start ) {
						return $bad;
					}
				}
				// is_paid is required by Square on every break; without it the paid/unpaid split is unknowable.
				if ( ! isset( $break['is_paid'] ) || ! is_bool( $break['is_paid'] ) ) {
					return $bad;
				}
				$breaks[] = array(
					'id'      => $break['id'],
					'start'   => $b_start,
					'end'     => $b_end,
					'is_paid' => $break['is_paid'],
				);
			}
		}
		return array(
			'id'             => $raw['id'],
			'team_member_id' => $raw['team_member_id'],
			'location_id'    => $raw['location_id'],
			'timezone'       => $timezone,
			'start'          => $start,
			'end'            => $end,
			'status'         => $status,
			'version'        => $version,
			'updated_at'     => $updated,
			'breaks'         => $breaks,
		);
	}

	/**
	 * Every timecard relevant to a window at the given Square locations: those that START inside
	 * [$from, $to] (any status) plus every OPEN timecard (an open timecard can start long before the window).
	 *
	 * @param array $location_ids Square location ids.
	 * @param int   $from         Window start (UNIX time).
	 * @param int   $to           Window end (UNIX time).
	 * @return array|WP_Error { timecards: array, pages: int }
	 */
	public static function search_timecards( array $location_ids, $from, $to ) {
		$reason = self::not_ready_reason();
		if ( '' !== $reason ) {
			return new WP_Error( $reason, 'Square is not configured for reconciliation.' );
		}
		$locations = array();
		foreach ( $location_ids as $id ) {
			if ( ! self::valid_id( $id ) ) {
				return new WP_Error( 'square_request_invalid', 'A Square location id is not valid.' );
			}
			$locations[ $id ] = true;
		}
		if ( array() === $locations || (int) $from >= (int) $to ) {
			return new WP_Error( 'square_request_invalid', 'Nothing to search.' );
		}
		$ids = array_keys( $locations );
		sort( $ids, SORT_STRING );

		$by_start = self::search_all(
			'/v2/labor/timecards/search',
			array(
				'query' => array(
					'filter' => array(
						'location_ids' => $ids,
						'start'        => array(
							'start_at' => self::rfc3339( $from ),
							'end_at'   => self::rfc3339( $to ),
						),
					),
					'sort'   => array(
						'field' => 'START_AT',
						'order' => 'ASC',
					),
				),
			),
			'timecards'
		);
		if ( is_wp_error( $by_start ) ) {
			return $by_start;
		}
		$open = self::search_all(
			'/v2/labor/timecards/search',
			array(
				'query' => array(
					'filter' => array(
						'location_ids' => $ids,
						'status'       => 'OPEN',
					),
					'sort'   => array(
						'field' => 'START_AT',
						'order' => 'ASC',
					),
				),
			),
			'timecards'
		);
		if ( is_wp_error( $open ) ) {
			return $open;
		}

		$cards = array();
		foreach ( array( 'start' => $by_start['items'], 'open' => $open['items'] ) as $query => $items ) {
			$seen = array();
			foreach ( $items as $raw ) {
				$card = self::normalise_timecard( $raw );
				if ( is_wp_error( $card ) ) {
					return $card;
				}
				// Scope assertions: Square ignores an invalid filter instead of rejecting it.
				if ( ! isset( $locations[ $card['location_id'] ] ) ) {
					return new WP_Error( 'square_scope_location', 'Square returned a timecard for a location that was not requested.' );
				}
				if ( 'start' === $query && ( $card['start'] < (int) $from || $card['start'] > (int) $to ) ) {
					return new WP_Error( 'square_scope_window', 'Square returned a timecard outside the requested window.' );
				}
				if ( 'open' === $query && 'OPEN' !== $card['status'] ) {
					return new WP_Error( 'square_scope_status', 'Square returned a closed timecard to an open-only search.' );
				}
				if ( isset( $seen[ $card['id'] ] ) ) {
					return new WP_Error( 'square_pagination_inconsistent', 'Square returned the same timecard twice in one search.' );
				}
				$seen[ $card['id'] ] = true;
				if ( isset( $cards[ $card['id'] ] ) ) {
					$known = $cards[ $card['id'] ];
					if ( $known === $card ) {
						continue;
					}
					// The same timecard changed between the two searches: keep the newer version; an equal
					// version with different content cannot be resolved.
					if ( $card['version'] === $known['version'] ) {
						return new WP_Error( 'square_pagination_inconsistent', 'Square returned two different copies of one timecard.' );
					}
					if ( $card['version'] < $known['version'] ) {
						continue;
					}
				}
				$cards[ $card['id'] ] = $card;
			}
		}
		ksort( $cards, SORT_STRING );
		return array(
			'timecards' => array_values( $cards ),
			'pages'     => $by_start['pages'] + $open['pages'],
		);
	}

	/**
	 * Active team members at the given locations, reduced to id and lower-cased email, for mapping
	 * SUGGESTIONS only. Nothing returned here is stored.
	 *
	 * @param array $location_ids Square location ids.
	 * @return array|WP_Error List of array( id, email ).
	 */
	public static function search_team_members( array $location_ids ) {
		$reason = self::not_ready_reason();
		if ( '' !== $reason ) {
			return new WP_Error( $reason, 'Square is not configured for reconciliation.' );
		}
		$ids = array();
		foreach ( $location_ids as $id ) {
			if ( ! self::valid_id( $id ) ) {
				return new WP_Error( 'square_request_invalid', 'A Square location id is not valid.' );
			}
			$ids[ $id ] = true;
		}
		$ids = array_keys( $ids );
		sort( $ids, SORT_STRING );
		if ( array() === $ids ) {
			return new WP_Error( 'square_request_invalid', 'Nothing to search.' );
		}
		$found = self::search_all(
			'/v2/team-members/search',
			array(
				'query' => array(
					'filter' => array(
						'location_ids' => $ids,
						'status'       => 'ACTIVE',
					),
				),
			),
			'team_members'
		);
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		$out  = array();
		$seen = array();
		foreach ( $found['items'] as $member ) {
			if ( ! is_array( $member ) || ! isset( $member['id'] ) || ! self::valid_id( $member['id'] ) ) {
				return new WP_Error( 'square_response_invalid', 'A Square team member has no valid id.' );
			}
			if ( ! isset( $member['status'] ) || 'ACTIVE' !== $member['status'] ) {
				return new WP_Error( 'square_scope_status', 'Square returned an inactive team member to an active-only search.' );
			}
			if ( isset( $seen[ $member['id'] ] ) ) {
				return new WP_Error( 'square_pagination_inconsistent', 'Square returned the same team member twice.' );
			}
			$seen[ $member['id'] ] = true;
			$email                 = ( isset( $member['email_address'] ) && is_string( $member['email_address'] ) ) ? strtolower( trim( $member['email_address'] ) ) : '';
			$out[]                 = array(
				'id'    => $member['id'],
				'email' => $email,
			);
		}
		return $out;
	}

	/**
	 * Run one search until the cursor is exhausted.
	 *
	 * @param string $path       API path.
	 * @param array  $body       Request body without limit/cursor.
	 * @param string $collection Response key holding the items.
	 * @return array|WP_Error { items: array, pages: int }
	 */
	private static function search_all( $path, array $body, $collection ) {
		$items   = array();
		$cursor  = null;
		$cursors = array();
		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$request          = $body;
			$request['limit'] = self::PAGE_LIMIT;
			if ( null !== $cursor ) {
				$request['cursor'] = $cursor;
			}
			$decoded = self::post( $path, $request );
			if ( is_wp_error( $decoded ) ) {
				return $decoded;
			}
			if ( isset( $decoded[ $collection ] ) ) {
				if ( ! is_array( $decoded[ $collection ] ) ) {
					return new WP_Error( 'square_response_invalid', 'Square returned a malformed list.' );
				}
				foreach ( $decoded[ $collection ] as $item ) {
					$items[] = $item;
				}
			}
			$next = isset( $decoded['cursor'] ) ? $decoded['cursor'] : null;
			if ( null === $next || '' === $next ) {
				return array(
					'items' => $items,
					'pages' => $page,
				);
			}
			if ( ! is_string( $next ) || strlen( $next ) > 2048 ) {
				return new WP_Error( 'square_response_invalid', 'Square returned a malformed cursor.' );
			}
			if ( isset( $cursors[ $next ] ) ) {
				return new WP_Error( 'square_pagination_loop', 'Square returned a cursor it had already returned.' );
			}
			$cursors[ $next ] = true;
			$cursor           = $next;
		}
		return new WP_Error( 'square_pagination_cap', 'The Square search did not finish within the page limit.' );
	}

	/**
	 * One POST. Returns the decoded body or a WP_Error whose code is the run reason.
	 *
	 * @param string $path API path.
	 * @param array  $body Body.
	 * @return array|WP_Error
	 */
	private static function post( $path, array $body ) {
		$env   = self::environment();
		$token = DoughBoss_Growth_Settings::secret( self::TOKEN_NAME );
		if ( '' === $env || '' === $token ) {
			return new WP_Error( '' === $env ? 'square_env_missing' : 'square_token_missing', 'Square is not configured for reconciliation.' );
		}
		$result = DoughBoss_Growth_Http::request(
			'POST',
			self::BASE_URLS[ $env ] . $path,
			array(
				'json'    => $body,
				'headers' => array(
					'Authorization'  => 'Bearer ' . $token,
					'Square-Version' => self::SQUARE_VERSION,
					'Accept'         => 'application/json',
				),
			)
		);
		unset( $token );
		$status = (int) $result['status'];
		if ( 0 === $status ) {
			// No response: a transport failure, or the HTTP policy refused the request before sending it.
			return new WP_Error( 'transport_error' === $result['error'] ? 'square_transport' : 'square_request_refused', 'Square could not be reached.' );
		}
		if ( 429 === $status ) {
			return new WP_Error( 'square_http_429', 'Square rate-limited the request.' );
		}
		if ( $status >= 500 ) {
			return new WP_Error( 'square_http_5xx', 'Square returned a server error.' );
		}
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'square_http_' . $status, 'Square refused the request.' );
		}
		if ( strlen( $result['body'] ) >= DoughBoss_Growth_Http::MAX_BODY_BYTES ) {
			return new WP_Error( 'square_response_too_large', 'The Square response was larger than the companion accepts.' );
		}
		$decoded = json_decode( $result['body'], true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'square_response_unparseable', 'The Square response was not JSON.' );
		}
		if ( isset( $decoded['errors'] ) && array() !== $decoded['errors'] ) {
			return new WP_Error( 'square_response_errors', 'Square reported errors.' );
		}
		return $decoded;
	}
}
