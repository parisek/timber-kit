<?php

declare(strict_types=1);

namespace Tests\Unit\Breeze;

use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\Breeze\TailPlanner;

/**
 * Covers the tail's pure arithmetic: what falls outside the cap, how it is
 * fingerprinted, and how batches are sliced off it.
 *
 * No Brain\Monkey — this must stay pure so the property test that pins the
 * batching invariant can live under the `tests/Property/` isolation rule.
 */
class TailPlannerTest extends TestCase {

	/**
	 * @param array<int, array{url: string, score: int}> $pairs
	 * @return array<int, array<string, mixed>>
	 */
	private function scored( array $pairs ): array {
		$records = array();
		foreach ( $pairs as $pair ) {
			$records[] = array( 'url' => $pair['url'], 'score' => $pair['score'] );
		}

		return $records;
	}

	// -- split --------------------------------------------------------------

	public function test_tail_is_what_the_cap_excluded(): void {
		$scored = $this->scored(
			array(
				array( 'url' => 'https://example.test/a/', 'score' => 900 ),
				array( 'url' => 'https://example.test/b/', 'score' => 500 ),
				array( 'url' => 'https://example.test/c/', 'score' => 100 ),
			)
		);

		$tail = TailPlanner::split( $scored, array( 'https://example.test/a/' ), 100 );

		$this->assertSame(
			array( 'https://example.test/b/', 'https://example.test/c/' ),
			$tail
		);
	}

	public function test_tail_keeps_the_order_it_was_given(): void {
		// split() never sorts. The caller hands it an already-sorted set, and
		// the tail's order IS the feature's promise — the most valuable pages
		// are warmed first even when the run is cut short.
		$scored = $this->scored(
			array(
				array( 'url' => 'https://example.test/high/', 'score' => 900 ),
				array( 'url' => 'https://example.test/mid/', 'score' => 500 ),
				array( 'url' => 'https://example.test/low/', 'score' => 10 ),
			)
		);

		$tail = TailPlanner::split( $scored, array(), 100 );

		$this->assertSame(
			array( 'https://example.test/high/', 'https://example.test/mid/', 'https://example.test/low/' ),
			$tail
		);
	}

	public function test_everything_kept_leaves_an_empty_tail(): void {
		$scored = $this->scored( array( array( 'url' => 'https://example.test/a/', 'score' => 1 ) ) );

		$this->assertSame( array(), TailPlanner::split( $scored, array( 'https://example.test/a/' ), 100 ) );
	}

	public function test_tail_is_capped(): void {
		$pairs = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$pairs[] = array( 'url' => 'https://example.test/' . $i . '/', 'score' => 10 - $i );
		}

		$tail = TailPlanner::split( $this->scored( $pairs ), array(), 4 );

		$this->assertCount( 4, $tail );
		$this->assertSame( 'https://example.test/0/', $tail[0], 'the cap trims the end, not the front' );
	}

	public function test_records_without_a_url_are_skipped(): void {
		$scored = array(
			array( 'score' => 5 ),
			array( 'url' => 'https://example.test/a/', 'score' => 1 ),
		);

		$this->assertSame( array( 'https://example.test/a/' ), TailPlanner::split( $scored, array(), 100 ) );
	}

	// -- hash ---------------------------------------------------------------

	public function test_hash_is_stable_for_the_same_urls(): void {
		$urls = array( 'https://example.test/a/', 'https://example.test/b/' );

		$this->assertSame( TailPlanner::hash( $urls ), TailPlanner::hash( $urls ) );
	}

	public function test_hash_changes_when_a_url_changes(): void {
		$a = array( 'https://example.test/a/' );
		$b = array( 'https://example.test/b/' );

		$this->assertNotSame( TailPlanner::hash( $a ), TailPlanner::hash( $b ) );
	}

	public function test_hash_changes_when_order_changes(): void {
		// Order is meaning here: a reordered tail is a different plan, and the
		// cursor pointing into it must be invalidated.
		$a = array( 'https://example.test/a/', 'https://example.test/b/' );
		$b = array( 'https://example.test/b/', 'https://example.test/a/' );

		$this->assertNotSame( TailPlanner::hash( $a ), TailPlanner::hash( $b ) );
	}

	public function test_empty_tail_hashes_without_error(): void {
		$this->assertNotSame( '', TailPlanner::hash( array() ) );
	}

	// -- nextBatch ----------------------------------------------------------

	public function test_batch_starts_at_the_index(): void {
		$urls = array( 'a', 'b', 'c', 'd', 'e' );

		$this->assertSame( array( 'c', 'd' ), TailPlanner::nextBatch( $urls, 2, 2 ) );
	}

	public function test_last_batch_may_be_short(): void {
		$urls = array( 'a', 'b', 'c' );

		$this->assertSame( array( 'c' ), TailPlanner::nextBatch( $urls, 2, 10 ) );
	}

	public function test_index_past_the_end_yields_nothing(): void {
		$this->assertSame( array(), TailPlanner::nextBatch( array( 'a' ), 5, 10 ) );
	}

	public function test_negative_index_reads_from_the_start(): void {
		// A corrupted cursor must not silently read from the end of the array,
		// which is what a raw array_slice() with a negative offset would do.
		$this->assertSame( array( 'a' ), TailPlanner::nextBatch( array( 'a', 'b' ), -3, 1 ) );
	}

	public function test_batch_of_zero_yields_nothing(): void {
		$this->assertSame( array(), TailPlanner::nextBatch( array( 'a', 'b' ), 0, 0 ) );
	}
}
