<?php

declare(strict_types=1);

namespace Tests\Integration\Search;

use Tests\Integration\IntegrationTestCase;

/**
 * search_post_type_filter() against real WordPress queries.
 *
 * The unit test hands the filter a hand-made query and a stubbed is_admin().
 * It cannot see a REST controller build its own WP_Query, which is where the
 * filter once rewrote `post_type` and broke the block editor's pickers.
 */
final class SearchPostTypeFilterTest extends IntegrationTestCase {

	private const TERM = 'Zephyrine';

	public function test_rest_pages_search_returns_pages_with_the_filter_hooked(): void {
		$kit = $this->bootKit(
			array(
				'disable_search'    => false,
				'search_post_types' => array( 'post' ),
			)
		);
		self::assertNotFalse( has_filter( 'pre_get_posts', array( $kit, 'search_post_type_filter' ) ) );

		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => self::TERM . ' page' ) );
		self::factory()->post->create( array( 'post_type' => 'post', 'post_title' => self::TERM . ' post' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new \WP_REST_Request( 'GET', '/wp/v2/pages' );
		$request->set_param( 'search', self::TERM );
		$response = rest_do_request( $request );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array( $page ), array_column( $response->get_data(), 'id' ) );
	}

	public function test_frontend_search_main_query_is_limited_to_search_post_types(): void {
		$this->bootKit(
			array(
				'disable_search'    => false,
				'search_post_types' => array( 'post' ),
			)
		);

		$post = self::factory()->post->create( array( 'post_type' => 'post', 'post_title' => self::TERM . ' post' ) );
		self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => self::TERM . ' page' ) );

		$this->go_to( home_url( '/?s=' . self::TERM ) );

		global $wp_query;
		self::assertTrue( $wp_query->is_main_query() );
		self::assertTrue( $wp_query->is_search() );
		self::assertSame( array( 'post' ), $wp_query->get( 'post_type' ) );
		self::assertSame( array( $post ), wp_list_pluck( $wp_query->posts, 'ID' ) );
	}

	public function test_secondary_search_query_keeps_its_own_post_type(): void {
		$this->bootKit(
			array(
				'disable_search'    => false,
				'search_post_types' => array( 'post' ),
			)
		);

		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => self::TERM . ' page' ) );
		self::factory()->post->create( array( 'post_type' => 'post', 'post_title' => self::TERM . ' post' ) );

		// A frontend request, so the guard cannot pass on is_admin() alone.
		$this->go_to( home_url( '/' ) );

		$query = new \WP_Query(
			array(
				's'         => self::TERM,
				'post_type' => 'page',
				'fields'    => 'ids',
			)
		);

		self::assertSame( 'page', $query->get( 'post_type' ) );
		self::assertSame( array( $page ), $query->posts );
	}

	public function test_filter_is_not_hooked_when_search_is_disabled(): void {
		$kit = $this->bootKit( array( 'disable_search' => true ) );

		self::assertFalse( has_filter( 'pre_get_posts', array( $kit, 'search_post_type_filter' ) ) );
	}
}
