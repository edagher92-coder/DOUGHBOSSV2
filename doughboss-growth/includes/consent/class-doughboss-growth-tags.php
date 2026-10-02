<?php
/**
 * DoughBoss Growth tags: the Consent Mode v2 default and the single Google Tag Manager loader.
 *
 * Everything here is inert unless BOTH the consent_banner and gtm flags are effectively on AND a valid
 * container id is saved. Nothing is printed otherwise (the page stays byte-identical to today).
 *
 * Load order (00 section 3.5):
 *  - wp_head priority 0: an inline ES5 snippet that sets every Consent Mode signal to "denied"
 *    (wait_for_update 500) and replays a stored choice from the dbgr_consent cookie;
 *  - wp_head priority 1: the Tag Manager container snippet. GA4, Google Ads and the Meta Pixel are
 *    configured INSIDE the container only: one loader per vendor.
 *
 * Deliberately NOT printed: the <noscript> Tag Manager iframe. It would load the container for visitors
 * without JavaScript, who can never be asked for consent, so it would break "nothing before consent".
 *
 * @package DoughBoss_Growth
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Consent Mode default and Tag Manager snippet.
 */
final class DoughBoss_Growth_Tags {

	/**
	 * Container id format. Matches the settings sanitiser.
	 */
	const GTM_ID_PATTERN = '/^GTM-[A-Z0-9]{4,10}$/D';

	/**
	 * Name of the consent cookie (written by public/js/dbgr-consent.js).
	 */
	const COOKIE_NAME = 'dbgr_consent';

	/**
	 * The two modes the consent_default setting can take. Anything else is treated as "deny".
	 */
	const MODE_DENY    = 'deny';
	const MODE_OPT_OUT = 'opt_out';

	/**
	 * Hook the head output. Idempotent: WordPress ignores a second identical add_action().
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'print_consent_default' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'print_gtm' ), 1 );
	}

	/**
	 * Whether a string is a well-formed Tag Manager container id.
	 *
	 * @param mixed $id Candidate.
	 * @return bool
	 */
	public static function valid_container_id( $id ) {
		return is_string( $id ) && 1 === preg_match( self::GTM_ID_PATTERN, $id );
	}

	/**
	 * The saved container id when it is valid, else an empty string.
	 *
	 * @return string
	 */
	public static function container_id() {
		$id = DoughBoss_Growth_Settings::get( 'gtm_container_id', '' );
		return self::valid_container_id( $id ) ? $id : '';
	}

	/**
	 * Whether the container may be loaded now: both flags effectively on and a valid id saved.
	 *
	 * @return bool
	 */
	public static function ready() {
		return DoughBoss_Growth_Settings::enabled( 'consent_banner' )
			&& DoughBoss_Growth_Settings::enabled( 'gtm' )
			&& '' !== self::container_id();
	}

	/**
	 * The effective consent mode: "opt_out" only when saved as exactly that, else "deny".
	 *
	 * @return string
	 */
	public static function mode() {
		return ( self::MODE_OPT_OUT === DoughBoss_Growth_Settings::get( 'consent_default', self::MODE_DENY ) ) ? self::MODE_OPT_OUT : self::MODE_DENY;
	}

	/**
	 * Whether this request is a public front-end page view where tags may print.
	 *
	 * @return bool
	 */
	private static function frontend_request() {
		if ( is_admin() ) {
			return false;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return false;
		}
		return true;
	}

	/**
	 * Print the Consent Mode default (wp_head, priority 0).
	 *
	 * @return void
	 */
	public static function print_consent_default() {
		if ( ! self::frontend_request() || ! self::ready() ) {
			return;
		}
		$version = DoughBoss_Growth_Settings::get( 'consent_text_version', '1' );
		echo '<script id="dbgr-consent-default">' . "\n" . self::consent_default_script( is_string( $version ) ? $version : '1', self::mode() ) . "\n" . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from json_encode() values with hex-escaped markup characters.
	}

