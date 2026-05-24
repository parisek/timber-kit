<?php

declare(strict_types=1);

namespace Tests\Unit\Breadcrumb;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Breadcrumb;
use ReflectionClass;

final class BreadcrumbTest extends BreadcrumbTestCase {

	// -------------------------------------------------------------------
	// Constructor & config
	// -------------------------------------------------------------------

	public function test_constructor_accepts_no_arguments(): void {
		$bc = new Breadcrumb();
		$this->assertInstanceOf( Breadcrumb::class, $bc );
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
		$this->assertInstanceOf( Breadcrumb::class, $bc );
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

	// -------------------------------------------------------------------
	// Strategy: pagination
	// -------------------------------------------------------------------

	public function test_build_pagination_item_returns_paged_with_url(): void {
		Functions\expect( 'get_query_var' )->once()->with( 'paged' )->andReturn( 3 );
		Functions\expect( 'get_pagenum_link' )->once()->with( 1 )->andReturn( 'https://example.test/category/news/' );

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'build_pagination_item' );

		$this->assertSame( [
			'type' => 'pagination',
			'page' => 3,
			'url'  => 'https://example.test/category/news/',
		], $result );
	}

	// -------------------------------------------------------------------
	// Helper: get_menu_item (pure, no WP calls)
	// -------------------------------------------------------------------

	public function test_get_menu_item_returns_matching_item_by_id(): void {
		$items = [
			(object) [ 'ID' => 1, 'title' => 'Home' ],
			(object) [ 'ID' => 2, 'title' => 'About' ],
			(object) [ 'ID' => 3, 'title' => 'Contact' ],
		];

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'get_menu_item', [ 'ID', 2, $items ] );

