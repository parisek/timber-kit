<?php

declare(strict_types=1);

namespace Tests\Unit\Breadcrumb;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Breadcrumb;
use ReflectionClass;

final class BreadcrumbTest extends BreadcrumbTestCase {

	// -------------------------------------------------------------------
	// Constructor & config
	// -------------------------------------------------------------------

	public function test_constructor_accepts_no_arguments(): void {
		$bc = new Breadcrumb();
		$this->assertSame( [], $bc->get() );
	}

	public function test_constructor_applies_config_to_known_properties(): void {
		$bc = new Breadcrumb([
			'menu_name'     => 'custom-menu',
			'list_page_map' => [ 'project' => 'projects_list' ],
		]);

		$reflection = new ReflectionClass( $bc );
		$menuProp = $reflection->getProperty( 'menu_name' );
		$mapProp = $reflection->getProperty( 'list_page_map' );

		$this->assertSame( 'custom-menu', $menuProp->getValue( $bc ) );
		$this->assertSame( [ 'project' => 'projects_list' ], $mapProp->getValue( $bc ) );
	}

	public function test_constructor_ignores_unknown_config_keys(): void {
		$bc = new Breadcrumb([ 'nonexistent_key' => 'whatever' ]);
		$this->assertSame( [], $bc->get() );
	}

	// -------------------------------------------------------------------
	// Strategy: home
	// -------------------------------------------------------------------

	public function test_build_home_item_returns_home_with_home_url(): void {
		Functions\expect( 'home_url' )->once()->with( '/' )->andReturn( 'https://example.test/' );

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'build_home_item' );

