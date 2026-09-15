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
 *
 * Every write is journalled first. Core writes metadata while it works, so a
 * process killed half way leaves a row that no longer looks `-scaled`; the
 * journal is what lets the next run find that row and put it back.
 */
class OriginalImageRescaler {

	/** Post meta key holding the pre-rescale state of every affected row. */
	public const JOURNAL_KEY = '_timber_kit_rescale_journal';

	/** Suffix of the copy of the `-scaled` file kept until the rescale is verified. */
	private const BACKUP_SUFFIX = '.rescale-backup';

	/** Metadata keys `wp_create_image_subsizes()` owns. */
	private const CORE_KEYS = array( 'width', 'height', 'file', 'filesize', 'sizes', 'image_meta', 'original_image' );

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
	 *         unchanged, interrupted, not_scaled, no_original, missing, failed};
	 *         width and height describe the file served afterwards (planned, for
	 *         dry-run). `interrupted` is dry-run only: a previous run died on
	 *         this row, and the next real run puts it back before anything else.
	 */
	public function rescale( int $attachment_id, bool $dry_run = false ): array {
		$result = array(
			'status'   => '',
			'width'    => 0,
			'height'   => 0,
			'siblings' => array(),
		);

		$pending = get_post_meta( $attachment_id, self::JOURNAL_KEY, true );
		if ( is_array( $pending ) && ! empty( $pending['rows'] ) ) {
			if ( $dry_run ) {
				return array_merge( $result, array( 'status' => 'interrupted' ) );
			}
			$this->recover( $attachment_id, $pending );
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $metadata ) || empty( $metadata['original_image'] ) || empty( $metadata['file'] ) ) {
			return array_merge( $result, array( 'status' => 'no_original' ) );
		}
		if ( ! OriginalImagePruner::isScaledDerivative( $metadata['file'] ) ) {
			return array_merge( $result, array( 'status' => 'not_scaled' ) );
		}

		$original = wp_get_original_image_path( $attachment_id );
		// wp_getimagesize(), as core's own pipeline does, so the threshold filter
		// receives the same array here as inside wp_create_image_subsizes().
		$size = ( $original && is_file( $original ) ) ? wp_getimagesize( $original ) : false;
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

		// Measured on the file, not read from the row: rows over one file may
		// carry divergent metadata, and the file is what every one of them serves.
		$scaled_path = (string) get_attached_file( $attachment_id );
		$current     = self::longestEdge( $scaled_path ) ?? max( (int) ( $metadata['width'] ?? 0 ), (int) ( $metadata['height'] ?? 0 ) );
		if ( max( $width, $height ) <= $current ) {
			return array_merge( $result, array( 'status' => 'unchanged' ) );
		}

		// Before anything changes: the sibling query matches on _wp_attached_file.
		$siblings           = ( $this->find_siblings )( $attachment_id );
		$result['siblings'] = $siblings;

		if ( $dry_run ) {
			return array_merge( $result, array( 'status' => $fits ? 'would_restore' : 'would_rescale' ) );
		}

		$journal = $this->writeJournal( $attachment_id, $siblings, $scaled_path );
		if ( null === $journal ) {
			return array_merge( $result, array( 'status' => 'failed', 'siblings' => array() ) );
		}

		// While _wp_attached_file still names the -scaled file: the purger
		// locates derivatives by that name, and a rescale that keeps the name
		// would otherwise serve crops cut from the old, smaller file.
		( $this->purge_derivatives )( $attachment_id );

		// Core evaluates the threshold filter again. Pinned, so the file core
		// writes and the outcome checked below come from one decision.
		$pin = static fn (): int => $threshold;
		add_filter( 'big_image_size_threshold', $pin, PHP_INT_MAX );
		try {
			update_attached_file( $attachment_id, $original );

			if ( ! function_exists( 'wp_create_image_subsizes' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			$new_metadata = wp_create_image_subsizes( $original, $attachment_id );
		} catch ( \Throwable $e ) {
			$this->recover( $attachment_id, $journal );
			throw $e;
		} finally {
			remove_filter( 'big_image_size_threshold', $pin, PHP_INT_MAX );
		}

		$served = $this->verifyAndWrite( $attachment_id, $siblings, $journal, $new_metadata, $threshold, $current );
		if ( null === $served ) {
			$this->recover( $attachment_id, $journal );
			return array_merge( $result, array( 'status' => 'failed', 'siblings' => array() ) );
		}

		delete_post_meta( $attachment_id, self::JOURNAL_KEY );
		if ( is_file( $scaled_path . self::BACKUP_SUFFIX ) ) {
			unlink( $scaled_path . self::BACKUP_SUFFIX );
		}

		return array_merge(
			$result,
			array(
				'status' => $fits ? 'restored' : 'rescaled',
				'width'  => $served[0],
				'height' => $served[1],
			)
		);
	}

	/**
	 * Record every row's state and copy the `-scaled` file aside.
	 *
	 * The copy matters for a rescale: core writes the new `-scaled` file over
	 * the old one under the same name, so without it a rollback would restore
	 * metadata describing pixels that no longer exist.
	 *
	 * @param list<int> $siblings
	 * @return array{rows: array<int, array{attached_file: mixed, metadata: mixed}>, scaled_path: string}|null
	 */
	private function writeJournal( int $attachment_id, array $siblings, string $scaled_path ): ?array {
		$rows = array();
		foreach ( array_merge( array( $attachment_id ), $siblings ) as $row_id ) {
			$rows[ $row_id ] = array(
				'attached_file' => get_post_meta( $row_id, '_wp_attached_file', true ),
				'metadata'      => wp_get_attachment_metadata( $row_id ),
			);
		}
		$journal = array(
			'rows'        => $rows,
			'scaled_path' => $scaled_path,
		);

		if ( ! is_file( $scaled_path ) || ! copy( $scaled_path, $scaled_path . self::BACKUP_SUFFIX ) ) {
			return null;
		}

		update_post_meta( $attachment_id, self::JOURNAL_KEY, $journal );
		if ( get_post_meta( $attachment_id, self::JOURNAL_KEY, true ) !== $journal ) {
			unlink( $scaled_path . self::BACKUP_SUFFIX );
			return null;
		}

		return $journal;
	}

	/**
	 * Check core's actual outcome, then write it to every row and read it back.
	 *
	 * Core reports no failure: when the editor cannot load, resize or save, or
	 * a sub-size fails, it returns metadata anyway. So nothing is taken from the
	 * return value on trust. The outcome is what the database and the disk say.
	 *
	 * @param list<int>            $siblings
	 * @param array<string, mixed> $journal
	 * @param mixed                $new_metadata What wp_create_image_subsizes() returned.
	 * @return array{int, int}|null Served width and height, or null when anything does not hold.
	 */
	private function verifyAndWrite( int $attachment_id, array $siblings, array $journal, $new_metadata, int $threshold, int $previous_edge ): ?array {
		if ( ! is_array( $new_metadata ) || empty( $new_metadata['file'] ) ) {
			return null;
		}

		// The row must point at the file the metadata describes. Comparing the
		// two rather than a planned name also accepts what core may write
		// instead of the original: a `-rotated` copy, or one converted to
		// another format.
		$attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( $attached_file !== $new_metadata['file'] ) {
			return null;
		}

		$served = wp_getimagesize( (string) get_attached_file( $attachment_id ) );
		if ( false === $served ) {
			return null;
		}
		$edge = max( (int) $served[0], (int) $served[1] );
		if ( ( $threshold > 0 && $edge > $threshold ) || $edge <= $previous_edge ) {
			return null;
		}

		foreach ( array_merge( array( $attachment_id ), $siblings ) as $row_id ) {
			$previous = $journal['rows'][ $row_id ]['metadata'] ?? array();
			$merged   = array_merge( array_diff_key( is_array( $previous ) ? $previous : array(), array_flip( self::CORE_KEYS ) ), $new_metadata );

			if ( $row_id !== $attachment_id ) {
				update_post_meta( $row_id, '_wp_attached_file', $attached_file );
			}
			// Core saves sub-sizes as it goes but leaves the final write to the
			// caller. Keys core does not own belong to other plugins and to the
			// row itself, so they survive.
			wp_update_attachment_metadata( $row_id, $merged );

			// update_post_meta() returns false for an unchanged value too, so its
			// return says nothing. Reading the row back does. Loose comparison:
			// a metadata filter may reorder keys without changing a value.
			if ( get_post_meta( $row_id, '_wp_attached_file', true ) !== $attached_file
				|| wp_get_attachment_metadata( $row_id ) != $merged
			) {
				return null;
			}
		}

		if ( ! empty( wp_get_missing_image_subsizes( $attachment_id ) ) ) {
			return null;
		}

		return array( (int) $served[0], (int) $served[1] );
	}

	/**
	 * Put every journalled row and the `-scaled` file back, then drop the journal.
	 *
	 * Files core wrote on the way (sub-sizes, a `-rotated` or converted copy)
	 * stay on disk. Core names them from the original, so a later run that
	 * succeeds writes over them.
	 *
	 * @param array<string, mixed> $journal
	 */
	private function recover( int $attachment_id, array $journal ): void {
		foreach ( (array) ( $journal['rows'] ?? array() ) as $row_id => $row ) {
			update_post_meta( (int) $row_id, '_wp_attached_file', $row['attached_file'] );
			wp_update_attachment_metadata( (int) $row_id, $row['metadata'] );
		}

		$scaled_path = (string) ( $journal['scaled_path'] ?? '' );
		if ( '' !== $scaled_path && is_file( $scaled_path . self::BACKUP_SUFFIX ) ) {
			rename( $scaled_path . self::BACKUP_SUFFIX, $scaled_path );
		}

		delete_post_meta( $attachment_id, self::JOURNAL_KEY );
	}

	private static function longestEdge( string $path ): ?int {
		$size = ( '' !== $path && is_file( $path ) ) ? wp_getimagesize( $path ) : false;
		return false === $size ? null : max( (int) $size[0], (int) $size[1] );
	}
}
