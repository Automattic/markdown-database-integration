<?php
/** MySQL content-primary lifecycle runtime. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_MySQL_Content_Runtime {
	private static ?self $instance = null;
	private array $dirty = array();
	private bool $suppressed = false;
	private bool $flushing = false;

	public static function bootstrap(): void {
		if ( ! defined( 'MARKDOWN_DB_BACKEND' ) || 'mysql-content' !== MARKDOWN_DB_BACKEND || self::$instance ) {
			return;
		}
		self::$instance = new self();
	}

	/** Explicit callers receive the actual durability receipt or an exception. */
	public static function flush_now(): array {
		if ( ! self::$instance ) {
			throw new LogicException( 'The mysql-content runtime is not active.' );
		}
		return self::$instance->flush( true );
	}

	private function __construct() {
		add_action( 'save_post', array( $this, 'mark_post' ), 100, 1 );
		add_action( 'wp_after_insert_post', array( $this, 'capture_hierarchy_change' ), 100, 4 );
		add_action( 'before_delete_post', array( $this, 'capture_pre_delete' ), 1, 1 );
		add_action( 'added_post_meta', array( $this, 'mark_post_meta' ), 100, 2 );
		add_action( 'updated_post_meta', array( $this, 'mark_post_meta' ), 100, 2 );
		add_action( 'deleted_post_meta', array( $this, 'mark_post_meta' ), 100, 2 );
		add_action( 'set_object_terms', array( $this, 'mark_terms' ), 100, 2 );
		add_action( 'deleted_term_relationships', array( $this, 'mark_deleted_terms' ), 100, 2 );
		add_action( 'edited_term', array( $this, 'mark_edited_term_posts' ), 100, 4 );
		add_action( 'delete_term', array( $this, 'mark_deleted_term_posts' ), 100, 5 );
		add_action( 'shutdown', array( $this, 'flush' ), 1 );
		add_action( 'init', array( $this, 'recover' ), 99 );
	}

	public function mark_post( int $post_id ): void { $this->mark( $post_id ); }
	public function mark_post_meta( mixed $meta_id, int $post_id ): void { unset( $meta_id ); $this->mark( $post_id ); }
	public function mark_terms( int $post_id, mixed $terms ): void { unset( $terms ); $this->mark( $post_id ); }
	public function mark_deleted_terms( int $post_id, mixed $term_taxonomy_ids ): void { unset( $term_taxonomy_ids ); $this->mark( $post_id ); }
	/** A slug/reparent move changes every descendant's hierarchy-derived route. */
	public function capture_hierarchy_change( int $post_id, object $post, bool $update, ?object $before ): void {
		if ( ! $update || ! $before || (string) ( $post->post_name ?? '' ) === (string) ( $before->post_name ?? '' ) && (int) ( $post->post_parent ?? 0 ) === (int) ( $before->post_parent ?? 0 ) ) { return; }
		$this->mark( $post_id, true );
		$this->capture_descendants( $post_id );
	}
	/** Term edits and deletes alter frontmatter for every attached managed post. */
	public function mark_edited_term_posts( int $term_id, mixed $term_taxonomy_id, string $taxonomy, mixed $args ): void {
		unset( $args );
		$this->mark_term_objects( $term_id, $term_taxonomy_id, $taxonomy );
	}
	public function mark_deleted_term_posts( int $term_id, mixed $term_taxonomy_id, string $taxonomy, mixed $deleted_term, array $object_ids ): void {
		unset( $deleted_term );
		$this->mark_term_objects( $term_id, $term_taxonomy_id, $taxonomy, $object_ids );
	}
	private function mark_term_objects( int $term_id, mixed $term_taxonomy_id, string $taxonomy, ?array $object_ids = null ): void {
		unset( $term_taxonomy_id );
		if ( ! function_exists( 'get_objects_in_term' ) ) { return; }
		$ids = null === $object_ids ? get_objects_in_term( $term_id, $taxonomy ) : $object_ids;
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $ids ) ) { return; }
		foreach ( (array) $ids as $id ) { $this->mark( (int) $id ); }
	}
	public function capture_pre_delete( int $post_id ): void {
		$this->mark( $post_id, true );
		$this->capture_descendants( $post_id );
	}
	private function capture_descendants( int $post_id ): void {
		$parents = array( $post_id );
		while ( $parents ) {
			$parent = array_pop( $parents );
			foreach ( get_posts( array( 'post_parent' => $parent, 'post_type' => $this->types(), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $child ) {
				$child = (int) $child; $this->mark( $child, true ); $parents[] = $child;
			}
		}
	}

	private function mark( int $post_id, bool $known_managed = false ): void {
		if ( ! $this->suppressed && ! $this->flushing && $post_id > 0 && ( $known_managed || $this->managed( $post_id ) ) ) {
			$this->dirty[ $this->blog_id() ][ $post_id ] = true;
		}
	}

	private function managed( int $post_id ): bool {
		$type = get_post_type( $post_id );
		return is_string( $type ) && in_array( $type, $this->types(), true );
	}

	private function types(): array {
		$value = defined( 'MARKDOWN_DB_MANAGED_POST_TYPES' ) ? MARKDOWN_DB_MANAGED_POST_TYPES : '';
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ) ) );
	}

	public function flush( bool $throw_on_error = false ): array {
		$receipt = array( 'success' => true, 'created' => array(), 'changed' => array(), 'deleted' => array(), 'pending' => array(), 'failures' => array() );
		if ( $this->flushing || ! $this->dirty ) { return $receipt; }
		$this->flushing = true;
		try {
			foreach ( $this->dirty as $blog_id => $dirty ) {
				$this->with_blog( (int) $blog_id, function () use ( &$receipt, $blog_id, $dirty ): void {
				$pending = array_keys( $dirty );
			while ( $pending ) {
				$ids = array_splice( $pending, 0, 1000 );
				$result = $this->reconcile( 'wordpress_to_canonical', 'managed', 'prefer_wordpress', $ids );
				foreach ( $result['categories']['written_from_wordpress'] ?? array() as $entry ) { $receipt['changed'][] = $entry['expected_canonical_path']; }
				foreach ( $result['categories']['deleted_from_wordpress'] ?? array() as $entry ) { $receipt['deleted'][] = $entry['canonical_path']; }
				foreach ( $result['categories']['moved'] ?? array() as $entry ) {
					if ( ! empty( $entry['expected_canonical_path'] ) ) { $receipt['changed'][] = $entry['expected_canonical_path']; }
					if ( ! empty( $entry['canonical_path'] ) && $entry['canonical_path'] !== $entry['expected_canonical_path'] ) { $receipt['deleted'][] = $entry['canonical_path']; }
				}
				$conflicts = array_fill_keys( array_filter( array_map( static fn( mixed $entry ): string => is_array( $entry ) ? (string) ( $entry['resource_id'] ?? '' ) : '', $result['categories']['conflicts'] ?? array() ) ), true );
				foreach ( $ids as $id ) {
					$key = sprintf( 'post:%020d', $id );
					if ( ! isset( $conflicts[ $key ] ) && null === $result['continuation'] ) { unset( $this->dirty[ $blog_id ][ $id ] ); }
					else { $receipt['pending'][] = $key; }
				}
			}
				if ( empty( $this->dirty[ $blog_id ] ) ) { unset( $this->dirty[ $blog_id ] ); }
				} );
			}
			$receipt['changed'] = array_values( array_unique( array_filter( $receipt['changed'] ) ) );
			$receipt['deleted'] = array_values( array_unique( array_filter( $receipt['deleted'] ) ) );
			$receipt['pending'] = array_values( array_unique( $receipt['pending'] ) );
			if ( function_exists( 'do_action' ) ) { do_action( 'markdown_database_integration_flushed', $receipt ); }
			return $receipt;
		} catch ( Throwable $error ) {
			$receipt['success'] = false;
			foreach ( $this->dirty as $blog_id => $ids ) { foreach ( array_keys( $ids ) as $id ) { $receipt['pending'][] = $blog_id . ':post:' . sprintf( '%020d', $id ); } }
			$receipt['failures'][] = array( 'code' => 'markdown_db_mysql_content_flush_failed', 'message' => $error->getMessage() );
			if ( function_exists( 'do_action' ) ) { do_action( 'markdown_database_integration_flush_failed', $receipt ); }
			if ( $throw_on_error ) { throw $error; }
			return $receipt;
		} finally { $this->flushing = false; }
	}

	public function recover(): void {
		if ( ! $this->types() ) { return; }
		foreach ( $this->types() as $type ) {
			if ( ! post_type_exists( $type ) ) { error_log( 'Markdown DB MySQL content hydration skipped: managed post type is not registered: ' . $type ); return; }
		}
		$this->suppressed = true;
		try {
			$this->reconcile( 'canonical_to_wordpress', 'none', 'prefer_canonical' );
			$this->reconcile( 'wordpress_to_canonical', 'none', 'prefer_wordpress' );
			$this->reconcile( 'canonical_to_wordpress', 'none', 'prefer_canonical' );
		}
		catch ( Throwable $error ) { error_log( 'Markdown DB MySQL content hydration failed: ' . $error->getMessage() ); }
		finally { $this->suppressed = false; }
	}

	private function reconcile( string $direction, string $deletion_policy, string $conflict_policy, array $ids = array(), ?array $continuation = null ): array {
		$roots = $this->roots();
		$request = array( 'canonical_root' => $roots['content_dir'], 'state_root' => $roots['state_dir'], 'managed_scope' => $this->types(), 'direction' => $direction, 'deletion_policy' => $deletion_policy, 'conflict_policy' => $conflict_policy, 'batch_size' => 1000, 'layout_profile' => defined( 'MARKDOWN_DB_CONTENT_LAYOUT_PROFILE' ) ? MARKDOWN_DB_CONTENT_LAYOUT_PROFILE : '', 'resource_ids' => array_map( static fn( int $id ): string => sprintf( 'post:%020d', $id ), $ids ), 'continuation' => $continuation, 'wordpress_mutation_authorizer' => static fn(): bool => true );
		$result = array( 'categories' => array(), 'operation_ids' => array(), 'continuation' => null );
		$seen = array();
		for ( $page = 0; $page < 10000; ++$page ) {
			$plan = WP_Markdown_CLI::reconcile( $request + array( 'dry_run' => true ) );
			$applied = WP_Markdown_CLI::reconcile( $request + array( 'plan_id' => $plan['plan_id'], 'source_identity' => $plan['source_identity'] ) );
			foreach ( (array) ( $applied['categories'] ?? array() ) as $category => $entries ) {
				$result['categories'][ $category ] = array_merge( $result['categories'][ $category ] ?? array(), (array) $entries );
			}
			$result['operation_ids'] = array_merge( $result['operation_ids'], (array) ( $applied['operation_ids'] ?? array() ) );
			$next = $applied['continuation'] ?? null;
			if ( null === $next ) {
				$result['operation_ids'] = array_values( array_unique( $result['operation_ids'] ) );
				return $result;
			}
			$key = json_encode( $next, JSON_THROW_ON_ERROR );
			if ( isset( $seen[ $key ] ) ) { throw new RuntimeException( 'Reconciliation continuation made no progress.' ); }
			$seen[ $key ] = true;
			$request['continuation'] = $next;
		}
		throw new RuntimeException( 'Reconciliation continuation limit exceeded.' );
	}

	private function blog_id(): int { return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1; }
	private function roots(): array {
		$roots = array( 'content_dir' => MARKDOWN_DB_CONTENT_DIR, 'state_dir' => defined( 'MARKDOWN_DB_STATE_DIR' ) ? MARKDOWN_DB_STATE_DIR : MARKDOWN_DB_CONTENT_DIR );
		$roots = function_exists( 'apply_filters' ) ? apply_filters( 'markdown_db_mysql_content_roots', $roots, $this->blog_id() ) : $roots;
		if ( ! is_array( $roots ) || ! is_string( $roots['content_dir'] ?? null ) || ! is_string( $roots['state_dir'] ?? null ) ) { throw new RuntimeException( 'mysql-content roots must define content_dir and state_dir for the active blog.' ); }
		return $roots;
	}
	private function with_blog( int $blog_id, callable $callback ): void {
		$current = $this->blog_id();
		if ( $current !== $blog_id && function_exists( 'switch_to_blog' ) ) { switch_to_blog( $blog_id ); }
		try { $callback(); } finally { if ( $current !== $blog_id && function_exists( 'restore_current_blog' ) ) { restore_current_blog(); } }
	}
}
