<?php

declare(strict_types=1);

namespace Parisek\TimberKit;

/**
 * Re-encodes resizer derivatives in place, without deleting them first.
 *
 * Deleting a derivative and letting it regenerate on the next render breaks a
 * live site: a page cache keeps serving HTML that points at the deleted file,
 * and `<source type="image/avif">` has no fallback, so the visitor sees a
 * broken image until that page re-renders. This class writes the new bytes to
 * a temp file beside the target and renames it over the target, which is
 * atomic on one filesystem. The URL is never missing and never half-written.
 *
 * Every parameter of a derivative is recoverable from its path
 * (`<W>x<H>-<style>[-q<N>]/<source dir>/<source name>.<format>`), so the class
 * reads the path rather than the database. That holds only in the source-path
 * layout (`timber_kit_resizer_source_path_in_cache_key`); the flat layout
 * names a derivative after the sanitised source stem, which destroys the
 * source extension, so those files are reported as unreadable and left alone.
 *
 * The class never deletes a derivative. A derivative whose source is gone is
 * reported as an orphan; deleting it is `ImageCacheCleaner`'s job.
 */
class ImageCacheRegenerator {

	/** Prefix of the temp file a re-encode writes beside its target. */
	private const string TEMP_PREFIX = '.tk-regen-';

	/** Source extensions a derivative name may carry before its output format. */
	private const array SOURCE_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tif', 'tiff', 'heic' );

	private string $cache_dir;

	private string $uploads_basedir;

	private int $default_quality;

	/**
	 * @param string $cache_dir       The resizer cache directory.
	 * @param string $uploads_basedir The uploads root a derivative's source path is relative to.
	 * @param int    $default_quality Quality for a derivative whose path carries no `-q<N>`
	 *                                segment, i.e. the `timber_kit_resizer_target_quality` value.
	 */
	public function __construct( string $cache_dir, string $uploads_basedir, int $default_quality ) {
		$this->cache_dir       = rtrim( $cache_dir, '/\\' );
		$this->uploads_basedir = rtrim( $uploads_basedir, '/\\' );
		$this->default_quality = $default_quality;
	}

