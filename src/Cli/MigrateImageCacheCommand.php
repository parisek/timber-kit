<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Cli;

use Parisek\TimberKit\ImageCacheMigrator;

/**
 * `wp timber-kit migrate-image-cache` — move existing resizer cache
 * derivatives into the source-path layout ahead of enabling
 * `StarterBase::$resizer_source_path_in_cache_key`.
 *
 * Thin adapter over {@see ImageCacheMigrator}: it builds the
 * name-to-source-paths map from `_wp_attached_file` and delegates every
 * decision (unambiguous move, ambiguous collision, orphan, conflict) to the
 * migrator, which is unit-tested. The WP_CLI I/O here is intentionally not
 * unit-tested.
 */
class MigrateImageCacheCommand {

	/**
	 * Move flat-layout resizer cache derivatives into the source-path layout.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Actually move files. Without this flag the command only reports what
	 *   it would do.
	 *
	 * [--verbose]
	 * : List the ambiguous names with their candidate source paths.
	 *
	 * ## EXAMPLES
	 *
	 *     wp timber-kit migrate-image-cache
	 *     wp timber-kit migrate-image-cache --apply
	 *     wp timber-kit migrate-image-cache --verbose
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$apply   = isset( $assoc_args['apply'] );
		$verbose = isset( $assoc_args['verbose'] );

		$cache_dir = untrailingslashit( trailingslashit( WP_CONTENT_DIR ) . 'cache/image' );

		$migrator = new ImageCacheMigrator( $cache_dir, $this->buildNameToSourcePaths() );
		$plan     = $migrator->plan();

		$scanned = count( $plan['move'] ) + count( $plan['ambiguous'] ) + count( $plan['orphaned'] )
			+ count( $plan['conflict'] ) + count( $plan['already_in_place'] );

		\WP_CLI::log( sprintf( 'scanned          : %d', $scanned ) );
		\WP_CLI::log( sprintf( 'unambiguous      : %d  -> move', count( $plan['move'] ) ) );
		\WP_CLI::log( sprintf( 'ambiguous        : %d  -> left in place', count( $plan['ambiguous'] ) ) );
		\WP_CLI::log( sprintf( 'orphaned         : %d', count( $plan['orphaned'] ) ) );
		\WP_CLI::log( sprintf( 'conflicts        : %d', count( $plan['conflict'] ) ) );
		\WP_CLI::log( sprintf( 'already in place : %d  -> root uploads, nothing to move', count( $plan['already_in_place'] ) ) );

		if ( $verbose && array() !== $plan['ambiguous'] ) {
			\WP_CLI::log( '' );
			foreach ( $plan['ambiguous'] as $relative => $dirs ) {
				\WP_CLI::log( sprintf( '  %s: %s', $relative, implode( ', ', $dirs ) ) );
			}
		}

		if ( ! $apply ) {
			\WP_CLI::log( '(dry run -- nothing moved)' );
			return;
		}

		$result = $migrator->apply( $plan );

		// Warnings, then the exit-code-bearing call, in that order: a script
		// harness reads only the exit code, and WP_CLI::success() reports 0
		// regardless of what ran before it. Calling it while $result['failed']
		// is non-empty would report success on a run that left files
		// unmoved -- WP_CLI::error() exits non-zero instead.
		foreach ( $result['failed'] as $source ) {
			\WP_CLI::warning( sprintf( 'Failed to move %s.', $source ) );
		}

		if ( array() !== $result['failed'] ) {
			\WP_CLI::error( sprintf( 'Moved %d derivatives (%d failed).', $result['moved'], count( $result['failed'] ) ) );
			return;
		}

		\WP_CLI::success( sprintf( 'Moved %d derivatives (0 failed).', $result['moved'] ) );
	}

	/**
	 * Build the cache-name => full-source-paths map from `_wp_attached_file`.
	 *
	 * The value is a full source identity -- directory and the source's own
	 * filename, extension included -- not just a directory: the target
	 * filename the migrator builds needs the source's extension too (ADR
	 * 0007's amendment), so a bare directory can no longer carry enough
	 * information on its own.
	 *
	 * The directory half still goes through {@see guardSourceDir()} first. A
	 * directory it rejects contributes just the bare (sanitized) filename,
	 * no directory prefix -- the same shape a genuine root upload
	 * contributes -- rather than being dropped. Both cases mean the same
	 * thing to the flag-enabled `Resizer` (`Resizer::sourcePathSegment()`):
	 * the derivative stays at the flat cache key. Dropping a rejected
	 * attachment instead would erase it from the map entirely, so a real
	 * collision at that flat key -- another attachment sharing the basename
	 * -- would look unambiguous and move a derivative that in fact belongs
	 * to the dropped attachment.
	 *
	 * Deduped on the full source path, not the directory: two attachment
	 * rows can point at one identical file (WPML files one row per
	 * language), and that must collapse to one candidate, not read as an
	 * ambiguous pair.
	 *
	 * @return array<string, list<string>>
	 */
	private function buildNameToSourcePaths(): array {
		global $wpdb;

		$rows = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'" );

		$name_to_source_paths = array();

		foreach ( $rows as $row ) {
			if ( ! is_string( $row ) || '' === $row ) {
				continue;
			}

			$filename = basename( $row );

			// Two different spellings, deliberately.
			//
			// $name reproduces the LEGACY key: the flat files being migrated
			// were written by the old writer, which sanitized. Reading a
			// historical artefact means spelling it the historical way.
			//
			// $source_path is the NEW identity: the stored name verbatim
			// (ADR 0008). Sanitizing here would merge two distinct uploads --
			// `usp_1.webp` and `usp-1.webp` under a deployment that maps `_`
			// to `-` -- into a single candidate, and an ambiguity merged is an
			// ambiguity the planner then resolves confidently and wrongly.
			$name        = sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) );
			$guarded_dir = self::guardSourceDir( dirname( $row ) );

