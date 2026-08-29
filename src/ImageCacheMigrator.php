<?php

declare(strict_types=1);

namespace Parisek\TimberKit;

/**
 * Moves existing resizer cache derivatives from the flat layout
 * (`<size>/<name>.<fmt>`) into the source-path layout
 * (`<size>/<source-relative-dir>/<source-filename>.<fmt>`) — see
 * `docs/adr/0008-resizer-source-path-cache-key.md`.
 *
 * The flat derivative is named `pathinfo( basename( $src ), PATHINFO_FILENAME )`
 * plus the *target* format, so `hero.avif` in the cache is keyed by `hero`, not
 * `hero.avif`. That is why `11.png` and `11.jpg` collide as surely as two
 * `11.png` in different months do, and why the lookup map passed to the
 * constructor is keyed the same (extension-stripped) way.
 *
 * The new layout keys on the source's whole identity, extension included, so
 * the map's *values* must be full source paths (`_wp_attached_file`, e.g.
 * `2026/08/hero.webp`) rather than bare directories — the target filename
 * needs the source's own extension, not just its directory. A candidate name
 * can still map to more than one distinct source path: two files sharing a
 * directory and stem but not an extension (`hero.jpg` / `hero.png`) are two
 * identities that collided under the old flat name. Recovering which one a
 * flat derivative actually came from is exactly what the old layout
 * destroyed, so an ambiguous name is reported, never guessed.
 */
class ImageCacheMigrator {

	/**
	 * Basename (own extension included) of every root-upload (no directory)
	 * source path in `$name_to_source_paths`, mapped back to its distinct
	 * full source paths. A root upload's migrated derivative sits at the same
	 * depth in the size directory as an unmigrated legacy one -- this index
	 * is what lets `plan()` tell "already produced by the new layout" apart
	 * from "still a legacy name to migrate" without relying on path depth.
	 *
	 * @var array<string, list<string>>
	 */
	private readonly array $root_target_index;

