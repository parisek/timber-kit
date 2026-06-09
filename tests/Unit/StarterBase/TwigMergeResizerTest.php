<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Tests\Unit\StarterBaseTestCase;

/**
 * Coverage for `StarterBase::twig_merge_resizer()` — composes multiple
 * `Resizer`-shaped image lists into a single `<picture>` source set.
 *
 * Rules locked here:
 * - Empty input lists are dropped (so `array_key_last` reflects real last).
 * - Non-last lists contribute ONLY their media-qualified variants.
 * - The last list contributes everything (including the fallback default).
 */
class TwigMergeResizerTest extends StarterBaseTestCase {

	public function test_returns_empty_when_called_with_no_args(): void {
		$base = $this->createStarterBase();
		$this->assertSame( [], $base->twig_merge_resizer() );
	}

	public function test_returns_empty_when_all_inputs_are_empty(): void {
		$base = $this->createStarterBase();
		$this->assertSame( [], $base->twig_merge_resizer( [], [], [] ) );
	}

	public function test_keeps_only_media_qualified_variants_from_non_last_lists(): void {
		$mobile = [
			[ 'src' => '/m-variant.avif', 'media' => '(min-width: 320px)' ],
			[ 'src' => '/m-fallback.jpg' ], // no 'media' → fallback default of the mobile list
		];
		$desktop = [
			[ 'src' => '/d-variant.avif', 'media' => '(min-width: 1024px)' ],
			[ 'src' => '/d-fallback.jpg' ],
		];

		$base   = $this->createStarterBase();
		$result = $base->twig_merge_resizer( $mobile, $desktop );

		// Mobile contributes ONLY its media-qualified variant (fallback dropped),
		// desktop contributes everything (media-qualified + fallback).
		$this->assertCount( 3, $result );
		$this->assertSame( '/m-variant.avif', $result[0]['src'] );
		$this->assertSame( '/d-variant.avif', $result[1]['src'] );
		$this->assertSame( '/d-fallback.jpg', $result[2]['src'] );
	}

	public function test_drops_empty_media_string_variant_from_non_last_list(): void {
		// Regression (mobile hero image never rendered): `Resizer::processVariant()`
		// ALWAYS emits a 'media' key, setting it to '' when the tuple carries no
		// maxWidth. A non-last (desktop) list therefore contributes a
		// fallback-shaped entry with 'media' => '' — NOT a missing key. That entry
		// must still be dropped: an empty-media <source> has no media attribute, so
		// it matches every viewport and shadows the LAST list's mobile image, which
		// then never renders. `isset()` keeps '' (key exists); `! empty()` drops it.
		//
		// The earlier tests only covered the missing-key fallback shape, so the
		// `'media' => ''` case slipped through — this locks it.
		$desktop = [
			[ 'src' => '/d-2560.avif', 'media' => '(min-width: 2560px)' ],
			[ 'src' => '/d-768.avif',  'media' => '(min-width: 768px)' ],
			[ 'src' => '/d-fallback.avif', 'media' => '' ], // processVariant shape: empty-string media
			[ 'src' => '/d-original.jpg' ],                 // appended default_image: no 'media' key
		];
		$mobile = [
			[ 'src' => '/m-portrait.avif', 'media' => '' ], // mobile crop, empty-string media
			[ 'src' => '/m-original.jpg' ],                 // mobile default_image
		];

		$base   = $this->createStarterBase();
		$result = $base->twig_merge_resizer( $desktop, $mobile );

		$srcs = array_column( $result, 'src' );

		// Desktop (non-last) contributes ONLY its two real media variants. Both its
		// empty-media fallback and its default_image are dropped — otherwise the
		// empty-media <source> would match on mobile and the mobile image would
		// never render.
		$this->assertSame(
			[ '/d-2560.avif', '/d-768.avif', '/m-portrait.avif', '/m-original.jpg' ],
			$srcs,
		);
	}

	public function test_non_last_list_contributing_only_empty_media_adds_nothing(): void {
		// A non-last list whose ONLY entries are empty-media (no media-qualified
		// variant at all) contributes nothing — the whole list is conditional and
		// none of its <source>s should appear. Everything visible comes from the
		// last list.
		$desktop = [
			[ 'src' => '/d-fallback.avif', 'media' => '' ],
			[ 'src' => '/d-original.jpg' ], // no 'media' key
		];
		$mobile = [
			[ 'src' => '/m-variant.avif', 'media' => '(min-width: 320px)' ],
			[ 'src' => '/m-original.jpg' ],
		];

		$base   = $this->createStarterBase();
		$result = $base->twig_merge_resizer( $desktop, $mobile );

		$this->assertSame(
			[ '/m-variant.avif', '/m-original.jpg' ],
			array_column( $result, 'src' ),
		);
	}

