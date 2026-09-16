<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Tests\Unit\ResizerTestCase;

/**
 * Encoding one variant to a caller-chosen path.
 *
 * `processVariant()` writes to the path the cache key dictates. The CLI
 * regenerator needs the same encode aimed at a temp file beside that path, so
 * the encode step is its own method. Runs the real encoder: the point of
 * reusing this method is that the bytes match what a render produces, and a
 * stub would assert nothing about that.
 */
class EncodeVariantTest extends ResizerTestCase {

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();

		if ( ! extension_loaded( 'gd' ) ) {
			$this->markTestSkipped( 'GD is needed to write the source image.' );
		}
		$this->dir = sys_get_temp_dir() . '/timber-kit-encode-' . getmypid();
		if ( ! is_dir( $this->dir ) ) {
			mkdir( $this->dir, 0777, true );
		}
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}
		parent::tearDown();
	}

	private function source(): string {
		$path = $this->dir . '/src.png';
		$image = imagecreatetruecolor( 800, 533 );
		imagefilledrectangle( $image, 0, 0, 800, 533, (int) imagecolorallocate( $image, 40, 120, 200 ) );
		imagepng( $image, $path );

		return $path;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function variant( string $style, int $width, int $height ): array {
		return array(
			'width'       => $width,
			'height'      => $height,
			'image_style' => $style,
			'quality'     => 80,
			'format'      => 'jpg',
		);
	}

	public function testItWritesTheVariantToThePathItIsGiven(): void {
		$target = $this->dir . '/anywhere-i-ask.jpg';

		$ok = $this->createResizer()->encodeVariant( $this->variant( 'center', 400, 0 ), $this->source(), $target );

		$this->assertTrue( $ok );
		$this->assertFileExists( $target );
		$this->assertSame( 400, (int) ( getimagesize( $target ) ?: array( 0 ) )[0] );
	}

	public function testItCropsToBothDimensionsWhenTheStyleIsPositional(): void {
		$target = $this->dir . '/cropped.jpg';

		$ok = $this->createResizer()->encodeVariant( $this->variant( 'top', 300, 300 ), $this->source(), $target );

		$this->assertTrue( $ok );
		$size = getimagesize( $target ) ?: array( 0, 0 );
		$this->assertSame( array( 300, 300 ), array( (int) $size[0], (int) $size[1] ) );
	}

	public function testAScaleOnlyVariantPinsBothAxesOfARealFixture(): void {
		// The encode was moved out of processVariant() with no equivalence
		// test, so this pins the geometry the move must not have changed: an
		// 800x533 source asked for width 400 derives its own height, and that
		// derived value is part of the contract a re-encode has to reproduce.
		$target = $this->dir . '/scaled.jpg';

		$ok = $this->createResizer()->encodeVariant( $this->variant( 'center', 400, 0 ), $this->source(), $target );

		$this->assertTrue( $ok );
		$size = getimagesize( $target ) ?: array( 0, 0 );
		$this->assertSame( array( 400, 267 ), array( (int) $size[0], (int) $size[1] ) );
	}

	public function testAnUnreadableSourceIsReportedAndWritesNoTarget(): void {
		$target = $this->dir . '/never-written.jpg';

		$ok = @$this->createResizer()->encodeVariant( $this->variant( 'center', 400, 0 ), $this->dir . '/missing.png', $target );

		$this->assertFalse( $ok );
		$this->assertFileDoesNotExist( $target );
	}
}
