<?php

declare(strict_types=1);

namespace Tests\Unit\Health\Image;

use Parisek\TimberKit\Health\Image\BackendImageFormatProbe;
use Tests\Unit\Health\HealthTestCase;
use Parisek\TimberKit\Health\Image\ImageFormatProbe;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Exercises the real encoder rather than a stub.
 *
 * The stubbed check test asserts the judgement; this one asserts the thing the
 * judgement is built on — that a transparent pixel survives a real round trip
 * and that the alpha threshold reads it correctly. A wrong threshold would
 * make the check pass on a broken host, which is the exact failure it exists
 * to prevent.
 *
 * Each case skips when the running backend cannot write that format, so the
 * suite stays green on a minimal PHP build instead of asserting the host's
 * configuration.
 */
class BackendImageFormatProbeTest extends HealthTestCase {

	/**
	 * A format the backend genuinely supports round-trips with alpha intact.
	 */
	#[DataProvider( 'transparency_capable_formats' )]
	public function test_supported_format_round_trips_with_alpha( string $format ): void {
		if ( ! self::backendCanWrite( $format ) ) {
			$this->markTestSkipped( sprintf( 'No image backend on this build writes %s.', $format ) );
		}

		// UNVERIFIED is a pass for this assertion: it means the encode worked
		// and only the read-back was unavailable. Distinguishing those two is
		// the point — a write-only build must not read as a broken one.
		$this->assertContains(
			( new BackendImageFormatProbe() )->probe( $format ),
			array( ImageFormatProbe::VERDICT_OK, ImageFormatProbe::VERDICT_UNVERIFIED )
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function transparency_capable_formats(): array {
		return array(
			'webp' => array( 'webp' ),
			'avif' => array( 'avif' ),
		);
	}

	/**
	 * A format no backend knows must read as a missing delegate, not as a
	 * write failure — the two send an admin to different fixes.
	 */
	public function test_unknown_format_reports_a_missing_delegate(): void {
		$this->assertSame(
			ImageFormatProbe::VERDICT_MISSING_DELEGATE,
			( new BackendImageFormatProbe() )->probe( 'definitely-not-an-image-format' )
		);
	}

	/**
	 * The probe leaves no output behind. An unbalanced ob_start() in the GD
	 * path would silently swallow whatever wp-admin prints next.
	 */
	public function test_probe_writes_nothing_to_the_output_buffer(): void {
		$depth_before = ob_get_level();

		ob_start();
		( new BackendImageFormatProbe() )->probe( 'webp' );
		$leaked = (string) ob_get_clean();

		$this->assertSame( '', $leaked );
		$this->assertSame( $depth_before, ob_get_level() );
	}

	/**
	 * The negative control, through the public API.
	 *
	 * JPEG cannot carry an alpha channel, so a correct probe must report the
	 * transparency as lost. Without a case like this every other assertion
	 * would still pass with the threshold inverted, because a healthy backend
	 * never produces the failing verdict on its own.
	 */
	public function test_a_format_that_cannot_carry_alpha_reports_alpha_loss(): void {
		if ( ! self::backendCanWrite( 'jpeg' ) ) {
			$this->markTestSkipped( 'No image backend on this build writes JPEG.' );
		}

		$this->assertSame(
			ImageFormatProbe::VERDICT_ALPHA_LOST,
			( new BackendImageFormatProbe() )->probe( 'jpeg' )
		);
	}

	/**
	 * hasBackend() answers the question the check asks before it asks about a
	 * format. The test environment always has one, so this pins the contract
	 * rather than the host.
	 */
	public function test_has_backend_is_true_when_an_image_extension_is_loaded(): void {
		$expected = class_exists( '\Imagick' ) || function_exists( 'imagecreatetruecolor' );

		$this->assertSame( $expected, ( new BackendImageFormatProbe() )->hasBackend() );
	}

	/**
	 * Whether the active backend can write this format — decided by trying,
	 * not by asking.
	 *
	 * `gd_info()['AVIF Support']` was the obvious guard and it is wrong: it is
	 * true on GitHub's runners, where libavif ships a decoder and no encoder,
	 * so `imageavif()` fails and the probe correctly returns `write_failed`.
	 * Reading a capability flag to predict an encode is the exact mistake the
	 * class under test exists to catch, so the test must not make it either.
	 */
	private static function backendCanWrite( string $format ): bool {
		if ( class_exists( '\Imagick' ) ) {
			try {
				$image = new \Imagick();
				$image->newImage( 16, 16, new \ImagickPixel( 'red' ) );
				$image->setImageFormat( $format );
				$written = '' !== $image->getImageBlob();
				$image->clear();

				return $written;
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return false;
		}

		$image = imagecreatetruecolor( 16, 16 );
		if ( false === $image ) {
			return false;
		}

		try {
			ob_start();
			$written = match ( $format ) {
				'avif'  => function_exists( 'imageavif' ) && imageavif( $image ),
				'webp'  => function_exists( 'imagewebp' ) && imagewebp( $image ),
				'jpeg'  => function_exists( 'imagejpeg' ) && imagejpeg( $image ),
				default => false,
			};
			$blob = (string) ob_get_clean();

			return $written && '' !== $blob;
		} catch ( \Throwable $e ) {
			ob_end_clean();

			return false;
		} finally {
			imagedestroy( $image );
		}
	}
}
