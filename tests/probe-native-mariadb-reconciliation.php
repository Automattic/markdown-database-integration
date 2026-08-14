<?php
/**
 * Real WordPress/MariaDB reconciliation probe.
 *
 * Run only in a disposable WordPress runtime with this plugin active:
 * wp eval-file wp-content/plugins/markdown-database-integration/tests/probe-native-mariadb-reconciliation.php
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) || ! isset( $GLOBALS['wpdb'] ) ) {
	fwrite( STDERR, "This probe requires a booted disposable WordPress runtime.\n" );
	exit( 1 );
}

function mdi_native_probe_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( "FAIL: $message" );
	}
	echo "PASS: $message\n";
}

final class MDI_Native_MariaDB_Reconciliation_Probe_Adapter implements WP_Markdown_Reconciliation_Content_Adapter {
	public int $mutations = 0;

	public function __construct( private string $root, private string $table, private object $wpdb ) {}

	public function enumerate( array $scope, ?string $continuation, int $limit ): array {
		unset( $scope, $continuation, $limit );
		$value = $this->wpdb->get_var( "SELECT value FROM {$this->table} WHERE resource_id = 'apply'" );
		return array(
			'source_identity' => hash( 'sha256', 'native-mariadb-probe-v1' ),
			'snapshots'       => array(
				array(
					'resource_id'            => 'apply',
					'resource_type'          => 'post',
					'canonical_path'         => 'post/apply.md',
					'expected_canonical_path'=> 'post/apply.md',
					'canonical'              => 'canonical-value',
					'wordpress'              => false === $value || null === $value ? null : $value,
					'baseline'               => null,
				),
			),
			'continuation' => null,
		);
	}

	public function adapter_for( array $operation, ?array $plan_entry = null ): WP_Markdown_Reconciliation_Adapter {
		unset( $plan_entry );
		$observe = function ( array $record ): array {
			$id = (string) $record['binding']['resource']['id'];
			$value = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT value FROM {$this->table} WHERE resource_id = %s", $id ) );
			return array( 'canonical' => 'canonical-value', 'wordpress' => false === $value || null === $value ? null : $value );
		};
		$mutate = function ( array $record ): void {
			++$this->mutations;
			$id = (string) $record['binding']['resource']['id'];
			if ( false === $this->wpdb->query( $this->wpdb->prepare( "INSERT INTO {$this->table} (resource_id, value) VALUES (%s, %s) ON DUPLICATE KEY UPDATE value = VALUES(value)", $id, 'canonical-value' ) ) ) {
				throw new RuntimeException( 'Probe mutation failed.' );
			}
		};
		return new WP_Markdown_WPDB_Reconciliation_Adapter( $this->wpdb, $observe, $mutate );
	}
}

try {
	global $wpdb;
	mdi_native_probe_check( $wpdb->dbh instanceof mysqli, '$wpdb->dbh is mysqli' );
	$server = (string) $wpdb->get_var( 'SELECT VERSION()' );
	mdi_native_probe_check( str_contains( $server, 'MariaDB' ), "server identity is MariaDB ($server)" );

	$selected = ( new WP_Markdown_WordPress_Reconciliation_Adapter() )->adapter_for(
		array(
			'canonical_root' => sys_get_temp_dir(),
			'resource'       => array( 'type' => 'post', 'id' => 'selection' ),
			'direction'      => 'canonical_to_wordpress',
			'kind'           => 'created',
			'continuation'   => array(),
		)
	);
	mdi_native_probe_check( $selected instanceof WP_Markdown_WPDB_Reconciliation_Adapter, 'mysqli WordPress runtime selects the WPDB ownership adapter for canonical-to-WordPress operations' );

	$table = $wpdb->prefix . 'mdi_reconciliation_probe_' . bin2hex( random_bytes( 8 ) );
	$fence_table = $wpdb->prefix . 'mdi_resource_fences';
	$table_created = false;
	mdi_native_probe_check( false !== $wpdb->query( "CREATE TABLE {$table} (resource_id VARCHAR(64) PRIMARY KEY, value VARCHAR(191) NOT NULL) ENGINE=InnoDB" ), 'created a uniquely named disposable probe table' );
	$table_created = true;
	$root = sys_get_temp_dir() . '/mdi-native-mariadb-probe-' . bin2hex( random_bytes( 8 ) );
	mkdir( $root, 0700 );
	mkdir( $root . '/canonical', 0700 );
	$store = new WP_Markdown_Filesystem_Reconciliation_Operation_Store( $root . '/journal', bin2hex( random_bytes( 32 ) ), array( $root . '/canonical' ) );
	$adapter = new MDI_Native_MariaDB_Reconciliation_Probe_Adapter( $root . '/canonical', $table, $wpdb );
	$service = new WP_Markdown_Reconciliation_Service( new WP_Markdown_Durable_Reconciliation_Coordinator( $store, 'native-mariadb-probe', 1 ), $adapter );
	$request = array( 'canonical_root' => $root . '/canonical', 'managed_scope' => array( 'post' ), 'direction' => 'canonical_to_wordpress', 'deletion_policy' => 'none', 'conflict_policy' => 'none', 'batch_size' => 1 );
	$plan = $service->plan( $request );
	mdi_native_probe_check( 1 === $plan['counts']['created'] && 0 === $adapter->mutations && null === $wpdb->get_var( "SELECT value FROM {$table} WHERE resource_id = 'apply'" ), 'wpdb adapter dry-run plans without a probe-table mutation' );
	$applied = $service->apply( $request + array( 'plan_id' => $plan['plan_id'], 'source_identity' => $plan['source_identity'] ) );
	$apply_operation_id = $applied['operation_ids'][0] ?? '';
	$apply_fence_key = substr( hash( 'sha256', $root . '/canonical' ), 0, 16 ) . ':post:apply';
	$apply_fence = $wpdb->get_row( $wpdb->prepare( "SELECT operation_id, fence FROM `{$fence_table}` WHERE resource_key = %s", $apply_fence_key ), ARRAY_A );
	mdi_native_probe_check( 1 === $adapter->mutations && 'canonical-value' === $wpdb->get_var( "SELECT value FROM {$table} WHERE resource_id = 'apply'" ) && 1 === count( $applied['operation_ids'] ) && is_array( $apply_fence ) && $apply_operation_id === $apply_fence['operation_id'] && 0 < (int) $apply_fence['fence'], 'wpdb adapter writes and verifies its MariaDB fence before committing the probe mutation' );
	$repeated = $service->apply( $request + array( 'plan_id' => $plan['plan_id'], 'source_identity' => $plan['source_identity'] ) );
	mdi_native_probe_check( 1 === $adapter->mutations && 0 === count( $repeated['operation_ids'] ), 'wpdb adapter repeat apply is idempotent' );

	$intent = array( 'plan_id' => hash( 'sha256', 'recovery-plan' ), 'continuation' => array( 'service_schema' => 1, 'source_identity' => hash( 'sha256', 'recovery-source' ), 'cursor' => null, 'resource_id' => 'recovery', 'canonical_path' => 'post/recovery.md', 'expected_canonical_path' => 'post/recovery.md', 'layout_profile' => '' ), 'canonical_root' => $root . '/canonical', 'resource' => array( 'type' => 'post', 'id' => 'recovery' ), 'kind' => 'created', 'direction' => 'canonical_to_wordpress', 'before' => array( 'canonical' => WP_Markdown_Reconciliation_Identity::exact( 'canonical-value' ), 'wordpress' => WP_Markdown_Reconciliation_Identity::exact( null ) ), 'after' => array( 'canonical' => WP_Markdown_Reconciliation_Identity::exact( 'canonical-value' ), 'wordpress' => WP_Markdown_Reconciliation_Identity::exact( 'canonical-value' ) ) );
	$interrupted = $store->plan( $intent );
	$claimed = $store->claim( $interrupted['id'], $interrupted['revision'], 'crashed-probe', time() - 2, 1 );
	$interrupted_adapter = $adapter->adapter_for( $claimed );
	$interrupted_adapter->fence( $claimed );
	$interrupted_adapter->apply( $claimed );
	$mutations_after_effect = $adapter->mutations;
	$recovered = ( new WP_Markdown_Durable_Reconciliation_Coordinator( $store, 'recovery-probe', 1 ) )->recover( $interrupted['id'], $adapter->adapter_for( $claimed ) );
	$recovery_fence_key = substr( hash( 'sha256', $root . '/canonical' ), 0, 16 ) . ':post:recovery';
	$recovery_fence = $wpdb->get_row( $wpdb->prepare( "SELECT operation_id, fence FROM `{$fence_table}` WHERE resource_key = %s", $recovery_fence_key ), ARRAY_A );
	mdi_native_probe_check( 'completed' === $recovered['state'] && $mutations_after_effect === $adapter->mutations && 'canonical-value' === $wpdb->get_var( "SELECT value FROM {$table} WHERE resource_id = 'recovery'" ) && is_array( $recovery_fence ) && $recovered['id'] === $recovery_fence['operation_id'] && (int) $recovered['fence'] === (int) $recovery_fence['fence'] && (int) $claimed['fence'] < (int) $recovered['fence'], 'wpdb adapter recovery re-fences and recognizes the real MariaDB effect without replay' );
} finally {
	if ( isset( $wpdb, $apply_fence_key, $apply_operation_id ) ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$fence_table}` WHERE resource_key = %s AND operation_id = %s", $apply_fence_key, $apply_operation_id ) );
		mdi_native_probe_check( null === $wpdb->get_var( $wpdb->prepare( "SELECT operation_id FROM `{$fence_table}` WHERE resource_key = %s AND operation_id = %s", $apply_fence_key, $apply_operation_id ) ), 'removed the probe apply fence row owned by this process' );
	}
	if ( isset( $wpdb, $recovery_fence_key, $recovered ) ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$fence_table}` WHERE resource_key = %s AND operation_id = %s", $recovery_fence_key, $recovered['id'] ) );
		mdi_native_probe_check( null === $wpdb->get_var( $wpdb->prepare( "SELECT operation_id FROM `{$fence_table}` WHERE resource_key = %s AND operation_id = %s", $recovery_fence_key, $recovered['id'] ) ), 'removed the probe recovery fence row owned by this process' );
	}
	if ( isset( $wpdb, $table, $table_created ) && $table_created ) {
		$wpdb->query( "DROP TABLE {$table}" );
		mdi_native_probe_check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'removed the uniquely named probe table created by this process' );
	}
	if ( isset( $root ) && is_dir( $root ) ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $path ) {
			$path->isDir() ? rmdir( $path->getPathname() ) : unlink( $path->getPathname() );
		}
		rmdir( $root );
	}
}
