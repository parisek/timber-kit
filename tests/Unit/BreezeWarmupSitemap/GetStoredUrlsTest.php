<?php

declare(strict_types=1);

namespace Tests\Unit\BreezeWarmupSitemap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\BreezeWarmupSitemap;

/**
 * Covers `getStoredUrls()` — the read-only accessor over the last-known-good
 * option payload. Never touches the network or schedules anything.
 */
class GetStoredUrlsTest extends TestCase {

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

	public function test_returns_urls_from_stored_option(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'urls' => array( 'https://example.test/a/' ), 'fetched_at' => time() )
		);

		$this->assertSame( array( 'https://example.test/a/' ), BreezeWarmupSitemap::getStoredUrls() );
	}

	public function test_returns_empty_array_when_option_missing(): void {
		Functions\when( 'get_option' )->justReturn( null );

		$this->assertSame( array(), BreezeWarmupSitemap::getStoredUrls() );
	}

	public function test_returns_empty_array_when_option_is_not_an_array(): void {
		Functions\when( 'get_option' )->justReturn( 'not-an-array' );

		$this->assertSame( array(), BreezeWarmupSitemap::getStoredUrls() );
	}

	public function test_returns_empty_array_when_fetched_at_is_missing(): void {
		Functions\when( 'get_option' )->justReturn( array( 'urls' => array( 'https://example.test/a/' ) ) );

		$this->assertSame( array(), BreezeWarmupSitemap::getStoredUrls() );
	}

	public function test_returns_empty_array_when_urls_key_is_not_an_array(): void {
		Functions\when( 'get_option' )->justReturn( array( 'urls' => 'oops', 'fetched_at' => time() ) );

		$this->assertSame( array(), BreezeWarmupSitemap::getStoredUrls() );
	}

	public function test_filters_out_non_string_url_entries(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'urls'       => array( 'https://example.test/a/', 42, null, 'https://example.test/b/' ),
				'fetched_at' => time(),
			)
		);

		$this->assertSame(
			array( 'https://example.test/a/', 'https://example.test/b/' ),
			BreezeWarmupSitemap::getStoredUrls()
		);
	}
}
