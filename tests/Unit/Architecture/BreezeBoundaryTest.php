<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Everything that knows about the Breeze plugin lives under `src/Breeze/`.
 *
 * The point is excision: a project that does not run Breeze should be able to
 * see, at a glance, exactly which directory is dead weight. A boundary nobody
 * enforces blurs within months — one helper reaching for `breeze_get_option()`
 * from somewhere convenient, and the directory stops meaning anything.
 *
 * Two exceptions, both deliberate:
 * - `StarterBase` carries the opt-in flags and delegates. The flag names are
 *   public API — six projects in the fleet set them — so they stay put even
 *   though the implementation moved.
 * - `compat/aliases.php` keeps the pre-move class name resolving.
 */
class BreezeBoundaryTest extends TestCase {

	/** @var array<int, string> Paths allowed to name Breeze outside src/Breeze/. */
	private const ALLOWED = array(
		'src/StarterBase.php',
	);

	/**
	 * @return array<int, string>
	 */
	private function phpFilesUnderSrc(): array {
		$root  = dirname( __DIR__, 3 ) . '/src';
		$files = array();

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		sort( $files );

		return $files;
	}

	public function test_only_the_breeze_directory_names_breeze(): void {
		$root      = dirname( __DIR__, 3 );
		$offenders = array();

		foreach ( $this->phpFilesUnderSrc() as $path ) {
			$relative = ltrim( str_replace( $root, '', $path ), '/' );

			if ( str_starts_with( $relative, 'src/Breeze/' ) ) {
				continue;
			}
			if ( in_array( $relative, self::ALLOWED, true ) ) {
				continue;
			}

			$contents = (string) file_get_contents( $path );
			if ( 1 === preg_match( '/breeze/i', $contents ) ) {
				$offenders[] = $relative;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"These files name Breeze outside src/Breeze/:\n" . implode( "\n", $offenders )
		);
	}

	public function test_the_breeze_directory_exists_and_is_not_empty(): void {
		// Guards against the boundary passing vacuously if the directory were
		// ever removed or renamed without updating this test.
		$dir = dirname( __DIR__, 3 ) . '/src/Breeze';

		$this->assertDirectoryExists( $dir );
		$this->assertNotEmpty( glob( $dir . '/*.php' ) );
	}
}
