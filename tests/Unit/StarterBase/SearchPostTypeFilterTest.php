<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

class SearchPostTypeFilterTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
	}

	private function createQuery( bool $is_search ): object {
		return new class( $is_search ) {
			public bool $is_search;
			public array $set_calls = [];

			public function __construct( bool $is_search ) {
				$this->is_search = $is_search;
			}

			public function set( string $key, mixed $value ): void {
				$this->set_calls[ $key ] = $value;
			}
		};
	}

	public function test_sets_search_post_types_on_frontend_search(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		$query = $this->createQuery( true );
		$this->base->search_post_type_filter( $query );

		$this->assertSame( [ 'post' ], $query->set_calls['post_type'] );
	}

	public function test_uses_custom_search_post_types(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		$base = $this->createStarterBase( [ 'search_post_types' => [ 'post', 'page', 'product' ] ] );

		$query = $this->createQuery( true );
		$base->search_post_type_filter( $query );

		$this->assertSame( [ 'post', 'page', 'product' ], $query->set_calls['post_type'] );
	}

	public function test_does_not_filter_admin_search(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		$query = $this->createQuery( true );
		$this->base->search_post_type_filter( $query );

		$this->assertEmpty( $query->set_calls );
	}

	public function test_does_not_filter_non_search(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		$query = $this->createQuery( false );
		$this->base->search_post_type_filter( $query );

		$this->assertEmpty( $query->set_calls );
	}
}
