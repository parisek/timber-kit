<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

class FormatImageTest extends HelpersTestCase {

	public function test_array_input(): void {
		$image = [
			'ID'          => 42,
			'url'         => 'https://example.com/image.jpg',
			'mime_type'   => 'image/jpeg',
			'width'       => 800,
			'height'      => 600,
			'alt'         => 'Test image',
			'caption'     => 'A caption',
			'description' => 'A description',
		];

		$result = Helpers::formatImage( $image );

		$this->assertCount( 1, $result );
		$this->assertSame( 42, $result[0]['id'] );
		$this->assertSame( 'https://example.com/image.jpg', $result[0]['src'] );
		$this->assertSame( 'image/jpeg', $result[0]['type'] );
		$this->assertSame( 800, $result[0]['width'] );
		$this->assertSame( 600, $result[0]['height'] );
		$this->assertSame( 'Test image', $result[0]['alt'] );
		$this->assertSame( 'A caption', $result[0]['caption'] );
		$this->assertSame( 'A description', $result[0]['description'] );
	}

	public function test_object_input(): void {
		$image = (object) [
			'ID'             => 42,
			'src'            => 'https://example.com/image.jpg',
			'post_mime_type' => 'image/jpeg',
			'width'          => 800,
			'height'         => 600,
			'alt'            => 'Test image',
			'caption'        => 'A caption',
			'description'    => 'A description',
		];

		$result = Helpers::formatImage( $image );

		$this->assertCount( 1, $result );
		$this->assertSame( 42, $result[0]['id'] );
		$this->assertSame( 'https://example.com/image.jpg', $result[0]['src'] );
		$this->assertSame( 'image/jpeg', $result[0]['type'] );
	}

	public function test_svg_1px_width_fix(): void {
		$image = [
			'ID'          => 1,
			'url'         => 'https://example.com/icon.svg',
			'mime_type'   => 'image/svg+xml',
			'width'       => 1,
			'height'      => 1,
			'alt'         => '',
			'caption'     => '',
			'description' => '',
		];

		$result = Helpers::formatImage( $image );

		$this->assertNull( $result[0]['width'] );
		$this->assertNull( $result[0]['height'] );
	}

	public function test_svg_1px_width_fix_object(): void {
		$image = (object) [
			'ID'             => 1,
			'src'            => 'https://example.com/icon.svg',
			'post_mime_type' => 'image/svg+xml',
			'width'          => 1,
			'height'         => 1,
			'alt'            => '',
			'caption'        => '',
			'description'    => '',
		];

		$result = Helpers::formatImage( $image );

		$this->assertNull( $result[0]['width'] );
		$this->assertNull( $result[0]['height'] );
	}

	public function test_normal_dimensions_preserved(): void {
		$image = [
			'ID'          => 1,
			'url'         => 'https://example.com/photo.jpg',
			'mime_type'   => 'image/jpeg',
			'width'       => 1920,
			'height'      => 1080,
			'alt'         => '',
			'caption'     => '',
			'description' => '',
		];

		$result = Helpers::formatImage( $image );

		$this->assertSame( 1920, $result[0]['width'] );
		$this->assertSame( 1080, $result[0]['height'] );
	}

	public function test_multi_value_gallery(): void {
		$images = [
			[
				'ID'          => 1,
				'url'         => 'https://example.com/a.jpg',
				'mime_type'   => 'image/jpeg',
				'width'       => 100,
				'height'      => 100,
				'alt'         => 'A',
				'caption'     => '',
				'description' => '',
			],
			[
				'ID'          => 2,
				'url'         => 'https://example.com/b.jpg',
				'mime_type'   => 'image/jpeg',
				'width'       => 200,
				'height'      => 200,
				'alt'         => 'B',
				'caption'     => '',
				'description' => '',
			],
		];

		$result = Helpers::formatImage( $images );

		$this->assertCount( 2, $result );
		// each item is unwrapped from the nested array returned by formatImage
		$this->assertSame( 1, $result[0][0]['id'] );
		$this->assertSame( 2, $result[1][0]['id'] );
	}

	public function test_empty_array_returns_empty(): void {
		$result = Helpers::formatImage( [] );
		$this->assertSame( [], $result );
	}

	public function test_false_input_returns_empty(): void {
		$result = Helpers::formatImage( false );
		$this->assertSame( [], $result );
	}

	public function test_null_input_returns_empty(): void {
		$result = Helpers::formatImage( null );
		$this->assertSame( [], $result );
	}

	// --- Iteration 2: WP-dependent paths ---

	public function test_numeric_id_input(): void {
		Functions\when( 'acf_get_attachment' )->alias( function ( $id ) {
			if ( $id === 42 ) {
				return [
					'ID'          => 42,
					'url'         => 'https://example.com/image.jpg',
					'mime_type'   => 'image/jpeg',
					'width'       => 800,
					'height'      => 600,
					'alt'         => 'Alt text',
					'caption'     => 'Caption',
					'description' => 'Desc',
				];
			}
			return false;
		} );

		$result = Helpers::formatImage( 42 );

		$this->assertCount( 1, $result );
		$this->assertSame( 42, $result[0]['id'] );
		$this->assertSame( 'https://example.com/image.jpg', $result[0]['src'] );
	}

	public function test_numeric_id_not_found_returns_empty(): void {
		Functions\when( 'acf_get_attachment' )->justReturn( false );

		$result = Helpers::formatImage( 999 );

		$this->assertSame( [], $result );
	}

	public function test_url_input(): void {
		Functions\when( 'attachment_url_to_postid' )->justReturn( 42 );
		Functions\when( 'acf_get_attachment' )->alias( function ( $id ) {
			if ( $id === 42 ) {
				return [
					'ID'          => 42,
					'url'         => 'https://example.com/image.jpg',
					'mime_type'   => 'image/jpeg',
					'width'       => 800,
					'height'      => 600,
					'alt'         => 'Alt',
					'caption'     => '',
					'description' => '',
				];
			}
			return false;
		} );

		$result = Helpers::formatImage( 'https://example.com/image.jpg' );

		$this->assertCount( 1, $result );
		$this->assertSame( 42, $result[0]['id'] );
	}

	public function test_url_input_not_found_returns_empty(): void {
		Functions\when( 'attachment_url_to_postid' )->justReturn( 0 );
		Functions\when( 'acf_get_attachment' )->justReturn( false );

		$result = Helpers::formatImage( 'https://example.com/nonexistent.jpg' );

		$this->assertSame( [], $result );
	}
}
