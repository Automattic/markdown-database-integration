<?php
/**
 * ALTER TABLE ADD/MODIFY COLUMN against a generated core table (wp_users)
 * succeeds and backfills already-persisted rows, instead of failing closed.
 *
 * `wp_users` is the one core table whose compiled column set differs between
 * the single-site and multisite catalog variants (`spam`, `deleted`, see
 * WP_Markdown_Native_Schema_Catalog::definitions()). WordPress promotes a
 * single-site install to a network mid-request by running exactly this
 * ALTER TABLE against it: `dbDelta($users_multi_table)` inside
 * `populate_network()` (wp-admin/includes/ms.php), which -- because the
 * table already exists -- resolves to `ALTER TABLE wp_users ADD COLUMN
 * spam ..., ADD COLUMN deleted ...`, one statement per missing column.
 *
 * Before this fix, `WP_Markdown_Native_Schema_Mutation_Runtime::execute_alter()`
 * required a persisted `_schema/<suffix>.sql` file to rewrite and recompile.
 * A generated core table never gets one -- creating it registers the
 * compiled catalog definition directly and returns without ever calling
 * `write_schema()` (see the core-table branch of `execute()`) -- so that
 * ALTER failed closed as `unknown_table`, and `reconcile_rows()`'s existing
 * backfill-on-ADD-COLUMN behavior (already correct for a plugin's own
 * persisted table) never ran for a core table at all.
 *
 * Investigation note, wp-codebox#2500: this ALTER TABLE gap is real and
 * independently verified below, but it does not explain the issue's
 * originally reported 15 mdi-native-only Route_AffinityTest failures on
 * Extra-Chill/extrachill-api. Those already do not reproduce against this
 * repository's `main` (confirmed: 35/35, then the component's full 247/247,
 * passing through wp-codebox's real PHPUnit harness with an explicit
 * `markdown-database-integration` source pointed at an unmodified `main`
 * checkout) -- `main` already carries `materialize_multisite_user_defaults()`
 * (`WP_Markdown_Native_JSON_Snapshot_Provider::rows()`, added in #393,
 * merged 2026-09-10), an unconditional read-side default for `wp_users` rows
 * missing the multisite-only columns, independent of whether any ALTER
 * TABLE ever ran. wp-codebox's bundled `mdi-native` zip is pinned to
 * `0bec3f73f5367b692f7b0ab0acfeff55a77500ff` ("share multisite transaction
 * journals", #400, merged 2026-09-09 14:46) -- an ancestor of, and one day
 * older than, #393 (2026-09-10 19:00). The bundled zip predates the fix;
 * `main` already has it. See the PR description for the isolation test that
 * confirms this ALTER TABLE fix, alone, does not reproduce the reported
 * failures' fix either -- `main`'s real bootstrap apparently never reaches
 * this ALTER TABLE path for `wp_users` at all, so `wp_insert_user()`'s own
 * INSERT working correctly rests entirely on the read-side default, not on
 * schema reconciliation. This fix stands on its own merit: an ALTER TABLE
 * that WordPress core genuinely issues against a generated core table
 * should not fail closed and silently skip reconciling existing rows.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-multisite-core-reconcile-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0755, true );
mkdir( $root . '/_tables', 0755, true );

// The exact shape `wp_install()` persists while WordPress is still
// single-site: no `spam`/`deleted` columns.
$seed_admin = array(
	'ID'                  => '1',
	'user_login'          => 'admin',
	'user_pass'           => 'hash',
	'user_nicename'       => 'admin',
	'user_email'          => 'admin@example.test',
	'user_url'            => 'http://localhost',
	'user_registered'     => '2026-01-01 00:00:00',
	'user_activation_key' => '',
	'user_status'         => '0',
	'display_name'        => 'admin',
);
file_put_contents( $root . '/_tables/users.json', json_encode( array( $seed_admin ), JSON_THROW_ON_ERROR ) );

// A fresh runtime for the network's base ("wp_") prefix always resolves
// wp_users's compiled schema as multisite -- exactly what every query sees
// once WordPress has promoted the install to a network.
$runtime = WP_Markdown_Native_Runtime_Factory::multisite_runtime( $root, 'wp_' );

// dbDelta issues one ALTER TABLE ADD COLUMN statement per missing column,
// not a combined multi-action ALTER -- mirror that exactly.
$add_spam = $runtime->execute( new WP_Markdown_Query_Request( "ALTER TABLE wp_users ADD COLUMN spam tinyint(2) NOT NULL default '0'", 'wp_' ) );
$add_deleted = $runtime->execute( new WP_Markdown_Query_Request( "ALTER TABLE wp_users ADD COLUMN deleted tinyint(2) NOT NULL default '0'", 'wp_' ) );

$select_after_alter = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID, user_login, user_status, spam, deleted FROM wp_users WHERE ID = 1', 'wp_' ) );

// wp_insert_user()'s own INSERT shape (no spam/deleted -- WordPress never
// sets those on create; they default) against the now-reconciled table.
$insert_after_alter = $runtime->execute(
	new WP_Markdown_Query_Request(
		"INSERT INTO `wp_users` (`user_login`, `user_pass`, `user_nicename`, `user_email`, `user_url`, `user_registered`, `user_activation_key`, `user_status`, `display_name`) VALUES ('probeuser', 'password', 'probeuser', 'probeuser@example.test', '', '2026-01-01 00:00:02', '', 0, 'probeuser')",
		'wp_'
	)
);
$inserted_row = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID, spam, deleted FROM wp_users WHERE user_login = 'probeuser'", 'wp_' ) );

// A same-shape MODIFY against a core table is a no-op that still succeeds
// and leaves rows untouched.
$modify_noop = $runtime->execute( new WP_Markdown_Query_Request( "ALTER TABLE wp_users MODIFY display_name VARCHAR(250) NOT NULL DEFAULT ''", 'wp_' ) );
$after_modify = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID, spam, deleted FROM wp_users WHERE ID = 1', 'wp_' ) );

// DROP against a core table is unsupported (re-registering from the catalog
// cannot shrink the compiled shape back down for a runtime whose
// is_multisite() state is fixed for its lifetime) and fails closed rather
// than silently mishandling it.
$drop_rejected = $runtime->execute( new WP_Markdown_Query_Request( 'ALTER TABLE wp_users DROP COLUMN spam', 'wp_' ) );

// An unpersisted, non-core table is still rejected exactly as before --
// this fix must not widen what "alterable" means beyond generated core
// tables and a plugin's own persisted tables.
$unknown_table = $runtime->execute( new WP_Markdown_Query_Request( 'ALTER TABLE wp_absent ADD COLUMN note VARCHAR(10) NULL', 'wp_' ) );

$after_row = $select_after_alter->succeeded() ? ( $select_after_alter->wpdb_state()['last_result'][0] ?? null ) : null;
$inserted = $inserted_row->succeeded() ? ( $inserted_row->wpdb_state()['last_result'][0] ?? null ) : null;
$after_modify_row = $after_modify->succeeded() ? ( $after_modify->wpdb_state()['last_result'][0] ?? null ) : null;

$checks = array(
	'ALTER TABLE ADD COLUMN succeeds against a generated core table' => $add_spam->succeeded() && $add_deleted->succeeded(),
	'ADD COLUMN backfills the pre-existing row with the new columns\' default, like MySQL' => null !== $after_row
		&& '1' === $after_row->ID
		&& 'admin' === $after_row->user_login
		&& '0' === $after_row->spam
		&& '0' === $after_row->deleted,
	'a fresh wp_insert_user()-shaped INSERT succeeds against the reconciled table and returns a nonzero ID' => $insert_after_alter->succeeded()
		&& 0 !== (int) $insert_after_alter->wpdb_state()['insert_id'],
	'the newly inserted row is immediately selectable with the reconciled columns defaulted' => null !== $inserted
		&& '0' === $inserted->spam
		&& '0' === $inserted->deleted,
	'a same-shape MODIFY against a core table is a no-op success' => $modify_noop->succeeded()
		&& null !== $after_modify_row
		&& '0' === $after_modify_row->spam
		&& '0' === $after_modify_row->deleted,
	'DROP against a core table fails closed rather than silently mishandling it' => ! $drop_rejected->succeeded(),
	'altering an unpersisted, non-core table still fails closed as unknown_table' => ! $unknown_table->succeeded()
		&& 'unknown_table' === ( $unknown_table->diagnostic()['reason'] ?? null ),
);

$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	$failed += $passed ? 0 : 1;
}

function mdi_native_multisite_core_reconcile_remove( string $path ): void {
	foreach ( scandir( $path ) ?: array() as $child ) {
		if ( '.' === $child || '..' === $child ) {
			continue;
		}
		$child = $path . '/' . $child;
		is_dir( $child ) ? mdi_native_multisite_core_reconcile_remove( $child ) : unlink( $child );
	}
	rmdir( $path );
}
mdi_native_multisite_core_reconcile_remove( $root );
exit( $failed ? 1 : 0 );
