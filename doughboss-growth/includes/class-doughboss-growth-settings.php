<?php
/**
 * DoughBoss Growth settings: option storage, feature flags, env-first secrets.
 *
 * Every feature is OFF by default and fails closed: a missing, malformed or
 * dependency-blocked setting means the feature is inert. The only writer of the
 * option "doughboss_growth_settings" is this class (via the admin save handler).
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings and feature-flag access.
 */
final class DoughBoss_Growth_Settings {

	/**
	 * Option name (autoloaded: read on every request once the core gate passes).
	 */
	const OPTION = 'doughboss_growth_settings';

	/**
	 * The eleven feature flags. Names are frozen by the architecture (00 section 4.2).
	 */
	const FEATURES = array(
		'consent_banner',
		'gtm',
		'attribution',
		'server_conversions',
		'landing_pages',
		'seo_head',
		'lead_form',
		'party_sizer',
		'coming_soon',
		'waitlist',
		'timesheet_recon',
	);

	/**
	 * Names that may be supplied by environment variable or wp-config constant. Env first.
	 */
	const SECRET_NAMES = array(
		'DOUGHBOSS_GROWTH_GA4_API_SECRET',
		'DOUGHBOSS_GROWTH_META_CAPI_TOKEN',
		'DOUGHBOSS_GROWTH_WEBHOOK_SECRET',
		'DOUGHBOSS_GROWTH_SQUARE_LABOUR_TOKEN',
		'DOUGHBOSS_GROWTH_SQUARE_ENV',
	);

	/**
	 * Allowed values of the consent_default setting. "deny" is the shipped value; the
	 * consent module (WP-03) defines what any other value means. Unknown value = deny.
	 */
	const CONSENT_DEFAULTS = array( 'deny', 'opt_out' );

	/**
	 * Neutral teaser defaults (see web/docs/site/teaser-direction.md). No product, price,
	 * size, dietary, date or location claim may ever appear here.
	 */
	const DEFAULT_COMING_SOON_HEADLINE = 'Something exciting is coming';
	const DEFAULT_COMING_SOON_BODY     = 'Be first to know';

	/**
	 * Default settings. Every feature false.
	 *
	 * @return array
	 */
	public static function defaults() {
		$features = array();
		foreach ( self::FEATURES as $feature ) {
			$features[ $feature ] = false;
		}
		return array(
			'features'                   => $features,
			'gtm_container_id'           => '',
			'ga4_measurement_id'         => '',
			'meta_pixel_id'              => '',
			'consent_text_version'       => '1',
			'consent_default'            => 'deny',
			'sender_legal_name'          => '',
			'privacy_policy_url'         => '',
			'notify_webhook_url'         => '',
			'send_hashed_identifiers'    => 0,
			'seo_jsonld_with_seo_plugin' => 0,
			'retention_pending_days'     => 30,
			'retention_confirmed_months' => null,
			'coming_soon_headline'       => self::DEFAULT_COMING_SOON_HEADLINE,
			'coming_soon_body'           => self::DEFAULT_COMING_SOON_BODY,
			'coming_soon_page_slug'      => 'coming-soon',
		);
	}

	/**
	 * Whether the wp-config kill switch is set. Any truthy value stops everything.
	 *
	 * @return bool
	 */
	public static function kill_switch() {
		return defined( 'DOUGHBOSS_GROWTH_DISABLE' ) && (bool) constant( 'DOUGHBOSS_GROWTH_DISABLE' );
	}

	/**
	 * All settings, sanitised on read so a corrupted option can never produce an open state.
	 *
	 * @return array
	 */
	public static function get_all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return self::sanitize( $stored );
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::get_all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Configured (saved) flag values, ignoring the kill switch, filter and dependencies.
	 *
	 * @return array Feature => bool.
	 */
	public static function configured_features() {
		$all = self::get_all();
		return $all['features'];
	}

