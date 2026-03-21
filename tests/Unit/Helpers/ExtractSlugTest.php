<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

class ExtractSlugTest extends HelpersTestCase {

	/**
	 * When WPML is not active, function_exists returns false and
	 * the method should just extract the path from the URL.
	 */
	public function test_basic_path_extraction(): void {
		Functions\when( 'is_plugin_active' )->justReturn( false );

		$result = Helpers::extract_slug_from_url( 'https://example.com/about/team' );
		$this->assertSame( '/about/team', $result );
	}

	public function test_trailing_slash_removed(): void {
		Functions\when( 'is_plugin_active' )->justReturn( false );

		$result = Helpers::extract_slug_from_url( 'https://example.com/about/team/' );
		$this->assertSame( '/about/team', $result );
	}

	public function test_root_url(): void {
		Functions\when( 'is_plugin_active' )->justReturn( false );

		$result = Helpers::extract_slug_from_url( 'https://example.com/' );
		$this->assertSame( '', $result );
	}

	public function test_url_without_path(): void {
		Functions\when( 'is_plugin_active' )->justReturn( false );

		$result = Helpers::extract_slug_from_url( 'https://example.com' );
		$this->assertSame( '', $result );
	}

	public function test_deep_nested_path(): void {
		Functions\when( 'is_plugin_active' )->justReturn( false );

		$result = Helpers::extract_slug_from_url( 'https://example.com/a/b/c/d' );
		$this->assertSame( '/a/b/c/d', $result );
	}

	public function test_wpml_language_prefix_removed(): void {
		Functions\when( 'is_plugin_active' )->justReturn( true );
		Functions\when( 'function_exists' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias( function ( $filter, ...$args ) {
			if ( $filter === 'wpml_active_languages' ) {
				return [ 'cs' => [], 'en' => [], 'de' => [] ];
			}
			return $args[0] ?? null;
		} );

		$result = Helpers::extract_slug_from_url( 'https://example.com/cs/o-nas/tym' );
		$this->assertSame( '/o-nas/tym', $result );
	}

	public function test_wpml_language_only_path(): void {
		Functions\when( 'is_plugin_active' )->justReturn( true );
		Functions\when( 'function_exists' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias( function ( $filter, ...$args ) {
			if ( $filter === 'wpml_active_languages' ) {
				return [ 'cs' => [], 'en' => [] ];
			}
			return $args[0] ?? null;
		} );

		$result = Helpers::extract_slug_from_url( 'https://example.com/cs' );
		$this->assertSame( '', $result );
	}

	public function test_wpml_non_matching_prefix_kept(): void {
		Functions\when( 'is_plugin_active' )->justReturn( true );
		Functions\when( 'function_exists' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias( function ( $filter, ...$args ) {
			if ( $filter === 'wpml_active_languages' ) {
				return [ 'cs' => [], 'en' => [] ];
			}
			return $args[0] ?? null;
		} );

		$result = Helpers::extract_slug_from_url( 'https://example.com/about/team' );
		$this->assertSame( '/about/team', $result );
	}
}
