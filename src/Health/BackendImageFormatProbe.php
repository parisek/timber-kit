<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health;

/**
 * Encodes a half-transparent 16x16 test image in the target format and reads
 * it back, entirely in memory.
 *
 * Why a real encode instead of a capability list: on Cloudways, ImageMagick
 * 6.9.11 listed AVIF and wrote AVIF happily — it just flattened the alpha
 * channel, shipping black boxes where transparent logos belonged. A format
 * list cannot see that; only reading a pixel back can.
 *
 * Backend selection mirrors Spatie\Image and Resizer::probeBackendFormats()
 * exactly — Imagick whenever the class exists, GD otherwise. A probe that
 * tested Imagick on a GD-only host would report a failure production never
 * hits.
 *
 * Cost is ~6 ms on a current build. That is why nothing here is cached: Site
 * Health direct tests run on one admin screen and in the weekly cron, so a
 * cache would buy milliseconds and owe a staleness bug in return.
 */
final class BackendImageFormatProbe implements ImageFormatProbe {

	/**
	 * Sample point inside the half drawn transparent.
	 */
	private const int SAMPLE_X = 12;
	private const int SAMPLE_Y = 8;

	public function probe( string $format ): string {
		if ( class_exists( '\Imagick' ) ) {
			return $this->probeImagick( $format );
		}

		if ( function_exists( 'imagecreatetruecolor' ) ) {
			return $this->probeGd( $format );
		}

		return self::VERDICT_NO_BACKEND;
	}

	/**
	 * @return self::VERDICT_*
	 */
	private function probeImagick( string $format ): string {
		try {
			$known = ( new \Imagick() )->queryFormats();
		} catch ( \Throwable $e ) {
			return self::VERDICT_NO_BACKEND;
		}

		if ( ! in_array( strtoupper( $format ), array_map( 'strtoupper', $known ), true ) ) {
			return self::VERDICT_MISSING_DELEGATE;
		}

		$source = null;
		$read   = null;

		try {
			$source = new \Imagick();
			$source->newImage( 16, 16, new \ImagickPixel( 'transparent' ) );
			$source->setImageAlphaChannel( \Imagick::ALPHACHANNEL_ACTIVATE );

			// A fully transparent image is a degenerate case some encoders
			// special-case, so keep half of it opaque.
			$draw = new \ImagickDraw();
			$draw->setFillColor( new \ImagickPixel( 'red' ) );
			$draw->rectangle( 0, 0, 7, 15 );
			$source->drawImage( $draw );

			$source->setImageFormat( $format );
			$blob = $source->getImageBlob();

			if ( '' === $blob ) {
				return self::VERDICT_WRITE_FAILED;
			}

			$read = new \Imagick();
			$read->readImageBlob( $blob );

			$colour = $read->getImagePixelColor( self::SAMPLE_X, self::SAMPLE_Y )->getColor( 1 );

			// Imagick reports opacity directly: 1.0 opaque, 0.0 transparent.
			// An absent key means no alpha channel survived at all.
			return self::alphaVerdict( isset( $colour['a'] ) ? (float) $colour['a'] : 1.0 );
		} catch ( \Throwable $e ) {
			return self::VERDICT_WRITE_FAILED;
		} finally {
			// Imagick holds native memory the GC does not reclaim on its own;
			// clear both handles on every path, including the throws above.
			if ( $source instanceof \Imagick ) {
				$source->clear();
			}
			if ( $read instanceof \Imagick ) {
				$read->clear();
			}
		}
	}

	/**
	 * @return self::VERDICT_*
	 */
	private function probeGd( string $format ): string {
		// Never `'image' . $format`: the format arrives from a public filter,
		// and a concatenated variable function would let any value pick an
		// arbitrary `image*()` function to call.
		$writer = match ( $format ) {
			'avif'  => 'imageavif',
			'webp'  => 'imagewebp',
			default => null,
		};

		if ( null === $writer || ! function_exists( $writer ) ) {
			return self::VERDICT_MISSING_DELEGATE;
		}

		$source = imagecreatetruecolor( 16, 16 );

		try {
			imagealphablending( $source, false );
			imagesavealpha( $source, true );

			$transparent = imagecolorallocatealpha( $source, 0, 0, 0, 127 );
			$opaque      = imagecolorallocate( $source, 255, 0, 0 );
			if ( false === $transparent || false === $opaque ) {
				return self::VERDICT_WRITE_FAILED;
			}

			imagefilledrectangle( $source, 0, 0, 15, 15, $transparent );
			imagefilledrectangle( $source, 0, 0, 7, 15, $opaque );

			$blob = $this->captureGdOutput( $writer, $source );
			if ( null === $blob || '' === $blob ) {
				return self::VERDICT_WRITE_FAILED;
			}

			$read = @imagecreatefromstring( $blob );
			if ( false === $read ) {
				return self::VERDICT_WRITE_FAILED;
			}

			try {
				imagesavealpha( $read, true );

				// GD packs alpha into bits 24-30 on an inverted scale: 0 is
				// opaque, 127 fully transparent. Normalise to opacity so both
				// backends are judged by one threshold.
				$packed = ( imagecolorat( $read, self::SAMPLE_X, self::SAMPLE_Y ) >> 24 ) & 0x7F;

				return self::alphaVerdict( 1.0 - ( $packed / 127.0 ) );
			} finally {
				imagedestroy( $read );
			}
		} catch ( \Throwable $e ) {
			return self::VERDICT_WRITE_FAILED;
		} finally {
			imagedestroy( $source );
		}
	}

	/**
	 * Run a GD writer with output buffering and always close the buffer.
	 *
	 * Isolated because an unbalanced ob_start() does not fail loudly — it
	 * swallows whatever wp-admin prints next. Null signals the writer failed.
	 */
	private function captureGdOutput( string $writer, \GdImage $image ): ?string {
		ob_start();
		try {
			// Literal calls, not `$writer( … )`: a variable invocation hides
			// from static analysis which function actually runs.
			$written = match ( $writer ) {
				'imageavif' => imageavif( $image ),
				'imagewebp' => imagewebp( $image ),
				default     => false,
			};
		} catch ( \Throwable $e ) {
			ob_end_clean();

			return null;
		}
		$blob = (string) ob_get_clean();

		return false === $written ? null : $blob;
	}
	/**
	 * Judge one sampled pixel taken from the half that was drawn transparent.
	 *
	 * Public and static because it is the one piece of this class that is pure,
	 * and it carries the threshold the whole check rests on: a wrong threshold
	 * would pass a host that flattens alpha, which is precisely the failure the
	 * check exists to catch. Not part of the ImageFormatProbe contract — only
	 * this implementation and its test use it.
	 *
	 * Lossy encoders never land exactly on 0, so the comparison is a midpoint
	 * rather than equality: above it the channel was flattened, below it merely
	 * compressed.
	 *
	 * @param float $opacity 0.0 fully transparent, 1.0 fully opaque.
	 * @return self::VERDICT_ALPHA_LOST|self::VERDICT_OK
	 */
	public static function alphaVerdict( float $opacity ): string {
		return $opacity > 0.5 ? self::VERDICT_ALPHA_LOST : self::VERDICT_OK;
	}
}
