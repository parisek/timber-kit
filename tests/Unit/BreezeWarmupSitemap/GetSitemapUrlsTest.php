<?php

declare(strict_types=1);

namespace Tests\Unit\BreezeWarmupSitemap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\BreezeWarmupSitemap;

/**
 * Covers `getSitemapUrls()`'s transient cache: hit short-circuits the fetch,
 * miss fetches then stores the result.
 */
class GetSitemapUrlsTest extends TestCase {

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

	public function test_cache_hit_returns_cached_urls_without_fetching(): void {
		Functions\when( 'get_transient' )->justReturn( array( 'https://example.test/cached/' ) );
		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'set_transient' )->never();

		$result = BreezeWarmupSitemap::getSitemapUrls();

		$this->assertSame( array( 'https://example.test/cached/' ), $result );
	}

	public function test_cache_miss_fetches_and_stores_result(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://example.test/sitemap.xml' );
		Functions\when( 'wp_remote_get' )->justReturn(
			Fixtures::response( Fixtures::urlset( array( 'https://example.test/page/' ) ) )
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( $r ) => $r['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );
		Functions\expect( 'set_transient' )->once()->with(
			'timber_kit_breeze_warmup_sitemap_urls',
			array( 'https://example.test/page/' ),
			3600
		)->andReturn( true );

		$result = BreezeWarmupSitemap::getSitemapUrls();

		$this->assertSame( array( 'https://example.test/page/' ), $result );
	}

	public function test_non_array_cache_entries_are_filtered_out(): void {
		Functions\when( 'get_transient' )->justReturn( array( 'https://example.test/a/', 42, null, 'https://example.test/b/' ) );

		$result = BreezeWarmupSitemap::getSitemapUrls();

		$this->assertSame( array( 'https://example.test/a/', 'https://example.test/b/' ), $result );
	}
}
