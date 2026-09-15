<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Parisek\TimberKit\Resizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spatie\Image\Image;

/**
 * A higher quality must produce a larger file, in every format and on both
 * encode paths.
 *
 * Runs the real encoder, because the defect this pins lives in a dependency.
 * spatie/image's Imagick driver sets the wand-level compression quality to
 * `100 - $quality` "for PNGs". ImageMagick's AVIF coder reads that wand-level
 * value, not the image-level one, so on AVIF the setting ran backwards:
 * quality 80 encoded as 20, and a 1600 px photo came out at 7 KB. JPEG and
 * WebP read the image-level value and were unaffected.
 */
class EncoderQualityTest extends TestCase {

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();

		if ( ! extension_loaded( 'imagick' ) || ! extension_loaded( 'gd' ) ) {
			$this->markTestSkipped( 'Imagick and GD are needed to encode.' );
		}

		$this->dir = sys_get_temp_dir() . '/timber-kit-quality-' . getmypid();
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

	/** A noisy source, so the encoder has detail to keep or throw away. */
	private function source(): string {
		$path  = $this->dir . '/src.png';
		$image = new \Imagick();
		$image->newPseudoImage( 800, 533, 'plasma:' );
		$image->setImageFormat( 'png' );
		$image->writeImage( $path );
		$image->clear();

		return $path;
	}

	/** @return array<string, array{string}> */
	public static function formats(): array {
		$formats = [];
		foreach ( [ 'avif', 'webp', 'jpg' ] as $format ) {
			$formats[ $format ] = [ $format ];
		}
		return $formats;
	}

	/**
	 * Whether this ImageMagick build writes the format and lets quality change
	 * the output at all. Builds differ: the CI runner's writes AVIF as an empty
	 * blob and WebP at one size whatever the quality, and there the property
	 * under test cannot be observed.
	 */
	private function supports( string $format ): bool {
		if ( ! in_array( strtoupper( 'jpg' === $format ? 'jpeg' : $format ), \Imagick::queryFormats(), true ) ) {
			return false;
		}
		$sizes = [];
		foreach ( [ 30, 80 ] as $quality ) {
			$probe = new \Imagick( $this->source() );
			$probe->setImageFormat( $format );
			$probe->setImageCompressionQuality( $quality );
			$probe->setCompressionQuality( $quality );
			$sizes[ $quality ] = strlen( $probe->getImagesBlob() );
			$probe->clear();
		}
		return $sizes[30] > 0 && $sizes[80] > $sizes[30];
	}

	#[DataProvider( 'formats' )]
	public function test_spatie_path_grows_with_quality( string $format ): void {
		if ( ! $this->supports( $format ) ) {
			$this->markTestSkipped( "This ImageMagick build does not vary {$format} output with quality." );
		}
		$source = $this->source();
		$sizes  = [];
		foreach ( [ 30, 80 ] as $quality ) {
			$target = "{$this->dir}/spatie-{$quality}.{$format}";
			$image  = Image::load( $source )->width( 400 )->format( $format );
			Resizer::applyQuality( $image, $quality, $format );
			$image->save( $target );
			$sizes[ $quality ] = filesize( $target );
		}

		$this->assertGreaterThan( $sizes[30], $sizes[80] );
	}

	#[DataProvider( 'formats' )]
	public function test_imagick_path_grows_with_quality( string $format ): void {
		if ( ! $this->supports( $format ) ) {
			$this->markTestSkipped( "This ImageMagick build does not vary {$format} output with quality." );
		}
		$source = $this->source();
		$sizes  = [];
		foreach ( [ 30, 80 ] as $quality ) {
			$image = new \Imagick( $source );
			$image->setImageFormat( $format );
			Resizer::applyQuality( $image, $quality, $format );
			$sizes[ $quality ] = strlen( $image->getImagesBlob() );
			$image->clear();
		}

		$this->assertGreaterThan( $sizes[30], $sizes[80] );
	}
}
