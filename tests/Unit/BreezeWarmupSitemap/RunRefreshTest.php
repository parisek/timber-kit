<?php

declare(strict_types=1);

namespace Tests\Unit\BreezeWarmupSitemap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\BreezeWarmupSitemap;

/**
 * Covers `runRefresh()` — the deferred cron job that does the actual crawl.
 * A successful, non-empty crawl replaces the stored last-known-good list; a
 * failed or empty crawl must leave whatever was previously stored untouched
 * (never overwrite valid data with an empty result). The refresh lock is
 * always released at the end, success or failure.
 */
class RunRefreshTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		BreezeWarmupSitemap::reset_for_tests();
		Functions\when( 'home_url' )->alias( fn( $path = '' ) => 'https://example.test' . $path );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( $r ) => $r['response']['code'] ?? 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] ?? '' );
	}

	protected function tearDown(): void {
		BreezeWarmupSitemap::reset_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_successful_refresh_stores_urls_and_releases_lock(): void {
		Functions\when( 'wp_remote_get' )->justReturn(
			Fixtures::response( Fixtures::urlset( array( 'https://example.test/fresh/' ) ) )
		);

		$updateOptionCalls = array();
		Functions\when( 'update_option' )->alias(
			function ( $key, $value, $autoload ) use ( &$updateOptionCalls ) {
				$updateOptionCalls[] = array( $key, $value, $autoload );
				return true;
			}
		);
		$deletedTransients = array();
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) use ( &$deletedTransients ) {
				$deletedTransients[] = $key;
				return true;
			}
		);

		BreezeWarmupSitemap::runRefresh();

		$this->assertCount( 1, $updateOptionCalls );
		[ $key, $value, $autoload ] = $updateOptionCalls[0];
		$this->assertSame( 'timber_kit_breeze_warmup_sitemap_urls', $key );
		$this->assertSame( array( 'https://example.test/fresh/' ), $value['urls'] );
		$this->assertIsInt( $value['fetched_at'] );
		$this->assertFalse( $autoload );
		$this->assertSame( array( 'timber_kit_breeze_warmup_sitemap_refresh_lock' ), $deletedTransients );
	}

	public function test_failed_crawl_does_not_overwrite_stored_data(): void {
		Functions\when( 'wp_remote_get' )->justReturn( 'error-marker' );
		Functions\when( 'is_wp_error' )->justReturn( true );

		Functions\expect( 'update_option' )->never();
		$deletedTransients = array();
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) use ( &$deletedTransients ) {
				$deletedTransients[] = $key;
				return true;
			}
		);

		BreezeWarmupSitemap::runRefresh();

		$this->assertSame( array( 'timber_kit_breeze_warmup_sitemap_refresh_lock' ), $deletedTransients );
	}

	public function test_empty_sitemap_result_does_not_overwrite_stored_data(): void {
		Functions\when( 'wp_remote_get' )->justReturn( Fixtures::response( Fixtures::urlset( array() ) ) );

		Functions\expect( 'update_option' )->never();
		$deletedTransients = array();
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) use ( &$deletedTransients ) {
				$deletedTransients[] = $key;
				return true;
			}
		);

		BreezeWarmupSitemap::runRefresh();

		$this->assertSame( array( 'timber_kit_breeze_warmup_sitemap_refresh_lock' ), $deletedTransients );
	}
}
