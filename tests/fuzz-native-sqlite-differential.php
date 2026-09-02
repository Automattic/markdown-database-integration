<?php
/** Seeded behavioral differential between the MDI native runtime and SQLite. */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$plugin_root = dirname( __DIR__ );
if ( defined( 'WP_PLUGIN_DIR' ) && is_file( WP_PLUGIN_DIR . '/markdown-database-integration/inc/native/class-wp-markdown-native-query-runtime.php' ) ) {
	$plugin_root = WP_PLUGIN_DIR . '/markdown-database-integration';
}

require_once $plugin_root . '/inc/native/class-wp-markdown-native-query-runtime.php';
require_once $plugin_root . '/inc/compatibility/class-wp-markdown-query-compatibility-comparator.php';

const MDI_FUZZ_DEFAULT_SEED = 'mdi-native-sqlite-full-surface-v2';
const MDI_FUZZ_ROWS = 24;
const MDI_FUZZ_CASES_PER_CATEGORY = 8;

/** Return a stable bounded integer without using process-global PRNG state. */
function mdi_fuzz_number( string $seed, string $key, int $maximum ): int {
	return hexdec( substr( hash( 'sha256', $seed . ':' . $key ), 0, 7 ) ) % $maximum;
}

function mdi_fuzz_literal( ?string $value ): string {
	return null === $value ? 'NULL' : "'" . str_replace( "'", "''", $value ) . "'";
}

/** Normalize scalar types while retaining result row and column order. */
function mdi_fuzz_normalize_rows( array $rows ): array {
	return array_map(
		static function ( object|array $row ): array {
			return array_map(
				static fn( mixed $value ): mixed => null === $value ? null : (string) $value,
				(array) $row
			);
		},
		$rows
	);
}

/** @return array{status:string,columns?:array<int,string>,rows?:array,error_code?:string,message?:string} */
function mdi_fuzz_native_outcome( WP_Markdown_Native_Query_Runtime $runtime, string $query ): array {
	$result = $runtime->execute( new WP_Markdown_Query_Request( $query ) );
	if ( false === $result->return_value() ) {
		$diagnostic = $result->diagnostic();
		return array(
			'status' => 'error',
			'error_code' => (string) ( $diagnostic['code'] ?? '' ),
			'message' => (string) ( $diagnostic['message'] ?? '' ),
		);
	}

	$state = $result->wpdb_state();
	return array(
		'status' => 'ok',
		'columns' => array_map(
			static fn( object $column ): string => (string) $column->name,
			$state['col_info'] ?? array()
		),
		'rows' => mdi_fuzz_normalize_rows( $state['last_result'] ?? array() ),
	);
}

/** @return array{status:string,columns?:array<int,string>,rows?:array,error_code?:string,message?:string} */
function mdi_fuzz_sqlite_outcome( PDO $pdo, string $query ): array {
	try {
		$statement = $pdo->query( $query );
		$columns = array();
		for ( $index = 0; $index < $statement->columnCount(); ++$index ) {
			$metadata = $statement->getColumnMeta( $index );
			$columns[] = (string) ( $metadata['name'] ?? '' );
		}
		return array(
			'status' => 'ok',
			'columns' => $columns,
			'rows' => mdi_fuzz_normalize_rows( $statement->fetchAll( PDO::FETCH_ASSOC ) ),
		);
	} catch ( PDOException $error ) {
		return array(
			'status' => 'error',
			'error_code' => (string) $error->getCode(),
			'message' => $error->getMessage(),
		);
	}
}

function mdi_fuzz_fixture( string $seed ): array {
	$items = array();
	$meta = array();
	$groups = array();
	for ( $index = 0; $index < MDI_FUZZ_ROWS; ++$index ) {
		$id = $index + 1;
		$items[] = array(
			'item_id' => $id,
			'join_key' => 1 + ( $index % 9 ),
			'status' => array( 'publish', 'draft', 'private', 'trash' )[ $index % 4 ],
			'title' => ( 0 === $index % 5 ? 'alpha' : 'item' ) . '_' . substr( hash( 'sha256', $seed . ':item:' . $index ), 0, 7 ),
			'nullable_text' => 0 === $index % 6 ? null : ( 0 === $index % 3 ? '' : 'note_' . ( $index % 5 ) ),
			'numeric_text' => (string) ( ( $index % 7 ) * 10 + 2 ),
			'amount' => ( $index % 8 ) * 11,
			'event_date' => sprintf( '2026-%02d-%02d 12:00:00', 1 + ( $index % 4 ), 1 + ( $index % 27 ) ),
			'group_key' => array( 'blue', 'green', 'red', 'blue' )[ $index % 4 ],
		);
		if ( 0 !== $index % 5 ) {
			$meta[] = array(
				'meta_id' => count( $meta ) + 1,
				'item_ref' => $id,
				'join_key' => 1 + ( $index % 9 ),
				'meta_key' => 0 === $index % 3 ? 'featured' : 'coverage',
				'meta_value' => 0 === $index % 7 ? null : (string) ( $index % 13 ),
			);
		}
		if ( 0 === $index % 4 ) {
			$meta[] = array(
				'meta_id' => count( $meta ) + 1,
				'item_ref' => $id,
				'join_key' => 1 + ( $index % 9 ),
				'meta_key' => 'duplicate',
				'meta_value' => 'extra',
			);
		}
	}
	for ( $index = 1; $index <= 7; ++$index ) {
		$groups[] = array( 'group_id' => $index, 'join_key' => $index, 'group_name' => 'group_' . $index );
	}
	return array( 'items' => $items, 'meta' => $meta, 'groups' => $groups );
}

/** @return array{category:string,native_sql:string,sqlite_sql:string,ordered:bool} */
function mdi_fuzz_case( string $category, string $native_sql, bool $ordered, ?string $sqlite_sql = null ): array {
	return array(
		'category' => $category,
		'native_sql' => $native_sql,
		'sqlite_sql' => $sqlite_sql ?? $native_sql,
		'ordered' => $ordered,
	);
}

