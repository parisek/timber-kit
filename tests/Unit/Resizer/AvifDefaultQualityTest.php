<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Resizer;
use Tests\Unit\ResizerTestCase;

/**
 * AVIF gets its own default quality, 80, when a site sets none.
 *
 * The package default of 100 was harmless for AVIF only while AVIF ignored it:
 * the encoder ran at its own default. With quality honoured, 100 makes a
 * typical photo about 25x larger. JPEG and WebP always honoured the value and
 * keep 100, so nothing changes for them. A site that sets a quality, even 100,
 * gets that value for every format.
 */
class AvifDefaultQualityTest extends ResizerTestCase {

	private function resizer( ?int $filtered_quality, string $format = 'avif' ): Resizer {
		Functions\when( 'has_filter' )->alias(
			fn ( $hook ) => 'timber_kit_resizer_target_quality' === $hook && null !== $filtered_quality ? 10 : false
		);
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

	public function test_avif_defaults_to_80_when_no_quality_is_set(): void {
		$this->assertSame( 80, $this->normalized( $this->resizer( null ), [ '800', '600' ] )['quality'] );
	}

	public function test_jpeg_keeps_100_when_no_quality_is_set(): void {
		$this->assertSame( 100, $this->normalized( $this->resizer( null, 'jpeg' ), [ '800', '600' ] )['quality'] );
	}

	public function test_a_per_variant_avif_default_follows_the_variant_format(): void {
		$resizer = $this->resizer( null, 'jpeg' );

		$this->assertSame( 80, $this->normalized( $resizer, [ 'width' => 800, 'format' => 'avif' ] )['quality'] );
		$this->assertSame( 100, $this->normalized( $resizer, [ 'width' => 800, 'format' => 'webp' ] )['quality'] );
	}

	public function test_a_filtered_quality_wins_for_every_format_even_100(): void {
		$this->assertSame( 100, $this->normalized( $this->resizer( 100 ), [ '800', '600' ] )['quality'] );
		$this->assertSame( 65, $this->normalized( $this->resizer( 65 ), [ '800', '600' ] )['quality'] );
	}

	public function test_an_explicit_variant_quality_wins(): void {
		$this->assertSame( 95, $this->normalized( $this->resizer( null ), [ '800', '600', '', 'center', '95' ] )['quality'] );
	}
}
