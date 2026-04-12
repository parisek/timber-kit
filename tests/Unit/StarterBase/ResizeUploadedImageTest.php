<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

class ResizeUploadedImageTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;
	/** @var string[] */
	private array $temp_files = [];

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase( [
			'max_upload_width' => 2560,
			'max_upload_height' => 2560,
		] );
	}

	protected function tearDown(): void {
		foreach ( $this->temp_files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}

		parent::tearDown();
	}

	public function test_returns_unchanged_when_under_limit(): void {
		$upload = [
			'file' => $this->create_temp_image( 'jpg', 800, 600 ),
			'type' => 'image/jpeg',
		];

		$result = $this->base->resize_uploaded_image( $upload );
		$this->assertSame( $upload, $result );
	}

	public function test_resizes_when_width_exceeds_limit(): void {
		$upload = [
			'file' => $this->create_temp_image( 'jpg', 3000, 2000 ),
			'type' => 'image/jpeg',
		];

		$editor = \Mockery::mock( 'WP_Image_Editor' );
		$editor->shouldReceive( 'resize' )->once()->with( 2560, 2560 )->andReturn( true );
		$editor->shouldReceive( 'save' )->once()->with( $upload['file'] )->andReturn( true );

		Functions\when( 'wp_get_image_editor' )->justReturn( $editor );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$result = $this->base->resize_uploaded_image( $upload );
		$this->assertSame( $upload, $result );
	}

	public function test_resizes_when_height_exceeds_limit(): void {
		$upload = [
			'file' => $this->create_temp_image( 'jpg', 2000, 3000 ),
			'type' => 'image/jpeg',
		];

		$editor = \Mockery::mock( 'WP_Image_Editor' );
		$editor->shouldReceive( 'resize' )->once()->with( 2560, 2560 )->andReturn( true );
		$editor->shouldReceive( 'save' )->once()->with( $upload['file'] )->andReturn( true );

		Functions\when( 'wp_get_image_editor' )->justReturn( $editor );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$result = $this->base->resize_uploaded_image( $upload );
		$this->assertSame( $upload, $result );
	}

	public function test_resizes_when_both_dimensions_exceed_limit(): void {
		$upload = [
			'file' => $this->create_temp_image( 'jpg', 3000, 3000 ),
			'type' => 'image/jpeg',
		];

		$editor = \Mockery::mock( 'WP_Image_Editor' );
		$editor->shouldReceive( 'resize' )->once()->with( 2560, 2560 )->andReturn( true );
		$editor->shouldReceive( 'save' )->once()->with( $upload['file'] )->andReturn( true );

		Functions\when( 'wp_get_image_editor' )->justReturn( $editor );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$result = $this->base->resize_uploaded_image( $upload );
		$this->assertSame( $upload, $result );
	}

	public function test_skips_non_image_mime_type(): void {
		$upload = [
			'file' => tempnam( sys_get_temp_dir(), 'tk-pdf-' ),
			'type' => 'application/pdf',
		];
		$this->temp_files[] = $upload['file'];

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
			'file' => tempnam( sys_get_temp_dir(), 'tk-bad-' ),
			'type' => 'image/jpeg',
		];
		$this->temp_files[] = $upload['file'];
		file_put_contents( $upload['file'], 'not-an-image' );

		$result = $this->base->resize_uploaded_image( $upload );
		$this->assertSame( $upload, $result );
	}

	public function test_skips_when_image_editor_returns_error(): void {
		$upload = [
			'file' => $this->create_temp_image( 'jpg', 3000, 3000 ),
			'type' => 'image/jpeg',
		];

		Functions\when( 'wp_get_image_editor' )->justReturn( new \stdClass() );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$result = $this->base->resize_uploaded_image( $upload );
		$this->assertSame( $upload, $result );
	}

	public function test_handles_webp_images(): void {
		$upload = [
			'file' => $this->create_temp_image( 'webp', 3000, 2000 ),
			'type' => 'image/webp',
		];

		$editor = \Mockery::mock( 'WP_Image_Editor' );
		$editor->shouldReceive( 'resize' )->once();
		$editor->shouldReceive( 'save' )->once();

		Functions\when( 'wp_get_image_editor' )->justReturn( $editor );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$result = $this->base->resize_uploaded_image( $upload );
		$this->assertSame( $upload, $result );
	}

	public function test_handles_png_images(): void {
		$upload = [
			'file' => $this->create_temp_image( 'png', 800, 600 ),
			'type' => 'image/png',
		];

		$result = $this->base->resize_uploaded_image( $upload );
		$this->assertSame( $upload, $result );
	}

	public function test_handles_gif_images(): void {
		$upload = [
			'file' => $this->create_temp_image( 'gif', 800, 600 ),
			'type' => 'image/gif',
		];

		$result = $this->base->resize_uploaded_image( $upload );
		$this->assertSame( $upload, $result );
	}

	private function create_temp_image( string $extension, int $width, int $height ): string {
		$file = tempnam( sys_get_temp_dir(), 'tk-img-' );
		if ( false === $file ) {
			$this->fail( 'Failed to create temporary file.' );
		}

		$target = $file . '.' . $extension;
		rename( $file, $target );
		$this->temp_files[] = $target;

		$image = imagecreatetruecolor( $width, $height );
		if ( false === $image ) {
			$this->fail( 'Failed to create test image resource.' );
		}

		$background = imagecolorallocate( $image, 255, 255, 255 );
		imagefill( $image, 0, 0, $background );

		switch ( $extension ) {
			case 'jpg':
			case 'jpeg':
				imagejpeg( $image, $target );
				break;
			case 'png':
				imagepng( $image, $target );
				break;
			case 'gif':
				imagegif( $image, $target );
				break;
			case 'webp':
				if ( ! function_exists( 'imagewebp' ) ) {
					$this->markTestSkipped( 'GD WebP support is not available.' );
				}
				imagewebp( $image, $target );
				break;
			default:
				$this->fail( 'Unsupported image extension: ' . $extension );
		}

		return $target;
	}
}
