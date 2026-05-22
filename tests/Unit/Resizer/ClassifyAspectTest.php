<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Resizer;
use Tests\Unit\ResizerTestCase;

/**
 * Coverage for `Resizer::classifyAspect()` and `Resizer::resizerAspect()` —
 * orientation classification (landscape / portrait / square) with tolerance,
 * missing-metadata fallback, and dispatch into the right tuple set.
 *
 * Lives in its own file to keep the existing `ResizerPublicApiTest` focused
 * on the pre-existing `resizer()` API surface.
 */
class ClassifyAspectTest extends ResizerTestCase {

	/**
	 * Mock `apply_filters` to either return the default (most tests) or override
	 * a specific filter name. Keeps each test's setup self-contained — no
	 * cross-test state leakage via Brain Monkey's global filter registry.
	 *
	 * @param array<string, mixed> $overrides Filter name → forced return value.
	 */
	private function mockFilters( array $overrides = [] ): void {
		Functions\when( 'apply_filters' )->alias( function ( $filter, $default, ...$args ) use ( $overrides ) {
			unset( $args );
			return $overrides[ $filter ] ?? $default;
		} );
	}

	// ------------------------------------------------------------------
	// Landscape classification
	// ------------------------------------------------------------------

	public function test_classifies_16_9_as_landscape(): void {
		$this->mockFilters();
		$image = [ [ 'src' => '/x.jpg', 'width' => 1920, 'height' => 1080 ] ];
		$this->assertSame( 'landscape', Resizer::classifyAspect( $image ) );
	}

	public function test_classifies_4_3_as_landscape(): void {
		$this->mockFilters();
		$image = [ [ 'src' => '/x.jpg', 'width' => 1200, 'height' => 900 ] ];
		$this->assertSame( 'landscape', Resizer::classifyAspect( $image ) );
	}

	public function test_classifies_just_outside_square_band_as_landscape(): void {
		// Aspect = 1.11 → above 1 + 0.1 tolerance band → landscape
		$this->mockFilters();
		$image = [ [ 'src' => '/x.jpg', 'width' => 1110, 'height' => 1000 ] ];
		$this->assertSame( 'landscape', Resizer::classifyAspect( $image ) );
	}

	// ------------------------------------------------------------------
	// Portrait classification
	// ------------------------------------------------------------------

	public function test_classifies_9_16_as_portrait(): void {
		$this->mockFilters();
		$image = [ [ 'src' => '/x.jpg', 'width' => 1080, 'height' => 1920 ] ];
		$this->assertSame( 'portrait', Resizer::classifyAspect( $image ) );
	}

	public function test_classifies_3_4_as_portrait(): void {
		$this->mockFilters();
		$image = [ [ 'src' => '/x.jpg', 'width' => 900, 'height' => 1200 ] ];
		$this->assertSame( 'portrait', Resizer::classifyAspect( $image ) );
	}

	public function test_classifies_just_outside_square_band_as_portrait(): void {
		// Aspect = 0.892 → distance 0.108 > 0.1 tolerance → portrait
		// (At 1000×1110 the distance is 0.0991, still inside the band; pick a
		// pair clearly past 10 % to avoid floating-point boundary noise.)
		$this->mockFilters();
		$image = [ [ 'src' => '/x.jpg', 'width' => 1000, 'height' => 1120 ] ];
		$this->assertSame( 'portrait', Resizer::classifyAspect( $image ) );
	}

	// ------------------------------------------------------------------
	// Square classification (within ±10 % tolerance)
	// ------------------------------------------------------------------

	public function test_classifies_exact_square_as_square(): void {
		$this->mockFilters();
		$image = [ [ 'src' => '/x.jpg', 'width' => 1000, 'height' => 1000 ] ];
		$this->assertSame( 'square', Resizer::classifyAspect( $image ) );
	}

	public function test_classifies_slightly_wide_as_square(): void {
		// Aspect = 1.05 → inside tolerance band → square
		$this->mockFilters();
		$image = [ [ 'src' => '/x.jpg', 'width' => 1050, 'height' => 1000 ] ];
		$this->assertSame( 'square', Resizer::classifyAspect( $image ) );
	}

	public function test_classifies_slightly_tall_as_square(): void {
		// Aspect = 0.952 → inside tolerance band → square
		$this->mockFilters();
		$image = [ [ 'src' => '/x.jpg', 'width' => 1000, 'height' => 1050 ] ];
		$this->assertSame( 'square', Resizer::classifyAspect( $image ) );
	}

	public function test_classifies_upper_boundary_as_square(): void {
		// Aspect = 1.099 → clearly inside the 0.1 tolerance band → square.
		// (Exact 1100×1000 = 1.1 lands on the boundary where IEEE-754 noise
		// makes `abs(1.1 - 1.0) <= 0.1` flip false; pick a fixture inside.)
		$this->mockFilters();
		$image = [ [ 'src' => '/x.jpg', 'width' => 1099, 'height' => 1000 ] ];
		$this->assertSame( 'square', Resizer::classifyAspect( $image ) );
	}

	// ------------------------------------------------------------------
	// Tolerance filter override
	// ------------------------------------------------------------------

	public function test_tolerance_filter_can_tighten_square_band(): void {
		// With tolerance 0.05, aspect 1.07 falls OUTSIDE the band → landscape.
		// (Same image at the default 0.1 tolerance would classify as square.)
		$this->mockFilters( [ 'timber_kit_resizer_aspect_tolerance' => 0.05 ] );
		$image = [ [ 'src' => '/x.jpg', 'width' => 1070, 'height' => 1000 ] ];
		$this->assertSame( 'landscape', Resizer::classifyAspect( $image ) );
	}

