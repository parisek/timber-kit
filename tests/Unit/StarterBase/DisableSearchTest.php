<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

class DisableSearchTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
	}

	public function test_disables_frontend_search(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'do_action_ref_array' )->justReturn( null );
		Functions\expect( 'status_header' )->once()->with( 404 );
		Functions\expect( 'nocache_headers' )->once();

		$query             = new \WP_Query();
		$query->is_search  = true;
		$query->query_vars = [ 's' => 'test' ];
		$query->query      = [ 's' => 'test' ];
		$query->is_404     = false;
		$query->set_is_main_query( true );

		$this->base->disable_search( $query );

		$this->assertFalse( $query->is_search );
		$this->assertSame( '', $query->query_vars['s'] );
		// $query->query is cleared too, unlike core's own set_404(): see the
		// comment at the call site in StarterBase::disable_search() — plugins
		// (Algolia-style search integrations among them) read
		// $query->query['s'] on pre_get_posts, which fires after this hook.
		$this->assertSame( '', $query->query['s'] );
		$this->assertTrue( $query->is_404 );
	}

	/**
	 * Pins the defect the issue names: the manual flag-flip cleared
	 * `is_search` and set `is_404`, but left every other conditional
	 * (`is_archive`, `is_home`, `is_post_type_archive`) exactly as it was.
	 * `set_404()` resets all of them via `init_query_flags()`.
	 */
	public function test_resets_other_conditionals_via_set_404(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'do_action_ref_array' )->justReturn( null );
		Functions\expect( 'status_header' )->once()->with( 404 );
		Functions\expect( 'nocache_headers' )->once();

		$query                       = new \WP_Query();
		$query->is_search            = true;
		$query->is_archive           = true;
		$query->is_home              = true;
		$query->is_post_type_archive = true;
		$query->query_vars           = [ 's' => 'test' ];
		$query->query                = [ 's' => 'test' ];
		$query->is_404               = false;
		$query->set_is_main_query( true );

		$this->base->disable_search( $query );

		$this->assertFalse( $query->is_archive );
		$this->assertFalse( $query->is_home );
		$this->assertFalse( $query->is_post_type_archive );
		$this->assertTrue( $query->is_404 );
	}

	/**
	 * Pins that `set_404()` fires the `set_404` action — a contract the
	 * manual flag-flip silently opted out of.
	 */
	public function test_fires_set_404_action(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\expect( 'status_header' )->once()->with( 404 );
		Functions\expect( 'nocache_headers' )->once();
		Functions\expect( 'do_action_ref_array' )->once()->with( 'set_404', \Mockery::type( 'array' ) );

		$query             = new \WP_Query();
		$query->is_search  = true;
		$query->query_vars = [ 's' => 'test' ];
		$query->query      = [ 's' => 'test' ];
		$query->is_404     = false;
		$query->set_is_main_query( true );

		$this->base->disable_search( $query );

		$this->assertTrue( $query->is_404 );
	}

	/**
	 * Pins that `set_404()` preserves `is_feed` across the reset — core does
	 * this deliberately (a 404 during a feed request still renders as a
	 * feed), unlike a blanket reset of every conditional.
	 */
	public function test_preserves_is_feed_across_reset(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'do_action_ref_array' )->justReturn( null );
		Functions\expect( 'status_header' )->once()->with( 404 );
		Functions\expect( 'nocache_headers' )->once();

		$query             = new \WP_Query();
		$query->is_search  = true;
		$query->is_feed    = true;
		$query->query_vars = [ 's' => 'test' ];
		$query->query      = [ 's' => 'test' ];
		$query->is_404     = false;
		$query->set_is_main_query( true );

		$this->base->disable_search( $query );

		$this->assertTrue( $query->is_feed );
		$this->assertTrue( $query->is_404 );
	}

	/**
	 * Pins the stub's own ownership split, which two earlier drafts of the
	 * stub disagreed about: `init_query_flags()` resets `is_feed` just like
	 * every other conditional — core does this too. It's `set_404()` that
	 * saves `is_feed` before calling `init_query_flags()` and restores it
	 * after, which is what test_preserves_is_feed_across_reset() above pins
	 * at the `set_404()` level. Calling `init_query_flags()` directly, with
	 * nothing to restore it, must reset `is_feed` like everything else.
	 */
	public function test_init_query_flags_resets_is_feed(): void {
		$query          = new \WP_Query();
		$query->is_feed = true;

		$query->init_query_flags();

		$this->assertFalse( $query->is_feed );
	}

	public function test_does_not_disable_admin_search(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\expect( 'status_header' )->never();

		$query             = new \WP_Query();
		$query->is_search  = true;
		$query->query_vars = [ 's' => 'test' ];
		$query->query      = [ 's' => 'test' ];
		$query->is_404     = false;
		$query->set_is_main_query( true );

		$this->base->disable_search( $query );

		$this->assertTrue( $query->is_search );
		$this->assertSame( 'test', $query->query_vars['s'] );
		$this->assertFalse( $query->is_404 );
	}

	public function test_does_not_affect_non_search_query(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\expect( 'status_header' )->never();

		$query             = new \WP_Query();
		$query->is_search  = false;
		$query->query_vars = [];
		$query->query      = [];
		$query->is_404     = false;
		$query->set_is_main_query( true );

		$this->base->disable_search( $query );

		$this->assertFalse( $query->is_search );
		$this->assertFalse( $query->is_404 );
	}

	/**
	 * Regression for #170: a secondary WP_Query built while the global query
	 * still reports a search must not be touched. The old guard asked the
	 * global `is_search()` instead of the object it was mutating, so a
	 * co-registrant on `parse_query` (e.g. WPML) building its own query
	 * during a real search request got `is_search` cleared, its `s` blanked,
	 * and `is_404` set on a query that was never a search.
	 */
	public function test_does_not_affect_secondary_query_during_search(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\expect( 'status_header' )->never();
		Functions\expect( 'nocache_headers' )->never();

		$query             = new \WP_Query();
		$query->is_search  = true;
		$query->query_vars = [ 's' => 'test' ];
		$query->query      = [ 's' => 'test' ];
		$query->is_404     = false;
		$query->set_is_main_query( false );

		$this->base->disable_search( $query );

		$this->assertTrue( $query->is_search );
		$this->assertSame( 'test', $query->query_vars['s'] );
		$this->assertSame( 'test', $query->query['s'] );
		$this->assertFalse( $query->is_404 );
	}

	/**
	 * Pins that the guard reads `$query->is_search`, not the global
	 * `is_search()`. A main query on a page that is not a search, while the
	 * global lies and says it is (as it would during a real search request,
	 * for a secondary main query on a different part of the same request),
	 * must be left untouched. Without this test, the suite proves the fix
	 * only by accident: the other cases simply stopped stubbing the global,
	 * so calling it would raise an Error rather than the assertions failing
	 * for the right reason.
	 */
	public function test_reads_the_query_object_not_the_global_search_flag(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( true );
		Functions\expect( 'status_header' )->never();

		$query             = new \WP_Query();
		$query->is_search  = false;
		$query->query_vars = [ 's' => 'test' ];
		$query->query      = [ 's' => 'test' ];
		$query->is_404     = false;
		$query->set_is_main_query( true );

		$this->base->disable_search( $query );

		$this->assertFalse( $query->is_search );
		$this->assertSame( 'test', $query->query_vars['s'] );
		$this->assertSame( 'test', $query->query['s'] );
		$this->assertFalse( $query->is_404 );
	}
}
