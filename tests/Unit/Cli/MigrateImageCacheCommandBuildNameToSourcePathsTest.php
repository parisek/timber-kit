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


		// Same stub shape as guardSourceDir's own test: keep A-Za-z0-9._-,
		// A traversal component fails the guard; a space no longer does, because
		// the guard refuses only what can leave the directory (ADR 0008).
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
		// A: refused directory ('..' can escape the cache tree) -- the runtime
		// keeps its derivative at the flat cache key, so it contributes just
		// the bare (sanitized) filename, no directory prefix.
		// B: clean directory, same basename, different extension.
		$this->stubWpdb( [ '2026/../hero.png', '2026/08/hero.jpg' ] );

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
		$this->stubWpdb( [ '2026/../hero.png', '2026/08/hero.jpg' ] );

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
	/**
	 * The regression this change exists for.
	 *
	 * A deployment whose `sanitize_file_name()` filter lowercases and maps `_`
	 * to `-` makes `usp_1.webp` and `usp-1.webp` sanitize to one spelling. The
	 * map used to store that sanitized spelling, so the two uploads deduped
	 * into a single candidate: the name looked unambiguous, and the planner
	 * moved one upload's derivative into a path the other one also claims.
	 *
	 * Storing the stored name verbatim keeps them two identities, so the flat
	 * derivative they share is reported ambiguous and left alone. Eight such
	 * pairs were measured on one production site.
	 */
	public function test_two_sources_that_sanitize_alike_stay_two_candidates(): void {
		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			return strtolower( str_replace( '_', '-', (string) $name ) );
		} );

		$this->stubWpdb( [ '2025/05/usp_1.webp', '2025/05/usp-1.webp' ] );

		$map = $this->buildNameToSourcePaths();

		$this->assertSame(
			[ '2025/05/usp_1.webp', '2025/05/usp-1.webp' ],
			$map['usp-1'],
			'two distinct uploads must stay two candidates, however alike they sanitize'
		);
	}

	/**
	 * The resizer refuses SVG and PDF before it computes a cache key, so
	 * neither can ever have written a derivative. Counting them as candidates
	 * made a real image's derivative look contested and left it unmigrated:
	 * 30 of 329 ambiguous entries on the surveyed site were exactly this.
	 */
	public function test_a_source_the_resizer_cannot_decode_is_not_a_candidate(): void {
		$this->stubWpdb( [ '2022/01/badge.svg', '2022/01/badge.webp', '2025/04/guide.pdf' ] );

		$map = $this->buildNameToSourcePaths();

		$this->assertSame(
			[ '2022/01/badge.webp' ],
			$map['badge'],
			'the SVG must not make the webp look contested'
		);
		$this->assertArrayNotHasKey( 'guide', $map, 'a PDF contributes no candidate at all' );
	}

	/**
	 * The exclusion reads the static format superset, never the live
	 * `canDecode()`. A site that resized TIFFs under Imagick and has since
	 * moved to a GD build still has those derivatives on disk; dropping the
	 * source because *today's* backend cannot read it would leave the
	 * surviving candidate looking unique, and move another attachment's
	 * derivative into it.
	 */
	public function test_a_format_the_current_backend_cannot_read_is_still_a_candidate(): void {
		$this->stubWpdb( [ '2022/01/scan.tif', '2022/01/scan.png' ] );

		$map = $this->buildNameToSourcePaths();

		$this->assertSame(
			[ '2022/01/scan.tif', '2022/01/scan.png' ],
			$map['scan'],
			'a statically supported format stays a candidate whatever the backend reports now'
		);
	}

}
