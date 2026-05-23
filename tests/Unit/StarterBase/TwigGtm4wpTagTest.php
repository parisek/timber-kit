<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Unit\StarterBaseTestCase;

/**
 * Coverage for `StarterBase::twig_gtm4wp_the_gtm_tag()` — Twig function that
 * calls the global `gtm4wp_the_gtm_tag()` printer when GTM4WP is loaded and
 * is a silent no-op otherwise, so themes can call it unconditionally.
 *
 * Note: the `function_exists == false` path is forced into a fresh PHP
 * process (`#[RunInSeparateProcess]` + `#[PreserveGlobalState(false)]`)
 * because Brain\Monkey function definitions persist across tests in the
 * same run — relying on ordering would be order-dependent and silently
 * drop coverage as soon as any earlier test in the suite defines
 * `gtm4wp_the_gtm_tag`.
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

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_no_op_when_gtm4wp_function_undefined(): void {
		// Fresh PHP process: no earlier Brain\Monkey definitions, no
		// `gtm4wp_the_gtm_tag` global. The contract is "no fatal, no
		// exception" — `function_exists()` guards the global call.
		$this->assertFalse( function_exists( 'gtm4wp_the_gtm_tag' ) );

		$base = $this->createStarterBase();
		$base->twig_gtm4wp_the_gtm_tag();

		// Reaching this line is the assertion — the call returned without throwing.
		$this->addToAssertionCount( 1 );
	}
}
