<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

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

		$result = Helpers::formatVideo( $video );

		// formatVideo unwraps the nested array from formatImage
		$this->assertIsArray( $result );
		$this->assertSame( 5, $result['id'] );
		$this->assertSame( 'https://example.com/video.mp4', $result['src'] );
		$this->assertSame( 'video/mp4', $result['type'] );
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
