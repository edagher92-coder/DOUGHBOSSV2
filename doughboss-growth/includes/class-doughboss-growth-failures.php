<?php
/**
 * DoughBoss Growth failure log: a small, capped, already-redacted list of things that went wrong, kept where the owner
 * can see it.
 *
 * Why it exists. The companion fails closed: a module that throws, a table that cannot be created, an outbound request
 * that is refused, a page that cannot be composed. All of those used to end in DoughBoss_Growth_Http::log(), which
 * writes only when WP_DEBUG_LOG is on and otherwise reaches nobody, so on a normal production site the owner saw a
 * feature quietly missing and no reason. This class is the second sink: it keeps what Http::log() was given so the
 * Growth settings screen can show it.
 *
 * PUBLIC API (stable; later work packages may call it)
 *
 *   DoughBoss_Growth_Failures::record( $code, array $context = array() ): bool
 *       Note one failure. $code is a short snake_case name (a-z, 0-9, underscore, at most 64 characters; anything else
 *       is normalised, an empty one becomes "unknown_failure"). $context is a few scalar facts WITHOUT personal data
 *       (stage, module, channel, exception class name, status): it is redacted again here, capped at 5 keys and 80
 *       characters a value, keys that look personal (email, phone, name, address, ip, token ...) are dropped and a URL
 *       is reduced to its host. Never throws. Returns true when the failure is held in the list, false when it could not
 *       be stored. A repeat of the same code WITH THE SAME CONTEXT within 60 seconds of the last one is folded in without
 *       a write, so a failure that happens on every page view costs at most one option write a minute. A repeat with a
 *       different context (another stage, another module) is a new occurrence and is written at once, so no stage is lost.
 *   DoughBoss_Growth_Failures::all(): array
 *       Records, newest first. Each is array( code, count, first_seen, last_seen, context ): count is the number of
 *       times recorded (identical repeats inside the 60 second window count once), first_seen and last_seen are UNIX times, context
 *       is the redacted facts from the latest occurrence. Empty when nothing is recorded or the option is corrupt.
 *   DoughBoss_Growth_Failures::count(): int
 *       How many distinct codes are held (at most 20; when full, the code seen longest ago is dropped for a new one).
 *   DoughBoss_Growth_Failures::clear( $code = null ): bool
 *       Forget one code, or everything with null. True when the code (or the list) is gone afterwards; false when the
 *       write failed. Clearing an empty list writes nothing.
 *   DoughBoss_Growth_Failures::from_log( $event, array $context ): bool
 *       Used by DoughBoss_Growth_Http::log(): records the event only when it is a failure (see code_for_event()).
 *       Other code should call DoughBoss_Growth_Http::log( 'something_failed', ... ) (debug log, action and this list in
 *       one call) or record() directly.
 *
 * STORAGE: ONE option, "doughboss_growth_failures", written with autoload "no" so it costs nothing on a request that has
 * no failure. It holds a list of records only, never a request, response, address or message text. It is removed by a
 * data-deleting uninstall (DoughBoss_Growth_Activator::OPTIONS). A read-modify-write race between two simultaneous
 * failures can lose one count; the list is a hint for the owner, not an audit trail.
 *
 * This class must stay free of side effects at include time, and must never call Http::log() (that would loop).
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Failure list.
 */
final class DoughBoss_Growth_Failures {

	/**
	 * Option holding the list (written with autoload "no").
	 */
	const OPTION = 'doughboss_growth_failures';

	/**
	 * Most distinct codes held.
	 */
	const MAX_CODES = 20;

	/**
	 * Most context keys kept for a record.
	 */
	const MAX_CONTEXT_KEYS = 5;

	/**
	 * Longest context value, in characters.
	 */
	const MAX_VALUE_LENGTH = 80;

	/**
	 * Longest code, in characters.
	 */
	const MAX_CODE_LENGTH = 64;

	/**
	 * A repeat of a code with the same context inside this many seconds of its last occurrence is folded in without a write.
	 */
	const REPEAT_WINDOW = 60;

	/**
	 * Ceiling for a stored count (a corrupt value can never overflow the display).
	 */
	const MAX_COUNT = 1000000;

	/**
	 * Words in a context key that mark it as possibly personal or secret: such a key is dropped.
	 */
	const PERSONAL_KEY_WORDS = array( 'email', 'phone', 'mobile', 'name', 'address', 'ip', 'agent', 'user', 'token', 'secret', 'password', 'key', 'auth', 'cookie', 'signature', 'body', 'payload', 'message' );

