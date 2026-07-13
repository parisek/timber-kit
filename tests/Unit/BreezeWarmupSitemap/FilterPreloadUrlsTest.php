<?php

declare(strict_types=1);

namespace Tests\Unit\BreezeWarmupSitemap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\BreezeWarmupSitemap;

/**
 * Covers `filterPreloadUrls()` — the `breeze_preload_urls` filter callback.
 *
 * Two concerns, kept separate:
 * - merge/dedup/cap behaviour, fed via a *fresh* stored option so no refresh
 *   is scheduled and the network is never touched;
 * - the last-known-good + deferred-refresh contract itself: a missing or
 *   stale stored list must never trigger a live fetch from inside the
 *   filter callback — only a `wp_schedule_single_event()` job, guarded by
 *   the short lock, and the callback still returns immediately either way.
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

	/**
	 * @param string[] $urls
	 * @return array{urls: string[], fetched_at: int}
	 */
	private function freshStoredData( array $urls ): array {
		return array(
			'urls'       => $urls,
			'fetched_at' => time(),
		);
	}

	// -- merge / dedup / cap, fed via a fresh (non-stale) stored option -----

	public function test_merges_sitemap_urls_into_existing_list(): void {
		Functions\when( 'get_option' )->justReturn(
			$this->freshStoredData( array( 'https://example.test/a/', 'https://example.test/b/' ) )
		);

		$result = BreezeWarmupSitemap::filterPreloadUrls( array( 'https://example.test/' ) );

		$this->assertSame(
			array( 'https://example.test/', 'https://example.test/a/', 'https://example.test/b/' ),
			$result
		);
	}

	public function test_dedupes_sitemap_urls_already_present(): void {
		Functions\when( 'get_option' )->justReturn(
			$this->freshStoredData( array( 'https://example.test/', 'https://example.test/new/' ) )
		);

		$result = BreezeWarmupSitemap::filterPreloadUrls( array( 'https://example.test/' ) );

		$this->assertSame(
			array( 'https://example.test/', 'https://example.test/new/' ),
			$result
		);
	}

	public function test_non_array_input_is_treated_as_empty(): void {
		Functions\when( 'get_option' )->justReturn( $this->freshStoredData( array( 'https://example.test/a/' ) ) );

		$result = BreezeWarmupSitemap::filterPreloadUrls( null );

		$this->assertSame( array( 'https://example.test/a/' ), $result );
	}

	public function test_caps_sitemap_urls_at_default_max(): void {
		$sitemapUrls = array();
		for ( $i = 0; $i < 250; $i++ ) {
			$sitemapUrls[] = "https://example.test/page-{$i}/";
		}
		Functions\when( 'get_option' )->justReturn( $this->freshStoredData( $sitemapUrls ) );

		$result = BreezeWarmupSitemap::filterPreloadUrls( array() );

		$this->assertCount( 200, $result );
	}

	public function test_existing_urls_are_never_counted_against_the_cap(): void {
		$existing = array();
		for ( $i = 0; $i < 200; $i++ ) {
			$existing[] = "https://example.test/existing-{$i}/";
		}
		Functions\when( 'get_option' )->justReturn( $this->freshStoredData( array( 'https://example.test/new/' ) ) );

		$result = BreezeWarmupSitemap::filterPreloadUrls( $existing );

		$this->assertCount( 201, $result );
		$this->assertContains( 'https://example.test/new/', $result );
	}

	public function test_max_urls_cap_is_filterable(): void {
		$sitemapUrls = array( 'https://example.test/a/', 'https://example.test/b/', 'https://example.test/c/' );
		Functions\when( 'get_option' )->justReturn( $this->freshStoredData( $sitemapUrls ) );
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
		Functions\expect( 'get_option' )->never();

		$result = BreezeWarmupSitemap::filterPreloadUrls( array( 'https://example.test/' ) );

		$this->assertSame( array( 'https://example.test/' ), $result );
	}

	// -- last-known-good + deferred-refresh contract -------------------------

	public function test_missing_stored_data_never_fetches_live_and_schedules_a_refresh(): void {
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_remote_get' )->never();

		$setTransientCalls = array();
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) use ( &$setTransientCalls ) {
				$setTransientCalls[] = array( $key, $value, $ttl );
				return true;
			}
		);
		$scheduleCalls = array();
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook ) use ( &$scheduleCalls ) {
				$scheduleCalls[] = array( $timestamp, $hook );
				return true;
			}
		);

		$result = BreezeWarmupSitemap::filterPreloadUrls( array( 'https://example.test/' ) );

		$this->assertSame( array( 'https://example.test/' ), $result );
		$this->assertSame(
			array( array( 'timber_kit_breeze_warmup_sitemap_refresh_lock', 1, 60 ) ),
			$setTransientCalls
		);
		$this->assertCount( 1, $scheduleCalls );
		$this->assertSame( 'timber_kit_breeze_warmup_sitemap_refresh', $scheduleCalls[0][1] );
	}

	public function test_stale_stored_data_is_still_returned_while_a_refresh_is_scheduled(): void {
		$stale = array(
			'urls'       => array( 'https://example.test/stale-page/' ),
			'fetched_at' => time() - 7200, // 2h old, past the 1h TTL.
		);
		Functions\when( 'get_option' )->justReturn( $stale );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\expect( 'wp_remote_get' )->never();

		$scheduleCalls = 0;
		Functions\when( 'wp_schedule_single_event' )->alias(
			function () use ( &$scheduleCalls ) {
				++$scheduleCalls;
				return true;
			}
		);

		$result = BreezeWarmupSitemap::filterPreloadUrls( array() );

		$this->assertSame( array( 'https://example.test/stale-page/' ), $result );
		$this->assertSame( 1, $scheduleCalls );
	}

	public function test_pending_refresh_job_is_not_scheduled_twice(): void {
		Functions\when( 'get_option' )->justReturn( null );
		// A job is already on the cron schedule — no new lock/job should be created.
		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 30 );
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'wp_schedule_single_event' )->never();
		Functions\expect( 'wp_remote_get' )->never();

		$result = BreezeWarmupSitemap::filterPreloadUrls( array() );

		$this->assertSame( array(), $result );
	}
}
