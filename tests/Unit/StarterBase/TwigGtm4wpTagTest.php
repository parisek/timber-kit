<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;

/**
 * Coverage for `StarterBase::twig_gtm4wp_the_gtm_tag()` — Twig function that
 * calls the global `gtm4wp_the_gtm_tag()` printer when GTM4WP is loaded and
 * is a silent no-op otherwise, so themes can call it unconditionally.
 */
class TwigGtm4wpTagTest extends StarterBaseTestCase {

	public function test_calls_global_when_gtm4wp_is_loaded(): void {
		$called = false;
		Functions\when( 'gtm4wp_the_gtm_tag' )->alias( function () use ( &$called ) {
			$called = true;
		} );

		$base = $this->createStarterBase();
		$base->twig_gtm4wp_the_gtm_tag();

		$this->assertTrue( $called );
	}

	public function test_no_op_when_gtm4wp_function_undefined(): void {
		// Brain\Monkey function definitions persist across tests in the same
		// run, so this test relies on running before any test that defines
		// `gtm4wp_the_gtm_tag` — keep it in this file (and at the top of any
		// test sequence) to preserve the function_exists==false path.
		//
		// If this assertion ever fails due to ordering, the right fix is to
		// move it to its own process-isolated test (annotation
		// `@runInSeparateProcess`) rather than mocking the gone-function path.
		if ( function_exists( 'gtm4wp_the_gtm_tag' ) ) {
			$this->markTestSkipped( 'gtm4wp_the_gtm_tag already defined in this PHP process by an earlier test.' );
		}

		$base = $this->createStarterBase();

		// Just verifying no fatal/exception is thrown is the entire contract.
		$base->twig_gtm4wp_the_gtm_tag();

		$this->assertTrue( true );
	}
}
