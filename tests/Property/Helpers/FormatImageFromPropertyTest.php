<?php

declare(strict_types=1);

namespace Tests\Property\Helpers;

use Eris\Generator;
use Parisek\TimberKit\Helpers;
use Tests\Property\Support\PropertyTestCase;

/**
 * Property tests for the extracted pure-core ACF-array image formatter.
 *
 * Three invariants:
 *  - Non-throw + no PHP warnings/notices for any ?array input.
 *  - Shape contract: output is null or a dict with exactly the documented keys.
 *  - Null propagation for the two degenerate inputs.
 *
 * The generator deliberately produces dirty values for every documented
 * ACF key (null / empty string / wrong-type scalars) — not "valid ACF".
 * The point is to find implicit assumptions in the formatter that fail
 * on real-world data. (Sparse-key coverage — i.e. arrays where a key is
 * genuinely absent — lives in `FormatImageFromTest::test_missing_keys_default_to_null_without_warning`
 * because `Generator\associative` always emits all declared keys.)
 */
class FormatImageFromPropertyTest extends PropertyTestCase {

	private const EXPECTED_KEYS = [
		'id', 'src', 'type', 'width', 'height', 'alt', 'caption', 'description',
	];

	private function rawAcfImageGenerator(): \Eris\Generator {
		$maybeNullStr = Generator\oneOf(
			Generator\string(),
			Generator\constant( '' ),
			Generator\constant( null )
		);
		// Numeric-string `'800'` is a real ACF return shape; non-numeric `'abc'`
		// stresses the is_numeric guard. Both must produce int|null without notices.
		$numericLike = Generator\oneOf(
			Generator\nat(),
			Generator\map( fn ( int $n ) => (string) $n, Generator\nat() ),
			Generator\constant( 'abc' ),
			Generator\constant( '' ),
			Generator\constant( null )
		);

		$arrayShape = Generator\associative( [
			'ID'          => Generator\oneOf( Generator\nat(), Generator\map( fn ( int $n ) => (string) $n, Generator\nat() ), Generator\constant( 'abc' ), Generator\constant( null ) ),
			'url'         => $maybeNullStr,
			'mime_type'   => $maybeNullStr,
			'width'       => $numericLike,
			'height'      => $numericLike,
			'alt'         => $maybeNullStr,
			'caption'     => $maybeNullStr,
			'description' => $maybeNullStr,
		] );

		return Generator\oneOf(
			Generator\constant( null ),
			Generator\constant( [] ),
			$arrayShape
		);
	}

	public function test_never_throws_and_emits_no_php_warnings(): void {
		$this->forAll( $this->rawAcfImageGenerator() )
			->then( function ( $raw ): void {
				set_error_handler( static function ( int $errno, string $errstr ): bool {
					throw new \RuntimeException( "Unexpected PHP error ($errno): $errstr" );
				} );
				try {
					Helpers::formatImageFrom( $raw );
				} finally {
					restore_error_handler();
				}
				$this->addToAssertionCount( 1 );
			} );
	}

	public function test_output_is_null_or_matches_documented_shape(): void {
		$this->forAll( $this->rawAcfImageGenerator() )
			->then( function ( $raw ): void {
				$result = Helpers::formatImageFrom( $raw );

				if ( null === $result ) {
					$this->addToAssertionCount( 1 );
					return;
				}

				$this->assertIsArray( $result );
				$this->assertEqualsCanonicalizing(
					self::EXPECTED_KEYS,
					array_keys( $result )
				);

				// Value-type contract: each documented key matches its declared type
				// (int|null for id/width/height, string|null for the rest).
				$this->assertTrue( null === $result['id']     || is_int( $result['id'] ),     'id must be int|null' );
				$this->assertTrue( null === $result['width']  || is_int( $result['width'] ),  'width must be int|null' );
				$this->assertTrue( null === $result['height'] || is_int( $result['height'] ), 'height must be int|null' );
				foreach ( [ 'src', 'type', 'alt', 'caption', 'description' ] as $key ) {
					$this->assertTrue(
						null === $result[ $key ] || is_string( $result[ $key ] ),
						"$key must be string|null"
					);
				}
			} );
	}

	public function test_null_and_empty_array_propagate_to_null(): void {
		$this->forAll( Generator\elements( null, [] ) )
			->then( function ( $degenerate ): void {
				$this->assertNull( Helpers::formatImageFrom( $degenerate ) );
			} );
	}
}
