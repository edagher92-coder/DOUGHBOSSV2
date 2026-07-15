<?php
/**
 * Deterministic pickup-capacity slot calculator.
 *
 * This class deliberately contains no database writes. It is the shared,
 * testable planning core used by the preview API and, once transactional holds
 * are enabled, checkout. All stored instants remain UTC; wall-clock labels are
 * presentation only.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds location-scoped pickup windows from explicit operating rules.
 */
class DoughBoss_Capacity {

	/**
	 * Supported weekly schedule keys.
	 *
	 * @var string[]
	 */
	private static $weekdays = array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' );

	/**
	 * Calculate capacity windows.
	 *
	 * The method fails closed: malformed time zones, hours, demand or capacity
	 * return no windows. Usage is keyed by the UTC window start in MySQL format.
	 *
	 * @param array                  $config  Normalised location configuration.
	 * @param int                    $demand  Server-computed cart capacity units.
	 * @param array                  $usage   Used units keyed by UTC start.
	 * @param DateTimeImmutable|null $now_utc Injected UTC clock for deterministic tests.
	 * @return array<int,array<string,mixed>>
	 */
	public static function windows( array $config, $demand, array $usage = array(), $now_utc = null ) {
		$demand = (int) $demand;
		if ( $demand < 1 || $demand > 10000 || empty( $config['enabled'] ) || empty( $config['active'] ) || empty( $config['pickup_enabled'] ) ) {
			return array();
		}

		try {
			$timezone = new DateTimeZone( isset( $config['timezone'] ) ? (string) $config['timezone'] : '' );
		} catch ( Exception $e ) {
			return array();
		}

		$slot_minutes   = isset( $config['slot_minutes'] ) ? (int) $config['slot_minutes'] : 0;
		$notice_minutes = isset( $config['notice_minutes'] ) ? (int) $config['notice_minutes'] : 0;
		$horizon_days   = isset( $config['horizon_days'] ) ? (int) $config['horizon_days'] : 0;
		$capacity       = isset( $config['capacity_units'] ) ? (int) $config['capacity_units'] : 0;
		if ( $slot_minutes < 5 || $slot_minutes > 120 || $notice_minutes < 0 || $notice_minutes > 1440 || $horizon_days < 1 || $horizon_days > 31 || $capacity < 1 || $capacity > 10000 ) {
			return array();
		}

		$hours = self::validate_hours( isset( $config['hours'] ) ? $config['hours'] : array() );
		if ( false === $hours ) {
			return array();
		}

		$blackouts = array();
		foreach ( isset( $config['blackout_dates'] ) ? (array) $config['blackout_dates'] : array() as $date ) {
			if ( is_string( $date ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				$blackouts[ $date ] = true;
			}
		}

		if ( ! $now_utc instanceof DateTimeImmutable ) {
			try {
				$now_utc = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
			} catch ( Exception $e ) {
				return array();
			}
		}
		$now_utc       = $now_utc->setTimezone( new DateTimeZone( 'UTC' ) );
		$earliest      = $now_utc->modify( '+' . $notice_minutes . ' minutes' );
		$local_today   = $now_utc->setTimezone( $timezone )->setTime( 0, 0, 0 );
		$slot_seconds  = $slot_minutes * MINUTE_IN_SECONDS;
		$windows       = array();
		$seen_slot_ids = array();

		for ( $day = 0; $day < $horizon_days; ++$day ) {
			$local_date = $local_today->modify( '+' . $day . ' days' );
			$date_key   = $local_date->format( 'Y-m-d' );
			if ( isset( $blackouts[ $date_key ] ) ) {
				continue;
			}
			$weekday = self::$weekdays[ (int) $local_date->format( 'w' ) ];
			foreach ( $hours[ $weekday ] as $range ) {
				$open = self::local_instant( $date_key, $range[0], $timezone );
				$close_date = self::minutes( $range[1] ) <= self::minutes( $range[0] ) ? $local_date->modify( '+1 day' )->format( 'Y-m-d' ) : $date_key;
				$close = self::local_instant( $close_date, $range[1], $timezone );
				if ( ! $open || ! $close || $close <= $open ) {
					return array();
				}

				$cursor = $open->setTimezone( new DateTimeZone( 'UTC' ) );
				$end    = $close->setTimezone( new DateTimeZone( 'UTC' ) );
				while ( $cursor->getTimestamp() + $slot_seconds <= $end->getTimestamp() ) {
					$by = $cursor->modify( '+' . $slot_minutes . ' minutes' );
					if ( $cursor >= $earliest ) {
						$usage_key = $cursor->format( 'Y-m-d H:i:s' );
						$used      = isset( $usage[ $usage_key ] ) ? max( 0, (int) $usage[ $usage_key ] ) : 0;
						$remaining = max( 0, $capacity - $used );
						$slot_id   = self::slot_id( $config, $cursor, $by );
						if ( isset( $seen_slot_ids[ $slot_id ] ) ) {
							return array();
						}
						$seen_slot_ids[ $slot_id ] = true;
						$windows[] = array(
							'slot_id'        => $slot_id,
							'ready_from_utc' => $cursor->format( 'Y-m-d\TH:i:s\Z' ),
							'ready_by_utc'   => $by->format( 'Y-m-d\TH:i:s\Z' ),
							'local_date'     => $cursor->setTimezone( $timezone )->format( 'Y-m-d' ),
							'local_from'     => $cursor->setTimezone( $timezone )->format( 'H:i' ),
							'local_by'       => $by->setTimezone( $timezone )->format( 'H:i' ),
							'utc_offset'     => $cursor->setTimezone( $timezone )->format( 'P' ),
							'capacity_units' => $capacity,
							'remaining_units'=> $remaining,
							'availability'   => $demand <= $remaining ? 'available' : 'full',
						);
					}
					$cursor = $by;
				}
			}
		}

		return $windows;
	}

	/**
	 * Validate weekly ranges and reject malformed or overlapping local hours.
	 *
	 * @param mixed $raw Raw hours.
	 * @return array|false
	 */
	private static function validate_hours( $raw ) {
		if ( ! is_array( $raw ) ) {
			return false;
		}
		$out = array();
		foreach ( self::$weekdays as $weekday ) {
			$out[ $weekday ] = array();
			$ranges = isset( $raw[ $weekday ] ) ? $raw[ $weekday ] : array();
			if ( ! is_array( $ranges ) ) {
				return false;
			}
			$previous_end = -1;
			foreach ( $ranges as $range ) {
				if ( ! is_array( $range ) || 2 !== count( $range ) || ! self::valid_time( $range[0] ) || ! self::valid_time( $range[1] ) || $range[0] === $range[1] ) {
					return false;
				}
				$start = self::minutes( $range[0] );
				$end   = self::minutes( $range[1] );
				$effective_end = $end <= $start ? $end + 1440 : $end;
				if ( $start < $previous_end ) {
					return false;
				}
				$previous_end = $effective_end;
				$out[ $weekday ][] = array( (string) $range[0], (string) $range[1] );
			}
		}
		return $out;
	}

	/** @return bool */
	private static function valid_time( $value ) {
		return is_string( $value ) && (bool) preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value );
	}

	/** @return int */
	private static function minutes( $value ) {
		$parts = explode( ':', (string) $value );
		return ( (int) $parts[0] * 60 ) + (int) $parts[1];
	}

	/**
	 * Parse a wall time and prove PHP did not silently normalise it across a DST gap.
	 *
	 * @return DateTimeImmutable|false
	 */
	private static function local_instant( $date, $time, DateTimeZone $timezone ) {
		$instant = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $time, $timezone );
		if ( ! $instant || $instant->format( 'Y-m-d H:i' ) !== $date . ' ' . $time ) {
			return false;
		}
		return $instant;
	}

	/** @return string */
	private static function slot_id( array $config, DateTimeImmutable $from, DateTimeImmutable $by ) {
		$location_id = isset( $config['location_id'] ) ? (int) $config['location_id'] : 0;
		$version     = isset( $config['planning_version'] ) ? max( 1, (int) $config['planning_version'] ) : 1;
		return 'dbs_' . substr( hash( 'sha256', $location_id . '|' . $version . '|' . $from->format( 'U' ) . '|' . $by->format( 'U' ) ), 0, 32 );
	}
}
