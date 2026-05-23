<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Parisek\TimberKit\Resizer;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for `Resizer::isOrientationMap()` — the predicate that drives
 * `StarterBase::timber_twig()`'s polymorphic `|resizer` Twig filter into
 * either the orientation-aware `resizerAspect()` path or the historical
 * variadic-tuples `resizer()` path. The actual resize methods are tested
 * elsewhere (see ResizerTest, ClassifyAspectTest); this file isolates the
 * shape-detection decision so the Twig filter callback in `StarterBase`
 * can stay a one-line dispatch.
 */
final class IsOrientationMapTest extends TestCase {

	// ----- Positive cases: orientation map detected -----

	public function test_detects_full_orientation_map(): void {
		$variants = [
			[
				'landscape' => [ [ '960', '720', '1280', 'crop' ] ],
				'portrait'  => [ [ '720', '960', '1280', 'crop' ] ],
				'square'    => [ [ '800', '800', '1280', 'crop' ] ],
			],
		];

		$this->assertTrue( Resizer::isOrientationMap( $variants ) );
	}

	public function test_detects_landscape_only_map(): void {
		// Partial maps still count — the runtime falls through to landscape
		// when other buckets are absent, so accepting them at the predicate
		// stage matches the documented "single bucket → still routes via
		// resizerAspect" contract.
		$variants = [ [ 'landscape' => [ [ '640', '480', '', 'crop' ] ] ] ];

		$this->assertTrue( Resizer::isOrientationMap( $variants ) );
	}

	public function test_detects_portrait_only_map(): void {
		$variants = [ [ 'portrait' => [ [ '480', '640', '', 'crop' ] ] ] ];

		$this->assertTrue( Resizer::isOrientationMap( $variants ) );
	}

	public function test_detects_square_only_map(): void {
		$variants = [ [ 'square' => [ [ '600', '600', '', 'crop' ] ] ] ];

		$this->assertTrue( Resizer::isOrientationMap( $variants ) );
	}

	public function test_detects_map_with_empty_buckets(): void {
		// `square => []` still flips dispatch into orientation mode — the
		// emptyness is the resizerAspect()'s concern, not the predicate's.
		// resizerAspect() then falls through to landscape per its docblock.
		$variants = [
			[
				'landscape' => [ [ '640', '480', '', 'crop' ] ],
				'square'    => [],
			],
		];

		$this->assertTrue( Resizer::isOrientationMap( $variants ) );
	}

	public function test_detects_map_with_extra_unrecognised_keys(): void {
		// As long as one orientation key is present, extra keys don't block
		// detection. Lets callers mix in metadata without breaking dispatch.
		$variants = [
			[
				'landscape' => [ [ '960', '720', '', 'crop' ] ],
				'_meta'     => 'extra-info-that-resizerAspect-will-ignore',
			],
		];

		$this->assertTrue( Resizer::isOrientationMap( $variants ) );
	}

	// ----- Negative cases: tuples mode (positional variadic) -----

	public function test_rejects_single_positional_tuple(): void {
		// Historical shape: `image|resizer(['960', '720', '1280', 'crop'])`.
		// Integer-keyed array, no orientation strings.
		$variants = [ [ '960', '720', '1280', 'crop' ] ];

		$this->assertFalse( Resizer::isOrientationMap( $variants ) );
	}

	public function test_rejects_multiple_positional_tuples(): void {
		// `image|resizer(['960', '720', …], ['480', '360', …])`. Multi-arg
		// is always tuples mode, regardless of any one tuple's contents —
		// orientation mode is by contract single-arg.
		$variants = [
			[ '960', '720', '1280', 'crop' ],
			[ '480', '360', '', 'crop' ],
		];

		$this->assertFalse( Resizer::isOrientationMap( $variants ) );
	}

	public function test_rejects_two_args_even_if_first_is_orientation_map(): void {
		// A defensive check: if a template accidentally piped a second arg
		// after an orientation map, the predicate refuses orientation mode.
		// resizerAspect() can't usefully consume extra args; tuples mode
		// would treat the map as an integer-indexed tuple and (correctly)
		// reject it downstream.
		$variants = [
			[ 'landscape' => [ [ '960', '720', '', 'crop' ] ] ],
			[ '480', '360', '', 'crop' ],
		];

		$this->assertFalse( Resizer::isOrientationMap( $variants ) );
	}

	public function test_rejects_zero_args(): void {
		// `image|resizer` with no further args — neither shape applies.
		// resizer() will return the image unchanged in this case.
		$this->assertFalse( Resizer::isOrientationMap( [] ) );
	}

	public function test_rejects_single_non_array_arg(): void {
		// A scalar or string can't be either shape. Defensive against type-
		// confused calls from Twig templates.
		$this->assertFalse( Resizer::isOrientationMap( [ 'not-an-array' ] ) );
		$this->assertFalse( Resizer::isOrientationMap( [ 42 ] ) );
		$this->assertFalse( Resizer::isOrientationMap( [ null ] ) );
	}

	public function test_rejects_associative_array_without_orientation_keys(): void {
		// An associative array with unrelated keys is not an orientation
		// map — the predicate gates on the presence of at least one of the
		// three recognised strings.
		$variants = [
			[
				'width'  => 960,
				'height' => 720,
			],
		];

		$this->assertFalse( Resizer::isOrientationMap( $variants ) );
	}
}