		$this->assertSame( 'About', $result->title );
	}

	public function test_get_menu_item_returns_false_when_no_match(): void {
		$items = [ (object) [ 'ID' => 1, 'title' => 'Home' ] ];

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'get_menu_item', [ 'ID', 99, $items ] );

		$this->assertFalse( $result );
	}

	public function test_get_menu_item_normalizes_string_int_mismatch(): void {
		// REGRESSION: wp_get_nav_menu_items() returns object_id as STRING
		// (post_meta is stringified); get_queried_object_id() returns INT.
		// Without is_numeric normalization, strict `===` silently fails.
		$items = [
			(object) [ 'object_id' => '42', 'title' => 'About' ],
			(object) [ 'object_id' => '99', 'title' => 'Other' ],
		];

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'get_menu_item', [ 'object_id', 42, $items ] );

		$this->assertSame( 'About', $result->title );
	}

	// -------------------------------------------------------------------
	// Helper: by_menu_trail
	// -------------------------------------------------------------------

	public function test_by_menu_trail_returns_empty_when_no_menu_items(): void {
		Functions\expect( 'get_nav_menu_locations' )->once()->andReturn( [] );
		Functions\expect( 'wp_get_nav_menu_items' )->once()->andReturn( false );

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'by_menu_trail' );

		$this->assertSame( [], $result );
	}

	public function test_by_menu_trail_returns_empty_when_current_page_not_in_menu(): void {
		Functions\expect( 'get_nav_menu_locations' )->once()->andReturn( [] );
		Functions\expect( 'wp_get_nav_menu_items' )->once()->andReturn( [
			(object) [
				'ID'               => 10,
				'object_id'        => '100',
				'menu_item_parent' => '0',
				'url'              => 'https://example.test/about',
				'title'            => 'About',
			],
		] );
		Functions\expect( 'get_queried_object_id' )->once()->andReturn( 999 );
		\Brain\Monkey\Filters\expectApplied( 'wpml_object_id' )->andReturn( 999 );

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'by_menu_trail' );

		$this->assertSame( [], $result );
	}

	// -------------------------------------------------------------------
	// Helper: get_global_links
	// -------------------------------------------------------------------

	public function test_get_global_links_returns_empty_when_acf_unavailable(): void {
		// get_field is not stubbed — function_exists() returns false
		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'get_global_links' );

		$this->assertSame( [], $result );
	}

	public function test_get_global_links_returns_empty_when_acf_returns_null(): void {
		Functions\when( 'get_field' )->justReturn( null );

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'get_global_links' );

		$this->assertSame( [], $result );
	}

	public function test_get_global_links_normalises_array_entry_with_post_resolution(): void {
		Functions\when( 'get_field' )->justReturn( [
			'article_list' => [
				'url'   => 'https://example.test/blog',
				'title' => 'Blog',
			],
		] );
		Functions\expect( 'url_to_postid' )->with( 'https://example.test/blog' )->andReturn( 42 );
		\Brain\Monkey\Filters\expectApplied( 'wpml_object_id' )->with( 42, 'page' )->andReturn( 42 );
		Functions\expect( 'get_permalink' )->with( 42 )->andReturn( 'https://example.test/blog' );

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'get_global_links' );

		$this->assertArrayHasKey( 'article_list', $result );
		$this->assertSame( 42, $result['article_list']['id'] );
		$this->assertSame( 'Blog', $result['article_list']['title'] );
		$this->assertSame( 'https://example.test/blog', $result['article_list']['url'] );
	}

	// -------------------------------------------------------------------
	// Strategy: singular (page, menu-trail success)
	// -------------------------------------------------------------------

	public function test_build_for_singular_page_with_menu_trail(): void {
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		$post = (object) [ 'ID' => 30, 'post_title' => 'Web Design' ];
		Functions\when( 'get_queried_object' )->justReturn( $post );
		Functions\when( 'get_permalink' )->alias( function( $id = null ) {
			return 'https://example.test/services/web';  // both no-arg and arg-30 return same
		} );

		Functions\expect( 'get_nav_menu_locations' )->once()->andReturn( [] );
		Functions\expect( 'wp_get_nav_menu_items' )->once()->andReturn( [
			(object) [ 'ID' => 1, 'object_id' => '10', 'menu_item_parent' => '0',
				'url' => 'https://example.test/', 'title' => 'Home' ],
			(object) [ 'ID' => 2, 'object_id' => '20', 'menu_item_parent' => '1',
				'url' => 'https://example.test/services', 'title' => 'Services' ],
			(object) [ 'ID' => 3, 'object_id' => '30', 'menu_item_parent' => '2',
				'url' => 'https://example.test/services/web', 'title' => 'Web Design' ],
		] );
		Functions\expect( 'get_queried_object_id' )->andReturn( 30 );
		\Brain\Monkey\Filters\expectApplied( 'wpml_object_id' )->andReturn( 30 );

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'build_for_singular' );

		$this->assertSame( [
			[ 'type' => 'item', 'title' => 'Home',     'url' => 'https://example.test/' ],
			[ 'type' => 'item', 'title' => 'Services', 'url' => 'https://example.test/services' ],
			[ 'type' => 'item', 'title' => 'Web Design', 'url' => null ],
		], $result );
	}

	public function test_build_for_singular_page_falls_back_to_ancestors_when_not_in_menu(): void {
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		$post = (object) [ 'ID' => 30, 'post_title' => 'Orphan Page' ];
		Functions\when( 'get_queried_object' )->justReturn( $post );

		Functions\expect( 'get_nav_menu_locations' )->once()->andReturn( [] );
		Functions\expect( 'wp_get_nav_menu_items' )->once()->andReturn( [] );
		Functions\expect( 'get_queried_object_id' )->andReturn( 30 );
		\Brain\Monkey\Filters\expectApplied( 'wpml_object_id' )->andReturn( 30 );

		Functions\expect( 'get_post' )->once()->andReturn( $post );
		Functions\expect( 'get_post_ancestors' )->once()->with( $post )->andReturn( [ 20, 10 ] );
		Functions\when( 'get_permalink' )->alias( function( $id = null ) {
			return match( $id ) {
				10 => 'https://example.test/about/',
				20 => 'https://example.test/about/team/',
				default => '',
			};
		} );
		Functions\when( 'get_the_title' )->alias( function( $id ) {
			return match( $id ) {
				10 => 'About',
				20 => 'Team',
				default => '',
			};
		} );

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'build_for_singular' );

		$this->assertSame( [
			[ 'type' => 'item', 'title' => 'About', 'url' => 'https://example.test/about/' ],
			[ 'type' => 'item', 'title' => 'Team', 'url' => 'https://example.test/about/team/' ],
			[ 'type' => 'item', 'title' => 'Orphan Page', 'url' => null ],
		], $result );
	}

	public function test_build_for_singular_post_with_list_page_map(): void {
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		$post = (object) [ 'ID' => 50, 'post_title' => 'My Article' ];
		Functions\when( 'get_queried_object' )->justReturn( $post );

		Functions\when( 'get_field' )->justReturn( [
			'article_list' => [ 'url' => 'https://example.test/blog/', 'title' => 'Blog' ],
		] );
		Functions\expect( 'url_to_postid' )->with( 'https://example.test/blog/' )->andReturn( 42 );
		\Brain\Monkey\Filters\expectApplied( 'wpml_object_id' )->with( 42, 'page' )->andReturn( 42 );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/blog/' );

		$bc = new Breadcrumb([
			'list_page_map' => [ 'post' => 'article_list' ],
		]);
		$result = $this->invoke_protected( $bc, 'build_for_singular' );

		$this->assertSame( [
			[ 'type' => 'item', 'title' => 'Blog', 'url' => 'https://example.test/blog/' ],
			[ 'type' => 'item', 'title' => 'My Article', 'url' => null ],
		], $result );
	}

	public function test_build_for_singular_hierarchical_cpt_uses_ancestors(): void {
		Functions\when( 'get_post_type' )->justReturn( 'project' );
		$post = (object) [ 'ID' => 70, 'post_title' => 'Project Z' ];
		Functions\when( 'get_queried_object' )->justReturn( $post );

		Functions\expect( 'is_post_type_hierarchical' )->with( 'project' )->andReturn( true );

		// by_menu_trail returns [] (no menu items)
		Functions\expect( 'get_nav_menu_locations' )->once()->andReturn( [] );
		Functions\expect( 'wp_get_nav_menu_items' )->once()->andReturn( [] );
		Functions\expect( 'get_queried_object_id' )->andReturn( 70 );
		\Brain\Monkey\Filters\expectApplied( 'wpml_object_id' )->andReturn( 70 );

		// Fallback to ancestors
		Functions\when( 'get_post' )->justReturn( $post );
		Functions\expect( 'get_post_ancestors' )->once()->with( $post )->andReturn( [ 60 ] );
		Functions\when( 'get_the_title' )->alias( fn( $id ) => $id === 60 ? 'Parent Project' : '' );
		Functions\when( 'get_permalink' )->alias( fn( $id = null ) => $id === 60 ? 'https://example.test/projects/parent/' : '' );

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'build_for_singular' );

		$this->assertSame( [
			[ 'type' => 'item', 'title' => 'Parent Project', 'url' => 'https://example.test/projects/parent/' ],
			[ 'type' => 'item', 'title' => 'Project Z', 'url' => null ],
		], $result );
	}

	public function test_build_for_singular_flat_cpt_just_title(): void {
		Functions\when( 'get_post_type' )->justReturn( 'team' );
		$post = (object) [ 'ID' => 80, 'post_title' => 'John Doe' ];
		Functions\when( 'get_queried_object' )->justReturn( $post );
		Functions\expect( 'is_post_type_hierarchical' )->with( 'team' )->andReturn( false );

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'build_for_singular' );

		$this->assertSame( [
			[ 'type' => 'item', 'title' => 'John Doe', 'url' => null ],
		], $result );
	}

	// -------------------------------------------------------------------
	// Hydrate
	// -------------------------------------------------------------------

	public function test_hydrate_resolves_all_types(): void {
		Functions\when( 'wp_date' )->justReturn( 'May' );

		$bc = new Breadcrumb([
			'labels' => [
				'home'       => 'Domů',
				'404'        => 'Nenalezeno',
				'search'     => 'Hledání: %s',
				'pagination' => 'Strana %d',
				'author'     => 'Autor: %s',
			],
		]);

		$items = [
			[ 'type' => 'home', 'url' => 'https://example.test/' ],
			[ 'type' => 'item', 'title' => 'About', 'url' => '/about/' ],
			[ 'type' => '404' ],
			[ 'type' => 'search', 'query' => 'foo', 'url' => '/?s=foo' ],
			[ 'type' => 'pagination', 'page' => 2, 'url' => '/page/1/' ],
			[ 'type' => 'author', 'display_name' => 'Jane', 'url' => '/author/jane/' ],
			[ 'type' => 'date_year', 'year' => 2024, 'url' => null ],
			[ 'type' => 'date_month', 'year' => 2024, 'month' => 5, 'url' => null ],
			[ 'type' => 'date_day', 'year' => 2024, 'month' => 5, 'day' => 15 ],
		];

		\Brain\Monkey\Filters\expectApplied( 'timber_kit_breadcrumb_labels' )
			->andReturnUsing( fn( $labels ) => $labels );

		$result = $this->invoke_protected( $bc, 'hydrate', [ $items ] );

		$this->assertSame( 'Domů', $result[0]['title'] );
		$this->assertSame( 'About', $result[1]['title'] );
		$this->assertSame( 'Nenalezeno', $result[2]['title'] );
		$this->assertSame( 'Hledání: foo', $result[3]['title'] );
		$this->assertSame( 'Strana 2', $result[4]['title'] );
		$this->assertSame( 'Autor: Jane', $result[5]['title'] );
		$this->assertSame( '2024', $result[6]['title'] );
		$this->assertSame( 'May', $result[7]['title'] );
		$this->assertSame( '15', $result[8]['title'] );
	}

	public function test_hydrate_falls_back_to_english_when_labels_missing(): void {
		$bc = new Breadcrumb();  // default English labels
		$items = [ [ 'type' => '404' ] ];

		\Brain\Monkey\Filters\expectApplied( 'timber_kit_breadcrumb_labels' )
			->andReturnUsing( fn( $labels ) => $labels );

		$result = $this->invoke_protected( $bc, 'hydrate', [ $items ] );
		$this->assertSame( 'Page not found', $result[0]['title'] );
	}

	public function test_hydrate_applies_labels_filter(): void {
		$bc = new Breadcrumb();
		$items = [ [ 'type' => 'home', 'url' => '/' ] ];

		\Brain\Monkey\Filters\expectApplied( 'timber_kit_breadcrumb_labels' )
			->once()
			->andReturnUsing( fn( $labels ) => array_merge( $labels, [ 'home' => 'Hauptseite' ] ) );

		$result = $this->invoke_protected( $bc, 'hydrate', [ $items ] );
		$this->assertSame( 'Hauptseite', $result[0]['title'] );
	}

	public function test_by_menu_trail_returns_ancestor_chain_with_current_filtered(): void {
		$menu_items = [
			(object) [
				'ID' => 1, 'object_id' => '10', 'menu_item_parent' => '0',
				'url' => 'https://example.test/', 'title' => 'Home',
			],
			(object) [
				'ID' => 2, 'object_id' => '20', 'menu_item_parent' => '1',
				'url' => 'https://example.test/services', 'title' => 'Services',
			],
			(object) [
				'ID' => 3, 'object_id' => '30', 'menu_item_parent' => '2',
				'url' => 'https://example.test/services/web', 'title' => 'Web',
			],
		];

		Functions\expect( 'get_nav_menu_locations' )->once()->andReturn( [] );
		Functions\expect( 'wp_get_nav_menu_items' )->once()->andReturn( $menu_items );
		Functions\expect( 'get_queried_object_id' )->once()->andReturn( 30 );
		\Brain\Monkey\Filters\expectApplied( 'wpml_object_id' )->andReturn( 30 );
		Functions\expect( 'get_permalink' )->andReturn( 'https://example.test/services/web' );

		$bc = new Breadcrumb();
		$result = $this->invoke_protected( $bc, 'by_menu_trail' );

		$this->assertCount( 2, $result );
		$this->assertSame( 'item', $result[0]['type'] );
		$this->assertSame( 'Home', $result[0]['title'] );
		$this->assertSame( 'Services', $result[1]['title'] );
	}

	// -------------------------------------------------------------------
	// Filter: menu_trail
	// -------------------------------------------------------------------

	public function test_by_menu_trail_applies_menu_trail_filter(): void {
		Functions\expect( 'get_nav_menu_locations' )->once()->andReturn( [] );
		Functions\expect( 'wp_get_nav_menu_items' )->once()->andReturn( [
			(object) [ 'ID' => 1, 'object_id' => '10', 'menu_item_parent' => '0',
				'url' => 'https://example.test/about/', 'title' => 'About' ],
		] );
		Functions\expect( 'get_queried_object_id' )->andReturn( 10 );
		\Brain\Monkey\Filters\expectApplied( 'wpml_object_id' )->andReturn( 10 );
		Functions\when( 'get_permalink' )->justReturn( 'https://elsewhere/' );

		\Brain\Monkey\Filters\expectApplied( 'timber_kit_breadcrumb_menu_trail' )
			->once()
			->andReturnUsing( function( $items, $menu_name ) {
				$items[0]['title'] = 'Modified ' . $items[0]['title'];
				return $items;
			} );

		$bc = new Breadcrumb([ 'menu_name' => 'main-menu' ]);
		$result = $this->invoke_protected( $bc, 'by_menu_trail' );

		$this->assertSame( 'Modified About', $result[0]['title'] );
	}

	// -------------------------------------------------------------------
	// Integration: get() dispatcher
	// -------------------------------------------------------------------

	public function test_get_dispatches_404_and_hydrates(): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_paged' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( true );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'is_date' )->justReturn( false );
		Functions\when( 'is_author' )->justReturn( false );
		Functions\when( 'is_post_type_archive' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\when( 'is_category' )->justReturn( false );
		Functions\when( 'is_tag' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://example.test/' );

		\Brain\Monkey\Filters\expectApplied( 'timber_kit_breadcrumb_skip' )->andReturn( false );
		\Brain\Monkey\Filters\expectApplied( 'timber_kit_breadcrumb_items' )->andReturnUsing( fn( $items ) => $items );
		\Brain\Monkey\Filters\expectApplied( 'timber_kit_breadcrumb_labels' )->andReturnUsing( fn( $labels ) => $labels );

		$bc = new Breadcrumb();
		$result = $bc->get();

		$this->assertCount( 2, $result );
		$this->assertSame( 'home', $result[0]['type'] );
		$this->assertSame( 'Home', $result[0]['title'] );
		$this->assertSame( '404', $result[1]['type'] );
		$this->assertSame( 'Page not found', $result[1]['title'] );
	}

	public function test_get_returns_empty_on_front_page(): void {
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_paged' )->justReturn( false );
		\Brain\Monkey\Filters\expectApplied( 'timber_kit_breadcrumb_skip' )->andReturn( false );

		$bc = new Breadcrumb();
		$this->assertSame( [], $bc->get() );
	}

	public function test_get_respects_skip_filter(): void {
		\Brain\Monkey\Filters\expectApplied( 'timber_kit_breadcrumb_skip' )->andReturn( true );

		$bc = new Breadcrumb();
		$this->assertSame( [], $bc->get() );
	}

	public function test_get_applies_items_filter_before_hydrate(): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_paged' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( true );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'is_date' )->justReturn( false );
		Functions\when( 'is_author' )->justReturn( false );
		Functions\when( 'is_post_type_archive' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\when( 'is_category' )->justReturn( false );
		Functions\when( 'is_tag' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://example.test/' );

		\Brain\Monkey\Filters\expectApplied( 'timber_kit_breadcrumb_skip' )->andReturn( false );
		\Brain\Monkey\Filters\expectApplied( 'timber_kit_breadcrumb_items' )
			->andReturnUsing( fn( $items ) => array_values( array_filter( $items, fn( $i ) => 'home' === $i['type'] ) ) );
		\Brain\Monkey\Filters\expectApplied( 'timber_kit_breadcrumb_labels' )->andReturnUsing( fn( $labels ) => $labels );

		$bc = new Breadcrumb();
		$result = $bc->get();

		$this->assertCount( 1, $result );
		$this->assertSame( 'home', $result[0]['type'] );
	}
}
