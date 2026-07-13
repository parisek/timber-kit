<?php

declare(strict_types=1);

namespace Tests\Unit\BreezeWarmupSitemap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\BreezeWarmupSitemap;

/**
 * Covers `fetchSitemapUrls()`: AIOSEO-first source resolution, sitemap-index
 * recursion (with its depth/count bounds), same-host filtering, and the
 * silent-degrade contract on any transport or parsing failure.
 *
 * Runs each test in its own process because some cases declare the global
 * `aioseo()` function to simulate an active AIOSEO install — a PHP function,
 * once declared, cannot be undeclared within the same process.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class FetchSitemapUrlsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		BreezeWarmupSitemap::reset_for_tests();
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( $r ) => $r['response']['code'] ?? 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] ?? '' );
	}

	protected function tearDown(): void {
		BreezeWarmupSitemap::reset_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_uses_core_wp_sitemap_when_aioseo_is_absent(): void {
		Functions\when( 'home_url' )->alias( fn( $path = '' ) => 'https://example.test' . $path );

		$requested = array();
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url ) use ( &$requested ) {
				$requested[] = $url;
				return Fixtures::response( Fixtures::urlset( array( 'https://example.test/page/' ) ) );
			}
		);

		$result = BreezeWarmupSitemap::fetchSitemapUrls();

		$this->assertSame( array( 'https://example.test/wp-sitemap.xml' ), $requested );
		$this->assertSame( array( 'https://example.test/page/' ), $result );
	}

	public function test_prefers_aioseo_sitemap_when_active(): void {
		if ( ! function_exists( 'aioseo' ) ) {
			// eval() here is a test-only trick to declare a global function with
			// a literal, hardcoded body (no external/user input involved) — PHP
			// has no other way to conditionally declare a top-level function.
			// Safe because: (1) the string is a fixed literal, not derived from
			// any input; (2) the class is isolated with RunTestsInSeparateProcesses
			// so the declaration never leaks into other test files.
			eval( 'function aioseo() { return true; }' );
		}
		Functions\when( 'home_url' )->alias( fn( $path = '' ) => 'https://example.test' . $path );

		$requested = array();
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url ) use ( &$requested ) {
				$requested[] = $url;
				return Fixtures::response( Fixtures::urlset( array( 'https://example.test/aioseo-page/' ) ) );
			}
		);

		$result = BreezeWarmupSitemap::fetchSitemapUrls();

		$this->assertSame( array( 'https://example.test/sitemap.xml' ), $requested );
		$this->assertSame( array( 'https://example.test/aioseo-page/' ), $result );
	}

	public function test_recurses_into_sitemap_index_entries(): void {
		Functions\when( 'home_url' )->alias( fn( $path = '' ) => 'https://example.test' . $path );

		Functions\when( 'wp_remote_get' )->alias(
			function ( $url ) {
				return match ( $url ) {
					'https://example.test/wp-sitemap.xml' => Fixtures::response(
						Fixtures::sitemapIndex(
							array(
								'https://example.test/wp-sitemap-posts.xml',
								'https://example.test/wp-sitemap-pages.xml',
							)
						)
					),
					'https://example.test/wp-sitemap-posts.xml' => Fixtures::response(
						Fixtures::urlset( array( 'https://example.test/post-1/' ) )
					),
					'https://example.test/wp-sitemap-pages.xml' => Fixtures::response(
						Fixtures::urlset( array( 'https://example.test/page-1/' ) )
					),
					default => Fixtures::response( '', 404 ),
				};
			}
		);

		$result = BreezeWarmupSitemap::fetchSitemapUrls();

		sort( $result );
		$this->assertSame(
			array( 'https://example.test/page-1/', 'https://example.test/post-1/' ),
			$result
		);
	}

	public function test_foreign_host_urls_are_dropped(): void {
		Functions\when( 'home_url' )->alias( fn( $path = '' ) => 'https://example.test' . $path );
		Functions\when( 'wp_remote_get' )->justReturn(
			Fixtures::response(
				Fixtures::urlset(
					array( 'https://example.test/local/', 'https://evil.test/phishing/' )
				)
			)
		);

		$result = BreezeWarmupSitemap::fetchSitemapUrls();

		$this->assertSame( array( 'https://example.test/local/' ), $result );
	}

	public function test_malformed_xml_degrades_to_empty_array(): void {
		Functions\when( 'home_url' )->alias( fn( $path = '' ) => 'https://example.test' . $path );
		Functions\when( 'wp_remote_get' )->justReturn( Fixtures::response( '<not><valid' ) );

		$result = BreezeWarmupSitemap::fetchSitemapUrls();

		$this->assertSame( array(), $result );
	}

	public function test_wp_error_response_degrades_to_empty_array(): void {
		Functions\when( 'home_url' )->alias( fn( $path = '' ) => 'https://example.test' . $path );
		Functions\when( 'wp_remote_get' )->justReturn( 'error-marker' );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$result = BreezeWarmupSitemap::fetchSitemapUrls();

		$this->assertSame( array(), $result );
	}

	public function test_non_200_response_degrades_to_empty_array(): void {
		Functions\when( 'home_url' )->alias( fn( $path = '' ) => 'https://example.test' . $path );
		Functions\when( 'wp_remote_get' )->justReturn( Fixtures::response( '', 500 ) );

		$result = BreezeWarmupSitemap::fetchSitemapUrls();

		$this->assertSame( array(), $result );
	}

	public function test_missing_home_url_degrades_to_empty_array(): void {
		// No `home_url` mock registered at all — simulates a non-WP load
		// context where the function is entirely undefined.
		$result = BreezeWarmupSitemap::fetchSitemapUrls();

		$this->assertSame( array(), $result );
	}

	public function test_self_referencing_sitemap_index_does_not_loop(): void {
		Functions\when( 'home_url' )->alias( fn( $path = '' ) => 'https://example.test' . $path );

		$calls = 0;
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url ) use ( &$calls ) {
				++$calls;
				// The index points back at itself — the cycle guard must
				// stop after the first fetch instead of recursing forever.
				return Fixtures::response(
					Fixtures::sitemapIndex( array( 'https://example.test/wp-sitemap.xml' ) )
				);
			}
		);

		$result = BreezeWarmupSitemap::fetchSitemapUrls();

		$this->assertSame( array(), $result );
		$this->assertSame( 1, $calls );
	}

	public function test_index_of_index_of_index_is_not_followed_past_max_depth(): void {
		// MAX_DEPTH=2 bounds the chain of *index* documents, not urlsets: a
		// root index pointing at per-post-type sub-sitemaps that are
		// themselves indexes (2 levels of index-following) still resolves,
		// matching AIOSEO's index -> per-type-urlset shape with headroom for
		// one extra index level. A fourth level (index of index of index)
		// is where the guard fires.
		Functions\when( 'home_url' )->alias( fn( $path = '' ) => 'https://example.test' . $path );

		Functions\when( 'wp_remote_get' )->alias(
			function ( $url ) {
				return match ( $url ) {
					'https://example.test/wp-sitemap.xml' => Fixtures::response(
						Fixtures::sitemapIndex( array( 'https://example.test/level-2.xml' ) )
					),
					'https://example.test/level-2.xml' => Fixtures::response(
						Fixtures::sitemapIndex( array( 'https://example.test/level-3.xml' ) )
					),
					'https://example.test/level-3.xml' => Fixtures::response(
						Fixtures::sitemapIndex( array( 'https://example.test/level-4.xml' ) )
					),
					'https://example.test/level-4.xml' => Fixtures::response(
						Fixtures::urlset( array( 'https://example.test/too-deep/' ) )
					),
					default => Fixtures::response( '', 404 ),
				};
			}
		);

		$result = BreezeWarmupSitemap::fetchSitemapUrls();

		// level-4.xml is never fetched: depth 0 (root index) -> depth 1
		// (level-2, an index) -> depth 2 (level-3, an index) is where
		// collectFromIndex bails (depth >= MAX_DEPTH) before dereferencing
		// level-3's own <sitemap><loc> entries.
		$this->assertSame( array(), $result );
	}

	public function test_index_pointing_at_urlset_two_levels_deep_still_resolves(): void {
		Functions\when( 'home_url' )->alias( fn( $path = '' ) => 'https://example.test' . $path );

		Functions\when( 'wp_remote_get' )->alias(
			function ( $url ) {
				return match ( $url ) {
					'https://example.test/wp-sitemap.xml' => Fixtures::response(
						Fixtures::sitemapIndex( array( 'https://example.test/level-2.xml' ) )
					),
					'https://example.test/level-2.xml' => Fixtures::response(
						Fixtures::sitemapIndex( array( 'https://example.test/level-3.xml' ) )
					),
					'https://example.test/level-3.xml' => Fixtures::response(
						Fixtures::urlset( array( 'https://example.test/found-it/' ) )
					),
					default => Fixtures::response( '', 404 ),
				};
			}
		);

		$result = BreezeWarmupSitemap::fetchSitemapUrls();

		$this->assertSame( array( 'https://example.test/found-it/' ), $result );
	}

	public function test_subsitemap_count_is_capped(): void {
		Functions\when( 'home_url' )->alias( fn( $path = '' ) => 'https://example.test' . $path );

		$subLocs = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$subLocs[] = "https://example.test/sub-{$i}.xml";
		}

		Functions\when( 'wp_remote_get' )->alias(
			function ( $url ) use ( $subLocs ) {
				if ( 'https://example.test/wp-sitemap.xml' === $url ) {
					return Fixtures::response( Fixtures::sitemapIndex( $subLocs ) );
				}
				return Fixtures::response( Fixtures::urlset( array( $url . '-page/' ) ) );
			}
		);

		$result = BreezeWarmupSitemap::fetchSitemapUrls();

		// MAX_SUBSITEMAPS = 50, so only the first 50 of the 60 sub-sitemaps
		// are followed.
		$this->assertCount( 50, $result );
	}
}
