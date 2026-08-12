<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Parisek\TimberKit\Resizer;
use PHPUnit\Framework\TestCase;

/**
 * The dimensions a variant actually gets, as opposed to the ones it asked for.
 *
 * Every expectation here was measured against the real encoder before it was
 * written — a 4000x2250 source through each branch, with getimagesize() on the
 * result. Deriving them instead of measuring at runtime is what keeps this free:
 * the source dimensions are already in the image array.
 */
class ProducedDimensionsTest extends TestCase {

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function variant( array $overrides = [] ): array {
		return array_merge(
			[ 'width' => 0, 'height' => 0, 'media' => 0, 'image_style' => 'center', 'quality' => 100, 'format' => 'avif' ],
			$overrides
		);
	}

	public function test_an_exact_crop_gets_what_it_asked_for(): void {
		$result = Resizer::producedDimensions( $this->variant( [ 'width' => 800, 'height' => 600 ] ), 4000, 2250 );

		$this->assertSame( [ 800, 600 ], $result );
	}

	public function test_width_only_derives_the_height_from_the_source_ratio(): void {
		// Measured: 600 wide off a 4000x2250 source produces 600x338.
		$result = Resizer::producedDimensions( $this->variant( [ 'width' => 600 ] ), 4000, 2250 );

		$this->assertSame( [ 600, 338 ], $result );
	}

	public function test_height_only_derives_the_width_from_the_source_ratio(): void {
		// Measured: 400 tall off the same source produces 711x400.
		$result = Resizer::producedDimensions( $this->variant( [ 'height' => 400 ] ), 4000, 2250 );

		$this->assertSame( [ 711, 400 ], $result );
	}

	public function test_the_derived_dimension_is_rounded_not_truncated(): void {
		// 2250 * 600 / 4000 is 337.5, and the encoder writes 338. Truncating
		// would be off by one on every second image.
		$this->assertSame( 338, Resizer::producedDimensions( $this->variant( [ 'width' => 600 ] ), 4000, 2250 )[1] );
	}

	public function test_upscaling_is_reported_as_upscaled(): void {
		// The resizer does not cap at the source size: 6000 off a 4000 source
		// really does write 6000x3375.
		$result = Resizer::producedDimensions( $this->variant( [ 'width' => 6000 ] ), 4000, 2250 );

		$this->assertSame( [ 6000, 3375 ], $result );
	}

	public function test_a_non_cropping_style_with_both_dimensions_lets_the_height_win(): void {
		// width() then height(), so the last call decides the ratio: measured
		// 800x800 in a 'scale' style produces 1422x800.
		$result = Resizer::producedDimensions( $this->variant( [ 'width' => 800, 'height' => 800, 'image_style' => 'scale' ] ), 4000, 2250 );

		$this->assertSame( [ 1422, 800 ], $result );
	}

	public function test_no_dimensions_at_all_is_the_source_re_encoded(): void {
		$result = Resizer::producedDimensions( $this->variant(), 4000, 2250 );

		$this->assertSame( [ 4000, 2250 ], $result );
	}

	public function test_smart_crop_on_a_larger_source_crops_exactly(): void {
		$result = Resizer::producedDimensions( $this->variant( [ 'width' => 800, 'height' => 600, 'image_style' => 'smart-crop' ] ), 4000, 2250 );

		$this->assertSame( [ 800, 600 ], $result );
	}

	public function test_smart_crop_on_a_smaller_source_leaves_it_alone(): void {
		// That branch skips the crop entirely rather than upscaling, so the
		// file keeps the source's dimensions while claiming the target's.
		$result = Resizer::producedDimensions( $this->variant( [ 'width' => 5000, 'height' => 3000, 'image_style' => 'smart-crop' ] ), 4000, 2250 );

		$this->assertSame( [ 4000, 2250 ], $result );
	}

	public function test_smart_crop_crops_when_only_one_axis_exceeds_the_source(): void {
		// The encoder's own test is `>` on either axis, not both.
		$result = Resizer::producedDimensions( $this->variant( [ 'width' => 5000, 'height' => 1000, 'image_style' => 'smart-crop' ] ), 4000, 2250 );

		$this->assertSame( [ 5000, 1000 ], $result );
	}

	public function test_an_unknown_source_size_is_admitted_as_zero(): void {
		// Guessing would be worse than saying so: a consumer can test for zero,
		// but cannot tell a made-up number from a measured one.
		$this->assertSame( [ 600, 0 ], Resizer::producedDimensions( $this->variant( [ 'width' => 600 ] ), 0, 0 ) );
		$this->assertSame( [ 0, 400 ], Resizer::producedDimensions( $this->variant( [ 'height' => 400 ] ), 0, 0 ) );
		$this->assertSame( [ 0, 0 ], Resizer::producedDimensions( $this->variant(), 0, 0 ) );
	}

	public function test_an_exact_crop_needs_no_source_size(): void {
		// Both dimensions are given, so nothing has to be derived.
		$this->assertSame( [ 800, 600 ], Resizer::producedDimensions( $this->variant( [ 'width' => 800, 'height' => 600 ] ), 0, 0 ) );
	}
}
