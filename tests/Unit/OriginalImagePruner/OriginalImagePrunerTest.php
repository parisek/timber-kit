<?php

declare(strict_types=1);

namespace Tests\Unit\OriginalImagePruner;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\OriginalImagePruner;
use PHPUnit\Framework\TestCase;

/**
 * Verifies OriginalImagePruner only prunes genuine size-driven `-scaled`
 * downscales, resolves/deletes the preserved original safely, and never strips
 * the `original_image` metadata pointer unless the file actually went away.
 */
class OriginalImagePrunerTest extends TestCase {

	/** @var string[] */
	private array $temp_files = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		foreach ( $this->temp_files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	private function tempOriginal(): string {
		$file = tempnam( sys_get_temp_dir(), 'tk-orig-' ) . '.jpg';
		file_put_contents( $file, str_repeat( 'x', 1024 ) );
		$this->temp_files[] = $file;
		return $file;
	}

	// --- isScaledDerivative ---------------------------------------------------

	public function test_scaled_suffix_is_recognised(): void {
		$this->assertTrue( OriginalImagePruner::isScaledDerivative( '2026/06/image-scaled.jpg' ) );
		$this->assertTrue( OriginalImagePruner::isScaledDerivative( 'photo-scaled.webp' ) );
	}

	public function test_non_scaled_files_are_rejected(): void {
		$this->assertFalse( OriginalImagePruner::isScaledDerivative( '2026/06/image.jpg' ) );
		// "-scaled" not immediately before the extension (e.g. a rotated/converted file).
		$this->assertFalse( OriginalImagePruner::isScaledDerivative( 'my-scaled-photo.jpg' ) );
		$this->assertFalse( OriginalImagePruner::isScaledDerivative( 'image-rotated.jpg' ) );
	}

	public function test_empty_or_null_file_is_rejected(): void {
		$this->assertFalse( OriginalImagePruner::isScaledDerivative( '' ) );
		$this->assertFalse( OriginalImagePruner::isScaledDerivative( null ) );
	}

	// --- prune ----------------------------------------------------------------

	public function test_deletes_original_and_unsets_pointer_for_scaled_image(): void {
		$original = $this->tempOriginal();

		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [
			'file'           => '2026/06/photo-scaled.jpg',
			'original_image' => 'photo.jpg',
		] );
		Functions\when( 'wp_get_original_image_path' )->justReturn( $original );
		Functions\when( 'wp_delete_file' )->alias( function ( $path ) {
			unlink( $path );
		} );
		$updated = null;
		Functions\when( 'wp_update_attachment_metadata' )->alias( function ( $id, $meta ) use ( &$updated ) {
			$updated = $meta;
			return true;
		} );

		$result = ( new OriginalImagePruner() )->prune( 123 );

		$this->assertSame( 'deleted', $result['status'] );
		$this->assertSame( 1024, $result['bytes'] );
		$this->assertFileDoesNotExist( $original );
		$this->assertIsArray( $updated );
		$this->assertArrayNotHasKey( 'original_image', $updated );
	}

	public function test_skips_non_scaled_file_even_when_original_image_present(): void {
		// EXIF-rotated / format-converted uploads also carry original_image, but the
		// served file is NOT -scaled. Deleting their original would be data loss.
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [
			'file'           => '2026/06/photo-rotated.jpg',
			'original_image' => 'photo.jpg',
		] );
		Functions\expect( 'wp_delete_file' )->never();
		Functions\expect( 'wp_update_attachment_metadata' )->never();

		$result = ( new OriginalImagePruner() )->prune( 123 );

		$this->assertSame( 'not_scaled', $result['status'] );
		$this->assertSame( 0, $result['bytes'] );
	}

	public function test_skips_when_no_original_image_in_metadata(): void {
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [ 'file' => '2026/06/photo.jpg' ] );
		Functions\expect( 'wp_delete_file' )->never();

		$result = ( new OriginalImagePruner() )->prune( 123 );

		$this->assertSame( 'no_original', $result['status'] );
	}

	public function test_reports_missing_when_original_file_absent_on_disk(): void {
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [
			'file'           => '2026/06/photo-scaled.jpg',
			'original_image' => 'photo.jpg',
		] );
		Functions\when( 'wp_get_original_image_path' )->justReturn( '/tmp/does-not-exist-' . uniqid() . '.jpg' );
		Functions\expect( 'wp_delete_file' )->never();

		$result = ( new OriginalImagePruner() )->prune( 123 );

		$this->assertSame( 'missing', $result['status'] );
	}

	public function test_dry_run_reports_bytes_without_deleting(): void {
		$original = $this->tempOriginal();

		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [
			'file'           => '2026/06/photo-scaled.jpg',
			'original_image' => 'photo.jpg',
		] );
		Functions\when( 'wp_get_original_image_path' )->justReturn( $original );
		Functions\expect( 'wp_delete_file' )->never();
		Functions\expect( 'wp_update_attachment_metadata' )->never();

		$result = ( new OriginalImagePruner() )->prune( 123, true );

		$this->assertSame( 'would_delete', $result['status'] );
		$this->assertSame( 1024, $result['bytes'] );
		$this->assertFileExists( $original );
	}

	public function test_failed_delete_does_not_strip_metadata_pointer(): void {
		$original = $this->tempOriginal();

		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [
			'file'           => '2026/06/photo-scaled.jpg',
			'original_image' => 'photo.jpg',
		] );
		Functions\when( 'wp_get_original_image_path' )->justReturn( $original );
		// Simulate a failed unlink: wp_delete_file is a no-op, file persists.
		Functions\when( 'wp_delete_file' )->justReturn( null );
		Functions\expect( 'wp_update_attachment_metadata' )->never();

		$result = ( new OriginalImagePruner() )->prune( 123 );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertFileExists( $original );
	}
}
