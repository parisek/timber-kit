<?php

declare(strict_types=1);

namespace Tests\Property\Resizer;

use Eris\Generator;
use Parisek\TimberKit\Resizer;
use Tests\Property\Support\PropertyTestCase;

/**
 * Property tests for the private Resizer::normalizeVariants() transformer.
 *
 * Input domain: list of indexed tuples (raw variant specs).
 * Output domain: list of associative dicts with five typed keys.
 * The domains differ, so classic idempotence (f(f(x))===f(x)) does not apply.
 * What does hold: type stability, ordering, count preservation, determinism.
 */
class NormalizeVariantsPropertyTest extends PropertyTestCase {

	/**
	 * Generates a single raw variant tuple of 0–5 fields, each a string that
	 * either represents a small non-negative integer or is empty.
	 */
	private function rawVariantGenerator(): \Eris\Generator {
		$numericString = Generator\oneOf(
			Generator\constant( '' ),
			Generator\map(
				fn ( int $n ) => (string) $n,
				Generator\choose( 0, 4000 )
			)
		);
		$styleString = Generator\elements( 'center', 'crop', 'scale', '' );

		return Generator\bind(
			Generator\choose( 0, 5 ),
			function ( int $arity ) use ( $numericString, $styleString ) {
				$fields = [ $numericString, $numericString, $numericString, $styleString, $numericString ];
				$slice  = array_slice( $fields, 0, $arity );
				if ( [] === $slice ) {
					return Generator\constant( [] );
				}
				return Generator\tuple( ...$slice );
			}
		);
	}

	/**
	 * Generates a bounded list (0–8 elements) of raw variant tuples.
	 */
	private function variantsGenerator(): \Eris\Generator {
		return Generator\bind(
			Generator\choose( 0, 8 ),
			fn ( int $n ) => 0 === $n
				? Generator\constant( [] )
				: Generator\vector( $n, $this->rawVariantGenerator() )
		);
	}

	public function test_output_keys_and_types_are_stable(): void {
		$this->forAll( $this->variantsGenerator() )
			->then( function ( array $variants ): void {
				$resizer = new Resizer();
				$result  = $this->callPrivate( $resizer, 'normalizeVariants', [ $variants ] );

				$this->assertIsArray( $result );
				foreach ( $result as $row ) {
					$this->assertIsArray( $row );
					$this->assertEqualsCanonicalizing(
						[ 'width', 'height', 'media', 'image_style', 'quality' ],
						array_keys( $row )
					);
					$this->assertIsInt( $row['width'] );
					$this->assertIsInt( $row['height'] );
					$this->assertIsInt( $row['media'] );
					$this->assertIsString( $row['image_style'] );
					$this->assertIsInt( $row['quality'] );
				}
			} );
	}

	public function test_count_is_preserved(): void {
		$this->forAll( $this->variantsGenerator() )
			->then( function ( array $variants ): void {
				$resizer = new Resizer();
				$result  = $this->callPrivate( $resizer, 'normalizeVariants', [ $variants ] );

				$this->assertCount( count( $variants ), $result );
			} );
	}

	public function test_result_is_deterministic(): void {
		$this->forAll( $this->variantsGenerator() )
			->then( function ( array $variants ): void {
				$resizer = new Resizer();
				$first   = $this->callPrivate( $resizer, 'normalizeVariants', [ $variants ] );
				$second  = $this->callPrivate( $resizer, 'normalizeVariants', [ $variants ] );

				$this->assertSame( $first, $second );
			} );
	}

	public function test_output_is_sorted_by_media_descending(): void {
		$this->forAll( $this->variantsGenerator() )
			->then( function ( array $variants ): void {
				$resizer = new Resizer();
				$result  = $this->callPrivate( $resizer, 'normalizeVariants', [ $variants ] );

				// Pairwise check: each adjacent pair must satisfy prev >= next.
				// Direct expression of "non-strict DESC" — avoids depending on
				// PHP 8's stable-sort behaviour to make a `=== rsort(copy)`
				// comparison work.
				$mediaValues = array_map( fn ( array $row ) => $row['media'], $result );
				for ( $i = 1, $n = count( $mediaValues ); $i < $n; $i++ ) {
					$this->assertGreaterThanOrEqual(
						$mediaValues[ $i ],
						$mediaValues[ $i - 1 ],
						'normalizeVariants must sort by media DESC'
					);
				}
			} );
	}
}
