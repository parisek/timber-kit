<?php

declare(strict_types=1);

/**
 * Resizer — Image resizing and optimization via Spatie/Image.
 *
 * @package Parisek\TimberKit
 */

namespace Parisek\TimberKit;

use Spatie\Image\Image;
use Spatie\Image\Enums\CropPosition;
use Spatie\Image\Enums\Fit;

/**
 * Handles on-the-fly image resizing, format conversion, and smart cropping.
 *
 * Generates responsive image variants from WordPress uploads, stores the
 * results in the content cache directory, and returns structured arrays
 * suitable for `<picture>` / `<img>` markup. Supports standard positional
 * cropping via Spatie/Image and entropy-based smart cropping via GD/Imagick.
 *
 * All defaults (format, quality, cache path, force-regenerate, aspect-classification
 * tolerance) are overridable through WordPress filters prefixed with `timber_kit_resizer_`.
 */
class Resizer {

	/**
	 * Default tolerance band for square aspect classification.
	 *
	 * A source image whose `width / height` ratio falls within `[1 - TOL, 1 + TOL]`
	 * is classified as 'square'. The 0.1 default covers most editor uploads that
	 * are intended to be square but have minor fuzz (1020×1000, 1050×950, etc.).
	 */
	private const float DEFAULT_ASPECT_TOLERANCE = 0.1;

	/**
	 * Default image quality (0-100)
	 */
	private const int DEFAULT_QUALITY = 100;

	/**
	 * Default target image format
	 */
	private const string DEFAULT_FORMAT = 'avif';

	/**
	 * Cache directory path relative to WP_CONTENT_DIR
	 */
	private const string CACHE_DIR_PATH = '/cache/image';

	/**
	 * Force regenerate images (ignore cache)
	 */
	private const bool FORCE_REGENERATE = false;

	/**
	 * Raster input formats the resizer is willing to process, as a MIME → backend
	 * format-tag map. The *desired* superset; the actual allow-list is this map
	 * intersected with what the active image backend (Imagick or GD) can decode
	 * at runtime (see `decodableFormats()`). jpeg/png/gif are the universal
	 * baseline; the rest are gated by backend capability. SVG is intentionally
	 * absent (vector — not raster-resizable); so are heic/heif *sequences* and ico.
	 *
	 * @var array<string, string>
	 */
	private const array DESIRED_INPUT_FORMATS = [
		'image/jpeg' => 'JPEG',
		'image/png'  => 'PNG',
		'image/gif'  => 'GIF',
		'image/webp' => 'WEBP',
		'image/bmp'  => 'BMP',
		'image/avif' => 'AVIF',
		'image/tiff' => 'TIFF',
		'image/heic' => 'HEIC',
		'image/heif' => 'HEIC', // libheif handles both; Imagick reports the family as HEIC.
	];

	/**
	 * Target image format
	 *
	 * @var string
	 */
	private string $target_format;

	/**
	 * Target image quality
	 *
	 * @var int
	 */
	private int $target_quality;

	/**
	 * Image cache directory path
	 *
	 * @var string
	 */
	private string $image_cache_dir;

	/**
	 * Force regenerate images
	 *
	 * @var bool
	 */
	private bool $force_regenerate;

	/**
	 * Whether to pass animated sources (animated AVIF / WebP / GIF) through
	 * untouched instead of re-encoding them. Default on — re-encoding an animated
	 * source flattens it to its first frame, so skipping is the safe default. Set
	 * false (via `StarterBase::$resizer_skip_animated` or the
	 * `timber_kit_resizer_skip_animated` filter) to restore the legacy re-encode.
	 *
	 * @var bool
	 */
	private bool $skip_animated;

	/**
	 * Memoized allow-list of decodable input MIME types (per request).
	 *
	 * @var array<int, string>|null
	 */
	private ?array $decodable_cache = null;

	/**
	 * Initialize resizer settings, each of which can be overridden via a WordPress filter.
	 *
	 * Filters available:
	 *   - `timber_kit_resizer_target_format`   — output image format (default: avif)
	 *   - `timber_kit_resizer_target_quality`  — output quality 0-100 (default: 100)
	 *   - `timber_kit_resizer_image_cache_dir` — absolute path to cache directory
	 *   - `timber_kit_resizer_force_regenerate` — skip cache and always regenerate
	 *   - `timber_kit_resizer_skip_animated`   — pass animated sources through untouched (default: true)
	 */
	public function __construct() {
		$this->target_format = apply_filters( 'timber_kit_resizer_target_format', self::DEFAULT_FORMAT );
		$this->target_quality = (int) apply_filters( 'timber_kit_resizer_target_quality', self::DEFAULT_QUALITY );
		$this->image_cache_dir = apply_filters( 'timber_kit_resizer_image_cache_dir', WP_CONTENT_DIR . self::CACHE_DIR_PATH );
		$this->force_regenerate = (bool) apply_filters( 'timber_kit_resizer_force_regenerate', self::FORCE_REGENERATE );
		$this->skip_animated = (bool) apply_filters( 'timber_kit_resizer_skip_animated', true );
	}

	/**
	 * Capability matrix of every input format the resizer is willing to handle,
	 * keyed by MIME type, with a boolean for whether the active image backend can
	 * actually decode it on this server.
	 *
	 * Callable for diagnostics, conditional UI, or pre-flight checks before
	 * pointing editors at a particular upload format.
	 *
	 * @return array<string, bool> e.g. `['image/jpeg' => true, 'image/heic' => false, …]`.
	 */
	public function supportedInputFormats(): array {
		$decodable = $this->decodableFormats();
		$matrix = [];
		foreach ( array_keys( self::DESIRED_INPUT_FORMATS ) as $mime ) {
			$matrix[ $mime ] = in_array( $mime, $decodable, true );
		}
		return $matrix;
	}

