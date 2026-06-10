<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Resizer;
use Tests\Unit\ResizerTestCase;

/**
 * Covers the capability-gated input allow-list: `isAllowedImageType()`,
 * `canDecode()`, and `supportedInputFormats()`.
 *
 * The allow-list is the desired format superset intersected with what the active
 * backend can decode. To keep these unit tests deterministic (independent of the
 * host's Imagick/GD delegates), they drive the result through the
 * `timber_kit_resizer_allowed_types` filter rather than the live probe. One smoke
 * test exercises the real probe against the universal JPEG baseline.
 */
class IsAllowedImageTypeTest extends ResizerTestCase {

	/**
	 * Build a Resizer whose decodable allow-list is forced to $mimes via the
	 * `timber_kit_resizer_allowed_types` filter (the probe result is overridden).
	 *
	 * @param array<int, string> $mimes
	 */
	private function resizerAllowing( array $mimes ): Resizer {
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $default, ...$args ) use ( $mimes ) {
				unset( $args );
				return 'timber_kit_resizer_allowed_types' === $filter ? $mimes : $default;
			}
		);
		return new Resizer();
	}

	private function isAllowed( Resizer $resizer, string $path ): bool {
		// isAllowedImageType is private on Resizer — invoke via the declaring class.
		$ref = new \ReflectionMethod( Resizer::class, 'isAllowedImageType' );
		return (bool) $ref->invoke( $resizer, $path );
	}

	public function test_jpeg_allowed(): void {
		$resizer = $this->resizerAllowing( [ 'image/jpeg', 'image/png' ] );
		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/jpeg', 'ext' => 'jpg' ] );
		$this->assertTrue( $this->isAllowed( $resizer, 'photo.jpg' ) );
	}

	public function test_avif_allowed_when_backend_decodes_it(): void {
		// AVIF is also the *target* format, but an avif source still needs cropping +
		// downscaling — so when the backend can decode it, it must be processed.
		$resizer = $this->resizerAllowing( [ 'image/jpeg', 'image/avif' ] );
		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/avif', 'ext' => 'avif' ] );
		$this->assertTrue( $this->isAllowed( $resizer, 'photo.avif' ) );
	}

	public function test_heic_not_allowed_when_backend_cannot_decode_it(): void {
		// GD-only backend: heic is desired but un-decodable, so it must be excluded
		// rather than passed through at full size.
		$resizer = $this->resizerAllowing( [ 'image/jpeg', 'image/png', 'image/gif' ] );
		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/heic', 'ext' => 'heic' ] );
		$this->assertFalse( $this->isAllowed( $resizer, 'iphone.heic' ) );
	}

	public function test_svg_never_allowed(): void {
		// Vector — not raster-resizable. Never in the desired set, so never allowed
		// regardless of backend.
		$resizer = $this->resizerAllowing( [ 'image/jpeg', 'image/avif', 'image/heic' ] );
		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/svg+xml', 'ext' => 'svg' ] );
		$this->assertFalse( $this->isAllowed( $resizer, 'logo.svg' ) );
	}

	public function test_unknown_type_not_allowed(): void {
		$resizer = $this->resizerAllowing( [ 'image/jpeg', 'image/png' ] );
		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => false, 'ext' => false ] );
		$this->assertFalse( $this->isAllowed( $resizer, 'document.xyz' ) );
	}

	public function test_can_decode_reflects_allow_list(): void {
		$resizer = $this->resizerAllowing( [ 'image/jpeg', 'image/png', 'image/gif', 'image/avif' ] );
		$this->assertTrue( $resizer->canDecode( 'image/avif' ) );
		$this->assertTrue( $resizer->canDecode( 'image/jpeg' ) );
		$this->assertFalse( $resizer->canDecode( 'image/heic' ) );
		$this->assertFalse( $resizer->canDecode( 'image/svg+xml' ) );
	}

	public function test_supported_input_formats_matrix(): void {
		$resizer = $this->resizerAllowing( [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/avif', 'image/tiff', 'image/heic', 'image/heif' ] );
		$matrix = $resizer->supportedInputFormats();

		// Every desired format appears as a key…
		$this->assertArrayHasKey( 'image/jpeg', $matrix );
		$this->assertArrayHasKey( 'image/avif', $matrix );
		$this->assertArrayHasKey( 'image/heif', $matrix );
		// …with a boolean reflecting decodability.
		$this->assertTrue( $matrix['image/avif'] );
		$this->assertTrue( $matrix['image/heic'] );
		$this->assertTrue( $matrix['image/heif'] );
		$this->assertTrue( $matrix['image/tiff'] );
		// SVG / ico / sequence types are out of the resizer's scope entirely.
		$this->assertArrayNotHasKey( 'image/svg+xml', $matrix );
	}

	public function test_supported_input_formats_marks_undecodable_false(): void {
		// GD-only allow-list: modern formats present as keys but flagged false.
		$resizer = $this->resizerAllowing( [ 'image/jpeg', 'image/png', 'image/gif' ] );
		$matrix = $resizer->supportedInputFormats();

		$this->assertTrue( $matrix['image/jpeg'] );
		$this->assertFalse( $matrix['image/avif'] );
		$this->assertFalse( $matrix['image/heic'] );
		$this->assertFalse( $matrix['image/tiff'] );
	}

	public function test_real_probe_decodes_jpeg_baseline(): void {
		// Smoke test against the live backend (no filter override): JPEG decode is
		// universal across both GD and Imagick, so this is environment-safe.
		$resizer = $this->createResizer();
		$this->assertTrue( $resizer->canDecode( 'image/jpeg' ) );
		$this->assertTrue( $resizer->canDecode( 'image/png' ) );
		$this->assertFalse( $resizer->canDecode( 'image/svg+xml' ) );
	}
}
