<?php
/** Deterministic recorder and replay contract for the mdi-native query corpus. */
declare( strict_types=1 );

define( 'ABSPATH', '/private/runtime/wordpress/' );
require_once __DIR__ . '/../inc/class-wp-markdown-query-compatibility-corpus.php';

final class MDI_Query_Corpus_DB {
	public array $last_result = array();
	protected ?array $col_info = null;
	private array $query_col_info = array();
	public string $last_error = '';
	public int $last_errno = 0;
	public int $insert_id = 0;
	public int $rows_affected = 0;
	public int $num_rows = 0;
	public array $transaction = array( 'active' => false, 'savepoints' => array() );

	public function query( string $query ): int|bool {
		$this->last_result = array(); $this->col_info = null; $this->query_col_info = array(); $this->last_error = ''; $this->last_errno = 0; $this->insert_id = 0; $this->rows_affected = 0; $this->num_rows = 0;
		if ( str_starts_with( $query, 'SHOW' ) ) {
			$this->last_result = array( (object) array( 'Field' => 'id', 'Type' => 'bigint unsigned', 'Null' => 'NO', 'Key' => 'PRI' ) );
			$this->query_col_info = array( (object) array( 'name' => 'Field', 'table' => 'COLUMNS', 'type' => 'var_string' ), (object) array( 'name' => 'Type', 'table' => 'COLUMNS', 'type' => 'long_blob' ) );
			$this->col_info = $this->query_col_info; $this->num_rows = 1; return 1;
		}
		if ( str_starts_with( $query, 'SELECT' ) ) {
			if ( str_contains( $query, 'wp_users' ) ) { $this->last_result = array( (object) array( 'ID' => '3', 'user_login' => 'editor' ) ); $this->query_col_info = array( (object) array( 'name' => 'ID', 'table' => 'wp_users', 'type' => 'longlong' ), (object) array( 'name' => 'user_login', 'table' => 'wp_users', 'type' => 'var_string' ) ); }
			elseif ( str_contains( $query, 'wp_term_relationships' ) ) { $this->last_result = array( (object) array( 'object_id' => '41', 'taxonomy' => 'category', 'slug' => 'news' ) ); $this->query_col_info = array( (object) array( 'name' => 'object_id', 'table' => 'wp_term_relationships', 'type' => 'longlong' ), (object) array( 'name' => 'taxonomy', 'table' => 'wp_term_taxonomy', 'type' => 'var_string' ), (object) array( 'name' => 'slug', 'table' => 'wp_terms', 'type' => 'var_string' ) ); }
			else { $this->last_result = array( (object) array( 'option_name' => 'siteurl', 'option_value' => 'https://secret.example.test', 'updated_at' => '2026-08-23 12:34:56' ) ); $this->query_col_info = array( (object) array( 'name' => 'option_name', 'table' => 'wp_options', 'type' => 'var_string' ), (object) array( 'name' => 'option_value', 'table' => 'wp_options', 'type' => 'long_blob' ), (object) array( 'name' => 'updated_at', 'table' => 'wp_options', 'type' => 'datetime' ) ); }
			$this->col_info = $this->query_col_info; $this->num_rows = 1; return 1;
		}
		if ( str_starts_with( $query, 'INSERT' ) ) { $this->insert_id = 41; $this->rows_affected = 1; return 1; }
		if ( str_starts_with( $query, 'UPDATE' ) || str_starts_with( $query, 'DELETE' ) ) { $this->rows_affected = 1; return 1; }
		if ( str_starts_with( $query, 'CREATE' ) ) { return true; }
		if ( 'START TRANSACTION' === $query ) { $this->transaction['active'] = true; return true; }
		if ( 'ROLLBACK' === $query ) { $this->transaction['active'] = false; return true; }
		$this->last_error = 'Syntax error near secret-token at /private/runtime/wordpress/db.php'; $this->last_errno = 1064; return false;
	}
	public function get_col_info( string $type ): array { if ( null === $this->col_info ) { $this->col_info = $this->query_col_info; } return array_map( static fn( object $column ): mixed => $column->{$type} ?? null, $this->col_info ); }
}

final class MDI_Lazy_Query_Corpus_DB {
	public array $last_result = array(); public string $last_error = ''; public int $insert_id = 0; public int $rows_affected = 0; public int $num_rows = 0;
	protected ?array $col_info = null; protected mixed $result = null; public int $metadata_calls = 0;
	public function query( string $query ): int { unset( $query ); return 0; }
	public function get_col_info( string $type ): array { unset( $type ); ++$this->metadata_calls; return array( 'should-not-load' ); }
}

