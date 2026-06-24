<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Resizer;
use Tests\Unit\ResizerTestCase;

/**
 * Covers the raw-Imagick multi-frame path. Backend-dependent assertions skip when
 * the host Imagick cannot write the target format; the frame-cap branch is
 * asserted with a real 2-frame GIF (the universal Imagick baseline).
 */
class ProcessAnimatedVariantTest extends ResizerTestCase {

	/** @var array<int,string> */
	private array $tmp = [];

	/** @var array<int,string> */
	private array $tmp_dirs = [];

	private string $cache_dir;

	protected function tearDown(): void {
		foreach ( $this->tmp as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
		foreach ( array_reverse( $this->tmp_dirs ) as $dir ) {
			if ( is_dir( $dir ) ) {
				rmdir( $dir );
			}
		}
		$this->tmp = [];
		$this->tmp_dirs = [];
		parent::tearDown();
	}

	/** Minimal animated 2-frame GIF (89a, two image descriptors). */
	private function animatedGif(): string {
		$path = sys_get_temp_dir() . '/tk-animated-' . uniqid( '', true ) . '.gif';
		$this->tmp[] = $path;
		$ani = new \Imagick();
		foreach ( [ 'red', 'blue' ] as $c ) {
			$f = new \Imagick();
			$f->newImage( 8, 6, new \ImagickPixel( $c ) );
			$f->setImageFormat( 'gif' );
			$f->setImageDelay( 10 );
			$ani->addImage( $f );
			$f->clear();
			$f->destroy();
		}
		$ani->setImageFormat( 'gif' );
		$ani->writeImages( $path, true );
		$ani->clear();
		$ani->destroy();
		return $path;
	}

	private function resizerWritingGif(): Resizer {
		$this->cache_dir = sys_get_temp_dir() . '/tk-resizer-' . uniqid( '', true );
		mkdir( $this->cache_dir, 0777, true );
		$this->tmp_dirs[] = $this->cache_dir;

		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $default ) {
				if ( 'timber_kit_resizer_target_format' === $filter ) {
					return 'gif';
				}
				if ( 'timber_kit_resizer_image_cache_dir' === $filter ) {
					return $this->cache_dir;
				}
				return $default;
			}
		);
		Functions\when( 'wp_mkdir_p' )->alias(
			function ( string $dir ): bool {
				$created = is_dir( $dir ) || mkdir( $dir, 0777, true );
				if ( $created && ! in_array( $dir, $this->tmp_dirs, true ) ) {
					$this->tmp_dirs[] = $dir;
				}
				return $created;
			}
		);
		Functions\when( 'content_url' )->alias( fn( $p = '' ) => 'http://example.test/wp-content/' . ltrim( (string) $p, '/' ) );
		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/gif', 'ext' => 'gif' ] );
		return new Resizer();
	}

	private function outputPath( int $w, int $h, string $style ): string {
		$path = $this->cache_dir . '/' . $w . 'x' . $h . '-' . $style . '/img.gif';
		$this->tmp[] = $path;
		return $path;
	}

	private function assertAnimatedDimensions( string $path, int $width, int $height ): void {
		$check = new \Imagick();
		$check->readImage( $path );
		$this->assertGreaterThan( 1, $check->getNumberImages() );
		foreach ( $check as $frame ) {
			$this->assertSame( $width, $frame->getImageWidth() );
			$this->assertSame( $height, $frame->getImageHeight() );
		}
		$check->clear();
		$check->destroy();
	}

	private function variant( int $w, int $h, string $style = 'center' ): array {
		return [ 'width' => $w, 'height' => $h, 'media' => 0, 'image_style' => $style, 'quality' => 80 ];
	}

	private function defaultImage(): array {
		return [ 'src' => 'http://example.test/img.gif', 'width' => 8, 'height' => 6, 'alt' => 'a', 'caption' => '', 'description' => '' ];
	}

	public function test_animated_output_retains_multiple_frames(): void {
		if ( ! extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'imagick not available' );
		}
		$resizer = $this->resizerWritingGif();
		if ( ! $this->callPrivate( $resizer, 'canEncodeAnimated', [ 'gif' ] ) ) {
			$this->markTestSkipped( 'backend cannot write animated gif' );
		}
		$src = $this->animatedGif();

		$result = $this->callPrivate( $resizer, 'processAnimatedVariant', [ $this->variant( 4, 4, 'center' ), $src, 'img', $this->defaultImage() ] );

		$this->assertIsArray( $result );
		$this->assertSame( 4, $result['width'] );
		$this->assertAnimatedDimensions( $this->outputPath( 4, 4, 'center' ), 4, 4 );
	}

	public function test_frame_cap_exceeded_returns_null(): void {
		if ( ! extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'imagick not available' );
		}
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $default ) {
				if ( 'timber_kit_resizer_target_format' === $filter ) {
					return 'gif';
				}
				if ( 'timber_kit_resizer_animated_max_frames' === $filter ) {
					return 1; // our fixture has 2 frames → over cap.
				}
				return $default;
			}
		);
		Functions\when( 'wp_mkdir_p' )->justReturn( true );
		Functions\when( 'content_url' )->alias( fn( $p = '' ) => 'http://example.test/' . ltrim( (string) $p, '/' ) );
		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/gif', 'ext' => 'gif' ] );
		Functions\when( 'error_log' )->justReturn( true );
		$resizer = new Resizer();
		$src = $this->animatedGif();

		$result = $this->callPrivate( $resizer, 'processAnimatedVariant', [ $this->variant( 4, 4 ), $src, 'img', $this->defaultImage() ] );

		$this->assertNull( $result );
	}

	public function test_scale_only_branch_resizes_without_cropping(): void {
		if ( ! extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'imagick not available' );
		}
		$resizer = $this->resizerWritingGif();
		if ( ! $this->callPrivate( $resizer, 'canEncodeAnimated', [ 'gif' ] ) ) {
			$this->markTestSkipped( 'backend cannot write animated gif' );
		}
		$src = $this->animatedGif();

		// height=0 means unconstrained — scale-only path, no crop.
		$result = $this->callPrivate( $resizer, 'processAnimatedVariant', [ $this->variant( 4, 0, 'center' ), $src, 'img', $this->defaultImage() ] );

		$this->assertIsArray( $result );
		$this->assertAnimatedDimensions( $this->outputPath( 4, 0, 'center' ), 4, 3 );
	}

	public function test_positional_crop_offset_produces_target_dimensions(): void {
		if ( ! extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'imagick not available' );
		}
		$resizer = $this->resizerWritingGif();
		if ( ! $this->callPrivate( $resizer, 'canEncodeAnimated', [ 'gif' ] ) ) {
			$this->markTestSkipped( 'backend cannot write animated gif' );
		}
		$src = $this->animatedGif();

		// 'top' is a non-centre positional crop — exercises the offset branch.
		$result = $this->callPrivate( $resizer, 'processAnimatedVariant', [ $this->variant( 4, 4, 'top' ), $src, 'img', $this->defaultImage() ] );

		$this->assertIsArray( $result );
		$this->assertAnimatedDimensions( $this->outputPath( 4, 4, 'top' ), 4, 4 );
	}
}
