<?php

declare(strict_types=1);

namespace Tests\Unit\Cli;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Cli\MigrateImageCacheCommand;
use Parisek\TimberKit\ImageCacheMigrator;
use PHPUnit\Framework\TestCase;

/**
 * `buildNameToSourcePaths()` is private and reads `$wpdb` directly, so it is
 * exercised here through reflection with a stub `$wpdb`, rather than through
 * `__invoke()` (WP_CLI I/O, deliberately not unit-tested per the class
 * docblock).
 *
 * ADR 0008's amendment: the map's values are full source paths (directory
 * *and* the source's own filename, extension included), not bare
 * directories -- the target filename needs the source's extension, and two
 * sources sharing a directory and stem but differing only by extension
 * (`hero.jpg` / `hero.png`) are two distinct identities that collided under
 * one flat name.
 */
class MigrateImageCacheCommandBuildNameToSourcePathsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Same stub shape as guardSourceDir's own test: keep A-Za-z0-9._-,
		// strip the rest, so `'aug ust'` (a space) fails the guard.
		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $name );
		} );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param list<string> $attached_files
	 */
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

	/** @return array<string, list<string>> */
	private function buildNameToSourcePaths(): array {
		$command = new MigrateImageCacheCommand();
		$method  = new \ReflectionMethod( $command, 'buildNameToSourcePaths' );
		$method->setAccessible( true );

		return $method->invoke( $command );
	}

	public function test_rejected_directory_still_contributes_the_flat_key(): void {
		// A: rejected directory ('aug ust' contains a space) -- the runtime
		// keeps its derivative at the flat cache key, so it contributes just
		// the bare (sanitized) filename, no directory prefix.
		// B: clean directory, same basename, different extension.
		$this->stubWpdb( [ '2026/aug ust/hero.png', '2026/08/hero.jpg' ] );

		$name_to_source_paths = $this->buildNameToSourcePaths();

		$this->assertArrayHasKey( 'hero', $name_to_source_paths );
		$this->assertSame( [ 'hero.png', '2026/08/hero.jpg' ], $name_to_source_paths['hero'] );
	}

	public function test_a_genuine_root_upload_still_maps_the_same_way(): void {
		$this->stubWpdb( [ 'hero.png' ] );

		$name_to_source_paths = $this->buildNameToSourcePaths();

		$this->assertSame( [ 'hero.png' ], $name_to_source_paths['hero'] );
	}

	/**
	 * Two sources in the same clean directory, same stem, different
	 * extension: ADR 0008's own worked example of the same-directory
	 * collision. Both must be recorded under the flat name, not
	 * collapsed into one.
	 */
	public function test_same_directory_same_stem_different_extension_both_recorded(): void {
		$this->stubWpdb( [ '2026/08/hero.jpg', '2026/08/hero.png' ] );

		$name_to_source_paths = $this->buildNameToSourcePaths();

		$this->assertSame( [ '2026/08/hero.jpg', '2026/08/hero.png' ], $name_to_source_paths['hero'] );
	}

	/**
	 * The full interaction: a flat derivative shared between a
	 * guard-rejected upload and a clean one must be reported ambiguous, not
	 * silently moved into the clean upload's directory.
	 */
	public function test_flat_derivative_shared_with_a_rejected_directory_is_ambiguous_not_moved(): void {
		$this->stubWpdb( [ '2026/aug ust/hero.png', '2026/08/hero.jpg' ] );

		$name_to_source_paths = $this->buildNameToSourcePaths();

		$cache_dir = sys_get_temp_dir() . '/tk-cache-' . bin2hex( random_bytes( 6 ) );
		mkdir( $cache_dir . '/900x0-center', 0777, true );
		file_put_contents( $cache_dir . '/900x0-center/hero.avif', 'x' );

		try {
			$plan = ( new ImageCacheMigrator( $cache_dir, $name_to_source_paths ) )->plan();

			$this->assertSame( [], $plan['move'] );
			$this->assertSame( [ 'hero.png', '2026/08/hero.jpg' ], $plan['ambiguous']['900x0-center/hero.avif'] );
		} finally {
			unlink( $cache_dir . '/900x0-center/hero.avif' );
			rmdir( $cache_dir . '/900x0-center' );
			rmdir( $cache_dir );
		}
	}

	/**
	 * Same directory, same stem, distinct source extensions -- ADR 0008's
	 * worked example. Since recovering which source the flat `hero.avif`
	 * actually came from is impossible, the migrator must leave it alone
	 * rather than guess.
	 */
	public function test_ambiguous_by_extension_alone_is_left_unmigrated(): void {
		$this->stubWpdb( [ '2026/08/hero.jpg', '2026/08/hero.png' ] );

		$name_to_source_paths = $this->buildNameToSourcePaths();

		$cache_dir = sys_get_temp_dir() . '/tk-cache-' . bin2hex( random_bytes( 6 ) );
		mkdir( $cache_dir . '/900x0-center', 0777, true );
		file_put_contents( $cache_dir . '/900x0-center/hero.avif', 'x' );

		try {
			$plan = ( new ImageCacheMigrator( $cache_dir, $name_to_source_paths ) )->plan();

			$this->assertSame( [], $plan['move'] );
			$this->assertArrayHasKey( '900x0-center/hero.avif', $plan['ambiguous'] );
		} finally {
			unlink( $cache_dir . '/900x0-center/hero.avif' );
			rmdir( $cache_dir . '/900x0-center' );
			rmdir( $cache_dir );
		}
	}
}
