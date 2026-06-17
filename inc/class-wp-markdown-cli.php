<?php
/**
 * Generic WP-CLI import/export commands for markdown storage.
 *
 * @package Markdown_Database_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Markdown_CLI {

	private const SOURCE_PATH_META = '_markdown_source_path';
	private const SOURCE_HASH_META = '_markdown_source_hash';

	/**
	 * Register generic import/export abilities when the Abilities API is present.
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

			if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'markdown-db/import' ) ) {
				wp_register_ability(
					'markdown-db/import',
					array(
						'label'               => 'Import Markdown Content',
						'description'         => 'Import MDI markdown files into the current WordPress database.',
						'category'            => 'markdown-db',
						'input_schema'        => self::import_input_schema(),
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => array( self::class, 'import' ),
						'permission_callback' => array( self::class, 'can_manage_markdown_db' ),
					)
				);
			}

			if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'markdown-db/export' ) ) {
				wp_register_ability(
					'markdown-db/export',
					array(
						'label'               => 'Export Markdown Content',
						'description'         => 'Export WordPress posts from the current database into MDI markdown files.',
						'category'            => 'markdown-db',
						'input_schema'        => self::export_input_schema(),
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => array( self::class, 'export' ),
						'permission_callback' => array( self::class, 'can_manage_markdown_db' ),
					)
				);
			}
		};

		if ( function_exists( 'doing_action' ) && doing_action( 'wp_abilities_api_categories_init' ) ) {
			$category_callback();
		} elseif ( ( ! function_exists( 'did_action' ) || ! did_action( 'wp_abilities_api_categories_init' ) ) && function_exists( 'add_action' ) ) {
			add_action( 'wp_abilities_api_categories_init', $category_callback );
		}

		if ( function_exists( 'doing_action' ) && doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
			return;
		}

		if ( ( ! function_exists( 'did_action' ) || ! did_action( 'wp_abilities_api_init' ) ) && function_exists( 'add_action' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Permission callback for import/export abilities.
	 */
	public static function can_manage_markdown_db(): bool {
		return function_exists( 'current_user_can' ) ? current_user_can( 'manage_options' ) : true;
	}

	/**
	 * Import markdown files into the current WordPress database.
	 *
	 * ## OPTIONS
	 *
	 * [--path=<path>]
	 * : Markdown content root. Defaults to MARKDOWN_DB_CONTENT_DIR.
	 *
	 * [--dry-run]
	 * : Report changes without writing to the database.
	 *
	 * [--from=<format>]
	 * : Source content format. Defaults to markdown.
	 *
	 * [--to=<format>]
	 * : Stored WordPress content format. Defaults to blocks.
	 *
	 * [--no-convert]
	 * : Preserve raw file body bytes without BFB conversion.
	 *
	 * [--format=<format>]
	 * : Output format. Supports table or json. Defaults to table.
	 */
	public static function import_cli( array $args, array $assoc_args ): void {
		$result = self::import(
			array(
				'path'       => $assoc_args['path'] ?? '',
				'dry_run'    => array_key_exists( 'dry-run', $assoc_args ),
				'from'       => $assoc_args['from'] ?? '',
				'to'         => $assoc_args['to'] ?? '',
				'no_convert' => array_key_exists( 'no-convert', $assoc_args ),
				'profile'    => $assoc_args['profile'] ?? '',
			)
		);
		self::emit_cli_result( $result, $assoc_args['format'] ?? 'table', 'Import' );
	}

	/**
	 * Export current WordPress posts into markdown files.
	 *
	 * ## OPTIONS
	 *
	 * [--path=<path>]
	 * : Markdown content root. Defaults to MARKDOWN_DB_CONTENT_DIR.
	 *
	 * [--post_type=<type>]
	 * : Comma-separated post types to export. Defaults to every non-excluded post type.
	 *
	 * [--dry-run]
	 * : Report changes without writing markdown files.
	 *
	 * [--from=<format>]
	 * : Source WordPress content format. Defaults to blocks.
	 *
	 * [--to=<format>]
	 * : Exported file body format. Defaults to markdown.
	 *
	 * [--no-convert]
	 * : Preserve raw post_content bytes without BFB conversion.
	 *
	 * [--format=<format>]
	 * : Output format. Supports table or json. Defaults to table.
	 */
	public static function export_cli( array $args, array $assoc_args ): void {
		$result = self::export(
			array(
				'path'       => $assoc_args['path'] ?? '',
				'post_types' => $assoc_args['post_type'] ?? $assoc_args['post-type'] ?? '',
				'dry_run'    => array_key_exists( 'dry-run', $assoc_args ),
				'from'       => $assoc_args['from'] ?? '',
				'to'         => $assoc_args['to'] ?? '',
				'no_convert' => array_key_exists( 'no-convert', $assoc_args ),
				'profile'    => $assoc_args['profile'] ?? '',
			)
		);
		self::emit_cli_result( $result, $assoc_args['format'] ?? 'table', 'Export' );
	}

	/**
	 * Import markdown files into WordPress posts.
	 *
	 * @param array $options Import options.
	 * @return array Import report.
	 */
	public static function import( array $options ): array {
		if ( ! class_exists( 'WP_Markdown_Storage' ) ) {
			return self::failure( 'Markdown storage is not loaded.' );
		}

		$content_dir = self::content_dir( (string) ( $options['path'] ?? '' ) );
		if ( '' === $content_dir || ! is_dir( $content_dir ) || ! is_readable( $content_dir ) ) {
			return self::failure( 'A readable markdown content directory is required.' );
		}

		$dry_run        = ! empty( $options['dry_run'] ) || ! empty( $options['dry-run'] );
		$conversion     = self::conversion_options( $options, 'markdown', 'blocks' );
		$excluded_types = self::excluded_types();
		$storage        = new WP_Markdown_Storage( $content_dir, $excluded_types );
		$storage->set_frontmatter_profile( (string) ( $options['profile'] ?? '' ) );
		$posts          = iterator_to_array( $storage->get_all_posts_iterator( false ) );

		usort(
			$posts,
			static function ( object $a, object $b ): int {
				$parent_compare = ( (int) ( $a->post_parent ?? 0 ) ) <=> ( (int) ( $b->post_parent ?? 0 ) );
				if ( 0 !== $parent_compare ) {
					return $parent_compare;
				}

				return ( (int) ( $a->ID ?? 0 ) ) <=> ( (int) ( $b->ID ?? 0 ) );
			}
		);

		$source_map   = self::existing_source_map();
		$id_map       = array();
		$created      = array();
		$updated      = array();
		$skipped      = array();
		$sample       = array();
		$create_count = 0;
		$update_count = 0;

		foreach ( $posts as $post ) {
			$source_path = self::relative_path( (string) ( $post->_source_file ?? '' ), $content_dir );
			if ( '' === $source_path ) {
				$skipped[] = array(
					'path'   => '',
					'reason' => 'missing_source_path',
				);
				continue;
			}

			$source_hash = self::source_hash( (string) ( $post->_source_file ?? '' ) );
			$old_id      = (int) ( $post->ID ?? 0 );
			$existing_id = $source_map[ $source_path ] ?? self::find_existing_post_id( $post );
			$operation   = $existing_id > 0 ? 'update' : 'create';
			if ( 'update' === $operation ) {
				++$update_count;
			} else {
				++$create_count;
			}

			$postarr = self::post_array( $post, $existing_id, $id_map );
			if ( $existing_id <= 0 && $old_id > 0 ) {
				$postarr['import_id'] = $old_id;
			}

			$context           = self::transform_context( 'import', $post, $content_dir, $source_path, $dry_run, $operation, $conversion );
			$converted_content = self::apply_transform_filter( 'markdown_db_import_post_content', (string) $postarr['post_content'], $context, $postarr, $post );
			if ( self::is_error( $converted_content ) ) {
				$skipped[] = array(
					'path'   => $source_path,
					'reason' => self::error_message( $converted_content ),
				);
				continue;
			}
			$postarr['post_content'] = $converted_content;
			$postarr                 = self::apply_post_data_filter( $postarr, $context, $post );

			if ( count( $sample ) < 10 ) {
				$sample[] = array(
					'operation' => $operation,
					'id'        => $existing_id > 0 ? $existing_id : $old_id,
					'title'     => (string) ( $post->post_title ?? '' ),
					'path'      => $source_path,
				);
			}

			if ( $dry_run ) {
				continue;
			}

			$result = wp_insert_post( $postarr, true );
			if ( self::is_error( $result ) ) {
				$skipped[] = array(
					'path'   => $source_path,
					'reason' => self::error_message( $result ),
				);
				continue;
			}

			if ( ! is_int( $result ) ) {
				$skipped[] = array(
					'path'   => $source_path,
					'reason' => 'insert_failed',
				);
				continue;
			}

			$new_id = $result;

			if ( $old_id > 0 ) {
				$id_map[ $old_id ] = $new_id;
			}

			self::sync_meta( $new_id, (array) ( $post->_frontmatter_meta ?? array() ) );
			self::sync_terms( $new_id, (array) ( $post->_frontmatter_terms ?? array() ) );
			update_post_meta( $new_id, self::SOURCE_PATH_META, $source_path );
			update_post_meta( $new_id, self::SOURCE_HASH_META, $source_hash );

			$row = array(
				'id'   => $new_id,
				'path' => $source_path,
			);
			if ( 'update' === $operation ) {
				$updated[] = $row;
			} else {
				$created[] = $row;
			}
		}

		return array(
			'success'         => true,
			'mode'            => $dry_run ? 'dry-run' : 'apply',
			'path'            => $content_dir,
			'candidate_count' => count( $posts ),
			'create_count'    => $dry_run ? $create_count : count( $created ),
			'update_count'    => $dry_run ? $update_count : count( $updated ),
			'skipped_count'   => count( $skipped ),
			'created'         => $created,
			'updated'         => $updated,
			'skipped'         => $skipped,
			'sample'          => $sample,
		);
	}

	/**
	 * Export WordPress posts into markdown files.
	 *
	 * @param array $options Export options.
	 * @return array Export report.
	 */
	public static function export( array $options ): array {
		if ( ! class_exists( 'WP_Markdown_Storage' ) ) {
			return self::failure( 'Markdown storage is not loaded.' );
		}

		$content_dir    = self::content_dir( (string) ( $options['path'] ?? '' ) );
		$dry_run        = ! empty( $options['dry_run'] ) || ! empty( $options['dry-run'] );
		$conversion     = self::conversion_options( $options, 'blocks', 'markdown' );
		$excluded_types = self::excluded_types();
		$post_types     = self::post_types( (string) ( $options['post_types'] ?? $options['post-type'] ?? '' ), $excluded_types );
		$posts          = self::export_posts( $post_types );

		$storage = new WP_Markdown_Storage( $content_dir, $excluded_types );
		$storage->set_frontmatter_profile( (string) ( $options['profile'] ?? '' ) );
		$storage->set_post_resolver( static fn( int $id ) => get_post( $id ) );
		$storage->set_meta_resolver( array( self::class, 'post_meta_rows' ) );
		$storage->set_terms_resolver( array( self::class, 'post_term_rows' ) );

		$written = array();
		$skipped = array();
		$sample  = array();
		foreach ( $posts as $post ) {
			$expected = self::expected_export_path( $post );
			if ( count( $sample ) < 10 ) {
				$sample[] = array(
					'id'    => (int) ( $post->ID ?? 0 ),
					'title' => (string) ( $post->post_title ?? '' ),
					'path'  => $expected,
				);
			}

			if ( $dry_run ) {
				continue;
			}

			$export_post       = clone $post;
			$context           = self::transform_context( 'export', $export_post, $content_dir, $expected, $dry_run, '', $conversion );
			$converted_content = self::apply_transform_filter( 'markdown_db_export_post_content', (string) ( $export_post->post_content ?? '' ), $context, $export_post, $post );
			if ( self::is_error( $converted_content ) ) {
				$skipped[] = array(
					'id'     => (int) ( $post->ID ?? 0 ),
					'path'   => $expected,
					'reason' => self::error_message( $converted_content ),
				);
				continue;
			}
			$export_post_data     = (object) array_merge( (array) $export_post, array( 'post_content' => $converted_content ) );
			$filtered_export_post = self::apply_export_object_filter( $export_post_data, $context, $post );
			$file                 = $storage->write_post( $filtered_export_post );
			if ( is_string( $file ) ) {
				$filtered_export_post_data = (array) $filtered_export_post;
				$filtered_export_post_id   = (int) ( $filtered_export_post_data['ID'] ?? 0 );
				$relative                  = self::relative_path( $file, $content_dir );
				$written[]                 = array(
					'id'   => $filtered_export_post_id,
					'path' => $relative,
				);
				if ( $filtered_export_post_id > 0 ) {
					update_post_meta( $filtered_export_post_id, self::SOURCE_PATH_META, $relative );
					update_post_meta( $filtered_export_post_id, self::SOURCE_HASH_META, self::source_hash( $file ) );
				}
			}
		}

		return array(
			'success'         => true,
			'mode'            => $dry_run ? 'dry-run' : 'apply',
			'path'            => $content_dir,
			'post_types'      => $post_types,
			'candidate_count' => count( $posts ),
			'written_count'   => count( $written ),
			'skipped_count'   => count( $skipped ),
			'written'         => $written,
			'skipped'         => $skipped,
			'sample'          => $sample,
		);
	}

	private static function emit_cli_result( array $result, string $format, string $label ): void {
		if ( class_exists( 'WP_CLI' ) && ( $result['success'] ?? false ) !== true ) {
			WP_CLI::error( $result['message'] ?? $label . ' failed.' );
		}

		if ( 'json' === $format && class_exists( 'WP_CLI' ) ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		WP_CLI::line( sprintf( '%s mode: %s', $label, $result['mode'] ?? '' ) );
		WP_CLI::line( sprintf( 'Path: %s', $result['path'] ?? '' ) );
		WP_CLI::line( sprintf( 'Candidates: %d', (int) ( $result['candidate_count'] ?? 0 ) ) );
		foreach ( array( 'create_count', 'update_count', 'written_count', 'skipped_count' ) as $key ) {
			if ( isset( $result[ $key ] ) ) {
				WP_CLI::line( sprintf( '%s: %d', ucfirst( str_replace( '_', ' ', $key ) ), (int) $result[ $key ] ) );
			}
		}
		if ( ! empty( $result['sample'] ) ) {
			\WP_CLI\Utils\format_items( 'table', $result['sample'], array_map( 'strval', array_keys( $result['sample'][0] ) ) );
		}
	}

	private static function import_input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'path'       => array(
					'type'        => 'string',
					'description' => 'Markdown content root. Defaults to MARKDOWN_DB_CONTENT_DIR.',
				),
				'dry_run'    => array(
					'type'        => 'boolean',
					'description' => 'Report changes without writing to the database.',
				),
				'from'       => array(
					'type'        => 'string',
					'description' => 'Source content format. Defaults to markdown.',
				),
				'to'         => array(
					'type'        => 'string',
					'description' => 'Stored WordPress content format. Defaults to blocks.',
				),
				'no_convert' => array(
					'type'        => 'boolean',
					'description' => 'Preserve raw file body bytes without BFB conversion.',
				),
			),
		);
	}

	private static function export_input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'path'       => array(
					'type'        => 'string',
					'description' => 'Markdown content root. Defaults to MARKDOWN_DB_CONTENT_DIR.',
				),
				'post_types' => array(
					'type'        => 'string',
					'description' => 'Comma-separated post types to export. Defaults to every non-excluded post type.',
				),
				'dry_run'    => array(
					'type'        => 'boolean',
					'description' => 'Report changes without writing markdown files.',
				),
				'from'       => array(
					'type'        => 'string',
					'description' => 'Source WordPress content format. Defaults to blocks.',
				),
				'to'         => array(
					'type'        => 'string',
					'description' => 'Exported file body format. Defaults to markdown.',
				),
				'no_convert' => array(
					'type'        => 'boolean',
					'description' => 'Preserve raw post_content bytes without BFB conversion.',
				),
			),
		);
	}

	private static function conversion_options( array $options, string $default_from, string $default_to ): array {
		$from = self::sanitize_format_key( (string) ( $options['from'] ?? '' ) );
		$to   = self::sanitize_format_key( (string) ( $options['to'] ?? '' ) );

		return array(
			'enabled' => empty( $options['no_convert'] ) && empty( $options['no-convert'] ),
			'from'    => '' !== $from ? $from : $default_from,
			'to'      => '' !== $to ? $to : $default_to,
		);
	}

	private static function content_dir( string $path ): string {
		if ( '' !== $path ) {
			return rtrim( $path, '/' );
		}
		return defined( 'MARKDOWN_DB_CONTENT_DIR' ) ? rtrim( MARKDOWN_DB_CONTENT_DIR, '/' ) : '';
	}

	private static function excluded_types(): array {
		$value = defined( 'MARKDOWN_DB_EXCLUDED_TYPES' ) ? MARKDOWN_DB_EXCLUDED_TYPES : '';
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ), static fn( string $type ): bool => '' !== $type ) );
	}

	private static function existing_source_map(): array {
		if ( ! function_exists( 'get_posts' ) || ! function_exists( 'get_post_meta' ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::SOURCE_PATH_META,
			)
		);

		$map = array();
		foreach ( $posts as $post_id ) {
			$source_path = (string) get_post_meta( (int) $post_id, self::SOURCE_PATH_META, true );
			if ( '' !== $source_path ) {
				$map[ $source_path ] = (int) $post_id;
			}
		}
		return $map;
	}

	private static function find_existing_post_id( object $post ): int {
		$id = (int) ( $post->ID ?? 0 );
		if ( $id > 0 && function_exists( 'get_post' ) && get_post( $id ) ) {
			return $id;
		}

		$post_name = (string) ( $post->post_name ?? '' );
		$post_type = (string) ( $post->post_type ?? 'post' );
		if ( '' !== $post_name && function_exists( 'get_page_by_path' ) ) {
			$existing = get_page_by_path( $post_name, OBJECT, $post_type );
			if ( $existing instanceof WP_Post ) {
				return (int) $existing->ID;
			}
		}

		return 0;
	}

	private static function post_array( object $post, int $existing_id, array $id_map ): array {
		$old_parent = (int) ( $post->post_parent ?? 0 );
		$parent_id  = $old_parent > 0 ? (int) ( $id_map[ $old_parent ] ?? $old_parent ) : 0;

		$postarr = array(
			'post_author'       => (int) ( $post->post_author ?? 1 ),
			'post_date'         => (string) ( $post->post_date ?? '' ),
			'post_date_gmt'     => (string) ( $post->post_date_gmt ?? '' ),
			'post_content'      => (string) ( $post->post_content ?? '' ),
			'post_title'        => (string) ( $post->post_title ?? '' ),
			'post_excerpt'      => (string) ( $post->post_excerpt ?? '' ),
			'post_status'       => (string) ( $post->post_status ?? 'publish' ),
			'comment_status'    => (string) ( $post->comment_status ?? 'open' ),
			'ping_status'       => (string) ( $post->ping_status ?? 'open' ),
			'post_password'     => (string) ( $post->post_password ?? '' ),
			'post_name'         => (string) ( $post->post_name ?? '' ),
			'post_modified'     => (string) ( $post->post_modified ?? '' ),
			'post_modified_gmt' => (string) ( $post->post_modified_gmt ?? '' ),
			'post_parent'       => $parent_id,
			'menu_order'        => (int) ( $post->menu_order ?? 0 ),
			'post_type'         => (string) ( $post->post_type ?? 'post' ),
			'post_mime_type'    => (string) ( $post->post_mime_type ?? '' ),
			'comment_count'     => (int) ( $post->comment_count ?? 0 ),
		);

		if ( $existing_id > 0 ) {
			$postarr['ID'] = $existing_id;
		}

		return $postarr;
	}

	private static function transform_context( string $operation, object $post, string $content_dir, string $source_path, bool $dry_run, string $write_operation = '', array $conversion = array() ): array {
		$context = array(
			'operation'     => $operation,
			'post_type'     => (string) ( $post->post_type ?? 'post' ),
			'source_path'   => $source_path,
			'content_dir'   => $content_dir,
			'source_format' => 'import' === $operation ? 'markdown_file_body' : 'wordpress_post_content',
			'stored_format' => 'import' === $operation ? 'wordpress_post_content' : 'markdown_file_body',
			'dry_run'       => $dry_run,
			'frontmatter'   => (array) ( $post->_frontmatter ?? array() ),
			'conversion'    => $conversion,
		);

		if ( '' !== $write_operation ) {
			$context['write_operation'] = $write_operation;
		}

		return $context;
	}

	private static function apply_transform_filter( string $filter, string $content, array $context, $post_data, object $source_post ) {
		if ( ! function_exists( 'apply_filters' ) ) {
			return self::apply_content_format_conversion( $filter, $content, $context, $post_data, $source_post );
		}
		if ( '' === $filter ) {
			return self::apply_content_format_conversion( $filter, $content, $context, $post_data, $source_post );
		}

		$filtered = apply_filters( $filter, $content, $context, $post_data, $source_post );
		$filtered = is_string( $filtered ) ? $filtered : $content;

		return self::apply_content_format_conversion( $filter, $filtered, $context, $post_data, $source_post );
	}

	/**
	 * Apply optional content-format conversion after MDI transform filters run.
	 *
	 * @param string       $filter      Current transform filter name.
	 * @param string       $content     Filtered content.
	 * @param array        $context     Transform context.
	 * @param array|object $post_data   Post payload for the current transform.
	 * @param object       $source_post Source post object.
	 * @return string|mixed Converted content or conversion error.
	 */
	private static function apply_content_format_conversion( string $filter, string $content, array $context, $post_data, object $source_post ) {
		$operation          = (string) ( $context['operation'] ?? '' );
		$context_conversion = is_array( $context['conversion'] ?? null ) ? $context['conversion'] : array();
		if ( 'markdown_db_import_post_content' === $filter || 'import' === $operation ) {
			$conversion = array(
				'enabled' => true,
				'from'    => 'markdown',
				'to'      => 'blocks',
			);
		} elseif ( 'markdown_db_export_post_content' === $filter || 'export' === $operation ) {
			$conversion = array(
				'enabled' => true,
				'from'    => 'blocks',
				'to'      => 'markdown',
			);
		} else {
			return $content;
		}
		$conversion = array_merge( $conversion, $context_conversion );

		if ( function_exists( 'apply_filters' ) ) {
			$conversion = apply_filters( 'markdown_db_content_format_conversion', $conversion, $context, $post_data, $source_post );
		}

		if ( ! is_array( $conversion ) || empty( $conversion['enabled'] ) ) {
			return $content;
		}

		$from = self::sanitize_format_key( (string) ( $conversion['from'] ?? '' ) );
		$to   = self::sanitize_format_key( (string) ( $conversion['to'] ?? '' ) );
		if ( '' === $from || '' === $to || $from === $to ) {
			return $content;
		}

		return self::convert_content_format( $content, $from, $to, $context, $source_post );
	}

	/**
	 * Convert content with Block Format Bridge when it is available.
	 *
	 * @param string $content     Source content.
	 * @param string $from        Source format.
	 * @param string $to          Target format.
	 * @param array  $context     Transform context for diagnostics.
	 * @param object $source_post Source post object for diagnostics.
	 * @return string|mixed Converted content or conversion error.
	 */
	private static function convert_content_format( string $content, string $from, string $to, array $context, object $source_post ) {
		if ( function_exists( 'bfb_normalize' ) ) {
			$normalized = bfb_normalize( $content, $from );
			if ( self::is_error( $normalized ) ) {
				self::content_format_conversion_failed( $normalized, $context, $source_post );
				return $normalized;
			}

			if ( is_string( $normalized ) ) {
				$content = $normalized;
			}
		}

		if ( ! function_exists( 'bfb_convert' ) ) {
			$error = class_exists( 'WP_Error' ) ? new WP_Error( 'markdown_db_bfb_unavailable', 'Block Format Bridge is required for MDI import/export content conversion.' ) : false;
			self::content_format_conversion_failed( $error, $context, $source_post );
			return $error;
		}

		$converted = bfb_convert( $content, $from, $to );
		if ( self::is_error( $converted ) ) {
			self::content_format_conversion_failed( $converted, $context, $source_post );
			return $converted;
		}

		return is_string( $converted ) ? $converted : $content;
	}

	/**
	 * Emit a diagnostic action for optional BFB conversion failures.
	 *
	 * @param mixed  $error       Conversion error object.
	 * @param array  $context     Transform context.
	 * @param object $source_post Source post object.
	 * @return void
	 */
	private static function content_format_conversion_failed( $error, array $context, object $source_post ): void {
		if ( function_exists( 'do_action' ) ) {
			do_action( 'markdown_db_content_format_conversion_failed', $error, $context, $source_post );
		}
	}

	private static function apply_post_data_filter( array $postarr, array $context, object $source_post ): array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $postarr;
		}

		$filtered = apply_filters( 'markdown_db_import_post_data', $postarr, $context, $source_post );
		return is_array( $filtered ) ? $filtered : $postarr;
	}

	private static function apply_export_object_filter( object $post, array $context, object $source_post ): object {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $post;
		}

		$filtered = apply_filters( 'markdown_db_export_post_object', $post, $context, $source_post );
		return is_object( $filtered ) ? $filtered : $post;
	}

	private static function sync_meta( int $post_id, array $meta ): void {
		foreach ( $meta as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}
			delete_post_meta( $post_id, $key );
			$values = is_array( $value ) ? $value : array( $value );
			foreach ( $values as $meta_value ) {
				add_post_meta( $post_id, $key, $meta_value );
			}
		}
	}

	private static function sync_terms( int $post_id, array $terms ): void {
		foreach ( $terms as $taxonomy => $slugs ) {
			if ( ! is_string( $taxonomy ) || '' === $taxonomy ) {
				continue;
			}
			$term_slugs = is_array( $slugs ) ? $slugs : array( $slugs );
			if ( function_exists( 'wp_set_object_terms' ) ) {
				wp_set_object_terms( $post_id, array_values( array_filter( array_map( 'strval', $term_slugs ) ) ), $taxonomy, false );
			}
		}
	}

	private static function post_types( string $requested, array $excluded_types ): array {
		if ( '' !== $requested ) {
			$types = array_values( array_filter( array_map( 'trim', explode( ',', $requested ) ) ) );
		} elseif ( function_exists( 'get_post_types' ) ) {
			$types = array_values( get_post_types( array(), 'names' ) );
		} else {
			$types = array( 'post', 'page' );
		}

		return array_values( array_diff( $types, $excluded_types ) );
	}

	private static function export_posts( array $post_types ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		return $posts;
	}

	public static function post_meta_rows( int $post_id ): array {
		$all_meta = function_exists( 'get_post_meta' ) ? get_post_meta( $post_id ) : array();
		$rows     = array();
		foreach ( $all_meta as $key => $values ) {
			foreach ( (array) $values as $value ) {
				$rows[] = (object) array(
					'meta_key'   => $key,
					'meta_value' => function_exists( 'maybe_serialize' ) ? maybe_serialize( $value ) : $value,
				);
			}
		}
		return $rows;
	}

	public static function post_term_rows( int $post_id ): array {
		if ( ! function_exists( 'get_object_taxonomies' ) || ! function_exists( 'get_post_type' ) || ! function_exists( 'wp_get_object_terms' ) ) {
			return array();
		}

		$post_type = get_post_type( $post_id );
		if ( ! is_string( $post_type ) ) {
			return array();
		}

		$taxonomies = get_object_taxonomies( $post_type );
		$rows       = array();
		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'all' ) );
			if ( self::is_error( $terms ) || ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$rows[] = (object) array(
					'taxonomy' => $taxonomy,
					'slug'     => (string) $term->slug,
				);
			}
		}
		return $rows;
	}

	private static function expected_export_path( object $post ): string {
		$slug = (string) ( $post->post_name ?? (string) ( $post->ID ?? 0 ) );
		return self::sanitize_path( (string) ( $post->post_type ?? 'post' ) ) . '/' . self::sanitize_path( $slug ) . '.md';
	}

	private static function relative_path( string $path, string $content_dir ): string {
		if ( '' === $path ) {
			return '';
		}
		$prefix = rtrim( $content_dir, '/' ) . '/';
		return str_starts_with( $path, $prefix ) ? substr( $path, strlen( $prefix ) ) : $path;
	}

	private static function source_hash( string $path ): string {
		return is_file( $path ) ? (string) hash_file( 'sha256', $path ) : '';
	}

	private static function sanitize_path( string $name ): string {
		$name = preg_replace( '/[^a-zA-Z0-9_-]/', '-', $name );
		$name = trim( (string) $name, '-' );
		return '' !== $name ? $name : 'unnamed';
	}

	private static function sanitize_format_key( string $key ): string {
		if ( function_exists( 'sanitize_key' ) ) {
			return sanitize_key( $key );
		}

		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) );
	}

	private static function is_error( $value ): bool {
		return function_exists( 'is_wp_error' ) ? is_wp_error( $value ) : ( class_exists( 'WP_Error' ) && $value instanceof WP_Error );
	}

	private static function error_message( $value ): string {
		return is_object( $value ) && method_exists( $value, 'get_error_message' ) ? $value->get_error_message() : 'unknown_error';
	}

	private static function failure( string $message ): array {
		return array(
			'success' => false,
			'message' => $message,
		);
	}
}
