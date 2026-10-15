<?php
/**
 * Shop trading-hours presentation: weekly schedules, a readable summary and a
 * pure "open now" calculator evaluated in Sydney time.
 *
 * Read-only and presentation-only. This never books a slot, never reports
 * ordering availability and never changes checkout behaviour; online pickup
 * status remains DoughBoss_Locations::pickup_status().
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Weekly shop schedules for the public store cards.
 */
class DoughBoss_Store_Hours {

	const TIMEZONE = 'Australia/Sydney';

	/** ISO weekday keys used by DoughBoss_Locations::weekly_hours(). */
	const DAY_KEYS = array( 1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun' );

	/**
	 * Published trading hours used when a shop has no stored weekly hours.
	 * Weekday 1 = Monday … 7 = Sunday; ranges are [opens, closes] in 24h time.
	 *
	 * @return array<string,array<int,array<int,array{0:string,1:string}>>>
	 */
	public static function fallback_hours() {
		$hours = array(
			'revesby'   => self::same_hours( array( 1, 2, 3, 4, 5, 6, 7 ), '06:30', '14:30' ),
			'bankstown' => self::same_hours( array( 1, 2, 3, 4, 5 ), '07:00', '14:00' ),
			'roselands' => self::same_hours( array( 1, 2, 3, 4, 5, 6, 7 ), '08:00', '15:00' ),
		);
		return function_exists( 'apply_filters' ) ? (array) apply_filters( 'doughboss_store_fallback_hours', $hours ) : $hours;
	}

	/**
	 * Build a week where the listed days share one range.
	 *
	 * @param int[]  $days  ISO weekdays.
	 * @param string $open  HH:MM.
	 * @param string $close HH:MM.
	 * @return array<int,array<int,array{0:string,1:string}>>
	 */
	private static function same_hours( array $days, $open, $close ) {
		$week = self::empty_week();
		foreach ( $days as $day ) {
			$week[ (int) $day ] = array( array( $open, $close ) );
		}
		return $week;
	}

	/**
	 * @return array<int,array>
	 */
	private static function empty_week() {
		return array_fill( 1, 7, array() );
	}

	/**
	 * Whether a value is a valid 24h HH:MM clock.
	 *
	 * @param mixed $time Candidate.
	 * @return bool
	 */
	public static function valid_clock( $time ) {
		return is_string( $time ) && (bool) preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time );
	}

	/**
	 * Normalise an arbitrary week into weekday => list of valid same-day ranges.
	 * Invalid, empty or overnight (closes <= opens) ranges are dropped.
	 *
	 * @param mixed $week Candidate schedule.
	 * @return array<int,array<int,array{0:string,1:string}>>
	 */
	public static function normalise_week( $week ) {
		$out = self::empty_week();
		if ( ! is_array( $week ) ) {
			return $out;
		}
		foreach ( $week as $day => $ranges ) {
			$day = (int) $day;
			if ( $day < 1 || $day > 7 || ! is_array( $ranges ) ) {
				continue;
			}
			foreach ( $ranges as $range ) {
				if ( ! is_array( $range ) || 2 !== count( $range ) ) {
					continue;
				}
				$range = array_values( $range );
				if ( ! self::valid_clock( $range[0] ) || ! self::valid_clock( $range[1] ) || strcmp( $range[0], $range[1] ) >= 0 ) {
					continue;
				}
				$out[ $day ][] = array( $range[0], $range[1] );
			}
			usort(
				$out[ $day ],
				function ( $left, $right ) {
					return strcmp( $left[0], $right[0] );
				}
			);
		}
		return $out;
	}

	/**
	 * Convert DoughBoss_Locations::weekly_hours() output
	 * (mon => "06:30-14:30, 16:00-18:00") into a normalised week.
	 *
	 * @param array<string,string> $ranges Day key => comma-separated ranges.
	 * @return array<int,array<int,array{0:string,1:string}>>
	 */
	public static function from_weekly_ranges( array $ranges ) {
		$week = array();
		foreach ( self::DAY_KEYS as $day => $key ) {
			$week[ $day ] = array();
			$raw = isset( $ranges[ $key ] ) ? (string) $ranges[ $key ] : '';
			foreach ( explode( ',', $raw ) as $part ) {
				if ( preg_match( '/^\s*(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})\s*$/', $part, $match ) ) {
					$week[ $day ][] = array( $match[1], $match[2] );
				}
			}
		}
		return self::normalise_week( $week );
	}

