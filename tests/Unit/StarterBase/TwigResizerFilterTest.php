<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

/**
 * Coverage for `StarterBase::twig_resizer_filter()` — the polymorphic dispatch
 * behind the `|resizer` Twig filter. Verifies the closure-extracted-to-method
 * routes correctly between `Resizer::resizerAspect()` (orientation map) and
 * `Resizer::resizer()` (positional tuples), and locks the behaviour the
 * `|resizer` Twig filter exposes against future regression.
 *
 * Identification of which underlying Resizer method ran is done via
 * observable behaviour, not spies: `resizer()` with zero variants returns `[]`,
 * `resizerAspect()` with empty orientations returns the source image unchanged.
 * That contract is locked by the existing ResizerPublicApiTest and
 * ClassifyAspectTest suites, so we can safely use it as a routing oracle here.
 */
class TwigResizerFilterTest extends StarterBaseTestCase {

	protected function setUp(): void {
		parent::setUp();
		// Resizer ctor reads `timber_kit_resizer_image_cache_dir` via apply_filters.
		Functions\when( 'apply_filters' )->alias( function ( $filter, $default, ...$args ) {
			unset( $filter, $args );
			return $default;
		} );
	}

	public function test_routes_orientation_map_to_resizerAspect(): void {
		// Single arg that is an associative array with at least one orientation
		// key flips the predicate into orientation mode. We pass an empty
		// 'landscape' bucket so resizerAspect returns the source image unchanged
		// (per ClassifyAspectTest::test_resizer_aspect_returns_image_when_only_other_bucket_is_empty).
		// If routing had gone through resizer() instead, the result would be []
		// (per ResizerPublicApiTest::test_empty_variants_returns_empty) — the two
		// outputs are observably distinct.
		$base  = $this->createStarterBase();
		$image = [ [ 'src' => '/x.jpg', 'width' => 1920, 'height' => 1080 ] ];

		$result = $base->twig_resizer_filter( $image, [ 'landscape' => [] ] );

		$this->assertSame( $image, $result );
	}

	public function test_routes_positional_tuples_to_resizer(): void {
		// Two variadic args is always tuples mode (isOrientationMap rejects
		// multi-arg). resizer() with a missing-src image returns [] — that's
		// our routing oracle: if dispatch had picked resizerAspect, the image
		// would have come back unchanged.
		$base  = $this->createStarterBase();
		$image = [ 'no-src-here' => true ];

		$result = $base->twig_resizer_filter(
			$image,
			[ '960', '720', '', 'crop' ],
			[ '480', '360', '', 'crop' ]
		);

		$this->assertSame( [], $result );
	}

	public function test_routes_zero_args_to_resizer(): void {
		// `image|resizer` with no further args — isOrientationMap returns false
		// on an empty variadic, so dispatch goes through resizer(), which short-
		// circuits on empty variants → []. (Notably NOT routed to resizerAspect,
		// which would have returned the source image.)
		$base  = $this->createStarterBase();
		$image = [ 'src' => '/x.jpg' ];

		$result = $base->twig_resizer_filter( $image );

		$this->assertSame( [], $result );
	}

	public function test_orientation_map_with_unrelated_keys_still_routes_to_resizerAspect(): void {
		// Even if the orientation map carries extra metadata keys, presence of
		// any orientation key keeps dispatch on the orientation-aware path —
		// matching IsOrientationMapTest::test_detects_map_with_extra_unrecognised_keys.
		$base  = $this->createStarterBase();
		$image = [ [ 'src' => '/x.jpg', 'width' => 1080, 'height' => 1920 ] ];

		// portrait source + only-landscape map → resizerAspect falls through to
		// landscape; since landscape is empty too, the source passes through.
		$result = $base->twig_resizer_filter( $image, [
			'landscape' => [],
			'_meta'     => 'arbitrary',
		] );

		$this->assertSame( $image, $result );
	}
}
