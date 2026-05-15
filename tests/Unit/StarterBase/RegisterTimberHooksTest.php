<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Parisek\TimberKit\StarterBase;
use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies that registerTimberHooks() registers all expected Timber/Twig
 * integration hooks without touching any other concern.
 */
class RegisterTimberHooksTest extends StarterBaseTestCase {

	private function invokeRegisterTimberHooks( StarterBase $instance ): void {
		$method = ( new \ReflectionClass( StarterBase::class ) )->getMethod( 'registerTimberHooks' );
		$method->invoke( $instance );
	}

	private function bareInstance(): StarterBase {
		return ( new \ReflectionClass( StarterBase::class ) )->newInstanceWithoutConstructor();
	}

	public function test_registers_timber_context_and_twig_filters(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$this->invokeRegisterTimberHooks( $this->bareInstance() );

		$this->assertContains( 'timber/context', $filters );
		$this->assertContains( 'timber/twig', $filters );
		$this->assertContains( 'timber/loader/loader', $filters );
		$this->assertContains( 'timber/locations', $filters );
	}

	public function test_registers_timber_image_and_cache_actions(): void {
		$actions = [];
		Functions\when( 'add_action' )->alias( function ( $hook, ...$rest ) use ( &$actions ) {
			$actions[] = $hook;
		} );
		Functions\when( 'add_filter' )->justReturn( true );

		$this->invokeRegisterTimberHooks( $this->bareInstance() );

		$this->assertContains( 'timber/twig/environment/options', $actions );
		$this->assertContains( 'timber/image/new_url', $actions );
		$this->assertContains( 'timber/image/new_path', $actions );
	}

	public function test_registers_acf_save_post_for_block_cache_invalidation(): void {
		$actions = [];
		Functions\when( 'add_action' )->alias( function ( $hook, ...$rest ) use ( &$actions ) {
			$actions[] = $hook;
		} );
		Functions\when( 'add_filter' )->justReturn( true );

		$this->invokeRegisterTimberHooks( $this->bareInstance() );

		$this->assertContains( 'acf/save_post', $actions );
	}
}