$db = new MDI_Query_Corpus_DB();
$normalizer = new WP_Markdown_Query_Compatibility_Normalizer( array( 'site_url' => 'https://secret.example.test', 'runtime_root' => '/private/runtime/wordpress', 'secret' => 'secret-token' ) );
$recorder = new WP_Markdown_Query_Compatibility_Recorder( $normalizer );
$transaction = static fn(): array => $db->transaction;
$queries = array(
	'core.bootstrap.options' => "SELECT option_name, option_value, updated_at FROM wp_options WHERE option_name='siteurl' AND token='550e8400-e29b-41d4-a716-446655440000'",
	'core.posts.insert' => "INSERT INTO wp_posts (post_title) VALUES ('Recorded')",
	'core.postmeta.update' => "UPDATE wp_postmeta SET meta_value='stable' WHERE meta_id=7",
	'core.users.select' => 'SELECT ID, user_login FROM wp_users WHERE ID=3',
	'core.comments.delete' => 'DELETE FROM wp_comments WHERE comment_ID=9',
	'core.taxonomy.join' => 'SELECT tr.object_id, tt.taxonomy, t.slug FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tr.term_taxonomy_id=tt.term_taxonomy_id JOIN wp_terms t ON tt.term_id=t.term_id WHERE tr.object_id=41',
	'core.schema.create' => 'CREATE TABLE wp_plugin_rows (id bigint)',
	'core.schema.inspect' => 'SHOW COLUMNS FROM wp_plugin_rows',
	'core.transaction.begin' => 'START TRANSACTION',
	'core.transaction.rollback' => 'ROLLBACK',
	'core.failure.invalid-query' => 'BROKEN secret-token SQL',
);
$returns = array();
foreach ( $queries as $scenario => $query ) { $returns[ $scenario ] = $recorder->capture( $scenario, $query, static fn() => $db->query( $query ), $db, $transaction ); }

$document = $recorder->document();
$fixture_path = __DIR__ . '/fixtures/query-corpus/wordpress-core-v1.json';
$fixture = json_decode( (string) @file_get_contents( $fixture_path ), true );
$comparison = is_array( $fixture ) ? WP_Markdown_Query_Compatibility_Comparator::compare( $fixture, $document ) : array( 'compatible' => false, 'mismatches' => array( array( 'path' => '$', 'expected' => 'fixture', 'actual' => 'missing' ) ) );
$changed = $document; $changed['observations'][1]['result']['insert_id'] = 99;
$mismatch = WP_Markdown_Query_Compatibility_Comparator::compare( $document, $changed );
$failing_recorder = new WP_Markdown_Query_Compatibility_Recorder( $normalizer );
$state_calls = 0; $backend_exception = null;
try { $failing_recorder->capture( 'core.exception', 'THROW', static function (): never { throw new RuntimeException( 'backend exception' ); }, $db, static function () use ( &$state_calls ): array { if ( ++$state_calls > 1 ) { throw new LogicException( 'capture exception' ); } return array(); } ); }
catch ( RuntimeException $error ) { $backend_exception = $error->getMessage(); }
$lazy_db = new MDI_Lazy_Query_Corpus_DB(); $lazy_recorder = new WP_Markdown_Query_Compatibility_Recorder( $normalizer );
$lazy_recorder->capture( 'core.lazy-metadata', 'SELECT 1', static fn(): int => $lazy_db->query( 'SELECT 1' ), $lazy_db );
$invalid_replacement = false;
try { new WP_Markdown_Query_Compatibility_Normalizer( array( 'invalid' => null ) ); }
catch ( InvalidArgumentException $error ) { $invalid_replacement = true; }
$json = $recorder->json();
$checks = array(
	'caller return values preserved' => 1 === $returns['core.posts.insert'] && true === $returns['core.transaction.begin'] && false === $returns['core.failure.invalid-query'],
	'versioned deterministic fixture' => true === $comparison['compatible'],
	'normalization removes volatile and sensitive bytes' => ! str_contains( $json, 'secret.example.test' ) && ! str_contains( $json, '/private/runtime/wordpress' ) && ! str_contains( $json, 'secret-token' ) && str_contains( $json, '<uuid>' ) && str_contains( $json, '<timestamp>' ),
	'invalid sanitization replacements fail explicitly' => $invalid_replacement,
	'rows and column metadata retain ordered behavioral shape' => 'siteurl' === ( $document['observations'][0]['result']['rows'][0]['option_name'] ?? null ) && 'option_name' === ( $document['observations'][0]['result']['columns'][0]['name'] ?? null ),
	'error code and message behavior are retained' => 1064 === ( $document['observations'][10]['result']['error_code'] ?? null ) && str_contains( (string) ( $document['observations'][10]['result']['last_error'] ?? '' ), '<secret>' ),
	'lazy non-mysqli metadata remains untouched' => 0 === $lazy_db->metadata_calls && array() === $lazy_recorder->document()['observations'][0]['result']['columns'],
	'transaction transitions are captured' => false === ( $document['observations'][8]['transaction']['before']['active'] ?? null ) && true === ( $document['observations'][8]['transaction']['after']['active'] ?? null ) && false === ( $document['observations'][9]['transaction']['after']['active'] ?? null ),
	'exact mismatch paths are structured' => false === $mismatch['compatible'] && '$.observations[1].result.insert_id' === ( $mismatch['mismatches'][0]['path'] ?? null ),
	'backend exceptions survive capture failures' => 'backend exception' === $backend_exception && 1 === count( $failing_recorder->capture_failures() ),
);
$failed = 0;
foreach ( $checks as $label => $passed ) { echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL; if ( ! $passed ) { ++$failed; } }
if ( ! is_array( $fixture ) ) { fwrite( STDERR, json_encode( $document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . PHP_EOL ); }
elseif ( ! $comparison['compatible'] ) { fwrite( STDERR, json_encode( $document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . PHP_EOL ); }
exit( $failed ? 1 : 0 );
