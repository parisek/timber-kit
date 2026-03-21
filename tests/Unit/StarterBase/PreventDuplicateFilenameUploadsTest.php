<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

class PreventDuplicateFilenameUploadsTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
	}

	public function test_passes_non_image_files_through(): void {
		$file = [ 'name' => 'document.pdf' ];

		$result = $this->base->prevent_duplicate_filename_uploads( $file );

		$this->assertSame( $file, $result );
		$this->assertArrayNotHasKey( 'error', $result );
	}

	public function test_passes_through_when_upload_dir_missing(): void {
		Functions\when( 'wp_upload_dir' )->justReturn( [ 'path' => '/nonexistent/path' ] );

		$file = [ 'name' => 'photo.jpg' ];
		$result = $this->base->prevent_duplicate_filename_uploads( $file );

		$this->assertArrayNotHasKey( 'error', $result );
	}

	public function test_detects_duplicate_with_different_extension(): void {
		// Create temp directory with an existing file
		$tmp_dir = sys_get_temp_dir() . '/wp_upload_test_' . uniqid();
		mkdir( $tmp_dir );
		touch( $tmp_dir . '/sample.png' );

		Functions\when( 'wp_upload_dir' )->justReturn( [ 'path' => $tmp_dir ] );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$file = [ 'name' => 'sample.jpg' ];
		$result = $this->base->prevent_duplicate_filename_uploads( $file );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertNotEmpty( $result['error'] );

		// Cleanup
		unlink( $tmp_dir . '/sample.png' );
		rmdir( $tmp_dir );
	}

	public function test_allows_same_filename_same_extension(): void {
		$tmp_dir = sys_get_temp_dir() . '/wp_upload_test_' . uniqid();
		mkdir( $tmp_dir );
		touch( $tmp_dir . '/sample.jpg' );

		Functions\when( 'wp_upload_dir' )->justReturn( [ 'path' => $tmp_dir ] );

		$file = [ 'name' => 'sample.jpg' ];
		$result = $this->base->prevent_duplicate_filename_uploads( $file );

		$this->assertArrayNotHasKey( 'error', $result );

		// Cleanup
		unlink( $tmp_dir . '/sample.jpg' );
		rmdir( $tmp_dir );
	}

	public function test_allows_different_basename(): void {
		$tmp_dir = sys_get_temp_dir() . '/wp_upload_test_' . uniqid();
		mkdir( $tmp_dir );
		touch( $tmp_dir . '/photo.jpg' );

		Functions\when( 'wp_upload_dir' )->justReturn( [ 'path' => $tmp_dir ] );

		$file = [ 'name' => 'banner.jpg' ];
		$result = $this->base->prevent_duplicate_filename_uploads( $file );

		$this->assertArrayNotHasKey( 'error', $result );

		// Cleanup
		unlink( $tmp_dir . '/photo.jpg' );
		rmdir( $tmp_dir );
	}

	public function test_ignores_non_image_existing_files(): void {
		$tmp_dir = sys_get_temp_dir() . '/wp_upload_test_' . uniqid();
		mkdir( $tmp_dir );
		touch( $tmp_dir . '/sample.pdf' );

		Functions\when( 'wp_upload_dir' )->justReturn( [ 'path' => $tmp_dir ] );

		$file = [ 'name' => 'sample.jpg' ];
		$result = $this->base->prevent_duplicate_filename_uploads( $file );

		$this->assertArrayNotHasKey( 'error', $result );

		// Cleanup
		unlink( $tmp_dir . '/sample.pdf' );
		rmdir( $tmp_dir );
	}
}
