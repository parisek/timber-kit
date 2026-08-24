<?php

declare(strict_types=1);

namespace Tests\Property\Breeze;

use Eris\Generator;
use Parisek\TimberKit\Breeze\Scorer;
use Tests\Property\Support\PropertyTestCase;

/**
 * Sorting must be a permutation: for any input, every URL that went in comes
 * out exactly once, and nothing else appears.
 *
 * This is the invariant the whole module rests on — a reordering bug that
 * silently drops URLs would look like a working feature while leaving pages
 * cold. `Scorer::sort()` is pure, so it belongs in the Property suite under
 * the AGENTS.md isolation rule.
 */
class ScorerSortTest extends PropertyTestCase {

	public function test_sorting_is_a_permutation(): void {
		$this->forAll( Generator\seq( Generator\nat() ) )
			->then( function ( array $scores ): void {
				$records = array();
				foreach ( array_values( $scores ) as $i => $score ) {
					$records[] = array(
						'url'   => 'https://example.test/' . $i . '/',
						'score' => $score,
					);
				}

				$sorted = Scorer::sort( $records );

				$this->assertCount( count( $records ), $sorted );

				$in  = array_column( $records, 'url' );
				$out = array_column( $sorted, 'url' );
				sort( $in );
				sort( $out );
				$this->assertSame( $in, $out );
			} );
	}

	public function test_sorting_never_increases_score_going_down_the_list(): void {
		$this->forAll( Generator\seq( Generator\nat() ) )
			->then( function ( array $scores ): void {
				$records = array();
				foreach ( array_values( $scores ) as $i => $score ) {
					$records[] = array(
						'url'   => 'https://example.test/' . $i . '/',
						'score' => $score,
					);
				}

				$sorted = array_column( Scorer::sort( $records ), 'score' );

				for ( $i = 1, $n = count( $sorted ); $i < $n; $i++ ) {
					$this->assertLessThanOrEqual( $sorted[ $i - 1 ], $sorted[ $i ] );
				}
			} );
	}
}
