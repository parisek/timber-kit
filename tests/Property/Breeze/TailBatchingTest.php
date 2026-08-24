<?php

declare(strict_types=1);

namespace Tests\Property\Breeze;

use Eris\Generator;
use Parisek\TimberKit\Breeze\TailPlanner;
use Tests\Property\Support\PropertyTestCase;

/**
 * Walking a tail with nextBatch() must visit every URL exactly once, in order.
 *
 * This is about the pure slicing function only. The concurrent case — two ticks
 * racing over one cursor — is pinned by TailStore's conditional write, which
 * needs WordPress and therefore cannot live here.
 */
class TailBatchingTest extends PropertyTestCase {

	public function test_walking_the_tail_visits_every_url_exactly_once(): void {
		$this->forAll( Generator\seq( Generator\nat() ), Generator\choose( 1, 10 ) )
			->then( function ( array $items, int $batch ): void {
				$urls = array();
				foreach ( array_values( $items ) as $i => $_ ) {
					$urls[] = 'https://example.test/' . $i . '/';
				}

				$seen  = array();
				$index = 0;
				while ( true ) {
					$slice = TailPlanner::nextBatch( $urls, $index, $batch );
					if ( array() === $slice ) {
						break;
					}
					foreach ( $slice as $url ) {
						$seen[] = $url;
					}
					$index += count( $slice );
				}

				$this->assertSame( $urls, $seen );
			} );
	}

	public function test_a_batch_never_reaches_past_the_end(): void {
		$this->forAll( Generator\seq( Generator\nat() ), Generator\choose( 1, 10 ), Generator\nat() )
			->then( function ( array $items, int $batch, int $index ): void {
				$urls = array();
				foreach ( array_values( $items ) as $i => $_ ) {
					$urls[] = 'https://example.test/' . $i . '/';
				}

				$slice = TailPlanner::nextBatch( $urls, $index, $batch );

				$this->assertLessThanOrEqual( $batch, count( $slice ) );
				$this->assertLessThanOrEqual( max( 0, count( $urls ) - $index ), count( $slice ) );
			} );
	}
}