	/**
	 * Print the Tag Manager snippet (wp_head, priority 1).
	 *
	 * @return void
	 */
	public static function print_gtm() {
		if ( ! self::frontend_request() || ! self::ready() ) {
			return;
		}
		echo '<script id="dbgr-gtm">' . "\n" . self::gtm_script( self::container_id() ) . "\n" . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the id is validated against GTM_ID_PATTERN before use.
	}

	/**
	 * The inline Consent Mode default and cookie replay, as ES5 source (no script tags).
	 *
	 * "deny" mode: all four signals denied until a choice is stored. "opt_out" mode (notice and opt-out, an
	 * owner decision): analytics_storage starts granted while the three advertising signals stay denied; a
	 * stored choice replaces the default in both modes. A stored choice is honoured only when it was made
	 * under the current consent wording version.
	 *
	 * The cookie rules here are the same as parse() in public/js/dbgr-consent.js; tests/consent.test.js runs
	 * both on the same vectors.
	 *
	 * @param string $version Consent wording version (consent_text_version).
	 * @param string $mode    "deny" or "opt_out".
	 * @return string
	 */
	public static function consent_default_script( $version, $mode ) {
		$flags     = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
		$version_j = json_encode( (string) $version, $flags );
		if ( ! is_string( $version_j ) ) {
			$version_j = '""';
		}
		$analytics = ( self::MODE_OPT_OUT === $mode ) ? 'granted' : 'denied';

		$js  = "(function (w, d) {\n";
		$js .= "\t'use strict';\n";
		$js .= "\tw.dataLayer = w.dataLayer || [];\n";
		$js .= "\tif (typeof w.gtag !== 'function') { w.gtag = function () { w.dataLayer.push(arguments); }; }\n";
		$js .= "\tvar V = " . $version_j . ";\n";
		$js .= "\tw.gtag('consent', 'default', { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: '" . $analytics . "', wait_for_update: 500 });\n";
		$js .= "\tvar o = null;\n";
		$js .= "\ttry {\n";
		$js .= "\t\tvar m = d.cookie.match(/(?:^|;\\s*)" . self::COOKIE_NAME . "=([^;]*)/);\n";
		$js .= "\t\tif (m && m[1].length <= 400) { o = JSON.parse(decodeURIComponent(m[1])); }\n";
		$js .= "\t} catch (e) { o = null; }\n";
		$js .= "\tif (o && typeof o === 'object' && o.v === V && (o.m === 0 || o.m === 1) && (o.a === 0 || o.a === 1) && typeof o.ts === 'number' && o.ts > 0) {\n";
		$js .= "\t\tw.gtag('consent', 'update', { ad_storage: o.a === 1 ? 'granted' : 'denied', ad_user_data: o.a === 1 ? 'granted' : 'denied', ad_personalization: o.a === 1 ? 'granted' : 'denied', analytics_storage: o.m === 1 ? 'granted' : 'denied' });\n";
		$js .= "\t}\n";
		$js .= '}(window, document));';
		return $js;
	}

	/**
	 * The standard Tag Manager loader for one container, as ES5 source (no script tags). Returns an empty
	 * string for an invalid id so a bad value can never reach the page.
	 *
	 * @param string $container_id Container id (GTM-XXXX).
	 * @return string
	 */
	public static function gtm_script( $container_id ) {
		if ( ! self::valid_container_id( $container_id ) ) {
			return '';
		}
		return "(function (w, d, s, l, i) {\n"
			. "\tw[l] = w[l] || [];\n"
			. "\tw[l].push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });\n"
			. "\tvar f = d.getElementsByTagName(s)[0], j = d.createElement(s);\n"
			. "\tj.async = true;\n"
			. "\tj.src = 'https://www.googletagmanager.com/gtm.js?id=' + i;\n"
			. "\tf.parentNode.insertBefore(j, f);\n"
			. "}(window, document, 'script', 'dataLayer', '" . $container_id . "'));";
	}
}
