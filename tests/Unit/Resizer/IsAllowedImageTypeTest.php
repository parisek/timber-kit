<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Brain\Monkey\Functions;
use Tests\Unit\ResizerTestCase;

class IsAllowedImageTypeTest extends ResizerTestCase {

	public function test_jpeg_allowed(): void {
		$resizer = $this->createResizer();

		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/jpeg', 'ext' => 'jpg' ] );

		$result = $this->callPrivate( $resizer, 'isAllowedImageType', [ 'photo.jpg' ] );
		$this->assertTrue( $result );
	}

	public function test_png_allowed(): void {
		$resizer = $this->createResizer();

		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/png', 'ext' => 'png' ] );

		$result = $this->callPrivate( $resizer, 'isAllowedImageType', [ 'logo.png' ] );
		$this->assertTrue( $result );
	}

	public function test_webp_allowed(): void {
		$resizer = $this->createResizer();

		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/webp', 'ext' => 'webp' ] );

		$result = $this->callPrivate( $resizer, 'isAllowedImageType', [ 'image.webp' ] );
		$this->assertTrue( $result );
	}

	public function test_svg_not_allowed(): void {
		$resizer = $this->createResizer();

		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/svg+xml', 'ext' => 'svg' ] );

		$result = $this->callPrivate( $resizer, 'isAllowedImageType', [ 'icon.svg' ] );
		$this->assertFalse( $result );
	}

	public function test_unknown_type(): void {
		$resizer = $this->createResizer();

		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => false, 'ext' => false ] );

		$result = $this->callPrivate( $resizer, 'isAllowedImageType', [ 'document.xyz' ] );
		$this->assertFalse( $result );
	}

	public function test_gif_allowed(): void {
		$resizer = $this->createResizer();

		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/gif', 'ext' => 'gif' ] );

		$result = $this->callPrivate( $resizer, 'isAllowedImageType', [ 'animation.gif' ] );
		$this->assertTrue( $result );
	}

	public function test_bmp_allowed(): void {
		$resizer = $this->createResizer();

		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/bmp', 'ext' => 'bmp' ] );

		$result = $this->callPrivate( $resizer, 'isAllowedImageType', [ 'legacy.bmp' ] );
		$this->assertTrue( $result );
	}

	public function test_avif_allowed(): void {
		$resizer = $this->createResizer();

		// AVIF is also the *target* format, but an avif source still needs cropping +
		// downscaling — so it must be processed, not passed through at full size.
		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/avif', 'ext' => 'avif' ] );

		$result = $this->callPrivate( $resizer, 'isAllowedImageType', [ 'photo.avif' ] );
		$this->assertTrue( $result );
	}

	public function test_tiff_allowed(): void {
		$resizer = $this->createResizer();

		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/tiff', 'ext' => 'tiff' ] );

		$result = $this->callPrivate( $resizer, 'isAllowedImageType', [ 'scan.tiff' ] );
		$this->assertTrue( $result );
	}

	public function test_heic_allowed(): void {
		$resizer = $this->createResizer();

		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/heic', 'ext' => 'heic' ] );

		$result = $this->callPrivate( $resizer, 'isAllowedImageType', [ 'iphone.heic' ] );
		$this->assertTrue( $result );
	}

	public function test_heif_allowed(): void {
		$resizer = $this->createResizer();

		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/heif', 'ext' => 'heif' ] );

		$result = $this->callPrivate( $resizer, 'isAllowedImageType', [ 'iphone.heif' ] );
		$this->assertTrue( $result );
	}

	public function test_svg_still_not_allowed(): void {
		$resizer = $this->createResizer();

		// SVG is vector — the raster resizer can't crop/downscale it, so it stays excluded.
		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/svg+xml', 'ext' => 'svg' ] );

		$result = $this->callPrivate( $resizer, 'isAllowedImageType', [ 'logo.svg' ] );
		$this->assertFalse( $result );
	}
}
