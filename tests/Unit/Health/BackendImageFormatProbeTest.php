<?php

declare(strict_types=1);

namespace Tests\Unit\Health;

use Parisek\TimberKit\Health\BackendImageFormatProbe;
use Parisek\TimberKit\Health\ImageFormatProbe;
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

		$this->assertSame(
			ImageFormatProbe::VERDICT_OK,
			( new BackendImageFormatProbe() )->probe( $format )
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
	 * Whether the active backend — chosen by the same rule the probe uses —
	 * can write this format at all.
	 */
	private static function backendCanWrite( string $format ): bool {
		if ( class_exists( '\Imagick' ) ) {
			return in_array(
				strtoupper( $format ),
				array_map( 'strtoupper', ( new \Imagick() )->queryFormats() ),
				true
			);
		}

		$info = function_exists( 'gd_info' ) ? gd_info() : array();

		return match ( $format ) {
			'webp'  => ! empty( $info['WebP Support'] ),
			'avif'  => ! empty( $info['AVIF Support'] ),
			default => false,
		};
	}
	/**
	 * The threshold, stated as a table. Opacity above the midpoint means the
	 * encoder flattened the channel; at or below it, the pixel is still
	 * transparent and merely lossy.
	 */
	#[DataProvider( 'alpha_samples' )]
	public function test_alpha_threshold( float $opacity, string $expected ): void {
		$this->assertSame( $expected, BackendImageFormatProbe::alphaVerdict( $opacity ) );
	}

	/**
	 * @return array<string, array{float, string}>
	 */
	public static function alpha_samples(): array {
		return array(
			'fully transparent'   => array( 0.0, ImageFormatProbe::VERDICT_OK ),
			'lossy but see-through' => array( 0.02, ImageFormatProbe::VERDICT_OK ),
			'exactly at midpoint' => array( 0.5, ImageFormatProbe::VERDICT_OK ),
			'mostly opaque'       => array( 0.8, ImageFormatProbe::VERDICT_ALPHA_LOST ),
			'fully opaque'        => array( 1.0, ImageFormatProbe::VERDICT_ALPHA_LOST ),
		);
	}

	/**
	 * Negative control against a real encoder: an image written with no alpha
	 * channel — the exact shape a flattening build returns — must be caught.
	 *
	 * Without this, every assertion above would still pass if the threshold
	 * were inverted, because a correct backend never produces the failing case.
	 */
	public function test_an_opaque_encode_is_detected_as_alpha_loss(): void {
		if ( ! function_exists( 'imagewebp' ) || ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD with WebP support is required to build the control image.' );
		}

		$image = imagecreatetruecolor( 16, 16 );
		imagefilledrectangle( $image, 0, 0, 15, 15, (int) imagecolorallocate( $image, 255, 0, 0 ) );

		ob_start();
		imagewebp( $image );
		$blob = (string) ob_get_clean();
		imagedestroy( $image );

		$read = imagecreatefromstring( $blob );
		$this->assertNotFalse( $read );

		imagesavealpha( $read, true );
		$packed = ( imagecolorat( $read, 12, 8 ) >> 24 ) & 0x7F;
		imagedestroy( $read );

		$this->assertSame(
			ImageFormatProbe::VERDICT_ALPHA_LOST,
			BackendImageFormatProbe::alphaVerdict( 1.0 - ( $packed / 127.0 ) )
		);
	}
}
