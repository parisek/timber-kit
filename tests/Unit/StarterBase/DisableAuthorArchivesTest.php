<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

/**
 * `/author/{nicename}/` is the destination `?author=N` redirects to, so the
 * guard on the query-string form is only half a guard while this page answers.
 *
 * The assertions pin three things that break quietly: that the archive 404s,
 * that a theme branching on is_author() cannot render over that 404, and that
 * nothing else is touched.
 */
class DisableAuthorArchivesTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
		Functions\when( 'status_header' )->justReturn( null );
		Functions\when( 'nocache_headers' )->justReturn( null );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_query'] );
		parent::tearDown();
	}

	public function test_an_author_archive_becomes_a_404(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_author' )->justReturn( true );
		$wp_query = new \WP_Query();
		$GLOBALS['wp_query'] = $wp_query;

		$this->base->disable_author_archives();

		$this->assertTrue( $wp_query->is_404, 'WP_Query::set_404 should have been called.' );
	}

	/**
	 * Setting 404 is not enough on its own. A theme that routes on is_author()
	 * would still match its author branch and render a page over the 404, which
	 * is how this site's own router answered 503 instead of 404.
	 */
	public function test_the_author_flag_is_cleared_so_a_theme_cannot_route_on_it(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_author' )->justReturn( true );
		$wp_query = new \WP_Query();
		$wp_query->is_author = true;
		$GLOBALS['wp_query'] = $wp_query;

		$this->base->disable_author_archives();

		$this->assertFalse( $wp_query->is_author );
	}

	public function test_a_page_that_is_not_an_author_archive_is_left_alone(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_author' )->justReturn( false );
		$wp_query = new \WP_Query();
		$GLOBALS['wp_query'] = $wp_query;

		$this->base->disable_author_archives();

		$this->assertFalse( $wp_query->is_404 );
	}

	/** The admin lists and edits users; nothing there goes through this. */
	public function test_the_admin_is_left_alone(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'is_author' )->justReturn( true );
		$wp_query = new \WP_Query();
		$GLOBALS['wp_query'] = $wp_query;

		$this->base->disable_author_archives();

		$this->assertFalse( $wp_query->is_404 );
	}

	/** Nothing to mutate is not an error. */
	public function test_a_missing_wp_query_does_not_fatal(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_author' )->justReturn( true );
		unset( $GLOBALS['wp_query'] );

		$this->base->disable_author_archives();

		$this->assertTrue( true );
	}
}