	/**
	 * Whether a feature is effectively enabled.
	 *
	 * True only when ALL hold: the feature is a known flag, the kill switch is not set,
	 * the saved flag is on, its prerequisites are met, and the
	 * doughboss_growth_feature_enabled filter (which can only DISABLE) did not return false.
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	public static function enabled( $feature ) {
		if ( ! is_string( $feature ) || ! in_array( $feature, self::FEATURES, true ) ) {
			return false;
		}
		if ( self::kill_switch() ) {
			return false;
		}
		$settings = self::get_all();
		if ( true !== $settings['features'][ $feature ] ) {
			return false;
		}
		if ( array() !== self::unmet_requirements( $feature, $settings, array( __CLASS__, 'enabled' ) ) ) {
			return false;
		}
		// The filter starts from true and may only turn a feature off. Anything other than a
		// strict true (including null from a careless callback) disables, so it fails closed.
		return true === apply_filters( 'doughboss_growth_feature_enabled', true, $feature );
	}

	/**
	 * Prerequisites that are not met for a feature.
	 *
	 * @param string   $feature   Feature key.
	 * @param array    $settings  Sanitised settings.
	 * @param callable $is_on     Callable( $feature ) returning whether a prerequisite feature is on.
	 * @return array List of error codes (empty when everything is satisfied).
	 */
	public static function unmet_requirements( $feature, array $settings, $is_on ) {
		$errors = array();
		switch ( $feature ) {
			case 'gtm':
				if ( ! call_user_func( $is_on, 'consent_banner' ) ) {
					$errors[] = 'gtm_requires_consent_banner';
				}
				break;
			case 'server_conversions':
				if ( ! call_user_func( $is_on, 'attribution' ) ) {
					$errors[] = 'server_conversions_requires_attribution';
				}
				if ( ! self::destination_configured( $settings ) ) {
					$errors[] = 'server_conversions_requires_destination';
				}
				break;
			case 'waitlist':
				if ( '' === $settings['sender_legal_name'] ) {
					$errors[] = 'waitlist_requires_sender_legal_name';
				}
				if ( '' === $settings['privacy_policy_url'] ) {
					$errors[] = 'waitlist_requires_privacy_policy_url';
				}
				break;
		}
		return $errors;
	}

	/**
	 * Whether at least one conversion destination is fully configured: an id plus its
	 * secret (GA4, Meta) or a webhook URL. Secrets are checked for presence only.
	 *
	 * @param array $settings Sanitised settings.
	 * @return bool
	 */
	public static function destination_configured( array $settings ) {
		if ( '' !== $settings['ga4_measurement_id'] && self::has_secret( 'DOUGHBOSS_GROWTH_GA4_API_SECRET' ) ) {
			return true;
		}
		if ( '' !== $settings['meta_pixel_id'] && self::has_secret( 'DOUGHBOSS_GROWTH_META_CAPI_TOKEN' ) ) {
			return true;
		}
		return '' !== $settings['notify_webhook_url'];
	}

	/**
	 * Sanitise and persist settings from a raw (already unslashed) array, enforcing the
	 * dependency rules: a feature whose prerequisites are unmet is forced OFF and reported.
	 *
	 * @param array $raw Raw input.
	 * @return array { settings: array, errors: string[], saved: bool }
	 */
	public static function apply_save( array $raw ) {
		$clean  = self::sanitize( $raw );
		$errors = array();

		$candidate = $clean['features'];
		$resolver  = function ( $name ) use ( &$candidate ) {
			return isset( $candidate[ $name ] ) && true === $candidate[ $name ];
		};
		// Repeat until stable so a forced-off prerequisite also switches off its dependants.
		for ( $pass = 0; $pass < count( self::FEATURES ); $pass++ ) {
			$changed = false;
			foreach ( self::FEATURES as $feature ) {
				if ( true !== $candidate[ $feature ] ) {
					continue;
				}
				$unmet = self::unmet_requirements( $feature, $clean, $resolver );
				if ( array() !== $unmet ) {
					$candidate[ $feature ] = false;
					$errors                = array_merge( $errors, $unmet );
					$changed               = true;
				}
			}
			if ( ! $changed ) {
				break;
			}
		}
		$clean['features'] = $candidate;

		update_option( self::OPTION, $clean, true );
		// update_option() returns false when the value is unchanged, so confirm by reading back.
		$saved = ( $clean === self::get_all() );

		return array(
			'settings' => $clean,
			'errors'   => array_values( array_unique( $errors ) ),
			'saved'    => $saved,
		);
	}

