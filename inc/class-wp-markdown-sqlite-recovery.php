<?php
/**
 * Recover posts stranded in a SQLite database into MDI markdown storage.
 *
 * @package Markdown_Database_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- Recovery reads an external SQLite file, not the active WordPress connection.

class WP_Markdown_SQLite_Recovery {

	private const DEFAULT_META_ALLOWLIST = array(
		'_datamachine_raw_source',
		'_datamachine_content_hash',
		'_intelligence_wiki_provenance',
		'_intelligence_wiki_generated_identity',
		'_intelligence_wiki_freshness',
		'_datamachine_post_handler',
		'_datamachine_post_flow_id',
		'_intelligence_wiki_auto_stub',
	);

	/**
	 * Register the recovery ability when the Abilities API is present.
	 */
	public static function register(): void {
		$category_callback = static function (): void {
			if ( ! function_exists( 'wp_register_ability_category' ) ) {
				return;
			}

			if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'markdown-db' ) ) {
				return;
			}

			wp_register_ability_category(
				'markdown-db',
				array(
					'label'       => 'Markdown Database',
					'description' => 'Markdown Database Integration maintenance abilities.',
				)
			);
		};

		$register_callback = static function (): void {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}

			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'markdown-db/recover-sqlite-posts' ) ) {
				return;
			}

			wp_register_ability(
				'markdown-db/recover-sqlite-posts',
				array(
					'label'               => 'Recover SQLite Posts',
					'description'         => 'Recover posts stranded in a SQLite database into MDI markdown storage.',
					'category'            => 'markdown-db',
					'input_schema'        => array( 'type' => 'object' ),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( self::class, 'recover' ),
					'permission_callback' => static fn() => function_exists( 'current_user_can' ) ? current_user_can( 'manage_options' ) : true,
				)
			);
		};

		if ( function_exists( 'doing_action' ) && doing_action( 'wp_abilities_api_categories_init' ) ) {
			$category_callback();
		} elseif ( function_exists( 'did_action' ) && did_action( 'wp_abilities_api_categories_init' ) ) {
			$category_callback();
		} elseif ( function_exists( 'add_action' ) ) {
			add_action( 'wp_abilities_api_categories_init', $category_callback );
		}

		if ( function_exists( 'doing_action' ) && doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
			return;
		}

		if ( function_exists( 'did_action' ) && did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
			return;
		}

		if ( function_exists( 'add_action' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * WP-CLI wrapper for the recovery ability.
	 *
	 * ## OPTIONS
	 *
	 * --source-db=<path>
	 * : SQLite database to recover from.
	 *
	 * [--apply]
	 * : Write markdown files. Defaults to dry-run.
	 *
	 * [--post-type=<type>]
	 * : Post type to recover. Defaults to wiki.
	 *
	 * [--root-slug=<slug>]
	 * : Only recover posts whose path is under this root slug.
	 *
	 * [--min-id=<id>]
	 * : Only recover candidate rows at or above this ID. Ancestors may still be included.
	 *
	 * [--status=<status>]
	 * : Comma-separated statuses to recover. Defaults to publish.
	 *
	 * [--limit=<n>]
	 * : Limit candidate rows after filtering.
	 *
	 * [--format=<format>]
	 * : Output format. Supports json or table. Defaults to table.
	 */
	public static function cli( array $args, array $assoc_args ): void {
		$options = array(
			'source_db' => $assoc_args['source-db'] ?? '',
			'apply'     => array_key_exists( 'apply', $assoc_args ),
			'post_type' => $assoc_args['post-type'] ?? 'wiki',
			'root_slug' => $assoc_args['root-slug'] ?? '',
			'min_id'    => isset( $assoc_args['min-id'] ) ? (int) $assoc_args['min-id'] : 0,
			'status'    => $assoc_args['status'] ?? 'publish',
			'limit'     => isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0,
		);

		$result = self::recover( $options );

		if ( class_exists( 'WP_CLI' ) && ( $result['success'] ?? false ) !== true ) {
			WP_CLI::error( $result['message'] ?? 'SQLite recovery failed.' );
		}

		$format = $assoc_args['format'] ?? 'table';
		if ( 'json' === $format && class_exists( 'WP_CLI' ) ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::line( sprintf( 'Mode: %s', $result['mode'] ) );
			WP_CLI::line( sprintf( 'Candidates: %d', $result['candidate_count'] ) );
			WP_CLI::line( sprintf( 'Would write: %d', $result['would_write_count'] ) );
			WP_CLI::line( sprintf( 'Written: %d', $result['written_count'] ) );
			WP_CLI::line( sprintf( 'Skipped: %d', $result['skipped_count'] ) );
			if ( ! empty( $result['sample'] ) ) {
				WP_CLI\Utils\format_items( 'table', $result['sample'], array( 'id', 'title', 'path', 'status' ) );
			}
		}
	}

	/**
	 * Recover matching SQLite posts into markdown storage.
	 *
	 * @param array $options Recovery options.
	 * @return array Recovery report.
	 */
	public static function recover( array $options ): array {
		if ( ! defined( 'MARKDOWN_DB_CONTENT_DIR' ) || ! class_exists( 'WP_Markdown_Storage' ) ) {
			return self::failure( 'Markdown Database Integration storage is not loaded.' );
		}

		$source_db = (string) ( $options['source_db'] ?? $options['source-db'] ?? '' );
		if ( '' === $source_db || ! is_file( $source_db ) || ! is_readable( $source_db ) ) {
			return self::failure( 'A readable --source-db SQLite file is required.' );
		}

		$post_type = (string) ( $options['post_type'] ?? $options['post-type'] ?? 'wiki' );
		$root_slug = (string) ( $options['root_slug'] ?? $options['root-slug'] ?? '' );
		$min_id    = max( 0, (int) ( $options['min_id'] ?? $options['min-id'] ?? 0 ) );
		$limit     = max( 0, (int) ( $options['limit'] ?? 0 ) );
		$apply     = ! empty( $options['apply'] );
		$statuses  = self::csv( (string) ( $options['status'] ?? 'publish' ) );
		if ( empty( $statuses ) ) {
			$statuses = array( 'publish' );
		}

		try {
			$pdo = new PDO( 'sqlite:' . $source_db );
			$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		} catch ( Throwable $e ) {
			return self::failure( 'Could not open SQLite source: ' . $e->getMessage() );
		}

		if ( ! self::sqlite_table_exists( $pdo, 'wp_posts' ) ) {
			return self::failure( 'SQLite source does not contain wp_posts.' );
		}

		$excluded_types = array_filter( array_map( 'trim', explode( ',', MARKDOWN_DB_EXCLUDED_TYPES ) ) );
		$storage        = new WP_Markdown_Storage( MARKDOWN_DB_CONTENT_DIR, $excluded_types );
		$existing_posts = $storage->get_all_posts( true );
		$existing_by_id = array();
		$resolver_posts = array();

		foreach ( $existing_posts as $post ) {
			$id = (int) ( $post->ID ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$existing_by_id[ $id ] = $post;
			$resolver_posts[ $id ] = $post;
		}

		$source_posts = self::source_posts( $pdo, $post_type );
		foreach ( $source_posts as $id => $post ) {
			$resolver_posts[ $id ] = $post;
		}

		$storage->set_post_resolver(
			static function ( int $id ) use ( &$resolver_posts ) {
				return $resolver_posts[ $id ] ?? null;
			}
		);
		$storage->set_meta_resolver(
			static function ( int $id ) use ( $pdo ) {
				return WP_Markdown_SQLite_Recovery::source_meta( $pdo, $id );
			}
		);

		if ( function_exists( 'add_filter' ) ) {
			add_filter(
				'markdown_db_internal_meta_allowlist',
				static function ( array $allowlist ): array {
					return array_values( array_unique( array_merge( $allowlist, self::DEFAULT_META_ALLOWLIST ) ) );
				}
			);
		}

		$target_ids = array();
		foreach ( $source_posts as $id => $post ) {
			if ( $id < $min_id ) {
				continue;
			}
			if ( ! in_array( (string) ( $post->post_status ?? '' ), $statuses, true ) ) {
				continue;
			}
			if ( '' !== $root_slug && ! self::is_under_root( $post, $root_slug, $resolver_posts ) ) {
				continue;
			}
			$target_ids[ $id ] = true;
		}

		foreach ( array_keys( $target_ids ) as $id ) {
			self::include_missing_ancestors( $id, $resolver_posts, $existing_by_id, $target_ids, $post_type, $min_id );
		}

		$candidates = array_values( array_filter( array_map( static fn( $id ) => $source_posts[ $id ] ?? null, array_keys( $target_ids ) ) ) );
		usort(
			$candidates,
			static function ( object $a, object $b ) use ( $resolver_posts ): int {
				$depth_compare = self::depth( $a, $resolver_posts ) <=> self::depth( $b, $resolver_posts );
				if ( 0 !== $depth_compare ) {
					return $depth_compare;
				}

				return ( (int) ( $a->ID ?? 0 ) ) <=> ( (int) ( $b->ID ?? 0 ) );
			}
		);
		if ( $limit > 0 ) {
			$candidates = array_slice( $candidates, 0, $limit );
		}

		$written = array();
		$skipped = array();
		$sample  = array();

		foreach ( $candidates as $post ) {
			$id = (int) $post->ID;
			if ( isset( $existing_by_id[ $id ] ) ) {
				$skipped[] = array(
					'id'     => $id,
					'reason' => 'already_exists',
				);
				continue;
			}

			$path = self::expected_relative_path( $post, $resolver_posts );
			if ( count( $sample ) < 10 ) {
				$sample[] = array(
					'id'     => $id,
					'title'  => (string) ( $post->post_title ?? '' ),
					'path'   => $path,
					'status' => (string) ( $post->post_status ?? '' ),
				);
			}

			if ( ! $apply ) {
				continue;
			}

			/** @var string|false $file */
			$file = $storage->write_post( $post );
			if ( ! is_string( $file ) ) {
				$skipped[] = array(
					'id'     => $id,
					'reason' => 'write_failed',
				);
				continue;
			}

			$resolver_posts[ $id ] = $post;
			$written[]             = array(
				'id'   => $id,
				'path' => self::relative_to_content_dir( $file ),
			);
		}

		return array(
			'success'           => true,
			'mode'              => $apply ? 'apply' : 'dry-run',
			'source_db'         => $source_db,
			'post_type'         => $post_type,
			'root_slug'         => $root_slug,
			'min_id'            => $min_id,
			'statuses'          => $statuses,
			'candidate_count'   => count( $candidates ),
			'would_write_count' => count( $candidates ) - count( $skipped ),
			'written_count'     => count( $written ),
			'skipped_count'     => count( $skipped ),
			'written'           => $written,
			'skipped'           => $skipped,
			'sample'            => $sample,
		);
	}

	private static function failure( string $message ): array {
		return array(
			'success' => false,
			'message' => $message,
		);
	}

	private static function sqlite_table_exists( PDO $pdo, string $table ): bool {
		$stmt = $pdo->prepare( "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?" );
		$stmt->execute( array( $table ) );
		return false !== $stmt->fetchColumn();
	}

	private static function source_posts( PDO $pdo, string $post_type ): array {
		$stmt = $pdo->prepare( 'SELECT * FROM wp_posts WHERE post_type = :post_type ORDER BY ID ASC' );
		$stmt->execute( array( ':post_type' => $post_type ) );
		$posts = array();
		$row   = $stmt->fetch( PDO::FETCH_OBJ );
		while ( false !== $row ) {
			$row->ID                 = (int) $row->ID;
			$posts[ (int) $row->ID ] = $row;
			$row                     = $stmt->fetch( PDO::FETCH_OBJ );
		}
		return $posts;
	}

	public static function source_meta( PDO $pdo, int $post_id ): array {
		if ( ! self::sqlite_table_exists( $pdo, 'wp_postmeta' ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( self::DEFAULT_META_ALLOWLIST ), '?' ) );
		$stmt         = $pdo->prepare( "SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key IN ({$placeholders}) ORDER BY meta_id ASC" );
		$stmt->execute( array_merge( array( $post_id ), self::DEFAULT_META_ALLOWLIST ) );
		$rows = $stmt->fetchAll( PDO::FETCH_OBJ );
		return is_array( $rows ) ? $rows : array();
	}

	private static function csv( string $value ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), static fn( $item ) => '' !== $item ) );
	}

	private static function is_under_root( object $post, string $root_slug, array $resolver_posts ): bool {
		$current = $post;
		$seen    = array();
		while ( $current && ! isset( $seen[ (int) $current->ID ] ) ) {
			$seen[ (int) $current->ID ] = true;
			if ( (string) ( $current->post_name ?? '' ) === $root_slug ) {
				return true;
			}
			$parent_id = (int) ( $current->post_parent ?? 0 );
			$current   = $parent_id > 0 ? ( $resolver_posts[ $parent_id ] ?? null ) : null;
		}
		return false;
	}

	private static function include_missing_ancestors( int $id, array $resolver_posts, array $existing_by_id, array &$target_ids, string $post_type, int $min_id ): void {
		$current = $resolver_posts[ $id ] ?? null;
		$seen    = array();
		while ( $current && ! isset( $seen[ (int) $current->ID ] ) ) {
			$seen[ (int) $current->ID ] = true;
			$parent_id                  = (int) ( $current->post_parent ?? 0 );
			if ( $parent_id <= 0 || isset( $existing_by_id[ $parent_id ] ) ) {
				return;
			}
			$parent = $resolver_posts[ $parent_id ] ?? null;
			if ( ! $parent || (string) ( $parent->post_type ?? '' ) !== $post_type ) {
				return;
			}
			if ( $parent_id < $min_id ) {
				return;
			}
			$target_ids[ $parent_id ] = true;
			$current                  = $parent;
		}
	}

	private static function depth( object $post, array $resolver_posts ): int {
		$depth   = 0;
		$current = $post;
		$seen    = array();
		while ( ! isset( $seen[ (int) $current->ID ] ) ) {
			$seen[ (int) $current->ID ] = true;
			$parent_id                  = (int) ( $current->post_parent ?? 0 );
			if ( $parent_id <= 0 || empty( $resolver_posts[ $parent_id ] ) ) {
				return $depth;
			}
			++$depth;
			$current = $resolver_posts[ $parent_id ];
		}
		return $depth;
	}

	private static function expected_relative_path( object $post, array $resolver_posts ): string {
		$segments = array( self::sanitize_path( (string) ( $post->post_type ?? 'post' ) ) );
		$parents  = array();
		$current  = $post;
		$seen     = array();
		while ( ! isset( $seen[ (int) $current->ID ] ) ) {
			$seen[ (int) $current->ID ] = true;
			$parent_id                  = (int) ( $current->post_parent ?? 0 );
			if ( $parent_id <= 0 || empty( $resolver_posts[ $parent_id ] ) ) {
				break;
			}
			$parent    = $resolver_posts[ $parent_id ];
			$parents[] = self::sanitize_path( (string) ( $parent->post_name ?? $parent_id ) );
			$current   = $parent;
		}
		$segments   = array_merge( $segments, array_reverse( $parents ) );
		$segments[] = self::sanitize_path( (string) ( $post->post_name ?? (string) ( $post->ID ?? 0 ) ) ) . '.md';
		return implode( '/', $segments );
	}

	private static function sanitize_path( string $name ): string {
		$name = preg_replace( '/[^a-zA-Z0-9_-]/', '-', $name );
		$name = trim( (string) $name, '-' );
		return '' !== $name ? $name : 'unnamed';
	}

	private static function relative_to_content_dir( string $file ): string {
		$prefix = rtrim( MARKDOWN_DB_CONTENT_DIR, '/' ) . '/';
		return str_starts_with( $file, $prefix ) ? substr( $file, strlen( $prefix ) ) : $file;
	}
}

// phpcs:enable WordPress.DB.RestrictedClasses.mysql__PDO
