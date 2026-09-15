<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Parisek\TimberKit\Resizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spatie\Image\Image;

/**
 * A higher quality must produce a larger file on both encode paths.
 *
 * Runs the real encoder. ImageMagick's AVIF coder reads the wand-level quality,
 * not the image-level one. spatie/image < 3.9.6 set the wand value to
 * `100 - $quality` for every format, so AVIF quality ran backwards (80 encoded
 * as 20); the Spatie case guards against that dependency coming back. The
 * smart-crop path set only the image value, so AVIF ignored it; the Imagick
 * case pins this package's own fix.
 */
class EncoderQualityTest extends TestCase {

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();

		if ( ! extension_loaded( 'imagick' ) || ! extension_loaded( 'gd' ) ) {
			if ( getenv( 'TIMBERKIT_REQUIRE_AVIF_ENCODER' ) ) {
				$this->fail( 'TIMBERKIT_REQUIRE_AVIF_ENCODER is set, but Imagick or GD is missing.' );
			}
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

	/**
	 * A noisy source, so the encoder has detail to keep or throw away. Seeded,
	 * so the probe and the test encode the same pixels on every run.
	 */
	private function source(): string {
		$path = $this->dir . '/src.png';
		if ( is_file( $path ) ) {
			return $path;
		}
		mt_srand( 20260915 );
		$image = imagecreatetruecolor( 800, 533 );
		for ( $i = 0; $i < 4000; $i++ ) {
			$color = imagecolorallocate( $image, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) );
			imagefilledellipse( $image, mt_rand( 0, 800 ), mt_rand( 0, 533 ), mt_rand( 4, 40 ), mt_rand( 4, 40 ), $color );
		}
		imagepng( $image, $path );
		mt_srand();

		return $path;
	}

	/**
	 * Skip where the build cannot show the property, except in the CI job that
	 * installs an AVIF encoder for exactly this test: there a skip would hide
	 * the case the test exists for, so it fails instead.
	 */
	private function skipOrFail( string $format ): void {
		if ( 'avif' === $format && getenv( 'TIMBERKIT_REQUIRE_AVIF_ENCODER' ) ) {
			$this->fail( 'TIMBERKIT_REQUIRE_AVIF_ENCODER is set, but this ImageMagick build does not vary AVIF output with quality.' );
		}
		$this->markTestSkipped( "This ImageMagick build does not vary {$format} output with quality." );
	}

	/** @return array<string, array{string}> */
	public static function formats(): array {
		$formats = [];
		// AVIF is the format the defect affected. JPEG is the control: it reads
		// the image-level value, so it must pass with or without the fix.
		foreach ( [ 'avif', 'jpg' ] as $format ) {
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
			$this->skipOrFail( $format );
		}
		$source = $this->source();
		$sizes  = [];
		foreach ( [ 30, 80 ] as $quality ) {
			$target = "{$this->dir}/spatie-{$quality}.{$format}";
			Image::load( $source )->width( 400 )->format( $format )->quality( $quality )->save( $target );
			$sizes[ $quality ] = filesize( $target );
		}

		$this->assertGreaterThan( $sizes[30], $sizes[80] );
	}

	#[DataProvider( 'formats' )]
	public function test_imagick_path_grows_with_quality( string $format ): void {
		if ( ! $this->supports( $format ) ) {
			$this->skipOrFail( $format );
		}
		$source = $this->source();
		$sizes  = [];
		foreach ( [ 30, 80 ] as $quality ) {
			$image = new \Imagick( $source );
			$image->setImageFormat( $format );
			( new \ReflectionMethod( Resizer::class, 'applyImagickQuality' ) )->invoke( null, $image, $quality, $format );
			$sizes[ $quality ] = strlen( $image->getImagesBlob() );
			$image->clear();
		}

		$this->assertGreaterThan( $sizes[30], $sizes[80] );
	}
}
