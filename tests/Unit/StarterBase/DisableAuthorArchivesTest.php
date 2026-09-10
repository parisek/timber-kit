<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Parisek\TimberKit\StarterBase;
use Tests\Unit\StarterBaseTestCase;

/**
 * `/author/{nicename}/` is the destination `?author=N` redirects to, so the
 * guard on the query-string form is only half a guard while this page answers.
 *
 * The response is three separate acts — set_404() on the query, a 404 status
 * line, and no-cache headers — and dropping any one of them leaves a bug that
 * still looks fixed from PHP. Each is asserted on its own, and each is asserted
 * NOT to happen where it must not.
 */
class DisableAuthorArchivesTest extends StarterBaseTestCase {

	private StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_query'] );
		parent::tearDown();
	}

	private function queryOnAuthorArchive(): \WP_Query {
		$wp_query            = new \WP_Query();
		$wp_query->is_author = true;
		$GLOBALS['wp_query'] = $wp_query;

		return $wp_query;
	}

	public function test_an_author_archive_becomes_a_404(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_author' )->justReturn( true );
		Functions\when( 'status_header' )->justReturn( null );
		Functions\when( 'nocache_headers' )->justReturn( null );
		$wp_query = $this->queryOnAuthorArchive();

		$this->base->disable_author_archives();

		$this->assertTrue( $wp_query->is_404, 'WP_Query::set_404 should have been called.' );
	}

	/**
	 * Core's set_404() clears every is_* flag before setting is_404, so a theme
	 * routing on is_author() cannot render over the 404. This asserts the
	 * outcome rather than the mechanism, so it keeps holding if the production
	 * code stops clearing the flag by hand — which it did.
	 */
	public function test_the_author_flag_no_longer_holds_after_the_404(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_author' )->justReturn( true );
		Functions\when( 'status_header' )->justReturn( null );
		Functions\when( 'nocache_headers' )->justReturn( null );
		$wp_query = $this->queryOnAuthorArchive();

		$this->base->disable_author_archives();

		$this->assertFalse( $wp_query->is_author );
	}

	/**
	 * Setting the query flag alone leaves the response a 200. The status line
	 * is what a crawler reads, and it is a separate call.
	 */
	public function test_a_404_status_line_and_no_cache_headers_are_sent(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_author' )->justReturn( true );
		Functions\expect( 'status_header' )->once()->with( 404 );
		Functions\expect( 'nocache_headers' )->once();
		$wp_query = $this->queryOnAuthorArchive();

		$this->base->disable_author_archives();

		$this->assertTrue( $wp_query->is_404 );
	}

	public function test_a_page_that_is_not_an_author_archive_is_left_untouched(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_author' )->justReturn( false );
		Functions\expect( 'status_header' )->never();
		Functions\expect( 'nocache_headers' )->never();
		$wp_query            = new \WP_Query();
		$GLOBALS['wp_query'] = $wp_query;

		$this->base->disable_author_archives();

		$this->assertFalse( $wp_query->is_404 );
	}

	/** The admin lists and edits users; nothing there goes through this. */
	public function test_the_admin_is_left_untouched(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'is_author' )->justReturn( true );
		Functions\expect( 'status_header' )->never();
		Functions\expect( 'nocache_headers' )->never();
		$wp_query = $this->queryOnAuthorArchive();

		$this->base->disable_author_archives();

		$this->assertFalse( $wp_query->is_404 );
	}

	/**
	 * The status line still has to be sent when there is no query object to
	 * mutate, or an unusual dispatch would answer 200 with an empty body.
	 */
	public function test_a_missing_wp_query_still_sends_the_404(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_author' )->justReturn( true );
		Functions\expect( 'status_header' )->once()->with( 404 );
		Functions\expect( 'nocache_headers' )->once();
		unset( $GLOBALS['wp_query'] );

		$this->base->disable_author_archives();

		// Mockery verifies the two expectations above at teardown, which PHPUnit
		// does not count. Without this the test reports as risky and reads as
		// though it asserted nothing.
		$this->addToAssertionCount( 1 );
	}

	public function test_the_flag_is_on_by_default(): void {
		$property = ( new \ReflectionClass( StarterBase::class ) )->getProperty( 'disable_author_archives' );

		$this->assertTrue(
			$property->getDefaultValue(),
			'Downstream sites rely on this being on without configuring anything.'
		);
	}

	/**
	 * An archive that answers 404 must not be listed in a sitemap, so disabling
	 * the archives forces the users provider off whatever the sitemap flag says.
	 * Without this, a `||` quietly becoming an `&&` would ship a sitemap full of
	 * 404s and no test would notice.
	 */
	public function test_disabling_the_archives_also_drops_the_users_sitemap(): void {
		$filters = [];
		Functions\when( 'add_action' )->justReturn( null );
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		$instance = ( new \ReflectionClass( StarterBase::class ) )->newInstanceWithoutConstructor();
		foreach ( [
			'cleanup_wp_head', 'disable_xmlrpc', 'disable_emojis', 'disable_feeds',
			'disable_search', 'cleanup_dashboard', 'cleanup_admin_bar',
			'editor_role_enhancements', 'disable_self_pingbacks', 'restrict_rest_users',
			'disable_application_passwords', 'block_author_enumeration',
			'disable_404_permalink_guess', 'disable_file_editing', 'remove_wp_generator',
			'disable_author_sitemap', 'security_headers',
		] as $flag ) {
			( new \ReflectionClass( StarterBase::class ) )->getProperty( $flag )->setValue( $instance, false );
		}
		// Everything off EXCEPT the archives flag.
		( new \ReflectionClass( StarterBase::class ) )->getProperty( 'disable_author_archives' )->setValue( $instance, true );

		( new \ReflectionClass( StarterBase::class ) )->getMethod( 'registerSecurityHardeningHooks' )->invoke( $instance );

		$this->assertContains( 'wp_sitemaps_add_provider', $filters );
	}
}