	/**
	 * Sanitise a settings array. Unknown keys are dropped; invalid values fall back to the default.
	 *
	 * @param array $raw Raw settings.
	 * @return array
	 */
	public static function sanitize( array $raw ) {
		$out = self::defaults();

		$features_in = ( isset( $raw['features'] ) && is_array( $raw['features'] ) ) ? $raw['features'] : array();
		foreach ( self::FEATURES as $feature ) {
			$out['features'][ $feature ] = isset( $features_in[ $feature ] ) && self::truthy( $features_in[ $feature ] );
		}

		$out['gtm_container_id'] = self::match_id( $raw, 'gtm_container_id', '/^GTM-[A-Z0-9]{4,10}$/D', true );
		$out['ga4_measurement_id'] = self::match_id( $raw, 'ga4_measurement_id', '/^G-[A-Z0-9]{4,20}$/D', true );
		$out['meta_pixel_id']      = self::match_id( $raw, 'meta_pixel_id', '/^[0-9]{8,20}$/D', false );

		$version = isset( $raw['consent_text_version'] ) ? self::text( $raw['consent_text_version'], 20 ) : '';
		if ( 1 === preg_match( '/^[A-Za-z0-9._-]{1,20}$/D', $version ) ) {
			$out['consent_text_version'] = $version;
		}

		if ( isset( $raw['consent_default'] ) && is_string( $raw['consent_default'] ) && in_array( $raw['consent_default'], self::CONSENT_DEFAULTS, true ) ) {
			$out['consent_default'] = $raw['consent_default'];
		}

		$out['sender_legal_name']  = isset( $raw['sender_legal_name'] ) ? self::text( $raw['sender_legal_name'], 120 ) : '';
		$out['privacy_policy_url'] = isset( $raw['privacy_policy_url'] ) ? self::page_url( $raw['privacy_policy_url'] ) : '';
		$out['notify_webhook_url'] = isset( $raw['notify_webhook_url'] ) ? self::https_url( $raw['notify_webhook_url'] ) : '';

		$out['send_hashed_identifiers']    = ( isset( $raw['send_hashed_identifiers'] ) && self::truthy( $raw['send_hashed_identifiers'] ) ) ? 1 : 0;
		$out['seo_jsonld_with_seo_plugin'] = ( isset( $raw['seo_jsonld_with_seo_plugin'] ) && self::truthy( $raw['seo_jsonld_with_seo_plugin'] ) ) ? 1 : 0;

		$pending = isset( $raw['retention_pending_days'] ) ? self::positive_int( $raw['retention_pending_days'] ) : 0;
		if ( $pending >= 1 ) {
			$out['retention_pending_days'] = min( 365, $pending );
		}

		// Unset until Elie decides: no automatic deletion of confirmed rows while null. A negative, zero or
		// non-numeric value leaves it unset (absint() would turn -4 into 4, which is not what was typed).
		$months = isset( $raw['retention_confirmed_months'] ) ? self::positive_int( $raw['retention_confirmed_months'] ) : 0;
		if ( $months >= 1 ) {
			$out['retention_confirmed_months'] = min( 120, $months );
		}

		$headline = isset( $raw['coming_soon_headline'] ) ? self::text( $raw['coming_soon_headline'], 80 ) : '';
		if ( '' !== $headline && ! self::contains_banned_teaser_word( $headline ) ) {
			$out['coming_soon_headline'] = $headline;
		}
		$body = isset( $raw['coming_soon_body'] ) ? self::text( $raw['coming_soon_body'], 240 ) : '';
		if ( '' !== $body && ! self::contains_banned_teaser_word( $body ) ) {
			$out['coming_soon_body'] = $body;
		}

		$slug = isset( $raw['coming_soon_page_slug'] ) && is_string( $raw['coming_soon_page_slug'] ) ? sanitize_title( $raw['coming_soon_page_slug'] ) : '';
		if ( '' !== $slug ) {
			$out['coming_soon_page_slug'] = $slug;
		}

		return $out;
	}

	/**
	 * A secret from the environment first, then a wp-config constant. Never printed or logged.
	 *
	 * @param string $name One of SECRET_NAMES.
	 * @return string Empty string when absent or the name is not allowed.
	 */
	public static function secret( $name ) {
		if ( ! is_string( $name ) || ! in_array( $name, self::SECRET_NAMES, true ) ) {
			return '';
		}
		$env = getenv( $name );
		if ( is_string( $env ) && '' !== trim( $env ) ) {
			return trim( $env );
		}
		if ( defined( $name ) ) {
			$value = constant( $name );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}
		return '';
	}