	/**
	 * Note one failure. See the class comment for the contract.
	 *
	 * @param string $code    Short snake_case failure code.
	 * @param array  $context Scalar facts without personal data.
	 * @return bool True when the failure is held in the list.
	 */
	public static function record( $code, array $context = array() ) {
		try {
			$code = self::normalise_code( $code );
			if ( '' === $code ) {
				$code = 'unknown_failure';
			}
			$now     = self::now();
			$records = self::load();
			$clean   = self::clean_context( $context );

			if ( isset( $records[ $code ] ) ) {
				$elapsed = $now - $records[ $code ]['last_seen'];
				if ( $elapsed >= 0 && $elapsed < self::REPEAT_WINDOW && $records[ $code ]['context'] === $clean ) {
					return true; // The same failure, noted a moment ago: no write on a failure that repeats on every request.
				}
				$records[ $code ]['count']     = min( self::MAX_COUNT, $records[ $code ]['count'] + 1 );
				$records[ $code ]['last_seen'] = $now;
				$records[ $code ]['context']   = $clean;
			} else {
				while ( count( $records ) >= self::MAX_CODES ) {
					unset( $records[ self::oldest_code( $records ) ] );
				}
				$records[ $code ] = array(
					'code'       => $code,
					'count'      => 1,
					'first_seen' => $now,
					'last_seen'  => $now,
					'context'    => $clean,
				);
			}
			return self::store( $records );
		} catch ( Throwable $e ) {
			return false; // The failure log must never be the thing that breaks a request.
		}
	}

	/**
	 * Every recorded failure, newest first.
	 *
	 * @return array List of array( code, count, first_seen, last_seen, context ).
	 */
	public static function all() {
		try {
			$records = array_values( self::load() );
			usort( $records, array( __CLASS__, 'compare_newest_first' ) );
			return $records;
		} catch ( Throwable $e ) {
			return array();
		}
	}

