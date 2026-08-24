<?php

declare(strict_types=1);

namespace Tests\Unit\BreezeWarmupSitemap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\BreezeWarmupSitemap;

/**
 * Covers `fetchSitemapRecords()` — the structured replacement for
 * `fetchSitemapUrls()`.
 *
 * The provenance of a URL (which sub-sitemap it came from) is what carries
 * the post type, so the recursion has to hand that name down rather than
 * flattening it away as the string-only version did.
 */
class FetchSitemapRecordsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		BreezeWarmupSitemap::reset_for_tests();
		Functions\when( 'home_url' )->alias(
			static fn( string $path = '' ): string => 'https://example.test' . $path
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
	}

	protected function tearDown(): void {
		BreezeWarmupSitemap::reset_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, string> $bodies URL => body
	 */
	private function serve( array $bodies ): void {
		Functions\when( 'wp_remote_get' )->alias(
			static fn( string $url ): array => array( 'body' => $bodies[ $url ] ?? '' )
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( array $r ): string => (string) $r['body']
		);
	}

	public function test_reads_lastmod_into_a_timestamp(): void {
		$this->serve(
			array(
				'https://example.test/wp-sitemap.xml' => Fixtures::urlsetWithLastmod(
					array( 'https://example.test/a/' => '2026-08-01T10:00:00+00:00' )
				),
			)
		);

		$records = BreezeWarmupSitemap::fetchSitemapRecords();

		$this->assertCount( 1, $records );
		$this->assertSame( strtotime( '2026-08-01T10:00:00+00:00' ), $records[0]['lastmod'] );
	}

	public function test_missing_lastmod_is_null(): void {
		$this->serve(
			array(
				'https://example.test/wp-sitemap.xml' => Fixtures::urlset( array( 'https://example.test/a/' ) ),
			)
		);

		$this->assertNull( BreezeWarmupSitemap::fetchSitemapRecords()[0]['lastmod'] );
	}

	public function test_unparseable_lastmod_is_null(): void {
		$this->serve(
			array(
				'https://example.test/wp-sitemap.xml' => Fixtures::urlsetWithLastmod(
					array( 'https://example.test/a/' => 'not a date' )
				),
			)
		);

		$this->assertNull( BreezeWarmupSitemap::fetchSitemapRecords()[0]['lastmod'] );
	}

	public function test_post_type_comes_from_the_sub_sitemap_name(): void {
		$this->serve(
			array(
				'https://example.test/wp-sitemap.xml' => Fixtures::sitemapIndex(
					array( 'https://example.test/wp-sitemap-posts-realizace-1.xml' )
				),
				'https://example.test/wp-sitemap-posts-realizace-1.xml' => Fixtures::urlset(
					array( 'https://example.test/realizace/a/' )
				),
			)
		);

		$this->assertSame( 'realizace', BreezeWarmupSitemap::fetchSitemapRecords()[0]['type'] );
	}

	public function test_record_carries_a_canonical_key(): void {
		$this->serve(
			array(
				'https://example.test/wp-sitemap.xml' => Fixtures::urlset( array( 'https://example.test/a' ) ),
			)
		);

		$record = BreezeWarmupSitemap::fetchSitemapRecords()[0];

		$this->assertSame( 'https://example.test/a', $record['url'], 'the original URL is what Breeze must warm' );
		$this->assertSame( 'https://example.test/a/', $record['key'] );
	}

	public function test_legacy_string_api_still_works(): void {
		$this->serve(
			array(
				'https://example.test/wp-sitemap.xml' => Fixtures::urlset(
					array( 'https://example.test/a/', 'https://example.test/b/' )
				),
			)
		);

		$this->assertSame(
			array( 'https://example.test/a/', 'https://example.test/b/' ),
			BreezeWarmupSitemap::fetchSitemapUrls()
		);
	}
}
