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
		$size     = ( $original && is_file( $original ) ) ? getimagesize( $original ) : false;
		if ( ! $original || false === $size ) {
			return array_merge( $result, array( 'status' => 'missing' ) );
		}

		[ $width, $height ] = $size;
		$threshold          = (int) apply_filters( 'big_image_size_threshold', 2560, array( $width, $height ), $original, $attachment_id );
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

		if ( $dry_run ) {
			return array_merge( $result, array( 'status' => $fits ? 'would_restore' : 'would_rescale' ) );
		}

		$siblings      = ( $this->find_siblings )( $attachment_id );
		$attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );

		// While _wp_attached_file still names the -scaled file: the purger
		// locates derivatives by that name, and a rescale that keeps the name
		// would otherwise serve crops cut from the old, smaller file.
		( $this->purge_derivatives )( $attachment_id );

		update_attached_file( $attachment_id, $original );

		if ( ! function_exists( 'wp_create_image_subsizes' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$new_metadata = wp_create_image_subsizes( $original, $attachment_id );

		if ( is_wp_error( $new_metadata ) || ! is_array( $new_metadata ) || empty( $new_metadata['file'] ) ) {
			update_post_meta( $attachment_id, '_wp_attached_file', $attached_file );
			wp_update_attachment_metadata( $attachment_id, $metadata );
			return array_merge( $result, array( 'status' => 'failed' ) );
		}

		// Core saves sub-sizes as it goes but leaves the final write to the
		// caller, as wp_generate_attachment_metadata()'s callers do.
		wp_update_attachment_metadata( $attachment_id, $new_metadata );

		$result['width']  = (int) ( $new_metadata['width'] ?? $width );
		$result['height'] = (int) ( $new_metadata['height'] ?? $height );

		$new_attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		foreach ( $siblings as $sibling_id ) {
			update_post_meta( $sibling_id, '_wp_attached_file', $new_attached_file );
			wp_update_attachment_metadata( $sibling_id, $new_metadata );
		}
		$result['siblings'] = $siblings;

		return array_merge( $result, array( 'status' => $fits ? 'restored' : 'rescaled' ) );
	}
}
