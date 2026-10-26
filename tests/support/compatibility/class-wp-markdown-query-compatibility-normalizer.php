<?php
/** Deterministic normalization for wpdb compatibility observations. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Query_Compatibility_Normalizer {
	/** @var array<string,string> */
	private array $replacements;

	/** @param array<array-key,mixed> $replacements Sensitive or volatile bytes keyed by their stable token. */
	public function __construct( array $replacements = array() ) {
		$normalized = array();
		foreach ( $replacements as $token => $value ) {
			if ( ! is_string( $token ) || '' === $token || ! is_string( $value ) || '' === $value ) {
				throw new InvalidArgumentException( 'Query corpus replacements require non-empty string tokens and values.' );
			}
			$normalized[ $value ] = '<' . trim( $token, '<>' ) . '>';
		}
		uksort(
			$normalized,
			static function ( string $left, string $right ): int {
				$length_order = strlen( $right ) <=> strlen( $left );
				return 0 !== $length_order ? $length_order : strcmp( $left, $right );
			}
		);
		$this->replacements = $normalized;
	}

	public function value( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			return $this->string( $value );
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = $this->value( $item );
			}
			return $out;
		}
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		throw new InvalidArgumentException( 'Query corpus values must be JSON-compatible.' );
	}

	public function string( string $value ): string {
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		if ( $this->replacements ) {
			$value = str_replace( array_keys( $this->replacements ), array_values( $this->replacements ), $value );
		}
		$value = (string) preg_replace( '/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i', '<uuid>', $value );
		return (string) preg_replace( '/\b\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?\b/', '<timestamp>', $value );
	}
}
