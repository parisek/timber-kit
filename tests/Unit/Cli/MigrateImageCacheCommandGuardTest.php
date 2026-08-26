<?php

declare(strict_types=1);

namespace Tests\Unit\Cli;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Cli\MigrateImageCacheCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `guardSourceDir()` is the one piece of `MigrateImageCacheCommand` that is a
 * pure function of its input (no `$wpdb`, no `WP_CLI`), so — unlike the rest
 * of the class, which is WP_CLI I/O and deliberately not unit-tested — it is
 * tested directly. It must classify a directory exactly the way
 * `Resizer::sourcePathSegment()` does per path component, or the migrator can
 * move a derivative into a subdirectory the flag-enabled `Resizer` will never
 * look in.
 */
class MigrateImageCacheCommandGuardTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Same stub shape as Resizer's own SourcePathSegmentTest: keep
		// A-Za-z0-9._-, strip the rest.
		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $name );
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public static function directories(): array {
		return [
			'plain year/month' => [ '2026/08', '2026/08' ],
			'deeper custom structure' => [ 'sites/3/2026/08', 'sites/3/2026/08' ],
			'root upload (no directory)' => [ '', '' ],
			'dot from dirname()' => [ '.', '' ],
			'traversal component' => [ '2026/../evil', '' ],
			'dot component' => [ '2026/./08', '' ],
			'empty component from a double slash' => [ '2026//08', '' ],
			'component sanitize_file_name() would alter' => [ '2026/aug ust', '' ],
		];
	}

	#[DataProvider( 'directories' )]
	public function test_guard_source_dir( string $dir, string $expected ): void {
		$this->assertSame( $expected, MigrateImageCacheCommand::guardSourceDir( $dir ) );
	}
}
