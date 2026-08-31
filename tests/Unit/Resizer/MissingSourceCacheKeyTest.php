<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Tests\Unit\ResizerTestCase;

/**
 * The source-path segment is appended *before* the `file_exists()` branch, and
 * that ordering is a stated design point of ADR 0008 rather than an accident.
 *
 * A missing source is handed to `timber_kit_resizer_missing_source_variants`,
 * which is how DevMediaProxy serves a local render from a production origin.
 * The proxy addresses the same cache path on the other host, so it has to
 * receive the same key a present source would have produced. Every other
 * flag-on test stubs `file_exists()` to true, so moving the append below the
 * branch would pass all of them.
 */
class MissingSourceCacheKeyTest extends ResizerTestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_upload_dir' )->justReturn( [
			'basedir' => '/var/www/wp-content/uploads',
			'baseurl' => 'https://example.com/wp-content/uploads',
		] );
		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/png', 'ext' => 'png' ] );
		// Only the flag-off branch reaches it; flag on uses the name verbatim.
		Functions\when( 'sanitize_file_name' )->alias( fn( $n ) => (string) $n );

		// The point of this file: the source is NOT on disk.
		\Patchwork\redefine( 'file_exists', function ( string $path ) {
			return false;
		} );
	}

	protected function tearDown(): void {
		\Patchwork\restoreAll();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return array{0: array<int, array<string, mixed>>, 1: string} */
	private function captureMissingSourceCall( bool $flag ): array {
		$seen_variants = [];
		$seen_filename = '';

		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $default, ...$args ) use ( $flag, &$seen_variants, &$seen_filename ) {
				if ( 'timber_kit_resizer_source_path_in_cache_key' === $filter ) {
					return $flag;
				}
				if ( 'timber_kit_resizer_missing_source_variants' === $filter ) {
					$seen_variants = $args[0];
					$seen_filename = $args[1];
				}
				return $default;
			}
		);

		( new \Parisek\TimberKit\Resizer() )->resizer(
			[ 'src' => 'https://example.com/wp-content/uploads/2026/08/hero.png', 'width' => 100, 'height' => 100, 'alt' => '' ],
			[ [ '900', '0', '', 'center' ] ]
		);

		return [ $seen_variants, $seen_filename ];
	}

	public function test_flag_on_a_missing_source_is_offered_the_source_path_key(): void {
		[ $variants, $filename ] = $this->captureMissingSourceCall( true );

		$this->assertSame(
			'900x0-center/2026/08',
			$variants[0]['cache_key'],
			'the proxy must be handed the same key a present source would have produced'
		);
		$this->assertSame( 'hero.png', $filename, 'and the same filename, extension included' );
	}

	public function test_flag_off_a_missing_source_keeps_the_flat_key(): void {
		[ $variants, $filename ] = $this->captureMissingSourceCall( false );

		$this->assertSame( '900x0-center', $variants[0]['cache_key'] );
		$this->assertSame( 'hero', $filename );
	}
}
