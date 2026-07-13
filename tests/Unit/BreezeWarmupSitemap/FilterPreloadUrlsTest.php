<?php

declare(strict_types=1);

namespace Tests\Unit\BreezeWarmupSitemap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\BreezeWarmupSitemap;

/**
 * Covers `filterPreloadUrls()` — the `breeze_preload_urls` filter callback —
 * merge, dedup, and safety-cap behaviour. The sitemap side is fed via a
 * transient cache hit so these cases never touch the network.
 */
class FilterPreloadUrlsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		BreezeWarmupSitemap::reset_for_tests();
	}

	protected function tearDown(): void {
		BreezeWarmupSitemap::reset_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_merges_sitemap_urls_into_existing_list(): void {
		Functions\when( 'get_transient' )->justReturn(
			array( 'https://example.test/a/', 'https://example.test/b/' )
		);

		$result = BreezeWarmupSitemap::filterPreloadUrls( array( 'https://example.test/' ) );

		$this->assertSame(
			array( 'https://example.test/', 'https://example.test/a/', 'https://example.test/b/' ),
			$result
		);
	}

	public function test_dedupes_sitemap_urls_already_present(): void {
		Functions\when( 'get_transient' )->justReturn(
			array( 'https://example.test/', 'https://example.test/new/' )
		);

		$result = BreezeWarmupSitemap::filterPreloadUrls( array( 'https://example.test/' ) );

		$this->assertSame(
			array( 'https://example.test/', 'https://example.test/new/' ),
			$result
		);
	}

	public function test_non_array_input_is_treated_as_empty(): void {
		Functions\when( 'get_transient' )->justReturn( array( 'https://example.test/a/' ) );

		$result = BreezeWarmupSitemap::filterPreloadUrls( null );

		$this->assertSame( array( 'https://example.test/a/' ), $result );
	}

	public function test_caps_sitemap_urls_at_default_max(): void {
		$sitemapUrls = array();
		for ( $i = 0; $i < 250; $i++ ) {
			$sitemapUrls[] = "https://example.test/page-{$i}/";
		}
		Functions\when( 'get_transient' )->justReturn( $sitemapUrls );

		$result = BreezeWarmupSitemap::filterPreloadUrls( array() );

		$this->assertCount( 200, $result );
	}

	public function test_existing_urls_are_never_counted_against_the_cap(): void {
		$existing = array();
		for ( $i = 0; $i < 200; $i++ ) {
			$existing[] = "https://example.test/existing-{$i}/";
		}
		Functions\when( 'get_transient' )->justReturn( array( 'https://example.test/new/' ) );

		$result = BreezeWarmupSitemap::filterPreloadUrls( $existing );

		$this->assertCount( 201, $result );
		$this->assertContains( 'https://example.test/new/', $result );
	}

	public function test_max_urls_cap_is_filterable(): void {
		$sitemapUrls = array( 'https://example.test/a/', 'https://example.test/b/', 'https://example.test/c/' );
		Functions\when( 'get_transient' )->justReturn( $sitemapUrls );
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $default, ...$args ) {
				unset( $args );
				return 'timberkit_warmup_sitemap_max_urls' === $filter ? 2 : $default;
			}
		);

		$result = BreezeWarmupSitemap::filterPreloadUrls( array() );

		$this->assertCount( 2, $result );
	}

	public function test_opted_out_returns_existing_list_unchanged(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $default, ...$args ) {
				unset( $args );
				return 'timberkit_warmup_sitemap_enabled' === $filter ? false : $default;
			}
		);
		Functions\expect( 'get_transient' )->never();

		$result = BreezeWarmupSitemap::filterPreloadUrls( array( 'https://example.test/' ) );

		$this->assertSame( array( 'https://example.test/' ), $result );
	}
}
