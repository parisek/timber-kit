<?php

declare(strict_types=1);

namespace Parisek\TimberKit;

/**
 * Finds and deletes resizer derivatives, so they regenerate on the next request.
 *
 * Selects every derivative, those of one output format, or those of named
 * images. A name is matched in both cache layouts: the flat one names a
 * derivative after the source's sanitised stem (`hero.avif`), the source-path
 * one after the source's whole name (`2026/08/hero.png.avif`). A name also
 * matches the `-scaled` copy WordPress serves for a large upload, because that
 * is the file the resizer read.
 *
 * Deletes files only, never directories, and never a path that resolves
 * outside the cache directory.
 */
class ImageCacheCleaner {

	/** Extensions split off a bare name; anything else stays part of the stem. */
	private const array IMAGE_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'tif', 'tiff', 'heic' );

	private string $cache_dir;

	private bool $source_path_layout;

	/**
	 * @param string $cache_dir          The resizer cache directory.
	 * @param bool   $source_path_layout Whether `timber_kit_resizer_source_path_in_cache_key` is on,
	 *                                   which is what lets a name's directory narrow the match.
	 */
	public function __construct( string $cache_dir, bool $source_path_layout = false ) {
		$this->cache_dir          = rtrim( $cache_dir, '/\\' );
		$this->source_path_layout = $source_path_layout;
	}

	/**
	 * Derivative paths matching the selection.
	 *
	 * @param list<string> $names  Source names: `hero.jpg`, `hero`, or a path relative to
	 *                             uploads (`2026/08/hero.jpg`, as `_wp_attached_file` stores it).
	 *                             In the source-path layout a directory scopes the match to
	 *                             that directory; a bare name matches in every directory.
	 *                             Empty selects every derivative.
	 * @param string|null  $format Output format (`avif`); null selects every format.
	 * @return list<string> Absolute paths, sorted.
	 */
	public function find( array $names = [], ?string $format = null ): array {
		if ( ! is_dir( $this->cache_dir ) ) {
			return [];
		}

		$formats = null === $format ? null : self::formatAliases( $format );
		$stems   = self::candidateStems( $names, $this->source_path_layout );
		$found   = [];

		// CATCH_GET_CHILD: an unreadable directory is skipped, not fatal.
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->cache_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY,
			\RecursiveIteratorIterator::CATCH_GET_CHILD
		);
		foreach ( $files as $file ) {
			if ( ! $file->isFile() || str_starts_with( $file->getFilename(), '.' ) ) {
				continue;
			}
			$extension = strtolower( $file->getExtension() );
			if ( null !== $formats && ! in_array( $extension, $formats, true ) ) {
				continue;
			}
			// A derivative's name is its source stem (flat) or source name
			// (source-path) plus the output extension. Exact comparison, so
			// `hero` never selects `hero-banner`.
			$stem = substr( $file->getFilename(), 0, - ( strlen( $file->getExtension() ) + 1 ) );
			if ( null !== $stems ) {
				if ( ! isset( $stems[ $stem ] ) ) {
					continue;
				}
				// Source-path layout: <size>/<source dir>/<name>. A name given
				// with a directory only matches in that directory.
				$scopes = $stems[ $stem ];
				if ( $this->source_path_layout && ! isset( $scopes['*'] ) ) {
					$relative = substr( $file->getPath(), strlen( $this->cache_dir ) + 1 );
					$parts    = explode( '/', str_replace( '\\', '/', $relative ) );
					array_shift( $parts );
					if ( ! isset( $scopes[ implode( '/', $parts ) ] ) ) {
						continue;
					}
				}
			}
			$found[] = $file->getPathname();
		}

		sort( $found );
		return $found;
	}

	/**
	 * Delete derivative files.
	 *
	 * @param list<string> $paths Paths from {@see find()}.
	 * @return int Files actually deleted.
	 */
	public function delete( array $paths ): int {
		$root = realpath( $this->cache_dir );
		if ( false === $root ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $paths as $path ) {
			$real = realpath( $path );
			if ( false === $real || ! is_file( $real ) || ! str_starts_with( $real, $root . DIRECTORY_SEPARATOR ) ) {
				continue;
			}
			if ( unlink( $real ) ) {
				++$deleted;
			}
		}
		return $deleted;
	}

	/**
	 * Every stem a derivative of the named sources can carry, with the source
	 * directories each one may sit in (`*` for a name given without one).
	 *
	 * The flat stem is `sanitize_file_name()` of the source stem, as Resizer
	 * writes it. Resizer reads that stem from the image URL; a WordPress upload
	 * URL is the stored file name unencoded, so the two agree for uploads made
	 * through WordPress.
	 *
	 * @param list<string> $names
	 * @param bool         $source_path_layout
	 * @return array<string, array<string, true>>|null Null when nothing is named.
	 */
	private static function candidateStems( array $names, bool $source_path_layout ): ?array {
		if ( [] === $names ) {
			return null;
		}

		$stems = [];
		foreach ( $names as $name ) {
			$name = trim( str_replace( '\\', '/', $name ), '/' );
			$base = basename( $name );
			if ( '' === $base ) {
				continue;
			}
			$dir   = dirname( $name );
			$scope = ( '.' === $dir || '' === $dir ) ? '*' : $dir;

			$extension = pathinfo( $base, PATHINFO_EXTENSION );
			if ( ! in_array( strtolower( $extension ), self::IMAGE_EXTENSIONS, true ) ) {
				$extension = '';
			}
			$stem = '' === $extension ? $base : substr( $base, 0, - ( strlen( $extension ) + 1 ) );

			foreach ( [ $stem, $stem . '-scaled' ] as $candidate ) {
				// Flat layout: the sanitised stem, which carries no directory. In
				// the source-path layout a name given with a directory is one
				// upload, so a flat leftover of the same stem is not its own.
				if ( ! ( $source_path_layout && '*' !== $scope ) ) {
					$flat = function_exists( 'sanitize_file_name' ) ? sanitize_file_name( $candidate ) : $candidate;
					$stems[ $flat ]['*'] = true;
				}
				// Source-path layout: the whole name, verbatim, in its directory.
				if ( '' !== $extension ) {
					$stems[ $candidate . '.' . $extension ][ $scope ] = true;
				}
			}
		}
		return $stems;
	}

	/**
	 * @return list<string>
	 */
	private static function formatAliases( string $format ): array {
		$format = strtolower( trim( $format ) );
		return in_array( $format, [ 'jpg', 'jpeg' ], true ) ? [ 'jpg', 'jpeg' ] : [ $format ];
	}
}
