<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Cli;

use Parisek\TimberKit\OriginalImageRescaler;

/**
 * `wp timber-kit rescale-originals` — re-run the upload pipeline from the
 * originals WordPress preserved beside `-scaled` images, so a raised
 * `big_image_size_threshold` reaches images uploaded under a lower one.
 *
 * Thin adapter over {@see OriginalImageRescaler}: it selects `-scaled`
 * attachments, processes each file once, and delegates the per-attachment work
 * to the rescaler, which is unit-tested. The WP_CLI I/O here is intentionally
 * not unit-tested.
 *
 * Dry-run by default: the command rewrites attachment metadata and deletes
 * resizer cache derivatives, so writing is the explicit choice.
 */
class RescaleOriginalsCommand {

	private OriginalImageRescaler $rescaler;

	public function __construct( OriginalImageRescaler $rescaler ) {
		$this->rescaler = $rescaler;
	}

	/**
	 * Rescale `-scaled` images from their preserved originals.
	 *
	 * Run it before `timber-kit prune-originals`, never after: pruning deletes
	 * the originals this command reads.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs to process. Default: every attachment whose file is `-scaled`.
	 *
	 * [--apply]
	 * : Write the changes. Without it the command only reports the plan.
	 *
	 * [--limit=<n>]
	 * : Stop after processing this many files. Default: all.
	 *
	 * [--verbose]
	 * : Log a per-attachment line (ID, status, served size).
	 *
	 * ## EXAMPLES
	 *
	 *     wp timber-kit rescale-originals
	 *     wp timber-kit rescale-originals 232 270 --apply --verbose
	 *     wp timber-kit rescale-originals --apply
	 *
	 * @param array<int, string>    $args       Positional args (attachment IDs).
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		global $wpdb;

		$apply   = isset( $assoc_args['apply'] );
		$verbose = isset( $assoc_args['verbose'] );
		$limit   = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;

		$ids = array_map( 'intval', $args );
		if ( empty( $ids ) ) {
			$ids = array_map(
				'intval',
				(array) $wpdb->get_col(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE '%-scaled.%' ORDER BY post_id ASC"
				)
			);
		}

		$counts = array();
		$done   = array();
		$seen   = 0;

		foreach ( $ids as $id ) {
			// Rows over one file are rewritten together by the rescaler, so the
			// second row of a pair is already done by the time it comes up.
			$file = (string) get_post_meta( $id, '_wp_attached_file', true );
			if ( isset( $done[ $file ] ) ) {
				continue;
			}
			if ( $limit > 0 && $seen >= $limit ) {
				break;
			}
			++$seen;

			$result        = $this->rescaler->rescale( $id, ! $apply );
			$done[ $file ] = true;
			if ( $apply && in_array( $result['status'], array( 'restored', 'rescaled' ), true ) ) {
				$done[ (string) get_post_meta( $id, '_wp_attached_file', true ) ] = true;
			}

			$status            = $result['status'];
			$counts[ $status ] = ( $counts[ $status ] ?? 0 ) + 1;

			if ( 'failed' === $status ) {
				\WP_CLI::warning( sprintf( 'Failed to rescale attachment #%d; it was left unchanged.', $id ) );
			}
			if ( $verbose ) {
				\WP_CLI::log(
					sprintf(
						'#%d: %s %dx%d%s',
						$id,
						$status,
						$result['width'],
						$result['height'],
						$result['siblings'] ? ' (also #' . implode( ', #', $result['siblings'] ) . ')' : ''
					)
				);
			}
		}

		ksort( $counts );
		$summary = array();
		foreach ( $counts as $status => $count ) {
			$summary[] = $count . ' ' . $status;
		}

		\WP_CLI::success(
			sprintf(
				'%s %d file(s): %s.',
				$apply ? 'Processed' : 'Dry run over',
				$seen,
				$summary ? implode( ', ', $summary ) : 'nothing to do'
			)
		);
	}
}
