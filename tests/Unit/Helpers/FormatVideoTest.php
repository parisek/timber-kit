<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

class FormatVideoTest extends HelpersTestCase {

	public function test_delegates_to_format_image_and_unwraps(): void {
		$video = [
			'ID'          => 5,
			'url'         => 'https://example.com/video.mp4',
			'mime_type'   => 'video/mp4',
			'width'       => 1920,
			'height'      => 1080,
			'alt'         => '',
			'caption'     => '',
			'description' => '',
		];

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 5, '_timber_kit_video_codecs', true )
			->andReturn( 'none' );
		Functions\expect( 'get_attached_file' )->never();
		Functions\expect( 'update_post_meta' )->never();

		$result = Helpers::formatVideo( $video );

		// formatVideo unwraps the nested array from formatImage
		$this->assertIsArray( $result );
		$this->assertSame( 5, $result['id'] );
		$this->assertSame( 'https://example.com/video.mp4', $result['src'] );
		$this->assertSame( 'video/mp4', $result['type'] );
		$this->assertArrayHasKey( 'codecs', $result );
		$this->assertNull( $result['codecs'] );
	}

	public function test_video_includes_av1_codecs_after_unwrap(): void {
		$video = [
			'ID'          => 15,
			'url'         => 'https://example.com/video-av1.mp4',
			'mime_type'   => 'video/mp4',
			'width'       => 1920,
			'height'      => 1080,
			'alt'         => '',
			'caption'     => '',
			'description' => '',
		];

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 15, '_timber_kit_video_codecs', true )
			->andReturn( '' );
		Functions\expect( 'get_attached_file' )
			->once()
			->with( 15 )
			->andReturn( dirname( __DIR__, 2 ) . '/Fixtures/video/av1-8bit.mp4' );
		Functions\expect( 'update_post_meta' )
			->once()
			->with( 15, '_timber_kit_video_codecs', 'av01.0.00M.08' );

		$result = Helpers::formatVideo( $video );

		$this->assertIsArray( $result );
		$this->assertSame( 'av01.0.00M.08', $result['codecs'] );
	}

	public function test_object_input(): void {
		$video = (object) [
			'ID'             => 5,
			'src'            => 'https://example.com/video.mp4',
			'post_mime_type' => 'video/mp4',
			'width'          => 1920,
			'height'         => 1080,
			'alt'            => '',
			'caption'        => '',
			'description'    => '',
		];

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 5, '_timber_kit_video_codecs', true )
			->andReturn( 'none' );
		Functions\expect( 'get_attached_file' )->never();
		Functions\expect( 'update_post_meta' )->never();

		$result = Helpers::formatVideo( $video );

		$this->assertIsArray( $result );
		$this->assertSame( 5, $result['id'] );
	}

	public function test_null_returns_falsy(): void {
		$result = Helpers::formatVideo( null );
		// reset() on empty array returns false
		$this->assertEmpty( $result );
	}

	public function test_false_returns_falsy(): void {
		$result = Helpers::formatVideo( false );
		$this->assertEmpty( $result );
	}
}
