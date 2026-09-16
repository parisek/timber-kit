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
	 * : Only files modified before this moment: `2026-09-16`,
	 *   `2026-09-16 22:00`, `2026-09-16T22:00:00+02:00`. This is the resume
	 *   mechanism. A regenerated file carries a fresh mtime, so the next run
	 *   with the same cutoff skips what the last one finished and continues
	 *   where it stopped. Start a sweep with the moment the sweep began and
	 *   every night after that reaches only leftovers.
	 *
	 *   A relative value (`-2 days`, `yesterday`) is refused: it moves with
	 *   the run, so a few nights later the same files are older than it again
	 *   and the sweep re-encodes work it already did.
	 *
	 *   A value with no timezone is read in the PHP process timezone, which
	 *   under cron is not necessarily the site's. Write the zone into the
	 *   value to settle it.
	 *
	 * [--limit=<n>]
	 * : Stop after this many files. Default: all.
	 *
	 * [--quality=<n>]
	 * : Quality for a derivative whose path carries no `-q<N>` segment.
	 *   Default: `timber_kit_resizer_target_quality`. Use it when the files
	 *   without a suffix were written at a quality the site no longer asks
	 *   for -- a per-call `quality => 95` variant, or, on a site whose cache
	 *   key carries the quality, the unsuffixed quality-100 files.
	 *
	 * [--threads=<n>]
	 * : Cap the threads Imagick gives one encode. One AVIF encode saturates
	 *   every core by default, and `--max-load` cannot see that coming,
	 *   because the one-minute average reports it a minute late. Reaches the
	 *   Imagick extension only; an ImageMagick binary reads
	 *   `MAGICK_THREAD_LIMIT` and `OMP_NUM_THREADS` from the environment.
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
	 *     wp timber-kit regenerate-image-cache --older-than='2026-09-16 22:00' --limit=500 --sleep=200 --max-load=2 --threads=1 --apply
	 *
	 * ## EXIT STATUS
	 *
	 * 0 on a run that reached the end with nothing failed. 1 when a file
	 * failed, or when the load gate gave up with work left, so cron and
	 * monitoring see it.
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
		$threads  = isset( $assoc_args['threads'] ) ? (int) $assoc_args['threads'] : 0;

		$older_than = null;
		if ( isset( $assoc_args['older-than'] ) && '' !== $assoc_args['older-than'] ) {
			$cutoff = ImageCacheRegenerator::parseCutoff( (string) $assoc_args['older-than'] );
			if ( $cutoff['relative'] ) {
				\WP_CLI::error(
					sprintf(
						'"%s" is relative, so it moves with every run and the same files fall before it again a few nights later. Give the moment the sweep started, e.g. --older-than=\'%s\'.',
						$assoc_args['older-than'],
						gmdate( 'Y-m-d H:i' )
					)
				);
			}
			if ( null === $cutoff['time'] ) {
				\WP_CLI::error( sprintf( 'Cannot read "%s" as a date.', $assoc_args['older-than'] ) );
			}
			$older_than = $cutoff['time'];
		}

		$cache_dir   = (string) apply_filters( 'timber_kit_resizer_image_cache_dir', WP_CONTENT_DIR . '/cache/image' );
		$source_path = (bool) apply_filters( 'timber_kit_resizer_source_path_in_cache_key', false );
		$quality     = isset( $assoc_args['quality'] )
			? (int) $assoc_args['quality']
			: (int) apply_filters( 'timber_kit_resizer_target_quality', 80 );
		$uploads     = wp_get_upload_dir();
		$basedir     = (string) ( $uploads['basedir'] ?? '' );

		if ( $threads > 0 && ! ImageCacheRegenerator::applyThreadLimit( $threads ) ) {
			\WP_CLI::warning( 'Imagick is not available here, so --threads reached nothing. Set MAGICK_THREAD_LIMIT and OMP_NUM_THREADS in the environment instead.' );
		}

		if ( ! $source_path ) {
			\WP_CLI::warning( 'timber_kit_resizer_source_path_in_cache_key is off, so most derivative paths do not name their source file. Run `wp timber-kit migrate-image-cache` first.' );
		}

		$regenerator = new ImageCacheRegenerator( $cache_dir, $basedir, $quality );

		if ( $apply && ! $regenerator->acquireLock() ) {
			\WP_CLI::error(
				sprintf(
					'Another regenerate-image-cache run holds %s. Two runs plan the same files and encode each one twice; wait for the first to finish.',
					$regenerator->lockPath()
				)
			);
		}

		if ( $apply ) {
			$swept = $regenerator->sweepStaleTemps();
			if ( array() !== $swept['deleted'] ) {
				\WP_CLI::log( sprintf( 'Swept %d temp file(s) left by a run that is no longer alive.', count( $swept['deleted'] ) ) );
			}
			if ( array() !== $swept['kept'] ) {
				\WP_CLI::log( sprintf( 'Left %d temp file(s) alone; their process is still running or their name carries no PID.', count( $swept['kept'] ) ) );
			}
		}

		$targets = $regenerator->resolveTargets( array_values( array_map( 'strval', $args ) ) );
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

		$this->warnAboutOwner( $plan['entries'] );

		$selected = 0;
		foreach ( $plan['entries'] as $entry ) {
			$selected += (int) filesize( (string) $entry['path'] );
		}
		$disk = $regenerator->diskPreflight( $selected );
		if ( ! $disk['ok'] ) {
			\WP_CLI::error(
				sprintf(
					'%s selected, so the run wants %s free on the cache filesystem and finds %s. A re-encode at the honoured quality is several times the size of one at the broken quality, so %.1fx is the floor. Narrow the run with --limit or free some space.',
					size_format( $selected ),
					size_format( $disk['needed'] ),
					size_format( $disk['free'] ),
					ImageCacheRegenerator::DISK_FACTOR
				)
			);
		}

		\WP_CLI::log(
			sprintf(
				'%d file(s) selected, %s now; the run wants %s free and finds %s. Paths without -q will be encoded at quality %d.',
				count( $plan['entries'] ),
				size_format( $selected ),
				size_format( $disk['needed'] ),
				-1 === $disk['free'] ? 'an unreadable amount' : size_format( $disk['free'] ),
				$quality
			)
		);

		// Without this a long run prints nothing at all until it ends, so
		// nobody can tell a slow encode from a hung one. --verbose already
		// prints a line per file, and a bar over that output is unreadable.
		$progress = null;
		if ( $apply && ! $verbose && array() !== $plan['entries'] && function_exists( '\WP_CLI\Utils\make_progress_bar' ) ) {
			$progress = \WP_CLI\Utils\make_progress_bar( 'Re-encoding', count( $plan['entries'] ) );
		}

		$gave_up    = false;
		$started    = time();
		$done       = 0;
		$failed     = 0;
		$suspect    = 0;
		$ratios     = array();
		$before     = 0;
		$after      = 0;
		$unreached  = $plan['remaining'];

		foreach ( $plan['entries'] as $index => $entry ) {
			if ( $apply && null !== $max_load && ! $this->waitForLoad( $max_load ) ) {
				$unreached += count( $plan['entries'] ) - $index;
				$gave_up    = true;
				\WP_CLI::warning( sprintf( 'Load stayed above %s for %d minutes; stopping.', $max_load, self::LOAD_WAIT_LIMIT_SECONDS / 60 ) );
				break;
			}

			$result  = $regenerator->regenerate( $entry, $encoder, $verifier, ! $apply );
			$before += $result['before'];
			$after  += $result['after'];
			if ( 'failed' === $result['status'] ) {
				++$failed;
				\WP_CLI::warning( sprintf( 'Failed to re-encode %s (%s); the old file is unchanged.', $result['path'], $result['reason'] ) );
			} else {
				++$done;
				if ( $apply ) {
					$ratios[] = $result['ratio'];
					if ( $result['suspect'] ) {
						++$suspect;
					}
				}
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

			if ( null !== $progress ) {
				$progress->tick();
			}

			if ( $apply && $sleep > 0 ) {
				usleep( $sleep * 1000 );
			}
		}

		if ( null !== $progress ) {
			$progress->finish();
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

		if ( $apply && array() !== $ratios ) {
			$summary .= sprintf(
				' Median size ratio %.2fx, %d suspect (at or below %.1fx, which usually means the encoder did not change).',
				ImageCacheRegenerator::median( $ratios ),
				$suspect,
				ImageCacheRegenerator::SUSPECT_RATIO
			);
		}

		if ( ! $apply ) {
			\WP_CLI::success( $summary . ' Run with --apply to write.' );
			return;
		}

		$status = ImageCacheRegenerator::exitStatus( $failed, $gave_up );
		if ( 0 !== $status ) {
			// The summary goes out first: a status alone says a run went
			// wrong, and the line above it says which files and why.
			\WP_CLI::log( $summary );
			\WP_CLI::halt( $status );
		}
		\WP_CLI::success( $summary );
	}

	/**
	 * Say so when this process does not own the files it is about to replace.
	 *
	 * A rename by another user writes a file that user owns. The mode is
	 * carried over, but a mode is only half the answer: `0644` owned by
	 * `root` is unreadable to nobody, while `0600` owned by `root` is
	 * unreadable to the web server, and the visitor sees a 403 on an
	 * `<source type="image/avif">` that has no fallback. Only the first
	 * mismatch is reported; a whole cache owned by someone else is one fact,
	 * not thousands.
	 *
	 * @param list<array<string, mixed>> $entries
	 */
	private function warnAboutOwner( array $entries ): void {
		if ( array() === $entries || ! function_exists( 'posix_geteuid' ) ) {
			return;
		}

		$me = posix_geteuid();
		foreach ( $entries as $entry ) {
			$owner = @fileowner( (string) $entry['path'] );
			if ( false === $owner || $owner === $me ) {
				continue;
			}
			\WP_CLI::warning(
				sprintf(
					'%s is owned by uid %d and this process runs as uid %d. The mode is carried over, but the new file belongs to uid %d; check the web server can still read it.',
					$entry['path'],
					$owner,
					$me,
					$me
				)
			);
			return;
		}
	}

	/**
	 * Hold until the one-minute load average is at or below the limit.
	 *
	 * Bounded on purpose: a cron run that waits forever is a run nobody knows
	 * is still going. When the limit runs out the caller stops cleanly and
	 * reports what is left, and the next run picks those files up.
	 *
	 * It logs when it starts holding and when it stops: a progress bar that
	 * stops moving looks the same as a stalled encode, and an operator who
	 * cannot tell them apart kills the run.
	 *
	 * @return bool Whether the machine came back under the limit.
	 */
	private function waitForLoad( float $max ): bool {
		$waited = 0;
		while ( ImageCacheRegenerator::loadExceeded( $max, sys_getloadavg() ) ) {
			if ( 0 === $waited ) {
				$loads = sys_getloadavg();
				\WP_CLI::log(
					sprintf(
						'Load is %.2f, above --max-load=%s. Holding, and re-checking every %d seconds for up to %d minutes.',
						is_array( $loads ) ? $loads[0] : 0.0,
						$max,
						self::LOAD_POLL_SECONDS,
						self::LOAD_WAIT_LIMIT_SECONDS / 60
					)
				);
			}
			if ( $waited >= self::LOAD_WAIT_LIMIT_SECONDS ) {
				return false;
			}
			sleep( self::LOAD_POLL_SECONDS );
			$waited += self::LOAD_POLL_SECONDS;
		}
		if ( $waited > 0 ) {
			\WP_CLI::log( sprintf( 'Load is back under %s after %ds; continuing.', $max, $waited ) );
		}
		return true;
	}
}
