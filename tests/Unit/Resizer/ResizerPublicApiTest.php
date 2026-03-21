<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Resizer;
use Tests\Unit\ResizerTestCase;

class ResizerPublicApiTest extends ResizerTestCase {

	public function test_empty_variants_returns_empty(): void {
		$resizer = $this->createResizer();

		$result = $resizer->resizer( [ 'src' => 'https://example.com/image.jpg' ], [] );

		$this->assertSame( [], $result );
	}

	public function test_missing_src_returns_empty(): void {
		$resizer = $this->createResizer();

		$result = $resizer->resizer( [ 'id' => 1 ], [ [ '800', '600' ] ] );

		$this->assertSame( [], $result );
	}

	public function test_empty_src_returns_empty(): void {
		$resizer = $this->createResizer();

		$result = $resizer->resizer( [ 'src' => '' ], [ [ '800', '600' ] ] );

		$this->assertSame( [], $result );
	}

	public function test_multi_image_uses_last(): void {
		$resizer = $this->createResizer();

		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/svg+xml', 'ext' => 'svg' ] );

		$images = [
			[ 'src' => 'https://example.com/first.svg', 'alt' => 'First' ],
			[ 'src' => 'https://example.com/last.svg', 'alt' => 'Last' ],
		];

		$result = $resizer->resizer( $images, [ [ '800', '600' ] ] );

		// SVG not allowed → returns [default_image] using last image
		$this->assertCount( 1, $result );
		$this->assertSame( 'https://example.com/last.svg', $result[0]['src'] );
		$this->assertSame( 'Last', $result[0]['alt'] );
	}

	public function test_disallowed_type_returns_default(): void {
		$resizer = $this->createResizer();

		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/svg+xml', 'ext' => 'svg' ] );

		$image = [
			'src'    => 'https://example.com/icon.svg',
			'width'  => 100,
			'height' => 100,
			'alt'    => 'Icon',
		];

		$result = $resizer->resizer( $image, [ [ '800', '600' ] ] );

		$this->assertCount( 1, $result );
		$this->assertSame( 'https://example.com/icon.svg', $result[0]['src'] );
		$this->assertSame( 100, $result[0]['width'] );
		$this->assertSame( 'Icon', $result[0]['alt'] );
	}

