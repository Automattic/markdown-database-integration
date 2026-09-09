<?php
/** Native wp_posts writes by ID reuse storage's indexed canonical path. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

final class MDI_ID_Write_Storage extends WP_Markdown_Storage {
	public int $manifest_traversals = 0;

	public function get_markdown_file_manifest_iterator( bool $strict = false, ?array $post_types = null ): Generator {
		++$this->manifest_traversals;
		yield from parent::get_markdown_file_manifest_iterator( $strict, $post_types );
	}
}

function mdi_id_write_post( string $root, int $id, string $title ): void {
	file_put_contents( $root . '/post/post-' . $id . '.md', "---\nid: {$id}\ntitle: {$title}\nstatus: publish\ntype: post\nauthor: 1\ndate: 2026-01-01 00:00:00\nmodified: 2026-01-01 00:00:00\nslug: post-{$id}\ncomment_status: open\nping_status: open\n---\n\nBody\n" );
}

$root = sys_get_temp_dir() . '/mdi-native-id-write-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/post', 0755, true );
mkdir( $root . '/_options', 0755, true );
mdi_id_write_post( $root, 1, 'First' );
mdi_id_write_post( $root, 2, 'Second' );

$storage = new MDI_ID_Write_Storage( $root );
$storage->read_post( 1 ); // Populate the maintained ID-to-path index once.
$before_writes = $storage->manifest_traversals;
$schema = WP_Markdown_Native_Runtime_Factory::posts_schema();
$registry = new WP_Markdown_Native_Table_Registry();
$provider = new WP_Markdown_Native_Post_Provider( $root, $schema, $storage );
$registry->register( 'wp_posts', $schema, $provider );
$runtime = new WP_Markdown_Native_Query_Runtime(
	$registry,
	new WP_Markdown_Native_Query_Parser(),
	null,
	null,
	null,
	null,
	new WP_Markdown_Native_Post_Mutation_Runtime( $registry, new WP_Markdown_Native_Table_Insert_Parser(), $storage )
);

$first_update = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_posts SET post_title = 'First updated' WHERE ID = 1" ) );
$second_update = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_posts SET post_title = 'Second updated' WHERE ID IN (2)" ) );
$delete = $runtime->execute( new WP_Markdown_Query_Request( 'DELETE FROM wp_posts WHERE ID = 1' ) );
$after_id_writes = $storage->manifest_traversals;
$arbitrary = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_posts SET post_status = 'draft' WHERE post_title = 'Second updated'" ) );
$after_arbitrary = $storage->manifest_traversals;

$checks = array(
	'repeated ID UPDATE and DELETE use the populated storage index without a manifest traversal' => 1 === $first_update->return_value()
		&& 1 === $second_update->return_value()
		&& 1 === $delete->return_value()
		&& $before_writes === $after_id_writes,
	'arbitrary write predicates retain the complete manifest scan' => 1 === $arbitrary->return_value()
		&& $after_id_writes < $after_arbitrary,
);

foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
}

array_map( 'unlink', glob( $root . '/post/*' ) ?: array() );
@rmdir( $root . '/post' );
@rmdir( $root . '/_options' );
@rmdir( $root );
exit( in_array( false, $checks, true ) ? 1 : 0 );
