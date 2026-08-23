<?php
/** Publish durable mysql-full semantic envelopes to canonical files. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_MySQL_Canonical_Publisher {
	private array $last_changes = array( 'created' => array(), 'changed' => array(), 'deleted' => array() );

	public function __construct( private object $connection ) {}

	public function publish( array $envelope ): bool {
		$intents = $envelope['intents'] ?? null;
		if ( ! is_array( $intents ) || ! is_string( $envelope['event_id'] ?? null ) || '' === $envelope['event_id'] ) {
			throw new RuntimeException( 'MySQL canonical publication envelope is invalid.' );
		}
		if ( ! $intents ) { $this->last_changes = array( 'created' => array(), 'changed' => array(), 'deleted' => array() ); return true; }
		$first = $intents[0];
		$blog_id = (int) ( $first['blog_id'] ?? -1 );
		$prefix = (string) ( $first['table_prefix'] ?? '' );
		$base = (string) ( $first['base_prefix'] ?? '' );
		if ( $blog_id < 0 ) { throw new RuntimeException( 'MySQL canonical publication scope is invalid.' ); }

		$normalized = array();
		foreach ( $intents as $intent ) {
			if ( ! is_array( $intent ) || (string) ( $intent['event_id'] ?? '' ) !== $envelope['event_id'] || (int) ( $intent['blog_id'] ?? -1 ) !== $blog_id || (string) ( $intent['table_prefix'] ?? '' ) !== $prefix || (string) ( $intent['base_prefix'] ?? '' ) !== $base || ! is_array( $intent['scope'] ?? null ) || ! is_array( $intent['resource_ids'] ?? null ) ) {
				throw new RuntimeException( 'MySQL canonical publication intents do not share one validated scope.' );
			}
			$suffix = WP_Markdown_MySQL_Operations::logical_table_suffix( (string) ( $intent['table'] ?? '' ), $prefix, $base );
			$intent['table_suffix'] = $suffix;
			$normalized[] = $intent;
		}

		$changes = array( 'created' => array(), 'changed' => array(), 'deleted' => array() );
		foreach ( $normalized as $intent ) {
			$network = (string) $intent['table'] === $base . $intent['table_suffix'] && ! str_starts_with( (string) $intent['table'], $prefix );
			$root_blog_id = $network ? 0 : $blog_id;
			$roots = $this->roots( $root_blog_id, $blog_id, array( $intent ) );
			$operations = new WP_Markdown_MySQL_Operations( $this->connection, $prefix, $base, $intent );
			$excluded = defined( 'MARKDOWN_DB_EXCLUDED_TYPES' ) ? (string) MARKDOWN_DB_EXCLUDED_TYPES : '';
			$storage = new WP_Markdown_Storage( $roots['content_dir'], array_values( array_filter( array_map( 'trim', explode( ',', $excluded ) ) ) ) );
			if ( method_exists( $storage, 'set_content_layout_profile' ) ) { $storage->set_content_layout_profile( defined( 'MARKDOWN_DB_CONTENT_LAYOUT_PROFILE' ) ? (string) MARKDOWN_DB_CONTENT_LAYOUT_PROFILE : '' ); }
			$storage->set_post_resolver( static function ( int $post_id ) use ( $operations ): ?object { $rows = $operations->post_rows( array( $post_id ) ); return $rows ? (object) $rows[0] : null; } );
			$storage->set_meta_resolver( static fn( int $post_id ): array => $operations->post_meta( $post_id ) );
			$storage->set_terms_resolver( static fn( int $post_id ): array => $operations->post_terms( $post_id ) );
			$persistence = new WP_Markdown_Canonical_Persistence( $roots['content_dir'], $storage, $operations, $prefix, $roots['state_dir'] );
			$context = array( 'resource_ids' => $intent['resource_ids'], 'scope' => $intent['scope'], 'schema' => 'schema' === ( $intent['kind'] ?? '' ) );
			if ( array_key_exists( 'current_rows', $intent ) ) { $context['current_rows'] = $intent['current_rows']; }
			$persistence->persist_mutation(
				array(
					'key'       => (string) ( $intent['stable_id'] ?? '' ),
					'resource'  => (string) ( $intent['stable_id'] ?? '' ),
					'operation' => (string) ( $intent['operation'] ?? '' ),
					'table'     => $intent['table_suffix'],
					'context'   => $context,
				),
				true
			);
			$receipt = $persistence->flush_dirty( true );
			foreach ( $changes as $kind => $_ ) { foreach ( $receipt[ $kind ] as $path ) { $changes[ $kind ][] = $root_blog_id > 1 ? 'sites/' . $root_blog_id . '/' . $path : $path; } }
		}
		foreach ( $changes as &$paths ) { $paths = array_values( array_unique( $paths ) ); sort( $paths, SORT_STRING ); } unset( $paths );
		$this->last_changes = $changes;
		return true;
	}

	public function last_changes(): array { return $this->last_changes; }

	private function roots( int $root_blog_id, int $captured_blog_id, array $intents ): array {
		$content = defined( 'MARKDOWN_DB_CONTENT_DIR' ) ? (string) MARKDOWN_DB_CONTENT_DIR : WP_CONTENT_DIR . '/markdown';
		$state = defined( 'MARKDOWN_DB_STATE_DIR' ) ? (string) MARKDOWN_DB_STATE_DIR : $content;
		if ( $root_blog_id > 1 ) {
			$content = rtrim( $content, '/\\' ) . '/sites/' . $root_blog_id;
			$state = rtrim( $state, '/\\' ) . '/sites/' . $root_blog_id;
		}
		$roots = array( 'content_dir' => $content, 'state_dir' => $state );
		if ( function_exists( 'apply_filters' ) ) {
			$roots = apply_filters( 'markdown_db_mysql_full_roots', $roots, $root_blog_id, $captured_blog_id, $intents );
		}
		if ( ! is_array( $roots ) || ! is_string( $roots['content_dir'] ?? null ) || ! is_string( $roots['state_dir'] ?? null ) || '' === $roots['content_dir'] || '' === $roots['state_dir'] ) {
			throw new RuntimeException( 'mysql-full canonical roots are invalid.' );
		}
		return $roots;
	}
}