	/**
	 * Whether the active image backend can decode the given image MIME type.
	 *
	 * @param string $mime Image MIME type, e.g. `image/avif`.
	 * @return bool
	 */
	public function canDecode( string $mime ): bool {
		return in_array( $mime, $this->decodableFormats(), true );
	}

	/**
	 * The allow-list of input MIME types: the desired superset intersected with what
	 * the active backend can decode. Memoized per request.
	 *
	 * Filterable via `timber_kit_resizer_allowed_types` ( `array<int,string> $mimes,
	 * array<int,string> $backend_formats` ) for projects that need to force a format
	 * on or off regardless of the probe.
	 *
	 * @return array<int, string>
	 */
	private function decodableFormats(): array {
		if ( null !== $this->decodable_cache ) {
			return $this->decodable_cache;
		}

		$backend = $this->probeBackendFormats();
		$allowed = [];
		foreach ( self::DESIRED_INPUT_FORMATS as $mime => $format_tag ) {
			if ( in_array( $format_tag, $backend, true ) ) {
				$allowed[] = $mime;
			}
		}

		/**
		 * Filter the resizer's capability-gated input allow-list.
		 *
		 * @param array<int, string> $allowed Decodable input MIME types.
		 * @param array<int, string> $backend Backend format tags reported by the active driver.
		 */
		$allowed = apply_filters( 'timber_kit_resizer_allowed_types', $allowed, $backend );

		$this->decodable_cache = array_values( array_unique( $allowed ) );
		return $this->decodable_cache;
	}

	/**
	 * Whether a MIME type belongs to a container that can carry animation.
	 *
	 * Gate for `isAnimated()` — only AVIF / WebP / GIF can be multi-frame, so
	 * the common case (JPEG / PNG / BMP / TIFF / HEIC still images) skips the
	 * Imagick probe and the header read entirely: zero added cost per render.
	 *
	 * @param string $mime Image MIME type, e.g. `image/png`.
	 * @return bool
	 */
	private function isAnimatableType( string $mime ): bool {
		return in_array( $mime, [ 'image/avif', 'image/webp', 'image/gif' ], true );
	}

	/**
	 * Whether the source image is animated (multi-frame).
	 *
	 * Animated AVIF / WebP / GIF must NOT go through the resize pipeline: both
	 * backends used by processVariant() — Spatie\Image and Imagick's singular
	 * writeImage() — operate on a single raster surface and flatten the output
	 * to the first frame, silently destroying the animation. resizer() treats a
	 * positive result here like an unsupported type and returns the original.
	 *
	 * Single detection per source, in priority order:
	 *   1. Imagick frame count — authoritative when the extension is present, and
	 *      it reads the whole file so it isn't bounded by the byte sniff's window.
	 *   2. A backend-independent, structurally-parsed byte sniff — the GD-only
	 *      fallback (and the path when Imagick throws on the source).
	 * The byte sniff parses container structure (it does not scan for loose
	 * substrings), so when it has to run its false-positive rate is negligible.
	 * Note: on GD-only servers the sniff is bounded by its read window, so an
	 * animated GIF whose first frame exceeds that window can be missed — Imagick,
	 * when present, has no such limit.
	 *
	 * Callers must gate this behind `isAnimatableType()` — it assumes the source
	 * is one of the animatable containers and only reads enough to confirm.
	 *
	 * @param string $source_path Absolute filesystem path to the source image.
	 * @return bool True when the source has more than one frame.
	 */
	private function isAnimated( string $source_path ): bool {
		if ( extension_loaded( 'imagick' ) && class_exists( '\Imagick' ) ) {
			$probe = null;
			try {
				$probe = new \Imagick();
				$probe->pingImage( $source_path );
				return $probe->getNumberImages() > 1;
			} catch ( \Throwable $e ) {
				// Unreadable by Imagick — fall through to the byte sniff.
				unset( $e );
			} finally {
				if ( $probe instanceof \Imagick ) {
					$probe->clear();
					$probe->destroy();
				}
			}
		}

		return $this->sniffAnimated( $source_path );
	}