	public function test_drops_empty_media_variant_from_a_middle_list(): void {
		// Three-list call where the MIDDLE list carries an empty-media variant.
		// Both outer non-last lists are filtered to media-qualified entries only;
		// the middle list's empty-media entry must be dropped too.
		$xs = [ [ 'src' => '/xs.avif', 'media' => '(min-width: 320px)' ] ];
		$sm = [
			[ 'src' => '/sm.avif', 'media' => '(min-width: 640px)' ],
			[ 'src' => '/sm-fallback.avif', 'media' => '' ], // must be dropped (sm is non-last)
		];
		$lg = [
			[ 'src' => '/lg.avif', 'media' => '(min-width: 1024px)' ],
			[ 'src' => '/lg-fallback.jpg' ],
		];

		$base   = $this->createStarterBase();
		$result = $base->twig_merge_resizer( $xs, $sm, $lg );

		$this->assertSame(
			[ '/xs.avif', '/sm.avif', '/lg.avif', '/lg-fallback.jpg' ],
			array_column( $result, 'src' ),
		);
	}

	public function test_null_media_value_is_dropped_from_non_last_list(): void {
		// `Resizer` never emits a null media today (it's '' or a min-width string),
		// but `! empty( null )` is false, so a null-media entry is correctly dropped
		// from a non-last list. Lock the behaviour in case the producer ever changes.
		$desktop = [
			[ 'src' => '/d-variant.avif', 'media' => '(min-width: 1024px)' ],
			[ 'src' => '/d-null.avif', 'media' => null ],
		];
		$mobile = [ [ 'src' => '/m.jpg' ] ];

		$base   = $this->createStarterBase();
		$result = $base->twig_merge_resizer( $desktop, $mobile );

		$this->assertSame(
			[ '/d-variant.avif', '/m.jpg' ],
			array_column( $result, 'src' ),
		);
	}

	public function test_last_list_contributes_all_items_including_unqualified(): void {
		// Single-list call — the only list IS the last list, so even
		// unqualified entries pass through.
		$base   = $this->createStarterBase();
		$result = $base->twig_merge_resizer( [
			[ 'src' => '/a.jpg' ],
			[ 'src' => '/b.jpg' ],
		] );

		$this->assertCount( 2, $result );
		$this->assertSame( '/a.jpg', $result[0]['src'] );
		$this->assertSame( '/b.jpg', $result[1]['src'] );
	}

	public function test_empty_intermediate_list_is_dropped_and_does_not_shift_last_index(): void {
		// Regression guard: the empty-list filtering step is what makes
		// `array_key_last` reliable. Without it, an empty middle list would
		// leave a hole that confuses the "is this the last list?" check.
		$mobile = []; // empty
		$desktop = [
			[ 'src' => '/d-variant.avif', 'media' => '(min-width: 1024px)' ],
			[ 'src' => '/d-fallback.jpg' ],
		];

		$base   = $this->createStarterBase();
		$result = $base->twig_merge_resizer( $mobile, $desktop );

		// Desktop is now effectively the only (and therefore last) list,
		// contributing both items.
		$this->assertCount( 2, $result );
		$this->assertSame( '/d-variant.avif', $result[0]['src'] );
		$this->assertSame( '/d-fallback.jpg', $result[1]['src'] );
	}

	public function test_preserves_variant_metadata(): void {
		// Width/height/type/alt should propagate from each contributing variant
		// untouched — the merge is structural, not data-transforming.
		$mobile = [
			[
				'src'    => '/m.avif',
				'media'  => '(min-width: 320px)',
				'width'  => 480,
				'height' => 360,
				'type'   => 'image/avif',
				'alt'    => 'Hero',
			],
		];
		$desktop = [
			[
				'src'    => '/d.avif',
				'media'  => '(min-width: 1024px)',
				'width'  => 1920,
				'height' => 1080,
				'type'   => 'image/avif',
				'alt'    => 'Hero',
			],
			[
				'src'    => '/d.jpg',
				'width'  => 1920,
				'height' => 1080,
				'alt'    => 'Hero',
			],
		];

		$base   = $this->createStarterBase();
		$result = $base->twig_merge_resizer( $mobile, $desktop );

		$this->assertSame( 480,  $result[0]['width'] );
		$this->assertSame( 1920, $result[1]['width'] );
		$this->assertSame( 'image/avif', $result[1]['type'] );
		$this->assertSame( 'Hero', $result[2]['alt'] );
	}

	public function test_handles_three_or_more_lists(): void {
		$xs = [ [ 'src' => '/xs.avif', 'media' => '(min-width: 320px)' ] ];
		$sm = [ [ 'src' => '/sm.avif', 'media' => '(min-width: 640px)' ] ];
		$lg = [
			[ 'src' => '/lg.avif', 'media' => '(min-width: 1024px)' ],
			[ 'src' => '/lg-fallback.jpg' ],
		];

		$base   = $this->createStarterBase();
		$result = $base->twig_merge_resizer( $xs, $sm, $lg );

		// xs and sm contribute only their media-qualified entry; lg contributes both.
		$this->assertCount( 4, $result );
		$this->assertSame( '/xs.avif', $result[0]['src'] );
		$this->assertSame( '/sm.avif', $result[1]['src'] );
		$this->assertSame( '/lg.avif', $result[2]['src'] );
		$this->assertSame( '/lg-fallback.jpg', $result[3]['src'] );
	}
}
