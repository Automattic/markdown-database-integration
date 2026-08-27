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
		);
		foreach ( $options as $option ) {
			mdi_native_lifecycle_option( $root, ...$option );
		}
	}
}
