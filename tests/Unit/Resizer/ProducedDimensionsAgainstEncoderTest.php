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
 * Asserted to within a pixel, not exactly, and that tolerance is the finding
 * rather than a convenience. The encoder resizes in two steps and the second
 * rounds against the first's result, so an intermediate landing on a .5
 * boundary is decided by the image driver: GD writes 501x500 where Imagick
 * writes 500x500 for a 1000x999 source asked for 500x500. Measured across 32
 * source/target pairs, one differed, by one pixel. Modelling each driver's
 * rounding would buy that pixel back at the cost of tracking two libraries'
 * internals; a pixel does not move a layout, while the zero this replaces did.
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

		$this->assertEqualsWithDelta( $written[0], $derived[0], 1, 'derived width is more than a pixel from what was written' );
		$this->assertEqualsWithDelta( $written[1], $derived[1], 1, 'derived height is more than a pixel from what was written' );
	}
}
