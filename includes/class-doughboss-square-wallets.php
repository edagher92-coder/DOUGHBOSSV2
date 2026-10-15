<?php
/**
 * Apple Pay domain verification for Square digital wallets.
 *
 * Serves the Square-issued domain association file (pasted in Settings) at
 * /.well-known/apple-developer-merchantid-domain-association, as plain bytes
 * with no extension, so the domain can be registered in the Square Developer
 * Console without uploading files to the host.
 *
 * @package DoughBoss
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DoughBoss_Square_Wallets {

	const PATH = '/.well-known/apple-developer-merchantid-domain-association';

	/** @return void */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_serve' ), 0 );
	}

	/**
	 * Pure: is this request path the Apple association file?
	 *
	 * @param string $uri REQUEST_URI.
	 * @return bool
	 */
	public static function is_association_request( $uri ) {
		$path = (string) wp_parse_url( (string) $uri, PHP_URL_PATH );
		return self::PATH === rtrim( $path, '/' ) || self::PATH === $path;
	}

	/** @return void */
	public static function maybe_serve() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! self::is_association_request( $uri ) ) {
			return;
		}
		$file = (string) DoughBoss_Settings::get( 'square_apple_pay_domain_file', '' );
		if ( '' === trim( $file ) ) {
			return; // Fall through to the normal 404.
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo $file; // phpcs:ignore WordPress.Security.EscapeOutput -- sanitised to base64/hex charset on save.
		exit;
	}
}
