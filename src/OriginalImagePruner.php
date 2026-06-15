<?php

declare(strict_types=1);

namespace Parisek\TimberKit;

/**
 * Reclaims disk space by deleting the full-resolution original WordPress
 * preserves alongside a `-scaled` derivative (the WP 5.3+ big-image mechanism).
 *
 * Deliberately a deferred / batch operation, NOT an on-upload hook:
 * WordPress regenerates intermediate sub-sizes from the **original** (for best
 * quality — see `wp_create_image_subsizes()`), so deleting it on upload would
 * silently degrade any later thumbnail regeneration (new crop sizes, retina,
 * `wp media regenerate`) to double-compressed output. A scheduled / WP-CLI
 * sweep run after the redesign window leaves an interval where regeneration is
 * still high-quality, and is opt-in per site. See the `prune-originals` command.
 */
class OriginalImagePruner {

	/**
	 * Whether a stored `file` path is a size-driven `-scaled` derivative.
	 *
	 * The `-scaled` suffix is the *only* deterministic signal that WordPress
	 * downscaled the upload because it exceeded `big_image_size_threshold`.
	 * The `original_image` metadata key alone is NOT sufficient — WP also sets
	 * it for EXIF-rotation (`-rotated`) and format-conversion uploads, whose
	 * originals must NOT be pruned.
	 *
	 * @param string|null $file Relative `file` path from attachment metadata.
	 * @return bool
	 */
	public static function isScaledDerivative( ?string $file ): bool {
		if ( null === $file || '' === $file ) {
			return false;
		}

		return 1 === preg_match( '/-scaled\.[A-Za-z0-9]+$/', basename( $file ) );
	}

	/**
	 * Prune one attachment's preserved original, if it is a `-scaled` downscale.
	 *
	 * @param int  $attachment_id Attachment post ID.
	 * @param bool $dry_run       When true, report what would happen without deleting.
	 * @return array{status: string, bytes: int} status ∈ {deleted, would_delete,
	 *         not_scaled, no_original, missing, failed}; bytes = reclaimed (or
	 *         reclaimable, for dry-run) size of the original.
	 */
	public function prune( int $attachment_id, bool $dry_run = false ): array {
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $metadata ) || empty( $metadata['original_image'] ) || empty( $metadata['file'] ) ) {
			return array( 'status' => 'no_original', 'bytes' => 0 );
		}

		if ( ! self::isScaledDerivative( $metadata['file'] ) ) {
			return array( 'status' => 'not_scaled', 'bytes' => 0 );
		}

		$original = wp_get_original_image_path( $attachment_id );
		if ( ! $original || ! is_file( $original ) ) {
			return array( 'status' => 'missing', 'bytes' => 0 );
		}

		$bytes = (int) filesize( $original );

		if ( $dry_run ) {
			return array( 'status' => 'would_delete', 'bytes' => $bytes );
		}

		wp_delete_file( $original );

		// wp_delete_file() returns void — confirm the unlink actually happened
		// before mutating metadata, so a failed delete never strips the
		// still-valid original_image pointer (which would orphan the file and
		// break wp_get_original_image_path()).
		if ( file_exists( $original ) ) {
			return array( 'status' => 'failed', 'bytes' => 0 );
		}

		unset( $metadata['original_image'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return array( 'status' => 'deleted', 'bytes' => $bytes );
	}
}
