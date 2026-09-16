<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Cli;

use Parisek\TimberKit\ImageCacheRegenerator;
use Parisek\TimberKit\Resizer;

/**
 * `wp timber-kit regenerate-image-cache` — re-encode resizer derivatives in
 * place, so none of them is ever missing.
 *
 * Thin adapter over {@see ImageCacheRegenerator}: it reads the resizer's own
 * filters for the cache directory, the uploads root and the default quality,
 * then delegates path parsing, selection and the temp-file-plus-rename write
 * to the regenerator, which is unit-tested. Encoding itself goes to
 * {@see Resizer::encodeVariant()}, so a regenerated file carries the bytes a
 * render would have produced. The WP_CLI I/O here is intentionally not
 * unit-tested.
 */
class RegenerateImageCacheCommand {

	/** Seconds between two load-average reads while the run waits. */
	private const int LOAD_POLL_SECONDS = 5;

	/** Longest a run waits for the load to fall before it stops cleanly. */
	private const int LOAD_WAIT_LIMIT_SECONDS = 1800;

	/**
	 * Re-encode resizer cache derivatives at their existing paths.
	 *
	 * Use this instead of `clear-image-cache` on a live site. Deleting a
	 * derivative leaves the page cache pointing at a file that is gone, and
	 * `<source type="image/avif">` has no fallback, so visitors see broken
	 * images until every page re-renders. This command writes each new file
	 * beside its target and renames it over the target, which is atomic on one
	 * filesystem: the URL never 404s and never serves half a file.
	 *
	 * A derivative is re-encoded from the parameters in its own path, so the
	 * command needs `$resizer_source_path_in_cache_key`. The flat layout names
	 * a derivative after the sanitised source stem, which does not say which
	 * file it came from; those are reported as unreadable and left alone.
	 *
	 * A derivative whose source file is gone is reported as an orphan and kept.
	 * Deleting it is `clear-image-cache`'s job.
	 *
	 * ## OPTIONS
	 *
	 * [<path>...]
	 * : Derivative files or directories under the cache directory, to narrow the
	 *   run. Absolute, or relative to the cache directory. A path resolving
	 *   outside the cache directory is refused. Default: every derivative.
	 *
	 * [--format=<format>]
	 * : Only derivatives of this output format, e.g. `avif`.
	 *
	 * [--older-than=<date>]
	 * : Only files modified before this moment: `2026-09-16`, `2026-09-16 22:00`,
	 *   or a relative `-2 days`. This is the resume mechanism. A regenerated file
	 *   carries a fresh mtime, so the next run with the same cutoff skips what the
	 *   last one finished and continues where it stopped. Start a sweep with the
	 *   moment the sweep began and every night after that reaches only leftovers.
	 *
	 * [--limit=<n>]
	 * : Stop after this many files. Default: all.
	 *
	 * [--sleep=<ms>]
	 * : Pause this many milliseconds between files. Default: 0.
	 *
	 * [--max-load=<n>]
	 * : Before each file, read the one-minute load average. While it is above
	 *   <n>, wait and re-check; after 30 minutes of waiting the run stops
	 *   cleanly and says how many files remain. Omitted: no load check. A
	 *   platform without a load average (`sys_getloadavg()` returns false)
	 *   never holds.
	 *
	 * [--apply]
	 * : Write the files. Without it the command only reports what it would do.
	 *
	 * [--verbose]
	 * : Log a line per file.
	 *
	 * ## EXAMPLES
	 *
	 *     wp timber-kit regenerate-image-cache --format=avif
	 *     wp timber-kit regenerate-image-cache --format=avif --apply
	 *     wp timber-kit regenerate-image-cache 1440x0-center-q80 --apply
	 *     wp timber-kit regenerate-image-cache --older-than='2026-09-16 22:00' --limit=500 --sleep=200 --max-load=4 --apply
	 *
	 * @param array<int, string>    $args       Paths under the cache directory.
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$apply   = isset( $assoc_args['apply'] );
		$verbose = isset( $assoc_args['verbose'] );
		$format  = isset( $assoc_args['format'] ) && '' !== $assoc_args['format'] ? (string) $assoc_args['format'] : null;
		$limit   = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;
		$sleep   = isset( $assoc_args['sleep'] ) ? max( 0, (int) $assoc_args['sleep'] ) : 0;
		$max_load = isset( $assoc_args['max-load'] ) ? (float) $assoc_args['max-load'] : null;

		$older_than = null;
		if ( isset( $assoc_args['older-than'] ) && '' !== $assoc_args['older-than'] ) {
			$parsed = strtotime( (string) $assoc_args['older-than'] );
			if ( false === $parsed ) {
				\WP_CLI::error( sprintf( 'Cannot read "%s" as a date.', $assoc_args['older-than'] ) );
			} else {
				$older_than = $parsed;
			}
		}

		$cache_dir   = (string) apply_filters( 'timber_kit_resizer_image_cache_dir', WP_CONTENT_DIR . '/cache/image' );
		$source_path = (bool) apply_filters( 'timber_kit_resizer_source_path_in_cache_key', false );
		$quality     = (int) apply_filters( 'timber_kit_resizer_target_quality', 80 );
		$uploads     = wp_get_upload_dir();
		$basedir     = (string) ( $uploads['basedir'] ?? '' );

		if ( ! $source_path ) {
			\WP_CLI::warning( 'timber_kit_resizer_source_path_in_cache_key is off, so most derivative paths do not name their source file. Run `wp timber-kit migrate-image-cache` first.' );
		}

		$regenerator = new ImageCacheRegenerator( $cache_dir, $basedir, $quality );
		$targets     = $regenerator->resolveTargets( array_values( array_map( 'strval', $args ) ) );
		foreach ( $targets['outside'] as $refused ) {
			\WP_CLI::warning( sprintf( '"%s" is not inside %s; skipped.', $refused, $cache_dir ) );
		}
		if ( array() !== $args && array() === $targets['paths'] ) {
			\WP_CLI::error( 'None of the given paths selected a derivative. Nothing to do.' );
		}

		$plan = $regenerator->plan( $targets['paths'], $format, $older_than, $limit );
		foreach ( $plan['orphan'] as $path ) {
			\WP_CLI::warning( sprintf( 'Source gone for %s; kept. Delete it with clear-image-cache.', $path ) );
		}
		if ( $verbose ) {
			foreach ( $plan['unreadable'] as $path ) {
				\WP_CLI::log( sprintf( 'unreadable %s', $path ) );
			}
		}

		$resizer  = new Resizer();
		$encoder  = fn ( array $variant, string $source, string $temp ): bool => $resizer->encodeVariant( $variant, $source, $temp );
		$verifier = fn ( string $path ): bool => ImageCacheRegenerator::decodes( $path );

		$started    = time();
		$done       = 0;
		$failed     = 0;
		$before     = 0;
		$after      = 0;
		$unreached  = $plan['remaining'];

		foreach ( $plan['entries'] as $index => $entry ) {
			if ( $apply && null !== $max_load && ! $this->waitForLoad( $max_load ) ) {
				$unreached += count( $plan['entries'] ) - $index;
				\WP_CLI::warning( sprintf( 'Load stayed above %s for %d minutes; stopping.', $max_load, self::LOAD_WAIT_LIMIT_SECONDS / 60 ) );
				break;
			}

			$result  = $regenerator->regenerate( $entry, $encoder, $verifier, ! $apply );
			$before += $result['before'];
			$after  += $result['after'];
			if ( 'failed' === $result['status'] ) {
				++$failed;
				\WP_CLI::warning( sprintf( 'Failed to re-encode %s; the old file is unchanged.', $result['path'] ) );
			} else {
				++$done;
			}

			if ( $verbose ) {
				$variant = $entry['variant'];
				\WP_CLI::log(
					sprintf(
						'%s %s (%dx%d %s q%d -> %s)',
						$result['status'],
						$result['path'],
						$variant['width'],
						$variant['height'],
						$variant['image_style'],
						$variant['quality'],
						$variant['format']
					)
				);
			}

			if ( $apply && $sleep > 0 ) {
				usleep( $sleep * 1000 );
			}
		}

		$summary = sprintf(
			'%s %d, failed %d, skipped %d, orphan %d, unreadable %d, not reached %d. %s -> %s in %ds.',
			$apply ? 'Regenerated' : 'Would regenerate',
			$done,
			$failed,
			$plan['skipped'],
			count( $plan['orphan'] ),
			count( $plan['unreadable'] ),
			$unreached,
			size_format( $before ),
			$apply ? size_format( $after ) : 'unknown',
			time() - $started
		);

		if ( ! $apply ) {
			\WP_CLI::success( $summary . ' Run with --apply to write.' );
			return;
		}
		\WP_CLI::success( $summary );
	}

	/**
	 * Hold until the one-minute load average is at or below the limit.
	 *
	 * Bounded on purpose: a cron run that waits forever is a run nobody knows
	 * is still going. When the limit runs out the caller stops cleanly and
	 * reports what is left, and the next run picks those files up.
	 *
	 * @return bool Whether the machine came back under the limit.
	 */
	private function waitForLoad( float $max ): bool {
		$waited = 0;
		while ( ImageCacheRegenerator::loadExceeded( $max, sys_getloadavg() ) ) {
			if ( $waited >= self::LOAD_WAIT_LIMIT_SECONDS ) {
				return false;
			}
			sleep( self::LOAD_POLL_SECONDS );
			$waited += self::LOAD_POLL_SECONDS;
		}
		return true;
	}
}
