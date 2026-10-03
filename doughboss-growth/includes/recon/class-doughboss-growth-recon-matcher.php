<?php
/**
 * Timesheet reconciliation: the pure matching engine (no I/O, no WordPress calls).
 *
 * Input is the plugin's staff shifts and Square's timecards, already normalised to UTC epoch seconds by the
 * reader and the Square client. Output is a list of evidence rows, each carrying mismatch codes from the
 * catalogue in docs/wp/03-staff-dev-gaps.md section 2.5. The report never decides who is right and never
 * computes pay.
 *
 * Rules that matter (each is pinned by tests/test-recon-matcher.php):
 *   - every comparison is made on UTC epoch minutes; local time is used ONLY to label a row's workday and to
 *     display instants (Australia/Sydney). Local time is derived from DateTimeZone::getOffset() on a UTC
 *     DateTime and from getTransitions(); a timezone-converted object's getTimestamp() is never used, because
 *     PHP 7.4 collapses the repeated (fold) hour that way;
 *   - plugin instants are floored to the minute (Square truncates seconds); plugin net minutes use exactly the
 *     core formula of DoughBoss_Timeclock::worked_minutes() (seconds, each break floored);
 *   - a tolerance with no configured value never passes or fails: the row gets UNRATED_<check>;
 *     a delta equal to the tolerance passes, one minute more fails;
 *   - an open entry is never "matched";
 *   - items are grouped per mapped employee; a connected group of overlapping plugin shifts and Square
 *     timecards with more than one item on either side is one SPLIT row (union interval, summed breaks).
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure reconciliation logic.
 */
final class DoughBoss_Growth_Recon_Matcher {

	/**
	 * The shops' timezone. Workdays are labelled and instants displayed in this zone.
	 */
	const TIMEZONE = 'Australia/Sydney';

	/**
	 * Severity classes.
	 */
	const CLASS_BLOCKER = 'blocker';
	const CLASS_REVIEW  = 'review';
	const CLASS_UNRATED = 'unrated';
	const CLASS_INFO    = 'info';

	/**
	 * Row states, most severe first. MATCHED needs both sides closed, every check rated and within tolerance
	 * and no flag at all.
	 */
	const STATES = array( 'UNMAPPED', 'REVIEW', 'OPEN', 'UNRATED', 'INFO', 'MATCHED' );

	/**
	 * Row kinds.
	 */
	const KINDS = array( 'pair', 'split', 'near_miss', 'plugin_only', 'square_only', 'unmapped' );

