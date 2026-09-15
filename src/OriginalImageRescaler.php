<?php

declare(strict_types=1);

namespace Parisek\TimberKit;

/**
 * Re-runs WordPress's upload pipeline from the full-resolution original that
 * core preserved beside a `-scaled` derivative, so the current
 * `big_image_size_threshold` applies to images uploaded under a lower one.
 *
 * The counterpart of {@see OriginalImagePruner}: pruning deletes the original
 * this class reads, so a site that may raise its threshold later must rescale
 * before it prunes.
 *
 * Core's own `wp media regenerate` already reads the original, but it is not
 * enough on its own. `wp_create_image_subsizes()` rewrites `_wp_attached_file`
 * only when it downscales or rotates. For an original that now fits under the
 * threshold, the metadata would describe the original while the attached file
 * still names the old `-scaled` copy, and every URL would keep serving it.
 */
class OriginalImageRescaler {

	/** @var callable(int): void */
	private $purge_derivatives;

	/** @var callable(int): list<int> */
	private $find_siblings;

	/**
	 * @param callable(int): void      $purge_derivatives Deletes resizer cache derivatives of an attachment's current file.
	 * @param callable(int): list<int> $find_siblings     Returns the other attachment IDs whose `_wp_attached_file` equals this one's.
	 */
	public function __construct( callable $purge_derivatives, callable $find_siblings ) {
		$this->purge_derivatives = $purge_derivatives;
		$this->find_siblings     = $find_siblings;
	}

	/**
	 * Rescale one attachment from its preserved original.
	 *
	 * @param int  $attachment_id Attachment post ID.
	 * @param bool $dry_run       When true, report the plan without writing.
	 * @return array{status: string, width: int, height: int, siblings: list<int>}
	 *         status ∈ {restored, rescaled, would_restore, would_rescale,
	 *         unchanged, not_scaled, no_original, missing, failed}; width and
	 *         height describe the file served afterwards (planned, for dry-run).
	 */
	public function rescale( int $attachment_id, bool $dry_run = false ): array {
		$result   = array(
			'status'   => '',
			'width'    => 0,
			'height'   => 0,
			'siblings' => array(),
		);
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $metadata ) || empty( $metadata['original_image'] ) || empty( $metadata['file'] ) ) {
			return array_merge( $result, array( 'status' => 'no_original' ) );
		}
		if ( ! OriginalImagePruner::isScaledDerivative( $metadata['file'] ) ) {
			return array_merge( $result, array( 'status' => 'not_scaled' ) );
		}

		$original = wp_get_original_image_path( $attachment_id );
		// wp_getimagesize(), as core's own pipeline does, so the threshold filter
		// sees the same array at plan time and at apply time.
		$size     = ( $original && is_file( $original ) ) ? wp_getimagesize( $original ) : false;
		if ( ! $original || false === $size ) {
			return array_merge( $result, array( 'status' => 'missing' ) );
		}

		[ $width, $height ] = $size;
		$threshold          = (int) apply_filters( 'big_image_size_threshold', 2560, $size, $original, $attachment_id );
		$longest            = max( $width, $height );
		$fits               = $threshold <= 0 || $longest <= $threshold;

		if ( ! $fits ) {
			$ratio  = $threshold / $longest;
			$width  = (int) round( $width * $ratio );
			$height = (int) round( $height * $ratio );
		}
		$result['width']  = $width;
		$result['height'] = $height;

		// Nothing gains pixels when the threshold is not above the edge the
		// -scaled file already has.
		$current = max( (int) ( $metadata['width'] ?? 0 ), (int) ( $metadata['height'] ?? 0 ) );
		if ( max( $width, $height ) <= $current ) {
			return array_merge( $result, array( 'status' => 'unchanged' ) );
		}

		$siblings           = ( $this->find_siblings )( $attachment_id );
		$result['siblings'] = $siblings;

		if ( $dry_run ) {
			return array_merge( $result, array( 'status' => $fits ? 'would_restore' : 'would_rescale' ) );
		}

		$attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$rollback      = function () use ( $attachment_id, $attached_file, $metadata ): void {
			update_post_meta( $attachment_id, '_wp_attached_file', $attached_file );
			wp_update_attachment_metadata( $attachment_id, $metadata );
		};

		// While _wp_attached_file still names the -scaled file: the purger
		// locates derivatives by that name, and a rescale that keeps the name
		// would otherwise serve crops cut from the old, smaller file.
		( $this->purge_derivatives )( $attachment_id );

		try {
			update_attached_file( $attachment_id, $original );

			if ( ! function_exists( 'wp_create_image_subsizes' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			// Writes metadata itself, first right away and then per sub-size.
			$new_metadata = wp_create_image_subsizes( $original, $attachment_id );
		} catch ( \Throwable $e ) {
			$rollback();
			throw $e;
		}

		// Core reports no failure. When the editor cannot load, resize or save,
		// it returns metadata that still describes the unscaled original. So the
		// result is checked against the outcome this plan expects.
		$expected_file = $fits ? basename( $original ) : null;
		$new_file      = basename( (string) ( $new_metadata['file'] ?? '' ) );
		$succeeded     = '' !== $new_file && ( $fits
			? $new_file === $expected_file
			: OriginalImagePruner::isScaledDerivative( $new_file )
				&& max( (int) ( $new_metadata['width'] ?? 0 ), (int) ( $new_metadata['height'] ?? 0 ) ) <= $threshold );

		if ( ! $succeeded ) {
			$rollback();
			return array_merge( $result, array( 'status' => 'failed', 'siblings' => array() ) );
		}

		$result['width']  = (int) ( $new_metadata['width'] ?? $width );
		$result['height'] = (int) ( $new_metadata['height'] ?? $height );

		$new_attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		foreach ( array_merge( array( $attachment_id ), $siblings ) as $row_id ) {
			$row_metadata = $row_id === $attachment_id ? $metadata : wp_get_attachment_metadata( $row_id );
			if ( $row_id !== $attachment_id ) {
				update_post_meta( $row_id, '_wp_attached_file', $new_attached_file );
			}
			// Core saves sub-sizes as it goes but leaves the final write to the
			// caller. Keys core does not own belong to other plugins and to the
			// row itself, so they survive.
			wp_update_attachment_metadata( $row_id, self::mergeMetadata( is_array( $row_metadata ) ? $row_metadata : array(), $new_metadata ) );
		}

		return array_merge( $result, array( 'status' => $fits ? 'restored' : 'rescaled' ) );
	}

	/**
	 * Replace the keys core's pipeline owns, keep every other key of the row.
	 *
	 * @param array<string, mixed> $previous  The row's metadata before the rescale.
	 * @param array<string, mixed> $generated What wp_create_image_subsizes() returned.
	 * @return array<string, mixed>
	 */
	private static function mergeMetadata( array $previous, array $generated ): array {
		$core_keys = array( 'width', 'height', 'file', 'filesize', 'sizes', 'image_meta', 'original_image' );
		return array_merge( array_diff_key( $previous, array_flip( $core_keys ) ), $generated );
	}
}
