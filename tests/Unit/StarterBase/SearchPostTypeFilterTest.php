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

	/**
	 * The shared `WP_Query` stub has no `set()` / `get()`. This subclass adds
	 * just those two, so the test reads what the filter wrote.
	 */
	private function createQuery( bool $is_search, bool $is_main_query = true ): \WP_Query {
		$query = new class() extends \WP_Query {
			public function set( string $key, mixed $value ): void {
				$this->query_vars[ $key ] = $value;
			}

			public function get( string $key, mixed $default = '' ): mixed {
				return $this->query_vars[ $key ] ?? $default;
			}
		};

		$query->is_search = $is_search;
		$query->set_is_main_query( $is_main_query );

		return $query;
	}

	public function test_sets_search_post_types_on_frontend_search(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		$query = $this->createQuery( true );
		$this->base->search_post_type_filter( $query );

		$this->assertSame( [ 'post' ], $query->get( 'post_type' ) );
	}

	public function test_uses_custom_search_post_types(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		$base = $this->createStarterBase( [ 'search_post_types' => [ 'post', 'page', 'product' ] ] );

		$query = $this->createQuery( true );
		$base->search_post_type_filter( $query );

		$this->assertSame( [ 'post', 'page', 'product' ], $query->get( 'post_type' ) );
	}

	public function test_does_not_filter_admin_search(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		$query = $this->createQuery( true );
		$this->base->search_post_type_filter( $query );

		$this->assertEmpty( $query->get( 'post_type' ) );
	}

	public function test_does_not_filter_non_search(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		$query = $this->createQuery( false );
		$this->base->search_post_type_filter( $query );

		$this->assertEmpty( $query->get( 'post_type' ) );
	}

	/**
	 * Regression: a REST controller (`/wp/v2/pages?search=`, `/wp/v2/media?search=`,
	 * `/wp/v2/search`), `get_posts()` and WP-CLI each build their own `WP_Query`.
	 * None of them is `is_admin()`, and none is the main query. The filter
	 * rewrote their `post_type` to `$search_post_types`, so the editor's
	 * "Parent" picker listed blog posts instead of pages, and the media
	 * picker found nothing.
	 */
	public function test_does_not_filter_a_secondary_search_query(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		$query = $this->createQuery( true, false );
		$query->set( 'post_type', 'page' );
		$this->base->search_post_type_filter( $query );

		$this->assertSame( 'page', $query->get( 'post_type' ) );
	}

	public function test_leaves_an_empty_post_type_of_a_secondary_search_query_empty(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		$query = $this->createQuery( true, false );
		$this->base->search_post_type_filter( $query );

		$this->assertEmpty( $query->get( 'post_type' ) );
	}

	public function test_returns_the_query_it_was_given(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		$query = $this->createQuery( true, false );

		$this->assertSame( $query, $this->base->search_post_type_filter( $query ) );
	}
}
