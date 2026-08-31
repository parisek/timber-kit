<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Health\Image;

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

	/**
	 * Opacity above this counts as a flattened alpha channel.
	 *
	 * A midpoint rather than equality with 0: lossy encoders never land exactly
	 * on transparent, so the question is "is this pixel still see-through",
	 * not "is it bit-identical".
	 */
	private const float ALPHA_OPAQUE_THRESHOLD = 0.5;

	public function hasBackend(): bool {
		return class_exists( '\Imagick' ) || function_exists( 'imagecreatetruecolor' );
	}

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
		} catch ( \Throwable $e ) {
			return self::VERDICT_WRITE_FAILED;
		} finally {
			if ( $source instanceof \Imagick ) {
				$source->clear();
			}
		}

		// The encode succeeded. Everything below only verifies transparency, so
		// a failure here is "could not check", never "cannot write".
		try {
			$read = new \Imagick();
			$read->readImageBlob( $blob );

			$colour = $read->getImagePixelColor( self::SAMPLE_X, self::SAMPLE_Y )->getColor( 1 );

			// Imagick reports opacity directly: 1.0 opaque, 0.0 transparent.
			// An absent key means no alpha channel survived at all.
			return self::alphaVerdict( isset( $colour['a'] ) ? (float) $colour['a'] : 1.0 );
		} catch ( \Throwable $e ) {
			return self::VERDICT_UNVERIFIED;
		} finally {
			// Imagick holds native memory the GC does not reclaim on its own;
			// clear the handle on every path, including the throw above.
			if ( $read instanceof \Imagick ) {
				$read->clear();
			}
		}
	}

	/**
	 * @return self::VERDICT_*
	 */
	private function probeGd( string $format ): string {
		// One match, returning the call itself. Never `'image' . $format`: the
		// format arrives from a public filter, and a concatenated variable
		// function would let any value pick an arbitrary `image*()` function.
		// Closures rather than function names so the name is decided and
		// invoked in the same place — holding a name here and dispatching on it
		// elsewhere let the two lists drift, and a format present in one and
		// missing from the other silently read as a failed encode.
		$writer = match ( $format ) {
			'avif' => function_exists( 'imageavif' )
				? static fn ( \GdImage $image ): bool => imageavif( $image )
				: null,
			'webp' => function_exists( 'imagewebp' )
				? static fn ( \GdImage $image ): bool => imagewebp( $image )
				: null,
			// JPEG cannot carry an alpha channel, so probing it always yields
			// VERDICT_ALPHA_LOST. The check never asks for it — JPEG
			// short-circuits as delegate-free long before the probe runs — but
			// the tests do, as the one negative control a working backend can
			// actually produce.
			'jpeg' => function_exists( 'imagejpeg' )
				? static fn ( \GdImage $image ): bool => imagejpeg( $image )
				: null,
			default => null,
		};

		if ( null === $writer ) {
			return self::VERDICT_MISSING_DELEGATE;
		}

		$source = imagecreatetruecolor( 16, 16 );

		// Guarded, not assumed: under memory pressure GD returns false, and
		// every call below is typed for GdImage, so the first one would throw
		// a TypeError that escapes probe() and fatals the Site Health screen —
		// a health check taking down the page that shows health.
		if ( false === $source ) {
			return self::VERDICT_NO_BACKEND;
		}

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

			// The encode succeeded. Reading it back is a separate capability,
			// so a failure from here on is "could not check transparency",
			// never "cannot write".
			$read = @imagecreatefromstring( $blob );
			if ( false === $read ) {
				return self::VERDICT_UNVERIFIED;
			}

			imagesavealpha( $read, true );

			// GD packs alpha into bits 24-30 on an inverted scale: 0 is
			// opaque, 127 fully transparent. Normalise to opacity so both
			// backends are judged by one threshold.
			$packed = ( imagecolorat( $read, self::SAMPLE_X, self::SAMPLE_Y ) >> 24 ) & 0x7F;

			return self::alphaVerdict( 1.0 - ( $packed / 127.0 ) );
		} catch ( \Throwable $e ) {
			return self::VERDICT_WRITE_FAILED;
		}
	}

	/**
	 * Run a GD writer with output buffering and always close the buffer.
	 *
	 * Isolated because an unbalanced ob_start() does not fail loudly — it
	 * swallows whatever wp-admin prints next. Null signals the writer failed.
	 */
	/**
	 * @param \Closure(\GdImage): bool $writer
	 */
	private function captureGdOutput( \Closure $writer, \GdImage $image ): ?string {
		ob_start();
		try {
			$written = $writer( $image );
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
	 * @param float $opacity 0.0 fully transparent, 1.0 fully opaque.
	 * @return self::VERDICT_ALPHA_LOST|self::VERDICT_OK
	 */
	private static function alphaVerdict( float $opacity ): string {
		return $opacity > self::ALPHA_OPAQUE_THRESHOLD ? self::VERDICT_ALPHA_LOST : self::VERDICT_OK;
	}
}
