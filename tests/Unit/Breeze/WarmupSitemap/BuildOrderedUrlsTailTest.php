<?php

declare(strict_types=1);

namespace Tests\Unit\Breeze\WarmupSitemap;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\Breeze\Scorer;
use Parisek\TimberKit\Breeze\WarmupSitemap;

/**
 * Covers the tail that `buildOrderedUrls()` now returns alongside the capped
 * list.
 *
 * The ordering assertion here is the one that matters. Everything upstream
 * preserves input order — `Scorer::scoreAll()` only attaches scores, and
 * `LanguageQuota::apply()` has a test pinning that it does not reorder — so a
 * tail taken straight from them would come out in sitemap order, silently
 * undoing the whole point of prioritising.
 */
class BuildOrderedUrlsTailTest extends TestCase {

	private const NOW = 1000000000;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WarmupSitemap::reset_for_tests();
	}

	protected function tearDown(): void {
		WarmupSitemap::reset_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function record( string $url, array $overrides = array() ): array {
		return array_merge(
			array(
				'url'        => $url,
				'key'        => $url,
				'lastmod'    => null,
				'type'       => '',
				'lang'       => 'cs',
				'source'     => 'https://example.test/wp-sitemap.xml',
				'menu'       => false,
				'front_page' => false,
				'manual'     => false,
			),
			$overrides
		);
	}

	public function test_tail_is_sorted_by_score_not_sitemap_order(): void {
		// Deliberately fed in ascending score, i.e. the worst first — the order
		// a sitemap generator might well produce. The tail must come back
		// descending.
		$records = array(
			$this->record( 'https://example.test/worst/' ),
			$this->record( 'https://example.test/middle/', array( 'type' => 'post' ) ),
			$this->record( 'https://example.test/best/', array( 'menu' => true ) ),
		);

		$weights                   = Scorer::DEFAULT_WEIGHTS;
		$weights['types']['post']  = 50;

		$built = WarmupSitemap::buildOrderedUrls( $records, $weights, self::NOW, 0 );

		$this->assertSame(
			array(
				'https://example.test/middle/',
				'https://example.test/worst/',
			),
			$built['tail'],
			'the tail carries the feature promise: most valuable first'
		);
	}

	public function test_tail_excludes_everything_that_made_the_cap(): void {
		$records = array(
			$this->record( 'https://example.test/kept/', array( 'menu' => true ) ),
			$this->record( 'https://example.test/dropped/' ),
		);

		$built = WarmupSitemap::buildOrderedUrls( $records, Scorer::DEFAULT_WEIGHTS, self::NOW, 1 );

		$this->assertSame( array( 'https://example.test/kept/' ), $built['urls'] );
		$this->assertSame( array( 'https://example.test/dropped/' ), $built['tail'] );
	}

	public function test_tail_is_empty_when_everything_fits(): void {
		$records = array( $this->record( 'https://example.test/a/' ) );

		$built = WarmupSitemap::buildOrderedUrls( $records, Scorer::DEFAULT_WEIGHTS, self::NOW, 100 );

		$this->assertSame( array(), $built['tail'] );
	}

	public function test_urls_and_signals_are_unchanged(): void {
		// The existing contract must not move: this task adds a key, it does
		// not alter the two that were already there.
		$records = array( $this->record( 'https://example.test/a/', array( 'menu' => true ) ) );

		$built = WarmupSitemap::buildOrderedUrls( $records, Scorer::DEFAULT_WEIGHTS, self::NOW, 100 );

		$this->assertSame( array( 'https://example.test/a/' ), $built['urls'] );
		$this->assertArrayHasKey( 'https://example.test/a/', $built['signals'] );
	}
}
