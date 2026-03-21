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
		Functions\when( 'is_search' )->justReturn( true );

		$query = new \stdClass();
		$query->is_search = true;
		$query->query_vars = [ 's' => 'test' ];
		$query->query = [ 's' => 'test' ];
		$query->is_404 = false;

		$this->base->disable_search( $query );

		$this->assertFalse( $query->is_search );
		$this->assertFalse( $query->query_vars['s'] );
		$this->assertFalse( $query->query['s'] );
		$this->assertTrue( $query->is_404 );
	}

	public function test_does_not_disable_admin_search(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'is_search' )->justReturn( true );

		$query = new \stdClass();
		$query->is_search = true;
		$query->query_vars = [ 's' => 'test' ];
		$query->query = [ 's' => 'test' ];
		$query->is_404 = false;

		$this->base->disable_search( $query );

		$this->assertTrue( $query->is_search );
		$this->assertSame( 'test', $query->query_vars['s'] );
		$this->assertFalse( $query->is_404 );
	}

	public function test_does_not_affect_non_search_query(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );

		$query = new \stdClass();
		$query->is_search = false;
		$query->query_vars = [];
		$query->query = [];
		$query->is_404 = false;

		$this->base->disable_search( $query );

		$this->assertFalse( $query->is_search );
		$this->assertFalse( $query->is_404 );
	}
}
