<?php

declare(strict_types=1);

namespace Parisek\TimberKit;

/**
 * Moves existing resizer cache derivatives from the flat layout
 * (`<size>/<name>.<fmt>`) into the source-path layout
 * (`<size>/<source-relative-dir>/<name>.<fmt>`) — see
 * `docs/adr/0007-resizer-source-path-cache-key.md`.
 *
 * The cache filename drops the source's own extension: a derivative is named
 * `pathinfo( basename( $src ), PATHINFO_FILENAME )` plus the *target* format,
 * so `hero.avif` in the cache is keyed by `hero`, not `hero.avif`. That is why
 * `11.png` and `11.jpg` collide as surely as two `11.png` in different months
 * do, and why the lookup map passed to the constructor is built the same way.
 *
 * A candidate name can map to more than one source directory (the collision
 * this ADR exists to fix). Recovering which source a flat derivative actually
 * came from is exactly what the old layout destroyed, so an ambiguous name is
 * reported, never guessed.
 */
class ImageCacheMigrator {

	/**
	 * @param string                $cache_dir    Absolute path to the resizer
	 *                                            cache root (holds the size
	 *                                            directories directly).
	 * @param array<string, list<string>> $name_to_dirs Cache name (source
	 *                                            basename without extension,
	 *                                            run through
	 *                                            `sanitize_file_name()`)
	 *                                            mapped to its distinct
	 *                                            source directories.
	 */
	public function __construct(
		private readonly string $cache_dir,
		private readonly array $name_to_dirs
	) {
	}

	/**
	 * Plan the migration without touching the filesystem.
	 *
	 * `move` is keyed by absolute source paths because `apply()` calls
	 * `rename()` on them directly. `ambiguous`, `orphaned`, `conflict` and
	 * `already_in_place` use paths relative to the cache root instead,
	 * because they are printed to an operator and a short relative path
	 * reads better than a long absolute one — nothing downstream needs to
	 * `rename()` those.
	 *
	 * @return array{move: array<string,string>, ambiguous: array<string, list<string>>, orphaned: list<string>, conflict: list<string>, already_in_place: list<string>}
	 */
	public function plan(): array {
		$move             = array();
		$ambiguous        = array();
		$orphaned         = array();
		$conflict         = array();
		$already_in_place = array();

		foreach ( $this->candidates() as $relative => $absolute ) {
			$size_dir = dirname( $relative );
			$filename = basename( $relative );
			$name     = pathinfo( $filename, PATHINFO_FILENAME );

			$dirs = $this->name_to_dirs[ $name ] ?? array();

			if ( array() === $dirs ) {
				$orphaned[] = $relative;
				continue;
			}

			if ( count( $dirs ) > 1 ) {
				$ambiguous[ $relative ] = $dirs;
				continue;
			}

			// dirs[0] === '' means the source is a genuine root upload (no
			// year/month directory, or the whole site has them switched
			// off), so the flat path this candidate already sits at IS its
			// final location — there is nothing to move. Building $target
			// anyway would join in an empty path segment: the double slash
			// collapses and $target resolves to $absolute itself, so
			// is_file() on it is always true and would otherwise misreport
			// every such file as a conflict with itself.
			if ( '' === $dirs[0] ) {
				$already_in_place[] = $relative;
				continue;
			}

			$target = $this->cache_dir . '/' . $size_dir . '/' . $dirs[0] . '/' . $filename;

			if ( is_file( $target ) ) {
				$conflict[] = $relative;
				continue;
			}

			$move[ $absolute ] = $target;
		}

		return array(
			'move'             => $move,
			'ambiguous'        => $ambiguous,
			'orphaned'         => $orphaned,
			'conflict'         => $conflict,
			'already_in_place' => $already_in_place,
		);
	}

	/**
	 * Execute a plan produced by {@see plan()}. Never deletes, never
	 * overwrites — the target directory is created and the file is `rename()`d
	 * within the same filesystem, so the move is atomic and an interrupted run
	 * leaves no partial file.
	 *
	 * @param array{move: array<string,string>, ambiguous: array<string, list<string>>, orphaned: list<string>, conflict: list<string>, already_in_place: list<string>} $plan
	 * @return array{moved: int, failed: list<string>}
	 */
	public function apply( array $plan ): array {
		$moved  = 0;
		$failed = array();

		foreach ( $plan['move'] as $source => $target ) {
			// plan() only snapshots the filesystem; by the time this loop
			// reaches an entry, another process (a second invocation, a file
			// dropped by hand) may have created the target since. rename()
			// silently replaces an existing destination on POSIX, so the
			// "never overwrites" rule needs this re-check right here, not
			// just in plan().
			if ( is_file( $target ) ) {
				$failed[] = $source;
				continue;
			}

			$target_dir = dirname( $target );

			if ( ! is_dir( $target_dir ) && ! mkdir( $target_dir, 0777, true ) && ! is_dir( $target_dir ) ) {
				$failed[] = $source;
				continue;
			}

			if ( rename( $source, $target ) ) {
				++$moved;
			} else {
				$failed[] = $source;
			}
		}

		return array(
			'moved'  => $moved,
			'failed' => $failed,
		);
	}

	/**
	 * Files sitting directly inside a size directory (`<cache>/<size>/<file>`).
	 * Anything deeper already carries a source-relative-dir segment and is
	 * therefore already migrated, so it is not a candidate.
	 *
	 * @return array<string,string> Relative path (from `$cache_dir`) => absolute path.
	 */
	private function candidates(): array {
		$found = array();

		$size_dirs = glob( $this->cache_dir . '/*', GLOB_ONLYDIR );
		if ( false === $size_dirs ) {
			return $found;
		}

		foreach ( $size_dirs as $size_dir ) {
			$files = glob( $size_dir . '/*' );
			if ( false === $files ) {
				continue;
			}

			foreach ( $files as $file ) {
				if ( ! is_file( $file ) ) {
					continue;
				}

				$relative           = ltrim( substr( $file, strlen( $this->cache_dir ) ), '/' );
				$found[ $relative ] = $file;
			}
		}

		return $found;
	}
}
