<?php

declare(strict_types=1);

namespace Tests\Unit\Cli;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Cli\MigrateImageCacheCommand;
use PHPUnit\Framework\TestCase;

/**
 * `__invoke()`'s WP_CLI I/O is otherwise deliberately not unit-tested (see
 * the class docblock) -- this test exists narrowly to pin the success/error
 * exit-code ordering: a script harness reads only the exit code, and
 * `WP_CLI::success()` reports 0 regardless of what ran before it.
 * `WP_CLI::error()` halts (real WP_CLI exits non-zero), so it must run
 * instead of, never alongside, `success()`.
 *
 * `WP_CONTENT_DIR` is a constant fixed once by the test bootstrap, so —
 * unlike `MigrateImageCacheCommandInvokeTest`'s sibling tests — the cache
 * directory here cannot be varied per test; it is cleaned up in place
 * instead, the same pattern `CleanupCachedImagesTest` uses.
 */
class MigrateImageCacheCommandInvokeTest extends TestCase {

	private string $cache_dir;

	/** @var list<string> */
	private array $created = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Extension -> MIME, the same way wp_check_filetype() derives it. SVG
		// and PDF resolve to types the Resizer's static superset excludes,
		// which is what the map builder filters on.
		Functions\when( 'wp_check_filetype' )->alias( function ( $filename ) {
			$map = [
				'jpg'  => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'png'  => 'image/png',
				'gif'  => 'image/gif',
				'webp' => 'image/webp',
				'avif' => 'image/avif',
				'tif'  => 'image/tiff',
				'tiff' => 'image/tiff',
				'svg'  => 'image/svg+xml',
				'pdf'  => 'application/pdf',
			];
			$ext = strtolower( (string) pathinfo( (string) $filename, PATHINFO_EXTENSION ) );

			return [ 'ext' => $ext, 'type' => $map[ $ext ] ?? false ];
		} );

