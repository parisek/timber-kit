<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Brain\Monkey\Functions;
use Tests\Unit\ResizerTestCase;

/**
 * ADR 0007's amendment: the flag must also retain the source's own
 * extension in the derivative filename, not just its directory --
 * `hero.jpg` and `hero.png` sharing a directory collided on one derivative
 * name (`hero.avif`) even after the directory segment was added.
 */
class SourceExtensionCacheKeyTest extends ResizerTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_upload_dir' )->justReturn( [
			'basedir' => '/var/www/html/wp-content/uploads',
			'baseurl' => 'https://example.com/wp-content/uploads',
		] );
		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $name );
		} );
		Functions\when( 'content_url' )->alias( function ( $path ) {
			return 'https://example.com/wp-content/' . $path;
		} );
		Functions\when( 'wp_check_filetype' )->alias( function ( $path ) {
			if ( str_contains( $path, '.avif' ) ) {
				return [ 'type' => 'image/avif', 'ext' => 'avif' ];
			}
			if ( str_contains( $path, '.png' ) ) {
				return [ 'type' => 'image/png', 'ext' => 'png' ];
			}
			return [ 'type' => 'image/jpeg', 'ext' => 'jpg' ];
		} );

		// All variants "already cached" so processVariant() never reaches
		// the encoder -- these tests assert the computed path, not pixels.
		\Patchwork\redefine( 'file_exists', function ( string $path ) {
			return true;
		} );
	}

	protected function tearDown(): void {
		\Patchwork\restoreAll();
		parent::tearDown();
	}

	/** The flag off must keep dropping the source extension, byte-identical to today. */
	public function test_flag_off_pins_the_extension_stripped_filename(): void {
		$resizer = $this->createResizerWithSourcePathFlag( false );

		$result = $resizer->resizer(
			[ 'src' => 'https://example.com/wp-content/uploads/2026/08/hero.webp', 'width' => 100, 'height' => 100, 'alt' => '' ],
			[ [ '900', '0', '', 'center' ] ]
		);

		$this->assertStringContainsString( '900x0-center/hero.avif', $result[0]['src'] );
		$this->assertStringNotContainsString( 'hero.webp.avif', $result[0]['src'] );
	}

	/** The regression this amendment exists for: two sources, one directory, different extensions. */
	public function test_flag_on_two_sources_sharing_a_directory_and_stem_get_different_filenames(): void {
		$resizer = $this->createResizerWithSourcePathFlag( true );

		$jpg = $resizer->resizer(
			[ 'src' => 'https://example.com/wp-content/uploads/2026/08/hero.jpg', 'width' => 100, 'height' => 100, 'alt' => '' ],
			[ [ '900', '0', '', 'center' ] ]
		);
		$png = $resizer->resizer(
			[ 'src' => 'https://example.com/wp-content/uploads/2026/08/hero.png', 'width' => 100, 'height' => 100, 'alt' => '' ],
			[ [ '900', '0', '', 'center' ] ]
		);

		$this->assertStringContainsString( '900x0-center/2026/08/hero.jpg.avif', $jpg[0]['src'] );
		$this->assertStringContainsString( '900x0-center/2026/08/hero.png.avif', $png[0]['src'] );
		$this->assertNotSame( $jpg[0]['src'], $png[0]['src'] );
	}

	/** ADR 0007's worked example. */
	public function test_flag_on_matches_the_adr_worked_example(): void {
		$resizer = $this->createResizerWithSourcePathFlag( true );

		$result = $resizer->resizer(
			[ 'src' => 'https://example.com/wp-content/uploads/2026/08/hero.webp', 'width' => 100, 'height' => 100, 'alt' => '' ],
			[ [ '900', '0', '', 'center' ] ]
		);

		$this->assertStringContainsString( '900x0-center/2026/08/hero.webp.avif', $result[0]['src'] );
	}
}
