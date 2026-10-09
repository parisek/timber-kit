<?php

declare(strict_types=1);

namespace Tests\Integration\Search;

use Tests\Integration\IntegrationTestCase;

/**
 * disable_search() against real WordPress: the posts query behind a `/?s=` 404.
 */
class DisableSearchEmptyQueryTest extends IntegrationTestCase {

	public function set_up(): void {
		parent::set_up();
		self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );
	}

	public function test_empty_query_flag_runs_no_posts_under_the_404(): void {
		$this->bootKit( array( 'disable_search_empty_query' => true ) );

		$this->go_to( home_url( '/?s=needle' ) );

		self::assertTrue( is_404() );
		self::assertSame( array(), $GLOBALS['wp_query']->posts );
		self::assertSame( 0, $GLOBALS['wp_query']->found_posts );
		self::assertSame( 0, $GLOBALS['wp_query']->post_count );
		self::assertStringContainsString( 'IN (0)', $GLOBALS['wp_query']->request, 'one trivial lookup, no table scan' );
	}

	public function test_flag_off_still_fills_posts_under_the_404(): void {
		$this->bootKit( array( 'disable_search_empty_query' => false ) );

		$this->go_to( home_url( '/?s=needle' ) );

		self::assertTrue( is_404() );
		self::assertNotEmpty( $GLOBALS['wp_query']->posts, 'the unconstrained query is the behaviour the flag exists to stop' );
	}

	public function test_flag_on_leaves_a_normal_listing_alone(): void {
		$this->bootKit( array( 'disable_search_empty_query' => true ) );

		$this->go_to( home_url( '/' ) );

		self::assertTrue( is_home() );
		self::assertCount( 3, $GLOBALS['wp_query']->posts );
	}

	public function test_flag_on_leaves_a_secondary_query_alone(): void {
		$this->bootKit( array( 'disable_search_empty_query' => true ) );
		$this->go_to( home_url( '/?s=needle' ) );

		$query = new \WP_Query( array( 'post_type' => 'post', 'fields' => 'ids' ) );

		self::assertCount( 3, $query->posts );
	}
}
