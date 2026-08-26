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

	public function test_a_curated_url_inside_the_cap_never_reaches_the_tail(): void {
		// The two features meet here, and the question they raise is whether the
		// tail should exclude curated entries. It must not, and it also does not
		// have to: the split subtracts everything already kept, so no URL can be
		// in both lists. Double warming is impossible by construction rather
		// than by an exclusion someone has to remember.
		$records = array(
			$this->record( 'https://example.test/curated/', array( 'manual' => true, 'source' => 'curated' ) ),
			$this->record( 'https://example.test/ordinary/' ),
		);

		$built = WarmupSitemap::buildOrderedUrls( $records, Scorer::DEFAULT_WEIGHTS, self::NOW, 1 );

		$this->assertSame( array( 'https://example.test/curated/' ), $built['urls'] );
		$this->assertNotContains( 'https://example.test/curated/', $built['tail'] );
	}

	public function test_a_curated_url_pushed_past_the_cap_appears_in_the_tail_exactly_once(): void {
		// The case an exclusion would break. A curated entry earns the manual
		// weight and nothing else -- no freshness, no type -- so an ordinary
		// menu page with a types weight outranks it and pushes it out. Dropping
		// it from the tail as well would leave the page a project explicitly
		// named as the only one warmed by nobody.
		$weights                  = Scorer::DEFAULT_WEIGHTS;
		$weights['types']['post'] = 600;

		$records = array(
			$this->record( 'https://example.test/curated/', array( 'manual' => true, 'source' => 'curated' ) ),
			$this->record( 'https://example.test/hot/', array( 'menu' => true, 'type' => 'post' ) ),
		);

		$built = WarmupSitemap::buildOrderedUrls( $records, $weights, self::NOW, 1 );

		$this->assertSame( array( 'https://example.test/hot/' ), $built['urls'] );
		$this->assertSame( array( 'https://example.test/curated/' ), $built['tail'] );
	}

	public function test_head_and_tail_together_cover_every_record_without_repeating_one(): void {
		// The invariant the two features share. Neither list is meaningful on
		// its own: the head promises the most valuable pages are warm now, the
		// tail promises the rest follow, and together they must be the whole
		// set with nothing counted twice.
		$records = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$records[] = $this->record( "https://example.test/p{$i}/" );
		}
		$records[] = $this->record( 'https://example.test/curated/', array( 'manual' => true, 'source' => 'curated' ) );

		$built = WarmupSitemap::buildOrderedUrls( $records, Scorer::DEFAULT_WEIGHTS, self::NOW, 5 );

		$all = array_merge( $built['urls'], $built['tail'] );

		$this->assertCount( 13, $all, 'every record is placed' );
		$this->assertSame( $all, array_unique( $all ), 'and none is placed twice' );
		$this->assertCount( 5, $built['urls'] );
	}

	public function test_a_curated_only_set_still_produces_a_tail(): void {
		// The sitemap can be unreachable -- that is the failure #142 was about
		// -- and the curated list is then the only source of records. The tail
		// has to work from it alone, or the drain silently covers nothing on
		// exactly the site that needs it most.
		$records = array(
			$this->record( 'https://example.test/a/', array( 'manual' => true, 'source' => 'curated' ) ),
			$this->record( 'https://example.test/b/', array( 'manual' => true, 'source' => 'curated' ) ),
		);

		$built = WarmupSitemap::buildOrderedUrls( $records, Scorer::DEFAULT_WEIGHTS, self::NOW, 1 );

		$this->assertCount( 1, $built['urls'] );
		$this->assertCount( 1, $built['tail'] );
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
