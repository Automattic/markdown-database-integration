<?php
/** Shared canonical fixture seeding for native WordPress runtime tests. */

declare( strict_types=1 );

if ( ! function_exists( 'mdi_native_lifecycle_option' ) ) {
	/** Write one canonical option row into a fixture state root. */
	function mdi_native_lifecycle_option( string $root, int $id, string $name, string $value, string $autoload = 'on' ): void {
		$encoded = json_encode(
			array(
				'option_id'    => $id,
				'option_name'  => $name,
				'option_value' => $value,
				'autoload'     => $autoload,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
		);
		file_put_contents( $root . '/_options/' . $name . '.json', $encoded . "\n" );
	}
}

if ( ! function_exists( 'mdi_native_lifecycle_seed_options' ) ) {
	/** Seed the canonical options an installed WordPress runtime expects to find. */
	function mdi_native_lifecycle_seed_options( string $root ): void {
		$options = array(
			array( 1, 'siteurl', 'http://localhost' ),
			array( 2, 'home', 'http://localhost' ),
			array( 3, 'blogname', 'MDI Native Lifecycle' ),
			array( 4, 'blogdescription', '' ),
			array( 5, 'users_can_register', '0' ),
			array( 6, 'admin_email', 'admin@example.test' ),
			array( 7, 'start_of_week', '1' ),
			array( 8, 'use_balanceTags', '0' ),
			array( 9, 'use_smilies', '1' ),
			array( 10, 'require_name_email', '1' ),
			array( 11, 'comments_notify', '1' ),
			array( 12, 'posts_per_rss', '10' ),
			array( 13, 'rss_use_excerpt', '0' ),
			array( 14, 'mailserver_url', 'mail.example.test' ),
			array( 15, 'mailserver_login', 'login@example.test' ),
			array( 16, 'mailserver_pass', 'password' ),
			array( 17, 'mailserver_port', '110' ),
			array( 18, 'default_category', '1' ),
			array( 19, 'default_comment_status', 'open' ),
			array( 20, 'default_ping_status', 'open' ),
			array( 21, 'default_pingback_flag', '1' ),
			array( 22, 'posts_per_page', '10' ),
			array( 23, 'date_format', 'F j, Y' ),
			array( 24, 'time_format', 'g:i a' ),
			array( 25, 'links_updated_date_format', 'F j, Y g:i a' ),
			array( 26, 'timezone_string', '' ),
			array( 27, 'gmt_offset', '0' ),
			array( 28, 'active_plugins', 'a:0:{}' ),
			array( 29, 'db_version', '61833' ),
			array( 30, 'initial_db_version', '61833' ),
			array( 31, 'fresh_site', '1' ),
			array( 32, 'template', 'twentytwentyfive' ),
			array( 33, 'stylesheet', 'twentytwentyfive' ),
			array( 34, 'wp_user_roles', mdi_native_lifecycle_roles() ),
		);
		foreach ( $options as $option ) {
			mdi_native_lifecycle_option( $root, ...$option );
		}
	}
}

if ( ! function_exists( 'mdi_native_lifecycle_roles' ) ) {
	/**
	 * Serialize the core role definitions wp_install() would populate.
	 *
	 * A fixture without them boots a site whose administrator has no
	 * capabilities, so every admin screen answers 403 for reasons that have
	 * nothing to do with the database backend.
	 */
	function mdi_native_lifecycle_roles(): string {
		$administrator = array_fill_keys(
			array(
				'switch_themes', 'edit_themes', 'activate_plugins', 'edit_plugins', 'edit_users', 'edit_files',
				'manage_options', 'moderate_comments', 'manage_categories', 'manage_links', 'upload_files',
				'import', 'unfiltered_html', 'edit_posts', 'edit_others_posts', 'edit_published_posts',
				'publish_posts', 'edit_pages', 'read', 'level_10', 'level_9', 'level_8', 'level_7', 'level_6',
				'level_5', 'level_4', 'level_3', 'level_2', 'level_1', 'level_0', 'edit_others_pages',
				'edit_published_pages', 'publish_pages', 'delete_pages', 'delete_others_pages',
				'delete_published_pages', 'delete_posts', 'delete_others_posts', 'delete_published_posts',
				'delete_private_posts', 'edit_private_posts', 'read_private_posts', 'delete_private_pages',
				'edit_private_pages', 'read_private_pages', 'delete_users', 'create_users', 'unfiltered_upload',
				'edit_dashboard', 'update_plugins', 'delete_plugins', 'install_plugins', 'update_themes',
				'install_themes', 'update_core', 'list_users', 'remove_users', 'promote_users',
				'edit_theme_options', 'delete_themes', 'export',
			),
			true
		);
		return serialize(
			array(
				'administrator' => array( 'name' => 'Administrator', 'capabilities' => $administrator ),
				'editor' => array( 'name' => 'Editor', 'capabilities' => array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true, 'publish_posts' => true, 'upload_files' => true ) ),
				'author' => array( 'name' => 'Author', 'capabilities' => array( 'read' => true, 'edit_posts' => true, 'publish_posts' => true, 'upload_files' => true ) ),
				'contributor' => array( 'name' => 'Contributor', 'capabilities' => array( 'read' => true, 'edit_posts' => true ) ),
				'subscriber' => array( 'name' => 'Subscriber', 'capabilities' => array( 'read' => true ) ),
			)
		);
	}
}

if ( ! function_exists( 'mdi_native_lifecycle_seed_administrator' ) ) {
	/** Seed one administrator user with the capabilities WordPress expects. */
	function mdi_native_lifecycle_seed_administrator( string $root, string $prefix = 'wp_' ): void {
		file_put_contents(
			$root . '/_tables/users.json',
			json_encode(
				array(
					array(
						'ID' => '1',
						'user_login' => 'admin',
						'user_pass' => 'x',
						'user_nicename' => 'admin',
						'user_email' => 'admin@example.test',
						'user_url' => '',
						'user_registered' => '2026-01-01 00:00:00',
						'user_activation_key' => '',
						'user_status' => '0',
						'display_name' => 'admin',
					),
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
			) . "\n"
		);
		file_put_contents(
			$root . '/_tables/usermeta.json',
			json_encode(
				array(
					array( 'umeta_id' => '1', 'user_id' => '1', 'meta_key' => $prefix . 'capabilities', 'meta_value' => serialize( array( 'administrator' => true ) ) ),
					array( 'umeta_id' => '2', 'user_id' => '1', 'meta_key' => $prefix . 'user_level', 'meta_value' => '10' ),
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
			) . "\n"
		);
	}
}
