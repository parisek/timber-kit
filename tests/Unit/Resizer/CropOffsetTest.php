<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Parisek\TimberKit\Resizer;
use Tests\Unit\ResizerTestCase;

/**
 * Covers cropOffset(): the per-frame positional-crop pixel-offset math used by
 * the animated multi-frame path. Pure arithmetic — no Imagick backend.
 */
class CropOffsetTest extends ResizerTestCase {

	/**
	 * @return array{x:int,y:int}
	 */
	private function offset( string $style, int $sw, int $sh, int $w, int $h ): array {
		return $this->callPrivate( $this->createResizer(), 'cropOffset', [ $style, $sw, $sh, $w, $h ] );
	}

	public function test_center_horizontal_overflow(): void {
		// Scaled 200x100, target 100x100 → centered x = (200-100)/2 = 50, y = 0.
		$this->assertSame( [ 'x' => 50, 'y' => 0 ], $this->offset( 'center', 200, 100, 100, 100 ) );
	}

	public function test_center_vertical_overflow(): void {
		$this->assertSame( [ 'x' => 0, 'y' => 25 ], $this->offset( 'center', 100, 150, 100, 100 ) );
	}

	public function test_top_pins_y_zero(): void {
		$this->assertSame( [ 'x' => 0, 'y' => 0 ], $this->offset( 'top', 100, 150, 100, 100 ) );
	}

	public function test_bottom_pins_y_to_far_edge(): void {
		$this->assertSame( [ 'x' => 0, 'y' => 50 ], $this->offset( 'bottom', 100, 150, 100, 100 ) );
	}

	public function test_left_pins_x_zero(): void {
		$this->assertSame( [ 'x' => 0, 'y' => 0 ], $this->offset( 'left', 200, 100, 100, 100 ) );
	}

	public function test_right_pins_x_to_far_edge(): void {
		$this->assertSame( [ 'x' => 100, 'y' => 0 ], $this->offset( 'right', 200, 100, 100, 100 ) );
	}

	public function test_crop_alias_behaves_as_center(): void {
		$this->assertSame( [ 'x' => 50, 'y' => 0 ], $this->offset( 'crop', 200, 100, 100, 100 ) );
	}

	public function test_unknown_style_falls_back_to_center(): void {
		$this->assertSame( [ 'x' => 50, 'y' => 0 ], $this->offset( 'smart-crop', 200, 100, 100, 100 ) );
	}

	public function test_no_overflow_is_zero_offset(): void {
		$this->assertSame( [ 'x' => 0, 'y' => 0 ], $this->offset( 'center', 100, 100, 100, 100 ) );
	}
}