	/**
	 * Mismatch catalogue (03 section 2.5) in display order, with class.
	 *
	 * @return array Code => class.
	 */
	public static function codes() {
		return array(
			'UNMAPPED_EMPLOYEE'      => self::CLASS_BLOCKER,
			'UNMAPPED_LOCATION'      => self::CLASS_BLOCKER,
			'MISSING_IN_SQUARE'      => self::CLASS_REVIEW,
			'MISSING_IN_PLUGIN'      => self::CLASS_REVIEW,
			'OPEN_PLUGIN_SHIFT'      => self::CLASS_REVIEW,
			'OPEN_SQUARE_TIMECARD'   => self::CLASS_REVIEW,
			'OPEN_ONE_SIDE'          => self::CLASS_REVIEW,
			'START_DIFF'             => self::CLASS_REVIEW,
			'END_DIFF'               => self::CLASS_REVIEW,
			'BREAK_DIFF'             => self::CLASS_REVIEW,
			'NET_DIFF'               => self::CLASS_REVIEW,
			'LOCATION_MISMATCH'      => self::CLASS_REVIEW,
			'OVERLAP_SAME_SIDE'      => self::CLASS_REVIEW,
			'CROSS_LOCATION_OVERLAP' => self::CLASS_REVIEW,
			'LONG_SHIFT'             => self::CLASS_REVIEW,
			'MANAGER_CLOSED'         => self::CLASS_REVIEW,
			'NO_OVERLAP_NEAR_MISS'   => self::CLASS_REVIEW,
			'BREAK_PAID_CLASS'       => self::CLASS_INFO,
			'TIMEZONE_MISMATCH'      => self::CLASS_INFO,
			'SQUARE_EDITED'          => self::CLASS_INFO,
			'SPLIT'                  => self::CLASS_INFO,
			'UNRATED_START'          => self::CLASS_UNRATED,
			'UNRATED_END'            => self::CLASS_UNRATED,
			'UNRATED_BREAK'          => self::CLASS_UNRATED,
			'UNRATED_NET'            => self::CLASS_UNRATED,
			'UNRATED_OPEN_ALERT'     => self::CLASS_UNRATED,
			'UNRATED_LONG_SHIFT'     => self::CLASS_UNRATED,
			'UNRATED_NEAR_MISS'      => self::CLASS_UNRATED,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Time helpers (version-independent; never getTimestamp() on a local) */
	/* ------------------------------------------------------------------ */

	/**
	 * The shops' timezone object.
	 *
	 * @return DateTimeZone
	 */
	public static function tz() {
		static $tz = null;
		if ( null === $tz ) {
			$tz = new DateTimeZone( self::TIMEZONE );
		}
		return $tz;
	}

	/**
	 * UTC offset in seconds at a UTC instant.
	 *
	 * @param int $ts UNIX time.
	 * @return int
	 */
	public static function offset( $ts ) {
		return (int) self::tz()->getOffset( new DateTime( '@' . (int) $ts ) );
	}

	/**
	 * Local wall-clock date and time of a UTC instant.
	 *
	 * @param int $ts UNIX time.
	 * @return array { date: 'Y-m-d', hm: 'H:i' }
	 */
	public static function local_parts( $ts ) {
		$local = (int) $ts + self::offset( $ts );
		return array(
			'date' => gmdate( 'Y-m-d', $local ),
			'hm'   => gmdate( 'H:i', $local ),
		);
	}

	/**
	 * Zone abbreviation in force at a UTC instant (AEST or AEDT), from the transition table.
	 *
	 * @param int $ts UNIX time.
	 * @return string
	 */
	public static function abbreviation( $ts ) {
		$transitions = self::tz()->getTransitions( (int) $ts, (int) $ts );
		if ( is_array( $transitions ) && isset( $transitions[0]['abbr'] ) && is_string( $transitions[0]['abbr'] ) ) {
			return $transitions[0]['abbr'];
		}
		$offset = self::offset( $ts );
		return sprintf( 'UTC%s%02d:%02d', $offset < 0 ? '-' : '+', intdiv( abs( $offset ), 3600 ), intdiv( abs( $offset ) % 3600, 60 ) );
	}

	/**
	 * Display string for a UTC instant: local date, time and zone abbreviation. The repeated hour on the
	 * last DST day therefore shows as "02:30 AEDT" and "02:30 AEST", never twice the same.
	 *
	 * @param int|null $ts UNIX time.
	 * @return string Empty for null.
	 */
	public static function format_local( $ts ) {
		if ( null === $ts ) {
			return '';
		}
		$parts = self::local_parts( $ts );
		return $parts['date'] . ' ' . $parts['hm'] . ' ' . self::abbreviation( $ts );
	}

	/**
	 * Add whole days to a Y-m-d date (calendar arithmetic, no timezone involved).
	 *
	 * @param string $date Y-m-d.
	 * @param int    $days Days (may be negative).
	 * @return string
	 */
	public static function add_days( $date, $days ) {
		$y = (int) substr( $date, 0, 4 );
		$m = (int) substr( $date, 5, 2 );
		$d = (int) substr( $date, 8, 2 );
		return gmdate( 'Y-m-d', gmmktime( 0, 0, 0, $m, $d + (int) $days, $y ) );
	}

	/**
	 * Whether a string is a valid Y-m-d calendar date.
	 *
	 * @param mixed $date Value.
	 * @return bool
	 */
	public static function valid_date( $date ) {
		if ( ! is_string( $date ) || 1 !== preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) && (int) $m[1] >= 2000 && (int) $m[1] <= 2100;
	}

	/**
	 * Whether a string is a valid HH:MM local time.
	 *
	 * @param mixed $hm Value.
	 * @return bool
	 */
	public static function valid_hm( $hm ) {
		return is_string( $hm ) && 1 === preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/D', $hm );
	}

	/**
	 * Business date of a UTC instant: the local date, moved back one day when the local time is before the
	 * shop's cutoff. With no cutoff configured the local calendar date is used.
	 *
	 * @param int         $ts     UNIX time.
	 * @param string|null $cutoff HH:MM or null.
	 * @return string Y-m-d.
	 */
	public static function business_date( $ts, $cutoff ) {
		$parts = self::local_parts( $ts );
		if ( null !== $cutoff && self::valid_hm( $cutoff ) && strcmp( $parts['hm'], $cutoff ) < 0 ) {
			return self::add_days( $parts['date'], -1 );
		}
		return $parts['date'];
	}

	/**
	 * UTC instant of a local wall-clock time.
	 *
	 * Unambiguous for the two DST edge cases: a time inside the spring-forward gap (does not exist) maps to
	 * the instant the gap ends; a time inside the autumn fold (exists twice) maps to the EARLIER instant.
	 * Built from getTransitions() only, so PHP 7.4 and 8.x give identical answers.
	 *
	 * @param string $date Y-m-d.
	 * @param string $hm   HH:MM.
	 * @return int|null Null when the input is invalid or the zone data cannot be read.
	 */
	public static function local_to_utc( $date, $hm ) {
		if ( ! self::valid_date( $date ) || ! self::valid_hm( $hm ) ) {
			return null;
		}
		$naive       = gmmktime( (int) substr( $hm, 0, 2 ), (int) substr( $hm, 3, 2 ), 0, (int) substr( $date, 5, 2 ), (int) substr( $date, 8, 2 ), (int) substr( $date, 0, 4 ) );
		$transitions = self::tz()->getTransitions( $naive - 2 * 86400, $naive + 2 * 86400 );
		if ( ! is_array( $transitions ) || array() === $transitions ) {
			return null;
		}
		$transitions = array_values( $transitions );
		$count       = count( $transitions );
		$valid       = array();
		for ( $k = 0; $k < $count; $k++ ) {
			$utc  = $naive - (int) $transitions[ $k ]['offset'];
			$low  = ( 0 === $k ) ? null : (int) $transitions[ $k ]['ts'];
			$high = ( $k + 1 < $count ) ? (int) $transitions[ $k + 1 ]['ts'] : null;
			if ( ( null === $low || $utc >= $low ) && ( null === $high || $utc < $high ) ) {
				$valid[] = $utc;
			}
		}
		if ( array() !== $valid ) {
			return min( $valid );
		}
		for ( $k = 1; $k < $count; $k++ ) {
			$before = (int) $transitions[ $k - 1 ]['offset'];
			$after  = (int) $transitions[ $k ]['offset'];
			$at     = (int) $transitions[ $k ]['ts'];
			if ( $after > $before && $naive >= $at + $before && $naive < $at + $after ) {
				return $at;
			}
		}
		return null;
	}

	/* ------------------------------------------------------------------ */
	/* Minute arithmetic                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Floor seconds to whole minutes since the epoch (also correct for values before 1970).
	 *
	 * @param int $ts Seconds.
	 * @return int
	 */
	public static function to_minute( $ts ) {
		return (int) floor( (int) $ts / 60 );
	}

	/**
	 * Recorded break minutes, exactly as core DoughBoss_Staff_Badge::break_minutes_for_shift(): each break is
	 * floored to whole minutes separately, an open break runs until $until, a break that does not end after
	 * it starts counts zero.
	 *
	 * @param array $breaks List of array( start => int, end => int|null ).
	 * @param int   $until  End used for an open break.
	 * @return int
	 */
	public static function break_minutes( array $breaks, $until ) {
		$total = 0;
		foreach ( $breaks as $break ) {
			$start  = (int) $break['start'];
			$finish = ( null === $break['end'] ) ? (int) $until : (int) $break['end'];
			if ( $start && $finish > $start ) {
				$total += (int) floor( ( $finish - $start ) / 60 );
			}
		}
		return max( 0, $total );
	}

	/**
	 * Net worked minutes, exactly as core DoughBoss_Timeclock::worked_minutes() for a closed shift:
	 * floor( (out - in) / 60 ) minus the recorded breaks, never below zero.
	 *
	 * @param int   $in     Clock-in (UNIX time).
	 * @param int   $until  Clock-out, or the provisional end for an open shift.
	 * @param array $breaks Breaks.
	 * @return int
	 */
	public static function net_minutes( $in, $until, array $breaks ) {
		return max( 0, (int) floor( ( (int) $until - (int) $in ) / 60 ) - self::break_minutes( $breaks, $until ) );
	}

	/* ------------------------------------------------------------------ */
	/* Matching                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Reconcile one window.
	 *
	 * @param array $input {
	 *     now:               int UNIX time of the run (provisional end of open entries),
	 *     report_from:       Y-m-d first business day reported (inclusive),
	 *     report_to:         Y-m-d last business day reported (inclusive),
	 *     params:            sanitised doughboss_growth_recon parameters (unset values are null),
	 *     employees:         array( user_id => team_member_id ) confirmed one-to-one,
	 *     locations:         array( plugin_location_id => square_location_id ) confirmed one-to-one,
	 *     blocked_locations: array( plugin_location_id => reason ) shops forced to UNMAPPED_LOCATION,
	 *     shifts:            normalised plugin shifts (DoughBoss_Growth_Recon_Reader::read()),
	 *     timecards:         normalised Square timecards (DoughBoss_Growth_Recon_Square::normalise_timecard()),
	 * }
	 * @return array List of rows (see build_row()), sorted by workday, shop, employee, start.
	 */
	public static function match( array $input ) {
		$now       = (int) $input['now'];
		$params    = $input['params'];
		$employees = isset( $input['employees'] ) && is_array( $input['employees'] ) ? $input['employees'] : array();
		$locations = isset( $input['locations'] ) && is_array( $input['locations'] ) ? $input['locations'] : array();
		$blocked   = isset( $input['blocked_locations'] ) && is_array( $input['blocked_locations'] ) ? $input['blocked_locations'] : array();
		$cutoffs   = ( isset( $params['business_day_cutoff_local'] ) && is_array( $params['business_day_cutoff_local'] ) ) ? $params['business_day_cutoff_local'] : array();

		$member_to_user = array();
		foreach ( $employees as $user_id => $member ) {
			$member_to_user[ (string) $member ] = (int) $user_id;
		}
		$square_to_plugin = array();
		foreach ( $locations as $plugin_location => $square_location ) {
			$square_to_plugin[ (string) $square_location ] = (int) $plugin_location;
		}

		$rows     = array();
		$by_staff = array();

		foreach ( $input['shifts'] as $shift ) {
			$item            = self::plugin_item( $shift, $now );
			$plugin_location = (int) $shift['location_id'];
			if ( isset( $blocked[ $plugin_location ] ) || ! isset( $locations[ $plugin_location ] ) ) {
				$rows[] = self::unmapped_row( 'UNMAPPED_LOCATION', $item, $plugin_location, '', (int) $shift['user_id'], '', $cutoffs );
				continue;
			}
			$item['square_location'] = (string) $locations[ $plugin_location ];
			if ( ! isset( $employees[ (int) $shift['user_id'] ] ) ) {
				$rows[] = self::unmapped_row( 'UNMAPPED_EMPLOYEE', $item, $plugin_location, $item['square_location'], (int) $shift['user_id'], '', $cutoffs );
				continue;
			}
			$by_staff[ (int) $shift['user_id'] ][] = $item;
		}

		foreach ( $input['timecards'] as $card ) {
			$item            = self::square_item( $card, $now );
			$square_location = (string) $card['location_id'];
			if ( ! isset( $square_to_plugin[ $square_location ] ) || isset( $blocked[ $square_to_plugin[ $square_location ] ] ) ) {
				$rows[] = self::unmapped_row( 'UNMAPPED_LOCATION', $item, 0, $square_location, 0, (string) $card['team_member_id'], $cutoffs );
				continue;
			}
			$item['plugin_location'] = $square_to_plugin[ $square_location ];
			$member                  = (string) $card['team_member_id'];
			if ( ! isset( $member_to_user[ $member ] ) ) {
				$rows[] = self::unmapped_row( 'UNMAPPED_EMPLOYEE', $item, $item['plugin_location'], $square_location, 0, $member, $cutoffs );
				continue;
			}
			$by_staff[ $member_to_user[ $member ] ][] = $item;
		}

		ksort( $by_staff );
		foreach ( $by_staff as $user_id => $items ) {
			$member = (string) $employees[ $user_id ];
			foreach ( self::employee_rows( (int) $user_id, $member, $items, $now, $params, $cutoffs ) as $row ) {
				$rows[] = $row;
			}
		}

		$from = (string) $input['report_from'];
		$to   = (string) $input['report_to'];
		$kept = array();
		foreach ( $rows as $row ) {
			if ( $row['has_open'] || ( strcmp( $row['workday_local'], $from ) >= 0 && strcmp( $row['workday_local'], $to ) <= 0 ) ) {
				$kept[] = $row;
			}
		}
		usort( $kept, array( __CLASS__, 'compare_rows' ) );
		return $kept;
	}

	/**
	 * Row order (stable: same input, same order).
	 *
	 * @param array $a Row.
	 * @param array $b Row.
	 * @return int
	 */
	public static function compare_rows( $a, $b ) {
		$keys = array( 'workday_local', 'plugin_location_id', 'user_id', 'anchor', 'stable_key' );
		foreach ( $keys as $key ) {
			if ( $a[ $key ] === $b[ $key ] ) {
				continue;
			}
			if ( is_int( $a[ $key ] ) && is_int( $b[ $key ] ) ) {
				return ( $a[ $key ] < $b[ $key ] ) ? -1 : 1;
			}
			return strcmp( (string) $a[ $key ], (string) $b[ $key ] ) < 0 ? -1 : 1;
		}
		return 0;
	}

	/**
	 * Internal item for a plugin shift.
	 *
	 * @param array $shift Normalised shift.
	 * @param int   $now   Run time.
	 * @return array
	 */
	private static function plugin_item( array $shift, $now ) {
		$open   = ( null === $shift['out'] );
		$until  = $open ? $now : (int) $shift['out'];
		$breaks = $shift['breaks'];
		$snap   = array(
			'shift_id'       => (int) $shift['shift_id'],
			'location_id'    => (int) $shift['location_id'],
			'timezone'       => (string) $shift['timezone'],
			'in'             => (int) $shift['in'],
			'out'            => $open ? null : (int) $shift['out'],
			'source'         => (string) $shift['source'],
			'manager_closed' => (bool) $shift['manager_closed'],
			'updated_at'     => isset( $shift['updated_at'] ) ? $shift['updated_at'] : null,
			'breaks'         => array(),
		);
		foreach ( $breaks as $break ) {
			$snap['breaks'][] = array( (int) $break['break_id'], (int) $break['start'], null === $break['end'] ? null : (int) $break['end'] );
		}
		return array(
			'side'            => 'p',
			'id'              => (string) (int) $shift['shift_id'],
			'start'           => (int) $shift['in'],
			'end'             => $open ? null : (int) $shift['out'],
			'start_min'       => self::to_minute( $shift['in'] ),
			'end_min'         => $open ? null : self::to_minute( $shift['out'] ),
			'eff_end_min'     => $open ? max( self::to_minute( $shift['in'] ), self::to_minute( $now ) ) : self::to_minute( $shift['out'] ),
			'open'            => $open,
			'duration_min'    => (int) floor( ( $until - (int) $shift['in'] ) / 60 ),
			'break_all'       => self::break_minutes( $breaks, $until ),
			'break_unpaid'    => null,
			'paid_break'      => false,
			'net'             => $open ? null : self::net_minutes( $shift['in'], $shift['out'], $breaks ),
			'net_unpaid'      => null,
			'plugin_location' => (int) $shift['location_id'],
			'square_location' => '',
			'timezone'        => (string) $shift['timezone'],
			'manager_closed'  => (bool) $shift['manager_closed'],
			'edited'          => false,
			'snapshot'        => $snap,
		);
	}

	/**
	 * Internal item for a Square timecard.
	 *
	 * @param array $card Normalised timecard.
	 * @param int   $now  Run time.
	 * @return array
	 */
	private static function square_item( array $card, $now ) {
		$open        = ( null === $card['end'] );
		$until       = $open ? $now : (int) $card['end'];
		$all         = array();
		$unpaid      = array();
		$paid_break  = false;
		$snap_breaks = array();
		foreach ( $card['breaks'] as $break ) {
			$entry = array(
				'start' => (int) $break['start'],
				'end'   => null === $break['end'] ? null : (int) $break['end'],
			);
			$all[] = $entry;
			if ( true === $break['is_paid'] ) {
				$paid_break = true;
			} else {
				$unpaid[] = $entry;
			}
			$snap_breaks[] = array( (string) $break['id'], $entry['start'], $entry['end'], (bool) $break['is_paid'] );
		}
		$gross   = (int) floor( ( $until - (int) $card['start'] ) / 60 );
		$edited  = ( (int) $card['version'] > 1 );
		if ( ! $open && null !== $card['updated_at'] && self::to_minute( $card['updated_at'] ) > self::to_minute( $card['end'] ) ) {
			$edited = true;
		}
		return array(
			'side'            => 's',
			'id'              => (string) $card['id'],
			'start'           => (int) $card['start'],
			'end'             => $open ? null : (int) $card['end'],
			'start_min'       => self::to_minute( $card['start'] ),
			'end_min'         => $open ? null : self::to_minute( $card['end'] ),
			'eff_end_min'     => $open ? max( self::to_minute( $card['start'] ), self::to_minute( $now ) ) : self::to_minute( $card['end'] ),
			'open'            => $open,
			'duration_min'    => $gross,
			'break_all'       => self::break_minutes( $all, $until ),
			'break_unpaid'    => self::break_minutes( $unpaid, $until ),
			'paid_break'      => $paid_break,
			'net'             => $open ? null : max( 0, $gross - self::break_minutes( $all, $until ) ),
			'net_unpaid'      => $open ? null : max( 0, $gross - self::break_minutes( $unpaid, $until ) ),
			'plugin_location' => 0,
			'square_location' => (string) $card['location_id'],
			'timezone'        => (string) $card['timezone'],
			'manager_closed'  => false,
			'edited'          => $edited,
			'snapshot'        => array(
				'id'             => (string) $card['id'],
				'team_member_id' => (string) $card['team_member_id'],
				'location_id'    => (string) $card['location_id'],
				'timezone'       => (string) $card['timezone'],
				'start'          => (int) $card['start'],
				'end'            => $open ? null : (int) $card['end'],
				'status'         => (string) $card['status'],
				'version'        => (int) $card['version'],
				'updated_at'     => $card['updated_at'],
				'breaks'         => $snap_breaks,
			),
		);
	}

	/**
	 * Minutes two items overlap (half-open minute intervals; an open item runs to the run time).
	 *
	 * @param array $a Item.
	 * @param array $b Item.
	 * @return int
	 */
	private static function overlap( array $a, array $b ) {
		return min( $a['eff_end_min'], $b['eff_end_min'] ) - max( $a['start_min'], $b['start_min'] );
	}

	/**
	 * Distance in minutes between two non-overlapping items (0 when they touch or overlap).
	 *
	 * @param array $a Item.
	 * @param array $b Item.
	 * @return int
	 */
	private static function gap( array $a, array $b ) {
		return max( 0, max( $b['start_min'] - $a['eff_end_min'], $a['start_min'] - $b['eff_end_min'] ) );
	}

	/**
	 * Order items: start, then plugin before Square, then id.
	 *
	 * @param array $a Item.
	 * @param array $b Item.
	 * @return int
	 */
	private static function compare_items( $a, $b ) {
		if ( $a['start'] !== $b['start'] ) {
			return ( $a['start'] < $b['start'] ) ? -1 : 1;
		}
		if ( $a['side'] !== $b['side'] ) {
			return ( 'p' === $a['side'] ) ? -1 : 1;
		}
		return strcmp( $a['id'], $b['id'] );
	}

	/**
	 * Rows for one mapped employee.
	 *
	 * @param int    $user_id WordPress user id.
	 * @param string $member  Square team member id.
	 * @param array  $items   Items of both sides.
	 * @param int    $now     Run time.
	 * @param array  $params  Parameters.
	 * @param array  $cutoffs Per-shop cutoffs.
	 * @return array
	 */
	private static function employee_rows( $user_id, $member, array $items, $now, array $params, array $cutoffs ) {
		usort( $items, array( __CLASS__, 'compare_items' ) );
		$count  = count( $items );
		$parent = range( 0, max( 0, $count - 1 ) );
		$same   = array_fill( 0, $count, false );
		$cross  = array_fill( 0, $count, false );

		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( self::overlap( $items[ $i ], $items[ $j ] ) < 1 ) {
					continue;
				}
				if ( $items[ $i ]['side'] !== $items[ $j ]['side'] ) {
					self::union( $parent, $i, $j );
					continue;
				}
				$same[ $i ] = true;
				$same[ $j ] = true;
				if ( $items[ $i ]['square_location'] !== $items[ $j ]['square_location'] ) {
					$cross[ $i ] = true;
					$cross[ $j ] = true;
				}
			}
		}
		for ( $i = 0; $i < $count; $i++ ) {
			$items[ $i ]['same_side']  = $same[ $i ];
			$items[ $i ]['cross_loc']  = $cross[ $i ];
		}

		$groups = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$groups[ self::find( $parent, $i ) ][] = $items[ $i ];
		}

