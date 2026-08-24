<?php
/** Generic bounded provider access contract. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/class-wp-markdown-native-query-runtime.php';

final class MDI_Bounded_Iterable_Provider implements WP_Markdown_Native_Table_Provider {
	public ?WP_Markdown_Native_Table_Access $observed = null;

	public function read( WP_Markdown_Native_Table_Access $access ): iterable|WP_Markdown_Query_Result {
		$this->observed = $access;
		return ( static function (): Generator {
			yield array( 'label' => 'first' );
		} )();
	}
}

$schema = new WP_Markdown_Native_Table_Schema(
	array(
		'row_id' => new WP_Markdown_Native_Column(
			8,
			false,
			static fn( mixed $value ): bool => is_int( $value ),
			null,
			array( 'IN' )
		),
		'label'  => new WP_Markdown_Native_Column( 253, false, 'is_string' ),
	),
	'row_id'
);
$provider = new MDI_Bounded_Iterable_Provider();
$registry = new WP_Markdown_Native_Table_Registry();
$registry->register( 'wp_rows', $schema, $provider );
$runtime = new WP_Markdown_Native_Query_Runtime( $registry );
$result = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT label FROM wp_rows WHERE row_id IN (2, 1) ORDER BY row_id ASC LIMIT 1' )
);
$access = $provider->observed;

$checks = array(
	'provider receives one typed bounded access contract' => $access instanceof WP_Markdown_Native_Table_Access
		&& array( 'label' ) === $access->projection()
		&& 'row_id' === $access->order()
		&& 1 === $access->limit(),
	'provider receives the typed predicate unchanged' => $access?->predicate() instanceof WP_Markdown_Native_Query_Predicate
		&& 'row_id' === $access->predicate()->column()
		&& 'IN' === $access->predicate()->operator()
		&& array( 2, 1 ) === $access->predicate()->values(),
	'iterable projected rows retain the public result shape' => 1 === $result->return_value()
		&& 'first' === ( $result->wpdb_state()['last_result'][0]->label ?? null )
		&& array( 'label' ) === array_map(
			static fn( object $column ): string => $column->name,
			$result->wpdb_state()['col_info']
		),
);

$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $passed ) {
		++$failed;
	}
}
exit( $failed ? 1 : 0 );