		$this->assertSame( [
			'type' => 'home',
			'url'  => 'https://example.test/',
		], $result );
	}

	// -------------------------------------------------------------------
	// Strategy: 404
	// -------------------------------------------------------------------

	public function test_build_for_404_returns_single_404_item(): void {
		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'build_for_404' );

		$this->assertSame( [ [ 'type' => '404' ] ], $result );
	}

	// -------------------------------------------------------------------
	// Strategy: search
	// -------------------------------------------------------------------

	public function test_build_for_search_returns_query_with_url(): void {
		Functions\expect( 'get_search_query' )->once()->andReturn( 'foo bar' );
		Functions\expect( 'get_search_link' )->once()->andReturn( 'https://example.test/?s=foo+bar' );

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'build_for_search' );

		$this->assertSame( [
			[
				'type'  => 'search',
				'query' => 'foo bar',
				'url'   => 'https://example.test/?s=foo+bar',
			],
		], $result );
	}

	public function test_build_for_search_handles_empty_query(): void {
		Functions\expect( 'get_search_query' )->once()->andReturn( '' );
		Functions\expect( 'get_search_link' )->once()->andReturn( 'https://example.test/?s=' );

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'build_for_search' );

		$this->assertSame( [
			[
				'type'  => 'search',
				'query' => '',
				'url'   => 'https://example.test/?s=',
			],
		], $result );
	}

	// -------------------------------------------------------------------
	// Strategy: author archive
	// -------------------------------------------------------------------

	public function test_build_for_author_archive_returns_author_with_url(): void {
		$author = (object) [
			'ID'           => 5,
			'display_name' => 'Jane Doe',
		];
		Functions\expect( 'get_queried_object' )->once()->andReturn( $author );
		Functions\expect( 'get_author_posts_url' )->once()->with( 5 )->andReturn( 'https://example.test/author/jane/' );

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'build_for_author_archive' );

		$this->assertSame( [
			[
				'type'         => 'author',
				'display_name' => 'Jane Doe',
				'url'          => 'https://example.test/author/jane/',
			],
		], $result );
	}

	// -------------------------------------------------------------------
	// Strategy: date archive
	// -------------------------------------------------------------------

	public function test_build_for_date_archive_year_only(): void {
		Functions\expect( 'get_query_var' )->times( 3 )->andReturnUsing(
			static fn( string $key ): string => [ 'year' => '2024', 'monthnum' => '', 'day' => '' ][ $key ] ?? ''
		);

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'build_for_date_archive' );

		$this->assertSame( [
			[ 'type' => 'date_year', 'year' => 2024, 'url' => null ],
		], $result );
	}

	public function test_build_for_date_archive_year_and_month(): void {
		Functions\expect( 'get_query_var' )->times( 3 )->andReturnUsing(
			static fn( string $key ): string => [ 'year' => '2024', 'monthnum' => '5', 'day' => '' ][ $key ] ?? ''
		);
		Functions\expect( 'get_year_link' )->once()->with( 2024 )->andReturn( 'https://example.test/2024/' );

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'build_for_date_archive' );

		$this->assertSame( [
			[ 'type' => 'date_year',  'year' => 2024, 'url' => 'https://example.test/2024/' ],
			[ 'type' => 'date_month', 'year' => 2024, 'month' => 5, 'url' => null ],
		], $result );
	}

	public function test_build_for_date_archive_year_month_day(): void {
		Functions\expect( 'get_query_var' )->times( 3 )->andReturnUsing(
			static fn( string $key ): string => [ 'year' => '2024', 'monthnum' => '5', 'day' => '15' ][ $key ] ?? ''
		);
		Functions\expect( 'get_year_link' )->once()->with( 2024 )->andReturn( 'https://example.test/2024/' );
		Functions\expect( 'get_month_link' )->once()->with( 2024, 5 )->andReturn( 'https://example.test/2024/05/' );

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'build_for_date_archive' );

		$this->assertSame( [
			[ 'type' => 'date_year',  'year' => 2024, 'url' => 'https://example.test/2024/' ],
			[ 'type' => 'date_month', 'year' => 2024, 'month' => 5, 'url' => 'https://example.test/2024/05/' ],
			[ 'type' => 'date_day',   'year' => 2024, 'month' => 5, 'day' => 15 ],
		], $result );
	}

	// -------------------------------------------------------------------
	// Strategy: post type archive
	// -------------------------------------------------------------------

	public function test_build_for_post_type_archive_returns_archive_label(): void {
		Functions\expect( 'get_query_var' )->with( 'post_type' )->andReturn( 'project' );
		$cpt_object = (object) [
			'name'   => 'project',
			'labels' => (object) [ 'archives' => 'Projects archive' ],
		];
		Functions\expect( 'get_post_type_object' )->once()->with( 'project' )->andReturn( $cpt_object );
		Functions\expect( 'get_post_type_archive_link' )->once()->with( 'project' )->andReturn( 'https://example.test/projects/' );

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'build_for_post_type_archive' );

		$this->assertSame( [
			[
				'type'  => 'item',
				'title' => 'Projects archive',
				'url'   => 'https://example.test/projects/',
			],
		], $result );
	}

	public function test_build_for_post_type_archive_skips_missing_cpt(): void {
		Functions\expect( 'get_query_var' )->with( 'post_type' )->andReturn( false );

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'build_for_post_type_archive' );

		$this->assertSame( [], $result );
	}

	// -------------------------------------------------------------------
	// Strategy: taxonomy
	// -------------------------------------------------------------------

	public function test_build_for_taxonomy_flat_term(): void {
		$term = (object) [ 'term_id' => 10, 'name' => 'News', 'taxonomy' => 'category' ];
		Functions\expect( 'get_queried_object' )->once()->andReturn( $term );
		Functions\expect( 'is_taxonomy_hierarchical' )->once()->with( 'category' )->andReturn( false );
		Functions\expect( 'get_term_link' )->once()->with( $term )->andReturn( 'https://example.test/category/news/' );

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'build_for_taxonomy' );

		$this->assertSame( [
			[ 'type' => 'item', 'title' => 'News', 'url' => 'https://example.test/category/news/' ],
		], $result );
	}

	public function test_build_for_taxonomy_hierarchical_with_ancestors(): void {
		$term = (object) [ 'term_id' => 30, 'name' => 'Web Design', 'taxonomy' => 'services' ];
		$parent = (object) [ 'term_id' => 20, 'name' => 'Services', 'taxonomy' => 'services' ];

		Functions\expect( 'get_queried_object' )->once()->andReturn( $term );
		Functions\expect( 'is_taxonomy_hierarchical' )->once()->with( 'services' )->andReturn( true );
		Functions\expect( 'get_ancestors' )->once()->with( 30, 'services', 'taxonomy' )->andReturn( [ 20 ] );
		Functions\expect( 'get_term' )->once()->with( 20, 'services' )->andReturn( $parent );
		Functions\expect( 'is_wp_error' )->andReturn( false );
		Functions\expect( 'get_term_link' )->times( 2 )->andReturnUsing(
			function ( $t ) use ( $parent, $term ) {
				return $t === $parent ? 'https://example.test/services/' : 'https://example.test/services/web-design/';
			}
		);

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'build_for_taxonomy' );

		$this->assertSame( [
			[ 'type' => 'item', 'title' => 'Services',   'url' => 'https://example.test/services/' ],
			[ 'type' => 'item', 'title' => 'Web Design', 'url' => 'https://example.test/services/web-design/' ],
		], $result );
	}

	public function test_build_for_taxonomy_handles_wp_error_term_link(): void {
		$term = (object) [ 'term_id' => 99, 'name' => 'Broken', 'taxonomy' => 'category' ];
		$wp_error = new \stdClass();
		Functions\expect( 'get_queried_object' )->once()->andReturn( $term );
		Functions\expect( 'is_taxonomy_hierarchical' )->once()->with( 'category' )->andReturn( false );
		Functions\expect( 'get_term_link' )->once()->with( $term )->andReturn( $wp_error );

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'build_for_taxonomy' );

		$this->assertSame( [
			[ 'type' => 'item', 'title' => 'Broken', 'url' => null ],
		], $result );
	}
}
