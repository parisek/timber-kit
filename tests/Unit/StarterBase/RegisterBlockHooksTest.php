<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Parisek\TimberKit\StarterBase;
use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies that registerBlockHooks() registers the Gutenberg block pipeline
 * filters and ACF block-related actions.
 */
class RegisterBlockHooksTest extends StarterBaseTestCase {

	private function invokeRegisterBlockHooks( StarterBase $instance ): void {
		$method = ( new \ReflectionClass( StarterBase::class ) )->getMethod( 'registerBlockHooks' );
		$method->invoke( $instance );
	}

	private function bareInstance(): StarterBase {
		return ( new \ReflectionClass( StarterBase::class ) )->newInstanceWithoutConstructor();
	}

	public function test_registers_allowed_block_types_and_block_category_filters(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$this->invokeRegisterBlockHooks( $this->bareInstance() );

		$this->assertContains( 'allowed_block_types_all', $filters );
		$this->assertContains( 'render_block_data', $filters );
		$this->assertContains( 'render_block', $filters );
		$this->assertContains( 'block_categories_all', $filters );
	}

	public function test_registers_acf_init_for_options_page_and_gutenberg_blocks_init(): void {
		$actions = [];
		Functions\when( 'add_action' )->alias( function ( $hook, $callback, ...$rest ) use ( &$actions ) {
			$actions[] = [ 'hook' => $hook, 'callback' => $callback ];
		} );
		Functions\when( 'add_filter' )->justReturn( true );

		$instance = $this->bareInstance();
		$this->invokeRegisterBlockHooks( $instance );

		$hookNames = array_column( $actions, 'hook' );
		$this->assertContains( 'init', $hookNames );
		$this->assertContains( 'acf/init', $hookNames );
		$this->assertContains( 'acf/save_post', $hookNames );
		$this->assertContains( 'acf/fields/google_map/api', $hookNames );
	}

	public function test_does_not_register_wpml_block_override_by_default(): void {
		$actions = [];
		Functions\when( 'add_action' )->alias( function ( $hook, $callback, ...$rest ) use ( &$actions ) {
			$actions[] = [ 'hook' => $hook, 'callback' => $callback ];
		} );
		Functions\when( 'add_filter' )->justReturn( true );

		$this->invokeRegisterBlockHooks( $this->bareInstance() );

		$wbo = array_filter(
			$actions,
			fn( $a ) => is_array( $a['callback'] ) && ( $a['callback'][0] ?? '' ) === \Parisek\TimberKit\WpmlBlockOverride::class
		);
		$this->assertEmpty( $wbo, 'WpmlBlockOverride must be opt-in — not registered while the flag is off (default)' );
	}

	public function test_registers_wpml_block_override_on_init_when_flag_enabled(): void {
		$actions = [];
		Functions\when( 'add_action' )->alias( function ( $hook, $callback, ...$rest ) use ( &$actions ) {
			$actions[] = [ 'hook' => $hook, 'callback' => $callback ];
		} );
		Functions\when( 'add_filter' )->justReturn( true );

		$instance = $this->bareInstance();
		$prop = new \ReflectionProperty( StarterBase::class, 'wpml_block_override' );
		$prop->setValue( $instance, true );

		$this->invokeRegisterBlockHooks( $instance );

		$wbo = array_filter(
			$actions,
			fn( $a ) => $a['hook'] === 'init' && $a['callback'] === [ \Parisek\TimberKit\WpmlBlockOverride::class, 'register' ]
		);
		$this->assertNotEmpty( $wbo, 'flag on → WpmlBlockOverride::register() hooked on init' );
	}

	public function test_registers_clear_cache_on_options_save_at_priority_20(): void {
		$actions = [];
		Functions\when( 'add_action' )->alias( function ( $hook, $callback, $priority = 10, ...$rest ) use ( &$actions ) {
			$actions[] = [ 'hook' => $hook, 'callback' => $callback, 'priority' => $priority ];
		} );
		Functions\when( 'add_filter' )->justReturn( true );

		$instance = $this->bareInstance();
		$this->invokeRegisterBlockHooks( $instance );

		$savePost = array_filter( $actions, fn( $a ) => $a['hook'] === 'acf/save_post' && is_array( $a['callback'] ) && $a['callback'][1] === 'clear_cache_on_options_save' );
		$this->assertNotEmpty( $savePost );
		$entry = array_values( $savePost )[0];
		$this->assertSame( 20, $entry['priority'] );
	}
}
