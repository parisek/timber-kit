<?php

declare(strict_types=1);

namespace Tests\Unit\Cli;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Cli\MigrateImageCacheCommand;
use Parisek\TimberKit\ImageCacheMigrator;
use PHPUnit\Framework\TestCase;

/**
 * `buildNameToDirs()` is private and reads `$wpdb` directly, so it is
 * exercised here through reflection with a stub `$wpdb`, rather than through
 * `__invoke()` (WP_CLI I/O, deliberately not unit-tested per the class
 * docblock).
 *
 * The scenario below is the one in
 * `docs/adr/0007-resizer-source-path-cache-key.md`: a directory
 * `guardSourceDir()` rejects must not simply vanish from the map, because
 * the flag-enabled `Resizer` keeps that attachment's derivative at the flat
 * cache key exactly like a genuine root upload does. If the rejected
 * directory is dropped instead of folded into the flat key, a real
 * collision at that flat key reads as an unambiguous move and corrupts a
 * sibling attachment's derivative.
 */
class MigrateImageCacheCommandBuildNameToDirsTest extends TestCase {

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
	private function buildNameToDirs(): array {
		$command = new MigrateImageCacheCommand();
		$method  = new \ReflectionMethod( $command, 'buildNameToDirs' );
		$method->setAccessible( true );

		return $method->invoke( $command );
	}

	public function test_rejected_directory_still_contributes_the_flat_key(): void {
		// A: rejected directory ('aug ust' contains a space) -- the runtime
		// keeps its derivative at the flat cache key.
		// B: clean directory, same basename.
		$this->stubWpdb( [ '2026/aug ust/hero.png', '2026/08/hero.jpg' ] );

		$name_to_dirs = $this->buildNameToDirs();

		$this->assertArrayHasKey( 'hero', $name_to_dirs );
		$this->assertSame( [ '', '2026/08' ], $name_to_dirs['hero'] );
	}

	public function test_a_genuine_root_upload_still_maps_the_same_way(): void {
		$this->stubWpdb( [ 'hero.png' ] );

		$name_to_dirs = $this->buildNameToDirs();

		$this->assertSame( [ '' ], $name_to_dirs['hero'] );
	}

	/**
	 * The full interaction: a flat derivative shared between a
	 * guard-rejected upload and a clean one must be reported ambiguous, not
	 * silently moved into the clean upload's directory.
	 */
	public function test_flat_derivative_shared_with_a_rejected_directory_is_ambiguous_not_moved(): void {
		$this->stubWpdb( [ '2026/aug ust/hero.png', '2026/08/hero.jpg' ] );

		$name_to_dirs = $this->buildNameToDirs();

		$cache_dir = sys_get_temp_dir() . '/tk-cache-' . bin2hex( random_bytes( 6 ) );
		mkdir( $cache_dir . '/900x0-center', 0777, true );
		file_put_contents( $cache_dir . '/900x0-center/hero.avif', 'x' );

		try {
			$plan = ( new ImageCacheMigrator( $cache_dir, $name_to_dirs ) )->plan();

			$this->assertSame( [], $plan['move'] );
			$this->assertSame( [ '', '2026/08' ], $plan['ambiguous']['900x0-center/hero.avif'] );
		} finally {
			unlink( $cache_dir . '/900x0-center/hero.avif' );
			rmdir( $cache_dir . '/900x0-center' );
			rmdir( $cache_dir );
		}
	}
}
