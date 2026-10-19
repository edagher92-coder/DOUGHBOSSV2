<?php
/**
 * Pure, complete mapped catalogue graph comparison.
 *
 * The caller supplies independently fetched GET root payloads, not HTTP
 * envelopes. This class cannot prove their provenance, actor approval,
 * freshness or merchant identity, and grants no dispatch/verification authority.
 * No transport, storage, credentials, hooks or state transitions occur here.
 *
 * @package DoughBoss
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class DoughBoss_Square_Catalogue_Readback {
	const MAX_OBJECTS = 1000;
	const MAX_DEPTH = 32;
	const MAX_NODES = 100000;

	/** Complete deterministic minimized evidence, or one generic error. */
	public static function validate( array $target, array $request, $provider_key, array $receipt, array $readback ) {
		try {
			self::bounded( $target, DoughBoss_Square_Catalogue_Operations::MAX_TARGET_JSON );
			foreach ( array( $request, $receipt, $readback ) as $input ) { self::bounded( $input, DoughBoss_Square_Catalogue_Operations::MAX_JSON ); }
			// PHP arrays can contain references supplied by an internal caller.
			// Detach them before transforming even a local copy of the graph.
			$target = self::copy( $target ); $request = self::copy( $request ); $receipt = self::copy( $receipt ); $readback = self::copy( $readback );
			$graph = DoughBoss_Square_Catalogue_Operations::validate_packet( $target, $request, $provider_key );
			self::need( ! is_wp_error( $graph ) );
			list( $mapping, $versions ) = self::receipt( $receipt, $graph, $target );
			$expected = array();
			foreach ( $request['batches'][0]['objects'] as $root ) { $expected[] = self::mapped( $root, $mapping, $versions ); }
			self::list_value( $readback );
			self::need( count( $readback ) === count( $expected ) );
			$seen = array(); $actual = array();
			foreach ( $readback as $root ) { $actual[] = self::observed( $root, $versions, $seen ); }
			self::need( count( $seen ) === count( $graph ) );
			$actual = self::canonical( $actual );
			self::need( $actual === self::canonical( $expected ) );
			ksort( $versions, SORT_STRING );
			$objects = array();
			foreach ( $versions as $id => $entry ) { $objects[] = array( 'id' => (string) $id, 'type' => $entry['type'], 'version' => $entry['version'] ); }
			return array( 'schema_version' => 1, 'data_state' => 'graph_matches', 'graph_hash' => hash( 'sha256', self::json( $actual ) ), 'objects' => $objects );
		} catch ( Throwable $error ) {
			return new WP_Error( 'doughboss_square_catalogue_readback', 'The complete mapped catalogue graph could not be validated safely.', array( 'status' => 409 ) );
		}
	}

	/** Same exact closure as the existing durable receipt, without production reflection. */
	private static function receipt( array $receipt, array $graph, array $target ) {
		self::keys( $receipt, array( 'id_mappings', 'objects' ) );
		self::list_value( $receipt['id_mappings'] ); self::list_value( $receipt['objects'] );
		self::need( count( $receipt['id_mappings'] ) === count( $graph ) && count( $receipt['objects'] ) === count( $graph ) );
		$mapping = array(); $ids = array(); $versions = array();
		$existing = array_column( $target['existing_references'], 'type', 'id' );
		foreach ( $receipt['id_mappings'] as $entry ) {
			self::keys( $entry, array( 'client_object_id', 'object_id' ) );
			$client = $entry['client_object_id']; $id = $entry['object_id'];
			self::need( is_string( $client ) && isset( $graph[ $client ] ) && self::id( $id ) && ! isset( $mapping[ $client ] ) && ! isset( $ids[ $id ] ) && ! isset( $existing[ $id ] ) );
			$mapping[ $client ] = $id; $ids[ $id ] = $graph[ $client ]['type'];
		}
		foreach ( $receipt['objects'] as $entry ) {
			self::keys( $entry, array( 'id', 'type', 'version' ) );
			self::need( self::id( $entry['id'] ) && isset( $ids[ $entry['id'] ] ) && ! isset( $versions[ $entry['id'] ] ) && $ids[ $entry['id'] ] === $entry['type'] && self::version( $entry['version'] ) );
			$versions[ $entry['id'] ] = array( 'type' => $entry['type'], 'version' => $entry['version'] );
		}
		return array( $mapping, $versions );
	}

	/** Substitute only identity/reference fields, never ID-shaped semantic text. */
	private static function mapped( array $object, array $mapping, array $versions ) {
		$object['id'] = $mapping[ $object['id'] ];
		$object['version'] = $versions[ $object['id'] ]['version'];
		$object['is_deleted'] = false;
		$field = strtolower( $object['type'] ) . '_data'; $data = $object[ $field ];
		foreach ( array( 'item_id', 'modifier_list_id' ) as $reference ) {
			if ( isset( $data[ $reference ] ) ) { $data[ $reference ] = self::reference( $data[ $reference ], $mapping ); }
		}
		if ( isset( $data['categories'] ) ) {
			foreach ( $data['categories'] as &$category ) { $category['id'] = self::reference( $category['id'], $mapping ); } unset( $category );
		}
		if ( isset( $data['modifier_list_info'] ) ) {
			foreach ( $data['modifier_list_info'] as &$info ) {
				$info['modifier_list_id'] = self::reference( $info['modifier_list_id'], $mapping );
				foreach ( $info['modifier_overrides'] as &$override ) { $override['modifier_id'] = self::reference( $override['modifier_id'], $mapping ); } unset( $override );
			} unset( $info );
		}
		foreach ( array( 'variations', 'modifiers' ) as $children ) {
			if ( isset( $data[ $children ] ) ) { foreach ( $data[ $children ] as &$child ) { $child = self::mapped( $child, $mapping, $versions ); } unset( $child ); }
		}
		$object[ $field ] = $data;
		return $object;
	}

	private static function reference( $id, array $mapping ) {
		return isset( $mapping[ $id ] ) ? $mapping[ $id ] : $id;
	}

	/** Only explicitly understood nonsemantic provider metadata is removed. */
	private static function observed( $object, array $versions, array &$seen, $nested = false ) {
		self::need( is_array( $object ) && isset( $object['id'], $object['type'], $object['version'], $object['is_deleted'] ) && self::id( $object['id'] ) );
		$id = $object['id'];
		self::need( isset( $versions[ $id ] ) && ! isset( $seen[ $id ] ) && $object['type'] === $versions[ $id ]['type'] && $object['version'] === $versions[ $id ]['version'] && false === $object['is_deleted'] );
		self::need( ! $nested || in_array( $object['type'], array( 'ITEM_VARIATION', 'MODIFIER' ), true ) );
		$seen[ $id ] = true;
		if ( array_key_exists( 'updated_at', $object ) ) { self::need( self::timestamp( $object['updated_at'] ) ); unset( $object['updated_at'] ); }
		$field = strtolower( $object['type'] ) . '_data';
		self::need( isset( $object[ $field ] ) && is_array( $object[ $field ] ) );
		$data = $object[ $field ];
		// Square's pinned 2025-01-23 spec: description_plaintext is readOnly;
		// legacy description is synchronized with description_html. Only the
		// escaped-plaintext request subset is admitted here, not arbitrary HTML.
		if ( 'ITEM' === $object['type'] ) {
			foreach ( array( 'description_plaintext', 'description' ) as $generated ) {
				if ( array_key_exists( $generated, $data ) ) {
					self::need( isset( $data['description_html'] ) && is_string( $data['description_html'] ) && false === strpos( $data['description_html'], '<' ) && false === strpos( $data['description_html'], '>' ) );
					self::need( $data[ $generated ] === html_entity_decode( $data['description_html'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
					unset( $data[ $generated ] );
				}
			}
		}
		// The same pinned spec marks variation ordinal readOnly and says read
		// ordinals need not be sequential or unique. Writable modifier ordinals
		// and every other unsubmitted behavior-changing field remain forbidden.
		if ( 'ITEM_VARIATION' === $object['type'] && array_key_exists( 'ordinal', $data ) ) {
			self::need( is_int( $data['ordinal'] ) && $data['ordinal'] >= -2147483648 && $data['ordinal'] <= 2147483647 );
			unset( $data['ordinal'] );
		}
		foreach ( array( 'variations', 'modifiers' ) as $children ) {
			if ( array_key_exists( $children, $data ) ) {
				self::need( ! $nested ); self::list_value( $data[ $children ] );
				foreach ( $data[ $children ] as &$child ) { $child = self::observed( $child, $versions, $seen, true ); } unset( $child );
			}
		}
		$object[ $field ] = $data;
		return $object;
	}

	/** All lists in this approved subset are set-like; duplicates never normalize away. */
	private static function canonical( array $value ) {
		foreach ( $value as $key => $entry ) { if ( is_array( $entry ) ) { $value[ $key ] = self::canonical( $entry ); } }
		if ( array_values( $value ) === $value ) {
			usort( $value, static function ( $left, $right ) { return strcmp( self::json( $left ), self::json( $right ) ); } );
		} else { ksort( $value, SORT_STRING ); }
		return $value;
	}

	private static function bounded( array $value, $bytes ) {
		$nodes = 0; self::walk( $value, 0, $nodes );
		self::need( strlen( self::json( $value ) ) <= $bytes );
	}
	private static function copy( array $value ) {
		$out = array();
		foreach ( $value as $key => $entry ) { $out[ $key ] = is_array( $entry ) ? self::copy( $entry ) : $entry; }
		return $out;
	}
	private static function walk( $value, $depth, &$nodes ) {
		self::need( ++$nodes <= self::MAX_NODES && $depth <= self::MAX_DEPTH );
		if ( is_array( $value ) ) {
			self::need( count( $value ) <= self::MAX_OBJECTS );
			foreach ( $value as $key => $entry ) { if ( is_string( $key ) ) { self::need( strlen( $key ) <= 65536 && 1 === preg_match( '//u', $key ) ); } self::walk( $entry, $depth + 1, $nodes ); }
		} elseif ( is_string( $value ) ) { self::need( strlen( $value ) <= DoughBoss_Square_Catalogue_Operations::MAX_JSON && 1 === preg_match( '//u', $value ) );
		} elseif ( is_int( $value ) ) { self::need( $value >= -DoughBoss_Square_Catalogue_Operations::MAX_INTEGER && $value <= DoughBoss_Square_Catalogue_Operations::MAX_INTEGER );
		} else { self::need( null === $value || is_bool( $value ) ); }
	}
	private static function timestamp( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.[0-9]{1,9})?(Z|[+-]([0-9]{2}):([0-9]{2}))$/D', $value, $parts ) ) { return false; }
		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) && (int) $parts[4] <= 23 && (int) $parts[5] <= 59 && (int) $parts[6] <= 59 && ( 'Z' === $parts[7] || ( (int) $parts[8] <= 23 && (int) $parts[9] <= 59 ) );
	}
	private static function id( $value ) { return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,191}$/D', $value ); }
	private static function version( $value ) { return is_int( $value ) && $value > 0 && $value <= DoughBoss_Square_Catalogue_Operations::MAX_INTEGER; }
	private static function list_value( $value ) { self::need( is_array( $value ) && array_values( $value ) === $value && count( $value ) <= self::MAX_OBJECTS ); }
	private static function keys( $value, array $keys ) { self::need( is_array( $value ) ); $actual = array_keys( $value ); sort( $actual, SORT_STRING ); sort( $keys, SORT_STRING ); self::need( $actual === $keys ); }
	private static function json( $value ) { $json = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS ); self::need( is_string( $json ) ); return $json; }
	private static function need( $condition ) { if ( ! $condition ) { throw new UnexpectedValueException( 'Invalid mapped catalogue readback.' ); } }
}
