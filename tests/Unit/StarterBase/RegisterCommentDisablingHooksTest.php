<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Parisek\TimberKit\StarterBase;
use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies that registerCommentDisablingHooks() registers all expected hooks
 * when disable_comments is true, and registers nothing when it is false.
 */
class RegisterCommentDisablingHooksTest extends StarterBaseTestCase {

	private function invokeRegisterCommentDisablingHooks( StarterBase $instance ): void {
		$method = ( new \ReflectionClass( StarterBase::class ) )->getMethod( 'registerCommentDisablingHooks' );
		$method->invoke( $instance );
	}

	private function bareInstance(): StarterBase {
		return ( new \ReflectionClass( StarterBase::class ) )->newInstanceWithoutConstructor();
	}

	private function setProperty( StarterBase $instance, string $name, mixed $value ): void {
		$prop = ( new \ReflectionClass( StarterBase::class ) )->getProperty( $name );
		$prop->setValue( $instance, $value );
	}

	public function test_skips_all_hooks_when_disable_comments_is_false(): void {
		$actions = [];
		$filters = [];
		Functions\when( 'add_action' )->alias( function ( $hook, ...$rest ) use ( &$actions ) {
			$actions[] = $hook;
		} );
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'disable_comments', false );

		$this->invokeRegisterCommentDisablingHooks( $instance );

		$this->assertEmpty( $actions, 'No actions should be registered when disable_comments is false' );
		$this->assertEmpty( $filters, 'No filters should be registered when disable_comments is false' );
	}

	public function test_registers_core_comment_hooks_when_enabled(): void {
		$actions = [];
		$filters = [];
		Functions\when( 'add_action' )->alias( function ( $hook, ...$rest ) use ( &$actions ) {
			$actions[] = $hook;
		} );
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'disable_comments', true );

		$this->invokeRegisterCommentDisablingHooks( $instance );

		$this->assertContains( 'init', $actions );
		$this->assertContains( 'registered_post_type', $actions );
		$this->assertContains( 'admin_menu', $actions );
		$this->assertContains( 'wp_enqueue_scripts', $actions );

		$this->assertContains( 'comments_open', $filters );
		$this->assertContains( 'pings_open', $filters );
		$this->assertContains( 'comments_array', $filters );
		$this->assertContains( 'rest_pre_dispatch', $filters );
		$this->assertContains( 'rest_pre_insert_comment', $filters );
		$this->assertContains( 'xmlrpc_methods', $filters );
		$this->assertContains( 'wp_headers', $filters );
	}

	public function test_registers_init_at_max_priority_for_late_sweep(): void {
		$actions = [];
		Functions\when( 'add_action' )->alias( function ( $hook, $callback, $priority = 10, ...$rest ) use ( &$actions ) {
			$actions[] = [ 'hook' => $hook, 'priority' => $priority ];
		} );
		Functions\when( 'add_filter' )->justReturn( true );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'disable_comments', true );

		$this->invokeRegisterCommentDisablingHooks( $instance );

		$initActions = array_filter( $actions, fn( $a ) => $a['hook'] === 'init' );
		$this->assertNotEmpty( $initActions );
		$priorities = array_column( array_values( $initActions ), 'priority' );
		$this->assertContains( PHP_INT_MAX, $priorities, 'init must be registered at PHP_INT_MAX priority' );
	}

	public function test_registers_default_comment_status_filters(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'disable_comments', true );

		$this->invokeRegisterCommentDisablingHooks( $instance );

		$this->assertContains( 'pre_option_default_comment_status', $filters );
		$this->assertContains( 'pre_option_default_ping_status', $filters );
		$this->assertContains( 'feed_links_show_comments_feed', $filters );
	}
}
