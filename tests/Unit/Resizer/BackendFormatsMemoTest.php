<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Resizer;
use Tests\Fixtures\CountingResizer;
use Tests\Unit\ResizerTestCase;

/**
 * Covers the process-wide memo on the image backend's format list.
 *
 * The list is a property of the ImageMagick or GD build, so it cannot change
 * while the process runs. It used to be probed once per Resizer instance, and
 * one `|resizer` call builds one instance — 320 probes on a front page that
 * resizes 320 images.
 */
class BackendFormatsMemoTest extends ResizerTestCase {

	private function passThroughFilters(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $default, ...$args ) {
				unset( $filter, $args );
				return $default;
			}
		);
	}

	public function test_probe_runs_once_across_instances(): void {
		$this->passThroughFilters();
		CountingResizer::$probes = 0;

		( new CountingResizer() )->canDecode( 'image/jpeg' );
		( new CountingResizer() )->canDecode( 'image/jpeg' );
		( new CountingResizer() )->canDecode( 'image/png' );

		$this->assertSame(
			1,
			CountingResizer::$probes,
			'Three instances must share one backend probe.'
		);
	}

	public function test_memo_does_not_change_the_answer(): void {
		$this->passThroughFilters();
		CountingResizer::$probes = 0;

		$first  = new CountingResizer();
		$second = new CountingResizer();

		$this->assertTrue( $first->canDecode( 'image/jpeg' ) );
		$this->assertTrue( $second->canDecode( 'image/jpeg' ) );
		$this->assertFalse( $second->canDecode( 'image/heic' ) );
	}

	public function test_flush_forces_a_fresh_probe(): void {
		$this->passThroughFilters();
		CountingResizer::$probes = 0;

		// Two probes either side of the flush, so the count separates a working
		// memo (2) from no memo at all (4) — one call each side would be 2 in
		// both worlds and prove nothing.
		( new CountingResizer() )->canDecode( 'image/jpeg' );
		( new CountingResizer() )->canDecode( 'image/jpeg' );
		Resizer::flushBackendFormats();
		( new CountingResizer() )->canDecode( 'image/jpeg' );
		( new CountingResizer() )->canDecode( 'image/jpeg' );

		$this->assertSame( 2, CountingResizer::$probes );
	}
}
