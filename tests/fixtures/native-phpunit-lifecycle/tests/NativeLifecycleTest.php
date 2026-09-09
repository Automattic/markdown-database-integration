<?php

class NativeLifecycleTest extends WP_UnitTestCase {

	public function test_01_insert_read_and_update(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Native lifecycle title',
				'post_content' => 'Native lifecycle content',
			)
		);

		$this->assertSame( 'Native lifecycle title', get_post( $post_id )->post_title );
		wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Native lifecycle updated', 'post_content' => 'Native lifecycle updated content' ) );
		$post = get_post( $post_id );
		$this->assertSame( 'Native lifecycle updated', $post->post_title );
		$this->assertSame( 'Native lifecycle updated content', $post->post_content );
	}

	public function test_02_reset_removes_the_previous_test_post(): void {
		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'title'          => 'Native lifecycle updated',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		$this->assertSame( array(), $query->posts );
	}

	public function test_multisite_switch_keeps_site_posts_isolated(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires a multisite PHPUnit run.' );
		}

		$site_id = self::factory()->blog->create();
		switch_to_blog( $site_id );
		$post_id = self::factory()->post->create( array( 'post_title' => 'Native switched site post' ) );
		$this->assertSame( 'Native switched site post', get_post( $post_id )->post_title );
		restore_current_blog();
		$this->assertSame( array(), get_posts( array( 'post_type' => 'post', 'title' => 'Native switched site post', 'fields' => 'ids' ) ) );
	}
}
