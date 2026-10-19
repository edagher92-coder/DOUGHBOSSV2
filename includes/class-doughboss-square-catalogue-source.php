<?php
/**
 * Versioned, local-only menu normalization shared with the offline Square planner.
 * No provider calls, URL fetches, database writes, or approval are performed here.
 *
 * @package DoughBoss
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class DoughBoss_Square_Catalogue_Source {
	const ALGORITHM = 'doughboss-menu-utf8-v1';
	const MAX_SAFE_INTEGER = 9007199254740991;

	/** @param mixed $raw_menu Current trusted menu producer's raw data. @return array|WP_Error */
	public static function snapshot( $raw_menu ) {
		try {
			self::require_list( $raw_menu );
			if ( count( $raw_menu ) < 1 || count( $raw_menu ) > 5000 ) { self::invalid(); }
			$items = array();
			$item_ids = array();
			foreach ( $raw_menu as $raw ) {
				self::require_object( $raw );
				$id = self::positive_id( self::field( $raw, 'id' ) );
				if ( isset( $item_ids[ $id ] ) ) { self::invalid(); }
				$item_ids[ $id ] = true;
				$dietary = self::field( $raw, 'dietary' );
				self::require_list( $dietary );
				foreach ( $dietary as $flag ) { self::text( $flag, 200, true ); }
				self::require_unique( $dietary );
				usort( $dietary, 'strcmp' );
				$options = self::field( $raw, 'options' );
				self::require_list( $options );
				$groups = array();
				$group_ids = array();
				foreach ( $options as $option ) {
					self::require_object( $option );
					$source_id = self::text( self::field( $option, 'id' ), 200, true );
					if ( ! preg_match( '/^[a-z][a-z0-9_]*$/D', $source_id ) || isset( $group_ids[ $source_id ] ) ) { self::invalid(); }
					$group_ids[ $source_id ] = true;
					$group_id = 'item_' . $id . '_option_' . $source_id;
					$type = self::field( $option, 'type' );
					if ( ! in_array( $type, array( 'radio', 'check' ), true ) ) { self::invalid(); }
					$raw_choices = self::field( $option, 'choices' );
					self::require_list( $raw_choices );
					if ( ! count( $raw_choices ) ) { self::invalid(); }
					$choices = array();
					$slugs = array();
					$defaults = 0;
					foreach ( $raw_choices as $choice ) {
						self::require_object( $choice );
						$slug = self::text( self::field( $choice, 'slug' ), 200, true );
						if ( ! preg_match( '/^[a-z][a-z0-9_-]*$/D', $slug ) || isset( $slugs[ $slug ] ) ) { self::invalid(); }
						$slugs[ $slug ] = true;
						$default = self::boolean( self::field( $choice, 'default' ) );
						$defaults += $default ? 1 : 0;
						$choices[] = array( 'id' => $group_id . '_choice_' . $slug, 'slug' => $slug, 'label' => self::text( self::field( $choice, 'label' ), 200, true ), 'price_delta_minor' => self::cents( self::field( $choice, 'price' ) ), 'default' => $default );
					}
					if ( 'radio' === $type && 1 !== $defaults ) { self::invalid(); }
					usort( $choices, static function ( $a, $b ) { return strcmp( $a['id'], $b['id'] ); } );
					$groups[] = array( 'id' => $group_id, 'source_id' => $source_id, 'label' => self::text( self::field( $option, 'label' ), 200, true ), 'type' => $type, 'choices' => $choices );
				}
				usort( $groups, static function ( $a, $b ) { return strcmp( $a['id'], $b['id'] ); } );
				$items[] = array( 'website_item_id' => $id, 'name' => self::text( self::field( $raw, 'name' ), 200, true ), 'description' => self::text( self::field( $raw, 'description' ), 5000, false ), 'category' => self::text( self::field( $raw, 'category' ), 200, true ), 'type' => self::text( self::field( $raw, 'type' ), 200, true ), 'base_price_minor' => self::cents( self::field( $raw, 'price' ) ), 'image_url' => self::image_url( self::field( $raw, 'image' ) ), 'public_availability' => self::boolean( self::field( $raw, 'available' ) ), 'global_availability_only' => true, 'dietary' => $dietary, 'modifier_groups' => $groups );
			}
			usort( $items, static function ( $a, $b ) { return $a['website_item_id'] <=> $b['website_item_id']; } );
			$json = json_encode( self::canonical( $items ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS );
			if ( ! is_string( $json ) ) { self::invalid(); }
			return array( 'algorithm' => self::ALGORITHM, 'canonical_json' => $json, 'source_fingerprint' => hash( 'sha256', $json ), 'items' => $items );
		} catch ( UnexpectedValueException $error ) {
			return new WP_Error( 'doughboss_square_source_invalid', 'The current menu cannot be normalized safely. No catalogue transfer was authorized.', array( 'status' => 400 ) );
		}
	}

	private static function invalid() { throw new UnexpectedValueException( 'Invalid menu source.' ); }
	private static function require_list( $value ) { if ( ! is_array( $value ) || array_values( $value ) !== $value ) { self::invalid(); } }
	private static function require_object( $value ) { if ( ! is_array( $value ) || array() === $value || array_values( $value ) === $value ) { self::invalid(); } }
	private static function field( array $value, $field ) { if ( ! array_key_exists( $field, $value ) ) { self::invalid(); } return $value[ $field ]; }
	private static function boolean( $value ) { if ( ! is_bool( $value ) ) { self::invalid(); } return $value; }
	private static function require_unique( array $values ) { if ( count( array_unique( $values, SORT_STRING ) ) !== count( $values ) ) { self::invalid(); } }

	/** Node integer numbers include a JSON 1.0; numeric strings and booleans never qualify. */
	private static function positive_id( $value ) {
		if ( ( ! is_int( $value ) && ! is_float( $value ) ) || ! is_finite( (float) $value ) || $value <= 0 || $value > self::MAX_SAFE_INTEGER || floor( $value ) !== (float) $value ) { self::invalid(); }
		return (int) $value;
	}

	/** UTF-16 units without mbstring: each valid four-byte scalar consumes two units. */
	private static function text( $value, $max, $nonempty ) {
		if ( ! is_string( $value ) || ( $nonempty && '' === $value ) || 1 !== preg_match( '//u', $value ) ) { self::invalid(); }
		$units = preg_match_all( '/./us', $value, $characters );
		if ( false === $units ) { self::invalid(); }
		foreach ( $characters[0] as $character ) { if ( 4 === strlen( $character ) ) { ++$units; } }
		if ( $units > $max ) { self::invalid(); }
		return $value;
	}

	/** Lossless numeric serialization, followed by plain-decimal parsing; never round money. */
	private static function cents( $value ) {
		if ( is_int( $value ) ) { $text = (string) $value;
		} elseif ( is_float( $value ) ) {
			if ( ! is_finite( $value ) || $value < 0 ) { self::invalid(); }
			$precision = ini_get( 'serialize_precision' );
			if ( '-1' !== $precision && false === ini_set( 'serialize_precision', '-1' ) ) { self::invalid(); }
			try { $text = json_encode( $value, JSON_PRESERVE_ZERO_FRACTION );
			} finally { if ( '-1' !== $precision ) { ini_set( 'serialize_precision', $precision ); } }
		} elseif ( is_string( $value ) ) { $text = $value;
		} else { self::invalid(); }
		if ( ! is_string( $text ) || ! preg_match( '/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $text ) ) { self::invalid(); }
		$parts = explode( '.', $text );
		$digits = ltrim( $parts[0] . str_pad( isset( $parts[1] ) ? $parts[1] : '', 2, '0' ), '0' );
		$max = (string) self::MAX_SAFE_INTEGER;
		if ( strlen( $digits ) > strlen( $max ) || ( strlen( $digits ) === strlen( $max ) && strcmp( $digits, $max ) > 0 ) ) { self::invalid(); }
		return '' === $digits ? 0 : (int) $digits;
	}

	/** Shared lexical profile only: no URL normalization, DNS checks, or fetches. */
	private static function image_url( $value ) {
		self::text( $value, 2048, false );
		if ( '' === $value ) { return ''; }
		if ( preg_match( '/[^\x21-\x7e]/', $value ) || false !== strpos( $value, '\\' ) || ! preg_match( '~^https://([A-Za-z0-9.-]+)(?::443)?(/[^?#]*)?$~D', $value, $parts ) ) { self::invalid(); }
		$host = strtolower( $parts[1] );
		$labels = explode( '.', $host );
		if ( strlen( $host ) > 253 || count( $labels ) < 2 || ! preg_match( '/^[a-z]{2,63}$/D', end( $labels ) ) || 'localhost' === $host || substr( $host, -10 ) === '.localhost' || substr( $host, -6 ) === '.local' ) { self::invalid(); }
		foreach ( $labels as $label ) { if ( strlen( $label ) > 63 || ! preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label ) ) { self::invalid(); } }
		$path = isset( $parts[2] ) ? $parts[2] : '';
		if ( preg_match( '/%(?![0-9A-Fa-f]{2})/', $path ) || preg_match( '/%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i', $path ) ) { self::invalid(); }
		return $value;
	}

	/** Lists keep their explicitly normalized order; generated object keys are ASCII. */
	private static function canonical( array $value ) {
		$is_list = array_values( $value ) === $value;
		if ( ! $is_list ) { ksort( $value, SORT_STRING ); }
		foreach ( $value as $key => $entry ) { if ( is_array( $entry ) ) { $value[ $key ] = self::canonical( $entry ); } }
		return $value;
	}
}
