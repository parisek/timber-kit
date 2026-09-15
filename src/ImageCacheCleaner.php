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

	private string $cache_dir;

	public function __construct( string $cache_dir ) {
		$this->cache_dir = rtrim( $cache_dir, '/\\' );
	}

	/**
	 * Derivative paths matching the selection.
	 *
	 * @param list<string> $names  Source file names (`hero.jpg` or `hero`); empty selects every derivative.
	 * @param string|null  $format Output format (`avif`); null selects every format.
	 * @return list<string> Absolute paths, sorted.
	 */
	public function find( array $names = [], ?string $format = null ): array {
		if ( ! is_dir( $this->cache_dir ) ) {
			return [];
		}

		$formats = null === $format ? null : self::formatAliases( $format );
		$stems   = self::candidateStems( $names );
		$found   = [];

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->cache_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
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
			if ( null !== $stems && ! isset( $stems[ $stem ] ) ) {
				continue;
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
	 * Every stem a derivative of the named sources can carry.
	 *
	 * @param list<string> $names
	 * @return array<string, true>|null Null when nothing is named.
	 */
	private static function candidateStems( array $names ): ?array {
		if ( [] === $names ) {
			return null;
		}

		$stems = [];
		foreach ( $names as $name ) {
			$base = basename( trim( $name ) );
			if ( '' === $base ) {
				continue;
			}
			$extension = pathinfo( $base, PATHINFO_EXTENSION );
			$stem      = '' === $extension ? $base : substr( $base, 0, - ( strlen( $extension ) + 1 ) );

			foreach ( [ $stem, $stem . '-scaled' ] as $candidate ) {
				// Flat layout: the sanitised stem, as Resizer writes it.
				$stems[ function_exists( 'sanitize_file_name' ) ? sanitize_file_name( $candidate ) : $candidate ] = true;
				// Source-path layout: the whole name, extension included.
				if ( '' !== $extension ) {
					$stems[ $candidate . '.' . $extension ] = true;
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
