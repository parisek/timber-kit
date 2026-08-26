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

			$name = sanitize_file_name( pathinfo( basename( $row ), PATHINFO_FILENAME ) );
			$dir  = dirname( $row );
			$dir  = '.' === $dir ? '' : $dir;

			if ( ! isset( $name_to_dirs[ $name ] ) ) {
				$name_to_dirs[ $name ] = array();
			}

			if ( ! in_array( $dir, $name_to_dirs[ $name ], true ) ) {
				$name_to_dirs[ $name ][] = $dir;
			}
		}

		return $name_to_dirs;
	}
}
