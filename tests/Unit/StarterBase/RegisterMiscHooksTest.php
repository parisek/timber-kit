<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Parisek\TimberKit\StarterBase;
use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies that registerMiscHooks() registers the run_wptexturize and
 * wpcf7_autop_or_not one-off filters.
 */
class RegisterMiscHooksTest extends StarterBaseTestCase {

	private function invokeRegisterMiscHooks( StarterBase $instance ): void {
		$method = ( new \ReflectionClass( StarterBase::class ) )->getMethod( 'registerMiscHooks' );
		$method->invoke( $instance );
	}

	private function bareInstance(): StarterBase {
		return ( new \ReflectionClass( StarterBase::class ) )->newInstanceWithoutConstructor();
	}

	public function test_registers_run_wptexturize_and_cf7_autop_filters(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		$this->invokeRegisterMiscHooks( $this->bareInstance() );

		$this->assertContains( 'run_wptexturize', $filters );
		$this->assertContains( 'wpcf7_autop_or_not', $filters );
	}

	public function test_run_wptexturize_is_disabled_with_return_false(): void {
		$callbacks = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, $callback, ...$rest ) use ( &$callbacks ) {
			$callbacks[$hook] = $callback;
		} );

		$this->invokeRegisterMiscHooks( $this->bareInstance() );

		$this->assertSame( '__return_false', $callbacks['run_wptexturize'] );
		$this->assertSame( '__return_false', $callbacks['wpcf7_autop_or_not'] );
	}

	public function test_registers_exactly_two_filters(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		$this->invokeRegisterMiscHooks( $this->bareInstance() );

		$this->assertCount( 2, $filters );
	}
}
