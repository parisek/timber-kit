<?php

declare(strict_types=1);

namespace Parisek\TimberKit;

use Spatie\Image\Image;
use Spatie\Image\Enums\CropPosition;
use Spatie\Image\Enums\Fit;

/**
 * Used for resizer Twig filter.
 */
class Resizer {

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
	 * Constructor - Initialize with default values that can be filtered
	 */
	public function __construct() {
		$this->target_format = apply_filters( 'timber_kit_resizer_target_format', self::DEFAULT_FORMAT );
		$this->target_quality = (int) apply_filters( 'timber_kit_resizer_target_quality', self::DEFAULT_QUALITY );
		$this->image_cache_dir = apply_filters( 'timber_kit_resizer_image_cache_dir', WP_CONTENT_DIR . self::CACHE_DIR_PATH );
		$this->force_regenerate = (bool) apply_filters( 'timber_kit_resizer_force_regenerate', self::FORCE_REGENERATE );
	}

	/**
	 * Check if file type is allowed for processing
	 *
	 * @param string $file_path File path to check.
	 * @return bool True if allowed, false otherwise.
	 */
	private function isAllowedImageType( string $file_path ): bool {
		$allowed_types = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp' ];
		$filetype = wp_check_filetype( $file_path );
		return in_array( $filetype['type'], $allowed_types, true );
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
		$target_url = content_url( 'cache/image/' . $target_dirname . '/' . $filename . '.' . $this->target_format );

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
	 * Calculate Shannon entropy for an image slice
	 *
	 * @param resource $gdImage GD image resource.
	 * @param int      $x       X coordinate of slice.
	 * @param int      $y       Y coordinate of slice.
	 * @param int      $width   Width of slice.
	 * @param int      $height  Height of slice.
	 * @return float Entropy value (0-8, higher = more detail).
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
	 * Create edge-detected copy of image for entropy analysis
	 *
	 * @param resource $gdImage Source GD image resource.
	 * @return resource Edge-detected GD image resource.
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
	 * Find optimal crop using entropy slice algorithm
	 *
	 * @param resource $gdImage    Edge-detected GD image.
	 * @param int      $cropWidth  Target crop width.
	 * @param int      $cropHeight Target crop height.
	 * @return array Rectangle with keys: x, y, width, height.
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
	 * Find optimal crop using entropy grid algorithm
	 *
	 * @param resource $gdImage    Edge-detected GD image.
	 * @param int      $cropWidth  Target crop width.
	 * @param int      $cropHeight Target crop height.
	 * @param int      $gridWidth  Grid cell width in pixels.
	 * @param int      $gridHeight Grid cell height in pixels.
	 * @param int      $subRows    Subgrid height in cells.
	 * @param int      $subCols    Subgrid width in cells.
	 * @return array Rectangle with keys: x, y, width, height.
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
	 * Generate responsive image variants
	 *
	 * @param array $image Image data array with src, alt, width, height, etc.
	 * @param array $variants Array of variant specifications [width, height, media, style, quality].
	 * @return array Array of processed image variants.
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

		// Validate source file is an allowed image type
		if ( ! $this->isAllowedImageType( $default_image['src'] ) ) {
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

		// if source file not exists return default image
		if ( ! file_exists( $source_path ) ) {
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
}
