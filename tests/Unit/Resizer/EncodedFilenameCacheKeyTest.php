<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Brain\Monkey\Functions;
use Tests\Unit\ResizerTestCase;

/**
 * ADR 0008's derivative name must agree with the name
 * `StarterBase::cached_derivative_paths_by_source_path()` and
 * `MigrateImageCacheCommand`'s map builder compute from the decoded
 * `_wp_attached_file` value -- otherwise the writer and the deleter/migrator
 * name the same source differently and never find each other's file.
 *
 * `wp_upload_dir()['baseurl']` and an attachment's `src` are URLs, so a
 * space or a non-ASCII character in the filename is percent-encoded there
 * but not in the database. The filename half of the cache key must decode
 * the URL first, exactly as `Resizer::sourcePathSegment()` already does for
 * the directory half.
 */
class EncodedFilenameCacheKeyTest extends ResizerTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_upload_dir' )->justReturn( [
			'basedir' => '/var/www/html/wp-content/uploads',
			'baseurl' => 'https://example.com/wp-content/uploads',
		] );
		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			return preg_replace( '/[^A-Za-z0-9._ -]/', '', (string) $name );
		} );
		Functions\when( 'content_url' )->alias( function ( $path ) {
			return 'https://example.com/wp-content/' . $path;
		} );
		Functions\when( 'wp_check_filetype' )->alias( function ( $path ) {
			unset( $path );
			return [ 'type' => 'image/jpeg', 'ext' => 'jpg' ];
		} );

		\Patchwork\redefine( 'file_exists', function ( string $path ) {
			return true;
		} );
	}

	protected function tearDown(): void {
		\Patchwork\restoreAll();
		parent::tearDown();
	}

	/**
	 * The measured production shape: 230 `_wp_attached_file` values contain a
	 * space. The writer must name the derivative from the decoded name
	 * (`My Photo.JPG`), matching what the deleter/migrator compute from the
	 * DB value -- not from the still-encoded URL basename (`My%20Photo.JPG`).
	 */
	public function test_percent_encoded_space_decodes_before_sanitizing(): void {
		$resizer = $this->createResizerWithSourcePathFlag( true );

		$result = $resizer->resizer(
			[ 'src' => 'https://example.com/wp-content/uploads/2026/08/My%20Photo.JPG', 'width' => 100, 'height' => 100, 'alt' => '' ],
			[ [ '900', '0', '', 'center' ] ]
		);

		$this->assertStringContainsString( '900x0-center/2026/08/My Photo.JPG.avif', $result[0]['src'] );
		$this->assertStringNotContainsString( 'My%20Photo.JPG', $result[0]['src'] );
	}

	/** UTF-8 percent-encoding (e.g. an accented character) must decode the same way. */
	public function test_percent_encoded_utf8_decodes_before_sanitizing(): void {
		$resizer = $this->createResizerWithSourcePathFlag( true );

		$result = $resizer->resizer(
			[ 'src' => 'https://example.com/wp-content/uploads/2026/08/caf%C3%A9.jpg', 'width' => 100, 'height' => 100, 'alt' => '' ],
			[ [ '900', '0', '', 'center' ] ]
		);

		$this->assertStringNotContainsString( '%C3%A9', $result[0]['src'] );
	}

	/** A query string on the URL must not leak into the derivative's filename. */
	public function test_query_string_is_stripped_before_sanitizing(): void {
		$resizer = $this->createResizerWithSourcePathFlag( true );

		$result = $resizer->resizer(
			[ 'src' => 'https://example.com/wp-content/uploads/2026/08/hero.jpg?ver=123', 'width' => 100, 'height' => 100, 'alt' => '' ],
			[ [ '900', '0', '', 'center' ] ]
		);

		$this->assertStringContainsString( '900x0-center/2026/08/hero.jpg.avif', $result[0]['src'] );
		$this->assertStringNotContainsString( 'ver=123', $result[0]['src'] );
	}
}
