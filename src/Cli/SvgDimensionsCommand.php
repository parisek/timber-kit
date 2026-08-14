<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Cli;

use Parisek\TimberKit\SvgDimensions;

/**
 * `wp timber-kit svg-dimensions` — give existing SVG attachments the
 * `width`/`height` metadata WordPress never measured for them.
 *
 * Thin adapter over {@see SvgDimensions}: it pages through SVG attachments and
 * delegates the per-attachment decision to the resolver, which is unit-tested.
 * The WP_CLI I/O here is intentionally not unit-tested.
 *
 * The upload filter only fires on new uploads, so every SVG already in the media
 * library needs this sweep once. Re-running it is safe and cheap — an attachment
 * that already has dimensions is skipped without the file being opened.
 */
class SvgDimensionsCommand {

	private const BATCH = 200;

	/**
	 * Derive and store intrinsic dimensions for SVG attachments.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would be written without touching the database.
	 *
	 * [--force]
	 * : Re-derive even where a non-zero size is already stored. Use this to
	 *   correct values another plugin resolved wrongly; without it, anything
	 *   already sized is left alone.
	 *
	 * [--limit=<n>]
	 * : Stop after processing this many attachments. Default: all.
	 *
	 * [--verbose]
	 * : Log a per-attachment line (ID + status + size) for auditing a large run.
	 *
	 * ## EXAMPLES
	 *
	 *     wp timber-kit svg-dimensions --dry-run
	 *     wp timber-kit svg-dimensions
	 *     wp timber-kit svg-dimensions --force --verbose
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );
		$force   = isset( $assoc_args['force'] );
		$verbose = isset( $assoc_args['verbose'] );
		$limit   = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;

		$resolver = new SvgDimensions();

		$derived    = 0;
		$sized      = 0;
		$unreadable = 0;
		$failed     = 0;
		$seen       = 0;
		$paged      = 1;

		do {
			$ids = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_mime_type' => 'image/svg+xml',
					'post_status'    => 'inherit',
					'posts_per_page' => self::BATCH,
					'paged'          => $paged,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $id ) {
				if ( $limit > 0 && $seen >= $limit ) {
					break 2;
				}
				++$seen;

				$result = $resolver->backfill( (int) $id, $force, $dry_run );

				if ( $verbose ) {
					\WP_CLI::log(
						sprintf(
							'#%d: %s (%sx%s)',
							$id,
							$result['status'],
							null === $result['width'] ? '?' : (string) $result['width'],
							null === $result['height'] ? '?' : (string) $result['height']
						)
					);
				}

				switch ( $result['status'] ) {
					case 'derived':
					case 'would_derive':
						++$derived;
						break;
					case 'already_sized':
						++$sized;
						break;
					case 'unreadable':
						++$unreadable;
						break;
					case 'failed':
						++$failed;
						\WP_CLI::warning( sprintf( 'Failed to store dimensions for attachment #%d.', $id ) );
						break;
				}
			}

			++$paged;
		} while ( true );

		// `unreadable` is reported separately rather than folded into "skipped".
		// A missing file and an SVG carrying no size at all are both real findings
		// worth chasing, and a single skipped count hides them behind the ones
		// that were correctly left alone.
		if ( $unreadable > 0 ) {
			\WP_CLI::warning(
				sprintf(
					'%d attachment(s) could not be read or carry no intrinsic size. Re-run with --verbose to list them.',
					$unreadable
				)
			);
		}

		\WP_CLI::success(
			sprintf(
				'%s dimensions for %d SVG(s) (%d already sized, %d unreadable, %d failed).',
				$dry_run ? 'Would store' : 'Stored',
				$derived,
				$sized,
				$unreadable,
				$failed
			)
		);
	}
}