	/**
	 * How many distinct failure codes are held.
	 *
	 * @return int
	 */
	public static function count() {
		try {
			return count( self::load() );
		} catch ( Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Forget one failure code, or all of them.
	 *
	 * @param string|null $code Code to forget, or null for everything.
	 * @return bool True when the code (or the whole list) is gone afterwards.
	 */
	public static function clear( $code = null ) {
		try {
			if ( null === $code ) {
				if ( false === get_option( self::OPTION, false ) ) {
					return true; // Nothing held: nothing to write.
				}
				delete_option( self::OPTION );
				return false === get_option( self::OPTION, false );
			}
			$code = self::normalise_code( $code );
			if ( '' === $code ) {
				return false;
			}
			$records = self::load();
			if ( ! isset( $records[ $code ] ) ) {
				return true;
			}
			unset( $records[ $code ] );
			if ( array() === $records ) {
				delete_option( self::OPTION );
				return false === get_option( self::OPTION, false );
			}
			return self::store( $records );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * The failure code for a Http::log() event, or an empty string when the event is not a failure.
	 *
	 * A failure is: an event whose name ends _failed, _refused, _error or _failure; waitlist_webhook_enqueue (logged only
	 * when the outbox did not accept the row); an "http" line that is a transport error or a non-2xx status; and a
	 * "recon_run" line whose status is FAILED. Everything else is information and is never recorded.
	 *
	 * @param string $event   Event name as given to Http::log().
	 * @param array  $context Redacted scalar context.
	 * @return string
	 */
	public static function code_for_event( $event, array $context = array() ) {
		$event = self::normalise_code( $event );
		if ( '' === $event ) {
			return '';
		}
		if ( 'http' === $event ) {
			if ( isset( $context['error'] ) && 'transport_error' === $context['error'] ) {
				return 'http_transport_error';
			}
			if ( isset( $context['status'] ) && is_numeric( $context['status'] ) ) {
				$status = (int) $context['status'];
				return ( $status < 200 || $status >= 300 ) ? 'http_status_error' : '';
			}
			return '';
		}
		if ( 'recon_run' === $event ) {
			return ( isset( $context['status'] ) && 'FAILED' === $context['status'] ) ? 'recon_run_failed' : '';
		}
		if ( 'waitlist_webhook_enqueue' === $event ) {
			return $event;
		}
		return ( 1 === preg_match( '/_(?:failed|refused|error|failure)$/D', $event ) ) ? $event : '';
	}

	/**
	 * Record a Http::log() event when it is a failure.
	 *
	 * @param string $event   Event name.
	 * @param array  $context Redacted scalar context.
	 * @return bool True when the event was a failure and is now held.
	 */
	public static function from_log( $event, array $context = array() ) {
		try {
			$code = self::code_for_event( $event, $context );
			if ( '' === $code ) {
				return false;
			}
			return self::record( $code, $context );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/* ------------------------------------------------------------------------------------------ */
	/* Internals                                                                                    */
	/* ------------------------------------------------------------------------------------------ */

	/**
	 * Current time (the companion clock when it is loaded, so tests can move it).
	 *
	 * @return int
	 */
	private static function now() {
		return class_exists( 'DoughBoss_Growth', false ) ? (int) DoughBoss_Growth::now() : time();
	}

	/**
	 * A code reduced to a-z, 0-9 and underscore, at most MAX_CODE_LENGTH characters.
	 *
	 * @param mixed $code Candidate.
	 * @return string Empty when nothing usable is left.
	 */
	private static function normalise_code( $code ) {
		if ( ! is_string( $code ) && ! is_int( $code ) ) {
			return '';
		}
		$code = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $code ) );
		return substr( (string) $code, 0, self::MAX_CODE_LENGTH );
	}

	/**
	 * Read the stored list, repairing anything corrupt: unknown shapes are dropped, counts and times are made sane, the
	 * list is capped to the newest MAX_CODES.
	 *
	 * @return array Code => record.
	 */
	private static function load() {
		$stored = get_option( self::OPTION, array() );
		$out    = array();
		if ( ! is_array( $stored ) ) {
			return $out;
		}
		foreach ( $stored as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['code'] ) ) {
				continue;
			}
			$code = self::normalise_code( $item['code'] );
			if ( '' === $code ) {
				continue;
			}
			$record = array(
				'code'       => $code,
				'count'      => ( isset( $item['count'] ) && is_int( $item['count'] ) && $item['count'] >= 1 ) ? min( self::MAX_COUNT, $item['count'] ) : 1,
				'first_seen' => ( isset( $item['first_seen'] ) && is_int( $item['first_seen'] ) && $item['first_seen'] > 0 ) ? $item['first_seen'] : 0,
				'last_seen'  => ( isset( $item['last_seen'] ) && is_int( $item['last_seen'] ) && $item['last_seen'] > 0 ) ? $item['last_seen'] : 0,
				'context'    => ( isset( $item['context'] ) && is_array( $item['context'] ) ) ? self::clean_context( $item['context'] ) : array(),
			);
			if ( isset( $out[ $code ] ) && $out[ $code ]['last_seen'] >= $record['last_seen'] ) {
				continue; // A duplicate code: keep the more recent record.
			}
			$out[ $code ] = $record;
		}
		while ( count( $out ) > self::MAX_CODES ) {
			unset( $out[ self::oldest_code( $out ) ] );
		}
		return $out;
	}

	/**
	 * Write the list and confirm it by reading it back.
	 *
	 * @param array $records Code => record.
	 * @return bool True when the stored list is the one given.
	 */
	private static function store( array $records ) {
		$list = array_values( $records );
		update_option( self::OPTION, $list, 'no' );
		return get_option( self::OPTION, false ) === $list;
	}

	/**
	 * The code whose last occurrence is the oldest (ties: first seen, then code).
	 *
	 * @param array $records Code => record (not empty).
	 * @return string
	 */
	private static function oldest_code( array $records ) {
		$oldest = '';
		$best   = null;
		foreach ( $records as $code => $record ) {
			$key = array( $record['last_seen'], $record['first_seen'], (string) $code );
			if ( null === $best || $key < $best ) {
				$best   = $key;
				$oldest = (string) $code;
			}
		}
		return $oldest;
	}

	/**
	 * Sort callback: most recent last_seen first, then code.
	 *
	 * @param array $a Record.
	 * @param array $b Record.
	 * @return int
	 */
	private static function compare_newest_first( $a, $b ) {
		if ( $a['last_seen'] !== $b['last_seen'] ) {
			return ( $a['last_seen'] > $b['last_seen'] ) ? -1 : 1;
		}
		return strcmp( $a['code'], $b['code'] );
	}

	/**
	 * Reduce a context to a few short, redacted, printable facts.
	 *
	 * @param array $context Raw context.
	 * @return array Key => string.
	 */
	private static function clean_context( array $context ) {
		$out = array();
		foreach ( $context as $key => $value ) {
			if ( count( $out ) >= self::MAX_CONTEXT_KEYS ) {
				break;
			}
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$key = substr( (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $key ) ), 0, 24 );
			if ( '' === $key || self::is_personal_key( $key ) ) {
				continue;
			}
			$text = is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value;
			if ( 1 === preg_match( '#^https?://#i', $text ) ) {
				$host = parse_url( $text, PHP_URL_HOST );
				$text = is_string( $host ) ? strtolower( $host ) : '[url]';
				$key  = 'host'; // Only the host is kept: a path or query can carry a token.
			}
			if ( class_exists( 'DoughBoss_Growth_Http', false ) ) {
				$text = DoughBoss_Growth_Http::redact_text( $text );
			} else {
				$text = (string) preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $text );
				$text = (string) preg_replace( '/\+?\d[\d ()-]{7,}\d/', '[number]', $text );
			}
			$text = (string) preg_replace( '/[^\x20-\x7E]/', '', $text );
			$text = trim( substr( $text, 0, self::MAX_VALUE_LENGTH ) );
			if ( '' === $text ) {
				continue;
			}
			$out[ $key ] = $text;
		}
		return $out;
	}

	/**
	 * Whether a context key looks like it could hold personal data or a secret.
	 *
	 * @param string $key Normalised key.
	 * @return bool
	 */
	private static function is_personal_key( $key ) {
		foreach ( explode( '_', $key ) as $word ) {
			if ( in_array( $word, self::PERSONAL_KEY_WORDS, true ) ) {
				return true;
			}
		}
		return false;
	}
}
