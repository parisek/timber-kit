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
	public const string TEMP_PREFIX = '.tk-regen-';

	/**
	 * Up to this much growth counts a re-encode as suspect.
	 *
	 * The encoder defects this command exists for -- a quality read backwards,
	 * a coder default of 0 -- all made a file far too small, so a correct
	 * re-encode of such a file is several times bigger. A file that stayed
	 * about the same size did not necessarily fail, so this is a report, never
	 * a refusal: a legitimately smaller re-encode exists.
	 */
	public const float SUSPECT_RATIO = 1.2;

	/** Name of the lock file that keeps two runs from planning the same files. */
	public const string LOCK_FILE = '.tk-regen.lock';

	/**
	 * Free space a run wants, as a multiple of what the selected files weigh.
	 *
	 * Three, because the re-encode this command exists for makes a file
	 * several times bigger -- measured in 1.53.0, an AVIF at the honoured
	 * quality is about six times the size of the same file at the broken one,
	 * and about twenty-five times at quality 100. Two would only cover a
	 * re-encode that changed nothing. The factor is a floor under a run that
	 * could otherwise fill the disk halfway through and leave a site with no
	 * room to write anything at all, not a prediction of the final size.
	 */
	public const float DISK_FACTOR = 3.0;

	/** Source extensions a derivative name may carry before its output format. */
	private const array SOURCE_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tif', 'tiff', 'heic' );

	private string $cache_dir;

	private string $uploads_basedir;

	private int $default_quality;

	/** @var resource|null The held lock file handle, while this run holds it. */
	private $lock = null;

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
	 * Take the run lock, or report that another run holds it.
	 *
	 * Two overlapping runs plan the same files and encode each one twice: the
	 * cost doubles and two processes rename over one target. `flock()` carries
	 * the answer rather than the file's existence, so a run killed with -9
	 * releases the lock the moment the kernel closes its handle -- a lock file
	 * holding a PID would survive that and lock the cache until someone
	 * deleted it by hand.
	 *
	 * The lock lives in the cache directory, next to the files it guards, so
	 * one lock covers one cache even where several sites share a filesystem.
	 */
	public function acquireLock(): bool {
		if ( null !== $this->lock ) {
			return true;
		}

		$handle = @fopen( $this->lockPath(), 'c' );
		if ( false === $handle ) {
			// Nowhere to put the lock is not a reason to run unguarded.
			return false;
		}
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );
			return false;
		}

		$this->lock = $handle;
		// A run that ends any way at all gives the lock back.
		register_shutdown_function( fn () => $this->releaseLock() );
		return true;
	}

	/** Give the run lock back. Doing this twice, or never having held it, is fine. */
	public function releaseLock(): void {
		if ( null === $this->lock ) {
			return;
		}
		flock( $this->lock, LOCK_UN );
		fclose( $this->lock );
		$this->lock = null;
	}

	/** Where the run lock lives. */
	public function lockPath(): string {
		return $this->cache_dir . '/' . self::LOCK_FILE;
	}

	/**
	 * Delete temp files left behind by runs that are no longer alive.
	 *
	 * A run killed between the encode and the rename leaves its temp file in
	 * the cache directory, where nothing removes it: {@see walk()} skips
	 * dotfiles, so neither this command nor `clear-image-cache` ever sees it.
	 * The PID in the name says which run wrote it, and a file whose PID is
	 * gone is nobody's.
	 *
	 * A file is kept whenever the answer is not certain -- a live PID, an
	 * unreadable one, or a platform without `posix_kill()`. Deleting a temp
	 * file another process is writing this second costs that process its work.
	 *
	 * @return array{deleted: list<string>, kept: list<string>}
	 */
	public function sweepStaleTemps(): array {
		$deleted = array();
		$kept    = array();

		foreach ( $this->scanTemps( $this->cache_dir ) as $path ) {
			$pid = self::pidOf( basename( $path ) );
			if ( null === $pid || self::processIsAlive( $pid ) ) {
				$kept[] = $path;
				continue;
			}
			if ( @unlink( $path ) ) {
				$deleted[] = $path;
			} else {
				$kept[] = $path;
			}
		}

		sort( $deleted );
		sort( $kept );
		return array( 'deleted' => $deleted, 'kept' => $kept );
	}

	/**
	 * Every temp file below a directory. Separate from {@see walk()}, which
	 * skips dotfiles on purpose because a derivative is never one.
	 *
	 * @return list<string>
	 */
	private function scanTemps( string $directory ): array {
		if ( ! is_dir( $directory ) ) {
			return array();
		}

		$found = array();
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY,
			\RecursiveIteratorIterator::CATCH_GET_CHILD
		);
		foreach ( $files as $file ) {
			if ( $file->isFile() && str_starts_with( $file->getFilename(), self::TEMP_PREFIX ) ) {
				$found[] = $file->getPathname();
			}
		}
		return $found;
	}

	/** The PID a temp file name carries, or null when it carries none. */
	private static function pidOf( string $filename ): ?int {
		$rest = substr( $filename, strlen( self::TEMP_PREFIX ) );
		if ( 1 !== preg_match( '/^(\d+)-/', $rest, $m ) ) {
			return null;
		}
		return (int) $m[1];
	}

	/** Whether a PID belongs to a running process; unknown counts as alive. */
	private static function processIsAlive( int $pid ): bool {
		if ( ! function_exists( 'posix_kill' ) ) {
			return true;
		}
		// Signal 0 checks for the process without sending anything. EPERM
		// means it exists and belongs to someone else, which is still alive.
		if ( posix_kill( $pid, 0 ) ) {
			return true;
		}
		return function_exists( 'posix_get_last_error' ) && PHP_OS_FAMILY !== 'Windows' && 1 === posix_get_last_error();
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
	 * new file replaces the target only once it encoded, weighs more than zero,
	 * decodes and carries the same pixel dimensions as the file it replaces;
	 * anything else deletes the temp file and keeps the old target, which a
	 * visitor is reading while this runs.
	 *
	 * The new file also takes the old file's mode. A rename keeps the temp
	 * file's own permissions, and a cron running under a different umask or a
	 * different user writes a file the web server cannot read -- a 403 on a
	 * `<source type="image/avif">` that has no fallback.
	 *
	 * @param array<string, mixed> $entry    One entry from {@see plan()}.
	 * @param callable(array<string, mixed>, string, string): bool $encoder Variant, source path, temp path.
	 * @param callable(string): bool $verifier Whether the temp file decodes.
	 * @param bool $dry_run Report the entry and write nothing.
	 * @param (callable(string): (array{0: int, 1: int}|null))|null $measurer Pixel dimensions of a file;
	 *        null uses {@see dimensions()}.
	 * @return array{status: string, path: string, before: int, after: int, reason: string, ratio: float, suspect: bool}
	 */
	public function regenerate( array $entry, callable $encoder, callable $verifier, bool $dry_run = false, ?callable $measurer = null ): array {
		$path   = (string) $entry['path'];
		$before = (int) filesize( $path );

		if ( $dry_run ) {
			return self::outcome( 'would_regenerate', $path, $before, $before, '' );
		}

		/** @var array<string, mixed> $variant */
		$variant  = $entry['variant'];
		$temp     = dirname( $path ) . '/' . self::TEMP_PREFIX . getmypid() . '-' . basename( $path );
		$measurer ??= static fn ( string $file ): ?array => self::dimensions( $file );

		try {
			$encoded = $encoder( $variant, (string) $entry['source'], $temp );
		} catch ( \Throwable $e ) {
			// A single unencodable image must not end the run; the old file is
			// still there and still correct-looking.
			$encoded = false;
		}

		$size   = $encoded && is_file( $temp ) ? (int) filesize( $temp ) : 0;
		$reason = self::refusal( $encoded, $size, $temp, $path, $verifier, $measurer );

		if ( '' === $reason ) {
			// Carry the old mode before the rename, not after: between the two
			// the file is already live at its URL.
			$mode = fileperms( $path );
			if ( false !== $mode ) {
				@chmod( $temp, $mode & 0777 );
			}
			if ( ! rename( $temp, $path ) ) {
				$reason = 'rename';
			}
		}

		if ( '' !== $reason ) {
			if ( is_file( $temp ) ) {
				unlink( $temp );
			}
			return self::outcome( 'failed', $path, $before, $before, $reason );
		}

		return self::outcome( 'regenerated', $path, $before, $size, '' );
	}

	/**
	 * Which check refuses this temp file, or an empty string for none.
	 *
	 * Order is deliberate. Cheap facts first, then the decode, then the
	 * dimension compare, which is the only check that reads the old file too.
	 *
	 * @param callable(string): bool $verifier
	 * @param callable(string): (array{0: int, 1: int}|null) $measurer
	 */
	private static function refusal( bool $encoded, int $size, string $temp, string $path, callable $verifier, callable $measurer ): string {
		if ( ! $encoded ) {
			return 'encoder';
		}
		if ( 0 === $size ) {
			return 'empty';
		}
		if ( ! $verifier( $temp ) ) {
			return 'undecodable';
		}

		$old = $measurer( $path );
		if ( null === $old ) {
			// An old file nothing can measure is what this command exists to
			// replace, so it is a reason to continue rather than to stop.
			return '';
		}

		// A new file nothing can measure fails here too, and correctly: the
		// old one is readable, so the new one dropping below that is a loss.
		return $measurer( $temp ) === $old ? '' : 'dimensions';
	}

	/**
	 * @return array{status: string, path: string, before: int, after: int, reason: string, ratio: float, suspect: bool}
	 */
	private static function outcome( string $status, string $path, int $before, int $after, string $reason ): array {
		$ratio = $before > 0 ? $after / $before : 0.0;
		return array(
			'status'  => $status,
			'path'    => $path,
			'before'  => $before,
			'after'   => $after,
			'reason'  => $reason,
			'ratio'   => $ratio,
			'suspect' => 'regenerated' === $status && $ratio <= self::SUSPECT_RATIO,
		);
	}

	/**
	 * The middle value of a list, or 0.0 for an empty one.
	 *
	 * A run reports the median size ratio rather than the mean, because one
	 * enormous re-encode moves a mean and says nothing about the rest.
	 *
	 * @param list<float> $values
	 */
	public static function median( array $values ): float {
		if ( array() === $values ) {
			return 0.0;
		}
		sort( $values );
		$count  = count( $values );
		$middle = intdiv( $count, 2 );
		return 0 === $count % 2 ? ( $values[ $middle - 1 ] + $values[ $middle ] ) / 2 : $values[ $middle ];
	}

	/**
	 * Pixel dimensions of an image file, or null when nothing here reads it.
	 *
	 * `getimagesize()` answers for the formats PHP itself parses; AVIF on many
	 * builds it does not, so an unreadable file falls through to Imagick.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	public static function dimensions( string $path ): ?array {
		$size = @getimagesize( $path );
		if ( false !== $size ) {
			return array( (int) $size[0], (int) $size[1] );
		}
		if ( ! class_exists( '\Imagick' ) ) {
			return null;
		}
		try {
			$image = new \Imagick();
			if ( ! $image->pingImage( $path ) ) {
				return null;
			}
			$found = array( $image->getImageWidth(), $image->getImageHeight() );
			$image->clear();
			return $found;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Whether a written file is a decodable image.
	 *
	 * `getimagesize()` answers for the formats PHP itself parses, but only from
	 * the header: it reports a truncated file as a valid image of its declared
	 * size. So the pixels are read as well, and for AVIF they are the only
	 * check that means anything -- a truncated `mdat` keeps a perfectly good
	 * header. `readImage()` decodes the whole frame, `pingImage()` does not,
	 * which is why the ping this replaced passed files the browser refuses.
	 *
	 * With Imagick absent the header check stands alone for the formats PHP
	 * reads, and a format it does not read is refused: a re-encode that cannot
	 * be checked must not replace a file that works.
	 */
	public static function decodes( string $path ): bool {
		$header = false !== @getimagesize( $path );
		if ( ! class_exists( '\Imagick' ) ) {
			return $header;
		}
		try {
			$image = new \Imagick();
			$image->readImage( $path );
			$ok = $image->getImageWidth() > 0 && $image->getImageHeight() > 0;
			$image->clear();
			return $ok;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Read an `--older-than` value, and say whether it is relative.
	 *
	 * A relative value is refused by the caller. `--older-than` is the resume
	 * mechanism: a regenerated file carries a fresh mtime, so a fixed cutoff
	 * makes each night reach only what the last one did not finish. A relative
	 * cutoff moves with the run instead, so a few nights later the same files
	 * are older than it again and the sweep re-encodes work it already did,
	 * for as long as anyone leaves the cron line in place.
	 *
	 * Relative is detected rather than pattern-matched: the value is read
	 * against two different "now"s, and a value that answers differently
	 * depends on when it is read.
	 *
	 * An absolute value with no zone is read in the PHP process timezone,
	 * which under cron is not necessarily the site's. Write the zone into the
	 * value to settle it.
	 *
	 * @return array{time: int|null, relative: bool}
	 */
	public static function parseCutoff( string $value ): array {
		$here  = strtotime( $value, 1000000000 );
		$later = strtotime( $value, 2000000000 );

		if ( false === $here || false === $later ) {
			return array( 'time' => null, 'relative' => false );
		}
		if ( $here !== $later ) {
			return array( 'time' => null, 'relative' => true );
		}

		return array( 'time' => $here, 'relative' => false );
	}

	/**
	 * Whether there is room on the cache directory's filesystem.
	 *
	 * A run that fills the disk does not only fail: it leaves a site that
	 * cannot write a session, a log or an upload. Unreadable free space passes
	 * -- a number nobody can read is not evidence of a full disk, and a run
	 * that refuses on it never runs on the hosts that hide it.
	 *
	 * @param int $selected_bytes What the files this run would touch weigh now.
	 * @return array{free: int, needed: int, ok: bool}
	 */
	public function diskPreflight( int $selected_bytes ): array {
		$needed = (int) ( $selected_bytes * self::DISK_FACTOR );
		$free   = @disk_free_space( $this->cache_dir );

		if ( false === $free ) {
			return array( 'free' => -1, 'needed' => $needed, 'ok' => true );
		}

		return array( 'free' => (int) $free, 'needed' => $needed, 'ok' => (int) $free >= $needed );
	}

	/**
	 * The status a finished run leaves behind.
	 *
	 * Cron and monitoring read the status, not the summary. A run that failed
	 * files, or gave up waiting for the load with work left, must not look
	 * like a run that finished -- the sweep would otherwise report success
	 * every night while never reaching the end.
	 *
	 * @param int  $failed  Files whose re-encode was refused.
	 * @param bool $gave_up Whether the load gate ended the run early.
	 */
	public static function exitStatus( int $failed, bool $gave_up ): int {
		return $failed > 0 || $gave_up ? 1 : 0;
	}

	/**
	 * Cap the threads Imagick gives one encode.
	 *
	 * One AVIF encode saturates every core by default, which is the load the
	 * `--max-load` gate cannot see coming: the one-minute average reports it
	 * only after the damage is a minute old. Capping the encoder is the part
	 * that acts immediately.
	 *
	 * This reaches the Imagick extension only. An ImageMagick binary called as
	 * a subprocess reads `MAGICK_THREAD_LIMIT` and `OMP_NUM_THREADS` from the
	 * environment instead, which is the cron line's job.
	 *
	 * @return bool Whether the limit was applied.
	 */
	public static function applyThreadLimit( int $threads ): bool {
		if ( $threads < 1 || ! class_exists( '\Imagick' ) ) {
			return false;
		}
		try {
			\Imagick::setResourceLimit( \Imagick::RESOURCETYPE_THREAD, $threads );
			return true;
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
