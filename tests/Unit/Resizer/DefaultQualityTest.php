<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Resizer;
use Tests\Unit\ResizerTestCase;

/**
 * The package default quality is 80, for every format.
 *
 * 100 was harmless for AVIF only while AVIF ignored it and ran at the
 * encoder's own default. Honoured, 100 makes a typical AVIF photo about 25x
 * larger than what sites were serving.
 */
class DefaultQualityTest extends ResizerTestCase {

	private function resizer( ?int $filtered_quality, string $format = 'avif' ): Resizer {
		Functions\when( 'apply_filters' )->alias( function ( $filter, $default ) use ( $filtered_quality, $format ) {
			if ( 'timber_kit_resizer_target_quality' === $filter && null !== $filtered_quality ) {
				return $filtered_quality;
			}
			if ( 'timber_kit_resizer_target_format' === $filter ) {
				return $format;
			}
			return $default;
		} );
		return new Resizer();
	}

	/** @return array<string, mixed> */
	private function normalized( Resizer $resizer, array $variant ): array {
		return $this->callPrivate( $resizer, 'normalizeVariants', [ [ $variant ] ] )[0];
	}

	public function test_default_quality_is_80_for_every_format(): void {
		foreach ( [ 'avif', 'webp', 'jpeg' ] as $format ) {
			$this->assertSame( 80, $this->normalized( $this->resizer( null, $format ), [ '800', '600' ] )['quality'], $format );
		}
	}

	public function test_a_filtered_quality_wins(): void {
		$this->assertSame( 65, $this->normalized( $this->resizer( 65 ), [ '800', '600' ] )['quality'] );
	}

	public function test_an_explicit_variant_quality_wins(): void {
		$this->assertSame( 95, $this->normalized( $this->resizer( null ), [ '800', '600', '', 'center', '95' ] )['quality'] );
	}

	public function test_a_filtered_target_format_is_trimmed_and_lowercased(): void {
		$this->assertSame( 'avif', $this->normalized( $this->resizer( null, ' AVIF ' ), [ '800', '600' ] )['format'] );
	}
}
