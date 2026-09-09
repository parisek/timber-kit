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
		$this->assertFalse( $query->query_vars['s'] );
		$this->assertFalse( $query->query['s'] );
		$this->assertTrue( $query->is_404 );
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
}