	/**
	 * Backend-independent animation sniff via structural container parsing.
	 *
	 * Reads a bounded header window and confirms animation from each container's
	 * actual structure — not loose substring scans, which false-positive on
	 * compressed payloads. Biased toward detecting animation (see isAnimated()):
	 * a missed frame is the dangerous case.
	 *
	 *   - GIF  — counts Image Descriptors via a block walk (`gifIsAnimated()`),
	 *            so non-looping GIFs and GIFs without Graphic Control Extensions
	 *            are still caught.
	 *   - WebP — reads the VP8X chunk's animation flag (bit 1) at its fixed
	 *            offset; a file without a VP8X chunk is a single image.
	 *   - AVIF — looks for the `avis` image-sequence brand strictly inside the
	 *            ISOBMFF `ftyp` box's brand list.
	 *
	 * @param string $source_path Absolute filesystem path to the source image.
	 * @return bool
	 */
	private function sniffAnimated( string $source_path ): bool {
		$handle = @fopen( $source_path, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		$head = (string) fread( $handle, 65536 );
		fclose( $handle );
		if ( '' === $head ) {
			return false;
		}

		// GIF87a / GIF89a — count image descriptors structurally.
		if ( 0 === strncmp( $head, 'GIF8', 4 ) ) {
			return $this->gifIsAnimated( $head );
		}

		// RIFF/WEBP — animation is declared by a VP8X chunk's animation flag.
		// Layout: "RIFF"(4) size(4) "WEBP"(4) "VP8X"(4) chunkSize(4, LE, =10) flags(1)…
		// Validate the VP8X header is structurally intact (present + canonical
		// 10-byte chunk size + buffer long enough) before reading the flags byte
		// at offset 20, so a malformed/truncated chunk can't be misread.
		if ( 0 === strncmp( $head, 'RIFF', 4 ) && 'WEBP' === substr( $head, 8, 4 ) ) {
			if ( 'VP8X' === substr( $head, 12, 4 )
				&& strlen( $head ) >= 21
				&& 10 === (int) ( unpack( 'V', substr( $head, 16, 4 ) )[1] ?? 0 ) ) {
				// Animation flag is bit 1 (0x02) of the flags byte at offset 20.
				return 0 !== ( ord( $head[20] ) & 0x02 );
			}
			return false;
		}

		// ISOBMFF (AVIF) — the 'avis' brand marks an image sequence. The ftyp box
		// layout is: size(4) 'ftyp'(4) major_brand(4) minor_version(4) compatible
		// brands(4·n). 'avis' can be the major brand OR a compatible brand; the
		// minor_version field (offset 12–15) is a version number, NOT a brand, so
		// it is skipped to avoid a false positive on version bytes spelling 'avis'.
		if ( 'ftyp' === substr( $head, 4, 4 ) ) {
			$box_size = (int) ( unpack( 'N', substr( $head, 0, 4 ) )[1] ?? 0 );
			$end = ( $box_size > 8 && $box_size <= strlen( $head ) ) ? $box_size : min( strlen( $head ), 64 );
			if ( 'avis' === substr( $head, 8, 4 ) ) { // major brand
				return true;
			}
			for ( $i = 16; $i + 4 <= $end; $i += 4 ) { // compatible brands
				if ( 'avis' === substr( $head, $i, 4 ) ) {
					return true;
				}
			}
			return false;
		}

		return false;
	}

	/**
	 * Count GIF Image Descriptors by walking the block stream; animated when
	 * more than one frame is found within the buffered window.
	 *
	 * Walks the documented GIF89a block structure — skipping the optional Global
	 * Color Table, then iterating Image Descriptors (`0x2C`, one per frame),
	 * Extension blocks (`0x21`), and the Trailer (`0x3B`) — and short-circuits
	 * as soon as a second frame appears. This is the GD-only fallback; Imagick,
	 * when present, is the authoritative counter.
	 *
	 * @param string $buf Buffered file head.
	 * @return bool
	 */
	private function gifIsAnimated( string $buf ): bool {
		$len = strlen( $buf );
		// Header (6) + Logical Screen Descriptor (7); packed field at offset 10.
		if ( $len < 13 ) {
			return false;
		}
		$packed = ord( $buf[10] );
		$pos = 13;
		if ( $packed & 0x80 ) { // Global Color Table present.
			$pos += 3 * ( 1 << ( ( $packed & 0x07 ) + 1 ) );
		}

		$frames = 0;
		while ( $pos < $len ) {
			$block = ord( $buf[ $pos ] );
			if ( 0x3B === $block ) { // Trailer.
				break;
			}
			if ( 0x2C === $block ) { // Image Descriptor → one frame.
				if ( ++$frames > 1 ) {
					return true;
				}
				$pos += 10; // Separator + 9 descriptor bytes.
				// Need the full descriptor AND at least the following LZW-min byte
				// within the buffer; otherwise the frame is cut off by the read
				// window — stop rather than derive sizes from truncated bytes.
				if ( $pos >= $len ) {
					break;
				}
				$img_packed = ord( $buf[ $pos - 1 ] );
				if ( $img_packed & 0x80 ) { // Local Color Table.
					$pos += 3 * ( 1 << ( ( $img_packed & 0x07 ) + 1 ) );
				}
				$pos += 1; // LZW minimum code size.
				$pos = $this->gifSkipSubBlocks( $buf, $pos );
			} elseif ( 0x21 === $block ) { // Extension.
				$pos += 2; // Introducer + label.
				$pos = $this->gifSkipSubBlocks( $buf, $pos );
			} else {
				break; // Malformed / unknown — stop walking.
			}
		}

		return false;
	}

	/**
	 * Advance past a GIF data sub-block sequence (length-prefixed runs ended by
	 * a zero-length block terminator).
	 *
	 * @param string $buf Buffered file head.
	 * @param int    $pos Position of the first sub-block length byte.
	 * @return int Position just past the block terminator.
	 */
	private function gifSkipSubBlocks( string $buf, int $pos ): int {
		$len = strlen( $buf );
		while ( $pos < $len ) {
			$size = ord( $buf[ $pos ] );
			$pos += 1;
			if ( 0 === $size ) { // Block terminator.
				break;
			}
			$pos += $size;
		}
		return $pos;
	}

	/**
	 * Probe the active image backend for the format tags it can decode.
	 *
	 * Mirrors Spatie/Image's own driver selection — Imagick when the extension is
	 * loaded, GD otherwise — so the reported capability matches what actually
	 * decodes the source. Returns Imagick-style upper-case tags (`JPEG`, `AVIF`,
	 * `HEIC`, `TIFF`, …) for uniform matching against DESIRED_INPUT_FORMATS.
	 *
	 * Extracted as a `protected` seam so tests can stub backend capability without
	 * a live Imagick/GD build.
	 *
	 * @return array<int, string>
	 */
	protected function probeBackendFormats(): array {
		if ( extension_loaded( 'imagick' ) && class_exists( '\Imagick' ) ) {
			try {
				$formats = ( new \Imagick() )->queryFormats();
				if ( ! empty( $formats ) ) {
					return $formats;
				}
			} catch ( \Throwable $e ) {
				// Fall through to GD probing below.
			}
		}

		// GD baseline (always decodable) plus build-dependent extras. GD has no
		// TIFF / HEIC / HEIF decode path at all, so those stay excluded here.
		$formats = [ 'JPEG', 'PNG', 'GIF' ];
		$info = function_exists( 'gd_info' ) ? gd_info() : [];
		if ( ! empty( $info['WebP Support'] ) ) {
			$formats[] = 'WEBP';
		}
		if ( ! empty( $info['AVIF Support'] ) ) {
			$formats[] = 'AVIF';
		}
		if ( function_exists( 'imagecreatefrombmp' ) ) {
			$formats[] = 'BMP';
		}
		return $formats;
	}

	/**
	 * Prepare default image array with metadata
	 *
	 * @param array $image Raw image data.
	 * @return array Formatted default image array.
	 */
	private function prepareDefaultImage( array $image ): array {
		return [
			'src' => $image['src'],
			'width' => $image['width'] ?? '',
			'height' => $image['height'] ?? '',
			'alt' => $image['alt'] ?? '',
			'caption' => $image['caption'] ?? '',
			'description' => $image['description'] ?? '',
		];
	}

	/**
	 * Normalize variant specifications into consistent format
	 *
	 * @param array $variants Raw variant specifications.
	 * @return array Normalized variants.
	 */
	private function normalizeVariants( array $variants ): array {
		$normalized = [];

		foreach ( $variants as $variant ) {
			$normalized[] = [
				'width' => ( isset( $variant[0] ) && ! empty( $variant[0] ) ) ? intval( $variant[0] ) : 0,
				'height' => ( isset( $variant[1] ) && ! empty( $variant[1] ) ) ? intval( $variant[1] ) : 0,
				'media' => ( isset( $variant[2] ) && ! empty( $variant[2] ) ) ? intval( $variant[2] ) : 0,
				'image_style' => ( isset( $variant[3] ) && ! empty( $variant[3] ) ) ? $variant[3] : 'center',
				'quality' => ( isset( $variant[4] ) && ! empty( $variant[4] ) ) ? intval( $variant[4] ) : $this->target_quality,
			];
		}

		// Sort by media value (largest first)
		usort( $normalized, function ( $a, $b ) {
			return $b['media'] - $a['media'];
		} );

		return $normalized;
	}

	/**
	 * Process a single image variant
	 *
	 * @param array  $variant       Variant specification.
	 * @param string $source_path   Source file path.
	 * @param string $filename      Sanitized filename.
	 * @param array  $default_image Default image data for metadata.
	 * @return array|null Processed image data or null on failure.
	 */
	private function processVariant( array $variant, string $source_path, string $filename, array $default_image ): ?array {
		$target_dirname = $variant['width'] . 'x' . $variant['height'] . '-' . $variant['image_style'];
		$target_dir = $this->image_cache_dir . '/' . $target_dirname;
		$target_path = $target_dir . '/' . $filename . '.' . $this->target_format;
		// Derive URL from the configured cache directory relative to WP_CONTENT_DIR
		$relative_cache_dir = $this->image_cache_dir;
		if ( defined( 'WP_CONTENT_DIR' ) && strpos( $relative_cache_dir, WP_CONTENT_DIR ) === 0 ) {
			$relative_cache_dir = substr( $relative_cache_dir, strlen( WP_CONTENT_DIR ) );
		}
		$relative_cache_dir = ltrim( (string) $relative_cache_dir, '/\\' );
		$target_url = content_url( $relative_cache_dir . '/' . $target_dirname . '/' . $filename . '.' . $this->target_format );

		// Skip processing if target file already exists (unless force regenerate is enabled)
		if ( ! file_exists( $target_path ) || $this->force_regenerate ) {
			// Create target directory if it doesn't exist
			if ( ! file_exists( $target_dir ) ) {
				$result = wp_mkdir_p( $target_dir );
				if ( ! $result ) {
					error_log( sprintf( 'Resizer: failed to create directory "%s"', $target_dir ) );
					return null;
				}
			}
			try {
				$imageGenerator = Image::load( $source_path );

				// Handle smart-crop using entropy analysis
				if ( $variant['image_style'] === 'smart-crop' && $variant['width'] > 0 && $variant['height'] > 0 ) {
					// Load source image with GD for entropy analysis
					$gdImage = imagecreatefromstring( file_get_contents( $source_path ) );
					if ( $gdImage === false ) {
						throw new \Exception( 'Failed to load image with GD' );
					}

					$imgWidth = imagesx( $gdImage );
					$imgHeight = imagesy( $gdImage );

					// Only apply smart crop if image is larger than target dimensions
					if ( $imgWidth > $variant['width'] || $imgHeight > $variant['height'] ) {
						// Create edge-detected image for entropy analysis
						$edgeImage = $this->createEdgeDetectedImage( $gdImage );

						// Use grid algorithm by default (better results)
						$cropRect = $this->getEntropyCropByGridding( $edgeImage, $variant['width'], $variant['height'] );

						// Clean up GD resources
						imagedestroy( $edgeImage );
						imagedestroy( $gdImage );

						// Apply crop using Imagick with calculated coordinates
						$imagick = new \Imagick( $source_path );
						// Preserve transparency for PNG/GIF sources
						if ( $imagick->getImageAlphaChannel() ) {
							$imagick->setImageAlphaChannel( \Imagick::ALPHACHANNEL_ACTIVATE );
						}
						$imagick->cropImage( $cropRect['width'], $cropRect['height'], $cropRect['x'], $cropRect['y'] );
						$imagick->setImageFormat( $this->target_format );
						$imagick->setImageCompressionQuality( $variant['quality'] );
						$imagick->writeImage( $target_path );
						$imagick->clear();
						$imagick->destroy();
					} else {
						// Image is smaller than target, just resize without cropping
						imagedestroy( $gdImage );
						$imageGenerator->format( $this->target_format );
						$imageGenerator->quality( $variant['quality'] );
						$imageGenerator->save( $target_path );
					}
				}
				// Check if both dimensions are provided for standard cropping
				elseif ( in_array( $variant['image_style'], [ 'crop', 'center', 'top', 'bottom', 'left', 'right' ], true ) && $variant['width'] > 0 && $variant['height'] > 0 ) {
					$position = $this->mapCropPosition( $variant['image_style'] );

					// Use fit with Fit::Crop which resizes the image to fill the dimensions
					// maintaining aspect ratio and cropping any overflow
					$imageGenerator->fit( Fit::Crop, $variant['width'], $variant['height'] );

					// Then crop to exact dimensions at the specified position
					$imageGenerator->crop( $variant['width'], $variant['height'], $position );

					$imageGenerator->format( $this->target_format );
					$imageGenerator->quality( $variant['quality'] );

					$imageGenerator->save( $target_path );
				} else {
					// Resize while maintaining aspect ratio; it is possible to provide only one dimension
					if ( $variant['width'] !== 0 ) {
						$imageGenerator->width( $variant['width'] );
					}
					if ( $variant['height'] !== 0 ) {
						$imageGenerator->height( $variant['height'] );
					}

					$imageGenerator->format( $this->target_format );
					$imageGenerator->quality( $variant['quality'] );

					$imageGenerator->save( $target_path );
				}

			} catch (\Exception $e) {
				error_log( sprintf( 'Resizer: failed to process "%s" to "%s": %s', $source_path, $target_path, $e->getMessage() ) );
				return null;
			}
		}

		// Derive MIME from target extension instead of accessing a protected property
		$filetype = wp_check_filetype( $target_path );
		$actual_mime = $filetype['type'] ?? 'image/' . $this->target_format;

		return [
			'src' => $target_url,
			'type' => $actual_mime,
			'width' => $variant['width'],
			'height' => $variant['height'],
			'media' => ( ! empty( $variant['media'] ) ) ? '(min-width: ' . $variant['media'] . 'px)' : '',
			'alt' => $default_image['alt'],
			'caption' => $default_image['caption'],
			'description' => $default_image['description'],
		];
	}

	/**
	 * Map crop position from Timber format to Spatie CropPosition enum
	 *
	 * @param string $position Crop position string.
	 * @return CropPosition Mapped crop position enum.
	 */
	private function mapCropPosition( string $position ): CropPosition {
		return match ( strtolower( $position ) ) {
			'top' => CropPosition::Top,
			'bottom' => CropPosition::Bottom,
			'left' => CropPosition::Left,
			'right' => CropPosition::Right,
			'center', 'crop' => CropPosition::Center,
			default => CropPosition::Center,
		};
	}

	/**
	 * Calculate Shannon entropy for a rectangular slice of a GD image.
	 *
	 * Builds a 256-bucket grayscale histogram for the slice and computes
	 * H = -Σ(p × log₂(p)). Higher values indicate more visual detail.
	 *
	 * @param \GdImage $gdImage GD image object (expected grayscale/edge-detected).
	 * @param int      $x       Left offset of the slice in pixels.
	 * @param int      $y       Top offset of the slice in pixels.
	 * @param int      $width   Width of the slice in pixels.
	 * @param int      $height  Height of the slice in pixels.
	 * @return float Shannon entropy value (0–8); 0 when slice has no pixels.
	 */
	private function calculateSliceEntropy( $gdImage, int $x, int $y, int $width, int $height ): float {
		$histogram = array_fill( 0, 256, 0 );
		$total_pixels = 0;

		// Build histogram of pixel values
		for ( $py = $y; $py < $y + $height && $py < imagesy( $gdImage ); $py++ ) {
			for ( $px = $x; $px < $x + $width && $px < imagesx( $gdImage ); $px++ ) {
				$rgb = imagecolorat( $gdImage, $px, $py );
				$gray = ( $rgb >> 16 ) & 0xFF; // Extract red channel (grayscale image)
				$histogram[ $gray ]++;
				$total_pixels++;
			}
		}

		if ( $total_pixels === 0 ) {
			return 0.0;
		}

		// Calculate Shannon entropy: H = -Σ(p * log2(p))
		$entropy = 0.0;
		foreach ( $histogram as $count ) {
			if ( $count > 0 ) {
				$probability = $count / $total_pixels;
				$entropy -= $probability * log( $probability, 2 );
			}
		}

		return $entropy;
	}

	/**
	 * Create a greyscale, edge-detected copy of a GD image for entropy analysis.
	 *
	 * Copies the source image, converts it to greyscale, applies the built-in
	 * edge-detection filter, then boosts contrast. The returned image must be
	 * destroyed by the caller when no longer needed.
	 *
	 * @param \GdImage $gdImage Source GD image object.
	 * @return \GdImage New GD image object with edge detection applied.
	 */
	private function createEdgeDetectedImage( $gdImage ) {
		$width = imagesx( $gdImage );
		$height = imagesy( $gdImage );

		// Create a copy
		$edgeImage = imagecreatetruecolor( $width, $height );
		imagecopy( $edgeImage, $gdImage, 0, 0, 0, 0, $width, $height );

		// Convert to grayscale
		imagefilter( $edgeImage, IMG_FILTER_GRAYSCALE );

		// Apply edge detection
		imagefilter( $edgeImage, IMG_FILTER_EDGEDETECT );

		// Enhance contrast
		imagefilter( $edgeImage, IMG_FILTER_CONTRAST, -10 );

		return $edgeImage;
	}

	/**
	 * Find the optimal crop rectangle using a coarse-to-fine entropy slice algorithm.
	 *
	 * Independently optimises the X and Y offsets by scanning horizontal and
	 * vertical slices of the edge-detected image for maximum Shannon entropy,
	 * first with a coarse step and then refined to single-pixel precision.
	 *
	 * @param \GdImage $gdImage    Edge-detected GD image object.
	 * @param int      $cropWidth  Desired crop width in pixels.
	 * @param int      $cropHeight Desired crop height in pixels.
	 * @return array{x: int, y: int, width: int, height: int} Optimal crop rectangle.
	 */
	private function getEntropyCropBySlicing( $gdImage, int $cropWidth, int $cropHeight ): array {
		$imgWidth = imagesx( $gdImage );
		$imgHeight = imagesy( $gdImage );

		// Find optimal X position (horizontal slice)
		$bestX = 0;
		$maxEntropyX = 0;
		$stepSize = max( 1, (int) ( ( $imgWidth - $cropWidth ) / 10 ) ); // Coarse search

		for ( $x = 0; $x <= $imgWidth - $cropWidth; $x += $stepSize ) {
			$entropy = $this->calculateSliceEntropy( $gdImage, $x, 0, $cropWidth, $imgHeight );
			if ( $entropy > $maxEntropyX ) {
				$maxEntropyX = $entropy;
				$bestX = $x;
			}
		}

		// Fine-tune X position in 1px steps
		$searchStart = max( 0, $bestX - $stepSize );
		$searchEnd = min( $imgWidth - $cropWidth, $bestX + $stepSize );
		for ( $x = $searchStart; $x <= $searchEnd; $x++ ) {
			$entropy = $this->calculateSliceEntropy( $gdImage, $x, 0, $cropWidth, $imgHeight );
			if ( $entropy > $maxEntropyX ) {
				$maxEntropyX = $entropy;
				$bestX = $x;
			}
		}

		// Find optimal Y position (vertical slice)
		$bestY = 0;
		$maxEntropyY = 0;
		$stepSize = max( 1, (int) ( ( $imgHeight - $cropHeight ) / 10 ) );

		for ( $y = 0; $y <= $imgHeight - $cropHeight; $y += $stepSize ) {
			$entropy = $this->calculateSliceEntropy( $gdImage, $bestX, $y, $cropWidth, $cropHeight );
			if ( $entropy > $maxEntropyY ) {
				$maxEntropyY = $entropy;
				$bestY = $y;
			}
		}

		// Fine-tune Y position
		$searchStart = max( 0, $bestY - $stepSize );
		$searchEnd = min( $imgHeight - $cropHeight, $bestY + $stepSize );
		for ( $y = $searchStart; $y <= $searchEnd; $y++ ) {
			$entropy = $this->calculateSliceEntropy( $gdImage, $bestX, $y, $cropWidth, $cropHeight );
			if ( $entropy > $maxEntropyY ) {
				$maxEntropyY = $entropy;
				$bestY = $y;
			}
		}

		return [
			'x' => $bestX,
			'y' => $bestY,
			'width' => $cropWidth,
			'height' => $cropHeight,
		];
	}

	/**
	 * Find optimal subgrid with maximum entropy
	 *
	 * @param array $entropyGrid 2D array of entropy values.
	 * @param int   $gridRows    Number of grid rows.
	 * @param int   $gridCols    Number of grid columns.
	 * @param float $cellWidth   Width of each grid cell.
	 * @param float $cellHeight  Height of each grid cell.
	 * @param int   $cropWidth   Target crop width.
	 * @param int   $cropHeight  Target crop height.
	 * @param int   $subRows     Subgrid height in cells.
	 * @param int   $subCols     Subgrid width in cells.
	 * @return array Rectangle with keys: x, y, width, height.
	 */
	private function findOptimalSubgrid( array $entropyGrid, int $gridRows, int $gridCols, float $cellWidth, float $cellHeight, int $cropWidth, int $cropHeight, int $subRows, int $subCols ): array {
		$maxEntropy = 0;
		$bestRow = 0;
		$bestCol = 0;

		// Slide subgrid window across entropy grid
		for ( $row = 0; $row <= $gridRows - $subRows; $row++ ) {
			for ( $col = 0; $col <= $gridCols - $subCols; $col++ ) {
				$totalEntropy = 0;

				// Sum entropy in current subgrid
				for ( $r = $row; $r < $row + $subRows; $r++ ) {
					for ( $c = $col; $c < $col + $subCols; $c++ ) {
						$totalEntropy += $entropyGrid[ $r ][ $c ] ?? 0;
					}
				}

				if ( $totalEntropy > $maxEntropy ) {
					$maxEntropy = $totalEntropy;
					$bestRow = $row;
					$bestCol = $col;
				}
			}
		}

		// Calculate center of best subgrid
		$subgridCenterX = ( $bestCol + $subCols / 2 ) * $cellWidth;
		$subgridCenterY = ( $bestRow + $subRows / 2 ) * $cellHeight;

		// Center crop on subgrid center
		$cropX = (int) max( 0, $subgridCenterX - $cropWidth / 2 );
		$cropY = (int) max( 0, $subgridCenterY - $cropHeight / 2 );

		return [
			'x' => $cropX,
			'y' => $cropY,
			'width' => $cropWidth,
			'height' => $cropHeight,
		];
	}

	/**
	 * Find the optimal crop rectangle using a grid-based entropy algorithm.
	 *
	 * Divides the image into a regular grid of cells, computes Shannon entropy
	 * for each cell, then slides a subgrid window across the entropy grid to
	 * find the region with the highest cumulative entropy. The final crop is
	 * centred on that subgrid. This generally produces better results than
	 * the slice algorithm for complex images.
	 *
	 * @param \GdImage $gdImage    Edge-detected GD image object.
	 * @param int      $cropWidth  Desired crop width in pixels.
	 * @param int      $cropHeight Desired crop height in pixels.
	 * @param int      $gridWidth  Width of each grid cell in pixels (default 16).
	 * @param int      $gridHeight Height of each grid cell in pixels (default 16).
	 * @param int      $subRows    Number of cell rows in the sliding subgrid window (default 3).
	 * @param int      $subCols    Number of cell columns in the sliding subgrid window (default 3).
	 * @return array{x: int, y: int, width: int, height: int} Optimal crop rectangle.
	 */
	private function getEntropyCropByGridding( $gdImage, int $cropWidth, int $cropHeight, int $gridWidth = 16, int $gridHeight = 16, int $subRows = 3, int $subCols = 3 ): array {
		$imgWidth = imagesx( $gdImage );
		$imgHeight = imagesy( $gdImage );

		// Calculate grid dimensions
		$gridCols = (int) ceil( $imgWidth / $gridWidth );
		$gridRows = (int) ceil( $imgHeight / $gridHeight );
		$cellWidth = $imgWidth / $gridCols;
		$cellHeight = $imgHeight / $gridRows;

		// Calculate entropy for each grid cell
		$entropyGrid = [];
		for ( $row = 0; $row < $gridRows; $row++ ) {
			$entropyGrid[ $row ] = [];
			for ( $col = 0; $col < $gridCols; $col++ ) {
				$x = (int) ( $col * $cellWidth );
				$y = (int) ( $row * $cellHeight );
				$w = (int) min( $cellWidth, $imgWidth - $x );
				$h = (int) min( $cellHeight, $imgHeight - $y );

				$entropyGrid[ $row ][ $col ] = $this->calculateSliceEntropy( $gdImage, $x, $y, $w, $h );
			}
		}

		// Find optimal subgrid
		return $this->findOptimalSubgrid( $entropyGrid, $gridRows, $gridCols, $cellWidth, $cellHeight, $cropWidth, $cropHeight, $subRows, $subCols );
	}

	/**
	 * Generate responsive image variants from a WordPress image array.
	 *
	 * Accepts the image array produced by Timber's `formatImage` helper (or a
	 * plain associative array with at least a `src` key) and a list of variant
	 * specifications. Each variant is a numerically-indexed array of the form
	 * `[width, height, media_breakpoint, image_style, quality]`. Missing values
	 * fall back to 0 / `'center'` / the configured default quality.
	 *
	 * Returns an array where each entry has the keys `src`, `type`, `width`,
	 * `height`, `media`, `alt`, `caption`, and `description`. The last entry
	 * is always the unmodified original image as a fallback. Returns an empty
	 * array when `$variants` is empty or `$image` has no usable `src`.
	 *
	 * @param array|mixed $image    Timber/WordPress image data. When an indexed
	 *                              array is passed (multiple sizes), the last
	 *                              element is used as the source image.
	 * @param array       $variants List of variant spec arrays
	 *                              `[width, height, media, image_style, quality]`.
	 * @return array Processed image variants, each with src/type/width/height/media/alt/caption/description keys.
	 */
	public function resizer( $image, array $variants ): array {

		// Validate variants parameter
		if ( empty( $variants ) || ! is_array( $variants ) ) {
			return [];
		}

		// formatImage will return an array of images just use the last one as original for processing
		if ( is_array( $image ) && isset( $image[0] ) ) {
			$image = end( $image );
		}

		// if empty src, something is not working correctly, return empty array
		if ( ! isset( $image['src'] ) || empty( $image['src'] ) ) {
			return [];
		}

		$default_image = $this->prepareDefaultImage( $image );

		// Resolve the source MIME once and reuse it for both the allow-list gate
		// and the animated-source gate below.
		$source_mime = (string) ( wp_check_filetype( $default_image['src'] )['type'] ?? '' );

		// Validate source file is a backend-decodable image type.
		if ( ! $this->canDecode( $source_mime ) ) {
			return [ $default_image ];
		}

		// Normalize and sort variants
		$normalized_variants = $this->normalizeVariants( $variants );

		$upload_dir = wp_upload_dir();
		$basedir = $upload_dir['basedir'];
		$baseurl = $upload_dir['baseurl'];

		// Sanitize filename to prevent path traversal attacks
		$filename = sanitize_file_name( pathinfo( basename( $default_image['src'] ), PATHINFO_FILENAME ) );

		// Get actual source file path by converting URL to filesystem path
		$source_path = str_replace( $baseurl, $basedir, $default_image['src'] );

		if ( ! file_exists( $source_path ) ) {
			$images = apply_filters(
				'timber_kit_resizer_missing_source_variants',
				null,
				$normalized_variants,
				$filename,
				$default_image,
				[
					'uploads_base_url' => $baseurl,
					'uploads_base_dir' => $basedir,
					'target_format' => $this->target_format,
					'image_cache_dir' => $this->image_cache_dir,
				]
			);
			if ( is_array( $images ) ) {
				$images[] = $default_image;
				return $images;
			}

			return [ $default_image ];
		}

		// Animated sources (animated AVIF / WebP / GIF) cannot survive the
		// single-frame re-encode pipeline — Spatie\Image and Imagick's singular
		// writeImage() both flatten to frame 0, silently dropping the animation.
		// Pass the original through untouched ($skip_animated, on by default) —
		// same contract as an unsupported type; cropping/scaling of an animated
		// source is then the consumer's CSS job. Set StarterBase::$resizer_skip_animated
		// false to restore the legacy (flattening) re-encode. $skip_animated is
		// checked first so the detection cost (Imagick probe / header read) is
		// skipped entirely when the legacy behaviour is selected.
		if ( $this->skip_animated
			&& $this->isAnimatableType( $source_mime )
			&& $this->isAnimated( $source_path ) ) {
			return [ $default_image ];
		}

		// Process each variant
		$images = [];
		foreach ( $normalized_variants as $variant ) {
			$processed = $this->processVariant( $variant, $source_path, $filename, $default_image );
			if ( $processed !== null ) {
				$images[] = $processed;
			}
		}

		// Add fallback image
		$images[] = $default_image;

		return $images;
	}

	/**
	 * Classify a source image's aspect orientation.
	 *
	 * Returns one of three buckets based on the `width / height` ratio of the
	 * source image, with a tolerance band around 1:1 controlling the 'square'
	 * classification:
	 *
	 *   - `aspect > 1 + tolerance` → 'landscape'
	 *   - `aspect < 1 - tolerance` → 'portrait'
	 *   - within ± tolerance        → 'square'
	 *
	 * Missing or non-numeric width/height in the image metadata falls back to
	 * 'landscape' so legacy uploads (pre-ACF imports, SVG without intrinsic
	 * dimensions) preserve the kit's historical wide-crop default — components
	 * that adopt `resizerAspect()` don't silently shift their rendering for
	 * legacy assets.
	 *
	 * The source element is picked the same way `resizer()` picks its source:
	 * the LAST entry of a multi-image array (per `Helpers::formatImage`'s
	 * convention that the last item is the original / largest variant). This
	 * keeps classification aligned with what the cropping pipeline will
	 * actually operate on.
	 *
	 * WordPress filter:
	 *
	 *   - `timber_kit_resizer_aspect_tolerance` — float, default 0.1.
	 *     Tighten with `add_filter( 'timber_kit_resizer_aspect_tolerance', fn() => 0.05 );`
	 *     for design-sensitive components that shouldn't classify near-square
	 *     sources as 'square'.
	 *
	 * @param array|mixed $image Image data — array from Helpers::formatImage()
	 *                           (multiple variants, last is the original) or a
	 *                           single image dict.
	 * @return string One of 'landscape', 'portrait', 'square'.
	 */
	public static function classifyAspect( $image ): string {
		if ( ! is_array( $image ) || empty( $image ) ) {
			return 'landscape';
		}

		// Mirror resizer()'s source selection — the last entry of a multi-
		// variant array is the original. Falls through to treating the input
		// as a single image dict when there's no integer-indexed 0 key.
		$source = isset( $image[0] ) ? end( $image ) : $image;

		if ( ! is_array( $source ) ) {
			return 'landscape';
		}

		$width = (float) ( $source['width'] ?? 0 );
		$height = (float) ( $source['height'] ?? 0 );

		if ( $width <= 0 || $height <= 0 ) {
			return 'landscape';
		}

		$tolerance = (float) apply_filters( 'timber_kit_resizer_aspect_tolerance', self::DEFAULT_ASPECT_TOLERANCE );

		// Compare in dimension space instead of `abs($width/$height - 1.0) <= $tol`:
		// the division-based form trips IEEE-754 representation noise at the
		// inclusive boundary (1100×1000 → 1.1000...0001, which fails `<= 0.1`).
		// Algebraically equivalent for positive dimensions, boundary-stable here.
		if ( abs( $width - $height ) <= $tolerance * $height ) {
			return 'square';
		}

		return $width > $height ? 'landscape' : 'portrait';
	}

	/**
	 * Aspect-aware variant of `resizer()`.
	 *
	 * Classifies the source image's orientation (via `classifyAspect()`),
	 * picks the matching tuple set from `$orientations`, and delegates to
	 * `resizer()`. Callers pass three named tuple sets keyed by orientation;
	 * the Twig surface for this is the polymorphic `|resizer` filter
	 * (StarterBase detects an orientation-keyed map as the single argument
	 * and routes here):
	 *
	 * ```twig
	 * item.image|resizer({
	 *     landscape: [['960', '720', '1280', 'crop'], …],
	 *     portrait:  [['720', '960', '1280', 'crop'], …],
	 *     square:    [['800', '800', '1280', 'crop'], …],
	 * })
	 * ```
	 *
	 * When the classified bucket's tuples are missing, falls back to the
	 * 'landscape' tuples — same fallback policy as `classifyAspect()` itself.
	 * If 'landscape' is also missing (no usable tuple set at all), returns
	 * the image array unchanged so the caller still has something to render
	 * rather than crashing with an empty `<picture>`.
	 *
	 * Composes naturally with `merge_resizer()` for art-direction layers —
	 * each layer's source is classified independently:
	 *
	 * ```twig
	 * merge_resizer(
	 *     item.image|resizer({ landscape: […], portrait: […], square: […] }),
	 *     item.image_mobile|resizer({ landscape: […], portrait: […], square: […] }),
	 * )
	 * ```
	 *
	 * @param array|mixed $image        Image data (same shape as `resizer()`).
	 * @param array       $orientations Map keyed by 'landscape' / 'portrait' /
	 *                                   'square'. Each value is a list of
	 *                                   variant spec arrays (`[w, h, media,
	 *                                   image_style, quality]`) — the same
	 *                                   tuple shape `resizer()` consumes.
	 * @return array Processed image variants matching the source orientation,
	 *               or the original image array if no usable tuples were found.
	 */
	public function resizerAspect( $image, array $orientations ): array {
		$bucket = self::classifyAspect( $image );

		// Pick the matched bucket's tuples when present AND non-empty;
		// otherwise fall through to 'landscape'. `??` alone would treat
		// `portrait => []` as "set" and skip the fallback — the caller's
		// intent for an empty list is "I haven't filled this bucket yet",
		// same semantic as a missing key.
		$matched = $orientations[ $bucket ] ?? null;
		$tuples = ( is_array( $matched ) && ! empty( $matched ) ) ? $matched : ( $orientations['landscape'] ?? [] );

		if ( empty( $tuples ) || ! is_array( $tuples ) ) {
			return is_array( $image ) ? $image : [];
		}

		return $this->resizer( $image, $tuples );
	}

	/**
	 * Detects whether a `|resizer` variadic-args list is an orientation-keyed
	 * map (single arg, associative, carries at least one of `landscape` /
	 * `portrait` / `square`) rather than positional tuple variants.
	 *
	 * Extracted as a static helper so the dispatch decision in
	 * `StarterBase::timber_twig()`'s Twig filter callback stays one line and
	 * the predicate is independently testable without going through a Twig
	 * environment or constructing a `Resizer` instance (whose constructor
	 * pulls in WP filters / globals).
	 *
	 * Returns false on multi-arg or non-array inputs — both shapes a tuple
	 * call could plausibly take. Tuples have integer keys so the recognised
	 * orientation strings can't collide with a positional tuple's contents.
	 *
	 * @param array<int, mixed> $variants The variadic tail captured by the
	 *                                    Twig filter callback (i.e. the
	 *                                    arguments after the piped image).
	 * @return bool True when the args should be dispatched to
	 *              `resizerAspect()`; false routes to `resizer()`.
	 */
	public static function isOrientationMap( array $variants ): bool {
		$first = $variants[0] ?? null;
		return count( $variants ) === 1
			&& is_array( $first )
			&& (
				array_key_exists( 'landscape', $first )
				|| array_key_exists( 'portrait', $first )
				|| array_key_exists( 'square', $first )
			);
	}
}