/** Build a fixed category matrix; seed changes literals and bounds, not coverage. */
function mdi_fuzz_cases( string $seed, array $fixture ): array {
	$items = $fixture['items'];
	$cases = array();
	for ( $case = 0; $case < MDI_FUZZ_CASES_PER_CATEGORY; ++$case ) {
		$a = $items[ mdi_fuzz_number( $seed, "{$case}:a", count( $items ) ) ];
		$b = $items[ mdi_fuzz_number( $seed, "{$case}:b", count( $items ) ) ];
		$limit = 1 + mdi_fuzz_number( $seed, "{$case}:limit", 5 );
		$offset = mdi_fuzz_number( $seed, "{$case}:offset", 4 );
		$ids = $a['item_id'] . ', ' . $b['item_id'] . ', ' . $a['item_id'];
		$statuses = "'publish', 'draft'";

		$cases[] = mdi_fuzz_case( 'projection', "SELECT item_id, title FROM wp_items WHERE item_id = {$a['item_id']} ORDER BY item_id", true );
		$cases[] = mdi_fuzz_case( 'distinct', "SELECT DISTINCT group_key FROM wp_items WHERE status IN ({$statuses}) ORDER BY group_key", true );
		$cases[] = mdi_fuzz_case( 'order.composite', "SELECT item_id, status FROM wp_items ORDER BY status ASC, item_id DESC LIMIT {$limit}", true );
		$cases[] = mdi_fuzz_case( 'limit.offset', "SELECT item_id FROM wp_items ORDER BY item_id ASC LIMIT {$offset}, {$limit}", true );
		$cases[] = mdi_fuzz_case( 'filter.equality', 'SELECT item_id FROM wp_items WHERE title = ' . mdi_fuzz_literal( $a['title'] ) . ' ORDER BY item_id', true );
		$cases[] = mdi_fuzz_case( 'filter.in', "SELECT item_id FROM wp_items WHERE item_id IN ({$ids}) ORDER BY item_id", true );
		$cases[] = mdi_fuzz_case( 'filter.not_in', "SELECT item_id FROM wp_items WHERE status NOT IN ('trash') ORDER BY item_id LIMIT {$limit}", true );
		$cases[] = mdi_fuzz_case( 'filter.range', "SELECT item_id FROM wp_items WHERE amount >= {$a['amount']} AND amount < " . ( $a['amount'] + 30 ) . ' ORDER BY amount, item_id', true );
		$cases[] = mdi_fuzz_case( 'filter.between', 'SELECT item_id FROM wp_items WHERE item_id BETWEEN ' . min( $a['item_id'], $b['item_id'] ) . ' AND ' . max( $a['item_id'], $b['item_id'] ) . ' ORDER BY item_id', true );
		$cases[] = mdi_fuzz_case( 'filter.like', "SELECT item_id FROM wp_items WHERE title LIKE 'alpha%' ORDER BY item_id", true );
		$cases[] = mdi_fuzz_case( 'filter.null', "SELECT item_id FROM wp_items WHERE nullable_text IS NULL OR nullable_text = '' ORDER BY item_id", true );
		$cases[] = mdi_fuzz_case( 'filter.regexp', "SELECT item_id FROM wp_items WHERE title REGEXP '^alpha' ORDER BY item_id", true );
		$cases[] = mdi_fuzz_case( 'group.count.having', "SELECT group_key, COUNT(*) AS total FROM wp_items WHERE status IN ({$statuses}) GROUP BY group_key HAVING total > 0", false );
		$cases[] = mdi_fuzz_case( 'aggregate.numeric', 'SELECT SUM(amount) AS total, AVG(amount) AS mean, MIN(amount) AS lowest, MAX(amount) AS highest FROM wp_items', false );
		$cases[] = mdi_fuzz_case( 'aggregate.group_concat', 'SELECT group_key, GROUP_CONCAT(item_id) AS ids FROM wp_items WHERE item_id <= 12 GROUP BY group_key', false );
		$cases[] = mdi_fuzz_case( 'join.inner.multiplicity', "SELECT i.item_id, m.meta_key FROM wp_items i INNER JOIN wp_meta m ON i.item_id = m.item_ref WHERE i.item_id IN ({$ids}) ORDER BY i.item_id, m.meta_id", true );
		$cases[] = mdi_fuzz_case( 'join.left.unmatched', 'SELECT i.item_id, m.meta_key FROM wp_items i LEFT JOIN wp_meta m ON i.item_id = m.item_ref WHERE i.item_id <= 8 ORDER BY i.item_id, m.meta_id', true );
		$cases[] = mdi_fuzz_case( 'join.chained', 'SELECT i.item_id, g.group_name FROM wp_items i INNER JOIN wp_meta m ON i.item_id = m.item_ref INNER JOIN wp_groups g ON m.join_key = g.join_key WHERE i.item_id <= 8 ORDER BY i.item_id, m.meta_id', true );
		$cases[] = mdi_fuzz_case( 'subquery.in', "SELECT item_id FROM wp_items WHERE item_id IN ( SELECT item_ref FROM wp_meta WHERE meta_key = 'featured' ) ORDER BY item_id", true );
		$cases[] = mdi_fuzz_case( 'subquery.not_in', "SELECT item_id FROM wp_items WHERE item_id NOT IN ( SELECT item_ref FROM wp_meta WHERE meta_key = 'featured' ) ORDER BY item_id", true );
		$cases[] = mdi_fuzz_case( 'subquery.exists', "SELECT item_id FROM wp_items i WHERE EXISTS ( SELECT 1 FROM wp_meta m WHERE m.item_ref = i.item_id AND m.meta_key = 'featured' ) ORDER BY item_id", true );
		$cases[] = mdi_fuzz_case( 'union', "SELECT item_id FROM wp_items WHERE status = 'publish' UNION SELECT item_id FROM wp_items WHERE status = 'draft'", false );
		$cases[] = mdi_fuzz_case( 'scalar.case', "SELECT item_id, CASE WHEN status = 'publish' THEN 1 ELSE 0 END AS is_live FROM wp_items WHERE item_id <= {$limit} ORDER BY item_id", true );
		$cases[] = mdi_fuzz_case( 'scalar.concat', "SELECT CONCAT(title, '-', item_id) AS label FROM wp_items WHERE item_id <= {$limit} ORDER BY item_id", true );
		$cases[] = mdi_fuzz_case( 'scalar.coalesce', "SELECT COALESCE(nullable_text, title) AS shown FROM wp_items WHERE item_id <= {$limit} ORDER BY item_id", true );
		$cases[] = mdi_fuzz_case( 'scalar.substring', "SELECT SUBSTRING(title, 1, 3) AS head FROM wp_items WHERE item_id <= {$limit} ORDER BY item_id", true );
		$cases[] = mdi_fuzz_case( 'scalar.cast', "SELECT CAST(numeric_text AS UNSIGNED) AS numeric_value FROM wp_items WHERE item_id <= {$limit} ORDER BY item_id", true, "SELECT CAST(numeric_text AS INTEGER) AS numeric_value FROM wp_items WHERE item_id <= {$limit} ORDER BY item_id" );
		$cases[] = mdi_fuzz_case( 'scalar.date', "SELECT YEAR(event_date) AS y, MONTH(event_date) AS m, DATE_FORMAT(event_date, '%Y-%m') AS period FROM wp_items WHERE item_id <= {$limit} ORDER BY item_id", true );
		$cases[] = mdi_fuzz_case( 'order.field', "SELECT item_id FROM wp_items ORDER BY FIELD(status, 'publish', 'draft', 'private', 'trash'), item_id LIMIT {$limit}", true );
		$cases[] = mdi_fuzz_case( 'aliases', "SELECT i.item_id, i.title FROM wp_items AS i WHERE i.item_id = {$a['item_id']}", false );
	}
	return $cases;
}

