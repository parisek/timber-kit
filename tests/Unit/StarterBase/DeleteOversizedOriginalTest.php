<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies delete_oversized_original() reclaims disk space by deleting the
 * full-resolution original WordPress preserves when it generates a -scaled
 * derivative, and removes the now-dangling original_image metadata pointer.
 */
class DeleteOversizedOriginalTest extends StarterBaseTestCase {

	/** @var string[] */
	private array $temp_files = [];

	protected function tearDown(): void {
		foreach ( $this->temp_files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}

		parent::tearDown();
	}

	public function test_deletes_original_and_unsets_pointer(): void {
		$dir      = sys_get_temp_dir();
		$original = $dir . '/tk-original-' . uniqid() . '.jpg';
		file_put_contents( $original, 'x' );
		$this->temp_files[] = $original;

		Functions\when( 'wp_get_upload_dir' )->justReturn( [ 'basedir' => $dir ] );
		Functions\when( 'wp_delete_file' )->alias( function ( $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		} );

		$base = $this->createStarterBase();

		$metadata = [
			'file'           => basename( $original ),
			'original_image' => basename( $original ),
		];

		$result = $base->delete_oversized_original( $metadata, 123 );

		$this->assertFileDoesNotExist( $original );
		$this->assertArrayNotHasKey( 'original_image', $result );
	}

	public function test_skips_when_no_original_image(): void {
		Functions\when( 'wp_get_upload_dir' )->justReturn( [ 'basedir' => sys_get_temp_dir() ] );
		Functions\expect( 'wp_delete_file' )->never();

		$base = $this->createStarterBase();

		$metadata = [ 'file' => '2026/06/image.jpg' ];

		$this->assertSame( $metadata, $base->delete_oversized_original( $metadata, 123 ) );
	}

	public function test_skips_when_file_key_missing(): void {
		Functions\when( 'wp_get_upload_dir' )->justReturn( [ 'basedir' => sys_get_temp_dir() ] );
		Functions\expect( 'wp_delete_file' )->never();

		$base = $this->createStarterBase();

		$metadata = [ 'original_image' => 'image.jpg' ];

		$this->assertSame( $metadata, $base->delete_oversized_original( $metadata, 123 ) );
	}

	public function test_returns_metadata_untouched_when_not_array(): void {
		$base = $this->createStarterBase();

		$this->assertFalse( $base->delete_oversized_original( false, 123 ) );
	}
}