	/**
	 * Read a derivative path back into the parameters that produced it.
	 *
	 * @param string $path Absolute path to a derivative file.
	 * @return array{path: string, source: string, source_relative: string, variant: array{width: int, height: int, image_style: string, quality: int, format: string}}|null
	 *         Null when the path is outside the cache directory, or when it
	 *         carries no source path to read back.
	 */
	public function parse( string $path ): ?array {
		$path     = str_replace( '\\', '/', $path );
		$prefix   = $this->cache_dir . '/';
		if ( ! str_starts_with( $path, $prefix ) ) {
			return null;
		}

		$parts = explode( '/', substr( $path, strlen( $prefix ) ) );
		// A derivative is at least <size segment>/<name>; the cache root holds none.
		if ( count( $parts ) < 2 ) {
			return null;
		}

		$size     = array_shift( $parts );
		$filename = array_pop( $parts );
		$variant  = self::parseSizeSegment( (string) $size );
		if ( null === $variant ) {
			return null;
		}

		$format = strtolower( (string) pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
		if ( '' === $format ) {
			return null;
		}
		$source_name = substr( (string) $filename, 0, - ( strlen( $format ) + 1 ) );

		// The source name keeps its own extension in the source-path layout.
		// Without one this is a flat-layout derivative, whose sanitised stem
		// no longer says which file it came from.
		$source_extension = strtolower( (string) pathinfo( $source_name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $source_extension, self::SOURCE_EXTENSIONS, true ) ) {
			return null;
		}

		$directory       = implode( '/', $parts );
		$source_relative = '' === $directory ? $source_name : $directory . '/' . $source_name;
		$variant['format'] = $format;

		return array(
			'path'            => $path,
			'source'          => $this->uploads_basedir . '/' . $source_relative,
			'source_relative' => $source_relative,
			'variant'         => $variant,
		);
	}

	/**
	 * Every derivative file the given arguments reach.
	 *
	 * An argument is a file or a directory, absolute or relative to the cache
	 * directory. No argument means the whole cache directory. An argument that
	 * resolves outside the cache directory is refused, never processed.
	 *
	 * @param list<string> $args
	 * @return array{paths: list<string>, outside: list<string>}
	 */
	public function resolveTargets( array $args ): array {
		$root = realpath( $this->cache_dir );
		if ( false === $root ) {
			return array( 'paths' => array(), 'outside' => $args );
		}

		if ( array() === $args ) {
			return array( 'paths' => $this->rebase( $this->walk( $root ), $root ), 'outside' => array() );
		}

		$paths   = array();
		$outside = array();
		foreach ( $args as $arg ) {
			$candidate = str_starts_with( $arg, '/' ) ? $arg : $this->cache_dir . '/' . $arg;
			$real      = realpath( $candidate );
			if ( false === $real || ( $real !== $root && ! str_starts_with( $real, $root . DIRECTORY_SEPARATOR ) ) ) {
				$outside[] = $arg;
				continue;
			}
			if ( is_dir( $real ) ) {
				$paths = array_merge( $paths, $this->walk( $real ) );
				continue;
			}
			$paths[] = $real;
		}

		$paths = array_values( array_unique( $this->rebase( $paths, $root ) ) );
		sort( $paths );
		return array( 'paths' => $paths, 'outside' => $outside );
	}

	/**
	 * Put resolved paths back under the configured cache directory.
	 *
	 * `realpath()` follows symlinks, so on a host whose cache directory sits
	 * behind one (macOS `/tmp`, a moved uploads volume) the resolved path has
	 * a different prefix than the configured one. Everything downstream --
	 * parsing, reporting, the outside-the-cache refusal -- compares against
	 * the configured directory, so the run stays in that one spelling.
	 *
	 * @param list<string> $paths
	 * @return list<string>
	 */
	private function rebase( array $paths, string $root ): array {
		if ( $root === $this->cache_dir ) {
			return $paths;
		}
		return array_map(
			fn ( string $path ): string => str_starts_with( $path, $root . DIRECTORY_SEPARATOR )
				? $this->cache_dir . substr( $path, strlen( $root ) )
				: $path,
			$paths
		);
	}

	/**
	 * Split the selected paths into the work a run does and what it leaves.
	 *
	 * `--older-than` is the resume mechanism. A regenerated file carries a
	 * fresh mtime, so the next run with the same cutoff skips what the last
	 * one finished and picks up where it stopped.
	 *
	 * An orphan does not consume the limit: it costs no encode, so counting it
	 * would shrink the batch a cron run actually does.
	 *
	 * @param list<string> $paths      From {@see resolveTargets()}.
	 * @param string|null  $format     Output format to keep; null keeps every format.
	 * @param int|null     $older_than Unix time; keep only files modified before it.
	 * @param int          $limit      Stop after this many entries; 0 means no limit.
	 * @return array{entries: list<array<string, mixed>>, skipped: int, orphan: list<string>, unreadable: list<string>, remaining: int}
	 */
	public function plan( array $paths, ?string $format = null, ?int $older_than = null, int $limit = 0 ): array {
		$formats    = null === $format ? null : self::formatAliases( $format );
		$entries    = array();
		$skipped    = 0;
		$orphan     = array();
		$unreadable = array();
		$remaining  = 0;

		foreach ( $paths as $path ) {
			$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( null !== $formats && ! in_array( $extension, $formats, true ) ) {
				++$skipped;
				continue;
			}
			if ( null !== $older_than && (int) filemtime( $path ) >= $older_than ) {
				++$skipped;
				continue;
			}

			$entry = $this->parse( $path );
			if ( null === $entry ) {
				$unreadable[] = $path;
				continue;
			}
			if ( ! is_file( $entry['source'] ) ) {
				$orphan[] = $path;
				continue;
			}

			if ( $limit > 0 && count( $entries ) >= $limit ) {
				++$remaining;
				continue;
			}
			$entries[] = $entry;
		}

		return array(
			'entries'    => $entries,
			'skipped'    => $skipped,
			'orphan'     => $orphan,
			'unreadable' => $unreadable,
			'remaining'  => $remaining,
		);
	}

	/**
	 * Re-encode one derivative in place.
	 *
	 * The encoder writes to a temp path in the target's own directory, so the
	 * rename that follows stays on one filesystem and is therefore atomic. A
	 * new file replaces the target only once it encoded, weighs something and
	 * decodes; anything else deletes the temp file and keeps the old target,
	 * which a visitor is reading while this runs.
	 *
	 * @param array<string, mixed> $entry    One entry from {@see plan()}.
	 * @param callable(array<string, mixed>, string, string): bool $encoder Variant, source path, temp path.
	 * @param callable(string): bool $verifier Whether the temp file decodes.
	 * @param bool $dry_run Report the entry and write nothing.
	 * @return array{status: string, path: string, before: int, after: int}
	 */
	public function regenerate( array $entry, callable $encoder, callable $verifier, bool $dry_run = false ): array {
		$path   = (string) $entry['path'];
		$before = (int) filesize( $path );

		if ( $dry_run ) {
			return array( 'status' => 'would_regenerate', 'path' => $path, 'before' => $before, 'after' => $before );
		}

		/** @var array<string, mixed> $variant */
		$variant = $entry['variant'];
		$temp    = dirname( $path ) . '/' . self::TEMP_PREFIX . getmypid() . '-' . basename( $path );

		try {
			$encoded = $encoder( $variant, (string) $entry['source'], $temp );
		} catch ( \Throwable $e ) {
			// A single unencodable image must not end the run; the old file is
			// still there and still correct-looking.
			$encoded = false;
		}

		$size = $encoded && is_file( $temp ) ? (int) filesize( $temp ) : 0;
		if ( ! $encoded || 0 === $size || ! $verifier( $temp ) || ! rename( $temp, $path ) ) {
			if ( is_file( $temp ) ) {
				unlink( $temp );
			}
			return array( 'status' => 'failed', 'path' => $path, 'before' => $before, 'after' => $before );
		}

		return array( 'status' => 'regenerated', 'path' => $path, 'before' => $before, 'after' => $size );
	}

	/**
	 * Whether a written file is a decodable image.
	 *
	 * `getimagesize()` answers for the formats PHP itself parses. It does not
	 * read AVIF on every build, so an unreadable file falls through to an
	 * Imagick ping, which reads the header only. With neither able to read the
	 * format, the file is refused: a re-encode that cannot be checked must not
	 * replace a file that works.
	 */
	public static function decodes( string $path ): bool {
		if ( false !== @getimagesize( $path ) ) {
			return true;
		}
		if ( ! class_exists( '\Imagick' ) ) {
			return false;
		}
		try {
			$image = new \Imagick();
			$ok    = $image->pingImage( $path );
			$image->clear();
			return $ok;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Whether the machine is too busy to encode another image.
	 *
	 * Reads the one-minute average, the only one that reacts inside a run.
	 * `sys_getloadavg()` returns false where the platform has no load average;
	 * the gate then opens, because a run that cannot read the load must still
	 * finish rather than wait for a number that never arrives.
	 *
	 * @param float|null        $max   Limit; null means no load check.
	 * @param array<int, float>|false $loads As `sys_getloadavg()` returns it.
	 */
	public static function loadExceeded( ?float $max, array|false $loads ): bool {
		if ( null === $max || false === $loads || ! isset( $loads[0] ) ) {
			return false;
		}
		return (float) $loads[0] > $max;
	}

	/**
	 * Parse a cache directory segment, e.g. `1440x0-smart-crop-q80`.
	 *
	 * The style itself may carry a dash (`smart-crop`), so the quality suffix
	 * is matched at the end and the style takes whatever is left.
	 *
	 * @return array{width: int, height: int, image_style: string, quality: int, format: string}|null
	 */
	private function parseSizeSegment( string $segment ): ?array {
		if ( 1 !== preg_match( '/^(\d+)x(\d+)-(.+?)(?:-q(\d+))?$/', $segment, $m ) ) {
			return null;
		}
		return array(
			'width'       => (int) $m[1],
			'height'      => (int) $m[2],
			'image_style' => $m[3],
			// An absent group is an absent suffix; `-q0` is a real quality of 0.
			'quality'     => isset( $m[4] ) ? (int) $m[4] : $this->default_quality,
			'format'      => '',
		);
	}

	/**
	 * Every file below a directory, skipping dotfiles and unreadable subtrees.
	 *
	 * @return list<string>
	 */
	private function walk( string $directory ): array {
		if ( ! is_dir( $directory ) ) {
			return array();
		}

		$found = array();
		// CATCH_GET_CHILD: an unreadable directory is skipped, not fatal.
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY,
			\RecursiveIteratorIterator::CATCH_GET_CHILD
		);
		foreach ( $files as $file ) {
			if ( $file->isFile() && ! str_starts_with( $file->getFilename(), '.' ) ) {
				$found[] = $file->getPathname();
			}
		}
		sort( $found );
		return $found;
	}

	/**
	 * @return list<string>
	 */
	private static function formatAliases( string $format ): array {
		$format = strtolower( trim( $format ) );
		return in_array( $format, array( 'jpg', 'jpeg' ), true ) ? array( 'jpg', 'jpeg' ) : array( $format );
	}
}
