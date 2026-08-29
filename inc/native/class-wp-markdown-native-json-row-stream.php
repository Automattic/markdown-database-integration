<?php
/** Incremental reading of canonical table snapshots. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Yield the rows of a canonical snapshot one at a time.
 *
 * A snapshot is a JSON array of row objects. Decoding the whole array costs
 * the whole file in memory before a single row can be answered, which a
 * bounded query should never have to pay. This reader walks the file and
 * decodes one row at a time, so a caller that stops early reads only what it
 * asked for.
 */
final class WP_Markdown_Native_JSON_Row_Stream {

	private const CHUNK = 65536;

	/**
	 * @param resource $handle An opened, already safety-checked file handle.
	 * @return Generator<int,array<string,mixed>>
	 * @throws JsonException When a row is not valid JSON.
	 */
	public static function rows( $handle ): Generator {
		$depth = 0;
		$in_string = false;
		$escaped = false;
		$buffer = '';

		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, self::CHUNK );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$length = strlen( $chunk );
			for ( $i = 0; $i < $length; $i++ ) {
				$character = $chunk[ $i ];
				if ( $depth > 0 ) {
					$buffer .= $character;
				}

				if ( $in_string ) {
					if ( $escaped ) {
						$escaped = false;
					} elseif ( '\\' === $character ) {
						$escaped = true;
					} elseif ( '"' === $character ) {
						$in_string = false;
					}
					continue;
				}

				if ( '"' === $character ) {
					$in_string = true;
					continue;
				}
				if ( '{' === $character ) {
					if ( 0 === $depth ) {
						$buffer = '{';
					}
					++$depth;
					continue;
				}
				if ( '}' === $character ) {
					--$depth;
					if ( 0 === $depth ) {
						yield json_decode( $buffer, true, 512, JSON_THROW_ON_ERROR );
						$buffer = '';
					}
				}
			}
		}
	}
}
