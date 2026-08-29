<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health\Check;

use Parisek\TimberKit\Health\BackendImageFormatProbe;
use Parisek\TimberKit\Health\HealthCheck;
use Parisek\TimberKit\Health\ImageFormatProbe;
use Parisek\TimberKit\Health\Result;

/**
 * Effect check: can this server actually WRITE the format the resizer targets,
 * and does that write keep transparency?
 *
 * The neighbouring `timber_kit_resizer_formats` test asks the opposite
 * question — which formats the backend can *decode*, so uploads get cropped
 * instead of served full-size. Nothing asked about the output side, and the
 * output side is the one every image passes through: one missing delegate
 * takes down every variant on the site, not one editor's upload.
 *
 * Failure is silent everywhere else. `Resizer::resizeImage()` catches the
 * encoder throw, error_log()s it and returns null, so the variant drops out of
 * `<picture>` with no fallback and no admin-visible signal. This check is that
 * signal.
 *
 * Three failure verdicts stay distinct on purpose. "No delegate", "read-only
 * delegate" and "drops alpha" send an admin to three different fixes, and the
 * last one is invisible to any capability list — see BackendImageFormatProbe.
 */
final class ResizerOutputFormatWritable implements HealthCheck {

	/**
	 * Formats every ImageMagick and GD build writes without an external
	 * delegate. Probing them would burn an encode to prove the obvious.
	 */
	private const array DELEGATE_FREE = array( 'jpeg', 'jpg', 'png', 'gif' );

	private readonly ImageFormatProbe $probe;

	public function __construct( ?ImageFormatProbe $probe = null ) {
		$this->probe = $probe ?? new BackendImageFormatProbe();
	}

	public function id(): string {
		return 'resizer_output_format_writable';
	}

	public function label(): string {
		return __( 'Image resizer can write its output format', 'timber-kit' );
	}

	public function category(): string {
		return 'performance';
	}

	public function method(): string {
		return self::METHOD_EFFECT;
	}

	public function run(): Result {
		/**
		 * Read the format through the same filter the resizer reads, not the
		 * constant behind it: a project that switched the output to WebP must
		 * be told about WebP, and a project on the default about AVIF.
		 */
		$format = strtolower( (string) apply_filters( 'timber_kit_resizer_target_format', 'avif' ) );

		if ( in_array( $format, self::DELEGATE_FREE, true ) ) {
			return Result::good(
				sprintf(
					/* translators: %s: image format, e.g. "png". */
					__( 'The resizer writes %s, which every image backend supports without an external delegate.', 'timber-kit' ),
					$format
				)
			);
		}

		return $this->verdictToResult( $this->probe->probe( $format ), $format );
	}

	/**
	 * Map a probe verdict onto a Site Health result.
	 *
	 * Split from the probe so the judgement stays pure and testable without an
	 * image backend, mirroring how Utf8mb4Tables splits audit() from run().
	 */
	private function verdictToResult( string $verdict, string $format ): Result {
		switch ( $verdict ) {
			case ImageFormatProbe::VERDICT_OK:
				return Result::good(
					sprintf(
						/* translators: %s: image format, e.g. "avif". */
						__( 'The image backend writes %s and keeps transparency intact.', 'timber-kit' ),
						$format
					)
				);

			case ImageFormatProbe::VERDICT_NO_BACKEND:
				return Result::critical(
					__( 'Neither Imagick nor GD is available, so no image can be resized at all. Every image is served at its original size.', 'timber-kit' ),
					'<p>' . esc_html__( 'Install the Imagick PHP extension (preferred) or GD.', 'timber-kit' ) . '</p>'
				);

			case ImageFormatProbe::VERDICT_MISSING_DELEGATE:
				return Result::critical(
					sprintf(
						/* translators: %s: image format, e.g. "avif". */
						__( 'The resizer targets %s, but the image backend cannot write it — the format is not in its build. Every resized image silently drops out of the page.', 'timber-kit' ),
						$format
					),
					'<p>' . esc_html__( 'Ask the host to rebuild ImageMagick with the matching delegate (libaom + libavif for AVIF, libheif for HEIC/HEIF), or point the resizer at a format this build supports via the timber_kit_resizer_target_format filter.', 'timber-kit' ) . '</p>'
				);

			case ImageFormatProbe::VERDICT_WRITE_FAILED:
				return Result::critical(
					sprintf(
						/* translators: %s: image format, e.g. "avif". */
						__( 'The image backend lists %s but refuses to write it — the delegate is present for reading only. Every resized image silently drops out of the page.', 'timber-kit' ),
						$format
					),
					'<p>' . esc_html__( 'A read-only delegate usually means the encoder library is missing while the decoder is present. Ask the host to install the encoder (libaom for AVIF), or switch the resizer to another format via the timber_kit_resizer_target_format filter.', 'timber-kit' ) . '</p>'
				);

			case ImageFormatProbe::VERDICT_ALPHA_LOST:
				return Result::critical(
					sprintf(
						/* translators: %s: image format, e.g. "avif". */
						__( 'The image backend writes %s but discards transparency — a transparent test image came back opaque. Logos and cut-out images render on a solid black or white box.', 'timber-kit' ),
						$format
					),
					'<p>' . esc_html__( 'This is an outdated encoder, not a configuration error: ImageMagick builds before 7.x commonly flatten the alpha channel when writing AVIF. Ask the host to upgrade ImageMagick and its delegate libraries.', 'timber-kit' ) . '</p>'
				);
		}

		// A future verdict without a branch here must surface as "unknown",
		// never as a silent pass.
		return Result::recommended(
			sprintf(
				/* translators: %s: verdict identifier. */
				__( 'The output-format probe returned an unrecognised verdict (%s).', 'timber-kit' ),
				$verdict
			)
		);
	}
}