function mdi_fuzz_remove_fixture( string $root ): void {
	foreach ( glob( $root . '/_tables/*' ) ?: array() as $path ) {
		@unlink( $path );
	}
	foreach ( glob( $root . '/_schema/*' ) ?: array() as $path ) {
		@unlink( $path );
	}
	@rmdir( $root . '/_tables' );
	@rmdir( $root . '/_schema' );
	@rmdir( $root . '/_options' );
	@rmdir( $root );
}

function mdi_fuzz_create_sqlite( array $fixture ): PDO {
	$pdo = new Pdo\Sqlite( 'sqlite::memory:', null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
	$pdo->createFunction( 'CONCAT', static fn( ...$values ): ?string => in_array( null, $values, true ) ? null : implode( '', $values ), -1 );
	$pdo->createFunction( 'FIELD', static function ( mixed $value, mixed ...$values ): int {
		$index = array_search( $value, $values, true );
		return false === $index ? 0 : $index + 1;
	}, -1 );
	$pdo->createFunction( 'REGEXP', static fn( string $pattern, ?string $value ): int => null === $value ? 0 : (int) preg_match( '/' . str_replace( '/', '\\/', $pattern ) . '/', $value ) );
	$pdo->createFunction( 'YEAR', static fn( ?string $value ): ?string => null === $value ? null : substr( $value, 0, 4 ), 1 );
	$pdo->createFunction( 'MONTH', static fn( ?string $value ): ?string => null === $value ? null : substr( $value, 5, 2 ), 1 );
	$pdo->createFunction( 'DATE_FORMAT', static fn( ?string $value, string $format ): ?string => null === $value ? null : str_replace( array( '%Y', '%m', '%d' ), array( substr( $value, 0, 4 ), substr( $value, 5, 2 ), substr( $value, 8, 2 ) ), $format ), 2 );

	$pdo->exec( 'CREATE TABLE wp_items (item_id INTEGER PRIMARY KEY, join_key INTEGER NOT NULL, status TEXT NOT NULL COLLATE NOCASE, title TEXT NOT NULL COLLATE NOCASE, nullable_text TEXT, numeric_text TEXT NOT NULL, amount INTEGER NOT NULL, event_date TEXT NOT NULL, group_key TEXT NOT NULL COLLATE NOCASE)' );
	$pdo->exec( 'CREATE TABLE wp_meta (meta_id INTEGER PRIMARY KEY, item_ref INTEGER NOT NULL, join_key INTEGER NOT NULL, meta_key TEXT NOT NULL COLLATE NOCASE, meta_value TEXT)' );
	$pdo->exec( 'CREATE TABLE wp_groups (group_id INTEGER PRIMARY KEY, join_key INTEGER NOT NULL, group_name TEXT NOT NULL COLLATE NOCASE)' );

	foreach ( array( 'items', 'meta', 'groups' ) as $table ) {
		$columns = array_keys( $fixture[ $table ][0] );
		$insert = $pdo->prepare( 'INSERT INTO wp_' . $table . ' (' . implode( ', ', $columns ) . ') VALUES (:' . implode( ', :', $columns ) . ')' );
		foreach ( $fixture[ $table ] as $row ) {
			$insert->execute( $row );
		}
	}
	return $pdo;
}

/** Canonicalize unordered rows without collapsing duplicated result rows. */
function mdi_fuzz_row_multiset( array $rows ): array {
	$serialized = array_map(
		static fn( array $row ): array => array( 'key' => serialize( $row ), 'row' => $row ),
		$rows
	);
	usort( $serialized, static fn( array $left, array $right ): int => strcmp( $left['key'], $right['key'] ) );
	return array_column( $serialized, 'row' );
}

/**
 * Compare all observable result properties exactly, except for row sequence
 * where the generated SQL deliberately has no outer ORDER BY.
 */
function mdi_fuzz_compare( array $sqlite, array $native, bool $ordered ): array {
	if ( ! $ordered && 'ok' === $sqlite['status'] && 'ok' === $native['status'] ) {
		$sqlite['rows'] = mdi_fuzz_row_multiset( $sqlite['rows'] );
		$native['rows'] = mdi_fuzz_row_multiset( $native['rows'] );
	}
	return WP_Markdown_Query_Compatibility_Comparator::compare( $sqlite, $native );
}

function mdi_fuzz_initialize_categories( array $cases ): array {
	$categories = array();
	foreach ( $cases as $case ) {
		$category = $case['category'];
		$categories[ $category ] ??= array(
			'cases' => 0,
			'passed' => 0,
			'failed' => 0,
			'native_duration_ms' => 0.0,
			'sqlite_duration_ms' => 0.0,
		);
		++$categories[ $category ]['cases'];
	}
	return $categories;
}

/** Run one representative query per category before timing the measured set. */
function mdi_fuzz_warmup( WP_Markdown_Native_Query_Runtime $runtime, PDO $pdo, array $cases ): array {
	$warmups = array();
	foreach ( $cases as $case ) {
		if ( isset( $warmups[ $case['category'] ] ) ) {
			continue;
		}
		mdi_fuzz_native_outcome( $runtime, $case['native_sql'] );
		mdi_fuzz_sqlite_outcome( $pdo, $case['sqlite_sql'] );
		$warmups[ $case['category'] ] = array(
			'category' => $case['category'],
			'native_sql' => $case['native_sql'],
			'sqlite_sql' => $case['sqlite_sql'],
			'ordered' => $case['ordered'],
		);
	}
	return array_values( $warmups );
}

/** Stable surface inventory. `executable` means this standalone harness proves it. */
function mdi_fuzz_operation_registry(): array {
	return array(
		'query.read' => array( 'family' => 'query', 'safety_class' => 'read_only', 'executable' => true ),
		'dml.generic' => array( 'family' => 'update', 'safety_class' => 'isolated_mutation', 'executable' => true ),
		'schema.ddl.introspection' => array( 'family' => 'create', 'safety_class' => 'isolated_mutation', 'executable' => true ),
		'constraints.defaults-uniqueness-auto-increment' => array( 'family' => 'create', 'safety_class' => 'isolated_mutation', 'executable' => true ),
		'transaction.savepoint' => array( 'family' => 'update', 'safety_class' => 'isolated_mutation', 'executable' => true ),
		'persistence.restart' => array( 'family' => 'read', 'safety_class' => 'isolated_mutation', 'executable' => true ),
		'wpdb.observable-state' => array( 'family' => 'read', 'safety_class' => 'requires_wordpress_bootstrap', 'executable' => false, 'skip_reason' => 'The standalone runtime does not bootstrap wpdb.' ),
		'concurrency.deterministic' => array( 'family' => 'read', 'safety_class' => 'requires_deterministic_coordinator', 'executable' => false, 'skip_reason' => 'No deterministic multi-process coordinator is available in this PHP workload.' ),
		'unsupported.error' => array( 'family' => 'query', 'safety_class' => 'read_only', 'executable' => true ),
	);
}

/** Normalize the Homeboy request while retaining direct-script environment fallback. */
function mdi_fuzz_request( array $input ): array {
	$request = $input['execution_request'] ?? array();
	$request = is_array( $request ) && 'homeboy/fuzz-execution-request/v1' === ( $request['schema'] ?? null ) ? $request : array();
	$runtime_env = $input['runtime_env'] ?? $input['runtimeEnv'] ?? array();
	$runtime_env = is_array( $runtime_env ) ? $runtime_env : array();
	$file = $runtime_env['MDI_FUZZ_REQUEST_FILE'] ?? getenv( 'MDI_FUZZ_REQUEST_FILE' );
	if ( array() === $request && is_string( $file ) && is_file( $file ) ) {
		$decoded = json_decode( (string) file_get_contents( $file ), true );
		$request = is_array( $decoded ) && 'homeboy/fuzz-execution-request/v1' === ( $decoded['schema'] ?? null ) ? $decoded : array();
	}
	$sampling = is_array( $request['sampling'] ?? null ) ? $request['sampling'] : array();
	$metadata = is_array( $request['metadata'] ?? null ) ? $request['metadata'] : array();
	$selection = is_array( $metadata['selection'] ?? null ) ? $metadata['selection'] : array();
	$budgets = is_array( $metadata['budgets'] ?? null ) ? $metadata['budgets'] : array();
	$profile = $metadata['planner']['profile'] ?? $sampling['metadata']['profile'] ?? null;
	$profile = is_string( $profile ) ? $profile : null;
	$profiles = array(
		'quick' => array( 'operations' => array( 'query.read' ), 'case_budget' => 24, 'duration_budget_seconds' => 30 ),
		'read-only' => array( 'operations' => array( 'unsupported.error', 'query.read' ), 'case_budget' => 242, 'duration_budget_seconds' => 120 ),
		'crud' => array( 'operations' => array( 'dml.generic', 'constraints.defaults-uniqueness-auto-increment', 'query.read' ), 'case_budget' => 66, 'duration_budget_seconds' => 120 ),
		'full' => array( 'operations' => array_keys( mdi_fuzz_operation_registry() ), 'case_budget' => 256, 'duration_budget_seconds' => 300 ),
		'nightly' => array( 'operations' => array_keys( mdi_fuzz_operation_registry() ), 'case_budget' => 512, 'duration_budget_seconds' => 900 ),
	);
	$profile_defaults = null === $profile ? array() : ( $profiles[ $profile ] ?? array() );
	$operation_ids = array_values( array_filter( $request['operation_ids'] ?? $request['operations'] ?? array(), 'is_string' ) );
	$families = array_values( array_filter( $request['operation_families'] ?? $request['families'] ?? array(), 'is_string' ) );
	foreach ( $sampling['operation_strata'] ?? array() as $stratum ) {
		if ( ! is_array( $stratum ) ) {
			continue;
		}
		if ( 'operation' === ( $stratum['kind'] ?? null ) || 'selected-operations' === ( $stratum['id'] ?? null ) ) {
			$operation_ids = array_merge( $operation_ids, array_values( array_filter( $stratum['values'] ?? array(), 'is_string' ) ) );
		} elseif ( 'operation_family' === ( $stratum['kind'] ?? null ) || 'selected-operation-families' === ( $stratum['id'] ?? null ) ) {
			$families = array_merge( $families, array_values( array_filter( $stratum['values'] ?? array(), 'is_string' ) ) );
		}
	}
	foreach ( $selection['operations'] ?? array() as $operation ) {
		if ( is_array( $operation ) && is_string( $operation['operation_id'] ?? null ) ) {
			$operation_ids[] = $operation['operation_id'];
		}
	}
	$families = array_merge( $families, array_values( array_filter( $selection['operation_families'] ?? array(), 'is_string' ) ) );
	if ( array() === $operation_ids && array() === $families && isset( $profile_defaults['operations'] ) ) {
		$operation_ids = $profile_defaults['operations'];
	}
	$seed = $sampling['seed'] ?? $request['seed'] ?? $runtime_env['HOMEBOY_FUZZ_SEED'] ?? $runtime_env['MDI_FUZZ_SEED'] ?? getenv( 'MDI_FUZZ_SEED' ) ?: MDI_FUZZ_DEFAULT_SEED;
	$case_budget = $sampling['case_budget'] ?? $budgets['case_budget'] ?? $request['case_budget'] ?? $runtime_env['MDI_FUZZ_CASE_BUDGET'] ?? $profile_defaults['case_budget'] ?? 0;
	$duration_budget = $sampling['duration_budget_seconds'] ?? $budgets['duration_budget_seconds'] ?? $request['duration_budget_seconds'] ?? $runtime_env['MDI_FUZZ_DURATION_BUDGET_SECONDS'] ?? $profile_defaults['duration_budget_seconds'] ?? 0;
	$max_duration = $request['max_duration'] ?? $budgets['max_duration'] ?? null;
	if ( 0 >= (float) $duration_budget && is_string( $max_duration ) && preg_match( '/^([0-9]+(?:\.[0-9]+)?)(s|m|h)$/', $max_duration, $matches ) ) {
		$duration_budget = (float) $matches[1] * array( 's' => 1, 'm' => 60, 'h' => 3600 )[ $matches[2] ];
	}
	return array(
		'seed' => is_string( $seed ) && '' !== $seed ? $seed : MDI_FUZZ_DEFAULT_SEED,
		'operation_ids' => array_values( array_unique( $operation_ids ) ),
		'families' => array_values( array_unique( $families ) ),
		'case_budget' => max( 0, (int) $case_budget ),
		'duration_budget_seconds' => max( 0, (float) $duration_budget ),
		'profile' => $profile,
		'execution_request' => $request,
	);
}

function mdi_fuzz_selected_operations( array $request ): array {
	$registry = mdi_fuzz_operation_registry();
	$ids = $request['operation_ids'];
	$families = $request['families'];
	if ( array() === $ids && array() === $families ) {
		return array( 'query.read' ); // Preserve the established 240-query direct campaign.
	}
	$candidates = array() === $ids ? array_keys( $registry ) : $ids;
	return array_values( array_filter( $candidates, static fn( string $id ): bool => isset( $registry[ $id ] ) && ( array() === $families || in_array( $registry[ $id ]['family'], $families, true ) ) ) );
}

function mdi_fuzz_create_runtime_fixture( string $seed ): array {
	$root = sys_get_temp_dir() . '/mdi-native-sqlite-fuzz-' . bin2hex( random_bytes( 6 ) );
	if ( ! mkdir( $root . '/_tables', 0777, true ) || ! mkdir( $root . '/_schema', 0777, true ) || ! mkdir( $root . '/_options', 0777, true ) ) {
		throw new RuntimeException( 'Failed to create the differential fixture.' );
	}
	$fixture = mdi_fuzz_fixture( $seed );
	foreach ( $fixture as $table => $rows ) {
		file_put_contents( $root . '/_tables/' . $table . '.json', json_encode( $rows, JSON_THROW_ON_ERROR ) );
	}
	$ddl = 'CREATE TABLE wp_items (item_id bigint unsigned NOT NULL, join_key bigint unsigned NOT NULL, status varchar(20) NOT NULL, title varchar(100) NOT NULL, nullable_text varchar(100) NULL, numeric_text varchar(20) NOT NULL, amount bigint NOT NULL, event_date datetime NOT NULL, group_key varchar(20) NOT NULL, PRIMARY KEY (item_id), KEY join_key (join_key), KEY status (status), KEY title (title), KEY amount (amount), KEY event_date (event_date), KEY group_key (group_key)); CREATE TABLE wp_meta (meta_id bigint unsigned NOT NULL, item_ref bigint unsigned NOT NULL, join_key bigint unsigned NOT NULL, meta_key varchar(30) NOT NULL, meta_value varchar(100) NULL, PRIMARY KEY (meta_id), KEY item_ref (item_ref), KEY join_key (join_key), KEY meta_key (meta_key)); CREATE TABLE wp_groups (group_id bigint unsigned NOT NULL, join_key bigint unsigned NOT NULL, group_name varchar(50) NOT NULL, PRIMARY KEY (group_id), KEY join_key (join_key));';
	$first_break = strpos( $ddl, ';' );
	file_put_contents( $root . '/_schema/items.sql', substr( $ddl, 0, $first_break + 1 ) );
	$remaining = substr( $ddl, $first_break + 1 );
	$second_break = strpos( $remaining, ';' );
	file_put_contents( $root . '/_schema/meta.sql', substr( $remaining, 0, $second_break + 1 ) );
	file_put_contents( $root . '/_schema/groups.sql', substr( $remaining, $second_break + 1 ) );
	return array( $root, $fixture, WP_Markdown_Native_Runtime_Factory::runtime( $root ), mdi_fuzz_create_sqlite( $fixture ) );
}

function mdi_fuzz_sequence_case( string $operation, string $seed ): array {
	[ $root, $fixture, $runtime, $pdo ] = mdi_fuzz_create_runtime_fixture( $seed . ':' . $operation );
	try {
		$sequence = match ( $operation ) {
			'dml.generic' => array( 'CREATE TABLE wp_probe (id int unsigned NOT NULL AUTO_INCREMENT, label varchar(20) NOT NULL, PRIMARY KEY (id))', "INSERT INTO wp_probe (label) VALUES ('one')", "UPDATE wp_probe SET label = 'two' WHERE id = 1", 'DELETE FROM wp_probe WHERE id = 1' ),
			'schema.ddl.introspection' => array( 'CREATE TABLE wp_probe (id int unsigned NOT NULL AUTO_INCREMENT, label varchar(20) NOT NULL, PRIMARY KEY (id))' ),
			'constraints.defaults-uniqueness-auto-increment' => array( "CREATE TABLE wp_probe (id int unsigned NOT NULL AUTO_INCREMENT, name varchar(20) NOT NULL, state varchar(20) NOT NULL DEFAULT 'draft', PRIMARY KEY (id), UNIQUE KEY name (name))", "INSERT INTO wp_probe (name) VALUES ('one')" ),
			'transaction.savepoint' => array( 'CREATE TABLE wp_probe (id int unsigned NOT NULL AUTO_INCREMENT, label varchar(20) NOT NULL, PRIMARY KEY (id))', 'START TRANSACTION', "INSERT INTO wp_probe (label) VALUES ('one')", 'SAVEPOINT fuzz_save', "UPDATE wp_probe SET label = 'two' WHERE id = 1", 'ROLLBACK TO SAVEPOINT fuzz_save', 'COMMIT' ),
			'persistence.restart' => array( 'CREATE TABLE wp_probe (id int unsigned NOT NULL AUTO_INCREMENT, label varchar(20) NOT NULL, PRIMARY KEY (id))', "INSERT INTO wp_probe (label) VALUES ('one')" ),
			default => array(),
		};
		foreach ( $sequence as $sql ) {
			$native = mdi_fuzz_native_outcome( $runtime, $sql );
			$sqlite_sql = match ( $sql ) {
				'CREATE TABLE wp_probe (id int unsigned NOT NULL AUTO_INCREMENT, label varchar(20) NOT NULL, PRIMARY KEY (id))' => 'CREATE TABLE wp_probe (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL)',
				"CREATE TABLE wp_probe (id int unsigned NOT NULL AUTO_INCREMENT, name varchar(20) NOT NULL, state varchar(20) NOT NULL DEFAULT 'draft', PRIMARY KEY (id), UNIQUE KEY name (name))" => "CREATE TABLE wp_probe (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, state TEXT NOT NULL DEFAULT 'draft')",
				'START TRANSACTION' => 'BEGIN',
				default => $sql,
			};
			$sqlite = mdi_fuzz_sqlite_outcome( $pdo, $sqlite_sql );
			if ( 'ok' !== $native['status'] || 'ok' !== $sqlite['status'] ) {
				return array( 'passed' => false, 'native' => $native, 'sqlite' => $sqlite );
			}
		}
		if ( 'schema.ddl.introspection' === $operation ) {
			$native = mdi_fuzz_native_outcome( $runtime, 'DESCRIBE wp_probe' );
			return array( 'passed' => 'ok' === $native['status'] && 2 === count( $native['rows'] ), 'native' => $native );
		}
		if ( 'persistence.restart' === $operation ) {
			$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
		}
		if ( 'constraints.defaults-uniqueness-auto-increment' === $operation ) {
			$native = mdi_fuzz_native_outcome( $runtime, 'SELECT id, name, state FROM wp_probe ORDER BY id' );
			$sqlite = mdi_fuzz_sqlite_outcome( $pdo, 'SELECT id, name, state FROM wp_probe ORDER BY id' );
			$native_duplicate = mdi_fuzz_native_outcome( $runtime, "INSERT INTO wp_probe (name) VALUES ('one')" );
			$sqlite_duplicate = mdi_fuzz_sqlite_outcome( $pdo, "INSERT INTO wp_probe (name) VALUES ('one')" );
			$passed = mdi_fuzz_compare( $sqlite, $native, true )['compatible'] && 'error' === $native_duplicate['status'] && 'error' === $sqlite_duplicate['status'];
			return array( 'passed' => $passed, 'native' => $native, 'sqlite' => $sqlite, 'native_duplicate' => $native_duplicate, 'sqlite_duplicate' => $sqlite_duplicate );
		}
		$native = mdi_fuzz_native_outcome( $runtime, 'SELECT id, label FROM wp_probe ORDER BY id' );
		$sqlite = mdi_fuzz_sqlite_outcome( $pdo, 'SELECT id, label FROM wp_probe ORDER BY id' );
		return array( 'passed' => mdi_fuzz_compare( $sqlite, $native, true )['compatible'], 'native' => $native, 'sqlite' => $sqlite );
	} finally {
		mdi_fuzz_remove_fixture( $root );
	}
}

function mdi_fuzz_run( string $seed, array $request = array() ): array {
	$root = '';
	$operations = mdi_fuzz_selected_operations( $request + array( 'operation_ids' => array(), 'families' => array() ) );
	$registry = mdi_fuzz_operation_registry();
	$case_budget = max( 0, (int) ( $request['case_budget'] ?? 0 ) );
	$duration_budget_seconds = max( 0, (float) ( $request['duration_budget_seconds'] ?? 0 ) );
	$report = array(
		'schema' => 'mdi/query-compatibility-differential/v3',
		'seed' => $seed,
		'status' => 'failed',
		'generated_cases' => 0,
		'fixture_rows' => MDI_FUZZ_ROWS,
		'passed_cases' => 0,
		'mismatch_count' => 0,
		'mismatches' => array(),
		'cases' => array(),
		'categories' => array(),
		'warmups' => array(),
		'timing_scope' => 'Measured case executions after one untimed representative warmup per category; fixture and runtime setup are excluded.',
		'unsupported_checks' => array(),
		'native_duration_ms' => 0.0,
		'sqlite_duration_ms' => 0.0,
		'query_set_sha256' => '',
		'replay_command' => 'MDI_FUZZ_SEED=' . escapeshellarg( $seed ) . ' php tests/fuzz-native-sqlite-differential.php',
		'campaign' => array( 'profile' => $request['profile'] ?? null, 'operation_ids' => $operations, 'case_budget' => $case_budget, 'duration_budget_seconds' => $duration_budget_seconds ),
		'case_log' => array(),
		'replay' => array(),
		'coverage' => array( 'declared_operations' => count( $registry ), 'executable_operations' => count( array_filter( $registry, static fn( array $operation ): bool => $operation['executable'] ) ), 'selected_operations' => count( $operations ), 'selected_executable_operations' => count( array_filter( $operations, static fn( string $operation ): bool => $registry[ $operation ]['executable'] ) ), 'proven_operations' => 0, 'skipped_operations' => 0, 'operations' => array() ),
	);

	try {
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			throw new RuntimeException( 'pdo_sqlite is required for the reference runtime.' );
		}
		$campaign_started = hrtime( true );
		[ $root, $fixture, $runtime, $pdo ] = mdi_fuzz_create_runtime_fixture( $seed );
		$cases = mdi_fuzz_cases( $seed, $fixture );
		if ( ! in_array( 'query.read', $operations, true ) ) {
			$cases = array();
		}
		if ( 0 < $case_budget ) {
			$reserved_cases = 0;
			foreach ( $operations as $operation ) {
				if ( 'query.read' === $operation || ! $registry[ $operation ]['executable'] ) {
					continue;
				}
				$reserved_cases += 'unsupported.error' === $operation ? 2 : 1;
			}
			$cases = array_slice( $cases, 0, max( 0, $case_budget - min( $case_budget, $reserved_cases ) ) );
		}
		$executed_cases = 0;
		$query_executed = 0;
		$query_failed = false;
		$report['categories'] = mdi_fuzz_initialize_categories( $cases );
		$report['warmups'] = mdi_fuzz_warmup( $runtime, $pdo, $cases );

		foreach ( $cases as $index => $case ) {
			if ( 0 < $duration_budget_seconds && ( hrtime( true ) - $campaign_started ) / 1_000_000_000 >= $duration_budget_seconds ) {
				$report['duration_budget_exhausted'] = true;
				break;
			}
			$category = $case['category'];
			$started = hrtime( true );
			$native = mdi_fuzz_native_outcome( $runtime, $case['native_sql'] );
			$native_ms = ( hrtime( true ) - $started ) / 1_000_000;
			$started = hrtime( true );
			$sqlite = mdi_fuzz_sqlite_outcome( $pdo, $case['sqlite_sql'] );
			$sqlite_ms = ( hrtime( true ) - $started ) / 1_000_000;

			$report['native_duration_ms'] += $native_ms;
			$report['sqlite_duration_ms'] += $sqlite_ms;
			$report['categories'][ $category ]['native_duration_ms'] += $native_ms;
			$report['categories'][ $category ]['sqlite_duration_ms'] += $sqlite_ms;
			$comparison = mdi_fuzz_compare( $sqlite, $native, $case['ordered'] );
			$passed = $comparison['compatible'];
			$case_id = 'query.read:' . $index;
			$duration_ms = (int) round( $native_ms + $sqlite_ms );
			$report['cases'][] = array(
				'schema' => 'homeboy/fuzz-case/v1',
				'id' => $case_id,
				'target_id' => 'mdi-native-sqlite',
				'operation_id' => 'query.read',
				'workload_id' => 'mdi-native-sqlite-differential',
				'seed_id' => $seed,
				'replay_id' => 'mdi-native-sqlite-replay',
				'case' => $index,
				'category' => $category,
				'native_sql' => $case['native_sql'],
				'sqlite_sql' => $case['sqlite_sql'],
				'ordered' => $case['ordered'],
				'status' => $passed ? 'passed' : 'failed',
				'passed' => $passed,
				'input' => array( 'native_sql' => $case['native_sql'], 'sqlite_sql' => $case['sqlite_sql'], 'ordered' => $case['ordered'] ),
				'expected' => $sqlite,
				'observed' => $native,
				'metadata' => array( 'category' => $category, 'status' => $passed ? 'passed' : 'failed', 'native_duration_ms' => round( $native_ms, 3 ), 'sqlite_duration_ms' => round( $sqlite_ms, 3 ) ),
			);
			$report['case_log'][] = array(
				'schema' => 'homeboy/fuzz-case-log/v1',
				'version' => 1,
				'case_id' => $case_id,
				'target_id' => 'mdi-native-sqlite',
				'operation_id' => 'query.read',
				'operation_family' => 'query',
				'seed' => $seed,
				'input_hash' => hash( 'sha256', json_encode( array( $case['native_sql'], $case['sqlite_sql'], $case['ordered'] ), JSON_THROW_ON_ERROR ) ),
				'status' => $passed ? 'passed' : 'failed',
				'duration_ms' => $duration_ms,
				'failure_fingerprint' => $passed ? null : hash( 'sha256', json_encode( $comparison['mismatches'], JSON_THROW_ON_ERROR ) ),
				'metadata' => array( 'category' => $category ),
			);
			++$executed_cases;
			++$query_executed;
			if ( $passed ) {
				++$report['passed_cases'];
				++$report['categories'][ $category ]['passed'];
				continue;
			}
			$query_failed = true;
			++$report['mismatch_count'];
			++$report['categories'][ $category ]['failed'];
			$report['mismatches'][] = array(
				'case' => $index,
				'category' => $category,
				'native_sql' => $case['native_sql'],
				'sqlite_sql' => $case['sqlite_sql'],
				'ordered' => $case['ordered'],
				'differences' => $comparison['mismatches'],
				'native' => $native,
				'sqlite' => $sqlite,
			);
		}

		foreach ( $operations as $operation ) {
			$definition = $registry[ $operation ];
			$entry = array( 'id' => $operation, 'family' => $definition['family'], 'safety_class' => $definition['safety_class'] );
			if ( 0 < $duration_budget_seconds && ( hrtime( true ) - $campaign_started ) / 1_000_000_000 >= $duration_budget_seconds ) {
				$entry['status'] = 'skipped';
				$entry['reason'] = 'The campaign duration budget was exhausted.';
				$report['duration_budget_exhausted'] = true;
				++$report['coverage']['skipped_operations'];
			} elseif ( ! $definition['executable'] ) {
				$entry['status'] = 'skipped';
				$entry['reason'] = $definition['skip_reason'];
				++$report['coverage']['skipped_operations'];
			} elseif ( 'query.read' === $operation ) {
				$entry['status'] = 0 === $query_executed ? 'skipped' : ( $query_failed ? 'failed' : 'proven' );
				if ( 'skipped' === $entry['status'] ) {
					$entry['reason'] = 'The campaign budget did not permit a query case.';
					++$report['coverage']['skipped_operations'];
				}
				$report['coverage']['proven_operations'] += 'proven' === $entry['status'] ? 1 : 0;
			} elseif ( 0 < $case_budget && $executed_cases + ( 'unsupported.error' === $operation ? 2 : 1 ) > $case_budget ) {
				$entry['status'] = 'skipped';
				$entry['reason'] = 'The campaign case budget was exhausted.';
				++$report['coverage']['skipped_operations'];
			} elseif ( 'unsupported.error' === $operation ) {
				$unsupported_passed = true;
				foreach ( array( "SELECT item_id FROM wp_items WHERE title = 'admiñ'", "SELECT item_id FROM wp_items WHERE title IN 'item_0000000'" ) as $index => $query ) {
					$started = hrtime( true );
					$first = mdi_fuzz_native_outcome( $runtime, $query );
					$second = mdi_fuzz_native_outcome( $runtime, $query );
					$duration_ms = (int) round( ( hrtime( true ) - $started ) / 1_000_000 );
					$stable = 'error' === $first['status'] && $first === $second && '' !== $first['error_code'];
					$case_id = 'unsupported.error:' . $index;
					$report['unsupported_checks'][] = array( 'native_sql' => $query, 'sqlite_sql' => $query, 'stable' => $stable, 'diagnostic' => $first );
					$report['cases'][] = array( 'schema' => 'homeboy/fuzz-case/v1', 'id' => $case_id, 'target_id' => 'mdi-native', 'operation_id' => $operation, 'workload_id' => 'mdi-native-sqlite-differential', 'seed_id' => $seed, 'replay_id' => 'mdi-native-sqlite-replay', 'status' => $stable ? 'passed' : 'failed', 'input' => array( 'native_sql' => $query ), 'expected' => array( 'status' => 'error', 'stable' => true ), 'observed' => array( 'first' => $first, 'second' => $second ), 'metadata' => array( 'status' => $stable ? 'passed' : 'failed' ) );
					$report['case_log'][] = array( 'schema' => 'homeboy/fuzz-case-log/v1', 'version' => 1, 'case_id' => $case_id, 'target_id' => 'mdi-native', 'operation_id' => $operation, 'operation_family' => $definition['family'], 'seed' => $seed, 'input_hash' => hash( 'sha256', $query ), 'status' => $stable ? 'passed' : 'failed', 'duration_ms' => $duration_ms, 'failure_reason' => $stable ? null : 'The unsupported diagnostic was not deterministic.' );
					$unsupported_passed = $unsupported_passed && $stable;
					++$executed_cases;
					$report['passed_cases'] += $stable ? 1 : 0;
					$report['mismatch_count'] += $stable ? 0 : 1;
				}
				$entry['status'] = $unsupported_passed ? 'proven' : 'failed';
				$report['coverage']['proven_operations'] += $unsupported_passed ? 1 : 0;
			} else {
				$started = hrtime( true );
				$outcome = mdi_fuzz_sequence_case( $operation, $seed );
				$duration_ms = (int) round( ( hrtime( true ) - $started ) / 1_000_000 );
				$case_id = $operation . ':0';
				$entry['status'] = $outcome['passed'] ? 'proven' : 'failed';
				$entry['result'] = $outcome;
				$report['cases'][] = array( 'schema' => 'homeboy/fuzz-case/v1', 'id' => $case_id, 'target_id' => 'mdi-native-sqlite', 'operation_id' => $operation, 'workload_id' => 'mdi-native-sqlite-differential', 'seed_id' => $seed, 'replay_id' => 'mdi-native-sqlite-replay', 'status' => $outcome['passed'] ? 'passed' : 'failed', 'input' => array( 'sequence' => $operation ), 'expected' => array( 'compatible' => true ), 'observed' => $outcome, 'metadata' => array( 'status' => $outcome['passed'] ? 'passed' : 'failed' ) );
				$report['case_log'][] = array( 'schema' => 'homeboy/fuzz-case-log/v1', 'version' => 1, 'case_id' => $case_id, 'target_id' => 'mdi-native-sqlite', 'operation_id' => $operation, 'operation_family' => $definition['family'], 'seed' => $seed, 'input_hash' => hash( 'sha256', $seed . ':' . $operation ), 'status' => $outcome['passed'] ? 'passed' : 'failed', 'duration_ms' => $duration_ms, 'failure_reason' => $outcome['passed'] ? null : 'The native and SQLite sequence outcomes differ.' );
				++$executed_cases;
				$report['passed_cases'] += $outcome['passed'] ? 1 : 0;
				if ( $outcome['passed'] ) {
					++$report['coverage']['proven_operations'];
				} else {
					++$report['mismatch_count'];
				}
			}
			if ( 'skipped' === $entry['status'] ) {
				$case_id = $operation . ':skipped';
				$report['cases'][] = array( 'schema' => 'homeboy/fuzz-case/v1', 'id' => $case_id, 'target_id' => 'mdi-native-sqlite', 'operation_id' => $operation, 'workload_id' => 'mdi-native-sqlite-differential', 'seed_id' => $seed, 'replay_id' => 'mdi-native-sqlite-replay', 'status' => 'skipped', 'input' => array(), 'metadata' => array( 'status' => 'skipped', 'skip_reason' => $entry['reason'] ) );
				$report['case_log'][] = array( 'schema' => 'homeboy/fuzz-case-log/v1', 'version' => 1, 'case_id' => $case_id, 'target_id' => 'mdi-native-sqlite', 'operation_id' => $operation, 'operation_family' => $definition['family'], 'seed' => $seed, 'input_hash' => hash( 'sha256', $seed . ':' . $operation . ':skipped' ), 'status' => 'skipped', 'duration_ms' => 0, 'skip_reason' => $entry['reason'] );
			}
			$report['coverage']['operations'][] = $entry;
		}
		$report['generated_cases'] = $executed_cases;

		foreach ( $report['categories'] as &$category ) {
			$category['native_duration_ms'] = round( $category['native_duration_ms'], 3 );
			$category['sqlite_duration_ms'] = round( $category['sqlite_duration_ms'], 3 );
		}
		unset( $category );
		$query_set = array_map(
			static fn( array $case ): array => array( 'native_sql' => $case['native_sql'], 'sqlite_sql' => $case['sqlite_sql'], 'ordered' => $case['ordered'] ),
			$cases
		);
		$report['query_set_sha256'] = hash( 'sha256', json_encode( $query_set, JSON_THROW_ON_ERROR ) );
		$report['native_duration_ms'] = round( $report['native_duration_ms'], 3 );
		$report['sqlite_duration_ms'] = round( $report['sqlite_duration_ms'], 3 );
		$report['status'] = 0 === $report['mismatch_count'] ? 'passed' : 'failed';
		$skipped_operations = array_values( array_map(
			static fn( array $operation ): array => array( 'id' => $operation['id'], 'reason' => $operation['reason'] ),
			array_filter( $report['coverage']['operations'], static fn( array $operation ): bool => 'skipped' === $operation['status'] )
		) );
		$report['coverage_summary'] = array(
			'schema' => 'homeboy/fuzz-coverage-summary/v1',
			'declared_targets' => 1,
			'executable_targets' => 1,
			'proven_targets' => 'passed' === $report['status'] ? 1 : 0,
			'declared_operations' => count( $operations ),
			'executable_operations' => $report['coverage']['selected_executable_operations'],
			'proven_operations' => $report['coverage']['proven_operations'],
			'skipped_targets' => array(),
			'skipped_operations' => $skipped_operations,
			'artifact_ids' => array( 'mdi_native_sqlite_differential', 'case-log', 'replay-data', 'coverage-summary' ),
			'metadata' => array( 'executed_cases' => $executed_cases, 'failed_cases' => $report['mismatch_count'] ),
		);
		$report['replay'] = array(
			'schema' => 'homeboy/fuzz-replay/v1',
			'id' => 'mdi-native-sqlite-replay',
			'command' => 'php',
			'args' => array( 'tests/fuzz-native-sqlite-differential.php' ),
			'env' => array( 'MDI_FUZZ_SEED=' . $seed ),
			'seed' => $seed,
			'artifact_id' => 'mdi_native_sqlite_differential',
			'metadata' => array( 'operation_ids' => $operations, 'case_budget' => $case_budget, 'duration_budget_seconds' => $duration_budget_seconds ),
		);
		$artifact_path = 'files/workload-results/mdi-native-sqlite-differential.json';
		$canonical_artifacts = array();
		foreach ( array( 'mdi_native_sqlite_differential' => 'fuzz_report', 'case-log' => 'case_log', 'replay-data' => 'replay', 'coverage-summary' => 'coverage_summary' ) as $artifact_id => $artifact_kind ) {
			$canonical_artifacts[] = array(
				'schema' => 'homeboy/fuzz-artifact/v1',
				'id' => $artifact_id,
				'kind' => $artifact_kind,
				'artifact' => array( 'schema' => 'homeboy/artifact-contract/v1', 'kind' => $artifact_kind, 'type' => 'json', 'path' => $artifact_path, 'role' => $artifact_kind, 'semantic_key' => 'fuzz.' . str_replace( '-', '_', $artifact_id ) ),
				'metadata' => array( 'embedded_field' => array( 'case-log' => 'case_log', 'replay-data' => 'replay', 'coverage-summary' => 'coverage_summary' )[ $artifact_id ] ?? null ),
			);
		}
		$canonical_campaign = array(
			'schema' => 'homeboy/fuzz-campaign/v1',
			'version' => 1,
			'id' => 'mdi-native-sqlite-' . substr( hash( 'sha256', $seed . ':' . implode( ',', $operations ) ), 0, 12 ),
			'title' => 'MDI native versus SQLite database surface differential',
			'safety_class' => 'isolated_mutation',
			'cases' => $report['cases'],
			'coverage_summary' => $report['coverage_summary'],
			'artifacts' => $canonical_artifacts,
			'replay' => $report['replay'],
			'metadata' => array( 'status' => $report['status'], 'profile' => $request['profile'] ?? null, 'query_set_sha256' => $report['query_set_sha256'] ),
		);
		$execution_request = $request['execution_request'] ?? array();
		if ( array() === $execution_request ) {
			$execution_request = array(
				'schema' => 'homeboy/fuzz-execution-request/v1',
				'version' => 1,
				'id' => 'mdi-native-sqlite-direct',
				'component' => 'markdown-database-integration',
				'workload_id' => 'mdi-native-sqlite-differential',
				'seed' => $seed,
				'sampling' => array( 'schema' => 'homeboy/fuzz-sampling-request/v1', 'strategy' => 'deterministic', 'seed' => $seed, 'case_budget' => 0 < $case_budget ? $case_budget : null, 'duration_budget_seconds' => 0 < $duration_budget_seconds ? $duration_budget_seconds : null, 'operation_strata' => array( array( 'id' => 'selected-operations', 'kind' => 'operation', 'values' => $operations ) ), 'replay' => array( 'deterministic' => true, 'seed_source' => 'caller' ) ),
			);
		}
		$report['homeboy_campaign'] = $canonical_campaign;
		$report['result_envelope'] = array(
			'schema' => 'homeboy/fuzz-result-envelope/v1',
			'version' => 1,
			'id' => $canonical_campaign['id'] . '-result',
			'status' => $report['status'],
			'request' => $execution_request,
			'campaign' => $canonical_campaign,
			'artifacts' => $canonical_artifacts,
			'metadata' => array( 'producer' => 'markdown-database-integration' ),
		);
	} catch ( Throwable $error ) {
		$report['failure'] = array( 'class' => get_class( $error ), 'message' => $error->getMessage() );
	} finally {
		mdi_fuzz_remove_fixture( $root );
	}

	return $report;
}

$workload = static function ( array $input = array() ): array {
	$request = mdi_fuzz_request( $input );
	$report = mdi_fuzz_run( $request['seed'], $request );
	file_put_contents( '/tmp/mdi-native-sqlite-differential.json', json_encode( $report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) );
	return array( 'artifacts' => array( 'mdi_native_sqlite_differential' => $report ) );
};

if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( (string) $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	$artifact = $workload();
	echo json_encode( $artifact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . PHP_EOL;
	exit( 'passed' === $artifact['artifacts']['mdi_native_sqlite_differential']['status'] ? 0 : 1 );
}

return $workload;
