<?php
/** Verify dbDelta schema round trips through the native wpdb backend. */

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

global $wpdb;

$table = $wpdb->prefix . 'mdi_dbdelta_events';
$schema = "CREATE TABLE {$table} (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	post_id BIGINT(20) UNSIGNED NOT NULL,
	start_datetime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	end_datetime DATETIME NULL,
	post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
	post_slug VARCHAR(255) NOT NULL DEFAULT '',
	PRIMARY KEY  (id),
	UNIQUE KEY post_id (post_id),
	KEY start_datetime (start_datetime),
	KEY status_start (post_status, start_datetime)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
$migrated_schema = str_replace(
	"\tpost_status VARCHAR(20) NOT NULL DEFAULT 'publish',",
	"\tpost_status VARCHAR(30) NOT NULL DEFAULT 'publish',\n\tmigration_version BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,",
	$schema
);

$first = dbDelta( $schema );
$second = dbDelta( $schema );
$inserted = $wpdb->insert(
	$table,
	array(
		'post_id'        => 7,
		'start_datetime' => '2026-01-01 00:00:00',
		'end_datetime'   => null,
		'post_status'    => 'publish',
		'post_slug'      => 'fixture',
	)
);
$add_key = $wpdb->query( "ALTER TABLE {$table} ADD KEY status_slug (post_status, post_slug(191))" );
$sub_part = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'status_slug'" );
$drop_key = $wpdb->query( "ALTER TABLE {$table} DROP INDEX status_slug" );
$dropped = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'status_slug'" );
$migration = dbDelta( $migrated_schema );
$row_after_migration = $wpdb->get_row( "SELECT id, post_id, post_status, migration_version FROM {$table} WHERE post_id = 7" );
$after_migration = dbDelta( $migrated_schema );

$checks = array(
	'first dbDelta creates the table' => array() !== $first,
	'second dbDelta is a no-op' => array() === $second,
	'fixture row is inserted before schema mutation' => 1 === $inserted,
	'ALTER ADD KEY accepts a composite sub-part index' => false !== $add_key
		&& array( null, '191' ) === array_map( static fn( object $row ): ?string => $row->Sub_part, $sub_part ),
	'ALTER DROP INDEX removes the key' => false !== $drop_key && array() === $dropped,
	'dbDelta applies CHANGE and ADD COLUMN migrations' => array() !== $migration,
	'dbDelta preserves rows and materializes an ADD COLUMN default' => is_object( $row_after_migration )
		&& '7' === (string) $row_after_migration->post_id
		&& 'publish' === $row_after_migration->post_status
		&& '1' === (string) $row_after_migration->migration_version,
	'dbDelta is a no-op after the migration' => array() === $after_migration,
);

$failed = array_keys( array_filter( $checks, static fn( bool $passed ): bool => ! $passed ) );
fwrite(
	STDOUT,
	json_encode(
		array(
			'schema' => 'mdi-native-dbdelta-probe/v1',
			'passed' => array() === $failed,
			'checks' => $checks,
			'failed' => $failed,
		),
		JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
	) . "\n"
);

exit( array() === $failed ? 0 : 1 );
