<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\ResizerTestCase;

/**
 * A crop tuple's anchor decides which part of the source survives.
 *
 * `fit( Fit::Crop, w, h )` already crops to the exact size around the centre,
 * so a `crop( w, h, $position )` after it had nothing left to cut: every
 * anchor returned the centre crop, byte for byte. The source here has three
 * bands of colour, so the surviving band names the anchor that was applied.
 * Runs the real encoder, because the defect lived in the encoder call order.
 */
class CropAnchorTest extends ResizerTestCase {

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();

		if ( ! extension_loaded( 'gd' ) ) {
			$this->markTestSkipped( 'GD is needed to write and read the images.' );
		}
		$this->dir = sys_get_temp_dir() . '/timber-kit-crop-anchor-' . getmypid();
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

	/**
	 * Three equal bands, red / green / blue, side by side or stacked.
	 */
	private function bands( bool $vertical ): string {
		$path   = $this->dir . ( $vertical ? '/stacked.png' : '/side.png' );
		$width  = $vertical ? 300 : 900;
		$height = $vertical ? 900 : 300;
		$image  = imagecreatetruecolor( $width, $height );
		$colors = array(
			(int) imagecolorallocate( $image, 255, 0, 0 ),
			(int) imagecolorallocate( $image, 0, 255, 0 ),
			(int) imagecolorallocate( $image, 0, 0, 255 ),
		);
		foreach ( $colors as $i => $color ) {
			if ( $vertical ) {
				imagefilledrectangle( $image, 0, $i * 300, 299, $i * 300 + 299, $color );
			} else {
				imagefilledrectangle( $image, $i * 300, 0, $i * 300 + 299, 299, $color );
			}
		}
		imagepng( $image, $path );

		return $path;
	}

	/**
	 * The colour channel that dominates the centre of the written file.
	 */
	private function dominantChannel( string $path ): string {
		$image = imagecreatefromstring( (string) file_get_contents( $path ) );
		$this->assertNotFalse( $image );
		// imagecolorsforindex, not bit shifts: Imagick writes a three-colour
		// image as a palette PNG, where imagecolorat returns an index.
		$color = imagecolorsforindex( $image, imagecolorat( $image, (int) ( imagesx( $image ) / 2 ), (int) ( imagesy( $image ) / 2 ) ) );
		$channels = array(
			'red'   => $color['red'],
			'green' => $color['green'],
			'blue'  => $color['blue'],
		);
		arsort( $channels );

		return (string) array_key_first( $channels );
	}

	/**
	 * @return array<string, array{string, bool, string}>
	 */
	public static function anchors(): array {
		return array(
			'left keeps the left band'      => array( 'left', false, 'red' ),
			'center keeps the middle band'  => array( 'center', false, 'green' ),
			'crop is the centre'            => array( 'crop', false, 'green' ),
			'right keeps the right band'    => array( 'right', false, 'blue' ),
			'top keeps the top band'        => array( 'top', true, 'red' ),
			'bottom keeps the bottom band'  => array( 'bottom', true, 'blue' ),
		);
	}

	#[DataProvider( 'anchors' )]
	public function testTheAnchorDecidesWhichPartSurvives( string $style, bool $stacked, string $expected ): void {
		$target = $this->dir . '/' . $style . '.png';

		$ok = $this->createResizer()->encodeVariant(
			array(
				'width'       => 300,
				'height'      => 300,
				'image_style' => $style,
				'quality'     => 90,
				'format'      => 'png',
			),
			$this->bands( $stacked ),
			$target
		);

		$this->assertTrue( $ok );
		$this->assertSame( array( 300, 300 ), array_slice( getimagesize( $target ) ?: array( 0, 0 ), 0, 2 ) );
		$this->assertSame( $expected, $this->dominantChannel( $target ) );
	}

	public function testAnUpscaledSourceIsStillCroppedAtTheAnchor(): void {
		// A source smaller than the target on one axis is scaled up to cover
		// before the crop, as Fit::Crop did. 900x300 into 600x600 covers at
		// 1800x600; the right third is blue.
		$target = $this->dir . '/up-right.png';

		$ok = $this->createResizer()->encodeVariant(
			array(
				'width'       => 600,
				'height'      => 600,
				'image_style' => 'right',
				'quality'     => 90,
				'format'      => 'png',
			),
			$this->bands( false ),
			$target
		);

		$this->assertTrue( $ok );
		$this->assertSame( array( 600, 600 ), array_slice( getimagesize( $target ) ?: array( 0, 0 ), 0, 2 ) );
		$this->assertSame( 'blue', $this->dominantChannel( $target ) );
	}
}