	/**
	 * Whether a secret is configured (presence only).
	 *
	 * @param string $name One of SECRET_NAMES.
	 * @return bool
	 */
	public static function has_secret( $name ) {
		return '' !== self::secret( $name );
	}

	/**
	 * Owner decisions still outstanding. They block ENABLING, never building.
	 *
	 * @return array Code => text beginning "[CONFIRM: ...".
	 */
	public static function confirm_gaps() {
		$settings = self::get_all();
		$gaps     = array();
		if ( '' === $settings['sender_legal_name'] ) {
			$gaps['sender_legal_name'] = '[CONFIRM: sender legal name, and ABN if shown. Required before the waitlist can be enabled.]';
		}
		if ( '' === $settings['privacy_policy_url'] ) {
			$gaps['privacy_policy_url'] = '[CONFIRM: privacy-policy URL. Required before the waitlist can be enabled.]';
		}
		if ( null === $settings['retention_confirmed_months'] ) {
			$gaps['retention_confirmed_months'] = '[CONFIRM: retention period for confirmed waitlist rows. Until set, confirmed rows are never deleted automatically.]';
		}
		if ( '' === $settings['gtm_container_id'] ) {
			$gaps['gtm_container_id'] = '[CONFIRM: Google Tag Manager container id from the account owned by the right entity.]';
		}
		if ( '' === $settings['ga4_measurement_id'] ) {
			$gaps['ga4_measurement_id'] = '[CONFIRM: GA4 measurement id from the property owned by the right entity.]';
		}
		if ( '' === $settings['meta_pixel_id'] ) {
			$gaps['meta_pixel_id'] = '[CONFIRM: Meta pixel id from the account owned by the right entity.]';
		}
		return $gaps;
	}

	/**
	 * A positive whole number from an int or a plain digit string; 0 for anything else.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private static function positive_int( $value ) {
		if ( is_int( $value ) ) {
			return max( 0, $value );
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\s*[0-9]{1,9}\s*$/D', $value ) ) {
			return (int) trim( $value );
		}
		return 0;
	}

	/**
	 * Whether a stored value counts as "on".
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function truthy( $value ) {
		return true === $value || 1 === $value || '1' === $value || 'on' === $value;
	}

	/**
	 * Sanitised, length-capped single-line text.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Maximum characters.
	 * @return string
	 */
	private static function text( $value, $max ) {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return '';
		}
		$value = sanitize_text_field( (string) $value );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max, 'UTF-8' );
		}
		return substr( $value, 0, $max );
	}

	/**
	 * An identifier that must match a pattern, else empty.
	 *
	 * @param array  $raw       Raw settings.
	 * @param string $key       Key.
	 * @param string $pattern   Regex.
	 * @param bool   $uppercase Uppercase before matching.
	 * @return string
	 */
	private static function match_id( array $raw, $key, $pattern, $uppercase ) {
		if ( ! isset( $raw[ $key ] ) || ! is_string( $raw[ $key ] ) ) {
			return '';
		}
		$value = trim( $raw[ $key ] );
		if ( $uppercase ) {
			$value = strtoupper( $value );
		}
		return 1 === preg_match( $pattern, $value ) ? $value : '';
	}

	/**
	 * A privacy-policy style URL: absolute http(s) with a host, or a site-relative path.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function page_url( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 300 || preg_match( '/[\x00-\x20"<>\\\\]/', $value ) ) {
			return '';
		}
		if ( 1 === preg_match( '#^/(?!/)#', $value ) ) {
			return esc_url_raw( $value );
		}
		$parts = parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		return esc_url_raw( $value );
	}

	/**
	 * An https URL that passes the outbound-URL policy, else empty.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function https_url( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 300 ) {
			return '';
		}
		$value = esc_url_raw( $value );
		return DoughBoss_Growth_Http::url_allowed( $value ) ? $value : '';
	}

	/**
	 * Teaser-direction guard: admin-editable teaser text may never contain the working name.
	 * The fuller public-copy lint (digits, dietary words, and so on) belongs to the ledger module.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	private static function contains_banned_teaser_word( $text ) {
		return 1 === preg_match( '/minis/i', $text );
	}
}
