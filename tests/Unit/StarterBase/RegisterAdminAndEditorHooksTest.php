<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Parisek\TimberKit\StarterBase;
use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies that registerAdminAndEditorHooks() registers the admin UI,
 * editor, and frontend search post-type hooks.
 */
class RegisterAdminAndEditorHooksTest extends StarterBaseTestCase {

	private function invokeRegisterAdminAndEditorHooks( StarterBase $instance ): void {
		$method = ( new \ReflectionClass( StarterBase::class ) )->getMethod( 'registerAdminAndEditorHooks' );
		$method->invoke( $instance );
	}

	private function bareInstance(): StarterBase {
		return ( new \ReflectionClass( StarterBase::class ) )->newInstanceWithoutConstructor();
	}

	public function test_registers_template_redirect_and_theme_page_templates(): void {
		$actions = [];
		$filters = [];
		Functions\when( 'add_action' )->alias( function ( $hook, ...$rest ) use ( &$actions ) {
			$actions[] = $hook;
		} );
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		$this->invokeRegisterAdminAndEditorHooks( $this->bareInstance() );

		$this->assertContains( 'template_redirect', $actions );
		$this->assertContains( 'restrict_manage_posts', $actions );
		$this->assertContains( 'admin_bar_menu', $actions );
		$this->assertContains( 'admin_head', $actions );
		$this->assertContains( 'acf/input/admin_footer', $actions );

		$this->assertContains( 'theme_page_templates', $filters );
		$this->assertContains( 'tiny_mce_before_init', $filters );
		$this->assertContains( 'pre_get_posts', $filters );
		$this->assertContains( 'mce_css', $filters );
	}

	private function collectFilters( bool $excludeEditorStyles ): array {
		$filters  = [];
		$instance = $this->bareInstance();

		$property = ( new \ReflectionClass( StarterBase::class ) )->getProperty( 'mce_exclude_editor_styles' );
		$property->setValue( $instance, $excludeEditorStyles );

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );

		$this->invokeRegisterAdminAndEditorHooks( $instance );

		return $filters;
	}

	public function test_registers_mce_css_by_default(): void {
		$this->assertContains( 'mce_css', $this->collectFilters( true ) );
	}

	// The escape hatch a project needs when it styles the classic editor from
	// inside gutenberg-editor.css itself.
	public function test_does_not_register_mce_css_when_the_flag_is_off(): void {
		$this->assertNotContains( 'mce_css', $this->collectFilters( false ) );
	}

	public function test_registers_template_redirect_at_priority_0(): void {
		$actions = [];
		Functions\when( 'add_action' )->alias( function ( $hook, $callback, $priority = 10, ...$rest ) use ( &$actions ) {
			$actions[] = [ 'hook' => $hook, 'priority' => $priority ];
		} );
		Functions\when( 'add_filter' )->justReturn( true );

		$this->invokeRegisterAdminAndEditorHooks( $this->bareInstance() );

		$redirect = array_filter( $actions, fn( $a ) => $a['hook'] === 'template_redirect' );
		$this->assertNotEmpty( $redirect );
		$entry = array_values( $redirect )[0];
		$this->assertSame( 0, $entry['priority'] );
	}

	public function test_registers_admin_bar_menu_at_priority_100(): void {
		$actions = [];
		Functions\when( 'add_action' )->alias( function ( $hook, $callback, $priority = 10, ...$rest ) use ( &$actions ) {
			$actions[] = [ 'hook' => $hook, 'priority' => $priority ];
		} );
		Functions\when( 'add_filter' )->justReturn( true );

		$this->invokeRegisterAdminAndEditorHooks( $this->bareInstance() );

		$adminBar = array_filter( $actions, fn( $a ) => $a['hook'] === 'admin_bar_menu' );
		$this->assertNotEmpty( $adminBar );
		$entry = array_values( $adminBar )[0];
		$this->assertSame( 100, $entry['priority'] );
	}
}