		\WP_CLI::reset();

		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $name );
		} );
		Functions\when( 'untrailingslashit' )->alias( fn( $s ) => rtrim( (string) $s, '/' ) );
		Functions\when( 'trailingslashit' )->alias( fn( $s ) => rtrim( (string) $s, '/' ) . '/' );

		$this->cache_dir = WP_CONTENT_DIR . '/cache/image';
		$this->makeDir( $this->cache_dir . '/900x0-center' );
	}

	protected function tearDown(): void {
		// Directory permissions are restored before cleanup: a leftover
		// 0555 directory from the error-path test would otherwise make its
		// own removal fail.
		foreach ( array_reverse( $this->created ) as $path ) {
			if ( is_dir( $path ) ) {
				chmod( $path, 0777 );
			}
		}
		foreach ( array_reverse( $this->created ) as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			} elseif ( is_dir( $path ) ) {
				@rmdir( $path );
			}
		}
		$this->created = [];
		unset( $GLOBALS['wpdb'] );
		\WP_CLI::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Create $path and every missing parent, recording only what did not exist. */
	private function makeDir( string $path ): void {
		$missing = [];
		for ( $dir = $path; ! is_dir( $dir ); $dir = dirname( $dir ) ) {
			$missing[] = $dir;
		}
		mkdir( $path, 0777, true );
		$this->created = array_merge( $this->created, array_reverse( $missing ) );
	}

	private function seedFile( string $path ): void {
		file_put_contents( $path, 'x' );
		$this->created[] = $path;
	}

	/** @param list<string> $attached_files */
	private function stubWpdb( array $attached_files ): void {
		$GLOBALS['wpdb'] = new class( $attached_files ) extends \wpdb {
			public string $postmeta = 'wp_postmeta';

			public function __construct( private readonly array $attached_files ) {
			}

			public function get_col( $query ) {
				return $this->attached_files;
			}
		};
	}

	/**
	 * `--apply` refuses to run while the flag is off, so a test that exercises
	 * the moving path has to turn it on.
	 */
	private function enableSourcePathCacheKey(): void {
		Functions\when( 'apply_filters' )->alias( function ( $filter, $value, ...$args ) {
			unset( $args );
			return 'timber_kit_resizer_source_path_in_cache_key' === $filter ? true : $value;
		} );
	}

	public function test_success_is_called_when_nothing_failed(): void {
		$this->seedFile( $this->cache_dir . '/900x0-center/hero.avif' );
		$this->stubWpdb( [ '2099/07/hero.webp' ] );
		$this->enableSourcePathCacheKey();

		( new MigrateImageCacheCommand() )( [], [ 'apply' => true ] );

		$this->assertSame( [], \WP_CLI::$errors );
		$this->assertNotSame( [], \WP_CLI::$successes );
		$this->assertSame( [], \WP_CLI::$warnings );

		// seedFile() tracked the pre-move path for teardown; apply() moved
		// it elsewhere, so track the real destination instead. Parent-first,
		// matching makeDir()'s convention: tearDown() deletes in the reverse
		// (child-first) order, and pushing this child-first here would flip
		// that to parent-first at delete time, attempting rmdir() on a
		// directory before the file inside it is gone.
		$this->created[] = $this->cache_dir . '/900x0-center/2099';
		$this->created[] = $this->cache_dir . '/900x0-center/2099/07';
		$this->created[] = $this->cache_dir . '/900x0-center/2099/07/hero.webp.avif';
	}

	public function test_error_is_called_instead_of_success_when_something_failed(): void {
		$this->seedFile( $this->cache_dir . '/900x0-center/hero.avif' );
		// The target directory exists but is not writable -- link() must
		// fail on the syscall itself (not the pre-existence check plan()
		// already ran), apply() reports it as failed, and __invoke() must
		// reflect that in its exit path instead of reporting success.
		$this->makeDir( $this->cache_dir . '/900x0-center/2099/07' );
		$this->enableSourcePathCacheKey();
		chmod( $this->cache_dir . '/900x0-center/2099/07', 0555 );

		$this->stubWpdb( [ '2099/07/hero.webp' ] );

		try {
			( new MigrateImageCacheCommand() )( [], [ 'apply' => true ] );
			$this->fail( 'expected WP_CLI::error() to halt the command' );
		} catch ( \RuntimeException $e ) {
			// Real WP_CLI::error() halts the same way -- exiting non-zero,
			// never returning control to the caller.
		}

		$this->assertNotSame( [], \WP_CLI::$warnings, 'the failed move must be reported' );
		$this->assertNotSame( [], \WP_CLI::$errors );
		$this->assertSame( [], \WP_CLI::$successes, 'success must never fire alongside a failed move' );
	}
	/**
	 * The command is registered whether or not the flag is on, so that a plan
	 * can be read before deciding. Moving files is a different matter: the
	 * layout it moves them into is exactly what a flag-off site does not read,
	 * so every derivative would be orphaned and re-encoded on first view.
	 */
	public function test_apply_is_refused_while_the_flag_is_off(): void {
		$this->seedFile( $this->cache_dir . '/900x0-center/hero.avif' );
		$this->stubWpdb( [ '2099/07/hero.webp' ] );

		try {
			( new MigrateImageCacheCommand() )( [], [ 'apply' => true ] );
			$this->fail( 'applying with the flag off must not proceed' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'resizer_source_path_in_cache_key', $e->getMessage() );
		}

		$this->assertNotSame( [], \WP_CLI::$errors, 'applying with the flag off must be an error' );
		$this->assertFileExists(
			$this->cache_dir . '/900x0-center/hero.avif',
			'and it must refuse before moving anything'
		);
	}

	/** Reporting stays available with the flag off; only --apply is gated. */
	public function test_a_dry_run_still_reports_while_the_flag_is_off(): void {
		$this->seedFile( $this->cache_dir . '/900x0-center/hero.avif' );
		$this->stubWpdb( [ '2099/07/hero.webp' ] );

		( new MigrateImageCacheCommand() )( [], [] );

		$this->assertSame( [], \WP_CLI::$errors );
		$this->assertNotSame( [], \WP_CLI::$warnings, 'the preview must say the flag is off' );
	}

}
