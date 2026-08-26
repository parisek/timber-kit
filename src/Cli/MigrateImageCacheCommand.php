<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Cli;

use Parisek\TimberKit\ImageCacheMigrator;

/**
 * `wp timber-kit migrate-image-cache` — move existing resizer cache
 * derivatives into the source-path layout ahead of enabling
 * `StarterBase::$resizer_source_path_in_cache_key`.
 *
 * Thin adapter over {@see ImageCacheMigrator}: it builds the name-to-source-dir
 * map from `_wp_attached_file` and delegates every decision (unambiguous move,
 * ambiguous collision, orphan, conflict) to the migrator, which is
 * unit-tested. The WP_CLI I/O here is intentionally not unit-tested.
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
	 * : List the ambiguous names with their candidate source directories.
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

		$migrator = new ImageCacheMigrator( $cache_dir, $this->buildNameToDirs() );
		$plan     = $migrator->plan();

		$scanned = count( $plan['move'] ) + count( $plan['ambiguous'] ) + count( $plan['orphaned'] ) + count( $plan['conflict'] );

		\WP_CLI::log( sprintf( 'scanned      : %d', $scanned ) );
		\WP_CLI::log( sprintf( 'unambiguous  : %d  -> move', count( $plan['move'] ) ) );
		\WP_CLI::log( sprintf( 'ambiguous    : %d  -> left in place', count( $plan['ambiguous'] ) ) );
		\WP_CLI::log( sprintf( 'orphaned     : %d', count( $plan['orphaned'] ) ) );
		\WP_CLI::log( sprintf( 'conflicts    : %d', count( $plan['conflict'] ) ) );

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

		\WP_CLI::success( sprintf( 'Moved %d derivatives (%d failed).', $result['moved'], count( $result['failed'] ) ) );

		foreach ( $result['failed'] as $source ) {
			\WP_CLI::warning( sprintf( 'Failed to move %s.', $source ) );
		}
	}

	/**
	 * Build the cache-name => source-directories map from `_wp_attached_file`.
	 *
	 * Every directory goes through {@see guardSourceDir()} first. Skipping a
	 * directory that fails it (rather than mapping it anyway) matters because
	 * the flag-enabled `Resizer` applies the identical guard at render time
	 * (`Resizer::sourcePathSegment()`): a directory it rejects never gets a
	 * cache subdirectory there either, so the derivative stays at the flat
	 * key. Mapping it here would move the file into a nested path nothing
	 * ever looks up again.
	 *
	 * @return array<string, list<string>>
	 */
	private function buildNameToDirs(): array {
		global $wpdb;

		$rows = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'" );

		$name_to_dirs = array();

		foreach ( $rows as $row ) {
			if ( ! is_string( $row ) || '' === $row ) {
				continue;
			}

			$name        = sanitize_file_name( pathinfo( basename( $row ), PATHINFO_FILENAME ) );
			$source_dir  = dirname( $row );
			$guarded_dir = self::guardSourceDir( $source_dir );

			// An upload at the uploads root (dirname() === '.') legitimately
			// guards to '' — that is still a valid, flat mapping, so it must
			// not be conflated with a directory the guard rejected.
			if ( '' === $guarded_dir && '.' !== $source_dir ) {
				continue;
			}

			if ( ! isset( $name_to_dirs[ $name ] ) ) {
				$name_to_dirs[ $name ] = array();
			}

			if ( ! in_array( $guarded_dir, $name_to_dirs[ $name ], true ) ) {
				$name_to_dirs[ $name ][] = $guarded_dir;
			}
		}

		return $name_to_dirs;
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
	public static function guardSourceDir( string $dir ): string {
		if ( '' === $dir || '.' === $dir ) {
			return '';
		}

		$parts = array();

		foreach ( explode( '/', $dir ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return '';
			}

			$clean = sanitize_file_name( $part );
			if ( '' === $clean || $clean !== $part ) {
				return '';
			}

			$parts[] = $clean;
		}

		return implode( '/', $parts );
	}
}
