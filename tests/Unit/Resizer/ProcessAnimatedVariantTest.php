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

	protected function tearDown(): void {
		foreach ( $this->tmp as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
		$this->tmp = [];
		parent::tearDown();
	}

	/** Minimal animated 2-frame GIF (89a, two image descriptors). */
	private function animatedGif(): string {
		$path = tempnam( sys_get_temp_dir(), 'agif' ) . '.gif';
		$this->tmp[] = $path;
		// 1x1, 2 frames. Built with GD if available, else a hand-rolled byte blob.
		if ( function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagegif' ) ) {
			$im = imagecreatetruecolor( 2, 2 );
			ob_start();
			imagegif( $im );
			$one = (string) ob_get_clean();
			imagedestroy( $im );
			// Duplicate the single frame's image-descriptor block to fake a 2nd frame
			// is fragile; instead rely on Imagick to read a real animated GIF when
			// present. Fall back: write via Imagick directly.
		}
		if ( extension_loaded( 'imagick' ) ) {
			$ani = new \Imagick();
			foreach ( [ 'red', 'blue' ] as $c ) {
				$f = new \Imagick();
				$f->newImage( 8, 6, new \ImagickPixel( $c ) );
				$f->setImageFormat( 'gif' );
				$f->setImageDelay( 10 );
				$ani->addImage( $f );
				$f->clear();
			}
			$ani->setImageFormat( 'gif' );
			$ani->writeImages( $path, true );
			$ani->clear();
		}
		return $path;
	}

	private function resizerWritingGif(): Resizer {
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $default ) {
				if ( 'timber_kit_resizer_target_format' === $filter ) {
					return 'gif';
				}
				return $default;
			}
		);
		Functions\when( 'wp_mkdir_p' )->justReturn( true );
		Functions\when( 'content_url' )->alias( fn( $p = '' ) => 'http://example.test/wp-content/' . ltrim( (string) $p, '/' ) );
		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/gif', 'ext' => 'gif' ] );
		return new Resizer();
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
		$cache = $this->getPrivateProperty( $resizer, 'image_cache_dir' );

		$result = $this->callPrivate( $resizer, 'processAnimatedVariant', [ $this->variant( 4, 4, 'center' ), $src, 'img', $this->defaultImage() ] );

		$this->assertIsArray( $result );
		$this->assertSame( 4, $result['width'] );
		$out = str_replace( 'http://example.test/wp-content/', WP_CONTENT_DIR . '/', $result['src'] );
		$this->tmp[] = $out;
		$check = new \Imagick();
		$check->pingImage( $out );
		$this->assertGreaterThan( 1, $check->getNumberImages() );
		$check->clear();
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
}
