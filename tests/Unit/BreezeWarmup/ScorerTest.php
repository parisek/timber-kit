<?php

declare(strict_types=1);

namespace Tests\Unit\BreezeWarmup;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\BreezeWarmup\Scorer;

/**
 * Covers the scoring core.
 *
 * Pure by design: no Brain\Monkey, no WordPress. The property test in
 * `tests/Property/` relies on that purity, and the AGENTS.md isolation
 * convention forbids reaching for `Functions\when()` there.
 */
class ScorerTest extends TestCase {

	private const NOW = 1000000000;

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function record( array $overrides = array() ): array {
		return array_merge(
			array(
				'url'        => 'https://example.test/a/',
				'key'        => 'https://example.test/a/',
				'lastmod'    => null,
				'type'       => '',
				'lang'       => 'cs',
				'menu'       => false,
				'front_page' => false,
				'manual'     => false,
			),
			$overrides
		);
	}

	// -- freshness buckets --------------------------------------------------

	/**
	 * @return array<string, array{int, int}>
	 */
	public static function freshnessCases(): array {
		$day = 86400;

		return array(
			'today'                 => array( 0, 300 ),
			'just under two days'   => array( 2 * $day - 1, 300 ),
			'exactly two days'      => array( 2 * $day, 200 ),
			'just under seven days' => array( 7 * $day - 1, 200 ),
			'exactly seven days'    => array( 7 * $day, 100 ),
			'exactly thirty days'   => array( 30 * $day, 25 ),
			'exactly a year'        => array( 365 * $day, 0 ),
			'ancient'               => array( 4000 * $day, 0 ),
		);
	}

	#[DataProvider('freshnessCases')]
	public function test_freshness_buckets( int $ageSeconds, int $expected ): void {
		$this->assertSame(
			$expected,
			Scorer::freshness( self::NOW - $ageSeconds, self::NOW, Scorer::DEFAULT_WEIGHTS['freshness'] )
		);
	}

	public function test_missing_lastmod_scores_zero(): void {
		$this->assertSame( 0, Scorer::freshness( null, self::NOW, Scorer::DEFAULT_WEIGHTS['freshness'] ) );
	}

	public function test_future_lastmod_scores_zero(): void {
		// Scheduled content is not fresh content, and a broken lastmod must
		// never be able to shoot a URL to the front of the queue.
		$this->assertSame(
			0,
			Scorer::freshness( self::NOW + 86400, self::NOW, Scorer::DEFAULT_WEIGHTS['freshness'] )
		);
	}

	// -- score composition --------------------------------------------------

	public function test_front_page_weight(): void {
		$score = Scorer::score( $this->record( array( 'front_page' => true ) ), Scorer::DEFAULT_WEIGHTS );

		$this->assertSame( 1000, $score );
	}

	public function test_manual_weight(): void {
		$this->assertSame( 800, Scorer::score( $this->record( array( 'manual' => true ) ), Scorer::DEFAULT_WEIGHTS ) );
	}

	public function test_menu_weight(): void {
		$this->assertSame( 500, Scorer::score( $this->record( array( 'menu' => true ) ), Scorer::DEFAULT_WEIGHTS ) );
	}

	public function test_type_weight_comes_from_the_map(): void {
		$weights                    = Scorer::DEFAULT_WEIGHTS;
		$weights['types']['realizace'] = 50;

		$this->assertSame( 50, Scorer::score( $this->record( array( 'type' => 'realizace' ) ), $weights ) );
	}

	public function test_unknown_type_scores_zero(): void {
		$this->assertSame( 0, Scorer::score( $this->record( array( 'type' => 'nonesuch' ) ), Scorer::DEFAULT_WEIGHTS ) );
	}

	public function test_components_add_up(): void {
		// A fresh page in a menu must outrank a stale page in a menu — that is
		// why the model sums instead of taking a maximum.
		$record = $this->record(
			array(
				'menu'    => true,
				'lastmod' => self::NOW,
			)
		);

		$this->assertSame( 800, Scorer::scoreAll( array( $record ), Scorer::DEFAULT_WEIGHTS, self::NOW )[0]['score'] );
	}

	// -- sorting ------------------------------------------------------------

	public function test_sorts_descending_by_score(): void {
		$records = array(
			array_merge( $this->record( array( 'url' => 'https://example.test/low/' ) ), array( 'score' => 10 ) ),
			array_merge( $this->record( array( 'url' => 'https://example.test/high/' ) ), array( 'score' => 900 ) ),
		);

		$sorted = Scorer::sort( $records );

		$this->assertSame( 'https://example.test/high/', $sorted[0]['url'] );
	}

	public function test_ties_keep_input_order(): void {
		// Stable ordering preserves the sitemap's own date ordering as a free
		// last resort. AIOSEO emits newest-first inside each sub-sitemap.
		$records = array();
		foreach ( array( 'a', 'b', 'c', 'd' ) as $slug ) {
			$records[] = array_merge(
				$this->record( array( 'url' => 'https://example.test/' . $slug . '/' ) ),
				array( 'score' => 100 )
			);
		}

		$sorted = array_column( Scorer::sort( $records ), 'url' );

		$this->assertSame(
			array(
				'https://example.test/a/',
				'https://example.test/b/',
				'https://example.test/c/',
				'https://example.test/d/',
			),
			$sorted
		);
	}

	// -- weights hash -------------------------------------------------------

	public function test_weights_hash_is_stable_across_key_order(): void {
		$a = array( 'menu' => 500, 'manual' => 800 );
		$b = array( 'manual' => 800, 'menu' => 500 );

		$this->assertSame( Scorer::weightsHash( $a ), Scorer::weightsHash( $b ) );
	}

	public function test_weights_hash_changes_with_a_value(): void {
		$a = array( 'menu' => 500 );
		$b = array( 'menu' => 501 );

		$this->assertNotSame( Scorer::weightsHash( $a ), Scorer::weightsHash( $b ) );
	}
}
