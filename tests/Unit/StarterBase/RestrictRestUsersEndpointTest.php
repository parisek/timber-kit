<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

class RestrictRestUsersEndpointTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp'] );
		parent::tearDown();
	}

	private function setRestRoute( string $route ): void {
		$GLOBALS['wp'] = (object) [ 'query_vars' => [ 'rest_route' => $route ] ];
	}

	public function test_blocks_unauthenticated_users_list(): void {
		$this->setRestRoute( '/wp/v2/users' );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = $this->base->restrict_rest_users_endpoint( null );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_blocks_unauthenticated_single_user(): void {
		$this->setRestRoute( '/wp/v2/users/1' );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = $this->base->restrict_rest_users_endpoint( null );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_allows_authenticated_users_endpoint(): void {
		$this->setRestRoute( '/wp/v2/users' );
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$result = $this->base->restrict_rest_users_endpoint( null );

		$this->assertNull( $result );
	}

	public function test_allows_other_endpoints(): void {
		$this->setRestRoute( '/wp/v2/posts' );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$result = $this->base->restrict_rest_users_endpoint( null );

		$this->assertNull( $result );
	}

	public function test_passes_through_existing_error(): void {
		$this->setRestRoute( '/wp/v2/users' );

		$existing_error = new \WP_Error( 'existing', 'error' );
		$result = $this->base->restrict_rest_users_endpoint( $existing_error );

		$this->assertSame( $existing_error, $result );
	}
}
