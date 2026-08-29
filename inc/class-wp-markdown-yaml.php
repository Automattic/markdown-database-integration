<?php
/** YAML encoding and decoding for canonical Markdown front matter. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A bounded YAML codec for the front matter canonical files carry.
 *
 * The subset is deliberate: scalars, lists and one level of nesting are
 * what front matter uses, so anything broader would be untested surface.
 */
final class WP_Markdown_Yaml {

	/**
	 * Encode an array as simple YAML.
	 *
	 * Minimal YAML encoder — handles the subset we need for frontmatter.
	 * No dependency on symfony/yaml or any external library.
	 *
	 * @param array $data Key-value pairs.
	 * @return string YAML string.
	 */
	public static function encode_yaml( array $data ): string {
		$lines = array();

		foreach ( $data as $key => $value ) {
			self::encode_yaml_entry( (string) $key, $value, 0, $lines );
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Append one YAML key/value pair to a line buffer.
	 *
	 * @param string $key    YAML key.
	 * @param mixed  $value  YAML value.
	 * @param int    $indent Current indentation depth.
	 * @param array  $lines  Encoded line buffer.
	 */

	/**
	 * Append one YAML key/value pair to a line buffer.
	 *
	 * @param string $key    YAML key.
	 * @param mixed  $value  YAML value.
	 * @param int    $indent Current indentation depth.
	 * @param array  $lines  Encoded line buffer.
	 */
	public static function encode_yaml_entry( string $key, $value, int $indent, array &$lines ): void {
		$prefix = str_repeat( ' ', $indent );
		if ( ! is_array( $value ) ) {
			$lines[] = $prefix . $key . ': ' . self::yaml_scalar( $value );
			return;
		}

		$lines[] = $prefix . $key . ':';
		if ( self::is_list_array( $value ) ) {
			foreach ( $value as $item ) {
				$lines[] = str_repeat( ' ', $indent + 2 ) . '- ' . self::yaml_scalar( $item );
			}
			return;
		}

		foreach ( $value as $sub_key => $sub_value ) {
			self::encode_yaml_entry( (string) $sub_key, $sub_value, $indent + 2, $lines );
		}
	}

	/**
	 * Determine whether an array should be encoded as a YAML list.
	 *
	 * @param array $value Array value.
	 * @return bool Whether the array is list-shaped.
	 */

	/**
	 * Determine whether an array should be encoded as a YAML list.
	 *
	 * @param array $value Array value.
	 * @return bool Whether the array is list-shaped.
	 */
	public static function is_list_array( array $value ): bool {
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Encode a scalar value for YAML.
	 *
	 * @param mixed $value The value to encode.
	 * @return string YAML representation.
	 */

	/**
	 * Encode a scalar value for YAML.
	 *
	 * @param mixed $value The value to encode.
	 * @return string YAML representation.
	 */
	public static function yaml_scalar( $value ): string {
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_null( $value ) ) {
			return '""';
		}

		$str = (string) $value;

		// Quote strings that could be misinterpreted.
		if (
			'' === $str
			|| is_numeric( $str )
			|| preg_match( '/^(true|false|null|yes|no|on|off)$/i', $str )
			|| preg_match( '/[:#\[\]{}|>!&*?]/', $str )
			|| str_starts_with( $str, ' ' )
			|| str_ends_with( $str, ' ' )
			|| str_contains( $str, "\n" )
		) {
			// Use double quotes and escape.
			$str = str_replace( '\\', '\\\\', $str );
			$str = str_replace( '"', '\\"', $str );
			$str = str_replace( "\n", '\\n', $str );
			return '"' . $str . '"';
		}

		return $str;
	}

	/**
	 * Decode simple YAML frontmatter into an array.
	 *
	 * Handles the subset we produce — not a general YAML parser.
	 *
	 * @param string $yaml The YAML string.
	 * @return array|null Decoded array, or null on failure.
	 */

	/**
	 * Decode simple YAML frontmatter into an array.
	 *
	 * Handles the subset we produce — not a general YAML parser.
	 *
	 * @param string $yaml The YAML string.
	 * @return array|null Decoded array, or null on failure.
	 */
	public static function decode_yaml( string $yaml ): ?array {
		$result = array();
		$lines  = explode( "\n", $yaml );
		$stack  = array(
			-1 => &$result,
		);

		foreach ( $lines as $line ) {
			// Skip empty lines.
			if ( '' === trim( $line ) ) {
				continue;
			}

			if ( preg_match( '/^(\s*)- (.+)$/', $line, $m ) ) {
				$indent        = strlen( $m[1] );
				$parent_indent = self::yaml_parent_indent( $indent, $stack );
				$stack[ $parent_indent ][] = self::yaml_decode_scalar( $m[2] );
				continue;
			}

			if ( preg_match( '/^(\s*)([^:]+):\s*(.*)$/', $line, $m ) ) {
				$indent        = strlen( $m[1] );
				$key           = trim( $m[2] );
				$value         = trim( $m[3] );
				$parent_indent = self::yaml_parent_indent( $indent, $stack );

				if ( '' === $value ) {
					$stack[ $parent_indent ][ $key ] = array();
					$stack[ $indent ]                 = &$stack[ $parent_indent ][ $key ];
				} else {
					$stack[ $parent_indent ][ $key ] = self::yaml_decode_scalar( $value );
				}
				continue;
			}
		}

		return empty( $result ) ? null : $result;
	}

	/**
	 * Find the nearest lower indentation level in the YAML parse stack.
	 *
	 * @param int   $indent Current indentation depth.
	 * @param array $stack  Parse stack keyed by indentation depth.
	 * @return int Parent indentation depth.
	 */

	/**
	 * Find the nearest lower indentation level in the YAML parse stack.
	 *
	 * @param int   $indent Current indentation depth.
	 * @param array $stack  Parse stack keyed by indentation depth.
	 * @return int Parent indentation depth.
	 */
	public static function yaml_parent_indent( int $indent, array $stack ): int {
		$parent_indent = -1;
		foreach ( array_keys( $stack ) as $level ) {
			if ( $level < $indent && $level > $parent_indent ) {
				$parent_indent = $level;
			}
		}
		return $parent_indent;
	}

	/**
	 * Decode a YAML scalar value.
	 *
	 * @param string $value Raw YAML scalar.
	 * @return mixed Decoded value.
	 */

	/**
	 * Decode a YAML scalar value.
	 *
	 * @param string $value Raw YAML scalar.
	 * @return mixed Decoded value.
	 */
	public static function yaml_decode_scalar( string $value ) {
		$value = trim( $value );

		// Quoted string.
		if ( preg_match( '/^"(.*)"$/s', $value, $m ) ) {
			$str = $m[1];
			$str = str_replace( '\\"', '"', $str );
			$str = str_replace( '\\\\', '\\', $str );
			$str = str_replace( '\\n', "\n", $str );
			return $str;
		}
		if ( preg_match( "/^'(.*)'$/s", $value, $m ) ) {
			return str_replace( "''", "'", $m[1] );
		}

		// Boolean.
		if ( 'true' === strtolower( $value ) ) {
			return true;
		}
		if ( 'false' === strtolower( $value ) ) {
			return false;
		}

		// Null.
		if ( 'null' === strtolower( $value ) || '~' === $value ) {
			return null;
		}

		// Integer.
		if ( preg_match( '/^-?\d+$/', $value ) ) {
			return (int) $value;
		}

		// Float.
		if ( preg_match( '/^-?\d+\.\d+$/', $value ) ) {
			return (float) $value;
		}

		return $value;
	}

	/**
	 * Sanitize a string for use as a filesystem path component.
	 *
	 * @param string $name The name to sanitize.
	 * @return string Sanitized name.
	 */
}
