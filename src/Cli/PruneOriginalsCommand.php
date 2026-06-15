<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Cli;

use Parisek\TimberKit\OriginalImagePruner;

/**
 * `wp timber-kit prune-originals` — reclaim disk space from preserved `-scaled`
 * originals (the WP 5.3+ big-image mechanism).
 *
 * Thin adapter over {@see OriginalImagePruner}: it pages through image
 * attachments and delegates the per-attachment decision (the `-scaled` guard,
 * safe delete, metadata pointer cleanup) to the pruner, which is unit-tested.
 * The WP_CLI I/O here is intentionally not unit-tested.
 *
 * Run this as a deliberate, opt-in sweep — NOT on upload. WordPress regenerates
 * sub-sizes from the original, so prune only after the window in which new crop
 * sizes are likely to be added (hence `--older-than`).
 */
class PruneOriginalsCommand {

	private const BATCH = 200;

	/**
	 * Delete preserved full-resolution originals of `-scaled` images.
	 *
	 * ## OPTIONS
	 *
	 * [--older-than=<days>]
	 * : Only prune attachments uploaded more than this many days ago. Leaves a
	 *   window for high-quality thumbnail regeneration. Default: 0 (no limit).
	 *
	 * [--dry-run]
	 * : Report what would be reclaimed without deleting anything.
	 *
	 * [--limit=<n>]
	 * : Stop after processing this many candidate attachments. Default: all.
	 *
	 * [--verbose]
	 * : Log a per-attachment line (ID + status) for auditing a large run.
	 *
	 * ## EXAMPLES
	 *
	 *     wp timber-kit prune-originals --dry-run
	 *     wp timber-kit prune-originals --older-than=30
	 *     wp timber-kit prune-originals --older-than=30 --verbose
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$dry_run    = isset( $assoc_args['dry-run'] );
		$verbose    = isset( $assoc_args['verbose'] );
		$older_than = isset( $assoc_args['older-than'] ) ? max( 0, (int) $assoc_args['older-than'] ) : 0;
		$limit      = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;

		$pruner = new OriginalImagePruner();

		$deleted = 0;
		$bytes   = 0;
		$skipped = 0;
		$failed  = 0;
		$seen    = 0;
		$paged   = 1;

		do {
			$query_args = array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => self::BATCH,
				'paged'          => $paged,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			);

			if ( $older_than > 0 ) {
				$query_args['date_query'] = array(
					array( 'before' => $older_than . ' days ago' ),
				);
			}

			$ids = get_posts( $query_args );

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $id ) {
				if ( $limit > 0 && $seen >= $limit ) {
					break 2;
				}
				++$seen;

				$result = $pruner->prune( (int) $id, $dry_run );

				if ( $verbose ) {
					\WP_CLI::log( sprintf( '#%d: %s (%d bytes)', $id, $result['status'], $result['bytes'] ) );
				}

				switch ( $result['status'] ) {
					case 'deleted':
					case 'would_delete':
						++$deleted;
						$bytes += $result['bytes'];
						break;
					case 'failed':
						++$failed;
						\WP_CLI::warning( sprintf( 'Failed to delete original for attachment #%d.', $id ) );
						break;
					default:
						++$skipped;
				}
			}

			++$paged;
		} while ( true );

		$mb   = round( $bytes / 1048576, 1 );
		$verb = $dry_run ? 'Would reclaim' : 'Reclaimed';

		\WP_CLI::success(
			sprintf(
				'%s %s MB from %d originals (%d skipped, %d failed).',
				$verb,
				$mb,
				$deleted,
				$skipped,
				$failed
			)
		);
	}
}