	public function test_source_not_found_returns_default(): void {
		$resizer = $this->createResizer();

		Functions\when( 'wp_check_filetype' )->justReturn( [ 'type' => 'image/jpeg', 'ext' => 'jpg' ] );
		Functions\when( 'wp_upload_dir' )->justReturn( [
			'basedir' => '/var/www/html/wp-content/uploads',
			'baseurl' => 'https://example.com/wp-content/uploads',
		] );
		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			return $name;
		} );

		\Patchwork\redefine( 'file_exists', function ( string $path ) {
			return false;
		} );

		$image = [
			'src'    => 'https://example.com/wp-content/uploads/photo.jpg',
			'width'  => 1200,
			'height' => 800,
			'alt'    => 'Photo',
		];

		$result = $resizer->resizer( $image, [ [ '800', '600', '768', 'crop' ] ] );

		// Source file not found → returns [default_image]
		$this->assertCount( 1, $result );
		$this->assertSame( 'https://example.com/wp-content/uploads/photo.jpg', $result[0]['src'] );

		\Patchwork\restoreAll();
	}

	public function test_cached_variant_skips_processing(): void {
		$resizer = $this->createResizer();

		Functions\when( 'wp_check_filetype' )->alias( function ( $path ) {
			if ( str_contains( $path, '.avif' ) ) {
				return [ 'type' => 'image/avif', 'ext' => 'avif' ];
			}
			return [ 'type' => 'image/jpeg', 'ext' => 'jpg' ];
		} );
		Functions\when( 'wp_upload_dir' )->justReturn( [
			'basedir' => '/var/www/html/wp-content/uploads',
			'baseurl' => 'https://example.com/wp-content/uploads',
		] );
		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			return $name;
		} );
		Functions\when( 'content_url' )->alias( function ( $path ) {
			return 'https://example.com/wp-content/' . $path;
		} );

		// All file_exists calls return true (source exists, cached variant exists)
		\Patchwork\redefine( 'file_exists', function ( string $path ) {
			return true;
		} );

		$image = [
			'src'         => 'https://example.com/wp-content/uploads/photo.jpg',
			'width'       => 1200,
			'height'      => 800,
			'alt'         => 'Photo',
			'caption'     => 'Nice photo',
			'description' => '',
		];

		$result = $resizer->resizer( $image, [ [ '800', '600', '768', 'crop' ] ] );

		// Should return variant + fallback = 2 items
		$this->assertCount( 2, $result );

		// First item = processed variant
		$this->assertStringContainsString( 'cache/image/800x600-crop', $result[0]['src'] );
		$this->assertSame( 800, $result[0]['width'] );
		$this->assertSame( 600, $result[0]['height'] );
		$this->assertSame( '(min-width: 768px)', $result[0]['media'] );
		$this->assertSame( 'Photo', $result[0]['alt'] );

		// Last item = fallback default
		$this->assertSame( 'https://example.com/wp-content/uploads/photo.jpg', $result[1]['src'] );

		\Patchwork\restoreAll();
	}

	public function test_fallback_always_last(): void {
		$resizer = $this->createResizer();

		Functions\when( 'wp_check_filetype' )->alias( function ( $path ) {
			if ( str_contains( $path, '.avif' ) ) {
				return [ 'type' => 'image/avif', 'ext' => 'avif' ];
			}
			return [ 'type' => 'image/jpeg', 'ext' => 'jpg' ];
		} );
		Functions\when( 'wp_upload_dir' )->justReturn( [
			'basedir' => '/var/www/html/wp-content/uploads',
			'baseurl' => 'https://example.com/wp-content/uploads',
		] );
		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			return $name;
		} );
		Functions\when( 'content_url' )->alias( function ( $path ) {
			return 'https://example.com/wp-content/' . $path;
		} );

		\Patchwork\redefine( 'file_exists', function ( string $path ) {
			return true;
		} );

		$image = [
			'src'    => 'https://example.com/wp-content/uploads/photo.jpg',
			'width'  => 1200,
			'height' => 800,
			'alt'    => 'Photo',
		];

		$result = $resizer->resizer( $image, [
			[ '1680', '1260', '768', 'crop' ],
			[ '800', '600', '512', 'crop' ],
			[ '400', '300', '320', 'crop' ],
		] );

		// 3 variants + 1 fallback = 4
		$this->assertCount( 4, $result );
		// Last item is always the fallback (original image)
		$last = end( $result );
		$this->assertSame( 'https://example.com/wp-content/uploads/photo.jpg', $last['src'] );

		\Patchwork\restoreAll();
	}

	public function test_variant_media_format(): void {
		$resizer = $this->createResizer();

		Functions\when( 'wp_check_filetype' )->alias( function ( $path ) {
			if ( str_contains( $path, '.avif' ) ) {
				return [ 'type' => 'image/avif', 'ext' => 'avif' ];
			}
			return [ 'type' => 'image/jpeg', 'ext' => 'jpg' ];
		} );
		Functions\when( 'wp_upload_dir' )->justReturn( [
			'basedir' => '/var/www/html/wp-content/uploads',
			'baseurl' => 'https://example.com/wp-content/uploads',
		] );
		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			return $name;
		} );
		Functions\when( 'content_url' )->alias( function ( $path ) {
			return 'https://example.com/wp-content/' . $path;
		} );

		\Patchwork\redefine( 'file_exists', function ( string $path ) {
			return true;
		} );

		$image = [
			'src'    => 'https://example.com/wp-content/uploads/photo.jpg',
			'width'  => 1200,
			'height' => 800,
			'alt'    => '',
		];

		$result = $resizer->resizer( $image, [ [ '800', '600', '768', 'crop' ] ] );

		$this->assertSame( '(min-width: 768px)', $result[0]['media'] );

		\Patchwork\restoreAll();
	}

	public function test_variant_empty_media(): void {
		$resizer = $this->createResizer();

		Functions\when( 'wp_check_filetype' )->alias( function ( $path ) {
			if ( str_contains( $path, '.avif' ) ) {
				return [ 'type' => 'image/avif', 'ext' => 'avif' ];
			}
			return [ 'type' => 'image/jpeg', 'ext' => 'jpg' ];
		} );
		Functions\when( 'wp_upload_dir' )->justReturn( [
			'basedir' => '/var/www/html/wp-content/uploads',
			'baseurl' => 'https://example.com/wp-content/uploads',
		] );
		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			return $name;
		} );
		Functions\when( 'content_url' )->alias( function ( $path ) {
			return 'https://example.com/wp-content/' . $path;
		} );

		\Patchwork\redefine( 'file_exists', function ( string $path ) {
			return true;
		} );

		$image = [
			'src'    => 'https://example.com/wp-content/uploads/photo.jpg',
			'width'  => 1200,
			'height' => 800,
			'alt'    => '',
		];

		$result = $resizer->resizer( $image, [ [ '800', '600', '', 'crop' ] ] );

		// No media query when media is empty/0
		$this->assertSame( '', $result[0]['media'] );

		\Patchwork\restoreAll();
	}

	public function test_variant_output_includes_type(): void {
		$resizer = $this->createResizer();

		Functions\when( 'wp_check_filetype' )->alias( function ( $path ) {
			if ( str_contains( $path, '.avif' ) ) {
				return [ 'type' => 'image/avif', 'ext' => 'avif' ];
			}
			return [ 'type' => 'image/jpeg', 'ext' => 'jpg' ];
		} );
		Functions\when( 'wp_upload_dir' )->justReturn( [
			'basedir' => '/var/www/html/wp-content/uploads',
			'baseurl' => 'https://example.com/wp-content/uploads',
		] );
		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			return $name;
		} );
		Functions\when( 'content_url' )->alias( function ( $path ) {
			return 'https://example.com/wp-content/' . $path;
		} );

		\Patchwork\redefine( 'file_exists', function ( string $path ) {
			return true;
		} );

		$image = [
			'src'    => 'https://example.com/wp-content/uploads/photo.jpg',
			'width'  => 1200,
			'height' => 800,
			'alt'    => 'Photo',
		];

		$result = $resizer->resizer( $image, [ [ '800', '600', '768', 'crop' ] ] );

		// Variant should have 'type' key with MIME from wp_check_filetype on target path
		$this->assertArrayHasKey( 'type', $result[0] );
		$this->assertSame( 'image/avif', $result[0]['type'] );

		// Fallback (default_image) should NOT have 'type' key
		$this->assertArrayNotHasKey( 'type', $result[1] );

		\Patchwork\restoreAll();
	}

	public function test_caption_description_propagated(): void {
		$resizer = $this->createResizer();

		Functions\when( 'wp_check_filetype' )->alias( function ( $path ) {
			if ( str_contains( $path, '.avif' ) ) {
				return [ 'type' => 'image/avif', 'ext' => 'avif' ];
			}
			return [ 'type' => 'image/jpeg', 'ext' => 'jpg' ];
		} );
		Functions\when( 'wp_upload_dir' )->justReturn( [
			'basedir' => '/var/www/html/wp-content/uploads',
			'baseurl' => 'https://example.com/wp-content/uploads',
		] );
		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			return $name;
		} );
		Functions\when( 'content_url' )->alias( function ( $path ) {
			return 'https://example.com/wp-content/' . $path;
		} );

		\Patchwork\redefine( 'file_exists', function ( string $path ) {
			return true;
		} );

		$image = [
			'src'         => 'https://example.com/wp-content/uploads/photo.jpg',
			'width'       => 1200,
			'height'      => 800,
			'alt'         => 'Alt text',
			'caption'     => 'My caption',
			'description' => 'My description',
		];

		$result = $resizer->resizer( $image, [ [ '800', '600', '768', 'crop' ] ] );

		// Variant inherits metadata from default_image
		$this->assertSame( 'My caption', $result[0]['caption'] );
		$this->assertSame( 'My description', $result[0]['description'] );
		$this->assertSame( 'Alt text', $result[0]['alt'] );

		// Fallback also has them
		$this->assertSame( 'My caption', $result[1]['caption'] );
		$this->assertSame( 'My description', $result[1]['description'] );

		\Patchwork\restoreAll();
	}

	public function test_multiple_variants_ordered_by_media_desc(): void {
		$resizer = $this->createResizer();

		Functions\when( 'wp_check_filetype' )->alias( function ( $path ) {
			if ( str_contains( $path, '.avif' ) ) {
				return [ 'type' => 'image/avif', 'ext' => 'avif' ];
			}
			return [ 'type' => 'image/jpeg', 'ext' => 'jpg' ];
		} );
		Functions\when( 'wp_upload_dir' )->justReturn( [
			'basedir' => '/var/www/html/wp-content/uploads',
			'baseurl' => 'https://example.com/wp-content/uploads',
		] );
		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			return $name;
		} );
		Functions\when( 'content_url' )->alias( function ( $path ) {
			return 'https://example.com/wp-content/' . $path;
		} );

		\Patchwork\redefine( 'file_exists', function ( string $path ) {
			return true;
		} );

		$image = [
			'src'    => 'https://example.com/wp-content/uploads/photo.jpg',
			'width'  => 1200,
			'height' => 800,
			'alt'    => '',
		];

		// Pass variants in non-sorted order
		$result = $resizer->resizer( $image, [
			[ '400', '300', '320', 'crop' ],
			[ '1680', '1260', '1024', 'crop' ],
			[ '800', '600', '768', 'crop' ],
		] );

		// 3 variants + 1 fallback = 4
		$this->assertCount( 4, $result );

		// Variants sorted by media descending
		$this->assertSame( '(min-width: 1024px)', $result[0]['media'] );
		$this->assertSame( 1680, $result[0]['width'] );
		$this->assertSame( '(min-width: 768px)', $result[1]['media'] );
		$this->assertSame( 800, $result[1]['width'] );
		$this->assertSame( '(min-width: 320px)', $result[2]['media'] );
		$this->assertSame( 400, $result[2]['width'] );

		// Fallback always last
		$this->assertSame( 'https://example.com/wp-content/uploads/photo.jpg', $result[3]['src'] );

		\Patchwork\restoreAll();
	}

	public function test_gif_source_allowed_and_processed(): void {
		$resizer = $this->createResizer();

		Functions\when( 'wp_check_filetype' )->alias( function ( $path ) {
			if ( str_contains( $path, '.avif' ) ) {
				return [ 'type' => 'image/avif', 'ext' => 'avif' ];
			}
			if ( str_contains( $path, '.gif' ) ) {
				return [ 'type' => 'image/gif', 'ext' => 'gif' ];
			}
			return [ 'type' => 'image/jpeg', 'ext' => 'jpg' ];
		} );
		Functions\when( 'wp_upload_dir' )->justReturn( [
			'basedir' => '/var/www/html/wp-content/uploads',
			'baseurl' => 'https://example.com/wp-content/uploads',
		] );
		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			return $name;
		} );
		Functions\when( 'content_url' )->alias( function ( $path ) {
			return 'https://example.com/wp-content/' . $path;
		} );

		\Patchwork\redefine( 'file_exists', function ( string $path ) {
			return true;
		} );

		$image = [
			'src'    => 'https://example.com/wp-content/uploads/animation.gif',
			'width'  => 800,
			'height' => 600,
			'alt'    => 'Animated',
		];

		$result = $resizer->resizer( $image, [ [ '400', '300', '768', 'crop' ] ] );

		// GIF is in the allowed list, so should produce variant + fallback
		$this->assertCount( 2, $result );
		$this->assertStringContainsString( 'cache/image/400x300-crop', $result[0]['src'] );
		$this->assertSame( 'https://example.com/wp-content/uploads/animation.gif', $result[1]['src'] );

		\Patchwork\restoreAll();
	}

	public function test_png_source_allowed(): void {
		$resizer = $this->createResizer();

		Functions\when( 'wp_check_filetype' )->alias( function ( $path ) {
			if ( str_contains( $path, '.avif' ) ) {
				return [ 'type' => 'image/avif', 'ext' => 'avif' ];
			}
			if ( str_contains( $path, '.png' ) ) {
				return [ 'type' => 'image/png', 'ext' => 'png' ];
			}
			return [ 'type' => 'image/jpeg', 'ext' => 'jpg' ];
		} );
		Functions\when( 'wp_upload_dir' )->justReturn( [
			'basedir' => '/var/www/html/wp-content/uploads',
			'baseurl' => 'https://example.com/wp-content/uploads',
		] );
		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			return $name;
		} );
		Functions\when( 'content_url' )->alias( function ( $path ) {
			return 'https://example.com/wp-content/' . $path;
		} );

		\Patchwork\redefine( 'file_exists', function ( string $path ) {
			return true;
		} );

		$image = [
			'src'    => 'https://example.com/wp-content/uploads/logo.png',
			'width'  => 500,
			'height' => 200,
			'alt'    => 'Logo',
		];

		$result = $resizer->resizer( $image, [ [ '250', '100', '', 'crop' ] ] );

		$this->assertCount( 2, $result );
		$this->assertSame( 250, $result[0]['width'] );
		$this->assertSame( 100, $result[0]['height'] );

		\Patchwork\restoreAll();
	}

	public function test_custom_cache_dir_reflected_in_url(): void {
		// Create resizer with custom cache dir via filter
		Functions\when( 'apply_filters' )->alias( function ( $filter, $default ) {
			if ( $filter === 'timber_kit_resizer_image_cache_dir' ) {
				return '/tmp/wp-content/custom/resized';
			}
			return $default;
		} );
		$resizer = new Resizer();

		Functions\when( 'wp_check_filetype' )->alias( function ( $path ) {
			if ( str_contains( $path, '.avif' ) ) {
				return [ 'type' => 'image/avif', 'ext' => 'avif' ];
			}
			return [ 'type' => 'image/jpeg', 'ext' => 'jpg' ];
		} );
		Functions\when( 'wp_upload_dir' )->justReturn( [
			'basedir' => '/tmp/wp-content/uploads',
			'baseurl' => 'https://example.com/wp-content/uploads',
		] );
		Functions\when( 'sanitize_file_name' )->alias( function ( $name ) {
			return $name;
		} );
		Functions\when( 'content_url' )->alias( function ( $path ) {
			return 'https://example.com/wp-content/' . $path;
		} );

		\Patchwork\redefine( 'file_exists', function ( string $path ) {
			return true;
		} );

		$image = [
			'src'         => 'https://example.com/wp-content/uploads/photo.jpg',
			'width'       => 1200,
			'height'      => 800,
			'alt'         => 'Photo',
			'caption'     => '',
			'description' => '',
		];

		$result = $resizer->resizer( $image, [ [ '800', '600', '768', 'crop' ] ] );

		$this->assertCount( 2, $result );
		// URL should use the custom cache dir, not hardcoded 'cache/image/'
		$this->assertStringContainsString( 'custom/resized/800x600-crop', $result[0]['src'] );
		$this->assertStringNotContainsString( 'cache/image/', $result[0]['src'] );

		\Patchwork\restoreAll();
	}
}
