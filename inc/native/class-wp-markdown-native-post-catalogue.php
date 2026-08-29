<?php
/** What the canonical corpus said, and which file said it. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../class-wp-markdown-file-witness.php';

/**
 * The rows the corpus produced, held against the files that produced them.
 *
 * Reading posts needs two things repeatedly: what a file said last time it
 * was read, and which file a durable identity lives in. Those were answered
 * by separate collections that were filled by different code paths and could
 * disagree, so they are one collection here, and an entry is only ever handed
 * back with the witness it was recorded under so a caller must prove the file
 * still stands before believing it.
 */
final class WP_Markdown_Native_Post_Catalogue {

	/** @var array<string,array{witness:WP_Markdown_File_Witness,file:array<string,mixed>,post:object,row:array<string,mixed>}> */
	private array $entries = array();

	/** @var array<int,string> */
	private array $paths = array();

	/**
	 * @param array<string,mixed> $file The manifest entry the row came from.
	 * @param array<string,mixed> $row
	 */
	public function remember( WP_Markdown_File_Witness $witness, array $file, object $post, array $row ): void {
		$path = (string) ( $file['absolute'] ?? '' );
		if ( '' === $path ) {
			return;
		}
		$this->entries[ $path ] = array( 'witness' => $witness, 'file' => $file, 'post' => $post, 'row' => $row );
		$id = (int) ( $post->ID ?? 0 );
		if ( $id > 0 ) {
			$this->paths[ $id ] = $path;
		}
	}

	/**
	 * The entry recorded for a file, if it was recorded under this witness.
	 *
	 * @return array{witness:WP_Markdown_File_Witness,file:array<string,mixed>,post:object,row:array<string,mixed>}|null
	 */
	public function recorded( string $path, ?WP_Markdown_File_Witness $witness ): ?array {
		$entry = $this->entries[ $path ] ?? null;
		if ( null === $entry || null === $witness || ! $entry['witness']->is( $witness ) ) {
			return null;
		}
		return $entry;
	}

	/** The file a durable identity was last found in. */
	public function file_for( int $id ): ?array {
		$path = $this->paths[ $id ] ?? null;
		return null === $path ? null : ( $this->entries[ $path ]['file'] ?? null );
	}

	/** A write makes every recorded statement about the corpus unproven. */
	public function forget(): void {
		$this->entries = array();
		$this->paths   = array();
	}
}
