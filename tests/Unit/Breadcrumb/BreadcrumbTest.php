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
}