			if ( ! self::safePathComponent( $filename ) ) {
				continue;
			}

			$source_path = '' === $guarded_dir ? $filename : $guarded_dir . '/' . $filename;

			if ( ! isset( $name_to_source_paths[ $name ] ) ) {
				$name_to_source_paths[ $name ] = array();
			}

			if ( ! in_array( $source_path, $name_to_source_paths[ $name ], true ) ) {
				$name_to_source_paths[ $name ][] = $source_path;
			}
		}

		return $name_to_source_paths;
	}

	/**
	 * Mirror (not reuse — `Resizer::sourcePathSegment()` is private and takes
	 * a URL, not a relative path) the per-component guard the runtime applies
	 * before it will place a derivative under a source directory: every
	 * component must survive `sanitize_file_name()` unchanged and be neither
	 * empty nor `.`/`..`. One failing component voids the whole directory
	 * rather than being silently dropped or repaired — dropping just that
	 * component would quietly point the migration at a different directory
	 * than the one the upload is actually in.
	 *
	 * Public and static because, unlike the rest of this class, it is a pure
	 * function of its input — see the class docblock on why the rest isn't
	 * unit-tested.
	 *
	 * @param string $dir Relative directory, e.g. from `dirname( $attached_file )`.
	 * @return string The same directory, or '' when any component fails the guard.
	 */
	/**
	 * Whether a stored path component can be used verbatim.
	 *
	 * Mirrors `Resizer::safePathComponent()` and
	 * `StarterBase::safe_path_component()`. Refuses; never rewrites -- see
	 * ADR 0008 on why the rewrite was removed.
	 *
	 * @param string $component One stored path component.
	 * @return bool
	 */
	public static function safePathComponent( string $component ): bool {
		return '' !== $component
			&& '.' !== $component
			&& '..' !== $component
			&& false === strpos( $component, '/' )
			&& false === strpos( $component, '\\' )
			&& false === strpos( $component, "\0" );
	}

	public static function guardSourceDir( string $dir ): string {
		if ( '' === $dir || '.' === $dir ) {
			return '';
		}

		// No backslash normalization here, unlike Resizer::sourcePathSegment()
		// (which reads a URL): this reads dirname() of `_wp_attached_file`,
		// a database value WordPress always stores with forward slashes, so
		// a backslash cannot occur in this input domain.
		$parts = array();

		foreach ( explode( '/', $dir ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return '';
			}

			if ( ! self::safePathComponent( $part ) ) {
				return '';
			}

			$parts[] = $part;
		}

		return implode( '/', $parts );
	}
}
