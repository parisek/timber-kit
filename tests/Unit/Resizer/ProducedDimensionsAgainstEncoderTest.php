<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Parisek\TimberKit\Resizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spatie\Image\Image;

/**
 * The one test here that cannot be circular.
 *
 * Every other assertion about produced dimensions compares this package's
 * arithmetic against numbers a human wrote down. This one runs the real encoder
 * over a real file and compares against `getimagesize()` — so it fails when
 * Spatie changes how it rounds, which is exactly the change that would silently
 * turn `producedDimensions()` into a confident lie.
 *
 * Only the plain-resize path is covered, because it is the only one whose
 * arithmetic is the encoder's rather than this package's: cropping paths write
 * the dimensions they were handed by construction.
 *
 * It asserts the two halves of the contract separately, because they are not
 * equally strong. A **requested** axis is exact, and that is asserted as such.
 * A **derived** axis is an estimate: Spatie's drivers implement the step
 * differently — GD's `width()` delegates to a bounding-box resize — so the same
 * request writes 500x499 under GD and 500x500 under Imagick. A single step
 * stays within a pixel of the estimate; the two-axis non-cropping path scales
 * that disagreement by the second step and is therefore asserted loosely, with
 * the bound recorded as a measurement rather than a promise.
 */
class ProducedDimensionsAgainstEncoderTest extends TestCase {

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();

		if ( ! extension_loaded( 'gd' ) ) {
			$this->markTestSkipped( 'GD is needed to write the source image.' );
		}

		$this->dir = sys_get_temp_dir() . '/timber-kit-produced-' . getmypid();
		if ( ! is_dir( $this->dir ) ) {
			mkdir( $this->dir, 0777, true );
		}
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/*' ) ?: [] as $file ) {
			unlink( $file );
		}
		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}
		parent::tearDown();
	}

	private function sourceImage( int $width, int $height ): string {
		$path = $this->dir . "/src-{$width}x{$height}.jpg";
		$image = imagecreatetruecolor( $width, $height );
		imagefilledrectangle( $image, 0, 0, $width, $height, imagecolorallocate( $image, 120, 90, 60 ) );
		imagejpeg( $image, $path, 90 );
		imagedestroy( $image );

		return $path;
	}

	/**
	 * Sources and targets chosen for their rounding, not their realism: each
	 * pair below produced a different answer under one-step arithmetic than the
	 * encoder's two-step resize actually writes.
	 *
	 * @return array<string, array{0: int, 1: int, 2: int, 3: int}>
	 */
	public static function cases(): array {
		return [
			'both axes, ratio survives' => [ 3000, 2000, 800, 800 ],
			'both axes, intermediate rounds down' => [ 3000, 2000, 2, 2 ],
			'both axes, near-square source' => [ 1000, 999, 500, 500 ],
			'both axes, portrait target' => [ 1000, 999, 333, 777 ],
			'both axes, wide source' => [ 777, 333, 800, 800 ],
			'both axes, wide source small target' => [ 777, 333, 5, 3 ],
			'width only' => [ 3000, 2000, 600, 0 ],
			'height only' => [ 3000, 2000, 0, 400 ],
			'width only, upscaling' => [ 777, 333, 6000, 0 ],
			'height only, near-square source' => [ 1000, 999, 0, 250 ],
			'both axes, first step ambiguous and second upscales hard' => [ 1000, 999, 500, 5000 ],
		];
	}

	#[DataProvider( 'cases' )]
	public function test_the_derivation_matches_what_the_encoder_writes( int $source_width, int $source_height, int $width, int $height ): void {
		$source = $this->sourceImage( $source_width, $source_height );
		$target = $this->dir . "/out-{$width}x{$height}.jpg";

		// The same two calls processVariant() makes for a non-cropping style,
		// in the same order — the order is the whole point.
		$image = Image::load( $source );
		if ( 0 !== $width ) {
			$image->width( $width );
		}
		if ( 0 !== $height ) {
			$image->height( $height );
		}
		$image->format( 'jpg' )->save( $target );

		$written = getimagesize( $target );
		$this->assertIsArray( $written, 'the encoder wrote no readable file' );

		$derived = Resizer::producedDimensions(
			[ 'width' => $width, 'height' => $height, 'image_style' => 'scale', 'quality' => 90, 'format' => 'jpg' ],
			$source_width,
			$source_height
		);

		// A requested axis is a promise, so it is asserted as one.
		if ( 0 !== $width && 0 === $height ) {
			$this->assertSame( $written[0], $derived[0], 'a requested width must be exact' );
		}
		if ( 0 !== $height ) {
			$this->assertSame( $written[1], $derived[1], 'a requested height must be exact' );
		}

		// A derived axis is an estimate. One step stays within a pixel; two
		// steps scale the drivers' disagreement, so the looser bound below is
		// what was measured, not what is guaranteed.
		$tolerance = ( 0 !== $width && 0 !== $height ) ? max( 1, (int) ceil( $written[0] * 0.01 ) ) : 1;

		$this->assertEqualsWithDelta( $written[0], $derived[0], $tolerance, 'derived width drifted further than measured' );
		$this->assertEqualsWithDelta( $written[1], $derived[1], $tolerance, 'derived height drifted further than measured' );
	}
}
