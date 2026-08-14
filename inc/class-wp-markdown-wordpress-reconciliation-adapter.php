<?php
/** WordPress and canonical markdown reconciliation content adapter. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-markdown-reconciliation-service.php';
if ( ! class_exists( 'WP_Markdown_Storage' ) ) {
	require_once __DIR__ . '/class-wp-markdown-storage.php';
}

final class WP_Markdown_WordPress_Reconciliation_Adapter implements WP_Markdown_Reconciliation_Content_Adapter {
	private const SCHEMA_VERSION = 1;
	private const SOURCE_PATH_META = '_markdown_source_path';
	private const SOURCE_IDENTITY_META = '_markdown_source_identity';
	private const SOURCE_HASH_META = '_markdown_source_hash';
	private const BASELINE_META = '_markdown_reconciliation_baseline';
	private const BASELINE_OPTION_PREFIX = '_markdown_reconciliation_baselines_';

	private ?WP_Markdown_Storage $storage;
	private $owning_adapter_factory;
	private $mutation_authorizer;

	/**
	 * @param callable|null $owning_adapter_factory Receives direction, operation,
	 * observer, mutation, and context, and returns an ownership adapter.
	 */
	public function __construct( ?WP_Markdown_Storage $storage = null, ?callable $owning_adapter_factory = null, ?callable $mutation_authorizer = null ) {
		$this->storage                = $storage;
		$this->owning_adapter_factory = $owning_adapter_factory;
		$this->mutation_authorizer    = $mutation_authorizer;
	}

	public function enumerate( array $scope, ?string $continuation, int $limit ): array {
		$root  = $this->root( $scope['canonical_root'] ?? null );
		$types = $this->types( $scope['managed_scope'] ?? null );
		$layout_profile = is_string( $scope['layout_profile'] ?? null ) ? $scope['layout_profile'] : '';
		$state = $this->complete_state( $root, $types, $layout_profile );
		if ( ! empty( $scope['resource_ids'] ) ) {
			$wanted = array_fill_keys( $scope['resource_ids'], true );
			$pending = array_keys( $wanted );
			while ( $pending ) {
				$key = array_pop( $pending );
				$parent = (int) ( $state[ $key ]['snapshot']['canonical']['post_parent'] ?? 0 );
				$parent_key = sprintf( 'post:%020d', $parent );
				if ( $parent > 0 && isset( $state[ $parent_key ] ) && ! isset( $wanted[ $parent_key ] ) ) { $wanted[ $parent_key ] = true; $pending[] = $parent_key; }
			}
			$state = array_intersect_key( $state, $wanted );
		}
		$keys  = array_keys( $state );
		sort( $keys, SORT_STRING );

		$offset = 0;
		if ( null !== $continuation ) {
			list( $last, $remaining_identity ) = $this->parse_continuation( $continuation );
			while ( $offset < count( $keys ) && strcmp( $keys[ $offset ], $last ) <= 0 ) {
				++$offset;
			}
			if ( ! hash_equals( $remaining_identity, $this->source_identity( $state, array_slice( $keys, $offset ) ) ) ) {
				throw new WP_Markdown_Reconciliation_Store_Conflict( 'The unprocessed reconciliation source changed.' );
			}
		}

		$page_keys = array_slice( $keys, $offset, $limit );
		$snapshots = array();
		foreach ( $page_keys as $key ) {
			$snapshots[] = $state[ $key ]['snapshot'];
		}

		return array(
			'source_identity' => $this->source_identity( $state, $keys ),
			'snapshots'       => $snapshots,
			'continuation'    => $offset + count( $page_keys ) < count( $keys )
				? 'v2:' . rawurlencode( end( $page_keys ) ) . ':' . $this->source_identity( $state, array_slice( $keys, $offset + count( $page_keys ) ) )
				: null,
		);
	}

	/** @return array{string,string} */
	private function parse_continuation( string $continuation ): array {
		if ( ! preg_match( '/^v2:((?:[A-Za-z0-9._~-]|%[A-F0-9]{2})+):([a-f0-9]{64})$/', $continuation, $match ) ) {
			throw new InvalidArgumentException( 'The reconciliation continuation is invalid.' );
		}
		$last = rawurldecode( $match[1] );
		if ( rawurlencode( $last ) !== $match[1] ) {
			throw new InvalidArgumentException( 'The reconciliation continuation is invalid.' );
		}
		return array( $last, $match[2] );
	}

	/** @param array<string,array{snapshot:array,source:array}> $state @param string[] $keys */
	private function source_identity( array $state, array $keys ): string {
		$source = array();
		foreach ( $keys as $key ) {
			$source[ $key ] = $state[ $key ]['source'];
		}
		return hash( 'sha256', WP_Markdown_Reconciliation_Identity::encode( $source ) );
	}

	public function adapter_for( array $operation, ?array $plan_entry = null ): WP_Markdown_Reconciliation_Adapter {
		$binding = isset( $operation['binding'] ) && is_array( $operation['binding'] ) ? $operation['binding'] : $operation;
		$root    = $this->root( $binding['canonical_root'] ?? null );
		$context = $this->effect_context( $binding, $plan_entry, $root );
		$observe = fn( array $record ): array => $this->observe_effect( $record, $context );
		$mutate  = function ( array $record ) use ( $context ): void {
			$this->mutate_effect( $record, $context );
		};

		if ( null !== $this->owning_adapter_factory ) {
			$adapter = call_user_func( $this->owning_adapter_factory, $binding['direction'], $operation, $observe, $mutate, $context );
			if ( ! $adapter instanceof WP_Markdown_Reconciliation_Adapter ) {
				throw new UnexpectedValueException( 'The owning adapter factory returned an invalid adapter.' );
			}
			return $adapter;
		}

		if ( 'wordpress_to_canonical' === $binding['direction'] ) {
			return new WP_Markdown_Filesystem_Reconciliation_Adapter( $this->fence_directory( $root ), $observe, $mutate );
		}
		if ( 'canonical_to_wordpress' !== $binding['direction'] ) {
			throw new InvalidArgumentException( 'The reconciliation direction is invalid.' );
		}
		$pdo = $this->pdo();
		if ( null !== $pdo ) {
			return new WP_Markdown_PDO_Reconciliation_Adapter( $pdo, $observe, $mutate );
		}
		global $wpdb;
		return new WP_Markdown_WPDB_Reconciliation_Adapter( $wpdb, $observe, $mutate );
	}

	/** @return array<string,array{snapshot:array,source:array}> */
	private function complete_state( string $root, array $types, string $layout_profile ): array {
		$storage = $this->storage( $root, $layout_profile );
		$baselines = $this->baseline_registry( $root );
		$files   = array();
		$by_id   = array();
		$by_source_identity = array();
		foreach ( $storage->get_markdown_file_manifest_iterator() as $relative => $info ) {
			$relative = $this->relative_path( (string) $relative );
			if ( null === $relative ) {
				continue;
			}
			$post = $storage->read_file( $info['absolute'], false, $info['parent_id'] );
			if ( ! is_object( $post ) || ! in_array( (string) ( $post->post_type ?? 'post' ), $types, true ) ) {
				continue;
			}
			$id = (int) ( $post->ID ?? 0 );
			if ( $id > 0 && isset( $by_id[ $id ] ) ) {
				throw new UnexpectedValueException( 'Canonical markdown contains duplicate managed post IDs.' );
			}
			$record = array(
				'post'    => $post,
				'path'    => $relative,
				'receipt' => $this->post_receipt( $post, (array) ( $post->_frontmatter_meta ?? array() ), (array) ( $post->_frontmatter_terms ?? array() ) ),
				'hash'    => hash_file( 'sha256', $info['absolute'] ),
			);
			$files[ $relative ] = $record;
			$source_identity = (string) ( $post->_source_identity ?? $relative );
			if ( isset( $by_source_identity[ $source_identity ] ) ) { throw new UnexpectedValueException( 'Canonical markdown contains duplicate managed source identities.' ); }
			$by_source_identity[ $source_identity ] = $relative;
			if ( $id > 0 ) {
				$by_id[ $id ] = $relative;
			}
		}

		$wp_posts = $this->wordpress_posts( $types );
		$used_files = array();
		$state = array();
		foreach ( $wp_posts as $post ) {
			$id = (int) $post->ID;
			$source_path = $this->relative_path( (string) get_post_meta( $id, self::SOURCE_PATH_META, true ) );
			$source_identity = $this->relative_path( (string) get_post_meta( $id, self::SOURCE_IDENTITY_META, true ) );
			$file_path = null !== $source_identity && isset( $by_source_identity[ $source_identity ] ) ? $by_source_identity[ $source_identity ] : ( null !== $source_path && isset( $files[ $source_path ] ) ? $source_path : ( $by_id[ $id ] ?? null ) );
			$file = null !== $file_path ? $files[ $file_path ] : null;
			if ( null !== $file_path ) {
				$used_files[ $file_path ] = true;
			}
			$expected = $this->expected_path( $post, $source_path, $file_path, $wp_posts, $storage, $layout_profile, $root );
			$key = sprintf( 'post:%020d', $id );
			$state[ $key ] = $this->state_entry( $key, $root, $file, $post, $file_path, $expected, $baselines[ $key ] ?? null );
		}
		foreach ( $files as $path => $file ) {
			if ( isset( $used_files[ $path ] ) ) {
				continue;
			}
			$id  = (int) ( $file['post']->ID ?? 0 );
			$key = $id > 0 ? sprintf( 'post:%020d', $id ) : 'path:' . $path;
			if ( isset( $state[ $key ] ) ) {
				throw new UnexpectedValueException( 'A canonical file resolves to a duplicate reconciliation resource.' );
			}
			$state[ $key ] = $this->state_entry( $key, $root, $file, null, $path, $path, $baselines[ $key ] ?? null );
		}
		ksort( $state, SORT_STRING );
		return $state;
	}

	private function state_entry( string $key, string $root, ?array $file, ?object $wp_post, ?string $path, ?string $expected, ?array $registered_baseline ): array {
		$wp_receipt = null === $wp_post ? null : $this->wordpress_receipt( (int) $wp_post->ID, $wp_post );
		$canonical  = $file['receipt'] ?? null;
		$baseline   = null === $wp_post ? $registered_baseline : ( $this->baseline( (int) $wp_post->ID, $root ) ?? $registered_baseline );
		$post_id    = null === $wp_post ? (int) ( $file['post']->ID ?? 0 ) : (int) $wp_post->ID;
		$management_before = $this->management_state( $post_id, $root, $key );
		$wp_source_identity = $post_id > 0 ? $this->relative_path( (string) get_post_meta( $post_id, self::SOURCE_IDENTITY_META, true ) ) : null;
		$canonical_source_identity = null === $file ? null : $this->relative_path( (string) ( $file['post']->_source_identity ?? $path ) );
		$management_after = fn( ?array $receipt, ?string $target_path, ?string $source_identity = null ): array => array(
			'management' => WP_Markdown_Reconciliation_Identity::exact(
				null === $receipt || null === $target_path ? null : $this->desired_management_state( $root, $target_path, $receipt, $key, $source_identity )
			),
		);
		$snapshot = array(
			'resource_id'            => $key,
			'resource_type'          => 'post',
			'canonical_path'         => $path,
			'expected_canonical_path'=> $expected,
			'canonical'              => $canonical,
			'wordpress'              => $wp_receipt,
			'baseline'               => $baseline,
			'move_direction'         => 'wordpress_to_canonical',
			'management_uninitialized' => null !== $canonical && null !== $wp_receipt && null === $management_before,
			'durable_before_extra'   => array( 'management' => WP_Markdown_Reconciliation_Identity::exact( $management_before ) ),
			'durable_after_extra_by_category' => array(
				'created'                => $management_after( $canonical, $path, $canonical_source_identity ),
				'updated_from_file'      => $management_after( $canonical, $path, $canonical_source_identity ),
				'written_from_wordpress' => $management_after( $wp_receipt, $expected, $wp_source_identity ),
				'deleted_from_file'      => $management_after( null, null ),
				'deleted_from_wordpress' => $management_after( null, null ),
				'moved'                  => $management_after( $wp_receipt, $expected, $wp_source_identity ),
			),
		);
		if ( null !== $canonical && null !== $wp_receipt && null !== $path && null !== $expected && $path !== $expected ) {
			$snapshot['durable_before'] = array(
				'canonical' => WP_Markdown_Reconciliation_Identity::exact( array( 'path' => $path, 'value' => $canonical ) ),
				'wordpress' => WP_Markdown_Reconciliation_Identity::exact( $wp_receipt ),
			);
			$snapshot['durable_after'] = array(
				'canonical' => WP_Markdown_Reconciliation_Identity::exact( array( 'path' => $expected, 'value' => $wp_receipt ) ),
				'wordpress' => WP_Markdown_Reconciliation_Identity::exact( $wp_receipt ),
			);
		}
		return array(
			'snapshot' => $snapshot,
			'source'   => array(
				'canonical_path'          => $path,
				'expected_canonical_path' => $expected,
				'canonical_hash'          => $file['hash'] ?? null,
				'canonical'               => null === $canonical ? null : WP_Markdown_Reconciliation_Identity::exact( $canonical ),
				'wordpress'               => null === $wp_receipt ? null : WP_Markdown_Reconciliation_Identity::exact( $wp_receipt ),
				'baseline'                => $baseline,
			),
		);
	}

	private function effect_context( array $binding, ?array $entry, string $root ): array {
		$snapshot = is_array( $entry['snapshot'] ?? null ) ? $entry['snapshot'] : array();
		$current  = $this->relative_path( (string) ( $binding['continuation']['canonical_path'] ?? '' ) );
		$expected = $this->relative_path( (string) ( $snapshot['expected_canonical_path'] ?? ( $binding['continuation']['expected_canonical_path'] ?? '' ) ) );
		$post_id  = $this->resource_post_id( (string) ( $binding['resource']['id'] ?? '' ), $snapshot );
		$layout_profile = is_string( $binding['continuation']['layout_profile'] ?? null ) ? $binding['continuation']['layout_profile'] : '';
		if ( null === $expected && 'wordpress_to_canonical' === ( $binding['direction'] ?? '' ) && $post_id > 0 && ( $post = get_post( $post_id ) ) ) {
			$all_posts = get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) );
			$expected = $this->expected_path( $post, $this->relative_path( (string) get_post_meta( $post_id, self::SOURCE_PATH_META, true ) ), $current, $all_posts, $this->storage( $root, $layout_profile ), $layout_profile, $root );
		}
		return array(
			'root'         => $root,
			'current_path' => $current,
			'expected_path'=> $expected ?? $current,
			'post_id'      => $post_id,
			'direction'    => (string) ( $binding['direction'] ?? '' ),
			'kind'         => (string) ( $binding['kind'] ?? '' ),
			'layout_profile' => $layout_profile,
		);
	}

	private function observe_effect( array $operation, array $context ): array {
		$binding = $operation['binding'];
		$post_id = $this->locate_post_id( $context['post_id'], $context['current_path'] ?? $context['expected_path'] );
		$wp = $post_id > 0 && ( $post = get_post( $post_id ) ) ? $this->wordpress_receipt( $post_id, $post ) : null;
		$storage = $this->storage( $context['root'], $context['layout_profile'] );
		$file_post = $post_id > 0 ? $storage->read_post( $post_id ) : null;
		$path = $post_id > 0 && false !== ( $absolute = $storage->path_for_post( $post_id ) ) ? $this->path_from_root( $absolute, $context['root'] ) : null;
		if ( null === $file_post ) {
			$probe = $this->absolute_path( $context['root'], $context['expected_path'] ?? $context['current_path'] );
			if ( null !== $probe && is_file( $probe ) ) {
				$file_post = $storage->read_file( $probe );
				$path = $this->path_from_root( $probe, $context['root'] );
			}
		}
		$canonical = null === $file_post ? null : $this->post_receipt( $file_post, (array) ( $file_post->_frontmatter_meta ?? array() ), (array) ( $file_post->_frontmatter_terms ?? array() ) );
		$result = isset( $binding['before']['canonical'], $binding['after']['canonical'] ) && 'moved' === $binding['kind']
			? array( 'canonical' => array( 'path' => $path, 'value' => $canonical ), 'wordpress' => $wp )
			: array( 'canonical' => $canonical, 'wordpress' => $wp );
		if ( array_key_exists( 'management', $binding['before'] ) ) {
			$result['management'] = $this->management_state( $post_id, $context['root'], (string) $binding['resource']['id'] );
		}
		return $result;
	}

	private function mutate_effect( array $operation, array $context ): void {
		if ( 'canonical_to_wordpress' === $context['direction'] ) {
			$this->canonical_to_wordpress( $operation, $context );
			return;
		}
		if ( 'wordpress_to_canonical' === $context['direction'] ) {
			$this->wordpress_to_canonical( $operation, $context );
			return;
		}
		throw new InvalidArgumentException( 'The reconciliation mutation direction is invalid.' );
	}

	private function canonical_to_wordpress( array $operation, array $context ): void {
		$storage = $this->storage( $context['root'], $context['layout_profile'] );
		$post_id = $this->locate_post_id( $context['post_id'], $context['current_path'] );
		if ( 'deleted_from_file' === $context['kind'] ) {
			$this->authorize_post_mutation( 'delete', $post_id );
			if ( $post_id > 0 && false === wp_delete_post( $post_id, true ) ) {
				throw new RuntimeException( 'WordPress post deletion failed.' );
			}
			$this->delete_baseline( $context['root'], (string) $operation['binding']['resource']['id'] );
			return;
		}
		$source = $context['post_id'] > 0 ? $storage->read_post( $context['post_id'] ) : null;
		if ( null === $source && null !== ( $absolute = $this->absolute_path( $context['root'], $context['current_path'] ) ) ) {
			$source = $storage->read_file( $absolute );
		}
		if ( null === $source ) {
			throw new WP_Markdown_Reconciliation_Store_Conflict( 'The canonical source no longer exists.' );
		}
		$this->authorize_post_mutation( $post_id > 0 ? 'edit' : 'create', $post_id, (string) ( $source->post_type ?? 'post' ) );
		$postarr = $this->post_array( $source );
		$postarr['post_parent'] = $this->runtime_parent_id( $source, $storage, $context['root'] );
		if ( $post_id > 0 ) {
			$postarr['ID'] = $post_id;
		} elseif ( (int) ( $source->ID ?? 0 ) > 0 ) {
			$postarr['import_id'] = (int) $source->ID;
		}
		$result = wp_insert_post( $postarr, true );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
			throw new RuntimeException( 'WordPress post write failed: ' . $result->get_error_message() );
		}
		if ( ! is_int( $result ) || $result < 1 ) {
			throw new RuntimeException( 'WordPress post write failed.' );
		}
		$this->sync_meta( $result, (array) ( $source->_frontmatter_meta ?? array() ) );
		$this->sync_terms( $result, (array) ( $source->_frontmatter_terms ?? array() ) );
		$receipt = $this->wordpress_receipt( $result, get_post( $result ) );
		$receipt['source_identity'] = (string) ( $source->_source_identity ?? $context['current_path'] );
		$this->update_management_meta( $result, $context['root'], $context['current_path'], $receipt, (string) $operation['binding']['resource']['id'] );
	}

	private function wordpress_to_canonical( array $operation, array $context ): void {
		$post_id = $this->locate_post_id( $context['post_id'], $context['current_path'] );
		$storage = $this->storage( $context['root'], $context['layout_profile'] );
		if ( 'deleted_from_wordpress' === $context['kind'] ) {
			if ( $context['post_id'] > 0 ) {
				$storage->delete_post( $context['post_id'] );
			} elseif ( null !== $context['current_path'] && ! $storage->delete_relative_path( $context['current_path'] ) ) {
				throw new RuntimeException( 'Canonical deletion failed.' );
			}
			$this->delete_baseline( $context['root'], (string) $operation['binding']['resource']['id'] );
			return;
		}
		if ( $post_id < 1 || ! ( $post = get_post( $post_id ) ) ) {
			throw new WP_Markdown_Reconciliation_Store_Conflict( 'The WordPress source no longer exists.' );
		}
		$post->_frontmatter_meta  = $this->wordpress_meta( $post_id );
		$post->_frontmatter_terms = $this->wordpress_terms( $post_id, (string) $post->post_type );
		$post->_source_identity = (string) get_post_meta( $post_id, self::SOURCE_IDENTITY_META, true );
		if ( '' === $post->_source_identity ) { $post->_source_identity = $context['current_path'] ?? $context['expected_path']; }
		$expected_receipt = $this->post_receipt( $post, $post->_frontmatter_meta, $post->_frontmatter_terms );
		$existing = $storage->read_post( $post_id );
		$existing_path = false !== ( $found = $storage->path_for_post( $post_id ) ) ? $this->path_from_root( $found, $context['root'] ) : null;
		if ( null === $existing || $existing_path !== $context['expected_path'] || $this->post_receipt( $existing, (array) $existing->_frontmatter_meta, (array) $existing->_frontmatter_terms ) !== $expected_receipt ) {
			if ( 'index.md' === basename( (string) $context['expected_path'] ) ) {
				$target = rtrim( $context['root'], '/\\' ) . '/' . $context['expected_path'];
				if ( ! is_dir( dirname( $target ) ) && ! mkdir( dirname( $target ), 0755, true ) && ! is_dir( dirname( $target ) ) ) {
					throw new RuntimeException( 'Unable to create the planned canonical hierarchy directory.' );
				}
			}
			$written = $storage->write_post( $post );
			if ( false === $written ) {
				throw new RuntimeException( 'Canonical markdown write failed.' );
			}
			$written_path = $this->path_from_root( $written, $context['root'] );
			if ( null !== $context['expected_path'] && $written_path !== $context['expected_path'] ) {
				throw new WP_Markdown_Reconciliation_Store_Conflict( 'Storage selected a different canonical path than the planned target.' );
			}
		}
		$management_receipt = $expected_receipt;
		$management_receipt['source_identity'] = $post->_source_identity;
		$this->update_management_meta( $post_id, $context['root'], $context['expected_path'], $management_receipt, (string) $operation['binding']['resource']['id'] );
	}

	private function update_management_meta( int $post_id, string $root, ?string $path, array $receipt, ?string $resource_id = null ): void {
		if ( null === $path ) {
			throw new RuntimeException( 'A managed canonical path is required.' );
		}
		$absolute = $this->absolute_path( $root, $path );
		update_post_meta( $post_id, self::SOURCE_PATH_META, $path );
		$source_identity = is_string( $receipt['source_identity'] ?? null ) && '' !== $receipt['source_identity'] ? $receipt['source_identity'] : $path;
		unset( $receipt['source_identity'] );
		update_post_meta( $post_id, self::SOURCE_IDENTITY_META, $source_identity );
		update_post_meta( $post_id, self::SOURCE_HASH_META, null !== $absolute && is_file( $absolute ) ? hash_file( 'sha256', $absolute ) : '' );
		$resource_id ??= sprintf( 'post:%020d', $post_id );
		$baseline = array(
			'schema_version' => self::SCHEMA_VERSION,
			'canonical_root' => $root,
			'canonical_path' => $path,
			'identity'       => WP_Markdown_Reconciliation_Identity::exact( $receipt ),
			'resource_id'    => $resource_id,
			'resource_type'  => 'post',
		);
		update_post_meta( $post_id, self::BASELINE_META, $baseline );
		$registry = $this->baseline_registry( $root );
		$registry[ $resource_id ] = $this->public_baseline( $baseline );
		$this->write_baseline_registry( $root, $registry );
	}

	private function management_state( int $post_id, string $root, string $resource_id ): ?array {
		$registry = $this->baseline_registry( $root );
		$registered = isset( $registry[ $resource_id ] ) && is_array( $registry[ $resource_id ] ) ? $registry[ $resource_id ] : null;
		if ( $post_id < 1 || ! get_post( $post_id ) ) {
			return null === $registered ? null : array( 'post' => null, 'registry' => $registered );
		}
		$path = $this->relative_path( (string) get_post_meta( $post_id, self::SOURCE_PATH_META, true ) );
		$identity = $this->relative_path( (string) get_post_meta( $post_id, self::SOURCE_IDENTITY_META, true ) );
		$baseline = $this->baseline( $post_id, $root );
		if ( null === $path && null === $identity && null === $baseline && null === $registered ) {
			return null;
		}
		return array(
			'post'     => array( 'source_path' => $path, 'source_identity' => $identity, 'baseline' => $baseline ),
			'registry' => $registered,
		);
	}

	private function desired_management_state( string $root, string $path, array $receipt, string $resource_id, ?string $source_identity = null ): array {
		$source_identity = null !== $source_identity ? $source_identity : $path;
		$baseline = array(
			'canonical_root' => $root,
			'canonical_path' => $path,
			'identity'       => WP_Markdown_Reconciliation_Identity::exact( $receipt ),
			'resource_id'    => $resource_id,
			'resource_type'  => 'post',
		);
		return array(
			'post'     => array( 'source_path' => $path, 'source_identity' => $source_identity, 'baseline' => $baseline ),
			'registry' => $baseline,
		);
	}

	private function wordpress_posts( array $types ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			throw new RuntimeException( 'WordPress post APIs are unavailable.' );
		}
		$posts = get_posts( array( 'post_type' => $types, 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) );
		$posts = array_values( array_filter( $posts, 'is_object' ) );
		usort( $posts, static fn( object $a, object $b ): int => (int) $a->ID <=> (int) $b->ID );
		return $posts;
	}

	private function wordpress_receipt( int $post_id, object $post ): array {
		return $this->post_receipt( $post, $this->wordpress_meta( $post_id ), $this->wordpress_terms( $post_id, (string) $post->post_type ) );
	}

	private function post_receipt( object $post, array $meta, array $terms ): array {
		return array(
			'post_author'       => (int) ( $post->post_author ?? 0 ),
			'post_date'         => (string) ( $post->post_date ?? '' ),
			'post_date_gmt'     => (string) ( $post->post_date_gmt ?? '' ),
			'post_content'      => (string) ( $post->post_content ?? '' ),
			'post_title'        => (string) ( $post->post_title ?? '' ),
			'post_excerpt'      => (string) ( $post->post_excerpt ?? '' ),
			'post_status'       => (string) ( $post->post_status ?? 'draft' ),
			'comment_status'    => (string) ( $post->comment_status ?? 'open' ),
			'ping_status'       => (string) ( $post->ping_status ?? 'open' ),
			'post_password'     => (string) ( $post->post_password ?? '' ),
			'post_name'         => (string) ( $post->post_name ?? '' ),
			'post_parent'       => (int) ( $post->post_parent ?? 0 ),
			'menu_order'        => (int) ( $post->menu_order ?? 0 ),
			'post_type'         => (string) ( $post->post_type ?? 'post' ),
			'post_mime_type'    => (string) ( $post->post_mime_type ?? '' ),
			'post_content_filtered' => (string) ( $post->post_content_filtered ?? '' ),
			'to_ping'           => (string) ( $post->to_ping ?? '' ),
			'pinged'            => (string) ( $post->pinged ?? '' ),
			'meta'              => $this->normalize_meta( $meta ),
			'terms'             => $this->normalize_terms( $terms ),
		);
	}

	private function wordpress_meta( int $post_id ): array {
		$all = (array) get_post_meta( $post_id );
		unset( $all[ self::SOURCE_PATH_META ], $all[ self::SOURCE_IDENTITY_META ], $all[ self::SOURCE_HASH_META ], $all[ self::BASELINE_META ] );
		$allowed_internal = array( '_thumbnail_id', '_wp_page_template' );
		if ( function_exists( 'apply_filters' ) ) {
			$allowed_internal = (array) apply_filters( 'markdown_db_internal_meta_allowlist', $allowed_internal, function_exists( 'get_post' ) ? get_post( $post_id ) : null );
		}
		foreach ( $all as $key => $values ) {
			if ( str_starts_with( (string) $key, '_' ) && ! in_array( $key, $allowed_internal, true ) ) {
				unset( $all[ $key ] );
				continue;
			}
			$all[ $key ] = array_map(
				static fn( mixed $value ): mixed => is_string( $value ) && function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $value ) : $value,
				(array) $values
			);
		}
		return $all;
	}

	private function wordpress_terms( int $post_id, string $post_type ): array {
		$result = array();
		foreach ( (array) get_object_taxonomies( $post_type ) as $taxonomy ) {
			$slugs = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'slugs' ) );
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $slugs ) ) {
				throw new RuntimeException( 'Unable to read WordPress terms.' );
			}
			if ( ! empty( $slugs ) ) {
				$result[ (string) $taxonomy ] = array_map( 'strval', (array) $slugs );
			}
		}
		return $result;
	}

	private function normalize_meta( array $meta ): array {
		$result = array();
		foreach ( $meta as $key => $values ) {
			if ( ! is_string( $key ) || '' === $key || in_array( $key, array( self::SOURCE_PATH_META, self::SOURCE_IDENTITY_META, self::SOURCE_HASH_META, self::BASELINE_META ), true ) ) {
				continue;
			}
			$values = is_array( $values ) && array_is_list( $values ) ? $values : array( $values );
			$values = array_map( function ( mixed $value ): mixed {
				if ( is_string( $value ) && function_exists( 'maybe_unserialize' ) ) {
					$value = maybe_unserialize( $value );
				}
				return WP_Markdown_Reconciliation_Identity::normalize( $value );
			}, $values );
			usort( $values, static fn( mixed $a, mixed $b ): int => WP_Markdown_Reconciliation_Identity::encode( $a ) <=> WP_Markdown_Reconciliation_Identity::encode( $b ) );
			$result[ $key ] = $values;
		}
		ksort( $result, SORT_STRING );
		return $result;
	}

	private function normalize_terms( array $terms ): array {
		$result = array();
		foreach ( $terms as $taxonomy => $slugs ) {
			if ( ! is_string( $taxonomy ) || '' === $taxonomy ) {
				continue;
			}
			$slugs = array_values( array_unique( array_map( 'strval', is_array( $slugs ) ? $slugs : array( $slugs ) ) ) );
			sort( $slugs, SORT_STRING );
			if ( $slugs ) {
				$result[ $taxonomy ] = $slugs;
			}
		}
		ksort( $result, SORT_STRING );
		return $result;
	}

	private function sync_meta( int $post_id, array $meta ): void {
		$current = $this->wordpress_meta( $post_id );
		$wanted  = $this->normalize_meta( $meta );
		foreach ( array_unique( array_merge( array_keys( $current ), array_keys( $wanted ) ) ) as $key ) {
			delete_post_meta( $post_id, $key );
			foreach ( $wanted[ $key ] ?? array() as $value ) {
				add_post_meta( $post_id, $key, $value );
			}
		}
	}

	private function sync_terms( int $post_id, array $terms ): void {
		$wanted = $this->normalize_terms( $terms );
		$post_type = (string) get_post_type( $post_id );
		foreach ( (array) get_object_taxonomies( $post_type ) as $taxonomy ) {
			$result = wp_set_object_terms( $post_id, $wanted[ $taxonomy ] ?? array(), $taxonomy, false );
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
				throw new RuntimeException( 'Unable to synchronize WordPress terms.' );
			}
		}
	}

	private function baseline( int $post_id, string $root ): ?array {
		$value = get_post_meta( $post_id, self::BASELINE_META, true );
		if ( ! is_array( $value ) || self::SCHEMA_VERSION !== ( $value['schema_version'] ?? null ) ) {
			return null;
		}
		$path = $this->relative_path( (string) $value['canonical_path'] );
		if ( null === $path || $this->root( $value['canonical_root'] ) !== $root || ! is_array( $value['identity'] ) ) {
			return null;
		}
		return array( 'canonical_root' => $root, 'canonical_path' => $path, 'identity' => $value['identity'], 'resource_id' => (string) ( $value['resource_id'] ?? sprintf( 'post:%020d', $post_id ) ), 'resource_type' => 'post' );
	}

	private function baseline_registry( string $root ): array {
		if ( ! function_exists( 'get_option' ) ) { return array(); }
		$value = get_option( self::BASELINE_OPTION_PREFIX . hash( 'sha256', $root ), array() );
		return is_array( $value ) ? array_filter( $value, 'is_array' ) : array();
	}

	private function write_baseline_registry( string $root, array $registry ): void {
		if ( function_exists( 'update_option' ) ) { update_option( self::BASELINE_OPTION_PREFIX . hash( 'sha256', $root ), $registry, false ); }
	}

	private function delete_baseline( string $root, string $resource_id ): void {
		$registry = $this->baseline_registry( $root );
		unset( $registry[ $resource_id ] );
		$this->write_baseline_registry( $root, $registry );
	}

	private function public_baseline( array $baseline ): array {
		return array_intersect_key( $baseline, array_flip( array( 'canonical_root', 'canonical_path', 'identity', 'resource_id', 'resource_type' ) ) );
	}

	private function expected_path( object $post, ?string $source, ?string $canonical, array $posts, WP_Markdown_Storage $storage, string $layout_profile, string $root ): ?string {
		$profile = WP_Markdown_Content_Layout_Profiles::resolve( $layout_profile, array( 'content_dir' => $root ) );
		if ( empty( $profile['legacy'] ) ) {
			$path = $storage->profile_path_for_post( $post );
			return false === $path ? null : $path;
		}
		$type = $this->safe_segment( (string) $post->post_type );
		$legacy = null === $canonical || str_starts_with( $canonical, $type . '/' );
		if ( ! $legacy ) {
			return $source ?? $canonical;
		}
		$parts = array( $type );
		$parents = array();
		$has_children = false;
		foreach ( $posts as $candidate ) {
			$parents[ (int) $candidate->ID ] = $candidate;
			$has_children = $has_children || (int) ( $candidate->post_parent ?? 0 ) === (int) $post->ID;
		}
		$lineage = array();
		$parent_id = (int) ( $post->post_parent ?? 0 );
		while ( $parent_id > 0 && isset( $parents[ $parent_id ] ) ) {
			array_unshift( $lineage, $this->safe_segment( (string) $parents[ $parent_id ]->post_name ) );
			$parent_id = (int) ( $parents[ $parent_id ]->post_parent ?? 0 );
		}
		$parts = array_merge( $parts, $lineage );
		$slug = $this->safe_segment( (string) ( $post->post_name ?: $post->ID ) );
		$parts[] = $has_children ? $slug . '/index.md' : $slug . '.md';
		return $this->relative_path( implode( '/', $parts ) );
	}

	private function post_array( object $post ): array {
		$receipt = $this->post_receipt( $post, array(), array() );
		unset( $receipt['meta'], $receipt['terms'] );
		return $receipt;
	}

	private function authorize_post_mutation( string $operation, int $post_id, string $post_type = 'post' ): void {
		if ( null !== $this->mutation_authorizer ) {
			if ( ! call_user_func( $this->mutation_authorizer, $operation, $post_id, $post_type ) ) {
				throw new WP_Markdown_Reconciliation_Store_Conflict( 'The runtime cannot mutate this WordPress resource.' );
			}
			return;
		}
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ! function_exists( 'current_user_can' ) ) {
			return;
		}
		if ( 'delete' === $operation ) {
			$allowed = $post_id > 0 && current_user_can( 'delete_post', $post_id );
		} elseif ( 'edit' === $operation ) {
			$allowed = $post_id > 0 && current_user_can( 'edit_post', $post_id );
		} else {
			$type = function_exists( 'get_post_type_object' ) ? get_post_type_object( $post_type ) : null;
			$capability = is_object( $type ) && isset( $type->cap->create_posts ) ? (string) $type->cap->create_posts : 'edit_posts';
			$allowed = current_user_can( $capability );
		}
		if ( ! $allowed ) {
			throw new WP_Markdown_Reconciliation_Store_Conflict( 'The current user cannot mutate this WordPress resource.' );
		}
	}

	private function resource_post_id( string $resource_id, array $snapshot ): int {
		if ( preg_match( '/^post:0*([0-9]+)$/', $resource_id, $match ) ) {
			return (int) $match[1];
		}
		return (int) ( $snapshot['canonical']['ID'] ?? $snapshot['wordpress']['ID'] ?? 0 );
	}

	private function locate_post_id( int $post_id, ?string $path ): int {
		if ( $post_id > 0 && get_post( $post_id ) ) {
			return $post_id;
		}
		if ( null === $path ) {
			return 0;
		}
		$posts = get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'posts_per_page' => 2, 'fields' => 'ids', 'meta_key' => self::SOURCE_PATH_META, 'meta_value' => $path ) );
		if ( count( $posts ) > 1 ) {
			throw new WP_Markdown_Reconciliation_Store_Conflict( 'Multiple WordPress posts claim the same canonical source path.' );
		}
		return isset( $posts[0] ) ? (int) $posts[0] : 0;
	}

	/** Resolve canonical parent IDs through immutable source identity, never a raw ID match. */
	private function runtime_parent_id( object $source, WP_Markdown_Storage $storage, string $root ): int {
		$parent = (int) ( $source->post_parent ?? 0 );
		if ( $parent < 1 ) { return 0; }
		$parent_source = $storage->read_post( $parent );
		if ( null === $parent_source ) {
			throw new WP_Markdown_Reconciliation_Store_Conflict( 'Canonical managed hierarchy references a missing parent.' );
		}
		$identity = $this->relative_path( (string) ( $parent_source->_source_identity ?? '' ) );
		if ( null === $identity ) { throw new WP_Markdown_Reconciliation_Store_Conflict( 'Canonical parent source identity is invalid.' ); }
		$ids = get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'posts_per_page' => 2, 'fields' => 'ids', 'meta_key' => self::SOURCE_IDENTITY_META, 'meta_value' => $identity ) );
		if ( count( $ids ) !== 1 ) { throw new WP_Markdown_Reconciliation_Store_Conflict( 'Canonical parent source identity is missing or ambiguous in WordPress.' ); }
		return (int) $ids[0];
	}

	private function storage( string $root, string $layout_profile = '' ): WP_Markdown_Storage {
		$storage = $this->storage ?? new WP_Markdown_Storage( $root );
		$storage->set_content_layout_profile( $layout_profile );
		if ( function_exists( 'get_post' ) ) {
			$storage->set_post_resolver( static fn( int $post_id ): ?object => get_post( $post_id ) );
		}
		return $storage;
	}

	private function types( mixed $types ): array {
		if ( ! is_array( $types ) || array() === $types ) {
			throw new InvalidArgumentException( 'managed_scope must contain post types.' );
		}
		$result = array_values( array_unique( array_filter( array_map( static fn( mixed $type ): string => is_string( $type ) ? trim( $type ) : '', $types ) ) ) );
		sort( $result, SORT_STRING );
		return $result;
	}

	private function root( mixed $root ): string {
		if ( ! is_string( $root ) || '' === trim( $root ) || ( ! str_starts_with( $root, '/' ) && ! preg_match( '/^[A-Za-z]:[\\\\\/]/', $root ) ) ) {
			throw new InvalidArgumentException( 'canonical_root must be absolute.' );
		}
		$root = str_replace( '\\', '/', trim( $root ) );
		$parts = array();
		foreach ( explode( '/', preg_replace( '/^[A-Za-z]:/', '', $root ) ) as $part ) {
			if ( '' === $part || '.' === $part ) { continue; }
			if ( '..' === $part ) { array_pop( $parts ); continue; }
			$parts[] = $part;
		}
		$prefix = preg_match( '/^[A-Za-z]:/', $root ) ? substr( $root, 0, 2 ) . '/' : '/';
		return rtrim( $prefix . implode( '/', $parts ), '/' ) ?: '/';
	}

	private function relative_path( string $path ): ?string {
		$path = str_replace( '\\', '/', trim( $path, " \t\n\r\0\x0B/" ) );
		$parts = explode( '/', $path );
		if ( '' === $path || in_array( '..', $parts, true ) || in_array( '.', $parts, true ) || in_array( '', $parts, true ) || str_contains( $path, "\0" ) ) {
			return null;
		}
		return implode( '/', $parts );
	}

	private function absolute_path( string $root, ?string $relative ): ?string {
		$relative = null === $relative ? null : $this->relative_path( $relative );
		if ( null === $relative || false === ( $real_root = realpath( $root ) ) ) {
			return null;
		}
		$path = rtrim( $root, '/' ) . '/' . $relative;
		$probe = file_exists( $path ) ? $path : dirname( $path );
		$real = realpath( $probe );
		if ( false === $real || ( $real !== $real_root && ! str_starts_with( $real, $real_root . DIRECTORY_SEPARATOR ) ) ) {
			return null;
		}
		$current = rtrim( $root, '/\\' );
		foreach ( preg_split( '#[\\\\/]#', $relative ) ?: array() as $segment ) {
			$current .= DIRECTORY_SEPARATOR . $segment;
			if ( file_exists( $current ) && is_link( $current ) ) {
				return null;
			}
		}
		return $path;
	}

	private function path_from_root( string $path, string $root ): ?string {
		$path = str_replace( '\\', '/', $path );
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );
		return str_starts_with( $path, $root . '/' ) ? $this->relative_path( substr( $path, strlen( $root ) + 1 ) ) : null;
	}

	private function safe_segment( string $segment ): string {
		$segment = function_exists( 'sanitize_title' ) ? sanitize_title( $segment ) : strtolower( preg_replace( '/[^A-Za-z0-9_-]+/', '-', $segment ) );
		return '' === $segment ? 'untitled' : $segment;
	}

	private function fence_directory( string $root ): string {
		$base = realpath( sys_get_temp_dir() );
		if ( false === $base ) {
			throw new RuntimeException( 'Unable to resolve the temporary directory.' );
		}
		return rtrim( $base, DIRECTORY_SEPARATOR ) . '/mdi-reconciliation-fences-' . hash( 'sha256', $root );
	}

	private function pdo(): ?PDO {
		global $pdo, $wpdb;
		$candidates = array( $pdo ?? null, is_object( $wpdb ?? null ) ? ( $wpdb->dbh ?? null ) : null, $wpdb ?? null );
		foreach ( $candidates as $candidate ) {
			$resolved = $this->resolve_pdo( $candidate );
			if ( $resolved instanceof PDO ) {
				return $resolved;
			}
		}
		return null;
	}

	private function resolve_pdo( mixed $candidate ): ?PDO {
		for ( $depth = 0; $depth < 4 && is_object( $candidate ); ++$depth ) {
			if ( $candidate instanceof PDO ) { return $candidate; }
			if ( method_exists( $candidate, 'get_pdo' ) ) { $candidate = $candidate->get_pdo(); continue; }
			if ( method_exists( $candidate, 'get_connection' ) ) { $candidate = $candidate->get_connection(); continue; }
			if ( isset( $candidate->dbh ) ) { $candidate = $candidate->dbh; continue; }
			break;
		}
		return $candidate instanceof PDO ? $candidate : null;
	}
}
