<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Parisek\TimberKit\StarterBase;
use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies that registerBootstrapHooks() registers the after_setup_theme,
 * init (menus), and acf/init (post types) hooks.
 */
class RegisterBootstrapHooksTest extends StarterBaseTestCase {

	private function invokeRegisterBootstrapHooks( StarterBase $instance ): void {
		$method = ( new \ReflectionClass( StarterBase::class ) )->getMethod( 'registerBootstrapHooks' );
		$method->invoke( $instance );
	}

	private function bareInstance(): StarterBase {
		return ( new \ReflectionClass( StarterBase::class ) )->newInstanceWithoutConstructor();
	}

	public function test_registers_after_setup_theme_for_theme_supports(): void {
		$actions = [];
		Functions\when( 'add_action' )->alias( function ( $hook, $callback, ...$rest ) use ( &$actions ) {
			$actions[] = [ 'hook' => $hook, 'callback' => $callback ];
		} );

		$instance = $this->bareInstance();
		$this->invokeRegisterBootstrapHooks( $instance );

		$afterSetup = array_filter( $actions, fn( $a ) => $a['hook'] === 'after_setup_theme' );
		$this->assertNotEmpty( $afterSetup, 'after_setup_theme must be registered' );
		$registered = array_values( $afterSetup )[0]['callback'];
		$this->assertSame( [ $instance, 'theme_supports' ], $registered );
	}

	public function test_registers_init_for_menus(): void {
		$actions = [];
		Functions\when( 'add_action' )->alias( function ( $hook, $callback, ...$rest ) use ( &$actions ) {
			$actions[] = [ 'hook' => $hook, 'callback' => $callback ];
		} );

		$instance = $this->bareInstance();
		$this->invokeRegisterBootstrapHooks( $instance );

		$initMenus = array_filter( $actions, fn( $a ) => $a['hook'] === 'init' && is_array( $a['callback'] ) && $a['callback'][1] === 'register_menus' );
		$this->assertNotEmpty( $initMenus, 'init → register_menus must be registered' );
	}

	public function test_registers_acf_init_for_post_types(): void {
		$actions = [];
		Functions\when( 'add_action' )->alias( function ( $hook, $callback, ...$rest ) use ( &$actions ) {
			$actions[] = [ 'hook' => $hook, 'callback' => $callback ];
		} );

		$instance = $this->bareInstance();
		$this->invokeRegisterBootstrapHooks( $instance );

		$acfInit = array_filter( $actions, fn( $a ) => $a['hook'] === 'acf/init' && is_array( $a['callback'] ) && $a['callback'][1] === 'register_post_types' );
		$this->assertNotEmpty( $acfInit, 'acf/init → register_post_types must be registered' );
	}

	public function test_registers_init_for_setup_breadcrumb_labels_at_priority_1(): void {
		$actions = [];
		Functions\when( 'add_action' )->alias( function ( $hook, $callback, $priority = 10 ) use ( &$actions ) {
			$actions[] = [ 'hook' => $hook, 'callback' => $callback, 'priority' => $priority ];
		} );

		$instance = $this->bareInstance();
		$this->invokeRegisterBootstrapHooks( $instance );

		$labels = array_values( array_filter(
			$actions,
			fn( $a ) => $a['hook'] === 'init'
				&& is_array( $a['callback'] )
				&& $a['callback'][1] === 'setup_breadcrumb_labels'
		) );
		$this->assertNotEmpty( $labels, 'init → setup_breadcrumb_labels must be registered' );
		$this->assertSame( 1, $labels[0]['priority'], 'setup_breadcrumb_labels must run on init priority 1' );
	}

	public function test_default_setup_breadcrumb_labels_is_noop(): void {
		$instance = $this->bareInstance();

		$ref = new \ReflectionProperty( StarterBase::class, 'breadcrumb_labels' );
		$before = $ref->getValue( $instance );

		$instance->setup_breadcrumb_labels();

		$after = $ref->getValue( $instance );
		$this->assertSame( $before, $after, 'Default setup_breadcrumb_labels must not mutate the property — projects override to translate' );
	}
}