		$rows     = array();
		$singles  = array(
			'p' => array(),
			's' => array(),
		);
		foreach ( $groups as $group ) {
			$plugin = array();
			$square = array();
			foreach ( $group as $item ) {
				if ( 'p' === $item['side'] ) {
					$plugin[] = $item;
				} else {
					$square[] = $item;
				}
			}
			if ( array() !== $plugin && array() !== $square ) {
				$kind   = ( 1 === count( $plugin ) && 1 === count( $square ) ) ? 'pair' : 'split';
				$rows[] = self::build_row( $kind, $plugin, $square, $user_id, $member, $now, $params, $cutoffs );
			} elseif ( array() !== $plugin ) {
				$singles['p'][] = $plugin[0];
			} else {
				$singles['s'][] = $square[0];
			}
		}

		$near_miss = isset( $params['near_miss_search_minutes'] ) ? $params['near_miss_search_minutes'] : null;
		$used_p    = array();
		$used_s    = array();
		if ( null !== $near_miss ) {
			$candidates = array();
			foreach ( $singles['p'] as $pi => $p ) {
				foreach ( $singles['s'] as $si => $s ) {
					$gap = self::gap( $p, $s );
					if ( $gap <= (int) $near_miss ) {
						$candidates[] = array( $gap, min( $p['start'], $s['start'] ), $p['id'], $s['id'], $pi, $si );
					}
				}
			}
			usort( $candidates, array( __CLASS__, 'compare_candidates' ) );
			foreach ( $candidates as $candidate ) {
				$pi = $candidate[4];
				$si = $candidate[5];
				if ( isset( $used_p[ $pi ] ) || isset( $used_s[ $si ] ) ) {
					continue;
				}
				$used_p[ $pi ] = true;
				$used_s[ $si ] = true;
				$rows[]        = self::build_row( 'near_miss', array( $singles['p'][ $pi ] ), array( $singles['s'][ $si ] ), $user_id, $member, $now, $params, $cutoffs );
			}
		}
		foreach ( $singles['p'] as $pi => $p ) {
			if ( ! isset( $used_p[ $pi ] ) ) {
				$rows[] = self::build_row( 'plugin_only', array( $p ), array(), $user_id, $member, $now, $params, $cutoffs );
			}
		}
		foreach ( $singles['s'] as $si => $s ) {
			if ( ! isset( $used_s[ $si ] ) ) {
				$rows[] = self::build_row( 'square_only', array(), array( $s ), $user_id, $member, $now, $params, $cutoffs );
			}
		}
		return $rows;
	}

	/**
	 * Near-miss candidate order: smallest gap, earliest start, then ids.
	 *
	 * @param array $a Candidate.
	 * @param array $b Candidate.
	 * @return int
	 */
	private static function compare_candidates( $a, $b ) {
		for ( $k = 0; $k < 4; $k++ ) {
			if ( $a[ $k ] === $b[ $k ] ) {
				continue;
			}
			if ( is_int( $a[ $k ] ) ) {
				return ( $a[ $k ] < $b[ $k ] ) ? -1 : 1;
			}
			return strcmp( $a[ $k ], $b[ $k ] ) < 0 ? -1 : 1;
		}
		return 0;
	}

	/**
	 * Union-find: root of $i with path halving.
	 *
	 * @param array $parent Parent array.
	 * @param int   $i      Index.
	 * @return int
	 */
	private static function find( array &$parent, $i ) {
		while ( $parent[ $i ] !== $i ) {
			$parent[ $i ] = $parent[ $parent[ $i ] ];
			$i            = $parent[ $i ];
		}
		return $i;
	}

	/**
	 * Union-find: join two sets (the smaller index becomes the root, so group order is stable).
	 *
	 * @param array $parent Parent array.
	 * @param int   $a      Index.
	 * @param int   $b      Index.
	 * @return void
	 */
	private static function union( array &$parent, $a, $b ) {
		$ra = self::find( $parent, $a );
		$rb = self::find( $parent, $b );
		if ( $ra === $rb ) {
			return;
		}
		if ( $ra < $rb ) {
			$parent[ $rb ] = $ra;
		} else {
			$parent[ $ra ] = $rb;
		}
	}

	/**
	 * Rate one comparison. Unset tolerance: UNRATED code. Delta strictly above the tolerance: the diff code.
	 *
	 * @param array    $codes     Codes collected so far (by reference).
	 * @param int      $delta     Signed delta in minutes.
	 * @param int|null $tolerance Tolerance or null.
	 * @param string   $diff      Code when out of tolerance.
	 * @param string   $unrated   Code when the tolerance is unset.
	 * @return void
	 */
	private static function rate( array &$codes, $delta, $tolerance, $diff, $unrated ) {
		if ( null === $tolerance ) {
			$codes[ $unrated ] = true;
			return;
		}
		if ( abs( (int) $delta ) > (int) $tolerance ) {
			$codes[ $diff ] = true;
		}
	}

	/**
	 * Build one evidence row from the plugin and Square items it covers.
	 *
	 * @param string $kind    pair|split|near_miss|plugin_only|square_only.
	 * @param array  $plugin  Plugin items.
	 * @param array  $square  Square items.
	 * @param int    $user_id WordPress user id.
	 * @param string $member  Square team member id.
	 * @param int    $now     Run time.
	 * @param array  $params  Parameters.
	 * @param array  $cutoffs Per-shop cutoffs.
	 * @return array
	 */
	private static function build_row( $kind, array $plugin, array $square, $user_id, $member, $now, array $params, array $cutoffs ) {
		$codes    = array();
		$p_open   = false;
		$s_open   = false;
		$all      = array_merge( $plugin, $square );
		usort( $all, array( __CLASS__, 'compare_items' ) );
		$anchor   = $all[0];

		foreach ( $plugin as $item ) {
			$p_open = $p_open || $item['open'];
		}
		foreach ( $square as $item ) {
			$s_open = $s_open || $item['open'];
		}

		$side = array(
			'p' => self::side_totals( $plugin ),
			's' => self::side_totals( $square ),
		);

		$row = array(
			'start_delta' => null,
			'end_delta'   => null,
			'break_delta' => null,
			'net_delta'   => null,
		);

		if ( array() !== $plugin && array() !== $square ) {
			$row['start_delta'] = $side['p']['start_min'] - $side['s']['start_min'];
			self::rate( $codes, $row['start_delta'], self::param( $params, 'start_tolerance_minutes' ), 'START_DIFF', 'UNRATED_START' );
			if ( ! $p_open && ! $s_open ) {
				$row['end_delta']   = $side['p']['end_min'] - $side['s']['end_min'];
				$row['break_delta'] = $side['p']['break_all'] - $side['s']['break_all'];
				$row['net_delta']   = $side['p']['net'] - $side['s']['net'];
				self::rate( $codes, $row['end_delta'], self::param( $params, 'end_tolerance_minutes' ), 'END_DIFF', 'UNRATED_END' );
				self::rate( $codes, $row['break_delta'], self::param( $params, 'break_tolerance_minutes' ), 'BREAK_DIFF', 'UNRATED_BREAK' );
				self::rate( $codes, $row['net_delta'], self::param( $params, 'net_tolerance_minutes' ), 'NET_DIFF', 'UNRATED_NET' );
			} elseif ( $p_open !== $s_open ) {
				$codes['OPEN_ONE_SIDE'] = true;
			}
			$p_locations = array();
			$s_locations = array();
			$p_zones     = array();
			foreach ( $plugin as $item ) {
				$p_locations[ $item['square_location'] ] = true;
				$p_zones[ $item['timezone'] ]           = true;
			}
			foreach ( $square as $item ) {
				$s_locations[ $item['square_location'] ] = true;
				if ( '' !== $item['timezone'] ) {
					foreach ( array_keys( $p_zones ) as $zone ) {
						if ( (string) $zone !== $item['timezone'] ) {
							$codes['TIMEZONE_MISMATCH'] = true;
						}
					}
				}
			}
			ksort( $p_locations );
			ksort( $s_locations );
			if ( array_keys( $p_locations ) !== array_keys( $s_locations ) ) {
				$codes['LOCATION_MISMATCH'] = true;
			}
		}

		if ( 'split' === $kind ) {
			$codes['SPLIT'] = true;
		} elseif ( 'near_miss' === $kind ) {
			$codes['NO_OVERLAP_NEAR_MISS'] = true;
		} elseif ( 'plugin_only' === $kind ) {
			$codes['MISSING_IN_SQUARE'] = true;
		} elseif ( 'square_only' === $kind ) {
			$codes['MISSING_IN_PLUGIN'] = true;
		}
		if ( ( 'plugin_only' === $kind || 'square_only' === $kind ) && null === self::param( $params, 'near_miss_search_minutes' ) ) {
			$codes['UNRATED_NEAR_MISS'] = true;
		}

		$open_alert = self::param( $params, 'open_alert_after_minutes' );
		$max_shift  = self::param( $params, 'max_shift_minutes' );
		foreach ( $all as $item ) {
			if ( $item['open'] ) {
				if ( null === $open_alert ) {
					$codes['UNRATED_OPEN_ALERT'] = true;
				} elseif ( (int) floor( ( $now - $item['start'] ) / 60 ) > $open_alert ) {
					$codes[ 'p' === $item['side'] ? 'OPEN_PLUGIN_SHIFT' : 'OPEN_SQUARE_TIMECARD' ] = true;
				}
			}
			if ( null === $max_shift ) {
				$codes['UNRATED_LONG_SHIFT'] = true;
			} elseif ( $item['duration_min'] > $max_shift ) {
				$codes['LONG_SHIFT'] = true;
			}
			if ( $item['manager_closed'] ) {
				$codes['MANAGER_CLOSED'] = true;
			}
			if ( $item['paid_break'] ) {
				$codes['BREAK_PAID_CLASS'] = true;
			}
			if ( $item['edited'] ) {
				$codes['SQUARE_EDITED'] = true;
			}
			if ( ! empty( $item['same_side'] ) ) {
				$codes['OVERLAP_SAME_SIDE'] = true;
			}
			if ( ! empty( $item['cross_loc'] ) ) {
				$codes['CROSS_LOCATION_OVERLAP'] = true;
			}
		}

		// The row's shop is the plugin shop (the WordPress system of record for shops) when the plugin side is
		// present; a Square-only row uses the plugin shop its Square location is mapped to.
		$plugin_location = ( array() !== $plugin ) ? $plugin[0]['plugin_location'] : $anchor['plugin_location'];
		$square_location = ( array() !== $square ) ? $square[0]['square_location'] : $anchor['square_location'];

		$out = array(
			'kind'                => $kind,
			'codes'               => self::ordered_codes( $codes ),
			'workday_local'       => self::business_date( $anchor['start'], self::cutoff_for( $cutoffs, $plugin_location ) ),
			'plugin_location_id'  => (int) $plugin_location,
			'square_location_id'  => (string) $square_location,
			'user_id'             => (int) $user_id,
			'team_member_id'      => (string) $member,
			'plugin_ids'          => self::ids( $plugin ),
			'square_ids'          => self::ids( $square ),
			'plugin_start'        => $side['p']['start'],
			'plugin_end'          => $side['p']['end'],
			'square_start'        => $side['s']['start'],
			'square_end'          => $side['s']['end'],
			'start_delta'         => $row['start_delta'],
			'end_delta'           => $row['end_delta'],
			'break_delta'         => $row['break_delta'],
			'plugin_break'        => $side['p']['break_all'],
			'square_break_all'    => $side['s']['break_all'],
			'square_break_unpaid' => $side['s']['break_unpaid'],
			'plugin_net'          => $side['p']['net'],
			'square_net_all'      => $side['s']['net'],
			'square_net_unpaid'   => $side['s']['net_unpaid'],
			'net_delta'           => $row['net_delta'],
			'anchor'              => (int) $anchor['start'],
			'has_open'            => ( $p_open || $s_open ),
		);
		$out['state']        = self::state( $out['codes'], $out['has_open'] );
		$out['stable_key']   = self::stable_key( 'u' . (int) $user_id . '|t' . $member, $out['plugin_ids'], $out['square_ids'] );
		$out['content_hash'] = self::content_hash( $plugin, $square );
		return $out;
	}

	/**
	 * A parameter value or null.
	 *
	 * @param array  $params Parameters.
	 * @param string $key    Key.
	 * @return int|null
	 */
	private static function param( array $params, $key ) {
		return ( isset( $params[ $key ] ) && is_int( $params[ $key ] ) ) ? $params[ $key ] : null;
	}

	/**
	 * The cutoff for a shop or null.
	 *
	 * @param array $cutoffs Cutoffs keyed by plugin location id.
	 * @param int   $shop    Plugin location id.
	 * @return string|null
	 */
	private static function cutoff_for( array $cutoffs, $shop ) {
		return ( isset( $cutoffs[ (int) $shop ] ) && self::valid_hm( $cutoffs[ (int) $shop ] ) ) ? $cutoffs[ (int) $shop ] : null;
	}

	/**
	 * Totals for one side: union start/end (seconds and minutes), summed breaks and nets. A side with any
	 * open item has no end, no net and no break total for comparison.
	 *
	 * @param array $items Items of one side.
	 * @return array
	 */
	private static function side_totals( array $items ) {
		$out = array(
			'start'        => null,
			'end'          => null,
			'start_min'    => null,
			'end_min'      => null,
			'break_all'    => null,
			'break_unpaid' => null,
			'net'          => null,
			'net_unpaid'   => null,
		);
		if ( array() === $items ) {
			return $out;
		}
		$open = false;
		foreach ( $items as $item ) {
			$open             = $open || $item['open'];
			$out['start']     = ( null === $out['start'] ) ? $item['start'] : min( $out['start'], $item['start'] );
			$out['start_min'] = ( null === $out['start_min'] ) ? $item['start_min'] : min( $out['start_min'], $item['start_min'] );
		}
		if ( $open ) {
			return $out;
		}
		$out['break_all'] = 0;
		$out['net']       = 0;
		$is_square        = ( 's' === $items[0]['side'] );
		if ( $is_square ) {
			$out['break_unpaid'] = 0;
			$out['net_unpaid']   = 0;
		}
		foreach ( $items as $item ) {
			$out['end']       = ( null === $out['end'] ) ? $item['end'] : max( $out['end'], $item['end'] );
			$out['end_min']   = ( null === $out['end_min'] ) ? $item['end_min'] : max( $out['end_min'], $item['end_min'] );
			$out['break_all'] += $item['break_all'];
			$out['net']       += $item['net'];
			if ( $is_square ) {
				$out['break_unpaid'] += $item['break_unpaid'];
				$out['net_unpaid']   += $item['net_unpaid'];
			}
		}
		return $out;
	}

	/**
	 * Sorted ids of one side.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	private static function ids( array $items ) {
		$ids = array();
		foreach ( $items as $item ) {
			$ids[] = $item['id'];
		}
		sort( $ids, SORT_STRING );
		return $ids;
	}

	/**
	 * Codes in catalogue order.
	 *
	 * @param array $codes Code => true.
	 * @return array
	 */
	private static function ordered_codes( array $codes ) {
		$out = array();
		foreach ( array_keys( self::codes() ) as $code ) {
			if ( isset( $codes[ $code ] ) ) {
				$out[] = $code;
			}
		}
		return $out;
	}

	/**
	 * Row state from its codes.
	 *
	 * @param array $codes    Codes.
	 * @param bool  $has_open Whether any item is open.
	 * @return string
	 */
	public static function state( array $codes, $has_open ) {
		$catalogue = self::codes();
		$classes   = array();
		foreach ( $codes as $code ) {
			if ( isset( $catalogue[ $code ] ) ) {
				$classes[ $catalogue[ $code ] ] = true;
			}
		}
		if ( isset( $classes[ self::CLASS_BLOCKER ] ) ) {
			return 'UNMAPPED';
		}
		if ( isset( $classes[ self::CLASS_REVIEW ] ) ) {
			return 'REVIEW';
		}
		if ( $has_open ) {
			return 'OPEN';
		}
		if ( isset( $classes[ self::CLASS_UNRATED ] ) ) {
			return 'UNRATED';
		}
		if ( isset( $classes[ self::CLASS_INFO ] ) ) {
			return 'INFO';
		}
		return 'MATCHED';
	}

	/**
	 * Stable identity of a row across runs.
	 *
	 * @param string $who        Employee key (or "unmapped").
	 * @param array  $plugin_ids Plugin shift ids.
	 * @param array  $square_ids Square timecard ids.
	 * @return string sha256 hex.
	 */
	public static function stable_key( $who, array $plugin_ids, array $square_ids ) {
		return hash( 'sha256', 'v1|' . $who . '|p:' . implode( ',', $plugin_ids ) . '|s:' . implode( ',', $square_ids ) );
	}

	/**
	 * Hash of both source snapshots (ids, instants, locations, versions, breaks; never a name or reason), so
	 * an edit on either side between runs is visible.
	 *
	 * @param array $plugin Plugin items.
	 * @param array $square Square items.
	 * @return string sha256 hex.
	 */
	public static function content_hash( array $plugin, array $square ) {
		$p = array();
		foreach ( $plugin as $item ) {
			$p[ $item['id'] ] = $item['snapshot'];
		}
		$s = array();
		foreach ( $square as $item ) {
			$s[ $item['id'] ] = $item['snapshot'];
		}
		ksort( $p, SORT_STRING );
		ksort( $s, SORT_STRING );
		return hash( 'sha256', (string) wp_json_encode( array( 'p' => array_values( $p ), 's' => array_values( $s ) ) ) );
	}

	/**
	 * A row for one item that cannot be evaluated because a mapping is missing.
	 *
	 * @param string $code            UNMAPPED_EMPLOYEE or UNMAPPED_LOCATION.
	 * @param array  $item            Item.
	 * @param int    $plugin_location Plugin location id (0 when unknown).
	 * @param string $square_location Square location id ('' when unknown).
	 * @param int    $user_id         User id (0 for a Square item).
	 * @param string $member          Team member id ('' for a plugin item).
	 * @param array  $cutoffs         Per-shop cutoffs.
	 * @return array
	 */
	private static function unmapped_row( $code, array $item, $plugin_location, $square_location, $user_id, $member, array $cutoffs ) {
		$is_plugin = ( 'p' === $item['side'] );
		$plugin    = $is_plugin ? array( $item ) : array();
		$square    = $is_plugin ? array() : array( $item );
		$row       = array(
			'kind'                => 'unmapped',
			'codes'               => array( $code ),
			'workday_local'       => self::business_date( $item['start'], self::cutoff_for( $cutoffs, $plugin_location ) ),
			'plugin_location_id'  => (int) $plugin_location,
			'square_location_id'  => (string) $square_location,
			'user_id'             => (int) $user_id,
			'team_member_id'      => (string) $member,
			'plugin_ids'          => self::ids( $plugin ),
			'square_ids'          => self::ids( $square ),
			'plugin_start'        => $is_plugin ? $item['start'] : null,
			'plugin_end'          => $is_plugin ? $item['end'] : null,
			'square_start'        => $is_plugin ? null : $item['start'],
			'square_end'          => $is_plugin ? null : $item['end'],
			'start_delta'         => null,
			'end_delta'           => null,
			'break_delta'         => null,
			'plugin_break'        => null,
			'square_break_all'    => null,
			'square_break_unpaid' => null,
			'plugin_net'          => null,
			'square_net_all'      => null,
			'square_net_unpaid'   => null,
			'net_delta'           => null,
			'anchor'              => (int) $item['start'],
			'has_open'            => (bool) $item['open'],
		);
		$row['state']        = 'UNMAPPED';
		$row['stable_key']   = self::stable_key( 'unmapped', $row['plugin_ids'], $row['square_ids'] );
		$row['content_hash'] = self::content_hash( $plugin, $square );
		return $row;
	}

	/**
	 * Summary counts: per workday and plugin shop, rows per state.
	 *
	 * @param array $rows Rows.
	 * @return array { total: array( state => n ), by_day_shop: array( "Y-m-d|shop" => array( state => n ) ) }
	 */
	public static function summarise( array $rows ) {
		$total = array_fill_keys( self::STATES, 0 );
		$cells = array();
		foreach ( $rows as $row ) {
			$total[ $row['state'] ]++;
			$key = $row['workday_local'] . '|' . (int) $row['plugin_location_id'];
			if ( ! isset( $cells[ $key ] ) ) {
				$cells[ $key ] = array_fill_keys( self::STATES, 0 );
			}
			$cells[ $key ][ $row['state'] ]++;
		}
		ksort( $cells, SORT_STRING );
		return array(
			'total'       => $total,
			'by_day_shop' => $cells,
		);
	}
}