	/**
	 * @param string                      $cache_dir           Absolute path to
	 *                                            the resizer cache root (holds
	 *                                            the size directories directly).
	 * @param array<string, list<string>> $name_to_source_paths Cache name (source
	 *                                            basename without extension,
	 *                                            run through
	 *                                            `sanitize_file_name()`)
	 *                                            mapped to its distinct full
	 *                                            source paths (source-relative
	 *                                            directory plus the source's
	 *                                            own sanitized filename,
	 *                                            extension included; '' directory
	 *                                            means a root upload).
	 */
	public function __construct(
		private readonly string $cache_dir,
		private readonly array $name_to_source_paths
	) {
		$root_target_index = array();

		foreach ( $this->name_to_source_paths as $source_paths ) {
			foreach ( $source_paths as $source_path ) {
				if ( '' !== $this->dirOf( $source_path ) ) {
					continue;
				}

				$target_name = basename( $source_path );

				if ( ! isset( $root_target_index[ $target_name ] ) ) {
					$root_target_index[ $target_name ] = array();
				}

				if ( ! in_array( $source_path, $root_target_index[ $target_name ], true ) ) {
					$root_target_index[ $target_name ][] = $source_path;
				}
			}
		}

		$this->root_target_index = $root_target_index;
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
			$size_dir      = dirname( $relative );
			$filename      = basename( $relative );
			$name          = pathinfo( $filename, PATHINFO_FILENAME );
			$target_format = pathinfo( $filename, PATHINFO_EXTENSION );

			// Dedup here too: two attachment rows can share one full source
			// path (WPML files one row per language against the same file),
			// and that must read as one identity, not an ambiguous pair.
			//
			// The root-target index is merged in on purpose, not consulted
			// separately: a root upload's own migrated derivative (e.g.
			// `hero.png.avif`) sits at the very same depth in the size
			// directory as an unmigrated legacy candidate, so `candidates()`
			// cannot tell them apart by path shape alone. Folding both
			// interpretations into one set before counting means a name that
			// resolves the same way under both (the common case -- see
			// `test_root_upload_with_extensionless_source_name_is_already_in_place`)
			// still reads as unambiguous, while a name that resolves to two
			// distinct identities (ADR 0008's aliasing case: `hero.png` the
			// root upload vs. `hero.png.jpg` a legacy source -- both strip to
			// `hero.png`) reads as ambiguous exactly as any other collision
			// would. Recovering which one produced the file on disk is
			// exactly what the flat layout destroyed.
			$source_paths = array_values( array_unique( array_merge(
				$this->root_target_index[ $name ] ?? array(),
				$this->name_to_source_paths[ $name ] ?? array()
			) ) );

			if ( array() === $source_paths ) {
				$orphaned[] = $relative;
				continue;
			}

			if ( count( $source_paths ) > 1 ) {
				$ambiguous[ $relative ] = $source_paths;
				continue;
			}

			$source_path = $source_paths[0];
			$source_dir  = $this->dirOf( $source_path );
			$source_name = basename( $source_path );

			$target_filename = $source_name . '.' . $target_format;
			$target          = '' === $source_dir
				? $this->cache_dir . '/' . $size_dir . '/' . $target_filename
				: $this->cache_dir . '/' . $size_dir . '/' . $source_dir . '/' . $target_filename;

			// A root upload whose source has no extension of its own
			// resolves to the exact path the candidate already sits at
			// (the sole case the target filename equals the flat filename)
			// -- there is nothing to move.
			if ( $target === $absolute ) {
				$already_in_place[] = $relative;
				continue;
			}

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
	 * overwrites.
	 *
	 * Moves via `link()` then `unlink()`, not `rename()`: POSIX `rename()`
	 * silently replaces an existing destination, and a target can appear in
	 * the gap between this method's own `is_file()` check and the syscall
	 * that acts on it -- the exact TOCTOU window a plain existence check
	 * cannot close. `link()` is atomic against that race: the filesystem
	 * itself refuses to create a second name over an existing one, so it
	 * either creates the new name or leaves everything untouched, never a
	 * partial link. Only once the new name exists do we `unlink()` the old
	 * one -- a failure there could leave the file linked twice, never zero.
	 *
	 * @param array{move: array<string,string>, ambiguous: array<string, list<string>>, orphaned: list<string>, conflict: list<string>, already_in_place: list<string>} $plan
	 * @return array{moved: int, failed: list<string>}
	 */
	public function apply( array $plan ): array {
		$moved  = 0;
		$failed = array();

		foreach ( $plan['move'] as $source => $target ) {
			$target_dir = dirname( $target );

			if ( ! is_dir( $target_dir ) && ! mkdir( $target_dir, 0777, true ) && ! is_dir( $target_dir ) ) {
				$failed[] = $source;
				continue;
			}

			// link() fails outright (returns false, raises no fatal) when
			// $target already exists -- the plan()-time snapshot is stale by
			// then, another invocation or a hand-placed file got there
			// first. That failure leaves both $source and $target exactly
			// as they were, so there is nothing to unwind: no fallback
			// rename(), which would reopen the very race this replaces.
			if ( ! @link( $source, $target ) ) {
				$failed[] = $source;
				continue;
			}

			if ( unlink( $source ) ) {
				++$moved;
			} else {
				// The new name exists and the old one didn't go away: not a
				// data-loss case (both copies are intact), but not a clean
				// move either, so it is reported rather than silently
				// counted as done.
				$failed[] = $source;
			}
		}

		return array(
			'moved'  => $moved,
			'failed' => $failed,
		);
	}

	/**
	 * `dirname()` of a relative path, normalized so "no directory" is '' —
	 * `dirname()` itself returns '.' for a bare filename, which would
	 * otherwise be joined into the target path as a literal segment.
	 *
	 * @param string $relative_path e.g. `2026/08/hero.webp` or `hero.webp`.
	 * @return string
	 */
	private function dirOf( string $relative_path ): string {
		$dir = dirname( $relative_path );

		return '.' === $dir ? '' : $dir;
	}

	/**
	 * Files sitting directly inside a size directory (`<cache>/<size>/<file>`).
	 * Anything deeper already carries a source-relative-dir segment, so it is
	 * not a candidate. A file at this depth is not necessarily unmigrated,
	 * though: a root upload's own migrated derivative lands here too (its
	 * source-relative-dir is empty), so `plan()` -- not this method -- is
	 * what tells the two apart, via `$root_target_index`.
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