	public function test_tolerance_filter_can_loosen_square_band(): void {
		// With tolerance 0.2, aspect 1.15 falls INSIDE the band → square.
		// (Same image at the default 0.1 tolerance would classify as landscape.)
		$this->mockFilters( [ 'timber_kit_resizer_aspect_tolerance' => 0.2 ] );
		$image = [ [ 'src' => '/x.jpg', 'width' => 1150, 'height' => 1000 ] ];
		$this->assertSame( 'square', Resizer::classifyAspect( $image ) );
	}

	// ------------------------------------------------------------------
	// Missing-metadata fallback → landscape
	// ------------------------------------------------------------------

	public function test_empty_array_falls_back_to_landscape(): void {
		$this->mockFilters();
		$this->assertSame( 'landscape', Resizer::classifyAspect( [] ) );
	}

	public function test_non_array_input_falls_back_to_landscape(): void {
		$this->mockFilters();
		$this->assertSame( 'landscape', Resizer::classifyAspect( null ) );
		$this->assertSame( 'landscape', Resizer::classifyAspect( 'string-not-array' ) );
	}

	public function test_missing_width_falls_back_to_landscape(): void {
		$this->mockFilters();
		$image = [ [ 'src' => '/x.jpg', 'height' => 1000 ] ];
		$this->assertSame( 'landscape', Resizer::classifyAspect( $image ) );
	}

	public function test_missing_height_falls_back_to_landscape(): void {
		$this->mockFilters();
		$image = [ [ 'src' => '/x.jpg', 'width' => 1000 ] ];
		$this->assertSame( 'landscape', Resizer::classifyAspect( $image ) );
	}

	public function test_zero_dimensions_fall_back_to_landscape(): void {
		$this->mockFilters();
		$image = [ [ 'src' => '/x.jpg', 'width' => 0, 'height' => 0 ] ];
		$this->assertSame( 'landscape', Resizer::classifyAspect( $image ) );
	}

	public function test_non_numeric_dimensions_fall_back_to_landscape(): void {
		// (float) cast of a non-numeric string is 0.0, which trips the
		// $width <= 0 || $height <= 0 guard → landscape.
		$this->mockFilters();
		$image = [ [ 'src' => '/x.jpg', 'width' => 'invalid', 'height' => 'invalid' ] ];
		$this->assertSame( 'landscape', Resizer::classifyAspect( $image ) );
	}

	// ------------------------------------------------------------------
	// Source-element selection — last entry wins (mirrors `resizer()`)
	// ------------------------------------------------------------------

	public function test_uses_last_entry_when_array_has_multiple_variants(): void {
		// First entry is a portrait thumbnail, last is the landscape original —
		// classification should pick the original (last), matching how
		// `resizer()` picks its source via `end($image)`.
		$this->mockFilters();
		$image = [
			[ 'src' => '/thumb.jpg', 'width' => 100, 'height' => 200 ],   // portrait thumbnail
			[ 'src' => '/full.jpg', 'width' => 1920, 'height' => 1080 ], // landscape original
		];
		$this->assertSame( 'landscape', Resizer::classifyAspect( $image ) );
	}

	public function test_accepts_single_image_dict_as_input(): void {
		// Defensive — some callers may pass a single image dict instead of
		// the formatImage()-style array of dicts. classifyAspect should
		// handle both shapes.
		$this->mockFilters();
		$image = [ 'src' => '/x.jpg', 'width' => 1080, 'height' => 1920 ];
		// Note: the function detects array-of-dicts by `isset($image[0])`.
		// A single dict has no [0] index, so it falls through the else branch
		// and uses the dict directly.
		$this->assertSame( 'portrait', Resizer::classifyAspect( $image ) );
	}

	// ------------------------------------------------------------------
	// resizerAspect() dispatch — bucket → tuples
	// ------------------------------------------------------------------

	public function test_resizer_aspect_returns_image_when_orientations_empty(): void {
		$this->mockFilters();
		$resizer = $this->createResizer();
		$image = [ [ 'src' => '/x.jpg', 'width' => 1920, 'height' => 1080 ] ];
		$result = $resizer->resizerAspect( $image, [] );
		$this->assertSame( $image, $result );
	}

	public function test_resizer_aspect_returns_empty_array_when_image_is_non_array(): void {
		$this->mockFilters();
		$resizer = $this->createResizer();
		$result = $resizer->resizerAspect( null, [ 'landscape' => [ [ '960', '720', '', 'crop' ] ] ] );
		$this->assertSame( [], $result );
	}

	public function test_resizer_aspect_returns_image_when_matched_bucket_is_empty(): void {
		// Portrait source classifies as 'portrait'; the orientations map
		// provides an empty 'portrait' list and no 'landscape' fallback.
		// Both branches yield empty tuples, so the helper short-circuits
		// before hitting the real resizer() (which would touch the filesystem).
		$this->mockFilters();
		$resizer = $this->createResizer();
		$image = [ [ 'src' => '/x.jpg', 'width' => 1080, 'height' => 1920 ] ];
		$result = $resizer->resizerAspect( $image, [ 'portrait' => [] ] );
		$this->assertSame( $image, $result );
	}

	public function test_resizer_aspect_returns_image_when_only_other_bucket_is_empty(): void {
		// Landscape source classifies as 'landscape'; orientations map only
		// holds an empty 'landscape' entry. Bucket matches but tuples are
		// empty → passthrough without hitting resizer().
		$this->mockFilters();
		$resizer = $this->createResizer();
		$image = [ [ 'src' => '/x.jpg', 'width' => 1920, 'height' => 1080 ] ];
		$result = $resizer->resizerAspect( $image, [ 'landscape' => [] ] );
		$this->assertSame( $image, $result );
	}
}
