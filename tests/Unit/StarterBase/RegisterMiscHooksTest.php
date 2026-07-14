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

	public function test_registers_menu_cache_clear_by_default(): void {
		$actions = [];
		Functions\when( 'add_action' )->alias( function ( $hook, $callback, ...$rest ) use ( &$actions ) {
			$actions[] = [ 'hook' => $hook, 'callback' => $callback ];
		} );
		Functions\when( 'add_filter' )->justReturn( true );

		$this->invokeRegisterMiscHooks( $this->bareInstance() );

		$menuActions = array_filter(
			$actions,
			fn( $a ) => $a['hook'] === 'wp_update_nav_menu'
				&& is_array( $a['callback'] )
				&& $a['callback'][1] === 'clear_cache_on_menu_update'
		);
		$this->assertCount( 1, $menuActions );
	}

	public function test_does_not_register_menu_cache_clear_when_flag_disabled(): void {
		$actions = [];
		Functions\when( 'add_action' )->alias( function ( $hook, $callback, ...$rest ) use ( &$actions ) {
			$actions[] = [ 'hook' => $hook, 'callback' => $callback ];
		} );
		Functions\when( 'add_filter' )->justReturn( true );

		$instance = $this->bareInstance();
		$prop     = new \ReflectionProperty( StarterBase::class, 'clear_cache_on_menu_update' );
		$prop->setValue( $instance, false );

		$this->invokeRegisterMiscHooks( $instance );

		$this->assertNotContains( 'wp_update_nav_menu', array_column( $actions, 'hook' ) );
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
