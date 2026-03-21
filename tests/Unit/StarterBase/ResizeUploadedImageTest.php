<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

class ResizeUploadedImageTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase( [
			'max_upload_width' => 2560,
			'max_upload_height' => 2560,
		] );
	}

	public function test_returns_unchanged_when_under_limit(): void {
		$upload = [
			'file' => '/tmp/test.jpg',
			'type' => 'image/jpeg',
		];

		Functions\when( 'getimagesize' )->justReturn( [ 800, 600 ] );

		$result = $this->base->resize_uploaded_image( $upload );
		$this->assertSame( $upload, $result );
	}

	public function test_resizes_when_width_exceeds_limit(): void {
		$upload = [
			'file' => '/tmp/test.jpg',
			'type' => 'image/jpeg',
		];

		$editor = \Mockery::mock( 'WP_Image_Editor' );
		$editor->shouldReceive( 'resize' )->once()->with( 2560, 2560 )->andReturn( true );
		$editor->shouldReceive( 'save' )->once()->with( '/tmp/test.jpg' )->andReturn( true );

		Functions\when( 'getimagesize' )->justReturn( [ 4000, 2000 ] );
		Functions\when( 'wp_get_image_editor' )->justReturn( $editor );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$result = $this->base->resize_uploaded_image( $upload );
		$this->assertSame( $upload, $result );
	}

	public function test_resizes_when_height_exceeds_limit(): void {
		$upload = [
			'file' => '/tmp/test.jpg',
			'type' => 'image/jpeg',
		];

		$editor = \Mockery::mock( 'WP_Image_Editor' );
		$editor->shouldReceive( 'resize' )->once()->with( 2560, 2560 )->andReturn( true );
		$editor->shouldReceive( 'save' )->once()->with( '/tmp/test.jpg' )->andReturn( true );

		Functions\when( 'getimagesize' )->justReturn( [ 2000, 4000 ] );
		Functions\when( 'wp_get_image_editor' )->justReturn( $editor );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$result = $this->base->resize_uploaded_image( $upload );
		$this->assertSame( $upload, $result );
	}

	public function test_resizes_when_both_dimensions_exceed_limit(): void {
		$upload = [
			'file' => '/tmp/test.jpg',
			'type' => 'image/jpeg',
		];

		$editor = \Mockery::mock( 'WP_Image_Editor' );
		$editor->shouldReceive( 'resize' )->once()->with( 2560, 2560 )->andReturn( true );
		$editor->shouldReceive( 'save' )->once()->with( '/tmp/test.jpg' )->andReturn( true );

		Functions\when( 'getimagesize' )->justReturn( [ 5000, 4000 ] );
		Functions\when( 'wp_get_image_editor' )->justReturn( $editor );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$result = $this->base->resize_uploaded_image( $upload );
		$this->assertSame( $upload, $result );
	}

	public function test_skips_non_image_mime_type(): void {
		$upload = [
			'file' => '/tmp/test.pdf',
			'type' => 'application/pdf',
		];

		$result = $this->base->resize_uploaded_image( $upload );
		$this->assertSame( $upload, $result );
	}

	public function test_skips_when_file_key_missing(): void {
		$upload = [
			'type' => 'image/jpeg',
		];

		$result = $this->base->resize_uploaded_image( $upload );
		$this->assertSame( $upload, $result );
	}

	public function test_skips_when_type_key_missing(): void {
		$upload = [
			'file' => '/tmp/test.jpg',
		];

		$result = $this->base->resize_uploaded_image( $upload );
		$this->assertSame( $upload, $result );
	}

	public function test_skips_when_getimagesize_fails(): void {
		$upload = [
			'file' => '/tmp/test.jpg',
			'type' => 'image/jpeg',
		];

		Functions\when( 'getimagesize' )->justReturn( false );

		$result = $this->base->resize_uploaded_image( $upload );
		$this->assertSame( $upload, $result );
	}

	public function test_skips_when_image_editor_returns_error(): void {
		$upload = [
			'file' => '/tmp/test.jpg',
			'type' => 'image/jpeg',
		];

		Functions\when( 'getimagesize' )->justReturn( [ 5000, 4000 ] );
		Functions\when( 'wp_get_image_editor' )->justReturn( new \stdClass() );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$result = $this->base->resize_uploaded_image( $upload );
		$this->assertSame( $upload, $result );
	}

	public function test_handles_webp_images(): void {
		$upload = [
			'file' => '/tmp/test.webp',
			'type' => 'image/webp',
		];

		$editor = \Mockery::mock( 'WP_Image_Editor' );
		$editor->shouldReceive( 'resize' )->once();
		$editor->shouldReceive( 'save' )->once();

		Functions\when( 'getimagesize' )->justReturn( [ 5000, 3000 ] );
		Functions\when( 'wp_get_image_editor' )->justReturn( $editor );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$result = $this->base->resize_uploaded_image( $upload );
		$this->assertSame( $upload, $result );
	}

	public function test_handles_png_images(): void {
		$upload = [
			'file' => '/tmp/test.png',
			'type' => 'image/png',
		];

		Functions\when( 'getimagesize' )->justReturn( [ 800, 600 ] );

		$result = $this->base->resize_uploaded_image( $upload );
		$this->assertSame( $upload, $result );
	}

	public function test_handles_gif_images(): void {
		$upload = [
			'file' => '/tmp/test.gif',
			'type' => 'image/gif',
		];

		Functions\when( 'getimagesize' )->justReturn( [ 800, 600 ] );

		$result = $this->base->resize_uploaded_image( $upload );
		$this->assertSame( $upload, $result );
	}
}
