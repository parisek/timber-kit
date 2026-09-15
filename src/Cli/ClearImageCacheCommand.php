<?php

declare(strict_types=1);

namespace Parisek\TimberKit\Cli;

use Parisek\TimberKit\ImageCacheCleaner;

/**
 * `wp timber-kit clear-image-cache` — delete resizer derivatives so they
 * regenerate on the next request.
 *
 * Thin adapter over {@see ImageCacheCleaner}: it resolves attachment IDs to
 * file names and the cache directory from the resizer's own filter, then
 * delegates selection and deletion to the cleaner, which is unit-tested. The
 * WP_CLI I/O here is intentionally not unit-tested.
 */
class ClearImageCacheCommand {

	/**
	 * Delete resizer cache derivatives: all, one format, or selected images.
	 *
	 * Derivatives regenerate on the next request that renders them. Browsers
	 * and proxies may still hold old copies at the same URL; bump
	 * `StarterBase::$resizer_cache_version` to change the URLs too.
	 *
	 * ## OPTIONS
	 *
	 * [<image>...]
	 * : Attachment ID, source file name (`hero.jpg`, `hero`) or path relative to
	 *   uploads (`2026/08/hero.jpg`). A number is always an attachment ID; name a
	 *   file called `2024.jpg` with its extension. Default: every derivative.
	 *
	 * [--format=<format>]
	 * : Only derivatives of this output format, e.g. `avif`.
	 *
	 * [--apply]
	 * : Delete the files. Without it the command only reports what it would delete.
	 *
	 * [--verbose]
	 * : List every matching file.
	 *
	 * ## EXAMPLES
	 *
	 *     wp timber-kit clear-image-cache
	 *     wp timber-kit clear-image-cache --format=avif --apply
	 *     wp timber-kit clear-image-cache 232 hero.jpg --apply
	 *
	 * @param array<int, string>    $args       Attachment IDs or file names.
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$apply   = isset( $assoc_args['apply'] );
		$verbose = isset( $assoc_args['verbose'] );
		$format  = isset( $assoc_args['format'] ) && '' !== $assoc_args['format'] ? (string) $assoc_args['format'] : null;

		$names = [];
		foreach ( $args as $arg ) {
			if ( ctype_digit( (string) $arg ) ) {
				$attachment_names = self::attachmentNames( (int) $arg );
				if ( [] === $attachment_names ) {
					\WP_CLI::warning( sprintf( 'Attachment #%s has no file; skipped.', $arg ) );
				}
				$names = array_merge( $names, $attachment_names );
				continue;
			}
			$names[] = (string) $arg;
		}
		if ( [] !== $args && [] === $names ) {
			\WP_CLI::error( 'None of the given images resolved to a file name. Nothing selected.' );
		}

		$cache_dir   = (string) apply_filters( 'timber_kit_resizer_image_cache_dir', WP_CONTENT_DIR . '/cache/image' );
		$source_path = (bool) apply_filters( 'timber_kit_resizer_source_path_in_cache_key', false );
		$cleaner     = new ImageCacheCleaner( $cache_dir, $source_path );
		$names       = array_values( array_unique( $names ) );
		$selection   = $cleaner->select( $names, $format );
		$paths       = $selection['paths'];

		// Say what matched nothing, so an empty result is not read as a clean cache.
		foreach ( $selection['unmatched'] as $name ) {
			\WP_CLI::warning( sprintf( 'No derivative matches "%s"%s.', $name, null === $format ? '' : ' in format ' . $format ) );
		}
		if ( [] === $names && null !== $format && [] === $paths ) {
			\WP_CLI::warning( sprintf( 'No derivative has the format "%s".', $format ) );
		}

		$bytes = 0;
		foreach ( $paths as $path ) {
			$bytes += (int) filesize( $path );
			if ( $verbose ) {
				\WP_CLI::log( $path );
			}
		}
		$size = size_format( $bytes );

		if ( ! $apply ) {
			\WP_CLI::success( sprintf( 'Dry run: %d derivative(s), %s, in %s. Run with --apply to delete.', count( $paths ), $size, $cache_dir ) );
			return;
		}

		$deleted = $cleaner->delete( $paths );
		\WP_CLI::success( sprintf( 'Deleted %d of %d derivative(s), %s. They regenerate on the next request.', $deleted, count( $paths ), $size ) );
	}

	/**
	 * The source paths an attachment's derivatives can be named after, relative
	 * to uploads so the source-path layout can scope them to their directory:
	 * the file it serves and, for a `-scaled` or `-rotated` upload, its original.
	 *
	 * @return list<string>
	 */
	private static function attachmentNames( int $attachment_id ): array {
		$names    = [];
		$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
		// An uploads-root file is passed as `./name`, so it stays scoped to the
		// root instead of reaching every month like a bare name.
		$dir = is_string( $attached ) ? dirname( $attached ) : '.';
		if ( is_string( $attached ) && '' !== $attached ) {
			$names[] = '.' === $dir ? './' . $attached : $attached;
		}
		// Core's array shape omits original_image, which only -scaled and
		// -rotated uploads carry.
		$metadata = (array) wp_get_attachment_metadata( $attachment_id, true );
		$original = $metadata['original_image'] ?? '';
		if ( is_string( $original ) && '' !== $original ) {
			$names[] = ( '.' === $dir ? '.' : $dir ) . '/' . $original;
		}
		return $names;
	}
}
