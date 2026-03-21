<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Tests\Unit\ResizerTestCase;

class PrepareDefaultImageTest extends ResizerTestCase {

	public function test_full_image(): void {
		$resizer = $this->createResizer();

		$image = [
			'src'         => 'https://example.com/image.jpg',
			'width'       => 1200,
			'height'      => 800,
			'alt'         => 'Alt text',
			'caption'     => 'Caption text',
			'description' => 'Description text',
		];

		$result = $this->callPrivate( $resizer, 'prepareDefaultImage', [ $image ] );

		$this->assertSame( 'https://example.com/image.jpg', $result['src'] );
		$this->assertSame( 1200, $result['width'] );
		$this->assertSame( 800, $result['height'] );
		$this->assertSame( 'Alt text', $result['alt'] );
		$this->assertSame( 'Caption text', $result['caption'] );
		$this->assertSame( 'Description text', $result['description'] );
	}

	public function test_missing_optional_keys(): void {
		$resizer = $this->createResizer();

		$image = [
			'src' => 'https://example.com/image.jpg',
		];

		$result = $this->callPrivate( $resizer, 'prepareDefaultImage', [ $image ] );

		$this->assertSame( 'https://example.com/image.jpg', $result['src'] );
		$this->assertSame( '', $result['width'] );
		$this->assertSame( '', $result['height'] );
		$this->assertSame( '', $result['alt'] );
		$this->assertSame( '', $result['caption'] );
		$this->assertSame( '', $result['description'] );
	}

	public function test_preserves_src(): void {
		$resizer = $this->createResizer();

		$url = 'https://example.com/uploads/2024/photo-with-spaces.jpg';
		$result = $this->callPrivate( $resizer, 'prepareDefaultImage', [
			[ 'src' => $url ],
		] );

		$this->assertSame( $url, $result['src'] );
	}
}