	/**
	 * Whether a normalised week contains at least one range.
	 *
	 * @param array $week Normalised week.
	 * @return bool
	 */
	public static function has_hours( array $week ) {
		foreach ( $week as $ranges ) {
			if ( ! empty( $ranges ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Choose the schedule shown for a shop: stored weekly hours when present,
	 * otherwise the published fallback for that slug.
	 *
	 * @param string     $slug   Shop slug (revesby, bankstown, roselands).
	 * @param array|null $stored Stored weekly ranges (weekly_hours() format) or null.
	 * @return array{source:string,week:array}
	 */
	public static function schedule_for( $slug, $stored = null ) {
		if ( is_array( $stored ) ) {
			$week = self::from_weekly_ranges( $stored );
			if ( self::has_hours( $week ) ) {
				return array( 'source' => 'stored', 'week' => $week );
			}
		}
		$fallback = self::fallback_hours();
		$slug     = strtolower( (string) $slug );
		if ( isset( $fallback[ $slug ] ) ) {
			return array( 'source' => 'published', 'week' => self::normalise_week( $fallback[ $slug ] ) );
		}
		return array( 'source' => 'none', 'week' => self::empty_week() );
	}

	/**
	 * Read the stored weekly hours for the active shop with this slug.
	 * Returns null when the plugin tables are unavailable or no shop matches.
	 *
	 * @param string $slug Shop slug.
	 * @return array<string,string>|null
	 */
	public static function stored_ranges_for_slug( $slug ) {
		if ( ! class_exists( 'DoughBoss_Locations' ) ) {
			return null;
		}
		$use_stored = function_exists( 'apply_filters' ) ? (bool) apply_filters( 'doughboss_store_hours_use_stored', true, $slug ) : true;
		if ( ! $use_stored ) {
			return null;
		}
		try {
			foreach ( DoughBoss_Locations::all( true ) as $location ) {
				if ( is_object( $location ) && isset( $location->slug, $location->id ) && strtolower( (string) $location->slug ) === strtolower( (string) $slug ) ) {
					return DoughBoss_Locations::weekly_hours( (int) $location->id );
				}
			}
		} catch ( Throwable $e ) {
			return null;
		}
		return null;
	}

	/**
	 * Open/closed state for a moment, evaluated in Sydney local time.
	 *
	 * @param array                  $week Normalised week.
	 * @param DateTimeInterface|null $now  Instant to evaluate (defaults to now).
	 * @return array{known:bool,open:bool,closes_at:?string,next_open_day:?int,next_open_at:?string}
	 */
	public static function state( array $week, $now = null ) {
		$week  = self::normalise_week( $week );
		$state = array(
			'known'         => self::has_hours( $week ),
			'open'          => false,
			'closes_at'     => null,
			'next_open_day' => null,
			'next_open_at'  => null,
		);
		if ( ! $state['known'] ) {
			return $state;
		}
		try {
			$zone  = new DateTimeZone( self::TIMEZONE );
			$local = $now instanceof DateTimeInterface
				? ( new DateTimeImmutable( '@' . $now->getTimestamp() ) )->setTimezone( $zone )
				: new DateTimeImmutable( 'now', $zone );
		} catch ( Exception $e ) {
			$state['known'] = false;
			return $state;
		}
		$day   = (int) $local->format( 'N' );
		$clock = $local->format( 'H:i' );
		foreach ( $week[ $day ] as $range ) {
			if ( strcmp( $clock, $range[0] ) >= 0 && strcmp( $clock, $range[1] ) < 0 ) {
				$state['open']      = true;
				$state['closes_at'] = $range[1];
				return $state;
			}
		}
		for ( $offset = 0; $offset < 8; $offset++ ) {
			$candidate = ( ( $day - 1 + $offset ) % 7 ) + 1;
			foreach ( $week[ $candidate ] as $range ) {
				if ( 0 === $offset && strcmp( $range[0], $clock ) <= 0 ) {
					continue;
				}
				$state['next_open_day'] = $candidate;
				$state['next_open_at']  = $range[0];
				return $state;
			}
		}
		return $state;
	}

	/**
	 * Whether the shop is open at the given instant.
	 *
	 * @param array                  $week Normalised week.
	 * @param DateTimeInterface|null $now  Instant.
	 * @return bool
	 */
	public static function is_open( array $week, $now = null ) {
		$state = self::state( $week, $now );
		return $state['open'];
	}

	/**
	 * Customer-facing badge copy for a state.
	 *
	 * @param array                  $state State from state().
	 * @param DateTimeInterface|null $now   Instant used for "today" wording.
	 * @return string
	 */
	public static function badge_label( array $state, $now = null ) {
		if ( empty( $state['known'] ) ) {
			return '';
		}
		if ( ! empty( $state['open'] ) ) {
			return sprintf( 'Open now · until %s', self::format_clock( (string) $state['closes_at'] ) );
		}
		if ( empty( $state['next_open_day'] ) ) {
			return 'Closed now';
		}
		$today = (int) self::local_now( $now )->format( 'N' );
		$when  = (int) $state['next_open_day'] === $today ? '' : ' ' . self::day_label( (int) $state['next_open_day'] );
		return sprintf( 'Closed now · opens%s %s', $when, self::format_clock( (string) $state['next_open_at'] ) );
	}

	/**
	 * @param DateTimeInterface|null $now Instant.
	 * @return DateTimeImmutable
	 */
	private static function local_now( $now ) {
		$zone = new DateTimeZone( self::TIMEZONE );
		return $now instanceof DateTimeInterface
			? ( new DateTimeImmutable( '@' . $now->getTimestamp() ) )->setTimezone( $zone )
			: new DateTimeImmutable( 'now', $zone );
	}

	/**
	 * Short day label.
	 *
	 * @param int $day ISO weekday.
	 * @return string
	 */
	public static function day_label( $day ) {
		$labels = array( 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun' );
		return isset( $labels[ (int) $day ] ) ? $labels[ (int) $day ] : '';
	}

	/**
	 * 24h HH:MM to "6:30am" / "2pm".
	 *
	 * @param string $time HH:MM.
	 * @return string
	 */
	public static function format_clock( $time ) {
		if ( ! self::valid_clock( $time ) ) {
			return '';
		}
		list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );
		$suffix = $hour >= 12 ? 'pm' : 'am';
		$hour12 = $hour % 12;
		$hour12 = 0 === $hour12 ? 12 : $hour12;
		return $hour12 . ( $minute ? ':' . sprintf( '%02d', $minute ) : '' ) . $suffix;
	}

	/**
	 * Readable weekly summary such as "7 days · 6:30am–2:30pm" or
	 * "Mon–Fri · 7am–2pm". Groups consecutive days with identical hours.
	 *
	 * @param array $week Normalised week.
	 * @return string[] One line per group.
	 */
	public static function summary_lines( array $week ) {
		$week   = self::normalise_week( $week );
		$groups = array();
		foreach ( range( 1, 7 ) as $day ) {
			if ( empty( $week[ $day ] ) ) {
				continue;
			}
			$label = implode(
				', ',
				array_map(
					function ( $range ) {
						return self::format_clock( $range[0] ) . '–' . self::format_clock( $range[1] );
					},
					$week[ $day ]
				)
			);
			$last = count( $groups ) - 1;
			if ( $last >= 0 && $groups[ $last ]['label'] === $label && $groups[ $last ]['end'] === $day - 1 ) {
				$groups[ $last ]['end'] = $day;
			} else {
				$groups[] = array( 'start' => $day, 'end' => $day, 'label' => $label );
			}
		}
		$lines = array();
		foreach ( $groups as $group ) {
			if ( 1 === $group['start'] && 7 === $group['end'] ) {
				$days = '7 days';
			} elseif ( $group['start'] === $group['end'] ) {
				$days = self::day_label( $group['start'] );
			} else {
				$days = self::day_label( $group['start'] ) . '–' . self::day_label( $group['end'] );
			}
			$lines[] = $days . ' · ' . $group['label'];
		}
		return $lines;
	}

	/**
	 * Compact JSON for a data attribute consumed by the theme's live badge.
	 *
	 * @param array $week Normalised week.
	 * @return string
	 */
	public static function to_json( array $week ) {
		$week = self::normalise_week( $week );
		$out  = array();
		foreach ( $week as $day => $ranges ) {
			if ( $ranges ) {
				$out[ (string) $day ] = $ranges;
			}
		}
		$json = json_encode( (object) $out );
		return is_string( $json ) ? $json : '{}';
	}
}
